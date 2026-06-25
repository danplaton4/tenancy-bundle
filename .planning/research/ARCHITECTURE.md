# Architecture Research — v0.5 Operations & Scale

**Domain:** Symfony bundle — integration design for OPS-01 maintenance mode, OPS-02 health checks, ISOL-07 parallel migrations
**Researched:** 2026-06-25
**Confidence:** HIGH (grounded in the shipped v0.4.1 codebase read directly from `src/`)

---

## Existing Architecture Anchor

Before designing v0.5 integration, the key structural facts from the shipped code:

```
Request arrives
    │
    ▼  kernel.request prio=20
TenantContextOrchestrator::onKernelRequest()          [src/EventListener/TenantContextOrchestrator.php]
    │
    ├─► ResolverChain::resolve($request)              [src/Resolver/ResolverChain.php]
    │       ├─ HostResolver (prio 30)
    │       ├─ OriginHeaderResolver (prio 25)
    │       ├─ HeaderResolver (prio 20)
    │       ├─ QueryParamResolver (prio 10)
    │       └─ ConsoleResolver (autoconfigure)
    │       returns nullable TenantResolution
    │
    ├─► null resolution → return early (public/health route passes through)
    │
    ├─► TenantContext::setTenant($tenant)             [src/Context/TenantContext.php]
    ├─► BootstrapperChain::boot($tenant)              [src/Bootstrapper/BootstrapperChain.php]
    │       ├─ SharedDriver (prio auto, shared_db mode only)
    │       ├─ DatabaseSwitchBootstrapper (prio auto, database_per_tenant only)
    │       ├─ DoctrineBootstrapper (prio -10)
    │       ├─ MailerBootstrapper (prio -20)
    │       └─ FilesystemBootstrapper (prio -30)
    └─► dispatch(TenantResolved{tenant, request, resolvedBy})

    ... controller runs in tenant context ...

    ▼  kernel.terminate
TenantContextOrchestrator::onKernelTerminate()
    ├─► BootstrapperChain::clear()   (reverse order)
    ├─► TenantContext::clear()
    └─► dispatch(TenantContextCleared)
```

**Critical invariants that v0.5 must preserve:**

1. `TenantContext` is zero-dependency — never inject services into it
2. `TenantBootstrapperInterface` has only `boot(TenantInterface): void` and `clear(): void` — no other methods on the interface without a new sibling interface
3. Optional-dependency posture: guard every new Doctrine/Flysystem/Mailer call with `class_exists`/`interface_exists`
4. Compiler passes handle all wiring — no user DI config required
5. `TenantContextOrchestrator` runs at `prio=20` (after Router at 32, before Security firewall at 8)

---

## Feature 1: OPS-01 — Tenant Maintenance Mode

### Design Problem

The orchestrator resolves the tenant and boots the chain at `kernel.request prio=20`. Maintenance mode must:

- Fire AFTER tenant resolution (we need to know WHICH tenant is in maintenance)
- Short-circuit BEFORE the controller runs
- NOT apply to health check routes, landlord routes, and IP/route allow-list entries
- Work without modifying `TenantContextOrchestrator` (which is `final`)

### Integration Point: New `kernel.request` Listener at Priority 16

Priority 20 → orchestrator resolves and boots tenant, dispatches `TenantResolved`.
Priority 16 → `TenantMaintenanceModeListener` checks `TenantContext::hasTenant()` and checks maintenance flag.

This is the canonical Symfony pattern for post-resolution short-circuits (e.g., the Security subscriber at priority 8 follows the same model). Running at priority 16 gives:
- After orchestrator (20): tenant is already in `TenantContext`
- After Firewall (8): we don't need to be — maintenance mode is pre-auth, not post-auth
- Well before any controller listener (negative priorities)

```
kernel.request prio=20  →  TenantContextOrchestrator (resolves + boots)
kernel.request prio=16  →  TenantMaintenanceModeListener (checks flag, may set response)
    ...Security (prio=8)...
    ...controller (prio=0 or negative)...
```

### Where the Maintenance Flag Lives

**Option A — On the `TenantInterface`/`AbstractTenant` entity:**

Add `isInMaintenance(): bool` to `AbstractTenant` + a column. Per-tenant, persisted, survives restarts.

**Option B — In a separate key-value store (Redis, config file):**

Operator-friendly for fast toggles without DB migrations.

**Decision: Option A — extend `AbstractTenant`.**

Rationale:
- The pattern is already established: `isActive`, `mailerDsn`, `filesystemConfig` all live on the entity. Maintenance flag is per-tenant data the same way.
- `AbstractTenant` is the extension point; users with a custom tenant entity `use TenantMaintenanceConfigTrait` (same pattern as `TenantMailerConfigTrait` and `TenantFilesystemConfigTrait`).
- The `DoctrineTenantProvider::findBySlug()` already caches tenant lookups (5-min TTL) — the maintenance flag read is effectively free on hot paths.
- A new optional `TenantMaintenanceConfigTrait` keeps the BC cost minimal (users not using maintenance mode get zero schema changes unless they opt in or extend `AbstractTenant`).

Schema addition:
```php
// In AbstractTenant (or via TenantMaintenanceConfigTrait)
#[ORM\Column(type: 'boolean')]
private bool $isInMaintenance = false;
```

New method on `TenantInterface`:
```php
public function isInMaintenance(): bool;
```

