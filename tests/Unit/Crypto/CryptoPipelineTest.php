<?php

declare(strict_types=1);

namespace Tests\Unit\Crypto;

use DateTimeImmutable;
use Maatify\Crypto\DX\CryptoContextFactory;
use Maatify\Crypto\DX\CryptoDirectFactory;
use Maatify\Crypto\DX\CryptoProvider;
use Maatify\Crypto\HKDF\HKDFService;
use Maatify\Crypto\KeyRotation\DTO\CryptoKeyDTO;
use Maatify\Crypto\KeyRotation\KeyRotationService;
use Maatify\Crypto\KeyRotation\KeyStatusEnum;
use Maatify\Crypto\KeyRotation\Policy\StrictSingleActiveKeyPolicy;
use Maatify\Crypto\KeyRotation\Providers\InMemoryKeyProvider;
use Maatify\Crypto\Reversible\Algorithms\Aes256GcmAlgorithm;
use Maatify\Crypto\Reversible\DTO\ReversibleCryptoMetadataDTO;
use Maatify\Crypto\Reversible\ReversibleCryptoAlgorithmEnum;
use Maatify\Crypto\Reversible\Registry\ReversibleCryptoAlgorithmRegistry;
use PHPUnit\Framework\TestCase;

final class CryptoPipelineTest extends TestCase
{
    private CryptoProvider $cryptoProvider;

    protected function setUp(): void
    {
        $registry = new ReversibleCryptoAlgorithmRegistry();
        $registry->register(new Aes256GcmAlgorithm());

        $provider = new InMemoryKeyProvider([
            new CryptoKeyDTO(
                id:        'v1',
                material:  random_bytes(32),
                status:    KeyStatusEnum::ACTIVE,
                createdAt: new DateTimeImmutable()
            ),
        ]);

        $keyRotation = new KeyRotationService($provider, new StrictSingleActiveKeyPolicy());

        $this->cryptoProvider = new CryptoProvider(
            new CryptoContextFactory($keyRotation, new HKDFService(), $registry),
            new CryptoDirectFactory($keyRotation, $registry)
        );
    }

    public function testContextEncryptDecryptRoundTrip(): void
    {
        $plain   = 'user@example.com';
        $context = 'email:queue:recipient:v1';

        $service = $this->cryptoProvider->context($context);

        $encrypted = $service->encrypt($plain);

        /** @var \Maatify\Crypto\Reversible\DTO\ReversibleCryptoEncryptionResultDTO $result */
        $result = $encrypted['result'];

        $decrypted = $service->decrypt(
            $result->cipher,
            $encrypted['key_id'],
            ReversibleCryptoAlgorithmEnum::AES_256_GCM,
            new ReversibleCryptoMetadataDTO($result->iv, $result->tag)
        );

        $this->assertSame($plain, $decrypted);
    }

    public function testDifferentContextsProduceDifferentCiphertexts(): void
    {
        $plain    = 'user@example.com';
        $service1 = $this->cryptoProvider->context('email:queue:recipient:v1');
        $service2 = $this->cryptoProvider->context('email:queue:payload:v1');

        $cipher1 = $service1->encrypt($plain)['result']->cipher;
        $cipher2 = $service2->encrypt($plain)['result']->cipher;

        $this->assertNotSame($cipher1, $cipher2);
    }

    public function testEncryptedDataIsNotPlaintext(): void
    {
        $plain   = 'secret@example.com';
        $service = $this->cryptoProvider->context('email:queue:recipient:v1');

        $cipher = $service->encrypt($plain)['result']->cipher;

        $this->assertStringNotContainsString($plain, $cipher);
    }
}
