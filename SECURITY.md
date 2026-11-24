# Security Policy

[![Maatify Channel Delivery](https://img.shields.io/badge/Maatify-channel--delivery-blue?style=for-the-badge)](https://github.com/Maatify/channel-delivery)
[![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-9C27B0?style=for-the-badge)](https://github.com/Maatify)

## Supported versions

The current support policy follows publication of 2.0.0 on 2026-11-07.

| Published release line | Security fixes |
| --- | --- |
|1.x|Supported|
|2.x|Supported|

The owner approved continued 1.x security support and 2.x support from 2.0.0
publication. See the
[published release](https://github.com/Maatify/channel-delivery/releases/tag/v2.0.0)
and [release history](https://github.com/Maatify/channel-delivery/releases).
The immutable 2.0.0 artifact retains its dated preparation snapshot. This
default-branch policy records the current support state.

This source targets 2.1.0 and requires PHP 8.4 or later within PHP 8. Published
artifacts follow their own manifests, including the published 2.0.0 baseline.
See the [2.1.0 target guide](docs/releases/2.1.0.md) and the
[2.0.0 upgrade notes](docs/upgrade/2.0.0.md).

## Reporting a vulnerability

Privately email Mohamed Abdulalim at [mohamed@maatify.dev](mailto:mohamed@maatify.dev).
Before reporting, update to the latest available stable release within your
supported major line (1.x or 2.x) and check whether the issue still occurs. This
does not require moving a 1.x installation to 2.x. Then include the affected
version/commit, a minimal reproduction and impact. Do not post
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
