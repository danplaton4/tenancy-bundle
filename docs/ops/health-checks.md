# Health Checks

Per-tenant health checks give Kubernetes (and your dashboard) a live signal on tenant database
connectivity. The bundle exposes three HTTP endpoints — a zero-I/O liveness probe, a per-tenant
readiness probe that does a real DB round-trip, and a fleet dashboard endpoint — plus a CLI command
for operator use. Routes are opt-in: no endpoints exist until you import the provided route files.

This page covers how the health checker works, the opt-in route setup, the endpoint contract,
Kubernetes liveness and readiness probe YAML with probe interval justification, the CDN 5xx-caching
warning, the CLI command, the fleet dashboard endpoint, and optional LiipMonitorBundle integration.

---

## How it works

The health checker sets `TenantContext` manually (without calling `BootstrapperChain::boot()`),
runs a read-only probe against the tenant's database connection, then clears the context in a
`finally` block — no service state is mutated globally and no side effects leak across tenants.

DSN credentials and connection details are redacted from all response bodies by `HealthResponseSanitizer`
before any data reaches the wire. This sanitizer delegates to the same `DsnSanitizer` regex used
elsewhere in the bundle, so redaction is consistent across the codebase.

!!! note "Health probes never call `boot()`"
    `BootstrapperChain::boot()` is deliberately NOT called during health checks. It is reserved for
    the request path where a full tenant context (cache namespace, Doctrine filter, etc.) is needed.
    The health probe only needs to verify DB reachability — it should not trigger bootstrapper side
    effects or mutate global service state.

---

## Enabling health endpoints (opt-in)

No health endpoints exist until you import the route files into your application:

```php
// config/routes/health.php — imports liveness + readiness endpoints
<?php

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import('@TenancyBundle/config/routes/health.php');
};
```

```php
// config/routes/health_fleet.php — imports the fleet aggregate endpoint (optional)
<?php

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import('@TenancyBundle/config/routes/health_fleet.php');
};
```

There is no `tenancy.health.enabled` config flag. **Route import is the opt-in mechanism.** Import
only `config/routes/health.php` if you want liveness and readiness probes without the fleet endpoint.
Import `config/routes/health_fleet.php` additionally if you need the dashboard.

