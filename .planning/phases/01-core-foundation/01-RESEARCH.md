# Phase 1: Core Foundation - Research

**Researched:** 2026-03-17
**Domain:** Symfony AbstractBundle (6.1+), DI compiler passes, Doctrine entity mapping, PSR-14 events, kernel lifecycle listeners
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Package identity**
- Packagist name: `danplaton4/tenancy-bundle`
- PHP root namespace: `Tenancy\Bundle` (e.g. `Tenancy\Bundle\TenantContext`, `Tenancy\Bundle\Event\TenantResolved`)
- Symfony Bundle class: `TenancyBundle` registered as `new Tenancy\Bundle\TenancyBundle()` in `bundles.php`
- Bundle config root key: `tenancy:` (standard Symfony convention)

**Bundle configuration shape (tenancy.yaml)**
- Driver selection: `tenancy.driver: database_per_tenant` or `shared_database` — single key
- Resolver priority: hybrid — built-in resolvers via YAML list; custom resolvers via DI tag `tenancy.resolver` with `priority` attribute
- v1 top-level config keys: `strict_mode`, `landlord_connection`, `tenant_entity_class`, `cache_prefix_separator`

**Tenant entity design**
- Primary identifier: `slug` (string PK, no separate auto-increment id)
- Fields: `slug` (PK), `domain` (nullable), `connection_config` (JSON), `name`, `is_active` (default true), `created_at`, `updated_at`
- `TenantInterface`: `getSlug(): string`, `getDomain(): ?string`, `getConnectionConfig(): array`, `getName(): string`, `isActive(): bool`
- Bundle ships concrete `Tenancy\Bundle\Entity\Tenant` implementing `TenantInterface`

**Event design**
- All events are plain PHP objects (PSR-14 / Symfony 5+ style) — no base class extension, no `stopPropagation()`
- `TenantResolved` carries: `tenant: TenantInterface`, `request: ?Request`, `resolvedBy: string`
- `TenantBootstrapped` carries: `tenant: TenantInterface`, `bootstrappers: string[]`
- `TenantContextCleared` — signal-only (no payload)

**TenantContext design**
- Zero-dependency pure value holder — no injected services
- API: `setTenant(TenantInterface $tenant): void`, `getTenant(): ?TenantInterface`, `hasTenant(): bool`, `clear(): void`
- Service ID: `tenancy.context` (also aliased to `Tenancy\Bundle\TenantContext` for autowiring)

**Bootstrapper chain**
- Interface: `Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface` with `boot(TenantInterface $tenant): void` and `clear(): void`
- Tagged with `tenancy.bootstrapper` — compiler pass collects and injects into `BootstrapperChain`
- `BootstrapperChain` runs bootstrappers in tag priority order

**Kernel event wiring**
- `TenantContextOrchestrator` listens on `kernel.request` at priority 20 — defined as `public const PRIORITY = 20`
- Context clear fires on `kernel.terminate`

### Claude's Discretion
- Internal ordering of compiler pass execution
- Exact DI service IDs for internal/private services
- How `connection_config` JSON is validated (schema vs. loose array)
- Whether `TenantBootstrapperInterface::clear()` receives the previous tenant or nothing

### Deferred Ideas (OUT OF SCOPE)
- Project setup / init phase (package init, PHPStan, Makefile)
- `TenantContextCleared` previous tenant payload — v1.1 roadmap
- `TenantBootstrapperInterface::clear($previousTenant)` — same deferral
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| CORE-01 | Bundle provides a stateful `TenantContext` service (leaf-node, no circular deps) that all tenant-aware services read at call time | Pure value holder pattern; zero-dependency class; service tagged `tenancy.context`; aliased for autowiring |
| CORE-02 | Bundle fires `TenantResolved`, `TenantBootstrapped`, `TenantContextCleared` Symfony events at each lifecycle stage | PSR-14 plain PHP objects dispatched via `EventDispatcherInterface`; readonly constructor properties; documented dispatch points |
| CORE-03 | Bundle provides `TenantBootstrapperInterface`; a compiler pass auto-tags implementations so users register bootstrappers via DI config only | `registerForAutoconfiguration()` in `build()` + `BootstrapperChainPass` using `PriorityTaggedServiceTrait::findAndSortTaggedServices()` |
| CORE-04 | Bundle ships a `Tenant` Doctrine entity in the landlord DB with slug, domain, connection config, and status fields | PHP 8 attribute mapping (`#[ORM\Entity]` etc.); `prependExtension()` registers mapping automatically; slug as string PK |
| CORE-05 | Tenant resolution fires at `kernel.request` priority 20 — after router (32) and before security (8) | `#[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]` on `TenantContextOrchestrator`; public `PRIORITY = 20` constant |
</phase_requirements>

