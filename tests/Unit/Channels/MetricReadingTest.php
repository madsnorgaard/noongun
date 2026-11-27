<?php

use App\Domain\Channels\Contracts\ChannelCapabilities;
use App\Domain\Channels\Contracts\Metric;
use App\Domain\Channels\Contracts\MetricReading;

it('keeps a number the platform did not give apart from zero', function () {
    $reading = new MetricReading(reactions: 0, comments: 4);

    expect($reading->reactions)->toBe(0)
        ->and($reading->get(Metric::Reactions))->toBe(0)
        ->and($reading->shares)->toBeNull()
        ->and($reading->get(Metric::Reach))->toBeNull()
        ->and($reading->isEmpty())->toBeFalse()
        ->and((new MetricReading)->isEmpty())->toBeTrue()
        ->and((new MetricReading(reactions: 0))->equals(new MetricReading))->toBeFalse()
        ->and($reading->toArray())->toBe(['reactions' => 0, 'comments' => 4, 'shares' => null, 'clicks' => null, 'reach' => null]);
});

it('refuses numbers that cannot be counts', function (array $numbers) {
    expect(fn () => new MetricReading(...$numbers))->toThrow(InvalidArgumentException::class);
})->with([
    'negative' => [['reactions' => -1]],
    'far too large' => [['reach' => MetricReading::MAX + 1]],
]);

it('drops what a platform sent that is not a whole number in range', function () {
    $reading = MetricReading::fromPlatform([
        'reactions' => '12',
        'comments' => -3,
        'shares' => 2.5,
        'clicks' => true,
        'reach' => '99999999999999999999',
        'followers' => 7,
    ]);

    expect($reading->toArray())->toBe(['reactions' => 12, 'comments' => null, 'shares' => null, 'clicks' => null, 'reach' => null])
        ->and(MetricReading::fromPlatform(['shares' => 0, 'reach' => MetricReading::MAX])->toArray())
        ->toMatchArray(['shares' => 0, 'reach' => MetricReading::MAX])
        ->and(MetricReading::fromPlatform(['reactions' => '', 'comments' => ' 4', 'shares' => '1e3', 'clicks' => ['count' => 1], 'reach' => null])->isEmpty())->toBeTrue();
});

it('can be narrowed to the metrics a channel may read', function () {
    $reading = new MetricReading(reactions: 5, comments: 1, shares: 0, clicks: 9, reach: 300);

    expect($reading->only([Metric::Reactions, Metric::Shares])->toArray())
        ->toBe(['reactions' => 5, 'comments' => null, 'shares' => 0, 'clicks' => null, 'reach' => null])
        ->and($reading->only([])->isEmpty())->toBeTrue();
});

it('can say that the post is gone, which is not a number and not a failure', function () {
    $gone = MetricReading::gone();

    expect($gone->gone)->toBeTrue()
        ->and($gone->isEmpty())->toBeTrue()
        ->and($gone->only([Metric::Reactions])->gone)->toBeTrue()
        ->and((new MetricReading(reactions: 0))->gone)->toBeFalse()
        ->and(MetricReading::fromPlatform(['reactions' => 3, 'gone' => true])->gone)->toBeFalse()
        ->and(fn () => new MetricReading(reactions: 1, gone: true))->toThrow(InvalidArgumentException::class);
});

it('lists the metrics of a platform in its capabilities', function () {
    $none = new ChannelCapabilities(maxBodyLength: 3000);
    $some = new ChannelCapabilities(maxBodyLength: 3000, metrics: [Metric::Reactions, Metric::Comments]);

    expect($none->readsMetrics())->toBeFalse()
        ->and($none->toArray()['metrics'])->toBe([])
        ->and($some->readsMetrics())->toBeTrue()
        ->and($some->gives(Metric::Comments))->toBeTrue()
        ->and($some->gives(Metric::Reach))->toBeFalse()
        ->and($some->toArray()['metrics'])->toBe(['reactions', 'comments']);
});
