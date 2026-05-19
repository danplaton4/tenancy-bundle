---
phase: 03-database-per-tenant-driver
verified: 2026-03-19T07:10:00Z
status: passed
score: 10/10 must-haves verified
re_verification:
  previous_status: gaps_found
  previous_score: 9/10
  gaps_closed:
    - "Bundle prependExtension targets the landlord EM mapping specifically (not top-level orm.mappings)"
  gaps_remaining: []
  regressions: []
---

# Phase 03: Database-Per-Tenant Driver Verification Report

**Phase Goal:** An active tenant's database connection is switched at runtime without rebuilding the container, and two named entity managers (landlord and tenant) are available and correctly scoped
**Verified:** 2026-03-19T07:10:00Z
**Status:** passed
**Re-verification:** Yes — after gap closure (Plan 03-06)

---

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | DatabaseSwitchBootstrapper delegates boot() to TenantConnection::switchTenant() with tenant connectionConfig | VERIFIED | `src/Bootstrapper/DatabaseSwitchBootstrapper.php` line 26: `$this->tenantConnection->switchTenant($tenant->getConnectionConfig())` |
| 2 | DatabaseSwitchBootstrapper delegates clear() to TenantConnection::reset() | VERIFIED | `src/Bootstrapper/DatabaseSwitchBootstrapper.php` line 31: `$this->tenantConnection->reset()` |
| 3 | TenantDriverInterface defines the boot/clear contract for isolation drivers | VERIFIED | `src/Driver/TenantDriverInterface.php`: `interface TenantDriverInterface extends TenantBootstrapperInterface` |
| 4 | switchTenant() changes the DBAL connection params to the tenant's database and closes the current connection | VERIFIED | `src/DBAL/TenantConnection.php` lines 46-48: `array_merge`, `setValue`, `close()` |
| 5 | reset() restores the connection params to the original landlord defaults and closes the current connection | VERIFIED | `src/DBAL/TenantConnection.php` lines 56-58: `setValue($this->originalParams)`, `close()` |
| 6 | Tenant-specific params merge over original params so missing keys inherit from landlord defaults | VERIFIED | `array_merge($this->originalParams, $tenantConnectionConfig)` — landlord keys preserved when not overridden; unit + integration tests confirm |
| 7 | When tenancy.database.enabled is true, DatabaseSwitchBootstrapper and EntityManagerResetListener are registered in the DI container | VERIFIED | `src/TenancyBundle.php` lines 69-85: conditional block registers both services with correct wiring |
| 8 | On TenantContextCleared event, the tenant entity manager is reset via resetManager('tenant') | VERIFIED | `src/EventListener/EntityManagerResetListener.php` line 21: `$this->managerRegistry->resetManager('tenant')` — `#[AsEventListener(event: TenantContextCleared::class)]` |
| 9 | The landlord entity manager is never reset | VERIFIED | `EntityManagerResetListener.__invoke` only calls `resetManager('tenant')`; integration test `testLandlordEmNotResetOnTenantContextCleared` confirms landlord EM spl_object_id unchanged |
| 10 | Bundle prependExtension targets the landlord EM mapping specifically (not top-level orm.mappings) | VERIFIED | `src/TenancyBundle.php` lines 107-131: reads `getExtensionConfig('tenancy')`, sets `$databaseEnabled`, branches — when true: `entity_managers.landlord.mappings`; when false/absent: `orm.mappings`. Three unit tests (44 assertions) confirm both paths. |

**Score:** 10/10 truths verified

---

## Gap Closure Detail (Plan 03-06)

The single gap from the initial verification was:

> `prependExtension()` unconditionally wrote to `orm.mappings` regardless of `database.enabled`, causing the Tenant entity to be mapped to the default EM instead of the landlord EM when dual-EM mode was enabled.

**Fix implemented in `src/TenancyBundle.php` lines 95-131:**

- `getExtensionConfig('tenancy')` is called to read raw pre-resolved config
- Iterates all returned config arrays (last-wins, matching Symfony merge behavior)
- When `$databaseEnabled === true`: calls `prependExtensionConfig('doctrine', ['orm' => ['entity_managers' => ['landlord' => ['mappings' => $mapping]]]])`
- When `$databaseEnabled === false` or absent: calls `prependExtensionConfig('doctrine', ['orm' => ['mappings' => $mapping]])` (backward-compatible)