---

## Summary

Phase 1 builds the architectural skeleton every subsequent phase depends on. The five components — `TenantContext`, lifecycle events, `TenantBootstrapperInterface` + `BootstrapperChain`, the `Tenant` entity, and `TenantContextOrchestrator` — form a closed, testable unit with no external I/O requirements other than a Doctrine entity manager for the `Tenant` entity.

The Symfony 6.1+ `AbstractBundle` pattern (single class, `configure()` + `loadExtension()` + `build()`) replaces the legacy `Extension + DependencyInjection` triple. Compiler passes registered in `build()` collect `tenancy.bootstrapper`-tagged services; `registerForAutoconfiguration()` in `loadExtension()` auto-tags all `TenantBootstrapperInterface` implementations so user-land code needs zero DI boilerplate. The `Tenant` entity uses PHP 8 attribute mapping registered via `prependExtension()`, so no user configuration is needed for Doctrine to discover it.

The critical architectural constraint for Phase 1 is Pitfall 8: `TenantContext` must be a zero-dependency pure value holder. Any dependency injection into `TenantContext` creates a circular graph at compile time. Similarly, CORE-05's kernel.request priority 20 is non-negotiable — priority 0 would cause Security (priority 8) to run before the tenant is resolved.

**Primary recommendation:** Implement `TenancyBundle` as a single `AbstractBundle` subclass; use `build()` for compiler passes and `registerForAutoconfiguration()`; use `prependExtension()` to inject Doctrine mapping; use `#[AsEventListener]` for both `kernel.request` and `kernel.terminate` listeners.

---

## Standard Stack

### Core (Phase 1 runtime deps)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `symfony/http-kernel` | `^6.4\|\|^7.4` | `AbstractBundle`, `KernelEvents`, `RequestEvent`, `TerminateEvent` | All Phase 1 kernel integration lives here |
| `symfony/dependency-injection` | `^6.4\|\|^7.4` | `CompilerPassInterface`, `PriorityTaggedServiceTrait`, `ContainerBuilder` | Compiler pass and DI registration |
| `symfony/event-dispatcher` | `^6.4\|\|^7.4` | `EventDispatcherInterface`, `#[AsEventListener]` attribute | PSR-14 lifecycle events |
| `doctrine/orm` | `^3.3` | `#[ORM\Entity]`, `#[ORM\Column]`, `#[ORM\HasLifecycleCallbacks]`, `EntityManagerInterface` | `Tenant` entity mapping and persistence |
| `doctrine/dbal` | `^4.4` | `Types` constants for column type declaration | DBAL 4 is the DBAL floor for ORM 3.3 |

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `doctrine/doctrine-bundle` | `^2.13` (dev dep) | DoctrineBundle integration in test kernel | Required in `require-dev`; 3.x is PHP ^8.4 only |
| `phpunit/phpunit` | `^11.0` | Unit and integration tests | PHPUnit 11 floors at PHP 8.2; aligns with bundle floor |
| `symfony/phpunit-bridge` | `^6.4\|\|^7.4` | Symfony deprecation detection | Required alongside PHPUnit for deprecation helper |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `#[AsEventListener]` attribute | `EventSubscriberInterface` | Subscriber groups events but requires `getSubscribedEvents()` static array; attribute is more discoverable per-method |
| `PriorityTaggedServiceTrait` | Manual `usort()` + `findTaggedServiceIds()` | Trait handles edge cases (equal priority, FIFO), reduces boilerplate; prefer trait |
| PHP 8 attribute entity mapping | XML mapping (`DoctrineOrmMappingsPass`) | XML allows user overrides; attributes are simpler for a concrete (non-extendable) `Tenant` entity; users override via `tenant_entity_class` config, not XML override |

**Installation (Phase 1 runtime — not the full bundle):**

```bash
composer require \
  "symfony/http-kernel:^6.4|^7.4" \
  "symfony/dependency-injection:^6.4|^7.4" \
  "symfony/event-dispatcher:^6.4|^7.4" \
  "doctrine/orm:^3.3" \
  "doctrine/dbal:^4.4"
```

---

## Architecture Patterns

### Recommended Project Structure (Phase 1 scope only)

