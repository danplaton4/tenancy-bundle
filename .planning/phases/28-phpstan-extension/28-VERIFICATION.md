---
phase: 28-phpstan-extension
verified: 2026-06-17T13:30:00Z
status: human_needed
score: 9/9 must-haves verified
overrides_applied: 0
re_verification:
  previous_status: gaps_found
  previous_score: 8/9
  gaps_closed:
    - "WR-04 (BLOCKER): two positional fixtures (TenantIdPositionalNonStringViolating.php, TenantIdPositionalNullableViolating.php) + two new RuleTestCase test methods exercise $args[1] (type) and $args[6] (nullable) positional fallbacks — suite is now 11/11, revert of lines 224-225 breaks CI"
    - "W1 (WARNING): checkViaMetadata() instanceof \\ArrayAccess branch now reads FieldMapping via property access ($fm->columnName/$fm->nullable/$fm->type) after inner @var \\ArrayAccess<array-key,mixed>&object{columnName:string,nullable:bool|null,type:string} narrowing — zero E_USER_DEPRECATED notices, ORM-4.0-safe, level-9 clean, zero @phpstan-ignore"
    - "W2 (WARNING): line-151..154 comment corrected — ORM 2.x plain arrays do NOT implement \\ArrayAccess; the old false phrase 'satisfy \\ArrayAccess check' is removed; comment accurately describes the is_array() branch below"
  gaps_remaining: []
  regressions: []
human_verification:
  - test: "Extension-installer zero-config auto-load in a real consumer project"
    expected: "Rules auto-register; vendor/bin/phpstan analyse reports tenancy.mutualExclusion on an entity carrying both #[Shared] and #[TenantAware] without any includes: in the consumer's phpstan.neon"
    why_human: "Cannot be reproduced inside the bundle's own RuleTestCase harness (which bypasses extension-installer via getAdditionalConfigFiles()). Requires a real downstream consumer project."
---

# Phase 28: PHPStan Extension Re-Verification Report (Final)

**Phase Goal:** Ship a consumer-facing PHPStan extension with three rules catching `#[TenantAware]`/`#[Shared]` misuse at static-analysis time: (1) mutual exclusion, (2) cross-EM leak, (3) tenant_id config drift. Soft-integrates ObjectMetadataResolver when present, degrades to reflection when absent. Ships via extension-installer auto-load.
**Verified:** 2026-06-17T13:30:00Z
**Status:** human_needed
**Re-verification:** Yes — after gap-closure plan 28-07 (WR-04 BLOCKER + W1/W2 WARNINGs); previous score 8/9 gaps_found

## Goal Achievement

### Observable Truths

All 9 must-have truths are now VERIFIED. The sole previous BLOCKER (WR-04) is closed.

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | MutualExclusionRule fires on entity with both #[TenantAware] and #[Shared] | VERIFIED | Rule + 3 tests green in full suite (763 tests, 2 skipped) |
| 2 | SharedEntityLeakRule fires when tenant EM queries a #[Shared] entity | VERIFIED | Rule + conservative precision guard + 3 tests green |
| 3 | TenantIdDriftRule fires when tenant_id is missing from a #[TenantAware] entity | VERIFIED | `testFiresWhenTenantIdMissing()` passes; message "has no column mapped to tenant_id" |
| 4 | TenantIdDriftRule fires when tenant_id is nullable | VERIFIED | `testFiresWhenTenantIdNullable()` + `testFiresWhenTenantIdPositionalNullable()` both pass |
| 5 | TenantIdDriftRule fires when tenant_id type is non-string | VERIFIED | `testFiresWhenTenantIdNonString()` + `testFiresWhenTenantIdPositionalNonString()` both pass |
| 6 | Positional #[ORM\Column] type at index 1 detected (WR-04 type direction) | VERIFIED | `TenantIdPositionalNonStringViolating.php` fixture confirmed; `testFiresWhenTenantIdPositionalNonString()` passes; `$args[1]` fallback at line 228 present; revert of line 228 breaks CI |
| 7 | Positional #[ORM\Column] nullable at index 6 detected (WR-04 nullable direction) | VERIFIED | `TenantIdPositionalNullableViolating.php` fixture confirmed; `testFiresWhenTenantIdPositionalNullable()` passes; `$args[6]` fallback at line 227 present; revert of line 227 breaks CI |
| 8 | checkViaMetadata() uses ORM-4.0-safe property access, no E_USER_DEPRECATED (W1) | VERIFIED | `$fm->columnName/$fm->nullable/$fm->type` on lines 157/159/160; inner `@var \ArrayAccess<array-key, mixed>&object{columnName: string, nullable: bool|null, type: string}` on line 156; zero `@phpstan-ignore`; metadata-path tests still pass; PHPStan L9 [OK] |
| 9 | Line-151..154 comment correctly describes ORM 2.x is_array() branch (W2) | VERIFIED | Old "satisfy \\ArrayAccess check" phrase is absent (grep confirms zero matches); new comment reads "ORM 2.x: plain array entries do NOT implement \\ArrayAccess — they fall to the is_array() branch below and are NOT matched by this instanceof check" |

