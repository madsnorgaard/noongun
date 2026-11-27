<?php

use App\Domain\Channels\Adapters\Facebook\FacebookAdapter;
use App\Domain\Channels\ChannelManager;
use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Contracts\Metric;
use App\Domain\Channels\Contracts\MetricReading;
use App\Domain\Channels\Contracts\RateLimit;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostState;
use App\Domain\Insights\ChannelPolling;
use App\Domain\Insights\DueVariants;
use App\Domain\Insights\FetchMetricsJob;
use App\Domain\Insights\InsightsConfig;
use App\Domain\Scheduling\PublishingConfig;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\ProvisioningPlan;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';
require_once __DIR__.'/../../Support/insights.php';

beforeEach(function () {
    $this->travelTo('2026-11-26 10:00:00');

    $this->fake = fakeMetricsAdapter();
    $this->workspace = Workspace::factory()->create();
    $this->channel = channelIn($this->workspace);
});

/**
 * The ids of the variants of the test's channel that are due now.
 *
 * @return list<int>
 */
function dueNow(Channel $channel, int $limit = 100): array
{
    $workspace = Workspace::query()->findOrFail($channel->workspace_id);

    return inWorkspace($workspace, fn () => app(DueVariants::class)->for($channel, $limit)->modelKeys());
}

/**
 * A published variant on the test's channel that is this old and was last
 * asked about that long ago (never when null).
 */
function variantAged(string $age, ?string $checkedAgo = null): PostVariant
{
    $test = test();
    $variant = publishedVariant($test->workspace, $test->channel, now()->sub($age));

    if ($checkedAgo !== null) {
        DB::table('post_variants')->where('id', $variant->id)->update(['metrics_checked_at' => now()->sub($checkedAgo)]);
    }

    return $variant;
}

it('reads a post every hour during its first day', function () {
    $new = variantAged('10 minutes');
    $askedLately = variantAged('5 hours', '30 minutes');
    $askedAnHourAgo = variantAged('5 hours', '56 minutes');
    $almostADay = variantAged('23 hours 59 minutes', '61 minutes');

    expect(dueNow($this->channel))->toEqualCanonicalizing([$new->id, $askedAnHourAgo->id, $almostADay->id])
        ->not->toContain($askedLately->id);
});

it('reads a post once a day after its first day, and stops after three weeks', function () {
    $second = variantAged('30 hours', '2 hours');
    $secondDue = variantAged('30 hours', '24 hours');
    $lastDay = variantAged('20 days 23 hours', '2 days');
    $old = variantAged('21 days 1 hour', '5 days');
    $ancient = variantAged('90 days', '80 days');

    expect(dueNow($this->channel))->toEqualCanonicalizing([$secondDue->id, $lastDay->id])
        ->not->toContain($second->id, $old->id, $ancient->id);
});

it('reads a post that was never read once, when it is older than the cadence reaches and younger than a year', function () {
    $fresh = variantAged('2 hours');
    $lastMonth = variantAged('30 days');
    $lastSpring = variantAged('200 days');
    $overAYear = variantAged('366 days');
    $readBefore = variantAged('40 days', '39 days');

    // Inside the cadence first, then the never read ones, newest first.
    expect(dueNow($this->channel))->toBe([$fresh->id, $lastMonth->id, $lastSpring->id])
        ->not->toContain($overAYear->id, $readBefore->id)
        ->and(dueNow($this->channel, 2))->toBe([$fresh->id, $lastMonth->id]);

    // Asked once, with or without an answer: never again.
    $this->fake->reads($lastMonth, ['reactions' => 3]);
    runFetchMetrics($this->channel);

    expect($this->fake->lastMetricBatch())->toEqualCanonicalizing([$fresh->id, $lastMonth->id, $lastSpring->id])
        ->and(snapshotsOf($lastMonth))->toHaveCount(1)
        ->and(snapshotsOf($lastSpring))->toBe([]);

    $this->travel(3)->days();

    expect(dueNow($this->channel))->toBe([$fresh->id]);
});

