<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\AtomicHttpMysqlTestCase;

final class AtomicEmailConcurrencyMysqlTest extends AtomicHttpMysqlTestCase
{
    private string $directory = '';
    /** @var array<string, resource> */
    private array $children = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/channel-atomic-http-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        if ($this->directory !== '') {
            self::assertMatchesRegularExpression('~\A' . preg_quote(sys_get_temp_dir(), '~') . '/channel-atomic-http-[a-f0-9]{12}\z~', $this->directory);
            file_put_contents($this->directory . '/release', '1');
            foreach ($this->children as $process) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process);
                }
                proc_close($process);
            }
            foreach (glob($this->directory . '/*') ?: [] as $file) {
                if (is_file($file) || is_link($file)) {
                    unlink($file);
                }
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    private function start(string $name, bool $hold = false): void
    {
        $spec = $this->directory . '/' . $name . '.spec';
        file_put_contents($spec, json_encode(['dir' => $this->directory, 'name' => $name, 'hold' => $hold, 'env' => $_ENV, 'payload' => $this->payload()], JSON_THROW_ON_ERROR));
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/atomic-http-process.php', $spec], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->directory . '/' . $name . '.stdout', 'w'], 2 => ['file', $this->directory . '/' . $name . '.stderr', 'w']], $pipes);
        self::assertIsResource($process);
        $this->children[$name] = $process;
    }

    private function waitFile(string $name, string $suffix): string
    {
        $path = $this->directory . '/' . $name . '.' . $suffix;
        $deadline = microtime(true) + 10;
        while (!is_file($path) && microtime(true) < $deadline) {
            if (!proc_get_status($this->children[$name])['running']) {
                break;
            }
            usleep(10000);
        }
        self::assertFileExists($path, (string) file_get_contents($this->directory . '/' . $name . '.stderr'));
        return (string) file_get_contents($path);
    }

    /** @return array{pid: int, status: int, body: array<string, mixed>} */
    private function processResult(string $name): array
    {
        $data = $this->waitFile($name, 'result');
        self::assertSame('', file_get_contents($this->directory . '/' . $name . '.stderr'));
        /** @var array{pid: int, status: int, body: array<string, mixed>} $result */
        $result = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        return $result;
    }

    public function testFourActualHttpProcessesContendOnOneIdentityAndRestartReplaysTheWinner(): void
    {
        $this->start('winner', true);
        $this->waitFile('winner', 'inserted');
        $ids = [];
        foreach (['peer1', 'peer2', 'peer3'] as $name) {
            $this->start($name);
            $this->waitFile($name, 'before');
            $ids[] = (int) $this->waitFile($name, 'connection');
        }
        $sql = "SELECT COUNT(DISTINCT w.REQUESTING_THREAD_ID) FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID=w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID IN (" . implode(',', $ids) . ')';
        $deadline = microtime(true) + 10;
        do {
            $waiting = (int) self::scalar($this->pdo, $sql);
            if ($waiting === 3) {
                break;
            }
            usleep(200000);
        } while (microtime(true) < $deadline);
        self::assertSame(3, $waiting, 'All three authenticated HTTP peers must really wait on the uncommitted acceptance.');
        file_put_contents($this->directory . '/release', '1');
        $winner = $this->processResult('winner');
        self::assertSame(202, $winner['status']);
        foreach (['peer1', 'peer2', 'peer3'] as $name) {
            $peer = $this->processResult($name);
            self::assertNotSame($winner['pid'], $peer['pid']);
            if ($peer['status'] === 503) {
                self::assertSame('unknown', $peer['body']['acceptance']);
                $this->start($name . '-recovery');
                $peer = $this->processResult($name . '-recovery');
            }
            self::assertSame(202, $peer['status']);
            self::assertSame($winner['body'], $peer['body']);
        }
        $this->start('restart');
        $restart = $this->processResult('restart');
        self::assertSame(202, $restart['status']);
        self::assertSame($winner['body'], $restart['body']);
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
    }
}
