---
phase: 08-developer-experience
plan: 01
subsystem: testing
tags: [phpunit, trait, symfony-kernel, doctrine, sqlite, tenant-context]

# Dependency graph
requires:
  - phase: 03-database-per-tenant-driver
    provides: TenantConnection wrapperClass for DBAL connection swap at runtime
  - phase: 05-infrastructure-bootstrappers
    provides: BootstrapperChain boot/clear, DoctrineBootstrapper, TenantAwareCacheAdapter
  - phase: 01-core-foundation
    provides: TenantContext, BootstrapperChain, TenantInterface, Tenant entity
provides:
  - InteractsWithTenancy PHPUnit trait with initializeTenant, clearTenant, tearDown, assertTenantActive, assertNoTenant, getTenantService
  - TenancyTestKernel: database-per-tenant mode test kernel for InteractsWithTenancy test suite
  - MakeTenancyTestServicesPublicPass: compiler pass exposing all tenancy and Doctrine services for test container
affects: [future test classes using InteractsWithTenancy, 08-02-profiler]

# Tech tracking
tech-stack:
  added: []
  patterns: [KernelTestCase-trait pattern for tenant isolation in integration tests, :memory: SQLite per-test-method via TenantConnection.switchTenant]

key-files:
  created:
    - src/Testing/InteractsWithTenancy.php
    - tests/Integration/Testing/Support/TenancyTestKernel.php
    - tests/Integration/Testing/Support/MakeTenancyTestServicesPublicPass.php
  modified: []

key-decisions:
  - "InteractsWithTenancy calls tenantContext.clear() + chain.clear() first in initializeTenant() to allow re-use across multiple calls in one test"
  - "switchTenant(['driver' => 'pdo_sqlite', 'memory' => true]) uses DBAL 4 canonical in-memory SQLite syntax"
  - "TenancyTestKernel uses 'tenancy_test' as default env to prevent cache dir collisions with DoctrineTestKernel (env=test)"
  - "clearTenant() guards on hasTenant() before calling chain.clear() — avoids running bootstrapper reverse sequence when no tenant was initialized"
  - "tearDown() calls clearTenant() before parent::tearDown() to ensure container is available during cleanup"

patterns-established:
  - "Tenant-aware test isolation: initializeTenant() creates fresh :memory: SQLite per call, tearDown() auto-cleans"
  - "Compiler pass pattern for test containers: iterate IDs, check hasDefinition/hasAlias, setPublic(true)"
  - "TenancyTestKernel reuses ReplaceTenancyProviderPass from tests/Integration/Support — no duplicate"

requirements-completed: [DX-01]

# Metrics
duration: 6min
completed: 2026-04-02
---

# Phase 08 Plan 01: InteractsWithTenancy PHPUnit Trait Summary

**InteractsWithTenancy trait with 6-method DX surface plus TenancyTestKernel database-per-tenant mode kernel and MakeTenancyTestServicesPublicPass for test container access**

## Performance

- **Duration:** 6 min
- **Started:** 2026-04-02T06:28:02Z
- **Completed:** 2026-04-02T06:34:00Z
- **Tasks:** 2
- **Files modified:** 3

## Accomplishments
- `InteractsWithTenancy` trait delivering single-method DX (`$this->initializeTenant('acme')`) with auto-cleanup in `tearDown()`, assertion helpers, and typed service accessor
- `TenancyTestKernel` boots in database-per-tenant mode with `TenantConnection` wrapperClass, `tenancy_test` isolation environment, reuses existing `TestProduct` entity and `ReplaceTenancyProviderPass`
- `MakeTenancyTestServicesPublicPass` exposes all 7 tenancy/Doctrine services needed by the trait for test container retrieval

## Task Commits

Each task was committed atomically:

1. **Task 1: Create TenancyTestKernel and MakeTenancyTestServicesPublicPass** - `840527e` (feat)
2. **Task 2: Create InteractsWithTenancy trait** - `ba9864f` (feat)

**Plan metadata:** _(docs commit follows)_

## Files Created/Modified
- `src/Testing/InteractsWithTenancy.php` — PHPUnit trait: initializeTenant, clearTenant, tearDown, assertTenantActive, assertNoTenant, getTenantService
- `tests/Integration/Testing/Support/TenancyTestKernel.php` — Database-mode kernel for trait test suite with tenancy_test env isolation
- `tests/Integration/Testing/Support/MakeTenancyTestServicesPublicPass.php` — Compiler pass exposing tenancy.context, tenancy.bootstrapper_chain, doctrine.dbal.tenant_connection and 4 more services

## Decisions Made
- `initializeTenant()` uses DBAL 4 canonical `['memory' => true]` syntax for in-memory SQLite (not `'path' => ':memory:'`)
- `clearTenant()` guards on `hasTenant()` before `chain->clear()` to avoid reverse-running bootstrappers when no tenant was set
- `TenancyTestKernel` uses `'tenancy_test'` as default env to avoid cache dir collisions with `DoctrineTestKernel` (env=`test`)
- `tearDown()` calls `clearTenant()` before `parent::tearDown()` so the container is still available during cleanup

## Deviations from Plan

None — plan executed exactly as written.

## Issues Encountered

Pre-existing test suite errors (17 errors from prior phases) confirmed unrelated to this plan. No new regressions introduced:
- `TenantAwareFilterTest` errors: `symfony/var-exporter` missing
- Various integration test errors: `doctrine.dbal.tenant_connection` missing in non-database-mode kernels

These are out-of-scope pre-existing issues, logged to deferred items.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness
- `InteractsWithTenancy` is ready for use in Phase 08-02 (profiler) test classes if needed
- Downstream projects can use `TenancyTestKernel` + `InteractsWithTenancy` for tenant-isolated integration tests
- No blockers for next plan (08-02)

## Self-Check: PASSED

- FOUND: src/Testing/InteractsWithTenancy.php
- FOUND: tests/Integration/Testing/Support/TenancyTestKernel.php
- FOUND: tests/Integration/Testing/Support/MakeTenancyTestServicesPublicPass.php
- FOUND: .planning/phases/08-developer-experience/08-01-SUMMARY.md
- FOUND commit: 840527e (TenancyTestKernel + MakeTenancyTestServicesPublicPass)
- FOUND commit: ba9864f (InteractsWithTenancy trait)

---
*Phase: 08-developer-experience*
*Completed: 2026-04-02*
