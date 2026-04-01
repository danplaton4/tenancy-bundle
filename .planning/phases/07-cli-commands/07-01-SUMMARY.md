---
phase: 07-cli-commands
plan: 01
subsystem: cli
tags: [console, doctrine-migrations, tenant-provider, dependency-factory]

# Dependency graph
requires:
  - phase: 01-core-foundation
    provides: BootstrapperChain, TenantContext, TenantInterface
  - phase: 02-tenant-resolution
    provides: TenantProviderInterface, DoctrineTenantProvider
provides:
  - TenantProviderInterface::findAll() returning all tenants bypassing cache
  - TenantMigrateCommand (tenancy:migrate) for sequential per-tenant migrations
  - DI wiring with class_exists guard on DependencyFactory
affects:
  - 07-02-PLAN: TenantRunCommand depends on TenantProviderInterface::findAll()
  - integration-tests: migrations command testable via CommandTester

# Tech tracking
tech-stack:
  added:
    - doctrine/migrations ^3.9 (require-dev + suggest)
    - symfony/process ^6.4||^7.0 (require-dev + suggest; production require is Plan 07-02)
  patterns:
    - class_exists guard pattern for optional-dependency DI registration
    - TDD RED-GREEN cycle for console commands using CommandTester
    - SpyBootstrapper pattern for testing BootstrapperChain interactions (final class)

key-files:
  created:
    - src/Command/TenantMigrateCommand.php
    - tests/Unit/Command/TenantMigrateCommandTest.php
  modified:
    - src/Provider/TenantProviderInterface.php (added findAll())
    - src/Provider/DoctrineTenantProvider.php (implemented findAll())
    - tests/Unit/Provider/DoctrineTenantProviderTest.php (added findAll tests)
    - config/services.php (added class_exists guard + tenancy.command.migrate)
    - composer.json (doctrine/migrations + symfony/process in suggest + require-dev)

key-decisions:
  - "findAll() bypasses cache intentionally — operator tool not a hot path; returns ALL tenants including inactive"
  - "class_exists guard on DependencyFactory ensures command not registered when doctrine/migrations absent"
  - "ConsoleOutputInterface check for getErrorOutput() — CommandTester uses StreamOutput, not ConsoleOutput"
  - "symfony/process added to require-dev and suggest only; Plan 07-02 promotes it to production require"

patterns-established:
  - "class_exists guard pattern: wrap optional-dep services in if (class_exists(...)) in services.php"
  - "TDD for console commands: write failing tests first, implement minimal command to pass"

requirements-completed: [CLI-01]

# Metrics
duration: 3min
completed: 2026-04-01
---

# Phase 7 Plan 1: CLI Commands — tenancy:migrate Summary

**tenancy:migrate console command with per-tenant Doctrine Migrations execution, continue-on-failure loop, --tenant filter, and class_exists guard DI wiring**

## Performance

- **Duration:** 3 min
- **Started:** 2026-04-01T21:38:10Z
- **Completed:** 2026-04-01T21:41:39Z
- **Tasks:** 2
- **Files modified:** 7

## Accomplishments
- Added `findAll(): TenantInterface[]` to TenantProviderInterface and DoctrineTenantProvider (bypasses cache)
- Implemented `tenancy:migrate` command with per-tenant sequential migration execution, continue-on-failure, summary output, and `--tenant=<slug>` single-tenant filter
- DI registered with `class_exists(\Doctrine\Migrations\DependencyFactory::class)` guard so command absent when migrations not installed
- Full unit test suite (6 tests) via TDD RED-GREEN cycle

## Task Commits

Each task was committed atomically:

1. **Task 1: Add findAll() to TenantProviderInterface and DoctrineTenantProvider** - `1805255` (feat)
2. **TDD RED: Failing tests for TenantMigrateCommand** - `89f665a` (test)
3. **Task 2: Implement TenantMigrateCommand with DI wiring** - `7a270e6` (feat)

_Note: Task 2 is a TDD task — separate RED (test) and GREEN (feat) commits._

## Files Created/Modified
- `src/Provider/TenantProviderInterface.php` - Added `findAll(): TenantInterface[]` method signature
- `src/Provider/DoctrineTenantProvider.php` - Implemented `findAll()` via EM->getRepository()->findAll()
- `src/Command/TenantMigrateCommand.php` - New `tenancy:migrate` command with full orchestration logic
- `tests/Unit/Provider/DoctrineTenantProviderTest.php` - Added 2 findAll tests (cache never called)
- `tests/Unit/Command/TenantMigrateCommandTest.php` - New 6-test suite for TenantMigrateCommand
- `config/services.php` - Added class_exists guard + tenancy.command.migrate DI registration
- `composer.json` - Added doctrine/migrations and symfony/process to suggest + require-dev

## Decisions Made
- `findAll()` bypasses cache intentionally — it's an operator tool (migration commands), not a hot path. Returns ALL tenants including inactive so operators can migrate inactive tenant DBs for reactivation.
- `class_exists(\Doctrine\Migrations\DependencyFactory::class)` guard in services.php ensures the command is only registered when the package is installed — consistent with the Messenger middleware guard pattern.
- symfony/process added to require-dev and suggest only; Plan 07-02 is the owner of promoting it to production require.
- Used `ConsoleOutputInterface` instanceof check before calling `getErrorOutput()` — CommandTester uses `StreamOutput` which doesn't implement that interface.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed ConsoleOutputInterface check for getErrorOutput()**
- **Found during:** Task 2 (TDD GREEN phase)
- **Issue:** Plan specified `$output->getErrorOutput()` directly, but CommandTester passes `StreamOutput` which doesn't implement `ConsoleOutputInterface`. Test failed with "Call to undefined method StreamOutput::getErrorOutput()"
- **Fix:** Added `instanceof ConsoleOutputInterface` check — falls back to `$output` when not available (CommandTester) while still using error output in real console context
- **Files modified:** src/Command/TenantMigrateCommand.php
- **Verification:** All 6 tests pass
- **Committed in:** 7a270e6 (Task 2 commit)

---

**Total deviations:** 1 auto-fixed (Rule 1 - Bug)
**Impact on plan:** Essential for testability. No scope creep. Behavior identical in production (real console output always implements ConsoleOutputInterface).

## Issues Encountered
- None beyond the ConsoleOutputInterface deviation documented above.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- `TenantProviderInterface::findAll()` ready for use by Plan 07-02 (TenantRunCommand)
- `symfony/process` in require-dev ready; Plan 07-02 promotes it to require
- All 70 unit tests pass

---
*Phase: 07-cli-commands*
*Completed: 2026-04-01*