it('follows the configured age for the one reading of old posts, and none when it is zero', function () {
    $lastMonth = variantAged('30 days');
    $lastSpring = variantAged('200 days');

    config(['noongun.insights.backfill_days' => 60]);
    expect(dueNow($this->channel))->toBe([$lastMonth->id]);

    config(['noongun.insights.backfill_days' => 0]);
    expect(dueNow($this->channel))->toBe([]);

    // Not longer back than the cadence reaches: nothing to add.
    config(['noongun.insights.backfill_days' => 21]);
    expect(dueNow($this->channel))->toBe([]);

    $this->artisan('noongun:poll-metrics')->expectsOutputToContain('Queued a reading for 0 channel(s).')->assertSuccessful();
});

it('keeps the one reading of old posts inside the share of the rate limit', function () {
    $this->fake->withRateLimit(new RateLimit(maxCalls: 20, perSeconds: 86400), callsPerVariant: 2);

    foreach (range(1, 5) as $i) {
        variantAged((30 + $i).' days');
    }

    // 20 percent of 20 calls is 4 calls, and a variant costs 2.
    runFetchMetrics($this->channel);
    runFetchMetrics($this->channel);

    $this->fake->assertMetricsFetchedTimes(1);
    expect($this->fake->lastMetricBatch())->toHaveCount(2);
});

it('never lists a variant that is not published, whatever the state of its post', function () {
    $published = variantAged('1 hour');

    foreach ([PostState::Draft, PostState::Approved, PostState::Scheduled, PostState::Publishing, PostState::Failed, PostState::Rejected] as $state) {
        postInState($this->workspace, $state, [$this->channel]);
    }

    expect(dueNow($this->channel))->toBe([$published->id]);
});

it('lists a published variant of a post that failed on another channel', function () {
    $other = channelIn($this->workspace);
    $post = postInState($this->workspace, PostState::Failed, [$this->channel, $other]);

    DB::table('post_variants')->where('post_id', $post->id)->where('channel_id', $this->channel->id)->update([
        'external_id' => 'ext_partial', 'published_at' => now()->subHour(), 'error' => null,
    ]);

    expect(dueNow($this->channel))->toHaveCount(1)
        ->and(dueNow($other))->toBe([]);
});

it('gives the variants that waited longest first, up to the limit', function () {
    $askedLongAgo = variantAged('20 hours', '10 hours');
    $never = variantAged('2 hours');
    $asked = variantAged('20 hours', '3 hours');

    expect(dueNow($this->channel, 2))->toBe([$never->id, $askedLongAgo->id])
        ->and(dueNow($this->channel))->toBe([$never->id, $askedLongAgo->id, $asked->id])
        ->and(dueNow($this->channel, 0))->toBe([]);
});

it('follows the configured cadence', function () {
    config(['noongun.insights.cadence' => ' 48:30 , 2:5 ', 'noongun.insights.backfill_days' => 0]);
    Log::spy();

    expect(InsightsConfig::cadence())->toBe([
        ['until_hours' => 2, 'every_minutes' => 5],
        ['until_hours' => 48, 'every_minutes' => 30],
    ])->and(InsightsConfig::stopAfterHours())->toBe(48);

    $young = variantAged('1 hour', '6 minutes');
    $older = variantAged('3 hours', '6 minutes');
    $tooOld = variantAged('49 hours');

    expect(dueNow($this->channel))->toBe([$young->id])
        ->not->toContain($older->id, $tooOld->id);

    Log::shouldNotHaveReceived('warning');
});

it('uses the whole default cadence when any pair cannot be read, and says so once', function (string $configured) {
    config(['noongun.insights.cadence' => $configured]);

    $logged = [];
    Log::listen(function ($message) use (&$logged) {
        $logged[] = $message->level.': '.$message->message;
    });

    $default = [
        ['until_hours' => 24, 'every_minutes' => 60],
        ['until_hours' => 504, 'every_minutes' => 1440],
    ];

    // Not "the pairs that could be read": 24:60,504:1440m would then mean
    // one day of readings instead of three weeks.
    expect(InsightsConfig::cadence())->toBe($default)
        ->and(InsightsConfig::cadence())->toBe($default)
        ->and(InsightsConfig::stopAfterHours())->toBe(504)
        ->and($logged)->toHaveCount(1)
        ->and($logged[0])->toStartWith('warning: NOONGUN_INSIGHTS_CADENCE cannot be read');
})->with([
    'a unit on a number' => ['24:60,504:1440m'],
    'a word' => ['never'],
    'a pair of zero' => ['24:60,0:10'],
    'a pair without minutes' => ['24:60,504'],
    'an empty pair' => ['24:60,,504:1440'],
]);

