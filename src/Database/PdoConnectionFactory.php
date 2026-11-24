<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Database;

use PDO;
use Pdo\Mysql;

/**
 * Opens a fresh exception-mode MySQL session on every call.
 *
 * Session settings owned by this service are applied by the driver on each new
 * connection (Pdo\Mysql::ATTR_INIT_COMMAND), so a replacement connection behaves
 * like the first one and does not depend on the server's global defaults.
 *
 * The factory never reconnects by itself. Replacing a connection is the caller's
 * decision and must never happen while that connection has an open transaction:
 * the statements before and after the swap would silently run in different
 * sessions. Only the worker replaces its own connection, outside transactions.
 */
final readonly class PdoConnectionFactory
{
    /** MySQL's own compiled default, so a host-level override does not change worker behavior. */
    public const DEFAULT_WAIT_TIMEOUT_SECONDS = 28800;

    /** MySQL's documented upper bound for wait_timeout. */
    public const MAX_WAIT_TIMEOUT_SECONDS = 31536000;

    public function __construct(
        private string $dsn,
        private string $username,
        #[\SensitiveParameter]
        private string $password,
        private int $waitTimeoutSeconds = self::DEFAULT_WAIT_TIMEOUT_SECONDS,
    ) {
        if ($waitTimeoutSeconds < 1 || $waitTimeoutSeconds > self::MAX_WAIT_TIMEOUT_SECONDS) {
            throw new \InvalidArgumentException(sprintf(
                'The session wait_timeout must be 1..%d seconds.',
                self::MAX_WAIT_TIMEOUT_SECONDS
            ));
        }
    }

    /**
     * Reads DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD and
     * DB_SESSION_WAIT_TIMEOUT. An unset or empty DB_SESSION_WAIT_TIMEOUT uses the
     * default; any other value must be an integer number of seconds in range.
     *
     * @param array<mixed> $environment
     */
    public static function fromEnvironment(array $environment): self
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            self::text($environment, 'DB_HOST', '127.0.0.1'),
            self::text($environment, 'DB_PORT', '3306'),
            self::text($environment, 'DB_DATABASE', 'channel_delivery')
        );

        return new self(
            $dsn,
            self::text($environment, 'DB_USERNAME', ''),
            self::text($environment, 'DB_PASSWORD', ''),
            self::waitTimeout($environment['DB_SESSION_WAIT_TIMEOUT'] ?? null),
        );
    }

    public function create(): PDO
    {
        return $this->open($this->sessionInitCommand());
    }

    public function createForWorker(): PDO
    {
        return $this->open($this->sessionInitCommand() . ', autocommit = 1');
    }

    private function open(string $initCommand): PDO
    {
        return new PDO($this->dsn, $this->username, $this->password, [
            PDO::ATTR_ERRMODE             => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES    => false,
            Mysql::ATTR_INIT_COMMAND     => $initCommand,
        ]);
    }

    /** The statement the driver runs on every new connection before it is handed out. */
    public function sessionInitCommand(): string
    {
        return sprintf('SET SESSION wait_timeout = %d', $this->waitTimeoutSeconds);
    }

    /** @param array<mixed> $environment */
    private static function text(array $environment, string $name, string $default): string
    {
        $value = $environment[$name] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    private static function waitTimeout(mixed $configured): int
    {
        if ($configured === null || $configured === '') {
            return self::DEFAULT_WAIT_TIMEOUT_SECONDS;
        }
        if (!is_string($configured) || preg_match('/\A[1-9][0-9]{0,7}\z/', $configured) !== 1
            || (int) $configured > self::MAX_WAIT_TIMEOUT_SECONDS) {
            throw new \InvalidArgumentException(sprintf(
                'DB_SESSION_WAIT_TIMEOUT must be an integer number of seconds, 1..%d.',
                self::MAX_WAIT_TIMEOUT_SECONDS
            ));
        }

        return (int) $configured;
    }
}
