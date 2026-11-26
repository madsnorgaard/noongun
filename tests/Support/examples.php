<?php

use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Pillar;
use App\Domain\Content\Models\Post;
use App\Domain\Content\PostState;
use App\Domain\Drafting\Actions\SelectExamplePosts;
use App\Domain\Drafting\ExamplePost;
use App\Domain\Drafting\ExampleSelection;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Helpers for the tests of approved posts as examples. Loaded by those test
 * files with require_once, after publishing.php.
 */

/**
 * A post in the given state with one text for each channel, approved at
 * the given time when the state carries an approval.
 *
 * @param  list<array{Channel, string}>  $texts
 */
function postWithTexts(Workspace $workspace, array $texts, string $approvedAt, PostState $state = PostState::Approved, ?Pillar $pillar = null): Post
{
    $post = postInState($workspace, $state, array_map(fn (array $text): Channel => $text[0], $texts));

    foreach ($texts as [$channel, $body]) {
        DB::table('post_variants')->where('post_id', $post->id)->where('channel_id', $channel->id)->update(['body' => $body]);
    }

    $values = array_filter([
        'approved_at' => $state->requiresApproval() ? $approvedAt : null,
        'pillar_id' => $pillar?->id,
    ]);

    if ($values !== []) {
        DB::table('posts')->where('id', $post->id)->update($values);
    }

    return $post;
}

/**
 * One approved text on one channel.
 */
function approvedText(Workspace $workspace, Channel $channel, string $body, string $approvedAt, PostState $state = PostState::Approved, ?Pillar $pillar = null): Post
{
    return postWithTexts($workspace, [[$channel, $body]], $approvedAt, $state, $pillar);
}

/**
 * @param  list<Channel>  $channels
 */
function examplesFor(Workspace $workspace, array $channels): ExampleSelection
{
    return inWorkspace($workspace, fn () => app(SelectExamplePosts::class)->handle($channels));
}

/**
 * @return list<string>
 */
function exampleTexts(ExampleSelection $selection): array
{
    return array_map(fn (ExamplePost $example): string => $example->body, $selection->examples);
}

/**
 * A pending run for one post on the page of the test ($this->page), asked
 * for by its owner and planned for Monday 30 November 2026.
 *
 * @param  array<string, mixed>  $note
 */
function runForThePage(array $note = []): DraftRun
{
    $test = test();

    return draftRunIn($test->workspace, $test->owner, request: [
        'proposal' => 'proposal-22',
        'planned_at' => '2026-11-30T08:00:00+00:00',
        'channel_ids' => [$test->page->id],
        'attempt' => 1,
        ...$note,
    ]);
}