it('uses the default cadence without a word when none is configured', function () {
    config(['noongun.insights.cadence' => '']);
    Log::spy();

    expect(InsightsConfig::stopAfterHours())->toBe(504);

    Log::shouldNotHaveReceived('warning');
});

it('queues one job for each channel that has something due', function () {
    Queue::fake();

    variantAged('1 hour');
    variantAged('2 hours');

    $nothingDue = channelIn($this->workspace);
    $read = publishedVariant($this->workspace, $nothingDue, now()->subDays(60));
    DB::table('post_variants')->where('id', $read->id)->update(['metrics_checked_at' => now()->subDays(40)]);

    $otherWorkspace = Workspace::factory()->create();
    $otherChannel = channelIn($otherWorkspace);
    publishedVariant($otherWorkspace, $otherChannel, now()->subHour());

    $this->artisan('noongun:poll-metrics')
        ->expectsOutputToContain('Queued a reading for 2 channel(s).')
        ->assertSuccessful();

    Queue::assertPushed(FetchMetricsJob::class, 2);
    Queue::assertPushedOn('insights', FetchMetricsJob::class);
    Queue::assertPushed(FetchMetricsJob::class, fn (FetchMetricsJob $job) => $job->workspaceId === $this->workspace->id && $job->channelId === $this->channel->id);
    Queue::assertPushed(FetchMetricsJob::class, fn (FetchMetricsJob $job) => $job->workspaceId === $otherWorkspace->id && $job->channelId === $otherChannel->id);

    $this->fake->assertNoMetricsFetched();
});

it('queues nothing for a channel that cannot be read', function (Closure $arrange) {
    Queue::fake();
    variantAged('1 hour');

    $arrange($this);

    $this->artisan('noongun:poll-metrics')
        ->expectsOutputToContain('Queued a reading for 0 channel(s).')
        ->assertSuccessful();

    Queue::assertNothingPushed();
})->with([
    'needs a reconnect' => [fn ($test) => DB::table('channels')->where('id', $test->channel->id)->update(['status' => ChannelStatus::NeedsReconnect->value])],
    'disconnected' => [fn ($test) => inWorkspace($test->workspace, fn () => $test->channel->disconnect())],
    'token expired' => [fn ($test) => DB::table('channels')->where('id', $test->channel->id)->update(['token_expires_at' => now()->subDay()])],
    'paused' => [fn ($test) => ChannelPolling::pause($test->channel->id, 600)],
    'a platform without metrics' => [fn ($test) => $test->fake->givesMetrics([])],
    'an adapter that does not read metrics' => [fn ($test) => fakeChannelAdapter()],
    'a platform that is not configured' => [fn ($test) => DB::table('channels')->where('id', $test->channel->id)->update(['platform' => 'gone'])],
]);

