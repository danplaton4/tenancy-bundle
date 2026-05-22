#!/usr/bin/env bash
set -euo pipefail

# Per RESEARCH §"Pitfall 5": run app:seed-demo BEFORE exec'ing FrankenPHP so /health
# only goes green after seed completes (no race between healthcheck and seed).
# Compose's depends_on: service_healthy already waits for MariaDB before this script runs.

cd /srv/bundle/examples/saas

# FrankenPHP sets SERVER_NAME=":80,:443" for the HTTP runtime. Symfony Runtime
# auto-detects this and routes bin/console through HttpKernelRunner, which then
# rejects ":80,:443" as an invalid Host header. Force the CLI runtime by
# unsetting SERVER_NAME for the seed step (we restore it implicitly by leaving
# the entrypoint env unchanged for the `exec frankenphp` below).
echo "==> Seeding demo (idempotent)"
env -u SERVER_NAME bin/console app:seed-demo --no-interaction

echo "==> Starting FrankenPHP"
exec frankenphp run --config /srv/bundle/examples/saas/Caddyfile
