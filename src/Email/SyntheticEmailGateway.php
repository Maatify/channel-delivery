<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Email;

use Maatify\Crypto\Contract\CryptoContextProviderInterface;
use Maatify\Crypto\DX\CryptoProvider;
use Maatify\EmailDelivery\Synthetic\DTO\SyntheticEmailRecordDTO;
use Maatify\EmailDelivery\Synthetic\DTO\SyntheticEmailRequestDTO;
use Maatify\EmailDelivery\Synthetic\PdoSyntheticEmailStore;
use Maatify\EmailDelivery\Synthetic\SyntheticEmailMaintenanceInterface;
use Maatify\EmailDelivery\Synthetic\SyntheticEmailRecorderInterface;
use RuntimeException;

/** Lifecycle adapter only. Recording, isolation, expiry and purge remain package-owned. */
final readonly class SyntheticEmailGateway implements SyntheticEmailRecorderInterface, SyntheticEmailMaintenanceInterface
{
    public function __construct(
        private AtomicEmailConnectionFactory $connections,
        private CryptoProvider $crypto,
        private CryptoContextProviderInterface $contexts,
        private string $retentionSeconds,
    ) {
    }

    private function store(): PdoSyntheticEmailStore
    {
        if (preg_match('/\A[1-9][0-9]{0,9}\z/', $this->retentionSeconds) !== 1 || (int) $this->retentionSeconds > 2147483647) {
            // Configuration failure is unavailable, not invalid client input. No default business policy.
            throw new RuntimeException('Explicit synthetic retention is required.');
        }
        return new PdoSyntheticEmailStore($this->connections->open(), $this->crypto, $this->contexts, (int) $this->retentionSeconds);
    }

    public function record(SyntheticEmailRequestDTO $request): SyntheticEmailRecordDTO
    {
        return $this->store()->record($request);
    }

    public function purgeExpired(int $limit = 100): int
    {
        return $this->store()->purgeExpired($limit);
    }
}
