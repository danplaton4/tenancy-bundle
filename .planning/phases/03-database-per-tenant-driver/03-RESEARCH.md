# Phase 3: Database-Per-Tenant Driver - Research

**Researched:** 2026-03-19
**Domain:** Doctrine DBAL 4 / DoctrineBundle 2.x / Symfony 7 — runtime connection switching via wrapperClass
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **Phase boundary**: Only DBAL 4 wrapperClass connection switching + two named EMs (landlord/tenant). Phase does NOT include shared-DB SQL filters — that is Phase 4.
- **`connectionConfig` schema**: Individual DSN component keys (`driver`, `host`, `port`, `dbname`, `user`, `password`), NOT a URL string. Missing keys inherit from base connection's params (merge-over-landlord-defaults).
- **Doctrine config ownership**: Bundle does NOT auto-configure `doctrine.dbal` connections or entity managers. Bundle provides `TenantConnection` (wrapperClass) and `DatabaseSwitchBootstrapper`. User configures `config/packages/doctrine.yaml` manually.
- **Bundle config flag**: `tenancy.database.enabled` (default: `false`) — when `true`, wires `DatabaseSwitchBootstrapper` into DI.
- **Connection switch trigger**: `DatabaseSwitchBootstrapper implements TenantBootstrapperInterface` — plugs into existing bootstrapper chain. `boot()` calls `TenantConnection::switchTenant()`. `clear()` calls `TenantConnection::reset()`.
- **EM reset on context clear**: A dedicated `EntityManagerResetListener` listens on `TenantContextCleared` event and calls `$managerRegistry->resetManager('tenant')`.
- **DoctrineTenantProvider rewiring**: Phase 3 updates `config/services.php`: `service('doctrine.orm.default_entity_manager')` → `service('doctrine.orm.landlord_entity_manager')`.
- **`DatabaseSwitchBootstrapper`**: Must be `final` class (consistent with Phase 1/2 convention).
- **Integration tests**: Use file-based SQLite (`:memory:` not suitable for two separate connections — see Pitfalls).

### Claude's Discretion

- Exact DBAL 4 API call for updating connection params inside `TenantConnection::switchTenant()` (reflection vs. parent::__construct re-call)
- Whether `TenantConnection` stores a "reset params" snapshot at construction or derives it at runtime
- Internal service IDs for `TenantConnection`, `DatabaseSwitchBootstrapper`, `EntityManagerResetListener`
- SQLite file paths for integration tests (unique temp files per test slug)

### Deferred Ideas (OUT OF SCOPE)

- Async/coroutine safety (Swoole, Symfony Runtime) — out of scope for Phase 3
- Connection health check / reconnect on lost connection
- `TenantProviderInterface::findAll()` — Phase 7
- Multiple tenant connections (read replicas)
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| ISOL-01 | Database-per-tenant driver switches the DBAL connection at runtime using DBAL 4's `wrapperClass` pattern (`TenantConnection::switchTenant()`) without rebuilding the container | DBAL 4 source confirms `wrapperClass` mechanism; `connect()` is protected and overridable; params mutation approach via ReflectionProperty documented below |
| ISOL-02 | Database-per-tenant driver configures two named entity managers: `landlord` (static) and `tenant` (runtime-switched) | DoctrineBundle 2.18 dual-EM YAML config documented; service IDs confirmed as `doctrine.orm.{name}_entity_manager`; `resetManager()` works with lazy EMs |
</phase_requirements>

---

## Summary

Phase 3 implements runtime database connection switching for Doctrine DBAL 4 using the `wrapperClass` subclassing mechanism. The central challenge is that DBAL 4 stores connection params in a **private** property (`$params`), meaning no public API exists to update them at runtime. The correct approach for `TenantConnection::switchTenant()` is to use `ReflectionProperty` to write directly to the private `$params` property on the parent `Connection` class, then call `close()` to drop the live driver connection. The next query will trigger `connect()` (protected, lazy) with the updated params.

The `landlord` and `tenant` entity managers are standard DoctrineBundle two-EM configuration. Symfony's `ManagerRegistry::resetManager('tenant')` resets the lazy-declared EM to a fresh instance, clearing the identity map and any closed-EM state. This is the correct teardown mechanism because DoctrineBundle declares its abstract EM service with `->lazy()`, which is what `resetService()` requires.

