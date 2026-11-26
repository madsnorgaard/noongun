<?php

use App\Domain\Content\Media\JpegOrientation;
use App\Domain\Content\Media\MediaConfig;
use App\Domain\Content\Models\Media;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/media.php';

beforeEach(function () {
    $this->disk = fakeMediaDisk();
    $this->workspace = Workspace::factory()->create();
    $this->editor = userWithRole($this->workspace, Role::Editor);
});

/**
 * The one stored image of the test, read without a workspace.
 */
function onlyMedia(): Media
{
    return Media::query()->withoutGlobalScopes()->sole();
}

it('has an image library that reads and writes WebP, JPEG and PNG', function () {
    $support = gd_info();

    expect($support['WebP Support'] ?? false)->toBeTrue()
        ->and($support['JPEG Support'] ?? false)->toBeTrue()
        ->and($support['PNG Support'] ?? false)->toBeTrue()
        ->and(imagecreatefromstring(webpBytes(12, 8)))->toBeInstanceOf(GdImage::class)
        ->and(getimagesizefromstring(webpBytes(12, 8))[2] ?? null)->toBe(IMAGETYPE_WEBP);
});

describe('what is stored', function () {
    it('stores an upload as an image it wrote itself', function (Closure $bytes, string $name, string $mime, string $extension) {
        $response = $this->actingAs($this->editor)->postJson(route('media.store'), ['file' => uploadOf($bytes(), $name)]);

        $media = onlyMedia();

        $response->assertCreated()->assertExactJson([
            'id' => $media->id,
            'url' => "/media/{$media->id}",
            'thumb_url' => "/media/{$media->id}/thumb",
            'mime' => $mime,
            'byte_size' => $media->byte_size,
            'width' => 40,
            'height' => 20,
        ]);

        $stored = storedBytes($media);

        expect($media->workspace_id)->toBe($this->workspace->id)
            ->and($media->uploaded_by)->toBe($this->editor->id)
            ->and($media->disk)->toBe('media')
            ->and($media->mime)->toBe($mime)
            ->and($media->path)->toMatch('#^w'.$this->workspace->id.'/\d{4}/\d{2}/[0-9a-f]{40}\.'.$extension.'$#')
            ->and($media->thumb_path)->toMatch('#^w'.$this->workspace->id.'/\d{4}/\d{2}/[0-9a-f]{40}_thumb\.jpg$#')
            ->and($media->byte_size)->toBe(strlen($stored))
            ->and($media->content_hash)->toBe(hash('sha256', $stored))
            ->and(getimagesizefromstring($stored)['mime'] ?? null)->toBe($mime)
            ->and([$media->width, $media->height])->toBe([40, 20])
            ->and(getimagesizefromstring(storedBytes($media, thumbnail: true))['mime'] ?? null)->toBe('image/jpeg')
            ->and($this->disk->allFiles())->toHaveCount(2);
    })->with([
        'a JPEG' => [fn () => jpegBytes(), 'photo.jpg', 'image/jpeg', 'jpg'],
        'a PNG' => [fn () => pngBytes(), 'drawing.png', 'image/png', 'png'],
        'a WebP without transparency, as a JPEG' => [fn () => webpBytes(), 'photo.webp', 'image/jpeg', 'jpg'],
        'a WebP with transparency, as a PNG' => [fn () => webpBytes(transparent: true), 'logo.webp', 'image/png', 'png'],
    ]);

    it('keeps the transparency of a PNG and of a WebP', function (Closure $bytes) {
        $this->actingAs($this->editor)->postJson(route('media.store'), ['file' => uploadOf($bytes())])->assertCreated();

        $stored = storedBytes(onlyMedia());

        expect(pixelOf($stored, 1, 1)[3])->toBe(127)
            ->and(pixelOf($stored, 30, 15))->toBe([220, 20, 20, 0])
            // The small copy is a JPEG: transparent becomes white.
            ->and(pixelOf(storedBytes(onlyMedia(), thumbnail: true), 1, 1)[0])->toBeGreaterThan(240);
    })->with([
        'PNG' => [fn () => pngBytes()],
        'WebP' => [fn () => webpBytes(transparent: true)],
    ]);

    it('decides what a file is from its content, not from its name or the type the browser gave', function () {
        $this->actingAs($this->editor)
            ->postJson(route('media.store'), ['file' => uploadOf(pngBytes(), 'page.html', 'text/html')])
            ->assertCreated()
            ->assertJsonPath('mime', 'image/png');

        expect(onlyMedia()->path)->toEndWith('.png');
    });

    it('keeps nothing of the name the file was sent with', function () {
        $this->actingAs($this->editor)
            ->postJson(route('media.store'), ['file' => uploadOf(jpegBytes(), '../../secret-client-name.php.jpg')])
            ->assertCreated();

        $media = onlyMedia();

        expect(json_encode($media->getAttributes()))->not->toContain('secret-client-name')
            ->and(implode(' ', $this->disk->allFiles()))->not->toContain('secret-client-name')
            ->and($media->fileName())->toBe("image-{$media->id}.jpg");
    });

    it('drops EXIF, with the place the photo was taken', function () {
        $sent = jpegBytes(withLocation: true);

        expect($sent)->toContain('Exif')->toContain(exifMarker());

        $this->actingAs($this->editor)->postJson(route('media.store'), ['file' => uploadOf($sent)])->assertCreated();

        foreach ([storedBytes(onlyMedia()), storedBytes(onlyMedia(), thumbnail: true)] as $stored) {
            expect($stored)->not->toContain('Exif')
                ->not->toContain(exifMarker())
                ->not->toContain("\xFF\xE1");
        }
    });

    it('drops a payload that hides in a real image', function () {
        $sent = polyglotJpegBytes();

        expect($sent)->toContain(payloadMarker())
            ->and(getimagesizefromstring($sent))->not->toBeFalse();

        $this->actingAs($this->editor)->postJson(route('media.store'), ['file' => uploadOf($sent, 'photo.jpg')])->assertCreated();

        expect(storedBytes(onlyMedia()))->not->toContain(payloadMarker())->not->toContain('<?php')
            ->and(storedBytes(onlyMedia(), thumbnail: true))->not->toContain(payloadMarker());
    });

    it('turns a photo upright before the note about its orientation is dropped', function (int $orientation, int $width, int $height, array $red, array $blue) {
        // Sent 40 wide and 20 high, red on the left and blue on the right.
        $this->actingAs($this->editor)
            ->postJson(route('media.store'), ['file' => uploadOf(jpegBytes(orientation: $orientation))])
            ->assertCreated()
            ->assertJsonPath('width', $width)
            ->assertJsonPath('height', $height);

        $stored = storedBytes(onlyMedia());

        expect(JpegOrientation::fromBytes($stored))->toBe(1);

        [$r, , $b] = pixelOf($stored, ...$red);
        expect($r)->toBeGreaterThan(150)->and($b)->toBeLessThan(100);

        [$r, , $b] = pixelOf($stored, ...$blue);
        expect($b)->toBeGreaterThan(150)->and($r)->toBeLessThan(100);
    })->with([
        'as it is' => [1, 40, 20, [5, 10], [35, 10]],
        'mirrored' => [2, 40, 20, [35, 10], [5, 10]],
        'upside down' => [3, 40, 20, [35, 10], [5, 10]],
        'upside down and mirrored' => [4, 40, 20, [5, 10], [35, 10]],
        'on its side, camera turned left' => [6, 20, 40, [10, 5], [10, 35]],
        'on its side, camera turned right' => [8, 20, 40, [10, 35], [10, 5]],
        'transposed' => [5, 20, 40, [10, 5], [10, 35]],
        'transversed' => [7, 20, 40, [10, 35], [10, 5]],
    ]);

    it('reads the orientation of a file and of nothing else', function () {
        expect(JpegOrientation::fromBytes(jpegBytes(orientation: 6)))->toBe(6)
            ->and(JpegOrientation::fromBytes(jpegBytes(orientation: 8, withLocation: true)))->toBe(8)
            ->and(JpegOrientation::fromBytes(jpegBytes()))->toBe(1)
            ->and(JpegOrientation::fromBytes(jpegBytes(orientation: 9)))->toBe(1)
            ->and(JpegOrientation::fromBytes(pngBytes()))->toBe(1)
            ->and(JpegOrientation::fromBytes(''))->toBe(1)
            ->and(JpegOrientation::fromBytes("\xFF\xD8\xFF\xE1\xFF\xFFExif\0\0MM"))->toBe(1)
            ->and(JpegOrientation::fromBytes(substr(jpegBytes(orientation: 6), 0, 30)))->toBe(1);
    });

    it('scales an image down to the stored size and makes a small copy for lists', function () {
        config(['noongun.media.stored_side' => 100, 'noongun.media.thumbnail_side' => 32]);

        $this->actingAs($this->editor)
            ->postJson(route('media.store'), ['file' => uploadOf(jpegBytes(400, 200))])
            ->assertCreated()
            ->assertJsonPath('width', 100)
            ->assertJsonPath('height', 50);

        $thumb = getimagesizefromstring(storedBytes(onlyMedia(), thumbnail: true));

        expect([$thumb[0] ?? null, $thumb[1] ?? null])->toBe([32, 16]);
    });

    it('does not enlarge a small image', function () {
        $this->actingAs($this->editor)
            ->postJson(route('media.store'), ['file' => uploadOf(jpegBytes(12, 8))])
            ->assertCreated()
            ->assertJsonPath('width', 12)
            ->assertJsonPath('height', 8);
    });
});

