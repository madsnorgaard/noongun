<?php

namespace App\Domain\Drafting;

use App\Domain\Channels\Exceptions\ChannelAdapterNotAvailable;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Idea;
use App\Domain\Content\Models\Pillar;
use App\Domain\Drafting\Actions\BuildDraftRequest;
use App\Domain\Drafting\Actions\GenerateDraft;
use App\Domain\Drafting\Actions\ListPlannedAlongside;
use App\Domain\Drafting\Actions\ProposeWeek;
use App\Domain\Drafting\Actions\SelectExamplePosts;
use App\Domain\Drafting\Exceptions\DraftGenerationFailed;
use App\Domain\Drafting\Exceptions\DraftGeneratorNotAvailable;
use App\Domain\Drafting\Exceptions\DraftingException;
use App\Domain\Drafting\Exceptions\DraftingNotAllowed;
use App\Domain\Drafting\Exceptions\DraftingStateConflict;
use App\Domain\Drafting\Exceptions\UnknownPromptVersion;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Scheduling\Models\Slot;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs one pending draft run: builds the request from the records the run
 * points at, asks the workspace's generator through GenerateDraft, and
 * leaves the run succeeded with a draft post, or failed with a reason a
 * person can read. The post it makes is a draft. This job cannot approve
 * anything.
 *
 * The job carries three numbers and nothing else. The key and the context
 * are resolved here, when it runs, and never travel through the queue.
 *
 * The examples (SelectExamplePosts) and the list of the other posts of the
 * week (ListPlannedAlongside) are also chosen here, when the job runs. The
 * run notes the ids of the posts whose texts went along as examples, which
 * of them were chosen because they did well, and whether its prompt told
 * the model so.
 *
 * A call to a provider costs money, so:
 *
 * - A run is started by one conditional UPDATE (DraftRun::markRunning(),
 *   inside GenerateDraft). A job that is delivered twice, dispatched twice
 *   or run again by hand finds the run taken and does nothing.
 * - The queue never runs this job again by itself ($tries is 1).
 * - Only a DraftGenerationFailed that the generator marks as retryable is
 *   tried again, and at most noongun.drafting.limits.max_attempts times in
 *   all. A run is never reopened: the failed run keeps its reason and what
 *   it used, and a new pending run is opened for the next attempt, with
 *   request['retry_of'] and request['attempt'], and queued after the
 *   configured backoff. No retry is opened once the workspace has used its
 *   draft runs for the day.
 * - Everything else ends the run as failed: invalid output (the generator
 *   has already asked twice), a refusal, a missing key, a channel that can
 *   no longer publish.
 */
final class GenerateDraftJob implements ShouldQueue
{
    use Queueable;

    public const INTERRUPTED = 'The worker stopped before the draft was finished. No draft was created. Propose the week again to fill this time.';

    public const REQUESTER_GONE = 'The person who asked for this draft is no longer a user here, so it was not drafted.';

    public const BRIEF_GONE = 'The brief this draft was asked from no longer exists, so it was not drafted.';

    public const CHANNELS_GONE = 'None of the channels this draft was planned for can publish any more, so it was not drafted.';

    private const LOGGED_MESSAGE_LENGTH = 500;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    /**
     * @param  int  $attempt  which try for this planned post this is, from 1
     */
    public function __construct(
        public int $workspaceId,
        public int $runId,
        public int $attempt = 1,
    ) {
        $this->timeout = DraftingLimits::jobSeconds();
    }

