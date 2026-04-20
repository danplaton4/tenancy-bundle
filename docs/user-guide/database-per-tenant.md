# Database-per-Tenant Driver

In database-per-tenant mode, each tenant gets its own physical database. The bundle switches
the DBAL connection at runtime — zero application code changes required. This provides
**maximum isolation**: one tenant's data is physically separate from another's.

## Overview

Two entity managers are configured:

- **`landlord`** — the central tenant registry. Stores `Tenant` records. Never changes connection.
- **`tenant`** — the runtime-switched EM. All application queries go here. Switches database
  on every tenant request.

When a request arrives, `DatabaseSwitchBootstrapper::boot()` calls `$connection->close()` on
the tenant DBAL connection. The bundle's `TenantDriverMiddleware` wraps the tenant
connection's driver; on the next query, DBAL's lazy-reconnect path calls
`TenantAwareDriver::connect()`, which merges the active tenant's `getConnectionConfig()` over
the placeholder params and opens a fresh socket to the tenant database.

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

Configure two connections and two entity managers. The bundle registers its driver
middleware on the `tenant` connection automatically when `tenancy.database.enabled: true`
— no extra Doctrine configuration is required.

=== "YAML"

    ```yaml
    # config/packages/doctrine.yaml (example for MySQL tenants)
    doctrine:
        dbal:
            default_connection: landlord
            connections:
                landlord:
                    url: '%env(DATABASE_URL)%'       # e.g. mysql://app:app@127.0.0.1:3306/landlord
                tenant:
                    # Driver family MUST match your tenant databases (see callout below).
                    # Connection params below are merged with the active tenant's
                    # getConnectionConfig() at connect() time by TenantDriverMiddleware.
                    # The 'dbname' below is a placeholder; it is overridden per-request.
                    driver: pdo_mysql
                    host: '%env(TENANT_DB_HOST)%'
                    user: '%env(TENANT_DB_USER)%'
                    password: '%env(TENANT_DB_PASSWORD)%'
                    dbname: placeholder_tenant

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
                ->driver('pdo_mysql')
                ->host('%env(TENANT_DB_HOST)%')
                ->user('%env(TENANT_DB_USER)%')
                ->password('%env(TENANT_DB_PASSWORD)%')
                ->dbname('placeholder_tenant');

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

!!! warning "Driver family must match"
    The tenant connection's `driver` parameter MUST match the driver family of your actual
    tenant databases. `TenantDriverMiddleware` merges tenant params at `connect()` time,
    but the driver itself is resolved from the placeholder config at container boot. If
    your tenant databases are MySQL, the placeholder `driver:` must be `pdo_mysql`. If
    they are PostgreSQL, use `pdo_pgsql`. You cannot mix driver families across tenants
    within a single connection.

!!! tip "Placeholder parameters"
    The `dbname: placeholder_tenant` on the tenant connection is never actually used to
    open a real connection during a tenant-scoped request. The middleware overrides it
    with the active tenant's `getConnectionConfig()` before each connect.

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

The `connectionConfig` array is merged by `TenantAwareDriver::connect()` over the
placeholder params via `array_merge()` on every lazy reconnect. Any discrete DBAL
connection parameter is valid here — `host`, `port`, `dbname`, `user`, `password`,
`charset`, etc.

!!! danger "Do not return a `url` key from getConnectionConfig()"
    DBAL parses `url` at DriverManager time, **before** middlewares run. A `url` key in
    the tenant's `getConnectionConfig()` return value is silently ignored. Return
    discrete params only.

### Supported Drivers

Any DBAL-supported driver works, as long as every tenant uses the same driver family as
the placeholder:

| Driver | `driver` value |
|--------|---------------|
| MySQL / MariaDB | `pdo_mysql` |
| PostgreSQL | `pdo_pgsql` |
| SQLite (testing) | `pdo_sqlite` |

## How It Works

### The Middleware Pipeline

1. **At container compile time** — `TenantDriverMiddleware` is registered on the `tenant`
   connection via the `doctrine.middleware` tag with `connection: tenant`. DoctrineBundle
   attaches it to the tenant connection's DBAL configuration automatically.
2. **At connection construction** — DBAL's `DriverManager` resolves the driver from the
   placeholder and walks the middleware chain. `TenantDriverMiddleware::wrap($driver)`
   returns a `TenantAwareDriver`.
3. **On first tenant query** — DBAL's lazy `Connection::connect()` calls
   `$this->driver->connect($params)` which routes through `TenantAwareDriver::connect()`.
   The middleware reads `TenantContext::getTenant()`, merges the active tenant's
   `getConnectionConfig()` over `$params`, and delegates to the real driver's `connect()`.
4. **On tenant switch** — `DatabaseSwitchBootstrapper::boot()` calls
   `$connection->close()`, which nulls the internal driver-connection. The next query
   re-enters step 3 with fresh `TenantContext` state.

See [Architecture: DBAL Driver-Middleware](../architecture/dbal-middleware.md) for the full
pipeline, driver-immutability rationale, and the rejected alternative.

### Request Lifecycle

1. Request arrives at `TenantContextOrchestrator` (priority 20 on `kernel.request`)
2. Resolver chain identifies tenant → `TenantContext::setTenant()` called
3. `BootstrapperChain::boot()` fires → `DatabaseSwitchBootstrapper::boot()` calls
   `$connection->close()`
4. Application controller runs. On the first tenant query, DBAL reconnects through
   `TenantAwareDriver::connect()` — new socket opens against the tenant database
5. Request ends → `BootstrapperChain::clear()` fires → `DatabaseSwitchBootstrapper::clear()`
   also calls `$connection->close()`; with `TenantContext` cleared, the next reconnect
   opens a landlord socket (driven by placeholder params only)

### Entity Manager Isolation

`EntityManagerResetListener` listens for `TenantContextCleared` and resets entity managers
to prevent identity map pollution across tenant switches. The behavior depends on the
active driver:

- **`database_per_tenant` mode**: Only the `tenant` EM is reset via `resetManager('tenant')`.
  The `landlord` EM is never reset — it remains stable across tenant switches.
- **`shared_db` / single-EM mode**: The default EM is reset via `resetManager(null)`.

!!! warning "Stale EM References"
    `resetManager()` is called on every tenant switch. Any `EntityManagerInterface`
    reference to the **tenant** EM obtained before the switch may be invalid after. Always
    retrieve the tenant EM from the registry (e.g., `$doctrine->getManager('tenant')`)
    rather than caching it as a class property. The `landlord` EM is not affected.

## Migrations

Use the `tenancy:migrate` command to run Doctrine Migrations for all tenants or a specific
one. See [CLI Commands](cli-commands.md) for full documentation.

```bash
# Run migrations for all tenants
bin/console tenancy:migrate

# Run migrations for a single tenant
bin/console tenancy:migrate --tenant=acme
```

## See Also

- [Architecture: DBAL Driver-Middleware](../architecture/dbal-middleware.md) — connection
  switching internals
- [Shared-DB Driver](shared-db.md) — single database, SQL filter isolation
- [CLI Commands](cli-commands.md) — `tenancy:migrate`, `tenancy:run`
- [Testing](testing.md) — `InteractsWithTenancy` trait for database-per-tenant tests
- [Examples: SaaS Subdomain](examples/saas-subdomain.md) — end-to-end tutorial
