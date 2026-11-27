<?php

namespace App\Http\Presenters;

use App\Domain\Channels\ChannelManager;
use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Contracts\Metric;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Insights\ChannelPolling;
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
 * What it keeps to, so that no number says more than it knows:
 *
 * - A number that the platform did not give goes out as null with the
 *   reason next to it, never as zero.
 * - Reach is people, and the same person sees several posts and follows
 *   several Pages. It is never added up over posts or channels; it goes out
 *   for one post on one channel, or as an average.
 * - "Score" is the comparable score (Performance::comparableScore()):
 *   reactions, comments and shares, which every measured channel has.
 *   Clicks are shown and not counted.
 * - Comparisons leave out posts that are too young to have collected their
 *   reactions, and make no claim about a best post or pillar from fewer
 *   posts than the configured minimum.
 *
 * Times go out as UTC instants and the pages show them on the clock of the
 * workspace. The sentences are written by the pages
 * (resources/js/lib/insights.ts); this class says which one applies.
 */
final class InsightsPresenter
{
    /** The periods the Insights page offers, in days. */
    public const PERIODS = [7, 28, 90];

    public const DEFAULT_PERIOD = 28;

    /** The period of the block on the dashboard, in days. */
    public const DASHBOARD_PERIOD = 7;

    /**
     * Minutes between two runs of noongun:poll-metrics. A post that was
     * never read is read by the next run, so its first reading is expected
     * this long after it went out. The schedule is in routes/console.php;
     * a test fails when the two differ.
     */
    public const POLL_MINUTES = 10;

    private const TOP_POSTS = 10;

    private const EXCERPT_LENGTH = 90;

    /**
     * What is known of each channel while one answer is put together. Both
     * are emptied at the start of every answer: a controller, and with it
     * this presenter, can outlive a request.
     *
     * @var array<int, array{platform: list<Metric>, channel: list<Metric>}>
     */
    private array $channelMetrics = [];

    /** @var array<int, array{kind: string, until: string|null}|null> */
    private array $pauses = [];

    public function __construct(
        private readonly ChannelManager $channels,
        private readonly Performance $performance,
        private readonly CurrentWorkspace $currentWorkspace,
    ) {}

