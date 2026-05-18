---
phase: 18-tenancy-install
plan: "04"
subsystem: testing
tags: [php-parser, ast, bundles-php, phpunit, phpstan, dx-06, write-path, atomic-write, bak-sidecar, lint-check, restore]

# Dependency graph
requires:
  - phase: 18-tenancy-install
    plan: "03"
    provides: "BundlesPhpInstaller AST detector with LogicException stub in write branch; InstallResult/InstallStatus/DetectionResult DTOs; 7-fixture corpus + .expected/ baselines"
provides:
  - "BundlesPhpInstaller::install() full write path: string-template insertion at AST byte offset, atomic Filesystem::dumpFile, timestamped .bak via copy(), php -l post-write, restore-via-copy on lint failure"
  - "InstallResult::dryRun(string \$diff) static constructor for dry-run path"
  - "\$lintRunner Closure injection for testability (forced-failure in tests)"
  - "BundlesPhpInstallerTest: 18 tests replacing LogicException expectations with WROTE+byte-equality assertions, plus testDryRunDoesNotWrite"
  - "BundlesPhpInstallerSafetyTest: 2 tests proving T-INSTALL-02 mitigation (.bak survives restore path)"
  - "tests/bootstrap.php fix: addPsr4 calls use prepend:true so worktree src/ overrides vendor classmap"
affects:
  - 18-05-PLAN (TenancyInstallCommand wires InstallResult to SymfonyStyle output; uses BundlesPhpInstaller with real filesystem)
  - 18-09-PLAN (integration test exercises the full write path end-to-end via CommandTester)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Symfony Filesystem::copy() with overwriteNewerFiles=true for restore path — default false silently skips copy when target is newer than source (which it always is after dumpFile)"
    - "BundlesPhpInstaller write algorithm: insert at endPos (the ] position directly, NOT at insertAt after whitespace walkback), entry without trailing newline, source's existing \\n before ] provides spacing, +\\n appended at EOF to normalize trailing newline"
    - "Closure injection for Process-dependent code (lintRunner) — enables unit-testable forced-failure scenarios without mocking Process itself"
    - "prepend:true in addPsr4 calls in tests/bootstrap.php so worktree src/ takes precedence over vendor classmap in parallel execution"

key-files:
  created:
    - "tests/Unit/Command/Install/BundlesPhpInstallerSafetyTest.php — 2 tests proving T-INSTALL-02 mitigation (forced lint failure; .bak outlives restore)"
  modified:
    - "src/Command/Install/BundlesPhpInstaller.php — replaced LogicException stub with full write path; added lintRunner Closure injection; buildMutatedSource() and buildDiff() helpers"
    - "src/Command/Install/InstallResult.php — added dryRun(string \$diff) static constructor"
    - "tests/Unit/Command/Install/BundlesPhpInstallerTest.php — replaced testInstallTerminalBranches (LogicException) with testInstall (WROTE + byte-equality); added testDryRunDoesNotWrite; updated fixturesProvider to 4-tuple with expectedBaseline"
    - "tests/bootstrap.php — fixed addPsr4 calls to use prepend:true for correct worktree execution"

key-decisions:
  - "Insert at endPos (the ] character directly), NOT at insertAt after whitespace walkback — the walkback-based insert produces \\n\\n before ]; which the .expected/ baselines do not have; inserting at ] preserves the existing \\n before ] in the source, producing entry\\n];"
  - "Filesystem::copy() for restore MUST pass overwriteNewerFiles=true — the default false silently skips the copy when dumpFile just wrote a newer bundlesPhpPath; the safety test proved this without the fix"
  - "Closure injection for lintRunner (not abstract method) — keeps BundlesPhpInstaller final; test injects forced-failure via constructor"
  - "Trailing \\n appended after entire output to normalize EOF to \\n\\n — matches all four .expected/ baselines"

patterns-established:
  - "Forced-failure Closure injection pattern: constructor accepts ?\\Closure for external process runners; tests inject forced outcomes without mocking at process level"
  - "Symfony Filesystem::copy() restore always passes overwriteNewerFiles=true — any copy-based restore in this codebase must account for the default-false timestamp check"

requirements-completed: [DX-06]

# Metrics
duration: "28min"
completed: "2026-05-18"
---

# Phase 18 Plan 04: BundlesPhpInstaller Write Path Summary

**Full write path shipped: string-template insertion at AST byte offset with atomic dumpFile, timestamped .bak sidecar (copy, not rename), php -l post-write lint, restore-via-copy on failure; T-INSTALL-01 and T-INSTALL-02 threats mitigated with byte-exact baseline assertions + forced-failure safety test**

## Performance

- **Duration:** 28 min
- **Started:** 2026-05-18T07:38:53Z
- **Completed:** 2026-05-18T08:07:00Z
- **Tasks:** 2
- **Files modified:** 5 (1 created, 4 modified)

