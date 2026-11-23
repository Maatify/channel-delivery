<?php

declare(strict_types=1);

namespace Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailWorkerCommandTest extends TestCase
{
    /** @param list<string> $options */
    #[DataProvider('invalidOptions')]
    public function testRejectsInvalidOptionsBeforeLoadingTheEnvironment(array $options): void
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/scripts/email_worker.php', ...$options],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(2, proc_close($process));
        self::assertSame('', $stdout);
        self::assertIsString($stderr);
        self::assertMatchesRegularExpression('/\A(?:Expected|Unknown|--loop) [^\n]+\n\z/', $stderr);
    }

    /** @return array<string, array{list<string>}> */
    public static function invalidOptions(): array
    {
        return [
            'batch text' => [['--batch=invalid']],
            'zero batch' => [['--batch=0']],
            'batch overflow' => [['--batch=' . PHP_INT_MAX . '0']],
            'negative sleep' => [['--loop', '--sleep=-1']],
            'repeated option' => [['--batch', '50', '--batch=2']],
            'unknown option' => [['--bacth=50']],
            'unexpected positional argument' => [['--loop', 'unexpected']],
            'flag with value' => [['--loop=false']],
            'missing option value' => [['--state-file', '--loop']],
            'missing final option value' => [['--batch']],
            'out of range connection limit' => [['--max-connection-failures=10000']],
        ];
    }
}
