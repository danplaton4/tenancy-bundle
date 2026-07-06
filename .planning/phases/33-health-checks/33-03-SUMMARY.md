---
phase: 33-health-checks
plan: 03
subsystem: health
tags: [health-checks, http-controller, route-files, tdd, security, ietf-health-json]
dependency_graph:
  requires:
    - src/Health/TenantHealthCheckerInterface.php (this plan — new interface)
    - src/Health/TenantHealthChecker.php (plan 33-02, implements interface)
    - src/Health/HealthResponseSanitizer.php (plan 33-01)
    - src/Health/TenantHealthReport.php (plan 33-01)
    - src/Health/BootstrapperHealthResult.php (plan 33-01)
    - src/Health/HealthStatus.php (plan 33-01)
    - src/Provider/TenantProviderInterface.php (existing)
    - src/Exception/TenantNotFoundException.php (existing)
  provides:
    - src/Controller/TenantHealthController.php
    - src/Health/TenantHealthCheckerInterface.php
    - config/routes/health.php (live + ready routes)
    - config/routes/health_fleet.php (fleet route, separate per D-02)
    - tests/Unit/Controller/TenantHealthControllerTest.php
  affects:
    - plan 33-05 (wiring pass wires TenantHealthController as a service using TenantHealthCheckerInterface)
tech_stack:
  added: []
  patterns:
    - IETF application/health+json response shape (pass/warn/fail status + checks{} structure)
    - D-05 HTTP status mapping (Pass/Warn=200, Fail=503, unknown slug=404)
    - D-06 TenantNotFoundException catch -> HTTP 404 with fail body
    - D-07 liveness purity — zero dependency I/O, zero tenant iteration
    - D-08 fleet bounded pagination (limit clamped to fleet_max_limit, sequential probing)
    - D-01/D-02 two separate importable PHP-DSL route files
    - TenantHealthCheckerInterface extracted for testability (final class cannot be mocked)
key_files:
  created:
    - src/Controller/TenantHealthController.php
    - src/Health/TenantHealthCheckerInterface.php
    - config/routes/health.php
    - config/routes/health_fleet.php
    - tests/Unit/Controller/TenantHealthControllerTest.php
  modified:
    - src/Health/TenantHealthChecker.php (implements TenantHealthCheckerInterface — additive)
decisions:
  - "TenantHealthCheckerInterface extracted as a separate interface so TenantHealthController can declare a typed dep that PHPUnit can double (TenantHealthChecker is final — same issue as plan 33-02 with BootstrapperChain)"
  - "Controller constructor takes TenantHealthCheckerInterface (not concrete), provider nullable for no-Doctrine lane — returns safe 503 if provider is null"
  - "fleet() includes output field in tenant entry only for non-pass statuses to keep passing entries compact"
  - "buildReadyBody() maps all probe results under a single tenancy:db:{slug} checks key per IETF health+json Pattern 5"
metrics:
  duration: "~7 minutes"
  completed: "2026-07-06T07:25:41Z"
  tasks_completed: 2
  files_created: 5
  files_modified: 1
  tests_added: 17
---

# Phase 33 Plan 03: HTTP Controller + Route Files Summary

IETF application/health+json HTTP surface for the bundle: TenantHealthController with live/ready/fleet actions, TenantHealthCheckerInterface for testability, and two separate importable PHP-DSL route files (config/routes/health.php for live+ready, config/routes/health_fleet.php for fleet — D-02). Every response body sanitized; zero raw DSNs reach the wire.

## Tasks Completed

| Task | Name | Commit | Files Created/Modified |
|------|------|--------|----------------------|
| 1 | TenantHealthController live+ready + TenantHealthCheckerInterface | 7c267a6 | TenantHealthController.php, TenantHealthCheckerInterface.php, TenantHealthChecker.php, TenantHealthControllerTest.php |
| 2 | Fleet action + two route files | bf7e33d | config/routes/health.php, config/routes/health_fleet.php |

## Verification Results

- `vendor/bin/phpunit --filter TenantHealthControllerTest` — 17 tests, 64 assertions, 0 failures
- `vendor/bin/phpunit --testsuite unit` — 760 tests, 2048 assertions, 2 skipped, 0 failures
- `vendor/bin/phpunit` (full suite) — 926 tests, 3693 assertions, 2 skipped, 0 failures
- `vendor/bin/phpstan analyse --memory-limit=512M` — [OK] No errors (level 9)
- `vendor/bin/php-cs-fixer check --diff` — clean (@Symfony ruleset)
- `grep -c "application/health+json" src/Controller/TenantHealthController.php` — 4 (GOOD)
- `grep "extends AbstractController" src/Controller/TenantHealthController.php` — empty (GOOD)
- `grep -q "tenancy_health_live" config/routes/health.php` — found (GOOD)
- `grep -q "tenancy_health_fleet" config/routes/health_fleet.php` — found (GOOD)
- `grep "tenancy_health_fleet" config/routes/health.php` — empty (GOOD — D-02 separation)
- `php -l config/routes/health.php` — No syntax errors
- `php -l config/routes/health_fleet.php` — No syntax errors
- `grep "^use.*EntityManager\|^use.*Doctrine" src/Controller/TenantHealthController.php` — empty (no Doctrine imports)

## Deviations from Plan

