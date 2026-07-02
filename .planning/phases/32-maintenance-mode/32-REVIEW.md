---
phase: 32-maintenance-mode
reviewed: 2026-07-01T00:00:00Z
depth: deep
files_reviewed: 15
files_reviewed_list:
  - src/Maintenance/TenantMaintenanceConfigTrait.php
  - src/TenantInterface.php
  - src/Entity/AbstractTenant.php
  - src/Event/TenantMaintenanceEnabled.php
  - src/Event/TenantMaintenanceDisabled.php
  - src/DependencyInjection/Compiler/MaintenanceModeContractPass.php
  - src/EventListener/TenantMaintenanceModeListener.php
  - src/Command/TenantMaintenanceEnableCommand.php
  - src/Command/TenantMaintenanceDisableCommand.php
  - src/Command/TenantMaintenanceStatusCommand.php
  - src/TenancyBundle.php
  - config/services.php
  - tests/Unit/EventListener/TenantMaintenanceModeListenerTest.php
  - tests/Unit/Command/TenantMaintenanceEnableCommandTest.php
  - tests/Unit/Command/TenantMaintenanceDisableCommandTest.php
findings:
  critical: 0
  warning: 0
  info: 2
  total: 2
warnings_resolved: 4
status: issues_found
resolution: "WR-01..04 fixed 2026-07-02 (commits 69d8c6b, e5c83ae, 386b560) — see 32-REVIEW-FIX.md. IN-01/IN-02 out of scope (info-only, no --all)."
---

> **Resolution (2026-07-02):** All 4 warnings resolved via `/gsd:code-review 32 --fix`.
> - **WR-01 + WR-04** — `setInMaintenance(): static` added to `TenantInterface`; `method_exists()` guard removed from both commands; return types normalized to `static` across all implementers (commit `69d8c6b`). WR-04 closed-by-removal.
> - **WR-02** — `MaintenanceModeContractPass` now throws when no `kernel.request` tag is found (commit `e5c83ae`).
> - **WR-03** — dead `$listeners` variable removed (commit `386b560`).
>
> Gates re-verified on a clean cache: PHPUnit 854/854, PHPStan L9 clean, cs-fixer clean. See `32-REVIEW-FIX.md`.
> The two **Info** items below (IN-01, IN-02) were out of scope for this `--fix` run (no `--all`) and remain as advisory notes.

# Phase 32: Code Review Report

**Reviewed:** 2026-07-01
**Depth:** deep
**Files Reviewed:** 15
**Status:** issues_found

## Summary

Phase 32 introduces per-tenant maintenance mode: a `$inMaintenance` DB column on
`AbstractTenant`, opt-in `TenantMaintenanceConfigTrait`, two events, a priority-16
`kernel.request` listener that gates 503 responses, enable/disable/status CLI commands,
a compile-time priority guard (`MaintenanceModeContractPass`), and full DI wiring in
`TenancyBundle` and `config/services.php`.

The overall architecture is solid. Cache-coherence is correctly implemented: the commands
delete `tenancy.tenant.<slug>` via the unwrapped inner pool (no active `TenantContext` in
console context), matching the key written by `DoctrineTenantProvider::findBySlug()`. The
503 listener priority ordering and the compile-time guard are correct. The Doctrine-optional
invariant is satisfied: the listener and events have zero Doctrine imports; the commands are
gated behind `interface_exists(EntityManagerInterface::class)` in `config/services.php`.

Four warnings and two info items are documented below. The most important is WR-01:
`setInMaintenance()` is absent from `TenantInterface`, creating an interface gap where any
valid `TenantInterface` implementation that does NOT extend `AbstractTenant` or use the trait
will silently receive a `FAILURE` exit code from the enable/disable commands, with no
compile-time or container-level enforcement.

---

## Warnings

### WR-01: `setInMaintenance()` absent from `TenantInterface` creates a silent runtime failure

**File:** `src/Command/TenantMaintenanceEnableCommand.php:80-88`, `src/Command/TenantMaintenanceDisableCommand.php:80-88`

**Issue:** `TenantInterface` declares `isInMaintenance(): bool` (readable) but NOT
`setInMaintenance(bool): static` (writable). A user who implements `TenantInterface`
directly — without extending `AbstractTenant` or using `TenantMaintenanceConfigTrait` —
satisfies the type contract and passes container wiring, but then receives `Command::FAILURE`
when they first run `tenancy:maintenance:enable` or `tenancy:maintenance:disable`. The error
path is only reachable after the DB entity lookup has already succeeded.

There is no compile-time or container-level enforcement: the `method_exists()` check at
runtime is the only protection. This is not caught by PHPStan level 9 on the user's codebase
because they are conforming to the published interface.

**Fix (option A — add setter to interface; preferred):**
```php
// src/TenantInterface.php
interface TenantInterface
{
    // existing methods...
    public function isInMaintenance(): bool;
    public function setInMaintenance(bool $inMaintenance): static;
}
```
This makes the contract explicit and removes the `method_exists()` guard entirely.

**Fix (option B — if interface is frozen):** Document the requirement prominently in the
`TenantInterface` docblock and in `TenantMaintenanceConfigTrait`, and convert the
`method_exists()` guard to throw a `\LogicException` instead of returning `FAILURE`, so
the class misconfiguration is surfaced as a hard boot error rather than a silent command
failure.

---

### WR-02: `MaintenanceModeContractPass` silently no-ops when no `kernel.request` tags are found

**File:** `src/DependencyInjection/Compiler/MaintenanceModeContractPass.php:63-73`

**Issue:** The priority guard iterates `$def->getTag('kernel.event_listener')` and checks
each tag's `event` key. If zero tags match `KernelEvents::REQUEST` (e.g., the service is
registered but autoconfigure has not added the tag yet, or the tag uses a non-standard
shape), the `foreach` loop completes without throwing — the compile-time guard is silently
a no-op. Nothing asserts that at least one matching `kernel.request` tag was found.

