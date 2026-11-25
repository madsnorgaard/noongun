<?php

namespace App\Http\Controllers\Brief;

use App\Domain\Content\Models\Pillar;
use App\Http\Controllers\Brief\Concerns\ActsAsMember;
use App\Http\Controllers\Controller;
use App\Http\Requests\Brief\SavePillarRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The content themes of the workspace and how many posts a week each gets.
 */
class PillarController extends Controller
{
    use ActsAsMember;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Pillar::class);

        $pillars = Pillar::query()->withCount(['ideas', 'posts'])->orderBy('name')->get();

        return Inertia::render('brief/Pillars', [
            'pillars' => $pillars->map(fn (Pillar $pillar): array => [
                'id' => $pillar->id,
                'name' => $pillar->name,
                'description' => $pillar->description,
                'weekly_target' => $pillar->weekly_target,
                // Shown before a pillar is deleted: these lose their pillar.
                'ideas_count' => (int) $pillar->getAttribute('ideas_count'),
                'posts_count' => (int) $pillar->getAttribute('posts_count'),
            ])->all(),
            'limits' => [
                'name' => SavePillarRequest::MAX_NAME_LENGTH,
                'description' => SavePillarRequest::MAX_DESCRIPTION_LENGTH,
                'weekly_target' => SavePillarRequest::MAX_WEEKLY_TARGET,
            ],
            'can' => [
                'manage' => $this->mayWrite($request, Pillar::class),
            ],
        ]);
    }

    public function store(SavePillarRequest $request): RedirectResponse
    {
        $pillar = Pillar::query()->create($request->pillarAttributes());

        $this->toast('success', __(':name is added.', ['name' => $pillar->name]));

        return to_route('pillars.index');
    }

    public function update(SavePillarRequest $request, int $pillar): RedirectResponse
    {
        // Looked up here, not by route binding: the workspace scope needs the
        // current workspace, which is set after bindings are resolved.
        $pillar = Pillar::query()->findOrFail($pillar);

        Gate::authorize('update', $pillar);

        $pillar->update($request->pillarAttributes());

        $this->toast('success', __(':name is saved.', ['name' => $pillar->name]));

        return to_route('pillars.index');
    }

    /**
     * Removes the pillar. Its ideas and posts stay and lose their pillar:
     * the foreign keys clear the column.
     */
    public function destroy(int $pillar): RedirectResponse
    {
        $pillar = Pillar::query()->findOrFail($pillar);

        Gate::authorize('delete', $pillar);

        $pillar->delete();

        $this->toast('success', __(':name is deleted. Its ideas and posts are kept.', ['name' => $pillar->name]));

        return to_route('pillars.index');
    }
}