```
src/
├── TenancyBundle.php              # AbstractBundle — build(), loadExtension(), configure(), prependExtension()
├── TenantInterface.php            # getSlug(), getDomain(), getConnectionConfig(), getName(), isActive()
│
├── Context/
│   └── TenantContext.php          # Pure value holder; setTenant/getTenant/hasTenant/clear; service ID tenancy.context
│
├── Event/
│   ├── TenantResolved.php         # readonly constructor: tenant + request + resolvedBy
│   ├── TenantBootstrapped.php     # readonly constructor: tenant + bootstrappers[]
│   └── TenantContextCleared.php   # signal-only; no properties
│
├── Bootstrapper/
│   ├── TenantBootstrapperInterface.php  # boot(TenantInterface): void; clear(): void
│   └── BootstrapperChain.php            # holds ordered bootstrapper array; addBootstrapper(); boot(); clear()
│
├── DependencyInjection/
│   ├── Configuration.php                # (optional) or inline in TenancyBundle::configure()
│   └── Compiler/
│       └── BootstrapperChainPass.php    # CompilerPassInterface; PriorityTaggedServiceTrait
│
├── Entity/
│   └── Tenant.php                       # #[ORM\Entity], slug PK, domain, connection_config, name, is_active, timestamps
│
└── EventListener/
    └── TenantContextOrchestrator.php    # kernel.request priority 20; kernel.terminate; public const PRIORITY = 20
```

### Pattern 1: AbstractBundle — Three-Method Bundle Class

**What:** In Symfony 6.1+, `AbstractBundle` replaces the legacy `Bundle + Extension + Configuration` triple. The bundle class itself contains `configure()` (tree definition), `loadExtension()` (service loading + container setup), and `build()` (compiler pass registration).

**When to use:** Always — this is the correct Symfony-native approach for new bundles targeting 6.1+.

**Example:**
```php
// Source: https://symfony.com/blog/new-in-symfony-6-1-simpler-bundle-extension-and-configuration
namespace Tenancy\Bundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface;
use Tenancy\Bundle\DependencyInjection\Compiler\BootstrapperChainPass;

class TenancyBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('driver')->defaultValue('database_per_tenant')->end()
                ->booleanNode('strict_mode')->defaultTrue()->end()
                ->scalarNode('landlord_connection')->defaultValue('default')->end()
                ->scalarNode('tenant_entity_class')->defaultValue(Entity\Tenant::class)->end()
                ->scalarNode('cache_prefix_separator')->defaultValue(':')->end()
            ->end()
        ;
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        // Auto-tag all TenantBootstrapperInterface implementations
        $builder->registerForAutoconfiguration(TenantBootstrapperInterface::class)
            ->addTag('tenancy.bootstrapper');

        // Pass config to container parameters
        $container->parameters()
            ->set('tenancy.driver', $config['driver'])
            ->set('tenancy.strict_mode', $config['strict_mode'])
            ->set('tenancy.landlord_connection', $config['landlord_connection'])
            ->set('tenancy.tenant_entity_class', $config['tenant_entity_class']);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new BootstrapperChainPass());
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Register bundle's Tenant entity mapping automatically
        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'TenancyBundle' => [
                        'is_bundle' => false,
                        'type' => 'attribute',
                        'dir' => __DIR__.'/Entity',
                        'prefix' => 'Tenancy\Bundle\Entity',
                        'alias' => 'TenancyBundle',
                    ],
                ],
            ],
        ]);
    }
}
```

### Pattern 2: Compiler Pass with PriorityTaggedServiceTrait

**What:** `BootstrapperChainPass` implements `CompilerPassInterface` and uses `PriorityTaggedServiceTrait::findAndSortTaggedServices()` to collect all `tenancy.bootstrapper`-tagged services, already sorted descending by `priority` attribute (FIFO for equal priorities), and inject them into `BootstrapperChain`.

**When to use:** Whenever an ordered collection of tagged services must be wired into a chain/composite service at compile time.

**Example:**
```php
// Source: https://symfony.com/doc/current/service_container/compiler_passes.html
namespace Tenancy\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PriorityTaggedServiceTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;

final class BootstrapperChainPass implements CompilerPassInterface
{
    use PriorityTaggedServiceTrait;

    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(BootstrapperChain::class)) {
            return;
        }

        $definition = $container->findDefinition(BootstrapperChain::class);

        // Returns Reference[] sorted by priority descending, FIFO for equal priority
        $bootstrappers = $this->findAndSortTaggedServices('tenancy.bootstrapper', $container);

        foreach ($bootstrappers as $bootstrapper) {
            $definition->addMethodCall('addBootstrapper', [$bootstrapper]);
        }
    }
}
```

**Key detail:** `findAndSortTaggedServices()` returns `Reference[]` already sorted — higher `priority` attribute value runs first. No manual `usort()` needed.

### Pattern 3: PSR-14 Events as Readonly PHP Objects

**What:** Events are plain PHP classes with `readonly` constructor properties (PHP 8.1+). No base class, no `StopPropagationCapableInterface`, no `GenericEvent`. Dispatched via `EventDispatcherInterface::dispatch()`.

**When to use:** All three Phase 1 events — `TenantResolved`, `TenantBootstrapped`, `TenantContextCleared`.

