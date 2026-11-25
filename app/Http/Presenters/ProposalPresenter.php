<?php

namespace App\Http\Presenters;

use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Pillar;
use App\Domain\Drafting\DraftGenerators;
use App\Domain\Drafting\DraftingConfig;
use App\Domain\Drafting\DraftingLimits;
use App\Domain\Drafting\DraftRunStatus;
use App\Domain\Drafting\Exceptions\DraftGeneratorNotAvailable;
use App\Domain\Drafting\Exceptions\LlmCredentialMissing;
use App\Domain\Drafting\Exceptions\ProposalRefused;
use App\Domain\Drafting\Models\Brief;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Drafting\Planning\PlannedPost;
use App\Domain\Drafting\Planning\ProposalCost;
use App\Domain\Drafting\Planning\WeekPlan;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Route;

/**
 * What the pages show of a week proposal: the plan before it starts, and
 * the draft runs while and after they are worked on. Everything a provider
 * wrote (rationale, failure reasons) goes out as plain text.
 */
final class ProposalPresenter
{
    /** How long a finished proposal stays on the queue when a draft failed. */
    private const FAILED_SHOWN_HOURS = 24;

    /** How long a finished proposal stays on the queue when every post was drafted. */
    private const DONE_SHOWN_MINUTES = 60;

    /**
     * Where each cause of a refusal is fixed: the route names to try, in
     * order, and the words of the link. A route that does not exist on
     * this instance gives no link.
     *
     * @var array<string, array{routes: list<string>, label: string}>
     */
    private const FIXES = [
        ProposalRefused::BRIEF_MISSING => ['routes' => ['brief.edit'], 'label' => 'Write the brief'],
        ProposalRefused::NO_CHANNEL => ['routes' => ['channels.index'], 'label' => 'Go to Channels'],
        ProposalRefused::NO_SLOT => ['routes' => ['slots.index'], 'label' => 'Set the slots'],
        ProposalRefused::NO_KEY => ['routes' => ['llm-key.edit'], 'label' => 'Add an API key'],
        ProposalRefused::CHOICE_NOT_OFFERED => ['routes' => ['llm-key.edit'], 'label' => 'Choose a provider'],
        ProposalRefused::NOTHING_TO_DRAFT => ['routes' => ['slots.index'], 'label' => 'Look at the slots'],
        ProposalRefused::IN_PROGRESS => ['routes' => ['queue.index'], 'label' => 'See the progress in the queue'],
    ];

    public function __construct(
        private readonly DraftGenerators $generators,
        private readonly ProposalCost $proposalCost,
    ) {}

    /**
     * @param  list<ProposalRefused>  $refusals
     * @return list<array{reason: string, message: string, href: string|null, label: string|null}>
     */
    public function refusals(array $refusals): array
    {
        return array_map(function (ProposalRefused $refusal): array {
            $fix = self::FIXES[$refusal->reason] ?? ['routes' => [], 'label' => ''];
            $href = self::firstRoute($fix['routes']);

            return [
                'reason' => $refusal->reason,
                'message' => $refusal->getMessage(),
                'href' => $href,
                'label' => $href !== null ? (string) __($fix['label']) : null,
            ];
        }, $refusals);
    }

    /**
     * @return array<string, mixed>
     */
    public function plan(WeekPlan $plan): array
    {
        return [
            'fingerprint' => $plan->fingerprint(),
            'posts' => array_map(fn (PlannedPost $post): array => [
                'at' => $post->at->toIso8601String(),
                'channels' => array_map(fn (Channel $channel): array => [
                    'name' => $channel->display_name,
                    'platform_label' => PlatformLabel::for($channel->platform),
                ], $post->channels),
                'pillar' => $post->pillar?->name,
                'idea' => $post->idea?->title,
            ], $plan->posts),
            'past' => $plan->past,
            'soon' => $plan->soon,
            'lead_minutes' => intdiv(DraftingLimits::leadSeconds(), 60),
            'taken' => $plan->taken,
            'unavailable' => $plan->unavailable,
            'left_out' => $plan->leftOut,
            // One post for several channels, and posts that reach one
            // channel although the plan drafts for more than one.
            'shared' => $plan->sharedPosts(),
            'for_one_channel' => $plan->postsForOneChannel(),
            'pillars_with_target' => Pillar::query()->where('weekly_target', '>', 0)->count(),
            'short' => array_map(fn (array $short): array => [
                'pillar' => $short['pillar']->name,
                'missing' => $short['missing'],
            ], $plan->short),
        ];
    }

