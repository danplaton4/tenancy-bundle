# Feature Research — v0.5 Operations & Scale

**Domain:** Symfony multi-tenancy bundle (`danplaton4/tenancy-bundle` v0.4.1 → v0.5)
**Researched:** 2026-06-25
**Mode:** Feature decomposition for SUBSEQUENT milestone (operations & production-readiness)
**Confidence:** HIGH for maintenance mode lifecycle and HTTP semantics (IETF RFC + stancl/tenancy docs verified); HIGH for health check JSON format (IETF draft-inadarei-api-health-check-06 verified); MEDIUM for parallel migration UX (community sources + symfony/process docs verified, no canonical PHP multi-tenant parallel migration reference exists); LOW for MonitorBundle integration specifics (LiipMonitorBundle/MacPaw verified at interface level only)

---

## Scope Note

v0.4 made a real SaaS work end-to-end. v0.5 makes it **operable at scale** — the three confirmed features are:

| ID | Feature |
|----|---------|
| OPS-01 | Tenant-level maintenance mode — per-tenant toggle, isolated from other tenants |
| OPS-02 | Health check / MonitorBundle integration — per-tenant connectivity + bootstrapper probes |
| ISOL-07 | Parallel `tenancy:migrate` via `symfony/process` |

Each feature is self-contained. ISOL-07 is a direct enhancement to the existing `TenantMigrateCommand`. OPS-01 and OPS-02 are new subsystems that hook into the existing resolver chain and bootstrapper lifecycle.

This document does NOT re-propose anything already shipped (see PROJECT.md §Validated).

---

## Table Stakes

Features operators expect. Missing = the bundle cannot be called "production-ready."

| Feature | Why Expected | Complexity | Dependencies on Existing Systems |
|---------|--------------|------------|-----------------------------------|
| **OPS-01a** Per-tenant maintenance toggle stored on the Tenant entity | Any SaaS operator expects to flip a DB row to take one tenant offline without redeploying; stancl/tenancy v3 ships `MaintenanceMode` trait on the tenant model; this is the de-facto Symfony equivalent | S | `AbstractTenant` (extend with `maintenance_mode` column or `TenantMaintenanceConfigTrait`); `TenantInterface` (add `isUnderMaintenance(): bool` or keep on concrete entity only) |
| **OPS-01b** HTTP 503 with `Retry-After` header when tenant is under maintenance | HTTP spec and IETF guidance: maintenance → 503 + `Retry-After`; search engines (Googlebot), uptime monitors, and API clients all act on 503 differently from 404/500; without `Retry-After`, crawlers may de-index the tenant | S | `TenantContextOrchestrator` (kernel.request listener) or a new dedicated `MaintenanceModeListener` at higher priority; no new Symfony infrastructure needed |
| **OPS-01c** CLI command `tenancy:maintenance:enable <slug>` and `tenancy:maintenance:disable <slug>` | Operators toggle maintenance via automation (Kubernetes Jobs, CI/CD pipelines, Ansible) — they cannot use the database console; having a CLI command is table stakes for anything calling itself a production operations feature | S | `TenantProviderInterface::findBySlug()` (shipped Phase 07); Doctrine EM to persist the flag |
| **OPS-01d** Landlord and health-check routes bypass maintenance mode | If the landlord admin panel also returns 503 when a tenant is under maintenance, the operator cannot fix the tenant; health check routes returning 503 will cause the load balancer to pull the entire node | S | Resolver chain (`ResolverChain` returns null for landlord/health routes; FIX-02 already handles null-resolution at orchestrator level) — maintenance check fires only when a tenant IS resolved |
| **OPS-02a** Per-tenant health endpoint returning JSON | Every SaaS with k8s/ECS/Fly.io infra needs `/health` or `/health/tenants/{slug}` returning `{"status":"pass"}` for uptime monitors and readiness probes; IETF `draft-inadarei-api-health-check-06` defines `pass`/`fail`/`warn` with `application/health+json` content type | M | `TenantProviderInterface`; `BootstrapperChain`; existing bootstrappers need a `probe()` hook or the health controller runs them independently; resolver chain for tenant identification |
| **OPS-02b** HTTP 200/503 from health endpoint (not 200 with `{"status":"fail"}`) | Load balancers (AWS ALB, nginx upstream, k8s httpGet probes) act on HTTP status code, not response body; returning 200 for a failing tenant is invisible to infrastructure tooling | S | Symfony controller returning `JsonResponse` with status 200 or 503 depending on probe aggregate |
| **OPS-02c** Bootstrapper probe interface | Health check must verify the same subsystems the bootstrapper configures (DB reachable, cache reachable, mailer DSN valid) — a health check that only pings "is the PHP process alive?" does not detect broken tenant DB credentials; bootstrappers are the natural probe owners | M | `TenantBootstrapperInterface` (extend with `probe(): ProbeResult` optional interface or separate `TenantBootstrapperProbeInterface`); `BootstrapperChain` to iterate probes |
| **ISOL-07a** `--concurrency=N` flag on `tenancy:migrate` | The current command is sequential; with 100+ tenants, a sequential migration taking 3s/tenant = 5min total; operators expect to tune parallelism; `--concurrency=5` is the idiomatic CLI flag (matches GitLab, MigrationWiz, and other migration tooling) | M | `TenantProviderInterface::findAll()` (existing); `symfony/process` (already a production `require` since CLI-02/`tenancy:run`) |
| **ISOL-07b** Per-tenant status output during parallel run | Operators must know which tenants succeeded and which failed, in near-real-time; a silent parallel run that only summarizes at the end is not debuggable at scale | M | `SymfonyStyle` / `OutputInterface` (existing in command); process output multiplexing via `Process::getIncrementalOutput()` |
| **ISOL-07c** Continue-on-failure semantics preserved from sequential mode | The sequential command already has continue-on-failure + summary table + exit-code-1-on-any-failure; operators have automation that relies on this contract; parallelizing must not break it | S | Existing `TenantMigrateCommand` failure aggregation pattern (Phase 07) |

