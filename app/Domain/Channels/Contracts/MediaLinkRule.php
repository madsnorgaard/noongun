<?php

namespace App\Domain\Channels\Contracts;

/**
 * What a platform does with a post that has both images and a link
 * (PostVariant::$link). On most platforms a post with a picture and a post
 * with a link preview are two different kinds of post.
 */
enum MediaLinkRule: string
{
    /**
     * The images win. The post shows them and no preview or card of the
     * link. The link is sent as the last line of the text, see
     * ChannelCapabilities::textFor(). A typed card for the link is not used.
     */
    case InText = 'in_text';

    /** The platform shows the images and the link as it shows a link without them. */
    case Both = 'both';

    /** The platform takes one or the other. A variant with both cannot be approved. */
    case Refused = 'refused';
}
