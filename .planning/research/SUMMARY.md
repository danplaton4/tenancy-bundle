# v0.3 Adoption Surface — Research Summary

**Project:** `danplaton4/tenancy-bundle` v0.3 (Adoption Surface milestone)
**Domain:** Symfony reusable bundle — adoption-funnel features layered on a published v0.2 engine
**Researched:** 2026-05-15
**Confidence:** HIGH overall (single MEDIUM call-out on cross-OS demo DNS behavior, verified via vendor bug trackers rather than empirical reproduction)

Downstream readers (REQUIREMENTS.md author, gsd-roadmapper) — this file is self-sufficient. The four dimension files (`STACK.md`, `FEATURES.md`, `ARCHITECTURE.md`, `PITFALLS.md`) contain the long-form rationale; everything load-bearing for requirements and phase planning is here.

---

## Executive Summary

v0.3 is **scope-locked to six features plus one governance carry-forward**. The engine shipped in v0.2 (resolvers, bootstrappers, MessengerStamp, drivers, CLI, test trait) is unchanged contractually — v0.3 is additive on existing seams. Net new production dependencies: **zero**. Three additions to `require-dev` only: `symfony/mailer`, `symfony/twig-bundle`, `symfony/web-profiler-bundle`. The adoption thesis is "v0.2 works for people who already know they want it; v0.3 closes the install funnel so people who *should* want it can find out in five minutes."

The single highest-risk decision is the **Mailer bootstrapper extension point**. Three plausible Symfony hooks exist (`MailerInterface` decoration, `MessageEvent` listener, custom `TransportFactoryInterface`); only the `X-Transport` header strategy paired with multi-transport mailer config is safe under async Messenger dispatch — every other approach has a worker-process race that silently sends tenant emails from the landlord SMTP. The second-highest-risk decision is **`bundles.php` mutation**: the file is user-owned, often modified, and a corruption incident is a v1.0.0-retraction-class event. The Pitfalls and Architecture dimensions disagreed on technique; the recommended synthesis is "detect via `nikic/php-parser`, write via Flex string-template, abort with a manual snippet on any non-standard shape" — gated by a fixture corpus of at least six real-project `bundles.php` shapes.

The governance carry-forward from the v0.2 retrospective (plan↔summary parity check, `human_needed` TTL) is **not bundle code** but **prerequisite phase work**. v0.2 close uncovered four retroactively-authored summaries and three unresolved `human_needed` items (7–42 days latent). v0.3 features are the user-facing surface; shipping them with the same process defects would compound the risk. Phase 0 (governance) must land before any v0.3 feature phase opens. Build order: **GOV → RESV-06 → DX-06 → DX-02 → BOOT-04 → DEMO-01 → DOC-19**.

---

## Key Findings

### Recommended Stack

Zero changes to v0.2 `require`. Three additions to `require-dev` plus two `suggest` entries. No AST parser ships in `require`. No Docker library in bundle composer.json. No Symfony Flex recipe (explicit non-goal per PROJECT.md).

**Diff against v0.2 `composer.json`:**

```diff
 "require-dev": {
+    "symfony/mailer": "^7.4||^8.0",
+    "symfony/twig-bundle": "^7.4||^8.0",
+    "symfony/web-profiler-bundle": "^7.4||^8.0"
 },
 "suggest": {
+    "symfony/mailer": "Required for per-tenant SMTP transport and From-header bootstrapping",
+    "symfony/web-profiler-bundle": "Required for the Tenancy WDT panel (dev-only)"
 }
```

**Why this composition (not its alternatives):**

