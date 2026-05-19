# Phase 5: Infrastructure Bootstrappers - Research

**Researched:** 2026-03-19
**Domain:** Doctrine EntityManager lifecycle, Symfony Cache namespace isolation
**Confidence:** HIGH

## Summary

Phase 5 delivers two always-on infrastructure services that protect against the two most common cross-tenant data leak vectors: the Doctrine identity map and the cache pool.

`DoctrineBootstrapper` is a straightforward `TenantBootstrapperInterface` implementation that calls `EntityManager::clear()` in both `boot()` and `clear()`. It is structurally identical to `DatabaseSwitchBootstrapper` — a `final class` with a single constructor-injected dependency (`EntityManagerInterface`). It always targets `doctrine.orm.default_entity_manager`, which is the correct EM in both driver modes. This phase also fixes a pre-existing bug in `EntityManagerResetListener`: `resetManager('tenant')` must become `resetManager()` (no argument) to work in `shared_db` mode where only a default EM exists.

`TenantAwareCacheAdapter` decorates `cache.app` with per-tenant namespace isolation using `NamespacedPoolInterface::withSubNamespace()`. The critical finding from source inspection is that `withSubNamespace()` returns a **clone** of the adapter — it cannot be called once at construction time and reused across requests. The adapter must call `withSubNamespace(slug)` on every cache operation and delegate to the resulting scoped clone. When no tenant is active, it delegates directly to the undecorated inner pool. Because all cache state management is live (reads `TenantContext` on every call), `TenantAwareCacheAdapter` does NOT implement `TenantBootstrapperInterface` — it is wired as a pure Symfony DI service decorator.

**Primary recommendation:** Implement `DoctrineBootstrapper` as a direct copy of `DatabaseSwitchBootstrapper`'s structure, substitute `EM::clear()` for connection switching, and implement `TenantAwareCacheAdapter` as a `CacheItemPoolInterface`/`AdapterInterface` wrapper that calls `pool->withSubNamespace(slug)` on every delegation.

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**DoctrineBootstrapper**
- Scope: `EM::clear()` only — no SQL filter involvement. SQL filter is SharedDriver's domain.
- Target EM: Always clear `doctrine.orm.default_entity_manager` — driver-agnostic.
- `boot(TenantInterface $tenant)`: calls `EntityManager::clear()`.
- `clear()`: calls `EntityManager::clear()` — belt-and-suspenders alongside `EntityManagerResetListener::resetManager()`.
- Location: `src/Bootstrapper/DoctrineBootstrapper.php`.
- Registration: Always registered in `services.php` — no driver-conditional wiring.

**EntityManagerResetListener (existing — must fix)**
- Bug: Currently calls `resetManager('tenant')` — hardcoded to the named 'tenant' EM.
- Fix: Change to `resetManager()` (no argument) — resets the default EM. Correct in both modes.
- Do NOT remove or absorb into DoctrineBootstrapper — two-step teardown (clear then reset) is intentional.

**CacheBootstrapper / TenantAwareCacheAdapter**
- Mechanism: `NamespacedPoolInterface::withSubNamespace(string $namespace)` — adapter-level isolation, NOT key prefixing.
- Namespace key: Tenant slug (`TenantInterface::getSlug()`).
- No-tenant fallback: No-op — when no tenant is active, delegate to the undecorated `cache.app` pool. Do NOT throw.
- Pool scope: `cache.app` only. No multi-pool config in v1.
- Location: `src/Cache/TenantAwareCacheAdapter.php`.

**Bootstrapper activation**
- Both `DoctrineBootstrapper` and cache adapter are always registered — no driver-conditional wiring.
- Zero extra configuration for users.

### Claude's Discretion

- Exact `TenantAwareCacheAdapter` implementation — proxy that reads TenantContext live vs. static swap; researcher must verify against `NamespacedPoolInterface` and `withSubNamespace()` semantics.
- Whether `TenantAwareCacheAdapter` implements `TenantBootstrapperInterface` (if boot/clear are truly no-ops) or is registered as a pure Symfony service decorator.
- Priority values for `tenancy.bootstrapper` tag on `DoctrineBootstrapper` (must be lower priority than drivers so drivers run first).
- Whether `DoctrineBootstrapper` implements `TenantDriverInterface` (marker) or just `TenantBootstrapperInterface` — it's not a driver, so `TenantBootstrapperInterface` directly is correct.