    /**
     * The week on the workspace calendar, and the weeks next to it that
     * can be proposed.
     *
     * @return array{start: string, end: string, previous: string|null, next: string|null}
     */
    public function week(Workspace $workspace, string $week): array
    {
        $monday = CarbonImmutable::parse($week, $workspace->timezone);
        $thisWeek = $workspace->toLocal(CarbonImmutable::now())->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();

        return [
            'start' => $monday->format('Y-m-d'),
            'end' => $monday->addDays(6)->format('Y-m-d'),
            'previous' => $monday->gt($thisWeek) ? $monday->subWeek()->format('Y-m-d') : null,
            'next' => $monday->lt($thisWeek->addWeeks(DraftingLimits::weeksAhead())) ? $monday->addWeek()->format('Y-m-d') : null,
        ];
    }

    /**
     * The provider and model the drafts would be asked from, or null when
     * drafting is not available.
     *
     * @return array{provider: string, model: string}|null
     */
    public function generator(Workspace $workspace): ?array
    {
        try {
            $context = $this->generators->contextFor($workspace);
        } catch (DraftGeneratorNotAvailable|LlmCredentialMissing) {
            return null;
        }

        return ['provider' => DraftingConfig::label($context->provider), 'model' => $context->model];
    }

    /**
     * What the plan is expected to cost and what it can cost at most, by
     * the price table (see ProposalCost). Null when there is nothing to
     * draft, no brief, no generator or no price for the model.
     *
     * @return array{currency: string, expected: string, worst: string}|null
     */
    public function cost(Workspace $workspace, WeekPlan $plan): ?array
    {
        $brief = Brief::current();

        if ($brief === null) {
            return null;
        }

        try {
            $cost = $this->proposalCost->of($plan, $brief, $this->generators->contextFor($workspace));
        } catch (DraftGeneratorNotAvailable|LlmCredentialMissing) {
            return null;
        }

        if ($cost === null) {
            return null;
        }

        return [
            'currency' => $cost['expected']->currency,
            // Both rounded up to whole cents: never shown lower than it is.
            'expected' => Money::upToCents($cost['expected']->amount),
            'worst' => Money::upToCents($cost['worst']->amount),
        ];
    }

    /**
     * Links to the pages that shape a proposal, for those that exist.
     *
     * @return array<string, string|null>
     */
    public function links(): array
    {
        return [
            'brief' => self::firstRoute(['brief.edit']),
            'pillars' => self::firstRoute(['pillars.index']),
            'slots' => self::firstRoute(['slots.index']),
            'ideas' => self::firstRoute(['ideas.index']),
        ];
    }

