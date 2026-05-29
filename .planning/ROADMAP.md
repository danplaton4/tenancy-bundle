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

### 📋 v0.3 Adoption Surface — Phases 17–23 (7 active)

Goal: lower install friction + ship the highest-leverage missing features. 7 active phases, 6 active requirements + 1 closure phase. Phase 16 / GOV-01 skipped as a non-functional gate (see below). See `.planning/REQUIREMENTS.md` for full acceptance criteria and `.planning/research/SUMMARY.md` for the research synthesis.

- ⊘ **Phase 16: Governance Carry-Forward** — **SKIPPED.** Non-functional process tooling (`audit-open` extension). Retrospective items #1 (plan↔summary parity) and #2 (`human_needed` 72h TTL) acknowledged as gaps in `RETROSPECTIVE.md` but intentionally not enforced via tooling. Bundle-user value is zero; the v0.2 retrospective surfaces the lessons humans need without machine enforcement. Phase number retained for stable references; downstream phase numbers (17–22) unchanged.
- [x] **Phase 17: OriginHeaderResolver** — SPA-friendly resolver at priority 25, allow-list config, `OriginHeaderResolverConfigPass` guard (RESV-06) (completed 2026-05-15)
- [x] **Phase 18: tenancy:install** — single-command setup (auto-registers bundle, runs `tenancy:init`), `nikic/php-parser` detection, ≥6 fixture corpus, atomic write + .bak (DX-06). Originally completed 2026-05-18; gap reopened 2026-05-21 after human UAT exposed zero-config boot regression at 6 `nullOnInvalid()` consumer sites with non-nullable signatures; gap-closure plans 18-08…18-11 + follow-up fixes (CR-01/WR-01/WR-04) landed 2026-05-21. Shipped in v0.3.0 on 2026-05-22.
- [x] **Phase 19: Profiler Tab** — `TenantDataCollector` + Twig template, dev-only, three render states (resolved/null/error), stored-profile reload tested (DX-02) (completed 2026-05-19)
- [x] **Phase 20: Mailer Bootstrapper** — `X-Transport` strategy (sync + async safe), `TenantInterface` BC break + trait migration, `MailerTransportContractPass` guard, async canary test (BOOT-04) (completed 2026-05-20)
- [x] **Phase 21: Demo App** — `examples/saas/` with FrankenPHP + Caddy + MariaDB, three-step fallback ladder, `bin/smoke.sh` CI release-gate (DEMO-01) — *4/4 plans complete; verification gaps_found (CR-02 smoke gate)* (completed 2026-05-22)
- [x] **Phase 22: Docs Refresh** — install page rewrite, new pages (resolver/profiler/mailer/demo/roadmap), UPGRADE 0.2→0.3, docs-lint extended (DOC-19) (completed 2026-05-28; 4 human_needed items in 22-HUMAN-UAT.md — mkdocs --strict in CI, ASCII visual check, post-publish URL, cross-tree link rendering)
- [ ] **Phase 23: v0.3 tech-debt closure** — close the accumulated tech-debt surfaced by `v0.3-MILESTONE-AUDIT.md` before tagging v0.3.3: INT-01 Profiler/Mailer Twig contract drift, CR-01 nullable-provider drift guard, WR-01 LogicException for misconfiguration, IN-01..IN-05 ZeroConfigKernelBootTest cleanup, smoke.sh mailer assertion, CHANGELOG Unreleased→0.3.2/0.3.3 promotion.

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

**Plans:** 5/5 plans complete

Plans:
**Wave 1**

- [x] 21-01-PLAN.md — Demo Symfony 7.4 skeleton (composer.json path-repo + config/bundles.php + tenancy/doctrine/mailer/web_profiler.yaml)
- [x] 21-02-PLAN.md — Demo PHP source (DemoTenant + Post entities, four controllers, LandlordTenantsFixture, SeedDemoCommand replacing D-05 `--create-dbs`, Twig templates)

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 21-03-PLAN.md — Container stack (compose.yaml + Dockerfile + Caddyfile + entrypoint.sh + php.ini; seed-before-serve per Pitfall 5)

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 21-04-PLAN.md — bin/smoke.sh + demo-smoke.yml CI gate + examples/saas/README.md three-step fallback + root README pointer (human walkthrough checkpoint)

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

