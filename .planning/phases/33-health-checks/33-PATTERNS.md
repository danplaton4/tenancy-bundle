# Phase 33: Health Checks - Pattern Map

**Mapped:** 2026-07-02
**Files analyzed:** 18 (11 new, 7 modified)
**Analogs found:** 15 / 18 (3 greenfield with no codebase analog)

---

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `src/Health/HealthCheckBootstrapperInterface.php` | interface | request-response | `src/Bootstrapper/TenantBootstrapperInterface.php` | exact |
| `src/Health/BootstrapperHealthResult.php` | value-object | transform | `src/Command/Install/DetectionResult.php` | role-match |
| `src/Health/HealthStatus.php` (enum) | value-object | transform | `src/Command/Install/InstallStatus.php` | exact |
| `src/Health/TenantHealthReport.php` | value-object | transform | `src/Command/Install/DetectionResult.php` | role-match |
| `src/Health/TenantHealthChecker.php` | service | request-response | `src/Context/TenantContext.php` + `src/Bootstrapper/BootstrapperChain.php` | role-match (composite) |
| `src/Health/HealthResponseSanitizer.php` | utility | transform | `src/Mailer/DsnSanitizer.php` | exact |
| `src/Controller/TenantHealthController.php` | controller | request-response | NONE — bundle's first controller | greenfield |
| `config/routes/health.php` | config/route | request-response | NONE — bundle's first route files | greenfield |
| `config/routes/health_fleet.php` | config/route | request-response | NONE — bundle's first route files | greenfield |
| `src/Command/TenantHealthCommand.php` | command | streaming | `src/Command/TenantMigrateCommand.php` | exact |
| `src/DependencyInjection/Compiler/HealthCheckIntegrationPass.php` | compiler-pass | event-driven | `src/DependencyInjection/Compiler/FilesystemContractPass.php` | exact |
| `src/Bootstrapper/DatabaseSwitchBootstrapper.php` (modified) | bootstrapper | CRUD | self | self-analog |
| `src/Driver/SharedDriver.php` (modified) | bootstrapper | CRUD | self | self-analog |
| `src/Bootstrapper/BootstrapperChain.php` (modified) | service | event-driven | self | self-analog |
| `src/TenancyBundle.php` (modified — config + build) | bundle | config | self | self-analog |
| `config/services.php` (modified — wiring) | config | DI | self | self-analog |

---

## Pattern Assignments

### `src/Health/HealthCheckBootstrapperInterface.php` (interface, request-response)

**Analog:** `src/Bootstrapper/TenantBootstrapperInterface.php` (lines 1-14)

This is a **sibling** interface — sits in the same namespace vicinity, mirrors the file structure exactly. No existing bootstrapper is forced to implement it.

**Imports + declaration pattern** (`src/Bootstrapper/TenantBootstrapperInterface.php` lines 1-14):
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Bootstrapper;

use Tenancy\Bundle\TenantInterface;

interface TenantBootstrapperInterface
{
    public function boot(TenantInterface $tenant): void;

    public function clear(): void;
}
```

**New interface shape** — mirrors this exactly but namespace is `Tenancy\Bundle\Health` (or `Tenancy\Bundle\Bootstrapper` as sibling; planning call) and method is `check()` returning `BootstrapperHealthResult`:
```php
interface HealthCheckBootstrapperInterface
{
    public function check(TenantInterface $tenant): BootstrapperHealthResult;
}
```

**Key constraint:** This interface must NOT extend `TenantBootstrapperInterface` — it is a sibling, not a subtype. No existing bootstrapper is forced to implement it (zero BC break).

---

### `src/Health/HealthStatus.php` (enum, transform)

**Analog:** `src/Command/Install/InstallStatus.php` (lines 1-28)

The bundle already has a backed string enum pattern. Use it exactly.

**Enum pattern** (`src/Command/Install/InstallStatus.php` lines 1-28):
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Command\Install;

enum InstallStatus: string
{
    case WROTE = 'wrote';
    case ALREADY_REGISTERED = 'already_registered';
    // ...
}
```