This is a **BC break** for custom `TenantInterface` implementors — identical situation to `getMailerDsn()` in v0.3. Mitigate with a default trait and UPGRADE note.

### Allow-List for Bypass

The maintenance listener needs to pass through:
- Health check routes (by route name or path prefix)
- Landlord/admin routes (by route name, firewall zone, or attribute)
- IP ranges for support access

Config shape (in `tenancy.yaml`):
```yaml
tenancy:
  maintenance:
    allow_ips: ['127.0.0.1', '10.0.0.0/8']
    allow_routes: ['tenancy_health', 'app_admin_*']
    allow_paths: ['/_health', '/_tenancy/health']
```

The listener reads `$request->getClientIp()` and the matched route name. Route name matching uses `fnmatch`-style glob (already used in Symfony Security).

**Allow-list evaluation runs BEFORE the tenant maintenance check** — so health probes always pass.

### New vs Modified Components — OPS-01

**NEW files:**

| File | Role |
|------|------|
| `src/EventListener/TenantMaintenanceModeListener.php` | `kernel.request` listener at priority 16 |
| `src/Exception/TenantInMaintenanceException.php` | HTTP 503 exception implementing `HttpExceptionInterface` |
| `src/Maintenance/TenantMaintenanceConfigTrait.php` | Default trait for `AbstractTenant` maintenance columns |
| `src/DependencyInjection/Compiler/MaintenanceModePass.php` | Validates config + wires listener; optionally noop if feature disabled |

**MODIFIED files:**

| File | Change |
|------|--------|
| `src/TenantInterface.php` | Add `isInMaintenance(): bool` — BC break, requires UPGRADE note |
| `src/Entity/AbstractTenant.php` | Add `$isInMaintenance` column + getter/setter (or `use TenantMaintenanceConfigTrait`) |
| `config/services.php` | Register `TenantMaintenanceModeListener`, guarded by `tenancy.maintenance.enabled` param |
| `src/TenancyBundle.php` | Expose `maintenance` config section via `Configuration.php` |

### Maintenance Mode Request Flow

```
kernel.request prio=20  →  TenantContextOrchestrator boots tenant
    TenantContext now has tenant object
kernel.request prio=16  →  TenantMaintenanceModeListener::onKernelRequest()
    1. if not $event->isMainRequest() → return
    2. if not $tenantContext->hasTenant() → return (public route, no tenant)
    3. check allow-list: IP? route name? path prefix? → if any match, return
    4. $tenant = $tenantContext->getTenant()
    5. if $tenant->isInMaintenance():
          dispatch(TenantMaintenanceModeEntered{tenant, request})  [new event]
          $response = new Response('Service Temporarily Unavailable', 503, [...])
          — or render a Twig template if bundle has views enabled
          $event->setResponse($response)  ← short-circuits controller
          return
    6. dispatch(TenantMaintenanceModeExited{tenant}) is NOT needed (normal path)
```

The `setResponse()` call on the `RequestEvent` causes Symfony to fire `kernel.response` and `kernel.terminate` normally — so `TenantContextOrchestrator::onKernelTerminate()` still clears the context cleanly. No special teardown needed.

### Dispatch New Events

| Event | When | Purpose |
|-------|------|---------|
| `TenantMaintenanceModeEntered` | When listener sets 503 response | Allows user-land logging/alerting |

Minimal payload: `{tenant: TenantInterface, request: Request}`.

---

## Feature 2: OPS-02 — Health Checks / MonitorBundle Integration

### Design Problem

Health checks need to answer two questions per tenant:
1. Can the bundle reach the tenant's database? (connectivity probe)
2. Is each bootstrapper in a healthy state? (service readiness probe)

The challenge: probing health without running a full request (which boots the full bootstrapper chain and requires a real HTTP request).

### Architecture Decision: Separate HealthBootstrapperInterface (NOT health mode on BootstrapperChain)

**Option A — Add `health()` method to `TenantBootstrapperInterface`:**
Would force every existing bootstrapper to implement a new method. BC break on the public interface.

**Option B — New sibling `HealthCheckBootstrapperInterface`:**
```php
interface HealthCheckBootstrapperInterface
{
    public function check(TenantInterface $tenant): BootstrapperHealthResult;
}
```
Bootstrappers that want to expose health checks implement this second interface. `BootstrapperChain` gets a new `healthCheck(TenantInterface $tenant): BootstrapperHealthResult[]` method that calls `check()` only on implementing bootstrappers.

**Decision: Option B — `HealthCheckBootstrapperInterface` as a sibling interface.**

Rationale:
- Zero BC break on `TenantBootstrapperInterface` (the only public contract)
- `BootstrapperChain::healthCheck()` is additive (new method, no change to `boot()`/`clear()`)
- Clean type separation: not every bootstrapper has meaningful health semantics (e.g., `FilesystemBootstrapper::boot()` is a no-op; its health probe checks if the adapter DSN resolves)

### Health Check Result Shape

```php
// src/Health/BootstrapperHealthResult.php
final class BootstrapperHealthResult
{
    public function __construct(
        public readonly string $bootstrapperClass,
        public readonly bool $healthy,
        public readonly string $message = '',
        public readonly ?\Throwable $exception = null,
    ) {}
}
```

### BootstrapperChain Additive Method

