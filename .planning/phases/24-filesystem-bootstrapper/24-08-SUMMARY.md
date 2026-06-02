---
phase: 24-filesystem-bootstrapper
plan: 08
subsystem: testing
tags: [filesystem, integration, validation, lru-cache, flysystem, tenant-isolation]

requires:
  - phase: 24-filesystem-bootstrapper/00
    provides: FilesystemTestKernel stub + MakeFilesystemServicesPublicPass + ScopedStorageTaggingPass scaffolding
  - phase: 24-filesystem-bootstrapper/05
    provides: FilesystemPrefixingDecorator — prefix-mode decoration under test
  - phase: 24-filesystem-bootstrapper/06
    provides: TenantAwareFilesystemDecorator + LruFilesystemCache + AdapterDsnParser — per-tenant-adapter under test
  - phase: 24-filesystem-bootstrapper/07
    provides: FilesystemBootstrapper + FilesystemContractPass + TenancyBundle wiring — full kernel under test

provides:
  - "FilesystemBootstrapperIntegrationTest — 6-scenario integration suite (5 DEC-FILE-TEST-ADAPTER + autowiring regression)"
  - "LongRunningWorkerFilesystemSimulationTest — 100-tenant LRU simulation (4 test methods)"
  - "FilesystemTestKernel extension — enables tenancy.filesystem; registers users.storage (prefix) + tenant_buckets.storage (per_tenant_adapter) + public.storage (untagged)"
  - "StubTenantWithFilesystem — TenantInterface stub with StubTenantFilesystemExtension trait"
  - "StubFilesystemTenantProvider — pre-seeds acme/globex/broken + tenant_000..tenant_099"
  - "ReplaceFilesystemProviderPass — compiler pass swapping tenancy.provider for stub at compile time"

affects:
  - "24-09 docs — all BOOT-03 acceptance criteria proven here; docs can reference test file paths"

tech-stack:
  added: []
  patterns:
    - "setUpBeforeClass/tearDownAfterClass kernel lifecycle: one kernel boot per test class, setUp() resets cache + context between methods"
    - "Priority-ordered compiler passes: ScopedStorageTaggingPass at priority 10 runs BEFORE FilesystemContractPass at priority 0 (TYPE_BEFORE_OPTIMIZATION)"
    - "PHPStan @phpstan-ignore staticMethod.alreadyNarrowedType for assertNotInstanceOf(RuntimeException) — documented intent, not suppression (mirrors Phase 23-02 WR-01 pattern)"
    - "Provider cast via @var TenantProviderInterface: $container->get() returns object; inline @var provides PHPStan the concrete interface without unsafe assertions"

key-files:
  created:
    - tests/Integration/Filesystem/StubTenantWithFilesystem.php
    - tests/Integration/Filesystem/StubFilesystemTenantProvider.php
    - tests/Integration/Filesystem/ReplaceFilesystemProviderPass.php
  modified:
    - tests/Integration/Filesystem/FilesystemTestKernel.php
    - tests/Integration/Filesystem/ScopedStorageTaggingPass.php
    - tests/Integration/Filesystem/MakeFilesystemServicesPublicPass.php
    - tests/Integration/Filesystem/FilesystemBootstrapperIntegrationTest.php
    - tests/Integration/Filesystem/LongRunningWorkerFilesystemSimulationTest.php

key-decisions:
  - "ScopedStorageTaggingPass registered at PassConfig::TYPE_BEFORE_OPTIMIZATION priority 10: the bundle-supplied FilesystemContractPass (priority 0, same type) must not walk findTaggedServiceIds('tenancy.scoped') before our tagging pass has attached the tags. Higher priority = earlier execution within a PassConfig type."
  - "StubFilesystemTenantProvider inlined into FilesystemTestKernel namespace (not Integration/Messenger/Support) because it carries filesystemConfig pre-seeded — reusing the Messenger StubTenantProvider would require trait-mixing that would affect unrelated test classes."
  - "cache_size=2 in FilesystemTestKernel: this deliberately low value makes the 100-tenant LRU test deterministic — after 3 writes without TenantContextCleared, evictions start on every subsequent write. With cache_size=32 the eviction assertion would pass trivially but require 33+ tenants before evictions could start."
  - "KernelTestCase not used for LongRunningWorkerFilesystemSimulationTest: extends plain TestCase (same as Phase 20's LongRunningWorkerSimulationTest precedent). KernelTestCase adds no value here — we boot the kernel manually in setUpBeforeClass for lifecycle control."
  - "Stub property naming: self::$filesystem_kernel (not $kernel) to avoid collision with KernelTestCase's getKernelClass() infrastructure in FilesystemBootstrapperIntegrationTest."

patterns-established:
  - "Filesystem integration test shape: KernelTestCase for scenario tests, plain TestCase for simulation tests — mirrors Phase 20 Mailer split."
  - "Compiler-pass priority ordering to sequence tag-attachment BEFORE tag-walking: priority 10 (tag) > priority 0 (walk)."

