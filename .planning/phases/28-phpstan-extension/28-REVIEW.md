---
phase: 28-phpstan-extension
reviewed: 2026-06-17T00:00:00Z
depth: standard
files_reviewed: 7
files_reviewed_list:
  - src/PHPStan/Rule/TenantIdDriftRule.php
  - tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php
  - tests/Unit/PHPStan/Rule/Fixtures/TenantAwareConcreteChild.php
  - extension-doctrine.neon
  - phpstan-extension-dogfood.neon
  - phpstan-extension-dogfood-nodoctrine.neon
  - .github/workflows/ci.yml
findings:
  critical: 0
  warning: 3
  info: 1
  total: 4
status: issues_found
---

# Phase 28 Gap-Closure: Code Review Report

**Reviewed:** 2026-06-17
**Depth:** standard (gap-closure diff 4db61d0..HEAD)
**Files Reviewed:** 7
**Status:** issues_found

## Summary

This review covers only the gap-closure work from plans 28-05 and 28-06: the ORM-3.x
`checkViaMetadata()` fix (CR-01), the MappedSuperclass/abstract exemption (WR-02), the
positional-arg fallbacks (WR-04), the `ObjectMetadataResolver` wiring via standalone
`extension-doctrine.neon` (WR-01), and the no-doctrine CI lane (WR-05).

**What is correct:**
- CR-01 is genuinely fixed: `is_array()` guard replaced with `instanceof \ArrayAccess` + `is_array()` two-branch dispatch; plain PHP arrays do NOT implement ArrayAccess (confirmed), so ORM 2.x entries correctly fall to the `elseif (is_array($fm))` branch. The logic is sound for ORM 3.x FieldMapping objects.
- WR-02 MappedSuperclass/abstract skip guard is correctly placed in `processNode()` after `hasTenantAwareInHierarchy()` and before the path branch, so it applies to both the metadata and reflection paths. The `::class` reference to `\Doctrine\ORM\Mapping\MappedSuperclass` is PHP compile-time string resolution — no `class_exists` guard is needed and none is missing.
- WR-01 wiring via standalone `extension-doctrine.neon` is architecturally sound: the fragment owns the full rule set (no `includes:`), PHPStan's Nette DI resolves `@PHPStan\Type\Doctrine\ObjectMetadataResolver` only when phpstan-doctrine is installed, and the dogfood now exercises the wired metadata path end-to-end.
- WR-05 CI lane correctly removes `phpstan/phpstan-doctrine` (not just `doctrine/orm`), adds the `phpstan --version` survival guard, runs `tests/Unit/PHPStan` (with metadata tests self-skipping via `class_exists`), and runs the base dogfood to prove graceful degradation.
- The per-test injectable resolver pattern (`private ?object $resolver = null` reset to null per PHPUnit instance) is correct — PHPUnit creates a new test class instance per method, so resolver state cannot leak between tests.
- The `assertNotNull(getClassMetadata(...))` entry proofs in the metadata tests correctly close the Warning-3 silent-fallthrough trap.

**What is deficient (see findings):**
Three warnings were found. Two are code quality issues in `TenantIdDriftRule.php` that survive from the gap-closure commit. One is a missing test coverage gap for WR-04. No new critical issues were introduced.

## Narrative Findings (AI reviewer)

## Warnings

### WR-01: `checkViaMetadata()` uses deprecated `FieldMapping::ArrayAccess` — fires `E_USER_DEPRECATED` per field read and will break on Doctrine ORM 4.0

**File:** `src/PHPStan/Rule/TenantIdDriftRule.php:153-174`

**Issue:** The CR-01 fix correctly replaces `is_array()` with `instanceof \ArrayAccess`, but
then accesses the field data via the ArrayAccess offsetGet interface (`$fm['columnName']`,
`$fm['nullable']`, `$fm['type']`). The `FieldMapping::ArrayAccessImplementation::offsetGet()`
method fires `Deprecation::trigger()` on every invocation, emitting `E_USER_DEPRECATED` with
the message *"Using ArrayAccess on FieldMapping is deprecated and will not be possible in
Doctrine ORM 4.0. Use the corresponding property instead."* (confirmed from
`vendor/doctrine/orm/src/Mapping/ArrayAccessImplementation.php:31-36`).

