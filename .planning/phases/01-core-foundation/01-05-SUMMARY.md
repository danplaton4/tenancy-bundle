---
phase: 01-core-foundation
plan: 05
subsystem: http-lifecycle
tags: [symfony, kernel-events, event-listener, dependency-injection, integration-tests, autoconfiguration]

# Dependency graph
requires:
  - phase: 01-core-foundation plan 01
    provides: TenancyBundle, services.php, BootstrapperChainPass, config skeleton
  - phase: 01-core-foundation plan 02
    provides: TenantContext, BootstrapperChain, TenantBootstrapperInterface
  - phase: 01-core-foundation plan 03
    provides: TenantContextCleared event class

provides:
  - TenantContextOrchestrator kernel event listener (priority 20)
  - kernel.request listener with isMainRequest guard (Phase 1 no-op skeleton)
  - kernel.terminate listener that clears bootstrappers, context, and dispatches TenantContextCleared
  - Unit tests for orchestrator (6 tests)
  - Integration tests: container compilation, listener priority, autoconfiguration (11 tests)

affects:
  - phase-02-resolvers (TenantContextOrchestrator is the entry point Phase 2 fills in)
  - all future phases (integration test infrastructure: TestKernel reusable)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - AsEventListener attribute for kernel event wiring at explicit priority
    - SpyBootstrapper test pattern for testing against final classes (non-mockable)
    - TestKernel pattern for integration tests (FrameworkBundle + TenancyBundle, minimal config)
    - MakeBootstrapperChainPublicPass test compiler pass for exposing private services in integration tests
    - setUpBeforeClass/tearDownAfterClass for kernel lifecycle in integration tests (avoids risky warnings)

key-files:
  created:
    - src/EventListener/TenantContextOrchestrator.php
    - tests/Unit/EventListener/TenantContextOrchestratorTest.php
    - tests/Integration/TestKernel.php
    - tests/Integration/ContainerCompilationTest.php
    - tests/Integration/ListenerPriorityTest.php
    - tests/Integration/AutoconfigurationTest.php
  modified: []

key-decisions:
  - "SpyBootstrapper pattern used instead of mock — BootstrapperChain is final (cannot be mocked/extended)"
  - "MakeBootstrapperChainPublicPass compiler pass used to expose private tenancy.bootstrapper_chain for integration test inspection"
  - "setUpBeforeClass/tearDownAfterClass for kernel lifecycle avoids PHPUnit risky-test warnings from kernel error handler registration"
  - "TestKernel omits router config — framework.router requires resource which is not needed for these integration tests"

patterns-established:
  - "TestKernel: minimal Symfony kernel (FrameworkBundle + TenancyBundle, secret + test=true) for integration tests"
  - "SpyBootstrapper: concrete TenantBootstrapperInterface implementation that records clear() calls for testing against final BootstrapperChain"
  - "MakeBootstrapperChainPublicPass: dedicated test compiler pass to expose private bundle services in integration tests without modifying production code"

requirements-completed: [CORE-05, CORE-01, CORE-02, CORE-03]

# Metrics
duration: 6min
completed: 2026-03-18
---

# Phase 1 Plan 05: TenantContextOrchestrator and Integration Tests Summary

**HTTP lifecycle entry point wired at kernel.request priority 20 with full Phase 1 integration test coverage: container compilation, listener priority, and end-to-end autoconfiguration of TenantBootstrapperInterface via registerForAutoconfiguration**

## Performance

- **Duration:** 6 min
- **Started:** 2026-03-18T06:33:23Z
- **Completed:** 2026-03-18T06:39:00Z
- **Tasks:** 4
- **Files modified:** 6

## Accomplishments

- TenantContextOrchestrator created with PRIORITY=20, isMainRequest guard, and full kernel.terminate teardown (bootstrappers → context → TenantContextCleared)
- 6 unit tests covering priority constant, sub-request guard, Phase 1 no-op, no-tenant guard, full teardown, and teardown ordering
- Integration TestKernel infrastructure for all future integration tests
- 3 integration test files (11 tests total) proving container compiles without circular refs, listener wired at priority 20, and TenantBootstrapperInterface auto-tagging works end-to-end
- Full test suite: 45 tests, 96 assertions, all passing

## Task Commits

1. **Task 1: Create TenantContextOrchestrator** - `1e51559` (feat)
2. **Task 2: Unit tests for TenantContextOrchestrator** - `76941f0` (test)
3. **Task 3: ContainerCompilationTest + ListenerPriorityTest** - `36462fa` (test)
4. **Task 4: AutoconfigurationTest** - `234ff23` (test)

## Files Created/Modified

- `src/EventListener/TenantContextOrchestrator.php` — kernel.request/terminate listener, PRIORITY=20 constant, Phase 1 no-op skeleton
- `tests/Unit/EventListener/TenantContextOrchestratorTest.php` — 6 unit tests with SpyBootstrapper pattern
- `tests/Integration/TestKernel.php` — reusable minimal Symfony kernel for integration tests
- `tests/Integration/ContainerCompilationTest.php` — container compilation smoke tests
- `tests/Integration/ListenerPriorityTest.php` — listener priority verification
- `tests/Integration/AutoconfigurationTest.php` — TenantBootstrapperInterface autoconfiguration end-to-end

