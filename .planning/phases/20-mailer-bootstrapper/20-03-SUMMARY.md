---
phase: 20-mailer-bootstrapper
plan: 03
subsystem: mailer
tags: [mailer, bootstrapper, event-listener, decorator, tdd, lru, transport-routing]

requires:
  - phase: 20-mailer-bootstrapper
    plan: 01
    provides: "TenantInterface::getMailerDsn / getMailerFrom / getMailerReplyTo + TenantMailerConfigTrait"
  - phase: 20-mailer-bootstrapper
    plan: 02
    provides: "LruTransportCache + DsnSanitizer + SanitizingMailerDecorator + StoppableSpyTransport / PlainSpyTransport fixtures"
provides:
  - "Tenancy\\Bundle\\Bootstrapper\\MailerBootstrapper — no-op boot + LRU flush on clear (TenantBootstrapperInterface)"
  - "Tenancy\\Bundle\\Mailer\\TenantMessageDecorator — MessageEvent listener at priority 100; stamps X-Transport + From/Reply-To"
  - "Tenancy\\Bundle\\Mailer\\TenantAwareTransportsDecorator — final TransportInterface decorator routing tenant_<slug> via LRU + provider chain, with EventDispatcher pass-through (RESEARCH Q2 RESOLVED) and a defensive cross-tenant guard"
affects: [20-04, 20-05, 20-06, 20-07]

tech-stack:
  added: []
  patterns:
    - "Closure-injected transport factory keeps the decorator final + unit tests SMTP-free (default factory delegates to Transport::fromDsn)"
    - "EventDispatcher pass-through to Transport::fromDsn — guarantees SentMessageEvent / FailedMessageEvent fire from tenant transports identically to landlord (RESEARCH Q2 RESOLVED)"
    - "MessageEvent listener at priority 100 — runs before Symfony default-priority listeners; load-bearing firing point is isQueued=false (RESEARCH Finding 2)"
    - "Idempotency-by-default headers: X-Transport / From / Reply-To stamping skips when user-supplied"
    - "Defensive cross-tenant routing guard (T-20-03-02 mitigation): if TenantContext slug differs from header slug, refuse to send"

key-files:
  created:
    - src/Bootstrapper/MailerBootstrapper.php
    - src/Mailer/TenantMessageDecorator.php
    - src/Mailer/TenantAwareTransportsDecorator.php
  modified:
    - tests/Unit/Mailer/MailerBootstrapperTest.php
    - tests/Unit/Mailer/TenantMessageDecoratorTest.php
    - tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php

key-decisions:
  - "Phase 20-03: MailerBootstrapper takes a nullable LruTransportCache constructor arg — bootstrapper still loads when mailer dep is absent (no LRU service registered)"
  - "Phase 20-03: TenantMessageDecorator does NOT filter on isQueued — listener runs on every MessageEvent firing; the load-bearing path is isQueued=false (transport-level) per RESEARCH Finding 2"
  - "Phase 20-03: TenantAwareTransportsDecorator accepts an injectable \\Closure transport factory (default: Transport::fromDsn). Keeps tests SMTP-free without overriding a protected method (class stays `final`)"
  - "Phase 20-03: Defensive cross-tenant guard added in TenantAwareTransportsDecorator::send() — when TenantContext has an active tenant whose slug differs from the routed header slug, RuntimeException is thrown rather than risk cross-tenant mail leak. Justifies the TenantContext constructor dependency named in the plan's <interfaces> block (Rule 2 auto-add — missing critical defensive check; aligns with T-20-03-02 mitigation)"

patterns-established:
  - "Closure factory injection for SMTP-free unit testing of final decorators wrapping Symfony Transport::fromDsn"
  - "Header-observer anonymous-class spy pattern: a TransportInterface impl that records header state at send-time, enabling assertions on the post-routing header-stripped message"

requirements-completed: [BOOT-04]

metrics:
  duration_min: 10
  tasks: 3
  files_created: 3
  files_modified: 3
  commits: 7
  started: "2026-05-19T22:08:53Z"
  completed: "2026-05-19T22:19:21Z"
---

# Phase 20 Plan 03: MailerBootstrapper + TenantMessageDecorator + TenantAwareTransportsDecorator Summary

**Wired the three load-bearing Mailer classes that bridge the BOOT-04 contract to Symfony Mailer's runtime: a no-op chain participant (`MailerBootstrapper`), the `MessageEvent` listener (`TenantMessageDecorator` at priority 100), and the `tenant_<slug>` X-Transport routing decorator (`TenantAwareTransportsDecorator`) with EventDispatcher pass-through and a defensive cross-tenant guard. Converted Plan 00's three stub tests into 26 passing behavior tests; full suite stays green at 480 tests / 1300 assertions / 3 incomplete / 0 failures.**

