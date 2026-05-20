---
phase: 20-mailer-bootstrapper
verified: 2026-05-20T13:30:00Z
status: passed
score: 6/6 roadmap success criteria fully verified
overrides_applied: 0
re_verification: 1
re_verification_history:
  - run: 1
    verified: 2026-05-20T07:42:07Z
    status: gaps_found
    score: "3/6 fully verified; 2 partial; 1 broken"
    blockers:
      - "Gap #1: TenantMessageDecorator listener-based stamping fires AFTER routing"
      - "Gap #2: DSN leak via TransportException::getDebug + TransportExceptionInterface-only catch"
      - "Gap #3: TenantAwareTransportsDecorator empty-slug routing vector"
  - run: 2
    verified: 2026-05-20T13:30:00Z
    status: passed
    score: "6/6"
    closure_plans: ["20-09", "20-10", "20-11"]
gaps_closed:
  - "Gap #1: TenantMailerDecorator added at decoration_priority 10 (INNER) stamps X-Transport / From / Reply-To BEFORE Mailer::send routes — testSyncDispatchUsesTenantDsnWithoutPreStamping + testAsyncDispatchWithoutPreStampingSurvivesMessengerRoundTrip prove zero-boilerplate routing"
  - "Gap #2: TenantSanitizedTransportException constructor sanitizes previous TransportException's getDebug via DsnSanitizer::redact and appendDebug; SanitizingMailerDecorator catch widened to Mailer ExceptionInterface; DsnSanitizer regex tightened to require literal :// shape"
  - "Gap #3: TenantAwareTransportsDecorator now refuses empty slug ('' === $slug) and validates against /^[a-z0-9_-]+$/ BEFORE the provider round-trip — closes BL-02"
gaps_remaining: []
regressions: []
gaps: []
deferred: []
human_verification: []
---

# Phase 20: Mailer Bootstrapper Verification Report (Run 2 — Re-verification after gap closure)

**Phase Goal:** A tenant with a `mailerDsn` configured sends mail from that DSN with the tenant's `From`/`Reply-To` headers — correct under BOTH synchronous Mailer dispatch AND Messenger-routed async dispatch.

**Verified:** 2026-05-20T13:30:00Z
**Status:** passed
**Re-verification:** Yes — Run 2 after gap-closure plans 20-09, 20-10, 20-11

## Gap Closure Summary

The initial verification (Run 1, 2026-05-20T07:42:07Z) flagged 3 BLOCKER gaps. Three gap-closure plans landed in this wave to close them. Each is verified below against the actual codebase, not against SUMMARY claims.

### Gap #1 — X-Transport listener-based stamping does not drive routing

**Closure plan:** 20-09 — "Upstream X-Transport stamping decorator"
**Closing commits:**

- `6b1b10c` — `feat(20-09): add TenantMailerDecorator + remove WR-08 header mutation`
- `35017b2` — `feat(20-09): wire TenantMailerDecorator with explicit decoration priorities`
- `3568a19` — `test(20-09): cover upstream stamping path + WR-08 re-send preservation`

**What changed (verified in the codebase):**

| Check | Result |
| ----- | ------ |
| `src/Mailer/TenantMailerDecorator.php` exists and `implements MailerInterface` | VERIFIED — file present (102 lines), `final class TenantMailerDecorator implements MailerInterface` at line 50 |
| `config/services.php` registers `tenancy.mailer.tenant_decorator` decorating `mailer` at `decoration_priority: 10` (INNER) | VERIFIED — `services.php:209-214`: `->decorate('mailer', null, 10)` |
| `tenancy.mailer.sanitizing_decorator` priority 0 (OUTERMOST) | VERIFIED — `services.php:221-223`: `->decorate('mailer', null, 0)` |
| `TenantMessageDecorator` UNCHANGED and still subscribed at priority 100 | VERIFIED — `src/Mailer/TenantMessageDecorator.php` unchanged; `getSubscribedEvents` returns `[MessageEvent::class => ['onMessage', 100]]` (line 80) |
| `testSyncDispatchUsesTenantDsnWithoutPreStamping` exists and passes | VERIFIED — AsyncCanaryTest.php:342-377 |
| `testAsyncDispatchWithoutPreStampingSurvivesMessengerRoundTrip` exists and passes | VERIFIED — AsyncCanaryTest.php:391-427 |
| WR-08 mutation footgun closed: `grep -c "remove..X-Transport" src/Mailer/TenantAwareTransportsDecorator.php` → 0 | VERIFIED — returns 0 |
| Re-send regression test exists | VERIFIED — `testReSendingPreservesXTransportRouting` (AsyncCanaryTest.php:435-478) + `testPreservesXTransportHeaderAfterRouting` (TenantAwareTransportsDecoratorTest.php) — both present, both pass |

