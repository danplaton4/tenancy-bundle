---
phase: 02-tenant-resolution
plan: 01
subsystem: resolver
tags: [resolver, chain-of-responsibility, doctrine, cache, symfony-cache, compiler-pass, http-exception]

# Dependency graph
requires:
  - phase: 01-core-foundation
    provides: TenantInterface, BootstrapperChain, TenancyBundle, services.php patterns, BootstrapperChainPass template

provides:
  - TenantResolverInterface contract (resolve(Request): ?TenantInterface)
  - ResolverChain (chain-of-responsibility, first-match-wins, throws TenantNotFoundException on exhaustion)
  - TenantProviderInterface contract (findBySlug(string): TenantInterface)
  - DoctrineTenantProvider (cached Doctrine lookup, 300s TTL, is_active check after cache retrieval)
  - TenantNotFoundException (HTTP 404 domain exception implementing HttpExceptionInterface)
  - TenantInactiveException (HTTP 403 domain exception implementing HttpExceptionInterface)
  - ResolverChainPass compiler pass (mirrors BootstrapperChainPass with tenancy.resolver tag)
  - TenancyBundle config nodes: resolvers list and host.app_domain scalar
  - DI services: tenancy.resolver_chain, tenancy.provider, TenantProviderInterface alias
  - TenantContextOrchestrator updated with ResolverChain constructor dependency

affects:
  - 02-02 (HostResolver uses TenantResolverInterface and TenantProviderInterface)
  - 02-03 (HeaderResolver uses TenantResolverInterface)
  - 02-04 (QueryParamResolver and ConsoleResolver use TenantResolverInterface)
  - 02-05 (Orchestrator wiring uses ResolverChain, TenantContextOrchestrator)

# Tech tracking
tech-stack:
  added:
    - symfony/cache ^6.4||^7.0 (added to composer.json require)
    - symfony/console ^6.4||^7.0 (added to composer.json require, needed for ConsoleResolver in Plan 04)
  patterns:
    - ResolverChain chain-of-responsibility: addResolver() appends, resolve() returns first-match array with tenant+resolvedBy
    - DoctrineTenantProvider cache-then-check: cache raw tenant objects (including inactive), check is_active AFTER cache retrieval
    - ResolverChainPass mirrors BootstrapperChainPass: same PriorityTaggedServiceTrait pattern with different tag/class/method

key-files:
  created:
    - src/Resolver/TenantResolverInterface.php
    - src/Resolver/ResolverChain.php
    - src/Provider/TenantProviderInterface.php
    - src/Provider/DoctrineTenantProvider.php
    - src/Exception/TenantNotFoundException.php
    - src/Exception/TenantInactiveException.php
    - src/DependencyInjection/Compiler/ResolverChainPass.php
    - tests/Unit/Resolver/ResolverChainTest.php
    - tests/Unit/Provider/DoctrineTenantProviderTest.php
    - tests/Unit/Exception/TenantNotFoundExceptionTest.php
    - tests/Unit/Exception/TenantInactiveExceptionTest.php
    - tests/Unit/DependencyInjection/Compiler/ResolverChainPassTest.php
  modified:
    - src/TenancyBundle.php
    - config/services.php
    - composer.json
    - src/EventListener/TenantContextOrchestrator.php
    - tests/Unit/EventListener/TenantContextOrchestratorTest.php

key-decisions:
  - "ResolverChain::resolve() returns array{tenant, resolvedBy} rather than a value object — simpler for callers, no extra class needed at this stage"
  - "DoctrineTenantProvider caches inactive tenants (cache-then-check pattern) to prevent DB hammering on repeated requests for disabled tenants (Research Pitfall 3)"
  - "TenantInactiveException constructor takes optional slug string rather than full message — produces 'Tenant X is inactive.' automatically"
  - "symfony/cache and symfony/console added to hard require (not suggest) as they are used directly by bundle classes"
  - "TenantContextOrchestrator updated to accept ResolverChain as 4th constructor arg — Phase 2 Plans 02-04 will implement resolvers, Plan 05 will wire them into onKernelRequest"

patterns-established:
  - "Resolver chain pattern: TenantResolverInterface -> ResolverChain (chain-of-responsibility) -> ResolverChainPass (compiler pass auto-wires tagged resolvers)"
  - "Cache-then-check pattern: DoctrineTenantProvider caches all entities, checks business rules after cache retrieval"
  - "HttpExceptionInterface on domain exceptions: TenantNotFoundException (404) and TenantInactiveException (403) implement HttpExceptionInterface directly"

requirements-completed:
  - RESV-05

# Metrics
duration: 12min
completed: 2026-03-18
---

# Phase 2 Plan 01: Resolver Foundation Summary

**Chain-of-responsibility resolver infrastructure with Doctrine+cache provider, HTTP domain exceptions (404/403), compiler pass, and full DI wiring**

## Performance

- **Duration:** 12 min
- **Started:** 2026-03-18T07:00:00Z
- **Completed:** 2026-03-18T07:12:00Z
- **Tasks:** 2 (TDD, 4 commits each)
- **Files modified:** 17

## Accomplishments

