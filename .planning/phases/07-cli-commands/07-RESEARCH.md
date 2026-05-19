# Phase 7: CLI Commands - Research

**Researched:** 2026-04-01
**Domain:** Symfony Console commands, Symfony Process, Doctrine Migrations programmatic execution, multi-tenant CLI lifecycle
**Confidence:** HIGH

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **Tenant discovery**: Add `findAll(): array` to `TenantProviderInterface`; `DoctrineTenantProvider` implements via landlord EM (bypasses/short-TTL cache — operator tool)
- **Error handling**: Continue ALL tenants on failure; per-tenant status line `✓ acme` / `✗ beta (message)`; summary at end; exit code 1 if any failure; no `--stop-on-failure` in v1
- **tenancy:run execution mechanism**: Spawn `bin/console {command} {args} --tenant={slug}` as subprocess via `symfony/process`; ConsoleResolver handles `--tenant` on the child process; forward stdout/stderr; exit with child exit code
- **Driver scope guard**: In `execute()`, if driver is `shared_db`, write to stderr and exit 1; fail loudly
- **doctrine/migrations dependency**: Add to `suggest` block; guard command registration with `class_exists(\Doctrine\Migrations\DependencyFactory::class)` or equivalent; if absent, command is not registered at all
- **Output style**: `tenancy:migrate` — verbose table output, respects `-q`; `tenancy:run` — transparent passthrough

### Claude's Discretion

- Exact output formatting (column widths, color usage)
- Whether `findAll()` returns only active tenants or all tenants (active + inactive)
- How `tenancy:run` finds the `bin/console` binary (PHP_BINARY + script path pattern)
- Whether `tenancy:migrate` supports `--tenant=acme` for single-tenant targeted runs (nice-to-have)

### Deferred Ideas (OUT OF SCOPE)

- `--stop-on-failure` flag for `tenancy:migrate`
- Parallel `tenancy:migrate` via `symfony/process` (ISOL-07)
- `--tenant=acme` single-tenant targeted run for `tenancy:migrate` — defer to v1.1
- `tenancy:restore` command — separate phase
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| CLI-01 | `tenancy:migrate` runs Doctrine migrations for every tenant sequentially, reporting per-tenant success/failure | DependencyFactory + Migrator API documented; class_exists guard pattern established in services.php |
| CLI-02 | `tenancy:run {tenantId} "command:name arg1"` wraps any Symfony console command with full tenant context bootstrapped | symfony/process v7.4 subprocess pattern; ConsoleResolver already handles --tenant on child processes |
</phase_requirements>

---

## Summary

Phase 7 introduces two Symfony console commands that let operators interact with tenants at the CLI level. The implementation draws entirely on existing bundle infrastructure: `TenantProviderInterface` (extended with `findAll()`), `BootstrapperChain`, `TenantContext`, and `ConsoleResolver`. No new abstractions are needed — the commands compose what already exists.

`tenancy:migrate` is the more complex command. It must iterate over all tenants, switch database context for each, run Doctrine migrations against each tenant's database programmatically via `DependencyFactory`, and accumulate a success/failure summary. The `DependencyFactory` must be constructed fresh per tenant using the switched DBAL connection — this is how doctrine/migrations supports multi-tenant scenarios. The command is conditionally registered only when `class_exists(\Doctrine\Migrations\DependencyFactory::class)` returns true, matching the messenger guard pattern already in `services.php`.

`tenancy:run` is simpler by design: it spawns a subprocess via `symfony/process` with `--tenant={slug}` appended to the command. Because `ConsoleResolver` already handles `--tenant` on `ConsoleEvents::COMMAND`, the child process automatically boots full tenant context. This gives complete process isolation with zero additional bootstrapping logic in the command itself.

**Primary recommendation:** Implement `findAll()` on the interface and provider first (Plan 07-01 prerequisite), then build commands using `#[AsCommand]` following established bundle patterns.

