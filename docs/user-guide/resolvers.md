# Resolvers

Resolvers identify the current tenant from each request. The bundle ships with four resolvers and supports unlimited custom resolvers via the standard Symfony DI tag system.

---

## Overview

At `kernel.request` priority 20, `TenantContextOrchestrator` calls `ResolverChain::resolve()`. The chain iterates resolvers in priority order (highest first). The **first resolver to return a non-null `TenantInterface`** wins. If all resolvers return `null`, the request proceeds without a tenant context (no exception is thrown — routes that do not require a tenant work normally).

---

## Resolver Priority Table

| Resolver | Priority | Trigger | Config Key |
|----------|----------|---------|------------|
| `HostResolver` | 30 | Subdomain: `acme.example.com` | `tenancy.host.app_domain` |
| `HeaderResolver` | 20 | Header: `X-Tenant-ID: acme` | *(none)* |
| `QueryParamResolver` | 10 | Query param: `?_tenant=acme` | *(none)* |
| `ConsoleResolver` | N/A | CLI option: `--tenant=acme` | *(none)* |

`ConsoleResolver` does **not** participate in the HTTP resolver chain — it operates independently on the `ConsoleCommandEvent`.

---

## Exception Behavior

All HTTP resolvers share the same exception policy:

- **`TenantNotFoundException`** — caught internally; the resolver returns `null` and the chain tries the next resolver.
- **`TenantInactiveException`** — **not** caught; bubbles up as an unhandled exception (results in HTTP 403/500 depending on your error handler).

---

## HostResolver

**Priority: 30**

Extracts the tenant slug from the subdomain of the incoming request's `Host` header.

### Configuration

```yaml
tenancy:
    host:
        app_domain: example.com
```

With `app_domain: example.com`, the following subdomains resolve as:

| Host | Resolved Slug |
|------|--------------|
| `acme.example.com` | `acme` |
| `beta.example.com` | `beta` |
| `api.acme.example.com` | `acme` (last segment before suffix) |
| `www.acme.example.com` | `acme` (`www.` prefix stripped) |
| `example.com` | *(null — no subdomain)* |
| `other-domain.com` | *(null — suffix mismatch)* |

For multi-segment subdomains (e.g. `api.acme.example.com`), the resolver takes the **last segment** before the `app_domain` suffix. This means `api.acme.example.com` resolves to `acme`, not `api`.

### When app_domain is null

When `tenancy.host.app_domain` is `null` (the default), `HostResolver` always returns `null` and passes control to the next resolver. You must configure `app_domain` for subdomain resolution to work.

---

## HeaderResolver

**Priority: 20**

Reads the `X-Tenant-ID` request header and uses its value as the tenant slug.

### Usage

```http
GET /api/invoices HTTP/1.1
Host: api.myapp.com
X-Tenant-ID: acme
```

### Best For

API-first applications (mobile apps, SPAs, microservices) where subdomain routing is not available or not desired. The header is also useful for local development, where running multiple subdomains locally is inconvenient.

### Notes

- The header name is `X-Tenant-ID` (case-insensitive in HTTP, but Symfony normalises it).
- If the header is absent or empty, the resolver returns `null` — no exception is thrown.
- `TenantNotFoundException` is caught (resolver returns `null`); `TenantInactiveException` bubbles up.

---

## QueryParamResolver

**Priority: 10**

Reads the `_tenant` query parameter and uses its value as the tenant slug.

### Usage

```
GET /admin/preview?_tenant=acme
```

!!! warning "Use only for internal/debug tooling"
    Query parameter resolution exposes the tenant slug in the URL, which may appear in server logs, browser history, and third-party analytics. **Do not use this resolver in production API endpoints or user-facing routes.** Limit it to internal admin panels, developer debug views, and QA tooling.

### Disabling the Query Param Resolver

To disable this resolver in production, remove `query_param` from the `resolvers` list:

```yaml
tenancy:
    resolvers:
        - host
        - header
```

---

## ConsoleResolver

**Priority: N/A (not part of the HTTP chain)**

`ConsoleResolver` operates independently from the HTTP resolver chain. It listens on the `ConsoleCommandEvent` (dispatched before any console command runs) — **not** on `kernel.request`.

### How It Works

On every console command invocation, `ConsoleResolver`:

1. Adds a `--tenant=<slug>` option to the application's global definition (if not already present)
2. Rebinds the input against the updated definition so the option is parsed
3. If `--tenant` is provided and non-empty, loads the tenant and boots the full bootstrapper chain

