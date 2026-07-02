# Phase 33: Health Checks - Research

**Researched:** 2026-07-02
**Domain:** Symfony bundle — HTTP + CLI health-check surface, IETF application/health+json, liip/monitor-bundle optional integration, probe-safety, DSN redaction
**Confidence:** HIGH (all claims grounded in live v0.4.1 source reads plus verified docs)

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** Route-import IS the HTTP opt-in. Bundle ships `config/routes/health.php`; consuming app imports it with a prefix. No `tenancy.health.enabled` flag for HTTP. CLI command and `TenantHealthChecker` core are always registered.
- **D-02:** Fleet endpoint ships as a SEPARATE importable resource (`config/routes/health_fleet.php`) from live+ready.
- **D-03:** Real connectivity probes, not shallow config checks. DB probe: `close()` + lazy connect + `SELECT 1`.
- **D-04:** Coverage this phase = `DatabaseSwitchBootstrapper` + `SharedDriver` only. Mailer, Filesystem, Doctrine bootstrappers excluded.
- **D-05:** IETF `application/health+json`, states `pass`/`warn`/`fail`. Aggregate = strict worst-of: any `fail` → HTTP 503; else any `warn` → HTTP 200; else `pass` → HTTP 200.
- **D-06:** Unknown `{slug}` on `/ready/{slug}` → HTTP 404 (not 503), body `application/health+json` with `status: fail` + "tenant not found" note.
- **D-07:** Liveness = pure process check. Zero dependency I/O, never iterates tenants, no `degraded` state.
- **D-08:** HTTP fleet endpoint = cap + pagination. Default `limit=50` (hard max ~200), `offset`; sequential probing; rollup summary.
- **D-09:** `tenancy:health --all` is UNBOUNDED and streams per-tenant. Mirrors `tenancy:migrate` per-tenant output, exits non-zero if any tenant fails.

### Claude's Discretion

- `HealthCheckBootstrapperInterface` shape: `check()` return type (a `BootstrapperHealthResult` VO with a status enum + component name + optional detail/output), whether `BootstrapperChain` gains an additive `healthCheck()` vs checker iterating directly, and `TenantHealthReport` aggregate VO — working names; final surfaces are a planning call.
- `HealthResponseSanitizer` should reuse/generalize `src/Mailer/DsnSanitizer.php` regex (single source of truth).
- Config schema (a `health` node in `TenancyBundle::getConfigTreeBuilder()`): fleet default `limit`/hard max and any liip-integration toggle — a planning call. No HTTP `enabled` flag.
- Exact class names/namespaces and placement (`src/Health/`, `src/Controller/`, `src/Command/`, `src/DependencyInjection/Compiler/`) are working names for planning.

### Deferred Ideas (OUT OF SCOPE)

- Filesystem + Mailer bootstrapper probes
- Per-tenant probe-result caching
- Fleet random-sampling mode
- Application-level auth on health endpoints
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| HEALTH-01 | Liveness endpoint `/_tenancy/health/live` — pure process check, no tenant iteration | D-07; TenantContext.hasTenant() never true; controller returns `{"status":"ok"}` |
| HEALTH-02 | Readiness endpoint `/_tenancy/health/ready/{slug}` — IETF application/health+json, HTTP 200 or 503 | D-05/D-06; IETF spec verified; set→probe→clear-in-finally pattern |
| HEALTH-03 | `HealthCheckBootstrapperInterface` sibling; `TenantHealthChecker` never calls `boot()` | BootstrapperChain source read; TenantContext.setTenant/clear API confirmed public |
| HEALTH-04 | No DSNs/credentials in health responses; HTTP endpoints opt-in (route-import) | DsnSanitizer.REDACTION_REGEX verified; D-01 route-import confirmed; no existing route files |
| HEALTH-05 | `tenancy:health [--tenant=slug\|--all]` CLI command | TenantMigrateCommand streaming pattern; exit-code aggregation |
| HEALTH-06 | Aggregate fleet-health endpoint, bounded, NOT k8s probe target | D-08 cap+pagination; D-02 separate route file |
| HEALTH-07 | liip/monitor-bundle auto-register when present, class_exists-guarded | liip tag=`liip_monitor.check`; laminas CheckInterface verified; HealthCheckIntegrationPass pattern |
</phase_requirements>

---

## Summary

Phase 33 adds the bundle's **first HTTP surface** (zero routes existed through v0.4.1 — confirmed by grep of `src/` and `config/`) and a companion CLI command. The implementation is highly additive: every public API on `TenantContext` (`setTenant`, `hasTenant`, `clear`) that `TenantHealthChecker` needs is already public; `BootstrapperChain` gets only one new method (`healthCheck()`) that does not touch `boot()`/`clear()`; both isolation drivers (`DatabaseSwitchBootstrapper`, `SharedDriver`) gain `HealthCheckBootstrapperInterface` alongside their existing `TenantDriverInterface`.

The phase has three genuinely novel areas:

1. **IETF `application/health+json` response shape** — the spec defines `status: pass|warn|fail`, a `checks{}` object, `output` field, and content-type `application/health+json`. The response shape is fully specced; the planner can write exact JSON contracts into task acceptance criteria.

2. **liip/monitor-bundle integration** — tag name is `liip_monitor.check`; checks implement `Laminas\Diagnostics\Check\CheckInterface` with two methods: `check(): ResultInterface` and `getLabel(): string`. The `HealthCheckIntegrationPass` compiler pass follows the exact same `class_exists`-guarded pattern as the existing `MailerTransportContractPass` and `FilesystemContractPass`.

3. **DatabaseSwitchBootstrapper::check() probe safety** — the probe must call `$connection->close()` then trigger a lazy reconnect (via `SELECT 1`) under a manually set `TenantContext`, WITHOUT calling `boot()`. This is safe because `DatabaseSwitchBootstrapper::boot()` already does `close()` and the middleware handles lazy reconnect; the probe reuses the same mechanism in a controlled try/catch/finally envelope. An integration test must verify `TenantContext::hasTenant() === false` after probe completion.

The `HealthResponseSanitizer` is a thin generalization of the existing `DsnSanitizer` — the existing `REDACTION_REGEX = '/(:\/\/[^:\/@]+:)[^@\/]+(@)/'` is already general enough for any `scheme://user:password@host` DSN pattern, not just SMTP.

