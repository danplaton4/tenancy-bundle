---
phase: 01-core-foundation
plan: "04"
subsystem: database
tags: [doctrine, orm, entity, tenant, slug-pk, lifecycle-callbacks, phpunit]

# Dependency graph
requires:
  - phase: 01-core-foundation plan 02
    provides: TenantInterface contract (getSlug, getDomain, getConnectionConfig, getName, isActive)
  - phase: 01-core-foundation plan 01
    provides: TenancyBundle prependExtension registers Entity/ directory mapping for Doctrine
provides:
  - Tenant Doctrine entity with slug as string primary key (no auto-increment id)
  - All 7 entity fields with correct Doctrine attribute types
  - TenantInterface fully implemented on the concrete entity class
  - Lifecycle callbacks for createdAt/updatedAt timestamp management
  - 9 unit tests covering interface compliance, slug PK, field defaults, setters, and callbacks
affects: [phase-02-resolvers, phase-03-drivers, phase-04-drivers, phase-05-bootstrappers]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - Doctrine PHP 8 attribute mapping (#[ORM\Entity], #[ORM\Table], #[ORM\Column], #[ORM\Id], #[ORM\HasLifecycleCallbacks])
    - Slug as natural string primary key — no #[ORM\GeneratedValue], no auto-increment
    - Lifecycle callbacks via #[ORM\PrePersist] / #[ORM\PreUpdate] for timestamp automation
    - Fluent setters returning self for builder-style mutations
    - Reflection-based structural unit tests (ReflectionClass to verify ORM attribute presence without DB)

key-files:
  created:
    - src/Entity/Tenant.php
    - tests/Unit/Entity/TenantTest.php
  modified: []

key-decisions:
  - "Tenant slug is the natural string PK — no separate auto-increment id column; no #[ORM\GeneratedValue] anywhere on the entity"
  - "Lifecycle callbacks (onPrePersist / onPreUpdate) set timestamps — constructor does not set them"
  - "Unit tests use ReflectionClass to verify ORM attribute presence without requiring a DB connection — DB round-trip persistence deferred to Phase 3"

patterns-established:
  - "Pattern: Slug-as-PK — use #[ORM\Id] + #[ORM\Column(type: 'string')] without #[ORM\GeneratedValue] for natural string identifiers"
  - "Pattern: Structural entity tests — use ReflectionClass + getAttributes() to verify Doctrine mapping without persistence infrastructure"

requirements-completed: [CORE-04]

# Metrics
duration: 1min
completed: 2026-03-18
---

# Phase 1 Plan 04: Tenant Entity Summary

**Doctrine Tenant entity with slug string PK, 7 mapped fields, TenantInterface implementation, lifecycle timestamp callbacks, and 9 structural unit tests**

## Performance

- **Duration:** 1 min
- **Started:** 2026-03-18T06:29:02Z
- **Completed:** 2026-03-18T06:30:05Z
- **Tasks:** 2
- **Files modified:** 2

## Accomplishments

- Tenant entity with `slug` as `#[ORM\Id]` string primary key — no `#[ORM\GeneratedValue]`, no auto-increment id
- All 7 fields mapped with correct Doctrine attribute types: slug (string/63), domain (string/253 nullable), connectionConfig (json), name (string/255), isActive (boolean), createdAt/updatedAt (datetime_immutable)
- TenantInterface fully satisfied: 5 getter methods plus 4 fluent setters
- Lifecycle callbacks `onPrePersist` and `onPreUpdate` automate timestamp creation
- 9 unit tests pass covering: interface compliance, constructor, slug PK reflection, all field defaults, fluent setter contract, both lifecycle callbacks

## Task Commits

Each task was committed atomically:

1. **Task 1: Create Tenant entity with Doctrine attribute mapping** - `7404147` (feat)
2. **Task 2: Create unit tests for Tenant entity** - `b707119` (test)

## Files Created/Modified

- `src/Entity/Tenant.php` - Doctrine entity with slug PK, 7 fields, TenantInterface implementation, lifecycle callbacks, fluent setters
- `tests/Unit/Entity/TenantTest.php` - 9 structural unit tests using ReflectionClass for ORM attribute verification

## Decisions Made

- Lifecycle callbacks (onPrePersist / onPreUpdate) set timestamps — constructor intentionally does not set them, keeping construction lightweight and consistent with Doctrine conventions
- Unit tests use ReflectionClass to verify `#[ORM\Id]` on slug and absence of `#[ORM\GeneratedValue]` without requiring a DB connection — DB round-trip persistence deferred to Phase 3

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Tenant entity is the central record every Phase 2 resolver queries and every Phase 3/4 driver reads from
- `src/Entity/Tenant.php` is available for Doctrine mapping via the `prependExtension()` registration in `TenancyBundle` (Plan 01)
- DB round-trip persistence testing deferred to Phase 3 when dual EntityManager configuration is available

## Self-Check: PASSED

- src/Entity/Tenant.php: FOUND
- tests/Unit/Entity/TenantTest.php: FOUND
- .planning/phases/01-core-foundation/01-04-SUMMARY.md: FOUND
- Commit 7404147 (entity): FOUND
- Commit b707119 (tests): FOUND

---
*Phase: 01-core-foundation*
*Completed: 2026-03-18*
