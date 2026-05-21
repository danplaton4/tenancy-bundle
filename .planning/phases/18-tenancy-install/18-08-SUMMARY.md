---
phase: 18-tenancy-install
plan: 08
subsystem: testing
tags: [phpunit, symfony-kernel, tdd, canary, regression, zero-config]

requires:
  - phase: 18-tenancy-install
    provides: "Phase 18 defect inventory documenting the 6 nullOnInvalid() sites (18-VERIFICATION.md, 18-HUMAN-UAT.md)"

provides:
  - "ZeroConfigKernelBootTest: canary RED-bar regression test for zero-config boot crash"
  - "RemoveTenancyProviderPass: compiler pass simulating absent doctrine/orm in test environment"
  - "MakeZeroConfigServicesPublicPass: compiler pass exposing resolver services for container inspection"
  - "phpunit.xml.dist canary-red group exclusion: prevents CI from failing on intentionally-RED tests"
  - "tests/bootstrap.php worktree isolation: clears stale compiled container caches with wrong base path"

affects:
  - 18-09-PLAN
  - 18-10-PLAN
  - 18-11-PLAN

tech-stack:
  added: []
  patterns:
    - "canary-red PHPUnit group: marks intentionally-failing regression tests for TDD RED phase"
    - "RemoveTenancyProviderPass pattern: simulates doctrine/orm absence in test environment without unloading classes"
    - "Worktree cache isolation in bootstrap.php: detects stale Symfony compiled containers referencing wrong project root"

key-files:
  created:
    - tests/Integration/ZeroConfigKernelBootTest.php
  modified:
    - phpunit.xml.dist
    - tests/bootstrap.php

key-decisions:
  - "RED-bar approach: commit the failing test BEFORE any production fixes so the regression gate is established first (TDD discipline)"
  - "RemoveTenancyProviderPass used instead of trying to unload doctrine/orm classes: simulates the fresh-skeleton scenario where doctrine/orm is absent without requiring PHP class unloading"
  - "canary-red PHPUnit group + exclusion in phpunit.xml.dist: allows intentionally-failing tests to coexist with green CI until the fix lands in 18-09/18-10"
  - "Bootstrap worktree cache purge: necessary because Symfony compiled containers hard-code entity mapping absolute paths; stale containers from main-repo runs cause class redeclaration crashes when integration tests run from a git worktree"

requirements-completed:
  - DX-06

duration: 25min
completed: 2026-05-21
---

# Phase 18 Plan 08: ZeroConfigKernelBootTest Canary Regression Test Summary

**RED-bar canary test capturing the zero-config kernel boot crash: TypeError thrown when resolver/command constructors receive null for non-nullable TenantProviderInterface due to nullOnInvalid() wiring without a registered provider**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-05-21T00:00:00Z
- **Completed:** 2026-05-21T00:00:00Z
- **Tasks:** 1
- **Files modified:** 3

## Accomplishments

- Created `ZeroConfigKernelBootTest` that boots a minimal kernel with TenancyBundle but NO `tenancy:` config block, replicating the exact state of a fresh `composer require danplaton4/tenancy-bundle` skeleton
- RED bar confirmed: `vendor/bin/phpunit tests/Integration/ZeroConfigKernelBootTest.php --group canary-red` exits non-zero with `TypeError: HostResolver::__construct(): Argument #1 ($tenantProvider) must be of type TenantProviderInterface, null given` and `TypeError: ConsoleResolver::__construct(): Argument #1 ($tenantProvider) must be of type TenantProviderInterface, null given`
- Fixed a pre-existing worktree infrastructure bug in `tests/bootstrap.php` that caused class redeclaration crashes when running the full integration suite from a git worktree

## RED-bar Confirmation

Running `vendor/bin/phpunit tests/Integration/ZeroConfigKernelBootTest.php --group canary-red` on current master produces:

```
ERRORS!
Tests: 3, Assertions: 4, Errors: 2, PHPUnit Deprecations: 1, Risky: 1.

TypeError: Tenancy\Bundle\Resolver\HostResolver::__construct(): Argument #1
($tenantProvider) must be of type Tenancy\Bundle\Provider\TenantProviderInterface,
null given

TypeError: Tenancy\Bundle\Resolver\ConsoleResolver::__construct(): Argument #1
($tenantProvider) must be of type Tenancy\Bundle\Provider\TenantProviderInterface,
null given
```

This matches the exact error from the 2026-05-21 human UAT transcript (18-HUMAN-UAT.md).

## Task Commits

1. **Task 1: Add ZeroConfigKernelBootTest canary regression test** - `089671e` (test)

**Plan metadata:** (docs commit follows)

## Files Created/Modified

