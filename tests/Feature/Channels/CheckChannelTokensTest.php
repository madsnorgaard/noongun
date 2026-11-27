<?php

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\CheckChannelTokenJob;
use App\Domain\Channels\Events\ChannelTokenVerified;
use App\Domain\Channels\Models\Channel;
use App\Domain\Channels\TokenCheckConfig;
use App\Domain\Insights\ChannelPolling;
use App\Domain\Workspaces\Models\Workspace;
use GuzzleHttp\Exception\NetworkTimeoutException;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/facebook.php';
require_once __DIR__.'/../../Support/linkedin.php';
require_once __DIR__.'/../../Support/posts.php';

const CHECKED_PAGE_TOKEN = 'test-page-token-kalk-bay-books';

/**
 * Runs the check for a channel the way a worker does, without the queue.
 */
function checkToken(Channel $channel): void
{
    app()->call([new CheckChannelTokenJob($channel->workspace_id, $channel->id), 'handle']);
}

/**
 * The stored row of a channel, read without a workspace.
 */
function storedChannel(Channel $channel): stdClass
{
    return DB::table('channels')->where('id', $channel->id)->sole();
}

beforeEach(function () {
    configureFacebook(['insights_scope' => 'read_insights', 'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'read_insights']]);
    configureLinkedIn();
    $this->travelTo('2026-11-27 09:00:00');

    $this->workspace = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
    $this->page = channelIn($this->workspace, [
        'external_id' => '100000000000001',
        'access_token' => CHECKED_PAGE_TOKEN,
        'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'read_insights'],
    ]);
    $this->profile = inWorkspace($this->workspace, fn () => Channel::factory()->platform('linkedin')->create([
        'external_id' => LINKEDIN_TEST_MEMBER,
        'access_token' => LINKEDIN_TEST_TOKEN,
        'scopes' => ['openid', 'profile', 'w_member_social'],
        'token_expires_at' => '2027-01-24 10:00:00',
    ]));
});

describe('the command', function () {
    it('queues a check for every active channel that was not checked in the last day', function () {
        Queue::fake();

        $recent = channelIn($this->workspace, ['external_id' => '100000000000002']);
        DB::table('channels')->where('id', $recent->id)->update(['token_checked_at' => now()->subHours(23)]);
        $old = channelIn($this->workspace, ['external_id' => '100000000000003']);
        DB::table('channels')->where('id', $old->id)->update(['token_checked_at' => now()->subHours(25)]);

        $otherWorkspace = Workspace::factory()->create();
        $elsewhere = channelIn($otherWorkspace, ['external_id' => '100000000000004']);

        $this->artisan('noongun:check-channel-tokens')
            ->expectsOutputToContain('Queued a token check for 4 channel(s).')
            ->assertSuccessful();

        Queue::assertPushed(CheckChannelTokenJob::class, 4);
        Queue::assertPushedOn('insights', CheckChannelTokenJob::class);

        foreach ([$this->page, $this->profile, $old, $elsewhere] as $channel) {
            Queue::assertPushed(CheckChannelTokenJob::class, fn (CheckChannelTokenJob $job) => $job->workspaceId === $channel->workspace_id && $job->channelId === $channel->id);
        }

        Http::assertNothingSent();
    });

    it('leaves out channels that are not connected, wait for their user, or have no adapter', function (Closure $arrange) {
        Queue::fake();
        DB::table('channels')->where('id', $this->profile->id)->update(['token_checked_at' => now()]);
        $arrange($this);

        $this->artisan('noongun:check-channel-tokens')->assertSuccessful();

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    })->with([
        'needs a reconnect' => [fn ($test) => DB::table('channels')->where('id', $test->page->id)->update(['status' => ChannelStatus::NeedsReconnect->value])],
        'pending' => [fn ($test) => DB::table('channels')->where('id', $test->page->id)->update(['status' => ChannelStatus::Pending->value])],
        'disconnected' => [fn ($test) => inWorkspace($test->workspace, fn () => $test->page->disconnect())],
        'a platform that is not configured' => [fn ($test) => DB::table('channels')->where('id', $test->page->id)->update(['platform' => 'gone'])],
        'turned off' => [fn ($test) => config(['noongun.token_check.enabled' => false])],
    ]);

    it('queues a bounded number in one run, the longest waiting first, and the rest in the next', function () {
        Queue::fake();
        config(['noongun.token_check.channels_per_run' => 2]);

        $otherWorkspace = Workspace::factory()->create();
        $third = channelIn($otherWorkspace, ['external_id' => '100000000000004']);
        DB::table('channels')->where('id', $this->profile->id)->update(['token_checked_at' => now()->subDays(3)]);

        $this->artisan('noongun:check-channel-tokens')->expectsOutputToContain('for 2 channel(s)')->assertSuccessful();

        Queue::assertPushed(CheckChannelTokenJob::class, 2);
        Queue::assertNotPushed(CheckChannelTokenJob::class, fn (CheckChannelTokenJob $job) => $job->channelId === $third->id);

        // The first two are still queued: a second run adds only the third.
        $this->artisan('noongun:check-channel-tokens')->assertSuccessful();

        Queue::assertPushed(CheckChannelTokenJob::class, 3);
        Queue::assertPushed(CheckChannelTokenJob::class, fn (CheckChannelTokenJob $job) => $job->channelId === $third->id);
    });

    it('is scheduled every hour without overlapping', function () {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'noongun:check-channel-tokens'));

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('17 * * * *')
            ->and($event->withoutOverlapping)->toBeTrue();
    });

    it('checks end to end: the command queues, the job asks, the answer is stored', function () {
        fakeGraph();
        DB::table('channels')->where('id', $this->profile->id)->update(['token_checked_at' => now()]);

        $this->artisan('noongun:check-channel-tokens')->assertSuccessful();
        $this->artisan('noongun:check-channel-tokens')->expectsOutputToContain('for 0 channel(s)')->assertSuccessful();

        Http::assertSentCount(1);
        expect(storedChannel($this->page)->token_checked_at)->toBe('2026-11-27 09:00:00');

        $this->travel(25)->hours();
        $this->artisan('noongun:check-channel-tokens')->assertSuccessful();

        Http::assertSentCount(2);
    });
});

