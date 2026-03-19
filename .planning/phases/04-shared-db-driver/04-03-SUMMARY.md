---
phase: 04-shared-db-driver
plan: "03"
subsystem: testing
tags: [doctrine, sql-filter, sqlite, phpunit, integration-test, shared-db, tenant-isolation]

requires:
  - phase: 04-01
    provides: TenantAwareFilter with setTenantContext setter-injection and addFilterConstraint
  - phase: 04-02
    provides: SharedDriver::boot() injecting TenantContext into filter; TenancyBundle prependExtension wiring tenancy_aware filter

provides:
  - SharedDbTestKernel: single-EM Symfony kernel with tenancy.driver=shared_db and strict_mode=true
  - TestTenantProduct: #[TenantAware] entity with explicit tenant_id column for filter scoping tests
  - MakeSharedDbServicesPublicPass: exposes doctrine.orm.default_entity_manager, tenancy.context, tenancy.shared_driver for test access
  - SharedDbFilterIntegrationTest: 5 end-to-end tests proving full shared-DB stack correctness

affects: [phase-05, phase-09]

tech-stack:
  added: []
  patterns:
    - "SharedDbTestKernel: single-EM kernel with shared_db driver — contrast with DoctrineTestKernel dual-EM pattern"
    - "DBAL insert for seed data to bypass Doctrine filter; ORM findAll/DQL to exercise filter"
    - "TenantContext::clear() in setUp() resets tenant between tests; $em->clear() resets identity map"
    - "Strict mode test: inject TenantContext (no tenant) directly into filter via setTenantContext, then query"

key-files:
  created:
    - tests/Integration/Support/SharedDbTestKernel.php
    - tests/Integration/Support/MakeSharedDbServicesPublicPass.php
    - tests/Integration/Support/Entity/TestTenantProduct.php
    - tests/Integration/SharedDbFilterIntegrationTest.php
  modified: []

key-decisions:
  - "TestTenantProduct uses explicit #[ORM\\Column(name: 'tenant_id')] to avoid camelCase-to-underscore naming ambiguity with SQLite"
  - "Seed data inserted via DBAL (bypasses filter) so rows from both tenants exist before test queries run"
  - "Strict mode test calls setTenantContext directly on the filter (no SharedDriver::boot()) so no tenant stub is needed"
  - "Pre-existing ListenerPriorityTest failures (ArgumentCountError in TenantContextOrchestrator) are out-of-scope — not caused by this plan"

patterns-established:
  - "SharedDbTestKernel: model for single-connection single-EM test kernels with shared_db driver"
  - "MakeSharedDbServicesPublicPass: pattern for exposing shared_db services in integration tests"

requirements-completed: [ISOL-03, ISOL-04, ISOL-05]

duration: 8min
completed: 2026-03-19
---

# Phase 04 Plan 03: Shared-DB Filter Integration Tests Summary

**End-to-end SQLite integration tests proving TenantAwareFilter scopes queries by tenant_id, non-TenantAware entities are unaffected, and strict mode throws TenantMissingException — 5 tests, 12 assertions, all green**

## Performance

- **Duration:** ~8 min
- **Started:** 2026-03-19T08:21:53Z
- **Completed:** 2026-03-19T08:29:38Z
- **Tasks:** 2
- **Files modified:** 4 created

## Accomplishments

- SharedDbTestKernel boots a single-EM Symfony kernel with `tenancy.driver: shared_db` and `strict_mode: true` against a file-based SQLite DB
- TestTenantProduct is a `#[TenantAware]` entity with an explicit `tenant_id` VARCHAR(63) column, proving the attribute/filter pipeline end-to-end
- SharedDbFilterIntegrationTest covers: per-tenant scoping (2 acme rows, not globex), tenant switching (acme→globex changes result set), non-TenantAware passthrough (TestProduct unfiltered), strict mode exception, and DQL filter scoping
- All 5 integration tests pass; new SharedDbFilterIntegrationTest suite runs independently without affecting other test classes

## Task Commits

1. **Task 1: SharedDbTestKernel, TestTenantProduct, MakeSharedDbServicesPublicPass** - `96c3824` (feat)
2. **Task 2: SharedDbFilterIntegrationTest** - `6f9eced` (feat)

**Plan metadata:** (docs commit follows)

## Files Created/Modified

- `tests/Integration/Support/SharedDbTestKernel.php` - Single-EM kernel with shared_db driver config and SQLite connection
- `tests/Integration/Support/MakeSharedDbServicesPublicPass.php` - Compiler pass exposing doctrine.orm.default_entity_manager, tenancy.context, tenancy.shared_driver
- `tests/Integration/Support/Entity/TestTenantProduct.php` - #[TenantAware] entity with explicit tenant_id column
- `tests/Integration/SharedDbFilterIntegrationTest.php` - 5 end-to-end filter scoping integration tests

## Decisions Made

- Used explicit `#[ORM\Column(name: 'tenant_id')]` on TestTenantProduct to guarantee the column name matches what TenantAwareFilter expects, regardless of Doctrine naming strategy
- Seed data inserted via raw DBAL `insert()` (bypasses the filter) so rows from both 'acme' and 'globex' tenants exist before test queries run
- Strict mode test injects TenantContext directly into the filter via `setTenantContext($ctx, true)` (no SharedDriver::boot needed) — cleaner isolation for the "no tenant" scenario
- `TenantContext::clear()` + `$em->clear()` in `setUp()` ensures each test starts from a clean state without re-booting the kernel

## Deviations from Plan

None — plan executed exactly as written.

Pre-existing failures (out of scope, not caused by this plan):
- `ListenerPriorityTest::testOrchestratorRegisteredAtPriority20OnKernelRequest` and `testOrchestratorRegisteredOnKernelTerminate` fail with ArgumentCountError (TenantContextOrchestrator constructor mismatch in cached container). These existed before this plan and are unrelated to shared-DB work.

## Issues Encountered

None — the kernel booted cleanly, schema creation succeeded, and all 5 tests passed on first run.

## Next Phase Readiness

- Full shared-DB stack verified: attribute + filter + driver + bundle wiring work end-to-end with real Doctrine queries
- Phase 04 (shared-db-driver) is complete — all 3 plans done
- Ready for Phase 05 (cache-driver) or whatever phase comes next in the roadmap

---
*Phase: 04-shared-db-driver*
*Completed: 2026-03-19*
