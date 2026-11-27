<?php

namespace App\Domain\Channels\Exceptions;

/**
 * Thrown by ChannelAdapter::publish(). PublishPostJob retries only when
 * $retryable is true, and asks for a reconnect when $requiresReconnect is.
 */
class PublishFailed extends ChannelException
{
    /** Context key of uploadsNoLongerValid(). */
    public const UPLOADS_INVALID = 'uploads_invalid';

    /**
     * The platform refused to create the post because it no longer holds
     * the images that were uploaded for it (UploadsMedia): they expired,
     * or it does not know their ids. The adapter keeps no state, so this
     * is how it says so. PublishPostJob forgets the uploads of the variant
     * and, as for any retryable failure, sends the variant again within
     * its attempts: the images go up again first.
     *
     * Only for a definite refusal. Like every retryable failure it is a
     * promise that no post was made. To keep a post that the platform
     * refuses for another reason from being uploaded again and again, an
     * adapter should not blame uploads that were made a moment ago.
     *
     * @param  array<string, mixed>  $context
     */
    public static function uploadsNoLongerValid(string $platform, string $message, array $context = []): static
    {
        return new static($platform, $message, true, null, false, [...$context, self::UPLOADS_INVALID => true]);
    }

    /**
     * Whether the uploads of the variant must be made again before the
     * next attempt.
     */
    public function invalidatesUploads(): bool
    {
        return $this->retryable && ($this->context()[self::UPLOADS_INVALID] ?? false) === true;
    }
}
