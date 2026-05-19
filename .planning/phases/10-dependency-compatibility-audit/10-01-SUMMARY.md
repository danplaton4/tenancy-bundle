---
phase: 10-dependency-compatibility-audit
plan: 01
subsystem: infra
tags: [composer, symfony, phpunit, phpstan, dependency-audit]

requires:
  - phase: 09-oss-hardening
    provides: composer.json package constraints and CI configuration
provides:
  - Symfony floor constraint raised to ^7.4||^8.0
  - AUDIT-REPORT.md documenting all dependency interactions
  - SYMFONY_DEPRECATIONS_HELPER enabled in PHPUnit
affects: [09-oss-hardening, ci]

tech-stack:
  added: []
  patterns: [symfony-constraint-floor-validation]

key-files:
  created:
    - .planning/phases/10-dependency-compatibility-audit/AUDIT-REPORT.md
  modified:
    - composer.json
    - phpunit.xml.dist

key-decisions:
  - "Symfony constraint floor ^7.4||^8.0 — NamespacedPoolInterface requires cache-contracts ^3.6 (Symfony 7.3+), 7.4 is LTS"
  - "DoctrineBundle keep ^2.13||^3.0 — Composer platform resolver handles PHP 8.4 branching"
  - "doctrine/migrations stay ^3.9 — no stable 4.0 release"
  - "SYMFONY_DEPRECATIONS_HELPER set to max[direct]=0"

patterns-established:
  - "Dependency audit pattern: scan all src/ for syntax/guard/deprecation issues before constraint changes"

requirements-completed: [D-01, D-02, D-03, D-04, D-05, D-06, D-08]

duration: 7min
completed: 2026-04-10
---

# Phase 10 Plan 01: Dependency Compatibility Audit & Constraint Fixes

**Raised all 11 Symfony constraints from ^7.0||^8.0 to ^7.4||^8.0, produced formal AUDIT-REPORT.md with guard/syntax/deprecation findings, enabled PHPUnit deprecation detection**

## Performance

- **Duration:** ~7 min
- **Tasks:** 2
- **Files modified:** 3

## Accomplishments
- All 11 Symfony package constraints (8 require + 3 require-dev) raised from ^7.0 to ^7.4, fixing NamespacedPoolInterface gap
- Formal AUDIT-REPORT.md created covering guard audit, PHP syntax scan, deprecation check, v1.1 dependency audit
- SYMFONY_DEPRECATIONS_HELPER enabled in phpunit.xml.dist for proactive deprecation detection
- Zero PHP 8.4-only syntax found in src/
- All class_exists/interface_exists guards verified complete

## Task Commits

1. **Task 1: Dependency audit and composer.json constraint fix** - `668fd6c` (feat)
2. **Task 2: PHPUnit deprecation detection** - `11bd5a1` (feat)

## Files Created/Modified
- `composer.json` — All Symfony constraints ^7.0||^8.0 → ^7.4||^8.0
- `phpunit.xml.dist` — Added SYMFONY_DEPRECATIONS_HELPER env var
- `.planning/phases/10-dependency-compatibility-audit/AUDIT-REPORT.md` — Formal audit report

## Decisions Made
- Symfony constraint floor ^7.4 chosen: NamespacedPoolInterface needs cache-contracts ^3.6, 7.0-7.3 all EOL
- DoctrineBundle ^2.13||^3.0 kept unchanged: Composer platform resolver handles PHP version branching
- doctrine/migrations stays ^3.9: no stable 4.0 exists
- SYMFONY_DEPRECATIONS_HELPER set to max[direct]=0 (strict on direct deps)

## Deviations from Plan
None - plan executed as written

## Issues Encountered
None

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Symfony constraints are now correct for the 7.4/8.0 matrix
- PHPUnit will catch deprecations going forward
- AUDIT-REPORT.md documents all findings for reference

---
*Phase: 10-dependency-compatibility-audit*
*Completed: 2026-04-10*