**Score: 9/9 must-have truths verified**

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `tests/Unit/PHPStan/Rule/Fixtures/TenantIdPositionalNonStringViolating.php` | Positional type 'integer' at index 1; exercises $args[1] WR-04 fallback | VERIFIED | File exists; line 22: `#[ORM\Column('tenant_id', 'integer')]`; no named args (grep count 0); `#[TenantAware]` on line 10 |
| `tests/Unit/PHPStan/Rule/Fixtures/TenantIdPositionalNullableViolating.php` | Positional nullable=true at index 6; exercises $args[6] WR-04 fallback | VERIFIED | File exists; line 23: `#[ORM\Column('tenant_id', 'string', 63, null, null, false, true)]`; no named args (grep count 0); `#[TenantAware]` on line 10 |
| `tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php` | 11 tests (was 9); two positional test methods added | VERIFIED | 11 tests pass; `testFiresWhenTenantIdPositionalNonString()` and `testFiresWhenTenantIdPositionalNullable()` present; both use `::class` in message construction; both assert error on line 10 |
| `src/PHPStan/Rule/TenantIdDriftRule.php` | W1 property access + W2 comment; WR-04 code at lines 227-228 untouched | VERIFIED | Property reads at lines 157/159/160; inner narrowing at line 156; `$args[6]` at line 227; `$args[1]` at line 228; `elseif (is_array($fm))` at line 167 untouched; zero `@phpstan-ignore` |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `TenantIdPositionalNonStringViolating.php` | `checkViaReflection() $args[1]` | `testFiresWhenTenantIdPositionalNonString()` in RuleTestCase | WIRED | Test passes; asserts non-string "integer" error on line 10 |
| `TenantIdPositionalNullableViolating.php` | `checkViaReflection() $args[6]` | `testFiresWhenTenantIdPositionalNullable()` in RuleTestCase | WIRED | Test passes; asserts nullable error on line 10 |
| `checkViaMetadata() instanceof \ArrayAccess branch` | FieldMapping public properties | `$fm->columnName/$fm->nullable/$fm->type` after inner `@var \ArrayAccess<array-key,mixed>&object{...}` narrowing | WIRED | Lines 156-160 confirmed; W1 closed |
| `extension-doctrine.neon` | `TenantIdDriftRule::$objectMetadataResolver` | `arguments: objectMetadataResolver: @PHPStan\Type\Doctrine\ObjectMetadataResolver` | WIRED | Verified in prior iteration; wired dogfood [OK] |
| `phpstan-extension-dogfood.neon` | `extension-doctrine.neon` | `includes:` | WIRED | Dogfood gate [OK] confirmed this run |
| `phpstan-extension-dogfood-nodoctrine.neon` | `extension.neon` | `includes:` | WIRED | No-doctrine dogfood gate [OK] confirmed this run |

### Data-Flow Trace (Level 4)

Not applicable — PHPStan rules operate at static analysis time, not runtime. No data flows through DB or runtime state. The "data" is PHP attribute arguments read via reflection, exercised by RuleTestCase fixtures.

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| TenantIdDriftRuleTest: 11/11 tests pass (including 2 new positional WR-04 tests) | `vendor/bin/phpunit tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php --no-coverage` | OK (11 tests, 13 assertions) | PASS |
| Full suite: 763 tests, 2 pre-existing skips, 0 failures | `vendor/bin/phpunit --no-coverage` | OK, 763 tests, 2 skipped | PASS |
| Bundle level-9 self-analysis clean | `vendor/bin/phpstan analyse --memory-limit=512M` | [OK] No errors | PASS |
| Wired dogfood (metadata path, phpstan-doctrine present) | `vendor/bin/phpstan analyse -c phpstan-extension-dogfood.neon --memory-limit=512M` | [OK] No errors | PASS |
| Base dogfood (no-doctrine path) | `vendor/bin/phpstan analyse -c phpstan-extension-dogfood-nodoctrine.neon --memory-limit=512M` | [OK] No errors | PASS |
| Code style clean | `vendor/bin/php-cs-fixer check --diff` | No violations (0 files diff) | PASS |

