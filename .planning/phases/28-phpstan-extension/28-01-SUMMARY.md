---
phase: 28-phpstan-extension
plan: 01
subsystem: testing
tags: [phpstan, phpstan-extension, phpstan-rules, phpstan-doctrine, static-analysis, extension-installer]

# Dependency graph
requires:
  - phase: 25-shared-entity-foundation
    provides: "Shared/TenantAware attributes + SharedEntityMutualExclusionPass hasAttributeInHierarchy() logic extracted here as AttributeHierarchyHelper"

provides:
  - "Shipped extension.neon registering 3 PHPStan rules + AttributeHierarchyHelper service, auto-loaded by phpstan/extension-installer"
  - "AttributeHierarchyHelper — ancestor-walking attribute detector extracted from SharedEntityMutualExclusionPass"
  - "MutualExclusionRule (Rule 1) — fully implemented: detects classes carrying both #[Shared] and #[TenantAware] incl. inherited"
  - "TenantIdDriftRule skeleton (Rule 3) + SharedEntityLeakRule skeleton (Rule 2) — DI-wired, processNode() returns [] until Plans 02/03"
  - "RuleTestCase test suite for Rule 1 (3 tests: violation, hierarchy, clean)"
  - "PSR-4 fixture classes in tests/Unit/PHPStan/Rule/Fixtures/ namespace"
  - "phpunit.xml.dist memory_limit=512M (prevents OOM in git pre-commit hook)"

affects:
  - 28-02
  - 28-03
  - "any phase adding new Shared or TenantAware entity classes (rule enforcement)"

# Tech tracking
tech-stack:
  added:
    - "phpstan/extension-installer ^1.4 (1.4.3) — dev-only; detects via extra.phpstan.includes"
    - "phpstan/phpstan-doctrine ^2.0 (2.0.25) — dev-only; Doctrine metadata for Rule 3 (Plan 02)"
  patterns:
    - "PHPStan extension shipped via extension.neon at package root, auto-loaded by extension-installer"
    - "Rule hierarchy walk via PHPStan ClassReflection.getParentClass() loop (not PHP native reflection) to avoid BetterReflection adapter type incompatibility at level 9"
    - "PHPStan RuleTestCase with getAdditionalConfigFiles returning extension.neon path (4 levels up)"
    - "PSR-4 fixture classes per-file in tests/Unit/PHPStan/Rule/Fixtures/ (one class per file for autoloading)"

key-files:
  created:
    - extension.neon
    - src/PHPStan/Rule/Helper/AttributeHierarchyHelper.php
    - src/PHPStan/Rule/MutualExclusionRule.php
    - src/PHPStan/Rule/TenantIdDriftRule.php
    - src/PHPStan/Rule/SharedEntityLeakRule.php
    - tests/Unit/PHPStan/Rule/MutualExclusionRuleTest.php
    - tests/Unit/PHPStan/Rule/Fixtures/BothAttributesViolating.php
    - tests/Unit/PHPStan/Rule/Fixtures/SharedParentViolating.php
    - tests/Unit/PHPStan/Rule/Fixtures/TenantAwareChildViolating.php
    - tests/Unit/PHPStan/Rule/Fixtures/OnlySharedClean.php
    - tests/Unit/PHPStan/Rule/Fixtures/OnlyTenantAwareClean.php
  modified:
    - composer.json
    - phpstan.neon
    - phpunit.xml.dist
    - src/PHPStan/Rule/Helper/AttributeHierarchyHelper.php

key-decisions:
  - "MutualExclusionRule uses PHPStan ClassReflection.getParentClass() loop instead of the AttributeHierarchyHelper, because ClassReflection.getNativeReflection() returns BetterReflection adapter types incompatible with ReflectionClass<object> at PHPStan level 9; AttributeHierarchyHelper is retained for Plans 02/03 which will find a compatible usage pattern"
  - "Fixture classes split into one-file-per-class in tests/Unit/PHPStan/Rule/Fixtures/ (PSR-4 compliant) instead of multi-class files in data/ directory, because PHPStan RuleTestCase needs fixture classes to be PHP-autoloadable via composer autoload-dev (Tenancy\\Bundle\\Tests\\ maps to tests/)"
  - "phpunit.xml.dist memory_limit=512M added because the git pre-commit hook calls vendor/bin/phpunit without a memory limit, causing OutOfMemoryError at 128M default with 749 tests"
  - "composer.json type stays symfony-bundle (NOT phpstan-extension) — extension-installer detects by extra.phpstan.includes, not by type field; changing type would break Symfony bundle discovery"
  - "phpstan-doctrine 2.0.25 installed cleanly against phpstan 2.1.50; the research's concern about ^2.2.2 minimum was moot (2.0.25 satisfies ^2.0 which covers 2.1.x)"