Two concrete consequences:
1. In ORM 3.x today, every call to `checkViaMetadata()` emits deprecation notices —
   currently suppressed in CI only because `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0`
   limits the fail-threshold to direct (non-vendor) deprecations. When phpunit-bridge's
   vendor-deprecation counting is tightened (or under a different consumer's PHPUnit config),
   these notices will surface as test failures.
2. In Doctrine ORM 4.0, `FieldMapping` will stop implementing `ArrayAccess` entirely. At
   that point `$fm instanceof \ArrayAccess` returns `false` for every entry, the metadata
   path falls through to `$found = null`, and `evaluateFinding(null, ...)` reports
   *"no tenant_id column"* on every valid `#[TenantAware]` entity — the same symptom as the
   original CR-01 bug. The fix is ORM-3.x-functional but introduces an ORM-4.0 regression.

The correct and deprecation-free access pattern — direct property read — was the recommended
alternative in the verification report and is level-9 clean because `FieldMapping` has typed
public properties:

**Fix:**
```php
foreach ($meta->fieldMappings as $fm) {
    if ($fm instanceof \ArrayAccess) {
        // ORM 3.x: FieldMapping — read public properties directly (deprecation-free, ORM-4.0 safe).
        // property_exists() guards are not needed: columnName/nullable/type are declared on FieldMapping.
        /** @var object{columnName: string, nullable: bool|null, type: string} $fm */
        $colName = $fm->columnName ?? null;
        if ('tenant_id' === $colName) {
            $found = [
                'nullable' => (bool) ($fm->nullable ?? false),
                'type' => is_string($fm->type) ? $fm->type : null,
            ];
            break;
        }
    } elseif (is_array($fm)) {
        // ORM 2.x fallback: plain array entry (unchanged)
        $colName = $fm['columnName'] ?? $fm['column'] ?? null;
        if ('tenant_id' === $colName) {
            $found = [
                'nullable' => (bool) ($fm['nullable'] ?? false),
                'type' => isset($fm['type']) && is_string($fm['type']) ? $fm['type'] : null,
            ];
            break;
        }
    }
}
```
If PHPStan level 9 does not accept `->columnName` on the object-shape-narrowed `$fm`
(the `@var object{fieldMappings: iterable<object>}` annotates `$fm` as `object`, not the
concrete `FieldMapping` class), add a more specific `@var` annotation:
`/** @var object{columnName: string, nullable: bool|null, type: string} $fm */`
inside the `instanceof \ArrayAccess` branch. No `@phpstan-ignore` is needed.

### WR-02: Misleading comment on ORM 2.x `ArrayAccess` branch in `checkViaMetadata()`

**File:** `src/PHPStan/Rule/TenantIdDriftRule.php:152`

**Issue:** The inline comment reads:
> "ORM 2.x: plain array entries also satisfy \ArrayAccess check via the is_array() branch below."

This is factually incorrect in its first clause. Plain PHP arrays do NOT implement
`\ArrayAccess` — `$arr instanceof ArrayAccess` is `false` for any PHP native array (confirmed
empirically). The sentence intends to say ORM 2.x arrays are handled by the `is_array()`
branch, but states instead that they "satisfy \ArrayAccess check," which is the opposite of
what happens. A future maintainer reading this could conclude plain arrays fall through the
`instanceof \ArrayAccess` branch (they do not) and misunderstand the invariant.

**Fix:** Replace the comment with an accurate description:
```php
// ORM 3.x: FieldMapping objects implement \ArrayAccess — caught by the instanceof branch above.
// ORM 2.x: plain array entries do NOT implement \ArrayAccess — they reach this is_array() branch.
```

### WR-03: WR-04 (positional `#[ORM\Column]` args) has no test coverage despite the plan promising fixtures

**File:** `tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php` (missing tests),
`src/PHPStan/Rule/TenantIdDriftRule.php:224-225`

**Issue:** Plan 28-05 and the 28-05 SUMMARY both list as a `provides:` deliverable:
*"TenantIdDriftRule::checkViaReflection() — positional fallbacks for nullable ($args[6]) and
type ($args[1]) in addition to named args (WR-04)"* and describe adding
*"reflection-path tests for #[ORM\Column('tenant_id', 'integer')] (positional non-string type
fires) and a positional nullable=true at index 6 (positional nullable fires)"*.

