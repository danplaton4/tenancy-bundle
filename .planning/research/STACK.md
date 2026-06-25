# Stack Research — v0.5 Operations & Scale

**Domain:** Symfony reusable bundle — production-operations features for `danplaton4/tenancy-bundle`
**Researched:** 2026-06-25
**Confidence:** HIGH (all recommendations verified against official sources or Packagist; one MEDIUM call-out on self-contained health check approach)
**Scope:** Additive only. The existing v0.4.1 stack (PHP ^8.2, Symfony ^7.4||^8.0||^8.1, Doctrine ORM/DBAL 4, Flysystem, Messenger, `symfony/process`, `nikic/php-parser`) is fixed. This document covers *what is new or changed for OPS-01 / OPS-02 / ISOL-07*.

---

## Executive Summary

**Net new production dependencies: ZERO.**

All three v0.5 features can be built using components already in `require` or `require-dev`, plus one optional integration hook for LiipMonitorBundle (OPS-02). No new packages enter the bundle's hard `require` list.

Key calls:
- **OPS-01 (maintenance mode):** Build in-bundle. `lexik/maintenance-bundle` is abandoned (last release 2018, Packagist confirms). The only maintained forks target Symfony ≤7.0. Use Symfony's own `CacheInterface` (already `require`d via `symfony/cache`) as the storage backend for per-tenant flags — it is distributed-safe, testable, and already namespaced per-tenant by the bundle's `CacheBootstrapper`. A `kernel.request` listener at priority 19 (one tick below the existing orchestrator at 20) intercepts post-resolution requests and returns `503 JsonResponse` for tenants in maintenance. Storage abstraction with a swappable driver (`CacheMaintenanceDriver`, `ArrayMaintenanceDriver` for tests) keeps the design extensible.
- **OPS-02 (health checks):** The integration target is `liip/monitor-bundle` ^2.25 as an **optional** dependency, integrated via a compiler pass that registers bundle-provided checks only when `LiipMonitorBundle` is present (`class_exists` guard). The alternative — `macpaw/symfony-health-check-bundle` v2.1.0 — is smaller but exposes a weaker check model (returns a `Response`, not a `CheckResult`). LiipMonitor's tag-autoconfiguration + `laminas/laminas-diagnostics` `CheckInterface` is the de-facto Symfony monitoring standard (9M installs). Self-contained health endpoint is a viable fallback pattern that adds ~40 lines and zero deps, documented as the "no-monitor-bundle" path.
- **ISOL-07 (parallel migrations):** `symfony/process` (already in `require`) is sufficient. No additional library is warranted. The bounded-pool pattern — a work queue + `$process->start()` / `$process->isRunning()` poll loop with configurable `--workers` cap — is pure PHP and well-documented. The key constraint is DB connection limits: each worker opens a new DBAL connection, so the default pool cap should be conservative (4 workers). The existing `TenantMigrateCommand` becomes the model for a `ParallelTenantMigrateCommand`, or the existing command gets a `--workers` flag.

---

## Feature-by-Feature Stack

---

### OPS-01: Tenant-Level Maintenance Mode

#### Decision: Build in-bundle, no external library

**`lexik/maintenance-bundle`** — Packagist confirms **abandoned** (v2.1.5, February 2018). Only targets Symfony ~2.7/~3.0/^4.0. No Symfony 7/8 support. Do not use.

Maintained forks found:
- `toshy/maintenance-bundle` v3.1.0 (Jan 2026) — supports Symfony 6/7/8. *However:* designed as a global-site maintenance toggle, not per-tenant. Adding per-tenant semantics would require monkey-patching its driver or event listener. More complexity than building natively.
- `prolix/maintenance-bundle` — Symfony 6/7 only; no Symfony 8 tag.

**Verdict:** Neither fork supports per-tenant toggle natively. The feature is small enough (~3 classes) that building it in-bundle is the right call.

#### Storage backend: `symfony/cache` (already required)

The bundle already requires `symfony/cache` and has `TenantAwareCacheAdapter` for per-tenant cache namespacing. Maintenance flags are a natural fit:

