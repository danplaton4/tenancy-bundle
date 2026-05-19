# Requirements: Symfony Tenancy Bundle — v0.3 Adoption Surface

**Defined:** 2026-05-15
**Milestone:** v0.3 Adoption Surface
**Goal:** Lower install friction and ship the highest-leverage missing features. Turn the Packagist page into a successful first install. v0.2 shipped to two self-installs / zero external dependents; v0.3 attacks the install funnel.

For prior-milestone (v0.2) requirements, see `.planning/milestones/v0.2-REQUIREMENTS.md`.

## v0.3 Requirements

### Adoption / Onboarding

- [x] **DX-06**: `bin/console tenancy:install` performs a one-command bundle setup — auto-registers `TenancyBundle::class` in `config/bundles.php`, invokes `tenancy:init` programmatically (forwarding `--force`), and prints next-step guidance. The user runs `composer require` then `tenancy:install` and the bundle is ready; no manual `bundles.php` editing.
  - Acceptance: idempotent on re-run (detects bundle already present, exits 0)
  - Acceptance: `--dry-run` mode prints the proposed mutation without writing
  - Acceptance: detects `bundles.php` via `nikic/php-parser` AST walk (`require-dev`, lazy-loaded)
  - Acceptance: refuses to mutate non-standard `bundles.php` shapes (DDD `registerBundles()` overrides, env-conditional loading) — prints a clean manual snippet, exits 0 (not a failure)
  - Acceptance: atomic write via `Filesystem::dumpFile()`, timestamped `.bak`, `php -l` post-mutation, automatic restore on parse failure
  - Acceptance: fixture corpus quality gate — passes on ≥6 distinct `bundles.php` shapes (standard skeleton, API Platform, Sulu, DDD-override, with-comments, with-env-conditionals)
- [ ] **DEMO-01**: `examples/saas/` ships a runnable two-tenant Symfony app — `docker compose up` → two tenant subdomains resolve and serve isolated content out of the box. Doubles as a CI smoke test that gates `master` merges.
  - Acceptance: FrankenPHP + Caddy + MariaDB 11 composition, single `docker compose up`
  - Acceptance: `*.tenancy.localhost` subdomain routing via Caddy + internal CA (no `/etc/hosts` edits required on Chrome/macOS/Linux)
  - Acceptance: README documents a three-step fallback ladder for Firefox/Safari/WSL2: curl with `Host:` header → `/etc/hosts` line → browser-native `*.localhost`
  - Acceptance: `bin/smoke.sh` exercises both tenants via `Host:` header (DNS-independent); fixtures seed two tenants on boot
  - Acceptance: `.github/workflows/demo-smoke.yml` runs the smoke script on every push to `master`; failure blocks merge
  - Acceptance: demo lives at its own Composer root with a path repository pointing back to the bundle; bundle source changes reflect on demo rebuild

### Developer Experience

- [x] **DX-02**: Symfony Profiler ships a "Tenancy" panel in the Web Debug Toolbar showing the active tenant for the current request — slug, ID, driver, connection name, resolved-by FQCN, bootstrappers run. Panel renders cleanly in three states (resolved tenant, null-resolution, error during resolution).
  - Acceptance: `TenantDataCollector` extends `AbstractDataCollector`; service registered only when `kernel.debug = true`
  - Acceptance: `collect()` (NOT `lateCollect()`) reads scalars only from `TenantContext` on `kernel.response`; `$this->data` contains no entity references, no closures, no DSN strings with credentials
  - Acceptance: `resolved_by` plumbing via a `TenantResolved` event subscriber that stashes the resolver FQCN — keeps `TenantContext` zero-dependency contract intact
  - Acceptance: stored-profile reload test — serializes and rehydrates a profile dump without error
  - Acceptance: WDT icon and panel render correctly for null-resolution requests (public/landlord/health-check routes)
  - Acceptance: dev-only — compile-out of the prod container verified by a CI check

### Bootstrappers

