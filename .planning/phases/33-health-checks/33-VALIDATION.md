---
phase: 33
slug: health-checks
status: complete
nyquist_compliant: true
wave_0_complete: true
created: 2026-07-02
updated: 2026-07-06
---

# Phase 33 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Derived from `33-RESEARCH.md` §"Validation Architecture". Per-task map completed
> at execution time and audited post-execution (2026-07-06).

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11 (unit + integration suites) |
| **Config file** | `phpunit.xml.dist` |
| **Quick run command** | `vendor/bin/phpunit --testsuite unit` |
| **Full suite command** | `vendor/bin/phpunit` |
| **Estimated runtime** | ~10 seconds (SQLite `:memory:` integration) |
| **Full-suite result (2026-07-06)** | 966 tests, 3821 assertions, 2 skipped, 0 failures; PHPStan L9 clean; cs-fixer clean |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit --testsuite unit`
- **After every plan wave:** Run `vendor/bin/phpunit`
- **Before `/gsd:verify-work`:** Full suite must be green + `vendor/bin/phpstan analyse --memory-limit=512M` clean
- **Max feedback latency:** ~10 seconds

---

## Per-Requirement Verification Map

> Audited 2026-07-06 against the executed test suite. Every phase requirement is
> covered by at least one automated, green test.

| Requirement | Secure/Expected Behavior | Test Type | Covering Test(s) | Automated Command | Status |
|-------------|--------------------------|-----------|------------------|-------------------|--------|
| HEALTH-01 | `live()` returns 200 `{"status":"ok"}` with zero checker/provider/tenant I/O (D-07) | unit + integration | `TenantHealthControllerTest::testLiveness*` (3); `HealthEndpointsIntegrationTest::testLiveness*` | `vendor/bin/phpunit --filter 'TenantHealthControllerTest\|HealthEndpointsIntegrationTest'` | ✅ green |
| HEALTH-02 | `ready()`/`checkOne()` set→probe→clear-in-`finally`, never `boot()`; `hasTenant()` false after probe; 200/503/404/503-inactive mapping | unit + integration | `TenantHealthCheckerTest` (6); `TenantHealthControllerTest::testReadiness*`; `TenantHealthCheckerProbeTest` (3) | `vendor/bin/phpunit --filter 'TenantHealthChecker\|TenantHealthControllerTest'` | ✅ green |
| HEALTH-03 | Sibling `HealthCheckBootstrapperInterface` (no BC break); read-only `check()` probe called by additive `BootstrapperChain::healthCheck()`; per-probe try/catch; no events | unit | `BootstrapperChainHealthCheckTest` (5) | `vendor/bin/phpunit --filter BootstrapperChainHealthCheckTest` | ✅ green |
| HEALTH-04 | No raw DSN/credential in any output path; `HealthResponseSanitizer` reuses `DsnSanitizer::REDACTION_REGEX` (incl. CR-01 password-only + slash-password shapes) | unit | `HealthResponseSanitizerTest` (14, incl. CR-01 regressions); `TenantHealthControllerTest::testReadinessRedactsDsn*`/`testFleetRedactsDsn*`; `TenantHealthCommandTest` DSN-redaction cases | `vendor/bin/phpunit --filter 'HealthResponseSanitizerTest\|Redacts'` | ✅ green |
| HEALTH-05 | `tenancy:health [--tenant\|--all] [--format=json]`; per-tenant streaming; exit-code aggregation; sanitized output; delegates lifecycle to `checkOne()` | unit | `TenantHealthCommandTest` (15) | `vendor/bin/phpunit --filter TenantHealthCommandTest` | ✅ green |
| HEALTH-06 | Fleet aggregate always 200 (D-08), bounded by clamped limit/offset, sanitized body, separate route file; always-200 holds when `findAll()` throws (WR-01) | unit + integration | `TenantHealthControllerTest::testFleet*` (incl. `testFleetReturnsHttp200WhenFindAllThrows`, `testFleetClampsLimitToMaxLimit`); `HealthEndpointsIntegrationTest::testFleetEndpoint*` | `vendor/bin/phpunit --filter 'testFleet\|HealthEndpointsIntegrationTest'` | ✅ green |
| HEALTH-07 | Optional liip: `class_exists`/`interface_exists` + `tenancy.provider` guard (CR-03); auto-register when present, no-op + self-contained surface when absent | unit + integration | `HealthCheckIntegrationPassTest` (7, incl. provider-absent + alias); `HealthChecksNoLiipTest` (5) | `vendor/bin/phpunit --filter 'HealthCheckIntegrationPassTest\|HealthChecksNoLiipTest'` | ✅ green |

Supporting value-object coverage: `BootstrapperHealthResultTest` (18), `TenantHealthReportTest` (11).

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [x] Integration test proving `DatabaseSwitchBootstrapper::check()` under a manual `TenantContext::setTenant()` leaves `TenantContext::hasTenant() === false` after the probe and the next real request reconnects cleanly (Success Criterion 2 / research probe-safety flag) — two SQLite tenants. → **`tests/Integration/Health/TenantHealthCheckerProbeTest.php`** (3 tests: false-after-success, false-after-failure, clean reconnect to tenant B).
- [x] Integration test proving a DSN injected into a bootstrapper exception message is redacted by `HealthResponseSanitizer` before it reaches the response body (HEALTH-04, no-DSN-leak). → **`tests/Unit/Health/HealthResponseSanitizerTest.php`** + response-path assertions in `TenantHealthControllerTest`/`TenantHealthCommandTest` (raw password never present, `***` present).
- [x] Test lane proving self-contained endpoints + `tenancy:health` work with `liip/monitor-bundle` ABSENT, and the `liip_monitor.check` auto-registration fires when it is PRESENT (HEALTH-07, class_exists guard both directions). → **`tests/Integration/Health/HealthChecksNoLiipTest.php`** (absent) + **`tests/Unit/DependencyInjection/Compiler/HealthCheckIntegrationPassTest.php`** (present + provider guard).

All Wave 0 requirements satisfied.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Per-second LB/k8s liveness probe latency | HEALTH-01 | Real-time probe cadence is an operational property, not a unit assertion | Curl `GET /_tenancy/health/live` in a loop; confirm sub-ms 200 `{"status":"ok"}` with no tenant iteration |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency < 60s
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** approved — 7/7 requirements automated-covered, all Wave 0 met, full suite green (2026-07-06). One documented manual-only item (liveness probe cadence, operational).
