# Architecture Research — v0.3 Adoption Surface

**Domain:** Symfony bundle additions (install command, demo app, profiler, mailer, resolver)
**Researched:** 2026-05-15
**Confidence:** HIGH (built on shipped v0.2 architecture; new code surfaces are well-trodden Symfony extension points)

Scope: how the five v0.3 features (`tenancy:install`, demo app, profiler tab, mailer bootstrapper, `OriginHeaderResolver`) integrate with the existing bundle without disturbing the v0.2 contract. The bundle skeleton, `AbstractBundle`, compiler-pass set, event lifecycle, and DI graph are fixed; new code plugs into established seams.

---

## Integration Map

```
Existing v0.2 surface (DO NOT modify contractually)
┌───────────────────────────────────────────────────────────────────────────┐
│  TenancyBundle (AbstractBundle)                                           │
│    configure()  loadExtension()  prependExtension()  build()              │
│         │             │                 │                │                │
│         ▼             ▼                 ▼                ▼                │
│      Config       services.php      Doctrine cfg    Compiler passes       │
│                                                                           │
│  Resolver chain (priority-ordered)    Bootstrapper chain (priority)       │
│    HostResolver       (30)              shared_driver    (auto)           │
│    HeaderResolver     (20)              doctrine_bootstr (-10)            │
│    QueryParamResolver (10)              database_switch  (auto)           │
│    ConsoleResolver    (auto)            cache decorator  (decorate)       │
│                                                                           │
│  Listener: TenantContextOrchestrator                                      │
│    kernel.request  prio=20  → resolve → setTenant → chain.boot → event    │
│    kernel.terminate         → chain.clear → context.clear → event         │
└───────────────────────────────────────────────────────────────────────────┘
                              │
                              ▼ (new seams)
┌───────────────────────────────────────────────────────────────────────────┐
│  v0.3 additions                                                           │
│                                                                           │
│  RESV-06  OriginHeaderResolver   → tagged tenancy.resolver, priority 25   │
│           (sibling of HeaderResolver, no compiler-pass change)            │
│                                                                           │
│  BOOT-04  MailerBootstrapper     → tagged tenancy.bootstrapper, prio -20  │
│           transport-swap via TransportFactory decorator (recommended)     │
│           or MessageEvent listener (fallback) — see Decision DEC-MAIL     │
│                                                                           │
│  DX-02   TenantDataCollector     → tagged data_collector (kernel.debug)   │
│           reads TenantContext on collect() (kernel.response, NOT term.)   │
│                                                                           │
│  DX-06   TenancyInstallCommand   → new console command (sibling of init)  │
│           mutates config/bundles.php in-place, then invokes tenancy:init  │
│                                                                           │
│  DEMO-01 examples/saas/           → Symfony skeleton, NOT in src/         │
│           composer path repository → bundle, docker-compose, Caddy *.lvh  │
└───────────────────────────────────────────────────────────────────────────┘
```

---

## Feature-by-Feature Integration

### 1. `tenancy:install` (DX-06)

**Verdict:** new standalone command class, **not** an extension of `TenancyInitCommand`. The two have different responsibilities (registration vs. config-scaffold) and we want each to stay individually runnable / idempotent.

**Class:** `Tenancy\Bundle\Command\TenancyInstallCommand`
**Command name:** `tenancy:install`
**Steps it runs (in order):**

1. Detect Symfony skeleton layout — confirm `config/bundles.php` exists, fail loudly if absent (custom `Kernel.php` registration path).
2. Read `config/bundles.php` → check whether `Tenancy\Bundle\TenancyBundle::class` is already registered.
3. If not registered, append the entry (see file-mutation strategy below).
4. Invoke `tenancy:init` programmatically via `getApplication()->find('tenancy:init')->run(new ArrayInput([...]), $output)` — single console invocation for the user.
5. Print next-steps (entity scaffold, env vars, docs link).

**File-mutation strategy (`config/bundles.php`) — token-array parse + rewrite:**

- **String append (rejected):** brittle — fails the moment the user formats the array on multiple lines, uses trailing-comma style differences, or wraps registration in env-conditionals.
- **`MicroKernelTrait`-style detection (n/a):** that trait is for runtime kernel composition, not file authoring; not applicable here.
- **Recommended approach:** use `PhpToken::tokenize()` on the file. Walk tokens, locate the outermost `return [...]` array literal, find its closing `]`, insert `Tenancy\Bundle\TenancyBundle::class => ['all' => true],` on the line above. This is what Symfony Flex itself uses (`Symfony\Flex\Configurator\BundlesConfigurator`) and what the broader PHP-AST ecosystem treats as the safe minimum.
- **Idempotency:** detect existing registration by a regex match on the FQCN before mutating. On re-run print "already registered, skipping".
- **Non-standard kernel recovery path:** if `config/bundles.php` does not exist or the token walker cannot find the outermost `return []`, abort with a clear message + the manual snippet:
  ```
  config/bundles.php not in the expected format. Add manually:
      Tenancy\Bundle\TenancyBundle::class => ['all' => true],
  Then run: bin/console tenancy:init
  ```
  Do NOT attempt to rewrite an unrecognized format. Recovery is human-driven for the edge case.
- **Env-conditional registration:** if the file already contains `Tenancy\Bundle\TenancyBundle::class` (even guarded by env conditional), treat as registered and skip — do not duplicate. We trust the user's environment scoping intent.

**Calling `tenancy:init` from `tenancy:install`:** call programmatically (not as separate user action) — single-command UX is the point of DX-06. Forward `--force` flag (if user wants idempotent overwrite). If `tenancy.yaml` already exists, surface a clear "config already present, leaving as-is" message; do not require `--force` for install-on-top-of-init.