---

## Differentiators

Features beyond table stakes that make the bundle stand out in the Symfony ecosystem.

| Feature | Value Proposition | Complexity | Dependencies |
|---------|-------------------|------------|--------------|
| **OPS-01e** IP allowlist bypass for maintenance mode | Operators need to test their own maintenance page before lifting the toggle; stancl/tenancy does NOT ship IP bypass (it only has a boolean toggle); shipping this is competitive whitespace | S | New config key `tenancy.maintenance.bypass_ips`; check `$request->getClientIp()` in the maintenance listener |
| **OPS-01f** Custom maintenance response template | SaaS products have branded maintenance pages; a hardcoded bundle Twig template is unprofessional; operators must be able to override the 503 body with their own template | S | Bundle `templates/maintenance.html.twig` override via standard Symfony template override mechanism; fallback to bundle default |
| **OPS-01g** `TenantMaintenanceEnabled` / `TenantMaintenanceDisabled` events | Operators want audit logs, Slack notifications, and monitoring hooks when a tenant goes in/out of maintenance; dispatching events from the CLI commands enables zero-coupling listeners | S | `EventDispatcherInterface` (already injected throughout the bundle); two new event classes |
| **OPS-01h** `tenancy:maintenance:status` command listing all tenants and their maintenance state | Operators managing 100+ tenants need a single command to see the maintenance status fleet-wide; no competing Symfony bundle ships this | S | `TenantProviderInterface::findAll()`; tabular `SymfonyStyle` output |
| **OPS-02d** Aggregate health endpoint `/health/tenants` with summary across ALL tenants | Useful for operator dashboards and uptime monitors that want a single webhook; returns fleet-level `pass`/`warn`/`fail` with per-tenant detail array; not k8s-safe (too slow for k8s probes, which need <1s) — document as "operator dashboard, not k8s probe" | L | All tenant iteration via `TenantProviderInterface::findAll()`; timeout per-tenant probe essential |
| **OPS-02e** Liveness vs. readiness distinction | Liveness: "is PHP alive?" → check process only (no DB); readiness: "can this tenant serve traffic?" → check DB + cache + every bootstrapper; k8s users need both; shipping distinct routes (`/health/live` vs `/health/ready/{slug}`) matches k8s idioms exactly | M | Two separate controller actions; liveness has no tenant dependency at all |
| **OPS-02f** Profiler integration — add health probe results to Tenancy WDT tab | Developers running health checks in dev can see probe outcomes in the profiler panel without hitting the HTTP endpoint; extends the existing `TenancyDataCollector` (Phase 19) | S | `TenantDataCollector` (existing); `TenantProfilerStash` (existing) |
| **ISOL-07d** `--dry-run` flag for parallel migrate | Mirrors `tenancy:shared:resync --dry-run` (SHARE-02); shows which tenants have pending migrations without applying them; operators run this before production deploys | S | Existing migrations config; `MigrationPlanCalculator` (already used in sequential path); zero new infrastructure |
| **ISOL-07e** `--tenant=<slug>` filter preserved for single-tenant parallel path | The sequential command already filters by `--tenant=`; parallelism must not accidentally break single-tenant targeted runs (N=1 is valid) | S | Existing `--tenant` option wiring in `TenantMigrateCommand` |
| **ISOL-07f** Structured JSON output mode (`--format=json`) | CI pipelines and deployment automation tools consume structured output more reliably than SymfonyStyle text; per-tenant result as a JSON array with `slug`, `status`, `migrations_run`, `error` fields | S | Symfony `OutputInterface`; no new dependencies |

