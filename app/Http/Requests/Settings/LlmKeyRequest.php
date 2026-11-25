<?php

namespace App\Http\Requests\Settings;

use App\Domain\Drafting\DraftGenerators;
use App\Domain\Drafting\DraftingConfig;
use App\Domain\Drafting\Exceptions\DraftGeneratorNotAvailable;
use App\Domain\Drafting\Models\LlmCredential;
use App\Domain\Workspaces\CurrentWorkspace;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The key form: the key, and the provider it is for. Without a provider it
 * is the one the workspace drafts with.
 *
 * The key is sent to the provider in an HTTP header, so it may hold
 * printable characters without spaces only: a line break in it would let
 * the header be split.
 *
 * A key that starts the way the keys of another provider do is refused,
 * so that a key pasted under the wrong provider is not sent to a company
 * it does not belong to.
 *
 * No message quotes the value, and "api_key" is on the list of fields that
 * are never flashed to the session (bootstrap/app.php).
 */
class LlmKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', LlmCredential::class);
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'provider' => ['bail', 'sometimes', 'required', 'string', Rule::in(app(DraftGenerators::class)->offered())],
            'api_key' => ['bail', 'required', 'string', 'min:20', 'max:512', 'regex:/\A[\x21-\x7E]+\z/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider.required' => 'Say which provider the key is for.',
            'provider.string' => 'Say which provider the key is for.',
            'provider.in' => 'This instance does not offer that provider, so no key is stored for it.',
            'api_key.required' => 'Paste the API key.',
            'api_key.string' => 'The API key must be text.',
            'api_key.min' => 'That is too short to be an API key. Paste the whole key.',
            'api_key.max' => 'That is too long to be an API key.',
            'api_key.regex' => 'The API key may not hold spaces, line breaks or other characters a key never has. Paste the key only.',
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $provider = $this->provider() ?? $this->providerInUse();

            if ($validator->errors()->isNotEmpty() || $provider === null) {
                return;
            }

            $looksLike = DraftingConfig::providerWithKeysLike($this->key());

            if ($looksLike !== null && $looksLike !== $provider) {
                $validator->errors()->add('api_key', sprintf(
                    'This looks like a key for %s, not for %s. It was not stored. Store it under %1$s.',
                    DraftingConfig::label($looksLike),
                    DraftingConfig::label($provider),
                ));
            }
        }];
    }

    /**
     * The provider the form names, or null when it names none.
     */
    public function provider(): ?string
    {
        $provider = $this->input('provider');

        return is_string($provider) && $provider !== '' ? $provider : null;
    }

    public function key(): string
    {
        return $this->string('api_key')->toString();
    }

    private function providerInUse(): ?string
    {
        $workspace = app(CurrentWorkspace::class)->get();

        try {
            return $workspace !== null ? app(DraftGenerators::class)->providerFor($workspace) : null;
        } catch (DraftGeneratorNotAvailable) {
            return null;
        }
    }
}