- Full resolver chain infrastructure: TenantResolverInterface contract, ResolverChain stops at first match and tracks resolvedBy FQCN, throws TenantNotFoundException when exhausted
- DoctrineTenantProvider with Symfony Cache integration (300s TTL), caches inactive tenants and checks is_active after retrieval (prevents DB hammering on disabled tenants)
- Domain exceptions TenantNotFoundException (404) and TenantInactiveException (403) implementing Symfony HttpExceptionInterface
- ResolverChainPass compiler pass mirrors BootstrapperChainPass pattern with PriorityTaggedServiceTrait and tenancy.resolver tag
- TenancyBundle updated: registerForAutoconfiguration for TenantResolverInterface, resolvers list config node, host.app_domain config node, parameters
- services.php: tenancy.resolver_chain service, tenancy.provider service (DoctrineTenantProvider), both with class aliases

## Task Commits

Each task was committed atomically using TDD (RED then GREEN):

1. **Task 1 RED: failing tests** - `57db9f2` (test)
2. **Task 1 GREEN: resolver contracts, chain, provider, exceptions** - `e481c25` (feat)
3. **Task 2 RED: failing tests for ResolverChainPass** - `343b514` (test)
4. **Task 2 GREEN: ResolverChainPass, TenancyBundle, services.php** - `8e59ad9` (feat)

## Files Created/Modified

- `src/Resolver/TenantResolverInterface.php` - Resolver contract: resolve(Request): ?TenantInterface
- `src/Resolver/ResolverChain.php` - Chain-of-responsibility: first-match-wins, returns array{tenant, resolvedBy}
- `src/Provider/TenantProviderInterface.php` - Provider contract: findBySlug(string): TenantInterface (throws on not-found/inactive)
- `src/Provider/DoctrineTenantProvider.php` - Doctrine + Symfony Cache provider with 300s TTL, is_active check after cache
- `src/Exception/TenantNotFoundException.php` - HTTP 404 domain exception implementing HttpExceptionInterface
- `src/Exception/TenantInactiveException.php` - HTTP 403 domain exception implementing HttpExceptionInterface
- `src/DependencyInjection/Compiler/ResolverChainPass.php` - Compiler pass collecting tenancy.resolver tagged services
- `src/TenancyBundle.php` - Added ResolverChainPass, TenantResolverInterface autoconfiguration, resolvers/host config nodes
- `config/services.php` - Added tenancy.resolver_chain, tenancy.provider, updated TenantContextOrchestrator args
- `composer.json` - Added symfony/cache and symfony/console to require
- `src/EventListener/TenantContextOrchestrator.php` - Added ResolverChain as 4th constructor parameter
- `tests/Unit/Resolver/ResolverChainTest.php` - 5 test cases covering chain behavior
- `tests/Unit/Provider/DoctrineTenantProviderTest.php` - 6 test cases covering cache, not-found, inactive, cache-then-check
- `tests/Unit/Exception/TenantNotFoundExceptionTest.php` - 7 test cases
- `tests/Unit/Exception/TenantInactiveExceptionTest.php` - 6 test cases
- `tests/Unit/DependencyInjection/Compiler/ResolverChainPassTest.php` - 3 test cases mirroring BootstrapperChainPassTest
- `tests/Unit/EventListener/TenantContextOrchestratorTest.php` - Updated to pass ResolverChain in setUp

## Decisions Made

- `ResolverChain::resolve()` returns `array{tenant: TenantInterface, resolvedBy: string}` rather than a value object — simpler for callers without extra DTO class at this stage
- `DoctrineTenantProvider` uses cache-then-check pattern: caches all tenants including inactive ones, then checks `is_active` after retrieval to prevent DB hammering on repeated requests for disabled tenants (Research Pitfall 3)
- `TenantInactiveException` constructor accepts optional slug string to generate "Tenant X is inactive." message automatically
- `symfony/cache` and `symfony/console` moved to hard `require` (not `suggest`) as they are directly used by bundle classes

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Updated TenantContextOrchestratorTest to pass ResolverChain constructor arg**
- **Found during:** Task 2 (after updating TenantContextOrchestrator to accept ResolverChain)
- **Issue:** Existing test at line 70-74 passed only 3 constructor args; after adding ResolverChain as 4th arg, 6 tests threw ArgumentCountError
- **Fix:** Added `use Tenancy\Bundle\Resolver\ResolverChain` import and passed `new ResolverChain()` as 4th arg in `setUp()`
- **Files modified:** tests/Unit/EventListener/TenantContextOrchestratorTest.php
- **Verification:** All 62 unit tests pass after fix
- **Committed in:** 8e59ad9 (Task 2 feat commit)

---

**Total deviations:** 1 auto-fixed (Rule 1 - Bug)
**Impact on plan:** Necessary correctness fix — adding ResolverChain to TenantContextOrchestrator constructor is part of the plan's spec, test update was the natural consequence.

## Issues Encountered

None — plan executed smoothly. All 62 unit tests green after completion.

## Next Phase Readiness

- Resolver chain foundation complete; Plans 02-04 can implement HostResolver, HeaderResolver, QueryParamResolver, ConsoleResolver using TenantResolverInterface
- DoctrineTenantProvider registered as tenancy.provider; individual resolvers will autowire it
- ResolverChainPass will auto-collect resolvers tagged tenancy.resolver (or via registerForAutoconfiguration)
- TenantContextOrchestrator already wired with ResolverChain dependency; Plan 05 will implement the actual resolution logic in onKernelRequest

---
*Phase: 02-tenant-resolution*
*Completed: 2026-03-18*

## Self-Check: PASSED

All created files confirmed present. All 4 task commits confirmed in git log. 62/62 unit tests green.
