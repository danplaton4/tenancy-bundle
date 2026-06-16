---
phase: 28-phpstan-extension
plan: 02
subsystem: testing
tags: [phpstan, phpstan-rules, static-analysis, doctrine, reflection, tenant-id-drift]

# Dependency graph
requires:
  - phase: 28-phpstan-extension
    plan: 01
    provides: "extension.neon wired with TenantIdDriftRule skeleton + AttributeHierarchyHelper service"

provides:
  - "TenantIdDriftRule (Rule 3) — full implementation: missing/nullable/non-string tenant_id detection via ORM Column reflection"
  - "RuleTestCase test suite: 5 tests covering missing, nullable, non-string, valid (clean), inherited TenantAware"
  - "PSR-4 fixture classes: TenantIdMissingViolating, TenantIdNullableViolating, TenantIdNonStringViolating, TenantIdValidClean, TenantAwareParent, TenantIdMissingChild"

affects:
  - 28-03
  - "any plan adding TenantAware entities (Rule 3 now enforces tenant_id config)"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "TenantIdDriftRule: TenantAware hierarchy walk inlined via ClassReflection.getParentClass() loop (same as MutualExclusionRule — avoids BetterReflection adapter type incompatibility)"
    - "Reflection fallback: walks all class properties across hierarchy, resolves column name from explicit name: arg, positional arg, or camelCase->snake_case property name"
    - "ObjectMetadataResolver optional path: double-guarded by null check + class_exists; falls through to reflection when null metadata returned"
    - "evaluateFinding() shared method: single evaluation logic for both metadata and reflection paths"

key-files:
  created:
    - tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php
    - tests/Unit/PHPStan/Rule/Fixtures/TenantIdMissingViolating.php
    - tests/Unit/PHPStan/Rule/Fixtures/TenantIdNullableViolating.php
    - tests/Unit/PHPStan/Rule/Fixtures/TenantIdNonStringViolating.php
    - tests/Unit/PHPStan/Rule/Fixtures/TenantIdValidClean.php
    - tests/Unit/PHPStan/Rule/Fixtures/TenantAwareParent.php
    - tests/Unit/PHPStan/Rule/Fixtures/TenantIdMissingChild.php
  modified:
    - src/PHPStan/Rule/TenantIdDriftRule.php
    - extension.neon
    - phpstan.neon

key-decisions:
  - "TenantIdDriftRule does NOT use AttributeHierarchyHelper for the TenantAware hierarchy walk — inlined ClassReflection.getParentClass() loop instead, same as MutualExclusionRule deviation from Plan 01"
  - "Removed $helper constructor param from TenantIdDriftRule (it was unused; PHPStan level 9 reported property.onlyWritten); updated extension.neon to remove the helper argument"
  - "phpstan.neon property.onlyWritten suppression pattern updated to reference only SharedEntityLeakRule (TenantIdDriftRule no longer suppressed)"
  - "Fixture line numbers for violating classes: all three violating fixtures report errors at line 10 (first #[TenantAware] attribute); TenantIdMissingChild reports line 9 (#[ORM\\Entity])"
  - "Accepted string types: string, ascii_string, guid, uuid — case-insensitive strtolower comparison"
  - "camelCase->snake_case property name fallback: preg_replace('/([A-Z])/', '_$1', lcfirst($propName)) + strtolower — handles $tenantId -> tenant_id"

patterns-established:
  - "PHPStan rule hierarchy walk: inline ClassReflection.getParentClass() loop, not AttributeHierarchyHelper (BetterReflection adapter incompatibility at level 9)"
  - "Optional phpstan-doctrine path: ?object constructor param + class_exists() guard + null metadata fallthrough"
  - "checkViaMetadata / checkViaReflection / evaluateFinding separation: both metadata and reflection paths share evaluateFinding() for consistent error messages"
  - "Doctrine ClassMetadata accessed via (array) cast to avoid hard import dependency"

requirements-completed: [DX-03]

# Metrics
duration: 35min
completed: 2026-06-16
---

# Phase 28 Plan 02: TenantIdDriftRule (Rule 3) Summary

