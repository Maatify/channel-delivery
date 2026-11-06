<?php

declare(strict_types=1);

namespace Tests\Support;

use PDOStatement;

final class AtomicTestStatement extends PDOStatement
{
    protected function __construct(private AtomicTestPdo $connection)
    {
    }

    /** @param array<array-key, mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        if ($this->connection->beforeExecute !== null) {
            ($this->connection->beforeExecute)($this->queryString);
        }
        $result = parent::execute($params);
        if ($this->connection->afterExecute !== null) {
            ($this->connection->afterExecute)($this->queryString);
        }
        return $result;
    }
}
