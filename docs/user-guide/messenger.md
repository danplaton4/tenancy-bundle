# Messenger Integration

When a message is dispatched while a tenant context is active, a `TenantStamp` carrying the
tenant slug is automatically attached to the envelope. When a worker processes the message, the
stamp restores the full tenant context before the handler runs — zero boilerplate required.

## Requirements

`symfony/messenger` must be installed. The bundle detects it via:

```php
interface_exists(MessageBusInterface::class)
```

If `symfony/messenger` is not installed, all Messenger wiring is silently skipped — no error,
no service definitions registered.

```bash
composer require symfony/messenger
```

!!! warning "Stamps not being attached?"
    If you see messages processed without tenant context, verify that `symfony/messenger` is
    installed. The `interface_exists` guard means missing the package silently disables the
    integration.

## How It Works

There are three stages in the stamp lifecycle:

### Stage 1: Dispatch

`TenantSendingMiddleware` intercepts every dispatched message. If `TenantContext` has an active
tenant and the envelope does not already carry a `TenantStamp` (idempotency guard), it attaches
one:

```php
// src/Messenger/TenantSendingMiddleware.php (simplified)
public function handle(Envelope $envelope, StackInterface $stack): Envelope
{
    $tenant = $this->tenantContext->getTenant();
    if (null === $envelope->last(TenantStamp::class) && null !== $tenant) {
        $envelope = $envelope->with(new TenantStamp($tenant->getSlug()));
    }
    return $stack->next()->handle($envelope, $stack);
}
```

The `TenantStamp` carries only the tenant **slug** — a simple string:

```php
// src/Messenger/TenantStamp.php
final class TenantStamp implements StampInterface
{
    public function __construct(public readonly string $tenantSlug) {}
}
```

### Stage 2: Serialize and Transport

The stamp is serialized as part of the envelope and travels through the transport layer —
Redis, RabbitMQ, Doctrine transport, Amazon SQS, etc. The slug is a plain string so it
survives any serialization format (PHP native, JSON, etc.).

### Stage 3: Consume

`TenantWorkerMiddleware` runs in the worker process. It reads the `TenantStamp`, looks up the
full `Tenant` entity via `TenantProviderInterface::findBySlug()`, boots the tenant context via
`BootstrapperChain::boot()`, then runs the handler:

```php
// src/Messenger/TenantWorkerMiddleware.php (simplified)
public function handle(Envelope $envelope, StackInterface $stack): Envelope
{
    $stamp = $envelope->last(TenantStamp::class);

    if (null === $stamp) {
        // No stamp — pass through unmodified (unstamped messages are fine)
        return $stack->next()->handle($envelope, $stack);
    }

    $tenant = $this->tenantProvider->findBySlug($stamp->getTenantSlug());
    $this->tenantContext->setTenant($tenant);
    $this->bootstrapperChain->boot($tenant);

    try {
        return $stack->next()->handle($envelope, $stack);
    } finally {
        $this->bootstrapperChain->clear();
        $this->tenantContext->clear();
        $this->eventDispatcher->dispatch(new TenantContextCleared());
    }
}
```

## Teardown Guarantee

The `try/finally` block in `TenantWorkerMiddleware` ensures cleanup **even if the handler
throws**. Two messages with different tenant stamps processed sequentially get the correct
isolated context:

```
Message 1 (stamp: acme)  → boot acme → handle → clear acme context
Message 2 (stamp: demo)  → boot demo → handle → clear demo context
```

Even if Message 1's handler throws a `RuntimeException`, the `finally` block clears the 'acme'
context before Message 2 runs.

## Important: No `TenantResolved` Event in Workers

The worker middleware does **not** dispatch the `TenantResolved` event. It calls
`BootstrapperChain::boot()` directly. This is intentional:

- `TenantResolved` is a kernel event signal, used by HTTP-specific listeners
- Worker context does not have an HTTP request
- Firing `TenantResolved` in a worker would trigger request-scoped listeners unexpectedly

The tenant is **restored** in the worker, not **resolved**. All bootstrappers (database switch,
cache namespace, etc.) still run — the distinction is that HTTP listeners are not triggered.

## Bus Enrollment

`MessengerMiddlewarePass` (a compiler pass with priority 1) automatically prepends both
`TenantSendingMiddleware` and `TenantWorkerMiddleware` to every configured message bus. No
manual bus configuration required:

```yaml
# No additional messenger config needed — middleware is auto-enrolled
framework:
    messenger:
        transports:
            async: '%env(MESSENGER_TRANSPORT_DSN)%'
        routing:
            App\Message\ProcessOrderMessage: async
```

## Example: Dispatching a Tenant-Scoped Message

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\GenerateReportMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class ReportController extends AbstractController
{
    #[Route('/reports/generate', methods: ['POST'])]
    public function generate(MessageBusInterface $bus): Response
    {
        // TenantSendingMiddleware automatically attaches TenantStamp
        // based on the active tenant context (resolved from subdomain/header)
        $bus->dispatch(new GenerateReportMessage(period: 'monthly'));

        return $this->json(['status' => 'queued']);
    }
}
```

The handler in the worker receives the message with full tenant context already active:

```php
<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\GenerateReportMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class GenerateReportHandler
{
    public function __invoke(GenerateReportMessage $message): void
    {
        // Tenant context is active here — DB connection, cache, etc.
        // all pointing at the correct tenant automatically
    }
}
```

## See Also

- [Architecture: Messenger Stamp Lifecycle](../architecture/messenger-lifecycle.md)
- [Testing](testing.md) — testing message handlers with tenant context
- [CLI Commands](cli-commands.md) — running commands with tenant context
