---
phase: 25-shared-entities-sync-mode
plan: "00"
subsystem: testing
tags: [phpunit, doctrine-orm, sqlite, shared-entity, wave-0, tdd-scaffold]

requires:
  - phase: 03-database-per-tenant-driver
    provides: dual-EM landlord+tenant SQLite kernel pattern (DoctrineTestKernel)
  - phase: 04-shared-db-driver
    provides: shared_db kernel pattern (SharedDbTestKernel)

provides:
  - SharedEntitySyncTestKernel — landlord + 2-tenant SQLite kernel (database.enabled: true)
  - StubMultiTenantProvider — 2 deterministic test tenants with file-based SQLite configs
  - ReplaceWithStubMultiTenantProviderPass — swaps tenancy.provider to stub in test kernels
  - MakeSharedEntityServicesPublicPass — makes shared-entity services public, tolerates absent Wave-3 services
  - SharedEntityNoDbTestKernel — shared_db driver kernel for SHARE-01-j no-op test
  - TestPlan — #[Shared] entity with scalar fields (name, priceCents)
  - TestPlanCategory — plain non-shared entity (ManyToOne association target)
  - TestPlanWithAssociation — #[Shared] entity with ManyToOne (cascade depth=1 boundary test)
  - SharedTest — unit test scaffold for SHARE-01-a (attribute target-class assertion)
  - SharedEntityMutualExclusionPassTest — unit test scaffold for SHARE-01-l (mutual exclusion guard)
  - SharedEntitySyncIntegrationTest — integration test scaffold for SHARE-01-b..m (10 methods)
  - SharedEntityNoDatabaseKernelTest — integration test for SHARE-01-j (no-op under shared_db)

affects:
  - 25-01 (Shared attribute — activates SharedTest + fixture classes)
  - 25-02 (MutualExclusionPass — activates SharedEntityMutualExclusionPassTest)
  - 25-03 (write-protection exception — activates testTenantSidePersistThrows/UpdateThrows/DeleteThrows)
  - 25-04 (sync subscriber — activates all fan-out + wiring tests)

tech-stack:
  added: []
  patterns:
    - "Wave 0 test scaffold: skip-guard pattern (class_exists + markTestSkipped) for tests referencing not-yet-built production classes — keeps hook green while leaving tests discoverable via --filter"
    - "$conn->close() + resetManager('tenant') before SchemaTool::createSchema() for multi-tenant SQLite setup — mirrors DatabasePerTenantMiddlewareIntegrationTest"
    - "Kernel-level StubMultiTenantProvider with static getTenantAPath()/getTenantBPath() factories for test reuse across setup and per-test assertions"
    - "MakeSharedEntityServicesPublicPass: hasDefinition() / hasAlias() guards per service so pass is safe to run before Wave-3 services exist"

key-files:
  created:
    - tests/Integration/SharedEntity/Support/SharedEntitySyncTestKernel.php
    - tests/Integration/SharedEntity/Support/StubMultiTenantProvider.php
    - tests/Integration/SharedEntity/Support/ReplaceWithStubMultiTenantProviderPass.php
    - tests/Integration/SharedEntity/Support/MakeSharedEntityServicesPublicPass.php
    - tests/Integration/SharedEntity/Support/SharedEntityNoDbTestKernel.php
    - tests/Integration/SharedEntity/Support/Entity/TestPlan.php
    - tests/Integration/SharedEntity/Support/Entity/TestPlanCategory.php
    - tests/Integration/SharedEntity/Support/Entity/TestPlanWithAssociation.php
    - tests/Integration/SharedEntity/SharedEntitySyncIntegrationTest.php
    - tests/Integration/SharedEntity/SharedEntityNoDatabaseKernelTest.php
    - tests/Unit/Attribute/SharedTest.php
    - tests/Unit/DependencyInjection/Compiler/SharedEntityMutualExclusionPassTest.php
  modified: []

