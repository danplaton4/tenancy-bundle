---
phase: 25-shared-entities-sync-mode
plan: "02"
subsystem: database
tags: [doctrine-orm, compiler-pass, symfony-di, shared-entity, mutual-exclusion]

requires:
  - phase: 25-01
    provides: src/Attribute/Shared.php — the #[Shared] attribute FQCN reflected by the pass
  - phase: 25-00
    provides: SharedEntityMutualExclusionPassTest with 3 skip-guarded RED methods (SHARE-01-l)

provides:
  - SharedEntityMutualExclusionPass — compile-time guard throwing \LogicException when
    both #[Shared] and #[TenantAware] are present on a tenancy.shared_entity-tagged class
  - tenancy.shared_entity container tag — the discovery mechanism for the mutual-exclusion pass
    (users must tag their shared entity service definitions with this tag)

affects:
  - 25-04 (TenancyBundle::build() wires this pass — register with interface_exists guard)
  - Phase 28 (DX-03 PHPStan rule adds editor-time detection on top of this boot-time guard)
  - docs (Plan 25-04 docs note must document the tenancy.shared_entity tag requirement)

tech-stack:
  added: []
  patterns:
    - "SharedEntityMutualExclusionPass: interface_exists(EntityManagerInterface) early-return + findTaggedServiceIds('tenancy.shared_entity') loop + ReflectionClass::getAttributes() co-presence check — exact structural twin of FilesystemContractPass"
    - "Throw message: bare \\LogicException with sprintf, starts with 'tenancy:' prefix, names the offending class via %s — matches existing compiler pass convention"
    - "Test fixture annotation: fixture classes in unit tests annotated with actual production PHP attributes once Plan 25-01 landed; Wave 0 left them bare to prevent PHP attribute-class-resolution failures"

key-files:
  created:
    - src/DependencyInjection/Compiler/SharedEntityMutualExclusionPass.php
  modified:
    - tests/Unit/DependencyInjection/Compiler/SharedEntityMutualExclusionPassTest.php

key-decisions:
  - "Discovery mechanism (A1 resolved): tenancy.shared_entity container tag — mirrors tenancy.scoped in FilesystemContractPass; only tagged classes are inspected at compile time. A class never registered as a tagged service is not checked at compile time (Phase 28 PHPStan rule catches it at edit time)."
  - "Throw message format: 'tenancy: entity \"%s\" cannot carry both #[Shared] and #[TenantAware]. A shared entity is a landlord-side master; a TenantAware entity is tenant-scoped. Pick one.' — starts with 'tenancy:' prefix, names offending class via %s"
  - "Test fixture classes annotated with real PHP attributes in Plan 25-02 (as intended in Wave 0 summary) — BothAttributesEntity: #[Shared]+#[TenantAware], OnlySharedEntity: #[Shared], UntaggedBothAttributesEntity: #[Shared]+#[TenantAware]"
  - "Registration in TenancyBundle::build() deferred to Plan 25-04 per plan scope"

patterns-established:
  - "Compiler pass mutual-exclusion guard: implements CompilerPassInterface, private const TAG = 'tenancy.shared_entity', interface_exists guard, findTaggedServiceIds loop, ReflectionClass getAttributes co-presence check"

requirements-completed: [SHARE-01]

duration: 3min
completed: "2026-06-11"
---

# Phase 25 Plan 02: SharedEntityMutualExclusionPass Summary

**Boot-time compile-time guard (D-04/DEC-SHARE-03): throws \LogicException when a tenancy.shared_entity-tagged class carries both #[Shared] and #[TenantAware], discovered via findTaggedServiceIds mirroring FilesystemContractPass**

## Performance

- **Duration:** ~3 min
- **Started:** 2026-06-11T08:13:50Z
- **Completed:** 2026-06-11T08:17:00Z
- **Tasks:** 1
- **Files modified:** 1 created, 1 modified

## Accomplishments