patterns-established:
  - "PHPStan rule hierarchy walk: use ClassReflection loop (not native ReflectionClass) to avoid BetterReflection adapter type issues at level 9"
  - "PHPStan test fixture PSR-4: one class per file in Fixtures/ subdirectory under the test namespace"
  - "extension.neon DI: MutualExclusionRule registered without helper argument; skeleton rules wired with helper"
  - "phpstan.neon ignoreErrors with message regex uses single-backslash pairs (\\Bundle) for namespace separator matching"

requirements-completed: [DX-03]

# Metrics
duration: 105min
completed: 2026-06-16
---

# Phase 28 Plan 01: PHPStan Extension Foundation + Rule 1 (MutualExclusionRule) Summary

**PHPStan extension shipped via extension.neon with MutualExclusionRule detecting #[Shared]+#[TenantAware] co-presence (including inheritance) using PHPStan ClassReflection hierarchy walk — Rules 2/3 skeleton wired, RuleTestCase harness proven**

## Performance

- **Duration:** ~105 min
- **Started:** 2026-06-16 (continuation from previous session)
- **Completed:** 2026-06-16T15:38:59Z
- **Tasks:** 3 (Task 1: legitimacy gate approved pre-session; Task 2: infrastructure; Task 3: TDD implementation)
- **Files modified:** 13

## Accomplishments

- Installed phpstan/extension-installer 1.4.3 and phpstan/phpstan-doctrine 2.0.25 as dev deps; wired extension.neon auto-discovery via composer.json extra.phpstan.includes
- Shipped extension.neon registering all 3 rules + AttributeHierarchyHelper with parametersSchema for checkSharedEntityLeaks parameter
- Implemented MutualExclusionRule (Rule 1) end-to-end: fires on direct AND inherited #[Shared]+#[TenantAware] co-presence; identifier `tenancy.mutualExclusion`; RuleTestCase with 3 tests all green
- PHPStan level 9 self-analysis clean (749 tests pass, 0 phpstan errors)

## Task Commits

1. **Task 1: Package legitimacy gate** — approved by orchestrator/user pre-session (phpstan/extension-installer and phpstan/phpstan-doctrine both verified as official phpstan org packages)
2. **Task 2: Install dev deps + wire extension** — `e84cf68` (feat)
3. **Task 3: Implement MutualExclusionRule (TDD)** — `737b354` (feat)

## Files Created/Modified

- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/extension.neon` — Consumer-facing PHPStan extension; parametersSchema + 3 rules + helper; auto-loaded by extension-installer
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/src/PHPStan/Rule/Helper/AttributeHierarchyHelper.php` — Ancestor-walk helper (retained for Plans 02/03; extracted from SharedEntityMutualExclusionPass)
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/src/PHPStan/Rule/MutualExclusionRule.php` — Rule 1; uses ClassReflection.getParentClass() loop; identifier tenancy.mutualExclusion
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/src/PHPStan/Rule/TenantIdDriftRule.php` — Skeleton Rule 3 (processNode returns [])
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/src/PHPStan/Rule/SharedEntityLeakRule.php` — Skeleton Rule 2 (processNode returns [])
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/tests/Unit/PHPStan/Rule/MutualExclusionRuleTest.php` — RuleTestCase; loads extension.neon via getAdditionalConfigFiles; 3 tests
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/tests/Unit/PHPStan/Rule/Fixtures/` — 5 PSR-4 fixture classes (BothAttributesViolating at line 10, TenantAwareChildViolating at line 9, SharedParentViolating, OnlySharedClean, OnlyTenantAwareClean)
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/composer.json` — Added require-dev, extra.phpstan.includes, suggest, allow-plugins
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/phpstan.neon` — Added property.onlyWritten suppression for skeleton rules (regex: `\\Bundle\\PHPStan\\Rule\\`)
- `/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/phpunit.xml.dist` — Added memory_limit=512M

## Decisions Made

1. **MutualExclusionRule uses ClassReflection loop, not AttributeHierarchyHelper**: `ClassReflection.getNativeReflection()` returns `BetterReflection\Reflection\Adapter\ReflectionClass|ReflectionEnum`, incompatible with `ReflectionClass<object>` at PHPStan level 9. The helper still exists for Plans 02/03 which may find a compatible usage (e.g., passing native PHP reflection via a different codepath).

2. **Fixture classes PSR-4 split, not multi-class data/ files**: The original plan used `data/mutual-exclusion-violating.php` with multiple classes under `Fixtures\PHPStan` namespace. PHPStan RuleTestCase's `AutoloadSourceLocator` uses PHP's autoloader — so fixture classes must be in composer's autoload-dev map. The existing `Tenancy\Bundle\Tests\ → tests/` mapping covers `tests/Unit/PHPStan/Rule/Fixtures/` with PSR-4, but requires one class per file.

3. **phpunit.xml.dist memory_limit=512M**: The git pre-commit hook calls `vendor/bin/phpunit` without a memory limit. With 749 integration tests, PHP's default 128M was exhausted, causing the hook to block commits. Adding the limit to `phpunit.xml.dist` is the correct project-level fix (not modifying the git hook).

4. **phpstan-doctrine 2.0.25 installs cleanly**: Research's concern about potential ^2.2.2 minimum requirement was moot. 2.0.25 satisfies `^2.0` and installs against phpstan 2.1.50 with no version conflicts.

5. **getAdditionalConfigFiles path: `__DIR__.'/../../../../extension.neon'`**: 4 levels up from `tests/Unit/PHPStan/Rule/` to the package root. This is the exact path that loads the consumer extension.neon during RuleTestCase.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Pre-existing integration test failures from Phase 27 unconditional Messenger bus wiring**
- **Found during:** Task 2 (pre-commit hook blocked commit)
- **Issue:** Phase 27-02 wired the bus to SharedEntitySyncSubscriber unconditionally when `MessageBusInterface` existed, regardless of `tenancy.shared_entity.async` setting. With bus wired, postFlush() took the async path and `$this->landlordEm->clear()` detached entities the test held, causing subsequent UPDATE to be a no-op. Also, one test installed a BEFORE INSERT trigger without cleanup, contaminating subsequent test runs.
- **Fix:** Gated bus injection on `$sharedAsync && interface_exists(MessageBusInterface::class)` in `TenancyBundle.php`; added trigger cleanup in `SharedEntitySyncIntegrationTest::setUp()`
- **Files modified:** `src/TenancyBundle.php`, `tests/Integration/SharedEntity/SharedEntitySyncIntegrationTest.php`
- **Verification:** All 749 tests pass (was 746 before; 3 added by this plan)
- **Committed in:** `e84cf68` (Task 2)

**2. [Rule 1 - Bug] PHPStan level 9 type error: BetterReflection adapter incompatible with ReflectionClass<object>**
- **Found during:** Task 3 (phpstan analyse failed during pre-commit hook)
- **Issue:** `ClassReflection::getNativeReflection()` returns `BetterReflection\Reflection\Adapter\ReflectionClass|ReflectionEnum`, which is not assignable to `ReflectionClass<object>` due to invariant generic template type T in PHPStan level 9
- **Fix:** Moved hierarchy walk inline in MutualExclusionRule using `ClassReflection::getParentClass()` API instead of going through AttributeHierarchyHelper; removed helper from MutualExclusionRule constructor; updated extension.neon and test accordingly
- **Files modified:** `src/PHPStan/Rule/MutualExclusionRule.php`, `extension.neon`, `tests/Unit/PHPStan/Rule/MutualExclusionRuleTest.php`
- **Verification:** `vendor/bin/phpstan analyse` exits 0
- **Committed in:** `737b354` (Task 3)

**3. [Rule 3 - Blocking] Fixture autoload: Fixtures\PHPStan namespace not in composer autoload-dev**
- **Found during:** Task 3 RED phase (RuleTestCase analyse failed with "Class not found in ReflectionProvider")
- **Issue:** Original plan specified fixtures in `data/` with namespace `Fixtures\PHPStan`. PHPStan's `AutoloadSourceLocator` uses PHP's autoloader — so `Fixtures\PHPStan` (not in any autoload map) was invisible to RuleTestCase
- **Fix:** Changed fixture structure to PSR-4-compliant one-class-per-file in `tests/Unit/PHPStan/Rule/Fixtures/` under `Tenancy\Bundle\Tests\Unit\PHPStan\Rule\Fixtures` namespace (covered by existing `Tenancy\Bundle\Tests\` → `tests/` mapping)
- **Files modified:** All fixture files, `tests/Unit/PHPStan/Rule/MutualExclusionRuleTest.php`
- **Verification:** RuleTestCase finds all fixture classes, all 3 tests pass
- **Committed in:** `737b354` (Task 3)

**4. [Rule 2 - Missing Critical] phpunit.xml.dist memory_limit=512M**
- **Found during:** Task 3 (git pre-commit hook blocked commit with OutOfMemoryError)
- **Issue:** Git pre-commit hook runs `vendor/bin/phpunit --no-progress` without a memory limit. PHP default 128M is insufficient for 749 tests (113M peak). This blocked every commit attempt.
- **Fix:** Added `<ini name="memory_limit" value="512M"/>` to `phpunit.xml.dist`
- **Files modified:** `phpunit.xml.dist`
- **Verification:** `git commit` succeeds; hook exits 0
- **Committed in:** `737b354` (Task 3)

**5. [Rule 1 - Bug] phpstan.neon suppression regex: incorrect backslash escaping**
- **Found during:** Task 2/3 (phpstan still reported property.onlyWritten for skeleton rules despite suppression)
- **Issue:** NEON single-quoted string `\\Bundle` passes two chars to the regex, where `\B` is an invalid PCRE escape (`\P` = Unicode property assertion requires `{...}`). Required `\\\\` (four backslashes in neon) to produce `\\` in the regex (matching one literal backslash)... but testing showed the final correct pattern is `\\Bundle` in the actual regex string, which means the NEON value must be `\\Bundle` in single-quote (two backslashes = regex `\\` = matches one backslash). After testing, `#^Property Tenancy\\Bundle\\PHPStan\\Rule\\(TenantIdDriftRule|SharedEntityLeakRule)#` in the NEON value works correctly.
- **Files modified:** `phpstan.neon`
- **Verification:** `vendor/bin/phpstan analyse` exits 0 with skeleton rules suppressed
- **Committed in:** `737b354` (Task 3)

