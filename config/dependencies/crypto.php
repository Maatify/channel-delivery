<?php

/**
 * @copyright   ©2026 Maatify.dev
 * @Library     maatify/channel-delivery
 * @Project     maatify:channel-delivery
 * @author      Mohamed Abdulalim (megyptm) <mohamed@maatify.dev>
 * @since       2026-03-10 00:19
 * @see         https://www.maatify.dev Maatify.dev
 * @link        https://github.com/Maatify/channel-delivery view Project on GitHub
 * @note        Distributed in the hope that it will be useful - WITHOUT WARRANTY.
 */

declare(strict_types=1);

// ============================================================
// config/dependencies/crypto.php
//
// PHP-DI definitions for the full Crypto pipeline:
// KeyRotation → HKDF → CryptoContextFactory → CryptoProvider
// ============================================================

use Maatify\ChannelDelivery\Crypto\ChannelDeliveryCryptoContextProvider;
use Maatify\Crypto\Contract\CryptoContextProviderInterface;
use Maatify\Crypto\DX\CryptoContextFactory;
use Maatify\Crypto\DX\CryptoDirectFactory;
use Maatify\Crypto\DX\CryptoProvider;
use Maatify\Crypto\HKDF\HKDFService;
use Maatify\Crypto\KeyRotation\DTO\CryptoKeyDTO;
use Maatify\Crypto\KeyRotation\KeyProviderInterface;
use Maatify\Crypto\KeyRotation\KeyRotationPolicyInterface;
use Maatify\Crypto\KeyRotation\KeyRotationService;
use Maatify\Crypto\KeyRotation\KeyStatusEnum;
use Maatify\Crypto\KeyRotation\Policy\StrictSingleActiveKeyPolicy;
use Maatify\Crypto\KeyRotation\Providers\InMemoryKeyProvider;
use Maatify\Crypto\Reversible\Algorithms\Aes256GcmAlgorithm;
use Maatify\Crypto\Reversible\Registry\ReversibleCryptoAlgorithmRegistry;

use function DI\autowire;
use function DI\factory;
use function DI\get;

return [

    // ── 1. Algorithm Registry ─────────────────────────────────
    ReversibleCryptoAlgorithmRegistry::class => factory(
        function (): ReversibleCryptoAlgorithmRegistry {
            $registry = new ReversibleCryptoAlgorithmRegistry();
            $registry->register(new Aes256GcmAlgorithm());
            return $registry;
        }
    ),

    // ── 2. Key Provider ───────────────────────────────────────
    // ENV format:
    //   CRYPTO_ACTIVE_KEY_ID=v1
    //   CRYPTO_KEYS='[{"id":"v1","key":"<32 raw bytes>"}]'
    //
    // - id == CRYPTO_ACTIVE_KEY_ID → ACTIVE  (encrypt + decrypt)
    // - all others                 → INACTIVE (decrypt only)
    //
    KeyProviderInterface::class => factory(function (): KeyProviderInterface {
        $activeKeyId = $_ENV['CRYPTO_ACTIVE_KEY_ID'] ?? '';
        $keysJson    = $_ENV['CRYPTO_KEYS']           ?? '';

        if ($activeKeyId === '') {
            throw new \RuntimeException('CRYPTO_ACTIVE_KEY_ID is not set.');
        }

        if ($keysJson === '') {
            throw new \RuntimeException('CRYPTO_KEYS is not set.');
        }

        /** @var list<array{id: string, key: string}>|null $entries */
        $entries = json_decode($keysJson, true);

        if (!is_array($entries) || $entries === []) {
            throw new \RuntimeException('CRYPTO_KEYS must be a valid non-empty JSON array.');
        }

        $keys = [];

        foreach ($entries as $entry) {
            $id  = $entry['id']  ?? '';
            $raw = $entry['key'] ?? '';

            if ($id === '' || $raw === '') {
                throw new \RuntimeException(
                    'Each entry in CRYPTO_KEYS must have "id" and "key" fields.'
                );
            }

            if (strlen($raw) !== 32) {
                throw new \RuntimeException(
                    "CRYPTO_KEYS: key with id='{$id}' must be exactly 32 bytes."
                );
            }

            $keys[] = new CryptoKeyDTO(
                id:        $id,
                material:  $raw,
                status:    $id === $activeKeyId ? KeyStatusEnum::ACTIVE : KeyStatusEnum::INACTIVE,
                createdAt: new \DateTimeImmutable()
            );
        }

        return new InMemoryKeyProvider($keys);
    }),

    // ── 3. Key Rotation Policy ────────────────────────────────
    KeyRotationPolicyInterface::class => autowire(StrictSingleActiveKeyPolicy::class),

    // ── 4. Key Rotation Service ───────────────────────────────
    KeyRotationService::class => autowire(KeyRotationService::class),

    // ── 5. HKDF Service ──────────────────────────────────────
    HKDFService::class => autowire(HKDFService::class),

    // ── 6. Crypto Factories ───────────────────────────────────
    CryptoContextFactory::class => autowire(CryptoContextFactory::class),
    CryptoDirectFactory::class  => autowire(CryptoDirectFactory::class),

    // ── 7. CryptoProvider ─────────────────────────────────────
    CryptoProvider::class => autowire(CryptoProvider::class),

    // ── 8. CryptoContextProvider ──────────────────────────────
    CryptoContextProviderInterface::class => autowire(ChannelDeliveryCryptoContextProvider::class),

];