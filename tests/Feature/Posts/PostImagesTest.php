<?php

use App\Domain\Channels\Contracts\ChannelCapabilities;
use App\Domain\Channels\Contracts\MediaLinkRule;
use App\Domain\Content\Actions\SaveDraft;
use App\Domain\Content\Exceptions\AttachedMediaMissing;
use App\Domain\Content\Models\Media;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostPreconditions;
use App\Domain\Content\PostState;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';
require_once __DIR__.'/../../Support/media.php';

beforeEach(function () {
    $this->travelTo('2026-11-24 10:00:00');

    fakeMediaDisk();
    $this->fake = fakeChannelAdapter()->acceptingImages(max: 3, altTextLength: 100);
    $this->workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->owner = userWithRole($this->workspace, Role::Owner);
    $this->alpha = channelIn($this->workspace, ['display_name' => 'Alpha']);
    $this->bravo = channelIn($this->workspace, ['display_name' => 'Bravo']);
    $this->harbour = storedImage($this->workspace);
    $this->gull = storedImage($this->workspace, pngBytes());
});

/**
 * What the editor sends: one text per channel, each with its images.
 *
 * @param  array<int, list<array{id: int, alt_text?: string|null}>|null>  $mediaByChannel  channel id => images; null leaves the key out
 * @return array<string, mixed>
 */
function imagePayload(array $mediaByChannel, ?string $link = null): array
{
    $variants = [];

    foreach ($mediaByChannel as $channelId => $media) {
        $variants[] = [
            'channel_id' => $channelId,
            'body' => 'Fresh bread from eight.',
            'link' => $link,
            ...($media === null ? [] : ['media' => $media]),
        ];
    }

    return ['scheduled_at' => null, 'variants' => $variants];
}

function onlyPost(): Post
{
    return Post::query()->withoutGlobalScopes()->sole();
}