The `--tenant` option is available on **every** console command automatically — no per-command configuration needed.

### Usage

```bash
# Run a command in the context of tenant "acme"
bin/console app:generate-report --tenant=acme

# Run database migrations for tenant "acme"
bin/console doctrine:migrations:migrate --tenant=acme

# Without --tenant: no tenant context, bootstrappers not booted
bin/console cache:clear
```

### Notes

- `ConsoleResolver` is **always registered** — it is not part of the `tenancy.resolvers` config array and cannot be removed by configuration.
- When `--tenant` is absent or empty, the resolver does nothing — the command runs without tenant context (bootstrappers are not booted).
- `TenantInactiveException` is NOT caught — passing an inactive tenant slug to `--tenant` will fail the command.

---

## Enabling and Disabling Resolvers

The `tenancy.resolvers` config key controls which HTTP resolvers are active:

```yaml
# Default: all HTTP resolvers active
tenancy:
    resolvers:
        - host
        - header
        - query_param
        - console

# API-only setup: header resolver only
tenancy:
    resolvers:
        - header

# Subdomain + header, no query param (recommended for production web apps)
tenancy:
    resolvers:
        - host
        - header
```

!!! note "ConsoleResolver is always active"
    Removing `console` from the `resolvers` list has no effect — `ConsoleResolver` is registered unconditionally as a `ConsoleCommandEvent` listener.

!!! note "Custom resolvers always pass through"
    The `tenancy.resolvers` config list only filters the four built-in resolvers (`host`,
    `header`, `query_param`, `console`). Custom resolvers that implement
    `TenantResolverInterface` are **never** filtered — they are always added to the chain
    regardless of the `resolvers` config value. This means you cannot accidentally disable
    a custom resolver by omitting it from the config list.

---

## Custom Resolver

You can add your own resolver by implementing `TenantResolverInterface`. The bundle automatically tags any class implementing this interface with `tenancy.resolver` — no manual service configuration required.

### Interface

```php
namespace Tenancy\Bundle\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\TenantInterface;

interface TenantResolverInterface
{
    public function resolve(Request $request): ?TenantInterface;
}
```

Return `null` to signal "I cannot identify a tenant from this request — try the next resolver." Return a `TenantInterface` to claim the resolution.

### Example: PathResolver

A resolver that reads the tenant slug from the URL path (`/tenant/{slug}/...`):

```php
<?php

declare(strict_types=1);

namespace App\Resolver;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\Resolver\TenantResolverInterface;
use Tenancy\Bundle\TenantInterface;

#[AutoconfigureTag('tenancy.resolver', ['priority' => 25])]
final class PathResolver implements TenantResolverInterface
{
    public function __construct(
        private readonly TenantProviderInterface $tenantProvider,
    ) {
    }

    public function resolve(Request $request): ?TenantInterface
    {
        $pathInfo = $request->getPathInfo();

        // Expects paths like /tenant/acme/...
        if (!preg_match('#^/tenant/([^/]+)#', $pathInfo, $matches)) {
            return null;
        }

        $slug = $matches[1];

        try {
            return $this->tenantProvider->findBySlug($slug);
        } catch (TenantNotFoundException) {
            return null;
        }
        // TenantInactiveException is intentionally not caught — bubbles up as 403/500
    }
}
```

### Setting the Priority

Use `#[AutoconfigureTag]` on the class for compile-time priority configuration:

```php
#[AutoconfigureTag('tenancy.resolver', ['priority' => 25])]
final class PathResolver implements TenantResolverInterface { ... }
```

Or configure via YAML services if you prefer not to use attributes:

```yaml
# config/services.yaml
App\Resolver\PathResolver:
    tags:
        - { name: tenancy.resolver, priority: 25 }
```

A priority of `25` places this resolver between `HostResolver` (30) and `HeaderResolver` (20).

### Auto-Registration

Any class implementing `TenantResolverInterface` is automatically tagged with `tenancy.resolver` by the bundle's `loadExtension()` via `registerForAutoconfiguration()`. You do not need to add the tag manually unless you want to set a custom priority.

---

## Deep Dive

For a detailed walkthrough of the resolver chain compiler pass (`ResolverChainPass`) and how resolvers are sorted and wired at compile time, see [DI Compilation Pipeline](../architecture/di-compilation.md).