requirements-completed: [BOOT-03]

duration: 11min
completed: 2026-06-02
---

# Phase 24 Plan 08: Filesystem Integration Test Suite Summary

**10 integration tests across 2 classes proving all 5 DEC-FILE-TEST-ADAPTER scenarios + autowiring regression + 100-tenant LRU pressure — BOOT-03 acceptance criteria fully verified end-to-end against a real Symfony kernel with league/flysystem-bundle**

## Performance

- **Duration:** ~11 min
- **Started:** 2026-06-02T20:38:51Z
- **Completed:** 2026-06-02T20:49:58Z
- **Tasks:** 3
- **Files created:** 3
- **Files modified:** 5

## Accomplishments

- Extended `FilesystemTestKernel` to enable `tenancy.filesystem` with `cache_size=2` and added `tenant_buckets.storage` (per_tenant_adapter strategy) alongside existing `users.storage` (prefix) and `public.storage` (untagged)
- Created `StubTenantWithFilesystem` — TenantInterface impl with `StubTenantFilesystemExtension` trait, mirrors `StubTenantWithMailer` from Phase 20
- Created `StubFilesystemTenantProvider` — pre-seeds acme/globex/broken (named scenarios) + tenant_000..tenant_099 (LRU simulation)
- Created `ReplaceFilesystemProviderPass` — swaps `tenancy.provider` at compile time, mirrors `ReplaceProviderWithStubPass` from Phase 20 Messenger tests
- Updated `ScopedStorageTaggingPass` to tag both `users.storage` (prefix) and `tenant_buckets.storage` (per_tenant_adapter) — registered at priority 10 so it precedes `FilesystemContractPass`
- Implemented `FilesystemBootstrapperIntegrationTest` — 6 test methods: 5 DEC-FILE-TEST-ADAPTER scenarios + autowiring regression; 21 assertions; PHPStan level 9 + cs-fixer clean
- Implemented `LongRunningWorkerFilesystemSimulationTest` — 4 test methods: 100-tenant bounded loop, pure LRU eviction, cross-tenant leak negative assertion, listener wiring sanity check; 606 assertions
- Full integration suite: 10 tests, 627 assertions, all green; full suite 674 tests, 1 incomplete (pre-existing from another plan)

## Task Commits

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Extend FilesystemTestKernel + add stub tenant fixtures | `209a163` | `FilesystemTestKernel.php`, `ScopedStorageTaggingPass.php`, `MakeFilesystemServicesPublicPass.php`, `StubTenantWithFilesystem.php`, `StubFilesystemTenantProvider.php`, `ReplaceFilesystemProviderPass.php` |
| 2 | 6-scenario FilesystemBootstrapperIntegrationTest | `bcb8a20` | `FilesystemBootstrapperIntegrationTest.php` |
| 3 | LongRunningWorkerFilesystemSimulationTest 100-tenant LRU | `f2f3f38` | `LongRunningWorkerFilesystemSimulationTest.php` |

## Files Created/Modified

### Created

- `tests/Integration/Filesystem/StubTenantWithFilesystem.php` — minimal `TenantInterface` with `StubTenantFilesystemExtension`; mirrors Phase 20's `StubTenantWithMailer`
- `tests/Integration/Filesystem/StubFilesystemTenantProvider.php` — pre-seeded provider with 3 named tenants + 100 numbered tenants
- `tests/Integration/Filesystem/ReplaceFilesystemProviderPass.php` — compiler pass swapping `tenancy.provider` for stub

### Modified

- `tests/Integration/Filesystem/FilesystemTestKernel.php` — added `tenant_buckets.storage`, `tenancy.filesystem` config block (enabled/allow_per_tenant_adapter/cache_size), `ReplaceFilesystemProviderPass`, priority-10 `ScopedStorageTaggingPass`
- `tests/Integration/Filesystem/ScopedStorageTaggingPass.php` — now tags both `users.storage` (prefix) and `tenant_buckets.storage` (per_tenant_adapter)
- `tests/Integration/Filesystem/MakeFilesystemServicesPublicPass.php` — added `tenant_buckets.storage` to exposed IDs
- `tests/Integration/Filesystem/FilesystemBootstrapperIntegrationTest.php` — replaced 1-method stub with full 6-test class
- `tests/Integration/Filesystem/LongRunningWorkerFilesystemSimulationTest.php` — replaced 1-method stub with full 4-test class

## Decisions Made

1. **ScopedStorageTaggingPass priority 10 vs FilesystemContractPass priority 0.** Both passes run at `TYPE_BEFORE_OPTIMIZATION`. Higher priority = earlier execution. Without this ordering, `FilesystemContractPass` would call `findTaggedServiceIds('tenancy.scoped')` before the tags are attached → decoration never applied → `users.storage` returns plain `League\Flysystem\Filesystem` instead of `FilesystemPrefixingDecorator`.