---

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| symfony/console | ^7.4 (installed: v7.4.7) | Command, InputInterface, OutputInterface, SymfonyStyle | Already in `require`; all bundle code uses it |
| symfony/process | ^7.4 (available, not installed) | Subprocess spawning for `tenancy:run` | Locked decision; provides `Process::fromShellCommandline` with stdout/stderr streaming |
| doctrine/migrations | ^3.9 (available, not installed) | `DependencyFactory`, `Migrator`, `MigrateCommand` — programmatic migration execution | Locked decision; soft dependency in `suggest` |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| doctrine/doctrine-bundle | ^2.13 (installed dev) | Provides `Registry` service for EM access in `DoctrineTenantProvider::findAll()` | Already in require-dev/suggest |
| symfony/framework-bundle | ^6.4|^7.0 | TestKernel for integration tests | Testing only |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| symfony/process subprocess | In-process `Application::run()` | In-process shares state, risks memory leaks, does not reuse ConsoleResolver context boot |
| `DependencyFactory::fromConnection()` per tenant | `MigrateCommand::run()` with re-wired EM | `fromConnection()` is explicit and avoids Symfony service container coupling |

**Installation (dev only):**
```bash
composer require --dev symfony/process
composer require --dev doctrine/migrations
```

**Suggest block addition to composer.json:**
```json
"symfony/process": "Required for tenancy:run command (^6.4||^7.0)",
"doctrine/migrations": "Required for tenancy:migrate command (^3.9)"
```

---

## Architecture Patterns

### Recommended Project Structure
```
src/
├── Command/
│   ├── TenantMigrateCommand.php     # CLI-01: sequential migrations with summary
│   └── TenantRunCommand.php         # CLI-02: subprocess with --tenant= pass-through
├── Provider/
│   ├── TenantProviderInterface.php  # Add findAll(): array
│   └── DoctrineTenantProvider.php   # Implement findAll() via landlord EM
└── ...

tests/
├── Unit/
│   └── Command/
│       ├── TenantMigrateCommandTest.php
│       └── TenantRunCommandTest.php
└── Integration/
    └── Command/
        ├── TenantMigrateIntegrationTest.php
        └── TenantRunIntegrationTest.php
```

### Pattern 1: `#[AsCommand]` with `final class`
**What:** All Symfony console commands in this bundle are `final class` with `#[AsCommand]` attribute and constructor injection.
**When to use:** Every new command in this bundle.
**Example:**
```php
// Source: Symfony Console docs + established bundle pattern
#[AsCommand(name: 'tenancy:migrate', description: 'Run Doctrine migrations for all tenants')]
final class TenantMigrateCommand extends Command
{
    public function __construct(
        private readonly TenantProviderInterface $tenantProvider,
        private readonly BootstrapperChain $bootstrapperChain,
        private readonly TenantContext $tenantContext,
        private readonly string $driver,
    ) {
        parent::__construct();
    }
}
```

### Pattern 2: `class_exists` guard for optional-dependency commands
**What:** Commands that require optional libraries must NOT be registered if the library is absent. This is done via `class_exists` in `services.php`, matching the Messenger guard pattern.
**When to use:** `TenantMigrateCommand` (requires `doctrine/migrations`).
**Example:**
```php
// Source: services.php — existing Messenger guard pattern
if (class_exists(\Doctrine\Migrations\DependencyFactory::class)) {
    $services->set('tenancy.command.migrate', TenantMigrateCommand::class)
        ->args([...])
        ->tag('console.command');
}
```

### Pattern 3: `DependencyFactory` per-tenant for programmatic migrations
**What:** Build a fresh `DependencyFactory` for each tenant using the switched DBAL connection. Call `getMetadataStorage()->ensureInitialized()` before migrating.
**When to use:** Inside `TenantMigrateCommand::execute()` for each tenant iteration.
**Example:**
```php
// Source: doctrine/migrations programmatic API (verified via GitHub issue #943)
// Boot tenant context first to switch DBAL connection
$this->bootstrapperChain->boot($tenant);

$config = new Configuration();
// Configure migrations namespace/dirs from bundle config or from app config

$dependencyFactory = DependencyFactory::fromConnection(
    new ExistingMigrationsConfiguration($config),
    new ExistingConnection($tenantConnection),
);
$dependencyFactory->getMetadataStorage()->ensureInitialized();

$planCalculator = $dependencyFactory->getMigrationPlanCalculator();
$plan = $planCalculator->getPlanUntilVersion(new Version('latest'));
$migrator = $dependencyFactory->getMigrator();
$migrator->migrate($plan, new MigratorConfiguration());
```

