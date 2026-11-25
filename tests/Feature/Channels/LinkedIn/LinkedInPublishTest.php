<?php

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Exceptions\ChannelException;
use App\Domain\Channels\Exceptions\PublishFailed;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Workspaces\Models\Workspace;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../../Support/linkedin.php';

beforeEach(function () {
    configureLinkedIn();
    Http::preventStrayRequests();

    $this->workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->channel = inWorkspace($this->workspace, fn () => Channel::factory()->platform('linkedin')->create([
        'external_id' => LINKEDIN_TEST_MEMBER,
        'display_name' => 'Thandi Mokoena',
        'access_token' => LINKEDIN_TEST_TOKEN,
        'scopes' => ['openid', 'profile', 'w_member_social'],
        'token_expires_at' => '2027-01-24 10:00:00',
    ]));

    $this->variant = function (array $attributes = []): PostVariant {
        return inWorkspace($this->workspace, fn () => PostVariant::factory()->for($this->channel)->create([
            'body' => 'Fresh beans arrive on Thursday.',
            ...$attributes,
        ]));
    };

    $this->publish = fn (PostVariant $variant) => inWorkspace($this->workspace, fn () => linkedInAdapter()->publish($variant));
    $this->published = fn () => inWorkspace($this->workspace, fn () => PostVariant::factory()->for($this->channel)->published(LINKEDIN_TEST_POST)->create());
});

