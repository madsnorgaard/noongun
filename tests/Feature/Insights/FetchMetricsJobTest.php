<?php

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Contracts\Metric;
use App\Domain\Channels\Contracts\MetricReading;
use App\Domain\Channels\Contracts\RateLimit;
use App\Domain\Channels\Exceptions\ChannelException;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostState;
use App\Domain\Insights\ChannelPolling;
use App\Domain\Insights\FetchMetricsJob;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';
require_once __DIR__.'/../../Support/insights.php';

beforeEach(function () {
    $this->travelTo('2026-11-26 10:00:00');

    $this->fake = fakeMetricsAdapter();
    $this->workspace = Workspace::factory()->create();
    $this->channel = channelIn($this->workspace);
    $this->variant = publishedVariant($this->workspace, $this->channel, now()->subHours(2));
});

it('reads the due variants of a channel in one call and stores their numbers', function () {
    $second = publishedVariant($this->workspace, $this->channel, now()->subHours(3));
    $this->fake->reads($this->variant, ['reactions' => 12, 'comments' => 3, 'shares' => 1, 'clicks' => 40, 'reach' => 900])
        ->reads($second, ['reactions' => 0, 'comments' => 0, 'shares' => 0]);

    runFetchMetrics($this->channel);

    $this->fake->assertMetricsFetchedTimes(1);

    expect($this->fake->lastMetricBatch())->toEqualCanonicalizing([$this->variant->id, $second->id])
        ->and($this->fake->metricChannels)->toBe([$this->channel->id])
        ->and(snapshotsOf($this->variant))->toHaveCount(1)
        ->and(snapshotsOf($this->variant)[0]->reading()->toArray())
        ->toBe(['reactions' => 12, 'comments' => 3, 'shares' => 1, 'clicks' => 40, 'reach' => 900])
        ->and(snapshotsOf($second)[0]->reading()->toArray())
        ->toBe(['reactions' => 0, 'comments' => 0, 'shares' => 0, 'clicks' => null, 'reach' => null])
        ->and(snapshotsOf($this->variant)[0]->captured_at->toDateTimeString())->toBe('2026-11-26 10:00:00')
        ->and(metricsCheckedAt($this->variant))->toBe('2026-11-26 10:00:00')
        ->and(app(CurrentWorkspace::class)->get())->toBeNull();
});

it('finds nothing due when it runs again, and adds no row for the same numbers later', function () {
    $this->fake->reads($this->variant, ['reactions' => 12]);

    runFetchMetrics($this->channel);
    runFetchMetrics($this->channel);

    $this->fake->assertMetricsFetchedTimes(1);

    $this->travel(61)->minutes();
    runFetchMetrics($this->channel);

    $this->fake->assertMetricsFetchedTimes(2);

    expect(snapshotsOf($this->variant))->toHaveCount(1)
        ->and(snapshotsOf($this->variant)[0]->checked_at->toDateTimeString())->toBe('2026-11-26 11:01:00');
});

it('records that a variant was asked about when the platform gave no reading for it', function () {
    runFetchMetrics($this->channel);
    runFetchMetrics($this->channel);

    $this->fake->assertMetricsFetchedTimes(1);

    expect(snapshotsOf($this->variant))->toBe([])
        ->and(metricsCheckedAt($this->variant))->toBe('2026-11-26 10:00:00');
});

it('stops asking about a post that an adapter reports as gone three times in a row, and not sooner', function () {
    $this->fake->reads($this->variant, MetricReading::gone());

    foreach ([1, 2] as $round) {
        runFetchMetrics($this->channel);
        $this->travel(61)->minutes();
    }

    // A reading in between: the post was there after all.
    $this->fake->reads($this->variant, ['reactions' => 4]);
    runFetchMetrics($this->channel);

    expect(goneCount($this->variant))->toBe(0)
        ->and(snapshotsOf($this->variant))->toHaveCount(1);

    $this->fake->reads($this->variant, MetricReading::gone());

    foreach ([1, 2, 3, 4, 5] as $round) {
        $this->travel(61)->minutes();
        runFetchMetrics($this->channel);
    }

    // Two, one, and then three more: the last two rounds asked nothing.
    $this->fake->assertMetricsFetchedTimes(6);

    expect(goneCount($this->variant))->toBe(3)
        ->and(snapshotsOf($this->variant))->toHaveCount(1)
        ->and(ChannelPolling::isPaused($this->channel->id))->toBeFalse();
});

