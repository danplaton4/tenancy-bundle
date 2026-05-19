---
phase: 03-database-per-tenant-driver
plan: 05
subsystem: testing
tags: [doctrine, orm, dbal, sqlite, integration-tests, tenant-isolation, entity-manager]

# Dependency graph
requires:
  - phase: 03-01
    provides: TenantConnection.switchTenant() and reset() for DBAL-level switching
  - phase: 03-02
    provides: DatabaseSwitchBootstrapper wiring TenantDriverInterface to TenantConnection
  - phase: 03-03
    provides: EntityManagerResetListener resetting tenant EM on TenantContextCleared
  - phase: 03-04
    provides: TDD-proven EntityManagerResetListener calling resetManager('tenant')
provides:
  - DoctrineTestKernel: dual-EM test kernel (landlord + tenant) with file-based SQLite
  - TestProduct: Doctrine entity mapped to tenant EM for isolation tests
  - MakeDatabaseServicesPublicPass: exposes doctrine/tenancy services in test container
  - DatabaseSwitchIntegrationTest: cross-tenant query isolation confirmed via 4 tests
  - EntityManagerResetIntegrationTest: EM reset behavior confirmed via 3 tests
affects: [future phases using dual-EM kernel pattern, phase 04+]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "DoctrineTestKernel pattern: dual-EM kernel with file-based SQLite for Doctrine integration tests"
    - "setUpBeforeClass deletes leftover DB files before booting kernel to prevent table-exists errors on re-runs"
    - "resetManager freshness verified via UoW spl_object_id (not proxy object_id) under DoctrineBundle 2.x"

key-files:
  created:
    - tests/Integration/Support/DoctrineTestKernel.php
    - tests/Integration/Support/Entity/TestProduct.php
    - tests/Integration/Support/MakeDatabaseServicesPublicPass.php
    - tests/Integration/DatabaseSwitchIntegrationTest.php
    - tests/Integration/EntityManagerResetIntegrationTest.php
  modified: []

key-decisions:
  - "DoctrineBundle 2.x wraps EMs in lazy service proxies — spl_object_id($em) stays the same after resetManager(); freshness proven via UoW instance identity instead"
  - "setUpBeforeClass deletes the shared landlord DB file before booting kernel; both test classes use the same fixed path so stale files would cause table-exists on second run"
  - "DoctrineTestKernel uses MakeDatabaseServicesPublicPass not ReplaceTenancyProviderPass for tenancy.provider — database.enabled mode rewires provider to landlord EM (DoctrineTenantProvider, not Null)"

patterns-established:
  - "Dual-EM test kernel pattern: register DoctrineBundle + landlord/tenant connections, use MakeDatabaseServicesPublicPass to expose services"
  - "Tenant DB isolation test pattern: switchTenant() → resetManager('tenant') → assert row counts per DB file"

requirements-completed: [ISOL-01, ISOL-02]

# Metrics
duration: 4min
completed: 2026-03-19
---

# Phase 03 Plan 05: Integration Tests for Dual-EM Tenant Isolation Summary

**Dual-EM integration test suite with file-based SQLite proving ISOL-01 and ISOL-02: tenant A data invisible in tenant B context, landlord EM unaffected, TenantContextCleared resets tenant EM only**

## Performance

- **Duration:** 4 min
- **Started:** 2026-03-19T06:21:02Z
- **Completed:** 2026-03-19T06:25:24Z
- **Tasks:** 2
- **Files modified:** 5

## Accomplishments
- DoctrineTestKernel boots with dual-EM (landlord + tenant) using file-based SQLite databases, `database.enabled: true` wires DatabaseSwitchBootstrapper and EntityManagerResetListener
- DatabaseSwitchIntegrationTest (4 tests) confirms: queries hit correct tenant DB, tenant B cannot see tenant A data, landlord EM reads from central DB across any number of tenant switches, re-switching to same DB file reconnects correctly
- EntityManagerResetIntegrationTest (3 tests) confirms: resetManager produces a fresh UoW (DoctrineBundle 2.x proxy-aware assertion), TenantContextCleared triggers tenant EM reset with empty identity map, landlord EM not reset by event

## Task Commits

Each task was committed atomically:

1. **Task 1: DoctrineTestKernel, TestProduct entity, MakeDatabaseServicesPublicPass** - `a7e7a63` (feat)
2. **Task 2: Integration tests for cross-tenant query isolation and EM reset** - `35a4e51` (feat)

