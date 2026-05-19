---
phase: 19-profiler-tab
verified: 2026-05-19T12:00:00Z
status: passed
score: 5/5 must-haves verified (5 automated + 3 live HTTP via 19-UAT.md)
human_verification_closed_by: .planning/phases/19-profiler-tab/19-UAT.md
overrides_applied: 0
re_verification:
  previous_status: human_needed
  previous_score: null
  gaps_closed: ["resolved-state visual UAT", "null-state visual UAT", "error-state visual UAT"]
  gaps_remaining: []
  regressions: []
human_verification:
  - test: "Boot a real Symfony 7.x / 8.x application with WebProfilerBundle, install danplaton4/tenancy-bundle as a dev dependency, hit a request that resolves a tenant, then open /_profiler/{token}?panel=tenancy"
    expected: "WDT badge shows the tenant slug; the Tenancy panel renders slug, tenant label, driver, connection name (label only, never DSN), resolver FQCN, bootstrapper FQCN list; toolbar/menu colors are green=resolved / yellow=null / red=error"
    why_human: "Visual fidelity, CSS theming under light/dark profiler modes, and end-user click-through cannot be verified programmatically. Plan 06 renders blocks via Twig but does not run the full Symfony Profiler chrome in a browser."
  - test: "Repeat the above against a /health-check (or any non-tenant) route"
    expected: "WDT badge shows the literal em-dash (—); panel shows 'No tenant resolved for this request.' copy; menu badge is yellow"
    why_human: "Visual-only verification; the rendered HTML is asserted in Plan 06, but the toolbar/menu appearance under WebProfilerBundle's runtime CSS is not."
  - test: "Trigger a Tenancy\\Bundle\\Exception\\TenantNotFoundException by hitting an unknown tenant route"
    expected: "WDT badge shows the literal warning glyph (⚠); panel shows exception class FQCN and HTML-escaped message; menu badge is red"
    why_human: "Visual + end-to-end exception flow through the real ProfilerListener, including profile storage. Plan 06's test dispatches ExceptionEvent through event_dispatcher but does not exercise the full kernel.exception → profile-write path."
---

# Phase 19: Profiler Tab — Verification Report

**Phase Goal (ROADMAP):** Ship a Symfony Profiler "Tenancy" tab in the WDT showing the active tenant context for the current request, with three render states (resolved / null / error), strict compile-out from production containers (`kernel.debug=false`), and stored-profile round-trip safety.

**Requirement:** DX-02
**Verified:** 2026-05-19
**Status:** passed (all automated must-haves verified + 3 live HTTP UAT items closed by `19-UAT.md`)
**Re-verification:** Status escalation: `human_needed` → `passed` after live HTTP verification in `/Users/danplaton/dev/hype/tests/symfony8x-demo` confirmed all 3 panel states render correctly (`sf-toolbar-status-green:resolved`, `sf-toolbar-status-yellow:null`, `sf-toolbar-status-red:error`) with the expected toolbar badges, status pills, and panel content. See `19-UAT.md` for full evidence.

## Goal Achievement

### Observable Truths (DX-02 Acceptance Criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | A new "Tenancy" tab appears in the Symfony Profiler when `kernel.debug=true` | VERIFIED | `config/services_dev.php:49-52` registers `data_collector` tag with `id='tenancy'`, `template='@Tenancy/Collector/tenant.html.twig'`. `src/Profiler/TenantDataCollector.php:76-84` returns `getName()='tenancy'` and `getTemplate()='@Tenancy/Collector/tenant.html.twig'`. `TenantDataCollectorCompileOutTest::testDataCollectorTagIsPresentWhenDebugTrue` asserts the live container exposes the service with correct identity. |
| 2 | Panel shows tenant slug, label, driver, connection name, resolved-by, bootstrappers, error state | VERIFIED | `src/Profiler/TenantDataCollector.php:64-73` produces 8-key shape (`state, slug, tenant_label, driver, connection_name, resolved_by, bootstrappers, error`). `src/Resources/views/Collector/tenant.html.twig:63-109` renders all 8 fields across resolved/error/null branches. `TenantDataCollectorWdtTest::testResolvedTenantStateProducesCorrectDataAndRendersSlug` asserts both data shape and rendered HTML contain slug, label, resolver FQCN, both bootstrapper FQCNs. |
| 3 | Production containers (`kernel.debug=false`) do NOT register the collector — compile-time strip-out | VERIFIED | `src/TenancyBundle.php:125-127` guards `services_dev.php` import with `if (true === $builder->getParameter('kernel.debug'))`. `config/services.php` contains zero profiler references (verified by `grep -n "Profiler" config/services.php` returning no output). `TenantDataCollectorCompileOutTest::testCollectorIsAbsentWhenDebugFalse` boots `TestKernel('prod', false)` and asserts `has(TenantDataCollector::class) === false` AND `has(TenantProfilerStash::class) === false`. `SourceLayoutTest::testProfilerClassesAreNotReferencedInProductionServicesFile` enforces the architectural invariant statically. |
| 4 | Stored Profiler dumps round-trip the panel data through `serialize()`/`unserialize()` without errors | VERIFIED | `src/Profiler/TenantDataCollector.php:64-73` populates `$this->data` with scalar-only values (strings, nulls, `string[]`, `array{class:string,message:string}`). D-11 scalar discipline preserved: no cloneVar, no Throwable storage, no entity. `TenantDataCollectorSerializationTest` round-trips all three states (resolved/null/error) byte-identically and additionally asserts the serialized blob contains no `Closure`, `Mock_`, `TenantProfilerStash`, or `MockObject` substrings (T-19-03 mitigation). 4 tests, 16 assertions, all pass. |
| 5 | The Tenancy data collector is registered ONLY when `kernel.debug = true` (verifiable by container compilation check) | VERIFIED | Same guard as truth 3. `TenantDataCollectorCompileOutTest` provides the runtime container-compilation check on both kernels (debug=true asserts present, debug=false asserts absent). `SourceLayoutTest::testTenancyBundleGuardsServicesDevImportWithKernelDebug` validates the guard mechanism statically by grepping `src/TenancyBundle.php` for `$builder->getParameter('kernel.debug')` and `import('../config/services_dev.php')`. |