describe('a Facebook Page', function () {
    it('records the permissions and the expiry Facebook reports, and stays active', function () {
        fakeGraph(['debug' => 'debug_token_expiring']);
        Event::fake([ChannelTokenVerified::class]);

        checkToken($this->page);

        $stored = storedChannel($this->page);

        // The fixture names no read_insights: the Page lost that one.
        expect($stored->status)->toBe(ChannelStatus::Active->value)
            ->and(json_decode($stored->scopes))->toBe(['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'])
            ->and($stored->token_expires_at)->toBe('2027-01-01 00:00:00')
            ->and($stored->token_checked_at)->toBe('2026-11-27 09:00:00');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => facebookQuery($request)['input_token'] === CHECKED_PAGE_TOKEN);
        Event::assertDispatched(ChannelTokenVerified::class, fn (ChannelTokenVerified $event) => $event->workspaceId === $this->workspace->id && $event->channelId === $this->page->id);
    });

    it('learns of a permission the Page gained since it was connected', function () {
        DB::table('channels')->where('id', $this->page->id)->update(['scopes' => json_encode(['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'])]);

        $report = facebookFixture('debug_token_page');
        $report['data']['scopes'][] = 'read_insights';
        fakeGraph(['debug' => Http::response($report)]);

        checkToken($this->page);

        expect(json_decode(storedChannel($this->page)->scopes))->toContain('read_insights');
    });

    it('marks the Page as needing a reconnect on a definite answer', function (string $fixture, string $at) {
        fakeGraph(['debug' => $fixture]);
        Event::fake([ChannelTokenVerified::class]);
        $this->travelTo($at);
        Log::spy();

        checkToken($this->page);

        expect(storedChannel($this->page)->status)->toBe(ChannelStatus::NeedsReconnect->value)
            ->and(storedChannel($this->page)->token_checked_at)->not->toBeNull();

        Event::assertNotDispatched(ChannelTokenVerified::class);
        Log::shouldHaveReceived('notice')->once();
    })->with([
        'Facebook says the token is not valid' => ['debug_token_invalid', '2026-11-27 09:00:00'],
        'a permission that publishing needs is gone' => ['debug_token_missing_scope', '2026-11-27 09:00:00'],
        'the expiry Facebook reports has passed' => ['debug_token_expiring', '2027-02-01 10:00:00'],
    ]);

    it('leaves the Page exactly as it was when the answer is not definite', function (Closure $response) {
        fakeGraph(['debug' => $response]);
        Event::fake([ChannelTokenVerified::class]);
        $before = storedChannel($this->page);

        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = json_encode([$message->level, $message->message, $message->context]);
        });

        checkToken($this->page);

        $after = storedChannel($this->page);

        expect($after->status)->toBe(ChannelStatus::Active->value)
            ->and($after->scopes)->toBe($before->scopes)
            ->and($after->access_token)->toBe($before->access_token)
            ->and($after->token_expires_at)->toBe($before->token_expires_at)
            // Asked: the next attempt is at the next round, not at once.
            ->and($after->token_checked_at)->toBe('2026-11-27 09:00:00')
            ->and($logged)->toHaveCount(1)
            ->and($logged[0])->not->toContain(CHECKED_PAGE_TOKEN)->not->toContain(FACEBOOK_TEST_APP_SECRET);

        Event::assertNotDispatched(ChannelTokenVerified::class);
    })->with([
        'a read timeout' => [fn (Request $request) => throw facebookConnectionFailure($request, NetworkTimeoutException::class, 'Operation timed out')],
        'a service error' => [fn () => facebookResponse('error_service', 500)],
        'a gateway error without JSON' => [fn () => Http::response('<html>Bad gateway</html>', 502)],
        'a rate limit' => [fn () => facebookResponse('error_rate_limit', 400)],
        'an error about the app credentials' => [fn () => facebookResponse('error_token_expired', 400)],
        'an answer without data' => [fn () => Http::response(['unexpected' => true])],
        'an answer that does not say whether the token is valid' => [fn () => Http::response(['data' => ['app_id' => 'test-app-id', 'scopes' => []]])],
        'an exception that is not from the platform' => [fn (Request $request) => throw new RuntimeException("GET {$request->url()}")],
    ]);

    it('keeps the recorded permissions when a valid answer names none', function () {
        $report = facebookFixture('debug_token_page');
        unset($report['data']['scopes']);
        fakeGraph(['debug' => Http::response($report)]);

        checkToken($this->page);

        expect(storedChannel($this->page)->status)->toBe(ChannelStatus::Active->value)
            ->and(json_decode(storedChannel($this->page)->scopes))->toContain('pages_manage_posts', 'read_insights');
    });

    it('ends a pause of reading that was about the token, and no other', function () {
        fakeGraph();
        ChannelPolling::pauseUntilConnectedAgain($this->page->id, 3600);
        ChannelPolling::pause($this->profile->id, 3600, ChannelPolling::PAUSE_RATE_LIMIT);
        ChannelPolling::pauseUntilConnectedAgain($this->profile->id, 3600);

        checkToken($this->page);

        expect(ChannelPolling::isPaused($this->page->id))->toBeFalse()
            ->and(ChannelPolling::pauseOf($this->profile->id)['kind'])->toBe(ChannelPolling::PAUSE_RATE_LIMIT);
    });
});

