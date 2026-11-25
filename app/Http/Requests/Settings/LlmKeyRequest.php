<?php

namespace App\Http\Requests\Settings;

use App\Domain\Drafting\Models\LlmCredential;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The one field of the key form. The key is sent to the provider in an HTTP
 * header, so it may hold printable characters without spaces only: a line
 * break in it would let the header be split.
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
            'api_key' => ['bail', 'required', 'string', 'min:20', 'max:512', 'regex:/\A[\x21-\x7E]+\z/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'api_key.required' => 'Paste the API key.',
            'api_key.string' => 'The API key must be text.',
            'api_key.min' => 'That is too short to be an API key. Paste the whole key.',
            'api_key.max' => 'That is too long to be an API key.',
            'api_key.regex' => 'The API key may not hold spaces, line breaks or other characters a key never has. Paste the key only.',
        ];
    }

    public function key(): string
    {
        return $this->string('api_key')->toString();
    }
}
