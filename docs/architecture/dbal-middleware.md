# Database-Per-Tenant Connection Switching

The bundle's `database_per_tenant` driver rotates the tenant DBAL connection's socket on
every tenant switch — without rebuilding the container, without vendor-private-property
reflection, and without subclassing `Doctrine\DBAL\Connection`. It uses Doctrine DBAL 4's
`Doctrine\DBAL\Driver\Middleware` extension point.

## The Pipeline

1. **At container compile time:** `TenantDriverMiddleware` is registered with the
   `doctrine.middleware` tag scoped to the `tenant` connection (tag attribute
   `connection: tenant`). DoctrineBundle's `MiddlewaresPass` picks it up and attaches it to
   `doctrine.dbal.tenant_connection.configuration` via `setMiddlewares()`. The landlord
   connection is not tagged — it never sees the middleware.

2. **At DriverManager time (Connection construction):** DBAL's
   `DriverManager::getConnection()` resolves the tenant connection's driver from the
   placeholder params, then walks the middleware chain. `TenantDriverMiddleware::wrap($driver)`
   returns a `TenantAwareDriver` that holds the wrapped driver + a reference to
   `TenantContext`.

3. **On first query (lazy connect):** DBAL's `Connection::connect()` (protected, internal)
   calls `$this->driver->connect($this->params)`. `$this->driver` is `TenantAwareDriver`.
   `TenantAwareDriver::connect($params)` reads the active tenant, merges its
   `getConnectionConfig()` over `$params` (tenant keys win), and delegates to
   `parent::connect($mergedParams)`.

4. **On tenant switch:** `DatabaseSwitchBootstrapper::boot()` calls
   `$connection->close()`. That nulls the internal `_conn` reference. The next query
   re-enters step 3 with fresh `TenantContext` state — a new socket to the new tenant DB.

## Why close() alone suffices

`Connection::close()` implementation (DBAL 4):

```php
public function close(): void
{
    $this->_conn                   = null;
    $this->transactionNestingLevel = 0;
}
```

The surrounding `Connection` object is not discarded — every DI holder (EntityManager,
repositories, migrations config) keeps the same `Connection` instance. Only the socket
rotates. This is why `EntityManagerResetListener::resetManager()` + `$connection->close()`
is the minimal surface to switch tenants.

## Considered and rejected: connection subclass + private-property reflection

A prior v0.1 design extended `Doctrine\DBAL\Connection` as a bundle-owned subclass,
registered via a DoctrineBundle tenant-connection YAML option that tells `DriverManager`
to instantiate a custom `Connection` subclass. The subclass mutated `Connection::$params`
via private-property reflection and then called `close()` to force a reconnect.

Problems:

- `Connection::$driver` is resolved at construction time and **frozen** — mutating
  `$params` by reflection cannot change the driver. If the placeholder uses the SQLite
  URL form but tenants are MySQL, queries are still handed to the SQLite driver.
- Using a matching driver family (e.g. MySQL placeholder) works in practice but is brittle
  — any change in DBAL's internal `$params` handling could break the reflection approach.
- Private-property reflection against a vendor class is a maintenance trap: the bundle's
  correctness hinges on a vendor implementation detail that is outside the documented
  contract.

The middleware architecture avoids all three problems. The driver wraps the real driver
transparently; `$params` is never mutated (merged per-`connect()` instead); and the only
vendor contract the bundle depends on is the public
`Doctrine\DBAL\Driver\Middleware` interface.

## Tenant `getConnectionConfig()` rules

Return discrete DBAL params — never a `url` key. DBAL parses `url` at DriverManager time,
**before** middlewares run. Tenant-side `url` keys in the merged array are effectively
ignored.

Good:

```php
['dbname' => 'tenant_acme', 'host' => 'db-acme.internal']
```

Bad:

```php
['url' => 'mysql://user:pass@db-acme.internal/tenant_acme']
```

## Driver family requirement

The landlord placeholder on the tenant connection and the tenant databases must share the
same driver family (e.g. `pdo_mysql` placeholder for MySQL tenants, `pdo_pgsql` for
PostgreSQL tenants). `TenantAwareDriver::connect()` merges params at connect-time, but the
driver itself is resolved from the placeholder at container boot and cannot be rotated by
a tenant's config.

## Connection Lifecycle Diagram

```
Container boot
    │
    ▼
TenantDriverMiddleware::wrap($driver)   ← applied on tenant connection only
    │
    └─ returns TenantAwareDriver($driver, $tenantContext)

kernel.request (tenant resolved)
    │
    ▼
DatabaseSwitchBootstrapper::boot($tenant)
    │
    └── $connection->close()   ← nulls internal _conn

[Application issues tenant query]
    │
    ▼
Connection::connect()
    │
    └── $this->driver->connect($params)
            │
            ▼
        TenantAwareDriver::connect($params)
            ├── $merged = array_merge($params, $tenantContext->getTenant()->getConnectionConfig())
            └── parent::connect($merged)   ← opens new socket to tenant DB

kernel.terminate
    │
    ▼
DatabaseSwitchBootstrapper::clear()
    │
    └── $connection->close()   ← socket to tenant DB closed
```

## Thread Safety and Long-Running Processes

The `Connection` object is shared state within the DI container. `close()` clears only the
internal driver-connection; `TenantAwareDriver` reads the current `TenantContext` on every
`connect()`. This is safe for classic PHP (one request = one process = one tenant) but
requires care in long-running processes:

| Scenario | Behavior |
|----------|----------|
| HTTP request (PHP-FPM) | Safe — one tenant per process lifetime |
| Symfony Messenger worker | Safe — `TenantWorkerMiddleware` calls `clear()` in `finally` between messages |
| Swoole/ReactPHP coroutines | **Not safe** — multiple coroutines share the same DI container |

For Swoole or other async PHP runtimes, each coroutine needs its own `TenantContext` (and
therefore its own Connection). This requires a coroutine-scoped DI container, which is
outside the scope of this bundle.

## See Also

- `CHANGELOG.md` §0.2.0 — retrospective on the v0.1 connection-subclass → middleware
  migration (why the wrapper-subclass approach was pulled)
- `UPGRADE.md` §0.1 → 0.2 — migration recipe for forks that extended the v0.1 tenant
  connection subclass
- [Event Lifecycle](event-lifecycle.md) — where `DatabaseSwitchBootstrapper` runs
- [DI Compilation Pipeline](di-compilation.md) — where the middleware is tagged
