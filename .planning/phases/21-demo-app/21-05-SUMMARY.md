---
phase: 21-demo-app
plan: "05"
subsystem: demo
tags: [bash, smoke-test, resolver-priority, idempotency, cleanup-discipline]

requires:
  - phase: 21-demo-app/21-04
    provides: bin/smoke.sh with Origin-resolver assertion, SeedDemoCommand with boot/clear pattern, LandlordTenantsFixture with seed data

provides:
  - bin/smoke.sh with logically-achievable resolver-priority assertion (HostResolver@30 beats OriginHeaderResolver@25)
  - SeedDemoCommand with single-clear-per-tenant cleanup discipline (catch block clean, finally authoritative)
  - LandlordTenantsFixture with findOneBy slug-uniqueness guard (idempotent re-seeding)

affects: [21-VERIFICATION.md smoke gate, CI demo-smoke.yml, docker entrypoint seed idempotency]

tech-stack:
  added: []
  patterns:
    - "Resolver-priority assertion pattern: send Host: acme + Origin: globex, grep for acme content to prove HostResolver wins"
    - "Single-path cleanup discipline: clear() in finally only, never in catch before return"
    - "findOneBy early-continue guard in fixtures for idempotent persist"

key-files:
  created: []
  modified:
    - examples/saas/bin/smoke.sh
    - examples/saas/src/Command/SeedDemoCommand.php
    - examples/saas/src/DataFixtures/LandlordTenantsFixture.php

key-decisions:
  - "Replace Origin-resolver assertion with resolver-priority proof: HostResolver@30 wins over OriginHeaderResolver@25 when same request carries both Host:acme subdomain and Origin:globex subdomain"
  - "CR-01 (actions/checkout@v5) confirmed false positive — demo-smoke.yml is NOT modified"
  - "PHPStan standalone-file check for demo files is pre-existing non-issue: phpstan.neon only analyses src/ (bundle); demo app classes not in autoload scope. Bundle analysis (full suite) passes clean."

patterns-established:
  - "Resolver-priority smoke assertion: send Host: {tenant-a}.tenancy.localhost + Origin: https://{tenant-b}.tenancy.localhost, assert tenant-a content served"
  - "Cleanup discipline: clear() belongs in finally only; catch block handles error output and return only"
  - "Fixture idempotency: findOneBy slug guard before persist in any fixture run with append:true"

requirements-completed: [DEMO-01]

duration: 7min
completed: 2026-05-22
---

# Phase 21 Plan 05: Gap Closure (CR-02 + WR-01 + WR-02) Summary

**Three targeted fixes: smoke.sh resolver-priority assertion replaces logically-impossible Origin probe; SeedDemoCommand catch block stripped of duplicate clear() calls; LandlordTenantsFixture gains findOneBy slug guard for idempotent re-seeding.**

## Performance

- **Duration:** ~7 min
- **Started:** 2026-05-22T12:39:00Z
- **Completed:** 2026-05-22T12:46:16Z
- **Tasks:** 3
- **Files modified:** 3

## Accomplishments

- CR-02 BLOCKER closed: `bin/smoke.sh` Origin-resolver block (lines 41-49) replaced with a resolver-priority assertion that proves HostResolver (priority 30) beats OriginHeaderResolver (priority 25). The new assertion sends `Host: acme.tenancy.localhost` + `Origin: https://globex.tenancy.localhost` and greps for `Acme Corporation`. This is logically achievable (routes to TenantController, not LandlordController), so `--fail` + `set -euo pipefail` can no longer abort before the grep.
- WR-01 WARNING closed: Two `clear()` calls (`$this->tenantContext->clear()` and `$this->bootstrapperChain->clear()`) removed from the `catch (\Throwable $e)` block in `SeedDemoCommand::execute()`. PHP `finally` runs after `return` in `catch`, so both clear calls now execute exactly once per tenant iteration in the `finally` block regardless of success or failure path.
- WR-02 WARNING closed: `LandlordTenantsFixture::load()` gains a `$repository->findOneBy(['slug' => $data['slug']])` early-continue guard. Running `app:seed-demo` twice on a container that was not stopped with `docker compose down -v` no longer inserts duplicate tenant rows.

## Task Commits

Each task was committed atomically:

1. **Task 1: Replace logically-impossible Origin-resolver assertion (CR-02)** - `28690bb` (fix)
2. **Task 2: Remove double-clear from SeedDemoCommand catch block (WR-01)** - `171d389` (fix)
3. **Task 3: Add slug-uniqueness guard to LandlordTenantsFixture (WR-02)** - `043ba97` (fix)

## Files Created/Modified

- `examples/saas/bin/smoke.sh` — Lines 41-49 replaced: old `# Origin-resolver path (Phase 17 invariant)` block with `Host: tenancy.localhost` removed; new `# Resolver priority - HostResolver (30) wins over OriginHeaderResolver (25)` block with `Host: acme.tenancy.localhost` + `Origin: https://globex.tenancy.localhost` added
- `examples/saas/src/Command/SeedDemoCommand.php` — Lines 134-135 deleted: `$this->tenantContext->clear()` and `$this->bootstrapperChain->clear()` inside `catch` block; `finally` block unchanged
- `examples/saas/src/DataFixtures/LandlordTenantsFixture.php` — 6 lines inserted: `$repository = $manager->getRepository(DemoTenant::class);` before `foreach`, plus `if (null !== $repository->findOneBy(['slug' => $data['slug']])) { continue; }` as first statement inside `foreach`

