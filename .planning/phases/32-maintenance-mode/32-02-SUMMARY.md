---
phase: 32-maintenance-mode
plan: "02"
subsystem: maintenance-mode
tags: [maintenance, listener, kernel-request, content-negotiation, allow-list, twig, unit-test]
dependency_graph:
  requires:
    - TenantInterface::isInMaintenance() bool contract (plan 32-01)
    - TenantContext::hasTenant() / getTenant() API (existing)
    - MaintenanceModeContractPass priority guard (plan 32-01)
  provides:
    - TenantMaintenanceModeListener (kernel.request priority 16)
    - PRIORITY = 16 constant (for MaintenanceModeContractPass to reference in 32-04)
    - Unit test coverage for MAINT-03/04/05/06/07
  affects:
    - 32-04 (wiring: constructor arg order recorded below)
tech_stack:
  added: []
  patterns:
    - AsEventListener const-based priority (mirrors TenantContextOrchestrator)
    - setResponse() never-throw pattern (different from TenantInactiveException throw pattern)
    - IpUtils::checkIp for CIDR allow-list (Symfony stdlib, D-07)
    - Twig render with try/catch \Throwable fallback to hardcoded HTML (D-01/D-02)
    - Content negotiation via getAcceptableContentTypes() (D-04)
key_files:
  created:
    - src/EventListener/TenantMaintenanceModeListener.php
    - tests/Unit/EventListener/TenantMaintenanceModeListenerTest.php
  modified: []
decisions:
  - "Listener constructor arg order (plan 32-04 MUST wire in this order): TenantContext, int retryAfter, ?string template, array allowIps, array allowRoutes, array allowPaths, ?Environment twig"
  - "Service ID: tenancy.maintenance.listener (locked in plan 32-01, confirmed here)"
  - "getTenant() null guard added at maintenance-gate step (null === $tenant || !$tenant->isInMaintenance()) so PHPStan L9 is satisfied — hasTenant() is the real guard but PHPStan needs explicit null check on the getTenant() return"
  - "TenantInterface $tenant passed through buildMaintenanceResponse and renderHtml rather than re-read from context, enabling PHPStan to track non-null type"
metrics:
  duration: "~5 min"
  completed: "2026-07-01"
  tasks_completed: 2
  files_changed: 2
---

# Phase 32 Plan 02: Maintenance Mode Listener Summary

Built the `kernel.request` priority-16 listener that enforces maintenance mode: 503 + Retry-After + Cache-Control: no-store for maintenance tenants, with allow-list bypass, content-negotiated JSON/HTML body, and Twig-with-hardcoded-HTML-fallback rendering.

## One-liner

Priority-16 kernel.request listener that 503s maintenance tenants with content-negotiated body, IP/route/path allow-list bypass, and an unkillable hardcoded-HTML default path (Twig optional, Throwable-guarded).

## Tasks Completed

| Task | Description | Commit | Files |
|------|-------------|--------|-------|
| 1 (TDD) | TenantMaintenanceModeListener at priority 16 + unit tests (RED → GREEN in single commit) | 1954582 | src/EventListener/TenantMaintenanceModeListener.php, tests/Unit/EventListener/TenantMaintenanceModeListenerTest.php |

Note: Tasks 1 and 2 are merged — Task 2 (test file) was written first (TDD RED), but the pre-commit hook runs the full suite, so both files were committed together once the implementation made tests green.

## Listener Implementation

**Registered:** `#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: self::PRIORITY)]`

**Priority:** `public const PRIORITY = 16` — after TenantContextOrchestrator (20), before Security firewall (8)

**Constructor argument order (for plan 32-04 service wiring — MUST match exactly):**

```php
public function __construct(
    private readonly TenantContext $tenantContext,      // arg 0
    private readonly int $retryAfter,                   // arg 1 — param('tenancy.maintenance.retry_after')
    private readonly ?string $template,                 // arg 2 — param('tenancy.maintenance.template')
    private readonly array $allowIps,                   // arg 3 — param('tenancy.maintenance.allow_ips')
    private readonly array $allowRoutes,                // arg 4 — param('tenancy.maintenance.allow_routes')
    private readonly array $allowPaths,                 // arg 5 — param('tenancy.maintenance.allow_paths')
    private readonly ?Environment $twig,                // arg 6 — service('twig')->nullOnInvalid()
)
```

**Service ID:** `tenancy.maintenance.listener` (locked in plan 32-01)

**Check order in `onKernelRequest()`:**
1. `!$event->isMainRequest()` → return (sub-requests)
2. `!$this->tenantContext->hasTenant()` → return (MAINT-04 landlord/public bypass)
3. `$this->isAllowListed($request)` → return (MAINT-06)
4. `null === $tenant || !$tenant->isInMaintenance()` → return
5. `$event->setResponse($this->buildMaintenanceResponse($request, $tenant))`

