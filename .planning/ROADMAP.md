# Roadmap: Symfony Tenancy Bundle

## Milestones

- ✅ **v0.2 Architectural Fixes** — Phases 1–15 (shipped 2026-04-20)
- 📋 **v0.3 Adoption Surface** — install ergonomics + demo + Profiler tab + Mailer + OriginHeaderResolver + docs refresh
- 📋 **v0.4 Storage & Shared Entities** — Filesystem bootstrapper, shared entities sync/async, PHPStan extension
- 📋 **v0.5 Operations & Scale** — maintenance mode, health checks, parallel migrations
- 📋 **v0.6 Advanced Isolation** *(demand-gated)* — PostgreSQL RLS driver; v1.0 candidate if adoption validates

## Phases

<details>
<summary>✅ v0.2 Architectural Fixes (Phases 1–15) — SHIPPED 2026-04-20</summary>

- [x] Phase 1: Core Foundation (completed 2026-03-18)
- [x] Phase 2: Tenant Resolution (completed 2026-03-18)
- [x] Phase 3: Database-Per-Tenant Driver (completed 2026-03-19)
- [x] Phase 4: Shared-DB Driver (completed 2026-03-19)
- [x] Phase 5: Infrastructure Bootstrappers (completed 2026-03-19)
- [x] Phase 6: Messenger Integration (completed 2026-03-20)
- [x] Phase 7: CLI Commands (completed 2026-03-21)
- [x] Phase 8: Developer Experience — InteractsWithTenancy (completed 2026-04-02)
- [x] Phase 9: OSS Hardening (completed 2026-04-12)
- [x] Phase 10: Dependency Compatibility Audit (completed 2026-04-10)
- [x] Phase 11: Documentation Site — MkDocs Material (completed 2026-04-12)
- [x] Phase 12: Developer Onboarding — tenancy:init (completed 2026-04-13)
- [x] Phase 13: Audit Gap Closure (completed 2026-04-13)
- [x] Phase 14: Documentation refresh — remove Flex (completed 2026-04-14)
- [x] Phase 15: Architectural Fixes (v0.2) — cache, resolver, DBAL middleware, docs (completed 2026-04-20)

**Full details:** See `.planning/milestones/v0.2-ROADMAP.md` for phase goals, requirements, and plan breakdowns.

</details>

### 📋 v0.3 Adoption Surface — Phases 17–22 (6 active)

Goal: lower install friction + ship the highest-leverage missing features. 6 active phases, 6 active requirements. Phase 16 / GOV-01 skipped as a non-functional gate (see below). See `.planning/REQUIREMENTS.md` for full acceptance criteria and `.planning/research/SUMMARY.md` for the research synthesis.

- ⊘ **Phase 16: Governance Carry-Forward** — **SKIPPED.** Non-functional process tooling (`audit-open` extension). Retrospective items #1 (plan↔summary parity) and #2 (`human_needed` 72h TTL) acknowledged as gaps in `RETROSPECTIVE.md` but intentionally not enforced via tooling. Bundle-user value is zero; the v0.2 retrospective surfaces the lessons humans need without machine enforcement. Phase number retained for stable references; downstream phase numbers (17–22) unchanged.
- [x] **Phase 17: OriginHeaderResolver** — SPA-friendly resolver at priority 25, allow-list config, `OriginHeaderResolverConfigPass` guard (RESV-06) (completed 2026-05-15)
- [x] **Phase 18: tenancy:install** — single-command setup (auto-registers bundle, runs `tenancy:init`), `nikic/php-parser` detection, ≥6 fixture corpus, atomic write + .bak (DX-06). Originally completed 2026-05-18; gap reopened 2026-05-21 after human UAT exposed zero-config boot regression at 6 `nullOnInvalid()` consumer sites with non-nullable signatures; gap-closure plans 18-08…18-11 + follow-up fixes (CR-01/WR-01/WR-04) landed 2026-05-21. Shipped in v0.3.0 on 2026-05-22.
- [x] **Phase 19: Profiler Tab** — `TenantDataCollector` + Twig template, dev-only, three render states (resolved/null/error), stored-profile reload tested (DX-02) (completed 2026-05-19)
- [x] **Phase 20: Mailer Bootstrapper** — `X-Transport` strategy (sync + async safe), `TenantInterface` BC break + trait migration, `MailerTransportContractPass` guard, async canary test (BOOT-04) (completed 2026-05-20)
- [ ] **Phase 21: Demo App** — `examples/saas/` with FrankenPHP + Caddy + MariaDB, three-step fallback ladder, `bin/smoke.sh` CI release-gate (DEMO-01)
- [ ] **Phase 22: Docs Refresh** — install page rewrite, new pages (resolver/profiler/mailer/demo/roadmap), UPGRADE 0.2→0.3, docs-lint extended (DOC-19)

