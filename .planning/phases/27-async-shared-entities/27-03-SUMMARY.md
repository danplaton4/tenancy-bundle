---
phase: 27-async-shared-entities
plan: 03
subsystem: messaging
tags: [symfony-messenger, doctrine, shared-entities, async, integration-test, canary]

# Dependency graph
requires:
  - phase: 27-async-shared-entities
    plan: 02
    provides: SharedEntityChangedMessageHandler, SharedEntitySyncSubscriber async branch, deleteRow() on copier
  - phase: 27-async-shared-entities
    plan: 01
    provides: SharedEntityChangedMessage, SharedEntityAsyncFanOutException, tenancy.shared.async
affects: [28-phpstan-extension, 29-docs]

provides:
  - SharedEntityAsyncTestKernel (sync:// transport + SharedEntityChangedMessage routing + shared.async:true)
  - MakeSharedEntityAsyncServicesPublicPass (exposes handler + bus + EMs for integration test inspection)
  - SharedEntityAsyncCanaryTest — SHARE-03 round-trip acceptance proof covering SHARE-03-d/-e/-f/-g/-h/-j and D-01 stamp-clearing integration canary

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "SyncTransport canary pattern: asserts handler-reach + DB state, NOT PhpSerializer survival (RESEARCH Pattern 4 — SyncTransport re-dispatches on bus without serialization)"
    - "Active-dispatch-tenant isolation proof: set specific tenant ACTIVE before dispatch, assert ALL tenants received the change (establishes D-01 stamp-clearing integration gate)"
    - "resetManager('landlord') in setUp: required between tests when handler may close/invalidate the landlord EM via its stale-read clear() call"
    - "kernel() type-narrowing accessor in PHPUnit test class (mirrors AsyncCanaryTest pattern)"

key-files:
  created:
    - tests/Integration/SharedEntity/Support/SharedEntityAsyncTestKernel.php
    - tests/Integration/SharedEntity/Support/MakeSharedEntityAsyncServicesPublicPass.php
    - tests/Integration/SharedEntity/SharedEntityAsyncCanaryTest.php

key-decisions:
  - "resetManager('landlord') added to setUp() — the handler calls landlordEm->clear() which does NOT close the EM, but between tests the SchemaTool dropSchema/createSchema cycle requires a fresh EM to avoid EntityIdentityCollisionException from stale identity-map entries"
  - "createAllSchemas() uses $registry->getManager('landlord') (not container->get('doctrine.orm.landlord_entity_manager')) so it picks up the freshly-reset EM after setUp()'s resetManager call"
  - "testHandlerThrowsOnTenantFailure uses DROP TABLE via direct PDO (idempotent try/catch) on tenant_a's SQLite file — mirrors the testPerTenantFailureIsLogged BEFORE INSERT trigger pattern but uses DROP TABLE instead for the async handler (no SyncSubscriber to bypass, handler catches Throwable per-tenant)"
  - "setUp() calls createAllSchemas() which drops+recreates schemas before EACH test — ensures tests are independent and each starts with empty tables"

patterns-established:
  - "Async canary setUp pattern: resetManager('landlord') + resetManager('tenant') + createAllSchemas() — prevents cross-test EM pollution and EntityIdentityCollisionException"
  - "SHARE-03 D-01 stamp-clearing integration proof: set one tenant active BEFORE flush/dispatch, assert all tenants received the change — active-dispatch-tenant canary pattern"

requirements-completed: [SHARE-03]

# Metrics
duration: 20min
completed: 2026-06-15
---

# Phase 27 Plan 03: SHARE-03 Acceptance Proof + Phase Gate Summary

**SHARE-03 round-trip canary: async dispatch through sync:// transport reaches SharedEntityChangedMessageHandler and converges every tenant DB; D-01 stamp-clearing, D-04 vanished-row delete, D-02 throw-to-retry (DROP TABLE), and idempotency proven end-to-end; full suite + PHPStan L9 + cs-fixer green**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-06-15T11:22:28Z
- **Completed:** 2026-06-15T11:42:00Z
- **Tasks:** 3
- **Files modified:** 3 (all created)

## Accomplishments

- Created `SharedEntityAsyncTestKernel` — boots TenancyBundle with sync:// Messenger transport, `SharedEntityChangedMessage::class => 'sync'` routing, and `tenancy.shared.async: true`; distinct SQLite DB filenames (tenancy_shared_async_test_*.db) avoid collision with the sync kernel's files
- Created `MakeSharedEntityAsyncServicesPublicPass` — exposes `tenancy.shared_entity_changed_handler`, `messenger.bus.default`, plus all sync-mode service IDs for integration test inspection via the compiled container
- Created `SharedEntityAsyncCanaryTest` covering all SHARE-03-d/-e/-f/-g/-h/-j acceptance bullets:
  - `testAsyncRoundTripCanary` (SHARE-03-j/-d): bus→sync://→handler round-trip + latest-state re-fetch
  - `testHandlerFansOutToAllTenants` (SHARE-03-f): ≥2 distinct tenant DBs received the row
  - `testWrongTenantIsolationWithActiveDispatchTenant` (D-01 stamp-clearing): set tenantA ACTIVE before dispatch, asserted ALL tenants received the row — integration-level proof that subscriber clears context before dispatch so TenantSendingMiddleware does NOT stamp a single-tenant envelope
  - `testVanishedRowPropagatesToTenantDelete` (SHARE-03-e/D-04): vanished landlord row → tenant copies deleted
  - `testHandlerThrowsOnTenantFailure` (SHARE-03-g/D-02): DROP TABLE on one tenant → SharedEntityAsyncFanOutException thrown + healthy tenant got the change (best-effort)
  - `testHandlerIdempotentOnRetry` (SHARE-03-h): re-dispatch yields exactly one row per tenant
- Phase gate results: **746 tests / 3190 assertions, PHPStan L9 clean, cs-fixer clean**

## Task Commits

1. **Task 1: SharedEntityAsyncTestKernel + MakeSharedEntityAsyncServicesPublicPass** - `2ac6871` (feat)
2. **Task 2: SharedEntityAsyncCanaryTest (round-trip + all-tenant + stamp-clearing + vanished-row + throw-to-retry + idempotency)** - `ebd90a5` (feat)
3. **Task 3: Phase gate** — verification-only (no new files; full suite ran via pre-commit hook on Task 2 commit)

## Files Created/Modified

- `tests/Integration/SharedEntity/Support/SharedEntityAsyncTestKernel.php` — Async test kernel with sync:// transport + SharedEntityChangedMessage routing + shared.async:true + distinct DB filenames
- `tests/Integration/SharedEntity/Support/MakeSharedEntityAsyncServicesPublicPass.php` — Exposes handler + bus + EMs for test inspection
- `tests/Integration/SharedEntity/SharedEntityAsyncCanaryTest.php` — SHARE-03 acceptance proof (6 tests, 32 assertions standalone; 746/3190 full suite)

## Phase Gate Results

| Check | Result |
|-------|--------|
| `vendor/bin/phpunit` (full suite) | PASS — 746 tests / 3190 assertions / 2 pre-existing skips |
| `vendor/bin/phpstan analyse --level=9 --no-progress` | PASS — No errors |
| `vendor/bin/php-cs-fixer check --diff` | PASS — No fixes needed |

## SHARE-03 Test Map Coverage

| Acceptance Bullet | Test | Plan |
|---|---|---|
| SHARE-03-a: bus injected → dispatch, no sync fan-out | `testAsyncBranchDispatchesMessageNotSyncFanOut` | 27-02 |
| SHARE-03-b: bus=null → sync fan-out, no dispatch | `testSyncBranchRunsWhenBusIsNull` | 27-02 |
| SHARE-03-c: scalar-only message value object | `testCarriesOnlyScalars` | 27-01 |
| SHARE-03-d: latest-state re-fetch | `testAsyncRoundTripCanary` | 27-03 |
| SHARE-03-e: vanished-row → delete | `testVanishedRowPropagatesToTenantDelete` | 27-03 |
| SHARE-03-f: all-tenant fan-out | `testHandlerFansOutToAllTenants` | 27-03 |
| SHARE-03-g: throw-to-retry on tenant failure | `testHandlerThrowsOnTenantFailure` | 27-03 |
| SHARE-03-h: idempotency on retry | `testHandlerIdempotentOnRetry` | 27-03 |
| SHARE-03-i: compile-time guard (async:true + Messenger absent) | `testThrowsWhenAsyncTrueAndMessengerAbsent` (structural grep proof) | 27-01 |
| SHARE-03-j: transport round-trip canary | `testAsyncRoundTripCanary` | 27-03 |
| D-01 stamp-clearing integration proof | `testWrongTenantIsolationWithActiveDispatchTenant` | 27-03 |

## Decisions Made

- `resetManager('landlord')` added to `setUp()` to prevent `EntityIdentityCollisionException` from stale identity-map entries after the handler's `clear()` call modifies the landlord EM state (Rule 1 auto-fix)
- `createAllSchemas()` uses `$registry->getManager('landlord')` not the container service alias to always pick up the freshly-reset EM instance
- `testHandlerThrowsOnTenantFailure` uses DROP TABLE (not BEFORE INSERT trigger) because the async handler catches `\Throwable` per-tenant without the sync subscriber's re-entrancy guard complexity — a missing table produces the same failure class

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed EntityIdentityCollisionException in setUp schema recreation**
- **Found during:** Task 2 initial test run (all tests except first failed)
- **Issue:** setUp() called createAllSchemas() which dropped+recreated schema tables, but the landlord EM's identity map still held entity instances from the previous test (from the handler's fan-out which had called `$landlordEm->clear()` during its stale-read mitigation). When createAllSchemas() tried to drop+create and the next test called `$landlordEm->persist()`, Doctrine found a stale identity-map entry at the same PK.
- **Fix:** Added `$registry->resetManager('landlord')` in setUp() before createAllSchemas(); changed createAllSchemas() to use `$registry->getManager('landlord')` so it operates on the freshly-reset EM instance.
- **Files modified:** `tests/Integration/SharedEntity/SharedEntityAsyncCanaryTest.php`
- **Verification:** `vendor/bin/phpunit --testsuite integration --filter SharedEntityAsyncCanaryTest` exits 0 (6/6 tests pass)
- **Committed in:** `ebd90a5` (Task 2 commit)

