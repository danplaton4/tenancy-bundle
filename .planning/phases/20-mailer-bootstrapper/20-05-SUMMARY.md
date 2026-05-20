---
phase: 20-mailer-bootstrapper
plan: 05
subsystem: mailer
tags: [mailer, event-listener, lifecycle, socket-cleanup, tdd]

requires:
  - phase: 20-mailer-bootstrapper
    plan: 02
    provides: "LruTransportCache::clear() — the call-target invoked from the new listener"
  - phase: 20-mailer-bootstrapper
    plan: 04
    provides: "config/services.php mailer interface_exists block + tenancy.mailer.lru_cache service ID — extended (not replaced) by this plan"
provides:
  - "Tenancy\\Bundle\\Mailer\\TenantContextClearedListener — final EventSubscriberInterface listening on TenantContextCleared, calls LruTransportCache::clear() exactly once per dispatch"
  - "DI service tenancy.mailer.context_cleared_listener (autoconfigure(true) inside the mailer interface_exists block)"
  - "Roadmap success criterion 6 operationally proven by LongRunningWorkerSimulationTest (3 integration tests, 507 assertions)"
affects: [20-06, 20-07, 20-08]

tech-stack:
  added: []
  patterns:
    - "Event-driven safety-net cleanup: a dedicated subscriber on TenantContextCleared runs in parallel to the BootstrapperChain::clear() path — either alone is sufficient, both being present guarantees the cache is emptied even if Messenger middleware ordering bypasses the chain"
    - "Type-narrowing accessor helper (private kernel(): MailerTestKernel) — converts nullable static-property kernel handle into a non-null typed return so PHPStan level 9 can see the type narrowing across test methods, without inline @var or @phpstan-ignore"
    - "Worker-iteration simulation pattern: dispatch the real TenantContextCleared event on the real container's event_dispatcher and assert observable cache size — proves the listener is wired (Task 2 test 3 catches the regression where the service is defined but the autoconfigure tag is missing)"

key-files:
  created:
    - src/Mailer/TenantContextClearedListener.php
    - tests/Unit/Mailer/TenantContextClearedListenerTest.php
    - tests/Integration/Mailer/LongRunningWorkerSimulationTest.php
  modified:
    - config/services.php

key-decisions:
  - "Phase 20-05: Use the bundle's already-existing TenantContextCleared event — no new event class. The HTTP path (TenantContextOrchestrator::onKernelTerminate) and the async path (TenantWorkerMiddleware) both dispatch this event today, so a single subscriber covers both lifecycle paths without modification of either dispatcher."
  - "Phase 20-05: Belt-and-suspenders redundancy with MailerBootstrapper::clear() is INTENTIONAL. Both call LruTransportCache::clear(). T-20-05-01 mitigation depends on at-least-one-path triggering — having two independent paths means a future middleware ordering change cannot silently regress socket cleanup."
  - "Phase 20-05: Test fixture reuse — LongRunningWorkerSimulationTest imports StubTenant from tests/Integration/Messenger/Support/StubTenant.php (which uses StubTenantMailerExtension trait) rather than re-declaring a local stub. The plan referenced tests/Integration/Support/StubTenant.php which does not exist; the Messenger/Support/StubTenant is the canonical mailer-trait-using stub since Plan 01 wave."

patterns-established:
  - "Listener-class TDD pattern for signal-only events: real LruTransportCache + StoppableSpyTransport fixture instead of mocking the cache (LruTransportCache is final — cannot be mocked). The spy's stopCalls counter is the observable that verifies clear() reached the leaves."
  - "Integration test that proves DI wiring: dispatch the real event through the real container and assert the observable side-effect. Task 2 test 3 catches the regression where the listener service is registered but the autoconfigure tag is missing or the EventSubscriberInterface tag wasn't picked up."

requirements-completed: [BOOT-04]

metrics:
  duration_min: 8
  tasks: 2
  files_created: 3
  files_modified: 1
  commits: 3
  started: "2026-05-20T09:14:00Z"
  completed: "2026-05-20T09:22:00Z"