describe('a LinkedIn profile', function () {
    it('records what LinkedIn reports and stays active', function () {
        fakeLinkedIn();
        Event::fake([ChannelTokenVerified::class]);

        checkToken($this->profile);

        expect(storedChannel($this->profile)->status)->toBe(ChannelStatus::Active->value)
            ->and(storedChannel($this->profile)->token_checked_at)->toBe('2026-11-27 09:00:00');

        Http::assertSent(fn (Request $request) => $request->url() === LINKEDIN_TEST_WEB.'/oauth/v2/introspectToken');
        Event::assertDispatched(ChannelTokenVerified::class);
    });

    it('marks the profile as needing a reconnect on a definite answer', function (string $fixture) {
        fakeLinkedIn(['introspect' => $fixture]);

        checkToken($this->profile);

        expect(storedChannel($this->profile)->status)->toBe(ChannelStatus::NeedsReconnect->value);
    })->with([
        'the member withdrew the access' => ['introspect_revoked'],
        'the permission to post is gone' => ['introspect_missing_scope'],
    ]);

    it('leaves the profile as it was when the answer is not definite', function (Closure $response) {
        fakeLinkedIn(['introspect' => $response]);
        $before = storedChannel($this->profile);

        checkToken($this->profile);

        $after = storedChannel($this->profile);

        expect($after->status)->toBe(ChannelStatus::Active->value)
            ->and($after->scopes)->toBe($before->scopes)
            ->and($after->token_expires_at)->toBe($before->token_expires_at);
    })->with([
        ...linkedInAmbiguousFailures(),
        'a rate limit' => [fn () => linkedInResponse('error_rate_limit', 429)],
        'an answer that does not say whether the token is active' => [fn () => Http::response(['client_id' => 'test-client-id'])],
        'an answer with active as text' => [fn () => Http::response(['active' => 'false'])],
    ]);

    it('does not blame the profile when a refresh token cannot be exchanged for a reason of the instance', function (Closure $response) {
        inWorkspace($this->workspace, fn () => $this->profile->forceFill(['refresh_token' => 'test-linkedin-refresh-token'])->save());
        fakeLinkedIn(['token' => $response]);

        checkToken($this->profile);

        expect(storedChannel($this->profile)->status)->toBe(ChannelStatus::Active->value);
    })->with([
        'the client secret is wrong' => [fn () => Http::response(['error' => 'invalid_client', 'error_description' => 'Client authentication failed'], 401)],
        'an answer without a token' => [fn () => Http::response(['expires_in' => 5184000])],
        'a server error' => [fn () => linkedInResponse('error_server', 500)],
    ]);

    it('marks the profile when LinkedIn refuses its refresh token', function () {
        inWorkspace($this->workspace, fn () => $this->profile->forceFill(['refresh_token' => 'test-linkedin-refresh-token'])->save());
        fakeLinkedIn(['token' => linkedInResponse('error_refresh_token_invalid', 400)]);

        checkToken($this->profile);

        expect(storedChannel($this->profile)->status)->toBe(ChannelStatus::NeedsReconnect->value);
    });
});

