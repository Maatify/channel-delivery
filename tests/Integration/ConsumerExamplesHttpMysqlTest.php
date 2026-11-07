<?php

declare(strict_types=1);

namespace Tests\Integration;

use Maatify\EmailDelivery\Worker\EmailQueueWorker;
use Tests\Support\AtomicHttpMysqlTestCase;

/** Run distributed examples against a real loopback HTTP app and fake only SMTP delivery. */
final class ConsumerExamplesHttpMysqlTest extends AtomicHttpMysqlTestCase
{
    public function testConsumerScriptsPreserveRealReplayAndSyntheticIsolationAndRenderWelcome(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        self::assertIsString($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($listener);
        $log = tempnam(sys_get_temp_dir(), 'consumer-example-server-');
        self::assertNotFalse($log);
        $env = getenv();
        foreach ($_ENV as $name => $value) {
            if (is_string($value)) {
                $env[$name] = $value;
            }
        }
        $env['CD_BASE_URL'] = 'http://127.0.0.1:' . $port;
        $env['CD_API_KEY'] = 'fixture-key-a';
        $env['CD_REQUEST_KEY'] = 'fixture-example-intent';
        $root = dirname(__DIR__, 2);
        $server = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', '127.0.0.1:' . $port,
            $root . '/tests/Support/synthetic-timing-router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, $env);
        self::assertIsResource($server);
        try {
            $connected = false;
            $deadline = microtime(true) + 5;
            do {
                $ready = @fsockopen('127.0.0.1', $port);
                if (is_resource($ready)) {
                    fclose($ready);
                    $connected = true;
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertTrue($connected, (string) file_get_contents($log));
            $accepted = null;
            foreach (['enqueue-v1.sh', 'enqueue-v2.sh', 'receipt-v2.sh', 'enqueue-v2.sh', 'synthetic.sh', 'basic-usage.php', 'test_enqueue.php'] as $name) {
                $command = $name === 'basic-usage.php'
                    ? [PHP_BINARY, $root . '/docs/examples/basic-usage.php']
                    : ($name === 'test_enqueue.php' ? [PHP_BINARY, $root . '/scripts/test_enqueue.php'] : ['bash', $root . '/examples/' . $name]);
                $child = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $handles, $root, $env);
                self::assertIsResource($child);
                $output = stream_get_contents($handles[1]);
                $error = stream_get_contents($handles[2]);
                fclose($handles[1]);
                fclose($handles[2]);
                self::assertSame(0, proc_close($child), $name . ': ' . (string) $error);
                self::assertSame('', $error);
                if ($name === 'basic-usage.php') {
                    self::assertStringStartsWith("HTTP 202\n", (string) $output);
                    continue;
                }
                if ($name === 'test_enqueue.php') {
                    self::assertStringContainsString('Status : 202', (string) $output);
                    continue;
                }
                /** @var array<string, mixed> $data */
                $data = json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR);
                if ($name === 'enqueue-v1.sh') {
                    self::assertSame(['status' => 'queued'], $data);
                } elseif ($name === 'synthetic.sh') {
                    self::assertSame('recorded', $data['status']);
                } elseif ($accepted === null) {
                    $accepted = $data;
                    self::assertSame('accepted', $data['status']);
                } else {
                    self::assertSame($accepted, $data, 'Lookup and replay must retain the first receipt.');
                }
            }
            self::assertSame('4', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
            self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
            self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_synthetic_requests'));
            [, $container] = $this->application();
            $worker = $container->get(EmailQueueWorker::class);
            self::assertInstanceOf(EmailQueueWorker::class, $worker);
            $worker->processBatch();
            self::assertSame(4, $this->transport->calls, 'All welcome examples must really render with the required context.');
        } finally {
            proc_terminate($server);
            proc_close($server);
            unlink($log);
        }
    }
}
