# Phase 28: PHPStan Extension - Pattern Map

**Mapped:** 2026-06-16
**Files analyzed:** 10 new/modified files
**Analogs found:** 8 / 10 (2 new-territory files with no in-repo analog)

---

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `src/PHPStan/Rule/MutualExclusionRule.php` | rule/utility | transform | `src/DependencyInjection/Compiler/SharedEntityMutualExclusionPass.php` | role-match (edit-time mirror of boot-time guard) |
| `src/PHPStan/Rule/TenantIdDriftRule.php` | rule/utility | transform | `src/DependencyInjection/Compiler/SharedEntityMutualExclusionPass.php` | role-match (same hierarchy-walk + optional-dep guard) |
| `src/PHPStan/Rule/SharedEntityLeakRule.php` | rule/utility | request-response | `src/Filter/TenantAwareFilter.php` | partial (both interrogate entity type + attribute presence) |
| `src/PHPStan/Rule/Helper/AttributeHierarchyHelper.php` | utility | transform | `src/DependencyInjection/Compiler/SharedEntityMutualExclusionPass.php` (private `hasAttributeInHierarchy()`) | exact extraction |
| `extension.neon` | config | — | `phpstan.neon` (bundle self-analysis neon) | same format, different purpose |
| `tests/Unit/PHPStan/Rule/MutualExclusionRuleTest.php` | test | — | `tests/Unit/DependencyInjection/Compiler/SharedEntityMutualExclusionPassTest.php` | role-match (same domain, PHPUnit 11 conventions) |
| `tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php` | test | — | `tests/Unit/DependencyInjection/Compiler/SharedEntityMutualExclusionPassTest.php` | role-match |
| `tests/Unit/PHPStan/Rule/SharedEntityLeakRuleTest.php` | test | — | `tests/Unit/DependencyInjection/Compiler/SharedEntityMutualExclusionPassTest.php` | role-match |
| `tests/Unit/PHPStan/Rule/data/*.php` (6 fixture files) | test fixture | — | `tests/Unit/DependencyInjection/Compiler/SharedEntityMutualExclusionPassTest.php` (inline fixtures at file bottom) | role-match |
| `composer.json` (modified) | config | — | `composer.json` (current state) | exact (modification only) |

---

## Pattern Assignments

### `src/PHPStan/Rule/MutualExclusionRule.php` (rule, transform)

**Analog:** `src/DependencyInjection/Compiler/SharedEntityMutualExclusionPass.php`

**Namespace pattern** — the PSR-4 root `Tenancy\Bundle\` maps to `src/`, so the PHPStan rules land under:
```
Tenancy\Bundle\PHPStan\Rule\MutualExclusionRule
```

**Imports pattern** — mirror the analog's import style (FQCNs in use statements, not inline):
```php
declare(strict_types=1);

namespace Tenancy\Bundle\PHPStan\Rule;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Tenancy\Bundle\Attribute\Shared;
use Tenancy\Bundle\Attribute\TenantAware;
```

**Core pattern — hierarchy walk logic extracted from analog** (`SharedEntityMutualExclusionPass.php` lines 62–68 + 81–90):
```php
// From SharedEntityMutualExclusionPass.php lines 62–68 — this is the EXACT logic to mirror
$hasShared     = $this->hasAttributeInHierarchy($rc, \Tenancy\Bundle\Attribute\Shared::class);
$hasTenantAware = $this->hasAttributeInHierarchy($rc, \Tenancy\Bundle\Attribute\TenantAware::class);

if ($hasShared && $hasTenantAware) {
    throw new \LogicException(sprintf(...));
}

// hasAttributeInHierarchy() — lines 81–90 (the exact private method to lift into AttributeHierarchyHelper):
private function hasAttributeInHierarchy(\ReflectionClass $rc, string $attribute): bool
{
    for ($current = $rc; false !== $current; $current = $current->getParentClass()) {
        if ([] !== $current->getAttributes($attribute)) {
            return true;
        }
    }

    return false;
}
```

**PHPStan Rule adaptation** — the analog uses `new \ReflectionClass($class)` directly; the Rule gets it via `$node->getClassReflection()->getNativeReflection()`:
```php
// @implements Rule<InClassNode>
public function getNodeType(): string
{
    return InClassNode::class;
}

