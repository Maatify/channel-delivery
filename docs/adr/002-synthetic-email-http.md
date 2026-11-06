# Service ADR002: explicit synthetic no-send HTTP recording

Status: accepted by owner on2026-11-07 under channel-delivery#5.
The service implementation is adopted in the upstream project first, then Athar.
Deployment and business retention duration remain separate operator decisions.

Use POST /api/v2/email/synthetic with the existing authenticated key/IP/rate/binding
chain and shared v2 bounded raw-body validator, including F8-01 guards. Reuse the
public email-delivery1.2.0 recorder/maintenance ports and canonical optional schema004.
No real writer, fingerprint, receipt identity or worker is added to this path.
The HTTP result is an explicit inert record; no Idempotency-Key is accepted.
Do not expose a suppression mode on the real endpoint or copy data into its queue.

Retention is mandatory deployment configuration with no default product duration;
maintenance is a trusted bounded CLI, global across namespaces, externally scheduled.
Every operation owns a fresh UTC PDO. Unknown commits return no false success;
retries may append duplicate inert rows. No default production rollout or cleanup
schedule is authorized here. Preserve existing Redis fail-open quota behavior.

The consumer owns public response normalization and account eligibility. Timing
verification is a limited descriptive loopback HTTP smoke test plus known-delay
sensitivity control, not a promise of matching distributions. No synthetic sleep
or account/OTP business logic enters production service code. The library remains
responsible for general storage/encryption/isolation; the service owns HTTP and ops.
