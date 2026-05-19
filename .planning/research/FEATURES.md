# Feature Research — v0.3 Adoption Surface

**Domain:** Symfony multi-tenancy bundle (`danplaton4/tenancy-bundle` v0.2.0 → v0.3)
**Researched:** 2026-05-15
**Mode:** Feature decomposition for SUBSEQUENT milestone
**Confidence:** HIGH for `tenancy:install`, OriginHeaderResolver, Profiler tab patterns (multiple authoritative sources); MEDIUM for Mailer bootstrapper design (Symfony Mailer transport-factory pattern is the verified extension point; per-tenant DSN approach inferred from stancl/tenancy v4 model)

---

## Scope Note

v0.2 shipped the core engine (resolvers, drivers, bootstrappers, Messenger, CLI, test trait, docs site). v0.3 is **adoption surface only** — features whose value is "make first install succeed" or "close the highest-leverage gap blocking real production use." This research deliberately excludes everything already shipped under v0.2 (see `.planning/milestones/v0.2-research/FEATURES.md`).

The five v0.3 features in scope:

| ID | Feature | Adoption Lever |
|----|---------|---------------|
| DX-06 | `tenancy:install` command | Removes the "edit `config/bundles.php` by hand" install step |
| DEMO-01 | Demo app in `examples/` | Provides a running reference users can `docker compose up` |
| DX-02 | Symfony Profiler "Tenancy" tab | Closes the debuggability gap that turns confusion into rage-uninstalls |
| BOOT-04 | Mailer bootstrapper | Closes the #1 SaaS use case (transactional email per tenant) |
| RESV-06 | `OriginHeaderResolver` | Closes the SPA + cross-origin API gap |

---

## Feature Landscape

### Table Stakes (Users Expect These)

Behaviors a Symfony developer takes for granted in any 2026 bundle in this space.

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| `tenancy:install` registers bundle automatically | Without Flex, every other "install" command in the Symfony ecosystem (`make:user`, `doctrine:database:create`, etc.) does its setup in one step; users will not tolerate "now manually edit `config/bundles.php`" as step 2 of 3 | S | Mutate `config/bundles.php` array, write back. Must be idempotent (no-op if already registered). |
| `tenancy:install` is idempotent | Standard contract for setup commands; rerunning after a failed first run must succeed without corrupting files | S | Detect existing bundle entry; detect existing `config/packages/tenancy.yaml`; both → exit 0 with "already installed" message |
| Profiler tab shows active tenant | If the profiler is loaded, every other bundle has a tab — Doctrine, Security, Messenger, Mailer all have one; absence is a debuggability cliff | S | DataCollector + Twig template; one line in the WDT showing tenant slug or "(no tenant)" |
| Profiler shows resolver that won | Symfony developers expect "which resolver matched?" the way Security shows "which firewall matched?"; debugging tenant resolution otherwise requires xdebug | S | Already recorded indirectly via `TenantResolution` value object (FIX-02); plumb into collector |
| Profiler shows bootstrappers run | Mirrors how DoctrineBundle shows queries — "did the bootstrapper chain actually fire?" is the #1 question when a tenant context appears broken | S | BootstrapperChain already collects FQCNs into `TenantBootstrapped` event (BootstrapperChain.php:31); listener captures into collector |
| Demo app boots via `docker compose up` | Every modern OSS Symfony project (API Platform, Sulu, Mercure) has a one-command demo; without it users go to the next package | M | Two tenants on subdomains, SQLite or Postgres, Caddy/Traefik for wildcard routing |
| Demo app uses subdomain routing | This is the canonical multi-tenancy demo experience (tenant1.localhost, tenant2.localhost); anything else is "but does it really work like prod?" | M | Caddy is simpler than Traefik for wildcard subdomains; FrankenPHP + Caddy is the current Symfony Docker reference |
| `OriginHeaderResolver` reads `Origin` HTTP header | The proven SPA pattern; stancl/tenancy v4 shipped exactly this for the same reason — "SPA on tenant1.app.com → api.app.com" is the dominant cross-origin SaaS topology in 2026 | S | Symmetric to existing `HeaderResolver`; reads `$request->headers->get('Origin')`, parses host, delegates to `TenantProviderInterface::findBySlug()` |
| Mailer bootstrapper overrides `From` per tenant | Customer-facing email from `noreply@tenant1.com` rather than `noreply@app.com` is the basic ask — anyone using the bundle for SaaS will hit this in week one | S | Event listener on `MessageEvent`; set `From` from tenant entity if unset |
| Demo app proves DB switching visually | The whole pitch is "tenant resolves → entire app reconfigures"; the demo must show this in 5 seconds (tenant1 sees tenant1's data, tenant2 sees tenant2's data) | M | Two seeded tenants with distinct records; index controller lists them; visit `tenant1.localhost` vs `tenant2.localhost` to see the switch |

