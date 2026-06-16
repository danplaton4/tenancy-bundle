# Phase 28: PHPStan Extension - Research

**Researched:** 2026-06-16
**Domain:** PHPStan custom rules, PHP attribute reflection, Doctrine ORM metadata
**Confidence:** HIGH (core PHPStan API verified via official docs and running PHPStan 2.1.50 in vendor; package registry confirmed via Packagist; some D-02 ObjectMetadataResolver internals MEDIUM due to phpstan-doctrine ^2.0 being dev-only on Packagist)

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**D-01 (Rule activation model — middle path):** `phpstan/extension-installer` auto-loads `extension.neon`, registering all three rules. Rules 1 (mutual exclusion) and 3 (`tenant_id` drift) fire zero-config. Rule 2 (cross-EM leak) is registered but gated by a PHPStan parameter `tenancy.checkSharedEntityLeaks` (default `true`). Two install paths: extension-installer present → zero-config; absent → documented manual `includes:` snippet.

**D-02 (Doctrine metadata source — soft-integrate, degrade):** Use `phpstan/phpstan-doctrine`'s `ObjectMetadataResolver` when present; degrade gracefully to a reflection scan of `#[ORM\Column]` attributes when absent. Guard with `class_exists`/service-availability check. Document "install `phpstan/phpstan-doctrine` for full coverage."

**D-03 (Rule 2 — conservative/precision-first):** Fire ONLY when the rule can confidently see the tenant/default EM querying a `#[Shared]` entity. Safe paths: query routed through the named landlord EM, or explicit `@phpstan-ignore tenancy.sharedEntityLeak`. The acceptance text's `setEntityManager('landlord')` does NOT exist — the real static signal is WHICH EM the query goes through. Do NOT invent a `setEntityManager()` API.

**D-04 (Rule 3 — name + nullable + string-type):** Fire when a `#[TenantAware]` entity (a) has no column mapped to `tenant_id`, OR (b) maps it nullable, OR (c) maps it to a non-string type. No length assertion. Column name hardcoded as `tenant_id` (validated against `src/Filter/TenantAwareFilter.php:47`).

### Claude's Discretion

