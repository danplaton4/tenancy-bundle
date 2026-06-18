# CLI Commands

The bundle provides four commands: a one-shot setup command — `tenancy:install` — that
auto-registers the bundle and scaffolds `config/packages/tenancy.yaml` in a single step, plus
three subcommands: `tenancy:init` for regenerating the config file standalone, `tenancy:migrate`
for running Doctrine Migrations across all tenants, and `tenancy:run` for executing any Symfony
console command within a specific tenant's context.

## tenancy:install

One-command setup. Auto-registers `TenancyBundle::class` in `config/bundles.php` via AST
detection (`nikic/php-parser`), invokes `tenancy:init` programmatically to scaffold the config
file, and prints next-step guidance. Use this once per project right after
`composer require danplaton4/tenancy-bundle`.

### Usage

```bash
# One-shot setup — registers the bundle + scaffolds config/packages/tenancy.yaml
bin/console tenancy:install

# Preview the mutation without writing anything
bin/console tenancy:install --dry-run

# Overwrite an existing config/packages/tenancy.yaml (forwarded to tenancy:init)
bin/console tenancy:install --force
```

### Flags

| Flag | Effect |
|------|--------|
| `--dry-run` | Prints the proposed `bundles.php` mutation and the YAML scaffold without writing either file. Safe to run on any project to see what would change. |
| `--force` | Forwarded to the delegated `tenancy:init` call so an existing `config/packages/tenancy.yaml` is overwritten. Does NOT force the bundles.php mutation (that step is always idempotent and aborts on non-standard shapes — see below). |

### Behavior on non-standard `bundles.php`

If the command detects that `config/bundles.php` does not match the canonical Symfony skeleton
shape — for example, a DDD project with a custom `registerBundles()` override in `src/Kernel.php`,
or env-conditional loading via a wrapping `if (...)` — it does NOT mutate the file. Instead, it
prints the exact PHP snippet to add manually and exits with code `0`. The command will never
produce an invalid `bundles.php`: writes are atomic, a `.bak` copy is created before any
mutation, and the result is syntax-validated with `php -l` before being committed to disk.

### Idempotency

Re-running `tenancy:install` on an already-installed project exits `0` with an informational
message. The `bundles.php` AST scan detects the existing `TenancyBundle::class => …` entry and
skips the mutation; `tenancy:init` refuses to overwrite an existing
`config/packages/tenancy.yaml` unless `--force` is passed. Safe to run repeatedly in CI or
provisioning scripts.

See also: [Installation](installation.md) for the full v0.3.3 one-command install flow.

---

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

## tenancy:shared:resync

Re-synchronizes `#[Shared]` Doctrine entity records from the landlord database to every
tenant database. Supports `--tenant <slug>` (single tenant; absent means all tenants),
`--dry-run` (classify drift without writing), and `--force` (skip the confirmation prompt
for CI or non-interactive use). There is no `--all` flag — omitting `--tenant` already
targets all tenants.

```bash
# Dry-run: show what would be inserted/updated per tenant
bin/console tenancy:shared:resync --dry-run

# Live resync for a single tenant
bin/console tenancy:shared:resync --tenant=acme

# Live resync for all tenants, no confirmation prompt
bin/console tenancy:shared:resync --force
```

See [shared-entities.md](shared-entities.md) for full options, dry-run output, confirmation
prompt behavior, and continue-on-failure semantics.

---

## See Also

- [Installation](installation.md) — initial setup with tenancy:init
- [Database-per-Tenant](database-per-tenant.md) — connection switching mechanics
- [Testing](testing.md) — running tests with tenant context
- [Architecture: DI Compilation Pipeline](../architecture/di-compilation.md)