**New enum shape:**
```php
namespace Tenancy\Bundle\Health;

enum HealthStatus: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';
}
```

Backed by string so it serializes directly to the IETF `application/health+json` `status` field values. PHP 8.2+ project — enum is the idiomatic type-safe choice (PHPStan L9 exhaustiveness checking).

---

### `src/Health/BootstrapperHealthResult.php` (value-object, transform)

**Analog:** `src/Command/Install/DetectionResult.php` (lines 1-48)

The `final readonly class` with named public constructor pattern is established.

**VO pattern** (`src/Command/Install/DetectionResult.php` lines 1-48):
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Command\Install;

final readonly class DetectionResult
{
    public function __construct(
        public string $status,
        public array $registeredFqcns,
        public ?int $endPos,
        public ?string $reason,
    ) {
    }

    public static function standard(array $registeredFqcns, int $endPos): self
    {
        return new self('standard', $registeredFqcns, $endPos, null);
    }

    public static function nonStandard(string $reason): self
    {
        return new self('non_standard', [], null, $reason);
    }

    public static function missing(): self
    {
        return new self('missing', [], null, 'config/bundles.php does not exist');
    }
}
```

**New VO shape:** Use `final readonly class`, `HealthStatus` enum for `$status`, named static constructors `pass()`, `fail()`, `fromException()`. Example:
```php
namespace Tenancy\Bundle\Health;

final readonly class BootstrapperHealthResult
{
    public function __construct(
        public string $componentClass,
        public HealthStatus $status,
        public ?string $output = null,
        public ?\Throwable $exception = null,
    ) {
    }

    public static function pass(string $componentClass): self
    {
        return new self($componentClass, HealthStatus::Pass);
    }

    public static function fail(string $componentClass, string $output, ?\Throwable $e = null): self
    {
        return new self($componentClass, HealthStatus::Fail, $output, $e);
    }

    public static function fromException(string $componentClass, \Throwable $e): self
    {
        return new self($componentClass, HealthStatus::Fail, $e->getMessage(), $e);
    }
}
```

---

### `src/Health/TenantHealthReport.php` (value-object, transform)

**Analog:** `src/Command/Install/DetectionResult.php` (same `final readonly class` pattern)

Aggregate VO holding `$slug`, `HealthStatus` (worst-of), and `BootstrapperHealthResult[]`. Named static constructors `fromResults()` and `fromException()`.

**Pattern:** Same `final readonly class` as `DetectionResult`. The aggregate status is derived via worst-of: any `Fail` → `Fail`; else any `Warn` → `Warn`; else `Pass`. This derives from D-05 and must be implemented as a private static helper, not left to the controller.

---

### `src/Health/TenantHealthChecker.php` (service, request-response)

**Analogs:**
1. `src/Context/TenantContext.php` — public `setTenant()` / `clear()` / `hasTenant()` API (lines 1-32)
2. `src/Bootstrapper/BootstrapperChain.php` — foreach-over-bootstrappers loop + try/catch pattern (lines 1-43)

**TenantContext API consumed** (`src/Context/TenantContext.php` lines 11-31):
```php
private ?TenantInterface $currentTenant = null;

public function setTenant(TenantInterface $tenant): void
{
    $this->currentTenant = $tenant;
}

public function hasTenant(): bool
{
    return null !== $this->currentTenant;
}

public function clear(): void
{
    $this->currentTenant = null;  // unconditional — the finally invariant relies on this
}
```

**BootstrapperChain loop pattern** (`src/Bootstrapper/BootstrapperChain.php` lines 25-43):
```php
public function boot(TenantInterface $tenant): void
{
    $fqcns = [];

    foreach ($this->bootstrappers as $bootstrapper) {
        $bootstrapper->boot($tenant);
        $fqcns[] = $bootstrapper::class;
    }

    $this->eventDispatcher->dispatch(new TenantBootstrapped($tenant, $fqcns));
}