it('does not count a missing reading as a post that is gone', function () {
    foreach ([1, 2, 3, 4] as $round) {
        runFetchMetrics($this->channel);
        $this->travel(61)->minutes();
    }

    $this->fake->assertMetricsFetchedTimes(4);

    expect(goneCount($this->variant))->toBe(0);
});

it('stores only the metrics the channel may read, and null for the rest', function () {
    $this->fake->channelsMayRead([Metric::Reactions, Metric::Comments, Metric::Shares])
        ->reads($this->variant, ['reactions' => 5, 'comments' => 1, 'shares' => 0, 'clicks' => 77, 'reach' => 1234]);

    runFetchMetrics($this->channel);

    expect(snapshotsOf($this->variant)[0]->reading()->toArray())
        ->toBe(['reactions' => 5, 'comments' => 1, 'shares' => 0, 'clicks' => null, 'reach' => null]);
});

it('ignores a reading for a variant it did not ask about', function () {
    $otherWorkspace = Workspace::factory()->create();
    $foreign = publishedVariant($otherWorkspace, null, now()->subHours(2));
    $notDue = publishedVariant($this->workspace, $this->channel, now()->subDays(400));

    $this->fake->reads($this->variant, ['reactions' => 2])
        ->alsoReturns($foreign, ['reactions' => 999])
        ->alsoReturns($notDue, ['reactions' => 999]);

    runFetchMetrics($this->channel);

    expect($this->fake->lastMetricBatch())->toBe([$this->variant->id])
        ->and(snapshotsOf($this->variant))->toHaveCount(1)
        ->and(snapshotsOf($foreign))->toBe([])
        ->and(snapshotsOf($notDue))->toBe([])
        ->and(metricsCheckedAt($notDue))->toBeNull();
});

it('reads only the variants of its own workspace and channel', function () {
    $otherChannel = channelIn($this->workspace);
    $onOtherChannel = publishedVariant($this->workspace, $otherChannel, now()->subHours(2));
    $otherWorkspace = Workspace::factory()->create();
    $foreign = publishedVariant($otherWorkspace, null, now()->subHours(2));

    runFetchMetrics($this->channel);

    expect($this->fake->lastMetricBatch())->toBe([$this->variant->id])
        ->and(metricsCheckedAt($onOtherChannel))->toBeNull()
        ->and(metricsCheckedAt($foreign))->toBeNull();
});

it('reads at most the configured number of variants in one run, the longest waiting first', function () {
    config(['noongun.insights.variants_per_run' => 2]);

    $waiting = publishedVariant($this->workspace, $this->channel, now()->subHours(5));
    $asked = publishedVariant($this->workspace, $this->channel, now()->subHours(4));
    DB::table('post_variants')->where('id', $asked->id)->update(['metrics_checked_at' => now()->subHours(3)]);

    runFetchMetrics($this->channel);

    expect($this->fake->lastMetricBatch())->toBe([$this->variant->id, $waiting->id]);

    runFetchMetrics($this->channel);

    expect($this->fake->lastMetricBatch())->toBe([$asked->id]);
});

it('does nothing, without an error, for a channel that cannot be read', function (Closure $arrange) {
    $arrange($this);

    Log::spy();
    runFetchMetrics($this->channel);

    $this->fake->assertNoMetricsFetched();

    expect(metricsCheckedAt($this->variant))->toBeNull();
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
})->with([
    'needs a reconnect' => [fn ($test) => DB::table('channels')->where('id', $test->channel->id)->update(['status' => ChannelStatus::NeedsReconnect->value])],
    'disconnected' => [fn ($test) => inWorkspace($test->workspace, fn () => $test->channel->disconnect())],
    'pending' => [fn ($test) => DB::table('channels')->where('id', $test->channel->id)->update(['status' => ChannelStatus::Pending->value])],
    'token expired' => [fn ($test) => DB::table('channels')->where('id', $test->channel->id)->update(['token_expires_at' => now()->subDay()])],
    'paused' => [fn ($test) => ChannelPolling::pause($test->channel->id, 600)],
    'locked by another run' => [fn ($test) => ChannelPolling::lock($test->channel->id)->get()],
    'a platform without metrics' => [fn ($test) => $test->fake->givesMetrics([])],
    'a token that may read no metric' => [fn ($test) => $test->fake->channelsMayRead([])],
    'an adapter that does not read metrics' => [fn ($test) => fakeChannelAdapter()],
    'insights turned off' => [fn ($test) => config(['noongun.insights.enabled' => false])],
    'a channel that is gone' => [fn ($test) => $test->channel->id = 999999],
    'a workspace that is gone' => [fn ($test) => $test->channel->workspace_id = 999999],
]);

