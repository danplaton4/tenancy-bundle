# Roadmap: Symfony Tenancy Bundle

## Overview

This bundle is built in eight sequential phases derived from the dependency graph: Core Foundation must exist before resolvers can fire, resolvers must work before drivers can be tested end-to-end, drivers must be stable before bootstrappers can be verified in isolation, and the entire feature surface must be complete before OSS hardening is meaningful. Each phase delivers one coherent, independently verifiable capability. The only exceptions are Phase 3 (database-per-tenant) and Phase 4 (shared-DB), which are distinct isolation strategies sharing a configuration surface — Phase 3 is built first because its two-EntityManager constraint shapes the bundle config that Phase 4 must accommodate.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [x] **Phase 1: Core Foundation** - TenantContext, lifecycle events, bootstrapper interface, Tenant entity, and kernel event wiring (completed 2026-03-18)
- [x] **Phase 2: Tenant Resolution** - All four resolvers, resolver chain, and kernel.request orchestrator (completed 2026-03-18)
- [x] **Phase 3: Database-Per-Tenant Driver** - DBAL wrapperClass connection switching with two named entity managers (completed 2026-03-19)
- [x] **Phase 4: Shared-DB Driver** - Doctrine SQL filter, #[TenantAware] attribute, and strict mode (completed 2026-03-19)
- [x] **Phase 5: Infrastructure Bootstrappers** - Doctrine bootstrapper (identity map safety) and Cache bootstrapper (namespace isolation) (completed 2026-03-19)
- [x] **Phase 6: Messenger Integration** - TenantStamp, sending middleware, and worker-side teardown middleware (completed 2026-03-20)
- [x] **Phase 7: CLI Commands** - tenancy:migrate and tenancy:run console commands (completed 2026-03-21)
- [x] **Phase 8: Developer Experience** - InteractsWithTenancy PHPUnit trait (completed 2026-04-02)
- [x] **Phase 9: OSS Hardening** - composer.json, README, Flex recipe, and GitHub Actions CI matrix (completed 2026-04-12)
- [x] **Phase 13: Audit Gap Closure** - config wiring, type safety, EM targeting, and composer.lock sync (completed 2026-04-13)
- [x] **Phase 15: Architectural Fixes (v0.2)** - Cache decorator contract completeness, resolver optionality, DBAL driver-middleware rewrite, docs alignment (completed 2026-04-20)

## Phase Details

### Phase 1: Core Foundation
**Goal**: The architectural skeleton exists — TenantContext holds the active tenant, lifecycle events fire at each stage, the bootstrapper interface and compiler pass are wired, and the Tenant entity lives in the landlord DB
**Depends on**: Nothing (first phase)
**Requirements**: CORE-01, CORE-02, CORE-03, CORE-04, CORE-05
**Success Criteria** (what must be TRUE):
  1. `TenantContext::setTenant()` stores a tenant and `TenantContext::getTenant()` returns it in the same request; zero circular dependency errors on `bin/console debug:container`
  2. `TenantBootstrapped` and `TenantContextCleared` events are dispatched at the correct lifecycle stages in Phase 1; `TenantResolved` dispatch is wired in Phase 2 when the resolver chain is connected. All three event classes exist and can be observed by any event listener
  3. A service tagged `tenancy.bootstrapper` is automatically discovered by the compiler pass and added to the bootstrapper chain without manual wiring
  4. The `Tenant` entity class correctly defines all fields (slug PK, domain, connection_config, name, is_active, timestamps) and implements `TenantInterface` — runtime DB persistence verified in Phase 3 when the landlord EntityManager is configured
  5. The `TenantContextOrchestrator` kernel.request listener is registered at priority 20 — after the router (32) and before the Security firewall (8)
**Plans:** 5/5 plans complete

