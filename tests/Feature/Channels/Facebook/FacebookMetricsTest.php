<?php

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Contracts\Metric;
use App\Domain\Channels\Contracts\MetricReading;
use App\Domain\Channels\Exceptions\ChannelException;
use App\Domain\Channels\Exceptions\PublishFailed;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Workspaces\Models\Workspace;
use GuzzleHttp\Exception\NetworkTimeoutException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../../../Support/facebook.php';
require_once __DIR__.'/../../../Support/publishing.php';
require_once __DIR__.'/../../../Support/posts.php';
require_once __DIR__.'/../../../Support/insights.php';

const METRICS_PAGE_TOKEN = 'test-page-token-signal-hill-roasters';

const WITH_INSIGHTS = ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'read_insights'];

/**
 * The Facebook adapter with the metrics settings the application ships,
 * so the fixtures are read with the fields and names that are really used.
 *
 * @param  array<string, mixed>  $overrides
 */
function configureFacebookMetrics(array $overrides = []): void
{
    $shipped = config('noongun.channels.facebook');

    configureFacebook([
        'insights_scope' => $shipped['insights_scope'],
        'rate_limit_calls_per_day' => $shipped['rate_limit_calls_per_day'],
        'metrics' => $shipped['metrics'],
        ...$overrides,
    ]);
}

beforeEach(function () {
    $shipped = config('noongun.channels.facebook');
    $this->shippedFields = $shipped['metrics']['post_fields'];

    configureFacebookMetrics();
    $this->travelTo('2026-11-26 10:00:00');

    $this->workspace = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
    $this->channel = channelIn($this->workspace, [
        'external_id' => '100000000000001',
        'access_token' => METRICS_PAGE_TOKEN,
    ]);

    $this->post = fn (string $postId, ?Channel $channel = null): PostVariant => publishedAs(
        publishedVariant($this->workspace, $channel ?? $this->channel, now()->subHours(2)),
        $postId,
    );

    $this->grantInsights = function (): void {
        DB::table('channels')->where('id', $this->channel->id)->update(['scopes' => json_encode(WITH_INSIGHTS)]);
        $this->channel = inWorkspace($this->workspace, fn () => $this->channel->fresh());
    };

    /** @return array<int, array<string, int|null>> */
    $this->fetch = function (array $variants, ?Channel $channel = null): array {
        $channel ??= $this->channel;

        return array_map(
            fn (MetricReading $reading): array => $reading->gone ? ['gone' => true] : $reading->toArray(),
            inWorkspace($this->workspace, fn () => facebookAdapter()->fetchMetrics($channel, $variants)),
        );
    };

    $this->failure = function (array $variants): ChannelException {
        try {
            ($this->fetch)($variants);
        } catch (ChannelException $exception) {
            return $exception;
        }

        throw new RuntimeException('Reading the metrics did not fail.');
    };
});

