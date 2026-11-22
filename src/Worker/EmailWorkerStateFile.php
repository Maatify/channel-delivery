<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Worker;

use Psr\Log\LoggerInterface;

/**
 * JSON heartbeat a worker rewrites after every loop iteration. Each write replaces
 * the file atomically (temporary file + rename), so a reader never sees a partial
 * document. A write failure never stops the worker; it is logged once per outage.
 * Give each worker process its own path.
 */
final class EmailWorkerStateFile
{
    private bool $failing = false;

    public function __construct(
        private readonly string          $path,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function write(EmailWorkerState $state): void
    {
        $temporary = $this->path . '.' . getmypid() . '.tmp';
        $problem = null;
        set_error_handler(static function (int $severity, string $message) use (&$problem): bool {
            $problem = $message;

            return true;
        });
        try {
            $written = file_put_contents($temporary, json_encode($state->toArray(), JSON_THROW_ON_ERROR) . "\n") !== false
                && rename($temporary, $this->path);
            if (!$written && is_file($temporary)) {
                unlink($temporary);
            }
        } finally {
            restore_error_handler();
        }

        if (!$written && !$this->failing) {
            $this->logger->warning('Worker state file not written', ['path' => $this->path, 'reason' => $problem]);
        } elseif ($written && $this->failing) {
            $this->logger->info('Worker state file written again', ['path' => $this->path]);
        }
        $this->failing = !$written;
    }

    /** Null when the file is missing, unreadable or not a worker state document. */
    public static function read(string $path): ?EmailWorkerState
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }
        try {
            $data = json_decode($contents, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? EmailWorkerState::fromArray($data) : null;
    }
}
