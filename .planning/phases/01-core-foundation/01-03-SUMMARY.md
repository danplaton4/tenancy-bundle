---
phase: 01-core-foundation
plan: "03"
subsystem: events
tags: [psr-14, events, lifecycle, readonly, phpunit]

# Dependency graph
requires:
  - phase: 01-core-foundation
    provides: TenantInterface, BootstrapperChain, TenantBootstrapped stub (from 01-02)

provides:
  - TenantResolved final class with public readonly tenant/request/resolvedBy
  - TenantBootstrapped final class with public readonly tenant/bootstrappers (full impl, replaces stub)
  - TenantContextCleared final signal-only class (no payload)
  - 7 unit tests covering all three lifecycle event classes
  - 2 additional BootstrapperChainTest tests verifying TenantBootstrapped dispatch

affects: [02-resolvers, 03-drivers, 05-bootstrappers, 06-messenger]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "PSR-14 plain PHP object events with public readonly constructor properties — no base class, no stopPropagation"
    - "Signal-only event pattern for TenantContextCleared — final class with no constructor or properties"
    - "Capture-via-callback pattern for asserting dispatched event payload in PHPUnit mock"

key-files:
  created:
    - src/Event/TenantResolved.php
    - src/Event/TenantContextCleared.php
    - tests/Unit/Event/TenantResolvedTest.php
    - tests/Unit/Event/TenantBootstrappedTest.php
    - tests/Unit/Event/TenantContextClearedTest.php
  modified:
    - src/Event/TenantBootstrapped.php (private readonly + getters -> public readonly promoted properties)
    - tests/Unit/Bootstrapper/BootstrapperChainTest.php (added 2 TenantBootstrapped-dependent tests)

key-decisions:
  - "TenantBootstrapped stub updated from private readonly + getter methods to public readonly promoted properties per PSR-14 plain object spec"
  - "TenantContextCleared has no constructor at all — empty final class body satisfies signal-only requirement"
  - "BootstrapperChainTest dispatch assertions use willReturnCallback to capture dispatched event object, enabling FQCN assertion against mock-generated class names"

patterns-established:
  - "Event pattern: final class, public readonly promoted properties, no extends, no implements StoppableEventInterface"
  - "Signal event pattern: final class with empty body — instantiable but carries no data"
  - "Mock event capture: willReturnCallback with &$dispatchedEvent reference to assert event payload after dispatch"

requirements-completed: [CORE-02]

# Metrics
duration: 2min
completed: 2026-03-18
---

# Phase 1 Plan 03: Lifecycle Event Classes Summary

**Three PSR-14 lifecycle event final classes with public readonly properties, 7 event unit tests, and 2 deferred BootstrapperChain dispatch tests — 28 unit tests total, all green**

## Performance

- **Duration:** 2 min
- **Started:** 2026-03-18T06:29:04Z
- **Completed:** 2026-03-18T06:31:00Z
- **Tasks:** 3
- **Files modified:** 7

## Accomplishments

- Created `TenantResolved` (tenant/request/resolvedBy), `TenantBootstrapped` (tenant/bootstrappers), `TenantContextCleared` (signal-only) as plain PHP final classes with no base class
- Updated existing `TenantBootstrapped` stub from private readonly + getter pattern to public readonly promoted properties to match PSR-14 spec
- Added 7 event unit tests covering payload correctness, nullable request, readonly enforcement, and signal-only structure
- Completed BootstrapperChainTest with 2 deferred event dispatch tests: FQCN array correctness and empty-chain dispatch

## Task Commits

Each task was committed atomically:

1. **Task 1: Create TenantResolved, TenantBootstrapped, TenantContextCleared** - `f2b087e` (feat)
2. **Task 2: Create unit tests for all three event classes** - `fd48667` (test)
3. **Task 3: Add TenantBootstrapped-dependent tests to BootstrapperChainTest** - `7310650` (test)

## Files Created/Modified

- `src/Event/TenantResolved.php` - Final class, public readonly tenant/request(?Request)/resolvedBy
- `src/Event/TenantBootstrapped.php` - Final class, public readonly tenant/bootstrappers — updated from stub
- `src/Event/TenantContextCleared.php` - Final signal-only class, no constructor, no properties
- `tests/Unit/Event/TenantResolvedTest.php` - 3 tests: payload, nullable request, readonly enforcement
- `tests/Unit/Event/TenantBootstrappedTest.php` - 2 tests: FQCN array correctness, empty bootstrappers
- `tests/Unit/Event/TenantContextClearedTest.php` - 2 tests: instantiation, zero public properties
- `tests/Unit/Bootstrapper/BootstrapperChainTest.php` - Added 2 tests: event dispatch with FQCNs, empty-chain dispatch

## Decisions Made

- Updated `TenantBootstrapped` stub from private readonly + getter methods to public readonly promoted properties — the plan spec (`public readonly TenantInterface $tenant`) is explicit and PHPUnit tests access `$event->tenant` directly
- `TenantContextCleared` implemented as an empty final class body (no constructor) — cleanest signal-only pattern; passes `testHasNoPublicProperties` reflection check
- Used `willReturnCallback` with reference capture for dispatch assertions — PHPUnit mocks generate unpredictable class names for mock objects, so FQCNs must be captured at call time

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Updated TenantBootstrapped from private readonly + getters to public readonly**
- **Found during:** Task 1 (reading existing stub before creating event classes)
- **Issue:** Existing stub in `src/Event/TenantBootstrapped.php` used private readonly properties with getter methods (`getTenant()`, `getBootstrappers()`). Plan spec requires `public readonly TenantInterface $tenant` and `public readonly array $bootstrappers` — tests in Task 2 access properties directly via `$event->tenant` and `$event->bootstrappers`
- **Fix:** Rewrote `TenantBootstrapped` with public readonly promoted constructor properties; removed getter methods
- **Files modified:** `src/Event/TenantBootstrapped.php`
- **Verification:** `php -l` passed; all unit tests green (28/28)
- **Committed in:** `f2b087e` (Task 1 commit)

---

**Total deviations:** 1 auto-fixed (Rule 1 — bug in existing stub)
**Impact on plan:** Auto-fix necessary for correctness — public readonly access is the specified API contract. No scope creep.

## Issues Encountered

None — plan executed smoothly after stub correction.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- All three lifecycle event classes available for dispatch in `TenantContextOrchestrator` (Phase 1 Plan 05)
- `BootstrapperChain::boot()` now fully tested including event dispatch with FQCN list
- 28 unit tests passing — full unit suite green
- Plan 01-04 (Tenant entity) and Plan 01-05 (Orchestrator) are unblocked

---
*Phase: 01-core-foundation*
*Completed: 2026-03-18*
