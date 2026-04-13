# Messenger Stamp Lifecycle

Tenant context must survive process boundaries. When a message is dispatched in one process (HTTP request) and consumed in another (Redis consumer, RabbitMQ worker), the consumer has no HTTP request to resolve from. `TenantStamp` carries the tenant identity as a serialized envelope stamp across the boundary.

## Overview

```
HTTP Process                             Worker Process
─────────────────────────────────────    ──────────────────────────────────────
Controller dispatches message            Message dequeued from transport
        │                                        │
        ▼                                        ▼
TenantSendingMiddleware                  TenantWorkerMiddleware
  reads TenantContext                      reads TenantStamp
  attaches TenantStamp                     looks up tenant
        │                                  boots BootstrapperChain
        ▼                                        │
  Envelope → Transport (Redis/AMQP)             ▼
  [TenantStamp serialized]               [Handler runs in tenant context]
                                                 │
                                                 ▼ (finally)
                                         BootstrapperChain::clear()
                                         TenantContext::clear()
                                         dispatch: TenantContextCleared
```

---

## Phase 1: Dispatch (TenantSendingMiddleware)

`TenantSendingMiddleware` runs on the sending side of every bus. It checks for an active tenant and attaches a `TenantStamp` if found:

```php
final class TenantSendingMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $tenant = $this->tenantContext->getTenant();
        if (null === $envelope->last(TenantStamp::class) && null !== $tenant) {
            $envelope = $envelope->with(
                new TenantStamp($tenant->getSlug())
            );
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
```

**Behavior:**

- If a tenant is active: `$envelope->with(new TenantStamp($slug))` attaches the stamp before passing to the next middleware
- If no tenant is active: envelope passes through unchanged — no stamp added
- **Idempotency guard:** `$envelope->last(TenantStamp::class)` is checked first — if a stamp already exists (e.g. from a re-dispatched envelope), a second stamp is not added

**Position in the stack:** `MessengerMiddlewarePass` prepends `TenantSendingMiddleware` to every bus's middleware list. It runs before all user-defined middleware — the stamp is present for any middleware that needs to read it.

---

## Phase 2: Serialization

`TenantStamp` is a minimal value object:

```php
final class TenantStamp implements StampInterface
{
    public function __construct(public readonly string $tenantSlug)
    {
    }

    public function getTenantSlug(): string
    {
        return $this->tenantSlug;
    }
}
```

By implementing `StampInterface`, `TenantStamp` participates in Symfony Messenger's envelope serialization protocol:

- Stamps are serialized with the envelope metadata by the transport layer
- No custom serializer is needed — Symfony's built-in serializer handles `StampInterface` implementations
- The `tenantSlug` (a plain string) round-trips through PHP `serialize`/`unserialize` without data loss
- When using JSON-based transports (e.g. Doctrine transport with `use_notify: false`), stamps are JSON-encoded

The stamp survives any Symfony-supported transport: Redis, RabbitMQ, Amazon SQS, Doctrine.

---

## Phase 3: Consume (TenantWorkerMiddleware)

`TenantWorkerMiddleware` runs on the consuming side. It reads the stamp, restores full tenant context, and guarantees cleanup:

```php
final class TenantWorkerMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $stamp = $envelope->last(TenantStamp::class);

        if (null === $stamp) {
            return $stack->next()->handle($envelope, $stack);  // no tenant context needed
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
}
```

**Step by step:**

1. **Read stamp:** `$envelope->last(TenantStamp::class)` returns the most recently added `TenantStamp`, or `null` if none
2. **No stamp:** Pass through to next middleware — message handler runs without tenant context (non-tenanted messages are unaffected)
3. **Stamp found:**
   - Look up tenant: `TenantProviderInterface::findBySlug($stamp->getTenantSlug())`
   - Set context: `TenantContext::setTenant($tenant)`
   - Boot: `BootstrapperChain::boot($tenant)` — full context restored (DB connection switched, cache namespaced, Doctrine filter injected)
4. **Handler runs:** `$stack->next()->handle($envelope, $stack)` in a `try` block
5. **Cleanup (finally):** Runs unconditionally, even if the handler throws
   - `BootstrapperChain::clear()` — reverse-order teardown
   - `TenantContext::clear()` — removes active tenant
   - `dispatch(new TenantContextCleared())` — signal event for cleanup listeners

---

## try/finally Teardown Pattern

The `finally` block is critical for worker isolation:

```
Message A arrives (tenant: acme)
    boot(acme) → handle → [exception or success]
    finally: clear()   ← always runs
    
Message B arrives (tenant: demo)
    boot(demo) → handle → success
    finally: clear()
```

Without `finally`, an exception in message A's handler would leave the worker with `acme`'s tenant context. Message B would run with the wrong tenant — a cross-tenant data leak.

---

## Why TenantResolved Is Not Dispatched

The worker middleware does **not** dispatch `TenantResolved` when restoring tenant context from a stamp:

- `TenantResolved` carries `public readonly ?Request $request` — HTTP listeners attached to this event may assume a non-null `Request` is available
- The tenant is being **restored** from a stamp, not **resolved** from an HTTP request
- Firing `TenantResolved` in worker context could trigger listeners that call `$event->getRequest()` and fail with a null reference

The worker dispatches `TenantContextCleared` (which has no payload) after cleanup — this is safe in both HTTP and worker contexts.

---

## Bus Enrollment (MessengerMiddlewarePass)

Both middleware are automatically enrolled in every configured Messenger bus. No user configuration is required:

```php
// In TenancyBundle::build()
if (interface_exists(MessageBusInterface::class)) {
    $container->addCompilerPass(
        new MessengerMiddlewarePass(),
        PassConfig::TYPE_BEFORE_OPTIMIZATION,
        1  // priority 1 — before MessengerPass at 0
    );
}
```

`MessengerMiddlewarePass` reads all bus IDs from `findTaggedServiceIds('messenger.bus')` and prepends both middleware to every bus's `{busId}.middleware` parameter. This means tenancy context works on all buses without any per-bus configuration — the default bus, the command bus, and any custom buses.

See [DI Compilation Pipeline](di-compilation.md#messengermiddlewarepass) for the full details of how direct parameter modification avoids the `performNoDeepMerging()` issue.

---

## Sequential Message Isolation

Symfony Messenger workers process messages sequentially by default (one at a time). The `try/finally` pattern guarantees isolation between messages:

```
Worker loop:
  ┌─────────────────────────────────────────┐
  │ Dequeue: Message A (tenant: acme)       │
  │   boot(acme)                            │
  │   handle → invoice created              │
  │   finally: clear()                      │
  └─────────────────────────────────────────┘
  ┌─────────────────────────────────────────┐
  │ Dequeue: Message B (tenant: demo)       │
  │   boot(demo)                            │
  │   handle → invoice created              │
  │   finally: clear()                      │
  └─────────────────────────────────────────┘
  ┌─────────────────────────────────────────┐
  │ Dequeue: Message C (no stamp)           │
  │   pass through — no tenant context      │
  └─────────────────────────────────────────┘
```

Between messages, state is always cleared. `TenantContext::getTenant()` returns `null`. `BootstrapperChain::clear()` has restored the landlord database connection. No state leaks between messages.
