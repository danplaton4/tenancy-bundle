---
phase: 28-phpstan-extension
reviewed: 2026-06-16T00:00:00Z
depth: standard
files_reviewed: 13
files_reviewed_list:
  - src/PHPStan/Rule/MutualExclusionRule.php
  - src/PHPStan/Rule/TenantIdDriftRule.php
  - src/PHPStan/Rule/SharedEntityLeakRule.php
  - extension.neon
  - phpstan-extension-dogfood.neon
  - composer.json
  - .github/workflows/ci.yml
  - phpunit.xml.dist
  - src/TenancyBundle.php
  - tests/Integration/SharedEntity/SharedEntitySyncIntegrationTest.php
  - tests/Unit/PHPStan/Rule/MutualExclusionRuleTest.php
  - tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php
  - tests/Unit/PHPStan/Rule/SharedEntityLeakRuleTest.php
findings:
  critical: 1
  warning: 5
  info: 3
  total: 9
status: issues_found
---

# Phase 28: Code Review Report

**Reviewed:** 2026-06-16
**Depth:** standard
**Files Reviewed:** 13
**Status:** issues_found

## Summary

Reviewed the three shipped PHPStan rules (`MutualExclusionRule`, `TenantIdDriftRule`,
`SharedEntityLeakRule`), their `extension.neon` / dogfood wiring, the `composer.json`
extension-installer integration, CI changes, and the unit/integration tests.

Verification performed live against the installed toolchain:
- `vendor/bin/phpstan analyse` (main `phpstan.neon`) — clean; tenancy rules do **not**
  auto-load on the bundle's own self-analysis (Pitfall 4 correctly avoided — the root
  package is not in extension-installer's `GeneratedConfig`).
- `vendor/bin/phpstan analyse -c phpstan-extension-dogfood.neon` — clean; rules load and
  run against `src/` without firing on the bundle's own legitimate attribute usage.
- `vendor/bin/phpunit tests/Unit/PHPStan` — 11/11 green.
- `php-cs-fixer check src/PHPStan` — clean.
- Confirmed the `SharedEntityLeakRule` fires end-to-end through real `extension.neon`
  wiring (parameter `%tenancy.checkSharedEntityLeaks%` resolves, gate works).

The good: the conservative `SharedEntityLeakRule` (D-03) and `MutualExclusionRule` are
sound and well-tested. The optional-Doctrine guards in the rules' `processNode` entry
points are correct.

The serious problem: `TenantIdDriftRule::checkViaMetadata()` — the advertised
phpstan-doctrine integration path — is **broken against Doctrine ORM 3.x** (the bundle's
own required version, `doctrine/orm: ^3.3`) and is simultaneously **never wired and never
tested**, so the breakage is invisible to CI. It is a latent false-positive generator the
moment a consumer follows the documented `phpstan/phpstan-doctrine` suggestion. Details
below.

The dominant residual risk class across all three rules is **false positives**
(over-firing) rather than false negatives, which is the right direction for a precision-
first security tool, but several of these will produce confusing noise for legitimate
consumer code (MappedSuperclass bases, non-underscore naming strategies).

## Narrative Findings (AI reviewer)

## Critical Issues

### CR-01: `checkViaMetadata()` is broken for Doctrine ORM 3.x — every valid `#[TenantAware]` entity becomes a false "missing tenant_id" positive

**File:** `src/PHPStan/Rule/TenantIdDriftRule.php:114-139`

**Issue:** The phpstan-doctrine metadata path assumes `ClassMetadata::$fieldMappings` is an
`array<string, array>` ("public array<string, mixed> in Doctrine ORM 2.x/3.x", per the
inline comment on line 117). That is false for Doctrine ORM 3.x. In ORM 3.x each entry is
a `Doctrine\ORM\Mapping\FieldMapping` **object** (`final class FieldMapping implements
ArrayAccess`), not an array.

Verified empirically:
```
is_array(FieldMapping): false
RULE PATH: continue triggered -> mapping skipped (BUG)
```

Concrete failure trace in `checkViaMetadata()`:
1. `$raw = (array) $metadata;` then `$fieldMappings = $raw['fieldMappings'] ?? []` —
   yields `array<string, FieldMapping>` (objects).
2. `foreach ($fieldMappings as $mapping) { if (!is_array($mapping)) { continue; } ... }`
   — every `$mapping` is a `FieldMapping` object, so `is_array()` is `false`, every
   iteration `continue`s, the `tenant_id` mapping is never inspected.
