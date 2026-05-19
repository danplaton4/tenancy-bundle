---
phase: 20-mailer-bootstrapper
plan: 02
subsystem: mailer
tags: [mailer, security, lru, decorator, sanitization, tdd]

requires:
  - phase: 20-mailer-bootstrapper
    plan: 00
    provides: "Plan 00 stub tests (DsnSanitizerTest, LruTransportCacheTest, SanitizingMailerDecoratorTest) — converted from markTestIncomplete stubs to passing behavior suites"
provides:
  - "Tenancy\\Bundle\\Mailer\\DsnSanitizer — single source of truth for DSN password redaction (REDACTION_REGEX constant)"
  - "Tenancy\\Bundle\\Mailer\\LruTransportCache — bounded LRU (default 32) with stop()-on-eviction semantics + hits/evictions counters"
  - "Tenancy\\Bundle\\Mailer\\SanitizingMailerDecorator — MailerInterface decorator wrapping TransportExceptionInterface in redacted TenantSanitizedTransportException"
  - "Tenancy\\Bundle\\Exception\\TenantSanitizedTransportException — extends Symfony TransportException, preserves catch-block contract"
  - "tests/Unit/Mailer/Fixture/{StoppableSpyTransport,PlainSpyTransport}.php — reusable spies for downstream cache/transport tests"
affects: [20-03, 20-04, 20-05]

tech-stack:
  added: []
  patterns:
    - "TDD execution gate: each task split into RED commit (failing test) → GREEN commit (passing implementation); 6 atomic commits across 3 tasks"
    - "method_exists() guard for SmtpTransport::stop() — graceful behavior when cached transport is null/sendmail/etc"
    - "PHP-array LRU pattern: insertion order = LRU order; unset()+re-insert is move-to-end; array_key_first() yields the LRU slot"
    - "Single regex constant published as public class constant (DsnSanitizer::REDACTION_REGEX) for downstream consumers — prevents drift between decorator and profiler panel"

key-files:
  created:
    - src/Mailer/DsnSanitizer.php
    - src/Mailer/LruTransportCache.php
    - src/Mailer/SanitizingMailerDecorator.php
    - src/Exception/TenantSanitizedTransportException.php
    - tests/Unit/Exception/TenantSanitizedTransportExceptionTest.php
    - tests/Unit/Mailer/Fixture/StoppableSpyTransport.php
    - tests/Unit/Mailer/Fixture/PlainSpyTransport.php
  modified:
    - tests/Unit/Mailer/DsnSanitizerTest.php
    - tests/Unit/Mailer/LruTransportCacheTest.php
    - tests/Unit/Mailer/SanitizingMailerDecoratorTest.php

key-decisions:
  - "Phase 20-02: DsnSanitizer::REDACTION_REGEX exposed as public class constant — locks the regex shape early (Wave 1) so the Plan 04 profiler panel and Plan 02 decorator cannot drift"
  - "Phase 20-02: SanitizingMailerDecorator catches TransportExceptionInterface (not just the concrete TransportException) — covers all Symfony Mailer exception subclasses including EnvelopeAwareTransportException"
  - "Phase 20-02: LruTransportCache uses array_key_first() + unset/re-insert idiom rather than SplDoublyLinkedList — same O(1) cost, simpler code, mirrors Symfony's own ArrayAdapter LRU implementation"
  - "Phase 20-02: stop() invocation on evicted transports guarded by method_exists() — handles transports without the method (NullTransport, SendmailTransport) without try/catch noise"
  - "Phase 20-02: spy transports extracted to tests/Unit/Mailer/Fixture/ (separate files) — enables reuse from AsyncCanaryTest (Plan 06) and TenantAwareTransportsDecoratorTest (Plan 03), avoids cross-class collision in test namespace"

patterns-established:
  - "TDD RED commit message format: 'test(20-02): add failing tests for X' — pinpoints the gate checkpoint in git log"
  - "TDD GREEN commit message format: 'feat(20-02): add X with Y' — implementation lands in the second commit per task"
  - "Spy transport fixture pattern: final class + public counter properties + minimal docblock referencing the BOOT-04 row that motivates them"

requirements-completed: [BOOT-04]

duration: ~5min
completed: 2026-05-19
---

