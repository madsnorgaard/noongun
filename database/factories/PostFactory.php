<?php

namespace Database\Factories;

use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostState;
use App\Domain\Workspaces\Models\Membership;
use App\Domain\Workspaces\Role;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Create inside a workspace: inWorkspace($workspace, fn () => Post::factory()->create()).
 *
 * Posts are always created as drafts. The state methods below put the row
 * into a later state after it and its variants exist, writing the columns
 * directly. That shortcut is for tests and seeders only; application code
 * uses the transition methods on Post.
 *
 * Add variants before the state, because variants can only be created on a
 * draft: Post::factory()->withVariants(2)->scheduled()->create().
 *
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    protected $model = Post::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scheduled_at' => null,
        ];
    }

    /**
     * One variant per given channel, or that many variants on new channels.
     *
     * @param  int|iterable<Channel>  $channels
     */
    public function withVariants(int|iterable $channels = 1): static
    {
        if (is_int($channels)) {
            return $this->has(PostVariant::factory()->count($channels), 'variants');
        }

        $factory = $this;

        foreach ($channels as $channel) {
            $factory = $factory->has(PostVariant::factory()->state(['channel_id' => $channel->id]), 'variants');
        }

        return $factory;
    }

    /**
     * A draft with a planned time.
     */
    public function plannedFor(DateTimeInterface $at): static
    {
        return $this->state(fn () => ['scheduled_at' => $at]);
    }

    public function rejected(): static
    {
        return $this->inState(PostState::Rejected);
    }

    /**
     * Approved by the given user, or by a new owner of the post's workspace.
     */
    public function approved(?User $by = null): static
    {
        return $this->inState(PostState::Approved, by: $by);
    }

    /**
     * Scheduled for the given time, or else one hour from now.
     */
    public function scheduled(?DateTimeInterface $at = null, ?User $by = null): static
    {
        return $this->inState(PostState::Scheduled, $at ?? now()->addHour(), $by);
    }

    /**
     * Scheduled for a minute ago, so the scheduler picks it up.
     */
    public function due(?User $by = null): static
    {
        return $this->scheduled(now()->subMinute(), $by);
    }

    public function publishing(?User $by = null): static
    {
        return $this->inState(PostState::Publishing, now()->subMinute(), $by);
    }

    /**
     * Published, with every variant marked as published.
     */
    public function published(?User $by = null): static
    {
        return $this->inState(PostState::Published, now()->subMinute(), $by)
            ->afterCreating(function (Post $post): void {
                foreach ($post->variants()->get() as $variant) {
                    DB::table('post_variants')->where('id', $variant->id)->update([
                        'external_id' => 'ext_'.$variant->id,
                        'permalink' => 'https://platform.test/posts/ext_'.$variant->id,
                        'published_at' => now(),
                        'error' => null,
                    ]);
                }
            });
    }

    /**
     * Failed, with every variant carrying an error.
     */
    public function failed(?User $by = null): static
    {
        return $this->inState(PostState::Failed, now()->subMinute(), $by)
            ->afterCreating(function (Post $post): void {
                DB::table('post_variants')->where('post_id', $post->id)->whereNull('external_id')->update([
                    'error' => 'The platform refused the post.',
                ]);
            });
    }

    private function inState(PostState $state, ?DateTimeInterface $at = null, ?User $by = null): static
    {
        return $this->afterCreating(function (Post $post) use ($state, $at, $by): void {
            $values = ['state' => $state->value];

            if ($at !== null) {
                $values['scheduled_at'] = $at;
            }

            if ($state->requiresApproval()) {
                $by ??= User::factory()->create();

                Membership::query()->firstOrCreate(
                    ['workspace_id' => $post->workspace_id, 'user_id' => $by->id],
                    ['role' => Role::Owner],
                );

                $values['approved_by'] = $by->id;
                $values['approved_at'] = now();
            }

            DB::table('posts')->where('id', $post->id)->update($values);

            $post->refresh();
        });
    }
}