**Primary recommendation:** Follow the `src/Health/` namespace for the new value objects and core service; `src/Controller/` for the HTTP actions (new territory); `src/DependencyInjection/Compiler/HealthCheckIntegrationPass.php` for the liip guard. Routing lives in `config/routes/health.php` and `config/routes/health_fleet.php` (new files, no existing route infrastructure to conflict with).

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Liveness HTTP response | Backend (Symfony Controller) | — | Returns `{"status":"ok"}` with zero service I/O; pure PHP execution |
| Readiness HTTP response | Backend (Symfony Controller) | Backend (TenantHealthChecker) | Controller routes; checker performs DB probe under TenantContext |
| Fleet HTTP response | Backend (Symfony Controller) | Backend (TenantHealthChecker, TenantProviderInterface) | Controller paginates; checker iterates sequentially |
| DB connectivity probe | Database/DBAL layer | Backend (DatabaseSwitchBootstrapper) | close()+lazy-reconnect+SELECT 1 is a DBAL lifecycle operation |
| TenantContext set/clear for probes | Backend (TenantHealthChecker) | — | Core service owns the probe lifecycle; always clears in finally |
| DSN redaction | Backend (HealthResponseSanitizer) | — | Single source of truth for credential scrubbing from response body |
| liip check auto-registration | DI/Compiler layer | — | HealthCheckIntegrationPass registers services at container build time |
| CLI health reporting | CLI (TenantHealthCommand) | Backend (TenantHealthChecker) | Command is the CLI surface; checker is the shared probe engine |
| Route import (HTTP opt-in) | App config (route file) | — | Bundle ships files; app imports them — routing is always the app's job |

---

## Standard Stack

### Core (all confirmed in live v0.4.1 source)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `symfony/http-foundation` | `^7.4\|\|^8.0` | `JsonResponse`, `Response`, `Request` | Already required; controllers return HttpFoundation responses |
| `symfony/http-kernel` | `^7.4\|\|^8.0` | `AbstractController`, route attributes | Already required; bundle's event listener infrastructure |
| `symfony/routing` | `^7.4\|\|^8.0` | PHP-DSL route file (`config/routes/health.php`) | Already required; Symfony standard routing |
| `symfony/console` | `^7.4\|\|^8.0` | `tenancy:health` command | Already required; existing `TenantMigrateCommand` pattern |
| `doctrine/dbal` | `^4.4` (require-dev, optional) | `Connection` for `SELECT 1` probe | Optional guard; `DatabaseSwitchBootstrapper` already depends on it |

### Supporting (optional integration)

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `liip/monitor-bundle` | `^2.25` | Auto-register bundle checks as `liip_monitor.check` services | Only when consumer installs it; guarded by `class_exists` in `HealthCheckIntegrationPass` |
| `laminas/laminas-diagnostics` | `^1.27` (transitive via liip) | `CheckInterface` + `Success`/`Failure`/`Warning` result types | Transitive; only relevant when liip is installed |

### Package Legitimacy Audit

| Package | Registry | Age | Downloads | Source Repo | Disposition |
|---------|----------|-----|-----------|-------------|-------------|
| `liip/monitor-bundle` | Packagist | 13+ yrs | 9M+ installs | github.com/liip/LiipMonitorBundle | Approved [VERIFIED: Packagist] |
| `laminas/laminas-diagnostics` | Packagist | 10+ yrs (ZF lineage) | Transitive via liip | github.com/laminas/laminas-diagnostics | Approved [VERIFIED: Packagist] |

**No new production dependencies.** Both packages above are `require-dev` + `suggest` only. slopcheck was not run (no new production packages to verify).

---

## Architecture Patterns

### System Architecture Diagram

```
HTTP: GET /_tenancy/health/live
    │
    └─► TenantHealthController::live()
            └─► return JsonResponse({"status":"ok"}, 200)
                Content-Type: application/health+json
                [NO TenantContext touch, NO DB call]

HTTP: GET /_tenancy/health/ready/{slug}
    │
    └─► TenantHealthController::ready($slug)
            ├─► TenantProviderInterface::findBySlug($slug)
            │       ├─ not found → 404 application/health+json {status:fail, output:"tenant not found"}
            │       └─ found → TenantInterface $tenant
            │
            └─► TenantHealthChecker::checkOne($tenant): TenantHealthReport
                    ├─► TenantContext::setTenant($tenant)           ← manual, NOT via orchestrator
                    ├─► try {
                    │       BootstrapperChain::healthCheck($tenant)
                    │           └─ for each bootstrapper implementing HealthCheckBootstrapperInterface:
                    │               DatabaseSwitchBootstrapper::check() → close()+SELECT 1
                    │               SharedDriver::check()              → SELECT 1 (filter active)
                    │   } finally {
                    │       TenantContext::clear()                   ← ALWAYS runs, even on exception
                    │   }
                    └─► TenantHealthReport → HealthResponseSanitizer → JsonResponse
                            HTTP 200 (pass/warn) or 503 (fail)

HTTP: GET /_tenancy/health?limit=50&offset=0
    │
    └─► TenantHealthController::fleet($limit, $offset)
            ├─► TenantProviderInterface::findAll() → slice($offset, $limit)
            └─► for each (sequentially, max $limit tenants):
                    TenantHealthChecker::checkOne($tenant) → BootstrapperHealthResult[]
            └─► HealthResponseSanitizer → JsonResponse {total, offset, limit, summary, tenants:[...]}

CLI: tenancy:health [--tenant=slug] [--all] [--format=json]
    │
    └─► TenantHealthCommand::execute()
            ├─► --tenant=slug → [single tenant]
            └─► --all        → TenantProviderInterface::findAll() (UNBOUNDED)
            for each tenant (streaming output):
                TenantHealthChecker::checkOne($tenant) → per-tenant status line
            exit code: 0 (all pass/warn) or 1 (any fail)

liip/monitor-bundle (when installed):
    HealthCheckIntegrationPass (compiler pass, class_exists-guarded)
        └─► registers TenantConnectivityCheck, BootstrapperHealthCheck
            tagged liip_monitor.check
            implementing Laminas\Diagnostics\Check\CheckInterface
```

### Recommended Project Structure (new files only)

