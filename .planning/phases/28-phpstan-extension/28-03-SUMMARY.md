---
phase: 28-phpstan-extension
plan: 03
subsystem: testing
tags: [phpstan, phpstan-rules, static-analysis, doctrine, reflection, shared-entity-leak]

# Dependency graph
requires:
  - phase: 28-phpstan-extension
    plan: 01
    provides: "extension.neon wired with SharedEntityLeakRule skeleton + AttributeHierarchyHelper service"
  - phase: 28-phpstan-extension
    plan: 02
    provides: "TenantIdDriftRule pattern: inline ClassReflection hierarchy walk, no helper"

provides:
  - "SharedEntityLeakRule (Rule 2) — full implementation: conservative MethodCall rule detecting concrete EntityManager querying a #[Shared] entity"
  - "RuleTestCase test suite: 3 tests covering fires (line 15), gated-off (D-01), conservative-silent (D-03)"
  - "PSR-4 fixture classes: SharedProductViolating (#[Shared] entity), SharedEntityLeakViolating (concrete EM caller), SharedEntityLeakClean (interface caller + non-shared entity)"
  - "AttributeHierarchyHelper removed (dead code — neither Rule 1/2/3 uses it); extension.neon + Helper/ directory cleaned up"

affects:
  - 28-04
  - "any phase querying #[Shared] entities through Doctrine EntityManager"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "SharedEntityLeakRule: inject ReflectionProvider (not new ReflectionClass) to avoid phpstanApi.runtimeReflection level-9 error"
    - "SharedEntityLeakRule: use $scope->getType($node->var)->getObjectClassNames() to detect concrete EntityManager (not interface)"
    - "SharedEntityLeakRule: use $scope->getType($args[0]->value)->getConstantStrings() to extract literal ::class arg"
    - "SharedEntityLeakRule: inline ClassReflection.getParentClass() hierarchy walk for #[Shared] detection (same as Rules 1 & 3)"
    - "RuleTestCase: pass both entity fixture file + violating fixture file to analyse() so ReflectionProvider finds the entity FQCN"
    - "RuleTestCase: createReflectionProvider() used in getRule() to inject PHPStan-native ReflectionProvider into rule"
    - "Helper decision documented: AttributeHierarchyHelper removed when all three rules inline the hierarchy walk"

key-files:
  created:
    - tests/Unit/PHPStan/Rule/SharedEntityLeakRuleTest.php
    - tests/Unit/PHPStan/Rule/Fixtures/SharedProductViolating.php
    - tests/Unit/PHPStan/Rule/Fixtures/SharedEntityLeakViolating.php
    - tests/Unit/PHPStan/Rule/Fixtures/SharedEntityLeakClean.php
  modified:
    - src/PHPStan/Rule/SharedEntityLeakRule.php
    - extension.neon
    - phpstan.neon
  deleted:
    - src/PHPStan/Rule/Helper/AttributeHierarchyHelper.php

key-decisions:
  - "SharedEntityLeakRule uses ReflectionProvider (injected via constructor) for entity class lookup — avoids phpstanApi.runtimeReflection error from 'new ReflectionClass()'"
  - "SharedEntityLeakRule inlines ClassReflection.getParentClass() loop for #[Shared] hierarchy walk — identical pattern to MutualExclusionRule and TenantIdDriftRule; does NOT use AttributeHierarchyHelper"
  - "AttributeHierarchyHelper removed: all three rules inline the hierarchy walk (BetterReflection adapter type incompatibility at level 9 is the root cause); dead code must not ship in extension.neon"
  - "extension.neon SharedEntityLeakRule wired with reflectionProvider: @reflectionProvider (PHPStan built-in service) + checkSharedEntityLeaks: %tenancy.checkSharedEntityLeaks%"
  - "phpstan.neon property.onlyWritten suppression removed (SharedEntityLeakRule fully implemented; no more skeleton)"
  - "Fixture line numbers: tenancy.sharedEntityLeak fires at line 15 of SharedEntityLeakViolating.php (the $em->find() call)"
  - "RuleTestCase gated-off test: set private $checkLeaks = false before calling analyse() — same rule instance pattern as TenantIdDriftRuleTest's getRule() approach"

