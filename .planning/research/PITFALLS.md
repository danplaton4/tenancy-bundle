# Pitfalls Research — v0.5 Operations & Scale

**Domain:** Symfony multi-tenancy bundle (`danplaton4/tenancy-bundle`), v0.5 operational features added to an event-driven multi-tenant kernel (OPS-01 maintenance mode, OPS-02 health checks, ISOL-07 parallel migrations)
**Researched:** 2026-06-25
**Confidence:** HIGH for lifecycle/bootstrapper pitfalls (grounded in live source reading of `TenantContextOrchestrator`, `BootstrapperChain`, `TenantMigrateCommand`, and the `TenantContext` value holder); MEDIUM for health-check integration patterns (verified against Symfony MonitorBundle design intent and common probe patterns); HIGH for parallel-process pitfalls (grounded in `symfony/process` documented behavior and existing `TenantRunCommand` pattern in codebase).

> **Scope note.** This research covers only the *new attack surface* introduced by v0.5. General bootstrapper lifecycle pitfalls (identity-map pollution, DBAL reset, SQL filter bypass, Messenger worker teardown) live in `.planning/milestones/v0.2-research/PITFALLS.md`. Where a v0.5 feature intersects an existing pitfall, the intersection is called out explicitly. The **prime directive throughout is zero cross-tenant leaks** — a data leak is a security incident, not a configuration mistake (`strict_mode` ON by default).

---

## Critical Pitfalls

### Pitfall 1: Maintenance check fires BEFORE tenant resolution — can't gate per tenant

**[SECURITY-CRITICAL — zero-leak guarantee at risk]**

**What goes wrong:**
A `kernel.request` listener that checks "is this tenant in maintenance mode?" runs before the orchestrator at priority 20. At that point `TenantContext` is empty — no tenant has been resolved yet. The check cannot read the tenant slug, falls back to "no tenant → not in maintenance", and passes every request through. Maintenance mode effectively does nothing.

Alternatively, the implementation registers at priority > 20 (higher priority = earlier execution in Symfony), runs before the orchestrator, cannot determine the tenant, and silently passes.

**Why it happens:**
Symfony's `kernel.request` listener priority ordering is counterintuitive: priority 32 = router (runs first), priority 20 = orchestrator, priority 8 = security firewall. A developer adding a maintenance listener at priority 30 (before router) or 25 (after router, before orchestrator) has no tenant context yet.

The existing `TenantContextOrchestrator` is registered at `PRIORITY = 20`. Any maintenance-check listener at priority > 20 fires before tenant resolution.

**How to avoid:**
Register the maintenance-mode listener at **priority < 20** (e.g., priority 15 — after orchestrator but before security at 8). By the time the listener fires, `TenantContext` is populated (or null for landlord/health/public routes). The listener reads `$tenantContext->getTenant()`, fetches that tenant's maintenance flag, and either short-circuits with a 503 or passes. When `$tenantContext->getTenant()` is null (landlord/health routes — see Pitfall 3), the listener MUST pass through unconditionally.

Add a `MaintenanceModeContractPass` that asserts: if a service is tagged `tenancy.maintenance_checker`, it is NOT registered at priority >= 20 on `kernel.request`. Fail compile with a descriptive error if the ordering invariant is broken — this is the v0.2 `CacheDecoratorContractPass` pattern applied to listener ordering.

**Warning signs:**
- A maintenance-mode integration test passes (listener fires, checks, allows) when the tenant IS in maintenance, meaning the check is running after context is set — but fails to block if the listener is accidentally re-registered at the wrong priority.
- `dump($tenantContext->getTenant())` inside the listener returns null even on tenant routes.
- Maintenance flag set for tenant-A, tenant-A request still reaches controller.

**Phase to address:** Phase 31 (OPS-01 maintenance mode). The listener-priority contract test (`assert maintenance listener fires AFTER orchestrator`) is a quality gate.

---

### Pitfall 2: Maintenance listener fires TOO LATE — controller already did work before 503

**What goes wrong:**
The maintenance listener is registered at priority < kernel.request (e.g., a `kernel.controller` listener or an event subscriber on `kernel.controller_arguments`). The router runs, the controller is dispatched, the controller hits the database, does expensive work, and THEN maintenance mode is checked — returning a 503 that still logged a query and mutated state for a tenant that should have been blocked.

Subtler: the listener is correctly registered on `kernel.request` at priority 15, but conditionally defers to a second listener tagged `kernel.controller` (e.g., a "check in the controller trait" approach). Same result — expensive work runs before the gate.

**Why it happens:**
Controller traits or Symfony `AbstractController` patterns that check "am I allowed?" before returning feel natural in controller code. Developers extract the maintenance check into a helper, call it from the controller, and the 503 fires after the constructor has already injected services and queries have started.

**How to avoid:**
The gate MUST be a `kernel.request` listener at priority 15. Nothing else. No controller-level checks as the primary gate. Document explicitly in the maintenance-mode feature: "if you add a controller-level guard as a convenience, it is defense-in-depth only, not the primary gate."

**Warning signs:**
- Integration test: set tenant to maintenance, dispatch request, assert 0 Doctrine queries were executed. If the assertion fails, the gate is too late.
- Monolog shows "SELECT ... WHERE tenant_id = X" on the same request that returned 503.

**Phase to address:** Phase 31 (OPS-01). Query-count assertion (`assertSame(0, $queryCount)` before the 503 is confirmed) is a quality gate.

---

### Pitfall 3: Landlord, health-check, and public routes locked out by maintenance mode

**[SECURITY-CRITICAL — operators locked out of their own system]**

**What goes wrong:**
The maintenance listener checks `$tenantContext->getTenant()`. When a tenant IS active, it checks the flag and may return 503. But the developer forgets the exemption list: landlord admin routes, `/health`, `/ready`, and unauthenticated public routes. Two disasters:

1. The health probe URL `/health` returns 503 during tenant maintenance, causing the load balancer to mark the app server as dead, kill all traffic, and start a restart loop. A maintenance mode for one tenant takes the entire platform offline.
2. The operator trying to END maintenance mode logs in via the landlord admin, which hits the maintenance-mode listener, gets a 503 because the admin URL resolves to a tenant (or because the listener has no exemption for admin at all), and is locked out of the control plane.

**Why it happens:**
- The orchestrator already handles this correctly: `ResolverChain::resolve()` returns `null` for health/public/landlord routes, and the orchestrator null-branches (skip bootstrappers, leave `TenantContext` empty). But the maintenance listener has to make the SAME exemption decision independently.
- Developers assume "null tenant = no maintenance check needed" (correct) but forget that some routes DO resolve a tenant but MUST bypass maintenance (e.g., the landlord admin impersonating a tenant, health-check routes that carry a `X-Tenant-ID` header for per-tenant health, emergency bypass tokens).

**How to avoid:**
The maintenance listener's exemption logic must be explicit and tested:
1. `$tenantContext->getTenant() === null` — pass through (landlord/health/public; orchestrator already null-branched these).
2. Request matches a configurable `maintenance_bypass_routes` list (list of route names) — pass through.
3. Request carries a signed bypass token (`X-Maintenance-Bypass: <hmac>`) — pass through (for operator CLI access to a tenant in maintenance).
4. None of the above + tenant flag is set — return 503 with `Retry-After`.

The health-check endpoint must be on a route that resolvers return null for (no tenant identified), OR must be explicitly in the bypass list. The bundle should default the route name `tenancy_health` into the bypass list.

