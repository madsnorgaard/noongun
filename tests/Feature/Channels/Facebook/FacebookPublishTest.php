<?php

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Exceptions\ChannelException;
use App\Domain\Channels\Exceptions\PublishFailed;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../../Support/facebook.php';

const PAGE_TOKEN = 'test-page-token-table-bay-coffee';

beforeEach(function () {
    configureFacebook();
    Http::preventStrayRequests();

    $this->workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->channel = inWorkspace($this->workspace, fn () => Channel::factory()->create([
        'external_id' => '100000000000001',
        'display_name' => 'Table Bay Coffee',
        'access_token' => PAGE_TOKEN,
    ]));

    $this->variant = function (array $attributes = []): PostVariant {
        return inWorkspace($this->workspace, fn () => PostVariant::factory()->for($this->channel)->create([
            'body' => 'Fresh beans arrive on Thursday.',
            ...$attributes,
        ]));
    };

    $this->publish = fn (PostVariant $variant) => inWorkspace($this->workspace, fn () => facebookAdapter()->publish($variant));
});

describe('publish', function () {
    it('makes one call to the Page feed and returns the post id and permalink', function () {
        fakeGraph();
        $this->travelTo('2026-11-24 10:15:00');

        $variant = ($this->variant)();
        $result = ($this->publish)($variant);

        expect($result->externalId)->toBe('100000000000001_900000000000001')
            ->and($result->permalink)->toBe('https://www.facebook.test/100000000000001_900000000000001')
            ->and($result->publishedAt->toIso8601String())->toBe('2026-11-24T10:15:00+00:00');

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                // Nothing in the URL: a POST carries the token in its body.
                && $request->url() === FACEBOOK_TEST_GRAPH.'/100000000000001/feed'
                && $request->isForm()
                && $request->data() === [
                    'message' => 'Fresh beans arrive on Thursday.',
                    'access_token' => PAGE_TOKEN,
                    'appsecret_proof' => facebookProof(PAGE_TOKEN),
                ];
        });
    });

    it('does not write to the variant', function () {
        fakeGraph();

        $variant = ($this->variant)();
        ($this->publish)($variant);

        $fresh = inWorkspace($this->workspace, fn () => $variant->fresh());

        expect($fresh->external_id)->toBeNull()
            ->and($fresh->permalink)->toBeNull()
            ->and($fresh->published_at)->toBeNull();
    });

    it('sends the link with its UTM values, keeping what the link already has', function () {
        fakeGraph();

        ($this->publish)(($this->variant)([
            'link' => 'https://example.com/article?ref=home&utm_source=newsletter#top',
            'utm' => ['utm_source' => 'facebook', 'utm_medium' => 'social', 'utm_campaign' => 'spring launch'],
        ]));

        Http::assertSent(fn (Request $request) => $request['link']
            === 'https://example.com/article?utm_source=newsletter&utm_medium=social&utm_campaign=spring%20launch&ref=home#top');
    });

    it('sends a link without UTM values unchanged', function () {
        fakeGraph();

        ($this->publish)(($this->variant)(['link' => 'https://example.com/article', 'utm' => null]));

        Http::assertSent(fn (Request $request) => $request['link'] === 'https://example.com/article');
    });

    it('maps each kind of Graph failure', function (Closure $response, bool $retryable, bool $reconnect, ?int $retryAfter, array $context) {
        fakeGraph(['feed' => $response]);

        try {
            ($this->publish)(($this->variant)());
            $this->fail('Expected PublishFailed.');
        } catch (ChannelException $exception) {
            expect($exception)->toBeInstanceOf(PublishFailed::class)
                ->and($exception->platform)->toBe('facebook')
                ->and($exception->retryable)->toBe($retryable)
                ->and($exception->requiresReconnect)->toBe($reconnect)
                ->and($exception->retryAfterSeconds)->toBe($retryAfter)
                ->and($exception->context())->toMatchArray(['endpoint' => 'POST /100000000000001/feed', ...$context]);
        }

        // One call, whatever the outcome: the adapter never retries by itself.
        Http::assertSentCount(1);
    })->with([
        'expired token: reconnect' => [fn () => facebookResponse('error_token_expired', 400), false, true, null, ['code' => 190, 'subcode' => 463, 'http_status' => 400]],
        'missing permission: reconnect' => [fn () => facebookResponse('error_permission', 403), false, true, null, ['code' => 200]],
        'Page rate limit: retry after the time Facebook gives' => [
            fn () => facebookResponse('error_rate_limit', 400, ['X-Business-Use-Case-Usage' => json_encode([
                '100000000000001' => [['type' => 'pages', 'call_count' => 100, 'total_cputime' => 25, 'total_time' => 25, 'estimated_time_to_regain_access' => 12]],
            ])]),
            true, false, 720, ['code' => 80001],
        ],
        'rate limit without advice: retry' => [fn () => facebookResponse('error_rate_limit', 400), true, false, null, ['code' => 80001]],
        'HTTP 429 with Retry-After: retry' => [fn () => Http::response('Too many requests', 429, ['Retry-After' => '30']), true, false, 30, ['http_status' => 429]],
        'service error: retry' => [fn () => facebookResponse('error_service', 500), true, false, null, ['code' => 2, 'http_status' => 500]],
        'gateway error without JSON: retry' => [fn () => Http::response('<html>Bad gateway</html>', 502), true, false, null, ['http_status' => 502, 'code' => null]],
        'validation error: permanent' => [fn () => facebookResponse('error_validation', 400), false, false, null, ['code' => 100]],
        'duplicate post: permanent' => [fn () => facebookResponse('error_duplicate_post', 400), false, false, null, ['code' => 506]],
        'unreadable success: permanent, the post may be public' => [fn () => Http::response('OK', 200), false, false, null, ['http_status' => 200]],
        'success without an id: permanent, the post may be public' => [fn () => Http::response(['success' => true], 200), false, false, null, []],
    ]);

    it('treats a network failure as retryable', function () {
        fakeGraph(['feed' => fn (Request $request) => throw new ConnectionException(
            'cURL error 28: Operation timed out after 30001 milliseconds for '.$request->url().'?access_token='.PAGE_TOKEN,
        )]);

        try {
            ($this->publish)(($this->variant)());
            $this->fail('Expected PublishFailed.');
        } catch (PublishFailed $exception) {
            expect($exception->retryable)->toBeTrue()
                ->and($exception->requiresReconnect)->toBeFalse()
                ->and($exception->getPrevious())->toBeNull()
                ->and($exception->getMessage())->not->toContain('access_token')->not->toContain('graph.facebook');
        }
    });

    it('keeps the token, the proof and the secret out of the message, the context and the trace', function (Closure $response) {
        fakeGraph(['feed' => $response]);

        try {
            ($this->publish)(($this->variant)());
            $this->fail('Expected PublishFailed.');
        } catch (PublishFailed $exception) {
            $everything = $exception->getMessage().json_encode($exception->context()).$exception->getTraceAsString();

            expect($everything)
                ->not->toContain('test-page-token')
                ->not->toContain(FACEBOOK_TEST_APP_SECRET)
                ->not->toContain(facebookProof(PAGE_TOKEN))
                ->and($exception->getPrevious())->toBeNull();
        }
    })->with([
        'Graph error that repeats the credentials' => [fn () => facebookResponse('error_echoes_credentials', 400)],
        'Graph error that repeats the request' => [fn (Request $request) => Http::response(['error' => [
            'message' => 'Bad request: '.$request->body().' '.implode(' ', $request->data()),
            'code' => 100,
        ]], 400)],
        'network failure whose message holds the URL' => [fn (Request $request) => throw new ConnectionException(
            'cURL error 6: Could not resolve host for '.$request->url().'?access_token='.PAGE_TOKEN.'&appsecret_proof='.facebookProof(PAGE_TOKEN),
        )],
    ]);

    it('asks for a reconnect without calling Facebook when the channel holds no token', function () {
        inWorkspace($this->workspace, fn () => $this->channel->disconnect());

        $variant = ($this->variant)();

        expect(fn () => ($this->publish)($variant->unsetRelation('channel')))->toThrow(
            fn (PublishFailed $exception) => expect($exception->requiresReconnect)->toBeTrue(),
        );

        Http::assertNothingSent();
    });

    it('fails without a call when the instance is not configured', function () {
        $variant = ($this->variant)();
        configureFacebook(['app_secret' => null]);

        expect(fn () => ($this->publish)($variant))->toThrow(PublishFailed::class, 'FACEBOOK_APP_SECRET');

        Http::assertNothingSent();
    });
});

