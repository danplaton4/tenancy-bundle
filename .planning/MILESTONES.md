# Milestones

## v0.2 v0.2 (Shipped: 2026-04-20)

**Phases completed:** 15 phases, 48 plans, 82 tasks

**Key accomplishments:**

- Symfony AbstractBundle skeleton with BootstrapperChainPass (PriorityTaggedServiceTrait), services.php DI contract, and 3 passing compiler pass unit tests on a greenfield PHP 8.4 / Symfony 7.4 project.
- Zero-dependency TenantContext value holder, TenantBootstrapperInterface contract, and EventDispatcher-wired BootstrapperChain with 7 passing unit tests
- Three PSR-14 lifecycle event final classes with public readonly properties, 7 event unit tests, and 2 deferred BootstrapperChain dispatch tests — 28 unit tests total, all green
- Doctrine Tenant entity with slug string PK, 7 mapped fields, TenantInterface implementation, lifecycle timestamp callbacks, and 9 structural unit tests
- HTTP lifecycle entry point wired at kernel.request priority 20 with full Phase 1 integration test coverage: container compilation, listener priority, and end-to-end autoconfiguration of TenantBootstrapperInterface via registerForAutoconfiguration
- Chain-of-responsibility resolver infrastructure with Doctrine+cache provider, HTTP domain exceptions (404/403), compiler pass, and full DI wiring
- HostResolver with subdomain extraction: strips www prefix, handles multi-segment subdomains (api.acme.app.com -> acme), catches TenantNotFoundException, bubbles TenantInactiveException
- X-Tenant-ID header resolver (priority 20) and _tenant query param resolver (priority 10) — both delegate to TenantProviderInterface and catch TenantNotFoundException while letting TenantInactiveException bubble
- ConsoleResolver listens on ConsoleCommandEvent, adds --tenant to Application definition with input rebind, and orchestrates full tenant context (findBySlug + setTenant + boot + TenantResolved) for CLI commands
- One-liner:
- TenantDriverInterface marker interface and DatabaseSwitchBootstrapper established as the database-per-tenant driver, delegating boot/clear to TenantConnectionInterface with 4 passing unit tests
- DBAL 4 wrapperClass subclass that switches database connections at runtime via ReflectionProperty mutation of the private $params field, with merge semantics and close-on-switch
- tenancy.database.enabled config flag wires DatabaseSwitchBootstrapper and EntityManagerResetListener conditionally, with prependExtension targeting landlord EM mapping when enabled
- EntityManagerResetListener wired to TenantContextCleared via #[AsEventListener], calls resetManager('tenant') to close and recreate the tenant EM on every tenant teardown
- Dual-EM integration test suite with file-based SQLite proving ISOL-01 and ISOL-02: tenant A data invisible in tenant B context, landlord EM unaffected, TenantContextCleared resets tenant EM only
- prependExtension() conditionally routes Tenant entity mapping to `doctrine.orm.entity_managers.landlord.mappings` when `database.enabled=true`, preserving single-EM backward compatibility otherwise
- Doctrine SQLFilter `TenantAwareFilter` with 4-branch query interception, `#[TenantAware]` marker attribute, and `TenantMissingException` — the foundational types for shared-DB tenant isolation
- SharedDriver (TenantDriverInterface) implemented with boot() injecting TenantContext into TenantAwareFilter via setter injection, plus full TenancyBundle config wiring for shared_db driver including compile-time mutual exclusion guard
- End-to-end SQLite integration tests proving TenantAwareFilter scopes queries by tenant_id, non-TenantAware entities are unaffected, and strict mode throws TenantMissingException — 5 tests, 12 assertions, all green
- One-liner:
- Per-tenant cache namespace isolation via Symfony withSubNamespace() decorator on cache.app — transparent to all consumers, live TenantContext read on every operation
- One-liner:
- One-liner:
- Messenger middleware auto-enrolled in all Symfony buses via MessengerMiddlewarePass compiler pass, with 5 integration tests proving DI registration, stamp attachment, and context boot/teardown through a real kernel
- tenancy:migrate console command with per-tenant Doctrine Migrations execution, continue-on-failure loop, --tenant filter, and class_exists guard DI wiring
- tenancy:run console command spawning bin/console subprocess with --tenant= pass-through, forwarding stdout/stderr and propagating exit codes, via symfony/process promoted to production dependency
- Integration test suite proving tenancy:migrate and tenancy:run DI wiring via a stub-only CommandTestKernel that avoids DoctrineBundle proxy-factory failures
- InteractsWithTenancy trait with 6-method DX surface plus TenancyTestKernel database-per-tenant mode kernel and MakeTenancyTestServicesPublicPass for test container access
- 1. [Rule 1 - Bug] Fixed :memory: SQLite path override in InteractsWithTenancy::initializeTenant()
- composer.json enriched with Packagist discoverability metadata (keywords, authors, homepage, support URLs) and branch-alias dev-master → 1.0.x-dev for pre-release installs
- Flex recipe manifest.json and tenancy.yaml config stub scaffolded at flex/danplaton4/tenancy-bundle/1.0/ for symfony/recipes-contrib submission
- Raised all 11 Symfony constraints from ^7.0||^8.0 to ^7.4||^8.0, produced formal AUDIT-REPORT.md with guard/syntax/deprecation findings, enabled PHPUnit deprecation detection
- One-liner:
- MkDocs Material 9.7.6 site with three-tab navigation, PHP syntax highlighting, GitHub Pages deployment pipeline, landing page with comparison matrix, and 30 docs files establishing the full nav tree
- Five user guide pages written from source code with working PHP 8.2+ examples, YAML/PHP content tabs, and cross-page navigation covering the full installation-to-configuration critical path.
- 8 user guide pages covering database drivers, cache isolation, Messenger, CLI, testing, and two end-to-end SaaS tutorials — derived from actual source code with working PHP 8.2 examples
- tenancy:init console command scaffolds fully commented config/packages/tenancy.yaml with Doctrine-aware driver recommendation, overwrite protection, and next-steps guidance
- One-liner:
- Task 1 — cli-commands.md:
- ResolverChain::resolve() now returns a nullable TenantResolution value object — public routes proceed with empty TenantContext instead of a global 404, while strict_mode keeps data leaks sealed.
- TenantConnection + ReflectionProperty deleted; tenant database switching now routes through `Doctrine\DBAL\Driver\Middleware` — `$conn->close()` + lazy reconnect re-enters `TenantAwareDriver::connect()` with the fresh `TenantContext`, while the `['connection' => 'tenant']` tag prevents the landlord connection from ever seeing tenant params.
- Docs now describe post-Phase-15 architecture accurately — wrapperClass/reflection narrative is renamed/rewritten as driver-middleware, all sqlite:// placeholders for MySQL tenants are replaced with pdo_mysql samples, CHANGELOG [0.2.0] + UPGRADE 0.1→0.2 capture the full migration path, and scripts/docs-lint.sh prevents future drift.

---
