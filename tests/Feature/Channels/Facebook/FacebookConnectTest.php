<?php

use App\Domain\Channels\Adapters\Facebook\FacebookAdapter;
use App\Domain\Channels\ChannelManager;
use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Contracts\DescribesSetup;
use App\Domain\Channels\Exceptions\ChannelException;
use App\Domain\Channels\Models\Channel;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../../Support/facebook.php';

beforeEach(function () {
    configureFacebook();
    Http::preventStrayRequests();

    $this->workspace = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
});

it('is the adapter that ChannelManager builds for facebook', function () {
    $adapter = app(ChannelManager::class)->adapter('facebook');

    expect($adapter)->toBeInstanceOf(FacebookAdapter::class)
        ->toBeInstanceOf(DescribesSetup::class)
        ->and($adapter->platform())->toBe('facebook');
});

it('describes what a Page post accepts', function () {
    $capabilities = facebookAdapter()->capabilities();

    expect($capabilities->maxBodyLength)->toBe(63206)
        ->and($capabilities->linkPreviews)->toBeTrue()
        ->and($capabilities->rateLimit?->toArray())->toBe(['max_calls' => 4800, 'per_seconds' => 86400]);
});

it('takes the body limit from the settings when one is given', function () {
    configureFacebook(['max_body_length' => 5000]);

    expect(facebookAdapter()->capabilities()->maxBodyLength)->toBe(5000);
});

describe('setup', function () {
    it('reports which settings are missing', function (array $settings, array $missing) {
        configureFacebook($settings);

        expect(facebookAdapter()->missingSettings())->toBe($missing);
    })->with([
        'complete' => [[], []],
        'no app id' => [['app_id' => null], ['FACEBOOK_APP_ID']],
        'empty secret' => [['app_secret' => ''], ['FACEBOOK_APP_SECRET']],
        'neither' => [['app_id' => null, 'app_secret' => null], ['FACEBOOK_APP_ID', 'FACEBOOK_APP_SECRET']],
    ]);

    it('builds the redirect address from APP_URL and not from the request', function () {
        config(['app.url' => 'https://noongun.tail1234.ts.net/']);

        $this->get('http://some-other-host.test/up');

        expect(facebookAdapter()->redirectUri())->toBe('https://noongun.tail1234.ts.net/auth/facebook/callback');
    });

    it('refuses to start or finish a connection when it is not configured', function () {
        configureFacebook(['app_secret' => null]);

        expect(fn () => facebookAdapter()->authorizationUrl($this->workspace, 'state'))
            ->toThrow(ChannelException::class, 'FACEBOOK_APP_SECRET')
            ->and(fn () => facebookAdapter()->connect($this->workspace, 'test-auth-code'))
            ->toThrow(ChannelException::class, 'FACEBOOK_APP_SECRET');

        Http::assertNothingSent();
    });
});

describe('authorization URL', function () {
    it('points at the login dialog of the configured version with state, scopes and redirect address', function () {
        $url = facebookAdapter()->authorizationUrl($this->workspace, 'state-abc 123');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        expect($url)->toStartWith('https://www.facebook.test/v99.0/dialog/oauth?')
            ->and($query)->toBe([
                'client_id' => 'test-app-id',
                'redirect_uri' => 'https://noongun.test/auth/facebook/callback',
                'state' => 'state-abc 123',
                'response_type' => 'code',
                'scope' => 'pages_show_list,pages_read_engagement,pages_manage_posts',
                'auth_type' => 'rerequest',
            ]);
    });

    it('never holds the app secret', function () {
        expect(facebookAdapter()->authorizationUrl($this->workspace, 'state'))->not->toContain(FACEBOOK_TEST_APP_SECRET);
    });

    it('asks for the scopes that are configured', function () {
        configureFacebook(['scopes' => ['pages_show_list', 'pages_manage_posts']]);

        expect(facebookAdapter()->authorizationUrl($this->workspace, 'state'))
            ->toContain('scope=pages_show_list%2Cpages_manage_posts');
    });
});

