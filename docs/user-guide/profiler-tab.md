# Profiler tab

A **"Tenancy" tab** appears in the Symfony Profiler and Web Debug Toolbar (WDT) when your app runs in dev mode. It shows the active tenant context for the current request — slug, label, driver, connection, resolver, bootstrappers, and error state if tenant resolution failed.

This page answers two questions: **do you have to do anything to get it?** And **what will you see when it works?**

---

## Do I have to do anything?

**Probably not.** If your app uses the standard Symfony skeleton (most do), `symfony/web-profiler-bundle` is already in `require-dev` and the panel will appear automatically the next time you load a page in dev. Zero config, zero wiring.

The bundle registers the data collector **only when `kernel.debug = true`**. Production containers never see profiler code — it's stripped at compile time. There is nothing to disable for prod.

### When you DO need to install something

| If your app... | Run this |
|---|---|
| Was generated from `symfony/skeleton` (web app) | Nothing — `web-profiler-bundle` is already installed |
| Was generated from `symfony/skeleton --webapp` | Nothing |
| Was generated from `symfony/skeleton` with the **api** profile, or any minimal/microservice setup | `composer require --dev symfony/web-profiler-bundle` |
| Doesn't use Symfony Flex | Manually install both bundles and register them in `config/bundles.php` (see below) |

That's it. No tenancy config changes. No service overrides.

### Manual setup (no Flex)

If `symfony/flex` isn't in your `require`, add the bundles yourself:

```bash
composer require --dev symfony/web-profiler-bundle symfony/twig-bundle
```

```php
// config/bundles.php
return [
    // ... your existing bundles ...
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class => ['dev' => true, 'test' => true],
];
```

```yaml
# config/packages/web_profiler.yaml
when@dev:
    web_profiler:
        toolbar: true
    framework:
        profiler: { only_exceptions: false }
```

```yaml
# config/routes/web_profiler.yaml
when@dev:
    web_profiler_wdt:
        resource: '@WebProfilerBundle/Resources/config/routing/wdt.xml'
        prefix: /_wdt
    web_profiler_profiler:
        resource: '@WebProfilerBundle/Resources/config/routing/profiler.xml'
        prefix: /_profiler
```

---

## What will I see?

The panel has three render states — one per request — driven by what happened during tenant resolution. Want to see this live? The [SaaS demo](../../examples/saas/README.md) ships with the Profiler enabled — `docker compose up` and visit any tenant URL.

### Resolved state (green)

The most common state for tenant-scoped requests.

- **Toolbar badge:** the tenant slug (e.g. `acme`)
- **Status pill:** green, `resolved`
- **Panel sections:** tenant slug, tenant label, driver (`shared_db` or `database_per_tenant`), connection name (label only — never a DSN), resolver class basename (e.g. `HostResolver`), bootstrapper FQCN list with count, configuration settings

Example panel content:

```text
┌─ Tenancy ─────────────────────────────────────────────────────────┐
│  Slug          Tenant              Driver       Connection        │
│  acme          Acme Corporation    shared_db    tenant            │
│                                                                   │
│  Resolved by                                                      │
│    Tenancy\Bundle\Resolver\HostResolver                           │
│                                                                   │
│  Bootstrappers (2)                                                │
│    • Tenancy\Bundle\Bootstrapper\DoctrineBootstrapper             │
│    • Tenancy\Bundle\Bootstrapper\CacheBootstrapper                │
└───────────────────────────────────────────────────────────────────┘
```

### Null state (yellow)

Expected for public, landlord, and health-check routes — requests where no tenant was resolved and no tenant was needed.

- **Toolbar badge:** em-dash `—`
- **Status pill:** yellow, `null`
- **Panel:** "No tenant resolved for this request. This is the expected state for public, landlord, and health-check routes."

```text
┌─ Tenancy — no tenant ─────────────────────────────────────────────┐
│                                                                   │
│  No tenant resolved for this request.                             │
│  This is the expected state for public, landlord, and             │
│  health-check routes.                                             │
│                                                                   │
└───────────────────────────────────────────────────────────────────┘
```

### Error state (red)

Triggered when a `Tenancy\Bundle\Exception\*` exception is thrown during the request — typically `TenantInactiveException` (tenant exists but `isActive = false`) or `TenantMissingException` (a `#[TenantAware]` entity was queried with no tenant in strict mode).

- **Toolbar badge:** warning glyph `⚠`
- **Status pill:** red, `error`
- **Panel:** exception class FQCN + the exception message (HTML-escaped — XSS-safe)

