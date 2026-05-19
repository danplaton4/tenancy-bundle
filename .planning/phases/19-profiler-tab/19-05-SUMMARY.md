---
phase: 19-profiler-tab
plan: 05
subsystem: testing
tags: [phpunit, symfony-profiler, data-collector, serialization, integration-test, kernel-debug]

requires:
  - phase: 19-profiler-tab
    provides: TenantProfilerStash (Plan 01), TenantDataCollector (Plan 02), config/services_dev.php with kernel.debug import guard (Plan 04)
provides:
  - Runtime verification that kernel.debug guard removes Profiler services from production containers (T-19-02)
  - Static verification that config/services.php contains no Profiler references (T-19-10)
  - Stored-profile serialize/unserialize round-trip proof for all three render states (DX-02 line 4, T-19-03)
affects:
  - 19-profiler-tab (Plan 06 functional WDT test depends on the same wiring being correct)
  - Future phases that touch config/services.php (this guard test catches accidental drift)

tech-stack:
  added: []
  patterns:
    - "Compile-out testing: boot two TestKernel instances at class-level (different env + debug pairs, different cache dirs) and assert container.has(...) presence/absence"
    - "Static source-layout test: pure file_get_contents grep against repo paths, no kernel needed"
    - "Driving stateful final classes via their real public event listener methods instead of mocking"

key-files:
  created:
    - tests/Integration/Profiler/TenantDataCollectorCompileOutTest.php
    - tests/Integration/Profiler/TenantDataCollectorSerializationTest.php
    - tests/Integration/Profiler/SourceLayoutTest.php
  modified: []

key-decisions:
  - "Use class-level setUpBeforeClass for the compile-out kernel boots to avoid PHPUnit's risky-test warning caused by Symfony ErrorHandler global registration during per-test boot"
  - "Drive TenantProfilerStash with real event objects instead of mocks (the stash is final and cannot be doubled by PHPUnit)"
  - "Build ExceptionEvent via a mocked HttpKernelInterface so the error-state round-trip exercises the real onKernelException listener path"

patterns-established:
  - "Two-kernel compile-out pattern: TestKernel('test', true) + TestKernel('prod', false) at class-level setup, distinct cache dirs guaranteed by md5(static::class).'_'.environment"
  - "Stash-driven test fixtures: pure construction via the bundle's own event types, no mock generation for final classes"

requirements-completed: [DX-02]

duration: 22min
completed: 2026-05-19
---

# Phase 19 Plan 05: Profiler Integration Tests Summary

**Three integration tests that prove the kernel.debug compile-out guard, the stored-profile round-trip, and the source-layout invariant — together they form runtime + static verification for the Profiler tab wiring.**

## Performance

- **Duration:** ~22 min
- **Started:** 2026-05-19T06:22:00Z (approx)
- **Completed:** 2026-05-19T06:44:00Z
- **Tasks:** 3
- **Files created:** 3

## Accomplishments

- **Compile-out runtime proof (T-19-02):** `TenantDataCollectorCompileOutTest` boots `TestKernel('test', true)` and `TestKernel('prod', false)`, asserts `TenantDataCollector` and `TenantProfilerStash` are present in the debug container and absent in the prod container. Adds a third sanity check confirming the collector's `name='tenancy'` and `template='@Tenancy/Collector/tenant.html.twig'`.
- **Serialization round-trip proof (DX-02 line 4 + T-19-03):** `TenantDataCollectorSerializationTest` proves all three render states (`resolved`, `null`, `error`) survive `serialize()` → `unserialize()` byte-identically. A fourth test asserts the serialized blob contains no `Closure`, no `Mock_*`, no `TenantProfilerStash`, no `MockObject`, and no `TenantContext` substring — defence-in-depth against object leakage in stored profile dumps.
- **Source-layout static proof (T-19-10):** `SourceLayoutTest` verifies `config/services.php` contains zero profiler references, `config/services_dev.php` carries the full registration (collector, stash, `data_collector` tag with `id='tenancy'` and the template path), and `src/TenancyBundle.php` wraps the `services_dev.php` import in a `$builder->getParameter('kernel.debug')` guard.

## Task Commits

Each task was committed atomically with `--no-verify`:

