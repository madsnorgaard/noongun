<?php

namespace App\Http\Presenters;

use App\Domain\Channels\ChannelManager;
use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Contracts\Metric;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Insights\ChannelPolling;
use App\Domain\Insights\EngagementScore;
use App\Domain\Insights\InsightsConfig;
use App\Domain\Insights\MetricTotals;
use App\Domain\Insights\Models\MetricSnapshot;
use App\Domain\Insights\Performance;
use App\Domain\Insights\PerformanceSummary;
use App\Domain\Insights\PostPerformance;
use App\Domain\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * What the pages show of how published posts did: the numbers of a post for
 * each of its channels, and the summary of a period for the Insights page
 * and the dashboard. Read-only.
 *
 * A number that the platform did not give goes out as null with the reason
 * next to it, never as zero. Times go out as UTC instants and the pages
 * show them on the clock of the workspace. The sentences are written by the
 * pages (resources/js/lib/insights.ts); this class says which one applies.
 */
final class InsightsPresenter
{
    /** The periods the Insights page offers, in days. */
    public const PERIODS = [7, 28, 90];

    public const DEFAULT_PERIOD = 28;

    /** The period of the line on the dashboard, in days. */
    public const DASHBOARD_PERIOD = 7;

    /**
     * Minutes between two runs of noongun:poll-metrics, as scheduled in
     * routes/console.php. A post that was never read is read by the next
     * run, so its first reading is expected this long after it went out.
     */
    private const POLL_MINUTES = 10;

    private const TOP_POSTS = 10;

    private const EXCERPT_LENGTH = 90;

    /** @var array<int, array{platform: list<Metric>, channel: list<Metric>}> */
    private array $channelMetrics = [];

    public function __construct(
        private readonly ChannelManager $channels,
        private readonly Performance $performance,
        private readonly CurrentWorkspace $currentWorkspace,
    ) {}

    /**
     * Whether numbers are read on this instance, and how often, in words.
     *
     * @return array{enabled: bool, cadence: string, stop_after: string}
     */
    public function reading(): array
    {
        $steps = [];

        foreach (InsightsConfig::cadence() as $step) {
            $steps[] = self::every($step['every_minutes']).' until it is '.self::age($step['until_hours']).' old';
        }

        return [
            'enabled' => InsightsConfig::enabled(),
            'cadence' => 'A post is read '.implode(', then ', $steps).', and not after that.',
            'stop_after' => self::age(InsightsConfig::stopAfterHours()),
        ];
    }

    /**
     * The numbers of each post that has a text on a platform, keyed by post
     * id. A post that was never published is not in the answer. The posts
     * need their variants and the channels of those loaded. One query for
     * the current numbers, one more when the history is asked for.
     *
     * @param  iterable<Post>  $posts
     * @return array<int, array<string, mixed>>
     */
    public function forPosts(iterable $posts, bool $withHistory = false): array
    {
        $published = [];

        foreach ($posts as $post) {
            $variants = $post->variants->filter(fn (PostVariant $variant): bool => $variant->isPublished())->sortBy('id')->values()->all();

            if ($variants !== []) {
                $published[$post->id] = $variants;
            }
        }

        $variantIds = array_map(fn (PostVariant $variant): int => $variant->id, array_merge(...array_values($published)));
        $latest = $this->performance->latest($variantIds);
        $histories = $withHistory ? $this->performance->histories(array_keys($latest)) : [];
        $now = CarbonImmutable::now('UTC');
        $presented = [];

        foreach ($published as $postId => $variants) {
            $rows = [];
            $measured = [];

            foreach ($variants as $variant) {
                $snapshot = $latest[$variant->id] ?? null;
                $rows[] = $this->variant($variant, $snapshot, $withHistory ? ($histories[$variant->id] ?? []) : null, $now);

                if ($snapshot !== null) {
                    $measured[] = $snapshot;
                }
            }

            $presented[$postId] = [
                'variants' => $rows,
                'total' => $this->total($measured, count($variants)),
            ];
        }

        return $presented;
    }