In the normal Symfony lifecycle, `ResolveInstanceofConditionalsPass` runs at priority 100 in
`TYPE_BEFORE_OPTIMIZATION` before this pass (priority 0), so the tag is present in practice.
But if a future refactor removes `autoconfigure(true)` from the listener registration (e.g.,
to explicit tag wiring), and the explicit tag accidentally omits or misspells the event, the
guard would pass silently.

**Fix:**
```php
// After the foreach loop, assert that at least one kernel.request tag was inspected:
$foundRequestTag = false;
foreach ($tags as $tag) {
    if (($tag['event'] ?? '') !== KernelEvents::REQUEST) {
        continue;
    }
    $foundRequestTag = true;
    $priority = (int) ($tag['priority'] ?? 0);
    if ($priority >= TenantContextOrchestrator::PRIORITY) {
        throw new \LogicException(/* ... */);
    }
}

if (!$foundRequestTag) {
    throw new \LogicException(sprintf(
        'tenancy: maintenance.enabled is true but service "%s" has no kernel.event_listener '
        .'tag for kernel.request. Ensure autoconfigure is enabled on the listener service.',
        self::LISTENER_SERVICE_ID,
    ));
}
```

---

### WR-03: Dead code — unused `$listeners` variable in `ListenerPriorityTest`

**File:** `tests/Integration/ListenerPriorityTest.php:67`

**Issue:** `$listeners = $dispatcher->getListeners(KernelEvents::REQUEST)` is assigned on
line 67 and then never read. The loop at line 72 makes a second identical call to
`$dispatcher->getListeners(KernelEvents::REQUEST)`, making line 67 dead code. PHPStan level
9 should flag this as an unused variable.

**Fix:**
```php
// Remove line 67:
// $listeners = $dispatcher->getListeners(KernelEvents::REQUEST);

// Keep only:
foreach ($dispatcher->getListeners(KernelEvents::REQUEST) as $listener) {
```

---

### WR-04: `method_exists()` failure branch is untested in both enable and disable commands

**File:** `tests/Unit/Command/TenantMaintenanceEnableCommandTest.php`, `tests/Unit/Command/TenantMaintenanceDisableCommandTest.php`

**Issue:** The enable and disable commands both contain a defensive branch (lines 80-88) that
returns `Command::FAILURE` when the tenant entity is missing `setInMaintenance()`. This is
the only branch in both command `execute()` methods that has zero test coverage. As a result,
a regression that removes or skips this guard would not be caught by the test suite.

**Fix:** Add a test in each command's test class using a stub that implements `TenantInterface`
but omits `setInMaintenance()`:
```php
public function testEnableReturnsFailureWhenEntityLacksSetterMethod(): void
{
    // Concrete tenant with isInMaintenance() but no setInMaintenance()
    $tenant = new class implements TenantInterface {
        public function getSlug(): string { return 'acme'; }
        public function isInMaintenance(): bool { return false; } // not in maintenance
        // setInMaintenance intentionally absent
        // ... other interface methods
    };

    $this->repository->method('findOneBy')->willReturn($tenant);
    $this->landlordEm->expects($this->never())->method('flush');

    $tester = new CommandTester($this->makeCommand());
    $exitCode = $tester->execute(['slug' => 'acme']);

    $this->assertSame(Command::FAILURE, $exitCode);
}
```

---

## Info

### IN-01: `--format` option accepts arbitrary values and silently falls back to `txt`

**File:** `src/Command/TenantMaintenanceStatusCommand.php:62-63`

**Issue:** The `format` option is declared as `VALUE_REQUIRED` with default `'txt'`, but no
validation restricts it to `['txt', 'json']`. An unknown format like `--format=csv` is
silently treated as `txt`. This is a minor UX issue and could mislead operators who typo the
flag.

**Fix:**
```php
// In configure():
$this->addOption(
    'format',
    null,
    InputOption::VALUE_REQUIRED,
    'Output format: txt (default) or json',
    'txt',
)
// In execute(), add validation after $format is resolved:
if (!\in_array($format, ['txt', 'json'], true)) {
    $io->error(sprintf('Unknown format "%s". Use "txt" or "json".', $format));
    return Command::FAILURE;
}
```

---

### IN-02: Docblock in `TenantMaintenanceConfigTrait` inaccurately describes Doctrine-optional behavior

**File:** `src/Maintenance/TenantMaintenanceConfigTrait.php:18-19`

**Issue:** The docblock states: "The #[ORM\Column] attribute is only honored when doctrine/orm
is installed; with Doctrine absent the trait still works as plain PHP property storage."
This is accurate for PHP 8.2+ (attribute class names are not validated at class load time,
only by the Reflection API). However, the claim could mislead a reader into believing the
`use Doctrine\ORM\Mapping as ORM;` import is a no-op when Doctrine is absent. In practice
this is safe — the `use` alias does not trigger autoloading — but the explanation is subtly
imprecise.

The REAL reason Doctrine absence is safe is that PHP does not autoload attribute class names
at class definition time; the `use` alias is purely a compile-time rename with no runtime
effect. The docblock should say this explicitly to avoid future confusion.

**Fix:** Amend the docblock:
```
 * The #[ORM\Column] attribute is only resolved by Doctrine when it scans mappings via
 * the Reflection API. PHP does not autoload the `Doctrine\ORM\Mapping` namespace at class
 * load time — the `use ... as ORM` alias is a compile-time rename with no runtime cost.
 * With Doctrine absent the trait works as a plain PHP property storage.
```

---

_Reviewed: 2026-07-01_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: deep_