**Example:**
```php
// Source: PSR-14 spec; Symfony event-dispatcher docs
namespace Tenancy\Bundle\Event;

use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\TenantInterface;

final class TenantResolved
{
    public function __construct(
        public readonly TenantInterface $tenant,
        public readonly ?Request $request,
        public readonly string $resolvedBy,
    ) {}
}

final class TenantBootstrapped
{
    public function __construct(
        public readonly TenantInterface $tenant,
        /** @param string[] $bootstrappers FQCNs of bootstrappers that ran */
        public readonly array $bootstrappers,
    ) {}
}

/** Signal-only — no payload. Phase 1: carry nothing. */
final class TenantContextCleared {}
```

**Dispatching:**
```php
// Inside TenantContextOrchestrator or BootstrapperChain
$this->eventDispatcher->dispatch(new TenantResolved($tenant, $request, $resolvedBy));
```

### Pattern 4: Kernel Event Listener with #[AsEventListener]

**What:** Use the `#[AsEventListener]` PHP attribute on `TenantContextOrchestrator` methods. Place the priority as a class constant (`PRIORITY = 20`) so downstream code can reference it.

**When to use:** Both `kernel.request` (priority 20) and `kernel.terminate` (default priority 0) listeners.

**Example:**
```php
// Source: https://symfony.com/doc/current/event_dispatcher.html
namespace Tenancy\Bundle\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: TenantContextOrchestrator::PRIORITY)]
#[AsEventListener(event: KernelEvents::TERMINATE, method: 'onKernelTerminate')]
final class TenantContextOrchestrator
{
    /** Priority 20: after Router (32), before Security firewall (8). */
    public const PRIORITY = 20;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly BootstrapperChain $bootstrapperChain,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        // Resolution logic (Phase 2) sets $tenant, then:
        // $this->tenantContext->setTenant($tenant);
        // $this->eventDispatcher->dispatch(new TenantResolved(...));
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (!$this->tenantContext->hasTenant()) {
            return;
        }
        $this->bootstrapperChain->clear();
        $this->tenantContext->clear();
        $this->eventDispatcher->dispatch(new TenantContextCleared());
    }
}
```

**Important:** `#[AsEventListener]` is auto-discovered when the class is registered as a service via `autoconfigure: true`. In Phase 1's `services.php`, load the `EventListener/` namespace with autoconfigure enabled.

### Pattern 5: Tenant Entity with Slug PK

**What:** Doctrine entity using PHP 8 attributes, slug as string PK (no `#[ORM\GeneratedValue]`), JSON column for `connection_config`, lifecycle callbacks for timestamps.

**When to use:** The `Tenant` entity in the landlord EntityManager.

**Example:**
```php
namespace Tenancy\Bundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Tenancy\Bundle\TenantInterface;

#[ORM\Entity]
#[ORM\Table(name: 'tenancy_tenants')]
#[ORM\HasLifecycleCallbacks]
class Tenant implements TenantInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 63)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 253, nullable: true)]
    private ?string $domain = null;

    #[ORM\Column(type: 'json')]
    private array $connectionConfig = [];

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ... getters implementing TenantInterface
}
```

**Notes:**
- Slug length 63 = DNS label max; domain length 253 = FQDN max.
- `#[ORM\GeneratedValue]` is deliberately absent — slug is user-supplied.
- Table name `tenancy_tenants` avoids collision with user tables named `tenant`.

### Pattern 6: TenantContext as Pure Value Holder

**What:** `TenantContext` has exactly zero constructor parameters. It holds `?TenantInterface $currentTenant` as a private property. No event dispatching from within `TenantContext` itself — the orchestrator dispatches events after calling `setTenant()`.

**When to use:** Phase 1 mandates this. NEVER add a constructor dependency to this class.

**Example:**
```php
namespace Tenancy\Bundle\Context;

use Tenancy\Bundle\TenantInterface;

final class TenantContext
{
    private ?TenantInterface $currentTenant = null;

    public function setTenant(TenantInterface $tenant): void
    {
        $this->currentTenant = $tenant;
    }

    public function getTenant(): ?TenantInterface
    {
        return $this->currentTenant;
    }

    public function hasTenant(): bool
    {
        return $this->currentTenant !== null;
    }

    public function clear(): void
    {
        $this->currentTenant = null;
    }
}
```

**Service registration:**
```php
// config/services.php inside the bundle
$services->set('tenancy.context', TenantContext::class)
    ->public()
    ->alias(TenantContext::class, 'tenancy.context');
```

### Anti-Patterns to Avoid

