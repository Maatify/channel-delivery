# Channel Delivery documentation

This is a standalone Composer **project/service**, not an in-process email library.
Email is implemented; Telegram, SMS and Push remain planned.

## Consumer contracts

- [Usage guide](guides/USAGE_GUIDE.md): setup, first message, atomic recovery and no-send recording.
- [HTTP reference and template catalogue](CHANNEL_DELIVERY_API.md).
- [Legacy v1](api/email-enqueue.md), [atomic v2 / receipt lookup](api/email-atomic.md), [synthetic recording](api/email-synthetic.md).
- [Runnable consumer examples](../examples/README.md).
- [Environment and operator CLI](reference/configuration.md).

## Operators

- [Worker](how-to/run-worker.md).
- [Atomic schema/binding rollout and recovery](how-to/atomic-email-rollout.md).
- [Synthetic schema, retention and bounded maintenance](how-to/synthetic-email-operations.md).
- [Upgrade to project 2.0.0](upgrade/2.0.0.md).

## Release and community

- [2.0.0 release target](releases/2.0.0.md), [qualification/publication checklist](releases/RELEASE_CHECKLIST.md), [changelog](../CHANGELOG.md).
- [Security policy](../SECURITY.md), [security mechanisms and limits](../SECURITY_AUDIT.md).
- [Contributing](../CONTRIBUTING.md), [code of conduct](../CODE_OF_CONDUCT.md), [MIT license](../LICENSE).
- [Service ADRs](adr/001-atomic-email-http.md) document design; [synthetic ADR](adr/002-synthetic-email-http.md) adds the no-send path.

The endpoint-specific contract owns its request/response rules. The template catalogue
owns template names/fields, the configuration reference follows actual DI reads,
and release documents distinguish preparation from publication. A merge or a receipt
is not a deployment or proof of mailbox delivery. [llms.txt](../llms.txt) is navigation only.