    /**
     * The Insights page for the last $days days on the calendar of the
     * workspace, today included.
     *
     * @return array<string, mixed>
     */
    public function page(int $days): array
    {
        [$from, $until] = $this->period($days);

        $pillars = $this->performance->pillarSummaries($from, $until);
        $totals = $this->totals($pillars);
        $top = $totals['measured_posts'] > 0 ? $this->performance->topPosts($from, $until, self::TOP_POSTS) : [];

        return [
            'period' => [
                'days' => $days,
                'options' => self::PERIODS,
                'from' => $from->format('Y-m-d'),
                'until' => $until->subDay()->format('Y-m-d'),
            ],
            'reading' => $this->reading(),
            'weights' => $this->weights(),
            'status' => match (true) {
                $totals['posts'] === 0 => PostVariant::query()->whereNotNull('external_id')->exists() ? 'nothing_in_period' : 'nothing_published',
                $totals['measured_posts'] === 0 => 'nothing_measured',
                default => 'ok',
            },
            'totals' => $totals,
            'pillars' => $this->pillars($pillars),
            'top' => $this->top($top),
            'channels' => $this->channelRows($this->performance->channelSummaries($from, $until)),
        ];
    }

    /**
     * The line on the dashboard: what the last week added up to and the
     * post that did best. Null when no post of the week is measured.
     *
     * @return array<string, mixed>|null
     */
    public function lastWeek(): ?array
    {
        [$from, $until] = $this->period(self::DASHBOARD_PERIOD);

        $totals = $this->totals($this->performance->pillarSummaries($from, $until));

        if ($totals['measured_posts'] === 0) {
            return null;
        }

        return [
            'days' => self::DASHBOARD_PERIOD,
            ...$totals,
            'best' => $this->top($this->performance->topPosts($from, $until, 1))[0] ?? null,
        ];
    }

    public static function periodFrom(mixed $value): int
    {
        return is_string($value) && ctype_digit($value) && in_array((int) $value, self::PERIODS, true)
            ? (int) $value
            : self::DEFAULT_PERIOD;
    }

