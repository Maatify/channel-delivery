# Security Policy

[![Maatify Channel Delivery](https://img.shields.io/badge/Maatify-channel--delivery-blue?style=for-the-badge)](https://github.com/Maatify/channel-delivery)
[![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-9C27B0?style=for-the-badge)](https://github.com/Maatify)

## Supported versions

Support snapshot during2.0.0 preparation on2026-11-07:

| Published release line | Security fixes |
| --- | --- |
|1.x|Supported|

The owner approved continued1.x security support. **2.0.0 was not published at this
preparation snapshot**;2.x support begins on actual2.0.0 publication, without
automatically withdrawing1.x support. Preparation, merge and a future tag do not
by themselves establish publication or support. Check [release history](https://github.com/Maatify/channel-delivery/releases)
for live availability. An immutable artifact retains this dated snapshot; update
the live policy table after publication as part of release reconciliation.

Security support does not change PHP compatibility: this2.0.0 artifact requires
PHP8.4+, while previously published artifacts retain their own manifests. See
[upgrade notes](docs/upgrade/2.0.0.md).

## Reporting a vulnerability

Privately email Mohamed Abdulalim at [mohamed@maatify.dev](mailto:mohamed@maatify.dev).
Include affected version/commit, a minimal reproduction and impact. Do not post
credentials, personal data, exploit details or private logs in a public issue.
A support declaration is not a response-time SLA or a claim that all vulnerabilities
are known. Coordinate disclosure through that private channel.

## Scope and guarantees

The service owns HTTP ingestion, queue acceptance and worker integration. A202 is
not proof of rendering, SMTP delivery or mailbox receipt; keyed enqueue is not
exactly-once SMTP. Synthetic recording is inert storage, not a constant-time or
complete account-enumeration defense. [Mechanisms/limits](SECURITY_AUDIT.md) and the
[endpoint contracts](docs/CHANNEL_DELIVERY_API.md) describe the implemented boundary.
Operators and consumers own secrets, migrations, proxy trust, key retention,
cleanup, deployment and public-response normalization. Do not test production or
send live mail without separate authorization.
