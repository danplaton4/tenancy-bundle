---
phase: 25
slug: shared-entities-sync-mode
status: verified
nyquist_compliant: true
wave_0_complete: true
created: 2026-06-11
updated: 2026-06-12
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
| SHARE-01-a | SHARE-01 | — | `#[Shared]` is a bare class-target PHP attribute (no params) | unit | `vendor/bin/phpunit --filter testSharedAttributeIsClassTarget` | ✅ | ✅ green |
| SHARE-01-b | SHARE-01 | T-25-04 | Subscriber wired as `onFlush`+`postFlush` listener on **landlord** EM only | integration | `vendor/bin/phpunit --filter testSubscriberWiredToLandlordEm` | ✅ | ✅ green |
| SHARE-01-c | SHARE-01 | — | Landlord `#[Shared]` **insert** fans out to all tenant EMs | integration | `vendor/bin/phpunit --filter testInsertFansOutToAllTenants` | ✅ | ✅ green |
| SHARE-01-d | SHARE-01 | — | Landlord `#[Shared]` **update** fans out to all tenant EMs | integration | `vendor/bin/phpunit --filter testUpdateFansOutToAllTenants` | ✅ | ✅ green |
| SHARE-01-e | SHARE-01 | — | Landlord `#[Shared]` **delete** propagates to tenant EMs | integration | `vendor/bin/phpunit --filter testDeleteFansOutToAllTenants` | ✅ | ✅ green |
| SHARE-01-f | SHARE-01 | T-25-01 | Tenant-side `persist()` of `#[Shared]` throws `SharedEntityWriteInTenantContextException` | integration | `vendor/bin/phpunit --filter testTenantSidePersistThrows` | ✅ | ✅ green |
| SHARE-01-g | SHARE-01 | T-25-01 | Tenant-side **update** of `#[Shared]` throws | integration | `vendor/bin/phpunit --filter testTenantSideUpdateThrows` | ✅ | ✅ green |
| SHARE-01-h | SHARE-01 | T-25-01 | Tenant-side **delete** of `#[Shared]` throws | integration | `vendor/bin/phpunit --filter testTenantSideDeleteThrows` | ✅ | ✅ green |
| SHARE-01-i | SHARE-01 | T-25-04 | Subscriber-initiated sync write **bypasses** the write-protection guard (re-entrancy flag) | integration | `vendor/bin/phpunit --filter testSyncWriteBypassesWriteProtection` | ✅ | ✅ green |
| SHARE-01-j | SHARE-01 | — | Subscriber is a **no-op** under `shared_db` driver — `postFlush` D-03 short-circuit fires **before** `findAll()` | unit + integration | `vendor/bin/phpunit --filter 'testSharedDbDriverShortCircuitsBeforeFindAll\|testNoOpUnderSharedDb'` | ✅ | ✅ green |
| SHARE-01-k | SHARE-01 | — | Per-tenant failure is caught + logged at **error** level (tenant slug + class + id + error), does **not** abort fan-out (D-01/D-07) | integration | `vendor/bin/phpunit --filter testPerTenantFailureIsLogged` | ✅ | ✅ green |
| SHARE-01-l | SHARE-01 | — | Compiler pass throws when `#[Shared]` + `#[TenantAware]` co-present on one class | unit | `vendor/bin/phpunit --filter testMutualExclusionGuardThrows` | ✅ | ✅ green |
| SHARE-01-m | SHARE-01 | T-25-02 | Cascade depth = 1: association fields on `#[Shared]` entity are **NOT** synced | integration | `vendor/bin/phpunit --filter testAssociationsNotSynced` | ✅ | ✅ green |
| SHARE-01-n | SHARE-01 | T-25-03 | Fan-out triggered while a tenant is **active** restores the pre-fan-out tenant context + reconnects (CR-01/CR-02 regression) | integration | `vendor/bin/phpunit --filter testFanOutRestoresActiveTenantContext` | ✅ | ✅ green |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky · ❌ W0 = file created in Wave 0*

