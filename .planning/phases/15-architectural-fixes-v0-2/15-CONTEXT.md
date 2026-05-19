# Phase 15: Architectural Fixes (v0.2) — Context

**Gathered:** 2026-04-19
**Status:** Ready for planning
**Source:** Direct conversation — architectural review of issues #5-#8

<domain>
## Phase Boundary

This phase resolves four defects reported in GitHub issues #5-#8 that surfaced in downstream demo projects after the (retracted) v1.0.0 tag. Scope is architectural correction, not surface patching — each issue is traced to a root cause and the fix is the mature OOP-shaped resolution, not the "quick win" suggested in the issue bodies.

**In scope:**
- `TenantAwareCacheAdapter` decorator completeness against `cache.app` substitution surface
- `ResolverChain::resolve()` semantic: nullable return for "no resolver matched", narrow exception for "identifier rejected"
- DBAL 4 connection switching mechanism: move from `wrapperClass` + `ReflectionProperty` to `Doctrine\DBAL\Driver\Middleware`
- Documentation accuracy (no new docs; update architecture references to match the new wiring)
- CHANGELOG + UPGRADE notes for the v0.1.0 → v0.2.0 transition
- `tenancy:init` YAML template placeholder update (tenant driver family instead of `sqlite://` placeholder)

**Out of scope:**
- New features or capabilities beyond fixing the four issues
- RLS driver (ISOL-06 — v1.1 territory)
- Profiler toolbar tab (DX-02 — v1.1 territory)
- PHPStan extension (DX-03 — v1.1 territory)

**Release target:** v0.2.0 (minor bump on `0.x`; breaking internal DBAL wiring is allowed pre-1.0 per the UPGRADE.md note committed in 68840e1).

</domain>

<decisions>
## Implementation Decisions (Locked)

### Issue #5 — TenantAwareCacheAdapter missing CacheInterface

**Root cause:** The decorator picks a subset of the interfaces `cache.app` exposes. Symfony's `cache.app` is registered as `AdapterInterface`, `CacheItemPoolInterface`, `CacheInterface`, `PruneableInterface`, `ResetInterface`, and optionally `TagAwareAdapterInterface` + `TagAwareCacheInterface`. A decorator is a Liskov substitute and must implement **every** contract the decorated service exposes.

**Fix (locked):**
- `TenantAwareCacheAdapter` gains `Symfony\Contracts\Cache\CacheInterface`, `Symfony\Component\Cache\PruneableInterface`, `Symfony\Component\Cache\ResetInterface` on the `implements` list.
- `$inner` property's intersection type widens to include all the added interfaces.
- `pool()` return type widens accordingly.
- New delegation methods: `get()`, `delete()` (for `CacheInterface`), `prune()` (for `PruneableInterface`), `reset()` (for `ResetInterface`).
- When the inner pool is `TagAwareAdapterInterface`, register a sibling decorator class (`TenantAwareTagAwareCacheAdapter`) via a second `->decorate('cache.app.taggable')` definition — avoids one mega-decorator implementing optional interfaces at runtime.
- Add a DI-level sanity check in `TenancyBundle::loadExtension()` (or a compiler pass) that walks the decorated service's `->getTags()` and asserts every cache-family tag is covered by the decorator's declared interfaces. Prevents this class of bug from re-landing.
- Integration test: boot a stock `TestKernel` with `cache.app` wired, `->get($container)->get(CacheInterface::class)` resolves without TypeError.

**Non-goals:** Keep the `pool()` → `withSubNamespace()` prefixing logic unchanged. Only the contract surface grows.

### Issue #6 — ResolverChain throws when no resolver matches

**Root cause:** Exceptions used for expected control flow. Two semantically distinct outcomes are conflated:
- "No resolver claimed this request" — valid, expected (public routes, health checks, landlord pages)
- "A resolver extracted an identifier but it was rejected (unknown, inactive)" — genuine error

**Fix (locked):**
- New value object `Tenancy\Bundle\Resolver\TenantResolution` (final readonly class) replacing the current `array{tenant, resolvedBy}`. Fields: `TenantInterface $tenant`, `string $resolvedBy`.
- `ResolverChain::resolve(Request): ?TenantResolution` returns null when all resolvers return null.
- `TenantContextOrchestrator::onKernelRequest`:
  - If null: leave `TenantContext` empty, skip `BootstrapperChain::boot()`, do **not** dispatch `TenantResolved`, request proceeds.
  - If non-null: same as today — set context, fire bootstrappers, dispatch event.
- `TenantContextOrchestrator::onKernelTerminate`: already guards on `hasTenant()`, behavior unchanged.
- `TenantNotFoundException` is narrowed to "identifier present but invalid". Thrown sites: inside `HostResolver`/`HeaderResolver`/`QueryParamResolver` when the extracted slug hits the provider and the provider can't find it (and the resolver chose to bubble instead of swallowing — current behavior is to swallow per Phase 02-02 decision, so no change there). Thrown by `DoctrineTenantProvider::findBySlug()` when appropriate.
- Unit tests: `ResolverChainTest` gains "no resolvers match → returns null" case; remove the "throws TenantNotFoundException" assertion.
- Integration test: `TenantContextOrchestratorTest` with a `Request` that matches no resolver → controller returns 200, `TenantContext::hasTenant()` is false.