- [ ] **BOOT-04**: Per-tenant Mailer bootstrapper sends each tenant's mail from the tenant's own SMTP transport, with the tenant's `From`/`Reply-To` headers, correctly in BOTH synchronous and asynchronous (Messenger-routed) Mailer dispatch.
  - Acceptance: `MailerBootstrapper` implements `TenantBootstrapperInterface`; optional dep on `symfony/mailer` guarded by `interface_exists(\Symfony\Component\Mailer\MailerInterface::class)`
  - Acceptance: `X-Transport` header strategy — `TenantMessageDecorator` listens on `MessageEvent` and stamps `X-Transport: tenant_<slug>` BEFORE Messenger serialization; multi-transport mailer config routes envelopes to per-tenant `tenant_<slug>` transports
  - Acceptance: `TenantMailerConfigTrait` ships as the default implementation for `getMailerDsn(): ?string`; `Tenant` entity gains a `mailerDsn` nullable string column; landlord migration recipe documented
  - Acceptance: `TenantInterface` adds `getMailerDsn(): ?string` (BC break — UPGRADE 0.2→0.3 documents the trait migration path)
  - Acceptance: `MailerTransportContractPass` compile-time guard rejects "mailer bootstrapper enabled + no transport strategy"; if Mailer is routed async via Messenger, requires `x_transport` strategy specifically
  - Acceptance: DSN credentials never appear in exception traces or logs — sanitization wrapper redacts password component
  - Acceptance: async canary test — dispatch in tenant A's HTTP context, run worker in clean context, assert the recorded SMTP DSN matches tenant A (not landlord)
  - Acceptance: transport cache cleared on `TenantContextCleared` event to prevent SMTP socket leaks in long-running workers

### Resolvers

- [ ] **RESV-06**: `OriginHeaderResolver` resolves the active tenant from the `Origin` HTTP header — SPA-friendly alternative to `X-Tenant-ID`. Registered in the resolver chain at priority 25 (above `HeaderResolver`/20, below `HostResolver`/30): when both `Origin` and `X-Tenant-ID` are present, `Origin` wins because it is browser-locked for cross-origin XHR.
  - Acceptance: implements `TenantResolverInterface`; tagged `tenancy.resolver` priority 25; mirrors the shape of existing `HeaderResolver`
  - Acceptance: parsed-URL exact-equality matching (scheme + host + port); allow-list entries with at most one left-most wildcard label (`*.app.example.com` allowed, mid-string wildcards rejected)
  - Acceptance: returns `null` on absent `Origin` header (falls through resolver chain)
  - Acceptance: returns `null` on CORS preflight (`OPTIONS`) requests — preflight must not throw
  - Acceptance: warning log when `Origin` and `X-Tenant-ID` resolve to different tenants in the same request
  - Acceptance: `OriginHeaderResolverConfigPass` compile-time guard rejects empty allow-lists and unparseable URLs
  - Acceptance: dedicated "Trust Model" docs section explaining `Origin` is browser-protected cross-origin but spoofable from non-browser clients

### Documentation

- [ ] **DOC-19**: Documentation reflects everything v0.3 ships. Install page replaces "manually add to `bundles.php`" with `tenancy:install`; new pages for `OriginHeaderResolver`, Profiler tab, and Mailer bootstrapper; demo walkthrough; public roadmap page on the docs site mirroring `ROADMAP.md` at the repo root.
  - Acceptance: install page updated — single command, no manual `bundles.php` step
  - Acceptance: new `user-guide/origin-header-resolver.md` with trust-model section
  - Acceptance: new `user-guide/profiler-tab.md` with screenshots
  - Acceptance: new `user-guide/mailer-bootstrapper.md` with `X-Transport` strategy explained, async failure-mode warning, migration recipe for existing Tenant entities
  - Acceptance: new `examples/saas-demo.md` walkthrough referencing the `examples/saas/` app
  - Acceptance: new `roadmap.md` page mirroring repo-root `ROADMAP.md`, linked from index + README
  - Acceptance: `UPGRADE.md` 0.2 → 0.3 section explaining the `TenantInterface::getMailerDsn()` BC break and the trait-based mitigation path
  - Acceptance: `scripts/docs-lint.sh` extended to catch references to the old "edit `bundles.php`" install path

### Governance (Tooling Carry-Forward)

- ⊘ **GOV-01 (SKIPPED — non-functional)**: GSD tooling enforces v0.2 retrospective lessons that previously surfaced only at milestone close — plan↔summary parity and `human_needed` verification-status TTL.
  - **Decision (2026-05-15):** Skipped. Non-functional process gate; zero bundle-user value. Retrospective items #1 and #2 are acknowledged as known gaps and surface to humans via `RETROSPECTIVE.md`; we accept the risk of recurrence rather than maintain a parallel audit tool or patch a third-party SDK we don't own.
  - Original acceptance criteria (kept for historical reference):
    - ~~`gsd-sdk query audit-open` extended to fail when any phase has a `PLAN.md` without a matching `SUMMARY.md`~~
    - ~~`gsd-sdk query audit-open` extended to fail when any `VERIFICATION.md` has carried `human_needed` status for more than 72 hours~~
    - ~~not bundle source — tooling-only; lives under `.claude/get-shit-done/` or equivalent GSD location~~
    - ~~documented in `RETROSPECTIVE.md` carry-forward section as resolved~~

