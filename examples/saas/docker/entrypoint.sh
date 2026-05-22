#!/usr/bin/env bash
set -euo pipefail

# Per RESEARCH §"Pitfall 5": run app:seed-demo BEFORE exec'ing FrankenPHP so /health
# only goes green after seed completes (no race between healthcheck and seed).
# Compose's depends_on: service_healthy already waits for MariaDB before this script runs.

cd /srv/demo

echo "==> Seeding demo (idempotent)"
bin/console app:seed-demo --no-interaction

echo "==> Starting FrankenPHP"
exec frankenphp run --config /srv/demo/Caddyfile