**1. [Rule 1 - Bug] TenantHealthChecker is final — cannot mock for TenantHealthControllerTest**

- **Found during:** Task 1 RED phase — PHPUnit threw `ClassIsFinalException`
- **Issue:** The plan called for mocking `TenantHealthChecker` in controller unit tests, but the class is `final` and cannot be doubled by PHPUnit
- **Fix:** Extracted `TenantHealthCheckerInterface` (additive, no BC break) so the controller declares its dependency on the interface. `TenantHealthChecker` now implements the interface. Controller and tests use the interface type. This is the same pattern as the plan 33-02 BootstrapperChain/final-class issue.
- **Files modified:** src/Health/TenantHealthCheckerInterface.php (new), src/Health/TenantHealthChecker.php (implements interface — additive), src/Controller/TenantHealthController.php (uses interface), tests/Unit/Controller/TenantHealthControllerTest.php (mocks interface)
- **Impact:** The interface is minimal (one method: `checkOne()`). Plan 33-05 (DI wiring) should wire `TenantHealthCheckerInterface` → `TenantHealthChecker` concrete.

## Must-Have Verification

All five truths from the plan's `must_haves`:

| Truth | Status |
|-------|--------|
| GET /_tenancy/health/live returns HTTP 200 {"status":"ok"} with zero tenant iteration and zero DB I/O | PROVEN (testLivenessCallsCheckerZeroTimes: expects($this->never())->method('checkOne') passes; testLivenessReturnsHttp200WithOkStatus: body['status'] === 'ok') |
| GET /_tenancy/health/ready/{slug} returns application/health+json HTTP 200 (pass/warn) or 503 (fail); an unknown slug returns HTTP 404 with status:fail body | PROVEN (testReadinessReturnsHttp200ForPassingTenant, testReadinessReturnsHttp503ForFailingTenant, testReadinessReturnsHttp404ForUnknownSlug — all passing) |
| The fleet endpoint returns bounded pages (limit/offset, clamped to hard max) with per-tenant statuses + rollup summary + total, and is not a k8s probe target | PROVEN (testFleetClampsLimitToMaxLimit: ?limit=500 → exactly 200 checker calls; testFleetBodyContainsRequiredKeys: all required keys present; testFleetAlwaysReturnsHttp200: failing tenant still returns 200) |
| Every health response body is passed through HealthResponseSanitizer before serialization (no raw DSN in output) | PROVEN (testReadinessRedactsDsnInResponseBody and testFleetRedactsDsnInResponseBody: s3cr3t absent, *** present) |
| live+ready and fleet ship as two SEPARATE importable route files | PROVEN (config/routes/health.php contains tenancy_health_live; config/routes/health_fleet.php contains tenancy_health_fleet; fleet route absent from health.php) |

All artifacts from the plan's `must_haves`:

| Artifact | Status |
|----------|--------|
| src/Controller/TenantHealthController.php (contains "application/health+json") | EXISTS, confirmed 4 occurrences |
| config/routes/health.php (contains "tenancy_health_live") | EXISTS, confirmed |
| config/routes/health_fleet.php (contains "tenancy_health_fleet") | EXISTS, confirmed |

All key_links verified:

| Link | Status |
|------|--------|
| config/routes/health.php → TenantHealthController::class references | PRESENT (both live and ready controller refs) |
| TenantHealthController → checker->checkOne() | PRESENT (ready() and fleet() both delegate to checker->checkOne()) |
| TenantHealthController → sanitizer->sanitizeArray() | PRESENT (all three action paths call sanitizeArray before JsonResponse) |

## Threat Surface Scan

All threats in the plan's `<threat_model>` are mitigated:

| Threat | Mitigation Status |
|--------|-------------------|
| T-33-04 Information Disclosure (DSN in body) | MITIGATED — sanitizeArray() called before every JsonResponse; unit tests assert 's3cr3t' absent |
| T-33-ENUM Information Disclosure (fleet roster) | ACCEPTED — fleet is separate importable route (D-02); operators opt-in to disclosure |
| T-33-LIVE-DOS DoS via liveness I/O | MITIGATED — live() has zero checker/provider calls (unit test asserts never()) |
| T-33-FLEET-DOS DoS via unbounded fleet | MITIGATED — limit clamped to fleet_max_limit; unit test asserts ?limit=500 → exactly 200 calls |
| T-33-SLUG Input Validation | MITIGATED — slug route requirement [a-z0-9\-]+ in health.php; TenantNotFoundException → 404 |

## Known Stubs

None — all implementations are complete behavioral code. No hardcoded placeholders or TODOs in any created/modified file.

## Self-Check: PASSED

Created files confirmed to exist:
- [x] src/Controller/TenantHealthController.php
- [x] src/Health/TenantHealthCheckerInterface.php
- [x] config/routes/health.php
- [x] config/routes/health_fleet.php
- [x] tests/Unit/Controller/TenantHealthControllerTest.php

Modified files confirmed:
- [x] src/Health/TenantHealthChecker.php — implements TenantHealthCheckerInterface added

Commits verified in git log:
- [x] 7c267a6 feat(33-03): TenantHealthController — live/ready/fleet actions + TenantHealthCheckerInterface
- [x] bf7e33d feat(33-03): two importable route files — health.php (live+ready) and health_fleet.php (fleet)
