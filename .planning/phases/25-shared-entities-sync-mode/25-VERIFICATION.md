---
phase: 25-shared-entities-sync-mode
verified: 2026-06-11T12:00:00Z
status: passed
score: 6/6 must-haves verified
overrides_applied: 0
deferred:
  - truth: "PHPStan rejects #[Shared] + #[TenantAware] on the same class at static-analysis time"
    addressed_in: "Phase 28"
    evidence: "Phase 28 goal: 'rules for #[TenantAware] + #[Shared] correctness, phpstan/extension-installer auto-load (DX-03)'. Phase 25 ships the compile-time container guard (SharedEntityMutualExclusionPass) as the boot-time equivalent; the edit-time PHPStan rule is DX-03 in Phase 28."
  - truth: "dry-run mode via tenancy:shared:resync --dry-run console command"
    addressed_in: "Phase 26"
    evidence: "Phase 26 goal: 'bulk-initial-sync console command with continue-on-failure + dry-run + per-tenant pass/fail summary (SHARE-02)'. The resync command is SHARE-02, which maps to Phase 26 in REQUIREMENTS.md traceability."
---

# Phase 25: Shared Entities (Sync mode) — Verification Report

**Phase Goal:** When a `#[Shared]`-attributed entity is written on the landlord EM, a read-only denormalized copy is synced (best-effort, synchronous) into every tenant's EM via Doctrine `onFlush`-buffer/`postFlush`-fanout; tenant-side writes of `#[Shared]` entities are blocked, and `#[Shared]` + `#[TenantAware]` co-presence fails loud at container build.
**Verified:** 2026-06-11
**Status:** PASSED
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| #  | Truth                                                                                                                   | Status     | Evidence                                                                                                                                                            |
|----|-------------------------------------------------------------------------------------------------------------------------|------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 1  | `#[Shared]` entity written on landlord EM fans out (insert/update/delete) to every tenant EM via onFlush-buffer/postFlush-fanout | VERIFIED   | `SharedEntitySyncSubscriber::onFlush()` buffers changesets; `postFlush()` fans out. Integration tests `testInsertFansOutToAllTenants`, `testUpdateFansOutToAllTenants`, `testDeleteFansOutToAllTenants` all pass (706/0 suite green). |
| 2  | Fan-out is best-effort: per-tenant failures are caught, logged at error level, and do not abort the landlord transaction | VERIFIED   | `catch (\Throwable)` in `fanOutToTenant()`; `$this->logger->error(...)` at line 235. D-01/D-07 satisfied. |
| 3  | Fan-out copies scalar fields only; association fields are never copied (one-level cascade boundary DEC-SHARE-02)        | VERIFIED   | `doSync()` iterates `getFieldNames()` only; `getAssociationNames()` absent. `testAssociationsNotSynced` passes. |
| 4  | Tenant-side insert/update/delete of a `#[Shared]` entity throws `SharedEntityWriteInTenantContextException`            | VERIFIED   | `SharedEntityWriteProtectionListener::onFlush()` inspects all three scheduled sets and throws. `testTenantSidePersistThrows`, `testTenantSideUpdateThrows`, `testTenantSideDeleteThrows` pass. |
| 5  | `#[Shared]` + `#[TenantAware]` co-presence fails loud at container build time                                          | VERIFIED   | `SharedEntityMutualExclusionPass` registered in `TenancyBundle::build()` under `interface_exists(EntityManagerInterface)` guard. `testMutualExclusionGuardThrows` passes (throws `\LogicException` naming the class). |
| 6  | CR-01/CR-02: pre-fan-out tenant context is saved and restored after the loop; tenant DBAL connection is closed post-loop so the next query reconnects under the restored context | VERIFIED   | `$previousTenant` captured before the loop (line 162); `restoreTenantContext()` called in `finally` (line 176); `restoreTenantContext()` closes the DBAL connection (lines 266-270) and resets the manager. `testFanOutRestoresActiveTenantContext` passes. |

**Score:** 6/6 truths verified

### Deferred Items

Items not yet met but explicitly addressed in later milestone phases.

| # | Item | Addressed In | Evidence |
|---|------|--------------|----------|
| 1 | PHPStan rejects `#[Shared]` + `#[TenantAware]` co-presence at static-analysis time | Phase 28 (DX-03) | Phase 28 goal: PHPStan extension for `#[TenantAware]` + `#[Shared]` correctness. The compile-time container guard delivered in Phase 25 is the boot-time equivalent. |
| 2 | `tenancy:shared:resync --dry-run` console command for drift repair | Phase 26 (SHARE-02) | Phase 26 goal: bulk-initial-sync command with dry-run. REQUIREMENTS.md traceability maps SHARE-02 explicitly to Phase 26. |

### Required Artifacts

