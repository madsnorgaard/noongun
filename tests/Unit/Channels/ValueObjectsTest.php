<?php

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Contracts\ChannelCapabilities;
use App\Domain\Channels\Contracts\MediaLinkRule;
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
            'max_media_bytes' => null,
            'max_media_pixels' => null,
            'alt_text_length' => null,
            'media_link_rule' => 'in_text',
            'media_upload_lifetime' => null,
            'metrics' => [],
        ]);
});

it('says what a platform takes in images', function () {
    $capabilities = new ChannelCapabilities(
        maxBodyLength: 100,
        mediaTypes: ['image/jpeg', 'image/png'],
        maxMediaItems: 4,
        maxMediaBytes: 8_000_000,
        maxMediaPixels: 36_000_000,
        altTextLength: 120,
        mediaLinkRule: MediaLinkRule::Refused,
        mediaUploadLifetime: 3600,
    );

    expect($capabilities->acceptsMedia())->toBeTrue()
        ->and((new ChannelCapabilities(maxBodyLength: 100))->acceptsMedia())->toBeFalse()
        ->and((new ChannelCapabilities(maxBodyLength: 100, mediaTypes: ['image/jpeg']))->acceptsMedia())->toBeFalse()
        ->and($capabilities->toArray())->toMatchArray([
            'max_media_bytes' => 8_000_000,
            'max_media_pixels' => 36_000_000,
            'alt_text_length' => 120,
            'media_link_rule' => 'refused',
            'media_upload_lifetime' => 3600,
        ]);
});

it('refuses limits for media that are not positive', function (array $arguments) {
    expect(fn () => new ChannelCapabilities(...['maxBodyLength' => 100, ...$arguments]))->toThrow(InvalidArgumentException::class);
})->with([
    'bytes' => [['maxMediaBytes' => 0]],
    'pixels' => [['maxMediaPixels' => -1]],
    'alternative text' => [['altTextLength' => 0]],
    'lifetime of an upload' => [['mediaUploadLifetime' => 0]],
]);

it('sends the link of a post with images as the last line of its text, where images take the place of the preview', function () {
    $inText = new ChannelCapabilities(maxBodyLength: 100, mediaTypes: ['image/jpeg'], maxMediaItems: 2);
    $both = new ChannelCapabilities(maxBodyLength: 100, mediaTypes: ['image/jpeg'], maxMediaItems: 2, mediaLinkRule: MediaLinkRule::Both);

    expect($inText->textFor('Open from eight.', 'https://example.com/a', 1))->toBe("Open from eight.\n\nhttps://example.com/a")
        ->and($inText->textFor("Open from eight.\n", 'https://example.com/a', 2))->toBe("Open from eight.\n\nhttps://example.com/a")
        ->and($inText->textFor('', 'https://example.com/a', 1))->toBe('https://example.com/a')
        // Without images the link keeps its preview and stays out of the text.
        ->and($inText->textFor('Open from eight.', 'https://example.com/a', 0))->toBe('Open from eight.')
        ->and($inText->textFor('Open from eight.', null, 1))->toBe('Open from eight.')
        ->and($inText->textFor('Open from eight.', '  ', 1))->toBe('Open from eight.')
        // A link the author already put at the end is not sent twice.
        ->and($inText->textFor('Read https://example.com/a', 'https://example.com/a', 1))->toBe('Read https://example.com/a')
        ->and($inText->linkGoesInText('https://example.com/a', 1))->toBeTrue()
        ->and($inText->linkGoesInText('https://example.com/a', 0))->toBeFalse()
        ->and($both->textFor('Open from eight.', 'https://example.com/a', 1))->toBe('Open from eight.')
        ->and($both->linkGoesInText('https://example.com/a', 1))->toBeFalse();
});

it('gives the reader one link, with its campaign parameters, when the text already ends with the link', function () {
    $inText = new ChannelCapabilities(maxBodyLength: 500, mediaTypes: ['image/jpeg'], maxMediaItems: 2);
    $typed = 'https://example.com/a';
    $sent = 'https://example.com/a?utm_source=noongun';

    // The typed link at the end is replaced by the one that is sent.
    expect($inText->textFor("Open from eight.\n\n{$typed}", $sent, 1, $typed))->toBe("Open from eight.\n\n{$sent}")
        ->and($inText->textFor("Open from eight. {$typed}  \n", $sent, 1, $typed))->toBe("Open from eight. {$sent}")
        ->and($inText->textFor($typed, $sent, 1, $typed))->toBe($sent)
        // Already the link that is sent: nothing to do.
        ->and($inText->textFor("Open from eight.\n\n{$sent}", $sent, 1, $typed))->toBe("Open from eight.\n\n{$sent}")
        // Without parameters both are the same link.
        ->and($inText->textFor("Read {$typed}", $typed, 1, $typed))->toBe("Read {$typed}")
        // The link somewhere else in the text is not the last line.
        ->and($inText->textFor("{$typed} is where to read it.", $sent, 1, $typed))->toBe("{$typed} is where to read it.\n\n{$sent}")
        // Another address that only ends the same way is not this link.
        ->and($inText->textFor("See https://other.example/?next={$typed}", $sent, 1, $typed))->toBe("See https://other.example/?next={$typed}\n\n{$sent}")
        // Not given the typed link, as before: the sent one is added.
        ->and($inText->textFor("Open from eight.\n\n{$typed}", $sent, 1))->toBe("Open from eight.\n\n{$typed}\n\n{$sent}")
        // Without images nothing is touched.
        ->and($inText->textFor("Open from eight.\n\n{$typed}", $sent, 0, $typed))->toBe("Open from eight.\n\n{$typed}");
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