**Plans:** 6 plans

Plans:
**Wave 1** *(parallel — no inter-dependencies)*

- [x] 22-01-PLAN.md — composer.json nikic suggest→require + installation.md one-command + index.md Quick Start (D-09/D-10/D-11/D-12)
- [x] 22-02-PLAN.md — UPGRADE.md new 0.3.2→0.3.3 section + 0.2→0.3 wording polish (D-13, SC5)
- [x] 22-03-PLAN.md — Profiler-tab 3-state ASCII renders + new mailer-bootstrapper.md page (D-01/D-02/D-14)
- [x] 22-04-PLAN.md — examples/ reorg + new saas-demo.md thin walkthrough + 4 cross-link updates (D-06/D-07/D-08)
- [x] 22-05-PLAN.md — Yellow page refresh: getting-started/configuration/resolvers/cli-commands surgical additions (D-17/D-18/D-19/D-20)

**Wave 2** *(blocked on Wave 1 — final assembly)*

- [x] 22-06-PLAN.md — roadmap mirror (canonical→docs) + mkdocs.yml nav reorg + docs-lint awk extension + integration smoke (D-03/D-04/D-05/D-15/D-16, SC4/SC6)

### Phase 23: v0.3 Tech-Debt Closure

**Goal:** Close the accumulated tech-debt surfaced by `.planning/v0.3-MILESTONE-AUDIT.md` (2026-05-29) before tagging v0.3.3. Driven by the audit findings, not by a new requirement.

**Requirements:** none new — closure items map to v0.3 REQ-IDs (DX-02, BOOT-04, DX-06, DEMO-01, DOC-19) that are already SATISFIED in VERIFICATION but carry deferred polish.

**Scope (audit-driven):**

1. **INT-01 (Twig contract drift)** — Move `{% if collector.data.mailer is defined %}` block in `src/Resources/views/Collector/tenant.html.twig` out of the resolved-only branch so mailer cache metrics are visible on null/error states (where `TenantDataCollector::collectMailerState()` already populates the data). Re-assert `TenantDataCollectorMailerSectionTest::testMailerKeyPresentWhenNoTenantButCacheWired` against the rendered template, not just the data array.
2. **CR-01 (nullable-provider drift guard)** — Make all 6 sites consistent (`?TenantProviderInterface $tenantProvider = null` everywhere) AND add a contract test or PHPStan rule that pins the invariant. Builds on existing `NullableProviderInjectionContractTest`.
3. **WR-01 (Messenger retry semantics)** — Introduce `Tenancy\Bundle\Exception\MissingTenantProviderException extends \LogicException`; swap `\RuntimeException` for it in `TenantRunCommand` and `TenantWorkerMiddleware` so Messenger treats it as a permanent config error, not a transient failure.
4. **WR-02..WR-04 (intra-bundle consistency nits)** — `ConsoleResolver` guard-ordering docblock at the Application-mutation site; `QueryParamResolver` empty-string check pattern-aligned with `ConsoleResolver`; `TenantRunCommand` shell-injection trust-boundary docblock at `Process::fromShellCommandline` site.
5. **IN-01..IN-05 (ZeroConfigKernelBootTest cosmetics)** — Drop stale `@group canary-red` + class-docblock framing; remove double-removal in `tearDownAfterClass`; add PID to cache-dir hash to prevent parallel-PHPUnit race; add explicit `use TenantStamp` in `TenantWorkerMiddleware`.
6. **Smoke.sh mailer assertion** — Extend `examples/saas/bin/smoke.sh` to POST `/_demo/send-test-mail` for two tenants and query Mailpit's `/api/v1/messages` endpoint to assert per-tenant `From:` isolation.
7. **CHANGELOG promotion** — Promote `## [Unreleased]` entries into versioned `## [0.3.2]` (AbstractTenant split + demo fixes) and `## [0.3.3]` (nikic require move) sections; UPGRADE.md sections already exist.
8. **REQUIREMENTS.md checkbox refresh** — Flip RESV-06, DEMO-01, DOC-19 from `[ ]` to `[x]` to reflect shipped status (cosmetic, complete-milestone workflow handles in archival, doing it inline here for cleanliness).

