<?php

use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Post;
use App\Domain\Workspaces\Models\Workspace;
use App\Http\Presenters\PostPresenter;
use Illuminate\Support\Facades\DB;

/**
 * Helpers for the tests of the post editor, the queue and the calendar.
 * Loaded by those test files with require_once, after publishing.php.
 */

/**
 * The fingerprint the pages send back when a post is approved, read from
 * the database as it is now.
 */
function postVersion(Post $post): string
{
    $fresh = Post::query()
        ->withoutGlobalScopes()
        ->with(['variants' => fn ($variants) => $variants->withoutGlobalScopes()])
        ->findOrFail($post->id);

    return app(PostPresenter::class)->version($fresh);
}

/**
 * A channel in the workspace that can publish.
 *
 * @param  array<string, mixed>  $attributes
 */
function channelIn(Workspace $workspace, array $attributes = []): Channel
{
    return inWorkspace($workspace, fn () => Channel::factory()->create($attributes));
}

/**
 * Sets the text of every variant of a post directly, whatever its state.
 */
function setBody(Post $post, string $body): void
{
    DB::table('post_variants')->where('post_id', $post->id)->update(['body' => $body]);
}

/**
 * The stored state of a post, read without a workspace.
 */
function stateOf(Post $post): string
{
    return (string) DB::table('posts')->where('id', $post->id)->value('state');
}
