---
phase: 25-shared-entities-sync-mode
plan: "01"
subsystem: attribute
tags: [doctrine-orm, shared-entity, attribute, exception, php-attribute, logic-exception, wave-2]

requires:
  - phase: 25-shared-entities-sync-mode/25-00
    provides: Wave 0 test scaffold with skip-guards on Shared + SharedEntityWriteInTenantContextException class_exists checks

provides:
  - "Tenancy\\Bundle\\Attribute\\Shared — bare #[TARGET_CLASS] marker attribute (D-06)"
  - "Tenancy\\Bundle\\Exception\\SharedEntityWriteInTenantContextException — write-protection exception extends \\LogicException with forEntity(class, slug) factory (D-02)"

affects:
  - 25-02 (SharedEntityMutualExclusionPass — uses Shared attribute for validation)
  - 25-03 (SharedEntityWriteProtectionListener — throws SharedEntityWriteInTenantContextException)
  - 25-04 (SharedEntitySyncSubscriber — uses Shared attribute for isShared() check)

tech-stack:
  added: []
  patterns:
    - "Bare PHP 8 attribute: #[\\Attribute(\\Attribute::TARGET_CLASS)] final class with empty body — exact-analog of TenantAware.php"
    - "Static factory exception: final class extends \\LogicException + public static function forEntity(string $entityClass, string $tenantSlug): self with sprintf — exact-analog of MissingFilesystemConfigException.php"
    - "WR-01 no-retry invariant: \\LogicException (not \\RuntimeException) so Messenger does not retry programmer/operator errors"
    - "Wave N skip-guard addendum: when multiple production classes are required for a test to be meaningful, the skip-guard must check ALL of them (exception + listener)"

key-files:
  created:
    - src/Attribute/Shared.php
    - src/Exception/SharedEntityWriteInTenantContextException.php
  modified:
    - tests/Integration/SharedEntity/SharedEntitySyncIntegrationTest.php

key-decisions:
  - "D-06 confirmed: #[Shared] is a bare zero-param TARGET_CLASS marker, mirroring #[TenantAware] exactly"
  - "D-02 confirmed: SharedEntityWriteInTenantContextException extends \\LogicException (not \\RuntimeException) per WR-01 no-retry invariant; single static factory forEntity(class, slug)"
  - "[Rule 3 - Blocking] Skip-guard addendum: 3 write-protection tests un-skipped when exception class appeared but listener was absent; added SharedEntityWriteProtectionListener class_exists check to re-guard until Plan 25-03"

patterns-established:
  - "Exact-analog clone pattern: both new files mirror existing bundle files with only name + docblock changed"
  - "Multi-class skip-guard: when a test requires both an exception class and a listener, guard on BOTH with class_exists() || to prevent false un-skipping"

requirements-completed: [SHARE-01]

duration: 8min
completed: "2026-06-11"
---

# Phase 25 Plan 01: #[Shared] Attribute + Write-Protection Exception Summary

**`#[Shared]` bare TARGET_CLASS marker attribute (D-06) and `SharedEntityWriteInTenantContextException extends \LogicException` with `forEntity(class, slug)` factory (D-02) — the two leaf primitives that unblock Plans 25-02, 25-03, and 25-04**

## Performance

- **Duration:** ~8 min
- **Started:** 2026-06-11T08:10:00Z
- **Completed:** 2026-06-11T08:18:00Z
- **Tasks:** 2
- **Files modified:** 2 created, 1 modified (skip-guard fix)

## Accomplishments

- `src/Attribute/Shared.php` — `Tenancy\Bundle\Attribute\Shared`: `declare(strict_types=1)`, `#[\Attribute(\Attribute::TARGET_CLASS)]`, `final class Shared` with empty body. Docblock covers sync contract, write-protection, mutual-exclusion, shared_db no-op. Exact-analog clone of `TenantAware.php`.
- `src/Exception/SharedEntityWriteInTenantContextException.php` — `Tenancy\Bundle\Exception\SharedEntityWriteInTenantContextException`: `final class extends \LogicException` with `public static function forEntity(string $entityClass, string $tenantSlug): self` factory. Message includes entity class + tenant slug + remedy. `tenancy:` prefix. WR-01 no-retry invariant documented in docblock.
- `SharedTest` (`testSharedAttributeIsClassTarget` + `testSharedAttributeCanBeInstantiated`) both GREEN — un-skipped from Wave 0 scaffold as designed.
- Skip-guard fix: 3 write-protection integration tests (`testTenantSidePersistThrows`, `testTenantSideUpdateThrows`, `testTenantSideDeleteThrows`) re-guarded to check BOTH exception class AND listener class until Plan 25-03.

## Task Commits

