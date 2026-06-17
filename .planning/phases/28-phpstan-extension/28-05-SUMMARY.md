---
phase: 28-phpstan-extension
plan: 05
subsystem: testing
tags: [phpstan, phpstan-rules, static-analysis, doctrine, orm, fieldmapping, arrayaccess, metadata-path]

# Dependency graph
requires:
  - phase: 28-phpstan-extension
    plan: 02
    provides: "TenantIdDriftRule (Rule 3) with reflection fallback + checkViaMetadata() skeleton; TenantIdDriftRuleTest with 5 reflection-path tests"

provides:
  - "TenantIdDriftRule::checkViaMetadata() — ORM-3.x-correct via property_exists guard + object-shape @var narrowing + instanceof \ArrayAccess offset accessor; ORM 2.x plain-array fallback"
  - "TenantIdDriftRule::checkViaReflection() — positional fallbacks for nullable ($args[6]) and type ($args[1]) in addition to named args (WR-04)"
  - "TenantIdDriftRule::processNode() — MappedSuperclass/abstract skip guard (WR-02) before the metadata/reflection branch"
  - "TenantAwareConcreteChild fixture — concrete #[ORM\Entity] subclass of TenantAwareParent with no tenant_id; proves parent-silent/child-fires"
  - "TenantIdDriftRuleTest — 9 tests: 4 original reflection tests + 3 WR-02 hierarchy tests + 2 metadata-path tests with non-null getClassMetadata() entry proofs"

affects:
  - "28-06 (extension.neon wiring for ObjectMetadataResolver — completes the D-02 path)"
  - "any consumer following phpstan/phpstan-doctrine suggestion (CR-01 no longer causes false-positive storm)"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "ORM-3.x FieldMapping read: property_exists guard + object-shape @var narrowing + instanceof \\ArrayAccess offset accessor ($fm['columnName']) — zero @phpstan-ignore, level-9 clean"
    - "MappedSuperclass/abstract skip: getNativeReflection()->isAbstract() || getAttributes(MappedSuperclass) in processNode() before path branch"
    - "Positional arg fallbacks: $args['nullable'] ?? $args[6] ?? false; $args['type'] ?? $args[1] ?? null (mirrors existing name resolution)"
    - "Per-test resolver injection: private ?object $resolver = null property in RuleTestCase; getRule() passes $this->resolver"
    - "Metadata-path entry proof: assertNotNull($resolver->getClassMetadata(Fixture::class)) before analyse() — prevents Warning 3 silent fallthrough to reflection"
    - "class_exists(ObjectMetadataResolver) skip-guard on metadata tests — no-doctrine CI lane self-skips"

key-files:
  created:
    - tests/Unit/PHPStan/Rule/Fixtures/TenantAwareConcreteChild.php
  modified:
    - src/PHPStan/Rule/TenantIdDriftRule.php
    - tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php

key-decisions:
  - "ORM-3.x FieldMapping read: chose ArrayAccess offset accessor ($fm['columnName']) as PRIMARY path — compiles clean against object-typed $fm (PHPStan level 9) and covers ORM 2.x via the is_array() fallback branch; avoids property access on untyped object"
  - "object-shape @var narrowing chosen over @phpstan-ignore — object{fieldMappings: iterable<object>} accepted by PHPStan 2.1.x for ->fieldMappings read; zero suppressions"
  - "property_exists($metadata, 'fieldMappings') guard degrades to [] (silent) rather than 'missing tenant_id' — an unreadable metadata shape must NEVER emit a false positive"
  - "MappedSuperclass skip guard placed in processNode() BEFORE the metadata/reflection branch so it applies equally to both paths — a MappedSuperclass parent is silent regardless of resolver wiring"
  - "TenantIdMissingChild reused as the hierarchy-fires fixture; TenantAwareConcreteChild created as the dedicated WR-02 'concrete child fires' fixture to make the intent explicit"
  - "Non-null getClassMetadata() assertions added BEFORE analyse() in both metadata tests — prevents the Warning 3 trap (test passing silently via reflection fallthrough if metadata is null)"

patterns-established:
  - "Metadata-path entry proof pattern: assertNotNull($resolver->getClassMetadata($fixture)) + $this->resolver = $resolver, then analyse() — guarantees checkViaMetadata() ran"
  - "Per-test injectable resolver: private ?object $resolver = null in RuleTestCase; allows reflection and metadata tests to coexist in one test class"

requirements-completed: [DX-03]

# Metrics
duration: 25min
completed: 2026-06-17
---

# Phase 28 Plan 05: Gap-Closure (CR-01/WR-02/WR-04) Summary

**ORM-3.x-correct TenantIdDriftRule: ArrayAccess metadata path, MappedSuperclass exemption, positional-arg reads, and resolver-injected CI coverage that proves the metadata path is actually entered**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-06-17T00:00:00Z
- **Completed:** 2026-06-17T00:25:00Z
- **Tasks:** 3
- **Files modified:** 3 (1 created)

## Accomplishments

