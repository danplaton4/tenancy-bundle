# Architecture Research

**Domain:** Symfony multi-tenancy bundle (Context Orchestrator)
**Researched:** 2026-03-17
**Confidence:** HIGH

## Standard Architecture

### System Overview

```
┌──────────────────────────────────────────────────────────────────────────┐
│                         HTTP / CLI / Messenger                           │
├──────────────────────────────────────────────────────────────────────────┤
│                          Resolution Layer                                │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌─────────────┐  │
│  │HostResolver  │  │HeaderResolver│  │QueryResolver │  │ConsoleResolv│  │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘  └──────┬──────┘  │
│         └─────────────────┴──────────────────┴─────────────────┘         │
│                                     │                                    │
│                          ┌──────────▼──────────┐                         │
│                          │  ResolverChain       │  (priority-ordered)     │
│                          └──────────┬──────────┘                         │
├─────────────────────────────────────┼────────────────────────────────────┤
│                          Context Layer                                   │
│                          ┌──────────▼──────────┐                         │
│                          │  TenantContext       │  (request-scoped store) │
│                          └──────────┬──────────┘                         │
│                                     │  fires TenantResolved event        │
├─────────────────────────────────────┼────────────────────────────────────┤
│                          Bootstrapping Layer                             │
│  ┌──────────────────────────────────▼──────────────────────────────────┐ │
│  │               BootstrapperChain  (ordered execution)                │ │
│  │  ┌───────────┐ ┌───────────┐ ┌───────────┐ ┌─────────────────────┐ │ │
│  │  │ Doctrine  │ │  Cache    │ │Filesystem │ │ Custom Bootstrappers│ │ │
│  │  │Bootstrapr │ │Bootstrapr │ │Bootstrapr │ │ (tagged, user-land) │ │ │
│  │  └─────┬─────┘ └─────┬─────┘ └─────┬─────┘ └──────────┬──────────┘ │ │
│  └────────┼─────────────┼─────────────┼───────────────────┼────────────┘ │
│           │             │             │                   │              │
├───────────┼─────────────┼─────────────┼───────────────────┼──────────────┤
│                          Driver Layer                                    │
│  ┌────────▼──────┐  ┌────▼────────┐   │                                  │
│  │  DatabaseDriver│  │SharedDriver │   │  (one active per request)        │
│  │  (DBAL swap)  │  │(SQL Filter) │   │                                  │
│  └───────────────┘  └─────────────┘   │                                  │
│           │                           │                                  │
├───────────┼───────────────────────────┼──────────────────────────────────┤
│                          Infrastructure Layer                            │
│  ┌────────▼──────┐  ┌────▼────────┐  ┌▼────────────┐  ┌───────────────┐  │
│  │ Landlord DB   │  │  Tenant DBs │  │ Cache Pool  │  │  Filesystem   │  │
│  │ (Tenant model)│  │  (N x DBAL) │  │ (prefixed)  │  │  (prefixed)   │  │
│  └───────────────┘  └─────────────┘  └─────────────┘  └───────────────┘  │
└──────────────────────────────────────────────────────────────────────────┘
```

### Component Responsibilities