---

## Anti-Features

Features commonly requested or tempting to build, explicitly out of scope for v0.5.

| Anti-Feature | Why Tempting | Why NOT Building It | What to Do Instead |
|--------------|-------------|---------------------|-------------------|
| Global (all-tenants) maintenance mode | "I want to take the whole system down" | Symfony already has multiple solutions: environment variable checks in `public/index.php`, `kefisu/maintenance-bundle`, a plain Caddy/nginx 503 response — building this inside a tenancy bundle duplicates infrastructure responsibility and creates confusion about "which maintenance mode fires first" | Document: "for global maintenance, use your web server or a flag file at `public/index.php` level; the tenancy bundle only manages per-tenant toggles" |
| Maintenance mode stored in a file (not DB) | "File-based toggle survives a DB outage" | File-based maintenance mode requires filesystem sync across nodes in multi-pod deployments; tenant entity in DB is the single source of truth and is already the pattern used for `isActive()` — adding a second storage path for the same lifecycle concept is inconsistent and creates split-brain risk | Store flag in DB via the tenant entity; document that if DB is down, the pod restarts anyway |
| Health check running bootstrapper `boot()` + `clear()` per probe | "A real health check boots the tenant" | Calling `boot()` in a health probe fires side effects (connection switch, cache namespace switch, identity map clear) — these are stateful operations designed for the request lifecycle, not for read-only health assertions; running them in a probe pollutes the current request context | Add a separate `TenantBootstrapperProbeInterface::probe(): ProbeResult` that checks connectivity without switching state (e.g., ping the DB host without replacing the current connection) |
| Per-tenant k8s readiness probe at individual pod level | "I want k8s to pull a pod when a specific tenant's DB is unreachable" | k8s readiness probes apply to the entire pod, not to individual tenants; pulling the pod for one tenant's DB failure removes ALL tenants from that pod — destructive in database-per-tenant mode | Route per-tenant health checks to an operator dashboard/alerting system (OPS-02d aggregate endpoint); document the architectural mismatch explicitly |
| Parallel migrate using PHP `ext-parallel` or `ext-pcntl` threads | "Threads are faster than processes" | `ext-parallel` requires ZTS PHP (not standard in most Docker images); `ext-pcntl` requires `pcntl_fork()` which is unsafe inside web processes and breaks Doctrine connection state; `symfony/process` is already a production `require` for `tenancy:run` — use it | Use `symfony/process` subprocesses (same mechanism as `tenancy:run`); each subprocess gets a fresh PHP process with a fresh Doctrine connection, which is the CORRECT isolation model for per-tenant DB switching |
| Automatic rollback on parallel migration failure | "If 3 of 100 tenants fail, roll back the other 97" | Doctrine Migrations does not support cross-database rollback; rolling back 97 tenants to undo a failed 3 is more dangerous than the original failure; continue-on-failure + manual fix is the industry-standard mitigation (GitLab, Flyway, Liquibase all use this model) | Preserve continue-on-failure semantics; output a clear failure summary with re-run instructions (`tenancy:migrate --tenant=<failed-slug>`) |
| Health check authenticating the caller | "Health endpoints should require an API key" | Authentication on `/health` creates a chicken-and-egg problem: if auth infra is down, health checks fail even though the app is healthy; load balancers cannot send API keys; Kubernetes httpGet probes cannot inject auth headers | Use network-level access control (Kubernetes NetworkPolicy, security group rules, private VPC endpoint) instead of application-level auth; document this explicitly |
| `tenancy:migrate` resuming from a mid-batch checkpoint | "If I kill the process at tenant 50, I want to resume at 51" | Resume semantics require durable state (a file or DB record tracking which tenants ran); this is significant scope for v0.5; Doctrine Migrations is idempotent per-tenant by design (already-applied migrations are skipped) — re-running `tenancy:migrate` on the full set is effectively free | Document the idempotency guarantee: "re-run `tenancy:migrate` after failure — already-migrated tenants are no-ops" |
| Health check as a bootstrapper (auto-registered in BootstrapperChain) | "Health probes should participate in the bootstrapper lifecycle" | Bootstrappers run on EVERY request; if a health probe bootstrapper is in the chain it fires for every tenant request, adding DB+cache latency to every request even when no health check is happening | Register health probe logic as separate services tagged `tenancy.health_probe`, not `tenancy.bootstrapper`; health controller invokes them explicitly |

