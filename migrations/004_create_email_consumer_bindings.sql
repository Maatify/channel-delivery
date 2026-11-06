-- Additive auth binding for v2 only. Real receipt/queue binding DDL comes from email-delivery/schema/mysql/002 and003.
-- Never derive this namespace from the raw secret, mutable key name, or caller payload.
CREATE TABLE cd_email_consumer_bindings (
    api_key_id BIGINT UNSIGNED NOT NULL,
    consumer_namespace VARBINARY(128) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (api_key_id),
    CONSTRAINT fk_email_consumer_api_key FOREIGN KEY (api_key_id) REFERENCES cd_api_keys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
