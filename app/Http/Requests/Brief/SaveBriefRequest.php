<?php

namespace App\Http\Requests\Brief;

use App\Domain\Drafting\Models\Brief;
use App\Domain\Drafting\VoiceRules;
use App\Http\Presenters\BriefPresenter;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The brief as its page sends it. The body is markdown and is stored as it
 * was typed; nothing renders it as markup. Voice rules and calls to action
 * are lists of plain strings. An empty rule is dropped, not refused.
 */
class SaveBriefRequest extends FormRequest
{
    public const MAX_TITLE_LENGTH = 150;

    public const MAX_BODY_LENGTH = 20000;

    public const MAX_RULES = 30;

    public const MAX_RULE_LENGTH = 300;

    public const MAX_CALLS_TO_ACTION = 10;

    public const MAX_CALL_LENGTH = 200;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Brief::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rule = ['nullable', 'string', 'max:'.self::MAX_RULE_LENGTH];

        return [
            'title' => ['required', 'string', 'max:'.self::MAX_TITLE_LENGTH],
            'body' => ['required', 'string', 'max:'.self::MAX_BODY_LENGTH],
            'voice_rules' => ['present', 'array:general,platforms'],
            'voice_rules.general' => ['present', 'array', 'list', 'max:'.self::MAX_RULES],
            'voice_rules.general.*' => $rule,
            'voice_rules.platforms' => ['nullable', 'array', $this->knownPlatforms(...)],
            'voice_rules.platforms.*' => ['array', 'list', 'max:'.self::MAX_RULES],
            'voice_rules.platforms.*.*' => $rule,
            'calls_to_action' => ['present', 'array', 'list', 'max:'.self::MAX_CALLS_TO_ACTION],
            'calls_to_action.*' => ['array:text,url'],
            'calls_to_action.*.text' => ['required', 'string', 'max:'.self::MAX_CALL_LENGTH],
            'calls_to_action.*.url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => __('Give the brief a title, for example the name of the business.'),
            'body.required' => __('Write the brief first. Posts are drafted from this text.'),
            'calls_to_action.*.text.required' => __('A call to action needs a text. Remove the row if it is not needed.'),
            'calls_to_action.*.url.url' => __('The link must be a full address that starts with http:// or https://.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'body' => __('brief'),
            'voice_rules.general.*' => __('rule'),
            'voice_rules.platforms.*.*' => __('rule'),
            'calls_to_action.*.text' => __('text'),
            'calls_to_action.*.url' => __('link'),
        ];
    }

    public function briefTitle(): string
    {
        $title = $this->validated('title');

        return is_string($title) ? trim($title) : '';
    }

    public function briefBody(): string
    {
        $body = $this->validated('body');

        return is_string($body) ? $body : '';
    }

    public function voiceRules(): VoiceRules
    {
        $rules = $this->validated('voice_rules');

        return VoiceRules::fromArray(is_array($rules) ? $rules : []);
    }

    /**
     * @return list<array{text: string, url: string|null}>
     */
    public function callsToAction(): array
    {
        $calls = [];

        foreach ((array) $this->validated('calls_to_action', []) as $call) {
            $text = is_array($call) && is_string($call['text'] ?? null) ? trim($call['text']) : '';
            $url = is_array($call) && is_string($call['url'] ?? null) ? trim($call['url']) : '';

            if ($text !== '') {
                $calls[] = ['text' => $text, 'url' => $url === '' ? null : $url];
            }
        }

        return $calls;
    }

    private function knownPlatforms(string $attribute, mixed $value, Closure $fail): void
    {
        $known = app(BriefPresenter::class)->platformNames(Brief::current());

        foreach (array_keys(is_array($value) ? $value : []) as $platform) {
            if (! in_array($platform, $known, true)) {
                $fail(__('Voice rules can only be given for a platform this instance knows.'));

                return;
            }
        }
    }
}