    public function handle(
        CurrentWorkspace $currentWorkspace,
        BuildDraftRequest $build,
        GenerateDraft $generate,
        DraftGenerators $generators,
        SelectExamplePosts $selectExamples,
        ListPlannedAlongside $listAlongside,
        PromptRenderer $prompts,
    ): void {
        $workspace = Workspace::query()->find($this->workspaceId);

        if ($workspace === null) {
            return;
        }

        $currentWorkspace->run($workspace, function (Workspace $workspace) use ($build, $generate, $generators, $selectExamples, $listAlongside, $prompts): void {
            $run = DraftRun::query()->find($this->runId);

            // Only a look: markRunning() inside GenerateDraft is the guard.
            if ($run === null || $run->status !== DraftRunStatus::Pending) {
                return;
            }

            $requester = $run->requester;
            $brief = $run->brief;

            if ($requester === null) {
                $run->markFailed(self::REQUESTER_GONE);

                return;
            }

            if ($brief === null) {
                $run->markFailed(self::BRIEF_GONE);

                return;
            }

            $channels = Channel::query()
                ->whereKey(self::ids($run->request['channel_ids'] ?? null))
                ->orderBy('id')
                ->get()
                ->filter(fn (Channel $channel): bool => $channel->canPublish());

            if ($channels->isEmpty()) {
                $run->markFailed(self::CHANNELS_GONE);

                return;
            }

            $planned = self::ids($run->request['channel_ids'] ?? null);
            $drafted = array_values($channels->modelKeys());

            // A channel that dropped out since the plan keeps its slot
            // open: the run must not answer for a slot it writes nothing for.
            if (count($drafted) < count($planned)) {
                $slots = Slot::query()
                    ->whereKey(self::ids($run->request['slot_ids'] ?? null))
                    ->whereIn('channel_id', $drafted)
                    ->orderBy('id')
                    ->pluck('id')
                    ->all();

                if (! $run->draftForFewer($drafted, array_values($slots), array_values(array_diff($planned, $drafted)))) {
                    return;
                }
            }

            $examples = $selectExamples->handle($channels);

            try {
                $request = $build->handle(
                    $brief,
                    $channels,
                    $run->pillar,
                    $run->idea,
                    examples: $examples,
                    alsoPlanned: $listAlongside->handle($workspace, self::plannedAt($run), self::notWrittenYet($run)),
                );
            } catch (ChannelAdapterNotAvailable) {
                $run->markFailed(self::CHANNELS_GONE);

                return;
            }

            try {
                $marked = $prompts->render($request, $run->prompt_version)->marksPerformance;
            } catch (UnknownPromptVersion) {
                // The generator refuses the run for it. Nothing is sent, so nothing is marked.
                $marked = false;
            }

            if (! $run->noteExamples($examples->postIds, $examples->performancePostIds, $marked, $examples->performanceLeftOut)) {
                return;
            }

            $next = null;

            try {
                $generate->handle($requester, $run, $request, self::plannedAt($run), function (DraftRun $failed, DraftingException $failure) use (&$next, $workspace, $requester, $generators): void {
                    // Still inside the transaction that marked the run
                    // failed, under the lock on the workspace row.
                    if ($failure instanceof DraftGenerationFailed && $failure->retryable) {
                        $next = $this->openNextAttempt($workspace, $failed, $requester, $generators);
                    }
                });
            } catch (DraftingException|DraftGeneratorNotAvailable|DraftingNotAllowed $failure) {
                // GenerateDraft has marked the run failed with this reason.
                $this->note($run, $failure);
            } catch (DraftingStateConflict $failure) {
                // The run was closed while the provider was answering: a
                // person stopped waiting, or the recovery gave it up. The
                // answer has nowhere to go and no post was created.
                $this->note($run, $failure);
            }

            if ($next instanceof DraftRun) {
                $this->queueNextAttempt($next);
            }
        });
    }

