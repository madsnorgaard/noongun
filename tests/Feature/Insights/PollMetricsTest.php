<?php

use App\Domain\Channels\Adapters\Facebook\FacebookAdapter;
use App\Domain\Channels\ChannelManager;
use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Contracts\Metric;
use App\Domain\Channels\Contracts\MetricReading;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostState;
use App\Domain\Insights\ChannelPolling;
use App\Domain\Insights\DueVariants;
use App\Domain\Insights\FetchMetricsJob;
use App\Domain\Insights\InsightsConfig;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

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
    $ancient = variantAged('90 days');

    expect(dueNow($this->channel))->toEqualCanonicalizing([$secondDue->id, $lastDay->id])
        ->not->toContain($second->id, $old->id, $ancient->id);
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

it('follows the configured cadence, and falls back to the default when it makes no sense', function () {
    config(['noongun.insights.cadence' => ' 48:30 , 2:5,nonsense,0:10,7:0']);

    expect(InsightsConfig::cadence())->toBe([
        ['until_hours' => 2, 'every_minutes' => 5],
        ['until_hours' => 48, 'every_minutes' => 30],
    ])->and(InsightsConfig::stopAfterHours())->toBe(48);

    $young = variantAged('1 hour', '6 minutes');
    $older = variantAged('3 hours', '6 minutes');
    $tooOld = variantAged('49 hours');

    expect(dueNow($this->channel))->toBe([$young->id])
        ->not->toContain($older->id, $tooOld->id);

    config(['noongun.insights.cadence' => 'never']);

    expect(InsightsConfig::cadence())->toBe([
        ['until_hours' => 24, 'every_minutes' => 60],
        ['until_hours' => 504, 'every_minutes' => 1440],
    ]);
});

it('queues one job for each channel that has something due', function () {
    Queue::fake();

    variantAged('1 hour');
    variantAged('2 hours');

    $nothingDue = channelIn($this->workspace);
    publishedVariant($this->workspace, $nothingDue, now()->subDays(60));

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

it('declares the metrics of Facebook and stores nothing until reading them is built', function () {
    $manager = app(ChannelManager::class);
    $manager->extend('facebook', fn (array $settings, $container) => $container->make(
        FacebookAdapter::class,
        ['settings' => [...$manager->settings('facebook'), 'app_id' => '1', 'app_secret' => 's']],
    ));

    $withInsights = channelIn($this->workspace, ['scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'read_insights']]);
    $variant = variantAged('1 hour');
    $reader = $manager->metricsReader($this->channel);

    expect($reader)->not->toBeNull()
        ->and($reader->capabilities()->metrics)->toBe(Metric::cases())
        ->and($reader->metricsFor($this->channel))->toBe([Metric::Reactions, Metric::Comments, Metric::Shares])
        ->and($reader->metricsFor($withInsights))->toBe(Metric::cases())
        ->and($reader->metricCallsPerVariant())->toBe(2);

    // No HTTP fake: a call would fail the test.
    runFetchMetrics($this->channel);

    expect(snapshotsOf($variant))->toBe([])
        ->and(ChannelPolling::isPaused($this->channel->id))->toBeFalse();
});