describe('saving a draft with images', function () {
    it('attaches images to a text in the order they are sent, each with its description', function () {
        $this->actingAs($this->owner)->post(route('posts.store'), imagePayload([
            $this->alpha->id => [
                ['id' => $this->gull->id, 'alt_text' => '  A gull on a bollard. '],
                ['id' => $this->harbour->id, 'alt_text' => ''],
            ],
        ]))->assertRedirect();

        expect(imagesOf(variantsOf(onlyPost())[0]))->toBe([
            [$this->gull->id, 'A gull on a bollard.'],
            [$this->harbour->id, null],
        ]);
    });

    it('changes the order, the descriptions and the images of an existing draft', function () {
        $this->actingAs($this->owner)->post(route('posts.store'), imagePayload([
            $this->alpha->id => [['id' => $this->harbour->id, 'alt_text' => 'A harbour.'], ['id' => $this->gull->id]],
        ]));

        $post = onlyPost();
        $variant = variantsOf($post)[0];
        $third = storedImage($this->workspace);

        // Reorder.
        $this->put(route('posts.update', $post->id), imagePayload([
            $this->alpha->id => [['id' => $this->gull->id], ['id' => $this->harbour->id, 'alt_text' => 'A harbour.']],
        ]))->assertRedirect();

        expect(imagesOf($variant))->toBe([[$this->gull->id, null], [$this->harbour->id, 'A harbour.']]);

        // Describe one, remove one, add one.
        $this->put(route('posts.update', $post->id), imagePayload([
            $this->alpha->id => [['id' => $this->gull->id, 'alt_text' => 'A gull.'], ['id' => $third->id]],
        ]))->assertRedirect();

        expect(imagesOf($variant))->toBe([[$this->gull->id, 'A gull.'], [$third->id, null]]);

        // An empty list takes every image off. The files stay for the clean-up.
        $this->put(route('posts.update', $post->id), imagePayload([$this->alpha->id => []]))->assertRedirect();

        expect(imagesOf($variant))->toBe([])
            ->and(Media::query()->withoutGlobalScopes()->count())->toBe(3);
    });

    it('leaves the images alone when a save does not mention them', function () {
        $this->actingAs($this->owner)->post(route('posts.store'), imagePayload([
            $this->alpha->id => [['id' => $this->harbour->id, 'alt_text' => 'A harbour.']],
        ]));

        $post = onlyPost();

        $this->put(route('posts.update', $post->id), imagePayload([$this->alpha->id => null]))->assertRedirect();

        expect(imagesOf(variantsOf($post)[0]))->toBe([[$this->harbour->id, 'A harbour.']]);
    });

    it('puts the same image on two texts of one post without a second file', function () {
        $files = count(Storage::disk('media')->allFiles());

        $this->actingAs($this->owner)->post(route('posts.store'), imagePayload([
            $this->alpha->id => [['id' => $this->harbour->id, 'alt_text' => 'A harbour at dusk.']],
            $this->bravo->id => [['id' => $this->harbour->id, 'alt_text' => 'The harbour, for Bravo.']],
        ]))->assertRedirect();

        [$alpha, $bravo] = variantsOf(onlyPost());

        expect(imagesOf($alpha))->toBe([[$this->harbour->id, 'A harbour at dusk.']])
            ->and(imagesOf($bravo))->toBe([[$this->harbour->id, 'The harbour, for Bravo.']])
            ->and(Media::query()->withoutGlobalScopes()->count())->toBe(2)
            ->and(Storage::disk('media')->allFiles())->toHaveCount($files);
    });

    it('keeps the image on one text when it is taken off the other', function () {
        $this->actingAs($this->owner)->post(route('posts.store'), imagePayload([
            $this->alpha->id => [['id' => $this->harbour->id]],
            $this->bravo->id => [['id' => $this->harbour->id]],
        ]));

        $post = onlyPost();

        $this->put(route('posts.update', $post->id), imagePayload([$this->alpha->id => [], $this->bravo->id => null]));

        [$alpha, $bravo] = variantsOf($post);

        expect(imagesOf($alpha))->toBe([])->and(imagesOf($bravo))->toBe([[$this->harbour->id, null]]);
    });

    it('takes the images off with the channel', function () {
        $this->actingAs($this->owner)->post(route('posts.store'), imagePayload([
            $this->alpha->id => [['id' => $this->harbour->id]],
            $this->bravo->id => [['id' => $this->gull->id]],
        ]));

        $post = onlyPost();

        $this->put(route('posts.update', $post->id), imagePayload([$this->alpha->id => null]));

        expect(DB::table('post_variant_media')->pluck('media_id')->all())->toBe([$this->harbour->id])
            ->and(Media::query()->withoutGlobalScopes()->count())->toBe(2);
    });

    it('refuses an image of another workspace, as one that does not exist', function () {
        $foreign = storedImage(Workspace::factory()->create());

        foreach ([$foreign->id, 999999] as $id) {
            $this->actingAs($this->owner)
                ->post(route('posts.store'), imagePayload([$this->alpha->id => [['id' => $this->harbour->id], ['id' => $id]]]))
                ->assertSessionHasErrors(['variants.0.media.1.id' => 'This image does not exist in this workspace. It may have been removed because no post used it. Upload it again.']);
        }

        expect(Post::query()->withoutGlobalScopes()->count())->toBe(0)
            ->and(DB::table('post_variant_media')->count())->toBe(0);
    });

    it('refuses the same image twice on one text, more than the instance allows, and a description that is too long', function () {
        config(['noongun.media.max_per_variant' => 2]);

        $third = storedImage($this->workspace);

        $this->actingAs($this->owner)
            ->post(route('posts.store'), imagePayload([$this->alpha->id => [['id' => $this->harbour->id], ['id' => $this->harbour->id]]]))
            ->assertSessionHasErrors(['variants.0.media' => 'The same image is on this text twice.']);

        $this->post(route('posts.store'), imagePayload([$this->alpha->id => [['id' => $this->harbour->id], ['id' => $this->gull->id], ['id' => $third->id]]]))
            ->assertSessionHasErrors(['variants.0.media' => 'A text can carry 2 images at most.']);

        $this->post(route('posts.store'), imagePayload([$this->alpha->id => [['id' => $this->harbour->id, 'alt_text' => str_repeat('a', 5001)]]]))
            ->assertSessionHasErrors('variants.0.media.0.alt_text');

        $this->post(route('posts.store'), imagePayload([$this->alpha->id => [['alt_text' => 'No id.']]]))
            ->assertSessionHasErrors('variants.0.media.0.id');

        $this->post(route('posts.store'), ['scheduled_at' => null, 'variants' => [['channel_id' => $this->alpha->id, 'body' => 'x', 'link' => null, 'media' => 'not-a-list']]])
            ->assertSessionHasErrors('variants.0.media');

        expect(Post::query()->withoutGlobalScopes()->count())->toBe(0);
    });

    it('does not let a viewer attach anything', function () {
        $this->actingAs(userWithRole($this->workspace, Role::Viewer))
            ->post(route('posts.store'), imagePayload([$this->alpha->id => [['id' => $this->harbour->id]]]))
            ->assertForbidden();
    });

    it('says so when an image was removed between the check and the save', function () {
        $missing = fn () => inWorkspace($this->workspace, fn () => app(SaveDraft::class)->handle($this->owner, null, null, [
            ['channel_id' => $this->alpha->id, 'body' => 'Text.', 'link' => null, 'media' => [['id' => 999999]]],
        ]));

        expect($missing)->toThrow(AttachedMediaMissing::class)
            // Nothing of the draft is left behind.
            ->and(Post::query()->withoutGlobalScopes()->count())->toBe(0);

        $foreign = storedImage(Workspace::factory()->create());

        expect(fn () => inWorkspace($this->workspace, fn () => app(SaveDraft::class)->handle($this->owner, null, null, [
            ['channel_id' => $this->alpha->id, 'body' => 'Text.', 'link' => null, 'media' => [['id' => $foreign->id]]],
        ])))->toThrow(AttachedMediaMissing::class);
    });
});