# Phase 20 Plan 02: Sanitizing Mailer Primitives Summary

**Shipped the four leaf-level Mailer primitives (DsnSanitizer, LruTransportCache, SanitizingMailerDecorator, TenantSanitizedTransportException) plus 22 new behavior tests — converts Plan 00's 4 stub tests into a passing 26-test suite and unblocks Plan 03 (wiring) and Plan 04 (profiler / canary).**

## Performance

- **Duration:** ~5 min
- **Started:** 2026-05-19T21:52:09Z
- **Completed:** 2026-05-19T21:57:32Z
- **Tasks:** 3 (all TDD — RED+GREEN per task)
- **Commits:** 6 (3 RED test commits + 3 GREEN feature commits)
- **Files created:** 7 (4 src + 3 tests/fixtures)
- **Files modified:** 3 (Plan 00 stub tests converted to real suites)

## Class Inventory

| FQCN | Public Surface |
|------|----------------|
| `Tenancy\Bundle\Mailer\DsnSanitizer` | `const REDACTION_REGEX`, `const REPLACEMENT`, `public static function redact(?string $dsn): ?string` |
| `Tenancy\Bundle\Mailer\LruTransportCache` | `__construct(int $maxSize = 32)`, `get(string): ?TransportInterface`, `set(string, TransportInterface): void`, `clear(): void`, `size(): int`, `maxSize(): int`, `hits(): int`, `evictions(): int` |
| `Tenancy\Bundle\Mailer\SanitizingMailerDecorator` | `__construct(MailerInterface $inner)`, `send(RawMessage, ?Envelope = null): void` |
| `Tenancy\Bundle\Exception\TenantSanitizedTransportException` | Inherits parent `__construct(string $message = '', int $code = 0, ?\Throwable $previous = null)` |

## Test Mapping

| Source class | Test class | Tests | Assertions |
|--------------|-----------|------:|-----------:|
| `DsnSanitizer` | `tests/Unit/Mailer/DsnSanitizerTest.php` | 7 | 7 |
| `LruTransportCache` | `tests/Unit/Mailer/LruTransportCacheTest.php` | 9 | 24 |
| `SanitizingMailerDecorator` | `tests/Unit/Mailer/SanitizingMailerDecoratorTest.php` | 6 | 18 |
| `TenantSanitizedTransportException` | `tests/Unit/Exception/TenantSanitizedTransportExceptionTest.php` | 4 | 6 |
| **Total** | | **26** | **55** |

## Regex Constant (character-for-character)

```
DsnSanitizer::REDACTION_REGEX = '/(:[\/]{0,2}[^:]+:)[^@]+(@)/'
DsnSanitizer::REPLACEMENT     = '$1***$2'
```

Matches D-06 specification verbatim. Verified via grep against the source file
and exercised by the 7 DsnSanitizer behavior tests covering `smtp://`,
`smtps://`, `sendmail://`, null/empty passthrough, and non-DSN strings.

## Task Commits

Each task ran the full TDD gate sequence (RED → GREEN):

1. **Task 1: DsnSanitizer + TenantSanitizedTransportException**
   - `4b694a0` — test(20-02): RED — 11 failing tests
   - `4c99dec` — feat(20-02): GREEN — implementation, all 11 pass
2. **Task 2: LruTransportCache**
   - `ab9a9fa` — test(20-02): RED — 9 failing tests + 2 spy fixtures
   - `7015589` — feat(20-02): GREEN — implementation, all 9 pass
3. **Task 3: SanitizingMailerDecorator**
   - `34731f6` — test(20-02): RED — 6 failing tests
   - `07a69bb` — feat(20-02): GREEN — implementation, all 6 pass

## Files Created/Modified

### Created — Source
- `src/Mailer/DsnSanitizer.php` — 28 lines, single static helper, public REDACTION_REGEX constant
- `src/Mailer/LruTransportCache.php` — 96 lines, final class, default `maxSize=32`, stop()-on-eviction
- `src/Mailer/SanitizingMailerDecorator.php` — 39 lines, MailerInterface decorator, catches TransportExceptionInterface
- `src/Exception/TenantSanitizedTransportException.php` — 22 lines, extends Symfony TransportException

