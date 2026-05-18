---
phase: 18-tenancy-install
plan: "06"
subsystem: testing
tags: [symfony-console, command, integration-test, di-wiring, dx-06, installer, idempotency]

# Dependency graph
requires:
  - phase: 18-tenancy-install
    plan: "05"
    provides: "TenancyInstallCommand + BundlesPhpInstallerInterface — constructor args and type contracts needed for DI wiring"
  - phase: 18-tenancy-install
    plan: "04"
    provides: "BundlesPhpInstaller final class — the concrete implementation wired as tenancy.command.install.bundles_php_installer"
provides:
  - "tenancy.command.install (TenancyInstallCommand) DI-registered in config/services.php and tagged console.command"
  - "tenancy.command.install.bundles_php_installer (BundlesPhpInstaller) DI-registered as collaborator"
  - "InstallCommandTestKernel — subclass of CommandTestKernel accepting tmp projectDir via constructor"
  - "TenancyInstallCommandIntegrationTest — 6 end-to-end tests: DI registration, instance check, projectDir injection, skeleton write, dry-run, ddd-override refusal"
  - "TenancyInstallCommandIdempotencyTest — 3-run idempotency proof (1 .bak, byte-identical after first write)"
affects:
  - 18-09-PLAN (integration test may supplement or supersede if planned separately)
  - users running bin/console tenancy:install (command now discoverable by Symfony Console)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Symfony\\Bundle\\FrameworkBundle\\Console\\Application (not Symfony\\Component\\Console\\Application) required when booting a real kernel in integration tests — accepts KernelInterface, discovers all tagged commands"
    - "InstallCommandTestKernel subclass pattern: override getProjectDir() + unique getCacheDir()/getLogDir() per instance to prevent cache collisions between test runs"
    - "realpath() normalization in setUp() for macOS /var/ -> /private/var/ symlink stability in path assertions"
    - "RecursiveIteratorIterator typed via @var + instanceof guard for PHPStan level 9 compliance in cleanUp()"
    - "Per-test kernel boot (setUp/tearDown) required when each test needs a distinct projectDir fixture — accepted tradeoff: PHPUnit 11 marks tests risky (exception handlers not restored) but exit code 0"

key-files:
  created:
    - "tests/Integration/Command/Support/InstallCommandTestKernel.php — final kernel subclass; constructor accepts rootedProjectDir; overrides getProjectDir, getCacheDir, getLogDir"
    - "tests/Integration/Command/TenancyInstallCommandIntegrationTest.php — 6 tests: service registered, instance check, projectDir injection, skeleton write end-to-end, dry-run no-write, ddd-override refusal"
    - "tests/Integration/Command/TenancyInstallCommandIdempotencyTest.php — 1 test with 3 runs; proves .bak count stays at 1 and bytes are byte-identical after first write"
  modified:
    - "config/services.php — added BundlesPhpInstaller + TenancyInstallCommand imports; registered tenancy.command.install.bundles_php_installer and tenancy.command.install with console.command tag"
    - "tests/Integration/Command/Support/MakeCommandsPublicPass.php — added tenancy.command.install to $ids array for test container visibility"

key-decisions:
  - "Use Symfony\\Bundle\\FrameworkBundle\\Console\\Application not Symfony\\Component\\Console\\Application — the FrameworkBundle variant accepts a KernelInterface and auto-discovers all tagged console.command services; PHPStan level 9 rejects passing a kernel to the base Console Application constructor"
  - "Per-test kernel boot accepted despite PHPUnit risky flag — each integration test needs a distinct tmp projectDir fixture, making class-level static setup insufficient; PHPUnit 11's exception handler tracking marks these tests risky but exit code is 0 (passing)"
  - "realpath() normalization on macOS — sys_get_temp_dir() returns /var/folders/... but Symfony Kernel resolves it to /private/var/folders/... via realpath(); applied in setUp() for stable path assertions"

patterns-established:
  - "InstallCommandTestKernel subclass pattern: extend CommandTestKernel with a constructor parameter for the project directory root, enabling each test to point the kernel at a fresh tmp fixture dir while keeping the full DI stack under test"
  - "FrameworkBundle Console Application for integration tests: when a test needs to run a command via CommandTester with the full kernel service container, use Symfony\\Bundle\\FrameworkBundle\\Console\\Application(kernel) — it auto-discovers all tagged commands"

requirements-completed: [DX-06]

# Metrics
duration: "20min"
completed: "2026-05-18"
---

# Phase 18 Plan 06: Service Wiring + Integration Tests Summary

**tenancy.command.install wired into DI container and proven end-to-end: kernel boots at tmp fixture dir, BundlesPhpInstaller writes bundles.php to expected baseline, .bak created, tenancy:init delegated; idempotency across 3 consecutive runs verified (1 .bak, byte-identical)**

## Performance

- **Duration:** 20 min
- **Started:** 2026-05-18T07:51:00Z
- **Completed:** 2026-05-18T08:11:03Z
- **Tasks:** 3
- **Files modified:** 5 (2 modified, 3 created)

## Accomplishments

