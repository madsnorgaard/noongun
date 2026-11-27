<?php

namespace App\Domain\Drafting\Actions;

use App\Domain\Channels\Models\Channel;
use App\Domain\Content\ApprovedTexts;
use App\Domain\Content\Models\Pillar;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Drafting\DraftingConfig;
use App\Domain\Drafting\ExamplePost;
use App\Domain\Drafting\ExampleSelection;

/**
 * Chooses the examples a draft request carries: texts the current
 * workspace approved for the channels the draft is for, some because they
 * did well with readers and the rest because they are the most recent.
 *
 * - What counts. The text of a variant whose post carries a human approval
 *   now (Content\ApprovedTexts): on its way to a platform, on it, or failed
 *   on the way. A failed post and one whose outcome is unknown count,
 *   because a person signed off their text. A draft, a rejected post and a
 *   post that was returned to draft do not: none of them carries an
 *   approval.
 * - Which. Each target channel gets noongun.drafting.examples.per_channel
 *   texts of that channel. A share of them
 *   (noongun.drafting.examples.top_share, rounded down, never all) goes
 *   to the texts that did best in the last
 *   noongun.drafting.examples.top_days days: an engagement score above
 *   the average of the channel in those days, best first, at most one for
 *   each pillar. The rest, and every place no such text was found for,
 *   goes to the most recent texts, newest approval first. A channel with
 *   fewer is topped up with the most recent texts of other channels of
 *   the same platform, and then it stops. A text is in a request once, a
 *   post gives one channel one text, and a top-up that repeats a text
 *   already chosen word for word is left out.
 * - Not measured is not zero. A text without numbers competes only for
 *   the places of the most recent. When no text of a channel has numbers,
 *   the choice is the most recent texts and nothing else.
 * - Never from elsewhere for how it did. A score compares posts of one
 *   channel, so a text of another channel is never taken because it did
 *   well there.
 * - How much. The examples of one target channel together hold at most
 *   noongun.drafting.examples.max_characters characters
 *   (DraftingConfig::exampleCharactersFor()), so a request for two
 *   channels has room for both. Over that, the texts of that channel
 *   chosen for how they did are left out first, lowest score first, and
 *   then the text with the oldest approval, then the next oldest. A text
 *   is never cut. A text that was chosen for how it did and left out is
 *   counted, so that a run can say so.
 * - The same state gives the same choice in the same order: every order
 *   ends on the id of the text.
 *
 * Everything is read through the workspace scope, so a text of another
 * workspace cannot be chosen. It creates and changes nothing.
 */
final class SelectExamplePosts
{
    /** The most texts of one pillar a channel gets for how they did. */
    public const TOP_PER_PILLAR = 1;

    public function __construct(private readonly ApprovedTexts $approvedTexts) {}