```
src/
├── Health/
│   ├── HealthCheckBootstrapperInterface.php    # sibling to TenantBootstrapperInterface
│   ├── BootstrapperHealthResult.php            # VO: status enum + component + message
│   ├── TenantHealthReport.php                  # VO: per-tenant aggregate of results
│   ├── TenantHealthChecker.php                 # core: set→probe→clear-in-finally
│   └── HealthResponseSanitizer.php             # reuses DsnSanitizer.REDACTION_REGEX
├── Controller/
│   └── TenantHealthController.php              # live/ready/fleet actions (NEW territory)
├── Command/
│   └── TenantHealthCommand.php                 # tenancy:health (mirrors TenantMigrateCommand)
└── DependencyInjection/Compiler/
    └── HealthCheckIntegrationPass.php          # liip guard (mirrors MailerTransportContractPass)

config/routes/
├── health.php                                  # live + ready routes (NEW — bundle's first route files)
└── health_fleet.php                            # fleet route (separate import per D-02)
```

**Modified files:**
- `src/Bootstrapper/BootstrapperChain.php` — add `healthCheck(TenantInterface): array` (additive)
- `src/Bootstrapper/DatabaseSwitchBootstrapper.php` — implement `HealthCheckBootstrapperInterface`
- `src/Driver/SharedDriver.php` — implement `HealthCheckBootstrapperInterface`
- `src/TenancyBundle.php` — register new services in `loadExtension()`, add `HealthCheckIntegrationPass` in `build()`, add `health` config node in `configure()`
- `config/services.php` — wire `TenantHealthChecker`, `TenantHealthCommand`, `TenantHealthController`, `HealthResponseSanitizer`

### Pattern 1: set→probe→clear-in-finally (HEALTH-03 invariant)

The `TenantHealthChecker` MUST use this exact pattern. `boot()` is NEVER called.

```php
// Source: CONTEXT.md canonical invariant, verified against TenantContext source
// src/Health/TenantHealthChecker.php

public function checkOne(TenantInterface $tenant): TenantHealthReport
{
    $this->tenantContext->setTenant($tenant);
    try {
        $results = $this->bootstrapperChain->healthCheck($tenant);
        return new TenantHealthReport($tenant->getSlug(), $results);
    } catch (\Throwable $e) {
        return TenantHealthReport::fromException($tenant->getSlug(), $e);
    } finally {
        $this->tenantContext->clear();   // ALWAYS runs — the probe-safety invariant
    }
}
```

After `checkOne()` returns: `$this->tenantContext->hasTenant() === false` — verified by the `TenantContext::clear()` implementation which sets `$currentTenant = null`. [VERIFIED: src/Context/TenantContext.php line 28]

### Pattern 2: BootstrapperChain::healthCheck() additive method

```php
// Source: derived from live BootstrapperChain source (src/Bootstrapper/BootstrapperChain.php)
// Additive: $this->bootstrappers is already iterated by boot()/clear(); healthCheck() is a NEW method.

public function healthCheck(TenantInterface $tenant): array
{
    $results = [];
    foreach ($this->bootstrappers as $bootstrapper) {
        if ($bootstrapper instanceof HealthCheckBootstrapperInterface) {
            try {
                $results[] = $bootstrapper->check($tenant);
            } catch (\Throwable $e) {
                $results[] = BootstrapperHealthResult::fromException(
                    $bootstrapper::class,
                    $e,
                );
            }
        }
    }
    return $results;
}
```

No BC break: existing `boot()`/`clear()` loop and their event dispatching are untouched. [VERIFIED: src/Bootstrapper/BootstrapperChain.php]

### Pattern 3: DatabaseSwitchBootstrapper::check() probe safety

```php
// Source: DatabaseSwitchBootstrapper.php verified — boot() calls close(), clear() guards isConnected()
// The probe reuses the same close()+lazy-reconnect mechanism that boot() uses.
// TenantContext is already set by TenantHealthChecker before this is called.

public function check(TenantInterface $tenant): BootstrapperHealthResult
{
    try {
        $this->connection->close();
        // Lazy reconnect: TenantDriverMiddleware fires on next query, reads TenantContext
        $this->connection->executeQuery('SELECT 1');
        return new BootstrapperHealthResult(static::class, 'pass');
    } catch (\Throwable $e) {
        return new BootstrapperHealthResult(static::class, 'fail', $e->getMessage(), $e);
    }
}
```

**Probe safety analysis (research flag 2 resolution):**
- `DatabaseSwitchBootstrapper` holds NO tenant-specific state — its docblock says "This class holds no tenant-specific state." [VERIFIED: src/Bootstrapper/DatabaseSwitchBootstrapper.php lines 1-24]
- `close()` nulls the driver-connection reference. The next query triggers DBAL's lazy-connect path which calls `TenantDriverMiddleware` which reads the current `TenantContext`.
- After `TenantHealthChecker::checkOne()` runs its `finally { $this->tenantContext->clear(); }`, the TenantContext is null. The NEXT real request's `TenantContextOrchestrator` sets TenantContext fresh via `ResolverChain` and then calls `BootstrapperChain::boot()` which calls `DatabaseSwitchBootstrapper::boot()` → `close()` again, ensuring the next connection is to the correct tenant.
- **Global service state mutation risk:** The only shared state is the `Connection` object. After the probe, `$connection->isConnected()` may be true (pointing to whichever tenant the probe connected to). The next `boot()` call does `close()` unconditionally, so the state is reset correctly. No permanent mutation.
- **Integration test requirement:** An integration test with SQLite must confirm: (a) after `checkOne()`, `TenantContext::hasTenant() === false`; (b) a subsequent real request connects to the correct (different) tenant without residual state from the probe.

### Pattern 4: SharedDriver::check() probe safety

```php
// Source: SharedDriver.php verified — boot() injects TenantContext into TenantAwareFilter;
// clear() is a no-op. The filter reads TenantContext live at query time.
// Since TenantHealthChecker::setTenant() is already called before check() runs,
// the filter already has the correct context.

public function check(TenantInterface $tenant): BootstrapperHealthResult
{
    try {
        // Filter is live (TenantContext is set by TenantHealthChecker).
        // A SELECT 1 confirms DB connectivity and filter activation.
        $this->em->getConnection()->executeQuery('SELECT 1');
        return new BootstrapperHealthResult(static::class, 'pass');
    } catch (\Throwable $e) {
        return new BootstrapperHealthResult(static::class, 'fail', $e->getMessage(), $e);
    }
}
```

SharedDriver is only registered when `driver: shared_db` and `interface_exists(EntityManagerInterface::class)` — the same guards that already exist for its registration. [VERIFIED: src/TenancyBundle.php lines 441-451]

