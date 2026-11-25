<?php

/*
 * A workspace chooses the provider that writes its drafts, among those the
 * operator offers (ADR 0013). These tests hold the choice, that a key is
 * only ever used with the provider it was stored for, that the limits do
 * not care which provider a run used, and that cost follows the provider
 * and model of each run.
 *
 * Two providers are set up, both fakes: "fake" is the default of the
 * instance and "second" is offered next to it.
 */

use App\Domain\Drafting\Actions\GenerateDraft;
use App\Domain\Drafting\CostEstimator;
use App\Domain\Drafting\CredentialScope;
use App\Domain\Drafting\DraftBudget;
use App\Domain\Drafting\DraftGenerators;
use App\Domain\Drafting\DraftingConfig;
use App\Domain\Drafting\DraftingContext;
use App\Domain\Drafting\DraftUsage;
use App\Domain\Drafting\Exceptions\DraftGeneratorNotAvailable;
use App\Domain\Drafting\Exceptions\DraftingContextChanged;
use App\Domain\Drafting\Exceptions\LlmCredentialMissing;
use App\Domain\Drafting\Exceptions\ProposalRefused;
use App\Domain\Drafting\LlmCredentialResolver;
use App\Domain\Drafting\LlmKey;
use App\Domain\Drafting\Models\Brief;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Drafting\Models\InstanceLlmCredential;
use App\Domain\Drafting\Models\LlmCredential;
use App\Domain\Drafting\Planning\ProposalCost;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Fakes\FakeDraftGenerator;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';
require_once __DIR__.'/../../Support/proposals.php';

const SECOND_PROVIDER = 'second';

const SECOND_MODEL = 'second-model';

const SECOND_INSTANCE_KEY = 'second-instance-llm-key';

beforeEach(function () {
    $this->travelTo('2026-11-25 10:00:00');
    Queue::fake();

    fakeChannelAdapter();
    $this->first = fakeDraftGenerator();
    $this->second = secondDraftProvider();
    $this->generators = app(DraftGenerators::class);
    $this->workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->owner = userWithRole($this->workspace, Role::Owner);
    $this->channel = channelIn($this->workspace);
    $this->brief = briefIn($this->workspace);

    foreach ([1, 3, 5] as $weekday) {
        slotIn($this->workspace, $this->channel, $weekday, '09:00');
    }
});

/**
 * Offers "second" next to the default provider: 1.00 per million tokens in
 * and 4.00 out, with an instance key of its own unless null is given.
 */
function secondDraftProvider(?string $instanceKey = SECOND_INSTANCE_KEY): FakeDraftGenerator
{
    config([
        'noongun.drafting.offered' => [SECOND_PROVIDER],
        'noongun.drafting.providers.'.SECOND_PROVIDER => [
            'generator' => FakeDraftGenerator::class,
            'api_key' => $instanceKey,
            'model' => SECOND_MODEL,
        ],
        'noongun.drafting.prices.'.SECOND_PROVIDER => [
            SECOND_MODEL => ['input' => 1.00, 'output' => 4.00],
        ],
    ]);

    $fake = new FakeDraftGenerator;

    app(DraftGenerators::class)->extend(SECOND_PROVIDER, fn () => $fake);

    return $fake;
}

function chooseProvider(Workspace $workspace, string $provider): void
{
    app(DraftGenerators::class)->choose($workspace, $provider);
}

/**
 * Opens a run with what resolves for the workspace now and runs it to its
 * end, the way a job does.
 */
function draftOnce(Workspace $workspace): DraftRun
{
    $test = test();

    return inWorkspace($workspace, function () use ($workspace, $test): DraftRun {
        $run = DraftRun::start($test->owner, app(DraftGenerators::class)->contextFor($workspace), [], Brief::current());

        app(GenerateDraft::class)->handle($test->owner, $run, draftRequestIn($workspace, [$test->channel]));

        return $run;
    });
}

