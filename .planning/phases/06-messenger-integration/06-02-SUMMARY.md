---
phase: 06-messenger-integration
plan: 02
subsystem: messenger
tags: [symfony-messenger, dependency-injection, compiler-pass, integration-testing, middleware]

# Dependency graph
requires:
  - phase: 06-messenger-integration
    plan: 01
    provides: "TenantStamp, TenantSendingMiddleware, TenantWorkerMiddleware implementations"
  - phase: 01-core-foundation
    provides: "TenantContext, BootstrapperChain, TenantInterface, TenantProviderInterface"
provides:
  - "DI service definitions for tenancy.messenger.sending_middleware and tenancy.messenger.worker_middleware"
  - "MessengerMiddlewarePass compiler pass — auto-enrolls both middlewares into all Messenger buses"
  - "MessengerTestKernel — minimal FrameworkBundle + TenancyBundle test kernel for Messenger integration"
  - "5 integration tests: DI registration, stamp attachment, no-stamp, worker boot/teardown, two-message isolation"
affects:
  - "06-messenger-integration: phase complete"
  - "Any future test kernels needing Messenger: use MessengerTestKernel pattern"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "MessengerMiddlewarePass: modifies {busId}.middleware parameter before MessengerPass consumes it (priority 1)"
    - "interface_exists() guard for Messenger — MessageBusInterface is an interface, class_exists() returns false"
    - "StackMiddleware(MiddlewareInterface) directly (not array) for direct middleware testing"
    - "StubTenantProvider populated in setUpBeforeClass for deterministic worker middleware tests"
    - "ReplaceProviderWithStubPass + NoOpBootstrapper for no-Doctrine test kernel compilation"

key-files:
  created:
    - "src/DependencyInjection/Compiler/MessengerMiddlewarePass.php"
    - "tests/Integration/Messenger/MessengerTestKernel.php"
    - "tests/Integration/Messenger/MessengerMiddlewareIntegrationTest.php"
    - "tests/Integration/Messenger/Support/StubTenant.php"
    - "tests/Integration/Messenger/Support/StubTenantProvider.php"
    - "tests/Integration/Messenger/Support/MakeMessengerServicesPublicPass.php"
    - "tests/Integration/Messenger/Support/ReplaceProviderWithStubPass.php"
    - "tests/Integration/Messenger/Support/NoOpBootstrapper.php"
  modified:
    - "config/services.php — added tenancy.messenger.sending_middleware and worker_middleware with interface_exists guard"
    - "src/TenancyBundle.php — added MessengerMiddlewarePass registration in build() at priority 1"
    - "phpunit.xml.dist — added messenger testsuite"

key-decisions:
  - "MessengerMiddlewarePass compiler pass (not prependExtensionConfig) for bus enrollment: messenger.buses.*.middleware uses performNoDeepMerging() so prepended config is overwritten by explicit user config; modifying the {busId}.middleware parameter directly before MessengerPass consumes it is the only reliable approach"
  - "interface_exists() instead of class_exists() for MessageBusInterface: it is an interface, class_exists() always returns false for interfaces causing the guard to silently skip all Messenger wiring"
  - "MessengerMiddlewarePass registered at priority 1 (TYPE_BEFORE_OPTIMIZATION) to guarantee it runs before MessengerPass (priority 0) which consumes the parameter"
  - "StackMiddleware(MiddlewareInterface) not StackMiddleware([MiddlewareInterface]) for direct testing: when array is passed, StackMiddleware creates a generator and the first next() call advances past index 0 leaving the inner handler never called"
  - "NoOpBootstrapper replaces DoctrineBootstrapper (not removes it) in test kernel: removal causes ServiceNotFoundException when BootstrapperChainPass has already added method call references to the service"

patterns-established:
  - "MessengerMiddlewarePass pattern: register at priority 1, check hasParameter() for {busId}.middleware, prepend middleware array; for defensive fallback also check IteratorArgument if parameter already consumed"
  - "Messenger test kernel: FrameworkBundle + TenancyBundle only, ReplaceProviderWithStubPass + NoOpBootstrapper for no-Doctrine compilation, StubTenantProvider populated in setUpBeforeClass"

requirements-completed:
  - MSG-01
  - MSG-02
  - MSG-03

# Metrics
duration: 20min
completed: 2026-03-19
---

# Phase 06 Plan 02: Messenger Middleware DI Wiring and Integration Tests Summary

**Messenger middleware auto-enrolled in all Symfony buses via MessengerMiddlewarePass compiler pass, with 5 integration tests proving DI registration, stamp attachment, and context boot/teardown through a real kernel**

## Performance

- **Duration:** 20 min
- **Started:** 2026-03-19T20:42:55Z
- **Completed:** 2026-03-19T21:03:11Z
- **Tasks:** 2
- **Files modified:** 11 (3 src, 8 test)

## Accomplishments
- Both middleware services registered in DI with correct constructor arguments and `interface_exists` guard
- `MessengerMiddlewarePass` compiler pass auto-enrolls both middlewares into ALL configured buses by modifying the `{busId}.middleware` container parameter before `MessengerPass` processes it
- 5 end-to-end integration tests covering: DI registration, stamp attachment, no-stamp, worker boot/teardown, two-message isolation
- `MessengerTestKernel` establishes the pattern for no-Doctrine Messenger test kernels

## Task Commits

Each task was committed atomically:

1. **Task 1: DI wiring — register middleware services and auto-enroll in buses via prependExtension** - `02f7663` (feat)
2. **Task 2: Integration tests — MessengerTestKernel and end-to-end middleware verification** - `435e7e5` (feat)