1. **Task 05-01: TenantDataCollectorCompileOutTest** — `cdc8e51` (test)
2. **Task 05-02: TenantDataCollectorSerializationTest** — `14b057c` (test)
3. **Task 05-03: SourceLayoutTest** — `731f6c3` (test)

## Test Inventory (10 methods total, all passing)

### TenantDataCollectorCompileOutTest (3 methods)

1. `testCollectorIsRegisteredWhenDebugTrue`
2. `testCollectorIsAbsentWhenDebugFalse`
3. `testDataCollectorTagIsPresentWhenDebugTrue`

Cache dir paths for future debugging:
- debug kernel: `sys_get_temp_dir().'/tenancy_bundle_test_'.md5(TestKernel::class).'_test/cache'`
- prod kernel:  `sys_get_temp_dir().'/tenancy_bundle_test_'.md5(TestKernel::class).'_prod/cache'`

### TenantDataCollectorSerializationTest (4 methods)

1. `testCollectorRoundTripsResolvedState` — slug + tenant_label + resolved_by + bootstrappers all survive round-trip
2. `testCollectorRoundTripsNullState` — `state='null'` round-trips, no event activity needed
3. `testCollectorRoundTripsErrorState` — `state='error'`, `error['class']=TenantNotFoundException`, `error['message']` all survive
4. `testSerializedBlobContainsNoObjectReferences` — defence-in-depth: blob must not contain stash/context/mock substrings

### SourceLayoutTest (3 methods)

1. `testProfilerClassesAreNotReferencedInProductionServicesFile`
2. `testProfilerClassesAreReferencedInDevServicesFile`
3. `testTenancyBundleGuardsServicesDevImportWithKernelDebug`

## Files Created/Modified

- `tests/Integration/Profiler/TenantDataCollectorCompileOutTest.php` — runtime compile-out verification (86 lines)
- `tests/Integration/Profiler/TenantDataCollectorSerializationTest.php` — stored-profile round-trip + blob hygiene (135 lines)
- `tests/Integration/Profiler/SourceLayoutTest.php` — static source-layout invariant (85 lines)

## Decisions Made

- **Class-level kernel boot instead of per-test** — Plan-suggested per-test boot/shutdown triggered PHPUnit's "Test code or tested code did not remove its own exception handlers" risky warning because Symfony's ErrorHandler installs a process-global exception handler at boot. Switching to `setUpBeforeClass`/`tearDownAfterClass` (matching `ContainerCompilationTest`) silences the warning cleanly without changing what's asserted; the two kernels still have distinct cache dirs (md5(static::class) + env keying), so no compile collision.
- **Real stash, no mock** — `TenantProfilerStash` is `final`. PHPUnit cannot double final classes. The serialization tests instead instantiate a real stash and drive its state via its real public event listener methods (`onTenantResolved`, `onTenantBootstrapped`, `onKernelException` with a real `ExceptionEvent`). This exercises more production code per assertion and removes a phantom dependency on PHPUnit's class-doubler.
- **ExceptionEvent built via mocked HttpKernelInterface** — `ExceptionEvent`'s constructor requires an `HttpKernelInterface`; the interface is mockable (not final) and the listener never touches the kernel, so a no-op mock is sufficient.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Could not mock final TenantProfilerStash**
- **Found during:** Task 05-02
- **Issue:** Plan specified `$this->createMock(TenantProfilerStash::class)`, but the class is declared `final` and PHPUnit 11 throws `ClassIsFinalException`.
- **Fix:** Replaced the mock with a real `TenantProfilerStash` instance and drove its state via real `TenantResolved`/`TenantBootstrapped`/`ExceptionEvent` objects through the stash's public event listener methods. Added a `makeExceptionEvent()` helper that builds a real `ExceptionEvent` with a mocked `HttpKernelInterface` (mockable — not final).
- **Files modified:** `tests/Integration/Profiler/TenantDataCollectorSerializationTest.php`
- **Verification:** `vendor/bin/phpunit --filter TenantDataCollectorSerializationTest` exits 0 with 4 tests / 16 assertions; PHPStan level 9 clean.
- **Committed in:** 14b057c

