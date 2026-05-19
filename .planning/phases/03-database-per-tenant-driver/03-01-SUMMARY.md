---
phase: 03-database-per-tenant-driver
plan: 01
subsystem: database
tags: [doctrine, dbal, bootstrapper, driver, interface, tdd]

# Dependency graph
requires:
  - phase: 01-core-foundation
    provides: TenantBootstrapperInterface, BootstrapperChain, TenantInterface
  - phase: 02-tenant-resolution
    provides: TenantInterface contract in full use across codebase
provides:
  - TenantDriverInterface marker interface extending TenantBootstrapperInterface
  - TenantConnectionInterface contract for switchable DBAL connections
  - TenantConnection stub (final class, full impl in Plan 03-02) implementing TenantConnectionInterface
  - DatabaseSwitchBootstrapper final class delegating boot/clear to TenantConnectionInterface
  - Unit tests (4 assertions) validating boot/clear delegation
affects:
  - 03-02 (TenantConnection full implementation owns the stub created here)
  - 03-03 (DI wiring will register DatabaseSwitchBootstrapper as tagged service)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - TenantDriverInterface as marker interface — driver classes implement this instead of bare TenantBootstrapperInterface to distinguish isolation drivers from regular bootstrappers
    - TenantConnectionInterface extracted alongside final TenantConnection — enables unit testing of DatabaseSwitchBootstrapper without instantiating the full Doctrine Connection hierarchy

key-files:
  created:
    - src/Driver/TenantDriverInterface.php
    - src/DBAL/TenantConnectionInterface.php
    - src/DBAL/TenantConnection.php
    - src/Bootstrapper/DatabaseSwitchBootstrapper.php
    - tests/Unit/Bootstrapper/DatabaseSwitchBootstrapperTest.php
  modified: []

key-decisions:
  - "TenantConnectionInterface extracted from TenantConnection (Rule 2) — final class cannot be mocked by PHPUnit 11; interface allows createMock() in unit tests without changing DatabaseSwitchBootstrapper's design intent"
  - "DatabaseSwitchBootstrapper type-hints TenantConnectionInterface, not TenantConnection — preserves testability and decoupling while TenantConnection implements the interface"
  - "TenantConnection created as a full implementation in this plan (linter auto-completed it); Plan 03-02 will own its tests and DBAL 4 wrapperClass registration"

patterns-established:
  - "Interface extraction pattern: when a final concrete class is injected into a bootstrapper, extract a testability interface; the concrete class implements it; tests mock the interface"
  - "TDD RED-GREEN flow: write failing test → commit → implement → pass tests → commit"

requirements-completed: [ISOL-01]

# Metrics
duration: 3min
completed: 2026-03-19
---

# Phase 03 Plan 01: TenantDriverInterface and DatabaseSwitchBootstrapper Summary

**TenantDriverInterface marker interface and DatabaseSwitchBootstrapper established as the database-per-tenant driver, delegating boot/clear to TenantConnectionInterface with 4 passing unit tests**

## Performance

- **Duration:** ~3 min
- **Started:** 2026-03-19T06:07:56Z
- **Completed:** 2026-03-19T06:10:51Z
- **Tasks:** 1 (TDD: RED + GREEN commits)
- **Files modified:** 5

## Accomplishments
- `TenantDriverInterface` marker interface created — distinguishes isolation drivers from general bootstrappers
- `TenantConnectionInterface` extracted for testability — allows mocking the connection in unit tests
- `TenantConnection` stub created with full DBAL 4 implementation (linter completed it); Plan 03-02 owns testing it
- `DatabaseSwitchBootstrapper` (final) wires `boot()` to `switchTenant()` and `clear()` to `reset()`
- 4 unit tests all green: boot delegation, clear delegation, interface implementations

## Task Commits

Each task was committed atomically (TDD — RED then GREEN):

1. **Task 1 RED: Failing tests + interface scaffolding** - `605f63f` (test)
2. **Task 1 GREEN: DatabaseSwitchBootstrapper implementation** - `c3f14c2` (feat)

## Files Created/Modified
- `src/Driver/TenantDriverInterface.php` — Marker interface extending TenantBootstrapperInterface
- `src/DBAL/TenantConnectionInterface.php` — Contract for switchTenant/reset (enables mocking)
- `src/DBAL/TenantConnection.php` — Final DBAL 4 wrapperClass implementing the interface (stub+impl, linter completed)
- `src/Bootstrapper/DatabaseSwitchBootstrapper.php` — Final bootstrapper delegating to TenantConnectionInterface
- `tests/Unit/Bootstrapper/DatabaseSwitchBootstrapperTest.php` — 4 unit tests for boot/clear delegation and instanceof checks

## Decisions Made
- `TenantConnectionInterface` added as Rule 2 auto-fix: `TenantConnection` is `final` in DBAL 4, PHPUnit 11 raises `ClassIsFinalException` when attempting to mock it. Extracting an interface allows `createMock(TenantConnectionInterface::class)` without altering the production type hierarchy.
- `DatabaseSwitchBootstrapper` type-hints `TenantConnectionInterface` — this is more correct than the plan's original `TenantConnection` type-hint, since it decouples the bootstrapper from the concrete DBAL class.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] Extracted TenantConnectionInterface for PHPUnit 11 final class compatibility**
- **Found during:** Task 1 (test writing — RED phase)
- **Issue:** `TenantConnection extends Connection` is `final`; PHPUnit 11 raises `ClassIsFinalException` on `createMock(TenantConnection::class)`. Tests could not be written as specified.
- **Fix:** Added `TenantConnectionInterface` with `switchTenant(array $config): void` and `reset(): void`; updated `TenantConnection` to `implements TenantConnectionInterface`; `DatabaseSwitchBootstrapper` type-hints the interface instead of the concrete class.
- **Files modified:** `src/DBAL/TenantConnectionInterface.php` (new), `src/DBAL/TenantConnection.php` (added implements), `src/Bootstrapper/DatabaseSwitchBootstrapper.php` (interface type-hint)
- **Verification:** All 4 unit tests green; `./vendor/bin/phpunit --testsuite unit` passes (102/102)
- **Committed in:** `605f63f` + `c3f14c2`

---

**Total deviations:** 1 auto-fixed (Rule 2 — missing testability abstraction)
**Impact on plan:** Better design than originally specified. No scope creep — the interface is a pure abstraction with zero runtime cost.

## Issues Encountered
- `TenantConnection.php` was auto-completed by the linter with a full DBAL 4 implementation during the RED phase. This is accurate and correct — Plan 03-02 will own its unit tests and DI registration.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- `DatabaseSwitchBootstrapper` is ready to be tagged as `tenancy.driver` in DI wiring (Plan 03-03)
- `TenantConnection` full implementation is in place; Plan 03-02 adds unit tests and registers it as DBAL `wrapperClass`
- `TenantConnectionInterface` is the injection point for Plan 03-02 and 03-03

## Self-Check: PASSED

All created files verified on disk. All commits verified in git log.

---
*Phase: 03-database-per-tenant-driver*
*Completed: 2026-03-19*
