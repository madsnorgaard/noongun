<?php

use App\Domain\Channels\Adapters\LinkedIn\LinkedInAdapter;
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

require_once __DIR__.'/../../../Support/linkedin.php';

beforeEach(function () {
    configureLinkedIn();
    Http::preventStrayRequests();
    $this->travelTo('2026-11-25 10:00:00');

    $this->workspace = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
    $this->connect = fn (?Workspace $workspace = null) => inWorkspace(
        $workspace ?? $this->workspace,
        fn () => linkedInAdapter()->connect($workspace ?? $this->workspace, 'test-auth-code'),
    );
});

it('is the adapter that ChannelManager builds for linkedin', function () {
    $adapter = app(ChannelManager::class)->adapter('linkedin');

    expect($adapter)->toBeInstanceOf(LinkedInAdapter::class)
        ->toBeInstanceOf(DescribesSetup::class)
        ->and($adapter->platform())->toBe('linkedin');
});

it('is configured in the shipped configuration, next to facebook', function () {
    $shipped = require base_path('config/noongun.php');

    expect(array_keys($shipped['channels']))->toBe(['facebook', 'linkedin'])
        ->and($shipped['channels']['linkedin']['adapter'])->toBe(LinkedInAdapter::class)
        ->and($shipped['channels']['linkedin']['scopes'])->toBe(['openid', 'profile', 'w_member_social'])
        ->and($shipped['channels']['linkedin']['scopes'])->not->toContain('email')
        ->and($shipped['channels']['linkedin']['publish_scope'])->toBe('w_member_social');
});

it('describes what a LinkedIn post accepts', function () {
    $capabilities = linkedInAdapter()->capabilities();

    expect($capabilities->maxBodyLength)->toBe(3000)
        ->and($capabilities->linkPreviews)->toBeFalse()
        ->and($capabilities->needsLinkCard())->toBeTrue()
        ->and($capabilities->linkTitleLength)->toBe(399)
        ->and($capabilities->linkDescriptionLength)->toBe(4085)
        ->and($capabilities->maxMediaItems)->toBe(0)
        ->and($capabilities->mediaTypes)->toBe([])
        ->and($capabilities->rateLimit?->toArray())->toBe(['max_calls' => 150, 'per_seconds' => 86400]);
});

it('takes the body limit and the rate limit from the settings when they are given', function () {
    configureLinkedIn(['max_body_length' => 1300, 'rate_limit_per_day' => 100]);

    expect(linkedInAdapter()->capabilities()->maxBodyLength)->toBe(1300)
        ->and(linkedInAdapter()->capabilities()->rateLimit?->maxCalls)->toBe(100);
});

describe('setup', function () {
    it('reports which settings are missing', function (array $settings, array $missing) {
        configureLinkedIn($settings);

        expect(linkedInAdapter()->missingSettings())->toBe($missing);
    })->with([
        'complete' => [[], []],
        'no client id' => [['client_id' => null], ['LINKEDIN_CLIENT_ID']],
        'empty secret' => [['client_secret' => ''], ['LINKEDIN_CLIENT_SECRET']],
        'neither' => [['client_id' => null, 'client_secret' => null], ['LINKEDIN_CLIENT_ID', 'LINKEDIN_CLIENT_SECRET']],
    ]);

    it('builds the redirect address from APP_URL and not from the request', function () {
        config(['app.url' => 'https://noongun.tail1234.ts.net/']);

        $this->get('http://some-other-host.test/up');

        expect(linkedInAdapter()->redirectUri())->toBe('https://noongun.tail1234.ts.net/auth/linkedin/callback');
    });

    it('refuses to start or finish a connection when it is not configured', function (array $settings, string $names) {
        configureLinkedIn($settings);

        expect(fn () => linkedInAdapter()->authorizationUrl($this->workspace, 'state'))
            ->toThrow(ChannelException::class, $names)
            ->and(fn () => ($this->connect)())
            ->toThrow(ChannelException::class, $names);

        Http::assertNothingSent();
    })->with([
        'no secret' => [['client_secret' => null], 'LINKEDIN_CLIENT_SECRET'],
        'an API address that is not HTTPS' => [['api_url' => 'http://api.linkedin.test'], 'LINKEDIN_API_URL'],
        'a sign-in address that is not HTTPS' => [['web_url' => 'http://www.linkedin.test'], 'LINKEDIN_WEB_URL'],
        'a version that is not YYYYMM' => [['api_version' => 'v2'], 'LINKEDIN_API_VERSION'],
    ]);
});

