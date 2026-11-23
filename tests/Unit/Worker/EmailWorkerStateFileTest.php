<?php

declare(strict_types=1);

namespace Tests\Unit\Worker;

use Maatify\ChannelDelivery\Worker\EmailWorkerState;
use Maatify\ChannelDelivery\Worker\EmailWorkerStateFile;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EmailWorkerStateFileTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/cd-worker-state-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testWrittenStateReadsBackAndLeavesNoTemporaryFile(): void
    {
        (new EmailWorkerStateFile($this->path, new NullLogger()))->write(new EmailWorkerState(7, 1700000000, 2, 1, 1699999990));

        self::assertEquals(new EmailWorkerState(7, 1700000000, 2, 1, 1699999990), EmailWorkerStateFile::read($this->path));
        self::assertSame([$this->path], glob($this->path . '*'));
    }

    public function testOversizedStateIsUnavailable(): void
    {
        (new EmailWorkerStateFile($this->path, new NullLogger()))->write(new EmailWorkerState(7, 1700000000, 0, 0, null));
        self::assertNotFalse(file_put_contents($this->path, str_repeat(' ', 4096), FILE_APPEND));

        self::assertNull(EmailWorkerStateFile::read($this->path));
    }

    public function testFailedReplacementLeavesNoTemporaryFile(): void
    {
        self::assertTrue(mkdir($this->path));
        try {
            (new EmailWorkerStateFile($this->path, new NullLogger()))->write(new EmailWorkerState(7, 1700000000, 0, 0, null));

            self::assertDirectoryExists($this->path);
            self::assertSame([$this->path], glob($this->path . '*'));
        } finally {
            rmdir($this->path);
        }
    }

    public function testMissingOrInvalidDocumentsReadAsUnavailable(): void
    {
        self::assertNull(EmailWorkerStateFile::read($this->path));
        foreach (['not json', '[]', '{"pid":1}', '{"pid":1,"updated_at":2,"consecutive_batch_failures":-1,"consecutive_connection_failures":0}',
            '{"pid":1,"updated_at":2,"consecutive_batch_failures":"3","consecutive_connection_failures":0}',
            '{"pid":1,"updated_at":2,"consecutive_batch_failures":0,"consecutive_connection_failures":0,"last_batch_succeeded_at":"x"}'] as $document) {
            file_put_contents($this->path, $document);
            self::assertNull(EmailWorkerStateFile::read($this->path), $document);
        }
    }
}
