<?php

namespace App\Http\Controllers\Posts;

use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostState;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Posts\Concerns\ActsOnPosts;
use App\Http\Presenters\MediaPresenter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A week or a month of posts at their times, in the workspace timezone. The
 * days are worked out here, so the page needs no date library: it gets one
 * entry per day with the posts of that day in time order.
 *
 * Every post with a time is shown except rejected ones. A draft or an
 * approved post with a planned time is shown as a plan, not as something
 * that will go out.
 */
class CalendarController extends Controller
{
    use ActsOnPosts;

    private const DATE_FORMAT = 'Y-m-d';

    private const FIRST_DAY_OF_WEEK = CarbonInterface::MONDAY;

    private const EXCERPT_LENGTH = 140;

    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Post::class);

        $workspace = $this->currentWorkspace->getOrFail();
        $view = $request->query('view') === 'month' ? 'month' : 'week';
        $today = CarbonImmutable::now($workspace->timezone)->startOfDay();
        $anchor = $this->dateFromQuery($request->query('date'), $workspace->timezone) ?? $today;

        if ($view === 'month') {
            $period = $anchor->startOfMonth();
            $start = $period->startOfWeek(self::FIRST_DAY_OF_WEEK);
            $end = $period->endOfMonth()->startOfWeek(self::FIRST_DAY_OF_WEEK)->addWeek();
            $previous = $period->subMonth();
            $next = $period->addMonth();
        } else {
            $period = $anchor->startOfWeek(self::FIRST_DAY_OF_WEEK);
            $start = $period;
            $end = $period->addWeek();
            $previous = $period->subWeek();
            $next = $end;
        }

        // The bounds are local midnights; the column holds UTC.
        $byDay = Post::query()
            ->with(['variants.channel', 'variants.attachments.media'])
            ->inState(PostState::Draft, PostState::Approved, PostState::Scheduled, PostState::Publishing, PostState::Published, PostState::Failed)
            ->where('scheduled_at', '>=', $start->utc())
            ->where('scheduled_at', '<', $end->utc())
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Post $post): string => $this->localTime($post)->format(self::DATE_FORMAT));

        $days = [];

        for ($day = $start; $day->lt($end); $day = $day->addDay()) {
            $date = $day->format(self::DATE_FORMAT);

            $days[] = [
                'date' => $date,
                'in_period' => $view === 'week' || $day->isSameMonth($period),
                'today' => $day->isSameDay($today),
                'posts' => array_values(($byDay->get($date) ?? collect())->map($this->entry(...))->all()),
            ];
        }

        return Inertia::render('calendar/Index', [
            'view' => $view,
            'period' => $period->format(self::DATE_FORMAT),
            'previous' => $previous->format(self::DATE_FORMAT),
            'next' => $next->format(self::DATE_FORMAT),
            'today' => $today->format(self::DATE_FORMAT),
            'days' => $days,
            'can' => ['manage' => $this->mayChangePosts($request)],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(Post $post): array
    {
        $variants = $post->variants->sortBy('id')->values();
        $image = $variants->first()?->images()->first()?->media;

        return [
            'id' => $post->id,
            'state' => $post->state->value,
            'time' => $this->localTime($post)->format('H:i'),
            'channels' => $variants->map(fn (PostVariant $variant): string => $variant->channel->display_name ?? '')->filter()->values()->all(),
            'excerpt' => Str::limit(trim((string) $variants->first()?->body), self::EXCERPT_LENGTH),
            // The small copy of the first image of the first text, and how
            // many images that text has.
            'thumb_url' => $image !== null ? MediaPresenter::present($image)['thumb_url'] : null,
            'image_count' => $variants->first()?->attachments->count() ?? 0,
        ];
    }

    private function localTime(Post $post): CarbonImmutable
    {
        return $this->currentWorkspace->getOrFail()->toLocal($post->scheduled_at ?? now());
    }

    private function dateFromQuery(mixed $date, string $timezone): ?CarbonImmutable
    {
        if (! is_string($date) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1) {
            return null;
        }

        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || (int) $parts[1] < 2000 || (int) $parts[1] > 2100) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!'.self::DATE_FORMAT, $date, $timezone) ?: null;
    }
}
