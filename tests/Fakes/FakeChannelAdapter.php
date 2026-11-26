<?php

namespace Tests\Fakes;

use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\ChannelCapabilities;
use App\Domain\Channels\Contracts\MediaLinkRule;
use App\Domain\Channels\Contracts\PublishResult;
use App\Domain\Channels\Contracts\RateLimit;
use App\Domain\Channels\Contracts\UploadsMedia;
use App\Domain\Channels\Exceptions\ChannelException;
use App\Domain\Channels\Exceptions\PublishFailed;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\MediaAttachment;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Workspaces\Models\Workspace;
use Closure;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert;

/**
 * A ChannelAdapter that makes no HTTP calls. It records every call, returns
 * results you configure, and can be told to fail.
 *
 *     $fake = fakeChannelAdapter();              // registered for "facebook"
 *     $fake->failRetryable(times: 2);            // next two publishes throw
 *     ...
 *     $fake->assertPublishedTimes($variant, 1);
 *
 * It takes images as a real platform does, in two steps:
 *
 *     $fake = fakeChannelAdapter()->acceptingImages(max: 4);
 *     ...
 *     $fake->uploads;                    // each file it was sent, with a hash of its bytes
 *     $fake->lastPublished($variant);    // the text and the image ids the post was made with
 *
 * Register it with fakeChannelAdapter() from tests/Support/channels.php.
 */
class FakeChannelAdapter implements ChannelAdapter, UploadsMedia
{
    /** @var list<array{method: string, variant_id: int|null, channel_id: int|null}> */
    public array $calls = [];

    /**
     * Every image that was uploaded, in order.
     *
     * @var list<array{variant_id: int, media_id: int, position: int, mime: string, alt_text: string|null, bytes: int, sha256: string, external_id: string}>
     */
    public array $uploads = [];

    /**
     * What every publish call was given, failed calls included.
     *
     * @var list<array{variant_id: int, text: string, images: list<array{media_id: int, external_id: string|null, alt_text: string|null}>}>
     */
    public array $published = [];

    /** @var list<ChannelException> */
    private array $uploadFailures = [];

    /** @var list<ChannelException> */
    private array $publishFailures = [];

    private ?ChannelException $alwaysFail = null;

    /** @var (Closure(PostVariant, int): PublishResult)|null */
    private ?Closure $publishUsing = null;

    /** @var (Closure(Workspace, string): Collection<int, Channel>)|null */
    private ?Closure $connectUsing = null;

    private ?ChannelException $deleteFailure = null;

    private ?ChannelException $refreshFailure = null;

    private ChannelCapabilities $capabilities;

    public function __construct(private readonly string $platform = 'facebook')
    {
        $this->capabilities = new ChannelCapabilities(
            maxBodyLength: 5000,
            mediaTypes: ['image/jpeg', 'image/png'],
            maxMediaItems: 1,
            linkPreviews: true,
            rateLimit: new RateLimit(maxCalls: 200, perSeconds: 3600),
        );
    }

    public function platform(): string
    {
        return $this->platform;
    }

    public function capabilities(): ChannelCapabilities
    {
        return $this->capabilities;
    }

    public function authorizationUrl(Workspace $workspace, string $state): string
    {
        $this->record('authorizationUrl');

        return "https://platform.test/{$this->platform}/authorize?state=".urlencode($state);
    }

    public function connect(Workspace $workspace, string $code): Collection
    {
        $this->record('connect');

        if ($this->connectUsing !== null) {
            return ($this->connectUsing)($workspace, $code);
        }

        return new Collection([
            Channel::factory()->platform($this->platform)->create(),
        ]);
    }

    public function publish(PostVariant $variant): PublishResult
    {
        $this->record('publish', $variant);

        // What a real adapter reads when it creates the post: the images
        // in order, each with the id its upload returned, and the text
        // with the link where the images take the place of the preview.
        $this->published[] = [
            'variant_id' => $variant->id,
            'text' => $variant->textFor($this->capabilities),
            'images' => $variant->images()->map(fn (MediaAttachment $attachment): array => [
                'media_id' => $attachment->media_id,
                'external_id' => $attachment->external_id,
                'alt_text' => $attachment->alt_text,
            ])->values()->all(),
        ];

        if ($this->publishFailures !== []) {
            throw array_shift($this->publishFailures);
        }

        if ($this->alwaysFail !== null) {
            throw $this->alwaysFail;
        }

        $attempt = $this->publishCount($variant);

        if ($this->publishUsing !== null) {
            return ($this->publishUsing)($variant, $attempt);
        }

        // A new id on every call, as a real platform gives: a second call
        // for the same variant would be a visible duplicate.
        $externalId = "{$this->platform}_{$variant->id}_{$attempt}";

        return new PublishResult($externalId, "https://platform.test/{$this->platform}/posts/{$externalId}", now());
    }

