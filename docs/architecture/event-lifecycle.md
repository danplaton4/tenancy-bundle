# Event Lifecycle

This page traces the full lifecycle of a tenant-aware HTTP request from arrival to teardown. Every transition maps directly to source code in `TenantContextOrchestrator`, `ResolverChain`, `BootstrapperChain`, and the three lifecycle events.

## The Five Stages

```
Request → [Stage 1: Intercept] → [Stage 2: Resolve] → [Stage 3: Boot]
       → [Stage 4: Application Runs] → [Stage 5: Teardown]
```

---

## Stage 1: Request Interception

`TenantContextOrchestrator` is registered as a `kernel.request` listener at **priority 20**:

```php
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: TenantContextOrchestrator::PRIORITY)]
#[AsEventListener(event: KernelEvents::TERMINATE, method: 'onKernelTerminate')]
final class TenantContextOrchestrator
{
    /** Priority 20: after Router (32), before Security firewall (8). */
    public const PRIORITY = 20;
}
```

**Why priority 20?**

Symfony's built-in listeners run at these priorities:

| Priority | Listener |
|----------|----------|
| 32 | Router (resolves route parameters) |
| **20** | **TenantContextOrchestrator** |
| 8 | Security firewall |
| 0 | Everything else |

The orchestrator must run:

- **After the Router (32)** — so that the resolved route is available to resolvers that key on route attributes
- **Before Security (8)** — so that controllers receive fully-tenanted services when they are constructed. If tenancy ran after security, controllers that inject tenant-scoped services in their constructors would receive un-tenanted state

Only the main request triggers tenant resolution. Sub-requests (e.g. ESI fragments rendered via `HttpKernel::handle()`) are skipped:

```php
public function onKernelRequest(RequestEvent $event): void
{
    if (!$event->isMainRequest()) {
        return;
    }
    // ...
}
```

---

## Stage 2: Resolution

`ResolverChain::resolve(Request $request)` iterates all registered resolvers in tag-priority order:

```php
public function resolve(Request $request): array
{
    foreach ($this->resolvers as $resolver) {
        $tenant = $resolver->resolve($request);
        if (null !== $tenant) {
            return [
                'tenant' => $tenant,
                'resolvedBy' => $resolver::class,
            ];
        }
    }
    throw new TenantNotFoundException('No resolver could identify a tenant from the current request.');
}
```

**Resolution rules:**

- Each resolver receives the `Request` and returns `?TenantInterface`
- First non-null result wins — subsequent resolvers are not called
- The return type is `array{tenant: TenantInterface, resolvedBy: string}`, where `resolvedBy` is the FQCN of the resolver that succeeded
- If every resolver returns `null`, `TenantNotFoundException` is thrown (strict mode) or the request proceeds without tenant context (when you have a fallback handler)

After resolution, `TenantContext::setTenant()` stores the resolved tenant in the stateful context object, and `BootstrapperChain::boot()` is called.

**Event: `TenantResolved`** is dispatched *after* bootstrapping completes (see source order in `TenantContextOrchestrator::onKernelRequest()`):

```php
$result = $this->resolverChain->resolve($event->getRequest());
$this->tenantContext->setTenant($result['tenant']);
$this->bootstrapperChain->boot($result['tenant']);
$this->eventDispatcher->dispatch(
    new TenantResolved($result['tenant'], $event->getRequest(), $result['resolvedBy'])
);
```

---

## Stage 3: Bootstrapping

`BootstrapperChain::boot(TenantInterface $tenant)` iterates all registered bootstrappers:

```php
public function boot(TenantInterface $tenant): void
{
    $fqcns = [];
    foreach ($this->bootstrappers as $bootstrapper) {
        $bootstrapper->boot($tenant);
        $fqcns[] = $bootstrapper::class;
    }
    $this->eventDispatcher->dispatch(new TenantBootstrapped($tenant, $fqcns));
}
```

Each bootstrapper's `boot(TenantInterface $tenant)` method is called in tag-priority order (set by `BootstrapperChainPass`). Built-in bootstrappers:

| Bootstrapper | Responsibility |
|---|---|
| `DatabaseSwitchBootstrapper` | Calls `TenantConnection::switchTenant()` — swaps DBAL connection params |
| `SharedDriver` | Injects `TenantContext` into `TenantAwareFilter` for shared-DB mode |
| `DoctrineBootstrapper` | Clears the EntityManager identity map on each request |
| `TenantAwareCacheAdapter` | Namespaces the cache pool with the tenant slug |

The chain records the FQCN of every bootstrapper that ran.

**Event: `TenantBootstrapped`** is dispatched after all bootstrappers have completed, carrying the tenant and the list of bootstrapper FQCNs that ran.

After this point, the application runs with full tenant context.

---

