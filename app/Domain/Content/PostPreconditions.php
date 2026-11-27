<?php

namespace App\Domain\Content;

use App\Domain\Channels\ChannelManager;
use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Contracts\ChannelCapabilities;
use App\Domain\Channels\Contracts\MediaLinkRule;
use App\Domain\Channels\Exceptions\ChannelAdapterNotAvailable;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Exceptions\IllegalPostTransition;
use App\Domain\Content\Exceptions\PostActionNotAllowed;
use App\Domain\Content\Exceptions\PostNotPublishable;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Exceptions\CrossWorkspaceWrite;
use App\Domain\Workspaces\Exceptions\MissingWorkspaceContext;
use App\Http\Presenters\PlatformLabel;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * What the post actions check before they move a post, beyond what the
 * state machine on Post enforces by itself: who is asking, whether the post
 * can actually be sent, and whether the time makes sense.
 */
class PostPreconditions
{
    /** A time this far in the past still counts as "now". */
    public const PAST_GRACE_SECONDS = 60;

    /** Further ahead than this is treated as a mistake. */
    public const MAX_DAYS_AHEAD = 366;

    public function __construct(
        private readonly ChannelManager $channels,
        private readonly CurrentWorkspace $currentWorkspace,
    ) {}

    /**
     * Owners and editors of the post's workspace may act on it, and only
     * from inside that workspace. Reads the post again first, so that the
     * checks which follow see the row as it is now.
     *
     * @throws PostActionNotAllowed
     * @throws CrossWorkspaceWrite when the post is not in the current workspace
     * @throws MissingWorkspaceContext when there is no current workspace
     */
    public function authorize(User $actor, Post $post): void
    {
        $currentId = $this->currentWorkspace->getOrFail()->id;

        if ($post->workspace_id !== $currentId) {
            throw CrossWorkspaceWrite::for($post, $currentId);
        }

        $post->refresh();

        if (! $actor->exists || ! ($post->workspace?->roleOf($actor)?->canEditContent() ?? false)) {
            throw PostActionNotAllowed::for($post, $actor);
        }
    }

    /**
     * @throws IllegalPostTransition when the post is in none of the given states
     */
    public function assertState(Post $post, PostState $to, PostState ...$from): void
    {
        if (! in_array($post->state, $from, true)) {
            throw IllegalPostTransition::between($post, $post->state, $to);
        }
    }

    /**
     * The post has at least one variant, and every variant that is not on
     * its platform yet has a channel that can publish, a text the platform
     * accepts and, where the platform builds no preview, a card for its link.
     *
     * @throws PostNotPublishable
     */
    public function assertReadyToPublish(Post $post): void
    {
        $errors = $this->problems($post);

        if ($errors !== []) {
            throw new PostNotPublishable($post, $errors);
        }
    }

    /**
     * The same check without the exception, keyed like validation errors.
     *
     * @return array<string, non-empty-list<string>>
     */
    public function problems(Post $post): array
    {
        // A list that has loaded the variants for many posts at once does
        // not pay for a query per post here.
        $variants = $post->relationLoaded('variants')
            ? $post->variants->loadMissing(['channel', 'attachments.media'])->sortBy('id')->values()
            : $post->variants()->with(['channel', 'attachments.media'])->orderBy('id')->get();

        if ($variants->isEmpty()) {
            return ['variants' => [self::text('The post has no text for any channel. Add at least one channel.')]];
        }

        $errors = [];

        foreach ($variants as $variant) {
            if ($variant->isPublished()) {
                continue;
            }

            $channel = $variant->channel;
            $problem = self::channelProblem($channel);

            if ($channel === null || $problem !== null) {
                $errors["variants.{$variant->id}.channel"] = [$problem ?? self::text('The channel no longer exists.')];

                continue;
            }

            try {
                $capabilities = $this->channels->for($channel)->capabilities();
            } catch (ChannelAdapterNotAvailable) {
                $errors["variants.{$variant->id}.channel"] = [self::platformUnavailable($channel)];

                continue;
            }

            // With images, the link may be sent as the last line of the
            // text. What is measured is what the platform gets.
            $text = $variant->textFor($capabilities);
            $linkInText = $capabilities->linkGoesInText($variant->link, $variant->attachments->count());

            if (trim($variant->body) === '') {
                // A picture without a caption is not built: whether the
                // platforms take one is not verified. The message says so.
                $errors["variants.{$variant->id}.body"] = [self::text($variant->attachments->isEmpty()
                    ? 'The text for :channel is empty.'
                    : ':platform posts need a text for now, even with images. Write a text for :channel.', [
                        'channel' => $channel->display_name,
                        'platform' => PlatformLabel::for($channel->platform),
                    ])];
            } elseif (! $capabilities->fits($text)) {
                $errors["variants.{$variant->id}.body"] = [self::text($text === $variant->body
                    ? 'The text for :channel is :length characters long. The limit is :limit.'
                    : 'The text for :channel is :length characters long with its link, which goes out as the last line of the text because the post has an image. The limit is :limit.', [
                        'channel' => $channel->display_name,
                        'length' => mb_strlen($text),
                        'limit' => $capabilities->maxBodyLength,
                    ])];
            }

            $errors += $this->mediaProblems($variant, $channel, $capabilities);

            // No card is shown next to images, so none is asked for.
            if (! $linkInText) {
                $errors += $this->linkCardProblems($variant, $channel, $capabilities);
            }
        }

        return $errors;
    }

