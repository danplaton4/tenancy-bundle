---
phase: 18-tenancy-install
verified: 2026-05-18T08:41:24Z
status: human_needed
score: 5/5
overrides_applied: 0
human_verification:
  - test: "Run `bin/console tenancy:install` on a real fresh Symfony skeleton project (not a test kernel) and confirm the terminal shows the success transcript, bundles.php is updated correctly, tenancy.yaml is created, and exit code is 0."
    expected: "TenancyBundle registered in config/bundles.php, config/packages/tenancy.yaml created, 'Next steps' printed, exit code 0."
    why_human: "Integration tests use a test kernel with MakeCommandsPublicPass; cannot fully substitute for `bin/console` console discovery in a real app install."
---

# Phase 18: tenancy-install Verification Report

**Phase Goal:** A first-time user runs `composer require danplaton4/tenancy-bundle && bin/console tenancy:install` and the bundle is registered, configured, and ready — no manual `config/bundles.php` editing on the install path.
**Verified:** 2026-05-18T08:41:24Z
**Status:** human_needed
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | `bin/console tenancy:install` on a fresh Symfony skeleton results in `TenancyBundle::class` registered in `config/bundles.php` AND `config/packages/tenancy.yaml` written by the delegated `tenancy:init` call | VERIFIED | `TenancyInstallCommandIntegrationTest::testEndToEndAgainstSkeletonFixture` passes: bundles.php byte-matches `.expected/skeleton/bundles.php`, `tenancy.yaml` file created, exit SUCCESS (7 integration tests pass, 33 assertions) |
| 2 | Re-running `bin/console tenancy:install` is idempotent (bundle already present → exits 0 with informational message, no file mutation) | VERIFIED | `TenancyInstallCommandIdempotencyTest::testThreeConsecutiveRunsLeaveBytesIdenticalAfterFirstWrite` passes: 3 consecutive runs, 1 .bak created (not 3), bytes byte-identical after run 1 |
| 3 | `bin/console tenancy:install --dry-run` prints the proposed mutation to stdout without writing | VERIFIED | `TenancyInstallCommandIntegrationTest::testDryRunDoesNotWrite` passes; `TenancyInstallCommandTest::testDryRunSkipsTenancyInitInvocation` passes; `BundlesPhpInstallerTest::testDryRunDoesNotWrite` passes |
| 4 | On any of the 6 fixture-corpus shapes the command either (a) succeeds, OR (b) refuses to mutate and prints a clean manual snippet — never produces an invalid `bundles.php` | VERIFIED | 7 fixtures exercised: skeleton/api-platform/sulu/with-comments → WROTE (byte-exact match with `.expected/` baselines); ddd-override/env-conditional/malformed → REFUSED_NON_STANDARD. All 18 BundlesPhpInstallerTest tests pass, 70 assertions. |
| 5 | `nikic/php-parser` is in `require-dev` only and loaded lazily (verified by test on the bundle's runtime container) | VERIFIED | `composer.json` confirms absent from `require`, present in `require-dev` (`^5.0`) and `suggest`. `class_exists(ParserFactory::class)` guard present in both `install()` and `detect()` (2 occurrences verified by grep). `ComposerJsonContractTest` passes (3 tests, 12 assertions). PHPStan level 9 clean (0 errors). |

**Score:** 5/5 truths verified

---

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `composer.json` | `nikic/php-parser ^5.0` in `require-dev` + `suggest`, absent from `require` | VERIFIED | Confirmed via `php -r` check and `ComposerJsonContractTest` (3/3 assertions pass) |
| `src/Command/TenancyInstallCommand.php` | `#[AsCommand(name: 'tenancy:install')]`, extends Command, `--force`/`--dry-run` flags, all 5 InstallStatus branches | VERIFIED | File exists; `#[AsCommand(name: 'tenancy:install')]` present; both flags declared; switch covers DEV_DEPENDENCY_MISSING, REFUSED_NON_STANDARD, LINT_FAILED_RESTORED, WROTE, ALREADY_REGISTERED |
| `src/Command/Install/BundlesPhpInstaller.php` | AST detector, full write path, `Filesystem::dumpFile`, `.bak` via `Filesystem::copy`, `php -l` lint, restore via `copy` (NOT rename) | VERIFIED | All write path components confirmed: `dumpFile`, `gmdate('Ymd-His')` for .bak naming, `copy(bak, bundles, true)` for restore, `defaultLintRunner` using `Process`, no use of `rename` |
| `src/Command/Install/BundlesPhpInstallerInterface.php` | Single-method interface for testability | VERIFIED | File exists, `install()` method declared |
| `src/Command/Install/InstallStatus.php` | Backed-string enum, 5 cases | VERIFIED | `enum InstallStatus: string`, 5 cases: WROTE, ALREADY_REGISTERED, REFUSED_NON_STANDARD, LINT_FAILED_RESTORED, DEV_DEPENDENCY_MISSING |
| `src/Command/Install/InstallResult.php` | `final readonly class`, 6 static named constructors (includes `dryRun()`) | VERIFIED | `final readonly class InstallResult` with `wrote`, `dryRun`, `alreadyRegistered`, `refusedNonStandard`, `lintFailedRestored`, `devDependencyMissing` |
| `src/Command/Install/DetectionResult.php` | Internal DTO for detect() return | VERIFIED | `final readonly class DetectionResult` with `standard/nonStandard/missing` factories |
| `config/services.php` | `tenancy.command.install` + `tenancy.command.install.bundles_php_installer` registered, `console.command` tagged | VERIFIED | Lines 125-132 confirm both service registrations; `->tag('console.command')` at line 132 |
| `tests/Fixtures/BundlesPhpCorpus/` | 7 input fixtures (skeleton, api-platform, sulu, ddd-override, with-comments, env-conditional, malformed) | VERIFIED | All 7 directories exist with `bundles.php` |
| `tests/Fixtures/BundlesPhpCorpus/.expected/` | 4 post-mutation baselines (skeleton, api-platform, sulu, with-comments) — each contains Tenancy entry | VERIFIED | All 4 baseline files exist; all confirmed to contain `Tenancy\Bundle\TenancyBundle::class` |
| `tests/Unit/Command/Install/BundlesPhpInstallerTest.php` | `#[DataProvider]`, 7 fixture rows, all 7 classified correctly | VERIFIED | 18 tests, 70 assertions pass; 7 `yield` rows confirmed |
| `tests/Unit/Command/Install/BundlesPhpInstallerSafetyTest.php` | T-INSTALL-02: .bak survives lint-failure restore (copy, not rename) | VERIFIED | 2 tests, 12 assertions pass; `assertFileExists($result->backupPath)` after restore confirmed |
| `tests/Unit/Command/TenancyInstallCommandTest.php` | 9 unit tests covering all outcome branches | VERIFIED | 9 tests, 30 assertions pass |
| `tests/Integration/Command/Support/InstallCommandTestKernel.php` | Subclass of CommandTestKernel with configurable projectDir | VERIFIED | File exists, extends CommandTestKernel, overrides `getProjectDir()` |
| `tests/Integration/Command/TenancyInstallCommandIntegrationTest.php` | 6 end-to-end tests; DI wiring, skeleton write, dry-run, refusal | VERIFIED | 6 tests pass (risky flag is PHPUnit 11 housekeeping, not failure; all 33 assertions across both integration files pass) |
| `tests/Integration/Command/TenancyInstallCommandIdempotencyTest.php` | 3-run idempotency proof | VERIFIED | 1 test, 3 runs: 10 assertions pass |
| `tests/Unit/Composer/ComposerJsonContractTest.php` | 3 contract tests guarding nikic/php-parser placement | VERIFIED | 3 tests, 12 assertions pass |
| `CHANGELOG.md` | `[Unreleased]` entry for `tenancy:install` + `BundlesPhpInstaller` with DX-06, DEC-INST-01/02 citations | VERIFIED | `tenancy:install`, `DX-06`, `BundlesPhpInstaller`, `DEC-INST-01`, `DEC-INST-02` all present |

---

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `config/services.php` | `TenancyInstallCommand::class` | `$services->set('tenancy.command.install', TenancyInstallCommand::class)` | WIRED | Line 127; `->tag('console.command')` at line 132 |
| `config/services.php` | `BundlesPhpInstaller::class` | `service('tenancy.command.install.bundles_php_installer')` | WIRED | Line 125 registers installer; line 130 injects into command |
| `TenancyInstallCommand` | `BundlesPhpInstallerInterface` | Constructor injection `private readonly BundlesPhpInstallerInterface $installer` | WIRED | Line 40 of TenancyInstallCommand.php |
| `TenancyInstallCommand` | `tenancy:init` | `$app->find('tenancy:init')->run(new ArrayInput(['--force' => $force]), $output)` | WIRED | Lines 134-137 of TenancyInstallCommand.php |
| `BundlesPhpInstaller` | `PhpParser\Node\ArrayItem` | `use PhpParser\Node\ArrayItem;` (top-level Node\, NOT deprecated Node\Expr alias) | WIRED | Line 8 confirmed; no `use PhpParser\Node\Expr\ArrayItem` found |
| `BundlesPhpInstallerTest` | `tests/Fixtures/BundlesPhpCorpus/{slug}/bundles.php` | `#[DataProvider('fixturesProvider')]` yielding 7 slug rows | WIRED | 7 yield rows confirmed; `#[DataProvider]` attribute confirmed |
| `MakeCommandsPublicPass` | `tenancy.command.install` | `$ids` array at line 22 | WIRED | `'tenancy.command.install'` present in MakeCommandsPublicPass |

---

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `TenancyInstallCommand::execute()` | `$result` (InstallResult) | `$this->installer->install($bundlesPhpPath, $dryRun)` → `BundlesPhpInstaller::install()` → real file read + AST parse | Yes — real filesystem I/O + PhpParser AST | FLOWING |
| `BundlesPhpInstaller::install()` | `$newSource` (mutated file content) | `buildMutatedSource($source, $detection->endPos)` using real `file_get_contents` + AST-derived byte offset | Yes — real file, real AST offset | FLOWING |
| `BundlesPhpInstaller::detect()` | `$stmts` (AST nodes) | `(new ParserFactory())->createForNewestSupportedVersion()->parse($source)` from real file | Yes — real parser on real file | FLOWING |

---

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| `ComposerJsonContractTest` — nikic/php-parser not in `require` | `vendor/bin/phpunit tests/Unit/Composer/ComposerJsonContractTest.php` | 3 tests, 12 assertions, OK | PASS |
| All 7 fixtures classify correctly | `vendor/bin/phpunit tests/Unit/Command/Install/BundlesPhpInstallerTest.php` | 18 tests, 70 assertions, OK | PASS |
| 9 unit tests for all command branches | `vendor/bin/phpunit tests/Unit/Command/TenancyInstallCommandTest.php` | 9 tests, 30 assertions, OK | PASS |
| .bak survives lint-failure restore | `vendor/bin/phpunit tests/Unit/Command/Install/BundlesPhpInstallerSafetyTest.php` | 2 tests, 12 assertions, OK | PASS |
| End-to-end + idempotency integration | `vendor/bin/phpunit tests/Integration/Command/TenancyInstallCommandIntegrationTest.php tests/Integration/Command/TenancyInstallCommandIdempotencyTest.php` | 7 tests, 33 assertions, OK (7 risky — known PHPUnit 11 kernel boot handler note, not failures) | PASS |
| Full unit suite — no regressions | `vendor/bin/phpunit --testsuite unit` | 287 tests, 777 assertions, OK | PASS |
| PHPStan level 9 | `vendor/bin/phpstan analyse --memory-limit=512M` | 0 errors | PASS |
| Command name callable | `php -r '…new TenancyInstallCommand(…)->getName()…'` | `tenancy:install` | PASS |

---

### Requirements Coverage

| Requirement | Source Plan(s) | Description | Status | Evidence |
|-------------|----------------|-------------|--------|----------|
| DX-06 | 18-01 through 18-07 | `tenancy:install` command — idempotent, `--dry-run`, AST detect via nikic (require-dev, lazy), refuses non-standard shapes, atomic write + .bak + `php -l` + restore-via-copy | SATISFIED | All 5 ROADMAP success criteria verified; all DX-06 acceptance criteria met; `Closes DX-06` cited in CHANGELOG.md |

---

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `src/Command/Install/InstallResult.php` | 62-67 | `isSuccessOutcome()` includes `REFUSED_NON_STANDARD` (contradicts docblock: "WROTE or ALREADY_REGISTERED?"); zero callers found in `src/` and `tests/` | Warning | Dead code with misleading contract; does not affect runtime behavior (never called); noted as WR-03 in code review |
| `src/Command/TenancyInstallCommand.php` | 69-121 | `switch ($result->status)` without `default` branch — new `InstallStatus` case added without updating this switch would silently return `null` (TypeError from Symfony `Command::run()`) | Warning | Defensive robustness gap; does not affect current 5-case coverage; noted as WR-04 in code review |
| `src/Command/Install/BundlesPhpInstaller.php` | 210 | `buildMutatedSource()`: when `$prevChar === '['` (empty array input), `$prefix = ''` — entry jammed immediately after `[` on same line; `php -l` passes so lint guard does not catch | Warning | Edge case not exercised by any current fixture; standard Symfony skeletons always have at least one bundle so this path does not affect the happy-path corpus; noted as WR-01 in code review |
| `src/Command/Install/BundlesPhpInstaller.php` | 208-218 | `buildMutatedSource()`: when last entry has no trailing comma, comma appears on its own line before the new entry (syntactically valid, malformatted); no fixture covers this | Warning | Edge case; all upstream-grounded fixtures use trailing commas; noted as WR-02 in code review |

**Blocker anti-patterns:** None. All four warnings are code quality items that do not block the phase goal on any documented corpus shape. The four `.expected/` baselines produce byte-identical output (confirmed by `assertStringEqualsFile` in 18 tests).

---

### Human Verification Required

#### 1. Real `bin/console` Invocation on Fresh Skeleton

**Test:** Create a fresh Symfony skeleton app (`composer create-project symfony/skeleton myapp`), add the bundle (`composer require danplaton4/tenancy-bundle`), then run `bin/console tenancy:install`.
**Expected:** Terminal shows the "Tenancy Bundle — Installer" title, `config/bundles.php` contains `Tenancy\Bundle\TenancyBundle::class => ['all' => true]`, `config/packages/tenancy.yaml` is created, "Next steps" section is printed, exit code is 0.
**Why human:** The integration tests use a test kernel with a compiler pass (`MakeCommandsPublicPass`) to expose the command. This is structurally identical to production DI but cannot fully substitute for the real Symfony Console command-discovery path (`bin/console` reads kernel tagged services). Confirming the end-user flow requires a real app.

---

### Gaps Summary

No blocking gaps found. All 5 ROADMAP success criteria are verified against the codebase. DX-06 acceptance criteria are fully satisfied. The four code-review warnings (WR-01 through WR-04) do not block the phase goal and are informational for follow-up work.

The `human_needed` status reflects a single remaining item: confirming the full `bin/console tenancy:install` flow in a real Symfony skeleton app, which cannot be verified programmatically from within this repository's test environment.

---

_Verified: 2026-05-18T08:41:24Z_
_Verifier: Claude (gsd-verifier)_
