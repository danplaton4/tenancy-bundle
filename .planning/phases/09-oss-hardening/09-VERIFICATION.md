---
phase: 09-oss-hardening
verified: 2026-04-09T22:30:00Z
resolved: 2026-04-21T00:00:00Z
status: resolved
score: 9/9 must-haves verified
overrides_applied: 0
gaps:
  - truth: "composer validate --strict passes without errors"
    status: resolved
    reason: "Lock file was out of sync with composer.json require-dev. Resolved 2026-04-21 by running `composer update phpstan/phpstan friendsofphp/php-cs-fixer` to sync the lock file. `composer validate --strict` now exits 0."
    resolution_note: "composer.lock is gitignored in this repo (library bundle, not app). Sync is a local operation validated by CI, not a committed artifact."
human_verification:
  - test: "Symfony 7.4.* CI matrix resolution"
    expected: "The 'tests' job matrix entry 'symfony: 7.4.*' resolves to an installable Symfony version on GitHub Actions."
    status: resolved
    resolution: "Symfony 7.4 has shipped since this VERIFICATION was written (2026-04-09). CI matrix green on master — latest successful run 2026-04-19 (gh run 24631123976)."
  - test: "Flex recipe installs cleanly into a fresh Symfony project"
    expected: "After 'composer require danplaton4/tenancy-bundle' in a new Symfony skeleton, Flex configures the bundle automatically."
    status: obsolete
    resolution: "Flex recipe was removed in Phase 14 (see feedback_no_flex memory + docs/user-guide/installation.md). Onboarding is now done via `bin/console tenancy:init` (Phase 12). This human-verification item no longer applies."
---

# Phase 9: OSS Hardening Verification Report

**Phase Goal:** The bundle is Packagist-ready, installs with zero manual configuration via Symfony Flex, and the CI matrix enforces quality on every supported PHP and Symfony version
**Verified:** 2026-04-09T22:30:00Z
**Status:** gaps_found
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | `composer validate --strict` passes without errors | FAILED | Exit code 2; phpstan/phpstan and friendsofphp/php-cs-fixer present in require-dev but absent from composer.lock |
| 2 | `symfony/process` is NOT in the suggest block | VERIFIED | `grep` confirms symfony/process appears only on line 29 in `require`, not in `suggest` block |
| 3 | keywords, authors, homepage, support URLs are present for Packagist discoverability | VERIFIED | All fields present: keywords (7 terms), authors (Dan Platon), homepage, support.issues, support.source |
| 4 | branch-alias maps dev-master to 1.0.x-dev | VERIFIED | `extra.branch-alias.dev-master = "1.0.x-dev"` confirmed via PHP introspection |
| 5 | manifest.json is valid JSON and contains bundles, copy-from-recipe, and aliases configurators | VERIFIED | PHP json_decode validates; all three configurators present; copy-from-recipe used (not deprecated copy-from-package) |
| 6 | tenancy.yaml stub has all top-level config keys commented out with explanations | VERIFIED | Root `tenancy:` uncommented; driver, strict_mode, landlord_connection, resolvers, host, database all commented with inline docs |
| 7 | README opens with badges (CI, Packagist, PHP, License, Coverage) and quick-start with install + #[TenantAware] | VERIFIED | All 5 badges present; Quick Start has 3 steps: install, configure YAML, #[TenantAware] entity; comparison table has stancl/tenancy and RamyHakam |
| 8 | CONTRIBUTING.md has PR guidelines, coding standards (php-cs-fixer @Symfony), PHPStan level 9, test expectations | VERIFIED | All sections present; `vendor/bin/phpunit`, `vendor/bin/php-cs-fixer check --diff`, `vendor/bin/phpstan analyse`, @Symfony ruleset, level 9 — all confirmed |
| 9 | CI workflow defines a 3x2 PHP/Symfony matrix with phpstan level 9, php-cs-fixer check, no-doctrine job, and coverage | VERIFIED | ci.yml has all 5 jobs; matrix php: [8.2, 8.3, 8.4] x symfony: [6.4.*, 7.4.*]; fail-fast: false; phpstan.neon level 9; .php-cs-fixer.dist.php @Symfony |

