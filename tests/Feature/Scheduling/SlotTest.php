<?php

use App\Domain\Scheduling\Models\Slot;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/posts.php';

itIsolatesWorkspaces(fn () => Slot::factory()->create());

beforeEach(function () {
    $this->copenhagen = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->johannesburg = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
});

function utcTime(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'UTC');
}

it('stores the weekday and a wall-clock time, not an instant', function () {
    $slot = slotIn($this->copenhagen, weekday: 2, time: '09:00');

    $row = DB::table('slots')->where('id', $slot->id)->first();
    $slot = inWorkspace($this->copenhagen, fn () => Slot::with('channel')->findOrFail($slot->id));

    expect($row->weekday)->toBe(2)
        ->and($row->time)->toBe('09:00:00')
        ->and($slot->weekday)->toBe(2)
        ->and($slot->time)->toBe('09:00')
        ->and($slot->channel?->workspace_id)->toBe($this->copenhagen->id);
});

it('accepts a time of day in hours and minutes only', function (string $time) {
    expect(fn () => slotIn($this->copenhagen, time: $time))->toThrow(InvalidArgumentException::class);
})->with(['9:00', '24:00', '09:60', '09:00:30', 'noon', '']);

it('reads a time with seconds as the database gives it', function () {
    expect(slotIn($this->copenhagen, time: '18:30:00')->time)->toBe('18:30');
});

it('has ISO weekdays, Monday 1 to Sunday 7', function (int $weekday) {
    expect(fn () => slotIn($this->copenhagen, weekday: $weekday))->toThrow(QueryException::class);
})->with([0, 8]);

it('has each time once per channel and weekday', function () {
    $channel = channelIn($this->copenhagen);

    slotIn($this->copenhagen, $channel, 2, '09:00');
    slotIn($this->copenhagen, $channel, 3, '09:00');
    slotIn($this->copenhagen, $channel, 2, '17:00');

    expect(fn () => slotIn($this->copenhagen, $channel, 2, '09:00'))->toThrow(QueryException::class);
});

it('cannot point at a channel of another workspace', function () {
    $theirs = channelIn($this->johannesburg);

    expect(fn () => slotIn($this->copenhagen, $theirs))->toThrow(QueryException::class);
});

it('goes when its channel is deleted', function () {
    $channel = channelIn($this->copenhagen);
    $slot = slotIn($this->copenhagen, $channel);

    inWorkspace($this->copenhagen, fn () => $channel->delete());

    expect(DB::table('slots')->where('id', $slot->id)->exists())->toBeFalse();
});

describe('turning the local time into a UTC instant', function () {
    it('is one hour behind in Copenhagen in winter and two in summer', function () {
        $slot = slotIn($this->copenhagen, weekday: 2, time: '09:00');

        inWorkspace($this->copenhagen, function () use ($slot) {
            // Tuesday 12 January 2027, CET (UTC+1).
            expect($slot->occurrenceInWeekOf(utcTime('2027-01-14 12:00')))->toEqual(utcTime('2027-01-12 08:00'))
                // Tuesday 13 July 2027, CEST (UTC+2).
                ->and($slot->occurrenceInWeekOf(utcTime('2027-07-15 12:00')))->toEqual(utcTime('2027-07-13 07:00'))
                ->and($slot->occurrenceInWeekOf(utcTime('2027-07-15 12:00'))->getTimezone()->getName())->toBe('UTC');
        });
    });

    it('is two hours behind in Johannesburg all year', function () {
        $slot = slotIn($this->johannesburg, weekday: 2, time: '09:00');

        inWorkspace($this->johannesburg, function () use ($slot) {
            expect($slot->occurrenceInWeekOf(utcTime('2027-01-14 12:00')))->toEqual(utcTime('2027-01-12 07:00'))
                ->and($slot->occurrenceInWeekOf(utcTime('2027-07-15 12:00')))->toEqual(utcTime('2027-07-13 07:00'));
        });
    });

    it('keeps the wall-clock time across the change to summer time', function () {
        // Clocks go forward in Copenhagen on Sunday 28 March 2027.
        $slot = slotIn($this->copenhagen, weekday: 1, time: '09:00');

        inWorkspace($this->copenhagen, function () use ($slot) {
            expect($slot->occurrenceInWeekOf(utcTime('2027-03-24 12:00')))->toEqual(utcTime('2027-03-22 08:00'))
                ->and($slot->occurrenceInWeekOf(utcTime('2027-03-31 12:00')))->toEqual(utcTime('2027-03-29 07:00'));
        });
    });

    it('moves a time the clocks skip forward by an hour, as the calendar does', function () {
        $slot = slotIn($this->copenhagen, weekday: 7, time: '02:30');

        inWorkspace($this->copenhagen, function () use ($slot) {
            // 02:30 does not exist on 28 March 2027; it becomes 03:30 CEST.
            expect($slot->occurrenceInWeekOf(utcTime('2027-03-24 12:00')))->toEqual(utcTime('2027-03-28 01:30'));
        });
    });

    it('takes the week from the workspace, where Monday starts at local midnight', function () {
        // Sunday 23:30 UTC is already Monday in both timezones.
        $instant = utcTime('2027-01-10 23:30');

        $monday = slotIn($this->copenhagen, weekday: 1, time: '09:00');
        $sunday = slotIn($this->johannesburg, weekday: 7, time: '20:00');

        expect(inWorkspace($this->copenhagen, fn () => $monday->occurrenceInWeekOf($instant)))->toEqual(utcTime('2027-01-11 08:00'))
            ->and(inWorkspace($this->johannesburg, fn () => $sunday->occurrenceInWeekOf($instant)))->toEqual(utcTime('2027-01-17 18:00'));
    });

    it('finds the next time the slot comes up', function () {
        $slot = slotIn($this->johannesburg, weekday: 3, time: '12:00');

        inWorkspace($this->johannesburg, function () use ($slot) {
            // Wednesday 13 January 2027 at 12:00 SAST is 10:00 UTC.
            expect($slot->nextOccurrence(utcTime('2027-01-11 06:00')))->toEqual(utcTime('2027-01-13 10:00'))
                ->and($slot->nextOccurrence(utcTime('2027-01-13 09:59')))->toEqual(utcTime('2027-01-13 10:00'))
                ->and($slot->nextOccurrence(utcTime('2027-01-13 10:00')))->toEqual(utcTime('2027-01-20 10:00'))
                ->and($slot->nextOccurrence(utcTime('2027-01-17 23:00')))->toEqual(utcTime('2027-01-20 10:00'));

            $this->travelTo(utcTime('2027-01-14 08:00'));

            expect($slot->nextOccurrence())->toEqual(utcTime('2027-01-20 10:00'));
        });
    });
});