**Integration with existing files:**
- `config/services.php` — register the new command service alongside `tenancy.command.init`:
  ```php
  $services->set('tenancy.command.install', TenancyInstallCommand::class)
      ->args([param('kernel.project_dir')])
      ->tag('console.command');
  ```
- No bundle-class change required (no compiler pass, no new tag).

**Net-new files:**
- `src/Command/TenancyInstallCommand.php`
- `tests/Unit/Command/TenancyInstallCommandTest.php` — token-array mutation must be unit-tested against (a) clean skeleton bundles.php, (b) already-registered, (c) malformed file, (d) env-conditional entry.

**Files modified:**
- `config/services.php` (one service registration block)

---

### 2. Demo App (`examples/saas/`) — DEMO-01

**Verdict:** sibling directory at repo root, **not** under `src/`. Bundle code and demo code do not share a namespace, autoloader, or composer manifest. The demo doubles as living integration test by running the bundle from the working tree via a Composer path repository.

**Directory layout:**
```
examples/
└── saas/                               # full Symfony 7.4/8.0 skeleton
    ├── bin/console
    ├── composer.json                   # requires danplaton4/tenancy-bundle: @dev
    ├── config/
    │   ├── bundles.php                 # TenancyBundle pre-registered
    │   ├── packages/
    │   │   ├── tenancy.yaml            # database_per_tenant + host resolver
    │   │   └── doctrine.yaml           # landlord + tenant connections
    │   └── routes.yaml
    ├── docker/
    │   ├── caddy/Caddyfile             # wildcard *.tenancy.localhost → 8000
    │   ├── mysql/init.sql              # create landlord + 2 tenant DBs
    │   └── php/Dockerfile              # PHP 8.3 + composer
    ├── docker-compose.yml              # caddy + php-fpm + mysql + mailpit
    ├── public/index.php
    ├── README.md                       # the demo walkthrough
    ├── src/
    │   ├── Controller/
    │   │   ├── DashboardController.php
    │   │   └── PublicController.php
    │   ├── Entity/
    │   │   └── (sample tenant-scoped entity)
    │   └── DataFixtures/
    │       └── TenantFixtures.php      # seeds acme + globex tenants
    └── tests/                          # one feature test proving cross-tenant isolation
```

**Composer path repository (in `examples/saas/composer.json`):**
```json
"repositories": [
    { "type": "path", "url": "../../", "options": { "symlink": true } }
],
"require": { "danplaton4/tenancy-bundle": "@dev" }
```
Symlink edition means a `composer install` in the demo points at the working tree — bundle changes are picked up without re-installation. This is the standard recipe for "monorepo with example app".

**docker-compose service composition:**

| Service | Image | Role |
|---------|-------|------|
| `caddy` | `caddy:2` | Reverse-proxy on `:80`, terminates TLS for `*.tenancy.localhost`, proxies to php-fpm. |
| `php` | local `docker/php/Dockerfile` | PHP-FPM 8.3 + composer + xdebug-off; mounts `./` at `/app`. |
| `mysql` | `mysql:8.4` | Three DBs: `landlord`, `tenant_acme`, `tenant_globex`. Init SQL creates them. |
| `mailpit` | `axllent/mailpit` | SMTP sink on `:1025`, web UI on `:8025` — exercises the mailer bootstrapper. |

**Subdomain routing — Caddy with `*.localhost` + ACME-internal TLS:**

Three options assessed:

| Option | Pros | Cons | Verdict |
|--------|------|------|---------|
| `/etc/hosts` entries | Works everywhere | Manual edit per tenant, no wildcard | REJECT — friction kills DEMO-01's "out of the box" promise |
| `nip.io` / `sslip.io` | Free wildcard DNS, no setup | Requires internet, ugly URLs (`acme.127.0.0.1.nip.io`) | REJECT — kills offline dev |
| Caddy + `*.tenancy.localhost` | Browsers resolve `*.localhost` to `127.0.0.1` automatically (RFC 6761), Caddy issues internal certs via its local CA | One-time `caddy trust` to install root CA (or HTTP-only fallback) | **CHOSEN** |

Modern browsers (Chrome, Firefox, Safari, all 2024+) honor RFC 6761 and resolve any `*.localhost` hostname to `127.0.0.1` without DNS or `/etc/hosts` entries. Caddy can issue valid TLS certs for these via its internal CA. README documents the one-time `caddy trust` step; an `http://acme.tenancy.localhost` fallback works without trust for the impatient.

**DB seeding strategy:** Doctrine fixtures via `doctrine/doctrine-fixtures-bundle`. On `docker compose up`, a one-shot init container runs:
1. `bin/console doctrine:migrations:migrate --no-interaction` (landlord schema)
2. `bin/console doctrine:fixtures:load --no-interaction` (creates two `Tenant` rows in landlord)
3. `bin/console tenancy:migrate` (per-tenant schema across both)
4. Optional: per-tenant fixtures via `bin/console tenancy:run acme "doctrine:fixtures:load --no-interaction --group=tenant"`

Fixtures > SQL dump because (a) they live with code and survive schema changes, (b) they exercise the migration path which is itself a v0.2 feature worth advertising. On-boot migration via PHP-FPM startup is rejected — fragile, conflates two concerns.

**Integration with existing files:**
- None modified. Demo is a closed system that consumes the bundle as a dependency.
- However: `composer.json` (bundle root) may need an `"extra": { "branch-alias": { "dev-master": "0.3.x-dev" } }` entry so the `@dev` constraint in the demo resolves cleanly. Verify on first build.