**Stretch (may land in 15-02 if cheap):** `#[RequiresTenant]` controller attribute + argument resolver / kernel event listener that returns 404 for routes that need a tenant but don't have one. Explicit opt-in replacement for the former global-by-default behavior. Scope-gated: implement if the base change + tests land in < 60% of the plan's task budget, else defer to backlog.

**Non-goals:**
- No change to resolver priorities or the discovery compiler pass.
- No change to `ConsoleResolver` (operates outside the HTTP chain; unaffected).

### Issues #7 and #8 — Database-per-tenant driver is architecturally wrong for DBAL 4

**Root cause:** These are **one issue**, not two. DBAL 4 resolves the `Driver` implementation at `DriverManager::getConnection()` construction time and stores it immutably on the `Connection`. `TenantConnection::switchTenant()` mutates `$params` via reflection but cannot change `$driver`. The middleware chain wrapping the driver is also set at construction. So:
- With `sqlite://` placeholder + `unset($merged['url'])` (issue #7's suggested fix): params are correct but driver is still SQLite → queries fail because MySQL params are handed to SQLite driver.
- With MySQL placeholder (issue #8 Option A): works today but the whole design is fragile — any future change to `params['url']` handling in DBAL could break the reflection approach.

The ask in `TenantConnectionInterface` ("switch the underlying DBAL connection at runtime") is a **driver-level concern**, not a connection-wrapper concern. DBAL 4 exposes the correct extension point: `Doctrine\DBAL\Driver\Middleware`.

**Fix (locked):**
- New class `Tenancy\Bundle\DBAL\TenantDriverMiddleware implements \Doctrine\DBAL\Driver\Middleware`. Its `wrap(Driver $driver): Driver` returns a `TenantAwareDriver($driver, $tenantContext)`.
- New class `Tenancy\Bundle\DBAL\TenantAwareDriver implements \Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware`. Overrides `connect(array $params): Driver\Connection`:
  1. Read `TenantContext::getTenant()`.
  2. If tenant present: merge `$tenant->getConnectionConfig()` over `$params` (tenant keys win). Do **not** touch `url` since we're past that resolution stage.
  3. Delegate to `parent::connect($mergedParams)`.
- Registration: service-tag `doctrine.dbal.driver.middleware` (provided by DoctrineBundle). Configurable per connection — bundle config or convention registers it on the `tenant` connection only in `database_per_tenant` mode.
- `DatabaseSwitchBootstrapper::boot()` reduces to `$connection->close()`. DBAL transparently reconnects via `connect()` which now goes through the middleware that reads the fresh `TenantContext`. `clear()` stays `close()` — middleware sees empty context, reconnects to landlord params.
- `TenantConnection`, `TenantConnectionInterface`, and the `wrapperClass` registration are **removed** from the codebase. The EntityManager's `$conn` reference stays stable (same Connection object); only the socket under it rotates.
- `tenancy:init` YAML template: tenant connection placeholder uses the tenant driver family (documented example uses `pdo_mysql` with placeholder `dbname: placeholder_tenant`) — never `sqlite://` for MySQL/PG tenants. Add an explicit note that placeholder driver must match tenant driver family.
- Integration test (new): `DatabasePerTenantMiddlewareIntegrationTest` boots a Symfony kernel with two MySQL databases (via Docker-compose `db1`, `db2` or SQLite file DBs with distinct paths), seeds distinct data, executes tenant A → assert data A, tenant B → assert data B, reset → assert landlord data. Not a mock test — a real connect/query roundtrip.
- Deprecation path: `TenantConnection` and `TenantConnectionInterface` are **deleted outright**. The `0.1 → 0.2` UPGRADE note flags this and points forks at the middleware. Rationale: 2 Packagist downloads on v0.1 (self-downloads), no external users to carry.

**Non-goals:**
- No change to the shared-DB (SQL filter) path — unaffected.
- No bundle-managed connection pool (Option C in issue #8) — over-engineered for the problem.
- No reflection on `$driver` to replace it at runtime (Option B in issue #8) — documented as "considered and rejected" in the architecture reference.

### Issue #4 cross-cutting — Documentation accuracy

**Fix (locked):**
- `docs/architecture/di-compilation.md` + `docs/architecture/dbal-wrapper.md` (or rename to `dbal-middleware.md` — decide during planning): rewrite the database-per-tenant mechanism section to describe the middleware design. Remove the "wrapperClass + reflection" paragraphs. Add a brief "Considered and rejected" note for the reflection approach with the DBAL-4 driver-immutability rationale.
- `docs/user-guide/database-per-tenant.md`: placeholder config examples use MySQL, not SQLite. Add a callout box: "The landlord connection's `driver` must match the tenant databases' driver family."
- `tenancy:init` command's generated YAML template: same placeholder update.
- CHANGELOG.md: 0.2.0 entry with "Changed / Fixed / Removed" sections referencing issues #5, #6, #7+#8. Retrospective paragraph explains why v1.0.0 was retracted and v0.2.0 is where the architecture finally settles.
- UPGRADE.md: 0.1 → 0.2 section documenting (a) decorator works now, (b) resolver nullable semantics as a behavior change for anyone who caught `TenantNotFoundException` in a kernel.exception listener, (c) `TenantConnection` class removed — if you extended it, see the new middleware; (d) `tenancy:yaml` template differences with diff.
- Do NOT add new doc pages. Scope is accuracy, not expansion.

</decisions>

<canonical_refs>
## Canonical References

Downstream agents MUST read these before planning or implementing.

### Issue bodies (the source of truth for the user-reported defects)
- GitHub Issues #5, #6, #7, #8 in `danplaton4/tenancy-bundle` — read via `gh issue view N`

### Existing bundle source (the code that changes)
- `src/Cache/TenantAwareCacheAdapter.php` — FIX-01 target
- `src/Resolver/ResolverChain.php` — FIX-02 primary change
- `src/EventListener/TenantContextOrchestrator.php` — FIX-02 branching
- `src/Exception/TenantNotFoundException.php` — FIX-02 narrowing
- `src/Provider/DoctrineTenantProvider.php` — FIX-01 consumer (proves decorator works), FIX-02 exception callsite
- `src/DBAL/TenantConnection.php` — FIX-03 deletion target
- `src/DBAL/TenantConnectionInterface.php` — FIX-03 deletion target
- `src/Bootstrapper/DatabaseSwitchBootstrapper.php` — FIX-03 simplification
- `src/Command/TenantInitCommand.php` — FIX-03 + FIX-04 YAML template update
- `config/services.php` — FIX-01 decorator wiring, FIX-03 middleware registration

### DBAL 4 extension points (research targets)
- Upstream: `Doctrine\DBAL\Driver\Middleware` interface
- Upstream: `Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware` abstract class
- Upstream: `Symfony\Bridge\Doctrine\Middleware\IdleConnection\Driver` (reference middleware implementation in the Symfony/Doctrine stack)
- DoctrineBundle service tag `doctrine.dbal.driver.middleware` — verify per-connection targeting syntax

### Symfony cache contract surface (research targets)
- Upstream: `Symfony\Contracts\Cache\CacheInterface`, `Symfony\Component\Cache\Adapter\AdapterInterface`, `Symfony\Component\Cache\PruneableInterface`, `Symfony\Component\Cache\ResetInterface`, `Symfony\Component\Cache\Adapter\TagAwareAdapterInterface`
- How `framework.cache.app` is registered — what `->getTags()` exposes for consumers

### Project-specific
- `.planning/REQUIREMENTS.md` § `v0.2 Post-release Architectural Fixes` — FIX-01..04 full text
- `.planning/ROADMAP.md` § Phase 15 — goal + 6 success criteria
- `CLAUDE.md` — project conventions (Doctrine optional guards, strict_mode, bootstrapper clear() reverse order)

### Related prior-phase context (dependencies)
- `.planning/phases/03-database-per-tenant-driver/*-CONTEXT.md` (if exists) + SUMMARY — the `wrapperClass` approach being replaced
- `.planning/phases/05-infrastructure-bootstrappers/*-CONTEXT.md` (if exists) + SUMMARY — the cache adapter's original design intent

</canonical_refs>

<specifics>
## Specific Ideas

- The decorator DI sanity check is new — no prior art in this codebase. Could be a one-liner compile-time assertion via `ContainerBuilder::getDefinition('cache.app')->getTags()` or a small compiler pass `CacheDecoratorContractPass`. Planner chooses.
- `TenantResolution` value object is final readonly. Consider whether to also carry the `Request` reference (currently `TenantResolved` event does) — probably not; keep it minimal.
- For FIX-03 integration test: prefer two SQLite file DBs over Docker MySQL so CI stays hermetic. The test still proves the middleware mechanism — only the driver family matters (SQLite is fine as long as both tenant dbs use SQLite). Use distinct `path:` params to force different connections.
- `tenancy:init` YAML template: add a second example block showing shared-DB mode (no placeholder needed) to make the driver-family callout clearer by contrast.
- Issue comments: close #5, #6, #7, #8 with references to Phase 15 commits when plans execute.

</specifics>

<deferred>
## Deferred Ideas

- `#[RequiresTenant]` controller attribute (stretch goal for 15-02; moves to backlog if budget tight).
- Profiler toolbar tab (DX-02 — v1.1 milestone, not v0.2).
- PHPStan extension enforcing `#[TenantAware]` usage (DX-03 — v1.1).
- Tagging issue-closing commits onto each PR/commit that resolves an issue — let the executor agent mention `Fixes #N` in commit bodies.

</deferred>

---

*Phase: 15-architectural-fixes-v0-2*
*Context gathered: 2026-04-19 via direct conversation (no discuss-phase ceremony — scope locked in architectural review)*
