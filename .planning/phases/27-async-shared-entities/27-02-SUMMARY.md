---
phase: 27-async-shared-entities
plan: 02
subsystem: messaging
tags: [symfony-messenger, doctrine, shared-entities, async, fan-out]

# Dependency graph
requires:
  - phase: 27-async-shared-entities
    plan: 01
    provides: SharedEntityChangedMessage, SharedEntityAsyncFanOutException, tenancy.shared.async
  - phase: 26-tenancy-shared-resync-command
    provides: SharedEntityCopier, SharedEntitySyncSubscriber, switchToTenant/restoreTenantContext mechanics
provides:
  - SharedEntityChangedMessageHandler (async per-tenant fan-out with re-fetch + deleteRow/applyRow split)
  - deleteRow() on SharedEntityCopierInterface + SharedEntityCopier (OQ-1 resolution)
  - SharedEntitySyncSubscriber async dispatch branch (?MessageBusInterface $bus arg + postFlush early-return)
  - TenancyBundle wiring: subscriber $bus via named setArgument('$bus'), handler messenger.message_handler tag
affects: [27-03, 28-phpstan-extension, 29-docs]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "deleteRow() helper on copier extracts delete sub-path — single implementation, DRY (OQ-1 resolution)"
    - "Async postFlush branch: null-bus guard + dispatch-per-change + clear-before-dispatch (Pitfall 1)"
    - "Handler fan-out: security gate + clear()-before-find() + vanished-row->delete + best-effort + throw-to-retry"
    - "named setArgument('$bus') for subscriber bus wiring (future-proof against arg-count changes)"
    - "Anonymous class implementing UnrecoverableExceptionInterface for no-retry security gate"

key-files:
  created:
    - src/MessageHandler/SharedEntityChangedMessageHandler.php
    - tests/Unit/Subscriber/SharedEntitySyncSubscriberAsyncTest.php
  modified:
    - src/Shared/SharedEntityCopierInterface.php
    - src/Shared/SharedEntityCopier.php
    - src/Subscriber/SharedEntitySyncSubscriber.php
    - src/TenancyBundle.php

key-decisions:
  - "OQ-1 resolved: deleteRow(tenantEm, class, capturedIds) added to copier rather than passing null/stdClass to applyRow() — applyRow() dereferences $entity::class before the delete branch, making null unsafe at PHPStan L9"
  - "clear()-before-find() uses full identity-map clear() — Doctrine ORM 3 removed the per-class clear($class) overload; full clear is acceptable in a worker context (handler is sole landlord EM consumer)"
  - "OQ-2 resolved: switchToTenant() + restoreTenantContext() duplicated verbatim from subscriber rather than extracting a shared service — keeps 27-02 file-scoped, avoids touching proven CR-01/CR-02 internals"
  - "Bus wired via named setArgument('\$bus') not positional setArgument(6, ...) — future-proof if subscriber gains additional constructor args before position 6"
  - "Security gate throws anonymous class implementing UnrecoverableExceptionInterface (PHPStan clean, no extra class file, no retry)"

# Metrics
duration: 12min
completed: 2026-06-15
---

# Phase 27 Plan 02: Async Fan-Out Implementation Summary

**Async shared-entity fan-out: deleteRow() copier helper (OQ-1), async dispatch branch in subscriber (SHARE-03), SharedEntityChangedMessageHandler (re-fetch + vanished-row->delete + best-effort + throw-to-retry), and TenancyBundle wiring**

## Performance

- **Duration:** ~12 min
- **Started:** 2026-06-15T11:07:28Z
- **Completed:** 2026-06-15T11:19:28Z
- **Tasks:** 3
- **Files modified:** 6 (2 created, 4 modified)

## Accomplishments

- Added `deleteRow(EntityManagerInterface $tenantEm, string $class, array $capturedIds): void` to `SharedEntityCopierInterface` and `SharedEntityCopier` — idempotent delete with syncInProgress flag in try/finally, WR-03 missing-id guard; refactored `applyRow()`'s delete branch to delegate (DRY — `$tenantEm->remove()` appears exactly once)
- Added `?MessageBusInterface $bus = null` as 7th promoted readonly arg to `SharedEntitySyncSubscriber`; inserted async branch in `postFlush()` after clearing `$pendingChanges`: dispatches one `SharedEntityChangedMessage` per change, clears `TenantContext` before dispatch (Pitfall 1 / D-01 stamp-clearing), restores previous tenant in finally, and returns before the sync fan-out
- Created `SharedEntityChangedMessageHandler` with `__invoke(SharedEntityChangedMessage)`: security gate (validates entityClass against findSharedClasses() → UnrecoverableExceptionInterface), clear()-before-find() (T-27-02-STALE), re-fetch latest state (D-05), vanished-row→delete (D-04), per-tenant fan-out using deleteRow()/applyRow() split (OQ-1, never stdClass), best-effort with per-tenant catch + log + resetManager(), throw-to-retry SharedEntityAsyncFanOutException (D-02)
- Wired in `TenancyBundle::loadExtension()`: subscriber bus via `setArgument('$bus', new Reference('messenger.bus.default'))` (named arg, D-07); handler registered with `->tag('messenger.message_handler', ['handles' => SharedEntityChangedMessage::class])` — both inside `interface_exists(MessageBusInterface)` block within the database.enabled / EntityManagerInterface blocks
- Added `SharedEntitySyncSubscriberAsyncTest`: SHARE-03-a (bus injected → dispatch, findAll never called), SHARE-03-b (bus=null → findAll called), stamp-clearing invariant (D-01 unit proof), delete-id dispatch uses pre-captured ids (D-04/D-05)

