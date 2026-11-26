<?php

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Insights\ChannelPolling;
use App\Domain\Insights\FetchMetricsJob;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../../Support/facebook.php';
require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';
require_once __DIR__.'/../../Support/insights.php';

/**
 * FetchMetricsJob with the real Facebook adapter and a faked Graph API:
 * what the two do together.
 */
const POLLED_PAGE_TOKEN = 'test-page-token-bo-kaap-bakery';

beforeEach(function () {
    $shipped = config('noongun.channels.facebook');

    $this->configure = fn (array $overrides = []) => configureFacebook([
        'insights_scope' => $shipped['insights_scope'],
        'rate_limit_calls_per_day' => $shipped['rate_limit_calls_per_day'],
        'metrics' => $shipped['metrics'],
        ...$overrides,
    ]);

    ($this->configure)();
    $this->travelTo('2026-11-26 10:00:00');

    $this->workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->channel = channelIn($this->workspace, [
        'external_id' => '100000000000001',
        'access_token' => POLLED_PAGE_TOKEN,
        'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'read_insights'],
    ]);

    $this->post = fn (string $postId, ?DateTimeInterface $publishedAt = null): PostVariant => publishedAs(
        publishedVariant($this->workspace, $this->channel, $publishedAt ?? now()->subHours(2)),
        $postId,
    );

    $this->variant = ($this->post)('100000000000001_900000000000001');

    /** @return list<string> the post ids that were asked about, over every request so far */
    $this->asked = fn (): array => array_values(array_unique(array_merge([], ...array_map(
        fn (Request $request): array => array_column(facebookBatchReads($request), 'post'),
        facebookBatchRequests(),
    ))));
});

it('stores the five numbers of a post on a Page with the insights permission', function () {
    fakeGraphMetrics();

    runFetchMetrics($this->channel);

    Http::assertSentCount(1);

    expect(snapshotsOf($this->variant))->toHaveCount(1)
        ->and(snapshotsOf($this->variant)[0]->reading()->toArray())
        ->toBe(['reactions' => 12, 'comments' => 3, 'shares' => 2, 'clicks' => 40, 'reach' => 900])
        ->and(metricsCheckedAt($this->variant))->toBe('2026-11-26 10:00:00');
});

it('stores three numbers and leaves reach and clicks empty on a Page without the permission', function () {
    DB::table('channels')->where('id', $this->channel->id)->update(['scopes' => json_encode(['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'])]);
    fakeGraphMetrics();

    runFetchMetrics($this->channel);

    expect(snapshotsOf($this->variant)[0]->reading()->toArray())
        ->toBe(['reactions' => 12, 'comments' => 3, 'shares' => 2, 'clicks' => null, 'reach' => null])
        ->and(array_column(facebookBatchReads(facebookBatchRequests()[0]), 'kind'))->toBe(['post']);
});

it('stores nulls, not zeros, for a Page with fewer than 100 likes', function () {
    fakeGraphMetrics(['*' => ['insights' => 'insights/post_insights_empty']]);

    runFetchMetrics($this->channel);

    expect(snapshotsOf($this->variant)[0]->reading()->toArray())
        ->toBe(['reactions' => 12, 'comments' => 3, 'shares' => 2, 'clicks' => null, 'reach' => null]);
});

it('does not replace numbers it has with nothing when the insights fail for a moment', function () {
    $failing = false;

    Http::fake(function (Request $request) use (&$failing) {
        return Http::response(array_map(fn (array $read) => facebookBatchEntry(match (true) {
            $read['kind'] === 'post' => 'insights/post_engagement',
            $failing && $read['post'] === '100000000000001_900000000000001' => ['error_service', 500],
            default => 'insights/post_insights',
        }, $read['post']), facebookBatchReads($request)));
    });

    runFetchMetrics($this->channel);

    $failing = true;
    $other = ($this->post)('100000000000001_900000000000002');
    $this->travel(61)->minutes();
    runFetchMetrics($this->channel);

    Http::assertSentCount(2);

    expect(snapshotsOf($this->variant))->toHaveCount(1)
        ->and(snapshotsOf($this->variant)[0]->reading()->toArray())
        ->toBe(['reactions' => 12, 'comments' => 3, 'shares' => 2, 'clicks' => 40, 'reach' => 900])
        ->and(snapshotsOf($this->variant)[0]->checked_at->toDateTimeString())->toBe('2026-11-26 10:00:00')
        ->and(snapshotsOf($other))->toHaveCount(1)
        ->and(ChannelPolling::isPaused($this->channel->id))->toBeFalse();
});

