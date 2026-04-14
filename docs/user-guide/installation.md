# Installation

Tenancy Bundle requires **PHP ^8.2** and **Symfony ^7.4 or ^8.0**. It is published on Packagist as [`danplaton4/tenancy-bundle`](https://packagist.org/packages/danplaton4/tenancy-bundle).

---

## 1. Composer Install

```bash
composer require danplaton4/tenancy-bundle
```

---

## 2. Bundle Registration

Add the bundle to `config/bundles.php`:

```php
return [
    // ... other bundles
    Tenancy\Bundle\TenancyBundle::class => ['all' => true],
];
```

Then create `config/packages/tenancy.yaml` with the full defaults:

```yaml
tenancy:
    driver: database_per_tenant
    strict_mode: true
    landlord_connection: default
    tenant_entity_class: Tenancy\Bundle\Entity\Tenant
    cache_prefix_separator: '.'
    database:
        enabled: false
    resolvers:
        - host
        - header
        - query_param
        - console
    host:
        app_domain: ~
```

!!! tip "Or use tenancy:init"
    Instead of creating the config manually, run `bin/console tenancy:init` to generate a fully
    commented `config/packages/tenancy.yaml` with all keys and Doctrine-aware driver recommendations.
    See [CLI Commands](cli-commands.md#tenancyinit) for details.

---

## 3. Optional Dependencies

The bundle uses `class_exists()` and `interface_exists()` guards throughout. Features that require an optional package are silently skipped when that package is absent — you will never get a fatal error for a feature you do not use.

| Feature | Required Package | Notes |
|---------|-----------------|-------|
| Database-per-tenant driver | `doctrine/orm`, `doctrine/dbal`, `doctrine/doctrine-bundle` | DBAL `wrapperClass` connection switching at runtime |
| Shared-DB driver | `doctrine/orm`, `doctrine/dbal`, `doctrine/doctrine-bundle` | Doctrine SQL filter with `#[TenantAware]` attribute |
| Tenant migrations | `doctrine/migrations` | `tenancy:migrate` command |
| Messenger context propagation | `symfony/messenger` | `TenantStamp`, sending/worker middlewares — auto-enrolled in all buses |

!!! note "Core runs without Doctrine"
    If you only need header/subdomain resolution and cache isolation, the bundle runs without any Doctrine package installed. The resolver chain, bootstrapper lifecycle, and cache namespacing are all dependency-free.

---

## 4. Requirements Summary

| Requirement | Version |
|-------------|---------|
| PHP | `^8.2` |
| Symfony | `^7.4` or `^8.0` |
| doctrine/orm *(optional)* | `^2.17` or `^3.0` |
| doctrine/dbal *(optional)* | `^3.6` or `^4.0` |
| doctrine/doctrine-bundle *(optional)* | `^2.11` |
| doctrine/migrations *(optional)* | `^3.7` |
| symfony/messenger *(optional)* | `^7.4` or `^8.0` |

---

## 5. Verification

After installation, confirm the bundle is registered:

```bash
bin/console debug:container tenancy.context
```

Expected output (abbreviated):

```
Information for Service "tenancy.context"
=========================================

 Service ID  tenancy.context
 Class       Tenancy\Bundle\Context\TenantContext
 Tags        -
 Public      no
 Shared      yes
```

If you see `tenancy.context` in the output, the bundle is correctly wired.

Check that the resolver chain is populated:

```bash
bin/console debug:container tenancy.resolver_chain
```

You can also list all tenancy services:

```bash
bin/console debug:container tenancy
```

---

## Next Steps

You are ready to set up your first tenant. Continue to the [Getting Started](getting-started.md) walkthrough for a 5-minute end-to-end setup.
