---
phase: 26-tenancy-shared-resync-command
verified: 2026-06-13T00:00:00Z
status: human_needed
score: 12/12 must-haves verified
overrides_applied: 0
human_verification:
  - test: "Run `bin/console tenancy:shared:resync` in a real TTY against a dev fixture (at least one #[Shared] entity seeded on landlord)"
    expected: "Drift summary table renders correctly with tenant rows, Would-Insert/Would-Update/In-Sync columns, and the interactive [y/N] prompt appears with default-No behavior — pressing Enter aborts cleanly with SUCCESS exit"
    why_human: "CommandTester simulates input but does not exercise a live terminal; prompt copy, table formatting, and default-No UX are visual and TTY-dependent (SHARE-02-c per 26-VALIDATION.md Manual-Only section)"
---

# Phase 26: tenancy-shared-resync-command Verification Report

**Phase Goal:** A `tenancy:shared:resync [--tenant=<slug>] [--dry-run] [--force]` console command (mirroring `tenancy:migrate`) that enumerates all `#[Shared]` entity classes via landlord Doctrine metadata, classifies per-tenant drift (would-insert/would-update/in-sync), prints a summary, confirms before a live run, and idempotently upserts each row into the target tenant(s) via a shared `SharedEntityCopier` extracted from Phase 25 — the official drift-repair tool for the best-effort runtime fan-out; `shared_db` is an informational no-op.
**Verified:** 2026-06-13T00:00:00Z
**Status:** human_needed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|---------|
| 1 | tenancy:shared:resync command exists with --tenant, --dry-run, --force options | VERIFIED | `src/Command/SharedEntityResyncCommand.php` L21-55: `#[AsCommand(name: 'tenancy:shared:resync')]` with all three options configured |
| 2 | Enumerates #[Shared] classes via landlord Doctrine metadata (proxy-safe reflClass) | VERIFIED | `SharedEntityCopier::findSharedClasses()` iterates `getMetadataFactory()->getAllMetadata()`, filters by `$metadata->reflClass->getAttributes(Shared::class)`; unit test `testEnumeratesSharedClassesViaLandlordMetadata` green |
| 3 | Classifies per-tenant drift (would-insert / would-update / in-sync), read-only | VERIFIED | `SharedEntityCopier::classifyRow()` returns 'insert'/'update'/'in-sync' using only `$tenantEm->find()` (no persist/flush/newInstance); 3 unit tests cover all branches; integration test `testInSyncRowsNotCountedAsUpdate` green |
| 4 | --dry-run runs classify only (no flush), prints drift summary, exits SUCCESS | VERIFIED | Command L164-167: dry-run returns before confirm() and before any `applyRow()` call; `testDryRunNeverWrites` asserts `expects(never())->method('applyRow')` — green |
| 5 | Live run prints drift summary then confirm('Proceed?', false) default-No; --force skips; -n without --force aborts with SUCCESS | VERIFIED (partial) | Command L170-173: `!$isForce && !$io->confirm('Proceed with live resync?', false)` returns SUCCESS; `testLiveRunPromptsConfirmDefaultNoAbortsCleanly` (non-interactive = SUCCESS, no writes) green; `testForceSkipsConfirmation` green. TTY interactive rendering is HUMAN-ONLY (see human verification section) |
| 6 | shared_db driver is informational no-op exiting Command::SUCCESS | VERIFIED | Command L65-70: returns `Command::SUCCESS` with informational message; `testSharedDbDriverExitsSuccessWithNoOp` asserts SUCCESS and 'no-op' in display — green |
| 7 | Per-tenant try/catch/finally continue-on-failure with TenantContext + BootstrapperChain cleared | VERIFIED | Command L193-210: finally block clears both; `testContextAndBootstrapperClearedInFinally` and `testContinueOnFailureExitsFailureWhenAnyTenantFails` green |
| 8 | SharedEntityCopier is the single source of truth for upsert (extracted from Phase 25 subscriber) | VERIFIED | `src/Shared/SharedEntityCopier.php` L1-222: owns `applyRow`, `classifyRow`, `findSharedClasses`, `isShared`, `isSyncInProgress`; subscriber no longer declares `$syncInProgress` or `doSync()` — delegates to copier |
| 9 | WriteProtectionListener consults SharedEntityCopier::isSyncInProgress() (not subscriber) | VERIFIED | `SharedEntityWriteProtectionListener.php` L44,69: constructor takes `SharedEntityCopier $copier`, bypass calls `$this->copier->isSyncInProgress()` |
| 10 | Write-protection bypass works end-to-end (copier writes to tenant EM without exception) | VERIFIED | Integration test `testResyncWritesBypassWriteProtection`: runs CommandTester with `--force`, asserts Command::SUCCESS, asserts row found in both tenant DBs — 24 assertions, green |
| 11 | Idempotent re-run produces no duplicates; tenant copy id equals landlord master id (CR-01) | VERIFIED | Integration test `testResyncIsIdempotent`: two `--force` runs produce exactly one row; tenant copy id == landlord id asserted — green |
| 12 | Phase 25 SHARE-01 regression: full integration suite stays green after copier extraction | VERIFIED | `vendor/bin/phpunit tests/Integration/SharedEntity` — 16 tests, 89 assertions, 0 failures (includes SharedEntitySyncIntegrationTest) |

