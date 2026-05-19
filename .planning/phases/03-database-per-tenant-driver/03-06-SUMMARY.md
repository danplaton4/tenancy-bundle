---
phase: 03-database-per-tenant-driver
plan: "06"
subsystem: database
tags: [doctrine, symfony, entity-manager, prependExtension, multitenancy]

# Dependency graph
requires:
  - phase: 03-database-per-tenant-driver
    provides: "TenancyBundle with database.enabled config, dual-EM loadExtension wiring, TenantConnection DBAL driver"
provides:
  - "Conditional prependExtension() in TenancyBundle targeting landlord EM mappings when database.enabled=true"
  - "Backward-compatible orm.mappings path when database.enabled=false or absent"
  - "Unit tests proving both branches and empty-config default (3 tests, 44 assertions)"
affects:
  - phase-04-and-beyond
  - any-phase-that-reads-TenancyBundle

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "prependExtension reads getExtensionConfig('tenancy') to branch Doctrine config based on runtime bundle settings"
    - "Last config wins pattern: iterate all raw config arrays from getExtensionConfig, each assignment overwrites previous"

key-files:
  created:
    - tests/Unit/TenancyBundlePrependExtensionTest.php
  modified:
    - src/TenancyBundle.php

key-decisions:
  - "prependExtension reads getExtensionConfig('tenancy') (not the resolved config from loadExtension) because prependExtension runs before config is merged — raw arrays only"
  - "Last-wins iteration over getExtensionConfig result matches Symfony's merge behavior for multiple config sources"
  - "Backward compatibility preserved: false/absent database.enabled still writes to orm.mappings (same as before fix)"

patterns-established:
  - "Pattern: use getExtensionConfig('tenancy') in prependExtension to read raw config and branch Doctrine prepend target"

requirements-completed: [ISOL-01, ISOL-02]

# Metrics
duration: 3min
completed: 2026-03-19
---

# Phase 03 Plan 06: prependExtension Conditional Summary

**prependExtension() conditionally routes Tenant entity mapping to `doctrine.orm.entity_managers.landlord.mappings` when `database.enabled=true`, preserving single-EM backward compatibility otherwise**

## Performance

- **Duration:** ~3 min
- **Started:** 2026-03-19T06:42:59Z
- **Completed:** 2026-03-19T06:46:00Z
- **Tasks:** 1 (TDD)
- **Files modified:** 2

## Accomplishments

- Closed the single verification gap from Phase 03 verification report
- `prependExtension()` now reads raw `tenancy` config via `getExtensionConfig('tenancy')` and branches on `database.enabled`
- When `database.enabled=true`: Tenant entity mapped explicitly to landlord EM (`entity_managers.landlord.mappings`)
- When `database.enabled=false` or absent: keeps current `orm.mappings` path (backward-compatible, zero regressions)
- Three unit tests prove both branches and the empty-config default (44 assertions)
- Full unit suite: 108 tests, 297 assertions — all green

## Task Commits

Each task was committed atomically:

1. **Task 1 (RED): Failing tests for prependExtension branches** - `09a3279` (test)
2. **Task 1 (GREEN): Fix prependExtension conditional** - `05b5497` (feat)

_Note: TDD task has two commits — test (RED) then implementation (GREEN)_

## Files Created/Modified

- `src/TenancyBundle.php` — `prependExtension()` replaced with conditional logic reading `getExtensionConfig('tenancy')` and branching on `database.enabled`
- `tests/Unit/TenancyBundlePrependExtensionTest.php` — 3 test methods proving landlord-EM path, top-level path (disabled), and top-level path (no config)

## Decisions Made

- `getExtensionConfig('tenancy')` is the correct API in `prependExtension()` — config is NOT yet resolved at this stage; only raw arrays are available
- Last-wins iteration: iterating all config arrays and overwriting `$databaseEnabled` on each matching key mirrors Symfony's actual merge behavior
- Backward compatibility: the `false`/absent branch produces identical output to the pre-fix code — no regression for existing single-EM users

## Deviations from Plan

None - plan executed exactly as written. The TDD flow (RED then GREEN) followed the plan specification precisely.

## Issues Encountered

None. Tests went RED on first run as expected, then GREEN immediately after implementing the conditional.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Phase 03 verification gap is now fully closed — all 10 truths verified
- ISOL-01 and ISOL-02 are fully satisfied with no caveats
- Phase 03 (database-per-tenant-driver) is complete

---
*Phase: 03-database-per-tenant-driver*
*Completed: 2026-03-19*