describe('what is asked', function () {
    it('reads reactions, comments and shares of every post in one request when the Page has no insights permission', function () {
        fakeGraphMetrics();

        $first = ($this->post)('100000000000001_900000000000001');
        $second = ($this->post)('100000000000001_900000000000002');

        $readings = ($this->fetch)([$first, $second]);

        expect($readings)->toBe([
            $first->id => ['reactions' => 12, 'comments' => 3, 'shares' => 2, 'clicks' => null, 'reach' => null],
            $second->id => ['reactions' => 12, 'comments' => 3, 'shares' => 2, 'clicks' => null, 'reach' => null],
        ]);

        Http::assertSentCount(1);

        $request = facebookBatchRequests()[0];

        expect($request->url())->toBe(FACEBOOK_TEST_GRAPH.'/')
            ->and($request['access_token'])->toBe(METRICS_PAGE_TOKEN)
            ->and($request['appsecret_proof'])->toBe(facebookProof(METRICS_PAGE_TOKEN))
            ->and(facebookBatchReads($request))->toBe([
                ['post' => '100000000000001_900000000000001', 'kind' => 'post', 'query' => ['fields' => $this->shippedFields]],
                ['post' => '100000000000001_900000000000002', 'kind' => 'post', 'query' => ['fields' => $this->shippedFields]],
            ]);
    });

    it('also reads clicks and reach from the insights of each post when the Page has the permission', function () {
        fakeGraphMetrics();
        ($this->grantInsights)();

        $variant = ($this->post)('100000000000001_900000000000001');

        expect(($this->fetch)([$variant]))->toBe([
            $variant->id => ['reactions' => 12, 'comments' => 3, 'shares' => 2, 'clicks' => 40, 'reach' => 900],
        ]);

        Http::assertSentCount(1);

        expect(facebookBatchReads(facebookBatchRequests()[0]))->toBe([
            ['post' => '100000000000001_900000000000001', 'kind' => 'post', 'query' => ['fields' => $this->shippedFields]],
            ['post' => '100000000000001_900000000000001', 'kind' => 'insights', 'query' => [
                'metric' => 'post_clicks,post_total_media_view_unique',
                'period' => 'lifetime',
            ]],
        ]);
    });

    it('asks for totals only, never for the people behind them, and counts comments with their replies', function () {
        expect($this->shippedFields)
            ->toContain('reactions.summary(total_count).limit(0)')
            ->toContain('comments.filter(stream).summary(true).limit(0)')
            ->toContain('shares');
    });

    it('takes the fields, the metric names and the period from the settings', function () {
        configureFacebookMetrics(['metrics' => [
            'post_fields' => 'reactions.summary(total_count).limit(0),shares',
            'clicks' => 'post_clicks_renamed',
            'reach' => 'post_viewers_renamed',
            'period' => 'total_over_range',
            'batch_size' => 50,
        ]]);
        ($this->grantInsights)();

        fakeGraphMetrics(['*' => ['insights' => ['data' => [
            ['name' => 'post_clicks_renamed', 'period' => 'total_over_range', 'values' => [['value' => 7]]],
            ['name' => 'post_viewers_renamed', 'period' => 'total_over_range', 'values' => [['value' => 70]]],
            ['name' => 'post_clicks', 'period' => 'total_over_range', 'values' => [['value' => 999]]],
        ]]]]);

        $variant = ($this->post)('100000000000001_900000000000001');

        expect(($this->fetch)([$variant])[$variant->id])
            ->toBe(['reactions' => 12, 'comments' => 3, 'shares' => 2, 'clicks' => 7, 'reach' => 70])
            ->and(array_column(facebookBatchReads(facebookBatchRequests()[0]), 'query'))->toBe([
                ['fields' => 'reactions.summary(total_count).limit(0),shares'],
                ['metric' => 'post_clicks_renamed,post_viewers_renamed', 'period' => 'total_over_range'],
            ]);
    });

    it('does not ask for a metric whose name is empty in the settings', function () {
        $metrics = config('noongun.channels.facebook.metrics');
        configureFacebookMetrics(['metrics' => [...$metrics, 'reach' => '']]);
        ($this->grantInsights)();
        fakeGraphMetrics();

        $variant = ($this->post)('100000000000001_900000000000001');

        expect(($this->fetch)([$variant])[$variant->id])
            ->toBe(['reactions' => 12, 'comments' => 3, 'shares' => 2, 'clicks' => 40, 'reach' => null])
            ->and(facebookBatchReads(facebookBatchRequests()[0])[1]['query']['metric'])->toBe('post_clicks');
    });

    it('never sends more reads for a variant than it says a variant costs', function (bool $insights) {
        fakeGraphMetrics();

        if ($insights) {
            ($this->grantInsights)();
        }

        $variants = [($this->post)('100000000000001_900000000000001'), ($this->post)('100000000000001_900000000000002')];
        ($this->fetch)($variants);

        $reads = array_sum(array_map(fn (Request $request): int => count(facebookBatchReads($request)), facebookBatchRequests()));

        expect($reads)->toBe($insights ? 4 : 2)
            ->and($reads)->toBeLessThanOrEqual(count($variants) * facebookAdapter()->metricCallsPerVariant());
    })->with(['without the insights permission' => [false], 'with it' => [true]]);

    it('splits many posts over batches of at most the configured size, both reads of a post in the same one', function () {
        $metrics = config('noongun.channels.facebook.metrics');
        configureFacebookMetrics(['metrics' => [...$metrics, 'batch_size' => 4]]);
        ($this->grantInsights)();
        fakeGraphMetrics();

        $variants = array_map(fn (int $i): PostVariant => ($this->post)("100000000000001_90000000000000{$i}"), range(1, 5));

        expect(($this->fetch)($variants))->toHaveCount(5);

        $sizes = array_map(fn (Request $request): array => array_column(facebookBatchReads($request), 'post'), facebookBatchRequests());

        expect($sizes)->toBe([
            ['100000000000001_900000000000001', '100000000000001_900000000000001', '100000000000001_900000000000002', '100000000000001_900000000000002'],
            ['100000000000001_900000000000003', '100000000000001_900000000000003', '100000000000001_900000000000004', '100000000000001_900000000000004'],
            ['100000000000001_900000000000005', '100000000000001_900000000000005'],
        ]);
    });

    it('never puts more than 50 reads in a batch, whatever the settings say', function () {
        $metrics = config('noongun.channels.facebook.metrics');
        configureFacebookMetrics(['metrics' => [...$metrics, 'batch_size' => 5000]]);
        fakeGraphMetrics();

        $variants = array_map(fn (int $i): PostVariant => ($this->post)('100000000000001_'.(900000000000000 + $i)), range(1, 51));
        ($this->fetch)($variants);

        expect(array_map(fn (Request $request): int => count(facebookBatchReads($request)), facebookBatchRequests()))->toBe([50, 1]);
    });

    it('sends the token in the form body of a request that follows no redirect, and requests no paging address', function () {
        ($this->grantInsights)();
        $options = [];

        Http::fake(function (Request $request, array $sent) use (&$options) {
            $options[] = $sent;

            // The insights fixture holds paging addresses with a token in them.
            return Http::response(array_map(
                fn (array $read) => facebookBatchEntry($read['kind'] === 'post' ? 'insights/post_engagement' : 'insights/post_insights'),
                facebookBatchReads($request),
            ));
        });

        ($this->fetch)([($this->post)('100000000000001_900000000000001')]);

        Http::assertSentCount(1);

        $request = facebookBatchRequests()[0];

        expect($options[0]['allow_redirects'])->toBeFalse()
            ->and($request->method())->toBe('POST')
            ->and($request->url())->toBe(FACEBOOK_TEST_GRAPH.'/')->not->toContain(METRICS_PAGE_TOKEN)
            ->and($request->isForm())->toBeTrue()
            ->and((string) $request['batch'])->not->toContain(METRICS_PAGE_TOKEN)->not->toContain('access_token');
    });
});