    /**
     * What is not wrong but worth a second look before the post goes out,
     * keyed like problems(). Nothing here holds an approval back.
     *
     * An image without alternative text is advice and not a refusal: a
     * person who uses a screen reader gets nothing from such an image, and
     * the page says so, but whether a picture needs describing is for the
     * author to decide.
     *
     * @return array<string, non-empty-list<string>>
     */
    public function advice(Post $post): array
    {
        $variants = $post->relationLoaded('variants')
            ? $post->variants->loadMissing(['channel', 'attachments.media'])->sortBy('id')->values()
            : $post->variants()->with(['channel', 'attachments.media'])->orderBy('id')->get();

        $advice = [];

        foreach ($variants as $variant) {
            $channel = $variant->channel;

            if ($variant->isPublished() || $channel === null || $variant->attachments->isEmpty()) {
                continue;
            }

            try {
                $capabilities = $this->channels->for($channel)->capabilities();
            } catch (ChannelAdapterNotAvailable) {
                continue;
            }

            if ($capabilities->altTextLength === null) {
                continue;
            }

            foreach ($variant->attachments->values() as $index => $attachment) {
                if (trim((string) $attachment->alt_text) === '') {
                    $advice["variants.{$variant->id}.media.{$attachment->media_id}.alt_text"] = [self::text('Image :number for :channel has no description. People who use a screen reader will not know what it shows.', [
                        'number' => $index + 1,
                        'channel' => $channel->display_name,
                    ])];
                }
            }

            if ($capabilities->linkGoesInText($variant->link, $variant->attachments->count())) {
                // A text that ends with the bare link gets the link once
                // more when the link that is sent carries campaign
                // parameters and so is not the same text.
                $twice = str_ends_with(rtrim($variant->body), trim((string) $variant->link))
                    && $variant->textFor($capabilities) !== $variant->body;

                $advice["variants.{$variant->id}.link"] = [self::text($twice
                    ? 'The text for :channel already ends with its link. With an image the link is added once more as the last line, with its campaign parameters. Take the link out of the text.'
                    : 'With an image, :platform shows no preview of the link. The link for :channel goes out as the last line of the text.', [
                        'platform' => PlatformLabel::for($channel->platform),
                        'channel' => $channel->display_name,
                    ])];
            }
        }

        return $advice;
    }

