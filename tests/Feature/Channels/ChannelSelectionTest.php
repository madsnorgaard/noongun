<?php

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Workspaces\Models\Membership;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Http\Controllers\Channels\ConnectChannelController;
use App\Http\Controllers\Channels\ConnectSelection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/facebook.php';
require_once __DIR__.'/../../Support/members.php';

/*
 * A connect saves what the user already has connected as active, and every
 * other account as pending. Pending cannot publish. Only the user's choice
 * makes it active; everything else discards it.
 */

const COFFEE = '100000000000001';
const BOOKS = '100000000000002';

beforeEach(function () {
    configureFacebook();
    Http::preventStrayRequests();

    $this->workspace = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
    $this->owner = workspaceMember($this->workspace, Role::Owner);

    // Runs the whole round trip to Facebook and back. Facebook returns Table
    // Bay Coffee and Harbour Books unless another fixture is given.
    $this->connect = function (string $accounts = 'me_accounts'): TestResponse {
        fakeGraph(['accounts' => $accounts]);

        $this->actingAs($this->owner)->post(route('channels.connect', 'facebook'))->assertRedirect();

        return $this->get(route('channels.callback', [
            'platform' => 'facebook',
            'code' => 'test-auth-code',
            'state' => session(ConnectChannelController::PENDING)['state'],
        ]));
    };

    $this->channel = fn (string $externalId): ?Channel => inWorkspace(
        $this->workspace,
        fn () => Channel::query()->where('external_id', $externalId)->first(),
    );

    // A channel that is already in the workspace. $state is a factory state;
    // without one the channel is active.
    $this->existing = fn (string $externalId, ?string $state = null, array $attributes = []): Channel => inWorkspace(
        $this->workspace,
        fn () => ($state === null ? Channel::factory() : Channel::factory()->{$state}())
            ->create(['external_id' => $externalId, ...$attributes]),
    );

    $this->publishable = fn (): array => inWorkspace(
        $this->workspace,
        fn () => Channel::query()->get()->filter(fn (Channel $channel) => $channel->canPublish())->pluck('external_id')->sort()->values()->all(),
    );

    $this->storedTokens = fn (): int => DB::table('channels')->whereNotNull('access_token')->count();
});

