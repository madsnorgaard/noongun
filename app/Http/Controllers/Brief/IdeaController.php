<?php

namespace App\Http\Controllers\Brief;

use App\Domain\Content\Models\Idea;
use App\Domain\Content\Models\Pillar;
use App\Http\Controllers\Brief\Concerns\ActsAsMember;
use App\Http\Controllers\Controller;
use App\Http\Requests\Brief\SaveIdeaRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Topics to write about. The source of an idea is stored as an address and
 * is never opened by the server.
 */
class IdeaController extends Controller
{
    use ActsAsMember;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Idea::class);

        $ideas = Idea::query()->with('pillar')->withCount('posts')->orderByDesc('id')->get();

        return Inertia::render('ideas/Index', [
            'ideas' => $ideas->map(fn (Idea $idea): array => [
                'id' => $idea->id,
                'title' => $idea->title,
                'pillar_id' => $idea->pillar_id,
                'pillar_name' => $idea->pillar?->name,
                'source_url' => $idea->source_url,
                'notes' => $idea->notes,
                // Shown before an idea is deleted: these lose their idea.
                'posts_count' => (int) $idea->getAttribute('posts_count'),
                'created_at' => $idea->created_at?->toIso8601String(),
            ])->all(),
            'pillars' => Pillar::query()->orderBy('name')->get()
                ->map(fn (Pillar $pillar): array => ['id' => $pillar->id, 'name' => $pillar->name])
                ->all(),
            'limits' => [
                'title' => SaveIdeaRequest::MAX_TITLE_LENGTH,
                'source_url' => SaveIdeaRequest::MAX_URL_LENGTH,
                'notes' => SaveIdeaRequest::MAX_NOTES_LENGTH,
            ],
            'can' => [
                'manage' => $this->mayWrite($request, Idea::class),
            ],
        ]);
    }

    public function store(SaveIdeaRequest $request): RedirectResponse
    {
        Idea::query()->create($request->ideaAttributes());

        $this->toast('success', __('The idea is added.'));

        return to_route('ideas.index');
    }

    public function update(SaveIdeaRequest $request, int $idea): RedirectResponse
    {
        // Looked up here, not by route binding: the workspace scope needs the
        // current workspace, which is set after bindings are resolved.
        $idea = Idea::query()->findOrFail($idea);

        Gate::authorize('update', $idea);

        $idea->update($request->ideaAttributes());

        $this->toast('success', __('The idea is saved.'));

        return to_route('ideas.index');
    }

    /**
     * Removes the idea. Posts that were written from it stay and lose the
     * link to it: the foreign key clears the column.
     */
    public function destroy(int $idea): RedirectResponse
    {
        $idea = Idea::query()->findOrFail($idea);

        Gate::authorize('delete', $idea);

        $idea->delete();

        $this->toast('success', __('The idea is deleted. Posts that were written from it are kept.'));

        return to_route('ideas.index');
    }
}