**2. [Rule 3 — Blocking] PHPStan offsetAccess.nonOffsetAccessible on `getData()['error']['class']`**
- **Found during:** Task 05-02
- **Issue:** `getData(): array<string, mixed>` returns mixed values for each key; PHPStan level 9 rejects chained subscript access on the `error` key.
- **Fix:** Extracted `$error = $restoredData['error']`, asserted `assertIsArray($error)` (narrowing the type for PHPStan), then accessed `$error['class']` and `$error['message']`.
- **Files modified:** `tests/Integration/Profiler/TenantDataCollectorSerializationTest.php`
- **Verification:** `vendor/bin/phpstan analyse ... --level=9` exits 0.
- **Committed in:** 14b057c

**3. [Rule 1 — Bug] Per-test kernel boot triggered PHPUnit risky-test warning**
- **Found during:** Task 05-01
- **Issue:** Booting/shutting down a kernel inside every test method left Symfony's `ErrorHandler` global handler registered across the boot/shutdown boundary, which PHPUnit 11 reports as "Test code or tested code did not remove its own exception handlers" — risky but not failing. The plan's `<acceptance_criteria>` required `vendor/bin/phpunit --filter TenantDataCollectorCompileOutTest` to exit 0 (it did), but the warning hides real future regressions and pollutes CI output.
- **Fix:** Moved both kernel boots into `setUpBeforeClass` and shutdowns into `tearDownAfterClass`, matching the existing `ContainerCompilationTest` pattern. Both kernels are still distinct (different env + debug + cache dir), and the three test methods now read pre-booted containers.
- **Files modified:** `tests/Integration/Profiler/TenantDataCollectorCompileOutTest.php`
- **Verification:** `vendor/bin/phpunit --filter TenantDataCollectorCompileOutTest` exits 0 with 3 tests / 11 assertions, zero risky warnings.
- **Committed in:** cdc8e51

---

**Total deviations:** 3 auto-fixed (2 Rule 3 blocking, 1 Rule 1 bug)
**Impact on plan:** All deviations preserve the plan's intent — the same three test names exist, asserting the same things, but the implementation adapts to real-world constraints (final class, PHPStan level 9, PHPUnit 11 risky-test policy) the plan didn't anticipate. No scope creep; no acceptance criterion weakened.

## Issues Encountered

- **Worktree vendor symlink + autoloader double-declaration** — when running the full `--testsuite integration` (not just `--filter`), PHP errored on `Tenancy\Bundle\Tests\Integration\Support\Entity\TestProduct` redeclaration because the test fixture exists at both the worktree path AND the symlink-resolved canonical path. **Out of scope for this plan** — pre-existing worktree infrastructure concern, not caused by these tests. Logged here for the verifier's awareness. All 10 new tests run cleanly with `--filter` and with `vendor/bin/phpunit tests/Integration/Profiler/`.

- **Pre-existing PHPStan errors in `Support/ProfilerTestKernel.php`** — `class.notFound: Symfony\Bundle\WebProfilerBundle\WebProfilerBundle` (optional dev-dependency not installed in this worktree's vendor). Pre-existing from Plan 19-00, out of scope for this plan.

## User Setup Required

None.

## Next Phase Readiness

- **Plan 19-06 (functional WDT test)** can rely on the wiring being verified — if 19-06's WebProfiler kernel doesn't see the collector, the cause is either (a) WebProfilerBundle not registered or (b) `kernel.debug=false` in the test kernel, NOT a missing service registration (this plan rules that out).
- **DX-02 acceptance lines automated:** line 4 (stored-profile reload) via `TenantDataCollectorSerializationTest`; line 6 (compile-out CI check) via `TenantDataCollectorCompileOutTest` + `SourceLayoutTest`.
- **CI:** existing GitHub Actions matrix already runs `vendor/bin/phpunit --testsuite integration` — these 10 tests join automatically on next push; no workflow changes required.

## Self-Check: PASSED

- FOUND: tests/Integration/Profiler/TenantDataCollectorCompileOutTest.php
- FOUND: tests/Integration/Profiler/TenantDataCollectorSerializationTest.php
- FOUND: tests/Integration/Profiler/SourceLayoutTest.php
- FOUND commit cdc8e51 (Task 05-01)
- FOUND commit 14b057c (Task 05-02)
- FOUND commit 731f6c3 (Task 05-03)
- All 10 test methods pass: `vendor/bin/phpunit tests/Integration/Profiler/` → 10 tests / 44 assertions
- PHPStan level 9 clean on all three new files

---
*Phase: 19-profiler-tab*
*Plan: 05*
*Completed: 2026-05-19*
