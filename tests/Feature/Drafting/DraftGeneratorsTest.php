<?php

use App\Domain\Drafting\Contracts\DraftGenerator;
use App\Domain\Drafting\CostEstimator;
use App\Domain\Drafting\CredentialScope;
use App\Domain\Drafting\DraftGenerators;
use App\Domain\Drafting\DraftingConfig;
use App\Domain\Drafting\DraftUsage;
use App\Domain\Drafting\Exceptions\DraftGeneratorNotAvailable;
use App\Domain\Drafting\Exceptions\LlmCredentialMissing;
use App\Domain\Drafting\Models\LlmCredential;
use App\Domain\Workspaces\Models\Workspace;
use Tests\Fakes\FakeDraftGenerator;

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    $this->generators = app(DraftGenerators::class);
});

describe('the context for a workspace', function () {
    it('takes provider, model, limits and prompt version from config', function () {
        config([
            'noongun.drafting.provider' => 'acme',
            'noongun.drafting.max_tokens' => 3210,
            'noongun.drafting.timeout' => 45,
            'noongun.drafting.prompt_version' => 'v7',
            'noongun.drafting.providers.acme' => ['generator' => FakeDraftGenerator::class, 'api_key' => 'acme-instance-key', 'model' => 'acme-large-2'],
        ]);

        $context = $this->generators->contextFor($this->workspace);

        expect($context->provider)->toBe('acme')
            ->and($context->model)->toBe('acme-large-2')
            ->and($context->maxTokens)->toBe(3210)
            ->and($context->timeoutSeconds)->toBe(45)
            ->and($context->promptVersion)->toBe('v7')
            ->and($context->key->reveal())->toBe('acme-instance-key')
            ->and($context->key->scope)->toBe(CredentialScope::Instance)
            ->and($this->generators->for($context))->toBeInstanceOf(FakeDraftGenerator::class);
    });

    it('carries the workspace key when the workspace brought one', function () {
        fakeDraftGenerator();
        inWorkspace($this->workspace, fn () => LlmCredential::store(DRAFT_TEST_PROVIDER, 'own-workspace-llm-key'));

        $own = $this->generators->contextFor($this->workspace);
        $other = $this->generators->contextFor(Workspace::factory()->create());

        expect($own->key->reveal())->toBe('own-workspace-llm-key')
            ->and($own->key->scope)->toBe(CredentialScope::Workspace)
            ->and($other->key->reveal())->toBe(DRAFT_TEST_KEY)
            ->and($other->key->scope)->toBe(CredentialScope::Instance);
    });

    it('fails clearly when no key exists', function () {
        fakeDraftGenerator();
        config(['noongun.drafting.providers.'.DRAFT_TEST_PROVIDER.'.api_key' => null]);

        expect(fn () => $this->generators->contextFor($this->workspace))->toThrow(LlmCredentialMissing::class)
            ->and($this->generators->availableFor($this->workspace))->toBeFalse();
    });

    it('fails clearly when the configuration is incomplete', function (array $config, string $message) {
        config(['noongun.drafting.providers' => ['acme' => ['generator' => FakeDraftGenerator::class, 'api_key' => 'k', 'model' => 'acme-large-2']]]);
        config($config);

        expect(fn () => $this->generators->contextFor($this->workspace))->toThrow(DraftGeneratorNotAvailable::class, $message)
            ->and($this->generators->availableFor($this->workspace))->toBeFalse();
    })->with([
        'no default provider' => [['noongun.drafting.provider' => ''], 'No default drafting provider is set'],
        'an unknown provider' => [['noongun.drafting.provider' => 'nobody'], 'No draft generator is configured for provider [nobody]'],
        'no model' => [['noongun.drafting.provider' => 'acme', 'noongun.drafting.providers.acme.model' => ''], 'No model is configured for provider [acme]'],
    ]);

    it('replaces limits that make no sense with safe ones', function () {
        config(['noongun.drafting.max_tokens' => 0, 'noongun.drafting.timeout' => 'soon']);

        expect(DraftingConfig::maxTokens())->toBeGreaterThanOrEqual(256)
            ->and(DraftingConfig::timeoutSeconds())->toBeGreaterThanOrEqual(5);
    });
});

describe('the generator for a provider', function () {
    it('is built through the container from the configured class, once', function () {
        config(['noongun.drafting.providers.acme' => ['generator' => FakeDraftGenerator::class]]);

        $generator = $this->generators->generator('acme');

        expect($generator)->toBeInstanceOf(DraftGenerator::class)
            ->and($this->generators->generator('acme'))->toBe($generator)
            ->and($this->generators->forgetGenerators()->generator('acme'))->not->toBe($generator)
            ->and($this->generators->providers())->toContain('acme');
    });

    it('fails clearly, and only when asked for', function (mixed $class, string $message) {
        config(['noongun.drafting.providers.acme' => ['generator' => $class]]);

        expect($this->generators->providers())->toContain('acme')
            ->and(fn () => $this->generators->generator('acme'))->toThrow(DraftGeneratorNotAvailable::class, $message);
    })->with([
        'no class' => [null, 'No draft generator is configured for provider [acme]'],
        'a class that does not exist' => ['App\\Domain\\Drafting\\Generators\\NoSuchGenerator', 'that class does not exist'],
        'a class that is not a generator' => [CostEstimator::class, 'does not implement DraftGenerator'],
    ]);

    it('can be replaced with a fake, which is how every other test drafts', function () {
        $fake = fakeDraftGenerator();
        $context = $this->generators->contextFor($this->workspace);

        $result = $this->generators->for($context)->generate(plainDraftRequest(2), $context);

        expect($this->generators->for($context))->toBe($fake)
            ->and($this->generators->availableFor($this->workspace))->toBeTrue()
            ->and($result->bodies)->toBe([1 => 'Generated draft 1 for facebook.', 2 => 'Generated draft 1 for facebook.'])
            ->and($result->usage->provider)->toBe(DRAFT_TEST_PROVIDER)
            ->and($result->usage->model)->toBe(DRAFT_TEST_MODEL)
            ->and($fake->lastContext())->toBe($context);

        $fake->assertGeneratedTimes(1);
    });
});

