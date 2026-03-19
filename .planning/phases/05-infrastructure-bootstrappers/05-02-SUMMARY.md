---
phase: 05-infrastructure-bootstrappers
plan: 02
subsystem: cache
tags: [symfony-cache, cache-adapter, decorator, namespace-isolation, per-tenant]

# Dependency graph
requires:
  - phase: 01-core-foundation
    provides: TenantContext (hasTenant/getTenant/getSlug) used for namespace key
  - phase: 02-tenant-resolution
    provides: DI service registry (cache.app, tenancy.context) used in decorator wiring
provides:
  - TenantAwareCacheAdapter class implementing AdapterInterface + NamespacedPoolInterface
  - Per-tenant cache namespace isolation via Symfony withSubNamespace()
  - cache.app DI decorator — transparent to all cache consumers
affects: [06-session-bootstrapper, 07-queue-bootstrapper, integration tests referencing cache.app]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - Symfony DI service decorator pattern (->decorate('cache.app') with service('.inner'))
    - Live-read TenantContext in pool() — never cache the scoped pool across calls
    - withSubNamespace() on adapter itself clones with pre-scoped inner (not a tenant namespace)

key-files:
  created:
    - src/Cache/TenantAwareCacheAdapter.php
    - tests/Unit/Cache/TenantAwareCacheAdapterTest.php
  modified:
    - config/services.php

key-decisions:
  - "TenantAwareCacheAdapter.$inner is NOT readonly — withSubNamespace() must mutate it on the clone; final class + private visibility prevent external mutation"
  - "pool() reads TenantContext live on every cache operation — never cache withSubNamespace() result across calls to prevent stale tenant context"
  - "PHPUnit intersection mock (createMockForIntersectionOfInterfaces) required for AdapterInterface&NamespacedPoolInterface; withSubNamespace() static return type forces willReturnSelf() not a separate scoped mock"
  - "No-tenant fallback delegates to inner pool directly (no throw) — consistent with SharedDriver::clear() no-op precedent"

patterns-established:
  - "Decorator pattern: ->decorate('cache.app') with service('.inner') for transparent cache.app wrapping"
  - "PHPUnit 11 intersection mocks: use createMockForIntersectionOfInterfaces([A::class, B::class]) for intersection type properties"
  - "Static return type mocking: use willReturnSelf() when mocking interfaces with static return types"

requirements-completed: [BOOT-02]

# Metrics
duration: 3min
completed: 2026-03-19
---

# Phase 05 Plan 02: TenantAwareCacheAdapter Summary

**Per-tenant cache namespace isolation via Symfony withSubNamespace() decorator on cache.app — transparent to all consumers, live TenantContext read on every operation**

## Performance

- **Duration:** 3 min
- **Started:** 2026-03-19T18:51:26Z
- **Completed:** 2026-03-19T18:54:26Z
- **Tasks:** 2 (TDD: RED + GREEN + Task 2)
- **Files modified:** 3

## Accomplishments

- `TenantAwareCacheAdapter` implements `AdapterInterface & NamespacedPoolInterface` — all 9 CacheItemPoolInterface methods + `withSubNamespace()` delegate through `pool()` which reads `TenantContext` live
- No-tenant fallback uses inner pool directly without namespace scoping — no throw, graceful degradation
- Registered as `cache.app` Symfony DI decorator — any service injecting `cache.app` (including `DoctrineTenantProvider`) automatically gets tenant-scoped cache without any code changes
- 7 unit tests green, 136 total unit tests passing

## Task Commits

Each task was committed atomically:

1. **TDD RED — Failing tests for TenantAwareCacheAdapter** - `a08f23c` (test)
2. **TDD GREEN — TenantAwareCacheAdapter implementation** - `3a18739` (feat)
3. **Task 2: Register TenantAwareCacheAdapter as cache.app decorator in DI** - `4f7ca28` (feat)

_Note: TDD task has two commits (test RED then feat GREEN)_

## Files Created/Modified

- `src/Cache/TenantAwareCacheAdapter.php` - Final class implementing AdapterInterface + NamespacedPoolInterface; pool() reads TenantContext live; withSubNamespace() returns clone with pre-scoped inner
- `tests/Unit/Cache/TenantAwareCacheAdapterTest.php` - 7 unit tests covering tenant delegation, no-tenant fallback, clear, save, withSubNamespace clone, interface assertions
- `config/services.php` - Added TenantAwareCacheAdapter use statement + tenancy.cache_adapter service definition with ->decorate('cache.app')

## Decisions Made

- **$inner not readonly:** `withSubNamespace()` on the adapter itself needs to mutate `$inner` on the clone. `final class` + `private` visibility enforces encapsulation without `readonly`.
- **Live read in pool():** `pool()` is called on every cache operation to read `TenantContext` fresh. The result of `withSubNamespace()` is never cached to avoid stale tenant context.
- **willReturnSelf() for intersection mocks:** PHPUnit 11's `createMockForIntersectionOfInterfaces` generates mocks where `withSubNamespace(): static` enforces same-class return. Tests use `willReturnSelf()` — the mock acts as both the original and scoped pool — which correctly validates delegation path without needing a separately typed scoped mock.

## Deviations from Plan

None — plan executed exactly as written.

The only adaptation was updating tests to use `willReturnSelf()` instead of a separate `$scopedPool` mock, which is a PHPUnit 11 constraint (not a plan deviation): the plan's pseudocode used a separate scoped mock but the `static` return type on `withSubNamespace()` prevents mixing mock classes.

## Issues Encountered

None — implementation straightforward. PHPUnit 11 intersection type enforcement for `static` return type was resolved cleanly with `willReturnSelf()`.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Cache adapter complete and wired — transparent per-tenant namespace isolation active
- Plan 05-03 (if exists) can build on the established bootstrapper + decorator patterns
- Integration tests for cache isolation can be written if needed at phase verification time

---
*Phase: 05-infrastructure-bootstrappers*
*Completed: 2026-03-19*
