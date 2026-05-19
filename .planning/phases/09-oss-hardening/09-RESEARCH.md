# Phase 09: OSS Hardening - Research

**Researched:** 2026-04-09
**Domain:** Packagist publishing, Symfony Flex recipes, GitHub Actions CI matrix, PHPStan level 9, php-cs-fixer, README authoring
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- **D-01:** Developer-pragmatic tone — lead with the problem ("Symfony has no stancl/tenancy"), show code, let it sell itself. Think League/Flysystem style.
- **D-02:** Quick-start is install-only: `composer require` + bundle registration, then link to docs/documentation for actual usage. Keep README concise.
- **D-03:** Comparison table includes stancl/tenancy (Laravel) — positions this as the Symfony equivalent, useful for devs evaluating frameworks or migrating.
- **D-04:** Include badges (CI status, Packagist latest stable, PHP version, license) at the top.
- **D-05:** Include an architecture overview section — brief explanation of the event-driven bootstrapper model and how tenant context flows through the kernel.
- **D-06:** Include a contributing guide (CONTRIBUTING.md or README section) with PR guidelines, coding standards, test expectations.
- **D-07:** Default `tenancy.yaml` stub is a minimal skeleton — top-level keys commented out with explanations (driver, strict_mode, resolvers, database). User uncomments what they need.
- **D-08:** Flex recipe location: Claude's discretion — standard approach for OSS Symfony bundles (symfony/recipes-contrib PR is the canonical path, but in-repo recipe structure should be prepared regardless).
- **D-09:** Add a no-Doctrine CI job that removes doctrine/* before running tests — validates class_exists/interface_exists guards work correctly.
- **D-10:** Include code coverage reporting with Codecov/Coveralls badge in README.
- **D-11:** PHPStan level 9 (fixed, not --level=max) — matches project quality bar.
- **D-12:** Matrix: PHP 8.2/8.3/8.4 x Symfony 6.4/7.4 with PHPStan and php-cs-fixer checks.
- **D-13:** Remove `symfony/process` from `suggest` — it's already a hard `require` since Phase 07, so the suggest entry is redundant and misleading.
- **D-14:** Package metadata uses GitHub repo URLs: homepage, support.issues, support.source all pointing to the GitHub repository.
- **D-15:** Add `branch-alias` for dev-master → `1.0.x-dev` so users can `require ^1.0` before the first tag is released.

### Claude's Discretion
- Flex recipe repo strategy (in-repo vs contrib PR) — Claude picks the standard approach
- Additional README sections beyond those specified (changelog link, roadmap teaser, etc.)
- php-cs-fixer ruleset choice (Symfony, PSR-12, or custom)
- Keywords in composer.json for Packagist discoverability

### Deferred Ideas (OUT OF SCOPE)
None — discussion stayed within phase scope
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| OSS-01 | `composer.json` is Packagist-ready with PHP `^8.2`, Symfony `^6.4|^7.0` constraints, soft dependencies on doctrine/orm and doctrine/migrations, and correct `extra.symfony` bundle configuration | composer.json already has the right structure; needs metadata additions (authors, keywords, support URLs, branch-alias) and removal of the misleading `symfony/process` from suggest |
| OSS-02 | `README.md` contains: compelling headline, 30-second quick-start, comparison table vs RamyHakam/manual implementation, and philosophy section | Research confirms League/Flysystem style, badges format, stancl/tenancy feature set for comparison table, badge URL patterns |
| OSS-03 | Symfony Flex recipe auto-configures the bundle in `config/bundles.php` and creates a `config/packages/tenancy.yaml` stub on `composer require` | Research confirms manifest.json format with `bundles` + `copy-from-recipe` configurators; directory structure `danplaton4/tenancy-bundle/1.0/`; contrib PR is the submission path |
| OSS-04 | GitHub Actions CI runs the full test suite on a PHP 8.2/8.3/8.4 × Symfony 6.4/7.4 matrix with PHPStan and php-cs-fixer checks | Research confirms `shivammathur/setup-php@v2` + `SYMFONY_REQUIRE` env var pattern; PHPStan 2.1.x + phpstan-symfony 2.0.x; php-cs-fixer ^3.x; codecov/codecov-action@v5 |
</phase_requirements>

---

## Summary

Phase 09 is a pure configuration and documentation phase — no new PHP classes are required. The work divides cleanly into four deliverables: (1) polishing `composer.json` for Packagist, (2) authoring `README.md`, (3) creating a Symfony Flex recipe structure, and (4) writing a GitHub Actions CI workflow.

The existing `composer.json` is already 80% there. It has the correct `name`, `type: symfony-bundle`, `license`, PSR-4 autoload, and `extra.symfony.bundles`. What is missing is Packagist metadata (`keywords`, `authors`, `support` URLs, `homepage`) and the `extra.branch-alias` for pre-release `^1.0` installs. The `symfony/process` entry in `suggest` must be removed (it is already a hard `require`).

The Flex recipe follows a well-documented pattern: a directory at `danplaton4/tenancy-bundle/1.0/` containing `manifest.json` and a `config/packages/tenancy.yaml` stub. The `manifest.json` uses the `bundles` configurator to register `TenancyBundle` and `copy-from-recipe` to place the yaml stub. This in-repo structure can be submitted to `symfony/recipes-contrib` as a PR once the package is on Packagist.

The CI workflow uses `shivammathur/setup-php@v2` with the `SYMFONY_REQUIRE` environment variable in the matrix to test 6 PHP×Symfony combinations (3×2), plus a PHPStan job, a php-cs-fixer job, and a no-Doctrine job. This is the established standard across the Symfony ecosystem.

**Primary recommendation:** Plan the four deliverables as independent tasks (09-01 through 09-04). They have no inter-dependencies — any order works.

---

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `phpstan/phpstan` | `^2.1` (latest: 2.1.46) | Static analysis | De facto PHP standard; level 9 is the strictest deterministic level |
| `phpstan/phpstan-symfony` | `^2.0` (latest: 2.0.15) | Symfony-aware PHPStan rules | Understands DI container, service types, event dispatcher types |
| `friendsofphp/php-cs-fixer` | `^3.0` (latest: 3.89.x) | Code style enforcement | Official Symfony ecosystem tool; `@Symfony` ruleset matches bundle conventions |
| `codecov/codecov-action` | `v5` | Coverage upload to Codecov | Current recommended version; v3 is outdated |
| `shivammathur/setup-php` | `v2` | PHP setup in GitHub Actions | Universal standard for PHP CI; supports `tools: flex` for SYMFONY_REQUIRE |
| `ramsey/composer-install` | `v3` | Composer install action with caching | Better caching than raw `composer install`; used in symfony-bundle-test docs |

[VERIFIED: packagist.org - phpstan/phpstan 2.1.46 released 2026-04-01]
[VERIFIED: packagist.org - phpstan/phpstan-symfony 2.0.15 released 2026-02-26]
[VERIFIED: packagist.org - friendsofphp/php-cs-fixer latest 3.89.x]
[CITED: github.com/SymfonyTest/symfony-bundle-test README]
[CITED: github.com/codecov/codecov-action]

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `phpstan/extension-installer` | `^1.4` | Auto-installs phpstan extensions | Optional but removes manual `includes:` in phpstan.neon |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `friendsofphp/php-cs-fixer` | `squizlabs/PHP_CodeSniffer` | php-cs-fixer is the Symfony-idiomatic choice; `@Symfony` ruleset is authoritative |
| `codecov/codecov-action` | Coveralls | Codecov is the decided choice (D-10); either works |
| `ramsey/composer-install` | raw `composer install` step | ramsey/composer-install handles cache correctly; raw install risks cache side-effects per Symfony docs |

**Installation (require-dev additions):**
```bash
composer require --dev phpstan/phpstan phpstan/phpstan-symfony friendsofphp/php-cs-fixer
```

---

## Architecture Patterns

### 09-01: Packagist-Ready composer.json

The existing `composer.json` needs these additions only — it is NOT a rewrite.

**What must change:**
- Remove `symfony/process` from `suggest` (already in `require` since Phase 07) — it's misleading and contradicts Packagist expectations
- Add `keywords` array for Packagist discoverability
- Add `authors` array
- Add `homepage` and `support` (issues, source)
- Add `extra.branch-alias` (`dev-master` → `1.0.x-dev`)

**Final shape of additions:**
```json
{
    "keywords": ["symfony", "multitenancy", "multi-tenant", "saas", "bundle", "doctrine", "tenancy"],
    "authors": [
        {
            "name": "Dan Platon",
            "homepage": "https://github.com/danplaton4",
            "role": "Developer"
        }
    ],
    "homepage": "https://github.com/danplaton4/tenancy-bundle",
    "support": {
        "issues": "https://github.com/danplaton4/tenancy-bundle/issues",
        "source": "https://github.com/danplaton4/tenancy-bundle"
    },
    "extra": {
        "symfony": {
            "bundles": {
                "Tenancy\\Bundle\\TenancyBundle": ["all"]
            }
        },
        "branch-alias": {
            "dev-master": "1.0.x-dev"
        }
    }
}
```

[CITED: getcomposer.org/doc/04-schema.md - support, authors, keywords]
[CITED: symfony.com/doc/current/bundles/best_practices.html]

**Critical: `extra.branch-alias` vs `extra.symfony.require`**
`branch-alias` lives under `extra` at the top level, not under `extra.symfony`. The `extra.symfony.require` key is for application-level Symfony version pinning (used by `composer config extra.symfony.require "7.*"`), NOT for bundles. Bundles must not include `extra.symfony.require` — that key constrains the application's Symfony version globally.

[CITED: getcomposer.org/doc/04-schema.md]

### 09-02: Symfony Flex Recipe

**Directory structure (in-repo, ready for contrib PR):**
```
flex/
└── danplaton4/
    └── tenancy-bundle/
        └── 1.0/
            ├── manifest.json
            └── config/
                └── packages/
                    └── tenancy.yaml
```

**manifest.json:**
```json
{
    "bundles": {
        "Tenancy\\Bundle\\TenancyBundle": ["all"]
    },
    "copy-from-recipe": {
        "config/": "%CONFIG_DIR%/"
    },
    "aliases": ["tenancy"]
}
```

[VERIFIED: github.com/symfony/recipes - nelmio/cors-bundle/1.5/manifest.json as canonical reference]

**Key manifest.json facts:**
- `bundles` configurator: registers bundle in `config/bundles.php` for listed environments (`["all"]` = every env)
- `copy-from-recipe`: copies files from the recipe `config/` directory into the application's `%CONFIG_DIR%/` (resolves to `config/`)
- `aliases`: lets users run `composer require tenancy` instead of the full package name
- Version directory `1.0` = minimum package version the recipe supports; does NOT have to match an exact release tag

**tenancy.yaml stub (D-07: minimal skeleton, top-level keys commented out):**
```yaml
# config/packages/tenancy.yaml
tenancy:
    # driver: database_per_tenant  # Options: database_per_tenant, shared_db
    # strict_mode: true            # Throw TenantMissingException if #[TenantAware] queried without context
    # landlord_connection: default # DBAL connection name for the landlord (central) DB
    # resolvers: [host, header, query_param, console]
    # host:
    #     app_domain: app.example.com   # Used by HostResolver to extract tenant slug from subdomain
    # database:
    #     enabled: false               # Set true to enable database-per-tenant mode (two EMs)
```

**Contrib submission path (D-08):**
The canonical process for an OSS bundle is:
1. Prepare the recipe locally in a `flex/` directory at the repo root (or a standalone directory)
2. Once the package is live on Packagist, open a PR to `symfony/recipes-contrib`
3. PRs merge when Symfony Bot validates + at least one non-bot community approval

For this phase, deliver the recipe files in-repo. The contrib PR is a post-Packagist-registration action. [CITED: github.com/symfony/recipes-contrib README]

### 09-03: README.md

**Proven pattern (League/Flysystem, D-01):** Lead with the pain, show code in the first screenful, let test counts and badge row signal quality.

**Badge row format:**
```markdown
[![CI](https://github.com/danplaton4/tenancy-bundle/actions/workflows/ci.yml/badge.svg)](...)
[![Packagist](https://img.shields.io/packagist/v/danplaton4/tenancy-bundle.svg)](...)
[![PHP](https://img.shields.io/packagist/php-v/danplaton4/tenancy-bundle.svg)](...)
[![License](https://img.shields.io/packagist/l/danplaton4/tenancy-bundle.svg)](...)
[![Coverage](https://codecov.io/gh/danplaton4/tenancy-bundle/branch/master/graph/badge.svg)](...)
```

[ASSUMED - badge URL format from shields.io convention; confirm slug matches actual Packagist package name]

**Comparison table subjects (D-03):**
- `danplaton4/tenancy-bundle` (this bundle — Symfony)
- `stancl/tenancy` (Laravel — the gold standard; positions this as "the Symfony equivalent")
- `RamyHakam/multi_tenancy_bundle` (existing Symfony; database-per-tenant, manual config)
- Manual Doctrine multi-tenancy (discriminator column / raw SQL filter)

**stancl/tenancy key features for table:**
- Database-per-tenant: yes
- Shared-DB (single DB with scoping): yes
- Cache isolation: yes
- Queue/Messenger context propagation: yes (queue bootstrapper)
- Filesystem isolation: yes
- Subdomain/domain resolution: yes
- CLI tenant context: yes
- Strict mode (error on no-tenant query): no documented equivalent
- PHP attribute `#[TenantAware]`: no (uses model traits)
- Event-driven bootstrapper model: no (bootstrapper classes, but not event-driven)

[CITED: tenancyforlaravel.com/docs/v3/package-comparison/]

**RamyHakam bundle key features:**
- Database-per-tenant: yes
- Shared-DB: no
- Cache/Queue isolation: no
- Flex recipe: no
- PHPStan/CI: not published
- Messenger context: no

[CITED: github.com/RamyHakam/multi_tenancy_bundle]

**README sections (mandatory + discretionary):**
1. Badges
2. Headline (1 sentence — the "Symfony has no stancl/tenancy" problem statement)
3. Quick-start (composer require → one `#[TenantAware]` example → link to docs)
4. Architecture overview (event-driven bootstrapper model, kernel.request priority, TenantContext flow)
5. Comparison table
6. Philosophy / zero-leak guarantee
7. Contributing (link to CONTRIBUTING.md)
8. License

**Stat to highlight:** 40 source files, 68 test files (1.7:1 test ratio) — signals production readiness.

### 09-04: GitHub Actions CI

**Canonical pattern from `symfony-bundle-test` documentation:**

```yaml
# .github/workflows/ci.yml
name: CI

on:
  push:
  pull_request:

jobs:
  tests:
    runs-on: ubuntu-latest
    name: PHP ${{ matrix.php }} / Symfony ${{ matrix.symfony }}
    strategy:
      fail-fast: false
      matrix:
        php: ['8.2', '8.3', '8.4']
        symfony: ['6.4.*', '7.4.*']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          tools: flex
          coverage: none
      - name: Install dependencies
        env:
          SYMFONY_REQUIRE: ${{ matrix.symfony }}
        run: composer update --no-interaction --no-progress --prefer-dist
      - run: vendor/bin/phpunit
```

[VERIFIED: github.com/SymfonyTest/symfony-bundle-test - README canonical CI example]
[VERIFIED: github.com/symfony/symfony .github/workflows/unit-tests.yml - SYMFONY_REQUIRE pattern confirmed]

**How SYMFONY_REQUIRE works:** It is consumed by `symfony/flex` (installed via `tools: flex` in setup-php) to constrain all `symfony/*` packages to the specified version during `composer update`. This is the standard approach — a single `composer.json` serves all matrix combinations without forks. [CITED: symfony.com/doc/current/bundles/best_practices.html]

**No-Doctrine job (D-09):**
```yaml
  no-doctrine:
    runs-on: ubuntu-latest
    name: No Doctrine (guards check)
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          tools: flex
          coverage: none
      - run: composer update --no-interaction --no-progress --prefer-dist
      - run: composer remove --dev doctrine/orm doctrine/dbal doctrine/doctrine-bundle doctrine/migrations --no-update && composer update --no-interaction --no-progress
      - run: vendor/bin/phpunit --testsuite unit
```

This validates that `class_exists()` / `interface_exists()` guards in the bundle prevent crashes when Doctrine is absent.

**PHPStan job (D-11 — level 9, no matrix needed):**
```yaml
  phpstan:
    runs-on: ubuntu-latest
    name: PHPStan (level 9)
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: none
      - run: composer update --no-interaction --no-progress --prefer-dist
      - run: vendor/bin/phpstan analyse
```

PHPStan runs on latest PHP only — no matrix needed for static analysis. [CITED: SymfonyCasts bundle development docs]

**php-cs-fixer job:**
```yaml
  cs-fixer:
    runs-on: ubuntu-latest
    name: PHP CS Fixer
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          coverage: none
      - run: composer update --no-interaction --no-progress --prefer-dist
      - run: vendor/bin/php-cs-fixer check --diff
```

Run on lowest supported PHP (8.2) to confirm style is compatible with the minimum version.

**Coverage job (D-10):**
Coverage should run once (not on the full matrix) to avoid redundancy. Add a dedicated coverage step or matrix include with `coverage: xdebug`:
```yaml
      - name: Run tests with coverage
        if: matrix.php == '8.4' && matrix.symfony == '7.4.*'
        run: vendor/bin/phpunit --coverage-clover coverage.xml
      - uses: codecov/codecov-action@v5
        if: matrix.php == '8.4' && matrix.symfony == '7.4.*'
        with:
          files: ./coverage.xml
```

[CITED: about.codecov.io/blog/measuring-php-code-coverage-with-phpunit-and-github-actions]

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| PHP version matrix | Custom Docker images / shell scripts | `shivammathur/setup-php@v2` | Handles extensions, xdebug/pcov, tools; supports PHP 8.2–8.6 natively |
| Symfony version switching in CI | Multiple `composer.json` files | `SYMFONY_REQUIRE` env + `tools: flex` | Single source of truth; Flex resolves constraints per-run |
| Composer install caching | Manual cache step with hash keys | `ramsey/composer-install@v3` | Handles cache key correctly; avoids the vendor/ caching side-effect pitfall |
| Code coverage collection | Hand-writing xdebug ini | `coverage: xdebug` in setup-php | One line; handles php.ini correctly across PHP versions |

**Key insight:** Every CI problem in the PHP/Symfony ecosystem has been solved by the `shivammathur/setup-php` action. Use it — do not reach for raw shell commands.

---

## Common Pitfalls

### Pitfall 1: `extra.symfony.require` in a bundle's composer.json
**What goes wrong:** Including `extra.symfony.require: "^7.0"` in the bundle's own `composer.json` forces ALL consumers of the bundle to use Symfony 7+, overriding their own constraints.
**Why it happens:** Confusion between the application-level config key and bundle metadata.
**How to avoid:** Never include `extra.symfony.require` in a reusable bundle. It belongs only in application-level `composer.json` or for CI use via env var.
**Warning signs:** Consumers report `composer require` upgrading their entire Symfony installation.

[CITED: symfony.com/doc/current/bundles/best_practices.html - SYMFONY_REQUIRE warning]

### Pitfall 2: `symfony/process` in `suggest` AND `require`
**What goes wrong:** Packagist shows it as both a hard dep and a suggestion — misleading to users who think it is optional.
**Why it happens:** Phase 07 promoted `symfony/process` to hard `require` but the `suggest` entry was not cleaned up.
**How to avoid:** D-13 covers this; planner must include a task to remove it from `suggest`.

### Pitfall 3: Vendoring `vendor/` in CI cache
**What goes wrong:** Caching the `vendor/` directory in GitHub Actions can cause stale dependency issues across matrix runs. Specifically, Symfony docs warn against caching `vendor/` and recommend caching `$HOME/.composer/cache/files` instead.
**Why it happens:** Developers copy a naive "cache vendor/ for speed" pattern.
**How to avoid:** Use `ramsey/composer-install` which handles this correctly, or manually cache `~/.composer/cache`.

[CITED: symfony.com/doc/current/bundles/best_practices.html - CI section]

### Pitfall 4: PHPStan level 9 on a no-container bundle
**What goes wrong:** `phpstan/phpstan-symfony` requires a `containerXmlPath` pointing to a compiled Symfony container. For a standalone bundle (no full app in the repo), the container XML does not exist at analysis time.
**Why it happens:** phpstan-symfony is designed for full Symfony applications.
**How to avoid:** For this bundle, **do not include phpstan-symfony** in the phpstan.neon includes. Use plain `phpstan/phpstan` at level 9 without the Symfony extension — the bundle has no container-XML-dependent analysis needs (no service ID string lookups). This is the correct approach for a reusable bundle vs. an application.
**Confidence:** MEDIUM — verified that `containerXmlPath` is required for phpstan-symfony; inference that standalone bundles omit it. [CITED: github.com/phpstan/phpstan-symfony README - containerXmlPath is required]

### Pitfall 5: Recipe version directory mismatch
**What goes wrong:** The `1.0/` version directory in the recipe is interpreted as "minimum package version", not a specific tag. If the bundle is submitted to contrib before `1.0.0` is tagged, the recipe applies to `dev-master` installs.
**Why it happens:** Developers confuse the recipe version folder with an exact version tag.
**How to avoid:** Use `1.0` as the folder name (matching `1.0.x-dev` branch alias) — this is valid before any tag exists. The recipe applies to any install of the package at version `>=1.0`. [CITED: github.com/symfony/recipes README.rst]

### Pitfall 6: `@Symfony` ruleset in php-cs-fixer requires PHP docblock rigor
**What goes wrong:** The `@Symfony` ruleset enforces `phpdoc_trim`, `phpdoc_separation`, and `ordered_imports`, which can generate unexpected diffs on first run.
**Why it happens:** Developers enable `@Symfony` without a dry-run first.
**How to avoid:** Run `vendor/bin/php-cs-fixer check --diff` (not `fix`) in CI. Apply `fix` locally first before committing the `.php-cs-fixer.dist.php`. This way CI only detects regressions.

---

## Code Examples

### phpstan.neon (no phpstan-symfony for standalone bundle)
```neon
# phpstan.neon
parameters:
    level: 9
    paths:
        - src
    excludePaths:
        analyse: []
    treatPhpDocTypesAsCertain: false
```

Source: [PHPStan config reference](https://phpstan.org/config-reference) + reasoning above for omitting phpstan-symfony

### .php-cs-fixer.dist.php
```php
<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->name('*.php')
    ->notPath('bootstrap.php');

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => false,
        'declare_strict_types' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
    ])
    ->setFinder($finder);
```

[CITED: cs.symfony.com/doc/config.html]

### manifest.json (Flex recipe)
```json
{
    "bundles": {
        "Tenancy\\Bundle\\TenancyBundle": ["all"]
    },
    "copy-from-recipe": {
        "config/": "%CONFIG_DIR%/"
    },
    "aliases": ["tenancy"]
}
```

[VERIFIED: github.com/symfony/recipes main/nelmio/cors-bundle/1.5/manifest.json — confirmed format]

### Branch alias in composer.json
```json
"extra": {
    "symfony": {
        "bundles": {
            "Tenancy\\Bundle\\TenancyBundle": ["all"]
        }
    },
    "branch-alias": {
        "dev-master": "1.0.x-dev"
    }
}
```

[CITED: getcomposer.org/doc/04-schema.md]

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Travis CI for PHP bundles | GitHub Actions with `shivammathur/setup-php` | 2020–2022 | Travis is legacy; GHA is the standard |
| `phpunit/phpunit-bridge` for deprecation testing | `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` | Symfony 5.1+ | Still valid; add to test env |
| PHPStan 1.x level 8 | PHPStan 2.x level 9 | PHPStan 2.0 (late 2024) | 2.x is current; 1.x is legacy for new projects |
| `extra.symfony.require` in bundle | Never in bundles — only `SYMFONY_REQUIRE` env | Ongoing education | Bundles must NOT constrain application Symfony version |
| `copy-from-package` in Flex recipes | `copy-from-recipe` | Symfony Flex ~1.x | `copy-from-package` is deprecated for config files |

**Deprecated/outdated:**
- `phpunit/phpunit` PHAR method: use Composer require-dev (already the case here)
- PHPStan 1.x: project should use `^2.1`
- `copy-from-package` in Flex recipes: use `copy-from-recipe` (already planned)

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Packagist package name `danplaton4/tenancy-bundle` will be registered under that exact slug | OSS-01 / Packagist metadata | If slug differs, badge URLs and `support.source` will be wrong |
| A2 | GitHub repo URL is `https://github.com/danplaton4/tenancy-bundle` | composer.json metadata | All `support` and `homepage` URLs will 404 |
| A3 | Badge format from shields.io (`img.shields.io/packagist/v/danplaton4/tenancy-bundle`) is correct | README badges section | Badges will show "invalid" until confirmed |
| A4 | Codecov token will be added as `CODECOV_TOKEN` GitHub secret | CI coverage upload | Coverage upload will fail without the secret set |
| A5 | phpstan.neon does NOT need phpstan-symfony (no containerXmlPath available) | PHPStan job | If phpstan-symfony is desired, a build step to generate container.xml must be added |

**Note on A1–A3:** These depend on the GitHub repository being created and named before Packagist registration. The planner should flag "create GitHub repo" as a prerequisite or Wave 0 task if it does not already exist.

---

## Open Questions

1. **Does the GitHub repository `danplaton4/tenancy-bundle` already exist?**
   - What we know: The project is local-only; no `.github/` directory exists
   - What's unclear: Whether the repo is already created on GitHub under that name
   - Recommendation: Wave 0 task in 09-04 — verify/create repo; all CI badge URLs depend on this

2. **Should `@Symfony:risky` rules be enabled?**
   - What we know: The `@Symfony` ruleset is safe; `@Symfony:risky` adds rules that may change code behavior (e.g., `array_push` → `[]`)
   - What's unclear: Whether the codebase passes risky rules without changes
   - Recommendation: Claude's discretion — default to `false` (disabled) for the initial release; can be enabled later

3. **Symfony 7.4 constraint: `^7.0` or `^7.4`?**
   - What we know: `composer.json` currently requires `^6.4||^7.0`; the installed Symfony is 7.4.x
   - What's unclear: Whether `^7.0` is still correct (Symfony 7.1, 7.2, 7.3 may be out of support)
   - Recommendation: Keep `^6.4||^7.0` — this is correct per Symfony's semver; `^7.0` matches 7.x including 7.4

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | All tasks | ✓ | 8.4.12 | — |
| Composer | All tasks | ✓ | 2.9.5 | — |
| Git | CI / version control | ✓ | 2.50.1 | — |
| PHPUnit | CI / test suite | ✓ | 11.5.55 (via vendor/) | — |
| GitHub Actions runner | CI workflow | ✗ (cloud) | ubuntu-latest | N/A — workflow runs in GitHub cloud |
| phpstan/phpstan | 09-04 CI + local dev | ✗ (not in require-dev) | — | Add to require-dev |
| friendsofphp/php-cs-fixer | 09-04 CI + local dev | ✗ (not in require-dev) | — | Add to require-dev |
| GitHub repository (remote) | CI badges, Packagist | ✗ (not verified) | — | Must create before Packagist registration |

**Missing dependencies with no fallback:**
- GitHub repository creation — must exist before CI badges work and before Packagist registration

**Missing dependencies with fallback:**
- `phpstan/phpstan` and `friendsofphp/php-cs-fixer` — must be added to `require-dev` in plan 09-04; Wave 0 task

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5.55 |
| Config file | `phpunit.xml.dist` |
| Quick run command | `vendor/bin/phpunit --testsuite unit` |
| Full suite command | `vendor/bin/phpunit` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| OSS-01 | `composer.json` is valid and Packagist-parseable | manual | `composer validate --strict` | ✓ (file exists, validate command is the check) |
| OSS-02 | README.md exists and contains required sections | manual | — (human review) | ❌ Wave 0 |
| OSS-03 | Flex recipe manifest.json is valid JSON and installs cleanly | manual + smoke | `php -r "json_decode(file_get_contents('flex/.../manifest.json'), true); echo 'valid';"` | ❌ Wave 0 |
| OSS-04 | CI matrix passes all jobs | integration (GHA) | Push to GitHub triggers workflow | ❌ Wave 0 |

### Sampling Rate

- **Per task commit:** `composer validate --strict && vendor/bin/phpunit --testsuite unit`
- **Per wave merge:** `vendor/bin/phpunit` (full suite)
- **Phase gate:** Full suite green + CI workflow passes on GitHub before `/gsd-verify-work`

### Wave 0 Gaps

- [ ] `README.md` — covers OSS-02; authored in plan 09-03
- [ ] `.github/workflows/ci.yml` — covers OSS-04; authored in plan 09-04
- [ ] `flex/danplaton4/tenancy-bundle/1.0/manifest.json` — covers OSS-03; authored in plan 09-02
- [ ] `flex/danplaton4/tenancy-bundle/1.0/config/packages/tenancy.yaml` — covers OSS-03; authored in plan 09-02
- [ ] `phpstan.neon` — required by CI job; authored in plan 09-04
- [ ] `.php-cs-fixer.dist.php` — required by CI job; authored in plan 09-04
- [ ] `CONTRIBUTING.md` — covers D-06; authored in plan 09-03
- [ ] PHPStan + php-cs-fixer added to `require-dev` — prerequisite for CI job; plan 09-04

*(All gaps are new files — no existing infrastructure to retrofit)*

---

## Security Domain

> `security_enforcement` is not explicitly set to `false` in config.json — treated as enabled.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no | N/A — this phase is documentation/config only |
| V3 Session Management | no | N/A |
| V4 Access Control | no | N/A |
| V5 Input Validation | no | No new user-facing input in this phase |
| V6 Cryptography | no | N/A |

**Note:** Phase 09 creates no new PHP classes and introduces no new user input surfaces. The security surface is zero. The closest concern is supply chain security:

### Supply Chain Concerns for CI/CD

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Pinned third-party Actions versions | Tampering | Use `@v2`, `@v4`, `@v5` (verified semver tags) for `shivammathur/setup-php`, `actions/checkout`, `codecov/codecov-action` — not `@main` or SHAs unless required |
| Codecov token exposure | Information Disclosure | Store as GitHub secret `CODECOV_TOKEN`; never hardcode in YAML |
| `composer install` with untrusted plugins | Tampering | `composer config --global allow-plugins` restricts which plugins can run; `symfony/flex` is trusted |

[ASSUMED - ASVS V1-V14 inapplicability assessment based on phase scope; no security controls added or removed]

---

## Sources

### Primary (HIGH confidence)
- `github.com/SymfonyTest/symfony-bundle-test` README — canonical GitHub Actions CI pattern for Symfony bundles, verified YAML
- `github.com/symfony/recipes` — `nelmio/cors-bundle/1.5/manifest.json` confirmed recipe format
- `github.com/symfony/symfony` `.github/workflows/unit-tests.yml` — SYMFONY_REQUIRE env var usage confirmed
- `packagist.org/packages/phpstan/phpstan` — version 2.1.46 (2026-04-01) confirmed
- `packagist.org/packages/phpstan/phpstan-symfony` — version 2.0.15 (2026-02-26) confirmed
- `packagist.org/packages/friendsofphp/php-cs-fixer` — version 3.89.x confirmed
- `getcomposer.org/doc/04-schema.md` — keywords, authors, support, extra schema confirmed
- `cs.symfony.com/doc/config.html` — `.php-cs-fixer.dist.php` format confirmed
- `github.com/phpstan/phpstan-symfony` — `containerXmlPath` requirement confirmed

### Secondary (MEDIUM confidence)
- `symfony.com/doc/current/bundles/best_practices.html` — SYMFONY_REQUIRE, CI best practices, composer.json metadata
- `tenancyforlaravel.com/docs/v3/package-comparison/` — stancl/tenancy feature set for comparison table
- `about.codecov.io/blog` — codecov-action@v5 is current recommendation
- `phpstan.org/config-reference` — level, paths, excludePaths, treatPhpDocTypesAsCertain parameters

### Tertiary (LOW confidence)
- `github.com/RamyHakam/multi_tenancy_bundle` — RamyHakam bundle feature summary (web search, not directly scraped)

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — all package versions verified against Packagist (2026-04-09)
- Architecture: HIGH — recipe format verified against canonical recipe repo; CI pattern verified against symfony-bundle-test
- Pitfalls: MEDIUM-HIGH — most verified against official docs; phpstan-symfony omission is reasoned inference

**Research date:** 2026-04-09
**Valid until:** 2026-05-09 (stable ecosystem; phpstan and php-cs-fixer release frequently but the patterns are stable)