describe('images are part of what is approved', function () {
    beforeEach(function () {
        $this->actingAs($this->owner)->post(route('posts.store'), imagePayload([
            $this->alpha->id => [['id' => $this->harbour->id, 'alt_text' => 'A harbour.'], ['id' => $this->gull->id, 'alt_text' => 'A gull.']],
        ]));

        $this->post = onlyPost();
        $this->version = postVersion($this->post);
    });

    it('changes the fingerprint when an image is added, removed, moved or described differently', function (Closure $media) {
        $this->put(route('posts.update', $this->post->id), imagePayload([$this->alpha->id => $media($this)]))->assertRedirect();

        expect(postVersion($this->post))->not->toBe($this->version);
    })->with([
        'added' => [fn ($test) => [['id' => $test->harbour->id, 'alt_text' => 'A harbour.'], ['id' => $test->gull->id, 'alt_text' => 'A gull.'], ['id' => storedImage($test->workspace)->id]]],
        'removed' => [fn ($test) => [['id' => $test->harbour->id, 'alt_text' => 'A harbour.']]],
        'all removed' => [fn () => []],
        'moved' => [fn ($test) => [['id' => $test->gull->id, 'alt_text' => 'A gull.'], ['id' => $test->harbour->id, 'alt_text' => 'A harbour.']]],
        'described differently' => [fn ($test) => [['id' => $test->harbour->id, 'alt_text' => 'A harbour at dusk.'], ['id' => $test->gull->id, 'alt_text' => 'A gull.']]],
        'description removed' => [fn ($test) => [['id' => $test->harbour->id], ['id' => $test->gull->id, 'alt_text' => 'A gull.']]],
        'replaced by another image' => [fn ($test) => [['id' => storedImage($test->workspace)->id, 'alt_text' => 'A harbour.'], ['id' => $test->gull->id, 'alt_text' => 'A gull.']]],
    ]);

    it('keeps the fingerprint when the same images are saved again', function () {
        $this->put(route('posts.update', $this->post->id), imagePayload([
            $this->alpha->id => [['id' => $this->harbour->id, 'alt_text' => 'A harbour.'], ['id' => $this->gull->id, 'alt_text' => 'A gull.']],
        ]));

        expect(postVersion($this->post))->toBe($this->version);
    });

    it('refuses an approval that was given before an image changed', function () {
        $this->put(route('posts.update', $this->post->id), imagePayload([$this->alpha->id => [['id' => $this->gull->id, 'alt_text' => 'A gull.']]]));

        $this->post(route('posts.approve', $this->post->id), ['version' => $this->version])
            ->assertSessionHasErrors('version');

        expect(stateOf($this->post))->toBe('draft');

        $this->post(route('posts.approve', $this->post->id), ['version' => postVersion($this->post)])->assertSessionHasNoErrors();

        expect(stateOf($this->post))->toBe('approved');
    });

    it('does not change the images of a post that is no longer a draft', function () {
        $this->post(route('posts.approve', $this->post->id), ['version' => $this->version]);

        $this->put(route('posts.update', $this->post->id), imagePayload([$this->alpha->id => []]));

        expect(imagesOf(variantsOf($this->post)[0]))->toBe([[$this->harbour->id, 'A harbour.'], [$this->gull->id, 'A gull.']])
            ->and(postVersion($this->post))->toBe($this->version);

        // Back in draft they can be changed, and the approval is gone.
        $this->post(route('posts.reopen', $this->post->id));
        $this->put(route('posts.update', $this->post->id), imagePayload([$this->alpha->id => []]));

        expect(imagesOf(variantsOf($this->post)[0]))->toBe([])
            ->and(DB::table('posts')->where('id', $this->post->id)->value('approved_at'))->toBeNull();
    });
});

