# Phase 32: Maintenance Mode - Pattern Map

**Mapped:** 2026-07-01
**Files analyzed:** 9 new/modified files
**Analogs found:** 9 / 9

---

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `src/EventListener/TenantMaintenanceModeListener.php` | middleware/listener | request-response | `src/EventListener/TenantContextOrchestrator.php` | role-match (same event, different response strategy) |
| `src/Maintenance/TenantMaintenanceConfigTrait.php` | model/trait | CRUD | `src/Filesystem/TenantFilesystemConfigTrait.php` + `src/Mailer/TenantMailerConfigTrait.php` | exact |
| `src/DependencyInjection/Compiler/MaintenanceModeContractPass.php` | compiler-pass | build | `src/DependencyInjection/Compiler/FilesystemContractPass.php` + `MailerTransportContractPass.php` | role-match (different guard logic) |
| `src/Command/TenantMaintenanceEnableCommand.php` | command | CRUD | `src/Command/SharedEntityResyncCommand.php` | role-match (NO boot, landlord-write only) |
| `src/Command/TenantMaintenanceDisableCommand.php` | command | CRUD | `src/Command/SharedEntityResyncCommand.php` | role-match (NO boot, landlord-write only) |
| `src/Command/TenantMaintenanceStatusCommand.php` | command | request-response | `src/Command/TenantMigrateCommand.php` | role-match (`--format=json` table pattern) |
| `src/Event/TenantMaintenanceEnabled.php` | event | event-driven | `src/Event/TenantResolved.php` | exact |
| `src/Event/TenantMaintenanceDisabled.php` | event | event-driven | `src/Event/TenantResolved.php` | exact |
| `src/TenantInterface.php` (modified) | interface | — | `src/TenantInterface.php` (self) | exact (additive) |
| `src/Entity/AbstractTenant.php` (modified) | model | CRUD | `src/Entity/AbstractTenant.php` (self: `$isActive` column) | exact (additive) |
| `src/TenancyBundle.php` (modified) | config/bundle | build | `src/TenancyBundle.php` (self: `filesystem` node) | exact (additive) |
| `config/services.php` (modified) | config | build | `config/services.php` (self: Flysystem block) | exact (additive) |

---

## Pattern Assignments

### `src/EventListener/TenantMaintenanceModeListener.php` (listener, request-response)

**Analog:** `src/EventListener/TenantContextOrchestrator.php`

