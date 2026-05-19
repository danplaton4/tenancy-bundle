# Phase 15: Architectural Fixes (v0.2) — Research

**Researched:** 2026-04-19
**Domain:** Symfony bundle internals (cache contracts, kernel.request listener, DBAL 4 driver middleware, Doctrine-bundle wiring)
**Confidence:** HIGH

## Executive Summary

All three architectural unknowns collapse against the installed vendor tree:

1. **DBAL middleware is the correct, documented extension point.** `Doctrine\DBAL\Driver\Middleware::wrap(Driver): Driver` is invoked once at `DriverManager::getConnection()` time ([DriverManager.php:145](vendor/doctrine/dbal/src/DriverManager.php#L145)); the wrapped driver's `connect(array $params)` is called every time the DBAL `Connection` re-opens its underlying socket via the protected `connect()` method ([Connection.php:215-222](vendor/doctrine/dbal/src/Connection.php#L215)). `Connection::close()` just nulls `$this->_conn`, leaving the DBAL `Connection` object (and every DI reference to it, including `EntityManager::$conn`) stable while forcing a reconnect. The middleware sees the fresh `TenantContext` on every reconnect. **`$this->params` is passed to `driver->connect()` — the middleware is the only layer allowed to mutate them.**
2. **The DoctrineBundle tag is `doctrine.middleware`** (not `doctrine.dbal.driver.middleware` as CONTEXT guessed), with an optional `connection` attribute that scopes to named connections. `AsMiddleware` attribute + autoconfiguration also available. Registration is pure service-tag; no `doctrine.yaml` config key exists for middlewares.
3. **The `cache.app` substitution surface is fully substantiated.** Aliases in `framework-bundle` resolve `CacheItemPoolInterface`, `CacheInterface`, `NamespacedPoolInterface` → `cache.app`; `TagAwareCacheInterface` → `cache.app.taggable`. The canonical decorator signature (per `ProxyAdapter`, `TraceableAdapter`) is `AdapterInterface, CacheInterface, NamespacedPoolInterface, PruneableInterface, ResettableInterface`. **Note:** CONTEXT names `ResetInterface` — idiomatic Symfony Cache uses `Symfony\Component\Cache\ResettableInterface` (which extends `Symfony\Contracts\Service\ResetInterface`). Use `ResettableInterface` to match Symfony's own decorators.

**Primary recommendation:** Plan 15-01 and 15-03 should mirror the existing Symfony decorator shapes exactly (`TraceableAdapter` for cache, `Symfony\Bridge\Doctrine\Middleware\IdleConnection\Driver` for DBAL middleware) — these are blessed patterns, not greenfield design. Plan 15-02 is a small, contained signature change. Plan 15-04 is mechanical doc replacement.

---

## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| FIX-01 | `TenantAwareCacheAdapter` full cache.app substitution surface | Priority 2 — canonical decorator shape confirmed via `ProxyAdapter`, `TraceableAdapter`; sibling tag-aware pattern via `TraceableTagAwareAdapter` |
| FIX-02 | Nullable resolver chain + orchestrator branching | Priority 3 — no production TenantResolved subscribers in bundle; orchestrator is the only dispatch site; filter already null-guards |
| FIX-03 | DBAL Driver-Middleware architecture replacing `wrapperClass`+reflection | Priority 1 — middleware `wrap()` + `AbstractDriverMiddleware::connect($params)` confirmed; `Connection::close()` keeps object stable, only nulls `_conn` |
| FIX-04 | Docs + `tenancy:init` YAML template alignment | Priority 5/6 — 15 doc files reference stale terms; template currently has no tenant-driver YAML block |

---

## User Constraints (from CONTEXT.md)

### Locked Decisions

**Issue #5 (FIX-01):** Decorator gains `CacheInterface`, `PruneableInterface`, `ResetInterface`; sibling `TenantAwareTagAwareCacheAdapter` decorates `cache.app.taggable`; DI-level sanity check asserts decorator implements every interface the decorated service exposes. `pool()` return widens; `$inner` intersection widens. Integration test boots stock kernel and resolves `CacheInterface` without TypeError.

**Issue #6 (FIX-02):** New `final readonly class TenantResolution` replaces `array{tenant, resolvedBy}`. `ResolverChain::resolve(Request): ?TenantResolution`. `TenantContextOrchestrator::onKernelRequest` branches on null (no context, no boot, no `TenantResolved` dispatch). `TenantNotFoundException` narrowed to "identifier extracted but provider rejected". Stretch goal: `#[RequiresTenant]` attribute — deferred if budget tight.

**Issues #7+#8 (FIX-03):** `TenantDriverMiddleware implements \Doctrine\DBAL\Driver\Middleware` wrapping `TenantAwareDriver extends AbstractDriverMiddleware`. `connect()` reads `TenantContext`, merges `$tenant->getConnectionConfig()` over `$params`, delegates. `DatabaseSwitchBootstrapper::boot()` reduces to `$connection->close()`. `TenantConnection`, `TenantConnectionInterface`, `wrapperClass` are **deleted outright** (2 downloads on v0.1, all self). Integration test uses two SQLite file DBs (hermetic CI).

**Issue #4/FIX-04:** Docs refreshed for accuracy (no new pages). `docs/architecture/dbal-wrapper.md` → rename or rewrite to `dbal-middleware.md`. `docs/user-guide/database-per-tenant.md` placeholders: MySQL, not SQLite. CHANGELOG 0.2.0 entry + UPGRADE.md 0.1→0.2 section. `tenancy:init` template includes a tenant-driver-family `doctrine.yaml` block.

### Claude's Discretion

- Whether the decorator DI sanity check is inline in `loadExtension()` or a dedicated `CacheDecoratorContractPass`.
- Whether to rename `dbal-wrapper.md` to `dbal-middleware.md` in place or git-mv.
- Integration test topology for FIX-03: two SQLite file paths (per CONTEXT) confirmed workable.

### Deferred Ideas (OUT OF SCOPE)

- `#[RequiresTenant]` controller attribute + argument resolver (stretch for 15-02; backlog if budget tight).
- Profiler toolbar tab (DX-02 — v1.1).
- PHPStan extension enforcing `#[TenantAware]` usage (DX-03 — v1.1).
- Tagging issue-closing commits (let executor mention `Fixes #N`).

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Tenant identification from request | HTTP kernel (Orchestrator listener) | — | Priority 20 kernel.request listener, above Security (8), below Router (32) |
| Contract-complete cache decoration | Bundle DI wiring (compile-time) | Runtime decorator class | DI-level Liskov — decorator must declare every interface `cache.app` aliases expose |
| Tenant-scoped DB connection | DBAL driver layer (middleware) | DBAL Connection (close() triggers reconnect) | Driver is the correct DBAL 4 extension point; Connection is immutable post-construction |
| Bootstrapper orchestration | Bootstrapper chain (runtime) | Event dispatcher | BootstrapperChain owns boot/clear lifecycle; only fires when context is set |
| Documentation accuracy | Static docs + YAML template | CHANGELOG/UPGRADE | Non-runtime tier; pure text change |

---

## Priority 1 — DBAL 4 Driver Middleware (FIX-03)

### 1.1 Interface Surface

**`Doctrine\DBAL\Driver\Middleware`** ([vendor/doctrine/dbal/src/Driver/Middleware.php](vendor/doctrine/dbal/src/Driver/Middleware.php)):
```php
namespace Doctrine\DBAL\Driver;

use Doctrine\DBAL\Driver;

interface Middleware
{
    public function wrap(Driver $driver): Driver;
}
```

**`Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware`** ([vendor/.../Middleware/AbstractDriverMiddleware.php:14-38](vendor/doctrine/dbal/src/Driver/Middleware/AbstractDriverMiddleware.php)):
```php
abstract class AbstractDriverMiddleware implements Driver
{
    public function __construct(private readonly Driver $wrappedDriver) {}

    public function connect(#[SensitiveParameter] array $params): DriverConnection
    {
        return $this->wrappedDriver->connect($params);
    }

    public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
    {
        return $this->wrappedDriver->getDatabasePlatform($versionProvider);
    }

    public function getExceptionConverter(): ExceptionConverter
    {
        return $this->wrappedDriver->getExceptionConverter();
    }
}
```

**`Doctrine\DBAL\Driver` interface** (what `connect()` returns) — [vendor/doctrine/dbal/src/Driver.php:32](vendor/doctrine/dbal/src/Driver.php#L32):
```php
public function connect(#[SensitiveParameter] array $params): DriverConnection;
```
`DriverConnection` = `Doctrine\DBAL\Driver\Connection` (the raw socket-level connection, not the outer DBAL `Connection`).

### 1.2 Registration in Symfony/DoctrineBundle

**Tag name:** `doctrine.middleware` (NOT `doctrine.dbal.driver.middleware`).

Source: [vendor/.../doctrine-bundle/src/DependencyInjection/DoctrineExtension.php:551](vendor/doctrine/doctrine-bundle/src/DependencyInjection/DoctrineExtension.php#L551):
```php
$container->registerForAutoconfiguration(MiddlewareInterface::class)->addTag('doctrine.middleware');
```

**Per-connection scoping** via `connection` tag attribute ([MiddlewaresPass.php:33-48](vendor/doctrine/doctrine-bundle/src/DependencyInjection/Compiler/MiddlewaresPass.php#L33)):
```php
foreach ($container->findTaggedServiceIds('doctrine.middleware') as $id => $tags) {
    foreach ($tags as $tag) {
        if (! isset($tag['connection'])) {
            // global — applies to every connection
            $middlewarePriorities[$id] = $tag['priority'] ?? null;
            continue;
        }
        // scoped — only applies to the named connection
        $middlewareConnections[$id][$tag['connection']] = $tag['priority'] ?? null;
    }
}
```

**MiddlewaresPass then generates per-connection child definitions and installs them via `setMiddlewares()`** on each `doctrine.dbal.<name>_connection.configuration`:
```php
$container->getDefinition(sprintf('doctrine.dbal.%s_connection.configuration', $name))
    ->addMethodCall('setMiddlewares', [$middlewareRefs]);
```

**`AsMiddleware` PHP attribute** ([vendor/.../doctrine-bundle/src/Attribute/AsMiddleware.php](vendor/doctrine/doctrine-bundle/src/Attribute/AsMiddleware.php)):
```php
#[Attribute(Attribute::TARGET_CLASS)]
class AsMiddleware
{
    public function __construct(
        public array $connections = [],   // scopes to named connections; [] = global
        public int|null $priority = null,
    ) {}
}
```

**How a bundle registers a middleware scoped to the `tenant` connection:**
```php
// In services.php (or TenancyBundle::loadExtension inside the database.enabled branch)
$services->set('tenancy.dbal.tenant_driver_middleware', TenantDriverMiddleware::class)
    ->args([service('tenancy.context')])
    ->tag('doctrine.middleware', ['connection' => 'tenant']);
```
**No `doctrine.yaml` config key** exists for middleware registration. Confirmed via `grep "middleware" Configuration.php` → no results.

### 1.3 Reference Implementation — IdleConnection Middleware

**Primary analog for `TenantDriverMiddleware`**, [vendor/.../doctrine-bundle/src/Middleware/IdleConnectionMiddleware.php](vendor/doctrine/doctrine-bundle/src/Middleware/IdleConnectionMiddleware.php):
```php
class IdleConnectionMiddleware implements Middleware, ConnectionNameAwareInterface
{
    public function __construct(
        private readonly ArrayObject $connectionExpiries,
        private readonly array $ttlByConnection,
    ) {}

    public function setConnectionName(string $name): void { $this->connectionName = $name; }

    public function wrap(Driver $driver): IdleConnectionDriver
    {
        return new IdleConnectionDriver(
            $driver,
            $this->connectionExpiries,
            $this->ttlByConnection[$this->connectionName],
            $this->connectionName,
        );
    }
}
```

**Primary analog for `TenantAwareDriver`**, [vendor/symfony/doctrine-bridge/Middleware/IdleConnection/Driver.php](vendor/symfony/doctrine-bridge/Middleware/IdleConnection/Driver.php):
```php
final class Driver extends AbstractDriverMiddleware
{
    public function __construct(
        DriverInterface $driver,
        private \ArrayObject $connectionExpiries,
        private readonly int $ttl,
        private readonly string $connectionName,
    ) {
        parent::__construct($driver);
    }

    public function connect(array $params): ConnectionInterface
    {
        $timestamp = time();
        $connection = parent::connect($params);
        $this->connectionExpiries[$this->connectionName] = $timestamp + $this->ttl;

        return $connection;
    }
}
```

**Canonical class shape for the phase:**
```php
// src/DBAL/TenantDriverMiddleware.php
final class TenantDriverMiddleware implements \Doctrine\DBAL\Driver\Middleware
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function wrap(\Doctrine\DBAL\Driver $driver): \Doctrine\DBAL\Driver
    {
        return new TenantAwareDriver($driver, $this->tenantContext);
    }
}

// src/DBAL/TenantAwareDriver.php
final class TenantAwareDriver extends AbstractDriverMiddleware
{
    public function __construct(
        \Doctrine\DBAL\Driver $wrappedDriver,
        private readonly TenantContext $tenantContext,
    ) {
        parent::__construct($wrappedDriver);
    }

    public function connect(array $params): \Doctrine\DBAL\Driver\Connection
    {
        $tenant = $this->tenantContext->getTenant();
        if ($tenant !== null) {
            // Tenant keys win; do NOT touch 'url' (params have already been parsed upstream)
            $params = array_merge($params, $tenant->getConnectionConfig());
        }
        return parent::connect($params);
    }
}
```

### 1.4 `Connection::close()` Reconnect Behavior — THE LOAD-BEARING FACT

**[vendor/doctrine/dbal/src/Connection.php:412-416](vendor/doctrine/dbal/src/Connection.php#L412):**
```php
public function close(): void
{
    $this->_conn                   = null;
    $this->transactionNestingLevel = 0;
}
```

**[vendor/doctrine/dbal/src/Connection.php:215-232](vendor/doctrine/dbal/src/Connection.php#L215) — the lazy-reconnect path:**
```php
protected function connect(): DriverConnection
{
    if ($this->_conn !== null) {
        return $this->_conn;
    }

    try {
        $connection = $this->_conn = $this->driver->connect($this->params);
    } catch (Driver\Exception $e) {
        throw $this->convertException($e);
    }
    ...
}
```

**Three critical properties:**
1. `close()` **does not discard the Connection object** — it only nulls the driver-level `_conn`. Every DI holder (EntityManager, repositories, migrations config) keeps the same `Connection` instance.
2. Next query triggers `connect()` → `$this->driver->connect($this->params)`. `$this->driver` is whatever the middleware chain produced at `DriverManager::getConnection()` time (our `TenantAwareDriver` at the top).
3. `$this->params` is **frozen at Connection construction time**. We cannot mutate it (it's private in DBAL 4, and even `TenantConnection::switchTenant`'s reflection trick is obsolete). Instead, **`TenantAwareDriver::connect($params)` receives those frozen landlord params and merges tenant params on top of them at connect time.** This is why the middleware sees fresh context per connect.

### 1.5 Middleware Chain Ordering

[vendor/doctrine/dbal/src/DriverManager.php:137-155](vendor/doctrine/dbal/src/DriverManager.php#L137):
```php
$driver = self::createDriver($params['driver'] ?? null, $params['driverClass'] ?? null);

foreach ($config->getMiddlewares() as $middleware) {
    $driver = $middleware->wrap($driver);
}

$wrapperClass = $params['wrapperClass'] ?? Connection::class;
return new $wrapperClass($params, $driver, $config);
```

Middlewares wrap in registration order: **first middleware wraps the real driver; last middleware is the outermost** (seen first by Connection on `connect()`). DoctrineBundle sorts by tag priority desc (higher priority = applied later = outermost) — [MiddlewaresPass.php:83](vendor/doctrine/doctrine-bundle/src/DependencyInjection/Compiler/MiddlewaresPass.php#L83).

**Ordering decision for our middleware:** Default priority (0) is fine. The bundle-shipped `IdleConnection`, `Debug`, `Logging` middlewares only wrap behavior around `connect()`; none mutate `$params`. Our middleware is the only one that mutates params, and it does so **before** calling `parent::connect($params)`, so downstream middlewares (wrapping the real driver) see already-merged params. **Recommended:** no explicit priority on the tag. If ordering concerns surface in integration, bump priority: a high value (e.g. `100`) makes `TenantAwareDriver` outermost — so debug/idle middlewares see the merged params, matching observable behavior (log/debug should reflect the real tenant target).

### 1.6 Per-Connection Targeting

**Per-connection scoping IS supported** via tag attribute (see 1.2):
```php
->tag('doctrine.middleware', ['connection' => 'tenant'])
```

**Recommendation:** scope the middleware to `tenant` only (never `landlord`). This is both correct AND safer: the landlord connection never needs tenant context, and scoping prevents accidentally merging tenant params into a landlord query.

The connection name comes from `TenancyBundle::loadExtension()` — currently hardcoded `doctrine.dbal.tenant_connection` is the DI service, and the connection name in YAML is `tenant` (see [DoctrineTestKernel.php:73-82](tests/Integration/Support/DoctrineTestKernel.php#L73)). The bundle should document: *"In database_per_tenant mode, the middleware is tagged for the `tenant` connection. If you rename this connection in your doctrine config, also update the bundle's `tenant_connection_name` config key."*

**Open question for planner:** should the tenant connection name be configurable via a new `tenancy.tenant_connection` scalar, or hardcoded as `tenant`? The current code hardcodes `doctrine.dbal.tenant_connection` service ID in `TenancyBundle.php:106` — so `tenant` is already an implicit convention.

### 1.7 Integration Test Topology

**Chosen:** two SQLite file databases with distinct `path` params (per CONTEXT.md — hermetic CI, no Docker).

The existing `DoctrineTestKernel` + `DatabaseSwitchIntegrationTest` already proves the pattern works (see [tests/Integration/DatabaseSwitchIntegrationTest.php](tests/Integration/DatabaseSwitchIntegrationTest.php)). **The new test for FIX-03 replaces** this file: swap `$conn->switchTenant([...])` calls with `$tenantContext->setTenant(...)` + `$conn->close()` calls, and assert the same outcome (tenant A data not visible when context = tenant B).

**Test kernel changes (vs. existing DoctrineTestKernel):**
- Remove `'wrapper_class' => TenantConnection::class` from the tenant connection config.
- Middleware registration happens automatically via `TenancyBundle::loadExtension()` once the bundle wires it.
- The `tenant` connection's initial `path` placeholder (`tenancy_test_placeholder.db`) can stay — it gets overridden by merge at connect time.

**Test skeleton (new file `DatabasePerTenantMiddlewareIntegrationTest`):**
```php
// setUpBeforeClass: create schema for TestProduct under both $pathA and $pathB
$container = static::$kernel->getContainer();
$tenantContext = $container->get('tenancy.context');
$conn = $container->get('doctrine.dbal.tenant_connection');

// Tenant A
$tenantA = (new Tenant('a', 'A'))->setConnectionConfig(['path' => static::$pathA]);
$tenantContext->setTenant($tenantA);
$conn->close();   // force reconnect — middleware will merge $pathA on next query
$schemaTool = new SchemaTool($tenantEm);
$schemaTool->createSchema(...);

// Insert row as tenant A, then switch to B and assert empty
$tenantContext->setTenant($tenantA);
$conn->close();
$emA->persist(new TestProduct('only-in-A'));
$emA->flush();

$tenantB = (new Tenant('b', 'B'))->setConnectionConfig(['path' => static::$pathB]);
$tenantContext->setTenant($tenantB);
$conn->close();
$this->assertSame('0', (string) $conn->fetchOne('SELECT COUNT(*) FROM test_products'));
```

Note: we should NOT test through the orchestrator here — the orchestrator already has its own integration test (FIX-02). This test targets **the middleware in isolation**: does setting the context + closing the connection actually route the next query to the tenant's database?

---

## Priority 2 — Symfony `cache.app` Substitution Surface (FIX-01)

### 2.1 Exact Contracts Exposed by `cache.app`

**[vendor/symfony/framework-bundle/Resources/config/cache.php:36-49](vendor/symfony/framework-bundle/Resources/config/cache.php#L36):**
```php
$container->services()
    ->set('cache.app')
        ->parent('cache.adapter.filesystem')
        ->public()
        ->tag('cache.pool', ['clearer' => 'cache.app_clearer'])

    ->set('cache.app.taggable', TagAwareAdapter::class)
        ->args([service('cache.app')])
        ->tag('cache.taggable', ['pool' => 'cache.app'])
```

**Aliases at [cache.php:250-256](vendor/symfony/framework-bundle/Resources/config/cache.php#L250):**
```php
->alias(CacheItemPoolInterface::class, 'cache.app')
->alias(CacheInterface::class,         'cache.app')
->alias(NamespacedPoolInterface::class, 'cache.app')
->alias(TagAwareCacheInterface::class,  'cache.app.taggable')
```

**Parent `cache.adapter.filesystem` → `FilesystemAdapter`** [vendor/symfony/cache/Adapter/FilesystemAdapter.php:19](vendor/symfony/cache/Adapter/FilesystemAdapter.php#L19):
```php
class FilesystemAdapter extends AbstractAdapter implements PruneableInterface
```

**`AbstractAdapter`** [vendor/symfony/cache/Adapter/AbstractAdapter.php:27](vendor/symfony/cache/Adapter/AbstractAdapter.php#L27):
```php
abstract class AbstractAdapter
    implements AdapterInterface, CacheInterface, NamespacedPoolInterface, LoggerAwareInterface, ResettableInterface
```

**Therefore, the full interface surface of `cache.app` (service ID):**
- `Symfony\Component\Cache\Adapter\AdapterInterface` (extends `CacheItemPoolInterface`)
- `Psr\Cache\CacheItemPoolInterface` (inherited)
- `Symfony\Contracts\Cache\CacheInterface`
- `Symfony\Contracts\Cache\NamespacedPoolInterface`
- `Symfony\Component\Cache\PruneableInterface` (via `FilesystemAdapter`)
- `Symfony\Component\Cache\ResettableInterface` (via `AbstractAdapter`; extends `Symfony\Contracts\Service\ResetInterface`)
- `Psr\Log\LoggerAwareInterface` (present but NOT essential — no DI alias points at it; safe to skip on decorator)

### 2.2 Contract Signatures

**`CacheInterface`** [vendor/symfony/cache-contracts/CacheInterface.php](vendor/symfony/cache-contracts/CacheInterface.php):
```php
public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed;
public function delete(string $key): bool;
```

**`PruneableInterface`** [vendor/symfony/cache/PruneableInterface.php](vendor/symfony/cache/PruneableInterface.php):
```php
public function prune(): bool;
```

**`ResettableInterface`** [vendor/symfony/cache/ResettableInterface.php](vendor/symfony/cache/ResettableInterface.php):
```php
interface ResettableInterface extends \Symfony\Contracts\Service\ResetInterface
{
    // inherits: public function reset(): void;
}
```

**`NamespacedPoolInterface`** (already implemented):
```php
public function withSubNamespace(string $namespace): static;
```

**`TagAwareAdapterInterface` vs `TagAwareCacheInterface`** — two distinct hierarchies:
- `Symfony\Component\Cache\Adapter\TagAwareAdapterInterface extends AdapterInterface` (PSR-6 flavor)
- `Symfony\Contracts\Cache\TagAwareCacheInterface extends CacheInterface` (callable flavor)

Both add `invalidateTags(array $tags): bool`. `cache.app.taggable` is a `TagAwareAdapter` — which implements both at once ([TagAwareAdapter.php:38](vendor/symfony/cache/Adapter/TagAwareAdapter.php#L38)):
```php
class TagAwareAdapter implements
    TagAwareAdapterInterface, TagAwareCacheInterface,
    NamespacedPoolInterface, PruneableInterface, ResettableInterface, LoggerAwareInterface
```

### 2.3 Canonical Decorator Shapes (from Symfony's own decorators)

**`TraceableAdapter`** [vendor/symfony/cache/Adapter/TraceableAdapter.php:29](vendor/symfony/cache/Adapter/TraceableAdapter.php#L29):
```php
class TraceableAdapter implements AdapterInterface, CacheInterface, NamespacedPoolInterface, PruneableInterface, ResettableInterface
```

**`ProxyAdapter`** [vendor/symfony/cache/Adapter/ProxyAdapter.php:27](vendor/symfony/cache/Adapter/ProxyAdapter.php#L27):
```php
class ProxyAdapter implements AdapterInterface, NamespacedPoolInterface, CacheInterface, PruneableInterface, ResettableInterface
```

**`TraceableTagAwareAdapter`** [vendor/symfony/cache/Adapter/TraceableTagAwareAdapter.php:19](vendor/symfony/cache/Adapter/TraceableTagAwareAdapter.php#L19):
```php
class TraceableTagAwareAdapter extends TraceableAdapter
    implements TagAwareAdapterInterface, TagAwareCacheInterface
{
    public function __construct(TagAwareAdapterInterface $pool, ?\Closure $disabled = null)
    { parent::__construct($pool, $disabled); }

    public function invalidateTags(array $tags): bool { /* delegate */ }
}
```

**Recommendation for the phase — mirror `TraceableAdapter` exactly:**
```php
final class TenantAwareCacheAdapter implements
    AdapterInterface,
    CacheInterface,
    NamespacedPoolInterface,
    PruneableInterface,
    ResettableInterface
{
    public function __construct(
        private AdapterInterface&CacheInterface&NamespacedPoolInterface&PruneableInterface&ResettableInterface $inner,
        private readonly TenantContext $tenantContext,
        private readonly string $cachePrefixSeparator = '.',
    ) {}

    private function pool(): AdapterInterface&CacheInterface&NamespacedPoolInterface&PruneableInterface&ResettableInterface
    {
        $tenant = $this->tenantContext->getTenant();
        if ($tenant !== null) {
            return $this->inner->withSubNamespace($tenant->getSlug() . $this->cachePrefixSeparator);
        }
        return $this->inner;
    }

    // Existing AdapterInterface methods: getItem/getItems/hasItem/clear/deleteItem/deleteItems/save/saveDeferred/commit

    // NEW — CacheInterface
    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    { return $this->pool()->get($key, $callback, $beta, $metadata); }

    public function delete(string $key): bool
    { return $this->pool()->delete($key); }

    // NEW — PruneableInterface (delegate to inner root, namespace is irrelevant for prune)
    public function prune(): bool
    { return $this->inner->prune(); }

    // NEW — ResettableInterface
    public function reset(): void
    { $this->inner->reset(); }

    // Existing — withSubNamespace
}
```

**Sibling for `cache.app.taggable`:**
```php
final class TenantAwareTagAwareCacheAdapter extends TenantAwareCacheAdapter
    implements TagAwareAdapterInterface, TagAwareCacheInterface
{
    public function __construct(
        TagAwareAdapterInterface&TagAwareCacheInterface&NamespacedPoolInterface&PruneableInterface&ResettableInterface $inner,
        TenantContext $tenantContext,
        string $cachePrefixSeparator = '.',
    ) {
        parent::__construct($inner, $tenantContext, $cachePrefixSeparator);
    }

    public function invalidateTags(array $tags): bool
    { return $this->pool()->invalidateTags($tags); }
}
```

**DI wiring (extends existing `config/services.php:92-98`):**
```php
$services->set('tenancy.cache_adapter', TenantAwareCacheAdapter::class)
    ->decorate('cache.app')
    ->args([service('.inner'), service('tenancy.context'), param('tenancy.cache_prefix_separator')]);

$services->set('tenancy.cache_adapter.taggable', TenantAwareTagAwareCacheAdapter::class)
    ->decorate('cache.app.taggable')
    ->args([service('.inner'), service('tenancy.context'), param('tenancy.cache_prefix_separator')]);
```

### 2.4 DI-Level Contract Check (CONTEXT locked)

**No prior art in Symfony** for "assert decorator implements every interface decorated service exposes." Closest analog: `symfony/dependency-injection`'s `DecoratorServicePass` just rewires service IDs; it doesn't verify contracts.

**Recommended implementation — custom compiler pass `CacheDecoratorContractPass`** (runs at `TYPE_BEFORE_OPTIMIZATION`):
```php
final class CacheDecoratorContractPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ([
            'tenancy.cache_adapter' => 'cache.app',
            'tenancy.cache_adapter.taggable' => 'cache.app.taggable',
        ] as $decoratorId => $decoratedId) {
            if (!$container->hasDefinition($decoratorId)) continue;
            if (!$container->hasDefinition($decoratedId)) continue;

            $decoratorClass = $container->getDefinition($decoratorId)->getClass();
            $decoratedClass = $this->resolveEffectiveClass($container, $decoratedId);

            $missing = array_diff(
                class_implements($decoratedClass) ?: [],
                class_implements($decoratorClass) ?: [],
            );
            $missing = array_filter($missing, static fn($i) => str_starts_with($i, 'Symfony\\'));

            if ($missing !== []) {
                throw new \LogicException(sprintf(
                    'Cache decorator "%s" must implement every interface exposed by "%s". Missing: %s',
                    $decoratorClass, $decoratedClass, implode(', ', $missing),
                ));
            }
        }
    }

    private function resolveEffectiveClass(ContainerBuilder $c, string $id): string
    {
        $def = $c->getDefinition($id);
        while ($def->getClass() === null && $def->getParent() !== null) {
            $def = $c->getDefinition($def->getParent());
        }
        return $def->getClass() ?? throw new \LogicException("Cannot resolve class for $id");
    }
}
```

**Filter on `Symfony\*` namespace** because `cache.adapter.filesystem` inherits `Psr\Log\LoggerAwareInterface` which is NOT an alias; we shouldn't require decorators to implement it.

**Register** in `TenancyBundle::build()`:
```php
$container->addCompilerPass(new CacheDecoratorContractPass());
```

### 2.5 `withSubNamespace()` Backwards Compat

`NamespacedPoolInterface::withSubNamespace(string): static` — the `static` return type makes the clone-in-place pattern (current code) correct. The decorator's `withSubNamespace()` returns a clone of `TenantAwareCacheAdapter`, which still implements all contracts. `pool()->get(...)` downstream works because the returned pool still implements `CacheInterface`. No change to existing logic needed.

### 2.6 Integration Test (CONTEXT-locked)

Boot a stock `TestKernel` with `FrameworkBundle` + `TenancyBundle`, assert:
```php
$container->get(CacheInterface::class);           // aliases → cache.app → our decorator
$container->get(TagAwareCacheInterface::class);   // aliases → cache.app.taggable → our tag-aware decorator
$container->get(CacheItemPoolInterface::class);
$container->get(NamespacedPoolInterface::class);
```
None should throw TypeError. Then exercise the callable path: `$pool->get('key', fn() => 'value')` returns `'value'`.

The integration test replicates the `DoctrineTenantProvider` injection site (line 18-23): `CacheInterface $cache` as a constructor arg, then `$cache->get('tenancy.tenant.x', fn(ItemInterface $i) => null)` — proves issue #5 closure.

---

## Priority 3 — Resolver Optionality (FIX-02)

### 3.1 kernel.request behavior when no context is set

**Current orchestrator** ([src/EventListener/TenantContextOrchestrator.php:33-46](src/EventListener/TenantContextOrchestrator.php#L33)):
```php
public function onKernelRequest(RequestEvent $event): void
{
    if (!$event->isMainRequest()) return;
    $result = $this->resolverChain->resolve($event->getRequest());   // THROWS today
    $this->tenantContext->setTenant($result['tenant']);
    $this->bootstrapperChain->boot($result['tenant']);
    $this->eventDispatcher->dispatch(
        new TenantResolved($result['tenant'], $event->getRequest(), $result['resolvedBy'])
    );
}
```

**Current `ResolverChain::resolve()`** ([src/Resolver/ResolverChain.php:27-41](src/Resolver/ResolverChain.php#L27)) throws `TenantNotFoundException` when all resolvers return null — this bubbles as HTTP 404.

**After FIX-02:** if null, orchestrator skips all three side-effects (setTenant, boot, dispatch). The rest of the request pipeline runs normally.

**Downstream consumers of `TenantContext::getTenant()`:**

| Consumer | Null-safe? | Behavior without tenant |
|----------|------------|-------------------------|
| `TenantAwareCacheAdapter::pool()` | YES — [src/Cache/TenantAwareCacheAdapter.php:22-30](src/Cache/TenantAwareCacheAdapter.php#L22) | Returns `$this->inner` without sub-namespace |
| `TenantAwareFilter::addFilterConstraint()` | YES — [src/Filter/TenantAwareFilter.php:37-44](src/Filter/TenantAwareFilter.php#L37) | In strict_mode, throws `TenantMissingException`; otherwise `''` |
| `TenantSendingMiddleware` (Messenger) | Need to verify but presumed YES (dispatches without stamp) | Message sent without `TenantStamp` |
| `DatabaseSwitchBootstrapper::boot()` | N/A — only runs when chain fires, which won't fire in no-match path | — |

**Conclusion:** the bundle itself is null-safe. Other services in a downstream app that assume `getTenant() !== null` are the user's responsibility (hence the stretch `#[RequiresTenant]` attribute).

### 3.2 `strict_mode` Interplay

**Confirmed safe.** [src/Filter/TenantAwareFilter.php:37-44](src/Filter/TenantAwareFilter.php#L37):
```php
$tenant = $this->tenantContext->getTenant();
if (null === $tenant) {
    if ($this->strictMode) {
        throw new TenantMissingException($targetEntity->getName());
    }
    return '';
}
```

In shared_db mode, a request with no resolver match that queries a `#[TenantAware]` entity:
- `strict_mode: true` (default) → `TenantMissingException` thrown (intended, security-critical)
- `strict_mode: false` → no filter applied; **all tenants' rows returned** (existing documented escape hatch)

This is the desired behavior. No filter-path changes needed for FIX-02.

### 3.3 `ConsoleResolver` Unaffected

[src/Resolver/ConsoleResolver.php:14,68](src/Resolver/ConsoleResolver.php#L68):
```php
use Tenancy\Bundle\Event\TenantResolved;
...
new TenantResolved($tenant, null, self::class)  // dispatches directly
```

ConsoleResolver listens on `ConsoleCommandEvent`, orchestrates context directly via its own injected `TenantContext` + `BootstrapperChain`, does NOT use `ResolverChain`. FIX-02 changes do not affect it.

### 3.4 `TenantResolved` Subscribers

**Grep** (see Priority research): `TenantResolved` is used in:
- `src/EventListener/TenantContextOrchestrator.php` (dispatch) — the one being changed
- `src/Resolver/ConsoleResolver.php` (dispatch) — unaffected
- `src/Event/TenantResolved.php` (the class itself)
- Test files only

**No production subscribers** in the bundle. Skipping dispatch in the no-match path is safe. Downstream apps that subscribe can opt-in: if they want to know *when* tenant resolution was skipped, they can listen on `kernel.request` themselves.

### 3.5 `TenantResolution` Value Object Design

Current return: `array{tenant: TenantInterface, resolvedBy: string}`. New:
```php
// src/Resolver/TenantResolution.php
final readonly class TenantResolution
{
    public function __construct(
        public TenantInterface $tenant,
        public string $resolvedBy,
    ) {}
}
```

**Do NOT include `Request` reference** — `TenantResolved` event already carries it; `TenantResolution` is upstream of the dispatch and should stay minimal (per CONTEXT specifics).

Updated signature:
```php
public function resolve(Request $request): ?TenantResolution
```

Orchestrator:
```php
public function onKernelRequest(RequestEvent $event): void
{
    if (!$event->isMainRequest()) return;

    $resolution = $this->resolverChain->resolve($event->getRequest());
    if ($resolution === null) {
        return;   // public route / landlord / health check
    }

    $this->tenantContext->setTenant($resolution->tenant);
    $this->bootstrapperChain->boot($resolution->tenant);
    $this->eventDispatcher->dispatch(
        new TenantResolved($resolution->tenant, $event->getRequest(), $resolution->resolvedBy)
    );
}
```

### 3.6 `TenantNotFoundException` Narrowing

**Current sites that throw `TenantNotFoundException`:**
- `ResolverChain::resolve()` — **remove** after FIX-02.
- `DoctrineTenantProvider::findBySlug()` [src/Provider/DoctrineTenantProvider.php:46](src/Provider/DoctrineTenantProvider.php#L46) — **keep**. This is the "identifier extracted but not found" case.
- Individual resolvers (HostResolver/HeaderResolver/QueryParamResolver) already call `provider->findBySlug()` and **catch `TenantNotFoundException` → return null** (per Phase 02-02 decision). **No change** to these per CONTEXT non-goals.

Result: the exception narrows its meaning. The class docblock should be updated to reflect this: *"Thrown when a resolver extracted an identifier but the provider could not find a matching active tenant."*

---

## Priority 4 — Test Infrastructure Conventions

### 4.1 Existing Integration Test Kernels

| Kernel | Purpose | Location |
|--------|---------|----------|
| `DoctrineTestKernel` | Database-per-tenant, two EMs, two SQLite files | [tests/Integration/Support/DoctrineTestKernel.php](tests/Integration/Support/DoctrineTestKernel.php) |
| `TenancyTestKernel` | Trait-based tests with InteractsWithTenancy | [tests/Integration/Testing/Support/TenancyTestKernel.php](tests/Integration/Testing/Support/TenancyTestKernel.php) |
| `SharedDbTestKernel` | Shared-DB driver (single EM) | (existing, not loaded; follows same pattern) |
| `BootstrapperTestKernel` | Doctrine + Cache bootstrappers | (existing, not loaded) |

**Closest analog for the new middleware test:** `DoctrineTestKernel`. Clone it, remove `wrapper_class: TenantConnection::class`, the middleware wiring handles connection switching automatically.

### 4.2 SQLite File DB Pattern

Existing pattern: `sys_get_temp_dir().'/tenancy_test_<name>.db'` (see [DoctrineTestKernel.php:71](tests/Integration/Support/DoctrineTestKernel.php#L71)). No shared helper — each test class manages its own cleanup via `setUpBeforeClass` / `tearDownAfterClass`.

**For the new test**, reuse the same pattern. Paths: `sys_get_temp_dir().'/tenancy_middleware_test_tenant_a.db'` and `_b.db`. Clean up before and after.

### 4.3 Integration Test Kernel Lifecycle Convention

**Confirmed in [DatabaseSwitchIntegrationTest.php:30-92](tests/Integration/DatabaseSwitchIntegrationTest.php#L30):**
```php
public static function setUpBeforeClass(): void { ... static::$kernel->boot(); ... }
public static function tearDownAfterClass(): void { ... static::$kernel->shutdown(); ... }
```

Kernel lives for the whole class; each test method works on the same booted container. This avoids PHPUnit risky-test warnings from duplicate kernel error-handler registration.

**The new `DatabasePerTenantMiddlewareIntegrationTest`** MUST follow this convention.

### 4.4 Mocking vs Real

CONTEXT locked: real connect/query roundtrip, not param-level mock. The existing `DatabaseSwitchIntegrationTest` at lines 94-120 already runs real `SELECT COUNT(*)` queries against both SQLite files. **Reuse this pattern** — drop reflection-based `switchTenant` and rely on `setTenant() + close()` to force the middleware to run.

---

## Priority 5 — Deletion Safety for `TenantConnection` / `TenantConnectionInterface`

### 5.1 Production Code References

**Source files that use `TenantConnection` or `TenantConnectionInterface`:**

| File | Usage | Action |
|------|-------|--------|
| `src/DBAL/TenantConnection.php` | The class itself | **DELETE** |
| `src/DBAL/TenantConnectionInterface.php` | The interface itself | **DELETE** |
| `src/Bootstrapper/DatabaseSwitchBootstrapper.php` | Constructor argument typed as `TenantConnectionInterface` | **REWRITE** — constructor takes `Connection` (from `Doctrine\DBAL\Connection`); `boot()` calls `$this->connection->close()`; `clear()` calls `$this->connection->close()` |
| `src/Testing/InteractsWithTenancy.php` | Unused `use` statement + 3 docblock comments | **REMOVE** `use` statement; **UPDATE** 3 comments |
| `src/TenancyBundle.php:105-107` | `DatabaseSwitchBootstrapper` wired with `service('doctrine.dbal.tenant_connection')` | Keep the wiring — DBAL Connection service ID unchanged; just the argument type-hint inside the bootstrapper changes |

### 5.2 Test Code References

| File | Usage | Action |
|------|-------|--------|
| `tests/Unit/DBAL/TenantConnectionTest.php` | All-TenantConnection test | **DELETE** — tests a class that no longer exists |
| `tests/Unit/Bootstrapper/DatabaseSwitchBootstrapperTest.php` | Mocks `TenantConnectionInterface` | **REWRITE** — mock `Doctrine\DBAL\Connection`; expect `close()` calls |
| `tests/Integration/DatabaseSwitchIntegrationTest.php` | Uses `$conn->switchTenant([...])` | **REWRITE** — use `$tenantContext->setTenant(...)` + `$conn->close()` |
| `tests/Integration/EntityManagerResetIntegrationTest.php` | References TenantConnection | **VERIFY** — update if tied to switchTenant; otherwise leave |
| `tests/Integration/Support/DoctrineTestKernel.php:12,76` | `wrapper_class: TenantConnection::class` | **REMOVE** the `wrapper_class` line; **REMOVE** the import |
| `tests/Integration/Testing/Support/TenancyTestKernel.php:12,81` | Same as above | **REMOVE** both |
| `tests/Integration/Command/Support/StubConnectionFactory.php` | Creates TenantConnection via DriverManager | **REWRITE** — return a plain `Doctrine\DBAL\Connection` built via DriverManager; update the return type |

### 5.3 Docs References (FIX-04 scope, listed for plan-04)

| File | Stale Terms |
|------|-------------|
| `docs/user-guide/database-per-tenant.md` | `sqlite://` placeholder, `wrapper_class`, `TenantConnection`, `ReflectionProperty`, `wrapperClass` pattern section |
| `docs/architecture/dbal-wrapper.md` | Entire file is about `ReflectionProperty` + `wrapperClass` — needs rewrite or rename |
| `docs/user-guide/configuration.md` | `TenantConnection` / `wrapper_class` references |
| `docs/architecture/di-compilation.md` | Compiler-pass description likely mentions TenantConnection wiring |
| `docs/user-guide/installation.md` | Quick-start example likely uses SQLite placeholder |
| `docs/user-guide/index.md`, `docs/index.md` | Cross-links to removed content |
| `docs/architecture/event-lifecycle.md` | References `TenantConnection::switchTenant()` in lifecycle diagrams |
| `docs/architecture/design-decisions.md` | "ReflectionProperty considered and accepted" decision — rewrite as rejected with middleware rationale |
| `docs/architecture/index.md` | Nav link to dbal-wrapper.md |
| `docs/contributor-guide/architecture.md` | `TenantConnection` in architecture diagram |
| `docs/contributor-guide/test-infrastructure.md` | `wrapper_class` in test kernel example |
| `docs/user-guide/testing.md` | Trait example referencing TenantConnection |
| `docs/user-guide/examples/saas-subdomain.md` | Example YAML with `wrapper_class` |
| `docs/user-guide/getting-started.md` | Quick-start with `sqlite://` placeholder |
| `CHANGELOG.md` | Add 0.2.0 entry |

**15 markdown files total; plan-04 should batch these into a single commit.**

### 5.4 DI / Config References

| File | Usage | Action |
|------|-------|--------|
| `src/TenancyBundle.php` | No direct `TenantConnection` import (wiring is via string service ID `doctrine.dbal.tenant_connection`) — only the bootstrapper arg changes type | Rewrite bootstrapper constructor; no bundle-level change needed |
| `config/services.php` | No direct `TenantConnection` reference | No change |
| `tenancy:init` YAML template | Currently has NO doctrine.yaml output — need to ADD a correct tenant connection block | **REWRITE** — see Priority 6 |

**Deletion checklist:** 2 production source files deleted + 1 test file deleted + 5 production/test files updated + 15 doc files updated. Total: ~23 files touched.

---

## Priority 6 — `tenancy:init` YAML Template Change

### 6.1 Current Template Behavior

[src/Command/TenantInitCommand.php:83-128](src/Command/TenantInitCommand.php#L83) generates ONLY `config/packages/tenancy.yaml`. It does NOT emit a `doctrine.yaml` sample. The command's `printNextSteps()` tells users to "create your Tenant entity" and "run doctrine:schema:update" — but they're left to assemble the `doctrine.yaml` themselves without any example.

### 6.2 Required Output After FIX-03/FIX-04

Two things change:

**(A)** Update the commented `# driver: database_per_tenant` guidance — after FIX-03, no `wrapper_class` is needed. The existing tenancy.yaml output does not mention `wrapper_class` (never did — that was always in `doctrine.yaml`). So the tenancy.yaml output is already correct.

**(B)** The `printNextSteps()` section MUST point users at an accurate `doctrine.yaml` example. Recommendation: print an inline sample or write `config/packages/doctrine.yaml.example` alongside `tenancy.yaml`.

### 6.3 Recommended YAML Sample for Tenant Connection

**For MySQL tenants (most common):**
```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        default_connection: landlord
        connections:
            landlord:
                url: '%env(DATABASE_URL)%'    # e.g. mysql://app:app@127.0.0.1:3306/landlord
            tenant:
                # Driver family MUST match tenant databases.
                # Connection params are merged with the active tenant's getConnectionConfig() at runtime
                # by TenantDriverMiddleware. The 'dbname' below is a placeholder; it is overridden
                # per-request by the active tenant's config.
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
                        prefix: 'App\Entity\Landlord'
            tenant:
                connection: tenant
                mappings:
                    App:
                        type: attribute
                        dir: '%kernel.project_dir%/src/Entity/Tenant'
                        prefix: 'App\Entity\Tenant'
```

**For PostgreSQL tenants:** same shape, `driver: pdo_pgsql`.

**For SQLite tenants (testing only):** `driver: pdo_sqlite` and `path: '%kernel.project_dir%/var/tenant_placeholder.db'` — no `url:` key with `sqlite://` prefix (that was always a leaky abstraction).

**Critical callout to include in the printNextSteps output:**
> The landlord and tenant connections' `driver` parameter MUST match the driver family of your actual tenant databases. `TenantDriverMiddleware` merges tenant params at `connect()` time, but the driver itself is resolved from the placeholder config at container boot. If you use MySQL for tenants, the placeholder must also be MySQL.

### 6.4 Implementation Recommendation for `TenantInitCommand`

**Option A (simpler):** Print the sample `doctrine.yaml` to stdout as part of `printNextSteps()`. No new file written.

**Option B (richer):** Write `config/packages/tenancy-doctrine.yaml.sample` (dot-sample suffix so it doesn't auto-load) with the annotated YAML above. `printNextSteps()` points at it.

**Recommendation:** Option A — keeps the command surface small. Users can copy/paste into their own `doctrine.yaml`.

---

## Runtime State Inventory

> Refactor/migration phase — required.

| Category | Items Found | Action Required |
|----------|-------------|-----------------|
| **Stored data** | None — no persisted tenancy state stored in DB schemas or caches changes | None |
| **Live service config** | None — no external services registered with tenant-specific config | None |
| **OS-registered state** | None — no cron / systemd / scheduler entries reference `TenantConnection` or `wrapperClass` | None |
| **Secrets/env vars** | None — env var names unchanged; `DATABASE_URL` contract stable | None |
| **Build artifacts / installed packages** | `vendor/composer/autoload_*.php` references the deleted FQCNs `Tenancy\Bundle\DBAL\TenantConnection` + `TenantConnectionInterface` | Users must run `composer dump-autoload` after upgrading v0.1 → v0.2 — note in UPGRADE.md |

**Packagist downloads on v0.1:** 2 (both self). No external users to migrate. Deletion-outright is safe (per CONTEXT decision).

---

## Common Pitfalls

### Pitfall 1: Merging `url` at connect() time
**What goes wrong:** DBAL's `DriverManager::createDriver()` parses `$params['url']` BEFORE middlewares wrap the driver. If our middleware merges a tenant config that includes `url`, the merged `url` is ignored — only `driver`/`host`/`dbname`/etc. are respected at this stage.
**Why it happens:** `url` is resolved into discrete params at DriverManager level; by the time `connect()` is called, `url` is a no-op.
**How to avoid:** The `TenantInterface::getConnectionConfig()` return MUST NOT contain `url`. Tenant entities should expose discrete params (`dbname`, `host`, `port`, `user`, `password`). Document this in CHANGELOG + UPGRADE.md.
**Warning signs:** Tenant DB appears to be the landlord DB despite setTenant() being called — check if tenant config had `url:` set.

### Pitfall 2: Forgetting `$connection->close()` in `DatabaseSwitchBootstrapper::boot()`
**What goes wrong:** Tenant context is updated, but the DBAL Connection still has a cached `_conn` pointing at the landlord socket. Next query continues hitting the landlord database.
**Why it happens:** DBAL's `$this->_conn !== null` check in [Connection.php:217](vendor/doctrine/dbal/src/Connection.php#L217) short-circuits `connect()`.
**How to avoid:** `boot()` MUST call `$connection->close()` after the context is set. `clear()` does the same (so landlord-aware code after a request doesn't see tenant data).
**Warning signs:** Tenant A data visible during a Tenant B request. Detectable via the integration test.

### Pitfall 3: Decorator missing contract — silent at boot, explodes at use
**What goes wrong:** A service injects `CacheInterface` and receives the decorator; PHP throws `TypeError: argument 1 must be of type CacheInterface`.
**Why it happens:** The DI container resolved the alias `CacheInterface::class → cache.app → TenantAwareCacheAdapter`, but `TenantAwareCacheAdapter` doesn't implement `CacheInterface`.
**How to avoid:** `CacheDecoratorContractPass` catches this at container compile time with a descriptive error.
**Warning signs:** `cache:clear` fails on a stock kernel boot in a project using the bundle.

### Pitfall 4: Tag-aware decorator order of arguments
**What goes wrong:** `decorate('cache.app.taggable')` with `.inner` argument-type-hinted as `AdapterInterface` — type error on the narrower tag-aware interface.
**Why it happens:** `cache.app.taggable` is a `TagAwareAdapter` which implements both `TagAwareAdapterInterface` AND `TagAwareCacheInterface`. The decorator's `$inner` must accept the intersection.
**How to avoid:** `TenantAwareTagAwareCacheAdapter::__construct` uses the intersection `TagAwareAdapterInterface&TagAwareCacheInterface&NamespacedPoolInterface&PruneableInterface&ResettableInterface $inner`.
**Warning signs:** DI compile-time error; also caught by CacheDecoratorContractPass.

### Pitfall 5: Middleware registered globally instead of per-connection
**What goes wrong:** `TenantDriverMiddleware` wraps the landlord driver too. Landlord queries get tenant params merged — the landlord connection hits tenant DBs. Cross-tenant Tenant entity lookups break.
**Why it happens:** Forgot the `['connection' => 'tenant']` attribute on the `doctrine.middleware` tag.
**How to avoid:** Always scope the middleware tag. Add an integration test asserting the landlord EM continues reading from the landlord DB after `setTenant()` — already present at [DatabaseSwitchIntegrationTest.php:149-175](tests/Integration/DatabaseSwitchIntegrationTest.php#L149) (to be preserved in the rewritten test).

### Pitfall 6: Symfony Cache `ResetInterface` vs `ResettableInterface`
**What goes wrong:** Decorator implements `Symfony\Contracts\Service\ResetInterface` only; DI container aliases lookup might not recognize it as the Symfony Cache variant.
**Why it happens:** Two names exist. CONTEXT.md uses `ResetInterface`; Symfony's cache components use `Symfony\Component\Cache\ResettableInterface` (which extends `ResetInterface`).
**How to avoid:** Implement `ResettableInterface` (the cache-component one). It transitively implements `ResetInterface`. Mirrors `TraceableAdapter`'s signature exactly.

---

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11 |
| Config file | `phpunit.xml.dist` (project root) |
| Quick run command | `vendor/bin/phpunit --testsuite unit` |
| Full suite command | `vendor/bin/phpunit` |
| Phase gate | `vendor/bin/phpunit && vendor/bin/phpstan analyse && vendor/bin/php-cs-fixer check --diff` |

### Phase Requirements → Test Map

| Req | Behavior | Test Type | Automated Command | File Exists? |
|-----|----------|-----------|-------------------|--------------|
| FIX-01 | `CacheInterface` alias resolves to decorator; `get()` callable works | integration | `phpunit tests/Integration/Cache/TenantAwareCacheAdapterIntegrationTest.php` | Wave 0 — NEW |
| FIX-01 | Decorator implements full substitution surface | unit | `phpunit tests/Unit/Cache/TenantAwareCacheAdapterTest.php` | EXISTS — extend |
| FIX-01 | `CacheDecoratorContractPass` throws when a contract is missing | unit | `phpunit tests/Unit/DependencyInjection/Compiler/CacheDecoratorContractPassTest.php` | Wave 0 — NEW |
| FIX-01 | Tag-aware sibling decorates `cache.app.taggable` + `invalidateTags()` delegates | unit | `phpunit tests/Unit/Cache/TenantAwareTagAwareCacheAdapterTest.php` | Wave 0 — NEW |
| FIX-02 | `ResolverChain::resolve()` returns null when no resolver matches | unit | `phpunit tests/Unit/Resolver/ResolverChainTest.php` | EXISTS — modify |
| FIX-02 | Orchestrator skips boot + dispatch on null resolution | unit + integration | `phpunit tests/Unit/EventListener/TenantContextOrchestratorTest.php` | EXISTS — extend |
| FIX-02 | Public route with no resolver match returns 200 | integration | `phpunit tests/Integration/EventListener/TenantContextOrchestratorIntegrationTest.php` | Wave 0 — NEW (optional if unit test is strong) |
| FIX-02 | `TenantNotFoundException` still thrown by provider with unknown slug | unit | `phpunit tests/Unit/Provider/DoctrineTenantProviderTest.php` | EXISTS |
| FIX-03 | `TenantDriverMiddleware` wraps driver; `TenantAwareDriver::connect()` merges tenant params | unit | `phpunit tests/Unit/DBAL/TenantAwareDriverTest.php` | Wave 0 — NEW |
| FIX-03 | Two-tenant SQLite roundtrip: data isolation | integration | `phpunit tests/Integration/DatabasePerTenantMiddlewareIntegrationTest.php` | Wave 0 — NEW (replaces existing DatabaseSwitchIntegrationTest) |
| FIX-03 | `DatabaseSwitchBootstrapper::boot()` only calls `close()` | unit | `phpunit tests/Unit/Bootstrapper/DatabaseSwitchBootstrapperTest.php` | EXISTS — rewrite |
| FIX-03 | Landlord EM unaffected by tenant switches | integration | part of `DatabasePerTenantMiddlewareIntegrationTest` | Wave 0 — NEW |
| FIX-04 | Docs lint: no `wrapperClass`/`wrapper_class`/`ReflectionProperty`/`sqlite://` in non-shared-DB sections | manual grep | `grep -rn 'wrapperClass\|wrapper_class\|ReflectionProperty\|sqlite://' docs/` returns only intentional occurrences | Wave 0 — ADD lint script |
| FIX-04 | CHANGELOG 0.2.0 entry exists | smoke | `grep -q '## 0.2.0' CHANGELOG.md` | Wave 0 — ADD |
| FIX-04 | UPGRADE.md 0.1→0.2 section exists | smoke | `grep -q '## 0.1 to 0.2' UPGRADE.md` | Wave 0 — ADD |

### Sampling Rate
- **Per task commit:** `vendor/bin/phpunit --testsuite unit` (fast; covers all unit-level requirements)
- **Per wave merge:** `vendor/bin/phpunit` (full suite)
- **Phase gate:** full suite + PHPStan level 9 + php-cs-fixer — all green before `/gsd-verify-work`

### Wave 0 Gaps

- [ ] `tests/Integration/Cache/TenantAwareCacheAdapterIntegrationTest.php` — covers FIX-01 stock-kernel resolution
- [ ] `tests/Unit/Cache/TenantAwareTagAwareCacheAdapterTest.php` — covers FIX-01 tag-aware sibling
- [ ] `tests/Unit/DependencyInjection/Compiler/CacheDecoratorContractPassTest.php` — covers FIX-01 compile-time check
- [ ] `tests/Unit/DBAL/TenantAwareDriverTest.php` — covers FIX-03 middleware connect-time merge
- [ ] `tests/Integration/DatabasePerTenantMiddlewareIntegrationTest.php` — covers FIX-03 roundtrip (replaces DatabaseSwitchIntegrationTest)
- [ ] `scripts/docs-lint.sh` (or inline in CI) — grep for stale terms in docs/
- [ ] **Delete:** `tests/Unit/DBAL/TenantConnectionTest.php` — tests a class that will no longer exist
- [ ] **Rewrite:** `tests/Unit/Bootstrapper/DatabaseSwitchBootstrapperTest.php` — mock `Connection` not `TenantConnectionInterface`

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no | — (no auth changes) |
| V3 Session Management | no | — (no session changes) |
| V4 Access Control | **yes** | `TenantAwareFilter` strict_mode remains ON by default; `TenantNotFoundException` still triggers HTTP 404 when identifier extracted-but-invalid |
| V5 Input Validation | **yes** | Tenant slug already validated via DB lookup in `DoctrineTenantProvider::findBySlug` — no change |
| V6 Cryptography | no | — |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Cross-tenant data leak via cached identity map | Tampering / Info Disclosure | `resetManager('tenant')` on `TenantContextCleared` — unchanged |
| Cache key collision between tenants | Tampering | `TenantAwareCacheAdapter` sub-namespace (keeps working with new contract surface) |
| Resolver swallow masks a real attack (header injection with invalid slug) | Spoofing / Info Disclosure | `TenantInactiveException` bubbles up (not caught) — unchanged; `TenantNotFoundException` still throws from provider |
| **NEW risk from FIX-02:** a route that used to 404 now returns 200 with no tenant, leaking landlord data | Info Disclosure | **Documented escape hatch:** `strict_mode: true` (default) + `#[TenantAware]` attribute on every tenant entity ensures unauthorized tenant-aware queries throw `TenantMissingException`. Stretch goal `#[RequiresTenant]` adds opt-in per-controller enforcement. CHANGELOG + UPGRADE.md MUST call out this behavior change explicitly — it is a security-relevant semantic change. |

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | The `tenant` DBAL connection name is the convention the bundle uses; the middleware's `['connection' => 'tenant']` tag attribute matches | Priority 1.6 | If the bundle supports a configurable tenant connection name (`tenancy.tenant_connection`), the tag attribute must use that value. Verify at planning time whether to add such a config key or document `tenant` as the fixed convention. |
| A2 | `LoggerAwareInterface` can be omitted from the decorator | Priority 2.1 | Low risk — no DI alias points at it; if a downstream service type-hints `LoggerAwareInterface` expecting a logger setter on the cache, that's unusual. |
| A3 | Omitting Option B (write `doctrine.yaml.sample` file) is fine — inline stdout sample suffices | Priority 6.4 | Low risk — users can copy/paste. If UX feedback prefers a file artifact, flip to Option B post-v0.2. |
| A4 | No downstream code subscribes to `TenantResolved` in a way that assumes it fires on every request | Priority 3.4 | Verified null — no production subscribers in the bundle. Downstream apps are out of scope. UPGRADE.md calls out the change. |
| A5 | `composer dump-autoload` is sufficient to flush stale FQCNs after `TenantConnection`/`TenantConnectionInterface` deletion | Runtime State Inventory | Verified — `composer dump-autoload` regenerates `vendor/composer/autoload_*.php`. UPGRADE.md should include the command. |

---

## Open Questions

1. **Tenant connection name convention — fixed or configurable?**
   - What we know: Bundle currently uses `tenant` as the YAML connection name (see [DoctrineTestKernel.php:73](tests/Integration/Support/DoctrineTestKernel.php#L73)); service ID `doctrine.dbal.tenant_connection` hardcoded at [TenancyBundle.php:106](src/TenancyBundle.php#L106).
   - What's unclear: Whether to add `tenancy.tenant_connection` config key (parallel to existing `tenancy.landlord_connection`) so users can rename the tenant connection, or document "the connection must be named `tenant`" as convention.
   - Recommendation: Add `tenancy.tenant_connection` scalar with default `tenant`. Low marginal cost; plan-03 can handle this in the same change.

2. **Middleware tag priority — default or explicit?**
   - What we know: DoctrineBundle's own IdleConnection/Debug middlewares use priority 10. Default (absent) = 0, applies innermost.
   - What's unclear: Whether we want our middleware innermost or outermost.
   - Recommendation: Default (0) is safe. Our middleware wraps the real driver innermost; debug/idle wrap our merged params. If profiler logging needs to show the merged tenant DB target, bump to priority 20.

3. **Rename `dbal-wrapper.md` → `dbal-middleware.md` or rewrite in place?**
   - What we know: 195-line file, content needs full rewrite. Title/filename carries signal.
   - What's unclear: Whether to `git mv` + add a stub redirect page, or rewrite in place and leave the old filename (confusing long-term).
   - Recommendation: `git mv` to `dbal-middleware.md`, rewrite content, add a stub `dbal-wrapper.md` that redirects (MkDocs supports redirects via the `redirects` plugin or manual `<meta>` refresh). Alternatively, just delete and fix nav — no external users on v0.1 means no inbound links to break.

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | All tests | ✓ | 8.2+ (verified by composer.json constraint) | — |
| Composer vendor tree | Research | ✓ | Installed — doctrine/dbal 4.x, doctrine/doctrine-bundle, symfony/framework-bundle, symfony/cache, symfony/cache-contracts, symfony/doctrine-bridge all present | — |
| SQLite (pdo_sqlite) | Integration tests | ✓ (assumed, standard PHP SQLite) | — | — |
| PHPUnit 11 | Full test suite | ✓ (in `vendor/bin/phpunit`) | — | — |
| PHPStan level 9 | Phase gate | ✓ | — | — |
| php-cs-fixer | Phase gate | ✓ | — | — |

**Missing:** None. All tools already in use by prior phases.

---

## Project Constraints (from CLAUDE.md)

- **Doctrine is optional:** Guard all Doctrine-touching wiring with `class_exists(\Doctrine\ORM\EntityManagerInterface::class)` or `interface_exists`. The new middleware classes live under `src/DBAL/` but the DI wiring in `TenancyBundle::loadExtension` MUST stay inside the `$databaseConfig['enabled']` branch (already does).
- **strict_mode defaults ON:** Unchanged by this phase; FIX-02 orchestrator branch doesn't affect strict_mode semantics at query time.
- **`TenantContext` is zero-dep:** Unchanged; middleware receives `TenantContext` via constructor, same as existing bootstrappers.
- **Bootstrapper `clear()` runs in reverse order:** Unchanged; `DatabaseSwitchBootstrapper::clear()` still calls `close()` — the order constraint is still satisfied.
- **Tests use `setUpBeforeClass`/`tearDownAfterClass`:** New `DatabasePerTenantMiddlewareIntegrationTest` MUST follow this pattern (existing `DatabaseSwitchIntegrationTest` is the template).
- **Compiler passes for DI:** The new `CacheDecoratorContractPass` fits this convention exactly.

---

## Sources

### Primary (HIGH confidence)
- `vendor/doctrine/dbal/src/Driver/Middleware.php` — interface definition
- `vendor/doctrine/dbal/src/Driver/Middleware/AbstractDriverMiddleware.php` — base class
- `vendor/doctrine/dbal/src/Driver.php` — Driver contract
- `vendor/doctrine/dbal/src/Connection.php` — `close()` / `connect()` / `$params` behavior
- `vendor/doctrine/dbal/src/DriverManager.php:137-155` — middleware wrap loop + wrapperClass instantiation
- `vendor/doctrine/doctrine-bundle/src/DependencyInjection/Compiler/MiddlewaresPass.php` — per-connection scoping
- `vendor/doctrine/doctrine-bundle/src/DependencyInjection/DoctrineExtension.php:551-565` — auto-configuration + `AsMiddleware` attribute wiring
- `vendor/doctrine/doctrine-bundle/src/Attribute/AsMiddleware.php` — attribute definition
- `vendor/doctrine/doctrine-bundle/src/Middleware/IdleConnectionMiddleware.php` — reference Middleware impl
- `vendor/symfony/doctrine-bridge/Middleware/IdleConnection/Driver.php` — reference AbstractDriverMiddleware subclass
- `vendor/symfony/framework-bundle/Resources/config/cache.php` — cache.app service definition + aliases
- `vendor/symfony/cache-contracts/CacheInterface.php`, `NamespacedPoolInterface.php`, `TagAwareCacheInterface.php` — callable-flavor contracts
- `vendor/symfony/cache/PruneableInterface.php`, `ResettableInterface.php` — adapter-component contracts
- `vendor/symfony/cache/Adapter/AdapterInterface.php`, `TagAwareAdapterInterface.php` — adapter-flavor contracts
- `vendor/symfony/cache/Adapter/AbstractAdapter.php:27` — base implements list (what cache.app inherits)
- `vendor/symfony/cache/Adapter/FilesystemAdapter.php:19` — adds PruneableInterface
- `vendor/symfony/cache/Adapter/ProxyAdapter.php`, `TraceableAdapter.php`, `TagAwareAdapter.php`, `TraceableTagAwareAdapter.php` — canonical decorator shapes
- Existing bundle source (authoritative on current state): `src/Cache/TenantAwareCacheAdapter.php`, `src/Resolver/ResolverChain.php`, `src/EventListener/TenantContextOrchestrator.php`, `src/DBAL/TenantConnection.php`, `src/Bootstrapper/DatabaseSwitchBootstrapper.php`, `src/TenancyBundle.php`, `config/services.php`
- Existing tests (authoritative on conventions): `tests/Integration/Support/DoctrineTestKernel.php`, `tests/Integration/DatabaseSwitchIntegrationTest.php`

### Secondary (MEDIUM confidence)
- None — all research resolved against installed vendor tree.

### Tertiary (LOW confidence)
- None.

---

## Metadata

**Confidence breakdown:**
- Standard Stack (libraries + versions): HIGH — all verified against installed vendor tree
- Architecture (middleware + cache decorator patterns): HIGH — confirmed via reference implementations in Symfony and DoctrineBundle
- Pitfalls: HIGH — derived from DBAL Connection source inspection and canonical decorator shapes
- DI wiring details: HIGH — MiddlewaresPass source read line-by-line
- Test topology: HIGH — existing integration test is the template

**Research date:** 2026-04-19
**Valid until:** 2026-05-19 (30 days — vendor tree is stable; Symfony/DBAL major versions don't shift mid-minor)
