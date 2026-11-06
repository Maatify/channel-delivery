# Service ADR 001: additive atomic email HTTP acceptance

Status: accepted for implementation by owner, 2026-11-06.
Governing scope: Maatify/channel-delivery#6; downstream Athar Platform#684.

## Context

Legacy v1 returns an unkeyed queue acceptance. A timeout followed by retry can
create another job. The queue schema in migration 001 is historically locked
under ADR-008. email-delivery 1.2.0 now owns atomic keyed acceptance and receipts.

## Decision

Add v2 POST acceptance and GET receipt; keep v1 handler, response and worker
semantics. Delegate atomicity/fingerprinting/receipt reconciliation to the
released library. HTTP code owns auth, strict bounded input and response mapping.

Authorize additive extension of the locked queue schema through **installed
library** schema/mysql/002_email_enqueue_receipts.sql and
003_email_queue_receipt_binding.sql; do not edit historical migration 001 or copy
package SQL. Add service migration004 for operator-owned key-to-consumer mapping.
Multiple rotated keys can share one stable namespace; no namespace derived from
raw API secrets, key row ids, or caller input.

Every v2 operation uses a fresh owned UTC PDO, never the shared auth/legacy PDO.
An uncertain commit is followed only by fresh-session receipt lookup/same-key
replay. Receipts outlive queue retention. Keep fingerprint context and necessary
historical key material stable; an identity must not be recycled after pruning.

Projects use PHP8.4+ with CI8.4/8.5; library remains compatible with PHP8.2+.
Track the project's Composer lock for reproducible package/schema selection.

## Consequences

Operators must apply SQL explicitly and provision stable bindings before v2.
Missing bindings deny v2 without affecting legacy v1. There is no automatic
migration/backfill or SMTP exactly-once promise. The caller must durably preserve
its intent/key before a network attempt. Package-level synthetic acceptance and
service issue#5 are outside this decision. Athar adoption preserves its templates
and cache configuration; Platform#684 remains open until host integration lands.