Plans:
- [ ] 01-01-PLAN.md — Bundle skeleton: composer.json, PHPUnit config, TenancyBundle (AbstractBundle), BootstrapperChainPass, services.php
- [ ] 01-02-PLAN.md — Core contracts: TenantInterface, TenantContext (zero-dep value holder), TenantBootstrapperInterface, BootstrapperChain
- [ ] 01-03-PLAN.md — Lifecycle events: TenantResolved, TenantBootstrapped, TenantContextCleared (PSR-14 readonly objects)
- [ ] 01-04-PLAN.md — Tenant entity: Doctrine attribute-mapped entity with slug PK, implementing TenantInterface
- [ ] 01-05-PLAN.md — TenantContextOrchestrator (kernel.request priority 20, kernel.terminate teardown) and integration tests

### Phase 2: Tenant Resolution
**Goal**: Every production identification pattern (subdomain, custom domain, HTTP header, query param, CLI flag) resolves to an active tenant, and developers can inject custom resolvers without touching bundle internals
**Depends on**: Phase 1
**Requirements**: RESV-01, RESV-02, RESV-03, RESV-04, RESV-05
**Success Criteria** (what must be TRUE):
  1. A request to `tenant.app.com` or `tenant.com` resolves to the correct tenant via HostResolver by querying the landlord DB
  2. A request with `X-Tenant-ID: <slug>` header resolves to the correct tenant via HeaderResolver
  3. A request with `?_tenant=<slug>` resolves to the correct tenant via QueryParamResolver
  4. Running `bin/console any:command --tenant=<id>` resolves to the correct tenant via ConsoleResolver (fires on ConsoleCommandEvent, not kernel.request)
  5. A custom class implementing `TenantResolverInterface` and tagged with a DI priority attribute is discovered and inserted at the correct position in the resolution chain
**Plans:** 5/5 plans complete

Plans:
- [ ] 02-01-PLAN.md — TenantResolverInterface, ResolverChain, TenantProviderInterface, exceptions, and ResolverChainPass
- [ ] 02-02-PLAN.md — HostResolver (subdomain extraction from Host header)
- [ ] 02-03-PLAN.md — HeaderResolver and QueryParamResolver
- [ ] 02-04-PLAN.md — ConsoleResolver (ConsoleCommandEvent listener with --tenant option)
- [ ] 02-05-PLAN.md — TenantContextOrchestrator wired to ResolverChain and integration tests

### Phase 3: Database-Per-Tenant Driver
**Goal**: An active tenant's database connection is switched at runtime without rebuilding the container, and two named entity managers (landlord and tenant) are available and correctly scoped
**Depends on**: Phase 2
**Requirements**: ISOL-01, ISOL-02
**Success Criteria** (what must be TRUE):
  1. After `TenantContext::setTenant($tenantA)`, all Doctrine queries through the `tenant` entity manager hit Tenant A's database; after `setTenant($tenantB)`, they hit Tenant B's database — confirmed in the same process
  2. The `landlord` entity manager always reads from the central Tenant registry and is unaffected by tenant switches
  3. `TenantConnection::switchTenant()` changes connection parameters in DBAL 4 via the `wrapperClass` mechanism without calling deprecated APIs
  4. On tenant context clear, the tenant entity manager is reset (`resetManager()`) so no Tenant A entity is returned during a Tenant B request
**Plans:** 6/6 plans executed

Plans:
- [x] 03-01-PLAN.md — TenantDriverInterface and DatabaseSwitchBootstrapper (boot/clear delegation)
- [x] 03-02-PLAN.md — TenantConnection (DBAL 4 wrapperClass subclass with switchTenant/reset via reflection)
- [x] 03-03-PLAN.md — Bundle config (tenancy.database.enabled), conditional DI wiring, DoctrineTenantProvider rewiring to landlord EM
- [x] 03-04-PLAN.md — EntityManagerResetListener (resetManager('tenant') on TenantContextCleared)
- [x] 03-05-PLAN.md — Integration tests: cross-tenant query isolation and identity map teardown with dual-EM DoctrineTestKernel
- [x] 03-06-PLAN.md — Gap closure: conditional prependExtension targeting landlord EM mappings when database.enabled is true

