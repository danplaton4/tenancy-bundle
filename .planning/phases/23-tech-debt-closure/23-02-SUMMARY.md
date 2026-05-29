---
phase: 23-tech-debt-closure
plan: 02
subsystem: messenger+command
tags: [tech-debt, messenger, retry-semantics, logic-exception, wr-01, cr-01]
requirements:
  - WR-01
dependency_graph:
  requires:
    - phase: 18
      plan: 10
      provides: "MissingTenantProviderException class + throw sites at TenantRunCommand::execute() and TenantWorkerMiddleware::handle()"
    - commit: 31465dc
      provides: "CR-01 closure via consistent nullable-no-default signature across all 6 sites (resolved 2026-05-21, BEFORE this plan was authored)"
  provides:
    - "Runtime regression tests pinning MissingTenantProviderException as \\LogicException at BOTH throw sites — proving Symfony Messenger no-retry semantic at the test level"
    - "Stamp-less envelope regression test for TenantWorkerMiddleware (null provider + no TenantStamp must pass through, not throw)"
  affects: []
tech_stack:
  added: []
  patterns:
    - "Runtime exception-ancestry assertion (assertInstanceOf \\LogicException + assertNotInstanceOf \\RuntimeException) to pin retry-semantic at the test level"
    - "Caught-exception capture via try/catch + Throwable + assertNotNull to satisfy PHPStan flow analysis on contract assertions where multiple inheritance facts are checked"
key_files:
  created: []
  modified:
    - tests/Unit/Messenger/TenantWorkerMiddlewareTest.php  # +2 tests (LogicException assertion + stamp-less null-provider regression)
    - tests/Unit/Command/TenantRunCommandTest.php          # +1 test (LogicException assertion)
    - .planning/phases/23-tech-debt-closure/23-02-SUMMARY.md
decisions:
  - "Option D scope reduction: skipped Task 1 (CR-01 source edits) and Task 2 (CR-01 contract-test default-value strengthening) because commit 31465dc (2026-05-21, BEFORE this plan was authored) already closed CR-01 in the OPPOSITE direction — drop `= null` defaults from the 3 read-path resolvers (Host/Header/QueryParam) so all 6 sites match the no-default style used by ConsoleResolver / TenantRunCommand / TenantWorkerMiddleware (which cannot accept defaults because the provider param sits BEFORE required positional params; PHP 8.0+ deprecates optional-before-required). The audit's CR-01 finding and 23-CONTEXT.md D-02 were OBSOLETE on the day this plan was generated."
  - "Executed Task 3 only (WR-01 LogicException tests at both throw sites) — the unfinished audit item."
  - "Did NOT touch source under src/ — CR-01 is closed; mutating it would regress the deprecation fix from 31465dc."
  - "Did NOT modify NullableProviderInjectionContractTest.php — it already locks the right invariant (?TenantProviderInterface on all 7 registered sites). The default-value assertion proposed in Task 2 would have rolled back the 31465dc decision."
metrics:
  duration: ~12 min
  completed_date: 2026-05-29
  tasks_executed: 1
  tasks_skipped_by_design: 2
  tests_added: 3
  total_tests_before: 562
  total_tests_after: 565
---

# Phase 23 Plan 02: Nullable-provider drift guard + Messenger retry semantics — SUMMARY

## One-line summary

Pinned WR-01 invariant (`MissingTenantProviderException extends \LogicException`) at both throw sites via runtime tests; SKIPPED Task 1 + Task 2 (CR-01) because commit 31465dc (2026-05-21) had already closed CR-01 in the opposite direction from what the audit / 23-CONTEXT.md prescribed.

## Option D — Scope reduction rationale

When this executor was spawned to run 23-02 as-planned, the first read of source revealed that all 6 `tenancy.provider->nullOnInvalid()` consumer constructors used `?TenantProviderInterface $tenantProvider,` **without** the `= null` default — i.e. the opposite of what 23-PLAN.md prescribed. Inspection of `git log` showed commit **31465dc (2026-05-21)** had explicitly closed CR-01 by:

1. **Dropping** the `= null` defaults from `HostResolver` / `HeaderResolver` / `QueryParamResolver` (so all 6 sites match the no-default style).
2. Adding `tests/Unit/Container/NullableProviderInjectionContractTest.php` which asserts the `?TenantProviderInterface` type on all 7 registered sites (6 nullable-provider sites + `TenantAwareTransportsDecorator`).

The reason 31465dc chose to **drop** rather than **add** defaults:

- `ConsoleResolver` (`$tenantProvider` at position 0 of 4), `TenantRunCommand` (`$tenantProvider` at position 0 of 3), and `TenantWorkerMiddleware` (`$tenantProvider` at position 2 of 4) all have `$tenantProvider` **BEFORE** required positional parameters.
- PHP 8.0+ **deprecates** optional-before-required parameters. Setting `= null` on `$tenantProvider` in these three sites would trigger a runtime deprecation warning on every container resolution.
- The default-null was therefore impossible at three of the six sites; consistency had to be enforced in the **other** direction (drop defaults everywhere).

The audit's CR-01 finding (`.planning/v0.3-MILESTONE-AUDIT.md`) and 23-CONTEXT.md D-02 (which both said "add `= null` to ConsoleResolver / TenantRunCommand / TenantWorkerMiddleware") were authored 2026-05-29 — **8 days after** 31465dc landed but **without checking the resolved state**. They are stale audit findings.

Executing Task 1 + Task 2 as written would have:
- Reintroduced the PHP 8.0+ deprecation by adding `= null` to params before required params.
- Required reordering constructor parameters (a BC break for any extender — and `TenantWorkerMiddleware` is intentionally not `final`-friendly because Messenger middleware composition).
- Made `NullableProviderInjectionContractTest` fail because the 31465dc strict no-default policy is the current invariant.

The orchestrator accepted Option D after this analysis was presented: **skip Task 1 + Task 2; execute Task 3 only**.

## Task 3 — WR-01 LogicException regression coverage

`MissingTenantProviderException extends \LogicException` already existed in `src/Exception/` (introduced by Phase 18 — see 9a28a2e / ee99f2d). Both throw sites already used it (`TenantRunCommand::execute()` L42-43, `TenantWorkerMiddleware::handle()` L35-36). What was missing: **runtime tests proving the LogicException ancestry**.

The audit (WR-01) called out the gap because the class-level comment was the only place documenting the no-retry semantic — nothing failed loudly if a future contributor swapped the parent to `\RuntimeException`. Symfony Messenger's default retry strategy treats `\RuntimeException` as transient and retries; this is wrong for a permanent misconfiguration.

### Tests added

#### `tests/Unit/Messenger/TenantWorkerMiddlewareTest.php` (+2 tests, 6 → 8)

1. **`testMissingTenantProviderExceptionExtendsLogicException`** — Constructs `TenantWorkerMiddleware` with `$tenantProvider = null`, sends a stamp-bearing envelope (`new TenantStamp('acme')`), captures the thrown exception via `try/catch (\Throwable)`, then asserts:
   - `instanceof MissingTenantProviderException`
   - `instanceof \LogicException`  ← WR-01 invariant
   - `NOT instanceof \RuntimeException` (runtime documentation; PHPStan proves this statically — suppressed via `@phpstan-ignore method.alreadyNarrowedType`)
   - Message contains `'acme'` (stamp slug, for forensic debugging)
   - Message contains `'tenancy:install'` (operator hint baked into the exception constructor)

2. **`testNoThrowWhenProviderIsNullAndStampAbsent`** — Regression for the L29-33 stamp-less early-return path: a null provider on a stamp-less envelope must pass through to the next stack without throwing. The middleware checks the stamp BEFORE the null-provider guard — a null provider in a stamp-less context is legitimate zero-config boot, not an error.

Commit: **b0a0e3c**

#### `tests/Unit/Command/TenantRunCommandTest.php` (+1 test, 5 → 6)

1. **`testMissingTenantProviderExceptionExtendsLogicException`** — Constructs `new TenantRunCommand(null, '/app', null)`, invokes via `CommandTester` with `['tenant' => 'acme', 'command_string' => 'cache:clear']`, captures the thrown exception via `try/catch (\Throwable)` (CommandTester propagates by default — confirmed by the existing `testNonexistentTenantThrows`), then asserts:
   - `instanceof MissingTenantProviderException`
   - `instanceof \LogicException`  ← WR-01 invariant
   - `NOT instanceof \RuntimeException` (same suppression as above)
   - Message contains `'tenancy:run'` (caller context — proves the exception is reporting the right entry point)
   - Message contains `'tenancy:install'` (operator hint)

Commit: **babe1ab**

### Why try/catch + Throwable instead of expectException?

`expectException(\LogicException::class)` only takes one class assertion. To pin BOTH `instanceof MissingTenantProviderException` AND `instanceof \LogicException` AND `NOT instanceof \RuntimeException` AND two message substrings, a manual catch-and-assert pattern is required. The PHPStan "method.alreadyNarrowedType" warning on the `NOT instanceof \RuntimeException` line is suppressed inline — that assertion is kept deliberately as **runtime documentation** of the WR-01 invariant: if a future contributor ever changes the parent class to `\RuntimeException`, the assertion fails loudly.

## Deviations from plan

### Skipped by design (Option D)