```php
// Key: "tenancy_maintenance_{slug}" stored in the landlord cache pool (NOT the
// per-tenant namespaced pool — the flag must be readable BEFORE tenant boot).
// Use cache.app (the system/landlord pool); TenantAwareCacheAdapter's
// withSubNamespace() is NOT used here by design.
$item = $this->cache->getItem("tenancy_maintenance_{$slug}");
$isUnderMaintenance = $item->isHit() && true === $item->get();
```

Why cache over file-system or DB:
- **File:** Fails in distributed/horizontal-scale deployments (requires shared filesystem or replication). The article at camillehdl.dev explicitly lists this as a drawback. Not acceptable for a "operations & scale" feature.
- **DB:** Requires Doctrine, which is an optional dep. Can't be the default driver.
- **Cache:** Already required. Supports Redis/Memcached (distributed) transparently via the user's `cache.app` configuration. Per-item TTL possible. Testable with `ArrayAdapter`.

**Driver abstraction** (recommended pattern):

```php
interface MaintenanceDriverInterface {
    public function isUnderMaintenance(string $slug): bool;
    public function enable(string $slug): void;
    public function disable(string $slug): void;
}

final class CacheMaintenanceDriver implements MaintenanceDriverInterface { ... }
final class ArrayMaintenanceDriver implements MaintenanceDriverInterface { ... }  // tests
```

The `CacheMaintenanceDriver` uses `CacheInterface` from `symfony/cache` (already in `require`). This keeps the storage swappable if users want DB-backed flags in future.

#### Request interception: `kernel.request` listener at priority 19

The existing `TenantContextOrchestratorListener` runs at **priority 20**. The maintenance listener must run at **priority 19** (lower = later) so:
1. Orchestrator resolves tenant at priority 20 — `TenantContext` is populated.
2. `MaintenanceModeListener` at priority 19 reads the resolved tenant slug, checks the flag, returns `JsonResponse(['status' => 'maintenance'], 503)`.

The 503 response must set `Retry-After` header per RFC 7231. IP allow-listing for landlord/health routes is handled by checking `TenantContext::getTenant()` — if null (landlord route), bypass maintenance check entirely.

#### No new `require` dependencies

| Component | Already in `require`? | How used |
|---|---|---|
| `symfony/cache` | YES (`^7.4\|\|^8.0`) | `CacheInterface` for flag storage |
| `symfony/http-foundation` | YES | `JsonResponse`, `Request` |
| `symfony/http-kernel` | YES | `RequestEvent` listener |
| `symfony/event-dispatcher` | YES | Service tagging |

New CLI commands: `tenancy:maintenance:enable <slug>` and `tenancy:maintenance:disable <slug>` — both use `symfony/console` (already in `require`).

**Confidence:** HIGH — `lexik` abandonment verified on Packagist; `symfony/cache` CacheInterface usage is established bundle pattern.

---

### OPS-02: Health Check / MonitorBundle Integration

#### Decision: Optional LiipMonitorBundle integration via compiler pass

**Primary integration target: `liip/monitor-bundle` ^2.25**

| Attribute | Value |
|---|---|
| Latest stable | 2.25.0 (2026-03-23) |
| PHP | `^8.1` (bundle is `^8.2`, compatible) |
| Symfony | `^6.4\|\|^7.0\|\|^8.0` (covers 7.4, 8.0, 8.1) |
| Core dep | `laminas/laminas-diagnostics ^1.27` |
| Packagist installs | 9,028,051 (de-facto standard) |
| License | MIT |

The integration model: the bundle ships `TenantConnectivityCheck` and `BootstrapperHealthCheck` classes implementing `Laminas\Diagnostics\Check\CheckInterface`. A `HealthCheckIntegrationPass` compiler pass registers these as tagged services with `liip_monitor.check` **only if** `class_exists(\LiipMonitorBundle\LiipMonitorBundle::class)` (or the relevant CheckInterface). Zero overhead when LiipMonitorBundle is absent.

Custom check skeleton:

```php
use Laminas\Diagnostics\Check\CheckInterface;
use Laminas\Diagnostics\Result\Success;
use Laminas\Diagnostics\Result\Failure;

final class TenantConnectivityCheck implements CheckInterface
{
    public function check(): ResultInterface
    {
        // Probe each tenant's DB connection; return Failure on any unreachable
        foreach ($this->tenantProvider->findAll() as $tenant) {
            // attempt connection, catch \Throwable
        }
        return new Success('All tenant connections reachable');
    }

    public function getLabel(): string
    {
        return 'Tenancy: DB connectivity';
    }
}
```

