<?php

namespace App\Domain\Channels\Exceptions;

/**
 * Thrown by ChannelAdapter::publish(). PublishPostJob retries only when
 * $retryable is true, and asks for a reconnect when $requiresReconnect is.
 */
class PublishFailed extends ChannelException {}
