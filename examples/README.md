# Trusted-client examples

Run these examples only against an explicit test service with the appropriate
schema/binding/retention configured. The shared welcome payload uses nonsensitive
`.invalid` addresses and includes both fields required by the template.
They can enqueue real jobs in v1/v2; synthetic alone is no-send.

Requirements: Bash and curl7.76+ (`--fail-with-body`), plus a service prepared using
the [usage guide](../docs/guides/USAGE_GUIDE.md). Never commit/export examples with
real keys embedded in files or publicly paste their values.

```bash
export CD_BASE_URL='http://127.0.0.1:8080'
# Set CD_API_KEY privately through your secret/input mechanism.
bash examples/enqueue-v1.sh
```

For v2, durably store the intent/body/key before the first call, then supply that
same stored key as CD_REQUEST_KEY. The shell scripts do not implement durable
intent storage, retries or account-flow logic for you.

```bash
# CD_API_KEY and CD_REQUEST_KEY must already be set privately by the caller.
bash examples/enqueue-v2.sh
bash examples/receipt-v2.sh
bash examples/enqueue-v2.sh  # Same immutable key/body: replay, not another job.
```

Do not edit the payload between replays or generate a new key after timeout/503.
Query/retry the original identity. A nonzero curl exit can represent uncertain
acceptance; it does not authorize a v1 fallback.409 needs reconciliation.

```bash
bash examples/synthetic.sh
```

Synthetic does not send Idempotency-Key, even if CD_REQUEST_KEY exists in the
process environment. It returns `recorded`, not an email receipt; repeated calls
append inert rows. Keep caller public responses uniform independently of these
internal response formats; the examples make no timing-equivalence claim.

The [PHP legacy example](../docs/examples/basic-usage.php) uses the same payload
and CD_BASE_URL/CD_API_KEY, and can be run with PHP/cURL without a PHP SDK:

```bash
php docs/examples/basic-usage.php
```

See the [HTTP reference](../docs/CHANNEL_DELIVERY_API.md) for template fields and
[atomic](../docs/api/email-atomic.md) / [synthetic](../docs/api/email-synthetic.md)
contracts. Source-clone [verification](../CONTRIBUTING.md) never targets production.
