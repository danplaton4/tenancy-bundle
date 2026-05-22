#!/usr/bin/env bash
# bin/smoke.sh — DNS-independent demo smoke test.
# Runs on host (CI runner or dev machine). Caddy must publish :80 on localhost.
set -euo pipefail

CURL='curl --fail --max-time 10 --retry 5 --retry-all-errors --retry-connrefused -sS'
BASE='http://localhost'

# Wait for /health to be ready (max 30s)
echo "==> Waiting for app readiness..."
for i in $(seq 1 30); do
    if curl -sf --max-time 2 "$BASE/health" >/dev/null 2>&1; then
        echo "    ready"
        break
    fi
    sleep 1
    [ "$i" -eq 30 ] && { echo "    timeout"; exit 1; }
done

# Landlord index — should list all three tenant slugs
echo "==> Landlord root"
body=$($CURL -H "Host: tenancy.localhost" "$BASE/")
for slug in acme globex initech; do
    grep -q "$slug" <<<"$body" || { echo "FAIL: landlord index missing $slug"; exit 1; }
done

# Per-tenant marker assertions
echo "==> Per-tenant landing pages"
declare -A markers=(
    [acme]='Acme Corporation'
    [globex]='Globex Industries'
    [initech]='Initech LLC'
)
for slug in "${!markers[@]}"; do
    body=$($CURL -H "Host: $slug.tenancy.localhost" "$BASE/")
    grep -q "${markers[$slug]}" <<<"$body" || {
        echo "FAIL: $slug page missing '${markers[$slug]}'"; exit 1;
    }
done

# Origin-resolver path (Phase 17 invariant)
echo "==> OriginHeaderResolver"
body=$($CURL \
    -H "Host: tenancy.localhost" \
    -H "Origin: https://acme.tenancy.localhost" \
    "$BASE/")
grep -q 'Acme Corporation' <<<"$body" || {
    echo "FAIL: Origin-resolver did not resolve acme"; exit 1;
}

echo "==> All smoke assertions PASSED"