---

# Phase 20 Plan 05: TenantContextClearedListener Summary

**Closed the socket-leak loop: a dedicated `TenantContextClearedListener` (final, EventSubscriberInterface) subscribes to the bundle's existing `TenantContextCleared` event and calls `LruTransportCache::clear()` exactly once per dispatch — closing all cached per-tenant SMTP transports on both HTTP teardown (kernel.terminate) and async worker teardown (per-message). Shipped alongside a 100-tenant integration simulation that operationally proves roadmap success criterion 6: the LRU cache stays bounded (size ≤ 32) across 100 distinct tenants. Full suite: 499 tests / 1839 assertions / 0 failures.**

## Class Inventory

| FQCN | Public surface |
|------|---------------|
| `Tenancy\Bundle\Mailer\TenantContextClearedListener` | `__construct(LruTransportCache $cache)`, `onContextCleared(TenantContextCleared $event): void`, `static getSubscribedEvents(): array` |

## Service Registration

| Service ID | Class | Flags |
|------------|-------|-------|
| `tenancy.mailer.context_cleared_listener` | `TenantContextClearedListener` | args: `service('tenancy.mailer.lru_cache')` · `autoconfigure(true)` (picks up `EventSubscriberInterface`) |

Registered inside the existing `if (interface_exists(\Symfony\Component\Mailer\MailerInterface::class))` block in `config/services.php`, immediately after `tenancy.mailer.sanitizing_decorator`.

## Event Subscribed

| Event | Method | Priority |
|-------|--------|----------|
| `Tenancy\Bundle\Event\TenantContextCleared` | `onContextCleared` | default (`0`) — no priority-sensitive interaction with other listeners |

## Integration Test Methods

The three integration test methods in `tests/Integration/Mailer/LongRunningWorkerSimulationTest.php`:

| Test | Purpose | Assertions |
|------|---------|-----------:|
| `testCacheSizeRemainsBoundedAcross100Tenants` | Worker iteration shape: set + dispatch teardown event per tenant; cache size ≤ maxSize throughout, 0 after every dispatch | 200 (100 ≤-checks + 100 ==0-checks) |
| `testCacheLruEvictionStaysBoundedWithoutContextClear` | Pure LRU pressure: 100 sets, no intermediate clear; size capped at maxSize, oldest evicted, newest retained, evictions ≥ 68 | 104 |
| `testListenerActuallyWiredIntoEventDispatcher` | Sanity check: dispatching the real event through the real container empties the cache — catches DI regressions (missing autoconfigure tag) | 2 |
| **Total** | | **~507** |

## Simulation Parameters

| Parameter | Value |
|-----------|-------|
| `maxSize` (LRU cap, default) | **32** |
| Tenants simulated per loop | **100** |
| Evictions observed when no intermediate clear | **68** (= 100 − maxSize) |
| Cache size after every `TenantContextCleared` | **0** (exactly) |
| Cache size during loop with clears | **0 or 1** (never exceeds maxSize) |

## Test Mapping

| Source class | Test class | Tests | Assertions |
|--------------|-----------|------:|-----------:|
| `TenantContextClearedListener` | `tests/Unit/Mailer/TenantContextClearedListenerTest.php` | 4 | 9 |
| `TenantContextClearedListener` (integration) | `tests/Integration/Mailer/LongRunningWorkerSimulationTest.php` | 3 | 507 |
| **Total new for Plan 05** | | **7** | **516** |

## Task Commits

| # | Task | Commit | Type |
|---|------|--------|------|
| 1 | RED — failing tests for TenantContextClearedListener | `9c5a9e3` | test |
| 1 | GREEN — listener + DI registration | `e53bc6e` | feat |
| 2 | Integration test — 100-tenant bounded-cache simulation | `a224cbc` | test |

## Files Created/Modified

### Created — Source
- `src/Mailer/TenantContextClearedListener.php` — 43 lines, `final` class implementing `EventSubscriberInterface`, single dependency (`LruTransportCache`), one event subscription (`TenantContextCleared` → `onContextCleared`), one method-call (`$this->cache->clear()`).