describe('connect', function () {
    it('exchanges the code, gets a long-lived token and saves one channel for each Page that can be published to', function () {
        fakeGraph();

        $channels = inWorkspace($this->workspace, fn () => facebookAdapter()->connect($this->workspace, 'test-auth-code'));

        expect($channels)->toHaveCount(2)
            ->and($channels->pluck('display_name')->all())->toBe(['Table Bay Coffee', 'Harbour Books'])
            ->and($channels->pluck('external_id')->all())->toBe(['100000000000001', '100000000000002']);

        $channel = $channels->first();

        expect($channel->workspace_id)->toBe($this->workspace->id)
            ->and($channel->platform)->toBe('facebook')
            ->and($channel->access_token)->toBe('test-page-token-table-bay-coffee')
            ->and($channel->refresh_token)->toBeNull()
            ->and($channel->token_expires_at)->toBeNull()
            ->and($channel->scopes)->toBe(['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'])
            // New to this workspace: saved, but not able to publish until
            // the user has confirmed it.
            ->and($channel->status)->toBe(ChannelStatus::Pending)
            ->and($channel->wasRecentlyCreated)->toBeTrue()
            ->and($channel->canPublish())->toBeFalse();

        $stored = DB::table('channels')->where('id', $channel->id)->value('access_token');

        expect($stored)->not->toContain('test-page-token')
            ->and(Crypt::decryptString($stored))->toBe('test-page-token-table-bay-coffee');

        // The Page that only allows analysis is not connected.
        expect(DB::table('channels')->where('external_id', '100000000000003')->exists())->toBeFalse();
    });

    it('makes the documented calls, in order, with appsecret_proof on the user token call', function () {
        fakeGraph();

        inWorkspace($this->workspace, fn () => facebookAdapter()->connect($this->workspace, 'test-auth-code'));

        $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0])->values();

        expect($requests)->toHaveCount(5);

        expect($requests[0]->method())->toBe('GET')
            ->and($requests[0]->url())->toStartWith(FACEBOOK_TEST_GRAPH.'/oauth/access_token?')
            ->and(facebookQuery($requests[0]))->toBe([
                'client_id' => 'test-app-id',
                'redirect_uri' => 'https://noongun.test/auth/facebook/callback',
                'client_secret' => FACEBOOK_TEST_APP_SECRET,
                'code' => 'test-auth-code',
            ]);

        expect(facebookQuery($requests[1]))->toBe([
            'grant_type' => 'fb_exchange_token',
            'client_id' => 'test-app-id',
            'client_secret' => FACEBOOK_TEST_APP_SECRET,
            'fb_exchange_token' => 'test-short-lived-user-token',
        ]);

        expect($requests[2]->url())->toStartWith(FACEBOOK_TEST_GRAPH.'/me/accounts?')
            ->and(facebookQuery($requests[2]))->toBe([
                'fields' => 'id,name,tasks,access_token',
                'limit' => '100',
                'access_token' => 'test-long-lived-user-token',
                'appsecret_proof' => facebookProof('test-long-lived-user-token'),
            ]);

        expect($requests[3]->url())->toStartWith(FACEBOOK_TEST_GRAPH.'/debug_token?')
            ->and(facebookQuery($requests[3]))->toBe([
                'input_token' => 'test-page-token-table-bay-coffee',
                'access_token' => 'test-app-id|'.FACEBOOK_TEST_APP_SECRET,
            ])
            ->and(facebookQuery($requests[4])['input_token'])->toBe('test-page-token-harbour-books');
    });

    it('stores only Page tokens, never a user token', function () {
        fakeGraph();

        inWorkspace($this->workspace, fn () => facebookAdapter()->connect($this->workspace, 'test-auth-code'));

        $tokens = DB::table('channels')->pluck('access_token')->map(fn (string $token) => Crypt::decryptString($token))->all();

        expect($tokens)->toBe(['test-page-token-table-bay-coffee', 'test-page-token-harbour-books'])
            ->and(DB::table('channels')->whereNotNull('refresh_token')->count())->toBe(0);
    });

    it('records the expiry when Facebook reports one', function () {
        fakeGraph(['accounts' => 'me_accounts_one_page', 'debug' => 'debug_token_expiring']);

        $channel = inWorkspace($this->workspace, fn () => facebookAdapter()->connect($this->workspace, 'test-auth-code'))->sole();

        expect($channel->token_expires_at?->toIso8601String())->toBe('2027-01-01T00:00:00+00:00')
            ->and($channel->token_expires_at?->getTimezone()->getName())->toBe('UTC');
    });

    it('reads every result page by cursor and never follows the next link, which holds the user token', function () {
        fakeGraph([
            'accounts' => fn (Request $request) => facebookResponse(
                isset($request['after']) ? 'me_accounts_second_of_two' : 'me_accounts_first_of_two',
            ),
        ]);

        $channels = inWorkspace($this->workspace, fn () => facebookAdapter()->connect($this->workspace, 'test-auth-code'));

        expect($channels->pluck('external_id')->all())->toBe(['100000000000001', '100000000000002']);

        $accountCalls = Http::recorded()
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $request) => str_contains($request->url(), '/me/accounts'))
            ->values();

        expect($accountCalls)->toHaveCount(2)
            ->and(facebookQuery($accountCalls[1])['after'])->toBe('QVFIUtestSecondPage')
            ->and(facebookQuery($accountCalls[1])['appsecret_proof'])->toBe(facebookProof('test-long-lived-user-token'));
    });

    it('renews an existing channel instead of adding a second row', function (string $state, ChannelStatus $expected) {
        $existing = inWorkspace($this->workspace, fn () => Channel::factory()->{$state}()->create([
            'external_id' => '100000000000001',
            'display_name' => 'Old name',
            'scopes' => ['pages_show_list'],
        ]));

        fakeGraph(['accounts' => 'me_accounts_one_page']);

        $channel = inWorkspace($this->workspace, fn () => facebookAdapter()->connect($this->workspace, 'test-auth-code'))->sole();

        expect($channel->id)->toBe($existing->id)
            ->and($channel->display_name)->toBe('Table Bay Coffee')
            ->and($channel->access_token)->toBe('test-page-token-table-bay-coffee')
            ->and($channel->wasRecentlyCreated)->toBeFalse()
            ->and($channel->status)->toBe($expected)
            ->and($channel->canPublish())->toBe($expected === ChannelStatus::Active)
            ->and($channel->scopes)->toContain('pages_manage_posts')
            ->and(DB::table('channels')->count())->toBe(1);
    })->with([
        // Chosen by the user and never disconnected: renewed, active at once.
        'one that needs a reconnect' => ['needsReconnect', ChannelStatus::Active],
        'an active one whose token expired' => ['expired', ChannelStatus::Active],
        // Not connected by the user's last word on it: waits for a confirmation.
        'one the user disconnected' => ['disconnected', ChannelStatus::Pending],
        'one that is still pending' => ['pending', ChannelStatus::Pending],
    ]);

    it('keeps an active channel able to publish while it is renewed next to a new Page', function () {
        $active = inWorkspace($this->workspace, fn () => Channel::factory()->create(['external_id' => '100000000000001', 'access_token' => 'old-page-token']));

        fakeGraph();

        $channels = inWorkspace($this->workspace, fn () => facebookAdapter()->connect($this->workspace, 'test-auth-code'));

        expect($channels->firstWhere('id', $active->id)->canPublish())->toBeTrue()
            ->and($channels->firstWhere('id', $active->id)->access_token)->toBe('test-page-token-table-bay-coffee')
            ->and($channels->firstWhere('external_id', '100000000000002')->status)->toBe(ChannelStatus::Pending);
    });

    it('uses the current workspace of this moment, not one kept from when the adapter was built', function () {
        fakeGraph(['accounts' => 'me_accounts_one_page']);

        $adapter = facebookAdapter();
        $adapter->connect($this->workspace, 'test-auth-code');

        // What a queue worker or Octane does between two jobs or requests:
        // the scoped CurrentWorkspace is thrown away, the manager and the
        // adapter it caches live on.
        app()->forgetScopedInstances();

        expect(facebookAdapter())->toBe($adapter);

        $other = Workspace::factory()->create();
        $channel = facebookAdapter()->connect($other, 'test-auth-code')->sole();

        expect($channel->workspace_id)->toBe($other->id)
            ->and(DB::table('channels')->where('workspace_id', $other->id)->count())->toBe(1)
            ->and(DB::table('channels')->where('workspace_id', $this->workspace->id)->count())->toBe(1);
    });

    it('keeps the same Page apart in two workspaces', function () {
        $other = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
        $theirs = inWorkspace($other, fn () => Channel::factory()->create([
            'external_id' => '100000000000001',
            'access_token' => 'their-own-page-token',
            'display_name' => 'Theirs',
        ]));

        fakeGraph(['accounts' => 'me_accounts_one_page']);

        $mine = inWorkspace($this->workspace, fn () => facebookAdapter()->connect($this->workspace, 'test-auth-code'))->sole();

        $theirs = inWorkspace($other, fn () => $theirs->fresh());

        expect($mine->id)->not->toBe($theirs->id)
            ->and($mine->workspace_id)->toBe($this->workspace->id)
            ->and($theirs->access_token)->toBe('their-own-page-token')
            ->and($theirs->display_name)->toBe('Theirs');
    });

    it('saves into the given workspace even when no workspace is current', function () {
        fakeGraph(['accounts' => 'me_accounts_one_page']);

        $channel = facebookAdapter()->connect($this->workspace, 'test-auth-code')->sole();

        expect($channel->workspace_id)->toBe($this->workspace->id);
    });

    it('saves nothing when the code is refused', function () {
        fakeGraph(['code' => facebookResponse('error_code_used', 400)]);

        try {
            inWorkspace($this->workspace, fn () => facebookAdapter()->connect($this->workspace, 'test-auth-code'));
            $this->fail('Expected a ChannelException.');
        } catch (ChannelException $exception) {
            expect($exception->retryable)->toBeFalse()
                ->and($exception->requiresReconnect)->toBeFalse()
                ->and($exception->getMessage())->toContain('This authorization code has been used.')
                ->and($exception->context())->toMatchArray(['http_status' => 400, 'code' => 100, 'subcode' => 36009]);
        }

        Http::assertSentCount(1);
        expect(DB::table('channels')->count())->toBe(0);
    });

    it('saves nothing when a needed permission was not granted, and says which', function () {
        fakeGraph(['debug' => 'debug_token_missing_scope']);

        expect(fn () => inWorkspace($this->workspace, fn () => facebookAdapter()->connect($this->workspace, 'test-auth-code')))
            ->toThrow(ChannelException::class, 'pages_manage_posts');

        expect(DB::table('channels')->count())->toBe(0);
    });

    it('saves nothing when a Page token is reported as not valid', function () {
        fakeGraph(['debug' => 'debug_token_invalid']);

        expect(fn () => inWorkspace($this->workspace, fn () => facebookAdapter()->connect($this->workspace, 'test-auth-code')))
            ->toThrow(ChannelException::class, 'not valid');

        expect(DB::table('channels')->count())->toBe(0);
    });

    it('fails clearly when no Page was granted', function () {
        fakeGraph(['accounts' => 'me_accounts_empty']);

        expect(fn () => inWorkspace($this->workspace, fn () => facebookAdapter()->connect($this->workspace, 'test-auth-code')))
            ->toThrow(ChannelException::class, 'no Page');

        expect(DB::table('channels')->count())->toBe(0);
    });

    it('keeps the code, the tokens and the secret out of a failure', function (string $endpoint) {
        // The worst case: Facebook repeats the whole request in its message.
        fakeGraph([$endpoint => fn (Request $request) => Http::response(['error' => [
            'message' => 'Bad request to '.$request->url().' with values '.implode(' ', $request->data()),
            'type' => 'OAuthException',
            'code' => 100,
        ]], 400)]);

        try {
            inWorkspace($this->workspace, fn () => facebookAdapter()->connect($this->workspace, 'test-auth-code'));
            $this->fail('Expected a ChannelException.');
        } catch (ChannelException $exception) {
            $everything = $exception->getMessage().json_encode($exception->context()).$exception->getTraceAsString();

            expect($everything)
                ->not->toContain('test-auth-code')
                ->not->toContain(FACEBOOK_TEST_APP_SECRET)
                ->not->toContain('test-short-lived')
                ->not->toContain('test-long-lived')
                ->not->toContain('test-page-token')
                ->and($exception->getPrevious())->toBeNull();
        }
    })->with(['code', 'exchange', 'accounts', 'debug']);
});
