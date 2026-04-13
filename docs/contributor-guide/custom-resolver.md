# Custom Resolver

How to implement a custom tenant resolver that integrates automatically with the bundle's
resolver chain.

## Overview

Any class implementing `TenantResolverInterface` is automatically discovered and added
to the resolver chain. The bundle uses Symfony's `registerForAutoconfiguration` mechanism
in `TenancyBundle::loadExtension()`, so no manual service configuration is required when
your class is in the `src/` directory with autoconfigure enabled.

The resolver chain tries each resolver in priority order (highest first) and returns the
first non-null result. If all resolvers return `null`, a `TenantNotFoundException` is
thrown.

## The Interface

```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\TenantInterface;

interface TenantResolverInterface
{
    public function resolve(Request $request): ?TenantInterface;
}
```

Return a `TenantInterface` instance if your resolver can identify the tenant from this
request. Return `null` to pass to the next resolver in the chain.

## Implementation Example: PathResolver

This example implements a resolver that identifies the tenant from the URL path segment
`/t/{slug}/...` (useful for applications where each tenant has a path prefix rather than
a subdomain).

```php
<?php

declare(strict_types=1);

namespace App\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\Exception\TenantInactiveException;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\Resolver\TenantResolverInterface;
use Tenancy\Bundle\TenantInterface;

final class PathResolver implements TenantResolverInterface
{
    public function __construct(
        private readonly TenantProviderInterface $tenantProvider,
    ) {
    }

    public function resolve(Request $request): ?TenantInterface
    {
        // Match paths like /t/acme/dashboard, /t/acme/api/...
        if (!preg_match('#^/t/([^/]+)#', $request->getPathInfo(), $matches)) {
            return null;
        }

        $slug = $matches[1];

        try {
            return $this->tenantProvider->findBySlug($slug);
        } catch (TenantNotFoundException) {
            // Slug not found — return null and let the next resolver try
            return null;
        }
        // TenantInactiveException is intentionally NOT caught here — it bubbles up
        // as a 403 response, signalling that the tenant exists but is suspended.
    }
}
```

!!! warning "Exception handling"
    Always catch `TenantNotFoundException` and return `null` — this allows the next
    resolver in the chain to try. Do **not** catch `TenantInactiveException`; let it
    propagate so the framework returns a 403 response.

## Registration

If your class is in `src/` with `autoconfigure: true` (the Symfony default), it is
auto-tagged as `tenancy.resolver` and no further configuration is needed:

```yaml
# config/services.yaml — default Symfony config already does this
services:
    _defaults:
        autoconfigure: true

    App\:
        resource: '../src/'
```

To register manually or when autoconfigure is not available:

```yaml
services:
    App\Resolver\PathResolver:
        tags:
            - { name: tenancy.resolver, priority: 15 }
```

You can also use the `#[AutoconfigureTag]` PHP attribute directly on the class:

```php
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('tenancy.resolver', ['priority' => 15])]
final class PathResolver implements TenantResolverInterface
{
    // ...
}
```

## Priority System

Higher priority number = runs earlier in the chain. The default resolvers use:

| Resolver | Priority | Identifies tenant from |
|----------|----------|----------------------|
| `HostResolver` | 30 | Subdomain (`acme.app.com`) or custom domain (`acme.com`) |
| `HeaderResolver` | 20 | `X-Tenant-ID` HTTP header |
| `QueryParamResolver` | 10 | `?_tenant=acme` query parameter |
| `ConsoleResolver` | 10 | `--tenant=acme` CLI argument |

Choose a priority that makes sense for your use case:

- **Priority > 30**: Run before host resolution (unusual — only for specialized scenarios)
- **Priority 15–25**: Run between host and header resolution
- **Priority < 10**: Run as a last resort fallback

The first resolver that returns a non-null tenant wins. Subsequent resolvers are not
called.

## Unit Testing Your Resolver

```php
<?php

declare(strict_types=1);

namespace App\Tests\Resolver;

use App\Resolver\PathResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;

final class PathResolverTest extends TestCase
{
    private TenantProviderInterface&MockObject $provider;
    private PathResolver $resolver;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(TenantProviderInterface::class);
        $this->resolver = new PathResolver($this->provider);
    }

    public function testResolvesFromPathPrefix(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $request = Request::create('/t/acme/dashboard');

        $this->provider
            ->expects($this->once())
            ->method('findBySlug')
            ->with('acme')
            ->willReturn($tenant);

        $result = $this->resolver->resolve($request);

        $this->assertSame($tenant, $result);
    }

    public function testReturnsNullWhenPathDoesNotMatch(): void
    {
        $request = Request::create('/dashboard');

        $this->provider->expects($this->never())->method('findBySlug');

        $this->assertNull($this->resolver->resolve($request));
    }

    public function testReturnsNullWhenTenantNotFound(): void
    {
        $request = Request::create('/t/unknown/page');

        $this->provider
            ->method('findBySlug')
            ->willThrowException(new TenantNotFoundException('unknown'));

        $this->assertNull($this->resolver->resolve($request));
    }

    public function testBubblesInactiveException(): void
    {
        $request = Request::create('/t/suspended/page');

        $this->provider
            ->method('findBySlug')
            ->willThrowException(new \Tenancy\Bundle\Exception\TenantInactiveException('suspended'));

        $this->expectException(\Tenancy\Bundle\Exception\TenantInactiveException::class);

        $this->resolver->resolve($request);
    }
}
```

## See Also

- [Architecture Overview](architecture.md) — how the resolver chain fits into the request lifecycle
- [Custom Bootstrapper](custom-bootstrapper.md) — what happens after the tenant is resolved
- `src/Resolver/HeaderResolver.php` — a simple resolver to use as a reference implementation
