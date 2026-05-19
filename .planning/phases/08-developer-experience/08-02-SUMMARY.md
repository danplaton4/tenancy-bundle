---
phase: 08-developer-experience
plan: 02
subsystem: testing
tags: [phpunit, integration-tests, tenancy, sqlite, dx]
dependency_graph:
  requires: [08-01]
  provides: [DX-01 integration test coverage]
  affects: []
tech_stack:
  added: []
  patterns: [TestCase with manual kernel lifecycle, in-memory SQLite schema-per-test]
key_files:
  created:
    - tests/Integration/Testing/InteractsWithTenancyTest.php
  modified:
    - src/Testing/InteractsWithTenancy.php
    - tests/Integration/Testing/Support/TenancyTestKernel.php
    - tests/Integration/Testing/Support/MakeTenancyTestServicesPublicPass.php
    - config/services.php
decisions:
  - "Schema must be created AFTER chain->boot() so DatabaseSwitchBootstrapper does not destroy the :memory: SQLite DB via close()"
  - "Synthetic tenant carries {memory:true, path:null} so switchTenant() targets :memory: (path:null bypasses DBAL's isset check)"
  - "enable_native_lazy_objects: true required in TenancyTestKernel for PHP 8.4 + Symfony 8 compatibility"
  - "TenantContext::class FQCN alias must be explicitly made public in MakeTenancyTestServicesPublicPass"
  - "doctrine.orm.entity_manager is the correct DoctrineBundle alias (not doctrine.orm.default_entity_manager)"
metrics:
  duration: "~14 min"
  completed_date: "2026-04-02"
  tasks_completed: 1
  files_changed: 5
---

# Phase 08 Plan 02: InteractsWithTenancy Integration Tests Summary

Integration tests proving all DX-01 success criteria for the InteractsWithTenancy trait, with 5 auto-fixes resolving pre-existing bugs in services.php and the trait's :memory: SQLite initialization sequence.

## What Was Built

`tests/Integration/Testing/InteractsWithTenancyTest.php` — 6 integration tests covering DX-01a through DX-01f:

| Test | Criterion | Proves |
|------|-----------|--------|
| `testInitializeTenantBootsContextAndSchema` | DX-01a | initializeTenant boots context AND creates schema — TestProduct can be persisted |
| `testTearDownClearsContextAfterTest` | DX-01b | tearDown clears TenantContext — hasTenant() is false after explicit tearDown call |
| `testTwoMethodsGetIsolatedDatabases` | DX-01c | tenant_x and tenant_y get separate :memory: databases with no data leakage |
| `testAssertTenantActivePassesWithCorrectSlug` | DX-01d | assertTenantActive('acme') does not throw when 'acme' is active |
| `testAssertNoTenantPassesWhenContextIsEmpty` | DX-01e | assertNoTenant() does not throw when context is empty |
| `testGetTenantServiceReturnsServiceFromContainer` | DX-01f | getTenantService(TenantContext::class) returns a TenantContext with active tenant |

The test class extends `TestCase` directly (not KernelTestCase) and manages the kernel lifecycle via `setUpBeforeClass`/`tearDownAfterClass`, consistent with other integration tests in the suite. A `protected static getContainer()` helper delegates to `static::$kernel->getContainer()` to satisfy the trait's dependency on `static::getContainer()`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed :memory: SQLite path override in InteractsWithTenancy::initializeTenant()**

- **Found during:** Task 1 — first test run
- **Issue:** `switchTenant(['driver' => 'pdo_sqlite', 'memory' => true])` was merged over original params that included `path: /tmp/placeholder.db`. DBAL's SQLite driver checks `isset($params['path'])` before `isset($params['memory'])`, so the file path took precedence and the connection never actually used `:memory:`.
- **Fix:** Added `'path' => null` to the switchTenant call. `isset(null)` is false in PHP, so DBAL correctly falls through to the `memory` branch and uses `:memory:`.
- **Files modified:** `src/Testing/InteractsWithTenancy.php`
- **Commit:** dd4a42f

**2. [Rule 1 - Bug] Fixed initializeTenant() sequence — schema created before chain->boot() destroyed :memory: DB**

