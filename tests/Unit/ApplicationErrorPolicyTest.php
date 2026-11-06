<?php

declare(strict_types=1);

namespace Tests\Unit;

use DI\Container;
use Maatify\ChannelDelivery\Application\Application;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ApplicationErrorPolicyTest extends TestCase
{
    /** @return iterable<string, array{string|bool, bool}> */
    public static function flags(): iterable
    {
        foreach (['false', '0', 'off', 'no', 'invalid', '', false] as $index => $value) {
            yield 'disabled-' . $index => [$value, false];
        }
        yield 'enabled-string' => ['true', true];
        yield 'enabled-bool' => [true, true];
    }

    #[DataProvider('flags')]
    public function testEnvironmentBooleanMatchesIntendedErrorPolicy(string|bool $value, bool $enabled): void
    {
        $before = $_ENV['APP_DEBUG'] ?? null;
        $previousLog = ini_get('error_log');
        $log = tempnam(sys_get_temp_dir(), 'cd-error-policy-');
        if ($log === false) {
            throw new \RuntimeException('Cannot create owned error log fixture.');
        }
        ini_set('error_log', $log);
        try {
            $_ENV['APP_DEBUG'] = $value;
            $app = Application::create(new Container([ResponseFactoryInterface::class => new ResponseFactory()]));
            $app->get('/fixture-error', function (): never {
                throw new \RuntimeException('fixture-sensitive-detail');
            });
            $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/fixture-error')->withHeader('Accept', 'application/json'));
            self::assertSame(500, $response->getStatusCode());
            self::assertStringContainsString('fixture-sensitive-detail', (string) file_get_contents($log), 'The error must still be logged server-side.');
            if ($enabled) {
                self::assertStringContainsString('fixture-sensitive-detail', (string) $response->getBody());
            } else {
                self::assertStringNotContainsString('fixture-sensitive-detail', (string) $response->getBody());
            }
        } finally {
            ini_set('error_log', is_string($previousLog) ? $previousLog : '');
            unlink($log);
            if ($before === null) {
                unset($_ENV['APP_DEBUG']);
            } else {
                $_ENV['APP_DEBUG'] = $before;
            }
        }
    }
}
