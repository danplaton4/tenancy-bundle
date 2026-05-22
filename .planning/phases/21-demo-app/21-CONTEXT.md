# Phase 21: Demo App - Context

**Gathered:** 2026-05-22
**Status:** Ready for planning

<domain>
## Phase Boundary

Deliver `examples/saas/` — a runnable two-+-landlord Symfony app that a new
user clones, `docker compose up`s, and within ~2 minutes is hitting tenant
subdomains in a browser (or curl) and seeing isolated tenant data. The same
script doubles as a CI smoke test that gates `master` merges so the demo
cannot silently rot.

Concretely, this phase ships:

1. **`examples/saas/` Symfony app** — Symfony 7.4 skeleton at its own Composer
   root with a `repositories: [{ type: path, url: "../../", symlink: true }]`
   reference back to the bundle. Default driver: **database-per-tenant**
   against MariaDB 11.
2. **Three seeded tenants** — `acme`, `globex`, `initech` — each with distinct
   brand name, accent color (CSS variable), and 2–3 seeded posts. Mirrors
   the existing prototype demos under `/Users/danplaton/dev/hype/tests/` to
   reuse proven UX.
3. **Landlord root page** — `tenancy.localhost` (no tenant resolved) shows
   a Tenancy-Bundle Demo dashboard listing the three tenants with subdomain
   links. Demonstrates that null-tenant resolution is a first-class state
   (not an error).
4. **Per-tenant landing page** — `<slug>.tenancy.localhost` shows tenant
   name, slug, ID, active driver/connection, and the tenant's seeded post
   list. Read-only; no write paths in v0.3 demo.
5. **FrankenPHP + Caddy + MariaDB 11 + Mailpit** compose stack (DEC-DEMO-01).
   Caddy serves both HTTP and HTTPS; HTTP is the default walkthrough path
   (no cert friction), HTTPS is an optional subsection with the one-time
   `caddy trust` step documented.
6. **Doctrine fixtures** seed the landlord + 3 tenant DBs on first boot;
   `bin/console tenancy:migrate --create-dbs` provisions the per-tenant DBs
   from the entrypoint.
7. **`bin/smoke.sh`** — host-side curl-based smoke script (NOT in-container),
   `Host:` header injection, asserts HTTP 200 + tenant-specific body marker
   per tenant + landlord index listing. DNS-independent. Plus a brief curl
   with `-H 'Origin: …'` to exercise the Phase 17 OriginHeaderResolver code
   path.
8. **`.github/workflows/demo-smoke.yml`** — required CI job; `docker compose
   up -d --wait` + curl-retry readiness gate + `bin/smoke.sh`. Failure
   blocks merge to `master`.
9. **`docs/examples/saas-demo.md`** walkthrough scaffold — Phase 22 fills in
   the long-form prose; this phase ships a working README that Phase 22 can
   lift screenshots and copy from.
10. **v0.3 feature folding** (per the "fold competitive extensions" rule):
    - **Phase 19 Profiler tab** is on by default — `symfony/web-profiler-bundle`
      in demo's `require-dev`, `APP_ENV=dev` in compose. README walkthrough
      explicitly directs the user to open the Tenancy WDT panel and observe
      tenant/driver/bootstrappers.
    - **Phase 17 OriginHeaderResolver** is configured alongside `HostResolver`.
      README has a "SPA / cross-origin scenario" subsection with curl
      `-H 'Origin: …'` examples and a brief trust-model pointer. No new
      controller route — Origin-based resolution piggybacks on the existing
      landing routes.
    - **Phase 20 Mailer + Mailpit** ships always-up (NOT compose-profile-gated).
      Each tenant has a populated `mailerDsn` (pointing at Mailpit via
      `smtp://mailpit:1025?from=hello@<slug>.example`), a `mailerFrom`, and a
      `mailerReplyTo`. README walkthrough sends one test email per tenant
      and walks the user to `http://localhost:8025` (Mailpit UI) to see the
      isolated From/Reply-To per tenant.
    - **Phase 18 `tenancy:install`** referenced in README copy ("to add the
      bundle to YOUR app, run `tenancy:install`") — NOT exercised in the
      demo's own boot (the demo's bundle is wired by hand for path-repo
      reasons).