describe('delete', function () {
    it('deletes the post with the Page token', function () {
        fakeGraph();

        $variant = inWorkspace($this->workspace, fn () => PostVariant::factory()->for($this->channel)->published('100000000000001_900000000000001')->create());

        inWorkspace($this->workspace, fn () => facebookAdapter()->delete($variant));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_starts_with($request->url(), FACEBOOK_TEST_GRAPH.'/100000000000001_900000000000001?')
            && facebookQuery($request) === [
                'access_token' => PAGE_TOKEN,
                'appsecret_proof' => facebookProof(PAGE_TOKEN),
            ]);

        expect(inWorkspace($this->workspace, fn () => $variant->fresh())->external_id)->toBe('100000000000001_900000000000001');
    });

    it('reports a failure as a ChannelException without the token', function () {
        fakeGraph(['delete' => facebookResponse('error_token_expired', 400)]);

        $variant = inWorkspace($this->workspace, fn () => PostVariant::factory()->for($this->channel)->published('100000000000001_900000000000001')->create());

        try {
            inWorkspace($this->workspace, fn () => facebookAdapter()->delete($variant));
            $this->fail('Expected a ChannelException.');
        } catch (ChannelException $exception) {
            expect($exception)->not->toBeInstanceOf(PublishFailed::class)
                ->and($exception->requiresReconnect)->toBeTrue()
                ->and($exception->getMessage().json_encode($exception->context()))->not->toContain('test-page-token');
        }
    });

    it('refuses a variant that was never published, without a call', function () {
        $variant = ($this->variant)();

        expect(fn () => inWorkspace($this->workspace, fn () => facebookAdapter()->delete($variant)))
            ->toThrow(ChannelException::class, 'nothing to delete');

        Http::assertNothingSent();
    });
});

