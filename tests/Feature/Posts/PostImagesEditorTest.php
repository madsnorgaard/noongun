<?php

use App\Domain\Channels\Contracts\ChannelCapabilities;
use App\Domain\Content\Models\Post;
use App\Domain\Content\PostPreconditions;
use App\Domain\Content\PostState;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';
require_once __DIR__.'/../../Support/media.php';

/*
 * What the editor, the queue and the calendar are given to show images,
 * and what the editor sends back. The pages themselves are not run here.
 */

beforeEach(function () {
    $this->travelTo('2026-11-24 10:00:00');

    fakeMediaDisk();
    $this->facebook = fakeChannelAdapter()->acceptingImages(max: 3, altTextLength: 20);
    // A platform whose adapter takes no images yet.
    $this->linkedin = fakeChannelAdapter('linkedin')->acceptingImages(max: 0);
    $this->workspace = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
    $this->owner = userWithRole($this->workspace, Role::Owner);
    $this->alpha = channelIn($this->workspace, ['display_name' => 'Alpha']);
    $this->bravo = channelIn($this->workspace, ['display_name' => 'Bravo']);
    $this->quiet = channelIn($this->workspace, ['display_name' => 'Quiet', 'platform' => 'linkedin']);
    $this->harbour = storedImage($this->workspace);
    $this->gull = storedImage($this->workspace, pngBytes());
});

/**
 * One text per channel, each with the images the editor holds for it.
 *
 * @param  array<int, list<array{id: int, alt_text?: string|null}>>  $mediaByChannel
 * @return array<string, mixed>
 */
function editorPayload(array $mediaByChannel, string $body = 'Fresh bread from eight.', ?string $link = null): array
{
    $variants = [];

    foreach ($mediaByChannel as $channelId => $media) {
        $variants[] = ['channel_id' => $channelId, 'body' => $body, 'link' => $link, 'media' => $media];
    }

    return ['scheduled_at' => null, 'variants' => $variants];
}

function editorPost(): Post
{
    return Post::query()->withoutGlobalScopes()->sole();
}

/**
 * The variant of a post for a channel.
 */
function editorVariant(Post $post, $channel)
{
    return collect(variantsOf($post))->firstWhere('channel_id', $channel->id);
}

describe('what the editor is told about each channel', function () {
    it('says what a channel takes, and that another takes no images', function () {
        $this->actingAs($this->owner)->get(route('posts.create'))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $channels = collect($page->toArray()['props']['channels'])->keyBy('display_name');

                expect($channels['Alpha']['media'])->toBe([
                    'max_items' => 3,
                    'types' => ['image/jpeg', 'image/png'],
                    'max_bytes' => null,
                    'max_pixels' => null,
                    'alt_text_length' => 20,
                    'link_rule' => 'in_text',
                ])
                    ->and($channels['Quiet']['media']['max_items'])->toBe(0)
                    ->and($channels['Quiet']['media']['types'])->toBe([]);

                $page->where('uploads.url', '/media')
                    ->where('uploads.accept', ['image/jpeg', 'image/png', 'image/webp'])
                    ->where('uploads.max_bytes', 20 * 1024 * 1024);
            });
    });

    it('gives the limits of a platform in bytes and pixels', function () {
        $this->facebook->acceptingImages(max: 2, altTextLength: null, maxBytes: 4 * 1024 * 1024, maxPixels: 36_000_000);

        $this->actingAs($this->owner)->get(route('posts.create'))
            ->assertInertia(function (Assert $page) {
                $alpha = collect($page->toArray()['props']['channels'])->firstWhere('display_name', 'Alpha');

                expect($alpha['media']['max_bytes'])->toBe(4 * 1024 * 1024)
                    ->and($alpha['media']['max_pixels'])->toBe(36_000_000)
                    ->and($alpha['media']['alt_text_length'])->toBeNull();
            });
    });

    it('gives the link as the platform gets it, so that the count on the page is the count of the check', function () {
        $post = postInState($this->workspace, PostState::Draft, [$this->alpha, $this->bravo]);

        DB::table('post_variants')->where('id', editorVariant($post, $this->alpha)->id)
            ->update(['link' => 'https://example.com/bread', 'utm' => json_encode(['utm_source' => 'noongun'])]);

        $this->actingAs($this->owner)->get(route('posts.show', $post->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('post.variants.0.link', 'https://example.com/bread')
                ->where('post.variants.0.link_as_sent', 'https://example.com/bread?utm_source=noongun')
                ->where('post.variants.1.link_as_sent', null));
    });
});

