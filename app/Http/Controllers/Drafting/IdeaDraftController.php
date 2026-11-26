<?php

namespace App\Http\Controllers\Drafting;

use App\Domain\Content\Models\Idea;
use App\Domain\Drafting\Actions\DraftFromIdea;
use App\Domain\Drafting\DraftBudget;
use App\Domain\Drafting\DraftingLimits;
use App\Domain\Drafting\Exceptions\ProposalRefused;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Drafting\Planning\PlanIdeaDraft;
use App\Domain\Drafting\Planning\ProposalChecks;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use App\Http\Controllers\Controller;
use App\Http\Presenters\PostPresenter;
use App\Http\Presenters\ProposalPresenter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Draft a post" on one idea: one page that shows what would be drafted
 * and what it can cost, and one request that starts it. Starting opens one
 * draft run and queues its job; it creates no post and approves nothing.
 * The draft arrives in the queue, where a person approves it or not.
 *
 * Who may do this is who may propose a week: owners and editors.
 */
class IdeaDraftController extends Controller
{
    /** The refusals that belong to a field of the page; the others stand by the button. */
    private const FIELDS = [
        ProposalRefused::CHANNELS_CHOSEN => 'channels',
        ProposalRefused::PLANNED_TIME => 'scheduled_at',
    ];

    private const MAX_CHANNELS = 20;

    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly ProposalPresenter $presenter,
    ) {}

    /**
     * The choice travels in the address (?channels[]=..&at=..), so that the
     * cost and the checks on the page are the server's, for exactly what is
     * ticked. Nothing is created or spent here.
     */
    public function create(Request $request, int $idea, PlanIdeaDraft $planIdeaDraft, ProposalChecks $checks, DraftBudget $budget): Response
    {
        Gate::authorize('create', DraftRun::class);

        $workspace = $this->currentWorkspace->getOrFail();
        // Looked up here, not by route binding: the workspace scope needs
        // the current workspace, which is set after bindings are resolved.
        $idea = Idea::query()->with('pillar')->findOrFail($idea);

        $asked = $request->query('channels');
        $at = $request->query('at');

        $plan = $planIdeaDraft->handle(
            $idea,
            is_array($asked) ? self::ids($asked) : null,
            self::instant($workspace, is_string($at) ? $at : null),
        );

        return Inertia::render('ideas/Draft', [
            'plan' => $this->presenter->ideaDraft($workspace, $plan),
            'refusals' => $this->presenter->refusals([
                ...$checks->missingSetup($workspace, needsSlot: false),
                ...$checks->overLimits($workspace, 1),
            ]),
            'generator' => $this->presenter->generator($workspace),
            'cost' => $this->presenter->costOfIdeaDraft($workspace, $plan),
            'limits' => [
                'runs_per_day' => DraftingLimits::runsPerDay(),
                'runs_used' => $budget->usedByWorkspace(),
                'max_attempts' => DraftingLimits::maxAttempts(),
            ],
        ]);
    }

    public function store(Request $request, int $idea, DraftFromIdea $draft): RedirectResponse
    {
        Gate::authorize('create', DraftRun::class);

        $workspace = $this->currentWorkspace->getOrFail();
        $idea = Idea::query()->findOrFail($idea);

        $validated = $request->validate([
            'channels' => ['required', 'array', 'min:1', 'max:'.self::MAX_CHANNELS],
            'channels.*' => ['required', 'integer', 'distinct'],
            'scheduled_at' => ['nullable', 'date_format:'.PostPresenter::LOCAL_FORMAT],
            'plan' => ['required', 'string', 'max:64'],
        ], [
            'channels.required' => __('Choose at least one channel.'),
            'channels.min' => __('Choose at least one channel.'),
            'scheduled_at.date_format' => __('The planned time is not a valid date and time.'),
        ]);

        $actor = $request->user();

        abort_unless($actor instanceof User, 401);

        $at = $validated['scheduled_at'] ?? null;

        try {
            $draft->handle(
                $actor,
                $idea->id,
                self::ids((array) $validated['channels']),
                self::instant($workspace, is_string($at) ? $at : null),
                (string) $validated['plan'],
            );
        } catch (ProposalRefused $refusal) {
            throw ValidationException::withMessages([
                self::FIELDS[$refusal->reason] ?? 'draft' => [$refusal->getMessage()],
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('The post is being drafted. It waits for your approval in the queue.'),
        ]);

        return to_route('queue.index');
    }

    /**
     * A wall-clock time of the workspace as an instant, or null when none
     * was given or it is not a time.
     */
    private static function instant(Workspace $workspace, ?string $local): ?CarbonImmutable
    {
        if ($local === null || $local === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat(PostPresenter::LOCAL_FORMAT, $local);

        if ($parsed === false || $parsed->format(PostPresenter::LOCAL_FORMAT) !== $local) {
            return null;
        }

        return $workspace->toUtc($local);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<int>
     */
    private static function ids(array $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $ids[(int) $value] = (int) $value;
            }
        }

        sort($ids);

        return array_slice($ids, 0, self::MAX_CHANNELS);
    }
}
