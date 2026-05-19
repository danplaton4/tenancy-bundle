---
phase: 05-infrastructure-bootstrappers
plan: "03"
subsystem: bootstrapper-integration-tests
tags: [doctrine, identity-map, cache, namespace-isolation, integration-test]
dependency_graph:
  requires: [05-01, 05-02]
  provides: [DoctrineBootstrapperIntegrationTest, CacheBootstrapperIntegrationTest, BootstrapperTestKernel]
  affects: [tests/Integration/]
tech_stack:
  added: []
  patterns: [setUpBeforeClass/tearDownAfterClass kernel lifecycle, environment-aware SQLite DB path, anonymous TenantInterface stubs]
key_files:
  created:
    - tests/Integration/Support/BootstrapperTestKernel.php
    - tests/Integration/Support/MakeBootstrapperServicesPublicPass.php
    - tests/Integration/DoctrineBootstrapperIntegrationTest.php
    - tests/Integration/CacheBootstrapperIntegrationTest.php
  modified: []
decisions:
  - "BootstrapperTestKernel uses shared_db driver (single EM) — DoctrineBootstrapper targets default EM, no separate landlord/tenant split needed for these tests"
  - "BootstrapperTestKernel environment-aware SQLite DB path (tenancy_bootstrapper_{env}.db) prevents collision between DoctrineBootstrapper (env=test) and CacheBootstrapper (env=cache_test) kernel instances"
  - "CacheBootstrapperIntegrationTest boots separate kernel instance (env=cache_test) to avoid shared static state with DoctrineBootstrapperIntegrationTest"
  - "Anonymous TenantInterface stubs used for cache tests — simpler than persisting real Tenant entities; only getSlug() required for namespace routing"
  - "MakeBootstrapperServicesPublicPass exposes: tenancy.doctrine_bootstrapper, tenancy.context, tenancy.bootstrapper_chain, doctrine.orm.default_entity_manager, doctrine (ManagerRegistry), cache.app"
metrics:
  duration: "~3 min"
  completed_date: "2026-03-19"
  tasks_completed: 2
  files_modified: 4
---

# Phase 05 Plan 03: Bootstrapper Integration Tests Summary

**One-liner:** End-to-end integration tests proving DoctrineBootstrapper identity map isolation and TenantAwareCacheAdapter namespace isolation through the real Symfony DI container.

## What Was Built

### BootstrapperTestKernel (new)

A minimal test kernel for bootstrapper integration tests. Uses `shared_db` driver (single EM, simplest mode), registers FrameworkBundle + DoctrineBundle + TenancyBundle. Uses environment-aware SQLite DB path so multiple kernel instances in the same test run don't collide.

### MakeBootstrapperServicesPublicPass (new)

Compiler pass that exposes private bundle services for test container access: `tenancy.doctrine_bootstrapper`, `tenancy.context`, `tenancy.bootstrapper_chain`, `doctrine.orm.default_entity_manager`, `doctrine`, `cache.app`.

### DoctrineBootstrapperIntegrationTest (new)

Four integration tests proving identity map isolation:

1. **testDoctrineBootstrapperIsRegisteredInContainer** — `tenancy.doctrine_bootstrapper` resolves to `DoctrineBootstrapper` instance
2. **testBootClearsIdentityMap** — after loading entity, `boot()` wipes identity map completely
3. **testClearClearsIdentityMap** — after loading entity, `clear()` wipes identity map completely
4. **testEntityManagerResetListenerResetsDefaultEM** — `resetManager()` with no argument produces a new UoW (proven via `spl_object_id`)

### CacheBootstrapperIntegrationTest (new)

Four integration tests proving cache namespace isolation:

1. **testCacheAppIsDecoratedByTenantAwareAdapter** — `cache.app` resolves to `TenantAwareCacheAdapter`
2. **testCacheIsolationBetweenTenants** — write-as-A / read-as-B is a cache miss; switch back to A still sees original value
3. **testClearTenantACacheDoesNotAffectTenantB** — `pool->clear()` under tenant A does not invalidate tenant B entries
4. **testNoTenantDelegatesToGlobalPool** — no-tenant writes to global pool; switching to tenant A cannot read global key

## Tasks Completed

| Task | Description | Commit | Files |
|------|-------------|--------|-------|
| 1 | BootstrapperTestKernel + DoctrineBootstrapper integration test | 48f84a3 | 3 files created |
| 2 | CacheBootstrapper integration test (namespace isolation) | 0f0ec39 | 2 files created/modified |

## Verification

```
vendor/bin/phpunit tests/Integration/DoctrineBootstrapperIntegrationTest.php tests/Integration/CacheBootstrapperIntegrationTest.php
```

Result: **8 tests, 20 assertions, OK**

Full suite: 176 tests, 427 assertions — 1 pre-existing failure (out of scope, logged in deferred-items.md).

## Deviations from Plan

### Auto-applied Changes

**1. [Rule 2 - Missing critical] Environment-aware SQLite DB path in BootstrapperTestKernel**
- **Found during:** Task 2 setup
- **Issue:** Hardcoded `tenancy_bootstrapper_test.db` would be shared between DoctrineBootstrapper (env=test) and CacheBootstrapper (env=cache_test) kernel instances, causing DB-locked / schema-conflicts when both run in the same suite
- **Fix:** Changed `path` to `sys_get_temp_dir() . '/tenancy_bootstrapper_' . $this->environment . '.db'` and used a separate environment `cache_test` for the cache kernel
- **Files modified:** `tests/Integration/Support/BootstrapperTestKernel.php`
- **Commit:** 0f0ec39

### Out-of-Scope Issues (Logged to deferred-items.md)

**EntityManagerResetIntegrationTest::testResetManagerClearsIdentityMap** — Pre-existing failure confirmed before any 05-03 changes. Identity map not empty after `TenantContextCleared` dispatch — the test re-fetches via `registry->getManager()` but DoctrineBundle lazy proxy semantics mean the pre-reset reference is stale. Not caused by this plan.

## Self-Check: PASSED

All created files exist on disk. Both commits (48f84a3, 0f0ec39) verified in git log.

## Decisions Made

1. `BootstrapperTestKernel` uses `shared_db` driver — DoctrineBootstrapper targets the default EM which is correct in both modes
2. Environment-aware SQLite path prevents DB collision between kernel instances in same test run
3. Anonymous `TenantInterface` stubs for cache tests — only `getSlug()` required; simpler than persisting Tenant entities
4. Separate kernel environment (`cache_test`) for cache integration tests to ensure isolated cache dir and DB file