- **Injecting services into TenantContext:** Any constructor argument creates circular dependency risk at compile time. TenantContext is a leaf node — it must have zero deps.
- **Dispatching events from TenantContext:** Event dispatcher injection would be a constructor dep. The orchestrator owns event dispatch; TenantContext just holds state.
- **Using default priority 0 on kernel.request:** Security firewall runs at priority 8 — a priority-0 tenant listener runs AFTER security, causing null-context token loading.
- **Registering compiler pass in `loadExtension()` instead of `build()`:** Compiler passes must be registered in `build()` (called before container compilation). `loadExtension()` runs during compilation — too late for pass registration.
- **Using XML mapping for the Tenant entity:** XML is preferred for extendable mapped-superclasses. The `Tenant` entity is concrete (users replace via `tenant_entity_class`, not XML override), so PHP 8 attribute mapping is simpler and sufficient.
- **Forgetting `if (!$event->isMainRequest()) return;` in kernel.request listener:** Without this guard, sub-requests (ESI, HttpKernel::handle()) trigger tenant resolution again, potentially clearing a valid tenant context.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Sorting tagged services by priority | Custom `usort()` + `findTaggedServiceIds()` | `PriorityTaggedServiceTrait::findAndSortTaggedServices()` | Handles FIFO for equal priorities, returns `Reference[]` directly |
| Auto-tagging interface implementations | Manual `_instanceof` config in services.yaml | `$builder->registerForAutoconfiguration(Interface::class)->addTag(...)` in `loadExtension()` | Zero user config; compiler-enforced |
| Timestamp management on entity | Manual `setCreatedAt()` calls | `#[ORM\HasLifecycleCallbacks]` + `#[ORM\PrePersist]` / `#[ORM\PreUpdate]` | Doctrine handles at persistence time |
| Bundle Doctrine entity discovery | User must add `doctrine.orm.mappings` to their config | `prependExtension()` with `$builder->prependExtensionConfig('doctrine', [...])` | Zero user config required |
| Event listener registration | XML/YAML service tags | `#[AsEventListener]` attribute with `autoconfigure: true` | Attribute lives next to the handler method; no external config file |

**Key insight:** Symfony's compiler pass machinery (`PriorityTaggedServiceTrait`, `registerForAutoconfiguration`) handles the entire bootstrapper auto-discovery chain. Writing custom sorting or tagging logic creates a maintenance burden and misses edge cases (FIFO ordering, lazy services).

---

## Common Pitfalls

### Pitfall 1: TenantContext Constructor Dependencies (CRITICAL)
**What goes wrong:** Adding any constructor argument to `TenantContext` — even `EventDispatcherInterface` — creates a potential circular reference: bootstrappers depend on infrastructure services that may depend on `TenantContext`, and if `TenantContext` now depends on a service that transitively depends on a bootstrapper, the graph cycles.
**Why it happens:** Developers want `TenantContext::setTenant()` to dispatch `TenantResolved` automatically, which requires an `EventDispatcherInterface` dep.
**How to avoid:** Event dispatch is the orchestrator's job. `TenantContext` only stores state. `TenantContextOrchestrator` calls `setTenant()` then dispatches the event.
**Warning signs:** `ServiceCircularReferenceException` at container compile time; `TenantContext` constructor growing beyond zero parameters.

### Pitfall 2: kernel.request Priority Below 8 (Security)
**What goes wrong:** Security firewall (`kernel.request` priority 8) runs before tenant resolution. User authenticators call tenant-scoped repositories with a null `TenantContext` — resulting in `TenantNotResolvedException` or, worse, a data leak.
**Why it happens:** Default listener priority is 0; developers don't check Symfony's built-in priorities.
**How to avoid:** Register `TenantContextOrchestrator` at priority 20 (CORE-05 requirement). The `PRIORITY = 20` constant makes this explicit and referenceable.
**Warning signs:** Auth failures on login route only; Profiler shows tenant resolution after security token.

### Pitfall 3: Missing isMainRequest() Guard
**What goes wrong:** Sub-requests (HttpKernel::handle for ESI, error pages) trigger `kernel.request` again. If the orchestrator doesn't guard against sub-requests, it may attempt resolution with a fragmentary request or clear a valid tenant mid-request.
**Why it happens:** Developers test with direct HTTP requests only; sub-request scenario is not tested.
**How to avoid:** First line of `onKernelRequest()`: `if (!$event->isMainRequest()) { return; }`.
**Warning signs:** Tenant context mysteriously null mid-request; ESI fragments throw TenantNotResolvedException.

