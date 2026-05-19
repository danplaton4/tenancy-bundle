---
phase: 04-shared-db-driver
plan: "01"
subsystem: database
tags: [doctrine, sql-filter, attribute, tenant-isolation, shared-db]

# Dependency graph
requires:
  - phase: 01-core-foundation
    provides: TenantContext (hasTenant/getTenant), TenantInterface (getSlug)
  - phase: 03-database-per-tenant-driver
    provides: TenantDriverInterface marker interface pattern

provides:
  - TenantAware PHP attribute (TARGET_CLASS marker for Doctrine entity scoping)
  - TenantMissingException (RuntimeException with entity class name in message)
  - TenantAwareFilter (SQLFilter with 4 branches + uninitialized guard)

affects:
  - 04-02 (SharedDriver registers filter in DI, calls setTenantContext after boot)
  - 04-03 (integration tests exercise the filter end-to-end)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - Doctrine SQLFilter with setter injection instead of constructor injection (final constructor limitation)
    - Null guard pattern for uninitialized SQLFilter (returns '' before boot completes)
    - addslashes() for SQL safety on controlled slug values (defense-in-depth)
    - ReflectionClass::getAttributes() for attribute detection without instantiation

key-files:
  created:
    - src/Attribute/TenantAware.php
    - src/Exception/TenantMissingException.php
    - src/Filter/TenantAwareFilter.php
    - tests/Unit/Attribute/TenantAwareTest.php
    - tests/Unit/Exception/TenantMissingExceptionTest.php
    - tests/Unit/Filter/TenantAwareFilterTest.php
  modified: []

key-decisions:
  - "TenantAwareFilter uses setter injection (setTenantContext) not constructor injection — SQLFilter has a final constructor taking EntityManagerInterface only"
  - "TenantMissingException does NOT implement HttpExceptionInterface — it propagates from Doctrine query internals, not from the HTTP layer; differs from TenantNotFoundException"
  - "Uninitialized guard: tenantContext === null returns '' silently — prevents crashes when filter is enabled before SharedDriver::boot() in console commands or early-boot scenarios"
  - "addslashes() on tenant slug — slug is a controlled VARCHAR(63) value but defense-in-depth prevents SQL injection if slug validation ever weakens"
  - "reflClass null check before getAttributes() — ClassMetadata.reflClass is public but can be null for virtual/mapped-superclass metadata"

patterns-established:
  - "SQLFilter with null-guarded setter injection: setTenantContext(TenantContext, bool strictMode) called post-boot"
  - "TenantAware attribute on root entity only in STI/JTI hierarchies (Doctrine addFilterConstraint receives parent metadata)"

requirements-completed: [ISOL-03, ISOL-04, ISOL-05]

# Metrics
duration: 3min
completed: 2026-03-19
---

# Phase 04 Plan 01: TenantAware Attribute, TenantMissingException, and TenantAwareFilter Summary

**Doctrine SQLFilter `TenantAwareFilter` with 4-branch query interception, `#[TenantAware]` marker attribute, and `TenantMissingException` — the foundational types for shared-DB tenant isolation**

## Performance

- **Duration:** 3 min
- **Started:** 2026-03-19T08:08:33Z
- **Completed:** 2026-03-19T08:11:13Z
- **Tasks:** 2
- **Files modified:** 6

## Accomplishments

- `#[TenantAware]` pure marker attribute with `TARGET_CLASS` flag for Doctrine entity scoping
- `TenantMissingException` extending `RuntimeException` with entity class name in message (no HttpExceptionInterface — internal Doctrine exception)
- `TenantAwareFilter` implementing all 4 interception branches: no-attribute skip, tenant-active WHERE fragment, strict-mode throw, permissive-mode skip
- Uninitialized guard returns `''` when `setTenantContext` never called (safe for pre-boot console commands)
- 13 unit tests across 3 test files, all green

## Task Commits

Each task was committed atomically via TDD (RED then GREEN):

1. **Task 1 RED: TenantAware + TenantMissingException failing tests** - `f159c5f` (test)
2. **Task 1 GREEN: TenantAware + TenantMissingException production classes** - `b5d5800` (feat)
3. **Task 2 RED: TenantAwareFilter failing tests (7 branches)** - `6d5637b` (test)
4. **Task 2 GREEN: TenantAwareFilter production class** - `dfb46e8` (feat)

_TDD tasks have test commit followed by implementation commit_

## Files Created/Modified

- `src/Attribute/TenantAware.php` - Pure marker attribute with `#[\Attribute(\Attribute::TARGET_CLASS)]`
- `src/Exception/TenantMissingException.php` - RuntimeException with entity class name in sprintf message
- `src/Filter/TenantAwareFilter.php` - SQLFilter with setter injection, 4 branches, addslashes safety
- `tests/Unit/Attribute/TenantAwareTest.php` - TARGET_CLASS flag and instantiation tests
- `tests/Unit/Exception/TenantMissingExceptionTest.php` - RuntimeException inheritance and message format tests
- `tests/Unit/Filter/TenantAwareFilterTest.php` - All 7 filter branches tested with real EntityManager

## Decisions Made

- **Setter injection over constructor injection:** `SQLFilter::__construct()` is `final` and only accepts `EntityManagerInterface`. Tenant context must be injected post-construction via `setTenantContext(TenantContext, bool)`.
- **TenantMissingException is not HTTP-aware:** Unlike `TenantNotFoundException`, this exception originates inside Doctrine's query machinery. It should not carry HTTP status codes — callers in the HTTP layer may catch and convert it.
- **Null guard for uninitialized state:** Filter returning `''` when `tenantContext === null` prevents fatal errors in console commands that run before `SharedDriver::boot()`. Stricter alternatives (throw on null) would break valid pre-boot scenarios.
- **addslashes on slug:** Slug is a controlled `VARCHAR(63)` alphanumeric+dash value, but addslashes provides defense-in-depth against future slug validation regressions.

## Deviations from Plan

None — plan executed exactly as written.

## Issues Encountered

Pre-existing test failures in `ListenerPriorityTest` (2 errors, `TenantContextOrchestrator` constructor arity mismatch) were present before this plan. They are unrelated to the current work and out of scope. Documented in `deferred-items.md` below.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- `TenantAwareFilter`, `TenantAware`, and `TenantMissingException` are ready for Plan 02 (SharedDriver DI wiring)
- Plan 02 will register the filter in Doctrine configuration and call `setTenantContext()` from `SharedDriver::boot()`
- Plan 03 will use `#[TenantAware]` on test entities to exercise the full filter pipeline

---
*Phase: 04-shared-db-driver*
*Completed: 2026-03-19*

## Self-Check: PASSED

All created files verified present. All task commits verified in git log.