describe('a post that is gone', function () {
    it('is counted, and not asked about any more after three such answers in a row', function () {
        $kept = ($this->post)('100000000000001_900000000000002');
        fakeGraphMetrics(['100000000000001_900000000000001' => ['post' => 'insights/error_post_gone']]);

        foreach ([1, 2, 3] as $round) {
            runFetchMetrics($this->channel);

            expect(goneCount($this->variant))->toBe($round)
                ->and(goneCount($kept))->toBe(0)
                ->and(snapshotsOf($this->variant))->toBe([]);

            $this->travel(61)->minutes();
        }

        Http::assertSentCount(3);

        runFetchMetrics($this->channel);
        $this->travel(2)->days();
        runFetchMetrics($this->channel);

        $reads = array_map(fn (Request $request): array => array_column(facebookBatchReads($request), 'post'), facebookBatchRequests());

        // The fourth and fifth round ask about the other post only.
        expect($reads[3])->not->toContain('100000000000001_900000000000001')->toContain('100000000000001_900000000000002')
            ->and($reads[4])->not->toContain('100000000000001_900000000000001')
            ->and(goneCount($this->variant))->toBe(3)
            ->and(ChannelPolling::isPaused($this->channel->id))->toBeFalse()
            ->and(snapshotsOf($kept))->toHaveCount(1);
    });

    it('queues nothing for a channel whose only posts are gone', function () {
        DB::table('post_variants')->where('id', $this->variant->id)->update(['metrics_gone_count' => 3]);

        $this->artisan('noongun:poll-metrics')->expectsOutputToContain('Queued a reading for 0 channel(s).')->assertSuccessful();
    });

    it('follows the configured number of answers', function () {
        config(['noongun.insights.gone_after' => 1]);
        fakeGraphMetrics(['*' => ['post' => 'insights/error_post_gone']]);

        runFetchMetrics($this->channel);
        $this->travel(61)->minutes();
        runFetchMetrics($this->channel);

        Http::assertSentCount(1);
    });

    it('is asked about again as before once a reading arrives in between', function () {
        DB::table('post_variants')->where('id', $this->variant->id)->update(['metrics_gone_count' => 2]);
        fakeGraphMetrics();

        runFetchMetrics($this->channel);

        expect(goneCount($this->variant))->toBe(0)
            ->and(snapshotsOf($this->variant))->toHaveCount(1);
    });

    it('is asked about again after the Page was connected again', function () {
        DB::table('post_variants')->where('id', $this->variant->id)->update(['metrics_gone_count' => 3]);
        $otherWorkspace = Workspace::factory()->create();
        $foreign = publishedVariant($otherWorkspace, null, now()->subHours(2));
        DB::table('post_variants')->where('id', $foreign->id)->update(['metrics_gone_count' => 3]);

        inWorkspace($this->workspace, fn () => Channel::recordConnection('facebook', '100000000000001', [
            'display_name' => 'Bo-Kaap Bakery',
            'access_token' => 'test-page-token-after-reconnect',
        ]));

        expect(goneCount($this->variant))->toBe(0)
            ->and(goneCount($foreign))->toBe(3);
    });
});

describe('publishing comes first', function () {
    it('stays inside its share of the rate limit over several runs, and uses it again in the next window', function () {
        // 20 percent of 20 calls a day is 4 calls, and a post costs 2.
        ($this->configure)(['rate_limit_calls_per_day' => 20]);
        config(['noongun.insights.rate_limit_percent' => 20]);
        fakeGraphMetrics();

        foreach (range(2, 5) as $i) {
            ($this->post)("100000000000001_90000000000000{$i}");
        }

        runFetchMetrics($this->channel);

        expect(($this->asked)())->toHaveCount(2);

        foreach (range(1, 5) as $run) {
            $this->travel(61)->minutes();
            runFetchMetrics($this->channel);
        }

        Http::assertSentCount(1);
        expect(array_sum(array_map(fn (Request $request): int => count(facebookBatchReads($request)), facebookBatchRequests())))->toBe(4);

        $this->travel(19)->hours();
        runFetchMetrics($this->channel);

        Http::assertSentCount(2);
        expect(array_sum(array_map(fn (Request $request): int => count(facebookBatchReads($request)), facebookBatchRequests())))->toBe(8);
    });

    it('stops for the time Facebook asks when it limits requests, and reads again after it', function () {
        Http::fake(fn () => facebookResponse('error_rate_limit', 400, [
            'X-Business-Use-Case-Usage' => json_encode(['100000000000001' => [['type' => 'pages', 'call_count' => 100, 'estimated_time_to_regain_access' => 120]]]),
        ]));

        runFetchMetrics($this->channel);

        expect(ChannelPolling::isPaused($this->channel->id))->toBeTrue()
            ->and(metricsCheckedAt($this->variant))->toBeNull();

        // The configured pause is an hour; Facebook asked for two.
        $this->travel(110)->minutes();
        runFetchMetrics($this->channel);
        $this->artisan('noongun:poll-metrics')->expectsOutputToContain('Queued a reading for 0 channel(s).');

        Http::assertSentCount(1);

        $this->travel(11)->minutes();
        runFetchMetrics($this->channel);

        Http::assertSentCount(2);
    });

    it('keeps waiting for a rate limit when the Page is connected again in the meantime', function () {
        Http::fake(fn () => facebookResponse('error_rate_limit', 400));

        runFetchMetrics($this->channel);

        inWorkspace($this->workspace, fn () => Channel::recordConnection('facebook', '100000000000001', [
            'display_name' => 'Bo-Kaap Bakery',
            'access_token' => 'test-page-token-after-reconnect',
        ]));

        expect(ChannelPolling::isPaused($this->channel->id))->toBeTrue();
    });
});

