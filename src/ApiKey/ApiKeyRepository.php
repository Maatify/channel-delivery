<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\ApiKey;

use Maatify\ChannelDelivery\ApiKey\DTO\ApiKeyDTO;
use PDO;

final readonly class ApiKeyRepository implements ApiKeyRepositoryInterface
{
    private const TABLE = 'cd_api_keys';

    public function __construct(
        private PDO $pdo
    ) {
    }

    public function findByRawKey(string $rawKey): ?ApiKeyDTO
    {
        $hash  = hash('sha256', $rawKey);
        $table = self::TABLE;

        $stmt = $this->pdo->prepare(
            "SELECT id, name, key_hash, ip_whitelist, is_active, last_used_at
             FROM `{$table}`
             WHERE key_hash = :hash
               AND is_active = 1
             LIMIT 1"
        );

        $stmt->execute(['hash' => $hash]);

        /** @var array{id: int|string, name: string, key_hash: string, ip_whitelist: string, is_active: int|string, last_used_at: string|null}|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        /** @var list<string> $ipWhitelist */
        $ipWhitelist = json_decode($row['ip_whitelist'], true) ?? [];

        $lastUsedAt = isset($row['last_used_at'])
            ? new \DateTimeImmutable($row['last_used_at'])
            : null;

        return new ApiKeyDTO(
            id:          (int) $row['id'],
            name:        $row['name'],
            keyHash:     $row['key_hash'],
            ipWhitelist: $ipWhitelist,
            isActive:    (bool) $row['is_active'],
            lastUsedAt:  $lastUsedAt,
        );
    }

    public function touchLastUsed(int $id): void
    {
        $table = self::TABLE;

        $this->pdo->prepare(
            "UPDATE `{$table}` SET last_used_at = NOW() WHERE id = :id"
        )->execute(['id' => $id]);
    }

    /**
     * @param list<string> $ipWhitelist
     */
    public function create(string $name, string $rawKey, array $ipWhitelist): void
    {
        $table = self::TABLE;

        $this->pdo->prepare(
            "INSERT INTO `{$table}` (name, key_hash, ip_whitelist)
             VALUES (:name, :key_hash, :ip_whitelist)"
        )->execute([
            'name'         => $name,
            'key_hash'     => hash('sha256', $rawKey),
            'ip_whitelist' => json_encode($ipWhitelist, JSON_THROW_ON_ERROR),
        ]);
    }
}
