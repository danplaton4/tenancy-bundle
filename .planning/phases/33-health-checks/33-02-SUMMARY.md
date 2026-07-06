---
phase: 33-health-checks
plan: 02
subsystem: health
tags: [health-checks, probe-engine, bootstrapper-chain, sqlite-integration, tdd, security]
dependency_graph:
  requires:
    - src/Health/HealthCheckBootstrapperInterface.php (plan 33-01)
    - src/Health/BootstrapperHealthResult.php (plan 33-01)
    - src/Health/TenantHealthReport.php (plan 33-01)
    - src/Health/HealthResponseSanitizer.php (plan 33-01)
    - src/Context/TenantContext.php (existing)
    - src/Bootstrapper/BootstrapperChain.php (existing, additive only)
  provides:
    - src/Health/TenantHealthChecker.php
    - src/Bootstrapper/BootstrapperChain.php (healthCheck() method added)
    - src/Bootstrapper/DatabaseSwitchBootstrapper.php (check() method added)
    - src/Driver/SharedDriver.php (check() method added)
    - config/services.php (tenancy.health.checker + tenancy.health.sanitizer wired)
  affects:
    - plans 33-03, 33-04 (HTTP controller + CLI command depend on TenantHealthChecker)
    - plans 33-05 (wiring/liip pass uses tenancy.health.checker service ID)
tech_stack:
  added: []
  patterns:
    - set->probe->clear-in-finally invariant (TenantHealthChecker::checkOne)
    - additive method on final class (BootstrapperChain::healthCheck)
    - sibling interface opt-in (HealthCheckBootstrapperInterface alongside TenantDriverInterface)
    - close()+lazy-reconnect SELECT 1 DB probe (DatabaseSwitchBootstrapper::check)
    - real TenantContext in unit tests (final class, cannot mock)
    - DoctrineTestKernel extension for integration test kernel
key_files:
  created:
    - src/Health/TenantHealthChecker.php
    - tests/Unit/Health/TenantHealthCheckerTest.php
    - tests/Unit/Bootstrapper/BootstrapperChainHealthCheckTest.php
    - tests/Integration/Health/TenantHealthCheckerProbeTest.php
    - tests/Integration/Support/MakeHealthServicesPublicPass.php
  modified:
    - src/Bootstrapper/BootstrapperChain.php (healthCheck() added; boot()/clear() untouched)
    - src/Bootstrapper/DatabaseSwitchBootstrapper.php (check() + HealthCheckBootstrapperInterface)
    - src/Driver/SharedDriver.php (check() + HealthCheckBootstrapperInterface)
    - config/services.php (tenancy.health.checker + tenancy.health.sanitizer)
decisions:
  - "TenantHealthChecker takes BootstrapperChain by concrete type (final class) — unit tests use real BootstrapperChain with spy bootstrappers instead of mocks"
  - "DoctrineHealthTestKernel extends DoctrineTestKernel inline in the test file (not a separate file) to add MakeHealthServicesPublicPass — avoids introducing a new standalone kernel file"
  - "BootstrapperChain::healthCheck() catches per-probe throws into fromException entries (T-33-PROP mitigation) — chain-level throw invariant proven at integration level, not unit"
  - "Integration test uses two distinct SQLite temp files (pathA, pathB) with pre-created schemas to prove data isolation after probe"
metrics:
  duration: "~10 minutes"
  completed: "2026-07-06T07:13:32Z"
  tasks_completed: 3
  files_created: 5
  files_modified: 4
  tests_added: 14
---

# Phase 33 Plan 02: Probe Engine Summary

Core probe engine delivering the set->probe->clear-in-finally invariant: TenantHealthChecker orchestrates health probes, BootstrapperChain gains an additive event-free healthCheck() method, both isolation drivers expose live SELECT 1 connectivity probes, and a two-tenant SQLite integration test proves hasTenant() is always false after any probe (success or failure) with no residual global connection state.

## Tasks Completed

| Task | Name | Commit | Files Created/Modified |
|------|------|--------|----------------------|
| 1 | add check() to both isolation drivers + additive BootstrapperChain::healthCheck() | a7309c3 | BootstrapperChain.php, DatabaseSwitchBootstrapper.php, SharedDriver.php, BootstrapperChainHealthCheckTest.php |
| 2 | TenantHealthChecker (set->probe->clear-in-finally) + service wiring | 1af95e7 | TenantHealthChecker.php, config/services.php, TenantHealthCheckerTest.php |
| 3 | Probe-safety integration test (two SQLite tenants) — Wave 0 gap | 7a39a65 | TenantHealthCheckerProbeTest.php, MakeHealthServicesPublicPass.php |

## Verification Results

- `vendor/bin/phpunit --filter BootstrapperChainHealthCheckTest` — 5 tests, 17 assertions, 0 failures
- `vendor/bin/phpunit --filter TenantHealthCheckerTest` — 6 tests, 13 assertions, 0 failures
- `vendor/bin/phpunit --filter TenantHealthCheckerProbeTest` — 3 tests, 11 assertions, 0 failures
- `vendor/bin/phpunit` full suite — 909 tests, 3629 assertions, 2 skipped, 0 failures
- `vendor/bin/phpstan analyse --memory-limit=512M` — [OK] No errors (level 9)
- `vendor/bin/php-cs-fixer check --diff` — clean (@Symfony ruleset)
- `grep -L "EntityManager" src/Health/TenantHealthChecker.php` — file listed (no EntityManager import)
- `git diff a7309c3^1 a7309c3 -- src/Bootstrapper/BootstrapperChain.php` — only healthCheck() added, boot()/clear() bodies byte-identical

