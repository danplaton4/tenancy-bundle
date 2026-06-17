---
phase: 28-phpstan-extension
verified: 2026-06-17T00:00:00Z
status: gaps_found
score: 8/9 must-haves verified
overrides_applied: 0
re_verification:
  previous_status: gaps_found
  previous_score: 8/12
  gaps_closed:
    - "CR-01: checkViaMetadata() now reads ORM 3.x FieldMapping via instanceof \\ArrayAccess + offset accessor — zero false positives on valid entities"
    - "WR-01: ObjectMetadataResolver injected via standalone extension-doctrine.neon — D-02 metadata path reachable"
    - "WR-02: MappedSuperclass/abstract skip guard in processNode() — false positive on base classes eliminated"
    - "WR-05: no-doctrine CI lane extended to run tests/Unit/PHPStan + base dogfood with phpstan-doctrine removed; phpstan --version survival guard present"
  gaps_remaining:
    - "WR-04 test coverage: positional #[ORM\\Column] path code exists but has zero CI test coverage"
  regressions: []
gaps:
  - truth: "A positionally-declared #[ORM\\Column('tenant_id', 'integer')] / nullable positional column is detected (positional type/nullable args are read, not silently passed)"
    status: failed
    reason: "The code fix for WR-04 is present in checkViaReflection() lines 224-225 ($args['nullable'] ?? $args[6] ?? false; $args['type'] ?? $args[1] ?? null). However, plan 28-05 must_have explicitly requires this behavior to be testable in CI: 'A positionally-declared #[ORM\\Column('tenant_id', 'integer')] / nullable positional column is detected.' No fixture files for positional #[ORM\\Column] declarations were created (fixture directory has no TenantIdPositionalType*.php or TenantIdPositionalNullable*.php). No test methods exercise the $args[1] or $args[6] fallback paths. A revert of lines 224-225 back to the pre-fix named-only form would be invisible to CI. This is the sole false-negative direction in an otherwise precision-first rule — the security-relevant direction."
    artifacts:
      - path: "tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php"
        issue: "No test method exercises positional type (index 1) or positional nullable (index 6) fallbacks in checkViaReflection()"
      - path: "tests/Unit/PHPStan/Rule/Fixtures/"
        issue: "No TenantIdPositionalNonStringViolating.php or TenantIdPositionalNullableViolating.php fixture created"
    missing:
      - "Create tests/Unit/PHPStan/Rule/Fixtures/TenantIdPositionalNonStringViolating.php: #[TenantAware] #[ORM\\Entity] with #[ORM\\Column('tenant_id', 'integer')] — positional type at index 1 must fire tenancy.tenantIdDrift"
      - "Create tests/Unit/PHPStan/Rule/Fixtures/TenantIdPositionalNullableViolating.php: #[TenantAware] #[ORM\\Entity] with #[ORM\\Column('tenant_id', 'string', 63, null, null, false, true)] — positional nullable at index 6 must fire tenancy.tenantIdDrift"
      - "Add two test methods in TenantIdDriftRuleTest.php asserting each positional fixture fires the expected error"
human_verification:
  - test: "Extension-installer zero-config auto-load in a real consumer project"
    expected: "Rules auto-register; vendor/bin/phpstan analyse reports tenancy.mutualExclusion on an entity carrying both #[Shared] and #[TenantAware] without manual configuration"
    why_human: "Cannot be reproduced inside the bundle's own RuleTestCase harness (which bypasses extension-installer via getAdditionalConfigFiles()). Requires a real downstream consumer project."
---

# Phase 28: PHPStan Extension Re-Verification Report

**Phase Goal:** Ship a consumer-facing PHPStan extension with three rules catching `#[TenantAware]`/`#[Shared]` misuse at static-analysis time: (1) mutual exclusion, (2) cross-EM leak, (3) tenant_id config drift. Soft-integrates ObjectMetadataResolver when present, degrades to reflection when absent. Ships via extension-installer auto-load.
**Verified:** 2026-06-17
**Status:** gaps_found
**Re-verification:** Yes — after gap-closure plans 28-05 and 28-06

