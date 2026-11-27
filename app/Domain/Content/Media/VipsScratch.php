<?php

namespace App\Domain\Content\Media;

use RuntimeException;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Throwable;

/**
 * The folder one upload is worked on in, and the time it has left.
 *
 * Each step of VipsImageProcessor is one run of vips that reads a file and
 * writes the next. The files between the steps are in the format of libvips
 * itself (.v): the plain pixels, which cost nothing to read and write. They
 * are here, under a name nobody else knows, and are gone when the upload is
 * done. A folder that a killed process left behind is removed by the next
 * upload that finds it old.
 */
final class VipsScratch
{
    private const PREFIX = 'noongun-image-';

    /** A folder older than this belongs to no upload that is still running. */
    private const ABANDONED_AFTER_SECONDS = 3600;

    private int $files = 0;

    private function __construct(
        private readonly VipsCommand $vips,
        private readonly string $directory,
        private readonly float $deadline,
    ) {}

    /**
     * @param  int  $seconds  how long all steps together may take
     */
    public static function open(VipsCommand $vips, int $seconds): self
    {
        self::removeAbandoned();

        $directory = sys_get_temp_dir().'/'.self::PREFIX.bin2hex(random_bytes(12));

        if (! @mkdir($directory, 0700)) {
            throw new RuntimeException('No folder for temporary files could be made.');
        }

        return new self($vips, $directory, microtime(true) + $seconds);
    }

    /**
     * The path of a file that does not exist yet.
     */
    public function file(string $extension = 'v'): string
    {
        return sprintf('%s/%02d.%s', $this->directory, ++$this->files, $extension);
    }

    /**
     * Runs one operation on an image and returns the file it wrote. The
     * file it read is removed: a decoded image is large, and only the
     * newest one is needed.
     *
     * @throws ExceptionInterface
     */
    public function apply(string $operation, string $image, string ...$options): string
    {
        $result = $this->file();

        $this->run([$operation, $image, $result, ...array_values($options)]);

        @unlink($image);

        return $result;
    }

    /**
     * @param  list<string>  $arguments
     *
     * @throws ExceptionInterface
     * @throws RuntimeException when the time of the upload is used up
     */
    public function run(array $arguments, bool $untrusted = false): void
    {
        $left = $this->deadline - microtime(true);

        if ($left <= 0) {
            throw new RuntimeException('The image took longer to process than a processing slot is held.');
        }

        $this->vips->run($arguments, $this->directory, $left, $untrusted);
    }

    public function close(): void
    {
        self::remove($this->directory);
    }

    private static function removeAbandoned(): void
    {
        try {
            foreach (glob(sys_get_temp_dir().'/'.self::PREFIX.'*', GLOB_ONLYDIR) ?: [] as $directory) {
                $changed = @filemtime($directory);

                if ($changed !== false && $changed < time() - self::ABANDONED_AFTER_SECONDS) {
                    self::remove($directory);
                }
            }
        } catch (Throwable) {
            // Tidying up for an earlier upload must not stop this one.
        }
    }

    private static function remove(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }
}