public function clear(): void
{
    foreach (array_reverse($this->bootstrappers) as $bootstrapper) {
        $bootstrapper->clear();
    }
}
```

**Core set→probe→clear-in-finally invariant** (HEALTH-03, non-negotiable):
```php
public function checkOne(TenantInterface $tenant): TenantHealthReport
{
    $this->tenantContext->setTenant($tenant);
    try {
        $results = $this->bootstrapperChain->healthCheck($tenant);
        return TenantHealthReport::fromResults($tenant->getSlug(), $results);
    } catch (\Throwable $e) {
        return TenantHealthReport::fromException($tenant->getSlug(), $e);
    } finally {
        $this->tenantContext->clear();  // ALWAYS runs — even on exception
    }
}
```

**After `checkOne()` returns:** `$this->tenantContext->hasTenant() === false` — guaranteed because `TenantContext::clear()` unconditionally sets `$currentTenant = null` (verified at line 30).

**Constructor pattern** — follows services.php service wiring style (no `#[Autowire]`, no `#[Inject]`; wired explicitly in `config/services.php`):
```php
public function __construct(
    private readonly TenantContext $tenantContext,
    private readonly BootstrapperChain $bootstrapperChain,
) {
}
```

---

### `src/Health/HealthResponseSanitizer.php` (utility, transform)

**Analog:** `src/Mailer/DsnSanitizer.php` (lines 1-39) — **reuse, do not reinvent**

**DsnSanitizer constants to import/reference** (`src/Mailer/DsnSanitizer.php` lines 28-29):
```php
public const REDACTION_REGEX = '/(:\/\/[^:\/@]+:)[^@\/]+(@)/';
public const REPLACEMENT = '$1***$2';

public static function redact(?string $dsn): ?string
{
    if (null === $dsn || '' === $dsn) {
        return $dsn;
    }
    return preg_replace(self::REDACTION_REGEX, self::REPLACEMENT, $dsn) ?? $dsn;
}
```

**HealthResponseSanitizer** delegates to `DsnSanitizer::REDACTION_REGEX` — same constant, no copy:
```php
final class HealthResponseSanitizer
{
    public function sanitize(string $message): string
    {
        return preg_replace(
            DsnSanitizer::REDACTION_REGEX,
            DsnSanitizer::REPLACEMENT,
            $message,
        ) ?? $message;
    }

    /** @param array<string, mixed> $data */
    public function sanitizeArray(array $data): array
    {
        array_walk_recursive($data, function (mixed &$value): void {
            if (is_string($value)) {
                $value = $this->sanitize($value);
            }
        });
        return $data;
    }
}
```

The regex `'/(:\/\/[^:\/@]+:)[^@\/]+(@)/'` covers MySQL, PostgreSQL, Redis, SMTP, and any `scheme://user:password@host` DSN — confirmed general by WR-07 tightening rationale in the `DsnSanitizer` docblock.

**DSN leak precedent** in `src/Profiler/TenantDataCollector.php` (line 11): `use Tenancy\Bundle\Mailer\DsnSanitizer;` — same import path used for health sanitizer.

---

### `src/Controller/TenantHealthController.php` (controller, request-response)

**Analog:** NONE — this is the **bundle's first controller**. Confirmed: no `src/Controller/` directory exists through v0.4.1.

**Greenfield — use Symfony AbstractController or plain controller conventions:**
- `use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;` (if twig/framework-bundle available)
- Or: plain class returning `JsonResponse` directly (preferred for bundle controllers — no Twig dependency)
- Content-Type header: `application/health+json` on ALL health responses (IETF requirement)
- HTTP status mapping: `pass`/`warn` → 200; `fail` → 503; unknown slug → 404
- Liveness action body is literally: `return new JsonResponse(['status' => 'pass'], 200, ['Content-Type' => 'application/health+json']);`

**Dependency wiring pattern** — follows `config/services.php` explicit-args style (no autoconfigure for controllers in bundles):
```php
$services->set('tenancy.health.controller', TenantHealthController::class)
    ->args([
        service('tenancy.health.checker'),
        service('tenancy.provider')->nullOnInvalid(),
        service('tenancy.health.sanitizer'),
        param('tenancy.health.fleet_default_limit'),
        param('tenancy.health.fleet_max_limit'),
    ])
    ->public();  // required — controllers must be public services
```

