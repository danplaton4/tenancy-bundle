---
phase: 19-profiler-tab
plan: 06
subsystem: testing
tags: [phpunit, symfony-profiler, web-debug-toolbar, twig, integration-test, dx]

# Dependency graph
requires:
  - phase: 19-profiler-tab/00
    provides: ProfilerTestKernel (FrameworkBundle + TwigBundle + WebProfilerBundle + TenancyBundle, debug=true)
  - phase: 19-profiler-tab/01
    provides: TenantProfilerStash (#[AsEventListener] for TenantResolved + TenantBootstrapped + ExceptionEvent)
  - phase: 19-profiler-tab/02
    provides: TenantDataCollector with 8-key data shape and getData() accessor
  - phase: 19-profiler-tab/03
    provides: tenant.html.twig template with toolbar/menu/panel blocks for 3 states
  - phase: 19-profiler-tab/04
    provides: config/services_dev.php DI registration (autoconfigure + data_collector tag)
  - phase: 19-profiler-tab/05
    provides: Compile-out + serialization + source-layout invariants
provides:
  - End-to-end functional verification of the profiler tab — request → event → stash → collect → Twig render
  - Empirical proof that the 8-key data shape and 3 render states (resolved/null/error) all work in a real kernel
  - XSS mitigation verification for T-19-08 (HTML-escaped exception message in error-state render)
  - TenancyBundle Twig namespace fix (getPath() override) so @Tenancy resolves at production runtime
affects: [phase-20+ DX work, any future profiler-panel enhancements, bundle Twig namespace consumers]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Block-level Twig rendering for testing: TemplateWrapper::renderBlock() bypasses the full @WebProfiler/Profiler/layout chain"
    - "test.service_container access pattern for private services (twig, profiler) in framework.test=true kernels"
    - "Live event_dispatcher dispatching as a test seam — drives stash listeners without manual ::on*() calls"

key-files:
  created:
    - tests/Integration/Profiler/TenantDataCollectorWdtTest.php (271 lines, 4 methods, 42 assertions)
    - .planning/phases/19-profiler-tab/deferred-items.md
  modified:
    - src/TenancyBundle.php (added getPath() override for Twig namespace auto-discovery)

key-decisions:
  - "Block-level Twig rendering over full template render (avoids profiler.css.twig kernel-service dependency)"
  - "Use test.service_container alias to access private services rather than re-public-izing twig/profiler/event_dispatcher"
  - "Drive listeners via live event_dispatcher (production code path) rather than direct ::onTenantResolved() calls"
  - "Render toolbar block with profiler_url=false to skip url('_profiler', ...) (no profiler route registered in test)"

patterns-established:
  - "Bundle getPath() override pattern: when bundle templates live under src/Resources/views, override AbstractBundle::getPath() to return __DIR__"

requirements-completed: [DX-02]

# Metrics
duration: ~40min
completed: 2026-05-19
---

# Phase 19 Plan 06: End-to-End Functional WDT Test Summary

**Functional end-to-end test boots ProfilerTestKernel, drives live event dispatch + collect + Twig render for all 3 panel states (resolved/null/error), asserts both 8-key data shape and rendered HTML substrings — closing the DX-02 acceptance loop.**

## Performance

- **Duration:** ~40 min
- **Started:** 2026-05-19T06:20Z (worktree base reset)
- **Completed:** 2026-05-19T07:00Z
- **Tasks:** 1 (with 1 Rule 2 deviation fix)
- **Files created:** 2 (test + deferred-items)
- **Files modified:** 1 (TenancyBundle.php)

## Accomplishments

- Single integration test file (`TenantDataCollectorWdtTest.php`) covering 4 scenarios with 42 assertions
- All 3 panel states verified end-to-end: resolved (slug/label/resolver/bootstrappers), null (em-dash + "No tenant resolved"), error (warning glyph + escaped exception)
- T-19-08 XSS mitigation empirically verified: exception message `tenant "ghost" not found` renders as `tenant &quot;ghost&quot; not found` in the panel
- Critical bundle fix: `TenancyBundle::getPath()` override so the `@Tenancy` Twig namespace registers in any container (production + test)
- 14/14 profiler integration tests pass; 313/313 unit tests pass; PHPStan level 9 clean

## Task Commits

1. **Rule 2 Deviation — Fix TenancyBundle::getPath() for Twig namespace** — `0519171` (fix)
2. **Task 06-01: TenantDataCollectorWdtTest + deferred-items** — `de41cdf` (test)

## Files Created/Modified

- `tests/Integration/Profiler/TenantDataCollectorWdtTest.php` — 4 tests, 42 assertions, end-to-end Twig render verification
- `src/TenancyBundle.php` — added `getPath()` override returning `__DIR__` so TwigBundle's auto-discovery picks up `src/Resources/views` as `@Tenancy`
- `.planning/phases/19-profiler-tab/deferred-items.md` — documents pre-existing full-suite phpunit cross-worktree autoload collision

## Test Methods

| # | Method | Assertions | What it proves |
|---|--------|------------|----------------|
| 1 | `testProfilerServiceGraphIsRegisteredInDebugKernel` | 8 | DI graph (TenantDataCollector, TenantProfilerStash, profiler, twig, event_dispatcher, tenancy.context) plus collector identity (`getName()='tenancy'`, `getTemplate()='@Tenancy/...'`) |
| 2 | `testResolvedTenantStateProducesCorrectDataAndRendersSlug` | 18 | 8-key data shape with state='resolved' + rendered HTML contains slug, label, resolver name, both bootstrapper FQCNs, "Bootstrappers (2)", and green toolbar status class |
| 3 | `testNullResolutionStateRendersEmDashBadgeAndNoTenantPanelCopy` | 9 | 8-key data with state='null', no slug/label/resolver, empty bootstrappers, null error + rendered HTML contains em-dash (U+2014), "No tenant resolved", yellow toolbar status |
| 4 | `testErrorStateRendersWarningGlyphBadgeAndEscapedExceptionMessage` | 7 | state='error', error array has class+message + rendered HTML contains warning glyph (U+26A0), `TenantNotFoundException` FQCN, **HTML-escaped** message `tenant &quot;ghost&quot; not found` (T-19-08 XSS proof), red toolbar status |

## Rendering Approach

The collector template `@Tenancy/Collector/tenant.html.twig` extends `@WebProfiler/Profiler/layout.html.twig`. Rendering the full layout requires a fully wired profiler runtime context (the `kernel` service inside `profiler.css.twig` line 350, route registration for `url('_profiler', ...)`, etc.) — too much test infrastructure for a verification of the panel contract.

**Solution:** render the individual `toolbar` and `panel` blocks via `TemplateWrapper::renderBlock()`. This is the same code path WebProfilerBundle uses internally when composing the toolbar/page. The test passes the required context variables (`collector`, `name='tenancy'`, `token`, `profiler_url=false`) so the toolbar's include of `@WebProfiler/Profiler/toolbar_item.html.twig` resolves cleanly without needing a profiler route registered. Setting `profiler_url=false` skips the `<a href={{ url('_profiler', ...) }}>` wrapper.

## TenantContext API Adjustments

None — the plan's assumed `setTenant(TenantInterface)` / `clear()` / `getTenant()` / `hasTenant()` methods exist exactly as expected on `src/Context/TenantContext.php`.

## Decisions Made

- **Live event_dispatcher over direct listener calls** — dispatches through the kernel's real `event_dispatcher` service, which means stash listener registration (via `#[AsEventListener]` autoconfigure tags) is also being verified by the test. A direct `$stash->onTenantResolved(...)` call would bypass that wiring proof.
- **Block-level Twig render over full template render** — keeps the test from depending on the rest of the profiler chrome's runtime services.
- **test.service_container** for private service access — same pattern as `WebTestCase::getContainer()` in symfony/framework-bundle.
- **`profiler_url=false`** to disable toolbar item's `<a>` link wrapper, so the test doesn't need a profiler route.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 – Missing Critical] TenancyBundle::getPath() override for Twig namespace registration**

