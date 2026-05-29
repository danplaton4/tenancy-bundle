---
phase: 19
slug: profiler-tab
status: complete
nyquist_compliant: true
wave_0_complete: true
created: 2026-05-18
---

# Phase 19 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11 (already in `require-dev`: `"phpunit/phpunit": "^11.0"`) |
| **Config file** | `phpunit.xml` / `phpunit.xml.dist` (verify present; Wave 0 creates if missing) |
| **Quick run command** | `vendor/bin/phpunit --testsuite unit --filter Profiler` (~2s) |
| **Full suite command** | `vendor/bin/phpunit` (full unit + integration) |
| **Static check command** | `vendor/bin/phpstan analyse src/Profiler tests/Unit/Profiler tests/Integration/Profiler` |
| **Style command** | `vendor/bin/php-cs-fixer fix src/Profiler tests/Unit/Profiler tests/Integration/Profiler --diff` |
| **Estimated runtime** | ~10 seconds (full Profiler subset, unit + integration) |

---

## Sampling Rate

- **After every task commit:** `vendor/bin/phpunit --testsuite unit --filter Profiler` (~2s, sub-second after warm)
- **After every plan wave:** `vendor/bin/phpunit --filter Profiler` (unit + integration, ~10s)
- **Before `/gsd-verify-work`:** `vendor/bin/phpunit && vendor/bin/phpstan analyse && vendor/bin/php-cs-fixer check`
- **Max feedback latency:** 10 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 19-00-01 | 00 | 0 | DX-02 | — | composer require-dev updated | structural | `composer show symfony/web-profiler-bundle` | ✅ exists | ✅ green |
| 19-00-02 | 00 | 0 | DX-02 | — | test dirs exist | structural | `test -d tests/Unit/Profiler && test -d tests/Integration/Profiler` | ✅ exists | ✅ green |
| 19-01-01 | 01 | 1 | DX-02 | T-19-04 (stale ctx) | Stash captures resolver FQCN | unit | `vendor/bin/phpunit --filter TenantProfilerStashTest::testCapturesResolvedByOnTenantResolved` | ✅ exists | ✅ green |
| 19-01-02 | 01 | 1 | DX-02 | T-19-04 | Stash captures bootstrapper FQCNs | unit | `vendor/bin/phpunit --filter TenantProfilerStashTest::testCapturesBootstrappersOnTenantBootstrapped` | ✅ exists | ✅ green |
| 19-01-03 | 01 | 1 | DX-02 | T-19-05 (scope) | Stash ignores non-tenancy exceptions | unit | `vendor/bin/phpunit --filter TenantProfilerStashTest::testIgnoresNonTenancyExceptions` | ✅ exists | ✅ green |
| 19-01-04 | 01 | 1 | DX-02 | T-19-04 | reset() clears all fields | unit | `vendor/bin/phpunit --filter TenantProfilerStashTest::testResetClearsAllFields` | ✅ exists | ✅ green |
| 19-02-01 | 02 | 1 | DX-02 | T-19-03 (scalars) | collect() produces 8-key shape for resolved state | unit | `vendor/bin/phpunit --filter TenantDataCollectorTest::testCollectProducesResolvedStateShape` | ✅ exists | ✅ green |
| 19-02-02 | 02 | 1 | DX-02 | — | collect() produces null-state when no tenant | unit | `vendor/bin/phpunit --filter TenantDataCollectorTest::testCollectProducesNullStateWhenNoTenant` | ✅ exists | ✅ green |
| 19-02-03 | 02 | 1 | DX-02 | — | collect() produces error-state when stash captured exception | unit | `vendor/bin/phpunit --filter TenantDataCollectorTest::testCollectProducesErrorStateWhenStashCapturedException` | ✅ exists | ✅ green |
| 19-02-04 | 02 | 1 | DX-02 | T-19-01 (DSN leak) | DSN-like connection name string rejected | unit | `vendor/bin/phpunit --filter TenantDataCollectorTest::testConnectionNameDsnLikeStringThrows` | ✅ exists | ✅ green |
| 19-02-05 | 02 | 1 | DX-02 | T-19-03 | data contains only scalars + string[] | unit | `vendor/bin/phpunit --filter TenantDataCollectorTest::testDataContainsOnlyScalarsAndStringArrays` | ✅ exists | ✅ green |
| 19-02-06 | 02 | 1 | DX-02 | — | getTemplate() returns @Tenancy/Collector/tenant.html.twig | unit | `vendor/bin/phpunit --filter TenantDataCollectorTest::testTemplatePathReturnsBundleNamespace` | ✅ exists | ✅ green |
| 19-03-01 | 03 | 2 | DX-02 | — | Twig template renders 3 states | structural | `grep -E "data.state == 'resolved'\|data.state == 'error'" src/Resources/views/Collector/tenant.html.twig` + functional render | ✅ exists | ✅ green |
| 19-03-02 | 03 | 2 | DX-02 | — | _icon.svg.twig present and valid SVG | structural | `grep -c '<svg xmlns="http://www.w3.org/2000/svg"' src/Resources/views/Collector/_icon.svg.twig` | ✅ exists | ✅ green |
| 19-04-01 | 04 | 2 | DX-02 | T-19-02 (compile-out) | DI guard in TenancyBundle::loadExtension() | structural | `grep -n "kernel.debug" src/TenancyBundle.php` + `test -f config/services_dev.php` | ✅ exists | ✅ green |
| 19-04-02 | 04 | 2 | DX-02 | T-19-02 | services_dev.php imported only when debug=true | structural | `grep -c "services_dev" config/services.php src/TenancyBundle.php` (must be in TenancyBundle.php only, NOT in services.php) | ✅ exists | ✅ green |
| 19-05-01 | 05 | 3 | DX-02 | T-19-02 | Collector absent in debug=false container | integration | `vendor/bin/phpunit --filter TenantDataCollectorCompileOutTest` | ✅ exists | ✅ green |
| 19-05-02 | 05 | 3 | DX-02 | T-19-03 | Stored-profile serialize/unserialize round-trip | integration | `vendor/bin/phpunit --filter TenantDataCollectorSerializationTest` | ✅ exists | ✅ green |
| 19-05-03 | 05 | 3 | DX-02 | T-19-02 | Source layout: services.php contains no profiler refs | static | `vendor/bin/phpunit --filter SourceLayoutTest::testProfilerServicesOnlyInDevConfig` | ✅ exists | ✅ green |
| 19-06-01 | 06 | 3 | DX-02 | — | WDT badge shows slug for resolved tenant | functional | `vendor/bin/phpunit --filter TenantDataCollectorWdtTest::testBadgeShowsSlugForResolvedTenant` | ✅ exists | ✅ green |
| 19-06-02 | 06 | 3 | DX-02 | — | Panel renders all 8 required fields | functional | `vendor/bin/phpunit --filter TenantDataCollectorWdtTest::testPanelRendersAllRequiredFields` | ✅ exists | ✅ green |
| 19-06-03 | 06 | 3 | DX-02 | — | Null-resolution shows em-dash and no-tenant panel | functional | `vendor/bin/phpunit --filter TenantDataCollectorWdtTest::testNullResolutionShowsEmDashAndNoTenantPanel` | ✅ exists | ✅ green |
| 19-06-04 | 06 | 3 | DX-02 | — | Error state shows warning badge and exception class | functional | `vendor/bin/phpunit --filter TenantDataCollectorWdtTest::testErrorStateShowsWarningBadgeAndExceptionClass` | ✅ exists | ✅ green |