it('leaves the channel alone for the time the platform asks when it limits requests', function () {
    $this->fake->failMetricsRetryable(retryAfterSeconds: 7200)->reads($this->variant, ['reactions' => 1]);

    Log::spy();
    runFetchMetrics($this->channel);

    expect(ChannelPolling::isPaused($this->channel->id))->toBeTrue()
        ->and(snapshotsOf($this->variant))->toBe([])
        ->and(metricsCheckedAt($this->variant))->toBeNull();

    Log::shouldHaveReceived('warning')->once();

    $this->travel(119)->minutes();
    runFetchMetrics($this->channel);
    $this->fake->assertMetricsFetchedTimes(1);

    $this->travel(2)->minutes();
    runFetchMetrics($this->channel);
    $this->fake->assertMetricsFetchedTimes(2);

    expect(snapshotsOf($this->variant))->toHaveCount(1);
});

it('waits the configured pause when the platform names no time', function () {
    config(['noongun.insights.pause_minutes' => 30]);
    $this->fake->failMetricsRetryable();

    runFetchMetrics($this->channel);

    $this->travel(29)->minutes();
    expect(ChannelPolling::isPaused($this->channel->id))->toBeTrue();

    $this->travel(2)->minutes();
    expect(ChannelPolling::isPaused($this->channel->id))->toBeFalse();
});

it('never changes a channel, a post or a variant when reading fails, apart from when the variant was asked about', function (Closure $fail, bool $movesOn) {
    $fail($this->fake);

    $rows = fn (): array => array_map(fn (?stdClass $row): array => array_diff_key((array) $row, ['metrics_checked_at' => true]), [
        DB::table('channels')->where('id', $this->channel->id)->first(),
        DB::table('posts')->where('id', $this->variant->post_id)->first(),
        DB::table('post_variants')->where('id', $this->variant->id)->first(),
    ]);

    $before = $rows();

    runFetchMetrics($this->channel);

    $this->fake->assertMetricsFetchedTimes(1);

    expect($rows())->toBe($before)
        ->and(DB::table('channels')->where('id', $this->channel->id)->value('status'))->toBe(ChannelStatus::Active->value)
        ->and(DB::table('posts')->where('id', $this->variant->post_id)->value('state'))->toBe(PostState::Published->value)
        ->and(ChannelPolling::isPaused($this->channel->id))->toBeTrue()
        ->and(snapshotsOf($this->variant))->toBe([])
        // A refusal may be about the variants that were asked about, so
        // they go to the back of the line. A rate limit is about nothing
        // but the time: they stay first.
        ->and(metricsCheckedAt($this->variant))->toBe($movesOn ? '2026-11-26 10:00:00' : null);
})->with([
    'the token is not accepted' => [fn ($fake) => $fake->failMetricsReconnectRequired(), true],
    'a refusal' => [fn ($fake) => $fake->failMetricsPermanently(), true],
    'a temporary problem' => [fn ($fake) => $fake->failMetricsRetryable(), true],
    'a rate limit with a time to wait' => [fn ($fake) => $fake->failMetricsRetryable(retryAfterSeconds: 60), false],
    'a rate limit without one' => [fn ($fake) => $fake->failNextMetrics(ChannelException::retryable('facebook', 'The platform is limiting requests.', null, ['rate_limited' => true])), false],
    'an unexpected exception' => [fn ($fake) => $fake->failNextMetrics(new RuntimeException('access_token=EAAB-secret-value')), true],
]);

it('lets the variants behind a refused one be read at the next run, instead of asking about the same ones again', function () {
    config(['noongun.insights.variants_per_run' => 1, 'noongun.insights.variants_per_call' => 1]);
    $behind = publishedVariant($this->workspace, $this->channel, now()->subHours(3));

    $this->fake->failMetricsPermanently()->reads($behind, ['reactions' => 8]);

    runFetchMetrics($this->channel);
    ChannelPolling::resume($this->channel->id);
    runFetchMetrics($this->channel);

    expect($this->fake->metricBatches)->toBe([[$this->variant->id], [$behind->id]])
        ->and(snapshotsOf($behind))->toHaveCount(1);
});