```php
// src/Bootstrapper/BootstrapperChain.php — MODIFIED (additive)
public function healthCheck(TenantInterface $tenant): array
{
    $results = [];
    foreach ($this->bootstrappers as $bootstrapper) {
        if ($bootstrapper instanceof HealthCheckBootstrapperInterface) {
            try {
                $results[] = $bootstrapper->check($tenant);
            } catch (\Throwable $e) {
                $results[] = new BootstrapperHealthResult(
                    $bootstrapper::class, false, $e->getMessage(), $e
                );
            }
        }
    }
    return $results;
}
```

### Per-Bootstrapper Health Implementations

**DatabaseSwitchBootstrapper (database_per_tenant):**
The health check should attempt to boot the connection and run a lightweight ping without modifying global state. The safe approach: call `connection->close()` + `connection->connect()` + `connection->ping()` (or `executeQuery('SELECT 1')`) in a try/catch. On success: healthy. On exception: unhealthy with message.

This is safe because `DatabaseSwitchBootstrapper::boot()` already calls `close()`, and the health check runs after setting tenant context. The health check path does need to set `TenantContext` temporarily — see `TenantHealthChecker` service below.

**SharedDriver (shared_db):**
Health check: attempt a `SELECT 1` on the shared tenant connection with the `TenantAwareFilter` active. Confirms filter is wired and DB is reachable.

**DoctrineBootstrapper:**
No meaningful health probe (just EM clear). Skip or return trivially healthy.

**MailerBootstrapper:**
Attempt a transport `__toString()` or DSN parse (NOT a live SMTP handshake — too slow for health probes). Return healthy if the transport can be constructed.

**FilesystemBootstrapper:**
Attempt a `directoryExists('')` or `listContents('', false)->take(1)` on the tenant-scoped operator. Confirm the adapter is reachable.

### TenantHealthChecker Service (core health orchestrator)

```
// src/Health/TenantHealthChecker.php — NEW
```

Responsibilities:
1. Accept a list of tenants (all or specific)
2. For each tenant: set `TenantContext`, call `bootstrapperChain->healthCheck()`, clear `TenantContext`
3. Also probe DB connectivity independently (for tenants with no bootstrapper that covers DB)
4. Return `TenantHealthReport[]`

The critical design: health checks **do NOT call `BootstrapperChain::boot()`** (which would trigger full DB switch + EM clear + cache namespace changes). Instead they call `TenantContext::setTenant()` + `healthCheck()` + `TenantContext::clear()` — a lightweight "dry run". This is safe because `healthCheck()` only calls `HealthCheckBootstrapperInterface::check()` implementations, which are designed to probe without side effects.

### Delivery: Both a Controller/Endpoint AND a Console Command

**Controller endpoint (`/_tenancy/health`):**

