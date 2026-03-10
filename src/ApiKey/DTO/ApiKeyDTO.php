<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\ApiKey\DTO;

final readonly class ApiKeyDTO
{
    /**
     * @param list<string> $ipWhitelist
     */
    public function __construct(
        public int    $id,
        public string $name,
        public string $keyHash,
        public array  $ipWhitelist,
        public bool   $isActive,
    ) {
    }
}