describe('what an answer means', function () {
    it('counts shares as zero when Facebook leaves the field out for a post that was read', function () {
        fakeGraphMetrics(['*' => ['post' => 'insights/post_engagement_no_shares']]);

        $variant = ($this->post)('100000000000001_900000000000002');

        expect(($this->fetch)([$variant])[$variant->id])
            ->toBe(['reactions' => 0, 'comments' => 0, 'shares' => 0, 'clicks' => null, 'reach' => null]);
    });

    it('does not invent a zero for shares when the field was not asked for', function () {
        $metrics = config('noongun.channels.facebook.metrics');
        configureFacebookMetrics(['metrics' => [...$metrics, 'post_fields' => 'reactions.summary(total_count).limit(0)']]);
        fakeGraphMetrics(['*' => ['post' => 'insights/post_engagement_no_shares']]);

        $variant = ($this->post)('100000000000001_900000000000002');

        expect(($this->fetch)([$variant])[$variant->id]['shares'])->toBeNull();
    });

    it('drops a number that is not a whole number from zero up, and keeps the rest', function () {
        ($this->grantInsights)();
        fakeGraphMetrics(['*' => ['post' => 'insights/post_engagement_malformed', 'insights' => 'insights/post_insights_malformed']]);

        $variant = ($this->post)('100000000000001_900000000000003');

        // Reactions -4, comments "7", shares 2.5; clicks as a breakdown,
        // reach without a value, and entries for another period and metric.
        expect(($this->fetch)([$variant])[$variant->id])
            ->toBe(['reactions' => null, 'comments' => 7, 'shares' => null, 'clicks' => null, 'reach' => null]);
    });

    it('reports a post that Facebook no longer has as gone, and keeps the readings of the others', function (string|array $answer) {
        fakeGraphMetrics(['100000000000001_900000000000009' => ['post' => $answer]]);

        $kept = ($this->post)('100000000000001_900000000000001');
        $deleted = ($this->post)('100000000000001_900000000000009');

        expect(($this->fetch)([$kept, $deleted]))->toBe([
            $kept->id => ['reactions' => 12, 'comments' => 3, 'shares' => 2, 'clicks' => null, 'reach' => null],
            $deleted->id => ['gone' => true],
        ]);
    })->with([
        'error 100 with subcode 33' => ['insights/error_post_gone'],
        'error 803' => [[['error' => ['message' => '(#803) Some of the aliases you requested do not exist: 100000000000001_900000000000009', 'type' => 'OAuthException', 'code' => 803]], 404]],
        'HTTP 404 without a readable body' => [[[], 404]],
    ]);

    it('leaves reach and clicks empty and keeps the rest when the insights are not given', function (string|array $answer, bool $warns) {
        ($this->grantInsights)();
        fakeGraphMetrics(['*' => ['insights' => $answer]]);
        Log::spy();

        $variant = ($this->post)('100000000000001_900000000000001');

        expect(($this->fetch)([$variant])[$variant->id])
            ->toBe(['reactions' => 12, 'comments' => 3, 'shares' => 2, 'clicks' => null, 'reach' => null]);

        $warns ? Log::shouldHaveReceived('warning')->once() : Log::shouldNotHaveReceived('warning');
    })->with([
        'the permission is missing after all' => ['insights/error_insights_permission', false],
        'an empty list, as for a Page with fewer than 100 likes' => ['insights/post_insights_empty', false],
        'a metric name Meta has retired' => ['insights/error_invalid_metric', true],
        'a post Facebook has no insights for' => ['insights/error_post_gone', false],
    ]);

    it('keeps what arrived of a batch in which one post is gone and one read was not finished', function () {
        ($this->grantInsights)();
        Http::fake(fn () => facebookResponse('insights/batch_mixed'));

        $read = ($this->post)('100000000000001_900000000000001');
        $deleted = ($this->post)('100000000000001_900000000000002');
        $unfinished = ($this->post)('100000000000001_900000000000003');

        expect(($this->fetch)([$read, $deleted, $unfinished]))->toBe([
            $read->id => ['reactions' => 12, 'comments' => 3, 'shares' => 2, 'clicks' => 40, 'reach' => 900],
            $deleted->id => ['gone' => true],
        ]);
    });

    it('gives no reading for a post whose read was not finished or failed for a moment', function (string|array $answer) {
        fakeGraphMetrics(['100000000000001_900000000000002' => ['post' => $answer]]);

        $read = ($this->post)('100000000000001_900000000000001');
        $failed = ($this->post)('100000000000001_900000000000002');

        expect(array_keys(($this->fetch)([$read, $failed])))->toBe([$read->id]);
    })->with([
        'not finished' => [FACEBOOK_UNFINISHED],
        'a service error' => [['error_service', 500]],
        'an answer that is not a post' => [[['unexpected' => true], 200]],
        'a refusal of this one read' => ['error_validation'],
    ]);

    it('gives no reading at all, instead of one without reach and clicks, when the insights failed for a moment', function (string|array $answer) {
        ($this->grantInsights)();
        fakeGraphMetrics(['100000000000001_900000000000002' => ['insights' => $answer]]);

        $read = ($this->post)('100000000000001_900000000000001');
        $failed = ($this->post)('100000000000001_900000000000002');

        expect(($this->fetch)([$read, $failed]))->toBe([
            $read->id => ['reactions' => 12, 'comments' => 3, 'shares' => 2, 'clicks' => 40, 'reach' => 900],
        ]);
    })->with([
        'not finished' => [FACEBOOK_UNFINISHED],
        'a service error' => [['error_service', 500]],
    ]);

    it('reports an id that Facebook cannot have given as gone, without sending it', function (string $id) {
        fakeGraphMetrics();

        $odd = ($this->post)($id);
        $fine = ($this->post)('100000000000001_900000000000001');

        expect(($this->fetch)([$odd, $fine])[$odd->id])->toBe(['gone' => true])
            ->and(array_column(facebookBatchReads(facebookBatchRequests()[0]), 'post'))->toBe(['100000000000001_900000000000001']);
    })->with([
        'a query' => ['100000000000001_9?fields=access_token'],
        'a path' => ['../me/accounts'],
        'an address' => ['https://elsewhere.example/1_2'],
        'text' => ['ext_12'],
    ]);

    it('makes no request when there is nothing it can ask about', function () {
        fakeGraphMetrics();

        expect(($this->fetch)([]))->toBe([])
            ->and(($this->fetch)([($this->post)('not-an-id')]))->toHaveCount(1);

        Http::assertNothingSent();
    });
});