## Files Created/Modified
- `src/DependencyInjection/Compiler/MessengerMiddlewarePass.php` — Compiler pass (priority 1) that prepends tenancy middleware to all bus middleware stacks
- `config/services.php` — Adds `tenancy.messenger.sending_middleware` and `tenancy.messenger.worker_middleware` with `interface_exists` guard
- `src/TenancyBundle.php` — Registers `MessengerMiddlewarePass` in `build()` with priority 1; adds `PassConfig` import
- `phpunit.xml.dist` — Adds `messenger` testsuite pointing to `tests/Integration/Messenger/`
- `tests/Integration/Messenger/MessengerTestKernel.php` — Minimal FrameworkBundle + TenancyBundle kernel with Messenger configured
- `tests/Integration/Messenger/MessengerMiddlewareIntegrationTest.php` — 5 integration tests
- `tests/Integration/Messenger/Support/StubTenant.php` — Stub TenantInterface implementation
- `tests/Integration/Messenger/Support/StubTenantProvider.php` — Configurable test provider
- `tests/Integration/Messenger/Support/MakeMessengerServicesPublicPass.php` — Makes Messenger services public for test inspection
- `tests/Integration/Messenger/Support/ReplaceProviderWithStubPass.php` — Replaces DoctrineTenantProvider + DoctrineBootstrapper for no-Doctrine compilation
- `tests/Integration/Messenger/Support/NoOpBootstrapper.php` — No-op bootstrapper replacing DoctrineBootstrapper

## Decisions Made
- Used `MessengerMiddlewarePass` compiler pass instead of `prependExtensionConfig`: the middleware array config uses `performNoDeepMerging()` which means prepended middleware config gets overwritten by explicit user bus config. Direct parameter modification is the correct approach.
- Used `interface_exists()` instead of `class_exists()`: `MessageBusInterface` is an interface, and `class_exists()` returns `false` for interfaces. This was causing the entire Messenger wiring to silently skip.
- Registered compiler pass at priority 1 to guarantee it runs before `MessengerPass` (priority 0) which consumes the `{busId}.middleware` parameter.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Used interface_exists() instead of class_exists() for MessageBusInterface guard**
- **Found during:** Task 2 (integration test debugging)
- **Issue:** `class_exists(\Symfony\Component\Messenger\MessageBusInterface::class)` returns `false` because `MessageBusInterface` is an interface. This caused the guard in `services.php` and `TenancyBundle::build()` to silently skip ALL Messenger wiring, preventing middleware registration.
- **Fix:** Replaced `class_exists()` with `interface_exists()` in all three locations
- **Files modified:** `config/services.php`, `src/TenancyBundle.php`, `src/DependencyInjection/Compiler/MessengerMiddlewarePass.php`
- **Committed in:** `435e7e5` (Task 2 commit)

**2. [Rule 1 - Bug] Replaced prependExtensionConfig approach with MessengerMiddlewarePass compiler pass**
- **Found during:** Task 2 (integration test debugging — bus middleware list didn't include tenancy middleware)
- **Issue:** `prependExtensionConfig` cannot reliably inject middleware because `framework.messenger.buses.*.middleware` uses `performNoDeepMerging()` in the Symfony Config tree. Explicit user bus configuration (like `default_middleware: allow_no_handlers`) resets the middleware array, overwriting any prepended config.
- **Fix:** Created `MessengerMiddlewarePass` compiler pass (priority 1) that prepends tenancy middleware to the `{busId}.middleware` container parameter before `MessengerPass` processes it. This approach is immune to config merging.
- **Files modified:** `src/DependencyInjection/Compiler/MessengerMiddlewarePass.php` (created), `src/TenancyBundle.php`
- **Committed in:** `435e7e5` (Task 2 commit)

**3. [Rule 1 - Bug] Fixed StackMiddleware construction in integration tests**
- **Found during:** Task 2 (testWorkerMiddlewareBootsAndTearsDownContext and testTwoSequentialMessagesIsolateContext failing)
- **Issue:** `new StackMiddleware([$innerMiddleware])` creates an iterator. When `StackMiddleware::next()` is called, it calls `$iterator->next()` which advances PAST the first element (PHP generators start at index 0, but `next()` advances to index 1). The inner middleware was never invoked.
- **Fix:** Use `new StackMiddleware($innerMiddleware)` (passing `MiddlewareInterface` directly, not array). This stores the middleware in `$stack[0]` directly, making the first `next()` call return it correctly.
- **Files modified:** `tests/Integration/Messenger/MessengerMiddlewareIntegrationTest.php`
- **Committed in:** `435e7e5` (Task 2 commit)

---

**Total deviations:** 3 auto-fixed (3 Rule 1 bugs)
**Impact on plan:** All auto-fixes necessary for correctness. The compiler pass approach is architecturally superior to prependExtension for this use case. No scope creep.

## Issues Encountered
- Pre-existing test failure: `EntityManagerResetIntegrationTest::testResetManagerClearsIdentityMap` fails before and after our changes — out of scope per SCOPE BOUNDARY rules.

## Next Phase Readiness
- Phase 06 Messenger integration is complete
- Both middleware services are zero-config: auto-enrolled in all buses via compiler pass
- Integration tests prove the full dispatch-consume cycle works with a real kernel
- No blockers for subsequent phases

---
*Phase: 06-messenger-integration*
*Completed: 2026-03-19*

## Self-Check: PASSED

All files verified present. All task commits verified in git log.
