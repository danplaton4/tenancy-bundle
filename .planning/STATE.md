---
gsd_state_version: 1.0
milestone: v1.0
milestone_name: milestone
status: Executing Phase 08
stopped_at: Completed 07-02-PLAN.md
last_updated: "2026-04-02T06:27:19.725Z"
progress:
  total_phases: 9
  completed_phases: 7
  total_plans: 29
  completed_plans: 27
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-03-17)

**Core value:** When a tenant is resolved, every Symfony service automatically re-configures itself for that tenant — zero boilerplate, zero leaks, zero guessing.
**Current focus:** Phase 08 — developer-experience

## Current Position

Phase: 08 (developer-experience) — EXECUTING
Plan: 1 of 2

## Performance Metrics

**Velocity:**

- Total plans completed: 5
- Average duration: ~5 min
- Total execution time: ~0.5 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 01-core-foundation | 5 | ~25 min | ~5 min |

**Recent Trend:**

- Last 5 plans: P01(4min), P02(2min), P03(2min), P04(1min), P05(6min)
- Trend: stable

*Updated after each plan completion*
| Phase 01 P01 | 4 | 3 tasks | 11 files |
| Phase 01-core-foundation P02 | 2 | 2 tasks | 6 files |
| Phase 01-core-foundation P03 | 2 | 3 tasks | 7 files |
| Phase 01-core-foundation P04 | 1 | 2 tasks | 2 files |
| Phase 01-core-foundation P05 | 6 | 4 tasks | 6 files |
| Phase 02-tenant-resolution P01 | 12 | 2 tasks | 17 files |
| Phase 02-tenant-resolution P02 | 2 | 1 task (TDD) | 3 files |
| Phase 02-tenant-resolution P03 | 4 | 1 tasks | 5 files |
| Phase 02-tenant-resolution P04 | 2 | 1 tasks | 3 files |
| Phase 02-tenant-resolution P05 | 7 | 2 tasks | 7 files |
| Phase 03-database-per-tenant-driver P02 | 2 | 1 tasks | 2 files |
| Phase 03-database-per-tenant-driver P01 | 3 | 1 tasks | 5 files |
| Phase 03-database-per-tenant-driver P03 | 5 | 1 tasks | 1 files |
| Phase 03-database-per-tenant-driver P04 | 3 | 1 tasks (TDD) | 2 files |
| Phase 03-database-per-tenant-driver P05 | 4 | 2 tasks | 5 files |
| Phase 03-database-per-tenant-driver P06 | 3 | 1 tasks | 2 files |
| Phase 04-shared-db-driver P01 | 3 | 2 tasks | 6 files |
| Phase 04-shared-db-driver P02 | 8 | 2 tasks | 3 files |
| Phase 04-shared-db-driver P03 | 8 | 2 tasks | 4 files |
| Phase 05 P01 | 2 | 2 tasks | 6 files |
| Phase 05 P02 | 3 | 2 tasks | 3 files |
| Phase 05 P03 | 3 | 2 tasks | 4 files |
| Phase 06-messenger-integration P01 | 2 | 2 tasks | 8 files |
| Phase 06-messenger-integration P02 | 20 | 2 tasks | 11 files |
| Phase 07-cli-commands P02 | 2 | 1 tasks | 4 files |

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.
Recent decisions affecting current work:

- [Pre-phase]: TenantContext must be a zero-dependency pure value holder — enforced from Phase 1 to prevent circular dependency at container compile time
- [Pre-phase]: kernel.request listener must register at priority 20 (above Security at 8, below Router at 32) — define PRIORITY constant so callers know the correct value
- [Pre-phase]: strict_mode defaults to ON — a data leak is worse than a 500; developers opt out explicitly
- [Pre-phase]: DoctrineBundle 3.x and MigrationsBundle 4.0 require PHP ^8.4 — treat as suggested/optional dependencies in composer.json, not hard requires
- [Phase 01]: TenantInterface in Tenancy\\Bundle root namespace; BootstrapperChain clear() runs in reverse order; stubs created in Plan 01 to unblock compiler pass tests; explicit ->args() for bundle services
- [Phase 01-core-foundation]: TenantBootstrapped event stub created in Plan 01-02 as Rule 3 auto-fix to unblock BootstrapperChain tests; Plan 01-03 owns full implementation
- [Phase 01-core-foundation]: BootstrapperChain::boot() collects bootstrapper FQCNs and passes them to TenantBootstrapped; EventDispatcher mock in tests accepts any dispatch() call
- [Phase 01-core-foundation]: TenantContext has zero constructor parameters — enforced by testHasZeroConstructorParameters reflection test
- [Phase 01-core-foundation]: TenantBootstrapped event stub created in Plan 01-02 (Rule 3) to unblock BootstrapperChain tests; Plan 01-03 adds full event test suite
- [Phase 01-core-foundation]: EventDispatcher mock in BootstrapperChainTest accepts any dispatch() call — avoids TenantBootstrapped runtime type check
- [Phase 01-core-foundation]: Tenant slug is the natural string PK — no separate auto-increment id column; no #[ORM\GeneratedValue] anywhere on the entity
- [Phase 01-core-foundation]: Unit tests use ReflectionClass to verify ORM attribute presence without DB — DB round-trip persistence deferred to Phase 3
- [Phase 01-core-foundation]: TenantBootstrapped updated from private readonly+getters to public readonly promoted properties to match PSR-14 plain object spec
- [Phase 01-core-foundation]: TenantContextCleared implemented as empty final class body — signal-only, no constructor, no properties
- [Phase 01-core-foundation]: BootstrapperChainTest uses willReturnCallback with reference capture to assert dispatched TenantBootstrapped payload and FQCN list
- [Phase 01-core-foundation P05]: SpyBootstrapper pattern used for testing against final BootstrapperChain class (cannot be mocked/extended)
- [Phase 01-core-foundation P05]: MakeBootstrapperChainPublicPass test compiler pass to expose private bundle services in integration tests without modifying production code
- [Phase 01-core-foundation P05]: setUpBeforeClass/tearDownAfterClass for kernel lifecycle in integration tests avoids PHPUnit risky-test warnings from kernel error handler registration
- [Phase 01-core-foundation P05]: TestKernel omits framework.router config — FrameworkBundle requires router.resource when router section present, not needed for DI integration tests
- [Phase 02-tenant-resolution]: ResolverChain::resolve() returns array{tenant, resolvedBy} (not value object) — simpler for callers at this stage
- [Phase 02-tenant-resolution]: DoctrineTenantProvider uses cache-then-check pattern: caches all tenants including inactive, checks is_active after cache retrieval to prevent DB hammering
- [Phase 02-tenant-resolution]: symfony/cache and symfony/console added to hard require — directly used by bundle classes
- [Phase 02-tenant-resolution P02]: HostResolver takes last subdomain segment before app_domain suffix as slug (api.acme.app.com -> acme); TenantNotFoundException caught (null), TenantInactiveException bubbles; priority 30
- [Phase 02-tenant-resolution]: HeaderResolver (priority 20) reads X-Tenant-ID header; QueryParamResolver (priority 10) reads _tenant query param — both catch TenantNotFoundException, let TenantInactiveException bubble
- [Phase 02-tenant-resolution]: ConsoleResolver operates independently from HTTP resolver chain — listens on ConsoleCommandEvent, not tagged as tenancy.resolver, orchestrates context directly without TenantContextOrchestrator
- [Phase 02-05]: NullTenantProvider and ReplaceTenancyProviderPass extracted to tests/Integration/Support/ so compiler pass classes are PSR-4 autoloaded and available across multiple test files
- [Phase 02-05]: StubResolver (real TenantResolverInterface implementation) used in unit tests instead of mocking ResolverChain (final class)
- [Phase 02-05]: MakeResolverChainPublicPass targets tenancy.resolver_chain definition ID + alias to expose private ResolverChain for test container inspection
- [Phase 03-database-per-tenant-driver]: TenantConnection uses ReflectionProperty on Connection::class 'params' (DBAL 4 private field) — switchTenant() merges tenant config over originalParams captured at constructor time, both methods call close() to force lazy reconnect
- [Phase 03-database-per-tenant-driver]: TenantConnectionInterface extracted alongside final TenantConnection — PHPUnit 11 ClassIsFinalException requires interface for mocking; DatabaseSwitchBootstrapper type-hints interface, not concrete class
- [Phase 03-database-per-tenant-driver]: TenantDriverInterface is a marker interface (no additional methods) — distinguishes isolation drivers from general bootstrappers in BootstrapperChain
- [Phase 03-database-per-tenant-driver]: database.enabled defaults false — opt-in flag preserves Phase 2 single-EM behavior; conditional services registered in loadExtension() not services.php; prependExtension reads getExtensionConfig() to pick correct EM mapping target
- [Phase 03-04]: resetManager('tenant') used not clear() — resetManager closes and recreates the EM to prevent identity map pollution across tenant switches; landlord EM never touched
- [Phase 03-05]: DoctrineBundle 2.x wraps EMs in lazy proxies — resetManager freshness proven via UoW spl_object_id not proxy object_id
- [Phase 03-05]: setUpBeforeClass deletes shared landlord DB file before kernel boot to prevent table-exists errors on re-runs
- [Phase 03-database-per-tenant-driver]: prependExtension reads getExtensionConfig('tenancy') raw arrays and branches on database.enabled: landlord EM mappings when true, orm.mappings when false/absent
- [Phase 04-shared-db-driver]: TenantAwareFilter uses setter injection (setTenantContext) not constructor injection — SQLFilter has a final constructor taking EntityManagerInterface only
- [Phase 04-shared-db-driver]: TenantMissingException does NOT implement HttpExceptionInterface — propagates from Doctrine internals, not HTTP layer; differs from TenantNotFoundException
- [Phase 04-shared-db-driver]: TenantAwareFilter null guard: tenantContext === null returns '' silently — prevents crashes in console commands before SharedDriver::boot()
- [Phase 04-shared-db-driver]: FilterSpy extends SQLFilter (not standalone) to satisfy PHPUnit 11 return-type enforcement on FilterCollection::getFilter() — mock EM passed to final SQLFilter constructor
- [Phase 04-shared-db-driver]: SharedDriver::clear() is a documented no-op — TenantContext::clear() runs in BootstrapperChain; filter reads hasTenant() live at query time
- [Phase 04-shared-db-driver]: TenancyBundle validate() block placed after children end() — rejects shared_db + database.enabled at container compile time with a clear error message
- [Phase 04-shared-db-driver]: TestTenantProduct uses explicit #[ORM\Column(name: 'tenant_id')] to avoid camelCase-to-underscore naming ambiguity with SQLite
- [Phase 04-shared-db-driver]: Strict mode test injects TenantContext directly into filter via setTenantContext (no SharedDriver::boot needed) — cleaner isolation for no-tenant scenario
- [Phase 05]: DoctrineBootstrapper clears EM identity map in boot/clear; registered with priority -10 so it runs after drivers on boot, before on clear
- [Phase 05]: EntityManagerResetListener: resetManager() called with no arg (null) not 'tenant' — works in both driver modes; moved to always-on DI registration outside database.enabled block
- [Phase 05]: TenantAwareCacheAdapter.$inner is NOT readonly — withSubNamespace() clones with mutated inner; final class + private visibility enforce encapsulation
- [Phase 05]: pool() reads TenantContext live on every cache operation — never cache withSubNamespace() result to prevent stale tenant context
- [Phase 05]: PHPUnit 11 intersection mock static return type: use createMockForIntersectionOfInterfaces + willReturnSelf() for interfaces with withSubNamespace(): static
- [Phase 05-P03]: BootstrapperTestKernel uses shared_db driver (single EM) — DoctrineBootstrapper targets default EM, correct in both driver modes
- [Phase 05-P03]: BootstrapperTestKernel environment-aware SQLite path (tenancy_bootstrapper_{env}.db) prevents DB collision between Doctrine and cache kernel instances
- [Phase 05-P03]: CacheBootstrapperIntegrationTest uses env=cache_test to isolate cache dir and DB file from DoctrineBootstrapperIntegrationTest (env=test)
- [Phase 06-messenger-integration]: TenantWorkerMiddleware does NOT dispatch TenantResolved — tenant is restored not resolved; avoids HTTP listeners firing in worker context
- [Phase 06-messenger-integration]: symfony/messenger added to require-dev and suggest, NOT require — optional integration; class_exists guard in Plan 02 prevents crashes
- [Phase 06-messenger-integration]: MessengerMiddlewarePass compiler pass (not prependExtensionConfig) for bus enrollment: messenger.buses.*.middleware uses performNoDeepMerging() so prepended config is overwritten; direct parameter modification is the correct approach
- [Phase 06-messenger-integration]: interface_exists() instead of class_exists() for MessageBusInterface guard: MessageBusInterface is an interface, class_exists() returns false for interfaces — caused silent skip of all Messenger wiring
- [Phase 07-cli-commands]: processFactory optional Closure injected as third constructor param in TenantRunCommand — enables unit testing without real subprocess spawning
- [Phase 07-cli-commands]: symfony/process promoted from absent/dev to production require block (^6.4||^7.0) as tenancy:run is a core production feature

### Pending Todos

None yet.

### Blockers/Concerns

- [Phase 3]: DBAL 4 wrapperClass switchTenant() internals are underdocumented — verify implementation against community reference bundles (mapeveri, fds) before finalizing Phase 3 plans
- [Phase 5]: Cache adapter-level namespace vs. key-prefix distinction needs code-level verification against symfony/cache internals during Phase 5 planning
- [Phase 9]: Symfony Flex recipe submission process (symfony/recipes-contrib manifest.json format) needs research before Phase 9 plans are finalized

### Quick Tasks Completed

| # | Description | Date | Commit | Directory |
|---|-------------|------|--------|-----------|
| 260318-tj1 | Add local Docker Compose development environment with configurable PHP version | 2026-03-18 | db6b0f1 | [260318-tj1-add-local-docker-compose-development-env](.planning/quick/260318-tj1-add-local-docker-compose-development-env/) |

## Session Continuity

Last session: 2026-04-01T21:41:33.631Z
Stopped at: Completed 07-02-PLAN.md
Resume file: None
