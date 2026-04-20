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
- [x] **RESV-04**: `ConsoleResolver` identifies the active tenant from the `--tenant=ID` CLI option, firing on `ConsoleCommandEvent` (not `kernel.request`)
- [x] **RESV-05**: Resolver chain is configurable: developers register custom resolvers implementing `TenantResolverInterface`; execution order is controlled by DI tag priority

### Database Isolation

- [x] **ISOL-01**: Database-per-tenant driver switches the DBAL connection at runtime using DBAL 4's `wrapperClass` pattern (`TenantConnection::switchTenant()`) without rebuilding the container
- [x] **ISOL-02**: Database-per-tenant driver configures two named entity managers: `landlord` (static, reads central Tenant registry) and `tenant` (runtime-switched to active tenant DB)
- [x] **ISOL-03**: Shared-DB driver registers a Doctrine SQL Filter (`TenantAwareFilter`) that appends `tenant_id = :id` to every query for entities marked `#[TenantAware]`
- [x] **ISOL-04**: `#[TenantAware]` PHP attribute marks Doctrine entities for automatic SQL filter scoping in shared-DB mode
- [x] **ISOL-05**: `strict_mode` config option (default: `true`) throws `TenantMissingException` when a `#[TenantAware]` entity is queried with no active tenant context, instead of returning all rows

### Bootstrappers

- [x] **BOOT-01**: Doctrine bootstrapper enables the SQL filter and injects `tenant_id`, and calls `EntityManager::clear()` on every tenant context switch to prevent identity map pollution
- [x] **BOOT-02**: Cache bootstrapper isolates tenant cache at the namespace level by decorating the `cache.app` pool with a per-tenant namespace (not a key-prefix hack)

### Messenger / Context Preservation

- [x] **MSG-01**: `TenantStamp` is a custom Symfony Messenger stamp that carries the active tenant identifier across process boundaries
- [x] **MSG-02**: Sending middleware automatically attaches `TenantStamp` to every dispatched envelope when a tenant context is active
- [x] **MSG-03**: Worker-side middleware re-boots the tenant context from `TenantStamp` before the handler runs and clears it in a `try/finally` block — guaranteeing teardown even on handler exception

### CLI Commands

- [x] **CLI-01**: `tenancy:migrate` runs Doctrine migrations for every tenant sequentially, reporting per-tenant success/failure
- [x] **CLI-02**: `tenancy:run {tenantId} "command:name arg1"` wraps any Symfony console command with full tenant context bootstrapped

### Developer Experience / Testing

- [x] **DX-01**: `InteractsWithTenancy` PHPUnit trait for `KernelTestCase`/`WebTestCase` provides `$this->initializeTenant($id)` which sets up a clean tenant DB/schema and boots the tenant context for each test method
- [x] **DX-04**: `bin/console tenancy:init` scaffolds `config/packages/tenancy.yaml` with every top-level configuration key present and commented with inline documentation — zero manual YAML authoring required for first-boot
- [x] **DX-05**: `tenancy:init` detects whether Doctrine ORM is installed and recommends the appropriate driver (`database_per_tenant` when `Doctrine\ORM\EntityManagerInterface` exists, `shared_db` otherwise), emitting the driver recommendation alongside next-steps guidance in the console output

### OSS Release

- [ ] **OSS-01**: `composer.json` is Packagist-ready with PHP `^8.2`, Symfony `^7.4||^8.0` constraints, soft dependencies on `doctrine/orm` and `doctrine/migrations`, and correct `extra.symfony` bundle configuration
- [x] **OSS-02**: `README.md` contains: compelling headline, 30-second quick-start (composer install → `#[TenantAware]` → subdomain works), comparison table vs RamyHakam/manual implementation, and philosophy section explaining the kernel-extension model
- [x] **OSS-03**: Symfony Flex recipe auto-configures the bundle in `config/bundles.php` and creates a `config/packages/tenancy.yaml` stub on `composer require`
- [x] **OSS-04**: GitHub Actions CI runs the full test suite on a PHP 8.2/8.3/8.4 × Symfony 7.4/8.0 matrix with PHPStan and php-cs-fixer checks

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

## v0.2 Post-release Architectural Fixes