**Why LiipMonitorBundle over alternatives:**

| Option | Verdict | Reason |
|---|---|---|
| **`liip/monitor-bundle` ^2.25** | **CHOSEN** | 9M installs, actively maintained (2026-03-23 release), Symfony 7/8 compat, tag-based auto-registration, established `CheckInterface` from `laminas/laminas-diagnostics`. Integrates with Nagios, Kubernetes, and uptime monitors via `/monitor/health` JSON endpoint. The `laminas-diagnostics` dep is a diagnostics library, not a framework — adds ~50KB. |
| `macpaw/symfony-health-check-bundle` v2.1.0 | Viable fallback | Actively maintained (2026-05-06, Symfony ^6.4\|\|^7.4\|\|^8.0). Lighter — no Laminas dep. But check API is weaker: returns a custom `Response` DTO, not a `CheckResult`. YAML-only service registration. 1.3M installs vs 9M. Better if consumer wants zero Laminas dep. |
| `laminas/laminas-diagnostics` standalone (no bundle) | Rejected | Would require shipping a full HTTP endpoint in the bundle. Over-engineering. |
| Self-contained health controller | Fallback (see below) | Zero deps, ~40 lines. Document as "no-monitor-bundle" path. |
| PSR health check libs (`php-health-check`, etc.) | Rejected | Fragmented ecosystem, low adoption, no Symfony integration. |

**Self-contained health endpoint (no-monitor-bundle fallback)**

For consumers who do not use LiipMonitorBundle, the bundle should expose a simple controller and route:

```
GET /tenancy/health              → 200 {"status":"ok","tenants":{…}} or 503
GET /tenancy/health/{slug}       → 200 {"status":"ok"} or 503 per-tenant
```

This is a plain Symfony controller (~40 lines), registered via `prependExtension` only when `tenancy.health.enabled: true` in bundle config. No new deps. The LiipMonitorBundle integration is additive on top of this.

**What NOT to add:**

| Avoid | Why |
|---|---|
| `macpaw/symfony-health-check-bundle` as required dep | Optional only; consumers may already use LiipMonitor |
| `liip/monitor-bundle` in `require` (hard) | Must remain OPTIONAL — health check monitoring is not core bundle functionality |
| PSR-7 / PSR-15 health-check libraries | Wrong abstraction for Symfony; HttpFoundation is the Symfony contract |
| Kubernetes liveness vs. readiness distinction in bundle code | Out of scope; document the distinction, let consumers wire it |

**No new `require` dependencies for OPS-02.** The LiipMonitorBundle integration is `require-dev` + `suggest`:

```diff
 "require-dev": {
+    "liip/monitor-bundle": "^2.25",
 },
 "suggest": {
+    "liip/monitor-bundle": "Enables LiipMonitorBundle health check integration — registers per-tenant connectivity and bootstrapper probe checks (^2.25)",
 }
```

**Confidence:** HIGH on LiipMonitorBundle compat (Packagist verified); MEDIUM on self-contained endpoint (standard pattern, no external source needed).

---

### ISOL-07: Parallel `tenancy:migrate` via `symfony/process`

#### Decision: `symfony/process` is sufficient — no additional library

`symfony/process` is already in `require` (^7.4||^8.0, promoted in Phase 07 for `tenancy:run`). The Symfony Process component docs (current) confirm:

- `$process->start()` — starts process asynchronously
- `$process->isRunning()` — non-blocking poll
- `$process->wait()` — blocking until completion