**Anti-patterns to avoid (verified from RESEARCH.md):**
- No DB call in `live()` action (D-07, HEALTH-01)
- No `TenantContext::setTenant()` directly in controller — delegate entirely to `TenantHealthChecker`
- No iteration of all tenants in `live()` (Pitfall 5)

---

### `config/routes/health.php` + `config/routes/health_fleet.php` (config/route, request-response)

**Analog:** NONE — bundle's first route files. No existing `config/routes/` directory through v0.4.1.

**Greenfield — use Symfony PHP-DSL `RoutingConfigurator`:**
```php
// config/routes/health.php — live + ready routes (imported by consuming app)
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Tenancy\Bundle\Controller\TenantHealthController;

return function (RoutingConfigurator $routes): void {
    $routes->add('tenancy_health_live', '/live')
        ->controller([TenantHealthController::class, 'live'])
        ->methods(['GET']);

    $routes->add('tenancy_health_ready', '/ready/{slug}')
        ->controller([TenantHealthController::class, 'ready'])
        ->methods(['GET'])
        ->requirements(['slug' => '[a-z0-9\-]+']);
};
```

```php
// config/routes/health_fleet.php — fleet route (separate import per D-02)
return function (RoutingConfigurator $routes): void {
    $routes->add('tenancy_health_fleet', '/')
        ->controller([TenantHealthController::class, 'fleet'])
        ->methods(['GET']);
};
```

**Assumption A1:** PHP-DSL `RoutingConfigurator` is standard Symfony 7.4+/8.x bundle route file format. Not previously verified in this codebase (no existing route files), but it is the Symfony-recommended format for importable bundle routes (see RESEARCH.md §Assumption A1). Planner should verify FQCN `Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator` exists.

---

### `src/Command/TenantHealthCommand.php` (command, streaming)

**Analog:** `src/Command/TenantMigrateCommand.php` (lines 1-311) — **closest match, copy structure directly**

**Imports pattern** (`src/Command/TenantMigrateCommand.php` lines 1-25):
```php
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Provider\TenantProviderInterface;
```

**`#[AsCommand]` + constructor pattern** (lines 26-38):
```php
#[AsCommand(name: 'tenancy:migrate', description: 'Run Doctrine migrations for all tenants')]
final class TenantMigrateCommand extends Command
{
    public function __construct(
        private readonly TenantProviderInterface $tenantProvider,
        private readonly BootstrapperChain $bootstrapperChain,
        private readonly TenantContext $tenantContext,
        // ...
    ) {
        parent::__construct();
    }
```

**`--tenant` + `--all` option pattern** (lines 43-75):
```php
$this->addOption('tenant', null, InputOption::VALUE_OPTIONAL, 'Run ... for a single tenant only');
// --all: health command adds this as VALUE_NONE per D-09 (migrate uses absence of --tenant)
$this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: txt (default) or json', 'txt');
```

**`--format` validation pattern** (`src/Command/TenantMaintenanceStatusCommand.php` lines 63-67):
```php
$formatRaw = $input->getOption('format');
$format = \is_string($formatRaw) ? $formatRaw : 'txt';

if (!\in_array($format, ['txt', 'json'], true)) {
    $io->error(sprintf('Unknown format "%s". Use "txt" or "json".', $format));
    return Command::FAILURE;
}
```

**Per-tenant streaming + exit aggregation pattern** (`src/Command/TenantMigrateCommand.php` lines 240-265):
```php
$failures = [];

foreach ($tenants as $tenant) {
    try {
        $this->runMigrationsForTenant($tenant, ...);
        $io->writeln(sprintf(' <info>✓</info> %s', $tenant->getSlug()));
    } catch (\Throwable $e) {
        $failures[] = $tenant->getSlug();
        $io->writeln(sprintf(' <error>✗</error> %s (%s)', $tenant->getSlug(), $e->getMessage()));
    } finally {
        $this->tenantContext->clear();
        $this->bootstrapperChain->clear();
    }
}
// ... summary + return FAILURE if $failures !== []
```

