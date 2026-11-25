<?php

namespace App\Domain\Channels\Support;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\TransferException;
use Throwable;

/**
 * What a failed HTTP call says about how far it got. Shared by the platform
 * clients, because the answer decides whether a publish may be sent again.
 */
final class NetworkFailure
{
    /**
     * True only when the connection was never established: the host or
     * proxy did not resolve, the connection was refused, or the TLS
     * handshake failed. No byte of the request left this machine then.
     *
     * This relies on Guzzle 8, where ConnectException means exactly that and
     * timeouts have classes of their own. In Guzzle 7 a ConnectException
     * could also be a timeout after the request was sent, so without the
     * Guzzle 8 classes nothing is taken as "never connected". A timeout
     * while connecting is not trusted either: Guzzle tells it apart from a
     * later timeout by the text of the curl error.
     */
    public static function neverConnected(Throwable $exception): bool
    {
        if (! class_exists(NetworkTimeoutException::class)) {
            return false;
        }

        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof TransferException) {
                return $cause instanceof ConnectException && ! $cause instanceof ConnectTimeoutException;
            }
        }

        return false;
    }
}
