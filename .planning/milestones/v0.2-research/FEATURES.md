# Feature Research

**Domain:** Symfony multi-tenancy bundle (OSS, Packagist)
**Researched:** 2026-03-17
**Confidence:** HIGH (stancl/tenancy documented via official sources; RamyHakam via GitHub; gaps confirmed by SymfonyCon 2024/2025 talks)

---

## Competitive Landscape: What Exists

Before defining table stakes, here is a clear picture of what the existing competition does and does not do.

### stancl/tenancy (Laravel) — the gold standard

The most feature-complete multi-tenancy package in the PHP ecosystem. Used as the benchmark.

| Capability | v3 | v4 |
|------------|----|----|
| Database-per-tenant | YES | YES |
| Single-DB with SQL scoping | YES | YES |
| PostgreSQL RLS | NO | YES |
| Subdomain / domain resolver | YES | YES |
| Path / header / cookie resolver | YES | YES |
| Origin header resolver | NO | YES |
| Cache bootstrapper (tag-based) | YES | v4 changes to prefix-based |
| Filesystem bootstrapper | YES | YES |
| Queue (job payload stamp) bootstrapper | YES | YES |
| Redis bootstrapper | YES | YES |
| Mail config bootstrapper | NO | YES (v4) |
| Scout (search) bootstrapper | NO | YES (v4) |
| Database session bootstrapper | NO | YES (v4) |
| Custom bootstrapper interface | YES | YES |
| `tenants:migrate` CLI | YES | YES |
| `tenants:run` CLI | YES | YES (improved) |
| `tenant:tinker` CLI | NO | YES (v4) |
| `tenants:down` / `tenants:up` | NO | YES (v4) |
| Resource syncing (sync/async) | YES | YES (polymorphic in v4) |
| Pending tenants pool | NO | YES (v4) |
| Route cloning | NO | YES (v4) |
| Testing utilities | Partial | Partial |
| Profiler integration | NO | NO |
| PHPStan extension | NO | NO |

### RamyHakam/multi_tenancy_bundle (Symfony) — database-per-tenant only

The most downloaded Symfony-specific tenancy bundle. Supports PHP 8.1+, Symfony 6.4/7.x. Latest: v3.0.0-beta2 (July 2025).

| Capability | Status |
|------------|--------|
| Database-per-tenant | YES |
| Shared-DB / SQL filter | NO |
| Subdomain / path / header / host resolvers | YES (5 strategies + chain) |
| Custom resolver interface | YES |
| `TenantEntityManager` service | YES |
| Separate migration paths (main vs tenant) | YES |
| Fixture support (`#[TenantFixture]`) | YES |
| Shared entities (`#[TenantShared]`) | YES |
| Cache isolation (decorator) | YES (TenantAwareCacheDecorator) |
| Filesystem isolation | NO |
| Symfony Messenger support | NO |
| Queue / async context propagation | NO |
| CLI commands (migrate, run) | Partial |
| Testing utilities (`TenantTestTrait`) | YES (basic) |
| Profiler / Web Debug Toolbar | NO |
| PHPStan extension | NO |
| Event-driven lifecycle events | NO (uses SwitchDbEvent directly) |
| Strict mode / data-leak guard | NO |

### zhortein/multi-tenant-bundle (Symfony) — broad but less mature

PHP 8.3+, Symfony 7+. PostgreSQL 16 focus.

| Capability | Status |
|------------|--------|
| Shared DB + separate DB | YES |
| Multiple resolver strategies | YES |
| PostgreSQL RLS | YES |
| Messenger context propagation | YES (claims) |
| Mailer bootstrapper | YES (claims) |
| File storage isolation | YES (claims) |
| PHPStan Level Max | YES |
| Profiler integration | NO |
| Testing utilities | YES (claims "comprehensive test kit") |
| Tenant attribute (`#[TenantAware]`) | Unknown |
| Strict mode | Unknown |

### Gap summary

No existing Symfony bundle combines all of: shared-DB + database-per-tenant, Messenger stamp propagation, event-driven bootstrapper chain, profiler integration, PHPStan extension, strict mode, and a first-class testing trait. This is the whitespace this bundle fills.

---

## Feature Landscape