### Differentiators (Beyond what stancl/tenancy v4 or RamyHakam ships)

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| `tenancy:install` detects Doctrine and runs the appropriate `tenancy:init` flow | Combines two steps users currently do manually: register + scaffold-with-correct-defaults. DX-05 in v0.2 already does Doctrine detection in `tenancy:init`; surface it at the top-level install step. | S | Reuse `tenancy:init` machinery (do NOT reimplement YAML scaffolding inside `tenancy:install`); delegate. |
| `tenancy:install` prints copy-paste next-step block | Most install commands end with "now do X" in prose; printing a literal `php bin/console tenancy:migrate` etc. block raises the "first 5 minutes" success rate measurably | S | Trivial to add to console output; high adoption-funnel ROI |
| Profiler tab shows bootstrapper timing (ms per bootstrapper) | DoctrineBundle shows per-query timing; Messenger shows envelope handling time; tenancy bootstrappers fire on every request, so per-bootstrapper ms surfaces N+1-equivalent issues (a slow Mailer or Filesystem bootstrapper compounds across requests) | M | Wrap `BootstrapperChain::boot()` with timer; expose in collector. No competing bundle has this. |
| Profiler shows cache key prefix in use | Subtle bug-magnet: developers add a bootstrapper that writes to `cache.app` and don't realize it's not namespaced; surfacing the active prefix makes silent leaks visible | S | `TenantAwareCacheAdapter` already knows its subnamespace; expose via collector |
| Profiler shows connection DSN (redacted) per request | Database-per-tenant mode swaps connections; "which DB am I actually on?" is the #1 dev question; redact password but show host + dbname | S | Read from `Connection::getParams()` in `database_per_tenant` mode; safe — no live writes during collection |
| OriginHeaderResolver supports strict-mode fail-closed | If `Origin` is malformed/unknown and strict mode is ON, throw 403 rather than fall through silently; the strict-mode philosophy from v0.2 carries forward | S | `TenantNotFoundException` already bubbles to HTTP 404/403; just don't catch in strict mode |
| Mailer bootstrapper supports per-tenant transport DSN | Per-tenant SMTP credentials (each tenant's own SendGrid subaccount, etc.) — this is the production ask and what zhortein "claims" without verification; doing it right (transport factory, not a transport-mutation hack) is genuinely novel for Symfony | M | Custom `mailer.transport_factory` registering `tenant-aware://default` DSN; on `send()`, resolves the active tenant's DSN from the Tenant entity and delegates. See implementation note below. |
| Demo app includes BOTH shared-DB and database-per-tenant tenants | Most OSS demos pick one mode for clarity; this bundle's USP is "either driver works"; the demo proves it. Risk: doubles complexity. Mitigation: separate `examples/shared-db/` and `examples/database-per-tenant/` subdirectories with their own compose files | L | One compose file per mode; shared `examples/README.md` explains when to use which |
| Demo app doubles as integration smoke test | Run the demo in CI on every push; if `docker compose up && curl tenant1.localhost` fails, the build fails — closes the "v0.2.0 shipped, four post-release defects" loop from v0.2 retro | M | GitHub Actions job that builds compose, curls each tenant, checks the response body contains the seeded tenant name |

### Anti-Features (Commonly Requested, Explicitly NOT Building in v0.3)

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| `tenancy:install` running `composer require` | "Let me install everything in one go" | Composer manipulation in a console command is a maintenance trap (lockfile races, plugin compatibility, network failures inside a Process); Flex exists for this | Document `composer require` as step 0 above `tenancy:install` |
| `tenancy:install` creating a Tenant entity | "I want the install to give me a working Tenant entity I can use immediately" | The user's domain model belongs to the user; generating an entity in `App\Entity\` from a vendor bundle is invasive and would collide with `make:entity` workflows | Document the canonical Tenant shape in install output; `tenancy:init` already comments the YAML; defer to v0.4 if telemetry shows demand |
| `tenancy:install` for non-Doctrine projects refusing to run | "Bundle is Doctrine-coupled, why pretend otherwise" | DX-05 (already shipped) established Doctrine detection → driver recommendation; bundle works without Doctrine in shared-DB-only mode with custom `TenantProviderInterface`; refusing to install would break this | `tenancy:install` runs in both modes; when Doctrine is absent it prints "Doctrine ORM not detected — `shared_db` driver auto-selected; provide your own TenantProviderInterface" and exits 0 |
| Profiler tab showing **all** queries scoped per tenant | "I want to see queries-per-tenant" | DoctrineBundle's tab already shows queries; replicating it inside Tenancy tab is duplication; per-tenant query stats only matter when multiple tenants resolve in one request, which is a vanishingly rare debugging scenario | Link from Tenancy tab to Doctrine tab; show the **connection name** so users can find tenant-specific queries in Doctrine tab themselves |
| Profiler tab on production | "I want to see active tenant in production for debugging" | `WebProfilerBundle` is dev-only by design; production needs structured logging or APM (Datadog/Inspector), not WDT | Log `TenantBootstrapped` payload at INFO level; APM integration is post-v0.3 (Future / By Demand) |
| Mailer bootstrapper swapping the entire transport graph | "Per-tenant SMTP host should reconfigure the global mailer service" | Transport mutation is unsafe — `Transport` instances cache connections, are not designed for runtime mutation, and modifying them races worker threads (Mailer GitHub explicitly warns about this) | Use `mailer.transport_factory` extension point with a tenant-aware DSN scheme (`tenant-aware://default`); factory resolves the active tenant's DSN per `send()` call |
| Mailer bootstrapper failing closed when tenant has no SMTP | "If the tenant didn't configure SMTP, refuse to send" | Most tenants will not configure their own SMTP — the landlord/global mailer is the correct default; failing closed would break the most common path | Fallback to landlord mailer DSN by default; opt-in `strict_smtp: true` config flag flips to fail-closed |
| Mailer bootstrapper as a synchronous transport switch | "Just swap the transport service on TenantBootstrapped" | Doesn't work with Messenger async mail — the `MessageHandler` runs in a worker where the transport was resolved at boot time, before any tenant context exists | Transport-factory pattern naturally resolves at `send()` time, which is correct in both sync and async paths; combined with v0.2's `TenantStamp`/`TenantWorkerMiddleware`, async mail gets the right transport for free |
| OriginHeaderResolver as a replacement for HostResolver | "SPAs don't need subdomain routing" | The Origin header is browser-controlled; non-browser clients (curl, mobile apps without browser) don't send it; mobile in particular needs `X-Tenant-ID` | Ship `OriginHeaderResolver` as **one resolver in the chain**, not as a default-on replacement; chain priority order: `OriginHeaderResolver` → `HostResolver` → `HeaderResolver` → `QueryParamResolver` |
| OriginHeaderResolver trusting arbitrary `Origin` values | "Just look up whatever's in the header" | Same trust model as HostResolver — the header value must match a known tenant in the provider; resolver returns null on miss (matches existing `HeaderResolver` shape, see `src/Resolver/HeaderResolver.php:25-34`) | Resolver matches against the **same** tenant domain field as HostResolver (uniform threat model); no separate "allowed origins" config |
| Demo app shipping with production-grade Caddy/Traefik config | "Make it realistic" | Production config in a demo invites users to copy it into prod; that's an attack surface multiplier | Demo uses the simplest possible Caddy config with wildcard subdomain on `*.localhost`; docs explicitly say "demo only, do NOT copy to production" |
| Demo app deploying to a public URL | "Let people see it without installing Docker" | Hosting cost, security surface (anyone can spam the demo), version drift between live and `examples/` source | GitHub Codespaces button in README — zero hosting cost, ephemeral, sandboxed |

---

## Per-Feature Deep Dive

### 1. `tenancy:install` (DX-06) — Complexity: **S**

**User contract:**
```
$ composer require danplaton4/tenancy-bundle
$ php bin/console tenancy:install
✓ Registered Tenancy\Bundle\TenancyBundle in config/bundles.php
✓ Detected Doctrine ORM — recommending database_per_tenant driver
✓ Created config/packages/tenancy.yaml
→ Next steps:
    1. Edit config/packages/tenancy.yaml — set the tenant_class and domain_suffix
    2. Run: php bin/console doctrine:database:create
    3. Run: php bin/console make:migration && php bin/console doctrine:migrations:migrate
    4. See https://danplaton4.github.io/tenancy-bundle/install for details
```

**Idempotency contract (mandatory):**
- Already in `config/bundles.php` → skip, log "Already registered"
- `config/packages/tenancy.yaml` exists → skip, log "Config already exists" (do NOT overwrite — user's edits are sacred)
- Both → exit 0 with "Bundle already installed; nothing to do"
- Partial state (registered but no config, or vice versa) → complete the missing piece, exit 0

**Non-Doctrine projects:**
- Detect via `class_exists(Doctrine\ORM\EntityManagerInterface::class)` — already done by `DX-05`
- If absent: recommend `shared_db` (current behavior); print "Doctrine ORM not detected — `shared_db` selected; you'll need a custom TenantProviderInterface"
- Still register the bundle (bundle works without Doctrine in shared-DB-only mode if user supplies a provider)

**Should it write tenancy.yaml itself? NO — delegate to `tenancy:init`:**
- Single source of truth for the YAML template (which is non-trivial — it has commented placeholders for every key)
- `tenancy:init` is already battle-tested (Phase 12, Phase 15 hardening)
- `tenancy:install` becomes thin: register bundle → invoke `tenancy:init` via `getApplication()->find('tenancy:init')` → print next-steps
- Cost of NOT delegating: two copies of the YAML template, two places to update when adding a config key, drift between them by v0.4

**Implementation skeleton (validates feasibility):**
```php
final class TenancyInstallCommand extends Command
{
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $bundlesPhp = $this->projectDir.'/config/bundles.php';
        $bundles = require $bundlesPhp;
        $bundleClass = TenancyBundle::class;

        if (!array_key_exists($bundleClass, $bundles)) {
            $bundles[$bundleClass] = ['all' => true];
            file_put_contents($bundlesPhp, '<?php'.PHP_EOL.PHP_EOL.'return '.var_export($bundles, true).';'.PHP_EOL);
        }

        return $this->getApplication()->find('tenancy:init')->run(new ArrayInput([]), $output);
    }
}
```

**Caveats:**
- `var_export` loses the `::class` constant syntax (renders as string FQCN). This is a known issue. Two options: (a) generate via `nikic/php-parser` for AST-faithful rewrite (heavy dependency for one command), or (b) generate as string and accept that `bundles.php` now uses strings instead of `::class` (Symfony Flex itself uses strings — verify in any Symfony 7 skeleton's `bundles.php`). Recommendation: option (b), match Flex's output.

**Dependencies:** None new; reuses `tenancy:init` (DX-04, shipped v0.2)

---

### 2. Demo App (DEMO-01) — Complexity: **M**

**Minimum-viable demo proves the value in 3 commands:**
```
$ cd examples/database-per-tenant
$ docker compose up -d
$ curl http://tenant1.localhost/dashboard
{"tenant":"tenant1","records":["...tenant1's data..."]}
$ curl http://tenant2.localhost/dashboard
{"tenant":"tenant2","records":["...tenant2's data..."]}
```

**Architecture choice — pick one driver per demo subdirectory:**
- `examples/database-per-tenant/` — two SQLite files, one per tenant, plus a landlord SQLite
- `examples/shared-db/` — one Postgres (or SQLite) DB with `tenant_id` column and `#[TenantAware]` entity
- Each has its own `docker-compose.yml` and `README.md`
- Shared `examples/README.md` is a chooser ("Which mode? Read this first")

**Why not "one demo with both modes"?** Cognitive load. Demos sell by being readable in 60 seconds. Two clean demos > one impressive-but-confusing demo.

**Wildcard subdomain routing:**
- **Caddy** is the simplest config — three lines for wildcard `*.localhost` resolution: `*.localhost { reverse_proxy app:8000 }`. Caddy is also the official Symfony Docker reference (FrankenPHP).
- **Traefik** is more flexible but more YAML; reserve for v0.4+ if compose-file complexity matters
- **`dnsmasq`** for hosts file: not needed; `*.localhost` resolves to `127.0.0.1` natively on macOS/Linux/Windows

**With or without Mailer in the demo?**
- v0.3 demo: **NO Mailer.** Reasons: (a) requires an SMTP container (mailpit), doubling compose footprint; (b) demo's value prop is "tenant context follows the request" — Mailer is downstream; (c) Mailer bootstrapper itself is in scope for v0.3, but the demo proves the **core** loop, not every feature.
- v0.4+: Add Mailer + Mailpit container to the demo; show per-tenant `From` headers in Mailpit UI.

**What the demo MUST include:**
- Two seeded tenants in landlord DB
- One subdomain per tenant (`tenant1.localhost`, `tenant2.localhost`)
- One controller that returns a tenant-scoped query result
- An admin URL (`admin.localhost` or `landlord.localhost`) that shows ALL tenants — proves landlord/tenant separation
- A `README.md` with the 3-command quickstart

**What the demo MUST NOT include:**
- Authentication / login flows (orthogonal; bloats the demo)
- A frontend framework (React, Vue) — Twig + Bootstrap CDN is enough; framework adds noise
- Production config (no HTTPS, no secrets management, no health checks) — explicit "DEMO ONLY" banner in `README.md`
- Custom `php.ini` tweaks unless absolutely required for the bundle to work

**Doubles as integration test:**
- GitHub Actions job: `docker compose up -d && wait_for_ready && curl tenant1.localhost && assert response contains tenant1 && curl tenant2.localhost && assert contains tenant2`
- Failure mode: catches packaging regressions (e.g. "the bundle works in our test kernel but not in a real Symfony skeleton")
- v0.2 retro carry-forward: this addresses the "v1.0.0 shipped, four post-release defects" pattern — defects from downstream demo projects now surface in our own demo first

**Dependencies:** None — uses shipped bundle as-is; if demo discovers a bug, fix in bundle and re-test

---

### 3. Symfony Profiler "Tenancy" Tab (DX-02) — Complexity: **M**

**Canonical Profiler tab UX (from DoctrineBundle, SecurityBundle, MessengerBundle research):**
- WDT toolbar icon + small badge text (e.g. tenant slug, or "no tenant")
- Full panel with sections (Doctrine: "Queries grouped by connection"; Security: "Firewall context", "User", "Roles")
- Twig template extends `@WebProfiler/Profiler/layout.html.twig`

**Table-stakes fields (every multi-tenancy tab should have these):**

| Field | Source | Cost |
|-------|--------|------|
| Active tenant slug | `TenantContext::getTenant()->getSlug()` | Free (already in context) |
| Active tenant ID | `TenantContext::getTenant()->getId()` | Free |
| Resolved-by (which resolver matched) | `TenantResolution::getResolver()` (added in FIX-02 / v0.2) | Free |
| Bootstrappers that ran (list of FQCNs) | `TenantBootstrapped::getBootstrappers()` (already in event, see `BootstrapperChain.php:31`) | Free — capture in listener |
| Active driver mode (`database_per_tenant` / `shared_db`) | Config-time, inject from bundle extension | Free |
| Active connection DSN (host + dbname, redacted) | `Connection::getParams()` in db-per-tenant mode | Free |
| Cache key prefix in use | `TenantAwareCacheAdapter` exposes via getter | S — add getter if not present |
| Strict mode on/off | Config-time | Free |

**Nice-to-have fields (differentiators):**

| Field | Cost | Value |
|-------|------|-------|
| Bootstrapper timing (ms each) | M — wrap `BootstrapperChain::boot()` with `microtime(true)` | High — surfaces slow bootstrappers per-request |
| Total resolution + boot time | S — bracket the orchestrator listener | High — proves "tenancy adds <1ms" claim |
| Was tenant context cleared? (clean teardown check) | S — listener on `TenantContextCleared` | Medium — catches leak bugs in dev |
| Messenger envelopes dispatched with `TenantStamp` (count) | M — middleware logs to collector | Medium — confirms async path works |
| Stamp present? (when running in worker) | S — `TenantWorkerMiddleware` populates collector if active | High (workers) |

**Anti-fields (do NOT include):**
- All queries with tenant context (duplicates Doctrine tab; link to it instead)
- All cache keys hit per tenant (duplicates Cache tab)
- Tenant entity raw dump (security risk if config secrets are on the Tenant entity, e.g. SMTP password)

**What "good" looks like (reference: MessengerBundle tab):**
- Top section: 3-4 large stat blocks (active tenant, driver, connection, total boot ms)
- Middle section: bootstrapper table (FQCN, ms, status)
- Bottom section: "Resolved-by" with full TenantResolution dump (for debugging chain priority)
- Hidden by default, accordion-expandable: raw config snapshot for the request

**Implementation:**
- `TenancyDataCollector extends AbstractDataCollector` — Symfony 6+ standard pattern
- `collect(Request, Response)` reads `TenantContext`, `BootstrapperChain` state (already public), config
- Twig template `@Tenancy/profiler/panel.html.twig`
- Auto-registered via `services.php` (no compiler-pass plumbing needed for collectors — autoconfigure handles it)

**Toolbar visibility:**
- Always show in dev when bundle is loaded
- Badge: tenant slug if resolved, "(no tenant)" if not — explicitly visible absence is valuable for debugging public/landlord routes (FIX-02 territory)

**Dependencies:** None new; all data is in v0.2-shipped APIs

---

### 4. Mailer Bootstrapper (BOOT-04) — Complexity: **M**

**The question: switch transport or override From?**

**Answer: do BOTH, via different mechanisms, both opt-in via config.**

| Goal | Mechanism | Complexity | When |
|------|-----------|------------|------|
| Per-tenant `From` / `Reply-To` headers | Event listener on `MessageEvent` | S | Almost always wanted; cheap; safe |
| Per-tenant SMTP DSN | Custom `mailer.transport_factory` registering `tenant-aware://default` DSN | M | Opt-in; for tenants with their own SendGrid/Mailgun |
| Per-tenant message routing (one tenant uses Mailgun, another uses SES) | Same custom transport factory; routes per-send | M | Falls out of (2) for free |

**Why NOT swap the entire transport service:**
- Symfony's `Mailer` and `Transport` instances are not designed for runtime mutation (verified: symfony/symfony#42369 discussion, GitHub issue #59040). Transport caches connections; mutating shared state from a bootstrapper races with Messenger workers.
- Reference: Symfony team's official guidance (discussion #61506) is "custom transport factory that resolves DSN per send()".

**Tenant entity SMTP fields (schema change — dependency note):**
- `Tenant::getMailerDsn(): ?string` — `null` = use landlord default
- `Tenant::getMailerFrom(): ?string` — `null` = use landlord default `MAILER_FROM`
- These fields are **opt-in additions** users add to their own Tenant entity. The bundle does NOT enforce them — it reads via interface getters.
- Add `TenantHasMailerConfigInterface` (or method optionality via reflection) — bootstrapper only activates per-tenant transport for tenants implementing it.

**Fallback behavior:**
- Tenant has no SMTP configured → use landlord/global mailer (default, safe).
- `strict_smtp: true` (opt-in config flag) → throw `TenantMissingMailerException` instead (defense-in-depth for compliance-heavy SaaS where sending from the wrong account is a contract violation).

**Per-message override via Envelope:**
- Already supported by Symfony Mailer natively (`->setEnvelope()` on `Email`). Bundle doesn't need to do anything special. Document the pattern.

**Messenger async mail interaction:**
- Symfony's Mailer publishes a `SendEmailMessage` to a Messenger transport for async sending.
- v0.2's `TenantStamp` + `TenantWorkerMiddleware` already restores tenant context in the worker BEFORE the handler runs.
- Therefore: when the `SendEmailHandler` resolves the transport via our custom factory, `TenantContext` is already active. Transport factory reads current tenant → returns correct transport. **No additional work needed.**
- This is the strongest argument for transport-factory pattern over transport-mutation: it composes correctly with async by construction.

**Implementation sketch:**
```php
final class TenantAwareMailerTransportFactory extends AbstractTransportFactory
{
    public function __construct(
        private TenantContext $tenantContext,
        private TransportFactoryInterface $delegate,  // SMTP / Sendmail / etc.
        private string $defaultDsn,
    ) {}

    protected function getSupportedSchemes(): array { return ['tenant-aware']; }

    public function create(Dsn $dsn): TransportInterface
    {
        return new TenantAwareTransport(fn() => $this->resolveTransport());
    }

    private function resolveTransport(): TransportInterface
    {
        $tenant = $this->tenantContext->getTenant();
        $dsn = $tenant?->getMailerDsn() ?? $this->defaultDsn;
        return $this->delegate->create(Dsn::fromString($dsn));
    }
}
```

`TenantAwareTransport` is a thin wrapper that resolves the real transport lazily on each `send()`. This is the only pattern that survives both sync and async paths correctly.

**Dependencies:**
- **NEW:** Tenant entity SMTP fields (schema change in user-land — users add `mailer_dsn` and `mailer_from` columns to their Tenant entity); document as an optional interface `TenantHasMailerConfigInterface`
- **NEW:** Custom DSN scheme `tenant-aware://default` registered via `mailer.transport_factory` tag
- v0.2 shipped: `TenantContext`, `TenantStamp`, `TenantWorkerMiddleware` (async path)

---

### 5. `OriginHeaderResolver` (RESV-06) — Complexity: **S**

**Use case (from stancl/tenancy v4 PR #621):**
- SPA on `tenant1.app.com` makes XHR/fetch to `api.app.com`
- `Host: api.app.com` (useless for tenant resolution)
- `Origin: https://tenant1.app.com` (matches a tenant!)
- Resolver reads `Origin`, parses host, looks up tenant by host — same domain field HostResolver uses.

**SPA flow (CORS preflight interaction):**
1. SPA on `tenant1.app.com` sends `OPTIONS api.app.com/users` preflight
2. Symfony CORS bundle / NelmioCorsBundle responds with `Access-Control-Allow-Origin: https://tenant1.app.com`
3. SPA sends actual `GET api.app.com/users` with `Origin: https://tenant1.app.com`
4. **OriginHeaderResolver fires at kernel.request priority 20**, reads `Origin`, looks up tenant
5. TenantContext populated → query scoped → response returned with correct CORS headers

**When `Origin` is absent (server-side calls, curl, mobile native):**
- Return `null` (resolver miss), let chain continue to next resolver
- Chain order recommendation: `OriginHeaderResolver` (priority 80) → `HostResolver` (60) → `HeaderResolver` X-Tenant-ID (40) → `QueryParamResolver` (20) → `ConsoleResolver` (CLI only)
- Mobile apps use `X-Tenant-ID` (existing HeaderResolver) — this is unchanged
- If no resolver matches, behavior depends on strict mode (FIX-02 already handles this for public/landlord routes)

**Strict mode behavior:**
- Same as existing `HeaderResolver` (see `src/Resolver/HeaderResolver.php`): return `null` on miss
- `TenantNotFoundException` is NOT caught by the resolver chain; only the chain itself returning fully-null is handled at orchestrator level
- Strict mode (`strict_mode: true`) determines whether the orchestrator throws on chain-null for protected routes
- This is consistent with v0.2 architecture (FIX-02)

**Security model — same as HostResolver:**
- `Origin` is browser-controlled; can't be spoofed by JS from a different origin (browser enforces). For non-browser clients, `Origin` is server-controllable, but the lookup against `TenantProviderInterface::findBySlug()` rejects unknown values. Threat model: identical to `HostResolver` (which trusts `Host` similarly).
- **Trust boundary:** Origin matches a registered tenant domain → trusted. Origin is arbitrary string → rejected (null).
- Do NOT add a separate "trusted origins" allow-list config — that's a parallel source of truth that drifts from the Tenant entity's domain field.

**Implementation (mirror of HeaderResolver):**
```php
final class OriginHeaderResolver implements TenantResolverInterface
{
    public const HEADER_NAME = 'Origin';

    public function __construct(private readonly TenantProviderInterface $tenantProvider) {}

    public function resolve(Request $request): ?TenantInterface
    {
        $origin = $request->headers->get(self::HEADER_NAME);
        if (null === $origin || '' === $origin) return null;

        $host = parse_url($origin, PHP_URL_HOST);
        if (!is_string($host) || '' === $host) return null;

        try {
            return $this->tenantProvider->findByDomain($host);  // same provider method HostResolver uses
        } catch (TenantNotFoundException) {
            return null;
        }
    }
}
```

**Reuses existing `TenantProviderInterface::findByDomain()`** (used by HostResolver). Zero new provider methods. Zero schema changes. This is why it's an **S**.

**Documentation note:** CORS docs section explaining "use this resolver IF your API is on a different domain than the SPA" — this is the gotcha that produces the `Origin` value in the first place. Same-origin SPAs don't get an `Origin` header on all browsers.

**Dependencies:** None — uses shipped `TenantProviderInterface::findByDomain()`

---

## Feature Dependencies

```
[Tenant entity SMTP fields] (user-land schema change)
    └──required by──> [BOOT-04 Mailer bootstrapper] (per-tenant DSN)
    └──optional via──> [TenantHasMailerConfigInterface] (bundle-side opt-in interface)

[tenancy:init] (shipped v0.2)
    └──invoked by──> [DX-06 tenancy:install] (delegation, not duplication)

[TenantContext + TenantBootstrapped event + BootstrapperChain] (shipped v0.2)
    └──consumed by──> [DX-02 Profiler tab DataCollector]

[TenantResolution value object] (shipped v0.2 / FIX-02)
    └──consumed by──> [DX-02 Profiler tab "resolved-by" field]

[TenantStamp + TenantWorkerMiddleware] (shipped v0.2 / MSG-01..03)
    └──enables──> [BOOT-04 Mailer bootstrapper] (async mail path works for free)

[TenantProviderInterface::findByDomain] (shipped v0.2)
    └──reused by──> [RESV-06 OriginHeaderResolver]

[mailer.transport_factory tag] (Symfony framework, native)
    └──extended by──> [BOOT-04 TenantAwareMailerTransportFactory]

[Symfony AbstractDataCollector + WebProfilerBundle] (Symfony framework, dev-only)
    └──extended by──> [DX-02 TenancyDataCollector]

[Bundle registered + tenancy.yaml present] (state after install)
    └──required by──> [DEMO-01 examples/ apps] (compose file invokes `tenancy:install` during build, or assumes pre-installed state)

[DX-06 tenancy:install] (idempotent)
    └──enables──> [DEMO-01 demo app smoke test] (CI runs install in a fresh skeleton, then boots demo)
```

### Dependency Notes

- **BOOT-04 requires Tenant entity changes:** Adding `mailer_dsn` and `mailer_from` to user's Tenant entity is a user-land schema migration. Document as "if you want per-tenant SMTP, add these columns." Bundle ships an optional `TenantHasMailerConfigInterface` users implement; bootstrapper checks `$tenant instanceof TenantHasMailerConfigInterface` before reading. **This is the only feature in v0.3 requiring a user-land schema change.**
- **DX-06 must NOT duplicate DX-04 (`tenancy:init`):** Risk: someone "improves" install by inlining YAML generation, drifts from `tenancy:init`. Mitigation: integration test asserts `tenancy:install` produces identical output to `tenancy:init` when run on a clean project.
- **DEMO-01 enables CI integration test:** Demo doubles as smoke test. Sequence: CI provisions fresh Symfony skeleton → runs `composer require` → `tenancy:install` → boots demo compose → curls each tenant subdomain → asserts response. This closes the v0.2 retro item about "downstream defects surface only in user projects."
- **DX-02 has zero new runtime cost:** All data exposed by the Profiler tab is already collected by v0.2 code paths (events, value objects, chain state). The collector is read-only. No new instrumentation needed for table-stakes fields; only "bootstrapper timing" (differentiator) requires new wrapping.
- **RESV-06 is the only feature with zero new dependencies:** Reuses `TenantProviderInterface::findByDomain()` shipped in v0.2. Smallest, safest, most-isolated v0.3 addition.

---

## MVP Definition

### v0.3 Launch With

Order by adoption-velocity impact (smallest install-funnel friction reduction first):

- [ ] **DX-06 `tenancy:install`** — S — Eliminates "edit bundles.php" install step; doubles as gateway to `tenancy:init`
- [ ] **RESV-06 `OriginHeaderResolver`** — S — Pure addition, zero risk, closes SPA gap; ship to set differentiator-table parity with stancl/tenancy v4
- [ ] **DX-02 Profiler tab** — M — Debuggability is the single highest "stayed installed" predictor; competitive whitespace (no Symfony tenancy bundle has this)
- [ ] **BOOT-04 Mailer bootstrapper** — M — Highest-leverage SaaS use case; requires user-land schema doc but bundle complexity is M not L because Symfony's transport-factory pattern composes cleanly with Messenger
- [ ] **DEMO-01 Demo app in examples/** — M (M each for two demo subdirs; can ship one first, second in patch) — Most visible adoption signal; doubles as CI smoke test

### Suggested phase order (for the roadmapper)

1. **Phase 1: DX-06 `tenancy:install`** — fastest, unblocks everything downstream that wants to ship in a working skeleton. Validates `tenancy:init` integration.
2. **Phase 2: RESV-06 `OriginHeaderResolver`** — small, isolated, validates the v0.3 cadence is real before tackling M-complexity features.
3. **Phase 3: DX-02 Profiler tab** — M complexity, all M-complexity bits are well-understood (DataCollector is documented pattern).
4. **Phase 4: BOOT-04 Mailer bootstrapper** — M complexity, custom DSN scheme is the only novel piece; everything else falls out of v0.2 architecture.
5. **Phase 5: DEMO-01 Demo app** — Last because it consumes the other four (demo can show install command, profiler tab in screenshots, mailer working via per-tenant From). Also doubles as v0.3 release acceptance test.
6. **Phase 6: DOC-19 Docs refresh** — Public ROADMAP.md page + install page rewrite + Mailer/Profiler/Demo guides. After everything else lands.

### Add After Validation (v0.4)

These were considered for v0.3 but excluded based on the "tight scope on purpose" project constraint:

- [ ] **BOOT-03 Filesystem bootstrapper** — moved to v0.4 (Storage milestone) where it belongs alongside SHARE-01/02/03
- [ ] **DX-03 PHPStan extension** — v0.4 (research-validated as HIGH complexity in v0.2 docs; not adoption-critical)
- [ ] Mailer Mailpit container in demo — wait until BOOT-04 has real downstream usage; not required for v0.3 demo's value prop

### Future Consideration (v0.6+)

- [ ] Symfony Flex recipe — explicitly deferred per project constraints ("revisit when install volume justifies cost")
- [ ] APM integration (Datadog / Inspector / NewRelic span tagging) — production observability is post-v0.3

---

## Feature Prioritization Matrix

| Feature | User Value | Implementation Cost | Priority | Notes |
|---------|------------|---------------------|----------|-------|
| DX-06 `tenancy:install` | HIGH | LOW (S) | P1 | Install funnel — every adoption-stuck user mentions this kind of step |
| RESV-06 `OriginHeaderResolver` | MEDIUM | LOW (S) | P1 | Closes parity with stancl/tenancy v4; SPA architectures are dominant in 2026 |
| DX-02 Profiler tab | HIGH | MEDIUM | P1 | Sole Symfony multi-tenancy bundle with this; debuggability is retention |
| BOOT-04 Mailer bootstrapper | HIGH | MEDIUM | P1 | #1 missing SaaS feature; transport-factory pattern composes cleanly with v0.2 async |
| DEMO-01 Demo app | HIGH | MEDIUM | P1 | Highest visible signal; doubles as integration smoke test (v0.2 retro carry-forward) |
| DOC-19 Docs refresh | HIGH | LOW-MEDIUM | P1 | Necessary final phase to advertise v0.3 changes; public ROADMAP.md page |

All v0.3 features are P1 by construction — "tight scope on purpose, ship in weeks not months" means everything in v0.3 must justify being in v0.3.

---

## Competitor Feature Analysis (v0.3 features only)

| Feature | stancl/tenancy v4 (Laravel) | RamyHakam (Symfony) | zhortein (Symfony) | This Bundle v0.3 |
|---------|----------------------------|---------------------|--------------------|--|
| `install` single-command | YES (`tenancy:install`) | NO | NO | YES (DX-06) |
| Profiler/WDT tab | NO (Laravel has Telescope, not equivalent) | NO | NO | YES (DX-02) — competitive whitespace |
| Mailer bootstrapper | YES (v4 `MailTenancyBootstrapper`) | NO | YES (claims, unverified) | YES (BOOT-04) — verified-correct transport-factory approach |
| OriginHeaderResolver | YES (v4 `InitializeTenancyByOriginHeader`) | NO | NO | YES (RESV-06) |
| Working `docker compose` demo | YES (community demos exist) | NO | NO | YES (DEMO-01) — also CI smoke test |

Net effect of v0.3: closes all five public feature-table gaps against stancl/tenancy v4 (the gold standard) while keeping the Symfony-native flavor (Profiler tab, native transport factory, idempotent install command).

---

## Sources

### High confidence (official docs / source code / verified PRs)
- [stancl/tenancy v4 — Origin Header Resolver PR #621](https://github.com/archtechx/tenancy/pull/621) — Origin resolver design, SPA use case, browser security model
- [Tenancy for Laravel v4 — What's New](https://v4.tenancyforlaravel.com/version-4/) — confirms `InitializeTenancyByOriginHeader` middleware shipped in v4
- [Tenancy for Laravel v3 — Installation](https://tenancyforlaravel.com/docs/v3/installation/) — `tenancy:install` command behavior reference
- [Tenancy for Laravel v3 — Bootstrappers](https://tenancyforlaravel.com/docs/v3/tenancy-bootstrappers/) — `MailConfigBootstrapper` exists; pattern reference
- [Symfony Docs — Profiler / Data Collectors](https://symfony.com/doc/current/profiler.html) — `AbstractDataCollector`, Twig template structure, autoconfigure tag
- [Symfony Docs — Sending Emails with Mailer](https://symfony.com/doc/current/mailer.html) — `mailer.transport_factory` extension point
- [Symfony Discussion #61506 — Dynamic Mailer DSN from database](https://github.com/symfony/symfony/discussions/61506) — official guidance: custom transport factory, not transport mutation
- [Symfony Issue #42369 — Dynamic Mailgun domain](https://github.com/symfony/symfony/discussions/42369) — confirms transport instances are not designed for runtime mutation
- [Symfony Docs — Bundles](https://symfony.com/doc/current/bundles.html) — `config/bundles.php` format, environment keys
- Local source: `src/Resolver/HeaderResolver.php`, `src/Bootstrapper/BootstrapperChain.php`, `src/Bootstrapper/TenantBootstrapperInterface.php` — existing contracts that v0.3 features mirror

### Medium confidence (community articles, secondary sources)
- [Strangebuzz — Adding a custom data collector to the Symfony debug bar](https://www.strangebuzz.com/en/blog/adding-a-custom-data-collector-in-the-symfony-debug-bar) — practical DataCollector example
- [Inspector.dev — Ultimate Guide to Symfony Profiler](https://inspector.dev/ultimate-guide-to-symfony-profiler/) — Profiler tab UX conventions
- [Albert Moreno — Creating Custom Symfony Mailer Transports](https://albertmoreno.dev/posts/creating-custom-symfony-mailer-transports/) — `TransportFactoryInterface` implementation
- [Boxblinkracer — Multi-tenant wildcard domain setups with Docker and dnsmasq](https://www.boxblinkracer.com/blog/docker-dnsmasq) — wildcard subdomain routing for demos
- [Twilio — Multi-Tenant Laravel App with Docker](https://www.twilio.com/en-us/blog/create-multi-tenant-laravel-app-docker) — Docker compose demo patterns
- [GitHub gist — Docker Compose local dev with wildcard DNS](https://gist.github.com/BretFisher/f1d1be2a8ab6df379018bcbf766e74a4) — `*.localhost` resolution

### Low confidence (research-only)
- [zhortein/multi-tenant-bundle on Packagist](https://packagist.org/packages/zhortein/multi-tenant-bundle) — claims "Mailer bootstrapper" but source not verified; treated as unproven

---

*Feature research for: Symfony tenancy bundle v0.3 Adoption Surface*
*Researched: 2026-05-15*
