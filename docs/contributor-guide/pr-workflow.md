# PR Workflow

How to go from "I want to contribute" to a merged PR.

## 1. Fork and Create a Branch

Fork the repository on GitHub, then create a feature branch from `master`:

```bash
git clone https://github.com/YOUR_USERNAME/tenancy-bundle.git
cd tenancy-bundle
git checkout -b feature/my-change
```

Branch naming is not enforced. Use something descriptive: `fix/resolver-null-guard`,
`feat/filesystem-bootstrapper`, `docs/update-setup`.

## 2. One PR = One Change

Keep PRs focused. One feature or bug fix per PR. This makes review faster and keeps git
history clean.

If you find an unrelated bug while working on your feature, open a separate issue (or PR)
for it.

## 3. Write Tests

The project maintains a **1.7:1 test-to-source file ratio**. New features should include
proportional test coverage:

- If you add two source classes, add at least three test classes.
- **Unit tests** go in `tests/Unit/` — no kernel, no database, mocked dependencies.
- **Integration tests** go in `tests/Integration/` — full kernel boot, SQLite, real
  container compilation.

```bash
# Run full suite to verify nothing is broken
vendor/bin/phpunit

# Run only your new tests
vendor/bin/phpunit tests/Unit/Resolver/MyResolverTest.php
```

## 4. Pass All CI Checks

All 7 CI jobs must be green before merge:

```bash
# Run these locally before pushing
vendor/bin/phpunit                          # all tests
vendor/bin/phpstan analyse                  # PHPStan level 9
vendor/bin/php-cs-fixer check --diff        # code style
```

See [Development Setup](setup.md) for the full list of CI jobs and what each checks.

## 5. Commit Messages

Conventional Commits format is preferred but not enforced:

```
feat: add PathResolver for URL-based tenant identification
fix: guard TenantAwareFilter when no tenant is active
docs: update custom-resolver guide with priority examples
test: add integration test for PathResolver priority ordering
```

## 6. Open the Pull Request

Push your branch and open a PR against `master`:

1. **Title** — short and descriptive (what does this do?)
2. **Description** — explain the why, not just the what. Link the relevant GitHub issue
   if one exists: `Closes #42` or `Relates to #42`.
3. **Testing notes** — mention how you tested the change (unit test, integration test,
   manual test with a sample app).

## 7. Review Checklist

Before marking your PR as ready for review, confirm:

- [ ] `vendor/bin/phpunit` exits 0
- [ ] `vendor/bin/phpstan analyse` exits 0 (no new issues introduced)
- [ ] `vendor/bin/php-cs-fixer check --diff` exits 0
- [ ] Optional dependencies are guarded with `class_exists()` / `interface_exists()` — no hard imports of Doctrine or Messenger in `src/`
- [ ] All new classes have `declare(strict_types=1)`
- [ ] New features have tests (unit and/or integration as appropriate)
- [ ] Public API changes are reflected in the relevant documentation pages

## 8. After Review

The maintainer may request changes. Push additional commits to the same branch —
do not force-push unless asked. Once approved, the PR will be squashed or rebased onto
`master`.

## Reporting Bugs

If you find a bug but are not ready to fix it, open a
[GitHub Issue](https://github.com/danplaton4/tenancy-bundle/issues) with:

- PHP version (`php -v`)
- Symfony version (`composer show symfony/framework-bundle | grep versions`)
- Driver mode (`database_per_tenant` or `shared_db`)
- A minimal reproduction — ideally a failing test
- The full exception message and stack trace