    /**
     * What keeps the images of a variant from going out on its channel:
     * more than the platform takes, a platform that takes none, a file of
     * a type or size it refuses, an alternative text that is too long, or
     * images together with a link where the platform takes one or the other.
     *
     * @return array<string, non-empty-list<string>>
     */
    public function mediaProblems(PostVariant $variant, Channel $channel, ChannelCapabilities $capabilities): array
    {
        $attachments = $variant->images()->values();

        if ($attachments->isEmpty()) {
            return [];
        }

        $key = "variants.{$variant->id}.media";
        $name = ['channel' => $channel->display_name, 'platform' => PlatformLabel::for($channel->platform)];

        if (! $capabilities->acceptsMedia()) {
            return [$key => [self::text('Images cannot be published to :platform from this instance. Take the images off the text for :channel.', $name)]];
        }

        $errors = [];

        if ($attachments->count() > $capabilities->maxMediaItems) {
            // The limit may be the platform's or one set for this
            // instance, so the sentence names neither as the source.
            $errors[$key] = [self::text('The text for :channel has :count images. Noon Gun sends :limit at most to :platform on one post. Take :over off.', [
                ...$name,
                'count' => $attachments->count(),
                'limit' => $capabilities->maxMediaItems,
                'over' => $attachments->count() - $capabilities->maxMediaItems,
            ])];
        } elseif ($capabilities->mediaLinkRule === MediaLinkRule::Refused && ! blank($variant->link)) {
            $errors[$key] = [self::text(':platform takes a post with images or a post with a link, not both. Take the link or the images off the text for :channel.', $name)];
        }

        foreach ($attachments as $index => $attachment) {
            $image = $attachment->media;
            $number = [...$name, 'number' => $index + 1];
            $problem = match (true) {
                $image === null => self::text('Image :number for :channel no longer exists. Take it off the post.', $number),
                ! $capabilities->supportsMedia($image->mime) => self::text('Image :number for :channel is stored as a :type file, which is not sent to :platform. Take it off and add it again as :types.', [
                    ...$number,
                    'type' => self::typeName($image->mime),
                    'types' => implode(' or ', array_map(self::typeName(...), $capabilities->mediaTypes)),
                ]),
                // What is compared is the stored file, which is not the
                // file that was uploaded: it was scaled and encoded again.
                // Exporting the same picture smaller in bytes alone may
                // store at the same size, so the next step is fewer
                // pixels, or JPEG where the stored file is a PNG.
                $capabilities->maxMediaBytes !== null && $image->byte_size > $capabilities->maxMediaBytes => self::text($image->mime === 'image/png'
                    ? 'Image :number for :channel is stored as a PNG file of :size MB. Noon Gun sends :limit MB at most to :platform for one image. Take it off and add it again as a JPEG, or with fewer pixels.'
                    : 'Image :number for :channel is stored at :size MB. Noon Gun sends :limit MB at most to :platform for one image. Take it off and add it again with fewer pixels.', [
                        ...$number,
                        'size' => self::megabytes($image->byte_size),
                        'limit' => self::megabytes($capabilities->maxMediaBytes),
                    ]),
                $capabilities->maxMediaPixels !== null && $image->pixels() > $capabilities->maxMediaPixels => self::text('Image :number for :channel is stored at :width x :height pixels. Noon Gun sends :limit megapixels at most to :platform. Take it off and add it again with fewer pixels.', [
                    ...$number,
                    'width' => $image->width,
                    'height' => $image->height,
                    'limit' => round($capabilities->maxMediaPixels / 1_000_000, 1),
                ]),
                default => null,
            };

            if ($problem !== null) {
                $errors["{$key}.{$attachment->media_id}"] = [$problem];
            }

            $altText = (string) $attachment->alt_text;

            if ($capabilities->altTextLength !== null && mb_strlen($altText) > $capabilities->altTextLength) {
                $errors["{$key}.{$attachment->media_id}.alt_text"] = [self::text('The description of image :number for :channel is :length characters long. Noon Gun sends :limit at most to :platform. Shorten it.', [
                    ...$number,
                    'length' => mb_strlen($altText),
                    'limit' => $capabilities->altTextLength,
                ])];
            }
        }

        return $errors;
    }

    /**
     * A size in megabytes as the pages write it (megabytes() in
     * resources/js/lib/media.ts): a limit that was set in whole binary or
     * whole decimal megabytes is that number, anything else is decimal
     * megabytes to one place.
     */
    public static function megabytes(int $bytes): string
    {
        foreach ([1024 * 1024, 1_000_000] as $unit) {
            if ($bytes >= $unit && $bytes % $unit === 0) {
                return (string) intdiv($bytes, $unit);
            }
        }

        return (string) round($bytes / 1_000_000, 1);
    }