For integration tests, SQLite `:memory:` cannot be shared across two connections (PDO limitation confirmed by DBAL maintainers). Use file-based SQLite with unique temp file paths per test slug.

**Primary recommendation:** Use `ReflectionProperty` to mutate DBAL 4's private `$params`, then call `$this->close()` to drop the live connection. Override `connect()` as an optional additional hook for logging. Snapshot the original params in the `TenantConnection` constructor for `reset()`.

---

## Standard Stack

### Core (all confirmed from vendor/ source)

| Library | Version (installed) | Purpose | Why Standard |
|---------|---------------------|---------|--------------|
| `doctrine/dbal` | 4.4.2 | Database abstraction; `wrapperClass` mechanism | The only supported extension point for runtime connection switching in DBAL 4 |
| `doctrine/orm` | 3.6.2 | Entity managers; `landlord` + `tenant` named EMs | Two-EM pattern is the standard isolation approach |
| `doctrine/doctrine-bundle` | 2.18.2 | Symfony DI wiring for DBAL + ORM | DoctrineBundle 2.x is the Symfony 7 standard |
| `symfony/doctrine-bridge` | (transitive) | `ManagerRegistry` + `resetManager()` | Provides `resetService()` needed for EM reset |

### Compatibility Matrix

| Component | Version | PHP Constraint |
|-----------|---------|---------------|
| doctrine/dbal | ^4.4 | PHP ^8.2 |
| doctrine/orm | ^3.3 | PHP ^8.2 |
| doctrine/doctrine-bundle | ^2.13 | Symfony ^6.4\|^7.0 |
| Symfony | ^7.x | — |

**Note:** DoctrineBundle 3.x and MigrationsBundle 4.0 require PHP ^8.4 (from STATE.md). We use DoctrineBundle 2.18 which works on PHP 8.2+.

### Installation

```bash
# These are already in require-dev; when the bundle feature is enabled they become runtime deps:
composer require doctrine/dbal "^4.4"
composer require doctrine/orm "^3.3"
composer require doctrine/doctrine-bundle "^2.13"
```

---

## Architecture Patterns

### Recommended Project Structure

```
src/
├── DBAL/
│   └── TenantConnection.php         # wrapperClass subclass — switchTenant(), reset()
├── Bootstrapper/
│   └── DatabaseSwitchBootstrapper.php  # TenantBootstrapperInterface impl
└── EventListener/
    └── EntityManagerResetListener.php  # listens TenantContextCleared → resetManager('tenant')
```

### Pattern 1: DBAL 4 wrapperClass Subclass

**What:** `TenantConnection extends Doctrine\DBAL\Connection`. Registered as `wrapper_class` on the `tenant` DBAL connection in `doctrine.yaml`. The landlord connection uses the default `Connection` class.

**How it works (from DBAL 4.4.2 source — HIGH confidence):**

```
DriverManager::getConnection($params, $config) → new $wrapperClass($params, $driver, $config)
```

DBAL 4 constructor signature (confirmed from `vendor/doctrine/dbal/src/Connection.php:120`):
```php
public function __construct(
    #[SensitiveParameter]
    array $params,
    protected Driver $driver,
    ?Configuration $config = null,
)
```

Note: The `EventManager` parameter was **removed** in DBAL 4 compared to DBAL 3. Do NOT pass it.

**`@phpstan-consistent-constructor` annotation** is present on `Connection` — PHPStan enforces that subclass constructors keep the same parameter types. The `TenantConnection` constructor must accept `(array $params, Driver $driver, ?Configuration $config = null)`.

### Pattern 2: Mutating Private `$params` via Reflection

**The core problem (HIGH confidence, from vendor source):**

```php
// In Connection.php line 93:
private array $params;
```