key-decisions:
  - "Wave 0 skip-guard pattern: tests referencing missing production classes use class_exists() + markTestSkipped() in setUp() so the pre-commit hook stays green while methods remain discoverable via --filter"
  - "Landlord EM maps BOTH src/Entity AND Support/Entity so TestPlan is persistable on the landlord for fan-out tests"
  - "SharedEntityNoDbTestKernel created as a dedicated kernel for SHARE-01-j (separate from SharedEntitySyncTestKernel) — shared_db driver cannot coexist with database.enabled: true in the same kernel"
  - "ReplaceWithStubMultiTenantProviderPass added alongside MakeSharedEntityServicesPublicPass — separates provider replacement from service visibility concerns"
  - "testNoOpUnderSharedDb passes in Wave 0 (trivially — no subscriber = no fan-out) and continues to pass in Wave 4 via the D-03 short-circuit"

patterns-established:
  - "skip-guard: class_exists() + markTestSkipped() in setUp() for Wave N test referencing Wave M (M > N) production class"
  - "dual-kernel approach: SharedEntitySyncTestKernel (database.enabled + landlord+tenant EMs) vs SharedEntityNoDbTestKernel (shared_db + single default EM)"

requirements-completed: [SHARE-01]

duration: 18min
completed: "2026-06-11"
---

# Phase 25 Plan 00: Wave 0 Test Infrastructure Summary

**Landlord + 2-tenant SQLite test kernel, 3 #[Shared] test entities, stub provider, and 12 named SHARE-01-a..m test methods (skip-guarded RED scaffold) for the shared-entity sync system**

## Performance

- **Duration:** ~18 min
- **Started:** 2026-06-11T07:43:00Z
- **Completed:** 2026-06-11T08:01:27Z
- **Tasks:** 2
- **Files modified:** 12 created, 0 modified

## Accomplishments

