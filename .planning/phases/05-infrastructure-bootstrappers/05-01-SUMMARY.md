---
phase: 05-infrastructure-bootstrappers
plan: "01"
subsystem: bootstrapper
tags: [doctrine, identity-map, entity-manager, bootstrapper, bug-fix, tdd]
dependency_graph:
  requires: []
  provides: [DoctrineBootstrapper, EntityManagerResetListener-fix]
  affects: [TenancyBundle, config/services.php, BootstrapperChain]
tech_stack:
  added: [DoctrineBootstrapper]
  patterns: [TDD red-green, TenantBootstrapperInterface, ManagerRegistry.resetManager()]
key_files:
  created:
    - src/Bootstrapper/DoctrineBootstrapper.php
    - tests/Unit/Bootstrapper/DoctrineBootstrapperTest.php
  modified:
    - src/EventListener/EntityManagerResetListener.php
    - tests/Unit/EventListener/EntityManagerResetListenerTest.php
    - config/services.php
    - src/TenancyBundle.php
decisions:
  - "DoctrineBootstrapper calls EntityManager::clear() in both boot() and clear() — prevents cross-tenant identity map pollution"
  - "EntityManagerResetListener::resetManager() called with no argument (null) not 'tenant' — works in both database_per_tenant and shared_db modes"
  - "EntityManagerResetListener moved to always-on registration in TenancyBundle (outside database.enabled block)"
  - "DoctrineBootstrapper tagged with priority -10 — runs after drivers on boot, before drivers on clear (BootstrapperChain reverses clear order)"
metrics:
  duration: "~2 min"
  completed_date: "2026-03-19"
  tasks_completed: 2
  files_modified: 6
---

# Phase 05 Plan 01: DoctrineBootstrapper + EntityManagerResetListener Fix Summary

**One-liner:** DoctrineBootstrapper clears EM identity map on every tenant switch; EntityManagerResetListener bug fixed to call resetManager() with null (default EM) instead of 'tenant' string.

## What Was Built

### DoctrineBootstrapper (new)

A new `TenantBootstrapperInterface` implementation that calls `EntityManager::clear()` in both `boot()` and `clear()` to prevent cross-tenant entity identity map pollution. Registered in DI with `priority -10` so it runs after isolation drivers on `boot()` and before them on `clear()` (BootstrapperChain reverses clear order).

### EntityManagerResetListener fix

The existing listener had `resetManager('tenant')` which fails in `shared_db` mode (no EM named 'tenant'). Fixed to `resetManager()` (no argument) which resets the default EM — correct in both driver modes. The listener registration was also moved from inside the `database.enabled` conditional block to always-on, ensuring it fires in both `database_per_tenant` and `shared_db` modes.

## Tasks Completed

| Task | Description | Commit | Files |
|------|-------------|--------|-------|
| 1 (TDD RED) | Failing tests for DoctrineBootstrapper + resetManager(null) | cee7f5d | DoctrineBootstrapperTest.php, EntityManagerResetListenerTest.php |
| 1 (TDD GREEN) | Implement DoctrineBootstrapper, fix EntityManagerResetListener | acba955 | DoctrineBootstrapper.php, EntityManagerResetListener.php |
| 2 | Register DoctrineBootstrapper in DI, make listener always-on | 280416b | config/services.php, TenancyBundle.php |

## Verification

- `vendor/bin/phpunit tests/Unit/Bootstrapper/ tests/Unit/EventListener/` — 24 tests, 53 assertions, all pass
- `grep -r "resetManager('tenant')" src/` — no matches (bug fully removed)
- `grep "DoctrineBootstrapper" config/services.php` — confirms DI registration with priority -10

## Deviations from Plan

None - plan executed exactly as written.

## Deferred Issues (out-of-scope pre-existing failures)

**TenantAwareCacheAdapterTest (4 failures):** Pre-existing PHPUnit 11 intersection mock return type failures in cache adapter tests. Confirmed pre-existing via git stash verification. Not caused by this plan's changes. Will be addressed in Plan 05-02.

## Self-Check: PASSED

Files exist:
- src/Bootstrapper/DoctrineBootstrapper.php — FOUND
- tests/Unit/Bootstrapper/DoctrineBootstrapperTest.php — FOUND
- src/EventListener/EntityManagerResetListener.php — FOUND (modified)

Commits exist:
- cee7f5d — FOUND (test RED phase)
- acba955 — FOUND (feat GREEN phase)
- 280416b — FOUND (feat DI wiring)
