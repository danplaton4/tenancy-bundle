---
phase: 26-tenancy-shared-resync-command
plan: 02
subsystem: database
tags: [doctrine-orm, shared-entities, event-subscriber, write-protection, di-wiring, refactor]

requires:
  - phase: 26-01
    provides: SharedEntityCopierTest stubs (7 skip-guarded, now activated)
  - phase: 25-shared-entities-sync-mode
    provides: SharedEntitySyncSubscriber + SharedEntityWriteProtectionListener + SHARE-01 integration suite

provides:
  - SharedEntityCopier — single source of truth for upsert (applyRow), classify (classifyRow), enumeration (findSharedClasses), isShared, and syncInProgress flag
  - Thinned SharedEntitySyncSubscriber — delegates to copier, no longer owns flag or doSync()
  - Rewired SharedEntityWriteProtectionListener — depends on SharedEntityCopier, consults copier.isSyncInProgress()
  - DI registration: tenancy.shared_entity_copier wired before subscriber and listener
  - SHARE-02-j regression gate: Phase 25 SHARE-01 integration suite stays green after extraction

affects:
  - 26-03 (SharedEntityResyncCommand — copier is ready to inject; applyRow/classifyRow/findSharedClasses surface fully available)
  - 26-04 (verification — SHARE-02-i write-protection bypass now testable via copier; SHARE-02-a fully active)

tech-stack:
  added: []
  patterns:
    - "Flag ownership: syncInProgress lives on SharedEntityCopier, not on the subscriber — copier owns flush boundary; per-flush set/reset in finally (T-26-02-FLAG)"
    - "Write-protection bypass: SharedEntityWriteProtectionListener depends on SharedEntityCopier (not subscriber) — T-26-02-BYPASS scoped to flush boundary only"
    - "Proxy-safe reflection: isShared() and findSharedClasses() use ClassMetadata::$reflClass — WR-01 pattern extended to copier"
    - "Scalar-only cascade boundary: applyRow() copies getFieldNames() only, never getAssociationNames() — DEC-SHARE-02 preserved"

key-files:
  created:
    - src/Shared/SharedEntityCopier.php
    - tests/Unit/Shared/SharedEntityCopierTest.php (activated from Wave 0 stubs — 7 real assertions)
  modified:
    - src/Subscriber/SharedEntitySyncSubscriber.php
    - src/Subscriber/SharedEntityWriteProtectionListener.php
    - src/TenancyBundle.php
    - tests/Unit/Subscriber/SharedEntitySyncSubscriberSharedDbTest.php

key-decisions:
  - "syncInProgress flag moved to SharedEntityCopier — the copier owns the flush boundary; applyRow() sets flag immediately before flush, resets in finally. This is the minimum viable change to make the future resync command bypass write-protection correctly (T-26-02-FLAG, T-26-02-BYPASS)"
  - "SharedEntityWriteProtectionListener rewired to depend on SharedEntityCopier (not SharedEntitySyncSubscriber) — subscriber is no longer in the write-protection bypass chain; the copier is the single authority for isSyncInProgress()"
  - "SharedEntitySyncSubscriberSharedDbTest updated to assert isSyncInProgress() on the copier instance — no delegating shim on the subscriber (RESEARCH Open Q #2 resolution)"
  - "Task 2 + Task 3 collapsed into single commit 86ad99d — the integration suite (Task 3's gate) could not pass until DI was wired, so both changes are atomic in the git history"

patterns-established:
  - "SharedEntityCopier extraction: extract owned methods (doSync, isShared, syncInProgress) to a service class that multiple callers (subscriber, future command) inject — DI wiring MUST register the copier before its dependents"
  - "Test MockObject void-method stubs: willReturnSelf() not willReturn(null) for methods with void return type (persist, setFieldValue)"

requirements-completed: [SHARE-02]

duration: 15min
completed: 2026-06-12
---

# Phase 26 Plan 02: tenancy-shared-resync-command Copier Extraction Summary

