# Development Setup

Everything you need to go from a fresh checkout to a passing test suite.

## Prerequisites

- **PHP 8.2+** — check with `php -v`
- **Composer** — check with `composer --version`
- **Git**

No database server is required. Integration tests use SQLite `:memory:` databases that are
created automatically.

## Clone and Install

```bash
git clone https://github.com/danplaton4/tenancy-bundle.git
cd tenancy-bundle
composer install
```

## Run the Test Suite

```bash
# Full suite (unit + integration)
vendor/bin/phpunit

# Unit tests only (fast, no kernel boot, no SQLite)
vendor/bin/phpunit --testsuite unit

# Integration tests only (full kernel boot, SQLite)
vendor/bin/phpunit --testsuite integration

# Single test file
vendor/bin/phpunit tests/Integration/DBAL/DatabasePerTenantMiddlewareIntegrationTest.php
```

All tests should pass before you start making changes. If they do not, open an issue.

## Static Analysis

```bash
vendor/bin/phpstan analyse             # PHPStan level 9
```

PHPStan runs at the maximum level (9) without a baseline file — every warning is a real
problem. Configuration lives in `phpstan.neon`.

## Code Style

```bash
vendor/bin/php-cs-fixer fix            # Auto-fix style issues (run before committing)
vendor/bin/php-cs-fixer check --diff   # Check only — what CI runs
```

The project uses the `@Symfony` ruleset. Configuration lives in `.php-cs-fixer.dist.php`.

## CI Jobs

Every pull request must pass all 7 CI jobs before merge:

| Job | Description |
|-----|-------------|
| `tests` | Full PHPUnit suite on PHP 8.2 / 8.3 / 8.4 against Symfony 7.4, plus PHP 8.4 against Symfony 8.0 |
| `coverage` | PHPUnit with Xdebug on PHP 8.4 / Symfony 7.4, uploads to Codecov |
| `phpstan` | PHPStan level 9 on PHP 8.4 |
| `cs-fixer` | `php-cs-fixer check --diff --allow-risky=yes` on PHP 8.2 |
| `no-doctrine` | Removes `doctrine/orm`, `doctrine/dbal`, `doctrine/doctrine-bundle`, and `doctrine/migrations`, then runs the unit test subsets that must pass without Doctrine installed |
| `prefer-lowest` | `composer install --prefer-lowest --prefer-stable` on PHP 8.2 / Symfony 7.4, then full PHPUnit suite |
| `no-messenger` | Removes `symfony/messenger`, then runs unit test subsets that must pass without Messenger installed |

The `no-doctrine` and `no-messenger` jobs verify that the optional-dependency guard pattern
(`class_exists()` / `interface_exists()`) is working correctly — see
[Coding Standards](coding-standards.md) for details.

## Verify Your Setup

Run this before starting any work:

```bash
vendor/bin/phpunit && vendor/bin/phpstan analyse && vendor/bin/php-cs-fixer check --diff
```

All three commands should exit 0. If they do not, the repository is in a broken state —
open an issue.

## Useful Development Commands

```bash
# Regenerate composer.lock (after editing composer.json)
composer update

# Check for dependency conflicts
composer diagnose

# See which test suites are configured
cat phpunit.xml.dist
```

## See Also

- [CONTRIBUTING.md](https://github.com/danplaton4/tenancy-bundle/blob/master/CONTRIBUTING.md) — condensed quick-start guide
- [Coding Standards](coding-standards.md) — php-cs-fixer and PHPStan requirements
- [PR Workflow](pr-workflow.md) — from fork to merged PR