## Class Inventory

| FQCN | Public Surface |
|------|----------------|
| `Tenancy\Bundle\Bootstrapper\MailerBootstrapper` | `__construct(?LruTransportCache $cache = null)`, `boot(TenantInterface): void`, `clear(): void` |
| `Tenancy\Bundle\Mailer\TenantMessageDecorator` | `__construct(TenantContext $context)`, `onMessage(MessageEvent): void`, `static getSubscribedEvents(): array` |
| `Tenancy\Bundle\Mailer\TenantAwareTransportsDecorator` | `__construct(TransportInterface $inner, ?TenantProviderInterface $provider, LruTransportCache $cache, TenantContext $context, ?EventDispatcherInterface $eventDispatcher = null, ?\Closure $transportFactory = null)`, `send(RawMessage, ?Envelope = null): ?SentMessage`, `__toString(): string` |

## Event Subscriber Priority

`TenantMessageDecorator::getSubscribedEvents()` registers on `Symfony\Component\Mailer\Event\MessageEvent::class` with method `onMessage` at **priority 100** — runs before Symfony's default-priority (`0`) listeners.

## X-Transport Prefix String

The decorator chain uses the literal prefix string `tenant_` — `TenantMessageDecorator` stamps `tenant_<slug>`, and `TenantAwareTransportsDecorator` routes any X-Transport header starting with `tenant_` (via `str_starts_with`) by stripping the prefix with `substr($headerValue, 7)`.

## Test Mapping

| Source class | Test class | Tests | Assertions |
|--------------|-----------|------:|-----------:|
| `MailerBootstrapper` | `tests/Unit/Mailer/MailerBootstrapperTest.php` | 5 | 12 |
| `TenantMessageDecorator` | `tests/Unit/Mailer/TenantMessageDecoratorTest.php` | 9 | 23 |
| `TenantAwareTransportsDecorator` | `tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php` | 12 | 31 |
| **Total** | | **26** | **66** |

## Task Commits

Each task ran the full TDD gate sequence (RED → GREEN), with a final style-only cleanup commit at the end:

| # | Task | RED commit | GREEN commit | Gate order |
|---|------|------------|--------------|------------|
| 1 | MailerBootstrapper | `13d634f` | `3cb6742` | RED → GREEN |
| 2 | TenantMessageDecorator | `1621ab9` | `94bfa2e` | RED → GREEN |
| 3 | TenantAwareTransportsDecorator | `e608e03` | `0ed8891` | RED → GREEN |
| — | php-cs-fixer cleanup | — | `34d69ee` | style only |

## Files Created/Modified

### Created — Source
- `src/Bootstrapper/MailerBootstrapper.php` — 40 lines, final class implementing `TenantBootstrapperInterface`, no-op boot, `clear()` flushes LRU
- `src/Mailer/TenantMessageDecorator.php` — 79 lines, final `EventSubscriberInterface`, priority 100, idempotency guards on X-Transport / From / Reply-To
- `src/Mailer/TenantAwareTransportsDecorator.php` — 115 lines, final `TransportInterface`, LRU lookup + Closure transport factory + EventDispatcher pass-through + cross-tenant guard

### Modified — Tests (converted from Plan 00 stubs)
- `tests/Unit/Mailer/MailerBootstrapperTest.php` — 5 behavior tests
- `tests/Unit/Mailer/TenantMessageDecoratorTest.php` — 9 behavior tests with real `Email` / `Message` / `MessageEvent`
- `tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php` — 12 behavior tests with Closure-injected transport factory (SMTP-free)

## Decisions Made