**SharedEntityCopier extracted from SharedEntitySyncSubscriber with applyRow/classifyRow/findSharedClasses/isShared/syncInProgress, write-protection listener rewired to consult copier.isSyncInProgress(), SHARE-01 regression suite green**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-06-12T20:32:00Z
- **Completed:** 2026-06-12T20:47:00Z
- **Tasks:** 3
- **Files modified:** 6

## Accomplishments

- Created `SharedEntityCopier` in `Tenancy\Bundle\Shared` namespace with the full extracted surface: `applyRow()` (find-or-new upsert with GENERATOR_TYPE_NONE PK-preservation, syncInProgress flag owned per-flush in finally), `classifyRow()` (read-only insert/update/in-sync detection), `findSharedClasses()` (proxy-safe reflClass enumeration), `isShared()` (proxy-safe WR-01 check), `isSyncInProgress()` (bypass gate for write-protection)
- Activated all 7 Wave 0 stub tests in `SharedEntityCopierTest` — replaced every `markTestSkipped` with real assertions; 7 tests, 32 assertions, green
- Thinned `SharedEntitySyncSubscriber` — removed `$syncInProgress`, `isSyncInProgress()`, `doSync()`, and `isShared()`; subscriber now delegates to `$this->copier->applyRow()` and `$this->copier->isShared()`
- Rewired `SharedEntityWriteProtectionListener` — constructor changed from `SharedEntitySyncSubscriber $syncSubscriber` to `SharedEntityCopier $copier`; bypass call changed from `$this->syncSubscriber->isSyncInProgress()` to `$this->copier->isSyncInProgress()`
- Updated `SharedEntitySyncSubscriberSharedDbTest` — 6-arg constructor with real `SharedEntityCopier` instance; `isSyncInProgress()` assertion on copier, not subscriber (no delegating shim)
- Registered `tenancy.shared_entity_copier` in `TenancyBundle::loadExtension()` inside the `interface_exists(EntityManagerInterface::class)` block, before the subscriber; wired as 6th subscriber arg and as write-protection listener 2nd arg
- SHARE-02-j regression gate: Phase 25 SHARE-01 integration suite (12 tests, 58 assertions) green after extraction

## Task Commits

1. **Task 1: Create SharedEntityCopier + activate CopierTest stubs** - `8e8e603` (feat)
2. **Task 2 + Task 3: Thin subscriber, rewire listener + DI wiring** - `86ad99d` (feat)

Note: Task 2 commit was rejected by pre-commit hook (integration suite requires DI wiring to pass — subscriber had 6 args but DI still passed 5). Tasks 2 and 3 were staged together and committed atomically as `86ad99d` — this is the correct minimal unit since they have a hard dependency.

## Files Created/Modified

- `src/Shared/SharedEntityCopier.php` — New: extracted upsert + classify + enumeration + isShared + syncInProgress flag
- `src/Subscriber/SharedEntitySyncSubscriber.php` — Thinned: 6th arg SharedEntityCopier, removed $syncInProgress/isSyncInProgress()/doSync()/isShared(), delegates to copier
- `src/Subscriber/SharedEntityWriteProtectionListener.php` — Rewired: constructor dep changed to SharedEntityCopier, bypass calls copier.isSyncInProgress()
- `src/TenancyBundle.php` — DI wiring: tenancy.shared_entity_copier registered, subscriber/listener re-pointed
- `tests/Unit/Shared/SharedEntityCopierTest.php` — Activated: 7 real assertions replacing Wave 0 stubs
- `tests/Unit/Subscriber/SharedEntitySyncSubscriberSharedDbTest.php` — Updated: 6-arg constructor, isSyncInProgress() on copier

## Decisions Made

