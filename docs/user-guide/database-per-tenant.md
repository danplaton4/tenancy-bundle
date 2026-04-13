# Database-per-Tenant Driver

In database-per-tenant mode, each tenant gets its own physical database. The bundle switches the
DBAL connection at runtime — zero application code changes required. This provides **maximum
isolation**: one tenant's data is physically separate from another's.

## Overview

Two entity managers are configured:

- **`landlord`** — the central tenant registry. Stores `Tenant` records. Never changes connection.
- **`tenant`** — the runtime-switched EM. All application queries go here. Switches database
  on every tenant request.

When a request arrives, `DatabaseSwitchBootstrapper::boot()` calls
`TenantConnection::switchTenant()` with the active tenant's connection config. On request end,
`TenantConnection::reset()` restores the original placeholder connection.

## Configuration

### Tenancy Config

=== "YAML"

    ```yaml
    # config/packages/tenancy.yaml
    tenancy:
        driver: database_per_tenant
        database:
            enabled: true
        host:
            app_domain: yourapp.com  # for subdomain resolver
    ```

=== "PHP"

    ```php
    // config/packages/tenancy.php
    return static function (Tenancy\Bundle\TenancyBundle $tenancy): void {
        $tenancy->driver('database_per_tenant');
        $tenancy->database()->enabled(true);
        $tenancy->host()->appDomain('yourapp.com');
    };
    ```

### Doctrine Config

Configure two connections and two entity managers:

=== "YAML"

    ```yaml
    # config/packages/doctrine.yaml
    doctrine:
        dbal:
            default_connection: landlord
            connections:
                landlord:
                    url: '%env(DATABASE_URL)%'
                tenant:
                    url: 'sqlite:///:memory:'  # placeholder — switched at runtime
                    wrapper_class: Tenancy\Bundle\DBAL\TenantConnection

        orm:
            default_entity_manager: landlord
            entity_managers:
                landlord:
                    connection: landlord
                    mappings:
                        App:
                            type: attribute
                            dir: '%kernel.project_dir%/src/Entity/Landlord'
                            prefix: App\Entity\Landlord
                tenant:
                    connection: tenant
                    mappings:
                        AppTenant:
                            type: attribute
                            dir: '%kernel.project_dir%/src/Entity/Tenant'
                            prefix: App\Entity\Tenant
    ```

=== "PHP"

    ```php
    // config/packages/doctrine.php
    use Symfony\Config\DoctrineConfig;

    return static function (DoctrineConfig $doctrine): void {
        $doctrine->dbal()
            ->defaultConnection('landlord')
            ->connection('landlord')->url('%env(DATABASE_URL)%')
            ->connection('tenant')
                ->url('sqlite:///:memory:')
                ->wrapperClass(\Tenancy\Bundle\DBAL\TenantConnection::class);

        $doctrine->orm()
            ->defaultEntityManager('landlord')
            ->entityManager('landlord')
                ->connection('landlord')
                ->mapping('App')
                    ->type('attribute')
                    ->dir('%kernel.project_dir%/src/Entity/Landlord')
                    ->prefix('App\\Entity\\Landlord');

        $doctrine->orm()
            ->entityManager('tenant')
                ->connection('tenant')
                ->mapping('AppTenant')
                    ->type('attribute')
                    ->dir('%kernel.project_dir%/src/Entity/Tenant')
                    ->prefix('App\\Entity\\Tenant');
    };
    ```

!!! tip "Placeholder URL"
    The tenant connection URL (`sqlite:///:memory:`) is a placeholder. It is never actually used
    to open a real connection. `TenantConnection::switchTenant()` overwrites these params before
    the first query executes.

## Tenant Entity and Connection Config

The built-in `Tenant` entity stores per-tenant database credentials as a JSON field:

```php
<?php

declare(strict_types=1);

use Tenancy\Bundle\Entity\Tenant;

// In a fixture, admin controller, or provisioning service:
$tenant = new Tenant('acme', 'Acme Corp');
$tenant->setDomain('acme.yourapp.com');
$tenant->setConnectionConfig([
    'driver'   => 'pdo_mysql',
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'dbname'   => 'tenant_acme',
    'user'     => 'acme_user',
    'password' => 'secret',
]);

$landlordEm->persist($tenant);
$landlordEm->flush();
```

The `connectionConfig` array is passed directly to `TenantConnection::switchTenant()` and merged
over the original placeholder params via `array_merge()`. Any DBAL connection parameter is valid
here — `host`, `port`, `dbname`, `user`, `password`, `driver`, `charset`, etc.

### Supported Drivers

Any DBAL-supported driver works:

| Driver | `driver` value |
|--------|---------------|
| MySQL / MariaDB | `pdo_mysql` |
| PostgreSQL | `pdo_pgsql` |
| SQLite (testing) | `pdo_sqlite` |

## How It Works

### The `wrapperClass` Pattern

`TenantConnection` is a subclass of Doctrine DBAL 4's `Connection` class, registered via the
`wrapper_class` option. DBAL's `DriverManager` instantiates `TenantConnection` instead of the
base `Connection`. This gives the bundle a place to intercept connection setup.

### `switchTenant()` Internals

```php
// src/DBAL/TenantConnection.php (simplified)
public function switchTenant(array $tenantConnectionConfig): void
{
    // Merge tenant params over original placeholder params
    $merged = array_merge($this->originalParams, $tenantConnectionConfig);

    // Mutate DBAL 4's private $params via ReflectionProperty captured at construct time
    $this->paramsReflector->setValue($this, $merged);

    // Close the current connection — forces lazy reconnect on next query
    $this->close();
}
```

The `originalParams` are captured at construction time (from the placeholder URL). The
`ReflectionProperty` is also created at construction, avoiding repeated reflection overhead on
every tenant switch.

### Request Lifecycle

1. Request arrives at `TenantContextOrchestrator` (priority 20 on `kernel.request`)
2. Resolver chain identifies tenant → `TenantContext::setTenant()` called
3. `BootstrapperChain::boot()` fires → `DatabaseSwitchBootstrapper::boot()` calls `switchTenant()`
4. DBAL connection is now pointing at the tenant's database
5. Application controller runs against tenant data
6. Request ends → `BootstrapperChain::clear()` fires → `DatabaseSwitchBootstrapper::clear()` calls `reset()`
7. DBAL connection restored to original placeholder

### Entity Manager Isolation

`EntityManagerResetListener` listens for `TenantContextCleared` and calls `resetManager()` on the
registry. This clears the tenant EM's identity map, preventing entity objects from leaking between
requests.

!!! warning "Stale EM References"
    `resetManager()` is called on every tenant switch. Any `EntityManagerInterface` reference
    obtained before the switch may be invalid after. Always retrieve the EM from the registry
    (e.g., `$doctrine->getManager('tenant')`) rather than caching it as a class property.

## Migrations

Use the `tenancy:migrate` command to run Doctrine Migrations for all tenants or a specific one.
See [CLI Commands](cli-commands.md) for full documentation.

```bash
# Run migrations for all tenants
bin/console tenancy:migrate

# Run migrations for a single tenant
bin/console tenancy:migrate --tenant=acme
```

## See Also

- [Shared-DB Driver](shared-db.md) — single database, SQL filter isolation
- [CLI Commands](cli-commands.md) — `tenancy:migrate`, `tenancy:run`
- [Testing](testing.md) — `InteractsWithTenancy` trait for database-per-tenant tests
- [Examples: SaaS Subdomain](examples/saas-subdomain.md) — end-to-end tutorial
