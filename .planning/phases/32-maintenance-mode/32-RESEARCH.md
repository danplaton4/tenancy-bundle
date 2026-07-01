# Phase 32: Maintenance Mode — Research

**Researched:** 2026-07-01
**Domain:** Per-tenant maintenance mode — Symfony kernel.request listener, Doctrine entity trait, CLI commands, compiler pass, allow-list, events.
**Confidence:** HIGH — all findings grounded in live v0.4.1 source reads.

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- D-01: Built-in hardcoded HTML is the default 503 body — no Twig on the hot path.
- D-02: Custom-template override = `tenancy.maintenance.template`; Twig renders only when configured; if render throws, fall back to hardcoded HTML.
- D-03: `Retry-After` from global config `tenancy.maintenance.retry_after` (int seconds, default 3600). `Cache-Control: no-store` always set.
- D-04: Content-negotiated body — JSON (`{"status":"maintenance","retryAfter":N}`) or HTML. Status + headers identical in both branches.
- D-05: One bool column `$inMaintenance` on `AbstractTenant` via new `TenantMaintenanceConfigTrait`; `TenantInterface` gains exactly `isInMaintenance(): bool`; trait provides `false` default.
- D-06: Global config block `tenancy.maintenance.{allow_ips,allow_routes,allow_paths}`.
- D-07: Matching semantics — OR across three: `IpUtils::checkIp()`, exact `_route` match, `str_starts_with` path prefix.
- D-08: Idempotent enable/disable; events fire only on real state transition.
- D-09: Single slug per command; no `--all`.
- D-10: `tenancy:maintenance:status` lists tenants in maintenance as table; `--format=json` for CI parity.

### Claude's Discretion
- Persistence path: enable/disable via `TenantProviderInterface::findBySlug()`, flip bool, persist via landlord EntityManager. MUST NOT call `BootstrapperChain::boot()` or set `TenantContext`.
- Exact class names and namespace placement (`TenantMaintenanceModeListener`, `TenantMaintenanceConfigTrait`, `MaintenanceModeContractPass`, three command classes, two event classes).
- Config schema under `maintenance` node in `getConfigTreeBuilder()` / `configure()`. Whether `enabled` flag is included (always-on is defensible; feature-flag matches convention).

### Deferred Ideas (OUT OF SCOPE)
- Per-tenant maintenance message, per-tenant `Retry-After`, `?DateTimeImmutable $inMaintenanceUntil` auto-expiry.
- Per-tenant custom 503 template selection.
- Per-tenant allow-lists.
- Global/site-wide (all-tenants) maintenance mode; `--all` variadic enable.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| MAINT-01 | `tenancy:maintenance:enable <slug>` puts single tenant in maintenance | D-08/D-09; `findBySlug()` + landlord EM flush; see API signatures below |
| MAINT-02 | `tenancy:maintenance:disable <slug>` restores tenant | Same pattern as MAINT-01; idempotent |
| MAINT-03 | Request to maintenance tenant returns 503 + `Retry-After` + `Cache-Control: no-store` | Priority-16 listener, `$event->setResponse()`, headers always set |
| MAINT-04 | Landlord, public, health routes never blocked | `!hasTenant()` early return; allow-list OR check |
| MAINT-05 | Maintenance state stored on tenant entity, persists across processes, no cross-tenant leak | DB column on `AbstractTenant`; resolved fresh each request (see cache coherence finding) |
| MAINT-06 | IP / route / path allow-list bypasses maintenance | D-06/D-07; `IpUtils`, `_route`, `str_starts_with` |
| MAINT-07 | App can override 503 with custom Twig template | D-02; `tenancy.maintenance.template` config key; HTML fallback if render throws |
| MAINT-08 | `TenantMaintenanceEnabled` / `TenantMaintenanceDisabled` events on toggle | Dispatched only on real state change; readonly-constructor event pattern |
| MAINT-09 | `tenancy:maintenance:status` lists in-maintenance tenants | D-10; `--format=json`; `findAll()` + filter |
</phase_requirements>

---

## Summary

The design specified in CONTEXT.md is fully buildable against the live v0.4.1 codebase. All locked decisions map cleanly to existing source patterns. No mismatches between decisions and live source were found.

**The definitive answer to the cache coherence question** is documented in the dedicated section below: `DoctrineTenantProvider` caches tenant objects in `cache.app` for 300 seconds per request. When an operator flips the `$inMaintenance` bool via CLI and the web process's PSR cache still holds the pre-flip object, the maintenance state will NOT take effect (or will NOT lift) for up to 5 minutes. **Cache invalidation IS required on toggle.** The enable/disable commands MUST delete the `tenancy.tenant.<slug>` cache key after flushing the landlord EM.

**Primary recommendation:** Implement `MaintenanceModeContractPass` as the first task (it blocks shipping the listener at a wrong priority), then the `TenantMaintenanceConfigTrait` + schema migration, then the listener, then the commands. Cache invalidation on toggle is a correctness requirement, not an optimization.

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Maintenance state persistence | Database / Storage | — | DB column on landlord-side `AbstractTenant`; authoritative, process-independent, no cross-tenant leak |
| Request interception / 503 | API / Backend (kernel.request) | — | `TenantMaintenanceModeListener` at priority 16; sets response before controller runs |
| Allow-list bypass | API / Backend | — | Evaluated inside the listener before the maintenance check; IpUtils + route + path |
| CLI toggle (enable/disable/status) | API / Backend | — | Console commands writing landlord EM; no bootstrapper boot |
| Compile-time ordering invariant | Build (ContainerBuilder) | — | `MaintenanceModeContractPass` inspects listener priority at container compile time |
| Events on toggle | API / Backend | — | Dispatched from CLI commands; user-land hooks |