**TenantIdDriftRule fully implemented via ORM Column reflection fallback — detects missing, nullable, and non-string tenant_id on #[TenantAware] entities with 5-test RuleTestCase coverage including inherited TenantAware hierarchy**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-06-16T16:15:00Z
- **Completed:** 2026-06-16T16:50:00Z
- **Tasks:** 2 (both TDD)
- **Files modified:** 10

## Accomplishments

- Replaced Plan 01 skeleton `TenantIdDriftRule::processNode()` with full Rule 3 logic
- Reflection fallback primary path: walks `#[ORM\Column]` attributes across the full class hierarchy, resolving column name from explicit `name:`, positional arg, or camelCase-to-snake_case property name
- Optional phpstan-doctrine path: `ObjectMetadataResolver` when `class_exists` guard passes and resolver is non-null; falls through to reflection on null metadata
- 5 RuleTestCase tests all pass: missing tenant_id, nullable tenant_id, integer type, valid string (clean), inherited `#[TenantAware]` on parent
- PHPStan level 9 self-analysis: 0 errors; php-cs-fixer: clean

## Task Commits

1. **Task 1: Implement TenantIdDriftRule** — `ac60f51` (feat)
2. **Task 2: TenantIdDriftRuleTest + fixtures** — `6a32b46` (feat)

## Files Created/Modified

- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/src/PHPStan/Rule/TenantIdDriftRule.php` — Full Rule 3 implementation; reflection fallback primary + optional ObjectMetadataResolver path; `tenancy.tenantIdDrift` identifier
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/extension.neon` — Removed `helper:` argument from TenantIdDriftRule service (helper unused; hierarchy walk is inlined)
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/phpstan.neon` — Updated `property.onlyWritten` suppression to reference only `SharedEntityLeakRule` (TenantIdDriftRule fully implemented)
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php` — RuleTestCase; `getAdditionalConfigFiles` path `__DIR__.'/../../../../extension.neon'`; 5 tests
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/tests/Unit/PHPStan/Rule/Fixtures/TenantIdMissingViolating.php` — #[TenantAware] entity with id+name columns, NO tenant_id
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/tests/Unit/PHPStan/Rule/Fixtures/TenantIdNullableViolating.php` — tenant_id #[ORM\Column(name: 'tenant_id', nullable: true)]
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/tests/Unit/PHPStan/Rule/Fixtures/TenantIdNonStringViolating.php` — tenant_id #[ORM\Column(name: 'tenant_id', type: 'integer')]
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/tests/Unit/PHPStan/Rule/Fixtures/TenantIdValidClean.php` — non-nullable string #[ORM\Column(name: 'tenant_id', type: 'string')]
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/tests/Unit/PHPStan/Rule/Fixtures/TenantAwareParent.php` — #[TenantAware] MappedSuperclass, no tenant_id (hierarchy test parent)
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/tests/Unit/PHPStan/Rule/Fixtures/TenantIdMissingChild.php` — extends TenantAwareParent, no own #[TenantAware], no tenant_id (hierarchy test child)

## Decisions Made

1. **TenantIdDriftRule inlines the hierarchy walk, does not use AttributeHierarchyHelper**: Wave 1 found that `ClassReflection::getNativeReflection()` returns a BetterReflection adapter type incompatible with `ReflectionClass<object>` at PHPStan level 9. The hierarchy walk is inlined using `ClassReflection::getParentClass()` loop (identical to MutualExclusionRule). The `$helper` constructor param was removed (PHPStan reported `property.onlyWritten`).

2. **Doctrine ClassMetadata accessed via (array) cast**: In the optional phpstan-doctrine path, `$metadata->fieldMappings` is accessed via `(array) $metadata` to avoid a hard import of `ClassMetadata`. This is the only way to access a public property of an `object`-typed parameter without introducing a hard dependency.

3. **camelCase-to-snake_case property name fallback**: For `#[ORM\Column]` without an explicit `name:` argument, the column name is derived as `strtolower(preg_replace('/([A-Z])/', '_$1', lcfirst($propName)))`. This converts `$tenantId` -> `tenant_id`. Per Pitfall 6 in the research notes.

4. **All violating fixture classes report errors at line 10**: The `#[TenantAware]` attribute is placed at line 10 in all three violating fixtures (PHP 8 attributes appear before the `class` keyword; PHPStan reports the first attribute line as the node position). `TenantIdMissingChild` reports line 9 (`#[ORM\Entity]`).