## Accomplishments

- Replaced the LogicException stub from Plan 03 with the full write path; all 4 standard fixtures (skeleton, api-platform, sulu, with-comments) produce byte-identical output matching their `.expected/` baselines
- Added `InstallResult::dryRun()` static constructor + `$lintRunner` Closure injection for testability
- Safety test (`BundlesPhpInstallerSafetyTest`) proves T-INSTALL-02 mitigation: `.bak` exists on disk AFTER lint-failure restore (rename-based restore would fail this test)
- Fixed `tests/bootstrap.php` to use `prepend:true` in `addPsr4` calls so worktree source files take precedence over the vendor classmap when running tests in parallel executor worktrees
- Discovered and fixed critical bug: `Filesystem::copy()` for restore path requires `overwriteNewerFiles=true` (default `false` silently skips copy when target file is newer than source, which it always is after `dumpFile`)

## Task Commits

1. **Task 18-04-01: Write path + mutate-success tests** - `90c2647` (feat)
2. **Task 18-04-02: Safety dimension — forced-lint-failure proves .bak survives** - `4121c2c` (feat)

## Files Created/Modified

- `src/Command/Install/BundlesPhpInstaller.php` — full write path: `buildMutatedSource()`, `buildDiff()`, `defaultLintRunner()`, `$lintRunner` Closure injection; `Filesystem::copy(bak, bundles, overwrite:true)` for restore
- `src/Command/Install/InstallResult.php` — `dryRun(string $diff): self` static constructor added
- `tests/Unit/Command/Install/BundlesPhpInstallerTest.php` — `fixturesProvider()` updated to 4-tuple with `$expectedBaseline`; `testInstallTerminalBranches` replaced with `testInstall` (WROTE + assertStringEqualsFile); `testDryRunDoesNotWrite` added
- `tests/Unit/Command/Install/BundlesPhpInstallerSafetyTest.php` — NEW: 2 tests proving T-INSTALL-02 mitigation; `testLintFailureRestoresFromBackupAndKeepsBak` with critical `.bak exists after restore` assertion
- `tests/bootstrap.php` — `addPsr4` calls use `prepend: true` so worktree src/ overrides vendor classmap

## Decisions Made

- **Insert at endPos (not insertAt after walkback):** The RESEARCH.md §3b algorithm walks back whitespace then inserts, but this produces `entry\n\n];\n` (double-newline before `]`). The `.expected/` baselines (created in Plan 02) have `entry\n];\n\n` (single newline before `]`, trailing blank line at EOF). Inserting at `endPos` directly preserves the existing `\n` before `]` in the source, giving `entry\n];\n`, then `+\n` at EOF normalizes to `];\n\n`. This matches all 4 baselines exactly.

- **`overwriteNewerFiles=true` for restore:** Symfony's `Filesystem::copy()` default behavior is to skip copy if target is newer than source. After `dumpFile()` writes the mutated file, the target is always newer than the `.bak`. The safety test caught this: without `true`, the "restore" silently did nothing and the mutated content remained in `bundles.php`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] RESEARCH.md §3b insertion algorithm produces different output than .expected/ baselines**
- **Found during:** Task 18-04-01 (verification — algorithm produces `entry\n\n];\n`, baselines have `entry\n];\n\n`)
- **Issue:** The verbatim RESEARCH.md §3b algorithm walks back whitespace from `endPos`, then inserts at `insertAt`. This produces a blank line BEFORE the closing `]`. All four `.expected/` baselines have the blank line AFTER `];` (trailing EOF newline).
- **Fix:** Changed insertion point from `insertAt` (after walkback) to `endPos` (the `]` character directly). The existing `\n` before `]` in the source is preserved naturally. A final `+$lineEnding` normalizes EOF. Verified: all 4 baselines match byte-for-byte.
- **Files modified:** `src/Command/Install/BundlesPhpInstaller.php` (`buildMutatedSource()`)
- **Verification:** `assertStringEqualsFile` passes for all 4 standard fixtures
- **Committed in:** `90c2647`

