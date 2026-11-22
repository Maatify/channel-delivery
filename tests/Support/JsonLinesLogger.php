<?php

declare(strict_types=1);

namespace Tests\Support;

use Psr\Log\AbstractLogger;

/** Appends one JSON line per record to a file; worker process tests read it back. */
final class JsonLinesLogger extends AbstractLogger
{
    public function __construct(private readonly string $path)
    {
    }

    /** @param array<mixed> $context */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        unset($context['trace']);
        $line = json_encode([
            'time'    => microtime(true),
            'level'   => is_string($level) ? $level : 'unknown',
            'message' => (string) $message,
            'context' => $context,
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
