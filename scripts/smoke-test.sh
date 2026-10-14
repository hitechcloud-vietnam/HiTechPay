#!/usr/bin/env bash
# Post-deploy smoke test: router health (incl. DB/Redis), dashboard, checkout SDK.
#
#   scripts/smoke-test.sh                                   # local compose ports
#   API=https://api.hitechpay.vn DASHBOARD=https://dashboard.hitechpay.vn \
#     SDK=https://checkout.hitechpay.vn/HyperLoader.js scripts/smoke-test.sh
set -uo pipefail
API="${API:-http://127.0.0.1:8080}"
DASHBOARD="${DASHBOARD:-http://127.0.0.1:9000}"
SDK="${SDK:-http://127.0.0.1:9050/HyperLoader.js}"
fail=0
check() {
  local name="$1" url="$2" expect="${3:-200}"
  local code
  code="$(curl -sk -o /tmp/htp-smoke.out -w '%{http_code}' --max-time 20 "$url" || echo 000)"
  if [[ "$code" == "$expect" ]]; then
    printf '  \033[32mOK\033[0m   %-22s %s\n' "$name" "$url"
  else
    printf '  \033[31mFAIL\033[0m %-22s %s (HTTP %s)\n' "$name" "$url" "$code"
    head -c 300 /tmp/htp-smoke.out 2>/dev/null; echo
    fail=1
  fi
}
echo "HiTechPay smoke test"
check "router health"        "$API/health"
check "router deep health"   "$API/health/ready"
check "dashboard"            "$DASHBOARD/"
check "checkout SDK loader"  "$SDK"
if [[ -n "${ADMIN_API_KEY:-}" ]]; then
  code="$(curl -s -o /dev/null -w '%{http_code}' -H "api-key: $ADMIN_API_KEY" "$API/accounts/list?organization_id=none")"
  [[ "$code" != "401" ]] && echo "  OK   admin API key accepted" || { echo "  FAIL admin API key rejected"; fail=1; }
fi
exit $fail
