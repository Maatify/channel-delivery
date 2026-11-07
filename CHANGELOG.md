# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

## [2.0.0] - 2026-11-07

Published as [v2.0.0](https://github.com/Maatify/channel-delivery/releases/tag/v2.0.0).

### Fixed
- Reject ambiguous numeric-keyed and empty nested JSON objects before associative conversion, with raw HTTP regression tests; preserve named objects and lists.

### Added
- Optional authenticated POST /api/v2/email/synthetic via the package's isolated no-send store (#5).
- Explicit retention configuration and trusted bounded purge CLI, with no automatic cron or production SQL.
- Shared v2 body validation, worker-isolation, concurrency, failure-recovery and cleanup integration tests; optional loopback timing smoke measurement.
- v2 atomic email acceptance and authenticated receipt lookup via email-delivery1.2.0.
- Stable operator-owned consumer bindings and explicit canonical vendor migrations.
- Real HTTP/MySQL/Redis integration, uncertain-commit recovery and multiprocess lock-contention tests.

### Changed
- Project runtime PHP8.4+, an explicit PDO MySQL extension requirement, CI8.4/8.5 and committed Composer lock; library compatibility remains unchanged.
- APP_DEBUG textual false disables response exception details (channel-delivery#7).
- Complete the 2.0.0 upgrade, configuration, consumer examples, release artifact checks and Maatify presentation.
- Correct fixed-window rate-limit terminology, SMTP debug/proxy examples and welcome context.
- Remove deprecated no-op curl_close() from the diagnostic script for PHP 8.5.
- Legacy v1 behavior and mail policy retained alongside the new optional synthetic endpoint.

## [1.1.0] - 2026-05-17

### Added
- `admin_notification` email template (en/ar) for admin alerts from contact forms and customer inquiries.
- Optional `reply_to` field in the enqueue API — sets Reply-To header so admin replies go to the customer.
- Reply-To support in the delivery pipeline via `maatify/email-delivery` v1.1.0.
- 4 new tests for `reply_to` validation in `EnqueueEmailHandlerTest`.

### Changed
- `EnqueueEmailHandler` — parses and validates the optional `reply_to` field from request body.
- Updated README with admin notification example and Reply-To feature.
- Updated `docs/CHANNEL_DELIVERY_API.md` and `docs/api/email-enqueue.md` with `reply_to` field specification and `admin_notification` template contract.

## [1.0.1] - 2026-03-12

### Changed
- Updated dependency configuration to match the new `TwigEmailRenderer`
  constructor parameter (`templatesPath` instead of `templateDir`).

### Internal
- Improved naming consistency in the email template renderer wiring.
- No functional changes to the delivery pipeline.

## [1.0.0] - 2026-03-12

### Added
- Initial release of maatify/channel-delivery microservice.
- Async email pipeline with AES-256-GCM encryption.
- Redis sliding window rate limiter.
- API key generation and IP whitelisting.

[Unreleased]: https://github.com/Maatify/channel-delivery/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/Maatify/channel-delivery/releases/tag/v2.0.0
[1.1.0]: https://github.com/Maatify/channel-delivery/releases/tag/v1.1.0
[1.0.1]: https://github.com/Maatify/channel-delivery/releases/tag/v1.0.1
[1.0.0]: https://github.com/Maatify/channel-delivery/tree/v1.0.0