**Conclusion:** Gap #1 CLOSED. The bundle now ships zero-boilerplate routing: `$mailer->send($email)` in tenant A's HTTP context routes via tenant A's DSN with no user-stamped X-Transport. TenantMessageDecorator remains as defense-in-depth for code paths that bypass MailerInterface::send.

### Gap #2 — DSN leak via TransportException::getDebug

**Closure plan:** 20-10 — "Exception sanitization hardening"
**Closing commits:**

- `1f03331` — `fix(20-10): tighten DsnSanitizer regex to require literal :// shape`
- `b82f6e2` — `fix(20-10): sanitize TenantSanitizedTransportException::getDebug() (BL-01)`
- `775e7c8` — `fix(20-10): widen SanitizingMailerDecorator catch to Mailer ExceptionInterface (WR-01)`

**What changed (verified in the codebase):**

| Check | Result |
| ----- | ------ |
| `TenantSanitizedTransportException` overrides constructor and sanitizes `$previous->getDebug()` via `DsnSanitizer::redact` + `appendDebug` | VERIFIED — `src/Exception/TenantSanitizedTransportException.php:42-52` — constructor checks `$previous instanceof TransportException`, calls `DsnSanitizer::redact($debug)`, calls `$this->appendDebug(...)` |
| `SanitizingMailerDecorator` catches a broader exception type (not just `TransportExceptionInterface`) | VERIFIED — `src/Mailer/SanitizingMailerDecorator.php:42-73` — added `catch (\Throwable $e)` arm with runtime narrowing to `MailerExceptionInterface` (catches bridge-factory throws like `UnsupportedSchemeException`, `Mailer\Exception\InvalidArgumentException`); non-mailer Throwables re-thrown as-is |
| `DsnSanitizer::REDACTION_REGEX` tightened to require literal `://` | VERIFIED — `src/Mailer/DsnSanitizer.php:28` — `'/(:\/\/[^:\/@]+:)[^@\/]+(@)/'` — matches the required shape; no more `[\/]{0,2}` |
| Security grep on production code: `grep -rE ':[^*][^*@]*@' src/` filtering documentation/comments | VERIFIED — only matches are documentation strings in DsnSanitizer comments (`<scheme>://<user>:<password>@<host>`) and a defensive `str_contains` check in `TenantDataCollector` — no actual production DSN strings |
| `testGetDebugContainsNoUnredactedPasswordPattern` exists and passes | VERIFIED — `tests/Unit/Exception/TenantSanitizedTransportExceptionTest.php:111-121` — regex assertion confirms no `://<user>:<not-***>@` pattern in `getDebug()` |
| `testGetDebugIsRedactedWhenPreviousTransportExceptionHasDebug` passes | VERIFIED — TenantSanitizedTransportExceptionTest.php:59-71 — appends `smtp://user:hunter2@host:25` to previous, asserts wrapper getDebug contains `smtp://user:***@host:25` and NOT `hunter2` |

**Conclusion:** Gap #2 CLOSED. DSN credentials no longer escape via `getDebug()`. The previous-chain link is preserved for stack diagnostics but the wrapper's own `getDebug()` output is always redacted. Bridge-factory throws (which bypass the narrow `TransportExceptionInterface` catch) now also get redacted via the widened `\Throwable` + `MailerExceptionInterface` narrowing.

### Gap #3 — TenantAwareTransportsDecorator empty-slug routing vector

**Closure plan:** 20-11 — "Empty-slug + character-set guards (BL-02)"
**Closing commits:**