- `tests/Integration/ZeroConfigKernelBootTest.php` - Canary regression test with 3 methods: testContainerCompilesAndKernelBoots, testHostResolverInstantiatesWithNullProvider, testConsoleApplicationVersionCommandExitsZero
- `phpunit.xml.dist` - Added `<groups><exclude><group>canary-red</group></exclude></groups>` so the pre-commit hook does not fail on intentionally-RED tests
- `tests/bootstrap.php` - Worktree isolation fix: purges stale Symfony compiled container caches that hard-code the main repo path as kernel.project_dir or Doctrine entity mapping dir

## Decisions Made

- **RemoveTenancyProviderPass chosen over class unloading:** In the test environment, `doctrine/orm` IS present as a dev dependency (unlike a fresh skeleton). Without intervention, the container would fail with `ServiceNotFoundException` (missing `doctrine.orm.default_entity_manager`) rather than the documented `TypeError`. `RemoveTenancyProviderPass` removes `tenancy.provider` after services.php imports it, simulating the doctrine-absent scenario and producing the correct `nullOnInvalid() → null → TypeError` failure path.
- **canary-red group + phpunit.xml.dist exclusion:** The pre-commit hook runs `vendor/bin/phpunit --no-progress` (full suite). Without exclusion, the intentionally-RED test would fail the hook and block the commit — defeating the purpose of the RED-first TDD approach.
- **Bootstrap worktree cache purge:** The `tests/bootstrap.php` was updated to detect and purge Symfony compiled container caches generated from the main repo checkout. These stale caches contain absolute Doctrine entity mapping paths (`AttributeDriver(['/main-repo/src/Entity'])`) that cause PHP class redeclaration fatals when a git worktree (different filesystem root) runs the same kernels and loads the same entity classes via the worktree's autoloader prepend first.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Fixed pre-existing worktree infrastructure: stale compiled container cache causing class redeclaration**
- **Found during:** Task 1 (verifying the pre-commit hook passes)
- **Issue:** Running the full integration test suite from the git worktree caused `Fatal: Cannot redeclare class Tenancy\Bundle\Tests\Integration\Support\Entity\TestProduct`. A Symfony compiled container generated by a previous main-repo run had hard-coded entity mapping paths to the main repo's `src/Entity`. When the worktree's bootstrap prepended the worktree's `src/` to the autoloader, the entity class loaded from the worktree path first, then the stale compiled container tried to instantiate `AttributeDriver` with the main-repo path, triggering a direct `require` of the same class file — causing the redeclaration.
- **Fix:** Added cache-staleness detection to `tests/bootstrap.php` that scans known Symfony test kernel temp dirs, reads inner `*.php` container files, and purges any cache dirs whose content references a project path that does NOT start with the current worktree root.
- **Files modified:** `tests/bootstrap.php`
- **Verification:** Full integration suite (`vendor/bin/phpunit --testsuite integration`) passes: 120 tests, 862 assertions, no redeclaration errors.
- **Committed in:** `089671e` (Task 1 commit)

---

**Total deviations:** 1 auto-fixed (1 blocking infrastructure)
**Impact on plan:** The bootstrap fix is a worktree infrastructure improvement orthogonal to the canary test itself. It enables the pre-commit hook to pass when running from a worktree. No scope creep — the change is minimal and directly enables the commit to succeed.

## Issues Encountered

- PHPStan OOM (128MB limit) when running without `--memory-limit=512M` from the worktree. Root cause: PHPStan had no warm cache for the worktree's absolute paths (the main repo's cache uses different paths). Solution: run `vendor/bin/phpstan analyse --memory-limit=512M` once to warm the cache, then subsequent runs without the flag succeed. This is a cold-start performance issue only.

## Next Phase Readiness

- **18-09 and 18-10** (resolver/command nullable constructor fixes) can now depend on `ZeroConfigKernelBootTest` as their regression gate. After they apply the fix, `vendor/bin/phpunit tests/Integration/ZeroConfigKernelBootTest.php --group canary-red` should exit 0 with 3 passing tests.
- The `canary-red` group exclusion in `phpunit.xml.dist` should be REMOVED in plan 18-11 (GREEN phase) once the fixes land and the tests pass normally.

## Self-Check

- [x] `tests/Integration/ZeroConfigKernelBootTest.php` exists
- [x] Commit `089671e` exists in git log
- [x] Test is RED: exits non-zero with TypeError matching documented UAT failure
- [x] Full unit suite passes: 425 tests, 1149 assertions
- [x] PHPStan level 9: 0 errors
- [x] php-cs-fixer: 0 violations
- [x] No modifications to STATE.md, ROADMAP.md, or REQUIREMENTS.md

---
*Phase: 18-tenancy-install*
*Completed: 2026-05-21*
