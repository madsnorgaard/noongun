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
 * Chooses the examples a draft request carries: the texts the current
 * workspace approved most recently, for the channels the draft is for.
 *
 * - What counts. The text of a variant whose post carries a human approval
 *   now (Content\ApprovedTexts): on its way to a platform, on it, or failed
 *   on the way. A failed post and one whose outcome is unknown count,
 *   because a person signed off their text. A draft, a rejected post and a
 *   post that was returned to draft do not: none of them carries an
 *   approval.
 * - Which. For each target channel the last
 *   noongun.drafting.examples.per_channel texts of that channel, newest
 *   approval first. A channel with fewer is topped up with texts of other
 *   channels of the same platform, and then it stops. A text is in a
 *   request once, a post gives one channel one text, and a top-up that
 *   repeats a text already chosen word for word is left out.
 * - How much. All examples of a request together hold at most
 *   noongun.drafting.examples.max_characters characters. Over that, the
 *   text with the oldest approval is left out, then the next oldest. A
 *   text is never cut.
 *
 * Everything is read through the workspace scope, so a text of another
 * workspace cannot be chosen. It creates and changes nothing.
 */
final class SelectExamplePosts
{
    public function __construct(private readonly ApprovedTexts $approvedTexts) {}

    /**
     * @param  iterable<Channel>  $channels  the target channels of the request
     */
    public function handle(iterable $channels): ExampleSelection
    {
        $perChannel = DraftingConfig::examplesPerChannel();
        $budget = DraftingConfig::exampleCharacters();

        /** @var array<int, string> $targets */
        $targets = [];

        foreach ($channels as $channel) {
            $targets[$channel->id] = $channel->platform;
        }

        if ($perChannel === 0 || $budget === 0 || $targets === []) {
            return ExampleSelection::none();
        }

        /** @var array<int, list<PostVariant>> $chosen target channel id => variants */
        $chosen = array_fill_keys(array_keys($targets), []);
        /** @var array<int, true> $taken variant id => true */
        $taken = [];
        /** @var array<string, true> $texts */
        $texts = [];

        foreach (array_keys($targets) as $channelId) {
            foreach ($this->approvedTexts->latest([$channelId], $perChannel) as $variant) {
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

        $kept = self::withinBudget($chosen, $budget);

        if ($kept === []) {
            return ExampleSelection::none();
        }

        $pillars = Pillar::query()
            ->whereKey(array_values(array_unique(array_filter(array_map(self::pillarId(...), $kept)))))
            ->pluck('name', 'id');

        $examples = [];
        $postIds = [];

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
                );
                $postIds[$variant->post_id] = $variant->post_id;
            }
        }

        sort($postIds);

        return new ExampleSelection($examples, $postIds);
    }

    /**
     * Leaves out the texts with the oldest approval until the rest fits.
     *
     * @param  array<int, list<PostVariant>>  $chosen
     * @return array<int, PostVariant> keyed by variant id
     */
    private static function withinBudget(array $chosen, int $budget): array
    {
        $kept = [];

        foreach ($chosen as $variants) {
            foreach ($variants as $variant) {
                $kept[$variant->id] = $variant;
            }
        }

        // Oldest approval first; of two texts of one post, the lower id.
        uasort($kept, fn (PostVariant $a, PostVariant $b): int => [self::approvedAt($a), $a->id] <=> [self::approvedAt($b), $b->id]);

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

    private static function pillarId(PostVariant $variant): ?int
    {
        $id = $variant->getAttribute('post_pillar_id');

        return is_numeric($id) ? (int) $id : null;
    }
}
