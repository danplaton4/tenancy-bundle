# Phase 21: Demo App — Research

**Researched:** 2026-05-22
**Domain:** Multi-tenant Symfony demo app (FrankenPHP + Caddy + MariaDB + Mailpit) with CI smoke gate
**Confidence:** HIGH (most decisions locked in CONTEXT.md; remaining unknowns are integration mechanics, verified against bundle source)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

Carried forward (LOCKED — do not re-decide):
- **DEC-DEMO-01:** Caddy + `*.tenancy.localhost` + internal CA + three-step fallback ladder. Stack is FrankenPHP + Caddy + MariaDB 11. Demo lives at its own Composer root with a path repository back to the bundle. Path-repo `symlink: true` for working-tree dev loop.
- **From REQUIREMENTS.md DEMO-01:** `bin/smoke.sh` uses `Host:` header (DNS-independent); `.github/workflows/demo-smoke.yml` blocks merge on failure; demo `composer.json` path-repo + `--prefer-source`.
- **D-01:** Minimal landing page per tenant — brand name, accent color (CSS variable), tenant ID, active driver/connection, 2–3 seeded posts. Read-only.
- **D-02:** Landlord root page at `tenancy.localhost` lists three tenants with links. Mirrors `hype/tests/symfony74-demo/`.
- **D-03:** Three tenants: `acme` (Acme Corporation, orange), `globex` (Globex Industries, blue), `initech` (Initech LLC, green/mono).
- **D-04:** Database-per-tenant only. MariaDB 11 hosts `landlord` + `tenant_acme`, `tenant_globex`, `tenant_initech`. Single root `tenancy` user with grants on all four.
- **D-05:** Per-tenant DBs provisioned by `bin/console tenancy:migrate --create-dbs` invoked from FrankenPHP container entrypoint. Schema applied by Doctrine via `doctrine:schema:create`. **[ASSUMED — see "Critical Discrepancy" below]**
- **D-06:** Doctrine fixtures via `doctrine/doctrine-fixtures-bundle` in `require-dev`. Two fixture classes: `LandlordTenantsFixture` (3 tenants in landlord DB) and `TenantPostsFixture` (2–3 posts per tenant; iterated via `TenantContextOrchestrator::executeAs($tenant, fn() => ...)`). **[ASSUMED — see "Critical Discrepancy" below]**
- **D-07:** Phase 19 Profiler tab on by default. `symfony/web-profiler-bundle` in `require-dev`, `APP_ENV=dev` in compose.
- **D-08:** Phase 17 `OriginHeaderResolver` configured. Both `HostResolver` (priority 30) + `OriginHeaderResolver` (priority 25) in `tenancy.yaml`. README "SPA / cross-origin scenario" subsection. NO dedicated `/api/me` route.
- **D-09:** Phase 20 Mailer + Mailpit always-up (NOT compose-profile-gated). Each tenant has `mailerDsn: smtp://mailpit:1025`, `mailerFrom: noreply@<slug>.example`, `mailerReplyTo: support@<slug>.example`. `/_demo/send-test-mail` route — the ONLY write path.
- **D-10:** Phase 18 `tenancy:install` referenced in README copy ONLY. Demo's own `config/bundles.php` is hand-wired and committed.
- **D-11:** HTTP-by-default + HTTPS optional. Caddy serves both. README primary walkthrough uses HTTP. CI smoke uses HTTP.
- **D-12:** Three-step fallback ladder in this order: (1) curl `Host:` header, (2) `/etc/hosts` line, (3) browser-native `*.tenancy.localhost`.
- **D-13:** `bin/smoke.sh` runs on host (not in-container), curls `localhost` with `Host:` headers. Same script local + CI.
- **D-14:** Smoke assertions per tenant — HTTP 200 + body marker (`Acme Corporation` / `Globex Industries` / `Initech LLC`); landlord curl 200 + all three slugs; Origin-resolver curl proves chain works. Exit non-zero on any failure. No CRUD/write-isolation assertions.
- **D-15:** CI readiness gate — `docker compose up -d --wait`. MariaDB has `mysqladmin ping` healthcheck; FrankenPHP has `curl -fsS http://localhost/health` (trivial `/health` controller). Short host-side curl-retry loop (max 30s) after `--wait` as safety net. No fixed sleeps.
- **D-16:** Demo `composer.json` references bundle via `repositories: [{ type: path, url: "../../", options: { symlink: true } }]` + `require: { "danplaton4/tenancy-bundle": "@dev" }`. `composer install` runs during Docker image build (NOT host). Bundle source mounted into build context via Docker bind mount on `up`.
- **D-17:** External `/Users/danplaton/dev/hype/tests/symfony74-demo/` + `symfony8x-demo/` are verification assets, NOT modified by this phase.

### Claude's Discretion
- Exact `.env.example` shape (env vars for `MARIADB_ROOT_PASSWORD`, `TENANCY_DOMAIN_BASE`, etc.).
- Caddyfile structure (single Caddyfile vs split per-vhost).
- Whether FrankenPHP uses Symfony Runtime worker mode or classic dispatch (picker decision based on Doctrine connection lifecycle for database-per-tenant).
- Whether the demo uses Asset Mapper / Encore or just inlines its tiny CSS (inline likely correct for ~50 lines).
- Specific port binding (80/443 vs 8080/8443) based on README friction.
- Post entity shape — `id, title, body, createdAt` is the obvious set.
- Whether demo's `Tenant` entity uses bundle's default or a custom one. Default is fine; if custom is needed for `brandColor`, planner adds the column either on the bundle default OR on a demo-local subclass.

### Deferred Ideas (OUT OF SCOPE)
- Shared-DB driver demo
- Per-tenant CRUD (`/posts/new` form)
- SPA scenario route (`/api/me` JSON endpoint)
- Symfony 8.x version of the demo in-repo
- Production deployment blueprint
- Mailpit hardening / auth
- APM / observability container
- Demo full CRUD isolation smoke assertion
- `docs/examples/saas-demo.md` long-form walkthrough (owned by Phase 22)
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| DEMO-01 | `examples/saas/` ships a runnable two-tenant Symfony app — `docker compose up` → tenant subdomains resolve and serve isolated content out of the box. Doubles as CI smoke test that gates `master` merges. | Sections "Architecture & Patterns" (compose stack), "CI Smoke Script Design" (Host-header pattern), "Domain Research" (Caddy + `*.tenancy.localhost` UX), "Cross-Phase Integration Map" (resolver config, mailer config). Three tenants (`acme`/`globex`/`initech`) exceed the "two tenant" minimum acceptance criterion. |
</phase_requirements>

## Executive Summary

- **Cross-phase integration is well-defined and verified.** Exact class names, config keys, and DI surface for Phase 17 (`OriginHeaderResolver` + `OriginHeaderResolverConfigPass`), Phase 18 (`TenancyInstallCommand`), Phase 19 (`TenantDataCollector` — service ID, render states), and Phase 20 (`MailerBootstrapper` + `mailerDsn`/`mailerFrom`/`mailerReplyTo` on `Tenant`) all exist in source and match CONTEXT.md.

- **One critical discrepancy with CONTEXT.md (D-05): `tenancy:migrate` has NO `--create-dbs` flag.** Source inspection of `src/Command/TenantMigrateCommand.php` shows only `--tenant`. The demo CANNOT call `tenancy:migrate --create-dbs` as written. The planner must pick an alternative provisioning path. See "Critical Discrepancy" below for three options.

