# Coding Standards

All code in `src/` must pass PHP CS Fixer and PHPStan before a PR is merged. CI enforces
both automatically.

## PHP CS Fixer

The project uses the `@Symfony` ruleset via `friendsofphp/php-cs-fixer`.

**Auto-fix before committing:**

```bash
vendor/bin/php-cs-fixer fix
```

**Check without modifying (what CI runs):**

```bash
vendor/bin/php-cs-fixer check --diff --allow-risky=yes
```

Configuration lives in `.php-cs-fixer.dist.php` at the repository root. The `@Symfony`
ruleset enforces consistent spacing, import ordering, trailing commas, and dozens of other
style rules. Running `fix` before every commit is the easiest way to stay green.

## PHPStan Level 9

All code in `src/` must pass PHPStan at level 9 without any baseline file — every
reported issue is a real problem that must be fixed before the PR can be merged.

```bash
vendor/bin/phpstan analyse
```

Configuration lives in `phpstan.neon`. Level 9 catches:

- Missing return type declarations
- Undefined variables and properties
- Incorrect generic type annotations (`@var array<string, mixed>` etc.)
- Dead code branches
- Incorrect method call signatures

If you add a new class, make sure it carries complete type annotations. The CI job will
catch missing return types, undefined variables, and incorrect generics.

## `declare(strict_types=1)`

Every PHP file must start with `declare(strict_types=1)`:

```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Resolver;
```

This is enforced by PHP CS Fixer and enforced at the language level — implicit type coercion
is disabled across the entire bundle.

## Optional Dependency Guards

Doctrine and Messenger are `require-dev` dependencies — they must not be hard-imported
in production code. Always guard with `class_exists()` or `interface_exists()`:

```php
// CORRECT — guarded import in a compiler pass
if (interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
    $container->addCompilerPass(new MessengerMiddlewarePass(), ...);
}

// CORRECT — guarded class instantiation
if (class_exists(\Doctrine\Migrations\DependencyFactory::class)) {
    $services->set('tenancy.command.migrate', TenantMigrateCommand::class)
        ->tag('console.command');
}
```

The `no-doctrine` and `no-messenger` CI jobs verify these guards by removing the packages
and running the test suite. Any hard import of an optional dependency will cause these jobs
to fail.

## Naming Conventions

| Pattern | Convention |
|---------|------------|
| Classes | `final` where possible — open for extension only when designed for it |
| Interfaces | `Interface` suffix (e.g. `TenantResolverInterface`) |
| Events | PSR-14 readonly objects (e.g. `TenantResolved`, `TenantBootstrapped`) |
| Compiler passes | `Pass` suffix (e.g. `BootstrapperChainPass`) |
| Tags | `tenancy.bootstrapper`, `tenancy.resolver` |
| Service IDs | `tenancy.` prefix for bundle-owned services |

## Doctrine in Tests

Integration tests that need a database use SQLite `:memory:` — no MySQL or PostgreSQL
is required. When writing test kernels, follow the existing pattern in
`tests/Integration/Support/` and use `setUpBeforeClass` / `tearDownAfterClass` for
kernel lifecycle management. See [Test Infrastructure](test-infrastructure.md) for details.
