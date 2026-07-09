[![CI](https://github.com/danplaton4/tenancy-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/danplaton4/tenancy-bundle/actions/workflows/ci.yml)
[![demo-smoke](https://github.com/danplaton4/tenancy-bundle/actions/workflows/demo-smoke.yml/badge.svg)](https://github.com/danplaton4/tenancy-bundle/actions/workflows/demo-smoke.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/danplaton4/tenancy-bundle.svg)](https://packagist.org/packages/danplaton4/tenancy-bundle)
[![PHP Version](https://img.shields.io/packagist/php-v/danplaton4/tenancy-bundle.svg)](https://packagist.org/packages/danplaton4/tenancy-bundle)
[![License](https://img.shields.io/packagist/l/danplaton4/tenancy-bundle.svg)](https://packagist.org/packages/danplaton4/tenancy-bundle)
[![codecov](https://codecov.io/gh/danplaton4/tenancy-bundle/branch/master/graph/badge.svg)](https://codecov.io/gh/danplaton4/tenancy-bundle)

# Tenancy Bundle

> **Multi-tenancy for Symfony. Zero boilerplate, zero leaks.**

[Documentation](https://danplaton4.github.io/tenancy-bundle/) · [Runnable demo](examples/saas/README.md) · [Changelog](CHANGELOG.md) · [Roadmap](https://danplaton4.github.io/tenancy-bundle/roadmap/) · [Upgrade guide](UPGRADE.md)

---

Resolve a tenant once at the edge of the request — every Symfony subsystem reconfigures itself for the rest of the lifecycle.

- The DBAL connection switches to the tenant's database (or the Doctrine SQL filter scopes every query)
- Cache pools namespace by tenant
- The Mailer transport swaps to the tenant's SMTP/DSN
- The Flysystem filesystem scopes each tenant's uploads
- Messenger envelopes stamp the active tenant and re-boot it on the consumer side
- Your code stays tenant-unaware:

```php
// Controller — no $tenantId parameter, no manual filtering, no leaks
public function index(InvoiceRepository $repo): Response
{
    return $this->render('invoice/index.html.twig', [
        'invoices' => $repo->findAll(),  // automatically scoped to the active tenant
    ]);
}
```

That's it. The event-driven kernel extension does the rest.

## Why this exists

Laravel has [`stancl/tenancy`](https://github.com/stancl/tenancy). Symfony users have been writing their own glue for years — manual `$tenantId` parameters, leaked queries discovered in production, half-built abstractions that don't compose with Doctrine + Messenger + Cache + Mailer at the same time. This bundle treats tenancy as a first-class kernel extension, not a database switcher bolted on top.

## Quality signals

- **PHPStan level 9** clean — no `@phpstan-ignore`, no `mixed` shortcuts
- **970 tests / 3,830 assertions** across the unit + integration suites
- **CI matrix:** PHP 8.2 / 8.3 / 8.4 × Symfony 7.4 / 8.0 / 8.1, plus `prefer-lowest`, "No Doctrine", "No Messenger", and a `composer audit` supply-chain gate
- **`demo-smoke` live-stack gate:** every push to `master` rebuilds the three-tenant FrankenPHP + Caddy + MariaDB demo and exercises tenant isolation end-to-end via `bin/smoke.sh` (~90s)
- **ASVS-L1 threat model** per phase, security gate before phase verification
- **Strict mode on by default** — a missing tenant on a `#[TenantAware]` entity is an exception, not silent data leakage

## Install

```bash
composer require danplaton4/tenancy-bundle
```

Register the bundle in `config/bundles.php`, then run `bin/console tenancy:init` to scaffold `config/packages/tenancy.yaml`.

> **One-shot setup:** `bin/console tenancy:install` handles both steps in a single command. It uses `nikic/php-parser` to AST-edit `config/bundles.php` safely — install as a dev dependency first: `composer require --dev nikic/php-parser`. Without it the command exits 1 with a clear error and prints the manual snippet to paste.

Configure (`config/packages/tenancy.yaml`):

```yaml
tenancy:
    driver: database_per_tenant   # or shared_db
    database:
        enabled: true
```

Mark tenant-scoped entities (shared-DB mode only):

```php
use Tenancy\Bundle\Attribute\TenantAware;

#[ORM\Entity]
#[TenantAware]
class Invoice { /* ... */ }
```

That's the minimum. See the [Documentation](https://danplaton4.github.io/tenancy-bundle/) for resolver options, custom bootstrappers, Messenger integration, testing, and the contributor guide.

## Try the demo

A runnable three-tenant Symfony app lives under [`examples/saas/`](examples/saas/README.md):

```bash
git clone https://github.com/danplaton4/tenancy-bundle.git
cd tenancy-bundle/examples/saas
docker compose up -d --wait --build           # ~30s warm, ~110s cold
open http://acme.tenancy.localhost/           # or curl -H 'Host: acme.tenancy.localhost' http://localhost/
```

Three tenants (`acme`, `globex`, `initech`) + a landlord page, FrankenPHP + Caddy + MariaDB 11, with the Profiler tab and Mailpit always-up. If host ports 80 / 8025 are already taken on your machine, override:

```bash
PORT_HTTP=8081 PORT_MAILPIT_UI=8026 docker compose up -d --wait --build
BASE_PORT=8081 bash bin/smoke.sh              # DNS-independent isolation proof
```

See [`examples/saas/README.md`](examples/saas/README.md) for the full walkthrough — three-step fallback ladder (curl Host: → `/etc/hosts` → browser-native `*.localhost`), Mailpit + Profiler walkthroughs, CI gate details.

## Features

**Isolation**

- **Database-per-tenant** — DBAL connection switches at runtime per tenant via `TenantDriverMiddleware`, no `wrapper_class` config required
- **Shared-database** — Doctrine SQL filter with the `#[TenantAware]` attribute; zero manual query scoping; strict-mode by default

**Per-tenant subsystems (bootstrappers)**

- **Cache namespace isolation** — per-tenant cache pool prefixing, no cross-tenant bleed
- **Mailer** — per-tenant SMTP DSN + `From` + `Reply-To` headers, sync + async safe via the `X-Transport` strategy
- **Filesystem (Flysystem)** — per-tenant storage in `prefix` mode (default) or a per-tenant adapter for S3-style separation
- **Messenger context propagation** — `TenantStamp` on every envelope, re-booted on consume; works under sync and async transports

**Data sharing**

- **Shared entities (`#[Shared]`)** — landlord-side master records replicate to a tenant-side read-only copy via Doctrine events, with opt-in async fan-out over Messenger and a compile-time `#[Shared]` ⊕ `#[TenantAware]` mutual-exclusion guard. `tenancy:shared:resync` for bulk/initial sync.

**Operations & scale**

- **Maintenance mode** — per-tenant HTTP 503 + `Retry-After`, IP/route/path allow-list bypass, optional Twig template; `tenancy:maintenance:enable|disable|status`. Other tenants and the landlord keep serving.
- **Health checks** — `GET /_tenancy/health/live` + `/_tenancy/health/ready/{slug}` in IETF `application/health+json`, a bounded fleet dashboard, and a `tenancy:health` CLI. Optional `liip/monitor-bundle` auto-registration. Every response is DSN-redacted.
- **Parallel migrations** — `tenancy:migrate --parallel` runs per-tenant migrations concurrently through a bounded subprocess pool (`--concurrency`, `--dry-run`, `--format=json`); the no-flag path stays sequential.

**Resolution & DX**

- **5 built-in resolvers** — Host (subdomain), Origin header (SPA-friendly, allow-listed), `X-Tenant-ID` header, query param, CLI `--tenant` flag. Chain in any order via config; add your own.
- **CLI commands** — `tenancy:install` (one-shot setup), `tenancy:init` (scaffold config), `tenancy:migrate` (per-tenant, `--parallel`), `tenancy:run` (wrap any command in tenant context), plus the maintenance, health, and `shared:resync` commands above
- **Symfony Profiler tab** — "Tenancy" panel in the WDT showing slug, label, driver, resolver, bootstrappers, error state. Auto-registered when `kernel.debug=true`, compile-stripped in prod
- **PHPStan extension** — three consumer-facing static rules (`tenancy.mutualExclusion`, `tenancy.sharedEntityLeak`, `tenancy.tenantIdDrift`) auto-loaded via `phpstan/extension-installer`
- **PHPUnit testing trait** — `InteractsWithTenancy` sets up a clean tenant DB/schema per test method, real SQLite, no mocks
- **Custom entity support** — extend `AbstractTenant` (MappedSuperclass) to add columns like `brandColor`, `plan`, `billingId` without breaking Doctrine inheritance

## How it works

The bundle hooks into the Symfony kernel via a `kernel.request` listener at priority 20 (above Security at 8, below Router at 32). A resolver chain identifies the tenant from the request. Once resolved, `BootstrapperChain` runs every registered bootstrapper to reconfigure its subsystem. On `kernel.terminate`, tenant context is cleared.

```
Request → Router → TenantContextOrchestrator (priority 20)
                         │
                   ResolverChain
                   (Host / Origin / Header / QueryParam / Console)
                         │
                   TenantResolved event
                         │
                   BootstrapperChain.boot()
                    ├─ DatabaseSwitchBootstrapper
                    ├─ DoctrineBootstrapper
                    ├─ CacheBootstrapper
                    ├─ MailerBootstrapper
                    └─ FilesystemBootstrapper
                         │
                   TenantBootstrapped event
                         │
                     Controller runs
                         │
                   kernel.terminate
                         │
                   TenantContextCleared event
```

Bootstrappers are Symfony services tagged with `tenancy.bootstrapper` — add your own by implementing `TenantBootstrapperInterface` and tagging the service. No bundle internals to modify. See the [Custom Bootstrapper guide](https://danplaton4.github.io/tenancy-bundle/contributor-guide/custom-bootstrapper/).

## Comparison

| Feature | danplaton4/tenancy-bundle | stancl/tenancy (Laravel) | RamyHakam (Symfony) | Manual |
|---------|:-------------------------:|:------------------------:|:-------------------:|:------:|
| Database-per-tenant | ✅ | ✅ | ✅ | DIY |
| Shared-DB (SQL filter) | ✅ | ✅ | ❌ | DIY |
| `#[TenantAware]` attribute | ✅ | ❌ (traits) | ❌ | ❌ |
| Cache isolation | ✅ | ✅ | ❌ | ❌ |
| Mailer per-tenant | ✅ | ✅ | ❌ | ❌ |
| Filesystem per-tenant (Flysystem) | ✅ | ✅ | ❌ | ❌ |
| Shared-entity replication (`#[Shared]`) | ✅ | ✅ | ❌ | DIY |
| Messenger context propagation | ✅ | ✅ | ❌ | ❌ |
| 5 resolvers incl. Origin header | ✅ | ✅ | Host only | DIY |
| CLI tenant context (`tenancy:run`) | ✅ | ✅ | ❌ | ❌ |
| Parallel migrations | ✅ | ⚠️ | ❌ | DIY |
| Per-tenant maintenance mode | ✅ | ❌ | ❌ | DIY |
| Health-check endpoints | ✅ | ❌ | ❌ | DIY |
| Strict mode (default ON) | ✅ | ❌ | ❌ | ❌ |
| One-command setup (`tenancy:install`) | ✅ | N/A | ❌ | ❌ |
| PHPUnit testing trait | ✅ | ✅ | ❌ | ❌ |
| PHPStan level 9 + extension | ✅ | ❌ | ❌ | ❌ |
| Symfony Profiler / WDT panel | ✅ | N/A | ❌ | ❌ |
| Runnable demo + CI smoke gate | ✅ | ✅ | ❌ | ❌ |

## Philosophy

A data leak across tenants is a security incident, not a config mistake — so strict mode is on by default. Opt out explicitly if you understand the trade-off.

The bundle is a kernel extension, not just a database switcher: every Symfony subsystem (database, cache, queue, mailer, filesystem) participates in the tenant lifecycle through the same event-driven bootstrapper model. Doctrine is treated as an optional dependency — every entry point is guarded by `class_exists` / `interface_exists`, so the bundle installs cleanly into a Symfony app that doesn't use Doctrine at all.

## Requirements

- PHP `^8.2`
- Symfony `^7.4`, `^8.0`, or `^8.1`
- Optional: `doctrine/orm ^3`, `doctrine/dbal ^4`, `doctrine/migrations`, `symfony/messenger`, `symfony/mailer`, `league/flysystem-bundle`, `liip/monitor-bundle`

## Documentation

The full docs site is published from `docs/` to **<https://danplaton4.github.io/tenancy-bundle/>**.

Highlights:
- [Getting started](https://danplaton4.github.io/tenancy-bundle/user-guide/getting-started/)
- [Database-per-tenant guide](https://danplaton4.github.io/tenancy-bundle/user-guide/database-per-tenant/)
- [Shared-DB guide](https://danplaton4.github.io/tenancy-bundle/user-guide/shared-db/)
- [Cache isolation](https://danplaton4.github.io/tenancy-bundle/user-guide/cache-isolation/)
- [Messenger integration](https://danplaton4.github.io/tenancy-bundle/user-guide/messenger/)
- [Origin-header resolver (SPA)](https://danplaton4.github.io/tenancy-bundle/user-guide/origin-header-resolver/)
- [Profiler tab](https://danplaton4.github.io/tenancy-bundle/user-guide/profiler-tab/)
- [Testing with `InteractsWithTenancy`](https://danplaton4.github.io/tenancy-bundle/user-guide/testing/)
- **Operations:** [maintenance mode](https://danplaton4.github.io/tenancy-bundle/ops/maintenance-mode/) · [health checks](https://danplaton4.github.io/tenancy-bundle/ops/health-checks/) · [parallel migrations](https://danplaton4.github.io/tenancy-bundle/ops/parallel-migrations/)
- [Architecture (contributor guide)](https://danplaton4.github.io/tenancy-bundle/contributor-guide/architecture/)

## Roadmap

See the [roadmap on the documentation site](https://danplaton4.github.io/tenancy-bundle/roadmap/) for what's shipping next and what's tracked-but-unscheduled. Open a [GitHub issue](https://github.com/danplaton4/tenancy-bundle/issues) if you want something prioritized — real demand is the single strongest input to the next milestone's scope.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Bug reports, design discussions, and PRs are all welcome — the bundle is small enough that the first contributor read of the code can land a real change in a single session.

## License

MIT License. See [LICENSE](LICENSE).