3. `$found` stays `null` → `evaluateFinding(null, ...)` reports
   *"Class X is #[TenantAware] but has no column mapped to tenant_id"* for **every**
   `#[TenantAware]` entity — including perfectly correct ones with a non-nullable
   `tenant_id` string column.

Severity rationale: this is the path that fires only when a consumer installs
`phpstan/phpstan-doctrine` — which the bundle actively advertises in `composer.json`
`suggest` (line 61: *"For full Doctrine metadata support — enables XML/YAML-mapped entity
analysis in Rule 3"*). The moment a consumer follows that suggestion AND the resolver is
wired (see WR-01), the rule emits a wall of false errors on correct code, which both
breaks their CI and trains them to ignore the rule — defeating its security purpose. It is
shipping, consumer-facing, and incorrect behavior. The breakage is masked because the
rule's own unit test deliberately constructs `new TenantIdDriftRule()` with no resolver
(`TenantIdDriftRuleTest.php:24-26`), so this code path has **zero test coverage**.

**Fix:** `FieldMapping` implements `ArrayAccess`, so treat entries as array-accessible
rather than requiring `is_array()`. Access via `ArrayAccess` works for both ORM 2.x arrays
and ORM 3.x objects:
```php
$found = null;
foreach ($fieldMappings as $mapping) {
    // ORM 2.x: array; ORM 3.x: FieldMapping (ArrayAccess). Accept both.
    if (!is_array($mapping) && !$mapping instanceof \ArrayAccess) {
        continue;
    }
    $colName = $mapping['columnName'] ?? $mapping['column'] ?? null;
    if ('tenant_id' === $colName) {
        $found = [
            'nullable' => (bool) ($mapping['nullable'] ?? false),
            'type' => isset($mapping['type']) && is_string($mapping['type']) ? $mapping['type'] : null,
        ];
        break;
    }
}
```
Better still, call the documented metadata accessors instead of casting/array-poking:
`$metadata->fieldMappings`, and read `$fm->columnName`, `$fm->nullable`, `$fm->type`
property access (guarded with `property_exists`/`isset` for cross-version safety). Then add
a unit test that constructs the rule **with** a real `ObjectMetadataResolver` against a
mapped fixture so this path is exercised in CI.

## Warnings

### WR-01: The `ObjectMetadataResolver` is never injected — the entire phpstan-doctrine path is dead code as wired

**File:** `extension.neon:19-22`, `src/PHPStan/Rule/TenantIdDriftRule.php:34-39`

**Issue:** `TenantIdDriftRule::__construct()` declares
`?object $objectMetadataResolver = null` with the comment *"injected by phpstan-doctrine
when installed"*. But the `extension.neon` service definition for `TenantIdDriftRule`
(lines 19-22) declares **no `arguments`**, and PHPStan's Nette DI container cannot autowire
a parameter typed `?object` to the concrete `@PHPStan\Type\Doctrine\ObjectMetadataResolver`
service (the type hint is `object`, not the class; the default is `null`). Confirmed by
grep: nothing in the repo wires `objectMetadataResolver` except the test's
"No ObjectMetadataResolver" comment. Result: even with `phpstan/phpstan-doctrine`
installed, `$this->objectMetadataResolver` is always `null`, the `checkViaMetadata()` path
(see CR-01) is never reached, and the advertised "XML/YAML-mapped entity analysis" feature
(`composer.json` suggest line 61) silently does nothing.

This is the only thing currently shielding consumers from CR-01 — but it means the feature
is non-functional rather than safe by design.

**Fix:** Wire the resolver conditionally and reference the concrete type so DI can inject
it when phpstan-doctrine is present. One option is a dedicated dogfood-with-doctrine neon
fragment that adds the argument when the class exists; or use a small factory/optional
service reference, e.g. in `extension.neon`:
```yaml
    -
        class: Tenancy\Bundle\PHPStan\Rule\TenantIdDriftRule
        arguments:
            objectMetadataResolver: @PHPStan\Type\Doctrine\ObjectMetadataResolver
        tags:
            - phpstan.rules.rule
```
…guarded so it does not fail when phpstan-doctrine is absent (PHPStan resolves `@service`
references at config-merge time, so this must live in a conditionally-included fragment).
Until CR-01 is fixed, wiring this would expose the FieldMapping bug — fix CR-01 first,
then wire and add coverage.

### WR-02: `#[TenantAware]` MappedSuperclass base classes produce false positives

**File:** `src/PHPStan/Rule/TenantIdDriftRule.php:49-83`, fixtures `TenantAwareParent.php`

**Issue:** The rule fires on any `#[TenantAware]` class lacking a `tenant_id` column in its
own hierarchy, with no check for whether the class is a non-instantiable
`#[ORM\MappedSuperclass]` / abstract base that legitimately defers the `tenant_id` column
to concrete subclasses. The rule's own test `testFiresOnInheritedTenantAware`
(`TenantIdDriftRuleTest.php:104-126`) codifies this: it asserts the rule fires on
`TenantAwareParent` (a `#[ORM\MappedSuperclass]`) itself. A real consumer who puts
`#[TenantAware]` on a MappedSuperclass and declares the `tenant_id` column in each concrete
entity (a common, correct Doctrine pattern, and exactly how this bundle's own
`AbstractTenant` split works) will get a permanent false error on the base class even
though every concrete entity is correct. This trains users to suppress/ignore the rule.

**Fix:** Skip classes that are abstract or carry `#[ORM\MappedSuperclass]` /
`#[ORM\Embeddable]` when no `tenant_id` is found in their own hierarchy — only the
concrete, instantiable entity should be required to resolve a `tenant_id`:
```php
$nativeReflection = $classReflection->getNativeReflection();
if ($nativeReflection->isAbstract()
    || [] !== $nativeReflection->getAttributes(\Doctrine\ORM\Mapping\MappedSuperclass::class)) {
    return [];
}
```
Place after the `hasTenantAwareInHierarchy` check, before evaluating the column.

### WR-03: Reflection fallback hardcodes underscore naming — wrong column derivation under Doctrine's actual default strategy

**File:** `src/PHPStan/Rule/TenantIdDriftRule.php:174-179`

**Issue:** For a `#[ORM\Column]` with no explicit name, the rule derives the column from the
property via `strtolower(preg_replace('/([A-Z])/', '_$1', lcfirst($propName)))`, i.e. it
assumes the `UnderscoreNamingStrategy` (`$tenantId` → `tenant_id`). But Doctrine ORM's
**default** strategy is `DefaultNamingStrategy::propertyToColumnName()`, which returns the
property name verbatim (verified: `return $propertyName;`). So under the default strategy,
property `$tenantId` with `#[ORM\Column]` maps to column `tenantId`, not `tenant_id`. The
rule cannot know the consumer's configured naming strategy from pure reflection, so this
derivation is a guess that is wrong for the default strategy. In the default-strategy case
the rule's *conclusion* ("no tenant_id column") happens to align with the
`TenantAwareFilter`'s hardcoded `tenant_id` (which also won't match `tenantId`), so it is
not a leak — but the diagnostic reasoning is incorrect and the message will mislead users
who actually wrote `tenant_id` literally via a non-default-but-non-underscore mapping.

