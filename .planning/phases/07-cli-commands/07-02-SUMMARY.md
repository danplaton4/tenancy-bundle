---
phase: 07-cli-commands
plan: "02"
subsystem: cli
tags: [symfony-process, console-command, subprocess, tenant-context, di]

# Dependency graph
requires:
  - phase: 02-tenant-resolution
    provides: TenantProviderInterface, ConsoleResolver, TenantNotFoundException
  - phase: 01-core-foundation
    provides: TenantInterface, TenantContext, BootstrapperChain
provides:
  - tenancy:run console command spawning subprocess with full tenant context
  - symfony/process promoted to production dependency
  - DI registration of TenantRunCommand with kernel.project_dir
affects: [07-cli-commands, testing]

# Tech tracking
tech-stack:
  added: [symfony/process ^6.4||^7.0]
  patterns:
    - processFactory closure injection for testable subprocess creation
    - TDD RED/GREEN cycle for console command with subprocess spawning

key-files:
  created:
    - src/Command/TenantRunCommand.php
    - tests/Unit/Command/TenantRunCommandTest.php
  modified:
    - config/services.php
    - composer.json
    - composer.lock

key-decisions:
  - "processFactory optional Closure injected as third constructor param — enables unit testing without real subprocess spawning"
  - "Process::fromShellCommandline with escapeshellarg used in production path for correct shell quoting"
  - "Test assertion uses regex to match --tenant='acme' (POSIX-quoted) rather than exact string --tenant=acme"
  - "symfony/process promoted from require-dev to require (production dependency) since tenancy:run is a core feature"

patterns-established:
  - "Process factory pattern: optional Closure in constructor, null means use real Process, non-null means test stub"
  - "TenantRunCommand validates tenant with findBySlug() before spawning — exceptions bubble without catch"

requirements-completed: [CLI-02]

# Metrics
duration: 2min
completed: 2026-04-01
---

# Phase 07 Plan 02: TenantRunCommand Summary

**tenancy:run console command spawning bin/console subprocess with --tenant= pass-through, forwarding stdout/stderr and propagating exit codes, via symfony/process promoted to production dependency**

## Performance

- **Duration:** 2 min
- **Started:** 2026-04-01T21:38:18Z
- **Completed:** 2026-04-01T21:40:38Z
- **Tasks:** 1 (TDD: 2 commits — RED + GREEN)
- **Files modified:** 4

## Accomplishments

- Implemented `TenantRunCommand` (`tenancy:run {tenant} "command:name args"`) that validates tenant existence via `findBySlug()`, builds a shell command line with `Process::fromShellCommandline`, forwards stdout/stderr via run() callback, and returns the child process exit code
- Promoted `symfony/process` from absent/dev to production `require` block (`^6.4||^7.0`) and updated composer.lock
- Registered `tenancy.command.run` in `config/services.php` with `kernel.project_dir` injection and `console.command` tag
- 4 unit tests passing: valid tenant spawns process with correct args, exit code propagation, output forwarding, TenantNotFoundException bubbles before subprocess

## Task Commits

Each task was committed atomically using TDD:

1. **RED — Failing tests** - `2b5d8a6` (test)
2. **GREEN — Implementation** - `e4c2bcf` (feat)

## Files Created/Modified

- `src/Command/TenantRunCommand.php` — Final class implementing tenancy:run with processFactory injection pattern
- `tests/Unit/Command/TenantRunCommandTest.php` — 4 unit tests covering subprocess args, exit code, output forwarding, tenant validation
- `config/services.php` — Added `tenancy.command.run` service registration with `kernel.project_dir` and `console.command` tag
- `composer.json` — symfony/process added to production `require` block
- `composer.lock` — Updated to include symfony/process v7.4.8

## Decisions Made

- **processFactory pattern**: Made `?\Closure $processFactory = null` the third constructor parameter. In production (DI), it's null and the command uses real `Process::fromShellCommandline`. In tests, callers inject a closure returning a Process stub. This avoids spawning real subprocesses in unit tests while keeping production code clean.
- **Test assertion regex**: `escapeshellarg('acme')` produces `'acme'` on POSIX systems, so the test uses `assertMatchesRegularExpression('/--tenant=[\'"]?acme[\'"]?/')` to be platform-safe.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Test assertion regex for shell-quoted --tenant= argument**
- **Found during:** Task 1 GREEN phase
- **Issue:** Test asserted `--tenant=acme` but `escapeshellarg()` produces `--tenant='acme'` on POSIX systems, causing test failure
- **Fix:** Changed assertion to `assertMatchesRegularExpression('/--tenant=[\'"]?acme[\'"]?/')` to handle POSIX single-quote and Windows double-quote
- **Files modified:** tests/Unit/Command/TenantRunCommandTest.php
- **Verification:** All 4 tests pass after fix
- **Committed in:** e4c2bcf (feat commit, included in GREEN phase)

---

**Total deviations:** 1 auto-fixed (Rule 1 - Bug)
**Impact on plan:** Necessary for cross-platform test correctness. No scope creep.

## Issues Encountered

- composer.lock was stale (missing symfony/cache and symfony/console) — resolved with `composer update` before running tests
- symfony/process was not yet installed; adding to `require` and running `composer update symfony/process` resolved mock class resolution errors in tests

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- `tenancy:run` is complete and production-ready
- Phase 07-03 (if it exists) can build on tenancy:run or add additional CLI commands
- The `processFactory` pattern can be reused in other commands requiring testable subprocess spawning

---
*Phase: 07-cli-commands*
*Completed: 2026-04-01*
