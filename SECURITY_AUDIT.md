# Security mechanisms and verification boundaries

This file describes current mechanisms in the2.0.0 source. It is **not an independent
security audit or certification**. Report vulnerabilities through [SECURITY.md](SECURITY.md).

- Recipient and payload data (including context/Reply-To) are encrypted by
  maatify/crypto through email-delivery. Entity/template/namespace/date metadata is
  plaintext; encryption at rest does not anonymize the record.
- Ingestion requires an active hashed API key and exact caller-IP whitelist.
  Proxy trust is opt-in by exact IP, not CIDR. v2 consumer namespace is server-owned.
- Rate limiting is the existing Redis fixed-window request counter. Redis failure
  is fail-open, so it is not a strict quota or general DoS guarantee.
- v2 validates bounded raw JSON before conversion, including ambiguous object/list
  shapes. Atomic acceptance requires fresh UTC sessions, canonical transactional
  schemas, immutable keys/content and retention of receipt identity/read keys.
- Synthetic recording uses isolated optional storage with separate crypto domains,
  no worker schedule and no transfer to real queue. Retention is explicit; cleanup
  is trusted/global/bounded and must be operated. Expiry alone does not delete rows.
- APP_DEBUG=false hides response exception details. SMTP diagnostic output is
  controlled by MAIL_DEBUG_LEVEL; logs still need operator access/redaction/retention.
- Local integration and CI evidence is described in [contributing](CONTRIBUTING.md)
  and the [release checklist](docs/releases/RELEASE_CHECKLIST.md). Composer audit checks
  known dependency advisories at execution time, not all possible vulnerabilities.

Queue acceptance is not mailbox delivery or exactly-once SMTP. Synthetic timing
smoke measurements do not prove equal distributions or prevent enumeration in a
consumer. Consumer eligibility, public responses, secrets, proxy configuration,
deployment, migrations and ongoing cleanup need their own validation.