| Component | Responsibility | Talks To |
|-----------|---------------|----------|
| `ResolverChain` | Iterates resolvers by priority, returns first non-null `TenantIdentifier` | Individual resolvers, `TenantContext` |
| `TenantResolverInterface` | Contract for a single resolver strategy (host, header, query, console) | `ResolverChain` |
| `TenantContext` | Request-scoped holder of the active `Tenant` object; source of truth for the lifecycle | `BootstrapperChain`, drivers, data collector |
| `TenantContextOrchestrator` | `kernel.request` listener; drives resolution → context set → event fire | `ResolverChain`, `TenantContext`, event dispatcher |
| `BootstrapperChain` | Listens to `TenantResolved`; iterates bootstrappers in registered order | `TenantBootstrapperInterface` implementations |
| `TenantBootstrapperInterface` | Contract: `bootstrap(Tenant): void` + `revert(): void` | Called by `BootstrapperChain` |
| `DoctrineBootstrapper` | Delegates to the active driver (database-per-tenant or shared) | `TenantDriverInterface` |
| `TenantDriverInterface` | Abstraction over isolation strategy | `DatabaseDriver`, `SharedDriver` |
| `DatabaseDriver` | Closes current DBAL connection, replaces parameters from tenant config, reconnects | `Doctrine\DBAL\Connection` (wrapperClass subclass) |
| `SharedDriver` | Enables Doctrine SQL Filter, injects `tenant_id` parameter | `EntityManagerInterface`, `TenantAwareFilter` |
| `TenantAwareFilter` | Doctrine SQL Filter; appends `WHERE tenant_id = :tenant_id` for `#[TenantAware]` entities | Doctrine ORM filter system |
| `TenantStamp` | Messenger stamp carrying `tenantId` string | `TenantMiddleware`, worker listener |
| `TenantMiddleware` | Sending: injects `TenantStamp`; Receiving: re-boots context from stamp before handler | `TenantContext`, `BootstrapperChain` |
| `TenantDataCollector` | Collects active tenant, driver, connection info during request for the profiler panel | `TenantContext`, profiler |
| `PHPStan TenantAwareRule` | Detects `#[TenantAware]` entities queried without active context | PHPStan AST, `Scope` |
| `InteractsWithTenancy` | PHPUnit trait for `WebTestCase`; provides `initializeTenant()` / `clearTenant()` | `TenantContext`, test kernel |
| `Landlord EntityManager` | Named Doctrine entity manager for the central `Tenant` registry DB | Doctrine ORM, landlord DBAL connection |
| `Tenant EntityManager` | Named Doctrine entity manager for the current tenant's DB (runtime-switched) | Doctrine ORM, tenant DBAL connection |

---

## Recommended Project Structure

```
src/
├── SymfonyTenancyBundle.php         # AbstractBundle; build() registers compiler passes
│                                     # registerForAutoconfiguration() auto-tags resolvers
│                                     # and bootstrappers
│
├── DependencyInjection/
│   ├── Configuration.php            # TreeBuilder: strict_mode, driver, resolver priorities
│   ├── Compiler/
│   │   ├── ResolverChainPass.php    # Collects tenancy.resolver tagged services, injects into chain
│   │   └── BootstrapperChainPass.php# Collects tenancy.bootstrapper tagged services, sorted by priority
│   └── TenancyExtension.php        # (kept for BC; AbstractBundle's loadExtension() preferred)
│
├── Context/
│   ├── TenantContext.php            # Holds active Tenant|null; fires domain events
│   └── TenantContextClearer.php    # kernel.terminate listener; calls revert() on all bootstrappers
│
├── Resolver/
│   ├── TenantResolverInterface.php  # resolve(Request): ?TenantIdentifier
│   ├── ResolverChain.php            # Iterates priority-ordered resolvers
│   ├── HostResolver.php
│   ├── HeaderResolver.php
│   ├── QueryParamResolver.php
│   └── ConsoleResolver.php
│
├── Bootstrapper/
│   ├── TenantBootstrapperInterface.php  # bootstrap(Tenant): void; revert(): void
│   ├── BootstrapperChain.php            # Ordered executor; stores refs for revert
│   ├── DoctrineBootstrapper.php         # Delegates to active TenantDriverInterface
│   ├── CacheBootstrapper.php            # Prefixes cache pool tag
│   └── FilesystemBootstrapper.php       # Decorates Flysystem root path
│
├── Driver/
│   ├── TenantDriverInterface.php    # boot(Tenant): void; shutdown(): void
│   ├── DatabaseDriver.php           # DBAL connection swap (database-per-tenant)
│   └── SharedDriver.php             # Doctrine SQL Filter (shared-database)
│
├── Doctrine/
│   ├── Filter/
│   │   └── TenantAwareFilter.php    # Extends SQLFilter; appends tenant_id constraint
│   ├── Attribute/
│   │   └── TenantAware.php          # PHP 8 attribute for entity-level annotation
│   └── DBAL/
│       └── TenantConnection.php     # Extends Doctrine\DBAL\Connection; exposes
│                                     # switchTenant(array $params): void
│
├── Entity/
│   └── Landlord/
│       └── Tenant.php               # id, slug, domain, dbName, dbHost, dbUser, dbPassword
│
├── Event/
│   ├── TenantResolved.php
│   ├── TenantBootstrapped.php
│   └── TenantContextCleared.php
│
├── Messenger/
│   ├── TenantStamp.php              # implements StampInterface; holds tenantId: string
│   └── TenantMiddleware.php         # implements MiddlewareInterface; send + receive logic
│
├── Command/
│   ├── TenancyMigrateCommand.php    # tenancy:migrate - runs migrations for all tenants
│   └── TenancyRunCommand.php        # tenancy:run {id} {command}
│
├── Profiler/
│   ├── TenantDataCollector.php      # extends AbstractDataCollector
│   └── Resources/
│       └── views/
│           └── Collector/
│               └── tenancy.html.twig # toolbar + panel template
│
├── PHPStan/
│   ├── Rules/
│   │   ├── TenantAwareEntityDirectQueryRule.php  # Detects query without active context
│   │   └── TenantContextAssertedRule.php         # Ensures tenant context before tenant EM usage
│   └── extension.neon               # phpstan.rules.rule service registrations
│
└── Test/
    └── InteractsWithTenancy.php     # PHPUnit trait: initializeTenant(), clearTenant()
                                      # dropTenantSchema(), recreateTenantSchema()
```

