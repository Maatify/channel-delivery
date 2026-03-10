-- ============================================================
-- Migration: 003_optimize_worker_poll_index.sql
--
-- Problem (Jules Audit):
--   idx_worker_poll (status, scheduled_at, priority, id)
--   The range condition `scheduled_at <= NOW()` prevents MySQL
--   from using subsequent columns for ORDER BY priority, id.
--   This causes a filesort on every worker poll as the queue grows.
--
-- Fix:
--   Replace with two targeted indexes:
--
--   1. idx_worker_poll_priority (status, priority, id)
--      Supports ORDER BY priority ASC, id ASC without filesort.
--      MySQL uses this for ordered iteration; scheduled_at and
--      retry_after become post-filter conditions on the matched rows.
--
--   2. idx_worker_retry (status, retry_after, priority, id)
--      Dedicated index for the retry path (retry_after IS NOT NULL).
--      The worker query splits into two logical paths via the
--      (retry_after IS NULL OR retry_after <= NOW()) predicate.
--
-- Note: idx_retry already covers (status, retry_after, attempts)
--       and is kept unchanged for the recovery/audit path.
-- ============================================================

ALTER TABLE `cd_email_queue`
    DROP INDEX `idx_worker_poll`,
    ADD  INDEX `idx_worker_poll_priority` (`status`, `priority`, `id`),
    ADD  INDEX `idx_worker_retry`         (`status`, `retry_after`, `priority`, `id`);