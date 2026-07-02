---
phase: 32-maintenance-mode
fixed_at: 2026-07-02T00:00:00Z
review_path: .planning/phases/32-maintenance-mode/32-REVIEW.md
iteration: 1
findings_in_scope: 4
fixed: 4
skipped: 0
status: all_fixed
---

# Phase 32: Code Review Fix Report

**Fixed at:** 2026-07-02
**Source review:** `.planning/phases/32-maintenance-mode/32-REVIEW.md`
**Iteration:** 1

**Summary:**
- Findings in scope: 4 (WR-01, WR-02, WR-03, WR-04)
- Fixed: 4
- Skipped: 0

## Fixed Issues

### WR-01: `setInMaintenance()` absent from `TenantInterface`

**Files modified:**
`src/TenantInterface.php`,
`src/Entity/AbstractTenant.php`,
`src/Command/TenantMaintenanceEnableCommand.php`,
`src/Command/TenantMaintenanceDisableCommand.php`,
`tests/Integration/Filesystem/StubTenantWithFilesystem.php`,
`tests/Integration/Messenger/Support/StubTenant.php`,
`tests/Integration/Resolver/Support/StubTenant.php`,
`tests/Unit/Mailer/TenantMailerDecoratorTest.php`,
`tests/Unit/Mailer/TenantMessageDecoratorTest.php`,
`tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php`,
`tests/Unit/Filesystem/TenantAwareFilesystemDecoratorTest.php`,
`tests/Unit/Filesystem/FilesystemPrefixingDecoratorTest.php`,
`tests/Unit/Filter/TenantAwareFilterTest.php`,
`tests/Integration/DoctrineBootstrapperIntegrationTest.php`,
`tests/Integration/CacheBootstrapperIntegrationTest.php`,
`tests/Integration/SharedDbFilterIntegrationTest.php`,
`tests/Unit/Command/TenantMaintenanceEnableCommandTest.php`,
`tests/Unit/Command/TenantMaintenanceDisableCommandTest.php`

**Commit:** `69d8c6b`

**Applied fix:**
- Added `public function setInMaintenance(bool $inMaintenance): static;` to `TenantInterface` (option A — preferred)
- Changed `AbstractTenant::setInMaintenance()` return type from `: self` to `: static` for PHP covariant return-type compatibility (`TenantMaintenanceConfigTrait` already used `: static`)
- Removed the `method_exists($tenant, 'setInMaintenance')` guard and its `FAILURE` return branch from both enable and disable commands — the interface contract now enforces the method at compile time
- Added `public function setInMaintenance(bool $inMaintenance): static { return $this; }` stub to every concrete `TenantInterface` implementation in tests that was missing it (3 named stub classes + anonymous class stubs in 8 test files)
- Updated test docblocks to reflect that `setInMaintenance()` is now on the interface

---

### WR-02: `MaintenanceModeContractPass` silently no-ops on missing `kernel.request` tag

**Files modified:** `src/DependencyInjection/Compiler/MaintenanceModeContractPass.php`

**Commit:** `e5c83ae`

**Applied fix:**
Added a `$foundRequestTag = false` flag before the tag-inspection `foreach` loop. Set it to `true` when a tag with `event === KernelEvents::REQUEST` is found. After the loop, throw a `\LogicException` if `$foundRequestTag` is still `false`. All existing unit tests pass — their fixtures supply valid `kernel.request` tags so the new assertion does not fire for them. The `ListenerPriorityTest` integration tests boot with `autoconfigure(true)` so the tag is present in practice.

---

### WR-03: Dead `$listeners` variable in `ListenerPriorityTest`

**Files modified:** `tests/Integration/ListenerPriorityTest.php`

**Commit:** `386b560`

**Applied fix:**
Removed the dead assignment `$listeners = $dispatcher->getListeners(KernelEvents::REQUEST)` at line 67. The variable was assigned but never read — the `foreach` loop immediately below made an identical `getListeners()` call directly.

---

### WR-04: `method_exists()` failure branch untested

**Files modified:** (none — closed by WR-01 removal)

**Commit:** `69d8c6b` (same as WR-01)

**Applied fix:**
WR-04 is closed by removal: once the `method_exists()` guard was deleted (as part of WR-01), the `FAILURE` branch no longer exists and there is nothing to test. No new test was added. No existing test asserted the missing-setter → FAILURE behavior (the command tests only covered the happy path and slug-not-found path), so no test removal was needed.

---

## Skipped Issues

None — all in-scope findings were fixed.

---

## Final Gate Results

| Gate | Result |
|------|--------|
| PHPStan level 9 | OK — No errors |
| php-cs-fixer (@Symfony) | OK — No changes |
| PHPUnit | OK — 854 tests, 3531 assertions, 2 skipped (pre-existing), 0 failures |

_Fixed: 2026-07-02_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
