---
phase: 22-docs-refresh
plan: 01
subsystem: docs + composer
tags:
  - docs
  - composer
  - install-ux
  - one-command
requirements:
  - DOC-19
dependency_graph:
  requires: []
  provides:
    - "nikic/php-parser as a hard runtime dependency (composer.json#require)"
    - "One-command install flow on docs/user-guide/installation.md"
    - "One-command Quick Start on docs/index.md"
  affects:
    - "Plan 22-05 (cli-commands.md must publish a #tenancy-install anchor — installation.md links to it)"
    - "Plan 22-06 (docs-lint.sh + mkdocs --strict build will validate cross-links)"
tech_stack:
  added:
    - "nikic/php-parser ^5.0 (promoted from suggest to require)"
  patterns:
    - "One-command install UX (composer require + tenancy:install)"
key_files:
  created:
    - .planning/phases/22-docs-refresh/22-01-SUMMARY.md
  modified:
    - composer.json
    - tests/Unit/Composer/ComposerJsonContractTest.php
    - docs/user-guide/installation.md
    - docs/index.md
decisions:
  - D-09 (nikic/php-parser ^5.0 added to composer.json#require)
  - D-10 (nikic/php-parser entry removed from composer.json#suggest)
  - D-11 (installation.md rewritten as one-command flow, zero bundles.php references)
  - D-12 (no Manual install / fallback section)
  - Research Open Q2 (docs/index.md L22 Quick Start rewritten consistently with installation.md)
metrics:
  duration_seconds: 281
  completed: 2026-05-28
  tasks_total: 3
  tasks_completed: 3
  commits: 3
  files_modified: 4
  files_created: 1
---

# Phase 22 Plan 01: Install UX + nikic require — Summary

One-line: Promoted `nikic/php-parser` from `composer.json#suggest` to `#require` and rewrote both `installation.md` and `docs/index.md` Quick Start to a literal one-command install flow (`composer require danplaton4/tenancy-bundle && bin/console tenancy:install`).

This is the single most user-visible change in the v0.3.3 patch release: it makes `tenancy:install` work out-of-the-box on a fresh Symfony skeleton with zero manual `bundles.php` editing and zero "and also install nikic/php-parser" footnote.

---

## Files Modified

| File | Change | Lines |
|------|--------|-------|
| `composer.json` | +1 require entry (nikic/php-parser ^5.0 between php and symfony/cache), -1 suggest entry | +1 / -1 |
| `tests/Unit/Composer/ComposerJsonContractTest.php` | Inverted contract: was guarding nikic-absent-from-require + present-in-suggest; now guards present-in-require + absent-from-suggest (require-dev assertion unchanged) | full rewrite of two test methods + docblock |
| `docs/user-guide/installation.md` | Section 2 (was L15-50 "Bundle Registration") replaced with new "Run the install command" section. Section 3 mailer row added. All `bundles.php` references removed. New cross-link to `cli-commands.md#tenancy-install`. Sections 1, 3-5 preserved. | net -15 lines |
| `docs/index.md` | L22 Quick Start: replaced `Register the bundle in config/bundles.php, then run bin/console tenancy:init...` with the two-line `composer require ... && bin/console tenancy:install` flow + link to installation guide | +1 / -1 net |

Diff stats: composer.json +1/-1, contract test +18/-15, installation.md +16/-31, index.md +2/-1.

---

## Commits

| Hash | Type | Message |
|------|------|---------|
| `6bc1482` | feat | feat(deps-22): promote nikic/php-parser from suggest to require (D-09/D-10) |
| `ac8b355` | docs | docs(22-01): rewrite installation.md as one-command flow (D-11/D-12) |
| `5e39b52` | docs | docs(22-01): update index.md Quick Start to one-command install (Research Open Q2) |

---

## Verification

| Check | Command | Result |
|-------|---------|--------|
| composer manifest valid | `composer validate --no-check-publish` | exit 0 (lock-file warning expected; plan excludes composer.lock regeneration) |
| nikic in require + require-dev | `grep -c '"nikic/php-parser": "\^5.0"' composer.json` | 2 |
| nikic absent from suggest | `grep -c 'Required to run bin/console tenancy:install' composer.json` | 0 |
| require block alphabetical | `grep -A20 '"require":' composer.json \| grep -E '"(php\|nikic\|symfony)/' \| head -5` | php → nikic → symfony/cache order confirmed |
| composer.json JSON valid | `php -r 'json_decode(...)'` | VALID |
| installation.md no bundles.php | `grep -c 'bundles\.php' docs/user-guide/installation.md` | 0 |
| installation.md has install command | `grep -c 'bin/console tenancy:install' docs/user-guide/installation.md` | 3 |
| installation.md no Manual/Fallback/Bundle Registration H2 | `grep -ciE '^## [0-9]+\. (Manual\|Fallback\|Bundle Registration)' docs/user-guide/installation.md` | 0 |
| installation.md H2 numbering contiguous | `grep -nE '^## [0-9]+\.' docs/user-guide/installation.md` | 1,2,3,4,5 — contiguous |
| index.md no config/bundles.php | `grep -c 'config/bundles\.php' docs/index.md` | 0 |
| index.md has install command | `grep -c 'tenancy:install' docs/index.md` | 1 |
| index.md Quick Start preserved | `grep -c '^## Quick Start' docs/index.md` | 1 |
| Full PHPUnit suite | `vendor/bin/phpunit` | 559 tests, 2069 assertions, all OK |
| PHPStan level 9 | `vendor/bin/phpstan analyse` | OK, No errors (ran via pre-commit hook) |
| php-cs-fixer | (pre-commit hook) | OK on every commit |

---

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking Issue] Inverted ComposerJsonContractTest to match new contract**

- **Found during:** Task 1 commit (pre-commit phpunit hook caught it)
- **Issue:** `tests/Unit/Composer/ComposerJsonContractTest.php` was the regression guard for Phase 18 DEC-INST-02 (nikic must be absent from `require`, must be in `suggest`). Phase 22 D-09/D-10/D-13 explicitly reverse DEC-INST-02. The test would block every future commit until inverted.
- **Fix:** Inverted both affected test methods (`testNikicPhpParserIsAbsentFromRuntimeRequire` → `testNikicPhpParserIsPresentInRuntimeRequire`; `testNikicPhpParserIsSuggestedWithRationale` → `testNikicPhpParserIsAbsentFromSuggest`). Added version-pin regex assertion (`^\^5\./`) to the new require-test, mirroring the existing require-dev pinning assertion. Updated the class docblock to document Phase 22 D-09/D-10 as the new invariant, replacing the Phase 18 DEC-INST-02 docblock.
- **Files modified:** `tests/Unit/Composer/ComposerJsonContractTest.php` (folded into commit `6bc1482`).
- **Rationale:** The test IS the regression guard for the composer.json contract; reversing the contract without flipping the guard would leave the docs and code in inconsistent states. The new test still asserts: (a) nikic IS in require with `^5.x` pin, (b) nikic IS in require-dev with `^5.x` pin (unchanged), (c) nikic is NOT in suggest. All three new tests pass.
- **Why not Rule 4 (architectural):** No structural/architectural change — same file, same testing approach (manifest read + array key assertions), just inverted invariant. Phase 22 D-13 already locks this as a planned reversal.

### Out-of-Scope Discoveries (Logged, Not Fixed)

The following references to nikic/php-parser remain in the codebase and are NOT in scope for this plan:

- `src/Command/TenancyInstallCommand.php` lines 81-82: error message tells users to `composer require --dev nikic/php-parser` when nikic is missing. After D-09, this branch is rarely reachable but still defensive.
- `src/Command/Install/Step/MailerSetupStep.php` line 90: similar fallback messaging.
- `src/Command/Install/BundlesPhpInstaller.php` line 118: `DetectionResult::nonStandard('nikic/php-parser is not installed; cannot detect')` defensive branch.
- `tests/Unit/Command/TenancyInstallCommandTest.php` line 134: asserts the legacy error-message branch.
- `tests/Unit/Command/Install/Step/MailerSetupStepTest.php` 7 calls to `self::markTestSkipped('nikic/php-parser not installed')`: these now never trigger (nikic is always present), but they don't fail either.
- `tests/Integration/Command/TenancyInstallCommandWithMailerTest.php` line 42: same pattern.

**Why not fixed here:** Plan 22-01's scope is `composer.json` + `docs/user-guide/installation.md` + `docs/index.md`. The command-side defensive fallbacks could be removed in a follow-up plan that touches `src/Command/Install/*`, but they're harmless as-is — nikic is now always present, so the branches are dead code but not buggy. Recording for visibility; a future cleanup plan can revisit.

---

## Decisions Honored

| Decision ID | Description | How honored |
|-------------|-------------|-------------|
| D-09 | Add nikic/php-parser ^5.0 to composer.json#require, keep in require-dev | composer.json L22 (`nikic/php-parser: ^5.0` between `php` and `symfony/cache`); require-dev line 38 unchanged |
| D-10 | Remove nikic/php-parser from composer.json#suggest | suggest block now has 7 entries (was 8); nikic deletion confirmed by grep |
| D-11 | installation.md becomes one-command, zero bundles.php on install path; nikic footnote optional | installation.md rewritten; brief one-line nikic footnote included per "Claude's Discretion" per CONTEXT.md L74 |
| D-12 | No Manual install / fallback section | No section with "Manual" or "Fallback" or "Bundle Registration" H2 exists |
| Research Open Q2 | Rewrite docs/index.md L22 to match SC1 intent | Quick Start now uses one-command flow, no config/bundles.php reference |

---

## Cross-link Stability

Task 2's `cli-commands.md#tenancy-install` link uses the canonical mkdocs Material slug for an `## tenancy:install` H2 heading. Per the plan, Plan 22-05 (Wave 2) creates that H2 in `docs/user-guide/cli-commands.md`. Since Wave 1 + Wave 2 all land before any push, the mkdocs `--strict` build in CI (`.github/workflows/docs.yml` line 39) will validate the link once Plan 22-05 ships. If Plan 22-05 uses a different heading text or anchor, this cross-link will need follow-up.

---

## Known Stubs

None. Both docs pages are fully wired to live content (composer command, tenancy:install command). The nikic transitive dep is real (composer.json#require). Cross-link to `cli-commands.md#tenancy-install` is forward-pointing to Plan 22-05, which is in the same wave-sequence-before-push and not a stub by GSD's definition (no hardcoded placeholder data flowing to UI).

---

## Threat Flags

None. Per the plan's threat model:
- T-22-01 (supply-chain — nikic moves to require) is accepted: nikic/php-parser is widely-used (PHPStan, Psalm, Rector, Sulu), pinned to ^5.0, end-user composer.lock provides reproducibility. Severity LOW.
- T-22-02 (stale-docs regression) is mitigated by this plan (the docs are now correct) + Plan 22-06's docs-lint extension (regression guard).
- T-22-SC (package-manager install threat) is N/A — composer.json manifest edit only, no install command executed.

No new threat surface introduced by this plan.

---

## TDD Gate Compliance

N/A — this is a `type: execute` plan, not `type: tdd`. No RED/GREEN/REFACTOR gate sequence required. The contract test in `ComposerJsonContractTest.php` was already in place from Phase 18; this plan inverted it as a load-bearing fix (Rule 3), not as a new TDD cycle.

---

## Self-Check: PASSED

Files verified:
- composer.json: FOUND (modified)
- tests/Unit/Composer/ComposerJsonContractTest.php: FOUND (modified)
- docs/user-guide/installation.md: FOUND (modified)
- docs/index.md: FOUND (modified)
- .planning/phases/22-docs-refresh/22-01-SUMMARY.md: FOUND (this file)

Commits verified in git log:
- 6bc1482: FOUND (feat(deps-22): promote nikic/php-parser from suggest to require)
- ac8b355: FOUND (docs(22-01): rewrite installation.md as one-command flow)
- 5e39b52: FOUND (docs(22-01): update index.md Quick Start to one-command install)

All plan-level acceptance criteria from the PLAN.md `<success_criteria>` and the prompt `<must_haves>` satisfied.
