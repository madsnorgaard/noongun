<?php

/*
 * No call to the Graph API follows a redirect. The HTTP client would send
 * the same request again to the address the answer names, and on a 307 or
 * 308 with its body: the Page token, its proof and the image.
 *
 * Http::fake() never follows a redirect, so two things are held here: that
 * every request is sent with redirects turned off, and that a 3xx answer
 * is a failure of the right kind.
 */

use App\Domain\Channels\Exceptions\ChannelException;
use App\Domain\Channels\Exceptions\PublishFailed;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../../Support/facebook.php';
require_once __DIR__.'/../../../Support/media.php';

beforeEach(function () {
    configureFacebook();
    fakeMediaDisk();
    Http::preventStrayRequests();

    $this->workspace = Workspace::factory()->create();
    $this->channel = inWorkspace($this->workspace, fn () => Channel::factory()->create([
        'external_id' => '100000000000001',
        'access_token' => 'test-page-token-table-bay-coffee',
    ]));

    $this->run = fn (Closure $call) => inWorkspace($this->workspace, $call);

    $this->variant = fn (): PostVariant => inWorkspace($this->workspace, fn () => PostVariant::factory()->for($this->channel)->create());

    $this->withImage = function (?string $photoId = null): PostVariant {
        $variant = ($this->variant)();
        $attachment = attachImage($variant, storedImage($this->workspace), 'A harbour.');

        if ($photoId !== null) {
            DB::table('post_variant_media')->where('id', $attachment->id)->update(['external_id' => $photoId, 'uploaded_at' => now()]);
        }

        return inWorkspace($this->workspace, fn () => PostVariant::query()->findOrFail($variant->id));
    };

    // Each kind of call the adapter makes, by the key fakeGraph() knows it by.
    $this->calls = [
        'code' => fn () => facebookAdapter()->connect($this->workspace, 'test-auth-code'),
        'exchange' => fn () => facebookAdapter()->connect($this->workspace, 'test-auth-code'),
        'accounts' => fn () => facebookAdapter()->connect($this->workspace, 'test-auth-code'),
        'debug' => fn () => ($this->run)(fn () => facebookAdapter()->refreshToken($this->channel)),
        'feed' => fn () => ($this->run)(fn () => facebookAdapter()->publish(($this->variant)())),
        'feed with images' => fn () => ($this->run)(fn () => facebookAdapter()->publish(($this->withImage)('800000000000001'))),
        'photos' => function () {
            $variant = ($this->withImage)();

            return ($this->run)(fn () => facebookAdapter()->uploadMedia($variant, $variant->images()->firstOrFail()));
        },
        'delete' => function () {
            $variant = ($this->run)(fn () => PostVariant::factory()->for($this->channel)->published('100000000000001_900000000000001')->create());

            return ($this->run)(fn () => facebookAdapter()->delete($variant));
        },
    ];
});

it('turns redirects off on every call it makes', function () {
    fakeGraph();

    foreach ($this->calls as $call) {
        $call();
    }

    $calls = facebookCalls();
    $kinds = array_unique(array_map(fn (array $call) => $call['method'].' '.basename($call['path']), $calls));
    sort($kinds);

    // Sign-in, the list of Pages, the token check, the post, the photo and the delete.
    expect($kinds)->toBe([
        'DELETE 100000000000001_900000000000001',
        'GET access_token',
        'GET accounts',
        'GET debug_token',
        'POST feed',
        'POST photos',
    ]);

    foreach ($calls as $call) {
        expect($call['options']['allow_redirects'] ?? null)->toBeFalse("{$call['method']} {$call['path']} would follow a redirect.");
    }
});

it('takes a redirect as a failure and sends nothing to the address it names', function (string $key, int $status, bool $retryable, bool $unknown) {
    $answered = 0;

    fakeGraph([explode(' ', $key)[0] => function () use (&$answered, $status) {
        $answered++;

        return Http::response('', $status, ['Location' => 'https://collector.evil.test/graph?keep=1']);
    }]);

    try {
        $this->calls[$key]();
        $this->fail('Expected a ChannelException.');
    } catch (ChannelException $exception) {
        expect($exception->retryable)->toBe($retryable)
            ->and($exception->requiresReconnect)->toBeFalse()
            ->and($exception->context()['outcome_unknown'] ?? false)->toBe($unknown)
            ->and($exception->context())->toMatchArray(['http_status' => $status])
            ->and($exception->getMessage())->toContain("Facebook answered with a redirect (HTTP {$status}), which is not followed.")
            ->and($exception->getMessage().json_encode($exception->context()))
            ->not->toContain('evil.test')
            ->not->toContain('test-page-token');

        if (str_starts_with($key, 'feed') || $key === 'photos') {
            expect($exception)->toBeInstanceOf(PublishFailed::class);
        }
    }

    // Asked once, and nothing went anywhere else: a request to another
    // host would also have failed the test inside fakeGraph().
    expect($answered)->toBe(1);
    Http::assertNotSent(fn (Request $request) => ! str_starts_with($request->url(), FACEBOOK_TEST_GRAPH.'/'));
})->with([
    // Calls that are safe to repeat may be tried again.
    'the code exchange' => ['code', 302, true, false],
    'the long-lived exchange' => ['exchange', 307, true, false],
    'the list of Pages' => ['accounts', 301, true, false],
    'the token check' => ['debug', 307, true, false],
    'a delete' => ['delete', 308, true, false],
    'an upload, which makes nothing public' => ['photos', 307, true, false],
    // Creating a post is not: nobody knows what happened to it.
    'a post' => ['feed', 307, false, true],
    'a post, permanently moved' => ['feed', 308, false, true],
    'a post with images' => ['feed with images', 307, false, true],
]);