**Plan metadata:** (docs commit to follow)

## Files Created/Modified
- `tests/Integration/Support/DoctrineTestKernel.php` - Dual-EM kernel with landlord + tenant SQLite connections and tenancy.database.enabled
- `tests/Integration/Support/Entity/TestProduct.php` - Simple ORM entity mapped to tenant EM (test_products table)
- `tests/Integration/Support/MakeDatabaseServicesPublicPass.php` - Makes doctrine.dbal.tenant_connection, EM services, tenancy services public for container inspection
- `tests/Integration/DatabaseSwitchIntegrationTest.php` - 4 integration tests for cross-tenant query isolation
- `tests/Integration/EntityManagerResetIntegrationTest.php` - 3 integration tests for EM reset and identity map teardown

## Decisions Made

- **DoctrineBundle 2.x lazy proxy vs spl_object_id:** DoctrineBundle 2.x wraps the EM in a lazy service proxy. The proxy wrapper object has the same `spl_object_id` before and after `resetManager()`. Freshness is correctly detected by comparing `spl_object_id($em->getUnitOfWork())` — the inner `UnitOfWork` is recreated by the new `EntityManager` under the proxy.
- **Shared landlord DB cleanup:** Both test classes configure the kernel with the same fixed `tenancy_test_landlord.db` path (hardcoded in DoctrineTestKernel). The `setUpBeforeClass` must delete this file _before_ booting the kernel to avoid `table already exists` errors on repeat runs.
- **ReplaceTenancyProviderPass not needed for database.enabled mode:** When `database.enabled: true`, `TenancyBundle::loadExtension` rewires `tenancy.provider` to use `doctrine.orm.landlord_entity_manager` (the real DoctrineTenantProvider). The DoctrineTestKernel still adds `ReplaceTenancyProviderPass` to replace it with NullTenantProvider, avoiding actual DB lookups in tenant resolution paths during these tests.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] UoW-based freshness assertion replaces spl_object_id proxy check**
- **Found during:** Task 2 (EntityManagerResetIntegrationTest)
- **Issue:** Plan specified `spl_object_id($em)` to verify fresh EM instance. DoctrineBundle 2.x returns the same lazy proxy wrapper before and after `resetManager()` — spl_object_id never changes.
- **Fix:** Replaced with `spl_object_id($em->getUnitOfWork())` — the inner `UnitOfWork` is a new object when a fresh EntityManager is constructed, making it a reliable freshness indicator.
- **Files modified:** tests/Integration/EntityManagerResetIntegrationTest.php
- **Verification:** Test passes with clear before/after UoW id difference confirmed
- **Committed in:** 35a4e51 (Task 2 commit)

**2. [Rule 1 - Bug] Delete landlord DB file before kernel boot to prevent table-exists on re-runs**
- **Found during:** Task 2 (DatabaseSwitchIntegrationTest / EntityManagerResetIntegrationTest)
- **Issue:** Both test classes share the same `tenancy_test_landlord.db` path (fixed in DoctrineTestKernel). If a previous test run left the file, `SchemaTool::createSchema` would throw `table tenancy_tenants already exists`.
- **Fix:** In `setUpBeforeClass`, delete the landlord DB file (and placeholder DB) before calling `static::$kernel->boot()`.
- **Files modified:** tests/Integration/DatabaseSwitchIntegrationTest.php, tests/Integration/EntityManagerResetIntegrationTest.php
- **Verification:** Tests pass on second consecutive run (no stale-file error)
- **Committed in:** 35a4e51 (Task 2 commit)

---

**Total deviations:** 2 auto-fixed (2 × Rule 1 - Bug)
**Impact on plan:** Both fixes required for test correctness on DoctrineBundle 2.x and repeatable CI runs. No scope creep.

## Issues Encountered
- Pre-existing failures: `ListenerPriorityTest` (2 tests) fail with `ArgumentCountError` for `TenantContextOrchestrator` — stale compiled container cache. These failures predate this plan and are unrelated to the changes here.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Phase 03 complete: ISOL-01 and ISOL-02 proven by integration tests
- DoctrineTestKernel available as reusable dual-EM kernel for Phase 4+ Doctrine integration tests
- `ListenerPriorityTest` stale container cache is a pre-existing issue to address in a future cleanup

---
*Phase: 03-database-per-tenant-driver*
*Completed: 2026-03-19*