**Architectural decisions ratified** (see `REQUIREMENTS.md#architectural-decisions-ratified`): DEC-MAIL-01 X-Transport strategy, DEC-MAIL-02 full BOOT-04 in v0.3, DEC-MAIL-03 BC break with trait, DEC-RESV-01 priority 25, DEC-PROF-01 TenantResolved subscriber, DEC-INST-01 programmatic invoke, DEC-INST-02 refuse-on-nonstandard, DEC-DEMO-01 Caddy + `*.tenancy.localhost`.

**Explicit non-goal:** Symfony Flex recipe. Setup command is the supported onboarding path; revisit `symfony/recipes-contrib` only when install volume justifies the maintenance cost.

#### Phase Details (v0.3)

### Phase 17: OriginHeaderResolver

**Goal:** Ship a SPA-friendly tenant resolver that reads the `Origin` HTTP header, sits in the resolver chain at priority 25 (above `HeaderResolver`, below `HostResolver`), and exposes its trust model honestly in docs.

**Requirements:** RESV-06

**Success criteria:**
1. A request with a known `Origin` header value (matched against the configured allow-list) resolves the tenant from the chain
2. A `CORS` preflight (`OPTIONS`) request with `Origin` set does NOT throw — resolver returns `null` and chain falls through
3. Container compilation fails with a clear error message when `OriginHeaderResolver` is configured with an empty allow-list or with mid-string wildcards (`OriginHeaderResolverConfigPass`)
4. When both `Origin` and `X-Tenant-ID` resolve to different tenants in the same request, `Origin` wins and a `warning`-level log entry records the conflict
5. User-facing docs include a dedicated "Trust Model" section explaining `Origin` is browser-protected cross-origin but spoofable from non-browser clients

**Status:** ✅ Complete (2026-05-15) — 5 plans, 7 review-driven fix commits, 340/340 tests pass.

### Phase 18: tenancy:install

**Goal:** A first-time user runs `composer require danplaton4/tenancy-bundle && bin/console tenancy:install` and the bundle is registered, configured, and ready — no manual `config/bundles.php` editing on the install path.

**Requirements:** DX-06

**Research needed (limited):** Assemble a `bundles.php` fixture corpus of ≥6 real-project shapes (standard skeleton, API Platform, Sulu, DDD-with-`registerBundles()`-override, project-with-comments, project-with-env-conditionals). Confirm `nikic/php-parser` API for preserving the file's existing formatting (or accept reformatting + idempotency over byte-for-byte preservation).

