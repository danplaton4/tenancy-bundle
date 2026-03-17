# Symfony Tenancy Bundle

## What This Is

A definitive multi-tenancy bundle for Symfony that treats tenancy as a first-class citizen of the Symfony kernel — not just a database switcher, but a **Context Orchestrator**. When a tenant is identified, the entire application state (database, cache, filesystem, messenger) automatically follows suit. Targeting public release on Packagist as the Symfony equivalent of `stancl/tenancy` for Laravel.

## Core Value

When a tenant is resolved, every Symfony service automatically re-configures itself for that tenant — zero boilerplate, zero leaks, zero guessing.

## Requirements

### Validated

(None yet — ship to validate)

### Active

**Tenant Resolution**
- [ ] HostResolver: identify tenant from subdomain (`tenant.app.com`) or full custom domain (`tenant.com`)
- [ ] HeaderResolver: identify tenant from `X-Tenant-ID` header (mobile/SPA/API-first)
- [ ] QueryParamResolver: identify tenant from `?_tenant=...` (debugging/previews)
- [ ] ConsoleResolver: identify tenant from `--tenant=ID` CLI flag
- [ ] Pluggable resolver chain: custom resolver interface, configurable priority order

**Database Isolation**
- [ ] Database-per-tenant driver: swap DBAL connection parameters at runtime per tenant
- [ ] Shared-database driver: Doctrine SQL Filter auto-enabled for entities marked `#[TenantAware]`
- [ ] `#[TenantAware]` attribute: marks Doctrine entities for automatic tenant scoping
- [ ] Tenant model in landlord DB: `Tenant` entity with id, slug, domain, connection config

**Tenant Lifecycle Bootstrappers**
- [ ] `TenantBootstrapperInterface`: extensible contract for custom bootstrappers
- [ ] Cache bootstrapper: prefix `cache.app` pool with `{tenant_id}:` automatically
- [ ] Filesystem bootstrapper: decorate Flysystem to prefix paths (`uploads/` → `uploads/tenant_1/`)
- [ ] Doctrine bootstrapper: enable SQL filter and inject `tenant_id` parameter
- [ ] Event-driven lifecycle: `TenantResolved`, `TenantBootstrapped`, `TenantContextCleared` events

**Fail-Safe / Strict Mode**
- [ ] `strict_mode` config option: throw `TenantMissingException` when querying `#[TenantAware]` entity with no active tenant (instead of returning all rows)

**Messenger / Context Preservation**
- [ ] `TenantStamp`: custom Messenger stamp attached to every envelope
- [ ] Sending middleware: automatically injects `TenantStamp` when dispatching messages
- [ ] Worker listener: re-boots tenant context from `TenantStamp` before handler runs

**CLI Commands**
- [ ] `tenancy:migrate`: run Doctrine migrations for all tenants (sequential in v1)
- [ ] `tenancy:run`: wrap any console command with tenant context: `bin/console tenancy:run {id} "app:cmd"`

**Developer Experience**
- [ ] Symfony Profiler integration: "Tenancy" Web Debug Toolbar tab showing active tenant, ID, DB connection
- [ ] `InteractsWithTenancy` PHPUnit trait for `WebTestCase`: `$this->initializeTenant($id)` sets up clean tenant DB/schema per test method
- [ ] PHPStan extension: rules for tenant-aware code correctness

**Resource Sharing**
- [ ] Shared resource configuration: designate global entities (e.g. `User`) to sync across tenant DBs
- [ ] Sync mode: immediate replication via Doctrine events on landlord DB persist/update
- [ ] Async mode: fan-out via Symfony Messenger (eventually consistent) — configurable per resource

**Documentation & OSS Positioning**
- [ ] README with "hook" headline, 30-second quick start, comparison table vs RamyHakam/manual, philosophy section
- [ ] Packagist-ready `composer.json` with Symfony Flex recipe

### Out of Scope (V1)

- **Per-tenant middleware pipelines** — powerful but complex; added post-v1 once core adoption is proven
- **Parallel tenant migrations** — v1 runs sequential; parallel via `symfony/process` is v1.1
- **PostgreSQL Row-Level Security (RLS)** — native RLS support deferred; shared-DB driver covers the use case for v1
- **Health checks / MonitorBundle integration** — operational concern, post-v1
- **Tenant-aware Mailer bootstrapper** — swapping SMTP transport per tenant is v1.1
- **DNS TXT resolver** — niche; custom resolver interface covers the use case
- **Multiple DB engines** — v1 focuses on MySQL/MariaDB + PostgreSQL via DBAL; Redis, MongoDB etc. are v2+

## Context

- **Ecosystem gap**: Existing Symfony tenancy packages (RamyHakam, manual SQL filter implementations) are partial solutions — they don't address Messenger, Cache, Filesystem, or provide a unified bootstrapping API.
- **Inspiration**: `stancl/tenancy` for Laravel is the gold standard — event-driven, bootstrapper-first, comprehensive. This bundle brings that philosophy to Symfony idioms (bundles, DI, events, attributes).
- **Symfony idioms to embrace**: Bundle extension config (`Configuration.php`), compiler passes for bootstrapper registration, Doctrine event subscribers, kernel events, Messenger middleware, PHPStan extensions, Flex recipe.
- **Target PHP/Symfony versions**: PHP 8.2+, Symfony 6.4+ / 7.x (LTS-first, then current).
- **Testing philosophy**: Comprehensive test coverage is a selling point — PHPUnit, tenant-aware test trait, isolated DB per test method, PHPStan at max level.

## Constraints

- **Tech stack**: PHP 8.2+, Symfony 6.4/7.x, Doctrine ORM, Flysystem, Symfony Messenger — no framework-agnostic abstractions; lean into Symfony contracts
- **Compatibility**: Must work with both `doctrine/orm` shared-DB and separate-DB without requiring either — drivers are optional dependencies
- **Extensibility**: Every major system (resolvers, bootstrappers, drivers) must be replaceable via the DI container — no hardcoded coupling
- **Zero-leak guarantee**: Strict mode must be on by default; data leaks across tenants are a security incident, not a config mistake
- **OSS quality bar**: PHPStan max level, full test coverage, Symfony coding standards (`php-cs-fixer`), CI on GitHub Actions

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Event-driven bootstrapping over direct service decoration | Events allow user-land bootstrappers without modifying bundle internals; matches stancl/tenancy's proven model | — Pending |
| Hybrid bootstrapping: kernel events for infra, decorators for services | Kernel events handle connection swaps (coarse); decorators handle per-call service routing (fine-grained) | — Pending |
| `#[TenantAware]` PHP attribute over YAML/XML config | Collocated with the entity; enforced by PHPStan; immediately visible to future devs | — Pending |
| Strict mode ON by default | Security default — a data leak is worse than a 500; developers opt out explicitly | — Pending |
| Sequential migrations in v1 | Simplicity and correctness over speed; parallel is v1.1 with `symfony/process` | — Pending |
| Resource sharing: both sync and async, configurable per resource | Sync is safe for small fleets; async is necessary for 100+ tenants | — Pending |

---
*Last updated: 2026-03-17 after initialization*