### Phase 4: Shared-DB Driver
**Goal**: All Doctrine queries for entities marked #[TenantAware] are automatically scoped to the active tenant's ID via a SQL filter, and querying without an active tenant throws TenantMissingException
**Depends on**: Phase 3
**Requirements**: ISOL-03, ISOL-04, ISOL-05
**Success Criteria** (what must be TRUE):
  1. A Doctrine query for a `#[TenantAware]` entity automatically includes `WHERE tenant_id = :id` scoping — confirmed via SQL log — with no manual filter call required
  2. Switching tenant context changes the SQL filter's `tenant_id` parameter so queries return the new tenant's rows
  3. Querying a `#[TenantAware]` entity with no active tenant in strict mode (default: `true`) throws `TenantMissingException` rather than returning all rows
  4. An entity without `#[TenantAware]` is unaffected by the SQL filter and returns full result sets regardless of tenant context
**Plans:** 3/3 plans complete

Plans:
- [ ] 04-01-PLAN.md — TenantAware attribute, TenantMissingException, TenantAwareFilter + unit tests
- [ ] 04-02-PLAN.md — SharedDriver + TenancyBundle config wiring (shared_db driver, filter registration)
- [ ] 04-03-PLAN.md — Integration tests: SharedDbTestKernel, filter scoping, strict mode, attribute-less entity

### Phase 5: Infrastructure Bootstrappers
**Goal**: When a tenant is resolved, the Doctrine identity map is safe from cross-tenant pollution and the cache pool is isolated to the active tenant's namespace
**Depends on**: Phase 4
**Requirements**: BOOT-01, BOOT-02
**Success Criteria** (what must be TRUE):
  1. After a tenant switch, `EntityManager::clear()` has been called so no previously loaded entity from another tenant is returned from the identity map
  2. Cache keys written under Tenant A's context are not readable under Tenant B's context — verified by writing a key as Tenant A, switching to Tenant B, and confirming cache miss
  3. Clearing Tenant A's cache namespace does not invalidate any cache entries for Tenant B
  4. `EntityManagerResetListener` calls `resetManager()` (no argument) so it works in both `database_per_tenant` and `shared_db` driver modes
**Plans:** 3/3 plans complete

Plans:
- [ ] 05-01-PLAN.md — DoctrineBootstrapper (EM::clear on boot/clear), EntityManagerResetListener fix (resetManager() no-arg), DI wiring
- [ ] 05-02-PLAN.md — TenantAwareCacheAdapter (withSubNamespace decorator for cache.app), DI wiring
- [ ] 05-03-PLAN.md — Integration tests: identity map isolation and cache namespace isolation with BootstrapperTestKernel

### Phase 6: Messenger Integration
**Goal**: Tenant context is preserved across process boundaries — dispatched messages carry the active tenant, and worker handlers run with the correct tenant context restored and guaranteed torn down
**Depends on**: Phase 5
**Requirements**: MSG-01, MSG-02, MSG-03
**Success Criteria** (what must be TRUE):
  1. Every envelope dispatched while a tenant context is active contains a `TenantStamp` carrying that tenant's identifier
  2. A worker processing a stamped message boots the correct tenant context (all bootstrappers run) before the handler is invoked
  3. After the handler completes — including when the handler throws an exception — the tenant context is cleared via a `try/finally` block, leaving the worker in a clean state for the next message
  4. Two messages with different `TenantStamp` identifiers processed sequentially in the same worker process load the correct tenant for each and do not share any context
**Plans:** 2/2 plans executed

Plans:
- [x] 06-01-PLAN.md — TenantStamp + TenantSendingMiddleware + TenantWorkerMiddleware with unit tests, composer.json suggest entry
- [x] 06-02-PLAN.md — DI wiring (services.php + prependExtension bus enrollment) and integration tests with MessengerTestKernel