    /**
     * Whether numbers are read on this instance, how often, and what a
     * comparison asks of a post, in words.
     *
     * @param  bool  $canSetUp  whether the viewer can connect channels and is told how to change the set-up
     * @return array<string, mixed>
     */
    public function reading(bool $canSetUp): array
    {
        $steps = [];

        foreach (InsightsConfig::cadence() as $step) {
            $steps[] = self::every($step['every_minutes']).' until it is '.self::age($step['until_hours']).' old';
        }

        return [
            'enabled' => InsightsConfig::enabled(),
            'cadence' => 'A post is read '.implode(', then ', $steps).', and not after that.',
            'stop_after' => self::age(InsightsConfig::stopAfterHours()),
            // How far back a post that was never read is still read once.
            'once_within' => self::age(self::readOnceWithinDays() * 24),
            // How old a post has to be before it is compared with others.
            'min_age' => self::age(self::minAgeHours()),
            'min_measured' => self::minMeasuredPosts(),
            'weights' => Performance::comparableWeights(),
            'can_set_up' => $canSetUp,
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
        $this->forget();

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
     * workspace, today included. With a channel, the pillars and the top
     * posts are those of that channel alone.
     *
     * @return array<string, mixed>
     */
    public function page(int $days, ?int $channelId, bool $canSetUp): array
    {
        $this->forget();

        [$from, $until] = $this->period($days);
        $settledBefore = $this->settledBefore();

        $channels = $this->channelRows($this->performance->channelSummaries($from, $until));
        $channelId = in_array($channelId, array_column($channels, 'id'), true) ? $channelId : null;

        $totals = $this->totals($this->performance->pillarSummaries($from, $until));
        $pillars = $totals['measured'] > 0 ? $this->performance->pillarSummaries($from, $until, $channelId, $settledBefore) : [];
        $top = $totals['measured'] > 0
            ? $this->performance->topPosts($from, $until, max(self::TOP_POSTS, self::minMeasuredPosts()), $channelId, $settledBefore)
            : [];

        return [
            'period' => [
                'days' => $days,
                'options' => self::PERIODS,
                'from' => $from->format('Y-m-d'),
                'until' => $until->subDay()->format('Y-m-d'),
            ],
            // The channel the pillars and the top posts are narrowed to.
            'channel' => $channelId,
            'reading' => $this->reading($canSetUp),
            'status' => match (true) {
                $totals['posts'] === 0 => PostVariant::query()->whereNotNull('external_id')->exists() ? 'nothing_in_period' : 'nothing_published',
                $totals['measured'] === 0 => 'nothing_measured',
                default => 'ok',
            },
            'totals' => $totals,
            'pillars' => $this->pillars($pillars),
            // False while too few posts compete to say which did best.
            'ranked' => count($top) >= self::minMeasuredPosts(),
            'top' => array_slice($this->top($top, $channelId), 0, self::TOP_POSTS),
            'channels' => $channels,
        ];
    }

    /**
     * The block on the dashboard: what the last week added up to, and the
     * post that did best when enough posts compete to say so. Null when no
     * post of the week is measured.
     *
     * @return array<string, mixed>|null
     */
    public function lastWeek(): ?array
    {
        [$from, $until] = $this->period(self::DASHBOARD_PERIOD);

        $totals = $this->totals($this->performance->pillarSummaries($from, $until));

        if ($totals['measured'] === 0) {
            return null;
        }

        $top = $this->performance->topPosts($from, $until, self::minMeasuredPosts(), null, $this->settledBefore());

        return [
            'days' => self::DASHBOARD_PERIOD,
            ...$totals,
            'best' => count($top) >= self::minMeasuredPosts() ? ($this->top([$top[0]], null)[0] ?? null) : null,
        ];
    }

    public static function periodFrom(mixed $value): int
    {
        return is_string($value) && ctype_digit($value) && in_array((int) $value, self::PERIODS, true)
            ? (int) $value
            : self::DEFAULT_PERIOD;
    }

    public static function channelFrom(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) && strlen($value) <= 18 ? (int) $value : null;
    }

    private function forget(): void
    {
        $this->channelMetrics = [];
        $this->pauses = [];
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
     * A post that went out after this instant is too young to be compared.
     */
    private function settledBefore(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->subHours(self::minAgeHours());
    }

    /**
     * How old a post has to be before it competes, in hours. Read here
     * and nowhere else on the pages.
     */
    private static function minAgeHours(): int
    {
        $hours = config('noongun.insights.min_age_hours');

        return is_numeric($hours) && (int) $hours >= 0 ? min((int) $hours, 24 * 30) : 48;
    }

    /**
     * How many posts have to compete before one is called the best.
     */
    private static function minMeasuredPosts(): int
    {
        $posts = config('noongun.insights.min_measured_posts');

        return is_numeric($posts) && (int) $posts >= 1 ? min((int) $posts, 100) : 5;
    }

    /**
     * How many days back a post that was never read is still read once.
     * The key is the one the one-time read of old posts is expected to
     * use; this is the only place the pages read it.
     */
    private static function readOnceWithinDays(): int
    {
        $days = config('noongun.insights.backfill_days');

        return is_numeric($days) && (int) $days >= 0 ? (int) $days : 365;
    }

    /**
     * Why reading is paused for a channel, or null when it is not. The one
     * place the pages ask: kind is "rate_limit" (the platform asked for a
     * wait), "token" (it did not accept the token for reading, until the
     * channel is connected again), "other" (a reading did not get through)
     * or "unknown" when only the fact of a pause is known. "until" is when
     * reading starts again, when that is known.
     *
     * ChannelPolling only says that a channel is paused today, so every
     * pause is "unknown" and the pages say what is true of all of them.
     *
     * @return array{kind: string, until: string|null}|null
     */
    private function pauseOf(Channel $channel): ?array
    {
        if (array_key_exists($channel->id, $this->pauses)) {
            return $this->pauses[$channel->id];
        }

        return $this->pauses[$channel->id] = ChannelPolling::isPaused($channel->id)
            ? ['kind' => 'unknown', 'until' => null]
            : null;
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
        $asked = $variant->metrics_checked_at !== null;
        // The platform said often enough that the post is not there any
        // more, and it is not asked about again (see DueVariants).
        $gone = $variant->metrics_gone_count >= InsightsConfig::goneAfter();

        $state = match (true) {
            $snapshot !== null => 'measured',
            $metrics['platform'] === [] => 'unavailable',
            // Read while it was young, or once later, and nothing came.
            $stopped && $asked => 'no_numbers',
            $stopped && ($publishedAt === null || $publishedAt->lessThan($now->subDays(self::readOnceWithinDays()))) => 'too_old',
            ! InsightsConfig::enabled() => 'off',
            ! $read => 'blocked',
            $gone => 'gone',
            // Older than the cadence reaches and never asked about: it
            // waits for the one reading that old posts get.
            $stopped => 'waiting_once',
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

        $waiting = in_array($state, ['pending', 'waiting_once'], true) || ($snapshot !== null && ! $stopped && $read && ! $gone);

        return [
            'variant_id' => $variant->id,
            'channel_id' => $variant->channel_id,
            'channel_name' => $channel->display_name ?? '',
            'platform_label' => $channel !== null ? PlatformLabel::for($channel->platform) : '',
            'channel_status' => $status->value,
            'state' => $state,
            'numbers' => $numbers,
            'score' => $snapshot !== null ? Performance::comparableScore($snapshot->reading()) : null,
            'checked_at' => $snapshot?->checked_at->toIso8601String(),
            // Set while a reading is still to come: when the next one is due.
            'next_reading_at' => $state === 'pending'
                ? $this->nextReading($variant, $publishedAt, $now)->toIso8601String()
                : null,
            // The platform was asked at least once and gave nothing.
            'asked' => $asked,
            // Older than the last step of the cadence: not read any more.
            'stopped' => $stopped,
            // The numbers are not being updated, although the post is young enough.
            'stale' => $snapshot !== null && ! $stopped && ! $read,
            // Young enough to be read, and the platform says the post is gone.
            'gone' => ! $stopped && $read && $gone,
            // Set when a reading is expected and reading is paused for the channel.
            'pause' => $waiting && $channel !== null && InsightsConfig::enabled() ? $this->pauseOf($channel) : null,
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
     * when none is measured. Reach is only given when one channel is
     * measured: across channels the same person would be counted twice.
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

            $sums[$metric->value] = $values === [] || ($metric === Metric::Reach && count($measured) > 1) ? null : array_sum($values);
        }

        $totals = new MetricTotals(...$sums);

        return [
            'numbers' => $totals->toArray(),
            'score' => Performance::comparableScore($totals),
            'measured' => count($measured),
            'published' => $published,
            // The oldest of the readings: the total is at least this fresh.
            'checked_at' => collect($measured)->min(fn (MetricSnapshot $snapshot) => $snapshot->checked_at)?->toIso8601String(),
        ];
    }

    /**
     * The whole period, from the summaries of its pillars. A post has one
     * pillar or none, so adding the pillars up counts every post once.
     * Reach is not in it: it cannot be added up over posts.
     *
     * @param  list<PerformanceSummary>  $pillars
     * @return array{posts: int, channel_posts: int, measured: int, numbers: array<string, int|null>, with: array<string, int>, score: float|null}
     */
    private function totals(array $pillars): array
    {
        $sums = [];
        $with = [];

        foreach (Metric::cases() as $metric) {
            $values = array_filter(
                array_map(fn (PerformanceSummary $pillar): ?int => $pillar->totals->get($metric), $pillars),
                fn (?int $value): bool => $value !== null,
            );

            $sums[$metric->value] = $values === [] || $metric === Metric::Reach ? null : array_sum($values);
            // How many measured channel posts the total is made of.
            $with[$metric->value] = array_sum(array_map(fn (PerformanceSummary $pillar): int => $pillar->with($metric), $pillars));
        }

        $totals = new MetricTotals(...$sums);

        return [
            'posts' => array_sum(array_map(fn (PerformanceSummary $pillar): int => $pillar->posts, $pillars)),
            'channel_posts' => array_sum(array_map(fn (PerformanceSummary $pillar): int => $pillar->channelPosts, $pillars)),
            'measured' => array_sum(array_map(fn (PerformanceSummary $pillar): int => $pillar->measured, $pillars)),
            'numbers' => $totals->toArray(),
            'with' => $with,
            'score' => Performance::comparableScore($totals),
        ];
    }

    /**
     * Pillars with enough measured posts to compare first, best average
     * score first among them; then the pillars with too few, the same way;
     * by name among equals. Posts without a pillar are the last row: they
     * are not a theme to compare with the others.
     *
     * @param  list<PerformanceSummary>  $pillars
     * @return list<array<string, mixed>>
     */
    private function pillars(array $pillars): array
    {
        $enough = fn (PerformanceSummary $pillar): bool => $pillar->scored >= self::minMeasuredPosts();
        $key = fn (PerformanceSummary $pillar): array => [$pillar->id === null, ! $enough($pillar), -($pillar->averageScore() ?? -1.0), (string) $pillar->name];

        usort($pillars, fn (PerformanceSummary $a, PerformanceSummary $b): int => $key($a) <=> $key($b));

        return array_map(fn (PerformanceSummary $pillar): array => [
            'id' => $pillar->id,
            'name' => $pillar->name,
            // False while too few of its posts are measured to compare it.
            'enough' => $enough($pillar),
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
                // Reach has no total: it cannot be added up over posts.
                'total' => $metric === Metric::Reach ? null : $summary->totals->get($metric),
                'average' => $summary->average($metric),
                // How many channel posts the total and the average are made of.
                'with' => $summary->with($metric),
            ];
        }

        return [
            'posts' => $summary->posts,
            'channel_posts' => $summary->channelPosts,
            'measured' => $summary->measured,
            'scored' => $summary->scored,
            'numbers' => $numbers,
            'score' => $summary->score,
            'average_score' => $summary->averageScore(),
        ];
    }

    /**
     * @param  list<PostPerformance>  $top
     * @return list<array<string, mixed>>
     */
    private function top(array $top, ?int $channelId): array
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

            $variants = $post->variants
                ->filter(fn (PostVariant $variant): bool => $variant->isPublished() && ($channelId === null || $variant->channel_id === $channelId))
                ->sortBy('id')
                ->values();

            $rows[] = [
                'post_id' => $post->id,
                'excerpt' => self::firstLine((string) $variants->first()?->body),
                'pillar' => $post->pillar?->name,
                'channels' => $variants->map(fn (PostVariant $variant): array => [
                    'id' => $variant->channel_id,
                    'name' => $variant->channel->display_name ?? '',
                    'platform_label' => $variant->channel !== null ? PlatformLabel::for($variant->channel->platform) : '',
                ])->all(),
                'published_at' => $variants->max(fn (PostVariant $variant) => $variant->published_at)?->toIso8601String(),
                'numbers' => [
                    ...$performance->totals->toArray(),
                    // Of one channel only: across channels reach cannot be added up.
                    'reach' => $performance->measuredVariants === 1 ? $performance->totals->reach : null,
                ],
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

        $empty = new PerformanceSummary(null, null, 0, 0, 0, 0, new MetricTotals, [], null);
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
            $read = $metrics['platform'] !== [] && $channel->status === ChannelStatus::Active && InsightsConfig::enabled();

            $rows[] = [
                'id' => $channel->id,
                'name' => $channel->display_name,
                'platform_label' => PlatformLabel::for($channel->platform),
                'status' => $channel->status->value,
                // False for a platform that gives Noon Gun no numbers.
                'gives_numbers' => $metrics['platform'] !== [],
                'metrics' => $this->names($metrics['channel']),
                'needs_permission' => $this->names(array_udiff($metrics['platform'], $metrics['channel'], fn (Metric $a, Metric $b): int => $a->value <=> $b->value)),
                // Why reading is paused, for a channel that would be read.
                'pause' => $read ? $this->pauseOf($channel) : null,
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