---

## Per-Feature Deep Dive

### OPS-01 — Tenant-level Maintenance Mode

**Operator contract:**
```bash
# Bring a tenant down for maintenance
bin/console tenancy:maintenance:enable acme --retry-after=3600 --reason="Database upgrade"
# → Stores maintenance_mode = UNIX_TIMESTAMP + 3600, reason = "...", on tenant row
# → Dispatches TenantMaintenanceEnabled event

# Any request to acme's subdomain now returns:
# HTTP 503 Service Unavailable
# Retry-After: 3600
# Content-Type: text/html (or application/json for API requests)
# Body: maintenance.html.twig rendered

# Restore
bin/console tenancy:maintenance:disable acme
# → Clears maintenance_mode column
# → Dispatches TenantMaintenanceDisabled event
```

**Storage model — extend `AbstractTenant`:**
```php
// AbstractTenant gains (or TenantMaintenanceConfigTrait provides):
#[ORM\Column(type: 'integer', nullable: true)]  // UNIX timestamp of expected end, or 0 = indefinite
private ?int $maintenanceUntil = null;

public function isUnderMaintenance(): bool { return $this->maintenanceUntil !== null; }
public function getMaintenanceUntil(): ?int { return $this->maintenanceUntil; }
```

Storing as a nullable int timestamp avoids a new boolean + datetime pair. The `Retry-After` value is `maintenanceUntil - time()` when a future timestamp is set, or a sensible default (e.g., 3600) when `0` (indefinite).

**Listener priority:** Must fire AFTER tenant is resolved (i.e., after the resolver chain runs in `TenantContextOrchestrator` at priority 20) but BEFORE the controller dispatches. Priority 15 works.