The code changes in `checkViaReflection()` at lines 224-225 are present:
```php
$nullableRaw = $args['nullable'] ?? $args[6] ?? false;
$typeRaw     = $args['type']     ?? $args[1] ?? null;
```
But no fixture files for positional `#[ORM\Column]` declarations were created (the fixture
directory contains no new `TenantIdPositionalType*.php` or `TenantIdPositionalNullable*.php`
files), and no test methods exercise the `$args[1]` or `$args[6]` fallback paths. The fix was
delivered at the code level but not the test level.

The consequence is direct: a regression that reverts lines 224-225 back to
`$args['nullable'] ?? false` / `$args['type']` (the pre-fix form) would be invisible to CI.
The WR-04 fix is the security-relevant false-negative direction — a positionally-declared
nullable or non-string `tenant_id` would silently pass — which makes the missing coverage
more concerning than a typical code quality gap.

**Fix:** Add at minimum two fixtures and two test methods:
```php
// Fixture: tests/Unit/PHPStan/Rule/Fixtures/TenantIdPositionalNonStringViolating.php
#[TenantAware]
#[ORM\Entity]
class TenantIdPositionalNonStringViolating
{
    // type at positional index 1 — must fire tenancy.tenantIdDrift (non-string type)
    #[ORM\Column('tenant_id', 'integer')]
    private int $tenantId;
}

// Fixture: tests/Unit/PHPStan/Rule/Fixtures/TenantIdPositionalNullableViolating.php
#[TenantAware]
#[ORM\Entity]
class TenantIdPositionalNullableViolating
{
    // nullable at positional index 6 — must fire tenancy.tenantIdDrift (nullable)
    #[ORM\Column('tenant_id', 'string', 63, null, null, false, true)]
    private ?string $tenantId;
}
```
And corresponding test methods in `TenantIdDriftRuleTest` asserting each fires. These close
the CI blind spot on the security-direction false-negative path.

## Info

### IN-01: `extension-doctrine.neon` and `extension.neon` can be accidentally co-loaded via extension-installer without any runtime guard

**File:** `extension-doctrine.neon:1-28` (header comments), `extension.neon`

**Issue:** `extension-doctrine.neon` correctly documents that it MUST NOT be co-loaded with
the base `extension.neon` (PHPStan's Nette DI does not dedupe `phpstan.rules.rule` services
by class — co-loading doubles every error). The no-co-load constraint is enforced only by
documentation. A consumer who has `phpstan/extension-installer` registered (which auto-loads
`extension.neon` via `extra.phpstan.includes`) and then manually adds `extension-doctrine.neon`
to their `phpstan.neon` will silently get doubled rule firings. There is no runtime check,
version constraint, or parametersSchema collision that would surface this error loudly.

The schema IS duplicated verbatim in both files (the `parametersSchema: tenancy: structure()`
block), but PHPStan merges schema definitions rather than erroring on duplicate keys, so even
that won't alert the consumer.

This remains acceptable as a known design limitation pending the Phase 29 DOC-20 consumer
guidance, but the `extension-doctrine.neon` header should mention the extension-installer
co-load risk explicitly (not just the dogfood double-registration scenario) so the consuming
developer has the full picture without reading the SUMMARY.

**Fix:** Add one line to the `extension-doctrine.neon` LOADING CONSTRAINT comment:
```
# If phpstan/extension-installer is installed (auto-loads extension.neon), you MUST remove
# the extension-installer auto-load entry (or pin allow-plugins: false for this package) to
# avoid co-loading both files. Loading both doubles every rule error.
```

---

_Reviewed: 2026-06-17_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard (gap-closure diff 4db61d0..HEAD)_