### Pattern 5: IETF application/health+json response shapes

```php
// Source: draft-inadarei-api-health-check-06 [VERIFIED: WebFetch of IETF draft]
// Content-Type: application/health+json

// Liveness (HEALTH-01) — pure process health, no checks{}
{
    "status": "pass"
}

// Readiness — pass case (HEALTH-02)
{
    "status": "pass",
    "checks": {
        "tenancy:db:acme": [
            {
                "componentId": "database-switch-bootstrapper",
                "componentType": "datastore",
                "status": "pass",
                "time": "2026-07-02T12:00:00Z"
            }
        ]
    }
}

// Readiness — fail case (DB down, DSN REDACTED by HealthResponseSanitizer)
{
    "status": "fail",
    "output": "Connection failed: mysql://user:***@host/db",
    "checks": {
        "tenancy:db:acme": [
            {
                "componentId": "database-switch-bootstrapper",
                "componentType": "datastore",
                "status": "fail",
                "output": "Connection failed: mysql://user:***@host/db",
                "time": "2026-07-02T12:00:00Z"
            }
        ]
    }
}

// Unknown slug (D-06) — HTTP 404
{
    "status": "fail",
    "output": "Tenant 'unknown-slug' not found"
}

// Fleet response (D-08, HEALTH-06)
{
    "total": 1240,
    "offset": 0,
    "limit": 50,
    "summary": {"pass": 45, "warn": 3, "fail": 2},
    "tenants": [
        {"slug": "acme", "status": "pass"},
        {"slug": "globex", "status": "fail", "output": "Connection refused"}
    ]
}
```

**HTTP status mapping (D-05):**
- `pass` → HTTP 200
- `warn` → HTTP 200
- `fail` → HTTP 503
- Unknown slug → HTTP 404 (D-06)

**Content-Type header:** `application/health+json` on ALL health responses (including liveness). [VERIFIED: IETF draft]

### Pattern 6: liip/monitor-bundle CheckInterface

```php
// Source: laminas/laminas-diagnostics CheckInterface [VERIFIED: WebFetch GitHub]
// Tag for auto-registration: liip_monitor.check [VERIFIED: LiipMonitorBundle README]

use Laminas\Diagnostics\Check\CheckInterface;
use Laminas\Diagnostics\Result\ResultInterface;
use Laminas\Diagnostics\Result\Success;
use Laminas\Diagnostics\Result\Failure;
use Laminas\Diagnostics\Result\Warning;

// Interface contract:
// check(): ResultInterface  (may throw \Exception)
// getLabel(): string

final class TenantConnectivityCheck implements CheckInterface
{
    public function check(): ResultInterface
    {
        // Delegate to TenantHealthChecker for each tenant
        // Return Success/Failure/Warning based on worst-of result
        return new Success('All tenant connections reachable');
        // or: new Failure('3 tenants unreachable', $data)
        // or: new Warning('2 tenants degraded', $data)
    }

    public function getLabel(): string
    {
        return 'Tenancy: per-tenant DB connectivity';
    }
}
```

**HealthCheckIntegrationPass registration:**

```php
// Source: derived from MailerTransportContractPass + FilesystemContractPass pattern [VERIFIED: src/]
// Registered in TenancyBundle::build() with class_exists guard

final class HealthCheckIntegrationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Guard: only register when liip/monitor-bundle is installed
        if (!class_exists(\LiipMonitorBundle\LiipMonitorBundle::class)) {
            return;
        }
        // Register TenantConnectivityCheck and BootstrapperHealthCheck
        // as liip_monitor.check tagged services
        // ...
    }
}
```

### Pattern 7: HealthResponseSanitizer — single source of truth

```php
// Source: src/Mailer/DsnSanitizer.php [VERIFIED: live source read]
// REDACTION_REGEX = '/(:\/\/[^:\/@]+:)[^@\/]+(@)/'
// This regex is already GENERAL — it matches any scheme://user:pass@host DSN,
// not just SMTP DSNs. HealthResponseSanitizer reuses it directly.

final class HealthResponseSanitizer
{
    // Reuse DsnSanitizer::REDACTION_REGEX — do NOT reinvent.
    public function sanitize(string $message): string
    {
        return preg_replace(
            DsnSanitizer::REDACTION_REGEX,
            DsnSanitizer::REPLACEMENT,
            $message,
        ) ?? $message;
    }

    /** @param array<string, mixed> $data */
    public function sanitizeArray(array $data): array
    {
        // Walk all string values recursively, apply sanitize()
        array_walk_recursive($data, function (mixed &$value): void {
            if (is_string($value)) {
                $value = $this->sanitize($value);
            }
        });
        return $data;
    }
}
```

**Key insight:** `DsnSanitizer::REDACTION_REGEX` already covers MySQL, PostgreSQL, Redis, SMTP, and any `scheme://user:password@host` DSN. The tightening documented in the existing code (requires literal `://` to avoid matching free-text colons) is already correct for the health use case. [VERIFIED: src/Mailer/DsnSanitizer.php]

### Pattern 8: Route file shape (bundle's first route files)

```php
// config/routes/health.php — live + ready routes
// Imported by consuming app with prefix /_tenancy/health
// Source: Symfony PHP-DSL routing documentation [ASSUMED]

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Tenancy\Bundle\Controller\TenantHealthController;

return function (RoutingConfigurator $routes): void {
    $routes->add('tenancy_health_live', '/live')
        ->controller([TenantHealthController::class, 'live'])
        ->methods(['GET']);

    $routes->add('tenancy_health_ready', '/ready/{slug}')
        ->controller([TenantHealthController::class, 'ready'])
        ->methods(['GET'])
        ->requirements(['slug' => '[a-z0-9\-]+']);
};

// config/routes/health_fleet.php — fleet route (separate per D-02)
return function (RoutingConfigurator $routes): void {
    $routes->add('tenancy_health_fleet', '/')
        ->controller([TenantHealthController::class, 'fleet'])
        ->methods(['GET']);
};

// Consumer's config/routes.yaml (or config/routes/tenancy_health.yaml):
// tenancy_health:
//   resource: '@TenancyBundle/config/routes/health.php'
//   prefix: /_tenancy/health
```

**Confirmed zero route conflicts:** `grep -r '_tenancy' src/ config/` returns empty — the bundle had zero HTTP routes through v0.4.1. [VERIFIED: confirmed no `src/Controller/` directory and no route files in `config/` except `services.php` and `services_dev.php`]