---

## THE DEFINITIVE ANSWER: Cache Coherence on Toggle

### Finding

`DoctrineTenantProvider::findBySlug()` (file: `src/Provider/DoctrineTenantProvider.php`, lines 25–56) caches the resolved `TenantInterface` object in `cache.app` under key `tenancy.tenant.<slug>` with a **300-second TTL** (`CACHE_TTL = 300`, line 16).

```php
// src/Provider/DoctrineTenantProvider.php:16
private const CACHE_TTL = 300;

// src/Provider/DoctrineTenantProvider.php:31-43
$tenant = $this->cache->get(
    'tenancy.tenant.'.$slug,
    function (ItemInterface $item) use ($slug, $entityClass): ?TenantInterface {
        $item->expiresAfter(self::CACHE_TTL);
        $result = $this->entityManager
            ->getRepository($entityClass)
            ->findOneBy(['slug' => $slug]);
        return $result;
    }
);
```

The `TenantContextOrchestrator` (`src/EventListener/TenantContextOrchestrator.php`, line 39) calls `$this->resolverChain->resolve($event->getRequest())`, which internally calls `TenantProviderInterface::findBySlug()`. This is **a cross-process PSR cache** (`cache.app` is backed by `cache.adapter.redis` or similar in production), not a PHP-process in-memory store.

### The Stale-Object Timeline

1. Web worker A resolves `tenant-foo` at 12:00:00. The object is stored in `cache.app` key `tenancy.tenant.foo` with TTL 300s.
2. Operator runs `tenancy:maintenance:enable foo` at 12:01:00 in a separate CLI process. The CLI EM reads the object from the **same PSR cache** (same `cache.app` pool), gets the cached object, flips `$inMaintenance = true`, flushes to DB.
3. Doctrine EM's `flush()` updates the database row. However, the PSR cache still holds the **old serialized object** (with `$inMaintenance = false`).
4. Web worker A receives the next request for `tenant-foo` at 12:01:30. `findBySlug()` cache-hits on the old object. `isInMaintenance()` returns `false`. Maintenance mode DOES NOT ACTIVATE.
5. The stale cache expires at 12:05:00 (5 minutes after the original fetch). Only then do subsequent requests pick up the new DB state.

### Additional Subtlety: Doctrine Identity Map

The CLI process that runs `tenancy:maintenance:enable` goes through `findBySlug()` which also hits the PSR cache and returns the cached (un-modified) object. The CLI command then calls `flush()` on the landlord EM — but it is flushing a **proxy object originally retrieved from the PSR cache** which may not be attached to the current EM's identity map. The safe implementation pattern is:

```php
// In enable/disable commands — bypass PSR cache, fetch fresh from DB:
$entityClass = $this->tenantEntityClass; // string from param
$tenant = $this->landlordEm->getRepository($entityClass)->findOneBy(['slug' => $slug]);
// OR: $tenant = $this->landlordEm->find($entityClass, $slug);
// flip bool, flush, then delete PSR cache key
$this->cache->delete('tenancy.tenant.' . $slug);
```

Using `$landlordEm->getRepository()->findOneBy()` bypasses the PSR cache and returns a Doctrine-managed object. After flushing, delete the PSR cache key so the next web request reads fresh from DB.

### Verdict

**Cache invalidation IS required on toggle.** The enable/disable commands MUST:
1. Fetch the tenant fresh from the landlord EM (bypassing the PSR cache) using the repository, not `findBySlug()`.
2. Flip the bool and flush.
3. Delete the PSR cache key `tenancy.tenant.<slug>` from `cache.app`.

Without step 3, maintenance state changes have up to a 5-minute propagation delay — unacceptable for an ops feature.

`findAll()` (`src/Provider/DoctrineTenantProvider.php`, lines 60–75) intentionally bypasses the PSR cache ("operator tool, not a hot path" — comment at line 58). The `status` command can safely call `findAll()` directly to list current DB state.

**The symfony/cache memoization the milestone SUMMARY.md mentioned for the request path is NOT needed** — the PSR cache itself provides per-request memoization at the provider level (TTL 300s). The listener reads `$tenantContext->getTenant()->isInMaintenance()` which is a free in-memory bool read on the already-resolved object stored in `TenantContext`. No additional caching layer is needed in the listener.

---

## Confirmed API Signatures (file:line)

### TenantProviderInterface

```php
// src/Provider/TenantProviderInterface.php:17
public function findBySlug(string $slug): TenantInterface;
// Throws TenantNotFoundException when slug not found
// Throws TenantInactiveException when tenant exists but isActive() = false

// src/Provider/TenantProviderInterface.php:24
public function findAll(): array; // returns TenantInterface[]
```

**Important for commands:** `findBySlug()` throws `TenantInactiveException` for inactive tenants — the maintenance commands must catch this and treat inactive tenants as findable (the operator needs to be able to put an inactive tenant into maintenance). The safe pattern is to fetch directly via `$landlordEm->getRepository($class)->findOneBy(['slug' => $slug])` rather than `findBySlug()` to bypass both the PSR cache AND the `isActive()` gate.