/** @return list<IdentifierRuleError> */
public function processNode(Node $node, Scope $scope): array
{
    $classReflection  = $node->getClassReflection();
    $nativeReflection = $classReflection->getNativeReflection();

    // Delegate hierarchy walk to AttributeHierarchyHelper (extracted from analog)
    if (!$this->helper->hasAttributeInHierarchy($nativeReflection, Shared::class)) {
        return [];
    }
    if (!$this->helper->hasAttributeInHierarchy($nativeReflection, TenantAware::class)) {
        return [];
    }

    return [
        RuleErrorBuilder::message(sprintf(
            'Entity %s cannot carry both #[Shared] and #[TenantAware]. A shared entity is a landlord-side master; a TenantAware entity is tenant-scoped. Pick one.',
            $classReflection->getName()
        ))
            ->identifier('tenancy.mutualExclusion')
            ->build(),
    ];
}
```

**Error identifier:** `tenancy.mutualExclusion`

**Critical PHPStan 2.x constraint:** `processNode()` must return `list<IdentifierRuleError>`, never plain strings. Every `RuleErrorBuilder` chain must end with `->identifier('tenancy.xxx')->build()`. Missing `->identifier()` causes a PHPStan fatal error.

---

### `src/PHPStan/Rule/TenantIdDriftRule.php` (rule, transform)

**Analog:** `src/DependencyInjection/Compiler/SharedEntityMutualExclusionPass.php` for hierarchy walk; `src/Filter/TenantAwareFilter.php` line 47 for the hardcoded column name.

**Namespace:**
```
Tenancy\Bundle\PHPStan\Rule\TenantIdDriftRule
```

**Hardcoded column from analog** (`src/Filter/TenantAwareFilter.php` line 47):
```php
// This is the exact string Rule 3 validates against — do not make it configurable
return sprintf(
    "%s.tenant_id = '%s'",
    $targetTableAlias,
    addslashes($tenant->getSlug())
);
```
Rule 3 checks the **column name** `tenant_id` (not the PHP property name). The filter hardcodes this string; Rule 3 hardcodes the same.

**Optional-dependency guard pattern** (`SharedEntityMutualExclusionPass.php` lines 43–46 — guard at top of processing method):
```php
// Analog: early-return when Doctrine is absent (optional dep)
if (!interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
    return;
}
```
For Rule 3, apply the same guard in the constructor: guard the `ObjectMetadataResolver` code path with `class_exists()`. When phpstan-doctrine is absent, accept `null` and fall back:
```php
public function __construct(
    private readonly AttributeHierarchyHelper $helper,
    // D-02: optional — injected by phpstan-doctrine when installed; null when absent
    private readonly ?object $objectMetadataResolver = null,
) {
}
```

**Reflection fallback pattern** (D-02 absent path) — property scan for `#[ORM\Column]`:
```php
// Walk properties looking for a column mapped to 'tenant_id'
$nativeReflection = $classReflection->getNativeReflection();
$foundColumn = null;

foreach ($nativeReflection->getProperties() as $property) {
    foreach ($property->getAttributes(\Doctrine\ORM\Mapping\Column::class) as $attr) {
        $args    = $attr->getArguments();
        $colName = $args['name'] ?? $args[0] ?? null;
        // When no explicit name: Doctrine's default = property name (snake_case).
        // Check both $tenantId (camelCase → snake_case) and $tenant_id (explicit)
        if ($colName === null) {
            $rawName = $property->getName();
            // naive snake_case: tenantId → tenant_id
            $colName = strtolower(preg_replace('/([A-Z])/', '_$1', lcfirst($rawName)));
        }
        if ($colName === 'tenant_id') {
            $foundColumn = [
                'nullable' => (bool) ($args['nullable'] ?? false),
                'type'     => $args['type'] ?? null,
            ];
            break 2;
        }
    }
}
```

**Error identifier:** `tenancy.tenantIdDrift`

---

### `src/PHPStan/Rule/SharedEntityLeakRule.php` (rule, request-response)

**Analog:** `src/Filter/TenantAwareFilter.php` (both check attribute presence on entities before acting)

**Namespace:**
```
Tenancy\Bundle\PHPStan\Rule\SharedEntityLeakRule
```