### Pitfall 4: PHP 8 Attribute Mapping Not Discovered
**What goes wrong:** The `Tenant` entity uses `#[ORM\Entity]` but Doctrine doesn't know about the bundle's `Entity/` directory, so the entity is invisible and queries fail.
**Why it happens:** Without `prependExtension()` registering the mapping, users must manually add `doctrine.orm.mappings` in their config — an undocumented footgun.
**How to avoid:** `prependExtension()` in `TenancyBundle` registers the mapping automatically. Users get entity discovery with zero config.
**Warning signs:** `Class "Tenancy\Bundle\Entity\Tenant" is not a valid entity` Doctrine exception on first run.

### Pitfall 5: Compiler Pass Registered in loadExtension() Not build()
**What goes wrong:** Registering `BootstrapperChainPass` inside `loadExtension()` instead of `build()` causes it to run too late — service definitions may already be frozen.
**Why it happens:** Developers confuse `loadExtension()` (service configuration time) with `build()` (pre-compile pass registration time).
**How to avoid:** Compiler passes always go in `build(ContainerBuilder $container): void`. Configuration and service loading go in `loadExtension()`.
**Warning signs:** `BootstrapperChain` receives no bootstrappers at runtime despite tagged services existing.

---

## Code Examples

### BootstrapperChain (Phase 1 shell — no bootstrappers yet)

```php
namespace Tenancy\Bundle\Bootstrapper;

use Tenancy\Bundle\TenantInterface;

final class BootstrapperChain
{
    /** @var TenantBootstrapperInterface[] */
    private array $bootstrappers = [];

    public function addBootstrapper(TenantBootstrapperInterface $bootstrapper): void
    {
        $this->bootstrappers[] = $bootstrapper;
    }

    public function boot(TenantInterface $tenant): void
    {
        foreach ($this->bootstrappers as $bootstrapper) {
            $bootstrapper->boot($tenant);
        }
    }

    public function clear(): void
    {
        foreach (array_reverse($this->bootstrappers) as $bootstrapper) {
            $bootstrapper->clear();
        }
    }
}
```

**Note:** `clear()` runs in reverse order — the last bootstrapper to boot is the first to clear. This mirrors the stack-based teardown pattern.

### TenantInterface Contract

```php
namespace Tenancy\Bundle;

interface TenantInterface
{
    public function getSlug(): string;
    public function getDomain(): ?string;
    /** @return array<string, mixed> */
    public function getConnectionConfig(): array;
    public function getName(): string;
    public function isActive(): bool;
}
```

### services.php for bundle internals

```php
// config/services.php (inside bundle — loaded by loadExtension)
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\EventListener\TenantContextOrchestrator;

return function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('tenancy.context', TenantContext::class)
        ->public();
    $services->alias(TenantContext::class, 'tenancy.context');

    $services->set('tenancy.bootstrapper_chain', BootstrapperChain::class)
        ->public(false);
    $services->alias(BootstrapperChain::class, 'tenancy.bootstrapper_chain');

    $services->set(TenantContextOrchestrator::class)
        ->autoconfigure(true)  // Enables #[AsEventListener] discovery
        ->args([
            service('tenancy.context'),
            service('tenancy.bootstrapper_chain'),
            service('event_dispatcher'),
        ]);
};
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `Bundle + Extension + Configuration` three-class pattern | Single `AbstractBundle` with `configure()` + `loadExtension()` | Symfony 6.1 (May 2022) | 60% less boilerplate; no separate Extension class needed |
| `getSubscribedEvents()` static array in subscriber | `#[AsEventListener]` attribute per method | Symfony 6.0 (PHP 8.0+ baseline) | Priority and event name live next to the handler method |
| `@ORM\Entity` annotation | `#[ORM\Entity]` PHP 8 attribute | Doctrine ORM 2.9 (2021); 3.x removed annotation support | Annotations deprecated in ORM 3.x; attributes are the only supported driver |
| Manual `usort()` over `findTaggedServiceIds()` | `PriorityTaggedServiceTrait::findAndSortTaggedServices()` | Symfony 3.2 (trait); widely used since Symfony 4 | Handles FIFO correctly; returns Reference[] directly |
| XML entity mapping in reusable bundles | PHP 8 attribute mapping + `prependExtension()` for discovery | Doctrine ORM 2.9 / Symfony 6.1 | Attributes simpler for concrete entities; `prependExtension()` eliminates user config |

**Deprecated/outdated:**
- Doctrine annotations (`@ORM\Entity`): Removed from ORM 3.x — never use in new code.
- Separate `Extension::load()` + `DependencyInjection/` folder: Still works but is legacy; `AbstractBundle` is the blessed replacement.
- `EventSubscriberInterface` with `getSubscribedEvents()`: Still valid, but `#[AsEventListener]` is preferred for single-event listeners in modern Symfony.
- `kernel.request` at default priority 0 for tenant resolution: Results in security firewall running before tenant is set — documented Symfony pitfall.

---