**Net-new files:** all of `examples/saas/**` — roughly 25-30 files (skeleton + 2 controllers + 1 entity + 1 fixture + Docker config + Caddyfile + README walkthrough). Not enumerated in detail here; treat as one delivery unit.

**CI implication:** demo can run a smoke test in CI — `docker compose up -d && curl -H "Host: acme.tenancy.localhost" http://localhost/dashboard` — which catches "did we break the public install path?" regressions. Worth a flag for roadmap consideration but probably v0.4-grade.

---

### 3. Profiler "Tenancy" Tab (DX-02)

**Verdict:** new `TenantDataCollector` extending `Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector` (recommended over raw `DataCollectorInterface` because it auto-handles serialization via `getData()` / `Data` cloning). Registered only when `kernel.debug = true`. Renders one toolbar icon + one panel.

**Class:** `Tenancy\Bundle\Profiler\TenantDataCollector` (new `Profiler/` namespace)

**Lifecycle — when does `collect()` run?**

Symfony's `Profiler` service listens to `kernel.response` (priority `-100`). All data collectors fire there. **This is critical:** `kernel.terminate` is too late — `TenantContextOrchestrator::onKernelTerminate()` runs and clears the context. So:

```
kernel.request prio=20  → TenantContext populated by orchestrator
       ...controller runs...
kernel.response prio=-100 → Profiler::onKernelResponse() iterates collectors
       → TenantDataCollector::collect($request, $response) → reads TenantContext (STILL POPULATED)
kernel.terminate        → TenantContextOrchestrator clears context
```

