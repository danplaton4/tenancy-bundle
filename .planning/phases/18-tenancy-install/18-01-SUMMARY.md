---
phase: 18-tenancy-install
plan: 01
subsystem: testing
tags: [composer, php-parser, contract-test, phpunit, phpstan, dx]

# Dependency graph
requires:
  - phase: 12-developer-onboarding
    provides: tenancy:init command pattern (TenantInitCommand) and test infrastructure (CommandTestKernel)
provides:
  - "composer.json contract: nikic/php-parser ^5.0 in require-dev + suggest, absent from require"
  - "ComposerJsonContractTest: three PHPUnit assertions guarding DX-06 success criterion 5"
  - "TDD gate: T-INSTALL-03 build-fail guard active from this plan forward"
affects:
  - 18-tenancy-install (plans 02+: BundlesPhpInstaller and TenancyInstallCommand can safely depend on nikic/php-parser being a dev-only dep)
  - CI matrix (PHPUnit run will now fail if nikic/php-parser ever leaks into require)

# Tech tracking
tech-stack:
  added: ["nikic/php-parser ^5.0 (require-dev)"]
  patterns:
    - "Composer manifest contract test: json_decode + assertArrayNotHasKey/assertArrayHasKey on composer.json directly"
    - "PHPStan L9 + php-cs-fixer @Symfony compatibility: avoid inline @var annotation in PHPUnit test methods; use assertIsString to narrow mixed values before assertMatchesRegularExpression"

key-files:
  created:
    - "tests/Unit/Composer/ComposerJsonContractTest.php — three contract tests asserting nikic/php-parser placement in composer.json"
  modified:
    - "composer.json — nikic/php-parser ^5.0 added to require-dev (alphabetical) and suggest (with rationale string)"

key-decisions:
  - "nikic/php-parser declared in require-dev only (not require) per D-22 — locked contract prevents Wave 2+ accidental promotion"
  - "ComposerJsonContractTest uses assertIsString to narrow mixed array values for PHPStan L9 compliance — avoids inline @var cast which conflicts with php-cs-fixer phpdoc_to_comment rule"
  - "manifest() returns array<string, mixed> with is_array() narrowing per test method — cleaner than complex frontmatter type annotation under PHPStan L9 + @Symfony cs rules"

patterns-established:
  - "Composer contract test: load composer.json via json_decode with JSON_THROW_ON_ERROR, assert key presence/absence across require/require-dev/suggest sections"
  - "PHPStan L9 array narrowing in PHPUnit: use is_array() guard + assertIsString() instead of inline @var when dealing with array<string, mixed> return types"

requirements-completed: [DX-06]

# Metrics
duration: 4min
completed: 2026-05-18
---

# Phase 18 Plan 01: Composer Contract Lock Summary

**nikic/php-parser ^5.0 locked to require-dev + suggest via ComposerJsonContractTest (3 assertions), guarding T-INSTALL-03 supply-chain threat on every PHPUnit run**

## Performance

- **Duration:** 4 min
- **Started:** 2026-05-18T07:01:45Z
- **Completed:** 2026-05-18T07:05:20Z
- **Tasks:** 1 (TDD: 2 commits — RED + GREEN)
- **Files modified:** 2

## Accomplishments

- Added `nikic/php-parser: ^5.0` to `composer.json` `require-dev` block (alphabetical, between friendsofphp and phpstan)
- Added `nikic/php-parser` to `composer.json` `suggest` block with rationale string for tenancy:install
- Shipped `tests/Unit/Composer/ComposerJsonContractTest.php` with three contract assertions: absent from `require`, present in `require-dev` at `^5.x`, present in `suggest` with non-empty rationale
- PHPStan level 9 clean, php-cs-fixer @Symfony clean, `composer validate` clean
- T-INSTALL-03 (supply-chain tampering — accidental promotion to runtime `require`) mitigated

## Task Commits

