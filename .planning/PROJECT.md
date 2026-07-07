# Symfony Tenancy Bundle

## Current State

**Shipped:** **v0.5 Operations & Scale (2026-07-06, tag v0.5.0)** — production-readiness for running the bundle at scale. Four phases (31–34, 16 plans, 970 PHPUnit tests / 3830 assertions, PHPStan L9, cs-fixer @Symfony, docs-lint, examples/saas smoke green on PHP 8.2): parallel `tenancy:migrate` via a bounded `symfony/process` worker pool (ISOL-07..12); per-tenant maintenance mode — priority-16 listener returning HTTP 503 + `Retry-After` with allow-list bypass and three `tenancy:maintenance:*` commands (MAINT-01..09); tenant health checks — IETF `application/health+json` HTTP endpoints + `tenancy:health` CLI + optional LiipMonitorBundle, DSN-redacted throughout (HEALTH-01..07); a production `docs/ops/` section (DOC-21); and the full v0.4 carry-forward closure (DEMO-02 saas PHP-version pin, GOV-02 Nyquist policy, QA-01 two regression tests). Zero new production dependencies.

**Prior:** v0.4.1 (2026-06-23, tag) — release-integrity patch over v0.4.0 (per-tenant Filesystem bootstrapper, `#[Shared]` landlord→tenant sync + `tenancy:shared:resync`, consumer PHPStan extension, docs refresh); plus post-tag `composer audit` gate + Symfony 8.1 support. Packagist-published at `danplaton4/tenancy-bundle`.

**Next:** v0.6 (demand-gated) — **ISOL-06** PostgreSQL Row-Level Security driver is the standing candidate; a v1.0 tag remains deferred until external-adoption signals validate the public surface. Start with `/gsd:new-milestone`.

<details>
<summary>Prior milestone — v0.4 Storage & Shared Entities (shipped 2026-06-19, tag v0.4.0)</summary>

**Goal:** Make a real SaaS work end-to-end. v0.3 closed the install funnel; v0.4 closed the storage + data-sharing gaps.

**Delivered (all 6 requirements):** BOOT-03 per-tenant Filesystem bootstrapper (prefix + per-tenant-adapter modes); SHARE-01 `#[Shared]` sync model (landlord-side master → tenant-side read-only copy, compile-time mutual exclusion, write protection, one-level cascade); SHARE-02 `tenancy:shared:resync` (idempotent, continue-on-failure, `--dry-run`); SHARE-03 async fan-out via Messenger (opt-in, scalar message, re-fetch-at-handle); DX-03 PHPStan extension (`tenancy.mutualExclusion`/`tenancy.sharedEntityLeak`/`tenancy.tenantIdDrift`, extension-installer auto-load); DOC-20 docs refresh. Plus audit-driven Phase 30 pre-tag closure (W-01/W-02/W-03 + WR-06/WR-07 + WR-03).

**Known deferred (non-blocking):** 2 manual-UAT items (Phase 26 TTY confirm, Phase 28 extension-installer auto-load), 5 Nyquist VALIDATION.md discovery flags, Phase 27 advisory code-review notes, `mkdocs build --strict` CI-deferred.

Full milestone history: `.planning/milestones/v0.4-ROADMAP.md` + `.planning/MILESTONES.md`.

</details>

<details>
<summary>Prior milestone — v0.3 Adoption Surface (shipped 2026-05-29, tag v0.3.3)</summary>

**Goal:** Lower install friction and ship the highest-leverage missing features. v0.2 shipped to two self-installs / zero external dependents; v0.3 attacked the install funnel.

**Target features (all shipped):**
- `tenancy:install` single-command setup (auto-registers bundle, runs `tenancy:init`)
- Demo app in `examples/saas` — two tenants, `docker compose up`, subdomain routing
- Symfony Profiler "Tenancy" WDT tab
- Mailer bootstrapper (per-tenant SMTP + From)
- `OriginHeaderResolver` (SPA-friendly)
- Docs refresh + canonical roadmap mirrored to docs site

**Key context:** No Symfony Flex recipe (revisit when install volume justifies cost). No v1.0 tag (deferred until external adoption signals validation). Tight scope held — 7 active phases shipped in ~14 days.

