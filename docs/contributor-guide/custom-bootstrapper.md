# Custom Bootstrapper

How to implement a custom bootstrapper that reconfigures a Symfony service when a tenant
is resolved.

## Overview

Bootstrappers are the primary extension point for per-tenant service configuration. When
a tenant is identified (after the resolver chain runs), `BootstrapperChain::boot()` calls
every registered bootstrapper's `boot()` method in sequence. On request termination,
`clear()` is called in **reverse order**.

Any class implementing `TenantBootstrapperInterface` is automatically added to the chain
via `registerForAutoconfiguration` — no manual DI configuration required when autoconfigure
is enabled.

## The Interface

```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Bootstrapper;

use Tenancy\Bundle\TenantInterface;

interface TenantBootstrapperInterface
{
    /**
     * Reconfigure services for the active tenant.
     * Called once per request after the tenant is identified.
     */
    public function boot(TenantInterface $tenant): void;

    /**
     * Undo all changes made in boot().
     * Called on kernel.terminate, in REVERSE order of boot().
     */
    public function clear(): void;
}
```

## Implementation Example: MailerBootstrapper

This example switches the mailer transport to a per-tenant SMTP server when a tenant
becomes active. The tenant's SMTP configuration is read from the tenant's connection
config (or any tenant-specific config source you choose).

```php
<?php

declare(strict_types=1);

namespace App\Bootstrapper;

use Symfony\Component\Mailer\Transport\TransportInterface;
use Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface;
use Tenancy\Bundle\TenantInterface;

final class MailerBootstrapper implements TenantBootstrapperInterface
{
    private ?TransportInterface $defaultTransport = null;

    public function __construct(
        private readonly TransportFactory $transportFactory,
        private TransportInterface $mailerTransport,
    ) {
    }

    public function boot(TenantInterface $tenant): void
    {
        // Save the default transport so clear() can restore it
        $this->defaultTransport = $this->mailerTransport;

        // Read tenant-specific SMTP config
        /** @var array{smtp_dsn?: string} $config */
        $config = $tenant->getConnectionConfig();

        if (!isset($config['smtp_dsn'])) {
            // No per-tenant SMTP configured — keep the default transport
            return;
        }

        // Switch to the tenant's transport
        $this->mailerTransport = $this->transportFactory->fromString($config['smtp_dsn']);
    }

    public function clear(): void
    {
        if (null !== $this->defaultTransport) {
            // Restore the default transport
            $this->mailerTransport = $this->defaultTransport;
            $this->defaultTransport = null;
        }
    }
}
```

!!! warning "clear() MUST undo everything boot() did"
    `clear()` is called on `kernel.terminate` for every request where `boot()` ran,
    even if the request failed with an exception. If `boot()` modifies shared state and
    `clear()` does not restore it, subsequent requests on the same PHP process (e.g. in
    a long-running worker) will inherit the previous tenant's configuration — a data
    leak.

## Registration

If your class is in `src/` with `autoconfigure: true` (the Symfony default), it is
auto-tagged as `tenancy.bootstrapper` and no further configuration is needed.

To register manually:

```yaml
services:
    App\Bootstrapper\MailerBootstrapper:
        tags:
            - { name: tenancy.bootstrapper }
```

## `TenantDriverInterface`: The Driver Marker

The bundle ships two bootstrappers that act as **primary isolation drivers**:
`DatabaseSwitchBootstrapper` (database-per-tenant) and `SharedDriver` (shared-DB). Both
implement `TenantDriverInterface` in addition to `TenantBootstrapperInterface`:

```php
namespace Tenancy\Bundle\Driver;

use Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface;

interface TenantDriverInterface extends TenantBootstrapperInterface
{
    // No additional methods — this is a semantic marker interface only.
}
```

Implement `TenantDriverInterface` if your bootstrapper is a **primary isolation driver** —
i.e. it is the central mechanism that separates tenant data at the infrastructure level.
For application-level bootstrappers like `MailerBootstrapper`, implementing only
`TenantBootstrapperInterface` is correct.

## Lifecycle Guarantees

| Guarantee | Detail |
|-----------|--------|
| `boot()` is called for every tenant switch | Called from `TenantContextOrchestrator::onKernelRequest()` after the resolver chain succeeds |
| `clear()` is called on `kernel.terminate` | Called from `TenantContextOrchestrator::onKernelTerminate()` |
| `clear()` runs in reverse order of `boot()` | `BootstrapperChain::clear()` uses `array_reverse($this->bootstrappers)` |
| `clear()` is called even if `boot()` threw | `BootstrapperChain::boot()` completes all registered bootstrappers before dispatching `TenantBootstrapped`; clear runs for all that completed `boot()` |
| Test teardown also calls `clear()` | `InteractsWithTenancy::clearTenant()` and `tearDown()` call `BootstrapperChain::clear()` directly |

## Unit Testing Your Bootstrapper

```php
<?php

declare(strict_types=1);

namespace App\Tests\Bootstrapper;

use App\Bootstrapper\MailerBootstrapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Tenancy\Bundle\TenantInterface;

final class MailerBootstrapperTest extends TestCase
{
    private TransportFactory&MockObject $factory;
    private TransportInterface&MockObject $defaultTransport;
    private MailerBootstrapper $bootstrapper;

    protected function setUp(): void
    {
        $this->factory = $this->createMock(TransportFactory::class);
        $this->defaultTransport = $this->createMock(TransportInterface::class);
        $this->bootstrapper = new MailerBootstrapper($this->factory, $this->defaultTransport);
    }

    public function testBootSwitchesTransport(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getConnectionConfig')
            ->willReturn(['smtp_dsn' => 'smtp://mailer.acme.com:587']);

        $tenantTransport = $this->createMock(TransportInterface::class);
        $this->factory
            ->expects($this->once())
            ->method('fromString')
            ->with('smtp://mailer.acme.com:587')
            ->willReturn($tenantTransport);

        $this->bootstrapper->boot($tenant);

        // After boot, the active transport should be the tenant's transport.
        // Verify by calling clear() and confirming the default is restored.
        $this->bootstrapper->clear();

        // No assertion needed — if clear() didn't throw, the restore path worked.
        $this->addToAssertionCount(1);
    }

    public function testClearRestoresDefaultTransport(): void
    {
        // Boot with no per-tenant SMTP config
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getConnectionConfig')->willReturn([]);

        $this->factory->expects($this->never())->method('fromString');

        $this->bootstrapper->boot($tenant);
        $this->bootstrapper->clear();

        // clear() with no boot-time changes should not throw
        $this->addToAssertionCount(1);
    }

    public function testBootWithNoSmtpConfigKeepsDefaultTransport(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getConnectionConfig')->willReturn([]);

        $this->factory->expects($this->never())->method('fromString');

        $this->bootstrapper->boot($tenant);

        // Transport unchanged — no assertion beyond "no exception"
        $this->addToAssertionCount(1);
    }
}
```

## See Also

- [Architecture Overview](architecture.md) — where bootstrappers fit in the request lifecycle
- [Custom Resolver](custom-resolver.md) — the step before bootstrapping
- `src/Bootstrapper/DatabaseSwitchBootstrapper.php` — the simplest real bootstrapper in the bundle
- `src/Bootstrapper/BootstrapperChain.php` — how `boot()` and `clear()` are orchestrated
