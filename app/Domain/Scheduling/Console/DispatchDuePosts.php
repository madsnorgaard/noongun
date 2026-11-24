<?php

namespace App\Domain\Scheduling\Console;

use App\Domain\Content\Models\Post;
use App\Domain\Scheduling\Exceptions\LockStoreNotShared;
use App\Domain\Scheduling\PublishPostJob;
use App\Domain\Scheduling\VariantLock;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Run by the scheduler every minute. For each workspace it finds the posts
 * that are scheduled with a time that has come, claims each one and queues
 * its PublishPostJob.
 *
 * The claim is one conditional UPDATE (scheduled and due -> publishing), so
 * a post is queued once however many copies of this command run. The command only
 * ever looks at scheduled posts: it cannot approve or schedule anything.
 */
class DispatchDuePosts extends Command
{
    protected $signature = 'noongun:dispatch-due-posts';

    protected $description = 'Queue every scheduled post whose time has come';

    private int $errors = 0;

    public function handle(CurrentWorkspace $currentWorkspace): int
    {
        try {
            // Nothing is claimed while the locks would not work.
            VariantLock::assertStoreIsShared();
        } catch (LockStoreNotShared $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $now = now();
        $dispatched = 0;
        $this->errors = 0;

        foreach (Workspace::query()->lazyById(200) as $workspace) {
            try {
                $dispatched += $currentWorkspace->run($workspace, fn (): int => $this->dispatchDue($now));
            } catch (Throwable $e) {
                $this->errors++;
                report($e);
            }
        }

        $this->info("Queued {$dispatched} due post(s).");

        return $this->errors === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function dispatchDue(DateTimeInterface $now): int
    {
        $dispatched = 0;

        foreach (Post::query()->due($now)->get() as $post) {
            try {
                // The database refuses a scheduled row without an approval.
                // Checked anyway: nothing reaches a platform without one.
                if ($post->approved_at === null) {
                    Log::critical('A scheduled post without an approval was not dispatched.', ['post_id' => $post->id]);

                    continue;
                }

                // The time is part of the claim: a post that was moved
                // to a later time since the query is left alone.
                if (! $post->claimForPublishing($now)) {
                    continue;
                }

                // If queueing fails the post stays in publishing with no
                // job, and noongun:recover-stuck-posts fails it later.
                dispatch(PublishPostJob::forClaimed($post));

                $dispatched++;
            } catch (Throwable $e) {
                $this->errors++;
                report($e);
            }
        }

        return $dispatched;
    }
}
