<?php

use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Note;

beforeEach(fn () => Note::createTable());

it('runs the application in UTC', function () {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(date_default_timezone_get())->toBe('UTC');
});

it('stores local times as UTC and shows them back in the workspace timezone', function (string $timezone, string $local, string $utc) {
    $workspace = Workspace::factory()->create(['timezone' => $timezone]);

    $note = inWorkspace($workspace, fn () => Note::create([
        'body' => 'Post at noon',
        'remind_at' => $workspace->toUtc($local),
    ]));

    $stored = DB::table('test_notes')->where('id', $note->id)->value('remind_at');
    $reloaded = inWorkspace($workspace, fn () => Note::findOrFail($note->id));

    expect($stored)->toBe($utc)
        ->and($workspace->toLocal($reloaded->remind_at)->format('Y-m-d H:i:s'))->toBe($local);
})->with([
    'Copenhagen winter (CET, UTC+1)' => ['Europe/Copenhagen', '2026-01-15 12:00:00', '2026-01-15 11:00:00'],
    'Copenhagen summer (CEST, UTC+2)' => ['Europe/Copenhagen', '2026-07-15 12:00:00', '2026-07-15 10:00:00'],
    'Copenhagen just before spring DST' => ['Europe/Copenhagen', '2026-03-29 01:30:00', '2026-03-29 00:30:00'],
    'Copenhagen just after spring DST' => ['Europe/Copenhagen', '2026-03-29 03:30:00', '2026-03-29 01:30:00'],
    'Copenhagen just before autumn DST' => ['Europe/Copenhagen', '2026-10-25 01:30:00', '2026-10-24 23:30:00'],
    'Copenhagen just after autumn DST' => ['Europe/Copenhagen', '2026-10-25 03:30:00', '2026-10-25 02:30:00'],
    'Johannesburg (SAST, UTC+2, no DST)' => ['Africa/Johannesburg', '2026-03-29 02:30:00', '2026-03-29 00:30:00'],
    'Johannesburg during Copenhagen autumn DST' => ['Africa/Johannesburg', '2026-10-25 02:30:00', '2026-10-25 00:30:00'],
]);

it('shows the same stored instant correctly in both workspaces', function () {
    $copenhagen = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $johannesburg = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);

    $instant = $johannesburg->toUtc('2026-03-29 12:00:00');

    expect($instant->format('Y-m-d H:i'))->toBe('2026-03-29 10:00')
        ->and($copenhagen->toLocal($instant)->format('Y-m-d H:i T'))->toBe('2026-03-29 12:00 CEST')
        ->and($johannesburg->toLocal($instant)->format('Y-m-d H:i T'))->toBe('2026-03-29 12:00 SAST');

    $winter = $johannesburg->toUtc('2026-03-28 12:00:00');
    expect($copenhagen->toLocal($winter)->format('Y-m-d H:i T'))->toBe('2026-03-28 11:00 CET');
});

it('moves a Copenhagen time inside the spring DST gap forward by one hour', function () {
    $copenhagen = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);

    expect($copenhagen->toUtc('2026-03-29 02:30:00')->format('Y-m-d H:i'))->toBe('2026-03-29 01:30');
});
