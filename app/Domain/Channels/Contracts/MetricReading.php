<?php

namespace App\Domain\Channels\Contracts;

use InvalidArgumentException;

/**
 * What a platform reported about one published variant at one moment.
 *
 * Null and zero are different statements. Null: the platform did not give
 * this number (it has none, the permission for it is missing, or its answer
 * could not be trusted). Zero: the platform said zero. Nothing downstream
 * may turn one into the other.
 *
 * Every number is a whole number from 0 to MAX. Adapters build a reading
 * from a platform answer with fromPlatform(), which drops what is not such
 * a number instead of failing.
 */
final readonly class MetricReading
{
    /** No post has a count near this. A larger value is a broken answer. */
    public const MAX = 1_000_000_000_000;

    public function __construct(
        public ?int $reactions = null,
        public ?int $comments = null,
        public ?int $shares = null,
        public ?int $clicks = null,
        public ?int $reach = null,
    ) {
        foreach ($this->toArray() as $name => $value) {
            if ($value !== null && ($value < 0 || $value > self::MAX)) {
                throw new InvalidArgumentException("The metric {$name} must be a whole number from 0 to ".self::MAX.'.');
            }
        }
    }

    /**
     * A reading from values a platform sent, keyed by Metric value. Platform
     * answers are untrusted: a value that is not a whole number from 0 to
     * MAX (negative, a fraction, text, a boolean, far too large) becomes
     * null, and unknown keys are ignored. Whole numbers written as digit
     * strings are accepted.
     *
     * @param  array<array-key, mixed>  $values
     */
    public static function fromPlatform(array $values): self
    {
        return new self(
            reactions: self::wholeNumber($values[Metric::Reactions->value] ?? null),
            comments: self::wholeNumber($values[Metric::Comments->value] ?? null),
            shares: self::wholeNumber($values[Metric::Shares->value] ?? null),
            clicks: self::wholeNumber($values[Metric::Clicks->value] ?? null),
            reach: self::wholeNumber($values[Metric::Reach->value] ?? null),
        );
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

    /**
     * The same reading with every metric that is not listed set to null.
     *
     * @param  list<Metric>  $metrics
     */
    public function only(array $metrics): self
    {
        $keep = fn (Metric $metric): ?int => in_array($metric, $metrics, true) ? $this->get($metric) : null;

        return new self(
            reactions: $keep(Metric::Reactions),
            comments: $keep(Metric::Comments),
            shares: $keep(Metric::Shares),
            clicks: $keep(Metric::Clicks),
            reach: $keep(Metric::Reach),
        );
    }

    /**
     * True when the platform gave no number at all.
     */
    public function isEmpty(): bool
    {
        return array_filter($this->toArray(), fn (?int $value): bool => $value !== null) === [];
    }

    public function equals(self $other): bool
    {
        return $this->toArray() === $other->toArray();
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

    private static function wholeNumber(mixed $value): ?int
    {
        if (is_string($value) && strlen($value) <= 13 && ctype_digit($value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= 0 && $value <= self::MAX ? $value : null;
    }
}