it('stores what each call returned before the next call, so a failure keeps what was read', function () {
    config(['noongun.insights.variants_per_call' => 2]);
    $this->fake->withRateLimit(new RateLimit(maxCalls: 1000, perSeconds: 3600));

    $variants = [$this->variant];

    foreach (range(1, 4) as $i) {
        $variants[] = publishedVariant($this->workspace, $this->channel, now()->subHours(2 + $i));
    }

    $this->fake->readsUsing(function (PostVariant $variant, int $call) {
        if ($call === 2) {
            throw ChannelException::retryable('facebook', 'The platform is limiting requests.', 600);
        }

        return new MetricReading(reactions: 1);
    });

    runFetchMetrics($this->channel);

    $stored = array_values(array_filter($variants, fn (PostVariant $variant) => snapshotsOf($variant) !== []));

    // Two calls were made and paid for; the third never was.
    expect($this->fake->metricBatches)->toHaveCount(2)
        ->and($this->fake->metricBatches[0])->toHaveCount(2)
        ->and(array_map(fn (PostVariant $variant) => $variant->id, $stored))->toEqualCanonicalizing($this->fake->metricBatches[0])
        ->and(ChannelPolling::callsLeft($this->channel->id, new RateLimit(maxCalls: 1000, perSeconds: 3600)))->toBe(200 - 4)
        ->and(ChannelPolling::pauseOf($this->channel->id)['kind'])->toBe(ChannelPolling::PAUSE_RATE_LIMIT);
});

it('makes no further call when another one might not finish inside the time limit of the job', function () {
    // One call may take 30 seconds and the job has 20: only the first fits.
    config([
        'noongun.insights.variants_per_call' => 1,
        'noongun.insights.timeout' => 20,
        'noongun.publishing.timeout' => 30,
    ]);
    $this->fake->withRateLimit(new RateLimit(maxCalls: 1000, perSeconds: 3600));

    publishedVariant($this->workspace, $this->channel, now()->subHours(3));
    publishedVariant($this->workspace, $this->channel, now()->subHours(4));

    runFetchMetrics($this->channel);

    expect($this->fake->metricBatches)->toHaveCount(1)
        // Nothing was spent for the calls that were not made.
        ->and(ChannelPolling::callsLeft($this->channel->id, new RateLimit(maxCalls: 1000, perSeconds: 3600)))->toBe(199);

    // With room for them, every call is made in one run.
    config(['noongun.insights.timeout' => 300]);
    runFetchMetrics($this->channel);

    expect($this->fake->metricBatches)->toHaveCount(3);
});

it('says why reading is paused and until when', function () {
    expect(ChannelPolling::pauseOf($this->channel->id))->toBeNull();

    $this->fake->failMetricsRetryable(retryAfterSeconds: 7200);
    runFetchMetrics($this->channel);

    expect(ChannelPolling::pauseOf($this->channel->id))->toEqual([
        'kind' => 'rate_limit',
        'until' => CarbonImmutable::parse('2026-11-26 12:00:00', 'UTC'),
    ]);

    ChannelPolling::resume($this->channel->id);
    $this->travel(61)->minutes();
    $this->fake->failMetricsPermanently();
    runFetchMetrics($this->channel);

    expect(ChannelPolling::pauseOf($this->channel->id))->toEqual([
        'kind' => 'other',
        'until' => CarbonImmutable::parse('2026-11-26 12:01:00', 'UTC'),
    ]);

    ChannelPolling::resume($this->channel->id);
    $this->travel(61)->minutes();
    $this->fake->failMetricsReconnectRequired();
    runFetchMetrics($this->channel);

    expect(ChannelPolling::pauseOf($this->channel->id))->toEqual([
        'kind' => 'token',
        'until' => CarbonImmutable::parse('2026-11-26 18:02:00', 'UTC'),
    ]);

    // A wait the platform asked for is said first: a reconnect does not end it.
    ChannelPolling::pause($this->channel->id, 600, ChannelPolling::PAUSE_RATE_LIMIT);
    expect(ChannelPolling::pauseOf($this->channel->id)['kind'])->toBe('rate_limit');

    ChannelPolling::pause($this->channel->id, 600);
    expect(ChannelPolling::pauseOf($this->channel->id)['kind'])->toBe('token')
        ->and(ChannelPolling::isPaused($this->channel->id))->toBeTrue();

    ChannelPolling::resume($this->channel->id);
    expect(ChannelPolling::pauseOf($this->channel->id))->toBeNull()
        ->and(ChannelPolling::isPaused($this->channel->id))->toBeFalse();
});

