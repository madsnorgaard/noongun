<?php

/*
 * The OAuth round trip for LinkedIn through the real controller and the real
 * adapter, against the faked LinkedIn endpoints.
 */

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Models\Channel;
use App\Domain\Workspaces\Models\Membership;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Http\Controllers\Channels\ConnectChannelController;
use App\Http\Controllers\Channels\ConnectSelection;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../../Support/linkedin.php';
require_once __DIR__.'/../../../Support/members.php';

beforeEach(function () {
    configureLinkedIn();
    Http::preventStrayRequests();
    $this->travelTo('2026-11-25 10:00:00');

    $this->workspace = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
    $this->owner = workspaceMember($this->workspace, Role::Owner);

    // Starts a connection as the given user and returns the state value.
    $this->start = function (?object $user = null): string {
        $this->actingAs($user ?? $this->owner)->post(route('channels.connect', 'linkedin'))->assertRedirect();

        return session(ConnectChannelController::PENDING)['state'];
    };

    $this->callback = fn (array $query) => $this->get(route('channels.callback', ['platform' => 'linkedin', ...$query]));
});

describe('start', function () {
    it('sends the browser to LinkedIn with a state that is kept in the session and tied to the workspace', function () {
        $response = $this->actingAs($this->owner)->post(route('channels.connect', 'linkedin'));

        $pending = session(ConnectChannelController::PENDING);
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        expect($response->headers->get('Location'))->toStartWith(LINKEDIN_TEST_WEB.'/oauth/v2/authorization?')
            ->and($pending['state'])->toBeString()->toHaveLength(40)
            ->and($query['state'])->toBe($pending['state'])
            ->and($query['redirect_uri'])->toBe('https://noongun.test/auth/linkedin/callback')
            ->and($query['scope'])->toBe('openid profile w_member_social')
            ->and($pending['platform'])->toBe('linkedin')
            ->and($pending['workspace_id'])->toBe($this->workspace->id);

        Http::assertNothingSent();
    });

    it('lets only owners connect, as for every platform', function (Role $role, bool $allowed) {
        $response = $this->actingAs(workspaceMember($this->workspace, $role))->post(route('channels.connect', 'linkedin'));

        $allowed ? $response->assertRedirect() : $response->assertForbidden();

        expect(session()->has(ConnectChannelController::PENDING))->toBe($allowed);
    })->with([
        'owner' => [Role::Owner, true],
        'editor' => [Role::Editor, false],
        'viewer' => [Role::Viewer, false],
    ]);

    it('goes back with an explanation when LinkedIn is not configured', function () {
        configureLinkedIn(['client_id' => null]);

        $this->actingAs($this->owner)
            ->post(route('channels.connect', 'linkedin'))
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast.type', 'error');

        expect(session()->has(ConnectChannelController::PENDING))->toBeFalse();
    });
});

