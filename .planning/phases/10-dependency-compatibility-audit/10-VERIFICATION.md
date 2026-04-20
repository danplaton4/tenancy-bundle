---
phase: 10-dependency-compatibility-audit
verified: 2026-04-10T20:00:00Z
resolved: 2026-04-21T00:00:00Z
status: resolved
score: 13/13 must-haves verified
overrides_applied: 0
human_verification:
  - test: "Run CI prefer-lowest job on GitHub Actions"
    expected: "vendor/bin/phpunit exits 0 with oldest stable deps installed at Symfony 7.4.* floor"
    status: resolved
    resolution: "CI green on master — latest successful run 2026-04-19 (gh run 24631123976). `prefer-lowest` job defined in .github/workflows/ci.yml and passing."
  - test: "Run CI no-messenger job on GitHub Actions"
    expected: "phpunit passes with symfony/messenger removed, confirming interface_exists guards"
    status: resolved
    resolution: "CI green on master — latest successful run 2026-04-19 (gh run 24631123976). `no-messenger` job defined in .github/workflows/ci.yml and passing."
---

# Phase 10: Dependency Compatibility Audit Verification Report

**Phase Goal:** Audit and fix all dependency compatibility issues to ensure the bundle works reliably across PHP 8.2/8.3/8.4 x Symfony 7.4/8.0 with all optional dependency combinations. Produce a formal audit report in `.planning/`, fix all issues found (PHP 8.4-only syntax, unguarded imports, deprecated APIs), and expand CI to cover all supported combos including edge cases.
**Verified:** 2026-04-10T20:00:00Z
**Status:** human_needed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

All truths derived from PLAN frontmatter must_haves (ROADMAP has no success_criteria array for Phase 10). 13 truths total across both plans.

**Plan 01 Truths (D-01, D-02, D-03, D-04, D-05, D-06, D-08):**

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | All Symfony require constraints read ^7.4\|\|^8.0 (not ^7.0\|\|^8.0) | VERIFIED | composer.json lines 22-29: all 8 require Symfony packages show `^7.4\|\|^8.0`; grep for `7.0` returns 0 |
| 2 | All Symfony require-dev constraints read ^7.4\|\|^8.0 | VERIFIED | composer.json lines 39-41: framework-bundle, messenger, phpunit-bridge all `^7.4\|\|^8.0` |
| 3 | All Symfony suggest entries read ^7.4\|\|^8.0 | VERIFIED | composer.json line 48: `symfony/messenger` suggest entry contains `^7.4\|\|^8.0` |
| 4 | AUDIT-REPORT.md exists in .planning/phases/10-dependency-compatibility-audit/ | VERIFIED | File exists at `.planning/phases/10-dependency-compatibility-audit/AUDIT-REPORT.md` (366 lines) |
| 5 | AUDIT-REPORT.md documents every src/ file, its optional imports, guard status, and compatibility | VERIFIED | Guard Audit table covers 16 src/ files with optional imports, guard mechanism, and safety assessment |
| 6 | AUDIT-REPORT.md has a v1.1 dependency section covering Flysystem and Mailer constraints | VERIFIED | Section `## v1.1 Dependency Compatibility (D-02)` covers league/flysystem-bundle, league/flysystem, symfony/mailer |
| 7 | No PHP 8.4-only syntax exists in src/ (property hooks, asymmetric visibility) | VERIFIED | grep for `private(set)`, `protected(set)`, `public(set)`, `{ get {`, `{ set(` all return 0 matches across all src/ files |
| 8 | phpunit.xml.dist contains SYMFONY_DEPRECATIONS_HELPER env var | VERIFIED | phpunit.xml.dist line 23: `<env name="SYMFONY_DEPRECATIONS_HELPER" value="max[direct]=0"/>` |

