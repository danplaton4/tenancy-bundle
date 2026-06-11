---
phase: 25-shared-entities-sync-mode
plan: "04"
subsystem: shared-entities
tags: [doctrine, event-listener, container-wiring, shared-db, database-per-tenant, bug-fix]

dependency_graph:
  requires: ["25-02", "25-03"]
  provides: ["SHARE-01 runtime wiring", "D-03 shared_db no-op documentation"]
  affects: ["src/TenancyBundle.php", "src/Subscriber/SharedEntitySyncSubscriber.php", "docs/user-guide/shared-db.md"]

tech_stack:
  added: []
  patterns:
    - "Doctrine event_listener tags with connection scoping (Pattern 6 — connection: landlord/tenant)"
    - "interface_exists guard for optional Doctrine dependency in bundle build()/loadExtension()"
    - "ID capture in onFlush before Doctrine zeroes identifiers in executeDeletions() (postFlush bug prevention)"
    - "DBAL connection close() before resetManager() for multi-tenant fan-out reconnection"

key_files:
  created: []
  modified:
    - src/TenancyBundle.php
    - src/Subscriber/SharedEntitySyncSubscriber.php
    - docs/user-guide/shared-db.md

decisions:
  - "A2 resolution confirmed: subscriber wiring placed in loadExtension() database.enabled block (not config/services.php) — only database_per_tenant path has landlord+tenant connections; config/services.php left untouched"
  - "DBAL connection close() required before resetManager() so each tenant fan-out reconnects through TenantAwareDriver::connect() with fresh tenant context"
  - "Entity identifier for deletions must be captured in onFlush (not postFlush) — Doctrine zeroes ID fields in executeDeletions() before postFlush fires"

metrics:
  duration: "~4 hours (continuation across two sessions)"
  completed_date: "2026-06-11"
  tasks_completed: 2
  files_modified: 3
---

# Phase 25 Plan 04: Container Wiring + Full SHARE-01 Suite GREEN + shared_db Docs Summary

Container wiring for SharedEntitySyncSubscriber (landlord onFlush+postFlush) and SharedEntityWriteProtectionListener (tenant onFlush) inside the `database.enabled` block of `TenancyBundle::loadExtension()`, with two bug fixes (DBAL reconnection and Doctrine identifier zeroing) that made the DELETE fan-out and multi-tenant fan-out work correctly. All 11 SharedEntity integration tests GREEN (was 10 skip-guarded before this plan). Full suite: 705 tests / 2998 assertions / 1 pre-existing skip.

## What Was Built

### Task 1: Container Wiring + Bug Fixes

**TenancyBundle.php changes:**

- `build()`: Added `SharedEntityMutualExclusionPass` registration inside `interface_exists(\Doctrine\ORM\EntityManagerInterface::class)` guard (D-04 live). Added 3 `use` imports alphabetically: `SharedEntityMutualExclusionPass`, `SharedEntitySyncSubscriber`, `SharedEntityWriteProtectionListener`.

- `loadExtension()` database.enabled block: Registered two connection-scoped services:
  - `tenancy.shared_entity_sync_subscriber` → `SharedEntitySyncSubscriber` with args `[TenantContext, TenantProviderInterface, ManagerRegistry, LoggerInterface, param(tenancy.driver)]`, tagged `doctrine.event_listener` twice: `['event' => 'onFlush', 'connection' => 'landlord']` and `['event' => 'postFlush', 'connection' => 'landlord']`
  - `tenancy.shared_entity_write_protection` → `SharedEntityWriteProtectionListener` with args `[TenantContext, SharedEntitySyncSubscriber]`, tagged `doctrine.event_listener` with `['event' => 'onFlush', 'connection' => 'tenant']`
  - No `->autoconfigure()` on either (Pattern 7)
  - Both wrapped in `interface_exists(\Doctrine\ORM\EntityManagerInterface::class)` guard

**A2 resolution placement:** Both services live ONLY in the `database.enabled` block. Under `shared_db`, `database.enabled = false`, so neither service is registered — no missing-connection error is possible. `config/services.php` was NOT modified (it runs in both driver modes; `connection: landlord` tag there would surface under shared_db).

**SharedEntitySyncSubscriber.php bug fixes (Rule 1 — auto-fixed):**

**Bug 1 — DBAL connection not reset between tenants:**
- Symptom: multi-tenant fan-out (tenant_a then tenant_b) reused tenant_a's open socket when writing to tenant_b's EM.
- Root cause: `resetManager('tenant')` resets the Doctrine EntityManager as a lazy ghost but does NOT close the underlying DBAL connection socket. `TenantAwareDriver::connect()` is only called when the connection handle is null — it remains non-null after resetManager.
- Fix: Added `$tenantConn->close()` before `resetManager('tenant')` in `fanOutToTenant()`. The close() nulls the DBAL internal socket; the next query reconnects via `TenantAwareDriver::connect()` which reads `TenantContext` for the current tenant's params.

