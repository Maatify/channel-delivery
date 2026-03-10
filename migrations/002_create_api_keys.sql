-- ============================================================
-- Migration: cd_api_keys
-- Stores API keys with per-key IP whitelists
-- ============================================================

CREATE TABLE `cd_api_keys` (
                               `id`           bigint UNSIGNED                                    NOT NULL AUTO_INCREMENT,
                               `name`         varchar(64)  COLLATE utf8mb4_unicode_ci            NOT NULL COMMENT 'Human-readable label (e.g. iam-core)',
                               `key_hash`     varchar(64)  COLLATE utf8mb4_unicode_ci            NOT NULL COMMENT 'SHA-256 hash of the raw key',
                               `ip_whitelist` json                                               NOT NULL COMMENT 'Array of allowed IPv4/IPv6 addresses',
                               `is_active`    tinyint(1)                                         NOT NULL DEFAULT 1,
                               `last_used_at` datetime                                           DEFAULT NULL,
                               `created_at`   timestamp                                          NOT NULL DEFAULT CURRENT_TIMESTAMP,
                               `updated_at`   timestamp                                          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                               PRIMARY KEY (`id`),
                               UNIQUE KEY `uq_key_hash` (`key_hash`),
                               KEY `idx_active`         (`is_active`)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
    COMMENT='API keys with per-key IP whitelists for channel-delivery';