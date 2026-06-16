---
phase: 28-phpstan-extension
plan: 04
subsystem: testing
tags: [phpstan, phpstan-extension, phpstan-rules, static-analysis, ci, dogfood]

# Dependency graph
requires:
  - phase: 28-phpstan-extension
    plan: 01
    provides: "extension.neon + MutualExclusionRule (Rule 1) shipped"
  - phase: 28-phpstan-extension
    plan: 02
    provides: "TenantIdDriftRule (Rule 3) fully implemented"
  - phase: 28-phpstan-extension
    plan: 03
    provides: "SharedEntityLeakRule (Rule 2) fully implemented; AttributeHierarchyHelper removed"

provides:
  - "phpstan-extension-dogfood.neon — separate dogfood config; includes extension.neon; proves shipped rules load + run clean on bundle's own src/"
  - "CI dogfood step in phpstan job — running phpstan-extension-dogfood.neon on every push/PR"
  - "Phase 28 integration gate: all four gates green together (phpunit 757/2, phpstan L9, dogfood, cs-fixer)"
  - "DX-03 AC1–AC5 acceptance-to-test mapping documented"
  - "Two manual-only verification procedures documented with exact steps"

affects:
  - "29-phpstan-extension-docs (DOC-20) — verifier can reference this SUMMARY for manual verification steps"
  - "any consumer installing phpstan/extension-installer — CI dogfood proves the extension stays loadable"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Dogfood neon: includes extension.neon + mirrors phpstan.neon's trait.unused suppression (consumer-facing traits used zero times in bundle itself)"
    - "CI dogfood step with --memory-limit=512M in phpstan job (phpunit.xml.dist already sets 512M for phpunit; phpstan needs explicit CLI flag)"
    - "Dogfood targets src/ only — no fixture files, no test support entities (they would fire Rule 3 and Rule 2 intentionally)"

key-files:
  created:
    - phpstan-extension-dogfood.neon
  modified:
    - .github/workflows/ci.yml

key-decisions:
  - "Dogfood targets src/ only — not tests/; fixture files carry intentional violations; including them would need ignoreErrors entries that undermine the dogfood's purpose"
  - "trait.unused suppression in dogfood config mirrors phpstan.neon — three consumer-facing traits (TenantFilesystemConfigTrait, TenantMailerConfigTrait, InteractsWithTenancy) are used zero times in the bundle itself by design"
  - "Dogfood step added inside the existing phpstan: job (after level-9 step) — avoids a new job spin-up; both analyses share the same composer install"
  - "phpstan.neon left completely unchanged — Pitfall 4 from RESEARCH.md compliance confirmed via grep gate"

requirements-completed: [DX-03]

# Metrics
duration: 15min
completed: 2026-06-16T16:22:00Z
---

# Phase 28 Plan 04: Integration Gate + Dogfood + CI Wiring Summary

**Phase 28 integration gate green: 757 phpunit pass, phpstan L9 0 errors, dogfood 0 errors, cs-fixer clean — dogfood config + CI step wired, DX-03 AC1–AC5 mapped, manual verifications documented**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-06-16T16:18:58Z
- **Completed:** 2026-06-16T16:22:00Z
- **Tasks:** 2 (Task 1: dogfood config + CI; Task 2: gate run + SUMMARY)
- **Files modified:** 3 (phpstan-extension-dogfood.neon created, .github/workflows/ci.yml modified, this SUMMARY)

## Accomplishments

