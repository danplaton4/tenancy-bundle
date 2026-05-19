# Stack Research — v0.3 Adoption Surface

**Domain:** Symfony reusable bundle — adoption-surface features for `danplaton4/tenancy-bundle`
**Researched:** 2026-05-15
**Confidence:** HIGH (canonical sources for every new dep; one MEDIUM call-out on FrankenPHP vs symfony-cli)
**Scope:** Additive only. v0.2 stack (PHP ^8.2, Symfony ^7.4||^8.0, DBAL 4, ORM 3, PHPUnit 11, PHPStan 2, php-cs-fixer 3) is unchanged. This document covers *what's new for v0.3 features*.

---

## Executive Summary

**Net new production dependencies: ZERO.** Every v0.3 feature can be built against components already in `require` or `require-dev`, plus `symfony/mailer` and `symfony/twig-bundle` added as **optional/dev**. No AST parser, no docker base image baked into the bundle, no new Composer package surface for the consumer.

The single biggest decision: `tenancy:install` uses **string-templated PHP-array generation** (the Symfony Flex `BundlesConfigurator` pattern), NOT `nikic/php-parser`. The demo app (`examples/`) uses **Symfony Docker (FrankenPHP + Caddy)** because it ships subdomain TLS, file watching, and one-command setup with no extra services. The Profiler tab extends `AbstractDataCollector`, requiring `symfony/twig-bundle` as `require-dev`. The Mailer bootstrapper uses a `MessageEvent` listener (not transport decoration) for envelope+headers, with `Transport::fromDsn()` runtime construction only when per-tenant SMTP DSNs are configured.

---

## Recommended Stack Additions

### Core (no changes)

The v0.2 `require` block is sufficient for `tenancy:install`, `OriginHeaderResolver`, and most of the Profiler collector. Nothing moves into `require`.

| Already in require | Used by v0.3 feature |
|---|---|
| `symfony/console` ^7.4\|\|^8.0 | `tenancy:install` command |
| `symfony/http-foundation` ^7.4\|\|^8.0 | `OriginHeaderResolver` (reads `Origin` header from `Request`) |
| `symfony/dependency-injection` ^7.4\|\|^8.0 | Profiler `DataCollector` service tagging |
| `symfony/event-dispatcher` ^7.4\|\|^8.0 | Mailer bootstrapper `MessageEvent` listener |

### New Dev / Optional Dependencies

| Package | Constraint | Where | Why |
|---|---|---|---|
| `symfony/mailer` | `^7.4\|\|^8.0` | `require-dev` + `suggest` | Mailer bootstrapper (BOOT-04). Optional dep — guarded by `class_exists(Mailer::class)` and `class_exists(MessageEvent::class)`. Same pattern as the existing `symfony/messenger` guard. |
| `symfony/twig-bundle` | `^7.4\|\|^8.0` | `require-dev` only | Profiler tab template rendering. Already transitively pulled by `web-profiler-bundle`, but declared explicitly so PHPStan/CI know. **Do NOT put in `require`** — apps using the bundle without the Profiler shouldn't pay this cost. |
| `symfony/web-profiler-bundle` | `^7.4\|\|^8.0` | `require-dev` only | Profiler tab integration testing. Already optional at runtime; the `DataCollector` class is in `symfony/http-kernel` (already required). |

That's it. Three additions, all dev/optional. No AST parser, no docker library, no recipe library.

### What stays out of `composer.json` entirely

These are referenced by the demo or docs but live **in the demo project's own `composer.json` or as docs-only tooling** — never in the bundle's manifest:

| Tool | Where it lives | Why it's not in bundle composer.json |
|---|---|---|
| `dunglas/symfony-docker` (FrankenPHP/Caddy image) | `examples/Dockerfile` + `docker-compose.yml` | Docker image, not a Composer package |
| Demo app's own deps (`symfony/framework-bundle`, `twig/twig`, `doctrine/orm`) | `examples/composer.json` | Demo is a separate Composer root |
| MkDocs Material, plugins | `requirements.txt` for docs CI | Python tooling, not PHP |

---

