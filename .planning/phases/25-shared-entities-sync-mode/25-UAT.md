---
status: complete
phase: 25-shared-entities-sync-mode
source: [25-00-SUMMARY.md, 25-01-SUMMARY.md, 25-02-SUMMARY.md, 25-03-SUMMARY.md, 25-04-SUMMARY.md]
started: 2026-06-12T11:34:08Z
updated: 2026-06-12T11:40:00Z
---

## Current Test

[testing complete]

## Tests

### 1. Bundle boots & shared-entity services wire up
expected: With driver: database_per_tenant + database.enabled: true, the kernel boots with no container errors; tenancy.shared_entity_sync_subscriber (landlord onFlush+postFlush) and tenancy.shared_entity_write_protection (tenant onFlush) are registered and connection-scoped. Observe via `vendor/bin/phpunit --filter testSubscriberWiredToLandlordEm`.
result: pass

### 2. Landlord #[Shared] insert fans out to every tenant
expected: Persisting a #[Shared] entity (e.g. TestPlan) on the landlord EM creates a matching row in every tenant DB with the same scalar field values. Observe via `--filter testInsertFansOutToAllTenants`.
result: pass

### 3. Landlord #[Shared] update and delete propagate to all tenants
expected: Updating the landlord master updates every tenant copy; removing it deletes every tenant copy. Observe via `--filter testUpdateFansOutToAllTenants` and `--filter testDeleteFansOutToAllTenants`.
result: pass

### 4. Tenant copy keeps the landlord primary key even when sequences diverge (CR-01)
expected: Even when a tenant DB's auto-increment sequence is ahead of the landlord's, the synced copy lands under the LANDLORD id (not the tenant's next value), so later landlord updates/deletes still resolve it — no duplicates, no silent no-ops. Observe via `--filter testSyncPreservesLandlordIdWhenTenantSequenceDiverges`.
result: pass

### 5. Tenant-side writes to a #[Shared] entity are blocked
expected: Persisting, updating, or deleting a #[Shared] entity while a tenant is active throws SharedEntityWriteInTenantContextException (extends \LogicException, no Messenger retry). Write to the landlord instead. Observe via `--filter testTenantSidePersistThrows` (+ Update/Delete variants).
result: pass

### 6. #[Shared] + #[TenantAware] on one class fails at compile time
expected: Container compilation throws \LogicException naming the offending class when a tagged entity carries both attributes — including when one is inherited from a base / mapped-superclass (hierarchy walk). Observe via `--filter SharedEntityMutualExclusionPassTest`.
result: pass

### 7. One tenant's failure does not abort fan-out and is logged
expected: If syncing to one tenant fails (e.g. its table is missing/blocked), the landlord request still succeeds, the OTHER tenants still receive the change, and an error is logged at error level with tenant_slug/entity_class/identifier/error. The active tenant context is restored after fan-out (CR-01/CR-02). Observe via `--filter testPerTenantFailureIsLogged` and `--filter testFanOutRestoresActiveTenantContext`.
result: pass

### 8. shared_db driver: #[Shared] is a documented no-op
expected: Under driver: shared_db there are no per-tenant EMs, so the subscriber never fans out and #[Shared] is silently harmless; switching to database_per_tenant activates sync automatically. docs/user-guide/shared-db.md documents this no-op AND the tenancy.shared_entity tag requirement for the mutual-exclusion guard. Observe via `--filter SharedEntityNoDatabaseKernelTest` + reading the doc section.
result: pass

## Summary

total: 8
passed: 8
issues: 0
pending: 0
skipped: 0
blocked: 0

## Gaps

[none yet]
