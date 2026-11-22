<?php

declare(strict_types=1);

namespace Tests\Support;

/** Mutable script for one fake worker connection in runner unit tests. */
final class ScriptedConnection
{
    public bool $alive = true;
    public bool $inTransaction = false;
    public ?\Throwable $batchFailure = null;
    /** Connection state after a failed batch: true keeps it answering, false drops it. */
    public bool $aliveAfterBatchFailure = true;
    public int $batches = 0;
    public int $probes = 0;
}
