---
phase: 28-phpstan-extension
verified: 2026-06-16T00:00:00Z
status: gaps_found
score: 9/12 must-haves verified
overrides_applied: 0
gaps:
  - truth: "D-02 soft-integrate ObjectMetadataResolver path actually works (checkViaMetadata() produces correct results on Doctrine ORM 3.x)"
    status: failed
    reason: "checkViaMetadata() uses is_array() to test FieldMapping entries; on Doctrine ORM 3.x (3.6.3 installed) every entry is a FieldMapping OBJECT (implements ArrayAccess, not array). is_array() returns false, every iteration continues, $found stays null, evaluateFinding(null) fires 'no column mapped to tenant_id' on EVERY valid #[TenantAware] entity — 100% false-positive rate when the resolver path is reached. Confirmed empirically."
    artifacts:
      - path: "src/PHPStan/Rule/TenantIdDriftRule.php"
        issue: "checkViaMetadata() lines 124-126: 'if (!is_array($mapping)) { continue; }' — always continues on ORM 3.x FieldMapping objects"
    missing:
      - "Replace is_array($mapping) check with 'is_array($mapping) || $mapping instanceof ArrayAccess' to accept ORM 3.x FieldMapping objects"
      - "Alternatively access $metadata->fieldMappings directly (public property, array<string, FieldMapping>) and use $fm->columnName / $fm->nullable / $fm->type property access"
      - "Add a RuleTestCase test that constructs TenantIdDriftRule WITH a real ObjectMetadataResolver and a mapped entity fixture, so this path is exercised in CI"

  - truth: "D-02 ObjectMetadataResolver is injected via extension.neon when phpstan/phpstan-doctrine is installed"
    status: failed
    reason: "extension.neon registers TenantIdDriftRule with no arguments. PHPStan's DI container cannot autowire a parameter typed '?object' to the concrete ObjectMetadataResolver service. Even with phpstan-doctrine installed, $this->objectMetadataResolver is always null — the checkViaMetadata() path is never reached. The advertised 'XML/YAML-mapped entity analysis' (composer.json suggest line 61) silently does nothing. Currently the only thing shielding consumers from CR-01 is WR-01 — the feature is non-functional rather than safe by design."
    artifacts:
      - path: "extension.neon"
        issue: "TenantIdDriftRule service entry has no arguments block — objectMetadataResolver is never injected"
    missing:
      - "Fix CR-01 first (is_array check), then wire the resolver conditionally in a phpstan-doctrine-present neon fragment referenced from extension.neon, or use the concrete type hint '@PHPStan\\Type\\Doctrine\\ObjectMetadataResolver' with appropriate conditional loading"

  - truth: "#[TenantAware] on a MappedSuperclass base class does not produce a false positive (WR-02: legitimate pattern where subclasses declare the tenant_id column)"
    status: failed
    reason: "TenantIdDriftRule has no MappedSuperclass/abstract exemption. The test fixture TenantAwareParent is #[ORM\\MappedSuperclass] with #[TenantAware] and no tenant_id column — the rule fires on the parent itself (codified as expected behavior in testFiresOnInheritedTenantAware). A real consumer placing #[TenantAware] on a MappedSuperclass and declaring the tenant_id column in concrete subclasses (a valid, common Doctrine pattern) will receive a permanent false error on the base class. This is not a security-direction miss (false negative) but it trains users to suppress/ignore the rule."
    artifacts:
      - path: "src/PHPStan/Rule/TenantIdDriftRule.php"
        issue: "processNode() has no check for abstract class or #[ORM\\MappedSuperclass] before evaluating the column — fires on MappedSuperclass bases that legitimately defer tenant_id to concrete subclasses"
      - path: "tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php"
        issue: "testFiresOnInheritedTenantAware() asserts the rule fires on TenantAwareParent (#[ORM\\MappedSuperclass]) — the test codifies a false-positive as expected behavior"
    missing:
      - "Skip classes that are abstract OR carry #[ORM\\MappedSuperclass] when no tenant_id is found in their own hierarchy — require tenant_id only on concrete, instantiable entities"
      - "Update testFiresOnInheritedTenantAware() to assert the parent is SILENT while the concrete child (without a tenant_id column) still fires"