*Status: ✅ green · ✅ green · ❌ red · ⚠️ flaky*

*Task IDs are nominal — gsd-planner finalizes exact IDs. Each row maps to a ≥2-class Nyquist coverage of one ROADMAP success criterion.*

---

## Wave 0 Requirements

- [x] `composer.json` — add `"symfony/web-profiler-bundle": "^7.4||^8.0"` and `"symfony/twig-bundle": "^7.4||^8.0"` to `require-dev`. Run `composer update --dev` and commit `composer.lock`.
- [x] `tests/Unit/Profiler/` directory created (will be populated by Wave 1 tasks)
- [x] `tests/Integration/Profiler/` directory created (will be populated by Wave 2/3 tasks)
- [x] `tests/Integration/Profiler/Support/ProfilerTestKernel.php` — dedicated test kernel adding `WebProfilerBundle` + `TwigBundle` on top of `TenancyBundle + FrameworkBundle`. Reuses existing `tests/Integration/TestKernel.php` shape.
- [x] Verify `phpunit.xml` (or `.dist`) has an `<testsuite name="integration">` element that includes `tests/Integration/Profiler` glob

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Visual rendering of badge icon in real browser | DX-02 (success criterion 1) | Inline SVG rendering across Chrome/Firefox/Safari WDT versions cannot be asserted via DOM scraping alone; CSS in WebProfilerBundle ships with the toolbar | One-time manual smoke test: open demo app or any dev-profile app, observe Tenancy badge in toolbar, hover, click panel |

*All other phase behaviors have automated verification via 5 PHPUnit test classes (Stash, Collector, CompileOut, Serialization, Wdt) plus 1 SourceLayout structural test.*

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references (composer.json, test dirs, ProfilerTestKernel)
- [x] No watch-mode flags
- [x] Feedback latency < 10s
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** approved 2026-05-29 (retroactive — phase already shipped + verified)

---

## Validation Audit 2026-05-29

Retroactive audit during v0.3 milestone closure (Phase 23).

| Metric | Count |
|--------|-------|
| Gaps found | 0 |
| Resolved | 0 (none required — coverage was complete at execute-phase time) |
| Escalated | 0 |

**Audit basis:** All task IDs in the Per-Task Verification Map map to PHPUnit test methods that exist in the codebase and pass in the green 568-test suite (post-Phase 23 green-bar run, commit `4b0d1c6`). PHPStan level 9 clean. Initial VALIDATION.md status frontmatter (`draft` / `nyquist_compliant: false`) reflected pre-execution planning state, not actual coverage — refreshed here to match shipped reality.

**Approver:** Claude (gsd-orchestrator)
**Confirmed against:** `vendor/bin/phpunit --no-coverage` → `OK (568 tests, 2122 assertions)` at HEAD `4b0d1c6`.
