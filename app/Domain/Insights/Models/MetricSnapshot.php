<?php

namespace App\Domain\Insights\Models;

use App\Domain\Channels\Contracts\Metric;
use App\Domain\Channels\Contracts\MetricReading;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Insights\EngagementScore;
use App\Domain\Workspaces\Concerns\BelongsToWorkspace;
use App\Domain\Workspaces\Exceptions\CrossWorkspaceWrite;
use Carbon\CarbonImmutable;
use Database\Factories\MetricSnapshotFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The numbers of one published variant as the platform reported them, from
 * captured_at until at least checked_at. All times are UTC.
 *
 * Rows are written only by record(). A row is added when a number differs
 * from the newest row of the variant; a reading that repeats it only moves
 * checked_at. So the rows of a variant are its history without repeats, the
 * newest carries is_latest, and a variant never has more rows than it was
 * read times (the cadence stops, see InsightsConfig). Nothing is deleted
 * while the variant exists.
 *
 * A metric that is null was not given by the platform. That is not zero.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $post_variant_id
 * @property CarbonImmutable $captured_at
 * @property CarbonImmutable $checked_at
 * @property int|null $reactions
 * @property int|null $comments
 * @property int|null $shares
 * @property int|null $clicks
 * @property int|null $reach
 * @property bool $is_latest
 * @property array<string, string>|null $carried metric name to the time (ISO 8601, UTC) since which its number is carried forward because the platform stopped giving it; see record()
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read PostVariant|null $variant
 */
#[Fillable(['post_variant_id', 'captured_at', 'checked_at', 'reactions', 'comments', 'shares', 'clicks', 'reach', 'carried', 'is_latest'])]
#[UseFactory(MetricSnapshotFactory::class)]
class MetricSnapshot extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<MetricSnapshotFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_latest' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'checked_at' => 'datetime',
            'reactions' => 'integer',
            'comments' => 'integer',
            'shares' => 'integer',
            'clicks' => 'integer',
            'reach' => 'integer',
            'is_latest' => 'boolean',
            'carried' => 'array',
        ];
    }

    /**
     * @return BelongsTo<PostVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(PostVariant::class, 'post_variant_id');
    }

    /**
     * The newest row of each variant.
     *
     * @param  Builder<MetricSnapshot>  $query
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->where($this->qualifyColumn('is_latest'), true);
    }

    public function reading(): MetricReading
    {
        return new MetricReading($this->reactions, $this->comments, $this->shares, $this->clicks, $this->reach);
    }

    public function score(): ?float
    {
        return EngagementScore::of($this->reading());
    }

    /**
     * Stores what the platform reported about a published variant of the
     * current workspace, and returns the row that now holds it.
     *
     * The same numbers as the newest row: that row's checked_at moves and
     * nothing is added. Different numbers, or no row yet: a new row becomes
     * the newest. A reading without any number is not stored and gives null.
     * Calling it twice with the same reading changes nothing the second
     * time, apart from checked_at.
     *
     * $claimed are the metrics the channel can still read (the caller's
     * ReadsMetrics::metricsFor()). A number the variant has for one of them
     * is never replaced by "not given": when the reading lacks it, the
     * number of the newest row is carried forward, and `carried` on the row
     * says since when. Platforms leave numbers out for reasons that say
     * nothing about the post: a metric that was renamed, a permission that
     * was withdrawn on their side, an empty answer. A metric that is not in
     * $claimed is stored as the reading has it, so a number does become
     * "not given" once the channel no longer lists the metric.
     *
     * @param  list<Metric>  $claimed
     *
     * @throws InvalidArgumentException when the variant is not published
     * @throws CrossWorkspaceWrite when the variant belongs to another workspace
     */
    public static function record(PostVariant $variant, MetricReading $reading, ?DateTimeInterface $at = null, array $claimed = []): ?static
    {
        static::guardWorkspace($variant);

        if (! $variant->isPublished()) {
            throw new InvalidArgumentException("Variant #{$variant->id} is not published, so it has no numbers to store.");
        }

        if ($reading->isEmpty()) {
            return null;
        }

        $at = CarbonImmutable::instance($at ?? now())->utc();

        return DB::transaction(function () use ($variant, $reading, $at, $claimed): static {
            $latest = static::query()
                ->where('post_variant_id', $variant->id)
                ->current()
                ->lockForUpdate()
                ->first();

            $numbers = $reading->toArray();
            $carried = [];

            foreach ($latest === null ? [] : $claimed as $metric) {
                $known = $latest->reading()->get($metric);

                if ($numbers[$metric->value] === null && $known !== null) {
                    $numbers[$metric->value] = $known;
                    $carried[$metric->value] = $latest->carried[$metric->value] ?? $at->toIso8601String();
                }
            }

            ksort($carried);
            $reading = new MetricReading(...$numbers);
            $carried = $carried === [] ? null : $carried;

            if ($latest !== null && $latest->reading()->equals($reading)) {
                if ($at->greaterThan($latest->checked_at)) {
                    $latest->checked_at = $at;
                }

                if ($latest->carried !== $carried) {
                    $latest->carried = $carried;
                }

                $latest->save();

                return $latest;
            }

            if ($latest !== null) {
                $latest->is_latest = false;
                $latest->save();
            }

            return static::query()->create([
                'post_variant_id' => $variant->id,
                'captured_at' => $at,
                'checked_at' => $at,
                ...$reading->toArray(),
                'carried' => $carried,
                'is_latest' => true,
            ]);
        });
    }

    /**
     * Since when the number of a metric on this row is the last one the
     * platform gave and not one it gave at checked_at. Null when the
     * platform gave it at checked_at, or the row has no such number.
     */
    public function carriedSince(Metric $metric): ?CarbonImmutable
    {
        $since = $this->carried[$metric->value] ?? null;

        return is_string($since) ? CarbonImmutable::parse($since)->utc() : null;
    }
}
