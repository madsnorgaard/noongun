<?php

namespace App\Domain\Drafting;

use App\Domain\Drafting\Models\DraftRun;
use Illuminate\Database\Eloquent\Builder;

/**
 * How much of its drafting allowance a workspace has used. The one place
 * that decides which draft runs count, for the check before a proposal
 * starts and for the check before a retry is opened.
 *
 * A run counts when it was opened in the last 24 hours and either is still
 * open (it may yet spend) or recorded tokens (it reached a provider and was
 * paid for, whether it succeeded or failed). A run that ended without
 * tokens spent nothing and counts for nothing: one that could not be
 * queued, one no worker picked up, one a provider turned away for a wrong
 * key or an unknown model. Fixing a key therefore never uses up the day.
 */
final class DraftBudget
{
    public const WINDOW_HOURS = 24;

    /**
     * The runs of the current workspace that count towards
     * noongun.drafting.limits.runs_per_day.
     */
    public function usedByWorkspace(): int
    {
        return self::counting(DraftRun::query())->count();
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $runs
     * @return Builder<TModel>
     */
    private static function counting(Builder $runs): Builder
    {
        return $runs
            ->where('created_at', '>', now()->subHours(self::WINDOW_HOURS))
            ->where(fn (Builder $run) => $run
                ->whereIn('status', [DraftRunStatus::Pending, DraftRunStatus::Running])
                ->orWhereNotNull('input_tokens'));
    }
}
