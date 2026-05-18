---
phase: 18-tenancy-install
plan: "03"
subsystem: testing
tags: [php-parser, ast, bundles-php, phpunit, phpstan, dx-06, enum, value-object]

# Dependency graph
requires:
  - phase: 18-tenancy-install
    plan: "02"
    provides: "7-fixture BundlesPhpCorpus corpus + 4 .expected/ baselines that the test data provider exercises"
provides:
  - "InstallStatus backed-string enum with 5 cases (WROTE, ALREADY_REGISTERED, REFUSED_NON_STANDARD, LINT_FAILED_RESTORED, DEV_DEPENDENCY_MISSING)"
  - "InstallResult final readonly DTO with 5 static named constructors + isSuccessOutcome()"
  - "DetectionResult internal final readonly DTO with standard/nonStandard/missing factories"
  - "BundlesPhpInstaller AST detector: detect(), install() (detection branches), extractFqcns()"
  - "BundlesPhpInstallerTest with PHPUnit 11 #[DataProvider] covering all 7 fixtures (17 tests, 45 assertions)"
affects:
  - 18-04-PLAN (write logic fills in the LogicException stub in install(); uses DetectionResult.endPos for string-template insertion)
  - 18-05-PLAN (TenancyInstallCommand consumes BundlesPhpInstaller and uses InstallResult for SymfonyStyle output routing)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "PHP 8.2+ backed-string enum + final readonly DTO with static named constructors: the InstallStatus + InstallResult pair"
    - "PHPUnit 11 #[DataProvider] attribute with yield-based provider (NEW pattern in this codebase — first dataProvider test)"
    - "PhpParser v5 top-level Node\\ArrayItem (NOT deprecated Node\\Expr\\ArrayItem alias)"
    - "class_exists(ParserFactory::class) guard at method entry — mirrors Doctrine optional-dep pattern"
    - "instanceof SplFileInfo narrowing instead of @var docblock for PHPStan L9 compliance in test iterator"

key-files:
  created:
    - "src/Command/Install/InstallStatus.php — backed-string enum with 5 outcome cases"
    - "src/Command/Install/InstallResult.php — final readonly DTO pairing InstallStatus with nullable ancillary fields"
    - "src/Command/Install/DetectionResult.php — internal final readonly DTO for detect() return value"
    - "src/Command/Install/BundlesPhpInstaller.php — AST detector with detect(), install(), extractFqcns()"
    - "tests/Unit/Command/Install/BundlesPhpInstallerTest.php — 17 tests, 45 assertions, all 7 fixtures via #[DataProvider]"
  modified: []

key-decisions:
  - "LogicException stub in BundlesPhpInstaller::install() write branch is the explicit contract between Plan 03 (detection) and Plan 04 (write) — message 'not yet implemented (scheduled for plan 18-04)' is the test assertion anchor"
  - "extractFqcns() returns list<string>|null not array — null means non-conforming item found, distinguishing 'empty standard array' from 'refused non-standard'; PHPStan L9 enforces list<string>"
  - "DetectionResult::missing() used only for file_get_contents failure; install() checks filesystem->exists() first and returns refusedNonStandard instead, so missing() is reserved for detect() direct callers"
  - "instanceof SplFileInfo narrowing in cleanUp() iterator loop — avoids @var annotation conflict with php-cs-fixer phpdoc_to_comment rule (same issue as Plan 01's L9 fix)"

patterns-established:
  - "PHPUnit 11 #[DataProvider] with static fixturesProvider(): iterable yielding named rows — establishes this codebase's first dataProvider test; Plans 04+ should mirror this shape for write tests"
  - "PHP 8.2 backed-string enum + final readonly class pair for typed outcomes — InstallStatus + InstallResult is the canonical pattern for multi-outcome operations"

requirements-completed: [DX-06]

# Metrics
duration: "6min"
completed: "2026-05-18"
---

# Phase 18 Plan 03: BundlesPhpInstaller AST Detector Summary

**AST detector (detect/classify/FQCN-extract) for bundles.php landed with typed InstallStatus enum + InstallResult/DetectionResult DTOs and a 7-fixture #[DataProvider] test (17 tests, 45 assertions); write branch intentionally stubbed as LogicException for Plan 04**

## Performance

- **Duration:** 6 min
- **Started:** 2026-05-18T07:12:56Z
- **Completed:** 2026-05-18T07:18:56Z
- **Tasks:** 2
- **Files modified:** 5

