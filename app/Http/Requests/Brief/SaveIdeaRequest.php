<?php

namespace App\Http\Requests\Brief;

use App\Domain\Content\Models\Idea;
use App\Domain\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An idea as its page sends it. The source is an address that is stored as
 * text. Nothing here or later opens it: the rules below only look at its
 * form, and a draft is given the address as a line of text.
 */
class SaveIdeaRequest extends FormRequest
{
    public const MAX_TITLE_LENGTH = 255;

    public const MAX_URL_LENGTH = 2048;

    public const MAX_NOTES_LENGTH = 5000;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Idea::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.self::MAX_TITLE_LENGTH],
            'pillar_id' => [
                'nullable',
                'integer',
                Rule::exists('pillars', 'id')->where('workspace_id', app(CurrentWorkspace::class)->id()),
            ],
            'source_url' => ['nullable', 'string', 'max:'.self::MAX_URL_LENGTH, 'url:http,https'],
            'notes' => ['nullable', 'string', 'max:'.self::MAX_NOTES_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => __('Give the idea a title.'),
            'pillar_id.exists' => __('This pillar does not exist in this workspace.'),
            'source_url.url' => __('The source must be a full address that starts with http:// or https://.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'pillar_id' => __('pillar'),
            'source_url' => __('source'),
        ];
    }

    /**
     * @return array{title: string, pillar_id: int|null, source_url: string|null, notes: string|null}
     */
    public function ideaAttributes(): array
    {
        $title = $this->validated('title');
        $pillar = $this->validated('pillar_id');

        return [
            'title' => is_string($title) ? trim($title) : '',
            'pillar_id' => is_numeric($pillar) ? (int) $pillar : null,
            'source_url' => $this->text('source_url'),
            'notes' => $this->text('notes'),
        ];
    }

    private function text(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
