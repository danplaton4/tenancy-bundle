---
hide:
  - navigation
---

# Tenancy Bundle

**Multi-tenancy for Symfony. Zero boilerplate, zero leaks.**

Laravel has `stancl/tenancy`. Symfony had nothing comparable — until now. This bundle treats tenancy as a first-class citizen of the Symfony kernel. When a tenant is resolved, every service automatically reconfigures itself: database connection switches, cache namespace isolates, Messenger stamps propagate context.

---

## Quick Start

**1. Install:**

```bash
composer require danplaton4/tenancy-bundle
```

Register the bundle in `config/bundles.php`, then run `bin/console tenancy:init` to generate `config/packages/tenancy.yaml` with commented defaults.

**2. Configure** (`config/packages/tenancy.yaml`):

=== "YAML"
    ```yaml
    tenancy:
        driver: database_per_tenant
        database:
            enabled: true
    ```

=== "PHP"
    ```php
    // config/packages/tenancy.php
    use Symfony\Config\TenancyConfig;

    return static function (TenancyConfig $tenancy): void {
        $tenancy->driver('database_per_tenant');
        $tenancy->database()->enabled(true);
    };
    ```

**3. Mark entities** (shared-DB mode):

```php
use Doctrine\ORM\Mapping as ORM;
use Tenancy\Bundle\Attribute\TenantAware;

#[ORM\Entity]
#[TenantAware]
class Invoice
{
    // Doctrine SQL filter automatically scopes queries to the active tenant
}
```

That's it. Subdomain requests resolve tenants, database connections switch, cache isolates.

[:octicons-arrow-right-24: Installation guide](user-guide/installation.md){ .md-button .md-button--primary }
[:octicons-arrow-right-24: Getting started](user-guide/getting-started.md){ .md-button }

---

## Features

| Feature | Description |
|---------|-------------|
| **Database-per-tenant** | DBAL connection switching at runtime via `Doctrine\DBAL\Driver\Middleware` |
| **Shared-database** | Doctrine SQL filter with `#[TenantAware]` attribute |
| **4 built-in resolvers** | Subdomain, `X-Tenant-ID` header, query param, CLI `--tenant` |
| **Cache isolation** | Per-tenant cache namespace — no cross-tenant bleed |
| **Messenger context** | `TenantStamp` on every envelope, re-booted on consume |
| **CLI commands** | `tenancy:init`, `tenancy:migrate`, and `tenancy:run` |
| **PHPUnit trait** | `InteractsWithTenancy` for clean per-test tenant setup |
| **Strict mode** | `TenantMissingException` when querying without tenant (default: ON) |

---

## How It Works

```
Request --> Router (priority 32)
              |
       TenantContextOrchestrator (priority 20)
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

Bootstrappers are Symfony services tagged `tenancy.bootstrapper`. Add your own by implementing `TenantBootstrapperInterface`.

[:octicons-arrow-right-24: Architecture deep dive](architecture/event-lifecycle.md){ .md-button }

---

## Comparison

| Feature | tenancy-bundle | stancl/tenancy (Laravel) | RamyHakam (Symfony) | Manual |
|---------|:--------------:|:------------------------:|:-------------------:|:------:|
| Database-per-tenant | :material-check: | :material-check: | :material-check: | DIY |
| Shared-DB (SQL filter) | :material-check: | :material-check: | :material-close: | DIY |
| `#[TenantAware]` attribute | :material-check: | :material-close: | :material-close: | :material-close: |
| Cache isolation | :material-check: | :material-check: | :material-close: | :material-close: |
| Messenger context | :material-check: | :material-check: | :material-close: | :material-close: |
| Subdomain + domain resolution | :material-check: | :material-check: | :material-check: | DIY |
| CLI tenant context | :material-check: | :material-check: | :material-close: | :material-close: |
| Strict mode (default ON) | :material-check: | :material-close: | :material-close: | :material-close: |
| `tenancy:init` scaffolding | :material-check: | N/A | :material-close: | :material-close: |
| PHPUnit testing trait | :material-check: | :material-check: | :material-close: | :material-close: |
| PHPStan level 9 | :material-check: | :material-close: | :material-close: | :material-close: |

---

## Requirements

- PHP `^8.2`
- Symfony `^7.4` or `^8.0`
- Optional: `doctrine/orm`, `doctrine/dbal`, `doctrine/migrations`, `symfony/messenger`
