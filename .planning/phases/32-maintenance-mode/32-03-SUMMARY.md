---
phase: 32-maintenance-mode
plan: "03"
subsystem: maintenance-mode
tags: [maintenance, commands, cli, cache-invalidation, psr-cache, doctrine, event-dispatch]
dependency_graph:
  requires:
    - phase: 32-01
      provides: TenantInterface::isInMaintenance() bool contract, TenantMaintenanceEnabled/Disabled events
  provides:
    - TenantMaintenanceEnableCommand (tenancy:maintenance:enable) — idempotent, cache-invalidating
    - TenantMaintenanceDisableCommand (tenancy:maintenance:disable) — idempotent, cache-invalidating
    - TenantMaintenanceStatusCommand (tenancy:maintenance:status) — table + --format=json
  affects:
    - 32-04 (DI wiring — services.php registration + landlord-EM rewire for enable/disable commands)
tech_stack:
  added: []
  patterns:
    - Landlord-EM-only command pattern (no TenantContext, no BootstrapperChain, no findBySlug)
    - PSR cache key deletion after flush (cache-coherence correctness: tenancy.tenant.<slug>)
    - Idempotent guard before flush/cache/event (D-08 pattern)
    - findAll() bypass for operator read (DoctrineTenantProvider cache-safe path)
    - Concrete anonymous stub in unit tests for setInMaintenance() (not on TenantInterface)
key_files:
  created:
    - src/Command/TenantMaintenanceEnableCommand.php
    - src/Command/TenantMaintenanceDisableCommand.php
    - src/Command/TenantMaintenanceStatusCommand.php
    - tests/Unit/Command/TenantMaintenanceEnableCommandTest.php
    - tests/Unit/Command/TenantMaintenanceDisableCommandTest.php
    - tests/Unit/Command/TenantMaintenanceStatusCommandTest.php
  modified: []
key_decisions:
  - "setInMaintenance() is not on TenantInterface — commands use method_exists() guard (consistent with TenantAwareFilesystemDecorator pattern) to surface a clear error if the entity class doesn't implement it"
  - "Test stubs use concrete anonymous classes (not mocks) for setInMaintenance() since PHPUnit cannot mock non-interface methods"
  - "TenantMaintenanceStatusCommand injects TenantProviderInterface as nullable (nullOnInvalid for no-Doctrine lane) with a clear error message if null"
  - "Status command table uses Slug + Name columns (findAll() returns TenantInterface which has getName())"

requirements-completed: [MAINT-01, MAINT-02, MAINT-08, MAINT-09]

duration: ~15min
completed: "2026-07-01"
---

# Phase 32 Plan 03: Maintenance Commands Summary

Three maintenance CLI commands with idempotent landlord-side writes, immediate PSR cache invalidation, and event-on-transition dispatch; 16 unit tests green.

## Performance

- **Duration:** ~15 min
- **Started:** 2026-07-01T13:20:00Z
- **Completed:** 2026-07-01T13:35:47Z
- **Tasks:** 2
- **Files modified:** 6 created

## Accomplishments

- `tenancy:maintenance:enable <slug>`: fetches fresh via landlord EM repository (bypassing PSR cache and isActive() gate), idempotent guard, flush → `cache.delete('tenancy.tenant.<slug>')` → `dispatch(TenantMaintenanceEnabled)` → SUCCESS. The cache delete is the load-bearing correctness requirement — without it, maintenance state has a 5-minute propagation delay.
- `tenancy:maintenance:disable <slug>`: exact mirror; dispatches `TenantMaintenanceDisabled` on real transition.
- `tenancy:maintenance:status`: calls `findAll()` (cache-bypassing operator path), filters `isInMaintenance()`, outputs table or `--format=json` aggregate `{"tenants":[...],"total":N}` with the same `json_encode` flags as `TenantMigrateCommand`.
- 16 unit tests across all three commands: real-transition (flush+cache-delete+event), idempotent (no-op), unknown-slug (FAILURE), JSON total correctness, findAll-not-findBySlug structural guard.

## Task Commits

| Task | Description | Commit | Files |
|------|-------------|--------|-------|
| 1 | enable + disable commands (idempotent, cache-invalidating, event-on-transition) | 10971ff | src/Command/TenantMaintenanceEnableCommand.php, TenantMaintenanceDisableCommand.php, 2 test files |
| 2 | status command + all three command unit tests | 9968fa4 | src/Command/TenantMaintenanceStatusCommand.php, TenantMaintenanceStatusCommandTest.php |