**Key difference from Rules 1 & 3:** operates on `MethodCall` nodes, not `InClassNode`.

**Constructor — parameter toggle** (D-01 gated rule):
```php
public function __construct(
    private readonly AttributeHierarchyHelper $helper,
    private readonly bool $checkSharedEntityLeaks,
) {
}
```
The `$checkSharedEntityLeaks` value is injected from the neon `%tenancy.checkSharedEntityLeaks%` parameter.

**Core pattern** (D-03 conservative — fire only on unambiguous tenant EM):
```php
// @implements Rule<\PhpParser\Node\Expr\MethodCall>
public function getNodeType(): string
{
    return \PhpParser\Node\Expr\MethodCall::class;
}

/** @return list<IdentifierRuleError> */
public function processNode(Node $node, Scope $scope): array
{
    // D-01: gate
    if (!$this->checkSharedEntityLeaks) {
        return [];
    }

    // D-03: conservative — only fire on concrete EntityManager type, not EntityManagerInterface
    $callerType = $scope->getType($node->var);
    // ... resolve entity class from first arg (::class constant only)
    // ... use $this->helper->hasAttributeInHierarchy() on the resolved class
    // Stay silent on ambiguous types
}
```

**Error identifier:** `tenancy.sharedEntityLeak`

---

### `src/PHPStan/Rule/Helper/AttributeHierarchyHelper.php` (utility, transform)

**Analog:** `src/DependencyInjection/Compiler/SharedEntityMutualExclusionPass.php` lines 71–90 — this is a direct extraction.

**Namespace:**
```
Tenancy\Bundle\PHPStan\Rule\Helper\AttributeHierarchyHelper
```

**Exact extraction** (copy `hasAttributeInHierarchy()` from `SharedEntityMutualExclusionPass.php` lines 81–90 verbatim, promoting it to a public service class):
```php
declare(strict_types=1);

namespace Tenancy\Bundle\PHPStan\Rule\Helper;

final class AttributeHierarchyHelper
{
    /**
     * Whether $rc or any of its ancestor classes carries the given attribute.
     *
     * PHP class attributes are not inherited and ReflectionClass::getAttributes()
     * reports only attributes declared directly on the reflected class, so a
     * #[Shared]/#[TenantAware] declared on a parent or mapped-superclass must be
     * discovered by walking getParentClass() explicitly.
     *
     * Mirrors SharedEntityMutualExclusionPass::hasAttributeInHierarchy() — that pass
     * is the boot-time twin of the Rules that use this helper at edit-time.
     *
     * @param \ReflectionClass<object> $rc
     * @param class-string             $attribute
     */
    public function hasAttributeInHierarchy(\ReflectionClass $rc, string $attribute): bool
    {
        for ($current = $rc; false !== $current; $current = $current->getParentClass()) {
            if ([] !== $current->getAttributes($attribute)) {
                return true;
            }
        }

        return false;
    }
}
```

---

### `extension.neon` (config, new file at package root)

**Analog:** `phpstan.neon` (bundle self-analysis config) — same file format; keep ENTIRELY SEPARATE.

**Current `phpstan.neon`** (must NOT include `extension.neon`, and vice versa):
```neon
# phpstan.neon — bundle's OWN level-9 analysis of src/; SEPARATE from extension.neon
parameters:
    level: 9
    paths:
        - src
    treatPhpDocTypesAsCertain: false
    ignoreErrors:
        -
            identifier: trait.unused
            reportUnmatched: false
    parallel:
        maximumNumberOfProcesses: 1
```

