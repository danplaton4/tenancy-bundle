---
phase: 32-maintenance-mode
verified: 2026-07-01T00:00:00Z
status: passed
score: 9/9 must-haves verified
overrides_applied: 0
re_verification: false
---

# Phase 32: Maintenance Mode Verification Report

**Phase Goal:** Operators can put individual tenants into maintenance mode via CLI, returning HTTP 503 with `Retry-After` and `Cache-Control: no-store` to those tenant's requests while other tenants and the landlord continue serving normally.
**Verified:** 2026-07-01
**Status:** PASSED
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|---------|
| 1 | Three CLI commands exist: `tenancy:maintenance:enable <slug>`, `:disable <slug>`, `:status` | VERIFIED | `src/Command/TenantMaintenanceEnableCommand.php` (`#[AsCommand(name: 'tenancy:maintenance:enable')]`), `...DisableCommand.php`, `...StatusCommand.php` — all present with `#[AsCommand]`, single-slug argument, `configure()` + `execute()` fully implemented |
| 2 | HTTP 503 + `Retry-After` + `Cache-Control: no-store` returned for maintenance tenants at priority 16 (after orchestrator at 20); `MaintenanceModeContractPass` enforces priority < 20 at compile time | VERIFIED | `TenantMaintenanceModeListener::PRIORITY = 16` declared as const and applied via `#[AsEventListener(..., priority: self::PRIORITY)]`; `buildMaintenanceResponse()` always sets `Retry-After`, `Cache-Control: no-store, no-cache, must-revalidate`, `Pragma: no-cache`; `MaintenanceModeContractPass::process()` throws `\LogicException` when `$priority >= TenantContextOrchestrator::PRIORITY`; integration test `testMaintenanceListenerRegisteredAtPriority16AfterOrchestrator()` asserts registered priority equals 16 and 16 < 20 |
| 3 | Allow-list bypass: IP/CIDR, exact route, and path-prefix all exempt requests from maintenance gate | VERIFIED | `isAllowListed()` in listener uses `IpUtils::checkIp()` for IP/CIDR, `in_array($route, $allowRoutes, true)` for routes, `str_starts_with($pathInfo, $prefix)` for paths; unit tests `testAllowedIpBypassesMaintenance`, `testAllowedCidrRangeBypassesMaintenance`, `testAllowedRouteBypassesMaintenance`, `testAllowedPathPrefixBypassesMaintenance` all present |
| 4 | Enable/disable idempotent, PSR cache invalidated after flush, events only on real transition | VERIFIED | Both commands: idempotent guard checks current state before acting; `$this->cache->delete('tenancy.tenant.'.$slug)` called after `$this->landlordEm->flush()` on real transition only; `TenantMaintenanceEnabled`/`Disabled` dispatched only on real transition; unit tests assert `flush()` called exactly once, `cache->delete()` called once with correct key on transition, zero times on idempotent path |
| 5 | Commands fetch tenant via landlord EM `findOneBy(['slug' => $slug])`, never via `findBySlug()` or `TenantContext` | VERIFIED | Both enable/disable commands use `$this->landlordEm->getRepository($entityClass)->findOneBy(['slug' => $slug])`; no `findBySlug`, `BootstrapperChain`, or `TenantContext` in either command file |
| 6 | State persisted as `$inMaintenance` boolean column on `AbstractTenant` + `TenantInterface::isInMaintenance(): bool` + `TenantMaintenanceConfigTrait` for custom entities | VERIFIED | `AbstractTenant`: `#[ORM\Column(type: 'boolean')] private bool $inMaintenance = false;` with `isInMaintenance(): bool` and `setInMaintenance(bool): self`; `TenantInterface` has `public function isInMaintenance(): bool;`; `TenantMaintenanceConfigTrait` has `private bool $inMaintenance = false`, `isInMaintenance(): bool`, `setInMaintenance(bool): static` with "Do NOT use with AbstractTenant" warning |
| 7 | Doctrine-optional invariant: bundle compiles and boots with no Doctrine ORM (maintenance degrades safely) | VERIFIED | Commands are registered inside `interface_exists(EntityManagerInterface::class)` block in `config/services.php`; listener has zero Doctrine imports; `ZeroConfigKernelBootTest::testMaintenanceEnabledBootsWithoutDoctrineOrm()` asserts `tenancy.maintenance.enabled` parameter exists + defaults to false + listener not registered + `bin/console list` exits 0 |
| 8 | Full test suite passes with maintenance coverage; automated test suite green (854 tests, 3531 assertions, 0 errors, 2 skipped) | VERIFIED | Orchestrator confirmed green suite. Listener test has 19 test methods (> plan requirement of 10); compiler pass test has 8 methods; enable/disable command tests cover real transition, idempotent, unknown slug; status command test has 6 methods; integration `ListenerPriorityTest` has 4 methods for priority assertions |
| 9 | Null-tenant and sub-request bypass: landlord/public routes never blocked | VERIFIED | `onKernelRequest()` check order: (1) `!$event->isMainRequest() → return`; (2) `!$this->tenantContext->hasTenant() → return`; unit tests `testSubRequestIsIgnored()` and `testNullTenantRequestIsIgnored()` both present and verified |