Null-resolved requests (landlord/health routes) never reach the maintenance listener because `TenantContext::hasTenant()` returns false — the listener is a no-op for them. This is the FIX-02 null-branch behavior already in the resolver chain.

**IP allowlist bypass:**
```yaml
tenancy:
  maintenance:
    bypass_ips: ['192.168.1.0/24', '10.0.0.5']
```
Checked via `$request->getClientIp()` before the 503 response. `127.0.0.1` / `::1` should be in the default bypass list (prevents localhost health checks from triggering maintenance 503).

**Content negotiation:**
- `Accept: application/json` (or `X-Requested-With: XMLHttpRequest`) → JSON body `{"error":"maintenance","retry_after":3600}`
- Everything else → Twig template render (with bundle-default fallback)

**Dependencies:**
- `AbstractTenant` — add `maintenanceUntil` column (or `TenantMaintenanceConfigTrait` for users with custom entities)
- `TenantContext::hasTenant()` — existing; maintenance check skips null-tenant requests
- `EventDispatcherInterface` — existing; two new events

---

### OPS-02 — Health Check / MonitorBundle Integration

**The liveness / readiness split:**

| Endpoint | Purpose | What It Checks | Who Calls It |
|----------|---------|----------------|--------------|
| `GET /health/live` | Liveness | PHP process running, memory below limit | k8s `livenessProbe`, uptime monitors |
| `GET /health/ready/{slug}` | Per-tenant readiness | DB reachable, cache reachable, each bootstrapper probe OK | k8s `readinessProbe` (optional), operator dashboards, alerting |
| `GET /health/tenants` | Fleet aggregate | All-tenant summary, pass/warn/fail counts | Operator dashboards only, NOT k8s probes |

**Per-tenant readiness JSON (IETF `application/health+json`):**
```json
{
  "status": "pass",
  "checks": {
    "database:connectivity": [{ "status": "pass", "time": "2026-06-25T10:00:00Z" }],
    "cache:connectivity":    [{ "status": "pass", "time": "2026-06-25T10:00:00Z" }],
    "mailer:dsn":            [{ "status": "warn", "output": "No per-tenant DSN configured, using landlord default" }]
  }
}
```
HTTP 200 for `pass`/`warn`, 503 for `fail`. Media type `application/health+json`.

**Bootstrapper probe interface (separate from `TenantBootstrapperInterface`):**
```php
interface TenantBootstrapperProbeInterface
{
    public function probe(TenantInterface $tenant): ProbeResult;
}

final class ProbeResult
{
    public function __construct(
        public readonly string $status,  // 'pass' | 'warn' | 'fail'
        public readonly ?string $output = null,
    ) {}
}
```

Bootstrappers MAY implement this; the health controller iterates all registered bootstrappers, calls `probe()` on those that implement it, skips those that don't. No registration beyond the existing `tenancy.bootstrapper` tag. CRITICAL: `probe()` must NOT call `boot()` — it must use a read-only connectivity check (e.g., `$connection->ping()` or `SELECT 1`, not `$connection->close()` + reconnect).

**MonitorBundle integration strategy:**
- `liip/monitor-bundle` (tag: `liip_monitor.check`) and `MacPaw/symfony-health-check-bundle` both use a tagged-service pattern
- The bundle should ship a `TenantHealthCheck` that integrates with LiipMonitorBundle when present (guarded by `class_exists` check in the compiler pass), exposing per-tenant probe results as named checks
- This is opt-in glue code, not a hard dependency
- Without LiipMonitorBundle, the bundle uses its own lightweight Symfony controller

**Tenant identification for the health endpoint:**
- `GET /health/ready/{slug}` — slug in path, no resolver chain needed
- Bypasses resolver chain entirely (landlord-level route, no tenant context during resolution)
- Controller calls `TenantProviderInterface::findBySlug($slug)` directly, then invokes probes

