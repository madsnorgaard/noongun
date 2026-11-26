<?php

/*
 * The Drafting settings page with more than one provider: an owner chooses
 * the provider among those the instance offers, and stores, replaces and
 * removes a key for each of them. These tests hold that a key lands under
 * the provider it was entered for and under no other, that the choice is
 * guarded like the keys, and that the page says which key each provider
 * would use without ever showing one.
 */

use App\Domain\Content\Models\Post;
use App\Domain\Drafting\Generators\PrismDraftGenerator;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Drafting\Models\InstanceLlmCredential;
use App\Domain\Drafting\Models\LlmCredential;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Http\Controllers\Settings\LlmKeyController;
use App\Http\Presenters\PostPresenter;
use App\Http\Presenters\ProposalPresenter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Prism\Prism\Facades\Prism;

require_once __DIR__.'/../../Support/members.php';

const ANTHROPIC_PAGE_KEY = 'sk-ant-api03-ProviderPageAnthropicKeyNeverToLeak-0123-aaaa';

const OPENAI_PAGE_KEY = 'sk-proj-ProviderPageOpenAiKeyNeverToLeak-0123456789-oooo';

beforeEach(function () {
    $this->travelTo('2026-11-25 10:00:00');

    config([
        'noongun.drafting.provider' => 'anthropic',
        'noongun.drafting.offered' => ['openai'],
        'noongun.drafting.providers' => [
            'anthropic' => [
                'label' => 'Anthropic',
                'generator' => PrismDraftGenerator::class,
                'api_key' => null,
                'model' => 'configured-model',
                'key_prefix' => 'sk-ant-',
            ],
            'openai' => [
                'label' => 'OpenAI',
                'generator' => PrismDraftGenerator::class,
                'api_key' => null,
                'model' => 'configured-openai-model',
                'key_prefix' => 'sk-',
            ],
            'unlisted' => [
                'generator' => PrismDraftGenerator::class,
                'api_key' => null,
                'model' => 'unlisted-model',
            ],
        ],
    ]);

    $this->workspace = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
    $this->owner = workspaceMember($this->workspace, Role::Owner);
});

function confirmed(User $user): mixed
{
    return test()->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('llm-key.edit'));
}

function chooseProviderAs(User $user, mixed $provider): TestResponse
{
    return confirmed($user)->put(route('llm-key.choose'), ['provider' => $provider]);
}

function storeProviderKeyAs(User $user, string $provider, string $key): TestResponse
{
    return confirmed($user)->put(route('llm-key.update'), ['provider' => $provider, 'api_key' => $key]);
}

function removeProviderKeyAs(User $user, string $provider): TestResponse
{
    return confirmed($user)->delete(route('llm-key.destroy'), ['provider' => $provider]);
}

function keyStoredFor(Workspace $workspace, string $provider): ?string
{
    return inWorkspace($workspace, fn () => LlmCredential::query()->where('provider', $provider)->first()?->api_key);
}

function chosenProviderOf(Workspace $workspace): ?string
{
    return DB::table('workspaces')->where('id', $workspace->id)->value('drafting_provider');
}

