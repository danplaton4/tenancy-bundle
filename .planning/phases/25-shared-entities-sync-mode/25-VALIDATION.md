---
phase: 25
slug: shared-entities-sync-mode
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-06-11
---

# Phase 25 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Derived from `25-RESEARCH.md` § Validation Architecture.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.5.55 |
| **Config file** | `phpunit.xml.dist` (root) |
| **Quick run command** | `vendor/bin/phpunit --testsuite unit` |
| **Full suite command** | `vendor/bin/phpunit` |
| **Integration suite** | `vendor/bin/phpunit --testsuite integration` |
| **Estimated runtime** | ~15 seconds (unit) / ~40 seconds (full) |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit --testsuite unit`
- **After every plan wave:** Run `vendor/bin/phpunit`
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** ~40 seconds

---

## Per-Task Verification Map

> Task IDs are populated during execution. This map is the requirement→behavior→test contract
> the planner's tasks must satisfy. Each row maps a SHARE-01 acceptance behavior to its test.

| Behavior ID | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|-------------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| SHARE-01-a | SHARE-01 | — | `#[Shared]` is a bare class-target PHP attribute (no params) | unit | `vendor/bin/phpunit --filter testSharedAttributeIsClassTarget` | ❌ W0 | ⬜ pending |
| SHARE-01-b | SHARE-01 | T-25-04 | Subscriber wired as `onFlush`+`postFlush` listener on **landlord** EM only | integration | `vendor/bin/phpunit --filter testSubscriberWiredToLandlordEm` | ❌ W0 | ⬜ pending |
| SHARE-01-c | SHARE-01 | — | Landlord `#[Shared]` **insert** fans out to all tenant EMs | integration | `vendor/bin/phpunit --filter testInsertFansOutToAllTenants` | ❌ W0 | ⬜ pending |
| SHARE-01-d | SHARE-01 | — | Landlord `#[Shared]` **update** fans out to all tenant EMs | integration | `vendor/bin/phpunit --filter testUpdateFansOutToAllTenants` | ❌ W0 | ⬜ pending |
| SHARE-01-e | SHARE-01 | — | Landlord `#[Shared]` **delete** propagates to tenant EMs | integration | `vendor/bin/phpunit --filter testDeleteFansOutToAllTenants` | ❌ W0 | ⬜ pending |
| SHARE-01-f | SHARE-01 | T-25-01 | Tenant-side `persist()` of `#[Shared]` throws `SharedEntityWriteInTenantContextException` | integration | `vendor/bin/phpunit --filter testTenantSidePersistThrows` | ❌ W0 | ⬜ pending |
| SHARE-01-g | SHARE-01 | T-25-01 | Tenant-side **update** of `#[Shared]` throws | integration | `vendor/bin/phpunit --filter testTenantSideUpdateThrows` | ❌ W0 | ⬜ pending |
| SHARE-01-h | SHARE-01 | T-25-01 | Tenant-side **delete** of `#[Shared]` throws | integration | `vendor/bin/phpunit --filter testTenantSideDeleteThrows` | ❌ W0 | ⬜ pending |
| SHARE-01-i | SHARE-01 | T-25-04 | Subscriber-initiated sync write **bypasses** the write-protection guard (re-entrancy flag) | integration | `vendor/bin/phpunit --filter testSyncWriteBypassesWriteProtection` | ❌ W0 | ⬜ pending |
| SHARE-01-j | SHARE-01 | — | Subscriber is a **no-op** under `shared_db` driver | integration | `vendor/bin/phpunit --filter testNoOpUnderSharedDb` | ❌ W0 | ⬜ pending |
| SHARE-01-k | SHARE-01 | — | Per-tenant failure is caught + logged (tenant slug + class + id + failure), does **not** abort fan-out | integration | `vendor/bin/phpunit --filter testPerTenantFailureIsLogged` | ❌ W0 | ⬜ pending |
| SHARE-01-l | SHARE-01 | — | Compiler pass throws when `#[Shared]` + `#[TenantAware]` co-present on one class | unit | `vendor/bin/phpunit --filter testMutualExclusionGuardThrows` | ❌ W0 | ⬜ pending |
| SHARE-01-m | SHARE-01 | T-25-02 | Cascade depth = 1: association fields on `#[Shared]` entity are **NOT** synced | integration | `vendor/bin/phpunit --filter testAssociationsNotSynced` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky · ❌ W0 = file created in Wave 0*

---

## Wave 0 Requirements

All test files are NEW — no existing tests cover SHARE-01. No framework install needed (PHPUnit 11 in place).

- [ ] `tests/Integration/SharedEntity/SharedEntitySyncIntegrationTest.php` — covers SHARE-01-c…k (fan-out, write protection, logging)
- [ ] `tests/Integration/SharedEntity/SharedEntityNoDatabaseKernelTest.php` — covers SHARE-01-j (no-op under shared_db)
- [ ] `tests/Integration/SharedEntity/Support/SharedEntitySyncTestKernel.php` — landlord + 2 tenant SQLite DBs (mirrors `DoctrineTestKernel`)
- [ ] `tests/Integration/SharedEntity/Support/Entity/TestPlan.php` — `#[Shared]` entity, scalar fields only
- [ ] `tests/Integration/SharedEntity/Support/Entity/TestPlanWithAssociation.php` — `#[Shared]` entity with association (cascade test, SHARE-01-m)
- [ ] `tests/Unit/Subscriber/SharedEntitySyncSubscriberTest.php` — covers SHARE-01-a
- [ ] `tests/Unit/DependencyInjection/Compiler/SharedEntityMutualExclusionPassTest.php` — covers SHARE-01-l

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| — | — | — | All phase behaviors have automated verification (integration tests use SQLite `:memory:`/file DBs — no external infra). |

---

## Validation Sign-Off

- [ ] All behaviors have an `<automated>` verify or Wave 0 dependency
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references (7 new test files above)
- [ ] No watch-mode flags
- [ ] Feedback latency < 40s
- [ ] `nyquist_compliant: true` set in frontmatter (after planner maps every behavior to a task)

**Approval:** pending