**Success criteria:**

1. Mailer subsection in `tenant.html.twig` renders on all 3 panel states when LruTransportCache is wired; `TenantDataCollectorMailerSectionTest` updated to assert rendered HTML, not just data.
2. All 6 nullable-provider sites use identical signature (`?TenantProviderInterface $tenantProvider = null`); contract test fails if any one drops the `?` or omits the default.
3. `MissingTenantProviderException` exists, extends `\LogicException`, is thrown by both fail-loud sites; Messenger middleware test asserts no-retry behavior.
4. `bin/smoke.sh` exits non-zero when per-tenant mailer isolation is broken (verified by a deliberate-break test in a feature branch); `.github/workflows/demo-smoke.yml` runs the extended check.
5. CHANGELOG.md has dated 0.3.2 + 0.3.3 sections; Unreleased is empty (or contains only items not yet shipped).
6. Full PHPUnit suite + PHPStan level 9 + php-cs-fixer all green.

**Plans:** 7 plans

Plans:
**Wave 1** *(parallel — independent edits)*

- [ ] 23-01-PLAN.md — INT-01 Twig contract drift fix (mailer subsection hoisted out of resolved-only branch + rendered-HTML test)
- [x] 23-02-PLAN.md — WR-01 LogicException assertions at both throw sites (TenantWorkerMiddleware + TenantRunCommand). **Scope reduced via Option D 2026-05-29:** Task 1+2 (CR-01 `= null` default + contract-test strengthening) skipped because 31465dc had already closed CR-01 on 2026-05-21 in opposite direction (drop defaults, not add — 3 of 6 sites have `$tenantProvider` before required params, which PHP 8.0+ deprecates as optional-before-required). See 23-02-SUMMARY.md.
- [x] 23-03-PLAN.md — WR-02/03/04 intra-bundle consistency + IN-01..04 ZeroConfigKernelBootTest cosmetics. **IN-05 skipped 2026-05-29:** the project's php-cs-fixer @Symfony ruleset (via `no_unused_imports`) auto-strips same-namespace `use` statements, making the explicit `use TenantStamp` import the audit asked for unachievable. The "drift" IN-05 flagged is policed in the opposite direction by enforced cs-fixer config. See 23-03-SUMMARY.md.

**Wave 2** *(depends on Wave 1)*

- [ ] 23-04-PLAN.md — smoke.sh per-tenant mailer-isolation section (Mailpit /api/v1/messages jq assertion)
- [ ] 23-05-PLAN.md — CHANGELOG promotion (Unreleased → 0.3.2 + 0.3.3 sections)
- [ ] 23-06-PLAN.md — REQUIREMENTS.md checkbox refresh (RESV-06 / DEMO-01 / DOC-19 → [x])

**Wave 3** *(depends on Wave 2 — final green-bar verification + optional live-stack smoke)*

- [ ] 23-07-PLAN.md — Full PHPUnit + PHPStan + cs-fixer + docs-lint green-bar + optional live `docker compose up` smoke

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
| v0.3      | 16–23  | 46/?  | In Progress | —          |

*v0.3: Phase 16 skipped (non-functional gate). Phases 17–22 complete (46 plans shipped). Phase 23 added 2026-05-29 — audit-driven tech-debt closure before tagging v0.3.3; 3 of 7 plans complete (23-01 INT-01 Twig hoist, 23-02 WR-01 LogicException tests, 23-03 WR-02/03/04 + IN-01..04 with IN-05 skipped per cs-fixer policy).*