### TenantContext

```php
// src/Context/TenantContext.php:10-31
public function setTenant(TenantInterface $tenant): void;
public function getTenant(): ?TenantInterface;
public function hasTenant(): bool;
public function clear(): void;
```

`getTenant()` returns `?TenantInterface` — the listener must call `hasTenant()` first.

### TenantContextOrchestrator — Priority Declaration

```php
// src/EventListener/TenantContextOrchestrator.php:18
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: TenantContextOrchestrator::PRIORITY)]

// src/EventListener/TenantContextOrchestrator.php:22-23
/** Priority 20: after Router (32), before Security firewall (8). */
public const PRIORITY = 20;
```

The priority is declared via `#[AsEventListener]` attribute with a named constant. The `MaintenanceModeContractPass` must inspect this attribute to enforce "maintenance listener < 20". However, because the maintenance listener itself will also use `#[AsEventListener]`, the pass needs to check the maintenance listener's priority attribute, not the orchestrator's. See "ContractPass Implementation Pattern" below.

### Landlord EntityManager Accessor

From `TenancyBundle::loadExtension()` (`src/TenancyBundle.php`, lines 251 and 344): the landlord EM service ID is `doctrine.orm.landlord_entity_manager` (under `database.enabled: true`). Under the default (no `database.enabled`), the default EM is the landlord EM (`doctrine.orm.default_entity_manager`).

From `SharedEntityResyncCommand` constructor (`src/Command/SharedEntityResyncCommand.php`, line 29):
```php
private readonly EntityManagerInterface $landlordEm,
```
Wired in `TenancyBundle::loadExtension()` at line 344:
```php
service('doctrine.orm.landlord_entity_manager'),
```

The maintenance commands must follow the same pattern — inject `EntityManagerInterface $landlordEm` wired to `doctrine.orm.landlord_entity_manager` (when `database.enabled: true`). Under the default (no `database.enabled`), the provider uses `doctrine.orm.default_entity_manager` which is the landlord EM.

Also inject `CacheInterface $cache` (wired to `cache.app`) for the post-flush key deletion.

### ContractPass Implementation Pattern

The two existing contract passes use `$container->hasParameter()` / `$container->getParameter()` / `$container->hasDefinition()` (`ContainerBuilder` methods). `MaintenanceModeContractPass` has a different concern: it must check the **priority of a service's event listener registration**.

For `#[AsEventListener]`-based services, after the container is compiled, the dispatcher listener definitions are expanded by Symfony's `RegisterListenersPass`. The `MaintenanceModeContractPass` can use the **event dispatcher's registered listeners** via a public container to check priorities at runtime in integration tests. For a compile-time check, it must read the `kernel.event_listener` tag attributes on the maintenance listener service definition:

```php
// In MaintenanceModeContractPass::process(ContainerBuilder $container):
// Find the maintenance listener service and check its kernel.event_listener tag.
$def = $container->findDefinition('tenancy.maintenance.listener'); // service ID
$tags = $def->getTag('kernel.event_listener');
foreach ($tags as $tag) {
    if (($tag['event'] ?? '') === KernelEvents::REQUEST) {
        $priority = (int) ($tag['priority'] ?? 0);
        if ($priority >= TenantContextOrchestrator::PRIORITY) {
            throw new \LogicException('...');
        }
    }
}
```

With `#[AsEventListener]`, Symfony's autoconfiguration converts the attribute to a `kernel.event_listener` tag with the priority set. The ContainerBuilder tag inspection above is the correct approach.

**Caveat:** `#[AsEventListener]` with `autoconfigure(true)` on the service definition converts to a `kernel.event_listener` tag. The pass must run AFTER `ResolveInstanceofConditionalsPass` (which processes autoconfiguration). Use `PassConfig::TYPE_BEFORE_REMOVING` or the default `TYPE_BEFORE_OPTIMIZATION` ordering should work — the same ordering used by existing passes.

---

## Standard Stack

### Core (all already in `require` — net-zero new production deps)

| Library | Version | Purpose | Already Required |
|---------|---------|---------|-----------------|
| `symfony/http-kernel` | ^7.4\|\|^8.0\|\|^8.1 | `RequestEvent`, `KernelEvents`, `#[AsEventListener]` | YES |
| `symfony/http-foundation` | ^7.4\|\|^8.0\|\|^8.1 | `Request`, `Response`, `IpUtils` | YES |
| `symfony/twig-bundle` | ^7.4\|\|^8.0\|\|^8.1 | Optional Twig 503 render (D-02) | YES (hard require) |
| `symfony/console` | ^7.4\|\|^8.0\|\|^8.1 | CLI commands | YES |
| `symfony/cache` | ^7.4\|\|^8.0\|\|^8.1 | PSR cache key deletion on toggle | YES |
| `doctrine/orm` | ^3.3 | Landlord EM flush; optional guard | YES (require-dev; runtime optional) |

`IpUtils` is in `symfony/http-foundation` (`Symfony\Component\HttpFoundation\IpUtils`) — already required, not previously used in the bundle per CONTEXT.md §D-07. Confirmed in the Symfony source.

### No New Dependencies

**Package Legitimacy Audit:** Not applicable — this phase introduces zero new composer dependencies. All required functionality is covered by existing `require` entries.