`collect()` runs while context is alive — no special handling needed. Use `lateCollect()` only if we needed to wait until controller code finished influencing state (we don't).

**What to collect:**
| Field | Source |
|-------|--------|
| Active tenant slug | `$tenantContext->getTenant()?->getSlug()` |
| Tenant name | `$tenant?->getName()` |
| Active driver (`database_per_tenant` / `shared_db`) | `%tenancy.driver%` parameter |
| Strict mode | `%tenancy.strict_mode%` parameter |
| Resolved-by (FQCN of winning resolver) | requires plumbing — see below |
| Configured resolvers (chain order) | `%tenancy.resolvers%` parameter |
| Bootstrapper count + names | inject `BootstrapperChain`, expose `getBootstrappers()` getter |

**"Resolved-by" plumbing:** `TenantResolution` (already exists) carries `$resolvedBy` (FQCN string). The `TenantContextOrchestrator` currently dispatches it via `TenantResolved` event but does not persist it. Two minimal options:

- **A — store on `TenantContext`:** add `setResolvedBy(?string)` + `getResolvedBy()`. Lightweight, but bloats the value-holder contract that PROJECT.md describes as "zero-dependency value holder".
- **B — listen to `TenantResolved` in collector:** `TenantDataCollector implements EventSubscriberInterface`, stashes the value in a private property, exposes via `collect()`. Cleaner — keeps `TenantContext` lean.

**Decision DEC-PROF-01 (owner sign-off needed):** Go with **B** unless there's a stronger reason to extend `TenantContext`. Recommendation: B.

**Service registration (debug-only):**
```php
// In TenancyBundle::loadExtension(), guard on $builder->getParameter('kernel.debug')
if ($builder->getParameter('kernel.debug') === true) {
    $services->set('tenancy.profiler.data_collector', TenantDataCollector::class)
        ->args([service('tenancy.context'), param('tenancy.driver'), ...])
        ->tag('data_collector', [
            'template' => '@Tenancy/Collector/tenant.html.twig',
            'id'       => 'tenancy',
        ]);
}
```

Outside dev profile the collector class is never registered — zero overhead in prod, matching the `kernel.debug` discipline of every Symfony-shipped collector.

**Twig template path:** `src/Resources/views/Collector/tenant.html.twig` — Symfony auto-discovers `Resources/views/` under any bundle and exposes it as `@Tenancy/...`. The `id` tag attribute must match the dot used in profile templates (`{% set tenancy = collector %}` via the template name).

**WDT icon:** Twig template has two blocks — `toolbar` (small icon + tenant slug) and `menu` (panel link). Use `helper.formatLogMessage` / standard Symfony WDT styling — no custom CSS.

**Integration with existing files:**
- `config/services.php` — add the kernel.debug-guarded registration block (or push it into `TenancyBundle::loadExtension()` since `$builder` exposes `kernel.debug`; the latter is cleaner because services.php has no `if` ergonomics).
- `TenancyBundle::loadExtension()` — add debug-guarded service block (~6 lines).

**Net-new files:**
- `src/Profiler/TenantDataCollector.php`
- `src/Resources/views/Collector/tenant.html.twig`
- `tests/Unit/Profiler/TenantDataCollectorTest.php`
- `tests/Integration/Profiler/ProfilerIntegrationTest.php` (boots kernel with `debug=true`, asserts the collector renders)

---

### 4. Mailer Bootstrapper (BOOT-04)

**Verdict:** this requires the architectural decision the question flagged. Three options compared rigorously.

#### Option A — Decorate `MailerInterface`

Wrap `mailer.mailer` service with a `TenantAwareMailer` that, on each `send()`, reads `TenantContext` and reconfigures the transport.

| Pro | Con |
|-----|-----|
| Single integration point | Transport is constructed in `Mailer` constructor via DSN at boot time — cannot be changed per-send without rebuilding the transport. We'd have to manage a `Map<tenantSlug, Transport>` ourselves and worry about teardown. |
|  | Doesn't compose with Symfony Mailer's `TransportInterface` lazy-construction model. |
|  | Bypasses the Messenger `MessageHandler` for async sends. |

**REJECT.** Heavyweight, fights the framework, doesn't play nicely with async.

#### Option B — `MessageEvent` listener (Envelope/transport swap)

`MessageEvent` is dispatched right before transport hands off to actual transport implementation. A listener can mutate the envelope/headers and (with some care) re-route to a different transport.

| Pro | Con |
|-----|-----|
| Easy to set `From` headers, BCC, reply-to per tenant. | Transport switching at this stage requires consulting `Transports` registry by name — adds indirection. |
| Survives async dispatch (`MessengerTransportListener` re-fires `MessageEvent` on the worker before re-send). | Tenant context on the worker is restored by `TenantWorkerMiddleware` (v0.2), so worker-side `MessageEvent` listener sees the right tenant. ✓ |
|  | If tenant DSN is dynamic per row (e.g. `Tenant::$smtpHost`), we still need a way to register a fresh transport at runtime. |

#### Option C — Custom `TransportFactoryInterface` reading `TenantContext`

Register a transport with DSN scheme `tenant://` (e.g. `MAILER_DSN=tenant://default`). At `Transport::fromDsn('tenant://...')` time, our factory returns a `TenantAwareTransport` that, on each `send(RawMessage)`, reads `TenantContext`, looks up the tenant's actual DSN, lazily constructs (or caches) the real `TransportInterface`, and delegates `send()` to it.

| Pro | Con |
|-----|-----|
| Pure Symfony-Mailer extension point — `TransportFactoryInterface` is the documented seam. | Adds a layer of indirection users see in their `MAILER_DSN` env var. |
| Works identically in HTTP and Messenger worker — `TenantContext` is the source of truth in both. ✓ | First-time-per-tenant cost is the real transport construction (DNS resolve, etc.) — acceptable; can be cached per request. |
| Async-clean: a `SendEmailMessage` is serialized with its envelope, then on the worker the transport is resolved freshly (worker has tenant context from `TenantStamp`). | Per-message `From` is not in transport — needs an additional small `MessageEvent` listener (the trivial half of Option B). |
| Testable: the factory is one class, the transport is one class, both straightforward to unit test. |  |

**Decision DEC-MAIL-01 (owner sign-off needed): Option C is recommended.**

Rationale: it's the only option that aligns with Symfony Mailer's *actual* extension contract (`TransportFactoryInterface`). Options A and B both attempt to slip mutations into pipeline stages not designed for them and break under async. Option C is also the only option that gives the user a single env-var (`MAILER_DSN=tenant://default`) UX matching every other Mailer integration.

**Use a small `MessageEvent` listener (the safe half of Option B) for `From` / `Reply-To` / branding headers** — that's what `MessageEvent` exists for. Combine: TransportFactory (C) for transport routing + MessageEvent listener for header decoration.

**Class skeleton:**
```php
// src/Mailer/TenantTransportFactory.php — implements TransportFactoryInterface
//   supports(Dsn $dsn): bool — returns $dsn->getScheme() === 'tenant'
//   create(Dsn $dsn): TransportInterface — returns new TenantAwareTransport(...)

// src/Mailer/TenantAwareTransport.php — implements TransportInterface
//   __construct(TenantContext, MailerTransportRegistry, ?LoggerInterface)
//   send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
//     → reads TenantContext → looks up tenant DSN → resolves real Transport
//       (cached per-tenant for request lifetime) → delegates send

// src/Mailer/TenantMailerBootstrapper.php — implements TenantBootstrapperInterface
//   boot(TenantInterface): void — primes the transport cache for this tenant
//   clear(): void — clears the per-tenant transport from cache (release resources)

// src/Mailer/Listener/TenantMessageDecorator.php — listens to MessageEvent
//   sets From header from tenant config when context active and message has no explicit From
```

**Schema implication — DEC-MAIL-02 (owner sign-off needed):** the `Tenant` entity stores tenant SMTP config somewhere. Options:

| Option | Pro | Con |
|--------|-----|-----|
| Reuse existing `connectionConfig` JSON column | No schema migration. | Conflates DB and mail config; ugly. |
| Add `mailerConfig` JSON column to `Tenant` | Clean separation. | Schema migration required — bumps minimum schema version, requires `tenancy:migrate` story for upgraders. |
| Add `mailerDsn` string column | Simplest. | Pre-judges that all config fits in DSN — can't handle complex SMTP needs. |
| Pull from env (e.g. `MAILER_DSN_{TENANT_SLUG}`) | Zero schema impact, ops-team friendly. | Won't scale past ~dozen tenants; demo-app showed JSON-column SaaS pattern. |

**Recommendation:** add a `mailerDsn` (`string|null`) column to `Tenant`. Simplest, most ergonomic. Document that users with complex needs can subclass `Tenant` or supply their own implementer of `TenantInterface`. Schema migration is required — must be paired with an UPGRADE 0.2→0.3 note and a fresh landlord migration.

**Optionality:** all of `src/Mailer/*` must be guarded by `class_exists(Symfony\Component\Mailer\Mailer::class)` — `symfony/mailer` is a *new* optional dependency. Composer: `"symfony/mailer": "^7.4 || ^8.0"` in `require-dev` and `suggest`. The bootstrapper is only registered when both `class_exists(Mailer)` and tenant has a `mailerDsn` (loose-coupled — bootstrapper is harmless if every tenant returns null).

**Integration with existing files:**
- `src/Entity/Tenant.php` — add `mailerDsn` field, getter, setter (schema migration impact).
- `src/TenantInterface.php` — add `getMailerDsn(): ?string` method to the interface (BC implication: any custom tenant entity in the wild breaks. UPGRADE doc note required; consider default trait `TenantMailerConfigTrait` to ease).
- `src/TenancyBundle.php::loadExtension()` — conditionally register mailer services + bootstrapper inside `if (class_exists(Mailer::class)) { ... }` block.
- `config/services.php` — alternatively register at top with `class_exists` guard; either works. Prefer `TenancyBundle::loadExtension()` so the `class_exists` check stays alongside other optional-dependency blocks.

**Net-new files:**
- `src/Mailer/TenantTransportFactory.php`
- `src/Mailer/TenantAwareTransport.php`
- `src/Mailer/TenantMailerBootstrapper.php`
- `src/Mailer/Listener/TenantMessageDecorator.php`
- `src/Mailer/Exception/MissingTenantMailerConfigException.php` (when strict_mode + no DSN)
- `tests/Unit/Mailer/*` (4 unit tests)
- `tests/Integration/Mailer/MailerEndToEndTest.php`
- Migration file under bundle's docs/recipes or via `tenancy:install` upgrade path

**Files modified:**
- `src/Entity/Tenant.php` (new field + accessors)
- `src/TenantInterface.php` (new abstract method — BC break for custom implementations, requires UPGRADE note)
- `src/TenancyBundle.php` (loadExtension — conditional registration block ~20 LoC)
- `composer.json` (suggest + require-dev `symfony/mailer`)

---

### 5. `OriginHeaderResolver` (RESV-06)

**Verdict:** trivial — clone `HeaderResolver`, swap header read, change priority. The resolver system was designed precisely for this case; no plumbing beyond a tag + a config entry.

**Class:** `Tenancy\Bundle\Resolver\OriginHeaderResolver implements TenantResolverInterface`

```php
final class OriginHeaderResolver implements TenantResolverInterface
{
    public function __construct(private readonly TenantProviderInterface $tenantProvider) {}

    public function resolve(Request $request): ?TenantInterface
    {
        $origin = $request->headers->get('Origin');
        if (null === $origin || '' === $origin) {
            return null;
        }
        // Parse hostname out of Origin (scheme://host[:port])
        $host = parse_url($origin, PHP_URL_HOST);
        if (!is_string($host) || '' === $host) {
            return null;
        }
        // Extract subdomain slug — same convention as HostResolver
        $slug = $this->extractSlugFromHost($host); // reuse HostResolver's logic? Or just strip first DNS label
        if (null === $slug) {
            return null;
        }
        try {
            return $this->tenantProvider->findBySlug($slug);
        } catch (TenantNotFoundException) {
            return null;
        }
    }
}
```

**Priority decision DEC-RESV-01:** between `HostResolver` (30) and `HeaderResolver` (20). Recommendation: **priority 25**. Rationale:

- `Origin` is set on browser cross-origin requests (XHR/fetch) — explicit signal of "where the JS client is hosted".
- `X-Tenant-ID` is a manual override and should always be highest in *manual-override* scenarios — but in the chain it's at 20 today.
- `HostResolver` (30) is correctly highest: same-origin requests should use the URL the user actually typed.
- For an SPA hosted at `app.example.com` calling API at `api.example.com`, `Origin: https://app.example.com` is the right tenant signal — should beat `X-Tenant-ID` (20) which a malicious script could spoof to a tenant they don't own.
- Priority 25 places Origin between Host and Header — logically: "real host beats stated host beats manual override".

**When both `Origin` and `X-Tenant-ID` are present:** first-match-wins (existing `ResolverChain` contract). With priority 25, Origin wins. Documented behavior. If users want the opposite, they reconfigure `tenancy.resolvers` order — already supported by `ResolverChainPass`'s allow-list filter.

**Configuration:** add `'origin'` to the built-in resolver short-name map (`ResolverChainPass::BUILT_IN_RESOLVER_MAP`). Update default `tenancy.resolvers` list in `TenancyBundle::configure()` from `['host', 'header', 'query_param', 'console']` to `['host', 'origin', 'header', 'query_param', 'console']`.

**CORS preflight (OPTIONS):** `OriginHeaderResolver::resolve()` will fire on any request that has an `Origin` header — including preflight OPTIONS. This is the right behavior:
- Tenant must be resolved before authentication/CORS firewall runs (the firewall might be per-tenant configured in user-land later).
- For a malformed Origin (no matching tenant), the resolver returns `null` and falls through to `HeaderResolver` / `QueryParamResolver`.
- For a matched-but-inactive tenant, `TenantInactiveException` bubbles up as HTTP 403 (same as `HeaderResolver`).

**Strict-mode interaction:** identical to `HeaderResolver`. Strict mode does not change the resolver's behavior — it only changes how `TenantAwareFilter` and direct tenant-DB access behave when no tenant is resolved. An OPTIONS preflight that hits a public CORS endpoint still has no tenant; strict mode kicks in only if such a request later queries a `#[TenantAware]` entity, which it shouldn't.

**Integration with existing files:**
- `src/TenancyBundle.php::configure()` — extend `resolvers` defaultValue array (1 line) — minor BC note: default chain length changes; existing users with explicit `tenancy.resolvers:` config in YAML are unaffected.
- `src/DependencyInjection/Compiler/ResolverChainPass.php` — add `'origin' => OriginHeaderResolver::class` to `BUILT_IN_RESOLVER_MAP`.
- `config/services.php` — register the service with tag `tenancy.resolver`, priority 25.

**Net-new files:**
- `src/Resolver/OriginHeaderResolver.php`
- `tests/Unit/Resolver/OriginHeaderResolverTest.php`
- `tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php` (kernel-boot, verify priority placement)

**Files modified (all tiny):**
- `src/TenancyBundle.php` (1 line in `configure()`)
- `src/DependencyInjection/Compiler/ResolverChainPass.php` (1 line in map)
- `config/services.php` (3 lines)

---

## Architectural Decisions Requiring Owner Sign-Off

Decisions that change a public surface, schema, or contract — flagging before phase planning starts.

| ID | Decision | Recommendation | Why it can't be punted |
|----|----------|----------------|------------------------|
| **DEC-MAIL-01** | Mailer extension point: TransportFactory vs MessageEvent vs MailerInterface decoration | **TransportFactory + tiny MessageEvent listener** | Determines the entire `src/Mailer/*` shape; can't be retrofitted without rewriting the bootstrapper. |
| **DEC-MAIL-02** | Where tenant SMTP config lives: `mailerDsn` column vs `connectionConfig` JSON vs env vars | **New `mailerDsn` string column on `Tenant`** | Schema migration + UPGRADE note. Affects `TenantInterface` contract. |
| **DEC-MAIL-03** | Add `getMailerDsn(): ?string` to `TenantInterface` (BC break for custom tenants) | **Yes, with default trait `TenantMailerConfigTrait`** | Either the interface grows (every custom Tenant breaks) or we keep it off-interface (loses type safety). |
| **DEC-RESV-01** | `OriginHeaderResolver` priority placement | **25 (between Host 30 and Header 20)** | Changes which resolver wins when multiple match; user-visible. |
| **DEC-PROF-01** | "Resolved-by" plumbing: extend `TenantContext` vs collector subscribes to event | **Collector subscribes to `TenantResolved` event** | Keeps `TenantContext` as zero-dependency value holder per PROJECT.md key decision. |
| **DEC-INST-01** | `tenancy:install` invokes `tenancy:init` programmatically vs. instructs user to run it | **Invokes programmatically; pass `--force` flag through** | Single-command UX is the entire feature value. |
| **DEC-INST-02** | Behavior when `config/bundles.php` is non-standard (custom Kernel, env conditional) | **Abort with a clear manual snippet; do not attempt heuristic rewrite** | Heuristic rewrites of unknown formats are the #1 source of bug reports for similar tools (Flex history). |
| **DEC-DEMO-01** | Subdomain routing scheme in demo | **Caddy + `*.tenancy.localhost` + internal CA** | All three options have very different README walkthroughs; users see the choice. |

---

## Component Responsibilities

| Component | Responsibility | Implementation |
|-----------|---------------|----------------|
| `TenancyInstallCommand` | Register bundle in `config/bundles.php`, run `tenancy:init` | Token-array file mutation; idempotent; aborts on non-standard formats |
| `OriginHeaderResolver` | Resolve tenant from `Origin` HTTP header | Implements `TenantResolverInterface`; tagged priority 25 |
| `TenantDataCollector` | Expose tenant info in Symfony Profiler | Extends `AbstractDataCollector`; subscribes to `TenantResolved`; dev-only |
| `TenantTransportFactory` | Symfony Mailer factory for `tenant://` DSN scheme | Implements `TransportFactoryInterface` |
| `TenantAwareTransport` | Per-send transport resolution via `TenantContext` | Implements `TransportInterface`; lazy-builds + caches real transport per tenant |
| `TenantMailerBootstrapper` | Lifecycle hook to prime/clear per-tenant transport | Implements `TenantBootstrapperInterface`; priority -20 |
| `TenantMessageDecorator` | Set `From` header from tenant config | `MessageEvent` listener |
| `examples/saas/` | Living demo / smoke test consuming bundle via path repo | Symfony skeleton + docker-compose + Caddy |

---

## Build Order (Roadmap Recommendation)

Considering dependencies between features, BC risk, and adoption-velocity (which feature unlocks user value the fastest):

| Phase | Feature | Why this position | Size |
|-------|---------|-------------------|------|
| **1** | **`OriginHeaderResolver`** (RESV-06) | Zero dependencies, no schema impact, no BC break. Pure addition to a well-tested pattern. Ships value to SPA users immediately. Smallest possible PR — proves the v0.3 milestone has motion. | S |
| **2** | **`tenancy:install`** (DX-06) | Unlocks the demo app (Phase 5 below). Independent of other features. Requires test infrastructure for token-array mutation. | S-M |
| **3** | **Profiler tab** (DX-02) | Depends only on `TenantContext` + `TenantResolved` event (both shipped). High DX value, low risk. Sized after install so we can dogfood it in the demo. | S-M |
| **4** | **Mailer bootstrapper** (BOOT-04) | Largest single feature. Carries the three architectural decisions (DEC-MAIL-01/02/03), the BC break on `TenantInterface`, and a schema migration. Needs the most planning runway — put after the easy wins build confidence. | M-L |
| **5** | **Demo app** (DEMO-01) | Depends on `tenancy:install` (otherwise the README has a manual `bundles.php` step which defeats the demo's promise) AND benefits from the mailer bootstrapper (so Mailpit demo works) AND the profiler (so the WDT tab demo works). Last consolidates everything. | M |
| **6** | **Docs refresh** (DOC-19) + retro carry-forward | Touch every doc page affected by the above. Must be last so docs match what shipped. | M |

**Critical-path dependencies:**

```
RESV-06 (independent)──────────────────────┐
DX-06 ─────────────► DEMO-01 ──────────────┤
DX-02 (independent)──────────────────────► │ DOC-19
BOOT-04 ──────────► DEMO-01 (mailpit demo)─┤
                                           ▼
```

**Why this order maximizes adoption velocity:**
- Ship RESV-06 first → existing v0.2 users get value (SPA support) on next minor release, even if remaining v0.3 work slips.
- Ship `tenancy:install` second → onboarding friction drops *before* the demo, which is what users will copy.
- Profiler before mailer → builds momentum with low-risk feature before tackling the BC-break feature.
- Mailer before demo → demo can showcase mailer-per-tenant via Mailpit, much stronger demo than "look, two tenants share a DB".
- Demo last → consolidates everything into a single "5-minute setup" install funnel proof.

**Note on phase sizing:** v0.2 averaged ~3 plans per phase. v0.3 should aim similar; mailer bootstrapper (Phase 4) may merit its own phase given decision density.

---

## Data Flow Additions

### Profiler request lifecycle

```
kernel.request (prio=20)
    TenantContextOrchestrator::onKernelRequest()
        ResolverChain::resolve() → TenantResolution{tenant, resolvedBy: 'OriginHeaderResolver'}
        TenantContext::setTenant()
        BootstrapperChain::boot()
        dispatch(TenantResolved{tenant, request, resolvedBy})
            └─► TenantDataCollector::onTenantResolved()  ← STASHES resolvedBy

...controller runs...

kernel.response (prio=-100, Symfony Profiler)
    Profiler::onKernelResponse()
        TenantDataCollector::collect($request, $response, $exception)
            reads TenantContext (still populated)
            reads stashed resolvedBy
            packages data via $this->data = ['tenant' => ..., ...]
        Profiler::saveProfile()  ← serializes via Data cloner

kernel.terminate
    TenantContextOrchestrator::onKernelTerminate()
        BootstrapperChain::clear()
        TenantContext::clear()
        dispatch(TenantContextCleared)
```

### Mailer dispatch (sync)

```
Controller calls $mailer->send($email)
    ↓
Symfony\Mailer\Mailer::send()
    dispatch(MessageEvent{message, envelope, transport: 'tenant'})
        └─► TenantMessageDecorator::onMessage()  ← sets From from TenantContext
    ↓
Mailer resolves transport 'tenant' → TenantAwareTransport (from TenantTransportFactory)
    TenantAwareTransport::send($message, $envelope)
        reads TenantContext::getTenant()
        cached real transport for this tenant? → yes: delegate. no: build from $tenant->getMailerDsn().
        real Transport::send()
```

### Mailer dispatch (async via Messenger)

```
Controller calls $mailer->send($email)
    ↓
Symfony\Mailer\Mailer detects messenger bus → dispatches SendEmailMessage
    TenantSendingMiddleware attaches TenantStamp(slug) ← v0.2 mechanism
    Envelope serialized + queued
    ↓
Worker picks up envelope
    TenantWorkerMiddleware restores TenantContext from TenantStamp ← v0.2 mechanism
        BootstrapperChain::boot() runs (incl. TenantMailerBootstrapper)
    SendEmailHandler invokes $mailer->send() in worker process
        → MessageEvent fires → TenantAwareTransport resolves under populated TenantContext ✓
    Handler returns
    TenantWorkerMiddleware::clear() in finally → BootstrapperChain::clear()
```

This shows why Option C (TransportFactory) is correct under async: `TenantContext` is the source of truth in both HTTP and worker process; the transport reads it freshly on each `send()`. The transport itself is not serialized — only the message + tenant slug — so no cross-process state issues.

---

## Anti-Patterns to Avoid

### Anti-Pattern A1: Modifying `config/bundles.php` with regex

**What people do:** Use `preg_replace` on `bundles.php` to insert a new entry.
**Why it's wrong:** Symbol-aware mutation requires lexical understanding. Regex breaks on multi-line arrays, comments, env conditionals — all valid PHP that users actually write.
**Do this instead:** Use `PhpToken::tokenize()` + a small token walker. Abort cleanly on shapes you don't recognize; never guess.

### Anti-Pattern A2: Reading `TenantContext` in `kernel.terminate`-priority collector

**What people do:** `lateCollect()` reads `TenantContext` — sees null because orchestrator already cleared.
**Why it's wrong:** `kernel.terminate` runs the orchestrator's clear before any low-priority collector. Race against the cleanup.
**Do this instead:** Use `collect()` (fires on `kernel.response`) — context is still alive. Or subscribe to `TenantResolved` and stash the data the moment the tenant is known.

### Anti-Pattern A3: Decorating `MailerInterface` to swap transport per send

**What people do:** Wrap `mailer.mailer`, intercept `send()`, build a fresh transport every call.
**Why it's wrong:** Bypasses Symfony Mailer's transport routing (named transports, failover, round-robin all break). Doesn't survive `MessengerBus` async dispatch — the worker's mailer is a different service instance.
**Do this instead:** Implement `TransportFactoryInterface` for a `tenant://` DSN scheme. The framework's native extension point is the right answer.

### Anti-Pattern A4: Demo app uses `/etc/hosts` for tenant subdomains

**What people do:** README tells users to edit `/etc/hosts` to add `acme.tenancy.local` and `globex.tenancy.local`.
**Why it's wrong:** Requires sudo, doesn't survive reboot on some systems, doesn't wildcard, breaks the "just `docker compose up`" promise.
**Do this instead:** Use Caddy + `*.tenancy.localhost`. Browsers resolve `*.localhost` to `127.0.0.1` natively (RFC 6761); Caddy issues internal certs.

### Anti-Pattern A5: `OriginHeaderResolver` priority below `HeaderResolver`

**What people do:** Place Origin at priority 15 (below X-Tenant-ID at 20).
**Why it's wrong:** `X-Tenant-ID` is a header the *client controls* — a malicious script can claim any tenant. `Origin` is set by the *browser* and harder to spoof from a cross-origin script. Origin should beat X-Tenant-ID when both are present.
**Do this instead:** Origin at 25 — between Host (30) and Header (20).

---

## Integration Points Summary

### Files modified per feature (existing files only)

| Feature | Files Modified |
|---------|---------------|
| RESV-06 OriginHeaderResolver | `src/TenancyBundle.php` (1 line), `src/DependencyInjection/Compiler/ResolverChainPass.php` (1 line), `config/services.php` (3 lines) |
| DX-06 tenancy:install | `config/services.php` (1 service registration) |
| DX-02 Profiler | `src/TenancyBundle.php` (~8 lines, debug-guarded registration block) |
| BOOT-04 Mailer | `src/Entity/Tenant.php` (new field), `src/TenantInterface.php` (new method — BC break), `src/TenancyBundle.php` (~20-line conditional block), `composer.json` (suggest/require-dev) |
| DEMO-01 Demo app | `composer.json` (branch-alias entry, possibly) |
| DOC-19 Docs | Multiple `docs/**` files — out of scope for this research |

### Net-new files per feature

| Feature | Net-new files |
|---------|---------------|
| RESV-06 | `src/Resolver/OriginHeaderResolver.php`, tests (2) |
| DX-06 | `src/Command/TenancyInstallCommand.php`, tests (1-2) |
| DX-02 | `src/Profiler/TenantDataCollector.php`, `src/Resources/views/Collector/tenant.html.twig`, tests (2) |
| BOOT-04 | `src/Mailer/TenantTransportFactory.php`, `src/Mailer/TenantAwareTransport.php`, `src/Mailer/TenantMailerBootstrapper.php`, `src/Mailer/Listener/TenantMessageDecorator.php`, `src/Mailer/Exception/MissingTenantMailerConfigException.php`, tests (4-5), landlord migration recipe |
| DEMO-01 | `examples/saas/**` — full Symfony skeleton (~25-30 files) |

### Compiler-pass surface

No new compiler passes required. Existing `BootstrapperChainPass` and `ResolverChainPass` pick up the new tagged services automatically. `ResolverChainPass` gets one map entry (`'origin' => OriginHeaderResolver::class`).

### Tag surface

| Tag | New users |
|-----|-----------|
| `tenancy.resolver` | `OriginHeaderResolver` (priority 25) |
| `tenancy.bootstrapper` | `TenantMailerBootstrapper` (priority -20) |
| `data_collector` | `TenantDataCollector` (debug-only) |
| `console.command` | `TenancyInstallCommand` |
| `kernel.event_listener` (via `#[AsEventListener]`) | `TenantMessageDecorator` (MessageEvent), `TenantDataCollector` (TenantResolved — if Option B for resolvedBy) |

### Optional dependencies

| Dep | Used by | Guard |
|-----|---------|-------|
| `symfony/mailer` | BOOT-04 | `class_exists(\Symfony\Component\Mailer\Mailer::class)` |
| `symfony/web-profiler-bundle` | DX-02 | `kernel.debug === true` (sufficient — collector tag is harmless without profiler bundle) |
| All v0.2 optionals | Unchanged | Unchanged |

---

## Scaling / Hardening Considerations

| Feature | At demo scale | At small SaaS scale (10-100 tenants) | Production hardening |
|---------|---------------|--------------------------------------|----------------------|
| OriginHeaderResolver | Fine | Fine | Document that Origin can be `null` on same-origin — chain falls through correctly |
| tenancy:install | Run once | N/A | Single invocation, no scale concern |
| Profiler | Dev only | Dev only | Confirm zero-overhead via `kernel.debug` guard in prod |
| Mailer bootstrapper | Mailpit sink | Per-tenant SMTP credentials in landlord DB | Cache resolved Transports per-request only (release on `clear()`); consider connection limits on shared SMTP gateways |
| Demo app | `docker compose up` | N/A — demo only | Smoke-test in CI optional (v0.4 candidate) |

---

## Sources

- `src/TenancyBundle.php`, `src/EventListener/TenantContextOrchestrator.php`, `src/Context/TenantContext.php`, `src/Resolver/{HeaderResolver,ResolverChain}.php`, `src/Entity/Tenant.php`, `src/Command/TenantInitCommand.php`, `config/services.php`, `src/DependencyInjection/Compiler/{BootstrapperChainPass,ResolverChainPass,MessengerMiddlewarePass}.php` — read 2026-05-15, HIGH confidence (authoritative — this is the shipped v0.2 codebase).
- `.planning/PROJECT.md` — HIGH confidence (project source of truth).
- `.planning/milestones/v0.2-research/ARCHITECTURE.md` — HIGH confidence (prior architecture rationale).
- Symfony Profiler / DataCollector documentation (`symfony/profiler.html`, `AbstractDataCollector`) — HIGH confidence (stable since 4.x).
- Symfony Mailer `TransportFactoryInterface` and `MessageEvent` documentation — HIGH confidence (extension contracts are public, stable since 4.3).
- Symfony Flex `BundlesConfigurator` (token-array mutation reference) — MEDIUM confidence (read public source; pattern is widely copied).
- RFC 6761 (`localhost` reserved domain) and Caddy `internal` CA documentation — HIGH confidence (standardized; modern browser behavior verified).

---

*Architecture research for: Symfony Tenancy Bundle — v0.3 Adoption Surface*
*Researched: 2026-05-15*