> **SHARE-01-n** is a supplementary behavior added post-execution: the Phase 25 code review (`25-REVIEW.md`) found a Critical tenant-context-wipe bug (CR-01) + stale-connection bug (CR-02) in the fan-out path; `testFanOutRestoresActiveTenantContext` pins the fix.
>
> **SHARE-01-j and SHARE-01-k** were upgraded during this validation pass (`/gsd:validate-phase`): the originals passed trivially (`testNoOpUnderSharedDb` never reached the runtime short-circuit; `testPerTenantFailureIsLogged` asserted `>= 0`). The unit test `testSharedDbDriverShortCircuitsBeforeFindAll` now exercises the D-03 branch directly with a spy provider, and `testPerTenantFailureIsLogged` now induces a real per-tenant failure (SQLite trigger) and asserts isolation + a structured `error` log via a recording logger.

---

## Wave 0 Requirements

All test files were NEW — no prior tests covered SHARE-01. PHPUnit 11 in place (no framework install). All delivered in Wave 0 (25-00) and exercised by Waves 1-3:

- [x] `tests/Integration/SharedEntity/SharedEntitySyncIntegrationTest.php` — covers SHARE-01-b…m + n (fan-out, write protection, logging, context restore)
- [x] `tests/Integration/SharedEntity/SharedEntityNoDatabaseKernelTest.php` — covers SHARE-01-j (no-op under shared_db, outcome level)
- [x] `tests/Integration/SharedEntity/Support/SharedEntitySyncTestKernel.php` — landlord + 2 tenant SQLite DBs
- [x] `tests/Integration/SharedEntity/Support/Entity/TestPlan.php` — `#[Shared]` entity, scalar fields only
- [x] `tests/Integration/SharedEntity/Support/Entity/TestPlanWithAssociation.php` — `#[Shared]` entity with association (cascade test, SHARE-01-m)
- [x] `tests/Unit/Attribute/SharedTest.php` — covers SHARE-01-a *(landed here, not `tests/Unit/Subscriber/` as the draft estimated)*
- [x] `tests/Unit/DependencyInjection/Compiler/SharedEntityMutualExclusionPassTest.php` — covers SHARE-01-l

**Added during validation (`/gsd:validate-phase`, 2026-06-12):**
- [x] `tests/Unit/Subscriber/SharedEntitySyncSubscriberSharedDbTest.php` — SHARE-01-j runtime short-circuit (D-03 branch exercised directly)
- [x] `tests/Integration/SharedEntity/Support/RecordingLogger.php` + `InjectRecordingLoggerPass.php` + `SharedEntityFailureLoggingTestKernel.php` — infra for SHARE-01-k structured-logging assertion

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| — | — | — | All phase behaviors have automated verification (integration tests use SQLite `:memory:`/file DBs — no external infra). |

---

## Validation Audit 2026-06-12

| Metric | Count |
|--------|-------|
| Behaviors audited | 14 (SHARE-01-a…n) |
| Covered (green) | 14 |
| Gaps found | 2 (SHARE-01-j, SHARE-01-k — PARTIAL/trivial) |
| Resolved | 2 |
| Escalated | 0 |

**Gaps closed:** `SHARE-01-j` (added `testSharedDbDriverShortCircuitsBeforeFindAll` unit test exercising the D-03 short-circuit with a spy provider) and `SHARE-01-k` (rewrote `testPerTenantFailureIsLogged` to induce a real per-tenant failure via SQLite trigger + assert isolation and a structured `error` log through a recording logger). Full suite: 708 tests / 3016 assertions / 0 failures / 1 pre-existing skip. PHPStan level 9 clean; php-cs-fixer clean. Auditor: gsd-nyquist-auditor.

---

## Validation Sign-Off

- [x] All behaviors have an `<automated>` verify or Wave 0 dependency
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references (test files above)
- [x] No watch-mode flags
- [x] Feedback latency < 40s
- [x] `nyquist_compliant: true` set in frontmatter (every behavior maps to a green automated test)

**Approval:** verified 2026-06-12
