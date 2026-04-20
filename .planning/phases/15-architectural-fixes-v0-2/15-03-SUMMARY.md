---
phase: 15-architectural-fixes-v0-2
plan: 03
subsystem: dbal-driver-middleware
tags: [tenancy, dbal-4, middleware, driver, database-per-tenant, phpunit, symfony, doctrine-bundle]

# Dependency graph
requires:
  - phase: 03-database-per-tenant-driver
    provides: DatabaseSwitchBootstrapper, TenantConnection (wrapperClass + reflection — deleted)
  - phase: 15-architectural-fixes-v0-2 plan 01
    provides: cache.app decorator parity (unrelated, but in same phase)
  - phase: 15-architectural-fixes-v0-2 plan 02
    provides: TenantContext + TenantResolution semantics (ResolverChain nullable return; orchestrator null-branch)
provides:
  - TenantDriverMiddleware (Doctrine\DBAL\Driver\Middleware) — wraps tenant driver
  - TenantAwareDriver (extends AbstractDriverMiddleware) — per-connect() tenant params merge
  - DatabaseSwitchBootstrapper::boot()/clear() reduced to $connection->close()
  - `doctrine.middleware` tag with ['connection' => 'tenant'] scoping (landlord connection untouched)
  - DatabasePerTenantMiddlewareIntegrationTest — end-to-end two-SQLite-file roundtrip
  - TenantDriverMiddlewareWiringTest — compile-time tag-scoping assertions
affects:
  - Downstream forks that extended TenantConnection (class removed; v0.1 had 2 self-downloads on Packagist)
  - DoctrineTestKernel + TenancyTestKernel consumers (wrapper_class line dropped — automatic now)
  - Any test that drove tenant switching via $conn->switchTenant() must migrate to $ctx->setTenant() + $conn->close()

# Tech tracking
tech-stack:
  added:
    - Doctrine\DBAL\Driver\Middleware (interface; vendored in dbal 4.x)
    - Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware (abstract base; vendored in dbal 4.x)
  patterns:
    - "DBAL 4 driver-middleware: wrap(Driver): Driver + per-connect() param rewrite"
    - "Tag-scoped middleware: doctrine.middleware with ['connection' => 'tenant'] (MiddlewaresPass generates per-connection child definitions)"
    - "Lazy reconnect through close(): socket rotation via the middleware + DBAL's internal connect() path — zero reflection, zero wrapperClass"

key-files:
  created:
    - src/DBAL/TenantDriverMiddleware.php
    - src/DBAL/TenantAwareDriver.php
    - tests/Unit/DBAL/TenantAwareDriverTest.php
    - tests/Integration/DBAL/TenantDriverMiddlewareWiringTest.php
    - tests/Integration/DBAL/DatabasePerTenantMiddlewareIntegrationTest.php
  modified:
    - src/Bootstrapper/DatabaseSwitchBootstrapper.php (TenantConnectionInterface → Connection; close()-only)
    - src/TenancyBundle.php (register tenancy.dbal.tenant_driver_middleware + tag)
    - src/Testing/InteractsWithTenancy.php (docblock accuracy — middleware path replaces switchTenant narrative)
    - tests/Integration/Support/DoctrineTestKernel.php (drop TenantConnection import + wrapper_class)
    - tests/Integration/Testing/Support/TenancyTestKernel.php (drop TenantConnection import + wrapper_class)
    - tests/Integration/Support/MakeDatabaseServicesPublicPass.php (expose landlord_connection + middleware child def)
    - tests/Integration/Cache/DoctrineTenantProviderBootTest.php (drop wrapper_class)
    - tests/Integration/EntityManagerResetIntegrationTest.php (migrate switchTenant → setTenant + close)
    - tests/Integration/Command/Support/StubConnectionFactory.php (return plain Connection)
    - tests/Unit/Bootstrapper/DatabaseSwitchBootstrapperTest.php (Connection mock + close-only expectations)
  deleted:
    - src/DBAL/TenantConnection.php (v0.1 wrapperClass + reflection — incompatible with DBAL 4 immutable driver)
    - src/DBAL/TenantConnectionInterface.php (contract only implemented by the deleted class)
    - tests/Unit/DBAL/TenantConnectionTest.php (tests the deleted class)
    - tests/Integration/DatabaseSwitchIntegrationTest.php (switchTenant-based — superseded by middleware integration test)