describe('the choice', function () {
    it('follows the default of the instance until an owner chooses', function () {
        expect($this->workspace->drafting_provider)->toBeNull()
            ->and($this->generators->providerFor($this->workspace))->toBe(DRAFT_TEST_PROVIDER);

        config(['noongun.drafting.provider' => SECOND_PROVIDER]);

        expect($this->generators->providerFor($this->workspace))->toBe(SECOND_PROVIDER);
    });

    it('gives the provider, the model and the key of the chosen provider', function () {
        chooseProvider($this->workspace, SECOND_PROVIDER);

        $context = $this->generators->contextFor($this->workspace->refresh());

        expect($context->provider)->toBe(SECOND_PROVIDER)
            ->and($context->model)->toBe(SECOND_MODEL)
            ->and($context->key->provider)->toBe(SECOND_PROVIDER)
            ->and($context->key->reveal())->toBe(SECOND_INSTANCE_KEY)
            ->and($this->generators->for($context))->toBe($this->second);
    });

    it('stays when the operator changes the default', function () {
        chooseProvider($this->workspace, DRAFT_TEST_PROVIDER);
        config(['noongun.drafting.provider' => SECOND_PROVIDER, 'noongun.drafting.offered' => [DRAFT_TEST_PROVIDER]]);

        expect($this->generators->providerFor($this->workspace))->toBe(DRAFT_TEST_PROVIDER);
    });

    it('is the choice of one workspace only', function () {
        $other = Workspace::factory()->create();

        chooseProvider($this->workspace, SECOND_PROVIDER);

        expect($this->generators->providerFor($other->refresh()))->toBe(DRAFT_TEST_PROVIDER)
            ->and(DB::table('workspaces')->where('id', $other->id)->value('drafting_provider'))->toBeNull();
    });

    it('offers the default provider and the listed ones that are configured, and no other', function (array $listed, array $offered) {
        config([
            'noongun.drafting.offered' => $listed,
            'noongun.drafting.providers.unlisted' => ['generator' => FakeDraftGenerator::class, 'api_key' => 'unlisted-instance-key', 'model' => 'unlisted-model'],
        ]);

        expect($this->generators->offered())->toBe($offered);
    })->with([
        'nothing listed' => [[], [DRAFT_TEST_PROVIDER]],
        'one listed' => [[SECOND_PROVIDER], [DRAFT_TEST_PROVIDER, SECOND_PROVIDER]],
        'the default listed again' => [[SECOND_PROVIDER, DRAFT_TEST_PROVIDER], [DRAFT_TEST_PROVIDER, SECOND_PROVIDER]],
        'a name that is not configured' => [['nobody', SECOND_PROVIDER], [DRAFT_TEST_PROVIDER, SECOND_PROVIDER]],
    ]);

    it('cannot be a provider the operator does not offer', function (string $provider) {
        config([
            'noongun.drafting.offered' => [],
            'noongun.drafting.providers.unlisted' => ['generator' => FakeDraftGenerator::class, 'api_key' => 'unlisted-instance-key', 'model' => 'unlisted-model'],
        ]);

        expect(fn () => chooseProvider($this->workspace, $provider))->toThrow(DraftGeneratorNotAvailable::class, 'does not offer')
            ->and(DB::table('workspaces')->where('id', $this->workspace->id)->value('drafting_provider'))->toBeNull();
    })->with(['configured, not offered' => ['unlisted'], 'offered before, not now' => [SECOND_PROVIDER], 'unknown' => ['nobody'], 'empty' => ['']]);

    it('stops drafting when the chosen provider is no longer offered, and does not fall back to the default', function () {
        chooseProvider($this->workspace, SECOND_PROVIDER);
        config(['noongun.drafting.offered' => []]);
        $this->workspace->refresh();

        expect(fn () => $this->generators->providerFor($this->workspace))->toThrow(DraftGeneratorNotAvailable::class, 'does not offer')
            ->and(fn () => $this->generators->contextFor($this->workspace))->toThrow(DraftGeneratorNotAvailable::class)
            ->and($this->generators->availableFor($this->workspace))->toBeFalse();

        try {
            proposeWeek($this->workspace, $this->owner);
            $refusal = null;
        } catch (ProposalRefused $caught) {
            $refusal = $caught;
        }

        expect($refusal?->reason)->toBe(ProposalRefused::NOT_CONFIGURED)
            ->and(DB::table('draft_runs')->count())->toBe(0);

        $this->first->assertNothingGenerated();
        $this->second->assertNothingGenerated();
    });

    it('is a column that can be taken away and added again', function () {
        $migration = require database_path('migrations/2026_11_25_200001_add_drafting_provider_to_workspaces.php');

        $migration->down();
        expect(Schema::hasColumn('workspaces', 'drafting_provider'))->toBeFalse();

        $migration->up();
        expect(Schema::hasColumn('workspaces', 'drafting_provider'))->toBeTrue()
            ->and(DB::table('workspaces')->where('id', $this->workspace->id)->value('drafting_provider'))->toBeNull();
    });
});