describe('what the editor sends', function () {
    it('saves a new post with images in order, the same image on two channels and a description for each channel', function () {
        $this->actingAs($this->owner)->post(route('posts.store'), editorPayload([
            $this->alpha->id => [
                ['id' => $this->gull->id, 'alt_text' => 'A gull.'],
                ['id' => $this->harbour->id, 'alt_text' => 'The harbour at dusk.'],
            ],
            $this->bravo->id => [
                ['id' => $this->harbour->id, 'alt_text' => 'Our harbour.'],
            ],
            $this->quiet->id => [],
        ]))->assertRedirect();

        $post = editorPost();

        expect(imagesOf(editorVariant($post, $this->alpha)))->toBe([[$this->gull->id, 'A gull.'], [$this->harbour->id, 'The harbour at dusk.']])
            ->and(imagesOf(editorVariant($post, $this->bravo)))->toBe([[$this->harbour->id, 'Our harbour.']])
            ->and(imagesOf(editorVariant($post, $this->quiet)))->toBe([])
            ->and(DB::table('media')->count())->toBe(2);

        $this->get(route('posts.show', $post->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('post.variants.0.media.0.id', $this->gull->id)
                ->where('post.variants.0.media.1.alt_text', 'The harbour at dusk.')
                ->where('post.variants.1.media.0.id', $this->harbour->id)
                ->where('post.variants.1.media.0.alt_text', 'Our harbour.')
                ->where('post.variants.2.media', [])
                ->where('post.variants.2.media_rules.max_items', 0));
    });

    it('reorders and removes on one channel and leaves the other channel as it was', function () {
        $this->actingAs($this->owner)->post(route('posts.store'), editorPayload([
            $this->alpha->id => [['id' => $this->harbour->id, 'alt_text' => 'A harbour.'], ['id' => $this->gull->id, 'alt_text' => 'A gull.']],
            $this->bravo->id => [['id' => $this->harbour->id, 'alt_text' => 'A harbour.'], ['id' => $this->gull->id, 'alt_text' => 'A gull.']],
        ]));

        $post = editorPost();
        $before = postVersion($post);
        $bravo = [['id' => $this->harbour->id, 'alt_text' => 'A harbour.'], ['id' => $this->gull->id, 'alt_text' => 'A gull.']];

        $this->put(route('posts.update', $post->id), editorPayload([
            $this->alpha->id => [['id' => $this->gull->id, 'alt_text' => 'A gull.'], ['id' => $this->harbour->id, 'alt_text' => 'A harbour.']],
            $this->bravo->id => $bravo,
        ]))->assertRedirect();

        expect(imagesOf(editorVariant($post, $this->alpha)))->toBe([[$this->gull->id, 'A gull.'], [$this->harbour->id, 'A harbour.']])
            ->and(imagesOf(editorVariant($post, $this->bravo)))->toBe([[$this->harbour->id, 'A harbour.'], [$this->gull->id, 'A gull.']])
            ->and(postVersion($post))->not->toBe($before);

        $this->put(route('posts.update', $post->id), editorPayload([
            $this->alpha->id => [],
            $this->bravo->id => $bravo,
        ]))->assertRedirect();

        expect(imagesOf(editorVariant($post, $this->alpha)))->toBe([])
            ->and(imagesOf(editorVariant($post, $this->bravo)))->toHaveCount(2);
    });

    it('answers a refused save with the position of the text, so the page can show it in that column', function () {
        $elsewhere = storedImage(Workspace::factory()->create());

        $this->actingAs($this->owner)->post(route('posts.store'), editorPayload([
            $this->alpha->id => [['id' => $this->harbour->id]],
            $this->bravo->id => [['id' => $this->harbour->id], ['id' => $elsewhere->id]],
        ]))->assertSessionHasErrors(['variants.1.media.1.id'])->assertSessionDoesntHaveErrors(['variants.0.media.0.id']);

        expect(Post::query()->withoutGlobalScopes()->count())->toBe(0);
    });
});

describe('advice and problems for the column and the image they concern', function () {
    it('keys each to its text and its image, and holds nothing back for advice', function () {
        $this->actingAs($this->owner)->post(route('posts.store'), editorPayload([
            $this->alpha->id => [
                ['id' => $this->harbour->id, 'alt_text' => ''],
                ['id' => $this->gull->id, 'alt_text' => 'A gull on a bollard, far too long for this platform.'],
            ],
            $this->bravo->id => [['id' => $this->harbour->id, 'alt_text' => 'A harbour.']],
            $this->quiet->id => [],
        ]));

        $post = editorPost();
        $alpha = editorVariant($post, $this->alpha)->id;
        $bravo = editorVariant($post, $this->bravo)->id;

        $this->get(route('posts.show', $post->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('post.advice', [
                    "variants.{$alpha}.media.{$this->harbour->id}.alt_text" => ['Image 1 for Alpha has no description. People who use a screen reader will not know what it shows.'],
                ])
                ->where('post.problems', [
                    "variants.{$alpha}.media.{$this->gull->id}.alt_text" => ['The description of image 2 for Alpha is 52 characters long. Noon Gun sends 20 at most to Facebook. Shorten it.'],
                ]));

        // With the description shortened only the advice is left, and the
        // post is approved with it.
        $this->put(route('posts.update', $post->id), editorPayload([
            $this->alpha->id => [['id' => $this->harbour->id, 'alt_text' => ''], ['id' => $this->gull->id, 'alt_text' => 'A gull.']],
            $this->bravo->id => [['id' => $this->harbour->id, 'alt_text' => 'A harbour.']],
            $this->quiet->id => [],
        ]));

        $this->get(route('posts.show', $post->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('post.problems', [])
                // The same image has a description on Bravo, so only the
                // text for Alpha is advised on.
                ->where('post.advice', fn ($advice) => collect($advice)->keys()->all() === ["variants.{$alpha}.media.{$this->harbour->id}.alt_text"]
                    && ! collect($advice)->has("variants.{$bravo}.media.{$this->harbour->id}.alt_text")));

        $this->post(route('posts.approve', $post->id), ['version' => postVersion($post)])->assertSessionHasNoErrors();

        expect(stateOf($post))->toBe('approved');

        // An approved post is not advised on any more.
        $this->get(route('posts.show', $post->id))
            ->assertInertia(fn (Assert $page) => $page->where('post.advice', []));
    });

    it('names the text with too many images, and the text on a channel that takes none', function () {
        $extra = [storedImage($this->workspace), storedImage($this->workspace)];
        $post = postInState($this->workspace, PostState::Draft, [$this->alpha, $this->quiet]);

        foreach ([$this->harbour, $this->gull, ...$extra] as $image) {
            attachImage(editorVariant($post, $this->alpha), $image, 'An image.');
        }

        attachImage(editorVariant($post, $this->quiet), $this->harbour, 'An image.');

        $alpha = editorVariant($post, $this->alpha)->id;
        $quiet = editorVariant($post, $this->quiet)->id;

        $this->actingAs($this->owner)->get(route('posts.show', $post->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('post.problems', [
                    "variants.{$alpha}.media" => ['The text for Alpha has 4 images. Noon Gun sends 3 at most to Facebook on one post. Take 1 off.'],
                    "variants.{$quiet}.media" => ['Images cannot be published to LinkedIn from this instance. Take the images off the text for Quiet.'],
                ])
                ->where('post.can.approve', true));

        $this->post(route('posts.approve', $post->id), ['version' => postVersion($post)])
            ->assertSessionHasErrors(["variants.{$alpha}.media", "variants.{$quiet}.media"]);
    });

    it('names the image that is too large for its channel', function () {
        $this->facebook->acceptingImages(max: 3, maxBytes: 1024 * 1024);

        $large = storedImage($this->workspace, null, ['byte_size' => 3 * 1024 * 1024]);
        $post = postInState($this->workspace, PostState::Draft, [$this->alpha]);
        $variant = editorVariant($post, $this->alpha);

        attachImage($variant, $this->harbour, 'A harbour.');
        attachImage($variant, $large, 'A large one.');

        $this->actingAs($this->owner)->get(route('posts.show', $post->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('post.problems', [
                    "variants.{$variant->id}.media.{$large->id}" => ['Image 2 for Alpha is stored at 3 MB. Noon Gun sends 1 MB at most to Facebook for one image. Take it off and add it again with fewer pixels.'],
                ])
                ->where('post.variants.0.media.1.byte_size', 3 * 1024 * 1024)
                ->where('post.variants.0.media_rules.max_bytes', 1024 * 1024));
    });

    it('counts the link as the last line of a text with images, and says that it moves', function () {
        $this->facebook->withCapabilities(new ChannelCapabilities(maxBodyLength: 60, mediaTypes: ['image/jpeg', 'image/png'], maxMediaItems: 3, altTextLength: 100));

        // 40 characters, an empty line and a link of 25: 67 with images, 40 without.
        $body = str_repeat('a', 40);
        $link = 'https://example.com/bread';

        $this->actingAs($this->owner)->post(route('posts.store'), editorPayload([
            $this->alpha->id => [['id' => $this->harbour->id, 'alt_text' => 'A harbour.']],
            $this->bravo->id => [],
        ], $body, $link));

        $post = editorPost();
        $alpha = editorVariant($post, $this->alpha)->id;

        $this->get(route('posts.show', $post->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('post.problems', [
                    "variants.{$alpha}.body" => ['The text for Alpha is 67 characters long with its link, which goes out as the last line of the text because the post has an image. The limit is 60.'],
                ])
                ->where('post.advice', [
                    "variants.{$alpha}.link" => ['With an image, Facebook shows no preview of the link. The link for Alpha goes out as the last line of the text.'],
                ])
                ->where('post.variants.0.media_rules.link_rule', 'in_text')
                ->where('post.variants.0.link_as_sent', $link));
    });
});

describe('sentences that say what will help', function () {
    it('names the stored size of a PNG that is too large and says that JPEG or fewer pixels will help', function () {
        // 10,000,000 bytes is written "10 MB", and so is 10 x 1024 x 1024.
        $this->facebook->acceptingImages(max: 3, maxBytes: 10_000_000);

        $png = storedImage($this->workspace, pngBytes(), ['byte_size' => 12_345_678]);
        $post = postInState($this->workspace, PostState::Draft, [$this->alpha]);
        $variant = editorVariant($post, $this->alpha);

        attachImage($variant, $png, 'A drawing.');

        $this->actingAs($this->owner)->get(route('posts.show', $post->id))
            ->assertInertia(fn (Assert $page) => $page->where('post.problems', [
                "variants.{$variant->id}.media.{$png->id}" => ['Image 1 for Alpha is stored as a PNG file of 12.3 MB. Noon Gun sends 10 MB at most to Facebook for one image. Take it off and add it again as a JPEG, or with fewer pixels.'],
            ]));
    });

    it('writes a size the same way everywhere', function (int $bytes, string $written) {
        expect(PostPreconditions::megabytes($bytes))->toBe($written);
    })->with([
        'whole binary megabytes' => [20 * 1024 * 1024, '20'],
        'whole decimal megabytes' => [10_000_000, '10'],
        'the size of a file' => [2_512_345, '2.5'],
        'a small file' => [40_000, '0'],
    ]);

    it('says plainly that a text is needed for now when a text has images and no words', function () {
        $post = postInState($this->workspace, PostState::Draft, [$this->alpha, $this->bravo]);

        DB::table('post_variants')->where('post_id', $post->id)->update(['body' => '  ']);
        attachImage(editorVariant($post, $this->alpha), $this->harbour, 'A harbour.');

        $alpha = editorVariant($post, $this->alpha)->id;
        $bravo = editorVariant($post, $this->bravo)->id;

        $this->actingAs($this->owner)->get(route('posts.show', $post->id))
            ->assertInertia(fn (Assert $page) => $page->where('post.problems', [
                "variants.{$alpha}.body" => ['Facebook posts need a text for now, even with images. Write a text for Alpha.'],
                "variants.{$bravo}.body" => ['The text for Bravo is empty.'],
            ]));
    });

    it('gives the campaign parameters of a text, and says when the link would go out twice', function () {
        $post = postInState($this->workspace, PostState::Draft, [$this->alpha, $this->bravo]);
        $alpha = editorVariant($post, $this->alpha);

        DB::table('post_variants')->where('id', $alpha->id)->update([
            'body' => "Fresh bread from eight.\n\nhttps://example.com/bread",
            'link' => 'https://example.com/bread',
            'utm' => json_encode(['utm_source' => 'noon gun', 'utm_medium' => 'social']),
        ]);
        attachImage($alpha, $this->harbour, 'A harbour.');

        $this->actingAs($this->owner)->get(route('posts.show', $post->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('post.variants.0.link_campaign', 'utm_source=noon%20gun&utm_medium=social')
                ->where('post.variants.0.link_as_sent', 'https://example.com/bread?utm_source=noon%20gun&utm_medium=social')
                ->where('post.variants.1.link_campaign', null)
                ->where('post.advice', [
                    "variants.{$alpha->id}.link" => ['The text for Alpha already ends with its link. With an image the link is added once more as the last line, with its campaign parameters. Take the link out of the text.'],
                ]));

        // Without campaign parameters the link is not repeated, and the
        // advice is the ordinary one.
        DB::table('post_variants')->where('id', $alpha->id)->update(['utm' => null]);

        $this->get(route('posts.show', $post->id))
            ->assertInertia(fn (Assert $page) => $page->where('post.advice', [
                "variants.{$alpha->id}.link" => ['With an image, Facebook shows no preview of the link. The link for Alpha goes out as the last line of the text.'],
            ]));
    });
});

describe('sending again', function () {
    it('tells the page whether a failed text may be public or certainly is not', function () {
        $post = postInState($this->workspace, PostState::Failed, [$this->alpha, $this->bravo]);
        $unsure = editorVariant($post, $this->alpha);

        DB::table('post_variants')->where('post_id', $post->id)->update(['error' => 'An image could not be sent.', 'outcome_unknown_since' => null]);
        DB::table('post_variants')->where('id', $unsure->id)->update(['error' => null, 'outcome_unknown_since' => now()]);

        $this->actingAs($this->owner)->get(route('posts.show', $post->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('post.can.retry', true)
                ->where('post.variants.0.outcome_unknown', true)
                ->where('post.variants.0.published', false)
                ->where('post.variants.1.outcome_unknown', false)
                ->where('post.variants.1.error', 'An image could not be sent.'));
    });
});

describe('lists and read-only views', function () {
    it('shows an approved post with its images and their descriptions, and offers no change', function (PostState $state) {
        $post = postInState($this->workspace, $state, [$this->alpha, $this->bravo], now()->addDay());

        attachImage(editorVariant($post, $this->alpha), $this->harbour, 'A harbour.');
        attachImage(editorVariant($post, $this->alpha), $this->gull, null);

        $this->actingAs($this->owner)->get(route('posts.show', $post->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('post.can.update', false)
                ->where('post.variants.0.media.0.thumb_url', "/media/{$this->harbour->id}/thumb")
                ->where('post.variants.0.media.0.url', "/media/{$this->harbour->id}")
                ->where('post.variants.0.media.0.alt_text', 'A harbour.')
                ->where('post.variants.0.media.0.width', 40)
                ->where('post.variants.0.media.0.height', 20)
                ->where('post.variants.0.media.1.alt_text', null)
                ->where('post.variants.1.media', [])
                ->where('post.advice', []));
    })->with([
        'approved' => [PostState::Approved],
        'scheduled' => [PostState::Scheduled],
        'published' => [PostState::Published],
        'failed' => [PostState::Failed],
    ]);

    it('gives the queue small copies with their sizes, in every section and in the history', function () {
        $sections = ['drafts' => PostState::Draft, 'upcoming' => PostState::Scheduled, 'failed' => PostState::Failed];

        foreach ($sections as $state) {
            $post = postInState($this->workspace, $state, [$this->alpha], now()->addDay());

            attachImage(variantsOf($post)[0], $this->harbour, 'A harbour.');
            attachImage(variantsOf($post)[0], $this->gull, null);
        }

        $published = postInState($this->workspace, PostState::Published, [$this->alpha]);

        attachImage(variantsOf($published)[0], $this->gull, 'A gull.');

        $response = $this->actingAs($this->owner)->get(route('queue.index'));

        foreach (array_keys($sections) as $section) {
            $response->assertInertia(fn (Assert $page) => $page
                ->has("sections.{$section}.0.variants.0.media", 2)
                ->where("sections.{$section}.0.variants.0.media.0.thumb_url", "/media/{$this->harbour->id}/thumb")
                ->where("sections.{$section}.0.variants.0.media.0.alt_text", 'A harbour.')
                ->where("sections.{$section}.0.variants.0.media.0.width", 40)
                ->where("sections.{$section}.0.variants.0.media.1.alt_text", null));
        }

        $this->get(route('queue.index', ['view' => 'history']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('history.posts.0.variants.0.media', 1)
                ->where('history.posts.0.variants.0.media.0.thumb_url', "/media/{$this->gull->id}/thumb"));
    });

    it('gives a calendar entry its first small copy, what it shows and the count, and none without images', function () {
        $with = postInState($this->workspace, PostState::Scheduled, [$this->alpha], now()->addDay());
        $without = postInState($this->workspace, PostState::Scheduled, [$this->alpha], now()->addDay());

        attachImage(variantsOf($with)[0], $this->harbour, 'A harbour.');
        attachImage(variantsOf($with)[0], $this->gull, null);

        $this->actingAs($this->owner)->get(route('calendar.index', ['date' => now()->addDay()->format('Y-m-d')]))
            ->assertInertia(function (Assert $page) use ($with, $without) {
                $entries = collect($page->toArray()['props']['days'])->flatMap(fn ($day) => $day['posts'])->keyBy('id');

                expect($entries[$with->id])->toMatchArray([
                    'thumb_url' => "/media/{$this->harbour->id}/thumb",
                    'thumb_alt' => 'A harbour.',
                    'image_count' => 2,
                ])->and($entries[$without->id])->toMatchArray(['thumb_url' => null, 'thumb_alt' => null, 'image_count' => 0]);
            });
    });

    it('shows nothing of the images of another workspace', function () {
        $other = Workspace::factory()->create();
        $theirs = postInState($other, PostState::Scheduled, [channelIn($other, ['display_name' => 'Theirs'])], now()->addDay());
        $secret = storedImage($other);

        attachImage(variantsOf($theirs)[0], $secret, 'Not for others.');

        $address = "/media/{$secret->id}";

        $this->actingAs($this->owner);

        foreach ([route('queue.index'), route('queue.index', ['view' => 'history']), route('calendar.index', ['date' => now()->addDay()->format('Y-m-d')])] as $page) {
            expect($this->get($page)->assertOk()->getContent())
                ->not->toContain($address)
                ->not->toContain('Not for others.');
        }

        $this->get(route('posts.show', $theirs->id))->assertNotFound();
        $this->get($address)->assertNotFound();
        $this->get("{$address}/thumb")->assertNotFound();
    });
});

describe('a viewer', function () {
    it('sees the images of a draft, cannot change it and cannot upload', function () {
        $post = postInState($this->workspace, PostState::Draft, [$this->alpha]);

        attachImage(variantsOf($post)[0], $this->harbour, 'A harbour.');

        $viewer = userWithRole($this->workspace, Role::Viewer);

        $this->actingAs($viewer)->get(route('posts.show', $post->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('post.can.update', false)
                ->where('post.can.approve', false)
                ->where('post.variants.0.media.0.thumb_url', "/media/{$this->harbour->id}/thumb")
                ->where('post.variants.0.media.0.alt_text', 'A harbour.'));

        $this->get("/media/{$this->harbour->id}/thumb")->assertOk();
        $this->get(route('posts.create'))->assertForbidden();

        $this->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])->assertForbidden();
        $this->put(route('posts.update', $post->id), editorPayload([$this->alpha->id => []]))->assertForbidden();

        expect(imagesOf(variantsOf($post)[0]))->toBe([[$this->harbour->id, 'A harbour.']]);
    });
});

describe('what is saved and what is approved', function () {
    it('keeps an upload off the post until the draft is saved, and the approval off what was not seen', function () {
        $post = postInState($this->workspace, PostState::Draft, [$this->alpha]);

        DB::table('post_variants')->where('post_id', $post->id)->update(['body' => 'Fresh bread from eight.']);

        $seen = postVersion($post);

        $uploaded = $this->actingAs($this->owner)
            ->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])
            ->assertCreated()
            ->assertJsonStructure(['id', 'url', 'thumb_url', 'mime', 'byte_size', 'width', 'height'])
            ->json('id');

        // Stored, and on no post: the page shows the same version as before.
        expect(imagesOf(variantsOf($post)[0]))->toBe([])
            ->and(postVersion($post))->toBe($seen);

        $this->put(route('posts.update', $post->id), editorPayload([
            $this->alpha->id => [['id' => $uploaded, 'alt_text' => 'Fresh bread.']],
        ]))->assertRedirect();

        expect(imagesOf(variantsOf($post)[0]))->toBe([[$uploaded, 'Fresh bread.']]);

        // An approval of the page as it was before the save is refused.
        $this->post(route('posts.approve', $post->id), ['version' => $seen]);

        expect(stateOf($post))->toBe('draft');

        $this->post(route('posts.approve', $post->id), ['version' => postVersion($post)])->assertSessionHasNoErrors();

        expect(stateOf($post))->toBe('approved');

        $this->get(route('posts.show', $post->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('post.state', 'approved')
                ->where('post.variants.0.media.0.id', $uploaded)
                ->where('post.variants.0.media.0.alt_text', 'Fresh bread.'));
    });

    it('answers an upload that is refused with one sentence for the page', function () {
        $this->actingAs($this->owner)
            ->postJson(route('media.store'), ['file' => uploadOf(gifBytes(), 'moving.gif')])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['file']]);

        expect($this->postJson(route('media.store'), ['file' => uploadOf(gifBytes(), 'moving.gif')])->json('errors.file.0'))
            ->toBeString()->not->toBe('');
    });
});