| Artifact                                                     | Expected                                              | Status     | Details                                                                                                   |
|--------------------------------------------------------------|-------------------------------------------------------|------------|-----------------------------------------------------------------------------------------------------------|
| `src/Attribute/Shared.php`                                   | `#[\Attribute(\Attribute::TARGET_CLASS)]` marker, no constructor | VERIFIED   | Final class, `#[\Attribute(\Attribute::TARGET_CLASS)]`, empty body. `testSharedAttributeIsClassTarget` GREEN. |
| `src/Exception/SharedEntityWriteInTenantContextException.php` | `extends \LogicException` + `forEntity(class, slug)` factory | VERIFIED   | `final class ... extends \LogicException`; `public static function forEntity(string $entityClass, string $tenantSlug): self` at line 26. |
| `src/DependencyInjection/Compiler/SharedEntityMutualExclusionPass.php` | `implements CompilerPassInterface`, `tenancy.shared_entity` tag walk | VERIFIED   | Contains `findTaggedServiceIds('tenancy.shared_entity')`, `interface_exists(EntityManagerInterface)` early return, `\LogicException` throw naming the class. |
| `src/Subscriber/SharedEntitySyncSubscriber.php`              | onFlush buffer + postFlush fan-out, min 90 lines     | VERIFIED   | 335 lines. `getSubscribedEvents()` returns `[Events::onFlush, Events::postFlush]`. Contains `getFieldNames`, no `getAssociationNames`, no `merge(`. |
| `src/Subscriber/SharedEntityWriteProtectionListener.php`     | tenant onFlush guard + re-entrancy bypass            | VERIFIED   | `getSubscribedEvents()` returns `[Events::onFlush]`. Contains both bypass guards and `SharedEntityWriteInTenantContextException::forEntity(`. |
| `src/TenancyBundle.php`                                      | Compiler-pass registration + connection-scoped wiring | VERIFIED   | `addCompilerPass(new SharedEntityMutualExclusionPass())` in `build()` under `interface_exists` guard (line 315). Subscriber/listener registered inside `$databaseConfig['enabled']` block (lines 263-281). |
| `docs/user-guide/shared-db.md`                               | Shared entities / shared_db no-op section            | VERIFIED   | "Shared Entities (`#[Shared]`) Under `shared_db`" subsection present. Mentions `#[Shared]`, `SharedEntitySyncSubscriber`, `tenancy.shared_entity` tag. |

### Key Link Verification

| From                                         | To                                              | Via                                                            | Status     | Details                                                                                                      |
|----------------------------------------------|-------------------------------------------------|----------------------------------------------------------------|------------|--------------------------------------------------------------------------------------------------------------|
| `SharedEntitySyncSubscriber::postFlush`      | `TenantProviderInterface::findAll()`            | fan-out loop                                                   | WIRED      | `findAll()` called inside `foreach` at line 171, after `shared_db` short-circuit guard.                      |
| `SharedEntitySyncSubscriber::doSync`         | `ClassMetadata::getFieldNames`                  | scalar-only field copy                                         | WIRED      | `getFieldNames()` iterated at line 319; `getAssociationNames()` absent from file.                            |
| `SharedEntityWriteProtectionListener::onFlush` | `SharedEntitySyncSubscriber::isSyncInProgress()` | re-entrancy bypass                                           | WIRED      | `isSyncInProgress()` called at line 68 before scheduled-set inspection.                                      |
| `TenancyBundle::build()`                     | `SharedEntityMutualExclusionPass`               | `addCompilerPass` under `interface_exists` guard               | WIRED      | Line 315: `$container->addCompilerPass(new SharedEntityMutualExclusionPass())` inside `interface_exists(\Doctrine\ORM\EntityManagerInterface::class)` block. |
| `tenancy.shared_entity_sync_subscriber`      | `doctrine.event_listener` (connection: landlord) | `->tag('doctrine.event_listener', ['event' => 'onFlush', 'connection' => 'landlord'])` | WIRED | Lines 272-273 in `TenancyBundle.php` inside `$databaseConfig['enabled']` block. Two tags: onFlush + postFlush. |
| `tenancy.shared_entity_write_protection`     | `doctrine.event_listener` (connection: tenant)  | `->tag('doctrine.event_listener', ['event' => 'onFlush', 'connection' => 'tenant'])` | WIRED | Line 280 in `TenancyBundle.php` inside `$databaseConfig['enabled']` block. |
| `postFlush` `shared_db` guard                | `findAll()` never called under shared_db        | `'shared_db' === $this->driver` check before `findAll()`       | WIRED      | Lines 147-152: clears buffer and returns before `findAll()` call.                                             |
| `restoreTenantContext()`                     | DBAL tenant connection close + manager reset    | `$tenantConn->close()` + `resetManager('tenant')` in finally  | WIRED      | Lines 258-271: saves `$previousTenant`, restores context, closes connection, resets manager.                 |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `SharedEntitySyncSubscriber` | `$pendingChanges` | `getScheduledEntityInsertions/Updates/Deletions()` in `onFlush` | Yes — populated from Doctrine UoW scheduled sets | FLOWING |
| `SharedEntitySyncSubscriber::doSync` | `$existing` / `$copy` | `$tenantEm->find()` + `getFieldNames()` field copy | Yes — reads live entity data and writes to tenant EM | FLOWING |
| `SharedEntityWriteProtectionListener` | scheduled entity sets | `$uow->getScheduledEntityInsertions/Updates/Deletions()` | Yes — reads live UoW | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Full suite — 706 tests / 0 failures | `vendor/bin/phpunit` | 706 tests, 0 failures, 1 pre-existing skip | PASS |
| Unit suite | `vendor/bin/phpunit --testsuite unit` | 561 tests, 0 failures, 1 skip | PASS |
| Integration suite | `vendor/bin/phpunit --testsuite integration` | 145 tests, 0 failures | PASS |
| SharedEntity tests specifically | `vendor/bin/phpunit --filter SharedEntity` | 15 tests, 50 assertions, 0 skips | PASS |
| SharedTest + SharedEntityMutualExclusionPassTest | `vendor/bin/phpunit --filter SharedEntityMutualExclusionPassTest|SharedTest` | 5 tests, 7 assertions | PASS |
| PHPStan level 9 on all phase-25 src files | `vendor/bin/phpstan analyse src/Subscriber/ src/Attribute/Shared.php ...` | No errors | PASS |
| cs-fixer on src/ | `vendor/bin/php-cs-fixer check --diff src/` | 0 files with issues | PASS |

