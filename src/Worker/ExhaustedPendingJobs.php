<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Worker;

use PDO;
use RuntimeException;

/** The legacy reset could return an exhausted job to pending before this rollout. */
final class ExhaustedPendingJobs extends RuntimeException
{
    // Compatibility gate for the four-attempt cap in the qualified email-delivery 1.3.0 lock.
    public const ATTEMPT_LIMIT = 4;

    public static function exist(PDO $pdo): bool
    {
        $statement = $pdo->query("SELECT 1 FROM cd_email_queue WHERE status = 'pending' AND attempts >= " . self::ATTEMPT_LIMIT . ' LIMIT 1');
        if ($statement === false) {
            throw new RuntimeException('Unable to check for exhausted pending email jobs.');
        }
        $found = $statement->fetchColumn() !== false;
        $statement->closeCursor();

        return $found;
    }
}