---

## Architecture Patterns

### System Architecture Diagram

```
HTTP Request
    │
    ▼ kernel.request prio=32
Router (resolves _route)
    │
    ▼ kernel.request prio=20
TenantContextOrchestrator::onKernelRequest()
    ├─► ResolverChain::resolve($request) → null|TenantResolution
    ├─► null → return (landlord/public/health routes skip context)
    └─► TenantContext::setTenant() + BootstrapperChain::boot() + dispatch(TenantResolved)
    │
    ▼ kernel.request prio=16
TenantMaintenanceModeListener::onKernelRequest()
    ├─► !isMainRequest() → return (sub-requests skip)
    ├─► !hasTenant() → return (null-tenant = public/landlord route — MAINT-04)
    ├─► allow-list check (IP, route, path) → if match, return (MAINT-06)
    ├─► $tenant->isInMaintenance() = false → return (normal path)
    └─► true → content-negotiate (JSON or HTML) → build 503 Response
                → set Retry-After + Cache-Control: no-store headers
                → $event->setResponse($response)  (short-circuits controller)
    │
    ▼ kernel.request prio=8
Symfony Security Firewall
    │
    ▼ Controller runs in tenant context
    │
    ▼ kernel.terminate
TenantContextOrchestrator::onKernelTerminate()
    → BootstrapperChain::clear() + TenantContext::clear() + dispatch(TenantContextCleared)

CLI Process (separate PHP process — no shared TenantContext):
    tenancy:maintenance:enable <slug>
        → $landlordEm->getRepository()->findOneBy(['slug' => $slug])
        → if $tenant->isInMaintenance() → print "already in maintenance", exit 0
        → $tenant->setInMaintenance(true)
        → $landlordEm->flush()
        → $cache->delete('tenancy.tenant.' . $slug)  ← cache invalidation
        → dispatch(TenantMaintenanceEnabled{$tenant})
        → print "OK", exit 0
```

### Recommended Project Structure (new files only)

```
src/
├── Maintenance/
│   └── TenantMaintenanceConfigTrait.php    # bool $inMaintenance + isInMaintenance() + setInMaintenance()
├── EventListener/
│   └── TenantMaintenanceModeListener.php   # kernel.request prio=16
├── Command/
│   ├── TenantMaintenanceEnableCommand.php  # tenancy:maintenance:enable
│   ├── TenantMaintenanceDisableCommand.php # tenancy:maintenance:disable
│   └── TenantMaintenanceStatusCommand.php  # tenancy:maintenance:status
├── Event/
│   ├── TenantMaintenanceEnabled.php        # readonly-constructor event
│   └── TenantMaintenanceDisabled.php       # readonly-constructor event
└── DependencyInjection/
    └── Compiler/
        └── MaintenanceModeContractPass.php  # asserts listener priority < 20
```

### Pattern 1: Config Trait (mirrors TenantMailerConfigTrait)

```php
// Source: src/Mailer/TenantMailerConfigTrait.php (established pattern)
// TenantMaintenanceConfigTrait.php
namespace Tenancy\Bundle\Maintenance;

use Doctrine\ORM\Mapping as ORM;

trait TenantMaintenanceConfigTrait
{
    #[ORM\Column(type: 'boolean')]
    private bool $inMaintenance = false;

    public function isInMaintenance(): bool
    {
        return $this->inMaintenance;
    }

    public function setInMaintenance(bool $inMaintenance): static
    {
        $this->inMaintenance = $inMaintenance;
        return $this;
    }
}
```

**Key differences from mailer/filesystem traits:** `bool` with non-null default (not nullable), no `?` prefix. The `#[ORM\Column]` attribute is honored only when Doctrine is installed; the trait still works as plain PHP property storage otherwise (same note as `TenantMailerConfigTrait` docblock). The `setInMaintenance` setter uses `static` return type for fluency, matching both existing traits.

### Pattern 2: Event class (mirrors TenantResolved)

```php
// Source: src/Event/TenantResolved.php (established pattern)
final class TenantMaintenanceEnabled
{
    public function __construct(
        public readonly TenantInterface $tenant,
    ) {}
}
```

### Pattern 3: Listener with #[AsEventListener]

```php
// Source: src/EventListener/TenantContextOrchestrator.php:18 (established pattern)
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 16)]
final class TenantMaintenanceModeListener
{
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) { return; }
        if (!$this->tenantContext->hasTenant()) { return; }
        // allow-list checks...
        $tenant = $this->tenantContext->getTenant();
        if (!$tenant->isInMaintenance()) { return; }
        // build 503 response + setResponse()
    }
}
```

### Pattern 4: CLI Command (mirrors SharedEntityResyncCommand)

```php
// Source: src/Command/SharedEntityResyncCommand.php (established pattern)
#[AsCommand(name: 'tenancy:maintenance:enable', description: '...')]
final class TenantMaintenanceEnableCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $landlordEm,
        private readonly string $tenantEntityClass,
        private readonly CacheInterface $cache,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) { parent::__construct(); }
    // NOTE: do NOT inject TenantProviderInterface (it throws TenantInactiveException)
    //       and do NOT inject TenantContext or BootstrapperChain
}
```

### Pattern 5: ContractPass registration in TenancyBundle::build()

