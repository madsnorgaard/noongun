<?php

namespace App\Http\Requests\Settings;

use App\Domain\Drafting\DraftGenerators;
use App\Domain\Drafting\Models\LlmCredential;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The choice of the provider that writes the drafts of a workspace. Only a
 * provider the operator of the instance offers can be chosen, and only by
 * an owner: it decides which company is sent the brief and whose account
 * pays.
 */
class DraftingProviderRequest extends FormRequest
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
            'provider' => ['bail', 'required', 'string', Rule::in(app(DraftGenerators::class)->offered())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider.required' => 'Choose a provider.',
            'provider.string' => 'Choose a provider from the list.',
            'provider.in' => 'This instance does not offer that provider. Choose one from the list.',
        ];
    }

    public function provider(): string
    {
        return $this->string('provider')->toString();
    }
}