- Wired `tenancy.command.install` and `tenancy.command.install.bundles_php_installer` into `config/services.php` — command is now discoverable by `bin/console`
- Exposed `tenancy.command.install` in `MakeCommandsPublicPass` for test container access
- `InstallCommandTestKernel` ships: subclass of `CommandTestKernel` with caller-provided `rootedProjectDir`, unique cache/log dirs per instance
- 6 end-to-end integration tests in `TenancyInstallCommandIntegrationTest`: DI wiring assertions, projectDir injection via reflection, full skeleton write pipeline (bundles.php byte-match + .bak + tenancy.yaml), dry-run no-write, ddd-override refusal
- 1 idempotency test proving 3 consecutive runs produce only 1 .bak and byte-identical bundles.php after run 1

## Task Commits

1. **Task 18-06-01: Register tenancy.command.install in DI + expose via MakeCommandsPublicPass** - `f22bbe8` (feat)
2. **Task 18-06-02: InstallCommandTestKernel + 6 end-to-end integration tests** - `59d952b` (feat)
3. **Task 18-06-03: Idempotency test — three consecutive runs** - `f6eb551` (feat)

## Files Created/Modified

- `config/services.php` — added `use Tenancy\Bundle\Command\Install\BundlesPhpInstaller` + `use Tenancy\Bundle\Command\TenancyInstallCommand` imports; registered `tenancy.command.install.bundles_php_installer` (BundlesPhpInstaller) and `tenancy.command.install` (TenancyInstallCommand, args: projectDir + installer, tag: console.command)
- `tests/Integration/Command/Support/MakeCommandsPublicPass.php` — added `'tenancy.command.install'` to `$ids` array
- `tests/Integration/Command/Support/InstallCommandTestKernel.php` — final class extending CommandTestKernel; constructor `(string $rootedProjectDir, string $environment, bool $debug)`; overrides `getProjectDir()`, `getCacheDir()`, `getLogDir()` with instance-unique paths
- `tests/Integration/Command/TenancyInstallCommandIntegrationTest.php` — 6 tests; uses FrameworkBundle Console Application; setUp/tearDown per-test kernel boot; realpath() for macOS path stability; PHPStan-compliant cleanUp()
- `tests/Integration/Command/TenancyInstallCommandIdempotencyTest.php` — 1 test, 3 runs, 10 assertions; --force passed to avoid tenancy:init failure on runs 2+3

## Decisions Made

- **FrameworkBundle Console Application:** `Symfony\Bundle\FrameworkBundle\Console\Application` accepts a kernel and auto-discovers all tagged `console.command` services. The base `Symfony\Component\Console\Application` constructor takes a `string $name`, not a kernel — PHPStan level 9 rejects `new Application($kernel)` against the base class.
- **Per-test kernel boot accepted despite risky flag:** Each test needs a distinct tmp dir with its own fixtures. Sharing a static kernel would require the same tmp dir for all tests — not feasible with different fixture scenarios. PHPUnit 11's exception handler tracking marks tests as risky (Symfony kernel registers handlers during boot), but exit code remains 0 (all assertions pass).
- **realpath() normalization:** macOS's `sys_get_temp_dir()` returns `/var/folders/...` but the Symfony Kernel resolves project dir via `realpath()` internally, producing `/private/var/folders/...`. Applied `realpath()` in `setUp()` to normalize the path before any assertions.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Symfony\Component\Console\Application rejects kernel in constructor**
- **Found during:** Task 18-06-02 (PHPStan analysis)
- **Issue:** The plan's template specified `new Application($this->kernel)` using `Symfony\Component\Console\Application`, but the base Console Application constructor signature is `__construct(string $name, string $version)`. PHPStan level 9 correctly rejects passing a KernelInterface as `$name`.
- **Fix:** Changed import to `Symfony\Bundle\FrameworkBundle\Console\Application` which accepts a `KernelInterface` and auto-discovers all tagged commands.
- **Files modified:** `tests/Integration/Command/TenancyInstallCommandIntegrationTest.php`, `tests/Integration/Command/TenancyInstallCommandIdempotencyTest.php`
- **Verification:** PHPStan level 9 exits 0; all 6 integration tests pass; all 1 idempotency test passes
- **Committed in:** `59d952b` (Task 2 commit)

**2. [Rule 1 - Bug] macOS path normalization — sys_get_temp_dir() vs realpath()**
- **Found during:** Task 18-06-02 (test execution — testServiceReceivesProjectDirFromKernel)
- **Issue:** `sys_get_temp_dir()` returns `/var/folders/...` on macOS but Symfony Kernel internally normalizes via `realpath()` to `/private/var/folders/...`. The `testServiceReceivesProjectDirFromKernel` assertion compared the raw tmpDir against the DI-injected `kernel.project_dir` parameter and failed.
- **Fix:** Added `$this->tmpDir = (string) realpath($this->tmpDir);` at end of `setUp()` so the test's path reference matches what the kernel sees.
- **Files modified:** `tests/Integration/Command/TenancyInstallCommandIntegrationTest.php`, `tests/Integration/Command/TenancyInstallCommandIdempotencyTest.php`
- **Verification:** testServiceReceivesProjectDirFromKernel assertion passes; all path comparisons stable
- **Committed in:** `59d952b` (Task 2 commit)

