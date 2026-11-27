<?php

namespace App\Http\Requests\Posts;

use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Media\MediaConfig;
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

    /** As the column allows. What a platform accepts is checked on approval. */
    private const MAX_LINK_TITLE_LENGTH = 400;

    private const MAX_LINK_DESCRIPTION_LENGTH = 5000;

    /** For an image that is not in the workspace, whenever that is found out. */
    public const IMAGE_MISSING = 'This image does not exist in this workspace. It may have been removed because no post used it. Upload it again.';

    /**
     * The errors for images that were there when the request was checked
     * and gone when the draft was written, keyed as the check itself keys
     * a missing image, so that the page points at the same place either way.
     *
     * @param  list<int>  $ids
     * @return array<string, list<string>>
     */
    public function missingImageErrors(array $ids): array
    {
        $errors = [];

        foreach ((array) $this->input('variants', []) as $position => $variant) {
            foreach ((array) (is_array($variant) ? $variant['media'] ?? [] : []) as $index => $image) {
                if (is_array($image) && is_numeric($image['id'] ?? null) && in_array((int) $image['id'], $ids, true)) {
                    $errors["variants.{$position}.media.{$index}.id"] = [(string) __(self::IMAGE_MISSING)];
                }
            }
        }

        return $errors !== [] ? $errors : ['variants' => [(string) __(self::IMAGE_MISSING)]];
    }

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
            'variants.*.link_title' => ['nullable', 'string', 'max:'.self::MAX_LINK_TITLE_LENGTH],
            'variants.*.link_description' => ['nullable', 'string', 'max:'.self::MAX_LINK_DESCRIPTION_LENGTH],
            // The images of a text, in order. An image must be one this
            // workspace stored: an id from anywhere else does not exist.
            // How many and which a platform takes is checked on approval.
            'variants.*.media' => ['sometimes', 'array', 'list', 'max:'.MediaConfig::maxPerVariant()],
            'variants.*.media.*.id' => [
                'required',
                'integer',
                Rule::exists('media', 'id')->where('workspace_id', app(CurrentWorkspace::class)->id()),
            ],
            'variants.*.media.*.alt_text' => ['nullable', 'string', 'max:'.MediaConfig::MAX_ALT_TEXT_LENGTH],
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
            'variants.*.media.max' => __('A text can carry :max images at most.'),
            'variants.*.media.*.id.exists' => __(self::IMAGE_MISSING),
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
            'variants.*.link_title' => __('link title'),
            'variants.*.link_description' => __('link description'),
            'variants.*.channel_id' => __('channel'),
            'variants.*.media' => __('images'),
            'variants.*.media.*.id' => __('image'),
            'variants.*.media.*.alt_text' => __('image description'),
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
                    $ids = array_column($input['media'] ?? [], 'id');

                    if (count($ids) !== count(array_unique($ids))) {
                        $validator->errors()->add("variants.{$position}.media", (string) __('The same image is on this text twice.'));
                    }

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
     * The key "media" is only there when the request sent it: a request
     * without it leaves the images of that text as they are.
     *
     * @return list<array{channel_id: int, body: string, link: string|null, link_title: string|null, link_description: string|null, media?: list<array{id: int, alt_text: string|null}>}>
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
            $hasLink = is_string($link) && $link !== '';

            $input = [
                'channel_id' => (int) $variant['channel_id'],
                'body' => is_string($body) ? $body : '',
                'link' => $hasLink ? $link : null,
                // A card belongs to its link: without one it is not kept.
                'link_title' => $hasLink ? self::text($variant['link_title'] ?? null) : null,
                'link_description' => $hasLink ? self::text($variant['link_description'] ?? null) : null,
            ];

            if (is_array($variant['media'] ?? null)) {
                $input['media'] = self::media($variant['media']);
            }

            $variants[] = $input;
        }

        return $variants;
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return list<array{id: int, alt_text: string|null}>
     */
    private static function media(array $items): array
    {
        $media = [];

        foreach ($items as $item) {
            if (is_array($item) && is_numeric($item['id'] ?? null)) {
                $media[] = ['id' => (int) $item['id'], 'alt_text' => self::text($item['alt_text'] ?? null)];
            }
        }

        return $media;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
