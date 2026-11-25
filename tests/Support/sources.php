<?php

use Symfony\Component\Finder\Finder;

/**
 * Helpers for the tests that read the application's own source to keep a
 * rule: which code may approve, schedule or publish. Loaded by those test
 * files with require_once.
 */

/**
 * Every PHP file of the application with its source, keyed by path relative
 * to app/.
 *
 * @return array<string, string>
 */
function applicationSources(): array
{
    $sources = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $sources[str_replace('\\', '/', $file->getRelativePathname())] = $file->getContents();
    }

    return $sources;
}

/**
 * The files whose source matches the pattern.
 *
 * @return list<string>
 */
function sourcesMatching(string $pattern, ?string $under = null): array
{
    return array_keys(array_filter(
        applicationSources(),
        fn (string $source, string $path) => ($under === null || str_starts_with($path, $under)) && preg_match($pattern, $source) === 1,
        ARRAY_FILTER_USE_BOTH,
    ));
}