### Phase 7: CLI Commands
**Goal**: Operators can run Doctrine migrations for all tenants from a single command and can execute any Symfony console command scoped to a specific tenant
**Depends on**: Phase 5
**Requirements**: CLI-01, CLI-02
**Success Criteria** (what must be TRUE):
  1. `bin/console tenancy:migrate` runs Doctrine migrations for every tenant in the landlord DB sequentially and reports per-tenant success or failure without stopping on the first error
  2. `bin/console tenancy:run <tenantId> "app:some-command arg"` executes the inner command with full tenant context bootstrapped (database, cache) and clears context after completion
  3. `tenancy:migrate` accepts a `--tenant=<id>` filter to run migrations for a single tenant only
**Plans:** 3/3 plans executed

Plans:
- [x] 07-01-PLAN.md — findAll() provider method, tenancy:migrate command, DI wiring with class_exists guard, unit tests
- [x] 07-02-PLAN.md — tenancy:run subprocess command, DI wiring, unit tests
- [x] 07-03-PLAN.md — Integration tests: CommandTestKernel, DI wiring verification for both commands

### Phase 8: Developer Experience
**Goal**: Tests that use the bundle can initialize a clean tenant context in one method call, with automatic teardown between test methods
**Depends on**: Phase 7
**Requirements**: DX-01
**Success Criteria** (what must be TRUE):
  1. A test extending `KernelTestCase` that uses `InteractsWithTenancy` can call `$this->initializeTenant($id)` to boot the tenant context (database schema, bootstrappers) for that test method
  2. Tenant context is automatically cleared in `tearDown()` even when `setUp()` or the test method throws an exception
  3. Two test methods using different tenant IDs do not share any database state or cache entries
**Plans:** 2/2 plans complete

Plans:
- [x] 08-01-PLAN.md — InteractsWithTenancy trait, TenancyTestKernel, MakeTenancyTestServicesPublicPass
- [x] 08-02-PLAN.md — Integration tests: initializeTenant, tearDown cleanup, two-tenant isolation, assertion helpers

### Phase 9: OSS Hardening
**Goal**: The bundle is Packagist-ready, installs with zero manual configuration via Symfony Flex, and the CI matrix enforces quality on every supported PHP and Symfony version
**Depends on**: Phase 8
**Requirements**: OSS-01, OSS-02, OSS-03, OSS-04
**Success Criteria** (what must be TRUE):
  1. `composer require` installs the bundle on PHP 8.2, 8.3, and 8.4 against Symfony 6.4 and 7.4 without any `composer.json` constraint conflicts
  2. `composer require` with Flex auto-registers the bundle in `config/bundles.php` and creates a `config/packages/tenancy.yaml` stub with sensible defaults
  3. The README contains a 30-second quick-start (install, add `#[TenantAware]`, subdomain resolves) and a comparison table showing capabilities vs. RamyHakam/manual implementations
  4. GitHub Actions CI passes the full test suite, PHPStan at level 9, and php-cs-fixer on every combination of PHP 8.2/8.3/8.4 and Symfony 6.4/7.4
**Plans:** 5/5 plans executed

Plans:
- [x] 09-01: composer.json (Packagist constraints, soft dependencies on doctrine/doctrine-bundle and doctrine/migrations-bundle, extra.symfony config)
- [x] 09-02: Symfony Flex recipe (manifest.json, config/packages/tenancy.yaml stub)
- [x] 09-03: README.md (headline, 30-second quick-start, comparison table, philosophy section)
- [x] 09-04: GitHub Actions CI matrix (PHP 8.2/8.3/8.4 x Symfony 7.4/8.0, PHPStan level 9, php-cs-fixer)
- [x] 09-05: CHANGELOG.md, UPGRADE.md, final polish pass

## Progress