## Task Commits

1. **Task 1: deleteRow() on SharedEntityCopier + interface** - `d39b3b0` (feat)
2. **Task 2: async dispatch branch in subscriber + unit tests** - `337d68d` (feat)
3. **Task 3: SharedEntityChangedMessageHandler + TenancyBundle wiring** - `4f30674` (feat)

## Files Created/Modified

- `src/Shared/SharedEntityCopierInterface.php` — Added `deleteRow()` contract with class-string + array<string,mixed> PHPDoc
- `src/Shared/SharedEntityCopier.php` — Implemented `deleteRow()`; refactored `applyRow()` delete branch to delegate (single `remove()` call)
- `src/Subscriber/SharedEntitySyncSubscriber.php` — Added `?MessageBusInterface $bus = null` arg + async dispatch branch in `postFlush()` + imports
- `src/MessageHandler/SharedEntityChangedMessageHandler.php` — New: full async fan-out handler with security gate, stale-read mitigation, vanished-row->delete, per-tenant loop, throw-to-retry
- `src/TenancyBundle.php` — Added imports for SharedEntityChangedMessage + SharedEntityChangedMessageHandler; added handler registration + named bus arg wiring inside `interface_exists(MessageBusInterface)` block
- `tests/Unit/Subscriber/SharedEntitySyncSubscriberAsyncTest.php` — New: SHARE-03-a/-b + D-01 stamp-clearing + D-04/D-05 delete-id tests

## Decisions Made

- `clear()` (full identity-map) replaces `clear($class)` (per-class, removed in Doctrine ORM 3) — Rule 1 auto-fix; full clear is safe in worker context
- Anonymous class for UnrecoverableExceptionInterface (security gate) avoids a dedicated exception file while staying PHPStan L9 clean
- `switchToTenant()`/`restoreTenantContext()` duplicated verbatim in handler (OQ-2) — see decisions section above

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed clear($class) → clear() (Doctrine ORM 3 API change)**
- **Found during:** Task 3 — PHPStan L9 `arguments.count` error
- **Issue:** `EntityManagerInterface::clear()` in Doctrine Persistence interface takes 0 params; the per-class overload `clear(string $objectName)` was removed in Doctrine ORM 3. Plan's RESEARCH skeleton referenced `$landlordEm->clear($class)` which PHPStan L9 rejects.
- **Fix:** Changed to `$this->landlordEm->clear()` (full identity-map clear) with a comment noting the Doctrine ORM 3 removal and why full clear is acceptable in a worker context.
- **Files modified:** `src/MessageHandler/SharedEntityChangedMessageHandler.php`
- **Committed in:** `4f30674` (Task 3 commit)

---

**Total deviations:** 1 auto-fixed (Rule 1 - bug)
**Impact on plan:** Trivial — same stale-read mitigation semantics, wider blast radius in theory (full map vs. single class), but negligible in practice since the handler is the only landlord EM consumer during its invocation.

## Issues Encountered

- PHPStan L9 found 2 errors in the test file: `ClassMetadata` generic type spec (`ClassMetadata<object>` required) and an unused constructor parameter in an anonymous class — both auto-fixed inline before commit

## Threat Surface Scan

The STRIDE register entries from the plan's threat model are all mitigated:

| Threat | Status |
|--------|--------|
| T-27-02-LEAK (cross-tenant write) | Mitigated — switchToTenant() + restoreTenantContext() in finally in handler; per-failure resetManager() |
| T-27-02-STAMP (TenantSendingMiddleware stamp) | Mitigated — subscriber clears context before dispatch (D-01); unit-level proof in testDispatchClearsTenantContextToAvoidStamp |
| T-27-02-CLASSINJ (unknown entityClass) | Mitigated — handler validates against findSharedClasses() → UnrecoverableExceptionInterface |
| T-27-02-STALE (stale identity-map read) | Mitigated — clear()-before-find() ordering enforced; grep acceptance criterion passes |

No new network endpoints, auth paths, or schema changes introduced.

## Known Stubs

None — all artifacts are fully implemented with no placeholder values.

## Next Phase Readiness

- Plan 27-03 can build the canary integration test against the confirmed `postFlush()` async branch and `SharedEntityChangedMessageHandler`
- The handler's `__invoke` signature is stable: `(SharedEntityChangedMessage $message): void`
- Integration proof for T-27-02-STAMP (all-tenant fan-out under active dispatch tenant) is deferred to 27-03 Task 2 `testWrongTenantIsolation` as planned

## Self-Check: PASSED

- [x] `src/MessageHandler/SharedEntityChangedMessageHandler.php` — FOUND
- [x] `src/Shared/SharedEntityCopierInterface.php` — FOUND
- [x] `tests/Unit/Subscriber/SharedEntitySyncSubscriberAsyncTest.php` — FOUND
- [x] Commit `d39b3b0` (Task 1) — FOUND
- [x] Commit `337d68d` (Task 2) — FOUND
- [x] Commit `4f30674` (Task 3) — FOUND
