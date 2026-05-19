---
phase: 03-database-per-tenant-driver
plan: "04"
subsystem: database
tags: [doctrine, entity-manager, event-listener, symfony-events, tdd]

# Dependency graph
requires:
  - phase: 03-database-per-tenant-driver
    provides: TenantContextCleared event (03-01), TenantContext teardown flow in TenantContextOrchestrator (02-05)
provides:
  - EntityManagerResetListener: resets tenant EM on TenantContextCleared to prevent identity map pollution
affects:
  - 03-05 (phase capstone — wires EntityManagerResetListener into bundle extension or services)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "#[AsEventListener] on __invoke() for single-event listeners (no method: parameter needed)"
    - "ManagerRegistry::resetManager('tenant') for full EM close+recreate on teardown"
    - "ReflectionClass::getAttributes() to verify PHP 8 attributes in unit tests"

key-files:
  created:
    - src/EventListener/EntityManagerResetListener.php
    - tests/Unit/EventListener/EntityManagerResetListenerTest.php
  modified: []

key-decisions:
  - "resetManager('tenant') not clear() — resetManager closes and recreates the EM, preventing identity map pollution across tenant switches; clear() only detaches entities"
  - "Landlord EM never touched — only 'tenant' named manager is reset; landlord EM retains its state across requests"

patterns-established:
  - "Single-event listeners use __invoke() + #[AsEventListener] with no method: parameter"
  - "Test resetManager expectations with ->with('tenant') to lock in the exact manager name"

requirements-completed: [ISOL-02]

# Metrics
duration: 3min
completed: 2026-03-19
---

# Phase 03 Plan 04: EntityManagerResetListener Summary

**EntityManagerResetListener wired to TenantContextCleared via #[AsEventListener], calls resetManager('tenant') to close and recreate the tenant EM on every tenant teardown**

## Performance

- **Duration:** ~3 min
- **Started:** 2026-03-19T06:17:00Z
- **Completed:** 2026-03-19T06:18:16Z
- **Tasks:** 1 (TDD: test + feat)
- **Files modified:** 2

## Accomplishments
- EntityManagerResetListener implemented as a final class with `__invoke()` and `#[AsEventListener(event: TenantContextCleared::class)]`
- Calls `resetManager('tenant')` to fully close and recreate the tenant EM — not just clear() — eliminating identity map pollution between tenant switches
- Landlord EM is never touched
- 3 unit tests: invocation with exact argument, attribute reflection, no-landlord guarantee

## Task Commits

Each task was committed atomically (TDD flow):

1. **Task 1 (RED): Failing tests** - `c948083` (test)
2. **Task 1 (GREEN): EntityManagerResetListener implementation** - `6dbbc0f` (feat)

**Plan metadata:** (docs commit — see below)

_Note: TDD task has two commits (test RED → feat GREEN)_

## Files Created/Modified
- `src/EventListener/EntityManagerResetListener.php` - Final event listener; resets tenant EM on TenantContextCleared
- `tests/Unit/EventListener/EntityManagerResetListenerTest.php` - 3 unit tests verifying reset behavior and attribute presence

## Decisions Made
- Used `resetManager('tenant')` not `clear()`: resetManager closes and recreates the ObjectManager, fully releasing any held entity state. `clear()` only detaches entities and would leave the EM in a reusable state that could still leak lazy-loaded proxies.
- Landlord EM never touched: isolation is one-directional — tenant context is reset, landlord schema is stable across all requests.

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered
None. PHPUnit flag `-x` in the plan's verify block is not valid for PHPUnit 11 (unknown option); ran without it — no impact on test results.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- EntityManagerResetListener is ready to be registered as a service in the bundle's DI extension (03-05 capstone)
- The TenantContextOrchestrator teardown flow dispatches TenantContextCleared, which will trigger this listener automatically once wired
- No blockers

---
*Phase: 03-database-per-tenant-driver*
*Completed: 2026-03-19*
