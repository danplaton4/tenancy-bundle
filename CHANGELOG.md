# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] — 2026-04-20

Retrospective: v1.0.0 was tagged on 2026-04-12 but retracted the same day after four
defects surfaced in downstream demo projects. The line was restarted at v0.1.0. Phase 15
applied the four fixes as architectural corrections (not surface patches); v0.2.0 is
where the architecture finally settles.

### Changed

- **ResolverChain::resolve()** now returns `?TenantResolution` (nullable) instead of
  throwing `TenantNotFoundException` when no resolver matches. Public/landlord routes
  proceed with an empty `TenantContext`. `TenantNotFoundException` is narrowed to
  "provider-rejected identifier" (`DoctrineTenantProvider::findBySlug` is the only
  remaining thrower). Closes #6.
- **Database-per-tenant connection switching** migrated from DBAL `wrapperClass` +
  reflection to `Doctrine\DBAL\Driver\Middleware`. `TenantDriverMiddleware` +
  `TenantAwareDriver` intercept `connect()` per-tenant; `DatabaseSwitchBootstrapper::boot()`
  reduces to `$connection->close()`. Closes #7, #8.
- **`tenancy:init`** command now emits a sample `doctrine.yaml` (MySQL driver family)
  alongside the tenancy.yaml stub, with a driver-family-match callout.
- **Documentation:** `docs/architecture/dbal-wrapper.md` renamed/rewritten as
  `dbal-middleware.md`; user-guide `database-per-tenant`, `configuration`, `installation`,
  `getting-started`, `testing`, and examples updated to the middleware model. The
  `sqlite://` placeholder pattern for non-SQLite tenants is gone.

### Fixed

- **`TenantAwareCacheAdapter`** now implements the full `cache.app` substitution surface
  (`AdapterInterface`, `CacheInterface`, `NamespacedPoolInterface`, `PruneableInterface`,
  `ResettableInterface`). A sibling `TenantAwareTagAwareCacheAdapter` covers
  `cache.app.taggable`. Fresh Symfony 7.4 projects that run
  `composer require doctrine/orm doctrine/doctrine-bundle danplaton4/tenancy-bundle` can
  now `bin/console cache:clear` without TypeError. Closes #5.
- New compiler pass `CacheDecoratorContractPass` fails container compilation with a clear
  `LogicException` if the decorator is missing any `Symfony\*` interface the decorated
  service exposes. Prevents this class of regression from re-landing.

### Removed

- `Tenancy\Bundle\DBAL\TenantConnection` — deleted. (v0.1 had 2 Packagist downloads, both
  self; no external users.)
- `Tenancy\Bundle\DBAL\TenantConnectionInterface` — deleted.
- `tests/Unit/DBAL/TenantConnectionTest.php` — deleted.
- `tests/Integration/DatabaseSwitchIntegrationTest.php` — deleted (superseded by
  `tests/Integration/DBAL/DatabasePerTenantMiddlewareIntegrationTest.php`).
- `wrapper_class: TenantConnection::class` from test kernels' YAML.

### Migration

See `UPGRADE.md` § 0.1 → 0.2 for details. In short:

- No user action required for Fix #5.
- Fix #6 is a behavior change if you caught `TenantNotFoundException` in a
  `kernel.exception` listener for the "no resolver matched" case.
- Fix #7 + #8 require removing `wrapper_class:` from `doctrine.yaml` (if you had it) and
  ensuring your tenant connection's `driver:` matches your tenant database's driver
  family.

### Tooling

- `scripts/docs-lint.sh` — new CI-grade script that fails non-zero when post-v0.2 docs
  contain stale references (`wrapperClass`, `ReflectionProperty`, `sqlite://`,
  `TenantConnection`). Scoped to `docs/` + `src/Command/TenantInitCommand.php`.

## [0.1.0] - 2026-04-19

Initial public release. Multi-tenancy for Symfony with zero boilerplate and zero leaks.