key-decisions:
  - "DBAL 4 middleware is the architecturally correct extension point — replaces wrapperClass + ReflectionProperty approach that could not rotate the immutable Connection::$driver"
  - "Tag-scoped with connection: tenant — MiddlewaresPass generates per-connection child definitions; landlord connection never receives tenant param merges (proven by 2 DI-level assertions + 1 data-level assertion)"
  - "DatabaseSwitchBootstrapper reduced to $connection->close() — DBAL's lazy-reconnect path re-enters TenantAwareDriver::connect() with fresh TenantContext"
  - "Delete TenantConnection + TenantConnectionInterface outright — v0.1 had 2 Packagist downloads (both self), no external users to carry; UPGRADE.md will point forks at the middleware"
  - "Delete tests/Integration/DatabaseSwitchIntegrationTest.php — its switchTenant-based coverage is a strict subset of the new middleware-integration test; rewriting would duplicate the new test"
  - "Middleware tag uses default priority (0) — no other middleware in the bundle mutates $params; ordering is irrelevant for correctness"

patterns-established:
  - "Per-tenant socket rotation via middleware + close(): architecturally correct for any DBAL 4 driver-pooled connection pattern (not just tenancy)"
  - "DI-level regression guards for security-critical tag scoping: assert the child definition for the forbidden connection DOES NOT exist (explicit negative assertion, not just positive coverage)"

requirements-completed: [FIX-03]

# Metrics
duration: ~25min
completed: 2026-04-20
---

# Phase 15 Plan 03: DBAL Driver-Middleware Refactor Summary

**TenantConnection + ReflectionProperty deleted; tenant database switching now routes through `Doctrine\DBAL\Driver\Middleware` — `$conn->close()` + lazy reconnect re-enters `TenantAwareDriver::connect()` with the fresh `TenantContext`, while the `['connection' => 'tenant']` tag prevents the landlord connection from ever seeing tenant params.**

## Performance

- **Duration:** ~25 min (7 tasks, 8 commits)
- **Tasks:** 7/7 executed exactly as planned
- **Files modified:** 14 (5 created, 9 modified, 4 deleted)
- **Tests:** 296 pass (up from 273 pre-plan) — 13 new tests added across 3 files

## Accomplishments

