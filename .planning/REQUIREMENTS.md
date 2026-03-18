# Requirements: Symfony Tenancy Bundle

**Defined:** 2026-03-17
**Core Value:** When a tenant is resolved, every Symfony service automatically re-configures itself for that tenant — zero boilerplate, zero leaks, zero guessing.

## v1 Requirements

### Core Foundation

- [x] **CORE-01**: Bundle provides a stateful `TenantContext` service (leaf-node, no circular deps) that all tenant-aware services read at call time
- [x] **CORE-02**: Bundle fires `TenantResolved`, `TenantBootstrapped`, and `TenantContextCleared` Symfony events at each lifecycle stage
- [x] **CORE-03**: Bundle provides `TenantBootstrapperInterface`; a compiler pass auto-tags implementations so users register bootstrappers via DI config only
- [x] **CORE-04**: Bundle ships a `Tenant` Doctrine entity in the landlord DB with slug, domain, connection config, and status fields
- [x] **CORE-05**: Tenant resolution fires at `kernel.request` priority 20 — after the router (32) and before the security firewall (8) — preventing controller constructors from receiving un-tenanted service instances

### Tenant Resolution

- [x] **RESV-01**: `HostResolver` identifies the active tenant from subdomain (`tenant.app.com`) or full custom domain (`tenant.com`) by querying the landlord DB
- [x] **RESV-02**: `HeaderResolver` identifies the active tenant from the `X-Tenant-ID` HTTP request header (API-first: mobile apps, SPAs)
- [x] **RESV-03**: `QueryParamResolver` identifies the active tenant from the `?_tenant=` query parameter (debugging, previews)
- [ ] **RESV-04**: `ConsoleResolver` identifies the active tenant from the `--tenant=ID` CLI option, firing on `ConsoleCommandEvent` (not `kernel.request`)
- [x] **RESV-05**: Resolver chain is configurable: developers register custom resolvers implementing `TenantResolverInterface`; execution order is controlled by DI tag priority

### Database Isolation

- [ ] **ISOL-01**: Database-per-tenant driver switches the DBAL connection at runtime using DBAL 4's `wrapperClass` pattern (`TenantConnection::switchTenant()`) without rebuilding the container
- [ ] **ISOL-02**: Database-per-tenant driver configures two named entity managers: `landlord` (static, reads central Tenant registry) and `tenant` (runtime-switched to active tenant DB)
- [ ] **ISOL-03**: Shared-DB driver registers a Doctrine SQL Filter (`TenantAwareFilter`) that appends `tenant_id = :id` to every query for entities marked `#[TenantAware]`
- [ ] **ISOL-04**: `#[TenantAware]` PHP attribute marks Doctrine entities for automatic SQL filter scoping in shared-DB mode
- [ ] **ISOL-05**: `strict_mode` config option (default: `true`) throws `TenantMissingException` when a `#[TenantAware]` entity is queried with no active tenant context, instead of returning all rows

### Bootstrappers

- [ ] **BOOT-01**: Doctrine bootstrapper enables the SQL filter and injects `tenant_id`, and calls `EntityManager::clear()` on every tenant context switch to prevent identity map pollution
- [ ] **BOOT-02**: Cache bootstrapper isolates tenant cache at the namespace level by decorating the `cache.app` pool with a per-tenant namespace (not a key-prefix hack)

### Messenger / Context Preservation

- [ ] **MSG-01**: `TenantStamp` is a custom Symfony Messenger stamp that carries the active tenant identifier across process boundaries
- [ ] **MSG-02**: Sending middleware automatically attaches `TenantStamp` to every dispatched envelope when a tenant context is active
- [ ] **MSG-03**: Worker-side middleware re-boots the tenant context from `TenantStamp` before the handler runs and clears it in a `try/finally` block — guaranteeing teardown even on handler exception

### CLI Commands

- [ ] **CLI-01**: `tenancy:migrate` runs Doctrine migrations for every tenant sequentially, reporting per-tenant success/failure
- [ ] **CLI-02**: `tenancy:run {tenantId} "command:name arg1"` wraps any Symfony console command with full tenant context bootstrapped

### Developer Experience / Testing

- [ ] **DX-01**: `InteractsWithTenancy` PHPUnit trait for `KernelTestCase`/`WebTestCase` provides `$this->initializeTenant($id)` which sets up a clean tenant DB/schema and boots the tenant context for each test method

### OSS Release