describe('failures of the whole call', function () {
    it('fails as a temporary problem when nothing came back', function (string|array $answer) {
        fakeGraphMetrics(['*' => ['post' => $answer]]);

        $exception = ($this->failure)([($this->post)('100000000000001_900000000000001'), ($this->post)('100000000000001_900000000000002')]);

        expect($exception->retryable)->toBeTrue()
            ->and($exception->requiresReconnect)->toBeFalse();
    })->with([
        'no read was finished' => [FACEBOOK_UNFINISHED],
        'every read met a service error' => [['error_service', 500]],
    ]);

    it('fails as a refusal when every read was refused for another reason', function () {
        fakeGraphMetrics(['*' => ['post' => 'error_validation']]);

        $exception = ($this->failure)([($this->post)('100000000000001_900000000000001')]);

        expect($exception->retryable)->toBeFalse()
            ->and($exception->requiresReconnect)->toBeFalse()
            ->and($exception->context()['code'])->toBe(100);
    });

    it('asks for a new connection when the token is refused, also when other reads were answered', function (Closure $fake) {
        ($this->grantInsights)();
        $fake();

        $exception = ($this->failure)([($this->post)('100000000000001_900000000000001'), ($this->post)('100000000000001_900000000000002')]);

        expect($exception->requiresReconnect)->toBeTrue()
            ->and($exception->retryable)->toBeFalse()
            ->and($exception->context()['code'])->toBe(190)
            // The adapter only reads: the channel is as it was.
            ->and(DB::table('channels')->where('id', $this->channel->id)->value('status'))->toBe(ChannelStatus::Active->value);
    })->with([
        'for the batch' => [fn () => Http::fake(fn () => facebookResponse('error_token_expired', 400))],
        'for one post' => [fn () => fakeGraphMetrics(['100000000000001_900000000000002' => ['post' => 'error_token_expired']])],
        'for the insights of one post' => [fn () => fakeGraphMetrics(['100000000000001_900000000000002' => ['insights' => 'error_token_expired']])],
    ]);

    it('asks for a new connection when the permission to read the posts themselves is missing', function () {
        fakeGraphMetrics(['*' => ['post' => 'error_permission']]);

        expect(($this->failure)([($this->post)('100000000000001_900000000000001')])->requiresReconnect)->toBeTrue();
    });

    it('says how long to wait when Facebook is limiting requests', function (Closure $fake, ?int $seconds) {
        ($this->grantInsights)();
        $fake();

        $exception = ($this->failure)([($this->post)('100000000000001_900000000000001'), ($this->post)('100000000000001_900000000000002')]);

        expect($exception->retryable)->toBeTrue()
            ->and($exception->requiresReconnect)->toBeFalse()
            ->and($exception->retryAfterSeconds)->toBe($seconds)
            ->and($exception->context()['code'])->toBe(80001);
    })->with([
        'the batch, with the wait in the usage header' => [
            fn () => Http::fake(fn () => facebookResponse('error_rate_limit', 400, ['X-Business-Use-Case-Usage' => facebookUsage(19)])),
            1140,
        ],
        'the batch, without a wait' => [fn () => Http::fake(fn () => facebookResponse('error_rate_limit', 400)), null],
        'one read, with the wait in its own headers' => [
            fn () => fakeGraphMetrics(['100000000000001_900000000000002' => ['post' => ['error_rate_limit', 400, ['X-Business-Use-Case-Usage' => facebookUsage(7)]]]]),
            420,
        ],
        'one read, with the wait in the headers of the batch' => [
            fn () => fakeGraphMetrics(['100000000000001_900000000000002' => ['insights' => 'error_rate_limit']], ['x-business-use-case-usage' => facebookUsage(30)]),
            1800,
        ],
        'one read, with Retry-After' => [
            fn () => fakeGraphMetrics(['100000000000001_900000000000001' => ['post' => ['error_rate_limit', 400, ['Retry-After' => '90']]]]),
            90,
        ],
    ]);

    it('treats every failure that says nothing about the posts as temporary, because reading is safe to repeat', function (Closure $respond) {
        Http::fake($respond);

        $exception = ($this->failure)([($this->post)('100000000000001_900000000000001')]);

        expect($exception->retryable)->toBeTrue()
            ->and($exception->requiresReconnect)->toBeFalse()
            ->and($exception->getMessage())->not->toContain('test-page-token')->not->toContain('graph.facebook');
    })->with(fn () => facebookAmbiguousFailures());

    it('fails without a retry when the batch answers with something that is not a list of answers', function () {
        Http::fake(fn () => Http::response(['data' => []]));

        $exception = ($this->failure)([($this->post)('100000000000001_900000000000001')]);

        expect($exception->retryable)->toBeFalse()
            ->and($exception->getMessage())->toContain('could not be read');
    });

    it('does not follow a redirect', function () {
        Http::fake(fn () => Http::response('', 302, ['Location' => 'https://elsewhere.example/v99.0/']));

        ($this->failure)([($this->post)('100000000000001_900000000000001')]);

        Http::assertSentCount(1);
    });

    it('makes no call for a Page without a token, or on an instance without the app settings', function (Closure $arrange) {
        fakeGraphMetrics();
        $variant = ($this->post)('100000000000001_900000000000001');
        $arrange($this);

        ($this->failure)([$variant]);

        Http::assertNothingSent();
    })->with([
        'no token' => [function ($test) {
            DB::table('channels')->where('id', $test->channel->id)->update(['access_token' => null]);
            $test->channel = inWorkspace($test->workspace, fn () => $test->channel->fresh());
        }],
        'no app secret' => [fn () => configureFacebookMetrics(['app_secret' => ''])],
    ]);
});