describe('a key belongs to one provider', function () {
    beforeEach(function () {
        $this->keys = app(LlmCredentialResolver::class);
    });

    it('never gives the key a workspace stored for one provider to another', function () {
        secondDraftProvider(instanceKey: null);
        inWorkspace($this->workspace, fn () => LlmCredential::store(DRAFT_TEST_PROVIDER, 'own-key-for-the-first-provider'));
        chooseProvider($this->workspace, SECOND_PROVIDER);
        $this->workspace->refresh();

        expect(fn () => $this->keys->resolve($this->workspace, SECOND_PROVIDER))->toThrow(LlmCredentialMissing::class)
            ->and(fn () => $this->generators->contextFor($this->workspace))->toThrow(LlmCredentialMissing::class)
            ->and($this->generators->availableFor($this->workspace))->toBeFalse();
    });

    it('never gives the instance key of one provider to another', function () {
        secondDraftProvider(instanceKey: null);
        InstanceLlmCredential::store(DRAFT_TEST_PROVIDER, 'stored-instance-key-for-the-first-provider');

        expect(fn () => $this->keys->resolve($this->workspace, SECOND_PROVIDER))->toThrow(LlmCredentialMissing::class);
    });

    it('holds a key for each provider side by side', function () {
        inWorkspace($this->workspace, function () {
            LlmCredential::store(DRAFT_TEST_PROVIDER, 'own-key-for-the-first-provider');
            LlmCredential::store(SECOND_PROVIDER, 'own-key-for-the-second-provider');
        });

        $first = $this->keys->resolve($this->workspace, DRAFT_TEST_PROVIDER);
        $second = $this->keys->resolve($this->workspace, SECOND_PROVIDER);

        expect([$first->provider, $first->reveal(), $first->scope])->toBe([DRAFT_TEST_PROVIDER, 'own-key-for-the-first-provider', CredentialScope::Workspace])
            ->and([$second->provider, $second->reveal(), $second->scope])->toBe([SECOND_PROVIDER, 'own-key-for-the-second-provider', CredentialScope::Workspace]);
    });

    it('falls back for each provider by itself: workspace key, stored instance key, environment', function () {
        expect($this->keys->resolve($this->workspace, SECOND_PROVIDER)->reveal())->toBe(SECOND_INSTANCE_KEY);

        InstanceLlmCredential::store(SECOND_PROVIDER, 'stored-instance-key-for-the-second-provider');

        expect($this->keys->resolve($this->workspace, SECOND_PROVIDER)->reveal())->toBe('stored-instance-key-for-the-second-provider')
            ->and($this->keys->resolve($this->workspace, SECOND_PROVIDER)->scope)->toBe(CredentialScope::Instance)
            ->and($this->keys->resolve($this->workspace, DRAFT_TEST_PROVIDER)->reveal())->toBe(DRAFT_TEST_KEY);

        inWorkspace($this->workspace, fn () => LlmCredential::store(SECOND_PROVIDER, 'own-key-for-the-second-provider'));

        expect($this->keys->resolve($this->workspace, SECOND_PROVIDER)->reveal())->toBe('own-key-for-the-second-provider')
            ->and($this->keys->resolve($this->workspace, SECOND_PROVIDER)->scope)->toBe(CredentialScope::Workspace)
            ->and($this->keys->resolve($this->workspace, DRAFT_TEST_PROVIDER)->reveal())->toBe(DRAFT_TEST_KEY);
    });

    it('does not fall back to the instance key when the own key of the chosen provider cannot be read', function () {
        $credential = inWorkspace($this->workspace, fn () => LlmCredential::store(SECOND_PROVIDER, 'own-key-for-the-second-provider'));
        DB::table('llm_credentials')->where('id', $credential->id)->update(['api_key' => 'not-a-ciphertext']);
        chooseProvider($this->workspace, SECOND_PROVIDER);
        $this->workspace->refresh();

        expect(fn () => $this->generators->contextFor($this->workspace))->toThrow(LlmCredentialMissing::class, 'can no longer be read')
            ->and($this->keys->resolve($this->workspace, DRAFT_TEST_PROVIDER)->reveal())->toBe(DRAFT_TEST_KEY);
    });

    it('keeps the keys of two workspaces apart for every provider', function () {
        $other = Workspace::factory()->create();
        inWorkspace($this->workspace, fn () => LlmCredential::store(SECOND_PROVIDER, 'own-key-for-the-second-provider'));
        inWorkspace($other, fn () => LlmCredential::store(SECOND_PROVIDER, 'their-key-for-the-second-provider'));
        inWorkspace($other, fn () => LlmCredential::store(DRAFT_TEST_PROVIDER, 'their-key-for-the-first-provider'));

        expect($this->keys->resolve($this->workspace, SECOND_PROVIDER)->reveal())->toBe('own-key-for-the-second-provider')
            ->and($this->keys->resolve($other, SECOND_PROVIDER)->reveal())->toBe('their-key-for-the-second-provider')
            ->and($this->keys->resolve($this->workspace, DRAFT_TEST_PROVIDER)->reveal())->toBe(DRAFT_TEST_KEY);
    });

    it('cannot be put into a context for another provider, or revealed for one', function () {
        $key = new LlmKey(DRAFT_TEST_PROVIDER, 'own-key-for-the-first-provider', CredentialScope::Workspace);

        expect(fn () => new DraftingContext(SECOND_PROVIDER, SECOND_MODEL, 2000, 20, 'v1', $key))->toThrow(LogicException::class)
            ->and(fn () => $key->revealFor(SECOND_PROVIDER))->toThrow(LogicException::class)
            ->and($key->revealFor(DRAFT_TEST_PROVIDER))->toBe('own-key-for-the-first-provider');

        try {
            $key->revealFor(SECOND_PROVIDER);
        } catch (LogicException $refused) {
            expect($refused->getMessage().$refused->getTraceAsString())->not->toContain('own-key-for-the-first-provider');
        }
    });

    it('reaches only the generator of its provider when a draft is written', function () {
        inWorkspace($this->workspace, function () {
            LlmCredential::store(DRAFT_TEST_PROVIDER, 'own-key-for-the-first-provider');
            LlmCredential::store(SECOND_PROVIDER, 'own-key-for-the-second-provider');
        });
        chooseProvider($this->workspace, SECOND_PROVIDER);
        $this->workspace->refresh();

        draftOnce($this->workspace);

        $this->first->assertNothingGenerated();
        $this->second->assertGeneratedTimes(1);

        expect($this->second->lastContext()?->provider)->toBe(SECOND_PROVIDER)
            ->and($this->second->lastContext()?->key->provider)->toBe(SECOND_PROVIDER)
            ->and($this->second->lastContext()?->key->reveal())->toBe('own-key-for-the-second-provider');
    });
});