- `839c4e9` — `feat(20-11): add empty-slug + character-set guards in TenantAwareTransportsDecorator (BL-02)`
- `e6a2fd4` — `test(20-11): cover empty-slug + character-set guards in TenantAwareTransportsDecorator (BL-02)`

**What changed (verified in the codebase):**

| Check | Result |
| ----- | ------ |
| Empty-slug guard at `src/Mailer/TenantAwareTransportsDecorator.php:89-91` (`'' === $slug` → `\RuntimeException` with "empty slug") | VERIFIED |
| Character-set guard at `src/Mailer/TenantAwareTransportsDecorator.php:98-103` (`preg_match('/^[a-z0-9_-]+$/', $slug)` → `\RuntimeException` with "invalid slug") | VERIFIED |
| Both guards execute BEFORE the cross-tenant active-tenant guard (line 110) | VERIFIED — empty-slug at line 89, charset at line 98, active-tenant check at line 110 |
| `testRefusesEmptySlugXTransportHeader` exists and passes | VERIFIED — `tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php:380-397` |
| `testRefusesInvalidSlugCharacters` (path-traversal `../etc/passwd`) exists and passes | VERIFIED — TenantAwareTransportsDecoratorTest.php:405-422 |
| `testRefusesSlugWithWhitespace` exists and passes | VERIFIED — TenantAwareTransportsDecoratorTest.php:427-443 |
| `testRefusesSlugWithUppercase` exists and passes | VERIFIED — TenantAwareTransportsDecoratorTest.php:451-467 |
| `testValidSlugStillRoutes` (happy path regression) passes | VERIFIED — TenantAwareTransportsDecoratorTest.php:473+ |

**Conclusion:** Gap #3 CLOSED. `X-Transport: tenant_` (literal, empty slug) is now rejected with a clear `\RuntimeException` before reaching the provider. The character-set guard also catches path-traversal-ish, whitespace, and uppercase-slug attempts — no pathological provider implementation can be tricked by hostile X-Transport input.

## Goal Achievement

### Observable Truths (Roadmap Success Criteria)

| # | Truth (Roadmap SC) | Status | Evidence |
|---|---|---|---|
| 1 | Sync dispatch: `$mailer->send()` delivers via tenant A's SMTP DSN with tenant A's From header | **VERIFIED** | `testSyncDispatchUsesTenantDsn` PASSES (pre-stamped path) AND `testSyncDispatchUsesTenantDsnWithoutPreStamping` PASSES (zero-boilerplate path via TenantMailerDecorator). Both confirm SpyTransport recorded tenant A's DSN. |
| 2 | Async dispatch (the canary): worker-side capture asserts tenant A's SMTP DSN was used — NOT the landlord DSN | **VERIFIED** | `testAsyncDispatchInWorkerUsesTenantDsnNotLandlord` PASSES (pre-stamped) AND `testAsyncDispatchWithoutPreStampingSurvivesMessengerRoundTrip` PASSES (upstream-stamped). Negative assertion `assertNotContains('null://null', ...)` holds in both. |
| 3 | Container compilation fails when bootstrapper enabled but no transport strategy configured; fails when async without `x_transport` strategy | VERIFIED | `MailerTransportContractPass` unchanged; 3 LogicException paths still throw; ContainerCompilationTest still green. |
| 4 | User's custom Tenant entity (without `mailerDsn`) breaks compilation with clear migration path | VERIFIED | `TenantInterface` + `TenantMailerConfigTrait` + UPGRADE.md §"0.2 to 0.3" unchanged. |
| 5 | Thrown `TransportException` during send does NOT leak the DSN's password component in its message or trace | **VERIFIED** | Message redacted by `DsnSanitizer::redact` (unchanged path) AND `getDebug()` now redacted via the TenantSanitizedTransportException constructor override (Plan 20-10). `testGetDebugContainsNoUnredactedPasswordPattern` PASSES — the regex grep over getDebug finds no `://<user>:<non-***-password>@` shape. |
| 6 | After `TenantContextCleared` event, the per-tenant transport cache is cleared — verified by long-running-worker simulation that processes 100 distinct tenants without unbounded socket growth | VERIFIED | `TenantContextClearedListener` + `LongRunningWorkerSimulationTest::testCacheSizeRemainsBoundedAcross100Tenants` unchanged; still passes. |