describe('what is refused', function () {
    it('refuses everything that is not a JPEG, PNG or WebP image', function (Closure $bytes, string $name, ?string $mimeType, string $message) {
        $this->actingAs($this->editor)
            ->postJson(route('media.store'), ['file' => uploadOf($bytes(), $name, $mimeType)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => $message]);

        expect(Media::query()->withoutGlobalScopes()->count())->toBe(0)
            ->and($this->disk->allFiles())->toBe([]);
    })->with([
        'a script with the name of an image' => [fn () => scriptBytes(), 'photo.jpg', 'image/jpeg', 'not a JPEG, PNG or WebP image'],
        'an SVG' => [fn () => svgBytes(), 'logo.svg', 'image/svg+xml', 'not a JPEG, PNG or WebP image'],
        'an SVG that says it is a PNG' => [fn () => svgBytes(), 'logo.png', 'image/png', 'not a JPEG, PNG or WebP image'],
        'a GIF' => [fn () => gifBytes(), 'clip.gif', 'image/gif', 'GIF images are not supported'],
        'a GIF that says it is a JPEG' => [fn () => gifBytes(), 'clip.jpg', 'image/jpeg', 'GIF images are not supported'],
        'an empty file' => [fn () => '', 'photo.jpg', 'image/jpeg', 'not a JPEG, PNG or WebP image'],
        'a text file' => [fn () => 'Just some words.', 'notes.txt', 'text/plain', 'not a JPEG, PNG or WebP image'],
        'a JPEG cut off after its header' => [fn () => substr(jpegBytes(), 0, 3), 'photo.jpg', 'image/jpeg', 'not a JPEG, PNG or WebP image'],
    ]);

    it('refuses an image with more pixels than the limit from its header, without decoding it', function () {
        $bomb = pixelBombBytes(30000, 30000);

        expect(strlen($bomb))->toBeLessThan(200);

        $before = memory_get_peak_usage();

        $this->actingAs($this->editor)
            ->postJson(route('media.store'), ['file' => uploadOf($bomb, 'photo.png')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'The image is 30000 x 30000 pixels. The limit is 50 megapixels and 16000 pixels on a side.']);

        // 900 megapixels would take gigabytes. Nothing near that was used.
        expect(memory_get_peak_usage() - $before)->toBeLessThan(50 * 1024 * 1024)
            ->and($this->disk->allFiles())->toBe([]);
    });

    it('applies the limits for pixels and for one side', function (array $config, int $width, int $height, bool $accepted) {
        config($config);

        $response = $this->actingAs($this->editor)->postJson(route('media.store'), ['file' => uploadOf(jpegBytes($width, $height))]);

        $accepted ? $response->assertCreated() : $response->assertUnprocessable()->assertJsonValidationErrors('file');
    })->with([
        'at the pixel limit' => [['noongun.media.max_megapixels' => 0.1], 400, 250, true],
        'over the pixel limit' => [['noongun.media.max_megapixels' => 0.1], 401, 250, false],
        'at the side limit' => [['noongun.media.max_side' => 120], 120, 10, true],
        'over the side limit' => [['noongun.media.max_side' => 120], 10, 121, false],
    ]);

    it('refuses a file over the limit in bytes', function () {
        config(['noongun.media.max_bytes' => 20_000]);

        $large = noisyJpegBytes(200, 200);

        expect(strlen($large))->toBeGreaterThan(20_000);

        $this->actingAs($this->editor)
            ->postJson(route('media.store'), ['file' => uploadOf($large)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'The file is too large']);

        expect($this->disk->allFiles())->toBe([]);
    });

    it('never offers more than PHP itself accepts', function () {
        config(['noongun.media.max_bytes' => 10 * 1024 ** 3]);

        expect(MediaConfig::maxBytes())->toBeLessThan(10 * 1024 ** 3)
            ->and(MediaConfig::maxBytes())->toBeGreaterThan(1024 ** 2);
    });

    it('takes one image at a time', function () {
        $this->actingAs($this->editor)
            ->postJson(route('media.store'), ['file' => [uploadOf(jpegBytes()), uploadOf(jpegBytes())]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->actingAs($this->editor)->postJson(route('media.store'), [])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->actingAs($this->editor)->postJson(route('media.store'), ['file' => 'photo.jpg'])->assertUnprocessable();

        expect($this->disk->allFiles())->toBe([]);
    });

    it('refuses to store images on the public disk', function () {
        config(['noongun.media.disk' => 'public']);

        expect(fn () => MediaConfig::diskName())->toThrow(LogicException::class, 'private');

        config(['noongun.media.disk' => 'nowhere']);

        expect(fn () => MediaConfig::diskName())->toThrow(LogicException::class, 'not defined');
    });
});

describe('who may upload', function () {
    it('lets owners and editors upload, and no viewer', function (Role $role, int $status) {
        $this->actingAs(userWithRole($this->workspace, $role))
            ->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])
            ->assertStatus($status);

        expect(Media::query()->withoutGlobalScopes()->count())->toBe($status === 201 ? 1 : 0);
    })->with([
        'owner' => [Role::Owner, 201],
        'editor' => [Role::Editor, 201],
        'viewer' => [Role::Viewer, 403],
    ]);

    it('refuses a visitor who is not signed in, and a user without a workspace', function () {
        $this->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])->assertUnauthorized();
        $this->post(route('media.store'), ['file' => uploadOf(jpegBytes())])->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])
            ->assertForbidden();

        expect($this->disk->allFiles())->toBe([]);
    });

    it('stores an upload in the workspace of the person who uploads', function () {
        $other = Workspace::factory()->create();

        $this->actingAs(userWithRole($other, Role::Owner))
            ->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])
            ->assertCreated();

        expect(onlyMedia()->workspace_id)->toBe($other->id)
            ->and(onlyMedia()->path)->toStartWith("w{$other->id}/");
    });
});