**Score:** 8/9 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `composer.json` | Packagist-ready package metadata | VERIFIED | All fields present; branch-alias, keywords, authors, homepage, support |
| `flex/danplaton4/tenancy-bundle/1.0/manifest.json` | Flex recipe manifest for auto-registration | VERIFIED | Valid JSON; bundles configurator with TenancyBundle FQCN; copy-from-recipe; aliases: ["tenancy"] |
| `flex/danplaton4/tenancy-bundle/1.0/config/packages/tenancy.yaml` | Default config stub for Flex install | VERIFIED | Root tenancy: key uncommented; all 6 sub-keys commented with inline explanations |
| `README.md` | Public-facing documentation for GitHub and Packagist | VERIFIED | All 9 sections present: badges, headline, Quick Start, Features, How It Works, Comparison, Philosophy, Requirements, Contributing, License |
| `CONTRIBUTING.md` | Contributor guidelines | VERIFIED | Getting Started, PR Guidelines, Coding Standards, Static Analysis, Test Expectations, Bug Reporting, License |
| `.github/workflows/ci.yml` | GitHub Actions CI configuration | VERIFIED | 5 jobs: tests (3x2 matrix), coverage (xdebug + Codecov), phpstan (level 9), cs-fixer, no-doctrine |
| `phpstan.neon` | PHPStan static analysis config | VERIFIED | level: 9; paths: [src]; treatPhpDocTypesAsCertain: false; no includes (no phpstan-symfony extension) |
| `.php-cs-fixer.dist.php` | PHP CS Fixer configuration | VERIFIED | @Symfony ruleset; @Symfony:risky: false; declare_strict_types; covers src/ and tests/ |
| `composer.lock` | Lock file consistent with composer.json | FAILED | phpstan/phpstan and friendsofphp/php-cs-fixer in require-dev but not in packages-dev section of lock file |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `composer.json` | Packagist | keywords, homepage, support URLs | VERIFIED | All Packagist discovery fields present |
| `manifest.json` | `src/TenancyBundle.php` | bundles configurator FQCN | VERIFIED | `"Tenancy\\Bundle\\TenancyBundle": ["all"]` matches actual class namespace |
| `manifest.json` | `config/packages/tenancy.yaml` | copy-from-recipe configurator | VERIFIED | `copy-from-recipe: {"config/": "%CONFIG_DIR%/"}` links recipe config to app |
| `.github/workflows/ci.yml` | `phpstan.neon` | `vendor/bin/phpstan analyse` | VERIFIED | phpstan job runs `vendor/bin/phpstan analyse` which reads phpstan.neon at level 9 |
| `.github/workflows/ci.yml` | `.php-cs-fixer.dist.php` | `vendor/bin/php-cs-fixer check` | VERIFIED | cs-fixer job runs `vendor/bin/php-cs-fixer check --diff` which reads .php-cs-fixer.dist.php |
| `.github/workflows/ci.yml` | `phpunit.xml.dist` | `vendor/bin/phpunit` | VERIFIED | tests, coverage, and no-doctrine jobs all invoke phpunit which reads phpunit.xml.dist |
| `README.md` | `CONTRIBUTING.md` | link in Contributing section | VERIFIED | README line 115: `See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.` |

### Data-Flow Trace (Level 4)