```php
// Source: src/TenancyBundle.php:393-413 (established pattern)
public function build(ContainerBuilder $container): void
{
    parent::build($container);
    // existing passes...
    $container->addCompilerPass(new MaintenanceModeContractPass());
}
```

### Anti-Patterns to Avoid

- **Using findBySlug() in CLI commands:** It throws `TenantInactiveException` for inactive tenants and returns a cached object. Use `$landlordEm->getRepository($class)->findOneBy(['slug' => $slug])` instead.
- **Forgetting cache invalidation after flush:** Without `$cache->delete('tenancy.tenant.'.$slug)`, maintenance mode has a 5-minute propagation delay (CACHE_TTL = 300).
- **Registering listener at priority >= 20:** `TenantContext` is empty at that point. `MaintenanceModeContractPass` must catch this at compile time.
- **Calling BootstrapperChain::boot() or TenantContext::setTenant() in CLI commands:** Maintenance commands are landlord-side writes only; no tenant bootstrapping. See CONTEXT.md §Claude's Discretion.
- **Using $event->setResponse() on a sub-request:** The `isMainRequest()` guard prevents sub-request false-positives (same guard used by `TenantContextOrchestrator` at line 35).

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| IP/CIDR matching | Custom regex/subnet logic | `Symfony\Component\HttpFoundation\IpUtils::checkIp()` | Handles IPv6, CIDR notation, edge cases |
| Content negotiation detection | Custom Accept header parsing | `$request->getPreferredFormat()` or check `in_array($request->getAcceptableContentTypes(), ['application/json'])` | Symfony stdlib covers Accept header edge cases |
| Twig rendering with fallback | Try/catch around render | Inject `?Environment $twig` (nullable); catch `\Throwable` and fall back to hardcoded HTML | Twig is an optional dep in consumer apps |

---

## Common Pitfalls

### Pitfall 1: PSR cache not invalidated on toggle (NEW — not in PITFALLS.md)
**What goes wrong:** `tenancy:maintenance:enable foo` flushes the DB but the PSR cache still holds the old `TenantInterface` object. The next 5 minutes of web requests see `isInMaintenance() = false`.
**Why it happens:** `DoctrineTenantProvider::findBySlug()` caches at CACHE_TTL=300 (`src/Provider/DoctrineTenantProvider.php:16`). The CLI and web processes share `cache.app`.
**How to avoid:** After every successful enable/disable flush, call `$this->cache->delete('tenancy.tenant.'.$slug)`.
**Warning signs:** Integration test: enable maintenance, dispatch HTTP request immediately, assert 503 is returned. If it fails, cache is not being invalidated.

### Pitfall 2: findBySlug() throws TenantInactiveException in CLI (NEW — not in PITFALLS.md)
**What goes wrong:** An operator runs `tenancy:maintenance:enable` on a previously-deactivated tenant. `findBySlug()` throws `TenantInactiveException` and the command fails.
**Why it happens:** `DoctrineTenantProvider::findBySlug()` (lines 50-53) checks `isActive()` after cache retrieval and throws `TenantInactiveException` for inactive tenants.
**How to avoid:** Fetch via `$landlordEm->getRepository($class)->findOneBy(['slug' => $slug])` which bypasses both the PSR cache and the `isActive()` gate.
**Warning signs:** `tenancy:maintenance:enable inactive-tenant` exits with "Tenant X is not active" instead of succeeding.

### Pitfall 3: Listener priority >= 20 (Pitfall 1 from PITFALLS.md)
**What goes wrong:** Maintenance check fires before orchestrator; `TenantContext` is empty; every request passes through.
**How to avoid:** Priority 16, enforced by `MaintenanceModeContractPass` at compile time.

### Pitfall 4: Null-tenant requests not bypassed (Pitfall 3 from PITFALLS.md)
**What goes wrong:** `hasTenant()` not checked; calling `getTenant()->isInMaintenance()` on null throws fatal.
**How to avoid:** `if (!$this->tenantContext->hasTenant()) { return; }` as the second check in the listener (after `isMainRequest()`).

### Pitfall 5: CDN caches the 503 (Pitfall 7/CDN warning from PITFALLS.md)
**What goes wrong:** Some CDNs (Cloudflare) override `Cache-Control: no-store` on 5xx responses.
**How to avoid:** Set full `Cache-Control: no-store, no-cache, must-revalidate` + `Pragma: no-cache`. Document CDN config in Phase 34 ops docs.

### Pitfall 6: AbstractTenant column naming conflict with TenantMaintenanceConfigTrait
**What goes wrong:** User who already extends `AbstractTenant` and also `use TenantMaintenanceConfigTrait` gets Doctrine duplicate column mapping error.
**Why it happens:** `AbstractTenant` will inline `$inMaintenance` (same column as the trait). The trait docblock must explicitly warn "Do NOT use with AbstractTenant, which already inlines this column" — same warning as `TenantFilesystemConfigTrait` (`src/Filesystem/TenantFilesystemConfigTrait.php`, lines 36-39).
**How to avoid:** Add the warning to the trait's docblock. Custom entity users who extend `AbstractTenant` get the column for free. Custom entity users who implement `TenantInterface` directly use the trait.