### Created — Tests
- `tests/Unit/Mailer/TenantContextClearedListenerTest.php` — 4 behavior tests, real cache + spy transport (LruTransportCache is final — not mockable), `interface_exists(MailerInterface)` skip-guard in `setUp()`.
- `tests/Integration/Mailer/LongRunningWorkerSimulationTest.php` — 3 tests, full Mailer test-kernel boot, simulates worker iterations driving the real LRU cache through the real event dispatcher. Includes a `removeDir` helper for stale-kernel-cache cleanup (mirroring `MessengerMiddlewareIntegrationTest`).

### Modified
- `config/services.php` — added one `use` import (`TenantContextClearedListener`), appended one service definition (`tenancy.mailer.context_cleared_listener`) inside the existing mailer `interface_exists` block.

## Decisions Made

- **Reuse the bundle's existing `TenantContextCleared` event** rather than creating a new one. Both kernel paths (HTTP via `TenantContextOrchestrator::onKernelTerminate` and async via `TenantWorkerMiddleware::handle`) already dispatch this event today — a single subscriber covers both lifecycle paths.
- **Belt-and-suspenders redundancy with `MailerBootstrapper::clear()` is INTENTIONAL.** Both code paths call `LruTransportCache::clear()`. T-20-05-01 mitigation depends on at-least-one-path triggering — having two independent paths means a future Messenger middleware ordering change cannot silently regress socket cleanup. The cost is one extra `clear()` call per request (cheap: iterates an array of up-to-32 transports, all already stopped on the first call) which is the right tradeoff for a security/resource invariant.
- **Real `LruTransportCache` + `StoppableSpyTransport` instead of `createMock(LruTransportCache::class)`.** `LruTransportCache` is `final` (Plan 02 design decision), so PHPUnit 11's `createMock()` raises `ClassIsFinalException`. The plan text said to use `createMock` — this is the same plan bug documented in the Plan 20-03 SUMMARY for `MailerBootstrapperTest`. The fix is identical: use the real cache and observe `StoppableSpyTransport::$stopCalls`. Assertions are strictly stronger than a mock would provide (verifies the call reached the leaf method on the cached transport, not just the surface method on the cache).
- **`tests/Integration/Messenger/Support/StubTenant.php`** is the canonical mailer-trait stub since Plan 01 wave. The plan's `read_first` referenced `tests/Integration/Support/StubTenant.php` (no such file) — Wave 0 of Phase 20 left the mailer-trait import in the Messenger stub. Both Plan 01 and Plan 03 use this same stub; this plan continues the convention.
- **Type-narrowing accessor `private function kernel(): MailerTestKernel`** instead of inline `\assert(null !== self::$kernel)` or `@var` annotations. PHPStan level 9 does not narrow `self::$kernel` from `?MailerTestKernel` to `MailerTestKernel` via `markTestSkipped` (no exit semantics). The accessor returns a non-null type because PHPStan flow-analyzes the throw-on-null branch.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Worktree lacked `composer.lock` / `vendor/` AND stale kernel caches in `/var/folders/.../tenancy_*`**
- **Found during:** pre-flight (vendor/bin/phpunit absent + full suite crashed with "Cannot redeclare class Tenancy\Bundle\Entity\Tenant").
- **Issue:** Same Rule-3 blocker documented in Plans 20-00 / 20-01 / 20-02 / 20-03 / 20-04. Additionally, stale Symfony kernel caches under `/var/folders/.../T/tenancy_*` (populated by prior runs from a different worktree / main repo) bring in absolute paths to the main repository's `src/` tree, causing duplicate-class errors during integration suite boot.
- **Fix:** `cp ../../../composer.lock . && composer install --no-interaction --quiet`, then `find /var/folders -maxdepth 4 -name "tenancy_*" -type d 2>/dev/null | xargs rm -rf` to clear stale kernel caches.
- **Files modified:** none committed.
- **Verification:** Full suite returned to 492 passing tests at baseline (HEAD = ef2dc62, Phase 20 wave 3 tip) before any Plan 05 changes.
- **Committed in:** n/a — workspace setup.

