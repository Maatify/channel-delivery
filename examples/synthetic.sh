#!/usr/bin/env bash
set -euo pipefail
: "${CD_BASE_URL:?Set an explicit test service URL}"
: "${CD_API_KEY:?Set a private test API key}"
example_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
curl --silent --show-error --fail-with-body --max-time 10 \
  --request POST "${CD_BASE_URL%/}/api/v2/email/synthetic" \
  --header "X-Api-Key: ${CD_API_KEY}" \
  --header "Content-Type: application/json" \
  --data-binary "@${example_dir}/payloads/welcome.json"