### Table Stakes (Users Expect These)

Features that users assume exist. Missing these = product feels incomplete or unusable for production.

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Subdomain resolver (`tenant.app.com`) | Primary tenant identification pattern for SaaS; every competitor has it | LOW | Uses `Request::getHost()`, strips first segment |
| Domain resolver (custom full domain) | Required for white-label products; RamyHakam, zhortein both provide it | LOW | DNS CNAME points to app, resolver maps domain to tenant |
| Header resolver (`X-Tenant-ID`) | Mobile/SPA/API-first is standard; developers expect it as an alternative | LOW | Reads header, looks up tenant in landlord DB |
| Database-per-tenant driver | Maximum isolation; every SaaS Symfony bundle provides this | HIGH | Swap DBAL connection params at runtime; requires ManagerRegistry decoration |
| Shared-DB driver with Doctrine SQL Filter | Lower infra cost for early-stage SaaS; RamyHakam lacks this, which is its main complaint | HIGH | Doctrine SQL filter + `#[TenantAware]` attribute; filter must be enabled/disabled per request |
| `#[TenantAware]` entity attribute | Collocated marking; matches modern Symfony/Doctrine attribute-first style | LOW | PHP 8.1+ attribute; read by SQL filter and PHPStan extension |
| Tenant model in landlord DB | Every package needs a canonical tenant record store | LOW | Doctrine entity: id, slug, domain, db connection config |
| `TenantBootstrapperInterface` | Required for the system to be extensible; any serious bundle has this contract | LOW | Simple interface: `bootstrap(Tenant)` + `revert()` |
| Cache bootstrapper | Developers assume cache is tenant-isolated by default; RamyHakam provides this | MEDIUM | Decorate `cache.app` pool; prefix all keys with `{tenant_id}:` |
| Doctrine bootstrapper | Core of shared-DB mode: enable filter + inject `tenant_id` | LOW | Enables Doctrine SQL filter, sets `tenant_id` parameter |
| Event-driven lifecycle (`TenantResolved`, `TenantBootstrapped`, `TenantContextCleared`) | Required for user-land extensibility; stancl/tenancy proves the model | LOW | Symfony EventDispatcher events dispatched at each lifecycle phase |
| `tenancy:migrate` CLI command | Without it, developers cannot manage multi-tenant DB provisioning | MEDIUM | Run Doctrine migrations for all tenants; sequential in v1 |
| `tenancy:run` CLI | Without it, any cron/CLI command must manually set tenant context | MEDIUM | Wrap any console command: `bin/console tenancy:run {id} "app:cmd"` |
| ConsoleResolver (`--tenant=ID` flag) | CLI commands are common; without this, workers and cron jobs can't identify tenant | LOW | Reads `--tenant` input option from Console Input |
| QueryParam resolver (`?_tenant=...`) | Useful for debugging and preview flows; expected as a utility resolver | LOW | Reads query param; should be disabled in production by default |

### Differentiators (Competitive Advantage)

