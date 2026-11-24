<?php

/*
 * The acceptance criterion of M3 (issue #3, brief section 12), through the
 * same routes the pages use: a manually written post, approved in the UI,
 * goes out at its scheduled minute and shows its permalink.
 */

use App\Domain\Content\Models\Post;
use App\Domain\Content\PostState;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';

beforeEach(function () {
    $this->travelTo('2026-11-24 10:00:00');
    Http::preventStrayRequests();

    $this->fake = fakeChannelAdapter();
});

it('publishes a hand-written post at its scheduled minute once a human approved it, and shows its permalink', function (string $timezone, string $justBefore, string $atTheMinute) {
    $workspace = Workspace::factory()->create(['timezone' => $timezone]);
    $editor = userWithRole($workspace, Role::Editor);
    $channel = channelIn($workspace, ['display_name' => 'Harbour Bakery']);

    // Written by hand, planned for nine the next morning on the workspace clock.
    $this->actingAs($editor)->post(route('posts.store'), [
        'scheduled_at' => '2026-11-25T09:00',
        'variants' => [
            ['channel_id' => $channel->id, 'body' => 'We open at eight tomorrow.', 'link' => 'https://example.com/hours'],
        ],
    ])->assertRedirect();

    $post = Post::query()->withoutGlobalScopes()->sole();
    $variant = variantsOf($post)[0];

    $this->get(route('posts.show', $post->id))->assertInertia(fn (Assert $page) => $page
        ->where('post.state', 'draft')
        ->where('post.problems', [])
        ->where('post.scheduled_local', '2026-11-25T09:00')
        ->where('post.version', postVersion($post))
        ->where('post.can.approve', true));

    // It is in the queue as a draft that waits for approval.
    $this->get(route('queue.index'))->assertInertia(fn (Assert $page) => $page
        ->where('sections.drafts.0.id', $post->id)
        ->has('sections.upcoming', 0));

    // Approved with one click. The planned time makes it scheduled.
    $this->post(route('posts.approve', $post->id), ['version' => postVersion($post)])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'The post is approved and scheduled.']);

    expect(freshPost($post)->state)->toBe(PostState::Scheduled)
        ->and(freshPost($post)->approved_by)->toBe($editor->id);

    $this->fake->assertNothingPublished();

    // One second early: the scheduler leaves it alone.
    $this->travelTo($justBefore);
    $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();

    $this->fake->assertNothingPublished();
    expect(stateOf($post))->toBe('scheduled');

    // At its minute it is dispatched, and the job publishes it. Once.
    $this->travelTo($atTheMinute);
    $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();
    $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();

    $this->fake->assertPublishedOnce($variant);
    expect(stateOf($post))->toBe('published');

    $permalink = "https://platform.test/facebook/posts/facebook_{$variant->id}_1";

    // The editor shows the permalink.
    $this->get(route('posts.show', $post->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('posts/Edit')
            ->where('post.state', 'published')
            ->where('post.approval.by', $editor->name)
            ->where('post.variants.0.published', true)
            ->where('post.variants.0.permalink', $permalink)
            ->where('post.variants.0.published_at', str_replace(' ', 'T', $atTheMinute).'+00:00')
            ->where('post.variants.0.error', null)
            ->where('post.can', fn ($can) => collect($can)->every(fn ($allowed) => $allowed === false)));

    // So do the history and the calendar day it went out on.
    $this->get(route('queue.index', ['view' => 'history']))->assertInertia(fn (Assert $page) => $page
        ->where('history.posts.0.id', $post->id)
        ->where('history.posts.0.variants.0.permalink', $permalink));

    $this->get(route('calendar.index', ['date' => '2026-11-25']))->assertInertia(fn (Assert $page) => $page
        ->where('days.2.date', '2026-11-25')
        ->where('days.2.posts.0.id', $post->id)
        ->where('days.2.posts.0.time', '09:00')
        ->where('days.2.posts.0.state', 'published'));

    Http::assertNothingSent();
})->with([
    'Copenhagen' => ['Europe/Copenhagen', '2026-11-25 07:59:59', '2026-11-25 08:00:00'],
    'Johannesburg' => ['Africa/Johannesburg', '2026-11-25 06:59:59', '2026-11-25 07:00:00'],
]);

it('sends nothing when the same post is written and planned but never approved', function () {
    $workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $channel = channelIn($workspace);

    $this->actingAs(userWithRole($workspace, Role::Owner))->post(route('posts.store'), [
        'scheduled_at' => '2026-11-25T09:00',
        'variants' => [['channel_id' => $channel->id, 'body' => 'We open at eight tomorrow.', 'link' => null]],
    ])->assertRedirect();

    $post = Post::query()->withoutGlobalScopes()->sole();

    $this->get(route('posts.show', $post->id))->assertOk();
    $this->get(route('queue.index'))->assertOk();

    $this->travelTo('2026-11-25 08:00:00');
    $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();
    $this->travel(2)->hours();
    $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();
    $this->artisan('noongun:recover-stuck-posts')->assertSuccessful();

    $this->fake->assertNothingPublished();
    expect($this->fake->calls)->toBe([])
        ->and(stateOf($post))->toBe('draft')
        ->and(freshPost($post)->approved_at)->toBeNull();
});