describe('what it never does', function () {
    it('asks nothing about a channel that is not active, and never makes one active', function (ChannelStatus $status) {
        fakeGraph();
        DB::table('channels')->where('id', $this->page->id)->update(['status' => $status->value]);

        checkToken($this->page);

        Http::assertNothingSent();
        expect(storedChannel($this->page)->status)->toBe($status->value)
            ->and(storedChannel($this->page)->token_checked_at)->toBeNull();
    })->with([
        'needs a reconnect' => [ChannelStatus::NeedsReconnect],
        'pending' => [ChannelStatus::Pending],
        'disconnected' => [ChannelStatus::Disconnected],
    ]);

    it('checks nothing when a job names the channel of another workspace', function () {
        fakeGraph();
        $otherWorkspace = Workspace::factory()->create();

        app()->call([new CheckChannelTokenJob($otherWorkspace->id, $this->page->id), 'handle']);

        Http::assertNothingSent();
        expect(storedChannel($this->page)->token_checked_at)->toBeNull();
    });

    it('carries no token in its payload, and has a time limit above one platform call', function () {
        $job = new CheckChannelTokenJob($this->workspace->id, $this->page->id);

        expect(serialize($job))->not->toContain(CHECKED_PAGE_TOKEN)
            ->and($job->tries)->toBe(1)
            ->and($job->timeout)->toBe(45)
            ->and($job->timeout)->toBeLessThan(60)
            ->and(TokenCheckConfig::everyHours())->toBe(24);
    });

    it('does nothing when checking is turned off', function () {
        fakeGraph();
        config(['noongun.token_check.enabled' => false]);

        checkToken($this->page);

        Http::assertNothingSent();
    });
});