Features that set this bundle apart. Not assumed, but strongly valued. These are the reasons a Symfony developer would choose this over RamyHakam.

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| Symfony Messenger `TenantStamp` + middleware | No Symfony bundle currently propagates tenant context through async queues. Context leaks on workers are a production incident. This closes the #1 infrastructure gap. | HIGH | Custom Messenger stamp; sending middleware injects it; worker listener re-boots tenant context before handler runs |
| Filesystem bootstrapper (Flysystem) | No existing Symfony bundle provides Flysystem path prefixing. Developers currently hand-roll this. | MEDIUM | Decorate Flysystem adapter; prefix paths `uploads/` -> `uploads/{tenant_id}/`; requires `league/flysystem` |
| Profiler integration (Web Debug Toolbar tab) | No Symfony tenancy bundle has this. Dramatically improves debuggability — developers can see active tenant, DB connection, bootstrappers run, at a glance. | MEDIUM | Custom `DataCollector`; Twig template for toolbar; shows: tenant ID, slug, DB mode, active bootstrappers |
| `InteractsWithTenancy` PHPUnit trait | No existing Symfony bundle has a clean test trait for `WebTestCase`. Current state: developers manually wire up tenant context in each test. | MEDIUM | `$this->initializeTenant($id)` boots clean tenant DB/schema per test method; integrates with dama/doctrine-test-bundle transaction rollback pattern |
| PHPStan extension | No existing Symfony bundle has static analysis rules. Catches: querying `#[TenantAware]` entity without active tenant, missing `#[TenantAware]` on related entities. | HIGH | Custom PHPStan rules; ships as separate `phpstan-extension.neon`; enforces tenant-safe code at compile time |
| Strict mode (default ON) | No existing Symfony bundle enforces tenant context presence. Data leaks across tenants are a GDPR incident, not a misconfiguration. Making strict mode the default is a safety-first philosophy statement. | LOW | Config flag `strict_mode: true` (default); throws `TenantMissingException` when `#[TenantAware]` entity is queried with no active tenant instead of silently returning all rows |
| Resource sharing (sync + async, per-resource) | Only stancl/tenancy (Laravel) provides this. For database-per-tenant: global entities (e.g. `Plan`, `Feature`, `Currency`) must be replicated to all tenant DBs. | HIGH | Sync mode via Doctrine events on landlord persist/update; async mode via Symfony Messenger fan-out; configurable per resource |
| Pluggable resolver chain with priority ordering | RamyHakam has a chain strategy but resolution priority is not documented as configurable. This makes resolver ordering a first-class config concern. | LOW | DI-tagged resolvers with `priority` attribute; tried in order; first non-null result wins |
| Symfony Flex recipe | Makes installation a one-command experience; critical for OSS adoption on Packagist | LOW | `composer.json` with `extra.symfony.id`; recipe creates `config/packages/tenancy.yaml` with commented defaults |

### Anti-Features (Things to Explicitly NOT Build in V1)

Features that are commonly requested but create disproportionate complexity, maintenance burden, or scope creep for v1.

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| Per-tenant middleware pipeline | Power users want per-tenant firewall, rate limiting, feature flags via middleware | Requires kernel-level request pipeline override; conflicts with Symfony's request handling; maintenance surface is enormous | Use `TenantResolved` event + user-land listeners to apply per-tenant configuration after resolution |
| Parallel tenant migrations | `tenancy:migrate` on 500 tenants is slow | Requires `symfony/process` orchestration, failure handling, retry logic, partial success state; a v1 correctness bug here destroys data | Sequential v1; document `--tenant=ID` to target a single tenant; parallel is v1.1 |
| PostgreSQL Row-Level Security (RLS) | Defense-in-depth beyond SQL filter | Requires pg-specific DDL on every tenant table, migration runner awareness, and breaks portability to MySQL/MariaDB; the shared-DB SQL filter covers the use case | Shared-DB SQL filter with strict mode covers the threat model for v1 |
| Tenant-aware Mailer bootstrapper | Per-tenant SMTP relay is a common SaaS requirement | Symfony Mailer transport configuration involves non-trivial service graph rewiring at runtime; a partial implementation breaks more than it helps | Document how to use `TenantBootstrapperInterface` to swap mailer transport manually; ship as v1.1 |
| DNS TXT resolver | Some platforms use DNS TXT records as the source of truth for tenant resolution | Niche; requires DNS query with unpredictable latency in the request path; caching strategy adds complexity | Custom resolver interface covers this; document a recipe |
| Multiple DB engine support (MongoDB, Redis as primary) | Some teams store tenant data in MongoDB or Redis | Completely different persistence model; Doctrine ORM assumptions break; would require separate driver architecture | v1 is explicitly MySQL/MariaDB + PostgreSQL via DBAL; v2+ can introduce a driver abstraction |
| Health check / MonitorBundle integration | Ops teams want per-tenant health checks | Operational concern, not application concern; depends on deployment topology | Out of scope; document integration points with SensioLabs MonitorBundle or Blackfire |
| Tenant impersonation | Super-admin acts as a specific tenant | Security surface is significant; requires careful audit trail; depends on application's own auth model | Document a pattern using `TenantContextInterface` + security voters; don't bake it into the bundle |
| Multi-DB-engine tenants (some tenants MySQL, some PG) | Heterogeneous fleets | Driver selection per-tenant at runtime; connection pool management; documentation complexity | Not a realistic v1 use case; all tenants use the same DB engine as the landlord |