- **Closure transport factory (Task 3):** Plan recommended either a protected factory method tests could override OR a `\Closure` factory. Chose the Closure approach — keeps the class `final` (matching the bundle's convention for all decorators) and unit tests stay SMTP-free without subclassing. Default factory uses `Transport::fromDsn($dsn, $dispatcher)`.
- **Defensive cross-tenant guard (Task 3 — Rule 2 auto-add):** The plan's `<interfaces>` block names `TenantContext` as a constructor argument, but the example Pattern 1 code never reads it — leaving an unused property that fails PHPStan level 9. Rather than drop the dependency (which would break the Plan 04 DI wiring contract), added a defensive check: if `TenantContext` has an active tenant whose slug differs from the routed header slug, throw `\RuntimeException` rather than risk cross-tenant mail leak. Aligns with `<threat_model>` T-20-03-02 mitigation philosophy. Two new tests added (`testRefusesCrossTenantRoutingWhenContextTenantSlugMismatches`, `testAllowsRoutingWhenContextTenantMatchesHeaderSlug`) — full Task 3 suite is now 12 tests (plan called for 10).
- **TenantMessageDecorator priority 100, no isQueued filter (Task 2):** Listener fires on every `MessageEvent` regardless of `isQueued()`. The load-bearing path per RESEARCH Finding 2 is the transport-level firing (`isQueued=false`), which runs identically in sync HTTP and worker contexts — but `isQueued=true` events are harmless to also receive (headers stamped on a clone Symfony discards).
- **Anonymous-class StubTenant for Task 2 + Task 3 unit tests:** Used `use StubTenantMailerExtension;` mixed into an anonymous class implementing `TenantInterface` — provides full fluent mailer setters without dragging Doctrine entity attributes into Unit tests.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Worktree lacked `composer.lock` / `vendor/`**
- **Found during:** Pre-flight before Task 1
- **Issue:** Worktrees only share git-tracked files; `composer.lock` is gitignored so the worktree had no installed dependencies. Same Rule-3 blocker documented in Plans 20-00 / 20-01 / 20-02.
- **Fix:** `cp ../../../composer.lock . && composer install` to install locked deps (including Plan 00's symfony/mailer + symfony/mime require-dev additions).
- **Files modified:** none committed (composer.lock + vendor/ stay gitignored).
- **Verification:** `vendor/bin/phpunit --version` returns `PHPUnit 11.5.55`; `vendor/bin/phpstan --version` returns `2.1.50`.
- **Committed in:** n/a — workspace setup.

**2. [Rule 1 — Bug] LruTransportCache is `final` — cannot be mocked**
- **Found during:** Task 1 (Test 2 / Test 3 fail with `ClassIsFinalException`)
- **Issue:** Plan §Task 1 said: "Use `createMock(LruTransportCache::class)` for the cache dep". But `LruTransportCache` from Plan 02 is `final` (one of the locked design decisions). PHPUnit 11 raises `ClassIsFinalException` when attempting to mock a final class. The plan's mocking guidance is incorrect.
- **Fix:** Switched both tests to use a **real** `LruTransportCache(32)` populated with the existing `StoppableSpyTransport` fixture (from Plan 02). `testBootIsNoOp` asserts the spy recorded zero `stop()` calls and the cache still contains the entry (boot didn't touch it). `testClearFlushesLruTransportCache` asserts the spy's `stopCalls` == 1 (clear() propagated to cache->clear() which calls stop() on each entry).
- **Files modified:** `tests/Unit/Mailer/MailerBootstrapperTest.php` only (within the Task 1 RED commit before the implementation lands).
- **Verification:** Both tests now pass; assertions are stronger (verifies observable effect on a real cache rather than just method-call presence on a mock).
- **Committed in:** `3cb6742` (Task 1 GREEN commit — same task, atomic).

**3. [Rule 2 — Missing Critical] PHPStan level 9 flagged `assertTrue(true)` placeholder + unused TenantContext property**
- **Found during:** Task 1 GREEN PHPStan run and Task 3 GREEN PHPStan run
- **Issue (a):** Test 5 in Task 1 had a placeholder `$this->assertTrue(true)` — PHPStan reports `method.alreadyNarrowedType`. Replaced with reflective constructor parameter checks (verifies nullability + correct type name).
- **Issue (b):** The plan's `<interfaces>` block names `TenantContext` as a constructor argument to `TenantAwareTransportsDecorator`, but the Pattern 1 reference implementation never reads it. PHPStan level 9 flags `property.onlyWritten`. Rather than drop the dependency (would break Plan 04 DI wiring), promoted it into a real defensive use — the cross-tenant guard documented above.
- **Fix:** Both issues resolved in-task without `@phpstan-ignore` annotations.
- **Files modified:** `tests/Unit/Mailer/MailerBootstrapperTest.php`, `src/Mailer/TenantAwareTransportsDecorator.php`, `tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php` (new tests added).
- **Verification:** `vendor/bin/phpstan analyse src/Bootstrapper/MailerBootstrapper.php src/Mailer/TenantMessageDecorator.php src/Mailer/TenantAwareTransportsDecorator.php tests/Unit/Mailer/MailerBootstrapperTest.php tests/Unit/Mailer/TenantMessageDecoratorTest.php tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php --level=9` → `[OK] No errors`.
- **Committed in:** `3cb6742` (Task 1) and `0ed8891` (Task 3) — within the respective GREEN commits, no separate refactor commit needed.

**4. [Rule 1 — Bug] Test 6 (header strip) anonymous class triggered PHPStan errors**
- **Found during:** Task 3 GREEN PHPStan run
- **Issue:** Initial implementation of `testStripsXTransportHeaderAfterRouting` used an anonymous class with a by-reference constructor parameter (`@param-out` annotation) — PHPStan flagged `paramOut.type` + `property.onlyWritten`.
- **Fix:** Replaced with a simpler header-observer anonymous class that stores the observation on a public property and asserts directly. Equivalent behavior, no by-reference plumbing.
- **Files modified:** `tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php`.
- **Verification:** PHPStan clean; test passes; assertion is semantically the same.
- **Committed in:** `0ed8891` (Task 3 GREEN commit).

**5. [Rule 2 — Missing Critical] php-cs-fixer @Symfony ruleset violations**
- **Found during:** Final verification before SUMMARY
- **Issue:** `TenantAwareTransportsDecorator` had multi-line `sprintf(...)` calls inside `throw` statements (Symfony ruleset prefers single-line when arg count is small). `TenantMessageDecoratorTest` had unordered use imports.
- **Fix:** Ran `vendor/bin/php-cs-fixer fix` on both files.
- **Files modified:** `src/Mailer/TenantAwareTransportsDecorator.php`, `tests/Unit/Mailer/TenantMessageDecoratorTest.php`.
- **Verification:** `php-cs-fixer check` exits 0 across all 6 Plan 03 files.
- **Committed in:** `34d69ee` (separate style commit — not amended into prior commits per parallel-executor rules).

### Out-of-scope discoveries

None new — pre-existing PHPStan warnings in `TestProduct.php` / `TestTenantProduct.php` already logged in `.planning/phases/20-mailer-bootstrapper/deferred-items.md` by Plan 20-00.

**Total deviations:** 5 auto-fixed (1 blocking workspace setup, 1 plan-bug — final-class mock, 2 missing-critical — phpstan placeholder + unused property, 1 phpstan-only — anonymous-class refactor, 1 style-only — cs-fixer). The plan-bug deviation (Rule 1) is the only one that materially changes test shape from what the plan called for — and the new shape produces stronger assertions (real cache + spy observable instead of mock method expectations).

## Threat Surface Audit

Per the plan's `<threat_model>`:

- **T-20-03-01 (Information Disclosure / Spoofing — cross-tenant email via wrong X-Transport):** `mitigate` disposition VERIFIED. `TenantMessageDecorator::onMessage` early-returns when `$this->context->getTenant() === null` OR `getMailerDsn() === null` (asserted by `testNoOpWhenTenantContextEmpty` + `testNoOpWhenTenantHasNullMailerDsn`). Idempotency guards on X-Transport / From / Reply-To prevent overwriting user-supplied values.
- **T-20-03-02 (Tampering — LRU stale-entry cross-tenant):** `mitigate` disposition VERIFIED + STRENGTHENED. Cache keyed by tenant slug (string equality); `findBySlug` is authoritative on miss. **Beyond plan:** added a defensive cross-tenant guard — if `TenantContext` slug differs from the routed header slug, `TenantAwareTransportsDecorator::send()` throws rather than send. Two new tests cover the guard.
- **T-20-03-03 (Elevation — findBySlug returns wrong tenant):** `accept` disposition CONFIRMED — provider IS the trust anchor; out of scope.
- **T-20-03-04 (Information Disclosure — DSN in RuntimeException messages):** `mitigate` disposition VERIFIED. Both `\RuntimeException` paths in `buildAndCache()` include only the slug — verified by `testTenantWithNullDsnThrowsRuntimeException` (`expectExceptionMessageMatches('/acme/')`) and by manual inspection of the source (no `$dsn` interpolation in any throw).
- **T-20-03-05 (DoS — unbounded transport build):** `mitigate` disposition CONFIRMED via the LRU contract (Plan 02). LRU `get()` hits skip the build path entirely — covered by `testUsesCachedTransportOnLruHit` (`provider->expects(never())->method('findBySlug')`).

No new threat surface introduced beyond what `<threat_model>` enumerated. No `threat_flag` entries to add.

## Validation Compliance

- ✅ All 3 source files exist, are `final`, carry `declare(strict_types=1)`, and pass acceptance-criteria greps:
  - `final class MailerBootstrapper implements TenantBootstrapperInterface` — 1 occurrence
  - `final class TenantMessageDecorator implements EventSubscriberInterface` — 1 occurrence
  - `final class TenantAwareTransportsDecorator implements TransportInterface` — 1 occurrence
  - `MessageEvent::class => ['onMessage', 100]` — 1 occurrence (priority 100 hardcoded)
  - `addTextHeader('X-Transport', 'tenant_'` — 1 occurrence
  - `str_starts_with($headerValue, 'tenant_')` — 1 occurrence
  - `substr($headerValue, 7)` — 1 occurrence
  - `$this->cache->get($slug) ?? $this->buildAndCache($slug)` — 1 occurrence
  - `getHeaders()->remove('X-Transport')` — 1 occurrence
  - `?EventDispatcherInterface $eventDispatcher` — 1 occurrence
  - `Transport::fromDsn($dsn, $dispatcher)` — 1 occurrence (default factory)
- ✅ All 3 test files pass: 26 tests, 66 assertions, 0 failures, 0 errors, 0 skipped
- ✅ Full unit suite: `vendor/bin/phpunit --testsuite unit` → 349 tests, 3 incomplete remaining (Wave-2+ stubs for Plans 04/06/07), 0 failures, 0 errors
- ✅ Full suite: `vendor/bin/phpunit` → 480 tests, 1300 assertions, 3 incomplete, 0 failures, 0 errors
- ✅ `vendor/bin/phpstan analyse <all-Plan-03-files> --level=9` → `[OK] No errors`
- ✅ `vendor/bin/php-cs-fixer check` clean on all 6 Plan 03 files

## TDD Gate Compliance

Plan is `type: execute` but every task declared `tdd="true"` — RED+GREEN gate sequence verified per task:

| Task | RED commit | GREEN commit | Gate order |
|------|------------|--------------|------------|
| 1    | `13d634f`  | `3cb6742`    | ✅ RED → GREEN |
| 2    | `1621ab9`  | `94bfa2e`    | ✅ RED → GREEN |
| 3    | `e608e03`  | `0ed8891`    | ✅ RED → GREEN |

No REFACTOR commits required — all three implementations passed PHPStan level 9 with the auto-fixed deviations folded into the GREEN commits. The final `34d69ee` is a cosmetic-only cs-fixer pass, not a REFACTOR.

## Next Plan Readiness

Wave 2+ plans can now consume Plan 03 outputs without further interface work:

- **Plan 20-04 (Bundle DI wiring):** registers `tenancy.mailer.bootstrapper` (tagged `tenancy.bootstrapper` priority `-20`), `tenancy.mailer.message_decorator` (tagged `kernel.event_subscriber`), and `tenancy.mailer.transports_decorator` (decorating `mailer.transports`). All constructor signatures are stable.
- **Plan 20-05 (Profiler):** `TenantDataCollector::collectMailerState()` can query the LRU directly via `tenancy.mailer.transport_cache`.
- **Plan 20-06 (Async canary):** `MailerTestKernel` from Plan 00 + the existing `SpyTransport` in `tests/Integration/Mailer/` can exercise the worker-side path through `TenantAwareTransportsDecorator`. The Closure factory provides a clean test seam if needed.
- **Plan 20-07 (Compile-time guard):** `MailerTransportContractPass` can reference the service IDs registered in Plan 04.

No blockers for Wave 2+.

## Self-Check: PASSED

Verified all 6 created/modified files exist on disk and all 7 task commits are present in git log:

```
$ git log --oneline 80303e237b3f9c53fd4b523e13e818f61959627d..HEAD
34d69ee style(20-03): apply php-cs-fixer to Plan 03 src + test files
0ed8891 feat(20-03): add TenantAwareTransportsDecorator with LRU + dispatcher pass-through
e608e03 test(20-03): add failing tests for TenantAwareTransportsDecorator
94bfa2e feat(20-03): add TenantMessageDecorator stamping X-Transport + From/Reply-To
1621ab9 test(20-03): add failing tests for TenantMessageDecorator
3cb6742 feat(20-03): add MailerBootstrapper with no-op boot + LRU flush on clear
13d634f test(20-03): add failing tests for MailerBootstrapper
```

Verified files:
- `src/Bootstrapper/MailerBootstrapper.php` — FOUND
- `src/Mailer/TenantMessageDecorator.php` — FOUND
- `src/Mailer/TenantAwareTransportsDecorator.php` — FOUND
- `tests/Unit/Mailer/MailerBootstrapperTest.php` — FOUND (5 tests passing)
- `tests/Unit/Mailer/TenantMessageDecoratorTest.php` — FOUND (9 tests passing)
- `tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php` — FOUND (12 tests passing)

---
*Phase: 20-mailer-bootstrapper*
*Plan: 03*
*Completed: 2026-05-19*