- **Task 1 (CR-01 source edits to 6 files):** SKIPPED. CR-01 was closed by 31465dc on 2026-05-21 in the opposite direction (drop defaults). Re-adding `= null` to the 3 backend sites would trigger PHP 8.0+ optional-before-required deprecation warnings on every container resolution.
- **Task 2 (Contract-test default-value strengthening):** SKIPPED. The proposed `isDefaultValueAvailable()` assertion would have failed against the current 31465dc-locked invariant. `NullableProviderInjectionContractTest` already pins the correct invariant: the `?TenantProviderInterface` type on all 7 registered sites.

### Auto-fixed Issues (Rule 1 — bug fix during execution)

**[Rule 1 — bug] PHPStan flow-analysis false positive on `assertNotInstanceOf RuntimeException`**

- **Found during:** Task 3a (middleware test).
- **Issue:** PHPStan level 9 flagged the `NOT instanceof RuntimeException` assertion as `method.alreadyNarrowedType` because PHPStan can statically prove from `MissingTenantProviderException extends \LogicException` that the negative is always true.
- **Fix:** Suppressed with `@phpstan-ignore method.alreadyNarrowedType` annotation on the assertion line in both test files. The runtime assertion is kept deliberately as documentation of the WR-01 invariant — if anyone changes the parent class, PHPStan would no longer prove the narrow and the suppression would become a dead annotation (which PHPStan flags).
- **Files modified:** Both test files.
- **Rationale documented inline:** Each ignore is preceded by a comment block explaining why the redundant-at-static-time assertion is kept at runtime.

### Authentication gates

None.

## Recommendations for v0.3-MILESTONE-AUDIT hygiene

The audit's CR-01 finding was already obsolete on the day Phase 23 was discussed (2026-05-29) — commit 31465dc had closed it 8 days earlier in the opposite direction. The audit and 23-CONTEXT.md D-02 both prescribed re-opening the closed invariant.

**Recommendation:** Before authoring a closure phase's CONTEXT.md, the discuss-phase workflow should:
1. Run `git log --oneline --since=<audit-date>` over the files named in the audit's `canonical_refs:`.
2. For each audit finding referencing a file with post-audit commits, read the diff and re-validate the finding against current source.

This would have caught CR-01's stale state automatically and reduced Phase 23's scope to its actual remaining items (WR-01 tests, INT-01 Twig drift, IN-01..IN-05 cosmetics, smoke.sh mailer assertion, CHANGELOG promotion) before plan generation.

## Verification

### Automated checks (all green)

- `vendor/bin/phpunit --no-coverage` → **565 tests, 2113 assertions, 0 failures** (was 563 → 565, +2 new tests across the two files).
- `vendor/bin/phpstan analyse --memory-limit=512M` → **[OK] No errors** (68 files analysed).
- `vendor/bin/php-cs-fixer check --diff` → **0 issues**.
- Pre-commit hook passed on both `b0a0e3c` and `babe1ab` (runs the full PHPUnit + cs-fixer + PHPStan gate before allowing commit).

### Specific WR-01 grep proof

```bash
$ grep -E "expectException\(\\\\LogicException::class\)|assertInstanceOf\(\\s*\\\\LogicException::class" \
    tests/Unit/Messenger/TenantWorkerMiddlewareTest.php \
    tests/Unit/Command/TenantRunCommandTest.php | wc -l
# → 2 (one assertion per file, exactly as required)
```

## Commits

| Hash    | Type | Message                                                                                           |
| ------- | ---- | ------------------------------------------------------------------------------------------------- |
| b0a0e3c | test | assert MissingTenantProviderException is LogicException at TenantWorkerMiddleware throw site     |
| babe1ab | test | assert MissingTenantProviderException is LogicException at TenantRunCommand throw site           |

(SUMMARY commit will follow as `docs(23-02): create SUMMARY.md (Option D — CR-01 already closed)`.)

## Self-Check: PASSED

- **File existence:**
  - `tests/Unit/Messenger/TenantWorkerMiddlewareTest.php` → FOUND (modified, +2 tests)
  - `tests/Unit/Command/TenantRunCommandTest.php` → FOUND (modified, +1 test)
  - `.planning/phases/23-tech-debt-closure/23-02-SUMMARY.md` → FOUND (this file)

- **Commits exist:**
  - `b0a0e3c` → FOUND in `git log` (middleware test commit)
  - `babe1ab` → FOUND in `git log` (command test commit)

- **Test count delta:** 563 → 565 (+2) confirmed by full-suite PHPUnit run.

- **Source under `src/` was NOT modified:** confirmed by `git diff --name-only b0a0e3c~1 HEAD -- src/` returning empty.

- **CR-01 closure NOT regressed:** confirmed by `grep -nE "\\?TenantProviderInterface \\\$tenantProvider," src/Resolver/*.php src/Command/*.php src/Messenger/*.php` returning 6 matches with no `= null` defaults (the 31465dc invariant is intact).