    private static function typeName(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'JPEG',
            'image/png' => 'PNG',
            'image/webp' => 'WebP',
            default => strtoupper(str_contains($mime, '/') ? explode('/', $mime, 2)[1] : $mime),
        };
    }

    /**
     * A platform that reads nothing from a linked page shows the link only
     * as the card the author typed. Without a title there is no card, so the
     * post is held back rather than sent with a bare link.
     *
     * @return array<string, non-empty-list<string>>
     */
    private function linkCardProblems(PostVariant $variant, Channel $channel, ChannelCapabilities $capabilities): array
    {
        if (blank($variant->link) || $capabilities->linkTitleLength === null) {
            return [];
        }

        $name = ['channel' => $channel->display_name, 'platform' => PlatformLabel::for($channel->platform)];
        $title = trim((string) $variant->link_title);
        $errors = [];

        if ($title === '') {
            $errors["variants.{$variant->id}.link_title"] = [self::text('The link for :channel has no title. :platform shows a link with the title you give it and reads nothing from the page. Open the post and type a title under the link.', $name)];
        } elseif (mb_strlen($title) > $capabilities->linkTitleLength) {
            $errors["variants.{$variant->id}.link_title"] = [self::text('The link title for :channel is :length characters long. The limit is :limit.', [
                ...$name,
                'length' => mb_strlen($title),
                'limit' => $capabilities->linkTitleLength,
            ])];
        }

        $description = (string) $variant->link_description;

        if ($capabilities->linkDescriptionLength !== null && mb_strlen($description) > $capabilities->linkDescriptionLength) {
            $errors["variants.{$variant->id}.link_description"] = [self::text('The link description for :channel is :length characters long. The limit is :limit.', [
                ...$name,
                'length' => mb_strlen($description),
                'limit' => $capabilities->linkDescriptionLength,
            ])];
        }

        return $errors;
    }

    /**
     * The time is not in the past (with a minute of grace, so that "now" is
     * accepted), not more than a year ahead, and not after the access to one
     * of the post's channels ends.
     *
     * The last one is a refusal and not advice: the publishing job does not
     * call a platform with an access that has run out, so such a post would
     * fail for certain. Connecting the channel again gives it a new end
     * date, and that can be done straight away.
     *
     * @throws PostNotPublishable
     */
    public function assertSensibleTime(Post $post, DateTimeInterface $at): void
    {
        $channels = [];

        foreach ($post->variants()->unpublished()->with('channel')->orderBy('id')->get() as $variant) {
            if ($variant->channel !== null) {
                $channels[] = $variant->channel;
            }
        }

        $problems = $this->timeProblems($channels, $at);

        if ($problems !== []) {
            throw new PostNotPublishable($post, ['scheduled_at' => $problems]);
        }
    }

    /**
     * The same rule for a post that does not exist yet: what is wrong with
     * the time for a post on the given channels, in words for the user.
     * Empty when the time makes sense.
     *
     * @param  iterable<Channel>  $channels
     * @return list<string>
     */
    public function timeProblems(iterable $channels, DateTimeInterface $at): array
    {
        $at = CarbonImmutable::instance($at);

        if ($at->lt(now()->subSeconds(self::PAST_GRACE_SECONDS))) {
            return [self::text('The scheduled time has passed. Choose a time in the future.')];
        }

        if ($at->gt(now()->addDays(self::MAX_DAYS_AHEAD))) {
            return [self::text('The scheduled time is more than a year ahead.')];
        }

        return $this->accessEndingBefore($channels, $at);
    }

    /**
     * One sentence for each of the channels whose access ends at or before
     * the given time, with the date on the clock of the workspace. A
     * channel whose access has already ended is left to channelProblem().
     *
     * @param  iterable<Channel>  $channels
     * @return list<string>
     */
    private function accessEndingBefore(iterable $channels, CarbonImmutable $at): array
    {
        $workspace = $this->currentWorkspace->getOrFail();
        $messages = [];

        foreach ($channels as $channel) {
            $endsAt = $channel->token_expires_at;

            if ($endsAt === null || $channel->tokenExpired() || $endsAt->gt($at)) {
                continue;
            }

            $messages[] = self::text('The access to :channel ends on :date, before this post is due. Connect :channel again on the Channels page before then, or choose an earlier time.', [
                'channel' => $channel->display_name,
                'date' => $workspace->toLocal($endsAt)->format('j F Y'),
            ]);
        }

        return $messages;
    }

    /**
     * Why the channel cannot publish, in words for the user, or null when
     * it can. PublishPostJob stores the same text on a variant it has to
     * skip.
     */
    public static function channelProblem(?Channel $channel): ?string
    {
        if ($channel === null) {
            return self::text('The channel no longer exists.');
        }

        if ($channel->canPublish()) {
            return null;
        }

        $name = ['channel' => $channel->display_name];

        return match (true) {
            $channel->status === ChannelStatus::NeedsReconnect => self::text(':channel must be connected again before it can publish.', $name),
            $channel->status === ChannelStatus::Disconnected => self::text(':channel is disconnected.', $name),
            $channel->status === ChannelStatus::Pending => self::text(':channel is waiting to be confirmed on the Channels page.', $name),
            $channel->tokenExpired() => self::text('The access to :channel has expired. Connect it again.', $name),
            default => self::text(':channel cannot publish right now.', $name),
        };
    }

    /**
     * The text for a channel whose platform has no adapter on this instance.
     */
    public static function platformUnavailable(Channel $channel): string
    {
        return self::text('Publishing to :platform is not available on this instance.', ['platform' => PlatformLabel::for($channel->platform)]);
    }

    /**
     * Every text a user reads goes through the translator, as in the
     * controllers.
     *
     * @param  array<string, string|int|float>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }
}
