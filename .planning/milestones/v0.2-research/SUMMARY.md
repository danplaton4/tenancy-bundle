# Project Research Summary

**Project:** Symfony Multi-Tenancy Bundle
**Domain:** Symfony reusable OSS bundle — multi-tenancy as a first-class kernel citizen
**Researched:** 2026-03-17
**Confidence:** HIGH

## Executive Summary

This project is a production-grade Symfony bundle that provides multi-tenancy infrastructure for PHP SaaS applications. The research establishes a clear approach: build a layered system of resolver → context → bootstrapper → driver, modelled structurally on stancl/tenancy (the Laravel gold standard) but purpose-built for Symfony's compiled DI container, kernel event system, and Doctrine ORM. Two isolation strategies are required — database-per-tenant (maximum isolation via DBAL connection switching using the `wrapperClass` pattern) and shared-database (Doctrine SQL filter with `#[TenantAware]` attribute) — because no existing Symfony bundle offers both in a single package. The recommended stack is PHP 8.2+, Symfony 6.4 LTS and 7.4 LTS (dual support), DBAL 4.4, ORM 3.3, and Flysystem 3.28, with PHPUnit 11 and PHPStan 2.1 at level 9 as the quality floor.

The whitespace this bundle fills is unambiguous. RamyHakam/multi_tenancy_bundle (the most downloaded Symfony tenancy package) lacks shared-DB support, Messenger context propagation, filesystem isolation, a profiler panel, and a PHPStan extension. zhortein/multi-tenant-bundle claims broader coverage but is unverified and targets PHP 8.3+, excluding a significant production install base. Neither bundle enforces strict mode by default — a GDPR risk in shared-DB mode. The recommended bundle ships all of these as v1 features, positioning it as the definitive Symfony tenancy solution.

The dominant risks are data safety risks, not implementation complexity risks. The three most dangerous failure modes — Doctrine identity map pollution across tenant switches, SQL filter bypass via native queries, and Messenger worker state leakage between messages — are all subtle enough that they look correct during development but cause cross-tenant data exposure in production. Each has a verified prevention pattern, and each must have an automated test that specifically reproduces the failure before the feature is considered complete. Strict mode ON by default, mandatory `finally`-block teardown in middleware, and PHPStan rules flagging native query usage are the three non-negotiable safeguards.

---

## Key Findings

### Recommended Stack

The bundle targets PHP `^8.2` as its floor, which is the natural intersection of Symfony 7.4 LTS (requires PHP 8.2), DBAL 4.4 (requires PHP 8.2), and PHPUnit 11 (requires PHP 8.2). Symfony `^6.4 || ^7.4` dual support gives the widest production install base — 6.4 LTS is supported until November 2027. Doctrine DBAL `^4.4` is required and is the most architecturally consequential dependency: DBAL 4 removed `Connection::connect()` and `Connection::close()` as standalone entry points, meaning runtime connection switching for database-per-tenant mode must use the `wrapperClass` extension mechanism exclusively.

Two packages that would be natural hard dependencies have version constraints that conflict with the PHP 8.2 floor: `doctrine/doctrine-bundle ^3.x` (requires PHP ^8.4) and `doctrine/doctrine-migrations-bundle ^4.0` (requires PHP ^8.4). Both must be treated as optional/suggested rather than required dependencies. CI must run a matrix across PHP 8.2, 8.3, 8.4 and Symfony 6.4, 7.4.

**Core technologies:**
- PHP `^8.2`: Runtime floor — required by DBAL 4.4 and Symfony 7.4 LTS
- Symfony `^6.4 || ^7.4`: Dual LTS support covering the active production install base
- `doctrine/dbal ^4.4`: DBAL abstraction — `wrapperClass` is the only supported connection-switching mechanism in DBAL 4
- `doctrine/orm ^3.3`: ORM and SQL filter system — `addFilterConstraint()` is the canonical shared-DB isolation hook
- `league/flysystem ^3.28`: Filesystem abstraction for per-tenant path isolation
- `symfony/messenger ^6.4 || ^7.4`: Async context propagation via `StampInterface` and `MiddlewareInterface`
- PHPStan `^2.1` at level 9 (max): Static analysis — bundle ships its own PHPStan extension
- PHPUnit `^11.0`: Test framework — aligns exactly with PHP 8.2 floor

### Expected Features