---

## Feature Dependencies

```
[Tenant Model (landlord DB)]
    └──required by──> [Database-per-tenant driver]
    └──required by──> [Shared-DB SQL filter driver]
    └──required by──> [All resolvers] (resolvers look up Tenant entity)
    └──required by──> [Resource sharing]

[TenantBootstrapperInterface]
    └──required by──> [Cache bootstrapper]
    └──required by──> [Filesystem bootstrapper]
    └──required by──> [Doctrine bootstrapper]
    └──required by──> [Any user-land custom bootstrapper]

[Event-driven lifecycle (TenantResolved, TenantBootstrapped)]
    └──required by──> [TenantBootstrapperInterface invocation chain]
    └──required by──> [Messenger worker listener] (re-boots from TenantStamp)
    └──required by──> [Profiler data collection]

[Shared-DB SQL filter driver]
    └──required by──> [#[TenantAware] attribute] (filter reads attribute)
    └──required by──> [Strict mode] (strict mode throws when filter has no tenant_id)
    └──required by──> [PHPStan extension] (rules check attribute presence)

[#[TenantAware] attribute]
    └──enhanced by──> [PHPStan extension] (compile-time enforcement)
    └──enhanced by──> [Strict mode] (runtime enforcement)

[Messenger TenantStamp]
    └──required by──> [Sending middleware] (injects stamp)
    └──required by──> [Worker listener] (reads stamp, re-boots context)
    └──depends on──> [Event-driven lifecycle] (worker listener fires TenantResolved)

[Database-per-tenant driver]
    └──enhanced by──> [Resource sharing] (syncs global entities to all tenant DBs)
    └──enhanced by──> [tenancy:migrate CLI] (provisions tenant DB schema)

[InteractsWithTenancy PHPUnit trait]
    └──depends on──> [TenantBootstrapperInterface chain] (calls bootstrappers in test setup)
    └──depends on──> [Event-driven lifecycle] (fires events during test setup)

[Profiler integration]
    └──depends on──> [Event-driven lifecycle] (collects data during request)
    └──depends on──> [Any active driver] (reports which mode is active)
```

### Dependency Notes

- **Shared-DB driver requires `#[TenantAware]`:** The SQL filter checks for this attribute at query time to decide whether to inject the `tenant_id` constraint. Without the attribute, the filter has no way to know which entities to scope.
- **Strict mode depends on Shared-DB driver:** Strict mode is meaningless for database-per-tenant (the wrong DB returns no data naturally); it is a safety net specifically for shared-DB mode where a missing `tenant_id` parameter causes a full-table scan across all tenants.
- **Messenger stamp depends on lifecycle events:** The worker listener re-boots tenant context by firing the same `TenantResolved` event chain that HTTP requests use, ensuring bootstrappers run identically in sync and async contexts.
- **PHPStan extension enhances `#[TenantAware]` but does not require strict mode:** They are independent enforcement layers — PHPStan is compile-time, strict mode is runtime. Both should be on.
- **Resource sharing conflicts with Shared-DB driver:** Resource sharing is only meaningful in database-per-tenant mode. In shared-DB mode, all tenants already read the same global entity rows. The feature dependency graph branches at driver selection.

---

## MVP Definition

### Launch With (v1)

Minimum viable product for OSS adoption. Covers the full request lifecycle for both database modes.