    /**
     * The first instant of the period and the first instant after it. The
     * days are days of the workspace: a post that went out at 23:30 there
     * belongs to that day, whatever the date was in UTC.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function period(int $days): array
    {
        $today = CarbonImmutable::now($this->currentWorkspace->getOrFail()->timezone)->startOfDay();

        return [$today->subDays(max($days, 1) - 1), $today->addDay()];
    }

    /**
     * @param  list<MetricSnapshot>|null  $history
     * @return array<string, mixed>
     */
    private function variant(PostVariant $variant, ?MetricSnapshot $snapshot, ?array $history, CarbonImmutable $now): array
    {
        $channel = $variant->channel;
        $metrics = $channel !== null ? $this->metricsOf($channel) : ['platform' => [], 'channel' => []];
        $status = $channel !== null ? $channel->status : ChannelStatus::Disconnected;
        $publishedAt = $variant->published_at;
        $stopped = $publishedAt === null || $publishedAt->lessThanOrEqualTo($now->subHours(InsightsConfig::stopAfterHours()));
        $read = $status === ChannelStatus::Active;
        // The platform said often enough that the post is not there any
        // more, and it is not asked about again (see DueVariants).
        $gone = $variant->metrics_gone_count >= InsightsConfig::goneAfter();

        $state = match (true) {
            $snapshot !== null => 'measured',
            $metrics['platform'] === [] => 'unavailable',
            $stopped => 'never',
            ! InsightsConfig::enabled() => 'off',
            ! $read => 'blocked',
            $gone => 'gone',
            default => 'pending',
        };

        $numbers = null;

        if ($snapshot !== null) {
            $numbers = [];

            foreach (Metric::cases() as $metric) {
                $value = $snapshot->reading()->get($metric);

                $numbers[$metric->value] = [
                    'value' => $value,
                    'missing' => match (true) {
                        $value !== null => null,
                        ! in_array($metric, $metrics['platform'], true) => 'platform',
                        ! in_array($metric, $metrics['channel'], true) => 'permission',
                        default => 'not_given',
                    },
                ];
            }
        }

        return [
            'variant_id' => $variant->id,
            'channel_id' => $variant->channel_id,
            'channel_name' => $channel->display_name ?? '',
            'platform_label' => $channel !== null ? PlatformLabel::for($channel->platform) : '',
            'channel_status' => $status->value,
            'state' => $state,
            'numbers' => $numbers,
            'score' => $snapshot?->score(),
            'checked_at' => $snapshot?->checked_at->toIso8601String(),
            // Set while a reading is still to come: when the next one is due.
            'next_reading_at' => $state === 'pending'
                ? $this->nextReading($variant, $publishedAt, $now)->toIso8601String()
                : null,
            // The platform was asked at least once and gave nothing.
            'asked' => $variant->metrics_checked_at !== null,
            // Older than the last step of the cadence: not read any more.
            'stopped' => $stopped,
            // The numbers are not being updated, although the post is young enough.
            'stale' => $snapshot !== null && ! $stopped && ! $read,
            // Young enough to be read, and the platform says the post is gone.
            'gone' => ! $stopped && $read && $gone,
            // Numbers the platform has and the token of this channel may not read.
            'needs_permission' => in_array($state, ['measured', 'pending'], true)
                ? $this->names(array_udiff($metrics['platform'], $metrics['channel'], fn (Metric $a, Metric $b): int => $a->value <=> $b->value))
                : [],
            'history' => $history === null ? null : array_map(fn (MetricSnapshot $row): array => [
                'captured_at' => $row->captured_at->toIso8601String(),
                'checked_at' => $row->checked_at->toIso8601String(),
                ...$row->reading()->toArray(),
            ], $history),
        ];
    }

    /**
     * When the next reading of a variant is due: by the next run when it
     * was never asked about, otherwise one step of the cadence after the
     * last time.
     */
    private function nextReading(PostVariant $variant, CarbonImmutable $publishedAt, CarbonImmutable $now): CarbonImmutable
    {
        if ($variant->metrics_checked_at === null) {
            return $publishedAt->addMinutes(self::POLL_MINUTES);
        }

        $cadence = InsightsConfig::cadence();
        $every = $cadence[count($cadence) - 1]['every_minutes'];

        foreach ($cadence as $step) {
            if ($publishedAt->greaterThan($now->subHours($step['until_hours']))) {
                $every = $step['every_minutes'];

                break;
            }
        }

        return $variant->metrics_checked_at->addMinutes($every);
    }

    /**
     * The numbers of the measured variants of one post added up, or null
     * when none is measured.
     *
     * @param  list<MetricSnapshot>  $measured
     * @return array<string, mixed>|null
     */
    private function total(array $measured, int $published): ?array
    {
        if ($measured === []) {
            return null;
        }

        $sums = [];

        foreach (Metric::cases() as $metric) {
            $values = array_filter(
                array_map(fn (MetricSnapshot $snapshot): ?int => $snapshot->reading()->get($metric), $measured),
                fn (?int $value): bool => $value !== null,
            );

            $sums[$metric->value] = $values === [] ? null : array_sum($values);
        }

        $totals = new MetricTotals(...$sums);

        return [
            'numbers' => $totals->toArray(),
            'score' => $totals->score(),
            'measured' => count($measured),
            'published' => $published,
            // The oldest of the readings: the total is at least this fresh.
            'checked_at' => collect($measured)->min(fn (MetricSnapshot $snapshot) => $snapshot->checked_at)?->toIso8601String(),
        ];
    }

