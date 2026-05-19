# Phase 1: Core Foundation - Context

**Gathered:** 2026-03-17
**Status:** Ready for planning

<domain>
## Phase Boundary

Build the architectural skeleton of the TenancyBundle: the `TenantContext` stateful service, lifecycle events (`TenantResolved`, `TenantBootstrapped`, `TenantContextCleared`), the `TenantBootstrapperInterface` contract + compiler pass, the `Tenant` entity in the landlord DB, and the `TenantContextOrchestrator` kernel.request listener at priority 20.

This phase establishes the public API every subsequent phase builds on. No driver logic, no resolver logic — that is Phase 2+.

</domain>

<decisions>
## Implementation Decisions

### Package identity
- **Packagist name:** `danplaton4/tenancy-bundle`
- **PHP root namespace:** `Tenancy\Bundle` (e.g. `Tenancy\Bundle\TenantContext`, `Tenancy\Bundle\Event\TenantResolved`)
- **Symfony Bundle class:** `TenancyBundle` registered as `new Tenancy\Bundle\TenancyBundle()` in `bundles.php`
- Bundle config root key: `tenancy:` (standard Symfony convention)

### Bundle configuration shape (tenancy.yaml)
- Driver selection: `tenancy.driver: database_per_tenant` or `shared_database` — single key, not nested sections
- Resolver priority: **hybrid** — built-in resolvers ordered via a YAML list (`tenancy.resolvers: [host, header, query_param, console]`); custom resolvers use DI tag `tenancy.resolver` with a `priority` attribute
- v1 top-level config keys:
  - `strict_mode: true` — throw `TenantMissingException` when `#[TenantAware]` entity queried without active tenant (default ON)
  - `landlord_connection: default` — which Doctrine connection is the central Tenant registry
  - `tenant_entity_class: Tenancy\Bundle\Entity\Tenant` — override to use a custom Tenant class
  - `cache_prefix_separator: ':'` — separator between tenant ID and cache key

### Tenant entity design
- **Primary identifier:** `slug` (string, e.g. `acme-corp`) — human-readable, safe for subdomains and URL paths. Slug IS the primary key (no separate auto-increment `id`).
- **Fields in v1:** `slug` (PK), `domain` (nullable — for custom domains), `connection_config` (JSON — connection parameters for database-per-tenant driver), `name` (human-readable display name), `is_active` (boolean, default true), `created_at`, `updated_at` (Doctrine lifecycle callbacks)
- **`TenantInterface`** is provided: `getSlug(): string`, `getDomain(): ?string`, `getConnectionConfig(): array`, `getName(): string`, `isActive(): bool` — users can substitute their own Tenant entity by implementing this interface and updating `tenant_entity_class`
- Bundle ships a concrete `Tenancy\Bundle\Entity\Tenant` that implements `TenantInterface` (users can extend or replace)

### Event design
- All events are **plain PHP objects (PSR-14 / Symfony 5+ style)** — no base class extension, no `stopPropagation()`
- **`TenantResolved`** carries:
  - `tenant: TenantInterface` — the resolved tenant entity
  - `request: Request` — the original Symfony request (nullable for console context)
  - `resolvedBy: string` — FQCN of the resolver that matched (e.g. `Tenancy\Bundle\Resolver\HostResolver`)
- **`TenantBootstrapped`** carries:
  - `tenant: TenantInterface` — the tenant that was just bootstrapped
  - `bootstrappers: string[]` — FQCNs of bootstrappers that ran (in order)
- **`TenantContextCleared`** — **signal-only for Phase 1**, no payload. Simplest correct implementation; adding `previousTenant: ?TenantInterface` is a noted future enhancement.

### TenantContext design
- Zero-dependency pure value holder — no injected services
- API: `setTenant(TenantInterface $tenant): void`, `getTenant(): ?TenantInterface`, `hasTenant(): bool`, `clear(): void`
- Service ID: `tenancy.context` (also aliased to `Tenancy\Bundle\TenantContext` for autowiring)

