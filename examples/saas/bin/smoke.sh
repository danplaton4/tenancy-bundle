#!/usr/bin/env bash
# bin/smoke.sh — DNS-independent demo smoke test.
# Runs on host (CI runner or dev machine). Caddy must publish $BASE_PORT (default 80) on localhost.
# Override the host port when something else owns :80 (e.g. another dev stack):
#   BASE_PORT=8080 bash bin/smoke.sh
set -euo pipefail

CURL='curl --fail --max-time 10 --retry 5 --retry-all-errors --retry-connrefused -sS'
BASE_PORT="${BASE_PORT:-80}"
BASE="http://localhost:${BASE_PORT}"

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

# Resolver priority - HostResolver (30) wins over OriginHeaderResolver (25)
echo "==> Resolver priority (HostResolver beats OriginHeaderResolver)"
body=$($CURL \
    -H "Host: acme.tenancy.localhost" \
    -H "Origin: https://globex.tenancy.localhost" \
    "$BASE/")
grep -q 'Acme Corporation' <<<"$body" || {
    echo "FAIL: HostResolver should win — acme host should serve acme content (got globex Origin)"; exit 1;
}

# Per-tenant mailer isolation — dispatch one test mail per tenant, then query
# Mailpit's REST API to assert that each tenant's distinct From: address landed.
# A regression in Phase 20 TenantMessageDecorator (per-tenant From/Reply-To
# injection) would silently strip tenant identity from outbound mail; this
# assertion catches that.
# Initech intentionally skipped — two tenants prove isolation; the per-tenant
# landing-page block above already exercises initech end-to-end.
echo "==> Per-tenant mailer isolation (Mailpit assertion)"
MAILPIT_PORT="${PORT_MAILPIT_UI:-8025}"
for slug in acme globex; do
    $CURL -X POST -H "Host: ${slug}.tenancy.localhost" "$BASE/_demo/send-test-mail" >/dev/null
done
# Give Mailpit a brief moment to ingest both messages (sync transport in the
# demo, but a 1s buffer is cheap insurance against worker-drain timing).
sleep 1
MESSAGES=$($CURL "http://127.0.0.1:${MAILPIT_PORT}/api/v1/messages")
echo "$MESSAGES" | jq -e '.messages[] | select(.From.Address == "noreply@acme.example")' >/dev/null \
    || { echo "FAIL: mailer isolation — acme From: address not found in Mailpit (TenantMessageDecorator regression?)"; exit 1; }
echo "$MESSAGES" | jq -e '.messages[] | select(.From.Address == "noreply@globex.example")' >/dev/null \
    || { echo "FAIL: mailer isolation — globex From: address not found in Mailpit (TenantMessageDecorator regression?)"; exit 1; }
echo "    PASS: acme + globex From: addresses isolated correctly"

echo "==> All smoke assertions PASSED"
