<?php

namespace App\Http\Controllers\Brief;

use App\Domain\Drafting\Actions\SaveBrief;
use App\Domain\Drafting\Models\Brief;
use App\Http\Controllers\Brief\Concerns\ActsAsMember;
use App\Http\Controllers\Controller;
use App\Http\Presenters\BriefPresenter;
use App\Http\Requests\Brief\SaveBriefRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The brief of the workspace. Saving goes through SaveBrief and always adds
 * a version; earlier versions can be read and are never changed or restored.
 */
class BriefController extends Controller
{
    use ActsAsMember;

    /** The version list shows this many of the latest versions. */
    private const VERSIONS_SHOWN = 50;

    public function __construct(private readonly BriefPresenter $presenter) {}

    public function edit(Request $request): Response
    {
        Gate::authorize('viewAny', Brief::class);

        $versions = Brief::query()->with('author')->orderByDesc('version')->limit(self::VERSIONS_SHOWN)->get();
        $current = $versions->firstWhere('is_current', true);

        return Inertia::render('brief/Edit', [
            'brief' => $current !== null ? $this->presenter->present($current) : null,
            'versions' => $versions->map($this->presenter->summary(...))->all(),
            'version_count' => Brief::query()->count(),
            'platforms' => $this->presenter->platforms($current),
            'starter_voice' => $this->presenter->starterVoice(),
            'limits' => [
                'title' => SaveBriefRequest::MAX_TITLE_LENGTH,
                'body' => SaveBriefRequest::MAX_BODY_LENGTH,
                'rules' => SaveBriefRequest::MAX_RULES,
                'rule' => SaveBriefRequest::MAX_RULE_LENGTH,
                'calls_to_action' => SaveBriefRequest::MAX_CALLS_TO_ACTION,
                'call' => SaveBriefRequest::MAX_CALL_LENGTH,
            ],
            'can' => [
                'update' => $this->mayWrite($request, Brief::class),
            ],
        ]);
    }

    public function update(SaveBriefRequest $request, SaveBrief $save): RedirectResponse
    {
        $title = $request->briefTitle();
        $body = $request->briefBody();
        $voiceRules = $request->voiceRules();
        $callsToAction = $request->callsToAction();

        $current = Brief::current();

        // A save that changes nothing would only add a copy to the history.
        if ($current !== null
            && $current->title === $title
            && $current->body === $body
            && $current->voice_rules->toArray() === $voiceRules->toArray()
            && $current->calls_to_action === $callsToAction) {
            $this->toast('info', __('Nothing changed. Version :version is still the current one.', ['version' => $current->version]));

            return to_route('brief.edit');
        }

        $brief = $save->handle($this->actor($request), $title, $body, $voiceRules, $callsToAction);

        $this->toast('success', __('The brief is saved as version :version. The next drafts are written from it.', ['version' => $brief->version]));

        return to_route('brief.edit');
    }

    public function showVersion(int $version): Response
    {
        $brief = Brief::query()->with('author')->where('version', $version)->firstOrFail();

        Gate::authorize('view', $brief);

        return Inertia::render('brief/Version', [
            'brief' => $this->presenter->present($brief),
            'platforms' => $this->presenter->platforms($brief),
        ]);
    }
}