- **Found during:** Task 1 — after path fix, "no such table: test_products" errors
- **Issue:** Original sequence: switch→resetManager→createSchema→setTenant→chain.boot(). `chain.boot()` calls `DatabaseSwitchBootstrapper::boot($tenant)` → `switchTenant($tenant.getConnectionConfig())` where the synthetic tenant has `connectionConfig = []`, resetting to the original placeholder file params. This calls `close()`, destroying the `:memory:` DB that had the schema.
- **Fix:** Reordered sequence: clear→buildTenant(with memory config)→setTenant→chain.boot()→resetManager→createSchema. The synthetic tenant now carries `{driver:pdo_sqlite, memory:true, path:null}` so `DatabaseSwitchBootstrapper` switches to `:memory:` correctly. Schema is created AFTER the final `close()` + reopen.
- **Files modified:** `src/Testing/InteractsWithTenancy.php`
- **Commit:** dd4a42f

**3. [Rule 1 - Bug] Fixed `doctrine.orm.entity_manager` alias name in services.php**

- **Found during:** Task 1 — container compilation failure
- **Issue:** `services.php` registered `tenancy.doctrine_bootstrapper` with `service('doctrine.orm.default_entity_manager')`. DoctrineBundle does NOT register this service ID — it registers `doctrine.orm.entity_manager` as the alias to the default EM.
- **Fix:** Changed to `service('doctrine.orm.entity_manager')` and wrapped in `interface_exists(EntityManagerInterface::class)` guard. Also added `.nullOnInvalid()` to the `doctrine.migrations.configuration` dependency to prevent compilation failures when DoctrineMigrationsBundle isn't registered.
- **Files modified:** `config/services.php`
- **Commit:** dd4a42f

**4. [Rule 2 - Missing] Added `enable_native_lazy_objects: true` to TenancyTestKernel**

- **Found during:** Task 1 — `ORMInvalidArgumentException: Symfony LazyGhost is not available`
- **Issue:** PHP 8.4 + Symfony 8 removed `LazyGhostTrait` from `symfony/var-exporter`. Doctrine ORM 3.x requires either LazyGhostTrait OR PHP 8.4 native lazy objects. The DoctrineBundle configuration defaults `enable_native_lazy_objects: false`, requiring explicit opt-in.
- **Fix:** Added `'enable_native_lazy_objects' => true` to the ORM section in `TenancyTestKernel::registerContainerConfiguration()`.
- **Files modified:** `tests/Integration/Testing/Support/TenancyTestKernel.php`
- **Commit:** dd4a42f

**5. [Rule 2 - Missing] Exposed TenantContext::class FQCN alias in MakeTenancyTestServicesPublicPass**

- **Found during:** Task 1 — `ServiceNotFoundException: The "Tenancy\Bundle\Context\TenantContext" service has been removed or inlined`
- **Issue:** `services.php` registers `$services->alias(TenantContext::class, 'tenancy.context')` — a DI alias that is private by default. `MakeTenancyTestServicesPublicPass` only made `tenancy.context` (the definition) public, not the FQCN alias.
- **Fix:** Added `\Tenancy\Bundle\Context\TenantContext::class` to the list of IDs made public in `MakeTenancyTestServicesPublicPass`.
- **Files modified:** `tests/Integration/Testing/Support/MakeTenancyTestServicesPublicPass.php`
- **Commit:** dd4a42f

## Pre-existing Test Failures (Deferred)

The full integration suite had 10 pre-existing failures before this plan (confirmed by verifying the same failures exist without this plan's changes). These are NOT caused by Plan 08-02:

- `AutoconfigurationTest`, `CacheBootstrapperIntegrationTest`, `ContainerCompilationTest`, `DatabaseSwitchIntegrationTest`, `DoctrineBootstrapperIntegrationTest`, `EntityManagerResetIntegrationTest`, `ListenerPriorityTest`, `MessengerMiddlewareIntegrationTest`, `SharedDbFilterIntegrationTest`, `TenantResolutionIntegrationTest`

Root cause: These kernels use Doctrine with dual-EM config but don't have `enable_native_lazy_objects: true`. Fix logged to `deferred-items.md`.

## Self-Check: PASSED

- `tests/Integration/Testing/InteractsWithTenancyTest.php` exists — FOUND
- `src/Testing/InteractsWithTenancy.php` modified — FOUND
- Commit dd4a42f exists — FOUND
- All 6 tests pass: `./vendor/bin/phpunit --filter InteractsWithTenancyTest` → OK (6 tests, 17 assertions)
- Plan 08-02 did not introduce new failures (pre-existing count unchanged at 10 integration + 7 unit)