**Fix:** Treat the property-name-derivation branch as best-effort and lower-confidence:
prefer matching only the explicit-name and exact `tenant_id` property cases with high
confidence, and document that reliable detection of unnamed columns requires the
phpstan-doctrine metadata path (once CR-01/WR-01 are fixed, that path knows the real
column name and naming strategy). At minimum, also match the verbatim property name
(`$tenantId` → column `tenantId`) so the default strategy is covered, and only emit the
"non-string"/"nullable" sub-diagnostics when the column was resolved with certainty.

### WR-04: Positional `nullable`/`type` arguments on `#[ORM\Column]` are silently missed

**File:** `src/PHPStan/Rule/TenantIdDriftRule.php:183-184`

**Issue:** When reading the resolved `tenant_id` column's attributes, the rule reads only
the **named** argument keys: `$args['nullable']` and `$args['type']`. `ORM\Column`'s
constructor lists `nullable` at positional index 6 and `type` at index 1. A consumer who
writes the attribute positionally — e.g.
`#[ORM\Column('tenant_id', 'integer')]` or
`#[ORM\Column('tenant_id', 'string', 63, null, null, false, true)]` — supplies `type` /
`nullable` as `$args[1]` / `$args[6]`, which the rule never inspects. Result: a
positionally-declared nullable or non-string `tenant_id` (a real cross-tenant leak risk)
is **not** flagged — a false negative in the security-relevant direction. This is narrow
(few people write `Column` fully positionally past `name`), but it is the one false-
negative path in an otherwise precision-first rule, so it deserves a fix.

