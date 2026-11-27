<?php

namespace App\Http\Controllers\Posts\Concerns;

use App\Domain\Content\Models\Post;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

trait ActsOnPosts
{
    /**
     * The post with what the pages show of it. Looked up here, not by route
     * binding: the workspace scope needs the current workspace, which is set
     * after bindings are resolved. A post of another workspace is a 404.
     */
    protected function findPost(int $id): Post
    {
        return Post::query()->with(['variants.channel', 'variants.attachments.media', 'approver'])->findOrFail($id);
    }

    protected function actor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }

    protected function mayChangePosts(Request $request): bool
    {
        return $request->user()?->can('create', Post::class) ?? false;
    }

    /**
     * @param  'success'|'info'|'warning'|'error'  $type
     */
    protected function toast(string $type, string $message): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
    }
}