**Score:** 9/9 truths verified

---

## Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `src/Maintenance/TenantMaintenanceConfigTrait.php` | Bool column + getter + fluent static setter with AbstractTenant warning | VERIFIED | `private bool $inMaintenance = false`, `isInMaintenance(): bool`, `setInMaintenance(bool): static`, docblock "Do NOT use with AbstractTenant" present |
| `src/TenantInterface.php` | `isInMaintenance(): bool` contract method | VERIFIED | Method present immediately after `isActive(): bool` |
| `src/Entity/AbstractTenant.php` | Inlined `$inMaintenance` boolean column + `self`-return accessors | VERIFIED | `private bool $inMaintenance = false`, `isInMaintenance(): bool`, `setInMaintenance(bool): self` at lines 64-65 and 175-185 |
| `src/Event/TenantMaintenanceEnabled.php` | Final class, single `readonly TenantInterface $tenant` | VERIFIED | `final class TenantMaintenanceEnabled { public function __construct(public readonly TenantInterface $tenant) {} }` |
| `src/Event/TenantMaintenanceDisabled.php` | Final class, single `readonly TenantInterface $tenant` | VERIFIED | Identical shape to Enabled |
| `src/DependencyInjection/Compiler/MaintenanceModeContractPass.php` | Priority < 20 guard at compile time | VERIFIED | `throw new \LogicException` when `$priority >= TenantContextOrchestrator::PRIORITY`; early return when disabled/absent |
| `src/EventListener/TenantMaintenanceModeListener.php` | Priority-16 kernel.request listener, 503 + headers, allow-list, Twig fallback | VERIFIED | `PRIORITY = 16`, `#[AsEventListener(..., priority: self::PRIORITY)]`, all allow-list checks, Twig try/catch with hardcoded-HTML fallback |
| `src/Command/TenantMaintenanceEnableCommand.php` | Landlord flush + cache delete + enable event, idempotent | VERIFIED | `findOneBy(['slug'])`, `flush()`, `delete('tenancy.tenant.'.$slug)`, `dispatch(new TenantMaintenanceEnabled($tenant))` |
| `src/Command/TenantMaintenanceDisableCommand.php` | Mirror of enable | VERIFIED | Identical structure dispatching `TenantMaintenanceDisabled` |
| `src/Command/TenantMaintenanceStatusCommand.php` | `findAll()` + filter + table + `--format=json` aggregate | VERIFIED | Uses `TenantProviderInterface::findAll()`, filters `isInMaintenance()`, supports `--format=json` with `{"tenants":[...],"total":N}` |
| `src/TenancyBundle.php` | Config node + parameters + conditional listener + contract pass in `build()` + landlord-EM rewire | VERIFIED | `arrayNode('maintenance')` at line 136; six `tenancy.maintenance.*` parameters set; listener registered inside `if ($maintenanceEnabled)`; `MaintenanceModeContractPass` added unconditionally in `build()` at line 463; landlord-EM rewire inside `database.enabled` block at lines 304-312 |
| `config/services.php` | Three command service definitions with `console.command` tags | VERIFIED | `tenancy.command.maintenance.{status,enable,disable}` all registered with `->tag('console.command')` inside `interface_exists(EntityManagerInterface::class)` block |
| `tests/Integration/ListenerPriorityTest.php` | `TenantMaintenanceModeListener` at priority 16, < orchestrator 20 | VERIFIED | `testMaintenanceListenerRegisteredAtPriority16AfterOrchestrator()` and `testMaintenancePriorityConstantIs16()` both present |
| `tests/Integration/ZeroConfigKernelBootTest.php` | No-Doctrine boot safety with maintenance config present | VERIFIED | `testMaintenanceEnabledBootsWithoutDoctrineOrm()` asserts parameter exists, defaults false, listener not registered, console exits 0 |

---

## Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `TenantMaintenanceModeListener` | `TenantContext` | `hasTenant()` / `getTenant()->isInMaintenance()` | WIRED | Both calls present in `onKernelRequest()` |
| `TenantMaintenanceModeListener` | `IpUtils::checkIp` | Allow-list IP/CIDR check | WIRED | `IpUtils::checkIp((string) $request->getClientIp(), $this->allowIps)` in `isAllowListed()` |
| `TenancyBundle::loadExtension()` | `TenantMaintenanceModeListener::class` | Conditional service registration when `maintenance.enabled` | WIRED | `$services->set('tenancy.maintenance.listener', TenantMaintenanceModeListener::class)->autoconfigure(true)` inside `if ($maintenanceEnabled)` |
| `TenancyBundle::build()` | `MaintenanceModeContractPass` | Unconditional `addCompilerPass()` | WIRED | `$container->addCompilerPass(new MaintenanceModeContractPass())` at line 463, no `interface_exists` guard |
| `config/services.php` | `TenantMaintenanceEnableCommand` | `tenancy.command.maintenance.enable` with landlord EM | WIRED | Registered with `doctrine.orm.default_entity_manager` → rewired to `doctrine.orm.landlord_entity_manager` by `loadExtension()` under `database.enabled: true` |
| `TenantMaintenanceEnableCommand` | PSR cache key `tenancy.tenant.<slug>` | `$this->cache->delete('tenancy.tenant.'.$slug)` after flush | WIRED | Exact pattern present in both enable and disable commands |
| `TenantMaintenanceStatusCommand` | `TenantProviderInterface::findAll()` | Cache-bypassing list for operator status | WIRED | `$this->tenantProvider->findAll()` → `array_filter(..., fn($t) => $t->isInMaintenance())` |
| `MaintenanceModeContractPass` | `TenantContextOrchestrator::PRIORITY` | Priority ceiling comparison | WIRED | `$priority >= TenantContextOrchestrator::PRIORITY` at compile time |

---

## Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `TenantMaintenanceModeListener` | `$tenant->isInMaintenance()` | `TenantContext` (populated by `TenantContextOrchestrator` @20 from DB entity) | Yes — real DB row | FLOWING |
| `TenantMaintenanceEnableCommand` | `$tenant` via `findOneBy(['slug'])` | Landlord `EntityManagerInterface` → DB row | Yes — fresh DB read bypassing PSR cache | FLOWING |
| `TenantMaintenanceStatusCommand` | `$this->tenantProvider->findAll()` | `DoctrineTenantProvider::findAll()` — documented as cache-bypassing operator path | Yes — fresh DB query | FLOWING |
| `AbstractTenant::$inMaintenance` | `bool` column | `#[ORM\Column(type: 'boolean')]` → DB persistence | Yes — DB-persisted state | FLOWING |

---

## Behavioral Spot-Checks

Step 7b: The codebase is a Symfony bundle (not a standalone runnable app). Key behaviors are fully covered by the unit and integration test suite already confirmed green by the orchestrator. Specific spot-check commands would require booting the full Symfony container which is not appropriate here.

| Behavior | Verification Method | Status |
|----------|---------------------|--------|
| `TenantMaintenanceModeListener::PRIORITY === 16` | `grep -n "PRIORITY = 16"` in source + integration test assertion | PASS |
| `MaintenanceModeContractPass` throws on priority >= 20 | Compiler pass test `testThrowsWhenListenerPriorityEqualsOrchestratorPriority()` | PASS (8 test methods, including exact priority-20 and priority-25 cases) |
| Enable command deletes PSR cache key on transition | Unit test `testEnableRealTransitionFlushesAndDeletesCacheAndDispatchesEvent()` asserts `cache->delete('tenancy.tenant.acme')` | PASS |
| Enable command is idempotent | Unit test `testEnableIdempotentOnAlreadyInMaintenanceTenant()` asserts no flush, no cache delete, no dispatch | PASS |
| Status command JSON format | Unit test `testStatusJsonFormatContainsOnlyInMaintenanceTenants()` asserts aggregate `{"tenants":[...],"total":N}` | PASS |

---

## Probe Execution

Step 7c: No phase-declared or conventional `scripts/*/tests/probe-*.sh` probes exist for this phase. The orchestrator ran the full PHPUnit suite (`854 tests, 3531 assertions, 0 errors, 2 skipped`) confirming all tests green. SKIPPED (no runnable probes; orchestrator full-suite verification accepted as equivalent).

---

## Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|---------|
| MAINT-01 | 32-03, 32-04 | `tenancy:maintenance:enable <slug>` | SATISFIED | `TenantMaintenanceEnableCommand` exists, wired, tested; enable/disable commands have landlord-EM rewire |
| MAINT-02 | 32-03, 32-04 | `tenancy:maintenance:disable <slug>` | SATISFIED | `TenantMaintenanceDisableCommand` exists, wired, tested |
| MAINT-03 | 32-02, 32-04 | HTTP 503 + `Retry-After` + `Cache-Control: no-store` | SATISFIED | Listener builds response with all three headers; unit tests assert `Retry-After` and `Cache-Control` containing `no-store` |
| MAINT-04 | 32-02 | Landlord/public/health routes never blocked | SATISFIED | `!hasTenant() → return` at check (2); `!isMainRequest() → return` at check (1); both unit-tested |
| MAINT-05 | 32-01 | State stored on entity (DB column, no cross-tenant leak) | SATISFIED | `AbstractTenant::$inMaintenance` boolean column; `TenantMaintenanceConfigTrait` for custom entities; per-row isolation enforced by DB |
| MAINT-06 | 32-02 | IP/route/path allow-list bypass | SATISFIED | `isAllowListed()` checks IpUtils (CIDR), `in_array(_route)`, `str_starts_with(pathInfo)`; all three paths unit-tested |
| MAINT-07 | 32-02 | Custom Twig template override with hardcoded-HTML fallback | SATISFIED | `renderHtml()` tries `$this->twig->render(...)` in `try { } catch (\Throwable) { }` falling back to `defaultHtml()`; both paths unit-tested |
| MAINT-08 | 32-01, 32-03 | `TenantMaintenanceEnabled`/`Disabled` events on real transition | SATISFIED | Both event classes exist; dispatched only on real bool transition in both commands; idempotent path dispatches nothing (asserted in tests) |
| MAINT-09 | 32-03, 32-04 | `tenancy:maintenance:status` lists in-maintenance tenants | SATISFIED | `TenantMaintenanceStatusCommand` with table + `--format=json`; uses `findAll()` (cache-bypassing); unit tests assert only in-maintenance tenants appear |

**MAINT-* coverage: 9/9 SATISFIED**

---

## Anti-Patterns Found

The code review (`32-REVIEW.md`) documented 4 warnings and 2 info items. None are blockers for the phase goal. Summary of findings and their impact:

| Finding | File | Severity | Impact on Phase Goal |
|---------|------|----------|---------------------|
| WR-01: `setInMaintenance()` absent from `TenantInterface`; a custom `TenantInterface` impl without `AbstractTenant` or the trait receives `FAILURE` from enable/disable via `method_exists()` guard | `src/TenantInterface.php`, `src/Command/TenantMaintenance{Enable,Disable}Command.php` | WARNING (non-blocking) | Does not block the phase goal for users using `AbstractTenant` (the standard path). The `method_exists()` guard produces a clear error message. Recommended fix: add `setInMaintenance(bool): static` to interface, or convert `method_exists()` guard to `\LogicException` |
| WR-02: `MaintenanceModeContractPass` no-ops silently if no `kernel.request` tag found for the listener (no assertion that at least one matching tag was inspected) | `src/DependencyInjection/Compiler/MaintenanceModeContractPass.php:63-73` | WARNING (non-blocking) | Does not affect normal use (autoconfigure is always true on the listener registration). Guard works correctly in the normal lifecycle. |
| WR-03: Dead code — unused `$listeners` variable in `ListenerPriorityTest` at line 67 | `tests/Integration/ListenerPriorityTest.php:67` | WARNING (non-blocking) | Does not affect test correctness. The test assertions are correct; the variable is simply never used. |
| WR-04: `method_exists()` failure branch untested in enable/disable command tests | `tests/Unit/Command/TenantMaintenanceEnableCommandTest.php`, `...DisableCommandTest.php` | WARNING (non-blocking) | One execution path in each command has no test coverage. Does not affect the phase goal for standard entity implementations. |
| IN-01: `--format` option accepts arbitrary values, silently falls back to `txt` | `src/Command/TenantMaintenanceStatusCommand.php` | INFO | Minor UX issue; does not affect correctness |
| IN-02: Docblock in `TenantMaintenanceConfigTrait` imprecise on Doctrine-optional behavior | `src/Maintenance/TenantMaintenanceConfigTrait.php` | INFO | Documentation quality only; behavior is correct |

**Debt markers:** No `TBD`, `FIXME`, or `XXX` markers found in phase-modified files.

---

## Human Verification Required

None. This Symfony bundle's maintenance-mode behavior is fully testable via unit and integration tests. The orchestrator confirmed the full suite green. No visual UI, real-time behavior, or external-service-dependent paths require human validation.

---

## Gaps Summary

No gaps blocking the phase goal. All 9 MAINT-* requirements are satisfied by code that exists, is substantive, is wired, and has data flowing through it.

The 4 warnings from the code review (`32-REVIEW.md`) are noted as non-blocking follow-up items:
- **WR-01** (most impactful): Adding `setInMaintenance(bool): static` to `TenantInterface` would make the setter contract explicit and eliminate the `method_exists()` defensive guard. This is a candidate for a follow-up patch before v0.5 release.
- **WR-02, WR-03, WR-04**: Minor robustness and test-coverage gaps; none affect the phase goal for standard usage.

---

_Verified: 2026-07-01_
_Verifier: Claude (gsd-verifier)_