1. **Task 1: #[Shared] marker attribute** - `613ff39` (feat)
2. **Task 2: SharedEntityWriteInTenantContextException** - `a0f1d2a` (feat, includes Rule 3 skip-guard fix)

## Files Created/Modified

- `src/Attribute/Shared.php` — bare `#[\Attribute(\Attribute::TARGET_CLASS)] final class Shared` marker, namespace `Tenancy\Bundle\Attribute`
- `src/Exception/SharedEntityWriteInTenantContextException.php` — `final class extends \LogicException`, `forEntity(string $entityClass, string $tenantSlug): self` factory
- `tests/Integration/SharedEntity/SharedEntitySyncIntegrationTest.php` — added `SharedEntityWriteProtectionListener` class_exists check to 3 write-protection skip-guards

## Decisions Made

1. **D-06 confirmed as bare marker**: `#[Shared]` has no constructor parameters. Mirrors `TenantAware.php` exactly. The per-tenant opt-out / selective sharing case is out of scope for Phase 25 per the context document.

2. **D-02 confirmed as \LogicException**: `SharedEntityWriteInTenantContextException` extends `\LogicException` not `\RuntimeException`. WR-01 no-retry invariant — a tenant-side write to a shared entity is a programmer/operator misconfiguration, not a transient fault Messenger should retry.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Fixed Wave 0 skip-guard gap for write-protection tests**
- **Found during:** Task 2 (SharedEntityWriteInTenantContextException creation — pre-commit hook PHPUnit run)
- **Issue:** Wave 0 skip-guards for `testTenantSidePersistThrows`, `testTenantSideUpdateThrows`, `testTenantSideDeleteThrows` checked only `class_exists(SharedEntityWriteInTenantContextException::class)`. Once the exception class existed, all 3 tests un-skipped and failed — the write-protection LISTENER (`SharedEntityWriteProtectionListener`, lands in 25-03) was absent, so the exception was never thrown. 3 FAILURES blocked the commit.
- **Fix:** Added `|| !class_exists(\Tenancy\Bundle\Subscriber\SharedEntityWriteProtectionListener::class)` to each of the 3 skip-guards. Tests re-skip until both the exception class AND the listener exist (Plan 25-03).
- **Files modified:** `tests/Integration/SharedEntity/SharedEntitySyncIntegrationTest.php`
- **Verification:** Full suite `vendor/bin/phpunit` — 705 tests, 0 failures, 14 skipped.
- **Committed in:** `a0f1d2a` (Task 2 commit)

---

**Total deviations:** 1 auto-fixed (1 blocking)
**Impact on plan:** Essential — without the skip-guard fix the commit hook blocks and the exception cannot be committed. No scope creep.

## Issues Encountered

Pre-commit hook runs the full PHPUnit suite. When `SharedEntityWriteInTenantContextException` was created, 3 tests immediately un-skipped and failed because the listener that throws the exception doesn't exist yet (25-03). Resolved by adding the listener class_exists check to the skip-guards (Rule 3 deviation).

## Known Stubs

None — both delivered files are complete primitives with no hardcoded stubs or placeholders.

## Threat Flags

No new threat surface introduced. `SharedEntityWriteInTenantContextException` is the exception class referenced in T-25-01 (Tampering: tenant write to `#[Shared]` corrupting landlord master). The threat's actual enforcement mechanism (the listener that throws this exception) lands in Plan 25-03.

## Next Phase Readiness

- **Plan 25-02** (SharedEntityMutualExclusionPass) can now use `Tenancy\Bundle\Attribute\Shared` in its attribute inspection logic.
- **Plan 25-03** (SharedEntityWriteProtectionListener) can now throw `SharedEntityWriteInTenantContextException::forEntity(...)` and un-skip 3 integration tests.
- **Plan 25-04** (SharedEntitySyncSubscriber) can now check `$rc->getAttributes(Shared::class)` in the fan-out logic.
- Both FQCNs are locked:
  - `Tenancy\Bundle\Attribute\Shared`
  - `Tenancy\Bundle\Exception\SharedEntityWriteInTenantContextException`
  - Factory signature: `public static function forEntity(string $entityClass, string $tenantSlug): self`

---

## Self-Check: PASSED

- `src/Attribute/Shared.php` — FOUND
- `src/Exception/SharedEntityWriteInTenantContextException.php` — FOUND
- Commit `613ff39` — FOUND in git log
- Commit `a0f1d2a` — FOUND in git log
- Test `testSharedAttributeIsClassTarget` — GREEN (verified before commit)
- Test `testSharedAttributeCanBeInstantiated` — GREEN (verified before commit)
- Full suite: 705 tests, 0 failures, 14 skipped

---
*Phase: 25-shared-entities-sync-mode*
*Completed: 2026-06-11*