**JSON aggregate output pattern** (`src/Command/TenantMigrateCommand.php` lines 172-204):
```php
$json = json_encode(
    $aggregate,
    \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR,
);
$output->writeln($json);
```

**Key difference from migrate:** Health command uses `TenantHealthChecker::checkOne()` instead of `runMigrationsForTenant()`. The checker handles its own `setTenant()`/`clear()` in `finally` — the command's loop must NOT also call `$this->tenantContext->clear()` (that's the checker's job). The command's `finally` block is empty or absent.

**`console.command` tag** — registered in `config/services.php` with `.tag('console.command')`, same as `tenancy.command.migrate` (line 346).

---

### `src/DependencyInjection/Compiler/HealthCheckIntegrationPass.php` (compiler-pass, event-driven)

**Analog:** `src/DependencyInjection/Compiler/FilesystemContractPass.php` (lines 1-140) — **closest structural match**

Also reference: `src/DependencyInjection/Compiler/MailerTransportContractPass.php` (lines 1-106) for the `interface_exists` early-return guard.

**File structure pattern** (`src/DependencyInjection/Compiler/FilesystemContractPass.php` lines 1-40):
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class FilesystemContractPass implements CompilerPassInterface
{
    private const TAG = 'tenancy.scoped';
    // ...
    public function process(ContainerBuilder $container): void
    {
        // Early-return when feature is absent/disabled
        if (!$container->hasParameter(self::ENABLED_PARAM) || !$container->getParameter(self::ENABLED_PARAM)) {
            return;
        }

        // Guard: optional library not installed
        if (!interface_exists(\League\Flysystem\FilesystemOperator::class)) {
            throw new \LogicException('...');
        }
        // ...
    }
}
```

**`class_exists` early-return guard pattern** (`src/DependencyInjection/Compiler/MailerTransportContractPass.php` lines 39-44):
```php
public function process(ContainerBuilder $container): void
{
    if (!interface_exists(\Symfony\Component\Mailer\MailerInterface::class)) {
        return;
    }
    // ...
}
```

**HealthCheckIntegrationPass shape** — early-return if liip not installed, then register liip checks tagged `liip_monitor.check`:
```php
final class HealthCheckIntegrationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // class_exists guard — liip/monitor-bundle is optional (HEALTH-07)
        if (!class_exists(\LiipMonitorBundle\LiipMonitorBundle::class)) {
            return;
        }
        // Register TenantConnectivityCheck tagged liip_monitor.check
        // ...
    }
}
```

**Assumption A2:** `LiipMonitorBundle\LiipMonitorBundle::class` as the guard FQCN — MEDIUM confidence (RESEARCH.md §Assumption A2). Alternative: `class_exists(\Laminas\Diagnostics\Check\CheckInterface::class)` since laminas-diagnostics is always installed when liip is. Verify actual FQCN in Wave 0.

**Registration in `build()`** — copy the `interface_exists`-guarded pattern from `src/TenancyBundle.php` lines 464-476:
```php
// From TenancyBundle::build() lines 464-476:
if (interface_exists(\Symfony\Component\Mailer\MailerInterface::class)) {
    $container->addCompilerPass(new MailerTransportContractPass());
}
if (interface_exists(\League\Flysystem\FilesystemOperator::class)) {
    $container->addCompilerPass(new FilesystemContractPass());
}
```

New entry follows this exact pattern — add AFTER existing passes:
```php
// Always register HealthCheckIntegrationPass — it self-guards via class_exists internally
$container->addCompilerPass(new HealthCheckIntegrationPass());
```

---

### `src/Bootstrapper/DatabaseSwitchBootstrapper.php` — add `check()` (modification)

**Self-analog** — `src/Bootstrapper/DatabaseSwitchBootstrapper.php` (lines 1-42, read in full)

**Current structure** (lines 25-42):
```php
final class DatabaseSwitchBootstrapper implements TenantDriverInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function boot(TenantInterface $tenant): void
    {
        $this->connection->close();
    }

    public function clear(): void
    {
        if ($this->connection->isConnected()) {
            $this->connection->close();
        }
    }
}
```

**Modification:** Add `implements HealthCheckBootstrapperInterface` to class declaration and add `check()` method. The `close()` + lazy reconnect via `SELECT 1` is safe because:
- "This class holds no tenant-specific state" (docblock line 18)
- `boot()` already calls `close()` — probe reuses the same lazy-reconnect path
- After `TenantHealthChecker::checkOne()`'s `finally`, `TenantContext::clear()` runs, leaving state clean

**New `check()` method shape:**
```php
public function check(TenantInterface $tenant): BootstrapperHealthResult
{
    try {
        $this->connection->close();
        $this->connection->executeQuery('SELECT 1');
        return BootstrapperHealthResult::pass(static::class);
    } catch (\Throwable $e) {
        return BootstrapperHealthResult::fail(static::class, $e->getMessage(), $e);
    }
}
```

---

### `src/Driver/SharedDriver.php` — add `check()` (modification)

**Self-analog** — `src/Driver/SharedDriver.php` (lines 1-43, read in full)

**Current structure** — `boot()` injects TenantContext into `TenantAwareFilter`; `clear()` is a no-op (filter reads `TenantContext` live at query time).

**Modification:** Add `implements HealthCheckBootstrapperInterface`. The filter is already live because `TenantHealthChecker::setTenant()` runs before `check()`. A `SELECT 1` confirms connectivity + filter activation:

```php
public function check(TenantInterface $tenant): BootstrapperHealthResult
{
    try {
        $this->em->getConnection()->executeQuery('SELECT 1');
        return BootstrapperHealthResult::pass(static::class);
    } catch (\Throwable $e) {
        return BootstrapperHealthResult::fail(static::class, $e->getMessage(), $e);
    }
}
```

**Guard:** `SharedDriver` is already registered only when `driver: shared_db` AND `interface_exists(EntityManagerInterface::class)` (`src/TenancyBundle.php` lines 441-451) — no additional guarding needed on `check()`.

---

### `src/Bootstrapper/BootstrapperChain.php` — add `healthCheck()` (modification)

**Self-analog** — `src/Bootstrapper/BootstrapperChain.php` (lines 1-43, read in full)

**Additive method** — does NOT touch `boot()` or `clear()`. Copies the `foreach ($this->bootstrappers as $bootstrapper)` pattern but filters by interface:

```php
/** @return BootstrapperHealthResult[] */
public function healthCheck(TenantInterface $tenant): array
{
    $results = [];
    foreach ($this->bootstrappers as $bootstrapper) {
        if ($bootstrapper instanceof HealthCheckBootstrapperInterface) {
            try {
                $results[] = $bootstrapper->check($tenant);
            } catch (\Throwable $e) {
                $results[] = BootstrapperHealthResult::fromException(
                    $bootstrapper::class,
                    $e,
                );
            }
        }
    }
    return $results;
}
```

**No event dispatch** — `healthCheck()` is read-only; `TenantResolved`/`TenantBootstrapped` events must NOT fire during probes (Anti-pattern H-A5 from RESEARCH.md).

**New `use` imports needed:** `Tenancy\Bundle\Health\BootstrapperHealthResult` and `Tenancy\Bundle\Health\HealthCheckBootstrapperInterface`.

---

### `src/TenancyBundle.php` — `health` config node + `HealthCheckIntegrationPass` in `build()` (modification)

**Self-analog** — `src/TenancyBundle.php` (lines 1-542, read in full)

**Config node pattern** — copy the `maintenance` array node pattern (lines 136-146) for the new `health` node:
```php
// Existing maintenance node (lines 136-146):
->arrayNode('maintenance')
->addDefaultsIfNotSet()
->children()
->booleanNode('enabled')->defaultFalse()->end()
->scalarNode('template')->defaultNull()->end()
->integerNode('retry_after')->defaultValue(3600)->min(1)->end()
->arrayNode('allow_ips')->scalarPrototype()->end()->defaultValue([])->end()
->arrayNode('allow_routes')->scalarPrototype()->end()->defaultValue([])->end()
->arrayNode('allow_paths')->scalarPrototype()->end()->defaultValue([])->end()
->end()
->end()
```

**New `health` node** — add after `maintenance`, before the closing `->validate()`:
```php
->arrayNode('health')
->addDefaultsIfNotSet()
->children()
    ->integerNode('fleet_default_limit')->defaultValue(50)->min(1)->end()
    ->integerNode('fleet_max_limit')->defaultValue(200)->min(1)->end()
