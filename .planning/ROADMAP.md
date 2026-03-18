# Roadmap: Symfony Tenancy Bundle

## Overview

This bundle is built in eight sequential phases derived from the dependency graph: Core Foundation must exist before resolvers can fire, resolvers must work before drivers can be tested end-to-end, drivers must be stable before bootstrappers can be verified in isolation, and the entire feature surface must be complete before OSS hardening is meaningful. Each phase delivers one coherent, independently verifiable capability. The only exceptions are Phase 3 (database-per-tenant) and Phase 4 (shared-DB), which are distinct isolation strategies sharing a configuration surface — Phase 3 is built first because its two-EntityManager constraint shapes the bundle config that Phase 4 must accommodate.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [x] **Phase 1: Core Foundation** - TenantContext, lifecycle events, bootstrapper interface, Tenant entity, and kernel event wiring (completed 2026-03-18)
- [ ] **Phase 2: Tenant Resolution** - All four resolvers, resolver chain, and kernel.request orchestrator
- [ ] **Phase 3: Database-Per-Tenant Driver** - DBAL wrapperClass connection switching with two named entity managers
- [ ] **Phase 4: Shared-DB Driver** - Doctrine SQL filter, #[TenantAware] attribute, and strict mode
- [ ] **Phase 5: Infrastructure Bootstrappers** - Doctrine bootstrapper (identity map safety) and Cache bootstrapper (namespace isolation)
- [ ] **Phase 6: Messenger Integration** - TenantStamp, sending middleware, and worker-side teardown middleware
- [ ] **Phase 7: CLI Commands** - tenancy:migrate and tenancy:run console commands
- [ ] **Phase 8: Developer Experience** - InteractsWithTenancy PHPUnit trait
- [ ] **Phase 9: OSS Hardening** - composer.json, README, Flex recipe, and GitHub Actions CI matrix

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
**Plans:** 4/5 plans executed

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
**Plans**: TBD

Plans:
- [ ] 03-01: TenantDriverInterface and DatabaseDriver skeleton
- [ ] 03-02: TenantConnection (DBAL wrapperClass subclass with switchTenant())
- [ ] 03-03: Landlord/tenant dual EntityManager configuration and DI wiring
- [ ] 03-04: EntityManager reset on TenantContextCleared event
- [ ] 03-05: Integration tests — cross-tenant query isolation and identity map teardown

### Phase 4: Shared-DB Driver
**Goal**: All Doctrine queries for entities marked #[TenantAware] are automatically scoped to the active tenant's ID via a SQL filter, and querying without an active tenant throws TenantMissingException
**Depends on**: Phase 3
**Requirements**: ISOL-03, ISOL-04, ISOL-05
**Success Criteria** (what must be TRUE):
  1. A Doctrine query for a `#[TenantAware]` entity automatically includes `WHERE tenant_id = :id` scoping — confirmed via SQL log — with no manual filter call required
  2. Switching tenant context changes the SQL filter's `tenant_id` parameter so queries return the new tenant's rows
  3. Querying a `#[TenantAware]` entity with no active tenant in strict mode (default: `true`) throws `TenantMissingException` rather than returning all rows
  4. An entity without `#[TenantAware]` is unaffected by the SQL filter and returns full result sets regardless of tenant context
**Plans**: TBD

Plans:
- [ ] 04-01: #[TenantAware] PHP attribute
- [ ] 04-02: TenantAwareFilter (Doctrine SQL filter implementing addFilterConstraint)
- [ ] 04-03: SharedDriver and strict mode (TenantMissingException)
- [ ] 04-04: Integration tests — filter scoping, strict mode throw, and attribute-less entity unaffected

### Phase 5: Infrastructure Bootstrappers
**Goal**: When a tenant is resolved, the Doctrine identity map is safe from cross-tenant pollution and the cache pool is isolated to the active tenant's namespace
**Depends on**: Phase 4
**Requirements**: BOOT-01, BOOT-02
**Success Criteria** (what must be TRUE):
  1. After a tenant switch, `EntityManager::clear()` has been called so no previously loaded entity from another tenant is returned from the identity map
  2. Cache keys written under Tenant A's context are not readable under Tenant B's context — verified by writing a key as Tenant A, switching to Tenant B, and confirming cache miss
  3. Clearing Tenant A's cache namespace does not invalidate any cache entries for Tenant B
  4. The Doctrine bootstrapper enables the SQL filter and injects the correct `tenant_id` parameter on every `TenantResolved` event
**Plans**: TBD

Plans:
- [ ] 05-01: DoctrineBootstrapper (SQL filter enable/inject tenant_id, EntityManager::clear on switch)
- [ ] 05-02: CacheBootstrapper (adapter-level namespace isolation, not key-prefix)
- [ ] 05-03: Integration tests — identity map pollution prevention and cache namespace isolation