- Introduced `final class TenantDriverMiddleware implements \Doctrine\DBAL\Driver\Middleware` + `final class TenantAwareDriver extends AbstractDriverMiddleware`. Together they replace the v0.1 `wrapperClass` approach with the documented DBAL 4 extension point.
- `TenantAwareDriver::connect()` merges `$tenant->getConnectionConfig()` over `$params` when `TenantContext::getTenant() !== null` (tenant keys win; `url` never touched — that's resolved by DriverManager before middlewares wrap).
- Registered `tenancy.dbal.tenant_driver_middleware` via the `doctrine.middleware` tag with `['connection' => 'tenant']` attribute inside `TenancyBundle::loadExtension()`'s `database.enabled` branch. DoctrineBundle's `MiddlewaresPass` generates a per-connection child definition; the landlord connection never receives a child.
- Reduced `DatabaseSwitchBootstrapper` to two one-liners: both `boot()` and `clear()` call `$connection->close()`. DBAL's lazy-connect path re-enters the middleware with the fresh `TenantContext` on the next query. The bootstrapper no longer reads `getConnectionConfig()` or uses reflection.
- Deleted `src/DBAL/TenantConnection.php`, `src/DBAL/TenantConnectionInterface.php`, and `tests/Unit/DBAL/TenantConnectionTest.php` outright. Rewrote `StubConnectionFactory` to return a plain `Doctrine\DBAL\Connection`.
- Migrated `EntityManagerResetIntegrationTest` + `DoctrineTenantProviderBootTest` off the deleted API. Replaced the old `DatabaseSwitchIntegrationTest` entirely with `DatabasePerTenantMiddlewareIntegrationTest` (real two-SQLite-file roundtrip).
- Added `TenantDriverMiddlewareWiringTest` with 5 compile-time assertions — most importantly the **negative** assertion that `tenancy.dbal.tenant_driver_middleware.landlord` does NOT exist (tag-scoping regression guard).

Closes GitHub issues #7 and #8 (same root cause — DBAL 4 immutable `Connection::$driver`).

## Task Commits

Executed against base `5122069`. All commits use `--no-verify` per worktree execution policy (orchestrator re-runs hooks at merge).

1. **Task 1: TenantDriverMiddleware + TenantAwareDriver (TDD)**
   - `d0655c3` — test(15-03): add failing tests for TenantDriverMiddleware + TenantAwareDriver
   - `f75178d` — feat(15-03): add TenantDriverMiddleware + TenantAwareDriver
2. **Task 2: DatabaseSwitchBootstrapper close-only (TDD)**
   - `ca63b93` — test(15-03): rewrite DatabaseSwitchBootstrapperTest for close()-only semantics
   - `11a189e` — feat(15-03): reduce DatabaseSwitchBootstrapper to close()-only
3. **Task 3: Middleware wiring + test kernel cleanup**
   - `683798a` — feat(15-03): wire TenantDriverMiddleware via doctrine.middleware tag
4. **Task 4: Deletions + factory rewrite + test migrations**
   - `a4eb1a1` — refactor(15-03): delete TenantConnection + TenantConnectionInterface
5. **Task 5: DI wiring assertion test**
   - `9a78bc2` — test(15-03): DI wiring test asserts middleware scoping to tenant connection only
6. **Task 6: Real two-SQLite-file integration test + delete old DatabaseSwitchIntegrationTest**
   - `082b141` — test(15-03): real two-SQLite-file roundtrip proves tenant data isolation
7. **Task 7: Full suite / PHPStan / cs-fixer gate**
   - No separate commit needed — quality gate ran clean on Task 6's final state.

## Files Created/Modified/Deleted

**Created:**
- `src/DBAL/TenantDriverMiddleware.php` — `final class` implementing `Doctrine\DBAL\Driver\Middleware`; constructor takes `TenantContext`; `wrap(Driver): Driver` returns a `TenantAwareDriver`.
- `src/DBAL/TenantAwareDriver.php` — `final class` extending `AbstractDriverMiddleware`; constructor takes wrapped `Driver` + `TenantContext`; `connect(array $params): DriverConnection` merges `$tenant->getConnectionConfig()` over `$params` when a tenant is active and delegates to `parent::connect()`. Uses `@phpstan-import-type Params from DriverManager` to satisfy PHPStan level 9.
- `tests/Unit/DBAL/TenantAwareDriverTest.php` — 5 tests (wrap, connect-without-tenant, connect-with-tenant merge, landlord-driver-key preservation, inherited delegation).
- `tests/Integration/DBAL/TenantDriverMiddlewareWiringTest.php` — 5 tests (child def exists, resolves to correct class, landlord child absent, tenant Configuration contains middleware, landlord Configuration does NOT contain middleware).
- `tests/Integration/DBAL/DatabasePerTenantMiddlewareIntegrationTest.php` — 3 tests (two-SQLite-file roundtrip data isolation, landlord params untouched, landlord EM unaffected by tenant switches).

**Modified:**
- `src/Bootstrapper/DatabaseSwitchBootstrapper.php` — constructor now accepts `Doctrine\DBAL\Connection` (was `TenantConnectionInterface`); both methods reduced to `$this->connection->close()`.
- `src/TenancyBundle.php` — added `TenantDriverMiddleware` import + service registration with tag `doctrine.middleware` / `['connection' => 'tenant']`.
- `src/Testing/InteractsWithTenancy.php` — removed `TenantConnection` import; docblock comments refreshed to describe the middleware path (step 4 of `initializeTenant()` now reads as "close() → lazy reconnect routes through the middleware").
- `tests/Integration/Support/DoctrineTestKernel.php` — removed `TenantConnection` import + `'wrapper_class' => TenantConnection::class` from the tenant connection config.
- `tests/Integration/Testing/Support/TenancyTestKernel.php` — same changes as `DoctrineTestKernel`.
- `tests/Integration/Support/MakeDatabaseServicesPublicPass.php` — added `doctrine.dbal.landlord_connection` and `tenancy.dbal.tenant_driver_middleware.tenant` (MiddlewaresPass-generated child definition) to the public-ids list.
- `tests/Integration/Cache/DoctrineTenantProviderBootTest.php` — removed `TenantConnection` import + `wrapper_class` line from its kernel.
- `tests/Integration/EntityManagerResetIntegrationTest.php` — migrated from `$conn->switchTenant([...])` to `$ctx->setTenant($tenantA); $conn->close();` pattern.
- `tests/Integration/Command/Support/StubConnectionFactory.php` — return type `Connection`; dropped `wrapperClass` param.
- `tests/Unit/Bootstrapper/DatabaseSwitchBootstrapperTest.php` — constructor dependency switched to `Connection` mock; assertions target `close()` (not `switchTenant`/`reset`); added a test that boot() does NOT call `getConnectionConfig()` on the tenant.

**Deleted:**
- `src/DBAL/TenantConnection.php`
- `src/DBAL/TenantConnectionInterface.php`
- `tests/Unit/DBAL/TenantConnectionTest.php`
- `tests/Integration/DatabaseSwitchIntegrationTest.php`

## Decisions Made

- **Tag-scoped per connection** — `['connection' => 'tenant']` ensures the landlord connection never receives the middleware. Proven at DI compile-time (2 positive + 1 negative assertion in `TenantDriverMiddlewareWiringTest`) and at data layer (landlord EM regression in `DatabasePerTenantMiddlewareIntegrationTest`). The plan's `<threat_model>` T-15-10 (landlord contamination) is mitigated twice.
- **Default priority (0) on the middleware tag** — no other bundle-owned middleware mutates `$params`, so ordering is irrelevant. Raising priority would not change behavior; keeping default avoids over-constraining downstream consumers.
- **Configuration inspected via `Connection::getConfiguration()` rather than via the container** — DoctrineBundle keeps `doctrine.dbal.*.configuration` services private and they get inlined at compile. `$conn->getConfiguration()->getMiddlewares()` gives the same view after compile with no DI plumbing.
- **Delete `DatabaseSwitchIntegrationTest` rather than rewrite it** — the new `DatabasePerTenantMiddlewareIntegrationTest` covers every assertion the old test made (data isolation between two SQLite files + landlord EM unaffected). Rewriting would have produced a near-duplicate file.
- **Keep `DatabaseSwitchBootstrapper` as the single public entry point** — renamed methods (`boot`/`clear`) stay on `TenantBootstrapperInterface`/`TenantDriverInterface`; no downstream-facing signature change beyond the constructor's DI type. Existing bundle service wiring (`service('doctrine.dbal.tenant_connection')`) remains valid — only the concrete type changed.
- **`@phpstan-import-type Params` for strict-typed param arrays** — DBAL level 9 PHPStan phpdocs use a `Params` shape from `DriverManager`; mirroring that in `TenantAwareDriver::connect()` lets us pass through to `parent::connect($params)` cleanly with no ignore comments.

## Deviations from Plan

**1. [Rule 3 - Blocking] `MakeDatabaseServicesPublicPass` needed updates for new services**
- **Found during:** Task 5 (DI wiring test)
- **Issue:** The test required `doctrine.dbal.landlord_connection` and the middleware child definition to be publicly retrievable; neither was in the existing public-ids list.
- **Fix:** Added both IDs to `MakeDatabaseServicesPublicPass::$ids`.
- **Files modified:** `tests/Integration/Support/MakeDatabaseServicesPublicPass.php`
- **Verification:** `TenantDriverMiddlewareWiringTest` passes (5 tests, 6 assertions).
- **Committed in:** `9a78bc2`

**2. [Rule 3 - Blocking] `EntityManagerResetIntegrationTest` needed migration off `switchTenant()`**
- **Found during:** Task 4 (post-deletion scan)
- **Issue:** The integration test still called `$conn->switchTenant([...])` after the class was deleted — prior-phase regression test had to be migrated to the middleware pattern (`$ctx->setTenant()` + `$conn->close()`).
- **Fix:** Updated two callsites (setUpBeforeClass + `testResetManagerClearsIdentityMap`) to use the middleware pattern.
- **Files modified:** `tests/Integration/EntityManagerResetIntegrationTest.php`
- **Verification:** Full `phpunit` suite passes (296/296).
- **Committed in:** `a4eb1a1` (bundled with the deletion commit since they share the root cause).

**3. [Rule 3 - Blocking] Wiring test initial approach (inspecting `.configuration` via container) failed because private services are inlined**
- **Found during:** Task 5 (initial attempt)
- **Issue:** `$container->get('doctrine.dbal.tenant_connection.configuration')` threw ServiceNotFoundException post-compile — the `.configuration` service is private and inlined.
- **Fix:** Pivoted to `Connection::getConfiguration()` — a public API on the already-public tenant connection. Same data, no DI plumbing.
- **Files modified:** `tests/Integration/DBAL/TenantDriverMiddlewareWiringTest.php` (only during authoring; no separate commit).
- **Verification:** All 5 wiring tests pass.
- **Committed in:** `9a78bc2`

**Total deviations:** 3 blocking issues, all auto-fixed inline. **Impact:** none on planned artifacts or scope; all deviations stayed within the plan's file list.

## Threat Model Coverage

Threat register dispositions from the plan (T-15-09..T-15-13) are implemented as specified:

- **T-15-09 (Tampering — TenantContext poisoning):** Accept (no change). Unchanged from v0.1 threat model.
- **T-15-10 (Information Disclosure — landlord receives tenant params):** **Mitigated**. The `['connection' => 'tenant']` tag attribute is asserted at DI compile-time by `TenantDriverMiddlewareWiringTest::testLandlordConnectionHasNoTenantDriverMiddlewareChild` and at data-level by `DatabasePerTenantMiddlewareIntegrationTest::testLandlordConnectionUnaffectedByTenantSwitches`. Two independent regression layers.
- **T-15-11 (Information Disclosure — stale `_conn` reused for wrong tenant):** **Mitigated**. `DatabaseSwitchBootstrapper::boot()` calls `$connection->close()` every switch. `DatabasePerTenantMiddlewareIntegrationTest::testRealTwoTenantSqliteFileRoundtripIsolatesData` proves round-trip isolation via real INSERT/SELECT COUNT across two SQLite files.
- **T-15-12 (Tampering — tenant injects `url` key to hijack driver):** **Mitigated by architecture**. DriverManager resolves the driver BEFORE middlewares wrap. `url` keys in tenant config cannot rewrite the already-chosen driver. This constraint is documented in the `TenantAwareDriver` class docblock and will surface in UPGRADE.md (plan 15-04).
- **T-15-13 (EoP — downstream fork extended `TenantConnection`):** Accept + document. Packagist metadata showed 2 self-downloads; risk is nominal. UPGRADE.md (plan 15-04) will point any affected fork at the middleware.

No new threat surface introduced.

## Issues Encountered

- **PHPUnit cache directories colliding across test runs** — mitigated by purging `/var/folders/**/tenancy_*` between runs. Non-issue in CI (fresh containers).
- **`$container->get('doctrine.dbal.tenant_connection.configuration')` inlined** — expected DoctrineBundle behavior; pivot to `Connection::getConfiguration()` (documented above as deviation #3).

No issues blocked task completion.

## Quality Gate

Full phase gate (Task 7):

- `vendor/bin/phpunit` — **296 tests / 708 assertions, all green** (up from 273 pre-plan)
- `vendor/bin/phpstan analyse --memory-limit=512M` — **level 9 clean across 44 files**
- `vendor/bin/php-cs-fixer check --diff --allow-risky=yes` — **clean** (0 files changed)
- `composer dump-autoload --optimize` — **clean** (5104 classes, no stale FQCN warnings)

## TDD Gate Compliance

Tasks 1 and 2 followed the full RED → GREEN cycle:

- **Task 1:** test commit `d0655c3` (RED — 5 errors, missing classes) → feat commit `f75178d` (GREEN — 5/5 pass, PHPStan clean with `@phpstan-import-type Params` to satisfy level 9).
- **Task 2:** test commit `ca63b93` (RED — 5 errors, constructor type mismatch) → feat commit `11a189e` (GREEN — 5/5 pass, PHPStan clean).

Tasks 3-6 are refactor/wiring/integration work; commits include both code + tests where tests are strictly integration (no RED/GREEN split needed since a failing integration test would gate on runtime infrastructure that Tasks 3-4 set up first).

## Next Phase Readiness

- **Plan 15-04 (UPGRADE.md + CHANGELOG + `tenancy:init` YAML):**
  - Must document: `TenantConnection` + `TenantConnectionInterface` classes removed; `wrapperClass` config key no longer required/supported.
  - Must document: `TenantInterface::getConnectionConfig()` MUST NOT contain `url` — discrete keys (`dbname`, `host`, `port`, `user`, `password`, `path`) only. See `src/DBAL/TenantAwareDriver.php` docblock for the canonical phrasing.
  - Must document: landlord connection `driver` MUST match tenant driver family (tenant `getConnectionConfig()` should not override `driver`). The middleware assumes driver-family consistency.
  - `tenancy:init` YAML template: tenant connection block uses the tenant driver family (`pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`) in the placeholder.
- **Phase gate (at phase 15 completion):** `Fixes #7` and `Fixes #8` footers should appear in the phase merge commit.

## Self-Check: PASSED

**Files verified:**
- FOUND: src/DBAL/TenantDriverMiddleware.php
- FOUND: src/DBAL/TenantAwareDriver.php
- FOUND: tests/Unit/DBAL/TenantAwareDriverTest.php
- FOUND: tests/Integration/DBAL/TenantDriverMiddlewareWiringTest.php
- FOUND: tests/Integration/DBAL/DatabasePerTenantMiddlewareIntegrationTest.php
- FOUND: src/Bootstrapper/DatabaseSwitchBootstrapper.php (modified — Connection constructor)
- FOUND: src/TenancyBundle.php (modified — middleware tag registration)
- DELETED: src/DBAL/TenantConnection.php
- DELETED: src/DBAL/TenantConnectionInterface.php
- DELETED: tests/Unit/DBAL/TenantConnectionTest.php
- DELETED: tests/Integration/DatabaseSwitchIntegrationTest.php

**Commits verified:**
- FOUND: d0655c3 (test: Task 1 RED)
- FOUND: f75178d (feat: Task 1 GREEN)
- FOUND: ca63b93 (test: Task 2 RED)
- FOUND: 11a189e (feat: Task 2 GREEN)
- FOUND: 683798a (feat: Task 3 wiring)
- FOUND: a4eb1a1 (refactor: Task 4 deletions)
- FOUND: 9a78bc2 (test: Task 5 DI wiring)
- FOUND: 082b141 (test: Task 6 integration)

**Quality gate:**
- phpunit (296 tests): PASS
- phpstan (level 9, 44 files): PASS
- php-cs-fixer (@Symfony, risky): PASS

---
*Phase: 15-architectural-fixes-v0-2*
*Plan: 03*
*Completed: 2026-04-20*