**2. [Rule 1 - Bug] `Filesystem::copy()` restore silently skips copy — `.bak` is older than the freshly-written `bundles.php`**
- **Found during:** Task 18-04-02 (safety test failure: `bundles.php must be byte-equal to the original after lint-failure restore`)
- **Issue:** `Symfony\Component\Filesystem\Filesystem::copy()` has a third parameter `$overwriteNewerFiles = false`. After `dumpFile()` writes the mutated file, `bundles.php` is newer than `.bak`. The default-false check (`filemtime($bak) > filemtime($bundles.php)`) evaluates to `false`, so the copy is skipped entirely — the restore is a no-op.
- **Fix:** Changed both restore calls to `$this->filesystem->copy($bakPath, $bundlesPhpPath, true)` (passing `true` for `$overwriteNewerFiles`). The safety test proved this: `testLintFailureRestoresFromBackupAndKeepsBak` failed without the fix and passed with it.
- **Files modified:** `src/Command/Install/BundlesPhpInstaller.php` (two `copy()` calls in lint-failure branches)
- **Verification:** `vendor/bin/phpunit tests/Unit/Command/Install/BundlesPhpInstallerSafetyTest.php` exits 0, 2 tests, 12 assertions
- **Committed in:** `4121c2c`

**3. [Rule 3 - Blocking] `tests/bootstrap.php` `addPsr4()` must use `prepend:true` in worktree context**
- **Found during:** Task 18-04-01 (tests run from worktree loaded OLD `BundlesPhpInstaller.php` from main project via vendor classmap)
- **Issue:** The worktree's `tests/bootstrap.php` calls `$loader->addPsr4('Tenancy\\Bundle\\', dirname(__DIR__).'/src')` without `prepend:true`. The vendor `autoload_psr4.php` already registered `Tenancy\\Bundle\\` pointing to the MAIN project's `/src/`. Without prepend, the worktree's `/src/` is appended AFTER the main project's — PHP loads the OLD file first.
- **Fix:** Changed both `addPsr4()` calls to `prepend: true`. The worktree's source now takes precedence over the vendor classmap.
- **Files modified:** `tests/bootstrap.php`
- **Verification:** All 18 tests in `BundlesPhpInstallerTest.php` pass from the worktree (previously 5 errors due to `LogicException` from old source)
- **Committed in:** `90c2647`

---

**Total deviations:** 3 auto-fixed (2 Rule 1 bugs + 1 Rule 3 blocking)
**Impact on plan:** All three fixes are correctness requirements. Deviation 1 ensures byte-exact output matching the established baselines. Deviation 2 is the critical security/data-integrity fix (restore must actually restore). Deviation 3 is a worktree infrastructure fix enabling the full test run. No scope creep.

## Issues Encountered

- The full suite (`vendor/bin/phpunit` without `--testsuite unit`) fails in the worktree context when integration tests boot the Symfony kernel: the kernel double-loads PSR-4 classes from both the worktree `/src/` (prepended by bootstrap) and the main project `/src/` (registered by the kernel's own autoloader), causing `Cannot redeclare class` fatal errors. This is a pre-existing limitation of the parallel worktree execution pattern when integration tests boot a kernel with the same PSR-4 namespace. Unit tests (`--testsuite unit`) pass cleanly (278 tests). The main project's full suite (360 tests) passes without modification.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- Plan 05 (TenancyInstallCommand): `BundlesPhpInstaller` is fully functional; `InstallResult` has all 5 outcomes + `dryRun()`. The command can route all outcomes to `SymfonyStyle` output. The `$lintRunner` injection makes the command unit-testable without a real PHP process.
- Plan 09 (integration test): The write path is complete. Integration test can copy a fixture to a tmp dir, run `tenancy:install`, assert `.bak` exists + `bundles.php` contains TenancyBundle entry.
- `tests/bootstrap.php` worktree fix is committed and will be in the merge — future plan agents will benefit from it automatically.

## Known Stubs

None — the LogicException stub from Plan 03 has been fully replaced. No partial implementations remain in the files touched by this plan.

## Threat Flags

All new security-relevant surface (filesystem writes, Process execution for `php -l`) is within the plan's declared threat model:
- T-INSTALL-01 (malformed write): mitigated by `php -l` + restore
- T-INSTALL-02 (.bak lost during restore): mitigated by `Filesystem::copy(overwrite:true)` + safety test regression gate

No new threat surface outside the plan's threat model.

## Self-Check: PASSED

- `src/Command/Install/BundlesPhpInstaller.php` — FOUND
- `src/Command/Install/InstallResult.php` — FOUND
- `tests/Unit/Command/Install/BundlesPhpInstallerTest.php` — FOUND
- `tests/Unit/Command/Install/BundlesPhpInstallerSafetyTest.php` — FOUND
- `tests/bootstrap.php` — FOUND
- `18-04-SUMMARY.md` — FOUND
- Task 1 commit `90c2647` — FOUND
- Task 2 commit `4121c2c` — FOUND
- PHPUnit: 20 tests, 82 assertions (unit: Install/ only) — PASSED
- PHPStan level 9 (src/Command/Install/ + tests/Unit/Command/Install/) — PASSED (0 errors)
- php-cs-fixer @Symfony (src/Command/Install/ + safety test) — PASSED (empty diff)

---
*Phase: 18-tenancy-install*
*Completed: 2026-05-18*