---

**Total deviations:** 5 auto-fixed (2 Rule 1 bugs, 1 Rule 2 missing critical, 1 Rule 3 blocking, 1 Rule 1 bug)
**Impact on plan:** All fixes necessary for correct operation. No scope creep. The fixture PSR-4 change improves the structure over the plan's proposal.

## Issues Encountered

- **PHPStan level 9 + BetterReflection generics**: `ClassReflection::getNativeReflection()` does not return `\ReflectionClass<object>` but rather BetterReflection adapter types. The hierarchy walk had to be inlined using PHPStan's own ClassReflection API rather than delegating to the helper. The helper remains available for Plans 02/03 which may have different codepaths.
- **NEON backslash escaping**: NEON single-quoted strings do not process escape sequences. `\\B` in a NEON string literal passes `\B` to PCRE which is not `\b` (word boundary) but is treated as invalid `\B` in some contexts. Working around this required careful testing of the exact regex content that NEON delivers to PHPStan.

## Known Stubs

- `src/PHPStan/Rule/TenantIdDriftRule::processNode()` returns `[]` — intentional skeleton; Plan 02 implements Rule 3 logic
- `src/PHPStan/Rule/SharedEntityLeakRule::processNode()` returns `[]` — intentional skeleton; Plan 03 implements Rule 2 logic

These stubs are intentional (documented in the plan) and do not prevent this plan's goal (Rule 1 + foundation wired).

## User Setup Required

None — no external service configuration required. phpstan/extension-installer and phpstan/phpstan-doctrine were installed as dev-only Packagist packages.

## Next Phase Readiness

- **Plan 02 (TenantIdDriftRule)**: AttributeHierarchyHelper available; extension.neon wired; skeleton TenantIdDriftRule in place. Need to implement processNode() with Doctrine metadata lookup.
- **Plan 03 (SharedEntityLeakRule)**: Same foundation ready. skeleton SharedEntityLeakRule in place.
- **Consumers**: Any project running `composer require phpstan/extension-installer` will have the tenancy rules auto-loaded from extension.neon.

**Research note:** `getAdditionalConfigFiles()` uses path `__DIR__.'/../../../../extension.neon'` (confirmed working: 4 levels up from `tests/Unit/PHPStan/Rule/` reaches package root).

---
*Phase: 28-phpstan-extension*
*Completed: 2026-06-16*
