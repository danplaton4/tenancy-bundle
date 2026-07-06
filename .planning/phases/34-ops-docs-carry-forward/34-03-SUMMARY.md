---
phase: 34-ops-docs-carry-forward
plan: "03"
subsystem: infra
tags: [composer, php-platform-pin, docker, demo, smoke-test]

# Dependency graph
requires:
  - phase: 34-ops-docs-carry-forward
    provides: Phase 34 context — ops docs + carry-forward scope including DEMO-02 drift item
provides:
  - examples/saas composer.json pinned config.platform.php = 8.2.99
  - examples/saas composer.lock regenerated with zero packages requiring php >=8.4
  - bin/smoke.sh confirmed green on PHP 8.2 via local Docker run
affects: [demo-smoke CI, examples/saas]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "config.platform.php in composer.json pins Composer resolution to a target PHP version regardless of host PHP version"

key-files:
  created: []
  modified:
    - examples/saas/composer.json
    - examples/saas/composer.lock

key-decisions:
  - "config.platform.php = 8.2.99 placed inside the config block (not top-level) at alphabetical position between optimize-autoloader and sort-packages, per sort-packages: true convention"
  - "Dockerfile base image (dunglas/frankenphp:1-php8.2-bookworm) left unchanged — the fix is the lock, not the image"
  - "Task 2 is a pure human-verify checkpoint — no code written; smoke evidence recorded in SUMMARY"

patterns-established:
  - "Platform pin pattern: demo sub-projects should carry config.platform.php matching their Dockerfile PHP version to prevent host-PHP drift poisoning the lock"

requirements-completed: [DEMO-02]

# Metrics
duration: 15min (Task 1 ~10min; Task 2 human-verify smoke run)
completed: 2026-07-06
---

# Phase 34 Plan 03: DEMO-02 PHP-Platform-Pin Summary

**Resolved Dockerfile-vs-lock PHP drift in examples/saas by pinning config.platform.php = 8.2.99, regenerating the lock with zero >=8.4 requirements, and verifying bin/smoke.sh exits 0 on PHP 8.2 in Docker**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-07-06 (continuation agent)
- **Completed:** 2026-07-06
- **Tasks:** 2 (1 auto + 1 human-verify checkpoint)
- **Files modified:** 2 (composer.json, composer.lock)

## Accomplishments

- Added `"platform": {"php": "8.2.99"}` inside `examples/saas/composer.json`'s `config` block (alphabetical key order: allow-plugins → optimize-autoloader → platform → sort-packages)
- Regenerated `examples/saas/composer.lock` under the PHP 8.2 platform pin — all 15 previously-offending packages down-resolved to their last-8.2-compatible versions; zero packages now declare `require.php >= 8.4`
- Confirmed lock consistency: `composer validate --no-check-publish` passed; `composer install --dry-run` passed
- Dockerfile base image `dunglas/frankenphp:1-php8.2-bookworm` left unchanged (fix was the lock, not the image)
- Smoke test verified by orchestrator via local Docker run: built + started stack on FrankenPHP 8.2, confirmed container PHP 8.2.32 (ZTS), `bin/smoke.sh` exited 0 covering readiness, landlord index (acme/globex/initech), per-tenant landing pages, resolver priority (HostResolver > OriginHeaderResolver), and per-tenant mailer isolation
- DEMO-02 closed

## Task Commits

Each task was committed atomically:

1. **Task 1: Pin examples/saas to PHP 8.2 and regenerate its lock** — `faac024` (fix)
2. **Task 2: CI/human verification that bin/smoke.sh is green on PHP 8.2** — human-verify checkpoint; no code commit (pure verification, approved by orchestrator after local Docker smoke run exiting 0)

**Plan metadata:** (this commit — docs)

## Files Created/Modified

- `examples/saas/composer.json` — added `"platform": {"php": "8.2.99"}` inside the `config` block
- `examples/saas/composer.lock` — regenerated under the PHP 8.2 platform pin; all PHP-version-sensitive packages down-resolved to 8.2-compatible versions

## Decisions Made

- `config.platform.php` goes INSIDE the `config` block (not top-level) — this is the Composer-specified location for platform overrides
- Pin value `8.2.99` simulates any 8.2.* patch for resolution; the container still runs the real FrankenPHP 8.2 image and receives OS/PHP patches
- Dockerfile unchanged — the drift was entirely in the lock (generated on a PHP 8.4 host without a pin); no image bump needed
- Task 2 is a pure checkpoint — zero code written; smoke evidence embedded here

## Smoke Test Evidence (Task 2 Verification)

The orchestrator ran a full local Docker smoke test after Task 1's commit:

- Stack built on `dunglas/frankenphp:1-php8.2-bookworm` with the regenerated lock — all containers healthy
- Container PHP version: **PHP 8.2.32 (cli) (ZTS)**
- `bin/smoke.sh` assertions that passed:
  - Readiness endpoint
  - Landlord index — tenants acme, globex, initech present
  - Per-tenant landing pages (acme, globex, initech)
  - Resolver priority: HostResolver beats OriginHeaderResolver
  - Per-tenant mailer isolation
- Exit code: **0**
- Stack torn down with `docker compose down -v`

## Deviations from Plan

None - plan executed exactly as written. Task 1 was a direct edit + lock regeneration; Task 2 was the prescribed human-verify checkpoint with no code changes.

## Issues Encountered

None. The platform pin resolved all 15 offending packages cleanly to 8.2-compatible versions with no production-dependency genuine-8.4 requirement found (as the research had predicted in 34-RESEARCH.md §DEMO-02 Investigation).

## Threat Surface Scan

No new network endpoints, auth paths, file access patterns, or schema changes introduced. The only change is a `composer.json` config key and a regenerated lock file scoped to the `examples/saas` demo sub-project. The regenerated lock is covered by the existing milestone-level `composer audit` CI gate (per threat register T-34-06 mitigation).

## Known Stubs

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- DEMO-02 is closed; the examples/saas demo now builds and smoke-tests on a single coherent PHP 8.2 target
- Remaining Phase 34 plans: 34-04 (GOV-02 advisory Nyquist policy + P31 VALIDATION backfill) and 34-05 (QA-01 two regression tests) — both already executed per STATE.md
- All 5 Phase 34 plans complete; phase is ready for orchestrator verification

---
*Phase: 34-ops-docs-carry-forward*
*Completed: 2026-07-06*