patterns-established:
  - "PHPStan MethodCall rule: use getObjectClassNames() on caller type to identify concrete class (not interface)"
  - "PHPStan MethodCall rule: use getConstantStrings() on first arg type to resolve literal ::class values"
  - "PHPStan rule + ReflectionProvider: inject via constructor and @reflectionProvider neon reference"
  - "PHPStan fixture: pass entity class file alongside code fixture file to analyse() so FQCN is visible in ReflectionProvider"
  - "Dead code removal: when helper is orphaned by all consumers inlining the walk, remove it — shipped extension must carry no dead code"

requirements-completed: [DX-03]

# Metrics
duration: 35min
completed: 2026-06-16
---

# Phase 28 Plan 03: SharedEntityLeakRule (Rule 2) Summary

**SharedEntityLeakRule fully implemented as conservative MethodCall rule — fires tenancy.sharedEntityLeak when concrete Doctrine\ORM\EntityManager queries a #[Shared] entity; gated by checkSharedEntityLeaks; silent on EntityManagerInterface callers; AttributeHierarchyHelper removed as dead code**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-06-16T19:00:00Z
- **Completed:** 2026-06-16T19:35:00Z
- **Tasks:** 2 (both TDD)
- **Files modified:** 8 (4 created, 3 modified, 1 deleted)

## Accomplishments

- Replaced Plan 01 skeleton `SharedEntityLeakRule::processNode()` with full Rule 2 implementation
- Conservative (D-03): fires only when caller is concrete `Doctrine\ORM\EntityManager` (not `EntityManagerInterface`) AND first arg is a literal `::class` constant resolving to a `#[Shared]` entity
- D-01 gate: `if (!$this->checkSharedEntityLeaks) { return []; }` as the first statement of processNode()
- Doctrine optional-dep guard: `interface_exists(\Doctrine\ORM\EntityManagerInterface::class)` — mirrors SharedEntityMutualExclusionPass line 44
- Methods inspected: `find`, `getReference`, `getRepository` (entity is the first `::class` arg)
- 3 RuleTestCase tests all pass: fires (line 15), gated-off (false gate), conservative-silent (interface + non-shared)
- Removed orphaned `AttributeHierarchyHelper` class + `Helper/` directory — all three rules inline the hierarchy walk
- Removed `property.onlyWritten` suppression from `phpstan.neon` — no more skeleton stubs
- PHPStan level 9: 0 errors; php-cs-fixer: clean; 757 tests pass (3 added)

## Task Commits

1. **Task 1: Implement SharedEntityLeakRule** — `f0186d1` (feat)
2. **Task 2: SharedEntityLeakRuleTest + fixtures** — `aa199b1` (feat)

## Files Created/Modified

- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/src/PHPStan/Rule/SharedEntityLeakRule.php` — Full Rule 2 implementation; ReflectionProvider injection; inline ClassReflection hierarchy walk; `tenancy.sharedEntityLeak` identifier
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/extension.neon` — Removed helper service (AttributeHierarchyHelper); SharedEntityLeakRule wired with reflectionProvider + checkSharedEntityLeaks only
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/phpstan.neon` — Removed property.onlyWritten suppression (skeleton rules fully implemented)
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/tests/Unit/PHPStan/Rule/SharedEntityLeakRuleTest.php` — RuleTestCase; 3 tests; createReflectionProvider() in getRule(); checkLeaks bool field for gate test
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/tests/Unit/PHPStan/Rule/Fixtures/SharedProductViolating.php` — PSR-4 #[Shared] entity fixture (line 10)
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/tests/Unit/PHPStan/Rule/Fixtures/SharedEntityLeakViolating.php` — Concrete EntityManager $em; $em->find(SharedProductViolating::class, 1) at line 15 (fires)
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/tests/Unit/PHPStan/Rule/Fixtures/SharedEntityLeakClean.php` — EntityManagerInterface caller (D-03 conservative) + concrete EM querying non-#[Shared] entity (both silent)
- **DELETED:** `src/PHPStan/Rule/Helper/AttributeHierarchyHelper.php` + `src/PHPStan/Rule/Helper/` directory

## Decisions Made

### Helper Decision (as required by prior wave context)

**DECISION 2 selected: inline the hierarchy walk + remove AttributeHierarchyHelper.**

Rationale: The rule uses `ReflectionProvider::getClass()` to obtain a `ClassReflection`, then calls `ClassReflection::getNativeReflection()` for attribute access. This is the same pattern as Rules 1 & 3 (both of which hit the BetterReflection adapter incompatibility and inlined the walk). The helper accepted `\ReflectionClass<object>` but all usages would have gone through `ClassReflection::getNativeReflection()` which returns BetterReflection adapter types.

With SharedEntityLeakRule also inlining the walk, the helper was orphaned (no remaining references from Rules 1, 2, or 3). Per the prior wave context: "the shipped extension must carry no dead code (28-04's level-9 self-analysis + dogfood runs over this)." The helper class file, the `Helper/` directory, the neon service registration, and the `property.onlyWritten` suppression have all been removed.

### ReflectionProvider Decision

Instead of `new \ReflectionClass($entityClass)` (which triggers PHPStan's `phpstanApi.runtimeReflection` error), we inject `ReflectionProvider` and call `$this->reflectionProvider->getClass($entityClass)` to get a `ClassReflection`. This is PHPStan's intended pattern for entity class lookup at analysis time.

### Concrete EntityManager Detection

`$scope->getType($node->var)->getObjectClassNames()` returns the list of class names for the caller type. Checking for `\Doctrine\ORM\EntityManager::class` (exact match) ensures we only fire on the concrete class, not when the caller is typed as `EntityManagerInterface`. This is the D-03 conservative implementation.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] phpstanApi.runtimeReflection: use ReflectionProvider instead of new \ReflectionClass()**
- **Found during:** Task 1 (PHPStan analysis after first implementation)
- **Issue:** Initial implementation used `new \ReflectionClass($entityClass)` for entity attribute lookup. PHPStan level 9 reports `phpstanApi.runtimeReflection`: "Creating new ReflectionClass is a runtime reflection concept that might not work in PHPStan because it uses fully static reflection engine. Use objects retrieved from ReflectionProvider instead."
- **Fix:** Added `ReflectionProvider $reflectionProvider` as constructor parameter; replaced `new \ReflectionClass()` with `$this->reflectionProvider->hasClass()` + `$this->reflectionProvider->getClass()`. Updated `extension.neon` to inject `reflectionProvider: @reflectionProvider`.
- **Files modified:** `src/PHPStan/Rule/SharedEntityLeakRule.php`, `extension.neon`
- **Verification:** `vendor/bin/phpstan analyse` exits 0
- **Committed in:** `f0186d1` (Task 1)

**2. [Rule 1 - Bug] ignore.parseError: @phpstan-ignore in docblock causes parse error**
- **Found during:** Task 1 (PHPStan analysis)
- **Issue:** Docblock text mentioning `"@phpstan-ignore tenancy.sharedEntityLeak"` (with literal @phpstan-ignore) caused PHPStan to try to parse it as a real annotation and fail with `ignore.parseError`.
- **Fix:** Rewrote the docblock to describe the suppression identifier by name without using the `@phpstan-ignore` prefix text.
- **Files modified:** `src/PHPStan/Rule/SharedEntityLeakRule.php`
- **Verification:** `vendor/bin/phpstan analyse` exits 0
- **Committed in:** `f0186d1` (Task 1)

**3. [Rule 1 - Bug] parameter.notFound: stale @param in hasSharedInHierarchy docblock**
- **Found during:** Task 1 (PHPStan analysis)
- **Issue:** Leftover `@param class-string $attribute` in the `hasSharedInHierarchy()` docblock referenced a parameter that didn't exist in the method signature.
- **Fix:** Removed the stale `@param` line.
- **Files modified:** `src/PHPStan/Rule/SharedEntityLeakRule.php`
- **Verification:** `vendor/bin/phpstan analyse` exits 0
- **Committed in:** `f0186d1` (Task 1)

**4. [Rule 3 - Blocking] ReflectionProvider not finding entity class: fixture must be passed to analyse()**
- **Found during:** Task 2 (RuleTestCase fails with "Class not found in ReflectionProvider")
- **Issue:** Initially had the `#[Shared]` entity class (`SharedProductViolating`) inside the same file as the function that queries it (`SharedEntityLeakViolating.php`). PHPStan could not find the entity FQCN in the ReflectionProvider because PSR-4 requires filename to match class name.
- **Fix:** Split into two files: `SharedProductViolating.php` (the entity, PSR-4 compliant) and `SharedEntityLeakViolating.php` (the querying function). Pass both files to `analyse()` in the test.
- **Files modified:** `tests/Unit/PHPStan/Rule/Fixtures/SharedEntityLeakViolating.php`, new `tests/Unit/PHPStan/Rule/Fixtures/SharedProductViolating.php`, `tests/Unit/PHPStan/Rule/SharedEntityLeakRuleTest.php`
- **Verification:** All 3 RuleTestCase tests pass
- **Committed in:** `aa199b1` (Task 2)