| Choice | Rejected alternative | Why |
|---|---|---|
| String-template write for `bundles.php` (Flex `BundlesConfigurator` pattern) | `symfony/var-exporter` | Can't emit `::class` constants |
| `nikic/php-parser` for *detection only*, not for write | `nikic/php-parser` end-to-end | Heavy dep for write-path that Flex already proved with strings |
| `symfony/mailer` as `require-dev` + `suggest` (optional) | Hard `require` | Mailer is not required to run the bundle; same guard pattern as `symfony/messenger` in v0.2 |
| `symfony/twig-bundle` in `require-dev` only | In `require` | Apps without the profiler shouldn't pay for Twig in prod |
| FrankenPHP + Caddy in demo | PHP-FPM + nginx; Symfony CLI; Bitnami | One service, native `*.localhost` TLS, official Symfony skeleton |
| MariaDB in demo | Postgres | DBAL 4 supports both; MariaDB image ~120MB vs ~400MB, faster cold start, more typical of Symfony app `.env` examples |
| Demo as separate Composer root (path repository) | Demo under `src/` | Bundle and demo are different autoloader scopes; path-repo symlink reflects working tree changes |

**Version matrix:** All v0.3 additions are compatible with the existing `^7.4||^8.0` Symfony constraint and `^8.2` PHP floor. CI matrix unchanged (PHP 8.2/8.3/8.4 × Symfony 7.4/8.0).

### v0.3 Feature Set (Locked)

The six bundle features below are user-conversation-locked; do not re-debate scope.

| ID | Feature | Adoption lever | Complexity |
|----|---------|---------------|------------|
| **DX-06** | `tenancy:install` setup command | Removes "edit `config/bundles.php` by hand" step | S-M |
| **DEMO-01** | Demo app in `examples/` | One-command reference (`docker compose up`) doubles as CI smoke test | M |
| **DX-02** | Symfony Profiler "Tenancy" tab | Closes debuggability gap; competitive whitespace | S-M |
| **BOOT-04** | Mailer bootstrapper (per-tenant SMTP) | Closes #1 SaaS use case (transactional email) | M-L |
| **RESV-06** | `OriginHeaderResolver` | Closes SPA + cross-origin API gap; parity with stancl/tenancy v4 | S |
| **DOC-19** | Docs refresh + public `ROADMAP.md` | Necessary to advertise everything above | M |

Plus one **governance carry-forward** (not bundle code):

- **GOV** — plan↔summary parity check added to `audit-open` GSD tooling; `human_needed` 72-hour TTL.

**Out of scope for v0.3 (deferred to v0.4+):** Filesystem bootstrapper (BOOT-03), PHPStan extension (DX-03), demo's Mailpit container by default, APM integration, Symfony Flex recipe.

### Architecture Approach

v0.3 adds new code at five well-trodden Symfony extension points; no new compiler-pass classes are required for service wiring beyond three optional **contract-enforcement passes** described under "Compile-Time Guards" below. The existing `BootstrapperChainPass`, `ResolverChainPass`, and `MessengerMiddlewarePass` pick up the new tagged services automatically.

**Component responsibilities:**

| Component | Where it lives | Tag |
|-----------|---------------|-----|
| `TenancyInstallCommand` | `src/Command/` | `console.command` |
| `OriginHeaderResolver` | `src/Resolver/` | `tenancy.resolver`, priority **25** (between Host 30 and Header 20) |
| `TenantDataCollector` (+ Twig template) | `src/Profiler/`, `src/Resources/views/Collector/` | `data_collector`, **dev-only via `kernel.debug` guard** |
| `MailerBootstrapper` + `TenantMessageDecorator` listener | `src/Mailer/` | `tenancy.bootstrapper` priority -20; `kernel.event_listener` on `MessageEvent` |
| `examples/saas/` demo app | Repo root, NOT under `src/` | Path-repo Composer reference back to bundle |

**Lifecycle correctness — critical timing notes:**

- **Profiler `collect()` runs on `kernel.response`**, before `kernel.terminate`. At this point `TenantContext` is still populated. Do NOT use `lateCollect()` — that fires during `kernel.terminate` and races the orchestrator's clear, producing blank-panel-on-resolved-tenant bugs. Subscribe to `TenantResolved` to stash `resolvedBy` early (per **DEC-PROF-01**); read scalars from `TenantContext` in `collect()`.
- **Mailer transport selection happens at `send()` time on the worker**, not at HTTP-request `TenantResolved` time. This is what makes the async path treacherous.
- **Bootstrapper `clear()` runs in reverse boot order** (unchanged from v0.2). The mailer bootstrapper's per-tenant transport cache MUST clear on `TenantContextCleared` to avoid SMTP socket leaks in long-running workers.

