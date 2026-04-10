# Contributing to Tenancy Bundle

Thank you for your interest in contributing. This guide covers everything you need to get a PR merged.

## Getting Started

```bash
git clone https://github.com/danplaton4/tenancy-bundle.git
cd tenancy-bundle
composer install
vendor/bin/phpunit
```

All tests should pass before you start making changes. If they do not, open an issue.

## Pull Request Guidelines

- Fork the repository and create a feature branch from `master`
- Keep PRs focused: one feature or fix per PR
- Include tests for all new features (unit tests in `tests/Unit/`, integration tests in `tests/Integration/`)
- All existing tests must pass: `vendor/bin/phpunit`
- Follow the existing code style (enforced by php-cs-fixer — see below)
- Reference the relevant GitHub issue in your PR description if one exists

## Coding Standards

The project uses the `@Symfony` ruleset via `friendsofphp/php-cs-fixer`.

**Fix style issues before committing:**

```bash
vendor/bin/php-cs-fixer fix
```

**Check without modifying (what CI runs):**

```bash
vendor/bin/php-cs-fixer check --diff
```

Configuration lives in `.php-cs-fixer.dist.php` at the repository root.

## Static Analysis

All code in `src/` must pass PHPStan at level 9 without baseline exceptions.

```bash
vendor/bin/phpstan analyse
```

Configuration lives in `phpstan.neon` at the repository root.

If you add a new class, make sure it carries the correct type annotations — the CI job will catch missing return types, undefined variables, and incorrect generics.

## Test Expectations

**Unit tests** (`tests/Unit/`):
- Fast, no I/O, no real Symfony kernel
- Mock external dependencies (Doctrine ORM, Messenger, etc.)
- One test class per source class

**Integration tests** (`tests/Integration/`):
- Use test kernels (see `tests/Integration/Support/` for patterns)
- Test real service wiring, compiler pass behavior, and DI container correctness
- Can use a real SQLite database for Doctrine tests

**Coverage:**
The project maintains a 1.7:1 test-to-source file ratio. New features should include proportional test coverage — if you add two source classes, add at least three test classes.

**Running specific suites:**

```bash
# Unit tests only
vendor/bin/phpunit --testsuite unit

# Integration tests only
vendor/bin/phpunit --testsuite integration
```

## Reporting Bugs

Use [GitHub Issues](https://github.com/danplaton4/tenancy-bundle/issues). Include:

- PHP version (`php -v`)
- Symfony version (`composer show symfony/framework-bundle | grep versions`)
- Driver mode (`database_per_tenant` or `shared_db`)
- A minimal reproduction — ideally a failing test or a standalone script
- The full exception message and stack trace

## License

By contributing, you agree that your contributions will be licensed under the [MIT License](LICENSE).