describe('callback', function () {
    it('connects the member who signed in and goes straight to the list', function () {
        fakeLinkedIn();
        $state = ($this->start)();

        ($this->callback)(['code' => 'test-auth-code', 'state' => $state])
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Thandi Mokoena is connected.']);

        $channel = inWorkspace($this->workspace, fn () => Channel::query()->sole());

        expect($channel->platform)->toBe('linkedin')
            ->and($channel->external_id)->toBe(LINKEDIN_TEST_MEMBER)
            ->and($channel->status)->toBe(ChannelStatus::Active)
            ->and($channel->canPublish())->toBeTrue()
            ->and($channel->access_token)->toBe(LINKEDIN_TEST_TOKEN)
            ->and($channel->token_expires_at?->toDateString())->toBe('2027-01-24')
            ->and(session()->has(ConnectChannelController::PENDING))->toBeFalse()
            ->and(session()->has(ConnectSelection::KEY))->toBeFalse();
    });

    it('always ends on the channels page, whatever the callback carries', function () {
        fakeLinkedIn();
        $state = ($this->start)();

        $response = ($this->callback)([
            'code' => 'test-auth-code',
            'state' => $state,
            'redirect' => 'https://evil.test/',
            'next' => '//evil.test',
            'return_to' => 'https://evil.test/',
        ]);

        expect($response->headers->get('Location'))->toBe(route('channels.index'));
    });

    it('shows the expiry on the channels page and never the token', function () {
        fakeLinkedIn();
        ($this->callback)(['code' => 'test-auth-code', 'state' => ($this->start)()]);

        $response = $this->get(route('channels.index'))->assertInertia(fn (Assert $page) => $page
            ->where('channels.0.platform', 'linkedin')
            ->where('channels.0.platform_label', 'LinkedIn')
            ->where('channels.0.display_name', 'Thandi Mokoena')
            ->where('channels.0.status', 'active')
            ->where('channels.0.token_expires_at', '2027-01-24T10:00:00+00:00')
            ->where('channels.0.token_expired', false)
            ->where('channels.0.token_expires_soon', false));

        expect($response->getContent())->not->toContain(LINKEDIN_TEST_TOKEN)->not->toContain(LINKEDIN_TEST_SECRET);

        $this->travelTo('2027-01-15 10:00:00');

        $this->get(route('channels.index'))->assertInertia(fn (Assert $page) => $page
            ->where('channels.0.token_expires_soon', true));

        $this->travelTo('2027-01-25 10:00:00');

        $this->get(route('channels.index'))->assertInertia(fn (Assert $page) => $page
            ->where('channels.0.token_expired', true));
    });

    it('replaces the token on the existing channel when the member connects again', function () {
        $existing = inWorkspace($this->workspace, fn () => Channel::factory()->platform('linkedin')->expired()->create([
            'external_id' => LINKEDIN_TEST_MEMBER,
            'access_token' => 'the-old-token',
        ]));

        fakeLinkedIn();

        ($this->callback)(['code' => 'test-auth-code', 'state' => ($this->start)()])
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Thandi Mokoena is connected.']);

        $channel = inWorkspace($this->workspace, fn () => Channel::query()->sole());

        expect($channel->id)->toBe($existing->id)
            ->and($channel->access_token)->toBe(LINKEDIN_TEST_TOKEN)
            ->and($channel->canPublish())->toBeTrue();
    });

    it('asks before a profile that was disconnected is connected again', function () {
        inWorkspace($this->workspace, fn () => Channel::factory()->platform('linkedin')->disconnected()->create(['external_id' => LINKEDIN_TEST_MEMBER]));

        fakeLinkedIn();

        ($this->callback)(['code' => 'test-auth-code', 'state' => ($this->start)()])
            ->assertRedirect(route('channels.selection.edit'));

        expect(inWorkspace($this->workspace, fn () => Channel::query()->sole())->status)->toBe(ChannelStatus::Pending);
    });

    it('accepts a state only once', function () {
        fakeLinkedIn();
        $state = ($this->start)();

        ($this->callback)(['code' => 'test-auth-code', 'state' => $state])->assertInertiaFlash('toast.type', 'success');
        $sent = Http::recorded()->count();

        ($this->callback)(['code' => 'another-code', 'state' => $state])
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The connection could not be verified. Start again from this page.']);

        expect(Http::recorded()->count())->toBe($sent)
            ->and(DB::table('channels')->count())->toBe(1);
    });

    it('uses up the state even when the first callback fails', function () {
        fakeLinkedIn(['token' => linkedInResponse('error_code_used', 401)]);
        $state = ($this->start)();

        ($this->callback)(['code' => 'test-auth-code', 'state' => $state])->assertInertiaFlash('toast.type', 'error');

        fakeLinkedIn();

        ($this->callback)(['code' => 'test-auth-code', 'state' => $state])
            ->assertInertiaFlash('toast.message', 'The connection could not be verified. Start again from this page.');

        expect(DB::table('channels')->count())->toBe(0);
    });

    it('connects nothing when the member says no', function (string $error) {
        $state = ($this->start)();

        ($this->callback)(['error' => $error, 'error_description' => '<b>The user cancelled</b>', 'state' => $state])
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'Access was not granted on LinkedIn. Nothing was connected.']);

        Http::assertNothingSent();
        expect(DB::table('channels')->count())->toBe(0)
            ->and(session()->has(ConnectChannelController::PENDING))->toBeFalse();
    })->with(['user_cancelled_authorize', 'user_cancelled_login', 'access_denied']);

    it('connects nothing when LinkedIn reports another error, and does not repeat its text', function () {
        $state = ($this->start)();

        ($this->callback)(['error' => 'server_error', 'error_description' => '<script>alert(1)</script>', 'state' => $state])
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'LinkedIn reported a problem during sign-in. Nothing was connected.']);

        Http::assertNothingSent();
    });

    it('refuses a callback whose state is wrong, missing or not expected', function (Closure $query) {
        $state = ($this->start)();

        ($this->callback)($query($state))
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The connection could not be verified. Start again from this page.']);

        Http::assertNothingSent();
        expect(DB::table('channels')->count())->toBe(0);
    })->with([
        'wrong state' => [fn (string $state) => ['code' => 'test-auth-code', 'state' => 'not-the-state']],
        'no state' => [fn (string $state) => ['code' => 'test-auth-code']],
        'state as an array' => [fn (string $state) => ['code' => 'test-auth-code', 'state' => [$state]]],
        'wrong state with an error' => [fn (string $state) => ['error' => 'user_cancelled_authorize', 'state' => 'not-the-state']],
    ]);

    it('refuses a state that was issued for another platform', function () {
        require_once __DIR__.'/../../../Support/facebook.php';
        configureFacebook();

        $this->actingAs($this->owner)->post(route('channels.connect', 'facebook'))->assertRedirect();
        $state = session(ConnectChannelController::PENDING)['state'];

        ($this->callback)(['code' => 'test-auth-code', 'state' => $state])
            ->assertInertiaFlash('toast.message', 'The connection could not be verified. Start again from this page.');

        Http::assertNothingSent();
    });

    it('refuses a callback that nothing in this session started', function () {
        $this->actingAs($this->owner);

        ($this->callback)(['code' => 'test-auth-code', 'state' => 'anything'])
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast.type', 'error');

        Http::assertNothingSent();
    });

    it('refuses a state from the session of another user', function () {
        $state = ($this->start)();

        // A second browser: the attacker's state, the victim's session.
        $this->flushSession();
        $this->actingAs(workspaceMember($this->workspace, Role::Owner));

        ($this->callback)(['code' => 'test-auth-code', 'state' => $state])
            ->assertInertiaFlash('toast.message', 'The connection could not be verified. Start again from this page.');

        Http::assertNothingSent();
        expect(DB::table('channels')->count())->toBe(0);
    });

    it('refuses a state that is too old', function () {
        $state = ($this->start)();

        $this->travel(16)->minutes();

        ($this->callback)(['code' => 'test-auth-code', 'state' => $state])->assertInertiaFlash('toast.type', 'error');

        Http::assertNothingSent();
    });

    it('refuses a state that was issued for another workspace', function () {
        $other = Workspace::factory()->create();
        Membership::factory()->for($other)->for($this->owner)->role(Role::Owner)->create();

        $state = ($this->start)();

        $this->withSession(['workspace_id' => $other->id]);

        ($this->callback)(['code' => 'test-auth-code', 'state' => $state])
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The connection could not be verified. Start again from this page.']);

        Http::assertNothingSent();
        expect(DB::table('channels')->count())->toBe(0);
    });

    it('refuses a callback without a code', function () {
        ($this->callback)(['state' => ($this->start)()])
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast.type', 'error');

        Http::assertNothingSent();
    });

    it('shows a safe message and logs nothing secret when LinkedIn refuses the code', function () {
        $logged = '';
        Event::listen(MessageLogged::class, function (MessageLogged $entry) use (&$logged) {
            $context = $entry->context;
            $exception = $context['exception'] ?? null;
            unset($context['exception']);

            $logged .= $entry->message.json_encode($context).($exception instanceof Throwable ? (string) $exception : '');
        });

        fakeLinkedIn(['token' => fn ($request) => Http::response([
            'error' => 'invalid_request',
            'error_description' => 'Refused: '.implode(' ', $request->data()),
        ], 400)]);

        $response = ($this->callback)(['code' => 'test-auth-code', 'state' => ($this->start)()])
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast.type', 'error');

        $message = session('inertia.flash_data')['toast']['message'];

        expect($message)->toContain('LinkedIn refused the sign-in.')
            ->not->toContain('test-auth-code')
            ->not->toContain(LINKEDIN_TEST_SECRET)
            // What LinkedIn wrote is for the log, not for the member.
            ->not->toContain('Refused:')
            ->and($logged)->toContain('LinkedIn refused the sign-in.')->toContain('Refused:')
            ->not->toContain('test-auth-code')
            ->not->toContain(LINKEDIN_TEST_SECRET)
            ->and($response->headers->get('Location'))->not->toContain('test-auth-code')
            ->and(DB::table('channels')->count())->toBe(0);
    });

    it('refuses viewers and guests', function () {
        $this->actingAs(workspaceMember($this->workspace, Role::Viewer));

        ($this->callback)(['code' => 'test-auth-code', 'state' => 'x'])->assertForbidden();

        Http::assertNothingSent();

        auth()->logout();

        ($this->callback)(['code' => 'test-auth-code', 'state' => 'x'])->assertRedirect(route('login'));
    });
});

describe('workspace isolation', function () {
    it('connects into the workspace of the session and leaves every other workspace alone', function () {
        $other = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
        $theirs = inWorkspace($other, fn () => Channel::factory()->platform('linkedin')->create([
            'external_id' => LINKEDIN_TEST_MEMBER,
            'access_token' => 'their-own-token',
        ]));

        fakeLinkedIn();

        ($this->callback)(['code' => 'test-auth-code', 'state' => ($this->start)()])->assertInertiaFlash('toast.type', 'success');

        expect(DB::table('channels')->where('workspace_id', $this->workspace->id)->count())->toBe(1)
            ->and(DB::table('channels')->where('workspace_id', $other->id)->count())->toBe(1)
            ->and(inWorkspace($other, fn () => $theirs->fresh())->access_token)->toBe('their-own-token');
    });

    it('does not let a member of one workspace disconnect the LinkedIn channel of another', function () {
        $other = Workspace::factory()->create();
        $theirs = inWorkspace($other, fn () => Channel::factory()->platform('linkedin')->create());

        $this->actingAs($this->owner)->delete(route('channels.destroy', $theirs->id))->assertNotFound();

        expect(inWorkspace($other, fn () => $theirs->fresh())->status)->toBe(ChannelStatus::Active);
    });
});