### Deferred Ideas (OUT OF SCOPE)

- Multi-pool cache decoration: Allow users to specify a list of additional cache pools to namespace beyond `cache.app` — v1.1.
- TenantAwareCacheAdapter for custom pools: v1.1.
- Cache invalidation command: `tenancy:cache:clear {tenantId}` — v1.1 (CLI-03 candidate).
- CacheBootstrapper as explicit bootstrapper: Potential rename to "decorator" or "adapter" — v1.1.
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| BOOT-01 | Doctrine bootstrapper calls `EntityManager::clear()` on every tenant context switch to prevent identity map pollution. (Note: BOOT-01 text in REQUIREMENTS.md also mentions SQL filter — that part is already complete in Phase 4; this phase only covers EM::clear.) | `EntityManager::clear()` wipes the UoW identity map immediately. Combined with `resetManager()` in `EntityManagerResetListener`, provides belt-and-suspenders identity map isolation. |
| BOOT-02 | Cache bootstrapper isolates tenant cache at the namespace level by decorating the `cache.app` pool with a per-tenant namespace (not a key-prefix hack). | `NamespacedPoolInterface::withSubNamespace(slug)` returns a cloned adapter scoped to `"{rootNamespace}{slug}:"`. All Symfony adapters extending `AbstractAdapter` implement this. Decorator wired via `->decorate('cache.app')` in services.php. |
</phase_requirements>

---

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `doctrine/orm` | (project-installed) | `EntityManager::clear()` wipes UoW identity map | The isolation mechanism required by BOOT-01 |
| `doctrine/persistence` | (transitive) | `ManagerRegistry::resetManager()` in `EntityManagerResetListener` | Already used; `resetManager()` with no arg resets default EM |
| `symfony/cache` | 7.4.7 (installed) | `AdapterInterface`, `NamespacedPoolInterface::withSubNamespace()` | Core cache isolation API |
| `symfony/cache-contracts` | 3.6.0 (installed) | `NamespacedPoolInterface` interface | Interface the decorator must implement |

### No New Dependencies

Phase 5 introduces zero new `composer require` entries. All required interfaces and classes are already present in the installed `symfony/cache` 7.4.7 and `doctrine/orm`.

**Version verification:** Confirmed against vendor directory — symfony/cache 7.4.7 released 2026-03-06, symfony/cache-contracts 3.6.0.

## Architecture Patterns

### Recommended Project Structure
```
src/
├── Bootstrapper/
│   ├── DatabaseSwitchBootstrapper.php  (existing)
│   └── DoctrineBootstrapper.php        (NEW — Phase 5)
├── Cache/                               (NEW directory — Phase 5)
│   └── TenantAwareCacheAdapter.php     (NEW — Phase 5)
└── EventListener/
    └── EntityManagerResetListener.php  (existing — fix resetManager bug)
```

### Pattern 1: Boot/Clear Bootstrapper (DoctrineBootstrapper)

**What:** `final class` implementing `TenantBootstrapperInterface` directly (not `TenantDriverInterface` — DoctrineBootstrapper is not a driver). Constructor-injects `EntityManagerInterface`. Both `boot()` and `clear()` call `EntityManager::clear()`.

**When to use:** Any always-on infrastructure that must react to tenant lifecycle events via the `BootstrapperChain`.

**Example:**
```php
// Source: Derived from DatabaseSwitchBootstrapper pattern (src/Bootstrapper/DatabaseSwitchBootstrapper.php)
// and CONTEXT.md decisions
final class DoctrineBootstrapper implements TenantBootstrapperInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function boot(TenantInterface $tenant): void
    {
        $this->em->clear();
    }

    public function clear(): void
    {
        $this->em->clear();
    }
}
```

**DI wiring (services.php):**
```php
$services->set('tenancy.doctrine_bootstrapper', DoctrineBootstrapper::class)
    ->args([service('doctrine.orm.default_entity_manager')])
    ->tag('tenancy.bootstrapper', ['priority' => 0]);
```

**Priority note:** Drivers (`DatabaseSwitchBootstrapper`, `SharedDriver`) must run first (connection switch / filter inject). `DoctrineBootstrapper` runs after drivers — assign a lower numeric priority value (Symfony tag priority: higher number = runs first). Drivers currently have no explicit priority tag, so use priority 0 for DoctrineBootstrapper (same as untagged services). If drivers are also at 0, consider using a negative priority like -10 to ensure DoctrineBootstrapper runs after driver boot. The planner should confirm actual tag priorities on existing drivers.

