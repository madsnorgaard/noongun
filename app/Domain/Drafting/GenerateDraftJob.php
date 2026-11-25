<?php

namespace App\Domain\Drafting;

use App\Domain\Channels\Exceptions\ChannelAdapterNotAvailable;
use App\Domain\Channels\Models\Channel;
use App\Domain\Drafting\Actions\BuildDraftRequest;
use App\Domain\Drafting\Actions\GenerateDraft;
use App\Domain\Drafting\Exceptions\DraftGenerationFailed;
use App\Domain\Drafting\Exceptions\DraftGeneratorNotAvailable;
use App\Domain\Drafting\Exceptions\DraftingException;
use App\Domain\Drafting\Exceptions\DraftingNotAllowed;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Log;
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

    public const REQUESTER_GONE = 'The person who asked for this draft is no longer a user here, so it was not generated.';

    public const BRIEF_GONE = 'The brief this draft was asked from no longer exists, so it was not generated.';

    public const CHANNELS_GONE = 'None of the channels this draft was planned for can publish any more, so it was not generated.';

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

    public function handle(CurrentWorkspace $currentWorkspace, BuildDraftRequest $build, GenerateDraft $generate, DraftGenerators $generators): void
    {
        $workspace = Workspace::query()->find($this->workspaceId);

        if ($workspace === null) {
            return;
        }

        $currentWorkspace->run($workspace, function (Workspace $workspace) use ($build, $generate, $generators): void {
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

            try {
                $request = $build->handle($brief, $channels, $run->pillar, $run->idea);
            } catch (ChannelAdapterNotAvailable) {
                $run->markFailed(self::CHANNELS_GONE);

                return;
            }

            try {
                $generate->handle($requester, $run, $request, self::plannedAt($run));
            } catch (DraftGenerationFailed $failure) {
                $this->note($run, $failure);

                if ($failure->retryable) {
                    $this->openNextAttempt($workspace, $run, $requester, $generators);
                }
            } catch (DraftingException|DraftGeneratorNotAvailable|DraftingNotAllowed $failure) {
                // GenerateDraft has marked the run failed with this reason.
                $this->note($run, $failure);
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
     * Opens the run for the next attempt and queues its job after the
     * backoff, unless the attempts or the runs of the day are used up.
     */
    private function openNextAttempt(Workspace $workspace, DraftRun $failed, User $requester, DraftGenerators $generators): void
    {
        if ($this->attempt >= DraftingLimits::maxAttempts()) {
            return;
        }

        if (DraftRun::query()->where('created_at', '>', now()->subDay())->count() >= DraftingLimits::runsPerDay()) {
            return;
        }

        try {
            $context = $generators->contextFor($workspace);
        } catch (DraftingException|DraftGeneratorNotAvailable) {
            return;
        }

        $next = DraftRun::start($requester, $context, [
            ...$failed->request,
            'attempt' => $this->attempt + 1,
            'retry_of' => $failed->id,
        ], $failed->brief, $failed->pillar, $failed->idea);

        dispatch(new self($this->workspaceId, $next->id, $this->attempt + 1))
            ->delay(DraftingLimits::backoffBefore($this->attempt));
    }

    /**
     * The messages of these exceptions are made safe by their classes.
     */
    private function note(DraftRun $run, Throwable $failure): void
    {
        Log::warning('A draft run failed.', [
            'workspace_id' => $this->workspaceId,
            'draft_run_id' => $run->id,
            'attempt' => $this->attempt,
            'retryable' => $failure instanceof DraftingException && $failure->retryable,
            'message' => $failure instanceof DraftingException ? $failure->getMessage() : $failure::class,
        ]);
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
