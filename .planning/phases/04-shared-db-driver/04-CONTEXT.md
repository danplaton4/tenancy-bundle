# Phase 4: Shared-DB Driver - Context

**Gathered:** 2026-03-19
**Status:** Ready for planning

<domain>
## Phase Boundary

Implement the shared-database isolation strategy: a Doctrine SQL filter (`TenantAwareFilter`) that automatically scopes all queries for entities marked `#[TenantAware]` to the active tenant's ID. Querying a `#[TenantAware]` entity with no active tenant in strict mode throws `TenantMissingException`. Entities without the attribute are completely unaffected.

This phase does NOT include: identity map clearing (Phase 5), cache isolation (Phase 5), or combining shared-DB with per-tenant DB (v1.1).

</domain>

<decisions>
## Implementation Decisions

### `#[TenantAware]` attribute
- Pure marker attribute — no parameters, no configuration
- Convention: user entities must have a `tenant_id` column (`VARCHAR(63)`, string type)
- The attribute signals "this entity is scoped by tenant" — the filter always looks for `tenant_id`
- Document in the attribute docblock: *"Add a `tenant_id VARCHAR(63)` column to your entity. The SQL filter injects the active tenant's slug automatically."*
- No per-entity column name override in v1

### `tenant_id` column contract
- Value is the tenant's **slug** (string, max 63 chars) — NOT an integer or UUID
- Type: `VARCHAR(63)` matching `Tenant.slug` (the PK of the `Tenant` entity)
- Slug is treated as **immutable** once set — document this as a bundle convention
- The filter injects `$tenantContext->getTenant()->getSlug()` at query time
- No foreign key declaration required (though users may add one for DB-level integrity)

### SQL filter registration and lifecycle
- `TenantAwareFilter` is **always enabled** — registered in Doctrine config via `prependExtension` and never explicitly enabled/disabled by application code
- The filter reads `TenantContext` directly (injected at construction via filter parameters or service reference)
- In `addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias)`:
  1. Check if `$targetEntity->getReflectionClass()` has `#[TenantAware]` → if not, return `''` (no SQL injected, entity unaffected)
  2. If `#[TenantAware]` present and `TenantContext::hasTenant()` → return `$targetTableAlias.tenant_id = :tenancy_tenant_id` with slug set as parameter
  3. If `#[TenantAware]` present and `!hasTenant()` and `strict_mode: true` → throw `TenantMissingException`
  4. If `#[TenantAware]` present and `!hasTenant()` and `strict_mode: false` → return `''` (no filtering — all rows accessible, user opted out of strict)
- This always-enabled approach prevents data leaks: there is no window where a TenantAware entity query can bypass the filter

### `TenantMissingException` throw point
- Thrown inside `addFilterConstraint()` — deep in Doctrine's query machinery
- This is intentional: the filter is the last line of defense. If code reaches a query without a tenant, it throws regardless of how the request was constructed
- Not thrown in the bootstrapper — no separate "pre-flight" check
- **Escape hatch for admin/migration contexts**: explicitly disable the filter via Doctrine's API: `$em->getFilters()->disable('tenancy_aware')`. This is the only supported opt-out in v1. No per-controller or per-action annotation.

### `SharedDriver` — the bootstrapper
- `SharedDriver` implements `TenantDriverInterface` (which extends `TenantBootstrapperInterface`)
- Since the filter is always-enabled, `SharedDriver`'s primary job is to **set/clear the tenant parameter on the filter** at boot/clear time, not to enable/disable the filter itself
- `boot(TenantInterface $tenant)`: sets the filter parameter so `hasTenant()` returns true during queries
- `clear()`: clears the tenant parameter (filter remains enabled but `TenantContext::hasTenant()` returns false)
- Registered conditionally: only wired in DI when `tenancy.driver === 'shared_db'`

### Driver config model
- Activated via `tenancy.driver: shared_db` — uses the existing `driver` config key
- **Mutually exclusive** with `tenancy.database.enabled: true` (the Phase 3 dual-EM path)
- A config tree validator throws a clear compile-time error if both are configured:
  *"tenancy.driver: shared_db cannot be combined with tenancy.database.enabled: true. Choose one isolation strategy."*
- `loadExtension()` adds a new conditional block: `if ($config['driver'] === 'shared_db') { /* register filter + SharedDriver */ }` parallel to the existing `database.enabled` block