- CR-01 fixed: `checkViaMetadata()` now reads ORM 3.x `FieldMapping` objects via `instanceof \ArrayAccess` offset accessor after `object{fieldMappings: iterable<object>}` @var narrowing — zero false positives on valid entities, zero `@phpstan-ignore` comments
- WR-02 fixed: non-instantiable MappedSuperclass/abstract bases carrying `#[TenantAware]` are now skipped (`processNode()` guard before the path branch) — concrete children still fire
- WR-04 fixed: `checkViaReflection()` now reads positional `nullable` (`$args[6]`) and `type` (`$args[1]`) fallbacks in addition to named args — the one false-negative direction in the security-relevant sense is closed
- IN-02 resolved: brittle `(array) $metadata` cast replaced by `property_exists` + `->fieldMappings` property access
- CR-01 CI coverage: two resolver-injected `RuleTestCase` tests prove the metadata path is actually entered (`assertNotNull(getClassMetadata())` before `analyse()`) — the path can no longer ship invisibly broken

## Task Commits

1. **Task 1: Fix CR-01 (ORM 3.x metadata path) + WR-04 (positional args)** - `9f1d90f` (fix)
2. **Task 2: WR-02 — skip MappedSuperclass/abstract bases, correct hierarchy test** - `92dcea2` (fix)
3. **Task 3: Metadata-path CI coverage via resolver-injected RuleTestCase tests** - `22ec1d9` (test)

## Files Created/Modified

- `src/PHPStan/Rule/TenantIdDriftRule.php` — CR-01 fix in `checkViaMetadata()` (ArrayAccess accessor + @var narrowing + property_exists guard); WR-04 fix in `checkViaReflection()` (positional fallbacks); WR-02 fix in `processNode()` (MappedSuperclass/abstract skip guard)
- `tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php` — per-test injectable resolver; 3 WR-02 hierarchy tests; 2 metadata-path tests with non-null entry proofs; corrected `testFiresOnInheritedTenantAware` renamed and fixed
- `tests/Unit/PHPStan/Rule/Fixtures/TenantAwareConcreteChild.php` — new: concrete `#[ORM\Entity]` subclass of `TenantAwareParent` with no `tenant_id` — the dedicated WR-02 "concrete child fires" fixture

## Decisions Made

- **ArrayAccess offset accessor as primary read path**: `$fm instanceof \ArrayAccess ? $fm['columnName'] : null` compiles level-9 clean against an `object`-typed `$fm` (offsetGet returns `mixed`), covers ORM 3.x `FieldMapping` objects, and is future-proof vs. property access on an untyped value.
- **object-shape @var narrowing** (`object{fieldMappings: iterable<object>}`): PHPStan 2.1.x accepts this for the `->fieldMappings` property read; no `@phpstan-ignore` needed — deviation from the prior failed attempt in Plan 28-02 which tried `@phpstan-ignore-line` at that exact spot.
- **property_exists guard degrades to `[]`** (not "missing tenant_id"): an unreadable metadata shape should never fire a false positive.
- **TenantAwareConcreteChild created** (not TenantIdMissingChild reused): both fixtures serve but TenantAwareConcreteChild explicitly documents the WR-02 intent.
- **Non-null getClassMetadata() assertion before analyse()**: the Warning 3 trap — metadata tests silently passing via reflection fallthrough — is blocked by asserting the resolver resolves the fixture to non-null metadata before the rule analysis runs.

## Deviations from Plan

None — plan executed exactly as written. Fixture line numbers were confirmed empirically (TenantAwareConcreteChild fires at line 16 not 19 due to the docblock; adjusted immediately).

## Issues Encountered

- TenantAwareConcreteChild initially asserted line 19 (the class body line); PHPUnit output showed the actual PHPStan error at line 16 (`#[ORM\Entity]` attribute line). Corrected in the same task before commit — no separate fix commit needed.

## Metadata-Access Shape (for output spec)

The committed level-9-clean narrowing:

```php
/** @var object{fieldMappings: iterable<object>} $meta */
$meta = $metadata;
foreach ($meta->fieldMappings as $fm) {
    if ($fm instanceof \ArrayAccess) {
        $colName = $fm['columnName'] ?? null;
        // ...
    } elseif (is_array($fm)) { // ORM 2.x fallback
        // ...
    }
}
```

No additional narrowing helper was needed. The `@var` object-shape annotation + `instanceof \ArrayAccess` path is sufficient for PHPStan level 9 with zero suppressions.

## Corrected Hierarchy Test — Actual Line Numbers

- `TenantIdMissingChild` fires at line **9** (`#[ORM\Entity]` is the first attribute)
- `TenantAwareConcreteChild` fires at line **16** (`#[ORM\Entity]` attribute after the 10-line docblock)
- Both confirmed empirically by running PHPUnit and reading the failure output.

## No @phpstan-ignore Comments Introduced

Confirmed: `grep -c '@phpstan-ignore' src/PHPStan/Rule/TenantIdDriftRule.php` returns **0**.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- Plan 28-05 closes CR-01/WR-02/WR-04: `checkViaMetadata()` is now correct, MappedSuperclass parents are silent, positional args are read.
- Plan 28-06 (next) wires the `ObjectMetadataResolver` into `extension.neon` (WR-01) so consumers who install `phpstan/phpstan-doctrine` get the metadata path automatically. The Task 3 metadata tests provide the CI gate that will catch any future regression.

---
*Phase: 28-phpstan-extension*
*Completed: 2026-06-17*