describe('credentials', function () {
    it('never lets the token, the proof or the app secret into an exception', function (Closure $fake) {
        ($this->grantInsights)();
        $fake();

        $exception = ($this->failure)([($this->post)('100000000000001_900000000000001')]);
        $text = $exception->getMessage().json_encode($exception->context()).$exception->getTraceAsString();

        expect($text)
            ->not->toContain(METRICS_PAGE_TOKEN)
            ->not->toContain(FACEBOOK_TEST_APP_SECRET)
            ->not->toContain(facebookProof(METRICS_PAGE_TOKEN))
            ->and($exception->getPrevious())->toBeNull();
    })->with([
        'an error of the batch that repeats them' => [fn () => Http::fake(fn () => Http::response(metricsEcho(), 400))],
        'an error of one read that repeats them' => [fn () => fakeGraphMetrics(['*' => ['post' => [metricsEcho(), 400]]])],
        'a rate limit of one read that repeats them' => [fn () => fakeGraphMetrics(['*' => ['insights' => [metricsEcho(80001), 400]]])],
        'a network failure whose message holds the address' => [fn () => Http::fake(
            fn (Request $request) => throw facebookConnectionFailure($request, NetworkTimeoutException::class, 'Operation timed out for token '.METRICS_PAGE_TOKEN),
        )],
    ]);

    it('logs a refusal of the insights without the token', function () {
        ($this->grantInsights)();
        fakeGraphMetrics(['*' => ['insights' => [metricsEcho(), 400]]]);

        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = json_encode([$message->level, $message->message, $message->context]);
        });

        ($this->fetch)([($this->post)('100000000000001_900000000000001')]);

        expect($logged)->toHaveCount(1)
            ->and($logged[0])->toContain('warning')->toContain((string) $this->channel->id)
            ->not->toContain(METRICS_PAGE_TOKEN)
            ->not->toContain(FACEBOOK_TEST_APP_SECRET)
            ->not->toContain(facebookProof(METRICS_PAGE_TOKEN));
    });
});