**Timeout requirement (critical):** Each probe MUST have a configurable timeout (default 5s) to prevent a hanging DB from making the health endpoint hang indefinitely. Use `symfony/http-client` connection timeout or PDO `ATTR_TIMEOUT` pattern.

**Dependencies:**
- `TenantBootstrapperInterface` — extend with optional `TenantBootstrapperProbeInterface`
- `TenantProviderInterface::findBySlug()` — existing (Phase 07)
- `BootstrapperChain` — iterate for probe-capable bootstrappers
- Optional: `liip/monitor-bundle` (guarded, existing bundle pattern for optional Doctrine/Messenger deps)

---

### ISOL-07 — Parallel `tenancy:migrate`

**Current state:** `TenantMigrateCommand::execute()` iterates `$tenants` sequentially with `foreach`. Each iteration: `runMigrationsForTenant()` which calls `BootstrapperChain::boot()` → Doctrine Migrations → `TenantContext::clear()` + `BootstrapperChain::clear()`. `symfony/process` is already in `require` (not `require-dev`).

**Target CLI contract:**
```bash
bin/console tenancy:migrate                      # parallel, default concurrency=4
bin/console tenancy:migrate --concurrency=10     # 10 parallel workers
bin/console tenancy:migrate --concurrency=1      # effectively sequential (for debugging)
bin/console tenancy:migrate --dry-run            # show pending migrations per tenant, no apply
bin/console tenancy:migrate --tenant=acme        # single tenant (N=1, concurrency ignored)
bin/console tenancy:migrate --format=json        # machine-readable output
```

**Parallel execution model:**

Each worker is a `tenancy:run acme "doctrine:migrations:migrate --no-interaction"` subprocess via `new Process([$php, 'bin/console', 'tenancy:run', $slug, 'doctrine:migrations:migrate', '--no-interaction'])`. This reuses the existing `tenancy:run` mechanism and gives each subprocess a fresh PHP process with a fresh Doctrine connection — correct isolation for database-per-tenant.

The concurrency loop pattern (PHP, no ext-parallel required):
```
batch = chunk tenants into groups of $concurrency
for each batch:
    $processes = []
    foreach batch as tenant:
        $p = new Process([...])
        $p->start(fn($type, $buf) => output($tenant, $type, $buf))  // streaming output
        $processes[$tenant->getSlug()] = $p
    foreach processes as slug => process:
        $process->wait()
        if !$process->isSuccessful(): $failures[] = $slug
```

Chunking into batches (rather than a sliding-window pool) is simpler to implement correctly and avoids process-count drift. For most SaaS fleets (< 1000 tenants, < 10 concurrency), batch-mode performs identically to a sliding window.

**Progress output during parallel run:**
- Prefix each output line with `[<slug>]` to attribute it to the correct tenant
- Use `Process::getIncrementalOutput()` polled in a wait loop for near-real-time streaming
- At completion: `SymfonyStyle` summary table showing slug, status (pass/fail), duration, migrations run (count from output parsing or exit code)

**Dry-run implementation:**
- `--dry-run` spawns `tenancy:run $slug "doctrine:migrations:migrate --dry-run"` (Doctrine Migrations natively supports `--dry-run`; output shows pending version names without applying)
- Exit code still 0 even if migrations are pending (dry-run is informational)

**Failure aggregation (unchanged contract from sequential mode):**
- Continue on failure (skip to next tenant on exception/non-zero exit)
- Collect all failed slugs
- Print failure list at end
- `exit 1` if any tenant failed

