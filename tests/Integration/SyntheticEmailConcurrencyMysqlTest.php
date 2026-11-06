<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\AtomicHttpMysqlTestCase;

final class SyntheticEmailConcurrencyMysqlTest extends AtomicHttpMysqlTestCase
{
    public function testFourIndependentProcessesAppendOnlyIsolatedRecords(): void
    {
        $directory = sys_get_temp_dir() . '/channel-synthetic-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        $children = [];
        try {
            for ($i = 0; $i < 4; ++$i) {
                $spec = $directory . '/' . $i . '.spec';
                file_put_contents($spec, json_encode(['dir' => $directory, 'name' => (string) $i, 'hold' => false,
                    'env' => $_ENV, 'payload' => $this->payload(), 'path' => '/api/v2/email/synthetic', 'barrier' => true], JSON_THROW_ON_ERROR));
                $child = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/atomic-http-process.php', $spec],
                    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory . '/' . $i . '.out', 'w'], 2 => ['file', $directory . '/' . $i . '.err', 'w']], $pipes);
                self::assertIsResource($child);
                $children[] = $child;
            }
            $deadline = microtime(true) + 10;
            do {
                $ready = count(glob($directory . '/*.ready') ?: []);
                if ($ready === 4) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertSame(4, $ready, 'All independent authenticated application processes must reach the shared start barrier.');
            file_put_contents($directory . '/release', '1');
            $pids = [];
            $ids = [];
            for ($i = 0; $i < 4; ++$i) {
                $deadline = microtime(true) + 10;
                while (!is_file($directory . '/' . $i . '.result') && microtime(true) < $deadline) {
                    usleep(10000);
                }
                self::assertFileExists($directory . '/' . $i . '.result', (string) file_get_contents($directory . '/' . $i . '.err'));
                self::assertSame('', file_get_contents($directory . '/' . $i . '.err'));
                /** @var array{pid: int, status: int, body: array{record: array{record_id: string}}} $result */
                $result = json_decode((string) file_get_contents($directory . '/' . $i . '.result'), true, 512, JSON_THROW_ON_ERROR);
                self::assertSame(202, $result['status']);
                $pids[] = $result['pid'];
                $ids[] = $result['body']['record']['record_id'];
            }
            self::assertCount(4, array_unique($pids));
            self::assertCount(4, array_unique($ids));
            self::assertSame('4', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_synthetic_requests'));
            self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
            self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
        } finally {
            file_put_contents($directory . '/release', '1');
            foreach ($children as $child) {
                if (proc_get_status($child)['running']) {
                    proc_terminate($child);
                }
                proc_close($child);
            }
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