### Structure Rationale

- **`Context/`:** The single authoritative source of "who is the current tenant" — kept separate from resolution and bootstrapping to avoid circular dependencies.
- **`Resolver/`:** Pure functions; each resolver only reads the request and returns an identifier. No side effects. Easy to unit test in isolation.
- **`Bootstrapper/`:** Side-effect classes that mutate infrastructure state. Must implement `revert()` to guarantee cleanup on `kernel.terminate`.
- **`Driver/`:** The isolation strategy is injected into `DoctrineBootstrapper`, making the database strategy swappable without touching the bootstrapper chain.
- **`Doctrine/`:** Groups everything Doctrine-specific (filter, attribute, DBAL extension) to make the optional dependency boundary obvious. `require` vs `require-dev` in `composer.json` maps to this.
- **`Entity/Landlord/`:** Landlord entities live under a distinct namespace so the landlord entity manager can be mapped exclusively to this namespace without overlap.
- **`PHPStan/`:** Ships as a separate `phpstan/extension-installer`-compatible neon file so users can opt in without mandatory PHPStan setup.
- **`Test/`:** Trait-based so it does not impose a specific base class. Works with `WebTestCase` and plain `KernelTestCase`.

---

## Architectural Patterns

### Pattern 1: Priority-Ordered Resolver Chain

**What:** Multiple resolver classes tagged with `tenancy.resolver` and a `priority` attribute. A `ResolverChain` service iterates them in descending priority order and returns on the first non-null result.

**When to use:** Any time the bundle needs to support multiple, independently-configurable resolution strategies.

**Trade-offs:** Adds one extra service lookup per request; negligible cost. Enables user-land resolvers without modifying bundle internals.

**Implementation sketch:**
```php
// In SymfonyTenancyBundle::build()
$container->registerForAutoconfiguration(TenantResolverInterface::class)
    ->addTag('tenancy.resolver');

// ResolverChainPass collects and sorts by priority attribute
$resolvers = $container->findTaggedServiceIds('tenancy.resolver');
usort($resolvers, fn($a, $b) => ($b[0]['priority'] ?? 0) <=> ($a[0]['priority'] ?? 0));
```