**3. [Rule 1 - Bug] SplFileInfo type not inferred by PHPStan in RecursiveIteratorIterator**
- **Found during:** Task 18-06-02 (PHPStan analysis on cleanUp())
- **Issue:** PHPStan level 9 infers iterator value as `mixed`, not `SplFileInfo`, causing "Cannot call method getRealPath() on mixed" and "Cannot call method isDir() on mixed" errors.
- **Fix:** Added `/** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $iterator */` PHPDoc on the iterator variable, and added `instanceof SplFileInfo` guard in the foreach body with `false !== $realPath` check.
- **Files modified:** `tests/Integration/Command/TenancyInstallCommandIntegrationTest.php`, `tests/Integration/Command/TenancyInstallCommandIdempotencyTest.php`
- **Verification:** PHPStan level 9 exits 0 on both files
- **Committed in:** `59d952b` (Task 2 commit)

**4. [Rule 1 - Bug] PHP CS Fixer reformatted config/services.php and dropped pending edits**
- **Found during:** Task 18-06-01 (after running CS Fixer on config/services.php)
- **Issue:** Running `php-cs-fixer fix config/services.php` reordered imports and removed the BundlesPhpInstaller/TenancyInstallCommand `use` statements and service blocks I had just added (CS Fixer appeared to merge with a cached version of the file).
- **Fix:** Re-applied the two `use` statements and two service blocks after CS Fixer completed its reformat; ran CS Fixer again to confirm no further changes.
- **Files modified:** `config/services.php`
- **Verification:** CS Fixer check exits 0; grep confirms both service IDs present
- **Committed in:** `f22bbe8` (Task 1 commit)

---

**Total deviations:** 4 auto-fixed (all Rule 1 — API mismatch, macOS path behavior, PHPStan type inference, CS Fixer interaction)
**Impact on plan:** All four fixes are correctness requirements. No scope creep. The FrameworkBundle Application fix is the most significant (was in every end-to-end test); the rest are environment-specific adaptations.

## Known Stubs

None — all services are fully wired. The integration tests assert real DI resolution and real command execution against real fixtures.

## Threat Flags

**T-INSTALL-01 (mitigated):** `testEndToEndAgainstSkeletonFixture` asserts byte-equality against `.expected/skeleton/bundles.php`. The full pipeline (DI → BundlesPhpInstaller → atomic write → lint → tenancy:init) is verified end-to-end.

**T-INSTALL-04 (mitigated):** `testRefusalAgainstDddOverrideFixture` asserts bundles.php UNCHANGED + no .bak + no tenancy.yaml after refusal of the ddd-override fixture.

No new security surface introduced.

## Issues Encountered

- PHP CS Fixer run during Task 1 validation also touched fixture files in the worktree that were already different from the main branch (from prior parallel executor runs). These were immediately restored via `git checkout -- tests/Fixtures/...` to prevent breaking pre-existing unit tests.
- Worktree context prevents running the full integration suite (class redeclaration fatal: both worktree `src/` and main `src/` map to `Tenancy\Bundle\` — prepend and vendor autoloader produce duplicate class declarations). Integration tests verified via `vendor/bin/phpunit --bootstrap=/tmp/worktree_bootstrap.php <test-file>` which correctly maps both `src/` and `tests/` to the worktree paths, producing exit 0. Unit suite (287 tests) verified via worktree phpunit.xml.dist. This limitation is documented in prior phase summaries (18-04-SUMMARY.md).

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- DX-06 acceptance criteria satisfied: service registered, end-to-end proven, idempotency proven
- `tenancy:install` is now discoverable by `bin/console` — real users running `composer require danplaton4/tenancy-bundle && bin/console tenancy:install` will succeed
- Phase 18 can be considered feature-complete for the install command path

## Self-Check: PASSED

- `config/services.php` (modified) — FOUND, contains `tenancy.command.install`
- `tests/Integration/Command/Support/MakeCommandsPublicPass.php` (modified) — FOUND, contains `tenancy.command.install`
- `tests/Integration/Command/Support/InstallCommandTestKernel.php` — FOUND
- `tests/Integration/Command/TenancyInstallCommandIntegrationTest.php` — FOUND
- `tests/Integration/Command/TenancyInstallCommandIdempotencyTest.php` — FOUND
- Task commit `f22bbe8` — FOUND
- Task commit `59d952b` — FOUND
- Task commit `f6eb551` — FOUND
- Unit suite: 287 tests, 777 assertions — PASSED
- Integration tests (worktree bootstrap): 6 tests pass (TenancyInstallCommandIntegrationTest), 1 test passes (TenancyInstallCommandIdempotencyTest) — PASSED (exit 0)
- PHPStan level 9 on new/modified test files — PASSED (0 errors)
- php-cs-fixer @Symfony on all modified files — PASSED (empty diff)

---
*Phase: 18-tenancy-install*
*Completed: 2026-05-18*
