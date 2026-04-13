# Architecture Overview

A contributor's map of the bundle: how it is structured, what happens during a request,
and where each responsibility lives.

## Event-Driven Bootstrapper Model

When a tenant is identified, a chain of bootstrappers reconfigure Symfony services for
that tenant. This model is borrowed from `stancl/tenancy` for Laravel, adapted to Symfony
idioms: bundles, compiler passes, kernel events, and Symfony service tags.

The design principle: **zero boilerplate for the application developer.** No manual service
decoration, no conditional checks scattered through business code. The bundle handles
everything at the framework boundary.

## Full Request Lifecycle

```
Request arrives
    |
Symfony Router (kernel.request priority 32)
    |
TenantContextOrchestrator (kernel.request priority 20)
    |
    +-- ResolverChain.resolve(request)
    |       |
    |       +-- HostResolver      (priority 30) — subdomain / custom domain
    |       +-- HeaderResolver    (priority 20) — X-Tenant-ID header
    |       +-- QueryParamResolver (priority 10) — ?_tenant= query param
    |       +-- ConsoleResolver   (priority 10) — --tenant= CLI flag
    |
    +-- TenantContext.setTenant(tenant)
    |
    +-- BootstrapperChain.boot(tenant)
    |       |
    |       +-- DatabaseSwitchBootstrapper  — swaps DBAL connection to tenant DB
    |       +-- DoctrineBootstrapper        — clears EntityManager identity map
    |       +-- SharedDriver                — injects TenantContext into TenantAwareFilter
    |       +-- (any custom bootstrapper)
    |
    +-- dispatch(TenantBootstrapped)
    |
Controller / Application Code runs in full tenant context
    |
kernel.terminate
    |
    +-- BootstrapperChain.clear()  [REVERSE order of boot()]
    |       |
    |       +-- (custom bootstrapper).clear()
    |       +-- SharedDriver.clear()
    |       +-- DoctrineBootstrapper.clear()
    |       +-- DatabaseSwitchBootstrapper.clear()
    |
    +-- TenantContext.clear()
    |
    +-- dispatch(TenantContextCleared)
```

!!! note "Priority ordering"
    `TenantContextOrchestrator` runs at priority 20 — after the Router (priority 32)
    but before the Security firewall (priority 8). This ensures the tenant is identified
    before any security decision is made, which allows tenant-specific security
    configurations to function correctly.

## Key Design Principles

**TenantContext is a zero-dependency value holder.** It has no constructor parameters
and no circular dependencies. Any service that needs to know the current tenant injects
`TenantContext` directly.

**Bootstrappers are registered via DI tags.** Any class implementing
`TenantBootstrapperInterface` is auto-tagged as `tenancy.bootstrapper` via
`registerForAutoconfiguration` in `TenancyBundle::loadExtension()`. No manual wiring
needed.

**`clear()` runs in reverse order of `boot()`.** `BootstrapperChain::clear()` uses
`array_reverse($this->bootstrappers)` to guarantee that the last bootstrapper to `boot()`
is the first to `clear()`. This mirrors LIFO cleanup in stack-like architectures.

**Doctrine dependencies are optional.** Every Doctrine and Messenger import in `src/`
is guarded by `class_exists()` or `interface_exists()`. The bundle works without any ORM
installed. See [Coding Standards](coding-standards.md) for the guard pattern.

**Strict mode is ON by default.** `strict_mode: true` means the bundle throws
`TenantMissingException` when a `#[TenantAware]` entity is queried with no active tenant.
This is a security default — a data leak across tenants is a security incident.

## Namespace Map

The `src/` directory contains 40 files across 18 namespaces:

| Namespace | Contents |
|-----------|----------|
| `Tenancy\Bundle` | `TenancyBundle`, `TenantInterface` — bundle entry point and core contract |
| `Tenancy\Bundle\Attribute` | `TenantAware` — PHP attribute that marks Doctrine entities for tenant scoping |
| `Tenancy\Bundle\Bootstrapper` | `BootstrapperChain`, `DatabaseSwitchBootstrapper`, `DoctrineBootstrapper`, `TenantBootstrapperInterface` |
| `Tenancy\Bundle\Cache` | `TenantAwareCacheAdapter` — decorates `cache.app` with per-tenant sub-namespace |
| `Tenancy\Bundle\Command` | `TenantMigrateCommand`, `TenantRunCommand` — CLI commands for tenant operations |
| `Tenancy\Bundle\Context` | `TenantContext` — zero-dependency value holder for the active tenant |
| `Tenancy\Bundle\DBAL` | `TenantConnection`, `TenantConnectionInterface` — DBAL `wrapperClass` that switches connection params at runtime |
| `Tenancy\Bundle\DependencyInjection\Compiler` | `BootstrapperChainPass`, `ResolverChainPass`, `MessengerMiddlewarePass` — the three compiler passes |
| `Tenancy\Bundle\Driver` | `SharedDriver`, `TenantDriverInterface` — isolation driver abstraction |
| `Tenancy\Bundle\Entity` | `Tenant` — the landlord-side Doctrine entity |
| `Tenancy\Bundle\Event` | `TenantResolved`, `TenantBootstrapped`, `TenantContextCleared` — PSR-14 events |
| `Tenancy\Bundle\EventListener` | `TenantContextOrchestrator`, `EntityManagerResetListener` — kernel event handlers |
| `Tenancy\Bundle\Exception` | `TenantNotFoundException`, `TenantInactiveException`, `TenantMissingException` |
| `Tenancy\Bundle\Filter` | `TenantAwareFilter` — Doctrine SQL filter for shared-DB mode |
| `Tenancy\Bundle\Messenger` | `TenantStamp`, `TenantSendingMiddleware`, `TenantWorkerMiddleware` — context preservation across async boundaries |
| `Tenancy\Bundle\Provider` | `DoctrineTenantProvider`, `TenantProviderInterface` — tenant lookup |
| `Tenancy\Bundle\Resolver` | `HostResolver`, `HeaderResolver`, `QueryParamResolver`, `ConsoleResolver`, `ResolverChain`, `TenantResolverInterface` |
| `Tenancy\Bundle\Testing` | `InteractsWithTenancy` — PHPUnit trait for clean per-test tenant context |

## Compiler Passes

Three compiler passes wire the bundle at container compilation time:

**`BootstrapperChainPass`** — Collects all services tagged `tenancy.bootstrapper` (using
`PriorityTaggedServiceTrait`) and calls `BootstrapperChain::addBootstrapper()` for each.
Also removes `tenancy.doctrine_bootstrapper` when no Doctrine EntityManager is present.

**`ResolverChainPass`** — Collects all services tagged `tenancy.resolver`, sorts by
priority (highest first using `PriorityTaggedServiceTrait`), and calls
`ResolverChain::addResolver()` for each.

**`MessengerMiddlewarePass`** — Prepends `TenantSendingMiddleware` and
`TenantWorkerMiddleware` to every configured Messenger bus. Guarded by
`interface_exists(MessageBusInterface::class)` so it does nothing when Messenger is not
installed. Registered at priority 1 to run before Symfony's own `MessengerPass`
(priority 0).

## Two Isolation Drivers

The bundle ships two optional isolation strategies. Both implement
`TenantBootstrapperInterface` and `TenantDriverInterface`:

**`database_per_tenant`** (default) — `DatabaseSwitchBootstrapper` calls
`TenantConnection::switchTenant()` on `boot()` and `TenantConnection::reset()` on
`clear()`. Each tenant has its own SQLite / MySQL / PostgreSQL database. Requires
`tenancy.database.enabled: true` in the bundle config.

**`shared_db`** — `SharedDriver` injects the active `TenantContext` into the
`TenantAwareFilter` Doctrine SQL filter on `boot()`. Entities annotated with
`#[TenantAware]` automatically have a `tenant_id = :tenantId` WHERE clause appended to all
queries. Cannot be combined with `database.enabled: true`.

## Deep Dives

For more detail on specific subsystems:

- [Architecture Reference](../architecture/index.md) — subsystem-by-subsystem documentation
- [Event Lifecycle](../architecture/event-lifecycle.md) — full event flow with sequence diagrams