The `$params` property is **private** — inaccessible from subclasses. There is no `setParams()` or equivalent public API. The DBAL maintainers explicitly declined to expose params as a public API (GitHub issue #5213). `getParams()` is marked `@internal` but remains publicly readable.

**Confirmed approach — Reflection write:**

```php
// Source: vendor/doctrine/dbal/src/Connection.php inspection + PHP reflection capability
final class TenantConnection extends Connection
{
    private array $originalParams;
    private \ReflectionProperty $paramsProperty;

    public function __construct(
        array $params,
        Driver $driver,
        ?Configuration $config = null,
    ) {
        parent::__construct($params, $driver, $config);
        // Snapshot original params for reset()
        $this->originalParams = $params;
        // Cache the reflection property — private on parent, accessible via ReflectionProperty
        $this->paramsProperty = new \ReflectionProperty(Connection::class, 'params');
    }

    public function switchTenant(array $tenantConfig): void
    {
        // Merge tenant-specific keys over current (landlord) params
        $newParams = array_merge($this->getParams(), $tenantConfig);
        // Write to private $params via reflection
        $this->paramsProperty->setValue($this, $newParams);
        // Drop live driver connection — next query triggers lazy connect() with new params
        $this->close();
    }

    public function reset(): void
    {
        $this->paramsProperty->setValue($this, $this->originalParams);
        $this->close();
    }
}
```

**Why `close()` is sufficient (HIGH confidence, from vendor source):**

```php
// Connection.php line 412-416:
public function close(): void
{
    $this->_conn = null;              // drops live DriverConnection (protected)
    $this->transactionNestingLevel = 0;
}
```

After `close()`, DBAL's lazy `connect()` (protected, line 215) is called on the next query:
```php
protected function connect(): DriverConnection
{
    if ($this->_conn !== null) {
        return $this->_conn;
    }
    $connection = $this->_conn = $this->driver->connect($this->params); // uses $params
    ...
}
```

`connect()` uses the private `$this->params` directly — so updating it via reflection before `close()` means the next query connects with the new tenant's params.

**Alternative: re-calling `parent::__construct()`** — This also works (resets `$params`, `$_config`, `$autoCommit`, `$schemaManagerFactory`) but unnecessarily recreates the Configuration object. Reflection is cleaner as it only touches `$params`.

### Pattern 3: DoctrineBundle Dual-EM Configuration

**User's `config/packages/doctrine.yaml`** (the bundle ships a README stub — Phase 9):

```yaml
doctrine:
    dbal:
        default_connection: landlord
        connections:
            landlord:
                driver: pdo_sqlite           # or pdo_mysql in production
                path: '%kernel.project_dir%/var/landlord.db'
                server_version: '3.45'
            tenant:
                driver: pdo_sqlite           # overridden at runtime by TenantConnection
                path: '%kernel.project_dir%/var/placeholder.db'
                wrapper_class: Tenancy\Bundle\DBAL\TenantConnection
    orm:
        default_entity_manager: landlord
        entity_managers:
            landlord:
                connection: landlord
                mappings:
                    TenancyBundle:
                        is_bundle: false
                        type: attribute
                        dir: '%kernel.project_dir%/vendor/danplaton4/tenancy-bundle/src/Entity'
                        prefix: 'Tenancy\Bundle\Entity'
                        alias: TenancyBundle
            tenant:
                connection: tenant
                mappings:
                    App:
                        is_bundle: false
                        type: attribute
                        dir: '%kernel.project_dir%/src/Entity'
                        prefix: 'App\Entity'
                        alias: App
```

**DoctrineBundle service ID pattern (HIGH confidence, from DoctrineExtension.php:1244):**

```
doctrine.orm.{name}_entity_manager
```

Therefore:
- Landlord EM: `doctrine.orm.landlord_entity_manager`
- Tenant EM:   `doctrine.orm.tenant_entity_manager`
- Default EM alias: `doctrine.orm.entity_manager` → landlord (since `default_entity_manager: landlord`)

### Pattern 4: EntityManagerResetListener

**What:** Listens on `TenantContextCleared` event, calls `$managerRegistry->resetManager('tenant')`.

**Why `resetManager()` works (HIGH confidence, from vendor source):**

DoctrineBundle declares the abstract EM as `->lazy()` (`config/orm.php:3rd line`). Each named EM inherits this. The Symfony bridge `ManagerRegistry::resetService()` uses `ReflectionClass::resetAsLazyGhost()` (PHP 8.4+) or `LazyObjectInterface::resetLazyObject()` depending on PHP version — both require the service to be lazy-declared. DoctrineBundle satisfies this requirement.

```php
#[AsEventListener(event: TenantContextCleared::class)]
final class EntityManagerResetListener
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
    ) {}

    public function __invoke(TenantContextCleared $event): void
    {
        $this->managerRegistry->resetManager('tenant');
    }
}
```

**`resetManager()` vs `clear()` distinction:**
- `EntityManager::clear()` — clears identity map only, EM stays open
- `resetManager('tenant')` — closes EM and recreates a fresh lazy ghost; required by success criteria ISOL-01 item 4

### Pattern 5: Bundle Config — `tenancy.database.enabled`

Add to `TenancyBundle::configure()`:

```php
->arrayNode('database')
    ->addDefaultsIfNotSet()
    ->children()
        ->booleanNode('enabled')->defaultFalse()->end()
    ->end()
->end()
```

Add to `TenancyBundle::loadExtension()` (conditional wiring):

```php
if ($config['database']['enabled'] ?? false) {
    $services->set('tenancy.database_switch_bootstrapper', DatabaseSwitchBootstrapper::class)
        ->args([service('doctrine.dbal.tenant_connection')])
        ->tag('tenancy.bootstrapper');
    $services->set(EntityManagerResetListener::class)
        ->autoconfigure(true)
        ->args([service('doctrine')]);
}
```

### Pattern 6: DoctrineTenantProvider Rewiring

In `config/services.php`, change:

```php
// Before (Phase 2):
service('doctrine.orm.default_entity_manager')

// After (Phase 3):
service('doctrine.orm.landlord_entity_manager')
```

This is also controlled by `tenancy.database.enabled`: only rewire when the flag is `true`. When `false`, the default EM is used (Phase 2 behavior).

### Anti-Patterns to Avoid

- **Using `close()` without updating params first:** Calling `close()` then `connect()` without changing `$params` reconnects to the same database.
- **Calling `$this->connect()` directly from subclass:** `connect()` is protected and lazy — let DBAL call it naturally on the next query.
- **Overriding `connect()` to inject params:** While `connect()` is overridable, it cannot access `$this->params` (private). Instead, update params first via reflection, then let the parent `connect()` run.
- **Re-calling `parent::__construct()` as the params update method:** Works but creates a new Configuration object each time and violates single responsibility. ReflectionProperty is cleaner.
- **Accessing `getParams()` with `@internal` warning ignored by PHPStan:** Mark usages with `@internal` suppression comment or accept the PHPStan noise. The method remains public in DBAL 4.4.2.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Lazy entity manager service | Custom lazy proxy mechanism | DoctrineBundle's built-in `->lazy()` service declaration | DoctrineBundle already declares EMs as lazy; `resetManager()` depends on it |
| EM identity map clearing | Custom entity tracking | `ManagerRegistry::resetManager('tenant')` | Handles closed EMs, lazy ghost reset, and full EM recreation in one call |
| Connection close + reconnect lifecycle | Custom connection lifecycle | DBAL 4's `close()` + lazy `connect()` pattern | DBAL manages connection pool, exceptions, autocommit; don't replicate |
| DBAL driver middleware | Custom middleware for param injection | wrapperClass subclass | wrapperClass is the documented extension point; middleware is for query-level interception |

**Key insight:** DBAL 4 intentionally encapsulates connection params as an internal implementation detail. The wrapperClass + ReflectionProperty approach is the de-facto community standard for runtime switching; there is no first-class public API for it.

---

## Common Pitfalls

### Pitfall 1: SQLite `:memory:` Fails for Two-Connection Tests

**What goes wrong:** Configuring both landlord and tenant test connections as `sqlite:///:memory:` results in a single shared in-memory database — both connections hit the same tables, making tenant isolation tests meaningless.

**Why it happens:** Each PDO connection to `:memory:` creates an independent SQLite in-memory database. SQLite's `file::memory:?cache=shared` URI would solve this, but PDO does not support this URI scheme (confirmed by DBAL issue #2901 — closed "won't fix").

**How to avoid:** Use file-based SQLite for integration tests with unique temp paths per test:

```php
// In test kernel or setUp():
$slugA = 'tenant_a_' . uniqid();
$slugB = 'tenant_b_' . uniqid();
$pathA = sys_get_temp_dir() . '/' . $slugA . '.db';
$pathB = sys_get_temp_dir() . '/' . $slugB . '.db';
// Register with TenantConnection::switchTenant(['path' => $pathA])
// Clean up in tearDown(): unlink($pathA), unlink($pathB)
```

**Warning signs:** Tests pass even when tenant switching is broken (both queries hit the same DB).

### Pitfall 2: DBAL 4 EventManager Parameter Removed

**What goes wrong:** Copying a DBAL 3 `TenantConnection` implementation that passes `EventManager` as 4th constructor argument fails with a type error or constructor mismatch in DBAL 4.

**Why it happens:** DBAL 4 removed `EventManager` from the `Connection` constructor (confirmed from `vendor/doctrine/dbal/src/Connection.php:120`). Old community examples and blog posts use the DBAL 3 signature.

**How to avoid:** Use the DBAL 4 signature exactly: `(array $params, Driver $driver, ?Configuration $config = null)`. No EventManager.

**Warning signs:** PHPStan errors on constructor argument count in `TenantConnection`.

### Pitfall 3: `resetManager()` Throws on Non-Lazy EM

**What goes wrong:** Calling `$managerRegistry->resetManager('tenant')` throws `\LogicException: Resetting a non-lazy manager service is not supported. Declare the "..." service as lazy.`

**Why it happens:** If the `tenant` EM definition in DoctrineBundle is not lazy, `resetService()` cannot reinitialize it. This would happen if the EM were declared as eager (non-lazy) in a custom DI definition.

**How to avoid:** Never override DoctrineBundle's EM service definition manually to remove `->lazy()`. Let DoctrineBundle own the EM service declaration. Verify with `bin/console debug:container doctrine.orm.tenant_entity_manager --show-arguments`.

**Warning signs:** Exception in `EntityManagerResetListener` during `kernel.terminate`.

### Pitfall 4: TenantBundle prependExtension Doctrine Mapping Conflicts

**What goes wrong:** The bundle's `prependExtension()` currently prepends a `TenancyBundle` mapping to the default `orm.mappings`. With dual EMs, this mapping must target the `landlord` entity manager, not the `default` or `tenant` EM.

**Why it happens:** `prependExtension()` prepends to `doctrine.orm.mappings` globally — DoctrineBundle assigns it to the default EM. If `default_entity_manager` changes from `default` to `landlord`, the mapping follows correctly. But if the user sets `default_entity_manager: tenant`, the `Tenant` entity would map to the wrong EM.

**How to avoid:** In `prependExtension()`, prepend specifically to the `landlord` EM mapping config, not the top-level ORM mappings. Use the explicit EM structure:

```php
$builder->prependExtensionConfig('doctrine', [
    'orm' => [
        'entity_managers' => [
            'landlord' => [
                'mappings' => [
                    'TenancyBundle' => [/* ... */],
                ],
            ],
        ],
    ],
]);
```

**Warning signs:** `Tenant` entity not found in landlord EM, or `Class ... is not a valid entity or mapped superclass` from tenant EM.

### Pitfall 5: `@phpstan-consistent-constructor` and Subclass Constructor

**What goes wrong:** Adding constructor parameters to `TenantConnection` that don't exist on the parent `Connection` fails PHPStan's `@phpstan-consistent-constructor` check.

**Why it happens:** The `Connection` class is annotated `@phpstan-consistent-constructor`, meaning PHPStan enforces compatible constructor signatures across subclasses and `new static(...)` call sites.

**How to avoid:** `TenantConnection`'s constructor must accept exactly `(array $params, Driver $driver, ?Configuration $config = null)`. Store the `ReflectionProperty` instance in a regular private property initialized in the constructor body (not as a constructor parameter).

**Warning signs:** PHPStan level 6+ reports errors in `TenantConnection` constructor.

### Pitfall 6: `getParams()` Marked `@internal` Triggers PHPStan

**What goes wrong:** Calling `$this->getParams()` inside `TenantConnection::switchTenant()` triggers a PHPStan `@internal` violation at level 5+.

**Why it happens:** `getParams()` is `@internal` per DBAL maintainers (issue #5213 confirms this is intentional).

**How to avoid:** Inside `TenantConnection` (which is the class that *owns* the internal method call since it extends `Connection`), calling `getParams()` is acceptable — the `@internal` warning applies to *external callers*, not to the class hierarchy. OR: use the cached `$this->originalParams` snapshot for the merge instead of calling `getParams()`. This avoids the PHPStan issue entirely.

**Warning signs:** PHPStan reports `Method Doctrine\DBAL\Connection::getParams() is @internal`.

---

## Code Examples

Verified patterns from vendor source inspection:

### TenantConnection: Full Skeleton

```php
// Source: vendor/doctrine/dbal/src/Connection.php inspection (DBAL 4.4.2)
declare(strict_types=1);

namespace Tenancy\Bundle\DBAL;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Configuration;

final class TenantConnection extends Connection
{
    private readonly array $originalParams;
    private readonly \ReflectionProperty $paramsReflector;

    public function __construct(
        #[\SensitiveParameter]
        array $params,
        Driver $driver,
        ?Configuration $config = null,
    ) {
        parent::__construct($params, $driver, $config);
        $this->originalParams = $params;
        // Cache reflector once — ReflectionProperty is cheap to construct but not zero-cost
        $this->paramsReflector = new \ReflectionProperty(Connection::class, 'params');
    }

    /**
     * Switch the connection to the given tenant's database parameters.
     * Merges tenant-specific keys over the original (landlord) params.
     *
     * @param array<string, mixed> $tenantConnectionConfig  Keys: driver, host, port, dbname, user, password, etc.
     */
    public function switchTenant(array $tenantConnectionConfig): void
    {
        $merged = array_merge($this->originalParams, $tenantConnectionConfig);
        $this->paramsReflector->setValue($this, $merged);
        $this->close(); // drops $_conn; next query triggers lazy connect() with new params
    }

    /**
     * Restore the connection to its original (landlord) parameters.
     */
    public function reset(): void
    {
        $this->paramsReflector->setValue($this, $this->originalParams);
        $this->close();
    }
}
```

### DatabaseSwitchBootstrapper

```php
// Source: TenantBootstrapperInterface contract (src/Bootstrapper/TenantBootstrapperInterface.php)
declare(strict_types=1);

namespace Tenancy\Bundle\Bootstrapper;

use Tenancy\Bundle\DBAL\TenantConnection;
use Tenancy\Bundle\TenantInterface;

final class DatabaseSwitchBootstrapper implements TenantBootstrapperInterface
{
    public function __construct(
        private readonly TenantConnection $tenantConnection,
    ) {}

    public function boot(TenantInterface $tenant): void
    {
        $this->tenantConnection->switchTenant($tenant->getConnectionConfig());
    }

    public function clear(): void
    {
        $this->tenantConnection->reset();
    }
}
```

### EntityManagerResetListener

```php
// Source: AsEventListener pattern from Phase 1/2 (TenantContextOrchestrator)
declare(strict_types=1);

namespace Tenancy\Bundle\EventListener;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Tenancy\Bundle\Event\TenantContextCleared;

#[AsEventListener(event: TenantContextCleared::class)]
final class EntityManagerResetListener
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
    ) {}

    public function __invoke(TenantContextCleared $event): void
    {
        $this->managerRegistry->resetManager('tenant');
    }
}
```

### DoctrineBundle Service IDs (for services.php)

```php
// Confirmed from DoctrineExtension.php:1244 — sprintf('doctrine.orm.%s_entity_manager', $name)
service('doctrine.orm.landlord_entity_manager')  // for DoctrineTenantProvider
service('doctrine.orm.tenant_entity_manager')    // for EntityManagerResetListener if needed
service('doctrine')                               // ManagerRegistry — the standard alias
service('doctrine.dbal.tenant_connection')        // DBAL connection service ID (DoctrineBundle convention)
```

### Integration Test Kernel — Dual-EM Setup

```php
// Based on TestKernel.php pattern from Phase 2
class DoctrineTestKernel extends Kernel
{
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            // FrameworkBundle config...
            $container->loadFromExtension('doctrine', [
                'dbal' => [
                    'default_connection' => 'landlord',
                    'connections' => [
                        'landlord' => [
                            'driver' => 'pdo_sqlite',
                            'path'   => sys_get_temp_dir() . '/tenancy_landlord_test.db',
                        ],
                        'tenant' => [
                            'driver'        => 'pdo_sqlite',
                            'path'          => sys_get_temp_dir() . '/tenancy_tenant_placeholder.db',
                            'wrapper_class' => TenantConnection::class,
                        ],
                    ],
                ],
                'orm' => [
                    'default_entity_manager' => 'landlord',
                    'entity_managers' => [
                        'landlord' => [
                            'connection' => 'landlord',
                            'mappings'   => ['TenancyBundle' => [/* ... */]],
                        ],
                        'tenant' => [
                            'connection' => 'tenant',
                            'mappings'   => ['TestApp' => [/* ... */]],
                        ],
                    ],
                ],
            ]);
        });
    }
}
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| DBAL 3: `Connection($params, $driver, $config, $eventManager)` 4-arg constructor | DBAL 4: `Connection($params, $driver, $config)` — EventManager removed | DBAL 4.0 (Feb 2024) | Copy-paste from DBAL 3 community examples breaks silently |
| DBAL 3: `$this->_params` protected, subclass access was possible | DBAL 4: `$this->params` private — subclass access requires ReflectionProperty | DBAL 4.0 | The main implementation challenge for this phase |
| Pre-DBAL 4: `Connection::refresh()` method for reconnection | DBAL 4: `refresh()` removed; use `close()` + lazy `connect()` | DBAL 3 (deprecated), removed DBAL 4 | `refresh()` calls fail with "method not found" in DBAL 4 |
| DoctrineBundle 1.x: monolithic single EM | DoctrineBundle 2.x: multi-EM as first-class config | DoctrineBundle 2.0+ | Standard YAML config supports named EMs; no compiler pass needed |

**Deprecated/outdated:**
- `Connection::refresh()`: removed in DBAL 4 — do not reference
- `Connection::$_params` (protected in DBAL 2/3): changed to private in DBAL 4 — reflection required
- EventManager in `Connection` constructor: removed in DBAL 4

---

## Open Questions

1. **PHPStan level at which `@internal getParams()` fires**
   - What we know: `getParams()` is marked `@internal` per DBAL source; using it inside `TenantConnection` (a subclass) may or may not trigger PHPStan depending on configuration
   - What's unclear: Project's PHPStan level (not yet configured; Phase 9 introduces PHPStan level 9)
   - Recommendation: Avoid `getParams()` entirely in `switchTenant()` — use `$this->originalParams` for the merge base instead. This sidesteps the `@internal` concern completely.

2. **`prependExtension()` EM mapping target**
   - What we know: Current `prependExtension()` in `TenancyBundle` prepends to `doctrine.orm.mappings` globally. DoctrineBundle assigns this to the default EM. When `default_entity_manager` is `landlord`, the `Tenant` entity maps to the `landlord` EM — which is correct.
   - What's unclear: Whether `prependExtensionConfig` for `orm.mappings` (flat) merges correctly with the nested `orm.entity_managers.landlord.mappings` structure, or whether we need to prepend to the specific EM mappings key.
   - Recommendation: Research in Plan 03-03; test both approaches empirically. The nested key approach is safer.

3. **`TenantConnection` service registration and DBAL's internal constructor**
   - What we know: DoctrineBundle creates connections via `DriverManager::getConnection()` using the `wrapperClass` param — not via Symfony DI. The `TenantConnection` object is the DBAL connection service.
   - What's unclear: Whether `services.php` needs an explicit `TenantConnection` definition, or whether DoctrineBundle creates it internally and we just reference the DBAL connection service alias `doctrine.dbal.tenant_connection`.
   - Recommendation: Do NOT register `TenantConnection` in `services.php` — DoctrineBundle owns connection creation. Reference it as `service('doctrine.dbal.tenant_connection')` in `DatabaseSwitchBootstrapper` args.

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x |
| Config file | `phpunit.xml.dist` (root) |
| Quick run command | `./vendor/bin/phpunit --testsuite=unit` |
| Full suite command | `./vendor/bin/phpunit` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| ISOL-01 | `TenantConnection::switchTenant()` changes connection params and reconnects | unit | `./vendor/bin/phpunit tests/Unit/DBAL/TenantConnectionTest.php -x` | ❌ Wave 0 |
| ISOL-01 | After `setTenant($tenantA)`, queries hit Tenant A's DB; after `setTenant($tenantB)`, Tenant B's DB | integration | `./vendor/bin/phpunit tests/Integration/DatabaseSwitchIntegrationTest.php -x` | ❌ Wave 0 |
| ISOL-01 | `TenantConnection::reset()` restores original params | unit | `./vendor/bin/phpunit tests/Unit/DBAL/TenantConnectionTest.php::testReset -x` | ❌ Wave 0 |
| ISOL-02 | Landlord EM is unaffected by tenant switches | integration | `./vendor/bin/phpunit tests/Integration/DatabaseSwitchIntegrationTest.php::testLandlordEmUnaffected -x` | ❌ Wave 0 |
| ISOL-02 | `resetManager('tenant')` closes EM and returns fresh instance | integration | `./vendor/bin/phpunit tests/Integration/EntityManagerResetIntegrationTest.php -x` | ❌ Wave 0 |
| ISOL-01+02 | `DatabaseSwitchBootstrapper` calls `switchTenant()` on `boot()` and `reset()` on `clear()` | unit | `./vendor/bin/phpunit tests/Unit/Bootstrapper/DatabaseSwitchBootstrapperTest.php -x` | ❌ Wave 0 |

### Sampling Rate

- **Per task commit:** `./vendor/bin/phpunit --testsuite=unit`
- **Per wave merge:** `./vendor/bin/phpunit`
- **Phase gate:** Full suite green before `/gsd:verify-work`

### Wave 0 Gaps

- [ ] `tests/Unit/DBAL/TenantConnectionTest.php` — covers ISOL-01 (switchTenant, reset, reflection)
- [ ] `tests/Unit/Bootstrapper/DatabaseSwitchBootstrapperTest.php` — covers boot/clear delegation
- [ ] `tests/Integration/DatabaseSwitchIntegrationTest.php` — covers cross-tenant query isolation with file-based SQLite
- [ ] `tests/Integration/EntityManagerResetIntegrationTest.php` — covers resetManager('tenant') behavior
- [ ] `tests/Integration/DoctrineTestKernel.php` — dual-EM test kernel with file-based SQLite

No missing framework config or shared fixtures; `phpunit.xml.dist` and `tests/bootstrap.php` already exist.

---

## Sources

### Primary (HIGH confidence)

- `vendor/doctrine/dbal/src/Connection.php` (DBAL 4.4.2) — constructor signature, `$params` private, `close()`, `connect()` protected
- `vendor/doctrine/dbal/src/DriverManager.php` (DBAL 4.4.2) — `wrapperClass` handling confirmed
- `vendor/doctrine/doctrine-bundle/config/orm.php` — `->lazy()` on abstract EM definition confirmed
- `vendor/symfony/doctrine-bridge/ManagerRegistry.php` — `resetService()` requires lazy service; `resetManager()` flow
- `vendor/doctrine/persistence/src/Persistence/AbstractManagerRegistry.php` — `resetManager()` calls `resetService()` then `getManager()`
- `vendor/doctrine/doctrine-bundle/src/DependencyInjection/DoctrineExtension.php:1244` — service ID format `doctrine.orm.{name}_entity_manager`
- `src/TenancyBundle.php`, `src/Bootstrapper/BootstrapperChain.php`, `config/services.php`, `src/EventListener/TenantContextOrchestrator.php` — Phase 1/2 integration surface confirmed

### Secondary (MEDIUM confidence)

- [Symfony Docs: Multiple Entity Managers](https://symfony.com/doc/current/doctrine/multiple_entity_managers.html) — confirmed YAML configuration pattern for dual-EM
- [Karol Dąbrowski: Dynamic database connection](https://karoldabrowski.com/blog/dynamic-database-connection-based-on-request-symfony-and-doctrine/) — confirmed `parent::__construct()` re-call pattern (older approach); DBAL 3 era
- [DBAL Issue #5213: getParams() @internal](https://github.com/doctrine/dbal/issues/5213) — confirms DBAL maintainers' position on connection params being internal

### Tertiary (LOW confidence — informational only)

- [Getparthenon TenantConnection](https://github.com/getparthenon/parthenon) — DBAL 3 era; override `connect()` approach (not directly applicable to DBAL 4 private params)
- [mapeveri/multi-tenancy-bundle](https://github.com/mapeveri/multi-tenancy-bundle) — deprecated/unmaintained; DBAL 2/3 era
- [DBAL Issue #2901: SQLite shared memory](https://github.com/doctrine/dbal/issues/2901) — PDO limitation on SQLite in-memory shared cache confirmed (issue closed "won't fix")

---

## Metadata

**Confidence breakdown:**
- DBAL 4 `$params` private + reflection approach: HIGH — confirmed from installed vendor source (DBAL 4.4.2)
- DBAL 4 constructor signature (no EventManager): HIGH — confirmed from vendor source
- `resetManager()` + lazy EM requirement: HIGH — confirmed from vendor source (doctrine-bridge + doctrine-bundle)
- DoctrineBundle service ID format: HIGH — confirmed from DoctrineExtension.php source
- SQLite `:memory:` two-connection limitation: HIGH — confirmed by DBAL maintainers (issue closed "won't fix")
- Dual-EM YAML config: MEDIUM-HIGH — confirmed from Symfony docs + DoctrineExtension source

**Research date:** 2026-03-19
**Valid until:** 2026-06-19 (stable libraries — 90-day estimate)