### Pattern 2: Event-Driven Bootstrapping (stancl/tenancy model)

**What:** `TenantContextOrchestrator` (kernel.request listener at priority 32) calls `ResolverChain`, stores the `Tenant` in `TenantContext`, then dispatches `TenantResolved`. `BootstrapperChain` listens to `TenantResolved` and calls `bootstrap(Tenant)` on every registered bootstrapper in order. On `kernel.terminate`, `TenantContextClearer` calls `revert()` on each bootstrapper in reverse order.

**When to use:** This is the core pattern — always active.

**Trade-offs:** Two event dispatches per request. The event-driven decoupling is the entire value proposition — user-land code can listen to `TenantResolved` without touching bundle internals.

### Pattern 3: DBAL wrapperClass for Runtime Connection Switching

**What:** A `TenantConnection` class extends `Doctrine\DBAL\Connection`. When `DatabaseDriver::boot(Tenant)` is called, it calls `$connection->switchTenant($params)` which: closes the existing connection (if open), replaces internal `_params` with tenant credentials, then lazily reconnects on the next query.

**When to use:** Database-per-tenant driver only.

**Trade-offs:** Extending DBAL internals is fragile across major DBAL versions (3.x → 4.x had breaking changes). Must pin DoctrineBundle version in `composer.json` and document the constraint. The alternative (multiple static entity managers) does not support runtime-discovered tenants and is not viable for SaaS with unbounded tenant counts.

**Configuration:**
```yaml
doctrine:
    dbal:
        connections:
            landlord:
                url: '%env(LANDLORD_DATABASE_URL)%'
            tenant:
                url: '%env(TENANT_DATABASE_URL)%'  # placeholder; overridden at runtime
                wrapper_class: TenancyBundle\Doctrine\DBAL\TenantConnection
```

### Pattern 4: Doctrine SQL Filter for Shared-Database Isolation

**What:** `TenantAwareFilter` extends `Doctrine\ORM\Query\Filter\SQLFilter` and overrides `addFilterConstraint()`. It checks whether the target entity implements `TenantAwareInterface` (or carries `#[TenantAware]` via reflection). If yes, it appends `{alias}.tenant_id = {tenantId}`. The `DoctrineBootstrapper`/`SharedDriver` enables the filter and sets its parameter on bootstrap; disables it on revert.

**When to use:** Shared-database driver.

**Trade-offs:** SQL filters run on every Doctrine query — no per-query opt-out unless the filter is temporarily disabled. In strict mode, `TenantAwareFilter` should throw `TenantMissingException` when `tenant_id` parameter is not set, rather than silently returning all rows.

### Pattern 5: Messenger Stamp for Async Context Propagation

**What:** `TenantMiddleware` wraps the message bus. On send, if `TenantContext` has an active tenant, it wraps the envelope with `TenantStamp(tenantId: $id)`. On receive (worker side), it reads `TenantStamp` from the envelope and re-boots tenant context before passing to the handler chain. After the handler returns, it calls the context clearer.

**When to use:** Every message dispatch when a tenant context is active.

**Trade-offs:** The stamp must be serializable by all configured Messenger serializers. `tenantId` is a plain string, which is always safe. Worker-side bootstrap re-runs the full bootstrapper chain — this is intentional and correct (the worker is a fresh process with no prior context).

### Pattern 6: Compiler Pass for Bootstrapper Registration

**What:** `BootstrapperChainPass` uses `$container->findTaggedServiceIds('tenancy.bootstrapper')` to collect all tagged services, sorts them by `priority` tag attribute, and injects them as method calls into `BootstrapperChain`. `registerForAutoconfiguration` auto-tags all `TenantBootstrapperInterface` implementations.

**When to use:** Compile time — always.