**New `extension.neon`** (consumer-facing, shipped at package root):
```neon
# extension.neon — shipped consumer extension; auto-loaded by phpstan/extension-installer
# DO NOT include this file in phpstan.neon (bundle self-analysis)

parametersSchema:
    tenancy: structure([
        checkSharedEntityLeaks: bool()
    ])

parameters:
    tenancy:
        checkSharedEntityLeaks: true

services:
    -
        class: Tenancy\Bundle\PHPStan\Rule\Helper\AttributeHierarchyHelper

    -
        class: Tenancy\Bundle\PHPStan\Rule\MutualExclusionRule
        arguments:
            helper: @Tenancy\Bundle\PHPStan\Rule\Helper\AttributeHierarchyHelper
        tags:
            - phpstan.rules.rule

    -
        class: Tenancy\Bundle\PHPStan\Rule\TenantIdDriftRule
        arguments:
            helper: @Tenancy\Bundle\PHPStan\Rule\Helper\AttributeHierarchyHelper
        tags:
            - phpstan.rules.rule

    -
        class: Tenancy\Bundle\PHPStan\Rule\SharedEntityLeakRule
        arguments:
            helper: @Tenancy\Bundle\PHPStan\Rule\Helper\AttributeHierarchyHelper
            checkSharedEntityLeaks: %tenancy.checkSharedEntityLeaks%
        tags:
            - phpstan.rules.rule
```

---

### `tests/Unit/PHPStan/Rule/MutualExclusionRuleTest.php` (test)

**Analog:** `tests/Unit/DependencyInjection/Compiler/SharedEntityMutualExclusionPassTest.php`