## Accomplishments

- Shipped `InstallStatus` backed-string enum (5 cases), `InstallResult` final readonly DTO (5 static named constructors), `DetectionResult` internal DTO — the complete typed-result contract for Plans 04 and 05
- Shipped `BundlesPhpInstaller` with the full detection branch: `detect()` applies the 5 exhaustive shape rules from CONTEXT.md D-02, `install()` routes all detection outcomes (devDependencyMissing, refusedNonStandard, alreadyRegistered) with the write branch stubbed
- Uses `PhpParser\Node\ArrayItem` (top-level `Node\`, NOT the deprecated `Node\Expr\ArrayItem` alias) — the critical landmine from RESEARCH.md §1 correctly avoided
- `class_exists(ParserFactory::class)` guard present in both `install()` and `detect()` — satisfies the lazy-load contract from D-01
- `BundlesPhpInstallerTest` introduces `#[DataProvider]` as the first dataProvider test in this codebase; 7 fixture rows × 2 methods = 14 parameterized tests + 3 standalone = 17 tests, 45 assertions
- Full suite: 360 tests, 896 assertions — no regressions

## Task Commits

1. **Task 18-03-01: InstallStatus enum + InstallResult DTO + DetectionResult DTO** - `92d596a` (feat)
2. **Task 18-03-02: BundlesPhpInstaller AST detection + #[DataProvider] unit test** - `e5fe16f` (feat)

## Files Created/Modified

- `src/Command/Install/InstallStatus.php` — backed-string enum: WROTE, ALREADY_REGISTERED, REFUSED_NON_STANDARD, LINT_FAILED_RESTORED, DEV_DEPENDENCY_MISSING
- `src/Command/Install/InstallResult.php` — final readonly class with status + nullable backupPath/diff/errorMessage + 5 static named constructors + isSuccessOutcome()
- `src/Command/Install/DetectionResult.php` — internal final readonly class with status ('standard'|'non_standard'|'missing') + registeredFqcns list + endPos + reason
- `src/Command/Install/BundlesPhpInstaller.php` — final class with install() (detection branches fully wired; write branch throws LogicException), detect() (5-rule shape classifier), extractFqcns() (FQCN list extractor)
- `tests/Unit/Command/Install/BundlesPhpInstallerTest.php` — PHPUnit 11 #[DataProvider] test: fixturesProvider() yields 7 rows; testDetect + testInstallTerminalBranches consume the provider; testAlreadyRegistered, testMissingFile, testExtractFqcns are standalone

## Decisions Made

- `LogicException` stub with exact message 'not yet implemented (scheduled for plan 18-04)' is the inter-plan contract — `testInstallTerminalBranches` asserts on this message substring, so Plan 04's replacement will automatically break this test (forcing Plan 04 to update the test as well as the implementation).
- `extractFqcns()` is public (not private) to enable whitebox testing in `testExtractFqcnsReturnsListShape()` — PHPStan L9 and the `@internal` doc on `DetectionResult` give enough signal that this is an implementation detail.
- `DetectionResult` uses `'missing'` status string for file-not-readable, but `install()` uses `refusedNonStandard('not found ...')` for the missing-file case (not `devDependencyMissing`) — the intent is that `missing` is for the `detect()` public API consumers; `install()` has its own error routing with a clear message.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] php-cs-fixer `phpdoc_to_comment` converts `/** @var */` to `/* @var */` which PHPStan L9 ignores**
- **Found during:** Task 18-03-02 (BundlesPhpInstallerTest cleanUp() iterator)
- **Issue:** Same conflict as Plan 01 (documented in 18-01-SUMMARY.md) — using `/** @var \SplFileInfo $file */` to narrow the iterator type causes cs-fixer to strip it to `/* @var */`, which PHPStan no longer reads; results in `Cannot call method isDir() on mixed` errors.
- **Fix:** Used `if ($file instanceof \SplFileInfo)` narrowing instead of any `@var` annotation — eliminates the conflict entirely without suppression.
- **Files modified:** `tests/Unit/Command/Install/BundlesPhpInstallerTest.php`
- **Verification:** `vendor/bin/phpstan analyse ... exits 0`; `vendor/bin/php-cs-fixer check --diff ... empty diff`; all 17 tests pass.
- **Committed in:** `e5fe16f`

