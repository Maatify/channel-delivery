# Changelog

All notable changes to this project will be documented in this file.

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