**Fix:** Fall back to positional indices when the named keys are absent, mirroring the
`name` resolution already done on line 172:
```php
$nullableRaw = $args['nullable'] ?? $args[6] ?? false;
$typeRaw     = $args['type']     ?? $args[1] ?? null;
$found = [
    'nullable' => (bool) $nullableRaw,
    'type' => is_string($typeRaw) ? $typeRaw : null,
];
```

### WR-05: CI never runs the dogfood (or any rule) without Doctrine — the optional-dependency guards are untested

**File:** `.github/workflows/ci.yml:74-115`

**Issue:** Two of the three rules guard their entry points with
`interface_exists(\Doctrine\ORM\EntityManagerInterface::class)`
(`TenantIdDriftRule.php:55`, `SharedEntityLeakRule.php:72`) precisely because Doctrine is
an optional dependency. But CI never exercises that guard:
- The `phpstan` job (incl. the dogfood, lines 59-75) runs with the full `require-dev`,
  i.e. Doctrine present — the early-return branch is never taken.
- The `no-doctrine` job (lines 95-115) removes Doctrine but runs **only PHPUnit** against
  a hand-listed set of unit directories; it does **not** run PHPStan or the dogfood, and
  the PHPStan rule unit tests under `tests/Unit/PHPStan` are not in its allow-list.

So the central design claim — "the rules load and degrade gracefully when Doctrine is
absent" — has no automated proof. A regression that hard-references a Doctrine class at
class-load time (e.g. type-hinting a Doctrine class in a signature, or a `use` that
triggers autoload) would pass all current CI.

**Fix:** Add a dogfood-without-Doctrine step to the `no-doctrine` job (or a new job): after
`composer remove --dev doctrine/*`, run
`vendor/bin/phpstan analyse -c phpstan-extension-dogfood.neon --memory-limit=512M` and
assert it exits clean, plus run `vendor/bin/phpunit tests/Unit/PHPStan`. This is the only
way to lock in the optional-dependency contract for the shipped extension.

## Info

### IN-01: `composer.json` `extra.phpstan.includes` + extension-installer is two delivery mechanisms — document the precedence

**File:** `composer.json:80-85`, `composer.json:60`

**Issue:** The bundle ships its extension via both `extra.phpstan.includes: ["extension.neon"]`
and by relying on `phpstan/extension-installer` (suggested for "zero-config auto-loading").
For a consumer these do not double-load (PHPStan core reads `extra.phpstan.includes` only
from the root project, while extension-installer reads it from dependencies — verified in
`Plugin.php:124-176`), so this is correct. But it is non-obvious and a future maintainer
could "simplify" by deleting one and break either the with-installer or without-installer
consumer path. Add a short comment / doc note recording why both exist and that neither
should be removed without testing the other consumer scenario.

**Fix:** Document in the extension README/install docs the two supported install paths and
that `extra.phpstan.includes` is the fallback for consumers who do not run
extension-installer.

### IN-02: `(array)` cast on a Doctrine `ClassMetadata` object is fragile beyond the CR-01 bug

**File:** `src/PHPStan/Rule/TenantIdDriftRule.php:118`

**Issue:** Even once CR-01 is fixed, `$raw = (array) $metadata;` to reach `fieldMappings`
relies on the property being `public` and on object-to-array cast semantics (which mangle
private/protected keys with null-byte prefixes). It works today only because
`ClassMetadata::$fieldMappings` happens to be public, but it is an undocumented coupling to
Doctrine internals.

**Fix:** Read `$metadata->fieldMappings` directly (it is a documented public property) or,
better, go through the metadata accessor API, rather than casting the whole object to an
array.

### IN-03: Duplicated `hasAttributeInHierarchy` walk across all three rules

**File:** `src/PHPStan/Rule/MutualExclusionRule.php:75-88`,
`src/PHPStan/Rule/TenantIdDriftRule.php:92-105`,
`src/PHPStan/Rule/SharedEntityLeakRule.php:159-172`

**Issue:** The "walk PHPStan `ClassReflection` ancestors and check
`getNativeReflection()->getAttributes($attr)`" loop is copy-pasted into all three rules
(and a fourth native-reflection variant lives in `SharedEntityMutualExclusionPass`). Four
copies of a security-relevant traversal means a fix to one (e.g. handling interfaces, or a
BetterReflection edge case) can silently miss the others.

**Fix:** Extract a small shared helper (e.g. a trait or a static utility in
`src/PHPStan/`) `hasClassAttributeInHierarchy(ClassReflection, string $attr): bool` and
have all three rules delegate to it. Keep it dependency-free so it loads without Doctrine.

---

_Reviewed: 2026-06-16_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
