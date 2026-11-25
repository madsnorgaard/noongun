<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Drafting\DraftGenerators;
use App\Domain\Drafting\DraftingConfig;
use App\Domain\Drafting\Exceptions\DraftGeneratorNotAvailable;
use App\Domain\Drafting\Exceptions\LlmCredentialMissing;
use App\Domain\Drafting\LlmCredentialResolver;
use App\Domain\Drafting\Models\LlmCredential;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DraftingProviderRequest;
use App\Http\Requests\Settings\LlmKeyRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Drafting settings of a workspace: which provider writes its drafts, and
 * its own API key for each provider - store it, replace it, remove it. A
 * key is write-only. Nothing here returns it, and the page gets the
 * provider, the last four characters, when it was set and whose key
 * drafting uses.
 *
 * A key belongs to one provider. It is stored under that provider's name
 * and looked up by it, so the key of one provider is never used for
 * another.
 *
 * Owners choose the provider and manage the keys. Editors and viewers
 * learn whether drafting is available and nothing about keys.
 */
class LlmKeyController extends Controller
{
    public function edit(Request $request, CurrentWorkspace $current, DraftGenerators $generators, LlmCredentialResolver $keys): Response
    {
        $workspace = $current->get();

        abort_if($workspace === null, 403);

        $provider = $this->providerOf($workspace, $generators);
        $canManage = $request->user()?->can('viewAny', LlmCredential::class) ?? false;
        $credentials = $canManage ? LlmCredential::query()->get()->keyBy('provider') : new Collection;
        $inEffect = $provider !== null ? $credentials->get($provider) : null;

        return Inertia::render('settings/Drafting', [
            'provider' => $provider === null ? null : [
                'name' => $provider,
                'label' => DraftingConfig::label($provider),
            ],
            'available' => $generators->availableFor($workspace),
            'key_source' => $canManage && $provider !== null ? $this->keySource($workspace, $provider, $keys) : null,
            'credential' => $inEffect instanceof LlmCredential ? $this->credentialFacts($inEffect) : null,
            'chosen_not_offered' => $provider === null && $workspace->drafting_provider !== null
                ? DraftingConfig::label($workspace->drafting_provider)
                : null,
            'providers' => $canManage ? $this->providerRows($workspace, $provider, $generators, $keys, $credentials) : [],
            'can' => [
                'manage' => $canManage,
            ],
        ]);
    }

    public function choose(DraftingProviderRequest $request, CurrentWorkspace $current, DraftGenerators $generators): RedirectResponse
    {
        $provider = $request->provider();

        $generators->choose($current->getOrFail(), $provider);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Drafts are written with :provider from now on.', ['provider' => DraftingConfig::label($provider)])]);

        return to_route('llm-key.edit');
    }

    public function update(LlmKeyRequest $request, CurrentWorkspace $current, DraftGenerators $generators): RedirectResponse
    {
        $provider = $request->provider() ?? $this->providerOf($current->getOrFail(), $generators);

        if ($provider === null) {
            throw ValidationException::withMessages([
                'api_key' => 'No drafting provider is configured on this instance, so there is nothing to store a key for.',
            ]);
        }

        $existing = $this->credentialFor($provider);

        if ($existing !== null) {
            Gate::authorize('update', $existing);
        }

        LlmCredential::store($provider, $request->key());

        Inertia::flash('toast', ['type' => 'success', 'message' => $existing === null
            ? __('The API key is stored. Drafting with :provider uses it from now on.', ['provider' => DraftingConfig::label($provider)])
            : __('The API key is replaced.')]);

        return to_route('llm-key.edit');
    }

    /**
     * A key can be removed for any provider it was stored for, also one the
     * instance no longer offers.
     */
    public function destroy(Request $request, CurrentWorkspace $current, DraftGenerators $generators): RedirectResponse
    {
        Gate::authorize('viewAny', LlmCredential::class);

        $named = $request->input('provider');
        $provider = is_string($named) && $named !== '' ? $named : $this->providerOf($current->getOrFail(), $generators);
        $credential = $provider !== null ? $this->credentialFor($provider) : null;

        if ($credential !== null) {
            Gate::authorize('delete', $credential);

            $credential->delete();

            Inertia::flash('toast', ['type' => 'success', 'message' => __('The API key is removed.')]);
        }

        return to_route('llm-key.edit');
    }

    private function providerOf(Workspace $workspace, DraftGenerators $generators): ?string
    {
        try {
            $provider = $generators->providerFor($workspace);
        } catch (DraftGeneratorNotAvailable) {
            return null;
        }

        return in_array($provider, $generators->providers(), true) ? $provider : null;
    }

    /**
     * Looked up inside the current workspace: the model is scoped to it.
     */
    private function credentialFor(string $provider): ?LlmCredential
    {
        return LlmCredential::query()->where('provider', $provider)->first();
    }

    /**
     * One row for each provider the instance offers, and one for each
     * provider this workspace still holds a key for, so that such a key can
     * be seen and removed.
     *
     * @param  Collection<array-key, LlmCredential>  $credentials
     * @return list<array<string, mixed>>
     */
    private function providerRows(Workspace $workspace, ?string $inUse, DraftGenerators $generators, LlmCredentialResolver $keys, Collection $credentials): array
    {
        $offered = $generators->offered();
        $rows = [];

        foreach (array_unique([...$offered, ...$credentials->keys()->map(fn (mixed $name): string => (string) $name)->all()]) as $name) {
            $credential = $credentials->get($name);

            $rows[] = [
                'name' => $name,
                'label' => DraftingConfig::label($name),
                'offered' => in_array($name, $offered, true),
                'in_use' => $name === $inUse,
                'key_source' => $this->keySource($workspace, $name, $keys),
                'credential' => $credential instanceof LlmCredential ? $this->credentialFacts($credential) : null,
            ];
        }

        return $rows;
    }

    /**
     * @return array{unreadable: bool, last_four: string, set_at: string|null}
     */
    private function credentialFacts(LlmCredential $credential): array
    {
        return [
            'unreadable' => ! $credential->isReadable(),
            'last_four' => $credential->lastFour(),
            'set_at' => $credential->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return 'workspace'|'instance'|null
     */
    private function keySource(Workspace $workspace, string $provider, LlmCredentialResolver $keys): ?string
    {
        try {
            return $keys->resolve($workspace, $provider)->scope->value;
        } catch (LlmCredentialMissing) {
            return null;
        }
    }
}
