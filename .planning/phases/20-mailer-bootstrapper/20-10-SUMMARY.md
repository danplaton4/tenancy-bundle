---
phase: 20-mailer-bootstrapper
plan: 10
subsystem: mailer
tags: [mailer, exception, sanitization, security, gap-closure, dsn, redaction]

# Dependency graph
requires:
  - phase: 20-mailer-bootstrapper
    provides: DsnSanitizer + TenantSanitizedTransportException + SanitizingMailerDecorator (Plan 04 — base sanitization)
provides:
  - "Airtight `no credentials anywhere` contract on mailer-component exceptions — DSN redaction now covers message, debug, and bridge-factory throw surfaces"
  - "Tightened DsnSanitizer regex that requires literal `://` shape (no longer mangles free-text colons; failover composite DSNs get every password redacted)"
  - "TenantSanitizedTransportException::getDebug() sanitized at construction via appendDebug(DsnSanitizer::redact(...))"
  - "Load-bearing security-grep assertion (testGetDebugContainsNoUnredactedPasswordPattern) that proves no `:<password>@` pattern survives sanitization"
affects: [20-verification, mailer-bootstrapper-acceptance, future-bridge-additions]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Defensive Throwable+instanceof catch arm — when an interface @throws contract is narrower than runtime reality, catching \\Throwable + instanceof Marker satisfies both PHPStan strict-mode and security intent"
    - "Constructor-level invariant enforcement on exception subclasses (sanitize debug at __construct rather than relying on caller discipline)"

key-files:
  created: []
  modified:
    - "src/Mailer/DsnSanitizer.php — tightened REDACTION_REGEX to require literal `://`"
    - "src/Exception/TenantSanitizedTransportException.php — constructor override that sanitizes previous TransportException's getDebug via appendDebug + DsnSanitizer::redact"
    - "src/Mailer/SanitizingMailerDecorator.php — second catch arm (Throwable + instanceof MailerExceptionInterface) re-throwing non-TransportException Mailer kinds as \\RuntimeException with sanitized message"
    - "tests/Unit/Mailer/DsnSanitizerTest.php — 3 new cases (failover composite, free-text colon, idempotency)"
    - "tests/Unit/Exception/TenantSanitizedTransportExceptionTest.php — 5 new cases including load-bearing security-grep assertion"
    - "tests/Unit/Mailer/SanitizingMailerDecoratorTest.php — 3 new cases (UnsupportedSchemeException, Mailer\\InvalidArgumentException, global RuntimeException boundary)"

key-decisions:
  - "Use appendDebug() (not setDebug) on the wrapper — symfony/mailer 8.x's TransportException only exposes appendDebug + getDebug, not setDebug; semantically equivalent on a freshly-constructed wrapper whose internal \$debug starts at ''."
  - "Catch \\Throwable + runtime instanceof MailerExceptionInterface (rather than catch MailerExceptionInterface directly) so PHPStan level 9 does not flag catch.neverThrown — MailerInterface::send declares only TransportExceptionInterface in @throws even though real bridge factories DO throw non-TransportException Mailer kinds in practice."
  - "Re-throw non-TransportException Mailer exceptions as \\RuntimeException (not as TenantSanitizedTransportException) — preserves the IS-A TransportException type contract for user catch-blocks on the original class."
  - "Global \\RuntimeException remains uncaught — boundary verified by testGlobalRuntimeExceptionStillPropagatesUnchanged."

patterns-established:
  - "Defensive Throwable+instanceof catch: when an interface contract is narrower than runtime reality, catching \\Throwable and narrowing via instanceof inside the catch satisfies both strict-mode static analysis (PHPStan level 9, catch.neverThrown) and security intent (defensive sanitization of any matching exception)."
  - "Constructor-time invariant enforcement: exception subclasses that need to guarantee a sanitization invariant should do the work in __construct (not as a separate method users must remember to call) — testGetDebugIsRedactedWhenPreviousTransportExceptionHasDebug + load-bearing regex grep are the regression catchers."

requirements-completed: [BOOT-04]

# Metrics
duration: 8min
completed: 2026-05-20
---

# Phase 20 Plan 10: Exception sanitization hardening Summary

**Closed BL-01 + WR-01 + WR-07 gaps from 20-VERIFICATION.md: `TenantSanitizedTransportException::getDebug()` now sanitized at construction, `SanitizingMailerDecorator` catches both `TransportExceptionInterface` and Mailer `ExceptionInterface`, and `DsnSanitizer::REDACTION_REGEX` requires literal `://` so failover composites are fully redacted and free-text colons are not mangled.**

## Performance

