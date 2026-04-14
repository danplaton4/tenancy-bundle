# DI Compilation Pipeline

The Tenancy Bundle wires all services at **container compile time** using three compiler passes and two extension hooks (`loadExtension`, `prependExtension`). No manual DI configuration is required from users — everything is automatic.

## Overview

```
Container Build
    │
    ├── prependExtension()       ← prepend Doctrine entity mappings + filter config
    ├── loadExtension()          ← register base services + conditional services
    │
    └── Compiler Passes (BeforeOptimization phase)
            ├── BootstrapperChainPass   (collects tenancy.bootstrapper tags)
            ├── ResolverChainPass       (collects tenancy.resolver tags)
            └── MessengerMiddlewarePass (priority 1, before MessengerPass)
```

All three compiler passes are registered in `TenancyBundle::build()`:

```php
public function build(ContainerBuilder $container): void
{
    parent::build($container);
    $container->addCompilerPass(new BootstrapperChainPass());
    $container->addCompilerPass(new ResolverChainPass());
    if (interface_exists(MessageBusInterface::class)) {
        $container->addCompilerPass(
            new MessengerMiddlewarePass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            1  // priority — before MessengerPass at 0
        );
    }
}
```

---

## BootstrapperChainPass

**Tag:** `tenancy.bootstrapper`

`BootstrapperChainPass` collects all services tagged `tenancy.bootstrapper`, sorts them by tag priority (descending), and registers them with `BootstrapperChain` via `addMethodCall`:

```php
public function process(ContainerBuilder $container): void
{
    // Remove DoctrineBootstrapper if Doctrine ORM is not installed
    if ($container->hasDefinition('tenancy.doctrine_bootstrapper')
        && !$container->has('doctrine.orm.entity_manager')) {
        $container->removeDefinition('tenancy.doctrine_bootstrapper');
    }

    $definition = $container->findDefinition(BootstrapperChain::class);
    $bootstrappers = $this->findAndSortTaggedServices('tenancy.bootstrapper', $container);

    foreach ($bootstrappers as $bootstrapper) {
        $definition->addMethodCall('addBootstrapper', [$bootstrapper]);
    }
}
```

The result is that `BootstrapperChain::$bootstrappers` is populated in priority order at compile time. At runtime, `boot()` just iterates the array — no tag lookups or reflection.

**Auto-tagging** is configured in `loadExtension()`:

```php
$builder->registerForAutoconfiguration(TenantBootstrapperInterface::class)
    ->addTag('tenancy.bootstrapper');
```

Any service implementing `TenantBootstrapperInterface` is automatically tagged `tenancy.bootstrapper` with no additional config. Users can override the priority via:

```yaml
# config/services.yaml
App\Bootstrapper\MyBootstrapper:
    tags:
        - { name: tenancy.bootstrapper, priority: 50 }
```

---

## ResolverChainPass

**Tag:** `tenancy.resolver`

`ResolverChainPass` mirrors `BootstrapperChainPass` for resolvers:

```php
// src/DependencyInjection/Compiler/ResolverChainPass.php (simplified)
public function process(ContainerBuilder $container): void
{
    $definition = $container->findDefinition(ResolverChain::class);

    // Build allowed FQCN set from config short-names (e.g. 'host', 'header')
    $allowedFqcns = null;
    if ($container->hasParameter('tenancy.resolvers')) {
        $allowedFqcns = [];
        foreach ($container->getParameter('tenancy.resolvers') as $name) {
            if (isset(self::BUILT_IN_RESOLVER_MAP[$name])) {
                $allowedFqcns[] = self::BUILT_IN_RESOLVER_MAP[$name];
            }
        }
    }

    $resolvers = $this->findAndSortTaggedServices('tenancy.resolver', $container);

    foreach ($resolvers as $resolver) {
        $serviceId = (string) $resolver;
        if (null !== $allowedFqcns) {
            $fqcn = $container->findDefinition($serviceId)->getClass() ?? $serviceId;
            // Built-in resolvers must be in the allowed list
            if (in_array($fqcn, self::BUILT_IN_RESOLVER_MAP, true)
                && !in_array($fqcn, $allowedFqcns, true)) {
                continue; // Skip — not in config
            }
            // Custom resolvers (not in built-in map) always pass through
        }
        $definition->addMethodCall('addResolver', [$resolver]);
    }
}
```

The pass reads the `tenancy.resolvers` config parameter to determine which built-in resolvers
are active. Built-in resolvers not listed in the config are skipped. Custom resolvers (any class
implementing `TenantResolverInterface` that is not in the `BUILT_IN_RESOLVER_MAP`) always pass
through the filter — they cannot be accidentally disabled by configuration. If no
`tenancy.resolvers` parameter exists, all resolvers are added unconditionally (backward
compatible).

Built-in resolver priorities (defined in `config/services.php`):

| Resolver | Priority | Source |
|----------|----------|--------|
| `HostResolver` | 30 | `tenancy.resolver` tag |
| `HeaderResolver` | 20 | `tenancy.resolver` tag |
| `QueryParamResolver` | 10 | `tenancy.resolver` tag |
| `ConsoleResolver` | 5 | `tenancy.resolver` tag |

Higher priority = runs first. `ResolverChain::resolve()` returns the first non-null result.

**Auto-tagging** for user-defined resolvers:

```php
$builder->registerForAutoconfiguration(TenantResolverInterface::class)
    ->addTag('tenancy.resolver');
```

---

## MessengerMiddlewarePass

