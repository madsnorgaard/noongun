<?php

/*
 * M5 (issue #5): one post with a text for each channel, edited side by
 * side, approved once and published once on each channel (ADR 0014).
 *
 * Everything goes through the routes the pages use. The second platform
 * is a fake registered as "linkedin" with a limit of 300 characters and
 * no link previews.
 */

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Contracts\ChannelCapabilities;
use App\Domain\Channels\Exceptions\PublishFailed;
use App\Domain\Content\Models\Post;
use App\Domain\Content\PostState;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';

const LINKEDIN_LIMIT = 300;

beforeEach(function () {
    // A Tuesday.
    $this->travelTo('2026-11-24 10:00:00');
    Http::preventStrayRequests();

    $this->facebook = fakeChannelAdapter();
    $this->linkedin = fakeChannelAdapter('linkedin')->withCapabilities(new ChannelCapabilities(maxBodyLength: LINKEDIN_LIMIT));

    $this->workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->editor = userWithRole($this->workspace, Role::Editor);
    $this->page = channelIn($this->workspace, ['display_name' => 'Table Bay Coffee']);
    $this->profile = channelIn($this->workspace, ['platform' => 'linkedin', 'display_name' => 'Thandi Mokoena']);
});

/**
 * Writes a post by hand for both channels and returns it.
 *
 * @param  array<string, mixed>  $overrides
 */
function writeForBoth(object $test, array $overrides = []): Post
{
    $test->actingAs($test->editor)->post(route('posts.store'), [
        'scheduled_at' => '2026-11-25T09:00',
        'variants' => [
            ['channel_id' => $test->page->id, 'body' => 'We open at eight tomorrow.', 'link' => 'https://example.com/hours'],
            ['channel_id' => $test->profile->id, 'body' => 'We open at eight tomorrow.', 'link' => 'https://example.com/hours'],
        ],
        ...$overrides,
    ])->assertSessionHasNoErrors()->assertRedirect();

    return Post::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
}

/**
 * The stored rows of a post's texts, keyed by channel id.
 *
 * @return array<int, object>
 */
function textsOf(Post $post): array
{
    return DB::table('post_variants')->where('post_id', $post->id)->get()->keyBy('channel_id')->all();
}