**Must have (table stakes) — users expect these on day one:**
- Subdomain, domain, and header resolvers — covers 95% of production identification patterns
- QueryParam and ConsoleResolver — debugging and CLI/worker contexts
- Pluggable resolver chain with priority ordering — extensibility contract; without it the bundle is a black box
- Database-per-tenant driver (DBAL `wrapperClass` connection swap) — maximum isolation; highest demand
- Shared-DB driver with Doctrine SQL filter and `#[TenantAware]` attribute — the critical gap vs. RamyHakam
- `TenantBootstrapperInterface` with DI tag auto-discovery — required for all downstream bootstrappers
- Cache bootstrapper (tenant-namespaced adapter, not just key prefix) — cache leaks are production bugs
- Doctrine bootstrapper — activates the correct driver per request
- Event-driven lifecycle events: `TenantResolved`, `TenantBootstrapped`, `TenantContextCleared`
- Strict mode ON by default — throws `TenantMissingException` rather than silently returning all rows
- `tenancy:migrate` and `tenancy:run` CLI commands — required for database-per-tenant provisioning and operations
- Symfony Flex recipe — required for OSS adoption and frictionless install

**Should have (competitive advantage — no existing Symfony bundle has these):**
- Messenger `TenantStamp` + sending/receiving middleware — closes the biggest infrastructure gap in the ecosystem
- Filesystem bootstrapper (Flysystem path prefixing) — no Symfony bundle provides this today
- Profiler / Web Debug Toolbar panel — dramatically improves debuggability; unique differentiator
- `InteractsWithTenancy` PHPUnit trait — makes the bundle testable and trustworthy
- PHPStan extension — compile-time enforcement of tenant-safe code; no Symfony bundle has this

**Defer (v1.x — add after adoption is validated):**
- Resource sharing (sync + async) — HIGH complexity; only meaningful once database-per-tenant adoption is confirmed
- Tenant-aware Mailer bootstrapper — Mailer service-graph rewiring is non-trivial; defer until 3+ GitHub requests
- Parallel `tenancy:migrate` — sequential is correct for v1; parallel is a speed optimization only

**Defer (v2+ — do not build in v1):**
- PostgreSQL Row-Level Security — niche; breaks MySQL/MariaDB portability
- Per-tenant middleware pipeline — enormous maintenance surface; conflicts with Symfony's request pipeline
- Multi-DB-engine heterogeneous tenants — requires complete driver abstraction redesign

### Architecture Approach

The architecture is a four-layer pipeline: Resolution Layer (resolvers identify the tenant from the request), Context Layer (`TenantContext` holds the active tenant and fires domain events), Bootstrapping Layer (ordered bootstrapper chain configures infrastructure for the active tenant), and Driver Layer (database-per-tenant or shared-DB isolation strategy). This separation is critical: `TenantContext` must be a pure value holder with zero dependencies to avoid circular dependency failures at container compile time. All bootstrappers depend on `TenantContext` (read from it); `TenantContext` never depends on bootstrappers. The bootstrapper chain runs on `TenantResolved` event and reverts in reverse order on `kernel.terminate`. The Messenger middleware replicates this full lifecycle for async workers using a `TenantStamp` stamp.

**Major components:**
1. `TenantContextOrchestrator` — `kernel.request` listener at priority 20 (above Security at 8, below Router at 32); drives resolution → context → event
2. `ResolverChain` + `TenantResolverInterface` — priority-ordered resolver strategies; first non-null result wins
3. `TenantContext` — request-scoped authoritative tenant holder; fires lifecycle events; pure value object, zero dependencies
4. `BootstrapperChain` + `TenantBootstrapperInterface` — ordered executor; `DoctrineBootstrapper` at priority 100, `CacheBootstrapper` at 50, `FilesystemBootstrapper` at 40, user-land at 0
5. `TenantDriverInterface` with `DatabaseDriver` (DBAL `wrapperClass` swap) and `SharedDriver` (SQL filter enable)
6. `TenantConnection` — extends `Doctrine\DBAL\Connection`; exposes `switchTenant()` for the `DatabaseDriver`
7. `TenantAwareFilter` — Doctrine SQL filter; appends `WHERE tenant_id = :tenant_id` for `#[TenantAware]` entities
8. `TenantMiddleware` — Messenger middleware; injects `TenantStamp` on send, re-boots context from stamp on receive in a `try/finally` block
9. `TenantDataCollector` — profiler data collector; reads from `TenantContext` on `kernel.response`
10. PHPStan rules + `InteractsWithTenancy` trait — static and runtime safety layers

### Critical Pitfalls

The research identified 10 pitfalls; these 5 are the most dangerous and must be addressed proactively:

1. **Doctrine identity map pollution across tenant switches** — always call `$entityManager->clear()` (and for database-per-tenant: `$managerRegistry->resetManager()`) on `TenantContextCleared` event. Failure mode: Tenant A's entities silently returned during Tenant B's request. Verify with a test that switches tenant twice in one process.

2. **SQL filter bypass via native queries** — Doctrine's SQL filter only applies to DQL/QueryBuilder, not `createNativeQuery()` or raw DBAL `executeQuery()`. This is a data breach, not a bug. Prevention: PHPStan rule flagging native queries in tenant-aware classes; `TenantAwareNativeQueryBuilder` helper; prominent README documentation. This is the most dangerous security pitfall in shared-DB mode.

3. **Messenger worker state leakage between messages** — workers are long-running processes; without a `try/finally` teardown in `TenantMiddleware`, a tenant context from message N persists into message N+1. The `finally` block calling `TenantContextClearer::clear()` is non-negotiable. Verify with a test processing two differently-stamped messages sequentially.

4. **`kernel.request` priority conflict with the Security firewall** — registering `TenantResolverListener` at default priority 0 causes it to run after the Security firewall (priority 8), which tries to load users before tenant context is set. Must register at priority 20. Define a `TenantResolverListener::PRIORITY = 20` constant so users know the correct value.

5. **Circular dependency in bootstrapper registration** — if `TenantContext` acquires any service dependency (Doctrine, cache, etc.), the DI graph cycles through bootstrappers that depend on it. `TenantContext` must remain a pure value holder with zero constructor parameters. Enforce this with a PHPStan rule and verify `bin/console debug:container` succeeds.

---

## Implications for Roadmap

Based on the dependency graph from FEATURES.md and the build order from ARCHITECTURE.md, the natural phase structure is:

### Phase 1: Core Foundation
**Rationale:** Everything else depends on these. `TenantContext` must exist before any resolver, bootstrapper, or driver can be built. This phase establishes the architectural skeleton and the event contract.
**Delivers:** `Tenant` entity, `TenantContext` (pure value holder), lifecycle events (`TenantResolved`, `TenantBootstrapped`, `TenantContextCleared`), `TenantBootstrapperInterface`, `BootstrapperChain`, `BootstrapperChainPass`, `TenantContextClearer` (`kernel.terminate` listener), and the bundle class with compiler pass infrastructure.
**Addresses:** Tenant model + landlord DB, `TenantBootstrapperInterface`, event-driven lifecycle (all P1 must-have)
**Avoids:** Circular dependency pitfall (design `TenantContext` as zero-dependency value holder from the start)
**Research flag:** Standard patterns — no deeper research needed; architecture is fully specified.

### Phase 2: Tenant Resolution
**Rationale:** Resolvers depend on the `Tenant` entity and `TenantContext` from Phase 1. The resolver chain must be complete before any driver can be tested end-to-end in a real HTTP request.
**Delivers:** `TenantResolverInterface`, `ResolverChain`, `ResolverChainPass`, `HostResolver`, `HeaderResolver`, `QueryParamResolver`, `ConsoleResolver`, `TenantContextOrchestrator` (`kernel.request` listener at priority 20), `TenantContextClearer` wiring.
**Addresses:** Subdomain/domain/header/QueryParam/ConsoleResolver (all P1), pluggable resolver chain
**Avoids:** `kernel.request` priority pitfall (register at 20, not 0; test against Security firewall)
**Research flag:** Standard patterns — Symfony kernel event priorities are well-documented.

### Phase 3: Database Isolation — Database-Per-Tenant Driver
**Rationale:** Database-per-tenant is the highest-demand isolation mode and the most architecturally complex. It must be built before the shared-DB driver because its patterns (two named entity managers, DBAL `wrapperClass`) define constraints that the bundle configuration must accommodate.
**Delivers:** `TenantDriverInterface`, `DatabaseDriver`, `TenantConnection` (DBAL `wrapperClass` subclass with `switchTenant()`), `DoctrineBootstrapper` (database-per-tenant path), landlord/tenant named entity manager configuration, `tenancy:migrate` CLI command.
**Addresses:** Database-per-tenant driver, `tenancy:migrate` CLI (both P1)
**Avoids:** DBAL connection not-reset pitfall (test `switchTenant()` against two real databases); identity map pollution (call `resetManager()` in teardown)
**Research flag:** Needs deeper research during phase planning. The `wrapperClass` internal API is stable in DBAL 4.4 but underdocumented. Verify `switchTenant()` implementation against community references (mapeveri/multi-tenancy-bundle, fds/multi-tenancy-bundle) before finalizing.

