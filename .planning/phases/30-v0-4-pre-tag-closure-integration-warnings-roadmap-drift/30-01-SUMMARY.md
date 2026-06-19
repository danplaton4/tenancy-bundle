---
phase: 30-v0-4-pre-tag-closure-integration-warnings-roadmap-drift
plan: "01"
subsystem: shared-entities
tags:
  - W-01
  - W-02
  - W-03
  - refactor
  - testability
dependency_graph:
  requires:
    - Phase 25: SharedEntitySyncSubscriber + SharedEntityWriteProtectionListener (consumers)
    - Phase 26: SharedEntityCopierInterface (existing mock seam pattern)
    - Phase 27: SharedEntityChangedMessageHandler (second duplicate consumer)
  provides:
    - TenantEmSwitcherInterface: mockable contract for tenant EM switch/restore
    - TenantEmSwitcher: single source of truth for per-change/per-message switch path
    - SharedEntityCopierInterface type-hints in subscriber + write-protection listener
  affects:
    - Phase 30 plan 02 (roadmap drift — no code dependency)
tech_stack:
  added:
    - src/Shared/TenantEmSwitcherInterface.php
    - src/Shared/TenantEmSwitcher.php
    - tests/Unit/Shared/TenantEmSwitcherTest.php
    - tests/Unit/Subscriber/SharedEntityWriteProtectionListenerTest.php
  patterns:
    - final-class + interface pair (same as TenantConnectionInterface / SharedEntityCopierInterface)
    - constructor-promoted private readonly properties
    - strict_types=1 everywhere
key_files:
  created:
    - src/Shared/TenantEmSwitcherInterface.php
    - src/Shared/TenantEmSwitcher.php
    - tests/Unit/Shared/TenantEmSwitcherTest.php
    - tests/Unit/Subscriber/SharedEntityWriteProtectionListenerTest.php
  modified:
    - src/Subscriber/SharedEntitySyncSubscriber.php
    - src/MessageHandler/SharedEntityChangedMessageHandler.php
    - src/Subscriber/SharedEntityWriteProtectionListener.php
    - src/Command/SharedEntityResyncCommand.php
    - src/TenancyBundle.php
    - tests/Unit/Subscriber/SharedEntitySyncSubscriberAsyncTest.php
    - tests/Unit/Subscriber/SharedEntitySyncSubscriberSharedDbTest.php
decisions:
  - "W-02: TenantEmSwitcher adopts use Doctrine\\DBAL\\Connection; import style (handler convention, canonical form) for the instanceof check rather than subscriber's fully-qualified \\Doctrine\\DBAL\\Connection inline form"
  - "W-02: switchTo()/restore() bodies copied byte-identical from subscriber (CR-01/CR-02 semantics preserved, no behavior divergence)"
  - "W-01: SharedEntityCopierInterface alias replaces SharedEntityCopier concrete type in two consumers — zero interface edits needed since isSyncInProgress() was already on the interface"
  - "Integration test stale-cache mitigation: purged /tmp/tenancy_* caches after DI wiring change to prevent cached container serving 6-arg subscriber"
  - "TenantInterface import removed from SharedEntityChangedMessageHandler after private method removal (no_unused_imports cs-fixer rule)"
metrics:
  duration: "~20 min"
  completed_date: "2026-06-19"
  tasks_completed: 3
  files_touched: 11
---

# Phase 30 Plan 01: W-01/W-02/W-03 Integration Warning Closure Summary

**One-liner:** Extracted TenantEmSwitcherInterface + final TenantEmSwitcher owning the single copy of switchTo()/restore(); wired both into subscriber and handler; swapped two SharedEntityCopier type-hints to SharedEntityCopierInterface; documented resync vs per-event asymmetry.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Create TenantEmSwitcherInterface + TenantEmSwitcher (W-02 + W-03) | bf01a3d | src/Shared/TenantEmSwitcherInterface.php, src/Shared/TenantEmSwitcher.php, tests/Unit/Shared/TenantEmSwitcherTest.php |
| 2 | Refactor subscriber + handler; W-01 type-hints; W-03 resync note; DI; fix existing tests | d4caf5c | src/Subscriber/SharedEntitySyncSubscriber.php, src/MessageHandler/SharedEntityChangedMessageHandler.php, src/Subscriber/SharedEntityWriteProtectionListener.php, src/Command/SharedEntityResyncCommand.php, src/TenancyBundle.php, tests/Unit/Subscriber/SharedEntitySyncSubscriberAsyncTest.php, tests/Unit/Subscriber/SharedEntitySyncSubscriberSharedDbTest.php |
| 3 | Add SharedEntityWriteProtectionListenerTest (D-07 mock-copier seam) | 528b9cd | tests/Unit/Subscriber/SharedEntityWriteProtectionListenerTest.php |

## What Was Built

### W-02: TenantEmSwitcherInterface + TenantEmSwitcher (single source of truth)

`src/Shared/TenantEmSwitcherInterface.php` — mockable interface declaring `switchTo(TenantInterface $tenant): EntityManagerInterface` and `restore(?TenantInterface $previousTenant): void`. Interface docblock cites "same pattern as TenantConnectionInterface" per project convention.