describe('the editor', function () {
    it('offers each channel with the limit and the link handling of its own platform', function () {
        $this->actingAs($this->editor)->get(route('posts.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('channels', 2)
                ->where('channels.0.display_name', 'Table Bay Coffee')
                ->where('channels.0.platform_label', 'Facebook')
                ->where('channels.0.max_body_length', 5000)
                ->where('channels.0.link_previews', true)
                ->where('channels.1.display_name', 'Thandi Mokoena')
                ->where('channels.1.platform_label', 'LinkedIn')
                ->where('channels.1.max_body_length', LINKEDIN_LIMIT)
                ->where('channels.1.link_previews', false));
    });

    it('saves one post with a text for each channel when the same text is written for both', function () {
        $post = writeForBoth($this);
        $texts = textsOf($post);

        expect(DB::table('posts')->count())->toBe(1)
            ->and($texts)->toHaveCount(2)
            ->and($texts[$this->page->id]->body)->toBe('We open at eight tomorrow.')
            ->and($texts[$this->profile->id]->body)->toBe('We open at eight tomorrow.');

        $this->get(route('posts.show', $post->id))->assertInertia(fn (Assert $page) => $page
            ->where('post.state', 'draft')
            ->where('post.problems', [])
            ->has('post.variants', 2)
            ->where('post.variants.0.channel_name', 'Table Bay Coffee')
            ->where('post.variants.0.platform_label', 'Facebook')
            ->where('post.variants.0.max_body_length', 5000)
            ->where('post.variants.1.channel_name', 'Thandi Mokoena')
            ->where('post.variants.1.platform_label', 'LinkedIn')
            ->where('post.variants.1.max_body_length', LINKEDIN_LIMIT));
    });

    it('changes the text of one channel and leaves the other exactly as it was', function () {
        $post = writeForBoth($this);
        $before = textsOf($post);

        $this->travel(5)->minutes();

        $this->put(route('posts.update', $post->id), [
            'scheduled_at' => '2026-11-25T09:00',
            'variants' => [
                ['channel_id' => $this->page->id, 'body' => 'We open at eight tomorrow.', 'link' => 'https://example.com/hours'],
                ['channel_id' => $this->profile->id, 'body' => 'From tomorrow our doors open at eight. Come by before work.', 'link' => null],
            ],
        ])->assertSessionHasNoErrors();

        $after = textsOf($post);

        expect($after[$this->page->id])->toEqual($before[$this->page->id])
            ->and($after[$this->profile->id]->id)->toBe($before[$this->profile->id]->id)
            ->and($after[$this->profile->id]->body)->toBe('From tomorrow our doors open at eight. Come by before work.')
            ->and($after[$this->profile->id]->link)->toBeNull();
    });

    it('saves a text that is too long for one channel, says which, and refuses the approval until it fits', function () {
        // 400 characters: fine for Facebook, too long for LinkedIn.
        $long = str_repeat('a', 400);

        $post = writeForBoth($this, ['variants' => [
            ['channel_id' => $this->page->id, 'body' => $long, 'link' => null],
            ['channel_id' => $this->profile->id, 'body' => $long, 'link' => null],
        ]]);

        $tooLong = textsOf($post)[$this->profile->id];
        $problem = ["variants.{$tooLong->id}.body" => ['The text for Thandi Mokoena is 400 characters long. The limit is 300.']];

        $this->get(route('posts.show', $post->id))->assertInertia(fn (Assert $page) => $page->where('post.problems', $problem));

        $this->post(route('posts.approve', $post->id), ['version' => postVersion($post)])
            ->assertSessionHasErrors(["variants.{$tooLong->id}.body"]);

        expect(stateOf($post))->toBe('draft');

        $this->put(route('posts.update', $post->id), [
            'scheduled_at' => '2026-11-25T09:00',
            'variants' => [
                ['channel_id' => $this->page->id, 'body' => $long, 'link' => null],
                ['channel_id' => $this->profile->id, 'body' => str_repeat('a', LINKEDIN_LIMIT), 'link' => null],
            ],
        ])->assertSessionHasNoErrors();

        $this->post(route('posts.approve', $post->id), ['version' => postVersion($post)])->assertSessionHasNoErrors();

        expect(stateOf($post))->toBe('scheduled')
            ->and(textsOf($post)[$this->page->id]->body)->toBe($long);
    });

    it('blocks the approval while a channel of the post is disconnected, and lets it through once that channel is taken off', function () {
        $post = writeForBoth($this);
        $orphan = textsOf($post)[$this->profile->id];

        DB::table('channels')->where('id', $this->profile->id)->update(['status' => ChannelStatus::Disconnected->value]);

        // The channel stays on the page, with what is wrong with it.
        $this->get(route('posts.show', $post->id))->assertInertia(fn (Assert $page) => $page
            ->has('channels', 2)
            ->where('channels.1.id', $this->profile->id)
            ->where('channels.1.can_publish', false)
            ->where('channels.1.problem', 'Thandi Mokoena is disconnected.')
            ->where('post.problems', ["variants.{$orphan->id}.channel" => ['Thandi Mokoena is disconnected.']]));

        $this->post(route('posts.approve', $post->id), ['version' => postVersion($post)])
            ->assertSessionHasErrors(["variants.{$orphan->id}.channel" => 'Thandi Mokoena is disconnected.']);

        expect(stateOf($post))->toBe('draft');

        $this->put(route('posts.update', $post->id), [
            'scheduled_at' => '2026-11-25T09:00',
            'variants' => [
                ['channel_id' => $this->page->id, 'body' => 'We open at eight tomorrow.', 'link' => 'https://example.com/hours'],
            ],
        ])->assertSessionHasNoErrors();

        $this->post(route('posts.approve', $post->id), ['version' => postVersion($post)])->assertSessionHasNoErrors();

        expect(stateOf($post))->toBe('scheduled')
            ->and(array_keys(textsOf($post)))->toBe([$this->page->id]);
    });

    it('names a platform this instance cannot publish to on the channel itself', function () {
        $elsewhere = channelIn($this->workspace, ['platform' => 'somewhere', 'display_name' => 'Elsewhere']);

        $this->actingAs($this->editor)->get(route('posts.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('channels.2.id', $elsewhere->id)
                ->where('channels.2.problem', 'Publishing to Somewhere is not available on this instance.')
                ->where('channels.2.max_body_length', null)
                ->where('channels.2.link_previews', null));
    });

    it('shows the title of a link only on the channel whose platform shows a card for it', function () {
        $this->linkedin->withCapabilities(new ChannelCapabilities(maxBodyLength: LINKEDIN_LIMIT, linkPreviews: true, linkTitleLength: 60, linkDescriptionLength: 120));

        $post = writeForBoth($this, ['variants' => [
            ['channel_id' => $this->page->id, 'body' => 'We open at eight.', 'link' => 'https://example.com/hours', 'link_title' => 'Our hours', 'link_description' => 'When we open.'],
            ['channel_id' => $this->profile->id, 'body' => 'We open at eight.', 'link' => 'https://example.com/hours', 'link_title' => 'Our hours', 'link_description' => 'When we open.'],
        ]]);

        $this->get(route('posts.show', $post->id))->assertInertia(fn (Assert $page) => $page
            ->where('post.problems', [])
            ->where('post.variants.0.platform_label', 'Facebook')
            ->where('post.variants.0.link', 'https://example.com/hours')
            ->where('post.variants.0.link_title', null)
            ->where('post.variants.0.link_description', null)
            ->where('post.variants.1.platform_label', 'LinkedIn')
            ->where('post.variants.1.link_title', 'Our hours')
            ->where('post.variants.1.link_description', 'When we open.'));
    });

    it('marks a text whose outcome on its platform is unknown, which the server keeps whatever is sent', function () {
        $post = writeForBoth($this);
        $kept = textsOf($post)[$this->profile->id];

        DB::table('post_variants')->where('id', $kept->id)->update(['outcome_unknown_since' => now(), 'error' => 'No answer came back.']);

        $this->get(route('posts.show', $post->id))->assertInertia(fn (Assert $page) => $page
            ->where('post.variants.0.outcome_unknown', false)
            ->where('post.variants.1.outcome_unknown', true));

        // Leaving the channel out of a save does not take it off the post.
        $this->put(route('posts.update', $post->id), [
            'scheduled_at' => '2026-11-25T09:00',
            'variants' => [
                ['channel_id' => $this->page->id, 'body' => 'We open at eight tomorrow.', 'link' => null],
            ],
        ])->assertSessionHasNoErrors();

        expect(array_keys(textsOf($post)))->toEqualCanonicalizing([$this->page->id, $this->profile->id]);
    });

    it('refuses an approval when the text of either channel changed after it was read', function () {
        $post = writeForBoth($this);
        $seen = postVersion($post);

        DB::table('post_variants')->where('post_id', $post->id)->where('channel_id', $this->profile->id)->update(['body' => 'Changed by someone else.']);

        $this->post(route('posts.approve', $post->id), ['version' => $seen])->assertSessionHasErrors('version');

        expect(stateOf($post))->toBe('draft');
    });
});

describe('approving and publishing', function () {
    it('sends nothing before a human approves, then each text once to its own channel at the one firing time', function (string $timezone, string $justBefore, string $atTheMinute) {
        DB::table('workspaces')->where('id', $this->workspace->id)->update(['timezone' => $timezone]);

        $post = writeForBoth($this, ['variants' => [
            ['channel_id' => $this->page->id, 'body' => 'We open at eight tomorrow. Come hungry.', 'link' => 'https://example.com/hours'],
            ['channel_id' => $this->profile->id, 'body' => 'From tomorrow our doors open at eight.', 'link' => null],
        ]]);
        $texts = variantsOf($post);

        // A draft goes nowhere, also when its planned time has come.
        $this->travelTo($atTheMinute);
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();
        $this->facebook->assertNothingPublished();
        $this->linkedin->assertNothingPublished();

        $this->travelTo('2026-11-24 10:00:00');

        // One approval for the whole post.
        $this->post(route('posts.approve', $post->id), ['version' => postVersion($post)])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'The post is approved and scheduled.']);

        expect(freshPost($post)->state)->toBe(PostState::Scheduled)
            ->and(freshPost($post)->approved_by)->toBe($this->editor->id);

        $this->travelTo($justBefore);
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();
        $this->facebook->assertNothingPublished();
        $this->linkedin->assertNothingPublished();

        $this->travelTo($atTheMinute);
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();

        $this->facebook->assertPublishedOnce($texts[0])->assertNotPublished($texts[1]);
        $this->linkedin->assertPublishedOnce($texts[1])->assertNotPublished($texts[0]);

        expect(stateOf($post))->toBe('published');

        $this->get(route('posts.show', $post->id))->assertInertia(fn (Assert $page) => $page
            ->where('post.state', 'published')
            ->where('post.variants.0.body', 'We open at eight tomorrow. Come hungry.')
            ->where('post.variants.0.permalink', "https://platform.test/facebook/posts/facebook_{$texts[0]->id}_1")
            ->where('post.variants.1.body', 'From tomorrow our doors open at eight.')
            ->where('post.variants.1.permalink', "https://platform.test/linkedin/posts/linkedin_{$texts[1]->id}_1"));

        Http::assertNothingSent();
    })->with([
        'Copenhagen' => ['Europe/Copenhagen', '2026-11-25 07:59:59', '2026-11-25 08:00:00'],
        'Johannesburg' => ['Africa/Johannesburg', '2026-11-25 06:59:59', '2026-11-25 07:00:00'],
    ]);

    it('keeps what one channel published when the other refuses, and a retry sends only the one that is missing', function () {
        $post = writeForBoth($this);
        [$onPage, $onProfile] = variantsOf($post);

        $this->linkedin->failNextPublishes(PublishFailed::permanent('linkedin', 'The platform refused the post.'));

        $this->post(route('posts.approve', $post->id), ['version' => postVersion($post)])->assertSessionHasNoErrors();

        $this->travelTo('2026-11-25 08:00:00');
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();

        expect(stateOf($post))->toBe('failed');

        $this->facebook->assertPublishedOnce($onPage);
        $this->linkedin->assertPublishedOnce($onProfile);

        $this->get(route('posts.show', $post->id))->assertInertia(fn (Assert $page) => $page
            ->where('post.state', 'failed')
            ->where('post.variants.0.published', true)
            ->where('post.variants.1.published', false)
            ->where('post.variants.1.error', 'The platform refused the post.')
            ->where('post.can.retry', true));

        // A human sends it again. The scheduler runs twice, to show it is sent once.
        $this->post(route('posts.retry', $post->id))->assertSessionHasNoErrors();

        $this->travel(2)->minutes();
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();

        $this->facebook->assertPublishedOnce($onPage);
        $this->linkedin->assertPublishedTimes($onProfile, 2);

        [$onPage, $onProfile] = variantsOf($post);

        expect(stateOf($post))->toBe('published')
            ->and($onPage->external_id)->toBe("facebook_{$onPage->id}_1")
            ->and($onProfile->external_id)->toBe("linkedin_{$onProfile->id}_2")
            ->and($onProfile->error)->toBeNull();
    });

    it('does not send a post of another workspace, and does not show it', function () {
        $post = writeForBoth($this);

        $other = Workspace::factory()->create();
        $stranger = userWithRole($other, Role::Owner);

        $this->actingAs($stranger)->get(route('posts.show', $post->id))->assertNotFound();
        $this->actingAs($stranger)->post(route('posts.approve', $post->id), ['version' => postVersion($post)])->assertNotFound();

        expect(stateOf($post))->toBe('draft');

        $this->travelTo('2026-11-25 08:00:00');
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();

        $this->facebook->assertNothingPublished();
        $this->linkedin->assertNothingPublished();
    });
});
