---
phase: 18-tenancy-install
plan: "05"
subsystem: testing
tags: [symfony-console, command, unit-test, dx-06, installer, dry-run, delegation]

# Dependency graph
requires:
  - phase: 18-tenancy-install
    plan: "04"
    provides: "BundlesPhpInstaller with full write path + all 5 InstallResult static constructors + dryRun()"
provides:
  - "TenancyInstallCommand — non-final console command orchestrating BundlesPhpInstaller and delegating to tenancy:init"
  - "BundlesPhpInstallerInterface — testability contract extracted from final BundlesPhpInstaller"
  - "9 unit tests covering all 5 InstallStatus branches + mutual-exclusion flag guard + D-09 swallow + D-10 dry-run skip"
affects:
  - 18-06-PLAN (DI registration in config/services.php — uses TenancyInstallCommand and BundlesPhpInstallerInterface)
  - 18-09-PLAN (integration test boots kernel and exercises TenancyInstallCommand end-to-end)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "BundlesPhpInstallerInterface extracted so final collaborator can be stubbed in unit tests — mirrors TenantConnectionInterface pattern (Phase 03)"
    - "BundlesPhpInstallerStub implements interface (not extends final class) — canonical PHP 8.2 test double pattern for final collaborators"
    - "TenancyInitSpyCommand records --force propagation per D-08 — spy pattern for programmatic delegation testing"
    - "Application::addCommand() not add() — Symfony Console API in this version"
    - "Exhaustive switch on backed enum: PHPStan level 9 removes unreachable defensive return after all cases covered"

key-files:
  created:
    - "src/Command/TenancyInstallCommand.php — non-final command with all 5 outcome branches, D-08/D-09/D-10/D-14/D-24 implementations"
    - "src/Command/Install/BundlesPhpInstallerInterface.php — single-method interface enabling test doubles for final BundlesPhpInstaller"
    - "tests/Unit/Command/TenancyInstallCommandTest.php — 9 tests; BundlesPhpInstallerStub + TenancyInitSpyCommand test doubles"
  modified:
    - "src/Command/Install/BundlesPhpInstaller.php — added 'implements BundlesPhpInstallerInterface'"

key-decisions:
  - "BundlesPhpInstallerInterface extracted from final BundlesPhpInstaller — PHPUnit ClassIsFinalException forces interface-based test doubles; TenancyInstallCommand types against the interface, not the concrete class"
  - "Defensive return removed after exhaustive switch — PHPStan level 9 deadCode.unreachable error on the post-switch fallback; backed enum switch with all 5 cases is exhaustive; no suppression needed, just remove the dead code"
  - "Application::addCommand() not add() — the Symfony Console Application in this version exposes addCommand() not add()"

patterns-established:
  - "Interface extraction for final collaborators: when a final class must be testable via test doubles, extract BundlesPhpInstallerInterface (install() method only), implement it on the concrete class, type-hint against the interface in consumers"
  - "Exhaustive backed-enum switch: no default/fallback needed; PHPStan level 9 enforces exhaustiveness and flags dead code after all cases return"

requirements-completed: [DX-06]

# Metrics
duration: "7min"
completed: "2026-05-18"
---

# Phase 18 Plan 05: TenancyInstallCommand Summary

**TenancyInstallCommand shipped with all 5 InstallStatus branches wired, D-08 programmatic delegation, D-09 yaml-exists swallow, D-10 dry-run skip, D-14 mutual-exclusion guard, and D-24 next-steps transcript; 9 unit tests cover every outcome via BundlesPhpInstallerInterface stub + TenancyInitSpyCommand**

## Performance

- **Duration:** 7 min
- **Started:** 2026-05-18T07:44:16Z
- **Completed:** 2026-05-18T07:51:24Z
- **Tasks:** 1
- **Files modified:** 4 (3 created, 1 modified)

## Accomplishments

- Shipped `TenancyInstallCommand` — the user-facing entry point for DX-06, non-final, extends Command, `#[AsCommand(name: 'tenancy:install')]`, wires all 5 `InstallStatus` outcomes to `SymfonyStyle` output
- Extracted `BundlesPhpInstallerInterface` so the final `BundlesPhpInstaller` collaborator is mockable in unit tests — mirrors the `TenantConnectionInterface` pattern from Phase 03
- 9 unit tests using `BundlesPhpInstallerStub` (implements interface) + `TenancyInitSpyCommand` (spy for --force propagation) — covers all branches, flags, and D-09/D-10 edge cases
- Full unit suite: 278 tests pass (no regressions); PHPStan level 9 clean; php-cs-fixer @Symfony clean

## Task Commits

1. **Task 18-05-01: TenancyInstallCommand + interface + 9 unit tests** - `3220a16` (feat)

## Files Created/Modified

- `src/Command/TenancyInstallCommand.php` — non-final command: `#[AsCommand('tenancy:install')]`, --force/--dry-run flags, 5-branch switch on InstallStatus, D-08 delegation, D-09 swallow, D-10 dry-run skip, D-14 mutual-exclusion, D-24 next-steps
- `src/Command/Install/BundlesPhpInstallerInterface.php` — single method `install(string, bool): InstallResult`; implemented by `BundlesPhpInstaller`, consumed by `TenancyInstallCommand`
- `src/Command/Install/BundlesPhpInstaller.php` — added `implements BundlesPhpInstallerInterface` (one-line change)
- `tests/Unit/Command/TenancyInstallCommandTest.php` — 9 tests + `BundlesPhpInstallerStub` + `TenancyInitSpyCommand` test doubles