2. **Separate stub provider and stub tenant for filesystem tests.** The `Messenger\Support\StubTenant` doesn't carry `filesystemConfig` (it uses `StubTenantMailerExtension` not `StubTenantFilesystemExtension`). Rather than adding the filesystem trait to the Messenger test tenant (coupling concerns), a dedicated `StubTenantWithFilesystem` + `StubFilesystemTenantProvider` was created in the `Integration\Filesystem` namespace.

3. **cache_size=2 in FilesystemTestKernel.** Deliberately low to make LRU eviction deterministic for the 100-tenant simulation. With cache_size=32 the test would pass but provide weaker eviction-path coverage (evictions only start after 33 distinct tenants; 100 tenants > 32 still works but the assertion is less crisp). With cache_size=2, every 3rd distinct tenant triggers an eviction — much stronger regression gate.

4. **`@phpstan-ignore staticMethod.alreadyNarrowedType` on `assertNotInstanceOf(\RuntimeException)`.** PHPStan correctly detects this is always true (MissingFilesystemConfigException inherits from LogicException, not RuntimeException, so they're disjoint by class hierarchy). The assertion is retained as explicit documentation of the DEC-FILE-EXCEPTION Messenger no-retry invariant — same reasoning as Phase 23-02 WR-01. The ignore annotation is added on the line only, not as a baseline entry.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] ScopedStorageTaggingPass needed priority 10 for correct ordering**
- **Found during:** Task 1 verification (`php -r "... get_class($c->get('users.storage'))"`)
- **Issue:** Kernel verification showed `users.storage` returning `League\Flysystem\Filesystem` instead of `FilesystemPrefixingDecorator`. Root cause: `ScopedStorageTaggingPass` (no explicit priority = 0) was running at the SAME priority as `FilesystemContractPass`. Compiler pass order within the same priority is insertion-order within each registered type; because `parent::build()` (which adds `FilesystemContractPass` via `TenancyBundle::build()`) runs BEFORE our test-kernel passes, `FilesystemContractPass` won the race.
- **Fix:** Added `PassConfig::TYPE_BEFORE_OPTIMIZATION, 10` as 2nd and 3rd arguments to `$container->addCompilerPass(new ScopedStorageTaggingPass(), ...)`.
- **Files modified:** `tests/Integration/Filesystem/FilesystemTestKernel.php`
- **Verification:** `php -r "... echo get_class($c->get('users.storage'))"` → `Tenancy\Bundle\Filesystem\FilesystemPrefixingDecorator`

### No Architectural Changes

No Rule 4 issues triggered.

## Threat Model Compliance

Per the plan's `<threat_model>`:

| Threat ID | Disposition | Coverage |
|-----------|-------------|----------|
| T-24-08-01 (cross-tenant data leak) | mitigate | Test 1 (prefix isolation), Test 2 (per-tenant-adapter isolation), and `testCrossTenatLeakNegativeAssertion()` form a triple gate: prefix-mode writes invisible across tenants; per-tenant-adapter adapters structurally isolated; negative assertion confirms FilesystemException on cross-tenant read. |
| T-24-08-02 (LRU unbounded growth) | mitigate | `testCacheLruEvictionStaysBoundedWithoutContextClear()` asserts `cache.size() <= 2` after 100 tenants; `cache.evictions() > 0` confirms the eviction path ran. |
| T-24-08-03 (misconfigured tenant retried) | mitigate | Test 4 pins `MissingFilesystemConfigException instanceof LogicException === true` + `assertNotInstanceOf(RuntimeException)` at the integration level. |
| T-24-08-04 (decorator bypassed by autowiring) | mitigate | Test 6 asserts `$container->get('users.storage') instanceof FilesystemPrefixingDecorator` and `$container->get('tenant_buckets.storage') instanceof TenantAwareFilesystemDecorator`. |

## Known Stubs

None. All three tasks are fully implemented.

## Self-Check: PASSED

- `[ -f tests/Integration/Filesystem/FilesystemBootstrapperIntegrationTest.php ]` → FOUND
- `[ -f tests/Integration/Filesystem/LongRunningWorkerFilesystemSimulationTest.php ]` → FOUND
- `[ -f tests/Integration/Filesystem/StubTenantWithFilesystem.php ]` → FOUND
- `[ -f tests/Integration/Filesystem/StubFilesystemTenantProvider.php ]` → FOUND
- `[ -f tests/Integration/Filesystem/ReplaceFilesystemProviderPass.php ]` → FOUND
- `git log --oneline | grep 209a163` → FOUND
- `git log --oneline | grep bcb8a20` → FOUND
- `git log --oneline | grep f2f3f38` → FOUND
- `vendor/bin/phpunit tests/Integration/Filesystem/` → 10 tests, 627 assertions, OK
- `vendor/bin/phpstan analyse tests/Integration/Filesystem/` → OK No errors
- `vendor/bin/php-cs-fixer check tests/Integration/Filesystem/` → files: []

---
*Phase: 24-filesystem-bootstrapper*
*Completed: 2026-06-02*
