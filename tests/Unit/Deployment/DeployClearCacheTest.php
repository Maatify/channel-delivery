<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeployClearCacheTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cd-cache-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root . '/scripts', 0700, true));
        self::assertTrue(mkdir($this->root . '/outside'));
        self::assertTrue(copy(
            dirname(__DIR__, 3) . '/scripts/deploy_clear_cache.php',
            $this->root . '/scripts/deploy_clear_cache.php'
        ));
        self::assertNotFalse(file_put_contents($this->root . '/outside/keep', 'outside cache'));
    }

    public function testClearsBothCachesAndUnlinksEntriesWithoutFollowingThem(): void
    {
        $cache = $this->root . '/var/cache';
        self::assertTrue(mkdir($cache . '/twig/nested', 0700, true));
        self::assertTrue(mkdir($cache . '/di'));
        self::assertNotFalse(file_put_contents($cache . '/twig/nested/template.php', 'fixture'));
        self::assertNotFalse(file_put_contents($cache . '/di/CompiledContainer.php', 'fixture'));
        self::assertTrue(symlink($this->root . '/outside/keep', $cache . '/twig/file-link'));
        self::assertTrue(symlink($this->root . '/outside', $cache . '/twig/directory-link'));
        self::assertTrue(symlink($this->root . '/absent', $cache . '/twig/dangling-link'));

        self::assertSame(0, $this->runCommand());
        self::assertSame(['.', '..'], scandir($cache . '/twig'));
        self::assertSame(['.', '..'], scandir($cache . '/di'));
        self::assertSame('outside cache', file_get_contents($this->root . '/outside/keep'));
        self::assertSame(0, $this->runCommand());
    }

    #[DataProvider('linkedDirectories')]
    public function testRejectsLinkedCacheRootsAndAncestors(string $relativePath): void
    {
        $link = $this->root . '/' . $relativePath;
        if (!is_dir(dirname($link))) {
            self::assertTrue(mkdir(dirname($link), 0700, true));
        }
        self::assertTrue(symlink($this->root . '/outside', $link));

        self::assertSame(1, $this->runCommand());
        self::assertTrue(is_link($link));
        self::assertSame('outside cache', file_get_contents($this->root . '/outside/keep'));
    }

    /** @return array<string, array{string}> */
    public static function linkedDirectories(): array
    {
        return [
            'var' => ['var'],
            'cache' => ['var/cache'],
            'twig' => ['var/cache/twig'],
            'DI' => ['var/cache/di'],
        ];
    }

    private function runCommand(): int
    {
        $process = proc_open(
            [PHP_BINARY, $this->root . '/scripts/deploy_clear_cache.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $this->root . '/outside'
        );
        self::assertIsResource($process);

        return proc_close($process);
    }

    protected function tearDown(): void
    {
        /** @var iterable<\SplFileInfo> $entries */
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if (!$entry->isLink() && $entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->root);
    }
}