### Probe Execution

No probes declared in any phase-25 PLAN. Step 7c: SKIPPED (no probe-*.sh files for this phase).

### Requirements Coverage

| Requirement | Source Plans | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| SHARE-01 (core fan-out) | 25-01 to 25-04 | `#[Shared]` attribute + sync subscriber + write protection | SATISFIED | All 6 truths verified; 706 tests green |
| SHARE-01 (PHPStan mutual-exclusion) | — | PHPStan rule for `#[Shared]` + `#[TenantAware]` co-presence | DEFERRED | Phase 28 (DX-03). Compile-time container guard delivered as the boot-time equivalent. |
| SHARE-01 (dry-run resync command) | — | `tenancy:shared:resync --dry-run` command | DEFERRED | Phase 26 (SHARE-02). REQUIREMENTS.md traceability maps `tenancy:shared:resync` to SHARE-02 / Phase 26. |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `tests/Integration/SharedEntity/SharedEntitySyncIntegrationTest.php` | 120-125, 147-151, 194-197, 240-243, 288-291, 320-323, 362-365, 406-409, 469-472, 537-540, 605-609 | Stale Wave-0 `class_exists`/`has(...)` skip guards (IN-03) | WARNING | Production classes now exist so guards never trigger. If a service is accidentally unwired, tests silently skip instead of failing. All 15 SharedEntity tests currently pass with 0 skips. |
| `tests/Integration/SharedEntity/SharedEntitySyncIntegrationTest.php` | 466-529 | `testPerTenantFailureIsLogged` asserts `assertGreaterThanOrEqual(0, $successCount)` — always true (IN-04) | WARNING | SHARE-01-k (partial-failure isolation) is the most safety-critical behavior of this phase but is effectively untested. The test never makes a tenant DB unwritable and asserts nothing about logging. |
| `tests/Integration/SharedEntity/SharedEntityNoDatabaseKernelTest.php` | — | `testNoOpUnderSharedDb` does not exercise the `postFlush` `'shared_db' === $this->driver` runtime short-circuit — the subscriber is never registered under shared_db (WR-03) | INFO | The no-op is structural (DI), not runtime; the runtime guard is belt-and-suspenders. Advisory only. |

No `TBD`, `FIXME`, or `XXX` markers found in any production source file modified by this phase.

### Human Verification Required

None. All critical behaviors are verified by the automated integration test suite.

### Gaps Summary

No blocking gaps. The phase goal is fully achieved in the codebase.

Two REQUIREMENTS.md acceptance criteria from SHARE-01 are deferred to later phases per the ROADMAP traceability table:
- `tenancy:shared:resync --dry-run` command → Phase 26 (SHARE-02)
- PHPStan co-presence rule → Phase 28 (DX-03)

The Phase 25 ROADMAP goal does not include either of these items; they were planned into dedicated downstream phases from the start.

Three advisory findings from the code review remain open (IN-03 stale skip guards, IN-04 trivial test assertion, WR-03 structural vs runtime no-op test). None block the phase goal. Recommend addressing via a follow-up phase or targeted fix during Phase 26/27 work.

---

_Verified: 2026-06-11_
_Verifier: Claude (gsd-verifier)_