**Score:** 12/12 truths verified (1 requires human TTY test for full confirmation)

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `src/Command/SharedEntityResyncCommand.php` | tenancy:shared:resync command, min 90 lines | VERIFIED | 252 lines, contains 'tenancy:shared:resync', all D-01..D-07 behaviors present |
| `src/Shared/SharedEntityCopier.php` | Extracted copier with classifyRow, applyRow, etc. | VERIFIED | 222 lines, contains all 5 required methods + GENERATOR_TYPE_NONE, no merge(), no getAssociationNames() |
| `src/Shared/SharedEntityCopierInterface.php` | Interface for testability (extracted during Plan 03) | VERIFIED | Exists with 5 method signatures; command type-hints the interface |
| `src/Subscriber/SharedEntityWriteProtectionListener.php` | Rewired to SharedEntityCopier | VERIFIED | Constructor takes `SharedEntityCopier $copier`, bypass calls `copier->isSyncInProgress()` |
| `tests/Unit/Shared/SharedEntityCopierTest.php` | 7 real assertions (no stubs) | VERIFIED | 7 tests, 32 assertions, 0 skipped — green |
| `tests/Unit/Command/SharedEntityResyncCommandTest.php` | 8 real CommandTester assertions (7 planned + 1 CR-01 regression) | VERIFIED | 8 tests, 41 assertions, 0 skipped — green |
| `tests/Integration/SharedEntity/SharedEntityResyncCommandIntegrationTest.php` | 3 real SQLite assertions | VERIFIED | 3 tests, 24 assertions, 0 skipped — green |
| `tests/Integration/SharedEntity/Support/MakeSharedEntityResyncServicesPublicPass.php` | Compiler pass exposing copier + command services | VERIFIED | Exists; Phase 26 IDs also added to MakeSharedEntityServicesPublicPass additively |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `SharedEntityResyncCommand.php` | `SharedEntityCopierInterface` | constructor injection | WIRED | L31: `readonly SharedEntityCopierInterface $copier`; calls `classifyRow`, `applyRow`, `findSharedClasses` |
| `SharedEntityResyncCommand.php` | `BootstrapperChain::boot/clear` | per-tenant try/finally | WIRED | L127/L148: `bootstrapperChain->boot($tenant)` / `bootstrapperChain->clear()` in finally |
| `src/TenancyBundle.php` | `tenancy.command.shared_resync` | console.command registration | WIRED | L301-311: registered with `->tag('console.command')`, NOT gated on Doctrine\Migrations |
| `SharedEntityWriteProtectionListener.php` | `SharedEntityCopier::isSyncInProgress()` | constructor + bypass call | WIRED | L44: `SharedEntityCopier $copier`; L69: `$this->copier->isSyncInProgress()` |
| `src/TenancyBundle.php` | `tenancy.shared_entity_copier` | DI registration + wiring | WIRED | L275: copier registered before subscriber; L287,295,309: subscriber, listener, command all receive it |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|--------------|--------|--------------------|--------|
| `SharedEntityResyncCommand::execute()` | `$sharedClasses` | `copier->findSharedClasses($landlordEm)` — Doctrine metadata scan | Yes — iterates real ORM metadata factory | FLOWING |
| `SharedEntityResyncCommand::execute()` | `$landlordRowsByClass` | `$landlordEm->getRepository($class)->findAll()` | Yes — real repository query per class | FLOWING |
| `SharedEntityResyncCommand::execute()` | `$driftSummary` | `copier->classifyRow($landlordEm, $tenantEm, $entity)` — real `$tenantEm->find()` | Yes — real DB lookup per row per tenant | FLOWING |
| `SharedEntityCopier::applyRow()` | `$copy` | `$tenantEm->find($class, $ids)` + getFieldNames() scalar copy | Yes — real find-or-new from DB | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| SharedEntityResync filter passes | `vendor/bin/phpunit --filter SharedEntityResync` | 11 tests, 65 assertions, 0 failures | PASS |
| Full SharedEntity integration suite | `vendor/bin/phpunit tests/Integration/SharedEntity` | 16 tests, 89 assertions, 0 failures | PASS |
| Full PHPUnit suite | `vendor/bin/phpunit` | 730 tests, 3130 assertions, 1 skipped (pre-existing), 0 failures | PASS |

### Probe Execution