- Rule-class location/namespace (`src/PHPStan/Rule/…`), shipped neon filename, separation from bundle's own `phpstan.neon`
- Error identifier strings (Rule 2 identifier is `tenancy.sharedEntityLeak`; Rules 1 and 3 identifiers are Claude's to name, e.g. `tenancy.mutualExclusion`, `tenancy.tenantIdDrift`)
- Whether each rule is one class or shares helpers
- `RuleTestCase` fixture design and CI dogfooding decisions
- `composer.json` require-dev additions (`phpstan/extension-installer`, `phpstan/phpstan-doctrine`) vs `suggest` entries

### Deferred Ideas (OUT OF SCOPE)

- User-guide `phpstan-extension.md` page — Phase 29 (DOC-20)
- Configurable tenant-scope column name — rejected outright, not deferred
- Aggressive/tenant-default Rule 2 modes — rejected; future `strictness` parameter could layer on top
- VARCHAR length assertion on `tenant_id` — rejected; not deferred
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| DX-03 | PHPStan extension for `#[TenantAware]` + `#[Shared]` correctness — three rules catching misuse at static-analysis time | Rule interface (InClassNode + MethodCall), RuleTestCase harness, extension-installer registration, parametersSchema for toggle |
| DX-03-AC1 | Rule fires on `#[TenantAware]` AND `#[Shared]` (mutual exclusion) | InClassNode rule + `getNativeReflection()->getAttributes()` hierarchy walk |
| DX-03-AC2 | Rule fires when Doctrine query in tenant EM context queries `#[Shared]` entity without landlord override | MethodCall node rule + `$scope->getType($node->var)` ObjectType check |
| DX-03-AC3 | Rule fires when `#[TenantAware]` entity `tenant_id` column is missing OR nullable=false | InClassNode + ObjectMetadataResolver (present) or `#[ORM\Column]` reflection fallback (absent) |
| DX-03-AC4 | Ships as `phpstan/extension-installer` auto-loaded via `composer.json#extra.phpstan.includes`; opt-in via `phpstan.neon` snippet | `extra.phpstan.includes` + `"type": "phpstan-extension"` pattern verified |
| DX-03-AC5 | Rule provides clear error message naming file + line + violation kind | `RuleErrorBuilder::message()->identifier()->build()` pattern verified |
</phase_requirements>

---

## Summary

Phase 28 adds the first consumer-facing PHPStan extension to the bundle. Three rules shipped in `src/PHPStan/Rule/` catch misuse of `#[TenantAware]` and `#[Shared]` attributes at editor time — the static-analysis complement to the boot-time `SharedEntityMutualExclusionPass` (Phase 25) and write-protection listener (Phase 25 D-02). All three rules register through a single `extension.neon` file that `phpstan/extension-installer` auto-discovers via `composer.json#extra.phpstan.includes`.

Rules 1 and 3 operate on `PHPStan\Node\InClassNode` and walk the class hierarchy via `getNativeReflection()->getParentClass()` — the same hierarchy-walk logic as `SharedEntityMutualExclusionPass::hasAttributeInHierarchy()`, re-expressed against PHPStan's reflection layer. Rule 2 operates on `PhpParser\Node\Expr\MethodCall`, restricts itself to the conservative case (named landlord EM vs default/tenant EM), and is gated by `tenancy.checkSharedEntityLeaks` (default true). Rule 3 has two code paths: when `phpstan/phpstan-doctrine` is installed its `ObjectMetadataResolver` provides real `ClassMetadata`; when absent the rule falls back to reading `#[ORM\Column]` attributes directly via native PHP reflection.

Testing uses PHPStan's `RuleTestCase` harness with per-rule fixture PHP files (violating + clean variants) in `tests/Unit/PHPStan/Rule/data/`. Each test overrides `getAdditionalConfigFiles()` to load the shipped `extension.neon`, ensuring tests exercise the same neon wiring consumers get.

**Primary recommendation:** Implement three `InClassNode` / `MethodCall` rules under `src/PHPStan/Rule/`, ship a single `extension.neon` declaring `parametersSchema + services + conditionalTags`, add `"type": "phpstan-extension"` to `composer.json`, and add `phpstan/extension-installer` + `phpstan/phpstan-doctrine` to `require-dev` for testing.

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Mutual exclusion detection (Rule 1) | PHPStan analysis layer | — | Pure static attribute scan; no runtime involvement |
| Cross-EM leak detection (Rule 2) | PHPStan analysis layer | Doctrine ORM (optional) | Needs method call type inference; Doctrine metadata optional for entity resolution |
| `tenant_id` config drift (Rule 3) | PHPStan analysis layer | Doctrine ORM (optional) | ClassMetadata is best source; falls back to attribute reflection |
| Extension auto-load | Composer plugin (`extension-installer`) | Manual `phpstan.neon` includes | Same two-path pattern as phpstan-symfony / phpstan-doctrine |
| Hierarchy walk (attribute inheritance) | Rule helpers (shared) | — | PHP attribute non-inheritance requires explicit ancestor walk in all class-targeting rules |
| Parameter toggle | PHPStan neon `parametersSchema` | — | `tenancy.checkSharedEntityLeaks` defined in extension.neon, readable in rule via constructor injection |

---

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `phpstan/phpstan` | `^2.1` (2.1.50 installed) | Core analysis engine + Rule/Scope/RuleErrorBuilder APIs | Already in require-dev; provides all rule interfaces |
| `nikic/php-parser` | `^5.0` | AST node types (MethodCall, Expr, etc.) | Already in `require` (production); PHPStan 2.x uses php-parser 5.x |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `phpstan/extension-installer` | `^1.4` (1.4.3 latest stable, supports `^2.0` PHPStan) | Auto-registers `extension.neon` via Composer plugin | Add to `require-dev` for testing; consumers install it optionally |
| `phpstan/phpstan-doctrine` | `^2.0` (dev-only branch; 1.5.x stable for PHPStan ^1.x only) | `ObjectMetadataResolver` for real `ClassMetadata` in Rules 2 & 3 | Add to `require-dev` for testing D-02 "present" code path; suggest for consumers |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `ObjectMetadataResolver` (phpstan-doctrine) | Native `\ReflectionClass` + `#[ORM\Column]` scan | Reflection fallback is simpler but misses XML/YAML-mapped entities |
| `InClassNode` (virtual node) | `PhpParser\Node\Stmt\Class_` | `Class_` node lacks `$scope->getClassReflection()` access; `InClassNode` is the correct choice for attribute-inspection rules |

**Installation (require-dev additions):**
```bash
composer require --dev phpstan/extension-installer:"^1.4" phpstan/phpstan-doctrine:"^2.0@dev"
```

**Version verification:**
- `phpstan/phpstan`: 2.1.50 (installed, confirmed via `vendor/phpstan/phpstan/phpstan --version`) [VERIFIED: Packagist + vendor]
- `phpstan/extension-installer`: 1.4.3 (latest stable, released 2024-09-04, supports `^2.0` PHPStan) [VERIFIED: Packagist]
- `phpstan/phpstan-doctrine`: 2.0.x-dev (latest, released 2026-06-10, requires `phpstan/phpstan: ^2.2.2`) [VERIFIED: Packagist — dev branch only; no stable 2.x tag yet]

**CRITICAL NOTE on phpstan-doctrine version:** phpstan-doctrine `^2.0` is still dev (no stable tag). The bundle's `require-dev` should use `"phpstan/phpstan-doctrine": "^2.0@dev"` with `minimum-stability: dev` guarded OR use `"2.0.*@dev"`. Alternatively, the ObjectMetadataResolver path may only be testable in dev; the reflection fallback remains the stable code path. Verify `phpstan/phpstan-doctrine` compatibility requirement (`^2.2.2`) against installed 2.1.50 — there may be a version mismatch requiring `^2.1` of phpstan/phpstan to trigger.

---

## Package Legitimacy Audit

> slopcheck unavailable (install permission denied by sandbox). All packages below are `[ASSUMED]` origin — the planner must gate each install behind a `checkpoint:human-verify` task before adding to `composer.json`.

| Package | Registry | Age | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-------------|-----------|-------------|
| `phpstan/extension-installer` | Packagist | ~6 yrs (2019) | github.com/phpstan/extension-installer | N/A (slopcheck unavailable) | [ASSUMED] — official phpstan org, well-known package |
| `phpstan/phpstan-doctrine` | Packagist | ~6 yrs (2019) | github.com/phpstan/phpstan-doctrine | N/A (slopcheck unavailable) | [ASSUMED] — official phpstan org; 2.0.x-dev only |

**Packages removed due to slopcheck [SLOP] verdict:** none (slopcheck unavailable — packages are both official `phpstan/` org packages on Packagist, well-established)

**Packages flagged as suspicious [SUS]:** none

*slopcheck was unavailable at research time. Planner must add `checkpoint:human-verify` before each install. Both packages are from the official `phpstan` GitHub organization and have years of adoption history — low risk, but protocol applies.*

---

## Architecture Patterns

### System Architecture Diagram

```
Consumer's phpstan analyse
        |
        v
[phpstan/extension-installer] ─────── auto-loads extension.neon
        |                                      |
        |                               [parametersSchema]
        |                               [parameters defaults]
        |                               [services registration]
        |                                      |
        v                                      v
 PHPStan analysis engine ──────────── Rules activated
        |
        |─── InClassNode ──────────────────────┐
        |                                       │
        |         ┌─────────────────────────────┤
        |         │  MutualExclusionRule (Rule 1)│
        |         │  walks class hierarchy       │
        |         │  checks #[Shared]+#[TenantAware]│
        |         │  fires: tenancy.mutualExclusion │
        |         └─────────────────────────────┤
        |                                       │
        |         ┌─────────────────────────────┤
        |         │  TenantIdDriftRule (Rule 3)  │
        |         │  walks class hierarchy       │
        |         │  checks tenant_id column:    │
        |         │    present / !nullable /     │
        |         │    string type               │
        |         │  ObjectMetadataResolver ─────┼── phpstan-doctrine (present)
        |         │     OR reflection fallback ──┼── #[ORM\Column] scan (absent)
        |         │  fires: tenancy.tenantIdDrift│
        |         └─────────────────────────────┘
        |
        |─── MethodCall ───────────────────────┐
                                               │
                  ┌────────────────────────────┤
                  │  SharedEntityLeakRule (Rule2)│
                  │  gated: checkSharedEntityLeaks│
                  │  scope->getType($node->var)  │
                  │  checks: is caller tenant EM │
                  │  checks: is arg #[Shared]?   │
                  │  fires: tenancy.sharedEntityLeak│
                  └────────────────────────────┘
```

### Recommended Project Structure

```
src/
├── PHPStan/
│   └── Rule/
│       ├── MutualExclusionRule.php          # Rule 1: InClassNode
│       ├── SharedEntityLeakRule.php         # Rule 2: MethodCall
│       ├── TenantIdDriftRule.php            # Rule 3: InClassNode
│       └── Helper/
│           └── AttributeHierarchyHelper.php # Shared: hierarchy walk logic
extension.neon                               # Consumer-facing: shipped extension
phpstan.neon                                 # Bundle's OWN level-9 self-analysis (UNCHANGED)

tests/
├── Unit/
│   └── PHPStan/
│       └── Rule/
│           ├── MutualExclusionRuleTest.php
│           ├── SharedEntityLeakRuleTest.php
│           ├── TenantIdDriftRuleTest.php
│           └── data/
│               ├── mutual-exclusion-violating.php
│               ├── mutual-exclusion-clean.php
│               ├── shared-entity-leak-violating.php
│               ├── shared-entity-leak-clean.php
│               ├── tenant-id-drift-violating.php
│               └── tenant-id-drift-clean.php
```

### Pattern 1: Rule Interface (PHPStan 2.x)

**What:** Implement `PHPStan\Rules\Rule<TNodeType>`. Two methods: `getNodeType()` returns the node class string; `processNode()` receives the node + scope, returns `list<IdentifierRuleError>`. [VERIFIED: phpstan.org/developing-extensions/rules + apiref.phpstan.org/2.1.x]

**CRITICAL:** In PHPStan 2.x, `processNode()` MUST return `list<IdentifierRuleError>` (not `array|string[]`). `RuleErrorBuilder` produces `IdentifierRuleError`. Returning plain strings is NOT supported in PHPStan 2.0+.

```php
// Source: https://phpstan.org/developing-extensions/rules
// Source: https://apiref.phpstan.org/2.1.x/PHPStan.Rules.Rule.html

declare(strict_types=1);

namespace Tenancy\Bundle\PHPStan\Rule;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Tenancy\Bundle\Attribute\Shared;
use Tenancy\Bundle\Attribute\TenantAware;

/**
 * @implements Rule<InClassNode>
 */
final class MutualExclusionRule implements Rule
{
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();
        $nativeReflection = $classReflection->getNativeReflection();

        $hasShared = $this->hasAttributeInHierarchy($nativeReflection, Shared::class);
        $hasTenantAware = $this->hasAttributeInHierarchy($nativeReflection, TenantAware::class);

        if (!$hasShared || !$hasTenantAware) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Entity %s cannot carry both #[Shared] and #[TenantAware]. A shared entity is a landlord-side master; a TenantAware entity is tenant-scoped.',
                $classReflection->getName()
            ))
                ->identifier('tenancy.mutualExclusion')
                ->build(),
        ];
    }

    /** @param \ReflectionClass<object> $rc */
    private function hasAttributeInHierarchy(\ReflectionClass $rc, string $attribute): bool
    {
        for ($current = $rc; $current !== false; $current = $current->getParentClass()) {
            if ([] !== $current->getAttributes($attribute)) {
                return true;
            }
        }
        return false;
    }
}
```

### Pattern 2: extension.neon — Parameter Schema + Rule Registration

**What:** Single shipped neon file consumers include (or extension-installer auto-loads). Declares custom parameters in `parametersSchema`, defaults in `parameters`, and registers rules via `services:` with `tags: [phpstan.rules.rule]` or `conditionalTags:`. [VERIFIED: phpstan.org/developing-extensions/dependency-injection-configuration]

```neon
# extension.neon (shipped at package root — consumed by phpstan/extension-installer)

parametersSchema:
    tenancy: structure([
        checkSharedEntityLeaks: bool()
    ])

parameters:
    tenancy:
        checkSharedEntityLeaks: true

services:
    -
        class: Tenancy\Bundle\PHPStan\Rule\MutualExclusionRule
        tags:
            - phpstan.rules.rule

    -
        class: Tenancy\Bundle\PHPStan\Rule\TenantIdDriftRule
        tags:
            - phpstan.rules.rule

    -
        class: Tenancy\Bundle\PHPStan\Rule\SharedEntityLeakRule
        arguments:
            checkSharedEntityLeaks: %tenancy.checkSharedEntityLeaks%
        tags:
            - phpstan.rules.rule
```

### Pattern 3: InClassNode for Class-Level Attribute Rules (Rules 1 and 3)

**What:** `PHPStan\Node\InClassNode` is PHPStan's virtual node for class-level analysis. Unlike `PhpParser\Node\Stmt\Class_`, `InClassNode` guarantees `$scope->getClassReflection()` returns the populated `ClassReflection`. [VERIFIED: phpstan-src/Rules/Classes/ClassAttributesRule.php at 2.1.x]

- `$node->getClassReflection()` — returns `ClassReflection`
- `$classReflection->getNativeReflection()` — returns `\ReflectionClass` for native attribute access
- `$nativeReflection->getAttributes(AttributeFQCN::class)` — checks attribute presence on THIS class only (NOT inherited)
- `$nativeReflection->getParentClass()` — returns `\ReflectionClass|false` for hierarchy walk

**The hierarchy walk pattern** (identical to `SharedEntityMutualExclusionPass::hasAttributeInHierarchy()`) is needed because PHP class attributes are NOT inherited — a `#[Shared]` on a `MappedSuperclass` parent is invisible from a child's `getAttributes()`. Rules 1 and 3 both need this walk.

### Pattern 4: MethodCall Node for Rule 2

**What:** `PhpParser\Node\Expr\MethodCall` fires on every method call. For Rule 2, filter to calls on EntityManager-typed callers where the method is `find`, `getRepository`, etc. [ASSUMED — training knowledge; verified pattern from phpstan-doctrine source review]

```php
// @implements Rule<\PhpParser\Node\Expr\MethodCall>
public function getNodeType(): string
{
    return \PhpParser\Node\Expr\MethodCall::class;
}

public function processNode(Node $node, Scope $scope): array
{
    // 1. Check if caller is an EntityManager (NOT a landlord EM)
    $callerType = $scope->getType($node->var);
    // ObjectType check: is it EntityManagerInterface but NOT the landlord EM?
    // The landlord EM is distinguishable by the service name in the container,
    // but at PHPStan analysis time only the TYPE is visible. Conservative approach:
    // only fire when we can see it's the default/tenant EM (ObjectType with known class).

    // 2. Extract entity class from first argument (::class constant expression)
    // 3. Check if entity class has #[Shared] in hierarchy

    // D-03: stay silent on ambiguous EntityManagerInterface — fire only on confirmed tenant EM type
}
```

**IMPORTANT PITFALL for Rule 2:** The landlord EM and the default EM have the same PHP type (`EntityManagerInterface`). PHPStan's type system cannot distinguish them at analysis time from the type alone — it cannot see which *service* was injected. Conservative behavior: fire only when:
1. The caller is a concrete `EntityManager` type (not `EntityManagerInterface`), AND
2. The argument is a literal `::class` constant resolving to a `#[Shared]` entity.

In practice this means Rule 2 will only catch clear cases: direct injection of the default EM, not cases where the EM comes through an interface. This is the precision-first approach mandated by D-03.

### Pattern 5: ObjectMetadataResolver (D-02 "present" path)

**What:** When `phpstan/phpstan-doctrine` is installed, its `PHPStan\Type\Doctrine\ObjectMetadataResolver` is available as a PHPStan service. Rules can accept it as a constructor dependency. [MEDIUM confidence — phpstan-doctrine 2.0.x source reviewed, no stable release yet]

```php
// In TenantIdDriftRule constructor (D-02 "present" path):
public function __construct(
    // Optional: only injected if phpstan-doctrine is available
    ?ObjectMetadataResolver $objectMetadataResolver = null,
)

// Usage in processNode():
$metadata = $this->objectMetadataResolver?->getClassMetadata($className);
if ($metadata !== null) {
    // Use real Doctrine ClassMetadata:
    // $metadata->fieldMappings has all mapped fields
    // Check if 'tenant_id' key exists (by column name, not property name)
    // Check fieldMappings['tenant_id']['nullable']
    // Check fieldMappings['tenant_id']['type'] for string types
} else {
    // Reflection fallback (D-02 "absent" path):
    // Walk class hierarchy via getNativeReflection()
    // Find property with #[ORM\Column(name: 'tenant_id')] or #[ORM\Column] with property name 'tenantId'
    // Read 'nullable' and 'type' arguments from attribute
}
```

**Service injection in neon (D-02 present path — conditional):**
The extension.neon cannot conditionally inject `ObjectMetadataResolver` based on whether phpstan-doctrine is installed. The recommended approach: use constructor with `?ObjectMetadataResolver` nullable default, OR use a separate rule class registered only when phpstan-doctrine is available. The simpler pattern: always accept `?ObjectMetadataResolver` with null default; the service container will inject it when available. [ASSUMED — needs verification during implementation]

### Pattern 6: RuleTestCase Harness

**What:** Tests extend `PHPStan\Testing\RuleTestCase<TRule>`. Override `getRule()` to return the rule; call `analyse()` with fixture file paths and expected `[message, line]` pairs. Override `getAdditionalConfigFiles()` (static) to load `extension.neon` so the parameter schema is available. [VERIFIED: apiref.phpstan.org/2.1.x/PHPStan.Testing.RuleTestCase.html]

```php
/**
 * @extends RuleTestCase<MutualExclusionRule>
 */
final class MutualExclusionRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new MutualExclusionRule();
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../../../extension.neon'];
    }

    public function testMutualExclusionViolation(): void
    {
        $this->analyse(
            [__DIR__ . '/data/mutual-exclusion-violating.php'],
            [
                [
                    'Entity Fixtures\BothAttributesEntity cannot carry both #[Shared] and #[TenantAware].',
                    // line number of the class declaration in the fixture file
                    10,
                ],
            ]
        );
    }

    public function testNoViolationWhenOnlyOneAttribute(): void
    {
        $this->analyse([__DIR__ . '/data/mutual-exclusion-clean.php'], []);
    }
}
```

### Pattern 7: composer.json Extension Registration

**What:** Set `"type": "phpstan-extension"` and declare `extra.phpstan.includes` pointing to the shipped neon. [VERIFIED: phpstan-doctrine composer.json 2.0.x + github.com/phpstan/extension-installer README]

```json
{
    "type": "phpstan-extension",
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
}
```

**NOTE:** The bundle is `"type": "symfony-bundle"` today. Adding `"phpstan-extension"` type would change the Composer type. This matters: `phpstan/extension-installer` discovers packages of type `phpstan-extension` OR packages with `extra.phpstan.includes` in their `composer.json`. Check extension-installer behavior — it may detect by the `extra.phpstan.includes` key regardless of type. [ASSUMED — needs verification; phpstan-symfony uses `"type": "phpstan-extension"` but is a dedicated PHPStan package, not a Symfony bundle. The tenancy bundle is both.]

**Safer approach:** Keep `"type": "symfony-bundle"` and rely on `extra.phpstan.includes` alone. Extension-installer 1.4.x detects by `extra.phpstan.includes` presence, not strictly by type. [MEDIUM confidence]

### Anti-Patterns to Avoid

- **Using `PhpParser\Node\Stmt\Class_` instead of `InClassNode`:** `Class_` node does not guarantee `$scope->getClassReflection()` is populated. Use `InClassNode` for all class-level rules.
- **Returning plain strings from `processNode()`:** PHPStan 2.0 enforces `list<IdentifierRuleError>` return. Always use `RuleErrorBuilder::message()->identifier()->build()`.
- **Missing `->identifier()` on `RuleErrorBuilder`:** PHPStan 2.x hard-enforces identifiers; omitting them causes a fatal error.
- **Calling `getAttributes()` only on the leaf class:** PHP class attributes are NOT inherited. Always walk ancestors via `getParentClass()` loop. This is the key lesson from `SharedEntityMutualExclusionPass::hasAttributeInHierarchy()`.
- **Registering rules in `phpstan.neon` (bundle's own self-analysis) instead of `extension.neon`:** The bundle's `phpstan.neon` analyses `src` at level 9 for the bundle itself. The shipped `extension.neon` is what consumers include. Keep them separate.
- **Hard-requiring phpstan-doctrine:** Breaks the bundle's optional-dependency pattern. Use `?ObjectMetadataResolver` or `class_exists()` guard.
- **Inventing `setEntityManager()` API:** The acceptance text's `setEntityManager('landlord')` is illustrative. It does not exist. Rule 2's safe signal is the type of EM in the call chain.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Error building with identifier | Custom error array structure | `RuleErrorBuilder::message()->identifier()->build()` | PHPStan 2.x requires `IdentifierRuleError`; raw arrays removed |
| Custom test harness for PHPStan rules | Custom `TestCase` + phpstan API calls | `PHPStan\Testing\RuleTestCase` | Standard harness handles DI container, scope resolution, fixture loading |
| Custom neon parsing for extension.neon | Manual neon reader | PHPStan's built-in neon includes + `parametersSchema:` | schema validation is built in; consumers can override with one line |
| Parameter toggle mechanism | Custom `is_enabled.json` or env var | `parametersSchema: + parameters:` defaults in neon + constructor injection via `%tenancy.checkSharedEntityLeaks%` | Built-in PHPStan mechanism; consumers silence with one line in their phpstan.neon |
| Class hierarchy attribute detection | Custom SPL traversal | `getNativeReflection()->getParentClass()` loop (mirrors `SharedEntityMutualExclusionPass::hasAttributeInHierarchy()`) | Established pattern in the bundle; PHP reflection API is the right tool |

**Key insight:** PHPStan has a complete, stable ecosystem for custom rules. The harness, neon configuration, parameter injection, and test infrastructure are all provided. The only bespoke logic is the tenant domain: attribute recognition, hierarchy walk, EM type identification.

---

## Common Pitfalls

### Pitfall 1: PHPStan 2.x Return Type Enforcement
**What goes wrong:** `processNode()` returns a plain string or `array<string>`. PHPStan 2.x fails with a type error.
**Why it happens:** PHPStan 1.x accepted plain strings; 2.0 made `IdentifierRuleError` a native requirement.
**How to avoid:** Always use `RuleErrorBuilder::message('...')->identifier('tenancy.xxx')->build()`. The return type annotation `@return list<IdentifierRuleError>` documents the constraint; the actual PHP return type should be `array` per the interface.
**Warning signs:** `Return type of ... must be ... list<IdentifierRuleError>` errors during PHPStan self-analysis.

### Pitfall 2: Attribute Non-Inheritance
**What goes wrong:** Rule only checks the leaf class. A `#[Shared]` on an abstract `MappedSuperclass` parent is invisible; the child entity passes the check despite having an inherited `#[Shared]`.
**Why it happens:** `ReflectionClass::getAttributes()` and `ClassReflection::getAttributes()` only return attributes declared DIRECTLY on the class being reflected.
**How to avoid:** Always walk `getNativeReflection()->getParentClass()` in a loop. Reference `SharedEntityMutualExclusionPass::hasAttributeInHierarchy()` as the established implementation.
**Warning signs:** Tests using inheritance fixtures fail to catch violations.

### Pitfall 3: phpstan-doctrine Version Mismatch
**What goes wrong:** `phpstan/phpstan-doctrine` 2.0.x-dev requires `phpstan/phpstan ^2.2.2`, but the bundle has `^2.1` in require-dev. Composer resolves to the 1.5.x branch which requires PHPStan ^1.x — a hard conflict.
**Why it happens:** phpstan-doctrine 2.0.x is dev-only; no stable tag exists as of 2026-06-16.
**How to avoid:** Either (a) bump `phpstan/phpstan` require-dev to `^2.2` to satisfy phpstan-doctrine 2.0.x-dev, OR (b) skip phpstan-doctrine from require-dev and test only the reflection fallback path in CI, documenting the phpstan-doctrine path as tested manually.
**Warning signs:** `composer require --dev phpstan/phpstan-doctrine:"^2.0@dev"` fails with conflict.

### Pitfall 4: extension.neon Naming Collision
**What goes wrong:** PHPStan's neon `includes:` stacks all loaded neons. If the bundle's `phpstan.neon` accidentally includes `extension.neon`, the consumer rules run on the bundle's own `src` during CI self-analysis with inappropriate context.
**Why it happens:** Lazy copy-paste of the neon path.
**How to avoid:** The bundle's `phpstan.neon` must NOT include `extension.neon`. Keep them fully separate. Optionally add a dogfooding step that explicitly includes the extension neon with test fixtures as the analysis target.
**Warning signs:** Level-9 errors from rule fixtures appearing in the phpstan job output.

### Pitfall 5: Rule 2 False Positives from EntityManagerInterface
**What goes wrong:** Rule 2 fires on every `$em->find()` call where `$em` is typed as `EntityManagerInterface`, producing false positives in code where the developer has correctly used the landlord EM.
**Why it happens:** PHPStan cannot distinguish landlord EM from tenant EM purely from the type — both are `EntityManagerInterface`.
**How to avoid:** D-03 mandates conservative/precision-first: fire only on cases where the tenant EM use is unambiguous. If the EM source is ambiguous (typed as interface, received via injection), stay silent. Document this conservative behavior in the rule's docblock.
**Warning signs:** High false-positive rate in consumer CI leads to `tenancy.checkSharedEntityLeaks: false` being the default response.

### Pitfall 6: Rule 3 Column Name vs Property Name Confusion
**What goes wrong:** Rule 3 checks the mapped column name `tenant_id` but reads the PHP property name. In Doctrine attribute mapping, `#[ORM\Column]` without an explicit `name:` argument uses the camelCase-to-underscore-converted property name. A property named `$tenantId` maps to column `tenant_id` by default — but `$tenant_id` as a property name is also valid.
**Why it happens:** The filter hardcodes column `tenant_id`; Doctrine maps by column name, not property name. The reflection fallback must match on the COLUMN name, not the property name.
**How to avoid:** When using the reflection fallback, check both: (a) properties with explicit `#[ORM\Column(name: 'tenant_id')]` AND (b) properties named `tenant_id` or `tenantId` with no explicit column name (relying on Doctrine's naming strategy). Document the limitation.
**Warning signs:** `#[ORM\Column]` with no explicit name passes rule 3 when the column is actually `tenant_id` via Doctrine's naming convention.

---

## Code Examples

### Example 1: Rule 1 — Mutual Exclusion (InClassNode, hierarchy walk)
```php
// Source: verified pattern from https://github.com/phpstan/phpstan-src/blob/2.1.x/src/Rules/Classes/ClassAttributesRule.php
// + https://apiref.phpstan.org/2.1.x/PHPStan.Rules.Rule.html

/**
 * @implements Rule<InClassNode>
 */
final class MutualExclusionRule implements Rule
{
    public function getNodeType(): string
    {
        return InClassNode::class; // PHPStan\Node\InClassNode
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();
        $nativeReflection = $classReflection->getNativeReflection();

        if (!$this->hasAttributeInHierarchy($nativeReflection, Shared::class)) {
            return [];
        }
        if (!$this->hasAttributeInHierarchy($nativeReflection, TenantAware::class)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Entity %s cannot carry both #[Shared] and #[TenantAware]. Pick one.',
                $classReflection->getName()
            ))
                ->identifier('tenancy.mutualExclusion')
                ->build(),
        ];
    }

    /** @param \ReflectionClass<object> $rc */
    private function hasAttributeInHierarchy(\ReflectionClass $rc, string $attribute): bool
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

### Example 2: Rule 3 — TenantId Drift (reflection fallback path)
```php
// Source: reflection fallback when phpstan-doctrine absent [ASSUMED — training knowledge]

// In processNode(), after confirming #[TenantAware] in hierarchy:
$nativeReflection = $classReflection->getNativeReflection();
$foundColumn = null;

foreach ($nativeReflection->getProperties() as $property) {
    foreach ($property->getAttributes(\Doctrine\ORM\Mapping\Column::class) as $attr) {
        $args = $attr->getArguments();
        $colName = $args['name'] ?? $args[0] ?? null;
        // Default Doctrine column name: property name (snake_case from camelCase)
        if ($colName === null) {
            $colName = $property->getName(); // naive; real Doctrine uses NamingStrategy
        }
        if ($colName === 'tenant_id') {
            $foundColumn = ['nullable' => $args['nullable'] ?? false, 'type' => $args['type'] ?? null];
            break 2;
        }
    }
}

if ($foundColumn === null) {
    // No tenant_id column found
    return [RuleErrorBuilder::message(...)->identifier('tenancy.tenantIdDrift')->build()];
}
if ($foundColumn['nullable'] === true) {
    // Column is nullable
    return [RuleErrorBuilder::message(...)->identifier('tenancy.tenantIdDrift')->build()];
}
// Check type is string
$stringTypes = ['string', 'ascii_string', 'guid', 'uuid', Types::STRING, Types::ASCII_STRING];
if ($foundColumn['type'] !== null && !in_array($foundColumn['type'], $stringTypes, true)) {
    return [RuleErrorBuilder::message(...)->identifier('tenancy.tenantIdDrift')->build()];
}
return [];
```

### Example 3: parametersSchema + parameter toggle for Rule 2
```neon
# Source: https://phpstan.org/developing-extensions/dependency-injection-configuration

parametersSchema:
    tenancy: structure([
        checkSharedEntityLeaks: bool()
    ])

parameters:
    tenancy:
        checkSharedEntityLeaks: true

services:
    -
        class: Tenancy\Bundle\PHPStan\Rule\SharedEntityLeakRule
        arguments:
            checkSharedEntityLeaks: %tenancy.checkSharedEntityLeaks%
        tags:
            - phpstan.rules.rule
```

Consumer opt-out (in their `phpstan.neon`):
```neon
parameters:
    tenancy:
        checkSharedEntityLeaks: false
```

### Example 4: RuleTestCase fixture file pattern
```php
// tests/Unit/PHPStan/Rule/data/mutual-exclusion-violating.php
<?php
declare(strict_types=1);
namespace Fixtures\PHPStan;

use Tenancy\Bundle\Attribute\Shared;
use Tenancy\Bundle\Attribute\TenantAware;

#[Shared]
#[TenantAware]
class BothAttributesViolating {} // Rule should fire here

#[Shared]
class OnlySharedClean {}         // No violation

#[TenantAware]
class OnlyTenantAwareClean {}    // No violation
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| PHPStan rules return plain `string[]` | Rules return `list<IdentifierRuleError>` via `RuleErrorBuilder::message()->identifier()->build()` | PHPStan 2.0 (stable 2024) | Hard-enforced; returning strings is a fatal error in 2.x |
| `phpstan.rules.rule` tag required | Both `tags:` and `rules:` shorthand work | PHPStan 1.x+ | `rules:` shorthand for simple rules; `services:` + `tags:` needed when constructor args required |
| `PhpParser\Node\Stmt\Class_` for class rules | `PHPStan\Node\InClassNode` virtual node | PHPStan 1.8+ | `InClassNode` provides `getClassReflection()` on the node directly; `Class_` node lacks this |
| phpstan-doctrine 1.x (PHPStan ^1.x) | phpstan-doctrine 2.0.x-dev (PHPStan ^2.2.2) | 2026-06 | 2.0.x still dev-only; no stable tag |

**Deprecated/outdated:**
- Returning `array<string|RuleError>` from `processNode()`: removed in PHPStan 2.0. All errors must use `RuleErrorBuilder`.
- `RuleError` without identifier: PHPStan 2.x requires `.identifier()` on every error.

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `"type": "symfony-bundle"` still allows extension-installer detection via `extra.phpstan.includes` alone (not requiring `"type": "phpstan-extension"`) | Architecture Patterns §Pattern 7 | If wrong: must change composer.json type field to `phpstan-extension` OR register manually; medium impact |
| A2 | `?ObjectMetadataResolver` null-default constructor injection works in PHPStan's DI container to handle optional phpstan-doctrine dependency | Architecture Patterns §Pattern 5 | If wrong: must use a wrapper class or conditional neon service registration; medium impact |
| A3 | phpstan-doctrine 2.0.x-dev `ObjectMetadataResolver::getClassMetadata()` returns null gracefully when `objectManagerLoader` is not configured (no fatal error) | Architecture Patterns §Pattern 5 | If wrong: must add explicit null check / try-catch around the call; low-medium impact |
| A4 | `fieldMappings` array on Doctrine 3.x `ClassMetadata` uses `'nullable'` and `'type'` keys (as in Doctrine 2.x) | Code Examples §Example 2 | If wrong: Doctrine 3.x may use different key names in `fieldMappings`; medium impact |
| A5 | The Doctrine naming convention (camelCase → snake_case) is NOT applied automatically in the reflection fallback — a `$tenantId` property with no explicit `name:` argument maps to `tenant_id` but our fallback may not find it without a naming-strategy lookup | Pitfall 6 | If wrong: Rule 3 has false negatives for the common `$tenantId` property name; medium-high impact |
| A6 | phpstan/extension-installer 1.4.3 (already require-dev absent, need to add) is the correct version constraint for PHPStan 2.1.50 | Package Legitimacy Audit | If wrong: version conflict on consumer install; low impact (can adjust constraint) |

---

## Open Questions

1. **phpstan-doctrine version conflict (`^2.2.2` vs `^2.1` installed)**
   - What we know: phpstan-doctrine 2.0.x-dev requires `phpstan/phpstan: ^2.2.2`; bundle has `^2.1` installed (2.1.50)
   - What's unclear: Can D-02's "present" path be tested in CI without bumping phpstan require-dev to ^2.2?
   - Recommendation: Bump `phpstan/phpstan` require-dev to `^2.1` (current) OR `^2.2`, whichever resolves. If phpstan-doctrine 2.0.x-dev won't install, test only the reflection fallback path in CI and document phpstan-doctrine path as "tested with phpstan-doctrine ^2.0.x-dev when available."

2. **composer.json `type` field**
   - What we know: phpstan-doctrine sets `"type": "phpstan-extension"`; the bundle is `"type": "symfony-bundle"`
   - What's unclear: Whether extension-installer 1.4.x requires the type OR just detects by `extra.phpstan.includes`
   - Recommendation: Research extension-installer source briefly during Wave 0; changing type to `phpstan-extension` breaks the Symfony bundle discovery mechanism (Symfony uses `type: symfony-bundle` for bundle detection). Most likely keep `symfony-bundle` type and rely on `extra.phpstan.includes` only.

3. **Rule 2 practical detectability**
   - What we know: PHPStan cannot distinguish landlord EM from tenant EM by type alone
   - What's unclear: Is there a realistic case where Rule 2 fires on unambiguous code in a typical Symfony app?
   - Recommendation: Conservative implementation is correct per D-03. The rule fires when: a method named `find`/`getRepository` is called on an object of concrete type `Doctrine\ORM\EntityManager` (not interface) AND the argument is `SomeSharedEntity::class`. Document limitation.

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | All code | ✓ | 8.5.6 (dev) | — |
| phpstan/phpstan | Self-analysis + rule implementation | ✓ | 2.1.50 | — |
| nikic/php-parser | AST node types | ✓ | ^5.0 (in require) | — |
| phpstan/extension-installer | Test D-01 auto-load path | ✗ | — | Manual includes: in test neon |
| phpstan/phpstan-doctrine | Test D-02 "present" path | ✗ | — | Test reflection-fallback path only |
| PHPUnit 11 | Rule tests via RuleTestCase | ✓ | ^11.0 (require-dev) | — |

**Missing dependencies with no fallback:** none that block execution

**Missing dependencies with fallback:**
- `phpstan/extension-installer`: Not in require-dev. `RuleTestCase::getAdditionalConfigFiles()` can manually include `extension.neon` in tests — the auto-load behavior is tested by verifying the neon is correctly structured (integration test of the neon wiring itself is optional).
- `phpstan/phpstan-doctrine`: Not in require-dev. D-02 "present" path untestable in CI without it. Plan must include a Wave 0 task to add it or scope the CI coverage to the fallback path only.

---

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11 (phpunit/phpunit ^11.0) via `PHPStan\Testing\RuleTestCase` |
| Config file | `phpunit.xml.dist` (existing) |
| Quick run command | `vendor/bin/phpunit tests/Unit/PHPStan --no-coverage` |
| Full suite command | `vendor/bin/phpunit` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| DX-03-AC1 (Rule 1) | Mutual exclusion fires on `#[Shared]` + `#[TenantAware]` co-present | unit (RuleTestCase) | `vendor/bin/phpunit tests/Unit/PHPStan/Rule/MutualExclusionRuleTest.php` | ❌ Wave 0 |
| DX-03-AC1 hierarchy | Rule 1 fires when attribute is on parent class | unit (RuleTestCase) | same | ❌ Wave 0 |
| DX-03-AC1 clean | Rule 1 silent when only one attribute present | unit (RuleTestCase) | same | ❌ Wave 0 |
| DX-03-AC2 (Rule 2) | Leak rule fires on tenant EM querying `#[Shared]` entity | unit (RuleTestCase) | `vendor/bin/phpunit tests/Unit/PHPStan/Rule/SharedEntityLeakRuleTest.php` | ❌ Wave 0 |
| DX-03-AC2 gated | Leak rule silent when `tenancy.checkSharedEntityLeaks: false` | unit (RuleTestCase) | same | ❌ Wave 0 |
| DX-03-AC3 (Rule 3) | Drift rule fires when no `tenant_id` column | unit (RuleTestCase) | `vendor/bin/phpunit tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php` | ❌ Wave 0 |
| DX-03-AC3 nullable | Drift rule fires when `tenant_id` is nullable | unit (RuleTestCase) | same | ❌ Wave 0 |
| DX-03-AC3 type | Drift rule fires when `tenant_id` is non-string type | unit (RuleTestCase) | same | ❌ Wave 0 |
| DX-03-AC4 | extension.neon loadable by `getAdditionalConfigFiles()` | unit (neon structure) | `vendor/bin/phpunit` (all rule tests load neon) | ❌ Wave 0 |
| DX-03-AC5 | Error message names file + line + violation kind | unit (RuleTestCase message assertions) | same | ❌ Wave 0 |
| Level-9 self-analysis | Bundle's own `phpstan analyse` still passes after adding `src/PHPStan/` | static analysis | `vendor/bin/phpstan analyse` | ✅ (existing CI job) |

### Sampling Rate
- **Per task commit:** `vendor/bin/phpunit tests/Unit/PHPStan --no-coverage`
- **Per wave merge:** `vendor/bin/phpunit && vendor/bin/phpstan analyse`
- **Phase gate:** Full suite green + `vendor/bin/phpstan analyse` clean before `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Unit/PHPStan/Rule/MutualExclusionRuleTest.php` — covers DX-03-AC1
- [ ] `tests/Unit/PHPStan/Rule/SharedEntityLeakRuleTest.php` — covers DX-03-AC2
- [ ] `tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php` — covers DX-03-AC3
- [ ] `tests/Unit/PHPStan/Rule/data/` directory with 6 fixture files (violating + clean per rule)
- [ ] `extension.neon` at package root (new file)
- [ ] `src/PHPStan/Rule/` directory with 3 rule classes + optional `Helper/AttributeHierarchyHelper.php`
- [ ] Add to `phpunit.xml.dist` or confirm `tests/Unit/PHPStan` is already covered by `<directory>tests/Unit</directory>` — YES, it is (existing `tests/Unit` glob covers it)

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no | — |
| V3 Session Management | no | — |
| V4 Access Control | no | — |
| V5 Input Validation | no | PHPStan rules operate at analysis time; no runtime input |
| V6 Cryptography | no | — |

**Note:** This phase is a developer-experience (DX) addition. The security value is INDIRECT: the rules catch cross-tenant data-leak misuse patterns (`#[Shared]` queried via tenant EM) before they reach production. The rules themselves have no runtime security surface.

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Disabled Rule 2 (`checkSharedEntityLeaks: false`) leaves no static guard | Elevation of Privilege (via data-leak) | Documented default-true + runtime write-protection (Phase 25 D-02) as backstop |
| Rule 3 false negatives (reflection fallback misses XML-mapped entities) | Tampering | Install phpstan-doctrine for full coverage; documented limitation |

---

## Sources

### Primary (HIGH confidence)
- [PHPStan Custom Rules docs](https://phpstan.org/developing-extensions/rules) — Rule interface, RuleErrorBuilder, registration patterns
- [PHPStan Rule Interface APIref 2.1.x](https://apiref.phpstan.org/2.1.x/PHPStan.Rules.Rule.html) — `processNode()` return type `list<IdentifierRuleError>`, generics signature
- [PHPStan RuleTestCase APIref 2.1.x](https://apiref.phpstan.org/2.1.x/PHPStan.Testing.RuleTestCase.html) — `analyse()`, `getAdditionalConfigFiles()`, `getRule()` signatures
- [PHPStan Dependency Injection docs](https://phpstan.org/developing-extensions/dependency-injection-configuration) — `parametersSchema:`, `%parameter%` injection
- [phpstan-src ClassAttributesRule 2.1.x](https://github.com/phpstan/phpstan-src/blob/2.1.x/src/Rules/Classes/ClassAttributesRule.php) — `InClassNode` usage, `getNativeReflection()->getAttributes()` pattern
- [phpstan/extension-installer Packagist](https://packagist.org/packages/phpstan/extension-installer) — 1.4.3 latest stable, PHPStan ^2.0 support
- [phpstan/phpstan-doctrine Packagist](https://packagist.org/packages/phpstan/phpstan-doctrine) — 2.0.x-dev only (no stable 2.x tag), requires phpstan ^2.2.2
- PHPStan 2.1.50 installed in vendor — confirmed running version

### Secondary (MEDIUM confidence)
- [phpstan-doctrine EntityColumnRule 2.0.x](https://github.com/phpstan/phpstan-doctrine/blob/2.0.x/src/Rules/Doctrine/ORM/EntityColumnRule.php) — `ObjectMetadataResolver` constructor injection, `getClassMetadata()`, `fieldMappings` access pattern
- [extension-installer README 1.2.x](https://github.com/phpstan/extension-installer/blob/1.2.x/README.md) — `extra.phpstan.includes` format, auto-registration mechanism
- [phpstan-doctrine composer.json 2.0.x](https://github.com/phpstan/phpstan-doctrine/blob/2.0.x/composer.json) — `"type": "phpstan-extension"`, `extra.phpstan.includes` with multiple neons

### Tertiary (LOW confidence)
- Web search for `getType($node->var)` + `ObjectType` pattern for MethodCall rules — training knowledge + community examples, not official API docs
- phpstan-doctrine `ObjectMetadataResolver` null-return behavior when no objectManagerLoader configured — inferred from issue comments, not official docs

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — PHPStan 2.1.50 installed in vendor; Packagist versions confirmed for both required packages
- Rule interface & patterns: HIGH — verified against official docs + phpstan-src source
- phpstan-doctrine integration (D-02): MEDIUM — 2.0.x is dev-only; ObjectMetadataResolver internals inferred from source review, not stable API docs
- Architecture: HIGH — mirrors established phpstan-symfony/phpstan-doctrine patterns
- Pitfalls: HIGH — attribute non-inheritance confirmed from `SharedEntityMutualExclusionPass` source; PHPStan 2.x return type confirmed from API ref

**Research date:** 2026-06-16
**Valid until:** 2026-07-16 (PHPStan releases frequently; phpstan-doctrine 2.0 may hit stable before then — re-verify version constraints)
