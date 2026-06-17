---
phase: 28-phpstan-extension
plan: 07
subsystem: phpstan
tags: [phpstan, phpstan-rules, static-analysis, doctrine, orm, fieldmapping, test-coverage, wr-04, w1, w2]

# Dependency graph
requires:
  - phase: 28-phpstan-extension
    plan: 06
    provides: "ObjectMetadataResolver injection via extension-doctrine.neon (WR-01); no-doctrine CI lane with WR-05 survival guard"

provides:
  - "WR-04: two positional #[ORM\\Column] RuleTestCase fixtures + two test methods — $args[1] (type) and $args[6] (nullable) positional fallbacks now exercised; a revert of lines 224-225 breaks CI"
  - "W1: checkViaMetadata() instanceof \\ArrayAccess branch uses property access ($fm->columnName/$fm->nullable/$fm->type) after @var ArrayAccess<array-key,mixed>&object{columnName:string,nullable:bool|null,type:string} inner narrowing — zero E_USER_DEPRECATED notices, ORM-4.0-safe"
  - "W2: corrected line-152 comment — ORM 2.x plain arrays do NOT implement \\ArrayAccess; they fall to the is_array() branch"

affects:
  - "Phase 28 completion — WR-04 was the sole gaps_found BLOCKER; all 9 must-haves from 28-VERIFICATION.md now satisfied"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Positional-arg fixtures for PHPStan RuleTestCase: mirror named-arg analogs exactly (same header layout so TenantAware lands on line 10)"
    - "Inner @var intersection narrowing: \\ArrayAccess<array-key,mixed>&object{columnName:string,nullable:bool|null,type:string} — required because plain object{...} is not a subtype of \\ArrayAccess at PHPStan level 9 (varTag.nativeType); the intersection is the correct form"
    - "W1 property-access read: $fm->columnName/$fm->nullable/$fm->type inside instanceof \\ArrayAccess branch after inner narrowing — ORM-4.0-safe, no deprecation notices"

key-files:
  created:
    - tests/Unit/PHPStan/Rule/Fixtures/TenantIdPositionalNonStringViolating.php
    - tests/Unit/PHPStan/Rule/Fixtures/TenantIdPositionalNullableViolating.php
  modified:
    - tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php
    - src/PHPStan/Rule/TenantIdDriftRule.php

key-decisions:
  - "Inner @var intersection type: plain object{...} narrowing inside instanceof \\ArrayAccess branch was rejected by PHPStan (varTag.nativeType — not a subtype of \\ArrayAccess); \\ArrayAccess<array-key,mixed>&object{...} is the valid form at level 9"
  - "W1 nullable property is declared bool|null on FieldMapping; (bool)($fm->nullable ?? false) normalizes null to false, matching prior offset semantics — keep the ?? false"
  - "Both new fixture files mirror the named-arg analog header layout exactly: #[TenantAware] on line 10 — confirmed empirically; expected line 10 matched PHPUnit output without adjustment"

patterns-established:
  - "When narrowing $fm to read properties inside instanceof \\ArrayAccess branch, use \\ArrayAccess<TKey,TValue>&object{prop:type} intersection so the @var is a valid subtype at PHPStan level 9"

requirements-completed: [DX-03-AC3]

# Metrics
duration: 6min
completed: 2026-06-17
---

# Phase 28 Plan 07: Gap-Closure (WR-04/W1/W2) Summary

**Two positional #[ORM\\Column] fixtures + tests closing the WR-04 anti-revert guard, plus ORM-4.0-safe property-access FieldMapping read (W1) and corrected comment (W2)**

## Performance

- **Duration:** ~6 min
- **Started:** 2026-06-17T12:56:23Z
- **Completed:** 2026-06-17T13:02:13Z
- **Tasks:** 2
- **Files modified:** 4 (2 created)

## Accomplishments

- WR-04 (BLOCKER) closed: two positional fixtures and two RuleTestCase test methods prove the `$args[1]` (type) and `$args[6]` (nullable) positional fallbacks in `checkViaReflection()` lines 224-225 are exercised — a silent revert of those lines now breaks CI
- W1 fixed: `checkViaMetadata()` `instanceof \ArrayAccess` branch now uses property access (`$fm->columnName` / `$fm->nullable` / `$fm->type`) after an inner `@var \ArrayAccess<array-key, mixed>&object{columnName: string, nullable: bool|null, type: string}` narrowing — zero E_USER_DEPRECATED notices on ORM 3.x, ORM-4.0-safe
- W2 fixed: corrected line-152 comment — ORM 2.x plain arrays do NOT implement `\ArrayAccess` and are NOT matched by the instanceof check; they fall to the separate `is_array()` branch
- Suite is 11/11; full suite green (763 tests, 2 skipped = normal no-doctrine skips); bundle L9 + both dogfoods + cs-fixer all green

## Task Commits

1. **Task 1: WR-04 — positional #[ORM\\Column] fixtures + tests (9 → 11)** — `1ccc6dc` (test)
2. **Task 2: W1 + W2 — property-access FieldMapping read + corrected comment** — `32b56d6` (fix)

## Files Created/Modified