**Success criteria:**
1. `bin/console tenancy:install` on a fresh Symfony skeleton results in `TenancyBundle::class` registered in `config/bundles.php` AND `config/packages/tenancy.yaml` written by the delegated `tenancy:init` call — single command, no manual edits
2. Re-running `bin/console tenancy:install` is idempotent (bundle already present → exits 0 with informational message, no file mutation)
3. `bin/console tenancy:install --dry-run` prints the proposed mutation to stdout without writing
4. On any of the 6 fixture-corpus shapes the command either (a) succeeds, OR (b) refuses to mutate and prints a clean manual snippet — never produces an invalid `bundles.php` (post-mutation `php -l` check enforces this; `.bak` restore on failure)
5. `nikic/php-parser` is in `require-dev` only and loaded lazily (its absence from `require` is verified by a test on the bundle's runtime container)

**Status:** ✅ Initial scope shipped 2026-05-18 (7 plans). ⚠ Reopened 2026-05-21 — human UAT on a fresh Symfony skeleton hit a blocker `TypeError` during post-`composer require` `cache:clear`. 6 `nullOnInvalid()` consumer sites declared non-nullable `TenantProviderInterface` parameters; bundle was unbootable in zero-config mode. Gap closure in 4 plans (18-08 → 18-11).

**Plans:** 11/11 plans complete

Plans:
**Original scope (Wave 1–4, shipped 2026-05-18)**
- [x] 18-01-PLAN.md — composer manifest + nikic/php-parser dev dep + suggest block
- [x] 18-02-PLAN.md — BundlesPhpInstaller AST detect/refuse path
- [x] 18-03-PLAN.md — BundlesPhpInstaller atomic write + .bak + lint + restore
- [x] 18-04-PLAN.md — TenancyInstallCommand + DI registration + tenancy:init delegation
- [x] 18-05-PLAN.md — Fixture corpus (skeleton, api-platform, sulu, ddd-override, with-comments, env-conditional, malformed)
- [x] 18-06-PLAN.md — Unit tests (command + installer + safety + composer contract)
- [x] 18-07-PLAN.md — Integration tests + idempotency proof + CHANGELOG

**Gap closure (Wave 1–3, opened 2026-05-21)**
- [x] 18-08-PLAN.md — Wave 1: ZeroConfigKernelBootTest canary regression test (RED bar)
- [x] 18-09-PLAN.md — Wave 2 (depends 18-08): Fix 4 resolver sites — nullable param + early-return null guard (fail-silent)
- [x] 18-10-PLAN.md — Wave 2 (parallel with 18-09): Fix TenantRunCommand + TenantWorkerMiddleware — nullable param + RuntimeException guard (fail-loud)
- [x] 18-11-PLAN.md — Wave 3 (depends 18-09, 18-10): Green-bar verification + CHANGELOG `### Fixed` + README nikic prereq callout

### Phase 19: Profiler Tab

**Goal:** When the developer hits any page in dev mode and the request has resolved a tenant, the Web Debug Toolbar shows a "Tenancy" panel with the tenant's identity, the resolver that picked it, the active driver, and the bootstrappers that ran.

**Requirements:** DX-02

**Success criteria:**
1. Open the demo app (or any dev-profile app) in a browser → the WDT shows a Tenancy icon with the active tenant slug visible at a glance
2. Click the Tenancy panel → see active slug, tenant ID, driver, connection name, resolved-by FQCN, list of bootstrappers run for the request
3. A null-resolution request (public/landlord/health-check route) shows the Tenancy panel in its "no tenant" state — does not throw, does not hide silently
4. Reloading a stored Profiler dump (after the request has terminated) renders the same panel state — no serialization errors from `$this->data`
5. The Tenancy data collector is registered ONLY when `kernel.debug = true` — production container compilation does not include it (verified by a CI check)

**Plans:** 7/7 plans complete

Plans:
**Wave 1**
- [x] 19-00-PLAN.md — Wave 0: composer deps + test dirs + ProfilerTestKernel
- [x] 19-01-PLAN.md — TenantProfilerStash (event-time capture + reset)
- [x] 19-02-PLAN.md — TenantDataCollector (8-key data shape + DSN defence)

**Wave 2** *(blocked on Wave 1 completion)*
- [x] 19-03-PLAN.md — Twig templates (tenant.html.twig + _icon.svg.twig)
- [x] 19-04-PLAN.md — DI registration + kernel.debug compile-out guard

**Wave 3** *(blocked on Wave 2 completion)*
- [x] 19-05-PLAN.md — Integration tests: compile-out + serialization + source layout
- [x] 19-06-PLAN.md — WDT functional integration test (3 panel states)

### Phase 20: Mailer Bootstrapper

**Goal:** A tenant with a `mailerDsn` configured sends mail from that DSN with the tenant's `From`/`Reply-To` headers — correct under BOTH synchronous Mailer dispatch AND Messenger-routed async dispatch.

**Requirements:** BOOT-04

**Research needed (substantial):** Validate `X-Transport` header survival across all Messenger transports the bundle supports (Doctrine, AMQP, JSON-redis); design the `TenantTransportProviderInterface` fallback for tenants not enumerable at compile time; calibrate the per-tenant transport LRU cache bound; design the DSN-sanitizing exception wrapper; draft the landlord schema migration recipe for adding the `mailerDsn` column.

**Success criteria:**
1. Sync dispatch: in tenant A's HTTP context, `$mailer->send()` delivers via tenant A's SMTP DSN with tenant A's `From` header — verified by a `Mailer\Test\TransportListener` capture
2. Async dispatch (the canary test): dispatch an email in tenant A's HTTP context with Mailer routed to Messenger; the Messenger worker runs in a clean context; the worker-side capture asserts tenant A's SMTP DSN was used — NOT the landlord DSN
3. Container compilation fails with a clear error when the Mailer bootstrapper is enabled but no transport strategy is configured (`MailerTransportContractPass`); additionally fails when Mailer is routed async but the strategy is not `x_transport`
4. An existing user's custom `Tenant` entity (without `mailerDsn` field) breaks compilation with a clear migration path: `use TenantMailerConfigTrait;` or implement `getMailerDsn(): ?string` — documented in `UPGRADE.md`
5. A thrown `TransportException` during send does NOT leak the DSN's password component in its message or trace (sanitization wrapper)
6. After `TenantContextCleared` event, the per-tenant transport cache is cleared (verified by a long-running-worker simulation test that processes messages for 100 distinct tenants without unbounded socket growth)

**Plans:** 12/12 plans complete

Plans:
**Wave 0**
- [x] 20-00-PLAN.md — Test scaffolding: stub PHPUnit classes + MailerTestKernel + SpyTransport + symfony/mailer require-dev
**Wave 1** *(blocked on Wave 0)*
- [x] 20-01-PLAN.md — Extend TenantInterface (BC break) + Tenant entity columns + TenantMailerConfigTrait + UPGRADE.md
- [x] 20-02-PLAN.md — Mailer primitives: DsnSanitizer + LruTransportCache + SanitizingMailerDecorator + TenantSanitizedTransportException
**Wave 2** *(blocked on Wave 1)*
- [x] 20-03-PLAN.md — Mailer wiring: MailerBootstrapper + TenantMessageDecorator + TenantAwareTransportsDecorator
**Wave 3** *(blocked on Wave 2)*
- [x] 20-04-PLAN.md — DI + compiler pass: MailerTransportContractPass + TenancyBundle configure/loadExtension/build + services.php registrations
**Wave 4** *(blocked on Wave 3)*
- [x] 20-05-PLAN.md — TenantContextClearedListener + 100-tenant long-running worker simulation test
- [x] 20-06-PLAN.md — AsyncCanaryTest: sync + async dispatch correctness (the headline differentiator)
**Wave 5** *(blocked on Wave 4)*
- [x] 20-07-PLAN.md — Profiler mailer subsection (D-08) — TenantDataCollector + tenant.html.twig
**Wave 6** *(blocked on Wave 5)*
- [x] 20-08-PLAN.md — tenancy:install --with-mailer (D-09) — MailerSetupStep + AST entity edit + migration scaffold

### Phase 21: Demo App

**Goal:** A new user runs `git clone … && cd examples/saas && docker compose up` and within 2 minutes can hit two tenant subdomains in a browser (or curl) and see isolated tenant data. The same script runs in CI on every push to `master` and blocks the merge on failure.

**Requirements:** DEMO-01

**Research needed (limited):** Document the `caddy trust` UX (internal CA acceptance step for browsers); confirm `*.tenancy.localhost` resolves correctly on macOS Chrome + Safari, Linux Firefox + Chromium, Windows WSL2 + Chrome; design the per-tenant fixtures pattern (Doctrine fixtures? raw SQL on container init?); finalize the CI smoke script using `Host:` header.

**Success criteria:**
1. `docker compose up` on a fresh clone (macOS Chrome or Linux Chromium) brings up the demo and `https://tenant1.tenancy.localhost` + `https://tenant2.tenancy.localhost` serve clearly distinct content
2. `bin/smoke.sh` (in the demo directory) makes `curl -H "Host: tenant1.tenancy.localhost"` + `Host: tenant2.tenancy.localhost` requests against `localhost:443` and verifies isolation — DNS-independent, works in CI
3. README's three-step fallback ladder (curl with `Host:` → `/etc/hosts` → browser-native `*.localhost`) is documented with copy-paste snippets
4. `.github/workflows/demo-smoke.yml` runs `bin/smoke.sh` on every push to `master`; smoke failure blocks merge
5. The demo's `composer.json` references the bundle via path repository so a developer can modify bundle source and rebuild the demo container to see changes immediately

### Phase 22: Docs Refresh

**Goal:** Docs match what v0.3 actually shipped. Install page mentions only `tenancy:install`. New user-guide pages for resolver/profiler/mailer/demo. Public roadmap page on the docs site. UPGRADE 0.2→0.3 explains the only BC break (the Mailer trait migration).

**Requirements:** DOC-19

**Success criteria:**
1. `docs/user-guide/installation.md` says "run `bin/console tenancy:install`" — zero references to manually editing `bundles.php` on the install path
2. New pages exist and are linked from `docs/index.md` nav: `user-guide/origin-header-resolver.md` (with Trust Model section), `user-guide/profiler-tab.md` (with screenshots from Phase 19/21), `user-guide/mailer-bootstrapper.md` (with X-Transport strategy + async failure-mode warning + migration recipe)
3. `docs/examples/saas-demo.md` walks through the Phase 21 demo end-to-end
4. `docs/roadmap.md` mirrors repo-root `ROADMAP.md`; both linked from `docs/index.md` + `README.md`
5. `UPGRADE.md` 0.2 → 0.3 section explains `TenantInterface::getMailerDsn()` BC break and the `TenantMailerConfigTrait` mitigation
6. `scripts/docs-lint.sh` extended with a check that fails on any `bundles.php` install-path reference outside the UPGRADE / Migration sections

> Full v0.3 phase-summary table and dependency notes live in `.planning/milestones/v0.3-ROADMAP.md`.

### 📋 Later Milestones

| Milestone | Theme | Key items |
|-----------|-------|-----------|
| v0.4 | Storage & Shared Entities | BOOT-03, SHARE-01/02/03, DX-03 PHPStan extension |
| v0.5 | Operations & Scale | OPS-01, OPS-02, ISOL-07 parallel migrations |
| v0.6 | Advanced Isolation | ISOL-06 PostgreSQL RLS (demand-gated; v1.0 candidate) |

### Future / By Demand

User-requestable but unscheduled. See `.planning/PROJECT.md#future--by-demand` for the canonical list and `ROADMAP.md` (repo root) for the public-facing version.

## Progress

| Milestone | Phases | Plans | Status      | Shipped    |
| --------- | ------ | ----- | ----------- | ---------- |
| v0.2      | 1–15   | 48/48 | Complete    | 2026-04-20 |
| v0.3      | 16–22  | 5/?   | In Progress | —          |

*v0.3: Phase 16 skipped (non-functional gate). Phase 17 complete (5 plans). Phases 18–22 not yet planned — plan counts derived once `/gsd-plan-phase` runs for each.*