->end()
->end()
```

**No `enabled` flag** — D-01 locks that route-import IS the opt-in. No `tenancy.health.enabled` parameter.

**`loadExtension()` parameter pattern** — copy the `$maintenanceConfig` extraction block (lines 203-231) for health config:
```php
/** @var array<string, mixed> $healthConfig */
$healthConfig = $config['health'] ?? [];
$healthFleetDefaultLimit = is_int($healthConfig['fleet_default_limit'] ?? 50) ? (int) ($healthConfig['fleet_default_limit'] ?? 50) : 50;
$healthFleetMaxLimit = is_int($healthConfig['fleet_max_limit'] ?? 200) ? (int) ($healthConfig['fleet_max_limit'] ?? 200) : 200;

$container->parameters()
    // ...existing params...
    ->set('tenancy.health.fleet_default_limit', $healthFleetDefaultLimit)
    ->set('tenancy.health.fleet_max_limit', $healthFleetMaxLimit);
```

**`build()` addition** — add `HealthCheckIntegrationPass` after existing passes (lines 454-477):
```php
$container->addCompilerPass(new HealthCheckIntegrationPass());
```

---

### `config/services.php` — new health service wiring (modification)

**Self-analog** — `config/services.php` (lines 1-308, read in full)

**Service registration patterns to copy:**

1. **Always-registered service** (copy `tenancy.context` pattern, lines 50-52):
```php
$services->set('tenancy.health.checker', TenantHealthChecker::class)
    ->args([
        service('tenancy.context'),
        service('tenancy.bootstrapper_chain'),
    ]);
