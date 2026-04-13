# Getting Started

This guide walks you from a freshly installed bundle to a working tenant-resolved request in under 5 minutes. Choose your isolation driver below and follow the corresponding quick path.

## Prerequisites

- Bundle installed (see [Installation](installation.md))
- Doctrine ORM configured (for database isolation drivers)
- At least one DBAL connection configured in `config/packages/doctrine.yaml`

---

## Choose Your Driver

| Driver | Isolation | Best For |
|--------|-----------|----------|
| `database_per_tenant` | Each tenant gets its own database | Maximum isolation; regulatory requirements; large tenants |
| `shared_db` | One database, SQL filter scopes queries | Simpler ops; small-to-medium tenants; hosting constraints |

!!! tip "Not sure which to choose?"
    Start with `database_per_tenant` if you have the operational capacity — it provides true data isolation at the database level. Use `shared_db` if you need to keep all tenant data in a single schema.

---

## Path A: Database-per-Tenant

### Step 1 — Configure tenancy.yaml

```yaml
# config/packages/tenancy.yaml
tenancy:
    driver: database_per_tenant
    database:
        enabled: true
    host:
        app_domain: example.com
```

### Step 2 — Configure Doctrine with Two Entity Managers

The bundle requires a **landlord** entity manager for its own `Tenant` entity, and a **tenant** entity manager that uses `TenantConnection` as its DBAL wrapper class.

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        connections:
            default:
                url: '%env(DATABASE_URL)%'
            tenant:
                url: '%env(DATABASE_URL)%'
                wrapper_class: Tenancy\Bundle\DBAL\TenantConnection

    orm:
        entity_managers:
            landlord:
                connection: default
                # Tenancy\Bundle\Entity\Tenant mapping is prepended automatically
            default:
                connection: tenant
                mappings:
                    App:
                        is_bundle: false
                        type: attribute
                        dir: '%kernel.project_dir%/src/Entity'
                        prefix: App\Entity
                        alias: App
```

!!! note "Entity manager naming"
    The landlord EM must be named `landlord`. The tenant EM is your default EM (named `default`). The bundle rewires `DoctrineTenantProvider` to the `landlord` EM automatically when `database.enabled: true`.

### Step 3 — Create the Tenant Record

The bundle ships with a `Tenant` entity stored in the `tenancy_tenants` table of the landlord database. Run migrations to create this table:

```bash
bin/console doctrine:migrations:migrate --em=landlord
```

Then create your first tenant. You can use a fixture, a command, or a data fixture:

```php
<?php

declare(strict_types=1);

use Tenancy\Bundle\Entity\Tenant;

$tenant = new Tenant('acme', 'Acme Corporation');
$tenant->setConnectionConfig([
    'driver'   => 'pdo_mysql',
    'host'     => 'localhost',
    'port'     => 3306,
    'dbname'   => 'tenant_acme',
    'user'     => 'acme_user',
    'password' => 'secret',
]);

$landlordEm->persist($tenant);
$landlordEm->flush();
```

The `Tenant` entity fields:

| Field | Type | Description |
|-------|------|-------------|
| `slug` | `string` (PK, max 63) | URL-safe identifier — used in subdomains and headers |
| `name` | `string` | Human-readable display name |
| `domain` | `string\|null` | Custom domain (e.g. `acme.com`) — optional |
| `connectionConfig` | `array` (JSON) | DBAL connection parameters for this tenant's database |
| `isActive` | `bool` | When `false`, requests from this tenant throw `TenantInactiveException` |
| `createdAt` | `DateTimeImmutable` | Set via `#[PrePersist]` |
| `updatedAt` | `DateTimeImmutable` | Set via `#[PreUpdate]` |

### Step 4 — Make a Request

With `app_domain: example.com` configured, a request to `acme.example.com` is automatically resolved:

1. `TenantContextOrchestrator` fires at `kernel.request` priority 20
2. `HostResolver` extracts slug `acme` from the subdomain
3. `DoctrineTenantProvider` loads the `Tenant` entity from the landlord DB
4. `TenantResolved` event fires
5. `DatabaseSwitchBootstrapper` calls `TenantConnection::switchTo($tenant)`, swapping the DBAL connection parameters to the tenant's database
6. Your controller runs — all Doctrine queries go to `tenant_acme`

---

## Path B: Shared-DB

### Step 1 — Configure tenancy.yaml

```yaml
# config/packages/tenancy.yaml
tenancy:
    driver: shared_db
    host:
        app_domain: example.com
```

!!! warning "shared_db and database.enabled are mutually exclusive"
    Setting both `driver: shared_db` and `database.enabled: true` is rejected at container compile time with a clear error message. These are two different isolation strategies — you must choose one.

### Step 2 — Configure Doctrine

In shared-DB mode you only need a single connection and entity manager. The bundle registers the `tenancy_aware` Doctrine SQL filter automatically:

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        url: '%env(DATABASE_URL)%'

    orm:
        auto_mapping: true
        # The bundle prepends the tenancy_aware filter automatically:
        # filters:
        #     tenancy_aware:
        #         class: Tenancy\Bundle\Filter\TenantAwareFilter
        #         enabled: true
```

### Step 3 — Mark Entities with #[TenantAware]

Add the `#[TenantAware]` attribute to any entity that belongs to a tenant. The SQL filter automatically appends a `WHERE tenant_id = '<slug>'` clause to all queries for these entities:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Tenancy\Bundle\Attribute\TenantAware;

#[ORM\Entity]
#[TenantAware]
class Invoice
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private string $tenantId;

    // ... other fields
}
```

!!! warning "tenant_id column required"
    Every `#[TenantAware]` entity must have a `tenant_id` column. The SQL filter generates `WHERE alias.tenant_id = '<slug>'` — if the column does not exist, queries will fail at runtime.

### Step 4 — Make a Request

A request to `acme.example.com` follows the same resolution path. Instead of switching connections, `SharedDriver::boot()` injects the active `TenantContext` into `TenantAwareFilter`. From that point on, every Doctrine query for a `#[TenantAware]` entity is automatically scoped to `acme`.

---

## What Happens on Every Request

```
HTTP Request
    |
kernel.request (priority 20)
    |
TenantContextOrchestrator
    |
ResolverChain.resolve()
    ├── HostResolver    (priority 30)  ← acme.example.com → slug: acme
    ├── HeaderResolver  (priority 20)  ← X-Tenant-ID: acme
    ├── QueryParamResolver (priority 10) ← ?_tenant=acme
    └── (no match → TenantNotFoundException → request proceeds without tenant)
    |
TenantResolved event
    |
BootstrapperChain.boot()
    ├── DatabaseSwitchBootstrapper  (database_per_tenant mode)
    │   └── TenantConnection::switchTo($tenant)
    ├── SharedDriver.boot()          (shared_db mode)
    │   └── TenantAwareFilter::setTenantContext(...)
    └── DoctrineBootstrapper
        └── EntityManager::clear()  (prevent identity map cross-tenant pollution)
    |
TenantBootstrapped event
    |
Controller / Handler runs
    |
kernel.terminate
    |
TenantContextCleared event
    |
BootstrapperChain.clear()  (reverse order)
```

---

## Next Steps

- [Configuration Reference](configuration.md) — every `tenancy.yaml` key with types and defaults
- [Resolvers](resolvers.md) — configure and extend the resolver chain
- [Database-per-Tenant](database-per-tenant.md) — deep dive into the DBAL wrapper mechanics
- [Shared-DB Driver](shared-db.md) — deep dive into the SQL filter approach
