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
    private const MAX_DOCUMENT_BYTES = 4096;

    private bool $failing = false;

    public function __construct(
        private readonly string          $path,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function write(EmailWorkerState $state): void
    {
        $problem = null;
        set_error_handler(static function (int $severity, string $message) use (&$problem): bool {
            $problem = $message;

            return true;
        });
        try {
            $written = $this->writeAtomically(json_encode($state->toArray(), JSON_THROW_ON_ERROR) . "\n");
        } catch (\Random\RandomException $failure) {
            $written = false;
            $problem = $failure->getMessage();
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

    private function writeAtomically(string $document): bool
    {
        $temporary = $this->path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = fopen($temporary, 'xb');
        if ($handle === false) {
            return false;
        }

        $bytesWritten = fwrite($handle, $document);
        $closed = fclose($handle);
        if ($bytesWritten === strlen($document) && $closed && rename($temporary, $this->path)) {
            return true;
        }
        // Remove only the file created by this call.
        unlink($temporary);

        return false;
    }

    /** Null when the file is missing, unreadable or not a worker state document. */
    public static function read(string $path): ?EmailWorkerState
    {
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        $contents = @file_get_contents($path, false, null, 0, self::MAX_DOCUMENT_BYTES + 1);
        if ($contents === false || strlen($contents) > self::MAX_DOCUMENT_BYTES) {
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