- **Found during:** Task 06-01 smoke-test (kernel boot + Twig render)
- **Issue:** `AbstractBundle::getPath()` defaults to `dirname($reflectedFile, 2)`, which for `src/TenancyBundle.php` resolves to the package root (`/path/to/tenancy-bundle/`), NOT the `src/` dir. TwigBundle's `getBundleTemplatePaths()` then searches `<root>/Resources/views` and `<root>/templates` — neither exists for this bundle whose templates live at `src/Resources/views/Collector/`. As a result the `@Tenancy` Twig namespace was never registered and any attempt to render `@Tenancy/Collector/tenant.html.twig` threw `Twig\Error\LoaderError: There are no registered paths for namespace "Tenancy"`.
- **Why this is correctness-critical (not scope-creep):** without this fix, the entire DX-02 feature (the Tenancy Profiler tab) cannot render in any container that uses TwigBundle — production or test. The tag `template='@Tenancy/Collector/tenant.html.twig'` on the data_collector service is dead-letter without the namespace.
- **Fix:** Override `TenancyBundle::getPath()` to return `__DIR__`. TwigBundle then auto-registers `src/Resources/views` as the `@Tenancy` namespace.
- **Files modified:** `src/TenancyBundle.php`
- **Verification:** Smoke-test `var_dump($twig->getLoader()->getNamespaces())` now returns `[WebProfiler, !WebProfiler, Tenancy, !Tenancy]` (before fix: only the WebProfiler entries). Plus all 4 new tests + 14 Profiler integration tests + 313 unit tests pass.
- **Committed in:** `0519171`

---

**Total deviations:** 1 auto-fixed (Rule 2 — missing critical functionality)
**Impact on plan:** Necessary correctness fix for DX-02 to work outside the unit-test sandbox. No scope creep.

## Issues Encountered

