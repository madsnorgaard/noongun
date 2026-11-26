<?php

use App\Domain\Channels\ChannelManager;
use App\Domain\Channels\Contracts\MetricReading;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Pillar;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostState;
use App\Domain\Insights\FetchMetricsJob;
use App\Domain\Insights\Models\MetricSnapshot;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeMetricsAdapter;

/**
 * Helpers for the tests of metrics: polling, snapshots and the performance
 * queries. Loaded by those test files with require_once, after
 * publishing.php and posts.php.
 */

/**
 * Replaces the adapter for a platform with a FakeMetricsAdapter and returns
 * it. The default platform matches Channel::factory().
 */
function fakeMetricsAdapter(string $platform = 'facebook'): FakeMetricsAdapter
{
    $fake = new FakeMetricsAdapter($platform);

    app(ChannelManager::class)->extend($platform, fn () => $fake);

    return $fake;
}

/**
 * A published post with one variant on the channel (a new active channel
 * when none is given), published at the given time (now when none is
 * given), and returns that variant.
 */
function publishedVariant(Workspace $workspace, ?Channel $channel = null, ?DateTimeInterface $publishedAt = null, ?Pillar $pillar = null, ?string $body = null): PostVariant
{
    $channel ??= channelIn($workspace);
    $post = postInState($workspace, PostState::Published, [$channel]);

    DB::table('post_variants')->where('post_id', $post->id)->update(array_filter([
        'published_at' => $publishedAt ?? now(),
        'body' => $body,
    ]));

    if ($pillar !== null) {
        DB::table('posts')->where('id', $post->id)->update(['pillar_id' => $pillar->id]);
    }

    return variantsOf($post)[0];
}

/**
 * One more published variant on the same post, for a post that is on
 * several channels.
 */
function alsoPublishedOn(Workspace $workspace, PostVariant $variant, Channel $channel, ?DateTimeInterface $publishedAt = null): PostVariant
{
    $id = DB::table('post_variants')->insertGetId([
        'workspace_id' => $workspace->id,
        'post_id' => $variant->post_id,
        'channel_id' => $channel->id,
        'body' => $variant->body,
        'external_id' => 'ext_also_'.$channel->id.'_'.$variant->post_id,
        'permalink' => 'https://platform.test/posts/also',
        'published_at' => $publishedAt ?? $variant->published_at,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return PostVariant::query()->withoutGlobalScopes()->findOrFail($id);
}

/**
 * Stores numbers for a variant the way the job does, as read at the given
 * time (now when none is given). The array is keyed by metric name; a
 * metric that is left out is null. Call it again with other numbers to
 * build a history.
 *
 * @param  array{reactions?: int|null, comments?: int|null, shares?: int|null, clicks?: int|null, reach?: int|null}  $numbers
 */
function snapshotFor(PostVariant $variant, array $numbers, ?DateTimeInterface $at = null): MetricSnapshot
{
    $workspace = Workspace::query()->findOrFail($variant->workspace_id);

    return inWorkspace($workspace, fn () => MetricSnapshot::record(
        $variant,
        new MetricReading(...$numbers),
        $at,
    ) ?? throw new LogicException('snapshotFor() needs at least one number.'));
}

/**
 * Runs the job for a channel the way a worker does, without the queue.
 */
function runFetchMetrics(Channel $channel): void
{
    app()->call([new FetchMetricsJob($channel->workspace_id, $channel->id), 'handle']);
}

/**
 * Every snapshot of a variant, oldest first, whatever the current workspace
 * is.
 *
 * @return list<MetricSnapshot>
 */
function snapshotsOf(PostVariant $variant): array
{
    return array_values(MetricSnapshot::query()
        ->withoutGlobalScopes()
        ->where('post_variant_id', $variant->id)
        ->orderBy('captured_at')
        ->orderBy('id')
        ->get()
        ->all());
}

/**
 * When the platform was last asked about a variant, read without a
 * workspace.
 */
function metricsCheckedAt(PostVariant $variant): ?string
{
    $at = DB::table('post_variants')->where('id', $variant->id)->value('metrics_checked_at');

    return is_string($at) ? $at : null;
}

/**
 * Gives a published variant the id a platform would have given its post,
 * and returns the variant as it is now stored.
 */
function publishedAs(PostVariant $variant, string $externalId): PostVariant
{
    DB::table('post_variants')->where('id', $variant->id)->update(['external_id' => $externalId]);

    return PostVariant::query()->withoutGlobalScopes()->findOrFail($variant->id);
}

/**
 * How many times in a row the platform said the post of a variant is gone,
 * read without a workspace.
 */
function goneCount(PostVariant $variant): int
{
    return (int) DB::table('post_variants')->where('id', $variant->id)->value('metrics_gone_count');
}