### Bootstrapper chain
- Interface: `Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface` with `boot(TenantInterface $tenant): void` and `clear(): void`
- Tagged with `tenancy.bootstrapper` — compiler pass collects and injects into the `BootstrapperChain` service
- `BootstrapperChain` runs bootstrappers in tag priority order

### Kernel event wiring
- `TenantContextOrchestrator` listens on `kernel.request` at **priority 20** (after Router at 32, before Security firewall at 8) — defined as a `PRIORITY = 20` constant in the class
- Context clear fires on `kernel.terminate` (end of request lifecycle)
- Console context: `ConsoleResolver` hooks into `ConsoleCommandEvent` separately (Phase 2); `TenantContextOrchestrator` only handles HTTP

### Claude's Discretion
- Internal ordering of compiler pass execution
- Exact DI service IDs for internal/private services
- How `connection_config` JSON is validated (schema vs. loose array)
- Whether `TenantBootstrapperInterface::clear()` receives the previous tenant or nothing (signal-only consistent with `TenantContextCleared`)

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project requirements and decisions
- `.planning/REQUIREMENTS.md` — v1 requirements, specifically CORE-01 through CORE-05
- `.planning/PROJECT.md` — Key Decisions table (event-driven bootstrapping, hybrid bootstrapping, strict_mode default)
- `.planning/STATE.md` — Pre-phase decisions: TenantContext zero-dependency, kernel.request priority 20 constant, DoctrineBundle 3.x as soft dep

### Research findings
- `.planning/research/STACK.md` — PHP 8.2+ floor, DoctrineBundle version constraints, DBAL 4 wrapperClass note, PHPStan 2.x setup
- `.planning/research/ARCHITECTURE.md` — AbstractBundle usage, compiler pass pattern, bootstrapper registration, component boundaries
- `.planning/research/PITFALLS.md` — Circular dependency in bootstrapper (pitfall #N), DI container immutability constraint

No external specs/ADRs exist yet — all requirements are captured in the documents above.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- None — greenfield project. No existing src/ directory.

### Established Patterns
- None yet — Phase 1 establishes all patterns for subsequent phases.

### Integration Points
- Phase 1 output (`TenantContext`, events, `TenantBootstrapperInterface`) are the integration surface for every subsequent phase:
  - Phase 2 (Resolvers) → calls `TenantContext::setTenant()` and fires `TenantResolved`
  - Phase 3/4 (Drivers) → implement `TenantBootstrapperInterface`
  - Phase 5 (Bootstrappers) → implement `TenantBootstrapperInterface`
  - Phase 6 (Messenger) → reads `TenantContext::getTenant()` to create `TenantStamp`

</code_context>

<specifics>
## Specific Ideas

- The bundle should feel "Symfony-native" — use `AbstractBundle` (Symfony 6.1+), not the legacy `Extension + Configuration` approach
- Resolver YAML list (`tenancy.resolvers`) should accept short aliases (`host`, `header`) not full class names — bundle maps aliases to service IDs internally
- `TenantInterface` is a deliberate design choice to avoid locking users into the bundled `Tenant` entity; the `tenant_entity_class` config key is how substitution works
- The `TenantContextOrchestrator` PRIORITY constant should be exported as a public constant so downstream code can reference it: `TenantContextOrchestrator::PRIORITY`

</specifics>

<deferred>
## Deferred Ideas

- **Project setup / init phase** — User mentioned wanting a phase for package initialization, local dev environment setup (e.g. creating the Symfony app skeleton, configuring PHPStan, Makefile). This is a valid pre-Phase-1 concern worth adding as a dedicated phase or setup script. Note for roadmap.
- **`TenantContextCleared` previous tenant** — Carry `previousTenant: ?TenantInterface` in the cleared event for audit/cleanup use cases. Deferred to v1.1 roadmap; Phase 1 ships signal-only.
- **`TenantBootstrapperInterface::clear($previousTenant)`** — Consistent with deferred event payload; bootstrappers could receive the departing tenant in their `clear()` call. Same deferral.

</deferred>

---

*Phase: 01-core-foundation*
*Context gathered: 2026-03-17*