**Namespace pattern** (from `composer.json` autoload-dev — `Tenancy\Bundle\Tests\` → `tests/`):
```
Tenancy\Bundle\Tests\Unit\PHPStan\Rule\MutualExclusionRuleTest
```

**PHPUnit 11 test class conventions** (from analog, lines 1–34):
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\PHPStan\Rule;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tenancy\Bundle\PHPStan\Rule\MutualExclusionRule;

/**
 * @extends RuleTestCase<MutualExclusionRule>
 */
final class MutualExclusionRuleTest extends RuleTestCase
```

**Key difference from regular TestCase analogs:** `RuleTestCase` (from `phpstan/phpstan`) replaces `PHPUnit\Framework\TestCase` as base class. The `getRule()`, `analyse()`, and `getAdditionalConfigFiles()` methods are provided by `RuleTestCase`.

**Required overrides:**
```php
protected function getRule(): Rule
{
    return new MutualExclusionRule(
        new \Tenancy\Bundle\PHPStan\Rule\Helper\AttributeHierarchyHelper()
    );
}

/** Loads extension.neon so parametersSchema is available during tests */
public static function getAdditionalConfigFiles(): array
{
    return [__DIR__ . '/../../../../extension.neon'];
}
```

**Test method pattern** (RuleTestCase assertion style — compare with analog's `expectException` style):
```php
public function testMutualExclusionViolation(): void
{
    $this->analyse(
        [__DIR__ . '/data/mutual-exclusion-violating.php'],
        [
            [
                'Entity Fixtures\PHPStan\BothAttributesViolating cannot carry both #[Shared] and #[TenantAware]. A shared entity is a landlord-side master; a TenantAware entity is tenant-scoped. Pick one.',
                // line number of the class declaration in the fixture file
                10,
            ],
        ]
    );
}

public function testNoViolationWhenOnlyOneAttribute(): void
{
    // Second argument is empty array — assert NO errors
    $this->analyse([__DIR__ . '/data/mutual-exclusion-clean.php'], []);
}
```

---

### `tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php` (test)

**Analog:** Same as `MutualExclusionRuleTest.php` above.

**Namespace:** `Tenancy\Bundle\Tests\Unit\PHPStan\Rule\TenantIdDriftRuleTest`

Three test cases required (D-04):
1. No `tenant_id` column → `tenancy.tenantIdDrift` violation
2. `tenant_id` nullable → `tenancy.tenantIdDrift` violation
3. `tenant_id` non-string type → `tenancy.tenantIdDrift` violation
4. Valid `tenant_id` (non-nullable string) → no violation

---

### `tests/Unit/PHPStan/Rule/SharedEntityLeakRuleTest.php` (test)

**Analog:** Same as `MutualExclusionRuleTest.php` above.

**Namespace:** `Tenancy\Bundle\Tests\Unit\PHPStan\Rule\SharedEntityLeakRuleTest`

Constructor injection of the `$checkSharedEntityLeaks` bool:
```php
protected function getRule(): Rule
{
    return new SharedEntityLeakRule(
        new \Tenancy\Bundle\PHPStan\Rule\Helper\AttributeHierarchyHelper(),
        true // checkSharedEntityLeaks = true for violation tests
    );
}
```
Separate test method with `false` to cover the gated-off path (D-01).

---

### `tests/Unit/PHPStan/Rule/data/*.php` (6 fixture files)

**Analog:** `tests/Unit/DependencyInjection/Compiler/SharedEntityMutualExclusionPassTest.php` lines 137–216 (inline fixture classes at file bottom).

**Key difference:** RuleTestCase expects fixtures as **separate files** (not inline in the test class), placed in a `data/` subdirectory alongside the test. The analog uses inline classes in the same test file — RuleTestCase's `analyse()` takes file paths, so fixtures are external.

**Fixture namespace pattern** (from RESEARCH.md Example 4):
```php
<?php
declare(strict_types=1);
namespace Fixtures\PHPStan;

use Tenancy\Bundle\Attribute\Shared;
use Tenancy\Bundle\Attribute\TenantAware;
```

**Fixture structure to copy from analog's inline classes** (lines 141–216):
- `mutual-exclusion-violating.php` → mirrors `BothAttributesEntity` (both attrs) + inheritance variant mirroring `InheritedSharedTenantAwareEntity`
- `mutual-exclusion-clean.php` → mirrors `OnlySharedEntity` + `OnlyTenantAwareClean`
- `tenant-id-drift-violating.php` → new territory (Doctrine entity fixtures, see No Analog section)
- `tenant-id-drift-clean.php` → new territory
- `shared-entity-leak-violating.php` → new territory (EM method call fixtures)
- `shared-entity-leak-clean.php` → new territory

---

### `composer.json` (modified)

**Current state** (read from file):
- `"type": "symfony-bundle"` — keep as-is (do NOT change to `phpstan-extension`; extension-installer detects by `extra.phpstan.includes` presence)
- `"extra"` block currently has only `branch-alias`
- `require-dev` has `"phpstan/phpstan": "^2.1"` but NOT `phpstan/extension-installer` or `phpstan/phpstan-doctrine`
- `suggest` block has Doctrine/Flysystem/Mailer entries

**Required modifications:**

1. Add `extra.phpstan.includes` (new key alongside existing `branch-alias`):
```json
"extra": {
    "branch-alias": {
        "dev-master": "0.1.x-dev"
    },
    "phpstan": {
        "includes": [
            "extension.neon"
        ]
    }
}
```

2. Add to `require-dev` (phpstan/phpstan-doctrine version constraint requires careful handling — see RESEARCH.md Pitfall 3; add with `@dev` flag and update `minimum-stability` to `dev` with `prefer-stable: true` if needed):
```json
"phpstan/extension-installer": "^1.4",
"phpstan/phpstan-doctrine": "^2.0@dev"
```

3. Add to `suggest`:
```json
"phpstan/extension-installer": "For zero-config auto-loading of the tenancy PHPStan rules (^1.4)",
"phpstan/phpstan-doctrine": "For full Doctrine metadata support in the tenancy PHPStan rules — enables XML/YAML-mapped entity analysis (^2.0@dev)"
```

**PLANNER NOTE — version conflict risk:** `phpstan/phpstan-doctrine ^2.0@dev` requires `phpstan/phpstan ^2.2.2`, but the bundle currently has `^2.1` (2.1.50 installed). Planner must include a Wave 0 checkpoint to verify whether bumping to `^2.2` is needed, or whether the D-02 "absent" (reflection fallback) path alone should be tested in CI.

---

## Shared Patterns

### Optional-dependency guarding (apply to `TenantIdDriftRule` and `SharedEntityLeakRule`)

**Source:** `src/DependencyInjection/Compiler/SharedEntityMutualExclusionPass.php` lines 43–46; `config/services.php` lines 60, 103, 149, 162, 247.

**Pattern — early return on missing interface:**
```php
// Guard Doctrine as optional dep — mirror SharedEntityMutualExclusionPass line 44
if (!interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
    return [];
}
```

**Pattern — class_exists guard for optional service:**
```php
// Mirror config/services.php pattern for optional phpstan-doctrine integration
if (class_exists(\PHPStan\Type\Doctrine\ObjectMetadataResolver::class)) {
    // D-02 "present" path
} else {
    // D-02 "absent" / reflection fallback path
}
```

**Apply to:** `TenantIdDriftRule` (guards Doctrine ORM presence AND phpstan-doctrine presence), `SharedEntityLeakRule` (guards Doctrine ORM presence).

---

### Attribute marker detection (apply to all three Rule classes)

**Source:** `src/Attribute/Shared.php` lines 30–31; `src/Attribute/TenantAware.php` lines 16–17.

Both attributes are bare `#[\Attribute(\Attribute::TARGET_CLASS)] final class` markers with NO constructor arguments. Detection is presence-only — `getAttributes(Shared::class)` returns non-empty if the attribute is present.

```php
// Shared.php line 30 — bare marker, TARGET_CLASS, no constructor args
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Shared {}

// TenantAware.php line 16 — same pattern
#[\Attribute(\Attribute::TARGET_CLASS)]
final class TenantAware {}
```

Rules detect via: `$current->getAttributes(Shared::class) !== []` (in hierarchy walk).

---

### PHPUnit 11 test class header (apply to all three RuleTestCase test files)

**Source:** `tests/Unit/Attribute/SharedTest.php` lines 1–8; `tests/Unit/DependencyInjection/Compiler/SharedEntityMutualExclusionPassTest.php` lines 1–12.

```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\PHPStan\Rule;

// Namespace prefix: Tenancy\Bundle\Tests\Unit\... (from composer.json autoload-dev line 69)
// PSR-4: Tenancy\Bundle\Tests\ → tests/
```

Class is always `final` unless abstract. No `setUp()` skip guard needed for RuleTestCase (unlike PHPUnit TestCase analogs that use `markTestSkipped`) — RuleTestCase tests are self-contained once the rule class exists.

---

### `strict_types` + `final` class convention (apply to all new PHP files)

**Source:** Every existing `src/` and `tests/` file opens with `declare(strict_types=1);`. All production classes are `final` unless they are abstract base classes or interfaces.

```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\PHPStan\Rule;

// ...

final class MutualExclusionRule implements Rule  // always final
```

---

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `tests/Unit/PHPStan/Rule/data/tenant-id-drift-*.php` | test fixture | — | No existing Doctrine entity fixtures with `#[ORM\Column]` in the test suite; use RESEARCH.md Example 2 column patterns instead |
| `tests/Unit/PHPStan/Rule/data/shared-entity-leak-*.php` | test fixture | — | No existing MethodCall-level PHPStan fixture in the codebase; use RESEARCH.md Pattern 4 + D-03 conservative EM detection guidance |

For these two fixture pairs, the planner should use RESEARCH.md Example 2 (Rule 3 column reflection), RESEARCH.md Pattern 4 (Rule 2 MethodCall), and the D-03 conservative behavior notes as the authoritative pattern source.

---

## PSR-4 Autoload Verification

**Source:** `composer.json` lines 62–70.

```json
"autoload": {
    "psr-4": {
        "Tenancy\\Bundle\\": "src/"
    }
},
"autoload-dev": {
    "psr-4": {
        "Tenancy\\Bundle\\Tests\\": "tests/"
    }
}
```

- `src/PHPStan/Rule/MutualExclusionRule.php` → namespace `Tenancy\Bundle\PHPStan\Rule\MutualExclusionRule` — **covered by existing `src/` mapping**
- `src/PHPStan/Rule/Helper/AttributeHierarchyHelper.php` → namespace `Tenancy\Bundle\PHPStan\Rule\Helper\AttributeHierarchyHelper` — **covered**
- `tests/Unit/PHPStan/Rule/MutualExclusionRuleTest.php` → namespace `Tenancy\Bundle\Tests\Unit\PHPStan\Rule\MutualExclusionRuleTest` — **covered by existing `tests/` mapping**

No new autoload entries required in `composer.json`.

**PHPUnit suite coverage:** `phpunit.xml.dist` line 9 has `<directory>tests/Unit</directory>` — `tests/Unit/PHPStan/Rule/` is automatically covered by the existing `unit` testsuite. No `phpunit.xml.dist` modifications required.

---

## Metadata

**Analog search scope:** `src/DependencyInjection/Compiler/`, `src/Attribute/`, `src/Filter/`, `tests/Unit/DependencyInjection/Compiler/`, `tests/Unit/Attribute/`, `config/`, `composer.json`, `phpstan.neon`
**Files scanned:** 11
**Pattern extraction date:** 2026-06-16
