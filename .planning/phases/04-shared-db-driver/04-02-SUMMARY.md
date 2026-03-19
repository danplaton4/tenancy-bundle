---
phase: 04-shared-db-driver
plan: "02"
subsystem: database
tags: [doctrine, sql-filter, shared-db, tenancy, tdd, phpunit]

# Dependency graph
requires:
  - phase: 04-shared-db-driver/04-01
    provides: TenantAwareFilter with setTenantContext() setter injection API

provides:
  - SharedDriver implementing TenantDriverInterface (boot injects context into filter, clear is no-op)
  - TenancyBundle wiring for shared_db driver (loadExtension conditional block)
  - TenantAwareFilter Doctrine filter registration via prependExtension when driver=shared_db
  - Compile-time mutual exclusion guard: shared_db + database.enabled throws config error

affects: [04-03-integration-tests, phase-05-cache]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "TDD with SQLFilter-extending spy: PHPUnit ClassIsFinalException on final filter classes bypassed by creating a FilterSpy extends SQLFilter with mock EM passed to parent constructor"
    - "Conditional DI registration in loadExtension(): parallel if-blocks for database.enabled and driver=shared_db, each wiring their own driver service"
    - "prependExtension() parallel branch: $isSharedDb detection mirrors $databaseEnabled pattern for Doctrine filter registration"

key-files:
  created:
    - src/Driver/SharedDriver.php
    - tests/Unit/Driver/SharedDriverTest.php
  modified:
    - src/TenancyBundle.php

key-decisions:
  - "FilterSpy extends SQLFilter (not standalone class) to satisfy PHPUnit 11 return-type enforcement on FilterCollection::getFilter() — mock EM passed to final SQLFilter constructor"
  - "SharedDriver::clear() is a documented no-op — TenantContext::clear() runs in BootstrapperChain; filter reads hasTenant() live at query time"
  - "TenancyBundle validate() placed after ->end() closing children block — rejects shared_db + database.enabled at container compile time with a clear error message"

patterns-established:
  - "FilterSpy pattern: extend abstract base class with mock dependency to bypass PHPUnit ClassIsFinalException on concrete filter/listener classes"
  - "loadExtension conditional wiring: each driver type (database.enabled, shared_db) has its own if-block registering its services independently"

requirements-completed: [ISOL-03, ISOL-05]

# Metrics
duration: 8min
completed: 2026-03-19
---

# Phase 4 Plan 02: SharedDriver and TenancyBundle Wiring Summary

**SharedDriver (TenantDriverInterface) implemented with boot() injecting TenantContext into TenantAwareFilter via setter injection, plus full TenancyBundle config wiring for shared_db driver including compile-time mutual exclusion guard**

## Performance

- **Duration:** ~8 min
- **Started:** 2026-03-19T08:20:00Z
- **Completed:** 2026-03-19T08:28:00Z
- **Tasks:** 2 (Task 1 TDD, Task 2 bundle wiring)
- **Files modified:** 3

## Accomplishments

- SharedDriver implements TenantDriverInterface: boot() calls setTenantContext(TenantContext, strictMode) on the tenancy_aware filter retrieved from EntityManager's FilterCollection; clear() is a documented no-op
- TenancyBundle.configure() now rejects shared_db + database.enabled: true combination at compile time with a clear error message
- TenancyBundle.loadExtension() registers tenancy.shared_driver service (tagged tenancy.bootstrapper) when driver=shared_db
- TenancyBundle.prependExtension() registers tenancy_aware Doctrine filter (enabled: true) when driver=shared_db

## Task Commits

Each task was committed atomically:

1. **Task 1 RED: Failing tests for SharedDriver** - `3879d23` (test)
2. **Task 1 GREEN: SharedDriver implementation** - `e0df7a8` (feat)
3. **Task 2: Wire SharedDriver and filter into TenancyBundle** - `3a66616` (feat)

_Note: TDD task split into RED (test) and GREEN (feat) commits as required by TDD flow._

## Files Created/Modified

- `src/Driver/SharedDriver.php` - TenantDriverInterface implementation; boot() injects TenantContext+strictMode into tenancy_aware filter; clear() no-op
- `tests/Unit/Driver/SharedDriverTest.php` - 5 unit tests covering boot/clear/interface contract; uses FilterSpy extending SQLFilter to bypass final class mocking limitation
- `src/TenancyBundle.php` - Three additions: validate() block, loadExtension shared_db block, prependExtension filter registration block

## Decisions Made

- **FilterSpy extends SQLFilter:** PHPUnit 11 enforces return-type compatibility even with `willReturn()`. FilterCollection::getFilter() returns `SQLFilter`, so the spy must extend it. The abstract `SQLFilter` constructor is `final` and takes `EntityManagerInterface` — the mock EM is passed as the parent constructor argument. This avoids needing interfaces or bypassing PHPUnit's type checks.
- **clear() as explicit no-op:** TenantContext::clear() is already called by BootstrapperChain before SharedDriver::clear() runs. The filter reads `hasTenant()` live at query time — no state to reset in the driver itself. This is documented in the clear() docblock.
- **validate() position:** The `->validate()` block goes after `->end()` that closes `->children()`, at the root node level. This is the correct position for cross-field validation in Symfony's Config component.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] FilterSpy must extend SQLFilter for PHPUnit 11 type enforcement**
- **Found during:** Task 1 GREEN (SharedDriverTest execution)
- **Issue:** PHPUnit 11 throws `IncompatibleReturnValueException` when `willReturn()` receives a value whose type doesn't match the mocked method's declared return type. `FilterCollection::getFilter()` returns `SQLFilter` — the original `FilterSpy` was a standalone class that didn't extend it.
- **Fix:** Changed `FilterSpy` to extend `SQLFilter` (abstract class), implement the required `addFilterConstraint()` abstract method, and pass the mock EM to `parent::__construct()`. The spy adds `setTenantContext()` as a non-override method for capturing calls.
- **Files modified:** `tests/Unit/Driver/SharedDriverTest.php`
- **Verification:** All 5 tests pass after fix.
- **Committed in:** `e0df7a8` (Task 1 GREEN commit)

---

**Total deviations:** 1 auto-fixed (Rule 1 — test infrastructure bug, not production code)
**Impact on plan:** Fix was necessary for test correctness. No production code affected. No scope creep.

## Issues Encountered

- Pre-existing `ListenerPriorityTest` failures (2 tests) were present before this plan's changes and are unrelated to shared_db wiring. Root cause is a stale compiled container cache referencing an old `TenantContextOrchestrator` constructor signature with 3 args. Logged to `deferred-items.md`.

## Next Phase Readiness

- SharedDriver is wired and tested — Plan 03 (integration tests) can proceed
- All three TenancyBundle integration points are complete: config validation, service registration, filter registration
- The tenancy_aware filter is auto-registered when driver=shared_db — no application-level setup needed

---
*Phase: 04-shared-db-driver*
*Completed: 2026-03-19*
