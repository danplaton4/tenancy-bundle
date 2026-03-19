# Phase 5: Infrastructure Bootstrappers - Context

**Gathered:** 2026-03-19
**Status:** Ready for planning

<domain>
## Phase Boundary

Implement two always-on infrastructure bootstrappers:
1. **`DoctrineBootstrapper`** — calls `EntityManager::clear()` on the default EM on every tenant boot, preventing cross-tenant identity map pollution. No filter involvement (that belongs to SharedDriver).
2. **`TenantAwareCacheAdapter`** — decorates `cache.app` with per-tenant namespace isolation using Symfony's native `NamespacedPoolInterface::withSubNamespace()`. Reads `TenantContext` live on every cache operation. When no tenant is active, delegates to the undecorated pool transparently.

This phase also fixes an existing bug: `EntityManagerResetListener` hardcodes `resetManager('tenant')` which breaks in `shared_db` mode (only a default EM exists, no 'tenant' named EM). Fix: call `resetManager()` with no argument to reset the default EM — works correctly in both driver modes.

This phase does NOT include: SQL filter management (SharedDriver's domain, Phase 4), Messenger context propagation (Phase 6), CLI commands (Phase 7).

</domain>

<decisions>
## Implementation Decisions

### DoctrineBootstrapper
- **Scope**: `EM::clear()` only — no SQL filter involvement. SQL filter is SharedDriver's domain (established in Phase 4).
- **Target EM**: Always clear `doctrine.orm.default_entity_manager` — driver-agnostic. In `database_per_tenant` mode the default EM is the tenant EM; in `shared_db` mode it's the single EM.
- **`boot(TenantInterface $tenant)`**: calls `EntityManager::clear()` — wipes the identity map before queries run for the new tenant.
- **`clear()`**: calls `EntityManager::clear()` — belt-and-suspenders alongside `EntityManagerResetListener::resetManager()`. Two mechanisms for one lifecycle stage is appropriate here: identity map clearing is the cross-tenant data leak vector. `clear()` wipes state immediately; `resetManager()` (fired afterward via `TenantContextCleared`) fully recreates the EM.
- **Location**: `src/Bootstrapper/DoctrineBootstrapper.php` — consistent with `DatabaseSwitchBootstrapper` location.
- **Registration**: Always registered in `services.php` — no driver-conditional wiring. `EM::clear()` is useful regardless of driver mode.

### EntityManagerResetListener (existing — must fix)
- **Bug**: Currently calls `resetManager('tenant')` — hardcoded to the named 'tenant' EM. Breaks in `shared_db` mode where only the default EM exists (no 'tenant' named EM).
- **Fix**: Change to `resetManager()` (no argument) — resets the default EM. Correct in both modes.
- **Coexists with DoctrineBootstrapper**: `DoctrineBootstrapper::clear()` fires in step 1 (BootstrapperChain), `EntityManagerResetListener` fires in step 3 (after `TenantContextCleared` is dispatched). Belt-and-suspenders is intentional.
- **Do NOT remove or absorb** into DoctrineBootstrapper — the two-step teardown (clear then reset) provides stronger guarantees than either alone.

### CacheBootstrapper / TenantAwareCacheAdapter
- **Mechanism**: Claude's discretion — but the correct Symfony-native API is `NamespacedPoolInterface::withSubNamespace(string $namespace)`. This is adapter-level isolation, NOT key prefixing. Researcher must verify the exact implementation approach (live-reading proxy vs. static delegation) based on Symfony internals.
- **Namespace key**: Tenant slug (`TenantInterface::getSlug()`).
- **No-tenant fallback**: No-op — when no tenant is active, delegate to the undecorated `cache.app` pool. Console commands, warmup, cron jobs all see the global pool. Do NOT throw.
- **Pool scope**: `cache.app` only. No multi-pool config in v1.
- **BOOT-01 note**: Since `boot()`/`clear()` may be no-ops (the adapter reads `TenantContext` live), this might not implement `TenantBootstrapperInterface`. Researcher should determine whether the cache isolation needs bootstrapper hook-in or is purely a service decorator. If no boot/clear needed → register as a Symfony service decorator only, no bootstrapper tag.
- **Location**: `src/Cache/TenantAwareCacheAdapter.php` (new `Cache/` directory, parallel to existing `src/Filter/`, `src/DBAL/`).

### Bootstrapper activation
- Both `DoctrineBootstrapper` and cache adapter are **always registered** — no driver-conditional wiring, no new config flags.
- Zero extra configuration for users — install the bundle, get isolation.

### Bootstrapper priority order
- Claude's discretion — planner assigns priority via `tenancy.bootstrapper` tag.
- Correct logical order: drivers (DatabaseSwitchBootstrapper, SharedDriver) run first (connection switch / filter inject), then DoctrineBootstrapper clears the EM (now pointing at the correct DB). Cache adapter is stateless (reads TenantContext live) so ordering is irrelevant for it.

### REQUIREMENTS.md update
- **BOOT-01** must be updated to clarify the split: DoctrineBootstrapper owns `EM::clear()` on tenant switch. SQL filter management is SharedDriver's responsibility (ISOL-03/ISOL-05). The current BOOT-01 text ("enables the SQL filter and injects tenant_id") is inaccurate for Phase 5 scope.
- Update traceability table: BOOT-01 → Phase 5 (EM::clear only), add note that SQL filter is covered by ISOL-03/ISOL-05.

### Claude's Discretion
- Exact `TenantAwareCacheAdapter` implementation — proxy that reads TenantContext live vs. static swap; researcher must verify against `NamespacedPoolInterface` and `withSubNamespace()` semantics in Symfony 6.4/7.x.
- Whether `TenantAwareCacheAdapter` implements `TenantBootstrapperInterface` (if boot/clear are truly no-ops) or is registered as a pure Symfony service decorator.
- Priority values for `tenancy.bootstrapper` tag on `DoctrineBootstrapper` (planner decides, must be lower priority than drivers so drivers run first).
- Whether `DoctrineBootstrapper` implements `TenantDriverInterface` (marker) or just `TenantBootstrapperInterface` — it's not a driver, so `TenantBootstrapperInterface` directly is correct.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase requirements
- `.planning/REQUIREMENTS.md` §BOOT-01, BOOT-02 — The two requirements this phase satisfies (note: BOOT-01 needs update as described above)
- `.planning/ROADMAP.md` §Phase 5 — Goal, success criteria (4 truths), planned plan breakdown

### Existing codebase — integration points
- `src/Bootstrapper/DatabaseSwitchBootstrapper.php` — Canonical boot/clear bootstrapper pattern; `DoctrineBootstrapper` follows the same structure
- `src/Bootstrapper/TenantBootstrapperInterface.php` — Interface `DoctrineBootstrapper` implements
- `src/EventListener/EntityManagerResetListener.php` — Existing listener with the `resetManager('tenant')` bug to fix in this phase
- `src/Driver/SharedDriver.php` — Shows what NOT to put in DoctrineBootstrapper (filter logic stays here)
- `src/Context/TenantContext.php` — `hasTenant()` and `getTenant()->getSlug()` — the two methods TenantAwareCacheAdapter needs
- `src/TenantInterface.php` — `getSlug()` return type (string) — the namespace key
- `src/TenancyBundle.php` — Where `DoctrineBootstrapper` and cache adapter are wired into DI (in `loadExtension()` or `services.php`)
- `config/services.php` — DI wiring conventions; where new services are registered

### Symfony internals — researcher must investigate
- `Symfony\Component\Cache\Adapter\AbstractAdapter` — namespace parameter and `withSubNamespace()` implementation
- `Symfony\Contracts\Cache\NamespacedPoolInterface` — the `withSubNamespace(string $namespace): static` method (adapter-level namespace isolation, NOT key prefixing)
- `Symfony\Component\Cache\Adapter\TagAwareAdapter` — how decorating adapters compose in Symfony's cache stack

### Prior phase context
- `.planning/phases/03-database-per-tenant-driver/03-CONTEXT.md` — Decision: `resetManager('tenant')` was correct for Phase 3 (database_per_tenant only); Phase 5 generalizes to default EM
- `.planning/phases/04-shared-db-driver/04-CONTEXT.md` — SQL filter lifecycle is SharedDriver's domain; DoctrineBootstrapper explicitly does NOT touch it

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `TenantBootstrapperInterface` — `DoctrineBootstrapper` implements this directly (same `boot(TenantInterface $tenant): void` and `clear(): void` contract)
- `TenantContext::hasTenant()` / `getTenant()->getSlug()` — exact two methods the cache adapter needs; both exist, no new API required
- `DatabaseSwitchBootstrapper` — structural template for `DoctrineBootstrapper`: `final class`, `__construct(private readonly ...)`, `boot()` + `clear()`

### Established Patterns
- **Boot/clear bootstrapper**: `DatabaseSwitchBootstrapper` is the canonical reference — `DoctrineBootstrapper` is identical in structure, different in operation (EM::clear vs. connection switch)
- **`final class` everywhere**: All bundle classes use `final` — `DoctrineBootstrapper` and `TenantAwareCacheAdapter` should too
- **`private readonly` constructor injection**: All bundle services use constructor injection with `private readonly` promoted properties
- **No-argument clear() for teardown**: Phase 4 decision — `SharedDriver::clear()` is a no-op because `TenantContext::clear()` runs in the chain. `DoctrineBootstrapper::clear()` diverges from this: it does call `EM::clear()` (belt-and-suspenders)

### Integration Points
- `services.php` — register `DoctrineBootstrapper` and tag it `tenancy.bootstrapper`; register `TenantAwareCacheAdapter` as decorator for `cache.app`
- `src/EventListener/EntityManagerResetListener.php` — change `resetManager('tenant')` → `resetManager()` (no arg)
- `src/Bootstrapper/` — new `DoctrineBootstrapper.php`
- `src/Cache/` — new directory, `TenantAwareCacheAdapter.php`
- `tests/Unit/Bootstrapper/` — new `DoctrineBootstrapperTest.php`
- `tests/Unit/Cache/` — new `TenantAwareCacheAdapterTest.php`
- `tests/Unit/EventListener/EntityManagerResetListenerTest.php` — update test for the `resetManager()` fix

### Known Code Issues to Fix
- `src/EventListener/EntityManagerResetListener.php:15` — `resetManager('tenant')` is hardcoded to the database_per_tenant driver's named EM. Breaks in `shared_db` mode. Must be fixed in this phase.

</code_context>

<specifics>
## Specific Ideas

- **Symfony Messenger precedent**: Symfony 4.4 added `DoctrineTransactionMiddleware` for the same reason (cross-message identity map pollution). Our `DoctrineBootstrapper` follows the same rationale but for per-request tenant context rather than per-message.
- **`withSubNamespace()` is the canonical Symfony API**: All Symfony cache adapters implement `NamespacedPoolInterface`. `withSubNamespace('acme')` returns a new adapter instance scoped to that namespace — this is adapter-level, not key-prefix. This is exactly what BOOT-02 calls for.
- **stancl/tenancy reference**: Laravel equivalent uses a custom CacheManager replacement with tenant-specific tags for bulk invalidation. Our Symfony approach is simpler: `withSubNamespace()` gives namespace isolation natively; no custom CacheManager replacement needed.
- **Zero config goal**: DoctrineBootstrapper and TenantAwareCacheAdapter should work with zero user configuration. Just install the bundle and both are active.

</specifics>

<deferred>
## Deferred Ideas

- **Multi-pool cache decoration**: Allow users to specify a list of additional cache pools to namespace beyond `cache.app` — v1.1 via config option.
- **TenantAwareCacheAdapter for custom pools**: Decorator pattern should work for any Symfony cache pool, but config surface is v1.1.
- **Cache invalidation command**: `tenancy:cache:clear {tenantId}` to clear a specific tenant's namespace — v1.1 (CLI-03 candidate).
- **CacheBootstrapper as explicit bootstrapper**: If `withSubNamespace()` is purely adapter-level (no boot/clear needed), the word "bootstrapper" in BOOT-02 may be a misnomer. Future rename to "decorator" or "adapter" may be appropriate, but REQUIREMENTS.md uses "bootstrapper" so keep naming consistent in v1.

</deferred>

---

*Phase: 05-infrastructure-bootstrappers*
*Context gathered: 2026-03-19*