    /**
     * The whole period, from the summaries of its pillars. A post has one
     * pillar or none, so adding the pillars up counts every post once.
     *
     * @param  list<PerformanceSummary>  $pillars
     * @return array{posts: int, measured_posts: int, numbers: array<string, int|null>, score: float|null}
     */
    private function totals(array $pillars): array
    {
        $sums = [];

        foreach (Metric::cases() as $metric) {
            $values = array_filter(
                array_map(fn (PerformanceSummary $pillar): ?int => $pillar->totals->get($metric), $pillars),
                fn (?int $value): bool => $value !== null,
            );

            $sums[$metric->value] = $values === [] ? null : array_sum($values);
        }

        $totals = new MetricTotals(...$sums);

        return [
            'posts' => array_sum(array_map(fn (PerformanceSummary $pillar): int => $pillar->posts, $pillars)),
            'measured_posts' => array_sum(array_map(fn (PerformanceSummary $pillar): int => $pillar->measuredPosts, $pillars)),
            'numbers' => $totals->toArray(),
            'score' => $totals->score(),
        ];
    }

    /**
     * Best average score first, pillars without a score after those, by
     * name among equals. Posts without a pillar are the last row: they are
     * not a theme to compare with the others.
     *
     * @param  list<PerformanceSummary>  $pillars
     * @return list<array<string, mixed>>
     */
    private function pillars(array $pillars): array
    {
        usort($pillars, fn (PerformanceSummary $a, PerformanceSummary $b): int => [$a->id === null, -($a->averageScore() ?? -1.0), (string) $a->name]
            <=> [$b->id === null, -($b->averageScore() ?? -1.0), (string) $b->name]);

        return array_map(fn (PerformanceSummary $pillar): array => [
            'id' => $pillar->id,
            'name' => $pillar->name,
            ...$this->summary($pillar),
        ], $pillars);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(PerformanceSummary $summary): array
    {
        $numbers = [];

        foreach (Metric::cases() as $metric) {
            $numbers[$metric->value] = [
                'total' => $summary->totals->get($metric),
                'average' => $summary->average($metric),
                // How many posts the total and the average are made of.
                'posts' => $summary->postsWith($metric),
            ];
        }

        return [
            'posts' => $summary->posts,
            'measured_posts' => $summary->measuredPosts,
            'scored_posts' => $summary->scoredPosts,
            'numbers' => $numbers,
            'score' => $summary->score,
            'average_score' => $summary->averageScore(),
        ];
    }

    /**
     * @param  list<PostPerformance>  $top
     * @return list<array<string, mixed>>
     */
    private function top(array $top): array
    {
        if ($top === []) {
            return [];
        }

        $posts = Post::query()
            ->with(['variants.channel', 'pillar'])
            ->whereKey(array_map(fn (PostPerformance $performance): int => $performance->postId, $top))
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($top as $performance) {
            $post = $posts->get($performance->postId);

            if ($post === null) {
                continue;
            }

            $variants = $post->variants->filter(fn (PostVariant $variant): bool => $variant->isPublished())->sortBy('id')->values();

            $rows[] = [
                'post_id' => $post->id,
                'excerpt' => self::firstLine((string) $variants->first()?->body),
                'pillar' => $post->pillar?->name,
                'channels' => $variants->map(fn (PostVariant $variant): array => [
                    'name' => $variant->channel->display_name ?? '',
                    'platform_label' => $variant->channel !== null ? PlatformLabel::for($variant->channel->platform) : '',
                ])->all(),
                'published_at' => $variants->max(fn (PostVariant $variant) => $variant->published_at)?->toIso8601String(),
                'numbers' => $performance->totals->toArray(),
                'score' => $performance->score,
                'measured' => $performance->measuredVariants,
                'published' => $variants->count(),
            ];
        }

        return $rows;
    }

    /**
     * Every channel that is connected, and every other channel with a post
     * in the period, with what it added up to and whether it is read.
     *
     * @param  list<PerformanceSummary>  $summaries
     * @return list<array<string, mixed>>
     */
    private function channelRows(array $summaries): array
    {
        $byChannel = [];

        foreach ($summaries as $summary) {
            $byChannel[(int) $summary->id] = $summary;
        }

        $empty = new PerformanceSummary(null, null, 0, 0, 0, new MetricTotals, [], null);
        $rows = [];

        $channels = Channel::query()
            ->where('status', '!=', ChannelStatus::Pending)
            ->orderBy('platform')
            ->orderBy('display_name')
            ->orderBy('id')
            ->get();

        foreach ($channels as $channel) {
            $summary = $byChannel[$channel->id] ?? null;

            if ($summary === null && $channel->status === ChannelStatus::Disconnected) {
                continue;
            }

            $metrics = $this->metricsOf($channel);

            $rows[] = [
                'id' => $channel->id,
                'name' => $channel->display_name,
                'platform_label' => PlatformLabel::for($channel->platform),
                'status' => $channel->status->value,
                // False for a platform that gives Noon Gun no numbers.
                'gives_numbers' => $metrics['platform'] !== [],
                'metrics' => $this->names($metrics['channel']),
                'needs_permission' => $this->names(array_udiff($metrics['platform'], $metrics['channel'], fn (Metric $a, Metric $b): int => $a->value <=> $b->value)),
                // The platform asked Noon Gun to wait. Reading starts again by itself.
                'paused' => $metrics['platform'] !== [] && ChannelPolling::isPaused($channel->id),
                ...$this->summary($summary ?? $empty),
            ];
        }

        return $rows;
    }

    /**
     * What the platform of a channel can give, and what the token of this
     * channel may read of it. Both empty for a platform without numbers.
     *
     * @return array{platform: list<Metric>, channel: list<Metric>}
     */
    private function metricsOf(Channel $channel): array
    {
        if (isset($this->channelMetrics[$channel->id])) {
            return $this->channelMetrics[$channel->id];
        }

        $reader = $this->channels->metricsReader($channel);

        if ($reader === null) {
            return $this->channelMetrics[$channel->id] = ['platform' => [], 'channel' => []];
        }

        $platform = $reader->capabilities()->metrics;

        return $this->channelMetrics[$channel->id] = [
            'platform' => $platform,
            'channel' => array_values(array_filter($reader->metricsFor($channel), fn (Metric $metric): bool => in_array($metric, $platform, true))),
        ];
    }

    /**
     * The weights of the engagement score, in the order of the formula.
     *
     * @return array<string, float>
     */
    private function weights(): array
    {
        $weights = [];

        foreach ([Metric::Reactions, Metric::Comments, Metric::Shares, Metric::Clicks] as $index => $metric) {
            $weights[$metric->value] = EngagementScore::bindings()[$index];
        }

        return $weights;
    }

    /**
     * @param  array<array-key, Metric>  $metrics
     * @return list<string>
     */
    private function names(array $metrics): array
    {
        return array_values(array_map(fn (Metric $metric): string => $metric->value, $metrics));
    }

    private static function firstLine(string $body): string
    {
        return Str::limit(trim((string) Str::of(trim($body))->before("\n")), self::EXCERPT_LENGTH);
    }

    private static function every(int $minutes): string
    {
        return match (true) {
            $minutes === 60 => 'every hour',
            $minutes === 1440 => 'once a day',
            $minutes % 1440 === 0 => 'every '.intdiv($minutes, 1440).' days',
            $minutes % 60 === 0 => 'every '.intdiv($minutes, 60).' hours',
            $minutes === 1 => 'every minute',
            default => "every {$minutes} minutes",
        };
    }

    private static function age(int $hours): string
    {
        return match (true) {
            $hours === 24 => '1 day',
            $hours % 24 === 0 => intdiv($hours, 24).' days',
            $hours === 1 => '1 hour',
            default => "{$hours} hours",
        };
    }
}