- [ ] **Tenant Model + landlord DB** — prerequisite for everything else
- [ ] **Subdomain resolver + domain resolver + header resolver** — covers 95% of production use cases
- [ ] **QueryParam resolver + ConsoleResolver** — covers debugging and CLI/worker contexts
- [ ] **Pluggable resolver chain** — OSS extensibility contract; without it the bundle is a black box
- [ ] **Database-per-tenant driver** — highest demand isolation mode; RamyHakam's core feature
- [ ] **Shared-DB driver + `#[TenantAware]` + Doctrine SQL filter** — critical gap vs RamyHakam; many small/mid SaaS teams can't afford per-tenant DBs
- [ ] **`TenantBootstrapperInterface` + bootstrapper DI tag** — required for the next items and user extensibility
- [ ] **Cache bootstrapper** — cache leaks across tenants are a production bug; expected by default
- [ ] **Doctrine bootstrapper** — required for shared-DB mode to work
- [ ] **Filesystem bootstrapper** — differentiator; no Symfony bundle has this; MEDIUM complexity
- [ ] **Event-driven lifecycle events** — required for extensibility; unlocks Profiler, Messenger, user hooks
- [ ] **Strict mode (default ON)** — security default; low complexity; high credibility signal for OSS
- [ ] **Messenger `TenantStamp` + middleware + worker listener** — biggest gap in Symfony ecosystem; HIGH value
- [ ] **`tenancy:migrate` CLI** — required for database-per-tenant provisioning
- [ ] **`tenancy:run` CLI** — required for CLI/cron/worker workflows
- [ ] **Profiler integration (Web Debug Toolbar)** — differentiator; MEDIUM complexity; huge DX win
- [ ] **`InteractsWithTenancy` PHPUnit trait** — differentiator; makes the bundle testable and trustworthy
- [ ] **PHPStan extension** — differentiator; no Symfony bundle has this; positions bundle as production-grade
- [ ] **Symfony Flex recipe** — required for OSS adoption; frictionless install

### Add After Validation (v1.x)

- [ ] **Resource sharing (sync + async)** — HIGH complexity; only needed once database-per-tenant adoption is proven; trigger: first GitHub issue requesting it
- [ ] **Tenant-aware Mailer bootstrapper** — common request; deferred due to Mailer service-graph complexity; trigger: 3+ GitHub issues
- [ ] **Parallel `tenancy:migrate`** — sequential is correct for v1; parallel is a speed optimization; trigger: user reports of slow migration on 50+ tenants
- [ ] **`tenant:tinker` equivalent** — stancl/tenancy v4 added this; useful for production debugging; trigger: community request

### Future Consideration (v2+)

- [ ] **PostgreSQL RLS** — niche; adds portability constraints; defer until MySQL is well-covered
- [ ] **Per-tenant middleware pipeline** — powerful but high maintenance surface
- [ ] **Multi-DB-engine heterogeneous tenants** — requires driver abstraction redesign
- [ ] **Health check / MonitorBundle integration** — operational, not application concern

---

## Feature Prioritization Matrix

| Feature | User Value | Implementation Cost | Priority |
|---------|------------|---------------------|----------|
| Tenant Model + landlord DB | HIGH | LOW | P1 |
| Subdomain / domain / header resolvers | HIGH | LOW | P1 |
| Pluggable resolver chain | HIGH | LOW | P1 |
| Database-per-tenant driver | HIGH | HIGH | P1 |
| Shared-DB driver + `#[TenantAware]` + SQL filter | HIGH | HIGH | P1 |
| `TenantBootstrapperInterface` | HIGH | LOW | P1 |
| Cache bootstrapper | HIGH | MEDIUM | P1 |
| Doctrine bootstrapper | HIGH | LOW | P1 |
| Event-driven lifecycle events | HIGH | LOW | P1 |
| Strict mode | HIGH | LOW | P1 |
| Messenger TenantStamp + middleware + worker | HIGH | HIGH | P1 |
| `tenancy:migrate` CLI | HIGH | MEDIUM | P1 |
| `tenancy:run` CLI | MEDIUM | MEDIUM | P1 |
| QueryParam resolver | MEDIUM | LOW | P1 |
| ConsoleResolver | MEDIUM | LOW | P1 |
| Filesystem bootstrapper (Flysystem) | HIGH | MEDIUM | P1 |
| Profiler / Web Debug Toolbar | HIGH | MEDIUM | P1 |
| `InteractsWithTenancy` PHPUnit trait | HIGH | MEDIUM | P1 |
| PHPStan extension | HIGH | HIGH | P1 |
| Symfony Flex recipe | HIGH | LOW | P1 |
| Resource sharing (sync + async) | MEDIUM | HIGH | P2 |
| Tenant-aware Mailer bootstrapper | MEDIUM | HIGH | P2 |
| Parallel `tenancy:migrate` | LOW | HIGH | P2 |
| `tenant:tinker` CLI equivalent | LOW | MEDIUM | P3 |
| PostgreSQL RLS | LOW | HIGH | P3 |
| Per-tenant middleware pipeline | LOW | HIGH | P3 |