5. **Accepted string types are string, ascii_string, guid, uuid**: No `Types::*` constant import needed — comparison is against the string values directly, case-insensitively via `strtolower`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] $helper constructor param removed from TenantIdDriftRule**
- **Found during:** Task 1 (PHPStan analysis after implementation)
- **Issue:** The plan specified `AttributeHierarchyHelper $helper` in the constructor and `hasAttributeInHierarchy($nativeReflection, TenantAware::class)` call. Wave 1 found that `getNativeReflection()` returns a BetterReflection adapter type. TenantIdDriftRule uses inline `ClassReflection::getParentClass()` loop instead, leaving `$helper` unread. PHPStan level 9 reported `property.onlyWritten`.
- **Fix:** Removed `$helper` constructor param and `use` import; updated `extension.neon` to drop the `helper:` argument; updated `phpstan.neon` to remove `TenantIdDriftRule` from the `property.onlyWritten` suppression pattern.
- **Files modified:** `src/PHPStan/Rule/TenantIdDriftRule.php`, `extension.neon`, `phpstan.neon`
- **Verification:** `vendor/bin/phpstan analyse` exits 0
- **Committed in:** `ac60f51` (Task 1)

**2. [Rule 1 - Bug] Spurious @phpstan-ignore-line comments caused unmatched ignore errors**
- **Found during:** Task 1 (PHPStan analysis)
- **Issue:** Initial implementation used `// @phpstan-ignore-line` on `$metadata->fieldMappings` and `$mapping['columnName']` accesses. PHPStan reported "No error to ignore is reported on line N" as a non-ignorable `ignore.unmatchedLine` error. The accesses did not trigger level-9 errors because `$metadata` is typed as `object` and the property/array accesses needed a different approach.
- **Fix:** Changed metadata access to `(array) $metadata` cast + `$raw['fieldMappings']` key read; removed all `@phpstan-ignore-line` comments.
- **Files modified:** `src/PHPStan/Rule/TenantIdDriftRule.php`
- **Verification:** `vendor/bin/phpstan analyse` exits 0 with no suppressed errors
- **Committed in:** `ac60f51` (Task 1)

---

**Total deviations:** 2 auto-fixed (both Rule 1 bugs)
**Impact on plan:** Both fixes necessary for PHPStan level 9 compliance. No scope creep. The AttributeHierarchyHelper is retained for Plan 03 which may find a compatible usage pattern.

## Plan Output Notes

Per plan `<output>` section:
- **Actual fixture line numbers:** Violating fixtures report at line 10 (first `#[TenantAware]` attribute); `TenantIdMissingChild` at line 9 (`#[ORM\Entity]` on child).
- **Exact string types accepted:** `string`, `ascii_string`, `guid`, `uuid` — compared case-insensitively via `strtolower()`.
- **camelCase->snake_case fallback needed:** Yes, `preg_replace('/([A-Z])/', '_$1', lcfirst($propName))` handles `$tenantId` -> `tenant_id` for the Wave 1 existing `TestTenantProduct` entity pattern.
- **No length assertion:** Confirmed — no `strlen`, `VARCHAR`, or length-checking code anywhere in `TenantIdDriftRule.php` beyond a documentation mention in error message text.

## Issues Encountered

- PHPStan level 9 `property.onlyWritten` error for the unused `$helper` — resolved by inlining the hierarchy walk (same pattern as MutualExclusionRule from Wave 1).
- `@phpstan-ignore-line` produced unmatched-ignore errors — resolved by restructuring the metadata access pattern.

## Known Stubs

None — `TenantIdDriftRule::processNode()` is fully implemented.

The only remaining skeleton is `SharedEntityLeakRule::processNode()` (Plan 03).

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- **Plan 03 (SharedEntityLeakRule)**: Same foundation ready. `extension.neon` wired, skeleton `SharedEntityLeakRule` in place.
- **Rule 3 is now fully active**: Any consumer project running `phpstan analyse` with the extension will detect `#[TenantAware]` entities missing/nullable/non-string `tenant_id`.

---
*Phase: 28-phpstan-extension*
*Completed: 2026-06-16*