Four defects surfaced in downstream demo projects between tagging v1.0.0 and public release. The v1.0.0 tag was retracted and the line restarted at v0.1.0. These requirements describe the mature architectural fixes being applied in Phase 15 to graduate to v0.2.0.

### Decorator Completeness

- **FIX-01**: `TenantAwareCacheAdapter` implements the complete `cache.app` substitution surface (`AdapterInterface`, `CacheInterface`, `NamespacedPoolInterface`, `PruneableInterface`, `ResetInterface`), with a sibling decorator for `TagAwareAdapterInterface` when the inner pool is tag-aware. A decorator must honor every contract the decorated service exposes (Liskov at the DI level). Closes issue #5.

### Resolver Optionality

- **FIX-02**: `ResolverChain::resolve()` returns a nullable result (new `TenantResolution` value object). The orchestrator branches on null by leaving `TenantContext` empty and skipping the bootstrapper chain — public/landlord/health routes proceed without a tenant. `TenantNotFoundException` is narrowed to "an identifier was extracted but the provider rejected it" and thrown by resolvers/provider, never by the chain itself. Closes issue #6.

### DBAL Driver-Middleware Architecture

- **FIX-03**: Database-per-tenant connection switching migrates from DBAL 4 `wrapperClass` + `ReflectionProperty` mutation of `Connection::$params` to a `Doctrine\DBAL\Driver\Middleware` implementation. `TenantDriverMiddleware::wrap(Driver $driver)` returns a `TenantAwareDriver` whose `connect()` reads `TenantContext` and merges the active tenant's params before delegating to the wrapped driver. `DatabaseSwitchBootstrapper::boot()` reduces to `$connection->close()`; DBAL transparently reconnects through the middleware. Supersedes the ISOL-01 mechanism (same outcome, correct DBAL 4 extension point). Closes issues #7 and #8.

### Documentation Alignment

- **FIX-04**: Architecture reference, database-per-tenant guide, and `tenancy:init` placeholder output reflect the driver-middleware approach — no stale mentions of `wrapperClass`, `ReflectionProperty`, or `sqlite://` placeholder URLs for non-SQLite tenant databases. Scope is accuracy, not new docs.

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
| RESV-04 | Phase 2 | Complete |
| RESV-05 | Phase 2 | Complete |
| ISOL-01 | Phase 3 | Complete |
| ISOL-02 | Phase 3 | Complete |
| ISOL-03 | Phase 4 | Complete |
| ISOL-04 | Phase 4 | Complete |
| ISOL-05 | Phase 4 | Complete |
| BOOT-01 | Phase 5 | Complete |
| BOOT-02 | Phase 5 | Complete |
| MSG-01 | Phase 6 | Complete |
| MSG-02 | Phase 6 | Complete |
| MSG-03 | Phase 6 | Complete |
| CLI-01 | Phase 7 + Phase 13 (type fix) | Complete |
| CLI-02 | Phase 7 | Complete |
| DX-01 | Phase 8 | Complete |
| DX-04 | Phase 12 | Complete |
| DX-05 | Phase 12 + Phase 15 (testability seam) | Complete |
| OSS-01 | Phase 9 + Phase 13 (lock fix) | Pending |
| OSS-02 | Phase 9 | Complete |
| OSS-03 | Phase 9 | Complete |
| OSS-04 | Phase 9 | Complete |
| RESV-05 | Phase 2 + Phase 13 (config wiring) | Complete |
| BOOT-01 | Phase 5 + Phase 13 (EM targeting) | Complete |
| BOOT-02 | Phase 5 + Phase 13 (separator wiring) | Complete |
| FIX-01 | Phase 15 | Complete |
| FIX-02 | Phase 15 | Complete |
| FIX-03 | Phase 15 (supersedes ISOL-01 mechanism) | Complete |
| FIX-04 | Phase 15 | Complete |

**Coverage:**
- v1 requirements: 29 total
- v0.2 post-release fixes: 4 total
- Mapped to phases: 33
- Unmapped: 0

---
*Requirements defined: 2026-03-17*
*Last updated: 2026-04-13 after milestone audit gap closure*
*Last updated: 2026-03-17 after roadmap creation*
