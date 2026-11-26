<?php

use App\Domain\Content\Media\MediaConfig;
use App\Domain\Content\Models\Media;
use App\Domain\Content\Models\MediaAttachment;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Helpers for everything that touches images: uploads, serving, attaching,
 * and publishing them through an adapter. Loaded with require_once.
 *
 * Every image here is made in memory with the image library the
 * application uses. Nothing is read from tests/Fixtures and nothing is
 * fetched.
 *
 *     fakeMediaDisk();                                    // first, in beforeEach
 *     $media = storedImage($workspace);                   // a row and its file
 *     attachImage($variant, $media, 'A harbour at dusk.'); // on a variant, in any state
 *     $upload = uploadOf(jpegBytes(), 'holiday.jpg');     // for POST /media
 */

/**
 * Replaces the media disk with an empty one for this test and returns it.
 */
function fakeMediaDisk(): Filesystem
{
    return Storage::fake(MediaConfig::diskName());
}

/**
 * Makes the media disk one that behaves like object storage: it reads and
 * writes, but has no path on this machine and no address. Code that asks
 * for either fails the test. Use it to prove that an upload to a platform
 * streams the file.
 */
function remoteMediaDisk(string $name = 'objects'): Filesystem
{
    config([
        "filesystems.disks.{$name}" => ['driver' => 's3', 'bucket' => 'media', 'visibility' => 'private'],
        'noongun.media.disk' => $name,
    ]);

    $local = Storage::fake($name);

    expect($local)->toBeInstanceOf(FilesystemAdapter::class);

    $disk = Mockery::mock(FilesystemAdapter::class, [$local->getDriver(), $local->getAdapter(), $local->getConfig()])->makePartial();

    foreach (['path', 'url', 'temporaryUrl', 'temporaryUploadUrl'] as $method) {
        $disk->shouldReceive($method)->andThrow(new LogicException("The media disk was asked for [{$method}]. A file on object storage has no local path and no public address: read it with readStream()."));
    }

    Storage::set($name, $disk);

    return $disk;
}

/**
 * A small JPEG. The left half is red and the right half is blue, so that a
 * test can tell which way up it is.
 *
 * @param  int|null  $orientation  an EXIF orientation, 1 to 8, to put in the file
 * @param  bool  $withLocation  whether the file carries EXIF with a GPS position and a camera name (exifMarker())
 */
function jpegBytes(int $width = 40, int $height = 20, ?int $orientation = null, bool $withLocation = false): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, imagecolorallocate($image, 220, 20, 20));
    imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, imagecolorallocate($image, 20, 20, 220));

    ob_start();
    imagejpeg($image, null, 95);
    $jpeg = (string) ob_get_clean();

    if ($orientation === null && ! $withLocation) {
        return $jpeg;
    }

    $exif = exifBlock($orientation ?? 1, $withLocation);

    // After the start-of-image marker: an APP1 segment with the EXIF block.
    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
}

/**
 * Text that is in every file made with $withLocation, and must be in no
 * stored file.
 */
function exifMarker(): string
{
    return 'NOONGUN-TEST-CAMERA';
}

/**
 * An EXIF block as a camera writes it: orientation, the camera name and,
 * when asked, a GPS directory with a position near Signal Hill.
 */