describe('a token that is not accepted', function () {
    it('pauses reading and leaves the channel able to publish', function () {
        Http::fake(fn () => facebookResponse('error_token_expired', 400));

        runFetchMetrics($this->channel);
        runFetchMetrics($this->channel);

        Http::assertSentCount(1);

        $channel = inWorkspace($this->workspace, fn () => $this->channel->fresh());

        expect(ChannelPolling::isPaused($this->channel->id))->toBeTrue()
            ->and($channel->status)->toBe(ChannelStatus::Active)
            ->and($channel->canPublish())->toBeTrue()
            ->and($channel->access_token)->toBe(POLLED_PAGE_TOKEN)
            ->and(snapshotsOf($this->variant))->toBe([]);
    });

    it('reads again at once after the Page was connected again', function (Closure $reconnect) {
        Http::fake(fn () => facebookResponse('error_token_expired', 400));
        runFetchMetrics($this->channel);

        expect(ChannelPolling::isPaused($this->channel->id))->toBeTrue();

        $reconnect($this);

        expect(ChannelPolling::isPaused($this->channel->id))->toBeFalse();
    })->with([
        'a connected Page is renewed' => [fn ($test) => inWorkspace($test->workspace, fn () => Channel::recordConnection('facebook', '100000000000001', [
            'display_name' => 'Bo-Kaap Bakery',
            'access_token' => 'test-page-token-after-reconnect',
        ]))],
        'a Page that needed a reconnect is renewed' => [function ($test) {
            DB::table('channels')->where('id', $test->channel->id)->update(['status' => ChannelStatus::NeedsReconnect->value]);

            inWorkspace($test->workspace, fn () => Channel::recordConnection('facebook', '100000000000001', [
                'display_name' => 'Bo-Kaap Bakery',
                'access_token' => 'test-page-token-after-reconnect',
            ]));
        }],
        'a disconnected Page is connected and confirmed' => [function ($test) {
            inWorkspace($test->workspace, function () use ($test) {
                $test->channel->fresh()->disconnect();

                $pending = Channel::recordConnection('facebook', '100000000000001', [
                    'display_name' => 'Bo-Kaap Bakery',
                    'access_token' => 'test-page-token-after-reconnect',
                ]);

                // Not confirmed yet: nothing changes.
                expect(ChannelPolling::isPaused($test->channel->id))->toBeTrue()
                    ->and($pending->confirm())->toBeTrue();
            });
        }],
    ]);

    it('does not end the pause of another channel', function () {
        $other = channelIn($this->workspace, ['external_id' => '100000000000009', 'access_token' => 'test-page-token-of-a-sibling-page']);
        ChannelPolling::pauseUntilConnectedAgain($other->id, 600);

        inWorkspace($this->workspace, fn () => Channel::recordConnection('facebook', '100000000000001', [
            'display_name' => 'Bo-Kaap Bakery',
            'access_token' => 'test-page-token-after-reconnect',
        ]));

        expect(ChannelPolling::isPaused($other->id))->toBeTrue();
    });
});