### Pattern 9: TenancyBundle config schema addition

```php
// Source: TenancyBundle::configure() pattern [VERIFIED: src/TenancyBundle.php]
// Add 'health' node alongside existing 'maintenance' node

->arrayNode('health')
->addDefaultsIfNotSet()
->children()
    ->integerNode('fleet_default_limit')->defaultValue(50)->min(1)->end()
    ->integerNode('fleet_max_limit')->defaultValue(200)->min(1)->end()
->end()
->end()
```

No `enabled` flag for HTTP — D-01 locks that route-import IS the opt-in.

### Anti-Patterns to Avoid

- **Anti-pattern H-A1: Calling `boot()` in health probes.** `boot()` has side effects (connection swap, EM clear, cache namespace change). Use `BootstrapperChain::healthCheck()` which calls only `HealthCheckBootstrapperInterface::check()` implementations.
- **Anti-pattern H-A2: Omitting `finally` around `TenantContext::clear()`.** An exception during the probe must still clear the context. The `try/catch/finally` structure is mandatory (Pitfall 8).
- **Anti-pattern H-A3: Iterating all tenants in the liveness endpoint.** Liveness is a pure process check — zero dependency I/O (D-07, Pitfall 9).
- **Anti-pattern H-A4: Raw exception messages in health responses.** Exception messages from DBAL failures contain DSN credentials. All output strings MUST pass through `HealthResponseSanitizer::sanitize()` before entering the response body (Pitfall 10).
- **Anti-pattern H-A5: Dispatching `TenantResolved` or `TenantBootstrapped` during probes.** The health probe is a read-only path; no bootstrapping events should fire. `TenantHealthChecker` calls `setTenant()`/`clear()` directly, not via the orchestrator.
- **Anti-pattern H-A6: Health routes resolving a tenant.** Health endpoints must be configured so all resolvers return null (no tenant identified). They are landlord-side routes, not tenant-side routes. Resolver null-branch is the correct path.
- **Anti-pattern H-A7: Using `$tenantContext->getTenant()` in liip checks to iterate.** The liip check adapter should call `TenantHealthChecker::checkAll()` which manages context safely; it should never set/clear TenantContext directly.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| DSN credential redaction | New regex | `DsnSanitizer::REDACTION_REGEX` + `REPLACEMENT` | Already battle-tested, tightened in WR-07, covers all `scheme://user:pass@host` shapes |
| liip check result types | Custom result VO | `Laminas\Diagnostics\Result\Success`, `Failure`, `Warning` | These are the types liip's runner inspects and reports on |
| Symfony route file syntax | Custom router config | Standard PHP-DSL `RoutingConfigurator` | Bundle's established convention for user-imported config |
| IP-based allow-list in maintenance bypass | Custom CIDR parsing | Symfony `IpUtils::checkIp()` (already used in `TenantMaintenanceModeListener`) | Handles IPv4/IPv6/CIDR correctly |
| JSON serialization | Custom encoder | `json_encode` with `JSON_THROW_ON_ERROR` (existing bundle pattern) | Consistent with maintenance response and migrate JSON format |

**Key insight:** Every hard problem (DSN redaction, IP matching, process-safe lazy DBAL reconnect) is already solved in this codebase. The health phase wires them together rather than reinventing them.

---

## Common Pitfalls

### Pitfall 1: Probe safety — TenantContext not cleared after exception

**What goes wrong:** An exception occurs during `DatabaseSwitchBootstrapper::check()`. If the `finally` block is missing, `TenantContext::hasTenant()` remains true for the remainder of the PHP process (in long-running runtimes: FrankenPHP, Swoole). The next request runs with the previous probe's tenant.

**How to avoid:** Mandatory `try/finally` in `TenantHealthChecker::checkOne()`. The `finally` block calls `$this->tenantContext->clear()` unconditionally. [VERIFIED: Pitfall 8 in PITFALLS.md; TenantContext::clear() sets currentTenant=null unconditionally]

**Warning signs:** `TenantContext::hasTenant()` returns true at the start of a request that should be landlord-scoped (e.g., the first request after a health probe that threw).

### Pitfall 2: DSN exposure in health response

**What goes wrong:** A `Doctrine\DBAL\Exception` caught during `SELECT 1` has the DSN in its message (MySQL does this). Without sanitization, the message appears in the `output` field of the `application/health+json` response on a public unauthenticated endpoint.

**How to avoid:** ALL strings entering the response body must pass through `HealthResponseSanitizer::sanitize()`. The response builder does this on the entire result structure, not per-field. [VERIFIED: DsnSanitizer source; Pitfall 10 in PITFALLS.md]

**Test:** inject a `BootstrapperHealthResult` with `output: "mysql://user:secret@db/tenant_a"`, assert response body contains `mysql://user:***@db/tenant_a`.

### Pitfall 3: Health routes receiving maintenance 503

**What goes wrong:** A tenant in maintenance mode causes the maintenance listener (priority 16) to return HTTP 503 for ALL requests to that tenant's subdomain — including the readiness probe `/ready/{slug}`. The LB marks the app as unhealthy and starts a restart loop.

**How to avoid:** The readiness endpoint is a landlord-side route — resolvers return null for it (no tenant identified), so `TenantMaintenanceModeListener` at step (2) (`if (!$this->tenantContext->hasTenant()) { return; }`) bypasses it automatically. Operators MUST also add `/_tenancy` to `tenancy.maintenance.allow_paths` as a safety net. [VERIFIED: TenantMaintenanceModeListener source; CONTEXT.md Integration Points section]

**Warning sign:** Setting a tenant to maintenance causes its `/_tenancy/health/ready/{slug}` to return 503 instead of a real health report.

### Pitfall 4: No-Doctrine lane failure

**What goes wrong:** `TenantHealthController` or `TenantHealthChecker` directly imports `EntityManagerInterface`. The no-Doctrine CI lane fails with a class-not-found error.

**How to avoid:** Follow the existing pattern: `SharedDriver` (which already uses EntityManager) is already conditionally registered. `TenantHealthChecker` takes `TenantProviderInterface` (already nullable in services.php) not `EntityManagerInterface` directly. `HealthCheckIntegrationPass` is `class_exists`-guarded. The health controller and command are always registered (TenantProviderInterface nullOnInvalid is the existing pattern). [VERIFIED: config/services.php; src/TenancyBundle.php]

### Pitfall 5: Liveness returning non-200 due to dependency check