    /**
     * Reads the whole file, as a real upload would, and records what it
     * was given. Returns a new id on every call.
     */
    public function uploadMedia(PostVariant $variant, MediaAttachment $attachment): string
    {
        $this->record('uploadMedia', $variant);

        if ($this->uploadFailures !== []) {
            throw array_shift($this->uploadFailures);
        }

        $stream = $attachment->readStream();
        $bytes = (string) stream_get_contents($stream);
        fclose($stream);

        $externalId = "{$this->platform}_image_{$attachment->media_id}_".(count($this->uploads) + 1);

        $this->uploads[] = [
            'variant_id' => $variant->id,
            'media_id' => $attachment->media_id,
            'position' => $attachment->position,
            'mime' => $attachment->image()->mime,
            'alt_text' => $attachment->alt_text,
            'bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'external_id' => $externalId,
        ];

        return $externalId;
    }

    public function delete(PostVariant $variant): void
    {
        $this->record('delete', $variant);

        if ($this->deleteFailure !== null) {
            throw $this->deleteFailure;
        }
    }

    public function refreshToken(Channel $channel): Channel
    {
        $this->record('refreshToken', channel: $channel);

        if ($this->refreshFailure !== null) {
            throw $this->refreshFailure;
        }

        return $channel;
    }

    // Configuration

    public function withCapabilities(ChannelCapabilities $capabilities): static
    {
        $this->capabilities = $capabilities;

        return $this;
    }

    /**
     * A platform that takes images: up to $max JPEG or PNG files on a post,
     * with an alternative text, and the link of such a post in its text.
     * Everything else stays as the fake has it. Without this the fake
     * takes one image and no alternative text.
     */
    public function acceptingImages(
        int $max = 4,
        ?int $altTextLength = 1000,
        MediaLinkRule $linkRule = MediaLinkRule::InText,
        ?int $maxBytes = null,
        ?int $maxPixels = null,
        ?int $uploadLifetime = null,
    ): static {
        $current = $this->capabilities;

        return $this->withCapabilities(new ChannelCapabilities(
            maxBodyLength: $current->maxBodyLength,
            mediaTypes: ['image/jpeg', 'image/png'],
            maxMediaItems: $max,
            linkPreviews: $current->linkPreviews,
            rateLimit: $current->rateLimit,
            linkTitleLength: $current->linkTitleLength,
            linkDescriptionLength: $current->linkDescriptionLength,
            maxMediaBytes: $maxBytes,
            maxMediaPixels: $maxPixels,
            altTextLength: $altTextLength,
            mediaLinkRule: $linkRule,
            mediaUploadLifetime: $uploadLifetime,
        ));
    }

    /**
     * The next $times uploads of an image throw the failure, then
     * uploading works again. By default a failure worth trying again.
     */
    public function failNextUploads(?ChannelException $failure = null, int $times = 1): static
    {
        for ($i = 0; $i < $times; $i++) {
            $this->uploadFailures[] = $failure ?? PublishFailed::retryable($this->platform, 'The platform is busy.');
        }

        return $this;
    }

    /**
     * Decide what publish() returns. The closure gets the variant and the
     * number of this publish call for that variant, starting at 1.
     *
     * @param  PublishResult|Closure(PostVariant, int): PublishResult  $result
     */
    public function publishReturns(PublishResult|Closure $result): static
    {
        $this->publishUsing = $result instanceof Closure ? $result : fn (): PublishResult => $result;

        return $this;
    }

    /**
     * The next $times publish calls throw a retryable failure, then
     * publishing works again.
     */
    public function failRetryable(int $times = 1, ?int $retryAfterSeconds = null, string $message = 'The platform is busy.'): static
    {
        return $this->failNextPublishes(PublishFailed::retryable($this->platform, $message, $retryAfterSeconds), $times);
    }

