---
phase: 34-ops-docs-carry-forward
plan: "05"
subsystem: testing
tags: [phpunit, phpstan, extension-installer, command-tester, regression-test]

# Dependency graph
requires:
  - phase: 34-ops-docs-carry-forward
    provides: plan context, QA-01 requirement (close two v0.4 human_needed UAT items)

provides:
  - testLiveRunConfirmYesProceedsToApply() — passing regression for SHARE-02-c confirm-YES branch
  - ExtensionInstallerContractTest — passing metadata contract test for PHPStan zero-config auto-load

affects: []

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "CommandTester::setInputs(['yes']) with ['interactive' => true] for TTY confirm-gate coverage"
    - "Metadata contract test: json_decode + assertContains + assertFileExists + assertStringContainsString (no live tool invocation)"

key-files:
  created:
    - tests/Unit/PHPStan/ExtensionInstallerContractTest.php
  modified:
    - tests/Unit/Command/SharedEntityResyncCommandTest.php

key-decisions:
  - "QA-01 Phase 26 closed via setInputs(['yes']) string token (not [true] or [1]) per Pitfall 3 — SymfonyStyle::confirm() reads raw stdin string"
  - "QA-01 Phase 28 closed via Option A (metadata contract): plain string-contains on neon file, no nette/neon API dependency, no live PHPStan invocation"

patterns-established:
  - "confirm-gate test pattern: setInputs(['yes']) + ['interactive' => true] to prove TTY branch distinct from --force bypass and default-no abort"
  - "extension-installer contract pattern: json_decode composer.json, assertContains on extra.phpstan.includes, assertFileExists + assertStringContainsString on neon"

requirements-completed: [QA-01]

# Metrics
duration: 2min
completed: 2026-07-06
---

# Phase 34 Plan 05: QA-01 Regression Tests Summary

**Two v0.4 human_needed UAT gaps converted to passing regression tests: SHARE-02-c confirm-YES branch and PHPStan extension-installer zero-config auto-load contract**

## Performance

- **Duration:** 2 min
- **Started:** 2026-07-06T18:44:23Z
- **Completed:** 2026-07-06T18:46:27Z
- **Tasks:** 2
- **Files modified:** 2 (1 created, 1 modified)

## Accomplishments

- Added `testLiveRunConfirmYesProceedsToApply()` to `SharedEntityResyncCommandTest` — proves the interactive confirm-YES branch reaches `applyRow()`, distinct from the already-covered `--force` bypass and default-no abort paths
- Created `ExtensionInstallerContractTest` with three methods proving composer.json `extra.phpstan.includes` contains `extension.neon`, the file exists at the declared path, and it declares all three rule classes
- Full PHPUnit suite remains green: 970 tests, 3830 assertions, 2 skipped

## Task Commits

Each task was committed atomically:

1. **Task 1: Add confirm-YES regression for SharedEntityResyncCommand** - `15cca41` (test)
2. **Task 2: Add PHPStan extension-installer metadata contract test** - `7ac4338` (test)

**Plan metadata:** (docs commit below)

## Files Created/Modified

- `tests/Unit/Command/SharedEntityResyncCommandTest.php` — Added `testLiveRunConfirmYesProceedsToApply()` after `testLiveRunPromptsConfirmDefaultNoAbortsCleanly()`, before `testForceSkipsConfirmation()`; uses `setInputs(['yes'])` + `['interactive' => true]`, asserts `applyRow()` called at least once
- `tests/Unit/PHPStan/ExtensionInstallerContractTest.php` — New file; extends `TestCase` (not `RuleTestCase`), namespace `Tenancy\Bundle\Tests\Unit\PHPStan`, `#[Group('phpstan-extension')]`; three test methods covering composer metadata + file existence + rule declarations

## Decisions Made

- Used string token `['yes']` (not `[true]` or `[1]`) in `setInputs()` per the plan's Pitfall 3: `SymfonyStyle::confirm()` reads a raw string token from stdin, so boolean/int inputs would not match
- Chose Option A (metadata contract) for PHPStan test: plain `string-contains` on neon file content avoids any `nette/neon` API dependency and keeps the test framework-agnostic

## Deviations from Plan

None — plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- QA-01 Success Criterion 5 satisfied: both v0.4 `human_needed` UAT items are closed as passing regression tests
- Phase 34 is now complete (all 5 plans executed)
- Full suite: 970 tests / PHPStan L9 clean / cs-fixer clean — ready for phase verification

## Threat Model Coverage

T-34-09 (weak test / gap not proved closed): mitigated — Task 1 asserts `applyRow()` IS invoked, Task 2 asserts metadata + rule declarations.
T-34-10 (confirm bypass masking interactive path): mitigated — new test drives `['interactive' => true]` + `setInputs(['yes'])`, distinct from the `--force` path.

## Self-Check: PASSED

- `tests/Unit/Command/SharedEntityResyncCommandTest.php` exists and contains `testLiveRunConfirmYesProceedsToApply` with `setInputs(['yes'])` and `['interactive' => true]`
- `tests/Unit/PHPStan/ExtensionInstallerContractTest.php` exists, extends `TestCase`, namespace `Tenancy\Bundle\Tests\Unit\PHPStan`
- Commits `15cca41` and `7ac4338` verified in git log
- `vendor/bin/phpunit` (full suite): 970 tests, 3830 assertions — green

---
*Phase: 34-ops-docs-carry-forward*
*Completed: 2026-07-06*
