<?php

use App\Domain\Channels\Contracts\Metric;
use App\Domain\Channels\Contracts\MetricReading;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Insights\Models\MetricSnapshot;
use App\Domain\Workspaces\Exceptions\CrossWorkspaceWrite;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';
require_once __DIR__.'/../../Support/insights.php';

itIsolatesWorkspaces(fn () => MetricSnapshot::factory()->create());

describe('a number the platform stops giving', function () {
    beforeEach(function () {
        $this->record = fn (array $numbers, array $claimed) => inWorkspace($this->workspace, fn () => MetricSnapshot::record(
            $this->variant,
            new MetricReading(...$numbers),
            null,
            $claimed,
        ));
    });

    it('is carried forward while the channel can read the metric, and the row says since when', function () {
        ($this->record)(['reactions' => 12, 'clicks' => 40, 'reach' => 900], Metric::cases());

        $this->travel(1)->hours();
        $same = ($this->record)(['reactions' => 12], Metric::cases());

        // No number changed: no new row, only the note and the time.
        expect(snapshotsOf($this->variant))->toHaveCount(1)
            ->and($same->reading()->toArray())->toBe(['reactions' => 12, 'comments' => null, 'shares' => null, 'clicks' => 40, 'reach' => 900])
            ->and($same->checked_at->toDateTimeString())->toBe('2026-11-26 11:00:00')
            ->and($same->carriedSince(Metric::Clicks)?->toDateTimeString())->toBe('2026-11-26 11:00:00')
            ->and($same->carriedSince(Metric::Reach)?->toDateTimeString())->toBe('2026-11-26 11:00:00')
            ->and($same->carriedSince(Metric::Reactions))->toBeNull();

        $this->travel(1)->hours();
        $changed = ($this->record)(['reactions' => 14], Metric::cases());

        // Another number changed: a new row that still holds the carried
        // ones, and still says since when they are.
        expect(snapshotsOf($this->variant))->toHaveCount(2)
            ->and($changed->reading()->toArray())->toBe(['reactions' => 14, 'comments' => null, 'shares' => null, 'clicks' => 40, 'reach' => 900])
            ->and($changed->carriedSince(Metric::Clicks)?->toDateTimeString())->toBe('2026-11-26 11:00:00');
    });

    it('is no longer marked once the platform gives it again', function () {
        ($this->record)(['reactions' => 12, 'clicks' => 40, 'reach' => 900], Metric::cases());
        $this->travel(1)->hours();
        ($this->record)(['reactions' => 12], Metric::cases());
        $this->travel(1)->hours();

        $back = ($this->record)(['reactions' => 12, 'clicks' => 40, 'reach' => 950], Metric::cases());

        expect($back->reading()->toArray())->toBe(['reactions' => 12, 'comments' => null, 'shares' => null, 'clicks' => 40, 'reach' => 950])
            ->and($back->carried)->toBeNull()
            ->and(DB::table('metric_snapshots')->where('id', $back->id)->value('carried'))->toBeNull();
    });

    it('becomes "not given" when the channel no longer reads the metric, or nobody says what it reads', function (array $claimed) {
        ($this->record)(['reactions' => 12, 'clicks' => 40], Metric::cases());
        $this->travel(1)->hours();

        $now = ($this->record)(['reactions' => 12], $claimed);

        expect(snapshotsOf($this->variant))->toHaveCount(2)
            ->and($now->clicks)->toBeNull()
            ->and($now->carried)->toBeNull();
    })->with([
        'the metric is not claimed' => [[Metric::Reactions, Metric::Comments, Metric::Shares]],
        'nothing is claimed' => [[]],
    ]);

    it('never turns a zero into a carried number, and has nothing to carry on a first reading', function () {
        $first = ($this->record)(['reactions' => 12], Metric::cases());

        expect($first->carried)->toBeNull();

        $this->travel(1)->hours();
        $zero = ($this->record)(['reactions' => 0, 'clicks' => 0], Metric::cases());

        expect($zero->reading()->toArray())->toBe(['reactions' => 0, 'comments' => null, 'shares' => null, 'clicks' => 0, 'reach' => null])
            ->and($zero->carried)->toBeNull();
    });

    it('still stores nothing for a reading without any number', function () {
        ($this->record)(['reactions' => 12], Metric::cases());

        expect(($this->record)([], Metric::cases()))->toBeNull()
            ->and(snapshotsOf($this->variant))->toHaveCount(1)
            ->and(snapshotsOf($this->variant)[0]->carried)->toBeNull();
    });
});

beforeEach(function () {
    $this->travelTo('2026-11-26 10:00:00');

    $this->workspace = Workspace::factory()->create();
    $this->variant = publishedVariant($this->workspace);
});

