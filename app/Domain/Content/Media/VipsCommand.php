<?php

namespace App\Domain\Content\Media;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs the vips program of libvips: one operation, with arguments this
 * application wrote, in a process of its own.
 *
 * libvips is not loaded into PHP. A decoder that meets a file made to break
 * it does so in a process that holds nothing else and ends a moment later,
 * and the memory it took goes back to the system when it ends. PHP needs no
 * FFI for this.
 *
 * The process gets none of the environment of the application, so no key
 * and no password is in reach of it. It never goes through a shell.
 */
class VipsCommand
{
    private const PROGRAM = 'vips';

    private const PATH = '/usr/local/bin:/usr/bin:/bin';

    private string|false|null $found = null;

    public function available(): bool
    {
        return $this->program() !== null;
    }

    /**
     * @param  list<string>  $arguments  the operation and what it takes, as vips reads them
     * @param  string  $directory  where the process runs and puts its temporary files
     * @param  float  $seconds  how long it may take
     * @param  bool  $untrusted  whether the operation reads a file that was sent in; libvips then refuses every loader it marks as unsafe for such files
     *
     * @throws ExceptionInterface when the program is missing, fails or takes too long
     */
    public function run(array $arguments, string $directory, float $seconds, bool $untrusted = false): void
    {
        $process = new Process(
            [$this->program() ?? self::PROGRAM, ...$this->limits(), ...$arguments],
            $directory,
            $this->environment($directory, $untrusted),
            null,
            max(0.1, $seconds),
        );

        $process->disableOutput()->mustRun();
    }

    private function program(): ?string
    {
        $this->found ??= (new ExecutableFinder)->find(self::PROGRAM, null, explode(':', self::PATH)) ?? false;

        return $this->found === false ? null : $this->found;
    }

    /**
     * What bounds one process: how many threads it works with, and no
     * cache of earlier operations, which a process that runs one operation
     * has no use for.
     *
     * @return list<string>
     */
    private function limits(): array
    {
        return [
            '--vips-concurrency='.MediaConfig::vipsConcurrency(),
            '--vips-cache-max=0',
        ];
    }

    /**
     * Every variable PHP has is taken away. What is left is where the
     * programs are and where temporary files go.
     *
     * @return array<string, string|false>
     */
    private function environment(string $directory, bool $untrusted): array
    {
        $environment = array_fill_keys(array_keys(getenv() + $_ENV), false);

        $environment['PATH'] = self::PATH;
        $environment['HOME'] = $directory;
        $environment['TMPDIR'] = $directory;

        if ($untrusted) {
            $environment['VIPS_BLOCK_UNTRUSTED'] = '1';
        }

        return $environment;
    }
}