Step 7c: SKIPPED — no `scripts/*/tests/probe-*.sh` files present for this phase; phase is a PHP bundle with PHPUnit as the automated proof mechanism.

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|----------|
| SHARE-02 (AC1) | 26-02/03 | Command lists all #[Shared] classes via Doctrine metadata | SATISFIED | `findSharedClasses()` + table output in command |
| SHARE-02 (AC1) | 26-03 | Reports drift before executing; respects --dry-run | SATISFIED | Drift summary table + dry-run returns before writes |
| SHARE-02 (AC2) | 26-03 | Works under database_per_tenant and shared_db (latter is no-op) | SATISFIED | Integration tests use database_per_tenant; shared_db guard returns SUCCESS with message |
| SHARE-02 (AC3) | 26-03 | Continue-on-failure matching tenancy:migrate; summary at exit | SATISFIED | Per-tenant try/catch/finally; "Completed: N succeeded, M failed" summary line |
| SHARE-02 (idempotent) | 26-02/04 | Idempotent upsert (requirements says merge(), superseded by D-02 locked decision) | SATISFIED (override N/A — documented) | find-or-new is the correct idempotent path; merge() removed in ORM 3.0; CONTEXT.md D-02 explicitly supersedes the "merge() semantics" wording; integration test proves no duplicates |

**Note on REQUIREMENTS.md "merge() semantics":** The REQUIREMENTS.md description says "Idempotent (uses `merge()` semantics, not `persist()`)". The implementation uses find-or-new + scalar field copy instead. This is an intentional, documented deviation — `26-CONTEXT.md` D-02 and `26-PATTERNS.md` line 795 explicitly state `EntityManager::merge()` was removed in ORM 3.0, and find-or-new achieves identical idempotency semantics. The integration test `testResyncIsIdempotent` proves idempotency holds.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| None | — | No TBD/FIXME/XXX debt markers found in any phase-modified files | — | — |

Scanned files: `src/Command/SharedEntityResyncCommand.php`, `src/Shared/SharedEntityCopier.php`, `src/Shared/SharedEntityCopierInterface.php`, `src/Subscriber/SharedEntityWriteProtectionListener.php`, `src/TenancyBundle.php`, `tests/Unit/Shared/SharedEntityCopierTest.php`, `tests/Unit/Command/SharedEntityResyncCommandTest.php`, `tests/Integration/SharedEntity/SharedEntityResyncCommandIntegrationTest.php`.

### Known Accepted Advisory Items (Not Gaps)

The following items from 26-REVIEW.md were intentionally addressed or deferred as non-blockers per the context provided:

- **CR-01 (FIXED):** Apply pass tenant EM cascade failure — fixed in commit `4439e45`: `resetManager('tenant')` added in apply catch, classify catch, plus `$classifyErrored` tracking, explicit `$succeeded` counter, and regression test `testApplyFailureResetsTenantManagerAndContinues`.
- **WR-01 (FIXED in CR-01 commit):** Classify-pass error masking — fixed: classify catch now sets `$classifyErrored[$slug]`, emits `$io->warning(...)`, resets manager, and table shows `STATUS=ERROR` column so operator cannot read a crash as in-sync.
- **WR-02-light (FIXED in CR-01 commit):** Classify-errored tenants now hard-skipped in apply pass and recorded as failures.
- **WR-03 (FIXED in CR-01 commit):** Success counted explicitly via `++$succeeded`.
- **WR-04 (DEFERRED — advisory):** Extra subscriber re-entrancy test (per-batch vs per-flush flag scope) — intentionally deferred; the per-flush behavior is proven by integration test; no production risk identified.
- **WR-05 (DEFERRED — advisory):** `applyRow` authority refactor (remove redundant find in command) — performance/API clarity improvement, not a correctness issue; deferred.
- **IN-01/02/03 (DEFERRED — informational):** Comment improvements — no code behavior affected.
- **IN-04 (FIXED via `testApplyFailureResetsTenantManagerAndContinues`):** The regression test now exercises the real flush-failure path with `resetManager` assertion.

### Human Verification Required

#### 1. Interactive TTY Confirm Prompt (SHARE-02-c)

**Test:** Run `bin/console tenancy:shared:resync` in a real terminal against a Symfony app with at least one `#[Shared]` entity seeded on the landlord EntityManager. Do not pass `--force` or `--dry-run`.
**Expected:** The drift summary table renders with tenant rows, Would-Insert/Would-Update/In-Sync counts, and a `[y/N]` confirm prompt appears at the bottom. Pressing Enter (accepting the default No) aborts cleanly with exit code 0 and no writes occur. Entering `y` proceeds to the apply pass.
**Why human:** `CommandTester` simulates input streams but does not render an interactive TTY. The prompt copy, table column alignment, and default-No UX behavior require visual inspection in a real terminal. This item is explicitly flagged in `26-VALIDATION.md` under Manual-Only Verifications.

### Gaps Summary

No gaps blocking goal achievement. All 12 must-haves are verified by codebase inspection and live test runs. The single human verification item (TTY prompt rendering) is a UX/visual check that cannot be automated — it does not block any functional correctness claim.

---

_Verified: 2026-06-13T00:00:00Z_
_Verifier: Claude (gsd-verifier)_
