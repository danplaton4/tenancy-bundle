---
phase: 17
plan: P04
subsystem: testing
tags: [origin-header, integration-tests, symfony-kernel, resolver-chain, end-to-end]
dependency_graph:
  requires:
    - src/Resolver/OriginHeaderResolver.php (Plan 01)
    - src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php (Plan 02)
    - src/TenancyBundle.php updated wiring (Plan 03)
    - src/DependencyInjection/Compiler/ResolverChainPass.php BUILT_IN_RESOLVER_MAP (Plan 03)
  provides:
    - tests/Integration/Resolver/Support/StubTenant.php
    - tests/Integration/Resolver/Support/StubTenantProvider.php
    - tests/Integration/Resolver/Support/RecordingLogger.php
    - tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php
  affects:
    - Phase 17 P05 (docs) — correctness proof that all four scenarios work end-to-end
tech_stack:
  added: []
  patterns:
    - Inline test kernel with SeedStubProviderPass + ReplaceLoggerPass compiler passes
    - Post-boot tenant seeding via container.get() + addTenant() (avoids Closure factory type error)
    - assertInstanceOf() for PHPStan-safe container.get() type narrowing
    - Non-nullable static property (OriginResolverTestKernel $kernel) to avoid PHPStan null guards
key_files:
  created:
    - tests/Integration/Resolver/Support/StubTenant.php
    - tests/Integration/Resolver/Support/StubTenantProvider.php
    - tests/Integration/Resolver/Support/RecordingLogger.php
    - tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php
  modified: []
key_decisions:
  - "Post-boot tenant seeding: Definition::setFactory() rejects Closures (TypeError); tenants seeded via addTenant() after kernel->boot() in setUpBeforeClass instead"
  - "Non-nullable static $kernel: typed OriginResolverTestKernel (not ?OriginResolverTestKernel) so PHPStan level 9 does not require null guards in each test method"
  - "assertInstanceOf for ResolverChain: container->get() returns object; assertInstanceOf() both asserts and narrows type to ResolverChain for PHPStan"
requirements-completed: [RESV-06]
duration: 6min
completed: 2026-05-15
---

# Phase 17 Plan P04: End-to-end integration test for OriginHeaderResolver Summary

**End-to-end proof via real Symfony kernel: 5 scenarios exercising Plans 01+02+03 together — exact match, wildcard match, OPTIONS preflight, X-Tenant-ID mismatch warning, and empty allow-list compile-time failure.**

## Performance

- **Duration:** ~6 min
- **Started:** 2026-05-15T10:52:00Z
- **Completed:** 2026-05-15T10:58:48Z
- **Tasks:** 2
- **Files modified:** 4 created, 0 modified

## Accomplishments

- Three test fixture files (StubTenant, StubTenantProvider, RecordingLogger) in `Integration/Resolver/Support/` namespace
- Five-scenario integration test suite booting a real Symfony kernel with `TenancyBundle`, `OriginHeaderResolver` service wired via Plans 01-03
- All 5 test scenarios pass; full suite 333 tests / 825 assertions, zero regressions
- PHPStan level 9 clean on all four new files
- php-cs-fixer reports no fixable files

## Task Commits

1. **Task 1: Create Integration/Resolver/Support fixtures** - `eeed373` (feat)
2. **Task 2: OriginHeaderResolverIntegrationTest with 5 scenarios** - `1b0490d` (feat)

## Files Created/Modified

- `tests/Integration/Resolver/Support/StubTenant.php` - TenantInterface stub, namespace `...Integration\Resolver\Support`
- `tests/Integration/Resolver/Support/StubTenantProvider.php` - Seeded provider with addTenant/findBySlug/findAll
- `tests/Integration/Resolver/Support/RecordingLogger.php` - PSR-3 in-memory logger with warnings() filter
- `tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php` - 5-scenario integration test with two inline kernels

## Scenario Results

| Scenario | Test Method | Result |
|----------|------------|--------|
| Exact origin match | testOriginMatchResolvesTenant | PASS — slug=acme, resolvedBy=OriginHeaderResolver::class |
| Wildcard match | testWildcardOriginMatchResolvesTenant | PASS — slug=beta from *.app.example.com |
| OPTIONS preflight | testOptionsPreflightReturnsNull | PASS — null returned, no exception |
| X-Tenant-ID mismatch warning | testMismatchWithXTenantIdLogsWarning | PASS — acme wins, 1 warning with D-11 context payload |
| Empty allow-list boot failure | testEmptyAllowListFailsAtBoot | PASS — InvalidArgumentException with locked message |

## TenantResolution API Observed

`ResolverChain::resolve()` returns `?TenantResolution` (Phase 15 `final readonly class`). Fields accessed as promoted public readonly properties:
- `$resolution->tenant` — `TenantInterface`
- `$resolution->resolvedBy` — `string` FQCN