    /**
     * The latest proposal of the workspace, for the queue: while it is at
     * work, and for a while after, longer when a draft failed. Null when
     * there is nothing to show.
     *
     * @return array<string, mixed>|null
     */
    public function progress(): ?array
    {
        $latest = DraftRun::query()->whereNotNull('request->proposal')->latest('id')->first();
        $proposal = $latest?->request['proposal'] ?? null;

        if ($latest === null || ! is_string($proposal)) {
            return null;
        }

        $runs = DraftRun::query()
            ->where('request->proposal', $proposal)
            ->with(['pillar', 'idea', 'post', 'requester'])
            ->orderBy('id')
            ->get();

        // A run that was tried again is told by the run that replaced it.
        $replaced = $runs->map(fn (DraftRun $run): mixed => $run->request['retry_of'] ?? null)->filter()->all();
        $shown = $runs->reject(fn (DraftRun $run): bool => in_array($run->id, $replaced, true))
            ->sortBy(fn (DraftRun $run): string => self::text($run->request['planned_at'] ?? null).'|'.$run->id)
            ->values();

        $counts = ['pending' => 0, 'running' => 0, 'succeeded' => 0, 'failed' => 0];

        foreach ($shown as $run) {
            $counts[$run->status->value]++;
        }

        $active = $counts['pending'] + $counts['running'] > 0;

        if (! $active && ! $this->stillShown($runs->max('finished_at'), $counts['failed'] > 0)) {
            return null;
        }

        $channelIds = $shown->flatMap(fn (DraftRun $run): array => (array) ($run->request['channel_ids'] ?? []))->unique()->all();
        $channelNames = Channel::query()->whereKey($channelIds)->pluck('display_name', 'id');
        $first = $runs->first();

        return [
            'week' => self::text($latest->request['week'] ?? null),
            'requested_at' => $first?->created_at?->toIso8601String(),
            'requested_by' => $first?->requester?->name,
            'active' => $active,
            // A run has waited for a worker, or been with one, longer than
            // a queue that runs would need. The page then says so and
            // offers a way to stop waiting.
            'waiting_long' => $shown->contains(fn (DraftRun $run): bool => self::overdue($run)),
            'counts' => $counts,
            'used' => self::used($runs),
            'max_attempts' => DraftingLimits::maxAttempts(),
            'runs' => $shown->map(function (DraftRun $run) use ($runs, $channelNames): array {
                $earlier = $runs->firstWhere('id', $run->request['retry_of'] ?? null);
                $plannedAt = $run->request['planned_at'] ?? null;

                return [
                    'id' => $run->id,
                    'status' => $run->status->value,
                    'planned_at' => is_string($plannedAt) ? $plannedAt : null,
                    'channels' => collect((array) ($run->request['channel_ids'] ?? []))
                        ->map(fn (mixed $id): ?string => is_numeric($id) ? $channelNames->get((int) $id) : null)
                        ->filter()
                        ->values()
                        ->all(),
                    'pillar' => $run->pillar?->name,
                    'idea' => $run->idea?->title,
                    'attempt' => is_numeric($run->request['attempt'] ?? null) ? (int) $run->request['attempt'] : 1,
                    'failure_reason' => $run->failure_reason,
                    // Why this run exists, while it waits or works on a retry.
                    'earlier_failure' => $run->status->isFinished() ? null : $earlier?->failure_reason,
                    'post_id' => $run->status === DraftRunStatus::Succeeded ? $run->post?->id : null,
                ];
            })->all(),
        ];
    }

    /**
     * What the runs of a proposal used so far, every attempt included, also
     * the ones that failed: tokens as the provider reported them, and the
     * estimated cost when the model has a price. Null before any answer.
     *
     * @param  Collection<int, DraftRun>  $runs
     * @return array{input_tokens: int, output_tokens: int, cost: string|null, currency: string|null}|null
     */
    private static function used(Collection $runs): ?array
    {
        $answered = $runs->filter(fn (DraftRun $run): bool => $run->input_tokens !== null);

        if ($answered->isEmpty()) {
            return null;
        }

        $priced = $answered->filter(fn (DraftRun $run): bool => $run->cost_estimate !== null);
        $currency = $priced->first()?->cost_currency;

        return [
            'input_tokens' => (int) $answered->sum('input_tokens'),
            'output_tokens' => (int) $answered->sum('output_tokens'),
            'cost' => $currency !== null
                ? Money::used(Money::sum($priced->where('cost_currency', $currency)->map(fn (DraftRun $run): ?string => $run->cost_estimate)))
                : null,
            'currency' => $currency,
        ];
    }

    private static function overdue(DraftRun $run): bool
    {
        if ($run->status === DraftRunStatus::Pending) {
            $attempt = is_numeric($run->request['attempt'] ?? null) ? (int) $run->request['attempt'] : 1;

            return $run->created_at !== null
                && $run->created_at->lte(now()->subSeconds(DraftingLimits::slowAfterSeconds($attempt)));
        }

        return $run->status === DraftRunStatus::Running
            && $run->started_at !== null
            && $run->started_at->lte(now()->subSeconds(DraftingLimits::jobSeconds() + DraftingLimits::JOB_SLACK_SECONDS));
    }

    private function stillShown(mixed $finishedAt, bool $failed): bool
    {
        if (! $finishedAt instanceof CarbonImmutable) {
            return false;
        }

        return $failed
            ? $finishedAt->gt(now()->subHours(self::FAILED_SHOWN_HOURS))
            : $finishedAt->gt(now()->subMinutes(self::DONE_SHOWN_MINUTES));
    }

    /**
     * @param  list<string>  $names
     */
    private static function firstRoute(array $names): ?string
    {
        foreach ($names as $name) {
            if (Route::has($name)) {
                return route($name);
            }
        }

        return null;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