**2. [Rule 1 - Bug] php-cs-fixer line-length rule collapses multi-line LogicException throw**
- **Found during:** Task 18-03-02 (BundlesPhpInstaller.php write-branch stub)
- **Issue:** The plan's EXACT content had a 3-line `throw new \LogicException(...)` with string concatenation. php-cs-fixer's `single_line_throw` rule collapses this to a single line.
- **Fix:** Inlined the message into a single-line throw as cs-fixer requires.
- **Files modified:** `src/Command/Install/BundlesPhpInstaller.php`
- **Verification:** `vendor/bin/php-cs-fixer check --diff src/Command/Install/ 2>&1` shows empty diff; contract message substring is still present; test `testInstallTerminalBranches` still asserts on the message and passes.
- **Committed in:** `e5fe16f`

---

**Total deviations:** 2 auto-fixed (Rule 1 — both are PHPStan L9 + php-cs-fixer @Symfony compatibility bugs in the plan's canonical content, same class of issue as Plan 01 deviation)
**Impact on plan:** Both fixes minimal — only whitespace/formatting and annotation approach changed. All behavioral assertions identical to the plan specification.

## Known Stubs

| Stub | File | Line | Reason |
|------|------|------|--------|
| `throw new \LogicException('...not yet implemented (scheduled for plan 18-04)...')` | `src/Command/Install/BundlesPhpInstaller.php` | 69 | Intentional inter-plan contract. The write branch (string-template insertion, atomic dumpFile, .bak, php -l, restore) is Plan 04's deliverable. This LogicException is the test anchor that forces Plan 04 to replace both the implementation AND the test assertion. |

The stub does NOT prevent the plan's goal from being achieved — the plan's scope is detection only; the stub correctly gates the write path.

## Threat Flags

T-INSTALL-04 (Tampering — non-standard bundles.php silently rewritten) — **mitigated** by `BundlesPhpInstaller::detect()` exhaustive 5-rule shape classifier; all 3 refusal triggers (ddd-override, env-conditional, malformed) confirmed passing.

T-INSTALL-05 (Spoofing — caller asserts file is standard when non-conforming) — **mitigated** by `extractFqcns()` returning null on any non-conforming item; `install()` short-circuits to refusedNonStandard.

No new security surface introduced beyond what the plan's threat model covers.

## Issues Encountered

None beyond the two auto-fixed Rule 1 deviations above. PHPStan level 9 and php-cs-fixer @Symfony are a known compatibility challenge in this codebase (first documented in Plan 01).

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- Plan 04 (write half): `BundlesPhpInstaller` is ready for the write branch. `DetectionResult.endPos` carries the byte offset of the closing `]`. The LogicException stub in `install()` is the exact line Plan 04 must replace. The test `testInstallTerminalBranches` rows with `null` expectedStatus must be updated to assert `WROTE` once the write path lands.
- Plan 05 (TenancyInstallCommand): `InstallResult` and `InstallStatus` contracts are complete; the command can route all 5 outcomes to `SymfonyStyle` output. `BundlesPhpInstaller` is injectable via constructor.

## TDD Gate Compliance

This plan was `tdd="true"` per task declarations. Both tasks were written following the execute-immediately pattern (no separate RED/GREEN commits, as the plan specified exact content for all files). The acceptance criteria were verified before committing each task. All 17 tests pass as of commit `e5fe16f`.

Note: The plan's `tdd="true"` flag on these tasks indicated the TDD mindset (test-first design), but the plan provided exact implementation content for both source and test files simultaneously, so the strict RED/GREEN commit separation was not applicable. A single `feat` commit per task captures both implementation and test.

## Self-Check: PASSED

- `src/Command/Install/InstallStatus.php` — FOUND
- `src/Command/Install/InstallResult.php` — FOUND
- `src/Command/Install/DetectionResult.php` — FOUND
- `src/Command/Install/BundlesPhpInstaller.php` — FOUND
- `tests/Unit/Command/Install/BundlesPhpInstallerTest.php` — FOUND
- Task 1 commit `92d596a` — FOUND
- Task 2 commit `e5fe16f` — FOUND
- PHPUnit: 360 tests, 896 assertions (full suite) — PASSED
- PHPStan level 9 — PASSED (0 errors)
- php-cs-fixer @Symfony — PASSED (empty diff)

---
*Phase: 18-tenancy-install*
*Completed: 2026-05-18*
