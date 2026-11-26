<?php

namespace App\Domain\Drafting\Actions;

use App\Domain\Content\Models\Idea;
use App\Domain\Content\Models\Pillar;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Drafting\PlannedAlongside;
use App\Domain\Scheduling\PlannedTimes;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Lists the other posts of the week a draft is planned for, in a few words
 * each, so that the model does not write the same post twice. It reads and
 * creates nothing.
 *
 * - The week is the one, on the workspace calendar, that holds the planned
 *   time. A draft without a planned time belongs to no week and gets no
 *   list.
 * - First the posts that already have that week as their time and are not
 *   rejected, in time order: the pillar, the title of the idea and the
 *   first line of the text, as far as each exists.
 * - Then the posts that are planned alongside and not written yet, as the
 *   caller names them: the pillar and the title of the idea.
 * - At most PlannedAlongside::MAX_ENTRIES entries, each part cut to
 *   PlannedAlongside::TEXT_LENGTH characters. Never a whole text.
 */
final class ListPlannedAlongside
{
    public function __construct(private readonly PlannedTimes $plannedTimes) {}

    /**
     * @param  DateTimeInterface|null  $plannedAt  the planned time of the draft, an instant
     * @param  list<array{pillar: Pillar|null, idea: Idea|null}>  $notWrittenYet  what else is being drafted for the week
     * @return list<PlannedAlongside>
     */
    public function handle(Workspace $workspace, ?DateTimeInterface $plannedAt, array $notWrittenYet = []): array
    {
        if ($plannedAt === null) {
            return [];
        }

        $monday = $workspace->toLocal(CarbonImmutable::instance($plannedAt))
            ->startOfWeek(CarbonInterface::MONDAY)
            ->startOfDay();

        $entries = [];

        foreach ($this->plannedTimes->postsBetween($monday->utc(), $monday->addWeek()->utc(), PlannedAlongside::MAX_ENTRIES) as $post) {
            /** @var PostVariant|null $first */
            $first = $post->variants->sortBy('id')->first();

            $entries[] = new PlannedAlongside($post->pillar?->name, $post->idea?->title, $first?->body);
        }

        foreach ($notWrittenYet as $planned) {
            $entries[] = new PlannedAlongside($planned['pillar']?->name, $planned['idea']?->title);
        }

        return array_slice(
            array_values(array_filter($entries, fn (PlannedAlongside $entry): bool => ! $entry->isEmpty())),
            0,
            PlannedAlongside::MAX_ENTRIES,
        );
    }
}
