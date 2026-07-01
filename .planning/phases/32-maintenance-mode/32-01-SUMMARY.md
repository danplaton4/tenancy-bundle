---
phase: 32-maintenance-mode
plan: "01"
subsystem: maintenance-mode
tags: [maintenance, trait, interface, entity, events, compiler-pass, bc-break]
dependency_graph:
  requires: []
  provides:
    - TenantInterface::isInMaintenance() bool contract
    - TenantMaintenanceConfigTrait (standalone entities)
    - AbstractTenant $inMaintenance column (built-in entity)
    - TenantMaintenanceEnabled / TenantMaintenanceDisabled events
    - MaintenanceModeContractPass (compile-time priority guard)
  affects:
    - All TenantInterface implementors (BC break — 13 test stubs fixed)
    - 32-02 (listener, wiring, config)
    - 32-03 (enable/disable/status commands)
    - 32-04 (integration wiring and ZeroConfigKernelBootTest)
tech_stack:
  added: []
  patterns:
    - Config-trait pattern (mirrors TenantMailerConfigTrait / TenantFilesystemConfigTrait)
    - Readonly-constructor event (mirrors TenantBootstrapped)
    - CompilerPassInterface early-return-when-disabled (mirrors FilesystemContractPass)
key_files:
  created:
    - src/Maintenance/TenantMaintenanceConfigTrait.php
    - src/Event/TenantMaintenanceEnabled.php
    - src/Event/TenantMaintenanceDisabled.php
    - src/DependencyInjection/Compiler/MaintenanceModeContractPass.php
    - tests/Unit/Entity/TenantMaintenanceConfigTraitTest.php
    - tests/Unit/DependencyInjection/Compiler/MaintenanceModeContractPassTest.php
  modified:
    - src/TenantInterface.php (added isInMaintenance(): bool — sole BC break D-05)
    - src/Entity/AbstractTenant.php (inlined $inMaintenance bool column + self-return accessors)
    - tests/Unit/Filesystem/FilesystemPrefixingDecoratorTest.php (stub fix)
    - tests/Unit/Filesystem/TenantAwareFilesystemDecoratorTest.php (stub fix)
    - tests/Unit/Filter/TenantAwareFilterTest.php (stub fix)
    - tests/Unit/Mailer/TenantMailerDecoratorTest.php (stub fix)
    - tests/Unit/Mailer/TenantMessageDecoratorTest.php (stub fix)
    - tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php (stub fix)
    - tests/Integration/DoctrineBootstrapperIntegrationTest.php (stub fix)
    - tests/Integration/CacheBootstrapperIntegrationTest.php (stub fix)
    - tests/Integration/SharedDbFilterIntegrationTest.php (stub fix)
    - tests/Integration/Filesystem/StubTenantWithFilesystem.php (stub fix)
    - tests/Integration/Messenger/Support/StubTenant.php (stub fix)
    - tests/Integration/Resolver/Support/StubTenant.php (stub fix)
decisions:
  - "TenantMaintenanceConfigTrait uses 'static' return type on setInMaintenance (fluent for subclasses); AbstractTenant uses 'self' matching its own $isActive setter convention"
  - "MaintenanceModeContractPass references TenantContextOrchestrator::PRIORITY directly; does NOT reference TenantMaintenanceModeListener::PRIORITY (not yet created in plan 32-01)"
  - "13 TenantInterface stubs across unit+integration tests required isInMaintenance(): bool {return false;} — applied as Rule 1 auto-fix (BC break caused by the planned interface change)"
metrics:
  duration: "~25 min"
  completed: "2026-07-01"
  tasks_completed: 3
  files_changed: 20
---

# Phase 32 Plan 01: Maintenance Foundation Summary

Established the maintenance-mode foundation: persisted boolean state (trait + interface method + AbstractTenant column), two toggle events, and the compile-time listener-priority guard. All downstream plans (32-02 listener, 32-03 commands, 32-04 wiring) can now build on these contracts.

## One-liner

Maintenance-mode foundation: isInMaintenance() bool contract on TenantInterface + TenantMaintenanceConfigTrait + AbstractTenant column + two toggle events + compile-time priority-16 guard pass.

## Tasks Completed