**Priority:** 1 (before Symfony's `MessengerPass` at priority 0)

**Guard:** `interface_exists(MessageBusInterface::class)` — entire pass skipped when Messenger is not installed.

`MessengerMiddlewarePass` prepends `TenantSendingMiddleware` and `TenantWorkerMiddleware` to every Messenger bus's middleware stack.

### Why direct parameter modification?

Symfony's `FrameworkExtension` stores the merged middleware config in container parameters named `{busId}.middleware` (e.g. `messenger.bus.default.middleware`). `MessengerPass` then reads these parameters to build the actual service references.

The middleware array uses `performNoDeepMerging()` in the Symfony Configuration tree, which means `prependExtensionConfig()` would **overwrite** the middleware array instead of prepending to it. The solution is to read and rewrite the parameter directly:

```php
public function process(ContainerBuilder $container): void
{
    $tenancyMiddleware = [
        ['id' => 'tenancy.messenger.sending_middleware'],
        ['id' => 'tenancy.messenger.worker_middleware'],
    ];

    $busIds = array_keys($container->findTaggedServiceIds('messenger.bus'));

    foreach ($busIds as $busId) {
        $paramName = $busId . '.middleware';

        if ($container->hasParameter($paramName)) {
            $existing = $container->getParameter($paramName);
            $container->setParameter($paramName, array_merge($tenancyMiddleware, $existing));
        }
    }
}
```

### Why priority 1?

`MessengerPass` runs at priority 0 and **consumes** the `{busId}.middleware` parameter (builds service references from it, then removes the parameter). If `MessengerMiddlewarePass` ran at priority 0 or lower, the parameter would already be gone. Running at priority 1 guarantees the pass sees the parameter before `MessengerPass` consumes it.

The pass includes a fallback path that directly modifies the bus `IteratorArgument` in case `MessengerPass` ran first (edge case for non-standard container configurations).

---

## prependExtension() — Doctrine Entity Mappings

`prependExtension()` runs before `loadExtension()` and prepends Doctrine configuration. The target path depends on whether `database.enabled` is set:

### database.enabled: false (default — shared-DB or no Doctrine)

```php
$builder->prependExtensionConfig('doctrine', [
    'orm' => [
        'mappings' => $mapping,  // maps to default entity manager
    ],
]);
```

The bundle's `Tenant` entity is registered in the default ORM mapping.

### database.enabled: true (database-per-tenant with dual-EM)

```php
$builder->prependExtensionConfig('doctrine', [
    'orm' => [
        'entity_managers' => [
            'landlord' => [
                'mappings' => $mapping,  // maps to landlord entity manager only
            ],
        ],
    ],
]);
```

The `Tenant` entity is registered only in the `landlord` entity manager, not the default tenant-switching EM.

### driver: shared_db

Additionally, the `tenancy_aware` SQL filter is registered:

```php
$builder->prependExtensionConfig('doctrine', [
    'orm' => [
        'filters' => [
            'tenancy_aware' => [
                'class' => TenantAwareFilter::class,
                'enabled' => true,
            ],
        ],
    ],
]);
```

This uses Doctrine's native filter mechanism so the filter participates in Doctrine's query cache.

---

## loadExtension() — Conditional Service Registration

`loadExtension()` imports the base service definitions and registers conditional services based on configuration:

### Always Registered

| Service | Class | Purpose |
|---------|-------|---------|
| `tenancy.context` | `TenantContext` | Stateful tenant holder, injected everywhere |
| `tenancy.bootstrapper_chain` | `BootstrapperChain` | Runs bootstrappers in priority order |
| `tenancy.resolver_chain` | `ResolverChain` | Runs resolvers in priority order |
| `TenantContextOrchestrator` | — | Kernel event listener (autoconfigured) |
| `EntityManagerResetListener` | — | Resets EM on `TenantContextCleared` |
| `tenancy.command.init` | `TenantInitCommand` | Scaffolds `config/packages/tenancy.yaml` |

### When database.enabled: true

| Service | Purpose |
|---------|---------|
| `tenancy.database_switch_bootstrapper` | Calls `TenantConnection::switchTenant()` on boot |
| DoctrineTenantProvider rewired | Reads from `doctrine.orm.landlord_entity_manager` |
| `tenancy.command.migrate` | `tenancy:migrate` command (when doctrine/migrations present) |

### When driver: shared_db

| Service | Purpose |
|---------|---------|
| `tenancy.shared_driver` | Injects `TenantContext` into `TenantAwareFilter` on boot |

### Mutual Exclusion Guard

The bundle's `configure()` validates that `shared_db` and `database.enabled: true` cannot be combined:

```php
->validate()
    ->ifTrue(fn(array $v) => $v['driver'] === 'shared_db' && $v['database']['enabled'] === true)
    ->thenInvalid('tenancy.driver: shared_db cannot be combined with tenancy.database.enabled: true.')
->end()
```

---

## Service Dependency Graph

```
TenantContextOrchestrator
    ├── TenantContext
    ├── BootstrapperChain
    │       ├── DatabaseSwitchBootstrapper → TenantConnectionInterface
    │       ├── SharedDriver → EntityManagerInterface + TenantContext
    │       ├── DoctrineBootstrapper → EntityManagerInterface
    │       └── TenantAwareCacheAdapter → CacheInterface
    ├── EventDispatcher
    └── ResolverChain
            ├── HostResolver → TenantProviderInterface
            ├── HeaderResolver → TenantProviderInterface
            └── ConsoleResolver → TenantProviderInterface

TenantWorkerMiddleware (Messenger)
    ├── TenantContext
    ├── BootstrapperChain
    ├── TenantProviderInterface
    └── EventDispatcher
```