## Service IDs and Constructor Argument Order (for plan 32-04 wiring)

This is the required output per plan `<output>` spec:

| Service ID | Class | Constructor Args (in order) |
|------------|-------|-----------------------------|
| `tenancy.command.maintenance.enable` | `TenantMaintenanceEnableCommand` | 0: `EntityManagerInterface $landlordEm`, 1: `string $tenantEntityClass`, 2: `CacheInterface $cache`, 3: `EventDispatcherInterface $eventDispatcher` |
| `tenancy.command.maintenance.disable` | `TenantMaintenanceDisableCommand` | 0: `EntityManagerInterface $landlordEm`, 1: `string $tenantEntityClass`, 2: `CacheInterface $cache`, 3: `EventDispatcherInterface $eventDispatcher` |
| `tenancy.command.maintenance.status` | `TenantMaintenanceStatusCommand` | 0: `?TenantProviderInterface $tenantProvider` (nullOnInvalid) |

**Arg 0 is the landlord EM** for enable/disable. When `database.enabled: true`, plan 32-04 rewires arg 0 to `doctrine.orm.landlord_entity_manager` (same pattern as `tenancy.provider` rewire at `TenancyBundle::loadExtension()` line 251).

The status command uses `tenancy.provider` (TenantProviderInterface) which is also arg 0 — it does NOT need an EM rewire since `findAll()` goes through the provider's own EM reference.

## Files Created/Modified

- `src/Command/TenantMaintenanceEnableCommand.php` — `tenancy:maintenance:enable`; landlord EM fetch + idempotent guard + flush + cache.delete + dispatch(TenantMaintenanceEnabled)
- `src/Command/TenantMaintenanceDisableCommand.php` — `tenancy:maintenance:disable`; mirror for disable direction
- `src/Command/TenantMaintenanceStatusCommand.php` — `tenancy:maintenance:status`; findAll() + filter + table/json
- `tests/Unit/Command/TenantMaintenanceEnableCommandTest.php` — 5 tests covering all branches
- `tests/Unit/Command/TenantMaintenanceDisableCommandTest.php` — 5 tests (mirror)
- `tests/Unit/Command/TenantMaintenanceStatusCommandTest.php` — 6 tests covering table/json/empty cases

## Decisions Made

- `setInMaintenance()` is not on `TenantInterface` (it's on the trait / `AbstractTenant`). Commands use `method_exists()` guard before calling it, consistent with `TenantAwareFilesystemDecorator:273`. PHPStan level 9 requires the `@var TenantInterface|null` annotation on the repository result to type-check the `isInMaintenance()` call cleanly.
- Unit tests use concrete anonymous stub classes (not mocks) for `setInMaintenance()` since PHPUnit cannot mock methods not declared on the interface. The stub tracks `$setInMaintenanceCallCount` for assertion.
- `TenantMaintenanceStatusCommand` injects `?TenantProviderInterface` (nullable) for the no-Doctrine lane (plan 32-04 will wire `service('tenancy.provider')->nullOnInvalid()`). Returns `Command::FAILURE` with a clear message if null.

## Deviations from Plan

None — plan executed exactly as written.

The `setInMaintenance()`-not-on-interface issue was anticipated in the plan (the plan mentions "setInMaintenance is on AbstractTenant / the trait") and the `method_exists()` guard + concrete test stub is consistent with existing codebase patterns.

## Known Stubs

None — commands write to the real DB/cache (mocked in tests). No placeholder data flows to output.

## Threat Flags

None — all STRIDE mitigations from plan threat model applied:
- T-32-09 (stale PSR cache): mitigated by `$this->cache->delete('tenancy.tenant.'.$slug)` after flush, asserted in tests
- T-32-10 (duplicate events): mitigated by idempotent guard (events dispatched only on real bool transition)
- T-32-11 (TenantInactiveException): mitigated by `findOneBy()` instead of `findBySlug()`
- T-32-12 (tenant context boot): mitigated by not injecting TenantContext or BootstrapperChain
- T-32-13 (malicious slug): mitigated by Doctrine parameterized query + cache key suffix only

## Self-Check: PASSED
