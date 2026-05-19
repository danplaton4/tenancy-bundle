# Phase 2: Tenant Resolution - Context

**Gathered:** 2026-03-18
**Status:** Ready for planning

<domain>
## Phase Boundary

Implement the full resolver layer: `TenantResolverInterface` contract, `ResolverChain` with compiler pass, four built-in resolvers (HostResolver — subdomain only in v1, HeaderResolver, QueryParamResolver, ConsoleResolver), and wire the chain into `TenantContextOrchestrator`.

**Phase 1 established:** `TenantContextOrchestrator::onKernelRequest()` is intentionally a no-op stub with a comment: "Phase 2 will inject ResolverChain here." This phase fills that gap.

**Custom domain resolution (Tenant.domain column)** is explicitly NOT in this phase — it requires DNS-level considerations and is deferred to a future phase. Phase 2 HostResolver handles subdomain identification only.

</domain>

<decisions>
## Implementation Decisions

### Resolver contract and chain
- **`TenantResolverInterface`**: single method `resolve(Request $request): ?TenantInterface` for HTTP resolvers. Console resolver has a separate interface or is handled via `ConsoleCommandEvent`.
- **ResolverChain**: chain-of-responsibility — runs resolvers in configured order, **first match wins**. Stops as soon as one resolver returns a non-null tenant.
- **Resolver priority system**: hybrid (decided in Phase 1):
  - YAML list `tenancy.resolvers: [host, header, query_param, console]` controls built-in order
  - Custom resolvers use DI tag `tenancy.resolver` with a `priority` attribute
- **ResolverChainPass**: compiler pass collects tagged `tenancy.resolver` services, builds the ordered chain (analogous to `BootstrapperChainPass` from Phase 1)

