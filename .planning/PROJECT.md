# Symfony Tenancy Bundle

## Current State

**Shipped:** v0.2.0 (2026-04-20) — Packagist-published at `danplaton4/tenancy-bundle`. 15 phases, 48 plans, 304 tests (739 assertions), PHPStan level 9 clean. All four v0.2 post-release architectural fixes (FIX-01–04, issues #5–#8) resolved; retroactive v1.0 tag was retracted and line restarted at v0.1.0 before reaching stable at v0.2.0.

## What This Is

A definitive multi-tenancy bundle for Symfony that treats tenancy as a first-class citizen of the Symfony kernel — not just a database switcher, but a **Context Orchestrator**. When a tenant is identified, the entire application state (database, cache, messenger) automatically follows suit. Published on Packagist as the Symfony equivalent of `stancl/tenancy` for Laravel.

## Core Value

When a tenant is resolved, every Symfony service automatically re-configures itself for that tenant — zero boilerplate, zero leaks, zero guessing.

## Requirements

### Validated

**Architectural Fixes — v0.2 (Phase 15 — 2026-04-20)**
- ✓ **FIX-01** Cache decorator contract completeness: `TenantAwareCacheAdapter` implements the full `cache.app` surface (`AdapterInterface, CacheInterface, NamespacedPoolInterface, PruneableInterface, ResettableInterface`); `TenantAwareTagAwareCacheAdapter` sibling for `cache.app.taggable`; `CacheDecoratorContractPass` compile-time guard. Closes issue #5. — v0.2
- ✓ **FIX-02** Resolver optionality: `ResolverChain::resolve()` returns nullable `TenantResolution`; orchestrator null-branches (public/landlord/health routes proceed without tenant); `TenantNotFoundException` narrowed. Closes issue #6. — v0.2
- ✓ **FIX-03** DBAL driver-middleware migration: `TenantDriverMiddleware` + `TenantAwareDriver` replace `wrapperClass` + `ReflectionProperty`; `DatabaseSwitchBootstrapper::boot()` reduces to `close()`; `TenantConnection` deleted outright. Closes issues #7 and #8. — v0.2
- ✓ **FIX-04** Documentation alignment: all docs reflect middleware architecture (`dbal-wrapper.md` → `dbal-middleware.md`); `scripts/docs-lint.sh` prevents future drift; CHANGELOG [0.2.0] + UPGRADE 0.1→0.2. — v0.2

**Documentation Refresh (Phase 14 — 2026-04-14)**
- ✓ **DOC-18** Remove all Flex artifacts and references; update docs for Phase 12–13 changes (`tenancy:init` as primary setup, cache_prefix_separator default, EM targeting). — v0.2

**Audit Gap Closure (Phase 13 — 2026-04-13)**
- ✓ **OSS-01** `composer.json` + `composer.lock` sync. — v0.2
- ✓ **BOOT-01/02** EntityManager targeting + separator wiring fixes. — v0.2
- ✓ **CLI-01** `tenancy:migrate` type fix. — v0.2
- ✓ **RESV-05** Resolver chain config wiring. — v0.2

**Developer Onboarding (Phase 12 — 2026-04-13, finalized Phase 15 — 2026-04-21)**
- ✓ **DX-04** `tenancy:init` scaffolds fully commented `config/packages/tenancy.yaml`. — v0.2
- ✓ **DX-05** Doctrine ORM detection + driver recommendation (`database_per_tenant` vs `shared_db`); testable via protected `detectDoctrine()` seam. — v0.2

**Documentation Site (Phase 11 — 2026-04-12)**
- ✓ **DOC-01..17** MkDocs Material site deployed to GitHub Pages with user-guide, contributor-guide, and architecture reference sections. — v0.2

**Dependency Compatibility (Phase 10 — 2026-04-10)**
- ✓ PHP 8.2/8.3/8.4 × Symfony 7.4/8.0 matrix, `prefer-lowest` and `no-messenger` CI jobs, deprecation detection. — v0.2

**OSS Hardening (Phase 09 — 2026-04-12)**
- ✓ **OSS-02** README + CONTRIBUTING.md with badges, quick-start, comparison table. — v0.2
- ✓ **OSS-03** Packagist discoverability metadata. — v0.2
- ✓ **OSS-04** GitHub Actions CI matrix, PHPStan level 9, php-cs-fixer @Symfony. — v0.2

**Developer Experience (Phase 08 — 2026-04-02)**
- [x] `InteractsWithTenancy` PHPUnit trait: `initializeTenant(string $slug)` boots clean tenant context (`:memory:` SQLite, schema, bootstrappers) per test method; `clearTenant()`, `tearDown()` auto-cleanup; `assertTenantActive()`, `assertNoTenant()`, `getTenantService()` helpers (DX-01, Validated in Phase 08)
- [x] `TenancyTestKernel`: database-per-tenant mode test kernel for trait integration tests — `TenantConnection` wrapperClass, `MakeTenancyTestServicesPublicPass` exposing private tenancy services in test container (DX-01, Validated in Phase 08)

**CLI Commands (Phase 07 — 2026-04-02)**
- [x] `TenantProviderInterface::findAll()`: returns all tenants from landlord EM, bypasses cache — powers sequential migration loop (CLI-01, Validated in Phase 07)
- [x] `TenantMigrateCommand`: `tenancy:migrate` sequential per-tenant migration with continue-on-failure, per-tenant status output, summary table, exit code 1 on any failure, `--tenant=<slug>` filter, shared_db driver guard, `class_exists` guard for doctrine/migrations (CLI-01, Validated in Phase 07)
- [x] `TenantRunCommand`: `tenancy:run <slug> "command args"` spawns subprocess via `Process::fromShellCommandline`, validates tenant exists first, forwards stdout/stderr, propagates exit code — full tenant context via ConsoleResolver `--tenant=` arg (CLI-02, Validated in Phase 07)
- [x] `symfony/process` promoted to production `require` — `tenancy:run` is production code (CLI-02, Validated in Phase 07)

**Messenger Integration (Phase 06 — 2026-03-19)**
- [x] `TenantStamp`: `StampInterface` implementation carrying tenant slug across process boundaries — survives PHP serialize/unserialize round-trip (MSG-01, Validated in Phase 06)
- [x] `TenantSendingMiddleware`: attaches `TenantStamp` on dispatch when tenant is active, idempotency guard prevents double-stamping (MSG-02, Validated in Phase 06)
- [x] `TenantWorkerMiddleware`: restores tenant context from stamp on consume, canonical `try/finally` teardown (bootstrapperChain → tenantContext → TenantContextCleared), passes through unstamped envelopes (MSG-03, Validated in Phase 06)
- [x] `MessengerMiddlewarePass`: compiler pass (priority 1) auto-enrolls both middlewares into all Messenger buses — zero user config, guarded by `interface_exists(MessageBusInterface)` (MSG-01–03, Validated in Phase 06)

**Infrastructure Bootstrappers (Phase 05 — 2026-03-19)**
- [x] `DoctrineBootstrapper`: calls `EntityManager::clear()` on `boot()` and `clear()` — prevents cross-tenant identity map pollution (BOOT-01, Validated in Phase 05)
- [x] `TenantAwareCacheAdapter`: decorates `cache.app` with `withSubNamespace(slug)` per cache operation — adapter-level namespace isolation, not key-prefix (BOOT-02, Validated in Phase 05)
- [x] `EntityManagerResetListener` bug fixed: `resetManager('tenant')` → `resetManager()` — now works correctly in both `database_per_tenant` and `shared_db` modes

**Shared-DB Isolation (Phase 04 — 2026-03-19)**
- [x] Shared-database driver: Doctrine SQL Filter auto-enabled for entities marked `#[TenantAware]` (Validated in Phase 04: shared-db-driver)
- [x] `#[TenantAware]` attribute: marks Doctrine entities for automatic tenant scoping (ISOL-03)
- [x] `TenantAwareFilter`: Doctrine SQL filter with 4-branch logic — scoped query, empty for non-aware, strict throw, permissive passthrough (ISOL-04)
- [x] `SharedDriver`: bootstrapper that injects `TenantContext` into `TenantAwareFilter` on `boot()` (ISOL-05)
- [x] Bundle wiring: compile-time guard blocking `shared_db + database.enabled`, conditional service registration, `prependExtension` Doctrine filter registration (ISOL-05)

**Database Isolation (Phase 03 — 2026-03-19)**
- [x] Database-per-tenant driver: swap DBAL connection parameters at runtime per tenant (Validated in Phase 03: database-per-tenant-driver)
- [x] `TenantConnection` DBAL wrapperClass subclass switches DB connection via reflection on private `$params` at runtime (ISOL-01)
- [x] `DatabaseSwitchBootstrapper` plugs into `BootstrapperChain` to trigger connection switch per tenant request (ISOL-01)
- [x] `EntityManagerResetListener` resets tenant EM on `TenantContextCleared` to prevent identity map pollution (ISOL-02)
- [x] Dual-EM DI wiring: `tenancy.database.enabled` flag, landlord EM for `DoctrineTenantProvider`, tenant EM for app queries (ISOL-02)
- [x] `prependExtension` conditionally targets `entity_managers.landlord.mappings` when `database.enabled=true` (ISOL-02)

**Core Foundation (Phase 01)**
- [x] Event-driven bootstrapping: `TenantResolved`, `TenantBootstrapped`, `TenantContextCleared` events
- [x] `BootstrapperChain` with compiler pass autoconfiguration and priority ordering
- [x] `TenantContext` stateful holder, `TenantContextOrchestratorListener` lifecycle management

**Tenant Resolution (Phase 02)**
- [x] `HostResolver`: subdomain and custom domain resolution
- [x] `HeaderResolver`: `X-Tenant-ID` header resolution
- [x] Pluggable resolver chain with configurable priority

### Active

*(No active requirements — all v1 scope shipped under v0.2. Next-milestone candidates below.)*

### Next Milestone Goals (candidates)

- [ ] **DX-02** Symfony Profiler "Tenancy" WDT tab (active tenant, ID, DB connection)
- [ ] **DX-03** PHPStan extension enforcing `#[TenantAware]` usage correctness
- [ ] **SHARE-01..03** Shared entity replication (sync + async via Messenger)
- [ ] **OPS-01** Tenant-level maintenance mode
- [ ] **OPS-02** Health check / MonitorBundle integration
- [ ] **RESV-06** `OriginHeaderResolver` (SPA-friendly alternative to `X-Tenant-ID`)
- [ ] Parallel tenant migrations via `symfony/process`
- [ ] Tenant-aware Mailer bootstrapper

### Out of Scope

- **Per-tenant middleware pipelines** — powerful but complex; reserved for later once core adoption is proven
- **PostgreSQL Row-Level Security (RLS)** — native RLS support deferred; shared-DB driver covers the use case
- **DNS TXT resolver** — niche; custom resolver interface covers the use case
- **Non-SQL isolation targets** (Redis, MongoDB, etc.) — v2+
- **Symfony Flex recipe** — removed in Phase 14; `tenancy:init` is the supported onboarding path

## Context

- **Ecosystem gap**: Existing Symfony tenancy packages (RamyHakam, manual SQL filter implementations) are partial solutions — they don't address Messenger, Cache, Filesystem, or provide a unified bootstrapping API.
- **Inspiration**: `stancl/tenancy` for Laravel is the gold standard — event-driven, bootstrapper-first, comprehensive. This bundle brings that philosophy to Symfony idioms (bundles, DI, events, attributes).
- **Symfony idioms to embrace**: Bundle extension config (`Configuration.php`), compiler passes for bootstrapper registration, Doctrine event subscribers, kernel events, Messenger middleware, PHPStan extensions, Flex recipe.
- **Target PHP/Symfony versions**: PHP 8.2+, Symfony 7.4+ / 8.x (LTS-first, then current).
- **Testing philosophy**: Comprehensive test coverage is a selling point — PHPUnit, tenant-aware test trait, isolated DB per test method, PHPStan at max level.

## Constraints

- **Tech stack**: PHP 8.2+, Symfony 7.4/8.x, Doctrine ORM, Flysystem, Symfony Messenger — no framework-agnostic abstractions; lean into Symfony contracts
- **Compatibility**: Must work with both `doctrine/orm` shared-DB and separate-DB without requiring either — drivers are optional dependencies
- **Extensibility**: Every major system (resolvers, bootstrappers, drivers) must be replaceable via the DI container — no hardcoded coupling
- **Zero-leak guarantee**: Strict mode must be on by default; data leaks across tenants are a security incident, not a config mistake
- **OSS quality bar**: PHPStan max level, full test coverage, Symfony coding standards (`php-cs-fixer`), CI on GitHub Actions

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Event-driven bootstrapping over direct service decoration | Events allow user-land bootstrappers without modifying bundle internals; matches stancl/tenancy's proven model | ✓ Good (validated Phase 01–06) |
| Hybrid bootstrapping: kernel events for infra, decorators for services | Kernel events handle connection swaps (coarse); decorators handle per-call service routing (fine-grained) | ✓ Good (validated Phase 05) |
| `#[TenantAware]` PHP attribute over YAML/XML config | Collocated with the entity; enforced by PHPStan; immediately visible to future devs | ✓ Good (validated Phase 04) |
| Strict mode ON by default | Security default — a data leak is worse than a 500; developers opt out explicitly | ✓ Good (validated Phase 04; StrictModeWithNullResolutionTest added in Phase 15) |
| Sequential migrations in v1 | Simplicity and correctness over speed; parallel via `symfony/process` deferred | ✓ Good (validated Phase 07) |
| DBAL 4 driver-middleware over `wrapperClass` + `ReflectionProperty` | Correct extension point for DBAL 4; `wrapperClass` could not mutate `Connection::$driver` (issues #7/#8) | ✓ Good (Phase 15; supersedes ISOL-01 mechanism) |
| `ResolverChain::resolve()` returns nullable instead of throwing | Public/landlord/health-check routes proceed without a tenant; narrow `TenantNotFoundException` to provider-level rejection | ✓ Good (Phase 15) |
| Full contract parity for cache decorators + compile-time guard | Liskov at the DI level — a decorator must honor every interface the decorated service exposes (issue #5) | ✓ Good (Phase 15) |
| Remove Symfony Flex recipe, use `tenancy:init` instead | Lower-maintenance, more discoverable, zero race with external recipe submission flow | ✓ Good (Phase 14) |
| Retract v1.0.0, restart at v0.1.0, graduate to v0.2.0 | Four defects (#5–#8) surfaced in downstream demo projects post-tag; architectural fixes rather than patches | ✓ Good (Phase 15 — semver integrity) |

---
*Last updated: 2026-04-21 — v0.2.0 shipped*