Compile-time guard: if `OPS-01` and `OPS-02` are both enabled, `MaintenanceModeContractPass` asserts the health-check route is in the bypass list (or the health resolver returns null). Fail compile if not.

**Warning signs:**
- Smoke test: set ALL tenants to maintenance mode, assert health endpoint returns 200, assert landlord admin returns 200.
- The load balancer starts cycling instances — check if health probe is 503.

**Phase to address:** Phase 31 (OPS-01). The "health endpoint exempt from maintenance" test is a quality gate. Phase 32 (OPS-02) must verify the same invariant holds when health checks are introduced.

---

### Pitfall 4: Maintenance flag stored in a location that leaks across tenants

**[SECURITY-CRITICAL — zero-leak guarantee at risk]**

**What goes wrong:**
The per-tenant maintenance flag is stored in a shared cache pool (e.g., `cache.app`) without namespace isolation. Tenant-A's `maintenance_mode = true` flag is written as `tenant_maintenance_A`. Tenant-B's code reads `cache.app->get('tenant_maintenance_A')` by accident — a key-guess attack, or (worse) the maintenance implementation uses a fixed cache key `current_maintenance_mode` shared across all tenants because the developer forgot to prefix by slug.

Parallel scenario: the flag is stored in the PHP process's static property or a singleton service (`MaintenanceRegistry::$flags`) that persists across requests in a long-running server (FrankenPHP, ReactPHP). Tenant-A's maintenance flag bleeds into tenant-B's request in the same worker process.

**Why it happens:**
- `cache.app` is namespaced per tenant via the `TenantAwareCacheAdapter` (Phase 05), but only when `TenantContext` is active. If the maintenance flag is written from a CLI command (where `TenantContext` may not be bootstrapped via the same adapter), it writes to the landlord-namespace pool, then the HTTP request reads from the tenant-namespace pool — cache miss, maintenance never activates.
- Static properties or services with `shared: true` in DI config survive across requests in async PHP runtimes. Any per-tenant flag held in PHP memory must be keyed by slug and reset on `TenantContextCleared`.

**How to avoid:**
Store the maintenance flag in the **tenant database row** (an `is_maintenance_mode` column on the `Tenant` entity). The flag is authoritative at the DB layer, completely isolated per tenant by definition, and readable by any process without cache-namespace concerns. Cache the result per-request in a `MaintenanceModeChecker` service that is `shared: true` and caches only for the duration of the request (cleared on `TenantContextCleared`).

Alternatively, use the tenant-namespaced cache adapter — but only via the same bootstrapper path that the HTTP request uses. CLI-triggered maintenance flag changes MUST go through the tenant bootstrapper chain or write directly to the DB.

Never use a static property or process-global state for the flag.

**Warning signs:**
- Integration test: set tenant-A to maintenance, dispatch a request for tenant-B, assert tenant-B is NOT in maintenance. If this fails, cross-tenant flag contamination is present.
- Async runtime test: handle two requests in the same worker process (tenant-A in maintenance, tenant-B not), assert tenant-B's second request passes. If it fails, a static/shared flag is leaking.

**Phase to address:** Phase 31 (OPS-01). Cross-tenant isolation test is a quality gate. The storage choice (DB column vs. namespaced cache) must be decided and documented before plan execution.

---

### Pitfall 5: Bad HTTP status code or missing `Retry-After` breaks SEO and monitoring

**What goes wrong:**
The maintenance mode 503 response lacks a `Retry-After` header. Search engine crawlers (Googlebot) treat a 503 without `Retry-After` as a temporary error and progressively de-index the tenant's content. After maintenance ends, re-indexing takes days to weeks. A monitoring probe that expects a specific status code (e.g., 200) starts firing P1 alerts for a scheduled maintenance window because nobody added the probe exemption or documented the expected status.

Secondary failure: developer returns 200 with an HTML "maintenance" body instead of 503, because "it feels nicer." Googlebot now thinks the maintenance page IS the content and indexes it — every URL for the tenant starts ranking for "this site is under maintenance."

**Why it happens:**
- HTTP semantic correctness is easy to get wrong under time pressure.
- `Retry-After` is rarely tested because testing time-related headers requires deliberate effort.
- Monitoring probes are written once and rarely revisited.

**How to avoid:**
The maintenance 503 response MUST include:
- Status code 503 (NOT 200, NOT 302).
- `Retry-After: <estimated_seconds>` header (configurable per tenant, default 3600).
- `Content-Type: application/json` for API routes (detected by `$request->getPreferredFormat()`); `text/html` for browser routes.
- `Cache-Control: no-store` (prevent CDNs from caching the 503).

The `RetryAfter` value must come from the maintenance configuration (estimated end time to seconds-until), not be hardcoded. If the estimated end time is not set, use a config default.

A dedicated `MaintenanceResponse` value object (not a generic `Response`) ensures all of the above are always present and testable. Test: instantiate `MaintenanceResponse`, assert all four header invariants.

**Warning signs:**
- `curl -I https://tenant.example.com` during maintenance returns 503 but no `Retry-After` header.
- Google Search Console shows "crawl errors" spiking post-maintenance.
- Uptime monitor fires P1 during a scheduled maintenance window.

**Phase to address:** Phase 31 (OPS-01). `MaintenanceResponse` invariant test (`assertSame(503, $response->getStatusCode())`, `assertNotNull($response->headers->get('Retry-After'))`) is a quality gate.

---

### Pitfall 6: Maintenance bypass allow-list is spoofable

**What goes wrong:**
The maintenance bypass mechanism uses a header like `X-Maintenance-Bypass: supersecret` where `supersecret` is a static string in the bundle's YAML config. Any HTTP client that discovers or guesses the value can bypass maintenance mode for any tenant.

Secondary: the bypass token is sent as a query parameter (`?bypass=supersecret`) and ends up in server logs, Sentry traces, CDN request logs, and referrer headers to third-party assets.

**Why it happens:**
- "Simple bypass" tokens are the natural first implementation: easy to generate, easy to test, but no time-bounded or tenant-scoped security.
- Query parameter bypass is common in older maintenance-mode patterns.

**How to avoid:**
Use an HMAC-signed bypass token: `HMAC-SHA256(tenant_slug + timestamp_floor_to_5min, secret_key)`. The token is:
- **Tenant-scoped** (a token for tenant-A cannot bypass tenant-B).
- **Time-bounded** (floor to 5-minute windows — token valid for at most 10 minutes, window-overlap included).
- **Secret-keyed** (uses `APP_SECRET` or a dedicated `TENANCY_BYPASS_SECRET` env var).
- Validated in constant time (`hash_equals`).

Header only, never query parameter. Document the token generation command in the ops docs (`tenancy:maintenance:bypass-token <slug>`).

**Warning signs:**
- The bypass value is a static string in `tenancy.yaml` committed to git.
- Bypass token appears in Nginx/Apache access logs.
- A bypass token for tenant-A successfully bypasses tenant-B.

**Phase to address:** Phase 31 (OPS-01). The bypass-token test (wrong tenant denied, expired window denied, correct token passes, constant-time comparison) is a quality gate.

---

### Pitfall 7: Cache staleness keeps a tenant down after maintenance ends

**What goes wrong:**
The maintenance flag is cached per-request (correct), but the cache warm-up or a secondary cache layer (APCu, OPcache, CDN) holds the 503 or the flag value for longer than the maintenance window. Maintenance ends, operator sets flag to false, but the CDN continues serving cached 503 responses for 10 minutes. The tenant calls support. Support checks the DB — flag is false. Everything looks fine on the server. The CDN is the culprit.

