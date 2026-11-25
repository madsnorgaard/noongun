<?php

namespace App\Domain\Drafting\Planning;

use App\Domain\Channels\ChannelManager;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Idea;
use App\Domain\Content\Models\Pillar;
use App\Domain\Content\PostState;
use App\Domain\Drafting\DraftingLimits;
use App\Domain\Scheduling\Models\Slot;
use App\Domain\Scheduling\PlannedTimes;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Works out which posts a proposal for one week would draft. It reads and
 * creates nothing. The rule, in the order it is applied:
 *
 * 1. Times. Every slot of a channel that can publish gives one time in the
 *    week (Slot::occurrenceInWeekOf(), on the workspace calendar). A time
 *    that has passed is left out. So is a time at which that channel
 *    already has a post that is not rejected.
 * 2. Posts. Channels whose open slots fall on the same instant share one
 *    post, with a text for each of them: the post is what a person
 *    approves (ADR 0009). The posts are put in time order, and only the
 *    earliest ones are kept when there are more than one proposal may
 *    draft.
 * 3. Pillars. Each pillar still needs its weekly target minus the posts
 *    the week already holds for it. The pillars take turns, largest target
 *    first, then by name, one post per turn, until every need is met. That
 *    sequence is laid over the posts in time order. With more needs than
 *    posts the end of the sequence is dropped; with fewer, the remaining
 *    posts are drafted from the brief alone, without a pillar.
 * 4. Ideas. A post takes the oldest idea of its pillar that no post that
 *    is not rejected has used. A post without a pillar takes one from the
 *    ideas without a pillar. When there is none it is drafted without an
 *    idea.
 *
 * The same records give the same plan, which is what lets the page show it
 * before anything is spent.
 */
final class PlanWeek
{
    public function __construct(
        private readonly PlannedTimes $plannedTimes,
        private readonly ChannelManager $channels,
    ) {}

    /**
     * The Monday, on the workspace calendar, of the week that holds the
     * given date. A date that is missing, malformed, before the current
     * week or further ahead than a week can be proposed gives the current
     * week.
     */
    public static function weekOf(Workspace $workspace, ?string $date, ?DateTimeInterface $now = null): string
    {
        $thisWeek = $workspace->toLocal(CarbonImmutable::instance($now ?? now()))->startOfWeek(CarbonInterface::MONDAY);

        if ($date === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return $thisWeek->format('Y-m-d');
        }

        $monday = CarbonImmutable::parse($date, $workspace->timezone)->startOfWeek(CarbonInterface::MONDAY);

        if ($monday->lt($thisWeek) || $monday->gt($thisWeek->addWeeks(DraftingLimits::weeksAhead()))) {
            return $thisWeek->format('Y-m-d');
        }

        return $monday->format('Y-m-d');
    }