- Created `phpstan-extension-dogfood.neon` at package root; includes `extension.neon`; runs all three shipped rules on `src/` at level 9; exits 0 (the bundle's own source contains no `#[Shared]`/`#[TenantAware]` misuse)
- Added dogfood step "PHPStan extension dogfood — run shipped rules on bundle sources" to the existing `phpstan:` CI job; executes after the level-9 self-analysis step; uses `--memory-limit=512M`
- `phpstan.neon` unchanged — does NOT include `extension.neon`; the two configs are entirely separate (Pitfall 4 compliance)
- All four integration gate commands exit 0

## Task Commits

1. **Task 1: Create dogfood config + CI step** — `eed4a3e` (feat)
2. **Task 2: SUMMARY.md + state updates** — docs commit (this plan's metadata)

## Files Created/Modified

- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/phpstan-extension-dogfood.neon` — Separate dogfood config; includes extension.neon; level 9; paths: [src]; trait.unused suppression; maximumNumberOfProcesses: 1
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/.github/workflows/ci.yml` — Added dogfood step inside the phpstan job after `vendor/bin/phpstan analyse`

## Integration Gate Results

| Command | Result | Detail |
|---------|--------|--------|
| `vendor/bin/phpunit` | PASS | 757 tests, 2 skipped, 3201 assertions |
| `vendor/bin/phpstan analyse` | PASS | Level 9, 0 errors (phpstan.neon — self-analysis) |
| `vendor/bin/phpstan analyse -c phpstan-extension-dogfood.neon --memory-limit=512M` | PASS | Level 9, 0 errors (dogfood — rules run on bundle src/) |
| `vendor/bin/php-cs-fixer check --diff --allow-risky=yes` | PASS | 0 files changed |

## DX-03 Acceptance Criteria → Test Mapping

| AC | Description | Test File | Test Method | Status |
|----|-------------|-----------|-------------|--------|
| AC1 fires | `#[Shared]` + `#[TenantAware]` co-present fires `tenancy.mutualExclusion` | `tests/Unit/PHPStan/Rule/MutualExclusionRuleTest.php` | `testMutualExclusionViolation()` | GREEN |
| AC1 hierarchy | Attribute on parent class detected (PHP attributes not inherited — ancestor walk required) | `tests/Unit/PHPStan/Rule/MutualExclusionRuleTest.php` | `testFiresOnInheritedAttribute()` | GREEN |
| AC1 clean | Silent when only one attribute present | `tests/Unit/PHPStan/Rule/MutualExclusionRuleTest.php` | `testNoViolationWhenOnlyOneAttribute()` | GREEN |
| AC2 fires | Concrete `EntityManager` querying `#[Shared]` entity fires `tenancy.sharedEntityLeak` | `tests/Unit/PHPStan/Rule/SharedEntityLeakRuleTest.php` | `testSharedEntityLeakViolation()` | GREEN |
| AC2 gated-off | Silent when `tenancy.checkSharedEntityLeaks: false` | `tests/Unit/PHPStan/Rule/SharedEntityLeakRuleTest.php` | `testSilentWhenGatedOff()` | GREEN |
| AC2 conservative | Silent on `EntityManagerInterface` caller (D-03 conservative) | `tests/Unit/PHPStan/Rule/SharedEntityLeakRuleTest.php` | `testSilentOnInterfaceCaller()` | GREEN |
| AC3 missing | `#[TenantAware]` entity with no `tenant_id` column fires `tenancy.tenantIdDrift` | `tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php` | `testMissingTenantIdViolation()` | GREEN |
| AC3 nullable | `tenant_id` nullable fires `tenancy.tenantIdDrift` | `tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php` | `testNullableTenantIdViolation()` | GREEN |
| AC3 type | `tenant_id` non-string type fires `tenancy.tenantIdDrift` | `tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php` | `testNonStringTenantIdViolation()` | GREEN |
| AC4 install | `extension.neon` loads via `getAdditionalConfigFiles()` in all three RuleTestCase tests | All three `*RuleTest.php` files | `getAdditionalConfigFiles()` override | GREEN |
| AC5 message | Error messages carry `tenancy.*` identifier + class name + violation type | All three `*RuleTest.php` files | Message + line assertions in `analyse()` | GREEN |

**All 11 acceptance rows claimed GREEN by the phase gate run.**

## Dogfood Analysis

**What the dogfood run checks:** the shipped `extension.neon` is included, all three rules are loaded, and they are executed against the bundle's own `src/`. The bundle's `src/` contains:
- Attribute DEFINITIONS (`src/Attribute/Shared.php`, `src/Attribute/TenantAware.php`) — these are `#[\Attribute]`-annotated final classes; no entity carries both, so Rule 1 is silent
- Rule IMPLEMENTATIONS (`src/PHPStan/Rule/*.php`) — the rule classes themselves; they reference the attribute FQCNs in code/docblocks, not as class-level attributes
- Service classes, bootstrappers, commands, subscribers — none carry `#[Shared]` or `#[TenantAware]` on the class declaration

**Findings:** 3 `trait.unused` errors for consumer-facing traits (`TenantFilesystemConfigTrait`, `TenantMailerConfigTrait`, `InteractsWithTenancy`). These are intentional — the traits are designed for downstream consumer use, not for the bundle itself. Resolution: `ignoreErrors` with `identifier: trait.unused` and `reportUnmatched: false` (mirrors the identical suppression in `phpstan.neon`). NOT a rule fire — this is a pre-existing PHPStan analysis behavior, not a tenancy-rule finding.

**Result:** `[OK] No errors` — shipped rules prove they load correctly and do not produce false positives on the bundle's own legitimate code.

## Manual-Only Verifications

The following two verifications CANNOT be automated inside the bundle's own RuleTestCase harness and are documented here for `/gsd:verify-work` and Phase 29 (DOC-20).

### MV-1: Zero-config auto-load via `phpstan/extension-installer` in a consumer project

**Why manual:** `phpstan/extension-installer` behavior depends on a downstream consumer's `composer install` run in a separate project. The bundle cannot simulate this from inside its own test suite — `RuleTestCase::getAdditionalConfigFiles()` is a manual override that bypasses extension-installer.

**Manual steps to verify:**
1. Create a scratch Symfony project: `composer create-project symfony/skeleton scratch-consumer && cd scratch-consumer`
2. Add the bundle: `composer require danplaton4/tenancy-bundle phpstan/extension-installer --dev`
3. Create a minimal `phpstan.neon`:
   ```neon
   parameters:
       level: 5
       paths: [src]
   ```
4. Run `vendor/bin/phpstan analyse --debug 2>&1 | grep tenancy` — confirm the output includes `tenancy.mutualExclusion`, `tenancy.tenantIdDrift`, `tenancy.sharedEntityLeak` in the loaded rules list
5. Create a test entity: `src/Entity/BadEntity.php` with both `#[Shared]` and `#[TenantAware]`
6. Run `vendor/bin/phpstan analyse` — confirm it reports `tenancy.mutualExclusion` without any manual `includes:` in the consumer's `phpstan.neon`

**Expected result:** The rules auto-register with zero consumer configuration. `phpstan/extension-installer` reads `extra.phpstan.includes` from `danplaton4/tenancy-bundle`'s `composer.json` and includes `extension.neon` automatically.

**Fallback (manual includes path — for consumers without extension-installer):**
Add to `phpstan.neon`:
```neon
includes:
    - vendor/danplaton4/tenancy-bundle/extension.neon
```

### MV-2: phpstan/phpstan-doctrine "present" path — ObjectMetadataResolver with XML/YAML-mapped entities

**Why manual:** The `phpstan/phpstan-doctrine` "present" code path in `TenantIdDriftRule` (Rule 3) uses `ObjectMetadataResolver::getClassMetadata()` to read real Doctrine `ClassMetadata` for entities mapped via XML or YAML rather than PHP attributes. Testing this path in CI is deferred because:
- The reflection fallback path (primary path) is fully covered by the `TenantIdDriftRuleTest.php` suite
- phpstan-doctrine's `ObjectMetadataResolver` requires a configured `objectManagerLoader` — a consumer-specific config pointing at the app's entity manager factory
- The Rule 3 code paths are isolated (`checkViaMetadata()` when resolver is non-null, `checkViaReflection()` fallback) — the reflection path is the CI-tested primary

**Manual steps to verify the "present" path:**
1. In a consumer project with phpstan-doctrine installed, add to `phpstan.neon`:
   ```neon
   parameters:
       doctrine:
           objectManagerLoader: tests/object-manager.php   # path to your EM factory
   ```
2. Create an XML-mapped entity (no PHP attributes) that is `#[TenantAware]` but has no `tenant_id` column in the mapping XML
3. Run `vendor/bin/phpstan analyse` — confirm `tenancy.tenantIdDrift` fires via the `ObjectMetadataResolver` path (verify by temporarily removing the `class_exists` guard and checking the code path executes)
4. Add a valid non-nullable string `tenant_id` column to the XML mapping — confirm the rule is silent

**Expected result:** Rule 3 uses real `ClassMetadata` when phpstan-doctrine is present, catching XML/YAML-mapped entities that the reflection fallback misses. The reflection fallback is correctly triggered when `ObjectMetadataResolver` is null or returns null metadata.

**CI-tested primary path:** The reflection fallback (D-02 "absent" path) is the CI-verified primary — all five `TenantIdDriftRuleTest.php` tests use PHP `#[ORM\Column]` attributes (no XML/YAML mapping), covering the fallback path fully.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] phpstan-extension-dogfood.neon needs trait.unused suppression**
- **Found during:** Task 1 (dogfood run exit 1 with 3 trait.unused errors)
- **Issue:** The dogfood config includes `extension.neon` but not `phpstan.neon`'s suppressions. Three consumer-facing traits in `src/` are used zero times in the bundle itself — legitimate by design. PHPStan's `trait.unused` check surfaced them.
- **Fix:** Added `ignoreErrors: [{identifier: trait.unused, reportUnmatched: false}]` to dogfood config — identical to the suppression already in `phpstan.neon`. This is an acceptable pattern match (same suppression, same bundle-source analysis context).
- **Files modified:** `phpstan-extension-dogfood.neon`
- **Verification:** `vendor/bin/phpstan analyse -c phpstan-extension-dogfood.neon --memory-limit=512M` exits 0
- **Committed in:** `eed4a3e` (Task 1)

**2. [Rule 2 - Missing Critical] phpstan-extension-dogfood.neon needs --memory-limit=512M flag**
- **Found during:** Task 1 (dogfood run crash with PHP memory limit 128M exceeded)
- **Issue:** PHPStan's parallel worker hit the PHP 128M default memory limit when loading `extension.neon` + parametersSchema + all 93 src files. The bundle's `phpunit.xml.dist` already has `memory_limit=512M` but this does not apply to phpstan CLI.
- **Fix:** Added `--memory-limit=512M` to the dogfood command in CI (`ci.yml`) and used it for local verification runs. The `phpstan-extension-dogfood.neon` itself does not set memory_limit (that is a CLI flag, not a neon parameter).
- **Files modified:** `.github/workflows/ci.yml`
- **Verification:** All dogfood runs with `--memory-limit=512M` exit 0
- **Committed in:** `eed4a3e` (Task 1)

---

**Total deviations:** 2 auto-fixed (both Rule 2 missing critical — analysis-time config gaps)
**Impact on plan:** Both fixes necessary for dogfood to exit 0. The suppression mirrors what `phpstan.neon` already does — no new exclusion from rule coverage. The memory-limit flag is a CI infrastructure detail, not a correctness concern.

## Known Stubs

None — all three rules fully implemented (Plans 01–03). No placeholder code in the extension.

## Threat Surface Scan

No new network endpoints, auth paths, file access patterns, or schema changes introduced. `phpstan-extension-dogfood.neon` and the CI step are analysis-time artifacts only. No new threat surface.

## User Setup Required

None — no external service configuration required.

## Phase 28 Closure

All four plans complete. Phase 28 (phpstan-extension) is done:

| Plan | What shipped |
|------|-------------|
| 28-01 | extension.neon + MutualExclusionRule (Rule 1) + RuleTestCase harness |
| 28-02 | TenantIdDriftRule (Rule 3) full implementation with reflection fallback |
| 28-03 | SharedEntityLeakRule (Rule 2) conservative MethodCall rule; AttributeHierarchyHelper removed |
| 28-04 | Integration gate green; dogfood config + CI step; DX-03 AC1–AC5 mapped; manual verifications documented |

**DX-03 is complete.** The three shipped PHPStan rules catch `#[TenantAware]`/`#[Shared]` misuse at static-analysis time with CI standing protection.

---
*Phase: 28-phpstan-extension*
*Completed: 2026-06-16*
