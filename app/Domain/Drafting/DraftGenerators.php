<?php

namespace App\Domain\Drafting;

use App\Domain\Drafting\Contracts\DraftGenerator;
use App\Domain\Drafting\Exceptions\DraftGeneratorNotAvailable;
use App\Domain\Drafting\Exceptions\LlmCredentialMissing;
use App\Domain\Workspaces\Models\Workspace;
use Closure;
use Illuminate\Contracts\Container\Container;

/**
 * Gives the DraftGenerator for a provider and the DraftingContext for a
 * workspace, in the style of ChannelManager. Providers come from
 * config('noongun.drafting.providers'):
 *
 *     'anthropic' => ['generator' => PrismDraftGenerator::class, 'model' => ..., 'api_key' => ...]
 *
 * The usual call is:
 *
 *     $context = $generators->contextFor($workspace);
 *     $result = $generators->for($context)->generate($request, $context);
 *
 * Nothing is resolved until a generator is asked for, so a configured class
 * that does not exist fails with a clear exception at that point, not at
 * boot.
 */
class DraftGenerators
{
    /** @var array<string, DraftGenerator> */
    private array $generators = [];

    /** @var array<string, Closure(Container): DraftGenerator> */
    private array $customCreators = [];

    public function __construct(
        private readonly Container $container,
        private readonly LlmCredentialResolver $credentials,
    ) {}

    /**
     * The provider a workspace drafts with. Every workspace uses the
     * configured default for now; a choice per workspace (M5) is added here
     * and nowhere else.
     *
     * @throws DraftGeneratorNotAvailable
     */
    public function providerFor(Workspace $workspace): string
    {
        return DraftingConfig::defaultProvider() ?? throw DraftGeneratorNotAvailable::noDefaultProvider();
    }

    /**
     * Provider, model, limits, prompt version and key for one workspace.
     *
     * @throws DraftGeneratorNotAvailable when the provider is not configured or has no model
     * @throws LlmCredentialMissing when neither the workspace nor the instance has a key
     */
    public function contextFor(Workspace $workspace): DraftingContext
    {
        $provider = $this->providerFor($workspace);

        if (! in_array($provider, $this->providers(), true)) {
            throw DraftGeneratorNotAvailable::notConfigured($provider);
        }

        return new DraftingContext(
            provider: $provider,
            model: DraftingConfig::model($provider) ?? throw DraftGeneratorNotAvailable::modelMissing($provider),
            maxTokens: DraftingConfig::maxTokens(),
            timeoutSeconds: DraftingConfig::timeoutSeconds(),
            promptVersion: DraftingConfig::promptVersion(),
            key: $this->credentials->resolve($workspace, $provider),
        );
    }

    /**
     * Whether drafts can be requested for the workspace right now: the
     * provider is configured, its generator can be built and a key exists.
     */
    public function availableFor(Workspace $workspace): bool
    {
        try {
            $this->for($this->contextFor($workspace));

            return true;
        } catch (DraftGeneratorNotAvailable|LlmCredentialMissing) {
            return false;
        }
    }

    /**
     * @throws DraftGeneratorNotAvailable
     */
    public function for(DraftingContext $context): DraftGenerator
    {
        return $this->generator($context->provider);
    }

    /**
     * @throws DraftGeneratorNotAvailable
     */
    public function generator(string $provider): DraftGenerator
    {
        return $this->generators[$provider] ??= $this->resolve($provider);
    }

    /**
     * Every provider name that is configured or registered with extend().
     *
     * @return list<string>
     */
    public function providers(): array
    {
        return array_values(array_unique([
            ...array_keys(DraftingConfig::providers()),
            ...array_keys($this->customCreators),
        ]));
    }

    /**
     * Register or replace the generator of a provider at runtime. Tests use
     * this to swap in a fake.
     *
     * @param  Closure(Container): DraftGenerator  $creator
     */
    public function extend(string $provider, Closure $creator): static
    {
        $this->customCreators[$provider] = $creator;
        unset($this->generators[$provider]);

        return $this;
    }

    /**
     * Drop resolved generators, so the next call builds them again.
     */
    public function forgetGenerators(): static
    {
        $this->generators = [];

        return $this;
    }

    private function resolve(string $provider): DraftGenerator
    {
        if (isset($this->customCreators[$provider])) {
            return $this->customCreators[$provider]($this->container);
        }

        $class = DraftingConfig::generatorClass($provider) ?? throw DraftGeneratorNotAvailable::notConfigured($provider);

        if (! class_exists($class)) {
            throw DraftGeneratorNotAvailable::classMissing($provider, $class);
        }

        if (! is_subclass_of($class, DraftGenerator::class)) {
            throw DraftGeneratorNotAvailable::notAGenerator($provider, $class);
        }

        return $this->container->make($class);
    }
}