### Pitfall 7: TenantInterface BC break handling
The trait provides `isInMaintenance(): bool { return $this->inMaintenance; }` with property default `false`. Existing custom entities that implement `TenantInterface` but do NOT use the trait will fail PHPUnit compilation if `isInMaintenance()` is added to `TenantInterface` without them implementing it. The UPGRADE 0.4→0.5 note (Phase 34) must clearly state: "Add `use TenantMaintenanceConfigTrait;` OR implement `isInMaintenance(): bool { return false; }` manually."

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11 |
| Config file | `phpunit.xml.dist` (root) |
| Quick run command | `vendor/bin/phpunit --testsuite unit` |
| Full suite command | `vendor/bin/phpunit` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| MAINT-01 | `enable` puts tenant in maintenance; idempotent second call | unit (CommandTester) | `vendor/bin/phpunit tests/Unit/Command/TenantMaintenanceEnableCommandTest.php` | ❌ Wave 0 |
| MAINT-01 | `enable` with unknown slug returns FAILURE | unit (CommandTester) | same | ❌ Wave 0 |
| MAINT-02 | `disable` restores tenant; idempotent second call | unit (CommandTester) | `vendor/bin/phpunit tests/Unit/Command/TenantMaintenanceDisableCommandTest.php` | ❌ Wave 0 |
| MAINT-03 | Request to maintenance tenant returns 503 + `Retry-After` + `Cache-Control: no-store` | unit (listener direct-invoke) | `vendor/bin/phpunit tests/Unit/EventListener/TenantMaintenanceModeListenerTest.php` | ❌ Wave 0 |
| MAINT-03 | JSON request returns JSON body; HTML request returns HTML | unit (listener direct-invoke) | same | ❌ Wave 0 |
| MAINT-04 | Null-tenant request bypasses listener | unit (listener direct-invoke) | same | ❌ Wave 0 |
| MAINT-04 | Sub-request bypasses listener | unit (listener direct-invoke) | same | ❌ Wave 0 |
| MAINT-05 | Cross-tenant isolation: tenant-A in maintenance does NOT affect tenant-B | unit (listener direct-invoke, two contexts) | same | ❌ Wave 0 |
| MAINT-05 | Cache invalidation: after enable, PSR cache key deleted | unit (mock cache) | same | ❌ Wave 0 |
| MAINT-06 | IP on allow-list bypasses maintenance | unit (listener) | same | ❌ Wave 0 |
| MAINT-06 | Route on allow-list bypasses maintenance | unit (listener) | same | ❌ Wave 0 |
| MAINT-06 | Path prefix on allow-list bypasses maintenance | unit (listener) | same | ❌ Wave 0 |
| MAINT-07 | Custom Twig template renders when configured | unit (listener, mock Twig env) | same | ❌ Wave 0 |
| MAINT-07 | Falls back to hardcoded HTML if Twig render throws | unit (listener, Twig throws) | same | ❌ Wave 0 |
| MAINT-08 | `TenantMaintenanceEnabled` dispatched on enable; not dispatched on idempotent re-enable | unit (mock dispatcher) | `vendor/bin/phpunit tests/Unit/Command/TenantMaintenanceEnableCommandTest.php` | ❌ Wave 0 |
| MAINT-08 | `TenantMaintenanceDisabled` dispatched on disable; not dispatched on idempotent re-disable | unit (mock dispatcher) | `vendor/bin/phpunit tests/Unit/Command/TenantMaintenanceDisableCommandTest.php` | ❌ Wave 0 |
| MAINT-09 | `status` lists only in-maintenance tenants; `--format=json` output | unit (CommandTester) | `vendor/bin/phpunit tests/Unit/Command/TenantMaintenanceStatusCommandTest.php` | ❌ Wave 0 |
| Success Criterion 3 | `MaintenanceModeContractPass` fails compilation when listener at priority >= 20 | unit (ContainerBuilder) | `vendor/bin/phpunit tests/Unit/DependencyInjection/Compiler/MaintenanceModeContractPassTest.php` | ❌ Wave 0 |
| Success Criterion 3 | Pass succeeds when listener at priority < 20 | unit (ContainerBuilder) | same | ❌ Wave 0 |
| Integration | Maintenance listener registered at priority 16 in compiled container | integration (ListenerPriorityTest extended) | `vendor/bin/phpunit tests/Integration/ListenerPriorityTest.php` | ❌ needs extension |
| No-Doctrine lane | Bundle compiles without doctrine/orm; maintenance feature not broken | integration (ZeroConfigKernelBootTest-style) | `vendor/bin/phpunit tests/Integration/ZeroConfigKernelBootTest.php` | ✅ (extend existing) |

### Test Seam Notes

**Unit tests for listener:** Instantiate `TenantMaintenanceModeListener` directly; create `TenantContext`, set a mock `TenantInterface`, fire `RequestEvent` with `HttpKernelInterface::MAIN_REQUEST`. Assert `$event->hasResponse()` and response status/headers. Pattern: `tests/Integration/EventListener/NoTenantRequestTest.php` shows the `RequestEvent` construction pattern.

**Unit tests for commands:** Use `CommandTester`. Inject a mock `EntityManagerInterface` as landlord EM, mock `CacheInterface` for the `delete()` assertion, mock `EventDispatcherInterface` for event dispatch assertion. Do NOT boot a kernel. Pattern: `tests/Unit/Command/TenantMigrateCommandTest.php`.