**Imports pattern** (lines 1–16):
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Twig\Environment;         // injected nullable
use Tenancy\Bundle\Context\TenantContext;
```

**Priority declaration and class header** (lines 18–23 of analog):
```php
// ANALOG src/EventListener/TenantContextOrchestrator.php:18-23
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: TenantContextOrchestrator::PRIORITY)]
final class TenantContextOrchestrator
{
    /** Priority 20: after Router (32), before Security firewall (8). */
    public const PRIORITY = 20;
```
**Phase 32 deviation:** Use priority `16` (not 20). Declare `public const PRIORITY = 16;` on the listener class. The `MaintenanceModeContractPass` inspects the `kernel.event_listener` tag's priority attribute (converted from the `#[AsEventListener]` attribute by autoconfiguration) and throws `\LogicException` if priority >= 20.

**isMainRequest guard** (lines 33–37 of analog):
```php
// ANALOG src/EventListener/TenantContextOrchestrator.php:33-37
public function onKernelRequest(RequestEvent $event): void
{
    if (!$event->isMainRequest()) {
        return;
    }
```

**hasTenant null-branch** (lines 56–60 of analog `onKernelTerminate`; same idiom):
```php
// ANALOG src/EventListener/TenantContextOrchestrator.php:56-58
if (!$this->tenantContext->hasTenant()) {
    return;
}
```
**Phase 32 deviation:** The listener's null-branch is the second check (after `isMainRequest()`), before allow-list and maintenance check. The orchestrator's null-branch short-circuits the whole resolver chain — the maintenance listener's null-branch means "landlord/public route; skip maintenance gate".

**Response strategy — critical difference from orchestrator:**
The orchestrator never sets a response. The maintenance listener must call `$event->setResponse(new Response(..., 503, $headers))`. No exception is thrown. This is intentional (D-03: `Retry-After` + `Cache-Control: no-store` headers are set on the response object directly). The bundle throws `HttpExceptionInterface` elsewhere (e.g. `TenantInactiveException`) — maintenance deliberately does NOT follow that pattern.

**TenantContext API** (lines 10–31 of `src/Context/TenantContext.php`):
```php
// src/Context/TenantContext.php:14-31 — full API used by the listener
public function setTenant(TenantInterface $tenant): void { ... }
public function getTenant(): ?TenantInterface { ... }   // returns null when no tenant
public function hasTenant(): bool { ... }               // null !== $this->currentTenant
public function clear(): void { ... }
```

---

### `src/Maintenance/TenantMaintenanceConfigTrait.php` (model/trait, CRUD)

**Primary analog:** `src/Filesystem/TenantFilesystemConfigTrait.php`

**Docblock pattern** (lines 9–42 of filesystem analog):
```php
// src/Filesystem/TenantFilesystemConfigTrait.php:9-42
/**
 * Default implementation of the (optional) filesystem-config accessor on a tenant entity.
 *
 * Users with a custom Tenant entity can `use TenantFilesystemConfigTrait;` to
 * inherit the nullable JSON column and its getter/setter pair ...
 *
 * The #[ORM\Column] attribute is only honored when doctrine/orm is installed;
 * with Doctrine absent the trait still works as plain PHP property storage.
 *
 * Do NOT combine with {@see \Tenancy\Bundle\Entity\AbstractTenant}, which
 * already inlines this column. Using both in the same entity will cause
 * Doctrine to see a duplicate column mapping and fail.
 */
```
**Phase 32 deviation:** The "Do NOT combine with AbstractTenant" warning is REQUIRED in the docblock (Pitfall 6 from RESEARCH). Adapt the wording: "Do NOT use with `AbstractTenant`, which already inlines `$inMaintenance`. Using both causes a Doctrine duplicate column mapping error."

**Property + getter + setter pattern** (lines 44–67 of filesystem analog):
```php
// src/Filesystem/TenantFilesystemConfigTrait.php:44-67
trait TenantFilesystemConfigTrait
{
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $filesystemConfig = null;

    public function getFilesystemConfig(): ?array
    {
        return $this->filesystemConfig;
    }

    public function setFilesystemConfig(?array $filesystemConfig): static
    {
        $this->filesystemConfig = $filesystemConfig;

        return $this;
    }
}
```
**Phase 32 deviation — critical:** `$inMaintenance` is `bool` with non-null default `false`, NOT nullable. The property declaration is:
```php
#[ORM\Column(type: 'boolean')]
private bool $inMaintenance = false;
```
No `?` prefix. No `null` default. Getter returns `bool` (not `?bool`). Setter takes `bool`. This matches the `$isActive` column in `AbstractTenant` (line 38–39):
```php
// src/Entity/AbstractTenant.php:38-39
#[ORM\Column(type: 'boolean')]
private bool $isActive = true;
```
Setter uses `static` return type, matching both existing traits (mailer line 45, filesystem line 62).

**Secondary analog for getter name:** `isActive()` (not `getActive()`) — follow the `is*` prefix for boolean accessors (line 107 of `AbstractTenant`): `public function isActive(): bool`. The maintenance trait's getter must be named `isInMaintenance(): bool`.

---

### `src/DependencyInjection/Compiler/MaintenanceModeContractPass.php` (compiler-pass, build)

**Analog A:** `src/DependencyInjection/Compiler/FilesystemContractPass.php` (early-return when disabled)

**Early-return when feature disabled** (lines 67–72 of filesystem analog):
```php
// src/DependencyInjection/Compiler/FilesystemContractPass.php:67-72
public function process(ContainerBuilder $container): void
{
    // Early-return when filesystem feature is disabled (the default).
    if (!$container->hasParameter(self::ENABLED_PARAM) || !$container->getParameter(self::ENABLED_PARAM)) {
        return;
    }
```
**Phase 32 deviation:** The maintenance pass's primary concern is a priority invariant, not a feature-disabled gate. The early return guards against the listener service definition being absent (if `tenancy.maintenance.enabled: false`). When the listener IS registered, the pass inspects its `kernel.event_listener` tag.

**Analog B:** `src/DependencyInjection/Compiler/MailerTransportContractPass.php` (`hasParameter` / `getParameter` / `hasDefinition` / `\LogicException` pattern)

**ContainerBuilder API usage** (lines 39–68 of mailer analog):
```php
// src/DependencyInjection/Compiler/MailerTransportContractPass.php:39-68
public function process(ContainerBuilder $container): void
{
    if (!interface_exists(\Symfony\Component\Mailer\MailerInterface::class)) {
        return;
    }

    if (!$container->hasParameter(self::ASYNC_PARAM)) {
        throw new \LogicException(sprintf('tenancy: parameter "%s" must be declared ...', self::ASYNC_PARAM));
    }
    // ...
    if (!$container->hasDefinition(self::X_TRANSPORT_SERVICE)) {
        throw new \LogicException('tenancy: Mailer is routed async ...');
    }
}
```

**Phase 32 MaintenanceModeContractPass logic** (from RESEARCH.md §ContractPass Implementation Pattern):
```php
// Concrete implementation pattern for MaintenanceModeContractPass::process():
public function process(ContainerBuilder $container): void
{
    // Early-return when maintenance feature is disabled (mirrors FilesystemContractPass:70)
    if (!$container->hasParameter('tenancy.maintenance.enabled')
        || !$container->getParameter('tenancy.maintenance.enabled')) {
        return;
    }

    // Guard: listener service must be registered
    if (!$container->hasDefinition('tenancy.maintenance.listener')) {
        throw new \LogicException('tenancy: maintenance.enabled is true but the maintenance listener service is not registered.');
    }

    // Inspect kernel.event_listener tag priority (converted from #[AsEventListener] by autoconfigure)
    $def = $container->findDefinition('tenancy.maintenance.listener');
    $tags = $def->getTag('kernel.event_listener');
    foreach ($tags as $tag) {
        if (($tag['event'] ?? '') === \Symfony\Component\HttpKernel\KernelEvents::REQUEST) {
            $priority = (int) ($tag['priority'] ?? 0);
            if ($priority >= \Tenancy\Bundle\EventListener\TenantContextOrchestrator::PRIORITY) {
                throw new \LogicException(sprintf(
                    'tenancy: TenantMaintenanceModeListener must be registered at priority < %d '
                    . '(TenantContextOrchestrator::PRIORITY) so the tenant is already resolved. '
                    . 'Got priority %d. Set it to %d or lower.',
                    \Tenancy\Bundle\EventListener\TenantContextOrchestrator::PRIORITY,
                    $priority,
                    \Tenancy\Bundle\EventListener\TenantMaintenanceModeListener::PRIORITY,
                ));
            }
        }
    }
}
```

**Registration in `TenancyBundle::build()`** (lines 393–413):
```php
// src/TenancyBundle.php:393-413
public function build(ContainerBuilder $container): void
{
    parent::build($container);
    $container->addCompilerPass(new BootstrapperChainPass());
    // ...
    if (interface_exists(\League\Flysystem\FilesystemOperator::class)) {
        $container->addCompilerPass(new FilesystemContractPass());
    }
    // ...
}
```
**Phase 32 deviation:** `MaintenanceModeContractPass` is always registered (no `interface_exists` guard — maintenance has no optional library dependency). Add it to `build()` unconditionally:
```php
$container->addCompilerPass(new MaintenanceModeContractPass());
```

---

### `src/Command/TenantMaintenanceEnableCommand.php` and `TenantMaintenanceDisableCommand.php` (command, CRUD)

**Primary analog:** `src/Command/SharedEntityResyncCommand.php`

**Class header + #[AsCommand] + constructor** (lines 1–34 of analog):
```php
// src/Command/SharedEntityResyncCommand.php:1-34
declare(strict_types=1);

namespace Tenancy\Bundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
// ... other use statements

#[AsCommand(name: 'tenancy:shared:resync', description: '...')]
final class SharedEntityResyncCommand extends Command
{
    public function __construct(
        private readonly TenantProviderInterface $tenantProvider,
        private readonly BootstrapperChain $bootstrapperChain,
        private readonly TenantContext $tenantContext,
        private readonly string $driver,
        private readonly EntityManagerInterface $landlordEm,
        // ...
    ) {
        parent::__construct();
    }
```

**Phase 32 deviation — CRITICAL:** The maintenance enable/disable commands inject a DIFFERENT set of services than `SharedEntityResyncCommand`. They must NOT inject `TenantProviderInterface`, `BootstrapperChain`, or `TenantContext` (no tenant boot, no resolver — Pitfall from RESEARCH). The correct constructor:
```php
public function __construct(
    private readonly EntityManagerInterface $landlordEm,
    private readonly string $tenantEntityClass,
    private readonly CacheInterface $cache,           // symfony/cache, for PSR key deletion
    private readonly EventDispatcherInterface $eventDispatcher,
) {
    parent::__construct();
}
```

**Phase 32 deviation — DB fetch pattern:** Do NOT call `$tenantProvider->findBySlug()`. Instead fetch directly via the landlord EM repository to bypass both the PSR cache and the `isActive()` gate (RESEARCH Pitfall 2). Pattern from RESEARCH.md §Cache Coherence:
```php
// Pattern confirmed in src/Provider/DoctrineTenantProvider.php:36-39
/** @var class-string<TenantInterface> $entityClass */
$entityClass = $this->tenantEntityClass;
/** @var TenantInterface|null $tenant */
$tenant = $this->landlordEm->getRepository($entityClass)->findOneBy(['slug' => $slug]);
if (null === $tenant) {
    $io->error(sprintf('Tenant "%s" not found.', $slug));
    return Command::FAILURE;
}
```

**Phase 32 deviation — cache invalidation after flush:**
```php
// REQUIRED after $this->landlordEm->flush() — RESEARCH.md §Cache Coherence Verdict
$this->cache->delete('tenancy.tenant.' . $slug);
```
PSR cache key format confirmed from `src/Provider/DoctrineTenantProvider.php:32`: `'tenancy.tenant.'.$slug`.

**Phase 32 deviation — idempotent check (D-08):**
```php
// enable command idempotent guard:
if ($tenant->isInMaintenance()) {
    $io->writeln(sprintf('Tenant "%s" is already in maintenance mode.', $slug));
    return Command::SUCCESS;
}
// disable command idempotent guard:
if (!$tenant->isInMaintenance()) {
    $io->writeln(sprintf('Tenant "%s" is not in maintenance mode.', $slug));
    return Command::SUCCESS;
}
```
Events `TenantMaintenanceEnabled` / `TenantMaintenanceDisabled` are dispatched only when the boolean actually changes (D-08).

**InputArgument** (single `<slug>` positional, D-09):
```php
// Pattern from TenantMigrateCommand.php:43-47 (--tenant option) adapted as a required positional argument:
$this->addArgument('slug', InputArgument::REQUIRED, 'Tenant slug');
```
No `--all` / variadic (D-09 out of scope).

**SymfonyStyle usage** (lines 58–60 of resync analog):
```php
// src/Command/SharedEntityResyncCommand.php:60
$io = new SymfonyStyle($input, $output);
```

---

### `src/Command/TenantMaintenanceStatusCommand.php` (command, request-response)

**Primary analog:** `src/Command/TenantMigrateCommand.php` for `--format=json` table pattern.

**`--format` option declaration** (lines 69–74 of migrate analog):
```php
// src/Command/TenantMigrateCommand.php:69-74
$this->addOption(
    'format',
    null,
    InputOption::VALUE_REQUIRED,
    'Output format: txt (default) or json',
    'txt',
);
```

**Format parsing** (lines 134–135 of migrate analog):
```php
// src/Command/TenantMigrateCommand.php:134-135
$formatRaw = $input->getOption('format');
$format = \is_string($formatRaw) ? $formatRaw : 'txt';
```

**JSON output to raw `$output` (NOT `$io`)** (lines 172–204 of migrate analog):
```php
// src/Command/TenantMigrateCommand.php:200-204
$json = json_encode(
    $aggregate,
    \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR,
);
$output->writeln($json);
```

**Table output** (lines 207–227 of migrate analog):
```php
// src/Command/TenantMigrateCommand.php:215-228
$io->table(
    ['Tenant', 'Status', 'Migrations Applied', 'Duration'],
    $rows,
);
```

**Phase 32 deviation — data source:** `status` calls `findAll()` (bypasses PSR cache, from `DoctrineTenantProvider.php:64-75`), then filters for `isInMaintenance() === true`. No landlord EM needed directly — inject `TenantProviderInterface $tenantProvider` for the `status` command (unlike enable/disable which must bypass `findBySlug()`; `findAll()` is safe). Output only lists tenants currently in maintenance (D-10).

**Phase 32 deviation — JSON shape (D-10):** Status lists in-maintenance tenants only. Suggested shape:
```json
{"tenants": [{"slug": "acme", "inMaintenance": true}], "total": 1}
```
Mirror the `json_encode` flags from `TenantMigrateCommand.php:200-202`.

---

### `src/Event/TenantMaintenanceEnabled.php` and `TenantMaintenanceDisabled.php` (event, event-driven)

**Analog:** `src/Event/TenantResolved.php` (lines 1–18)

**Complete analog** (full file):
```php
// src/Event/TenantResolved.php:1-18
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Event;

use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\TenantInterface;

final class TenantResolved
{
    public function __construct(
        public readonly TenantInterface $tenant,
        public readonly ?Request $request,
        public readonly string $resolvedBy,
    ) {
    }
}
```

**Phase 32 deviation:** Only one constructor arg: `public readonly TenantInterface $tenant`. No `$request`, no `$resolvedBy`. Mirror `TenantBootstrapped` style (lines 1–19) which has only `TenantInterface $tenant` and `array $bootstrappers`. The maintenance events carry only the tenant that changed state:
```php
// src/Event/TenantBootstrapped.php:1-19 (closer shape for events with one entity arg)
final class TenantBootstrapped
{
    public function __construct(
        public readonly TenantInterface $tenant,
        public readonly array $bootstrappers,
    ) {
    }
}
```
Maintenance events: `TenantMaintenanceEnabled` and `TenantMaintenanceDisabled` each take only `TenantInterface $tenant`.

---

### `src/TenantInterface.php` (modified — additive)

**Current interface** (lines 1–25, full file):
```php
// src/TenantInterface.php:1-25
interface TenantInterface
{
    public function getSlug(): string;
    public function getDomain(): ?string;
    /** @return array<string, mixed> */
    public function getConnectionConfig(): array;
    public function getName(): string;
    public function isActive(): bool;
    public function getMailerDsn(): ?string;
    public function getMailerFrom(): ?string;
    public function getMailerReplyTo(): ?string;
}
```

**Addition:** One method added after `isActive()`:
```php
public function isInMaintenance(): bool;
```
This is the sole BC break for v0.5 (D-05). Existing custom tenant entities that implement `TenantInterface` but do not use `TenantMaintenanceConfigTrait` will fail with a missing method. The UPGRADE 0.4→0.5 note (Phase 34) covers the mitigation.

---

### `src/Entity/AbstractTenant.php` (modified — additive)

**Column naming pattern** (lines 38–39):
```php
// src/Entity/AbstractTenant.php:38-39 — exact pattern to mirror
#[ORM\Column(type: 'boolean')]
private bool $isActive = true;
```

**Phase 32 addition — after the `$filesystemConfig` property block** (after line 59):
```php
// Add after src/Entity/AbstractTenant.php:59 (filesystemConfig property)
// Maintenance flag (Phase 32 / MAINT-05).
// Users with a custom Tenant entity can equivalently `use \Tenancy\Bundle\Maintenance\TenantMaintenanceConfigTrait;`
// instead of inlining this column. See UPGRADE.md §0.4→0.5.
#[ORM\Column(type: 'boolean')]
private bool $inMaintenance = false;
```

**Getter/setter naming pattern** (lines 107–115, `isActive` / `setIsActive`):
```php
// src/Entity/AbstractTenant.php:107-115
public function isActive(): bool
{
    return $this->isActive;
}
// ...
public function setIsActive(bool $isActive): self
{
    $this->isActive = $isActive;
    return $this;
}
```
**Phase 32 deviation:** `AbstractTenant` setters use `self` return type, NOT `static`. The trait uses `static` (per `TenantFilesystemConfigTrait:62`). Both are correct in their context — `AbstractTenant` methods should follow `AbstractTenant`'s own return-type convention (`self`); the trait's methods return `static` for fluency in subclasses. Add to `AbstractTenant`:
```php
public function isInMaintenance(): bool
{
    return $this->inMaintenance;
}

public function setInMaintenance(bool $inMaintenance): self
{
    $this->inMaintenance = $inMaintenance;
    return $this;
}
```

---

### `src/TenancyBundle.php` (modified — additive, config + build)

**Config node pattern — `filesystem` arrayNode** (lines 119–127 of `configure()`):
```php
// src/TenancyBundle.php:119-127
->arrayNode('filesystem')
->addDefaultsIfNotSet()
->children()
->booleanNode('enabled')->defaultFalse()->end()
->booleanNode('allow_per_tenant_adapter')->defaultTrue()->end()
->scalarNode('prefix_template')->defaultValue('tenant_{slug}/')->end()
->integerNode('cache_size')->defaultValue(32)->min(1)->end()
->end()
->end()
```

**`origin.allow_list` array node pattern** (lines 79–105):
```php
// src/TenancyBundle.php:79-105
->arrayNode('origin')
->addDefaultsIfNotSet()
->children()
->arrayNode('allow_list')
->defaultValue([])
->beforeNormalization()
    ->always(static function (mixed $v): array {
        if (!is_array($v)) { return []; }
        return array_map(...);
    })
->end()
->arrayPrototype()
->children()
->scalarNode('origin')->isRequired()->cannotBeEmpty()->end()
->scalarNode('slug')->defaultNull()->end()
->end()
->end()
->end()
->end()
->end()
```

**Phase 32 maintenance config node** (add after the `shared` node, before the closing `->validate()`):
```php
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

**Parameter wiring in `loadExtension()`** (lines 188–202 pattern):
```php
// src/TenancyBundle.php:188-202 — existing parameter wiring block to extend
$container->parameters()
    ->set('tenancy.driver', $config['driver'])
    // ...
    ->set('tenancy.filesystem.enabled', $filesystemEnabled)
    // ...
    ->set('tenancy.shared.async', $sharedAsync);
```
Add maintenance parameters with the same `is_scalar` / type-cast guards:
```php
/** @var array<string, mixed> $maintenanceConfig */
$maintenanceConfig = $config['maintenance'] ?? [];
$maintenanceEnabled = is_scalar($maintenanceConfig['enabled'] ?? false) ? (bool) ($maintenanceConfig['enabled'] ?? false) : false;
$maintenanceRetryAfter = is_int($maintenanceConfig['retry_after'] ?? 3600) ? (int) $maintenanceConfig['retry_after'] : 3600;
$maintenanceTemplate = isset($maintenanceConfig['template']) && is_scalar($maintenanceConfig['template']) ? (string) $maintenanceConfig['template'] : null;
$maintenanceAllowIps = is_array($maintenanceConfig['allow_ips'] ?? []) ? $maintenanceConfig['allow_ips'] : [];
$maintenanceAllowRoutes = is_array($maintenanceConfig['allow_routes'] ?? []) ? $maintenanceConfig['allow_routes'] : [];
$maintenanceAllowPaths = is_array($maintenanceConfig['allow_paths'] ?? []) ? $maintenanceConfig['allow_paths'] : [];
// ...then in ->set() chain:
->set('tenancy.maintenance.enabled', $maintenanceEnabled)
->set('tenancy.maintenance.retry_after', $maintenanceRetryAfter)
->set('tenancy.maintenance.template', $maintenanceTemplate)
->set('tenancy.maintenance.allow_ips', $maintenanceAllowIps)
->set('tenancy.maintenance.allow_routes', $maintenanceAllowRoutes)
->set('tenancy.maintenance.allow_paths', $maintenanceAllowPaths)
```

**Listener conditional registration in `loadExtension()`** (mirror of filesystem `interface_exists` block):
```php
// When maintenance.enabled is true, register the listener service.
// (services.php handles the unconditional registration of status command and the pass)
if ($maintenanceEnabled) {
    $services = $container->services();
    $services->set('tenancy.maintenance.listener', TenantMaintenanceModeListener::class)
        ->autoconfigure(true)
        ->args([
            service('tenancy.context'),
            param('tenancy.maintenance.retry_after'),
            param('tenancy.maintenance.template'),
            param('tenancy.maintenance.allow_ips'),
            param('tenancy.maintenance.allow_routes'),
            param('tenancy.maintenance.allow_paths'),
            service('twig')->nullOnInvalid(),  // D-02: nullable Twig env
            service('event_dispatcher'),
        ]);
}
```

**`build()` addition** (lines 393–413 pattern):
```php
// src/TenancyBundle.php:393-413 — add after the existing compiler pass registrations
$container->addCompilerPass(new MaintenanceModeContractPass());
```
No `interface_exists` guard (maintenance has no optional library dep).

**Use statement to add** (mirror line 28):
```php
use Tenancy\Bundle\DependencyInjection\Compiler\MaintenanceModeContractPass;
use Tenancy\Bundle\EventListener\TenantMaintenanceModeListener;
```

---

### `config/services.php` (modified — additive)

**`interface_exists` guard pattern** (lines 60–68 and 103–107):
```php
// config/services.php:60-68
if (interface_exists(Doctrine\ORM\EntityManagerInterface::class)) {
    $services->set('tenancy.provider', DoctrineTenantProvider::class)
        ->args([
            service('doctrine.orm.default_entity_manager'),
            service('cache.app'),
            param('tenancy.tenant_entity_class'),
        ]);
    $services->alias(TenantProviderInterface::class, 'tenancy.provider');
}
```

**`nullOnInvalid()` pattern for optional services** (line 97–100):
```php
// config/services.php:94-100
$services->set(TenantContextOrchestrator::class)
    ->autoconfigure(true)
    ->args([
        service('tenancy.context'),
        service('tenancy.bootstrapper_chain'),
        service('event_dispatcher'),
        service('tenancy.resolver_chain'),
    ]);
```

**console.command tag pattern** (lines 125–131):
```php
// config/services.php:125-131
$services->set('tenancy.command.run', TenantRunCommand::class)
    ->args([
        service('tenancy.provider')->nullOnInvalid(),
        param('kernel.project_dir'),
    ])
    ->tag('console.command');
```

**Phase 32 additions to `config/services.php`:**

The three commands and status command are always registered (the listener is registered conditionally from `TenancyBundle::loadExtension()` when `enabled: true`):
```php
// Add inside the interface_exists(EntityManagerInterface::class) guard (commands require Doctrine)
if (interface_exists(Doctrine\ORM\EntityManagerInterface::class)) {
    // Status command uses TenantProviderInterface::findAll() — safe (bypasses PSR cache)
    $services->set('tenancy.command.maintenance.status', TenantMaintenanceStatusCommand::class)
        ->args([
            service('tenancy.provider')->nullOnInvalid(),
        ])
        ->tag('console.command');

    // Enable/disable commands bypass the provider and use the landlord EM directly
    // (bypasses PSR cache + isActive() gate — see RESEARCH.md §Cache Coherence)
    $services->set('tenancy.command.maintenance.enable', TenantMaintenanceEnableCommand::class)
        ->args([
            service('doctrine.orm.default_entity_manager'),   // overridden to landlord EM when database.enabled: true
            param('tenancy.tenant_entity_class'),
            service('cache.app'),
            service('event_dispatcher'),
        ])
        ->tag('console.command');

    $services->set('tenancy.command.maintenance.disable', TenantMaintenanceDisableCommand::class)
        ->args([
            service('doctrine.orm.default_entity_manager'),
            param('tenancy.tenant_entity_class'),
            service('cache.app'),
            service('event_dispatcher'),
        ])
        ->tag('console.command');
}
```

**Landlord EM rewire in `TenancyBundle::loadExtension()` when `database.enabled: true`** (lines 250–252 pattern):
```php
// src/TenancyBundle.php:250-252 — existing rewire pattern
$builder->getDefinition('tenancy.provider')
    ->setArgument(0, new Reference('doctrine.orm.landlord_entity_manager'));
```
When `database.enabled: true`, the enable/disable command service definitions must also have argument 0 rewired to `doctrine.orm.landlord_entity_manager` (same block in `loadExtension()`):
```php
// Add in the database.enabled: true block of loadExtension():
if (interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
    $builder->getDefinition('tenancy.command.maintenance.enable')
        ->setArgument(0, new Reference('doctrine.orm.landlord_entity_manager'));
    $builder->getDefinition('tenancy.command.maintenance.disable')
        ->setArgument(0, new Reference('doctrine.orm.landlord_entity_manager'));
}
```

---

## Shared Patterns

### `declare(strict_types=1);`
**Source:** Every file in `src/` and `tests/`
**Apply to:** All new files — PHP 8.2 project convention. Always first line after `<?php`.

### `#[AsEventListener]` autoconfiguration
**Source:** `config/services.php:94-101` (TenantContextOrchestrator registration with `->autoconfigure(true)`)
```php
// config/services.php:94-101
$services->set(TenantContextOrchestrator::class)
    ->autoconfigure(true)
    ->args([...]);
```
**Apply to:** `TenantMaintenanceModeListener` service registration. `autoconfigure(true)` converts the `#[AsEventListener]` attribute to a `kernel.event_listener` tag, which `MaintenanceModeContractPass` then inspects.

### Error handling in commands: `\LogicException` for programming errors
**Source:** `src/DependencyInjection/Compiler/MailerTransportContractPass.php:46,67`
```php
throw new \LogicException(sprintf('tenancy: parameter "%s" must be declared ...', self::ASYNC_PARAM));
```
**Apply to:** `MaintenanceModeContractPass` — use `\LogicException` for misconfiguration caught at compile time.

### `SymfonyStyle` for command output
**Source:** `src/Command/SharedEntityResyncCommand.php:60` and `TenantMigrateCommand.php:79`
```php
$io = new SymfonyStyle($input, $output);
```
**Apply to:** All three maintenance commands.

### `parent::__construct()` call in command constructors
**Source:** `src/Command/SharedEntityResyncCommand.php:33`
```php
parent::__construct();
```
**Apply to:** All three maintenance commands — required by Symfony `Command`.

### `readonly` properties in event classes
**Source:** `src/Event/TenantResolved.php:12-16`
```php
public function __construct(
    public readonly TenantInterface $tenant,
    // ...
```
**Apply to:** `TenantMaintenanceEnabled` and `TenantMaintenanceDisabled`.

### Cache key format for PSR invalidation
**Source:** `src/Provider/DoctrineTenantProvider.php:32`
```php
'tenancy.tenant.'.$slug
```
**Apply to:** `TenantMaintenanceEnableCommand` and `TenantMaintenanceDisableCommand` — delete this exact key via `$this->cache->delete('tenancy.tenant.'.$slug)` after every successful flush.

---

## No Analog Found

All files for this phase have close analogs in the codebase. No "no analog" entries.

---

## Analogs NOT to Follow (Divergence Summary)

| Pattern in Analog | Used By | Phase 32 Must NOT Follow |
|-------------------|---------|--------------------------|
| `TenantProviderInterface::findBySlug()` for tenant lookup | `SharedEntityResyncCommand`, `TenantMigrateCommand` | Enable/disable commands: `findBySlug()` throws `TenantInactiveException` for inactive tenants and returns a PSR-cached object. Use `$landlordEm->getRepository($class)->findOneBy(['slug' => $slug])` instead. |
| `$bootstrapperChain->boot($tenant)` + `$tenantContext->setTenant($tenant)` in commands | `SharedEntityResyncCommand:126-127`, `TenantMigrateCommand:280-281` | Maintenance commands write landlord-side only — NO tenant bootstrapping, NO `TenantContext` set. |
| Throw `HttpExceptionInterface` for HTTP errors | `TenantInactiveException` (403) | Maintenance listener builds a `Response` directly via `$event->setResponse()` so it can set `Retry-After` + `Cache-Control: no-store` headers (D-01/D-03). |
| `interface_exists` guard around compiler pass registration in `build()` | `MailerTransportContractPass`, `FilesystemContractPass` | `MaintenanceModeContractPass` has no optional library dependency — register unconditionally. |
| `static` return type on `AbstractTenant` setters | `TenantFilesystemConfigTrait:62`, `TenantMailerConfigTrait:45` | `AbstractTenant`'s own setters use `self` (lines 113, 124, etc.) — follow `self` in `AbstractTenant`, `static` in the trait. |

---

## Metadata

**Analog search scope:** `src/`, `tests/Unit/`, `tests/Integration/`, `config/`
**Files scanned:** 18 source files read directly
**Pattern extraction date:** 2026-07-01