**2. [Rule 1 - Bug] Fixed PHPStan L9 null-dereference on self::$kernel**
- **Found during:** Task 2 PHPStan L9 check
- **Issue:** 6 test methods accessed `self::$kernel->getContainer()` directly; PHPStan sees the property as `?SharedEntityAsyncTestKernel` and rejects the call.
- **Fix:** Added `kernel(): SharedEntityAsyncTestKernel` type-narrowing accessor (mirrors AsyncCanaryTest pattern); replaced all 6 direct accesses with `$this->kernel()->getContainer()`. Static method `createAllSchemas()` kept using `self::$kernel->getContainer()` under the existing `null === self::$kernel` guard.
- **Files modified:** `tests/Integration/SharedEntity/SharedEntityAsyncCanaryTest.php`
- **Verification:** `vendor/bin/phpstan analyse tests/Integration/SharedEntity/SharedEntityAsyncCanaryTest.php --level=9 --no-progress` exits 0
- **Committed in:** `ebd90a5` (Task 2 commit)

**3. [Rule 1 - Bug] Fixed PHPStan L9 RecursiveIteratorIterator mixed-item type**
- **Found during:** Task 2 PHPStan L9 check
- **Issue:** `foreach ($items as $item)` where `$items` is `\RecursiveIteratorIterator` without generic type annotation; PHPStan infers item as `mixed`, rejects `$item->isDir()` / `$item->getPathname()`.
- **Fix:** Added `/** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $items */` and `/** @var \SplFileInfo $item */` annotations.
- **Files modified:** `tests/Integration/SharedEntity/SharedEntityAsyncCanaryTest.php`
- **Verification:** PHPStan L9 clean
- **Committed in:** `ebd90a5` (Task 2 commit)

