---
phase: 21-demo-app
plan: "03"
subsystem: infra
tags: [docker, frankenphp, caddy, mariadb, mailpit, compose, dockerfile, entrypoint]

requires:
  - plan: 21-01
    provides: composer.json path-repo ../../ layout + .env env vars
  - plan: 21-02
    provides: app:seed-demo command + /health controller that entrypoint + healthcheck call

provides:
  - "compose.yaml: three-service stack (db/mailpit/php) with healthchecks + depends_on"
  - "Dockerfile: FrankenPHP build with build-time composer install + /srv layout"
  - "Caddyfile: wildcard vhost *.tenancy.localhost + auto_https disable_redirects + tls internal"
  - "docker/entrypoint.sh: seed-before-serve (Pitfall 5 mitigation)"
  - "docker/php.ini: OPcache dev tuning (validate_timestamps=1, revalidate_freq=0)"

affects: [21-04-ci-smoke-script]

tech-stack:
  added:
    - "dunglas/frankenphp:1-php8.2-bookworm (pinned Docker image)"
    - "mariadb:11 (pinned Docker image)"
    - "axllent/mailpit:v1.20 (pinned Docker image)"
    - "composer:2 (multi-stage copy for Composer binary)"
  patterns:
    - "Repo-root build context (context: ../../) so Dockerfile can COPY both bundle src and demo source"
    - "BuildKit optional-lock COPY pattern (COPY composer.lock* — trailing * makes lock optional)"
    - "Seed-before-serve entrypoint: seed runs to completion BEFORE exec frankenphp so healthcheck only greens after data is ready"
    - "Bundle-source dev loop: ../../src:/srv/src bind-mount + OPcache revalidate_freq=0"
    - "MariaDB healthcheck.sh (not bare mysqladmin ping) — waits for innodb_initialized"

key-files:
  created:
    - examples/saas/Caddyfile
    - examples/saas/Dockerfile
    - examples/saas/compose.yaml
    - examples/saas/docker/entrypoint.sh
    - examples/saas/docker/php.ini

decisions:
  - "Bundle layout /srv/ + demo layout /srv/demo/ inside image — composer.json url:../../ from /srv/demo/ resolves to /srv/ (bundle root)"
  - "Build context set to ../../ (repo root) so Dockerfile COPY paths can reference both examples/saas/ and src/ in a single build"
  - "entrypoint.sh path: /srv/demo/docker/entrypoint.sh — Caddyfile path passed explicitly as --config /srv/demo/Caddyfile"
  - "mailpit healthcheck uses wget (not curl) because the mailpit:v1.20 image includes wget but not curl"
  - "MariaDB healthcheck retries=20 (higher than default) to handle slow CI MariaDB init"
  - "caddy-data and caddy-config named volumes persist Caddy CA root cert across docker compose up/down cycles"
  - "Mailpit SMTP :1025 intentionally NOT in compose.yaml ports (internal-only; T-21-MP mitigate)"

metrics:
  duration: 3min
  completed: "2026-05-22"
  tasks_completed: 3
  tasks_total: 3
  files_created: 5
  files_modified: 0
---

# Phase 21 Plan 03: Container Stack Summary

FrankenPHP + Caddy + MariaDB 11 + Mailpit compose stack with seed-before-serve entrypoint, wildcard Caddyfile, and OPcache dev-loop tuning — wires Plans 01+02 outputs into a bootable `docker compose up -d --wait --build` stack.

## Performance

- **Duration:** 3 min
- **Started:** 2026-05-22T11:37:39Z
- **Completed:** 2026-05-22T11:41:03Z
- **Tasks:** 3/3
- **Files created:** 5

## What Was Built

### Three-Service Compose Topology

`examples/saas/compose.yaml` defines exactly three services:

| Service | Image | Healthcheck | Published Ports |
|---------|-------|-------------|-----------------|
| `db` | `mariadb:11` | `healthcheck.sh --connect --innodb_initialized` | None (internal only — T-21-DB) |
| `mailpit` | `axllent/mailpit:v1.20` | `wget /api/v1/info` | `127.0.0.1:8025:8025` (loopback only — T-21-MP) |
| `php` | built from `Dockerfile` | `curl -fsS http://localhost/health` | `${PORT_HTTP:-80}:80`, `${PORT_HTTPS:-443}:443` |

`php` depends_on `db` and `mailpit` with `condition: service_healthy` — `docker compose up -d --wait` blocks until all three pass their healthchecks.

### Dockerfile Build Strategy

**Repo-root build context.** `compose.yaml` sets `build.context: ../../` (the repo root) and `dockerfile: examples/saas/Dockerfile`. This lets the Dockerfile COPY both the bundle source and the demo in a single build — no multi-repo tricks needed.

**Layout inside the image:**
- `/srv/` — bundle source root (src/, config/, composer.json, composer.lock*)
- `/srv/demo/` — demo app root (all of `examples/saas/`)

This makes `../../` in `examples/saas/composer.json` (the path-repo URL) resolve to `/srv/` from `/srv/demo/` — exactly where the bundle is COPYd.

**Build-time composer install.** `RUN composer install` runs during image build so the image is self-contained for CI (D-16). The BuildKit `# syntax=docker/dockerfile:1.7` header enables the optional-lock pattern (`COPY composer.lock* /srv/`) — if the bundle or demo doesn't commit a lockfile the COPY succeeds and `composer install` resolves from `composer.json` only.

**PHP extensions installed:** `pdo_mysql`, `intl`, `opcache`, `zip` via `install-php-extensions` (the FrankenPHP base image includes this helper).

### Caddyfile Shape

