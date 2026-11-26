<?php

use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Exceptions\MediaFileMissing;
use App\Domain\Content\Exceptions\PostNotEditable;
use App\Domain\Content\Models\Media;
use App\Domain\Content\Models\MediaAttachment;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostState;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';
require_once __DIR__.'/../../Support/media.php';

describe('a stored image', function () {
    itIsolatesWorkspaces(fn () => Media::factory()->create());
});

describe('an image on a variant', function () {
    itIsolatesWorkspaces(fn () => MediaAttachment::factory()->create());
});

beforeEach(function () {
    fakeMediaDisk();
    $this->workspace = Workspace::factory()->create();
});

it('reads its file as a stream from its disk', function () {
    $media = storedImage($this->workspace, pngBytes());

    $stream = $media->readStream();

    expect(stream_get_contents($stream))->toBe(pngBytes())
        ->and($media->fileExists())->toBeTrue()
        ->and($media->pixels())->toBe(800)
        ->and($media->fileName())->toBe("image-{$media->id}.png");

    fclose($stream);
});

it('says so when its file is gone', function () {
    $media = inWorkspace($this->workspace, fn () => Media::factory()->create());

    expect($media->fileExists())->toBeFalse()
        ->and(fn () => $media->readStream())->toThrow(MediaFileMissing::class);
});

it('keeps an image and the variant it is on in one workspace', function () {
    $other = Workspace::factory()->create();
    $foreign = inWorkspace($other, fn () => Media::factory()->create());
    $variant = inWorkspace($this->workspace, fn () => PostVariant::factory()->create());

    // Through the model: the image is not found in this workspace.
    expect(fn () => inWorkspace($this->workspace, fn () => $variant->attachments()->create(['media_id' => $foreign->id, 'position' => 1])))
        ->toThrow(QueryException::class);

    // And the database refuses the pair whoever writes it.
    expect(fn () => DB::table('post_variant_media')->insert([
        'workspace_id' => $other->id, 'post_variant_id' => $variant->id, 'media_id' => $foreign->id, 'position' => 1,
    ]))->toThrow(QueryException::class);
});

it('puts an image on a variant once', function () {
    inWorkspace($this->workspace, function () {
        $attachment = MediaAttachment::factory()->create();

        expect(fn () => MediaAttachment::factory()->create([
            'post_variant_id' => $attachment->post_variant_id,
            'media_id' => $attachment->media_id,
            'position' => 2,
        ]))->toThrow(QueryException::class);
    });
});

it('gives a variant its images in their order', function () {
    inWorkspace($this->workspace, function () {
        $variant = PostVariant::factory()->create();
        $second = MediaAttachment::factory()->for($variant, 'variant')->create(['position' => 2]);
        $first = MediaAttachment::factory()->for($variant, 'variant')->described('First.')->create(['position' => 1]);

        $images = PostVariant::query()->findOrFail($variant->id)->images();

        expect($images->modelKeys())->toBe([$first->id, $second->id])
            ->and($images->first()?->image()->id)->toBe($first->media_id)
            ->and($images->first()?->alt_text)->toBe('First.')
            ->and($images->first()?->relationLoaded('media'))->toBeTrue();
    });
});

it('cannot delete an image that is on a variant', function () {
    inWorkspace($this->workspace, function () {
        $attachment = MediaAttachment::factory()->create();

        expect(fn () => $attachment->media?->delete())->toThrow(QueryException::class);
    });
});

it('goes with its workspace when the workspace is deleted', function () {
    inWorkspace($this->workspace, fn () => MediaAttachment::factory()->create());

    DB::table('workspaces')->where('id', $this->workspace->id)->delete();

    expect(DB::table('media')->count())->toBe(0)
        ->and(DB::table('post_variant_media')->count())->toBe(0);
});

it('takes the attachments away with the variant and leaves the image', function () {
    inWorkspace($this->workspace, function () {
        $attachment = MediaAttachment::factory()->create();

        $attachment->variant?->delete();

        expect(MediaAttachment::query()->count())->toBe(0)
            ->and(Media::query()->whereKey($attachment->media_id)->exists())->toBeTrue();
    });
});