### Created — Tests
- `tests/Unit/Exception/TenantSanitizedTransportExceptionTest.php` — 4 tests, mailer-presence guard
- `tests/Unit/Mailer/Fixture/StoppableSpyTransport.php` — 41-line spy with `stopCalls` counter
- `tests/Unit/Mailer/Fixture/PlainSpyTransport.php` — 32-line spy without `stop()` method

### Modified — Tests (converted from Plan 00 stubs)
- `tests/Unit/Mailer/DsnSanitizerTest.php` — 7 behavior tests
- `tests/Unit/Mailer/LruTransportCacheTest.php` — 9 behavior tests
- `tests/Unit/Mailer/SanitizingMailerDecoratorTest.php` — 6 behavior tests

## Decisions Made

- **Public REDACTION_REGEX constant:** Made `DsnSanitizer::REDACTION_REGEX` and `::REPLACEMENT` public constants — downstream consumers (Plan 04 profiler panel) can reference the regex without copy-pasting it, and a future audit grep can lock the regex to a single source.
- **Catch interface, not concrete class:** `SanitizingMailerDecorator` catches `TransportExceptionInterface` so concrete subclasses (`EnvelopeAwareTransportException`, etc.) are covered without per-subclass updates.
- **`method_exists()` guard for `stop()`:** Avoids try/catch noise and works for transports that don't expose `stop()` (NullTransport, SendmailTransport). Verified by `testPlainTransportWithoutStopIsEvictedGracefully`.
- **Spy fixtures in separate files:** Extracted `StoppableSpyTransport` and `PlainSpyTransport` to `tests/Unit/Mailer/Fixture/` so Plans 03 (TenantAwareTransports) and 06 (AsyncCanary) can reuse them.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Worktree lacked `composer.lock` / `vendor/`**
- **Found during:** Task 1 pre-flight (vendor/bin/phpunit not present)
- **Issue:** Worktrees only share git-tracked files; `composer.lock` is gitignored, so the worktree had no installed dependencies — PHPUnit could not run. Same Rule-3 blocker documented in Plan 20-00 SUMMARY.
- **Fix:** `cp ../../../composer.lock ./` then `composer update symfony/mailer symfony/mime --no-interaction` to install the locked + new (mailer/mime) deps into this worktree's `vendor/`. Both files remain gitignored.
- **Files modified:** none committed (composer.lock + vendor/ stay gitignored)
- **Verification:** `vendor/bin/phpunit --version` returns `PHPUnit 11.5.55`; `vendor/bin/phpstan --version` returns `2.1.50`.
- **Committed in:** n/a — workspace setup, not committed.

**2. [Rule 2 — Missing Critical] Unnecessary `@phpstan-ignore-next-line` annotations on spy fixtures**
- **Found during:** Task 2 post-implementation PHPStan run
- **Issue:** I initially added `@phpstan-ignore-next-line` to the spy `send()` methods (copying the Plan 00 pattern from `SpyTransport`). PHPStan 2.1.50 reported `ignore.unmatchedLine — No error to ignore is reported on line 26` because the return type already matches the interface, so there's nothing to ignore.
- **Fix:** Removed the annotation, kept a plain comment explaining the null return.
- **Files modified:** `tests/Unit/Mailer/Fixture/StoppableSpyTransport.php`, `tests/Unit/Mailer/Fixture/PlainSpyTransport.php`
- **Verification:** `vendor/bin/phpstan analyse … --level=9` exits 0 with "No errors".
- **Committed in:** `7015589` (Task 2 GREEN commit — same task, single touch).

**Total deviations:** 2 auto-fixed (1 blocking workspace setup, 1 phpstan hygiene). Both are workspace/lint hygiene; neither changes plan behavior.

## Issues Encountered

None — TDD RED gates passed cleanly (every test failed as expected before implementation), GREEN gates passed cleanly (every test passed after implementation). No retries needed.

## Threat Surface Audit

`<threat_model>` enumerated 4 threats (T-20-02-01..04). All mitigations confirmed:

- **T-20-02-01 (Info Disclosure on TransportException):** `SanitizingMailerDecorator` catches `TransportExceptionInterface` (verified by `grep -c 'catch (TransportExceptionInterface'` → 1); behavior test `testTransportExceptionMessageIsRedacted` explicitly asserts the password substring `hunter2` is NOT present in the redacted message.
- **T-20-02-02 (DSN in previous-pointer chain):** Accepted per plan — `getPrevious()` returns the original exception verbatim; documented in the threat register and asserted by `testRethrowPreservesCodeAndPrevious`.
- **T-20-02-03 (LRU DoS via unbounded growth):** `LruTransportCache::set()` enforces `count >= maxSize` before insertion; `testEvictedTransportHasStopCalled` verifies `stop()` is invoked on the LRU entry; `testClearStopsAllAndEmpties` verifies `clear()` stops every cached transport.
- **T-20-02-04 (cross-tenant key reuse):** Cache keyed by tenant slug string; `testGetReturnsNullOnEmptyCache` + `testSetThenGetRoundTrip` jointly establish no cross-key leakage at the cache layer.

No new threat surface introduced beyond what `<threat_model>` enumerated.

## Validation Compliance

- ✅ All 4 source files exist and are `final` with `declare(strict_types=1)`
- ✅ All 4 test files pass — 26 tests, 55 assertions, 0 failures, 0 errors, 0 skipped
- ✅ Unit suite regression check: `vendor/bin/phpunit --testsuite unit` → 344 tests, 893 assertions, 5 incomplete (remaining Wave 0 stubs for plans 03-08), 0 failures, 0 errors
- ✅ `vendor/bin/phpstan analyse src/Mailer/ src/Exception/TenantSanitizedTransportException.php tests/Unit/Mailer/ tests/Unit/Exception/TenantSanitizedTransportExceptionTest.php --level=9` → "No errors"
- ✅ Regex constant `DsnSanitizer::REDACTION_REGEX` verified by grep against D-06 specification
- ✅ Smoke checks pass: redact returns expected strings for SMTP/null/empty inputs; decorator-wrapped TransportException emits `***` not the password

## TDD Gate Compliance

Plan-type `execute` (not `tdd`), but every task ran with `tdd="true"` — RED+GREEN gate sequence verified per task:

| Task | RED commit | GREEN commit | Gate order |
|------|------------|--------------|------------|
| 1    | `4b694a0`  | `4c99dec`    | ✅ RED → GREEN |
| 2    | `ab9a9fa`  | `7015589`    | ✅ RED → GREEN |
| 3    | `34731f6`  | `07a69bb`    | ✅ RED → GREEN |

No REFACTOR commits required — all three implementations passed PHPStan level 9 on the first try and required no follow-up cleanup beyond removing two unused `@phpstan-ignore` annotations (folded into the Task 2 GREEN commit, not a separate REFACTOR).

## Next Plan Readiness

- **Plan 20-03 (Wiring):** can consume `LruTransportCache` as a DI service (`tenancy.mailer.transport_cache`) and `SanitizingMailerDecorator` as the `cache.app`-style decorator of `mailer.mailer`. No further interface work needed.
- **Plan 20-04 (Profiler + AsyncCanary):** can reference `DsnSanitizer::REDACTION_REGEX` (public constant) from the `TenantDataCollector` template renderer, and reuse `StoppableSpyTransport` / `PlainSpyTransport` from `tests/Unit/Mailer/Fixture/`.
- **Plan 20-05 (DSN sanitization + profiler):** the `DsnSanitizer` static helper is the final implementation — no further drift risk.

No blockers for downstream waves.

## Self-Check: PASSED

Verified all 10 created/modified files exist on disk and all 6 task commits are present in git log (ffbf2115c5d7bb50975d38adbafb8ed95f2f45ce..HEAD):

```
07a69bb feat(20-02): add SanitizingMailerDecorator wrapping TransportException
34731f6 test(20-02): add failing tests for SanitizingMailerDecorator
7015589 feat(20-02): add LruTransportCache with stop()-on-eviction semantics
ab9a9fa test(20-02): add failing tests + fixtures for LruTransportCache
4c99dec feat(20-02): add DsnSanitizer and TenantSanitizedTransportException
4b694a0 test(20-02): add failing tests for DsnSanitizer and TenantSanitizedTransportException
```

---
*Phase: 20-mailer-bootstrapper*
*Completed: 2026-05-19*