- `tests/Unit/PHPStan/Rule/Fixtures/TenantIdPositionalNonStringViolating.php` — new: `#[ORM\Column('tenant_id', 'integer')]` positional type at index 1; exercises `$args[1]` (WR-04 type direction)
- `tests/Unit/PHPStan/Rule/Fixtures/TenantIdPositionalNullableViolating.php` — new: `#[ORM\Column('tenant_id', 'string', 63, null, null, false, true)]` positional nullable=true at index 6; exercises `$args[6]` (WR-04 nullable direction)
- `tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php` — +2 `use` imports (alphabetical); +2 test methods (`testFiresWhenTenantIdPositionalNonString`, `testFiresWhenTenantIdPositionalNullable`); total 11 tests
- `src/PHPStan/Rule/TenantIdDriftRule.php` — W1 property-access inner narrowing + reads in `checkViaMetadata()`; W2 corrected line-152 comment; lines 224-225 (WR-04 positional fallbacks) untouched

## Actual Error Lines Confirmed Empirically

Both new fixtures mirror the named-arg analog layout exactly:
- No docblock above attributes → `#[TenantAware]` lands on **line 10**
- PHPUnit output confirmed: both `testFiresWhenTenantIdPositionalNonString` and `testFiresWhenTenantIdPositionalNullable` asserted line **10** and passed immediately — no adjustment required

## W1 Property-Access Shape Committed

```php
if ($fm instanceof \ArrayAccess) {
    /** @var \ArrayAccess<array-key, mixed>&object{columnName: string, nullable: bool|null, type: string} $fm */
    $colName = $fm->columnName;
    if ('tenant_id' === $colName) {
        $nullableRaw = $fm->nullable;
        $typeRaw = $fm->type;
        $found = [
            'nullable' => (bool) ($nullableRaw ?? false),
            'type' => is_string($typeRaw) ? $typeRaw : null,
        ];
        break;
    }
}
```

Key deviation from plan's prescribed form: `object{...}` alone was rejected by PHPStan (`varTag.nativeType` — not a subtype of `\ArrayAccess`). The valid level-9 form is `\ArrayAccess<array-key, mixed>&object{columnName: string, nullable: bool|null, type: string}` (intersection). Zero `@phpstan-ignore` introduced. (Rule 1 auto-fix: PHPStan error during Task 2, fixed immediately.)

## W2 Comment Corrected

Before: `// ORM 2.x: plain array entries also satisfy \ArrayAccess check via the is_array() branch below.`

After:
```
// ORM 3.x: FieldMapping objects implement \ArrayAccess but public property access is the
// ORM-4.0-safe read path (ArrayAccess::offsetGet() fires E_USER_DEPRECATED on ORM 3.x).
// ORM 2.x: plain array entries do NOT implement \ArrayAccess — they fall to the is_array()
// branch below and are NOT matched by this instanceof check.
```

## Untouched Items Confirmed

- `checkViaReflection()` lines 224-225 (`$args['nullable'] ?? $args[6] ?? false` and `$args['type'] ?? $args[1] ?? null`): untouched
- ORM 2.x `elseif (is_array($fm))` plain-array branch: untouched (confirmed `grep -c 'elseif (is_array' src/PHPStan/Rule/TenantIdDriftRule.php` = 1)
- Existing 9 tests: unchanged; only two new test methods and two `use` imports added
- Zero `@phpstan-ignore` in `TenantIdDriftRule.php` (confirmed: count = 0)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] PHPStan level-9 varTag.nativeType error on inner @var narrowing**
- **Found during:** Task 2
- **Issue:** The plan's prescribed `/** @var object{columnName: string, nullable: bool|null, type: string} $fm */` form was rejected by PHPStan 2.1.x with `varTag.nativeType` — an `object{...}` shape is not a subtype of `\ArrayAccess`, so the narrowing inside the `instanceof \ArrayAccess` branch is invalid
- **Fix:** Changed to the intersection form `\ArrayAccess<array-key, mixed>&object{columnName: string, nullable: bool|null, type: string}`, which IS a valid subtype of `\ArrayAccess` and also requires specifying the generic type parameters (otherwise `missingType.generics` fires). Two-step fix: first tried `\ArrayAccess&object{...}` (missing generics) → then `\ArrayAccess<array-key, mixed>&object{...}` (clean)
- **Files modified:** `src/PHPStan/Rule/TenantIdDriftRule.php`
- **Commit:** `32b56d6`

## Threat Surface Scan

No new network endpoints, auth paths, file access patterns, or schema changes introduced. Both tasks are test/analysis-time only (RuleTestCase fixtures + a read-path refactor inside a PHPStan rule). No new threat surface.

## Self-Check

### Files exist:
- `tests/Unit/PHPStan/Rule/Fixtures/TenantIdPositionalNonStringViolating.php` — FOUND
- `tests/Unit/PHPStan/Rule/Fixtures/TenantIdPositionalNullableViolating.php` — FOUND
- `tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php` — FOUND (modified)
- `src/PHPStan/Rule/TenantIdDriftRule.php` — FOUND (modified)

### Commits exist:
- `1ccc6dc` — FOUND (test(28-07): WR-04 — positional fixtures + tests)
- `32b56d6` — FOUND (fix(28-07): W1/W2 — property-access FieldMapping read + corrected comment)

## Self-Check: PASSED

---
*Phase: 28-phpstan-extension*
*Completed: 2026-06-17*