**Dependencies:**
- `symfony/process` — already in `require` (Phase 07 / CLI-02)
- `tenancy:run` command — existing, reused as subprocess driver
- `TenantProviderInterface::findAll()` / `findBySlug()` — existing
- No new Doctrine Migrations dependency (probes are delegated to subprocesses which already have the migrations bundle in the user's container)

---

## Feature Dependencies

```
[AbstractTenant::$maintenanceUntil column OR TenantMaintenanceConfigTrait]
    └──required by──> [OPS-01 maintenance mode toggle CLI + listener]

[TenantContext::hasTenant() returning false for landlord/health routes] (FIX-02, shipped)
    └──gates──> [OPS-01 MaintenanceModeListener skips null-resolved requests]

[EventDispatcherInterface] (existing throughout bundle)
    └──used by──> [OPS-01 TenantMaintenanceEnabled / TenantMaintenanceDisabled events]

[TenantContextOrchestrator kernel.request at priority 20] (Phase 01, shipped)
    └──precedes──> [OPS-01 MaintenanceModeListener at priority 15]

[TenantBootstrapperInterface] (Phase 01, shipped)
    └──optionally extended by──> [OPS-02 TenantBootstrapperProbeInterface]

[BootstrapperChain::addBootstrapper()] (Phase 01, shipped)
    └──iterated by──> [OPS-02 HealthController for probe-capable bootstrappers]

[TenantProviderInterface::findBySlug()] (Phase 07, shipped)
    └──used by──> [OPS-02 HealthController tenant lookup]
    └──used by──> [OPS-01 tenancy:maintenance:enable/disable CLI]

[symfony/process in require] (Phase 07 / CLI-02, shipped)
    └──required by──> [ISOL-07 parallel migration subprocess spawning]

[tenancy:run command] (Phase 07 / CLI-02, shipped)
    └──reused as subprocess driver by──> [ISOL-07 parallel tenancy:migrate]

[TenantMigrateCommand continue-on-failure + failure aggregation] (Phase 07, shipped)
    └──preserved and extended by──> [ISOL-07 parallel batch execution]

[TenantDataCollector / TenantProfilerStash] (Phase 19 / DX-02, shipped)
    └──optionally extended by──> [OPS-02 probe results in WDT panel]

[liip/monitor-bundle] (optional, external)
    └──opt-in glue code via class_exists guard──> [OPS-02 TenantHealthCheck registered as liip_monitor.check]
```

---

## Competitor Comparison

| Feature | stancl/tenancy v3 (Laravel) | This bundle v0.4 | This bundle v0.5 target |
|---------|----------------------------|-----------------|------------------------|
| Per-tenant maintenance mode toggle | YES (`MaintenanceMode` trait, boolean DB flag) | NO | YES (OPS-01, + IP bypass + events — exceeds stancl) |
| Custom 503 maintenance page | NO (returns default 503) | NO | YES (Twig template override) |
| IP allowlist bypass for maintenance | NO | NO | YES (competitive whitespace) |
| Health check endpoint | NO | NO | YES (OPS-02, liveness + readiness) |
| Bootstrapper-level probes | NO | NO | YES (TenantBootstrapperProbeInterface) |
| Parallel migrations | YES (`tenancy:migrate --force`, single process via artisan) | NO | YES (ISOL-07, subprocess concurrency) |
| `--concurrency=N` for migrations | NO | NO | YES |
| Migration dry-run | NO | NO | YES (ISOL-07) |

---

## Phase Ordering Implications

1. **OPS-01 (Maintenance Mode) should come first** — smallest scope, pure PHP, no new external dependencies, validates the event dispatch pattern used in OPS-02. Blocks nothing but is independently shippable.

2. **ISOL-07 (Parallel Migrate) second** — extends existing `TenantMigrateCommand` (known surface), `symfony/process` already required, high operator value, no new event infrastructure needed.

3. **OPS-02 (Health Checks) third** — most novel (new controller, new interface, optional MonitorBundle glue), touches both the bootstrapper interface (new probe method) and adds HTTP routes; benefits from OPS-01 being already merged (maintenance-mode check can be one of the health check probes).

4. **DOC-21 (Ops Docs) last** — documents OPS-01, OPS-02, ISOL-07 together in a production runbook; written after all three features are verified.

---

## Sources

### High Confidence (official docs / IETF / verified source code)

- [IETF draft-inadarei-api-health-check-06](https://datatracker.ietf.org/doc/html/draft-inadarei-api-health-check-06) — JSON health check format: `pass`/`fail`/`warn`, `application/health+json` media type, `checks` sub-objects
- [stancl/tenancy v3 — Tenant Maintenance Mode](https://tenancyforlaravel.com/docs/v3/tenant-maintenance-mode/) — `MaintenanceMode` trait, `maintenance_mode` column, `CheckTenantForMaintenanceMode` middleware; verified storage model (nullable DB column)
- [Symfony Process Component Docs](https://symfony.com/doc/current/components/process.html) — `Process::start()` async pattern, `getIncrementalOutput()`, wait callbacks; confirmed: no built-in concurrency pool (manual batch loop required)
- [MDN — 503 Service Unavailable](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Status/503) — `Retry-After` header semantics
- [LiipMonitorBundle README](https://github.com/liip/LiipMonitorBundle) — `liip_monitor.check` tag, `CheckInterface::check()`, group support, REST API at `/monitor/health/run`
- [MacPaw symfony-health-check-bundle](https://github.com/MacPaw/symfony-health-check-bundle) — `CheckInterface`, `health_error_response_code` config, Doctrine/Redis built-in checks
- `src/Command/TenantMigrateCommand.php` — existing sequential loop, continue-on-failure pattern, `--tenant` filter (local, verified)
- `src/Command/TenantRunCommand.php` — `new Process([$php, 'bin/console', ..., '--tenant='.$slug])` pattern (local, verified — the subprocess driver for ISOL-07)
- `src/Bootstrapper/TenantBootstrapperInterface.php` — `boot(TenantInterface): void` + `clear(): void` (local, verified — extension point for OPS-02 probe interface)
- `src/Entity/AbstractTenant.php` — `isActive()` boolean column + `TenantMailerConfigTrait`/`TenantFilesystemConfigTrait` precedent (local, verified — informs maintenance flag storage pattern)

### Medium Confidence (community articles, secondary sources)

- [Titouan Galopin — Executing database migrations at scale with Symfony and Doctrine](https://medium.com/@galopintitouan/executing-database-migrations-at-scale-with-symfony-and-doctrine-4c60f86865b4) — distributed lock to prevent concurrent migration runs; single-pass model; no true parallelism described
- [Laracasts — Multi-tenancy with database-per-tenant: how to handle lot of migrations efficiently](https://laracasts.com/discuss/channels/laravel/multi-tenancy-with-database-per-tenant-how-to-handle-lot-of-migrations-efficiently) — community pattern: 10 parallel workers for 1000-tenant fleet reduces 83min to 8.3min
- [PHP Multithreading in Practice: Parallel Extension vs Symfony Process](https://jinalisolanki.medium.com/php-multithreading-in-practice-parallel-extension-vs-symfony-process-24d759612a5f) — process-based vs threads; confirmed: Symfony Process is safest for production PHP
- [Camille Hodoul — Symfony Maintenance Mode](https://camillehdl.dev/symfony-maintenance-mode/) — file-based flag pattern; confirms `kernel.request` is the correct interception point for web; confirms CLI does not trigger `kernel.request`
- [Reliable Uptime — REST API Health Check Endpoint Design](https://reliableuptime.com/blog/rest-api-health-check-endpoint-design) — aggregate JSON format, per-dependency checks with timeout, HTTP 503 for infrastructure tooling
- [oneuptime — Health Check Design](https://oneuptime.com/blog/post/2026-01-30-health-check-design/view) — `/health/live` vs `/health/ready` route structure; liveness/readiness distinction

### Low Confidence (inferred from architecture, no direct source)

- Tenant health check as a bootstrapper probe (vs a separate interface): no existing PHP multi-tenant bundle ships this pattern; design is original to this bundle; LOW confidence on naming/API shape but HIGH confidence on the need

---

*Feature research for: Symfony tenancy bundle v0.5 Operations & Scale*
*Researched: 2026-06-25*