describe('workspace isolation', function () {
    it('never asks about a variant of another workspace or another channel, even when it is handed one', function () {
        fakeGraphMetrics();

        $otherWorkspace = Workspace::factory()->create();
        $otherChannel = channelIn($otherWorkspace, ['external_id' => '200000000000002', 'access_token' => 'test-page-token-of-another-workspace']);
        $foreign = publishedAs(publishedVariant($otherWorkspace, $otherChannel, now()->subHours(2)), '200000000000002_900000000000001');

        $sibling = channelIn($this->workspace, ['external_id' => '100000000000003', 'access_token' => 'test-page-token-of-a-sibling-page']);
        $onSibling = ($this->post)('100000000000003_900000000000001', $sibling);

        $mine = ($this->post)('100000000000001_900000000000001');

        expect(array_keys(($this->fetch)([$foreign, $mine, $onSibling])))->toBe([$mine->id]);

        $request = facebookBatchRequests()[0];

        expect(array_column(facebookBatchReads($request), 'post'))->toBe(['100000000000001_900000000000001'])
            ->and($request['access_token'])->toBe(METRICS_PAGE_TOKEN);
    });

    it('makes no request when every variant it is handed belongs elsewhere', function () {
        fakeGraphMetrics();

        $otherWorkspace = Workspace::factory()->create();
        $foreign = publishedAs(publishedVariant($otherWorkspace, null, now()->subHours(2)), '200000000000002_900000000000001');

        expect(($this->fetch)([$foreign]))->toBe([]);

        Http::assertNothingSent();
    });
});

