---
phase: 12-developer-onboarding
plan: 01
subsystem: cli
tags: [symfony, console, command, yaml, scaffolding, onboarding, doctrine]

# Dependency graph
requires:
  - phase: 07-cli-commands
    provides: TenantRunCommand pattern for command structure and DI wiring
provides:
  - tenancy:init console command that scaffolds config/packages/tenancy.yaml
  - Doctrine detection at runtime with driver recommendation
  - Overwrite protection with --force bypass
  - Next-steps guidance for new bundle users
affects:
  - developer-onboarding
  - documentation

# Tech tracking
tech-stack:
  added: []
  patterns:
    - Symfony console command with SymfonyStyle for formatted output
    - Runtime Doctrine detection via interface_exists() (consistent with bundle guards)
    - YAML template generation via PHP string array (avoids heredoc indentation pitfalls)
    - MakeCommandsPublicPass pattern for exposing private command services in integration tests
    - Worktree bootstrap.php prepends worktree src/tests to autoloader for isolated testing

key-files:
  created:
    - src/Command/TenantInitCommand.php
    - tests/Unit/Command/TenantInitCommandTest.php
    - tests/Integration/Command/TenantInitCommandIntegrationTest.php
  modified:
    - config/services.php
    - tests/Integration/Command/Support/MakeCommandsPublicPass.php
    - tests/bootstrap.php

key-decisions:
  - "Use interface_exists() not class_exists() for Doctrine detection — EntityManagerInterface is an interface; class_exists() returns false for interfaces (consistent with bundle's existing MessageBusInterface guard pattern)"
  - "YAML template built via array+implode, not heredoc — PHP heredoc indentation stripping caused wrong YAML indentation when interpolating variables"
  - "tenancy.command.init registered unconditionally (no guards) — command only writes a config file, no optional dependencies required"
  - "Worktree bootstrap prepends worktree paths to shared vendor autoloader — enables worktree-isolated tests without duplicating vendor dependencies"

patterns-established:
  - "Console command follows final class + AsCommand attribute + parent::__construct() pattern (matches TenantRunCommand)"
  - "Doctrine presence detection uses interface_exists() for interfaces, class_exists() for classes"
  - "Integration tests use same CommandTestKernel + MakeCommandsPublicPass + ReflectionProperty pattern"

requirements-completed: [DX-01, DX-02, DX-03, DX-04, DX-05]

# Metrics
duration: 7min
completed: 2026-04-13
---

# Phase 12 Plan 01: Tenancy Init Command Summary

**tenancy:init console command scaffolds fully commented config/packages/tenancy.yaml with Doctrine-aware driver recommendation, overwrite protection, and next-steps guidance**

## Performance

- **Duration:** 7 min
- **Started:** 2026-04-13T13:41:01Z
- **Completed:** 2026-04-13T13:48:25Z
- **Tasks:** 3
- **Files modified:** 6

## Accomplishments

- `tenancy:init` command creates a fully commented `config/packages/tenancy.yaml` covering all 8 bundle config keys
- Detects Doctrine ORM at runtime and recommends the appropriate isolation driver
- Guards existing files from accidental overwrite; `--force` flag enables intentional overwrite
- Next-steps section guides users to create Tenant entity, configure resolvers, and run migrations
- Command is registered unconditionally in DI container with `kernel.project_dir` injection
- 6 unit tests + 3 integration tests; PHPStan level 9 and php-cs-fixer both clean

## Task Commits

Each task was committed atomically:

1. **Task 1: Create TenantInitCommand** - `2f0fed7` (feat)
2. **Task 2: DI wiring, unit tests, MakeCommandsPublicPass** - `357ecef` (feat)
3. **Task 3: Integration tests** - `9be8e54` (test)

## Files Created/Modified

- `src/Command/TenantInitCommand.php` - tenancy:init command with YAML generation, Doctrine detection, --force overwrite, next-steps output
- `config/services.php` - Adds `tenancy.command.init` service registration with `kernel.project_dir` arg
- `tests/Unit/Command/TenantInitCommandTest.php` - 6 unit tests covering all command behaviors
- `tests/Integration/Command/TenantInitCommandIntegrationTest.php` - 3 integration tests for DI wiring
- `tests/Integration/Command/Support/MakeCommandsPublicPass.php` - Added `tenancy.command.init` to public IDs list
- `tests/bootstrap.php` - Added worktree src/tests path prepend for isolated autoloading

## Decisions Made

1. Used `interface_exists()` instead of `class_exists()` for Doctrine detection — `EntityManagerInterface` is an interface and `class_exists()` returns `false` for interfaces. This matches the existing bundle pattern used for `MessageBusInterface`.

