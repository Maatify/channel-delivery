<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use PDO;
use PDOException;

/** Real fixture PDO; only declared transaction/statement boundaries are instrumented. */
final class AtomicTestPdo extends PDO
{
    public string $mode = 'none';
    public int $commitCalls = 0;
    /** @var (Closure(string): void)|null */
    public ?Closure $beforeExecute = null;
    /** @var (Closure(string): void)|null */
    public ?Closure $afterExecute = null;

    public function __construct()
    {
        $dsn = getenv('CHANNEL_IT_DSN');
        if ($dsn === false || !str_starts_with($dsn, 'mysql:')) {
            throw new \RuntimeException('An explicit isolated MySQL fixture is required.');
        }
        parent::__construct($dsn, (string) getenv('CHANNEL_IT_USER'), (string) getenv('CHANNEL_IT_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        $statement = parent::query('SELECT DATABASE()');
        if ($statement === false) {
            throw new \RuntimeException('Cannot verify fixture database.');
        }
        $database = $statement->fetchColumn();
        if (!is_string($database) || !str_ends_with($database, '_test')) {
            throw new \RuntimeException('Refusing non-test database.');
        }
        parent::exec("SET SESSION time_zone='+00:00'");
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [AtomicTestStatement::class, [$this]]);
    }

    public function commit(): bool
    {
        ++$this->commitCalls;
        if ($this->mode === 'none') {
            return parent::commit();
        }
        if (str_starts_with($this->mode, 'after-')) {
            parent::commit();
        }
        if (str_ends_with($this->mode, '-throw')) {
            throw new PDOException('Fixture commit response lost.');
        }
        return false;
    }
}