### HostResolver — v1 scope: subdomain only
- **v1 identifies tenants from subdomain only**: extract the leftmost subdomain segment from the host header, treat it as the tenant slug
  - e.g. `acme.app.com` → slug = `acme`
  - e.g. `api.acme.app.com` → slug = `acme` (strip leftmost non-base segments, Claude's discretion on exact algorithm)
- **`www` prefix**: strip `www.` before extraction (Claude's discretion)
- **Configuration**: `tenancy.host.app_domain` (or equivalent — researcher must investigate stancl/tenancy's config approach for inspiration before finalizing the config key name and shape)
- **`app_domain` is optional** (null by default): if not set, HostResolver skips entirely (supports pure custom-domain setups in future phases without config errors)
- **Custom domain lookup (Tenant.domain column)** — **deferred to a future phase**. v1 HostResolver does NOT query by domain column.
- Path-based tenant identification (e.g. `/tenant/{slug}/...`) is also in scope for v1 if research shows it fits cleanly; researcher to assess.

### Failure behavior
- **No resolver matches**: throw `TenantNotFoundException` immediately — request does not proceed without a resolved tenant
- **Inactive tenant (`is_active = false`)**: throw `TenantInactiveException` — treated as explicitly blocked, not as "not found"
- **Exception type**: domain exceptions that implement Symfony's `HttpExceptionInterface` — NOT extending `NotFoundHttpException` or `AccessDeniedHttpException` directly. Implements the interface to return the correct HTTP status code without coupling to HttpFoundation's exception hierarchy.
  - `TenantNotFoundException` → HTTP 404
  - `TenantInactiveException` → HTTP 403
- **First match wins**: chain stops at first non-null return. No "override" behavior.

### TenantProvider — tenant lookup service
- **`TenantProviderInterface`** with a default **`DoctrineTenantProvider`** implementation (Claude's discretion: shared provider is the right separation, makes resolvers testable without DB)
- Resolvers call `TenantProviderInterface::findBySlug(string $slug): ?TenantInterface` (and potentially other lookup methods)
- **Caching**: Symfony cache pool (PSR-6/16) — use `cache.app` or a dedicated pool. Tenant records are stable; a short TTL (minutes) is safe. Cache key: `tenancy.tenant.{slug}`.
- **Which EntityManager**: Phase 2 injects the **default** `EntityManagerInterface` (simple and functional). Phase 3 (dual-EM setup) will rewire this to the dedicated landlord EM. This is a known TODO in Phase 2's service wiring.
- **`TenantProviderInterface`**: tagged for user replacement via standard DI decoration or aliasing.

### ConsoleResolver
- **Scope**: `--tenant` option is added to **all console commands** automatically (via `ConsoleCommandEvent` — consistent with the HTTP approach of intercepting at the framework level, zero opt-in friction for developers)
- **Option name**: `--tenant` (short, domain-consistent)
- **Missing `--tenant`**: silent — no tenant context set, command runs without tenant. `TenantMissingException` fires lazily only if a `TenantAware` entity is queried (strict_mode applies)
- **ConsoleResolver fires on `ConsoleCommandEvent`**, NOT `kernel.request` — this was decided in Phase 1 and is captured in ROADMAP.md
- **Scope of commands**: general app commands only. `tenancy:migrate` and `tenancy:run` (Phase 7) manage their own tenant context explicitly — they do not rely on ConsoleResolver.

### TenantContextOrchestrator wiring (Phase 2 completion)
- Phase 2 injects `ResolverChain` into `TenantContextOrchestrator::onKernelRequest()`
- On successful resolution: call `TenantContext::setTenant()`, then `BootstrapperChain::boot()`, then dispatch `TenantResolved` event
- `resolvedBy` field in `TenantResolved` carries the FQCN of the winning resolver class

### Claude's Discretion
- Exact algorithm for multi-segment subdomain extraction beyond `app_domain` stripping
- Cache pool selection (dedicated `tenancy.cache` pool vs. `cache.app` namespace)
- Internal service IDs for `TenantProvider`, `ResolverChain`
- Whether `--tenant` option is added in `ConsoleCommandEvent` listener or via a `CompilerPass` on command definitions
- Exact config key name for app_domain — researcher must validate against stancl/tenancy patterns

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase 1 output (integration surface)
- `src/EventListener/TenantContextOrchestrator.php` — the stub comment in `onKernelRequest()` is Phase 2's entry point
- `src/TenancyBundle.php` — `configure()` has existing config keys; add `host.app_domain` and `resolvers` list here
- `src/TenantInterface.php` — resolver return type
- `src/Context/TenantContext.php` — resolver chain result is passed to `setTenant()`
- `src/Bootstrapper/BootstrapperChain.php` — `boot()` called after `setTenant()` in orchestrator
- `src/DependencyInjection/Compiler/BootstrapperChainPass.php` — pattern to follow for `ResolverChainPass`
- `config/services.php` — existing DI wiring; resolver services added here

### Project requirements and decisions
- `.planning/REQUIREMENTS.md` — RESV-01 through RESV-05
- `.planning/PROJECT.md` — extensibility constraint (every resolver must be replaceable via DI)
- `.planning/STATE.md` — pre-phase decisions on resolver priority hybrid model

### Research reference (to be produced)
- `.planning/phases/02-tenant-resolution/02-RESEARCH.md` — researcher MUST investigate:
  - stancl/tenancy's host configuration approach (app_domain config shape)
  - Symfony ConsoleCommandEvent --option injection patterns
  - PSR-6/PSR-16 cache integration in bundles (cache.app vs dedicated pool)
  - Whether path-based resolution fits the Phase 2 scope

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Patterns from Phase 1
- `BootstrapperChainPass` → direct template for `ResolverChainPass` (PriorityTaggedServiceTrait, tag `tenancy.resolver`)
- `TenancyBundle::build()` → add `ResolverChainPass` here alongside existing `BootstrapperChainPass`
- `TenancyBundle::loadExtension()` → add `registerForAutoconfiguration(TenantResolverInterface::class)->addTag('tenancy.resolver')`
- `TenancyBundle::configure()` → add `resolvers` list node and `host.app_domain` scalar node

### Integration Points
- `TenantContextOrchestrator::onKernelRequest()` — stub with comment "Phase 2 will inject ResolverChain here" — this is the injection point
- `TenantResolved` event class is ready: `new TenantResolved($tenant, $request, $resolvedBy::class)`
- `TenantContext::setTenant()` — called after successful resolution
- `BootstrapperChain::boot()` — called after `setTenant()`

### What Phase 3 will need from Phase 2
- `TenantProviderInterface` — Phase 3 will rewire the EM to the landlord one
- `ResolverChain` — used in Phase 3 integration tests for end-to-end tenant switching

</code_context>

<specifics>
## Specific Ideas

- Resolver chain should dispatch `TenantResolved` with `resolvedBy` = FQCN of the winning resolver (already part of the event class from Phase 1)
- `TenantProviderInterface::findBySlug()` should also check `is_active` and throw `TenantInactiveException` — centralizing the active check in the provider, not each resolver
- Both `TenantNotFoundException` and `TenantInactiveException` should live in `Tenancy\Bundle\Exception\` namespace
- Consider: `TenantProviderInterface::findAll(): array` for Phase 7 CLI batch commands — note for planner
- The `tenancy.resolvers` YAML list should accept short aliases (`host`, `header`, `query_param`, `console`) mapped to FQCNs internally — already decided in Phase 1

</specifics>

<deferred>
## Deferred Ideas

- **Custom domain resolution** — `HostResolver` in v1 handles subdomains only. Full custom domain (Tenant.domain column lookup, e.g. `client.com` → acme-corp) is a future phase requiring DNS-level thought.
- **Path-based resolution** — `/{tenant}/` URL prefix pattern. Needs router integration consideration; to be assessed in research.
- **ConsoleResolver `required` flag** — configurable whether missing `--tenant` throws vs. silent pass. Deferred; v1 is always silent.
- **Tenant cache TTL config** — expose cache TTL in bundle config. v1 uses a hard-coded sensible default.
- **`TenantProviderInterface::findAll()`** — for CLI batch commands in Phase 7. Note for that phase.
- **OriginHeaderResolver** — from v1.1 requirements (RESV-06). Not in this phase.

</deferred>

---

*Phase: 02-tenant-resolution*
*Context gathered: 2026-03-18*