**Plan 02 Truths (D-07, D-09, D-10, D-11):**

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 9 | CI has a prefer-lowest job that runs composer update --prefer-lowest --prefer-stable | VERIFIED | ci.yml lines 112-130: `prefer-lowest` job with `composer-options: --prefer-lowest --prefer-stable`, `SYMFONY_REQUIRE: '7.4.*'`, PHP 8.2 |
| 10 | CI has a no-messenger job that removes symfony/messenger and runs unit tests | VERIFIED | ci.yml lines 132-150: `no-messenger` job with `composer remove --dev symfony/messenger`, runs all unit dirs except Unit/Messenger, includes DependencyInjection |
| 11 | CI Symfony 8 + DoctrineBundle 3.x job exists as a separate job | VERIFIED | ci.yml lines 20-22: `include: - php: '8.4', symfony: '8.0.*'` matrix entry in `tests` job; DoctrineBundle 3.x resolves via Composer platform on PHP 8.4 |
| 12 | No reference to Symfony 6.4 exists in REQUIREMENTS.md | VERIFIED | grep for `6.4` in REQUIREMENTS.md returns empty; OSS-01 reads `^7.4\|\|^8.0`, OSS-04 reads `7.4/8.0` |
| 13 | No reference to Symfony 6.4 exists in PROJECT.md | VERIFIED | grep for `6.4` in PROJECT.md returns empty; Context section reads `Symfony 7.4+ / 8.x`, Constraints section reads `Symfony 7.4/8.x` |

**Score:** 13/13 truths verified

### Deferred Items

None — all phase items are addressed within this phase.

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `composer.json` | Updated Symfony version constraints | VERIFIED | All 11 Symfony packages at `^7.4\|\|^8.0`; php stays `^8.2`; Doctrine unchanged; commit 668fd6c |
| `.planning/phases/10-dependency-compatibility-audit/AUDIT-REPORT.md` | Formal dependency compatibility audit | VERIFIED | 366 lines, all required sections present: Guard Audit, PHP Syntax Compatibility, v1.1 Dependency Compatibility, Discretion Decisions |
| `phpunit.xml.dist` | Deprecation detection config | VERIFIED | `<env name="SYMFONY_DEPRECATIONS_HELPER" value="max[direct]=0"/>` in `<php>` block after `</source>`; commit 11bd5a1 |
| `.github/workflows/ci.yml` | Expanded CI matrix with prefer-lowest and no-messenger jobs | VERIFIED | Both jobs present; commit 43c2cb7 adds 41 lines |
| `.planning/REQUIREMENTS.md` | Updated version references | VERIFIED | No `Symfony 6.4` references remain; 7.4/8.0 terminology throughout |
| `.planning/PROJECT.md` | Updated version references | VERIFIED | No `Symfony 6.4` references remain; Context and Constraints sections updated |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `composer.json (symfony/cache)` | `src/Cache/TenantAwareCacheAdapter.php` | `NamespacedPoolInterface` requires cache-contracts ^3.6 (Symfony 7.4+) | WIRED | `composer.json` constraint `^7.4\|\|^8.0` guarantees cache-contracts 3.6+ is always installed; interface always present |
| `ci.yml (prefer-lowest)` | `composer.json constraints` | `composer update --prefer-lowest` validates floor constraints | WIRED | Job runs `--prefer-lowest --prefer-stable` with `SYMFONY_REQUIRE: '7.4.*'`; catches any floor violations |
| `ci.yml (no-messenger)` | `src/ Messenger guards` | `composer remove --dev symfony/messenger` validates interface_exists guards at runtime | WIRED | Job removes messenger, runs phpunit on all unit dirs except Unit/Messenger (including DependencyInjection to verify MessengerMiddlewarePass guard) |

### Data-Flow Trace (Level 4)

Not applicable. This phase produces configuration artifacts (composer.json, phpunit.xml.dist, ci.yml) and a documentation artifact (AUDIT-REPORT.md) — no components that render dynamic data.

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Unit tests pass with deprecation detection active | `vendor/bin/phpunit --testsuite unit` | 162 tests, 404 assertions, OK | PASS |
| PHPStan level 9 passes (PHP 8.2 compat) | `vendor/bin/phpstan analyse` | `[OK] No errors` | PASS |
| No ^7.0 constraints remain in composer.json | `grep -c '7\.0' composer.json` | `0` | PASS |
| prefer-lowest CI job exists and is correctly configured | code inspection | PHP 8.2, `--prefer-lowest --prefer-stable`, `SYMFONY_REQUIRE: '7.4.*'` | PASS |
| no-messenger CI job exists and is correctly configured | code inspection | PHP 8.2, removes messenger, runs 10 unit dirs exc. Unit/Messenger, incl. DependencyInjection | PASS |

### Requirements Coverage

These requirement IDs are phase-internal context decisions (D-01 to D-11 defined in 10-CONTEXT.md), not listed in REQUIREMENTS.md. The REQUIREMENTS.md lists OSS-01 (Packagist-ready composer.json) and OSS-04 (CI matrix) — both are declared as Phase 9 scope and remain Pending. The D-series decisions are implementation decisions for this phase, not v1 requirements.