**What goes wrong:** A developer adds a DB connectivity check to the liveness action (thinking "we should also check the landlord DB"). The liveness probe now returns 503 when the landlord DB is slow. Kubernetes kills the pod. The platform goes down due to a single DB hiccup.

**How to avoid:** Liveness (HEALTH-01, D-07) returns `{"status":"ok"}` with HTTP 200 as soon as the PHP process executes the action. Zero I/O. The action body is literally: `return new JsonResponse(['status' => 'pass'], 200, ['Content-Type' => 'application/health+json'])`. [VERIFIED: Pitfall 11 in PITFALLS.md]

### Pitfall 6: Fleet endpoint unbounded — thundering herd

**What goes wrong:** `TenantProviderInterface::findAll()` returns all tenants. The fleet action iterates all of them. With 1000 tenants and a 5-second probe interval, this exhausts DB connections.

**How to avoid:** Fleet is bounded via `limit`/`offset` pagination (D-08). Default limit=50, hard max=200. The fleet action reads `?limit=N&offset=M`, clamps to hard max, then iterates at most `limit` tenants. [VERIFIED: D-08 in CONTEXT.md]

---

## Runtime State Inventory

> Phase 33 is GREENFIELD for its feature set (no Phase 33 code has been written). Not a rename/refactor phase.

**Nothing found in any category** — verified by confirming: no `src/Health/` directory, no `src/Controller/` directory, no `config/routes/` files, and no existing `tenancy_health_*` routes in the codebase. This is a pure additive phase.

---

## Environment Availability Audit

All required tools are already available in the existing CI and local environment. No external services are needed for health check implementation.

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.2+ | All new code | Yes | PHP 8.2/8.3/8.4 CI matrix | — |
| PHPUnit 11 | Test suite | Yes | Already in require-dev | — |
| SQLite (via DBAL) | Integration tests | Yes | Already used in all integration tests | — |
| liip/monitor-bundle | HEALTH-07 test | Yes (require-dev) | ^2.25 (in require-dev per STACK.md) | — |

**Missing dependencies with no fallback:** None — the HEALTH-07 test requires liip/monitor-bundle to be in require-dev, which STACK.md confirmed is already planned.

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11 |
| Config file | `phpunit.xml.dist` |
| Quick run command | `vendor/bin/phpunit --testsuite unit` |
| Full suite command | `vendor/bin/phpunit` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| HEALTH-01 | Liveness returns `{"status":"pass"}` HTTP 200, zero tenant iteration | unit | `vendor/bin/phpunit --filter testLiveness` | ❌ Wave 0 |
| HEALTH-01 | Liveness action never calls `TenantProviderInterface::findAll()` | unit (mock assert) | `vendor/bin/phpunit --filter testLivenessNeverIteratesTenants` | ❌ Wave 0 |
| HEALTH-02 | Readiness returns pass HTTP 200 for healthy tenant | integration | `vendor/bin/phpunit --filter testReadinessPassesForHealthyTenant` | ❌ Wave 0 |
| HEALTH-02 | Readiness returns fail HTTP 503 for unreachable DB | unit (mock) | `vendor/bin/phpunit --filter testReadinessFailsForUnreachableDb` | ❌ Wave 0 |
| HEALTH-02 | Unknown slug returns HTTP 404 application/health+json | unit | `vendor/bin/phpunit --filter testReadinessUnknownSlugReturns404` | ❌ Wave 0 |
| HEALTH-03 | `TenantContext::hasTenant() === false` after probe (even if probe throws) | integration | `vendor/bin/phpunit --filter testContextClearedAfterProbe` | ❌ Wave 0 |
| HEALTH-03 | `boot()` is NEVER called during health probe | unit (mock assert) | `vendor/bin/phpunit --filter testBootNeverCalledDuringHealthCheck` | ❌ Wave 0 |
| HEALTH-03 | `BootstrapperChain::healthCheck()` skips non-implementing bootstrappers | unit | `vendor/bin/phpunit --filter testHealthCheckSkipsNonImplementors` | ❌ Wave 0 |
| HEALTH-04 | Health response body contains no raw DSN credentials | unit | `vendor/bin/phpunit --filter testHealthResponseSanitizerRedactsDsn` | ❌ Wave 0 |
| HEALTH-04 | `HealthResponseSanitizer` reuses `DsnSanitizer::REDACTION_REGEX` | unit | `vendor/bin/phpunit --filter testSanitizerUsesSharedRegex` | ❌ Wave 0 |
| HEALTH-04 | No HTTP routes exist when route files are NOT imported | manual/config-level | — | manual only |
| HEALTH-05 | `tenancy:health --tenant=slug` reports one tenant | unit | `vendor/bin/phpunit --filter testHealthCommandSingleTenant` | ❌ Wave 0 |
| HEALTH-05 | `tenancy:health --all` streams all tenants, exits 1 on any fail | unit | `vendor/bin/phpunit --filter testHealthCommandAllExitsNonZeroOnFail` | ❌ Wave 0 |
| HEALTH-06 | Fleet response respects `limit`/`offset` parameters | unit | `vendor/bin/phpunit --filter testFleetPaginationRespected` | ❌ Wave 0 |
| HEALTH-06 | Fleet response includes rollup summary `{pass:N, warn:N, fail:N}` | unit | `vendor/bin/phpunit --filter testFleetSummaryPresent` | ❌ Wave 0 |
| HEALTH-07 | `HealthCheckIntegrationPass` registers liip checks when bundle installed | unit (ContainerBuilder) | `vendor/bin/phpunit --filter testHealthCheckIntegrationPassRegistersChecks` | ❌ Wave 0 |
| HEALTH-07 | Self-contained endpoints work unchanged when liip absent | integration | `vendor/bin/phpunit --filter testHealthWorksWithoutLiip` | ❌ Wave 0 |
| Cross | No-Doctrine CI lane stays green after phase ships | CI | vendor/bin/phpunit (no-doctrine matrix leg) | existing infra |
| Cross | `DatabaseSwitchBootstrapper::check()` does not mutate global state | integration (SQLite) | `vendor/bin/phpunit --filter testDbSwitchProbeDoesNotMutateGlobalState` | ❌ Wave 0 |

### Critical Test: Probe Safety (Success Criterion 2)

This is the one genuine correctness question from CONTEXT.md. The test must:

```php
// tests/Integration/Health/TenantHealthCheckerProbeTest.php
// Use DoctrineTestKernel (already exists) with two SQLite tenant DBs

public function testContextClearedAfterSuccessfulProbe(): void
{
    // Set up: two tenants with distinct SQLite files
    $checker = $this->container->get(TenantHealthChecker::class);
    $tenantA  = $this->tenantProvider->findBySlug('tenant-a');

    $report = $checker->checkOne($tenantA);

    // THE invariant — Success Criterion 2
    $this->assertFalse($this->tenantContext->hasTenant());
    $this->assertSame('pass', $report->status());
}

public function testContextClearedAfterFailedProbe(): void
{
    // Simulate unreachable DB by pointing tenant to non-existent SQLite path
    $checker = $this->container->get(TenantHealthChecker::class);
    $badTenant = /* tenant with bad connectionConfig */;

    $report = $checker->checkOne($badTenant);

    // THE invariant — even when probe throws, finally ran
    $this->assertFalse($this->tenantContext->hasTenant());
    $this->assertSame('fail', $report->status());
}
```

### Validation for HEALTH-04 (No DSN Leak)

```php
// tests/Unit/Health/HealthResponseSanitizerTest.php
public function testRedactsMysqlDsn(): void
{
    $sanitizer = new HealthResponseSanitizer();
    $input = 'Connection failed: mysql://app:s3cr3t@db.host/tenant_a';
    $output = $sanitizer->sanitize($input);
    $this->assertStringNotContainsString('s3cr3t', $output);
    $this->assertStringContainsString('***', $output);
}
```

### Validation for HEALTH-07 (Works With and Without liip)

- **With liip:** `HealthCheckIntegrationPass::process()` finds `LiipMonitorBundle` class, registers `TenantConnectivityCheck` tagged `liip_monitor.check`. Test via `ContainerBuilder` unit test (same pattern as `MaintenanceModeContractPassTest`).
- **Without liip:** The pass returns early, no liip services registered. The HTTP endpoints and CLI command still function. Test via `ContainerCompilationTest` without liip installed (the no-liip lane mirrors the no-doctrine lane).

### Sampling Rate

- **Per task commit:** `vendor/bin/phpunit --testsuite unit` (~30s)
- **Per wave merge:** `vendor/bin/phpunit` (full suite, ~2 min with SQLite)
- **Phase gate:** Full suite green before `/gsd:verify-work`

### Wave 0 Gaps

- [ ] `tests/Unit/Health/HealthCheckBootstrapperInterfaceTest.php` — covers HEALTH-03 interface contract
- [ ] `tests/Unit/Health/TenantHealthCheckerTest.php` — covers probe safety unit tests (mock-based)
- [ ] `tests/Unit/Health/HealthResponseSanitizerTest.php` — covers HEALTH-04
- [ ] `tests/Unit/Controller/TenantHealthControllerTest.php` — covers HEALTH-01/02/06
- [ ] `tests/Unit/Command/TenantHealthCommandTest.php` — covers HEALTH-05
- [ ] `tests/Unit/DependencyInjection/Compiler/HealthCheckIntegrationPassTest.php` — covers HEALTH-07
- [ ] `tests/Integration/Health/TenantHealthCheckerProbeTest.php` — covers probe safety (SQLite integration)
- [ ] `tests/Integration/Health/HealthChecksNoLiipTest.php` — covers HEALTH-07 without liip

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | No | Health endpoints explicitly unauthenticated (Out of Scope in REQUIREMENTS.md) |
| V3 Session Management | No | No session state involved |
| V4 Access Control | Partial | Network-ACL-only posture; route-import is the access control mechanism (D-01) |
| V5 Input Validation | Yes | `{slug}` URL parameter — validate against known slugs via `findBySlug()`; 404 on unknown |
| V6 Cryptography | No | No keys, tokens, or encryption |

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| DSN/credential in health response body | Information Disclosure | `HealthResponseSanitizer` scrubs all `scheme://user:pass@host` patterns before response |
| Tenant enumeration via fleet endpoint | Information Disclosure | Fleet returns opaque statuses; slugs visible to authenticated operators only via separate fleet import (D-02) |
| Probe triggering full boot() — context leak | Tampering/Elevation | `TenantHealthChecker` calls `setTenant()`+`healthCheck()`+`clear()`, NEVER `boot()` |
| Health routes blocked by maintenance 503 | Denial of Service | Health routes are landlord-side (null-tenant resolution); maintenance listener bypasses null-tenant |
| Liip integration hard-requiring the bundle | Availability | `class_exists`-guarded; absent liip, no error — self-contained endpoints still function |

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Single `/health` endpoint for both liveness and readiness | Separate `/live` and `/ready/{slug}` with distinct semantics | Phase 33 (this phase) | Eliminates LB restart loops when one tenant DB is down |
| Shallow "is-configured" health checks | Real `SELECT 1` connectivity probe (D-03) | Phase 33 | Probes catch actual DOWN states, not just misconfiguration |
| application/json for health responses | `application/health+json` (IETF draft-inadarei) | Phase 33 | Standard tooling (Kubernetes, uptime monitors) can parse the format |
| No bundle HTTP routes | First-ever HTTP routes shipped as importable files (D-01) | Phase 33 | Idiomatic Symfony bundle routing; operator controls mount point |

**Deprecated/outdated (from STACK.md research):**
- `lexik/maintenance-bundle`: abandoned 2018 — already resolved in Phase 32 (built natively)
- Single-endpoint health pattern: see Pitfall 11 — two-endpoint pattern is mandatory for k8s safety

---

## Open Questions (RESOLVED)

All three were low-stakes "Claude's Discretion" items (CONTEXT.md §Discretion); each was resolved during Phase-33 planning (`/gsd:plan-phase 33`) and locked into the plan task actions.

1. **Status enum shape for `BootstrapperHealthResult`** — **RESOLVED: enum.**
   - What we know: D-05 locks `pass`/`warn`/`fail` as the aggregate states; the two probes ship this phase emit only `pass`/`fail`; `warn` is carried for future custom checks.
   - Resolution: `enum HealthStatus: string { case Pass = 'pass'; case Warn = 'warn'; case Fail = 'fail'; }` (backed string enum) — implemented in plan **33-01 T1**. Type-safe, `strict_types`-aligned, PHPStan L9 exhaustiveness.

