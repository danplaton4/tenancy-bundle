---
phase: 01-core-foundation
plan: 02
subsystem: domain
tags: [php, tenancy, value-holder, bootstrapper, event-dispatcher, phpunit]

# Dependency graph
requires:
  - phase: 01-core-foundation plan 01
    provides: TenancyBundle, BootstrapperChainPass, services.php stub, PHPUnit config
provides:
  - TenantInterface: 5-method contract (getSlug/getDomain/getConnectionConfig/getName/isActive)
  - TenantContext: zero-dependency pure value holder with setTenant/getTenant/hasTenant/clear
  - TenantBootstrapperInterface: boot(TenantInterface)/clear() contract
  - BootstrapperChain: ordered execution with EventDispatcher injection and reverse-order clear
  - TenantBootstrapped event stub (unblocks tests; fleshed out in Plan 01-03)
  - 7 passing unit tests (5 TenantContext + 2 BootstrapperChain)
affects:
  - 01-core-foundation plan 03 (events — TenantBootstrapped already exists as stub)
  - 01-core-foundation plan 04 (Tenant entity implements TenantInterface)
  - 01-core-foundation plan 05 (TenantContextOrchestrator uses TenantContext + BootstrapperChain)
  - All subsequent phases using TenantContext or TenantBootstrapperInterface

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Pure value holder: TenantContext has zero constructor parameters — injected via service ID, not constructor"
    - "PSR-14 plain object events: TenantBootstrapped is a final class with no base extension"
    - "Reverse-order bootstrapper cleanup: BootstrapperChain::clear() uses array_reverse for LIFO teardown"

key-files:
  created:
    - src/Context/TenantContext.php
    - src/Event/TenantBootstrapped.php
    - tests/Unit/Context/TenantContextTest.php
    - tests/Unit/Bootstrapper/BootstrapperChainTest.php
  modified:
    - src/Bootstrapper/BootstrapperChain.php
    - config/services.php

key-decisions:
  - "TenantContext has zero constructor parameters — enforced by testHasZeroConstructorParameters reflection test"
  - "TenantBootstrapped event stub created in Plan 01-02 (Rule 3) to unblock BootstrapperChain tests; Plan 01-03 adds full event test suite"
  - "BootstrapperChain::boot() collects bootstrapper FQCNs and passes them to TenantBootstrapped event"
  - "EventDispatcher mock in BootstrapperChainTest accepts any dispatch() call — avoids TenantBootstrapped runtime type check"

patterns-established:
  - "Pure value holder pattern: zero-dep service annotated only via service ID alias in services.php"
  - "Deferred event class stub: create minimal class in src/Event/ when needed to unblock tests, flesh out in dedicated event plan"

requirements-completed: [CORE-01, CORE-03]

# Metrics
duration: 5min
completed: 2026-03-18
---

# Phase 1 Plan 02: Core Domain Contracts and Value Holder Summary

**Zero-dependency TenantContext value holder, TenantBootstrapperInterface contract, and EventDispatcher-wired BootstrapperChain with 7 passing unit tests**

## Performance

- **Duration:** 5 min
- **Started:** 2026-03-17T22:30:15Z
- **Completed:** 2026-03-17T22:35:00Z
- **Tasks:** 2
- **Files modified:** 6

## Accomplishments

- TenantContext implemented as a zero-dependency pure value holder (setTenant/getTenant/hasTenant/clear) with enforced zero-constructor-param test
- BootstrapperChain upgraded from stub to full implementation: EventDispatcherInterface injection, ordered boot() execution, reverse-order clear(), TenantBootstrapped dispatch
- TenantBootstrapped event stub created to unblock BootstrapperChain runtime tests (Plan 01-03 fleshes out)
- All 7 unit tests pass: 5 TenantContextTest + 2 BootstrapperChainTest

## Task Commits

Each task was committed atomically:

1. **Task 1: Create TenantInterface and TenantContext** - `6b6a609` (feat)
2. **Task 2: Create TenantBootstrapperInterface and BootstrapperChain** - `eb30c84` (feat)

**Plan metadata:** _(final metadata commit follows)_

## Files Created/Modified

- `src/Context/TenantContext.php` - Zero-dependency pure value holder; final class with setTenant/getTenant/hasTenant/clear
- `src/Event/TenantBootstrapped.php` - Minimal event stub with getTenant()/getBootstrappers(); fleshed out in Plan 01-03
- `src/Bootstrapper/BootstrapperChain.php` - Updated from stub: EventDispatcherInterface constructor, boot() collects FQCNs + dispatches, clear() runs in reverse
- `config/services.php` - Added `->args([service('event_dispatcher')])` to tenancy.bootstrapper_chain service definition
- `tests/Unit/Context/TenantContextTest.php` - 5 tests: initial state, set/get, clear, overwrite, zero constructor params
- `tests/Unit/Bootstrapper/BootstrapperChainTest.php` - 2 tests: boot order (A then B), clear reverse order (B then A)

## Decisions Made

- TenantBootstrapped event stub created as Rule 3 auto-fix: BootstrapperChain::boot() instantiates the event at runtime, so without the class the tests fatal. A minimal stub unblocks Plan 01-02 tests; Plan 01-03 replaces it with the full implementation and adds event-level tests.
- BootstrapperChainTest mocks EventDispatcher with `->expects($this->once())->method('dispatch')` (no argument type assertion) — keeps test independent of TenantBootstrapped type until Plan 01-03 adds event tests.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Created TenantBootstrapped event stub to unblock BootstrapperChain tests**
- **Found during:** Task 2 (BootstrapperChain implementation and test)
- **Issue:** BootstrapperChain::boot() calls `new TenantBootstrapped(...)` at runtime. Without the class, `testBootCallsAllBootstrappersInOrder` fatals with "Class not found". The plan noted TenantBootstrapped would not exist until Plan 01-03 but also required boot()-calling tests to pass.
- **Fix:** Created `src/Event/TenantBootstrapped.php` with minimal constructor (TenantInterface + string[]) and getTenant()/getBootstrappers() accessors. Plan 01-03 will add the full test suite for this event class.
- **Files modified:** src/Event/TenantBootstrapped.php (created)
- **Verification:** All 7 tests pass after stub creation
- **Committed in:** eb30c84 (Task 2 commit)

---

**Total deviations:** 1 auto-fixed (Rule 3 - blocking dependency)
**Impact on plan:** Essential to make boot()-calling tests pass. Minimal scope — the stub has no extra logic beyond what BootstrapperChain::boot() requires. Plan 01-03 will own the full event implementation.

## Issues Encountered

- Plan stated "The mock avoids runtime dependency on TenantBootstrapped" but the instantiation occurs in production code (BootstrapperChain::boot()), not in test code — mocking EventDispatcher cannot prevent `new TenantBootstrapped` from executing. Resolved via Rule 3 stub creation.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plan 01-03 can consume TenantBootstrapped stub and add full event implementation + tests
- Plan 01-03 should also add TenantResolved and TenantContextCleared events
- TenantContext and TenantBootstrapperInterface are ready for all downstream phases
- No blockers for Plan 01-03

## Self-Check: PASSED

- src/TenantInterface.php: FOUND
- src/Context/TenantContext.php: FOUND
- src/Bootstrapper/TenantBootstrapperInterface.php: FOUND
- src/Bootstrapper/BootstrapperChain.php: FOUND
- src/Event/TenantBootstrapped.php: FOUND
- tests/Unit/Context/TenantContextTest.php: FOUND
- tests/Unit/Bootstrapper/BootstrapperChainTest.php: FOUND
- Commit 6b6a609: FOUND
- Commit eb30c84: FOUND
- 7 unit tests: ALL PASSING

---
*Phase: 01-core-foundation*
*Completed: 2026-03-18*