describe('when it stops', function () {
    it('makes no call for a Page that was disconnected', function () {
        fakeGraphMetrics();
        inWorkspace($this->workspace, fn () => $this->channel->fresh()->disconnect());

        runFetchMetrics($this->channel);
        $this->artisan('noongun:poll-metrics')->expectsOutputToContain('Queued a reading for 0 channel(s).');

        Http::assertNothingSent();
    });

    it('makes no call for a post older than the last step of the cadence', function () {
        fakeGraphMetrics();
        DB::table('post_variants')->where('id', $this->variant->id)->update(['published_at' => now()->subHours(505)]);

        runFetchMetrics($this->channel);
        $this->artisan('noongun:poll-metrics')->expectsOutputToContain('Queued a reading for 0 channel(s).');

        Http::assertNothingSent();

        config(['noongun.insights.cadence' => '24:60,1000:1440']);
        runFetchMetrics($this->channel);

        Http::assertSentCount(1);
    });
});

describe('overlapping runs', function () {
    it('asks Facebook once when the job is dispatched twice for a channel', function () {
        fakeGraphMetrics();

        dispatch(new FetchMetricsJob($this->workspace->id, $this->channel->id));
        dispatch(new FetchMetricsJob($this->workspace->id, $this->channel->id));

        Http::assertSentCount(1);
        expect(snapshotsOf($this->variant))->toHaveCount(1);
    });

    it('asks Facebook nothing while another run holds the channel', function () {
        fakeGraphMetrics();
        $lock = ChannelPolling::lock($this->channel->id);
        $lock->get();

        runFetchMetrics($this->channel);

        Http::assertNothingSent();
        $lock->release();
    });
});

describe('credentials', function () {
    it('logs nothing that holds the token, whatever Facebook answers', function (Closure $fake) {
        $fake();

        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = json_encode([$message->level, $message->message, $message->context]);
        });

        runFetchMetrics($this->channel);

        expect($logged)->toHaveCount(1)
            ->and($logged[0])
            ->not->toContain(POLLED_PAGE_TOKEN)
            ->not->toContain(FACEBOOK_TEST_APP_SECRET)
            ->not->toContain(facebookProof(POLLED_PAGE_TOKEN));
    })->with([
        'an error that repeats the credentials' => [fn () => Http::fake(fn () => Http::response(pollingEcho(), 400))],
        'a refusal of the insights that repeats them' => [fn () => fakeGraphMetrics(['*' => ['insights' => [pollingEcho(), 400]]])],
        'an exception of the HTTP client that holds the address' => [fn () => Http::fake(
            fn (Request $request) => throw new RuntimeException("POST {$request->url()}?access_token=".POLLED_PAGE_TOKEN),
        )],
    ]);

    it('keeps the token out of the payload of the job', function () {
        expect(serialize(new FetchMetricsJob($this->workspace->id, $this->channel->id)))->not->toContain(POLLED_PAGE_TOKEN);
    });
});

describe('workspace isolation', function () {
    it('reads nothing when a job names the channel of another workspace', function () {
        fakeGraphMetrics();
        $otherWorkspace = Workspace::factory()->create();

        app()->call([new FetchMetricsJob($otherWorkspace->id, $this->channel->id), 'handle']);

        Http::assertNothingSent();
        expect(metricsCheckedAt($this->variant))->toBeNull();
    });

    it('asks only about the posts of its own workspace and Page, with the token of that Page', function () {
        fakeGraphMetrics();

        $otherWorkspace = Workspace::factory()->create();
        $otherChannel = channelIn($otherWorkspace, [
            'external_id' => '200000000000002',
            'access_token' => 'test-page-token-of-another-workspace',
        ]);
        $foreign = publishedAs(publishedVariant($otherWorkspace, $otherChannel, now()->subHours(2)), '200000000000002_900000000000001');

        runFetchMetrics($this->channel);

        expect(($this->asked)())->toBe(['100000000000001_900000000000001'])
            ->and(facebookBatchRequests()[0]['access_token'])->toBe(POLLED_PAGE_TOKEN)
            ->and(snapshotsOf($foreign))->toBe([])
            ->and(metricsCheckedAt($foreign))->toBeNull();
    });
});

/**
 * A Graph error whose message repeats the credentials of the request.
 *
 * @return array<string, mixed>
 */
function pollingEcho(): array
{
    return ['error' => [
        'message' => 'Malformed request to /?access_token='.POLLED_PAGE_TOKEN.'&appsecret_proof='.facebookProof(POLLED_PAGE_TOKEN)
            .' with secret '.FACEBOOK_TEST_APP_SECRET.' and token '.POLLED_PAGE_TOKEN,
        'type' => 'OAuthException',
        'code' => 100,
        'fbtrace_id' => 'AtestTracePollingEcho01',
    ]];
}