function exifBlock(int $orientation, bool $withLocation): string
{
    $make = exifMarker()."\0";
    $entries = $withLocation ? 3 : 2;
    $directoryEnd = 8 + 2 + $entries * 12 + 4;
    $gpsOffset = $directoryEnd + strlen($make);

    $tiff = 'MM'.pack('n', 42).pack('N', 8).pack('n', $entries)
        .pack('nnN', 0x010F, 2, strlen($make)).pack('N', $directoryEnd)
        .pack('nnN', 0x0112, 3, 1).pack('n', $orientation)."\0\0";

    if ($withLocation) {
        $tiff .= pack('nnN', 0x8825, 4, 1).pack('N', $gpsOffset);
    }

    $tiff .= pack('N', 0).$make;

    if ($withLocation) {
        $values = $gpsOffset + 2 + 4 * 12 + 4;

        $tiff .= pack('n', 4)
            .pack('nnN', 0x0001, 2, 2)."S\0\0\0"
            .pack('nnN', 0x0002, 5, 3).pack('N', $values)
            .pack('nnN', 0x0003, 2, 2)."E\0\0\0"
            .pack('nnN', 0x0004, 5, 3).pack('N', $values + 24)
            .pack('N', 0)
            .pack('N6', 33, 1, 55, 1, 1, 1)
            .pack('N6', 18, 1, 24, 1, 1, 1);
    }

    return "Exif\0\0".$tiff;
}

/**
 * A JPEG of random noise, which does not compress: for the limit in bytes.
 */
function noisyJpegBytes(int $width = 400, int $height = 400): string
{
    $image = imagecreatetruecolor($width, $height);

    for ($x = 0; $x < $width; $x++) {
        for ($y = 0; $y < $height; $y++) {
            imagesetpixel($image, $x, $y, random_int(0, 0xFFFFFF));
        }
    }

    ob_start();
    imagejpeg($image, null, 100);

    return (string) ob_get_clean();
}

/**
 * A small PNG, red, with a fully transparent top left corner when asked.
 */
function pngBytes(int $width = 40, int $height = 20, bool $transparent = true): string
{
    $image = transparentCanvas($width, $height, $transparent);

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * A small WebP, red, lossless and with a transparent corner when asked.
 */
function webpBytes(int $width = 40, int $height = 20, bool $transparent = false): string
{
    $image = transparentCanvas($width, $height, $transparent);

    ob_start();
    imagewebp($image, null, $transparent ? IMG_WEBP_LOSSLESS : 90);

    return (string) ob_get_clean();
}

function transparentCanvas(int $width, int $height, bool $transparent): GdImage
{
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 220, 20, 20));

    if ($transparent) {
        imagefilledrectangle($image, 0, 0, intdiv($width, 4), intdiv($height, 4), imagecolorallocatealpha($image, 0, 0, 0, 127));
    }

    return $image;
}

function gifBytes(): string
{
    $image = imagecreatetruecolor(10, 10);

    ob_start();
    imagegif($image);

    return (string) ob_get_clean();
}

/**
 * An SVG that would run a script if a browser were given it as a page.
 */
function svgBytes(): string
{
    return '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(document.domain)</script><rect width="10" height="10"/></svg>';
}

/**
 * Not an image at all, whatever name and type it is sent with.
 */
function scriptBytes(): string
{
    return "<?php echo 'noongun-test-payload'; ?>\n<script>alert(1)</script>\n";
}

/**
 * A real JPEG that is also something else: a script after the end of the
 * image and one in a comment segment. It decodes as an image. Nothing of
 * payloadMarker() may be in what is stored.
 */
function polyglotJpegBytes(): string
{
    $jpeg = jpegBytes();
    $payload = '<?php system($_GET["c"]); ?><script>'.payloadMarker().'</script>';

    return substr($jpeg, 0, 2)."\xFF\xFE".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2).$payload;
}

function payloadMarker(): string
{
    return 'noongun-test-payload';
}

/**
 * The start of a PNG that says it is enormous. A few bytes on the wire,
 * gigabytes of memory if it were decoded. It must be refused from its
 * header.
 */
function pixelBombBytes(int $width = 30000, int $height = 30000): string
{
    $header = 'IHDR'.pack('NN', $width, $height)."\x08\x06\x00\x00\x00";
    $data = 'IDAT'.gzcompress(str_repeat("\0", 1024));
    $end = 'IEND';

    return "\x89PNG\r\n\x1a\n"
        .pack('N', 13).$header.pack('N', crc32($header))
        .pack('N', strlen($data) - 4).$data.pack('N', crc32($data))
        .pack('N', 0).$end.pack('N', crc32($end));
}