### Phase 4: Database Isolation — Shared-DB Driver
**Rationale:** Shared-DB driver can be built in parallel with Phase 3 but depends on the Doctrine bootstrapper contract established there. It is the critical differentiator vs. RamyHakam. Ships with `#[TenantAware]` attribute and strict mode.
**Delivers:** `SharedDriver`, `TenantAwareFilter` (Doctrine SQL filter), `#[TenantAware]` PHP attribute, `DoctrineBootstrapper` (shared-DB path), strict mode configuration (`TenantMissingException`).
**Addresses:** Shared-DB SQL filter driver, `#[TenantAware]`, strict mode (all P1)
**Avoids:** SQL filter bypass pitfall (document in README; defer native query helper to Phase 7); identity map pitfall (`clear()` in teardown, not `resetManager()`)
**Research flag:** Standard patterns — Doctrine SQL filter API is well-documented.

### Phase 5: Infrastructure Bootstrappers
**Rationale:** Cache and filesystem bootstrappers depend on both the bootstrapper interface (Phase 1) and a working driver (Phases 3/4). They can be built once the driver layer is stable.
**Delivers:** `CacheBootstrapper` (tenant-namespaced adapter, not key-prefix), `FilesystemBootstrapper` (Flysystem path decorator), `tenancy:cache:clear {tenant_id}` command.
**Addresses:** Cache bootstrapper, filesystem bootstrapper (both P1 differentiators)
**Avoids:** Cache cross-tenant invalidation pitfall (use `namespace` adapter option, not key prefix; test that clearing Tenant A's cache does not affect Tenant B)
**Research flag:** Cache bootstrapper needs careful research during phase planning. The distinction between key-prefix and adapter-level namespacing is subtle and the Symfony Cache namespace invalidation mechanism has a known cross-tenant bug. Verify the correct implementation approach.

### Phase 6: Messenger Integration
**Rationale:** Messenger depends on the full lifecycle (Phases 1-4) being stable. It is the ecosystem's biggest gap and a high-value differentiator, but it must not be built before teardown semantics are proven.
**Delivers:** `TenantStamp`, `TenantMiddleware` (send + receive with `try/finally` teardown), worker lifecycle documentation.
**Addresses:** Messenger `TenantStamp` + middleware + worker listener (P1)
**Avoids:** Worker state leakage pitfall (`finally` teardown is mandatory; test with two differently-stamped messages); stamp serialization verification across all configured transports
**Research flag:** Standard patterns — Messenger `StampInterface` and `MiddlewareInterface` are stable and well-documented.

### Phase 7: Developer Experience (DX) Layer
**Rationale:** DX features (profiler, PHPStan, test trait) depend on a fully working bundle beneath them. They are differentiators, not prerequisites. Build last to maximize the surface area they cover.
**Delivers:** `TenantDataCollector` + Twig toolbar template (profiler panel), `InteractsWithTenancy` PHPUnit trait (`initializeTenant()`, `clearTenant()`, `tearDown()` wiring), PHPStan extension (`TenantAwareEntityDirectQueryRule`, `TenantContextAssertedRule`, `extension.neon`), `TenantAwareNativeQueryBuilder` helper (native query safety), `tenancy:run` CLI command.
**Addresses:** Profiler integration, PHPUnit trait, PHPStan extension (all P1 differentiators); SQL filter bypass via native queries (security mitigation)
**Avoids:** PHPUnit test isolation pitfall (trait `tearDown()` must run even when `setUp()` throws; test suite in `--order=random`); profiler panel only registered when `kernel.debug = true`
**Research flag:** PHPStan custom rules need research during phase planning. The PHPStan `Rule` API and `Scope` introspection patterns for Doctrine entities are non-trivial. Plan for research before implementation.

### Phase 8: OSS Hardening
**Rationale:** The final phase addresses Packagist/Flex publishing, CI matrix configuration, and documentation — concerns that are irrelevant until the bundle itself is complete.
**Delivers:** Symfony Flex recipe (`manifest.json`, `config/packages/tenancy.yaml` stub), GitHub Actions CI matrix (PHP 8.2/8.3/8.4 x Symfony 6.4/7.4, plus `--prefer-lowest`), PHPStan job at level 9, php-cs-fixer job with `@Symfony` ruleset, `CHANGELOG.md`, `UPGRADE.md`, README with full lifecycle example (boot → handle → clear), UX polish (`--dry-run` on `tenancy:migrate`, `--tenant=ID` filter, debug resolver logging).
**Addresses:** Symfony Flex recipe (P1), full CI matrix, UX pitfalls
**Avoids:** UX pitfalls: error messages including resolver context in dev, no teardown example missing from README
**Research flag:** Flex recipe submission (symfony/recipes-contrib) needs research during phase planning. The contrib review process and manifest format have specific requirements.

### Phase Ordering Rationale

- **Phases 1-2 are architectural bedrock:** Every other phase depends on `TenantContext` and working resolvers. Building anything else first creates throwaway code.
- **Phase 3 before Phase 4:** Database-per-tenant defines the two-EntityManager constraint that shared-DB must accommodate. Reversing this order forces a configuration redesign.
- **Phase 5 after Phases 3-4:** Cache and filesystem bootstrappers need a stable driver beneath them to test isolation correctly.
- **Phase 6 after all isolation phases:** Messenger worker lifecycle replicates the HTTP lifecycle exactly. Testing it requires the full bootstrapper chain to be correct.
- **Phase 7 last among feature phases:** DX layer covers the entire bundle surface; building it early means rebuilding it as the bundle evolves.
- **Phase 8 as a standalone:** OSS packaging concerns are orthogonal to implementation. Separating them prevents premature publication of an incomplete bundle.

### Research Flags

Phases requiring deeper research before planning their tasks:
- **Phase 3 (Database-Per-Tenant Driver):** DBAL 4 `wrapperClass` internal API is underdocumented. Must verify `switchTenant()` implementation pattern against community reference bundles and confirm EntityManager reset semantics in DBAL 4.4 specifically.
- **Phase 5 (Cache Bootstrapper):** Symfony Cache namespace vs. key-prefix distinction is subtle. The correct implementation (adapter-level namespace, not key decoration) needs verification against `symfony/cache` source code and the known cross-tenant invalidation bug.
- **Phase 7 (PHPStan Extension):** PHPStan `Rule` interface, `Scope` API, and extension registration via `phpstan/extension-installer` neon format require hands-on research before the task list can be accurately sized.
- **Phase 8 (Flex Recipe):** symfony/recipes-contrib submission process and `manifest.json` format constraints need verification against the current contrib repository guidelines.

Phases with well-documented standard patterns (skip research-phase):
- **Phase 1 (Core Foundation):** Symfony AbstractBundle, compiler passes, and event dispatcher patterns are thoroughly documented.
- **Phase 2 (Tenant Resolution):** Kernel event priorities and tagged service chains are well-established Symfony patterns.
- **Phase 4 (Shared-DB Driver):** Doctrine SQL filter API is stable and official docs are comprehensive.
- **Phase 6 (Messenger):** `StampInterface` and `MiddlewareInterface` are stable; `try/finally` teardown pattern is straightforward.

---

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH | All versions verified against Packagist; PHP/Symfony/DBAL/ORM constraints cross-validated. The DoctrineBundle 3.x / Migrations 4.x PHP floor conflict is a confirmed constraint requiring careful composer.json design. |
| Features | HIGH | Competitive analysis based on official docs and direct GitHub source review for RamyHakam; stancl/tenancy based on official tenancy docs. zhortein claims unverified (MEDIUM for that competitor only). Feature gaps confirmed via SymfonyCon 2024/2025 official Symfony blog. |
| Architecture | HIGH | All patterns grounded in official Symfony and Doctrine documentation. DBAL `wrapperClass` pattern confirmed against two community implementations. Kernel event priorities verified against official Symfony event reference. |
| Pitfalls | HIGH | Doctrine identity map and SQL filter bypass confirmed via official Doctrine docs and GitHub issues. Messenger worker pitfall confirmed via official Symfony Messenger docs. Cache namespace pitfall confirmed via Symfony Cache docs and known GitHub issue. |

**Overall confidence:** HIGH

### Gaps to Address

- **DoctrineBundle soft dependency boundary:** The exact composer.json structure for expressing DoctrineBundle and MigrationsBundle as optional (suggested, not required) while still running them in CI needs to be nailed down during Phase 1 planning. Options: `require-dev` with `suggest`, or a separate dev-meta package.
- **DBAL 4 `switchTenant()` internals:** The `wrapperClass` mechanism is confirmed as the correct approach, but the exact internal API for modifying connection parameters in DBAL 4.4 (vs. 3.x's `resetParams()`) needs code-level verification during Phase 3. Community implementations exist but target older DBAL versions.
- **Cache namespace adapter decoration pattern:** The correct Symfony service decoration approach for wrapping `cache.app` with a per-request tenant namespace (not a static one) requires verification against the `symfony/cache` component internals during Phase 5.
- **zhortein/multi-tenant-bundle feature claims:** Several features are claimed (Messenger, Mailer, comprehensive test kit) but not source-verified. If this bundle matures before v1 release, re-evaluate competitive positioning.

---

## Sources

### Primary (HIGH confidence)
- [Symfony Releases](https://symfony.com/releases) — PHP floors for Symfony 6.4, 7.4, 8.0 confirmed
- [Doctrine DBAL 4.4 Configuration Docs](https://www.doctrine-project.org/projects/doctrine-dbal/en/4.4/reference/configuration.html) — `wrapperClass` mechanism
- [Doctrine ORM 3.6 Docs — Working with Objects](https://www.doctrine-project.org/projects/doctrine-orm/en/3.6/reference/working-with-objects.html) — identity map semantics
- [Symfony Built-in Events Reference](https://symfony.com/doc/current/reference/events.html) — kernel event priorities
- [Symfony Messenger Docs](https://symfony.com/doc/current/messenger.html) — stamps, middleware, long-running workers
- [Symfony Cache Docs — Namespaces](https://symfony.com/doc/current/components/cache/cache_invalidation.html) — namespace invalidation semantics
- [Symfony Best Practices for Reusable Bundles](https://symfony.com/doc/current/bundles/best_practices.html) — service naming, compiler passes
- [Symfony Multiple Entity Managers](https://symfony.com/doc/current/doctrine/multiple_entity_managers.html) — landlord/tenant EM pattern
- [Tenancy for Laravel v3/v4 Docs](https://tenancyforlaravel.com/docs/v3/) — reference architecture and feature set
- [RamyHakam/multi_tenancy_bundle GitHub](https://github.com/RamyHakam/multi_tenancy_bundle) — competitive feature analysis
- [SymfonyCon Amsterdam 2025: Multi-Tenantize Symfony Components](https://symfony.com/blog/symfonycon-amsterdam-2025-multi-tenantize-the-symfony-components) — ecosystem gap confirmation
- [SymfonyCon Brussels 2023: Multi-tenant applications using Symfony, for real?](https://symfony.com/blog/symfonycon-brussels-2023-multi-tenant-applications-using-symfony-for-real) — ecosystem gap confirmation
- [Packagist: doctrine/dbal](https://packagist.org/packages/doctrine/dbal), [doctrine/orm](https://packagist.org/packages/doctrine/orm), [doctrine/doctrine-bundle](https://packagist.org/packages/doctrine/doctrine-bundle), [league/flysystem-bundle](https://packagist.org/packages/league/flysystem-bundle), [phpstan/phpstan](https://packagist.org/packages/phpstan/phpstan), [phpunit/phpunit](https://phpunit.de/supported-versions.html) — version constraints verified

### Secondary (MEDIUM confidence)
- [mapeveri/multi-tenancy-bundle](https://github.com/mapeveri/multi-tenancy-bundle), [fds/multi-tenancy-bundle](https://packagist.org/packages/fds/multi-tenancy-bundle) — `wrapperClass` pattern reference implementations
- [SymfonyTest/symfony-bundle-test](https://github.com/SymfonyTest/symfony-bundle-test) — `TestKernel` for multi-Symfony CI
- [DAMADoctrineTestBundle](https://github.com/dmaicher/doctrine-test-bundle) — PHPUnit transaction isolation limitations in multi-DB setups
- [rentpost/doctrine-multi-tenancy](https://github.com/rentpost/doctrine-multi-tenancy) — SQL filter context pitfalls
- [zhortein/multi-tenant-bundle Packagist](https://packagist.org/packages/zhortein/multi-tenant-bundle) — competitive landscape (claims unverified against source)
- Doctrine ORM GitHub issues [#5606](https://github.com/doctrine/orm/issues/5606), [#1626](https://github.com/doctrine/orm/issues/1626) — identity map and EM closed behavior
- Symfony GitHub issue [#35360](https://github.com/symfony/symfony/issues/35360) — EM closed after DB error in workers
- Symfony GitHub issue [#59509](https://github.com/symfony/symfony/issues/59509) — cache `prefix_seed` per pool

---

*Research completed: 2026-03-17*
*Ready for roadmap: yes*
