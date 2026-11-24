<?php

namespace App\Domain\Scheduling\Console;

use App\Domain\Content\Models\Post;
use App\Domain\Content\PostState;
use App\Domain\Scheduling\PublishingConfig;
use App\Domain\Scheduling\SettleInterruptedPost;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Console\Command;
use Throwable;

/**
 * Finds posts that have been in the publishing state for too long without a
 * sign of life from their job (posts.updated_at, set by the claim and by
 * each attempt) and settles them. See SettleInterruptedPost: nothing is sent
 * again, a post with every variant out becomes published, any other becomes
 * failed and waits for a human.
 */
class RecoverStuckPosts extends Command
{
    protected $signature = 'noongun:recover-stuck-posts';

    protected $description = 'Settle posts whose publishing was interrupted';

    public function handle(CurrentWorkspace $currentWorkspace, SettleInterruptedPost $settle): int
    {
        $silentSince = now()->subSeconds(PublishingConfig::stuckAfterSeconds());
        $settled = 0;
        $errors = 0;

        foreach (Workspace::query()->lazyById(200) as $workspace) {
            try {
                $settled += $currentWorkspace->run($workspace, function () use ($settle, $silentSince): int {
                    $stuck = Post::query()
                        ->inState(PostState::Publishing)
                        ->where('updated_at', '<=', $silentSince)
                        ->orderBy('id')
                        ->get();

                    return $stuck->filter(fn (Post $post): bool => $settle->handle($post) !== null)->count();
                });
            } catch (Throwable $e) {
                $errors++;
                report($e);
            }
        }

        $this->info("Settled {$settled} interrupted post(s).");

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