### Pattern 4: Subprocess spawning with `symfony/process`
**What:** Spawn `bin/console` as a subprocess with `--tenant={slug}` appended. Stream stdout/stderr to parent output. Return child exit code.
**When to use:** `TenantRunCommand::execute()`.
**Example:**
```php
// Source: symfony/process docs; PHP_BINARY pattern is community standard
use Symfony\Component\Process\Process;

$consolePath = $_SERVER['SCRIPT_FILENAME'] ?? 'bin/console';
$process = new Process(
    array_merge([PHP_BINARY, $consolePath], $parsedArgs, ['--tenant=' . $tenantSlug])
);
$process->run(function (string $type, string $buffer) use ($output): void {
    $output->write($buffer);
});
return $process->getExitCode() ?? 0;
```

### Pattern 5: `SymfonyStyle` for per-tenant status reporting
**What:** Use `SymfonyStyle` for `tenancy:migrate` output. Write per-tenant status inline; print summary table after all tenants complete.
**When to use:** `TenantMigrateCommand::execute()`.
**Example:**
```php
// Source: symfony/console SymfonyStyle (installed v7.4.7)
$io = new SymfonyStyle($input, $output);
// Per-tenant line:
$io->writeln(sprintf(' <info>✓</info> %s', $tenant->getSlug()));
// or on failure:
$io->writeln(sprintf(' <error>✗</error> %s (%s)', $tenant->getSlug(), $e->getMessage()));
// Summary:
$io->table(['Tenant', 'Status'], $rows);
```

### Pattern 6: `findAll()` on `TenantProviderInterface`
**What:** Add `findAll(): array` to the interface. `DoctrineTenantProvider` implements it via the landlord EM, bypassing cache (or TTL=0) since this is an operator tool, not a hot path.
**When to use:** `TenantMigrateCommand` — iterating over all tenants.
**Example:**
```php
// TenantProviderInterface addition
/** @return TenantInterface[] */
public function findAll(): array;

// DoctrineTenantProvider implementation
public function findAll(): array
{
    /** @var TenantInterface[] $tenants */
    return $this->entityManager
        ->getRepository($this->tenantEntityClass)
        ->findAll();
}
```

### Anti-Patterns to Avoid

- **In-process Application::run() for tenancy:run**: Shares container state and memory; if the inner command modifies global state or leaves Doctrine dirty, the parent process sees it. Always use subprocess.
- **Catch Exception and silently continue in tenancy:migrate**: Must capture the exception message for the failure report. Empty catch swallows information operators need.
- **Re-using the same DependencyFactory instance across tenants**: `DependencyFactory` captures its connection at construction time. Always construct a fresh one per tenant after switching the DBAL connection.
- **Registering TenantMigrateCommand without class_exists guard**: If `doctrine/migrations` is not installed, the container will fail to compile because the command class doesn't exist. The guard prevents this at container build time.
- **Using `$appDefinition->hasOption('tenant')` in TenantMigrateCommand**: That's `ConsoleResolver`'s responsibility for the child process. `TenantMigrateCommand` manages tenants itself — it does not use `--tenant` on itself.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Subprocess execution | Custom exec/shell_exec wrapper | `symfony/process` Process class | Handles TTY, env inheritance, streaming output, exit codes, timeout, cross-platform |
| Migration execution | Custom SQL runner | `doctrine/migrations` DependencyFactory + Migrator | Handles version tracking, metadata storage, transaction wrapping, dry run |
| Tenant iteration | Direct EM query in command | `TenantProviderInterface::findAll()` | Keeps command decoupled from Doctrine; provider owns the query |
| Output formatting | printf-based status lines | `SymfonyStyle` | Consistent with Symfony ecosystem; handles `-q` silencing for free |