```text
┌─ Tenancy ─────────────────────────────────────────────────────────┐
│                                                                   │
│  Resolution error                                                 │
│                                                                   │
│    Tenancy\Bundle\Exception\TenantInactiveException               │
│                                                                   │
│    Tenant "acme" is inactive.                                     │
│                                                                   │
└───────────────────────────────────────────────────────────────────┘
```

> **Note on "tenant not found":** when a resolver returns a slug that doesn't exist (e.g. `X-Tenant-ID: ghost`), the bundle's resolvers **catch and swallow** the `TenantNotFoundException` internally and the request proceeds as the null state. This is a Phase 02-02 design decision so that mistyped headers don't 500 the request before your application can decide how to respond. The error state is specifically for tenancy exceptions that escape resolution and reach `kernel.exception` — like inactive tenants or strict-mode filter violations.

---

## Privacy and safety

- **No DSN leakage.** The panel shows the *connection name* (e.g. `tenant`, `landlord`) — never the raw DSN. A runtime tripwire in `TenantDataCollector::collect()` throws `RuntimeException` if a value containing `:` or `@` ever reaches the connection-name slot, preventing accidental credential disclosure even under misconfiguration.
- **No object capture.** Stored profile data is scalar-only (strings, nulls, `string[]`, and a `{class, message}` array for errors). Serialized profile dumps round-trip cleanly with no `Closure`, mock, or stash references in the blob.
- **Exception namespace scoping.** The data collector only records exceptions in the `Tenancy\Bundle\Exception\*` namespace. Unrelated errors (DB connection failures, controller bugs, etc.) leave the panel in the null state — they aren't tenant-context issues and shouldn't pollute the tenancy panel.

---

## Troubleshooting

**The tab doesn't appear in the toolbar.**
Check three things in order:
1. `bin/console debug:container --env=dev | grep TenantDataCollector` — the service should be listed. If not, the bundle isn't loading `config/services_dev.php`. Confirm `kernel.debug` is `true` in your dev env.
2. `bin/console debug:router --env=dev | grep _profiler` — you should see `_profiler_home` and `_wdt`. If not, `symfony/web-profiler-bundle` isn't installed or isn't registered in `bundles.php` under the `dev` env.
3. Hit your app once; the WDT shows up at the bottom of every HTML response (and at `/_wdt/{token}` for JSON responses).

**The tab appears but says "null" on every request.**
Your tenant isn't being resolved. This is expected for public routes — only routes that go through a resolver should show the resolved state. If a route you expect to resolve is showing null, check your `tenancy.yaml` resolvers list and your request (Host header, `X-Tenant-ID` header, etc.).

**The toolbar template fails with "Unable to find template @Tenancy/Collector/tenant.html.twig".**
The `@Tenancy` Twig namespace is registered automatically by the bundle's `prependExtension()` hook. If you see this error, your bundle install may be incomplete — try `composer reinstall danplaton4/tenancy-bundle` and clear caches.

**I want the collector available in `test` env too (for HTTP integration tests).**
Update `config/bundles.php`:

```php
Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class => ['dev' => true, 'test' => true],
```

The bundle's `kernel.debug` guard already covers `test` env (where `debug=true` by default in `bin/phpunit`), so no further changes are needed.

---

## Internals (for the curious)

The panel is implemented as a Symfony `AbstractDataCollector`. Each request:

1. `TenantProfilerStash` — a per-request `final class` event subscriber — listens on `TenantResolved`, `TenantBootstrapped`, `TenantContextCleared`, and `ExceptionEvent`. It captures the resolver class, bootstrapper FQCN list, and any tenancy-namespaced exception in three scalar fields.
2. On `kernel.response`, `TenantDataCollector::collect()` reads `TenantContext` and the stash, and produces a locked 8-key scalar-only `$this->data` array.
3. Twig templates under `src/Resources/views/Collector/` render the toolbar, menu, and panel blocks from `$data`.
4. The collector implements `ResetInterface` for long-running runtimes (FrankenPHP, Swoole, RoadRunner) — state clears between requests automatically.

The whole thing is gated on `kernel.debug = true` via a single check in `TenancyBundle::loadExtension()`; the dev-only DI lives in `config/services_dev.php` which production containers never load. The bundle ships a static `SourceLayoutTest` that asserts `config/services.php` never references any profiler class — the compile-out invariant is enforced statically, not just at runtime.