it('keeps a number the variant has when a later reading lacks it and the channel can still read it', function () {
    $this->fake->reads($this->variant, ['reactions' => 12, 'comments' => 3, 'shares' => 1, 'clicks' => 40, 'reach' => 900]);
    runFetchMetrics($this->channel);

    $this->travel(61)->minutes();
    $this->fake->reads($this->variant, ['reactions' => 13, 'comments' => 3, 'shares' => 1]);
    runFetchMetrics($this->channel);

    $rows = snapshotsOf($this->variant);

    expect($rows)->toHaveCount(2)
        ->and($rows[1]->reading()->toArray())->toBe(['reactions' => 13, 'comments' => 3, 'shares' => 1, 'clicks' => 40, 'reach' => 900])
        ->and($rows[1]->carriedSince(Metric::Clicks)?->toDateTimeString())->toBe('2026-11-26 11:01:00')
        ->and($rows[1]->carriedSince(Metric::Reach)?->toDateTimeString())->toBe('2026-11-26 11:01:00')
        ->and($rows[1]->carriedSince(Metric::Reactions))->toBeNull();
});

it('lets a number become "not given" once the channel can no longer read that metric', function () {
    $this->fake->reads($this->variant, ['reactions' => 12, 'clicks' => 40, 'reach' => 900]);
    runFetchMetrics($this->channel);

    $this->travel(61)->minutes();
    $this->fake->channelsMayRead([Metric::Reactions, Metric::Comments, Metric::Shares])
        ->reads($this->variant, ['reactions' => 12, 'clicks' => 41, 'reach' => 950]);
    runFetchMetrics($this->channel);

    $rows = snapshotsOf($this->variant);

    expect($rows)->toHaveCount(2)
        ->and($rows[1]->reading()->toArray())->toBe(['reactions' => 12, 'comments' => null, 'shares' => null, 'clicks' => null, 'reach' => null])
        ->and($rows[1]->carried)->toBeNull();
});

it('logs a failure without a token and without the exception of the HTTP client', function () {
    $token = (string) inWorkspace($this->workspace, fn () => $this->channel->fresh()->access_token);
    $this->fake->failNextMetrics(ChannelException::retryable('facebook', "Refused: access_token={$token}", 60, ['access_token' => $token]));
    $this->fake->failNextMetrics(new RuntimeException("GET https://graph.test/?access_token={$token}"));

    $logged = [];
    Log::listen(function ($message) use (&$logged) {
        $logged[] = json_encode([$message->level, $message->message, $message->context]);
    });

    runFetchMetrics($this->channel);
    ChannelPolling::resume($this->channel->id);
    runFetchMetrics($this->channel);

    expect($logged)->toHaveCount(2)
        ->and(implode("\n", $logged))->not->toContain($token)
        ->and($logged[1])->toContain('RuntimeException')->not->toContain('graph.test');
});

it('carries no token and no text in its payload', function () {
    $job = new FetchMetricsJob($this->workspace->id, $this->channel->id);
    $payload = serialize($job);
    $token = (string) inWorkspace($this->workspace, fn () => $this->channel->fresh()->access_token);

    expect($payload)->not->toContain($token)
        ->and(array_keys(get_object_vars($job)))->toContain('workspaceId', 'channelId')
        ->and($job->queue)->toBe('insights')
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(45);
});

it('spends no more than its share of the rate limit of the channel', function () {
    config(['noongun.insights.rate_limit_percent' => 20, 'noongun.insights.variants_per_run' => 50]);
    $this->fake->withRateLimit(new RateLimit(maxCalls: 50, perSeconds: 3600), callsPerVariant: 2);

    foreach (range(1, 7) as $i) {
        publishedVariant($this->workspace, $this->channel, now()->subHours(2));
    }

    // 20% of 50 calls is 10 calls, and a variant costs 2: five variants.
    runFetchMetrics($this->channel);
    expect($this->fake->lastMetricBatch())->toHaveCount(5);

    runFetchMetrics($this->channel);
    $this->fake->assertMetricsFetchedTimes(1);

    // The window of the limit has passed.
    $this->travel(61)->minutes();
    runFetchMetrics($this->channel);
    $this->fake->assertMetricsFetchedTimes(2);
    expect($this->fake->lastMetricBatch())->toHaveCount(5);
});

it('counts calls that failed against the share too', function () {
    $this->fake->withRateLimit(new RateLimit(maxCalls: 10, perSeconds: 3600))->failMetricsRetryable();

    runFetchMetrics($this->channel);

    expect(ChannelPolling::callsLeft($this->channel->id, new RateLimit(maxCalls: 10, perSeconds: 3600)))->toBe(1);
});

it('releases the lock of the channel when it is done, also after a failure', function () {
    $this->fake->failMetricsPermanently();

    runFetchMetrics($this->channel);

    $lock = ChannelPolling::lock($this->channel->id);

    expect($lock->get())->toBeTrue();
    $lock->release();
});
