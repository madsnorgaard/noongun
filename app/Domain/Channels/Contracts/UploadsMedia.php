<?php

namespace App\Domain\Channels\Contracts;

use App\Domain\Channels\Exceptions\ChannelException;
use App\Domain\Content\Models\MediaAttachment;
use App\Domain\Content\Models\PostVariant;

/**
 * For a ChannelAdapter whose platform takes images. An adapter that says in
 * its capabilities that it accepts media must implement this.
 *
 * Publishing a variant with images is several calls: each image is sent to
 * the platform first, and the post that shows them is created last. Only
 * that last call makes something public, and it stays the single call of
 * ChannelAdapter::publish().
 *
 * PublishPostJob drives the steps and remembers them. Inside the lock of
 * the variant, and before it marks the variant as "outcome unknown", it
 * calls uploadMedia() for every attachment the platform does not hold yet,
 * in order, and writes the returned id on the attachment at once
 * (MediaAttachment::markUploaded()). Then it calls publish(), which reads
 * the ids from $variant->images() and uploads nothing. A job that died or
 * failed half way is followed by one that sends only what is missing.
 *
 * The adapter stays stateless and writes nothing.
 */
interface UploadsMedia
{
    /**
     * Sends one image to the platform so that a later publish() of the
     * variant can refer to it, and returns what the platform calls it.
     *
     * - It must not make anything public. An upload the platform shows to
     *   anybody at once cannot be made through this method.
     * - Read the file with $attachment->readStream() and close the stream.
     *   Never ask for a path or an address: the file may be on object
     *   storage, and the instance may not be reachable from outside.
     * - Type, size and dimensions are on $attachment->image(); the
     *   alternative text is $attachment->alt_text. A platform that stores
     *   the text with the upload gets it here; a changed text makes the
     *   job upload again.
     * - All calls for one image together must stay within
     *   PublishingConfig::mediaTimeout() seconds.
     * - Because nothing public is made, every failure here means that no
     *   post exists. Mark a failure as retryable whenever trying again can
     *   help, including a timeout or a server error, which publish() may
     *   never treat as retryable. An upload that the platform kept without
     *   the answer arriving is only left behind there, unused.
     *
     * @return non-empty-string the platform's id of the uploaded image
     *
     * @throws ChannelException
     */
    public function uploadMedia(PostVariant $variant, MediaAttachment $attachment): string;
}
