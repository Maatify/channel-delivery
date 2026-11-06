<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Email;

use PDO;
use RuntimeException;

/** Bindings are operator-owned, stable across key rotation, and never supplied by an HTTP caller. */
final readonly class PdoConsumerNamespaceRepository implements ConsumerNamespaceRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findForApiKey(int $apiKeyId): ?string
    {
        $statement = $this->pdo->prepare('SELECT consumer_namespace FROM cd_email_consumer_bindings WHERE api_key_id = :id');
        if ($statement === false || !$statement->execute(['id' => $apiKeyId])) {
            throw new RuntimeException('Consumer binding lookup is unavailable.');
        }
        $value = $statement->fetchColumn();
        if ($value === false) {
            return null;
        }
        if (!is_string($value) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/', $value) !== 1) {
            throw new RuntimeException('Consumer binding is invalid.');
        }
        return $value;
    }
}
