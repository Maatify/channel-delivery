<?php

/**
 * @copyright   ©2026 Maatify.dev
 * @Library     maatify/channel-delivery
 * @Project     maatify:channel-delivery
 * @author      Mohamed Abdulalim (megyptm) <mohamed@maatify.dev>
 * @since       2026-03-10 00:16
 * @see         https://www.maatify.dev Maatify.dev
 * @link        https://github.com/Maatify/channel-delivery view Project on GitHub
 * @note        Distributed in the hope that it will be useful - WITHOUT WARRANTY.
 */

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Crypto;

use Maatify\Crypto\Contract\CryptoContextProviderInterface;

final class ChannelDeliveryCryptoContextProvider implements CryptoContextProviderInterface
{
    // ── Identifier PII ────────────────────────────────────────
    public function identifierEmail(): string
    {
        return 'identifier:email:v1';
    }

    public function identifierPhone(): string
    {
        return 'identifier:phone:v1';
    }

    // ── Notification (runtime) ────────────────────────────────
    public function notificationEmailRecipient(): string
    {
        return 'notification:email:recipient:v1';
    }

    public function notificationEmailPayload(): string
    {
        return 'notification:email:payload:v1';
    }

    // ── Email Queue (at-rest) ─────────────────────────────────
    public function emailQueueRecipient(): string
    {
        return 'email:queue:recipient:v1';
    }

    public function emailQueuePayload(): string
    {
        return 'email:queue:payload:v1';
    }

    // ── MFA / System ──────────────────────────────────────────
    public function totpSeed(): string
    {
        return 'totp:seed:v1';
    }

    public function systemSecret(): string
    {
        return 'system:secret:v1';
    }

    // ── Abuse Protection ──────────────────────────────────────
    public function abuseProtection(): string
    {
        return 'abuse:protection:signal:v1';
    }
}