### Pattern 2: Live-Delegating Cache Decorator (TenantAwareCacheAdapter)

**What:** `final class` implementing `AdapterInterface` (which extends `CacheItemPoolInterface`) and `NamespacedPoolInterface`. Holds a reference to the inner `AdapterInterface` (the real `cache.app`) and `TenantContext`. On every `CacheItemPoolInterface` method call, resolves the active pool: if a tenant is active, calls `innerPool->withSubNamespace(slug)` to get a scoped clone, then delegates to it; otherwise delegates directly to `innerPool`.

**Critical finding from source inspection:** `withSubNamespace()` is implemented in `AbstractAdapterTrait::withSubNamespace()` at line 252 as `clone $this` with `$clone->namespace .= $namespace . NS_SEPARATOR`. This means:
1. Every call returns a NEW adapter instance (the clone) — it is cheap (no I/O) but is object allocation.
2. The clone shares the same underlying storage backend as the original.
3. Keys written via the clone are stored as `"{root_namespace}{slug}:{key}"` in the backend.
4. `clear()` on the clone only clears keys in `"{root_namespace}{slug}:"` namespace — other tenants' keys are untouched. This is the BOOT-02 isolation guarantee.

**When to use:** Any service that wraps a shared Symfony cache pool and needs per-request tenant scoping without boot/clear lifecycle hooks.

**Example (core delegation logic):**
```php
// Source: Derived from NamespacedPoolInterface contract (vendor/symfony/cache-contracts/NamespacedPoolInterface.php)
// and withSubNamespace() implementation (vendor/symfony/cache/Traits/AbstractAdapterTrait.php:252)
final class TenantAwareCacheAdapter implements AdapterInterface, NamespacedPoolInterface
{
    public function __construct(
        private readonly AdapterInterface $inner,
        private readonly TenantContext $tenantContext,
    ) {
    }

    private function pool(): AdapterInterface
    {
        if ($this->tenantContext->hasTenant()) {
            // withSubNamespace() returns a clone — cheap, no I/O
            return $this->inner->withSubNamespace($this->tenantContext->getTenant()->getSlug());
        }
        return $this->inner;
    }

    public function getItem(mixed $key): CacheItem
    {
        return $this->pool()->getItem($key);
    }

    // ... all other CacheItemPoolInterface + AdapterInterface methods delegate to $this->pool()
}
```

**withSubNamespace() on the decorator itself:**
```php
public function withSubNamespace(string $namespace): static
{
    $clone = clone $this;
    $clone->inner = $this->inner->withSubNamespace($namespace);
    return $clone;
}
```

**DI wiring (services.php):**
```php
use Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;

$services->set('tenancy.cache_adapter', TenantAwareCacheAdapter::class)
    ->decorate('cache.app')
    ->args([
        service('.inner'),      // Symfony inner service placeholder
        service('tenancy.context'),
    ]);
```

The `service('.inner')` reference is the Symfony DI convention for the decorated service's inner instance when using `->decorate()`.

### Pattern 3: EntityManagerResetListener Bug Fix

**What:** Change `resetManager('tenant')` to `resetManager()` (no argument). `ManagerRegistry::resetManager(null)` resets the default entity manager. This is the correct call in both driver modes:
- `database_per_tenant` mode: default EM IS the tenant EM (PhaseDoc: there is a 'tenant' named EM, but `resetManager()` still works as the driver is also wired to `doctrine.orm.default_entity_manager`)
- `shared_db` mode: only default EM exists — `resetManager('tenant')` would throw

Actually, review the `EntityManagerResetIntegrationTest`: line 55 calls `$registry->resetManager('tenant')` directly — this is the named EM in `database_per_tenant` mode. The listener currently calls `resetManager('tenant')` to reset that named EM. In Phase 5 the fix changes to `resetManager()` to reset the default EM instead. The unit test `testInvokeResetsTenantEntityManager()` asserts `with('tenant')` — this test MUST be updated to assert `with(null)` after the fix.

