---
phase: 02-tenant-resolution
plan: "02"
subsystem: resolver
tags: [symfony, host-resolver, subdomain, tenant-resolution, phpunit, tdd]

# Dependency graph
requires:
  - phase: 02-01
    provides: TenantResolverInterface, ResolverChain, TenantProviderInterface, TenantNotFoundException, TenantInactiveException, services.php wiring skeleton

provides:
  - HostResolver class implementing subdomain-based tenant identification
  - Comprehensive unit tests for all subdomain extraction edge cases

affects:
  - 02-03 (HeaderResolver reuses same TDD and provider patterns)
  - 02-04 (QueryParamResolver same pattern)
  - 02-05 (ConsoleResolver same provider injection pattern)
  - 02-06 (integration tests wire HostResolver into full chain)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "HostResolver: null app_domain = skip entirely (not an error)"
    - "www. stripping before subdomain extraction"
    - "Last segment before app_domain suffix is the tenant slug (handles api.acme.app.com -> acme)"
    - "TenantNotFoundException caught at resolver level (returns null to chain); TenantInactiveException bubbles"

key-files:
  created:
    - src/Resolver/HostResolver.php
    - tests/Unit/Resolver/HostResolverTest.php
  modified:
    - config/services.php

key-decisions:
  - "Last subdomain segment before app_domain suffix is the slug: api.acme.app.com -> 'acme' (not 'api')"
  - "HostResolver catches TenantNotFoundException and returns null; TenantInactiveException is NOT caught — bubbles as HTTP 403"
  - "HostResolver registered in services.php with priority 30 — runs first among built-in resolvers"

patterns-established:
  - "Resolver TDD pattern: failing tests first -> HostResolver class -> all 11 tests green in one pass"

requirements-completed:
  - RESV-01

# Metrics
duration: 2min
completed: "2026-03-18"
---

# Phase 02 Plan 02: HostResolver Summary

**HostResolver with subdomain extraction: strips www prefix, handles multi-segment subdomains (api.acme.app.com -> acme), catches TenantNotFoundException, bubbles TenantInactiveException**

## Performance

- **Duration:** ~2 min
- **Started:** 2026-03-18T21:35:48Z
- **Completed:** 2026-03-18T21:37:20Z
- **Tasks:** 1 (TDD: 2 commits — test RED + feat GREEN)
- **Files modified:** 3

## Accomplishments
- HostResolver implementing TenantResolverInterface with full subdomain extraction algorithm
- 11 unit tests covering all behavioral edge cases specified in the plan
- Full unit test suite green (73 tests, 159 assertions) — no regressions

## Task Commits

Each task was committed atomically:

1. **Task 1 RED: Add failing HostResolver tests** - `4dcb982` (test)
2. **Task 1 GREEN: Implement HostResolver** - `e910dce` (feat)

_Note: TDD task — test commit (RED) then implementation commit (GREEN)_

## Files Created/Modified
- `src/Resolver/HostResolver.php` — Final class implementing TenantResolverInterface; subdomain extraction with www-stripping and multi-segment handling
- `tests/Unit/Resolver/HostResolverTest.php` — 11 unit tests covering all edge cases
- `config/services.php` — Added HostResolver service definition with provider injection, app_domain param, and priority 30 tag

## Decisions Made
- Last subdomain segment before `app_domain` suffix is the slug: `api.acme.app.com` with `app.com` resolves to `acme` (the segment immediately before the suffix, not the leftmost `api`)
- HostResolver catches `TenantNotFoundException` and returns null (lets chain continue), but does NOT catch `TenantInactiveException` — it bubbles up as HTTP 403
- Service registered with `priority: 30` — runs first among built-in resolvers (higher = earlier per PriorityTaggedServiceTrait)

## Deviations from Plan

None — plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness
- HostResolver complete and tested — covers RESV-01
- Same TDD + provider injection pattern ready for HeaderResolver (Plan 02-03), QueryParamResolver (Plan 02-04), and ConsoleResolver (Plan 02-05)
- No blockers

## Self-Check: PASSED

All created files present. All task commits verified.

---
*Phase: 02-tenant-resolution*
*Completed: 2026-03-18*
