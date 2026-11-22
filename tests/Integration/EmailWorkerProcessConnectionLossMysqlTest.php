<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\AtomicHttpMysqlTestCase;

/**
 * Black-box: the real DI-wired worker loop in its own process. The server closes
 * the worker's connection between batches (KILL, or its session idle timeout); a
 * message accepted afterwards must still be sent, the process must keep running,
 * and no PHP warning, notice or deprecation may reach its output.
 */
final class EmailWorkerProcessConnectionLossMysqlTest extends AtomicHttpMysqlTestCase
{
    private string $directory = '';
    /** @var resource|null */
    private $process = null;

    public function testLoopRecoversWhenTheServerKillsItsConnection(): void
    {
        [$app] = $this->application();
        self::assertLessThan(300, $this->request($app, key: 'process-1')->getStatusCode());
        // Connections that exist before the worker starts are not the worker's.
        $before = $this->connectionIds();
        $this->startWorker();
        $this->waitForSent(1, 15.0, 'before the connection loss');

        $worker = array_values(array_diff($this->connectionIds(), $before));
        self::assertCount(1, $worker, 'The worker holds exactly one connection.');
        $this->pdo->exec('KILL CONNECTION ' . (int) $worker[0]);

        self::assertLessThan(300, $this->request($app, key: 'process-2')->getStatusCode());
        $this->waitForSent(2, 10.0, 'after the server killed the worker connection');

        self::assertNotNull($this->process);
        self::assertTrue(proc_get_status($this->process)['running'], 'The worker loop keeps running.');
        $log = $this->log();
        self::assertStringContainsString('Worker database connection re-established', $log);
        self::assertStringNotContainsString('Worker exiting for restart', $log);
        $this->assertCleanOutput();
    }

    public function testLoopReconnectsAfterItsSessionIdleTimeoutWithCleanOutput(): void
    {
        [$app] = $this->application();
        self::assertLessThan(300, $this->request($app, key: 'idle-process-1')->getStatusCode());
        // The worker idles 3 s between batches, past its own 2 s session wait_timeout.
        $this->startWorker(['DB_SESSION_WAIT_TIMEOUT' => '2'], 3);
        $this->waitForSent(1, 15.0, 'before the idle timeout');
        sleep(4);

        self::assertLessThan(300, $this->request($app, key: 'idle-process-2')->getStatusCode());
        $this->waitForSent(2, 10.0, 'after the server closed the idle worker connection');

        self::assertNotNull($this->process);
        self::assertTrue(proc_get_status($this->process)['running']);
        $log = $this->log();
        self::assertStringContainsString('Worker database connection lost; reconnecting', $log);
        self::assertStringContainsString('Worker database connection re-established', $log);
        self::assertStringNotContainsString('Worker batch failed', $log);
        $this->assertCleanOutput();
    }

    private function assertCleanOutput(): void
    {
        foreach (['stdout', 'stderr'] as $stream) {
            self::assertSame('', (string) file_get_contents($this->directory . '/' . $stream), 'No PHP warnings, notices or deprecations on ' . $stream);
        }
    }

    /** @param array<string, string> $environment */
    private function startWorker(array $environment = [], int $sleep = 1): void
    {
        $this->directory = sys_get_temp_dir() . '/cd-worker-process-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->directory));
        $spec = $this->directory . '/spec.json';
        file_put_contents($spec, json_encode(['env' => array_merge($_ENV, $environment), 'log' => $this->directory . '/worker.log', 'batch' => 10, 'sleep' => $sleep], JSON_THROW_ON_ERROR));
        $process = proc_open(
            [PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', '-d', 'log_errors=0', dirname(__DIR__) . '/Support/email-worker-process.php', $spec],
            [0 => ['pipe', 'r'], 1 => ['file', $this->directory . '/stdout', 'w'], 2 => ['file', $this->directory . '/stderr', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $this->process = $process;
    }

    private function waitForSent(int $expected, float $seconds, string $when): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            $sent = (int) self::scalar($this->pdo, "SELECT COUNT(*) FROM cd_email_queue WHERE status = 'sent'");
            if ($sent >= $expected) {
                return;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        self::fail(sprintf(
            "Only %d of %d accepted messages were sent %s within %.0f s.\nWorker running: %s\nWorker log tail:\n%s\nstderr:\n%s",
            $sent,
            $expected,
            $when,
            $seconds,
            $this->process !== null && proc_get_status($this->process)['running'] ? 'yes' : 'no',
            implode("\n", array_slice(explode("\n", trim($this->log())), -8)),
            (string) @file_get_contents($this->directory . '/stderr')
        ));
    }

    /** @return list<string> ids of the open connections to the fixture database */
    private function connectionIds(): array
    {
        $statement = $this->pdo->query('SHOW FULL PROCESSLIST');
        self::assertNotFalse($statement);
        $database = self::scalar($this->pdo, 'SELECT DATABASE()');
        $ids = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            self::assertIsArray($row);
            $id = $row['Id'] ?? null;
            self::assertTrue(is_int($id) || is_string($id));
            if (($row['db'] ?? null) === $database) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    private function log(): string
    {
        $log = @file_get_contents($this->directory . '/worker.log');

        return $log === false ? '' : $log;
    }

    protected function tearDown(): void
    {
        if ($this->process !== null) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
        if ($this->directory !== '' && is_dir($this->directory)) {
            foreach (glob($this->directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }
}
