<?php

namespace App\Domain\Drafting;

use App\Domain\Drafting\Exceptions\LlmCredentialMissing;
use App\Domain\Drafting\Models\InstanceLlmCredential;
use App\Domain\Drafting\Models\LlmCredential;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;

/**
 * Finds the API key a workspace uses for a provider. The first that exists
 * wins:
 *
 * 1. the workspace's own key (bring your own key);
 * 2. the instance key the operator stored in the database;
 * 3. the instance key from the environment, config('noongun.drafting.providers.*.api_key').
 *
 * The scope of the result says who pays, and is recorded on the draft run.
 */
class LlmCredentialResolver
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /**
     * @throws LlmCredentialMissing when there is no key at any of the three places
     */
    public function resolve(Workspace $workspace, string $provider): LlmKey
    {
        $own = $this->current->run(
            $workspace,
            fn (): ?LlmCredential => LlmCredential::query()->where('provider', $provider)->first(),
        );

        if ($own !== null && $own->api_key !== '') {
            return new LlmKey($provider, $own->api_key, CredentialScope::Workspace);
        }

        $stored = InstanceLlmCredential::query()->where('provider', $provider)->first();

        if ($stored !== null && $stored->api_key !== '') {
            return new LlmKey($provider, $stored->api_key, CredentialScope::Instance);
        }

        $fromEnvironment = DraftingConfig::instanceKey($provider);

        if ($fromEnvironment !== null) {
            return new LlmKey($provider, $fromEnvironment, CredentialScope::Instance);
        }

        throw LlmCredentialMissing::for($provider, $workspace);
    }

    public function has(Workspace $workspace, string $provider): bool
    {
        try {
            $this->resolve($workspace, $provider);

            return true;
        } catch (LlmCredentialMissing) {
            return false;
        }
    }
}