**Full scope of fix:**
1. `src/EventListener/EntityManagerResetListener.php`: `resetManager('tenant')` → `resetManager()`.
2. `tests/Unit/EventListener/EntityManagerResetListenerTest.php`: Update both test methods that assert `->with('tenant')` to assert `->with(null)`.
3. `tests/Integration/EntityManagerResetIntegrationTest.php`: Review whether any direct `resetManager('tenant')` calls in test setUp need updating (setUp uses `resetManager('tenant')` to get the fresh EM for SQLite path — this is test infrastructure for `database_per_tenant` mode; it is NOT the listener under test and should remain as-is for that specific integration test).

### Anti-Patterns to Avoid

- **Calling `withSubNamespace()` once in the constructor:** The returned clone has the namespace baked into its state. Calling it in the constructor would create a static adapter for ONE tenant slug forever. Must be called live on every operation.
- **Key prefixing instead of `withSubNamespace()`:** Manually prepending `"tenantSlug:"` to cache keys does NOT provide namespace-level isolation. `clear()` on the pool would clear all tenants. `withSubNamespace()` uses backend-native versioning mechanisms.
- **DoctrineBootstrapper implementing TenantDriverInterface:** `TenantDriverInterface` is a marker for isolation *drivers* (connection-switching, filter-injecting). `DoctrineBootstrapper` is infrastructure plumbing, not a driver. It implements `TenantBootstrapperInterface` directly.
- **Absorbing EntityManagerResetListener into DoctrineBootstrapper:** The two-step teardown (immediate `clear()` + deferred `resetManager()` on `TenantContextCleared`) provides stronger guarantees. Do not merge them.
- **Throwing when no tenant is active in TenantAwareCacheAdapter:** Console commands, cache warmup, and cron jobs run without tenant context. The adapter MUST degrade gracefully to the global pool.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Cache namespace isolation | Custom key-prefix string manipulation | `NamespacedPoolInterface::withSubNamespace()` | Key prefixing doesn't isolate `clear()` — deleting one tenant's keys would delete all. `withSubNamespace()` uses backend-level namespace versioning |
| Cache pool decoration | Manual `implements CacheItemPoolInterface` with all 9 methods written by hand | Delegate all methods to `$this->pool()` which calls `withSubNamespace()` | The resolved pool clone already implements the full interface correctly — just proxy to it |
| EM identity map isolation | Custom entity tracking / per-tenant EM registry | `EntityManager::clear()` | `clear()` is the canonical API to wipe the UoW identity map; anything custom risks missing lazy-loaded associations |

**Key insight:** `withSubNamespace()` is the canonical Symfony API for exactly this use case. It was designed for adapter-level namespace isolation and is implemented correctly in all standard adapters. No custom namespace logic is needed.

## Common Pitfalls

### Pitfall 1: withSubNamespace() Returns a Clone, Not a Singleton

**What goes wrong:** Developer calls `withSubNamespace(slug)` in `__construct` and stores the result. All cache operations then use a fixed namespace from construction time — switching tenants mid-request has no effect.

**Why it happens:** `withSubNamespace()` looks like a configuration method. Its `static` return type suggests "configure this adapter." The clone semantic is only visible in the source.

**How to avoid:** Always call `$this->inner->withSubNamespace($slug)` inside a private `pool()` method that is called per-operation. Never store the result of `withSubNamespace()` across requests.

**Warning signs:** Cache keys from different tenants appearing under the same namespace in Redis/APCu inspection.

### Pitfall 2: EntityManagerResetListener Unit Test Uses `->with('tenant')`

**What goes wrong:** After fixing `resetManager('tenant')` to `resetManager()`, the unit test `EntityManagerResetListenerTest::testInvokeResetsTenantEntityManager()` still asserts `->with('tenant')` and passes — because the test is testing the OLD behavior. The bug is fixed in production but the test still validates the broken state.

**Why it happens:** The test was written for the original `database_per_tenant`-only implementation.

**How to avoid:** Update both test methods in `EntityManagerResetListenerTest` to assert `->with(null)` (PHPUnit `with()` passes `null` as the argument when `resetManager()` is called with no argument, since `ManagerRegistry::resetManager(?string $name = null)` has `null` as default).

**Warning signs:** Unit test passing but integration test in `shared_db` mode failing with "No entity manager named 'tenant'".

### Pitfall 3: `cache.app` May Not Implement `AdapterInterface`

