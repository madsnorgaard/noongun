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
 *
 * A stored key that cannot be decrypted (APP_KEY was changed) is no key. For
 * the workspace's own key that ends the search: the workspace chose to pay
 * itself, so the instance key does not step in silently, and the owner is
 * told to enter the key again. An unreadable stored instance key is passed
 * over for the one in the environment.
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

        $ownKey = $own?->readableKey();

        if ($own !== null && $ownKey === null) {
            throw LlmCredentialMissing::unreadable($provider, $workspace);
        }

        if ($ownKey !== null && $ownKey !== '') {
            return new LlmKey($provider, $ownKey, CredentialScope::Workspace);
        }

        $stored = InstanceLlmCredential::query()->where('provider', $provider)->first();
        $storedKey = $stored?->readableKey();

        if ($storedKey !== null && $storedKey !== '') {
            return new LlmKey($provider, $storedKey, CredentialScope::Instance);
        }

        $fromEnvironment = DraftingConfig::instanceKey($provider);

        if ($fromEnvironment !== null) {
            return new LlmKey($provider, $fromEnvironment, CredentialScope::Instance);
        }

        throw LlmCredentialMissing::for($provider, $workspace, storedInstanceKeyUnreadable: $stored !== null && $storedKey === null);
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