**Score:** 6/6 fully verified.

### Required Artifacts

| Artifact | Expected | Status | Details |
|---|---|---|---|
| `src/Mailer/TenantMailerDecorator.php` (NEW from 20-09) | Upstream MailerInterface decorator stamping X-Transport / From / Reply-To from TenantContext | **VERIFIED** | Final class, 102 lines, implements MailerInterface, stamps in `stamp()` method gated on tenant + dsn; idempotent (only stamps if absent). |
| `src/Mailer/TenantAwareTransportsDecorator.php` (modified by 20-09 + 20-11) | Empty-slug + charset guards added; X-Transport mutation removed | **VERIFIED** | Lines 89-103 contain both guards; `grep -c "remove..X-Transport"` returns 0; cross-tenant guard at line 110 still intact. |
| `src/Mailer/SanitizingMailerDecorator.php` (modified by 20-10) | Catch widened to Mailer ExceptionInterface via Throwable + instanceof narrowing | **VERIFIED** | Two-arm catch: `TransportExceptionInterface` re-throws `TenantSanitizedTransportException`; `\Throwable` arm narrows to `MailerExceptionInterface` and re-throws `\RuntimeException` with sanitized message; non-Mailer Throwables re-thrown as-is (preserves `testNonTransportExceptionPropagatesAsIs`). |
| `src/Mailer/DsnSanitizer.php` (modified by 20-10) | REDACTION_REGEX tightened to require literal `://` | **VERIFIED** | `'/(:\/\/[^:\/@]+:)[^@\/]+(@)/'` — no more `[\/]{0,2}` over-match on free-text colons. |
| `src/Exception/TenantSanitizedTransportException.php` (modified by 20-10) | Constructor sanitizes previous TransportException's getDebug via DsnSanitizer + appendDebug | **VERIFIED** | Constructor at line 42; `if ($previous instanceof TransportException)` + `$this->appendDebug(DsnSanitizer::redact($debug) ?? '')` at line 49. |
| `src/Mailer/TenantMessageDecorator.php` (UNCHANGED — defense-in-depth) | Still wired at priority 100; docblock notes upstream stamper exists | UNCHANGED — defense-in-depth | File untouched in this wave; service registration unchanged. Docblock on TenantMailerDecorator explicitly warns future maintainers not to remove TenantMessageDecorator. |
| `config/services.php` (modified by 20-09) | TenantMailerDecorator at decoration_priority 10; SanitizingMailerDecorator at decoration_priority 0 | **VERIFIED** | services.php:209-214 (priority 10 INNER); services.php:221-223 (priority 0 OUTERMOST). Inline comment documents the runtime chain. |
| `tests/Integration/Mailer/AsyncCanaryTest.php` (modified by 20-09) | +3 tests: sync upstream-stamp, async upstream-stamp, re-send | **VERIFIED** | 5 tests total: 2 original (pre-stamped) + 3 new. All 5 pass (`vendor/bin/phpunit tests/Integration/Mailer/AsyncCanaryTest.php` → 5 tests, 22 assertions, OK). |
| `tests/Unit/Mailer/TenantMailerDecoratorTest.php` (NEW from 20-09) | 7 unit tests covering stamping behavior | **VERIFIED** | 7 test methods; all pass. |
| `tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php` (modified by 20-09 + 20-11) | Renamed test + 4 new BL-02 guard tests | **VERIFIED** | `testPreservesXTransportHeaderAfterRouting` (rename per 20-09) + `testRefusesEmptySlugXTransportHeader` + `testRefusesInvalidSlugCharacters` + `testRefusesSlugWithWhitespace` + `testRefusesSlugWithUppercase` + `testValidSlugStillRoutes` (per 20-11). |
| `tests/Unit/Mailer/SanitizingMailerDecoratorTest.php` (modified by 20-10) | Tests for widened catch (bridge-factory throws) | **VERIFIED** | Run as part of the 52-test gap suite (52 tests / 115 assertions OK). |
| `tests/Unit/Mailer/DsnSanitizerTest.php` (modified by 20-10) | Failover composite + free-text safety tests | **VERIFIED** | Run as part of the 52-test gap suite (passes). |
| `tests/Unit/Exception/TenantSanitizedTransportExceptionTest.php` (NEW from 20-10) | Tests covering getDebug sanitization + load-bearing regex assertion | **VERIFIED** | 8 test methods including `testGetDebugContainsNoUnredactedPasswordPattern` (the BL-01 invariant). |
| All artifacts from initial verification | Bootstrapper, LruTransportCache, MailerTransportContractPass, etc. | UNCHANGED — VERIFIED | None of the Run-1-passing artifacts were modified in a way that breaks them — full suite still 545/545. |