## Decisions Made

- **Interface extraction for final collaborator:** `BundlesPhpInstaller` is `final` — `createMock()` throws `ClassIsFinalException`, extending throws a fatal. Extracted `BundlesPhpInstallerInterface` (1 method); `TenancyInstallCommand` types against the interface. This is the established codebase pattern (Phase 03: `TenantConnectionInterface`).
- **Defensive return removed:** After the exhaustive `switch ($result->status)` covering all 5 `InstallStatus` cases, PHPStan level 9 flagged the post-switch fallback as `deadCode.unreachable`. Removed it — no suppression, no workaround, just correct code.
- **Application::addCommand() not add():** Symfony Console in the version used here exposes `addCommand()` (not `add()`) for registering commands in an Application instance.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] BundlesPhpInstaller is final — createMock() and extend both fail**
- **Found during:** Task 18-05-01 (test execution)
- **Issue:** The plan specified `$this->createMock(BundlesPhpInstaller::class)` but PHPUnit 11 throws `ClassIsFinalException` for final classes. Extending also fails with a PHP fatal.
- **Fix:** Extracted `BundlesPhpInstallerInterface` with the `install()` signature; `BundlesPhpInstaller` implements it; `TenancyInstallCommand` types against the interface; test file uses `BundlesPhpInstallerStub implements BundlesPhpInstallerInterface` as the hand-rolled test double.
- **Files modified:** `src/Command/Install/BundlesPhpInstallerInterface.php` (new), `src/Command/TenancyInstallCommand.php` (type-hint changed), `src/Command/Install/BundlesPhpInstaller.php` (implements clause added), `tests/Unit/Command/TenancyInstallCommandTest.php` (stub class)
- **Verification:** 9 tests pass; PHPStan level 9 clean; cs-fixer empty diff
- **Committed in:** `3220a16`

**2. [Rule 1 - Bug] Unreachable defensive return after exhaustive switch triggers PHPStan deadCode.unreachable**
- **Found during:** Task 18-05-01 (PHPStan check)
- **Issue:** The plan's template included a post-switch defensive `return Command::FAILURE` after all 5 `InstallStatus` cases were covered. PHPStan level 9 correctly identifies this as unreachable dead code.
- **Fix:** Removed the defensive fallback block (2 lines). The switch is exhaustive; PHPStan proves it.
- **Files modified:** `src/Command/TenancyInstallCommand.php`
- **Verification:** `vendor/bin/phpstan analyse ... exits 0` (0 errors)
- **Committed in:** `3220a16`

**3. [Rule 1 - Bug] Application::add() does not exist — method is addCommand()**
- **Found during:** Task 18-05-01 (test execution)
- **Issue:** The plan's test template called `$app->add($command)`. In the Symfony Console version installed (`symfony/console ^7.4`), the method is `addCommand()` not `add()`.
- **Fix:** Changed `$app->add(...)` to `$app->addCommand(...)` in `buildTester()`.
- **Files modified:** `tests/Unit/Command/TenancyInstallCommandTest.php`
- **Verification:** All 9 tests pass
- **Committed in:** `3220a16`

---

**Total deviations:** 3 auto-fixed (all Rule 1 — API mismatch bugs in the plan's template content)
**Impact on plan:** All three fixes are correctness requirements. The interface extraction is the most impactful architecturally (adds `BundlesPhpInstallerInterface` as a new file) but follows the established codebase pattern. No behavioral scope creep.

## Known Stubs

None — all 5 `InstallStatus` branches are fully wired. No placeholder implementations remain.

## Threat Flags

T-INSTALL-05 (force+dry-run semantic confusion) — **mitigated** by mutual-exclusion guard at top of `execute()` returning `Command::INVALID` (exit 2) with explicit error message. Proven by `testForceAndDryRunMutuallyExclusiveReturnsInvalid`.

T-INSTALL-04 carry-forward (refusal branch wiring) — **mitigated**: `REFUSED_NON_STANDARD` branch does NOT delegate to `tenancy:init`; exits `Command::SUCCESS` with manual snippet. Proven by `testRefusedNonStandardExitsSuccessAndPrintsManualSnippet`.

No new security surface introduced beyond what the plan's threat model covers.

## Issues Encountered

Three API mismatches between the plan's template content and the actual installed library versions (PHPUnit 11 + Symfony Console version). All three were auto-fixed as Rule 1 bugs without requiring plan changes.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- Plan 06 (DI registration): `TenancyInstallCommand` and `BundlesPhpInstallerInterface`/`BundlesPhpInstaller` are ready for `config/services.php` wiring. The command accepts `(string $projectDir, BundlesPhpInstallerInterface $installer)` constructor args.
- Plan 09 (integration test): The command is fully functional for end-to-end integration testing via `CommandTester`.

## Self-Check: PASSED

- `src/Command/TenancyInstallCommand.php` — FOUND
- `src/Command/Install/BundlesPhpInstallerInterface.php` — FOUND
- `src/Command/Install/BundlesPhpInstaller.php` (modified) — FOUND
- `tests/Unit/Command/TenancyInstallCommandTest.php` — FOUND
- Task commit `3220a16` — FOUND
- PHPUnit: 9 tests, 30 assertions (TenancyInstallCommandTest only); 278 tests, 747 assertions (full unit suite) — PASSED
- PHPStan level 9 — PASSED (0 errors)
- php-cs-fixer @Symfony — PASSED (empty diff)

---
*Phase: 18-tenancy-install*
*Completed: 2026-05-18*