it('stores the first reading as the current numbers, in UTC', function () {
    $snapshot = snapshotFor($this->variant, ['reactions' => 12, 'comments' => 0], new DateTimeImmutable('2026-11-26 12:00:00', new DateTimeZone('Africa/Johannesburg')));

    expect($snapshot->is_latest)->toBeTrue()
        ->and($snapshot->workspace_id)->toBe($this->workspace->id)
        ->and($snapshot->captured_at->toDateTimeString())->toBe('2026-11-26 10:00:00')
        ->and($snapshot->checked_at->toDateTimeString())->toBe('2026-11-26 10:00:00')
        ->and($snapshot->reading()->toArray())->toBe(['reactions' => 12, 'comments' => 0, 'shares' => null, 'clicks' => null, 'reach' => null]);

    expect(DB::table('metric_snapshots')->where('id', $snapshot->id)->value('shares'))->toBeNull()
        ->and(DB::table('metric_snapshots')->where('id', $snapshot->id)->value('comments'))->toBe(0);
});

it('adds no row when the numbers are the same, and only moves checked_at', function () {
    $first = snapshotFor($this->variant, ['reactions' => 12, 'comments' => 3]);

    $this->travel(1)->hours();
    $again = snapshotFor($this->variant, ['reactions' => 12, 'comments' => 3]);

    expect(snapshotsOf($this->variant))->toHaveCount(1)
        ->and($again->id)->toBe($first->id)
        ->and($again->captured_at->toDateTimeString())->toBe('2026-11-26 10:00:00')
        ->and($again->checked_at->toDateTimeString())->toBe('2026-11-26 11:00:00');
});

it('adds a row when a number changed, and keeps the older one as history', function () {
    snapshotFor($this->variant, ['reactions' => 12, 'comments' => 3]);

    $this->travel(1)->hours();
    snapshotFor($this->variant, ['reactions' => 15, 'comments' => 3]);

    $this->travel(1)->hours();
    // A number the platform stops giving is a change too, and it is not zero.
    snapshotFor($this->variant, ['reactions' => 15]);

    $rows = snapshotsOf($this->variant);

    expect($rows)->toHaveCount(3)
        ->and(array_map(fn (MetricSnapshot $row): bool => $row->is_latest, $rows))->toBe([false, false, true])
        ->and(array_map(fn (MetricSnapshot $row): ?int => $row->reactions, $rows))->toBe([12, 15, 15])
        ->and($rows[2]->comments)->toBeNull()
        ->and($rows[0]->checked_at->toDateTimeString())->toBe('2026-11-26 10:00:00');
});

it('does not move checked_at backwards', function () {
    snapshotFor($this->variant, ['reactions' => 1], now());
    $earlier = snapshotFor($this->variant, ['reactions' => 1], now()->subHour());

    expect($earlier->checked_at->toDateTimeString())->toBe('2026-11-26 10:00:00');
});

it('stores nothing for a reading without any number', function () {
    $stored = inWorkspace($this->workspace, fn () => MetricSnapshot::record($this->variant, new MetricReading));

    expect($stored)->toBeNull()
        ->and(snapshotsOf($this->variant))->toBe([]);
});

it('refuses numbers for a variant that is not published', function () {
    $draft = inWorkspace($this->workspace, fn () => PostVariant::factory()->create());

    expect(fn () => inWorkspace($this->workspace, fn () => MetricSnapshot::record($draft, new MetricReading(reactions: 1))))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses numbers for a variant of another workspace', function () {
    $other = Workspace::factory()->create();

    expect(fn () => inWorkspace($other, fn () => MetricSnapshot::record($this->variant, new MetricReading(reactions: 1))))
        ->toThrow(CrossWorkspaceWrite::class);

    expect(snapshotsOf($this->variant))->toBe([]);
});

it('lets the database refuse what the model would not write', function (array $values) {
    $row = [
        'workspace_id' => $this->workspace->id,
        'post_variant_id' => $this->variant->id,
        'captured_at' => now(),
        'checked_at' => now(),
        'reactions' => 1,
        'is_latest' => true,
        ...$values,
    ];

    expect(fn () => DB::table('metric_snapshots')->insert($row))->toThrow(QueryException::class);
})->with([
    'a negative number' => [['reactions' => -1]],
    'no number at all' => [['reactions' => null]],
    'checked before captured' => [['checked_at' => '2026-11-26 09:00:00']],
    'a variant of another workspace' => fn () => ['workspace_id' => Workspace::factory()->create()->id],
]);

it('lets the database refuse a second current row for one variant', function () {
    snapshotFor($this->variant, ['reactions' => 1]);

    expect(fn () => DB::table('metric_snapshots')->insert([
        'workspace_id' => $this->workspace->id,
        'post_variant_id' => $this->variant->id,
        'captured_at' => now(),
        'checked_at' => now(),
        'reactions' => 2,
        'is_latest' => true,
    ]))->toThrow(QueryException::class);
});
