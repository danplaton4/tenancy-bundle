# Maintenance Mode

Per-tenant maintenance mode lets you take one tenant offline for maintenance while all other tenants
continue serving traffic. A tenant in maintenance mode returns HTTP 503 with a `Retry-After` header
and a `Cache-Control: no-store, no-cache, must-revalidate` header, so CDNs do not cache the response
and clients know when to retry. An allow-list lets ops engineers and health probes bypass the 503
entirely.

This page covers how the listener works, how to configure the allow-list (IPs, routes, paths), the
three management commands, the CDN and cache-invalidation timing notes, the cross-dependency with
health checks, a deploy-time runbook, and the `isInMaintenance()` BC break introduced in v0.5.

---

## How it works

`TenantMaintenanceModeListener` listens on `kernel.request` at **priority 16**, which fires AFTER
`TenantContextOrchestrator` at priority 20. By the time the maintenance listener runs, the tenant
has already been resolved and is available in `TenantContext`. If `$tenant->isInMaintenance()` returns
`true` and the current request is not in the allow-list, the listener short-circuits the request and
returns a 503 response:

- **Status:** `503 Service Unavailable`
- **Headers:** `Retry-After: {retry_after_seconds}` (default 3600) and
  `Cache-Control: no-store, no-cache, must-revalidate`
- **Body:** Content-negotiated — JSON `{"status":"maintenance","retryAfter":N}` if the request sends
  `Accept: application/json`; otherwise HTML (the bundle's built-in template or your custom Twig
  template if configured)