**Execution Order:**
Phases execute in numeric order: 1 -> 2 -> 3 -> 4 -> 5 -> 6 -> 7 -> 8 -> 9 -> 10 -> 11 -> 12 -> 13 -> 14 -> 15

| Phase                           | Plans Complete | Status      | Completed  |
|---------------------------------|----------------|-------------|------------|
| 1. Core Foundation              | 5/5            | Complete    | 2026-03-18 |
| 2. Tenant Resolution            | 5/5            | Complete    | 2026-03-18 |
| 3. Database-Per-Tenant Driver   | 6/6            | Complete    | 2026-03-19 |
| 4. Shared-DB Driver             | 3/3            | Complete    | 2026-03-19 |
| 5. Infrastructure Bootstrappers | 3/3            | Complete    | 2026-03-19 |
| 6. Messenger Integration        | 2/2            | Complete    | 2026-03-20 |
| 7. CLI Commands                 | 3/3            | Complete    | 2026-03-21 |
| 8. Developer Experience         | 2/2            | Complete    | 2026-04-02 |
| 9. OSS Hardening                | 5/5            | Complete    | 2026-04-12 |
| 10. Dependency Compatibility Audit | 2/2 | Complete    | 2026-04-10 |
| 11. Documentation Site          | 3/5 | Complete    | 2026-04-13 |
| 12. Developer Onboarding        | 1/1 | Complete    | 2026-04-13 |
| 13. Audit Gap Closure           | 1/1 | Complete    | 2026-04-13 |
| 14. Documentation Refresh       | 2/2 | Complete    | 2026-04-14 |
| 15. Architectural Fixes (v0.2)  | 4/4 | Complete    | 2026-04-20 |

### Phase 10: Dependency Compatibility Audit

**Goal:** Audit and fix all dependency compatibility issues to ensure the bundle works reliably across PHP 8.2/8.3/8.4 x Symfony 7.4/8.0 with all optional dependency combinations. Produce a formal audit report, fix all issues found, and expand CI to cover all supported combos.
**Requirements**: D-01, D-02, D-03, D-04, D-05, D-06, D-07, D-08, D-09, D-10, D-11
**Depends on:** Phase 9
**Plans:** 2/2 plans complete

Plans:
- [x] 10-01-PLAN.md — Formal AUDIT-REPORT.md, composer.json Symfony floor raised to ^7.4||^8.0, PHPUnit deprecation detection, guard audit, PHP syntax scan
- [x] 10-02-PLAN.md — CI matrix expansion (prefer-lowest + no-messenger jobs), Symfony 6.4 reference cleanup in REQUIREMENTS.md and PROJECT.md

### Phase 11: Documentation Site — MkDocs Material docs with user guide, contributor guide, and architecture reference, deployed to GitHub Pages

**Goal:** Build a documentation site using MkDocs Material 9.7.6 with three audience tracks (User Guide, Contributor Guide, Architecture Reference), deployed to GitHub Pages via a dedicated docs.yml workflow. Covers installation, configuration, all resolvers, both database drivers, cache isolation, Messenger integration, CLI commands, testing trait, strict mode, real-world examples, contributor setup, test infrastructure, extension points, and design decisions.
**Requirements**: DOC-01 through DOC-17 (internal tracking)
**Depends on:** Phase 10
**Plans:** 3/5 plans complete

Plans:
- [x] 11-01-PLAN.md — MkDocs infrastructure: mkdocs.yml, docs/requirements.txt, .github/workflows/docs.yml, landing page, section index pages, all stubs
- [x] 11-02-PLAN.md — User Guide core: installation, getting-started, configuration reference, resolvers, strict mode
- [x] 11-03-PLAN.md — User Guide features: database-per-tenant, shared-db, cache, messenger, CLI, testing, examples
- [ ] 11-04-PLAN.md — Contributor Guide: setup, architecture overview, test infrastructure, coding standards, PR workflow, custom resolver, custom bootstrapper
- [ ] 11-05-PLAN.md — Architecture Reference: event lifecycle, DI compilation, DBAL wrapper, SQL filter, messenger lifecycle, design decisions

