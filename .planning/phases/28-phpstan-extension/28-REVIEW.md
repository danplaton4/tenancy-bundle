---
phase: 28-phpstan-extension
reviewed: 2026-06-17T00:00:00Z
depth: standard
files_reviewed: 4
files_reviewed_list:
  - src/PHPStan/Rule/TenantIdDriftRule.php
  - tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php
  - tests/Unit/PHPStan/Rule/Fixtures/TenantIdPositionalNonStringViolating.php
  - tests/Unit/PHPStan/Rule/Fixtures/TenantIdPositionalNullableViolating.php
findings:
  critical: 0
  warning: 0
  info: 2
  total: 2
status: issues_found
---

# Phase 28 Gap-Closure 28-07: Code Review Report

**Reviewed:** 2026-06-17
**Depth:** standard (gap-closure plan 28-07 delta)
**Files Reviewed:** 4
**Status:** issues_found (2 Info only — no Blockers, no Warnings)

## Summary

Adversarial review of gap-closure plan 28-07: **WR-04** (two new positional `#[ORM\Column]` test
fixtures + two RuleTestCase methods exercising the `$args[1]` type / `$args[6]` nullable positional
fallbacks in `checkViaReflection()`), **W1** (ArrayAccess offset reads → public property access inside
`checkViaMetadata()`'s `instanceof \ArrayAccess` branch), and **W2** (corrected comment at line 152).

This plan is the remediation of the three open warnings from the *prior* 28-REVIEW
(WR-01 deprecated ArrayAccess read, WR-02 misleading comment, WR-03 missing WR-04 coverage). I
verified each is now genuinely closed — see "Prior warnings — closure verification" below. I did not
take the implementation's claims on trust; I verified the load-bearing facts against the vendored
Doctrine source and ran the gates.

**Verified against vendored source and runtime:**
- **Positional indices are correct.** `vendor/doctrine/orm/src/Mapping/Column.php` (ORM 3.6.3) declares
  the promoted constructor in order `name(0), type(1), length(2), precision(3), scale(4), unique(5),
  nullable(6)`. The rule's `$args[1]`=type and `$args[6]`=nullable map exactly.
- **`getArguments()` key shape is correct.** Empirically confirmed: positional args yield integer-keyed
  arrays (`["tenant_id","string",63,null,null,false,true]`); named args yield string keys
  (`{"name":"tenant_id","nullable":true}`). `$args['type'] ?? $args[1]` and `$args['nullable'] ?? $args[6]`
  resolve named-first (preserving existing behavior) then positional (closing the gap). The
  positional-nullable fixture isolates `$args[6]` cleanly — type is valid `'string'`, so ONLY the
  nullable error fires; the positional-non-string fixture has no index-6 arg so `?? false` correctly
  yields non-nullable.
- **W1 property access is real and behavior-preserving.** `FieldMapping` (ORM 3.6.3) declares
  `public string $type`, `public string $columnName` (constructor-promoted, lines 85-87) and
  `public bool|null $nullable` (line 24) as genuine public properties. `$fm->columnName/->type/->nullable`
  read those directly — they do NOT route through `ArrayAccess::offsetGet()`, so no `E_USER_DEPRECATED`
  fires and the path is ORM-4.0-safe. The ORM 2.x `elseif (is_array($fm))` branch is unchanged.
- **W2 comment is accurate.** `vendor/doctrine/orm/src/Mapping/ArrayAccessImplementation.php` shows
  `offsetGet()` calls `Deprecation::trigger(... 'Using ArrayAccess on %s is deprecated and will not be
  possible in Doctrine ORM 4.0. Use the corresponding property instead.')`. The W1 change is exactly the
  remediation Doctrine prescribes; the W2 comment describes this correctly.

**Gates (run in main checkout, not the vendor-less worktree):**
- `vendor/bin/phpunit --filter TenantIdDriftRuleTest` → **OK (11 tests, 13 assertions)**, no skipped/risky.
- The two W1 regression guards (`testMetadataPathSilentOnValidEntity`, `testMetadataPathFiresOnMissingTenantId`)
  execute (not skipped) and assert non-null metadata FIRST, proving `checkViaMetadata()` is actually
  entered — so the changed property-access path is genuinely covered.
- `vendor/bin/phpstan analyse src/PHPStan/Rule/TenantIdDriftRule.php` → **[OK] No errors** (level 9).
- `vendor/bin/php-cs-fixer check` on all 4 files → **no violations**.

The security-relevant failure mode for this rule is a false NEGATIVE (a nullable or non-string `tenant_id`
slipping through). I specifically probed for it: the WR-04 positional fallbacks *close* a real
false-negative gap rather than open one, and the two new fixtures are correctly isolated so each asserts
exactly one error in the security-direction. No Blocker, no Warning. The two Info items are minor
maintainability notes only.

## Prior warnings — closure verification

- **WR-01 (deprecated ArrayAccess read → E_USER_DEPRECATED + ORM-4.0 regression):** CLOSED by W1.
  Lines 157-163 now read `$fm->columnName / ->type / ->nullable` (verified public properties on
  `FieldMapping`). No `offsetGet()` invocation remains in the object branch.
- **WR-02 (misleading "plain arrays satisfy \ArrayAccess" comment):** CLOSED by W2. Lines 151-154 now
  correctly state ORM 3.x FieldMapping objects implement `\ArrayAccess` (caught by the `instanceof`
  branch) while ORM 2.x plain arrays do NOT and reach the `is_array()` branch.
- **WR-03 (WR-04 positional fallbacks had no test coverage):** CLOSED. Both prescribed fixtures
  (`TenantIdPositionalNonStringViolating`, `TenantIdPositionalNullableViolating`) and both test methods
  (`testFiresWhenTenantIdPositionalNonString`, `testFiresWhenTenantIdPositionalNullable`) exist, run, and
  pass. An anti-revert guard for lines 224-225 is now in place.

## Narrative Findings (AI reviewer)

## Info

### IN-01: Redundant `is_string()` guard on a statically-`string` property in the metadata path

**File:** `src/PHPStan/Rule/TenantIdDriftRule.php:160-163`

**Issue:** Inside the `instanceof \ArrayAccess` branch, the inner `@var` (line 156) narrows
`type: string` and the actual `FieldMapping::$type` is `public string $type` (non-nullable). Line 163
still does `is_string($typeRaw) ? $typeRaw : null`, whose `false` arm is statically unreachable for ORM
3.x FieldMapping objects. This is harmless and arguably justified as defense-in-depth at an
optional-dependency boundary you cannot fully control, but under the current declared type it is dead.

**Fix:** Optional. Either keep as deliberate future-proofing (recommended for the optional-dep posture)
and note it in a one-line comment, or simplify to `'type' => $typeRaw,`. No behavior change either way.
Leaning keep-as-is.

### IN-02: Positional-fallback coverage asserted via reflection path only, not the metadata path

**File:** `tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php:184-218`

**Issue:** The two new WR-04 tests exercise the positional `$args[1]`/`$args[6]` fallbacks through
`checkViaReflection()` only — which matches WR-04's stated scope (lines 224-225 of the reflection path).
There is no untested code path: the metadata path reads `$fm->type`/`$fm->nullable` and has its own
regression tests. The observation is only that "consumer declares column positionally" + "metadata path
active" is a combination no single test covers end-to-end. Because `ObjectMetadataResolver` normalizes
positional and named `#[ORM\Column]` args into the same `FieldMapping` shape before the rule sees them,
the positional-vs-named distinction is erased at the metadata boundary, making this combination
low-value.

**Fix:** None required. Optional future hardening: add one metadata-path test analysing
`TenantIdPositionalNullableViolating` with the injected resolver to assert the positional declaration
still surfaces as `nullable=true` post-normalization. Low priority.

---

_Reviewed: 2026-06-17_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard (gap-closure plan 28-07 delta)_