The `isInMaintenance()` method is defined on `TenantInterface` and backed by a DB column when using
`TenantMaintenanceConfigTrait` (see [BC break note below](#bc-break-isInMaintenance)).

!!! note "Listener priority ordering"
    Priority 16 fires AFTER priority 20. Symfony dispatches higher numbers first — `TenantContextOrchestrator`
    at 20 runs first, then the maintenance listener at 16. The maintenance check always has a fully
    resolved tenant available.

---

## Configuration

=== "YAML"

    ```yaml
    # config/packages/tenancy.yaml
    tenancy:
        maintenance:
            retry_after: 3600       # seconds; sent as Retry-After header (default: 3600)
            allow_ips: []           # IP addresses or CIDR ranges that bypass 503
            allow_routes: []        # exact Symfony _route names that bypass 503
            allow_paths: []         # URL path prefixes that bypass 503 (str_starts_with)
    ```

=== "PHP"

    ```php
    // config/packages/tenancy.php
    return static function (Tenancy\Bundle\TenancyBundle $tenancy): void {
        $tenancy->maintenance()
            ->retryAfter(3600)
            ->allowIps(['203.0.113.0/24'])
            ->allowRoutes(['tenancy_health_live', 'tenancy_health_ready'])
            ->allowPaths(['/_tenancy/health']);
    };
    ```

### Allow-list bypass

The allow-list performs three OR'd checks. If any one passes, the request is served normally (no 503):

| Key | Type | Match logic |
|-----|------|-------------|
| `allow_ips` | List of IP addresses or CIDR ranges | `Symfony\Component\HttpFoundation\IpUtils::checkIp()` — handles IPv4, IPv6, CIDR notation |
| `allow_routes` | List of Symfony route names | Exact match against `$request->attributes->get('_route')` |
| `allow_paths` | List of URL path prefixes | `str_starts_with($pathInfo, $entry)` — all paths starting with the prefix bypass 503 |

!!! note "Health probes require an allow_paths entry"
    The `/_tenancy/health` endpoint prefix **must** be in `allow_paths` so Kubernetes liveness and
    readiness probes are never blocked by maintenance mode. Without this entry, a probe hitting a
    tenant in maintenance returns 503, and Kubernetes will remove the pod from rotation.

    ```yaml
    tenancy:
        maintenance:
            allow_paths:
                - '/_tenancy/health'
    ```

    See [Health Checks](health-checks.md) for the full probe configuration.

---

## Commands

Three commands manage per-tenant maintenance state:

### `tenancy:maintenance:enable`

```bash
# Put tenant 'acme' into maintenance mode
bin/console tenancy:maintenance:enable acme

# Idempotent — running enable on an already-in-maintenance tenant exits 0 with an informational message
bin/console tenancy:maintenance:enable acme
```

Sets `isInMaintenance()` to `true` in the DB. The cache key `tenancy.tenant.acme` is deleted
immediately so the change takes effect on the **next request** (see cache-invalidation note below).

### `tenancy:maintenance:disable`

```bash
# Bring tenant 'acme' back online
bin/console tenancy:maintenance:disable acme

# Idempotent — running disable on a live tenant exits 0
bin/console tenancy:maintenance:disable acme
```

Sets `isInMaintenance()` to `false` and deletes the cache key. The tenant is live again on the next
request.

### `tenancy:maintenance:status`

```bash
# List all tenants currently in maintenance mode (text output)
bin/console tenancy:maintenance:status

# JSON output for scripting
bin/console tenancy:maintenance:status --format=json
```

Reports all tenants where `isInMaintenance()` is `true`. The JSON format returns:

```json
{
  "tenants_in_maintenance": ["acme", "demo"],
  "count": 2
}
```

---

## CDN and proxy caching

!!! warning "CDN / proxy caching"
    CDNs and reverse proxies must not cache the 503 maintenance response. The listener sets
    `Cache-Control: no-store, no-cache, must-revalidate` on every 503, but some CDN configurations
    apply their own TTL-based caching for 5xx responses regardless of origin headers.

    If a CDN caches a maintenance 503, every client hitting that CDN edge will continue to receive
    503 responses even after you run `tenancy:maintenance:disable` and the tenant is live again —
    effectively pinning the tenant offline until the CDN TTL expires.

    **Verify that your CDN configuration:**
    - Passes `Cache-Control` from origin unchanged
    - Does not apply a default TTL to 5xx responses

    This is the same concern as health probe 5xx caching — see [Health Checks](health-checks.md) for
    the CDN warning there.

---

## Cache invalidation timing

!!! note "State takes effect on the next request, not instantly"
    `DoctrineTenantProvider` caches resolved tenants in `cache.app` under the key
    `tenancy.tenant.<slug>` for approximately 300 seconds (5 minutes). The `enable` and `disable`
    commands **delete this cache key** immediately after writing to the DB. This means:

    - The maintenance state change is visible on the **next** incoming request — not after a TTL wait.
    - If for any reason the cache key is not deleted (e.g., the command crashes mid-run), the old
      state persists for up to 300 seconds.

    In practice, `tenancy:maintenance:enable acme` → `bin/console` exits 0 → next web request to
    `acme` sees maintenance mode. No wait required.

---

## Runbook: enabling maintenance during a deploy and operator bypass

**Scenario (D-02):** You need to perform a schema migration or data repair for tenant `acme` that
requires taking it offline. An ops engineer needs to verify the tenant during maintenance without
being blocked.

**Steps:**

1. **Add an operator IP to the allow-list** before enabling maintenance. Either set it in the config
   or pass it via an env var (if your config uses env references):

    ```yaml
    tenancy:
        maintenance:
            allow_ips:
                - '203.0.113.42'    # ops engineer's IP (placeholder)
            allow_paths:
                - '/_tenancy/health'
    ```

    Deploy this config change first, so the allow-list is live before you enable maintenance.

2. **Enable maintenance for the tenant:**

    ```bash
    bin/console tenancy:maintenance:enable acme
    ```

3. **Verify the tenant is in maintenance** (optional):

    ```bash
    bin/console tenancy:maintenance:status
    # or
    bin/console tenancy:maintenance:status --format=json
    ```

4. **Perform the maintenance work** (migrations, data repair, etc.). The ops engineer at
   `203.0.113.42` can access the tenant normally because their IP is in `allow_ips`.

5. **Run health checks** to confirm the tenant is healthy after the repair:

    ```bash
    bin/console tenancy:health --tenant=acme
    ```

6. **Disable maintenance** once complete:

    ```bash
    bin/console tenancy:maintenance:disable acme
    ```

7. **Remove the operator IP** from `allow_ips` once the maintenance window is closed (good practice
   — avoid leaving ops bypass entries permanently enabled).

!!! note "Health probes are unaffected"
    Kubernetes probes hitting `/_tenancy/health/ready/acme` are served normally during maintenance
    because `/_tenancy/health` is in `allow_paths`. The probe response reflects the actual tenant
    DB health, not the maintenance state.

---

## BC break: `isInMaintenance()` {#bc-break-isInMaintenance}

v0.5 adds `isInMaintenance(): bool` to `TenantInterface`. Any class implementing `TenantInterface`
must add this method. Two migration paths are available:

### Migration path A: use `TenantMaintenanceConfigTrait` (recommended)

Add the trait to your tenant entity. It provides the `isInMaintenance()` method (returning `false`
by default), the `bool $inMaintenance` property, and the `in_maintenance` Doctrine column:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Tenancy\Bundle\Entity\AbstractTenant;
use Tenancy\Bundle\Maintenance\TenantMaintenanceConfigTrait;

#[ORM\Entity]
#[ORM\Table(name: 'tenancy_tenants')]
class AppTenant extends AbstractTenant
{
    use TenantMaintenanceConfigTrait;
}
```

After adding the trait, run `bin/console doctrine:migrations:diff` to generate the migration adding
the `in_maintenance` column, then apply it with `bin/console doctrine:migrations:migrate`.

### Migration path B: manual implementation

If you prefer a custom column name or do not use Doctrine:

```php
public function isInMaintenance(): bool
{
    return false; // or: return $this->inMaintenance;
}
```

Returning `false` permanently is safe — the tenant will never enter maintenance mode via the commands,
but the interface contract is satisfied.

!!! note "No action if you don't use maintenance mode"
    `TenantMaintenanceConfigTrait` returns `false` by default. Any class that adds the trait or returns
    `false` manually is fully compatible with v0.5 — maintenance mode simply has no effect for that
    tenant.

For full migration instructions including the `ALTER TABLE` snippet, see
[UPGRADE.md — 0.4 to 0.5](https://github.com/danplaton4/tenancy-bundle/blob/master/UPGRADE.md#04-to-05).

---

## See Also

- [Health Checks](health-checks.md) — health endpoint allow_paths cross-dependency and k8s probes
- [Parallel Migrations](parallel-migrations.md) — running migrations during a deploy
- [CLI Commands](../user-guide/cli-commands.md) — full CLI reference
- [UPGRADE.md — 0.4 to 0.5](https://github.com/danplaton4/tenancy-bundle/blob/master/UPGRADE.md#04-to-05) — `isInMaintenance()` BC break migration
