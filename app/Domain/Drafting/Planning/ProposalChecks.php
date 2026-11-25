<?php

namespace App\Domain\Drafting\Planning;

use App\Domain\Channels\ChannelManager;
use App\Domain\Channels\Models\Channel;
use App\Domain\Drafting\CredentialScope;
use App\Domain\Drafting\DraftBudget;
use App\Domain\Drafting\DraftGenerators;
use App\Domain\Drafting\DraftingLimits;
use App\Domain\Drafting\DraftRunStatus;
use App\Domain\Drafting\Exceptions\DraftGeneratorNotAvailable;
use App\Domain\Drafting\Exceptions\DraftingException;
use App\Domain\Drafting\Exceptions\LlmCredentialMissing;
use App\Domain\Drafting\Exceptions\ProposalRefused;
use App\Domain\Drafting\Models\Brief;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Scheduling\Models\Slot;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\Log;

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
        private readonly DraftBudget $budget,
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
     * proposal that is still at work, the daily limit of the workspace,
     * or, when the key of the instance pays, the daily limit of that key.
     *
     * @param  int  $posts  how many posts the proposal would draft
     * @return list<ProposalRefused>
     */
    public function overLimits(Workspace $workspace, int $posts): array
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
        $used = $this->budget->usedByWorkspace();

        if ($posts > 0 && $used + $posts > $limit) {
            $refusals[] = ProposalRefused::because(
                ProposalRefused::DAILY_LIMIT,
                (string) __('This workspace can draft :limit posts in 24 hours and has used :used. This proposal needs :posts.', [
                    'limit' => $limit,
                    'used' => $used,
                    'posts' => $posts,
                ]).' '.self::howToGetMore('NOONGUN_DRAFT_MAX_RUNS_PER_DAY'),
            );
        }

        $instanceLimit = DraftingLimits::instanceRunsPerDay();

        if ($posts > 0 && $this->paidByInstance($workspace)) {
            $instanceUsed = $this->budget->usedOnInstanceKey();

            if ($instanceUsed + $posts > $instanceLimit) {
                $refusals[] = ProposalRefused::because(
                    ProposalRefused::INSTANCE_LIMIT,
                    (string) __('The API key of this instance is shared by all its workspaces and can draft :limit posts in 24 hours. :used are used and this proposal needs :posts.', [
                        'limit' => $instanceLimit,
                        'used' => $instanceUsed,
                        'posts' => $posts,
                    ]).' '.self::howToGetMore('NOONGUN_DRAFT_MAX_INSTANCE_RUNS_PER_DAY')
                    .' '.__('A workspace with an API key of its own is not held by this limit.'),
                );
            }
        }

        return $refusals;
    }

    /**
     * Whether the key of the instance would pay for a draft of the
     * workspace right now. False when no key resolves at all: that is
     * refused elsewhere.
     */
    private function paidByInstance(Workspace $workspace): bool
    {
        try {
            return $this->generators->contextFor($workspace)->key->scope === CredentialScope::Instance;
        } catch (DraftingException|DraftGeneratorNotAvailable) {
            return false;
        }
    }

    /**
     * What a person can do about a limit. On a self-hosted instance the
     * reader runs the instance, so the setting is named; a tenant can only
     * wait.
     */
    private static function howToGetMore(string $setting): string
    {
        return config('noongun.mode') === 'self_hosted'
            ? (string) __('Try again later, or raise :setting in the environment of this instance.', ['setting' => $setting])
            : (string) __('Try again later.');
    }

    private function whyNotAvailable(Workspace $workspace): ProposalRefused
    {
        try {
            $this->generators->for($this->generators->contextFor($workspace));
        } catch (LlmCredentialMissing $missing) {
            if ($missing->operatorDetail() !== null) {
                Log::warning('No API key resolves for drafting in a workspace.', [
                    'workspace_id' => $workspace->id,
                    'operator_detail' => $missing->operatorDetail(),
                ]);
            }

            return ProposalRefused::because(ProposalRefused::NO_KEY, $missing->getMessage());
        } catch (DraftGeneratorNotAvailable $unavailable) {
            // The message names classes, configuration paths and
            // environment variables, and holds no secret. It goes to the
            // log; members read a fixed sentence.
            Log::warning('Drafting is not set up, so a week cannot be proposed.', [
                'workspace_id' => $workspace->id,
                'detail' => $unavailable->getMessage(),
            ]);

            return ProposalRefused::because(ProposalRefused::NOT_CONFIGURED, (string) __(DraftGeneratorNotAvailable::FOR_MEMBERS));
        }

        return ProposalRefused::because(ProposalRefused::NOT_CONFIGURED, (string) __('Drafting is not available right now.'));
    }
}
