# Milestones

## v0.3 Adoption Surface (Shipped: 2026-05-29, Tag: v0.3.3)

**Phases completed:** 7 phases (17–23; Phase 16 GOV-01 skipped as non-functional gate), 53 plans, ~110 tasks
**Git range:** v0.2.0 → v0.3.3 (~14 days of active work across 2026-05-15 to 2026-05-29)
**Test suite:** 568 PHPUnit tests / 2122 assertions (up from 304 in v0.2); PHPStan level 9 clean; php-cs-fixer @Symfony clean; docs-lint clean; composer validates.

**Key accomplishments:**

- **OriginHeaderResolver (RESV-06, Phase 17):** SPA-friendly tenant resolver at priority 25 with browser-locked Origin header + allow-list, compile-time guard, CORS preflight short-circuit, and Trust Model docs section. 333/333 tests pass.
- **`tenancy:install` one-command setup (DX-06, Phase 18):** AST-based `bundles.php` registration via `nikic/php-parser` (later promoted to `require` in Phase 22), idempotent re-run, `--dry-run` mode, atomic write with `.bak` restore, refuse-non-standard-shapes contract. ZeroConfigKernelBootTest canary added during Phase 18 gap closure pins the zero-config boot path against future regressions.
- **Symfony Profiler "Tenancy" panel (DX-02, Phase 19):** Three-state Web Debug Toolbar tab (resolved / null / error), scalar-only `$this->data` for serialized profile round-trip, kernel.debug compile-out from prod containers, DSN-redaction tripwire defense-in-depth.
- **Per-tenant Mailer bootstrapper (BOOT-04, Phase 20):** X-Transport strategy correct under BOTH sync AND async (Messenger) dispatch via TenantMailerDecorator at decoration_priority 10 INNER. AsyncCanaryTest proves tenant A's DSN survives Messenger round-trip in a clean worker context. TenantInterface gets `getMailerDsn()` BC break, mitigated by `TenantMailerConfigTrait`. DSN redaction (DsnSanitizer + sanitizing decorator) keeps credentials out of exception traces.
- **Runnable demo app (DEMO-01, Phase 21):** `examples/saas/` ships a two-tenant SaaS with FrankenPHP + Caddy + MariaDB + Mailpit, three-step fallback ladder (curl Host: → /etc/hosts → browser-native `*.localhost`), `bin/smoke.sh` CI release-gate. Live-stack Pass 3 found and fixed 7 latent boot blockers including bundle Tenant entity split into AbstractTenant (MappedSuperclass) + concrete Tenant — BC break for downstream users.
- **Docs refresh (DOC-19, Phase 22):** New user-guide pages for OriginHeaderResolver / Profiler / Mailer Bootstrapper + thin saas-demo walkthrough + canonical roadmap mirrored to docs site + UPGRADE 0.2→0.3 (Mailer trait migration) + 0.3.1→0.3.2 (AbstractTenant split) + 0.3.2→0.3.3 (nikic require). `scripts/docs-lint.sh` extended with `bundles.php` install-path regression guard.
- **Audit-driven tech-debt closure (Phase 23):** `/gsd:audit-milestone` produced status `tech_debt`; 7-plan closure phase fixed INT-01 Profiler/Mailer Twig contract drift, added LogicException test pinning (WR-01) for Messenger no-retry semantics, extended smoke.sh with per-tenant mailer-isolation assertion via Mailpit `/api/v1/messages` + `jq -e`, promoted CHANGELOG Unreleased → 0.3.2/0.3.3 versioned sections, refreshed REQUIREMENTS.md checkboxes. Two documented stale-audit deviations: CR-01 source edits skipped (already closed by commit 31465dc in opposite direction — PHP 8.0+ optional-before-required deprecation), IN-05 `use TenantStamp` skipped (cs-fixer `no_unused_imports` strips same-namespace imports).
- **Nyquist VALIDATION sweep:** Phases 19/20/21 frontmatter refreshed from `status: draft, nyquist_compliant: false` (pre-execution planning state) to `status: complete, nyquist_compliant: true` matching shipped reality. Phase 22 VALIDATION.md generated from scratch (was missing); 18 task IDs mapped to source-assertion / functional commands; 4 manual-only items preserved (mkdocs --strict CI-gated + 3 visual checks).

**Audit findings, all closed:**

- v0.3 milestone audit produced status `tech_debt` (0 BLOCKERs, 6/6 REQs satisfied, 17 tech-debt items + 1 cross-phase WARNING). Phase 23 closure phase landed all closeable items; the 4 human-UAT items in 22-HUMAN-UAT.md remain non-blocking (CI-gated).

**v0.3 Architectural Decisions Ratified:** DEC-MAIL-01 X-Transport strategy, DEC-MAIL-02 full BOOT-04 in v0.3, DEC-MAIL-03 BC break with trait, DEC-RESV-01 priority 25, DEC-PROF-01 TenantResolved subscriber, DEC-INST-01 programmatic invoke, DEC-INST-02 refuse-on-nonstandard, DEC-DEMO-01 Caddy + `*.tenancy.localhost`.

**Explicit non-goal still:** Symfony Flex recipe. `tenancy:install` is the supported onboarding path; revisit `symfony/recipes-contrib` only when install volume justifies the maintenance cost.

---

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