**Trade-offs:** Build order for the bootstrapper chain matters. `DoctrineBootstrapper` must run before any bootstrapper that depends on the correct database connection. Priority conventions:
- 100: `DoctrineBootstrapper` (connection/filter first)
- 50: `CacheBootstrapper`
- 40: `FilesystemBootstrapper`
- 0: User-land bootstrappers (default)

---

## Data Flow

### HTTP Request Lifecycle

```
HTTP Request arrives
        │
        ▼
kernel.request (priority 32)
  TenantContextOrchestrator::onRequest()
        │
        ▼
  ResolverChain::resolve(Request)
    ├─ HostResolver     → null (no subdomain match)
    ├─ HeaderResolver   → TenantIdentifier("acme") ← first match
    └─ (remaining skipped)
        │
        ▼
  LandlordRepository::findByIdentifier("acme")
        │  (queries landlord DB via landlord EntityManager)
        ▼
  TenantContext::set(Tenant $tenant)
        │
        ▼
  EventDispatcher::dispatch(TenantResolved $event)
        │
        ▼
  BootstrapperChain::onTenantResolved()
    ├─ DoctrineBootstrapper::bootstrap(Tenant)
    │     └─ DatabaseDriver::boot(Tenant)
    │           └─ TenantConnection::switchTenant([host, dbname, user, password])
    │               → closes existing connection
    │               → replaces _params
    │               → next Doctrine query reconnects to tenant DB
    ├─ CacheBootstrapper::bootstrap(Tenant)
    │     └─ prefixes cache.app pool key with "{tenantId}:"
    └─ FilesystemBootstrapper::bootstrap(Tenant)
          └─ decorates Flysystem adapter root: "uploads/" → "uploads/{tenantId}/"
        │
        ▼
  EventDispatcher::dispatch(TenantBootstrapped $event)
        │
        ▼
  Controller executes
  (all Doctrine queries → tenant DB, cache → tenant namespace, files → tenant path)
        │
        ▼
kernel.response
  TenantDataCollector::collect()
    └─ reads TenantContext → stores tenant ID, driver, DB name, boot duration
        │
        ▼
kernel.terminate
  TenantContextClearer::onTerminate()
    ├─ FilesystemBootstrapper::revert()
    ├─ CacheBootstrapper::revert()
    ├─ DoctrineBootstrapper::revert()
    │     └─ DatabaseDriver::shutdown()
    │           └─ TenantConnection::close()
    └─ TenantContext::clear()
        │
        ▼
  EventDispatcher::dispatch(TenantContextCleared $event)
```

### Messenger Worker Lifecycle

```
Message dispatched from HTTP request
        │
        ▼
TenantMiddleware::handle() [send side]
  reads TenantContext → wraps Envelope with TenantStamp("acme")
        │
        ▼
Transport serializes + publishes Envelope
        │ (async queue: Redis, SQS, AMQP, etc.)
        ▼
Worker receives Envelope
        │
        ▼
TenantMiddleware::handle() [receive side]
  reads TenantStamp("acme") from Envelope
        │
        ▼
  LandlordRepository::findById("acme")
        │
        ▼
  TenantContext::set(Tenant)
  BootstrapperChain runs (same as HTTP flow above)
        │
        ▼
Handler::__invoke(Message)
  (runs in fully initialized tenant context)
        │
        ▼
TenantContextClearer runs
  (same revert sequence as HTTP terminate)
```

### Shared-Database Filter Flow

```
Request arrives → tenant resolved → TenantContext::set(Tenant)
        │
        ▼
SharedDriver::boot(Tenant)
  $em->getFilters()->enable('tenancy_aware')
  $filter->setParameter('tenant_id', $tenant->getId())
        │
        ▼
Any Doctrine query on a #[TenantAware] entity
  TenantAwareFilter::addFilterConstraint()
    → appends: AND {alias}.tenant_id = '42'
        │
        ▼
strict_mode = true AND tenant_id param not set
  → throws TenantMissingException (before query executes)
```

### Landlord DB vs Tenant DB Relationship