    /**
     * @param  iterable<Channel>  $channels  the target channels of the request
     */
    public function handle(iterable $channels): ExampleSelection
    {
        $perChannel = DraftingConfig::examplesPerChannel();
        $topSlots = DraftingConfig::exampleTopSlots();
        $topDays = DraftingConfig::exampleTopDays();
        /** @var array<int, string> $targets */
        $targets = [];

        foreach ($channels as $channel) {
            $targets[$channel->id] = $channel->platform;
        }

        $budget = DraftingConfig::exampleCharactersFor(count($targets));

        if ($perChannel === 0 || $budget === 0 || $targets === []) {
            return ExampleSelection::none();
        }

        /** @var array<int, list<PostVariant>> $chosen target channel id => variants */
        $chosen = array_fill_keys(array_keys($targets), []);
        /** @var array<int, true> $taken variant id => true */
        $taken = [];
        /** @var array<string, true> $texts */
        $texts = [];
        /** @var array<int, float> $scores variant id => score, of the texts chosen for how they did */
        $scores = [];

        foreach (array_keys($targets) as $channelId) {
            foreach ($this->approvedTexts->best($channelId, $topDays, $topSlots, self::TOP_PER_PILLAR) as $variant) {
                $chosen[$channelId][] = $variant;
                $taken[$variant->id] = true;
                $texts[$variant->body] = true;
                $scores[$variant->id] = self::score($variant);
            }

            foreach ($this->approvedTexts->latest([$channelId], $perChannel - count($chosen[$channelId]), array_keys($taken)) as $variant) {
                $chosen[$channelId][] = $variant;
                $taken[$variant->id] = true;
                $texts[$variant->body] = true;
            }
        }

        foreach ($targets as $channelId => $platform) {
            $missing = $perChannel - count($chosen[$channelId]);

            if ($missing <= 0) {
                continue;
            }

            $others = Channel::query()->where('platform', $platform)->whereKeyNot($channelId)->pluck('id')->all();

            if ($others === []) {
                continue;
            }

            $posts = array_fill_keys(array_map(fn (PostVariant $variant): int => $variant->post_id, $chosen[$channelId]), true);

            // More than are missing, because a text that repeats one
            // already chosen is passed over.
            $candidates = $this->approvedTexts->latest(
                array_values(array_map(intval(...), $others)),
                $missing + count($taken) + count($others),
                array_keys($taken),
                array_keys($posts),
            );

            foreach ($candidates as $variant) {
                if ($missing === 0) {
                    break;
                }

                if (isset($posts[$variant->post_id]) || isset($texts[$variant->body])) {
                    continue;
                }

                $chosen[$channelId][] = $variant;
                $taken[$variant->id] = true;
                $texts[$variant->body] = true;
                $posts[$variant->post_id] = true;
                $missing--;
            }
        }

        $kept = [];

        foreach ($chosen as $variants) {
            $kept += self::withinBudget($variants, $scores, $budget);
        }

        $leftOut = count(array_diff_key($scores, $kept));

        if ($kept === []) {
            return new ExampleSelection(performanceLeftOut: $leftOut);
        }

        $pillars = Pillar::query()
            ->whereKey(array_values(array_unique(array_filter(array_map(self::pillarId(...), $kept)))))
            ->pluck('name', 'id');

        $examples = [];
        $postIds = [];
        $performancePostIds = [];

        foreach ($chosen as $variants) {
            foreach ($variants as $variant) {
                if (! isset($kept[$variant->id])) {
                    continue;
                }

                $pillar = $pillars->get(self::pillarId($variant) ?? 0);

                $examples[] = new ExamplePost(
                    platform: $targets[$variant->channel_id] ?? self::platformOf($variant, $chosen, $targets),
                    body: $variant->body,
                    // Only a channel the request names anyway. Any other id
                    // would mean nothing to the model.
                    channelId: isset($targets[$variant->channel_id]) ? $variant->channel_id : null,
                    pillar: is_string($pillar) ? $pillar : null,
                    performedWell: isset($scores[$variant->id]),
                );
                $postIds[$variant->post_id] = $variant->post_id;

                if (isset($scores[$variant->id])) {
                    $performancePostIds[$variant->post_id] = $variant->post_id;
                }
            }
        }

        sort($postIds);
        sort($performancePostIds);

        return new ExampleSelection($examples, $postIds, $performancePostIds, $leftOut);
    }

    /**
     * Leaves out texts of one target channel until the rest fits: first
     * those chosen for how they did, lowest score first, then those with
     * the oldest approval. What a tight limit takes away first is the
     * newer half of the idea, and what stays is the choice as it was
     * before posts were measured.
     *
     * @param  list<PostVariant>  $variants  the texts chosen for one target channel
     * @param  array<int, float>  $scores  variant id => score, of the texts chosen for how they did
     * @return array<int, PostVariant> keyed by variant id
     */
    private static function withinBudget(array $variants, array $scores, int $budget): array
    {
        $kept = [];

        foreach ($variants as $variant) {
            $kept[$variant->id] = $variant;
        }

        // Chosen for how it did before chosen for being recent, the lower
        // score first; then the oldest approval; then the lower id.
        $order = fn (PostVariant $variant): array => [
            isset($scores[$variant->id]) ? 0 : 1,
            $scores[$variant->id] ?? 0.0,
            self::approvedAt($variant),
            $variant->id,
        ];

        uasort($kept, fn (PostVariant $a, PostVariant $b): int => $order($a) <=> $order($b));

        $total = array_sum(array_map(fn (PostVariant $variant): int => mb_strlen($variant->body), $kept));

        foreach ($kept as $id => $variant) {
            if ($total <= $budget) {
                break;
            }

            $total -= mb_strlen($variant->body);
            unset($kept[$id]);
        }

        return $kept;
    }

    /**
     * The platform of a text that was taken from a channel that is not a
     * target: that of the target it tops up.
     *
     * @param  array<int, list<PostVariant>>  $chosen
     * @param  array<int, string>  $targets
     */
    private static function platformOf(PostVariant $variant, array $chosen, array $targets): string
    {
        foreach ($chosen as $channelId => $variants) {
            if (in_array($variant, $variants, true)) {
                return $targets[$channelId];
            }
        }

        return '';
    }

    private static function approvedAt(PostVariant $variant): string
    {
        $at = $variant->getAttribute('post_approved_at');

        return is_string($at) ? $at : '';
    }

    private static function score(PostVariant $variant): float
    {
        $score = $variant->getAttribute('example_score');

        return is_numeric($score) ? (float) $score : 0.0;
    }

    private static function pillarId(PostVariant $variant): ?int
    {
        $id = $variant->getAttribute('post_pillar_id');

        return is_numeric($id) ? (int) $id : null;
    }
}