describe('the insights permission is optional', function () {
    beforeEach(function () {
        configureFacebookMetrics(['scopes' => WITH_INSIGHTS]);
    });

    it('says which metrics a Page can read from the permissions it was granted', function () {
        $adapter = facebookAdapter();

        expect($adapter->metricsFor($this->channel))->toBe([Metric::Reactions, Metric::Comments, Metric::Shares]);

        ($this->grantInsights)();

        expect($adapter->metricsFor($this->channel))->toBe(Metric::cases())
            ->and($adapter->capabilities()->metrics)->toBe(Metric::cases());
    });

    it('asks for it in the sign-in when it is configured', function () {
        expect(facebookAdapter()->authorizationUrl($this->workspace, 'state'))->toContain('read_insights');
    });

    it('connects a Page that was not granted it, without reach and clicks', function () {
        fakeGraph();

        $channel = inWorkspace($this->workspace, fn () => facebookAdapter()->connect($this->workspace, 'test-auth-code'))->first();

        expect($channel->hasScope('read_insights'))->toBeFalse()
            ->and($channel->hasScope('pages_manage_posts'))->toBeTrue()
            ->and(facebookAdapter()->metricsFor($channel))->toBe([Metric::Reactions, Metric::Comments, Metric::Shares]);
    });

    it('still refuses to connect a Page without a permission that publishing needs', function () {
        fakeGraph(['debug' => 'debug_token_missing_scope']);

        expect(fn () => inWorkspace($this->workspace, fn () => facebookAdapter()->connect($this->workspace, 'test-auth-code')))
            ->toThrow(ChannelException::class);
    });

    it('keeps a Page publishing when the token check finds the permission missing', function () {
        ($this->grantInsights)();
        fakeGraph();

        $checked = inWorkspace($this->workspace, fn () => facebookAdapter()->refreshToken($this->channel));

        expect($checked->status)->toBe(ChannelStatus::Active)
            ->and($checked->hasScope('read_insights'))->toBeFalse()
            ->and(facebookAdapter()->metricsFor($checked))->toBe([Metric::Reactions, Metric::Comments, Metric::Shares]);
    });
});

describe('one post that may not be read', function () {
    it('gets no reading and does not fail the call for the others', function (string|array $fixture) {
        fakeGraphMetrics(['100000000000001_900000000000002' => ['post' => $fixture]]);

        $read = ($this->post)('100000000000001_900000000000001');
        $refused = ($this->post)('100000000000001_900000000000002');

        expect(($this->fetch)([$read, $refused]))->toBe([
            $read->id => ['reactions' => 12, 'comments' => 3, 'shares' => 2, 'clicks' => null, 'reach' => null],
        ]);
    })->with([
        'error 200' => ['error_permission'],
        'error 10' => [[['error' => ['message' => '(#10) This endpoint requires the pages_read_engagement permission', 'type' => 'OAuthException', 'code' => 10]], 400]],
    ]);

    it('fails the call as a missing permission only when every post was refused that way', function () {
        fakeGraphMetrics([
            '100000000000001_900000000000001' => ['post' => 'error_permission'],
            '100000000000001_900000000000002' => ['post' => 'error_permission'],
            '100000000000001_900000000000003' => ['post' => FACEBOOK_UNFINISHED],
        ]);

        $posts = [($this->post)('100000000000001_900000000000001'), ($this->post)('100000000000001_900000000000002')];

        expect(($this->failure)($posts)->requiresReconnect)->toBeTrue();

        // One of three was only not finished: that is not the Page.
        $exception = ($this->failure)([...$posts, ($this->post)('100000000000001_900000000000003')]);

        expect($exception->requiresReconnect)->toBeFalse()
            ->and($exception->retryable)->toBeTrue();
    });
});

describe('text from Facebook', function () {
    it('is cut to a length that fits a log line, in the message and in the context', function (Closure $fake) {
        $fake(['error' => [
            'message' => str_repeat('Long words from a platform. ', 400),
            'type' => str_repeat('T', 5000),
            'code' => 100,
            'fbtrace_id' => str_repeat('A', 5000),
        ]]);

        $exception = ($this->failure)([($this->post)('100000000000001_900000000000001')]);

        expect(mb_strlen($exception->getMessage()))->toBeLessThan(400)
            ->and($exception->getMessage())->toContain('Long words from a platform.')
            ->and(mb_strlen((string) $exception->context()['type']))->toBeLessThan(70)
            ->and(mb_strlen((string) $exception->context()['fbtrace_id']))->toBeLessThan(70);
    })->with([
        'an error of the batch' => [fn (array $error) => Http::fake(fn () => Http::response($error, 400))],
        'an error of one read' => [fn (array $error) => fakeGraphMetrics(['*' => ['post' => [$error, 400]]])],
    ]);

    it('is logged once a day for each Page when the insights are refused, not at every reading', function () {
        ($this->grantInsights)();
        fakeGraphMetrics(['*' => ['insights' => 'insights/error_invalid_metric']]);

        $logged = 0;
        Log::listen(function () use (&$logged) {
            $logged++;
        });

        $variant = ($this->post)('100000000000001_900000000000001');

        ($this->fetch)([$variant]);
        ($this->fetch)([$variant]);
        $this->travel(23)->hours();
        ($this->fetch)([$variant]);

        expect($logged)->toBe(1);

        $this->travel(2)->hours();
        ($this->fetch)([$variant]);

        expect($logged)->toBe(2);
    });
});