### Critical Pitfalls (Top 5)

1. **Mailer transport overridden at dispatch instead of send → async emails go to wrong tenant.** Naive "override transport on TenantResolved" passes sync tests, ships, sends tenant-A welcome emails from the landlord SMTP. Customer-visible incident; DKIM/SPF reputation damage. **Prevention:** `X-Transport: tenant_<slug>` header stamped at dispatch (survives serialization) + multi-transport mailer config. Compile-time guard: **`MailerTransportContractPass`**. Async canary test (dispatch in tenant A, run worker, assert SMTP DSN matches tenant A) is a phase quality gate.

2. **`tenancy:install` corrupts a user's `config/bundles.php`.** A first user whose `bundles.php` is corrupted will `composer remove` and never come back — irrecoverable adoption loss. **Prevention:** detect-via-`nikic/php-parser` (read-only inspection), write-via-string-template (Flex pattern), **abort with a clean manual snippet on any non-standard shape** (`--dry-run` mode, atomic write, `.bak`, `php -l` post-mutation). Phase quality gate: fixture corpus of **at least six** distinct `bundles.php` shapes; 3-run idempotency test.

3. **Profiler tab serializes non-serializable state or shows blank for resolved tenants.** **Prevention:** subscribe to `TenantResolved` to stash `resolvedBy` early; `collect()` (NOT `lateCollect()`) reads scalars only; store only scalar/array data. Stored-profile reload test is the canary. Production compile-out via `kernel.debug` guard.

4. **Demo's `*.localhost` subdomain routing works in Chrome and nowhere else.** Firefox historically does not resolve `*.localhost`; Safari has long-standing subdomain-of-localhost issues. **Prevention:** README provides a **three-step fallback ladder** — (1) curl/HTTPie with `Host:` header, (2) `/etc/hosts` line, (3) browser-native `*.localhost`. CI smoke job uses `Host:` header, not real DNS. Demo CI smoke is a **release-gate** for `master` merges.

5. **`OriginHeaderResolver` trusts a header settable from any non-browser client.** `Origin` is browser-protected for cross-origin XHR/fetch but **trivially spoofable from curl/Postman/mobile**. **Prevention:** parsed-URL exact-equality matching; compile-time guard `OriginHeaderResolverConfigPass`; explicit "trust model" docs section; preflight returns `null` to fall through chain.

### Compile-Time Guards to Ship in v0.3

| Pass | Scope | Mandatory? |
|------|-------|------------|
| **`MailerTransportContractPass`** | If Mailer bootstrapper is enabled, require transport strategy. If Mailer routed async via Messenger, require `x_transport` (only async-safe). | YES |
| **`OriginHeaderResolverConfigPass`** | If `OriginHeaderResolver` is registered, require non-empty allow-list of parseable absolute URLs; reject mid-string wildcards. | YES |
| **`ProfilerCollectorContractPass`** | If `WebProfilerBundle` is registered, verify collector has `data_collector` tag and is `public: false`. | OPTIONAL |

---

## Decisions Requiring Owner Sign-Off

| ID | Decision | Recommendation |
|----|----------|----------------|
| **DEC-MAIL-01** | Mailer extension point | `X-Transport` header strategy + tiny `MessageEvent` listener for `From`/`Reply-To` |
| **DEC-MAIL-02** | Where per-tenant SMTP config lives | New `mailerDsn` column on `Tenant` (requires migration) — OR defer per-tenant DSN to v0.4 |
| **DEC-MAIL-03** | Add `getMailerDsn(): ?string` to `TenantInterface` (BC break) | Yes, with `TenantMailerConfigTrait` to ease upgraders (cost at floor: ~0 external installs) |
| **DEC-RESV-01** | `OriginHeaderResolver` priority | 25 (above `HeaderResolver` 20) — OR 10 if owner prefers fail-safe-conservative |
| **DEC-PROF-01** | "Resolved-by" plumbing | Collector subscribes to `TenantResolved` event |
| **DEC-INST-01** | `tenancy:install` invokes `tenancy:init` programmatically vs. instructing user to run it | Invokes programmatically; forwards `--force` flag |
| **DEC-INST-02** | Behavior when `bundles.php` is non-standard | Detect via `nikic/php-parser`, refuse to mutate, print manual snippet, exit 0 |
| **DEC-DEMO-01** | Subdomain routing scheme in demo | Caddy + `*.tenancy.localhost` + internal CA, with three-step fallback ladder |