describe('two Pages that are new to the workspace', function () {
    beforeEach(function () {
        $this->callback = ($this->connect)();
    });

    it('saves them as pending, which cannot publish, and asks the user', function () {
        $this->callback->assertRedirect(route('channels.selection.edit'));

        expect(($this->channel)(COFFEE)->status)->toBe(ChannelStatus::Pending)
            ->and(($this->channel)(BOOKS)->status)->toBe(ChannelStatus::Pending)
            ->and(($this->publishable)())->toBe([]);

        $this->get(route('channels.selection.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('channels/Select')
                ->has('channels', 2)
                ->where('channels.0.display_name', 'Harbour Books')
                ->where('channels.1.display_name', 'Table Bay Coffee')
                ->where('channels.1.status', 'pending')
                ->where('renewed', [])
                ->where('minutes', 15)
                ->missing('channels.0.access_token'));
    });

    it('connects the chosen Page and removes the other with its token', function () {
        $this->put(route('channels.selection.update'), ['keep' => [($this->channel)(COFFEE)->id]])
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '1 channel is connected.']);

        expect(($this->channel)(COFFEE)->status)->toBe(ChannelStatus::Active)
            ->and(($this->channel)(COFFEE)->access_token)->toBe('test-page-token-table-bay-coffee')
            ->and(($this->channel)(BOOKS))->toBeNull()
            ->and(($this->publishable)())->toBe([COFFEE])
            ->and(($this->storedTokens)())->toBe(1)
            ->and(session()->has(ConnectSelection::KEY))->toBeFalse();
    });

    it('can connect all of them', function () {
        $this->put(route('channels.selection.update'), ['keep' => [($this->channel)(COFFEE)->id, ($this->channel)(BOOKS)->id]])
            ->assertInertiaFlash('toast.message', '2 channels are connected.');

        expect(($this->publishable)())->toBe([COFFEE, BOOKS]);
    });

    it('connects none of them when the user says so', function () {
        $this->delete(route('channels.selection.destroy'))
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast.type', 'info');

        expect(DB::table('channels')->count())->toBe(0)
            ->and(session()->has(ConnectSelection::KEY))->toBeFalse();
    });

    it('leaves nothing publishable when the user just walks away', function () {
        // "Decide later", a closed tab, an expired session: no more requests.
        expect(($this->publishable)())->toBe([]);

        $this->travel(Channel::PENDING_MINUTES + 1)->minutes();

        $this->artisan('noongun:discard-pending-channels')->assertSuccessful();

        expect(DB::table('channels')->count())->toBe(0)
            ->and(($this->storedTokens)())->toBe(0);
    });

    it('needs at least one tick to connect', function () {
        $this->put(route('channels.selection.update'), ['keep' => []])->assertSessionHasErrors('keep');

        expect(DB::table('channels')->where('status', 'pending')->count())->toBe(2);
    });

    it('refuses a choice that is made too late, and connects nothing', function () {
        $coffee = ($this->channel)(COFFEE)->id;

        $this->travel(Channel::PENDING_MINUTES + 1)->minutes();

        $this->put(route('channels.selection.update'), ['keep' => [$coffee]])
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast.type', 'warning');

        expect(DB::table('channels')->count())->toBe(0)
            ->and(session()->has(ConnectSelection::KEY))->toBeFalse();
    });

    it('does not show a choice that is too old', function () {
        $this->travel(Channel::PENDING_MINUTES + 1)->minutes();

        $this->get(route('channels.selection.edit'))
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast.type', 'warning');

        expect(DB::table('channels')->count())->toBe(0);
    });

    it('only accepts channels that this connect returned', function () {
        $earlier = ($this->existing)('555');
        $foreign = inWorkspace(Workspace::factory()->create(), fn () => Channel::factory()->pending()->create());

        $this->put(route('channels.selection.update'), ['keep' => [$earlier->id]])->assertSessionHasErrors('keep.0');
        $this->put(route('channels.selection.update'), ['keep' => [$foreign->id]])->assertSessionHasErrors('keep.0');

        expect(DB::table('channels')->where('id', $foreign->id)->value('status'))->toBe('pending')
            ->and(($this->publishable)())->toBe(['555']);
    });

    it('is closed to viewers', function () {
        $viewer = workspaceMember($this->workspace, Role::Viewer);
        $coffee = ($this->channel)(COFFEE)->id;

        $this->actingAs($viewer)->get(route('channels.selection.edit'))->assertForbidden();
        $this->actingAs($viewer)->put(route('channels.selection.update'), ['keep' => [$coffee]])->assertForbidden();
        $this->actingAs($viewer)->delete(route('channels.selection.destroy'))->assertForbidden();

        expect(DB::table('channels')->where('status', 'pending')->count())->toBe(2);
    });

    it('does not carry over to another workspace of the same user', function () {
        $other = Workspace::factory()->create();
        Membership::factory()->for($other)->for($this->owner)->role(Role::Owner)->create();
        $coffee = ($this->channel)(COFFEE)->id;

        $this->withSession(['workspace_id' => $other->id])
            ->get(route('channels.selection.edit'))
            ->assertRedirect(route('channels.index'));

        $this->withSession(['workspace_id' => $other->id])
            ->put(route('channels.selection.update'), ['keep' => [$coffee]])
            ->assertRedirect(route('channels.index'));

        $this->withSession(['workspace_id' => $other->id])->delete(route('channels.selection.destroy'));

        expect(DB::table('channels')->where('status', 'pending')->count())->toBe(2);
    });

    it('is replaced when a new connect starts, which discards what was waiting', function () {
        $this->post(route('channels.connect', 'facebook'))->assertRedirect();

        expect(DB::table('channels')->count())->toBe(0)
            ->and(session()->has(ConnectSelection::KEY))->toBeFalse();
    });

    it('is shown on the Channels page while it is open', function () {
        $this->get(route('channels.index'))->assertInertia(fn (Assert $page) => $page
            ->where('selection_open', true)
            ->has('channels', 2)
            ->where('channels.0.status', 'pending'));

        $this->travel(Channel::PENDING_MINUTES + 1)->minutes();

        // Loading the list also clears what waited too long.
        $this->get(route('channels.index'))->assertInertia(fn (Assert $page) => $page
            ->where('selection_open', false)
            ->has('channels', 0));
    });
});