`src/Shared/TenantEmSwitcher.php` — final class implementing the interface. `switchTo()` and `restore()` bodies copied byte-identical from `SharedEntitySyncSubscriber::switchToTenant()` / `restoreTenantContext()` (the canonical source). Uses `use Doctrine\DBAL\Connection;` import style. Class docblock explains the lightweight per-change path vs `SharedEntityResyncCommand`'s full bootstrapper-chain path (W-03).

Both consumers now delegate: `$this->switcher->switchTo($tenant)` / `$this->switcher->restore($previousTenant)`. The private `switchToTenant()` / `restoreTenantContext()` methods have been removed from `SharedEntitySyncSubscriber` and `SharedEntityChangedMessageHandler`.

**CR-01 / CR-02 invariants preserved:** The save/restore framing (`$previousTenant = hasTenant() ? getTenant() : null` then `finally { switcher->restore(...) }`) remains in both consumers — only the switch mechanics moved into the service.

### W-01: SharedEntityCopierInterface type-hint swaps

- `SharedEntitySyncSubscriber::$copier` — `SharedEntityCopier` → `SharedEntityCopierInterface`
- `SharedEntityWriteProtectionListener::$copier` — `SharedEntityCopier` → `SharedEntityCopierInterface`

Zero interface edits required — `isSyncInProgress()` was already declared on the interface.

### W-03: Asymmetry documentation

- `TenantEmSwitcher` class docblock documents the lightweight path and contrasts with `SharedEntityResyncCommand` + `@see SharedEntityResyncCommand::resyncForTenant()`
- `SharedEntityResyncCommand::resyncForTenant()` docblock expanded with "Full bootstrapper-chain path (intentional)" note explaining why CLI backfill uses `setTenant() + bootstrapperChain->boot()` while per-event fan-out uses the lightweight `TenantEmSwitcher`

### DI wiring

`TenancyBundle::loadExtension()` inside the `interface_exists(EntityManagerInterface::class)` block:
- Registers `tenancy.shared.em_switcher` → `TenantEmSwitcher::class` with args `[tenancy.context, doctrine]`
- Registers `TenantEmSwitcherInterface::class` alias → `tenancy.shared.em_switcher`
- Adds `service('tenancy.shared.em_switcher')` to subscriber args (position 6, before optional bus)
- Adds `service('tenancy.shared.em_switcher')` to handler args (appended after logger)

### D-07 mock-copier seam test

`tests/Unit/Subscriber/SharedEntityWriteProtectionListenerTest.php`:
- Test 1 (re-entrancy bypass): `isSyncInProgress()` → true, tenant active, `#[Shared]` entity scheduled → no throw
- Test 2 (throw-on-Shared-write): `isSyncInProgress()` → false, tenant active, `#[Shared]` entity in insertions → throws `SharedEntityWriteInTenantContextException`
- Test 3 (no-tenant bypass): `hasTenant()` → false → returns without consulting copier

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Stale integration test kernel cache after DI wiring change**
- **Found during:** Task 2 — first commit attempt triggered pre-commit PHPUnit which ran integration tests using cached containers
- **Issue:** Integration test kernels had cached `getTenancy_SharedEntitySyncSubscriberService.php` with 6 positional args; `ArgumentCountError: Too few arguments to function SharedEntitySyncSubscriber::__construct()` from 15 integration tests
- **Fix:** Purged `/tmp/tenancy_*` test kernel caches (`rm -rf $(php -r 'echo sys_get_temp_dir();')/tenancy_*`) then re-ran; all 770 tests passed
- **Files modified:** None (cache purge only)

**2. [Rule 2 - Auto-add] Removed unused TenantInterface import from SharedEntityChangedMessageHandler**
- **Found during:** Task 2 — after removing private switchToTenant/restoreTenantContext methods, `TenantInterface` was no longer used in the handler body
- **Fix:** Removed `use Tenancy\Bundle\TenantInterface;` import; php-cs-fixer `no_unused_imports` rule confirmed the removal
- **Files modified:** src/MessageHandler/SharedEntityChangedMessageHandler.php

## Verification Results

All phase gate checks passed:
- `vendor/bin/phpunit` — 770 tests, 3242 assertions, 2 skipped, 0 failures/errors
- `vendor/bin/phpstan analyse --memory-limit=512M` — [OK] No errors (L9)
- `vendor/bin/php-cs-fixer check --diff` — 0 diffs

## Known Stubs

None — all code paths are fully implemented. No placeholder values, no TODO/FIXME markers introduced.

## Threat Flags

No new security-relevant surface introduced. This is a pure refactor (extracting existing private methods into a service) — no new network endpoints, no new auth paths, no schema changes. The threat model invariants T-30-01 and T-30-02 are satisfied: byte-identical method bodies preserve CR-01/CR-02 semantics.

## Self-Check: PASSED

- src/Shared/TenantEmSwitcherInterface.php — FOUND
- src/Shared/TenantEmSwitcher.php — FOUND
- tests/Unit/Shared/TenantEmSwitcherTest.php — FOUND
- tests/Unit/Subscriber/SharedEntityWriteProtectionListenerTest.php — FOUND
- Commits bf01a3d, d4caf5c, 528b9cd — all verified in git log