$services->alias(TenantHealthChecker::class, 'tenancy.health.checker');
```

2. **Always-registered service** (copy `tenancy.health.sanitizer`):
```php
$services->set('tenancy.health.sanitizer', HealthResponseSanitizer::class);
$services->alias(HealthResponseSanitizer::class, 'tenancy.health.sanitizer');
```

3. **Controller (public — Symfony requires public controllers)**:
```php
$services->set('tenancy.health.controller', TenantHealthController::class)
    ->public()
    ->args([
        service('tenancy.health.checker'),
        service('tenancy.provider')->nullOnInvalid(),
        service('tenancy.health.sanitizer'),
        param('tenancy.health.fleet_default_limit'),
        param('tenancy.health.fleet_max_limit'),
    ]);
```

4. **`console.command` tag pattern** (copy `tenancy.command.run` lines 128-133):
```php
$services->set('tenancy.command.health', TenantHealthCommand::class)
    ->args([
        service('tenancy.provider')->nullOnInvalid(),
        service('tenancy.health.checker'),
        service('tenancy.health.sanitizer'),
    ])
    ->tag('console.command');
```

5. **`nullOnInvalid()` guard pattern** (used throughout for optional services, e.g. line 75):
```php
service('tenancy.provider')->nullOnInvalid()
```

**Optional-dependency posture:** `TenantHealthChecker` and `TenantHealthController` must NOT import `EntityManagerInterface` directly — follow the established `service('tenancy.provider')->nullOnInvalid()` pattern. The no-Doctrine CI lane must stay green.

---

## Shared Patterns

### `declare(strict_types=1)` — every file
**Source:** Every existing file in `src/`
**Apply to:** All new PHP files
```php
<?php