### Doctrine filter registration
- Filter registered via `prependExtension` with `doctrine.orm.filters.tenancy_aware: TenantAwareFilter::class`
- Registered as enabled by default so it's always active without explicit application code
- `TenantContext` and `strict_mode` are accessible inside the filter — either injected as filter parameters (Doctrine's `setParameter` mechanism) or via a static/global accessor (discouraged — prefer parameter injection)

### `TenantMissingException`
- New exception class in `src/Exception/TenantMissingException.php`
- Message: `"No active tenant in context. Cannot query TenantAware entity '{entityClass}' in strict mode."`
- Extends `\RuntimeException`

### Claude's Discretion
- Exact Doctrine filter parameter injection mechanism (filter parameters vs constructor injection via Doctrine's filter infrastructure)
- Internal implementation of reflection caching for `#[TenantAware]` attribute detection (performance optimization if needed)
- Integration test kernel setup (reuse Phase 3's `DoctrineTestKernel` pattern or adapt)

</decisions>

<specifics>
## Specific Ideas

- The `stancl/tenancy` (Laravel) pattern is the reference: pure marker, convention-based `tenant_id`, always-enabled filter. Match that ergonomic simplicity.
- The filter should be transparent — developers shouldn't have to think about it. They add `#[TenantAware]` + `tenant_id` column, and all queries are automatically scoped. Zero manual calls.
- `TenantMissingException` message should include the entity class name so developers know exactly which query triggered it.

</specifics>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase requirements
- `.planning/REQUIREMENTS.md` §ISOL-03, ISOL-04, ISOL-05 — The three requirements this phase satisfies
- `.planning/ROADMAP.md` §Phase 4 — Success criteria (4 truths that must hold), planned plan breakdown

### Existing codebase — integration points
- `src/TenancyBundle.php` — Where `SharedDriver` wiring goes in `loadExtension()`, where filter registration goes in `prependExtension()`, and where the config validator goes in `configure()`
- `src/Driver/TenantDriverInterface.php` — Interface `SharedDriver` must implement
- `src/Bootstrapper/DatabaseSwitchBootstrapper.php` — Structural reference: how a driver bootstrapper is implemented (boot/clear pattern)
- `src/Context/TenantContext.php` — The context service the filter reads (`hasTenant()`, `getTenant()->getSlug()`)
- `src/Exception/TenantNotFoundException.php` — Reference for exception naming/structure conventions
- `config/services.php` — DI wiring conventions used throughout the bundle

### Prior phase context
- `.planning/phases/03-database-per-tenant-driver/03-03-SUMMARY.md` — How `loadExtension()` conditional wiring was done for Phase 3; `SharedDriver` wiring follows the same pattern

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `TenantDriverInterface`: `SharedDriver` implements this directly — same boot/clear contract as `DatabaseSwitchBootstrapper`
- `TenantContext::hasTenant()` / `getTenant()->getSlug()`: exact methods the filter needs — no new context API required
- `TenancyBundle::prependExtension()`: already has the `$databaseEnabled` branching pattern — filter registration adds a parallel `$isSharedDb` branch
- `TenancyBundle::loadExtension()`: existing `if ($config['database']['enabled'])` block is the model for the new `if ($config['driver'] === 'shared_db')` block

### Established Patterns
- **Boot/clear bootstrapper pattern**: `DatabaseSwitchBootstrapper` is the canonical reference — `SharedDriver` follows the same structure
- **Conditional DI registration**: Phase 3 showed how to register services only when a config flag is active — same pattern applies here
- **Exception naming**: `TenantNotFoundException`, `TenantInactiveException` — follow `Tenant*Exception` naming: `TenantMissingException`
- **PHPUnit test structure**: Phase 3 integration tests use a `DoctrineTestKernel` with in-memory SQLite — Phase 4 integration tests should adapt this kernel

### Integration Points
- `TenancyBundle::configure()` — add config validator: `shared_db` + `database.enabled` → compile-time error
- `TenancyBundle::loadExtension()` — add `driver === 'shared_db'` conditional block to register `SharedDriver` and wire `TenantContext` + `strict_mode` into the filter
- `TenancyBundle::prependExtension()` — add filter registration to Doctrine ORM config when `driver === 'shared_db'`
- `src/Exception/` — new `TenantMissingException.php`
- `src/Filter/` — new directory: `TenantAwareFilter.php`
- `src/Attribute/` — new directory: `TenantAware.php`
- `src/Driver/SharedDriver.php` — parallel to `DatabaseSwitchBootstrapper` under a different path

</code_context>

<deferred>
## Deferred Ideas

- **Hybrid mode** (shared-DB on landlord + per-tenant DB for tenant EM): architecturally sound but complex config surface — v1.1
- **Per-route / per-controller opt-out** from strict mode: no framework-specific annotations in v1; escape hatch is `$em->getFilters()->disable('tenancy_aware')` explicitly
- **PHPStan extension** to enforce `#[TenantAware]` correctness and flag unguarded native queries: v1.1 (DX-03)
- **Profiler integration** showing active tenant and filter status: v1.1 (DX-02)

</deferred>

---

*Phase: 04-shared-db-driver*
*Context gathered: 2026-03-19*
