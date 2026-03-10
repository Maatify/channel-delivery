-- ============================================================
-- Migration: cd_email_queue
-- ADR-008: Email Delivery as Independent Channel Queue
-- Schema is LOCKED — do not alter without a new ADR
--
-- Improvements over v1:
--   1. prefix cd_ for shared-database compatibility
--   2. idx_worker_poll includes priority + id for correct ORDER BY
--   3. recipient_iv / payload_iv → varbinary(12) matches AES-GCM IV
--   4. retry_after column for exponential backoff
-- ============================================================

CREATE TABLE `cd_email_queue` (
                                  `id`                  bigint UNSIGNED                                                          NOT NULL AUTO_INCREMENT,

    -- Entity reference
                                  `entity_type`         varchar(32)  COLLATE utf8mb4_unicode_ci                                 NOT NULL,
                                  `entity_id`           varchar(64)  COLLATE utf8mb4_unicode_ci                                 DEFAULT NULL,

    -- Encrypted recipient (AES-256-GCM — raw binary)
                                  `recipient_encrypted` varbinary(512)                                                          NOT NULL,
                                  `recipient_iv`        varbinary(12)                                                           NOT NULL,  -- AES-GCM: 96-bit IV
                                  `recipient_tag`       varbinary(16)                                                           NOT NULL,  -- AES-GCM: 128-bit tag
                                  `recipient_key_id`    varchar(64)  COLLATE utf8mb4_unicode_ci                                 NOT NULL,

    -- Encrypted payload (AES-256-GCM — raw binary)
                                  `payload_encrypted`   longblob                                                                NOT NULL,
                                  `payload_iv`          varbinary(12)                                                           NOT NULL,  -- AES-GCM: 96-bit IV
                                  `payload_tag`         varbinary(16)                                                           NOT NULL,  -- AES-GCM: 128-bit tag
                                  `payload_key_id`      varchar(64)  COLLATE utf8mb4_unicode_ci                                 NOT NULL,

    -- Template binding
                                  `template_key`        varchar(100) COLLATE utf8mb4_unicode_ci                                 NOT NULL,
                                  `language`            varchar(5)   COLLATE utf8mb4_unicode_ci                                 NOT NULL,

    -- Delivery metadata
                                  `sender_type`         tinyint UNSIGNED                                                        NOT NULL,
                                  `priority`            tinyint UNSIGNED                                                        NOT NULL DEFAULT '5',
                                  `status`              enum('pending','processing','sent','failed','skipped')
                                                            COLLATE utf8mb4_unicode_ci                                          NOT NULL DEFAULT 'pending',
                                  `attempts`            tinyint UNSIGNED                                                        NOT NULL DEFAULT '0',
                                  `last_error`          varchar(128) COLLATE utf8mb4_unicode_ci                                 NOT NULL DEFAULT '',

    -- Scheduling & retry backoff
                                  `scheduled_at`        datetime                                                                NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                  `retry_after`         datetime                                                                DEFAULT NULL,
                                  `sent_at`             datetime                                                                DEFAULT NULL,
                                  `created_at`          timestamp                                                               NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                  `updated_at`          timestamp                                                               NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                                  PRIMARY KEY (`id`),

    -- Worker polling: covers WHERE + ORDER BY in one index
                                  KEY `idx_worker_poll`          (`status`, `scheduled_at`, `priority`, `id`),

    -- Retry: find failed jobs eligible for requeue
                                  KEY `idx_retry`                (`status`, `retry_after`, `attempts`),

    -- Audit / lookup by entity
                                  KEY `idx_email_queue_entity`   (`entity_type`, `entity_id`),

                                  KEY `idx_email_queue_template` (`template_key`),
                                  KEY `idx_email_queue_language` (`language`)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
    COMMENT='ADR-008: Encrypted async email delivery queue — LOCKED SCHEMA';