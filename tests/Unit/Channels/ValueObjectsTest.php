<?php

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Contracts\ChannelCapabilities;
use App\Domain\Channels\Contracts\PublishResult;
use App\Domain\Channels\Contracts\RateLimit;
use Carbon\CarbonImmutable;

it('describes what a platform accepts', function () {
    $capabilities = new ChannelCapabilities(
        maxBodyLength: 10,
        mediaTypes: ['image/jpeg'],
        maxMediaItems: 1,
        linkPreviews: true,
        rateLimit: new RateLimit(maxCalls: 200, perSeconds: 3600),
    );

    expect($capabilities->fits('ten chars!'))->toBeTrue()
        ->and($capabilities->fits('eleven char'))->toBeFalse()
        ->and($capabilities->fits('æøå æøå æø'))->toBeTrue()
        ->and($capabilities->supportsMedia('IMAGE/JPEG'))->toBeTrue()
        ->and($capabilities->supportsMedia('video/mp4'))->toBeFalse()
        ->and($capabilities->toArray())->toBe([
            'max_body_length' => 10,
            'media_types' => ['image/jpeg'],
            'max_media_items' => 1,
            'link_previews' => true,
            'rate_limit' => ['max_calls' => 200, 'per_seconds' => 3600],
            'link_title_length' => null,
            'link_description_length' => null,
            'metrics' => [],
        ]);
});

it('defaults to text only, with no rate limit known', function () {
    $capabilities = new ChannelCapabilities(maxBodyLength: 3000);

    expect($capabilities->supportsMedia('image/jpeg'))->toBeFalse()
        ->and($capabilities->linkPreviews)->toBeFalse()
        ->and($capabilities->rateLimit)->toBeNull()
        ->and($capabilities->needsLinkCard())->toBeFalse();
});

it('says when the author has to supply the card for a link', function () {
    $capabilities = new ChannelCapabilities(maxBodyLength: 3000, linkTitleLength: 399, linkDescriptionLength: 4085);

    expect($capabilities->needsLinkCard())->toBeTrue()
        ->and($capabilities->toArray())->toMatchArray(['link_title_length' => 399, 'link_description_length' => 4085])
        ->and(fn () => new ChannelCapabilities(maxBodyLength: 3000, linkTitleLength: 0))->toThrow(InvalidArgumentException::class);
});

it('refuses capabilities and rate limits that make no sense', function () {
    expect(fn () => new ChannelCapabilities(maxBodyLength: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new RateLimit(maxCalls: 0, perSeconds: 60))->toThrow(InvalidArgumentException::class);
});

it('keeps the publish time in UTC', function (string $timezone) {
    $result = new PublishResult('123_456', 'https://www.facebook.com/123_456', new DateTimeImmutable('2026-07-15 12:00:00', new DateTimeZone($timezone)));

    expect($result->publishedAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and($result->publishedAt->timezoneName)->toBe('UTC')
        ->and($result->publishedAt->format('Y-m-d H:i'))->toBe('2026-07-15 10:00')
        ->and($result->externalId)->toBe('123_456');
})->with(['Europe/Copenhagen', 'Africa/Johannesburg']);

it('needs an external id and allows a missing permalink', function () {
    expect(fn () => new PublishResult('', null, now()))->toThrow(InvalidArgumentException::class)
        ->and((new PublishResult('1', null, now()))->permalink)->toBeNull();
});

it('lets only active channels publish', function (ChannelStatus $status) {
    expect($status->canPublish())->toBe($status === ChannelStatus::Active);
})->with(ChannelStatus::cases());