describe('limits on how much', function () {
    it('holds a workspace to its storage quota', function () {
        $this->actingAs($this->editor)->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])->assertCreated();

        $used = onlyMedia()->byte_size;

        // Room for exactly one more of the same.
        config(['noongun.media.quota_bytes' => $used * 2]);

        $this->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])->assertCreated();

        $this->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'has used its']);

        expect(Media::query()->withoutGlobalScopes()->count())->toBe(2)
            ->and($this->disk->allFiles())->toHaveCount(4);
    });

    it('refuses an upload that would go over the quota and leaves no file behind', function () {
        $this->actingAs($this->editor)->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])->assertCreated();

        config(['noongun.media.quota_bytes' => onlyMedia()->byte_size + 10]);

        $this->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])->assertUnprocessable();

        expect($this->disk->allFiles())->toHaveCount(2);
    });

    it('counts the quota for each workspace by itself', function () {
        $this->actingAs($this->editor)->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])->assertCreated();

        config(['noongun.media.quota_bytes' => onlyMedia()->byte_size]);

        $this->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])->assertUnprocessable();

        $this->actingAs(userWithRole(Workspace::factory()->create(), Role::Owner))
            ->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])
            ->assertCreated();
    });

    it('has no quota when it is set to zero', function () {
        config(['noongun.media.quota_bytes' => 0]);

        expect(MediaConfig::quotaBytes())->toBeNull();

        $this->actingAs($this->editor)->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])->assertCreated();
    });

    it('limits how many uploads a user makes in a minute', function () {
        config(['noongun.media.uploads_per_minute' => 2]);

        $this->actingAs($this->editor);

        $this->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])->assertCreated();
        // A refused upload counts as well.
        $this->postJson(route('media.store'), ['file' => uploadOf(scriptBytes())])->assertUnprocessable();
        $this->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])->assertTooManyRequests();

        // Another user has their own count.
        $this->actingAs(userWithRole($this->workspace, Role::Owner))
            ->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])
            ->assertCreated();

        $this->travel(61)->seconds();

        $this->actingAs($this->editor)->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])->assertCreated();
    });
});