- Landlord + 2-tenant SQLite integration kernel (`SharedEntitySyncTestKernel`) with `database.enabled: true`, dual EM mappings, and both stub passes registered
- `StubMultiTenantProvider` returning exactly 2 test tenants with deterministic file paths (`getTenantAPath()`/`getTenantBPath()` factory methods for test reuse)
- 3 test entities: `TestPlan` (#[Shared] with scalar fields), `TestPlanCategory` (plain, association target), `TestPlanWithAssociation` (#[Shared] with ManyToOne — SHARE-01-m cascade test)
- 12 named test methods covering all SHARE-01-a..m behaviors; all 15 new Wave 0 tests skip gracefully via `class_exists()` guards until production classes land in Plans 25-01..25-04
- 705 tests pass (16 skipped including 15 new Wave 0 tests); `testNoOpUnderSharedDb` passes immediately in Wave 0

## RED Test Inventory

The following tests skip gracefully until the production class arrives:

| Test Method | Behavior | Waiting For | Plan |
|-------------|----------|-------------|------|
| `testSharedAttributeIsClassTarget` | SHARE-01-a | `Shared` attribute class | 25-01 |
| `testSharedAttributeCanBeInstantiated` | SHARE-01-a | `Shared` attribute class | 25-01 |
| `testMutualExclusionGuardThrows` | SHARE-01-l | `SharedEntityMutualExclusionPass` | 25-02 |
| `testNoExceptionWhenOnlySharedPresent` | SHARE-01-l | `SharedEntityMutualExclusionPass` | 25-02 |
| `testUntaggedClassIsIgnored` | SHARE-01-l | `SharedEntityMutualExclusionPass` | 25-02 |
| `testTenantSidePersistThrows` | SHARE-01-f | `SharedEntityWriteInTenantContextException` | 25-03 |
| `testTenantSideUpdateThrows` | SHARE-01-g | `SharedEntityWriteInTenantContextException` | 25-03 |
| `testTenantSideDeleteThrows` | SHARE-01-h | `SharedEntityWriteInTenantContextException` | 25-03 |
| `testSubscriberWiredToLandlordEm` | SHARE-01-b | `SharedEntitySyncSubscriber` | 25-04 |
| `testInsertFansOutToAllTenants` | SHARE-01-c | `SharedEntitySyncSubscriber` | 25-04 |
| `testUpdateFansOutToAllTenants` | SHARE-01-d | `SharedEntitySyncSubscriber` | 25-04 |
| `testDeleteFansOutToAllTenants` | SHARE-01-e | `SharedEntitySyncSubscriber` | 25-04 |
| `testSyncWriteBypassesWriteProtection` | SHARE-01-i | `SharedEntitySyncSubscriber` | 25-04 |
| `testPerTenantFailureIsLogged` | SHARE-01-k | `SharedEntitySyncSubscriber` | 25-04 |
| `testAssociationsNotSynced` | SHARE-01-m | `SharedEntitySyncSubscriber` | 25-04 |

**Passing in Wave 0 (trivially, expected):**

| Test Method | Behavior | Why Passes |
|-------------|----------|------------|
| `testNoOpUnderSharedDb` | SHARE-01-j | No subscriber = no fan-out = correct no-op behavior |

## Database File Paths

The three SQLite files used by the integration kernel:

| File | Purpose | Connection |
|------|---------|------------|
| `{sys_get_temp_dir()}/tenancy_shared_test_landlord.db` | Landlord EM (default_connection) | `landlord` |
| `{sys_get_temp_dir()}/tenancy_shared_test_tenant_a.db` | Tenant A per-tenant DB (via getConnectionConfig) | `tenant` (switched) |
| `{sys_get_temp_dir()}/tenancy_shared_test_tenant_b.db` | Tenant B per-tenant DB (via getConnectionConfig) | `tenant` (switched) |
| `{sys_get_temp_dir()}/tenancy_shared_test_placeholder.db` | Placeholder (kernel boot) | `tenant` (initial) |

## Container Tag

The mutual-exclusion pass test (`SharedEntityMutualExclusionPassTest`) expects entities to be tagged with:

```
tenancy.shared_entity
```

This matches the tag name established in Plan 25-PATTERNS.md and the research (Option A from RESEARCH.md §Pattern 7). Plan 25-02 implements `SharedEntityMutualExclusionPass::process()` walking `$container->findTaggedServiceIds('tenancy.shared_entity')`.

## Task Commits

1. **Task 1: Multi-EM kernel + stub provider + pass + entities** - `eca367c` (feat)
2. **Task 2: Unit + integration test scaffolds** - `46bee07` (test)

## Files Created/Modified

- `tests/Integration/SharedEntity/Support/SharedEntitySyncTestKernel.php` — Landlord + tenant SQLite kernel, database.enabled: true
- `tests/Integration/SharedEntity/Support/StubMultiTenantProvider.php` — 2-tenant stub with path factories
- `tests/Integration/SharedEntity/Support/ReplaceWithStubMultiTenantProviderPass.php` — Compiler pass: swaps tenancy.provider to stub
- `tests/Integration/SharedEntity/Support/MakeSharedEntityServicesPublicPass.php` — Compiler pass: exposes Wave-3 services (tolerates absence)
- `tests/Integration/SharedEntity/Support/SharedEntityNoDbTestKernel.php` — shared_db driver kernel for SHARE-01-j
- `tests/Integration/SharedEntity/Support/Entity/TestPlan.php` — #[Shared] entity with name + priceCents scalar fields
- `tests/Integration/SharedEntity/Support/Entity/TestPlanCategory.php` — Plain entity (ManyToOne target for cascade test)
- `tests/Integration/SharedEntity/Support/Entity/TestPlanWithAssociation.php` — #[Shared] entity with title scalar + category ManyToOne
- `tests/Integration/SharedEntity/SharedEntitySyncIntegrationTest.php` — 10 behavior methods SHARE-01-b..m
- `tests/Integration/SharedEntity/SharedEntityNoDatabaseKernelTest.php` — SHARE-01-j no-op test
- `tests/Unit/Attribute/SharedTest.php` — SHARE-01-a attribute assertion
- `tests/Unit/DependencyInjection/Compiler/SharedEntityMutualExclusionPassTest.php` — SHARE-01-l mutual exclusion

## Decisions Made

1. **Skip-guard pattern for Wave 0**: `class_exists() + markTestSkipped()` in `setUp()` keeps the pre-commit hook green while tests remain discoverable via `--filter`. Tests are "pending" not "erroring" — avoids breaking the CI gate before production code lands.

2. **Separate SharedEntityNoDbTestKernel**: The `shared_db` driver is incompatible with `database.enabled: true`. Rather than using `SharedEntitySyncTestKernel` for SHARE-01-j, a dedicated `SharedEntityNoDbTestKernel` (single default EM, `driver: shared_db`) was created.

3. **$conn->close() in setUpBeforeClass()**: Following the established `DatabasePerTenantMiddlewareIntegrationTest` pattern — after `setTenant()`, calling `$tenantConn->close()` forces the DBAL lazy-reconnect through `TenantDriverMiddleware`, routing the connection to the correct SQLite file.

4. **Fixture classes without attribute annotations**: `SharedEntityMutualExclusionPassTest`'s inline fixture classes (BothAttributesEntity, etc.) omit the `#[Shared]`/`#[TenantAware]` attribute annotations in Wave 0 — adding them would cause PHP to attempt attribute class resolution at class-definition time, failing before the skip guard fires. The fixture classes will be annotated in Plan 25-02 when the pass is implemented.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Added ReplaceWithStubMultiTenantProviderPass**
- **Found during:** Task 1 (SharedEntitySyncTestKernel creation)
- **Issue:** The kernel's `build()` method needs to replace `tenancy.provider` with `StubMultiTenantProvider`. The existing `ReplaceTenancyProviderPass` in `tests/Integration/Support/` wires to `NullTenantProvider` — not suitable for the fan-out integration tests which need a working `findAll()`. A dedicated pass in the same namespace was needed.
- **Fix:** Created `ReplaceWithStubMultiTenantProviderPass` mirroring `ReplaceTenancyProviderPass` pattern.
- **Files modified:** `tests/Integration/SharedEntity/Support/ReplaceWithStubMultiTenantProviderPass.php`
- **Committed in:** `eca367c` (Task 1 commit)

**2. [Rule 1 - Bug] Added $conn->close() to setUpBeforeClass() tenant schema loop**
- **Found during:** Task 2 integration test run
- **Issue:** Without `$tenantConn->close()` after `setTenant()`, the tenant EM's DBAL connection did not re-route through `TenantDriverMiddleware` to the new SQLite file — all tenant schemas were being created on the placeholder DB, causing `table test_plans already exists` errors.
- **Fix:** Added `$tenantConn->close()` before each `$registry->resetManager('tenant')` call in the setup loop.
- **Files modified:** `tests/Integration/SharedEntity/SharedEntitySyncIntegrationTest.php`
- **Committed in:** `46bee07` (Task 2 commit)

**3. [Rule 2 - Missing Critical] Applied skip-guard pattern to all Wave 0 tests**
- **Found during:** Task 2 — pre-commit hook runs full phpunit suite, which would fail on tests referencing missing production classes
- **Issue:** Tests referencing `Shared`, `SharedEntityMutualExclusionPass`, and `SharedEntityWriteInTenantContextException` caused fatal errors during test discovery/execution, blocking commits.
- **Fix:** Added `class_exists()` + `markTestSkipped()` in each test's `setUp()` method. Tests remain discoverable via `--filter` but skip gracefully until production classes exist.
- **Files modified:** All 4 test files (SharedTest, SharedEntityMutualExclusionPassTest, SharedEntitySyncIntegrationTest, SharedEntityNoDatabaseKernelTest)
- **Committed in:** `46bee07` (Task 2 commit)

---

**Total deviations:** 3 auto-fixed (1 blocking, 1 bug, 1 missing critical)
**Impact on plan:** All 3 deviations were necessary for correctness and functionality. No scope creep.

## Issues Encountered

- Pre-commit hook runs full phpunit suite — Wave 0 RED tests (referencing non-existent production classes) blocked commits until the skip-guard pattern was applied. Resolved by auto-fix deviation #3.

## Known Stubs

None — all 12 test files are scaffolds targeting future production classes, not stubs with hardcoded empty values. The skip-guard pattern ensures they are properly marked as pending.

## Next Phase Readiness

- Plan 25-01 can immediately activate `SharedTest` by creating `src/Attribute/Shared.php`
- Plan 25-02 can immediately activate `SharedEntityMutualExclusionPassTest` by creating the compiler pass
- Plan 25-03 can immediately activate the write-protection tests by creating the exception + listener
- Plan 25-04 can immediately activate all fan-out tests by creating the sync subscriber + DI wiring
- The test kernel, entities, and stub provider are production-ready for use by Plans 25-01..25-04

---
*Phase: 25-shared-entities-sync-mode*
*Completed: 2026-06-11*
