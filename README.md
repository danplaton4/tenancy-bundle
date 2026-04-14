[![CI](https://github.com/danplaton4/tenancy-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/danplaton4/tenancy-bundle/actions/workflows/ci.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/danplaton4/tenancy-bundle.svg)](https://packagist.org/packages/danplaton4/tenancy-bundle)
[![PHP Version](https://img.shields.io/packagist/php-v/danplaton4/tenancy-bundle.svg)](https://packagist.org/packages/danplaton4/tenancy-bundle)
[![License](https://img.shields.io/packagist/l/danplaton4/tenancy-bundle.svg)](https://packagist.org/packages/danplaton4/tenancy-bundle)
[![codecov](https://codecov.io/gh/danplaton4/tenancy-bundle/branch/master/graph/badge.svg)](https://codecov.io/gh/danplaton4/tenancy-bundle)

# Tenancy Bundle

**Multi-tenancy for Symfony. Zero boilerplate, zero leaks.**

Laravel has `stancl/tenancy`. Symfony had nothing comparable — until now. This bundle treats tenancy as a first-class citizen of the Symfony kernel. When a tenant is resolved, every service automatically reconfigures itself: database connection switches, cache namespace isolates, Messenger stamps propagate context. No manual wiring. No leaks.

## Quick Start

**1. Install:**

```bash
composer require danplaton4/tenancy-bundle
```

Register the bundle in `config/bundles.php`, then run `bin/console tenancy:init` to generate `config/packages/tenancy.yaml`.

**2. Configure** (`config/packages/tenancy.yaml`):

```yaml
tenancy:
    driver: database_per_tenant
    database:
        enabled: true
```

**3. Mark your entities** (`#[TenantAware]` for shared-DB mode):

```php
use Tenancy\Bundle\Attribute\TenantAware;

#[ORM\Entity]
#[TenantAware]
class Invoice
{
    // Doctrine SQL filter automatically scopes queries to the active tenant
}
```

See the sections below for resolver configuration, shared-DB mode, Messenger integration, and more.

## Features

- **Database-per-tenant** — DBAL connection switching at runtime per tenant
- **Shared-database** — Doctrine SQL filter with `#[TenantAware]` attribute, zero manual query scoping
- **4 built-in resolvers** — subdomain, `X-Tenant-ID` header, query param, CLI `--tenant` flag
- **Cache namespace isolation** — per-tenant cache pool prefixing, no cross-tenant cache bleed
- **Messenger context propagation** — `TenantStamp` attached to every envelope, re-booted on consume
- **CLI commands** — `tenancy:init` (scaffold config), `tenancy:migrate` (run migrations per tenant), `tenancy:run` (wrap any command with tenant context)
- **PHPUnit testing trait** — `InteractsWithTenancy` sets up a clean tenant DB/schema per test method
- **Strict mode** — `TenantMissingException` thrown when `#[TenantAware]` entity is queried with no active tenant; on by default

## How It Works

The bundle hooks into the Symfony kernel via a `kernel.request` listener at priority 20 (above Security at 8, below Router at 32). A resolver chain identifies the tenant from the request. Once resolved, `BootstrapperChain` runs every registered bootstrapper to reconfigure its subsystem. On `kernel.terminate`, tenant context is cleared.

```
Request -> Router -> TenantContextOrchestrator (priority 20)
                          |
                    ResolverChain
                    (Host / Header / QueryParam / Console)
                          |
                  TenantResolved event
                          |
                  BootstrapperChain.boot()
                   - DatabaseSwitchBootstrapper
                   - DoctrineBootstrapper
                   - CacheBootstrapper
                          |
                  TenantBootstrapped event
                          |
                    Controller runs
                          |
                  kernel.terminate
                          |
                  TenantContextCleared event
```

Bootstrappers are Symfony services tagged with `tenancy.bootstrapper` — add your own by implementing `TenantBootstrapperInterface` and tagging the service. No bundle internals to modify.

## Comparison

| Feature | danplaton4/tenancy-bundle | stancl/tenancy (Laravel) | RamyHakam (Symfony) | Manual |
|---------|:-------------------------:|:------------------------:|:-------------------:|:------:|
| Database-per-tenant | Yes | Yes | Yes | DIY |
| Shared-DB (SQL filter) | Yes | Yes | No | DIY |
| `#[TenantAware]` attribute | Yes | No (uses traits) | No | No |
| Cache isolation | Yes | Yes | No | No |
| Messenger/Queue context | Yes | Yes | No | No |
| Subdomain + domain resolution | Yes | Yes | Yes | DIY |
| CLI tenant context | Yes | Yes | No | No |
| Strict mode (no-tenant = error) | Yes (default ON) | No | No | No |
| `tenancy:init` scaffolding | Yes | N/A (Laravel) | No | No |
| PHPUnit testing trait | Yes | Yes | No | No |
| PHPStan level 9 | Yes | No | No | No |
| Event-driven bootstrappers | Yes | Bootstrapper classes | No | No |

## Philosophy

A data leak across tenants is a security incident, not a config mistake — so strict mode is **on by default**. Opt out explicitly if you understand the trade-off. The bundle is a kernel extension, not just a database switcher: every Symfony subsystem (database, cache, queue, filesystem) participates in the tenant lifecycle through the same event-driven bootstrapper model. The 40 source files and 68 test files (1.7:1 ratio) reflect a production-readiness commitment: this is not a proof of concept.

## Requirements

- PHP `^8.2`
- Symfony `^7.4` or `^8.0`
- Optional: `doctrine/orm`, `doctrine/dbal`, `doctrine/migrations`, `symfony/messenger`

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## License

MIT License. See [LICENSE](LICENSE).