describe('the shipped configuration', function () {
    it('names a default provider that is configured with a model and a price for it', function () {
        $provider = DraftingConfig::defaultProvider();
        $model = DraftingConfig::model((string) $provider);

        expect($provider)->not->toBeNull()
            ->and(array_keys(DraftingConfig::providers()))->toContain($provider)
            ->and($model)->not->toBeNull()
            ->and(DraftingConfig::generatorClass((string) $provider))->not->toBeNull()
            ->and(DraftingConfig::price((string) $provider, (string) $model))->not->toBeNull()
            ->and(DraftingConfig::promptVersion())->not->toBe('');
    });

    it('holds no key in tests', function () {
        foreach (array_keys(DraftingConfig::providers()) as $provider) {
            expect(DraftingConfig::instanceKey($provider))->toBeNull();
        }

        expect(config('prism.providers.anthropic.api_key'))->toBeEmpty();
    });
});

describe('estimating cost', function () {
    beforeEach(function () {
        fakeDraftGenerator();
        $this->costs = app(CostEstimator::class);
    });

    it('multiplies tokens by the configured price per million', function () {
        $estimate = $this->costs->estimate(new DraftUsage(DRAFT_TEST_PROVIDER, DRAFT_TEST_MODEL, 1200, 300));

        expect($estimate?->amount)->toBe('0.005400')
            ->and($estimate->currency)->toBe('USD');
    });

    it('follows the price table and the currency, wherever they are changed', function () {
        config([
            'noongun.drafting.prices.'.DRAFT_TEST_PROVIDER.'.'.DRAFT_TEST_MODEL => ['input' => '0.25', 'output' => 1.25],
            'noongun.drafting.currency' => 'zar',
        ]);

        $estimate = $this->costs->estimate(new DraftUsage(DRAFT_TEST_PROVIDER, DRAFT_TEST_MODEL, 1_000_000, 2_000_000));

        expect($estimate?->amount)->toBe('2.750000')
            ->and($estimate->currency)->toBe('ZAR');
    });

    it('rounds to a millionth and handles a run that used nothing', function () {
        expect($this->costs->estimate(new DraftUsage(DRAFT_TEST_PROVIDER, DRAFT_TEST_MODEL, 1, 0))?->amount)->toBe('0.000002')
            ->and($this->costs->estimate(new DraftUsage(DRAFT_TEST_PROVIDER, DRAFT_TEST_MODEL, 0, 0))?->amount)->toBe('0.000000')
            ->and($this->costs->estimate(new DraftUsage(DRAFT_TEST_PROVIDER, DRAFT_TEST_MODEL, 3_000_000_000, 1_000_000_000))?->amount)->toBe('16000.000000');
    });

    it('prices the model that answered, or else the one that was asked for', function () {
        config(['noongun.drafting.prices.'.DRAFT_TEST_PROVIDER.'.fake-model-20270101' => ['input' => 4, 'output' => 20]]);

        $dated = new DraftUsage(DRAFT_TEST_PROVIDER, 'fake-model-20270101', 1200, 300);
        $unknown = new DraftUsage(DRAFT_TEST_PROVIDER, 'fake-model-unlisted', 1200, 300);

        expect($this->costs->estimate($dated, DRAFT_TEST_MODEL)?->amount)->toBe('0.010800')
            ->and($this->costs->estimate($unknown, DRAFT_TEST_MODEL)?->amount)->toBe('0.005400');
    });

    it('gives no estimate without a usable price', function (mixed $price) {
        config(['noongun.drafting.prices.'.DRAFT_TEST_PROVIDER.'.'.DRAFT_TEST_MODEL => $price]);

        expect($this->costs->estimate(new DraftUsage(DRAFT_TEST_PROVIDER, DRAFT_TEST_MODEL, 1200, 300)))->toBeNull();
    })->with([
        'no entry' => [null],
        'half an entry' => [['input' => 2.0]],
        'not numbers' => [['input' => 'cheap', 'output' => 'dear']],
        'a negative price' => [['input' => -1, 'output' => 10]],
    ]);

    it('gives no estimate for another provider', function () {
        expect($this->costs->estimate(new DraftUsage('nobody', DRAFT_TEST_MODEL, 1200, 300)))->toBeNull();
    });
});