**Key insight:** The subprocess approach for `tenancy:run` means zero migration logic in the command itself — `ConsoleResolver` does all the context boot work in the child process. The command is a thin launcher.

---

## Common Pitfalls

### Pitfall 1: MetadataStorage not initialized
**What goes wrong:** `DependencyFactory::getMigrator()->migrate()` throws `MetadataStorageError: The metadata storage is not initialized` on first run against a fresh tenant database.
**Why it happens:** The `doctrine_migration_versions` table doesn't exist yet on a new tenant DB.
**How to avoid:** Always call `$dependencyFactory->getMetadataStorage()->ensureInitialized()` before `getMigrator()`.
**Warning signs:** Exception on first migration run for a new tenant; subsequent runs succeed.

### Pitfall 2: symfony/process not in require (only suggest)
**What goes wrong:** `TenantRunCommand` is registered but `symfony/process` is absent — class not found at runtime.
**Why it happens:** Unlike messenger guard which uses `interface_exists`, there is no equivalent for symfony/process since Symfony owns the framework — it will almost always be present in real apps. But the bundle is standalone.
**How to avoid:** Add `symfony/process` to `require` (not just suggest) since `tenancy:run` can't function without it, OR add a `class_exists(Process::class)` guard. Given Process is a Symfony component and the bundle already hard-requires multiple Symfony components, adding it to `require` is correct.
**Warning signs:** Fatal error when running `tenancy:run` in an app that doesn't have symfony/process installed.

### Pitfall 3: `--tenant` option collision in TenantRunCommand
**What goes wrong:** `TenantRunCommand` might accidentally try to parse `--tenant` from the inner command string, or the ConsoleResolver adds `--tenant` to the parent process's definition and collides.
**Why it happens:** ConsoleResolver adds `--tenant` to the Application definition on `ConsoleCommandEvent`. `tenancy:run` itself fires this event too.
**How to avoid:** `tenancy:run` takes `tenantSlug` as a positional argument (not `--tenant`). The `--tenant=` flag is appended to the subprocess args, not used by the parent command.
**Warning signs:** `InvalidArgumentException: The "--tenant" option does not exist` in the inner command; or double-resolution.

### Pitfall 4: DependencyFactory migrations config path
**What goes wrong:** `DependencyFactory` can't find any migration files because the `Configuration` object has no paths set.
**Why it happens:** The `Configuration` needs `addMigrationsDirectory()` or `addMigrationsNamespace()`. In a bundle context, this comes from the app's `doctrine_migrations` config (migrations_paths).
**How to avoid:** Inject the migrations configuration from DoctrineMigrationsBundle if installed, or accept a `migrationsDirectory` constructor arg. Alternatively, read from `doctrine_migrations.migrations_paths` container parameter.
**Warning signs:** `No migrations found` even when migration files exist.

### Pitfall 5: `findAll()` returns inactive tenants
**What goes wrong:** `tenancy:migrate` runs migrations on inactive/suspended tenants, touching databases that may be archived.
**Why it happens:** `findAll()` returns all rows — implementation decision is left to Claude's Discretion per CONTEXT.md.
**How to avoid:** Document clearly whether `findAll()` returns active-only or all tenants. Default recommendation: return ALL tenants (active + inactive) and let the operator filter. This is an operator tool — operators need visibility on all tenants.
**Warning signs:** Silent skip of inactive tenants when operator expects them to be migrated.