describe('whether the images can go out', function () {
    /**
     * @param  list<array{Media, string|null}>  $images
     */
    function draftWithImages(Workspace $workspace, $channel, array $images, array $variant = []): Post
    {
        $post = postInState($workspace, PostState::Draft, [$channel]);

        DB::table('post_variants')->where('post_id', $post->id)->update(['body' => 'Fresh bread from eight.', ...$variant]);

        foreach ($images as [$media, $altText]) {
            attachImage(variantsOf($post)[0], $media, $altText);
        }

        return $post;
    }

    function problemsOf(Workspace $workspace, Post $post): array
    {
        return inWorkspace($workspace, fn () => app(PostPreconditions::class)->problems(Post::query()->findOrFail($post->id)));
    }

    function adviceOn(Workspace $workspace, Post $post): array
    {
        return inWorkspace($workspace, fn () => app(PostPreconditions::class)->advice(Post::query()->findOrFail($post->id)));
    }

    it('has nothing against images the platform takes', function () {
        $post = draftWithImages($this->workspace, $this->alpha, [[$this->harbour, 'A harbour.'], [$this->gull, 'A gull.']]);

        expect(problemsOf($this->workspace, $post))->toBe([])
            ->and(adviceOn($this->workspace, $post))->toBe([]);
    });

    it('holds back a post with more images than the platform takes', function () {
        $post = draftWithImages($this->workspace, $this->alpha, [[$this->harbour, 'a'], [$this->gull, 'b'], [storedImage($this->workspace), 'c'], [storedImage($this->workspace), 'd']]);
        $variant = variantsOf($post)[0];

        expect(problemsOf($this->workspace, $post))->toBe([
            "variants.{$variant->id}.media" => ['The text for Alpha has 4 images. Facebook takes 3 at most.'],
        ]);
    });

    it('holds back a post with images for a platform that takes none', function () {
        $this->fake->acceptingImages(max: 0);

        $post = draftWithImages($this->workspace, $this->alpha, [[$this->harbour, 'A harbour.']]);
        $variant = variantsOf($post)[0];

        expect(problemsOf($this->workspace, $post))->toBe([
            "variants.{$variant->id}.media" => ['Images cannot be published to Facebook from this instance. Take the images off the text for Alpha.'],
        ]);
    });

    it('holds back an image the platform does not take', function (Closure $capabilities, Closure $media, string $message) {
        $capabilities($this->fake);
        $media = $media($this);

        $post = draftWithImages($this->workspace, $this->alpha, [[$this->harbour, 'A harbour.'], [$media, 'The odd one.']]);
        $variant = variantsOf($post)[0];

        expect(problemsOf($this->workspace, $post))->toBe([
            "variants.{$variant->id}.media.{$media->id}" => [$message],
        ]);
    })->with([
        'a type it refuses' => [
            fn ($fake) => $fake->withCapabilities(new ChannelCapabilities(maxBodyLength: 5000, mediaTypes: ['image/jpeg'], maxMediaItems: 3, altTextLength: 100)),
            fn ($test) => $test->gull,
            'Image 2 for Alpha is of a type that Facebook does not take.',
        ],
        'a file that is too large' => [
            fn ($fake) => $fake->acceptingImages(max: 3, maxBytes: 4 * 1024 * 1024),
            fn ($test) => storedImage($test->workspace, null, ['byte_size' => 6 * 1024 * 1024]),
            'Image 2 for Alpha is 6 MB. Facebook takes 4 MB at most.',
        ],
        'too many pixels' => [
            fn ($fake) => $fake->acceptingImages(max: 3, maxPixels: 36_000_000),
            fn ($test) => storedImage($test->workspace, null, ['width' => 8000, 'height' => 6000]),
            'Image 2 for Alpha is 8000 x 6000 pixels. Facebook takes 36 megapixels at most.',
        ],
    ]);

    it('holds back a description that is longer than the platform takes', function () {
        $post = draftWithImages($this->workspace, $this->alpha, [[$this->harbour, str_repeat('a', 101)]]);
        $variant = variantsOf($post)[0];

        expect(problemsOf($this->workspace, $post))->toBe([
            "variants.{$variant->id}.media.{$this->harbour->id}.alt_text" => ['The description of image 1 for Alpha is 101 characters long. The limit is 100.'],
        ]);
    });

    it('advises a description for each image without one, and never refuses for it', function () {
        $post = draftWithImages($this->workspace, $this->alpha, [[$this->harbour, null], [$this->gull, 'A gull.'], [storedImage($this->workspace), '  ']]);
        $variant = variantsOf($post)[0];
        $third = imagesOf($variant)[2][0];

        expect(problemsOf($this->workspace, $post))->toBe([])
            ->and(adviceOn($this->workspace, $post))->toBe([
                "variants.{$variant->id}.media.{$this->harbour->id}.alt_text" => ['Image 1 for Alpha has no description. People who use a screen reader will not know what it shows.'],
                "variants.{$variant->id}.media.{$third}.alt_text" => ['Image 3 for Alpha has no description. People who use a screen reader will not know what it shows.'],
            ]);

        $this->actingAs($this->owner)->post(route('posts.approve', $post->id), ['version' => postVersion($post)])->assertSessionHasNoErrors();

        expect(stateOf($post))->toBe('approved');
    });

    it('asks for no description where the platform takes none', function () {
        $this->fake->acceptingImages(max: 3, altTextLength: null);

        $post = draftWithImages($this->workspace, $this->alpha, [[$this->harbour, null]]);

        expect(adviceOn($this->workspace, $post))->toBe([])
            ->and(problemsOf($this->workspace, $post))->toBe([]);
    });

    it('refuses the approval of a post whose images cannot go out', function () {
        $this->fake->acceptingImages(max: 1);

        $post = draftWithImages($this->workspace, $this->alpha, [[$this->harbour, 'a'], [$this->gull, 'b']]);
        $variant = variantsOf($post)[0];

        $this->actingAs($this->owner)->post(route('posts.approve', $post->id), ['version' => postVersion($post)])
            ->assertSessionHasErrors(["variants.{$variant->id}.media"]);

        expect(stateOf($post))->toBe('draft');
    });

    describe('together with a link', function () {
        it('sends the link as the last line of the text where images take the place of the preview', function () {
            $post = draftWithImages($this->workspace, $this->alpha, [[$this->harbour, 'A harbour.']], ['link' => 'https://example.com/bread', 'utm' => json_encode(['utm_source' => 'noongun'])]);
            $variant = variantsOf($post)[0];

            expect(problemsOf($this->workspace, $post))->toBe([])
                ->and(adviceOn($this->workspace, $post))->toBe([
                    "variants.{$variant->id}.link" => ['With an image, Facebook shows no preview of the link. The link for Alpha goes out as the last line of the text.'],
                ]);

            inWorkspace($this->workspace, function () use ($variant) {
                $variant = PostVariant::query()->findOrFail($variant->id);

                expect($variant->textFor($this->fake->capabilities()))->toBe("Fresh bread from eight.\n\nhttps://example.com/bread?utm_source=noongun")
                    ->and($variant->linkToSend())->toBe('https://example.com/bread?utm_source=noongun');
            });
        });

        it('measures the text with its link against the limit', function () {
            $post = draftWithImages($this->workspace, $this->alpha, [[$this->harbour, 'A harbour.']], ['body' => str_repeat('a', 4990), 'link' => 'https://example.com/bread']);
            $variant = variantsOf($post)[0];

            expect(problemsOf($this->workspace, $post))->toBe([
                "variants.{$variant->id}.body" => ['The text for Alpha is 5017 characters long with its link, which goes out as the last line of the text because the post has an image. The limit is 5000.'],
            ]);

            // Without the image the same text fits: the link keeps its preview.
            DB::table('post_variant_media')->delete();

            expect(problemsOf($this->workspace, $post))->toBe([]);
        });

        it('asks for no card for a link that is shown next to images as text', function () {
            $this->fake->withCapabilities(new ChannelCapabilities(
                maxBodyLength: 5000, mediaTypes: ['image/jpeg'], maxMediaItems: 3, linkTitleLength: 100, altTextLength: 100,
            ));

            $post = draftWithImages($this->workspace, $this->alpha, [[$this->harbour, 'A harbour.']], ['link' => 'https://example.com/bread']);
            $variant = variantsOf($post)[0];

            expect(problemsOf($this->workspace, $post))->toBe([]);

            // Without the image the card is needed, as before.
            DB::table('post_variant_media')->delete();

            expect(array_keys(problemsOf($this->workspace, $post)))->toBe(["variants.{$variant->id}.link_title"]);
        });

        it('holds back images with a link where the platform takes one or the other', function () {
            $this->fake->acceptingImages(max: 3, linkRule: MediaLinkRule::Refused);

            $post = draftWithImages($this->workspace, $this->alpha, [[$this->harbour, 'A harbour.']], ['link' => 'https://example.com/bread']);
            $variant = variantsOf($post)[0];

            expect(problemsOf($this->workspace, $post))->toBe([
                "variants.{$variant->id}.media" => ['Facebook takes a post with images or a post with a link, not both. Take the link or the images off the text for Alpha.'],
            ]);
        });

        it('leaves the link where it is on a platform that shows both', function () {
            $this->fake->acceptingImages(max: 3, linkRule: MediaLinkRule::Both);

            $post = draftWithImages($this->workspace, $this->alpha, [[$this->harbour, 'A harbour.']], ['body' => str_repeat('a', 4990), 'link' => 'https://example.com/bread']);

            expect(problemsOf($this->workspace, $post))->toBe([])
                ->and(adviceOn($this->workspace, $post))->toBe([]);
        });
    });
});