- **Duration:** ~8 min
- **Started:** 2026-05-20T09:58:43Z
- **Completed:** 2026-05-20T10:06:40Z
- **Tasks:** 3 (all atomic, all green)
- **Files modified:** 6 (3 src + 3 test)
- **Tests added:** 11 unit tests (3 DsnSanitizer + 5 TenantSanitizedTransportException + 3 SanitizingMailerDecorator)
- **Test count:** 402 baseline → 413 with new tests (all green, 1117 assertions)

## Accomplishments

- **BL-01 closed:** `TenantSanitizedTransportException` overrides parent constructor to copy + sanitize `$previous->getDebug()` via `DsnSanitizer::redact()` + `appendDebug()`. The wrapper's own `getDebug()` now always returns a redacted string. Cause chain (`getPrevious()`) preserved for stack-trace diagnostics.
- **WR-01 closed:** `SanitizingMailerDecorator::send()` now catches a second arm beyond `TransportExceptionInterface` — any `\Throwable` instance of `Mailer\Exception\ExceptionInterface` (e.g., `UnsupportedSchemeException`, `Mailer\InvalidArgumentException`, `Mailer\LogicException`) is re-thrown as `\RuntimeException` with a redacted message. Global `\RuntimeException` (non-mailer) still propagates unchanged.
- **WR-07 closed:** `DsnSanitizer::REDACTION_REGEX` tightened from `'/(:[\/]{0,2}[^:]+:)[^@]+(@)/'` (matched free-text colons because `[\/]{0,2}` accepted zero slashes) to `'/(:\/\/[^:\/@]+:)[^@\/]+(@)/'` requiring literal `://`. Failover composite DSNs now have every password redacted (the `@` anchor terminates each match before the next `://` segment).
- **Load-bearing security assertion:** `testGetDebugContainsNoUnredactedPasswordPattern` greps the wrapper's getDebug output for any `:<password>@` pattern where the password is not `***` — any match would fail the test. Currently asserts 0 matches against an SMTP+sendmail composite debug payload.
- **Defense-in-depth coverage:** `grep -Erno '://[^:/@]+:(?!\*\*\*@)[^@/]+@' src/` returns 0 — no production code path has an unredacted-password DSN pattern anywhere in src/.

## Task Commits

Each task was committed atomically with `--no-verify` (per parallel-executor protocol — orchestrator validates hooks once after both wave-A agents complete):

1. **Task 1: Tighten DsnSanitizer REDACTION_REGEX (WR-07) + 3 new tests** — `1f03331` (fix)
2. **Task 2: Override TenantSanitizedTransportException constructor (BL-01) + 5 new tests** — `b82f6e2` (fix)
3. **Task 3: Widen SanitizingMailerDecorator catch (WR-01) + 3 new tests** — `775e7c8` (fix)

## Code Changes

### A. DsnSanitizer regex (Task 1)

Old:

```php
public const REDACTION_REGEX = '/(:[\/]{0,2}[^:]+:)[^@]+(@)/';
```

New:

```php
// Tightened in Plan 20-10 (REVIEW WR-07): require the literal `://` DSN
// shape so free-text colons (e.g., "smtp:587 timeout") do not match.
// Covers smtp://, smtps://, sendmail://, and any other scheme of the
// form <scheme>://<user>:<password>@<host>[/<path>]. Composite DSNs
// like failover(smtp://u:p@h1 smtp://u:p@h2) work because preg_replace
// is non-greedy on `[^@\/]+` and the `(@)` anchor terminates each match
// before the next `://` segment.
public const REDACTION_REGEX = '/(:\/\/[^:\/@]+:)[^@\/]+(@)/';
```

### B. TenantSanitizedTransportException constructor (Task 2)

Old: empty subclass inheriting parent constructor.

New constructor:

```php
public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
{
    parent::__construct($message, $code, $previous);

    if ($previous instanceof TransportException) {
        $debug = $previous->getDebug();
        if ('' !== $debug) {
            $this->appendDebug(DsnSanitizer::redact($debug) ?? '');
        }
    }
}
```

### C. SanitizingMailerDecorator catch (Task 3)

Old:

```php
try {
    $this->inner->send($message, $envelope);
} catch (TransportExceptionInterface $e) {
    $sanitized = DsnSanitizer::redact($e->getMessage()) ?? $e->getMessage();
    throw new TenantSanitizedTransportException($sanitized, $e->getCode(), $e);
}
```

New:

```php
try {
    $this->inner->send($message, $envelope);
} catch (TransportExceptionInterface $e) {
    // TransportException kinds — preserve the IS-A TransportException
    // type contract for user catch-blocks.
    $sanitized = DsnSanitizer::redact($e->getMessage()) ?? $e->getMessage();
    throw new TenantSanitizedTransportException($sanitized, $e->getCode(), $e);
} catch (\Throwable $e) {
    // Plan 20-10 / REVIEW WR-01 — bridge-factory throws ... implement Mailer's
    // ExceptionInterface but NOT TransportExceptionInterface, so they are NOT
    // covered by MailerInterface::send's `@throws` declaration (which is why
    // we must catch \Throwable here rather than the narrower
    // MailerExceptionInterface — PHPStan would flag the narrower catch as dead
    // code per the interface contract, even though real bridge factories DO
    // throw these from send() in practice). We then narrow at runtime: only
    // MailerExceptionInterface gets sanitized + wrapped; anything else
    // re-throws as-is to preserve the existing testNonTransportExceptionPropagatesAsIs
    // contract for non-mailer exceptions.
    if (!$e instanceof MailerExceptionInterface) {
        throw $e;
    }
    $sanitized = DsnSanitizer::redact($e->getMessage()) ?? $e->getMessage();
    throw new \RuntimeException($sanitized, $e->getCode(), $e);
}
```

## Test Counts

| Test File | Existing | Added | Total |
|-----------|----------|-------|-------|
| `tests/Unit/Mailer/DsnSanitizerTest.php` | 7 | 3 | 10 |
| `tests/Unit/Exception/TenantSanitizedTransportExceptionTest.php` | 4 | 5 | 9 |
| `tests/Unit/Mailer/SanitizingMailerDecoratorTest.php` | 6 | 3 | 9 |
| **Total new** | — | **11** | — |

New methods added:

- DsnSanitizerTest: `testFailoverCompositeRedactsAllPasswords`, `testDoesNotMangleFreeTextColons`, `testIdempotentOnAlreadyRedactedDsn`
- TenantSanitizedTransportExceptionTest: `testGetDebugIsRedactedWhenPreviousTransportExceptionHasDebug`, `testGetDebugIsEmptyStringWhenPreviousIsNotTransportException`, `testGetDebugIsEmptyStringWhenNoPrevious`, `testGetDebugIsEmptyStringWhenPreviousTransportExceptionHasEmptyDebug`, `testGetDebugContainsNoUnredactedPasswordPattern` (load-bearing security grep)
- SanitizingMailerDecoratorTest: `testUnsupportedSchemeExceptionMessageIsRedacted`, `testMailerInvalidArgumentExceptionMessageIsRedacted`, `testGlobalRuntimeExceptionStillPropagatesUnchanged`

## Verification Results

- **Unit suite:** `vendor/bin/phpunit --testsuite unit` → 413 tests, 1117 assertions, all green.
- **Targeted suite:** `vendor/bin/phpunit tests/Unit/Exception/TenantSanitizedTransportExceptionTest.php tests/Unit/Mailer/SanitizingMailerDecoratorTest.php tests/Unit/Mailer/DsnSanitizerTest.php` → 28 tests, all green.
- **PHPStan level 9** on modified files: `vendor/bin/phpstan analyse src/Exception/TenantSanitizedTransportException.php src/Mailer/SanitizingMailerDecorator.php src/Mailer/DsnSanitizer.php --level=9` → No errors.
- **Security grep** on src/: `grep -Erno '://[^:/@]+:(?!\*\*\*@)[^@/]+@' src/ | wc -l` → 0 matches.
- **php -l (lint)** on each modified src file → No syntax errors detected.

## Decisions Made

- **appendDebug vs setDebug:** The plan's `<interfaces>` block claimed TransportException has a `setDebug(string)` method. In symfony/mailer 8.x it does not — only `appendDebug(string)` and `getDebug()` are public. On a freshly-constructed wrapper whose internal `$debug` starts as `''`, `appendDebug(...)` is semantically identical to a hypothetical `setDebug(...)`. Used `appendDebug()`.
- **Catch shape:** The plan specified `catch (MailerExceptionInterface $e)` as the second arm. PHPStan level 9 flagged this as `catch.neverThrown` because `MailerInterface::send`'s `@throws` declaration covers only `TransportExceptionInterface`. Restructured to `catch (\Throwable $e) { if (!$e instanceof MailerExceptionInterface) { throw $e; } ... }` to preserve the security intent without violating PHPStan. Documented inline in the source.
- **Re-throw type:** Followed the plan — non-TransportException Mailer kinds are re-thrown as `\RuntimeException`, not as `TenantSanitizedTransportException`, to preserve the IS-A TransportException type contract.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Replaced setDebug() with appendDebug() in TenantSanitizedTransportException constructor + test methods**
- **Found during:** Task 2 (PHPUnit run)
- **Issue:** Plan's `<interfaces>` block documented `TransportException::setDebug(string)` as a public method. In symfony/mailer 8.x (installed via composer in this worktree), TransportException only exposes `appendDebug(string)` + `getDebug(): string`. PHPUnit errored: `Error: Call to undefined method Symfony\Component\Mailer\Exception\TransportException::setDebug()`.
- **Fix:** Replaced all 3 `setDebug` occurrences (1 in src constructor, 2 in test methods) with `appendDebug`. Semantically equivalent on a freshly-constructed exception (internal `$debug` starts as `''`). Updated docblocks.
- **Files modified:** src/Exception/TenantSanitizedTransportException.php, tests/Unit/Exception/TenantSanitizedTransportExceptionTest.php
- **Verification:** PHPUnit re-ran clean (9/9 tests, 13 assertions) after the swap.
- **Committed in:** b82f6e2 (Task 2 commit)

**2. [Rule 1 - Bug] Restructured Mailer ExceptionInterface catch arm to satisfy PHPStan level 9 catch.neverThrown**
- **Found during:** Task 3 (PHPStan verification)
- **Issue:** Plan specified `catch (MailerExceptionInterface $e)` as the second catch arm. PHPStan level 9 flagged this: `Dead catch - Symfony\Component\Mailer\Exception\ExceptionInterface is never thrown in the try block` (catch.neverThrown). The static-analysis surface reads `MailerInterface::send`'s @throws declaration which lists only TransportExceptionInterface — even though real bridge factories DO throw non-TransportException Mailer exceptions in practice. CLAUDE.md / PHPStan output explicitly disallow `@phpstan-ignore` comments and baseline suppressions.
- **Fix:** Restructured to `catch (\Throwable $e) { if (!$e instanceof MailerExceptionInterface) { throw $e; } ... }`. Throwable is genuinely throwable so the catch is not flagged; runtime instanceof narrowing preserves the security intent (only Mailer exceptions get sanitized + wrapped) and the non-mailer-throwable boundary (testGlobalRuntimeExceptionStillPropagatesUnchanged still passes). Documented inline.
- **Files modified:** src/Mailer/SanitizingMailerDecorator.php
- **Verification:** PHPStan level 9 → No errors. testNonTransportExceptionPropagatesAsIs + testGlobalRuntimeExceptionStillPropagatesUnchanged + all bridge-factory tests pass.
- **Committed in:** 775e7c8 (Task 3 commit)

**3. [Rule 1 - Bug] Dropped unused `use Tenancy\Bundle\Mailer\DsnSanitizer;` import from TenantSanitizedTransportExceptionTest**
- **Found during:** Task 2 (test-file edit)
- **Issue:** Plan instructed adding `use Tenancy\Bundle\Mailer\DsnSanitizer;` at the top of the test file. The new test methods exercise the sanitization invariant indirectly via the wrapper's constructor — they never reference `DsnSanitizer::redact()` directly. An unused import would trip php-cs-fixer's @Symfony ruleset (no_unused_imports) on the orchestrator's post-merge hook validation.
- **Fix:** Did not add the import (or, equivalently, removed it before commit).
- **Files modified:** tests/Unit/Exception/TenantSanitizedTransportExceptionTest.php
- **Verification:** PHPUnit clean; would-be cs-fixer no-unused-imports issue avoided.
- **Committed in:** b82f6e2 (Task 2 commit)

---

**Total deviations:** 3 auto-fixed (all Rule 1 — Bug)
**Impact on plan:** All deviations preserve the plan's security intent (sanitize debug at construction, sanitize bridge-factory throws via the decorator). The structural changes (appendDebug, Throwable+instanceof) are documented inline in the source and the SUMMARY. No scope creep.

## Acceptance Criteria — Per-Task Status

### Task 1 (WR-07)
- [x] `grep -c "REDACTION_REGEX = '/(:\\\\/\\\\/" src/Mailer/DsnSanitizer.php` → 1
- [x] `grep -c '\[\\\\/\]{0,2}' src/Mailer/DsnSanitizer.php` → 0 (old shape removed)
- [x] `grep -c 'WR-07' src/Mailer/DsnSanitizer.php` → 2 (regression marker present)
- [x] All 3 new test methods present
- [x] Existing `testRedactsSmtpUserPasswordPair` still present
- [x] `vendor/bin/phpunit tests/Unit/Mailer/DsnSanitizerTest.php` → 10/10 pass

### Task 2 (BL-01)
- [x] `public function __construct(string $message...` declared on the subclass
- [x] `use Tenancy\Bundle\Mailer\DsnSanitizer;` present in src
- [x] `DsnSanitizer::redact` referenced in constructor (3 occurrences counting docblock)
- [x] `$previous instanceof TransportException` branch present
- [x] BL-01 regression marker present (2 occurrences)
- [x] `final class TenantSanitizedTransportException extends TransportException` preserved
- [x] All 5 new test methods present
- [x] Existing `testPreservesMessageCodePrevious` still present
- [x] `vendor/bin/phpunit tests/Unit/Exception/TenantSanitizedTransportExceptionTest.php` → 9/9 pass

### Task 3 (WR-01)
- [x] `use ... ExceptionInterface as MailerExceptionInterface;` present
- [x] `$e instanceof MailerExceptionInterface` runtime check present
- [x] `throw new \RuntimeException($sanitized, ...)` line present
- [x] `catch (TransportExceptionInterface $e)` arm preserved
- [x] WR-01 regression marker present (2 occurrences)
- [x] All 3 new test methods present
- [x] Existing `testNonTransportExceptionPropagatesAsIs` + `testTransportExceptionMessageIsRedacted` still present
- [x] `vendor/bin/phpunit tests/Unit/Mailer/SanitizingMailerDecoratorTest.php` → 9/9 pass
- [x] PHPStan level 9 on modified src files → 0 errors
- [x] Full unit suite → 413/413 pass
- [~] **Deviation note:** `catch (MailerExceptionInterface $e)` was restructured to `catch (\Throwable) + instanceof` per Deviation 2 above; the plan's literal grep-for-`catch (MailerExceptionInterface $e)` criterion is intentionally not met — the security intent and behavior are fully preserved by the runtime narrowing.

## Gap Closure — Per-Review-Marker

- [x] **BL-01 closed** — TenantSanitizedTransportException::getDebug() returns a sanitized string for any previous TransportException with a populated debug payload. Cause chain preserved. Load-bearing security grep (testGetDebugContainsNoUnredactedPasswordPattern) asserts zero unredacted `:<password>@` patterns. Regression catchers: 5 new tests.
- [x] **WR-01 closed** — SanitizingMailerDecorator catches both TransportExceptionInterface (re-thrown as TenantSanitizedTransportException, type contract preserved) and Mailer ExceptionInterface kinds (re-thrown as \RuntimeException with sanitized message). Bridge-factory throws (UnsupportedSchemeException, Mailer\InvalidArgumentException, Mailer\LogicException) now flow through DsnSanitizer. Non-Mailer Throwable (global \RuntimeException, \LogicException, etc.) propagates unchanged.
- [x] **WR-07 closed** — DsnSanitizer regex requires the literal `://` DSN shape. Free-text colons no longer mangled. Failover composite DSNs have every password redacted.

## Issues Encountered

- **Pre-existing integration-suite class-redeclaration issue** (not introduced by this plan): `vendor/bin/phpunit` without `--testsuite unit` errors with `Cannot redeclare class Tenancy\Bundle\Entity\Tenant` because the shared composer vendor in this worktree loads source files from BOTH the main repo's path AND the worktree path. Reproduced at baseline (before any of this plan's changes) — same error on `tests/Integration/Support/Entity/TestProduct.php`. Out of scope per executor scope-boundary rule (the plan only modifies unit-suite files; the unit suite passes 413/413 clean). Logged to deferred-items.

## Self-Check

All claimed artifacts verified.

**Created files exist:**
- `.planning/phases/20-mailer-bootstrapper/20-10-SUMMARY.md` — this file, created by Write tool.

**Commits exist:**
- `1f03331` — Task 1 (DsnSanitizer regex tightening)
- `b82f6e2` — Task 2 (TenantSanitizedTransportException constructor)
- `775e7c8` — Task 3 (SanitizingMailerDecorator catch widening)

## Self-Check: PASSED

## Next Phase Readiness

- BOOT-04 acceptance criterion 5 (`DSN credentials never appear in exception traces or logs`) is now airtight for the mailer-component exception surface.
- Wave A complete from this worktree's side; orchestrator should merge 20-09 + 20-10 worktrees, run the full unit suite + PHPStan + cs-fixer on the merged base, then update STATE.md/ROADMAP.md and proceed to Wave B (re-verification).
- No follow-up plan required for this gap-closure batch.

---
*Phase: 20-mailer-bootstrapper*
*Plan: 10 — exception sanitization hardening (gap closure)*
*Completed: 2026-05-20*