Internal variant: the per-request `MaintenanceModeChecker` caches the flag for the request but the flag is refreshed from a backing store (Redis/Memcached) with a 60-second TTL. The operator sets maintenance off, but in-flight requests in the same 60-second window still see the flag as true.

**Why it happens:**
- `Cache-Control: no-store` on the 503 should prevent CDN caching, but many CDNs override this for 5xx responses (e.g., Cloudflare always caches 503 for 30s by default).
- Per-request caches and external caches have different TTLs; the operational expectation is "maintenance ends NOW" but the technical reality is "maintenance ends within one TTL window."

**How to avoid:**
- The 503 response MUST include `Cache-Control: no-store, no-cache, must-revalidate` and `Pragma: no-cache`. Document known CDN overrides (Cloudflare, Fastly) in ops docs with the CDN-side config to disable 5xx caching.
- The backing flag store (DB or cache) must have a max TTL of 5 seconds for the "in maintenance" state (checked every request at negligible cost — single DB read or cache lookup). When the flag is false (normal operation), longer TTL is acceptable.
- Provide a `tenancy:maintenance:flush-cache <slug>` command that purges the cached flag for a specific tenant, callable from the landlord admin after setting maintenance off.

**Warning signs:**
- After setting maintenance off, CDN/monitoring still reports 503 for more than 30 seconds.
- `curl` directly to origin returns 200 but `curl` via CDN returns 503.
- Response headers show `Age: 45` on a 503 (CDN cached it).

**Phase to address:** Phase 31 (OPS-01). Docs must include the CDN 5xx caching warning.

---

### Pitfall 8: Health-check endpoint triggers full tenant bootstrapping and leaks context into next request

**[SECURITY-CRITICAL — zero-leak guarantee at risk]**

**What goes wrong:**
The health endpoint (`/health` or `/ready`) is registered as a route that the resolver chain CAN resolve to a tenant — for example, it carries `X-Tenant-ID: tenant-A` in the load balancer's probe configuration, or the host-based resolver matches `health.tenant-a.example.com`. The orchestrator then runs the full bootstrapper chain (DB connection switched, Doctrine EM cleared, cache namespaced). The health check completes, returns 200.

But the health endpoint fires on `kernel.terminate`, not after a long HTTP connection — it terminates quickly. If the server is async (FrankenPHP, Swoole) and `kernel.terminate` runs in the SAME fiber context that immediately accepts the next request, there is a window where `TenantContext` is not yet cleared when the next request's resolver runs.

Even without async: if the health endpoint throws during the check (DB unreachable), the exception propagates before `onKernelTerminate` runs. `BootstrapperChain::clear()` is never called. `TenantContext` is never cleared. The NEXT request runs with the previous health check's tenant context still set.

**Why it happens:**
The `TenantContextOrchestrator::onKernelTerminate` is the cleanup path (see source: it calls `bootstrapperChain->clear()` then `tenantContext->clear()`). But `onKernelTerminate` only fires when the kernel terminates cleanly. An exception in the request lifecycle can short-circuit `kernel.terminate`.

In Symfony's standard HTTP stack this is mostly safe (each request/response cycle is isolated in a fresh process or PHP-FPM request), but in long-running runtimes (FrankenPHP, Swoole, RoadRunner) a single PHP process handles thousands of requests — a leaked `TenantContext` from one request WILL contaminate the next.

**How to avoid:**
The health-check route MUST be configured so ALL resolvers return null for it. The `HostResolver` and `HeaderResolver` should have the health-check path in their bypass list, OR the health check URL must not match any tenant's domain/header pattern. Document this as a required configuration item in the ops docs.

Implement health-check checks in a separate `HealthChecker` service that receives explicit `TenantInterface` arguments (NOT via `TenantContext`) and never calls `BootstrapperChain::boot()`. The health check should do read-only probes (ping the tenant DB connection, check bootstrapper state) without mutating any shared state.

For the teardown-safety concern: add a `try/finally` wrapper in the health handler that guarantees `TenantContext::clear()` is called even if the probe throws. This is defense-in-depth over `kernel.terminate`.

**Warning signs:**
- Health endpoint returns tenant-specific data (e.g., tenant slug in response body).
- The request immediately AFTER a health-check request behaves as if it's scoped to the health-check tenant.
- `TenantContext::hasTenant()` returns true at the START of a request that should have been landlord-scoped.

**Phase to address:** Phase 32 (OPS-02). The "health endpoint does NOT populate TenantContext" test and the "leaked context after health check exception" test are quality gates.

---

### Pitfall 9: Health probes hammer every tenant DB — thundering herd on load-balancer poll

**What goes wrong:**
The OPS-02 health check iterates over all tenants (`TenantProviderInterface::findAll()`) and pings each tenant's DB connection on every probe request. The load balancer polls the health endpoint every 5 seconds. With 200 tenants, that is 200 DB connections opened, pinged, and closed every 5 seconds per app instance. With 3 app instances, that is 600 DB operations every 5 seconds — from health checks alone, before any real traffic.

At 1000 tenants (realistic SaaS scale), this is connection exhaustion. MySQL's default `max_connections = 151` is exceeded by the health probe alone.

**Why it happens:**
- "Check that everything works" is the natural health-check specification. "Everything" gets interpreted as "all tenants."
- Health checks are written for 5 tenants in dev, never load-tested at 200+ tenants.

**How to avoid:**
Distinguish between two health check modes:

1. **Liveness probe** (every 5s, called by load balancer): checks that the PHP process is alive and the landlord DB is reachable. NO per-tenant probes. Returns 200/503 based on a single connection ping to the landlord schema.

2. **Readiness probe** (every 30s, human-triggered or scheduled): samples a configurable N tenants (default: 5, random selection), checks their DB connections and bootstrapper health. Does NOT check all tenants. Returns a summary with OK/WARN/FAIL per sampled tenant.

3. **Full audit** (on-demand, CLI only: `tenancy:health:check --all`): checks all tenants. Not exposed via HTTP. Rate-limited by caller (operator runs it at their discretion).

This mirrors the liveness/readiness distinction in Kubernetes and prevents the thundering herd.

**Warning signs:**
- DB `SHOW PROCESSLIST` shows a spike in connections every 5 seconds.
- `max_connections_exceeded` errors in MySQL/PostgreSQL logs correlated with health-check timing.
- App performance degrades in proportion to the number of tenants (not traffic).

**Phase to address:** Phase 32 (OPS-02). The readiness probe's "max N tenants per poll" is a hard constraint, not a default. Liveness probe MUST NOT iterate tenants — enforced by test asserting zero calls to `TenantProviderInterface::findAll()` in the liveness path.

---

### Pitfall 10: Health endpoint exposes DSNs, tenant list, or secrets on unauthenticated route

**[SECURITY-CRITICAL]**

**What goes wrong:**
The health response body includes diagnostic information to help operators debug failures: connection DSNs, tenant slugs, bootstrapper configuration. This response is on a public, unauthenticated `/health` endpoint (it must be unauthenticated so the load balancer can poll it without session management). A malicious actor (or a misconfigured search engine crawler) accesses `/health` and learns the DB DSNs, tenant slugs, and internal service topology.

This is the v0.5 restatement of the v0.3 profiler pitfall (Pitfall 3 in the v0.3 PITFALLS.md): "Collector stores connection DSN with password in panel."

**Why it happens:**
- Developers copy the exception message directly into the health response ("Connection failed: mysql://user:password@db:3306/tenant_a").
- Tenant slugs seem non-sensitive but are enough to enumerate all tenants for a targeted attack.
- The health endpoint is tested on localhost and never audited for information leakage.