Not applicable — phase 09 artifacts are configuration files, documentation, and CI workflow definitions. No dynamic data rendering occurs.

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| manifest.json is valid JSON | `php -r "json_decode(file_get_contents('flex/danplaton4/tenancy-bundle/1.0/manifest.json'), true, 512, JSON_THROW_ON_ERROR); echo 'valid JSON';"` | "valid JSON" | PASS |
| branch-alias is correct | `php -r "echo json_decode(file_get_contents('composer.json'), true)['extra']['branch-alias']['dev-master'];"` | "1.0.x-dev" | PASS |
| composer.json is valid JSON (ignoring lock) | `composer validate` (not --strict) | "./composer.json is valid but your composer.lock has some errors" | PARTIAL |
| composer validate --strict | `composer validate --strict` | Exit code 2 — lock file errors for phpstan and php-cs-fixer | FAIL |
| phpstan config level | `grep "level:" phpstan.neon` | "level: 9" | PASS |
| phpstan has no includes | `grep "includes:" phpstan.neon` | (empty — no output) | PASS |
| CS fixer covers src/ and tests/ | `.php-cs-fixer.dist.php` finder paths | `[__DIR__ . '/src', __DIR__ . '/tests']` | PASS |
| symfony/process in require not suggest | PHP introspection on composer.json | IN REQUIRE, NOT IN SUGGEST | PASS |
| phpstan-symfony absent | `grep "phpstan-symfony" composer.json` | (empty) | PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|---------|
| OSS-01 | 09-01 | composer.json is Packagist-ready with PHP ^8.2, Symfony ^6.4\|^7.0, correct extra.symfony config | PARTIAL | All metadata present and correct; BLOCKED by lock file staleness — `composer validate --strict` fails |
| OSS-02 | 09-03 | README.md with headline, quick-start (install, #[TenantAware], subdomain), comparison table vs RamyHakam/manual | SATISFIED | README has all required sections including comparison table; quick-start shows install + #[TenantAware] + config YAML; subdomain mentioned in Features not in 3-step quick-start but overall requirement is met |
| OSS-03 | 09-02 | Flex recipe auto-configures bundle in config/bundles.php and creates config/packages/tenancy.yaml stub | SATISFIED | manifest.json uses bundles + copy-from-recipe configurators; tenancy.yaml stub with correct root key; actual Flex install requires human test |
| OSS-04 | 09-04 | GitHub Actions CI: full test suite on PHP 8.2/8.3/8.4 x Symfony 6.4/7.4, PHPStan, php-cs-fixer | SATISFIED | ci.yml has all 5 jobs with correct matrix; phpstan level 9; php-cs-fixer @Symfony; Symfony 7.4.* version validity needs human verification |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `composer.lock` | — | Lock file does not include phpstan/phpstan and friendsofphp/php-cs-fixer in packages-dev, despite both being in composer.json require-dev | Blocker | `composer validate --strict` fails (exit code 2); CI `ramsey/composer-install` will attempt to run with a stale lock, which may succeed (it runs `composer install` which reconciles deps) but the lock file inconsistency is a published artifact quality issue |

### Human Verification Required

#### 1. Symfony 7.4.* Availability

**Test:** Push to a GitHub branch and observe the `tests` CI job matrix entry for `symfony: 7.4.*`
**Expected:** The SYMFONY_REQUIRE=7.4.* constraint resolves to an installable Symfony version, and all 3 PHP versions complete successfully
**Why human:** As of April 2026, Symfony 7.x series has not yet published a 7.4 release (LTS schedule: 7.2 LTS, next LTS is 7.4 planned for late 2026). The CI matrix will fail until 7.4 ships. This is forward-looking by design but requires confirmation that the version resolves or that the ROADMAP's "7.4" intent means "latest 7.x" and should be changed to `7.*`.

#### 2. Flex Recipe Installation Flow

**Test:** In a fresh Symfony skeleton project with Flex enabled, run `composer require danplaton4/tenancy-bundle` (after the package is published on Packagist)
**Expected:** `config/bundles.php` gets `Tenancy\Bundle\TenancyBundle::class => ['all' => true]` appended; `config/packages/tenancy.yaml` is created with the commented stub
**Why human:** Flex recipe installation requires a real Packagist-registered package. The recipe structure in `flex/danplaton4/tenancy-bundle/1.0/` is correct per the spec, but the actual copy-from-recipe execution pathway cannot be verified without running a real `composer require` against a registered package.

### Gaps Summary

**1 gap blocks goal achievement:**

**Gap: Lock file is stale** — `phpstan/phpstan` and `friendsofphp/php-cs-fixer` were added to `require-dev` in composer.json (plan 09-04) by editing the file directly rather than via `composer require --dev`, which means the composer.lock was not updated. The plan's acceptance criterion "composer validate --strict exits 0" therefore fails.

Fix: Run `composer update phpstan/phpstan friendsofphp/php-cs-fixer` and commit the updated `composer.lock`. This is the only blocking gap — the JSON schema of composer.json is valid, all metadata fields are correct, and all other artifacts meet their acceptance criteria.

**Note on Symfony 7.4:** The CI matrix uses `7.4.*` which is the version specified in the ROADMAP ("PHP 8.2/8.3/8.4 × Symfony 6.4/7.4"). As of April 2026, Symfony 7.4 has not been released. The CI job will fail on GitHub Actions until 7.4 ships. This is a design choice from the ROADMAP (forward-looking), not a defect in the implementation — but it should be acknowledged and possibly changed to `7.2.*` for the interim. Routed to human verification since it requires judgment on whether to accept the forward-looking spec or use a currently-installable version.

---

_Verified: 2026-04-09T22:30:00Z_
_Verifier: Claude (gsd-verifier)_