- **Doctrine fixtures with multiple connections is a known footgun.** `doctrine/doctrine-fixtures-bundle` defaults to the default EM and is awkward for per-tenant DBs (which don't exist at fixture-load time until tenant DBs are created and the connection is switched). The bundle's `executeAs()` pattern (referenced in CONTEXT D-06) does NOT exist as a method — the actual primitive is `BootstrapperChain::boot($tenant)` + `BootstrapperChain::clear()`. The planner should either (a) wrap fixture loading in a dedicated `app:seed-demo` command that orchestrates the per-tenant boot/clear cycle, OR (b) write raw SQL DDL on container init.

- **Caddy `*.tenancy.localhost` + internal CA wildcard works cleanly on Chrome (all OS) and Firefox (modern); Safari is the known weak spot.** Caddy's `tls internal` directive issues wildcard certs from its internal CA without needing DNS challenges. Browser behavior for `*.localhost` varies: Chromium auto-resolves to loopback; Firefox now respects RFC 6761 §6.3 in recent versions but has a long bug tail; Safari historically required `/etc/hosts` entries. CONTEXT.md's HTTP-default + three-step fallback ladder is the right call.

- **CI smoke pattern is idiomatic and low-risk.** `docker compose up -d --wait` + curl-with-Host-header + host-port-publish (no in-container exec) is the standard pattern. Use `curl --fail --max-time 10 --retry 5 --retry-all-errors --retry-connrefused -H 'Host: ...' http://localhost/`. Exit non-zero on any failure via `set -euo pipefail` + per-curl `--fail`.

**Primary recommendation:** Plan around the three concrete gaps: (1) replace `--create-dbs` with a thin demo-local Symfony command that issues `CREATE DATABASE IF NOT EXISTS` DDL via the landlord DBAL connection before running `doctrine:schema:create --em=tenant` per tenant; (2) seed tenant DBs via a custom `app:seed-demo` command (not raw `doctrine:fixtures:load`) that uses `BootstrapperChain::boot()` per tenant; (3) accept that `brandColor` must live on a demo-local `App\Entity\DemoTenant extends Tenancy\Bundle\Entity\Tenant` (cleanest), NOT on the bundle's `Tenant` (would balloon scope into bundle source for a demo-only field).

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Subdomain → tenant routing | API/Backend (PHP) | Edge (Caddy) | `HostResolver` runs inside Symfony on every request; Caddy just terminates TLS and proxies. Wildcard cert is the only edge concern. |
| Tenant data isolation | Database (MariaDB) | API/Backend | Per-tenant physical DB is the isolation boundary; backend trusts the boundary and switches DBAL connection per request. |
| Per-tenant mail dispatch | API/Backend | External (Mailpit) | `MailerBootstrapper` selects the transport; Mailpit is just a sink that lets the user SEE the per-tenant From addresses. |
| Static assets (CSS) | API/Backend (inline) | — | ~50 lines of CSS; inline in base template avoids Asset Mapper / Encore scope creep. |
| HTTPS termination | Edge (Caddy) | — | Caddy's internal CA + automatic wildcard for `*.tenancy.localhost`. |
| Smoke testing | Host-side (curl in CI runner) | — | DNS-independent via `Host:` header; published Docker ports 80/443; same script local + CI. |
| Profiler / WDT | API/Backend (dev only) | Browser (rendering) | `TenantDataCollector` runs in PHP; WDT is rendered in browser HTML — dev-mode only via `kernel.debug`. |

## Critical Discrepancy with CONTEXT.md

> **D-05 says** the entrypoint runs `bin/console tenancy:migrate --create-dbs`.
> **Source says** `tenancy:migrate` only has `--tenant` (single-tenant filter). There is NO `--create-dbs` flag and no DB-creation path in the command anywhere in `src/`.

Verified via:
- `src/Command/TenantMigrateCommand.php` lines 38–46: only `addOption('tenant', ...)`.
- `grep -rn "create-dbs\|createDb" src/` returns no matches.

**Three viable options for the planner:**

1. **Add a thin demo-local command** `App\Command\SeedDemoCommand` (~50 LOC) that:
   - Issues `CREATE DATABASE IF NOT EXISTS tenant_acme/globex/initech` via the landlord DBAL connection
   - For each tenant, sets the active tenant via `TenantContext`, boots the chain, runs `doctrine:schema:create --em=tenant`, then loads tenant fixtures, then clears
   - Idempotent on re-run
   - This is the lowest-friction option and idiomatic.

2. **Raw SQL on MariaDB init.** Place a `init.sql` file in `/docker-entrypoint-initdb.d/` that creates the four databases. Then the entrypoint runs `doctrine:schema:create` per tenant. Schema-and-data still need PHP.

3. **Add `--create-dbs` to the bundle's `tenancy:migrate`.** Out of scope for Phase 21 (extends bundle surface for a demo-only need). Reject.

**Recommendation: Option 1.** It (a) keeps the bundle untouched, (b) is documented in the README as "the demo wraps tenant provisioning in a single command", and (c) is the exact shape the user's downstream app will want anyway.

Similarly, CONTEXT.md D-06 references `TenantContextOrchestrator::executeAs($tenant, fn() => ...)` — this method does NOT exist on `TenantContextOrchestrator` (verified via reading `src/EventListener/TenantContextOrchestrator.php`). The actual primitive is the pattern used inside `TenantMigrateCommand` itself:

```php
$this->tenantContext->setTenant($tenant);
$this->bootstrapperChain->boot($tenant);
try {
    // ...do work for this tenant
} finally {
    $this->tenantContext->clear();
    $this->bootstrapperChain->clear();
}
```

The planner's seeding command must use this pattern directly.

## Standard Stack

### Core (verified via package documentation + bundle composer.json)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `dunglas/frankenphp` (Docker image) | latest | PHP runtime + embedded Caddy | Single-process app server; eliminates PHP-FPM + nginx split [CITED: https://frankenphp.dev/docs/symfony/] |
| MariaDB | `11` | Per-tenant database backend | Locked in DEC-DEMO-01; mysql-compatible client tools (`mysqladmin ping`) work as healthcheck |
| Mailpit | `axllent/mailpit:latest` | SMTP sink + web UI | ~50MB, default port 1025 (SMTP) + 8025 (UI); zero-config local dev SMTP catcher [CITED: https://github.com/axllent/mailpit] |
| Caddy (embedded in FrankenPHP) | 2.x | TLS termination + wildcard cert via internal CA | `tls internal` directive issues internal-CA certs for `*.tenancy.localhost` without DNS challenge [CITED: https://caddyserver.com/docs/automatic-https] |
| Symfony | `7.4.*` (LTS) | Demo skeleton | Matches `hype/tests/symfony74-demo/` and bundle's lower-bound supported version |
| Doctrine ORM/DBAL/Bundle | `^3`/`^4`/`^2.13` | Tenant + Post entities | Already a hard-required transitive dep of `database.enabled: true` |
| `doctrine/doctrine-fixtures-bundle` | `^4` | Tenant + Post fixtures | `require-dev` in demo |
| `symfony/web-profiler-bundle` | `7.4.*` | WDT (D-07) | `require-dev` in demo |
| `symfony/mailer` | `7.4.*` | Mailpit dispatch (D-09) | Already in bundle suggest; demo elevates to `require` |
| `symfony/twig-bundle` | `7.4.*` | Templates | Idiomatic |

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `symfony/runtime` | `7.4.*` | Entry point | Standard skeleton dep |
| `symfony/dotenv` | `7.4.*` | `.env`/`.env.example` parsing | Required for Symfony skeleton |
| `symfony/asset` | `7.4.*` | Twig `asset()` helper (only if CSS is external) | Optional — inline CSS avoids it |

### Alternatives Considered (Rejected)

| Instead of | Could Use | Tradeoff (why we rejected) |
|------------|-----------|----------------------------|
| FrankenPHP | PHP-FPM + nginx | Two-process complexity; bigger image; ports juggling. FrankenPHP wins on "one container" simplicity. (Existing `hype/tests/symfony74-demo` uses FPM+nginx — Phase 21 deliberately diverges per DEC-DEMO-01.) |
| MariaDB | PostgreSQL | DEC-DEMO-01 locks MariaDB; CONTEXT lock. |
| Doctrine Fixtures | Raw SQL files in `init.sql` | Fixtures are PHP-readable, replayable, idiomatic. But fixtures fight multi-DB. The compromise: use fixtures for landlord (single connection), and a dedicated seeding command for tenant Posts. |
| `doctrine/migrations` | `doctrine:schema:create` | CONTEXT D-05 explicitly says no migrations in the demo. Schema is owned by the bundle's release history; demo just creates schema from entity metadata. |

**Installation (demo `composer.json` require block):**
```bash
composer require \
  danplaton4/tenancy-bundle:@dev \
  doctrine/doctrine-bundle \
  doctrine/orm \
  doctrine/dbal \
  symfony/mailer:7.4.* \
  symfony/twig-bundle:7.4.* \
  symfony/runtime:7.4.* \
  symfony/yaml:7.4.* \
  symfony/dotenv:7.4.* \
  symfony/framework-bundle:7.4.*

composer require --dev \
  symfony/web-profiler-bundle:7.4.* \
  doctrine/doctrine-fixtures-bundle:^4
```

## Package Legitimacy Audit

> All packages listed are well-known, multi-year-old packages with thousands of downloads/week. Listing for completeness; no [SLOP] or [SUS] flagged.

| Package | Registry | Age | Source Repo | Disposition |
|---------|----------|-----|-------------|-------------|
| `dunglas/frankenphp` (image) | Docker Hub / GHCR | 2+ years | github.com/php/frankenphp | Approved [CITED: https://frankenphp.dev] |
| `mariadb:11` | Docker Hub | Official | github.com/MariaDB/mariadb-docker | Approved (official) |
| `axllent/mailpit` | Docker Hub | 4+ years | github.com/axllent/mailpit | Approved [CITED: https://github.com/axllent/mailpit] |
| `doctrine/doctrine-fixtures-bundle` | Packagist | 12+ years, ~13M installs | github.com/doctrine/DoctrineFixturesBundle | Approved [CITED: https://packagist.org/packages/doctrine/doctrine-fixtures-bundle] |
| `symfony/web-profiler-bundle` | Packagist | 10+ years | github.com/symfony/web-profiler-bundle | Approved (official Symfony) |
| `symfony/mailer` | Packagist | 5+ years | github.com/symfony/mailer | Approved (official Symfony) |

*slopcheck was not run in this session (Python tool, project is PHP-only) — packages above are all manually-verified well-known incumbents. Planner does not need to add `checkpoint:human-verify` tasks.*

## Domain Research

### Caddy + `*.tenancy.localhost` HTTPS UX

**Internal CA — the `tls internal` directive.** Caddy ships an opt-in internal CA that issues certs without needing public ACME or DNS challenges. For internal/local hostnames (`.localhost`, `.internal`, IPs), Caddy enables `tls internal` automatically. For wildcard certs covering `*.tenancy.localhost`, you write:

```caddy
*.tenancy.localhost, tenancy.localhost {
    tls internal
    root * /app/public
    php_server
}
```

[CITED: https://caddyserver.com/docs/automatic-https — §"Local HTTPS"]

**The `caddy trust` step.** The first time Caddy generates its root cert, it tries to install it into the system trust store automatically. Inside a Docker container, this fails (no system trust store) — so the user runs `docker compose exec caddy caddy trust` (or `docker compose exec php caddy trust` since FrankenPHP includes Caddy). This installs Caddy's root cert into:
- macOS: `Keychain Access` → System
- Linux: `/usr/local/share/ca-certificates/` (Ubuntu) or equivalent
- Windows (WSL2): `update-ca-certificates` in WSL, BUT Windows-side browsers still need manual install

[CITED: https://blog.lanzani.nl/2024/trust-local-caddy-certificates-on-macos/, https://caddy.community/t/approach-to-offer-local-root-crt-for-download-by-a-browser-to-install-trust/13962]

**Firefox is the exception.** Firefox uses its own trust store (not system). Even after `caddy trust`, Firefox throws CERT_AUTHORITY_INVALID unless the user manually imports the CA via `about:preferences#privacy` → View Certificates → Authorities → Import. README must call this out.

**Implication for CONTEXT D-11:** HTTP-default is the right call. HTTPS section in README must include the Firefox-specific manual import step.

### Browser / OS Matrix for `*.tenancy.localhost`

Verified via Mozilla/Chromium bug trackers + RFC 6761 §6.3:

| OS | Browser | `*.tenancy.localhost` → 127.0.0.1? | Notes |
|----|---------|------------------------------------|-------|
| macOS | Chrome/Chromium/Edge | ✓ Out of the box | RFC 6761 §6.3 implemented |
| macOS | Safari | ✗ Historically; ~Safari 17+ improving | Long history of strict DNS — `/etc/hosts` is the safe path [CITED: https://github.com/ipfs/go-ipfs/issues/7527] |
| macOS | Firefox | ✓ Since FF 84+ (2020) | Bug 1433933 was the long-standing issue; resolved [CITED: https://bugzilla.mozilla.org/show_bug.cgi?id=1433933] |
| Linux (Ubuntu/Fedora) | Chrome/Chromium | ✓ Out of the box | Same as macOS |
| Linux | Firefox | ✓ Since FF 84+ | Same as macOS |
| Windows (native) | Chrome/Edge | ✓ Out of the box | Windows 10/11's DNS resolver also supports `.localhost` since ~2020 [CITED: https://learn.microsoft.com/en-us/aspnet/core/test/localhost-tld] |
| Windows + WSL2 | Chrome on Windows-host hitting WSL2 port | ⚠ Quirky | Loopback forwarding from WSL2 → Windows-host works for `localhost`, but `*.localhost` resolution depends on Windows resolver — usually fine, but the `/etc/hosts` line in WSL2 (NOT Windows-host hosts file) is the safety net. |

**Confidence:** HIGH (Chrome/Firefox/Linux); MEDIUM (Safari — best to assume it does NOT work for the demo's 2-minute promise); MEDIUM (WSL2 — works most of the time but `/etc/hosts` fallback is needed).

**Three-step ladder (CONTEXT D-12) is correct and minimal.** Recommend the planner write the README such that:
1. **First** show the curl `Host:` snippets — works on ALL platforms including headless CI.
2. **Then** the `/etc/hosts` line for Safari + WSL2 users.
3. **Last** the browser-native happy path (covers ~80% of dev machines: Chrome + macOS/Linux/Windows).

### `*.localhost` RFC 6761 Trivia (for planner's README copy)

RFC 6761 §6.3 says applications "MAY" recognize `.localhost` names as special. It does NOT mandate. The OS's DNS resolver is the arbiter:
- glibc / musl / Windows DNS: increasingly do the right thing in 2024+
- Older systems / corporate DNS that strips `.localhost`: break
- Browsers: implement their own DNS layer (Chrome's MDNS / Firefox DOH) which adds another layer of variability

This is why the curl-with-`Host:`-header path is the ground truth — it skips DNS entirely.

## Cross-Phase Integration Map

### Phase 17: `OriginHeaderResolver`

- **Class:** `Tenancy\Bundle\Resolver\OriginHeaderResolver` ([VERIFIED] — src/Resolver/OriginHeaderResolver.php)
- **Service ID:** `tenancy.resolver.origin` (registered conditionally in `TenancyBundle::loadExtension` when `'origin'` is in `tenancy.resolvers`)
- **Priority:** 25 (between `HostResolver` 30 and `HeaderResolver` 20)
- **Header read:** `Origin` (constant `OriginHeaderResolver::HEADER_NAME`)
- **Behavior:** Returns `null` on OPTIONS preflight; returns `null` on absent/unparseable Origin; matches origin against compile-time-normalized allow-list (exact host match OR `*.suffix` wildcard); logs warning if `X-Tenant-ID` resolves to different tenant.
- **Compile-time validation:** `OriginHeaderResolverConfigPass` throws on empty allow-list, mid-string wildcards, malformed URLs, missing slugs on non-wildcard entries.

**Demo `tenancy.yaml` config shape:**
```yaml
tenancy:
    driver: database_per_tenant
    database:
        enabled: true
    landlord_connection: landlord
    host:
        app_domain: tenancy.localhost
    resolvers: [host, origin, header, query_param, console]
    origin:
        allow_list:
            # Wildcard entry — slug derived from leftmost label at runtime
            - { origin: 'https://*.tenancy.localhost' }
            - { origin: 'http://*.tenancy.localhost' }
```

**Smoke test curl for D-08:**
```bash
# Resolves via Origin (Host is landlord, so HostResolver returns null)
curl --fail --max-time 10 \
     -H 'Host: tenancy.localhost' \
     -H 'Origin: https://acme.tenancy.localhost' \
     http://localhost/
# Expect: body contains "Acme Corporation"
```

### Phase 18: `tenancy:install`

- **Class:** `Tenancy\Bundle\Command\TenancyInstallCommand` ([VERIFIED])
- **Command name:** `tenancy:install`
- **Flags:** `--force`, `--dry-run`, `--with-mailer`
- **Demo's relationship to this command:** README copy ONLY (D-10). Demo's `config/bundles.php` is hand-committed. The README install section reads:
  > "To add Tenancy Bundle to YOUR app: `composer require danplaton4/tenancy-bundle` then `bin/console tenancy:install` (auto-registers the bundle and scaffolds `config/packages/tenancy.yaml`). The demo wires its bundles by hand because we want the demo's source to be diff-stable."

### Phase 19: `TenantDataCollector` (Profiler tab)

- **Class:** `Tenancy\Bundle\Profiler\TenantDataCollector` ([VERIFIED])
- **`getName()`:** `'tenancy'` — this is the panel ID in the WDT
- **Template:** `@Tenancy/Collector/tenant.html.twig`
- **Data shape (8 + optional mailer subsection):** `state`, `slug`, `tenant_label`, `driver`, `connection_name`, `resolved_by`, `bootstrappers`, `error`, optional `mailer` (10 keys including `from`, `reply_to`, `dsn_redacted`, `badge`).
- **Service registration:** Only when `kernel.debug = true` (via `services_dev.php` conditional import in `TenancyBundle::loadExtension`).
- **Demo enablement:** Include `symfony/web-profiler-bundle` in `require-dev`; ensure `APP_ENV=dev` in `compose.yaml`. The panel is automatic.

**README copy reference:**
> "Open `http://acme.tenancy.localhost/` and click the Tenancy icon in the WDT. The panel shows `slug: acme`, `driver: database_per_tenant`, `connection_name: tenant`, `resolved_by: Tenancy\Bundle\Resolver\HostResolver`, and the bootstrappers that ran for this request."

### Phase 20: Mailer

- **Bootstrapper class:** `Tenancy\Bundle\Bootstrapper\MailerBootstrapper` ([VERIFIED])
- **Bootstrapper priority:** -20 (runs AFTER DB/Doctrine on boot, BEFORE them on clear — so SMTP sockets close before EM reset)
- **Tenant fields:** `getMailerDsn(): ?string`, `getMailerFrom(): ?string`, `getMailerReplyTo(): ?string` — all on `Tenancy\Bundle\TenantInterface`. Bundle's default `Tenancy\Bundle\Entity\Tenant` provides them as nullable columns ([VERIFIED] — src/Entity/Tenant.php lines 35–42).
- **Demo seeding (D-09):**
  ```php
  $tenant->setMailerDsn('smtp://mailpit:1025');
  $tenant->setMailerFrom('noreply@acme.example');
  $tenant->setMailerReplyTo('support@acme.example');
  ```
- **Demo route:** `POST /_demo/send-test-mail` — controller calls `$mailer->send(new Email()->subject('Test from {tenant}')->text('Hello'))`. The bootstrapper + transport decorator handle DSN/From/Reply-To injection. No tenant-specific logic in the controller.
- **Mailer config:** No `transport_cache_size` change needed — default 32 is plenty for 3 tenants. `tenancy.mailer.async: false` (demo dispatches sync — async would need a Messenger transport which adds scope).

**Demo `mailer.yaml`:**
```yaml
framework:
    mailer:
        # Default DSN is overridden per-tenant by TenantAwareTransportsDecorator
        dsn: 'smtp://mailpit:1025'
```

### Phase 5 (carried context): `BootstrapperChain`

- **Class:** `Tenancy\Bundle\Bootstrapper\BootstrapperChain`
- **Method signature:** `boot(TenantInterface $tenant)` and `clear()` (no args; reverses order)
- **No `executeAs()` method exists.** CONTEXT D-06's reference to `TenantContextOrchestrator::executeAs($tenant, fn() => ...)` is a fiction — the actual pattern is shown in `TenantMigrateCommand` (see "Critical Discrepancy" section).

### Tenant Entity & `brandColor`

The bundle's default `Tenancy\Bundle\Entity\Tenant` ([VERIFIED] — src/Entity/Tenant.php) has:
- `slug`, `name`, `domain`, `connectionConfig` (JSON), `isActive`, `mailerDsn`, `mailerFrom`, `mailerReplyTo`, `createdAt`, `updatedAt`
- **No `brandColor`.**

**Recommendation (resolving Claude's-Discretion item in CONTEXT):** Demo defines a subclass:

```php
// examples/saas/src/Entity/DemoTenant.php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Tenancy\Bundle\Entity\Tenant;

#[ORM\Entity]
#[ORM\Table(name: 'tenancy_tenants')]  // reuse the same table
class DemoTenant extends Tenant
{
    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    private ?string $brandColor = null;

    public function getBrandColor(): ?string { return $this->brandColor; }
    public function setBrandColor(?string $c): self { $this->brandColor = $c; return $this; }
}
```

Then set `tenancy.tenant_entity_class: App\Entity\DemoTenant` in `tenancy.yaml`. Doctrine STI is awkward but for a single-table inheritance with one subclass + one extra column, this works without `@InheritanceType` shenanigans — Doctrine treats `DemoTenant` as a separate entity mapped to the same table. The simpler alternative is to NOT extend the bundle's Tenant at all and instead define a fully demo-local `App\Entity\Tenant` implementing `TenantInterface` from scratch — that's cleaner and the planner should pick this path if entity inheritance feels off.

## Architecture & Patterns

### System Architecture Diagram

```
                ┌──────────────────────────────────────────┐
                │   bin/smoke.sh (host)  /  CI runner       │
                │   curl --fail -H "Host: <slug>..." :80    │
                └──────────────┬───────────────────────────┘
                               │  HTTP
                               ▼
                      ┌─────────────────┐
                      │   localhost:80  │   (Docker port publish)
                      │   localhost:443 │
                      └────────┬────────┘
                               │
                               ▼
   ┌─────────────────────────────────────────────────────────┐
   │  FrankenPHP container (embeds Caddy)                     │
   │  ┌──────────────────────────────────────────────────┐    │
   │  │  Caddyfile vhost: *.tenancy.localhost            │    │
   │  │     tls internal → Caddy internal CA wildcard    │    │
   │  │     root * /app/public                           │    │
   │  │     php_server (FrankenPHP module)               │    │
   │  └────────────────────┬─────────────────────────────┘    │
   │                       ▼                                   │
   │  ┌────────────────────────────────────────────────┐       │
   │  │  Symfony 7.4 kernel (APP_ENV=dev)               │       │
   │  │  - TenantContextOrchestrator (kernel.request 20)│       │
   │  │  - ResolverChain                                │       │
   │  │      HostResolver(30) → OriginResolver(25) → …  │       │
   │  │  - BootstrapperChain.boot($tenant)              │       │
   │  │      DatabaseSwitchBootstrapper                 │       │
   │  │      DoctrineBootstrapper                       │       │
   │  │      MailerBootstrapper                         │       │
   │  │  - Controller (Landlord / Tenant / DemoMail)    │       │
   │  └────────────┬────────────────────┬───────────────┘       │
   │               │                    │                       │
   └───────────────┼────────────────────┼───────────────────────┘
                   │                    │
                   ▼                    ▼
        ┌───────────────────┐     ┌─────────────────┐
        │  MariaDB 11       │     │  Mailpit         │
        │  • landlord DB    │     │  SMTP :1025     │
        │  • tenant_acme    │     │  UI   :8025     │
        │  • tenant_globex  │     │                 │
        │  • tenant_initech │     │  catches:       │
        │                   │     │   From: noreply │
        │  healthcheck:     │     │   @<slug>.ex    │
        │  mysqladmin ping  │     └─────────────────┘
        └───────────────────┘
```

### Recommended Project Structure

```
examples/saas/
├── bin/
│   ├── smoke.sh                          # host-side curl smoke (chmod +x)
│   └── entrypoint.sh                     # waits-for-DB, runs seeding, exec frankenphp
├── compose.yaml                          # FrankenPHP + MariaDB + Mailpit
├── Dockerfile                            # FrankenPHP base + composer install + entrypoint
├── Caddyfile                             # vhosts: *.tenancy.localhost + tenancy.localhost
├── composer.json                         # path-repo to ../../, require @dev
├── .env, .env.example                    # APP_ENV=dev, DATABASE_URL_LANDLORD, MAILER_DSN…
├── .dockerignore                         # exclude var/, vendor/, etc.
├── .gitignore                            # var/, vendor/, .env.local
├── config/
│   ├── bundles.php                       # TenancyBundle, DoctrineBundle, etc. (hand-wired)
│   ├── routes.yaml                       # landlord '/', tenant '/', /_demo/send-test-mail, /health
│   └── packages/
│       ├── tenancy.yaml                  # driver, host.app_domain, resolvers (incl. origin)
│       ├── doctrine.yaml                 # landlord + tenant connections + entity managers
│       ├── mailer.yaml                   # default DSN to mailpit (overridden per-tenant)
│       ├── framework.yaml                # session, secret, etc.
│       └── web_profiler.yaml             # WDT enabled when APP_ENV=dev
├── src/
│   ├── Command/
│   │   └── SeedDemoCommand.php           # NEW: CREATE DATABASE + schema:create + fixtures per tenant
│   ├── Controller/
│   │   ├── LandlordController.php        # GET /  (when no tenant resolved)
│   │   ├── TenantController.php          # GET /  (when tenant resolved)
│   │   ├── DemoMailController.php        # POST /_demo/send-test-mail
│   │   └── HealthController.php          # GET /health  (200 OK; used by docker healthcheck)
│   ├── Entity/
│   │   ├── DemoTenant.php                # extends Tenancy\Bundle\Entity\Tenant + brandColor
│   │   └── Post.php                      # tenant-scoped entity (lives in tenant DB)
│   ├── Repository/
│   │   └── PostRepository.php
│   └── DataFixtures/
│       └── LandlordTenantsFixture.php    # seeds 3 DemoTenants in landlord DB only
├── templates/
│   ├── base.html.twig                    # inline CSS w/ accent-color CSS var
│   ├── landlord/index.html.twig
│   └── tenant/index.html.twig
├── public/
│   └── index.php                         # Symfony front controller
└── README.md                             # walkthrough + 3-step fallback ladder + Profiler/Mailpit copy
```

### Pattern 1: Per-tenant Database Provisioning (replaces broken D-05)

```php
// examples/saas/src/Command/SeedDemoCommand.php
#[AsCommand(name: 'app:seed-demo', description: 'Create tenant DBs, schemas, and seed posts.')]
final class SeedDemoCommand extends Command
{
    public function __construct(
        private readonly Connection $landlordConnection,   // DBAL landlord
        private readonly TenantProviderInterface $provider,
        private readonly TenantContext $tenantContext,
        private readonly BootstrapperChain $bootstrapperChain,
        private readonly EntityManagerInterface $tenantEm,
    ) { parent::__construct(); }

    protected function execute(...): int {
        // Step 1: ensure landlord schema + tenants exist
        // (LandlordTenantsFixture is run separately via doctrine:fixtures:load --em=landlord)

        // Step 2: per tenant — create DB, create schema, seed posts
        foreach ($this->provider->findAll() as $tenant) {
            $dbName = 'tenant_' . $tenant->getSlug();
            $this->landlordConnection->executeStatement("CREATE DATABASE IF NOT EXISTS `$dbName`");

            $this->tenantContext->setTenant($tenant);
            $this->bootstrapperChain->boot($tenant);
            try {
                // Run schema-create against the tenant EM (now pointed at tenant_<slug>)
                $schemaTool = new SchemaTool($this->tenantEm);
                $schemaTool->createSchema([$this->tenantEm->getClassMetadata(Post::class)]);

                // Seed posts (inline, since fixtures-bundle can't iterate per tenant cleanly)
                foreach ($this->seedDataFor($tenant) as $postData) {
                    $this->tenantEm->persist(new Post(...$postData));
                }
                $this->tenantEm->flush();
                $this->tenantEm->clear();
            } finally {
                $this->bootstrapperChain->clear();
                $this->tenantContext->clear();
            }
        }
        return Command::SUCCESS;
    }
}
```

### Pattern 2: Caddyfile (single file, wildcard vhost)

```caddy
# examples/saas/Caddyfile
{
    # frankenphp-specific options
    frankenphp
    # Use Caddy internal CA for *.tenancy.localhost (no Let's Encrypt)
    auto_https disable_redirects
}

# Wildcard + apex — same handler
*.tenancy.localhost, tenancy.localhost, localhost {
    tls internal

    root * public/
    encode zstd br gzip

    # Symfony front-controller pattern
    php_server
}
```

[CITED: https://caddyserver.com/docs/caddyfile/patterns — Symfony pattern; https://frankenphp.dev/docs/symfony/]

### Pattern 3: Composer Path Repository in Docker

The trap: `symlink: true` creates an absolute symlink that breaks inside the container because the symlink target (the bundle source) lives at a path that doesn't exist in the container's filesystem.

**Solution (verified pattern):** Bind-mount the bundle source at the SAME path inside the container as it lives on the host, OR (cleaner) use a relative path repo and bind-mount.

```yaml
# compose.yaml
services:
  php:
    build:
      context: .
      # Bundle source is at ../../, so the build context must include it
      # OR composer install runs at runtime instead of build time
    volumes:
      # Bind-mount the bundle source at /bundle (matches relative path from /app)
      - ../../:/bundle:ro            # bundle source (read-only)
      - .:/app                       # demo source
```

```json
// examples/saas/composer.json — uses a path inside the container
{
  "repositories": [
    { "type": "path", "url": "/bundle", "options": { "symlink": true } }
  ]
}
```

This works because `/bundle` exists both at runtime (bind mount) and at build time (via `COPY` in the Dockerfile if `composer install` runs at build).

**Alternative pattern (Dockerfile-only):** Copy the bundle source into the image at build time, then `composer install` from path. Simpler but loses the dev-loop benefit. Recommend the bind-mount approach.

### Pattern 4: OPcache Dev Loop for Bundle Source Edits

Per CONTEXT "Critical Edge: Bundle-Source Dev Loop" — the demo container needs OPcache configured to revalidate frequently so users editing `src/**` in the bundle see changes on next request.

```ini
; examples/saas/docker/php.ini (or in Dockerfile)
opcache.enable=1
opcache.validate_timestamps=1
opcache.revalidate_freq=0   ; check every request (dev only)
```

Production-shaped numbers (`revalidate_freq=60`, `validate_timestamps=0`) are explicitly OOS.

### Anti-Patterns to Avoid

- **Calling `tenancy:migrate --create-dbs`** — the flag does not exist. Use `app:seed-demo` (Pattern 1) instead.
- **`TenantContextOrchestrator::executeAs($tenant, ...)`** — method does not exist. Use the explicit `setTenant → boot → ... → clear` pattern from `TenantMigrateCommand`.
- **Mounting a host symlink as the path repo target** — Docker either follows it (mounting random host directories) or rejects it. Use a bind mount that resolves inside the container's filesystem.
- **Putting CRUD routes in the demo** — D-01 deliberately keeps the demo read-only. `/_demo/send-test-mail` is the ONE allowed write (and it writes to Mailpit, not to a tenant DB).
- **Using `doctrine/migrations` in the demo** — CONTEXT D-05 explicitly says schema-create only. Migrations add dep weight + bootstrapping complexity.
- **Running `composer install` on the host** — image must be self-contained for CI. `composer install` runs in the Dockerfile.
- **Hard-coding `SERVER_NAME` to a specific subdomain** in the FrankenPHP env — FrankenPHP / Caddy needs to listen on the wildcard, not a single host.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Wildcard SSL for `*.tenancy.localhost` | mkcert + manual cert install in Caddyfile | Caddy `tls internal` directive | Caddy auto-generates and rotates the cert; mkcert adds a host-side install step. |
| Wait-for-DB in entrypoint | `sleep 10 && bin/console …` | `mysqladmin ping --silent` retry loop OR `depends_on: condition: service_healthy` | Fixed sleeps fail on slow CI; healthcheck is the source of truth. |
| HTTP retry in smoke script | hand-rolled `while curl …; sleep 1; done` | `curl --retry 5 --retry-all-errors --retry-connrefused` | Single curl invocation handles transient errors with backoff. |
| Per-tenant fixture iteration | Multi-pass `doctrine:fixtures:load --em=tenant` (broken for per-tenant DBs that don't share an EM at fixture-load time) | Custom `app:seed-demo` command using `BootstrapperChain::boot()` per tenant | Fixtures bundle has known multi-connection gaps [CITED: https://github.com/doctrine/DoctrineFixturesBundle/issues/35]. |
| Symbolic-link path repo in Docker | Hope `symlink: true` "just works" with host paths | Bind-mount bundle source at a path that exists inside the container | Host symlinks resolve to host paths that don't exist in the container. |

**Key insight:** Every problem above is well-trodden by FrankenPHP / Caddy / Symfony Docker users. The pattern library is mature; just pick the idiomatic option.

## Runtime State Inventory

> Phase 21 is greenfield (creates `examples/saas/` from scratch). No rename/refactor — but a few stateful concerns to verify:

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | None — fresh databases, no pre-existing tenant data to migrate | None |
| Live service config | New CI workflow `.github/workflows/demo-smoke.yml` becomes a required status check after first green run | After first successful CI run on master, GitHub repo settings must add `demo-smoke` to "Require status checks to pass before merging" — this is a Repo-Admin action, not code. Document in SUMMARY.md for human follow-up. |
| OS-registered state | None | None |
| Secrets/env vars | New env vars: `MARIADB_ROOT_PASSWORD`, `MARIADB_USER`, `MARIADB_PASSWORD`, `TENANCY_DOMAIN_BASE`. Defaults committed to `.env`; secrets-free demo (no SOPS, no `.env.local` required). | Document in README "Configuration" section. |
| Build artifacts | New `examples/saas/var/`, `examples/saas/vendor/` — must be added to `.gitignore` (root-level or per-directory). Dockerfile must `COPY` correctly and entrypoint must `chmod` `var/` writable. | Update root `.gitignore` to ignore `examples/saas/var/` and `examples/saas/vendor/`. |

## Common Pitfalls

### Pitfall 1: Caddy auto-HTTPS redirects break HTTP-default walkthrough

**What goes wrong:** Caddy by default redirects HTTP → HTTPS for any host it has a cert for. The README's "open `http://acme.tenancy.localhost/`" instruction silently 301s to HTTPS and hits the trust-store wall.

**Why it happens:** Caddy's `auto_https` is on by default and includes HTTP→HTTPS redirect.

**How to avoid:** Add `auto_https disable_redirects` in the global Caddyfile options block (Pattern 2 above shows this). This keeps HTTPS available on :443 but lets HTTP on :80 stand on its own.

**Warning signs:** First curl in smoke.sh returns 301 with `Location: https://...`.

### Pitfall 2: `mysqladmin ping` returns success before MariaDB is ready for queries

**What goes wrong:** `mysqladmin ping` returns success as soon as the server accepts TCP, BUT before it's done initializing the data directory on first boot. `tenancy:migrate` (or schema-create) then crashes with "unknown database" or "connection lost".

**Why it happens:** MariaDB has a multi-second initialization window where it accepts connections but isn't fully ready.

**How to avoid:** Use the MariaDB image's bundled `healthcheck.sh` script: `test: ['CMD', 'healthcheck.sh', '--connect', '--innodb_initialized']` [CITED: https://hub.docker.com/_/mariadb]. OR add a `mysqladmin ping && mariadb -uroot -p$MARIADB_ROOT_PASSWORD -e 'SELECT 1'` two-step check.

**Warning signs:** Entrypoint logs show "connection refused" or "unknown database" on first boot but works on second `docker compose up`.

### Pitfall 3: Composer path repo `symlink: true` doesn't reflect bundle edits inside container

**What goes wrong:** `composer install` creates a symlink at `vendor/danplaton4/tenancy-bundle` pointing to an absolute host path (`/Users/...`). Inside the container, that path doesn't exist. Either Composer fails outright OR the symlink dangles silently and PHP autoloader crashes.

**Why it happens:** `symlink: true` is host-aware, not container-aware.

**How to avoid:** Bind-mount the bundle source at a path inside the container, and reference THAT path in the composer.json (Pattern 3 above). Recent Composer (2.x) has `relative: true` option for symlinks, but Docker's filesystem boundary still applies.

**Warning signs:** `composer install` log says "Symlinked from /Users/..." in the container build output (should say `/bundle` or similar).

### Pitfall 4: Firefox doesn't trust Caddy's internal CA even after `caddy trust`

**What goes wrong:** `docker compose exec caddy caddy trust` installs the CA into the system trust store. Chrome/Safari pick it up. Firefox uses its OWN trust store and continues showing CERT_AUTHORITY_INVALID.

**Why it happens:** Firefox bypasses system trust on Linux (and partially on macOS) for historical reasons.

**How to avoid:** README's HTTPS section documents the Firefox-specific import: `about:preferences#privacy` → View Certificates → Authorities → Import → select `~/.local/share/caddy/pki/authorities/local/root.crt` (or whichever path Caddy stored it at). [CITED: https://caddyserver.com/docs/automatic-https — §"Local HTTPS troubleshooting"]

### Pitfall 5: `docker compose up -d --wait` doesn't wait for the entrypoint script's seed step

**What goes wrong:** `--wait` waits for healthchecks to return healthy. The FrankenPHP healthcheck (curl `/health`) goes healthy as soon as the PHP server boots — BEFORE `app:seed-demo` finishes seeding. The smoke script then hits a tenant URL and gets a 500 because the tenant DB doesn't exist yet.

**Why it happens:** Healthcheck is an HTTP-layer signal, not an app-layer "ready to serve real traffic" signal.

**How to avoid:** Two options:
1. Make the healthcheck depend on seed completion — write a sentinel file (e.g. `/app/var/seeded`) from `app:seed-demo` and have `/health` controller check for it.
2. Run `app:seed-demo` BEFORE starting FrankenPHP in the entrypoint (`set -e; bin/console app:seed-demo; exec frankenphp run`). This way the healthcheck only goes green after seed completion.

**Recommendation:** Option 2. Simpler; deterministic.

**Warning signs:** Smoke script intermittently hits 500s in CI but passes locally where the user manually waits.

### Pitfall 6: WSL2 Windows-side browser can't reach `acme.tenancy.localhost` on Linux container ports

**What goes wrong:** Docker Desktop forwards `localhost` from Windows host into WSL2 distro. `*.localhost` resolution depends on Windows DNS resolver. On some corporate networks this is intercepted by a proxy that NXDOMAINs `.localhost`.

**Why it happens:** Windows DNS resolver behavior is inconsistent; corporate DNS proxies are worse.

**How to avoid:** README's `/etc/hosts` fallback applies to Windows `C:\Windows\System32\drivers\etc\hosts`, not the WSL2 `/etc/hosts`. Document this explicitly.

**Warning signs:** User reports "Chrome can't find acme.tenancy.localhost" but `curl http://localhost/ -H "Host: acme.tenancy.localhost"` from WSL2 works fine.

## Code Examples

### Smoke script (bin/smoke.sh)

```bash
#!/usr/bin/env bash
# bin/smoke.sh — DNS-independent demo smoke test.
# Runs on host (CI runner or dev machine). Caddy must publish :80 on localhost.
set -euo pipefail

CURL='curl --fail --max-time 10 --retry 5 --retry-all-errors --retry-connrefused -sS'
BASE='http://localhost'

# Wait for /health to be ready (max 30s)
echo "==> Waiting for app readiness…"
for i in $(seq 1 30); do
    if curl -sf --max-time 2 "$BASE/health" >/dev/null 2>&1; then
        echo "    ready"
        break
    fi
    sleep 1
    [ "$i" -eq 30 ] && { echo "    timeout"; exit 1; }
done

# Landlord index — should list all three tenant slugs
echo "==> Landlord root"
body=$($CURL -H "Host: tenancy.localhost" "$BASE/")
for slug in acme globex initech; do
    grep -q "$slug" <<<"$body" || { echo "FAIL: landlord index missing $slug"; exit 1; }
done

# Per-tenant marker assertions
echo "==> Per-tenant landing pages"
declare -A markers=(
    [acme]='Acme Corporation'
    [globex]='Globex Industries'
    [initech]='Initech LLC'
)
for slug in "${!markers[@]}"; do
    body=$($CURL -H "Host: $slug.tenancy.localhost" "$BASE/")
    grep -q "${markers[$slug]}" <<<"$body" || {
        echo "FAIL: $slug page missing '${markers[$slug]}'"; exit 1;
    }
done

# Origin-resolver path (Phase 17 invariant)
echo "==> OriginHeaderResolver"
body=$($CURL \
    -H "Host: tenancy.localhost" \
    -H "Origin: https://acme.tenancy.localhost" \
    "$BASE/")
grep -q 'Acme Corporation' <<<"$body" || {
    echo "FAIL: Origin-resolver did not resolve acme"; exit 1;
}

echo "==> All smoke assertions PASSED"
```

### CI workflow (.github/workflows/demo-smoke.yml)

```yaml
name: demo-smoke

on:
  push:
    branches: [master]
  pull_request:
    branches: [master]

jobs:
  smoke:
    runs-on: ubuntu-latest
    timeout-minutes: 10
    defaults: { run: { working-directory: examples/saas } }
    steps:
      - uses: actions/checkout@v4
      - name: Build and start demo
        run: docker compose up -d --wait --build
      - name: Show container logs on failure
        if: failure()
        run: docker compose logs
      - name: Run smoke
        run: bash bin/smoke.sh
      - name: Tear down
        if: always()
        run: docker compose down -v
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| PHP-FPM + nginx in separate containers | FrankenPHP (embeds Caddy) in one container | FrankenPHP stable 2023 | Smaller compose, faster boot, no nginx config file |
| mkcert host-side cert install | Caddy `tls internal` automatic wildcard | Caddy 2.5+ | Zero host-side cert setup; just `caddy trust` for browser |
| `wait-for-it.sh` script for DB readiness | Docker Compose `depends_on: condition: service_healthy` + `up --wait` | Compose v2 (2022+) | No bash dependency in entrypoint; idiomatic healthchecks |
| Hand-rolled subdomain DNS in /etc/hosts | `*.tenancy.localhost` (RFC 6761) with browser-native resolution | Chromium 2018+, Firefox 84+ (2020) | Zero host-side config for Chrome/FF on macOS/Linux/Windows |
| `doctrine/migrations` for schema | `doctrine:schema:create` for demos | always-true for demos | Migrations are for evolving schemas; demo schema is fixed per release |

**Deprecated/outdated:**
- Symfony Flex demo pattern (we use plain skeleton)
- `composer create-project symfony/website-skeleton` (deprecated 5.x; use `symfony/skeleton` and add what you need)

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `tenancy:migrate --create-dbs` does not exist; the planner must add a custom seeding command | Critical Discrepancy | If a `--create-dbs` flag is hidden somewhere (different command, plan I missed), the recommendation is over-engineered. Verified via grep + reading the full TenantMigrateCommand — confidence HIGH. |
| A2 | Demo-local `App\Entity\DemoTenant extends Tenant` works with Doctrine on the same `tenancy_tenants` table | Tenant Entity & brandColor | Doctrine single-table inheritance has edge cases; if the planner finds it cleaner to define `App\Entity\Tenant` from scratch implementing TenantInterface, that's also valid. |
| A3 | Mailpit on `axllent/mailpit:latest` is current and stable | Standard Stack | Pinning to `:v1.x` is safer than `:latest`; planner should pick a specific tag. |
| A4 | FrankenPHP's embedded Caddy honors the standard Caddyfile syntax for `tls internal` and `*.tenancy.localhost` wildcards | Domain Research | Cross-verified with FrankenPHP docs (Symfony integration page) and Caddy docs — confidence HIGH. |
| A5 | Caddy's internal CA + wildcard for `*.tenancy.localhost` does NOT require any DNS-01 challenge or external acme-dns helper | Domain Research | Verified: internal CA is local-only; no DNS challenge needed. Confidence HIGH (Caddy official docs). |
| A6 | The bundle's existing `MailerBootstrapper` works correctly when the user POSTs to `/_demo/send-test-mail` with synchronous Mailer dispatch (no Messenger transport configured) | Cross-Phase Integration: Mailer | Sync dispatch is the default and is the simplest path; Phase 20 acceptance tests cover both sync + async, so this should work. Confidence HIGH. |
| A7 | The CI workflow `docker compose up -d --wait` is sufficient when the entrypoint blocks on seeding before exec'ing FrankenPHP (Pitfall 5 mitigation) | CI Smoke Script Design | If the entrypoint's seeding step takes >5 min the GitHub Actions job will hit the 10-min job timeout. Demo should boot in <2 min cold; well under. |
| A8 | Three tenants is sufficient to satisfy DEMO-01 acceptance line 1's "two tenant" minimum | Phase Requirements | Three ≥ two; safe. |
| A9 | The README walkthrough does not need to demonstrate `tenancy:install` working — D-10 says reference in copy only | Cross-Phase Integration: Phase 18 | If verifier asks "but does the install command actually work in the demo?" the answer is no, and that's intentional. |

## Open Questions

1. **`brandColor` — bundle entity or demo-local entity?**
   - What we know: CONTEXT defers this to planner. Bundle's default `Tenant` has no `brandColor` column.
   - What's unclear: Adding `brandColor` to the BUNDLE'S `Tenant` entity bloats the bundle for a demo concern. But a demo-local subclass introduces a Doctrine STI footnote.
   - Recommendation: Demo defines `App\Entity\DemoTenant extends Tenant` mapped to the SAME table — works because Doctrine treats it as a separate entity; demo's `tenancy.tenant_entity_class` config points at `App\Entity\DemoTenant`. If STI feels off, define `App\Entity\Tenant` from scratch implementing `TenantInterface` — a bit more code but cleaner inheritance.

2. **FrankenPHP worker mode vs classic dispatch.**
   - What we know: FrankenPHP supports both; worker mode is faster but holds Symfony's kernel + DBAL connections across requests.
   - What's unclear: Database-per-tenant + worker mode could leak connections between tenants if `TenantConnection` doesn't reset on the request boundary (it should, via `EntityManagerResetListener`, but this is untested in worker mode).
   - Recommendation: Classic dispatch for the demo. Worker mode is a performance optimization; demo prioritizes correctness clarity. Phase 22 or v0.4 can revisit if there's a "demo runs in worker mode" success story.

3. **Should the demo's `/health` endpoint require a resolved tenant?**
   - What we know: D-15 uses `/health` for the Docker healthcheck. CONTEXT says "trivial /health controller that 200s once schema + fixtures are loaded."
   - What's unclear: The Docker healthcheck runs `curl http://localhost/health` from inside the container. There's no `Host:` header → defaults to `localhost`. With `host.app_domain: tenancy.localhost`, the HostResolver returns null for "localhost", so the route runs with no tenant. Good.
   - Recommendation: Confirm by writing the controller as a simple `return new Response('OK')` and verifying with a unit-style assertion (or just relying on smoke).

4. **Should `compose.yaml` use port 80 or 8080 by default?**
   - What we know: Port 80 needs `sudo` on some Linux distros for non-root. Port 8080 doesn't.
   - What's unclear: User-facing friction — typing `http://acme.tenancy.localhost:8080` is uglier than `http://acme.tenancy.localhost`.
   - Recommendation: Port 80 default; document a `.env` override for `PORT_HTTP=8080` if port 80 conflicts. CI uses port 80 (GitHub runners are fine with this).

## Environment Availability

| Dependency | Required By | Available (target env) | Version | Fallback |
|------------|------------|------------------------|---------|----------|
| Docker / Docker Desktop | Compose stack | Expected — user-installed | 20+ | None — demo requires Docker |
| Docker Compose v2 | `--wait` flag, `condition: service_healthy` | Bundled with modern Docker installs | v2+ | None — `--wait` is v2-only |
| curl | smoke.sh, CI healthcheck | Universal (preinstalled on GH runners, macOS, most Linux) | 7.76+ recommended (for `--fail-with-body`) | None — assume preinstalled |
| bash | smoke.sh | Universal | 4+ | None |
| Git | clone the repo | Pre-req | any | None |
| Composer | runs INSIDE Dockerfile | Bundled in FrankenPHP image | 2.x | None — image-bundled |
| PHP | runs INSIDE FrankenPHP image | Bundled | 8.2+ | None — image-bundled |

**Missing dependencies with no fallback:** Docker — but the demo's whole premise is "docker compose up". A non-Docker fallback (Symfony CLI + local MariaDB + Symfony serve + manual cert) is explicitly out of scope; users who can't run Docker should use Symfony CLI directly against the bundle docs.

**Missing dependencies with fallback:** None.

## Validation Architecture

> The bundle's `.planning/config.json` has `workflow.nyquist_validation: true`. This section seeds the Phase 21 VALIDATION.md.

### Test Framework

| Property | Value |
|----------|-------|
| Framework | bash + curl (smoke level) + bundle's existing PHPUnit 11 suite (regression level) |
| Config file | `examples/saas/bin/smoke.sh` (smoke); root `phpunit.xml.dist` (regression — unchanged) |
| Quick run command | `cd examples/saas && docker compose up -d --wait && bash bin/smoke.sh && docker compose down -v` |
| Full suite command | `vendor/bin/phpunit && cd examples/saas && docker compose up -d --wait && bash bin/smoke.sh` |

**Key principle:** Phase 21 deliberately does NOT add new unit/integration tests to the bundle's PHPUnit suite. The smoke script + GitHub workflow ARE the tests. The bundle's own isolation invariants are covered by the existing integration suite (Phases 1–20).

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| DEMO-01.1 | FrankenPHP + Caddy + MariaDB 11 composition, single `docker compose up` | smoke | `docker compose up -d --wait` (exit 0 = all healthchecks pass) | ❌ Phase 21 creates |
| DEMO-01.2 | `*.tenancy.localhost` subdomain routing via Caddy + internal CA | smoke (HTTP, no cert) | `curl -H "Host: acme.tenancy.localhost" http://localhost/` returns 200 + tenant body marker | ❌ Phase 21 creates (bin/smoke.sh) |
| DEMO-01.3 | README documents three-step fallback ladder | manual-only | Human reads `examples/saas/README.md` (verifier checks section headings) | ❌ Phase 21 creates |
| DEMO-01.4 | `bin/smoke.sh` DNS-independent via `Host:` header | smoke | `bash examples/saas/bin/smoke.sh` (asserts per-tenant body markers) | ❌ Phase 21 creates |
| DEMO-01.5 | `.github/workflows/demo-smoke.yml` runs smoke on every push to master; failure blocks merge | CI gate | `gh workflow run demo-smoke` (after merge, GitHub repo settings require the status check) | ❌ Phase 21 creates |
| DEMO-01.6 | Path repo + bundle source change reflects on demo rebuild | manual-only smoke | Edit `src/Resolver/HostResolver.php` to add a marker log, `docker compose restart php`, hit a tenant URL, observe log | ❌ Phase 21 creates (verified manually) |
| (extension) Phase 17 Origin path works | smoke | `curl -H "Origin: https://acme.tenancy.localhost" http://localhost/` returns acme content | ❌ Phase 21 creates (bin/smoke.sh §"OriginHeaderResolver") |
| (extension) Phase 19 Profiler panel renders | manual-only | Open `http://acme.tenancy.localhost/` in browser, observe WDT Tenancy panel | (Phase 19 functional tests already in PHPUnit suite cover the rendering) |
| (extension) Phase 20 Mailer per-tenant From | manual-only | POST `/_demo/send-test-mail` to each tenant subdomain, open `http://localhost:8025`, observe three distinct From addresses | ❌ Phase 21 creates (DemoMailController) |

### Sampling Rate

- **Per task commit (Wave-level):** `vendor/bin/phpunit --group=quick` (existing bundle suite, no demo changes) — fast feedback that bundle is still green during demo construction.
- **Per wave merge:** `vendor/bin/phpunit && cd examples/saas && docker compose up -d --wait && bash bin/smoke.sh && docker compose down -v` — full bundle + smoke.
- **Phase gate (before `/gsd:verify-work`):** Full bundle suite + smoke + manual walkthrough (Phase 19 panel render, Phase 20 Mailpit screenshots).

### Wave 0 Gaps

- [ ] `examples/saas/bin/smoke.sh` — covers DEMO-01.4 (and Phase 17 origin extension)
- [ ] `examples/saas/compose.yaml` with healthchecks — covers DEMO-01.1
- [ ] `.github/workflows/demo-smoke.yml` — covers DEMO-01.5
- [ ] `examples/saas/Caddyfile` — covers DEMO-01.2
- [ ] `examples/saas/README.md` with three-step ladder — covers DEMO-01.3
- [ ] `examples/saas/composer.json` path repo — covers DEMO-01.6

All checks are file-creation in Wave 0/1; no new test framework needed.

## Security Domain

> `security_enforcement` is not explicitly disabled in `.planning/config.json` → enabled by default.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no | Demo has no authn (read-only landing pages); `/_demo/send-test-mail` accepts unauthenticated POST as a deliberate demo affordance (localhost-only). |
| V3 Session Management | no | No sessions; stateless. |
| V4 Access Control | partial | Tenant isolation IS access control — but the bundle's existing integration suite covers this; demo proves it visually via fixture markers. |
| V5 Input Validation | yes | `Host:`/`Origin:` header parsing in resolvers (already validated at bundle level by HostResolver/OriginHeaderResolver). Demo adds NO new validation surface. |
| V6 Cryptography | yes | TLS via Caddy `tls internal` — Caddy handles cert lifecycle; never hand-roll. |
| V7 Errors & Logging | partial | Demo runs in `dev` mode — stack traces visible. This is INTENTIONAL for the WDT demo. Document explicitly that the demo is NOT prod-shaped. |
| V8 Data Protection | partial | Per-tenant DB separation IS data protection. Mailpit captures emails — localhost-only, no leak path. |
| V11 Business Logic | no | No business logic. |
| V12 Files & Resources | no | No file uploads. |
| V13 API & Web Services | partial | `/_demo/send-test-mail` accepts POST without CSRF — acknowledged: demo is local-dev only, document the omission as deliberate. |
| V14 Configuration | yes | `.env.example` committed; `.env.local` is gitignored; secrets-free defaults (Mailpit needs no auth, MariaDB root password is `root` in `.env.example` — fine for localhost). |

### Known Threat Patterns for FrankenPHP + Caddy + MariaDB + Multi-Tenancy

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Cross-tenant data leak via wrong DB connection | Information Disclosure | DEC-DEMO-01: database-per-tenant; `DatabaseSwitchBootstrapper` + `EntityManagerResetListener` (verified by bundle's integration suite). Demo's fixture-distinct markers visually prove this. |
| Caddy CA root cert leakage | Spoofing | Internal CA root is per-machine (not committed). Demo doesn't ship a CA — Caddy generates one on first run inside the container's volume; ephemeral. |
| Open Mailpit web UI exposes test emails | Information Disclosure | Mailpit binds to `localhost:8025` only (default). README says "localhost-only". Do NOT expose port 8025 to `0.0.0.0` in CI or otherwise. |
| `/_demo/send-test-mail` accepts unauthenticated POST | Tampering, Spoofing | Localhost-only by deployment shape. Add a route comment + README note: "This route is deliberately authn-free for the demo. Remove from any non-local deployment." |
| `Host:` header spoofing in smoke script reaches a tenant | Spoofing | THIS IS THE INTENDED BEHAVIOR. Smoke uses Host injection as a feature. The bundle's authn layer (deferred to user) is what protects production. |
| MariaDB exposed on host port 3306 | Information Disclosure | Don't publish the MariaDB port to host in CI; only publish in local dev mode (or never publish at all and use `docker compose exec` for inspection). |

### Project Constraints (from CLAUDE.md)

- **Doctrine dependencies are optional — always guard with `class_exists()` or `interface_exists()`, never hard-import.** Demo MAY hard-require Doctrine (it's an app, not the bundle). But if any demo-local code introspects bundle behavior, keep the guard.
- **`strict_mode` defaults to ON — a data leak across tenants is a security incident.** Demo's `tenancy.yaml` should NOT set `strict_mode: false`. Verify it's absent (default true).
- **Test kernels use `setUpBeforeClass`/`tearDownAfterClass` for kernel lifecycle.** N/A — demo adds no PHPUnit tests.
- **Integration tests use SQLite `:memory:` databases — no external DB required.** N/A — demo's testing IS the smoke script, not PHPUnit.
- **PHP 8.2+, strict_types=1 everywhere, `final class`, `private readonly` constructor injection, `@Symfony` ruleset.** Demo's `src/` follows these conventions.
- **PHPStan level 9.** Demo's `src/` is not part of the bundle's PHPStan run, BUT it should still pass level 9 to model good practice. Optional — planner's call.

## Sources

### Primary (HIGH confidence)
- Bundle source — `src/Resolver/OriginHeaderResolver.php`, `src/Resolver/HostResolver.php`, `src/Command/TenancyInstallCommand.php`, `src/Command/TenantMigrateCommand.php`, `src/Entity/Tenant.php`, `src/Profiler/TenantDataCollector.php`, `src/Bootstrapper/MailerBootstrapper.php`, `src/TenancyBundle.php`, `src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php`
- Caddy automatic HTTPS docs — https://caddyserver.com/docs/automatic-https
- Caddy Caddyfile patterns (Symfony) — https://caddyserver.com/docs/caddyfile/patterns
- FrankenPHP Symfony integration — https://frankenphp.dev/docs/symfony/
- FrankenPHP production deployment — https://frankenphp.dev/docs/production/
- MariaDB official Docker image — https://hub.docker.com/_/mariadb
- Mailpit GitHub — https://github.com/axllent/mailpit
- Existing prototype demos — `/Users/danplaton/dev/hype/tests/symfony74-demo/` (compose.yaml, README, composer.json, tenancy.yaml)

### Secondary (MEDIUM confidence)
- Firefox `*.localhost` bug history — https://bugzilla.mozilla.org/show_bug.cgi?id=1433933
- Safari `*.localhost` issues — https://github.com/ipfs/go-ipfs/issues/7527
- Microsoft .localhost TLD docs — https://learn.microsoft.com/en-us/aspnet/core/test/localhost-tld
- Caddy trust on macOS blog — https://blog.lanzani.nl/2024/trust-local-caddy-certificates-on-macos/
- Composer path repo + Docker gotchas — https://github.com/composer/composer/issues/12074
- Doctrine Fixtures multi-EM issue — https://github.com/doctrine/DoctrineFixturesBundle/issues/35
- Caddy community: FrankenPHP + Symfony — https://caddy.community/t/trying-to-build-symfony-app-with-docker-frankenphp-caddy/23803

### Tertiary (LOW confidence — flagged)
- Curl smoke patterns — https://everything.curl.dev/cmdline/exitcode.html (well-known but generic)
- Brave / general blog posts on Caddy local CA (multiple) — patterns consistent with official docs; cited for completeness

## Metadata

**Confidence breakdown:**
- Cross-phase integration map: HIGH — verified against bundle source
- Standard stack: HIGH — all packages pinned to working versions used in existing demos OR bundle composer.json
- Caddy / FrankenPHP topology: HIGH — multiple authoritative sources agree
- Browser/OS matrix for `*.localhost`: MEDIUM — Safari behavior is the soft spot, but CONTEXT's three-step ladder mitigates
- Critical discrepancy on `tenancy:migrate --create-dbs`: HIGH — code-verified

**Research date:** 2026-05-22
**Valid until:** 2026-06-22 (30 days; FrankenPHP + Caddy + Symfony are stable surfaces)