## Decisions Made

- SpyBootstrapper pattern: `BootstrapperChain` is `final` and cannot be mocked or extended. Used a concrete `SpyBootstrapper implements TenantBootstrapperInterface` that records `clear()` call count and allows an `onClear` callback for ordering assertions.
- `MakeBootstrapperChainPublicPass`: a dedicated test compiler pass that makes `tenancy.bootstrapper_chain` public after bundle compilation so integration tests can fetch it from the container. Avoids touching production code visibility.
- `setUpBeforeClass`/`tearDownAfterClass` pattern for kernel lifecycle: inline `try/finally { $kernel->shutdown() }` in test methods leaves PHP exception handlers registered (from FrameworkBundle's `HttpKernel::boot()`), triggering PHPUnit's "risky test" warning. Class-level boot/shutdown avoids this.
- TestKernel omits `framework.router` config — FrameworkBundle requires `router.resource` when router section is present, but HTTP routing is not needed for these integration tests.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] BootstrapperChain is final — cannot be mocked**
- **Found during:** Task 2 (unit tests)
- **Issue:** Plan specified `$this->createMock(BootstrapperChain::class)` but `BootstrapperChain` is declared `final` — PHPUnit cannot double final classes
- **Fix:** Created `SpyBootstrapper implements TenantBootstrapperInterface` — a concrete test double that tracks `clear()` calls. Added it to a real `BootstrapperChain` instance. Assertions changed from mock expectations on chain to count checks on spy.
- **Files modified:** `tests/Unit/EventListener/TenantContextOrchestratorTest.php`
- **Verification:** All 6 unit tests pass
- **Committed in:** `76941f0` (Task 2 commit)

**2. [Rule 3 - Blocking] TestKernel failed due to missing router.resource**
- **Found during:** Task 3 (integration tests)
- **Issue:** `framework.router` config block requires a `resource` key — `InvalidConfigurationException` on container compile
- **Fix:** Removed `router` section from TestKernel's framework config
- **Files modified:** `tests/Integration/TestKernel.php`
- **Verification:** Container compiles successfully, 8 integration tests pass
- **Committed in:** `36462fa` (Task 3 commit)

**3. [Rule 3 - Blocking] tenancy.bootstrapper_chain is private in compiled container**
- **Found during:** Task 4 (autoconfiguration tests)
- **Issue:** `$container->get('tenancy.bootstrapper_chain')` threw `ServiceNotFoundException` — private services are inlined at compile time
- **Fix:** Added `MakeBootstrapperChainPublicPass` test compiler pass that sets the definition public. Added `build()` override to `AutoconfigTestKernel` to register the pass.
- **Files modified:** `tests/Integration/AutoconfigurationTest.php`
- **Verification:** All 3 AutoconfigurationTest tests pass
- **Committed in:** `234ff23` (Task 4 commit)

**4. [Rule 1 - Bug] Kernel shutdown in try/finally caused risky test warnings**
- **Found during:** Task 4 (autoconfiguration tests)
- **Issue:** Booting Symfony kernel in test methods with `finally { $kernel->shutdown() }` left PHP error handlers registered, triggering PHPUnit's "Test code did not remove its own exception handlers" risky warning
- **Fix:** Switched to `setUpBeforeClass`/`tearDownAfterClass` for kernel lifecycle, boot once per test class
- **Files modified:** `tests/Integration/AutoconfigurationTest.php`
- **Verification:** Tests pass without risky warnings
- **Committed in:** `234ff23` (Task 4 commit)

---

**Total deviations:** 4 auto-fixed (2 Rule 1 bugs, 2 Rule 3 blocking)
**Impact on plan:** All auto-fixes were necessary for the tests to run correctly. No scope creep — test patterns established here (SpyBootstrapper, TestKernel, MakeXxxPublicPass) are reusable conventions for future phases.

## Issues Encountered

None beyond the auto-fixed deviations above.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Phase 1 complete: TenantInterface, TenantContext, BootstrapperChain, lifecycle events (TenantResolved, TenantBootstrapped, TenantContextCleared), Tenant entity, TenantContextOrchestrator, full test suite (45 tests)
- Phase 2 (Resolvers): plug ResolverChain into `TenantContextOrchestrator::onKernelRequest()` where the Phase 1 comment placeholder is
- Integration TestKernel is reusable for Phase 2+ integration tests

---
*Phase: 01-core-foundation*
*Completed: 2026-03-18*

## Self-Check: PASSED

- src/EventListener/TenantContextOrchestrator.php: FOUND
- tests/Unit/EventListener/TenantContextOrchestratorTest.php: FOUND
- tests/Integration/ContainerCompilationTest.php: FOUND
- tests/Integration/ListenerPriorityTest.php: FOUND
- tests/Integration/AutoconfigurationTest.php: FOUND
- .planning/phases/01-core-foundation/01-05-SUMMARY.md: FOUND
- Commit 1e51559: FOUND
- Commit 76941f0: FOUND
- Commit 36462fa: FOUND
- Commit 234ff23: FOUND
