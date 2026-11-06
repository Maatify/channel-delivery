<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Email;

interface ConsumerNamespaceRepositoryInterface
{
    public function findForApiKey(int $apiKeyId): ?string;
}