**Test coverage added (`tests/Unit/TenancyBundlePrependExtensionTest.php`):**
- `testPrependExtensionTargetsLandlordEmWhenDatabaseEnabled` — 14 assertions, confirms landlord EM path, confirms top-level `orm.mappings` is absent
- `testPrependExtensionTargetsTopLevelMappingsWhenDatabaseDisabled` — 15 assertions, confirms `orm.mappings` path, confirms `entity_managers` is absent
- `testPrependExtensionTargetsTopLevelMappingsWhenNoConfig` — 15 assertions, confirms safe empty-config default
- All 3 tests pass: `OK (3 tests, 44 assertions)`

---

## Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `src/Driver/TenantDriverInterface.php` | Driver contract extending TenantBootstrapperInterface | VERIFIED | `interface TenantDriverInterface extends TenantBootstrapperInterface` — substantive, correct namespace `Tenancy\Bundle\Driver` |
| `src/DBAL/TenantConnectionInterface.php` | Interface extracted for testability | VERIFIED (addition) | Not in original PLAN but added as improvement; `DatabaseSwitchBootstrapper` injects this interface instead of `TenantConnection` directly — enables clean unit testing |
| `src/DBAL/TenantConnection.php` | DBAL 4 wrapperClass subclass with runtime switching | VERIFIED | `final class TenantConnection extends Connection implements TenantConnectionInterface`; `ReflectionProperty(Connection::class, 'params')`; `switchTenant` + `reset` fully implemented |
| `src/Bootstrapper/DatabaseSwitchBootstrapper.php` | Bootstrapper delegating to TenantConnection | VERIFIED | `final class DatabaseSwitchBootstrapper implements TenantDriverInterface`; injects `TenantConnectionInterface` (design improvement over plan); `boot()` + `clear()` wired |
| `src/EventListener/EntityManagerResetListener.php` | Event listener resetting tenant EM on context clear | VERIFIED | `#[AsEventListener(event: TenantContextCleared::class)]`; `resetManager('tenant')` called in `__invoke` |
| `src/TenancyBundle.php` | database.enabled config node + conditional service registration + landlord EM rewire + conditional prependExtension | VERIFIED | Config node present, conditional services registered, landlord EM rewire present, `prependExtension()` now fully conditional — targets `entity_managers.landlord.mappings` when enabled, `orm.mappings` when disabled |
| `tests/Unit/Bootstrapper/DatabaseSwitchBootstrapperTest.php` | Unit tests for boot/clear delegation | VERIFIED | 4 tests; `testBootCallsSwitchTenantWithConnectionConfig`, `testClearCallsReset`, interface assertions — all green (4 tests, 8 assertions) |
| `tests/Unit/DBAL/TenantConnectionTest.php` | Unit tests for param mutation and close/reconnect | VERIFIED | 5 tests covering merge semantics, reset, close, DBAL 4 constructor — all green |
| `tests/Unit/EventListener/EntityManagerResetListenerTest.php` | Unit tests for EM reset behavior | VERIFIED | 3 tests including attribute reflection check — all green |
| `tests/Unit/TenancyBundlePrependExtensionTest.php` | Unit tests proving both prependExtension branches | VERIFIED | 3 tests, 44 assertions — landlord EM path, disabled path, no-config path — all green |
| `tests/Integration/Support/DoctrineTestKernel.php` | Dual-EM test kernel | VERIFIED | Registers `FrameworkBundle + DoctrineBundle + TenancyBundle`; dual-EM (landlord + tenant); `wrapper_class: TenantConnection::class`; `database.enabled: true` |
| `tests/Integration/Support/Entity/TestProduct.php` | Simple Doctrine entity for tenant DB tests | VERIFIED | `#[ORM\Entity]`, `#[ORM\Table(name: 'test_products')]` |
| `tests/Integration/Support/MakeDatabaseServicesPublicPass.php` | Compiler pass for test visibility | VERIFIED | Makes `doctrine.dbal.tenant_connection`, `doctrine.orm.tenant_entity_manager`, `doctrine.orm.landlord_entity_manager` public |
| `tests/Integration/DatabaseSwitchIntegrationTest.php` | Cross-tenant query isolation tests | VERIFIED | 4 tests all pass: `testSwitchToTenantAQueriesHitTenantADatabase`, `testSwitchToTenantBDoesNotSeeTenantAData`, `testLandlordEmIsUnaffectedByTenantSwitch`, `testSameTenantSwitchAfterResetReconnects` |
| `tests/Integration/EntityManagerResetIntegrationTest.php` | EM reset and identity map teardown tests | VERIFIED | 3 tests all pass: `testResetManagerReturnsFreshEntityManager`, `testResetManagerClearsIdentityMap`, `testLandlordEmNotResetOnTenantContextCleared` |

---

## Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `src/Bootstrapper/DatabaseSwitchBootstrapper.php` | `src/DBAL/TenantConnectionInterface.php` | constructor injection | VERIFIED | Injects `TenantConnectionInterface` (not `TenantConnection` directly — design improvement). Calls `switchTenant()` and `reset()` |
| `src/DBAL/TenantConnection.php` | `vendor/doctrine/dbal/src/Connection.php` | extends + ReflectionProperty on private $params | VERIFIED | `new \ReflectionProperty(Connection::class, 'params')` at line 34; `setValue($this, $merged)` and `setValue($this, $this->originalParams)` |
| `config/services.php` | `src/Bootstrapper/DatabaseSwitchBootstrapper.php` | DI service definition with tenancy.bootstrapper tag | VERIFIED (via loadExtension) | Registration in `TenancyBundle::loadExtension()` conditional block with `->tag('tenancy.bootstrapper')` |
| `config/services.php` | `src/EventListener/EntityManagerResetListener.php` | DI service definition with autoconfigure | VERIFIED (via loadExtension) | `$services->set(EntityManagerResetListener::class)->autoconfigure(true)->args([service('doctrine')])` in loadExtension conditional |
| `src/TenancyBundle.php` | doctrine config | prependExtension with entity_managers.landlord.mappings | VERIFIED | `prependExtension()` reads `getExtensionConfig('tenancy')`, branches on `$databaseEnabled` — true path: `entity_managers.landlord.mappings`; false/absent path: `orm.mappings` |
| `src/EventListener/EntityManagerResetListener.php` | `resetManager('tenant')` call | Doctrine ManagerRegistry | VERIFIED | `$this->managerRegistry->resetManager('tenant')` — exactly `'tenant'`, not null or landlord |
| `src/EventListener/EntityManagerResetListener.php` | `src/Event/TenantContextCleared.php` | `#[AsEventListener]` attribute | VERIFIED | `#[AsEventListener(event: TenantContextCleared::class)]` on class declaration |

---

## Requirements Coverage

| Requirement | Source Plans | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| ISOL-01 | 03-01, 03-02, 03-03, 03-05, 03-06 | Database-per-tenant driver switches the DBAL connection at runtime using DBAL 4's wrapperClass pattern without rebuilding the container | SATISFIED | `TenantConnection` is the DBAL wrapperClass; `switchTenant()` mutates private `$params` via reflection and calls `close()` so next query reconnects; `DatabaseSwitchBootstrapper` hooks into `BootstrapperChain`; integration tests prove cross-tenant query isolation with zero container rebuild |
| ISOL-02 | 03-03, 03-04, 03-05, 03-06 | Database-per-tenant driver configures two named entity managers: landlord (static, reads central Tenant registry) and tenant (runtime-switched to active tenant DB) | SATISFIED | Dual-EM correctly wired in DI when `database.enabled: true`; `EntityManagerResetListener` resets only tenant EM on `TenantContextCleared`; `prependExtension()` now explicitly maps Tenant entity to landlord EM when `database.enabled: true`; integration tests prove landlord EM isolation and tenant EM identity map teardown |

---

## Anti-Patterns Found

No TODO/FIXME/placeholder comments found in any Phase 03 source files.

No empty implementations or stub patterns found.

The `prependExtension` anti-pattern from the initial verification has been resolved.

---

## Human Verification Required

None identified. All behavioral claims are verified programmatically via unit and integration tests.

---

## Test Suite Summary

| Suite | Count | Result |
|-------|-------|--------|
| Unit (full) | 108 tests, 297 assertions | All pass |
| Integration (Phase 03 specific) | 7 tests, 10 assertions | All pass |
| New: TenancyBundlePrependExtensionTest | 3 tests, 44 assertions | All pass |

The full integration suite (`--testsuite=integration`, 27 tests) shows 2 pre-existing errors in `ListenerPriorityTest` due to a stale compiled container (`TestKernelTestContainer`) compiled with 3 constructor arguments for `TenantContextOrchestrator` before a Phase 2 change added a 4th argument. This is an unrelated Phase 2 regression, not introduced by Phase 03. Deleting the cached container at `sys_get_temp_dir()/tenancy_bundle_test_*/` resolves it. All Phase 03-specific tests pass.

---

## Notable Design Deviation (Improvement)

Plan 03-01 specified injecting `TenantConnection` directly into `DatabaseSwitchBootstrapper`. The implementation extracted `TenantConnectionInterface` (`src/DBAL/TenantConnectionInterface.php`) instead. This is a deliberate improvement that enables clean unit testing without mocking a `final` class extending a complex Doctrine hierarchy. `TenantConnection` implements this interface. No functional gap.

---

_Verified: 2026-03-19T07:10:00Z_
_Verifier: Claude (gsd-verifier)_
_Re-verification after Plan 03-06 gap closure_
