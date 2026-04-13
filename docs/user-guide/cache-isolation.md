# Cache Isolation

The bundle automatically isolates the `cache.app` pool per tenant using Symfony's
`withSubNamespace()` API. Cache entries written under Tenant A are completely invisible to Tenant B
— no manual key prefixing required.

## Overview

`TenantAwareCacheAdapter` decorates the `cache.app` pool. On every cache operation, it reads
`TenantContext` live and routes the call through a sub-namespaced pool scoped to the active tenant.
When no tenant is active, operations go through the original pool.

This happens at the **namespace level**, not the key level. Each tenant's cache occupies an
isolated sub-namespace within the same underlying storage backend.

!!! info "Automatic Registration"
    Cache isolation is zero-config. The `CacheBootstrapper` is registered as a
    `tenancy.bootstrapper` service automatically. No configuration required.

## How It Works

```php
// src/Cache/TenantAwareCacheAdapter.php (simplified)
private function pool(): AdapterInterface&NamespacedPoolInterface
{
    $tenant = $this->tenantContext->getTenant();
    if (null !== $tenant) {
        return $this->inner->withSubNamespace($tenant->getSlug());
    }

    return $this->inner;
}
```

Key design points:

- **Live reads**: `TenantContext` is read on **every cache call** — the sub-namespaced pool is
  never cached as a property. This prevents stale tenant context from leaking between requests in
  long-running processes.
- **No side effects**: `withSubNamespace()` returns a new pool instance — the original `$inner`
  pool is not mutated.
- **Transparent delegation**: All `CacheInterface` methods (`getItem`, `getItems`, `hasItem`,
  `save`, `deleteItem`, `deleteItems`, `clear`, `commit`, `saveDeferred`) delegate to `pool()`.

## Namespace Isolation in Practice

Assuming `cache.app` uses Redis or filesystem storage:

```
No tenant active:    cache key  →  app:my_key
Tenant 'acme':       cache key  →  app/acme:my_key
Tenant 'demo':       cache key  →  app/demo:my_key
```

Clearing the 'acme' namespace does not touch 'demo' data, and does not touch the global
(no-tenant) cache.

## Using the Cache in Your Code

No changes to your code are needed. Just inject `CacheInterface` or `CacheItemPoolInterface`
as normal — the adapter handles tenant routing transparently:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class ProjectStatisticsService
{
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function getStats(): array
    {
        // This key is automatically namespaced per active tenant
        return $this->cache->get('project_stats', function (ItemInterface $item): array {
            $item->expiresAfter(3600);
            return $this->computeStats();
        });
    }
}
```

## Cache Clearing

To clear the cache for a specific tenant, boot the tenant context and call `clear()` as usual.
The adapter routes `clear()` through the tenant's sub-namespace:

```bash
# Clear cache for tenant 'acme'
bin/console tenancy:run acme "cache:clear"
```

## Custom Cache Pools

The bundle only decorates `cache.app` automatically. If you use custom cache pools (e.g.,
`cache.custom` or pools defined in `framework.cache.pools`), decorate them manually:

```yaml
# config/services.yaml
services:
    App\Cache\TenantAwareCustomPool:
        class: Tenancy\Bundle\Cache\TenantAwareCacheAdapter
        decorates: cache.custom
        arguments:
            $inner: '@.inner'
            $tenantContext: '@tenancy.context'
```

## See Also

- [Configuration Reference](configuration.md) — full config options
- [Testing](testing.md) — cache state is automatically fresh per test via `initializeTenant()`
