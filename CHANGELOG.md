# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-04-12

First stable release. Multi-tenancy for Symfony with zero boilerplate and zero leaks.

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

[Unreleased]: https://github.com/danplaton4/tenancy-bundle/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/danplaton4/tenancy-bundle/releases/tag/v1.0.0