> **Note on versioning**: Previously tagged as `v1.0.0` (2026-04-12) but never publicly released — the v1.0.0 tag was removed because four architectural issues (cache decorator contract, resolver optionality, DBAL 4 connection switching) surfaced in downstream demo projects before the tag was advertised. The codebase has been restarted from `0.x` until those issues are resolved.

### Added

- **Core Foundation**
  - `TenantContext` zero-dependency value holder for active tenant state
  - `TenantInterface` and `TenantBootstrapperInterface` contracts
  - `BootstrapperChain` with compiler pass autoconfiguration (`tenancy.bootstrapper` tag)
  - Lifecycle events: `TenantResolved`, `TenantBootstrapped`, `TenantContextCleared`
  - `TenantContextOrchestrator` kernel.request listener at priority 20
  - `Tenant` Doctrine entity with slug primary key

- **Tenant Resolution**
  - `HostResolver` — subdomain and custom domain resolution (priority 30)
  - `HeaderResolver` — `X-Tenant-ID` header resolution (priority 20)
  - `QueryParamResolver` — `?_tenant=` query parameter (priority 10)
  - `ConsoleResolver` — `--tenant=` CLI flag on ConsoleCommandEvent
  - `ResolverChain` with pluggable priority-based ordering via compiler pass
  - `DoctrineTenantProvider` with cache-then-check lookup pattern

- **Database-Per-Tenant Isolation**
  - `TenantConnection` DBAL 4 wrapperClass with runtime connection switching via reflection
  - `DatabaseSwitchBootstrapper` for tenant boot/clear delegation
  - `EntityManagerResetListener` to prevent identity map pollution across tenants
  - Dual Entity Manager configuration: `landlord` (central) + `tenant` (swappable)
  - Conditional DI wiring via `tenancy.database.enabled` config flag

- **Shared-Database Isolation**
  - `#[TenantAware]` PHP attribute for marking Doctrine entities
  - `TenantAwareFilter` Doctrine SQL filter with 4-branch logic (scoped/empty/strict/permissive)
  - `SharedDriver` bootstrapper to inject tenant context into the filter
  - Strict mode on by default — `TenantMissingException` when querying without active tenant
  - Validation blocking `shared_db` + `database.enabled` config conflict

- **Infrastructure Bootstrappers**
  - `DoctrineBootstrapper` — clears EM identity map on boot/clear (priority -10)
  - `TenantAwareCacheAdapter` — decorates `cache.app` with per-tenant namespace isolation via `withSubNamespace()`

- **Messenger Integration**
  - `TenantStamp` carrying tenant slug across process boundaries
  - `TenantSendingMiddleware` — attaches stamp on dispatch
  - `TenantWorkerMiddleware` — restores context on consume with try/finally teardown
  - `MessengerMiddlewarePass` compiler pass auto-enrolling both middlewares in all buses

- **CLI Commands**
  - `tenancy:migrate` — sequential per-tenant Doctrine migrations with `--tenant=` filter
  - `tenancy:run` — wraps any console command with tenant context via subprocess

- **Developer Experience**
  - `InteractsWithTenancy` PHPUnit trait with `initializeTenant()`, automatic tearDown cleanup
  - Assertion helpers: `assertTenantActive()`, `assertNoTenant()`, `getTenantService()`

- **OSS Tooling**
  - Symfony Flex recipe with auto-registration and `config/packages/tenancy.yaml` stub
  - GitHub Actions CI: PHP 8.2/8.3/8.4 x Symfony 7.4/8.0 matrix
  - PHPStan level 9 enforcement, php-cs-fixer with `@Symfony` ruleset
  - CI jobs for no-Doctrine, no-Messenger, and prefer-lowest dependency validation
  - Codecov coverage reporting

[Unreleased]: https://github.com/danplaton4/tenancy-bundle/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/danplaton4/tenancy-bundle/releases/tag/v0.2.0
[0.1.0]: https://github.com/danplaton4/tenancy-bundle/releases/tag/v0.1.0