## Unit Test Coverage

**File:** `tests/Unit/EventListener/TenantMaintenanceModeListenerTest.php`

**19 test methods covering:**

| Test | Requirement |
|------|-------------|
| testPriorityConstantIs16 | Contract |
| testSubRequestIsIgnored | MAINT-04 |
| testNullTenantRequestIsIgnored | MAINT-04 |
| testTenantNotInMaintenanceIsPassedThrough | MAINT-03 negative |
| testMaintenanceTenantReturns503WithRequiredHeaders | MAINT-03 |
| testJsonRequestReturnsJsonBody | MAINT-03 JSON |
| testHtmlRequestReturnsHtmlBody | MAINT-03 HTML |
| testJsonAndHtmlBranchesHaveIdenticalStatusAndHeaders | MAINT-03 |
| testAllowedIpBypassesMaintenance | MAINT-06 IP |
| testAllowedCidrRangeBypassesMaintenance | MAINT-06 CIDR |
| testNonAllowedIpDoesNotBypassMaintenance | MAINT-06 IP negative |
| testAllowedRouteBypassesMaintenance | MAINT-06 route |
| testNonAllowedRouteDoesNotBypassMaintenance | MAINT-06 route negative |
| testAllowedPathPrefixBypassesMaintenance | MAINT-06 path |
| testNonAllowedPathDoesNotBypassMaintenance | MAINT-06 path negative |
| testTwigTemplateRendersWhenConfigured | MAINT-07 |
| testTwigRenderExceptionFallsBackToHardcodedHtml | MAINT-07 D-02 |
| testCrossTenantIsolationTenantADoesNotAffectTenantB | MAINT-05 isolation |
| testPragmaAndCacheControlHeadersAreSetOnJsonResponse | T-32-06 CDN defense |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] PHPStan L9: getTenant() returns ?TenantInterface — method.nonObject on isInMaintenance()**
- **Found during:** Task 1 implementation (PHPStan check)
- **Issue:** `$tenant = $this->tenantContext->getTenant()` returns `?TenantInterface`. Calling `$tenant->isInMaintenance()` without explicit null check fails PHPStan level 9 even though `hasTenant()` was asserted above.
- **Fix:** Added `null === $tenant ||` guard at the maintenance-gate check: `if (null === $tenant || !$tenant->isInMaintenance())`. Refactored `$tenant` to flow through `buildMaintenanceResponse(Request $request, TenantInterface $tenant)` and `renderHtml(TenantInterface $tenant)` so PHPStan tracks the non-null type throughout.
- **Files modified:** `src/EventListener/TenantMaintenanceModeListener.php`
- **Impact:** None on runtime behavior (getTenant() is always non-null when hasTenant() returned true); PHPStan is now satisfied.

**2. [Rule 1 - Bug] Pre-commit hook blocks RED commit — TDD cycle adapted**
- **Found during:** TDD RED phase
- **Issue:** The pre-commit hook runs the full PHPUnit suite. Writing the test (RED) first and committing while the listener doesn't exist causes hook failure. The plan's TDD requirement cannot be satisfied with separate RED/GREEN commits when the hook is active.
- **Fix:** Implemented the listener immediately after confirming RED (19 tests fail), then committed both files together once GREEN (19 pass). The TDD RED→GREEN cycle was completed in-session; the commit represents the GREEN state.
- **Commit:** `1954582` — both `src/EventListener/TenantMaintenanceModeListener.php` and `tests/Unit/EventListener/TenantMaintenanceModeListenerTest.php`

## Verification

- `vendor/bin/phpunit tests/Unit/EventListener/TenantMaintenanceModeListenerTest.php`: **19 tests, 56 assertions — OK**
- `vendor/bin/phpunit --testsuite unit`: **674 tests, 1826 assertions, 2 skipped — OK**
- `vendor/bin/phpstan analyse --memory-limit=512M`: **OK, no errors (level 9)**
- `vendor/bin/php-cs-fixer check --diff`: **clean**

## Known Stubs

None — no placeholder text or mock data flows to UI. The hardcoded HTML default is the actual production fallback by design (D-01).

## Threat Flags

None — all threats from the plan's threat model are mitigated within this plan's implementation (T-32-04 via IpUtils, T-32-05 via hasTenant() early return, T-32-06 via Cache-Control + Pragma headers, T-32-07 via try/catch \Throwable fallback, T-32-08 via stateless per-request design verified by cross-tenant isolation test).

## Self-Check: PASSED