    /**
     * @param  string  $week  a Monday on the workspace calendar, from weekOf()
     */
    public function handle(Workspace $workspace, string $week, ?DateTimeInterface $now = null): WeekPlan
    {
        $now = CarbonImmutable::instance($now ?? now());
        $monday = CarbonImmutable::parse($week, $workspace->timezone)->startOfDay();
        $from = $monday->utc();
        $until = $monday->addWeek()->utc();
        // Noon on the Monday is inside the week whatever the clocks do.
        $inWeek = $monday->addHours(12);

        $taken = $this->plannedTimes->takenBetween($from, $until);

        /** @var array<int, array{channels: array<int, Channel>, slots: list<int>}> $open */
        $open = [];
        $past = 0;
        $occupied = 0;
        $unavailable = 0;

        foreach (Slot::query()->with('channel')->orderBy('id')->get() as $slot) {
            $channel = $slot->channel;

            if ($channel === null || ! $channel->canPublish() || ! $this->channels->has($channel->platform)) {
                $unavailable++;

                continue;
            }

            $at = $slot->setRelation('workspace', $workspace)->occurrenceInWeekOf($inWeek)->getTimestamp();

            // Two slots of one channel can land on the same instant when
            // the clocks skip an hour. That is one time, not two.
            if (isset($open[$at]['channels'][$channel->id])) {
                continue;
            }

            if ($at <= $now->getTimestamp()) {
                $past++;

                continue;
            }

            if (in_array($at, $taken[$channel->id] ?? [], true)) {
                $occupied++;

                continue;
            }

            $open[$at]['channels'][$channel->id] = $channel;
            $open[$at]['slots'][] = $slot->id;
        }

        ksort($open);

        $posts = [];

        foreach ($open as $at => $group) {
            ksort($group['channels']);

            $channels = array_values($group['channels']);
            $slots = $group['slots'];

            if ($channels !== [] && $slots !== []) {
                $posts[] = new PlannedPost(CarbonImmutable::createFromTimestamp($at, 'UTC'), $channels, $slots);
            }
        }

        $leftOut = max(0, count($posts) - DraftingLimits::postsPerProposal());
        $posts = array_slice($posts, 0, DraftingLimits::postsPerProposal());

        [$sequence, $short] = $this->pillarSequence($from, $until, count($posts));
        $ideas = $this->unusedIdeas();

        foreach ($posts as $position => $post) {
            $pillar = $sequence[$position] ?? null;
            $shelf = $pillar !== null ? $pillar->id : 0;
            $idea = null;

            if (($ideas[$shelf] ?? []) !== []) {
                $idea = array_shift($ideas[$shelf]);
            }

            $posts[$position] = $post->about($pillar, $idea);
        }

        return new WeekPlan($week, $from, $until, $posts, $past, $occupied, $unavailable, $leftOut, $short);
    }

    /**
     * The pillars in the order the posts get them, and the pillars that
     * did not get all they need.
     *
     * @return array{list<Pillar>, list<array{pillar: Pillar, missing: int}>}
     */
    private function pillarSequence(CarbonImmutable $from, CarbonImmutable $until, int $places): array
    {
        $pillars = Pillar::query()
            ->where('weekly_target', '>', 0)
            ->withCount(['posts as held_in_week' => fn (Builder $posts) => $posts
                ->whereIn('state', self::holdingStates())
                ->where('scheduled_at', '>=', $from)
                ->where('scheduled_at', '<', $until)])
            ->orderByDesc('weekly_target')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $needs = [];

        foreach ($pillars as $pillar) {
            $held = $pillar->getAttribute('held_in_week');

            $needs[$pillar->id] = max(0, $pillar->weekly_target - (is_numeric($held) ? (int) $held : 0));
        }

        $sequence = [];

        while (array_sum($needs) > 0) {
            foreach ($pillars as $pillar) {
                if ($needs[$pillar->id] > 0) {
                    $sequence[] = $pillar;
                    $needs[$pillar->id]--;
                }
            }
        }

        $missing = [];

        foreach (array_slice($sequence, $places) as $pillar) {
            $missing[$pillar->id] = ($missing[$pillar->id] ?? 0) + 1;
        }

        $short = [];

        foreach ($pillars as $pillar) {
            if (isset($missing[$pillar->id])) {
                $short[] = ['pillar' => $pillar, 'missing' => $missing[$pillar->id]];
            }
        }

        return [array_slice($sequence, 0, $places), $short];
    }

    /**
     * Ideas no post has used, oldest first, keyed by pillar id. Ideas
     * without a pillar are under 0.
     *
     * @return array<int, list<Idea>>
     */
    private function unusedIdeas(): array
    {
        $ideas = Idea::query()
            ->whereDoesntHave('posts', fn (Builder $posts) => $posts->whereIn('state', self::holdingStates()))
            ->orderBy('id')
            ->get();

        $byPillar = [];

        foreach ($ideas as $idea) {
            $byPillar[$idea->pillar_id ?? 0][] = $idea;
        }

        return $byPillar;
    }

    /**
     * Every state but rejected: a post that was turned down holds no time
     * and uses up no idea.
     *
     * @return list<PostState>
     */
    private static function holdingStates(): array
    {
        return PlannedTimes::holdingStates();
    }
}