**What goes wrong:** In some Symfony configurations, `cache.app` resolves to a `TagAwareAdapter` wrapping another adapter, or to a `TraceableAdapter` (in dev). The decorator receives a `CacheItemPoolInterface` where it expects `AdapterInterface` with `getItem(): CacheItem` return type.

**Why it happens:** `cache.app` type depends on environment and configuration. In production it is typically a `FilesystemAdapter` or `RedisAdapter` (both extend `AbstractAdapter`). In dev, it may be wrapped in `TraceableAdapter`.

**How to avoid:** Type-hint the constructor on `AdapterInterface` (not the more restrictive types). Since all standard Symfony adapters implement `AdapterInterface`, this covers all production configurations. For `withSubNamespace()` to work, also verify the inner pool implements `NamespacedPoolInterface` — all `AbstractAdapter` descendants do.

**Warning signs:** `TypeError: Argument 1 passed to TenantAwareCacheAdapter::__construct() must be of type AdapterInterface, TagAwareAdapter given` — resolve by ensuring DI injects the unwrapped pool, or accept `CacheItemPoolInterface` and cast where needed.

### Pitfall 4: BootstrapperChain Priority Order

**What goes wrong:** `DoctrineBootstrapper::boot()` clears the EM before the driver has switched the connection (in `database_per_tenant` mode). `EM::clear()` runs against the old DB, then the connection switches — no harm done. But `DoctrineBootstrapper::clear()` MUST run AFTER `TenantContext::clear()` and BEFORE or alongside `EntityManagerResetListener` for belt-and-suspenders to be meaningful.

**Why it happens:** `tenancy.bootstrapper` tag priority controls `BootstrapperChain` order. Higher priority = runs first.

**How to avoid:** Assign `DoctrineBootstrapper` a lower priority than drivers (e.g., priority 0 while drivers are at 10 or above). For `clear()`, `BootstrapperChain::clear()` uses `array_reverse($this->bootstrappers)` (confirmed from source) — lower-priority bootstrappers (added later) run FIRST in clear. `DoctrineBootstrapper` at priority -10 will be added last to the array, so it runs FIRST in clear — wiping the EM before drivers reset their connections. This is the correct order.

**Warning signs:** Identity map not being cleared before first query for new tenant — detectable via `EM::getUnitOfWork()->getIdentityMap()` inspection in tests.

### Pitfall 5: `ManagerRegistry::resetManager()` Signature

**What goes wrong:** `resetManager()` called with no argument may fail if the signature requires a non-null parameter in some Doctrine versions.

**Why it happens:** `Doctrine\Persistence\ManagerRegistry::resetManager(?string $name = null)` — the default is `null` which resets the default manager. This is the correct call.

**How to avoid:** Verify against installed `doctrine/persistence` vendor signature. Confirmed in project: `doctrine/persistence` is a transitive dependency of `doctrine/orm`.

**Warning signs:** `TypeError: Too few arguments to function`.

## Code Examples

Verified patterns from actual vendor source:

### withSubNamespace() — What It Actually Does
```php
// Source: vendor/symfony/cache/Traits/AbstractAdapterTrait.php:252
public function withSubNamespace(string $namespace): static
{
    $this->rootNamespace ??= $this->namespace;

    $clone = clone $this;
    $clone->namespace .= CacheItem::validateKey($namespace) . static::NS_SEPARATOR;

    return $clone;
}
```
Key facts:
- Returns `clone $this` — NEW object, shares same backend storage.
- Appends `"{slug}:"` to existing `$this->namespace` — result is `"{root}{slug}:"`.
- `NS_SEPARATOR` is `':'` in `AbstractAdapter`.

### NamespacedPoolInterface Contract
```php
// Source: vendor/symfony/cache-contracts/NamespacedPoolInterface.php:30
public function withSubNamespace(string $namespace): static;
// Throws InvalidArgumentException if namespace contains RESERVED_CHARACTERS
```

### Symfony DI Decorator Pattern (services.php fluent API)
```php
// Source: vendor/symfony/dependency-injection/Loader/Configurator/Traits/DecorateTrait.php:28
// Usage in services.php:
$services->set('tenancy.cache_adapter', TenantAwareCacheAdapter::class)
    ->decorate('cache.app')
    ->args([
        service('tenancy.cache_adapter.inner'),  // auto-generated inner service id
        service('tenancy.context'),
    ]);
```
When `->decorate('cache.app')` is called, Symfony renames the original `cache.app` to `tenancy.cache_adapter.inner` (or the id you provide as second argument to `->decorate()`).

