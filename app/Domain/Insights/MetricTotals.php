<?php

namespace App\Domain\Insights;

use App\Domain\Channels\Contracts\Metric;

/**
 * The numbers of several variants added up. A total is null when none of
 * them had that number, and otherwise the sum of those that had it: null
 * still means "not given", never zero.
 */
final readonly class MetricTotals
{
    public function __construct(
        public ?int $reactions = null,
        public ?int $comments = null,
        public ?int $shares = null,
        public ?int $clicks = null,
        public ?int $reach = null,
    ) {}

    /**
     * From a row of sums read from the database, where each sum is null, an
     * integer or a string of digits.
     */
    public static function fromRow(object $row): self
    {
        $sum = fn (string $column): ?int => is_numeric($row->{$column} ?? null) ? (int) $row->{$column} : null;

        return new self($sum('reactions'), $sum('comments'), $sum('shares'), $sum('clicks'), $sum('reach'));
    }

    public function get(Metric $metric): ?int
    {
        return match ($metric) {
            Metric::Reactions => $this->reactions,
            Metric::Comments => $this->comments,
            Metric::Shares => $this->shares,
            Metric::Clicks => $this->clicks,
            Metric::Reach => $this->reach,
        };
    }

    public function score(): ?float
    {
        return EngagementScore::of($this);
    }

    /**
     * @return array{reactions: int|null, comments: int|null, shares: int|null, clicks: int|null, reach: int|null}
     */
    public function toArray(): array
    {
        return [
            'reactions' => $this->reactions,
            'comments' => $this->comments,
            'shares' => $this->shares,
            'clicks' => $this->clicks,
            'reach' => $this->reach,
        ];
    }
}