describe('publish', function () {
    it('makes one call to the Posts API as the member and returns the URN and permalink', function () {
        fakeLinkedIn();
        $this->travelTo('2026-11-25 10:15:00');

        $result = ($this->publish)(($this->variant)());

        expect($result->externalId)->toBe(LINKEDIN_TEST_POST)
            ->and($result->permalink)->toBe(LINKEDIN_TEST_WEB.'/feed/update/'.LINKEDIN_TEST_POST.'/')
            ->and($result->publishedAt->toIso8601String())->toBe('2026-11-25T10:15:00+00:00');

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                // Nothing in the URL: the token is a header.
                && $request->url() === LINKEDIN_TEST_API.'/rest/posts'
                && $request->isJson()
                && linkedInHeader($request, 'Authorization') === 'Bearer '.LINKEDIN_TEST_TOKEN
                // From the configuration, not from the adapter.
                && linkedInHeader($request, 'Linkedin-Version') === '209901'
                && linkedInHeader($request, 'X-Restli-Protocol-Version') === '2.0.0'
                && $request->data() === [
                    'author' => 'urn:li:person:'.LINKEDIN_TEST_MEMBER,
                    'commentary' => 'Fresh beans arrive on Thursday.',
                    'visibility' => 'PUBLIC',
                    'distribution' => [
                        'feedDistribution' => 'MAIN_FEED',
                        'targetEntities' => [],
                        'thirdPartyDistributionChannels' => [],
                    ],
                    'lifecycleState' => 'PUBLISHED',
                    'isReshareDisabledByAuthor' => false,
                ];
        });
    });

    it('accepts a ugcPost URN as well as a share URN', function () {
        fakeLinkedIn(['posts' => linkedInCreated('urn:li:ugcPost:7300000000000000002')]);

        $result = ($this->publish)(($this->variant)());

        expect($result->externalId)->toBe('urn:li:ugcPost:7300000000000000002')
            ->and($result->permalink)->toBe(LINKEDIN_TEST_WEB.'/feed/update/urn:li:ugcPost:7300000000000000002/');
    });

    it('does not write to the variant', function () {
        fakeLinkedIn();

        $variant = ($this->variant)();
        ($this->publish)($variant);

        $fresh = inWorkspace($this->workspace, fn () => $variant->fresh());

        expect($fresh->external_id)->toBeNull()
            ->and($fresh->permalink)->toBeNull()
            ->and($fresh->published_at)->toBeNull();
    });

    it('escapes what the commentary format reads as syntax, and keeps hashtags', function () {
        fakeLinkedIn();

        ($this->publish)(($this->variant)(['body' => "Prices (ZAR) are up 5% * see [the list] #coffee\nMail us @ a_b@example.com"]));

        Http::assertSent(fn (Request $request) => $request['commentary']
            === "Prices \\(ZAR\\) are up 5% \\* see \\[the list\\] #coffee\nMail us \\@ a\\_b\\@example.com");
    });

    it('sends a link as an article with the title and description the author gave, and the UTM values', function () {
        fakeLinkedIn();

        ($this->publish)(($this->variant)([
            'link' => 'https://example.com/article?ref=home&utm_source=newsletter#top',
            'link_title' => 'Why (and how) we roast',
            'link_description' => 'A short read.',
            'utm' => ['utm_source' => 'linkedin', 'utm_medium' => 'social'],
        ]));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['content'] === ['article' => [
            'source' => 'https://example.com/article?utm_source=newsletter&utm_medium=social&ref=home#top',
            // Plain text fields: not in the commentary format, so not escaped.
            'title' => 'Why (and how) we roast',
            'description' => 'A short read.',
        ]] && $request['commentary'] === 'Fresh beans arrive on Thursday.');
    });

    it('leaves the description out when there is none', function () {
        fakeLinkedIn();

        ($this->publish)(($this->variant)(['link' => 'https://example.com/article', 'link_title' => 'Why we roast', 'link_description' => '  ']));

        Http::assertSent(fn (Request $request) => $request['content'] === ['article' => [
            'source' => 'https://example.com/article',
            'title' => 'Why we roast',
        ]]);
    });

    it('never fetches the linked page', function () {
        fakeLinkedIn();

        ($this->publish)(($this->variant)(['link' => 'https://internal.example/admin', 'link_title' => 'A title']));

        // fakeLinkedIn() fails the test on any call that is not to LinkedIn.
        Http::assertSentCount(1);
    });

    it('refuses a link without a title before anything is sent', function () {
        fakeLinkedIn();

        expect(fn () => ($this->publish)(($this->variant)(['link' => 'https://example.com/article'])))->toThrow(
            fn (PublishFailed $exception) => expect($exception->retryable)->toBeFalse()
                ->and($exception->getMessage())->toContain('The link has no title')
                ->and($exception->context())->not->toHaveKey('outcome_unknown'),
        );

        Http::assertNothingSent();
    });

    it('maps each kind of refusal', function (Closure $response, bool $retryable, bool $reconnect, ?int $retryAfter, array $context) {
        fakeLinkedIn(['posts' => $response]);

        try {
            ($this->publish)(($this->variant)());
            $this->fail('Expected PublishFailed.');
        } catch (ChannelException $exception) {
            expect($exception)->toBeInstanceOf(PublishFailed::class)
                ->and($exception->platform)->toBe('linkedin')
                ->and($exception->retryable)->toBe($retryable)
                ->and($exception->requiresReconnect)->toBe($reconnect)
                ->and($exception->retryAfterSeconds)->toBe($retryAfter)
                ->and($exception->context())->toMatchArray(['endpoint' => 'POST /rest/posts', ...$context])
                // Each of these is a definite answer: nothing was posted.
                ->and($exception->context())->not->toHaveKey('outcome_unknown');
        }

        // One call, whatever the outcome: the adapter never retries by itself.
        Http::assertSentCount(1);
    })->with([
        'expired token: reconnect' => [fn () => linkedInResponse('error_token_expired', 401), false, true, null, ['http_status' => 401, 'code' => 'EXPIRED_ACCESS_TOKEN', 'service_error_code' => 65601]],
        'revoked token: reconnect' => [fn () => linkedInResponse('error_token_revoked', 401), false, true, null, ['http_status' => 401]],
        '401 that cannot be read: still a refusal, reconnect' => [fn () => Http::response('Unauthorized', 401), false, true, null, ['http_status' => 401]],
        'missing scope: reconnect' => [fn () => linkedInResponse('error_permission', 403), false, true, null, ['http_status' => 403, 'code' => 'ACCESS_DENIED']],
        'validation: permanent' => [fn () => linkedInResponse('error_validation', 422), false, false, null, ['http_status' => 422, 'code' => 'UNPROCESSABLE_ENTITY']],
        'text too long: permanent' => [fn () => linkedInResponse('error_field_too_long', 400), false, false, null, ['http_status' => 400, 'code' => 'FIELD_LENGTH_TOO_LONG']],
        'sunset API version: permanent' => [fn () => linkedInResponse('error_version', 426), false, false, null, ['http_status' => 426, 'code' => 'NONEXISTENT_VERSION']],
        'throttled: retry' => [fn () => linkedInResponse('error_rate_limit', 429), true, false, null, ['http_status' => 429, 'code' => 'TOO_MANY_REQUESTS']],
        'throttled with advice: retry after it' => [fn () => linkedInResponse('error_rate_limit', 429, ['Retry-After' => '120']), true, false, 120, ['http_status' => 429]],
    ]);

    it('says how to fix a sunset API version', function () {
        fakeLinkedIn(['posts' => linkedInResponse('error_version', 426)]);

        expect(fn () => ($this->publish)(($this->variant)()))->toThrow(PublishFailed::class, 'LINKEDIN_API_VERSION');
    });

    it('never marks a failure as retryable when the post may have been made', function (Closure $response, array $context) {
        fakeLinkedIn(['posts' => $response]);

        try {
            ($this->publish)(($this->variant)());
            $this->fail('Expected PublishFailed.');
        } catch (PublishFailed $exception) {
            expect($exception->retryable)->toBeFalse()
                ->and($exception->requiresReconnect)->toBeFalse()
                ->and($exception->retryAfterSeconds)->toBeNull()
                ->and($exception->getMessage())->toContain('Check the profile before trying again.')
                ->and($exception->context())->toMatchArray(['endpoint' => 'POST /rest/posts', 'outcome_unknown' => true, ...$context])
                ->and($exception->getPrevious())->toBeNull();
        }
    })->with([
        'timeout while waiting for the answer' => [fn (Request $request) => throw linkedInConnectionFailure($request, NetworkTimeoutException::class, 'Operation timed out after 30001 milliseconds'), []],
        'timeout that is reported as a connect timeout' => [fn (Request $request) => throw linkedInConnectionFailure($request, ConnectTimeoutException::class, 'Connection timed out after 10001 milliseconds'), []],
        'connection reset after sending' => [fn (Request $request) => throw linkedInConnectionFailure($request, NetworkException::class, 'Recv failure: Connection reset by peer'), []],
        'network failure with no detail' => [fn (Request $request) => throw new ConnectionException('Something went wrong for '.$request->url()), []],
        'server error' => [fn () => linkedInResponse('error_server', 500), ['http_status' => 500, 'code' => 'INTERNAL_SERVER_ERROR']],
        'service unavailable without JSON' => [fn () => Http::response('<html>Unavailable</html>', 503), ['http_status' => 503, 'code' => null]],
        'gateway timeout' => [fn () => Http::response('', 504), ['http_status' => 504]],
        'write conflict' => [fn () => linkedInResponse('error_conflict', 409), ['http_status' => 409]],
        'HTTP 429 that is not in the format of LinkedIn' => [fn () => Http::response('Too many requests', 429, ['Retry-After' => '30']), ['http_status' => 429]],
        'HTTP 400 that cannot be read' => [fn () => Http::response('<html>Bad request</html>', 400), ['http_status' => 400]],
        'a redirect, which is not followed' => [fn () => Http::response('', 302, ['Location' => 'https://elsewhere.test/rest/posts']), ['http_status' => 302]],
        'success without the id header' => [fn () => Http::response('', 201), ['http_status' => 201]],
        'success with an id that is not a URN' => [fn () => Http::response('', 201, ['x-restli-id' => 'javascript:alert(1)']), ['http_status' => 201]],
        'success with a URN that could break out of the permalink' => [fn () => Http::response('', 201, ['x-restli-id' => 'urn:li:share:1/../../evil?x=']), ['http_status' => 201]],
    ]);

    it('retries when the connection was never made, because nothing was sent', function (string $text) {
        fakeLinkedIn(['posts' => fn (Request $request) => throw linkedInConnectionFailure($request, ConnectException::class, $text)]);

        try {
            ($this->publish)(($this->variant)());
            $this->fail('Expected PublishFailed.');
        } catch (PublishFailed $exception) {
            expect($exception->retryable)->toBeTrue()
                ->and($exception->context())->not->toHaveKey('outcome_unknown')
                ->and($exception->getPrevious())->toBeNull()
                ->and($exception->getMessage())->not->toContain('Bearer')->not->toContain('api.linkedin');
        }
    })->with([
        'host not resolved' => ['Could not resolve host: api.linkedin.test'],
        'connection refused' => ['Failed to connect to api.linkedin.test port 443'],
    ]);

    it('keeps the token and the secret out of the message, the context and the trace', function (Closure $response) {
        fakeLinkedIn(['posts' => $response]);

        try {
            ($this->publish)(($this->variant)());
            $this->fail('Expected PublishFailed.');
        } catch (PublishFailed $exception) {
            $everything = $exception->getMessage().json_encode($exception->context()).$exception->getTraceAsString();

            expect($everything)
                ->not->toContain(LINKEDIN_TEST_TOKEN)
                ->not->toContain(LINKEDIN_TEST_SECRET)
                ->and($exception->getPrevious())->toBeNull();
        }
    })->with([
        'error that repeats the credentials' => [fn () => linkedInResponse('error_echoes_credentials', 400)],
        'error that repeats the request headers' => [fn (Request $request) => Http::response([
            'message' => 'Bad request: '.implode(' ', $request->header('Authorization')),
            'status' => 400,
        ], 400)],
        'network failure whose message holds the token' => [fn (Request $request) => throw new ConnectionException(
            'cURL error 6: Could not resolve host for '.$request->url().' Authorization: Bearer '.LINKEDIN_TEST_TOKEN,
        )],
    ]);

    it('asks for a reconnect without calling LinkedIn when the channel holds no token', function () {
        inWorkspace($this->workspace, fn () => $this->channel->disconnect());

        $variant = ($this->variant)();

        expect(fn () => ($this->publish)($variant->unsetRelation('channel')))->toThrow(
            fn (PublishFailed $exception) => expect($exception->requiresReconnect)->toBeTrue(),
        );

        Http::assertNothingSent();
    });

    it('refuses a variant of another platform and a stored member id that is not one, without a call', function () {
        $facebook = inWorkspace($this->workspace, fn () => PostVariant::factory()->create());

        expect(fn () => ($this->publish)($facebook))->toThrow(PublishFailed::class, 'not addressed to a LinkedIn profile');

        DB::table('channels')->where('id', $this->channel->id)->update(['external_id' => 'abc)(urn:li:organization:1']);

        expect(fn () => ($this->publish)(($this->variant)()))->toThrow(PublishFailed::class, 'member id is not valid');

        Http::assertNothingSent();
    });

    it('fails without a call when the instance is not configured', function () {
        $variant = ($this->variant)();
        configureLinkedIn(['client_secret' => null]);

        expect(fn () => ($this->publish)($variant))->toThrow(PublishFailed::class, 'LINKEDIN_CLIENT_SECRET');

        Http::assertNothingSent();
    });

    it('cannot reach the channel of another workspace', function () {
        $other = Workspace::factory()->create();
        $variant = ($this->variant)();

        // Read from inside another workspace, the variant has no channel.
        expect(fn () => inWorkspace($other, fn () => linkedInAdapter()->publish($variant->unsetRelation('channel'))))
            ->toThrow(PublishFailed::class, 'not addressed to a LinkedIn profile');

        Http::assertNothingSent();
    });
});