## Open Questions

1. **Should `TenantContextOrchestrator` dispatch `TenantBootstrapped` or should `BootstrapperChain` dispatch it?**
   - What we know: `BootstrapperChain.boot()` runs all bootstrappers and knows which FQCNs ran.
   - What's unclear: Whether the orchestrator or the chain owns the final event dispatch. Both options are valid; the chain is closer to the data.
   - Recommendation: `BootstrapperChain.boot()` dispatches `TenantBootstrapped` after all bootstrappers complete — it owns the bootstrapper list and can provide the accurate `bootstrappers[]` payload. The orchestrator dispatches `TenantResolved` (before boot) only.

2. **Does `BootstrapperChain` need `EventDispatcherInterface` in Phase 1 (no bootstrappers yet)?**
   - What we know: Phase 1 ships `BootstrapperChain` as a shell with no concrete bootstrappers.
   - What's unclear: Whether to inject `EventDispatcherInterface` into `BootstrapperChain` now or defer until a concrete bootstrapper is added.
   - Recommendation: Inject `EventDispatcherInterface` into `BootstrapperChain` now and dispatch `TenantBootstrapped` in `boot()` (even if the bootstrappers list is empty). CORE-02 requires the event fires — Phase 1 must dispatch it even with zero bootstrappers.

3. **Where does the landlord EntityManager injection for `Tenant` entity lookup live?**
   - What we know: Phase 1 ships the `Tenant` entity and Doctrine mapping. Actual lookup (by slug/domain) is Phase 2 (resolvers).
   - What's unclear: Whether Phase 1 should register a `TenantRepository` service pointing at the landlord EM, or defer that to Phase 2.
   - Recommendation: Register `TenantRepository` in Phase 1 as part of the entity/landlord infrastructure. Phase 2 resolvers will inject it — better to have it ready than to split the entity/repository pair across phases.

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11 (`phpunit/phpunit:^11.0`) |
| Config file | `phpunit.xml.dist` — Wave 0 creates this |
| Quick run command | `vendor/bin/phpunit --testsuite unit --stop-on-failure` |
| Full suite command | `vendor/bin/phpunit` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| CORE-01 | `TenantContext::setTenant()` stores a tenant; `getTenant()` returns it; zero constructor deps | unit | `vendor/bin/phpunit tests/Unit/Context/TenantContextTest.php -x` | Wave 0 |
| CORE-01 | `TenantContext::hasTenant()` returns false before set, true after, false after clear | unit | `vendor/bin/phpunit tests/Unit/Context/TenantContextTest.php -x` | Wave 0 |
| CORE-01 | Container compiles with no `ServiceCircularReferenceException` | integration | `vendor/bin/phpunit tests/Integration/ContainerCompilationTest.php -x` | Wave 0 |
| CORE-02 | `TenantResolved` dispatched with correct tenant/request/resolvedBy | unit | `vendor/bin/phpunit tests/Unit/EventListener/TenantContextOrchestratorTest.php -x` | Wave 0 |
| CORE-02 | `TenantBootstrapped` dispatched with correct tenant/bootstrappers array | unit | `vendor/bin/phpunit tests/Unit/Bootstrapper/BootstrapperChainTest.php -x` | Wave 0 |
| CORE-02 | `TenantContextCleared` dispatched on kernel.terminate | unit | `vendor/bin/phpunit tests/Unit/EventListener/TenantContextOrchestratorTest.php -x` | Wave 0 |
| CORE-03 | `BootstrapperChainPass` collects tagged services in priority order | unit | `vendor/bin/phpunit tests/Unit/DependencyInjection/Compiler/BootstrapperChainPassTest.php -x` | Wave 0 |
| CORE-03 | Service implementing `TenantBootstrapperInterface` is auto-tagged without manual config | integration | `vendor/bin/phpunit tests/Integration/AutoconfigurationTest.php -x` | Wave 0 |
| CORE-04 | `Tenant` entity persists to DB with all fields; queries return it | integration | `vendor/bin/phpunit tests/Integration/Entity/TenantEntityTest.php -x` | Wave 0 |
| CORE-04 | `Tenant` with slug PK — no auto-generated ID | unit | `vendor/bin/phpunit tests/Unit/Entity/TenantTest.php -x` | Wave 0 |
| CORE-05 | `TenantContextOrchestrator::PRIORITY === 20` | unit | `vendor/bin/phpunit tests/Unit/EventListener/TenantContextOrchestratorTest.php -x` | Wave 0 |
| CORE-05 | Listener registered at priority 20 on `kernel.request` | integration | `vendor/bin/phpunit tests/Integration/ListenerPriorityTest.php -x` | Wave 0 |

### Sampling Rate