declare(strict_types=1);
```

### Optional-dependency guard (`class_exists` / `interface_exists`)
**Source:** `config/services.php` lines 63, 106, 152, 165, 250, 280
**Apply to:** `HealthCheckIntegrationPass`, liip-dependent services in `config/services.php`
```php
// interface guard (services.php line 63)
if (interface_exists(Doctrine\ORM\EntityManagerInterface::class)) { ... }

// class guard (compiler pass)
if (!class_exists(\LiipMonitorBundle\LiipMonitorBundle::class)) {
    return;
}
```

### `nullOnInvalid()` for optional service dependencies
**Source:** `config/services.php` line 75, 81, 84 etc.
**Apply to:** Any service that takes `TenantProviderInterface` or other optionally-present services
```php
service('tenancy.provider')->nullOnInvalid()
```

### `json_encode` flags — JSON output
**Source:** `src/Command/TenantMigrateCommand.php` line 200-203
**Apply to:** `TenantHealthCommand` JSON output, `TenantHealthController` response building (where not using `JsonResponse`)
```php
json_encode(
    $data,
    \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR,
)
```

### `#[AsCommand]` + `parent::__construct()` — console commands
**Source:** `src/Command/TenantMigrateCommand.php` lines 26-39, `src/Command/TenantMaintenanceStatusCommand.php` lines 29-41
**Apply to:** `TenantHealthCommand`
```php
#[AsCommand(name: 'tenancy:health', description: 'Check health of tenant connections and bootstrappers')]
final class TenantHealthCommand extends Command
{
    public function __construct(/* ... */)
    {
        parent::__construct();
    }
```

### `final` + `readonly` constructor properties — all new classes
**Source:** Every service class in `src/` (e.g., `DatabaseSwitchBootstrapper` line 25, `SharedDriver` line 21)
**Apply to:** All new service and VO classes
```php
final class TenantHealthChecker
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly BootstrapperChain $bootstrapperChain,
    ) {
    }
```

### `try/finally` probe-safety invariant — HEALTH-03 (non-negotiable)
**Source:** CONTEXT.md HEALTH-03 invariant + `TenantContext::clear()` line 30 (unconditional null set)
**Apply to:** `TenantHealthChecker::checkOne()` exclusively — this is the single enforcement point
```php
$this->tenantContext->setTenant($tenant);
try {
    // probe
} catch (\Throwable $e) {
    // return fail report
} finally {
    $this->tenantContext->clear();  // ALWAYS
}
```

---

## No Analog Found (Greenfield)

| File | Role | Data Flow | Reason |
|---|---|---|---|
| `src/Controller/TenantHealthController.php` | controller | request-response | Bundle shipped zero controllers through v0.4.1; no `src/Controller/` directory exists |
| `config/routes/health.php` | config/route | request-response | Bundle shipped zero route files through v0.4.1; no `config/routes/` directory exists |
| `config/routes/health_fleet.php` | config/route | request-response | Same — bundle's first route files; separate from `health.php` per D-02 |

**For the greenfield controller:** Use standard Symfony `JsonResponse` (already in `symfony/http-foundation` which is a bundle requirement). Do NOT use `AbstractController` — bundle controllers avoid Twig/templating dependencies. Do NOT use route attributes on the controller class — routes live in the PHP-DSL route files.

**For the greenfield route files:** PHP-DSL `RoutingConfigurator` is the Symfony-recommended format for importable bundle routes. Verify `Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator` FQCN exists in the Symfony version matrix (7.4/8.x — it does, introduced in 5.1).

---

## Metadata

**Analog search scope:** `src/`, `config/`, `tests/` (read-only)
**Files read for pattern extraction:** 13 source files fully read
**Pattern extraction date:** 2026-07-02
**Greenfield files:** 3 (controller + 2 route files) — planner uses RESEARCH.md Pattern 5/8 for these
**Probe safety:** `TenantContext::clear()` at line 30 confirmed unconditional (`$currentTenant = null`) — the `finally` invariant is sound