## Goal Achievement

### Observable Truths (re-verification focus)

Previously-VERIFIED truths (truths #1-8, #13 from prior report) were quick-regression-checked; PHPStan L9 clean, full suite 9/9 tests green, both dogfoods OK confirmed. They hold.

The four previously-FAILED truths are the focus:

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 9 | D-02 metadata path produces CORRECT results on ORM 3.x (CR-01) | VERIFIED | `checkViaMetadata()` lines 150-174: `instanceof \ArrayAccess` dispatch with `$fm['columnName']` offset accessor replaces broken `is_array()` guard; `testMetadataPathSilentOnValidEntity()` and `testMetadataPathFiresOnMissingTenantId()` both assert non-null `getClassMetadata()` before `analyse()` (W3 trap blocked); 9/9 tests pass |
| 10 | ObjectMetadataResolver injected when phpstan-doctrine installed (WR-01) | VERIFIED | `extension-doctrine.neon` exists; `arguments: objectMetadataResolver: @PHPStan\Type\Doctrine\ObjectMetadataResolver`; standalone (no `includes:` key — zero base co-load risk); `phpstan-extension-dogfood.neon` includes ONLY `extension-doctrine.neon`; dogfood exits 0 over src/ with phpstan-doctrine present |
| 11 | MappedSuperclass/abstract #[TenantAware] bases are SILENT (WR-02) | VERIFIED | `processNode()` lines 74-78: `isAbstract()` OR `getAttributes(MappedSuperclass::class)` guard returns `[]` before path branch; `testSilentOnMappedSuperclassBase()` asserts zero errors on `TenantAwareParent`; `testMappedSuperclassParentSilentConcreteChildFires()` asserts exactly one error on concrete child, none on parent |
| 12 | Positional #[ORM\Column] nullable/type args detected — no false negative (WR-04) | FAILED | Code present (lines 224-225: `$args['nullable'] ?? $args[6] ?? false`; `$args['type'] ?? $args[1] ?? null`). ZERO test coverage: no positional fixture files, no test methods exercise `$args[1]`/`$args[6]` paths. A revert of lines 224-225 is invisible to CI. |

**Score: 8/9 gap-closure truths verified** (truths #9, #10, #11 closed; truth #12 partially closed — code present, tests absent)

## Code Review Finding Adjudication (W1, W2, W3)

### W1: ArrayAccess accessor on FieldMapping emits E_USER_DEPRECATED

**Finding:** `checkViaMetadata()` uses `$fm['columnName']` (ArrayAccess offsetGet) which fires `Deprecation::trigger()` on every call in ORM 3.x and will stop working in ORM 4.0 when FieldMapping drops ArrayAccess entirely.

**Evidence:** `vendor/doctrine/orm/src/Mapping/ArrayAccessImplementation.php` lines 29-36 confirmed: `offsetGet()` calls `Deprecation::trigger()` with message "Using ArrayAccess on FieldMapping is deprecated and will not be possible in Doctrine ORM 4.0. Use the corresponding property instead."

**Assessment:** The prior verification gap specified the must_have as "checkViaMetadata() reads Doctrine ORM 3.x FieldMapping CORRECTLY — a valid #[TenantAware] entity produces ZERO errors via the metadata path." The implementation achieves this: false positives are eliminated on ORM 3.x. The PLAN itself prescribed ArrayAccess offset accessor as the committed fix mechanism (28-05-PLAN.md `<interfaces>` section, `<action>` block — "Use the \ArrayAccess offset accessor as the PRIMARY read path"). The CR-01 must_have is satisfied.

However, W1 describes a real quality issue: the chosen accessor emits deprecation notices per field read on every consumer using ORM 3.x, and will reintroduce the CR-01 false-positive symptom when ORM 4.0 removes ArrayAccess from FieldMapping. This is a **WARNING** against the phase goal's forward-compatibility. It does not block the current ORM 3.x correctness requirement but it is a genuine defect that will require a follow-up fix.

**Classification: WARNING** — not a BLOCKER against the phase must_have (CR-01 is closed for ORM 3.x), but a forward-compat issue that should be tracked. The prescribed fix (property access with an inner `@var object{columnName: string, nullable: bool|null, type: string} $fm` narrowing inside the `instanceof \ArrayAccess` branch) is simple and avoids deprecation notices entirely.

### W2: Misleading comment at TenantIdDriftRule.php line 152

**Finding:** Comment reads "ORM 2.x: plain array entries also satisfy \ArrayAccess check via the is_array() branch below." Plain PHP arrays do NOT implement `\ArrayAccess` — `$arr instanceof ArrayAccess` is `false`.

**Assessment:** The comment describes the ORM 2.x branch correctly in intent (plain arrays fall to `is_array()`) but incorrectly states they "satisfy \ArrayAccess check." A future maintainer could misread this as arrays being accepted by the `instanceof \ArrayAccess` branch.

**Classification: WARNING (cosmetic)** — no behavioral impact; no phase must_have violation; misleading to maintainers only. Not a BLOCKER.

### W3: WR-04 positional arg path has zero test coverage

**Finding:** Plan 28-05 must_have truth explicitly requires: "A positionally-declared #[ORM\Column('tenant_id', 'integer')] / nullable positional column is detected." The code fix lands at lines 224-225 but no fixture files and no test methods were created for this path.

**Assessment:** This is a direct failure of the plan's must_have. The must_have is not just "code exists" — it requires the behavior to be demonstrable. The 28-05 SUMMARY claims `provides: "TenantIdDriftRule::checkViaReflection() — positional fallbacks for nullable ($args[6]) and type ($args[1])"` but does not claim test methods were created for this path (because they were not). The plan task explicitly listed "Add reflection-path tests for #[ORM\Column('tenant_id', 'integer')] (positional non-string type fires) and a positional nullable=true at index 6." These tests do not exist.

The security-relevant false-negative direction is what WR-04 addresses. A revert of lines 224-225 is invisible to CI. This is a **BLOCKER** gap against must_have truth #12.

**Classification: BLOCKER** — see gaps section above.

## Required Artifacts (gap-closure artifacts)

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `src/PHPStan/Rule/TenantIdDriftRule.php` | CR-01 fix + WR-02 skip + WR-04 code | VERIFIED (code); PARTIAL (tests) | `instanceof \ArrayAccess` dispatch present; `isAbstract()`/`MappedSuperclass` skip present; positional fallbacks at lines 224-225 present; no positional fixture/test |
| `tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php` | All gaps tested including WR-04 positional | PARTIAL | 9 tests (all pass): 4 reflection, 3 WR-02 hierarchy, 2 metadata-path with entry proofs; WR-04 positional tests ABSENT |
| `tests/Unit/PHPStan/Rule/Fixtures/TenantAwareConcreteChild.php` | WR-02 dedicated fixture | VERIFIED | File exists: concrete `#[ORM\Entity]` subclass of `TenantAwareParent` with no `tenant_id`; fires at line 16 |
| `extension-doctrine.neon` | Standalone wired fragment (WR-01) | VERIFIED | Exists; standalone (no `includes:` key); `objectMetadataResolver: @PHPStan\Type\Doctrine\ObjectMetadataResolver` present; `TenantIdDriftRule` registered exactly once |
| `phpstan-extension-dogfood.neon` | Includes ONLY extension-doctrine.neon | VERIFIED | `includes: [extension-doctrine.neon]` only; exits 0 over src/ |
| `phpstan-extension-dogfood-nodoctrine.neon` | Base-only dogfood for no-doctrine lane (WR-05) | VERIFIED | Exists; `includes: [extension.neon]` only; exits 0 |
| `.github/workflows/ci.yml` (no-doctrine job) | Remove phpstan-doctrine + phpstan survives + tests/Unit/PHPStan + base dogfood | VERIFIED | `phpstan/phpstan-doctrine` in remove command; `phpstan --version` step present; `tests/Unit/PHPStan` in phpunit step; `phpstan-extension-dogfood-nodoctrine.neon` step present |

## Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `extension-doctrine.neon` | `TenantIdDriftRule::$objectMetadataResolver` | `arguments: objectMetadataResolver: @PHPStan\Type\Doctrine\ObjectMetadataResolver` | WIRED | Line 47; standalone fragment (no base include) — single registration confirmed |
| `phpstan-extension-dogfood.neon` | `extension-doctrine.neon` | `includes:` | WIRED | Line 20; ONLY fragment (not base); dogfood exits 0 |
| `phpstan-extension-dogfood-nodoctrine.neon` | `extension.neon` | `includes:` | WIRED | Line 26; base resolver-less; no `extension-doctrine` reference |
| `TenantIdDriftRule.php processNode()` | `isAbstract()`/`MappedSuperclass` skip | Before path branch (lines 74-78) | WIRED | Both checks present; `testSilentOnMappedSuperclassBase()` green |
| `TenantIdDriftRule.php checkViaReflection()` | Positional args `$args[1]`/`$args[6]` | Lines 224-225 | PARTIAL (code wired, CI not wired) | Code present; no fixture; no test — revert invisible |
| `.github/workflows/ci.yml` no-doctrine job | `tests/Unit/PHPStan` | phpunit step | WIRED | Line 128 confirmed by grep |
| `.github/workflows/ci.yml` no-doctrine job | `phpstan-extension-dogfood-nodoctrine.neon` | PHPStan dogfood step | WIRED | Line 138 confirmed |

## Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| All 9 TenantIdDriftRule tests pass (including 2 metadata-path tests) | `vendor/bin/phpunit tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php --no-coverage` | 9/9 pass, 11 assertions | PASS |
| Level-9 self-analysis clean | `vendor/bin/phpstan analyse --memory-limit=1G` | [OK] No errors | PASS |
| Wired dogfood (metadata path end-to-end) | `vendor/bin/phpstan analyse -c phpstan-extension-dogfood.neon --memory-limit=1G` | [OK] No errors — zero false positives via resolver path | PASS |
| Base dogfood (no-doctrine path) | `vendor/bin/phpstan analyse -c phpstan-extension-dogfood-nodoctrine.neon --memory-limit=1G` | [OK] No errors | PASS |

## W1 Forward-Compatibility Detail

The `instanceof \ArrayAccess` + offset accessor approach is functional on ORM 3.x but deprecated. The recommended property access path (adding `/** @var object{columnName: string, nullable: bool|null, type: string} $fm */` inside the `instanceof \ArrayAccess` branch, then using `$fm->columnName` / `$fm->nullable` / `$fm->type`) avoids deprecation notices and is ORM 4.0 safe. This fix is independent of any phase gap and can be applied without touching tests. It is noted here as a forward-compat cleanup item.

## Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|---------|
| DX-03 (overall) | 28-01/02/03/04/05/06 | PHPStan extension for `#[TenantAware]` + `#[Shared]` correctness | PARTIAL | Three rules exist and CI is green; metadata path wired and correct (CR-01/WR-01 closed); WR-02 closed; WR-04 code present but untested |
| DX-03-AC1 | 28-01 | Rule fires on `#[TenantAware]` AND `#[Shared]` (mutual exclusion) | SATISFIED | `MutualExclusionRule` + 3 tests green |
| DX-03-AC2 | 28-03 | Rule fires on tenant EM querying `#[Shared]` entity | SATISFIED | `SharedEntityLeakRule` (conservative D-03) + 3 tests green |
| DX-03-AC3 | 28-02/05 | Rule fires when `tenant_id` missing/nullable/non-string; reflection + metadata paths correct | PARTIALLY SATISFIED | Named-arg reflection path fully tested; metadata path tested (CR-01 closed); positional arg path code present but untested (WR-04) |
| DX-03-AC4 | 28-01/04 | Ships via extension-installer auto-load | SATISFIED | `extra.phpstan.includes`; `allow-plugins` set |
| DEC-PHPSTAN-01 | 28-01 | Zero-config distribution | SATISFIED | `extra.phpstan.includes: ["extension.neon"]`; `extension.neon` stays resolver-less and crash-safe |

## Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `src/PHPStan/Rule/TenantIdDriftRule.php` | 151-152 | `instanceof \ArrayAccess` path uses `$fm['columnName']` offset accessor which fires `E_USER_DEPRECATED` on every ORM 3.x FieldMapping read; will break on ORM 4.0 | WARNING | W1: behavior is correct on ORM 3.x; deprecated; ORM-4.0-unsafe; fix is a one-line narrowing + property-access change |
| `src/PHPStan/Rule/TenantIdDriftRule.php` | 152 | Comment "ORM 2.x: plain array entries also satisfy \ArrayAccess check via the is_array() branch below" — factually incorrect (arrays do NOT implement \ArrayAccess) | WARNING | W2: cosmetic/misleading to maintainers; no behavioral impact |
| `tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php` | (absent) | WR-04 positional-arg fix (lines 224-225) has zero test coverage — no fixture files, no test methods | BLOCKER | W3: revert of the WR-04 fix is invisible to CI; security-relevant false-negative direction |

No unreferenced TBD/FIXME/XXX markers found in modified files.

## Human Verification Required

### 1. Extension-installer zero-config auto-load in a real consumer project

**Test:** In a scratch Symfony project, install `danplaton4/tenancy-bundle` + `phpstan/extension-installer`, run `vendor/bin/phpstan analyse --debug` and confirm the tenancy rules auto-load without any `includes:` in the consumer's `phpstan.neon`.
**Expected:** Rules auto-register; `vendor/bin/phpstan analyse` reports `tenancy.mutualExclusion` on an entity carrying both `#[Shared]` and `#[TenantAware]` without manual configuration.
**Why human:** Cannot be reproduced inside the bundle's own RuleTestCase harness. Requires a real downstream consumer project.

**Note:** The underlying gap (CR-01 false-positive storm) that previously blocked this human check is now closed. A consumer can safely install phpstan-doctrine and use the base extension.neon path without receiving false errors. The extension-doctrine.neon path for the metadata-enhanced experience requires consumer-side manual include (documented in Phase 29 DOC-20).

## Gaps Summary

**One gap blocks full goal achievement:**

**Gap (BLOCKER — WR-04 test coverage): Positional `#[ORM\Column]` path untested**

The WR-04 code fix in `checkViaReflection()` (lines 224-225) is present and correct — `$args['nullable'] ?? $args[6] ?? false` and `$args['type'] ?? $args[1] ?? null`. However, plan 28-05 explicitly promises test coverage of this path, and it is absent. No fixture files for positional column declarations exist in the fixture directory. No test methods assert that `#[ORM\Column('tenant_id', 'integer')]` (positional type at index 1) fires a `tenancy.tenantIdDrift` error, and no test asserts that `#[ORM\Column('tenant_id', 'string', 63, null, null, false, true)]` (positional nullable at index 6) fires. A silent revert of lines 224-225 would pass all CI checks. WR-04 is the sole false-negative direction in an otherwise precision-first security rule.

Fix requires two fixture files and two test methods — approximately 10 minutes of work. The code change itself is confirmed complete and does not need to be revisited.

**What IS working (closed gaps):**
- CR-01 (truth #9): `checkViaMetadata()` correct on ORM 3.x — zero false positives confirmed by resolver-injected metadata-path tests with non-null entry proofs
- WR-01 (truth #10): `ObjectMetadataResolver` injected via standalone `extension-doctrine.neon` — metadata path reachable; dogfood proves zero false positives end-to-end
- WR-02 (truth #11): MappedSuperclass/abstract bases are silent; concrete children fire; three dedicated tests cover all WR-02 cases
- WR-05: no-doctrine CI lane extended — removes phpstan-doctrine, asserts phpstan survives, runs `tests/Unit/PHPStan` + base dogfood
- All previously-verified truths (#1-8, #13) hold: MutualExclusionRule, SharedEntityLeakRule, extension-installer wiring, CI dogfood, bundle self-analysis all green

---

_Verified: 2026-06-17_
_Verifier: Claude (gsd-verifier)_
_Re-verification: gaps_found → 1 remaining gap (WR-04 test coverage)_
