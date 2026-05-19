---
phase: 10-dependency-compatibility-audit
reviewed: 2026-04-10T00:00:00Z
depth: standard
files_reviewed: 3
files_reviewed_list:
  - composer.json
  - phpunit.xml.dist
  - .github/workflows/ci.yml
findings:
  critical: 0
  warning: 4
  info: 3
  total: 7
status: issues_found
---

# Phase 10: Code Review Report

**Reviewed:** 2026-04-10
**Depth:** standard
**Files Reviewed:** 3
**Status:** issues_found

## Summary

This review covers the dependency compatibility audit changes: tightening Symfony lower-bounds from `^7.0` to `^7.4` in `composer.json`, adding `SYMFONY_DEPRECATIONS_HELPER` to `phpunit.xml.dist`, and adding the `prefer-lowest` and `no-messenger` CI jobs in `.github/workflows/ci.yml`.

The version-tightening in `composer.json` is correct and consistent — all `symfony/*` constraints were updated together. However, `doctrine/doctrine-bundle: ^2.13||^3.0` in `require-dev` still permits installation of DoctrineBundle 2.x, which does not support Symfony 7.x. This dead range can mislead contributors. The CI additions are sound in intent, but several new and existing jobs omit `SYMFONY_REQUIRE`, making the Symfony version used in those jobs non-deterministic. The matrix also has a coverage gap: PHP 8.2 and 8.3 are never tested against Symfony 8.0.

---

## Warnings

### WR-01: `doctrine/doctrine-bundle ^2.13` dead range — incompatible with Symfony 7.x

**File:** `composer.json:33`
**Issue:** `require-dev` lists `doctrine/doctrine-bundle: "^2.13||^3.0"`. DoctrineBundle 2.x does not support Symfony 7.x (it targets Symfony 5.4–6.x). With the Symfony lower bound now at `^7.4`, Composer will never install a DoctrineBundle 2.x release that satisfies both constraints simultaneously. The `^2.13` side of the constraint is a dead range that cannot be satisfied in this project's resolved dependency set, yet it misleads contributors about what is actually supported.

**Fix:** Remove the dead `^2.13` range and allow only `^3.0`, which supports Symfony 6.3+:
```json
"doctrine/doctrine-bundle": "^3.0"
```
Also update the `suggest` entry if it still references `^2.13`.

---

### WR-02: `no-doctrine` and `no-messenger` CI jobs omit `SYMFONY_REQUIRE` — non-deterministic Symfony version

**File:** `.github/workflows/ci.yml:104`, `.github/workflows/ci.yml:143`
**Issue:** Both `no-doctrine` and `no-messenger` jobs call `ramsey/composer-install@v3` without setting `SYMFONY_REQUIRE`. Composer will freely resolve the highest allowed Symfony version (currently 8.0.*), making these jobs silently test a different Symfony version than the standard `tests` matrix. If a guard fails under Symfony 8.0 but is only intended to be tested against Symfony 7.4, the inconsistency will go undetected. The `prefer-lowest` job correctly pins `SYMFONY_REQUIRE: '7.4.*'`, but the guard-checking jobs do not follow the same discipline.

**Fix:** Pin both guard jobs to a stable Symfony version:
```yaml
# no-doctrine job (line ~104)
      - uses: ramsey/composer-install@v3
        env:
          SYMFONY_REQUIRE: '7.4.*'

# no-messenger job (line ~143)
      - uses: ramsey/composer-install@v3
        env:
          SYMFONY_REQUIRE: '7.4.*'
```

---

### WR-03: CI matrix never tests PHP 8.2 or 8.3 against Symfony 8.0

**File:** `.github/workflows/ci.yml:17-22`
**Issue:** The `tests` matrix includes `symfony: ['7.4.*']` for PHP 8.2, 8.3, and 8.4, and adds `symfony: '8.0.*'` only as a PHP 8.4 include. Both PHP 8.2+ and 8.3 are valid targets for Symfony 8.0 (which requires PHP 8.2+). Any Symfony 8.0 incompatibility that manifests only on PHP 8.2 or 8.3 will go undetected. This is a real gap because deprecation-handling and constructor-promotion edge cases can differ across PHP minor versions.

**Fix:** Expand the matrix to include Symfony 8.0 for PHP 8.2 and 8.3:
```yaml
matrix:
  php: ['8.2', '8.3', '8.4']
  symfony: ['7.4.*', '8.0.*']
```
If CI run cost is a concern, at minimum add PHP 8.3/Symfony 8.0 as an additional include alongside the current PHP 8.4/Symfony 8.0 entry.

---

### WR-04: `no-messenger` job missing newline at end of file

**File:** `.github/workflows/ci.yml:150`
**Issue:** The `no-messenger` job's final `run:` line has no trailing newline. This is technically valid YAML but causes the `git diff` output to show `\ No newline at end of file`. In some CI parsers and GitHub UI rendering this can surface noise. The `no-doctrine` job had this same issue in the previous commit and was fixed; `no-messenger` has it again.

**Fix:** Add a trailing newline after line 150 in `.github/workflows/ci.yml`.

---

## Info

### IN-01: `phpunit.xml.dist` schema URL pinned to `11.0` — will drift as PHPUnit 11.x is updated

**File:** `phpunit.xml.dist:3`
**Issue:** `xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/11.0/phpunit.xsd"` is pinned to `11.0`. `composer.json` allows `^11.0`, which installs any 11.x release. While PHPUnit ships updated schemas for each minor (e.g., `11.3/phpunit.xsd`), `11.0` is still fetched and remains valid — so this does not break tests. But if PHPUnit adds new attributes in a later 11.x release and they are used in this file, the schema will report false warnings in IDEs.

**Fix:** Update the schema version when the minimum installed PHPUnit version is bumped, or use the version-less schema URL pattern if available. No immediate action required.

---

### IN-02: `phpstan` CI job has no `SYMFONY_REQUIRE` — analysis version is uncontrolled

**File:** `.github/workflows/ci.yml:73`
**Issue:** The `phpstan` job calls `ramsey/composer-install@v3` without `SYMFONY_REQUIRE`. PHPStan will analyse code with whichever Symfony version Composer resolves (likely 8.0.*). This means PHPStan may flag stubs or type changes specific to Symfony 8.0 while the primary test target is Symfony 7.4, or vice versa. For a library targeting both, running PHPStan against both versions would be ideal; running it against neither pinned version is the worst of both.

**Fix:** Pin PHPStan to the oldest supported Symfony version to catch forward-compatibility issues:
```yaml
      - uses: ramsey/composer-install@v3
        env:
          SYMFONY_REQUIRE: '7.4.*'
```
Or add two PHPStan jobs (one per Symfony major) if strictness is desired.

---

### IN-03: `coverage` CI job only covers Symfony 7.4 — Symfony 8.0 coverage data not collected

**File:** `.github/workflows/ci.yml:52`
**Issue:** The `coverage` job pins `SYMFONY_REQUIRE: '7.4.*'`. Coverage reports submitted to Codecov therefore only reflect Symfony 7.4 paths. If there are conditional branches in the source code that activate under Symfony 8.0, those branches will never appear as covered. This is informational — the coverage report is technically accurate for its stated scope but incomplete across the full compatibility matrix.

**Fix:** Either add a second coverage upload for Symfony 8.0 or document that coverage is intentionally measured against the LTS (7.4) only. No breaking behaviour.

---

_Reviewed: 2026-04-10_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