describe('choosing the provider', function () {
    it('makes the workspace draft with it', function () {
        chooseProviderAs($this->owner, 'openai')
            ->assertRedirect(route('llm-key.edit'))
            ->assertSessionHasNoErrors();

        expect(chosenProviderOf($this->workspace))->toBe('openai');

        $this->actingAs($this->owner)->get(route('llm-key.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('provider', ['name' => 'openai', 'label' => 'OpenAI'])
                ->where('chosen_not_offered', null));
    });

    it('refuses a provider the instance does not offer', function (mixed $provider) {
        chooseProviderAs($this->owner, $provider)->assertSessionHasErrors('provider');

        expect(chosenProviderOf($this->workspace))->toBeNull();
    })->with([
        'configured, not offered' => ['unlisted'],
        'unknown' => ['nobody'],
        'empty' => [''],
        'not a name' => [['openai']],
    ]);

    it('is for owners only', function (Role $role) {
        chooseProviderAs(workspaceMember($this->workspace, $role), 'openai')->assertForbidden();

        expect(chosenProviderOf($this->workspace))->toBeNull();
    })->with([Role::Editor, Role::Viewer]);

    it('needs a recently confirmed password', function (array $session) {
        $this->actingAs($this->owner)->withSession($session)->from(route('llm-key.edit'))
            ->put(route('llm-key.choose'), ['provider' => 'openai'])
            ->assertRedirect(route('password.confirm'));

        expect(chosenProviderOf($this->workspace))->toBeNull();
    })->with([
        'never confirmed' => [[]],
        'confirmed too long ago' => [['auth.password_confirmed_at' => 1]],
    ]);

    it('is rate limited', function () {
        foreach (range(1, 6) as $attempt) {
            chooseProviderAs($this->owner, 'nobody')->assertRedirect();
        }

        chooseProviderAs($this->owner, 'openai')->assertTooManyRequests();

        expect(chosenProviderOf($this->workspace))->toBeNull();
    });

    it('changes one workspace only', function () {
        $other = Workspace::factory()->create();

        chooseProviderAs($this->owner, 'openai');

        expect(chosenProviderOf($other))->toBeNull();
    });

    it('calls no provider', function () {
        $fake = Prism::fake();

        chooseProviderAs($this->owner, 'openai');

        $fake->assertCallCount(0);
        Http::assertNothingSent();
    });
});

describe('a key for each provider', function () {
    it('is stored under the provider it was entered for, and under no other', function () {
        storeProviderKeyAs($this->owner, 'openai', OPENAI_PAGE_KEY)->assertSessionHasNoErrors();

        expect(keyStoredFor($this->workspace, 'openai'))->toBe(OPENAI_PAGE_KEY)
            ->and(keyStoredFor($this->workspace, 'anthropic'))->toBeNull();

        storeProviderKeyAs($this->owner, 'anthropic', ANTHROPIC_PAGE_KEY)->assertSessionHasNoErrors();

        expect(keyStoredFor($this->workspace, 'openai'))->toBe(OPENAI_PAGE_KEY)
            ->and(keyStoredFor($this->workspace, 'anthropic'))->toBe(ANTHROPIC_PAGE_KEY)
            ->and(DB::table('llm_credentials')->count())->toBe(2);
    });

    it('goes to the provider in use when the form names none', function () {
        chooseProviderAs($this->owner, 'openai');

        confirmed($this->owner)->put(route('llm-key.update'), ['api_key' => OPENAI_PAGE_KEY])->assertSessionHasNoErrors();

        expect(keyStoredFor($this->workspace, 'openai'))->toBe(OPENAI_PAGE_KEY)
            ->and(keyStoredFor($this->workspace, 'anthropic'))->toBeNull();
    });

    it('is refused for a provider the instance does not offer', function (string $provider) {
        storeProviderKeyAs($this->owner, $provider, OPENAI_PAGE_KEY)->assertSessionHasErrors('provider');

        expect(DB::table('llm_credentials')->count())->toBe(0);
    })->with(['unlisted', 'nobody']);

    it('is refused when it looks like the key of another provider, without repeating it', function (string $provider, string $key, string $looksLike) {
        $response = storeProviderKeyAs($this->owner, $provider, $key);

        $response->assertSessionHasErrors('api_key');

        $errors = implode(' ', session('errors')->get('api_key'));

        expect($errors)->toContain("looks like a key for {$looksLike}")
            ->and($errors)->not->toContain($key)
            ->and($errors)->not->toContain(substr($key, 0, 12))
            ->and(DB::table('llm_credentials')->count())->toBe(0);
    })->with([
        'an Anthropic key under OpenAI' => ['openai', ANTHROPIC_PAGE_KEY, 'Anthropic'],
        'an OpenAI key under Anthropic' => ['anthropic', OPENAI_PAGE_KEY, 'OpenAI'],
    ]);

    it('is removed for the named provider and stays for the other', function () {
        storeProviderKeyAs($this->owner, 'openai', OPENAI_PAGE_KEY);
        storeProviderKeyAs($this->owner, 'anthropic', ANTHROPIC_PAGE_KEY);

        removeProviderKeyAs($this->owner, 'openai')->assertRedirect(route('llm-key.edit'));

        expect(keyStoredFor($this->workspace, 'openai'))->toBeNull()
            ->and(keyStoredFor($this->workspace, 'anthropic'))->toBe(ANTHROPIC_PAGE_KEY);
    });

    it('can be removed for a provider the instance stopped offering', function () {
        storeProviderKeyAs($this->owner, 'openai', OPENAI_PAGE_KEY);
        config(['noongun.drafting.offered' => []]);

        removeProviderKeyAs($this->owner, 'openai');

        expect(DB::table('llm_credentials')->count())->toBe(0);
    });

    it('is stored and removed by owners only, whatever provider is named', function (Role $role) {
        inWorkspace($this->workspace, fn () => LlmCredential::store('openai', OPENAI_PAGE_KEY));
        $member = workspaceMember($this->workspace, $role);

        storeProviderKeyAs($member, 'anthropic', ANTHROPIC_PAGE_KEY)->assertForbidden();
        removeProviderKeyAs($member, 'openai')->assertForbidden();

        expect(keyStoredFor($this->workspace, 'openai'))->toBe(OPENAI_PAGE_KEY)
            ->and(keyStoredFor($this->workspace, 'anthropic'))->toBeNull();
    })->with([Role::Editor, Role::Viewer]);

    it('of another workspace is not touched by naming a provider', function () {
        $other = Workspace::factory()->create();
        inWorkspace($other, fn () => LlmCredential::store('openai', OPENAI_PAGE_KEY));

        removeProviderKeyAs($this->owner, 'openai');
        storeProviderKeyAs($this->owner, 'anthropic', ANTHROPIC_PAGE_KEY);

        expect(keyStoredFor($other, 'openai'))->toBe(OPENAI_PAGE_KEY)
            ->and(keyStoredFor($other, 'anthropic'))->toBeNull();
    });
});

describe('a workspace settles on the provider in use', function () {
    it('when an owner stores a key for it', function () {
        storeProviderKeyAs($this->owner, 'anthropic', ANTHROPIC_PAGE_KEY)->assertSessionHasNoErrors();

        expect(chosenProviderOf($this->workspace))->toBe('anthropic');

        config(['noongun.drafting.provider' => 'openai', 'noongun.drafting.offered' => ['anthropic'], 'noongun.drafting.providers.openai.api_key' => OPENAI_PAGE_KEY]);

        $this->actingAs($this->owner)->get(route('llm-key.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('provider.name', 'anthropic')
                ->where('key_source', 'workspace')
                ->where('follows_default', false));
    });

    it('and not when the key is for another provider', function () {
        storeProviderKeyAs($this->owner, 'openai', OPENAI_PAGE_KEY)->assertSessionHasNoErrors();

        expect(chosenProviderOf($this->workspace))->toBeNull();
    });

    it('and not when the key is refused', function () {
        storeProviderKeyAs($this->owner, 'anthropic', 'too short')->assertSessionHasErrors('api_key');

        expect(chosenProviderOf($this->workspace))->toBeNull();
    });

    it('and keeps a choice an owner made when a key for it is replaced', function () {
        chooseProviderAs($this->owner, 'openai');
        storeProviderKeyAs($this->owner, 'anthropic', ANTHROPIC_PAGE_KEY);
        storeProviderKeyAs($this->owner, 'openai', OPENAI_PAGE_KEY);

        expect(chosenProviderOf($this->workspace))->toBe('openai');
    });
});

describe('what the page says is sent', function () {
    it('names every part of a draft request, and no part that is not in one', function () {
        expect(array_keys(LlmKeyController::SENT_TO_PROVIDER))->toBe(array_keys(plainDraftRequest()->toArray()));
    });

    it('is given to the page as that list', function () {
        $this->actingAs($this->owner)->get(route('llm-key.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sent_to_provider', array_values(LlmKeyController::SENT_TO_PROVIDER))
                ->where('follows_default', true));
    });

    it('is not written into the page a second time', function () {
        $page = (string) file_get_contents(resource_path('js/pages/settings/Drafting.vue'));

        expect($page)->not->toContain('and nothing else')
            ->and($page)->toContain('sentToProvider');
    });
});

describe('the name of a provider', function () {
    it('is spelled as the configuration spells it, on the plan page and on a drafted post', function () {
        config(['noongun.drafting.providers.openai.api_key' => OPENAI_PAGE_KEY]);
        chooseProviderAs($this->owner, 'openai');

        expect(app(ProposalPresenter::class)->generator($this->workspace->refresh()))->toBe(['provider' => 'OpenAI', 'model' => 'configured-openai-model']);

        $post = inWorkspace($this->workspace, function () {
            $run = DraftRun::factory()->succeeded()->create(['provider' => 'openai', 'model' => 'configured-openai-model']);
            $post = Post::factory()->create();
            $post->attributeTo($run);

            return $post->refresh();
        });

        expect(inWorkspace($this->workspace, fn () => app(PostPresenter::class)->origin($post))['provider'] ?? null)->toBe('OpenAI');
    });

    it('is spelled right in the configuration that ships', function () {
        $config = require config_path('noongun.php');

        expect($config['drafting']['providers']['openai']['label'])->toBe('OpenAI')
            ->and($config['drafting']['providers']['anthropic']['label'])->toBe('Anthropic');
    });
});

describe('the page', function () {
    it('lists the offered providers with the key each would use, and never a key', function () {
        config(['noongun.drafting.providers.anthropic.api_key' => ANTHROPIC_PAGE_KEY]);
        inWorkspace($this->workspace, fn () => LlmCredential::store('openai', OPENAI_PAGE_KEY));

        $response = $this->actingAs($this->owner)->get(route('llm-key.edit'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('provider', ['name' => 'anthropic', 'label' => 'Anthropic'])
            ->where('key_source', 'instance')
            ->where('credential', null)
            ->where('providers', [
                ['name' => 'anthropic', 'label' => 'Anthropic', 'offered' => true, 'in_use' => true, 'key_source' => 'instance', 'credential' => null],
                ['name' => 'openai', 'label' => 'OpenAI', 'offered' => true, 'in_use' => false, 'key_source' => 'workspace', 'credential' => ['unreadable' => false, 'last_four' => 'oooo', 'set_at' => '2026-11-25T10:00:00+00:00']],
            ]));

        expect((string) $response->getContent())->not->toContain(OPENAI_PAGE_KEY)
            ->not->toContain(ANTHROPIC_PAGE_KEY)
            ->not->toContain('NeverToLeak');
    });

    it('says which key source each provider has: its own, the stored instance key, the environment, none', function () {
        InstanceLlmCredential::store('openai', OPENAI_PAGE_KEY);

        $this->actingAs($this->owner)->get(route('llm-key.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('available', false)
                ->where('providers.0.key_source', null)
                ->where('providers.1.key_source', 'instance')
                ->where('providers.1.credential', null));
    });

    it('lists only the default provider when the operator offers no other', function () {
        config(['noongun.drafting.offered' => []]);

        $this->actingAs($this->owner)->get(route('llm-key.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('providers', 1)
                ->where('providers.0.name', 'anthropic'));
    });

    it('still lists a key of a provider that is no longer offered, so it can be removed', function () {
        inWorkspace($this->workspace, fn () => LlmCredential::store('openai', OPENAI_PAGE_KEY));
        config(['noongun.drafting.offered' => []]);

        $this->actingAs($this->owner)->get(route('llm-key.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('providers', 2)
                ->where('providers.1.name', 'openai')
                ->where('providers.1.offered', false)
                ->where('providers.1.in_use', false));
    });

    it('says so when the chosen provider is no longer offered, and drafting is not available', function () {
        config(['noongun.drafting.providers.anthropic.api_key' => ANTHROPIC_PAGE_KEY, 'noongun.drafting.providers.openai.api_key' => OPENAI_PAGE_KEY]);
        chooseProviderAs($this->owner, 'openai');
        config(['noongun.drafting.offered' => []]);

        $this->actingAs($this->owner)->get(route('llm-key.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('provider', null)
                ->where('available', false)
                ->where('chosen_not_offered', 'OpenAI')
                ->where('providers.0.in_use', false));
    });

    it('tells editors and viewers nothing about providers and keys', function (Role $role) {
        inWorkspace($this->workspace, fn () => LlmCredential::store('openai', OPENAI_PAGE_KEY));

        $response = $this->actingAs(workspaceMember($this->workspace, $role))->get(route('llm-key.edit'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('providers', [])
            ->where('key_source', null)
            ->where('credential', null)
            ->where('can.manage', false));

        expect((string) $response->getContent())->not->toContain('oooo');
    })->with([Role::Editor, Role::Viewer]);

    it('does not show the keys of another workspace', function () {
        $other = Workspace::factory()->create();
        inWorkspace($other, fn () => LlmCredential::store('openai', OPENAI_PAGE_KEY));

        $this->actingAs($this->owner)->get(route('llm-key.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('providers.1.credential', null)
                ->where('providers.1.key_source', null));
    });

    it('calls no provider', function () {
        $fake = Prism::fake();
        inWorkspace($this->workspace, fn () => LlmCredential::store('openai', OPENAI_PAGE_KEY));

        $this->actingAs($this->owner)->get(route('llm-key.edit'))->assertOk();

        $fake->assertCallCount(0);
        Http::assertNothingSent();
    });
});