---

**Total deviations:** 4 auto-fixed (3 Rule 1 bugs, 1 Rule 3 blocking)
**Impact on plan:** All fixes necessary for PHPStan level 9 compliance and correct test operation. No scope creep. The PSR-4 file split improves structure (consistent with Rules 1 & 3 fixture approach).

## Plan Output Notes

Per plan `<output>` section:
- **Actual fixture line numbers:** `tenancy.sharedEntityLeak` fires at line 15 of `SharedEntityLeakViolating.php` (the `$em->find(SharedProductViolating::class, 1)` call).
- **Concrete-vs-interface EM type check:** `$scope->getType($node->var)->getObjectClassNames()` — iterates class names, fires only when `\Doctrine\ORM\EntityManager::class` is found (exact string match). Returns `[]` on `EntityManagerInterface` caller.
- **Methods inspected:** `find`, `getReference`, `getRepository` — checked via `Node\Identifier::toString()` against `self::EM_QUERY_METHODS` constant.
- **Two-instance getRule() approach:** Private `$checkLeaks` bool field (default `true`); `testSilentWhenGatedOff()` sets `$this->checkLeaks = false` before calling `analyse()`. The `getRule()` method reads `$this->checkLeaks` — PHPUnit calls `getRule()` fresh for each test.

## Issues Encountered

- PHPStan `phpstanApi.runtimeReflection`: discovered that `new \ReflectionClass()` is forbidden in PHPStan rule implementations; must use `ReflectionProvider::getClass()` instead. This is a new pattern not established by Rules 1 & 3 (they use `ClassReflection::getNativeReflection()` which returns an already-BetterReflection-backed object). For Rule 2, the entity class comes as a string from `getConstantStrings()`, so `ReflectionProvider` is the correct entry point.
- PSR-4 one-class-per-file constraint (same as Plan 01 deviation 3): the violating fixture initially had both the entity and the querying function in one file; split required.

## Known Stubs

None — `SharedEntityLeakRule::processNode()` is fully implemented.

All three rules in the extension are now fully implemented (Rules 1, 2, and 3).

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- **Plan 04 (level-9 self-analysis + dogfood)**: All three rules fully implemented. `extension.neon` is clean (no dead services). `phpstan.neon` has no stale suppressions. Ready for dogfood analysis of the extension itself.
- **Consumers**: Any project running `phpstan analyse` with the tenancy extension will now catch cross-EM #[Shared] entity queries when the caller is typed as the concrete `EntityManager`.

---
*Phase: 28-phpstan-extension*
*Completed: 2026-06-16*
