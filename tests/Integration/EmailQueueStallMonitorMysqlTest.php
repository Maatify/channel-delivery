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

        $calm = $monitor->report(600, 3, 600, [$healthy], time());
        self::assertSame('ok', $calm['status']);
        self::assertSame([], $calm['alerts']);
        self::assertSame(0, $calm['workers'][0]['consecutive_batch_failures'] ?? null);
        self::assertEqualsWithDelta(4, $calm['workers'][0]['updated_seconds_ago'] ?? -1, 2);

        $stalled = $monitor->report(200, 3, 600, [$healthy, $failing, $missing], time());
        self::assertSame('alert', $stalled['status']);
        self::assertSame(['oldest_due_pending_age', 'consecutive_batch_failures', 'worker_state_unavailable'], $stalled['alerts']);
        self::assertSame(3, $stalled['workers'][1]['consecutive_batch_failures'] ?? null);
        self::assertSame(1, $stalled['workers'][1]['consecutive_connection_failures'] ?? null);
        self::assertFalse($stalled['workers'][2]['available']);
        self::assertSame(['max_pending_age_seconds' => 200, 'max_batch_failures' => 3, 'max_heartbeat_age_seconds' => 600, 'outcome_alert_window_seconds' => 600], $stalled['thresholds']);

        self::assertSame(['consecutive_batch_failures'], $monitor->report(600, 3, 600, [$failing], time())['alerts']);
        self::assertSame('ok', $monitor->report(600, 4, 600, [$failing], time())['status'], 'Below the failure threshold.');
    }

    public function testQueueOnlyStatusAndPreUpgradeHeartbeatExposeOutcomeCoverage(): void
    {
        $monitor = new EmailQueueStallMonitor($this->session);
        self::assertSame('not_configured', $monitor->report(600, 3, 600, [], time())['outcome_tracking']);
        $path = $this->stateFile(new EmailWorkerState(42, time(), 0, 0, null));
        $old = ['pid' => 42, 'updated_at' => time(), 'consecutive_batch_failures' => 0,
            'consecutive_connection_failures' => 0, 'last_batch_succeeded_at' => time()];
        self::assertNotFalse(file_put_contents($path, json_encode($old, JSON_THROW_ON_ERROR)));
        self::assertSame(['outcome_tracking_unavailable'], $monitor->report(600, 3, 600, [$path], time())['alerts']);
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

    public function testStatusScriptAlertsOnAStaleHeartbeatByDefault(): void
    {
        // Empty queue, and a valid state file with no failures last written an hour ago:
        // the worker stopped or hangs, so this must not read as healthy.
        $stale = $this->stateFile(new EmailWorkerState(103, time() - 3600, 0, 0, time() - 3600));

        [$code, $output] = $this->script(['--state-file=' . $stale]);

        self::assertSame(1, $code, $output);
        $report = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame('alert', $report['status']);
        self::assertSame(['worker_heartbeat_stale'], $report['alerts']);
    }

    public function testHeartbeatOlderThanTheThresholdAlertsAndTheBoundaryIsHealthy(): void
    {
        $now = 1_800_000_000;
        $fresh = $this->stateFile(new EmailWorkerState(201, $now - 5, 0, 0, $now - 5));
        $boundary = $this->stateFile(new EmailWorkerState(202, $now - 600, 0, 0, $now - 600));
        $stale = $this->stateFile(new EmailWorkerState(203, $now - 601, 0, 0, $now - 601));
        $staleAndFailing = $this->stateFile(new EmailWorkerState(204, $now - 3600, 3, 3, null));
        $monitor = new EmailQueueStallMonitor($this->session);

        self::assertSame('ok', $monitor->report(600, 3, 600, [$fresh], $now)['status']);
        $atBoundary = $monitor->report(600, 3, 600, [$boundary], $now);
        self::assertSame('ok', $atBoundary['status'], 'Exactly the threshold is still fresh.');
        self::assertSame(600, $atBoundary['workers'][0]['updated_seconds_ago'] ?? null);

        $justStale = $monitor->report(600, 3, 600, [$fresh, $stale], $now);
        self::assertSame('alert', $justStale['status']);
        self::assertSame(['worker_heartbeat_stale'], $justStale['alerts']);
        self::assertSame(601, $justStale['workers'][1]['updated_seconds_ago'] ?? null);

        self::assertSame(['worker_heartbeat_stale', 'consecutive_batch_failures'], $monitor->report(600, 3, 600, [$staleAndFailing], $now)['alerts']);
        self::assertSame('ok', $monitor->report(600, 3, 601, [$stale], $now)['status'], 'A longer threshold accepts it.');
    }

    public function testStatusScriptValidatesAndHonoursTheHeartbeatThreshold(): void
    {
        $hourOld = $this->stateFile(new EmailWorkerState(205, time() - 3600, 0, 0, time() - 3600));

        [$code, $output] = $this->script(['--max-heartbeat-age=7200', '--state-file=' . $hourOld]);
        self::assertSame(0, $code, $output);
        $report = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertIsArray($report['thresholds'] ?? null);
        self::assertSame(7200, $report['thresholds']['max_heartbeat_age_seconds'] ?? null);

        [$code] = $this->script(['--max-heartbeat-age=60', '--state-file=' . $hourOld]);
        self::assertSame(1, $code);

        foreach (['--max-heartbeat-age=0', '--max-heartbeat-age=-5', '--max-heartbeat-age=ten', '--max-heartbeat-age=', '--max-heartbeat-age=1234567890'] as $invalid) {
            [$code] = $this->script([$invalid]);
            self::assertSame(2, $code, $invalid);
        }
    }

    public function testStatusScriptHonoursTheOutcomeAlertWindow(): void
    {
        $path = $this->stateFile(new EmailWorkerState(206, time(), 0, 0, time(), time() - 60));
        [$code, $output] = $this->script(['--outcome-alert-window=600', '--state-file=' . $path]);
        self::assertSame(1, $code, $output);
        $report = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame(['outcome_write_failed'], $report['alerts']);

        [$code] = $this->script(['--outcome-alert-window=30', '--state-file=' . $path]);
        self::assertSame(0, $code);
        [$code] = $this->script(['--outcome-alert-window=0']);
        self::assertSame(2, $code);
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
