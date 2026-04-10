# CLAUDE.md

## Project

Symfony multi-tenancy bundle (`danplaton4/tenancy-bundle`). When a tenant is resolved, every Symfony service automatically re-configures itself for that tenant — zero boilerplate, zero leaks.

Targets PHP 8.2+ and Symfony 6.4/7.x. Published on Packagist as a reusable bundle.

## Stack

- **Language:** PHP 8.2+ (strict_types everywhere)
- **Framework:** Symfony 6.4 / 7.x (bundle architecture)
- **ORM:** Doctrine ORM/DBAL (optional dependency — guarded by `class_exists`/`interface_exists`)
- **Testing:** PHPUnit 11 (unit + integration suites)
- **Static Analysis:** PHPStan level 9
- **Code Style:** php-cs-fixer with `@Symfony` ruleset
- **CI:** GitHub Actions (PHP 8.2/8.3/8.4 × Symfony 6.4/7.4 matrix)

## Architecture

Event-driven bootstrapper model:

1. **Request arrives** → `TenantContextOrchestrator` (kernel.request priority 20) fires resolver chain
2. **Tenant identified** → `TenantResolved` event dispatched
3. **Bootstrappers run** → `BootstrapperChain` calls each bootstrapper's `boot()` (DB switch, cache namespace, Doctrine filter, etc.)
4. **TenantBootstrapped** event dispatched → application runs in tenant context
5. **Request ends** → `TenantContextCleared` event, bootstrappers `clear()` in reverse order

Key directories:
- `src/` — Bundle source (40 files across 18 namespaces)
- `tests/Unit/` — Pure unit tests (no container, no DB)
- `tests/Integration/` — Full kernel boot tests with SQLite
- `config/services.php` — Bundle DI service definitions

Two isolation drivers (optional, not both required):
- **database-per-tenant:** `TenantConnection` DBAL wrapperClass swaps connection params at runtime
- **shared-db:** `TenantAwareFilter` Doctrine SQL filter scopes queries by `tenant_id`

## Conventions

- Doctrine dependencies are **optional** — always guard with `class_exists()` or `interface_exists()`, never hard-import
- `strict_mode` defaults to ON — a data leak across tenants is a security incident
- `TenantContext` is a zero-dependency value holder — no constructor params, no circular deps
- Bootstrapper `clear()` runs in **reverse** order of `boot()`
- Test kernels use `setUpBeforeClass`/`tearDownAfterClass` for kernel lifecycle
- Integration tests use SQLite `:memory:` databases — no external DB required
- Compiler passes handle all service wiring — no manual DI config needed by users

## Commands

```bash
# Run tests
vendor/bin/phpunit                    # Full suite
vendor/bin/phpunit --testsuite unit   # Unit tests only

# Static analysis
vendor/bin/phpstan analyse            # PHPStan level 9

# Code style
vendor/bin/php-cs-fixer check --diff  # Check only
vendor/bin/php-cs-fixer fix           # Auto-fix
```