### Probe Execution

No probe scripts declared for this phase. Step 7c: N/A.

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|---------|
| DX-03 (overall) | 28-01..28-07 | PHPStan extension for `#[TenantAware]` + `#[Shared]` correctness | SATISFIED | Three rules exist; all CI gates green; metadata + reflection paths correct and tested; extension-installer wiring confirmed |
| DX-03-AC1 | 28-01 | Rule fires on entity with both `#[TenantAware]` AND `#[Shared]` (mutual exclusion) | SATISFIED | `MutualExclusionRule` + 3 tests green |
| DX-03-AC2 | 28-03 | Rule fires when tenant EM queries a `#[Shared]` entity | SATISFIED | `SharedEntityLeakRule` (conservative D-03) + 3 tests green |
| DX-03-AC3 | 28-02/05/07 | Rule fires when `tenant_id` is missing, nullable, or non-string; reflection + metadata paths correct; positional args detected | SATISFIED | Named-arg reflection path fully tested (4 methods); metadata path tested (2 methods, self-skip when phpstan-doctrine absent); positional type `$args[1]` tested by `testFiresWhenTenantIdPositionalNonString()`; positional nullable `$args[6]` tested by `testFiresWhenTenantIdPositionalNullable()`; WR-04 BLOCKER closed |
| DX-03-AC4 | 28-01/04 | Ships via extension-installer auto-load | SATISFIED (automated) / NEEDS HUMAN (end-to-end) | `extra.phpstan.includes: ["extension.neon"]` present; `allow-plugins` set; zero-config auto-load in a real consumer project requires human verification (see below) |
| DEC-PHPSTAN-01 | 28-01 | Zero-config distribution | SATISFIED | `extra.phpstan.includes: ["extension.neon"]`; `extension.neon` stays resolver-less and crash-safe |

### Anti-Patterns Found

No blockers. No unreferenced TBD/FIXME/XXX markers in phase-modified files.

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| (none) | — | All previously identified anti-patterns (W1/W2) resolved in plan 28-07 | — | W1: property access path is ORM-4.0-safe; W2: comment corrected |

### Human Verification Required

#### 1. Extension-installer zero-config auto-load in a real consumer project

**Test:** In a scratch Symfony project, install `danplaton4/tenancy-bundle` + `phpstan/extension-installer`, run `vendor/bin/phpstan analyse --debug` and confirm the tenancy rules auto-register without any manual `includes:` in the consumer's `phpstan.neon`.
**Expected:** Rules auto-register; `vendor/bin/phpstan analyse` reports `tenancy.mutualExclusion` on an entity carrying both `#[Shared]` and `#[TenantAware]` without manual configuration.
**Why human:** Cannot be reproduced inside the bundle's own RuleTestCase harness (which bypasses extension-installer via `getAdditionalConfigFiles()`). Requires a real downstream consumer project with `phpstan/extension-installer` installed.

**Note:** All previously-blocking code issues (CR-01 false-positive storm, WR-01 resolver injection) are closed. A consumer can now safely install phpstan-doctrine and use the base `extension.neon` path without receiving false errors. This human check verifies the installer plumbing only.

## Gaps Summary

No gaps. All 9 must-have truths verified. The sole BLOCKER from the previous iteration (WR-04: positional `#[ORM\Column]` code present but untested) is now closed by:

- Two new fixture files exercising the `$args[1]` (type) and `$args[6]` (nullable) positional fallbacks
- Two new `RuleTestCase` test methods that fail if lines 227-228 are reverted
- Suite is 11/11; all green; anti-revert property holds

The W1 ArrayAccess deprecation warning and W2 comment inaccuracy are both fixed in commit `32b56d6`.

The phase is complete pending the single human verification item above (extension-installer end-to-end in a real consumer project).

---

_Verified: 2026-06-17T13:30:00Z_
_Verifier: Claude (gsd-verifier)_
_Re-verification: gaps_found (8/9) → human_needed (9/9) after gap-closure plan 28-07_