**Compiler pass tests:** Instantiate `ContainerBuilder` directly, add a service definition tagged `kernel.event_listener` with varying priorities, run `MaintenanceModeContractPass::process()`. Assert `\LogicException` is thrown at priority >= 20. Pattern: `tests/Unit/DependencyInjection/Compiler/MailerTransportContractPassTest.php`.

**Integration listener priority:** Extend `tests/Integration/ListenerPriorityTest.php` with a second test that asserts `TenantMaintenanceModeListener` is registered at priority 16, and asserts it is lower (fires after) `TenantContextOrchestrator` at priority 20.

### Sampling Rate

- **Per task commit:** `vendor/bin/phpunit --testsuite unit` (seconds, no DB)
- **Per wave merge:** `vendor/bin/phpunit` (full suite)
- **Phase gate:** Full suite green before `/gsd:verify-work`

### Wave 0 Gaps

- [ ] `tests/Unit/EventListener/TenantMaintenanceModeListenerTest.php` — covers MAINT-03, MAINT-04, MAINT-05, MAINT-06, MAINT-07
- [ ] `tests/Unit/Command/TenantMaintenanceEnableCommandTest.php` — covers MAINT-01, MAINT-08
- [ ] `tests/Unit/Command/TenantMaintenanceDisableCommandTest.php` — covers MAINT-02, MAINT-08
- [ ] `tests/Unit/Command/TenantMaintenanceStatusCommandTest.php` — covers MAINT-09
- [ ] `tests/Unit/DependencyInjection/Compiler/MaintenanceModeContractPassTest.php` — covers Success Criterion 3
- [ ] `tests/Unit/Entity/TenantMaintenanceConfigTraitTest.php` — covers MAINT-05 default + getter/setter

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no | — |
| V3 Session Management | no | — |
| V4 Access Control | yes | null-tenant early return; allow-list evaluated before maintenance check |
| V5 Input Validation | yes | slug input validated by `findOneBy(['slug' => $slug])`; Symfony Request for client IP |
| V6 Cryptography | no | — |

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Bypass via spoofed `X-Forwarded-For` | Spoofing | Use `$request->getClientIp()` (respects `trustedProxies` config); document proxy trust requirement in ops docs |
| Operator locked out via maintenance on landlord routes | Denial of Service | `!hasTenant()` early return ensures landlord routes are never blocked |
| CDN caches 503 after maintenance ends | Denial of Service | `Cache-Control: no-store, no-cache, must-revalidate` + `Pragma: no-cache` |
| Cross-tenant maintenance flag leak | Information Disclosure | DB column per tenant (row-level isolation); PSR cache keyed by slug; no static properties |

**No HMAC bypass token in this phase.** CONTEXT.md D-06/D-07 specifies IP/route/path allow-lists only. The PITFALLS.md Pitfall 6 HMAC bypass token is a deferred enhancement, not in MAINT-01..09.

---

## Environment Availability

Step 2.6 SKIPPED for the production runtime (no new external tool dependencies — PHP 8.2+, Symfony 7.4+, and Doctrine are already verified present in CI). The PSR cache pool (`cache.app`) is always available in Symfony.

One environment note: integration tests use `TestKernel` with `cache.adapter.array` (`tests/Integration/TestKernel.php`, line 52). The cache invalidation test must verify that the `CacheInterface::delete()` call happens — not that a real distributed cache is updated. Unit-level mocking of `CacheInterface` is sufficient.

---

## Open Questions (RESOLVED)