- Returns JSON: overall status (healthy/degraded/unhealthy) + per-tenant results
- No tenant resolution needed (it's a landlord/infrastructure route — `ResolverChain` returns null, which is correct)
- Integrate with `symfony/health-check-bundle` or a custom controller — both work; the controller approach requires no external dependency
- Endpoint returns HTTP 200 (healthy), 207 (partially degraded), or 503 (unhealthy)
- Guard: only accessible from allow-listed IPs (same allow-list as maintenance mode) or behind Basic Auth

**Console command (`tenancy:health`):**

- Enumerates all tenants via `TenantProviderInterface::findAll()`
- For each: runs `TenantHealthChecker`
- Outputs a table (slug | driver | DB | mail | filesystem | status)
- Exit code 0 (all healthy), 1 (any unhealthy)
- `--tenant=<slug>` filter
- `--format=json` flag for machine-readable output (CI/CD integration)

Both surfaces consume the same `TenantHealthChecker` service. The console command is usable in cron-based monitoring or runbooks.

### Driver Coverage

| Driver | Health check mechanism |
|--------|------------------------|
| `database_per_tenant` | `DatabaseSwitchBootstrapper implements HealthCheckBootstrapperInterface` — boots tenant connection, runs `SELECT 1`, clears |
| `shared_db` | `SharedDriver implements HealthCheckBootstrapperInterface` — runs `SELECT 1` with filter active; confirms filter is wired |
| Both drivers (common) | Cache adapter probe (optional): attempt `$cache->get('_tenancy_health_probe', fn($i) => true)` with short TTL |

### New vs Modified Components — OPS-02

**NEW files:**

| File | Role |
|------|------|
| `src/Health/BootstrapperHealthResult.php` | Value object: bootstrapper FQCN + healthy bool + message + exception |
| `src/Health/TenantHealthReport.php` | Per-tenant aggregate: tenant slug + list of `BootstrapperHealthResult` + overall status |
| `src/Health/TenantHealthChecker.php` | Core service: iterates tenants, calls health probes, returns reports |
| `src/Health/HealthCheckBootstrapperInterface.php` | Sibling interface: `check(TenantInterface): BootstrapperHealthResult` |
| `src/Command/TenantHealthCommand.php` | `tenancy:health` console command |
| `src/Controller/TenantHealthController.php` | `/_tenancy/health` HTTP endpoint (optional; only if HTTP health check enabled) |

**MODIFIED files:**

| File | Change |
|------|--------|
| `src/Bootstrapper/BootstrapperChain.php` | Add `healthCheck(TenantInterface $tenant): array` method (additive, no BC break) |
| `src/Bootstrapper/DatabaseSwitchBootstrapper.php` | Implement `HealthCheckBootstrapperInterface` |
| `src/Driver/SharedDriver.php` | Implement `HealthCheckBootstrapperInterface` |
| `config/services.php` | Register `TenantHealthChecker`, `TenantHealthCommand`, `TenantHealthController` |

### Health Check Data Flow

```
tenancy:health (console) OR GET /_tenancy/health (HTTP)
    │
    ▼
TenantHealthChecker::checkAll()  (or checkOne($slug))
    │
    ├─► TenantProviderInterface::findAll()  (bypasses cache, landlord EM)
    │
    └─► for each $tenant:
            TenantContext::setTenant($tenant)
            │
            ├─► BootstrapperChain::healthCheck($tenant)
            │       for each bootstrapper implementing HealthCheckBootstrapperInterface:
            │           DatabaseSwitchBootstrapper::check($tenant)
            │               → connection->close(), boot($tenant), SELECT 1, record result
            │           SharedDriver::check($tenant)
            │               → filter inject, SELECT 1, record result
            │
            TenantContext::clear()
            │
            └─► TenantHealthReport{tenant, results[]}

Returns: TenantHealthReport[]
```

No `TenantResolved`, `TenantBootstrapped`, or `TenantContextCleared` events are dispatched during health checks — it is a read-only probe, not a full bootstrap cycle.

---

## Feature 3: ISOL-07 — Parallel `tenancy:migrate`

### Current Architecture

`TenantMigrateCommand` (`src/Command/TenantMigrateCommand.php`) runs a sequential loop:

```php
foreach ($tenants as $tenant) {
    $this->runMigrationsForTenant($tenant, $this->migrationsConfig, $io);
    // finally: $this->tenantContext->clear(); $this->bootstrapperChain->clear();
}
```

`runMigrationsForTenant()` calls `DependencyFactory::fromConnection()` with the already-switched `tenantConnection` — it relies on `BootstrapperChain::boot()` having called `DatabaseSwitchBootstrapper::boot()` (which calls `connection->close()`) so the next query reconnects to the right DB.

This is correct but sequential. With 100+ tenants, each migration run takes 30+ seconds minimum.

### Parallelization Model

The cleanest model reuses `TenantRunCommand`'s subprocess pattern (`symfony/process` is already a production dependency). Each tenant's migration runs in a **child process** via:

```
bin/console tenancy:migrate --tenant=<slug>
```

This is safe because:
1. Each subprocess has its own PHP process — no shared `TenantContext` state
2. Each subprocess boots its own `DatabaseSwitchBootstrapper` in isolation
3. DBAL connections are per-process — no concurrency issues on the connection pool
4. The single-tenant path in `TenantMigrateCommand` is already implemented (`--tenant=<slug>` filter)

### Process Pool Architecture

```
ParallelMigrateOrchestrator
    │
    ├─► TenantProviderInterface::findAll()   [get the full tenant list]
    │
    ├─► Partition into batches of size $concurrencyLimit (default: 4)
    │
    └─► for each batch:
            ├─► spawn Process["bin/console tenancy:migrate --tenant=acme"]
            ├─► spawn Process["bin/console tenancy:migrate --tenant=globex"]
            ├─► spawn Process["bin/console tenancy:migrate --tenant=foo"]
            ├─► spawn Process["bin/console tenancy:migrate --tenant=bar"]
            │
            └─► Process::wait() each  [or use non-blocking poll loop]
                capture stdout/stderr per process
                record exit code per process
```

### Where the Parallelism Lives: New Flag on `TenantMigrateCommand`

The simplest, least-disruptive integration: add `--parallel` flag to the existing `TenantMigrateCommand`.

```
tenancy:migrate                    → existing sequential behavior (unchanged)
tenancy:migrate --parallel         → new parallel mode (bounded worker pool)
tenancy:migrate --parallel --concurrency=8  → custom concurrency
tenancy:migrate --tenant=foo       → single tenant (unchanged; used by parallel subprocesses)
```

When `--parallel` is set, `TenantMigrateCommand::execute()` delegates to an extracted `ParallelMigrationRunner` service rather than the sequential loop. The sequential loop remains unchanged as the fallback and as the implementation used by each child subprocess.

This means `TenantMigrateCommand` is **modified** (new options), and `ParallelMigrationRunner` is **new**.

### ParallelMigrationRunner

```
// src/Command/Migration/ParallelMigrationRunner.php — NEW
```

Responsibilities:
1. Accept `TenantInterface[]` + concurrency limit + project dir + `OutputInterface`
2. Spawn `Process` per tenant using the same pattern as `TenantRunCommand`:
   ```php
   new Process([PHP_BINARY, $projectDir.'/bin/console', 'tenancy:migrate', '--tenant='.$slug])
   ```
3. Run up to `$concurrencyLimit` processes concurrently using a non-blocking poll loop:
   ```php
   // Start first $limit processes
   // Poll remaining processes non-blocking
   // As each finishes, start the next pending tenant
   // Collect stdout/stderr via $process->getIncrementalOutput()
   ```
4. Aggregate results: per-tenant success/failure + exit code + captured output
5. Return aggregated result for summary table rendering

**Concurrency safety:** symfony/process's non-blocking mode uses `proc_get_status()` internally. The poll loop checks `$process->isRunning()` and calls `$process->getIncrementalOutput()` to avoid buffer overruns. This is the same pattern used by Symfony's `ProcessHelper::run()`.

**Output streaming:** each process's output is buffered and printed after the subprocess finishes (not interleaved), OR a `--no-ansi` mode that prefixes each line with `[tenant-slug]`. Recommend: buffered by default, `--stream` flag for interleaved.

### Concurrency Limit Default

Default: **4** (not CPU count, not tenant count). Rationale:
- Most tenants share the same DB host — high concurrency causes connection pool exhaustion
- 4 is conservative, operators can tune up with `--concurrency=N`
- The flag name `--concurrency` matches the Messenger worker convention

### Exit Code and Continue-on-Failure

Preserve existing semantics exactly:
- If any tenant fails: exit code 1
- Failed tenants listed in summary table
- `--continue-on-failure` behavior is implicit in the worker-pool design (a failed subprocess doesn't block others)

### Shared-DB Guard

The `--parallel` flag must error early if `driver: shared_db` is active — identical to the existing check. No parallel migration is needed for shared-db (there's only one DB).

### New vs Modified Components — ISOL-07

**NEW files:**

| File | Role |
|------|------|
| `src/Command/Migration/ParallelMigrationRunner.php` | Worker-pool orchestrator for parallel tenant migrations |

**MODIFIED files:**

| File | Change |
|------|--------|
| `src/Command/TenantMigrateCommand.php` | Add `--parallel` + `--concurrency` options; branch to `ParallelMigrationRunner` when flag set; sequential loop untouched |

### Parallel Migration Data Flow

```
tenancy:migrate --parallel --concurrency=4

TenantMigrateCommand::execute()
    │
    ├─► guard: shared_db → error immediately
    ├─► guard: no migrationsConfig → error immediately
    │
    └─► $tenants = tenantProvider->findAll()  (or single-slug filter)
        │
        └─► ParallelMigrationRunner::run($tenants, concurrency=4, projectDir, io)
                │
                ├─► spawn Process(bin/console tenancy:migrate --tenant=acme)   ─────┐
                ├─► spawn Process(bin/console tenancy:migrate --tenant=globex) ────┐│
                ├─► spawn Process(bin/console tenancy:migrate --tenant=foo)    ───┐││
                ├─► spawn Process(bin/console tenancy:migrate --tenant=bar)    ──┐│││
                │                                                                ││││
                │   poll loop (non-blocking):                                    ││││
                │       check isRunning() on each active process                 ││││
                │       on process finish: capture output, record exit code ─────┘│││
                │       start next pending tenant process ────────────────────────┘││
                │       ...                                                         ││
                │       last process finishes ───────────────────────────────────  ┘│
                │                                                                    ┘
                └─► aggregate results → MigrationBatchResult{succeeded[], failed[]}

TenantMigrateCommand renders summary table, returns exit code
```

Each subprocess runs the EXISTING `TenantMigrateCommand::execute()` with `--tenant=slug` — reuses all existing migration logic including `runMigrationsForTenant()`, `DependencyFactory`, `MigratorConfiguration`.

---

## Full v0.5 Integration Map

```
Existing v0.4.1 surface (unchanged contracts)
┌─────────────────────────────────────────────────────────────────────────────────┐
│  TenantContextOrchestrator (kernel.request prio=20)                             │
│    ResolverChain → TenantContext → BootstrapperChain → TenantResolved event     │
│  TenantContextOrchestrator (kernel.terminate)                                   │
│    BootstrapperChain.clear() → TenantContext.clear() → TenantContextCleared     │
│                                                                                 │
│  BootstrapperChain                                                              │
│    boot()/clear() contract — TenantBootstrapperInterface                        │
│                                                                                 │
│  TenantMigrateCommand (sequential loop, --tenant filter)                        │
│  TenantRunCommand (subprocess spawner)                                          │
└────────────────────────────────────┬────────────────────────────────────────────┘
                                     │
                                     ▼ (v0.5 additions)
┌─────────────────────────────────────────────────────────────────────────────────┐
│  OPS-01: Maintenance Mode                                                       │
│                                                                                 │
│  TenantMaintenanceModeListener (kernel.request prio=16)                         │
│    → reads TenantContext (set by orchestrator at prio=20)                       │
│    → checks allow-list (IPs, routes, paths)                                     │
│    → calls $tenant->isInMaintenance()                                           │
│    → $event->setResponse(503) if in maintenance                                 │
│    → dispatches TenantMaintenanceModeEntered event                              │
│                                                                                 │
│  AbstractTenant: new $isInMaintenance column + TenantMaintenanceConfigTrait     │
│  TenantInterface: new isInMaintenance(): bool method                            │
│  TenantInMaintenanceException: HTTP 503, implements HttpExceptionInterface      │
│                                                                                 │
├─────────────────────────────────────────────────────────────────────────────────┤
│  OPS-02: Health Checks                                                          │
│                                                                                 │
│  HealthCheckBootstrapperInterface (sibling to TenantBootstrapperInterface)      │
│    check(TenantInterface): BootstrapperHealthResult                             │
│                                                                                 │
│  DatabaseSwitchBootstrapper now implements HealthCheckBootstrapperInterface     │
│  SharedDriver now implements HealthCheckBootstrapperInterface                   │
│                                                                                 │
│  BootstrapperChain: new healthCheck() method (additive, no BC break)            │
│                                                                                 │
│  TenantHealthChecker: set context → call healthCheck() → clear context         │
│    (no full boot(), no events dispatched — read-only probe)                    │
│                                                                                 │
│  tenancy:health command → TenantHealthChecker → table output                   │
│  GET /_tenancy/health   → TenantHealthChecker → JSON response                  │
│                                                                                 │
├─────────────────────────────────────────────────────────────────────────────────┤
│  ISOL-07: Parallel Migrations                                                   │
│                                                                                 │
│  TenantMigrateCommand: new --parallel + --concurrency options                  │
│    when --parallel: delegates to ParallelMigrationRunner                        │
│    without --parallel: existing sequential loop (unchanged)                    │
│                                                                                 │
│  ParallelMigrationRunner: bounded symfony/process worker pool                  │
│    spawns: bin/console tenancy:migrate --tenant=<slug>                          │
│    (reuses existing single-tenant migration path per subprocess)                │
└─────────────────────────────────────────────────────────────────────────────────┘
```

---

## Dependency-Ordered Build Sequence

Phases continue from 31.

### Phase 31 — ISOL-07 Parallel `tenancy:migrate`

**Build first.** Rationale:
- Zero schema changes, zero BC breaks on public interfaces
- `symfony/process` is already a production dependency (`TenantRunCommand`)
- The subprocess model reuses the existing `--tenant=slug` single-tenant path — no new migration logic
- Self-contained: `ParallelMigrationRunner` + modified `TenantMigrateCommand`
- Easiest to verify: run against the integration test kernel with real SQLite per-tenant DBs
- Unblocks operator confidence for large tenant sets before maintenance mode ships

**Dependencies:** none beyond existing codebase.

**Files:**
- NEW: `src/Command/Migration/ParallelMigrationRunner.php`
- MODIFIED: `src/Command/TenantMigrateCommand.php`

---

### Phase 32 — OPS-01 Tenant Maintenance Mode

**Build second.** Rationale:
- Requires schema change (`AbstractTenant` + migration) and a `TenantInterface` BC break — needs UPGRADE note
- The `TenantMaintenanceModeListener` at priority 16 depends on `TenantContextOrchestrator` (priority 20) having run first — this dependency exists at runtime, not build-time, but the ordering of phases mirrors the runtime priority
- Maintenance mode without health checks is meaningful and shippable on its own
- Health checks in Phase 33 will use the allow-list mechanism from this phase (health routes bypass maintenance)

**Dependencies:** no Phase 31 dependencies. Could ship before 31, but 31 is lower risk.

**Files:**
- NEW: `src/EventListener/TenantMaintenanceModeListener.php`
- NEW: `src/Exception/TenantInMaintenanceException.php`
- NEW: `src/Maintenance/TenantMaintenanceConfigTrait.php`
- MODIFIED: `src/TenantInterface.php` (add `isInMaintenance(): bool`)
- MODIFIED: `src/Entity/AbstractTenant.php` (add column or `use TenantMaintenanceConfigTrait`)
- MODIFIED: `config/services.php` (register listener)
- MODIFIED: `src/TenancyBundle.php` / `Configuration.php` (new `maintenance:` config section)

---

### Phase 33 — OPS-02 Health Checks

**Build third.** Rationale:
- The health route allow-list config (introduced in Phase 32) should be shared by both maintenance mode and health endpoint to avoid config duplication — Phase 32 first establishes the allow-list pattern
- `HealthCheckBootstrapperInterface` is new but does not touch `TenantBootstrapperInterface` — no BC break
- The HTTP endpoint must bypass maintenance mode (Phase 32's allow-list must cover `/_tenancy/health`) — natural dependency
- `TenantHealthChecker` is the most complex new service; best built after the simpler infrastructure of 31 + 32 is verified

**Dependencies:** Phase 32 (for allow-list bypass; the health endpoint path must be in the maintenance allow-list).

**Files:**
- NEW: `src/Health/BootstrapperHealthResult.php`
- NEW: `src/Health/TenantHealthReport.php`
- NEW: `src/Health/TenantHealthChecker.php`
- NEW: `src/Health/HealthCheckBootstrapperInterface.php`
- NEW: `src/Command/TenantHealthCommand.php`
- NEW: `src/Controller/TenantHealthController.php` (if HTTP endpoint enabled)
- MODIFIED: `src/Bootstrapper/BootstrapperChain.php` (add `healthCheck()`)
- MODIFIED: `src/Bootstrapper/DatabaseSwitchBootstrapper.php` (implement `HealthCheckBootstrapperInterface`)
- MODIFIED: `src/Driver/SharedDriver.php` (implement `HealthCheckBootstrapperInterface`)
- MODIFIED: `config/services.php` (register health services)

---

### Phase 34 — DOC-21 Ops Docs

**Build last.** Rationale: documents what shipped in 31–33.

**Files:** `docs/ops/maintenance-mode.md`, `docs/ops/health-checks.md`, `docs/ops/parallel-migrations.md`, UPGRADE 0.4→0.5 note, docs-lint update.

---

## Component Responsibilities Summary

| Component | File | Responsibility | NEW or MODIFIED |
|-----------|------|----------------|-----------------|
| `TenantMaintenanceModeListener` | `src/EventListener/TenantMaintenanceModeListener.php` | Short-circuit 503 after tenant resolved, before controller | NEW |
| `TenantInMaintenanceException` | `src/Exception/TenantInMaintenanceException.php` | HTTP 503 throwable for error-handler path | NEW |
| `TenantMaintenanceConfigTrait` | `src/Maintenance/TenantMaintenanceConfigTrait.php` | BC-safe trait for custom tenant entities | NEW |
| `TenantMaintenanceModeEntered` | `src/Event/TenantMaintenanceModeEntered.php` | Dispatched when 503 response is set | NEW |
| `HealthCheckBootstrapperInterface` | `src/Health/HealthCheckBootstrapperInterface.php` | Sibling interface for probing bootstrapper health | NEW |
| `BootstrapperHealthResult` | `src/Health/BootstrapperHealthResult.php` | Value object: class + healthy + message + exception | NEW |
| `TenantHealthReport` | `src/Health/TenantHealthReport.php` | Per-tenant aggregate of health results | NEW |
| `TenantHealthChecker` | `src/Health/TenantHealthChecker.php` | Core: set context → probe → clear → aggregate | NEW |
| `TenantHealthCommand` | `src/Command/TenantHealthCommand.php` | `tenancy:health` CLI surface | NEW |
| `TenantHealthController` | `src/Controller/TenantHealthController.php` | `/_tenancy/health` HTTP surface | NEW |
| `ParallelMigrationRunner` | `src/Command/Migration/ParallelMigrationRunner.php` | Bounded symfony/process worker pool for migrations | NEW |
| `BootstrapperChain` | `src/Bootstrapper/BootstrapperChain.php` | Add `healthCheck()` (additive) | MODIFIED |
| `DatabaseSwitchBootstrapper` | `src/Bootstrapper/DatabaseSwitchBootstrapper.php` | Implement `HealthCheckBootstrapperInterface` | MODIFIED |
| `SharedDriver` | `src/Driver/SharedDriver.php` | Implement `HealthCheckBootstrapperInterface` | MODIFIED |
| `TenantMigrateCommand` | `src/Command/TenantMigrateCommand.php` | Add `--parallel`/`--concurrency`; delegate to runner | MODIFIED |
| `AbstractTenant` | `src/Entity/AbstractTenant.php` | Add `$isInMaintenance` column | MODIFIED |
| `TenantInterface` | `src/TenantInterface.php` | Add `isInMaintenance(): bool` (BC break, mitigated by trait) | MODIFIED |

---

## Anti-Patterns to Avoid

### Anti-Pattern V5-A1: Maintenance Check at Priority Higher Than Orchestrator

**What people do:** Listen at `kernel.request prio=25` to short-circuit before the orchestrator runs.
**Why it's wrong:** `TenantContext` is empty at that point — there is no tenant to check maintenance for. The check would always return "not in maintenance" (no tenant = no flag = pass through) defeating the feature.
**Do this instead:** Priority 16 — after orchestrator (20) has populated `TenantContext`.

### Anti-Pattern V5-A2: Adding `health()` Method to `TenantBootstrapperInterface`

**What people do:** Add `health(): bool` or `probe(): HealthResult` to the existing bootstrapper interface.
**Why it's wrong:** Every existing bootstrapper in user codebases breaks (PHP fatal: class must implement all methods). BC break on a public interface with no escape hatch.
**Do this instead:** `HealthCheckBootstrapperInterface` as a sibling interface. Bootstrappers opt in by implementing both interfaces. The chain's `healthCheck()` uses `instanceof` to find participating bootstrappers.

### Anti-Pattern V5-A3: Health Check Calls `BootstrapperChain::boot()`

**What people do:** To probe health, reuse the full boot sequence and check for exceptions.
**Why it's wrong:** `boot()` has side effects: connection swap, EM clear, cache namespace change, mailer LRU priming. Running `boot()` on all tenants in a health check loop mutates global service state and does not clean up properly (would need `clear()` pairing). Under concurrent HTTP requests, this corrupts `TenantContext`.
**Do this instead:** A purpose-built `healthCheck()` on `BootstrapperChain` that calls only `HealthCheckBootstrapperInterface::check()` implementations. Each check is designed to probe without persistent side effects.

### Anti-Pattern V5-A4: Parallel Migrations Share In-Process State

**What people do:** Use PHP fibers or coroutines to "parallelize" migrations within one process.
**Why it's wrong:** `TenantContext` is a shared singleton in the DI container. Concurrent fiber execution in the same process leads to race conditions on `TenantContext::setTenant()`. `DoctrineBootstrapper::boot()` calls `$em->clear()` on the same EM instance. There is no thread-safe isolation.
**Do this instead:** Out-of-process parallelism via `symfony/process`. Each subprocess has its own PHP runtime, its own `TenantContext`, its own DBAL connection. No shared state possible.

### Anti-Pattern V5-A5: Maintenance Mode Applies to Null-Tenant Requests

**What people do:** Forget to check `$tenantContext->hasTenant()` before checking `isInMaintenance()`.
**Why it's wrong:** When `TenantContext` has no tenant (public routes, health routes, landlord routes), calling `getTenant()` returns null. Calling `isInMaintenance()` on null throws a fatal.
**Do this instead:** Early return in `TenantMaintenanceModeListener` if `!$tenantContext->hasTenant()`. This preserves the existing null-tenant = public route convention.

### Anti-Pattern V5-A6: Tenant Health Probes Run DB Migrations or Schema Changes

**What people do:** Health check calls `$connection->executeQuery('CREATE TABLE IF NOT EXISTS ...')` to confirm write access.
**Why it's wrong:** Health probes must be read-only and non-destructive. A health check that modifies schema is a bug source and a security risk.
**Do this instead:** `SELECT 1` or `SHOW STATUS` for DB connectivity. List 1 item from filesystem for adapter reachability. No writes, no DDL.

---

## Optional-Dependency Posture Preservation

All v0.5 code follows the existing bundle convention:

| New Component | Optional Dep Guard |
|---------------|--------------------|
| `DatabaseSwitchBootstrapper::check()` | Already guarded — `DatabaseSwitchBootstrapper` is only registered when `database.enabled: true` and `class_exists(Connection::class)` |
| `SharedDriver::check()` | Already guarded — `SharedDriver` registered only when `interface_exists(EntityManagerInterface::class)` |
| `TenantHealthController` | Guarded by `tenancy.health.http_enabled` param (default false — opt-in; avoids route conflicts) |
| Maintenance column on `AbstractTenant` | Guarded by opt-in: only added if user `use TenantMaintenanceConfigTrait` or extends `AbstractTenant` normally |
| `ParallelMigrationRunner` | No new deps — `symfony/process` already in `require` |

---

## Confidence Assessment

| Area | Confidence | Reason |
|------|------------|--------|
| Maintenance mode listener priority (16) | HIGH | Read real `TenantContextOrchestrator` (priority 20 constant); Symfony event priority ordering is deterministic |
| `HealthCheckBootstrapperInterface` sibling pattern | HIGH | Read real `TenantBootstrapperInterface` (2 methods only); BC break confirmed if added to existing interface |
| `BootstrapperChain::healthCheck()` additive | HIGH | Read real `BootstrapperChain` — `boot()`/`clear()` loop pattern; new method is structurally identical |
| Subprocess parallelism via `symfony/process` | HIGH | `TenantRunCommand` already uses this pattern; read real `TenantRunCommand`; `--tenant=slug` path in `TenantMigrateCommand` confirmed |
| `AbstractTenant` trait pattern for maintenance flag | HIGH | Read real `TenantMailerConfigTrait` + `TenantFilesystemConfigTrait` usage pattern; established convention |
| Database-per-tenant health probe safety | MEDIUM | `DatabaseSwitchBootstrapper::boot()` calls `close()` then lazy-reconnects; health probe uses same path but in try/catch; not integration-tested yet |
| HTTP health endpoint route conflict risk | MEDIUM | No routing config read; `/_tenancy/health` is an assumption — should verify no conflict with existing routing before Phase 33 |

---

## Sources

- `src/EventListener/TenantContextOrchestrator.php` — read 2026-06-25; priority 20 constant verified (line 22)
- `src/Bootstrapper/BootstrapperChain.php` — read 2026-06-25; `boot()` + `clear()` (reverse) loop confirmed; no existing `health*` method
- `src/Bootstrapper/TenantBootstrapperInterface.php` — read 2026-06-25; 2-method interface confirmed
- `src/Bootstrapper/DatabaseSwitchBootstrapper.php` — read 2026-06-25; `boot()` calls `close()`, `clear()` checks `isConnected()` first
- `src/Bootstrapper/FilesystemBootstrapper.php` — read 2026-06-25; `boot()` is no-op; `clear()` flushes LRU cache
- `src/Bootstrapper/MailerBootstrapper.php` — read 2026-06-25; `boot()` is no-op; `clear()` flushes LRU transport cache
- `src/Bootstrapper/DoctrineBootstrapper.php` — read 2026-06-25; `boot()` and `clear()` both call `$em->clear()`
- `src/Driver/SharedDriver.php` — read 2026-06-25; `boot()` injects TenantContext into `TenantAwareFilter`; `clear()` is no-op
- `src/Command/TenantMigrateCommand.php` — read 2026-06-25; sequential `foreach($tenants)` loop with `finally: context/chain clear`
- `src/Command/TenantRunCommand.php` — read 2026-06-25; subprocess model via `new Process([PHP_BINARY, .../bin/console, ...tokens..., --tenant=slug])`
- `src/Context/TenantContext.php` — read 2026-06-25; zero-dependency value holder; `hasTenant()` + `setTenant()` + `clear()` confirmed
- `src/Entity/AbstractTenant.php` — read 2026-06-25; `$isActive` bool column + trait-based extension pattern (mailer, filesystem) confirmed
- `src/TenantInterface.php` — read 2026-06-25; current 6-method interface; `isInMaintenance()` would be 7th method
- `src/Exception/TenantInactiveException.php` — read 2026-06-25; HTTP 503 pattern confirmed (actually 403 — maintenance exception should use 503)
- `config/services.php` — read 2026-06-25; `class_exists`/`interface_exists` optional-dep guard pattern confirmed throughout
- `.planning/PROJECT.md` — read 2026-06-25; v0.5 scope (OPS-01, OPS-02, ISOL-07, DOC-21) confirmed

---

*Architecture research for: Symfony Tenancy Bundle — v0.5 Operations & Scale*
*Researched: 2026-06-25*