    /**
     * Called by the queue when the job threw or ran out of time. Nothing
     * will run it again, so a run that is still open is closed here.
     */
    public function failed(?Throwable $exception): void
    {
        // The queue delivered the job a second time. The first delivery
        // may still be at work, so the run is left to it or to
        // noongun:recover-stuck-draft-runs.
        if ($exception instanceof MaxAttemptsExceededException) {
            return;
        }

        try {
            $workspace = Workspace::query()->find($this->workspaceId);

            if ($workspace === null) {
                return;
            }

            app(CurrentWorkspace::class)->run($workspace, function (): void {
                DraftRun::query()->find($this->runId)?->markFailed(self::INTERRUPTED);
            });
        } catch (Throwable $e) {
            Log::error('A failed drafting job could not close its run. The recovery command will.', [
                'workspace_id' => $this->workspaceId,
                'draft_run_id' => $this->runId,
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Opens the run for the next attempt, unless the attempts, the runs of
     * the workspace for the day or those of the instance key are used up.
     * Called under the lock on the workspace row, in the transaction that
     * marked the failed run, so the limits are counted where a proposal
     * counts them and the workspace is never without an open run in
     * between.
     */
    private function openNextAttempt(Workspace $workspace, DraftRun $failed, User $requester, DraftGenerators $generators): ?DraftRun
    {
        if ($this->attempt >= DraftingLimits::maxAttempts()) {
            return null;
        }

        $budget = app(DraftBudget::class);

        if ($budget->usedByWorkspace() >= DraftingLimits::runsPerDay()) {
            return null;
        }

        try {
            $context = $generators->contextFor($workspace);
        } catch (DraftingException|DraftGeneratorNotAvailable) {
            return null;
        }

        if ($context->key->scope === CredentialScope::Instance && $budget->usedOnInstanceKey() >= DraftingLimits::instanceRunsPerDay()) {
            return null;
        }

        return DraftRun::start($requester, $context, [
            ...$failed->request,
            'attempt' => $this->attempt + 1,
            'retry_of' => $failed->id,
        ], $failed->brief, $failed->pillar, $failed->idea);
    }

    /**
     * Queues the job of a run that was opened for a retry, after the
     * backoff. A run that cannot be queued is closed at once: left pending
     * it would hold the workspace until the recovery found it.
     */
    private function queueNextAttempt(DraftRun $next): void
    {
        try {
            dispatch(new self($this->workspaceId, $next->id, $this->attempt + 1))
                ->delay(DraftingLimits::backoffBefore($this->attempt));
        } catch (Throwable $failure) {
            // The exception itself is not logged: a queue connection
            // failure can carry its address and password.
            Log::error('The retry of a draft run could not be queued.', [
                'workspace_id' => $this->workspaceId,
                'draft_run_id' => $next->id,
                'exception' => $failure::class,
            ]);

            $next->markFailed(ProposeWeek::NOT_QUEUED);
        }
    }

    /**
     * Writes down for the operator why the run failed: the class of the
     * failure, its message, and the detail a drafting exception keeps for
     * the operator and does not show to members. The message is cleaned of
     * anything shaped like a credential and cut short before it is logged.
     */
    private function note(DraftRun $run, Throwable $failure): void
    {
        Log::warning('A draft run failed.', [
            'workspace_id' => $this->workspaceId,
            'draft_run_id' => $run->id,
            'attempt' => $this->attempt,
            'exception' => $failure::class,
            'retryable' => $failure instanceof DraftingException && $failure->retryable,
            'message' => Str::limit(DraftingException::redact($failure->getMessage()), self::LOGGED_MESSAGE_LENGTH),
            'operator_detail' => $failure instanceof DraftingException ? $failure->operatorDetail() : null,
        ]);
    }

    /**
     * What the other runs of the same proposal that are still open are
     * drafting. A run that is done is told by its post, and a run that
     * failed for good by nothing.
     *
     * @return list<array{pillar: Pillar|null, idea: Idea|null}>
     */
    private static function notWrittenYet(DraftRun $run): array
    {
        $proposal = $run->request['proposal'] ?? null;

        if (! is_string($proposal)) {
            return [];
        }

        $open = DraftRun::query()
            ->inStatus(DraftRunStatus::Pending, DraftRunStatus::Running)
            ->where('request->proposal', $proposal)
            ->whereKeyNot($run->id)
            ->with(['pillar', 'idea'])
            ->orderBy('id')
            ->get();

        return array_values($open->map(fn (DraftRun $other): array => ['pillar' => $other->pillar, 'idea' => $other->idea])->all());
    }

    private static function plannedAt(DraftRun $run): ?CarbonImmutable
    {
        $plannedAt = $run->request['planned_at'] ?? null;

        if (! is_string($plannedAt)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($plannedAt)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $values): array
    {
        $ids = [];

        foreach (is_array($values) ? $values : [] as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }
}
