<?php

namespace App\Domain\Drafting\Generators;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\ResponseReceived;

/**
 * Keeps the ProviderReply of the last HTTP answer that arrived while a call
 * to a provider was being made. It listens to the HTTP client and looks
 * only during watch(), so answers to other requests of the application are
 * never read.
 *
 * Bound as a singleton: it registers one listener for the life of the
 * application.
 */
final class ProviderReplies
{
    private bool $watching = false;

    private ?ProviderReply $last = null;

    public function __construct(Dispatcher $events)
    {
        $events->listen(ResponseReceived::class, function (ResponseReceived $event): void {
            if ($this->watching) {
                $this->last = ProviderReply::from($event->response);
            }
        });
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $call
     * @return TResult
     */
    public function watch(Closure $call): mixed
    {
        $this->last = null;
        $this->watching = true;

        try {
            return $call();
        } finally {
            $this->watching = false;
        }
    }

    /**
     * The answer to the call of the last watch(), or null when none came:
     * the provider was not reached, or the call was not made over HTTP.
     */
    public function last(): ?ProviderReply
    {
        return $this->last;
    }
}