## Future Requirements

Deferred to v0.4–v0.6 per the [Later Milestones](.planning/PROJECT.md#later-milestones-planned-scope-subject-to-v03-telemetry) plan in `PROJECT.md`. The full list and rationale is canonical in `PROJECT.md`; this section is a pointer, not a duplicate.

- **v0.4 Storage & Shared Entities:** BOOT-03 Filesystem bootstrapper, SHARE-01/02/03 shared entities, DX-03 PHPStan extension
- **v0.5 Operations & Scale:** OPS-01 maintenance mode, OPS-02 health checks, ISOL-07 parallel migrations
- **v0.6 Advanced Isolation (demand-gated):** ISOL-06 PostgreSQL RLS

## Out of Scope

Out of scope for v0.3 with reasoning. Some items are deferred to later milestones (see Future Requirements); others are user-requestable via GitHub issues (see [Future / By Demand](.planning/PROJECT.md#future--by-demand) in `PROJECT.md`).

| Item | Reason |
|------|--------|
| **Symfony Flex recipe / `symfony/recipes-contrib` submission** | Adopt when install volume justifies the contrib-submission maintenance cost. `tenancy:install` is the v0.3 onboarding path. |
| **v1.0 tag** | Requires external adoption signals (more than zero dependents) to validate the public surface. Deferred until at least v0.6, possibly later. |
| **Mailpit container in the demo by default** | Adds container weight for a feature most users won't need on first run. Document as optional add-on in the Mailer guide instead. |
| **APM / observability integration in demo** | Out of scope for adoption-funnel milestone; revisit during v0.5 Operations & Scale. |
| **PHPStan extension for `#[TenantAware]`** | Moved to v0.4 (DX-03) — pairs naturally with SHARE-* work where `#[TenantAware]` misuse risk increases. |
| **Filesystem bootstrapper (per-tenant uploads)** | Moved to v0.4 (BOOT-03) — depends on Flysystem optional dep wiring; not on critical path for install funnel. |

## Traceability

Filled by roadmap step. Each requirement maps to exactly one phase.

| Requirement | Phase | Status |
|-------------|-------|--------|
| GOV-01 | Phase 16 — Governance Carry-Forward | ⊘ Skipped (non-functional, 2026-05-15) |
| RESV-06 | Phase 17 — OriginHeaderResolver | Pending |
| DX-06 | Phase 18 — tenancy:install | Complete |
| DX-02 | Phase 19 — Profiler Tab | Complete |
| BOOT-04 | Phase 20 — Mailer Bootstrapper | Pending |
| DEMO-01 | Phase 21 — Demo App | Pending |
| DOC-19 | Phase 22 — Docs Refresh | Pending |

**Coverage:**
- v0.3 requirements: 7 total (1 skipped non-functional)
- Active: 6, mapped to phases 17–22 (100%)
- Skipped: 1 (GOV-01 — see decision note)
- Unmapped: 0

## Architectural Decisions (Ratified)

The 8 cross-dimension decisions surfaced in `.planning/research/SUMMARY.md` are ratified as follows. Plan-phase agents must respect these without re-litigating.

| ID | Decision | Ratified |
|----|----------|----------|
| **DEC-MAIL-01** | Mailer extension point | `X-Transport` header strategy + `MessageEvent` listener for `From`/`Reply-To` |
| **DEC-MAIL-02** | Per-tenant SMTP config storage | `mailerDsn` nullable column on `Tenant` (full BOOT-04 scope in v0.3) |
| **DEC-MAIL-03** | `getMailerDsn()` on `TenantInterface` | Yes (BC break, mitigated by `TenantMailerConfigTrait`) |
| **DEC-RESV-01** | `OriginHeaderResolver` priority | 25 (between `HostResolver` 30 and `HeaderResolver` 20) |
| **DEC-PROF-01** | Profiler resolved-by plumbing | Collector subscribes to `TenantResolved` event |
| **DEC-INST-01** | `tenancy:install` invokes `tenancy:init` | Programmatically, forwards `--force` |
| **DEC-INST-02** | `bundles.php` non-standard handling | `nikic/php-parser` detect, refuse to mutate, print manual snippet, exit 0 |
| **DEC-DEMO-01** | Demo subdomain routing | Caddy + `*.tenancy.localhost` + internal CA + three-step fallback ladder |

---

*Requirements defined: 2026-05-15*
*Milestone: v0.3 Adoption Surface*
*Decisions ratified inline; ready for roadmap*