| Req ID | Source Plan | Description | Status | Evidence |
|--------|-------------|-------------|--------|----------|
| D-01 | Plan 01 | Full audit report documenting all dependency interactions | SATISFIED | AUDIT-REPORT.md (366 lines, 10 sections) |
| D-02 | Plan 01 | Audit covers v1.1 deps (Flysystem, Mailer) — constraints only | SATISFIED | `## v1.1 Dependency Compatibility` section in AUDIT-REPORT.md |
| D-03 | Plan 01 | Audit report lives in .planning/ only | SATISFIED | AUDIT-REPORT.md at `.planning/phases/10-dependency-compatibility-audit/` |
| D-04 | Plan 01 | PHP 8.4-only syntax scan (property hooks, asymmetric visibility) | SATISFIED | Scan results in `## PHP Syntax Compatibility` section; 0 matches |
| D-05 | Plan 01 | Deprecation detection enabled; run with SYMFONY_DEPRECATIONS_HELPER | SATISFIED | phpunit.xml.dist has `max[direct]=0`; 162 tests pass |
| D-06 | Plan 01 | Comprehensive class_exists/interface_exists guard audit | SATISFIED | Guard Audit table covers 16 files, all guards verified or documented as acceptable |
| D-07 | Plan 02 | Symfony 6.4 references removed from REQUIREMENTS.md and PROJECT.md | SATISFIED | grep returns 0 matches for `6.4` in both files |
| D-08 | Plan 01 | PHP 8.2+ floor; no PHP 8.4-only syntax in src/ | SATISFIED | PHPStan level 9 passes; no property hooks or asymmetric visibility found |
| D-09 | Plan 02 | prefer-lowest CI job added | SATISFIED | ci.yml has `prefer-lowest` job with correct config |
| D-10 | Plan 02 | no-messenger CI job added | SATISFIED | ci.yml has `no-messenger` job; mirrors no-doctrine pattern |
| D-11 | Plan 02 | Symfony 8 + DoctrineBundle 3.x separate job confirmed | SATISFIED | Existing matrix include `php: '8.4', symfony: '8.0.*'` confirmed present |

**Orphaned REQUIREMENTS.md entries for Phase 10:** None. The D-series IDs are context decisions, not REQUIREMENTS.md line items. OSS-01 and OSS-04 are attributed to Phase 9, not Phase 10.

### Anti-Patterns Found

None. Scanned composer.json, phpunit.xml.dist, .github/workflows/ci.yml for TODO/FIXME/placeholder patterns — all clean. No stubs or empty implementations. All three commits produce substantive, wired changes.

### Human Verification Required

#### 1. prefer-lowest CI Job Runtime Validation

**Test:** Trigger the `prefer-lowest` CI job on GitHub Actions (push to master or open a PR)
**Expected:** `vendor/bin/phpunit` exits 0 after Composer installs the oldest stable versions of all dependencies at `SYMFONY_REQUIRE=7.4.*` — no floor constraint violations, no missing class errors, no deprecation failures
**Why human:** Cannot safely run `ramsey/composer-install` with `--prefer-lowest --prefer-stable` in the local working copy without downgrading installed packages. The job configuration is verified correct by code inspection; runtime validation requires the CI environment.

#### 2. no-messenger CI Job Runtime Validation

**Test:** Trigger the `no-messenger` CI job on GitHub Actions (push to master or open a PR)
**Expected:** `composer remove --dev symfony/messenger` succeeds, then `vendor/bin/phpunit tests/Unit/Context tests/Unit/Event ... tests/Unit/EventListener` exits 0 — all `interface_exists(MessageBusInterface::class)` guards prevent fatal class-not-found errors throughout the test run
**Why human:** Cannot safely run `composer remove --dev symfony/messenger` in the local dev environment without destroying the Messenger test suite. The guard logic was verified by reading the guard points in `src/TenancyBundle.php`, `src/DependencyInjection/Compiler/MessengerMiddlewarePass.php`, and `config/services.php`; runtime validation requires CI.

### Gaps Summary

No gaps found. All 13 must-haves are verified. Two items require human CI validation (prefer-lowest and no-messenger runtime behavior) — these cannot be automated without modifying the local dev environment. All automated checks pass: 162 unit tests OK, PHPStan level 9 clean, 0 instances of `^7.0` in composer.json, all required AUDIT-REPORT.md sections present, all CI job configurations correct.

---

_Verified: 2026-04-10T20:00:00Z_
_Verifier: Claude (gsd-verifier)_