> All three resolved during Phase 32 planning (see 32-01..32-04-PLAN.md):
> **Q1 → RESOLVED:** feature flag `tenancy.maintenance.enabled: false` default (mirrors filesystem; ContractPass early-returns when disabled).
> **Q2 → RESOLVED:** `maintenance` node lives in `TenancyBundle::configure()` alongside the `filesystem`/`shared` nodes, following the `origin.allow_list` array-node pattern.
> **Q3 → RESOLVED:** listener injects `?Environment` via `nullOnInvalid()` (defense-in-depth over the non-nullable recommendation; Claude's Discretion per CONTEXT.md); the `try/catch` HTML fallback is unchanged.

1. **`tenancy.maintenance.enabled` feature flag vs. always-on**
   - What we know: filesystem feature is behind `tenancy.filesystem.enabled: false` default. The listener is cheap (one in-memory bool read).
   - What's unclear: whether the bundle convention requires an `enabled` flag on every feature or whether always-on is acceptable for a sub-100ns listener.
   - Recommendation: Mirror the filesystem convention and add `tenancy.maintenance.enabled: false` default. The `MaintenanceModeContractPass` early-returns when disabled (same pattern as `FilesystemContractPass::process()` line 70). Always-on would also be correct; this is Claude's Discretion per CONTEXT.md.

2. **Config tree location: `TenancyBundle::configure()` vs. `loadExtension()`**
   - What we know: all existing config nodes are in `configure()` (`TenancyBundle.php` lines 49-143). Parameters are set in `loadExtension()`.
   - What's unclear: where exactly to register `allow_ips` (an array node) in the tree builder.
   - Recommendation: Follow the `origin.allow_list` pattern (lines 80-105 in `TenancyBundle::configure()`) — `arrayNode('maintenance')` → `arrayNode('allow_ips')->scalarPrototype()`, etc.

3. **Twig service injection — nullable or conditional registration**
   - What we know: `symfony/twig-bundle` is in `require` (not optional). The listener can safely inject `Environment $twig` directly.
   - Recommendation: Inject `Environment $twig` directly (non-nullable) since Twig is a hard require. The `try/catch` fallback handles the case where the template itself throws.

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `$cache->delete('tenancy.tenant.'.$slug)` is the correct PSR cache key format used by `DoctrineTenantProvider` | Cache Coherence | The key format is `'tenancy.tenant.'.$slug` (line 32 of DoctrineTenantProvider.php — verified directly). LOW risk. |
| A2 | `IpUtils::checkIp()` supports both IPv4 and IPv6 CIDR notation | Standard Stack | Symfony stdlib — confirmed via [CITED: symfony.com/doc/current/components/http_foundation.html]; LOW risk. |
| A3 | The maintenance listener at priority 16 fires after security firewall at priority 8 | Architecture | Maintenance is pre-auth (correct); if firewall moved to higher priority, maintenance fires before auth, which is acceptable behavior. LOW risk — the bundle does not dictate firewall priority. |

**If this table is empty of significant risks:** All critical claims (cache TTL, API signatures, pattern shapes, file:line locations) were verified directly against live source.

---

## Sources

### Primary (HIGH confidence — live source reads)

- `src/Provider/DoctrineTenantProvider.php` — CACHE_TTL=300, `findBySlug()` PSR cache behavior, `findAll()` bypasses cache, lines 1-76
- `src/Provider/TenantProviderInterface.php` — `findBySlug(string $slug): TenantInterface`, `findAll(): array`, lines 1-25
- `src/EventListener/TenantContextOrchestrator.php` — `#[AsEventListener]` priority 20, `isMainRequest()` guard, null-branch, lines 1-64
- `src/Context/TenantContext.php` — `hasTenant()`, `getTenant(): ?TenantInterface`, `setTenant()`, `clear()`, lines 1-33
- `src/TenantInterface.php` — current 8-method surface (7 plus getMailerReplyTo); `isInMaintenance()` would be 9th, lines 1-25
- `src/Entity/AbstractTenant.php` — `#[ORM\MappedSuperclass]`, `$isActive bool` column pattern, existing traits inlined, lines 1-200
- `src/Mailer/TenantMailerConfigTrait.php` — `static` return type, `#[ORM\Column]` only honored with Doctrine, lines 1-65
- `src/Filesystem/TenantFilesystemConfigTrait.php` — "Do NOT combine with AbstractTenant" warning pattern, lines 1-68
- `src/DependencyInjection/Compiler/MailerTransportContractPass.php` — `$container->hasParameter()`, `$container->getParameter()`, `$container->hasDefinition()`, early return when feature absent, `throw new \LogicException`, lines 1-106
- `src/DependencyInjection/Compiler/FilesystemContractPass.php` — `$container->hasParameter(ENABLED_PARAM)` early return, `findTaggedServiceIds()`, lines 1-140
- `src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php` — global allow-list compile-time normalization pattern, lines 1-162
- `src/Command/SharedEntityResyncCommand.php` — landlord EM injection pattern (`private readonly EntityManagerInterface $landlordEm`), `BootstrapperChain::boot()` contrast comment, lines 1-264
- `src/TenancyBundle.php` — `configure()` tree builder, `loadExtension()` parameter wiring, `build()` compiler pass registration, `TenancyBundle::build()` for `addCompilerPass()`, lines 1-478
- `config/services.php` — service registration patterns, `interface_exists` guards, `nullOnInvalid()` for optional services, lines 1-271
- `tests/Integration/TestKernel.php` — test kernel shape, `cache.adapter.array`, lines 1-65
- `tests/Integration/ListenerPriorityTest.php` — priority assertion pattern using `$dispatcher->getListenerPriority()`, lines 1-74
- `tests/Integration/EventListener/NoTenantRequestTest.php` — `RequestEvent` construction pattern, null-tenant test shape, lines 1-181
- `tests/Unit/Command/TenantMigrateCommandTest.php` — `CommandTester` pattern, mock injection, lines 1-60
- `tests/Unit/DependencyInjection/Compiler/MailerTransportContractPassTest.php` — compiler pass unit test pattern with `ContainerBuilder`, lines 1-60

### Secondary (MEDIUM confidence)

- `.planning/phases/32-maintenance-mode/32-CONTEXT.md` — locked decisions D-01..D-10, Claude's Discretion, code analogs
- `.planning/research/PITFALLS.md` — Pitfalls 1, 3, 5, 6, 7, 22 (maintenance-specific)
- `.planning/research/ARCHITECTURE.md` — listener priority ladder, OPS-01 new/modified file table

---

## Metadata

**Confidence breakdown:**
- Cache coherence answer: HIGH — verified directly from `DoctrineTenantProvider.php` CACHE_TTL=300 constant and PSR cache key format
- API signatures: HIGH — read directly from source files with line numbers
- ContractPass implementation pattern: HIGH — read `MailerTransportContractPass`, `FilesystemContractPass`, and `TenancyBundle::build()`
- Trait pattern: HIGH — read both existing config traits
- Test patterns: HIGH — read existing test files and kernel setup
- Pitfalls: HIGH for confirmed source-grounded ones; MEDIUM for CDN behavior (documented in PITFALLS.md)

**Research date:** 2026-07-01
**Valid until:** 2026-08-01 (stable PHP/Symfony API surface)
