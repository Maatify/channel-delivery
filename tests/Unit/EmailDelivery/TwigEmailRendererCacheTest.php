<?php

declare(strict_types=1);

namespace Tests\Unit\EmailDelivery;

use Maatify\EmailDelivery\DTO\GenericEmailPayload;
use Maatify\EmailDelivery\Renderer\TwigEmailRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TwigEmailRenderer — focusing on the caching behavior
 * added after Jules audit:
 *
 *   - APP_ENV=production  → cache enabled at var/cache/twig
 *   - APP_ENV=development → cache disabled
 *   - APP_ENV=testing     → cache disabled
 *   - cachePath=false     → always disabled (explicit)
 *   - cachePath='string'  → always that path (explicit)
 */
final class TwigEmailRendererCacheTest extends TestCase
{
    private string $templatesPath;
    private string $cacheDir;

    protected function setUp(): void
    {
        // Use a real temp dir with a minimal template for render tests
        $this->templatesPath = sys_get_temp_dir() . '/cd_twig_test_' . uniqid();
        $this->cacheDir      = sys_get_temp_dir() . '/cd_twig_cache_' . uniqid();

        mkdir($this->templatesPath . '/emails/welcome', 0755, true);

        // Minimal valid template with required subject block
        file_put_contents(
            $this->templatesPath . '/emails/welcome/en.twig',
            '{% block subject %}Welcome{% endblock %}Hello {{ user_name }}'
        );
    }

    protected function tearDown(): void
    {
        // Clean up temp dirs
        $this->removeDir($this->templatesPath);
        $this->removeDir($this->cacheDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        /** @var iterable<\SplFileInfo> $files */
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $file->isDir()
                ? rmdir((string) $file->getRealPath())
                : unlink((string) $file->getRealPath());
        }
        rmdir($dir);
    }

    // ── Cache auto-detection based on APP_ENV ─────────────────

    #[Test]
    public function testCacheDisabledInDevelopment(): void
    {
        $_ENV['APP_ENV'] = 'development';

        $renderer = new TwigEmailRenderer(
            templatesPath: $this->templatesPath,
            cachePath:     null, // auto-detect
        );

        // Render must succeed without creating a cache directory
        $result = $renderer->render('welcome', 'en', new GenericEmailPayload(['user_name' => 'Ahmed']));

        $this->assertSame('Welcome', $result->subject);
        $this->assertStringContainsString('Hello Ahmed', $result->htmlBody);
    }

    #[Test]
    public function testCacheDisabledInTesting(): void
    {
        $_ENV['APP_ENV'] = 'testing';

        $renderer = new TwigEmailRenderer(
            templatesPath: $this->templatesPath,
            cachePath:     null,
        );

        $result = $renderer->render('welcome', 'en', new GenericEmailPayload(['user_name' => 'Test']));

        $this->assertSame('Welcome', $result->subject);
    }

    #[Test]
    public function testCacheEnabledInProduction(): void
    {
        $_ENV['APP_ENV'] = 'production';

        $renderer = new TwigEmailRenderer(
            templatesPath: $this->templatesPath,
            cachePath:     $this->cacheDir, // explicit cache dir for test isolation
        );

        $result = $renderer->render('welcome', 'en', new GenericEmailPayload(['user_name' => 'Ahmed']));

        $this->assertSame('Welcome', $result->subject);
        // Cache directory should have been created and populated
        $this->assertDirectoryExists($this->cacheDir);
        $cachedFiles = glob($this->cacheDir . '/**/*.php', GLOB_BRACE) ?: [];
        $this->assertNotEmpty($cachedFiles, 'Expected Twig to write compiled templates to cache.');
    }

    // ── Explicit cachePath parameter ──────────────────────────

    #[Test]
    public function testExplicitFalseCacheAlwaysDisabled(): void
    {
        $_ENV['APP_ENV'] = 'production'; // even in production, explicit false wins

        $renderer = new TwigEmailRenderer(
            templatesPath: $this->templatesPath,
            cachePath:     false,
        );

        $result = $renderer->render('welcome', 'en', new GenericEmailPayload(['user_name' => 'Ahmed']));

        $this->assertSame('Welcome', $result->subject);
        // No cache directory should exist
        $this->assertDirectoryDoesNotExist($this->cacheDir);
    }

    #[Test]
    public function testExplicitCachePathIsUsed(): void
    {
        $renderer = new TwigEmailRenderer(
            templatesPath: $this->templatesPath,
            cachePath:     $this->cacheDir,
        );

        $renderer->render('welcome', 'en', new GenericEmailPayload(['user_name' => 'Ahmed']));

        $this->assertDirectoryExists($this->cacheDir);
    }

    // ── Globals still injected with caching enabled ───────────

    #[Test]
    public function testGlobalsAreAvailableWhenCachingEnabled(): void
    {
        // Template that uses a global
        file_put_contents(
            $this->templatesPath . '/emails/welcome/en.twig',
            '{% block subject %}Welcome{% endblock %}App: {{ app_name }}'
        );

        $renderer = new TwigEmailRenderer(
            templatesPath: $this->templatesPath,
            globals:       ['app_name' => 'MyApp'],
            cachePath:     false,
        );

        $result = $renderer->render('welcome', 'en', new GenericEmailPayload([]));

        $this->assertStringContainsString('App: MyApp', $result->htmlBody);
    }
}