No getters. All five tests use property access directly.

## Decisions Made

- Post-boot tenant seeding: `Definition::setFactory()` does not accept Closures (PHP TypeError at compile-time in Symfony DI). Resolved by seeding `StubTenantProvider` with `addTenant()` calls after `kernel->boot()` inside `setUpBeforeClass()`.
- Non-nullable `static $kernel` property: avoids requiring null guards in every test method (PHPStan level 9 reports `method.nonObject` on nullable kernel).
- `assertInstanceOf(ResolverChain::class, $chain)` after `container->get()`: PHPStan-safe type narrowing without `@var` suppression.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Created vendor symlink for worktree autoloading**
- **Found during:** Task 2 (running tests)
- **Issue:** `tests/bootstrap.php` uses `dirname(__DIR__).'/vendor/autoload.php'` which resolves to `.claude/worktrees/vendor/` — does not exist; tests couldn't load PHPUnit or any dependencies
- **Fix:** Created symlink `tests/Integration/Resolver/../../vendor -> /path/to/main/repo/vendor` at worktree root; symlink is covered by `.gitignore`
- **Files modified:** `vendor` symlink (not committed — gitignored, runtime only)
- **Verification:** PHPUnit runs successfully with 333 tests passing

**2. [Rule 1 - Bug] Fixed Closure-based factory in SeedStubProviderPass**
- **Found during:** Task 2 (first test run)
- **Issue:** Plan's code sample used `$def->setFactory($factory)` with a `Closure` — Symfony's `Definition::setFactory()` only accepts `array|string|null`, not `Closure`; throws `TypeError` at container compile time
- **Fix:** Removed Closure factory; changed SeedStubProviderPass to register bare `StubTenantProvider` (no args); added post-boot seeding in `setUpBeforeClass()` after `kernel->boot()`
- **Files modified:** `tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php`
- **Verification:** 5/5 tests pass; TypeError resolved

**3. [Rule 1 - Bug] Fixed PHPStan level 9 type errors (nullable kernel + object return)**
- **Found during:** Task 2 (PHPStan verification)
- **Issue 1:** `private static ?OriginResolverTestKernel $kernel` — PHPStan flags `getContainer()` on nullable as `method.nonObject` (9 errors)
- **Issue 2:** `container->get()` returns `object`; calling `->resolve()` on untyped object fails level 9 (`method.notFound`)
- **Fix:** Changed kernel property to non-nullable `private static OriginResolverTestKernel $kernel`; added `assertInstanceOf(ResolverChain::class, $chain)` before calling `->resolve()` in each test method (PHPStan-safe type narrowing via assertion, not `@var`)
- **Files modified:** `tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php`
- **Verification:** `phpstan analyse tests/Integration/Resolver --level=9` reports zero errors

---

**Total deviations:** 3 auto-fixed (1 blocking — vendor symlink, 2 bug fixes — factory + PHPStan)
**Impact on plan:** All fixes necessary for correct, type-safe test execution. No scope creep. Plan objective achieved fully.

## Issues Encountered

- Symfony's `Definition::setFactory()` has a strict type signature (no Closure support) not documented in the plan's code sample. The post-boot seeding approach is actually cleaner — it doesn't fight the DI container's type system.

## Known Stubs

None — all four new files are fully implemented and test real behavior against a booted kernel.

## Threat Coverage

| Threat ID | Test | Status |
|-----------|------|--------|
| T-17-02 | testEmptyAllowListFailsAtBoot | MITIGATED — compile-time guard fires in real kernel boot |
| T-17-03 | testEmptyAllowListFailsAtBoot | MITIGATED — InvalidArgumentException with locked message confirmed |
| T-17-04 | testMismatchWithXTenantIdLogsWarning | MITIGATED — structured D-11 warning context confirmed end-to-end |
| T-17-05 | testOptionsPreflightReturnsNull | MITIGATED — preflight passthrough confirmed with real resolver chain |

## Threat Flags

None — test files only. No new network endpoints, auth paths, file access patterns, or schema changes introduced.

## Self-Check

- `tests/Integration/Resolver/Support/StubTenant.php`: FOUND
- `tests/Integration/Resolver/Support/StubTenantProvider.php`: FOUND
- `tests/Integration/Resolver/Support/RecordingLogger.php`: FOUND
- `tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php`: FOUND
- Commit `eeed373` (fixtures): FOUND
- Commit `1b0490d` (integration test): FOUND
- Full suite 333 tests, 825 assertions: CONFIRMED

## Self-Check: PASSED

---
*Phase: 17-origin-header-resolver*
*Completed: 2026-05-15*
