<?php

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Models\Channel;
use App\Domain\Workspaces\Models\Membership;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Http\Controllers\Channels\ConnectChannelController;
use App\Http\Controllers\Channels\ConnectSelection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Support/facebook.php';
require_once __DIR__.'/../../Support/members.php';

beforeEach(function () {
    configureFacebook();
    Http::preventStrayRequests();

    $this->workspace = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
    $this->owner = workspaceMember($this->workspace, Role::Owner);

    // Starts a connection as the given user and returns the state value.
    $this->start = function (?object $user = null): string {
        $this->actingAs($user ?? $this->owner)->post(route('channels.connect', 'facebook'))->assertRedirect();

        return session(ConnectChannelController::PENDING)['state'];
    };
});

describe('start', function () {
    it('sends the browser to the login dialog with a state that is kept in the session', function () {
        $response = $this->actingAs($this->owner)->post(route('channels.connect', 'facebook'));

        $pending = session(ConnectChannelController::PENDING);
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        expect($response->headers->get('Location'))->toStartWith('https://www.facebook.test/v99.0/dialog/oauth?')
            ->and($pending['state'])->toBeString()->toHaveLength(40)
            ->and($query['state'])->toBe($pending['state'])
            ->and($query['redirect_uri'])->toBe('https://noongun.test/auth/facebook/callback')
            ->and($pending['platform'])->toBe('facebook')
            ->and($pending['workspace_id'])->toBe($this->workspace->id);

        Http::assertNothingSent();
    });

    it('gives a new state every time', function () {
        expect(($this->start)())->not->toBe(($this->start)());
    });

    it('answers an Inertia request with the address to leave the app for', function () {
        $this->actingAs($this->owner)
            ->post(route('channels.connect', 'facebook'), [], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location');
    });

    it('is not a GET route, so a link or a prefetch cannot start it', function () {
        $this->actingAs($this->owner)->get('/channels/facebook/connect')->assertStatus(405);
    });

    it('lets owners and editors connect and refuses viewers', function (Role $role, bool $allowed) {
        $response = $this->actingAs(workspaceMember($this->workspace, $role))->post(route('channels.connect', 'facebook'));

        $allowed ? $response->assertRedirect() : $response->assertForbidden();

        expect(session()->has(ConnectChannelController::PENDING))->toBe($allowed);
    })->with([
        'owner' => [Role::Owner, true],
        'editor' => [Role::Editor, true],
        'viewer' => [Role::Viewer, false],
    ]);

    it('sends guests to the login page', function () {
        $this->post(route('channels.connect', 'facebook'))->assertRedirect(route('login'));
    });

    it('answers 404 for a platform that does not exist', function () {
        $this->actingAs($this->owner)->post(route('channels.connect', 'myspace'))->assertNotFound();
    });

    it('goes back with an explanation when the platform is not configured', function () {
        configureFacebook(['app_id' => null]);

        $this->actingAs($this->owner)
            ->post(route('channels.connect', 'facebook'))
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast.type', 'error');

        expect(session()->has(ConnectChannelController::PENDING))->toBeFalse();
    });
});

describe('callback', function () {
    it('connects the one Page that was granted and goes straight to the list', function () {
        fakeGraph(['accounts' => 'me_accounts_one_page']);
        $state = ($this->start)();

        $this->get(route('channels.callback', ['platform' => 'facebook', 'code' => 'test-auth-code', 'state' => $state]))
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Table Bay Coffee is connected.']);

        $channel = inWorkspace($this->workspace, fn () => Channel::query()->sole());

        expect($channel->external_id)->toBe('100000000000001')
            ->and($channel->status)->toBe(ChannelStatus::Active)
            ->and($channel->access_token)->toBe('test-page-token-table-bay-coffee')
            ->and(session()->has(ConnectChannelController::PENDING))->toBeFalse()
            ->and(session()->has(ConnectSelection::KEY))->toBeFalse();
    });

    it('accepts a state only once', function () {
        fakeGraph(['accounts' => 'me_accounts_one_page']);
        $state = ($this->start)();
        $url = route('channels.callback', ['platform' => 'facebook', 'code' => 'test-auth-code', 'state' => $state]);

        $this->get($url)->assertInertiaFlash('toast.type', 'success');
        $sent = Http::recorded()->count();

        $this->get($url)->assertRedirect(route('channels.index'))->assertInertiaFlash('toast.type', 'error');

        expect(Http::recorded()->count())->toBe($sent);
    });

    it('connects nothing when the user denies access', function () {
        $state = ($this->start)();

        $this->get(route('channels.callback', [
            'platform' => 'facebook',
            'error' => 'access_denied',
            'error_reason' => 'user_denied',
            'error_description' => 'Permissions error.',
            'state' => $state,
        ]))
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'Access was not granted on Facebook. Nothing was connected.']);

        Http::assertNothingSent();
        expect(DB::table('channels')->count())->toBe(0)
            ->and(session()->has(ConnectChannelController::PENDING))->toBeFalse();
    });

    it('connects nothing when the platform reports another error', function () {
        $state = ($this->start)();

        $this->get(route('channels.callback', ['platform' => 'facebook', 'error' => 'server_error', 'state' => $state]))
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast.type', 'error');

        Http::assertNothingSent();
    });

    it('refuses a callback whose state is wrong, missing or not expected', function (Closure $query) {
        $state = ($this->start)();

        $this->get(route('channels.callback', ['platform' => 'facebook', ...$query($state)]))
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The connection could not be verified. Start again from this page.']);

        Http::assertNothingSent();
        expect(DB::table('channels')->count())->toBe(0);
    })->with([
        'wrong state' => [fn (string $state) => ['code' => 'test-auth-code', 'state' => 'not-the-state']],
        'no state' => [fn (string $state) => ['code' => 'test-auth-code']],
        'state as an array' => [fn (string $state) => ['code' => 'test-auth-code', 'state' => [$state]]],
        'wrong state with an error' => [fn (string $state) => ['error' => 'access_denied', 'state' => 'not-the-state']],
    ]);

    it('refuses a callback that nothing in this session started', function () {
        $this->actingAs($this->owner)
            ->get(route('channels.callback', ['platform' => 'facebook', 'code' => 'test-auth-code', 'state' => 'anything']))
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast.type', 'error');

        Http::assertNothingSent();
    });

    it('refuses a state that is too old', function () {
        $state = ($this->start)();

        $this->travel(16)->minutes();

        $this->get(route('channels.callback', ['platform' => 'facebook', 'code' => 'test-auth-code', 'state' => $state]))
            ->assertInertiaFlash('toast.type', 'error');

        Http::assertNothingSent();
    });

    it('refuses a state that was issued for another workspace', function () {
        $other = Workspace::factory()->create();
        Membership::factory()->for($other)->for($this->owner)->role(Role::Owner)->create();

        $state = ($this->start)();

        $this->withSession(['workspace_id' => $other->id])
            ->get(route('channels.callback', ['platform' => 'facebook', 'code' => 'test-auth-code', 'state' => $state]))
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast.type', 'error');

        Http::assertNothingSent();
        expect(DB::table('channels')->count())->toBe(0);
    });

    it('refuses a callback without a code', function () {
        $state = ($this->start)();

        $this->get(route('channels.callback', ['platform' => 'facebook', 'state' => $state]))
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast.type', 'error');

        Http::assertNothingSent();
    });

    it('shows a safe message when the platform refuses the code', function () {
        fakeGraph(['code' => facebookResponse('error_code_used', 400)]);
        $state = ($this->start)();

        $response = $this->get(route('channels.callback', ['platform' => 'facebook', 'code' => 'test-auth-code', 'state' => $state]))
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast.type', 'error');

        $message = session('inertia.flash_data')['toast']['message'];

        expect($message)->toContain('This authorization code has been used.')
            ->not->toContain('test-auth-code')
            ->not->toContain(FACEBOOK_TEST_APP_SECRET)
            ->and(DB::table('channels')->count())->toBe(0);

        expect($response->headers->get('Location'))->not->toContain('test-auth-code');
    });

    it('refuses viewers and guests', function () {
        $this->actingAs(workspaceMember($this->workspace, Role::Viewer))
            ->get(route('channels.callback', ['platform' => 'facebook', 'code' => 'test-auth-code', 'state' => 'x']))
            ->assertForbidden();

        Http::assertNothingSent();
    });

    it('sends guests to the login page', function () {
        $this->get(route('channels.callback', ['platform' => 'facebook', 'code' => 'test-auth-code', 'state' => 'x']))
            ->assertRedirect(route('login'));
    });
});