### Pitfall 6: `bin/console` path discovery in `tenancy:run`
**What goes wrong:** `PHP_BINARY . ' bin/console'` doesn't work when the script is called from a different working directory.
**Why it happens:** `bin/console` is relative; CWD may differ.
**How to avoid:** Use `$_SERVER['SCRIPT_FILENAME']` to get the absolute path of the currently running console script, then derive the project root. The pattern `dirname(dirname($_SERVER['SCRIPT_FILENAME'])) . '/bin/console'` is fragile — prefer injecting the kernel's project dir via `%kernel.project_dir%` as a constructor arg.
**Warning signs:** `bin/console: No such file or directory` when running from different CWDs.

---

## Code Examples

Verified patterns from source code and official documentation:

### Command registration with class_exists guard
```php
// Source: services.php — existing Messenger guard pattern (verified in codebase)
if (class_exists(\Doctrine\Migrations\DependencyFactory::class)) {
    $services->set('tenancy.command.migrate', TenantMigrateCommand::class)
        ->args([
            service('tenancy.provider'),
            service('tenancy.bootstrapper_chain'),
            service('tenancy.context'),
            param('tenancy.driver'),
            // migrations config will be injected or read from DoctrineMigrationsBundle
        ])
        ->tag('console.command');
}

$services->set('tenancy.command.run', TenantRunCommand::class)
    ->args([
        service('tenancy.provider'),
        param('kernel.project_dir'),
    ])
    ->tag('console.command');
```

### TenantProviderInterface::findAll() signature
```php
// Source: src/Provider/TenantProviderInterface.php (verified — currently has only findBySlug)
interface TenantProviderInterface
{
    public function findBySlug(string $slug): TenantInterface;

    /** @return TenantInterface[] */
    public function findAll(): array;
}
```

### tenancy:migrate execute() skeleton
```php
// Source: doctrine/migrations programmatic API (GitHub issue #943 + DependencyFactory docs)
protected function execute(InputInterface $input, OutputInterface $output): int
{
    $io = new SymfonyStyle($input, $output);

    if ($this->driver === 'shared_db') {
        $output->getErrorOutput()->writeln(
            'tenancy:migrate is only available with the database_per_tenant driver.'
        );
        return Command::FAILURE;
    }

    $tenants = $this->tenantProvider->findAll();
    $failures = [];

    foreach ($tenants as $tenant) {
        try {
            $this->runMigrationsForTenant($tenant, $io);
            $io->writeln(sprintf(' <info>✓</info> %s', $tenant->getSlug()));
        } catch (\Throwable $e) {
            $failures[] = ['slug' => $tenant->getSlug(), 'error' => $e->getMessage()];
            $io->writeln(sprintf(' <error>✗</error> %s (%s)', $tenant->getSlug(), $e->getMessage()));
        } finally {
            $this->tenantContext->clear();
            $this->bootstrapperChain->clear();
        }
    }

    // Summary table
    $io->newLine();
    $io->writeln(sprintf(
        'Completed: <info>%d succeeded</info>, <error>%d failed</error>',
        count($tenants) - count($failures),
        count($failures)
    ));

    return $failures === [] ? Command::SUCCESS : Command::FAILURE;
}
```

### tenancy:run execute() skeleton
```php
// Source: symfony/process Process class API (v7.4, available in ecosystem)
use Symfony\Component\Process\Process;

protected function execute(InputInterface $input, OutputInterface $output): int
{
    $tenantSlug = $input->getArgument('tenant');
    $commandString = $input->getArgument('command_string');

    // Validate tenant exists (let TenantNotFoundException bubble as non-zero exit)
    $this->tenantProvider->findBySlug($tenantSlug);

    $consolePath = $this->projectDir . '/bin/console';
    $args = array_filter(explode(' ', $commandString)); // Basic split; shell quoting is handled by Process

    $process = new Process(
        array_merge([PHP_BINARY, $consolePath], $args, ['--tenant=' . $tenantSlug])
    );
    $process->run(function (string $type, string $buffer) use ($output): void {
        $output->write($buffer);
    });

    return $process->getExitCode() ?? 0;
}
```

