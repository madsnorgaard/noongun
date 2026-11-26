<?php

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/facebook.php';
require_once __DIR__.'/../../Support/linkedin.php';
require_once __DIR__.'/../../Support/members.php';

beforeEach(function () {
    configureFacebook();
    configureLinkedIn();
    Http::preventStrayRequests();

    $this->workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->owner = workspaceMember($this->workspace, Role::Owner);
});

describe('list', function () {
    it('shows the channels of the workspace with status and expiry, and no tokens', function () {
        $this->travelTo('2026-11-24 09:00:00');

        inWorkspace($this->workspace, function () {
            Channel::factory()->create(['display_name' => 'Alpha', 'external_id' => '111']);
            Channel::factory()->needsReconnect()->create(['display_name' => 'Bravo']);
            Channel::factory()->expiresAt(now()->addDays(5))->create(['display_name' => 'Charlie']);
            Channel::factory()->disconnected()->create(['display_name' => 'Delta']);
        });

        $response = $this->actingAs($this->owner)->get(route('channels.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('channels/Index')
            ->has('channels', 4)
            ->where('channels.0', [
                'id' => inWorkspace($this->workspace, fn () => Channel::query()->where('display_name', 'Alpha')->value('id')),
                'platform' => 'facebook',
                'platform_label' => 'Facebook',
                'display_name' => 'Alpha',
                'external_id' => '111',
                'status' => 'active',
                'token_expires_at' => null,
                'token_expired' => false,
                'token_expires_soon' => false,
            ])
            ->where('channels.1.status', 'needs_reconnect')
            ->where('channels.2.token_expires_at', '2026-11-29T09:00:00+00:00')
            ->where('channels.2.token_expires_soon', true)
            ->where('channels.3.status', 'disconnected')
            ->where('can.manage', true)
            ->where('platforms', [[
                'name' => 'facebook',
                'label' => 'Facebook',
                'missing_settings' => [],
                'setup_steps' => [],
                'redirect_uri' => 'https://noongun.test/auth/facebook/callback',
            ], [
                'name' => 'linkedin',
                'label' => 'LinkedIn',
                'missing_settings' => [],
                'setup_steps' => ['In the LinkedIn app, on the Products tab, add both "Share on LinkedIn" and "Sign In with LinkedIn using OpenID Connect". Without the first Noon Gun may not post, and without the second it cannot tell whose profile it is.'],
                'redirect_uri' => 'https://noongun.test/auth/linkedin/callback',
            ]]));

        expect($response->getContent())->not->toContain('test-access-');

        Http::assertNothingSent();
    });

    it('says which settings are missing when the platform is not configured', function () {
        configureFacebook(['app_id' => null, 'app_secret' => null]);

        $this->actingAs($this->owner)->get(route('channels.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('channels', 0)
                ->where('platforms.0.missing_settings', ['FACEBOOK_APP_ID', 'FACEBOOK_APP_SECRET'])
                ->where('platforms.0.redirect_uri', 'https://noongun.test/auth/facebook/callback'));
    });

    it('lists LinkedIn with what is missing when only Facebook is set up', function () {
        configureLinkedIn(['client_id' => null, 'client_secret' => null]);

        $response = $this->actingAs($this->owner)->get(route('channels.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('platforms.0.missing_settings', [])
                ->where('platforms.1.name', 'linkedin')
                ->where('platforms.1.label', 'LinkedIn')
                ->where('platforms.1.missing_settings', ['LINKEDIN_CLIENT_ID', 'LINKEDIN_CLIENT_SECRET'])
                // The setup card names the two products the LinkedIn app needs.
                ->where('platforms.1.setup_steps.0', fn (string $step) => str_contains($step, 'Share on LinkedIn') && str_contains($step, 'Sign In with LinkedIn using OpenID Connect'))
                ->where('platforms.0.setup_steps', [])
                ->where('platforms.1.redirect_uri', 'https://noongun.test/auth/linkedin/callback'));

        expect($response->getContent())->not->toContain(LINKEDIN_TEST_SECRET);
    });

    it('never shows the app secret', function () {
        expect($this->actingAs($this->owner)->get(route('channels.index'))->getContent())
            ->not->toContain(FACEBOOK_TEST_APP_SECRET);
    });

    it('lets every role look, and only owners manage', function (Role $role, bool $manage) {
        $this->actingAs(workspaceMember($this->workspace, $role))->get(route('channels.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.manage', $manage));
    })->with([
        'owner' => [Role::Owner, true],
        'editor' => [Role::Editor, false],
        'viewer' => [Role::Viewer, false],
    ]);

    it('shows only the channels of the current workspace', function () {
        inWorkspace($this->workspace, fn () => Channel::factory()->create(['display_name' => 'Mine']));
        inWorkspace(Workspace::factory()->create(), fn () => Channel::factory()->create(['display_name' => 'Theirs']));

        $this->actingAs($this->owner)->get(route('channels.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('channels', 1)
                ->where('channels.0.display_name', 'Mine'));
    });

    it('is closed to guests and to users without a workspace', function () {
        $this->get(route('channels.index'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())->get(route('channels.index'))->assertForbidden();
    });
});

describe('disconnect', function () {
    it('erases the tokens and keeps the row', function () {
        $channel = inWorkspace($this->workspace, fn () => Channel::factory()->withRefreshToken()->create(['display_name' => 'Alpha']));

        $this->actingAs($this->owner)
            ->delete(route('channels.destroy', $channel->id))
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Alpha is disconnected.']);

        $row = DB::table('channels')->where('id', $channel->id)->first();

        expect($row->status)->toBe(ChannelStatus::Disconnected->value)
            ->and($row->access_token)->toBeNull()
            ->and($row->refresh_token)->toBeNull()
            ->and($row->token_expires_at)->toBeNull();

        Http::assertNothingSent();
    });

    it('works for a channel that has published variants', function () {
        $channel = inWorkspace($this->workspace, fn () => Channel::factory()->create());
        $variant = inWorkspace($this->workspace, fn () => PostVariant::factory()->for($channel)->published()->create());

        $this->actingAs($this->owner)->delete(route('channels.destroy', $channel->id))->assertRedirect(route('channels.index'));

        expect(DB::table('channels')->where('id', $channel->id)->value('status'))->toBe('disconnected')
            ->and(DB::table('post_variants')->where('id', $variant->id)->value('channel_id'))->toBe($channel->id);
    });

    it('is refused for editors and viewers', function (Role $role) {
        $channel = inWorkspace($this->workspace, fn () => Channel::factory()->create());

        $this->actingAs(workspaceMember($this->workspace, $role))
            ->delete(route('channels.destroy', $channel->id))
            ->assertForbidden();

        $row = DB::table('channels')->where('id', $channel->id)->first();

        expect($row->status)->toBe('active')
            ->and($row->access_token)->not->toBeNull();
    })->with(['editor' => Role::Editor, 'viewer' => Role::Viewer]);

    it('cannot reach a channel of another workspace', function () {
        $theirs = inWorkspace(Workspace::factory()->create(), fn () => Channel::factory()->create());

        $this->actingAs($this->owner)->delete(route('channels.destroy', $theirs->id))->assertNotFound();

        $row = DB::table('channels')->where('id', $theirs->id)->first();

        expect($row->status)->toBe('active')
            ->and($row->access_token)->not->toBeNull();
    });

    it('answers 404 for a channel that does not exist and sends guests to the login page', function () {
        $this->actingAs($this->owner)->delete(route('channels.destroy', 999999))->assertNotFound();

        auth()->logout();

        $this->delete(route('channels.destroy', 1))->assertRedirect(route('login'));
    });
});

it('gives each role the channel abilities it should have', function (Role $role, bool $manage) {
    $channel = inWorkspace($this->workspace, fn () => Channel::factory()->create());
    $user = workspaceMember($this->workspace, $role);
    $outsider = workspaceMember(Workspace::factory()->create(), Role::Owner);

    inWorkspace($this->workspace, function () use ($user, $outsider, $channel, $manage) {
        expect($user->can('viewAny', Channel::class))->toBeTrue()
            ->and($user->can('create', Channel::class))->toBe($manage)
            ->and($user->can('delete', $channel))->toBe($manage)
            ->and($outsider->can('viewAny', Channel::class))->toBeFalse()
            ->and($outsider->can('create', Channel::class))->toBeFalse()
            ->and($outsider->can('delete', $channel))->toBeFalse();
    });
})->with([
    'owner' => [Role::Owner, true],
    'editor' => [Role::Editor, false],
    'viewer' => [Role::Viewer, false],
]);