!!! tip "Maintenance mode allow_paths"
    After enabling health endpoints, add `/_tenancy/health` to your maintenance allow-list so probes
    are never blocked when a tenant is in maintenance mode:

    ```yaml
    tenancy:
        maintenance:
            allow_paths:
                - '/_tenancy/health'
    ```

    See [Maintenance Mode — allow_paths](maintenance-mode.md#configuration) for the full allow-list
    configuration.

---

## Endpoint reference

| Endpoint | Method | Status codes | Response type | Notes |
|----------|--------|--------------|---------------|-------|
| `/_tenancy/health/live` | GET | 200 | `application/health+json` | Zero-I/O — no tenant iteration, no DB query |
| `/_tenancy/health/ready/{slug}` | GET | 200 / 503 / 404 | `application/health+json` | 404 = unknown slug; 503 = known-but-unhealthy OR known-but-inactive |
| `/_tenancy/health` (fleet) | GET | 200 | `application/health+json` | Paginated aggregate — dashboard only, NOT a k8s probe target |

**Liveness (`/_tenancy/health/live`):**

```json
{"status": "ok"}
```

Always 200 as long as PHP and the Symfony kernel are alive. No database query, no tenant resolution.
Completes in under 1ms.

**Readiness (`/_tenancy/health/ready/{slug}`):**

```json
{"status": "pass", "slug": "acme", "durationMs": 12}
```

On 503 (known-but-unhealthy or inactive):

```json
{"status": "fail", "slug": "acme", "error": "REDACTED"}
```

DSN credentials are always redacted by `HealthResponseSanitizer` before the wire — never show raw
connection strings in expected output.

---

## Kubernetes integration

!!! warning "Do not add application-level auth to probe endpoints"
    Adding HTTP basic auth, JWT validation, or any other authentication middleware to the
    `/_tenancy/health/*` paths will cause k8s probes to fail (they do not send auth headers).
    **Protect probe endpoints with network ACLs** at the infrastructure layer (firewall rules,
    network policies) instead of application-level auth.

The liveness and readiness probes have distinct polling rates because their probes are fundamentally
different in cost:

- **Liveness** hits `/_tenancy/health/live` — zero-I/O, completes in under 1ms. Aggressive polling
  is safe; this probe only tests whether the PHP process is responsive.
- **Readiness** hits `/_tenancy/health/ready/{slug}` — performs a real DB `SELECT 1` round-trip
  (~5-50ms typical). On a large fleet, polling every pod at the same interval as liveness would
  hammer the database connection pool. A 30-second interval keeps steady-state DB probe traffic
  proportional to fleet size.

```yaml
containers:
  - name: app
    image: your-app:latest
    ports:
      - containerPort: 80

    # Liveness probe — zero-I/O, safe to poll frequently
    livenessProbe:
      httpGet:
        path: /_tenancy/health/live
        port: 80
      initialDelaySeconds: 10
      periodSeconds: 10
      failureThreshold: 3

    # Readiness probe — real DB SELECT 1, poll conservatively
    readinessProbe:
      httpGet:
        path: /_tenancy/health/ready/my-tenant
        port: 80
      initialDelaySeconds: 15
      periodSeconds: 30
      failureThreshold: 2
```

**Probe interval rationale:**

| Probe | `periodSeconds` | `failureThreshold` | Kill/remove after |
|-------|----------------|-------------------|-------------------|
| Liveness | 10s | 3 | 30s of consecutive failures — pod restarted |
| Readiness | 30s | 2 | 60s of consecutive failures — pod removed from rotation |

Readiness uses `failureThreshold: 2` (60s) rather than 3 (90s) because removing a pod from
rotation on two consecutive DB failures is safer than waiting 90s — a genuinely unhealthy tenant
should stop receiving traffic quickly. Tune these values for your fleet's SLOs.

!!! note "One readiness probe per tenant slug"
    In a multi-tenant deployment you typically configure the readiness probe for your primary or
    representative tenant. You can also run a dedicated sidecar or CronJob using the CLI
    `tenancy:health --all` command to monitor the full fleet separately from the k8s readiness probe.

---

## CDN and proxy caching

!!! warning "CDN / proxy caching"
    Health probe 503 responses (and maintenance 503s) must not be cached by a CDN or reverse proxy.
    The health endpoints set `Cache-Control: no-store` on all responses. Ensure your CDN is configured
    to pass `Cache-Control` from origin unchanged and does not apply TTL-based caching to 5xx responses.

    A cached 503 would leave a tenant permanently "down" in the CDN's cache even after the underlying
    condition clears. The same applies to maintenance-mode 503s — see
    [Maintenance Mode — CDN and proxy caching](maintenance-mode.md#cdn-and-proxy-caching).

---

## CLI: tenancy:health

The `tenancy:health` command streams per-tenant health status to the console. It is intended for
operator inspection and CI, not for use as a k8s probe target.

```bash
# Check health for a single tenant
bin/console tenancy:health --tenant=acme

# Check health for all tenants (unbounded — use for operator action, not automated probes)
bin/console tenancy:health --all

# JSON output for scripting
bin/console tenancy:health --all --format=json
```

The command exits non-zero if any tenant health check fails. `--all` is deliberately unbounded —
it checks every tenant, which may be slow on large fleets. Use it as a deliberate operator action,
not a scheduled job.

**JSON output shape per tenant:**

```json
{
  "tenants": [
    {"slug": "acme", "status": "pass", "durationMs": 8},
    {"slug": "broken-tenant", "status": "fail", "error": "REDACTED"}
  ],
  "summary": {"pass": 1, "fail": 1, "total": 2}
}
```

---

## Fleet dashboard

The fleet endpoint `/_tenancy/health` returns a paginated aggregate of all tenant health statuses.
It is designed for ops dashboards, not k8s probes:

```
GET /_tenancy/health?limit=50&offset=0
```

Default `limit` is 50; hard maximum is approximately 200. Response shape:

```json
{
  "total": 120,
  "offset": 0,
  "limit": 50,
  "summary": {"pass": 48, "warn": 1, "fail": 1},
  "tenants": [
    {"slug": "acme", "status": "pass"},
    {"slug": "demo", "status": "warn", "notes": "REDACTED"}
  ]
}
```

!!! warning "Fleet endpoint is NOT a k8s probe target"
    `/_tenancy/health` (no slug) queries all tenants sequentially and may take several seconds on
    large fleets. Do not use it as a Kubernetes liveness or readiness probe. Use
    `/_tenancy/health/live` (liveness) and `/_tenancy/health/ready/{slug}` (readiness) instead.

---

## LiipMonitorBundle integration (optional)

When `liip/monitor-bundle` is installed alongside this bundle, the tenant health checks
automatically register as `liip_monitor.check` services via a `class_exists`-guarded compiler
pass (`HealthCheckIntegrationPass`). This makes them available in the LiipMonitor web UI and CLI
without any additional configuration.

If `liip/monitor-bundle` is not installed, the health endpoints work exactly as documented above —
no features are degraded, no errors are thrown. The integration is purely additive.

To install the optional integration:

```bash
composer require liip/monitor-bundle --no-update
composer update
```

No additional service configuration is required. The compiler pass detects the bundle at container
compile time.

---

## Runbook: triaging a red readiness probe

**Scenario (D-02):** A Kubernetes pod is failing its readiness probe on `/_tenancy/health/ready/acme`.

1. **Check the probe response directly:**

    ```bash
    curl -i https://your-app.example.com/_tenancy/health/ready/acme
    ```

    - HTTP 200 = tenant is healthy (probe likely just flapping — check `failureThreshold`)
    - HTTP 503 = tenant is unhealthy or in maintenance; `status` is `fail` or `warn`
    - HTTP 404 = slug `acme` is unknown — verify the slug is correct

2. **Use the CLI for more detail:**

    ```bash
    bin/console tenancy:health --tenant=acme
    ```

    The CLI shows the full error message (with credentials redacted).

3. **Check if the tenant is in maintenance mode:**

    ```bash
    bin/console tenancy:maintenance:status
    ```

    If `acme` is listed, the readiness probe is returning 503 because the tenant is in maintenance.
    Either disable maintenance or ensure `/_tenancy/health` is in `allow_paths` (see above).

4. **Check DB connectivity** for the tenant separately. The health probe error is redacted in HTTP
   responses; use the CLI or application logs (which may have less redaction) to see the raw error.

5. **If the DB is healthy but probes are still failing**, check that `/_tenancy/health` is in the
   maintenance allow-list. A tenant in maintenance with no `allow_paths` entry will return 503 to
   every request including probes.

---

## See Also

- [Maintenance Mode](maintenance-mode.md) — per-tenant 503 + allow_paths cross-dependency
- [Parallel Migrations](parallel-migrations.md) — running migrations safely during a deploy
- [CLI Commands](../user-guide/cli-commands.md) — full CLI reference
