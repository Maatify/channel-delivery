#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

// Clear the compiled caches after installing the release and stopping its processes.
$root = dirname(__DIR__);
$cacheRoot = $root . '/var/cache';
$targets = [
    'Twig compiled templates' => $cacheRoot . '/twig',
    'PHP-DI compiled container' => $cacheRoot . '/di',
];

try {
    foreach ([$root . '/var', $cacheRoot] as $parent) {
        cacheDirectoryExists($parent);
    }
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

$allCleared = true;
foreach ($targets as $label => $dir) {
    try {
        if (!cacheDirectoryExists($dir)) {
            echo "  {$label}: nothing to clear ({$dir} does not exist)\n";
            continue;
        }

        $cleared = clearDirectory($dir);
        echo "  {$label}: cleared {$cleared} file(s) from {$dir}\n";
    } catch (RuntimeException $exception) {
        $allCleared = false;
        echo "  {$label}: FAILED to clear {$dir} — {$exception->getMessage()}\n";
    }
}

if (!$allCleared) {
    fwrite(STDERR, "\n❌ Cache clear incomplete — see failures above.\n");
    exit(1);
}

echo "\n✅ Cache cleared.\n";

function cacheDirectoryExists(string $dir): bool
{
    if (is_link($dir) || (file_exists($dir) && !is_dir($dir))) {
        throw new RuntimeException('Expected a cache directory, found a link or non-directory: ' . $dir);
    }

    return is_dir($dir);
}

function clearDirectory(string $dir): int
{
    $count = 0;

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($files as $file) {
        $path = $file->getPathname();
        if (!$file->isLink() && $file->isDir()) {
            if (!@rmdir($path)) {
                throw new RuntimeException('rmdir failed for ' . $path . ': ' . lastErrorMessage());
            }
            continue;
        }

        if (!@unlink($path)) {
            throw new RuntimeException('unlink failed for ' . $path . ': ' . lastErrorMessage());
        }
        $count++;
    }

    return $count;
}

function lastErrorMessage(): string
{
    $error = error_get_last();

    return $error['message'] ?? 'unknown reason';
}