Full milestone history: `.planning/milestones/v0.3-ROADMAP.md` + `.planning/MILESTONES.md`.

</details>

## Current Milestone: v0.5 Operations & Scale

**Theme:** Production-readiness. v0.4 made a real SaaS work end-to-end; v0.5 makes it operable at scale.

**Goal:** Give operators per-tenant maintenance control, health visibility, faster migrations, and the docs to run the bundle in production.

**Target features (confirmed scope):**
- **OPS-01:** Tenant-level maintenance mode — per-tenant toggle, isolated from other tenants
- **OPS-02:** Health check / MonitorBundle integration — per-tenant connectivity + bootstrapper probes
- **ISOL-07:** Parallel `tenancy:migrate` via `symfony/process` (sequential since Phase 07; parallelize now)
- **Ops docs:** new section — production deploy guide + runbook patterns

**Folded-in v0.4 carry-forward (confirmed in scope):**
- Fix `examples/saas` `Dockerfile` ↔ `composer.lock` PHP-version drift (open since v0.3)
- Decide + apply Nyquist `VALIDATION.md` enforcement (vs v0.4's discovery-only stance)
- Close the 2 `human_needed` UAT items (Phase 26 TTY confirm, Phase 28 extension-installer auto-load) — convert to code-level testability seams or exercise in a real consumer

**Planning lesson to carry:** Closure-phase CONTEXT.md should `git log --since=<audit-date>` over canonical_refs files before generating decisions (Phase 23; reaffirmed Phase 30).

Phase numbering continues from **Phase 31**. Detailed requirements live in `.planning/REQUIREMENTS.md`; phase breakdown in `.planning/ROADMAP.md`.

## What This Is

A definitive multi-tenancy bundle for Symfony that treats tenancy as a first-class citizen of the Symfony kernel — not just a database switcher, but a **Context Orchestrator**. When a tenant is identified, the entire application state (database, cache, messenger) automatically follows suit. Published on Packagist as the Symfony equivalent of `stancl/tenancy` for Laravel.

## Core Value

When a tenant is resolved, every Symfony service automatically re-configures itself for that tenant — zero boilerplate, zero leaks, zero guessing.

## Requirements

### Validated

**Architectural Fixes — v0.2 (Phase 15 — 2026-04-20)**
- ✓ **FIX-01** Cache decorator contract completeness: `TenantAwareCacheAdapter` implements the full `cache.app` surface (`AdapterInterface, CacheInterface, NamespacedPoolInterface, PruneableInterface, ResettableInterface`); `TenantAwareTagAwareCacheAdapter` sibling for `cache.app.taggable`; `CacheDecoratorContractPass` compile-time guard. Closes issue #5. — v0.2
- ✓ **FIX-02** Resolver optionality: `ResolverChain::resolve()` returns nullable `TenantResolution`; orchestrator null-branches (public/landlord/health routes proceed without tenant); `TenantNotFoundException` narrowed. Closes issue #6. — v0.2
- ✓ **FIX-03** DBAL driver-middleware migration: `TenantDriverMiddleware` + `TenantAwareDriver` replace `wrapperClass` + `ReflectionProperty`; `DatabaseSwitchBootstrapper::boot()` reduces to `close()`; `TenantConnection` deleted outright. Closes issues #7 and #8. — v0.2
- ✓ **FIX-04** Documentation alignment: all docs reflect middleware architecture (`dbal-wrapper.md` → `dbal-middleware.md`); `scripts/docs-lint.sh` prevents future drift; CHANGELOG [0.2.0] + UPGRADE 0.1→0.2. — v0.2

**Documentation Refresh (Phase 14 — 2026-04-14)**
- ✓ **DOC-18** Remove all Flex artifacts and references; update docs for Phase 12–13 changes (`tenancy:init` as primary setup, cache_prefix_separator default, EM targeting). — v0.2

**Audit Gap Closure (Phase 13 — 2026-04-13)**
- ✓ **OSS-01** `composer.json` + `composer.lock` sync. — v0.2
- ✓ **BOOT-01/02** EntityManager targeting + separator wiring fixes. — v0.2
- ✓ **CLI-01** `tenancy:migrate` type fix. — v0.2
- ✓ **RESV-05** Resolver chain config wiring. — v0.2

**Developer Onboarding (Phase 12 — 2026-04-13, finalized Phase 15 — 2026-04-21)**
- ✓ **DX-04** `tenancy:init` scaffolds fully commented `config/packages/tenancy.yaml`. — v0.2
- ✓ **DX-05** Doctrine ORM detection + driver recommendation (`database_per_tenant` vs `shared_db`); testable via protected `detectDoctrine()` seam. — v0.2

**Documentation Site (Phase 11 — 2026-04-12)**
- ✓ **DOC-01..17** MkDocs Material site deployed to GitHub Pages with user-guide, contributor-guide, and architecture reference sections. — v0.2

**Dependency Compatibility (Phase 10 — 2026-04-10)**
- ✓ PHP 8.2/8.3/8.4 × Symfony 7.4/8.0 matrix, `prefer-lowest` and `no-messenger` CI jobs, deprecation detection. — v0.2

**OSS Hardening (Phase 09 — 2026-04-12)**
- ✓ **OSS-02** README + CONTRIBUTING.md with badges, quick-start, comparison table. — v0.2
- ✓ **OSS-03** Packagist discoverability metadata. — v0.2
- ✓ **OSS-04** GitHub Actions CI matrix, PHPStan level 9, php-cs-fixer @Symfony. — v0.2

**Developer Experience (Phase 08 — 2026-04-02)**
- [x] `InteractsWithTenancy` PHPUnit trait: `initializeTenant(string $slug)` boots clean tenant context (`:memory:` SQLite, schema, bootstrappers) per test method; `clearTenant()`, `tearDown()` auto-cleanup; `assertTenantActive()`, `assertNoTenant()`, `getTenantService()` helpers (DX-01, Validated in Phase 08)
- [x] `TenancyTestKernel`: database-per-tenant mode test kernel for trait integration tests — `TenantConnection` wrapperClass, `MakeTenancyTestServicesPublicPass` exposing private tenancy services in test container (DX-01, Validated in Phase 08)

**CLI Commands (Phase 07 — 2026-04-02)**
- [x] `TenantProviderInterface::findAll()`: returns all tenants from landlord EM, bypasses cache — powers sequential migration loop (CLI-01, Validated in Phase 07)
- [x] `TenantMigrateCommand`: `tenancy:migrate` sequential per-tenant migration with continue-on-failure, per-tenant status output, summary table, exit code 1 on any failure, `--tenant=<slug>` filter, shared_db driver guard, `class_exists` guard for doctrine/migrations (CLI-01, Validated in Phase 07)
- [x] `TenantRunCommand`: `tenancy:run <slug> "command args"` spawns subprocess via `Process::fromShellCommandline`, validates tenant exists first, forwards stdout/stderr, propagates exit code — full tenant context via ConsoleResolver `--tenant=` arg (CLI-02, Validated in Phase 07)
- [x] `symfony/process` promoted to production `require` — `tenancy:run` is production code (CLI-02, Validated in Phase 07)

**Messenger Integration (Phase 06 — 2026-03-19)**
- [x] `TenantStamp`: `StampInterface` implementation carrying tenant slug across process boundaries — survives PHP serialize/unserialize round-trip (MSG-01, Validated in Phase 06)
- [x] `TenantSendingMiddleware`: attaches `TenantStamp` on dispatch when tenant is active, idempotency guard prevents double-stamping (MSG-02, Validated in Phase 06)
- [x] `TenantWorkerMiddleware`: restores tenant context from stamp on consume, canonical `try/finally` teardown (bootstrapperChain → tenantContext → TenantContextCleared), passes through unstamped envelopes (MSG-03, Validated in Phase 06)
- [x] `MessengerMiddlewarePass`: compiler pass (priority 1) auto-enrolls both middlewares into all Messenger buses — zero user config, guarded by `interface_exists(MessageBusInterface)` (MSG-01–03, Validated in Phase 06)

**Infrastructure Bootstrappers (Phase 05 — 2026-03-19)**
- [x] `DoctrineBootstrapper`: calls `EntityManager::clear()` on `boot()` and `clear()` — prevents cross-tenant identity map pollution (BOOT-01, Validated in Phase 05)
- [x] `TenantAwareCacheAdapter`: decorates `cache.app` with `withSubNamespace(slug)` per cache operation — adapter-level namespace isolation, not key-prefix (BOOT-02, Validated in Phase 05)
- [x] `EntityManagerResetListener` bug fixed: `resetManager('tenant')` → `resetManager()` — now works correctly in both `database_per_tenant` and `shared_db` modes

**Shared-DB Isolation (Phase 04 — 2026-03-19)**
- [x] Shared-database driver: Doctrine SQL Filter auto-enabled for entities marked `#[TenantAware]` (Validated in Phase 04: shared-db-driver)
- [x] `#[TenantAware]` attribute: marks Doctrine entities for automatic tenant scoping (ISOL-03)
- [x] `TenantAwareFilter`: Doctrine SQL filter with 4-branch logic — scoped query, empty for non-aware, strict throw, permissive passthrough (ISOL-04)
- [x] `SharedDriver`: bootstrapper that injects `TenantContext` into `TenantAwareFilter` on `boot()` (ISOL-05)
- [x] Bundle wiring: compile-time guard blocking `shared_db + database.enabled`, conditional service registration, `prependExtension` Doctrine filter registration (ISOL-05)

**Database Isolation (Phase 03 — 2026-03-19)**
- [x] Database-per-tenant driver: swap DBAL connection parameters at runtime per tenant (Validated in Phase 03: database-per-tenant-driver)
- [x] `TenantConnection` DBAL wrapperClass subclass switches DB connection via reflection on private `$params` at runtime (ISOL-01)
- [x] `DatabaseSwitchBootstrapper` plugs into `BootstrapperChain` to trigger connection switch per tenant request (ISOL-01)
- [x] `EntityManagerResetListener` resets tenant EM on `TenantContextCleared` to prevent identity map pollution (ISOL-02)
- [x] Dual-EM DI wiring: `tenancy.database.enabled` flag, landlord EM for `DoctrineTenantProvider`, tenant EM for app queries (ISOL-02)
- [x] `prependExtension` conditionally targets `entity_managers.landlord.mappings` when `database.enabled=true` (ISOL-02)

**Core Foundation (Phase 01)**
- [x] Event-driven bootstrapping: `TenantResolved`, `TenantBootstrapped`, `TenantContextCleared` events
- [x] `BootstrapperChain` with compiler pass autoconfiguration and priority ordering
- [x] `TenantContext` stateful holder, `TenantContextOrchestratorListener` lifecycle management

**Tenant Resolution (Phase 02)**
- [x] `HostResolver`: subdomain and custom domain resolution
- [x] `HeaderResolver`: `X-Tenant-ID` header resolution
- [x] Pluggable resolver chain with configurable priority

### Active

<!-- v0.5 Operations & Scale is feature-complete (all phases 31–34 validated below). No open Active requirements; the next milestone will repopulate this section. -->

_None — v0.5 is feature-complete. All milestone requirements moved to Validated below. Next: v0.5 tag via `/gsd:complete-milestone`, then v0.6 scoping._

### Validated — v0.5 Operations & Scale (in progress)

- ✓ **ISOL-07..12** Parallel `tenancy:migrate` — bounded subprocess worker pool (`ParallelMigrationRunner`) spawns one out-of-process `tenancy:migrate --tenant=<slug>` child per tenant, at-most-N concurrency (`--concurrency`, default 4, hard cap 32) via non-blocking 50ms sliding-window poll; `--parallel`/`--dry-run`/`--format=json` command surface, atomic per-tenant output blocks (no interleaving), null-exit=failure rule, `shared_db` guard refusing before any spawn, single aggregate JSON document (UTF-8-hardened: `JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR`). Sequential no-flag path byte-identical to v0.4. Runner registered inside the `class_exists(DependencyFactory)` block, wired as the migrate command's 7th arg. — v0.5 (Phase 31, verified 2026-06-26)
- ✓ **MAINT-01..09** Per-tenant Maintenance Mode — DB-authoritative state (`AbstractTenant::$inMaintenance` column + `TenantInterface::isInMaintenance()` + `TenantMaintenanceConfigTrait`); `kernel.request` priority-16 `TenantMaintenanceModeListener` (after `TenantContextOrchestrator` at 20) returns HTTP 503 with `Retry-After` + `Cache-Control: no-store` for an already-resolved in-maintenance tenant, never calling `boot()`; unkillable hardcoded-HTML default with opt-in Twig override (HTML fallback), allow-list bypass by IP/CIDR, route, and path prefix. Three operator commands `tenancy:maintenance:enable|disable|status` — idempotent landlord-side writes that invalidate the PSR cache key `tenancy.tenant.<slug>` after flush (cache-coherence correctness) and dispatch `TenantMaintenanceEnabled/Disabled` only on real transition; `:status` lists via cache-bypassing `findAll()`. `MaintenanceModeContractPass` enforces listener priority < 20 at compile time; Doctrine-optional invariant proven (`ZeroConfigKernelBootTest`). — v0.5 (Phase 32, verified 2026-07-01)
- ✓ **HEALTH-01..07** Tenant Health Checks — dependency-free contract layer (`HealthStatus` enum, sibling `HealthCheckBootstrapperInterface` — no BC break, `BootstrapperHealthResult`/`TenantHealthReport` VOs, `HealthResponseSanitizer` reusing `DsnSanitizer::REDACTION_REGEX`); `TenantHealthChecker::checkOne()` enforces set→probe→clear-in-`finally` (never `boot()`, dispatches no events, `hasTenant()` false after probe) via additive `BootstrapperChain::healthCheck()` + `check()` (`SELECT 1`) probes on both isolation drivers. HTTP surface `TenantHealthController` (live=zero-I/O 200, ready/{slug}=IETF `application/health+json` 200/503, 404 unknown-slug + 503 inactive-slug, always-200 bounded fleet dashboard) from two importable route files; `tenancy:health [--tenant|--all] [--format=json]` CLI with exit-code aggregation. Optional `liip/monitor-bundle` auto-registration via `HealthCheckIntegrationPass` (double-guarded on `CheckInterface` **and** `tenancy.provider` existence — Doctrine-optional safe); every output path DSN-redacted (password-only + slash-containing shapes). — v0.5 (Phase 33, verified 2026-07-06)
- ✓ **DOC-21 / DEMO-02 / GOV-02 / QA-01** Ops docs + v0.4 carry-forward (v0.5 closure) — new `docs/ops/` section (`parallel-migrations.md`, `maintenance-mode.md`, `health-checks.md`) with production Kubernetes probe YAML (distinct liveness/readiness `periodSeconds` + `failureThreshold`), CDN 5xx-caching warnings, and incident runbooks; all response/CLI contracts verified verbatim against shipped source (IETF `application/health+json` shape, `output`-not-`error` keys, exact `--concurrency`/shared-db guard semantics, honest no-DSN-redaction note for migrate JSON), plus `mkdocs.yml` Operations nav group + `docs-lint.sh` D-04 ops-terms guards + UPGRADE.md 0.4→0.5 (`isInMaintenance()` BC break). **DEMO-02:** `examples/saas` platform-pinned `config.platform.php=8.2.99` — smoke-verified live on FrankenPHP PHP 8.2.32 (`bin/smoke.sh` exit 0). **GOV-02:** Nyquist `VALIDATION.md` policy made explicit (advisory-only; green suite is the real gate) + Phase 31 `VALIDATION.md` backfilled. **QA-01:** the two open v0.4 `human_needed` UAT items closed as permanent regression tests (SHARE-02-c confirm-YES apply-branch assertion + extension-installer PHPStan metadata contract). Code-review gate found 10 doc-vs-code drift issues (2 blocker) — all fixed pre-verification. — v0.5 (Phase 34, verified 2026-07-06)

### Validated — v0.4 Storage & Shared Entities (Shipped 2026-06-19, tag v0.4.0)

- ✓ **BOOT-03** Per-tenant Filesystem (Flysystem) bootstrapper — `prefix` mode (default, `tenant_<slug>/` via `PathPrefixer`, live-read `TenantContext`) + `per_tenant_adapter` mode (DSN-parsed, LRU-cached); `FilesystemBootstrapper` priority −30, `FilesystemContractPass` 3-guard compile-time check, `TenantFilesystemConfigTrait` (zero BC break), `MissingFilesystemConfigException`, credential-redacted exceptions. Proven against a real kernel with `league/flysystem-bundle`. — v0.4 (Phase 24)
- ✓ **SHARE-01** Shared-entity sync model — `#[Shared]` marker → landlord-side master fans out a tenant-side read-only copy via `SharedEntitySyncSubscriber` (onFlush buffer + postFlush fan-out); tenant-side write protection (`SharedEntityWriteInTenantContextException`), compile-time `#[Shared]` ⊕ `#[TenantAware]` mutual exclusion, one-level cascade limit (documented landmine). UAT 8/8. — v0.4 (Phase 25)
- ✓ **SHARE-02** `tenancy:shared:resync` command — idempotent bulk/initial sync, two-pass classify→confirm→apply, `--tenant=<slug>|--all`, `--dry-run`, continue-on-failure with per-tenant summary; `SharedEntityCopier` as the single write path (cross-DB key equality). — v0.4 (Phase 26)
- ✓ **SHARE-03** Async shared-entity fan-out via Messenger — opt-in `tenancy.shared.async: true`, scalar `SharedEntityChangedMessage` (class + identifier + change-type), worker re-fetches latest landlord state, best-effort attempt-all → throw-to-retry; `SharedAsyncContractPass` compile-time guard, `SharedEntityAsyncCanaryTest` `sync://` round-trip proof. — v0.4 (Phase 27)
- ✓ **DX-03** PHPStan extension — three rules (`tenancy.mutualExclusion` hierarchy-aware, `tenancy.sharedEntityLeak` gated/conservative, `tenancy.tenantIdDrift` missing/nullable/non-string); soft-integrates `phpstan/phpstan-doctrine` `ObjectMetadataResolver`, degrades to `#[ORM\Column]` reflection; `phpstan/extension-installer` auto-load; no-doctrine CI lane proves optional-dep guards; bundle's own L9 self-analysis stays green and separate. — v0.4 (Phase 28)
- ✓ **DOC-20** Docs refresh — NEW `shared-entities.md` (locked D-07 vocabulary "landlord-side master" + "tenant-side read-only copy") + `phpstan-extension.md` (3 rule IDs) + `filesystem-bootstrapper.md` drift fix, UPGRADE 0.3→0.4 with no-breaking-changes statement, `docs-lint.sh` per-file shared-entity disambiguation check, both pages in `mkdocs.yml`. — v0.4 (Phase 29)

### Validated — v0.3 Adoption Surface (Shipped 2026-05-29, tag v0.3.3)

- ✓ **DX-06** `tenancy:install` command — auto-registers bundle in `config/bundles.php`, delegates to `tenancy:init`. Idempotent, `--dry-run` mode, AST detect via `nikic/php-parser`, refuse non-standard shapes with clean exit. ZeroConfigKernelBootTest canary added during gap closure (2026-05-21) pins zero-config boot path. — v0.3 (Phase 18)
- ✓ **DEMO-01** Demo app `examples/saas/` — two-tenant SaaS under FrankenPHP + Caddy + MariaDB + Mailpit, `bin/smoke.sh` CI release-gate, three-step fallback ladder (curl Host: → /etc/hosts → browser-native `*.localhost`). Live-stack Pass 3 found and fixed 7 latent boot blockers including AbstractTenant entity split (BC break). — v0.3 (Phase 21)
- ✓ **DX-02** Symfony Profiler "Tenancy" panel — three render states (resolved / null / error), scalar-only data for serialized profile round-trip, kernel.debug compile-out, DSN-redaction tripwire defense-in-depth. Mailer subsection hoisted in Phase 23 (INT-01 closure) so it renders on all panel states. — v0.3 (Phase 19)
- ✓ **BOOT-04** Mailer bootstrapper — X-Transport strategy correct under sync AND async Messenger dispatch (TenantMailerDecorator at decoration_priority 10 INNER), `TenantInterface::getMailerDsn()` BC break mitigated by `TenantMailerConfigTrait`, MailerTransportContractPass compile-time guard, DSN sanitization, LRU transport cache with TenantContextCleared listener. AsyncCanaryTest proves tenant-A DSN survives Messenger round-trip. — v0.3 (Phase 20)
- ✓ **RESV-06** `OriginHeaderResolver` — SPA-friendly resolver at priority 25 with browser-locked Origin allow-list, CORS preflight short-circuit, OriginHeaderResolverConfigPass compile-time guard, mismatch warning when Origin and X-Tenant-ID resolve to different tenants. Trust Model docs section explains spoofability from non-browser clients. — v0.3 (Phase 17)
- ✓ **DOC-19** Docs refresh — new pages for OriginHeaderResolver / Profiler tab / Mailer Bootstrapper, thin saas-demo walkthrough, canonical roadmap mirrored to docs site, UPGRADE 0.2→0.3 + 0.3.1→0.3.2 + 0.3.2→0.3.3 sections, `scripts/docs-lint.sh` extended with bundles.php install-path regression guard. — v0.3 (Phase 22)
- ⊘ **GOV-01** Plan↔summary parity check + 72-hour TTL on `human_needed` — SKIPPED as non-functional governance gate (bundle-user value zero). Retrospective items #1 and #2 acknowledged as known gaps; humans surface via RETROSPECTIVE.md. May revisit as part of v0.4 retro carry-forward.

### Later Milestones (planned, scope subject to telemetry)

<!-- v0.5 Operations & Scale is now the Current Milestone (see top of file). -->

**v0.6 — Advanced Isolation** *(demand-gated)*
- **ISOL-06** PostgreSQL Row-Level Security driver
- Advanced isolation docs + when-to-pick-which-driver matrix
- Candidate for v1.0 tag if external adoption signals validate the line

### Future / By Demand

Tracked but not scheduled. **Open an issue to request prioritization** — these are outside the v0.3–v0.6 cadence but are not rejected outright. Public roadmap mirrors this list for user visibility.

- Per-tenant middleware pipelines — powerful but complex; reserved for post-adoption validation
- DNS TXT resolver — custom `TenantResolverInterface` covers it today
- Non-SQL primary isolation targets (Redis, MongoDB) — v2+ territory
- Tenant-aware job scheduler — Messenger covers async context propagation today
- Multi-region / sharding — infrastructure concern outside bundle scope
- Symfony Flex recipe / `symfony/recipes-contrib` submission — adopt when install volume justifies the contrib-submission maintenance cost

## Context

- **Ecosystem gap**: Existing Symfony tenancy packages (RamyHakam, manual SQL filter implementations) are partial solutions — they don't address Messenger, Cache, Filesystem, or provide a unified bootstrapping API.
- **Inspiration**: `stancl/tenancy` for Laravel is the gold standard — event-driven, bootstrapper-first, comprehensive. This bundle brings that philosophy to Symfony idioms (bundles, DI, events, attributes).
- **Symfony idioms to embrace**: Bundle extension config (`Configuration.php`), compiler passes for bootstrapper registration, Doctrine event subscribers, kernel events, Messenger middleware, PHPStan extensions, Flex recipe.
- **Target PHP/Symfony versions**: PHP 8.2+, Symfony 7.4+ / 8.x (LTS-first, then current).
- **Testing philosophy**: Comprehensive test coverage is a selling point — PHPUnit, tenant-aware test trait, isolated DB per test method, PHPStan at max level.

## Constraints

- **Tech stack**: PHP 8.2+, Symfony 7.4/8.x, Doctrine ORM, Flysystem, Symfony Messenger — no framework-agnostic abstractions; lean into Symfony contracts
- **Compatibility**: Must work with both `doctrine/orm` shared-DB and separate-DB without requiring either — drivers are optional dependencies
- **Extensibility**: Every major system (resolvers, bootstrappers, drivers) must be replaceable via the DI container — no hardcoded coupling
- **Zero-leak guarantee**: Strict mode must be on by default; data leaks across tenants are a security incident, not a config mistake
- **OSS quality bar**: PHPStan max level, full test coverage, Symfony coding standards (`php-cs-fixer`), CI on GitHub Actions

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Event-driven bootstrapping over direct service decoration | Events allow user-land bootstrappers without modifying bundle internals; matches stancl/tenancy's proven model | ✓ Good (validated Phase 01–06) |
| Hybrid bootstrapping: kernel events for infra, decorators for services | Kernel events handle connection swaps (coarse); decorators handle per-call service routing (fine-grained) | ✓ Good (validated Phase 05) |
| `#[TenantAware]` PHP attribute over YAML/XML config | Collocated with the entity; enforced by PHPStan; immediately visible to future devs | ✓ Good (validated Phase 04) |
| Strict mode ON by default | Security default — a data leak is worse than a 500; developers opt out explicitly | ✓ Good (validated Phase 04; StrictModeWithNullResolutionTest added in Phase 15) |
| Sequential migrations in v1 | Simplicity and correctness over speed; parallel via `symfony/process` deferred | ✓ Good (validated Phase 07) |
| DBAL 4 driver-middleware over `wrapperClass` + `ReflectionProperty` | Correct extension point for DBAL 4; `wrapperClass` could not mutate `Connection::$driver` (issues #7/#8) | ✓ Good (Phase 15; supersedes ISOL-01 mechanism) |
| `ResolverChain::resolve()` returns nullable instead of throwing | Public/landlord/health-check routes proceed without a tenant; narrow `TenantNotFoundException` to provider-level rejection | ✓ Good (Phase 15) |
| Full contract parity for cache decorators + compile-time guard | Liskov at the DI level — a decorator must honor every interface the decorated service exposes (issue #5) | ✓ Good (Phase 15) |
| Remove Symfony Flex recipe, use `tenancy:init` instead | Lower-maintenance, more discoverable, zero race with external recipe submission flow | ✓ Good (Phase 14) |
| Retract v1.0.0, restart at v0.1.0, graduate to v0.2.0 | Four defects (#5–#8) surfaced in downstream demo projects post-tag; architectural fixes rather than patches | ✓ Good (Phase 15 — semver integrity) |
| Filesystem adapter-strategy defaults to `prefix` mode | Lower-friction onboarding; per-tenant-adapter is opt-in for S3-style separation (DEC-FILE-01) | ✓ Good (validated Phase 24) |
| `#[Shared]` sync mode defaults to synchronous | Predictable + easy to reason about; async is opt-in via `tenancy.shared.async` (DEC-SHARE-01) | ✓ Good (validated Phase 25/27) |
| One-level cascade for `#[Shared]` fan-out | Avoids unbounded sync cost; every shared entity carries an explicit `#[Shared]` (DEC-SHARE-02) | ✓ Good (validated Phase 25) |
| `#[Shared]` ⊕ `#[TenantAware]` mutual exclusion at compile time + PHPStan rule | Prevents the cross-tenant data-leak bug class entirely, not per-instance (DEC-SHARE-03) | ✓ Good (validated Phase 25/28) |
| PHPStan extension via `phpstan/extension-installer` auto-load | Zero-config, same pattern as phpstan-symfony/doctrine; degrades gracefully without phpstan-doctrine (DEC-PHPSTAN-01) | ✓ Good (validated Phase 28) |
| Single `TenantEmSwitcher` owns tenant-switch logic; resync keeps `setTenant()`+`boot()` | De-duplicates byte-identical logic across subscriber + async handler; resync's bootstrapper path is intentionally asymmetric and documented (W-02/W-03) | ✓ Good (validated Phase 30) |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd:complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-07-06 after **v0.5 Operations & Scale** milestone completion (tag v0.5.0). All four phases shipped + verified: 31 (ISOL-07..12 parallel migrations), 32 (MAINT-01..09 maintenance mode), 33 (HEALTH-01..07 health checks), 34 (DOC-21 ops docs + DEMO-02/GOV-02/QA-01 carry-forward). 16 plans, 970 PHPUnit tests / 3830 assertions, PHPStan L9, cs-fixer @Symfony, docs-lint, examples/saas smoke green on PHP 8.2. REQUIREMENTS.md archived to `.planning/milestones/v0.5-REQUIREMENTS.md`; a fresh one is created at the next `/gsd:new-milestone`. 6 pre-v0.5 residual UAT/verification items acknowledged as deferred (see STATE.md). Next: v0.6 (demand-gated) — ISOL-06 PostgreSQL RLS is the standing candidate; v1.0 tag deferred pending external adoption. Full history: `.planning/MILESTONES.md` + `.planning/milestones/v0.5-ROADMAP.md`.*