- **Twig render of full template fails on `@WebProfiler/Profiler/profiler.css.twig` line 350** — that template accesses the `kernel` service via a service locator that's not populated in the partial render context. Resolved by rendering the `toolbar` and `panel` blocks directly (see Rendering Approach above).
- **Toolbar block include fails without `name` variable** — `@WebProfiler/Profiler/toolbar_item.html.twig` reads `name` from context. Resolved by passing `name='tenancy'` (matches the data_collector tag id) in the renderBlock context.
- **Twig `name` unused variable warning when rendering `panel` block alone** — `strict_variables: true` is enabled in the kernel; `name` is only consumed by the toolbar block's include. Resolved by passing the same context to both renderBlock calls (Twig ignores extra context vars without warning).

## Deferred Items

### Cross-worktree autoload collision in full-suite phpunit run

Discovered while validating: running `vendor/bin/phpunit` (full suite) from inside the worktree fails partway through with `Cannot redeclare class Tenancy\Bundle\Entity\Tenant` — the worktree's autoloader (registered in `tests/bootstrap.php` with `prepend: true`) loads `Tenant.php` from the worktree's `src/`, but a later integration test triggers a kernel boot whose Doctrine mapping/autoload cache resolves the same class from the **parent repo's** `src/` path.

**Confirmed pre-existing:** reproduces even with this plan's changes stashed (`git stash` + re-run shows identical error). Not caused by plan 19-06.

**Per-suite runs pass cleanly:**
- `vendor/bin/phpunit --testsuite unit` → 313 tests OK
- `vendor/bin/phpunit tests/Integration/Profiler` → 14 tests OK
- `vendor/bin/phpunit --filter TenantDataCollectorWdtTest` → 4 tests OK

Tracked in `.planning/phases/19-profiler-tab/deferred-items.md`.

## Verification

- [x] `php -l tests/Integration/Profiler/TenantDataCollectorWdtTest.php` — No syntax errors
- [x] `vendor/bin/phpunit --filter TenantDataCollectorWdtTest` — OK (4 tests, 42 assertions)
- [x] `vendor/bin/phpunit tests/Integration/Profiler` — OK (14 tests, 86 assertions)
- [x] `vendor/bin/phpunit --testsuite unit` — OK (313 tests, 838 assertions)
- [x] `vendor/bin/phpstan analyse tests/Integration/Profiler/TenantDataCollectorWdtTest.php --level=9` — [OK] No errors
- [x] `vendor/bin/phpstan analyse src/TenancyBundle.php --level=9` — [OK] No errors
- [x] Acceptance criteria grep counts:
  - `namespace …Tests\\Integration\\Profiler;` → 1
  - `new ProfilerTestKernel` → 1 (>=1)
  - `public function test` → 4 (>=4)
  - `renderPanelAndToolbar` → 4 (1 helper + 3 calls)
  - `TenantResolved` → 3 (>=1)
  - `TenantBootstrapped` → 3 (>=1)
  - `ExceptionEvent` → 3 (>=1)
  - `assertStringContainsString` → 14 (>=8)
  - `acme` → 3 (>=2)
  - `Acme Corp` → 3 (>=1)
  - em-dash → 8 (>=1)
  - warning glyph → 1 (>=1)
  - line count → 271 (>=100)

## DX-02 Success Criteria — End-to-End Verifiable

| # | Criterion | Verified by |
|---|-----------|-------------|
| 1 | Resolved badge + panel show slug, label, resolver, bootstrappers | `testResolvedTenantStateProducesCorrectDataAndRendersSlug` |
| 2 | Null state shows em-dash badge + "No tenant resolved" copy | `testNullResolutionStateRendersEmDashBadgeAndNoTenantPanelCopy` |
| 3 | Error state shows warning glyph + escaped exception | `testErrorStateRendersWarningGlyphBadgeAndEscapedExceptionMessage` |
| 4 | Stored-profile reload round-trips losslessly | Plan 19-05 `TenantDataCollectorSerializationTest` (still passing) |
| 5 | Compile-out in non-debug containers | Plan 19-05 `TenantDataCollectorCompileOutTest` (still passing) |

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- DX-02 is now end-to-end verifiable. The phase is ready for ROADMAP closure (separate task — this executor does NOT update STATE.md or ROADMAP.md per harness constraints).
- The `TenancyBundle::getPath()` fix benefits **all** bundle Twig template consumers, not just the profiler tab. Worth a callout in the next CHANGELOG entry.
- Cross-worktree full-suite phpunit collision (deferred-items.md) should be picked up in a future infrastructure pass.

## Self-Check: PASSED

- [x] FOUND: tests/Integration/Profiler/TenantDataCollectorWdtTest.php
- [x] FOUND: src/TenancyBundle.php (modified)
- [x] FOUND: .planning/phases/19-profiler-tab/deferred-items.md
- [x] FOUND: commit 0519171 (fix bundle getPath)
- [x] FOUND: commit de41cdf (test + deferred-items)

---
*Phase: 19-profiler-tab*
*Plan: 06*
*Completed: 2026-05-19*
