<?php

use App\Domain\Content\PostState;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/members.php';
require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';

beforeEach(function () {
    // A Wednesday.
    $this->travelTo('2026-11-25 10:00:00');

    $this->workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->owner = workspaceMember($this->workspace, Role::Owner);
    $this->channel = channelIn($this->workspace, ['display_name' => 'Alpha']);
});

/**
 * @return list<string> "weekday time" of every slot of the channel, in order
 */
function slotsOf(int $channelId): array
{
    return DB::table('slots')->where('channel_id', $channelId)->orderBy('weekday')->orderBy('time')->get()
        ->map(fn (stdClass $slot): string => $slot->weekday.' '.substr($slot->time, 0, 5))
        ->all();
}

describe('list', function () {
    it('shows the slots with their next occurrence, and only channels that can take one', function (Role $role, bool $mayManage) {
        $reconnect = channelIn($this->workspace, ['display_name' => 'Bravo', 'status' => 'needs_reconnect']);
        channelIn($this->workspace, ['display_name' => 'Gone', 'status' => 'disconnected', 'access_token' => null]);
        channelIn($this->workspace, ['display_name' => 'Unconfirmed', 'status' => 'pending']);

        $friday = slotIn($this->workspace, $this->channel, 5, '12:00');
        $monday = slotIn($this->workspace, $this->channel, 1, '09:00');

        $response = $this->actingAs(workspaceMember($this->workspace, $role))->get(route('slots.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('brief/Slots')
            ->where('channels', [
                ['id' => $this->channel->id, 'display_name' => 'Alpha', 'platform_label' => 'Facebook', 'offered' => true, 'can_publish' => true],
                ['id' => $reconnect->id, 'display_name' => 'Bravo', 'platform_label' => 'Facebook', 'offered' => true, 'can_publish' => false],
            ])
            ->where('slots', [
                // Monday 09:00 in Copenhagen has passed this week: the next is 30 November, 08:00 UTC.
                ['id' => $monday->id, 'channel_id' => $this->channel->id, 'weekday' => 1, 'time' => '09:00', 'next_at' => '2026-11-30T08:00:00+00:00'],
                ['id' => $friday->id, 'channel_id' => $this->channel->id, 'weekday' => 5, 'time' => '12:00', 'next_at' => '2026-11-27T11:00:00+00:00'],
            ])
            ->where('workspace.timezone', 'Europe/Copenhagen')
            ->where('can.manage', $mayManage));

        expect($response->getContent())->not->toContain('test-access-');
    })->with([
        'owner' => [Role::Owner, true],
        'editor' => [Role::Editor, true],
        'viewer' => [Role::Viewer, false],
    ]);

    it('still lists the slots of a channel that was disconnected, without offering it', function () {
        $slot = slotIn($this->workspace, $this->channel, 2, '08:30');

        inWorkspace($this->workspace, fn () => $this->channel->disconnect());

        $this->actingAs($this->owner)->get(route('slots.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('channels', 1)
                ->where('channels.0.id', $this->channel->id)
                ->where('channels.0.offered', false)
                ->where('slots.0.id', $slot->id));
    });

    it('is not shown to someone without a workspace, and guests are sent to the login page', function () {
        $this->actingAs(User::factory()->create())->get(route('slots.index'))->assertForbidden();

        auth()->logout();

        $this->get(route('slots.index'))->assertRedirect(route('login'));
    });
});

describe('timezones', function () {
    it('reads the time on the clock of the workspace', function (string $timezone, string $now, string $next) {
        $this->travelTo($now);

        $workspace = Workspace::factory()->create(['timezone' => $timezone]);
        $channel = channelIn($workspace);

        $this->actingAs(workspaceMember($workspace))
            ->post(route('slots.store'), ['channel_id' => $channel->id, 'weekdays' => [2], 'time' => '09:00'])
            ->assertSessionHasNoErrors();

        // Stored as typed: a wall-clock time, not an instant.
        expect(slotsOf($channel->id))->toBe(['2 09:00']);

        $this->get(route('slots.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('slots.0.time', '09:00')
                ->where('slots.0.next_at', $next)
                ->where('workspace.timezone', $timezone));
    })->with([
        'Copenhagen in winter, UTC+1' => ['Europe/Copenhagen', '2026-11-25 10:00:00', '2026-12-01T08:00:00+00:00'],
        'Copenhagen in summer, UTC+2' => ['Europe/Copenhagen', '2026-06-24 10:00:00', '2026-06-30T07:00:00+00:00'],
        'Copenhagen across the change to winter time' => ['Europe/Copenhagen', '2026-10-21 10:00:00', '2026-10-27T08:00:00+00:00'],
        'Johannesburg, UTC+2 all year' => ['Africa/Johannesburg', '2026-11-25 10:00:00', '2026-12-01T07:00:00+00:00'],
        'Johannesburg in June' => ['Africa/Johannesburg', '2026-06-24 10:00:00', '2026-06-30T07:00:00+00:00'],
    ]);

    it('takes today when the time is still ahead on the clock of the workspace', function (string $timezone, string $next) {
        // Wednesday 06:30 UTC is 07:30 in Copenhagen and 08:30 in Johannesburg.
        $this->travelTo('2026-11-25 06:30:00');

        $workspace = Workspace::factory()->create(['timezone' => $timezone]);
        slotIn($workspace, channelIn($workspace), 3, '08:00');

        $this->actingAs(workspaceMember($workspace))->get(route('slots.index'))
            ->assertInertia(fn (Assert $page) => $page->where('slots.0.next_at', $next));
    })->with([
        'Copenhagen: 08:00 is still to come today' => ['Europe/Copenhagen', '2026-11-25T07:00:00+00:00'],
        'Johannesburg: 08:00 has passed, so next week' => ['Africa/Johannesburg', '2026-12-02T06:00:00+00:00'],
    ]);
});

describe('creating', function () {
    it('adds one slot per chosen day', function (Role $role) {
        $this->actingAs(workspaceMember($this->workspace, $role))
            ->post(route('slots.store'), ['channel_id' => $this->channel->id, 'weekdays' => [5, 1, 3], 'time' => '09:00'])
            ->assertRedirect(route('slots.index'))
            ->assertSessionHasNoErrors();

        expect(slotsOf($this->channel->id))->toBe(['1 09:00', '3 09:00', '5 09:00'])
            ->and(DB::table('slots')->pluck('workspace_id')->unique()->all())->toBe([$this->workspace->id]);
    })->with(['owner' => Role::Owner, 'editor' => Role::Editor]);

    it('does not add a slot twice', function () {
        slotIn($this->workspace, $this->channel, 1, '09:00');

        $this->actingAs($this->owner)
            ->post(route('slots.store'), ['channel_id' => $this->channel->id, 'weekdays' => [1, 2], 'time' => '09:00'])
            ->assertSessionHasNoErrors();

        $this->post(route('slots.store'), ['channel_id' => $this->channel->id, 'weekdays' => [1, 2], 'time' => '09:00'])
            ->assertSessionHasNoErrors();

        expect(slotsOf($this->channel->id))->toBe(['1 09:00', '2 09:00']);
    });

    it('takes a channel that needs reconnecting, because its slots are a plan', function () {
        $reconnect = channelIn($this->workspace, ['status' => 'needs_reconnect']);

        $this->actingAs($this->owner)
            ->post(route('slots.store'), ['channel_id' => $reconnect->id, 'weekdays' => [1], 'time' => '09:00'])
            ->assertSessionHasNoErrors();

        expect(slotsOf($reconnect->id))->toBe(['1 09:00']);
    });

    it('refuses a channel that is disconnected, pending or not there', function (Closure $channelId) {
        $this->actingAs($this->owner)
            ->post(route('slots.store'), ['channel_id' => $channelId($this), 'weekdays' => [1], 'time' => '09:00'])
            ->assertSessionHasErrors('channel_id');

        expect(DB::table('slots')->count())->toBe(0);
    })->with([
        'disconnected' => [fn ($test) => channelIn($test->workspace, ['status' => 'disconnected', 'access_token' => null])->id],
        'pending' => [fn ($test) => channelIn($test->workspace, ['status' => 'pending'])->id],
        'of another workspace' => [fn ($test) => channelIn(Workspace::factory()->create())->id],
        'unknown' => [fn ($test) => 999999],
        'missing' => [fn ($test) => null],
        'not a number' => [fn ($test) => 'alpha'],
    ]);

    it('refuses a day or a time that is not one', function (array $payload, string $field) {
        $this->actingAs($this->owner)
            ->post(route('slots.store'), ['channel_id' => $this->channel->id, 'weekdays' => [1], 'time' => '09:00', ...$payload])
            ->assertSessionHasErrors($field);

        expect(DB::table('slots')->count())->toBe(0);
    })->with([
        'no day' => [['weekdays' => []], 'weekdays'],
        'day zero' => [['weekdays' => [0]], 'weekdays.0'],
        'day eight' => [['weekdays' => [8]], 'weekdays.0'],
        'a day in words' => [['weekdays' => ['monday']], 'weekdays.0'],
        'the same day twice' => [['weekdays' => [1, 1]], 'weekdays.0'],
        'days that are not a list' => [['weekdays' => 1], 'weekdays'],
        'no time' => [['time' => ''], 'time'],
        'a time with seconds' => [['time' => '09:00:30'], 'time'],
        'an hour that does not exist' => [['time' => '24:00'], 'time'],
        'a time in words' => [['time' => 'noon'], 'time'],
        'a date and a time' => [['time' => '2026-11-25 09:00'], 'time'],
    ]);

    it('is refused for viewers', function () {
        $this->actingAs(workspaceMember($this->workspace, Role::Viewer))
            ->post(route('slots.store'), ['channel_id' => $this->channel->id, 'weekdays' => [1], 'time' => '09:00'])
            ->assertForbidden();

        expect(DB::table('slots')->count())->toBe(0);
    });
});

describe('deleting', function () {
    beforeEach(function () {
        $this->slot = slotIn($this->workspace, $this->channel, 1, '09:00');
    });

    it('removes the slot and leaves posts as they are', function (Role $role) {
        fakeChannelAdapter();

        $post = postInState($this->workspace, PostState::Draft, [$this->channel], now()->addDay());
        $planned = DB::table('posts')->where('id', $post->id)->value('scheduled_at');

        $this->actingAs(workspaceMember($this->workspace, $role))
            ->delete(route('slots.destroy', $this->slot->id))
            ->assertRedirect(route('slots.index'));

        expect(DB::table('slots')->count())->toBe(0)
            ->and(DB::table('posts')->where('id', $post->id)->value('scheduled_at'))->toBe($planned);
    })->with(['owner' => Role::Owner, 'editor' => Role::Editor]);

    it('is refused for viewers and answers 404 for a slot that does not exist', function () {
        $this->actingAs(workspaceMember($this->workspace, Role::Viewer))
            ->delete(route('slots.destroy', $this->slot->id))
            ->assertForbidden();

        $this->actingAs($this->owner)->delete(route('slots.destroy', 999999))->assertNotFound();

        expect(DB::table('slots')->count())->toBe(1);
    });
});

describe('another workspace', function () {
    beforeEach(function () {
        $this->slot = slotIn($this->workspace, $this->channel, 4, '17:45');
        $this->elsewhere = Workspace::factory()->create();
        $this->outsider = workspaceMember($this->elsewhere, Role::Owner);
    });

    it('does not see the slot or its channel', function () {
        $response = $this->actingAs($this->outsider)->get(route('slots.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('slots', [])
            ->where('channels', []));

        expect($response->getContent())->not->toContain('17:45')->not->toContain('Alpha');
    });

    it('cannot delete the slot or add one to our channel', function () {
        $this->actingAs($this->outsider)->delete(route('slots.destroy', $this->slot->id))->assertNotFound();

        $this->post(route('slots.store'), ['channel_id' => $this->channel->id, 'weekdays' => [1], 'time' => '09:00'])
            ->assertSessionHasErrors('channel_id');

        expect(slotsOf($this->channel->id))->toBe(['4 17:45']);
    });
});