describe('the limits', function () {
    it('count the runs of a workspace whatever their provider, so a change opens no new allowance', function () {
        config(['noongun.drafting.limits.runs_per_day' => 5]);

        inWorkspace($this->workspace, fn () => DraftRun::factory()->count(3)->succeeded()->create(['provider' => DRAFT_TEST_PROVIDER]));
        chooseProvider($this->workspace, SECOND_PROVIDER);
        $this->workspace->refresh();

        expect(inWorkspace($this->workspace, fn () => app(DraftBudget::class)->usedByWorkspace()))->toBe(3);

        try {
            proposeWeek($this->workspace, $this->owner);
            $refusal = null;
        } catch (ProposalRefused $caught) {
            $refusal = $caught;
        }

        expect($refusal?->reason)->toBe(ProposalRefused::DAILY_LIMIT)
            ->and(DB::table('draft_runs')->count())->toBe(3);
    });

    it('count the runs on the instance keys of every provider together', function () {
        config(['noongun.drafting.limits.instance_runs_per_day' => 5]);

        $other = Workspace::factory()->create();
        inWorkspace($other, fn () => DraftRun::factory()->count(3)->succeeded()->create(['provider' => DRAFT_TEST_PROVIDER, 'credential_scope' => CredentialScope::Instance]));
        chooseProvider($this->workspace, SECOND_PROVIDER);
        $this->workspace->refresh();

        expect(app(DraftBudget::class)->usedOnInstanceKey())->toBe(3);

        try {
            proposeWeek($this->workspace, $this->owner);
            $refusal = null;
        } catch (ProposalRefused $caught) {
            $refusal = $caught;
        }

        expect($refusal?->reason)->toBe(ProposalRefused::INSTANCE_LIMIT);
    });

    it('do not let a run that was opened for one provider be drafted with another', function () {
        $run = inWorkspace($this->workspace, fn () => DraftRun::start($this->owner, $this->generators->contextFor($this->workspace), [], Brief::current()));

        chooseProvider($this->workspace, SECOND_PROVIDER);
        $this->workspace->refresh();

        expect(fn () => inWorkspace($this->workspace, fn () => app(GenerateDraft::class)->handle($this->owner, $run, draftRequestIn($this->workspace, [$this->channel]))))
            ->toThrow(DraftingContextChanged::class, 'the provider');

        $this->first->assertNothingGenerated();
        $this->second->assertNothingGenerated();

        expect(freshRun($run)->status)->toBe('failed')
            ->and(freshRun($run)->provider)->toBe(DRAFT_TEST_PROVIDER)
            ->and(freshRun($run)->input_tokens)->toBeNull();
    });
});

