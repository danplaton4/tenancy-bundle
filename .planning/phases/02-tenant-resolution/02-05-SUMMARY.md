---
phase: 02-tenant-resolution
plan: 05
subsystem: event-listener, resolver, integration-tests
tags: [resolver-chain, tenant-context-orchestrator, integration-tests, di-wiring, autoconfiguration]
dependency_graph:
  requires: [02-01, 02-02, 02-03, 02-04]
  provides: [full-resolver-chain-wiring, integration-test-suite, phase-2-complete]
  affects: [TenantContextOrchestrator, ResolverChain, test-infrastructure]
tech_stack:
  added: []
  patterns: [PriorityTaggedServiceTrait, compiler-pass-for-test-isolation, null-object-pattern-for-test-doubles]
key_files:
  created:
    - tests/Integration/TenantResolutionIntegrationTest.php
    - tests/Integration/Support/NullTenantProvider.php
    - tests/Integration/Support/ReplaceTenancyProviderPass.php
  modified:
    - src/EventListener/TenantContextOrchestrator.php
    - tests/Unit/EventListener/TenantContextOrchestratorTest.php
    - tests/Integration/TestKernel.php
    - tests/Integration/AutoconfigurationTest.php
decisions:
  - "NullTenantProvider + ReplaceTenancyProviderPass extracted to tests/Integration/Support/ so compiler pass classes are PSR-4 autoloaded and available across multiple test files"
  - "StubResolver (real TenantResolverInterface implementation) used in unit tests instead of mocking ResolverChain (final class) — avoids PHPUnit limitation with final classes"
  - "MakeResolverChainPublicPass exposes both the tenancy.resolver_chain definition and its ResolverChain::class alias to allow $container->get(ResolverChain::class) in test assertions"
  - "TenantContextOrchestrator made public via MakeResolverChainPublicPass (same pass) to enable reflection-based injection verification"
metrics:
  duration_seconds: 414
  completed_date: "2026-03-18"
  tasks_completed: 2
  files_created: 3
  files_modified: 4
---

# Phase 02 Plan 05: Resolver Chain Wiring and Integration Tests Summary

**One-liner:** Wire ResolverChain into TenantContextOrchestrator.onKernelRequest, replacing Phase 1 stub, with comprehensive integration tests proving all four resolvers are correctly wired in the DI container.

## What Was Built

### Task 1: Wire ResolverChain into TenantContextOrchestrator

- `onKernelRequest()` now calls `$this->resolverChain->resolve($event->getRequest())`
- Sets `tenantContext->setTenant($result['tenant'])`
- Boots `bootstrapperChain->boot($result['tenant'])`
- Dispatches `new TenantResolved($result['tenant'], $event->getRequest(), $result['resolvedBy'])`
- Added missing `use Tenancy\Bundle\Event\TenantResolved;` import
- Phase 1 stub comment removed
- Unit tests updated: removed no-op Phase 1 test, added `StubResolver` test double, added 5 new tests covering full request flow and sub-request skipping

### Task 2: Integration Tests for Full Resolver Chain Wiring

- **`tests/Integration/Support/NullTenantProvider.php`** — Null object for TenantProviderInterface (no DB needed)
- **`tests/Integration/Support/ReplaceTenancyProviderPass.php`** — Compiler pass replacing DoctrineTenantProvider with NullTenantProvider in test kernels
- **`tests/Integration/TenantResolutionIntegrationTest.php`** — 9 integration tests:
  - `testResolverChainServiceExists` — ResolverChain is retrievable from container
  - `testResolverChainHasBuiltInResolvers` — HostResolver, HeaderResolver, QueryParamResolver in chain; ConsoleResolver NOT in chain
  - `testResolverChainHasThreeBuiltInResolvers` — exactly 3 HTTP resolvers
  - `testResolverChainResolverPriorityOrder` — HostResolver(30) > HeaderResolver(20) > QueryParamResolver(10)
  - `testCustomResolverIsAutoTagged` — DummyTenantResolver implementing TenantResolverInterface auto-tagged and injected
  - `testConsoleResolverIsRegisteredAsEventListener` — ConsoleResolver in event_dispatcher listeners
  - `testTenantContextOrchestratorHasResolverChainDependency` — ResolverChain injected via reflection check
  - `testBundleConfigResolversDefault` — tenancy.resolvers = ['host', 'header', 'query_param', 'console']
  - `testBundleConfigHostAppDomainDefault` — tenancy.host.app_domain = null

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] All existing integration tests were failing due to missing Doctrine EM dependency**

- **Found during:** Task 2 pre-check (running existing tests to ensure nothing breaks)
- **Issue:** `TestKernel` registered `TenancyBundle` which wires `DoctrineTenantProvider` via `services.php`. This service requires `doctrine.orm.default_entity_manager` and `cache.app`, neither available in the minimal test kernel. `ContainerCompilationTest`, `ListenerPriorityTest`, and `AutoconfigurationTest` all errored.
- **Fix:** Created `NullTenantProvider` (real TenantProviderInterface implementation) and `ReplaceTenancyProviderPass` (replaces DoctrineTenantProvider definition at compile time). Added pass to `TestKernel::build()` and updated `AutoconfigurationTest` kernels.
- **Files modified:** `TestKernel.php`, `AutoconfigurationTest.php`, new `Support/NullTenantProvider.php`, new `Support/ReplaceTenancyProviderPass.php`
- **Commits:** 7fa0ea2, eda39a2

**2. [Rule 1 - Bug] MakeResolverChainPublicPass needed to target definition ID not FQCN alias**

- **Found during:** Task 2 test run
- **Issue:** Initial `MakeResolverChainPublicPass` used `$container->findDefinition(ResolverChain::class)` but the DI optimizer had already inlined/removed the definition before the pass ran (or the alias was private). `$container->get(ResolverChain::class)` threw `ServiceNotFoundException`.
- **Fix:** Changed to `$container->getDefinition('tenancy.resolver_chain')->setPublic(true)` plus `$container->getAlias(ResolverChain::class)->setPublic(true)` to expose both the definition and the FQCN alias.
- **Files modified:** `TenantResolutionIntegrationTest.php`
- **Commit:** eda39a2

## Commits

| Task | Commit | Description |
|------|--------|-------------|
| Task 1 | 7fa0ea2 | Wire ResolverChain into TenantContextOrchestrator.onKernelRequest + unit tests |
| Task 2 | eda39a2 | Add integration tests for full resolver chain wiring |

## Test Results

**Full test suite:** 112 tests, 260 assertions, 0 failures, 0 errors

- Unit: 10 tests in TenantContextOrchestratorTest (all pass)
- Integration: 9 new tests in TenantResolutionIntegrationTest (all pass)
- All previously existing tests still pass (11 integration, 91 unit)

## Self-Check: PASSED

- `src/EventListener/TenantContextOrchestrator.php` — exists, contains `resolverChain->resolve`, `setTenant`, `boot`, `new TenantResolved`
- `tests/Integration/TenantResolutionIntegrationTest.php` — exists, 148+ lines
- `tests/Integration/Support/NullTenantProvider.php` — exists
- `tests/Integration/Support/ReplaceTenancyProviderPass.php` — exists
- Commits 7fa0ea2 and eda39a2 — confirmed in git log
- Full suite: 112 tests green