### Phase 6: Messenger Integration
**Goal**: Tenant context is preserved across process boundaries — dispatched messages carry the active tenant, and worker handlers run with the correct tenant context restored and guaranteed torn down
**Depends on**: Phase 5
**Requirements**: MSG-01, MSG-02, MSG-03
**Success Criteria** (what must be TRUE):
  1. Every envelope dispatched while a tenant context is active contains a `TenantStamp` carrying that tenant's identifier
  2. A worker processing a stamped message boots the correct tenant context (all bootstrappers run) before the handler is invoked
  3. After the handler completes — including when the handler throws an exception — the tenant context is cleared via a `try/finally` block, leaving the worker in a clean state for the next message
  4. Two messages with different `TenantStamp` identifiers processed sequentially in the same worker process load the correct tenant for each and do not share any context
**Plans**: TBD

Plans:
- [ ] 06-01: TenantStamp (StampInterface implementation with serialization)
- [ ] 06-02: Sending middleware (auto-attach TenantStamp when context is active)
- [ ] 06-03: Worker-side middleware (boot from stamp, try/finally teardown)
- [ ] 06-04: Integration tests — stamp injection, context restoration, teardown on exception, two-message sequential isolation

### Phase 7: CLI Commands
**Goal**: Operators can run Doctrine migrations for all tenants from a single command and can execute any Symfony console command scoped to a specific tenant
**Depends on**: Phase 5
**Requirements**: CLI-01, CLI-02
**Success Criteria** (what must be TRUE):
  1. `bin/console tenancy:migrate` runs Doctrine migrations for every tenant in the landlord DB sequentially and reports per-tenant success or failure without stopping on the first error
  2. `bin/console tenancy:run <tenantId> "app:some-command arg"` executes the inner command with full tenant context bootstrapped (database, cache) and clears context after completion
  3. `tenancy:migrate` accepts a `--tenant=<id>` filter to run migrations for a single tenant only
**Plans**: TBD

Plans:
- [ ] 07-01: tenancy:migrate command (sequential, per-tenant success/failure reporting, --tenant filter)
- [ ] 07-02: tenancy:run command (full bootstrapper chain, context clear after completion)
- [ ] 07-03: Integration tests — migrate reports failure without halt, tenancy:run boots and tears down context

### Phase 8: Developer Experience
**Goal**: Tests that use the bundle can initialize a clean tenant context in one method call, with automatic teardown between test methods
**Depends on**: Phase 7
**Requirements**: DX-01
**Success Criteria** (what must be TRUE):
  1. A test extending `KernelTestCase` that uses `InteractsWithTenancy` can call `$this->initializeTenant($id)` to boot the tenant context (database schema, bootstrappers) for that test method
  2. Tenant context is automatically cleared in `tearDown()` even when `setUp()` or the test method throws an exception
  3. Two test methods using different tenant IDs do not share any database state or cache entries
**Plans**: TBD

Plans:
- [ ] 08-01: InteractsWithTenancy trait (initializeTenant, clearTenant, tearDown wiring)
- [ ] 08-02: Tests of the trait itself — tearDown on throw, two methods with different tenants are isolated

### Phase 9: OSS Hardening
**Goal**: The bundle is Packagist-ready, installs with zero manual configuration via Symfony Flex, and the CI matrix enforces quality on every supported PHP and Symfony version
**Depends on**: Phase 8
**Requirements**: OSS-01, OSS-02, OSS-03, OSS-04
**Success Criteria** (what must be TRUE):
  1. `composer require` installs the bundle on PHP 8.2, 8.3, and 8.4 against Symfony 6.4 and 7.4 without any `composer.json` constraint conflicts
  2. `composer require` with Flex auto-registers the bundle in `config/bundles.php` and creates a `config/packages/tenancy.yaml` stub with sensible defaults
  3. The README contains a 30-second quick-start (install, add `#[TenantAware]`, subdomain resolves) and a comparison table showing capabilities vs. RamyHakam/manual implementations
  4. GitHub Actions CI passes the full test suite, PHPStan at level 9, and php-cs-fixer on every combination of PHP 8.2/8.3/8.4 and Symfony 6.4/7.4
**Plans**: TBD

Plans:
- [ ] 09-01: composer.json (Packagist constraints, soft dependencies on doctrine/doctrine-bundle and doctrine/migrations-bundle, extra.symfony config)
- [ ] 09-02: Symfony Flex recipe (manifest.json, config/packages/tenancy.yaml stub)
- [ ] 09-03: README.md (headline, 30-second quick-start, comparison table, philosophy section)
- [ ] 09-04: GitHub Actions CI matrix (PHP 8.2/8.3/8.4 x Symfony 6.4/7.4, PHPStan level 9, php-cs-fixer)
- [ ] 09-05: CHANGELOG.md, UPGRADE.md, final polish pass

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Core Foundation | 5/5 | Complete   | 2026-03-18 |
| 2. Tenant Resolution | 4/5 | In Progress|  |
| 3. Database-Per-Tenant Driver | 0/5 | Not started | - |
| 4. Shared-DB Driver | 0/4 | Not started | - |
| 5. Infrastructure Bootstrappers | 0/3 | Not started | - |
| 6. Messenger Integration | 0/4 | Not started | - |
| 7. CLI Commands | 0/3 | Not started | - |
| 8. Developer Experience | 0/2 | Not started | - |
| 9. OSS Hardening | 0/5 | Not started | - |