### Doctrine Migrations DependencyFactory setup per tenant
```php
// Source: doctrine/migrations docs + GitHub issue #943 (verified pattern)
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;

private function runMigrationsForTenant(TenantInterface $tenant, SymfonyStyle $io): void
{
    // Boot tenant (switches DBAL connection)
    $this->tenantContext->setTenant($tenant);
    $this->bootstrapperChain->boot($tenant);

    // Build DependencyFactory from the now-switched tenant connection
    $dependencyFactory = DependencyFactory::fromConnection(
        new ExistingConfiguration($this->migrationsConfig),
        new ExistingConnection($this->tenantConnection),
    );
    $dependencyFactory->getMetadataStorage()->ensureInitialized();

    $planCalculator = $dependencyFactory->getMigrationPlanCalculator();
    $plan = $planCalculator->getPlanUntilVersion(
        $dependencyFactory->getVersionAliasResolver()->resolveVersionAlias('latest')
    );
    $migrator = $dependencyFactory->getMigrator();
    $migrator->migrate($plan, new MigratorConfiguration());
}
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `exec('php bin/console ...')` | `symfony/process` Process class | Symfony 2+ | Streaming I/O, exit code, cross-platform |
| Doctrine Migrations 2.x `AbstractMigration` | Doctrine Migrations 3.x `DependencyFactory` | Migrations 3.0 (2020) | Fully injectable; no global state |
| `Command::run()` in-process | Subprocess per tenant | N/A — design choice | Full isolation, no state leakage |

**Deprecated/outdated:**
- `doctrine/migrations` 2.x `MigrationConfiguration` class: replaced by `Configuration` + `DependencyFactory` in 3.x.
- `DependencyFactory::fromEntityManager()` for tenant migrations: use `fromConnection()` instead — gives direct DBAL access without ORM identity map interference.

---

## Open Questions

1. **Migrations configuration source for `TenantMigrateCommand`**
   - What we know: `DependencyFactory` needs a `Configuration` with `migrations_paths`. In apps using `DoctrineMigrationsBundle`, this is available as a container service (`Doctrine\Migrations\Configuration\Configuration`).
   - What's unclear: Should the bundle inject the `Configuration` from DoctrineMigrationsBundle (adding another optional dependency), or accept a `migrationsDirectory` string parameter in bundle config?
   - Recommendation: Accept a `tenancy.migrations_path` bundle config parameter (Claude's Discretion), or inject `Doctrine\Migrations\Configuration\Configuration` if it exists. Plan 07-01 should decide and document.

2. **`findAll()` active-only vs all tenants**
   - What we know: CONTEXT.md marks this as Claude's Discretion.
   - What's unclear: Whether inactive tenants need schema migration (to keep DB schema current even if tenant is suspended).
   - Recommendation: Return ALL tenants from `findAll()`. Operators managing migrations want a complete picture. An inactive tenant's DB still needs schema parity for reactivation.

3. **Command string parsing for `tenancy:run`**
   - What we know: The argument form is `tenancy:run {tenantSlug} "command:name arg1 arg2"`.
   - What's unclear: A quoted string `"command:name arg1 arg2"` passed as a single argument needs `str_getcsv` or `Process::fromShellCommandline` — the basic `explode(' ')` approach breaks on quoted args with spaces.
   - Recommendation: Use `Process::fromShellCommandline(PHP_BINARY . ' ' . $consolePath . ' ' . $commandString . ' --tenant=' . $tenantSlug)` which handles shell quoting correctly.

---

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5 (phpunit.xml.dist configured) |
| Config file | `phpunit.xml.dist` in project root |
| Quick run command | `php vendor/bin/phpunit --testsuite=unit` |
| Full suite command | `php vendor/bin/phpunit` |

### Phase Requirements — Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| CLI-01 | `tenancy:migrate` iterates all tenants sequentially | Unit | `php vendor/bin/phpunit tests/Unit/Command/TenantMigrateCommandTest.php -x` | ❌ Wave 0 |
| CLI-01 | Continues on tenant failure, records error | Unit | `php vendor/bin/phpunit tests/Unit/Command/TenantMigrateCommandTest.php -x` | ❌ Wave 0 |
| CLI-01 | Exit code 1 when any tenant fails | Unit | `php vendor/bin/phpunit tests/Unit/Command/TenantMigrateCommandTest.php -x` | ❌ Wave 0 |
| CLI-01 | Fails loudly if driver is shared_db | Unit | `php vendor/bin/phpunit tests/Unit/Command/TenantMigrateCommandTest.php -x` | ❌ Wave 0 |
| CLI-01 | TenantMigrateCommand not registered when doctrine/migrations absent | Integration | `php vendor/bin/phpunit tests/Integration/Command/TenantMigrateIntegrationTest.php -x` | ❌ Wave 0 |
| CLI-02 | `tenancy:run` spawns subprocess with --tenant= arg | Unit (Process mock) | `php vendor/bin/phpunit tests/Unit/Command/TenantRunCommandTest.php -x` | ❌ Wave 0 |
| CLI-02 | Exit code propagated from child process | Unit | `php vendor/bin/phpunit tests/Unit/Command/TenantRunCommandTest.php -x` | ❌ Wave 0 |
| CLI-02 | TenantNotFoundException propagates on invalid tenant | Unit | `php vendor/bin/phpunit tests/Unit/Command/TenantRunCommandTest.php -x` | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `php vendor/bin/phpunit --testsuite=unit`
- **Per wave merge:** `php vendor/bin/phpunit`
- **Phase gate:** Full suite green before `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Unit/Command/TenantMigrateCommandTest.php` — covers CLI-01 unit behaviors
- [ ] `tests/Unit/Command/TenantRunCommandTest.php` — covers CLI-02 unit behaviors
- [ ] `tests/Integration/Command/TenantMigrateIntegrationTest.php` — covers class_exists guard + DI registration
- [ ] `tests/Integration/Command/` directory (does not exist)
- [ ] `tests/Unit/Command/` directory (does not exist)

---

## Sources

### Primary (HIGH confidence)
- Source code: `src/Provider/TenantProviderInterface.php` — verified interface has only `findBySlug`; `findAll()` is new
- Source code: `src/Provider/DoctrineTenantProvider.php` — verified constructor pattern and cache approach
- Source code: `src/Resolver/ConsoleResolver.php` — verified `--tenant` handling on `ConsoleCommandEvent`
- Source code: `config/services.php` — verified `interface_exists` guard pattern (Messenger block, line 92)
- Source code: `src/TenancyBundle.php` — verified `class_exists` guard in `build()` (line 120)
- Source code: `composer.json` — verified suggest block format; symfony/process and doctrine/migrations not yet present
- Source code: `phpunit.xml.dist` — verified PHPUnit 11, test suite structure (unit + integration)
- Installed package: `symfony/console` v7.4.7 — verified `SymfonyStyle`, `#[AsCommand]` attribute available

### Secondary (MEDIUM confidence)
- `composer show --all symfony/process` — confirms v7.4.8 is the latest stable for Symfony 7.4; not yet installed
- `composer show --all doctrine/migrations` — confirms v3.9.6 is current stable; v4.0 in dev; not installed
- GitHub issue #943 doctrine/migrations — confirmed `DependencyFactory::getMigrationPlanCalculator()`, `getMigrator()`, `getMetadataStorage()->ensureInitialized()` programmatic API
- Verified via docs: `DependencyFactory::fromConnection(ExistingConfiguration, ExistingConnection)` pattern

### Tertiary (LOW confidence)
- Process command-string parsing with `Process::fromShellCommandline` — standard community pattern, not verified against installed source (symfony/process not installed)

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — versions verified via `composer show`, existing code confirmed
- Architecture patterns: HIGH — all patterns are direct extensions of verified existing code
- Pitfalls: HIGH (most) / MEDIUM (migrations config path — depends on app setup)

**Research date:** 2026-04-01
**Valid until:** 2026-05-01 (stable libraries; doctrine/migrations 3.x has been stable since 2020)