## Deviations from Plan

**1. [Rule 1 - Bug] BootstrapperChain is final — cannot mock for TenantHealthCheckerTest**

- **Found during:** Task 2 (RED phase: PHPUnit threw ClassIsFinalException)
- **Issue:** TenantHealthChecker takes BootstrapperChain by concrete type (not an interface); PHPUnit cannot create a mock for a final class
- **Fix:** Used a real BootstrapperChain with anonymous spy bootstrappers implementing both TenantBootstrapperInterface and HealthCheckBootstrapperInterface to assert boot() receives 0 calls and check() receives the correct call count
- **Files modified:** tests/Unit/Health/TenantHealthCheckerTest.php
- **Impact:** Tests cover the same behavioral invariants; the spy approach is actually more robust than mocking because it tests through a real chain instance

**2. [Rule 3 - Blocking] testReconnectsCleanlyAfterProbe fetched BootstrapperChain from container (ServiceNotFoundException)**

- **Found during:** Task 3 first run
- **Issue:** BootstrapperChain was retrieved via `$container->get(BootstrapperChain::class)` but was not made public; the service was inlined/removed at compile
- **Fix:** Removed the unnecessary container lookup; the test directly calls `$ctx->setTenant()` + `$conn->close()` to simulate the boot() path (same mechanism), which is cleaner
- **Files modified:** tests/Integration/Health/TenantHealthCheckerProbeTest.php

## Must-Have Verification

All five truths from the plan's `must_haves`:

| Truth | Status |
|-------|--------|
| TenantHealthChecker sets TenantContext manually, runs probes, and clears in a finally block — boot() is NEVER called | PROVEN (unit test: bootCallCount === 0; source: no ->boot( call in TenantHealthChecker.php) |
| TenantContext::hasTenant() is false after checkOne() returns, even when a probe throws | PROVEN (integration: testContextClearedAfterFailedProbe) |
| A subsequent real request reconnects cleanly to the correct tenant after a probe ran (no residual global state) | PROVEN (integration: testReconnectsCleanlyAfterProbe — tenantB query hits tenantB DB) |
| BootstrapperChain::healthCheck() invokes check() only on bootstrappers implementing HealthCheckBootstrapperInterface, skipping the rest, and fires no TenantBootstrapped/TenantResolved event | PROVEN (unit: testHealthCheckSkipsNonImplementors + testHealthCheckDispatchesZeroEvents) |
| DatabaseSwitchBootstrapper and SharedDriver each expose a live SELECT 1 connectivity probe | PROVEN (source: both files contain check() method with executeQuery('SELECT 1'); integration test runs live) |

All artifacts from the plan's `must_haves`:

| Artifact | Status |
|----------|--------|
| src/Health/TenantHealthChecker.php (contains "finally") | EXISTS, confirmed `finally` present |
| src/Bootstrapper/BootstrapperChain.php (contains "function healthCheck") | EXISTS, confirmed |
| src/Bootstrapper/DatabaseSwitchBootstrapper.php (contains "HealthCheckBootstrapperInterface") | EXISTS, confirmed |
| src/Driver/SharedDriver.php (contains "HealthCheckBootstrapperInterface") | EXISTS, confirmed |
| tests/Integration/Health/TenantHealthCheckerProbeTest.php (contains "assertFalse") | EXISTS, confirmed |

## Threat Surface Scan

No new threat surface introduced beyond what the threat model anticipated:
- T-33-03 (Tampering/Elevation): Mitigated by set->probe->clear-in-finally; unit test proves boot() 0 calls; integration test proves hasTenant() false.
- T-33-STATE (Tampering global Connection): Integration test (testReconnectsCleanlyAfterProbe) proves no residual state — subsequent tenantB request gets tenantB data.
- T-33-PROP (DoS via exception propagation): BootstrapperChain::healthCheck() wraps per-probe throws in BootstrapperHealthResult::fromException.

## Known Stubs

None — all implementations are complete behavioral code. TenantHealthChecker.checkOne() is fully wired and tested. No hardcoded placeholder values or TODO stubs.

## Self-Check: PASSED

Created files confirmed to exist:
- [x] src/Health/TenantHealthChecker.php
- [x] tests/Unit/Health/TenantHealthCheckerTest.php
- [x] tests/Unit/Bootstrapper/BootstrapperChainHealthCheckTest.php
- [x] tests/Integration/Health/TenantHealthCheckerProbeTest.php
- [x] tests/Integration/Support/MakeHealthServicesPublicPass.php

Modified files confirmed:
- [x] src/Bootstrapper/BootstrapperChain.php — healthCheck() added
- [x] src/Bootstrapper/DatabaseSwitchBootstrapper.php — check() + interface added
- [x] src/Driver/SharedDriver.php — check() + interface added
- [x] config/services.php — tenancy.health.checker + tenancy.health.sanitizer wired

Commits verified in git log:
- [x] a7309c3 feat(33-02): add check() to both isolation drivers + additive BootstrapperChain::healthCheck()
- [x] 1af95e7 feat(33-02): TenantHealthChecker set->probe->clear-in-finally + service wiring
- [x] 7a39a65 feat(33-02): probe-safety integration test — two SQLite tenants (Success Criterion 2)