describe('delete', function () {
    it('deletes the post by its encoded URN with the member token', function () {
        fakeLinkedIn();

        $variant = ($this->published)();

        inWorkspace($this->workspace, fn () => linkedInAdapter()->delete($variant));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && $request->url() === LINKEDIN_TEST_API.'/rest/posts/urn%3Ali%3Ashare%3A7300000000000000001'
            && linkedInHeader($request, 'Authorization') === 'Bearer '.LINKEDIN_TEST_TOKEN
            && linkedInHeader($request, 'Linkedin-Version') === '209901'
            && linkedInHeader($request, 'X-Restli-Protocol-Version') === '2.0.0'
            && linkedInHeader($request, 'X-RestLi-Method') === 'DELETE');

        expect(inWorkspace($this->workspace, fn () => $variant->fresh())->external_id)->toBe(LINKEDIN_TEST_POST);
    });

    it('reports a failure as a ChannelException without the token', function () {
        fakeLinkedIn(['delete' => linkedInResponse('error_token_expired', 401)]);

        $variant = ($this->published)();

        try {
            inWorkspace($this->workspace, fn () => linkedInAdapter()->delete($variant));
            $this->fail('Expected a ChannelException.');
        } catch (ChannelException $exception) {
            expect($exception)->not->toBeInstanceOf(PublishFailed::class)
                ->and($exception->requiresReconnect)->toBeTrue()
                ->and($exception->getMessage().json_encode($exception->context()))->not->toContain(LINKEDIN_TEST_TOKEN);
        }
    });

    it('may be tried again after a failure that leaves the outcome open, because deleting twice does no harm', function (Closure $response) {
        fakeLinkedIn(['delete' => $response]);

        $variant = ($this->published)();

        expect(fn () => inWorkspace($this->workspace, fn () => linkedInAdapter()->delete($variant)))->toThrow(
            fn (ChannelException $exception) => expect($exception->retryable)->toBeTrue()
                ->and($exception->context())->not->toHaveKey('outcome_unknown'),
        );
    })->with(linkedInAmbiguousFailures());

    it('refuses a variant that was never published, without a call', function () {
        $variant = ($this->variant)();

        expect(fn () => inWorkspace($this->workspace, fn () => linkedInAdapter()->delete($variant)))
            ->toThrow(ChannelException::class, 'nothing to delete');

        Http::assertNothingSent();
    });
});