- [ ] **OSS-01**: `composer.json` is Packagist-ready with PHP `^8.2`, Symfony `^6.4|^7.0` constraints, soft dependencies on `doctrine/orm` and `doctrine/migrations`, and correct `extra.symfony` bundle configuration
- [ ] **OSS-02**: `README.md` contains: compelling headline, 30-second quick-start (composer install → `#[TenantAware]` → subdomain works), comparison table vs RamyHakam/manual implementation, and philosophy section explaining the kernel-extension model
- [ ] **OSS-03**: Symfony Flex recipe auto-configures the bundle in `config/bundles.php` and creates a `config/packages/tenancy.yaml` stub on `composer require`
- [ ] **OSS-04**: GitHub Actions CI runs the full test suite on a PHP 8.2/8.3/8.4 × Symfony 6.4/7.4 matrix with PHPStan and php-cs-fixer checks

## v1.1 Requirements

### Infrastructure Bootstrappers

- **BOOT-03**: Filesystem bootstrapper decorates Flysystem's `FilesystemOperator` to prefix all paths per tenant (`uploads/` → `uploads/tenant_1/`)
- **BOOT-04**: Mailer bootstrapper swaps SMTP transport and `From` headers per tenant

### Advanced Isolation

- **ISOL-06**: PostgreSQL Row-Level Security (RLS) driver: per-tenant DB user with PG policies ensuring users can only read/write their own data
- **ISOL-07**: Parallel `tenancy:migrate` using `symfony/process` — concurrent migrations for 100+ tenants (speedup proportional to CPU cores)

### Resource Sharing

- **SHARE-01**: Resource sharing config designates global entities (e.g. `User`) to sync across all tenant DBs
- **SHARE-02**: Sync mode replicates shared entity changes immediately via Doctrine events on persist/update in landlord DB
- **SHARE-03**: Async mode fans out shared entity changes via Symfony Messenger (eventually consistent), configurable per resource type

### Developer Experience

- **DX-02**: Symfony Profiler "Tenancy" Web Debug Toolbar tab shows active tenant, ID, and DB connection used for each request
- **DX-03**: PHPStan extension with rules enforcing `#[TenantAware]` usage correctness and flagging unguarded native queries in shared-DB mode

### Resolvers

- **RESV-06**: `OriginHeaderResolver` identifies tenant from the `Origin` HTTP header (SPA-friendly alternative to X-Tenant-ID)

### Operations

- **OPS-01**: Tenant-specific maintenance mode: take a single tenant offline without affecting others
- **OPS-02**: Health check integration: verify tenant DB connections are reachable (MonitorBundle compatible)

## Out of Scope

| Feature | Reason |
|---------|--------|
| Per-tenant middleware pipelines | High complexity; correct bootstrapping at kernel.request level achieves the same isolation goal for v1 |
| DNS TXT resolver | Niche use case; custom `TenantResolverInterface` implementation covers it without bundle complexity |
| Multiple DB engines (Redis, MongoDB as primary) | v1 focuses on MySQL/MariaDB + PostgreSQL via DBAL; other engines are v2+ |
| Tenant-aware job scheduler | Scheduler integration is complex and low adoption; Messenger covers async context propagation |
| Multi-region / sharding | Infrastructure concern outside bundle scope |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| CORE-01 | Phase 1 | Complete |
| CORE-02 | Phase 1 | Complete |
| CORE-03 | Phase 1 | Complete |
| CORE-04 | Phase 1 | Complete |
| CORE-05 | Phase 1 | Complete |
| RESV-01 | Phase 2 | Complete |
| RESV-02 | Phase 2 | Complete |
| RESV-03 | Phase 2 | Complete |
| RESV-04 | Phase 2 | Pending |
| RESV-05 | Phase 2 | Complete |
| ISOL-01 | Phase 3 | Pending |
| ISOL-02 | Phase 3 | Pending |
| ISOL-03 | Phase 4 | Pending |
| ISOL-04 | Phase 4 | Pending |
| ISOL-05 | Phase 4 | Pending |
| BOOT-01 | Phase 5 | Pending |
| BOOT-02 | Phase 5 | Pending |
| MSG-01 | Phase 6 | Pending |
| MSG-02 | Phase 6 | Pending |
| MSG-03 | Phase 6 | Pending |
| CLI-01 | Phase 7 | Pending |
| CLI-02 | Phase 7 | Pending |
| DX-01 | Phase 8 | Pending |
| OSS-01 | Phase 9 | Pending |
| OSS-02 | Phase 9 | Pending |
| OSS-03 | Phase 9 | Pending |
| OSS-04 | Phase 9 | Pending |

**Coverage:**
- v1 requirements: 27 total
- Mapped to phases: 27
- Unmapped: 0

---
*Requirements defined: 2026-03-17*
*Last updated: 2026-03-17 after roadmap creation*