```caddy
{
    frankenphp
    auto_https disable_redirects
}

*.tenancy.localhost, tenancy.localhost, localhost {
    tls internal
    root * public/
    encode zstd br gzip
    php_server
}
```

Key choices:
- `auto_https disable_redirects` — HTTP on :80 does NOT 301 to HTTPS; README's HTTP-first walkthrough works without cert friction (RESEARCH Pitfall 1; CONTEXT D-11).
- `tls internal` — Caddy's internal CA issues a wildcard cert for the entire matcher list. No external ACME, no DNS challenge, no Let's Encrypt rate limits.
- `localhost` in the matcher — the in-container healthcheck (`curl http://localhost/health`) matches this vhost.
- `php_server` — FrankenPHP Symfony idiom (https://frankenphp.dev/docs/symfony/).

### Entrypoint Ordering (Pitfall 5 Mitigation)

```bash
#!/usr/bin/env bash
set -euo pipefail
cd /srv/demo
echo "==> Seeding demo (idempotent)"
bin/console app:seed-demo --no-interaction
echo "==> Starting FrankenPHP"
exec frankenphp run --config /srv/demo/Caddyfile
```

`app:seed-demo` completes **before** `exec frankenphp run`. Because the PHP server doesn't start until seed is done, the `/health` healthcheck can only return 200 after tenant DBs exist and posts are seeded. This makes `docker compose up -d --wait` a true "ready to serve traffic" gate.

### OPcache Dev-Loop Configuration

`docker/php.ini` sets:
- `opcache.validate_timestamps=1` + `opcache.revalidate_freq=0` — OPcache checks file mtime on every request. Required for the bundle-source dev loop: edit `src/Resolver/HostResolver.php` on the host, the bind-mount reflects the change in the container instantly, OPcache picks it up on the next request without a restart.

### Volume Strategy

| Volume | Purpose |
|--------|---------|
| `db-data` | Persists MariaDB data across `up`/`down` (without `-v`) |
| `mailpit-data` | Persists Mailpit database across restart |
| `caddy-data` | Persists Caddy CA root cert — `caddy trust` only needed once |
| `caddy-config` | Caddy config state |
| `../../src:/srv/src` (bind) | Bundle-source dev loop — host edits visible in container |
| `./src:/srv/demo/src` (bind) | Demo source dev loop — template/controller edits without rebuild |

### Image Pins and Supply-Chain Rationale

| Image | Pin | Rationale |
|-------|-----|-----------|
| `dunglas/frankenphp:1-php8.2-bookworm` | major+minor-pinned | FrankenPHP 1.x API-stable; `bookworm` = Debian 12 base |
| `mariadb:11` | major-pinned | MariaDB 11 LTS; `healthcheck.sh` available in all `11.*` variants |
| `axllent/mailpit:v1.20` | minor-pinned | Pinned per RESEARCH §A3 — `latest` is not reproducible |
| `composer:2` | major-pinned | Composer 2.x stable; used in multi-stage COPY only |

## Task Commits

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Caddyfile + docker/php.ini | `5f06c36` | examples/saas/Caddyfile, examples/saas/docker/php.ini |
| 2 | Dockerfile + docker/entrypoint.sh | `3f2b7a3` | examples/saas/Dockerfile, examples/saas/docker/entrypoint.sh |
| 3 | compose.yaml | `eab4afa` | examples/saas/compose.yaml |

## Deviations from Plan

None — plan executed exactly as written.

All five RESEARCH pitfalls addressed as specified:
- Pitfall 1: `auto_https disable_redirects` in Caddyfile
- Pitfall 2: `healthcheck.sh --connect --innodb_initialized` on MariaDB
- Pitfall 3: bind-mount `../../src:/srv/src` (not host-symlink) + build-context COPY strategy
- Pitfall 4: (README concern only — no entrypoint change needed; documented in Plan 04)
- Pitfall 5: seed runs BEFORE `exec frankenphp run` in entrypoint.sh

## Threat Model Compliance

| Threat | Disposition | Implementation |
|--------|-------------|----------------|
| T-21-MP: Mailpit UI exposes test emails | mitigate | `127.0.0.1:8025:8025` in compose.yaml (NOT `0.0.0.0`); SMTP :1025 not in `ports:` |
| T-21-DB: MariaDB credentials | accept | Port 3306 NOT published; intra-compose only; demo defaults committed |
| T-21-CA: Caddy CA root cert leakage | mitigate | CA generated in `caddy-data` volume; never committed; README documents optional `caddy trust` step |
| T-21-PI: OPcache validate_timestamps=1 | accept | Dev-only; documented as NOT production-shaped in php.ini comment |
| T-21-SC: Container image supply chain | mitigate | All three images pinned by version tag (not `latest`) |

## Known Stubs

None — these are infra/config files, no UI rendering or data stubs.

## Threat Flags

No new threat surface beyond the plan's registered threat model.

## Self-Check

### Created files exist
- `examples/saas/Caddyfile` — FOUND
- `examples/saas/Dockerfile` — FOUND
- `examples/saas/compose.yaml` — FOUND
- `examples/saas/docker/entrypoint.sh` — FOUND
- `examples/saas/docker/php.ini` — FOUND

### Commits exist
- `5f06c36` — feat(21-03): Caddyfile + docker/php.ini — FOUND
- `3f2b7a3` — feat(21-03): Dockerfile + docker/entrypoint.sh — FOUND
- `eab4afa` — feat(21-03): compose.yaml — FOUND

### docker compose config
`docker compose config --quiet` exits 0 — PASSED

## Self-Check: PASSED

---
*Phase: 21-demo-app*
*Completed: 2026-05-22*
