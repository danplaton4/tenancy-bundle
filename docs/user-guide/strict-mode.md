# Strict Mode

Strict mode is the bundle's primary safety net against accidental cross-tenant data leaks. It is **on by default**.

---

## What Strict Mode Does

When `strict_mode: true` (the default), querying a `#[TenantAware]` entity without an active tenant in context throws `TenantMissingException` instead of silently returning all rows from all tenants.

```
Tenancy\Bundle\Exception\TenantMissingException:
  No active tenant in context. Cannot query TenantAware entity 'App\Entity\Invoice' in strict mode.
```

This applies to the **shared-DB driver only** — in `database_per_tenant` mode, the connection itself is scoped to one tenant's database, so cross-tenant queries are impossible at the DBAL level.

---

## Why It Defaults to ON

!!! danger "A data leak across tenants is a security incident, not a config mistake"
    In a multi-tenant system, returning all rows when no tenant is active does not produce a "neutral" result — it returns data from **every tenant in your system** to a potentially unauthenticated or wrong-tenant request.

    Strict mode turns this silent failure into an explicit exception. You see the problem immediately during development and it cannot silently reach production.

---

## How It Works Technically

The flow is:

1. `SharedDriver::boot()` is called when a tenant is resolved
2. It calls `TenantAwareFilter::setTenantContext($context, $strictMode)`
3. On every Doctrine query for a `#[TenantAware]` entity, `TenantAwareFilter::addFilterConstraint()` is invoked
4. The filter checks `TenantContext::getTenant()`:
   - **Tenant active** → appends `WHERE alias.tenant_id = '<slug>'` — query is correctly scoped
   - **No tenant, strict mode ON** → throws `TenantMissingException`
   - **No tenant, strict mode OFF** → returns empty string — no WHERE clause appended, all rows returned

For non-`#[TenantAware]` entities, the filter always returns an empty string (no scoping) regardless of strict mode.

---

## Console Commands and Strict Mode

Console commands that run without `--tenant=<slug>` start without a tenant context. In this case:

- If `TenantAwareFilter::setTenantContext()` was never called (no `SharedDriver::boot()` has run), the filter returns an empty string — the safety net that prevents the filter from interfering with setup commands like `doctrine:schema:create`.
- If `boot()` was called (e.g. via `--tenant` on a previous command in the same process), and strict mode is on, queries against `#[TenantAware]` entities will throw until `clear()` is called.

This is by design: console commands that need cross-tenant access (data exports, migration runners) should explicitly disable strict mode in their configuration, not work around it.

---

## When to Disable

Strict mode is appropriate to disable for internal tooling that intentionally needs cross-tenant access:

!!! warning "Disabling strict mode exposes all-tenant data"
    With `strict_mode: false`, any query against a `#[TenantAware]` entity without an active tenant returns rows from **all tenants**. This is intentional only for:

    - Internal admin panels that aggregate cross-tenant data
    - Data export scripts with explicit cross-tenant intent
    - Migration runners operating on all tenant data sequentially
    - CLI commands that loop over all tenants

    Never disable strict mode for regular application code. If a controller needs to query across tenants, that is an architectural concern — not a config toggle.

---

## How to Disable

=== "YAML"

    ```yaml
    # config/packages/tenancy.yaml
    tenancy:
        driver: shared_db
        strict_mode: false
    ```

=== "PHP"

    ```php
    // config/packages/tenancy.php
    $container->extension('tenancy', [
        'driver'      => 'shared_db',
        'strict_mode' => false,
    ]);
    ```

---

## Scoped Disable (Environment-Specific)

A common pattern is to enable strict mode in production but disable it in a dedicated internal environment:

```yaml
# config/packages/tenancy.yaml
tenancy:
    driver: shared_db
    strict_mode: true

# config/packages/admin/tenancy.yaml  (loaded only in 'admin' environment)
tenancy:
    strict_mode: false
```

Or use an environment variable:

```yaml
tenancy:
    strict_mode: '%env(bool:TENANCY_STRICT_MODE)%'
```

With `TENANCY_STRICT_MODE=false` in your admin worker's environment.