```
┌─────────────────────────────────────┐
│          Landlord Database          │
│  (one, shared, always accessible)   │
│                                     │
│  tenants table:                     │
│    id | slug | domain | db_name     │
│    42 | acme | acme.. | db_acme     │
│    43 | beta | beta.. | db_beta     │
└──────────────────┬──────────────────┘
                   │  lookup at resolution time
                   ▼
       ┌───────────────────────┐
       │  TenantConnection     │
       │  (DBAL wrapperClass)  │
       └───────┬───────┬───────┘
               │       │
        ┌──────▼──┐  ┌──▼──────┐
        │ db_acme │  │ db_beta │  ← one active at a time
        └─────────┘  └─────────┘
```

The landlord connection is **always open** (it's the `default` or `landlord` named connection). The tenant connection is **switched per request** and is a separate named connection (`tenant`) backed by `TenantConnection`.

---

## Key Interface Contracts

### TenantResolverInterface

```php
interface TenantResolverInterface
{
    /**
     * Attempt to resolve a tenant identifier from the current request.
     * Return null if this resolver cannot identify a tenant.
     * Priority is set via the tenancy.resolver tag attribute.
     */
    public function resolve(Request $request): ?TenantIdentifier;
}
```

### TenantBootstrapperInterface

```php
interface TenantBootstrapperInterface
{
    /**
     * Configure application infrastructure for the given tenant.
     * Called after TenantResolved event, in priority order.
     */
    public function bootstrap(Tenant $tenant): void;

    /**
     * Restore infrastructure to pre-tenant state.
     * Called on kernel.terminate and after Messenger handler completion.
     * Called in reverse registration order.
     */
    public function revert(): void;
}
```

### TenantDriverInterface

```php
interface TenantDriverInterface
{
    /**
     * Activate the isolation strategy for the given tenant.
     * For DatabaseDriver: swap DBAL connection params.
     * For SharedDriver: enable SQL filter + set parameter.
     */
    public function boot(Tenant $tenant): void;

    /**
     * Deactivate the isolation strategy.
     * Must restore original state unconditionally (even on exception).
     */
    public function shutdown(): void;
}
```

### TenantContext (not an interface, a service)

```php
final class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void;  // sets + dispatches TenantResolved
    public function get(): Tenant;              // throws TenantMissingException if null
    public function getOrNull(): ?Tenant;
    public function isActive(): bool;
    public function clear(): void;              // nulls + dispatches TenantContextCleared
}
```

---

## Suggested Build Order

Dependencies flow upward — build lower layers first.

| Order | Component | Depends On | Why First |
|-------|-----------|------------|-----------|
| 1 | `Tenant` entity + `TenantContext` | Nothing | Everything else depends on these |
| 2 | `TenantResolverInterface` + resolver implementations | `Tenant`, `Request` | Chain needs implementations to test |
| 3 | `ResolverChain` + `ResolverChainPass` | Resolvers | Needs at least one resolver |
| 4 | `TenantBootstrapperInterface` + `BootstrapperChain` + `BootstrapperChainPass` | `TenantContext` | Core lifecycle wiring |
| 5 | `TenantDriverInterface` + `DatabaseDriver` + `TenantConnection` | `TenantContext`, DBAL | Requires live DB to test |
| 6 | `DoctrineBootstrapper` | `TenantDriverInterface` | Wraps driver behind bootstrapper contract |
| 7 | `TenantAwareFilter` + `#[TenantAware]` attribute + `SharedDriver` | Doctrine ORM | Independent of DatabaseDriver |
| 8 | `TenantContextOrchestrator` (kernel.request listener) | Resolvers, `TenantContext`, EventDispatcher | Wires HTTP into context |
| 9 | `TenantContextClearer` (kernel.terminate listener) | `BootstrapperChain`, `TenantContext` | Needs bootstrappers to revert |
| 10 | `CacheBootstrapper` + `FilesystemBootstrapper` | `TenantBootstrapperInterface` | Can be built independently of DB driver |
| 11 | `TenantStamp` + `TenantMiddleware` | `TenantContext`, `BootstrapperChain` | Needs full lifecycle to test |
| 12 | `TenantDataCollector` + profiler template | `TenantContext` | Reads from context only |
| 13 | `InteractsWithTenancy` trait | Full lifecycle + test kernel | Tests all layers |
| 14 | `PHPStan` rules + extension.neon | PHPStan, `#[TenantAware]` | Static analysis layer over completed domain |
| 15 | CLI commands (`tenancy:migrate`, `tenancy:run`) | Full lifecycle, Doctrine Migrations | Needs all infrastructure stable |

---

## Scaling Considerations

| Scale | Architecture Adjustments |
|-------|--------------------------|
| 1-50 tenants | Default config. Single landlord DB, sequential migrations, synchronous bootstrapping |
| 50-500 tenants | Add connection pooling (PgBouncer / ProxySQL). `tenancy:migrate` benefits from parallel batches (v1.1). Cache bootstrapper should use Redis to avoid APCu cross-tenant contamination |
| 500+ tenants | Consider async resource sync (Messenger fan-out is already supported). Monitor landlord DB connection count — every HTTP worker holds one landlord connection open. Read replicas for landlord DB lookups |
| Shared-DB path | SQL filter adds a WHERE clause to every query — profile with EXPLAIN on large datasets. Ensure `tenant_id` column is indexed on every `#[TenantAware]` table |

---

## Anti-Patterns

### Anti-Pattern 1: Static Tenant Context

**What people do:** Store the active tenant in a static property or a singleton not managed by the DI container.
**Why it's wrong:** PHP-FPM workers are persistent — a static tenant leaks across requests. Symfony's kernel is shared across sub-requests; a static tenant corrupts ESI/HttpKernel fragment rendering.
**Do this instead:** Store tenant in a DI-registered `TenantContext` service with `shared: true` (request scope via listener reset on `kernel.terminate`).

### Anti-Pattern 2: Resolving Tenant Inside Doctrine Repositories

**What people do:** Inject `TenantContext` directly into repositories and call `getOrNull()` inside queries.
**Why it's wrong:** Repositories become stateful; PHPStan cannot catch missing context. Violates SRP — repositories should not know about tenancy resolution.
**Do this instead:** `TenantAwareFilter` handles scoping transparently. Repositories remain dumb query objects.

### Anti-Pattern 3: Switching DBAL Params Without Closing the Connection First

**What people do:** Update connection parameters on `TenantConnection` but skip calling `close()` first.
**Why it's wrong:** Doctrine maintains an open PDO connection internally. New params are ignored until the connection is closed and re-established. Queries silently run against the previous tenant's database.
**Do this instead:** `TenantConnection::switchTenant()` must always call `$this->close()` before replacing `_params`.

### Anti-Pattern 4: Using the Default Entity Manager for Both Landlord and Tenant Queries

**What people do:** Use a single Doctrine entity manager and switch its connection for tenant queries, leaving landlord queries to also go through the same switched connection.
**Why it's wrong:** If the tenant connection swap happens before a landlord query (e.g., inside a bootstrapper), the landlord query runs against the tenant DB. Landlord and tenant entity managers must be strictly separate named managers mapped to separate connections.
**Do this instead:** Configure two named entity managers: `landlord` (always points to central DB) and `tenant` (runtime-switched). Never inject the `tenant` EM without an active `TenantContext`.

### Anti-Pattern 5: Forgetting to Revert Bootstrappers in the Messenger Worker

**What people do:** Bootstrap tenant context in a Messenger middleware but skip the `revert()` step after the handler completes.
**Why it's wrong:** Symfony Messenger workers are long-running processes. Without revert, tenant context leaks into the next message — a potential data breach between tenants.
**Do this instead:** `TenantMiddleware` must wrap handler execution in a try/finally block, calling `TenantContextClearer::clear()` in the `finally` clause.

---

## Integration Points

### Bundle DI Registration

| Integration | Mechanism | Notes |
|-------------|-----------|-------|
| Resolver auto-discovery | `registerForAutoconfiguration(TenantResolverInterface::class)->addTag('tenancy.resolver')` | Users implement interface, bundle discovers automatically |
| Bootstrapper auto-discovery | `registerForAutoconfiguration(TenantBootstrapperInterface::class)->addTag('tenancy.bootstrapper')` | Same pattern |
| Profiler panel | `data_collector` tag on `TenantDataCollector` | Only registered when `kernel.debug = true` |
| PHPStan extension | `phpstan/extension-installer` compatible `extension.neon` | Optional; users `require-dev` the bundle |

### Symfony Kernel Events Used

| Event | Priority | Purpose |
|-------|----------|---------|
| `kernel.request` | 32 | Run resolution chain; set `TenantContext`; fire `TenantResolved` |
| `kernel.response` | 0 (default) | `TenantDataCollector::collect()` reads context |
| `kernel.terminate` | 0 (default) | `TenantContextClearer` calls `revert()` on all bootstrappers |
| `kernel.exception` | High (e.g. 128) | Ensure context is cleared on exceptions (guards against partial bootstrap state) |

Priority 32 for `kernel.request` places tenant resolution after Symfony's router (priority 1024) has run and `_route` is available, but well before security (priority 8). This ordering ensures the host/header/query resolvers have a fully initialized request to inspect.

### Doctrine Integration

| Integration | Mechanism | Notes |
|-------------|-----------|-------|
| Landlord connection | Named DBAL connection (`landlord`), static URL | Never switched; always reads central `tenants` table |
| Tenant connection | Named DBAL connection (`tenant`), `wrapperClass: TenantConnection` | Runtime-switched by `DatabaseDriver` |
| SQL Filter registration | `doctrine.orm.entity_manager.default.filter` in bundle services.xml | Registered as disabled; `SharedDriver` enables per-request |
| Attribute detection | PHP 8 `#[TenantAware]` + `ClassMetadata` reflection in filter | `addFilterConstraint()` checks `$targetEntity` for attribute presence |

---

## Sources

- [Symfony Compiler Passes Documentation](https://symfony.com/doc/current/service_container/compiler_passes.html) — HIGH confidence
- [Symfony Service Tags Documentation](https://symfony.com/doc/current/service_container/tags.html) — HIGH confidence
- [Symfony 6.1 AbstractBundle Simplification](https://symfony.com/blog/new-in-symfony-6-1-simpler-bundle-extension-and-configuration) — HIGH confidence
- [Symfony Built-in Events Reference](https://symfony.com/doc/current/reference/events.html) — HIGH confidence
- [Symfony Multiple Entity Managers](https://symfony.com/doc/current/doctrine/multiple_entity_managers.html) — HIGH confidence
- [Symfony Messenger Documentation](https://symfony.com/doc/current/messenger.html) — HIGH confidence
- [Symfony DataCollector / Profiler](https://symfony.com/doc/current/profiler.html) — HIGH confidence
- [Doctrine DBAL Configuration (v4)](https://www.doctrine-project.org/projects/doctrine-dbal/en/4.4/reference/configuration.html) — HIGH confidence
- [PHPStan Custom Rules](https://phpstan.org/developing-extensions/rules) — HIGH confidence
- [stancl/tenancy Bootstrapper Pattern](https://tenancyforlaravel.com/docs/v3/tenancy-bootstrappers/) — HIGH confidence (direct reference architecture)
- [Symfony Messenger Stamps & Middleware (SymfonyCasts)](https://symfonycasts.com/screencast/messenger/middleware-stamps) — MEDIUM confidence

---

*Architecture research for: Symfony multi-tenancy bundle (Context Orchestrator)*
*Researched: 2026-03-17*
