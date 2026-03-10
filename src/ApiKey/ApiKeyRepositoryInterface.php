<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\ApiKey;

use Maatify\ChannelDelivery\ApiKey\DTO\ApiKeyDTO;

interface ApiKeyRepositoryInterface
{
    public function findByRawKey(string $rawKey): ?ApiKeyDTO;

    public function touchLastUsed(int $id): void;

    /**
     * @param list<string> $ipWhitelist
     */
    public function create(string $name, string $rawKey, array $ipWhitelist): void;
}
