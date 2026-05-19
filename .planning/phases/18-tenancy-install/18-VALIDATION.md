---
phase: 18
slug: tenancy-install
status: draft
nyquist_compliant: true
wave_0_complete: true
created: 2026-05-15
---

# Phase 18 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x |
| **Config file** | `phpunit.xml.dist` (existing) |
| **Quick run command** | `vendor/bin/phpunit --testsuite unit --filter TenancyInstall` |
| **Full suite command** | `vendor/bin/phpunit` |
| **Estimated runtime** | ~30 seconds (full suite); ~3 seconds (filtered unit subset) |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit --testsuite unit --filter TenancyInstall`
- **After every plan wave:** Run `vendor/bin/phpunit` (full suite — guards against bundle-boot regression)
- **Before `/gsd-verify-work`:** Full suite green + `vendor/bin/phpstan analyse` green + `vendor/bin/php-cs-fixer check --diff` green
- **Max feedback latency:** 30 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 18-01-01 | 01 | 1 | DX-06 | — | composer.json declares nikic in require-dev only | contract | `vendor/bin/phpunit tests/Unit/Composer/ComposerJsonContractTest.php` | ❌ W0 | ⬜ pending |
| 18-02-01 | 02 | 1 | DX-06 | — | fixture corpus assembled (6 shapes + 1 malformed) | unit | `vendor/bin/phpunit tests/Unit/Command/Install/BundlesPhpInstallerTest.php` | ❌ W0 | ⬜ pending |
| 18-03-01 | 03 | 2 | DX-06 | — | AST detector classifies all 7 fixtures correctly | unit | `vendor/bin/phpunit tests/Unit/Command/Install/BundlesPhpInstallerTest.php` | ❌ W0 | ⬜ pending |
| 18-04-01 | 04 | 2 | DX-06 | T-INSTALL-01 | string-template write preserves user formatting | unit | `vendor/bin/phpunit tests/Unit/Command/Install/BundlesPhpInstallerTest.php` | ❌ W0 | ⬜ pending |
| 18-04-02 | 04 | 2 | DX-06 | T-INSTALL-02 | lint-failed-restore keeps `.bak` AND restores file | unit | `vendor/bin/phpunit tests/Unit/Command/Install/BundlesPhpInstallerTest.php --filter lintFailed` | ❌ W0 | ⬜ pending |
| 18-05-01 | 05 | 3 | DX-06 | T-INSTALL-05 | TenancyInstallCommand wires delegation, --dry-run skips tenancy:init, --force forwarded but does not bypass refusal, --force+--dry-run rejected with INVALID exit | unit | `vendor/bin/phpunit tests/Unit/Command/TenancyInstallCommandTest.php` | ❌ W0 | ⬜ pending |
| 18-06-01 | 06 | 3 | DX-06 | — | DI registration via tenancy.command.install tag | integration | `vendor/bin/phpunit tests/Integration/Command/TenancyInstallCommandIntegrationTest.php` | ❌ W0 | ⬜ pending |
| 18-06-02 | 06 | 3 | DX-06 | — | end-to-end: fresh fixture → mutated bundles.php + tenancy.yaml | integration | `vendor/bin/phpunit tests/Integration/Command/TenancyInstallCommandIntegrationTest.php --filter endToEnd` | ❌ W0 | ⬜ pending |
| 18-06-03 | 06 | 3 | DX-06 | — | idempotency: three consecutive runs leave bytes identical after run 1 | integration | `vendor/bin/phpunit tests/Integration/Command/TenancyInstallCommandIntegrationTest.php --filter idempotent` | ❌ W0 | ⬜ pending |
| 18-07-01 | 07 | 4 | DX-06 | — | CHANGELOG.md v0.3.0-unreleased entry references DX-06 | docs-grep | `grep -q '^- DX-06 ' CHANGELOG.md` | ✅ | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Unit/Composer/ComposerJsonContractTest.php` — new file (composer.json contract assertions for DX-06 success criterion 5)
- [ ] `tests/Unit/Command/Install/BundlesPhpInstallerTest.php` — new file (AST detector + write logic against 7 fixtures)
- [ ] `tests/Unit/Command/TenancyInstallCommandTest.php` — new file (command surface, flags, delegation behaviour)
- [ ] `tests/Integration/Command/TenancyInstallCommandIntegrationTest.php` — new file (full kernel boot + CommandTester + tmp project root)
- [ ] `tests/Fixtures/BundlesPhpCorpus/{skeleton,api-platform,sulu,ddd-override,with-comments,env-conditional,malformed}/bundles.php` — 7 fixture files (6 shapes + 1 malformed)
- [ ] `tests/Fixtures/BundlesPhpCorpus/.expected/{skeleton,api-platform,sulu,with-comments}/bundles.php` — 4 expected-after-mutation baselines (the 4 fixtures that mutate successfully; ddd-override / env-conditional / malformed have no `.expected/` because they refuse)
- [ ] `nikic/php-parser ^5.0` installed via `composer require --dev nikic/php-parser:^5.0` (already vendored transitively per RESEARCH.md §2; explicit require-dev declaration is Wave 1 plan-01 work)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Success-path stdout block (D-24 next-step copy-paste message) reads naturally to a fresh user | DX-06 acceptance criterion 1 | Subjective UX read — automated string-equality would brittle-couple to wording flexibility called out in CONTEXT.md "Claude's Discretion" | After Wave 3 completion, run the integration test, copy the stdout into a fresh terminal, follow the printed next-steps verbatim, confirm a `tenancy.yaml`-aware Symfony app boots without further searching docs. |
| The unified-diff dry-run output is copy-pasteable into bundles.php manually | DX-06 acceptance criterion 3 | Automated assertion would over-constrain the diff format | Run `bin/console tenancy:install --dry-run` against the skeleton fixture, copy the `+` line into `bundles.php` by hand, confirm `php -l config/bundles.php` exits 0. |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references (the 7 fixture files + 4 baselines + 4 test files + `nikic/php-parser` dev install)
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** approved 2026-05-15