### CacheItemPoolInterface Methods to Delegate
```php
// All 9 PSR-6 CacheItemPoolInterface methods + AdapterInterface additions:
// getItem($key), getItems($keys), hasItem($key), clear(), deleteItem($key),
// deleteItems($keys), save($item), saveDeferred($item), commit()
// AdapterInterface adds: getItem return type CacheItem, getItems return type iterable<string, CacheItem>, clear(string $prefix = '')
```

### EntityManagerResetListener Fix
```php
// BEFORE (buggy — only works in database_per_tenant mode):
$this->managerRegistry->resetManager('tenant');

// AFTER (fix — works in both driver modes):
$this->managerRegistry->resetManager();
// ManagerRegistry::resetManager(?string $name = null) — null resets default EM
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Key prefixing (`"tenant_slug:key"`) | `withSubNamespace()` adapter-level isolation | Added to Symfony contracts | `clear()` only clears the tenant's namespace, not all tenants |
| `resetManager('tenant')` | `resetManager()` (default EM) | Phase 5 fix | Works in both `database_per_tenant` and `shared_db` modes |
| Single-step EM teardown | Two-step: `EM::clear()` then `resetManager()` | Phase 5 introduces belt-and-suspenders | Immediate identity map wipe + deferred full EM recreation |

## Open Questions

1. **Does `cache.app` in test kernels implement `AdapterInterface`?**
   - What we know: `SharedDbTestKernel` uses FrameworkBundle with `test: true` — this sets `cache.app` to `cache.adapter.array` (array adapter) in test environments. `ArrayAdapter` extends `AbstractAdapter` — it implements both `AdapterInterface` and `NamespacedPoolInterface`.
   - What's unclear: Whether `TraceableAdapter` wrapping in dev mode affects the decorator chain in integration tests.
   - Recommendation: In `CacheBootstrapperIntegrationTest`, use a real `ArrayAdapter` directly or boot a test kernel that does NOT set `test: true` for the cache pool. The integration test needs to write and read cache items — `ArrayAdapter` works fine for this.

2. **Priority value for `DoctrineBootstrapper` bootstrapper tag** (RESOLVED)
   - Confirmed: `BootstrapperChainPass` uses `PriorityTaggedServiceTrait::findAndSortTaggedServices()` — higher priority = first in array = first in `boot()`.
   - Confirmed: `BootstrapperChain::clear()` uses `array_reverse()` — lower-priority bootstrappers run first in `clear()`.
   - Confirmed: `DatabaseSwitchBootstrapper` and `SharedDriver` have no explicit priority tag (default 0).
   - Recommendation: Assign `DoctrineBootstrapper` priority -10. In `boot()`, drivers (priority 0) run before DoctrineBootstrapper (-10) — connection switch happens first, then EM::clear(). In `clear()`, DoctrineBootstrapper (-10, appended last) runs first — EM::clear() fires before driver reset.

3. **`AdapterInterface` vs `CacheItemPoolInterface` for decorator type hint**
   - What we know: `cache.app` resolves to an `AdapterInterface` in standard Symfony configurations.
   - What's unclear: If DI injects a `TagAwareAdapter` (which also implements `NamespacedPoolInterface`), the `AdapterInterface` type hint still works since `TagAwareAdapter` implements it.
   - Recommendation: Type-hint constructor on `AdapterInterface` (Symfony-specific) not `CacheItemPoolInterface` (PSR-6) to allow calling `AdapterInterface::clear(string $prefix = '')`.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11 |
| Config file | `phpunit.xml.dist` |
| Quick run command | `vendor/bin/phpunit --testsuite unit` |
| Full suite command | `vendor/bin/phpunit` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| BOOT-01 | `DoctrineBootstrapper::boot()` calls `EM::clear()` | unit | `vendor/bin/phpunit tests/Unit/Bootstrapper/DoctrineBootstrapperTest.php` | ❌ Wave 0 |
| BOOT-01 | `DoctrineBootstrapper::clear()` calls `EM::clear()` | unit | `vendor/bin/phpunit tests/Unit/Bootstrapper/DoctrineBootstrapperTest.php` | ❌ Wave 0 |
| BOOT-01 | `EntityManagerResetListener` calls `resetManager()` (no arg, default EM) | unit | `vendor/bin/phpunit tests/Unit/EventListener/EntityManagerResetListenerTest.php` | ✅ (needs update) |
| BOOT-01 | Identity map isolation: entity loaded for Tenant A is not returned for Tenant B | integration | `vendor/bin/phpunit tests/Integration/DoctrineBootstrapperIntegrationTest.php` | ❌ Wave 0 |
| BOOT-02 | Cache key written under Tenant A is not readable under Tenant B | integration | `vendor/bin/phpunit tests/Integration/CacheBootstrapperIntegrationTest.php` | ❌ Wave 0 |
| BOOT-02 | Clearing Tenant A's namespace does not invalidate Tenant B's keys | integration | `vendor/bin/phpunit tests/Integration/CacheBootstrapperIntegrationTest.php` | ❌ Wave 0 |
| BOOT-02 | `TenantAwareCacheAdapter` decorates `cache.app` (DI wiring) | unit | `vendor/bin/phpunit tests/Unit/Cache/TenantAwareCacheAdapterTest.php` | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `vendor/bin/phpunit --testsuite unit`
- **Per wave merge:** `vendor/bin/phpunit`
- **Phase gate:** Full suite green before `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Unit/Bootstrapper/DoctrineBootstrapperTest.php` — covers BOOT-01 unit assertions
- [ ] `tests/Unit/Cache/TenantAwareCacheAdapterTest.php` — covers BOOT-02 unit assertions
- [ ] `tests/Integration/DoctrineBootstrapperIntegrationTest.php` — covers BOOT-01 identity map isolation
- [ ] `tests/Integration/CacheBootstrapperIntegrationTest.php` — covers BOOT-02 namespace isolation

## Sources

### Primary (HIGH confidence)
- `vendor/symfony/cache-contracts/NamespacedPoolInterface.php` — exact `withSubNamespace()` interface contract
- `vendor/symfony/cache/Traits/AbstractAdapterTrait.php:252` — `withSubNamespace()` implementation; returns clone, appends to namespace
- `vendor/symfony/cache/Adapter/AbstractAdapter.php` — implements `NamespacedPoolInterface`; `NS_SEPARATOR = ':'`
- `vendor/symfony/cache/Adapter/TagAwareAdapter.php:285` — `withSubNamespace()` delegates to inner pool clone
- `vendor/symfony/cache/Adapter/AdapterInterface.php` — `clear(string $prefix = '')` signature
- `vendor/symfony/dependency-injection/Loader/Configurator/Traits/DecorateTrait.php:28` — `->decorate()` API for services.php
- `src/Bootstrapper/DatabaseSwitchBootstrapper.php` — canonical bootstrapper pattern to follow
- `src/Bootstrapper/TenantBootstrapperInterface.php` — interface DoctrineBootstrapper implements
- `src/EventListener/EntityManagerResetListener.php` — existing listener with `resetManager('tenant')` bug
- `src/Context/TenantContext.php` — `hasTenant()` and `getTenant()->getSlug()` verified present
- `src/TenantInterface.php` — `getSlug(): string` return type confirmed
- `config/services.php` — DI wiring conventions and patterns
- `src/TenancyBundle.php` — `loadExtension()` where new services are registered

### Secondary (MEDIUM confidence)
- Phase 4 SharedDriver pattern — `clear()` as documented no-op precedent; DoctrineBootstrapper diverges intentionally
- Integration test patterns (`SharedDbFilterIntegrationTest`, `EntityManagerResetIntegrationTest`) — test kernel structure and setUp/tearDown patterns for Phase 5 tests

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — all packages confirmed in vendor/ at specific versions
- Architecture: HIGH — `withSubNamespace()` implementation read directly from source; decorator pattern confirmed via `DecorateTrait`
- `withSubNamespace()` clone semantics: HIGH — implementation read directly from `AbstractAdapterTrait`
- Bootstrapper pattern: HIGH — `DatabaseSwitchBootstrapper` is the canonical reference, read directly
- Pitfalls: HIGH — derived directly from source inspection and existing test patterns
- Priority tag values: HIGH — `BootstrapperChainPass` (PriorityTaggedServiceTrait, descending) and `BootstrapperChain::clear()` (array_reverse) confirmed from source

**Research date:** 2026-03-19
**Valid until:** 2026-04-19 (symfony/cache 7.x API is stable; withSubNamespace() contract is sealed)
