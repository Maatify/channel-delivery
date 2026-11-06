<?php

declare(strict_types=1);

namespace Tests\Integration;

use Maatify\EmailDelivery\Synthetic\SyntheticEmailMaintenanceInterface;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AtomicTestPdo;
use Maatify\EmailDelivery\Worker\EmailQueueWorker;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\Support\AtomicHttpMysqlTestCase;

final class SyntheticEmailHttpMysqlTest extends AtomicHttpMysqlTestCase
{
    /** @param App<ContainerInterface> $app */
    private function synthetic(App $app, string $apiKey = 'a'): ResponseInterface
    {
        return $app->handle((new ServerRequestFactory())->createServerRequest('POST', '/api/v2/email/synthetic', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Api-Key', 'fixture-key-' . $apiKey)->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream(json_encode($this->payload(), JSON_THROW_ON_ERROR))));
    }

    public function testRecordingNeverReachesWorkerAcrossRestartWithRealSendPositiveControl(): void
    {
        [$app, $container] = $this->application();
        $response = $this->synthetic($app);
        self::assertSame(202, $response->getStatusCode());
        self::assertSame('recorded', $this->json($response)['status']);
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertArrayNotHasKey('receipt', $this->json($response));
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_synthetic_requests'));
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
        $worker = $container->get(EmailQueueWorker::class);
        self::assertInstanceOf(EmailQueueWorker::class, $worker);
        $worker->processBatch();
        [$restarted, $fresh] = $this->application();
        $next = $fresh->get(EmailQueueWorker::class);
        self::assertInstanceOf(EmailQueueWorker::class, $next);
        $next->processBatch();
        self::assertSame(0, $this->transport->calls);
        self::assertSame(202, $this->request($restarted)->getStatusCode());
        $next->processBatch();
        self::assertSame(1, $this->transport->calls, 'The worker must really send the positive control.');
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_synthetic_requests'));
    }
    public function testRepeatedCallsAppendOnlyEncryptedInertRecordsWithExplicitExpiry(): void
    {
        [$app] = $this->application();
        $first = $this->json($this->synthetic($app));
        $second = $this->json($this->synthetic($app));
        self::assertIsArray($first['record']);
        self::assertIsArray($second['record']);
        self::assertNotSame($first['record']['record_id'], $second['record']['record_id']);
        self::assertSame('2', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_synthetic_requests'));
        self::assertSame('consumer-a', self::scalar($this->pdo, 'SELECT consumer_namespace FROM cd_email_synthetic_requests LIMIT 1'));
        self::assertSame('3600', self::scalar($this->pdo, 'SELECT TIMESTAMPDIFF(SECOND,recorded_at,expires_at) FROM cd_email_synthetic_requests LIMIT 1'));
        self::assertStringNotContainsString('fixture@example.invalid', self::scalar($this->pdo, 'SELECT recipient_encrypted FROM cd_email_synthetic_requests LIMIT 1'));
        self::assertStringNotContainsString('activation_link', self::scalar($this->pdo, 'SELECT payload_encrypted FROM cd_email_synthetic_requests LIMIT 1'));
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
    }

    public function testAuthScopeAndUnsupportedIdempotencyDoNotCreateRealWork(): void
    {
        [$app] = $this->application();
        self::assertSame(401, $this->synthetic($app, 'invalid')->getStatusCode());
        self::assertSame(403, $this->synthetic($app, 'unbound')->getStatusCode());
        self::assertSame(422, $this->request($app, path: '/api/v2/email/synthetic')->getStatusCode());
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_synthetic_requests'));
        self::assertSame(202, $this->synthetic($app, 'b')->getStatusCode());
        self::assertSame('consumer-b', self::scalar($this->pdo, 'SELECT consumer_namespace FROM cd_email_synthetic_requests LIMIT 1'));
        self::assertSame(404, $this->request($app, 'GET', path: '/api/v2/email/receipt')->getStatusCode());
        self::assertSame(202, $this->request($app)->getStatusCode(), 'Synthetic calls must not reserve a real identity.');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRetention(): iterable
    {
        foreach (['', '0', '-1', '1.5', '2147483648', 'invalid'] as $value) {
            yield 'retention-' . $value => [$value];
        }
    }

    #[DataProvider('invalidRetention')]
    public function testInvalidServerRetentionFailsClosedWithoutDisablingRealAcceptance(string $value): void
    {
        $_ENV['SYNTHETIC_EMAIL_RETENTION_SECONDS'] = $value;
        [$app] = $this->application();
        $response = $this->synthetic($app);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['error' => 'recording_unavailable', 'recording' => 'unknown'], $this->json($response));
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_synthetic_requests'));
        self::assertSame(202, $this->request($app)->getStatusCode());
    }

    public function testMissingOptionalTableOnlyDisablesSyntheticRecording(): void
    {
        $this->pdo->exec('DROP TABLE cd_email_synthetic_requests');
        [$app] = $this->application();
        $response = $this->synthetic($app);
        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString('SQL', (string) $response->getBody());
        self::assertSame(202, $this->request($app)->getStatusCode());
        self::assertSame(202, $this->request($app, path: '/api/v1/email/enqueue')->getStatusCode());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function commitFailures(): iterable
    {
        yield 'before throw' => ['before-throw', false];
        yield 'before false' => ['before-false', false];
        yield 'after throw' => ['after-throw', true];
        yield 'after false' => ['after-false', true];
    }

    #[DataProvider('commitFailures')]
    public function testUnknownRecordingNeverAcknowledgesAndRetryUsesFreshSession(string $mode, bool $committed): void
    {
        $fault = new AtomicTestPdo();
        $fault->mode = $mode;
        $opened = 0;
        [$app] = $this->application(static function () use ($fault, &$opened): PDO {
            return ++$opened === 1 ? $fault : self::connect();
        });
        $response = $this->synthetic($app);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('unknown', $this->json($response)['recording']);
        self::assertSame(1, $fault->commitCalls);
        self::assertSame($committed ? '1' : '0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_synthetic_requests'));
        self::assertSame(202, $this->synthetic($app)->getStatusCode());
        self::assertSame(2, $opened);
        self::assertSame($committed ? '2' : '1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_synthetic_requests'));
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
    }

    public function testBoundedCleanupNeverRemovesLiveRecordsOrRealAcceptance(): void
    {
        [$app, $container] = $this->application();
        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(202, $this->synthetic($app)->getStatusCode());
        }
        self::assertSame(202, $this->request($app)->getStatusCode());
        $this->pdo->exec('UPDATE cd_email_synthetic_requests SET expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE id<=2');
        $maintenance = $container->get(SyntheticEmailMaintenanceInterface::class);
        self::assertInstanceOf(SyntheticEmailMaintenanceInterface::class, $maintenance);
        self::assertSame(1, $maintenance->purgeExpired(1));
        self::assertSame('2', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_synthetic_requests'));
        self::assertSame(1, $maintenance->purgeExpired(100));
        self::assertSame(0, $maintenance->purgeExpired(100));
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_synthetic_requests'));
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
        self::assertSame(0, $this->transport->calls);
    }

    public function testProductionCompiledContainerCanRecordWithoutResolvingSmtp(): void
    {
        $_ENV['APP_ENV'] = 'production';
        [$app] = $this->application();
        self::assertSame(202, $this->synthetic($app)->getStatusCode());
        self::assertSame(0, $this->transport->calls);
    }

    public function testSharedHttpGuardsAndDistinctRealPathCannotBeBypassed(): void
    {
        [$app] = $this->application();
        $base = (new ServerRequestFactory())->createServerRequest('POST', '/api/v2/email/synthetic', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Api-Key', 'fixture-key-a')->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream(json_encode($this->payload(), JSON_THROW_ON_ERROR)));
        $cases = [
            [$base->withoutHeader('X-Api-Key'), 401],
            [(new ServerRequestFactory())->createServerRequest('POST', '/api/v2/email/synthetic', ['REMOTE_ADDR' => '192.0.2.1'])->withHeader('X-Api-Key', 'fixture-key-a'), 403],
            [$base->withHeader('Content-Type', 'text/plain'), 415],
            [$base->withBody((new StreamFactory())->createStream('{')), 400],
            [$base->withBody((new StreamFactory())->createStream('[]')), 422],
            [$base->withBody((new StreamFactory())->createStream(str_repeat('{', 1048577))), 413],
        ];
        $raw = json_encode($this->payload(), JSON_THROW_ON_ERROR);
        $raw = str_replace('"context":{', '"context":{"0":"ambiguous",', $raw);
        $cases[] = [$base->withBody((new StreamFactory())->createStream($raw)), 422];
        foreach ($cases as [$request, $status]) {
            $response = $app->handle($request);
            self::assertSame($status, $response->getStatusCode());
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        }
        $payload = $this->payload();
        $payload['synthetic'] = true;
        self::assertSame(422, $this->request($app, payload: $payload)->getStatusCode());
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_synthetic_requests'));
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
    }

    public function testExistingRateQuotaIsSharedWithRealEmail(): void
    {
        $redis = new \Redis();
        self::assertTrue($redis->connect((string) getenv('CHANNEL_IT_REDIS_HOST'), 6379, 1));
        self::assertTrue($redis->ping());
        $_ENV['RATE_LIMIT_MAX_REQUESTS'] = '0';
        [$app] = $this->application();
        self::assertSame(429, $this->synthetic($app)->getStatusCode());
        self::assertSame(429, $this->request($app)->getStatusCode());
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_synthetic_requests'));
    }

    public function testMaintenanceCliDeletesOnlyOneExpiredRowAndRejectsInvalidArguments(): void
    {
        [$app] = $this->application();
        self::assertSame(202, $this->synthetic($app)->getStatusCode());
        self::assertSame(202, $this->synthetic($app)->getStatusCode());
        $this->pdo->exec('UPDATE cd_email_synthetic_requests SET expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND');
        self::assertSame(202, $this->request($app)->getStatusCode());
        $environment = getenv();
        foreach ($_ENV as $name => $value) {
            if (is_string($value)) {
                $environment[$name] = $value;
            }
        }
        foreach (['--limit=1', '--limit=1001', '--limit=0', '--unknown', '--help'] as $argument) {
            $process = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', dirname(__DIR__, 2) . '/scripts/purge_synthetic_email.php', $argument],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            if ($argument === '--limit=1') {
                self::assertSame(0, $exit, (string) $error);
                self::assertSame(['purged' => 1], json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR));
            } else {
                self::assertSame($argument === '--help' ? 0 : 2, $exit);
            }
            self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_synthetic_requests'));
        }
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
        self::assertSame(0, $this->transport->calls);
    }

}