describe('what the pages get', function () {
    it('gives the editor the images of each text, the rules of each channel and where to upload', function () {
        $post = postInState($this->workspace, PostState::Draft, [$this->alpha]);
        $variant = variantsOf($post)[0];

        attachImage($variant, $this->gull, null);
        attachImage($variant, $this->harbour, 'A harbour.');

        $rules = [
            'max_items' => 3,
            'types' => ['image/jpeg', 'image/png'],
            'max_bytes' => null,
            'max_pixels' => null,
            'alt_text_length' => 100,
            'link_rule' => 'in_text',
        ];

        $this->actingAs(userWithRole($this->workspace, Role::Viewer))->get(route('posts.show', $post->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('post.variants.0.media', [
                    [
                        'id' => $this->gull->id,
                        'url' => "/media/{$this->gull->id}",
                        'thumb_url' => "/media/{$this->gull->id}/thumb",
                        'mime' => 'image/png',
                        'byte_size' => $this->gull->byte_size,
                        'width' => 40,
                        'height' => 20,
                        'alt_text' => null,
                    ],
                    [
                        'id' => $this->harbour->id,
                        'url' => "/media/{$this->harbour->id}",
                        'thumb_url' => "/media/{$this->harbour->id}/thumb",
                        'mime' => 'image/jpeg',
                        'byte_size' => $this->harbour->byte_size,
                        'width' => 40,
                        'height' => 20,
                        'alt_text' => 'A harbour.',
                    ],
                ])
                ->where('post.variants.0.media_rules', $rules)
                ->where('channels.0.media', $rules)
                ->where('post.problems', [])
                ->where('post.advice', [
                    "variants.{$variant->id}.media.{$this->gull->id}.alt_text" => ['Image 1 for Alpha has no description. People who use a screen reader will not know what it shows.'],
                ])
                ->where('uploads', [
                    'url' => '/media',
                    'field' => 'file',
                    'accept' => ['image/jpeg', 'image/png', 'image/webp'],
                    'max_bytes' => 20 * 1024 * 1024,
                    'max_megapixels' => 50,
                    'max_side' => 16000,
                    'max_per_variant' => 10,
                    'alt_text_length' => 5000,
                ]));
    });

    it('offers no more images on a text than the instance allows, and none where the platform takes none', function () {
        config(['noongun.media.max_per_variant' => 2]);

        $this->actingAs($this->owner)->get(route('posts.create'))
            ->assertInertia(fn (Assert $page) => $page->where('channels.0.media.max_items', 2)->has('uploads'));

        $this->fake->acceptingImages(max: 0);

        $this->get(route('posts.create'))
            ->assertInertia(fn (Assert $page) => $page->where('channels.0.media.max_items', 0)->where('channels.0.media.types', []));
    });

    it('gives the queue the images and the calendar the first small copy', function () {
        $post = postInState($this->workspace, PostState::Draft, [$this->alpha], now()->addDay());

        attachImage(variantsOf($post)[0], $this->harbour, 'A harbour.');
        attachImage(variantsOf($post)[0], $this->gull, null);

        $this->actingAs($this->owner)->get(route('queue.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sections.drafts.0.variants.0.media.0.thumb_url', "/media/{$this->harbour->id}/thumb")
                ->where('sections.drafts.0.variants.0.media.1.id', $this->gull->id)
                ->etc());

        $this->get(route('calendar.index', ['date' => now()->addDay()->format('Y-m-d')]))
            ->assertInertia(fn (Assert $page) => $page->where('days', function ($days) use ($post) {
                $entry = collect($days)->flatMap(fn ($day) => $day['posts'])->firstWhere('id', $post->id);

                return $entry['thumb_url'] === "/media/{$this->harbour->id}/thumb" && $entry['image_count'] === 2;
            }));
    });

    it('does not ask the database once more for every post in the queue', function () {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->owner)->get(route('queue.index'))->assertOk();

            return count(DB::getQueryLog());
        };

        foreach (range(1, 2) as $i) {
            attachImage(variantsOf(postInState($this->workspace, PostState::Draft, [$this->alpha]))[0], storedImage($this->workspace), 'An image.');
        }

        $few = $count();

        foreach (range(1, 4) as $i) {
            attachImage(variantsOf(postInState($this->workspace, PostState::Draft, [$this->alpha]))[0], storedImage($this->workspace), 'An image.');
        }

        expect($count())->toBe($few);
    });
});
