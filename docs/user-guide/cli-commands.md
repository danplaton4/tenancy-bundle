# CLI Commands

The bundle provides three console commands: `tenancy:init` for scaffolding configuration,
`tenancy:migrate` for running Doctrine Migrations across all tenants, and `tenancy:run` for
executing any Symfony console command within a specific tenant's context.

## tenancy:init

Scaffold a fully commented `config/packages/tenancy.yaml` with all configuration keys, Doctrine
detection, and next-steps guidance.

### Usage

```bash
# First-time setup — creates config/packages/tenancy.yaml
bin/console tenancy:init

# Regenerate (overwrite existing file)
bin/console tenancy:init --force
```

### Behavior

- **File creation**: Creates `config/packages/tenancy.yaml` in the project root. If the
  `config/packages/` directory does not exist, it is created automatically.
- **Overwrite protection**: If `config/packages/tenancy.yaml` already exists and `--force` is
  NOT passed, the command prints a warning and exits with failure. Pass `--force` to overwrite.
- **Doctrine detection**: The command checks `interface_exists(EntityManagerInterface::class)`.
  If Doctrine ORM is installed, it recommends `database_per_tenant` as the driver and
  uncomments the `driver:` line in the generated YAML. If Doctrine is absent, it recommends
  `shared_db` and leaves the driver line commented.
- **Next-steps guidance**: After creating the file, the command prints actionable next steps
  (review config, create Tenant entity, configure app_domain, run schema update).
- **No dependencies**: The command is always registered — no optional packages required.

### Output

When Doctrine ORM is detected:

```
 [OK] Created config/packages/tenancy.yaml

 Doctrine ORM detected — recommended driver: database_per_tenant
 Uncomment driver and set database.enabled: true in your config.

 Next Steps
 ----------
  * Review and uncomment the configuration values in config/packages/tenancy.yaml
  * Create your Tenant entity implementing Tenancy\Bundle\TenantInterface
  * Configure your host.app_domain if using subdomain-based resolution
  * Run bin/console doctrine:schema:update or create migrations for the Tenant entity
  * Visit https://github.com/danplaton4/tenancy-bundle for full documentation
```

When Doctrine ORM is NOT detected:

```
 [OK] Created config/packages/tenancy.yaml

 Doctrine ORM not detected — recommended driver: shared_db
 Install doctrine/orm to use database_per_tenant mode.

 Next Steps
 ----------
  * Review and uncomment the configuration values in config/packages/tenancy.yaml
  * Create your Tenant entity implementing Tenancy\Bundle\TenantInterface
  * Configure your host.app_domain if using subdomain-based resolution
  * Run bin/console doctrine:schema:update or create migrations for the Tenant entity
  * Visit https://github.com/danplaton4/tenancy-bundle for full documentation
```

---

## tenancy:migrate

Run Doctrine Migrations for all tenants or a single tenant.

### Requirements

!!! tip "Prerequisites"
    `tenancy:migrate` requires **both**:

    - `tenancy.database.enabled: true` (database-per-tenant driver)
    - `doctrine/migrations` package installed: `composer require doctrine/migrations`

    The command is silently unavailable if either requirement is missing.

### Usage

```bash
# Run pending migrations for all tenants
bin/console tenancy:migrate

# Run pending migrations for a single tenant only
bin/console tenancy:migrate --tenant=acme
```

### Output

The command iterates all tenants from `TenantProviderInterface::findAll()`, boots each tenant's
context, runs pending migrations, and reports per-tenant results:

```
 ✓ acme
 ✓ demo
 ✗ broken-tenant (Connection refused: mysql:host=broken-host;dbname=broken_db)
Completed: 2 succeeded, 1 failed
Failed tenants:
  - broken-tenant
```

### Behavior

- **Continue on failure**: The command does not stop on the first error. If one tenant's
  migration fails, it continues with the remaining tenants and reports all failures at the end.
- **Exit code**: Returns `1` if any tenant migration failed, `0` if all succeeded.
- **No-op tenants**: Tenants with no pending migrations are silently skipped.
- **Shared-DB guard**: Running `tenancy:migrate` with `driver: shared_db` returns an error
  immediately — the command only applies to database-per-tenant mode.

### How It Works

For each tenant, the command:

1. Sets the tenant in `TenantContext` and calls `BootstrapperChain::boot()` — this switches the
   DBAL connection to the tenant's database.
2. Creates a `DependencyFactory` using the switched DBAL connection.
3. Runs all pending migrations up to `latest`.
4. Calls `BootstrapperChain::clear()` in a `finally` block to reset the connection.

This means each tenant migration runs against the correct isolated database, not a shared one.

---

## tenancy:run

Wrap any Symfony console command with a specific tenant's context.

### Usage

```bash
bin/console tenancy:run <slug> "<command string>"
```

### Examples

```bash
# Clear cache for tenant 'acme'
bin/console tenancy:run acme "cache:clear"

# Import data for tenant 'demo'
bin/console tenancy:run demo "app:import-products --format=csv"

# Run a custom application command for tenant 'beta'
bin/console tenancy:run beta "app:send-reports --period=monthly"
```

### How It Works

`tenancy:run` spawns a subprocess (via `symfony/process`) that runs `bin/console` with the inner
command, appending `--tenant=<slug>`. The `ConsoleResolver` in the child process picks up the
`--tenant` argument and resolves the tenant before the command executes:

```
bin/console tenancy:run acme "cache:clear"
  └─ spawns: php bin/console cache:clear --tenant=acme
```

The tenant slug is validated before the subprocess is spawned — if the tenant does not exist or
is inactive, the command fails immediately with a clear error.

### Output Forwarding

All stdout and stderr from the subprocess are forwarded in real time to the parent process output.
Exit codes are propagated — if the inner command returns a non-zero exit code, `tenancy:run`
propagates it.

### Requirements

`symfony/process` is a production dependency of the bundle (it is promoted from `require-dev`).
No additional installation is needed.

---

## See Also

- [Installation](installation.md) — initial setup with tenancy:init
- [Database-per-Tenant](database-per-tenant.md) — connection switching mechanics
- [Testing](testing.md) — running tests with tenant context
- [Architecture: DI Compilation Pipeline](../architecture/di-compilation.md)