### Key Link Verification

| From | To | Via | Status | Details |
|---|---|---|---|---|
| `tenancy.mailer.tenant_decorator` | `mailer` | `->decorate('mailer', null, 10)` | **WIRED (NEW)** | services.php:210; priority 10 = INNER. |
| `tenancy.mailer.sanitizing_decorator` | `mailer` | `->decorate('mailer', null, 0)` | WIRED (made explicit) | services.php:222; priority 0 = OUTERMOST. |
| `TenantMailerDecorator::send` | `TenantMailerDecorator::stamp` | direct call before `$this->inner->send` | **WIRED** | TenantMailerDecorator.php:58-63. Stamping happens BEFORE inner delegation — the architectural fix for Gap #1. |
| `TenantMailerDecorator::stamp` | `TenantContext::getTenant` | constructor-injected `$this->context` | **WIRED** | TenantMailerDecorator.php:67 — reads active tenant; returns early if null. |
| `TenantSanitizedTransportException::__construct` | `DsnSanitizer::redact` | direct static call on `$previous->getDebug()` | **WIRED** | Exception.php:49 — sanitizes before `appendDebug`. |
| `SanitizingMailerDecorator` catch | `MailerExceptionInterface` | `\Throwable` catch + `instanceof` narrowing | **WIRED** | SanitizingMailerDecorator.php:49-73. |
| `TenantAwareTransportsDecorator::send` | empty/charset slug guards | inline checks at line 89 + 98 | **WIRED** | Guards execute before provider call and cross-tenant guard. |
| All previously WIRED links from Run 1 | unchanged | various | UNCHANGED — WIRED | Bootstrapper chain, MessageEvent listener, TenantContextClearedListener, MailerTransportContractPass, etc. |

### Data-Flow Trace (Level 4) — Re-traced

Production mailer dispatch under `testSyncDispatchUsesTenantDsnWithoutPreStamping` (zero-boilerplate path):

| Step | Variable | Source | Produces Real Data | Status |
|---|---|---|---|---|
| 1. User calls `$mailer->send($email)` | `$email` (no X-Transport) | application code | Yes (synthetic Email) | FLOWING |
| 2. `SanitizingMailerDecorator::send` enters try block | `$message` | inner delegation | passthrough | FLOWING |
| 3. `TenantMailerDecorator::send` calls `stamp($message)` | `$tenant = $this->context->getTenant()` | active TenantContext | tenant 'acme' with DSN | FLOWING |
| 4. `stamp()` adds `X-Transport: tenant_acme`, From, Reply-To | `$headers` mutated | tenant getters | real DSN string | FLOWING |
| 5. Inner Mailer routes to `mailer.transports` (TenantAwareTransportsDecorator) | `X-Transport` header value | upstream stamp | `tenant_acme` | FLOWING |
| 6. TenantAwareTransportsDecorator routes by slug, builds via SpyTransportFactory | `$sends[0]['dsn']` | SpyTransport records | tenant A DSN | FLOWING |