describe('reconnecting when one Page is active and another was disconnected', function () {
    beforeEach(function () {
        $this->coffee = ($this->existing)(COFFEE, attributes: ['access_token' => 'old-coffee-token']);
        $this->books = ($this->existing)(BOOKS, 'disconnected');

        // History, so the row of the disconnected Page has to stay.
        inWorkspace($this->workspace, fn () => PostVariant::factory()->for($this->books)->published()->create());

        $this->callback = ($this->connect)();
    });

    it('renews the active Page at once and does not connect the disconnected one', function () {
        $this->callback->assertRedirect(route('channels.selection.edit'));

        expect(($this->channel)(COFFEE)->access_token)->toBe('test-page-token-table-bay-coffee')
            ->and(($this->channel)(BOOKS)->status)->toBe(ChannelStatus::Pending)
            ->and(($this->channel)(BOOKS)->canPublish())->toBeFalse()
            ->and(($this->publishable)())->toBe([COFFEE]);

        $this->get(route('channels.selection.edit'))->assertInertia(fn (Assert $page) => $page
            ->has('channels', 1)
            ->where('channels.0.id', $this->books->id)
            ->has('renewed', 1)
            ->where('renewed.0.id', $this->coffee->id));
    });

    it('leaves the disconnect in force when the user decides later', function (Closure $abandon) {
        $abandon($this);

        $books = ($this->channel)(BOOKS);

        expect($books->status)->toBe(ChannelStatus::Disconnected)
            ->and($books->access_token)->toBeNull()
            ->and(DB::table('channels')->where('id', $books->id)->value('access_token'))->toBeNull()
            ->and(($this->channel)(COFFEE)->status)->toBe(ChannelStatus::Active)
            ->and(($this->publishable)())->toBe([COFFEE])
            ->and(($this->storedTokens)())->toBe(1);
    })->with([
        'by saying so' => [fn ($test) => $test->delete(route('channels.selection.destroy'))],
        'by closing the tab' => [function ($test) {
            $test->travel(Channel::PENDING_MINUTES + 1)->minutes();
            $test->artisan('noongun:discard-pending-channels')->assertSuccessful();
        }],
        'by coming back to the list much later' => [function ($test) {
            $test->travel(3)->hours();
            $test->get(route('channels.index'))->assertOk();
        }],
        'by answering much later' => [function ($test) {
            $test->travel(3)->hours();
            $test->put(route('channels.selection.update'), ['keep' => [$test->books->id]]);
        }],
        'by starting another connect' => [fn ($test) => $test->post(route('channels.connect', 'facebook'))],
    ]);

    it('connects the disconnected Page again only when the user ticks it', function () {
        $this->put(route('channels.selection.update'), ['keep' => [$this->books->id]])
            ->assertInertiaFlash('toast.message', '1 channel is connected.');

        expect(($this->publishable)())->toBe([COFFEE, BOOKS]);
    });

    it('cannot disconnect the Page that was already active, whatever is submitted or stored', function () {
        // Not offered, so not accepted.
        $this->put(route('channels.selection.update'), ['keep' => [$this->coffee->id]])->assertSessionHasErrors('keep.0');

        // Even an entry that names it, as the old flow stored, cannot reach it.
        $this->withSession([ConnectSelection::KEY => [
            'workspace_id' => $this->workspace->id,
            'channel_ids' => [$this->coffee->id, $this->books->id],
            'renewed_ids' => [],
            'expires_at' => now()->addMinutes(5)->getTimestamp(),
        ]])->put(route('channels.selection.update'), ['keep' => [$this->books->id]]);

        $this->withSession([ConnectSelection::KEY => [
            'workspace_id' => $this->workspace->id,
            'channel_ids' => [$this->coffee->id],
            'renewed_ids' => [],
            'expires_at' => now()->subHour()->getTimestamp(),
        ]])->delete(route('channels.selection.destroy'));

        expect(($this->channel)(COFFEE)->status)->toBe(ChannelStatus::Active)
            ->and(($this->channel)(COFFEE)->canPublish())->toBeTrue();
    });
});

describe('a connect that returns one Page', function () {
    it('goes straight through for a Page that is new', function () {
        ($this->connect)('me_accounts_one_page')
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast.message', 'Table Bay Coffee is connected.');

        expect(($this->publishable)())->toBe([COFFEE])
            ->and(session()->has(ConnectSelection::KEY))->toBeFalse();
    });

    it('goes straight through for a Page that is already connected, and renews it', function (?string $state) {
        ($this->existing)(COFFEE, $state, ['access_token' => 'old-coffee-token']);

        ($this->connect)('me_accounts_one_page')
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast.message', 'Table Bay Coffee is connected.');

        expect(($this->channel)(COFFEE)->access_token)->toBe('test-page-token-table-bay-coffee')
            ->and(($this->publishable)())->toBe([COFFEE]);
    })->with(['active' => [null], 'needs a reconnect' => ['needsReconnect']]);

    it('asks first for a Page the user had disconnected', function () {
        ($this->existing)(COFFEE, 'disconnected');

        ($this->connect)('me_accounts_one_page')->assertRedirect(route('channels.selection.edit'));

        expect(($this->channel)(COFFEE)->status)->toBe(ChannelStatus::Pending)
            ->and(($this->publishable)())->toBe([]);

        $this->get(route('channels.selection.edit'))->assertInertia(fn (Assert $page) => $page->has('channels', 1));

        $this->put(route('channels.selection.update'), ['keep' => [($this->channel)(COFFEE)->id]]);

        expect(($this->publishable)())->toBe([COFFEE]);
    });
});