**Automated Score:** 5/5 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `src/Profiler/TenantProfilerStash.php` | Per-request event-listener stash (final class, ResetInterface, 4 AsEventListener attrs, tenancy-namespace-only exception capture) | VERIFIED | 94 lines. `final class TenantProfilerStash implements ResetInterface`. 4 `#[AsEventListener]` attrs for `TenantResolved`, `TenantBootstrapped`, `TenantContextCleared`, `ExceptionEvent`. `TENANCY_EXCEPTION_NAMESPACE_PREFIX = 'Tenancy\\Bundle\\Exception\\'` constant with `str_starts_with` predicate on line 58. `reset()` clears all three fields. Imported and wired in `config/services_dev.php:30-32`. |
| `src/Profiler/TenantDataCollector.php` | AbstractDataCollector with 8-key shape, DSN defence, scalar-only data | VERIFIED | 103 lines. `final class TenantDataCollector extends AbstractDataCollector`. Synchronous `collect()` (no `lateCollect()` override, grep confirms 0). 4-arg constructor `(stash, tenantContext, driver, landlordConnection)`. DSN defence at lines 60-62 with `str_contains(':')` / `str_contains('@')` and `RuntimeException` with literal `'looks like a DSN'`. `getName()='tenancy'`, `getTemplate()='@Tenancy/Collector/tenant.html.twig'`. `$this->data` uses `array_values(array_map('strval', ...))` for bootstrappers (line 71). Imported by `config/services_dev.php:40-52` with full DI wiring. |
| `src/Resources/views/Collector/tenant.html.twig` | 3-branch state rendering with toolbar/menu/panel blocks | VERIFIED | 110 lines. `extends '@WebProfiler/Profiler/layout.html.twig'`. Three blocks: `toolbar` (line 3), `menu` (line 50), `panel` (line 60). Three branches in panel: `resolved` (line 63), `error` (line 97), else=null (line 104). Toolbar badge shows slug / `⚠` / `—`. All 8 data keys referenced. No `<script>`, no `<style>`, no `|raw`. |
| `src/Resources/views/Collector/_icon.svg.twig` | 24x24 inline SVG chain glyph, currentColor stroke | VERIFIED | 7 lines. `xmlns="http://www.w3.org/2000/svg"`, `viewBox="0 0 24 24"`, `stroke="currentColor"`. 3 `<path>` elements (spacer + 2 chain links). No `<style>`, no `<script>`, no Twig logic. |
| `config/services_dev.php` | Dev-only DI registration for stash + collector | VERIFIED | 53 lines. Registers both services with `->autoconfigure(true)->public()`. Collector args in plan-locked order. Explicit `->tag('data_collector', ['id' => 'tenancy', 'template' => '@Tenancy/Collector/tenant.html.twig'])`. |
| `config/services.php` | Untouched — zero profiler references | VERIFIED | `grep -n "Profiler" config/services.php` returns empty. T-19-02 and T-19-10 architectural invariants preserved. |
| `src/TenancyBundle.php` | Conditional `kernel.debug` guard + `getPath()` override | VERIFIED | Line 125 guard: `if (true === $builder->getParameter('kernel.debug'))`. Line 126 conditional import of `services_dev.php`. Lines 50-53 `getPath()` override returning `__DIR__` so TwigBundle registers `@Tenancy` namespace. |
| `tests/Integration/Profiler/SourceLayoutTest.php` | Static check that services.php is clean | VERIFIED | 85 lines, 3 tests, 17 assertions pass. |
| `tests/Integration/Profiler/TenantDataCollectorCompileOutTest.php` | Runtime container compile-out check | VERIFIED | 86 lines, 3 tests, 11 assertions pass. Boots debug=true and debug=false kernels. |
| `tests/Integration/Profiler/TenantDataCollectorSerializationTest.php` | Serialize/unserialize round-trip for all states | VERIFIED | 135 lines, 4 tests, 16 assertions pass. Round-trips resolved/null/error states and asserts blob contains no mock/closure/stash references. |
| `tests/Integration/Profiler/TenantDataCollectorWdtTest.php` | Functional end-to-end test driving events → collect → Twig render | VERIFIED | 271 lines, 4 tests, 42 assertions pass. Boots ProfilerTestKernel, dispatches real events through event_dispatcher, asserts data shape + rendered HTML contains slug/label/resolver/bootstrappers (resolved), em-dash + "No tenant resolved" (null), warning glyph + escaped exception message (error). |
| `tests/Unit/Profiler/TenantProfilerStashTest.php` | Unit coverage of stash | VERIFIED | 138 lines, 10 tests. Covered by full phpunit run. |
| `tests/Unit/Profiler/TenantDataCollectorTest.php` | Unit coverage of collector | VERIFIED | 230 lines, 12 tests. Covered by full phpunit run. |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|----|--------|---------|
| `src/TenancyBundle.php` | `config/services_dev.php` | Conditional `$container->import('../config/services_dev.php')` inside `if (true === $builder->getParameter('kernel.debug'))` | WIRED | Line 125-127. |
| `config/services_dev.php` | `TenantDataCollector` | `$services->set(TenantDataCollector::class)->args([...])->tag('data_collector', [...])` | WIRED | Lines 40-52 with full positional args (stash, tenancy.context, tenancy.driver, tenancy.landlord_connection). |
| `config/services_dev.php` | `TenantProfilerStash` | `$services->set(TenantProfilerStash::class)->autoconfigure(true)->public()` | WIRED | Lines 30-32. Autoconfigure picks up 4 AsEventListener attrs + ResetInterface tag. |
| `TenantDataCollector` | `TenantProfilerStash` | Constructor injection `private readonly TenantProfilerStash $stash` | WIRED | Line 34. |
| `TenantDataCollector` | `TenantContext` | Constructor injection `private readonly TenantContext $tenantContext` | WIRED | Line 35. |
| `tenant.html.twig` | `_icon.svg.twig` | `{{ include('@Tenancy/Collector/_icon.svg.twig') }}` | WIRED | Lines 5 and 55 (toolbar + menu blocks). |
| `tenant.html.twig` | `@WebProfiler/Profiler/layout.html.twig` | `{% extends '@WebProfiler/Profiler/layout.html.twig' %}` | WIRED | Line 1. |
| `TenantProfilerStash` | `TenantResolved` event | `#[AsEventListener(event: TenantResolved::class, method: 'onTenantResolved')]` | WIRED | Line 24. Verified by `TenantProfilerStashTest::testAsEventListenerAttributesReferenceCorrectEventsAndMethods`. |
| `TenantProfilerStash` | `TenantBootstrapped` event | `#[AsEventListener(event: TenantBootstrapped::class, method: 'onTenantBootstrapped')]` | WIRED | Line 25. |
| `TenantProfilerStash` | `TenantContextCleared` event | `#[AsEventListener(event: TenantContextCleared::class, method: 'onTenantContextCleared')]` | WIRED | Line 26. |
| `TenantProfilerStash` | `ExceptionEvent` | `#[AsEventListener(event: ExceptionEvent::class, method: 'onKernelException')]` | WIRED | Line 27. Filtered to `Tenancy\\Bundle\\Exception\\*` only (line 58). |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `tenant.html.twig` | `collector.data.*` (8 keys) | `TenantDataCollector::$data` populated in `collect()` from `TenantContext::getTenant()` + `TenantProfilerStash` getters + `%tenancy.driver%` / `%tenancy.landlord_connection%` DI params | Yes — `TenantDataCollectorWdtTest` empirically drives events through real `event_dispatcher`, calls `collect()`, renders Twig, and asserts HTML contains live data (slug `acme`, label `Acme Corp`, FQCNs, etc.) | FLOWING |
| `TenantDataCollector::$data['resolved_by']` | `$this->stash->getResolvedBy()` | `TenantResolved::$resolvedBy` captured by `TenantProfilerStash::onTenantResolved` | Yes | FLOWING |
| `TenantDataCollector::$data['bootstrappers']` | `$this->stash->getBootstrapperFqcns()` | `TenantBootstrapped::$bootstrappers` captured by `TenantProfilerStash::onTenantBootstrapped` | Yes — coerced via `array_values(array_map('strval', ...))` (collector line 71) | FLOWING |
| `TenantDataCollector::$data['error']` | `$this->stash->getCapturedException()` | `ExceptionEvent::getThrowable()` filtered to `Tenancy\\Bundle\\Exception\\*` namespace | Yes — error-state WDT test asserts FQCN + escaped message render in HTML | FLOWING |
| `TenantDataCollector::$data['connection_name']` | `match($this->driver) { 'database_per_tenant' => 'tenant', 'shared_db' => $this->landlordConnection }` | DI parameters `%tenancy.driver%` and `%tenancy.landlord_connection%` (label strings, never DSNs — defended by str_contains tripwire) | Yes — unit tests assert both driver modes; DSN tripwire tested via 2 RuntimeException assertions | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Full PHPUnit suite passes | `vendor/bin/phpunit` | 419 tests, 1143 assertions, OK | PASS |
| PHPStan level 9 clean | `vendor/bin/phpstan analyse --no-progress --memory-limit=512M` | `[OK] No errors` | PASS |
| `config/services.php` contains no profiler references | `grep -n "Profiler" config/services.php` | empty output (zero matches) | PASS |
| SourceLayoutTest enforces static invariant | `vendor/bin/phpunit --filter SourceLayoutTest` | OK (3 tests, 17 assertions) | PASS |
| Compile-out test proves debug=false absence | `vendor/bin/phpunit --filter TenantDataCollectorCompileOutTest` | OK (3 tests, 11 assertions) | PASS |
| Serialization test proves 8-key shape round-trips | `vendor/bin/phpunit --filter TenantDataCollectorSerializationTest` | OK (4 tests, 16 assertions) | PASS |
| WDT test drives full event→collect→render pipeline | `vendor/bin/phpunit --filter TenantDataCollectorWdtTest` | OK (4 tests, 42 assertions) | PASS |
| TenancyBundle guard uses strict equality on kernel.debug | `grep -n "kernel.debug" src/TenancyBundle.php` | `125: if (true === $builder->getParameter('kernel.debug')) {` (exactly 1 match) | PASS |
| DSN defence tripwire exists in collector | `grep -n "looks like a DSN" src/Profiler/TenantDataCollector.php` | line 61 — exact string present | PASS |
| Tenancy-only exception capture predicate exists in stash | `grep -n "TENANCY_EXCEPTION_NAMESPACE_PREFIX" src/Profiler/TenantProfilerStash.php` | line 30 (constant) and line 58 (predicate) | PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| DX-02 | Plans 19-00..19-06 (all declare `requirements: [DX-02]`) | Symfony Profiler "Tenancy" panel in the WDT showing active tenant for the current request — slug, ID, driver, connection name, resolved-by FQCN, bootstrappers run; renders cleanly in three states (resolved / null-resolution / error) | SATISFIED | All five DX-02 acceptance criteria verified (truths 1-5 above). Mapped via `.planning/REQUIREMENTS.md` line 113: `DX-02 | Phase 19 — Profiler Tab | Pending`. The "Pending" status is the pre-verification marker — STATE.md / requirements row update is the orchestrator's job, not this verifier's. |

