<?php

namespace App\Http\Requests\Brief;

use App\Domain\Content\Models\Pillar;
use App\Domain\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePillarRequest extends FormRequest
{
    public const MAX_NAME_LENGTH = 80;

    public const MAX_DESCRIPTION_LENGTH = 1000;

    /** Posts per week for one pillar. Far above any sensible plan. */
    public const MAX_WEEKLY_TARGET = 30;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Pillar::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $pillar = $this->route('pillar');

        return [
            'name' => [
                'required',
                'string',
                'max:'.self::MAX_NAME_LENGTH,
                Rule::unique('pillars', 'name')
                    ->where('workspace_id', app(CurrentWorkspace::class)->id())
                    ->ignore(is_numeric($pillar) ? (int) $pillar : null),
            ],
            'description' => ['nullable', 'string', 'max:'.self::MAX_DESCRIPTION_LENGTH],
            'weekly_target' => ['required', 'integer', 'min:0', 'max:'.self::MAX_WEEKLY_TARGET],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => __('There is already a pillar with this name.'),
            'weekly_target.required' => __('Say how many posts a week this pillar should get. Zero is allowed.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'weekly_target' => __('posts per week'),
        ];
    }

    /**
     * @return array{name: string, description: string|null, weekly_target: int}
     */
    public function pillarAttributes(): array
    {
        $name = $this->validated('name');
        $description = $this->validated('description');
        $target = $this->validated('weekly_target');

        return [
            'name' => is_string($name) ? trim($name) : '',
            'description' => is_string($description) && trim($description) !== '' ? trim($description) : null,
            'weekly_target' => is_numeric($target) ? (int) $target : 0,
        ];
    }
}
