<?php

declare(strict_types=1);

namespace Tests\Support;

use Psr\Log\AbstractLogger;

/** Keeps every record in memory for assertions. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /** @param array<mixed> $context */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => is_string($level) ? $level : 'unknown', 'message' => (string) $message, 'context' => $context];
    }

    /** @return list<string> */
    public function messages(?string $level = null): array
    {
        $messages = [];
        foreach ($this->records as $record) {
            if ($level === null || $record['level'] === $level) {
                $messages[] = $record['message'];
            }
        }

        return $messages;
    }

    public function count(string $message): int
    {
        return count(array_filter($this->records, static fn (array $record): bool => $record['message'] === $message));
    }
}
