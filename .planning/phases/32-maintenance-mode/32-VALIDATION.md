---
phase: 32
slug: maintenance-mode
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-07-01
---

# Phase 32 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Source: `32-RESEARCH.md` § Validation Architecture (all test seams verified against live source).

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11 |
| **Config file** | `phpunit.xml.dist` (root) |
| **Quick run command** | `vendor/bin/phpunit --testsuite unit` |
| **Full suite command** | `vendor/bin/phpunit` |
| **Estimated runtime** | ~5s unit / ~30s full (SQLite `:memory:`) |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit --testsuite unit` (no DB, seconds)
- **After every plan wave:** Run `vendor/bin/phpunit` (full suite, integration + unit)
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** ~30 seconds

---

## Per-Task Verification Map

Task IDs are assigned by the planner (Plan/Wave columns filled during planning). Rows below are the
requirement→behavior→test contract the planner must satisfy; every behavior maps to an automated command.

| Requirement | Behavior (secure/correct) | Test Type | Automated Command | File Exists |
|-------------|---------------------------|-----------|-------------------|-------------|
| MAINT-01 | `enable <slug>` puts tenant in maintenance; idempotent 2nd call exits 0 "already" | unit (CommandTester) | `vendor/bin/phpunit tests/Unit/Command/TenantMaintenanceEnableCommandTest.php` | ❌ W0 |
| MAINT-01 | `enable` with unknown slug returns FAILURE (no cache/EM write) | unit (CommandTester) | same | ❌ W0 |
| MAINT-02 | `disable <slug>` restores tenant; idempotent 2nd call exits 0 | unit (CommandTester) | `vendor/bin/phpunit tests/Unit/Command/TenantMaintenanceDisableCommandTest.php` | ❌ W0 |
| MAINT-03 | Maintenance tenant → 503 + `Retry-After` + `Cache-Control: no-store` | unit (listener direct-invoke) | `vendor/bin/phpunit tests/Unit/EventListener/TenantMaintenanceModeListenerTest.php` | ❌ W0 |
| MAINT-03 | JSON request → JSON body; HTML request → HTML body; identical headers | unit (listener) | same | ❌ W0 |
| MAINT-04 | Null-tenant / landlord request bypasses listener (no 503) | unit (listener) | same | ❌ W0 |
| MAINT-04 | Sub-request (`!isMainRequest()`) bypasses listener | unit (listener) | same | ❌ W0 |
| MAINT-05 | Cross-tenant isolation: tenant-A in maintenance does NOT 503 tenant-B | unit (listener, two contexts) | same | ❌ W0 |
| MAINT-05 | Trait default `isInMaintenance()` returns `false` (BC-break mitigation) | unit (trait) | `vendor/bin/phpunit tests/Unit/Entity/TenantMaintenanceConfigTraitTest.php` | ❌ W0 |
| MAINT-05 | **Cache invalidation**: after enable/disable, PSR key `tenancy.tenant.<slug>` deleted | unit (mock `CacheInterface`) | `vendor/bin/phpunit tests/Unit/Command/TenantMaintenanceEnableCommandTest.php` | ❌ W0 |
| MAINT-06 | IP on `allow_ips` (incl. CIDR via `IpUtils::checkIp`) bypasses maintenance | unit (listener) | `vendor/bin/phpunit tests/Unit/EventListener/TenantMaintenanceModeListenerTest.php` | ❌ W0 |
| MAINT-06 | Exact `_route` on `allow_routes` bypasses maintenance | unit (listener) | same | ❌ W0 |
| MAINT-06 | Path prefix (`str_starts_with`) on `allow_paths` bypasses maintenance | unit (listener) | same | ❌ W0 |
| MAINT-07 | Custom Twig template renders when `tenancy.maintenance.template` set | unit (listener, mock Twig env) | same | ❌ W0 |
| MAINT-07 | Falls back to hardcoded HTML if Twig render throws (defense in depth) | unit (listener, Twig throws) | same | ❌ W0 |
| MAINT-08 | `TenantMaintenanceEnabled` dispatched on real enable; NOT on idempotent re-enable | unit (mock dispatcher) | `vendor/bin/phpunit tests/Unit/Command/TenantMaintenanceEnableCommandTest.php` | ❌ W0 |
| MAINT-08 | `TenantMaintenanceDisabled` dispatched on real disable; NOT on idempotent re-disable | unit (mock dispatcher) | `vendor/bin/phpunit tests/Unit/Command/TenantMaintenanceDisableCommandTest.php` | ❌ W0 |
| MAINT-09 | `status` lists only in-maintenance tenants; `--format=json` aggregate object | unit (CommandTester) | `vendor/bin/phpunit tests/Unit/Command/TenantMaintenanceStatusCommandTest.php` | ❌ W0 |
| SC-3 | `MaintenanceModeContractPass` throws `LogicException` when listener priority ≥ 20 | unit (ContainerBuilder) | `vendor/bin/phpunit tests/Unit/DependencyInjection/Compiler/MaintenanceModeContractPassTest.php` | ❌ W0 |
| SC-3 | Pass succeeds when listener priority < 20 | unit (ContainerBuilder) | same | ❌ W0 |
| SC-3 | Maintenance listener registered at priority 16 (after orchestrator @20) in compiled container | integration | `vendor/bin/phpunit tests/Integration/ListenerPriorityTest.php` | ⚠️ extend existing |
| No-Doctrine | Bundle compiles without doctrine/orm; maintenance feature degrades safely | integration | `vendor/bin/phpunit tests/Integration/ZeroConfigKernelBootTest.php` | ⚠️ extend existing |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky · W0 = created in Wave 0*

---

## Wave 0 Requirements

- [ ] `tests/Unit/EventListener/TenantMaintenanceModeListenerTest.php` — MAINT-03, MAINT-04, MAINT-05, MAINT-06, MAINT-07
- [ ] `tests/Unit/Command/TenantMaintenanceEnableCommandTest.php` — MAINT-01, MAINT-08, MAINT-05 (cache-delete assertion)
- [ ] `tests/Unit/Command/TenantMaintenanceDisableCommandTest.php` — MAINT-02, MAINT-08, MAINT-05 (cache-delete assertion)
- [ ] `tests/Unit/Command/TenantMaintenanceStatusCommandTest.php` — MAINT-09
- [ ] `tests/Unit/DependencyInjection/Compiler/MaintenanceModeContractPassTest.php` — Success Criterion 3
- [ ] `tests/Unit/Entity/TenantMaintenanceConfigTraitTest.php` — MAINT-05 default + getter/setter
- [ ] Extend `tests/Integration/ListenerPriorityTest.php` — assert priority 16 (fires after @20)

Test seams (verified against live source — see `32-RESEARCH.md` § Test Seam Notes):
- **Listener:** instantiate directly, build `TenantContext`, set mock `TenantInterface`, fire `RequestEvent` with `MAIN_REQUEST`; assert `$event->hasResponse()` + status/headers. Pattern: `tests/Integration/EventListener/NoTenantRequestTest.php`.
- **Commands:** `CommandTester` + mock `EntityManagerInterface` (landlord), mock `CacheInterface` (`delete()` assertion), mock `EventDispatcherInterface`. No kernel boot. Pattern: `tests/Unit/Command/TenantMigrateCommandTest.php`.
- **Compiler pass:** raw `ContainerBuilder`, add a `kernel.event_listener`-tagged def with varying priority, run `process()`, assert `LogicException` at ≥20. Pattern: `tests/Unit/DependencyInjection/Compiler/MailerTransportContractPassTest.php`.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| CDN/proxy does not cache the 503 after maintenance lifts | MAINT-03 | Requires a real CDN/edge; unit test asserts headers only | Deploy behind CDN, enable maintenance, confirm 503 not served after `disable` (headers `no-store` verified in unit) |
| Spoofed `X-Forwarded-For` does not bypass allow-list unless proxy is trusted | MAINT-06 | Depends on app `trustedProxies` config, outside bundle | Set trusted proxies, send forged header, confirm `getClientIp()` ignores it |

*All in-code behaviors have automated verification; the two rows above are deployment-environment concerns documented for ops.*

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references (7 test files above)
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] `nyquist_compliant: true` set in frontmatter (after Wave 0 stubs land)

**Approval:** pending