---

**Total deviations:** 3 auto-fixed (all Rule 1 bugs)
**Impact on plan:** All fixes trivial test-infrastructure issues; no change to the tested behaviors or assertions.

## Issues Encountered

None — the three auto-fixed issues above were routine PHPUnit identity-map and PHPStan type annotation issues.

## Threat Surface Scan

No new network endpoints, auth paths, or schema changes introduced. All new files are test infrastructure only. The STRIDE threat register entries from the plan are fully mitigated:

| Threat | Status |
|--------|--------|
| T-27-03-LEAK (cross-tenant write under test) | Mitigated — `testWrongTenantIsolationWithActiveDispatchTenant` proves D-01 stamp-clearing at integration level |
| T-27-03-DRIFT (vanished-row convergence) | Mitigated — `testVanishedRowPropagatesToTenantDelete` proves D-04 end-to-end |
| T-27-03-RETRY (partial fan-out failure) | Mitigated — `testHandlerThrowsOnTenantFailure` proves D-02 best-effort + aggregate exception |

## Known Stubs

None — all artifacts are fully implemented integration tests with no placeholder values.

## Next Phase Readiness

- Phase 27 is complete — all 3 plans shipped; full suite green; SHARE-03 fully covered
- Phase 28 (PHPStan extension for #[TenantAware] / #[Shared]) can proceed
- Phase 29 (docs refresh — sharing model, async guide) can proceed
- All 10 SHARE-03 acceptance bullets have passing automated tests across plans 27-01/27-02/27-03

## Self-Check: PASSED

- [x] `tests/Integration/SharedEntity/Support/SharedEntityAsyncTestKernel.php` — FOUND
- [x] `tests/Integration/SharedEntity/Support/MakeSharedEntityAsyncServicesPublicPass.php` — FOUND
- [x] `tests/Integration/SharedEntity/SharedEntityAsyncCanaryTest.php` — FOUND
- [x] Commit `2ac6871` (Task 1) — FOUND
- [x] Commit `ebd90a5` (Task 2) — FOUND
- [x] Full suite: 746 tests / 3190 assertions — PASSED
- [x] PHPStan L9 — PASSED
- [x] cs-fixer — PASSED