---

## Implications for Roadmap

### Suggested phase structure (build order)

Final ordering: **GOV → RESV-06 → DX-06 → DX-02 → BOOT-04 → DEMO-01 → DOC-19**.

#### Phase 0 — GOV (governance carry-forward)
Retro carry-forward items: plan↔summary parity check in `audit-open` GSD tooling; `human_needed` 72-hour TTL convention. Not bundle code. Single small phase.

#### Phase 1 — RESV-06 `OriginHeaderResolver`
Smallest, lowest-risk, no schema impact, no BC break. Validates v0.3 cadence. **Delivers:** `OriginHeaderResolver` + `OriginHeaderResolverConfigPass` + allow-list config schema + trust-model docs. **Lock during planning:** DEC-RESV-01 priority value.

#### Phase 2 — DX-06 `tenancy:install`
Unlocks the demo. **Delivers:** `TenancyInstallCommand` + `--dry-run` + fixture corpus tests (≥6 shapes) + atomic write + `.bak` + `nikic/php-parser` detection. **Lock during planning:** DEC-INST-01, DEC-INST-02.

#### Phase 3 — DX-02 Profiler tab
Depends only on `TenantContext` + `TenantResolved`. **Delivers:** `TenantDataCollector` + Twig template + (optional) `ProfilerCollectorContractPass` + dev-only via `kernel.debug` guard. **Lock during planning:** DEC-PROF-01.

#### Phase 4 — BOOT-04 Mailer bootstrapper
Largest feature; only BC break in v0.3; async canary mandatory. **Delivers:** `MailerBootstrapper` + `TenantMessageDecorator` + `X-Transport` strategy + `MailerTransportContractPass` + `mailerDsn` column on `Tenant` + `getMailerDsn()` on `TenantInterface` + `TenantMailerConfigTrait` + DSN sanitization wrapper. **Lock during planning:** DEC-MAIL-01, DEC-MAIL-02, DEC-MAIL-03.

#### Phase 5 — DEMO-01 Demo app
Last because it consumes the prior four. Doubles as v0.3 release-gate smoke. **Delivers:** `examples/saas/` skeleton + `docker-compose.yml` + Caddy config + MariaDB init + fixtures + `bin/smoke.sh` + demo CI workflow + README with three-step fallback ladder. **Lock during planning:** DEC-DEMO-01.

#### Phase 6 — DOC-19 Docs refresh + public ROADMAP.md
Last so docs match what shipped. **Delivers:** Updated install page; new pages for OriginHeaderResolver, Profiler tab, Mailer bootstrapper; demo walkthrough; public `ROADMAP.md` page on docs site.

### Phase ordering rationale

- **Governance first** — process defects compound; fix the GSD tooling before user-facing features layer onto the same workflow.
- **Smallest-risk feature second (RESV-06)** — proves the v0.3 cadence is real and ships SPA value even if later phases slip.
- **Install funnel before demo** — demo's README cannot have a "now edit `bundles.php`" step.
- **Profiler before mailer** — Profiler is mostly read-only; mailer has the only BC break in v0.3.
- **Mailer before demo** — demo screenshots showing per-tenant `From` headers are a stronger demo than "two tenants share a DB."
- **Demo last among features** — consolidates everything; doubles as release-gate smoke.
- **Docs absolutely last** — write docs against what shipped, not against what was planned.

### Research flags