No orphaned requirements detected for Phase 19.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| (none) | — | No TODO/FIXME/HACK markers in any Phase 19 production file. No empty handlers. No hardcoded empty data flowing to UI. No `cloneVar` calls (architectural rule). No `lateCollect` overrides (DX-02 acceptance line 2). No `EventSubscriberInterface` in stash (bundle idiom). No `|raw` filter in Twig. No `<script>` / `<style>` tags. | Info | Clean. |

### Human Verification Required

See `human_verification` frontmatter. Three visual/runtime tests need a human to open the Symfony Profiler in a real application:

1. Resolved-state visual check — confirm WDT badge shows slug, panel renders all 8 fields, colors are green.
2. Null-state visual check — confirm em-dash badge, "No tenant resolved" copy, yellow colors.
3. Error-state visual check — confirm warning-glyph badge, escaped exception in panel, red colors.

These are intrinsically not automatable without a full browser-driving test harness, which is out of phase scope.

### Gaps Summary

None. Every must-have in the phase plan frontmatter and every DX-02 acceptance criterion in REQUIREMENTS.md / 19-CONTEXT.md is satisfied by concrete code + tests in the repository. The 419-test PHPUnit suite passes, PHPStan level 9 is clean, and the production-config invariant (`config/services.php` contains zero profiler references) holds.

The single non-automated dimension is visual fidelity of the rendered panel under WebProfilerBundle's CSS in a live browser — surfaced as human verification items rather than gaps because the plan explicitly notes block-level Twig rendering as the test boundary (Plan 06 SUMMARY, "Rendering Approach" section).

---

*Verified: 2026-05-19*
*Verifier: Claude (gsd-verifier)*