### Phase 12: Developer Onboarding — tenancy:init scaffolding command that creates config/packages/tenancy.yaml with commented defaults, detects Doctrine presence, suggests driver, and prints next-steps guidance. Zero Flex dependency.

**Goal:** New users can run `bin/console tenancy:init` to scaffold a fully commented `config/packages/tenancy.yaml` with all configuration keys, get driver recommendations based on their installed packages (Doctrine detection), and receive next-steps guidance — all without requiring Symfony Flex or MakerBundle.
**Requirements**: DX-01, DX-02, DX-03, DX-04, DX-05 (internal tracking)
**Depends on:** Phase 11
**Plans:** 1/1 plans complete

Plans:
- [x] 12-01-PLAN.md — TenantInitCommand (YAML template, Doctrine detection, overwrite protection, next-steps guidance), DI wiring, unit + integration tests

### Phase 13: Audit Gap Closure — config wiring, type safety, and composer.lock

**Goal:** Close all gaps identified in the v1.0 milestone audit: fix stale composer.lock (OSS-01), wire `tenancy.resolvers` config to actually filter active resolvers (RESV-05), fix TenantMigrateCommand nullable type mismatch (CLI-01), wire `cache_prefix_separator` into TenantAwareCacheAdapter (BOOT-02), and correct EntityManagerResetListener + DoctrineBootstrapper to target the tenant EM in database_per_tenant mode (BOOT-01).
**Requirements**: OSS-01, RESV-05, CLI-01, BOOT-02, BOOT-01
**Gap Closure:** Closes gaps from v1.0-MILESTONE-AUDIT.md
**Depends on:** Phase 12
**Plans:** 1/1 plans complete

Plans:
- [x] 13-01-PLAN.md — All gap fixes: composer.lock sync, resolver config filtering, TenantMigrateCommand type safety, cache_prefix_separator wiring, EM targeting corrections

### Phase 14: Documentation refresh — remove Flex, update docs for phase 12-13 changes

**Goal:** Remove all Symfony Flex artifacts (flex/ directory, extra.symfony in composer.json) and references from documentation. Update all docs to reflect Phase 12 (tenancy:init command as primary setup path) and Phase 13 (resolver config filtering, cache_prefix_separator default change to '.', EntityManagerResetListener EM targeting). Fix stale passages across seven files, add the tenancy:init command section, and update the DI Compilation architecture doc.
**Requirements**: DOC-REFRESH
**Depends on:** Phase 13
**Plans:** 2/2 plans complete

Plans:
- [x] 14-01-PLAN.md — Flex removal: delete flex/ directory, remove extra.symfony from composer.json, purge all Flex references from installation.md/index.md/README.md, replace with tenancy:init as primary setup path
- [x] 14-02-PLAN.md — Phase 12-13 doc accuracy: tenancy:init CLI docs section, cache_prefix_separator '.' fix, resolver custom pass-through note, EM reset scoping, DI compilation ResolverChainPass update + TenantInitCommand service row

### Phase 15: Architectural Fixes (v0.2) — cache decorator contract, resolver optionality, DBAL driver-middleware rewrite, docs alignment

