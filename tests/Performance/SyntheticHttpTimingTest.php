<?php

declare(strict_types=1);

namespace Tests\Performance;

use Tests\Support\AtomicHttpMysqlTestCase;

/** Optional local smoke measurement; excluded from the normal CI suite. No constant-time assertion. */
final class SyntheticHttpTimingTest extends AtomicHttpMysqlTestCase
{
    /** @return array{status: int, ms: float} */
    private function call(int $port, string $path, string $raw, ?string $key, string $auth = 'fixture-key-a'): array
    {
        $curl = curl_init('http://127.0.0.1:' . $port . $path);
        self::assertNotFalse($curl);
        $headers = ['Content-Type: application/json', 'X-Api-Key: ' . $auth];
        if ($key !== null) {
            $headers[] = 'Idempotency-Key: ' . $key;
        }
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $raw, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 5000]);
        $start = hrtime(true);
        $body = curl_exec($curl);
        $elapsed = (hrtime(true) - $start) / 1000000;
        self::assertNotFalse($body, curl_error($curl));
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        unset($curl);
        return ['status' => $status, 'ms' => $elapsed];
    }

    public function testLoopbackHttpDistributionsAndKnownDelaySensitivity(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($socket);
        $log = tempnam(sys_get_temp_dir(), 'synthetic-http-timing-');
        self::assertNotFalse($log);
        $environment = getenv();
        foreach ($_ENV as $name => $value) {
            if (is_string($value)) {
                $environment[$name] = $value;
            }
        }
        $server = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', '127.0.0.1:' . $port,
            dirname(__DIR__) . '/Support/synthetic-timing-router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, $environment);
        self::assertIsResource($server);
        try {
            $deadline = microtime(true) + 5;
            $connected = false;
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
            $raw = json_encode($this->payload(), JSON_THROW_ON_ERROR);
            self::assertSame(202, $this->call($port, '/api/v2/email/enqueue', $raw, 'replay')['status']);
            $large = $this->payload();
            $large['context'] = ['padding' => str_repeat('x', 16384)];
            $largeRaw = json_encode($large, JSON_THROW_ON_ERROR);
            $cases = [
                'real_v1_legacy' => ['/api/v1/email/enqueue', $raw, false, 'fixture-key-a', 202],
                'real_new' => ['/api/v2/email/enqueue', $raw, true, 'fixture-key-a', 202],
                'synthetic' => ['/api/v2/email/synthetic', $raw, false, 'fixture-key-a', 202],
                'real_replay' => ['/api/v2/email/enqueue', $raw, true, 'fixture-key-a', 202],
                'real_16KiB' => ['/api/v2/email/enqueue', $largeRaw, true, 'fixture-key-a', 202],
                'synthetic_16KiB' => ['/api/v2/email/synthetic', $largeRaw, false, 'fixture-key-a', 202],
                'real_bad_json' => ['/api/v2/email/enqueue', '{', true, 'fixture-key-a', 400],
                'synthetic_bad_json' => ['/api/v2/email/synthetic', '{', false, 'fixture-key-a', 400],
                'real_unauthorized' => ['/api/v2/email/enqueue', $raw, true, 'invalid', 401],
                'synthetic_unauthorized' => ['/api/v2/email/synthetic', $raw, false, 'invalid', 401],
                'synthetic_delay_50ms_control' => ['/__fixture/synthetic-delayed', $raw, false, 'fixture-key-a', 202],
            ];
            $samples = [];
            for ($i = -3; $i < 20; ++$i) {
                $names = array_keys($cases);
                if ($i % 2 === 0) {
                    $names = array_reverse($names);
                }
                foreach ($names as $name) {
                    [$path, $payload, $keyed, $auth, $status] = $cases[$name];
                    $key = $keyed ? ($name === 'real_replay' ? 'replay' : $name . ':' . $i) : null;
                    $result = $this->call($port, $path, $payload, $key, $auth);
                    self::assertSame($status, $result['status'], $name);
                    if ($i >= 0) {
                        $samples[$name][] = $result['ms'];
                    }
                }
            }
            // Fixture-only unavailable storage and recovery, never an application migration.
            $this->pdo->exec('DROP TABLE cd_email_enqueue_receipts');
            $this->pdo->exec('DROP TABLE cd_email_synthetic_requests');
            for ($i = 0; $i < 20; ++$i) {
                foreach (['real_missing_table' => '/api/v2/email/enqueue', 'synthetic_missing_table' => '/api/v2/email/synthetic'] as $name => $path) {
                    $result = $this->call($port, $path, $raw, $name === 'real_missing_table' ? 'failed:' . $i : null);
                    self::assertSame(503, $result['status']);
                    $samples[$name][] = $result['ms'];
                }
            }
            foreach (['002_email_enqueue_receipts.sql', '004_email_synthetic_requests.sql'] as $file) {
                $sql = file_get_contents(dirname(__DIR__, 2) . '/vendor/maatify/email-delivery/schema/mysql/' . $file);
                self::assertNotFalse($sql);
                $this->pdo->exec($sql);
            }
            self::assertSame(202, $this->call($port, '/api/v2/email/enqueue', $raw, 'recovered')['status']);
            self::assertSame(202, $this->call($port, '/api/v2/email/synthetic', $raw, null)['status']);
            $summary = [];
            foreach ($samples as $name => $values) {
                sort($values);
                $summary[$name] = ['n' => count($values), 'p50_ms' => $values[9], 'p95_ms' => $values[18], 'min_ms' => $values[0], 'max_ms' => $values[19]];
            }
            self::assertGreaterThan(20.0, $summary['synthetic_delay_50ms_control']['p50_ms'] - $summary['synthetic']['p50_ms']);
            echo "\nSYNTHETIC_TIMING " . json_encode(['php' => PHP_VERSION, 'scope' => 'local loopback HTTP, sequential, idle worker, 20 samples/case after 3 warmups; no timing-equivalence claim', 'summary' => $summary], JSON_THROW_ON_ERROR) . "\n";
        } finally {
            proc_terminate($server);
            proc_close($server);
            unlink($log);
        }
    }
}
