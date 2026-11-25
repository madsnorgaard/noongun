<?php

namespace App\Http\Controllers\Drafting;

use App\Domain\Drafting\Actions\ProposeWeek;
use App\Domain\Drafting\DraftingLimits;
use App\Domain\Drafting\Exceptions\ProposalRefused;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Drafting\Planning\PlanWeek;
use App\Domain\Drafting\Planning\ProposalChecks;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ProposalPresenter;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Propose a week": one page that shows what would be drafted and what it
 * can cost, and one request that starts it. Starting opens draft runs and
 * queues their jobs; it creates no post and approves nothing. The drafts
 * arrive in the queue, where a person approves each one or not.
 */
class ProposalController extends Controller
{
    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly ProposalPresenter $presenter,
    ) {}

    public function create(Request $request, PlanWeek $planWeek, ProposalChecks $checks): Response
    {
        Gate::authorize('create', DraftRun::class);

        $workspace = $this->currentWorkspace->getOrFail();
        $asked = $request->query('week');
        $week = PlanWeek::weekOf($workspace, is_string($asked) ? $asked : null);
        $plan = $planWeek->handle($workspace, $week);

        $refusals = $checks->missingSetup($workspace);

        if ($refusals === [] && $plan->isEmpty()) {
            $refusals[] = ProposalRefused::because(
                ProposalRefused::NOTHING_TO_DRAFT,
                (string) __('There is nothing to draft in this week: every slot time has passed or already has a post.'),
            );
        }

        $refusals = [...$refusals, ...$checks->overLimits(count($plan->posts))];

        return Inertia::render('proposals/Create', [
            'week' => $this->presenter->week($workspace, $week),
            'plan' => $this->presenter->plan($plan),
            'refusals' => $this->presenter->refusals($refusals),
            'generator' => $this->presenter->generator($workspace),
            'cost' => $this->presenter->costCeiling($workspace, count($plan->posts)),
            'limits' => [
                'posts_per_proposal' => DraftingLimits::postsPerProposal(),
                'runs_per_day' => DraftingLimits::runsPerDay(),
                'runs_used' => $checks->runsInLastDay(),
                'max_attempts' => DraftingLimits::maxAttempts(),
            ],
            'links' => $this->presenter->links(),
        ]);
    }

    public function store(Request $request, ProposeWeek $propose): RedirectResponse
    {
        Gate::authorize('create', DraftRun::class);

        $validated = $request->validate([
            'week' => ['required', 'date_format:Y-m-d'],
            'plan' => ['required', 'string', 'max:64'],
        ]);

        $actor = $request->user();

        abort_unless($actor instanceof User, 401);

        $workspace = $this->currentWorkspace->getOrFail();
        $week = PlanWeek::weekOf($workspace, (string) $validated['week']);

        try {
            $runs = $propose->handle($actor, $week, (string) $validated['plan']);
        } catch (ProposalRefused $refusal) {
            throw ValidationException::withMessages(['proposal' => [$refusal->getMessage()]]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(
                '{1} :count draft is being generated. It waits for your approval in the queue.|[2,*] :count drafts are being generated. They wait for your approval in the queue.',
                count($runs),
                ['count' => count($runs)],
            ),
        ]);

        return to_route('queue.index');
    }
}