describe('authorization URL', function () {
    it('points at the configured sign-in page with state, scopes and the exact redirect address', function () {
        $url = linkedInAdapter()->authorizationUrl($this->workspace, 'state-abc 123');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        expect($url)->toStartWith(LINKEDIN_TEST_WEB.'/oauth/v2/authorization?')
            ->toContain('scope=openid%20profile%20w_member_social')
            ->and($query)->toBe([
                'response_type' => 'code',
                'client_id' => 'test-client-id',
                'redirect_uri' => 'https://noongun.test/auth/linkedin/callback',
                'state' => 'state-abc 123',
                'scope' => 'openid profile w_member_social',
            ]);
    });

    it('never holds the client secret', function () {
        expect(linkedInAdapter()->authorizationUrl($this->workspace, 'state'))->not->toContain(LINKEDIN_TEST_SECRET);
    });

    it('asks for the scopes that are configured', function () {
        configureLinkedIn(['scopes' => ['openid', 'w_member_social']]);

        expect(linkedInAdapter()->authorizationUrl($this->workspace, 'state'))->toContain('scope=openid%20w_member_social');
    });
});

describe('connect', function () {
    it('exchanges the code, asks who signed in and saves one channel for that member', function () {
        fakeLinkedIn();

        $channel = ($this->connect)()->sole();

        expect($channel->workspace_id)->toBe($this->workspace->id)
            ->and($channel->platform)->toBe('linkedin')
            ->and($channel->external_id)->toBe(LINKEDIN_TEST_MEMBER)
            ->and($channel->display_name)->toBe('Thandi Mokoena')
            ->and($channel->access_token)->toBe(LINKEDIN_TEST_TOKEN)
            ->and($channel->refresh_token)->toBeNull()
            ->and($channel->scopes)->toBe(['openid', 'profile', 'w_member_social'])
            // Sixty days from now, in UTC.
            ->and($channel->token_expires_at?->toIso8601String())->toBe('2027-01-24T10:00:00+00:00')
            ->and($channel->token_expires_at?->getTimezone()->getName())->toBe('UTC')
            // New to this workspace: saved, but not able to publish until
            // the caller has had it confirmed.
            ->and($channel->status)->toBe(ChannelStatus::Pending)
            ->and($channel->wasRecentlyCreated)->toBeTrue()
            ->and($channel->canPublish())->toBeFalse();

        $stored = DB::table('channels')->where('id', $channel->id)->value('access_token');

        expect($stored)->not->toContain('test-linkedin')
            ->and(Crypt::decryptString($stored))->toBe(LINKEDIN_TEST_TOKEN);
    });

    it('makes the two documented calls, with the secret in a form body and the token in a header', function () {
        fakeLinkedIn();

        ($this->connect)();

        $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0])->values();

        expect($requests)->toHaveCount(2);

        expect($requests[0]->method())->toBe('POST')
            // Nothing in the URL: the code and the secret travel in the body.
            ->and($requests[0]->url())->toBe(LINKEDIN_TEST_WEB.'/oauth/v2/accessToken')
            ->and($requests[0]->isForm())->toBeTrue()
            ->and($requests[0]->data())->toBe([
                'grant_type' => 'authorization_code',
                'code' => 'test-auth-code',
                'client_id' => 'test-client-id',
                'client_secret' => LINKEDIN_TEST_SECRET,
                'redirect_uri' => 'https://noongun.test/auth/linkedin/callback',
            ])
            ->and($requests[0]->hasHeader('Authorization'))->toBeFalse();

        expect($requests[1]->method())->toBe('GET')
            ->and($requests[1]->url())->toBe(LINKEDIN_TEST_API.'/v2/userinfo')
            ->and(linkedInHeader($requests[1], 'Authorization'))->toBe('Bearer '.LINKEDIN_TEST_TOKEN);
    });

    it('stores a refresh token, encrypted, when LinkedIn issues one', function () {
        fakeLinkedIn(['token' => 'oauth_token_with_refresh']);

        $channel = ($this->connect)()->sole();
        $stored = DB::table('channels')->where('id', $channel->id)->value('refresh_token');

        expect($channel->refresh_token)->toBe('test-linkedin-refresh-token')
            ->and($stored)->not->toContain('test-linkedin')
            ->and(Crypt::decryptString($stored))->toBe('test-linkedin-refresh-token');
    });

    it('renews an existing channel instead of adding a second row', function (string $state, ChannelStatus $expected) {
        $existing = inWorkspace($this->workspace, fn () => Channel::factory()->platform('linkedin')->{$state}()->create([
            'external_id' => LINKEDIN_TEST_MEMBER,
            'display_name' => 'Old name',
            'scopes' => ['openid'],
        ]));

        fakeLinkedIn();

        $channel = ($this->connect)()->sole();

        expect($channel->id)->toBe($existing->id)
            ->and($channel->display_name)->toBe('Thandi Mokoena')
            ->and($channel->access_token)->toBe(LINKEDIN_TEST_TOKEN)
            ->and($channel->token_expires_at?->toDateString())->toBe('2027-01-24')
            ->and($channel->wasRecentlyCreated)->toBeFalse()
            ->and($channel->status)->toBe($expected)
            ->and($channel->canPublish())->toBe($expected === ChannelStatus::Active)
            ->and($channel->scopes)->toContain('w_member_social')
            ->and(DB::table('channels')->count())->toBe(1);
    })->with([
        // Chosen by the user and never disconnected: renewed, active at once.
        'one that needs a reconnect' => ['needsReconnect', ChannelStatus::Active],
        'an active one whose token expired' => ['expired', ChannelStatus::Active],
        // Not connected by the user's last word on it: waits for a confirmation.
        'one the user disconnected' => ['disconnected', ChannelStatus::Pending],
        'one that is still pending' => ['pending', ChannelStatus::Pending],
    ]);

    it('keeps a Facebook channel with the same external id apart', function () {
        $facebook = inWorkspace($this->workspace, fn () => Channel::factory()->create(['external_id' => LINKEDIN_TEST_MEMBER, 'access_token' => 'a-page-token']));

        fakeLinkedIn();

        $channel = ($this->connect)()->sole();

        expect($channel->id)->not->toBe($facebook->id)
            ->and(inWorkspace($this->workspace, fn () => $facebook->fresh())->access_token)->toBe('a-page-token');
    });

    it('uses the current workspace of this moment, not one kept from when the adapter was built', function () {
        fakeLinkedIn();

        $adapter = linkedInAdapter();
        $adapter->connect($this->workspace, 'test-auth-code');

        app()->forgetScopedInstances();

        expect(linkedInAdapter())->toBe($adapter);

        $other = Workspace::factory()->create();
        $channel = linkedInAdapter()->connect($other, 'test-auth-code')->sole();

        expect($channel->workspace_id)->toBe($other->id)
            ->and(DB::table('channels')->where('workspace_id', $other->id)->count())->toBe(1)
            ->and(DB::table('channels')->where('workspace_id', $this->workspace->id)->count())->toBe(1);
    });

    it('keeps the same member apart in two workspaces', function () {
        $other = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
        $theirs = inWorkspace($other, fn () => Channel::factory()->platform('linkedin')->create([
            'external_id' => LINKEDIN_TEST_MEMBER,
            'access_token' => 'their-own-token',
            'display_name' => 'Theirs',
        ]));

        fakeLinkedIn();

        $mine = ($this->connect)()->sole();
        $theirs = inWorkspace($other, fn () => $theirs->fresh());

        expect($mine->id)->not->toBe($theirs->id)
            ->and($mine->workspace_id)->toBe($this->workspace->id)
            ->and($theirs->access_token)->toBe('their-own-token')
            ->and($theirs->display_name)->toBe('Theirs');
    });

    it('saves nothing when the code is refused', function () {
        fakeLinkedIn(['token' => linkedInResponse('error_code_used', 401)]);

        try {
            ($this->connect)();
            $this->fail('Expected a ChannelException.');
        } catch (ChannelException $exception) {
            expect($exception->retryable)->toBeFalse()
                // A refused sign-in says nothing about a stored token.
                ->and($exception->requiresReconnect)->toBeFalse()
                // In plain words with a next step. What LinkedIn said is
                // kept for the log and is not in the message.
                ->and($exception->getMessage())->toContain('LinkedIn refused the sign-in.')->toContain('Start again from the Channels page')
                ->and($exception->getMessage())->not->toContain('authorization code')->not->toContain('invalid_request')
                ->and($exception->context())->toMatchArray([
                    'endpoint' => 'POST /oauth/v2/accessToken',
                    'http_status' => 401,
                    'code' => 'invalid_request',
                    'platform_message' => 'Unable to retrieve access token: authorization code not found',
                ]);
        }

        Http::assertSentCount(1);
        expect(DB::table('channels')->count())->toBe(0);
    });

    it('saves nothing when the answer names its scopes and the one for posting is not among them', function () {
        fakeLinkedIn(['token' => 'oauth_token_missing_scope']);

        try {
            ($this->connect)();
            $this->fail('Expected a ChannelException.');
        } catch (ChannelException $exception) {
            // Says what to do, without the name of a scope.
            expect($exception->getMessage())->toContain('permission to post')->toContain('Share on LinkedIn')
                ->not->toContain('w_member_social')
                ->and($exception->context())->toMatchArray(['missing_scope' => 'w_member_social', 'granted_scopes' => ['openid', 'profile']]);
        }

        // The member is not even asked for: the token is dropped.
        Http::assertSentCount(1);
        expect(DB::table('channels')->count())->toBe(0);
    });

    it('connects when the answer lists only the scope for posting, because userinfo proves the other two', function () {
        fakeLinkedIn(['token' => Http::response([...linkedInFixture('oauth_token'), 'scope' => 'w_member_social'])]);

        $channel = ($this->connect)()->sole();

        expect($channel->scopes)->toBe(['w_member_social'])
            ->and($channel->external_id)->toBe(LINKEDIN_TEST_MEMBER);

        Http::assertSentCount(2);
    });

    it('connects when the answer names no scopes at all', function (array $answer) {
        fakeLinkedIn(['token' => Http::response(['access_token' => LINKEDIN_TEST_TOKEN, 'expires_in' => 5184000, ...$answer])]);

        $channel = ($this->connect)()->sole();

        expect($channel->scopes)->toBe([])
            ->and($channel->access_token)->toBe(LINKEDIN_TEST_TOKEN);
    })->with([
        'no scope field' => [[]],
        'an empty scope field' => [['scope' => '']],
        'a scope field that is not text' => [['scope' => ['w_member_social']]],
    ]);

    it('takes the scope for posting from the settings', function () {
        configureLinkedIn(['publish_scope' => 'w_something_else']);
        fakeLinkedIn();

        expect(fn () => ($this->connect)())->toThrow(ChannelException::class, 'permission to post');
    });

    it('reads granted scopes whether they are separated by spaces or commas', function () {
        fakeLinkedIn(['token' => Http::response([...linkedInFixture('oauth_token'), 'scope' => 'openid,profile,w_member_social'])]);

        expect(($this->connect)()->sole()->scopes)->toBe(['openid', 'profile', 'w_member_social']);
    });

    it('saves nothing when the answer holds no access token', function () {
        fakeLinkedIn(['token' => Http::response(['expires_in' => 5184000, 'scope' => 'openid profile w_member_social'])]);

        expect(fn () => ($this->connect)())->toThrow(ChannelException::class, 'without giving access');
        expect(DB::table('channels')->count())->toBe(0);
    });

    it('does not trust what LinkedIn says about the member', function (array $profile, ?string $name) {
        fakeLinkedIn(['userinfo' => Http::response($profile)]);

        if ($name === null) {
            expect(fn () => ($this->connect)())->toThrow(ChannelException::class, 'did not say who signed in');
            expect(DB::table('channels')->count())->toBe(0);

            return;
        }

        expect(($this->connect)()->sole()->display_name)->toBe($name);
    })->with([
        'no sub' => [['name' => 'Someone'], null],
        'a sub that would change the URN' => [['sub' => 'abc:def)', 'name' => 'Someone'], null],
        'a sub that is not text' => [['sub' => ['abc'], 'name' => 'Someone'], null],
        'a sub that ends in a line break' => [['sub' => "abc123\n", 'name' => 'Someone'], null],
        'no name: built from its parts' => [['sub' => 'abc123', 'given_name' => 'Thandi', 'family_name' => 'Mokoena'], 'Thandi Mokoena'],
        'no name at all' => [['sub' => 'abc123'], 'LinkedIn member'],
        'a name that is not text' => [['sub' => 'abc123', 'name' => ['<script>']], 'LinkedIn member'],
        'a very long name is cut' => [['sub' => 'abc123', 'name' => str_repeat('n', 400)], str_repeat('n', 255)],
        // Kept as text. The pages show it through Vue, which escapes it.
        'markup in the name stays text' => [['sub' => 'abc123', 'name' => '<img src=x onerror=alert(1)>'], '<img src=x onerror=alert(1)>'],
    ]);

    it('reports a failed call as retryable, because signing in again does no harm', function (string $endpoint, Closure $response) {
        fakeLinkedIn([$endpoint => $response]);

        expect(fn () => ($this->connect)())->toThrow(
            fn (ChannelException $exception) => expect($exception->retryable)->toBeTrue()
                ->and($exception->context())->not->toHaveKey('outcome_unknown'),
        );

        expect(DB::table('channels')->count())->toBe(0);
    })->with(['token', 'userinfo'])->with(linkedInAmbiguousFailures());

    it('keeps the code, the token and the secret out of a failure', function (string $endpoint) {
        // The worst case: LinkedIn repeats the whole request in its message.
        fakeLinkedIn([$endpoint => fn (Request $request) => Http::response([
            'message' => 'Bad request to '.$request->url().' with '.implode(' ', $request->data()).' and '.implode(' ', $request->header('Authorization')),
            'status' => 400,
        ], 400)]);

        try {
            ($this->connect)();
            $this->fail('Expected a ChannelException.');
        } catch (ChannelException $exception) {
            $everything = $exception->getMessage().json_encode($exception->context()).$exception->getTraceAsString();

            expect($everything)
                ->not->toContain('test-auth-code')
                ->not->toContain(LINKEDIN_TEST_SECRET)
                ->not->toContain(LINKEDIN_TEST_TOKEN)
                ->and($exception->getPrevious())->toBeNull();
        }
    })->with(['token', 'userinfo']);
});
