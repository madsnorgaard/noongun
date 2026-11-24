<?php

namespace App\Http\Requests\Posts;

use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostPreconditions;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Http\Presenters\PostPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A draft as the editor sends it: an optional planned time in the wall-clock
 * time of the workspace, and one text per chosen channel.
 *
 * A text may be empty or too long for its platform: unfinished work can be
 * saved, and the readiness check on approval decides what may go out.
 */
class SavePostRequest extends FormRequest
{
    /** Far above any platform limit; only keeps a request from storing megabytes. */
    private const MAX_BODY_LENGTH = 20000;

    private const MAX_VARIANTS = 20;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Post::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scheduled_at' => ['nullable', 'date_format:'.PostPresenter::LOCAL_FORMAT],
            'variants' => ['required', 'array', 'min:1', 'max:'.self::MAX_VARIANTS],
            'variants.*.channel_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('channels', 'id')->where('workspace_id', app(CurrentWorkspace::class)->id()),
            ],
            'variants.*.body' => ['nullable', 'string', 'max:'.self::MAX_BODY_LENGTH],
            'variants.*.link' => ['nullable', 'string', 'max:2048', 'url:http,https'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'variants.required' => __('Choose at least one channel.'),
            'variants.min' => __('Choose at least one channel.'),
            'variants.*.channel_id.exists' => __('This channel does not exist in this workspace.'),
            'variants.*.link.url' => __('The link must be a full address that starts with http:// or https://.'),
            'scheduled_at.date_format' => __('The planned time is not a valid date and time.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'scheduled_at' => __('planned time'),
            'variants.*.body' => __('text'),
            'variants.*.link' => __('link'),
            'variants.*.channel_id' => __('channel'),
        ];
    }

    /**
     * A channel that is added to the post must be able to publish. A channel
     * the post already uses may stay whatever its condition: the readiness
     * check reports it.
     *
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $postId = $this->route('post');
                $used = is_numeric($postId)
                    ? PostVariant::query()->where('post_id', (int) $postId)->pluck('channel_id')->all()
                    : [];

                $channels = Channel::query()
                    ->whereKey(array_column($this->variantInputs(), 'channel_id'))
                    ->get()
                    ->keyBy('id');

                foreach ($this->variantInputs() as $position => $input) {
                    if (in_array($input['channel_id'], $used, true)) {
                        continue;
                    }

                    $problem = PostPreconditions::channelProblem($channels->get($input['channel_id']));

                    if ($problem !== null) {
                        $validator->errors()->add("variants.{$position}.channel_id", $problem);
                    }
                }
            },
        ];
    }

    /**
     * The planned time as an instant in UTC, or null when none was given.
     */
    public function plannedAt(): ?CarbonImmutable
    {
        $value = $this->validated('scheduled_at');

        return is_string($value) && $value !== ''
            ? app(CurrentWorkspace::class)->getOrFail()->toUtc($value)
            : null;
    }

    /**
     * @return list<array{channel_id: int, body: string, link: string|null}>
     */
    public function variantInputs(): array
    {
        $variants = [];

        foreach ((array) $this->input('variants', []) as $variant) {
            if (! is_array($variant) || ! is_numeric($variant['channel_id'] ?? null)) {
                continue;
            }

            $body = $variant['body'] ?? null;
            $link = $variant['link'] ?? null;

            $variants[] = [
                'channel_id' => (int) $variant['channel_id'],
                'body' => is_string($body) ? $body : '',
                'link' => is_string($link) && $link !== '' ? $link : null,
            ];
        }

        return $variants;
    }
}