/**
 * The bytes as an upload, with the name and the type a browser would send.
 * Both are whatever the test says: the application must not believe them.
 */
function uploadOf(string $bytes, string $name = 'photo.jpg', ?string $mimeType = null): UploadedFile
{
    $file = UploadedFile::fake()->createWithContent($name, $bytes);

    return $mimeType !== null ? $file->mimeType($mimeType) : $file;
}

/**
 * A stored image in the workspace: the row and a real file on the media
 * disk, without going through an upload. Call fakeMediaDisk() first.
 *
 * @param  string|null  $bytes  a JPEG or PNG; a small JPEG when left out
 * @param  array<string, mixed>  $attributes  to say something else than the file does, for example a size over a limit
 */
function storedImage(Workspace $workspace, ?string $bytes = null, array $attributes = []): Media
{
    $bytes ??= jpegBytes();
    $info = getimagesizefromstring($bytes);

    expect($info)->not->toBeFalse();

    $png = $info[2] === IMAGETYPE_PNG;
    $name = sprintf('w%d/test/%s', $workspace->id, bin2hex(random_bytes(20)));
    $path = $name.($png ? '.png' : '.jpg');
    $thumbPath = $name.'_thumb.jpg';

    $disk = MediaConfig::disk();
    $disk->put($path, $bytes);
    $disk->put($thumbPath, jpegBytes(8, 4));

    return inWorkspace($workspace, fn () => Media::factory()->create([
        'disk' => MediaConfig::diskName(),
        'path' => $path,
        'thumb_path' => $thumbPath,
        'mime' => $png ? 'image/png' : 'image/jpeg',
        'byte_size' => strlen($bytes),
        'width' => $info[0],
        'height' => $info[1],
        'content_hash' => hash('sha256', $bytes),
        ...$attributes,
    ]));
}

/**
 * Puts an image on a variant, after the ones it has. Written directly, so
 * it works on a post in any state; application code attaches through
 * SaveDraft.
 */
function attachImage(PostVariant $variant, Media $media, ?string $altText = null): MediaAttachment
{
    $position = (int) DB::table('post_variant_media')->where('post_variant_id', $variant->id)->max('position') + 1;

    $id = DB::table('post_variant_media')->insertGetId([
        'workspace_id' => $variant->workspace_id,
        'post_variant_id' => $variant->id,
        'media_id' => $media->id,
        'position' => $position,
        'alt_text' => $altText,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return MediaAttachment::query()->withoutGlobalScopes()->findOrFail($id);
}

/**
 * The stored file of an image, or its small copy, as bytes.
 */
function storedBytes(Media $media, bool $thumbnail = false): string
{
    return (string) MediaConfig::disk($media->disk)->get($thumbnail ? (string) $media->thumb_path : $media->path);
}

/**
 * The colour of one pixel of an image given as bytes, as [red, green, blue, alpha].
 *
 * @return array{int, int, int, int}
 */
function pixelOf(string $bytes, int $x, int $y): array
{
    $image = imagecreatefromstring($bytes);

    expect($image)->not->toBeFalse();

    $colour = imagecolorsforindex($image, (int) imagecolorat($image, $x, $y));

    return [$colour['red'], $colour['green'], $colour['blue'], $colour['alpha']];
}

/**
 * The ids of the images on a variant in their order, with their
 * alternative texts, read without a workspace.
 *
 * @return list<array{int, string|null}>
 */
function imagesOf(PostVariant $variant): array
{
    return DB::table('post_variant_media')
        ->where('post_variant_id', $variant->id)
        ->orderBy('position')
        ->orderBy('id')
        ->get()
        ->map(fn (object $row): array => [(int) $row->media_id, $row->alt_text])
        ->all();
}