2. Built YAML template content via array + `implode("\n")` instead of PHP heredoc with variable interpolation. Heredoc strips leading whitespace based on closing marker indentation, causing incorrect YAML indentation when variables were embedded mid-content.

3. Registered `tenancy.command.init` unconditionally (no Doctrine or other guards). The command only writes a static config file — no optional dependencies are used at runtime.

4. Updated worktree `tests/bootstrap.php` to prepend the worktree's `src/` and `tests/` directories to the Composer autoloader. This ensures the worktree's new classes are found first when the shared vendor autoloader is used in a git worktree.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed Doctrine detection: class_exists() → interface_exists()**
- **Found during:** Task 2 (unit test execution)
- **Issue:** The plan specified `class_exists(\Doctrine\ORM\EntityManagerInterface::class)`, but `EntityManagerInterface` is an interface — `class_exists()` returns `false` for interfaces. The unit test `testDoctrineDetectionOutputsRecommendation` failed with "Doctrine ORM not detected" even though `doctrine/orm` is installed.
- **Fix:** Changed to `interface_exists(\Doctrine\ORM\EntityManagerInterface::class)`, consistent with the bundle's pattern from Phase 06 (`interface_exists(MessageBusInterface::class)`)
- **Files modified:** `src/Command/TenantInitCommand.php`
- **Verification:** Unit test `testDoctrineDetectionOutputsRecommendation` passes; PHPStan clean
- **Committed in:** `357ecef` (Task 2 commit)

**2. [Rule 1 - Bug] Fixed YAML output indentation: heredoc → array+implode**
- **Found during:** Task 2 (manual YAML content inspection)
- **Issue:** PHP heredoc with variable interpolation caused incorrect YAML indentation. The `{$driverLine}` and `{$databaseEnabledLine}` variables were at 4-space indent but the heredoc closing marker stripped the wrong amount of whitespace, resulting in 8-space indent for those lines.
- **Fix:** Replaced heredoc with a `$lines[]` array and `implode("\n", $lines)`. Each line is an explicit string, eliminating indentation ambiguity.
- **Files modified:** `src/Command/TenantInitCommand.php`
- **Verification:** `driver: database_per_tenant` appears at 4-space indent in generated YAML; unit tests pass
- **Committed in:** `357ecef` (Task 2 commit)

**3. [Rule 3 - Blocking] Updated worktree bootstrap.php to prepend worktree src/tests paths**
- **Found during:** Task 2 (unit test execution)
- **Issue:** The git worktree shares a `vendor/` directory symlinked to the main repo. The Composer autoloader's PSR-4 map pointed to the main repo's `src/` and `tests/` directories, so `TenantInitCommand` (new in this worktree) was not found.
- **Fix:** Modified `tests/bootstrap.php` to call `$loader->addPsr4(..., ..., true)` (prepend=true) for both `Tenancy\Bundle\` and `Tenancy\Bundle\Tests\` namespaces, pointing to the worktree's directories.
- **Files modified:** `tests/bootstrap.php`
- **Verification:** All 6 unit tests and 3 integration tests pass
- **Committed in:** `357ecef` (Task 2 commit)

---

**Total deviations:** 3 auto-fixed (2 Rule 1 - Bug, 1 Rule 3 - Blocking)
**Impact on plan:** All auto-fixes were necessary for correctness. No scope creep. The interface_exists fix aligns with the documented bundle convention established in Phase 06.

## Issues Encountered

- Symfony compiled container cache (stored in `sys_get_temp_dir()/tenancy_command_test/cache/`) was stale from a previous run using the main repo's `config/services.php`. Integration test initially failed until cache was cleared. This is a worktree-specific concern: the `CommandTestKernel` uses a fixed cache path that doesn't incorporate the worktree identity.

## Known Stubs

None — the command generates real YAML content with no placeholder data.

## Threat Flags

No new threat surface introduced beyond what is documented in the plan's threat model (config file write to `projectDir`).

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- `tenancy:init` command is complete and ready for user testing
- Phase 12 (developer onboarding) is fully implemented
- Bundle is ready for Packagist release with a guided onboarding experience

---
*Phase: 12-developer-onboarding*
*Completed: 2026-04-13*

## Self-Check: PASSED

All created files and commits verified:
- FOUND: src/Command/TenantInitCommand.php
- FOUND: tests/Unit/Command/TenantInitCommandTest.php
- FOUND: tests/Integration/Command/TenantInitCommandIntegrationTest.php
- FOUND: .planning/phases/12-developer-onboarding-tenancy-init-scaffolding-command-that-c/12-01-SUMMARY.md
- FOUND commit: 2f0fed7 (Task 1)
- FOUND commit: 357ecef (Task 2)
- FOUND commit: 9be8e54 (Task 3)
