# DBAL Wrapper Mechanics

`TenantConnection` is a DBAL 4 `wrapperClass` subclass that switches database connections at runtime without rebuilding the DI container. This page explains the internal mechanics: why `ReflectionProperty` is used, how `switchTenant()` and `reset()` work, and the design trade-offs.

## Overview

DBAL 4 supports `wrapperClass` — a Doctrine configuration option that tells `DriverManager` to instantiate a custom subclass of `Doctrine\DBAL\Connection` instead of the base class. `TenantConnection` exploits this:

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        connections:
            tenant:
                url: '%env(DATABASE_URL)%'
                wrapper_class: Tenancy\Bundle\DBAL\TenantConnection
```

Because `TenantConnection` is a drop-in subclass, all services holding a reference to the connection automatically use the new database parameters on their next query — without any service rebuilding or reference invalidation.

---

## Why ReflectionProperty?

DBAL 4 made the `$params` property **private** on `Doctrine\DBAL\Connection`. There is no public setter. There are three possible approaches to runtime parameter mutation:

| Approach | Problem |
|----------|---------|
| Public setter on `Connection` | Not available in DBAL 4 — `$params` is private |
| Create a new `Connection` object | Breaks all DI references that hold the old instance |
| DBAL connection event hook | DBAL has no suitable event for parameter mutation |
| **`ReflectionProperty` mutation** | **Works — `$params` layout stable since DBAL 2** |

`TenantConnection` uses `ReflectionProperty` to read and write the private `$params` property directly:

```php
final class TenantConnection extends Connection implements TenantConnectionInterface
{
    private readonly array $originalParams;
    private readonly \ReflectionProperty $paramsReflector;

    public function __construct(array $params, Driver $driver, ?Configuration $config = null)
    {
        parent::__construct($params, $driver, $config);
        $this->originalParams = $params;
        $this->paramsReflector = new \ReflectionProperty(Connection::class, 'params');
    }
}
```

The `ReflectionProperty` instance is created once in the constructor and reused. This avoids the overhead of creating a new `ReflectionProperty` on every connection switch.

!!! warning "Conscious design decision — not a hack"
    Using `ReflectionProperty` to access a private property is intentional here, not a workaround. The alternative — creating a new `Connection` instance on each tenant switch — would invalidate every DI service reference holding the old connection. The `$params` property has existed on `Doctrine\DBAL\Connection` since DBAL 2. The bundle's CI matrix tests against DBAL 3/4 to catch any upstream breakage.

---

## switchTenant() Flow

`DatabaseSwitchBootstrapper::boot()` calls `switchTenant()` with the tenant's connection configuration:

```php
public function boot(TenantInterface $tenant): void
{
    $this->tenantConnection->switchTenant($tenant->getConnectionConfig());
}
```

Inside `TenantConnection::switchTenant()`:

```php
public function switchTenant(array $tenantConnectionConfig): void
{
    $merged = array_merge($this->originalParams, $tenantConnectionConfig);
    $this->paramsReflector->setValue($this, $merged);
    $this->close();
}
```

**Step by step:**

1. **Merge tenant config over original params** — `array_merge($this->originalParams, $tenantConnectionConfig)`. The tenant config contains only the keys that differ (e.g. `dbname`, `host`). The original params provide defaults for everything else (driver, charset, port).
2. **Write merged params to `Connection::$params`** — `$this->paramsReflector->setValue($this, $merged)`. This mutates the private property directly.
3. **Close the current PDO connection** — `$this->close()`. DBAL's connection is lazy — closing it does not throw an error even if no connection was open. The next `$em->find(...)` or `$connection->query(...)` call triggers a fresh PDO connection using the new params.

**Result:** All services holding a reference to this connection object now see the tenant's database on their next query.

---

## reset() Flow

`DatabaseSwitchBootstrapper::clear()` calls `reset()` to restore the landlord connection:

```php
public function clear(): void
{
    $this->tenantConnection->reset();
}
```

Inside `TenantConnection::reset()`:

```php
public function reset(): void
{
    $this->paramsReflector->setValue($this, $this->originalParams);
    $this->close();
}
```

**Step by step:**

1. **Restore original params** — `$this->originalParams` were captured in the constructor from the placeholder connection config (e.g. `%env(DATABASE_URL)%` resolved at boot time).
2. **Close the tenant PDO connection** — forces reconnect to the landlord database on next query.

**originalParams** are immutable (`readonly`). They represent the "factory default" state — the connection config as Doctrine knew it at container boot time.

---

## Why Not Create a New Connection?

Creating a new `Doctrine\DBAL\Connection` instance on each tenant switch would require:

1. Injecting the `DriverManager` into `DatabaseSwitchBootstrapper`
2. Replacing the service definition in the DI container at runtime (not possible without a container rebuild)
3. Updating every service that holds a reference to the old connection

The wrapperClass pattern avoids all of this. All services receive the same connection object reference at container build time. When the connection is mutated via `switchTenant()`, they automatically use the new database on their next query — no rebinding needed.

---

## Thread Safety and Long-Running Processes

The connection object is **shared state** within the DI container. `switchTenant()` mutates shared state — this is safe for classic PHP (one request = one process = one tenant) but requires care in long-running processes:

| Scenario | Behavior |
|----------|----------|
| HTTP request (PHP-FPM) | Safe — one tenant per process lifetime |
| Symfony Messenger worker | Safe — `TenantWorkerMiddleware` calls `clear()` in `finally` between messages |
| Swoole/ReactPHP coroutines | **Not safe** — multiple coroutines share the same DI container |

For Swoole or other async PHP runtimes, each coroutine needs its own `TenantConnection` instance. This requires a coroutine-scoped DI container, which is outside the scope of this bundle's v1.

---

## TenantConnectionInterface

`DatabaseSwitchBootstrapper` depends on `TenantConnectionInterface`, not `TenantConnection` directly:

```php
interface TenantConnectionInterface
{
    public function switchTenant(array $config): void;
    public function reset(): void;
}
```

This interface exists to enable unit testing — `DatabaseSwitchBootstrapper` can be tested with a mock `TenantConnectionInterface` without instantiating the full Doctrine `Connection` class hierarchy.

---

## Connection Lifecycle Diagram

```
Container boot
    │
    ▼
TenantConnection constructed
    ├── parent::__construct($params)       ← DBAL stores as Connection::$params
    ├── $this->originalParams = $params    ← captured snapshot (immutable)
    └── $this->paramsReflector = new ReflectionProperty(Connection::class, 'params')

kernel.request (tenant resolved)
    │
    ▼
DatabaseSwitchBootstrapper::boot($tenant)
    │
    ▼
TenantConnection::switchTenant($tenant->getConnectionConfig())
    ├── $merged = array_merge($originalParams, $tenantConfig)
    ├── $paramsReflector->setValue($this, $merged)  ← Connection::$params now = tenant
    └── $this->close()                              ← lazy reconnect on next query

[Application queries tenant DB]

kernel.terminate (teardown)
    │
    ▼
DatabaseSwitchBootstrapper::clear()
    │
    ▼
TenantConnection::reset()
    ├── $paramsReflector->setValue($this, $originalParams)  ← restore landlord
    └── $this->close()
```