**Priority key:**
- P1: Must have for launch
- P2: Should have, add when possible
- P3: Nice to have, future consideration

---

## Competitor Feature Analysis

| Feature | stancl/tenancy (Laravel) | RamyHakam (Symfony) | zhortein (Symfony) | This Bundle |
|---------|--------------------------|---------------------|--------------------|-------------|
| Shared-DB SQL filter | YES | NO | YES | YES |
| Database-per-tenant | YES | YES | YES | YES |
| Subdomain resolver | YES | YES | YES | YES |
| Header resolver | YES | YES | YES | YES |
| Path resolver | YES | YES | YES | YES |
| Custom resolver interface | YES | YES | YES | YES |
| Cache bootstrapper | YES (tag/prefix) | YES (decorator) | YES (claims) | YES (prefix) |
| Filesystem bootstrapper | YES | NO | YES (claims) | YES (Flysystem) |
| Messenger / Queue context | YES (queue bootstrapper) | NO | YES (claims) | YES (TenantStamp) |
| Mailer bootstrapper | YES (v4) | NO | YES (claims) | v1.1 |
| Strict mode | NO | NO | Unknown | YES (default ON) |
| `#[TenantAware]` entity attribute | NO (uses global scoping) | Partial (#[TenantShared]) | Unknown | YES |
| Profiler / toolbar integration | NO | NO | NO | YES |
| PHPStan extension | NO | NO | NO | YES |
| PHPUnit test trait | Partial | YES (basic TenantTestTrait) | YES (claims) | YES (full WebTestCase) |
| `tenants:migrate` CLI | YES | Partial | YES | YES |
| `tenants:run` CLI | YES | NO | Unknown | YES |
| Resource syncing | YES | Partial (#[TenantShared]) | NO | YES (v1.1) |
| Symfony Flex recipe | N/A | NO | NO | YES |
| OSS maturity | HIGH (24k GitHub stars) | MEDIUM (active, beta) | LOW (new) | — |

---

## Sources

- [Tenancy for Laravel v3 — Bootstrappers](https://tenancyforlaravel.com/docs/v3/tenancy-bootstrappers/) — HIGH confidence (official docs)
- [Tenancy for Laravel v3 — Configuration](https://tenancyforlaravel.com/docs/v3/configuration/) — HIGH confidence (official docs)
- [stancl/tenancy v4 — What's New](https://v4.tenancyforlaravel.com/version-4/) — HIGH confidence (official docs)
- [RamyHakam/multi_tenancy_bundle — GitHub](https://github.com/RamyHakam/multi_tenancy_bundle) — HIGH confidence (source code + README)
- [hakam/multi-tenancy-bundle — Packagist](https://packagist.org/packages/hakam/multi-tenancy-bundle) — HIGH confidence
- [zhortein/multi-tenant-bundle — Packagist](https://packagist.org/packages/zhortein/multi-tenant-bundle) — MEDIUM confidence (package description only; claims not verified against source)
- [SymfonyCon Amsterdam 2025: Multi-Tenantize Symfony Components](https://symfony.com/blog/symfonycon-amsterdam-2025-multi-tenantize-the-symfony-components) — HIGH confidence (official Symfony blog; confirms Messenger/Cache/Scheduler gap)
- [SymfonyCon Brussels 2023: Multi-tenant applications using Symfony, for real?](https://symfony.com/blog/symfonycon-brussels-2023-multi-tenant-applications-using-symfony-for-real) — HIGH confidence (official Symfony blog; confirms ecosystem gap)
- [Multi-tenant applications using Symfony, for real? — SymfonyOnline Jan 2024](https://live.symfony.com/2024-online-january/schedule/multi-tenant-applications-using-symfony-for-real) — HIGH confidence (official Symfony Live; confirms perception of complexity)
- [Implement Multi Tenant Architecture in Symfony — DEV.to](https://dev.to/tbeaumont79/implement-multi-tenant-architecture-in-symfony-4l1l) — MEDIUM confidence (practitioner article)

---
*Feature research for: Symfony multi-tenancy bundle (OSS Packagist)*
*Researched: 2026-03-17*
