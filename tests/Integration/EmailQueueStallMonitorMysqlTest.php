<?php

declare(strict_types=1);

namespace Tests\Integration;

use Maatify\ChannelDelivery\Database\PdoConnectionFactory;
use Maatify\ChannelDelivery\Worker\EmailQueueStallMonitor;
use Maatify\ChannelDelivery\Worker\EmailWorkerState;
use Maatify\ChannelDelivery\Worker\EmailWorkerStateFile;
use PDO;
use Psr\Log\NullLogger;
use Tests\Support\AtomicHttpMysqlTestCase;

final class EmailQueueStallMonitorMysqlTest extends AtomicHttpMysqlTestCase
{
    private PDO $session;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = PdoConnectionFactory::fromEnvironment($_ENV)->create();
        // A non-UTC session clock, as a server default time zone would give the worker:
        // unbound rows follow it, receipt-bound rows use UTC.
        $this->session->exec("SET SESSION time_zone = '+03:00'");
    }

    public function testEmptyQueueHasNoAge(): void
    {
        self::assertSame(['due_pending' => 0, 'oldest_due_pending_age_seconds' => null], (new EmailQueueStallMonitor($this->session))->queue());
    }

    public function testOldestDuePendingAgeFollowsTheWorkerDueClocks(): void
    {
        $this->seedQueue();

        $queue = (new EmailQueueStallMonitor($this->session))->queue();

        self::assertSame(3, $queue['due_pending'], 'Only due pending rows count.');
        self::assertNotNull($queue['oldest_due_pending_age_seconds']);
        self::assertEqualsWithDelta(300, $queue['oldest_due_pending_age_seconds'], 3);
    }

    public function testReportAlertsOnThresholdsAndWorkerState(): void
    {
        $this->seedQueue();
        $healthy = $this->stateFile(new EmailWorkerState(101, time() - 4, 0, 0, time() - 4));
        $failing = $this->stateFile(new EmailWorkerState(102, time() - 2, 3, 1, null));
        $missing = sys_get_temp_dir() . '/cd-worker-state-missing-' . bin2hex(random_bytes(6)) . '.json';
        $monitor = new EmailQueueStallMonitor($this->session);

        $calm = $monitor->report(600, 3, [$healthy], time());
        self::assertSame('ok', $calm['status']);
        self::assertSame([], $calm['alerts']);
        self::assertSame(0, $calm['workers'][0]['consecutive_batch_failures'] ?? null);
        self::assertEqualsWithDelta(4, $calm['workers'][0]['updated_seconds_ago'] ?? -1, 2);

        $stalled = $monitor->report(200, 3, [$healthy, $failing, $missing], time());
        self::assertSame('alert', $stalled['status']);
        self::assertSame(['oldest_due_pending_age', 'consecutive_batch_failures', 'worker_state_unavailable'], $stalled['alerts']);
        self::assertSame(3, $stalled['workers'][1]['consecutive_batch_failures'] ?? null);
        self::assertSame(1, $stalled['workers'][1]['consecutive_connection_failures'] ?? null);
        self::assertFalse($stalled['workers'][2]['available']);
        self::assertSame(['max_pending_age_seconds' => 200, 'max_batch_failures' => 3], $stalled['thresholds']);

        self::assertSame(['consecutive_batch_failures'], $monitor->report(600, 3, [$failing], time())['alerts']);
        self::assertSame('ok', $monitor->report(600, 4, [$failing], time())['status'], 'Below the failure threshold.');
    }

    public function testStatusScriptPrintsTheReportWithMonitoringExitCodes(): void
    {
        // The script's session uses the server default clock, like the legacy writer and the worker.
        $this->seedQueue(PdoConnectionFactory::fromEnvironment($_ENV)->create());
        $failing = $this->stateFile(new EmailWorkerState(102, time(), 5, 0, null));

        [$code, $output] = $this->script(['--max-pending-age=900']);
        self::assertSame(0, $code, $output);
        $report = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame('ok', $report['status']);
        self::assertSame(3, $report['queue']['due_pending'] ?? null);

        [$code, $output] = $this->script(['--max-pending-age=60', '--state-file=' . $failing]);
        self::assertSame(1, $code, $output);
        $report = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame(['oldest_due_pending_age', 'consecutive_batch_failures'], $report['alerts']);

        [$code] = $this->script(['--max-pending-age=0']);
        self::assertSame(2, $code);

        [$code, $output] = $this->script([], ['DB_PORT' => '1']);
        self::assertSame(1, $code);
        self::assertSame('{"status":"unavailable"}', trim($output));
    }

    private function seedQueue(?PDO $session = null): void
    {
        $session ??= $this->session;
        $receipt = 0;
        $insert = static function (string $status, string $scheduledAt, ?string $retryAfter, bool $keyed) use (&$receipt, $session): void {
            $retry = $retryAfter ?? 'NULL';
            $token = $keyed ? "'" . str_pad((string) ++$receipt, 32, '0', STR_PAD_LEFT) . "'" : 'NULL';
            $session->exec("INSERT INTO cd_email_queue
                (entity_type, entity_id, recipient_encrypted, recipient_iv, recipient_tag, recipient_key_id,
                 payload_encrypted, payload_iv, payload_tag, payload_key_id, template_key, language, sender_type,
                 status, scheduled_at, retry_after, enqueue_receipt_id)
                VALUES ('user', 'fixture', 'r', 'iv', 'tag', 'k', 'p', 'iv', 'tag', 'k', 'welcome', 'en', 1,
                 '$status', $scheduledAt, $retry, $token)");
        };
        $insert('pending', 'NOW() - INTERVAL 120 SECOND', null, false);                                       // due 120 s, session clock
        $insert('pending', 'UTC_TIMESTAMP() - INTERVAL 300 SECOND', null, true);                              // due 300 s, UTC: the oldest
        $insert('pending', 'UTC_TIMESTAMP() - INTERVAL 900 SECOND', 'UTC_TIMESTAMP() - INTERVAL 50 SECOND', true); // due since its retry, 50 s
        $insert('pending', 'NOW() + INTERVAL 600 SECOND', null, false);                                       // scheduled for later
        $insert('pending', 'UTC_TIMESTAMP() - INTERVAL 1000 SECOND', 'UTC_TIMESTAMP() + INTERVAL 60 SECOND', true); // backing off
        $insert('sent', 'NOW() - INTERVAL 5000 SECOND', null, false);
        $insert('processing', 'NOW() - INTERVAL 5000 SECOND', null, false);
        $insert('failed', 'UTC_TIMESTAMP() - INTERVAL 5000 SECOND', null, true);
    }

    private function stateFile(EmailWorkerState $state): string
    {
        $path = sys_get_temp_dir() . '/cd-worker-state-' . bin2hex(random_bytes(6)) . '.json';
        (new EmailWorkerStateFile($path, new NullLogger()))->write($state);
        $this->files[] = $path;

        return $path;
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     *
     * @return array{int, string}
     */
    private function script(array $arguments, array $environment = []): array
    {
        $env = [];
        foreach (array_merge($_ENV, $environment) as $name => $value) {
            if (is_scalar($value)) {
                $env[(string) $name] = (string) $value;
            }
        }
        $env['PATH'] = (string) getenv('PATH');
        $process = proc_open(
            array_merge([PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', dirname(__DIR__, 2) . '/scripts/email_queue_status.php'], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        self::assertDoesNotMatchRegularExpression('/Warning|Notice|Deprecated/i', $errors);

        return [$code, $output];
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }
}