**Not in this phase:**
- Per-tenant CRUD write paths (no `/posts/new` form; demo is read-only —
  isolation is shown by fixture-seeded distinct data + smoke isolation
  invariants live in the prose walkthrough, not the smoke script).
- Shared-DB driver showcase (DB-per-tenant only; shared-DB has its own
  fixture/route shape and would double the docs surface).
- Sulu/API-Platform/DDD variants of the demo (one canonical skeleton).
- Symfony 8.x version of the demo (one demo, SF 7.4 LTS; SF 8.x compat is
  proven by the bundle's CI matrix, not by a second demo).
- Production-shaped deployment docs (this is a local-dev demo, not a
  blueprint for running tenancy-bundle apps in prod).
- Mailpit auth/UI hardening (default open Mailpit; it's localhost-only).

</domain>

<decisions>
## Implementation Decisions

### Carried Forward (LOCKED — do not re-decide)
- **DEC-DEMO-01:** Caddy + `*.tenancy.localhost` + internal CA + three-step
  fallback ladder (curl `Host:` → `/etc/hosts` → browser-native `*.localhost`).
  Stack is FrankenPHP + Caddy + MariaDB 11. Demo lives at its own Composer
  root with a path repository back to the bundle. Path-repo `symlink: true`
  for working-tree dev loop.
- **From REQUIREMENTS.md DEMO-01:** `bin/smoke.sh` uses `Host:` header
  (DNS-independent); `.github/workflows/demo-smoke.yml` blocks merge on
  failure; demo `composer.json` path-repo + `--prefer-source`.
- **From PROJECT.md tight-scope mandate:** "ship in weeks not months" —
  the demo is the v0.3 showcase, not a feature in its own right; keep the
  surface tight.

### App Scope & What Tenants Show
- **D-01:** Minimal landing page per tenant. Shows brand name (tenant.name),
  accent color (tenant.brandColor via CSS custom property), tenant ID and
  active driver/connection, and a 2–3 item seeded post list (e.g. "Welcome
  to Acme", "Acme launches v2", "Acme Q3 numbers"). Read-only.
  - **Rationale:** Smallest surface that proves isolation visibly. Name +
    color + post list = visual proof at a glance + data proof on inspection.
    CRUD was on the table but rejected: write paths add CSRF/auth concerns
    that don't help the v0.3 "install funnel" thesis. The smoke script
    proves data isolation via fixture-distinct markers; users prove it
    interactively by visiting two subdomains side by side.
- **D-02:** Landlord root page at `tenancy.localhost` lists the three
  tenants with links. Mirrors the proven UX from
  `/Users/danplaton/dev/hype/tests/symfony74-demo/README.md` (landlord
  dashboard → click tenant → on tenant subdomain).
  - **Rationale:** Demonstrates that null-tenant resolution is a first-class
    state — the resolver chain returns `null` for the root domain, the
    orchestrator does NOT throw, the controller proceeds to render landlord
    content. This is the v0.2 FIX-02 behavior made tangible. Also gives the
    user a starting URL they don't have to type a subdomain into.

### Tenant Count & Branding
- **D-03:** Three tenants: `acme` (Acme Corporation, orange),
  `globex` (Globex Industries, blue), `initech` (Initech LLC, green/mono).
  Aligns with the existing prototype demos. Roadmap criterion 1 says "two
  tenant subdomains"; three meets that.
  - **Rationale:** Three tenants make "N tenants" visually obvious in a
    way two doesn't (two reads as "before/after"; three reads as "a fleet").
    Third tenant also gets a visually distinct theme to drive home that
    branding is per-tenant data, not hard-coded.

### Driver Showcased
- **D-04:** Database-per-tenant only. MariaDB 11 hosts one landlord DB
  (`landlord`) plus one DB per tenant (`tenant_acme`, `tenant_globex`,
  `tenant_initech`). Single root `tenancy` user with grants on all four.
  - **Rationale:** Strongest isolation story; the v0.2 driver-middleware
    rewrite (FIX-03) is the bundle's headline differentiator and the demo
    should showcase it. Shared-DB has its own value proposition but a
    different fixture/route shape — folding both would double the surface
    and dilute the pitch. Shared-DB demo can ship in v0.4 alongside SHARE-*.
- **D-05:** Per-tenant DBs are provisioned by `bin/console tenancy:migrate
  --create-dbs` invoked from the FrankenPHP container's entrypoint (after
  MariaDB is healthy). Schema applied by Doctrine via
  `doctrine:schema:create` against each tenant DB (no `doctrine/migrations`
  in the demo to keep deps light).
  - **Rationale:** Self-referential — the demo exercises the bundle's own
    `tenancy:migrate` CLI as part of the boot path. Showing `tenancy:migrate
    --create-dbs` is implicit documentation for the user's later "how do I
    onboard a new tenant in my app" question. Doctrine schema-create over
    migrations because the demo's schema isn't expected to evolve outside
    the bundle's own version history.

### Fixtures
- **D-06:** Doctrine fixtures via `doctrine/doctrine-fixtures-bundle` in
  the demo's `require-dev`. Loaded by entrypoint via
  `bin/console doctrine:fixtures:load --no-interaction --append` after
  `tenancy:migrate --create-dbs`. Two fixture classes:
  `LandlordTenantsFixture` (seeds 3 tenants in landlord DB) and
  `TenantPostsFixture` (seeds 2–3 posts per tenant; iterated per tenant via
  `TenantContextOrchestrator::executeAs($tenant, fn() => ...)` pattern).
  - **Rationale:** Idiomatic, lives in PHP, replayable on volume wipe,
    visible-to-PHP-readers. Raw-SQL-on-MariaDB-init was the alternative —
    rejected because it bypasses the bundle's own write path and can't
    seed tenant DBs (which don't exist until `tenancy:migrate` runs).

### v0.3 Feature Folding
- **D-07:** Phase 19 **Profiler tab** is on by default.
  `symfony/web-profiler-bundle` in demo `require-dev`, `APP_ENV=dev` in
  compose, README walkthrough Section 3 explicitly directs the user to
  click the Tenancy panel and observe `resolved_by`/`driver`/`bootstrappers`.
  - **Rationale:** WDT panel is the highest-leverage debuggability surface
    v0.3 ships. The demo running `dev` is free advertising. Phase 22 docs
    refresh can lift the screenshots from this surface.
- **D-08:** Phase 17 **OriginHeaderResolver** is configured.
  `tenancy.yaml` registers both `HostResolver` (priority 30) and
  `OriginHeaderResolver` (priority 25) with the demo's subdomains in the
  allow-list. README has a brief "SPA / cross-origin scenario" subsection
  showing curl with `-H 'Origin: https://acme.tenancy.localhost'` resolving
  via the Origin chain. NO dedicated `/api/me` JSON route — Origin
  resolution piggybacks on the existing landing routes.
  - **Rationale:** OriginHeaderResolver is fundamentally a backend behavior;
    a curl example is the cleanest way to show "this is the same controller
    code, different resolver". A dedicated SPA route doubles surface for
    marginal narrative value.
- **D-09:** Phase 20 **Mailer + Mailpit** ships always-up. Mailpit container
  added to `docker-compose.yml` (not gated behind `--profile mailer`).
  Each tenant's seed data includes:
  - `mailerDsn`: `smtp://mailpit:1025` (internal docker network).
  - `mailerFrom`: `noreply@<slug>.example` (or similar tenant-distinct).
  - `mailerReplyTo`: `support@<slug>.example`.
  README walkthrough Section 4 has a "send a test email per tenant"
  step: `curl -X POST http://acme.tenancy.localhost/_demo/send-test-mail`
  triggers a one-line `$mailer->send()` from a controller, and the user
  opens `http://localhost:8025` (Mailpit UI) to see the three distinct
  From addresses prove per-tenant mailer dispatch.
  - **Rationale:** Mailer is the v0.3 headline feature and Mailpit is ~50MB
    — the visible "From acme@... and From globex@... in the same Mailpit
    inbox" is the demo's single most compelling moment. The previous
    REQUIREMENTS.md "Mailpit by default = OOS" line was written before the
    competitive-extension feedback (see `[[feedback_scope_fold_competitive_extensions]]`)
    — this decision deliberately overrides it. The `/_demo/send-test-mail`
    route is the ONLY write path in the demo and is intentional.
- **D-10:** Phase 18 **`tenancy:install`** is referenced in README copy ONLY
  ("to add the bundle to YOUR app, run `composer require …` and `bin/console
  tenancy:install`"). NOT exercised in the demo's own boot — the demo's
  bundle registration in `config/bundles.php` is committed (path-repo
  pinning + a fresh `tenancy:install` run on every container build would
  produce noisy diffs).
  - **Rationale:** Walkthrough copy points to it for downstream adoption;
    the demo's own setup is hand-wired for repeatability.

### TLS & First-Run UX
- **D-11:** **HTTP-by-default + HTTPS optional.** Caddy serves both
  `http://acme.tenancy.localhost` and `https://acme.tenancy.localhost`.
  README's primary walkthrough uses HTTP — zero cert friction, no `caddy
  trust` step on first run. HTTPS is a clearly-labeled "Optional: HTTPS
  with local CA" subsection documenting `docker compose exec caddy caddy
  trust` and the one-time browser CA install.
  - **Rationale:** First-run friction is the v0.3 adoption-funnel target.
    A cert warning at second 60 of the 2-minute promise is a catastrophic
    UX regression. HTTPS still demonstrated, just not on the critical path.
    CI smoke script uses HTTP (no `caddy trust` in CI).
- **D-12:** Three-step fallback ladder documented in `examples/saas/README.md`
  per DEC-DEMO-01, in this order:
  1. **Curl with `Host:` header** (works everywhere, what `bin/smoke.sh`
     does — copy-pasteable snippets).
  2. **`/etc/hosts` line** for Chrome-on-macOS-with-quirky-DNS / Firefox /
     Safari / WSL2 (`127.0.0.1 tenancy.localhost acme.tenancy.localhost
     globex.tenancy.localhost initech.tenancy.localhost`).
  3. **Browser-native `*.tenancy.localhost`** (the happy path; Chrome
     + macOS/Linux default works out of the box).

### Smoke Script Depth & Posture
- **D-13:** `bin/smoke.sh` runs on the **host** (not in-container), curls
  `localhost` with `Host:` headers. Caddy publishes ports 80 and 443. Same
  script runs locally and in CI without modification.
  - **Rationale:** DNS-independent, no `docker compose exec` indirection,
    "run smoke" is one command for human and CI both.
- **D-14:** Smoke assertions, per tenant:
  - `curl -sf -H 'Host: acme.tenancy.localhost' http://localhost/` → HTTP
    200 + body contains `Acme Corporation`.
  - Same for `globex` (`Globex Industries`) and `initech` (`Initech LLC`).
  - One landlord curl: `curl -sf http://localhost/` → HTTP 200 + body
    contains all three tenant slugs.
  - One Origin-resolver curl: `curl -sf -H 'Host: localhost' -H 'Origin:
    https://acme.tenancy.localhost' http://localhost/` → body contains
    `Acme Corporation` (proves Origin chain works).
  - Exit non-zero on any failure. No CRUD / write-isolation assertions —
    those live in the bundle's own integration suite; the demo proves
    "the demo works", not "the bundle's isolation invariants hold".
  - **Rationale:** Fast, deterministic, isolation proven by fixture-distinct
    markers being distinct per subdomain. Write-isolation invariants are
    bundle-test territory and folding them into the demo smoke makes the
    smoke script slow + fragile.
- **D-15:** CI readiness gate: `docker compose up -d --wait` (Compose v2
  native flag — uses healthchecks). MariaDB has a `mysqladmin ping`
  healthcheck; FrankenPHP has a `curl -fsS http://localhost/health` (a
  trivial `/health` controller that 200s once the schema + fixtures are
  loaded). After `--wait` returns, a short (max 30s) host-side curl-retry
  loop on the landlord URL is the final gate before invoking `bin/smoke.sh`.
  No fixed `sleep`s anywhere.
  - **Rationale:** Idiomatic, robust on slow CI runners, no "works on my
    laptop" risk. Healthcheck is the source of truth; curl-retry is a
    safety net.

### Composer / Path-Repo Workflow
- **D-16:** Demo `composer.json` references the bundle via:
  ```json
  "repositories": [
    { "type": "path", "url": "../../", "options": { "symlink": true } }
  ],
  "require": { "danplaton4/tenancy-bundle": "@dev", ... }
  ```
  - `composer install` runs during Docker image build (`Dockerfile` step,
    NOT host) to keep the image self-contained for CI. The bundle source
    is mounted into the build context via Docker bind mount on `up`, so
    local edits to `src/**` are reflected after `docker compose restart php`
    without rebuild.
  - **Rationale:** Symlink + bind-mount = working-tree dev loop. Image
    build keeps CI hermetic. `--prefer-source` is implied by `type: path`
    with `symlink: true` so the `vendor/danplaton4/tenancy-bundle`
    `readlink` is into the bundle source tree, not a copy.
- **D-17:** Verification asset — `/Users/danplaton/dev/hype/tests/symfony74-demo/`
  and `/Users/danplaton/dev/hype/tests/symfony8x-demo/` are pre-existing
  working SF 7.4 / SF 8.x apps with the bundle path-repo'd in. After
  `examples/saas/` is built, the executor can re-point one of those
  projects' path repos at `examples/saas/` (or just diff the two demos to
  confirm parity) as a smoke-of-the-smoke. These projects are NOT modified
  by this phase — they stay as the user's external scratch space.

### Claude's Discretion
- Exact `.env.example` shape (env vars for MARIADB_ROOT_PASSWORD,
  TENANCY_DOMAIN_BASE, etc.) — planner picks idiomatic defaults.
- Caddyfile structure (single Caddyfile vs split per-vhost) — planner
  picks per Caddy 2.x idioms.
- Whether FrankenPHP uses the Symfony Runtime worker mode or the classic
  PHP-FPM-like dispatch in the demo — planner picks based on whether
  worker mode complicates Doctrine connection lifecycle for the
  database-per-tenant driver.
- Whether the demo uses Symfony's native asset pipeline (Asset Mapper,
  Encore) or just inlines its tiny CSS — planner's call; inline is
  probably right for ~50 lines of CSS.
- Specific port binding (80/443 vs 8080/8443) — planner picks based on
  README friction (80 needs `sudo` on some Linux distros).
- Post entity shape — `id, title, body, createdAt` is the obvious set;
  planner picks Doctrine ORM idioms.
- Whether the demo's `Tenant` entity uses the bundle's default or a
  custom one. Default is fine; if custom is needed for Mailer trait
  showcase, planner adds the `TenantMailerConfigTrait` import.

### Folded Todos
None — no pending GSD todos matched Phase 21's scope at discussion time.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase requirements & locked decisions
- `.planning/REQUIREMENTS.md` §DEMO-01 — 6 acceptance criteria
- `.planning/REQUIREMENTS.md` §"Architectural Decisions (Ratified)" — DEC-DEMO-01
- `.planning/ROADMAP.md` §"Phase 21: Demo App" — goal + 5 success criteria
- `.planning/PROJECT.md` §"Current Milestone — v0.3 Adoption Surface" — tight-scope
  mandate ("ship in weeks not months") and v0.3 install-funnel thesis

### v0.3 research synthesis (HIGH confidence)
- `.planning/research/SUMMARY.md` §"Critical Pitfalls (Top 5)" item 4 —
  `*.localhost` cross-OS DNS guidance, three-step fallback ladder rationale,
  CI demo-smoke gate
- `.planning/research/PITFALLS.md` §"Pitfall — Demo `*.localhost` only
  works on Chrome" — long-form rationale; READ before drafting README
- `.planning/research/STACK.md` — FrankenPHP + Caddy + MariaDB 11 + path-repo
  composition rationale and rejected alternatives (PHP-FPM+nginx, Symfony CLI,
  Postgres)

### Existing prototype demos (verification asset, NOT source-of-truth)
- `/Users/danplaton/dev/hype/tests/symfony74-demo/` — working SF 7.4 +
  MySQL 8 + nginx + PHP-FPM 3-tenant demo path-repo'd to this bundle. Use as:
  - Reference for fixture/landing-page UX (landlord dashboard → /dashboard)
  - Post-build verification (re-point its `repositories.path` at
    `examples/saas/` and confirm both render the same)
  - NOT a template to copy into `examples/saas/` (different stack:
    DEC-DEMO-01 mandates FrankenPHP + Caddy + MariaDB)
- `/Users/danplaton/dev/hype/tests/symfony8x-demo/` — same as above for SF 8.x;
  proves the SF 7.4 + SF 8.x compat story without requiring two demos in-repo

### Existing bundle codebase — integration points
- `src/Command/Install/TenancyInstallCommand.php` — referenced in README copy
  (D-10); NOT invoked by the demo boot path
- `src/Command/TenantMigrateCommand.php` — invoked by demo entrypoint
  (D-05) for per-tenant DB creation; check its `--create-dbs` flag semantics
- `src/Resolver/HostResolver.php` — primary resolver in demo (priority 30)
- `src/Resolver/OriginHeaderResolver.php` — secondary resolver in demo
  (priority 25); D-08 README curl snippets must match this resolver's
  allow-list config shape
- `src/EventListener/TenantContextOrchestrator.php` — null-tenant tolerance
  (FIX-02) is what makes the landlord root page work without throwing;
  reference for "this is OK behavior"
- `src/Profiler/TenantDataCollector.php` (Phase 19) — README walkthrough
  Section 3 points users at this collector's WDT output
- `src/Mailer/MailerBootstrapper.php` + `src/Mailer/TenantMessageDecorator.php`
  (Phase 20) — D-09 mailer demo route exercises this code path
- `src/Mailer/TenantMailerConfigTrait.php` (Phase 20) — demo's Tenant
  entity (whether bundle default or custom) needs this trait OR concrete
  `getMailerDsn/getMailerFrom/getMailerReplyTo` methods
- `src/Entity/Tenant.php` — bundle's default Tenant entity; if demo uses
  it directly (most likely), confirm it has `name`, `slug`, mailer columns
  from Phase 20, and add a `brandColor` column if needed (planner's call:
  add to bundle's default Tenant, or define a demo-local Tenant subclass)
- `BootstrapperChain` — Demo's `tenancy.yaml` enables Database, Doctrine,
  Cache, Mailer bootstrappers; verify ordering produces a clean panel

### Prior phase context (read in this order)
- `.planning/phases/17-origin-header-resolver/17-CONTEXT.md` — Origin
  resolver config shape; D-08 must match
- `.planning/phases/19-profiler-tab/19-CONTEXT.md` — WDT panel data
  shape; D-07 README screenshots come from this
- `.planning/phases/20-mailer-bootstrapper/20-CONTEXT.md` — Mailer
  bootstrapper config + Tenant interface methods; D-09 Mailpit demo route
  exercises this
- `.planning/phases/18-tenancy-install/18-CONTEXT.md` — `tenancy:install`
  semantics; D-10 README copy must accurately describe what the command does

### Documentation refs
- `examples/saas/README.md` — written this phase; Phase 22 lifts screenshots
  + walkthrough copy from it for `docs/examples/saas-demo.md`
- `UPGRADE.md` — no changes from this phase (no BC break)
- `CHANGELOG.md` — appends "Added examples/saas/ demo app and demo-smoke CI
  gate" to v0.3 section

### CI surface
- `.github/workflows/ci.yml` — existing PHP × Symfony matrix; Phase 21 adds
  a sibling workflow file, NOT a job in this one (separate workflow keeps
  the matrix lean and the demo failure isolatable from unit-test failures)
- `.github/workflows/docs.yml` — existing docs deploy; Phase 21 is unrelated
- **NEW:** `.github/workflows/demo-smoke.yml` — runs on push to `master` +
  PRs; `docker compose up -d --wait` + curl-retry + `bin/smoke.sh`. Required
  status check for merge.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `src/Command/TenantMigrateCommand.php` `--create-dbs` flag — D-05 boot
  path calls this; confirms the bundle's own DB-provisioning CLI is the
  intended entry point for demo seed flow
- `src/Resolver/ResolverChain.php` — null-tenant return path (FIX-02)
  makes D-02 (landlord root page) trivially correct without bypass code
- `src/Bootstrapper/BootstrapperChain.php` — Demo enables Database,
  Doctrine, Cache, and Mailer bootstrappers; the chain's boot/clear
  ordering is already debugged in prior phases
- `src/Profiler/TenantDataCollector.php` (Phase 19) — Demo gets WDT panel
  "for free" by including `symfony/web-profiler-bundle` in require-dev
- `src/Mailer/MailerBootstrapper.php` (Phase 20) — Demo gets per-tenant
  mailer dispatch by setting `mailerDsn` on tenants and enabling the
  bootstrapper in `tenancy.yaml`
- Bundle's existing `composer.json` `"type": "symfony-bundle"` — eligible
  for path-repo reference from demo's `composer.json`
- v0.2 `.github/workflows/ci.yml` is the precedent for the demo-smoke
  workflow's shape (checkout, setup, run, gate)

### Verification Assets (external, not modified by this phase)
- `/Users/danplaton/dev/hype/tests/symfony74-demo/` — pre-existing SF 7.4
  demo with bundle path-repo'd in via `/Users/danplaton/dev/tenancy-bundle-src`
  symlink (i.e. to this repo). Has `compose.yaml` (mysql:8 + nginx + PHP-FPM,
  3 tenants acme/globex/initech, `/dashboard` per tenant). Use during execution
  to validate the new demo behaves equivalently.
- `/Users/danplaton/dev/hype/tests/symfony8x-demo/` — SF 8.x sibling of the
  above. Same role.
- `/Users/danplaton/dev/hype/tests/BUG_REPORT.md` — historic bug findings
  from prior demo work; scan during planning for any Caddy/Mailpit/MariaDB
  gotchas already encountered

### Established Patterns
- `final class` everywhere, `private readonly` constructor injection,
  `strict_types=1` — demo's `src/` follows the bundle's style
- Demo's bundle registration mirrors the bundle's own
  `tests/Functional/app/config/bundles.php` patterns
- Bundle DI registration via `services.php` (configurator pattern) is the
  exemplar; demo can use the simpler `services.yaml` since it's app code
- Tests in this phase live OUTSIDE the bundle's PHPUnit suites — the
  demo's smoke script + GitHub workflow ARE the tests. No new
  `tests/Unit/` or `tests/Integration/` entries.

### Integration Points
- `examples/saas/` (NEW directory at repo root)
  - `composer.json` (NEW)
  - `compose.yaml` (NEW; FrankenPHP + Caddy + MariaDB + Mailpit)
  - `Dockerfile.php` (NEW; FrankenPHP base, `composer install`, entrypoint
    script)
  - `Caddyfile` (NEW; vhost per tenant + landlord, HTTP + HTTPS, internal CA)
  - `bin/smoke.sh` (NEW; host-side curl smoke; `chmod +x`)
  - `bin/entrypoint.sh` (NEW; waits-for-DB → tenancy:migrate → fixtures →
    php-fpm/franken)
  - `config/bundles.php`, `config/packages/tenancy.yaml`,
    `config/packages/doctrine.yaml`, `config/packages/mailer.yaml`,
    `config/packages/framework.yaml`, `config/packages/web_profiler.yaml`
    (NEW; idiomatic Symfony 7.4 skeleton minus the bits we don't need)
  - `config/routes.yaml` (NEW; routes for landlord + tenant landing +
    `/_demo/send-test-mail` + `/health`)
  - `src/Controller/LandlordController.php` (NEW)
  - `src/Controller/TenantController.php` (NEW)
  - `src/Controller/DemoMailController.php` (NEW; `/_demo/send-test-mail`)
  - `src/Controller/HealthController.php` (NEW; `/health` for the docker
    healthcheck)
  - `src/Entity/Post.php` (NEW)
  - `src/Repository/PostRepository.php` (NEW)
  - `src/DataFixtures/LandlordTenantsFixture.php` (NEW)
  - `src/DataFixtures/TenantPostsFixture.php` (NEW)
  - `templates/base.html.twig`, `templates/landlord/index.html.twig`,
    `templates/tenant/index.html.twig` (NEW)
  - `README.md` (NEW; user-facing walkthrough + three-step fallback ladder)
  - `.env`, `.env.example` (NEW)
  - `.dockerignore`, `.gitignore` (NEW)
- `.github/workflows/demo-smoke.yml` (NEW at repo root)
- `.gitignore` at repo root (MODIFIED if needed for `examples/saas/var/`,
  `examples/saas/vendor/`)
- `README.md` at repo root (MODIFIED — point to `examples/saas/README.md`
  in the "Try the demo" section)

### What does NOT exist yet
- No `examples/` directory in the bundle (`docker-compose.yml` at root is
  the bundle's OWN dev container, NOT a demo — leave it alone)
- No `.github/workflows/demo-smoke.yml`
- No `bin/smoke.sh` anywhere in the bundle
- No `Tenant` entity column for `brandColor` (decision deferred to planner
  per D-01 Claude's Discretion — add column to bundle default, or carry
  it on a demo-local `Tenant` subclass)
- No Mailpit reference anywhere in compose / docs
- No HTTPS/TLS configuration in any existing compose file

### Critical Edge: Bundle-Source Dev Loop
- The demo's path-repo `symlink: true` means edits to `src/**` in the
  bundle reflect in `examples/saas/vendor/danplaton4/tenancy-bundle/`
  immediately, BUT FrankenPHP/PHP needs an OPcache reset to pick them up.
  README + entrypoint must document/enable `opcache.validate_timestamps=1`
  + low revalidate-freq in dev so users editing bundle source see changes
  on next request without `restart`.

</code_context>

<specifics>
## Specific Ideas

- **Verification asset is `/Users/danplaton/dev/hype/tests/`** (user-flagged
  during discussion). Both `symfony74-demo/` and `symfony8x-demo/` already
  have the bundle wired via path-repo to
  `/Users/danplaton/dev/tenancy-bundle-src` (this repo). After
  `examples/saas/` is built, executor can validate parity by re-pointing
  those projects' `repositories.path` or diffing rendered pages. These
  external projects are NOT modified by this phase.

- **Landlord UX pattern**: lift the "landlord dashboard listing tenants"
  pattern from `hype/tests/symfony74-demo/README.md` — the user already
  signed off on that as the right shape during prior demo work. New demo
  reuses the pattern at `tenancy.localhost`.

- **Tenant identities** are deliberately the same triplet as the existing
  prototype demos: `acme` / `globex` / `initech`. Keeps the user's mental
  model continuous between the in-repo demo and the external scratch
  demos.

- **Competitive positioning the demo proves at-a-glance:**
  - **stancl/tenancy (Laravel leader)** ships a "tenancy + tenant + landlord"
    sample but NOT a one-command Docker demo with subdomain routing + CI
    gate. The five-pointed contrast: (1) `docker compose up` no-prereq
    boot, (2) `*.tenancy.localhost` subdomain routing with `caddy trust`
    optional, (3) Profiler WDT panel surfacing tenant state, (4) per-tenant
    From/Reply-To visible in Mailpit, (5) CI smoke gate so demo can't rot.
  - The Mailpit screenshot (three distinct From addresses from same code
    path) is the single most compelling competitive moment. README puts it
    at the top of the walkthrough.

- **Two-minute promise (roadmap criterion 1) is measured from
  `docker compose up` to "user sees isolated tenant content in browser":**
  - Container build + start: ~30s warm cache, ~90s cold (image pull)
  - MariaDB init + healthcheck: ~10s
  - `tenancy:migrate --create-dbs` + fixtures: <5s
  - User visits subdomain: <1s
  - **Total cold: ~110s, warm: ~50s.** Within budget if image layers are
    well-ordered (composer install + FrankenPHP base layer cached).

</specifics>

<deferred>
## Deferred Ideas

- **Shared-DB driver demo** — distinct fixture/route shape (no per-tenant
  DBs, tenant_id filter). Future v0.4 candidate alongside SHARE-*; would
  live in `examples/shared-db/` or as a compose profile in `examples/saas/`.
- **Per-tenant CRUD (`/posts/new` form)** — adds CSRF/auth concerns that
  distract from the install-funnel thesis. Future "tenancy admin" demo
  candidate.
- **SPA scenario route (`/api/me` JSON endpoint)** — D-08 documents Origin
  resolution via curl in README; a dedicated SPA-shaped route can ship as
  part of a future "SPA + JWT" cookbook.
- **Symfony 8.x version of the demo in-repo** — one canonical demo (SF 7.4
  LTS); SF 8.x compat proven by `hype/tests/symfony8x-demo/` and bundle
  CI matrix.
- **Production deployment blueprint** — different audience (DevOps), out
  of v0.3 scope. v0.5 Operations & Scale candidate.
- **Mailpit hardening / auth** — local-dev demo; production-shaped mail
  testing is its own concern.
- **APM / observability container in demo** — explicit v0.3 OOS per
  REQUIREMENTS.md; v0.5 candidate.
- **Demo full CRUD isolation smoke assertion (POST to A, GET from B,
  assert absent)** — bundle's own integration suite covers this; folding
  into demo smoke makes the script slow + flaky. Re-evaluate if the demo
  ever grows write paths.
- **`docs/examples/saas-demo.md` long-form walkthrough** — owned by Phase
  22 (Docs Refresh). Phase 21 ships a working in-repo README that Phase 22
  lifts from.

### Reviewed Todos (not folded)
None — no GSD todos matched at discussion time.

</deferred>

---

*Phase: 21-Demo App*
*Context gathered: 2026-05-22*
