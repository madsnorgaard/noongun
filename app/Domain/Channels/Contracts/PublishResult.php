<?php

namespace App\Domain\Channels\Contracts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * What a platform reported for one successful publish.
 */
final readonly class PublishResult
{
    public CarbonImmutable $publishedAt;

    /**
     * @param  string  $externalId  the platform's id of the post
     * @param  string|null  $permalink  public URL of the post, null when the platform gives none
     * @param  DateTimeInterface  $publishedAt  stored in UTC whatever timezone is passed
     */
    public function __construct(
        public string $externalId,
        public ?string $permalink,
        DateTimeInterface $publishedAt,
    ) {
        if ($externalId === '') {
            throw new InvalidArgumentException('A publish result needs the external id of the post.');
        }

        $this->publishedAt = CarbonImmutable::instance($publishedAt)->utc();
    }
}
