<?php

use App\Http\Presenters\PlatformLabel;

it('spells a platform the way the platform does', function (string $platform, string $label) {
    expect(PlatformLabel::for($platform))->toBe($label);
})->with([
    ['facebook', 'Facebook'],
    ['linkedin', 'LinkedIn'],
    ['mastodon', 'Mastodon'],
    ['some_platform', 'Some Platform'],
]);