- **Per task commit:** `vendor/bin/phpunit --testsuite unit --stop-on-failure`
- **Per wave merge:** `vendor/bin/phpunit`
- **Phase gate:** Full suite green before `/gsd:verify-work`

### Wave 0 Gaps

All test infrastructure is missing — this is a greenfield project. Wave 0 must create:

- [ ] `phpunit.xml.dist` — test suites: `unit` (`tests/Unit/`) and `integration` (`tests/Integration/`)
- [ ] `tests/Unit/Context/TenantContextTest.php` — covers CORE-01
- [ ] `tests/Unit/EventListener/TenantContextOrchestratorTest.php` — covers CORE-02, CORE-05
- [ ] `tests/Unit/Bootstrapper/BootstrapperChainTest.php` — covers CORE-02, CORE-03
- [ ] `tests/Unit/Entity/TenantTest.php` — covers CORE-04 (unit: field defaults, interface compliance)
- [ ] `tests/Unit/DependencyInjection/Compiler/BootstrapperChainPassTest.php` — covers CORE-03
- [ ] `tests/Integration/ContainerCompilationTest.php` — covers CORE-01 (no circular deps), CORE-03
- [ ] `tests/Integration/AutoconfigurationTest.php` — covers CORE-03 (auto-tag)
- [ ] `tests/Integration/Entity/TenantEntityTest.php` — covers CORE-04 (DB persistence)
- [ ] `tests/Integration/ListenerPriorityTest.php` — covers CORE-05
- [ ] `tests/bootstrap.php` — shared test bootstrap (autoload, env setup)
- [ ] Framework install: `composer require --dev phpunit/phpunit:^11.0 symfony/phpunit-bridge:^6.4\|^7.4`
- [ ] `SymfonyTest/symfony-bundle-test` for integration TestKernel: `composer require --dev symfony-test/symfony-bundle-test:^1.0`

---

## Sources

### Primary (HIGH confidence)
- [Symfony 6.1 AbstractBundle blog post](https://symfony.com/blog/new-in-symfony-6-1-simpler-bundle-extension-and-configuration) — `configure()`, `loadExtension()`, `prependExtension()` signatures
- [Symfony Bundle Extension docs](https://symfony.com/doc/current/bundles/extension.html) — `registerForAutoconfiguration()` in `loadExtension()`
- [Symfony Compiler Passes docs](https://symfony.com/doc/current/service_container/compiler_passes.html) — `CompilerPassInterface::process()`, `findTaggedServiceIds()`
- [Symfony Service Tags docs](https://symfony.com/doc/current/service_container/tags.html) — `PriorityTaggedServiceTrait`, `#[AutoconfigureTag]`, `#[AsTaggedItem]`
- [Symfony Event Dispatcher docs](https://symfony.com/doc/current/event_dispatcher.html) — `#[AsEventListener]` full signature, `kernel.request`/`kernel.terminate` patterns
- [Symfony Built-in Events reference](https://symfony.com/doc/current/reference/events.html) — priorities: Router 1024, Router (legacy) 32, Security 8, Locale 16
- [Symfony Prepend Extension docs](https://symfony.com/doc/current/bundles/prepend_extension.html) — `prependExtensionConfig()` for Doctrine mapping auto-registration
- [Doctrine Configuration Reference](https://symfony.com/doc/current/reference/configuration/doctrine.html) — named entity managers, attribute mapping config
- [symfony/dependency-injection PriorityTaggedServiceTrait source (7.2)](https://github.com/symfony/dependency-injection/blob/7.2/Compiler/PriorityTaggedServiceTrait.php) — confirmed method signature and return type

### Secondary (MEDIUM confidence)
- [SymfonyCasts Bundle Entity Mapping](https://symfonycasts.com/screencast/bundle-development/bundle-entity-mapping) — XML vs attribute mapping in bundles; `DoctrineOrmMappingsPass` pattern
- [Symfony Best Practices for Reusable Bundles](https://symfony.com/doc/current/bundles/best_practices.html) — service naming conventions, no autowiring in bundles without explicit config

### Tertiary (LOW confidence)
- None — all critical claims verified with official sources above.

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — verified against Packagist and Symfony release docs (see STACK.md)
- Architecture: HIGH — all patterns verified against official Symfony 6.4/7.x documentation
- Compiler pass pattern: HIGH — PriorityTaggedServiceTrait source confirmed on github.com/symfony/dependency-injection 7.2
- Pitfalls: HIGH — Pitfall 8 (circular deps) and Pitfall 9 (kernel.request priority) are documented in PITFALLS.md with official sources

**Research date:** 2026-03-17
**Valid until:** 2026-04-17 (stable Symfony core APIs; `AbstractBundle` has been stable since 6.1)