2. **Fleet endpoint — should slugs be returned or withheld?** — **RESOLVED: include slugs (redacted output).**
   - What we know: CONTEXT.md doesn't lock this; REQUIREMENTS.md HEALTH-06 says "aggregate summary for dashboards."
   - Resolution: D-02 makes the fleet route a separate, deliberate operator import (opt-in to its information disclosure), so the fleet response includes tenant slugs; all output still passes through `HealthResponseSanitizer`. Implemented in plan **33-03 T2**.

3. **`TenantHealthCommand --format=json` shape** — **RESOLVED: mirror the migrate JSON shape.**
   - What we know: `TenantMigrateCommand --format=json` emits a single aggregate object with `tenants[]`, `summary{}`, per the existing pattern.
   - Resolution: `tenancy:health --format=json` mirrors the migrate CLI aggregate shape (single object, `tenants[]` + `summary{}`) for CLI consistency; the IETF `application/health+json` shape is reserved for HTTP responses only. Implemented in plan **33-04 T2**.

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | PHP-DSL route file syntax (`RoutingConfigurator`) is the correct format for Symfony 7.4+/8.x route files shipped in bundles | Pattern 8 (Route file shape) | Route files would fail to load; fall back to attribute routing on controller |
| A2 | `LiipMonitorBundle\LiipMonitorBundle::class` is the correct FQCN to check in `class_exists` guard for the liip integration pass | Pattern 6 (liip integration) | Guard never fires; would need to check a different class |

**Note on A2:** The exact class name was not directly verified from the liip bundle source. The `class_exists` guard should use a class that is guaranteed to exist if and only if the bundle is installed. Verifying the exact FQCN during Wave 0 (HealthCheckIntegrationPass implementation) is recommended. Alternative candidate: `class_exists(\Laminas\Diagnostics\Check\CheckInterface::class)` since laminas-diagnostics is the core contract and always present when liip is installed.

---

## Sources

### Primary (HIGH confidence)

- `src/Context/TenantContext.php` — `setTenant()`, `clear()`, `hasTenant()` confirmed public; `clear()` sets `currentTenant = null` [VERIFIED: live read]
- `src/Bootstrapper/BootstrapperChain.php` — boot()/clear() loop; no existing `healthCheck()` method; additive pattern confirmed [VERIFIED: live read]
- `src/Bootstrapper/TenantBootstrapperInterface.php` — 2-method interface; sibling pattern confirmed safe [VERIFIED: live read]
- `src/Bootstrapper/DatabaseSwitchBootstrapper.php` — holds no tenant state; `boot()` calls `close()`; `clear()` guards `isConnected()` [VERIFIED: live read]
- `src/Driver/SharedDriver.php` — `boot()` injects TenantContext into filter; `clear()` is no-op; uses EntityManagerInterface [VERIFIED: live read]
- `src/Mailer/DsnSanitizer.php` — `REDACTION_REGEX`, `REPLACEMENT`, `redact()` — confirmed general for any `scheme://user:pass@host` shape [VERIFIED: live read]
- `src/TenancyBundle.php` — `configure()`, `loadExtension()`, `build()` patterns; `maintenance` config node pattern for `health` node [VERIFIED: live read]
- `config/services.php` — `class_exists`/`interface_exists` guard pattern; `nullOnInvalid()` service registration; confirmed no health services [VERIFIED: live read]
- `src/TenantInterface.php` — 9-method interface (including `isInMaintenance()` added in Phase 32) [VERIFIED: live read]
- `src/Entity/AbstractTenant.php` — `$inMaintenance` column confirmed; trait pattern for extension points [VERIFIED: live read]
- `src/EventListener/TenantMaintenanceModeListener.php` — `hasTenant()` null-bypass pattern; `allow_paths` prefix matching [VERIFIED: live read]
- `config/` directory — confirmed only `services.php` and `services_dev.php`; zero route files through v0.4.1 [VERIFIED: find command]
- `src/` directory — confirmed no `src/Controller/` or `src/Health/` directory [VERIFIED: find command]
- `src/DependencyInjection/Compiler/MailerTransportContractPass.php` — `class_exists` guard template for `HealthCheckIntegrationPass` [VERIFIED: live read]
- IETF draft-inadarei-api-health-check-06 — `application/health+json` spec: `status: pass|warn|fail`, `checks{}`, `output`, Content-Type, HTTP status mapping [VERIFIED: WebFetch]
- `Laminas\Diagnostics\Check\CheckInterface` — `check(): ResultInterface` and `getLabel(): string` methods [VERIFIED: WebFetch GitHub]
- LiipMonitorBundle README — tag name `liip_monitor.check`; autoconfiguration for `CheckInterface` implementors [VERIFIED: WebFetch GitHub]
- Laminas diagnostics Result hierarchy — `Success`, `Failure`, `Warning`, `Skip` all implement `ResultInterface` [VERIFIED: WebFetch GitHub]

### Secondary (MEDIUM confidence)

- `.planning/research/STACK.md` — `liip/monitor-bundle ^2.25`, 9M installs, Packagist-verified, Symfony 7/8 compat [CITED: .planning/research/STACK.md]
- `.planning/research/PITFALLS.md` — Pitfalls 8, 10, 11, 3 applied to health check design [CITED: .planning/research/PITFALLS.md]
- `.planning/research/ARCHITECTURE.md` — OPS-02 component table; probe safety analysis [CITED: .planning/research/ARCHITECTURE.md]
- `.planning/phases/33-health-checks/33-CONTEXT.md` — D-01..D-09 locked decisions [CITED: CONTEXT.md]

### Tertiary (LOW confidence / ASSUMED)

- A1: PHP-DSL RoutingConfigurator as route file format (standard Symfony pattern but not live-verified against a shipped bundle example in this codebase)
- A2: Exact FQCN for `class_exists` guard in `HealthCheckIntegrationPass`

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — all confirmed in live source reads and existing planning docs
- Architecture: HIGH — grounded in actual v0.4.1 source; additive-only, zero BC breaks
- IETF application/health+json contract: HIGH — verified from IETF draft
- liip/monitor-bundle integration: HIGH (tag/interface confirmed) — MEDIUM on exact FQCN for class_exists
- Probe safety analysis: HIGH — DatabaseSwitchBootstrapper source confirms stateless design; integration test required for full confirmation
- Pitfalls: HIGH — grounded in live source reads and existing PITFALLS.md

**Research date:** 2026-07-02
**Valid until:** 2026-08-01 (stable stack; liip monitor bundle is slowly-changing)