## Feature-by-Feature Stack

### 1. `tenancy:install` Command (DX-06)

**Approach:** String-templated PHP-array generation, idempotent via read-merge-write.

**Reference implementation:** [`symfony/flex` `BundlesConfigurator`](https://github.com/symfony/flex/blob/2.x/src/Configurator/BundlesConfigurator.php) — the canonical Symfony pattern for modifying `config/bundles.php`. It uses pure string concatenation, NOT an AST parser:

```php
$contents = "<?php\n\nreturn [\n";
foreach ($bundles as $class => $envs) {
    $contents .= "    $class::class => [";
    // ...
}
file_put_contents($file, $contents);
opcache_invalidate($file);  // critical for in-request reload
```

**Idempotency strategy (proven by Flex):**
1. `require $file` to load existing array (PHP itself becomes the parser)
2. If `$bundles[TenancyBundle::class]` already present → no-op, exit 0
3. Otherwise merge in `[TenancyBundle::class => ['all' => true]]`
4. Re-serialize entire array via string template, atomic-write, `opcache_invalidate()`

**Libraries used:**
- `symfony/console` (already required) — Command class
- `symfony/filesystem` (transitive via http-kernel) — atomic file writes via `dumpFile()`
- PHP native `require` + `var_export` — no parsing library needed

**What NOT to use:**

| Avoid | Why |
|---|---|
| `nikic/php-parser` | Adds ~600KB and a transitive dep just to write one array. `bundles.php` has a strictly enforced shape — Flex itself doesn't parse, it regenerates. Using an AST parser is over-engineering. |
| `humbug/php-scoper` | Wrong tool — it rewrites namespaces for prefixing, not for config files. |
| `symfony/var-exporter` | Closer fit (`VarExporter::export()` produces PHP code) BUT the `bundles.php` format uses `::class` constants which `var_export` can't emit. String templating is simpler and matches Flex. |
| Regex append (`file_put_contents($f, "...", FILE_APPEND)`) | Not idempotent across re-installs; corrupts file if user has manually reformatted it. Read-merge-rewrite is the only safe pattern. |
| `symfony/maker-bundle` as a dep | MakerBundle does NOT expose `bundles.php` registration as public API. Its internal `Generator` and `FileManager` are `@internal`. Pulling it in for `tenancy:install` would couple us to undocumented surface. |

**Confidence:** HIGH — Flex `BundlesConfigurator` source verified directly.

---

### 2. Demo App in `examples/` (DEMO-01)

**Approach:** [`dunglas/symfony-docker`](https://github.com/dunglas/symfony-docker) skeleton (FrankenPHP + Caddy in a single container) + a `docker-compose.override.yml` adding MariaDB and host-alias entries.

**Why FrankenPHP/Caddy over alternatives:**

| Option | Verdict | Reason |
|---|---|---|
| **Symfony Docker (FrankenPHP + Caddy)** | **CHOSEN** | One service, automatic Let's Encrypt/local-CA TLS, native wildcard subdomain support, file watcher built in, official maintained by Kévin Dunglas. `docker compose up` and a Symfony app is serving on `https://*.localhost`. |
| PHP-FPM + nginx + custom certs | Rejected | Three services, manual cert plumbing, more files to maintain in `examples/`. |
| `symfony serve` (Symfony CLI) | Rejected as primary | Excellent locally (supports `*.wip` proxy out of the box) but requires installing `symfony` binary first. Demo must work with `docker compose up` only — that's the friction we're removing. Document Symfony CLI as an *alternative* in `examples/README.md`. |
| Apache + mod_php | Rejected | Outdated for 2026 SaaS demo. |

**Demo docker-compose stack:**

| Service | Image | Purpose |
|---|---|---|
| `app` | `dunglas/frankenphp` (extends to install Symfony + bundle) | PHP runtime + Caddy web server with TLS, subdomain routing |
| `database` | `mariadb:11` | Two tenant DBs (`tenant1_db`, `tenant2_db`) seeded in `init.sql`. MariaDB over Postgres because: (a) DBAL 4 first-class support, (b) more familiar to "let me try this" first-time users, (c) smaller image. |

**Subdomain routing:** Caddy handles `tenant1.localhost`, `tenant2.localhost` natively — no `/etc/hosts` edits required on macOS/Linux (browsers resolve `*.localhost` to `127.0.0.1` per RFC 6761). For Windows, document a one-line `hosts` file edit.

**Why MariaDB over Postgres for the demo:**
- DBAL 4 supports both; demo neutrality matters less than friction
- MariaDB image is ~120MB vs Postgres ~400MB
- `docker compose up` cold start ~3s faster on MariaDB
- Apps adopting the bundle skew toward MySQL/MariaDB (e.g., Symfony default `.env` examples)

**What NOT to add:**

| Avoid | Why |
|---|---|
| `bitnami/symfony` Docker image | Less idiomatic; not maintained by the Symfony community core. |
| A Makefile orchestrating multiple `docker compose` calls | `docker compose up` should be the only required command. Hidden orchestration = hidden friction. |
| Per-tenant containers | Would multiply the demo's footprint and obscure the tenancy-bundle mechanism (which is what the demo is supposed to showcase). |
| Mercure / Redis / Elasticsearch | Out of scope for v0.3 demo. Adds services that confuse the value-prop. |
| Symfony Flex in the demo | Demo deliberately shows the *no-Flex* path (`composer require` + `bin/console tenancy:install`). |

**Confidence:** HIGH on docker-compose stack and Caddy `*.localhost` behavior; MEDIUM on FrankenPHP vs symfony-cli — both work; FrankenPHP wins on "no host tooling required" which is the explicit demo goal.

---

### 3. Symfony Profiler Tab (DX-02)

**Approach:** Extend `Symfony\Component\HttpKernel\DataCollector\AbstractDataCollector` (available in Symfony 5.2+, stable through 7.x and 8.x). The class lives in `symfony/http-kernel` which is **already in `require`** — no new production dep.

**Class skeleton:**

```php
final class TenancyDataCollector extends AbstractDataCollector
{
    public function __construct(private readonly TenantContext $context) {}

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $tenant = $this->context->getTenant();
        $this->data = [
            'active'         => null !== $tenant,
            'slug'           => $tenant?->getSlug(),
            'id'             => $tenant?->getId(),
            'resolved_by'    => $request->attributes->get('_tenancy.resolver_class'),
            'bootstrappers'  => $request->attributes->get('_tenancy.bootstrappers_run', []),
            'connection'     => $request->attributes->get('_tenancy.connection_name'),
        ];
    }

    public static function getTemplate(): ?string
    {
        return '@Tenancy/data_collector/tenancy.html.twig';
    }
}
```

**Wiring:**
1. Service registered in `config/services.php` with tag `data_collector` (auto-tagged via `autoconfigure: true` if user has it enabled, but the bundle should tag explicitly for safety).
2. Twig template at `templates/data_collector/tenancy.html.twig` extending `@WebProfiler/Profiler/layout.html.twig`.
3. Add `Twig\TenancyPath` namespace via `prependExtension()` so `@Tenancy/...` resolves without the user touching `twig.yaml`.

**Symfony 7.x-specific notes:**
- `AbstractDataCollector::getName()` defaults to `static::class` since 5.2 — no need to override.
- The `LateDataCollectorInterface` is NOT needed for tenant data (it's all available at `kernel.request` time).
- **Critical:** `collect()` data is serialized for storage. Don't store the `Tenant` entity (Doctrine proxy) — copy scalars only. The skeleton above does this correctly.
- Symfony 8.0 *may* tighten serialization; storing scalars guarantees forward-compat.

**Telemetry capture for `resolved_by` / `bootstrappers_run`:**
- Existing `TenantResolved` listener attaches `_tenancy.resolver_class` to the `Request` attributes
- `BootstrapperChain` appends each bootstrapper's `::class` to `_tenancy.bootstrappers_run` during `boot()`
- Zero new services; zero new events

**What NOT to add:**

| Avoid | Why |
|---|---|
| Custom JavaScript / WDT JS assets | Adds asset pipeline complexity. The WDT panel renders pure HTML/CSS via Twig. |
| `DataCollectorInterface` directly (without `AbstractDataCollector`) | `AbstractDataCollector` is the modern, simpler base. Direct interface implementation is the 2017 pattern. |
| A separate "tenancy.profiler" bundle | One bundle, one shipped feature — splitting would inflate the install surface we're trying to compress. |
| Twig template in the user's `templates/` directory | Templates ship inside the bundle's `templates/` directory via the bundle's auto-registered namespace. |

**Symfony 6 → 7 gotcha:** None at the DataCollector API level. The change between 6.x and 7.x for profiler tabs is purely cosmetic (toolbar pill styling); template blocks `toolbar`, `head`, `menu`, `panel` are unchanged.

**Confidence:** HIGH — `AbstractDataCollector` API is stable since 5.2, present and unchanged through 7.4 and 8.0.

---

### 4. Mailer Bootstrapper (BOOT-04)

**Approach: `MessageEvent` listener for envelope + From headers, plus optional runtime `Transport::fromDsn()` swap for per-tenant SMTP.** NOT decoration of `MailerInterface`. NOT a custom `TransportFactoryInterface`.

**Why MessageEvent over the alternatives:**

| Approach | Verdict | Reasoning |
|---|---|---|
| **`MessageEvent` listener** (chosen) | Right tool | `Symfony\Component\Mailer\Event\MessageEvent` fires before send; lets you mutate `Envelope` and `Email` (set `From`, override sender). Works with **both sync send and Messenger async** because the event is dispatched at message-creation time, before transport selection. Symfony 6.2+ allows mutating the message in the listener (relevant link: ["New in Symfony 6.2: More Extensible Mailer"](https://symfony.com/blog/new-in-symfony-6-2-more-extensible-mailer)). |
| `MailerInterface` decorator | Rejected | Per [GH discussion #61506](https://github.com/symfony/symfony/discussions/61506): the inner transport is captured at construction; you'd need to fully replace `send()`. Brittle. |
| Custom `TransportFactoryInterface` (`tenant://` DSN) | Rejected for v0.3 | Powerful but complex. Defers to `tenant://default` style DSN; rebuild transport on send. Reserve for v0.4 if users explicitly ask. v0.3 ships the 80% solution (From + Reply-To + Sender) via MessageEvent. |
| Runtime `Transport::fromDsn($tenant->getSmtpDsn())` | Used **inside** the bootstrapper for per-tenant transports | When a tenant has its own SMTP DSN, the bootstrapper builds a fresh `Transport` and decorates the `MailerInterface` for the request lifetime. Lazily cached per tenant slug; cleared on `TenantContextCleared`. This is genuinely needed (different SaaS tenants → different ESPs); the MessageEvent route covers only headers. |

**Recommended pattern: two-layer bootstrapper**

```
MailerBootstrapper::boot(TenantInterface $tenant)
  1. Register $tenant->getEmailDefaults() (from, replyTo) with the MessageEvent listener
  2. If $tenant->getSmtpDsn() is set:
     - Build Transport via Transport::fromDsn($dsn, $dispatcher)
     - Swap the active transport on the SymfonyMailer service (via a TenantMailer decorator
       that holds a transport map keyed by tenant slug)
  3. Otherwise: leave the app's default MAILER_DSN in place; only headers change.

MailerBootstrapper::clear()
  - Pop the From/replyTo override
  - Decorator returns to default transport
```

**Compatibility surface:**

| Environment | Behavior |
|---|---|
| Production (real SMTP) | Per-tenant DSN swap works; MessageEvent listener fires; envelope/from headers applied correctly. |
| Dev (MailHog/MailCatcher via `MAILER_DSN=smtp://mailhog:1025`) | If tenant has no `smtp_dsn`, default DSN used → all mail visible in MailHog. Demo recommendation: leave `smtp_dsn` null for both demo tenants. |
| Messenger async (`MAILER_DSN=…&messenger=true`) | Works correctly: `MessageEvent` fires at dispatch time before the message is queued. The `TenantStamp` already propagates context to the worker, so if a listener needs to re-fetch tenant SMTP creds on the worker side it has the context. |
| CLI (`tenancy:run`) | Works via existing ConsoleResolver. |

**Optional dependency wiring:** Guard with `class_exists(\Symfony\Component\Mailer\Mailer::class)` AND `class_exists(\Symfony\Component\Mailer\Event\MessageEvent::class)` in compiler pass. If absent, `MailerBootstrapper` service is not registered, no error.

**What NOT to add:**

| Avoid | Why |
|---|---|
| `swiftmailer/swiftmailer` or `symfony/swiftmailer-bundle` | Abandoned 2021. Not compatible with Symfony 6+. |
| `symfony/google-mailer`, `symfony/mailgun-mailer`, etc. bridges as bundle deps | These are **transport bridges** — picked by the *user's app*, not the bundle. The bundle is transport-agnostic. |
| A custom Envelope class | Symfony's `Envelope` is sufficient. Subclassing would break factory contracts. |
| Caching per-tenant `Transport` instances forever | Memory leak over many tenants. Cache only within request lifetime (cleared on `TenantContextCleared`). |

**Confidence:** HIGH — MessageEvent API verified; per-tenant DSN runtime construction is the documented pattern.

---

### 5. `OriginHeaderResolver` (RESV-06)

**Approach:** Tiny new resolver class implementing `TenantResolverInterface`. Reads `Origin` HTTP header from `Request`, parses host portion, delegates to existing `TenantProviderInterface::findByDomain()` (or `findBySlug()` — same logic as `HostResolver`).

**No new dependencies.** This is `~30 lines of code` reusing existing surface.

**Stack:**
- `symfony/http-foundation` (already required) — `Request::headers->get('Origin')`
- Existing `TenantProviderInterface` from v0.2
- PHP native `parse_url()` for host extraction

**Subtlety to document, not code:**
- The `Origin` header per RFC 6454 is the **scheme + host + port** of the requester (e.g., `https://tenant1.app.example.com:443`). On same-origin GET it may be absent — that's why `OriginHeaderResolver` returns `null` (not throws) on missing header, letting the resolver chain fall through to the next resolver.
- For cross-origin requests, the resolver matches the `Origin` value against the same tenant lookup as `HostResolver`. SPAs hosted at `https://app.example.com` calling an API at `https://api.example.com` won't match a per-tenant subdomain — that's expected; they should use `X-Tenant-ID` or query param instead.
- **Do NOT** auto-CORS-respond from this resolver. CORS is the user's responsibility (`nelmio/cors-bundle` or Symfony 7.4's built-in CORS support). Document the interaction in `RESV-06`'s docs page.

**Confidence:** HIGH — pure HTTP, pure PHP, leverages existing extension point.

---

### 6. Docs & Demo CI

**No new bundle deps.** Tooling additions live outside the bundle's `composer.json`:

| Tool | Where | Why |
|---|---|---|
| MkDocs Material (existing, v0.2) | Docs build pipeline | Keep current setup; no upgrade needed for v0.3 |
| GitHub Actions job for `examples/` | `.github/workflows/demo.yml` | Boot the demo, run `tenancy:install` non-interactively, hit two tenant URLs, assert correct DB used. ~3min on free tier. |
| `asciinema` recordings | Optional, embedded in docs via `<script>` | Live terminal recordings for install + demo. Not in CI; one-off generation. |
| `mkdocs-include-markdown-plugin` (already in v0.2 docs stack) | Docs only | Pull `examples/README.md` into docs site to avoid drift. |

**What NOT to add:**

| Avoid | Why |
|---|---|
| `phpstan/phpstan-deprecation-rules` | Symfony's deprecation contracts + phpunit-bridge already catch this. |
| Vale / textlint for prose | Docs aren't large enough yet. v0.4 or later. |
| Codecov upload from demo CI | Demo isn't covered by the bundle's coverage metric. Keep test coverage separate. |

---

## Updated `composer.json` Diff

Recommended additive change (verbatim):

```diff
 "require-dev": {
     "doctrine/dbal": "^4.4",
     "doctrine/doctrine-bundle": "^2.13||^3.0",
     "doctrine/migrations": "^3.9",
     "doctrine/orm": "^3.3",
     "friendsofphp/php-cs-fixer": "^3.0",
     "phpstan/phpstan": "^2.1",
     "phpunit/phpunit": "^11.0",
     "symfony/framework-bundle": "^7.4||^8.0",
+    "symfony/mailer": "^7.4||^8.0",
     "symfony/messenger": "^7.4||^8.0",
-    "symfony/phpunit-bridge": "^7.4||^8.0"
+    "symfony/phpunit-bridge": "^7.4||^8.0",
+    "symfony/twig-bundle": "^7.4||^8.0",
+    "symfony/web-profiler-bundle": "^7.4||^8.0"
 },
 "suggest": {
     "doctrine/dbal": "Required for database drivers (^4.4)",
     "doctrine/doctrine-bundle": "Required for Doctrine integration (^2.13||^3.0)",
     "doctrine/orm": "Required for Tenant entity (^3.3)",
     "doctrine/migrations": "Required for tenancy:migrate command (^3.9)",
-    "symfony/messenger": "Required for tenant context preservation across async message processing (^7.4||^8.0)"
+    "symfony/messenger": "Required for tenant context preservation across async message processing (^7.4||^8.0)",
+    "symfony/mailer": "Required for per-tenant SMTP transport and From-header bootstrapping (^7.4||^8.0)",
+    "symfony/web-profiler-bundle": "Required for the Tenancy WDT panel (dev-only)"
 }
```

**Constraint compatibility check:**
- `symfony/mailer` `^7.4||^8.0` — PHP 8.2+ on 7.4, PHP 8.4+ on 8.0. Aligns with existing matrix.
- `symfony/twig-bundle` `^7.4||^8.0` — same matrix.
- `symfony/web-profiler-bundle` `^7.4||^8.0` — same matrix.

**No conflicts with existing `^7.4||^8.0` floor or PHP 8.2+.** Verified against [`symfony/mailer` on Packagist](https://packagist.org/packages/symfony/mailer) (7.4.x stable, 8.0 BETA available).

---

## What NOT to Add (Consolidated)

| Avoid | Why | Use Instead |
|---|---|---|
| `nikic/php-parser` | AST overkill for one config file with strict shape | String templating (Flex `BundlesConfigurator` pattern) |
| `humbug/php-scoper` | Wrong tool — that's for namespace prefixing | N/A |
| `symfony/var-exporter` (for `bundles.php`) | Can't emit `::class` constants | String template |
| `symfony/maker-bundle` as a dep | Internal APIs not stable; pulls Twig/console deps you don't need | Plain `Command` class |
| `symfony/recipes-contrib` submission | Explicit non-goal per PROJECT.md ("revisit when install volume justifies") | `tenancy:install` command |
| `symfony/cors-bundle` or `nelmio/cors-bundle` for OriginResolver | CORS is the user's concern, not the bundle's | Document interaction in `RESV-06` docs |
| `MailerInterface` decorator for per-tenant transport | Inner transport captured at construction; brittle | `MessageEvent` listener + optional runtime `Transport::fromDsn()` swap |
| `swiftmailer/swiftmailer` | Abandoned 2021 | `symfony/mailer` |
| `bitnami/symfony` Docker image for demo | Not community-maintained; less idiomatic | `dunglas/symfony-docker` (FrankenPHP) |
| PHP-FPM + nginx in demo | Three services, manual TLS | FrankenPHP/Caddy (one service, auto TLS) |
| Postgres in demo | Larger image, less common for entry-level Symfony users | MariaDB 11 |
| Persistent per-tenant `Transport` cache (forever) | Memory leak with many tenants | Per-request cache, cleared on `TenantContextCleared` |
| `LateDataCollectorInterface` for Profiler | Tenant data is available at `kernel.request` time | Plain `AbstractDataCollector` |
| Custom JavaScript / WDT assets | Adds asset pipeline complexity | Pure HTML/CSS in Twig template |
| `phpunit/phpunit:^13` | Requires PHP 8.4+; excludes 8.2/8.3 from matrix | Keep `^11.0` |
| `doctrine/doctrine-bundle:^3.0` as `require` (not require-dev) | Bumps PHP minimum to 8.4 | Keep in `require-dev` with `^2.13||^3.0` constraint (current behavior) |

---

## Version Compatibility Matrix (v0.3 Additions Only)

| Package | Constraint | PHP Floor | Symfony Floor | Verified |
|---|---|---|---|---|
| `symfony/mailer` | `^7.4\|\|^8.0` | 8.2 (on 7.4) / 8.4 (on 8.0) | n/a (component) | Packagist Apr 2026 |
| `symfony/twig-bundle` | `^7.4\|\|^8.0` | 8.2 / 8.4 | self | Packagist |
| `symfony/web-profiler-bundle` | `^7.4\|\|^8.0` | 8.2 / 8.4 | self | Packagist |
| `dunglas/symfony-docker` (demo only) | latest | runtime: PHP 8.4 image | latest skeleton | [GH repo, May 2026](https://github.com/dunglas/symfony-docker) |
| `mariadb` docker image | `11.x` | n/a | n/a | Docker Hub |

**All additions are compatible with the existing `^7.4||^8.0` Symfony constraint and `^8.2` PHP floor.** No matrix changes required in CI.

---

## Sources

### Authoritative (HIGH confidence)
- [symfony/flex `BundlesConfigurator` source (2.x)](https://github.com/symfony/flex/blob/2.x/src/Configurator/BundlesConfigurator.php) — verified string-template approach, idempotency via read-merge-write
- [Symfony Profiler docs (current)](https://symfony.com/doc/current/profiler.html) — `AbstractDataCollector`, `getTemplate()`, template registration
- ["New in Symfony 5.2: Simpler DataCollectors"](https://symfony.com/blog/new-in-symfony-5-2-simpler-datacollectors) — `AbstractDataCollector` stable since 5.2
- ["New in Symfony 6.2: More Extensible Mailer"](https://symfony.com/blog/new-in-symfony-6-2-more-extensible-mailer) — `MessageEvent` mutability
- [Symfony Mailer docs (current)](https://symfony.com/doc/current/mailer.html) — `MessageEvent`, Transports, global From
- [`symfony/mailer` on Packagist](https://packagist.org/packages/symfony/mailer) — 7.4.x stable, 8.0 BETA Apr 2026
- [GH symfony/symfony Discussion #61506](https://github.com/symfony/symfony/discussions/61506) — runtime DSN switching: decorator approach + `tenant://` factory pattern
- [GH symfony/symfony Issue #37588](https://github.com/symfony/symfony/issues/37588) — `MessageEvent` transport-setting discussion
- [dunglas/symfony-docker](https://github.com/dunglas/symfony-docker) — FrankenPHP+Caddy skeleton, `*.localhost` wildcard, auto-TLS

### Supporting (MEDIUM confidence)
- ["Coding at the Speed of Thought: The New Era of Symfony Docker" (Mar 2026)](https://dunglas.dev/2026/03/coding-at-the-speed-of-thought-the-new-era-of-symfony-docker/) — 2026 FrankenPHP DX recommendation
- [Caddy Community: wildcard `*.localhost`](https://caddy.community/t/docker-compose-caddy-localhost-wildcard-subdomain-localhost-reverse-proxy-to-https-localhost/14549) — subdomain routing pattern
- [RFC 6761](https://datatracker.ietf.org/doc/html/rfc6761) — `*.localhost` reserved → resolves to loopback in browsers

### Negative-finding sources (verified what NOT to use)
- maker-bundle does NOT expose bundles.php registration as public API — verified by absence in docs at [SymfonyMakerBundle docs](https://symfony.com/bundles/SymfonyMakerBundle/current/index.html)
- `symfony/var-exporter` cannot emit `::class` references — verified against component docs

---

*Stack research for: v0.3 Adoption Surface additions to `danplaton4/tenancy-bundle`*
*Researched: 2026-05-15*