describe('the id of a post', function () {
    it('is never sent with a line break at its end', function (string $id) {
        fakeGraphMetrics();

        $odd = ($this->post)($id);

        expect(($this->fetch)([$odd]))->toBe([$odd->id => ['gone' => true]]);

        Http::assertNothingSent();
    })->with([
        'a trailing line break' => ["100000000000001_900000000000001\n"],
        'a line break and more' => ["100000000000001_900000000000001\n/insights"],
        'a leading line break' => ["\n100000000000001_900000000000001"],
    ]);

    it('is not stored by publish when Facebook answers with something that is not a post id', function (mixed $id) {
        fakeGraph(['feed' => Http::response(['id' => $id])]);

        $variant = inWorkspace($this->workspace, fn () => PostVariant::factory()->for($this->channel)->create(['body' => 'Fresh beans arrive on Thursday.']));

        expect(fn () => inWorkspace($this->workspace, fn () => facebookAdapter()->publish($variant)))->toThrow(
            fn (PublishFailed $exception) => expect($exception->retryable)->toBeFalse()
                // The post may be public: this is not a failure to send again.
                ->and($exception->context()['outcome_unknown'])->toBeTrue(),
        );
    })->with([
        'a trailing line break' => ["100000000000001_900000000000001\n"],
        'a path' => ['100000000000001_900000000000001/insights'],
        'a query' => ['100000000000001?fields=access_token'],
        'text' => ['post-1'],
        'empty' => [''],
        'a number that is not text' => [900000000000001],
    ]);

    it('still stores the id Facebook really gives', function () {
        fakeGraph();

        $variant = inWorkspace($this->workspace, fn () => PostVariant::factory()->for($this->channel)->create(['body' => 'Fresh beans arrive on Thursday.']));

        expect(inWorkspace($this->workspace, fn () => facebookAdapter()->publish($variant))->externalId)->toBe('100000000000001_900000000000001');
    });

    it('is not put into the address of a delete when it is not a post id', function (string $id) {
        fakeGraph();
        $variant = ($this->post)($id);
        $variant->setRelation('channel', $this->channel);

        expect(fn () => inWorkspace($this->workspace, fn () => facebookAdapter()->delete($variant)))->toThrow(ChannelException::class);

        Http::assertNothingSent();
    })->with([
        'a trailing line break' => ["100000000000001_900000000000001\n"],
        'a path' => ['../100000000000001'],
        'a query' => ['100000000000001?method=post'],
    ]);
});

it('takes the rate limit from the settings', function () {
    configureFacebookMetrics(['rate_limit_calls_per_day' => 200]);

    expect(facebookAdapter()->capabilities()->rateLimit?->toArray())->toBe(['max_calls' => 200, 'per_seconds' => 86400]);
});

/**
 * The value of an X-Business-Use-Case-Usage header that asks to wait the
 * given minutes.
 */
function facebookUsage(int $minutes): string
{
    return (string) json_encode(['100000000000001' => [[
        'type' => 'pages',
        'call_count' => 100,
        'total_cputime' => 25,
        'total_time' => 25,
        'estimated_time_to_regain_access' => $minutes,
    ]]]);
}

/**
 * A Graph error whose message repeats the credentials of the request, as
 * tests/Fixtures/facebook/error_echoes_credentials.json does for publishing.
 *
 * @return array<string, mixed>
 */
function metricsEcho(int $code = 100): array
{
    return ['error' => [
        'message' => 'Malformed request to /?access_token='.METRICS_PAGE_TOKEN.'&appsecret_proof='.facebookProof(METRICS_PAGE_TOKEN)
            .' with secret '.FACEBOOK_TEST_APP_SECRET.' and token '.METRICS_PAGE_TOKEN,
        'type' => 'OAuthException',
        'code' => $code,
        'fbtrace_id' => 'AtestTraceMetricsEcho01',
    ]];
}