describe('cost', function () {
    it('is worked out with the price of the provider and model of each run, and stays when the choice changes', function () {
        $before = draftOnce($this->workspace);

        chooseProvider($this->workspace, SECOND_PROVIDER);
        $this->workspace->refresh();

        $after = draftOnce($this->workspace);

        // 1200 in and 300 out: 2.00 and 10.00 per million for the first provider, 1.00 and 4.00 for the second.
        expect([freshRun($before)->provider, freshRun($before)->model, freshRun($before)->cost_estimate])->toBe([DRAFT_TEST_PROVIDER, DRAFT_TEST_MODEL, '0.005400'])
            ->and([freshRun($after)->provider, freshRun($after)->model, freshRun($after)->cost_estimate])->toBe([SECOND_PROVIDER, SECOND_MODEL, '0.002400']);
    });

    it('is shown before a proposal by the price of the provider that will be used', function () {
        $cost = fn (): ?array => inWorkspace($this->workspace, fn () => app(ProposalCost::class)->of(
            planFor($this->workspace, '2026-11-30'),
            Brief::current(),
            $this->generators->contextFor($this->workspace),
        ));

        $first = $cost();

        chooseProvider($this->workspace, SECOND_PROVIDER);
        $this->workspace->refresh();

        $second = $cost();

        expect($first)->not->toBeNull()
            ->and($second)->not->toBeNull()
            ->and((float) $second['expected']->amount)->toBeLessThan((float) $first['expected']->amount)
            ->and((float) $second['worst']->amount)->toBeLessThan((float) $first['worst']->amount);

        config(['noongun.drafting.prices.'.SECOND_PROVIDER => []]);

        expect($cost())->toBeNull();
    });

    it('prices input that went through the cache of a provider, when the table says how', function () {
        config(['noongun.drafting.prices.'.SECOND_PROVIDER.'.'.SECOND_MODEL => ['input' => 2.00, 'output' => 10.00, 'cached_input' => 0.20, 'cache_write' => 2.50]]);

        $usage = new DraftUsage(SECOND_PROVIDER, SECOND_MODEL, 10_000, 1_000, 1, cacheReadTokens: 6_000, cacheWriteTokens: 2_000);
        $costs = app(CostEstimator::class);

        // 2000 x 2.00 + 6000 x 0.20 + 2000 x 2.50 + 1000 x 10.00, per million.
        expect($costs->estimate($usage)?->amount)->toBe('0.020200')
            // Nothing is known about the cache beforehand: all input at the dearest input price.
            ->and($costs->atMost($usage)?->amount)->toBe('0.035000');

        config(['noongun.drafting.prices.'.SECOND_PROVIDER.'.'.SECOND_MODEL => ['input' => 2.00, 'output' => 10.00]]);

        expect($costs->estimate($usage)?->amount)->toBe('0.030000')
            ->and($costs->atMost($usage)?->amount)->toBe('0.030000')
            ->and(DraftingConfig::price(SECOND_PROVIDER, SECOND_MODEL))->toBe(['input' => 2.0, 'output' => 10.0, 'cached_input' => 2.0, 'cache_write' => 2.0]);
    });

    it('does not accept more cached tokens than input tokens', function () {
        expect(fn () => new DraftUsage(SECOND_PROVIDER, SECOND_MODEL, 100, 10, 1, 80, 30))->toThrow(InvalidArgumentException::class);
    });

    it('has a price for the default model of every provider in the configuration', function () {
        $config = require config_path('noongun.php');

        foreach ($config['drafting']['providers'] as $provider => $entry) {
            expect($config['drafting']['prices'][$provider][$entry['model']] ?? null)->not->toBeNull("No price for the default model of {$provider}.");
        }
    });
});