The HOLLOW_PROP from Run 1 (X-Transport header source was test pre-stamping) is now **FLOWING** — the source is the active TenantContext via TenantMailerDecorator.

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|---|---|---|---|
| Full PHPUnit suite passes (post-closure) | `vendor/bin/phpunit --no-progress` | 545 tests, 2011 assertions, OK | PASS |
| AsyncCanaryTest passes including 3 new tests | `vendor/bin/phpunit tests/Integration/Mailer/AsyncCanaryTest.php` | 5 tests, 22 assertions, OK | PASS |
| Gap-closure unit tests pass | `vendor/bin/phpunit tests/Unit/Mailer/TenantMailerDecoratorTest.php tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php tests/Unit/Exception/TenantSanitizedTransportExceptionTest.php tests/Unit/Mailer/SanitizingMailerDecoratorTest.php tests/Unit/Mailer/DsnSanitizerTest.php` | 52 tests, 115 assertions, OK | PASS |
| PHPStan level 9 still clean | `vendor/bin/phpstan analyse --memory-limit=512M --no-progress` | No errors | PASS |
| WR-08 fix: X-Transport no longer removed | `grep -c "remove..X-Transport" src/Mailer/TenantAwareTransportsDecorator.php` | 0 | PASS |
| TenantMailerDecorator wired at priority 10 | `grep -c "->decorate('mailer', null, 10)" config/services.php` | 1 | PASS |
| SanitizingMailerDecorator wired at priority 0 (explicit) | `grep -c "->decorate('mailer', null, 0)" config/services.php` | 1 | PASS |
| TenantMessageDecorator UNCHANGED (defense-in-depth) | `git log --oneline -- src/Mailer/TenantMessageDecorator.php` | last touched before 20-09 | PASS |
| Security grep over production code | `grep -rE ':[^*][^*@]*@' src/` filtered | Only doc comments + defensive checks; no actual production DSNs | PASS |
| Empty-slug guard precedes cross-tenant guard | Source inspection of TenantAwareTransportsDecorator.php:89→98→110 | Order correct | PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|---|---|---|---|---|
| BOOT-04 (a) | 20-03 | `MailerBootstrapper implements TenantBootstrapperInterface`; optional dep guarded | SATISFIED | Unchanged from Run 1. |
| BOOT-04 (b) | 20-03 / 20-09 | X-Transport stamped BEFORE Messenger serialization | **SATISFIED** | TenantMailerDecorator stamps at MailerInterface::send entry point (i.e. before Symfony's Mailer routes to mailer.transports → SendEmailMessage). TenantMessageDecorator still wired as defense-in-depth. `testSyncDispatchUsesTenantDsnWithoutPreStamping` + `testAsyncDispatchWithoutPreStampingSurvivesMessengerRoundTrip` operationally confirm the contract. |
| BOOT-04 (c) | 20-01 | `TenantMailerConfigTrait` + Tenant entity column + migration | SATISFIED | Unchanged. |
| BOOT-04 (d) | 20-01 | `TenantInterface::getMailerDsn` BC break + UPGRADE doc | SATISFIED | Unchanged. |
| BOOT-04 (e) | 20-04 | Compile-time guard rejects missing strategy + async without x_transport | SATISFIED | Unchanged. |
| BOOT-04 (f) | 20-02 / 20-10 | DSN credentials never appear in exception traces or logs | **SATISFIED** | getMessage redacted (unchanged) + getDebug redacted (Plan 20-10 constructor override) + bridge-factory throws sanitized (Plan 20-10 widened catch). Load-bearing assertion `testGetDebugContainsNoUnredactedPasswordPattern` passes. |
| BOOT-04 (g) | 20-06 / 20-09 | Async canary | **SATISFIED** | 5 canary tests pass — including the 3 new zero-boilerplate tests from Plan 20-09. |
| BOOT-04 (h) | 20-05 | Cache cleared on TenantContextCleared | SATISFIED | Unchanged. |

All 8 BOOT-04 acceptance bullets cross-referenced against the codebase: **8/8 SATISFIED**. (Previous run: 6/8 satisfied, 2 BLOCKED/PARTIAL.)

### Anti-Patterns Found

Compared to Run 1 — **3 BLOCKERs resolved**. Remaining warnings/info are unchanged from Run 1 and out of scope for this gap-closure wave (they were tracked as Plan 21+ items in the REVIEW.md):

| File | Pattern | Severity | Status |
|---|---|---|---|
| `src/Exception/TenantSanitizedTransportException.php` | BL-01 (getDebug leak) | ~~BLOCKER~~ | **RESOLVED — constructor override sanitizes getDebug** |
| `src/Mailer/TenantAwareTransportsDecorator.php` | BL-02 (empty-slug routing) | ~~BLOCKER~~ | **RESOLVED — empty + charset guards added** |
| `src/Mailer/TenantMessageDecorator.php` | Architectural — listener fires AFTER routing | ~~BLOCKER~~ | **RESOLVED — TenantMailerDecorator added at MailerInterface::send for upstream stamping; TenantMessageDecorator retained as defense-in-depth** |
| `src/Mailer/SanitizingMailerDecorator.php` | WR-01 (narrow catch) | ~~WARNING~~ | **RESOLVED — catch widened to `\Throwable` + MailerExceptionInterface narrowing** |
| `src/Mailer/TenantAwareTransportsDecorator.php` line "remove X-Transport" | WR-08 (header mutation footgun) | ~~WARNING~~ | **RESOLVED — line deleted in 20-09; re-send regression test added** |
| `src/Mailer/LruTransportCache.php` | WR-06 (stop()-throws-aborts-clear) | WARNING | Unchanged — out of scope for gap-closure wave |
| `src/Mailer/DsnSanitizer.php` | WR-07 (over-redaction) | ~~INFO~~ | **RESOLVED in 20-10 — regex tightened to require literal `://`** |
| `src/Command/Install/Step/MailerSetupStep.php` | WR-02/03/04 (migration nits) | WARNING | Unchanged — out of scope |
| `src/Command/TenancyInstallCommand.php` | WR-05 (Windows path) | INFO | Unchanged — out of scope |
| `src/DependencyInjection/Compiler/MailerTransportContractPass.php` | WR-09 (env-var bypass) | WARNING | Unchanged — out of scope |

**Net resolution from gap-closure wave:** 3 BLOCKERs → 0 BLOCKERs; 1 WARNING → 0 WARNINGs; 1 INFO → 0 INFOs. Remaining items are scheduled for future phases per the REVIEW.md ownership table.

### Human Verification Required

None automatically required. Every gap-closure outcome is observable via code inspection + test runs, all of which were performed in this verification run.

### Re-verification Regression Check

Per the re-verification protocol, items that passed in Run 1 received a quick regression check:

- Bootstrapper chain wiring — UNCHANGED, suite green.
- LRU cache + cleanup listener — UNCHANGED, `LongRunningWorkerSimulationTest` still passes.
- MailerTransportContractPass — UNCHANGED, ContainerCompilationTest still passes.
- TenantInterface + TenantMailerConfigTrait + UPGRADE.md — UNCHANGED.
- Profiler integration — UNCHANGED.
- Install command + MailerSetupStep — UNCHANGED.
- Full suite count: 519 → 545 (+26 tests from gap-closure work, 2011 assertions); zero pre-existing tests went from green to red.

**No regressions detected.**

## Gaps Summary

**None.** The 3 BLOCKER gaps from Run 1 are closed and verified against the codebase, not just against SUMMARY claims. The phase goal is now operationally achieved:

> A tenant with a `mailerDsn` configured sends mail from that DSN with the tenant's `From`/`Reply-To` headers — correct under BOTH synchronous Mailer dispatch AND Messenger-routed async dispatch.

The two missing operational proofs (zero-boilerplate sync routing and zero-boilerplate async routing) now have dedicated integration tests that exercise the production code path end-to-end without test-side header pre-stamping. The DSN data-leak vector via `getDebug()` is closed with a load-bearing regex-based test invariant. The empty/malformed-slug routing vector is closed with 4 dedicated unit tests covering empty, path-traversal, whitespace, and uppercase variants, plus a happy-path regression.

Phase 20 is ready to proceed to Phase 21.

---

_Re-verified: 2026-05-20T13:30:00Z_
_Verifier: Claude (gsd-verifier) — Run 2 after gap-closure plans 20-09, 20-10, 20-11_
_Previous run: 2026-05-20T07:42:07Z, status=gaps_found, score=3/6_