describe('refreshToken', function () {
    it('asks LinkedIn about the token and records what it reports', function () {
        fakeLinkedIn();
        $this->travelTo('2026-11-25 10:00:00');

        $channel = inWorkspace($this->workspace, fn () => linkedInAdapter()->refreshToken($this->channel));

        expect($channel->status)->toBe(ChannelStatus::Active)
            ->and($channel->token_expires_at?->getTimestamp())->toBe(1800784800)
            ->and($channel->scopes)->toBe(['openid', 'profile', 'w_member_social'])
            ->and($channel->access_token)->toBe(LINKEDIN_TEST_TOKEN)
            ->and($channel->isDirty())->toBeFalse();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === LINKEDIN_TEST_WEB.'/oauth/v2/introspectToken'
            && $request->isForm()
            && $request->data() === [
                'client_id' => 'test-client-id',
                'client_secret' => LINKEDIN_TEST_SECRET,
                'token' => LINKEDIN_TEST_TOKEN,
            ]);
    });

    it('marks the channel when the token cannot be used any more', function (string $fixture, string $now) {
        fakeLinkedIn(['introspect' => $fixture]);
        $this->travelTo($now);

        $channel = inWorkspace($this->workspace, fn () => linkedInAdapter()->refreshToken($this->channel));

        expect($channel->status)->toBe(ChannelStatus::NeedsReconnect)
            ->and(inWorkspace($this->workspace, fn () => $this->channel->fresh())->status)->toBe(ChannelStatus::NeedsReconnect);
    })->with([
        'revoked by the member' => ['introspect_revoked', '2026-11-25 10:00:00'],
        'the publishing permission is gone' => ['introspect_missing_scope', '2026-11-25 10:00:00'],
        'the expiry has passed' => ['introspect_active', '2027-03-01 10:00:00'],
    ]);

    it('keeps the recorded scopes when the answer names none', function () {
        $report = linkedInFixture('introspect_active');
        unset($report['scope']);
        fakeLinkedIn(['introspect' => Http::response($report)]);
        $this->travelTo('2026-11-25 10:00:00');

        $channel = inWorkspace($this->workspace, fn () => linkedInAdapter()->refreshToken($this->channel));

        expect($channel->status)->toBe(ChannelStatus::Active)
            ->and($channel->scopes)->toBe(['openid', 'profile', 'w_member_social']);
    });

    it('marks a channel that holds no token, without a call', function () {
        $channel = inWorkspace($this->workspace, fn () => Channel::factory()->platform('linkedin')->create(['access_token' => null]));

        expect(inWorkspace($this->workspace, fn () => linkedInAdapter()->refreshToken($channel))->status)
            ->toBe(ChannelStatus::NeedsReconnect);

        Http::assertNothingSent();
    });

    it('leaves a disconnected and a pending channel alone', function (string $state, ChannelStatus $status) {
        $channel = inWorkspace($this->workspace, fn () => Channel::factory()->platform('linkedin')->{$state}()->create());

        expect(inWorkspace($this->workspace, fn () => linkedInAdapter()->refreshToken($channel))->status)->toBe($status);

        Http::assertNothingSent();
    })->with([
        ['disconnected', ChannelStatus::Disconnected],
        ['pending', ChannelStatus::Pending],
    ]);

    it('reports a failed check as retryable and does not mark the channel', function (Closure $response) {
        fakeLinkedIn(['introspect' => $response]);

        expect(fn () => inWorkspace($this->workspace, fn () => linkedInAdapter()->refreshToken($this->channel)))->toThrow(
            fn (ChannelException $exception) => expect($exception->retryable)->toBeTrue()
                ->and($exception->context())->not->toHaveKey('outcome_unknown'),
        );

        expect(inWorkspace($this->workspace, fn () => $this->channel->fresh())->status)->toBe(ChannelStatus::Active);
    })->with(linkedInAmbiguousFailures());

    describe('with a refresh token', function () {
        beforeEach(function () {
            $this->travelTo('2026-11-25 10:00:00');
            inWorkspace($this->workspace, fn () => $this->channel->forceFill(['refresh_token' => 'test-linkedin-refresh-token'])->save());
        });

        it('exchanges it for a new access token and keeps it when LinkedIn sends none back', function () {
            $renewed = linkedInFixture('oauth_token_refreshed');
            unset($renewed['refresh_token']);
            fakeLinkedIn(['token' => Http::response($renewed)]);

            $channel = inWorkspace($this->workspace, fn () => linkedInAdapter()->refreshToken($this->channel));

            expect($channel->access_token)->toBe('test-linkedin-access-token-renewed')
                ->and($channel->refresh_token)->toBe('test-linkedin-refresh-token')
                ->and($channel->token_expires_at?->toDateString())->toBe('2027-01-24')
                ->and($channel->scopes)->toBe(['openid', 'profile', 'w_member_social'])
                ->and($channel->status)->toBe(ChannelStatus::Active);

            Http::assertSentCount(1);
            Http::assertSent(fn (Request $request) => $request->url() === LINKEDIN_TEST_WEB.'/oauth/v2/accessToken'
                && $request->data() === [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => 'test-linkedin-refresh-token',
                    'client_id' => 'test-client-id',
                    'client_secret' => LINKEDIN_TEST_SECRET,
                ]);
        });

        it('asks for a reconnect when the refresh token is refused', function () {
            fakeLinkedIn(['token' => linkedInResponse('error_refresh_token_invalid', 400)]);

            $channel = inWorkspace($this->workspace, fn () => linkedInAdapter()->refreshToken($this->channel));

            expect($channel->status)->toBe(ChannelStatus::NeedsReconnect)
                ->and($channel->access_token)->toBe(LINKEDIN_TEST_TOKEN);
        });

        it('leaves the channel as it is when LinkedIn cannot be reached', function () {
            fakeLinkedIn(['token' => linkedInResponse('error_server', 500)]);

            expect(fn () => inWorkspace($this->workspace, fn () => linkedInAdapter()->refreshToken($this->channel)))
                ->toThrow(fn (ChannelException $exception) => expect($exception->retryable)->toBeTrue());

            expect(inWorkspace($this->workspace, fn () => $this->channel->fresh())->status)->toBe(ChannelStatus::Active);
        });
    });
});
