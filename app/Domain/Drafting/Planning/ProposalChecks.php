<?php

namespace App\Domain\Drafting\Planning;

use App\Domain\Channels\ChannelManager;
use App\Domain\Channels\Models\Channel;
use App\Domain\Drafting\DraftGenerators;
use App\Domain\Drafting\DraftingLimits;
use App\Domain\Drafting\DraftRunStatus;
use App\Domain\Drafting\Exceptions\DraftGeneratorNotAvailable;
use App\Domain\Drafting\Exceptions\LlmCredentialMissing;
use App\Domain\Drafting\Exceptions\ProposalRefused;
use App\Domain\Drafting\Models\Brief;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Scheduling\Models\Slot;
use App\Domain\Workspaces\Models\Workspace;

/**
 * Everything that can stand in the way of proposing a week, each as a
 * ProposalRefused with a sentence for the person. The page that shows the
 * plan lists them; ProposeWeek throws the first one. Nothing here creates
 * a record or calls a provider.
 */
final class ProposalChecks
{
    public function __construct(
        private readonly DraftGenerators $generators,
        private readonly ChannelManager $channels,
    ) {}

    /**
     * What has to be set up before any week can be proposed: a brief, a
     * channel that can publish with a slot, and a working generator.
     *
     * @return list<ProposalRefused>
     */
    public function missingSetup(Workspace $workspace): array
    {
        $missing = [];

        if (Brief::current() === null) {
            $missing[] = ProposalRefused::because(
                ProposalRefused::BRIEF_MISSING,
                (string) __('There is no brief yet. Every draft is written from the brief, so write it first.'),
            );
        }

        $publishing = Channel::query()->orderBy('id')->get()
            ->filter(fn (Channel $channel): bool => $channel->canPublish() && $this->channels->has($channel->platform));

        if ($publishing->isEmpty()) {
            $missing[] = ProposalRefused::because(
                ProposalRefused::NO_CHANNEL,
                (string) __('No channel is connected that can publish. Connect a channel, or reconnect the one that needs it.'),
            );
        } elseif (! Slot::query()->whereIn('channel_id', $publishing->modelKeys())->exists()) {
            $missing[] = ProposalRefused::because(
                ProposalRefused::NO_SLOT,
                (string) __('No slot is set for a connected channel. Add the weekdays and times you want to post at.'),
            );
        }

        if (! $this->generators->availableFor($workspace)) {
            $missing[] = $this->whyNotAvailable($workspace);
        }

        return $missing;
    }

    /**
     * What stands in the way right now although the setup is complete: a
     * proposal that is still at work, or the daily limit.
     *
     * @param  int  $posts  how many posts the proposal would draft
     * @return list<ProposalRefused>
     */
    public function overLimits(int $posts): array
    {
        $refusals = [];

        $open = DraftRun::query()->inStatus(DraftRunStatus::Pending, DraftRunStatus::Running)->count();

        if ($open > 0) {
            $refusals[] = ProposalRefused::because(
                ProposalRefused::IN_PROGRESS,
                (string) trans_choice(
                    '{1} A draft is still being generated. Wait until it is done before you propose another week.|[2,*] :count drafts are still being generated. Wait until they are done before you propose another week.',
                    $open,
                    ['count' => $open],
                ),
            );
        }

        $limit = DraftingLimits::runsPerDay();
        $used = $this->runsInLastDay();

        if ($posts > 0 && $used + $posts > $limit) {
            $refusals[] = ProposalRefused::because(
                ProposalRefused::DAILY_LIMIT,
                (string) __('This workspace may generate :limit drafts in 24 hours and has generated :used. This proposal needs :posts. Try again later, or ask the operator of this instance to raise the limit.', [
                    'limit' => $limit,
                    'used' => $used,
                    'posts' => $posts,
                ]),
            );
        }

        return $refusals;
    }

    /**
     * How many draft runs the workspace opened in the last 24 hours,
     * whatever became of them: a failed run may have been paid for.
     */
    public function runsInLastDay(): int
    {
        return DraftRun::query()->where('created_at', '>', now()->subDay())->count();
    }

    private function whyNotAvailable(Workspace $workspace): ProposalRefused
    {
        try {
            $this->generators->for($this->generators->contextFor($workspace));
        } catch (LlmCredentialMissing $missing) {
            return ProposalRefused::because(ProposalRefused::NO_KEY, $missing->getMessage());
        } catch (DraftGeneratorNotAvailable $unavailable) {
            return ProposalRefused::because(
                ProposalRefused::NOT_CONFIGURED,
                (string) __('Drafting is not set up on this instance. For the operator: :detail', ['detail' => $unavailable->getMessage()]),
            );
        }

        return ProposalRefused::because(ProposalRefused::NOT_CONFIGURED, (string) __('Drafting is not available right now.'));
    }
}