- `syncInProgress` flag moved to `SharedEntityCopier::applyRow()` per-flush boundary. The subscriber's WR-02 reasoning ("keep the flag set for this tenant's whole batch") was valid when flag was per-batch, but after extraction the flag is set per-flush inside `applyRow()` — each Doctrine `flush()` is atomic from the event perspective, so per-flush is safe and eliminates the per-batch try/finally wrapper from the subscriber.
- `SharedEntityWriteProtectionListener` depends on `SharedEntityCopier` only, not on `SharedEntitySyncSubscriber`. The subscriber is no longer in the write-protection bypass chain. This is the central landmine fix: without this change, the future resync command's `applyRow()` writes would always trip `SharedEntityWriteInTenantContextException`.
- No delegating `isSyncInProgress()` shim on the subscriber — the test was updated instead (RESEARCH Open Q #2 resolution). A shim would leave permanent dead public API on the subscriber.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Task 2 commit failed pre-commit hook — integration suite requires DI before subscriber change compiles**
- **Found during:** Task 2 commit attempt
- **Issue:** The pre-commit hook runs the full test suite. `SharedEntitySyncSubscriber` now requires 6 args but the DI wiring in `TenancyBundle` still passed 5 — integration test kernels boot with the old container and throw `ArgumentCountError`
- **Fix:** Completed Task 3 (DI wiring) before committing; staged Task 2 + Task 3 changes together in `86ad99d`
- **Files modified:** `src/TenancyBundle.php` (Task 3) committed together with Task 2 files
- **Verification:** Full suite 729 tests, 3065 assertions, 11 skipped — green
- **Committed in:** `86ad99d`

**2. [Rule 1 - Bug] PHPUnit MockObject void-method stubs in SharedEntityCopierTest**
- **Found during:** Task 1 (GREEN phase test run)
- **Issue:** `willReturn(null)` on `persist()` and `setFieldValue()` (both have `void` return type) threw `IncompatibleReturnValueException`
- **Fix:** Changed to `willReturnSelf()` for these void methods
- **Files modified:** `tests/Unit/Shared/SharedEntityCopierTest.php`
- **Verification:** 7 tests, 32 assertions, green
- **Committed in:** `8e8e603`

---

**Total deviations:** 2 auto-fixed (Rule 1 - bug: commit sequencing, Rule 1 - bug: mock type)
**Impact on plan:** Zero scope creep. Both auto-fixes corrected test/process issues, not architectural changes. Final observable behavior matches plan exactly.

## Issues Encountered

Stale Symfony container caches from previous test runs blocked full-suite verification. Multiple cache paths in `/private/var/folders/...` retained old container definitions (5-arg subscriber constructor). Resolution: bulk-purged all `tenancy_*` directories from the OS temp dir after DI wiring was complete. Not a code issue — documented for awareness.

## Self-Check: PASSED

Files exist:
- src/Shared/SharedEntityCopier.php: FOUND
- tests/Unit/Shared/SharedEntityCopierTest.php: FOUND (activated)
- src/Subscriber/SharedEntitySyncSubscriber.php: FOUND (modified)
- src/Subscriber/SharedEntityWriteProtectionListener.php: FOUND (modified)
- src/TenancyBundle.php: FOUND (modified)
- tests/Unit/Subscriber/SharedEntitySyncSubscriberSharedDbTest.php: FOUND (modified)

Commits exist:
- 8e8e603: SharedEntityCopier + activated CopierTest — FOUND
- 86ad99d: thin subscriber + rewire listener + DI wiring — FOUND

## Next Phase Readiness

- `SharedEntityCopier` is fully wired in the DI container under `tenancy.shared_entity_copier`
- `applyRow()`, `classifyRow()`, `findSharedClasses()` surface is ready for injection into `SharedEntityResyncCommand` (Plan 26-03)
- Write-protection bypass is confirmed working: the copier owns the flag and the listener consults it
- SHARE-02-j regression gate passed (Phase 25 SHARE-01 full integration suite green)
- Plans 26-03 (command) and 26-04 (verification) can proceed

---
*Phase: 26-tenancy-shared-resync-command*
*Completed: 2026-06-12*