| Task | Description | Commit | Files |
|------|-------------|--------|-------|
| 1 | Maintenance state: trait, interface method, AbstractTenant column | a21ecbc | src/Maintenance/TenantMaintenanceConfigTrait.php, src/TenantInterface.php, src/Entity/AbstractTenant.php, test + 13 stub fixes |
| 2 | Toggle events: TenantMaintenanceEnabled / TenantMaintenanceDisabled | a3d355d | src/Event/TenantMaintenanceEnabled.php, src/Event/TenantMaintenanceDisabled.php |
| 3 | MaintenanceModeContractPass — compile-time priority < 20 guard | 3a4a9a9 | src/DependencyInjection/Compiler/MaintenanceModeContractPass.php, test |

## Contracts Produced

**Service IDs (for plans 32-02/03/04 to use unchanged):**

- Listener service ID: `tenancy.maintenance.listener`
- Enabled parameter: `tenancy.maintenance.enabled`

**TenantInterface addition (exact signature):**

```php
public function isInMaintenance(): bool;
```

Added immediately after `isActive(): bool`. This is the sole BC break of v0.5 (D-05, MAINT-05).

**Class names / namespaces:**

- `Tenancy\Bundle\Maintenance\TenantMaintenanceConfigTrait` — for custom entities that implement TenantInterface directly (not extending AbstractTenant)
- `Tenancy\Bundle\Event\TenantMaintenanceEnabled` — dispatched on enable (MAINT-08)
- `Tenancy\Bundle\Event\TenantMaintenanceDisabled` — dispatched on disable (MAINT-08)
- `Tenancy\Bundle\DependencyInjection\Compiler\MaintenanceModeContractPass` — registered unconditionally in TenancyBundle::build()

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed 13 TenantInterface stubs missing isInMaintenance(): bool**
- **Found during:** Task 1 implementation (unit suite crash)
- **Issue:** Adding `isInMaintenance(): bool` to TenantInterface broke 13 anonymous-class / concrete stubs across unit and integration tests that implement the interface
- **Fix:** Added `public function isInMaintenance(): bool { return false; }` to each stub (safe default matching trait behavior)
- **Files modified:** FilesystemPrefixingDecoratorTest, TenantAwareFilesystemDecoratorTest, TenantAwareFilterTest, TenantMailerDecoratorTest, TenantMessageDecoratorTest, TenantAwareTransportsDecoratorTest, DoctrineBootstrapperIntegrationTest, CacheBootstrapperIntegrationTest, SharedDbFilterIntegrationTest, StubTenantWithFilesystem, Messenger/Support/StubTenant, Resolver/Support/StubTenant, Filter/TenantAwareFilterTest

**2. [Rule 1 - Bug] MaintenanceModeContractPass cannot reference TenantMaintenanceModeListener::PRIORITY**
- **Found during:** Task 3 implementation
- **Issue:** `TenantMaintenanceModeListener` does not exist yet (created in plan 32-02). The pass's error message referred to that constant.
- **Fix:** Used hard-coded value `16` with a comment `/* TenantMaintenanceModeListener::PRIORITY — registered in plan 32-02 */` in the sprintf call. The docblock reference was also removed.
- **Impact:** When plan 32-02 creates `TenantMaintenanceModeListener::PRIORITY = 16`, the pass can optionally be updated to reference the constant (no behavioral change, just readability).

## Verification

- `vendor/bin/phpunit --testsuite unit`: **655 tests, 1770 assertions, 2 skipped — OK**
- `vendor/bin/phpstan analyse --memory-limit=512M`: **OK, no errors (level 9)**
- `vendor/bin/php-cs-fixer check --diff`: **clean** (cs-fixer auto-fixed multiline sprintf in contract pass)

## Known Stubs

None — all `isInMaintenance()` implementations in test stubs return `false` as intended. The trait and AbstractTenant both default to `false`. No placeholder text or mock data flows to UI.

## Threat Flags

| Flag | File | Description |
|------|------|-------------|
| threat_flag: bc-break | src/TenantInterface.php | New method `isInMaintenance(): bool` — breaks any custom TenantInterface implementation not using TenantMaintenanceConfigTrait. Mitigated by trait's false default and UPGRADE.md §0.4→0.5 (Phase 34). T-32-03 mitigated. |

## Self-Check: PASSED