**Bug 2 — Doctrine zeroes entity identifier before postFlush:**
- Symptom: DELETE fan-out threw `MissingIdentifierField` (empty `$ids` passed to `$tenantEm->find()`).
- Root cause: `UnitOfWork::executeDeletions()` (line 1180) sets `$class->propertyAccessors[$class->identifier[0]]->setValue($entity, null)` BEFORE `postFlush` fires. `getIdentifierValues()` returns `[]` in `postFlush`.
- Fix: Capture entity identifiers in `onFlush()` for all deletion-scheduled entities while the IDs are still set. Store as `'ids' => $ids` in `$pendingChanges` array. Pass `$capturedIds` through `postFlush` → `fanOutToTenant` → `doSync`. In `doSync`, use captured IDs for the delete path: `$ids = 'delete' === $type ? ($capturedIds ?? ...) : ...`. Also use captured IDs in the `catch` block warning log.
- Updated `$pendingChanges` type annotation: `array<int, array{entity: object, type: 'insert'|'update'|'delete', ids?: array<string, mixed>}>`.

### Task 2: Shared-DB Documentation

Added "Shared Entities (`#[Shared]`) Under `shared_db`" section to `docs/user-guide/shared-db.md`:
- Documents D-03: `SharedEntitySyncSubscriber` no-ops immediately in `postFlush()` when `driver: shared_db`
- Explains why: no per-tenant EntityManagers to fan out to under shared_db
- Notes `#[Shared]` is silently harmless under shared_db (attribute ignored)
- Migration note: switching to `database_per_tenant` activates sync automatically
- Documents `tenancy.shared_entity` tag requirement for `SharedEntityMutualExclusionPass` compile-time guard

## SHARE-01 Suite Results

| Before 25-04 | After 25-04 |
|---|---|
| 10 skip-guarded integration tests | 11 integration tests GREEN |
| Container wiring absent | Container wiring live |
| DELETE fan-out broken (MissingIdentifierField) | DELETE fan-out working |
| Multi-tenant fan-out broken (wrong socket) | Multi-tenant fan-out working |

Full suite: **705 tests / 2998 assertions / 1 pre-existing skip** (the 1 skip is pre-existing, unrelated to SHARE-01).

Integration suite: **144 tests / 1536 assertions / 0 skips**.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed DBAL connection not reset between tenant fan-outs**
- Found during: Task 1 (pre-commit integration test failure on `testDeleteFansOutToAllTenants`)
- Issue: `resetManager('tenant')` resets the ORM EntityManager but leaves the DBAL socket open; subsequent fan-out to tenant_b reused tenant_a's connection
- Fix: Added `$tenantConn->close()` before `$this->registry->resetManager('tenant')` in `fanOutToTenant()`
- Files modified: `src/Subscriber/SharedEntitySyncSubscriber.php`
- Commit: `dd882ce`

**2. [Rule 1 - Bug] Fixed Doctrine identifier zeroing before postFlush**
- Found during: Task 1 (pre-commit integration test failure on `testDeleteFansOutToAllTenants`)
- Issue: Doctrine ORM zeros entity identifier fields in `executeDeletions()` (before `postFlush` fires); `getIdentifierValues()` returns `[]`, causing empty `$ids` passed to `$tenantEm->find()` → `MissingIdentifierField` exception silently swallowed
- Fix: Capture entity identifiers in `onFlush()` for deletions; pass `$capturedIds` through the fan-out call chain; use captured IDs in `doSync` delete path and catch block
- Files modified: `src/Subscriber/SharedEntitySyncSubscriber.php`
- Commit: `dd882ce`

**3. [Rule 1 - Bug] Docblock style fixes for cs-fixer (@Symfony ruleset)**
- Found during: Task 1 (cs-fixer pre-commit hook)
- Issue: `@param` docblock tags had capitalized first word and trailing period — @Symfony ruleset requires lowercase first word, no trailing period
- Fix: Updated two `@param` docblock annotations in `SharedEntitySyncSubscriber`
- Files modified: `src/Subscriber/SharedEntitySyncSubscriber.php`
- Commit: `dd882ce` (fixed inline before commit)

## Wiring Placement Decision

Subscriber wiring placed in `TenancyBundle::loadExtension()` inside the `if ($databaseConfig['enabled'] ?? false)` block (the `database_per_tenant` path). Rationale:

- Only `database_per_tenant` creates `landlord`/`tenant` DBAL connections. A `connection: landlord` tag references a non-existent connection under `shared_db` — wiring there would cause a container compile error.
- `config/services.php` runs in BOTH driver modes — placing connection-scoped tags there is the A2 risk explicitly identified in the plan. Left untouched.
- Mirrors existing precedent: `TenantDriverMiddleware` is registered with `['connection' => 'tenant']` inside the same block (TenancyBundle.php lines 208-254).

## Threat Coverage

| Threat | Mitigation | Status |
|--------|-----------|--------|
| T-25-04: subscriber fires on wrong EM | `connection: landlord` on sync tags; `connection: tenant` on guard tag; `testSubscriberWiredToLandlordEm` GREEN | MITIGATED |
| T-25-01: entity with both #[Shared]+#[TenantAware] | `SharedEntityMutualExclusionPass` registered in `build()` | MITIGATED |

## Known Stubs

None — all integration tests exercise live container-wired services with real SQLite databases.

## Self-Check: PASSED

- `src/TenancyBundle.php` exists and contains `SharedEntityMutualExclusionPass` registration
- `src/Subscriber/SharedEntitySyncSubscriber.php` exists with `capturedIds` fix
- `docs/user-guide/shared-db.md` contains "Shared Entities" section with `#[Shared]` and `tenancy.shared_entity`
- Commits `dd882ce`, `ae8a7d0`, `7e58281` verified in git log
- 705 tests / 2998 assertions / 1 pre-existing skip