## Decisions Made

- **Resolver-priority framing for CR-02:** Option (a) from VERIFICATION.md — `Host: acme + Origin: globex` — was chosen over options (b) and (c). It directly proves the resolver chain's priority ordering end-to-end against the live stack, which is the most meaningful invariant for a smoke test.
- **CR-01 not actioned:** `actions/checkout@v5` in `demo-smoke.yml` is correct — matches the repo-wide convention set by commit `2d5e889` on 2026-05-22 12:29, before Plan 21-04 ran at 14:46. REVIEW.md CR-01 is a confirmed false positive per VERIFICATION.md.
- **CR-03 not actioned:** `SeedDemoCommand` depending on `DoctrineFixturesBundle` in `dev`-only environments is out of scope for this gap-closure plan (VERIFICATION.md did not flag it as a gap; demo runs with `APP_ENV=dev` per `compose.yaml`).

## Deviations from Plan

### Pre-existing constraint: PHPStan standalone file analysis

The plan's `<verification>` block calls for:
```
vendor/bin/phpstan analyse examples/saas/src/Command/SeedDemoCommand.php examples/saas/src/DataFixtures/LandlordTenantsFixture.php --no-progress
```

This command exits non-zero because `phpstan.neon` only configures analysis for `src/` (the bundle source). Demo app classes (`DemoTenant`, `Post`, `ORMExecutor`, `Fixture`) are not in the bundle's autoload context. This failure existed at the base commit `a1db883` before any plan-05 changes — confirmed by running `git show a1db883:examples/saas/...` which produces the same "class.notFound" errors.

The pre-commit hook runs `vendor/bin/phpstan analyse` (no path args) which uses the `phpstan.neon` `paths: [src]` config and passes clean on all three commits. PHPStan level 9 is not regressed.

**Total deviations:** None to plan logic. One pre-existing constraint documented above.

## Issues Encountered

- **Vendor symlink for worktree:** The git worktree working directory did not have a `vendor/` directory. The shared pre-commit hook (`vendor/bin/php-cs-fixer`, `vendor/bin/phpstan`, `vendor/bin/phpunit`) failed on first commit attempt. Resolution: created a symlink `vendor -> /[main-repo]/vendor` in the worktree root. This is a one-time setup for worktree-based execution.

## Verification Status

All automated checks that can run without Docker pass:

| Check | Status |
|-------|--------|
| `bash -n examples/saas/bin/smoke.sh` | PASS |
| `Host: acme.tenancy.localhost` present in smoke.sh | PASS |
| `Origin: https://globex.tenancy.localhost` present in smoke.sh | PASS |
| Old `Origin-resolver did not resolve acme` message gone | PASS |
| `Host: tenancy.localhost` appears exactly once (landlord block) | PASS |
| `php -l SeedDemoCommand.php` | PASS |
| `bootstrapperChain->clear()` count = 1 | PASS |
| `tenantContext->clear()` count = 1 | PASS |
| catch block has 0 clear() calls | PASS |
| `php -l LandlordTenantsFixture.php` | PASS |
| `findOneBy(['slug' => ...])` present | PASS |
| `continue;` inside findOneBy guard | PASS |
| `$manager->persist($tenant);` count = 1 | PASS |
| `$manager->flush();` count = 1 | PASS |
| `'slug' => '` appears exactly 3 times (acme/globex/initech) | PASS |
| `.github/workflows/demo-smoke.yml` unchanged | PASS |
| Bundle PHPStan level 9 (full suite) | PASS (all 3 commits) |
| PHPUnit 557 tests / 2064 assertions | PASS (all 3 commits) |

### Remaining human verification required

The following cannot be verified without a running Docker stack (unchanged from VERIFICATION.md):

1. `docker compose up -d --wait --build` exits 0 (human_verification[1])
2. Browser tenant isolation — distinct content per subdomain (human_verification[2])
3. WDT Tenancy panel on tenant and landlord pages (human_verification[3])
4. Mailpit per-tenant From: addresses (human_verification[4])
5. `bash bin/smoke.sh` exits 0 against the live stack with the fixed assertion (human_verification[5])

Once human_verification[5] is confirmed passing, Phase 21's VERIFICATION.md status can be updated from `gaps_found` to `verified`.

## Next Phase Readiness

- Phase 21 gap-closure complete. All automated checks pass.
- Human Docker re-verification (5 items above) is the remaining gate before Phase 21 can be marked `verified`.
- No production bundle source (`src/` at repo root) was modified. All changes are in `examples/saas/`.

## Self-Check: PASSED

- FOUND: `.planning/phases/21-demo-app/21-05-SUMMARY.md`
- FOUND: commit `28690bb` (Task 1 — smoke.sh CR-02)
- FOUND: commit `171d389` (Task 2 — SeedDemoCommand WR-01)
- FOUND: commit `043ba97` (Task 3 — LandlordTenantsFixture WR-02)
- FOUND: `examples/saas/bin/smoke.sh`
- FOUND: `examples/saas/src/Command/SeedDemoCommand.php`
- FOUND: `examples/saas/src/DataFixtures/LandlordTenantsFixture.php`

---
*Phase: 21-demo-app*
*Completed: 2026-05-22*
