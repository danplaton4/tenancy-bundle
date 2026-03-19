---
phase: 06-messenger-integration
plan: "01"
subsystem: Messenger
tags: [messenger, middleware, stamp, tenant-context, tdd]
dependency_graph:
  requires:
    - src/Context/TenantContext.php
    - src/Bootstrapper/BootstrapperChain.php
    - src/Provider/TenantProviderInterface.php
    - src/Event/TenantContextCleared.php
  provides:
    - src/Messenger/TenantStamp.php
    - src/Messenger/TenantSendingMiddleware.php
    - src/Messenger/TenantWorkerMiddleware.php
  affects:
    - composer.json
    - composer.lock
tech_stack:
  added:
    - symfony/messenger: "^7.4.7 (installed as dev dependency)"
  patterns:
    - "TDD RED-GREEN: tests written before production code"
    - "final class + private readonly constructor injection (consistent with Phase 1-5)"
    - "SpyBootstrapper pattern for final BootstrapperChain testing"
    - "try/finally teardown matches TenantContextOrchestrator canonical order"
key_files:
  created:
    - src/Messenger/TenantStamp.php
    - src/Messenger/TenantSendingMiddleware.php
    - src/Messenger/TenantWorkerMiddleware.php
    - tests/Unit/Messenger/TenantStampTest.php
    - tests/Unit/Messenger/TenantSendingMiddlewareTest.php
    - tests/Unit/Messenger/TenantWorkerMiddlewareTest.php
  modified:
    - composer.json
    - composer.lock
decisions:
  - "TenantWorkerMiddleware does NOT dispatch TenantResolved — tenant is restored not resolved; avoids HTTP listeners firing in worker context"
  - "BootstrapperChain is final (PHPUnit cannot mock) — real instance with mock EventDispatcherInterface, consistent with SpyBootstrapper pattern"
  - "Idempotency guard uses envelope->last(TenantStamp::class) === null check before stamp attachment"
  - "Teardown order in finally: bootstrapperChain.clear() -> tenantContext.clear() -> dispatch(TenantContextCleared) — matches TenantContextOrchestrator"
  - "symfony/messenger added to require-dev (tests) and suggest (users), NOT require (optional integration)"
metrics:
  duration: "2 min"
  completed: "2026-03-19"
  tasks_completed: 2
  files_changed: 8
---

# Phase 06 Plan 01: Messenger Middleware — TenantStamp, Sending & Worker Middleware Summary

**One-liner:** Three Messenger middleware classes (TenantStamp + TenantSendingMiddleware + TenantWorkerMiddleware) with TDD unit tests covering all MSG-01/MSG-02/MSG-03 behaviors.

## What Was Built

Tenant context preservation across Symfony Messenger process boundaries via three classes:

- **TenantStamp** — `StampInterface` carrier holding a `tenantSlug` string; survives PHP serialize/unserialize round-trip
- **TenantSendingMiddleware** — Auto-attaches `TenantStamp` on dispatch when a tenant is active; idempotency guard prevents double-stamping
- **TenantWorkerMiddleware** — Restores tenant context from stamp before handler runs; `try/finally` teardown guarantees `bootstrapperChain.clear()` → `tenantContext.clear()` → `dispatch(TenantContextCleared)` even on handler exceptions

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | TDD: tests + production code for all 3 classes | 9040cea | 6 files (3 src + 3 test) |
| 2 | Add symfony/messenger to composer.json | f827e34 | composer.json, composer.lock |

## Test Results

**14 tests, 28 assertions — all pass.**

### TenantStampTest (4 tests)
- `testImplementsStampInterface`
- `testCarriesSlug`
- `testSurvivesPhpSerializeRoundTrip`
- `testReadonlySlugProperty`

### TenantSendingMiddlewareTest (4 tests)
- `testAttachesStampWhenTenantActive`
- `testPassesThroughWhenNoTenant`
- `testIdempotent_DoesNotDoubleStamp`
- `testCallsNextInStack`

### TenantWorkerMiddlewareTest (6 tests)
- `testBootsTenantContextFromStamp` (+ `testBootsTenantContextFromStamp_setsTenant`)
- `testClearsContextAfterHandler`
- `testClearsContextOnHandlerException`
- `testPassesThroughWhenNoStamp`
- `testLetsTenantNotFoundExceptionPropagate`

## Deviations from Plan

None — plan executed exactly as written.

## Decisions Made

1. **TenantWorkerMiddleware does not dispatch TenantResolved** — On the worker side the tenant is "restored" not "resolved". Dispatching TenantResolved would cause HTTP-targeted listeners to fire in a worker context, which is incorrect. Only TenantContextCleared is dispatched in the finally teardown.

2. **BootstrapperChain testing strategy** — BootstrapperChain is a `final` class; PHPUnit 11 cannot mock final classes. A real BootstrapperChain is instantiated with a mock EventDispatcherInterface. This matches the SpyBootstrapper pattern established in Phase 1–5.

3. **symfony/messenger placement** — Added to `require-dev` (for tests) and `suggest` (user guidance). NOT added to `require` — the bundle is optional for users who do not use Messenger. The `class_exists` guard in Plan 02 prevents crashes when absent.

## Self-Check: PASSED

Files created:
- [x] src/Messenger/TenantStamp.php — FOUND
- [x] src/Messenger/TenantSendingMiddleware.php — FOUND
- [x] src/Messenger/TenantWorkerMiddleware.php — FOUND
- [x] tests/Unit/Messenger/TenantStampTest.php — FOUND
- [x] tests/Unit/Messenger/TenantSendingMiddlewareTest.php — FOUND
- [x] tests/Unit/Messenger/TenantWorkerMiddlewareTest.php — FOUND

Commits:
- [x] 9040cea — feat(06-01): TenantStamp, TenantSendingMiddleware, TenantWorkerMiddleware with unit tests
- [x] f827e34 — chore(06-01): add symfony/messenger to require-dev and suggest blocks