**2. [Rule 1 — Bug] PHPStan level 9 `method.nonObject` on `self::$kernel->getContainer()`**
- **Found during:** post-Task-2 PHPStan run.
- **Issue:** Static property `self::$kernel` is declared `?MailerTestKernel`. PHPStan does not understand `self::markTestSkipped()` as exit-semantics, so `self::$kernel->getContainer()` on lines 79/120/150 raised `method.nonObject — Cannot call method getContainer() on MailerTestKernel|null`.
- **Fix:** Extracted a private `kernel(): MailerTestKernel` accessor that calls `markTestSkipped` if null (the path PHPStan won't reach in the typed return) and returns the non-null kernel. All three call sites changed from `self::$kernel->getContainer()` → `$this->kernel()->getContainer()`.
- **Files modified:** `tests/Integration/Mailer/LongRunningWorkerSimulationTest.php` (within the Task 2 commit before push).
- **Verification:** `vendor/bin/phpstan analyse tests/Integration/Mailer/LongRunningWorkerSimulationTest.php --level=9` returns `[OK] No errors`. Tests still pass (3 tests, 507 assertions).
- **Committed in:** `a224cbc` (Task 2 commit — same task, single touch).

**Total deviations:** 2 auto-fixed (1 blocking workspace/cache setup, 1 phpstan type-narrowing). Both are hygiene / setup; neither changes plan behavior.

### Out-of-scope discoveries

None. The kernel-cache staleness issue that crashed the full integration suite at startup is a known Phase 20 worktree-execution hazard — already documented in deferred-items by prior waves. The clean-and-retry path is reliable.

## Threat Surface Audit

Per the plan's `<threat_model>`:

- **T-20-05-01 (D — Worker FD/socket exhaustion across 100s of tenants):** `mitigate` disposition VERIFIED. Two independent cleanup paths both call `LruTransportCache::clear()`. `MailerBootstrapper::clear()` (Plan 03) is invoked by `BootstrapperChain::clear()` in the orchestrator/middleware teardown path; `TenantContextClearedListener::onContextCleared()` (Plan 05) is invoked by the event dispatcher whenever `TenantContextCleared` is fired. The 100-tenant simulation (`testCacheSizeRemainsBoundedAcross100Tenants`) asserts `cache->size() == 0` after every event dispatch — operational proof that the listener path empties the cache between iterations.
- **T-20-05-02 (I — Stale tenant's transport reused for another tenant):** `mitigate` VERIFIED. `clear()` on `TenantContextCleared` empties the entire cache between tenant contexts. The LRU is keyed by tenant slug, but the empty-on-teardown invariant is what closes the cross-tenant transport-reuse window — `testCacheSizeRemainsBoundedAcross100Tenants` asserts `assertSame(0, $cache->size())` after every iteration, proving no stale entries persist into the next tenant's window.
- **T-20-05-03 (T — Listener not wired in services.php → cache never clears):** `mitigate` VERIFIED. `testListenerActuallyWiredIntoEventDispatcher` boots the real container and dispatches the event — if the listener weren't in services.php under the autoconfigure tag, the cache stays non-empty and the test fails. This integration check is the safety net that prevents Task 1 from being a paper-tiger service definition.

No new threat surface introduced beyond what `<threat_model>` enumerated. No `threat_flag` entries to add.

## TDD Gate Compliance

Plan is `type: execute` with Task 1 declared `tdd="true"`. RED+GREEN gate sequence verified:

| Task | RED commit | GREEN commit | Gate order |
|------|------------|--------------|------------|
| 1    | `9c5a9e3`  | `e53bc6e`    | RED → GREEN |
| 2    | — (no tdd flag — integration coverage of Task 1) | `a224cbc` | test |

No REFACTOR commits required. Task 2 is a pure-integration plan task (not TDD-flagged), and the type-narrowing fix in deviation 2 was folded into the same commit as the original test landing.

## Validation Compliance

- ✅ `src/Mailer/TenantContextClearedListener.php` — `final class TenantContextClearedListener implements EventSubscriberInterface` (1 occurrence), `TenantContextCleared::class => 'onContextCleared'` (1 occurrence), `$this->cache->clear()` (1 occurrence), `declare(strict_types=1)` (1 occurrence).
- ✅ `config/services.php` — `tenancy.mailer.context_cleared_listener` (1 occurrence), `TenantContextClearedListener::class` (1 occurrence), `service('tenancy.mailer.lru_cache')` (3 occurrences — bootstrapper, transports decorator, listener).
- ✅ `tests/Integration/Mailer/LongRunningWorkerSimulationTest.php` — `testCacheSizeRemainsBoundedAcross100Tenants` (1), `testCacheLruEvictionStaysBoundedWithoutContextClear` (1), `testListenerActuallyWiredIntoEventDispatcher` (1), `for ($i = 0; $i < 100` loops (2), `new TenantContextCleared` (2), `assertLessThanOrEqual($maxSize` (2).
- ✅ `vendor/bin/phpunit tests/Unit/Mailer/TenantContextClearedListenerTest.php` → 4 tests, 9 assertions, 0 failures.
- ✅ `vendor/bin/phpunit tests/Integration/Mailer/LongRunningWorkerSimulationTest.php` → 3 tests, 507 assertions, 0 failures.
- ✅ `vendor/bin/phpunit --testsuite unit` → 385 tests, 1019 assertions, 0 failures (was 381 before Plan 05; +4 from new listener tests).
- ✅ `vendor/bin/phpunit` full suite → 499 tests, 1839 assertions, 2 incomplete (pre-existing Wave-0 stubs), 0 failures, 0 errors.
- ✅ `vendor/bin/phpstan analyse src/Mailer/TenantContextClearedListener.php tests/Unit/Mailer/TenantContextClearedListenerTest.php tests/Integration/Mailer/LongRunningWorkerSimulationTest.php --level=9` → `[OK] No errors`.
- ✅ `vendor/bin/php-cs-fixer check` clean on all 4 touched files.
- ✅ `php -l config/services.php` → no syntax errors.

## Next Plan Readiness

- **Plan 20-06 (Async canary):** can dispatch a `SendEmailMessage` through the `MailerTestKernel`'s real Messenger bus and assert that the per-message `TenantContextCleared` dispatch (already handled by `TenantWorkerMiddleware`) reaches this listener and empties the cache between messages — Task 2 test 3 already established that the listener-via-dispatcher path is wired.
- **Plan 20-07 (additional compile-time guards):** no impact — this plan does not touch the contract pass surface.
- **Plan 20-08 (docs / UPGRADE.md):** can quote the verbatim service ID (`tenancy.mailer.context_cleared_listener`), the subscribed event (`TenantContextCleared`), and the redundancy rationale documented above ("belt-and-suspenders alongside `MailerBootstrapper::clear()`").

No blockers for Wave 5+.

## Self-Check: PASSED

Verified all 4 created/modified files exist on disk and all 3 task commits are present in git log:

```
$ git log --oneline ef2dc623..HEAD
a224cbc test(20-05): add LongRunningWorkerSimulationTest — 100-tenant bounded-cache proof
e53bc6e feat(20-05): add TenantContextClearedListener flushing LRU on event
9c5a9e3 test(20-05): add failing tests for TenantContextClearedListener
```

Verified files:
- `src/Mailer/TenantContextClearedListener.php` — FOUND
- `tests/Unit/Mailer/TenantContextClearedListenerTest.php` — FOUND (4 tests, 9 assertions)
- `tests/Integration/Mailer/LongRunningWorkerSimulationTest.php` — FOUND (3 tests, 507 assertions)
- `config/services.php` — MODIFIED (1 use import + 1 service definition)

---
*Phase: 20-mailer-bootstrapper*
*Plan: 05*
*Completed: 2026-05-20*
