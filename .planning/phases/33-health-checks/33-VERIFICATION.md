---
phase: 33-health-checks
verified: 2026-07-06T00:00:00Z
status: passed
score: 6/6 must-haves verified
overrides_applied: 0
---

# Phase 33: Health Checks Verification Report

**Phase Goal:** Operators can probe per-tenant connectivity and bootstrapper health via HTTP endpoints and a CLI command, with optional LiipMonitorBundle auto-registration, and health responses never expose DSNs or credentials
**Verified:** 2026-07-06
**Status:** passed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths (ROADMAP Success Criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | `GET /_tenancy/health/live` returns HTTP 200 `{"status":"ok"}` immediately without iterating tenants; fast enough for per-second liveness probes | VERIFIED | `live()` in `TenantHealthController` returns `new JsonResponse(['status' => 'ok'], 200, ...)` with zero I/O. Unit test asserts checker and provider receive 0 calls. `HealthEndpointsIntegrationTest` confirms 200 end-to-end. |
| 2 | `GET /_tenancy/health/ready/{slug}` returns IETF `application/health+json` with HTTP 200 (pass/warn) or HTTP 503 (fail); `TenantHealthChecker` sets `TenantContext` manually, runs probes, and clears it in a `finally` block — `boot()` is never called; `TenantContext::hasTenant()` is `false` after the probe completes | VERIFIED | `TenantHealthChecker::checkOne()` calls `setTenant()` then `try { healthCheck() } catch { fromException } finally { clear() }` — `boot()` never called. `TenantHealthCheckerProbeTest` (3 tests) asserts `hasTenant() === false` after success, failure, and exception paths. Controller maps Pass/Warn → 200, Fail → 503 via `mapStatusToHttpCode()`. `Content-Type: application/health+json` on all responses. |
| 3 | A bootstrapper implementing `HealthCheckBootstrapperInterface` exposes a read-only `check()` probe that is called by `TenantHealthChecker` without triggering `boot()`/`clear()` side effects | VERIFIED | `HealthCheckBootstrapperInterface` does NOT extend `TenantBootstrapperInterface` (zero BC break). `BootstrapperChain::healthCheck()` iterates bootstrappers, skips non-implementors, and catches probe exceptions — dispatches NO events. `DatabaseSwitchBootstrapper` and `SharedDriver` both implement the interface with `SELECT 1` probes. `BootstrapperChainHealthCheckTest` (11 tests) asserts zero event dispatches and exactly-implementor filtering. |
| 4 | Health responses never contain raw DSN strings or credentials; a DSN injected into any bootstrapper exception message is redacted by `HealthResponseSanitizer` before it reaches the response body | VERIFIED | CR-01 fix: `DsnSanitizer::REDACTION_REGEX` widened to `#(://[^:/@]*:)[^@]+(@)#` — username optional (`[^:/@]*`), password group `[^@]+` (terminated only at `@`). `HealthResponseSanitizer` reuses the constant (no duplication). `HealthResponseSanitizerTest` (14 tests) includes CR-01 regression tests for `redis://:s3cr3tpw@host` (password-only) and `mysql://user:pa/ss@host` (slash-containing password) — both redact to `***`. All controller/command output paths run through `sanitizeArray()` / `sanitize()` before serialization. |
| 5 | `tenancy:health [--tenant=<slug>\|--all]` reports per-tenant health from the CLI; an aggregate fleet endpoint summarizes all tenants for dashboard use (explicitly not a k8s probe target) | VERIFIED | `TenantHealthCommand` exists with `--tenant`, `--all`, `--format` options. `--all` streams per-tenant lines with exit-code aggregation (non-zero on any fail). `--format=json` emits single parseable object `{tenants:[...], summary:{pass,warn,fail,total}}`. `TenantHealthCommandTest` (15 tests) covers all branches including DSN redaction in both formats. Fleet endpoint `fleet()` returns always-HTTP-200 with bounded pagination (limit clamped to `fleet_max_limit=200`). |
| 6 | When `liip/monitor-bundle` is installed, bundle health checks auto-register as `liip_monitor.check` services (guarded by `class_exists`); absent the bundle, the self-contained endpoints and command work unchanged | VERIFIED | `HealthCheckIntegrationPass` guards via `interface_exists(\Laminas\Diagnostics\Check\CheckInterface::class)` then also guards on `tenancy.provider` existence (CR-03 fix). `TenantConnectivityCheck` delegates entirely to `TenantHealthCheckerInterface::checkOne()` without touching `TenantContext`. `liip/monitor-bundle` in `require-dev` + `suggest`. `HealthCheckIntegrationPassTest` (7 tests) covers positive path, provider-absent no-op, alias provider, empty-container safety. `HealthChecksNoLiipTest` proves self-contained surface works without liip. |

**Score: 6/6 truths verified**

---

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `src/Health/HealthStatus.php` | Backed string enum Pass/Warn/Fail | VERIFIED | `enum HealthStatus: string` with cases `Pass='pass'`, `Warn='warn'`, `Fail='fail'` |
| `src/Health/HealthCheckBootstrapperInterface.php` | Sibling probe interface — does NOT extend `TenantBootstrapperInterface` | VERIFIED | Declares `check(TenantInterface $tenant): BootstrapperHealthResult`; zero extends |
| `src/Health/BootstrapperHealthResult.php` | Per-component VO with named constructors | VERIFIED | `final readonly class` with `pass()`, `fail()`, `fromException()` named constructors |
| `src/Health/TenantHealthReport.php` | Per-tenant aggregate VO with `private static worstOf()` | VERIFIED | `final readonly class`; `worstOf()` is `private static` at line 73; `fromResults()` and `fromException()` constructors |
| `src/Health/HealthResponseSanitizer.php` | DSN scrubbing reusing `DsnSanitizer::REDACTION_REGEX` | VERIFIED | Delegates to `DsnSanitizer::REDACTION_REGEX` + `::REPLACEMENT` (line 35–37); no duplicated pattern |
| `src/Health/TenantHealthChecker.php` | set→probe→clear-in-finally, never calls `boot()` | VERIFIED | `finally { $this->tenantContext->clear(); }` present; `->boot(` absent from method body |
| `src/Health/TenantHealthCheckerInterface.php` | Contract interface for checker (testability seam) | VERIFIED | Interface exists; `TenantHealthChecker` implements it; controller and command typed against interface |
| `src/Bootstrapper/BootstrapperChain.php` | Additive `healthCheck()` method; `boot()`/`clear()` untouched | VERIFIED | `function healthCheck(TenantInterface $tenant): array` added; `boot()` and `clear()` bodies unchanged |
| `src/Bootstrapper/DatabaseSwitchBootstrapper.php` | Implements `HealthCheckBootstrapperInterface`, `check()` does `close()+SELECT 1` | VERIFIED | `implements TenantDriverInterface, HealthCheckBootstrapperInterface`; probe at lines 53–63 |
| `src/Driver/SharedDriver.php` | Implements `HealthCheckBootstrapperInterface`, `check()` does `SELECT 1` | VERIFIED | `implements TenantDriverInterface, HealthCheckBootstrapperInterface`; probe at lines 53–63 |
| `src/Controller/TenantHealthController.php` | live/ready/fleet, `application/health+json`, no `AbstractController` | VERIFIED | `final class`; `CONTENT_TYPE = 'application/health+json'`; does not extend AbstractController |
| `config/routes/health.php` | Contains `tenancy_health_live` and `tenancy_health_ready`; NOT fleet | VERIFIED | Routes `tenancy_health_live` and `tenancy_health_ready` defined; `tenancy_health_fleet` absent |
| `config/routes/health_fleet.php` | Contains `tenancy_health_fleet` only | VERIFIED | Route `tenancy_health_fleet` defined; separate file per D-02 |
| `src/Command/TenantHealthCommand.php` | `tenancy:health`, no `TenantContext` dependency | VERIFIED | `#[AsCommand(name: 'tenancy:health')]`; constructor has no `TenantContext`; comment reference in docblock only |
| `src/DependencyInjection/Compiler/HealthCheckIntegrationPass.php` | `interface_exists` guard + `tenancy.provider` guard (CR-03) | VERIFIED | Both guards at lines 62 and 70 |
| `src/Health/Liip/TenantConnectivityCheck.php` | Implements Laminas `CheckInterface`; no `TenantContext` direct use | VERIFIED | `implements CheckInterface`; `TenantContext` appears only in comments |
| `config/services.php` | `tenancy.health.controller` (public) + `tenancy.command.health` (console.command tag) | VERIFIED | Lines 298–317; `->public()` on controller; `->tag('console.command')` on command |

### Key Link Verification

| From | To | Via | Status | Details |
|------|-----|-----|--------|---------|
| `src/Health/HealthResponseSanitizer.php` | `src/Mailer/DsnSanitizer.php` | `DsnSanitizer::REDACTION_REGEX` constant | VERIFIED | Line 35 uses the constant; no duplicated regex literal |
| `src/Health/TenantHealthChecker.php` | `src/Bootstrapper/BootstrapperChain.php` | `bootstrapperChain->healthCheck()` | VERIFIED | Line 48; `boot()` never called |
| `src/Health/TenantHealthChecker.php` | `src/Context/TenantContext.php` | `setTenant()` + `clear()` in finally | VERIFIED | Lines 45 and 55 |
| `config/services.php` | `src/Health/TenantHealthChecker.php` | `tenancy.health.checker` service | VERIFIED | Lines 284–290; wired with context + chain args |
| `config/routes/health.php` | `src/Controller/TenantHealthController.php` | `[TenantHealthController::class, 'live'|'ready']` | VERIFIED | Lines 35, 41 of route file |
| `src/Controller/TenantHealthController.php` | `src/Health/TenantHealthChecker.php` | `checker->checkOne()` | VERIFIED | Lines 122, 175 |
| `src/Controller/TenantHealthController.php` | `src/Health/HealthResponseSanitizer.php` | `sanitizeArray()` on every response body | VERIFIED | Lines 96, 107, 114, 124, 156, 192 |
| `src/TenancyBundle.php` | `src/DependencyInjection/Compiler/HealthCheckIntegrationPass.php` | `addCompilerPass()` in `build()` | VERIFIED | Line 482 |
| `src/Health/Liip/TenantConnectivityCheck.php` | `src/Health/TenantHealthChecker.php` | `checker->checkOne()` per tenant | VERIFIED | Line 72 |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|-------------------|--------|
| `TenantHealthController::ready()` | `$report` | `TenantHealthChecker::checkOne()` → `BootstrapperChain::healthCheck()` → DB `SELECT 1` | Yes — live DBAL query | FLOWING |
| `TenantHealthController::fleet()` | `$allTenants` | `TenantProviderInterface::findAll()` → DoctrineTenantProvider (DB query) | Yes — Doctrine query | FLOWING |
| `TenantHealthCommand::executeTxt()` | `$report` | same `checkOne()` path | Yes | FLOWING |
| `TenantConnectivityCheck::check()` | `$tenants` | `TenantProviderInterface::findAll()` | Yes | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Unit test suite | `vendor/bin/phpunit --testsuite unit` | 789 tests, 0 failures, 2 skipped | PASS |
| Integration test suite | `vendor/bin/phpunit --testsuite integration` | 177 tests, 0 failures | PASS |
| Full suite | `vendor/bin/phpunit` | 966 tests, 0 failures, 2 skipped | PASS |
| PHPStan L9 | `vendor/bin/phpstan analyse --memory-limit=512M` | No errors | PASS |
| php-cs-fixer | `vendor/bin/php-cs-fixer check --diff` | 0 files to fix | PASS |
| Probe safety tests | `vendor/bin/phpunit --filter TenantHealthCheckerProbeTest` | 3/3 pass | PASS |
| HTTP integration | `vendor/bin/phpunit --filter "HealthEndpointsIntegrationTest\|HealthChecksNoLiipTest"` | 11/11 pass | PASS |
| CR-01 sanitizer regression | `vendor/bin/phpunit --filter HealthResponseSanitizerTest` | 14/14 pass (includes redis://:pw@ and mysql://u:pa/ss@) | PASS |
| CR-02 inactive tenant | `vendor/bin/phpunit --filter TenantHealthControllerTest` | 19/19 pass (includes inactive-tenant 503 path) | PASS |
| CR-03 liip+no-Doctrine | `vendor/bin/phpunit --filter HealthCheckIntegrationPassTest` | 7/7 pass (includes provider-absent no-op) | PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|----------|
| HEALTH-01 | 33-03, 33-05 | Liveness endpoint — zero tenant I/O | SATISFIED | `live()` returns `{"status":"ok"}` immediately; unit test asserts 0 checker/provider calls; integration test confirms 200 |
| HEALTH-02 | 33-02, 33-03 | Per-tenant readiness with `application/health+json`, 200/503/404 | SATISFIED | `ready()` maps Pass/Warn→200, Fail→503, unknown→404, inactive→503 (CR-02); IETF shape with `checks{}` |
| HEALTH-03 | 33-01, 33-02 | `HealthCheckBootstrapperInterface` sibling probe; `TenantHealthChecker` never calls `boot()`; finally-clear | SATISFIED | Sibling interface confirmed; `finally` at line 53 in checker; `boot()` absent from `checkOne()`; both drivers implement interface |
| HEALTH-04 | 33-01, 33-03, 33-04, 33-05 | Health responses never expose DSNs — `HealthResponseSanitizer` redacts all shapes | SATISFIED | CR-01 widens regex; all response paths sanitized; 14 unit tests including password-only and slash-containing DSN regressions |
| HEALTH-05 | 33-04, 33-05 | `tenancy:health [--tenant\|--all] [--format=json]` CLI command | SATISFIED | Command exists; all option branches tested (15 unit tests); exit-code aggregation; JSON single-object output |
| HEALTH-06 | 33-03, 33-05 | Fleet endpoint — bounded, always HTTP 200, not a k8s probe target | SATISFIED | `fleet()` clamps limit to `fleet_max_limit`; always returns 200; catches `findAll()` exceptions (WR-01 fix) |
| HEALTH-07 | 33-05 | Liip auto-registration when installed; no-op when absent | SATISFIED | `interface_exists` + `tenancy.provider` double guard; `liip/monitor-bundle` in require-dev + suggest; `HealthChecksNoLiipTest` proves no-liip lane |

### Anti-Patterns Found

No blockers found. The single "available" grep match in `TenantHealthCommand.php` line 80 is the no-provider error message string `'...installed and configured.'` — not a placeholder or debt marker.

| File | Pattern | Severity | Assessment |
|------|---------|----------|------------|
| No files | TBD/FIXME/XXX | — | None found in any phase-modified file |
| No files | TODO/HACK/PLACEHOLDER | — | None found (line 80 of TenantHealthCommand is an error-message string, not a stub) |

### Code-Review Blockers Resolved

All three BLOCKER-class issues identified in 33-REVIEW.md are verified resolved:

| Issue | Fix Commit | Verified In Code |
|-------|-----------|-----------------|
| CR-01: DSN regex did not redact password-only (`redis://:pw@`) or slash-containing passwords | `3223f77` | `DsnSanitizer::REDACTION_REGEX = '#(://[^:/@]*:)[^@]+(@)#'`; 3 regression tests in `HealthResponseSanitizerTest` (lines 57–88) |
| CR-02: `ready()` did not catch `TenantInactiveException` — inactive tenant returned stock 403 | `dc0bcef` | `catch (TenantInactiveException)` at line 113 in `TenantHealthController`; maps to sanitized 503 health+json body |
| CR-03: `HealthCheckIntegrationPass` hard-referenced `tenancy.provider` without guarding — liip+no-Doctrine broke compilation | `5172e4c` | Double guard at lines 62 + 70 in `HealthCheckIntegrationPass`; `HealthCheckIntegrationPassTest::testDoesNotRegisterWhenProviderAbsent()` covers the negative path |

### Human Verification Required

None. All phase success criteria are verifiable programmatically and all automated checks pass.

### Gaps Summary

No gaps. All six roadmap success criteria are satisfied in the codebase. All seven requirement IDs (HEALTH-01 through HEALTH-07) are satisfied. The three code-review blockers (CR-01/CR-02/CR-03) and the two warnings (WR-01/WR-02) are resolved in code with passing tests. The full suite (966 tests, 0 failures, 2 skipped), PHPStan L9, and php-cs-fixer all pass cleanly.

---

_Verified: 2026-07-06_
_Verifier: Claude (gsd-verifier)_