    /**
     * The next $times publish calls throw a failure that must not be retried.
     */
    public function failPermanently(int $times = 1, string $message = 'The platform refused the post.'): static
    {
        return $this->failNextPublishes(PublishFailed::permanent($this->platform, $message), $times);
    }

    /**
     * The next $times publish calls throw a "connect again" failure.
     */
    public function failReconnectRequired(int $times = 1, string $message = 'The access token is no longer valid.'): static
    {
        return $this->failNextPublishes(PublishFailed::reconnectRequired($this->platform, $message), $times);
    }

    public function failNextPublishes(ChannelException $failure, int $times = 1): static
    {
        for ($i = 0; $i < $times; $i++) {
            $this->publishFailures[] = $failure;
        }

        return $this;
    }

    /**
     * Every publish call throws, until publishSucceeds() is called.
     */
    public function alwaysFail(?ChannelException $failure = null): static
    {
        $this->alwaysFail = $failure ?? PublishFailed::retryable($this->platform, 'The platform is busy.');

        return $this;
    }

    public function publishSucceeds(): static
    {
        $this->alwaysFail = null;
        $this->publishFailures = [];

        return $this;
    }

    /**
     * @param  Closure(Workspace, string): Collection<int, Channel>  $callback
     */
    public function connectReturns(Closure $callback): static
    {
        $this->connectUsing = $callback;

        return $this;
    }

    public function failDelete(?ChannelException $failure = null): static
    {
        $this->deleteFailure = $failure ?? ChannelException::permanent($this->platform, 'The post could not be deleted.');

        return $this;
    }

    public function failRefresh(?ChannelException $failure = null): static
    {
        $this->refreshFailure = $failure ?? ChannelException::reconnectRequired($this->platform, 'The token could not be renewed.');

        return $this;
    }

    // Inspection

    /**
     * How many publish calls were made, for one variant or in total.
     * Failed calls count: each one reached the platform.
     */
    public function publishCount(?PostVariant $variant = null): int
    {
        return $this->count('publish', $variant);
    }

    /**
     * How many images were uploaded, for one variant or in total. Failed
     * uploads count.
     */
    public function uploadCount(?PostVariant $variant = null): int
    {
        return $this->count('uploadMedia', $variant);
    }

    /**
     * What the last publish call for the variant was given, or null when
     * there was none.
     *
     * @return array{variant_id: int, text: string, images: list<array{media_id: int, external_id: string|null, alt_text: string|null}>}|null
     */
    public function lastPublished(PostVariant $variant): ?array
    {
        foreach (array_reverse($this->published) as $call) {
            if ($call['variant_id'] === $variant->id) {
                return $call;
            }
        }

        return null;
    }

    public function deleteCount(?PostVariant $variant = null): int
    {
        return $this->count('delete', $variant);
    }

    public function callCount(string $method): int
    {
        return $this->count($method);
    }

    public function assertPublishedTimes(PostVariant $variant, int $times): static
    {
        Assert::assertSame(
            $times,
            $this->publishCount($variant),
            "Expected {$times} publish call(s) for variant #{$variant->id}, got {$this->publishCount($variant)}.",
        );

        return $this;
    }

    public function assertPublishedOnce(PostVariant $variant): static
    {
        return $this->assertPublishedTimes($variant, 1);
    }

    public function assertNotPublished(PostVariant $variant): static
    {
        return $this->assertPublishedTimes($variant, 0);
    }

    public function assertNothingPublished(): static
    {
        Assert::assertSame(0, $this->publishCount(), "Expected no publish calls, got {$this->publishCount()}.");

        return $this;
    }

    public function assertDeleted(PostVariant $variant, int $times = 1): static
    {
        Assert::assertSame($times, $this->deleteCount($variant));

        return $this;
    }

    private function record(string $method, ?PostVariant $variant = null, ?Channel $channel = null): void
    {
        $this->calls[] = [
            'method' => $method,
            'variant_id' => $variant?->id,
            'channel_id' => $channel->id ?? $variant?->channel_id,
        ];
    }

    private function count(string $method, ?PostVariant $variant = null): int
    {
        return count(array_filter(
            $this->calls,
            fn (array $call): bool => $call['method'] === $method
                && ($variant === null || $call['variant_id'] === $variant->id),
        ));
    }
}