describe('a pending channel', function () {
    it('cannot publish although it holds a token', function () {
        $channel = ($this->existing)('777', 'pending');

        expect($channel->access_token)->not->toBeNull()
            ->and($channel->canPublish())->toBeFalse()
            ->and(ChannelStatus::Pending->canPublish())->toBeFalse();
    });

    it('becomes active only through confirm, and only while it is pending', function () {
        $pending = ($this->existing)('777', 'pending');
        $disconnected = ($this->existing)('888', 'disconnected');
        $needsReconnect = ($this->existing)('999', 'needsReconnect');

        inWorkspace($this->workspace, function () use ($pending, $disconnected, $needsReconnect) {
            expect($pending->confirm())->toBeTrue()
                ->and($pending->status)->toBe(ChannelStatus::Active)
                ->and($pending->confirm())->toBeFalse()
                ->and($disconnected->confirm())->toBeFalse()
                ->and($disconnected->status)->toBe(ChannelStatus::Disconnected)
                ->and($needsReconnect->confirm())->toBeFalse()
                ->and($needsReconnect->status)->toBe(ChannelStatus::NeedsReconnect);
        });
    });

    it('is not confirmed once it has been discarded', function () {
        $pending = ($this->existing)('777', 'pending');

        inWorkspace($this->workspace, function () use ($pending) {
            Channel::query()->findOrFail($pending->id)->discardPending();

            expect($pending->confirm())->toBeFalse();
        });

        expect(DB::table('channels')->count())->toBe(0);
    });

    it('is discarded by deleting it, or by disconnecting it when posts point at it', function () {
        $bare = ($this->existing)('777', 'pending');
        $used = ($this->existing)('888', 'pending');
        $active = ($this->existing)('999');

        inWorkspace($this->workspace, function () use ($bare, $used, $active) {
            DB::table('channels')->where('id', $used->id)->update(['status' => 'active']);
            PostVariant::factory()->for($used)->create();
            DB::table('channels')->where('id', $used->id)->update(['status' => 'pending']);

            $bare->discardPending();
            $used->refresh()->discardPending();
            $active->discardPending();
        });

        expect(DB::table('channels')->where('id', $bare->id)->exists())->toBeFalse()
            ->and(DB::table('channels')->where('id', $used->id)->value('status'))->toBe('disconnected')
            ->and(DB::table('channels')->where('id', $used->id)->value('access_token'))->toBeNull()
            ->and(DB::table('channels')->where('id', $active->id)->value('status'))->toBe('active');
    });

    it('is left alone by a token check', function () {
        $pending = ($this->existing)('777', 'pending');

        expect(inWorkspace($this->workspace, fn () => facebookAdapter()->refreshToken($pending))->status)->toBe(ChannelStatus::Pending);

        Http::assertNothingSent();
    });

    it('can be removed from the list by an owner', function () {
        $pending = ($this->existing)('777', 'pending');

        $this->actingAs($this->owner)->delete(route('channels.destroy', $pending->id))->assertRedirect(route('channels.index'));

        expect(DB::table('channels')->count())->toBe(0);
    });
});

describe('noongun:discard-pending-channels', function () {
    it('removes pending channels that waited too long, in every workspace, and nothing else', function () {
        $other = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);

        $old = ($this->existing)('1', 'pending');
        $theirs = inWorkspace($other, fn () => Channel::factory()->pending()->create());
        $active = ($this->existing)('2');
        $disconnected = ($this->existing)('3', 'disconnected');

        $this->travel(Channel::PENDING_MINUTES + 1)->minutes();

        $fresh = ($this->existing)('4', 'pending');

        $this->artisan('noongun:discard-pending-channels')
            ->expectsOutput('Discarded 2 unconfirmed channel(s).')
            ->assertSuccessful();

        expect(DB::table('channels')->orderBy('id')->pluck('id')->all())->toBe([$active->id, $disconnected->id, $fresh->id])
            ->and(DB::table('channels')->whereIn('id', [$old->id, $theirs->id])->exists())->toBeFalse();
    });

    it('is on the schedule', function () {
        $this->artisan('schedule:list')->expectsOutputToContain('noongun:discard-pending-channels');
    });
});

it('has no selection step when nothing is waiting', function () {
    $this->actingAs($this->owner)->get(route('channels.selection.edit'))->assertRedirect(route('channels.index'));
    $this->actingAs($this->owner)->put(route('channels.selection.update'), ['keep' => [1]])->assertRedirect(route('channels.index'));
    $this->actingAs($this->owner)->delete(route('channels.selection.destroy'))->assertRedirect(route('channels.index'));
});