---

# Phase 28: PHPStan Extension Verification Report

**Phase Goal:** Ship a consumer-facing PHPStan extension with three rules that catch `#[TenantAware]`/`#[Shared]` misuse at static-analysis time before it becomes a runtime cross-tenant data leak.
**Verified:** 2026-06-16
**Status:** gaps_found
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Rule 1 fires when both `#[Shared]` and `#[TenantAware]` on same class (`tenancy.mutualExclusion`) | VERIFIED | `MutualExclusionRule.php` processNode() returns error with identifier; `testMutualExclusionViolation()` green |
| 2 | Rule 1 is hierarchy-aware (fires when marker on parent class) | VERIFIED | `hasAttributeInHierarchy()` walks getParentClass(); `testFiresOnInheritedAttribute()` green |
| 3 | Rule 1 is zero-config via extension-installer (`DEC-PHPSTAN-01`) | VERIFIED | `composer.json extra.phpstan.includes: ["extension.neon"]`; `allow-plugins: phpstan/extension-installer: true`; extension-installer in require-dev |
| 4 | Rule 2 fires when concrete `EntityManager` queries `#[Shared]` entity (`tenancy.sharedEntityLeak`) | VERIFIED | `SharedEntityLeakRule.php` fires on concrete `Doctrine\ORM\EntityManager` caller; `testFiresOnConcreteEntityManagerQueryingShared()` green |
| 5 | Rule 2 is gated by `tenancy.checkSharedEntityLeaks` (D-01, default true) | VERIFIED | `extension.neon` parametersSchema + default + constructor injection; `testSilentWhenGatedOff()` green |
| 6 | Rule 2 is conservative — silent on `EntityManagerInterface` caller (D-03) | VERIFIED | Rule checks `Doctrine\ORM\EntityManager::class` exact match; `testSilentOnAmbiguousEntityManagerInterface()` green |
| 7 | Rule 3 fires when `tenant_id` column missing/nullable/non-string (`tenancy.tenantIdDrift`) | VERIFIED | All three branches in `evaluateFinding()`; `testFiresWhenTenantIdMissing/Nullable/NonString()` green |
| 8 | Rule 3 reflection fallback (primary path) works correctly | VERIFIED | `checkViaReflection()` walks `#[ORM\Column]` attributes across hierarchy; all 5 TenantIdDriftRule tests pass |
| 9 | D-02 soft-integrate: `ObjectMetadataResolver` path works on Doctrine ORM 3.x | **FAILED** | CR-01 confirmed empirically: `is_array(FieldMapping) == false` in ORM 3.x; every FieldMapping entry causes `continue` in `checkViaMetadata()`, `$found` stays null, every valid entity triggers false "missing tenant_id" error |
| 10 | D-02 wiring: `ObjectMetadataResolver` is injected when phpstan-doctrine is installed | **FAILED** | WR-01: `extension.neon` has no `arguments` for `TenantIdDriftRule`; `?object $objectMetadataResolver = null` is always null even with phpstan-doctrine installed |
| 11 | Rule 3 does not false-positive on `#[TenantAware]` MappedSuperclass bases (WR-02) | **FAILED** | No MappedSuperclass exemption; `TenantAwareParent` (#[ORM\MappedSuperclass]) fires in test; behavior codified as "expected" in test |
| 12 | Bundle level-9 self-analysis and dogfood analysis both stay green | VERIFIED | `vendor/bin/phpstan analyse` exits 0; `vendor/bin/phpstan analyse -c phpstan-extension-dogfood.neon` exits 0; `phpstan.neon` contains no `extension.neon` include |

**Score: 9/12 truths verified**

### Deferred Items

None — all identified gaps are actionable in this phase.

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `src/PHPStan/Rule/MutualExclusionRule.php` | Rule 1 — mutual exclusion | VERIFIED | `tenancy.mutualExclusion` identifier; hierarchy walk; no Doctrine dep |
| `src/PHPStan/Rule/TenantIdDriftRule.php` | Rule 3 — tenant_id drift | PARTIAL | Primary reflection path works; `checkViaMetadata()` path broken on ORM 3.x (CR-01); `objectMetadataResolver` never injected (WR-01) |
| `src/PHPStan/Rule/SharedEntityLeakRule.php` | Rule 2 — cross-EM leak | VERIFIED | Conservative D-03 implementation; gate works; `tenancy.sharedEntityLeak` identifier |
| `extension.neon` | Shipped consumer extension | PARTIAL | parametersSchema, defaults, all 3 rules registered with `phpstan.rules.rule` tag; MISSING `arguments` for `TenantIdDriftRule` (objectMetadataResolver never wired) |
| `phpstan-extension-dogfood.neon` | Dogfood config | VERIFIED | Includes `extension.neon`; level 9; paths: [src]; exits 0 |
| `composer.json` | `extra.phpstan.includes`, suggest, allow-plugins | VERIFIED | All three entries present; `type` still `symfony-bundle` |
| `.github/workflows/ci.yml` | CI dogfood step | VERIFIED | Step at line 74-75 runs dogfood; inside phpstan job |
| `tests/Unit/PHPStan/Rule/MutualExclusionRuleTest.php` | Rule 1 tests | VERIFIED | 3 test methods; all green |
| `tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php` | Rule 3 tests | PARTIAL | 5 test methods; all green — but ALL tests use `new TenantIdDriftRule()` with no resolver; checkViaMetadata() has zero test coverage |
| `tests/Unit/PHPStan/Rule/SharedEntityLeakRuleTest.php` | Rule 2 tests | VERIFIED | 3 test methods; all green |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `extension.neon` | `MutualExclusionRule` | `phpstan.rules.rule` tag | WIRED | Line 15-17 |
| `extension.neon` | `TenantIdDriftRule` | `phpstan.rules.rule` tag | PARTIAL | Tag present (line 20-22); no arguments — `objectMetadataResolver` never injected |
| `extension.neon` | `SharedEntityLeakRule` | `phpstan.rules.rule` tag + args | WIRED | Lines 24-29; `checkSharedEntityLeaks` and `reflectionProvider` wired |
| `composer.json` | `extension.neon` | `extra.phpstan.includes` | WIRED | Lines 80-84 |
| `phpstan-extension-dogfood.neon` | `extension.neon` | `includes:` | WIRED | Line 14 |
| `.github/workflows/ci.yml` | `phpstan-extension-dogfood.neon` | CI step | WIRED | Lines 74-75 |
| `TenantIdDriftRule.php` | `ObjectMetadataResolver` | `?object` injection | NOT_WIRED | Constructor accepts `?object $objectMetadataResolver = null` but extension.neon provides nothing; path dead |

### Data-Flow Trace (Level 4)

Not applicable — all three rules are static-analysis tools (not components that render runtime data). Data flow is the attribute-reflection chain from PHP source → rule → PHPStan error, which is verified by the RuleTestCase tests.

**Special trace for CR-01 / D-02 path:**

| Path | Data Source | Produces Real Data | Status |
|------|-------------|-------------------|--------|
| Rule 3 reflection fallback | `#[ORM\Column]` attrs via `\ReflectionClass::getProperties()` | Yes — correctly reads columnName/nullable/type | FLOWING |
| Rule 3 metadata path | `ObjectMetadataResolver::getClassMetadata()` → `$metadata->fieldMappings` (FieldMapping objects) | No — `is_array()` false on every ORM 3.x FieldMapping, continue skips all, `$found` stays null | DISCONNECTED (CR-01) |
| Rule 3 metadata wiring | `extension.neon` injects `objectMetadataResolver` | No — never injected; path unreachable | DISCONNECTED (WR-01) |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| All 11 PHPStan rule tests pass | `vendor/bin/phpunit tests/Unit/PHPStan/ --no-coverage` | 11/11 green | PASS |
| Full suite (757 tests) green | `vendor/bin/phpunit` | 757 tests, 2 skipped, 3201 assertions | PASS |
| Level-9 self-analysis clean | `vendor/bin/phpstan analyse` | 0 errors | PASS |
| Dogfood analysis clean | `vendor/bin/phpstan analyse -c phpstan-extension-dogfood.neon --memory-limit=512M` | 0 errors | PASS |
| CR-01 empirical confirmation | PHP one-liner: `is_array(new FieldMapping(...))` | `false` — `continue` triggered | CONFIRMED BROKEN |
| CR-01 ArrayAccess fix works | PHP one-liner: `instanceof ArrayAccess` + `$fm['columnName']` | `tenant_id` correctly returned | FIX VALIDATED |

### Probe Execution

No probe scripts declared in PLAN/SUMMARY for this phase. Step 7c: SKIPPED (no conventional probe paths found).

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|---------|
| DX-03 (overall) | 28-01/02/03/04 | PHPStan extension for `#[TenantAware]` + `#[Shared]` correctness | PARTIAL | 3 rules exist and CI is green; D-02 path broken (CR-01/WR-01); MappedSuperclass false-positive (WR-02) |
| DX-03-AC1 | 28-01 | Rule fires on `#[TenantAware]` AND `#[Shared]` (mutual exclusion) | SATISFIED | `MutualExclusionRule` + 3 tests green |
| DX-03-AC2 | 28-03 | Rule fires on tenant EM querying `#[Shared]` entity without landlord override | SATISFIED | `SharedEntityLeakRule` (conservative D-03) + 3 tests green |
| DX-03-AC3 | 28-02 | Rule fires when `tenant_id` missing OR nullable (+ non-string per D-04) | PARTIALLY SATISFIED | Reflection path correct and tested; metadata path broken (CR-01/WR-01) — consumers using XML/YAML-mapped entities cannot benefit from the advertised phpstan-doctrine integration |
| DX-03-AC4 | 28-01/04 | Ships as extension-installer auto-loaded via `extra.phpstan.includes`; opt-in snippet | SATISFIED | `composer.json` wired; `allow-plugins` set; manual fallback snippet documented in SUMMARY |
| DX-03-AC5 | 28-01/02/03 | Clear error message naming file + line + violation kind | SATISFIED | All three identifiers (`tenancy.mutualExclusion`, `tenancy.sharedEntityLeak`, `tenancy.tenantIdDrift`) with descriptive messages |
| DEC-PHPSTAN-01 | 28-01 | Distribution via extension-installer (zero-config) | SATISFIED | `extra.phpstan.includes` + `allow-plugins`; same pattern as phpstan/phpstan-symfony |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `src/PHPStan/Rule/TenantIdDriftRule.php` | 117-118 | `(array) $metadata` cast — fragile coupling to Doctrine internals; works only because `$fieldMappings` is public | INFO | IN-02 from review; survivable but brittle for future Doctrine versions |
| `src/PHPStan/Rule/TenantIdDriftRule.php` | 124-126 | `if (!is_array($mapping)) { continue; }` — always continues on ORM 3.x FieldMapping objects | BLOCKER | CR-01: 100% false-positive rate on valid entities when checkViaMetadata() is reachable |
| `src/PHPStan/Rule/TenantIdDriftRule.php` | 183-184 | `$args['nullable'] ?? false` and `$args['type']` read only named args — positional `nullable`/`type` in `#[ORM\Column]` silently missed | WARNING | WR-04: false-negative for positional-arg column declarations (security-relevant direction) |
| `extension.neon` | 19-22 | `TenantIdDriftRule` registered with no arguments | BLOCKER | WR-01: `objectMetadataResolver` always null — D-02 "present" path permanently dead code |
| `src/PHPStan/Rule/TenantIdDriftRule.php` | 49-83 | No MappedSuperclass/abstract exemption before evaluating column | WARNING | WR-02: false-positive on MappedSuperclass bases where concrete subclasses declare `tenant_id` |
| `src/PHPStan/Rule/MutualExclusionRule.php` + `TenantIdDriftRule.php` + `SharedEntityLeakRule.php` | all three | Duplicated `hasAttributeInHierarchy` loop — three copies of security-relevant traversal | INFO | IN-03: fix to one doesn't propagate to others; low immediate risk but maintenance debt |
| `tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php` | 24-26 | `new TenantIdDriftRule()` with no resolver in all 5 tests | WARNING | checkViaMetadata() has zero test coverage; CR-01 invisible to CI |

No unreferenced TBD/FIXME/XXX markers found in phase files.

### Human Verification Required

These items were identified in the PLAN as manual-only and cannot be verified programmatically:

#### 1. Extension-installer zero-config auto-load in a real consumer project

**Test:** In a scratch Symfony project, install `danplaton4/tenancy-bundle` + `phpstan/extension-installer`, run `vendor/bin/phpstan analyse --debug` and confirm the tenancy rules auto-load without any `includes:` in the consumer's `phpstan.neon`.
**Expected:** Rules auto-register; `vendor/bin/phpstan analyse` reports `tenancy.mutualExclusion` on an entity carrying both `#[Shared]` and `#[TenantAware]` without manual configuration.
**Why human:** Cannot be reproduced inside the bundle's own RuleTestCase harness (which bypasses extension-installer via `getAdditionalConfigFiles()`). Requires a real downstream consumer project.

**NOTE:** This human check is currently BLOCKED by the gaps above. A consumer following the suggested `phpstan/phpstan-doctrine` install path would encounter CR-01 (false errors on valid entities) before the extension-installer auto-load is even observable as working. Fix CR-01 and WR-01 first.

### Gaps Summary

Three gaps block goal achievement:

**Gap 1 (BLOCKER — CR-01): `checkViaMetadata()` broken for Doctrine ORM 3.x**

The D-02 "phpstan-doctrine present" code path in `TenantIdDriftRule::checkViaMetadata()` emits a false "no tenant_id column" error on every valid `#[TenantAware]` entity because it uses `is_array()` to test `FieldMapping` entries, and on Doctrine ORM 3.x every entry is a `FieldMapping` object (implements `ArrayAccess`, not `array`). Empirically confirmed: `is_array(new FieldMapping(...)) === false`. The fix is to replace `!is_array($mapping)` with `!is_array($mapping) && !($mapping instanceof ArrayAccess)`, or better, access `$metadata->fieldMappings` directly and use `$fm->columnName` / `$fm->nullable` / `$fm->type` property access.

**Gap 2 (BLOCKER — WR-01): `ObjectMetadataResolver` never injected — D-02 "present" path is dead code**

`extension.neon` registers `TenantIdDriftRule` with no `arguments`. PHPStan's DI container cannot autowire `?object` to the concrete `ObjectMetadataResolver` service. Even with `phpstan/phpstan-doctrine` installed (which the bundle actively suggests in `composer.json` suggest), `$this->objectMetadataResolver` is always `null`. The advertised "XML/YAML-mapped entity analysis in Rule 3" silently does nothing. Currently WR-01 is the only thing shielding consumers from CR-01 — but the feature is non-functional rather than safe by design. Fix CR-01 first, then wire the resolver in a conditionally-included neon fragment.

**Gap 3 (WARNING — WR-02): False positive on `#[TenantAware]` MappedSuperclass bases**

`TenantIdDriftRule` fires on any `#[TenantAware]` class lacking a `tenant_id` column regardless of whether the class is a non-instantiable `#[ORM\MappedSuperclass]`. A common, correct Doctrine pattern — `#[TenantAware]` on a mapped superclass, `tenant_id` declared in each concrete subclass — produces a permanent false error on the base class. The test even asserts this is expected behavior (`testFiresOnInheritedTenantAware` expects `TenantAwareParent` to fire). This trains consumers to suppress or disable the rule, undermining its security value. The fix is to skip abstract classes and classes carrying `#[ORM\MappedSuperclass]` when no `tenant_id` is found in their own hierarchy.

**Relationship between gaps:** Gap 1 and Gap 2 are interdependent (fix CR-01 before wiring WR-01 or the fix immediately breaks consumers). Gap 3 is independent. All three affect the correctness of the D-02 decision and the consumer-facing behavior of Rule 3. Gaps 1 and 2 are BLOCKER severity (breaks the advertised feature for consumers who follow the documented phpstan-doctrine suggestion). Gap 3 is WARNING severity (false positive direction, not security-relevant false negative).

**What IS working:** Rule 1 (`MutualExclusionRule`) and Rule 2 (`SharedEntityLeakRule`) are sound, well-tested, and production-ready. Rule 3's reflection fallback (the primary CI-tested path) is correct and covers the majority of consumer use cases (PHP attribute-mapped entities). The extension.neon structure, composer.json wiring, dogfood analysis, and CI integration are all correct.

---

_Verified: 2026-06-16_
_Verifier: Claude (gsd-verifier)_