**How to avoid:**
Apply the same DSN-sanitization rule as the v0.3 profiler pitfall: health check responses MUST NOT contain:
- Any DSN-shaped string (sanitize the same way as the Mailer bootstrapper).
- Tenant slugs (use opaque IDs or counts: "3 of 5 tenants healthy").
- Stack traces or exception messages.
- Internal service identifiers.

The UNAUTHENTICATED liveness response should be `{"status": "ok"}` or `{"status": "degraded"}` with a 200/503 status code and nothing else.

The AUTHENTICATED (operator-only, protected by security firewall) readiness response can include per-tenant status summaries with slugs and failure reasons (redacted of credentials).

Provide a `HealthResponseSanitizer` that strips DSN-shaped strings from any string value before it enters the response. Test: inject a DB-unreachable error containing the DSN, assert the health response does not contain the DSN.

**Warning signs:**
- `curl https://app.example.com/health | jq .` shows any value containing `://` or `@`.
- Tenant slugs visible in unauthenticated health JSON.
- `/health` endpoint returns stack traces on DB failure.

**Phase to address:** Phase 32 (OPS-02). `HealthResponseSanitizer` test (DSN-in → DSN-out-redacted) is a quality gate.

---

### Pitfall 11: Readiness vs. liveness confusion causes load-balancer restart loops

**What goes wrong:**
Kubernetes (or any load balancer using health checks) uses two distinct probe types:

- **Liveness:** "Is this container alive? If not, restart it." Should NEVER fail for transient reasons (DB hiccup, one tenant down).
- **Readiness:** "Is this container ready to receive traffic? If not, remove from rotation." CAN fail for transient reasons.

The bundle ships one `/health` endpoint that the user wires as BOTH liveness and readiness. The liveness probe fails because one tenant's DB is unreachable. Kubernetes kills the container. The new container starts, the DB is still unreachable (it's a DB issue, not a code issue). Kubernetes kills it again. Restart loop — the entire app server is down because ONE tenant's DB is slow.

**Why it happens:**
- Single health endpoint is the path of least resistance.
- Kubernetes-specific health probe semantics are not part of bundle documentation.

**How to avoid:**
The bundle MUST ship two separate routes with documented semantics:

- `/health/live` (liveness): checks the PHP process and landlord DB only. NEVER fails due to tenant DB issues. Suitable for `livenessProbe`.
- `/health/ready` (readiness): checks landlord DB + sampled tenant DBs + bootstrapper state. Can fail transiently. Suitable for `readinessProbe`.

The ops docs MUST include a Kubernetes probe configuration example showing both probes with appropriate `periodSeconds` (10s liveness, 30s readiness) and `failureThreshold` values.

**Warning signs:**
- Kubernetes pod restart loop correlated with a single tenant DB outage.
- `kubectl describe pod` shows "Liveness probe failed" on a pod that was serving traffic fine.
- One tenant having DB issues causes 0% availability for ALL tenants on that pod.

**Phase to address:** Phase 32 (OPS-02). Two routes MUST be separate — not one route with a query parameter. This is a hard design requirement.

---

### Pitfall 12: Health check passes when a bootstrapper silently degraded

**What goes wrong:**
The health check pings the DB connection (TCP reachable, auth succeeds) and returns "healthy." But the `MailerBootstrapper` encountered an SMTP timeout during `boot()` and swallowed the exception (returning a degraded-but-not-failing state). The health check has no visibility into bootstrapper state — it only checks infrastructure reachability, not bootstrapper health.

From the user's perspective: the tenant's DB is healthy, but emails are silently failing. The health check says green. Operators don't investigate. The issue festers for hours.

**Why it happens:**
- `BootstrapperChain::boot()` currently has no return value and no per-bootstrapper health-state API (see source: `boot()` is `void`, errors propagate as exceptions). A bootstrapper that catches its own exception (to "degrade gracefully") has no channel to report degraded state.
- Health checks test infrastructure (DB, cache ping) not service state (did boot succeed?).

**How to avoid:**
Extend `TenantBootstrapperInterface` with an optional `health(): BootstrapperHealthState` method (via a separate `HealthReportingBootstrapperInterface` to avoid BC break). Bootstrappers that can degrade gracefully implement this interface and return `HEALTHY`, `DEGRADED`, or `FAILED` with a reason message.

