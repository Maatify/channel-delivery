#!/usr/bin/env bash
set -euo pipefail
: "${CD_BASE_URL:?Set an explicit test service URL}"
: "${CD_API_KEY:?Set a private test API key}"
: "${CD_REQUEST_KEY:?Persist the original intent key before calling v2}"
curl --silent --show-error --fail-with-body --max-time 10 \
  --request GET "${CD_BASE_URL%/}/api/v2/email/receipt" \
  --header "X-Api-Key: ${CD_API_KEY}" \
  --header "Idempotency-Key: ${CD_REQUEST_KEY}"