| Phase | Needs deeper research during planning? | Why |
|-------|---------------------------------------|-----|
| Phase 0 (GOV) | **No** — process work | Carry-forward from documented retrospective items |
| Phase 1 (RESV-06) | **No** — pure addition | Trivial implementation; security model in this SUMMARY |
| Phase 2 (DX-06) | **YES — limited** | Test corpus assembly; `nikic/php-parser` patterns for non-standard layouts |
| Phase 3 (DX-02) | **No** — `AbstractDataCollector` is documented and stable | Implementation patterns well-established |
| Phase 4 (BOOT-04) | **YES — substantial** | `X-Transport` survival across all Messenger transports; `TenantTransportProviderInterface` fallback design; LRU cache calibration; DSN sanitization; landlord migration recipe |
| Phase 5 (DEMO-01) | **YES — limited** | Caddy `caddy trust` UX; per-tenant fixtures pattern; cross-OS smoke test details |
| Phase 6 (DOC-19) | **No** — content work | Docs follow code |

---

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | **HIGH** | All deps verified against Packagist + official docs; Flex source read directly |
| Features | **HIGH** | Set locked from user conversation; competitor analysis confirms gap closure |
| Architecture | **HIGH** | Every integration point is a documented Symfony extension surface |
| Pitfalls | **HIGH** for technical findings; **MEDIUM** for cross-OS demo failure modes |

**Overall confidence: HIGH.**

### Gaps to Address During Planning

- **DEC-MAIL-02 scope:** schema migration adds work to Phase 4. Owner may prefer to ship only the `From`-header half in v0.3 and defer per-tenant SMTP DSN to v0.4.
- **DEC-RESV-01 priority:** 25 (Architecture/Stack) vs 10 (Pitfalls conservatism). Owner picks.
- **Fixture corpus for DX-06:** need ≥6 real `bundles.php` shapes (API Platform, Sulu, EasyAdmin, DDD-skeleton, with-comments, with-conditionals). Phase 2 research.
- **`MailerTransportContractPass` async-detection:** validate `messenger.routing` config is accessible from the pass during Phase 4 research.
- **`human_needed` 72-hour TTL enforcement mechanism:** Phase 0 must specify the exact GSD tooling check.

---

## Sources

### Primary
- [Symfony Profiler — Custom Data Collectors](https://symfony.com/doc/current/profiler/data_collector.html)
- [Symfony Mailer Docs](https://symfony.com/doc/current/mailer.html); [Symfony Messenger Docs](https://symfony.com/doc/current/messenger.html)
- [Symfony discussion #46372 — `X-Transport` selection](https://github.com/symfony/symfony/discussions/46372)
- [Symfony issue #34972 — `RawMessage` clone on `MessageEvent`](https://github.com/symfony/symfony/issues/34972); [issue #37588](https://github.com/symfony/symfony/issues/37588); [discussion #61506](https://github.com/symfony/symfony/discussions/61506)
- [symfony/flex `BundlesConfigurator` (2.x)](https://github.com/symfony/flex/blob/2.x/src/Configurator/BundlesConfigurator.php)
- [dunglas/symfony-docker](https://github.com/dunglas/symfony-docker); [RFC 6761](https://datatracker.ietf.org/doc/html/rfc6761)
- [Mozilla bug 1741109](https://bugzilla.mozilla.org/show_bug.cgi?id=1741109); [WebKit bug 160504](https://bugs.webkit.org/show_bug.cgi?id=160504)
- [stancl/tenancy v4 Origin Header Resolver PR #621](https://github.com/archtechx/tenancy/pull/621)

### Project-internal
- `.planning/PROJECT.md`; `.planning/RETROSPECTIVE.md`; `.planning/v1.0-MILESTONE-AUDIT.md`
- `src/Resolver/HeaderResolver.php`; `src/Bootstrapper/BootstrapperChain.php`; `src/DependencyInjection/Compiler/CacheDecoratorContractPass.php`

### Detailed dimension files
- `.planning/research/STACK.md` — dependency analysis, version matrix
- `.planning/research/FEATURES.md` — per-feature deep dive, user contracts
- `.planning/research/ARCHITECTURE.md` — per-feature integration map, full DEC table
- `.planning/research/PITFALLS.md` — 12 pitfalls (5 critical + 7 moderate), recovery

---

*Research completed: 2026-05-15*
*Ready for requirements + roadmap: yes*
*Commit handling: orchestrator (commit_docs=false at project level)*
