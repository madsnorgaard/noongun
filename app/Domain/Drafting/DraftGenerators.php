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
     * The provider a workspace drafts with: the one its owner chose, or the
     * default of the instance when none was chosen. This is the one place
     * that decides it.
     *
     * A choice the instance does not offer (any more) is not replaced by
     * the default: the brief would go to a vendor the workspace did not
     * choose. Drafting stops until an owner chooses again.
     *
     * @throws DraftGeneratorNotAvailable
     */
    public function providerFor(Workspace $workspace): string
    {
        $chosen = $workspace->drafting_provider;

        if ($chosen === null) {
            return DraftingConfig::defaultProvider() ?? throw DraftGeneratorNotAvailable::noDefaultProvider();
        }

        if (! in_array($chosen, $this->offered(), true)) {
            throw DraftGeneratorNotAvailable::notOffered($chosen, $workspace->id);
        }

        return $chosen;
    }

    /**
     * The providers a workspace may choose from: those the operator offers
     * (the default one and NOONGUN_DRAFT_PROVIDERS) that are configured.
     *
     * @return list<string>
     */
    public function offered(): array
    {
        return array_values(array_intersect(DraftingConfig::offeredProviders(), $this->providers()));
    }

    /**
     * Makes the provider the one the workspace drafts with from now on.
     * Runs that were opened for another provider are not drafted with this
     * one (DraftingContextChanged), and the daily limits count runs
     * whatever their provider, so a change opens no new allowance.
     *
     * @throws DraftGeneratorNotAvailable when the instance does not offer the provider
     */
    public function choose(Workspace $workspace, string $provider): void
    {
        if (! in_array($provider, $this->offered(), true)) {
            throw DraftGeneratorNotAvailable::notOffered($provider, $workspace->id);
        }

        $workspace->drafting_provider = $provider;
        $workspace->save();
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