it('does nothing when insights are turned off', function () {
    Queue::fake();
    config(['noongun.insights.enabled' => false]);
    variantAged('1 hour');

    $this->artisan('noongun:poll-metrics')->expectsOutputToContain('turned off')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('polls end to end: the command queues, the job reads, the numbers are stored', function () {
    $variant = variantAged('1 hour');
    $this->fake->readsUsing(fn (PostVariant $asked, int $call) => new MetricReading(reactions: 10 * $call, comments: 1));

    $this->artisan('noongun:poll-metrics')->assertSuccessful();
    $this->artisan('noongun:poll-metrics')->expectsOutputToContain('Queued a reading for 0 channel(s).')->assertSuccessful();

    $this->travel(70)->minutes();
    $this->artisan('noongun:poll-metrics')->assertSuccessful();

    expect(array_map(fn ($row) => [$row->reactions, $row->comments, $row->is_latest], snapshotsOf($variant)))
        ->toBe([[10, 1, false], [20, 1, true]])
        ->and($this->fake->metricsFetchCount())->toBe(2);
});

it('is scheduled every ten minutes without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'noongun:poll-metrics'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/10 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('asks nothing of LinkedIn, which gives no metrics yet', function () {
    Queue::fake();
    app(ChannelManager::class)->forgetAdapters();
    config(['noongun.channels.linkedin.client_id' => 'id', 'noongun.channels.linkedin.client_secret' => 'secret']);

    $profile = channelIn($this->workspace, ['platform' => 'linkedin']);
    publishedVariant($this->workspace, $profile, now()->subHour());

    $manager = app(ChannelManager::class);

    expect($manager->adapter('linkedin')->capabilities()->metrics)->toBe([])
        ->and($manager->metricsReader($profile))->toBeNull()
        ->and($manager->metricsReader('linkedin'))->toBeNull()
        ->and($manager->metricsReader('unknown'))->toBeNull();

    $this->artisan('noongun:poll-metrics')->expectsOutputToContain('Queued a reading for 0 channel(s).')->assertSuccessful();
    runFetchMetrics($profile);

    Queue::assertNothingPushed();
    expect(DB::table('metric_snapshots')->count())->toBe(0);
});

it('declares the metrics of Facebook, and which of them a Page without the insights permission can read', function () {
    $manager = app(ChannelManager::class);
    $manager->extend('facebook', fn (array $settings, $container) => $container->make(
        FacebookAdapter::class,
        ['settings' => [...$manager->settings('facebook'), 'app_id' => '1', 'app_secret' => 's']],
    ));

    $withInsights = channelIn($this->workspace, ['scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'read_insights']]);
    $reader = $manager->metricsReader($this->channel);

    expect($reader)->not->toBeNull()
        ->and($reader->capabilities()->metrics)->toBe(Metric::cases())
        ->and($reader->metricsFor($this->channel))->toBe([Metric::Reactions, Metric::Comments, Metric::Shares])
        ->and($reader->metricsFor($withInsights))->toBe(Metric::cases())
        ->and($reader->metricCallsPerVariant())->toBe(2);
});

it('queues one job for a channel, however often the command runs before a worker takes it', function () {
    Queue::fake();
    variantAged('1 hour');

    $this->artisan('noongun:poll-metrics')->assertSuccessful();
    $this->travel(10)->minutes();
    $this->artisan('noongun:poll-metrics')->assertSuccessful();
    $this->travel(40)->minutes();
    $this->artisan('noongun:poll-metrics')->assertSuccessful();

    Queue::assertPushed(FetchMetricsJob::class, 1);

    // A job that was lost does not keep the channel from being read for
    // longer than this.
    $this->travel(InsightsConfig::UNIQUE_SECONDS)->seconds();
    $this->artisan('noongun:poll-metrics')->assertSuccessful();

    Queue::assertPushed(FetchMetricsJob::class, 2);
});

it('lets a second job be queued once the first has run', function () {
    $variant = variantAged('1 hour');
    $this->fake->reads($variant, ['reactions' => 1]);

    dispatch(new FetchMetricsJob($this->workspace->id, $this->channel->id));

    Queue::fake();
    dispatch(new FetchMetricsJob($this->workspace->id, $this->channel->id));

    Queue::assertPushed(FetchMetricsJob::class, 1);
});

it('has a worker for the insights queue in every environment, apart from the workers that publish', function () {
    $plan = new ProvisioningPlan('test', config('horizon.environments'), config('horizon.defaults'));
    $environments = $plan->toSupervisorOptions();

    expect(array_keys($environments))->toEqualCanonicalizing(['production', 'local']);

    foreach ($environments as $environment => $supervisors) {
        $working = fn (string $queue) => collect($supervisors)
            ->filter(fn ($options) => in_array($queue, explode(',', $options->queue), true))
            ->values();

        $insights = $working(InsightsConfig::queue());
        $publishing = $working(PublishingConfig::queue());

        expect($insights)->toHaveCount(1, "no single supervisor for insights in {$environment}")
            ->and($publishing)->toHaveCount(1, "no single supervisor for publishing in {$environment}")
            ->and($insights[0]->name)->not->toBe($publishing[0]->name)
            // Each works its own queue only, so neither can keep the other waiting.
            ->and($insights[0]->queue)->toBe(InsightsConfig::queue())
            ->and($publishing[0]->queue)->toBe(PublishingConfig::queue())
            ->and($insights[0]->maxProcesses)->toBe(1)
            ->and($publishing[0]->maxProcesses)->toBeGreaterThanOrEqual(1)
            ->and($insights[0]->connection)->toBe($publishing[0]->connection)
            ->and($insights[0]->timeout)->toBeGreaterThan(InsightsConfig::jobSeconds())
            ->and($insights[0]->maxTries)->toBe(1);
    }
});