describe('locked with the text', function () {
    it('can be added, changed and removed while the post is a draft', function () {
        inWorkspace($this->workspace, function () {
            $attachment = MediaAttachment::factory()->create();

            $attachment->update(['alt_text' => 'A harbour.', 'position' => 3]);

            expect($attachment->refresh()->alt_text)->toBe('A harbour.');

            $attachment->delete();

            expect(MediaAttachment::query()->count())->toBe(0);
        });
    });

    it('cannot be added, changed or removed outside the draft state', function (PostState $state) {
        $post = postInState($this->workspace, PostState::Draft);
        $variant = variantsOf($post)[0];
        $attachment = attachImage($variant, storedImage($this->workspace), 'As approved.');
        $spare = storedImage($this->workspace);

        DB::table('posts')->where('id', $post->id)->update(['state' => $state->value, 'approved_at' => now(), 'approved_by' => userWithRole($this->workspace, Role::Owner)->id, 'scheduled_at' => now()->addHour()]);

        inWorkspace($this->workspace, function () use ($variant, $attachment, $spare) {
            $attachment = MediaAttachment::query()->findOrFail($attachment->id);

            expect(fn () => $attachment->update(['alt_text' => 'Changed.']))->toThrow(PostNotEditable::class)
                ->and(fn () => $attachment->fresh()?->update(['position' => 5]))->toThrow(PostNotEditable::class)
                ->and(fn () => $attachment->fresh()?->delete())->toThrow(PostNotEditable::class)
                ->and(fn () => PostVariant::query()->findOrFail($variant->id)->attachments()->create(['media_id' => $spare->id, 'position' => 2]))->toThrow(PostNotEditable::class);
        });

        expect(imagesOf($variant))->toBe([[$attachment->media_id, 'As approved.']]);
    })->with([PostState::Approved, PostState::Scheduled, PostState::Publishing, PostState::Failed]);

    it('cannot be changed on a variant that is published, even in a draft', function () {
        $post = postInState($this->workspace, PostState::Draft);
        $variant = variantsOf($post)[0];
        $attachment = attachImage($variant, storedImage($this->workspace));

        DB::table('post_variants')->where('id', $variant->id)->update(['external_id' => 'ext_1', 'published_at' => now()]);

        inWorkspace($this->workspace, function () use ($attachment) {
            $attachment = MediaAttachment::query()->findOrFail($attachment->id);

            expect(fn () => $attachment->update(['alt_text' => 'Changed.']))->toThrow(PostNotEditable::class)
                ->and(fn () => $attachment->fresh()?->delete())->toThrow(PostNotEditable::class);
        });
    });

    it('cannot be moved to another variant or pointed at another image', function () {
        inWorkspace($this->workspace, function () {
            $attachment = MediaAttachment::factory()->create();
            $other = MediaAttachment::factory()->create();

            expect(fn () => $attachment->update(['media_id' => $other->media_id]))->toThrow(PostNotEditable::class)
                ->and(fn () => $attachment->fresh()?->update(['post_variant_id' => $other->post_variant_id]))->toThrow(PostNotEditable::class);
        });
    });
});

describe('what the platform holds of it', function () {
    it('is written only through markUploaded and forgetUpload', function () {
        inWorkspace($this->workspace, function () {
            $attachment = MediaAttachment::factory()->create();

            expect($attachment->isUploaded())->toBeFalse()
                ->and(fn () => $attachment->forceFill(['external_id' => 'x', 'uploaded_at' => now()])->save())->toThrow(PostNotEditable::class);

            $attachment = $attachment->fresh();
            $attachment->markUploaded('photo_1');

            expect($attachment->external_id)->toBe('photo_1')
                ->and($attachment->isUploaded())->toBeTrue()
                ->and($attachment->uploaded_at)->not->toBeNull();

            $attachment->forgetUpload();

            expect($attachment->external_id)->toBeNull()
                ->and($attachment->uploaded_at)->toBeNull()
                ->and($attachment->isUploaded())->toBeFalse();
        });
    });

    it('can be recorded in any state of the post, because publishing does it', function () {
        $post = postInState($this->workspace, PostState::Publishing);
        $attachment = attachImage(variantsOf($post)[0], storedImage($this->workspace));

        inWorkspace($this->workspace, function () use ($attachment) {
            expect(MediaAttachment::query()->findOrFail($attachment->id)->markUploaded('photo_9')->external_id)->toBe('photo_9');
        });
    });

    it('counts for as long as the platform keeps an unused upload', function () {
        $this->travelTo('2026-11-26 12:00:00');

        inWorkspace($this->workspace, function () {
            $attachment = MediaAttachment::factory()->uploaded()->create();

            $this->travel(59)->minutes();

            expect($attachment->isUploaded(3600))->toBeTrue()
                ->and($attachment->isUploaded())->toBeTrue();

            $this->travel(2)->minutes();

            expect($attachment->isUploaded(3600))->toBeFalse()
                ->and($attachment->isUploaded())->toBeTrue();
        });
    });

    it('is forgotten when the alternative text changes, and kept when only the order does', function () {
        inWorkspace($this->workspace, function () {
            $attachment = MediaAttachment::factory()->described('Before.')->uploaded('photo_1')->create();

            $attachment->update(['position' => 4]);

            expect($attachment->refresh()->external_id)->toBe('photo_1');

            $attachment->update(['alt_text' => 'After.']);

            expect($attachment->refresh()->external_id)->toBeNull()
                ->and($attachment->alt_text)->toBe('After.');
        });
    });

    it('is forgotten when the variant moves to another channel', function () {
        inWorkspace($this->workspace, function () {
            $attachment = MediaAttachment::factory()->uploaded('photo_1')->create();
            $channel = Channel::factory()->create();

            $attachment->variant?->update(['channel_id' => $channel->id]);

            expect($attachment->refresh()->external_id)->toBeNull();
        });
    });

    it('is stored whole or not at all', function () {
        $attachment = inWorkspace($this->workspace, fn () => MediaAttachment::factory()->create());

        expect(fn () => DB::table('post_variant_media')->where('id', $attachment->id)->update(['external_id' => 'x']))
            ->toThrow(QueryException::class);
    });
});
