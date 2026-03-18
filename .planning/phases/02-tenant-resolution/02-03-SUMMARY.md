---
phase: 02-tenant-resolution
plan: 03
subsystem: api
tags: [symfony, http-foundation, tenant-resolution, header, query-param]

# Dependency graph
requires:
  - phase: 02-01
    provides: TenantResolverInterface, TenantProviderInterface, TenantNotFoundException, TenantInactiveException
provides:
  - HeaderResolver (X-Tenant-ID header-based tenant resolution, priority 20)
  - QueryParamResolver (_tenant query param tenant resolution, priority 10)
affects:
  - 02-05-resolver-chain-integration
  - 03-doctrine-tenant-provider

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Thin resolver pattern: extract slug from request, delegate to TenantProviderInterface, catch TenantNotFoundException (return null), let TenantInactiveException bubble (HTTP 403)"

key-files:
  created:
    - src/Resolver/HeaderResolver.php
    - src/Resolver/QueryParamResolver.php
    - tests/Unit/Resolver/HeaderResolverTest.php
    - tests/Unit/Resolver/QueryParamResolverTest.php
  modified:
    - config/services.php

key-decisions:
  - "HeaderResolver uses X-Tenant-ID header constant (HEADER_NAME) for discoverability; priority 20 (between HostResolver=30 and QueryParamResolver=10)"
  - "QueryParamResolver uses _tenant param constant (PARAM_NAME); priority 10 — lowest precedence, suitable for debug/preview use"
  - "Both resolvers follow identical error-handling contract: catch TenantNotFoundException (null return for chain), let TenantInactiveException propagate as HTTP 403"

patterns-established:
  - "Resolver priority ladder: HostResolver=30 > HeaderResolver=20 > QueryParamResolver=10 — host wins for SaaS, header for API clients, query param for debug"
  - "TDD cycle: write failing tests first, commit RED, then implement, commit GREEN"

requirements-completed: [RESV-02, RESV-03]

# Metrics
duration: 4min
completed: 2026-03-18
---

# Phase 02 Plan 03: HeaderResolver and QueryParamResolver Summary

**X-Tenant-ID header resolver (priority 20) and _tenant query param resolver (priority 10) — both delegate to TenantProviderInterface and catch TenantNotFoundException while letting TenantInactiveException bubble**

## Performance

- **Duration:** ~4 min
- **Started:** 2026-03-18T21:38:00Z
- **Completed:** 2026-03-18T21:41:20Z
- **Tasks:** 1 (TDD: 2 commits — RED + GREEN)
- **Files modified:** 5

## Accomplishments

- HeaderResolver extracts slug from X-Tenant-ID HTTP header; returns null on absent/empty header or TenantNotFoundException; propagates TenantInactiveException
- QueryParamResolver extracts slug from _tenant query parameter; same null/exception behaviour as HeaderResolver
- Both registered in config/services.php with priority tags (20 and 10 respectively), completing the priority ladder: Host=30 > Header=20 > QueryParam=10
- 10 new unit tests pass; full Resolver test suite: 27 tests, 71 assertions, 0 failures

## Task Commits

Each task committed atomically following TDD cycle:

1. **Task 1 RED: Failing tests** - `6eb9af2` (test)
2. **Task 1 GREEN: HeaderResolver, QueryParamResolver, services.php** - `e4d1d35` (feat)

## Files Created/Modified

- `src/Resolver/HeaderResolver.php` - Resolves tenant from X-Tenant-ID header; implements TenantResolverInterface; HEADER_NAME constant
- `src/Resolver/QueryParamResolver.php` - Resolves tenant from _tenant query param; implements TenantResolverInterface; PARAM_NAME constant
- `tests/Unit/Resolver/HeaderResolverTest.php` - 5 unit tests covering absent/empty/present header, not-found, and inactive-bubbles cases
- `tests/Unit/Resolver/QueryParamResolverTest.php` - 5 unit tests covering absent/empty/present param, not-found, and inactive-bubbles cases
- `config/services.php` - Added HeaderResolver (priority 20) and QueryParamResolver (priority 10) service registrations

## Decisions Made

- HeaderResolver constant named `HEADER_NAME = 'X-Tenant-ID'` for IDE discoverability and avoiding magic strings in callers
- QueryParamResolver constant named `PARAM_NAME = '_tenant'` with leading underscore to signal it is a framework-level param, not application data
- Priority 20 for header (below HostResolver=30) makes API clients second-choice; priority 10 for query param makes it debug/preview only

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- HeaderResolver and QueryParamResolver complete — all three resolvers (Host, Header, QueryParam) are now implemented and registered
- Phase 02-04 (or 02-05) can wire them into ResolverChain and verify priority ordering end-to-end
- No blockers

---
*Phase: 02-tenant-resolution*
*Completed: 2026-03-18*