TDD cycle (RED → GREEN):

1. **RED — test(18-01): add failing ComposerJsonContractTest** - `fa6bd06`
2. **GREEN — feat(18-01): composer.json additions + test fixes** - `0029419`

## Files Created/Modified

- `tests/Unit/Composer/ComposerJsonContractTest.php` — Three PHPUnit contract tests asserting nikic/php-parser composer.json placement; PHPStan L9 + php-cs-fixer @Symfony compliant
- `composer.json` — `nikic/php-parser: ^5.0` added to `require-dev` and `suggest`

## Decisions Made

- `manifest()` helper returns `array<string, mixed>` with per-test `is_array()` narrowing — avoids the `/** @var */` inline annotation that php-cs-fixer's `phpdoc_to_comment` rule changes to `/* @var */`, which PHPStan does not read.
- Used `assertIsString($version)` to narrow `mixed` to `string` before `assertMatchesRegularExpression` — satisfies PHPStan L9 `cast.string` error without type cast or `@phpstan-ignore`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] PHPStan L9 incompatibility: inline @var annotation conflict with php-cs-fixer**
- **Found during:** Task 18-01-01 (GREEN phase)
- **Issue:** The plan's canonical test skeleton used `/** @var array{...} $decoded */` inline annotation. PHPStan L9 requires `/** @var */` to narrow the type, but php-cs-fixer's `phpdoc_to_comment` rule converts it to `/* @var */`, which PHPStan does not read — creating an irreconcilable conflict on a single file.
- **Fix:** Changed `manifest()` return type to `array<string, mixed>`. Used `is_array()` guard + `assertIsString()` in each test method to narrow types without any inline annotation. Eliminates the conflict entirely.
- **Files modified:** `tests/Unit/Composer/ComposerJsonContractTest.php`
- **Verification:** `vendor/bin/phpstan analyse` exits 0; `vendor/bin/php-cs-fixer check --diff` shows empty diff; all 3 PHPUnit tests pass.
- **Committed in:** `0029419` (GREEN feat commit)

---

**Total deviations:** 1 auto-fixed (Rule 1 — bug in plan's canonical skeleton under PHPStan L9 + @Symfony cs rules)
**Impact on plan:** Minimal refactor within the test file only. All three behavioral assertions (absent from require, present in require-dev ^5.x, present in suggest with rationale) are preserved exactly as the plan specifies.

## Issues Encountered

PHPStan L9 `offsetAccess.notFound` errors on optional array keys, and a secondary `cast.string` error on `mixed` values — both resolved in GREEN phase via per-test `is_array()` narrowing and `assertIsString()`. No external dependencies or tooling issues.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- `nikic/php-parser ^5.0` is now an explicit `require-dev` entry; Wave 2 plans (`BundlesPhpInstaller`, `TenancyInstallCommand`) can safely reference PhpParser classes with confidence they are installed in all dev/CI environments.
- The contract test is the canonical guard for T-INSTALL-03 — it runs on every PHPUnit invocation and will fail the build if someone accidentally adds nikic/php-parser to `require`.
- `composer validate --no-check-publish` passes; `composer.json` is well-formed.

## TDD Gate Compliance

- RED gate: `fa6bd06` — `test(18-01)` commit with 2 failing tests (nikic absent from require-dev and suggest)
- GREEN gate: `0029419` — `feat(18-01)` commit making all 3 tests pass

Both gates present in git log. Compliance: PASSED.

## Self-Check: PASSED

- `tests/Unit/Composer/ComposerJsonContractTest.php` — FOUND
- `composer.json` — FOUND (nikic/php-parser in require-dev and suggest)
- RED commit `fa6bd06` — FOUND
- GREEN commit `0029419` — FOUND
- 3 PHPUnit tests pass, 0 PHPStan errors, 0 php-cs-fixer violations

---
*Phase: 18-tenancy-install*
*Completed: 2026-05-18*