The health checker iterates bootstrappers that implement `HealthReportingBootstrapperInterface` and includes their state in the readiness response. A `DEGRADED` state does not cause readiness to fail (it's a warning), but a `FAILED` state does.

The existing `TenantBootstrapperInterface` is unchanged — BC preserved. Only bootstrappers that opt in need to implement the health interface.

**Warning signs:**
- Mailer bootstrapper swallows SMTP timeouts, health says green, emails not delivered.
- Flysystem bootstrapper's per-tenant-adapter fails to initialize but does not propagate the failure.
- No health event fires that distinguishes "boot succeeded but degraded" from "boot succeeded cleanly."

**Phase to address:** Phase 32 (OPS-02). `HealthReportingBootstrapperInterface` is the zero-BC-break extension point; the `BootstrapperHealthState` value object carries `status` + `message` + optional `bootstrapper_class`. At minimum, the `DatabaseSwitchBootstrapper` and `MailerBootstrapper` should implement this interface.

---

### Pitfall 13: Parallel migration with unbounded concurrency exhausts DB connections

**What goes wrong:**
ISOL-07 replaces the sequential loop in `TenantMigrateCommand` with parallel `symfony/process` workers. Naively, the implementation spawns one subprocess per tenant simultaneously. With 500 tenants, 500 processes are created, each opening a DB connection to run migrations. MySQL/PostgreSQL default max connections (151/100 respectively) is exceeded immediately. All processes fail with "too many connections." The migration command exits with partial failure, no clear report.

Connection exhaustion also affects the application — live traffic connections are starved during migration.

**Why it happens:**
- "Parallel" is interpreted as "all at once" instead of "max N at a time."
- `symfony/process` makes it easy to spawn processes but provides no built-in rate limiting.
- The existing sequential command (see source: `TenantMigrateCommand`) has no concurrency, so there's no prior implementation to model rate limiting from.

**How to avoid:**
Implement a bounded process pool: `--max-parallel` option (default: 4, configurable). Never spawn more than `--max-parallel` processes simultaneously. Use `symfony/process`'s non-blocking `start()` + polling loop pattern:

```php
// bounded-pool pseudo-code
$running = [];
foreach ($tenants as $tenant) {
    while (count($running) >= $maxParallel) {
        foreach ($running as $key => $process) {
            if (!$process->isRunning()) {
                $this->collect($process, $tenant);
                unset($running[$key]);
            }
        }
        usleep(100_000); // 100ms poll
    }
    $running[] = $this->startMigrationProcess($tenant);
}
// drain remaining processes
```

Document the `--max-parallel` default rationale: 4 was chosen as "safe for a MySQL default of 151 connections, leaving headroom for application traffic."

**Warning signs:**
- `SHOW PROCESSLIST` on DB during migration shows connection count spiking to `max_connections`.
- Migration command fails with "SQLSTATE[HY000] [1040] Too many connections" across all tenants.
- Migration works with 10 tenants, fails with 100+ tenants.

**Phase to address:** Phase 33 (ISOL-07). The `--max-parallel` option with a default of 4 is required. A test asserting that no more than N processes run simultaneously (via a `Process` mock with a counting factory) is a quality gate.

---

### Pitfall 14: Per-tenant output interleaved in parallel migration — garbled and untrackable

**What goes wrong:**
Each subprocess writes its migration output to stdout/stderr. In a naive implementation using `$process->run(fn($type, $buffer) => $output->write($buffer))`, concurrent processes write to the same output stream simultaneously. The result is garbled:

```
[tenant-a] Running migration 20240101000001  [tenant-c] Running migration
Done. [tenant-b] ERROR: Table already exists [tenant-a] Done.
```

No operator can parse this. Failures are invisible in the noise.

**Why it happens:**
The `TenantRunCommand` uses the same `$process->run(fn($type, $buffer) => $output->write($buffer))` callback pattern (see source). That works for sequential processes. For parallel processes, interleaved writes to the same output stream produce garbled output.

**How to avoid:**
Buffer each subprocess's output in memory, print it atomically only after the process completes. The output structure should be:

```
[tenant-a] STARTED
[tenant-b] STARTED
[tenant-c] STARTED
[tenant-a] Done (2 migrations applied).
[tenant-b] FAILED: Table 'tenant_b.xyz' already exists
[tenant-c] Done (0 migrations applied).
```

Each block is printed atomically after the process exits. Use `$process->getOutput()` and `$process->getErrorOutput()` post-exit, not streaming callbacks. Use a `SymfonyStyle` table or section separator between tenants.

**Warning signs:**
- Manual test: run parallel migration with 5 tenants, check output — if any two tenant's lines are interleaved, buffering is broken.
- Error messages from failed tenants are truncated or missing.

**Phase to address:** Phase 33 (ISOL-07). Output-atomicity test (assert no interleaving in buffered output) is a quality gate.

---

### Pitfall 15: Failed tenant's exit code lost — parallel migration falsely reports success

**What goes wrong:**
Subprocess exit codes are not collected or are incorrectly aggregated. One tenant's migration subprocess exits with code 1 (failure), but the parent process:
- Only checks `$process->isSuccessful()` after all processes are done, by which time the unsuccessful process was already garbage-collected.
- Treats any non-zero exit code as "process terminated" rather than "process failed."
- Uses `$process->getExitCode() ?? 0` (the same `?? 0` pattern from `TenantRunCommand` — see source line 89) and silently maps a null exit code (process timed out or was killed) to success.

The migration command exits 0. The CI pipeline goes green. Tenant-B's schema is not migrated.

**Why it happens:**
- `Process::getExitCode()` returns `null` if the process was never started, was terminated by signal, or timed out. The `?? 0` fallback from `TenantRunCommand` was appropriate for a pass-through command but is wrong for an aggregation command where null means unknown/failure.
- Exit code collection must happen AFTER `$process->wait()` or `$process->isRunning() === false`, which is easy to miss in a polling loop.

**How to avoid:**
- Collect exit code from each process only after `$process->isRunning() === false`.
- Treat `null` exit code as `FAILURE` (process killed/timed out), not success.
- Aggregate: if any process exit code is non-zero or null, the migration command exits 1. Record failed tenant slugs for the summary report.
- Print a final summary table: "N tenants succeeded, M failed" with slugs of failures. This is consistent with the existing sequential `TenantMigrateCommand` behavior (see source lines 109-119).

**Warning signs:**
- Test: create a subprocess that exits 1. Assert the migration command exits 1. Assert the failure summary lists that tenant.
- A process that is `kill -9`ed reports as success.
- The `--tenant=<slug>` filter path exits 0 even when that tenant's migration fails.

**Phase to address:** Phase 33 (ISOL-07). The null-exit-code-as-failure rule is a hard requirement. The exit-code aggregation test is a quality gate.

---

### Pitfall 16: Parallel migration on shared-db driver runs the same migration N times

**[SECURITY-CRITICAL — data corruption risk]**

**What goes wrong:**
The ISOL-07 parallel migration feature is implemented for `database_per_tenant` mode — each tenant has its own DB, so running migrations in parallel is safe by design. But the guard that prevents shared-db consumers from running `tenancy:migrate` is at the sequential command level (see source: `TenantMigrateCommand` line 57: `if ('shared_db' === $this->driver) { ... return FAILURE; }`).

If the parallel implementation is built in a way that bypasses this guard (e.g., a separate `TenantMigrateParallelCommand` that forgets to copy the guard, or a refactored shared `MigrateCommandTrait` that doesn't inherit the guard), shared-db consumers can run the parallel command. Each tenant subprocess runs `doctrine:migrations:migrate` on THE SAME physical database, simultaneously. The migrations table gets corrupted, migrations run multiple times, data is inconsistent.

**Why it happens:**
- The guard is in the sequential command, but the parallel command is new code that may not carry it forward.
- The `database_per_tenant` assumption is implicit in the parallel design — "parallel is safe because DBs are separate" — but is not enforced at the parallel command level.

**How to avoid:**
The parallel migration command MUST have the same `shared_db` guard as the sequential command. The guard must be in the shared base/trait, not duplicated. A `DriverContractPass` should assert: if any `tenancy:migrate*` command service is registered AND `driver === shared_db`, fail compile with a clear message. This prevents the class of bug at compile time, not at runtime.

Integration test: register the bundle with `driver: shared_db`, assert that the parallel migrate command is not registered in the container (just as the sequential command is not). This test must pass before ISOL-07 ships.

**Warning signs:**
- Running `bin/console tenancy:migrate --parallel` on a shared-db setup succeeds.
- `SELECT COUNT(*) FROM migration_versions` returns N times the expected number.
- Symfony container defines `TenantMigrateParallelCommand` but not `TenantMigrateCommand` (shared-db guard was in sequential command only).

**Phase to address:** Phase 33 (ISOL-07). The "parallel migrate NOT registered for shared_db" test is a quality gate.

---

### Pitfall 17: `symfony/process` output buffer deadlock on large migration output

**What goes wrong:**
`Process::getOutput()` buffers stdout internally. When migration output is large (e.g., 1000 migration steps, verbose output), the buffer fills, the subprocess blocks waiting for the parent to read it, the parent is waiting for the subprocess to exit — classic deadlock.

This is documented in the `symfony/process` docs: if the output is not consumed, the process may deadlock. The existing `TenantRunCommand` avoids this by using a streaming callback (`$process->run(fn($type, $buffer) => ...)`) which reads the buffer continuously. But in the parallel implementation where we buffer output (to avoid interleaving — Pitfall 14), we switch to `$process->getOutput()` post-exit, which re-introduces the deadlock.

**Why it happens:**
- The fix for Pitfall 14 (buffer and print atomically) uses `getOutput()` post-exit.
- `getOutput()` is only safe if the output is not so large it fills the OS pipe buffer (typically 64KB on Linux).

**How to avoid:**
Use a custom incremental buffer per process:

```php
$buffer = '';
$process->start(function(string $type, string $chunk) use (&$buffer): void {
    $buffer .= $chunk; // accumulate in PHP memory, not OS pipe buffer
});
$process->wait(); // blocks until exit while draining stdout/stderr via callback
// Now $buffer contains complete output — print atomically
```

This approach reads the pipe continuously (no deadlock), accumulates in PHP memory (not in the OS pipe buffer), and prints atomically after completion (no interleaving). `wait()` blocks until the process exits while continuously draining stdout/stderr via the callback — the documented safe pattern for `symfony/process` with large output.

**Warning signs:**
- Migration hangs indefinitely with verbose output (`-vvv`).
- Subprocess shows in `ps aux` as running but making no progress.
- Deadlock is intermittent (small tenants: OK; tenants with 50+ migrations: hangs).

**Phase to address:** Phase 33 (ISOL-07). The large-output test (subprocess generating 100KB of output does not deadlock) is a quality gate.

---

### Pitfall 18: Zombie/orphaned migration subprocesses after SIGTERM

**What goes wrong:**
During a long-running parallel migration, the operator sends SIGTERM to the parent process (Ctrl+C, deploy restart, `kill $(pgrep tenancy:migrate)`). The parent receives the signal and exits. The spawned subprocess processes continue running — they are now orphaned, still holding DB connections, still running migrations. When the operator relaunches the command, they run into "another migration is already running" mutex locks, or worse, two migration processes run simultaneously on the same tenant DB.

**Why it happens:**
PHP's `Process` class does not automatically send SIGTERM to child processes on parent exit. `pcntl_signal` handlers that forward SIGTERM to children must be set up explicitly.

**How to avoid:**
Register a SIGTERM/SIGINT signal handler in the parallel migration command:

```php
if (extension_loaded('pcntl')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function() use (&$runningProcesses): void {
        foreach ($runningProcesses as $process) {
            $process->stop(0);
        }
        exit(1);
    });
}
```

Call `$process->stop()` on all running processes before exiting. `Process::stop()` sends SIGTERM then SIGKILL after a timeout.

For deployments without `pcntl` (Windows, some containers): document that SIGTERM forwarding is not available and operators should use `--max-parallel=1` in those environments.

**Warning signs:**
- `ps aux | grep 'tenancy:migrate'` shows child processes after the parent was killed.
- DB connections not released after migration cancellation.
- Re-running migration fails with "Migration lock held by another process."

**Phase to address:** Phase 33 (ISOL-07). SIGTERM handler is a hard requirement for Linux/macOS deployments. `pcntl` availability check is required.

---

### Pitfall 19: Partial-failure state — some tenants migrated, others not, no actionable report

**What goes wrong:**
Parallel migration fails for 30 out of 200 tenants. The command exits 1 (correctly). But the error report does not list WHICH tenants failed and which succeeded, does not distinguish "failed before migration started" (DB unreachable) from "failed mid-migration" (schema conflict after applying 3 of 10 migrations), and does not provide a re-run command to migrate only the failed tenants.

The operator must manually cross-reference which tenants are at which migration version — potentially querying each tenant's DB individually.

**Why it happens:**
The sequential command has a `$failures` array and prints it (see source lines 113-119). The parallel implementation must preserve this behavior and add "which tenants succeeded" to the output so the operator can construct a `--tenant=` filter re-run.

**How to avoid:**
Emit a final structured summary after all processes complete:

```
Parallel migration complete: 170 succeeded, 30 failed

Failed tenants:
  - tenant-b: SQLSTATE[42S01] Table already exists (applied 3/10 migrations)
  - tenant-f: Connection refused (applied 0/10 migrations)
  ...

To re-run failed tenants:
  bin/console tenancy:migrate --tenant=tenant-b --tenant=tenant-f ...
```

Each block is printed atomically after the process exits. The exit code must be 1 if any tenant failed, 0 only if all succeeded. The "re-run command" line should be machine-readable enough to paste directly into a shell.

**Warning signs:**
- Migration exits 1 with no output indicating WHICH tenant failed.
- Operator has to query each tenant DB to determine migration status.
- No `--tenant=` filter re-run hint in output.

**Phase to address:** Phase 33 (ISOL-07). The structured summary output test (assert presence of failed-tenant list and re-run hint) is a quality gate.

---

## General Lifecycle and Integration Pitfalls

### Pitfall 20: New kernel.request listener breaks the existing orchestrator priority-20 ordering

**What goes wrong:**
OPS-01 and OPS-02 each introduce new `kernel.request` listeners. If any of these is accidentally registered at priority > 20, it fires before the orchestrator. `TenantContext` is empty. The listener cannot read the current tenant, falls back to a no-op or throws.

If registered at priority < 8 (after Symfony's security firewall), authentication has already run — the security firewall may have rejected the request, but the maintenance mode listener still fires (harmless, but wasteful and potentially confusing in logs).

**How to avoid:**
Document the canonical priority ladder for this bundle's listeners:
- Priority > 20: FORBIDDEN for any new OPS listener (fires before orchestrator).
- Priority = 20: orchestrator (`TenantContextOrchestrator::PRIORITY`).
- Priority 15: maintenance mode listener (after orchestrator, before security).
- Priority 8: Symfony security firewall.
- Priority < 8: all other OPS listeners (health is NOT a kernel.request listener — it is a controller).

All new `kernel.request` listeners MUST have their priority documented and tested. A `KernelListenerPriorityPass` should assert no tenancy listener (except the orchestrator) is registered at priority >= 20.

**Warning signs:**
- New listener registers itself with `priority: 25` or `priority: 30`.
- Maintenance check fails to block tenant routes because `$tenantContext->getTenant()` is null inside the listener.

**Phase to address:** Phase 31, 32. Priority-ladder assertion test is a quality gate for both phases.

---

### Pitfall 21: OPS-01/OPS-02 features force Doctrine or a health library on consumers — breaks optional-dependency contract

**What goes wrong:**
The maintenance mode implementation uses `$em->find(Tenant::class, $id)` directly (hard-importing `EntityManagerInterface`). The health check uses `monolog/monolog` or a specific `HealthBundle` package not listed as optional. Consumers who don't use Doctrine or don't have that health library installed get a fatal error at container compile time, even if they don't use OPS-01/OPS-02.

This violates the bundle's core contract: "Doctrine ORM/DBAL is an optional dependency — always guard with `class_exists`/`interface_exists`, never hard-import."

**Why it happens:**
The existing pattern is established for Doctrine (see source: `DoctrineBootstrapper::__construct` takes `?EntityManagerInterface $em` and null-guards all usage). New phases under time pressure sometimes forget to apply the same pattern to new dependencies.

**How to avoid:**
For every new service introduced in OPS-01 and OPS-02:
1. External library dependencies (MonitorBundle, health check lib) must be guarded with `interface_exists` or `class_exists` and registered conditionally in the DI config.
2. If the feature requires Doctrine, use the existing nullable constructor injection pattern (`?EntityManagerInterface $em`).
3. `TenantInterface` must not gain new required methods — use a separate `MaintenanceTenantInterface` or configure the flag via a dedicated DB column (see Pitfall 22).

The CI no-doctrine lane (introduced in Phase 28) must remain green after every OPS phase. This is the canary that catches optional-dependency regressions.

**Warning signs:**
- No-doctrine CI lane fails after OPS-01 or OPS-02 ships.
- `class_exists` / `interface_exists` is not present in newly introduced bootstrapper or listener constructors.
- `composer require danplaton4/tenancy-bundle` on a project without `doctrine/orm` now triggers a fatal error.

**Phase to address:** Phase 31, 32, 33. The no-doctrine CI lane passing is a quality gate for every phase.

---

### Pitfall 22: `TenantInterface` BC break — new method required for maintenance or health

**What goes wrong:**
OPS-01 needs per-tenant maintenance state. The simplest implementation adds `isInMaintenance(): bool` to `TenantInterface`. Every user who has a custom `Tenant` entity implementing `TenantInterface` now gets a PHP fatal error (`Class X does not implement interface method`). This is a BC break on a published package.

`TenantInterface` currently has 7 methods (see source: `TenantInterface.php`). Adding an 8th is a BC break for every consumer.

**Why it happens:**
Interface extension is the natural OOP solution for "add a capability to a shared type." The BC cost is easy to forget when you're implementing and can test against your own fixtures.

**How to avoid:**
Three approaches, in order of preference:

1. **Config-driven, no interface change.** Store the maintenance flag in a dedicated DB table/column that is NOT part of `TenantInterface`. The `MaintenanceModeRepository` (landlord-side) looks up the flag by tenant slug. Zero BC impact.

2. **Optional interface.** Introduce `MaintenanceTenantInterface extends TenantInterface` with `isInMaintenance(): bool`. Only tenants that implement this interface get per-tenant maintenance checks. Tenants without it are treated as "never in maintenance." The `MaintenanceModeChecker` does an `instanceof` check.

3. **Trait with default implementation.** Introduce `TenantMaintenanceTrait` that provides a default `isInMaintenance(): bool { return false; }` that consumers opt into. Similar to the existing `TenantMailerConfigTrait` pattern (noted in PROJECT.md).

Never add a required method to `TenantInterface` without a major version bump.

**Warning signs:**
- `TenantInterface` diff in the PR adds a new method without a default implementation in a trait.
- `bin/console debug:container` on a user project with custom `Tenant` entity fails with "does not implement method."
- No UPGRADE.md entry for the change.

**Phase to address:** Phase 31 (OPS-01). Interface design is a pre-plan decision — determine storage approach before writing any code. The "existing custom Tenant entity still works" test is a quality gate.

---

## Technical Debt Patterns

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|----------|-------------------|----------------|-----------------|
| Single `/health` endpoint for both liveness and readiness | One route to document | Restart loops: one tenant DB down → liveness fails → all traffic killed | **Never** — two endpoints, two semantics |
| Maintenance flag in shared cache pool without tenant-namespace | Fast flag writes | Cross-tenant flag contamination (zero-leak violation) | **Never** |
| Adding `isInMaintenance()` to `TenantInterface` directly | Clean OOP design | BC break for every consumer with a custom Tenant entity | **Never without major version bump** |
| Unbounded parallelism in migration (spawn all at once) | Maximum parallelism | DB connection exhaustion at 100+ tenants | **Never** — `--max-parallel` is required |
| Streaming subprocess output directly (no buffering) | Real-time feedback | Interleaved/garbled output from concurrent subprocesses | **Never for parallel mode** |
| `getExitCode() ?? 0` in aggregation command | One line | Silent migration failures reported as success | **Never** — `null` must be treated as failure |
| Health endpoint returns raw exception messages (DSN with creds) | Easier debugging | Credentials exposed on unauthenticated URL | **Never** |
| Static property for maintenance flag (fast, no DB read) | Zero latency | Leaks across requests in async runtimes (FrankenPHP/Swoole) | **Never in long-running process runtime** |
| Skip SIGTERM forwarding to child migration processes | Simpler code | Orphaned processes, DB lock collisions on re-run | **Only on single-tenant or development usage** |
| Maintenance check as a controller-level guard | Familiar MVC pattern | Work runs before gate fires (queries, expensive operations) | **Never as the primary gate** |

---

## Integration Gotchas

| Integration | Common Mistake | Correct Approach |
|-------------|----------------|------------------|
| Symfony `kernel.request` priority ladder | New OPS listener at priority > 20 (fires before orchestrator) | All OPS listeners at priority < 20; orchestrator at exactly 20 |
| Maintenance mode + health endpoint | Health route resolves a tenant, triggers bootstrapper, may 503 | Health route must resolve null (all resolvers return null for health URLs) |
| `symfony/process` parallel + large output | `getOutput()` post-exit on large subprocess output (deadlock) | Streaming callback (`$process->start(fn...)`) accumulating to local var + `wait()` |
| `symfony/process` + SIGTERM | Parent exits, children orphaned, DB locks held | `pcntl_signal(SIGTERM, ...)` handler stops all children before exit |
| Parallel migration + shared_db driver | New parallel command forgets to copy shared_db guard | Guard in shared base/trait; compile-time pass asserts shared_db implies no migrate commands |
| Maintenance mode + FrankenPHP/Swoole | Static flag property leaks across requests | Flag stored in DB, read per-request, cleared on `TenantContextCleared` |
| Health check + Doctrine optional dependency | `HealthController` hard-imports `EntityManagerInterface` | Guard with `interface_exists`; all health check services conditionally registered |
| Liveness probe + per-tenant DB check | Liveness iterates all tenant DBs on every poll | Liveness: landlord DB only. Readiness: sampled N tenants. Full check: CLI only |
| Maintenance bypass token + CDN logs | Bypass token in query parameter appears in CDN/server logs | Header only (`X-Maintenance-Bypass`); HMAC-signed, time-bounded, tenant-scoped |
| Health response + DSN sanitization | Exception message with `user:pass@host` in health JSON | `HealthResponseSanitizer` strips all DSN-shaped strings before response |

---

## Performance Traps

| Trap | Symptoms | Prevention | When It Breaks |
|------|----------|------------|----------------|
| Health probe iterates all tenants on every load-balancer poll | DB connection spike every 5s; `max_connections` exceeded | Liveness: no tenant iteration. Readiness: max N sampled tenants. | At 50+ tenants with 5s probe interval |
| Maintenance flag DB read on every request with no per-request cache | Extra query per request per tenant | Cache flag in `MaintenanceModeChecker` for request duration (cleared on `TenantContextCleared`) | At 1000+ requests/second |
| Parallel migration spawns all tenants simultaneously | Connection exhaustion during migration | `--max-parallel` option (default 4); bounded process pool | At 20+ tenants without the limit |
| Per-process bootstrapper chain in migration subprocess boots all bootstrappers | Migration subprocess boots Flysystem, Mailer, etc. not needed for DB migration | Migration subprocess should only boot `DatabaseSwitchBootstrapper`; other bootstrappers add latency and failure surface | At 100+ tenants with slow bootstrappers |
| Maintenance check reads Tenant entity from DB on every request (no cache) | Extra query per request under load | Cache result per-request in `MaintenanceModeChecker` service (short-lived, cleared on request end) | At 500+ requests/second |

---

## Security Mistakes

| Mistake | Risk | Prevention |
|---------|------|------------|
| Maintenance flag stored in shared cache without tenant namespace | Cross-tenant maintenance state contamination (zero-leak violation) | DB column or per-tenant namespaced cache; cross-tenant isolation test |
| Health endpoint returns DB DSN / tenant list unauthenticated | Infrastructure topology exposed to public | `HealthResponseSanitizer`; liveness returns `{"status":"ok/degraded"}` only; detailed readiness behind auth |
| Maintenance bypass token is a static string in config | Any client that learns the token bypasses maintenance for any tenant | HMAC-signed, tenant-scoped, time-bounded (5-minute window); `hash_equals` comparison |
| Health check endpoint resolves a tenant (bootstrapper runs, context may leak) | TenantContext leaked into next request (zero-leak violation) | Health route must return null from ALL resolvers; health checks must not call `BootstrapperChain::boot()` |
| Parallel migration shared_db guard missing in new command | N migration processes run on same physical DB simultaneously — corruption | Compile-time pass; shared_db + parallel migrate not both registerable |
| New OPS feature hard-imports Doctrine/Monolog without optional guard | All consumers must install optional dependencies | `interface_exists` / `class_exists` guards; no-doctrine CI lane is a release gate |
| Bypass header sent as query parameter | Token in server access logs, CDN logs, referrer headers | Header only, never query param; document this in ops docs |

---

## "Looks Done But Isn't" Checklist

### OPS-01 Maintenance Mode (Phase 31)
- [ ] **Listener timing:** maintenance listener is at priority 15 — **verify with a test asserting orchestrator runs first** (tenant resolved before maintenance checked)
- [ ] **Null-tenant pass-through:** health/landlord routes pass through even during tenant maintenance — **verify with smoke test: all tenants in maintenance → health endpoint returns 200**
- [ ] **Cross-tenant isolation:** setting tenant-A to maintenance does NOT affect tenant-B — **verify with explicit cross-tenant test**
- [ ] **HTTP correctness:** 503 with `Retry-After` and `Cache-Control: no-store` — **verify all three header invariants with `MaintenanceResponse` assertion test**
- [ ] **Bypass security:** HMAC token is tenant-scoped, time-bounded, constant-time compared — **verify with wrong-tenant and expired-token tests**
- [ ] **BC safety:** `TenantInterface` unchanged — **verify no new methods added; existing custom entity still compiles**
- [ ] **No-doctrine CI lane:** passes green after OPS-01 ships — **verify in CI matrix**

### OPS-02 Health Checks (Phase 32)
- [ ] **No TenantContext mutation:** health check does not call `BootstrapperChain::boot()` — **verify `TenantContext::hasTenant()` is false after health probe**
- [ ] **Two routes:** `/health/live` and `/health/ready` are separate routes with separate semantics — **verify liveness does not iterate tenants; readiness samples max N**
- [ ] **DSN sanitization:** health response body contains no `://` with credentials — **verify with `HealthResponseSanitizer` injection test**
- [ ] **Degraded bootstrapper reporting:** `HealthReportingBootstrapperInterface` correctly surfaces DEGRADED state — **verify `MailerBootstrapper` returns DEGRADED when SMTP unreachable, not silently OK**
- [ ] **Unauthenticated surface:** liveness is safe to expose publicly — **verify liveness response contains only `status` key, nothing else**
- [ ] **No-doctrine CI lane:** passes green after OPS-02 ships

### ISOL-07 Parallel Migration (Phase 33)
- [ ] **Bounded concurrency:** `--max-parallel` default enforced — **verify with mock process factory asserting at-most-N concurrent processes**
- [ ] **Output atomicity:** no interleaving in buffered output — **verify with concurrent output test**
- [ ] **Exit code aggregation:** null exit code treated as failure — **verify process killed mid-run reports as failure**
- [ ] **Shared-db guard:** parallel command not registered when `driver: shared_db` — **verify container has no parallel migrate command in shared-db mode**
- [ ] **SIGTERM handler:** children stopped on parent SIGTERM — **verify child processes do not linger after parent exit** (requires `pcntl`)
- [ ] **Large output:** 100KB subprocess output does not deadlock — **verify with a subprocess generating large stdout**
- [ ] **Failure summary:** exit 1 + list of failed tenants + re-run hint — **verify output contains failed-tenant slugs and `--tenant=` re-run snippet**

---

## Pitfall-to-Phase Mapping

| Pitfall | Prevention Phase | Verification |
|---------|------------------|--------------|
| Maintenance check fires before tenant resolution | Phase 31 (OPS-01) | Listener-priority test: orchestrator runs before maintenance listener |
| Maintenance check fires too late (post-controller) | Phase 31 (OPS-01) | Query-count assertion: 0 queries before 503 |
| Landlord/health/public routes locked out | Phase 31 (OPS-01) | Smoke test: all tenants in maintenance → health returns 200 |
| Maintenance flag cross-tenant leak | Phase 31 (OPS-01) | Cross-tenant isolation test (tenant-A in maintenance does not affect tenant-B) |
| Bad HTTP status / missing Retry-After | Phase 31 (OPS-01) | `MaintenanceResponse` header invariant test |
| Bypass token spoofable | Phase 31 (OPS-01) | Wrong-tenant + expired-window bypass tests |
| Cache staleness after maintenance ends | Phase 31 (OPS-01) | Docs + CDN-override warning; flush-cache command |
| Health endpoint bootstraps tenant and context leaks | Phase 32 (OPS-02) | `TenantContext::hasTenant() === false` post-health-probe |
| Thundering herd on health probe | Phase 32 (OPS-02) | Liveness: assert 0 calls to `findAll()` |
| Sensitive data on unauthenticated health route | Phase 32 (OPS-02) | `HealthResponseSanitizer` DSN-injection test |
| Readiness/liveness confusion causes restart loops | Phase 32 (OPS-02) | Two routes exist; Kubernetes probe YAML in docs |
| Silent degraded bootstrapper | Phase 32 (OPS-02) | `HealthReportingBootstrapperInterface` degraded-state test |
| Unbounded parallel connection exhaustion | Phase 33 (ISOL-07) | At-most-N concurrency mock test |
| Interleaved output | Phase 33 (ISOL-07) | Output-atomicity test |
| Exit code lost — false success | Phase 33 (ISOL-07) | Killed-process reported-as-failure test |
| Parallel on shared_db — double migration | Phase 33 (ISOL-07) | Container has no parallel migrate command in shared-db mode |
| Subprocess output deadlock | Phase 33 (ISOL-07) | 100KB output no-deadlock test |
| Orphaned child processes on SIGTERM | Phase 33 (ISOL-07) | pcntl SIGTERM handler; child-linger test |
| Partial failure with no actionable report | Phase 33 (ISOL-07) | Output contains failed-tenant list + re-run snippet |
| New listener breaks priority-20 ordering | Phase 31, 32 | `KernelListenerPriorityPass` assertion |
| Optional-dependency contract broken | Phase 31, 32, 33 | No-doctrine CI lane green after each phase |
| `TenantInterface` BC break | Phase 31 (OPS-01) | Existing `Tenant` fixture compiles unchanged after OPS-01 ships |

---

## Sources

- Codebase source readings (live, 2026-06-25): `TenantContextOrchestrator.php` (priority 20 constant, null-branch design, `onKernelTerminate` cleanup path), `BootstrapperChain.php` (reverse clear ordering, `boot()` is void), `TenantMigrateCommand.php` (sequential loop, shared_db guard at line 57, failure aggregation at lines 109-119), `TenantContext.php` (value holder, `clear()` semantics), `TenantInterface.php` (current 7-method surface), `TenantRunCommand.php` (`getExitCode() ?? 0` pattern at line 89), `ResolverChain.php` (null-resolution contract for public/health routes)
- `.planning/RETROSPECTIVE.md`: v0.2 lesson "compile-time guards over runtime assertions"; v0.4 lesson "three-way invariant for data-leak bug classes"; both directly inform the compile-time-pass recommendations throughout
- `.planning/PROJECT.md` Key Decisions: "Strict mode ON by default — a data leak is worse than a 500"; optional-dependency contract; `ResolverChain::resolve()` returns nullable; `TenantMailerConfigTrait` BC-mitigation precedent
- `.planning/research/PITFALLS.md` (v0.3 version): Pitfall 3 (profiler serialization/DSN leak), Pitfall 7 (DSN in exception), Pitfall 8 (transport cache leak) — v0.5 health-check pitfalls are analogues of these established patterns
- `symfony/process` documentation: buffered vs. streaming output, deadlock warning for large output, SIGTERM behavior, `start()` + callback + `wait()` as the safe concurrent pattern

---
*Pitfalls research for: Symfony multi-tenancy bundle v0.5 — OPS-01 maintenance mode, OPS-02 health checks, ISOL-07 parallel migrations*
*Researched: 2026-06-25*
