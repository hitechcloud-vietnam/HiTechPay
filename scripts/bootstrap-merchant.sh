#!/usr/bin/env bash
# Create a merchant account and a secret API key through the admin API, and print the
# values a merchant integration needs (merchant_id, publishable key, profile id, API key).
#
#   ADMIN_API_KEY=... API=https://api.hitechpay.vn \
#     MERCHANT_ID=shop_demo MERCHANT_NAME="Shop Demo" RETURN_URL=https://shop.example.vn/return \
#     scripts/bootstrap-merchant.sh
#
# Then add payment connectors on the dashboard (or the API, docs/trien-khai/04-cong-thanh-toan.md).
set -euo pipefail
API="${API:-http://127.0.0.1:8080}"
: "${ADMIN_API_KEY:?set ADMIN_API_KEY (HTP_ADMIN_API_KEY from deploy/.env)}"
: "${MERCHANT_ID:?set MERCHANT_ID (lowercase letters, digits, _)}"
MERCHANT_NAME="${MERCHANT_NAME:-$MERCHANT_ID}"
RETURN_URL="${RETURN_URL:-https://example.com/return}"
command -v jq >/dev/null || { echo "jq is required" >&2; exit 1; }

call() {
  local method="$1" path="$2" body="${3:-}"
  local out code
  out="$(mktemp)"
  code="$(curl -sS -o "$out" -w '%{http_code}' -X "$method" "$API$path" \
    -H "Content-Type: application/json" -H "api-key: $ADMIN_API_KEY" ${body:+-d "$body"})"
  if [[ "$code" -ge 300 ]]; then
    echo "$method $path → HTTP $code" >&2; cat "$out" >&2; echo >&2; rm -f "$out"; return 1
  fi
  cat "$out"; rm -f "$out"
}

echo "creating merchant $MERCHANT_ID …" >&2
merchant="$(call POST /accounts "$(jq -n --arg id "$MERCHANT_ID" --arg name "$MERCHANT_NAME" --arg ret "$RETURN_URL" \
  '{merchant_id:$id, merchant_name:$name, return_url:$ret,
    merchant_details:{primary_business_details:null}, metadata:{platform:"hitechpay"}}')")"

echo "creating API key …" >&2
key="$(call POST "/api_keys/$MERCHANT_ID" '{"name":"server-key","description":"created by bootstrap-merchant.sh","expiration":"never"}')"

jq -n --argjson m "$merchant" --argjson k "$key" '{
  merchant_id: $m.merchant_id,
  publishable_key: $m.publishable_key,
  default_profile: $m.default_profile,
  api_key: $k.api_key,
  api_key_id: $k.key_id
}'
echo "store api_key securely — it is shown only once" >&2