- `SharedEntityMutualExclusionPass` created: `interface_exists(EntityManagerInterface)` early-return, `findTaggedServiceIds('tenancy.shared_entity')` loop, `ReflectionClass::getAttributes()` co-presence check, `\LogicException` throw with `tenancy:` prefix and class name
- All 3 `SharedEntityMutualExclusionPassTest` methods GREEN: `testMutualExclusionGuardThrows`, `testNoExceptionWhenOnlySharedPresent`, `testUntaggedClassIsIgnored`
- Test fixture classes annotated with real `#[Shared]`/`#[TenantAware]` attributes (Wave 0 deferred this to Plan 25-02 intentionally)
- PHPStan level 9 clean, php-cs-fixer clean, full unit suite 561 tests pass (1 skipped — remaining Wave 0 stubs awaiting Plans 25-03/25-04)
- Open question A1 (discovery mechanism) resolved: `tenancy.shared_entity` container tag is the compile-time discovery mechanism

## Discovery Mechanism (A1 Resolution)

The pass uses `$container->findTaggedServiceIds('tenancy.shared_entity')` — the same tag-walking pattern as `FilesystemContractPass` uses `'tenancy.scoped'`. Users must register their shared entity service definitions with the `tenancy.shared_entity` container tag for the guard to inspect them. This is documented in the class docblock and must be surfaced in Plan 25-04's user-facing docs.

## Throw Message Format

```
tenancy: entity "App\Entity\SomeEntity" cannot carry both #[Shared] and #[TenantAware]. A shared entity is a landlord-side master; a TenantAware entity is tenant-scoped. Pick one.
```

## Task Commits

1. **Task 1: SharedEntityMutualExclusionPass compile-time guard** - `d064660` (feat)

**Plan metadata:** _(this commit)_

## Files Created/Modified

- `src/DependencyInjection/Compiler/SharedEntityMutualExclusionPass.php` — Compile-time mutual-exclusion guard (D-04/DEC-SHARE-03): walks `tenancy.shared_entity`-tagged services, throws on `#[Shared]`+`#[TenantAware]` co-presence
- `tests/Unit/DependencyInjection/Compiler/SharedEntityMutualExclusionPassTest.php` — Added `use` imports for `Shared`/`TenantAware`; annotated fixture classes with real PHP attributes

## Decisions Made

1. **Discovery mechanism (A1 resolved):** `tenancy.shared_entity` container tag mirrors `tenancy.scoped` in `FilesystemContractPass`. Only tagged classes are inspected at compile time. Phase 28 PHPStan catches untagged classes at edit time.

2. **Test fixture annotation deferred to Plan 25-02 (confirmed):** Wave 0 intentionally left fixture classes unannotated to prevent PHP attribute-class-resolution failures while `Shared` didn't exist. Plan 25-02 added the real annotations once `Shared` and `TenantAware` both exist.

3. **Registration in `TenancyBundle::build()` deferred to Plan 25-04:** Per plan scope, the pass is not yet wired into the bundle's `build()` method.

## Deviations from Plan

None — plan executed exactly as written.

## Issues Encountered

None.

## Self-Check

- [x] `src/DependencyInjection/Compiler/SharedEntityMutualExclusionPass.php` created
- [x] `tests/Unit/DependencyInjection/Compiler/SharedEntityMutualExclusionPassTest.php` updated
- [x] Commit `d064660` exists

## Self-Check: PASSED

## Next Phase Readiness

- Plan 25-03 can proceed immediately: creates `SharedEntityWriteInTenantContextException` and `SharedEntityWriteProtectionListener`
- Plan 25-04 must register `SharedEntityMutualExclusionPass` in `TenancyBundle::build()` with `interface_exists(\Doctrine\ORM\EntityManagerInterface::class)` guard
- Plan 25-04 docs note must document the `tenancy.shared_entity` tag requirement for users

---
*Phase: 25-shared-entities-sync-mode*
*Completed: 2026-06-11*