describe('refreshToken', function () {
    it('checks the token and records what Facebook reports', function () {
        fakeGraph(['debug' => 'debug_token_expiring']);
        $this->travelTo('2026-11-24 10:00:00');

        $channel = inWorkspace($this->workspace, fn () => facebookAdapter()->refreshToken($this->channel));

        expect($channel->status)->toBe(ChannelStatus::Active)
            ->and($channel->token_expires_at?->toIso8601String())->toBe('2027-01-01T00:00:00+00:00')
            ->and($channel->access_token)->toBe(PAGE_TOKEN)
            ->and($channel->isDirty())->toBeFalse();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => facebookQuery($request) === [
            'input_token' => PAGE_TOKEN,
            'access_token' => 'test-app-id|'.FACEBOOK_TEST_APP_SECRET,
        ]);
    });

    it('leaves a token without expiry as it is', function () {
        fakeGraph();

        $channel = inWorkspace($this->workspace, fn () => facebookAdapter()->refreshToken($this->channel));

        expect($channel->status)->toBe(ChannelStatus::Active)
            ->and($channel->token_expires_at)->toBeNull();
    });

    it('marks the channel when the token cannot be used any more', function (string $fixture) {
        fakeGraph(['debug' => $fixture]);
        $this->travelTo('2027-02-01 10:00:00');

        $channel = inWorkspace($this->workspace, fn () => facebookAdapter()->refreshToken($this->channel));

        expect($channel->status)->toBe(ChannelStatus::NeedsReconnect)
            ->and(inWorkspace($this->workspace, fn () => $this->channel->fresh())->status)->toBe(ChannelStatus::NeedsReconnect);
    })->with([
        'reported as not valid' => ['debug_token_invalid'],
        'a needed permission is gone' => ['debug_token_missing_scope'],
        'the expiry has passed' => ['debug_token_expiring'],
    ]);

    it('marks a channel that holds no token, without a call', function () {
        $channel = inWorkspace($this->workspace, fn () => Channel::factory()->create(['access_token' => null]));

        expect(inWorkspace($this->workspace, fn () => facebookAdapter()->refreshToken($channel))->status)
            ->toBe(ChannelStatus::NeedsReconnect);

        Http::assertNothingSent();
    });

    it('leaves a disconnected channel alone', function () {
        $channel = inWorkspace($this->workspace, fn () => Channel::factory()->disconnected()->create());

        expect(inWorkspace($this->workspace, fn () => facebookAdapter()->refreshToken($channel))->status)
            ->toBe(ChannelStatus::Disconnected);

        Http::assertNothingSent();
    });

    it('does not mark the channel when the check itself fails', function () {
        fakeGraph(['debug' => facebookResponse('error_service', 500)]);

        expect(fn () => inWorkspace($this->workspace, fn () => facebookAdapter()->refreshToken($this->channel)))
            ->toThrow(fn (ChannelException $exception) => expect($exception->retryable)->toBeTrue());

        expect(inWorkspace($this->workspace, fn () => $this->channel->fresh())->status)->toBe(ChannelStatus::Active);
    });
});