describe('where the file goes', function () {
    it('writes to whatever disk is configured, without a local path', function () {
        $objects = remoteMediaDisk();

        $response = $this->actingAs($this->editor)
            ->postJson(route('media.store'), ['file' => uploadOf(jpegBytes(withLocation: true))])
            ->assertCreated();

        $media = onlyMedia();

        expect($media->disk)->toBe('objects')
            ->and($objects->allFiles())->toHaveCount(2)
            ->and($this->disk->allFiles())->toBe([])
            ->and(storedBytes($media))->not->toContain(exifMarker());

        $this->get($response->json('url'))->assertOk();
        $this->get($response->json('thumb_url'))->assertOk();
    });

    it('reads an image from the disk it was stored on, whatever the setting is now', function () {
        $this->actingAs($this->editor)->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])->assertCreated();

        $media = onlyMedia();

        config(['filesystems.disks.objects' => ['driver' => 's3', 'visibility' => 'private'], 'noongun.media.disk' => 'objects']);
        Storage::fake('objects');

        $this->get("/media/{$media->id}")->assertOk();
    });

    it('removes the file again when the row cannot be written', function () {
        DB::statement('ALTER TABLE media ADD CONSTRAINT media_test_refuses_all CHECK (width < 0)');

        $this->actingAs($this->editor)
            ->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])
            ->assertServerError();

        expect($this->disk->allFiles())->toBe([]);
    });
});