## Stage 4: Application Execution

During this stage, controllers, services, and Doctrine queries operate in the tenant context:

- `TenantContext::getTenant()` returns the active tenant for any service that reads it
- DBAL queries use the tenant's database connection (database-per-tenant mode)
- Doctrine queries have `WHERE tenant_id = 'slug'` appended automatically (shared-DB mode)
- Cache reads and writes use a tenant-namespaced pool

`TenantContext` is read-only at this point — only `TenantContextOrchestrator` calls `setTenant()` and `clear()`.

---

## Stage 5: Teardown

When the kernel finishes sending the response, it fires `KernelEvents::TERMINATE`. The orchestrator handles this:

```php
public function onKernelTerminate(TerminateEvent $event): void
{
    if (!$this->tenantContext->hasTenant()) {
        return;
    }

    $this->bootstrapperChain->clear();
    $this->tenantContext->clear();
    $this->eventDispatcher->dispatch(new TenantContextCleared());
}
```

**Teardown sequence:**

1. `BootstrapperChain::clear()` — runs each bootstrapper's `clear()` method in **reverse** order of `boot()`. If bootstrappers A, B, C ran in that order, teardown runs C → B → A. This mirrors the stack-unwind pattern used by middleware and database transactions.
2. `TenantContext::clear()` — sets `$currentTenant = null`
3. **Event: `TenantContextCleared`** — dispatched as a signal-only event (no payload). Listeners use this for cleanup tasks like resetting connection pools or flushing metrics.

!!! note "Teardown only fires if a tenant was active"
    If no tenant was resolved (e.g. the request matched a public route), `hasTenant()` returns false and the entire teardown block is skipped. `TenantContextCleared` is not dispatched.

---

## Event Reference

| Event | Class | Payload | Dispatched When | Typical Use Cases |
|-------|-------|---------|-----------------|-------------------|
| `TenantResolved` | `Tenancy\Bundle\Event\TenantResolved` | `tenant`, `request`, `resolvedBy` | After bootstrapping completes | Audit logging, metrics, analytics |
| `TenantBootstrapped` | `Tenancy\Bundle\Event\TenantBootstrapped` | `tenant`, `bootstrappers[]` | After all bootstrappers' `boot()` run | Post-boot hooks, lazy initialization |
| `TenantContextCleared` | `Tenancy\Bundle\Event\TenantContextCleared` | _(none)_ | After all bootstrappers' `clear()` run | Cleanup, connection pooling, flushing |

### TenantResolved

```php
final class TenantResolved
{
    public function __construct(
        public readonly TenantInterface $tenant,
        public readonly ?Request $request,  // null when dispatched from worker context
        public readonly string $resolvedBy,  // FQCN of the winning resolver
    ) {}
}
```

### TenantBootstrapped

```php
final class TenantBootstrapped
{
    /**
     * @param string[] $bootstrappers FQCNs of bootstrappers that ran (in order)
     */
    public function __construct(
        public readonly TenantInterface $tenant,
        public readonly array $bootstrappers,
    ) {}
}
```

### TenantContextCleared

```php
final class TenantContextCleared
{
    // Empty — a signal event with no payload
}
```

---

## Console Commands

Console commands have no HTTP `Request` object. The `ConsoleResolver` operates independently of `TenantContextOrchestrator`:

- Listens on `ConsoleCommandEvent` (not `kernel.request`)
- Reads the `--tenant=<slug>` flag injected by `ConsoleResolver`
- Calls `BootstrapperChain::boot()` directly after resolution
- The lifecycle events still fire in the same order: `TenantResolved` → `TenantBootstrapped`

See [CLI Commands](../user-guide/cli-commands.md) for usage details.

---

## Lifecycle Diagram

```
kernel.request (priority 20)
        │
        ▼
ResolverChain::resolve(Request)
        │
        ├── resolver 1 (priority 30): HostResolver
        ├── resolver 2 (priority 20): HeaderResolver
        └── resolver N: first non-null wins
                │
                ▼
        TenantContext::setTenant($tenant)
                │
                ▼
        BootstrapperChain::boot($tenant)
                │
                ├── bootstrapper 1::boot()
                ├── bootstrapper 2::boot()
                └── bootstrapper N::boot()
                        │
                        ▼
                dispatch: TenantBootstrapped
                        │
                        ▼
                dispatch: TenantResolved
                        │
                        ▼
              [Application runs in tenant context]
                        │
                        ▼
        kernel.terminate
                │
                ▼
        BootstrapperChain::clear()      ← reverse order
                │
                ├── bootstrapper N::clear()
                ├── bootstrapper 2::clear()
                └── bootstrapper 1::clear()
                        │
                        ▼
        TenantContext::clear()
                │
                ▼
        dispatch: TenantContextCleared
```
