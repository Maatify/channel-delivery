# How to use Channel Delivery

Start with the maintained [usage guide](docs/guides/USAGE_GUIDE.md).

- [First setup](docs/guides/USAGE_GUIDE.md#first-service-setup): environment, schema, key and worker.
- [Consumer examples](examples/README.md): legacy real, keyed real, receipt recovery and isolated no-send calls.
- [HTTP/template reference](docs/CHANNEL_DELIVERY_API.md).
- [2.0.0 upgrade](docs/upgrade/2.0.0.md) and [configuration/CLI](docs/reference/configuration.md).

The v1 endpoint remains per-call enqueue. Atomic recovery requires v2 and its
explicit schema/binding adoption. Use synthetic recording only when no email
should be queued; it does not promise identical timing or account-flow protection.
