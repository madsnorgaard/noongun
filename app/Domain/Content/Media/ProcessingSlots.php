<?php

namespace App\Domain\Content\Media;

use App\Domain\Content\Exceptions\MediaBusy;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Lets only a few images be decoded at the same time.
 *
 * Decoding is what an upload costs: four bytes for every pixel, and more in
 * the image library, outside what PHP's own memory limit counts. A file of
 * two kilobytes can ask for half a gigabyte for two seconds. The rate limit
 * counts requests in a minute and says nothing about how many run at once,
 * so without this a handful of small requests sent together could take all
 * the memory of the machine.
 *
 * There are two limits, both held as locks in the cache, which every web
 * process shares:
 *
 * - one image at a time for each user, so that one person cannot take every
 *   place;
 * - MediaConfig::concurrentUploads() images at a time on the whole instance.
 *
 * A request waits a few seconds for its turn, because a person who drops
 * five photos on the page sends them close together, and is then turned
 * away with MediaBusy. Waiting costs no memory.
 *
 * Each place is a lease. It is given back when the work ends, however it
 * ends, and when the process is killed before it can do that (out of
 * memory, a time limit) the lease runs out by itself.
 */
class ProcessingSlots
{
    /** How long a request sleeps between two looks for a free place. */
    private const POLL_MICROSECONDS = 100_000;

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $work
     * @return TReturn
     *
     * @throws MediaBusy
     */
    public function run(int $userId, Closure $work): mixed
    {
        $deadline = microtime(true) + MediaConfig::slotWaitSeconds();
        $lease = MediaConfig::slotLeaseSeconds();

        $own = $this->acquire([Cache::lock("noongun:media:user:{$userId}", $lease)], $deadline)
            ?? throw MediaBusy::forUser($this->retryAfter());

        try {
            $places = [];

            for ($place = 0; $place < MediaConfig::concurrentUploads(); $place++) {
                $places[] = Cache::lock("noongun:media:place:{$place}", $lease);
            }

            $shared = $this->acquire($places, $deadline)
                ?? throw MediaBusy::forInstance($this->retryAfter());

            try {
                return $work();
            } finally {
                $shared->release();
            }
        } finally {
            $own->release();
        }
    }

    /**
     * The first of the locks that could be taken before the deadline.
     *
     * @param  list<Lock>  $locks
     */
    private function acquire(array $locks, float $deadline): ?Lock
    {
        do {
            foreach ($locks as $lock) {
                if ($lock->get()) {
                    return $lock;
                }
            }

            if (microtime(true) >= $deadline) {
                return null;
            }

            usleep(self::POLL_MICROSECONDS);
        } while (true);
    }

    /**
     * What to tell the client to wait: about as long as one image takes.
     */
    private function retryAfter(): int
    {
        return 3;
    }
}
