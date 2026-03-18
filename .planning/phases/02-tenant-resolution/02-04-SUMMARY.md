---
phase: 02-tenant-resolution
plan: "04"
subsystem: resolver
tags: [console, event-listener, symfony-console, tenant-resolution, tdd]

# Dependency graph
requires:
  - phase: 02-01
    provides: TenantProviderInterface, TenantContext, BootstrapperChain, TenantResolved event
provides:
  - ConsoleResolver: CLI tenant resolution via --tenant option on any console command
  - ConsoleCommandEvent listener registered via #[AsEventListener]
affects:
  - 02-05-PLAN
  - phase-03-persistence
  - any phase that needs CLI tenant context

# Tech tracking
tech-stack:
  added: []
  patterns:
    - ConsoleCommandEvent listener adds --tenant to Application definition then rebinds input before reading option
    - ConsoleResolver orchestrates context directly (no TenantContextOrchestrator): setTenant + boot + dispatch TenantResolved
    - ConsoleResolver NOT tagged as tenancy.resolver — not part of HTTP resolver chain
    - TDD with real TenantContext + real BootstrapperChain + ConsoleSpyBootstrapper (final classes cannot be mocked)

key-files:
  created:
    - src/Resolver/ConsoleResolver.php
    - tests/Unit/Resolver/ConsoleResolverTest.php
  modified:
    - config/services.php

key-decisions:
  - "ConsoleResolver operates independently from the HTTP resolver chain — listens on ConsoleCommandEvent, directly orchestrates context (setTenant + boot + dispatch) without TenantContextOrchestrator"
  - "ConsoleResolver is NOT tagged with tenancy.resolver — it does not implement TenantResolverInterface (takes ConsoleCommandEvent, not Request)"
  - "TDD test uses real TenantContext and real BootstrapperChain with ConsoleSpyBootstrapper — both are final and cannot be mocked; spy pattern detects boot() calls"
  - "--tenant option must be added to Application definition and input rebound before getOption() call — without rebind, Symfony throws InvalidArgumentException"

patterns-established:
  - "ConsoleSpyBootstrapper pattern: implement TenantBootstrapperInterface with counters to detect boot() calls on final BootstrapperChain in tests"
  - "Application definition rebind pattern: addOption to appDefinition -> mergeApplicationDefinition() -> input->bind(command->getDefinition()) -> getOption()"

requirements-completed:
  - RESV-04

# Metrics
duration: 2min
completed: 2026-03-18
---

# Phase 2 Plan 04: ConsoleResolver Summary

**ConsoleResolver listens on ConsoleCommandEvent, adds --tenant to Application definition with input rebind, and orchestrates full tenant context (findBySlug + setTenant + boot + TenantResolved) for CLI commands**

## Performance

- **Duration:** 2 min
- **Started:** 2026-03-18T21:43:48Z
- **Completed:** 2026-03-18T21:45:56Z
- **Tasks:** 1 (TDD: test + feat commits)
- **Files modified:** 3

## Accomplishments

- ConsoleResolver listens on ConsoleEvents::COMMAND via `#[AsEventListener]`, reads `--tenant` option from any command
- Adds `--tenant` to Application definition with `mergeApplicationDefinition` + `input->bind` rebind pattern to avoid `InvalidArgumentException`
- Calls `findBySlug` -> `setTenant` -> `boot` -> dispatches `TenantResolved` with `request=null` and `resolvedBy=ConsoleResolver::class`
- Silent when `--tenant` is absent or empty string
- 5 unit tests with ConsoleSpyBootstrapper pattern for final BootstrapperChain

## Task Commits

Each task was committed atomically (TDD):

1. **RED: Failing tests** - `44aafd7` (test)
2. **GREEN: ConsoleResolver implementation** - `eb64053` (feat)

**Plan metadata:** (docs commit — see below)

_Note: TDD task had two commits: RED (failing tests) then GREEN (implementation)._

## Files Created/Modified

- `src/Resolver/ConsoleResolver.php` - Final event listener class for CLI tenant resolution
- `tests/Unit/Resolver/ConsoleResolverTest.php` - 5 unit tests using ConsoleSpyBootstrapper pattern
- `config/services.php` - Added ConsoleResolver service with autoconfigure

## Decisions Made

- **ConsoleResolver is independent from HTTP resolver chain**: No `TenantResolverInterface` implementation, not tagged with `tenancy.resolver`. Listens directly on `ConsoleCommandEvent` and orchestrates context itself.
- **TDD with spy pattern**: `TenantContext` and `BootstrapperChain` are both `final` classes — cannot be mocked. Used real instances with `ConsoleSpyBootstrapper` to detect `boot()` calls, following the pattern established in Phase 01-05.
- **Input rebind required**: `--tenant` option must be added to the `Application`-level definition, then `mergeApplicationDefinition()` and `input->bind()` called before `getOption('tenant')` — without this, Symfony throws because input was already bound before `ConsoleCommandEvent` fired.

## Deviations from Plan

None — plan executed exactly as written.

The TDD test structure was adjusted (compared to plan's initial suggestion using `ArrayInput(['--tenant' => 'acme'])`) because `TenantContext` and `BootstrapperChain` are `final` and cannot be mocked. Used the established spy pattern from Phase 01-05 tests instead. This is consistent with the project's testing conventions, not a deviation from the plan's intent.

## Issues Encountered

- `TenantContext` is declared `final` — initial test draft used `createMock(TenantContext::class)` which PHPUnit rejected. Fixed immediately by using real `TenantContext` instance with direct assertions, following existing `TenantContextOrchestratorTest` pattern.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- All four HTTP resolvers complete (HostResolver, HeaderResolver, QueryParamResolver) plus ConsoleResolver for CLI
- Phase 02 resolver subsystem fully implemented — ready for Plan 02-05 (integration tests or final phase plan)
- ConsoleResolver registered with `autoconfigure(true)` — Symfony event listener tag automatically applied via `#[AsEventListener]`

---
*Phase: 02-tenant-resolution*
*Completed: 2026-03-18*