There is **no native ProcessPool in Symfony** — confirmed by docs and a long-standing GitHub issue (#8454, open since 2013). All third-party pool wrappers (`spatie/async`, `graze/parallel-process`, `bluepsyduck/symfony-process-manager`, etc.) are thin wrappers that implement the same pattern: work queue + `start()`/`isRunning()` poll loop. Adding any of them as a dependency is not justified — the pattern is 20 lines.

**Why NOT to add external pool libraries:**

| Library | Verdict |
|---|---|
| `spatie/async` | Designed for PHP closures in child processes, not `bin/console` subprocesses. Adds serialization overhead. Wrong abstraction. |
| `amphp/parallel` | Event-loop-based; requires amphp/event-loop runtime. Heavy. Wrong fit for a CLI command. |
| `graze/parallel-process` | Thin wrapper. Adds a dep for ~20 lines of equivalent code. Last release 2021. |
| `bluepsyduck/symfony-process-manager` | Low adoption, low maintenance. Same pattern, more dep surface. |
| `react/child-process` | Event-loop-based. Same objection as amphp. |

**Bounded-pool pattern (implement directly in command):**

```php
// Pseudocode — actual implementation adds output buffering, exit-code tracking, etc.
$queue   = $tenants;   // list<TenantInterface>
$running = [];         // list<Process>
$workers = min($input->getOption('workers'), count($tenants));

while ($queue || $running) {
    // Fill pool
    while (count($running) < $workers && $queue) {
        $tenant  = array_shift($queue);
        $process = new Process([PHP_BINARY, 'bin/console', 'tenancy:migrate',
                                '--tenant=' . $tenant->getSlug()]);
        $process->start();
        $running[$tenant->getSlug()] = $process;
    }
    // Reap finished
    foreach ($running as $slug => $process) {
        if (!$process->isRunning()) {
            // record success/failure, emit output
            unset($running[$slug]);
        }
    }
    usleep(50_000); // 50ms poll interval — low CPU overhead
}
```

Each worker calls the *existing* `tenancy:migrate --tenant=<slug>` subprocess, which already handles the full migration lifecycle (DBAL connection, DependencyFactory, etc.). This avoids duplicating migration logic.

#### DB connection constraint — the critical sizing factor

Each parallel worker opens a dedicated DBAL connection to the tenant database. For `database_per_tenant` mode:
- Each worker = 1 connection
- N workers = N simultaneous connections
- MariaDB/MySQL default `max_connections` = 151
- A single Symfony app process holding the landlord connection already consumes 1

**Default `--workers` recommendation: 4**

Rationale:
- Covers 99% of self-hosted SaaS fleets (< 20 tenants)
- Stays well below typical `max_connections` limits
- 4× speedup over sequential for the median fleet
- Users with 100+ tenants and a dedicated DB server can raise `--workers` explicitly

**`--workers` flag** on the migrate command (or a new `--parallel` flag) with a sensible cap:

```
--workers=N   Maximum parallel migrations (default: 4, max enforced: 32)
```

Hard-cap at 32 in code to prevent accidental `--workers=500` + DB crash.

#### No new `require` dependencies for ISOL-07

`symfony/process` is already in `require`. The only additive change is the `--workers` option on the migrate command.

**Confidence:** HIGH — confirmed by Symfony Process docs and lack of native pool API.

---

## Updated `composer.json` Diff

Recommended additive changes (verbatim):

```diff
 "require-dev": {
     "doctrine/dbal": "^4.4",
     "doctrine/doctrine-bundle": "^2.13||^3.0",
     "doctrine/migrations": "^3.9",
     "doctrine/orm": "^3.3",
     "friendsofphp/php-cs-fixer": "^3.0",
     "league/flysystem": "^3.34",
     "league/flysystem-bundle": "^3.7",
     "league/flysystem-memory": "^3.31",
+    "liip/monitor-bundle": "^2.25",
     "phpstan/extension-installer": "^1.4",
     "phpstan/phpstan": "^2.1",
     "phpstan/phpstan-doctrine": "^2.0",
     "phpunit/phpunit": "^11.0",
     "symfony/framework-bundle": "^7.4||^8.0",
     "symfony/mailer": "^7.4||^8.0",
     "symfony/messenger": "^7.4||^8.0",
     "symfony/mime": "^7.4||^8.0",
     "symfony/phpunit-bridge": "^7.4||^8.0",
     "symfony/twig-bundle": "^7.4||^8.0",
     "symfony/web-profiler-bundle": "^7.4||^8.0"
 },
 "suggest": {
     "doctrine/dbal": "Required for database drivers (^4.4)",
     "doctrine/doctrine-bundle": "Required for Doctrine integration (^2.13||^3.0)",
     "doctrine/orm": "Required for Tenant entity (^3.3)",
     "doctrine/migrations": "Required for tenancy:migrate command (^3.9)",
     "league/flysystem-bundle": "Required for per-tenant Filesystem bootstrapper (^3.7)",
     "league/flysystem-memory": "In-memory Flysystem adapter for the memory:// DSN scheme (^3.31)",
+    "liip/monitor-bundle": "Enables LiipMonitorBundle health check integration — per-tenant connectivity + bootstrapper probes (^2.25)",
     "phpstan/extension-installer": "For zero-config auto-loading of the tenancy PHPStan rules",
     "phpstan/phpstan-doctrine": "For full Doctrine metadata support in PHPStan Rule 3",
     "symfony/mailer": "Required for per-tenant mailer bootstrapper (^7.4||^8.0)",
     "symfony/messenger": "Required for tenant context preservation across async processing (^7.4||^8.0)",
     "symfony/web-profiler-bundle": "Adds a 'Tenancy' panel to the Symfony Profiler (dev-only)"
 }
```

**One-line summary:** `liip/monitor-bundle ^2.25` in `require-dev` + `suggest`. Nothing else changes.

---

## What NOT to Add (Consolidated)

| Avoid | Why | Use Instead |
|---|---|---|
| `lexik/maintenance-bundle` (any version) | Abandoned 2018; latest stable targets Symfony ^4.0 only | Build in-bundle with `CacheInterface` flag |
| `toshy/maintenance-bundle` / `prolix/maintenance-bundle` | Community forks with no per-tenant semantics; adds dep for feature that is 3 classes | Build in-bundle |
| `liip/monitor-bundle` in `require` (hard dep) | Health monitoring is optional infra, not core tenancy | `require-dev` + `suggest` with `class_exists` guard |
| `macpaw/symfony-health-check-bundle` | Weaker check API, fewer installs; acceptable as user-land alternative but not what the bundle targets | Document as alternative in OPS-02 docs |
| PSR health-check libs (`php-health-check`, oat-sa, etc.) | Fragmented, low Symfony 7/8 adoption | LiipMonitorBundle or self-contained endpoint |
| `spatie/async` | Designed for PHP closures, not `bin/console` subcommands | Native `Process::start()` + poll loop |
| `amphp/parallel` / `react/child-process` | Event-loop runtime required; wrong abstraction for CLI command | Native `Process::start()` + poll loop |
| `graze/parallel-process` | Thin wrapper around the same pattern; last release 2021; adds dep for 20 lines of code | Implement bounded pool directly |
| `bluepsyduck/symfony-process-manager` | Low adoption, low maintenance | Implement bounded pool directly |
| `symfony/lock` for migration concurrency | Distributed lock is not needed — the pool is local to a single `tenancy:migrate` invocation; concurrent invocations of `tenancy:migrate` itself are a user-ops concern | Document in runbook: "run only one `tenancy:migrate` at a time" |
| `doctrine/dbal` async drivers | DBAL RFC #5117 (async connection pool) is open but unimplemented; not available | Sequential per-worker DBAL connections |

---

## New Classes by Feature (Architect's View)

### OPS-01 Maintenance Mode

| Class | Type | Notes |
|---|---|---|
| `MaintenanceDriverInterface` | Interface | `isUnderMaintenance(slug)`, `enable(slug)`, `disable(slug)` |
| `CacheMaintenanceDriver` | Implementation | Uses `CacheInterface` (cache.app) |
| `ArrayMaintenanceDriver` | Implementation | In-memory; used by tests |
| `MaintenanceModeListener` | `kernel.request` listener at priority 19 | Returns 503 JsonResponse; bypasses null-tenant (landlord routes) |
| `TenantMaintenanceEnableCommand` | `tenancy:maintenance:enable <slug>` | Uses `symfony/console` |
| `TenantMaintenanceDisableCommand` | `tenancy:maintenance:disable <slug>` | Uses `symfony/console` |
| `MaintenancePass` (compiler pass) | Registers driver service | Wires `CacheMaintenanceDriver` as default |

### OPS-02 Health Checks

| Class | Type | Notes |
|---|---|---|
| `TenantConnectivityCheck` | Implements `Laminas\Diagnostics\Check\CheckInterface` | Probes each tenant DBAL connection |
| `BootstrapperHealthCheck` | Implements `Laminas\Diagnostics\Check\CheckInterface` | Dry-runs each bootstrapper's `check()` (new optional interface) |
| `TenantHealthController` | Symfony controller | Self-contained `/tenancy/health` endpoint (no-monitor-bundle fallback) |
| `HealthCheckIntegrationPass` | Compiler pass | Registers checks as `liip_monitor.check` services; guarded by `class_exists(\LiipMonitorBundle\LiipMonitorBundle::class)` |

### ISOL-07 Parallel Migrations

| Class | Type | Notes |
|---|---|---|
| `TenantMigrateCommand` (updated) | Existing command | Add `--workers=4` option; when > 1, delegate to pool runner |
| `MigrationWorkerPool` | Internal helper | Work queue + `Process::start()` / `isRunning()` loop |

---

## Version Compatibility Matrix (v0.5 Additions Only)

| Package | Constraint | PHP | Symfony | Verified |
|---|---|---|---|---|
| `liip/monitor-bundle` | `^2.25` | `^8.1` | `^6.4\|\|^7.0\|\|^8.0` | Packagist 2026-06-25 |
| `laminas/laminas-diagnostics` | `^1.27` (transitive via liip) | `^8.1` | n/a (component) | Packagist 2026-06-25 |

**No PHP floor change.** The bundle requires PHP `^8.2`; liip requires `^8.1`. Compatible.
**No Symfony constraint change.** liip requires `^6.4\|\|^7.0\|\|^8.0`; bundle requires `^7.4\|\|^8.0`. Intersection is `^7.4\|\|^8.0`. Compatible.

---

## Sources

### Authoritative (HIGH confidence)
- [lexik/maintenance-bundle on Packagist](https://packagist.org/packages/lexik/maintenance-bundle) — confirmed abandoned, v2.1.5 Feb 2018, Symfony ^4.0 only
- [liip/monitor-bundle on Packagist](https://packagist.org/packages/liip/monitor-bundle) — v2.25.0 (2026-03-23), Symfony ^6.4|^7.0|^8.0, PHP ^8.1
- [LiipMonitorBundle README (2.x branch)](https://github.com/liip/LiipMonitorBundle/blob/2.x/README.md) — custom check interface, tag name `liip_monitor.check`, autoconfiguration
- [macpaw/symfony-health-check-bundle on Packagist](https://packagist.org/packages/macpaw/symfony-health-check-bundle) — v2.1.0 (2026-05-06), Symfony ^6.4|^7.4|^8.0, PHP ^8.1
- [Symfony Process Component docs (current)](https://symfony.com/doc/current/components/process.html) — `start()`, `isRunning()`, `wait()` APIs; confirmed no native pool
- [symfony/symfony Issue #8454](https://github.com/symfony/symfony/issues/8454) — Process pool feature request, open since 2013, confirms no native implementation
- [symfony/cache docs (current)](https://symfony.com/doc/current/cache.html) — `CacheInterface`, `withSubNamespace()`, distributed backends
- Packagist for `toshy/maintenance-bundle` v3.1.0 — Symfony 6/7/8 fork; no per-tenant semantics

### Supporting (MEDIUM confidence)
- [Concurrency with Symfony Process gist (mortenson)](https://gist.github.com/mortenson/f9e51e5d4028c2c9ad196f8592e8081a) — verified bounded pool pattern: queue + `isRunning()` filter loop; demonstrated 14 commands in 10s vs 22s
- [Symfony Maintenance Mode — camillehdl.dev](https://camillehdl.dev/symfony-maintenance-mode/) — file-based approach and its distributed-system limitations (justifies cache-based approach)
- [MySQL connection limits — infomaniak.com](https://www.infomaniak.com/en/support/faq/471/understanding-simultaneous-connection-limits-in-mysql-mariadb) — `max_connections` default 151; basis for `--workers=4` default recommendation

---

*Stack research for: v0.5 Operations & Scale additions to `danplaton4/tenancy-bundle`*
*Researched: 2026-06-25*