**Goal:** The bundle boots in a stock `composer require symfony/skeleton + doctrine/orm + danplaton4/tenancy-bundle` project on Symfony 7.4 and 8.0 with zero patches. Four defects surfaced post-tag in downstream demo projects (issues #5–#8) are resolved at the architectural level — not as surface patches — so the resulting code is correct by construction: the cache adapter decorator honors every contract `cache.app` exposes, the resolver chain treats "no resolver matched" as a nullable return (public/landlord routes proceed), and database-per-tenant connection switching uses DBAL 4's `Doctrine\DBAL\Driver\Middleware` extension point instead of `wrapperClass` + reflection against an immutable `Connection::$driver`. Documentation is refreshed for accuracy only. Release target: v0.2.0.

**Requirements**: FIX-01, FIX-02, FIX-03, FIX-04

**Depends on:** Phase 14

**Success Criteria** (what must be TRUE):
  1. A fresh Symfony 7.4 project with `composer require danplaton4/tenancy-bundle doctrine/orm doctrine/doctrine-bundle` boots `bin/console cache:clear` with no TypeError — the decorated `cache.app` substitutes cleanly wherever `CacheInterface` is type-hinted (including `DoctrineTenantProvider`)
  2. A `GET /` request with no tenant resolver match returns whatever the controller returns (200, landlord page, health check response) — `TenantNotFoundException` is not thrown by the chain; `TenantContext` remains empty; the orchestrator does not invoke the bootstrapper chain
  3. In `database_per_tenant` mode, `TenantA` and `TenantB` requests executed in the same process hit two different MySQL databases — verified by a real integration test, not mock-level params assertions; the driver used at connect-time is the driver resolved from the landlord's real params, not an SQLite placeholder
  4. `TenantConnection` class + `ReflectionProperty`-based switching are removed from the codebase (or deprecated with tests pinning the new middleware path). `DatabaseSwitchBootstrapper::boot()` only calls `$connection->close()`; no param mutation
  5. Documentation (architecture reference, database-per-tenant guide, `tenancy:init` YAML template) contains zero mentions of `wrapperClass`, `ReflectionProperty`, or hard-coded `sqlite://` placeholder URLs as the recommended landlord config for non-SQLite tenants
  6. Full PHPUnit suite + PHPStan level 9 + php-cs-fixer all pass; CI matrix (PHP 8.2/8.3/8.4 × Symfony 7.4/8.0) stays green

**Plans** (proposed — final split produced by gsd-planner):
- 15-01-PLAN.md — `TenantAwareCacheAdapter` full substitution surface: implement `CacheInterface` + `PruneableInterface` + `ResetInterface` alongside existing `AdapterInterface` + `NamespacedPoolInterface`; conditional tag-aware sibling; widen `$inner` intersection; delegate `get()`/`delete()`/`prune()`/`reset()`; compile-time DI check against decorated service contracts; integration test with a stock Symfony kernel boot.
- 15-02-PLAN.md — `ResolverChain::resolve(): ?TenantResolution` + new `TenantResolution` value object; orchestrator branches on null (no context set, no bootstrappers fired, no `TenantResolved` event); narrow `TenantNotFoundException` to resolver/provider-thrown; update `DoctrineTenantProvider.findBySlug` signature contract; add `#[RequiresTenant]` attribute + argument resolver as opt-in enforcement (stretch); integration tests for public/landlord/health-check routes.
- 15-03-PLAN.md — `TenantDriverMiddleware implements Doctrine\DBAL\Driver\Middleware` + `TenantAwareDriver` (wraps inner `Driver`, reads `TenantContext` on `connect()`); register via `doctrine.dbal.driver.middleware` service tag; remove `wrapperClass` from `TenantConnection` callsite in `tenancy:init` template; simplify `DatabaseSwitchBootstrapper::boot()` to `close()`; integration test with two MySQL dbnames under the same driver family; deprecate `TenantConnection`/`TenantConnectionInterface` (or delete outright if no downstream users — confirm via composer/Packagist metadata).
- 15-04-PLAN.md — Docs refresh: `docs/architecture/dbal-wrapper.md` rewritten as `dbal-middleware.md` (or in-place rewrite); `docs/user-guide/database-per-tenant.md` placeholder config examples use MySQL, not SQLite; `tenancy:init` YAML template tenant-driver-family placeholder; CHANGELOG 0.2.0 entry with retrospective note; UPGRADE.md 0.1 → 0.2 migration section (one-paragraph impact summary for any consumer who forked `TenantConnection`); DI compilation reference updated for driver-middleware wiring.

**Plans:** 4/4 plans complete