# Phase 27: Async Shared Entities - Research

**Researched:** 2026-06-15
**Domain:** Symfony Messenger handler registration, retry/failure contract, PhpSerializer round-trip, compile-time guard pattern, async branch over existing sync machinery
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**D-01 (Fan-out topology):** One `SharedEntityChangedMessage` per changed `#[Shared]` entity; handler runs the full per-tenant loop over ALL tenants. Reuses Phase 25's `switchToTenant()` + `SharedEntityCopier::applyRow()` in the handler. NO `TenantStamp` — the message fans to all tenants, the handler sets each tenant's context itself. `findAll()` is NOT called on the landlord request.

**D-02 (Worker failure / retry semantics):** Best-effort attempt-all, then throw-to-retry on any failure. Per-tenant `try/catch` with logging (`tenant_slug` / `entity_class` / `identifier` / `error`). After the loop, if ANY tenant failed, throw an aggregate exception so Messenger retries the whole message per the transport's `retry_strategy`. Re-applying to already-synced tenants is safe (copier is idempotent). Does NOT violate Phase 25's D-01 "never throw" — that rule protects the HTTP request; the async worker has no request to protect.

**D-03 (Transport routing):** Document-only. User maps `SharedEntityChangedMessage` to their async transport in `framework.messenger.routing`. If NOT routed async, message executes synchronously on the bus (handler runs inline) — still correct, just not deferred. Bundle never forces routing.

**D-04 (Delete / stale-state):** Vanished landlord row at handle time = propagate tenant-side delete. `type=delete` uses the captured identifier. `type=insert|update` but row gone = handler propagates delete (eventual-consistency convergence). Prevent permanent orphan drift since Phase 26 deferred orphan-batch deletion.

**D-05 (Insert/update collapse):** Handler always re-fetches the latest landlord state. `insert` and `update` collapse into "upsert-by-id from latest state" via `SharedEntityCopier::applyRow()`. Only `delete` (including vanished-row→delete) is a distinct branch.

**D-06 (Config shape):** Plain `booleanNode()->defaultFalse()` (NOT mailer tri-state). Compile-time guard: `tenancy.shared.async=true` + `!interface_exists(MessageBusInterface::class)` → throw descriptive `\LogicException` at build time.

**D-07 (Subscriber wiring):** `SharedEntitySyncSubscriber` gains `?MessageBusInterface` nullable constructor arg. Guard (D-06) prevents async:true + no-Messenger from reaching runtime. Wired inside the existing `interface_exists(EntityManagerInterface)` + `database_per_tenant` block.

### Claude's Discretion

- Exact `SharedEntityChangedMessage` shape (property names, identifier representation — reuse `getIdentifierValues()` scalar-array shape), namespace (`Tenancy\Bundle\Message\...`), and whether the handler is a separate class or `__invoke`able service.
- The precise mechanism for branching `postFlush()` between sync and async (e.g., private `dispatchAsync()` vs `fanOutSync()` split).
- How the handler reuses `switchToTenant()` (extract a shared helper vs duplicate the small method).
- The aggregate-exception type for D-02 (new `\RuntimeException` subclass vs reusing/wrapping exception semantics) and exact log keys for the dead-letter path.
- Compile-time guard class name (`SharedAsyncContractPass` is a working name) and where it registers in `TenancyBundle::build()`.
- Canary test kernel/transport plumbing — follow `AsyncCanaryTest`'s pattern.

### Deferred Ideas (OUT OF SCOPE)

- PHPStan rule for `#[Shared]`/`#[TenantAware]` — Phase 28.
- Docs page for shared-entities async mode — Phase 29 (inline PHPDoc only this phase).
- Orphan-copy deletion / full reconciliation in `tenancy:shared:resync` — still deferred (Phase 26).
- Per-tenant async retry isolation (one message per tenant w/ `TenantStamp`) — rejected in D-01.
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| SHARE-03 | Opt-in `tenancy.shared.async: true` mode; `SharedEntityChangedMessage` (class+identifier+change-type, NOT full payload); worker re-fetches latest state at handle time; `MailerTransportContractPass`-style compile-time guard for async+no-messenger; integration test proving Messenger transport round-trip | Handler registration pattern (verified), retry contract (verified), SyncTransport round-trip mechanics (verified), guard registration pattern (verified), canary test pattern (documented in detail) |
</phase_requirements>

---

## Summary

Phase 27 is a thin async branch layered over already-proven Phase 25 (sync fan-out) and Phase 26 (resync) machinery. The architecture is fully locked in CONTEXT.md; this research de-risks three specific areas the planner must get right: (1) how to register the handler without autoconfigure, (2) the exact retry/failure exception contract, and (3) what the `sync://` transport actually does (and what the canary must therefore test).

**Critical finding — SyncTransport does NOT serialize:** The Phase 20 `AsyncCanaryTest` comment claims PhpSerializer encode→decode happens in the `sync://` transport. This is incorrect for the transport itself — `SyncTransport::send()` directly re-dispatches the envelope on the bus without any serialization. The SHARE-03 canary is therefore testing middleware execution order and handler reach (fan-out to all tenant EMs, never wrong tenant) rather than serialization survival. This changes what the canary must assert: not "message survived PhpSerializer" but "handler reached each tenant EM and applied the correct change." The message class itself (`SharedEntityChangedMessage`) must be verified to be PHP-serializable (it carries only scalars, so this is trivially true), but testing that is not the canary's primary purpose.

**Critical finding — handler registration:** `#[AsMessageHandler]` works only via `registerAttributeForAutoconfiguration` in FrameworkBundle, which requires autoconfigure to be enabled. This bundle uses explicit `doctrine.event_listener` tags for all subscriber registration and disables autoconfigure on shared-entity services. The SHARE-03 handler MUST be registered via explicit `->tag('messenger.message_handler', [...])` in `TenancyBundle::loadExtension()`, not via `#[AsMessageHandler]`.

**Critical finding — retry contract:** Any exception thrown from a handler that is NOT `UnrecoverableExceptionInterface` causes Messenger to retry per the transport's `retry_strategy`. A plain `\RuntimeException` subclass is the correct aggregate exception for D-02 — no need to wrap `HandlerFailedException` (which is what Messenger itself wraps single-handler failures in). The bundle's aggregate exception should extend `\RuntimeException` directly.

**Primary recommendation:** Implement `SharedEntityChangedMessage` as a pure scalar value object; register the handler with explicit `messenger.message_handler` tag; throw a new `SharedEntityAsyncFanOutException extends \RuntimeException` when any tenant failed in the per-tenant loop; register the compile-time guard unconditionally in `TenancyBundle::build()` (it short-circuits on `interface_exists` check of parameter existence).

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Async dispatch decision (`postFlush` branch) | Subscriber (HTTP request tier) | — | `postFlush` fires in the landlord request; the branch on `tenancy.shared.async` determines whether to dispatch or fan out synchronously |
| Message value object (`SharedEntityChangedMessage`) | Message contract | — | Pure data carrier; no tier-specific logic |
| Per-tenant fan-out (async path) | Messenger worker tier | — | Handler runs in the worker process, decoupled from HTTP request |
| Landlord re-fetch at handle time | Messenger worker tier → Doctrine landlord EM | — | Handler re-fetches from landlord EM at handle time (not dispatch time); this is a worker-tier responsibility |
| Compile-time guard (`SharedAsyncContractPass`) | Container build phase | — | Fires during `kernel.compile`; must run before `MessengerMiddlewarePass` if needed, but is a simple parameter-check pass with no ordering dependency |
| Test kernel (`SharedEntityAsyncTestKernel`) | Test infrastructure | — | Mirrors `MailerTestKernel` shape: FrameworkBundle + DoctrineBundle + TenancyBundle, `sync://` transport, no real broker |

---

## Standard Stack

### Core (all already installed — this phase adds no new packages)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| symfony/messenger | ^7.4\|\|^8.0 (v8.0.8 installed) | Message dispatch, handler registration, transport abstraction | Already in require-dev + suggest; Phase 6 Messenger integration already wired |
| doctrine/orm | (optional dep) | Landlord EM re-fetch in handler; copier reuse | Already guarded with `interface_exists(EntityManagerInterface::class)` throughout bundle |
| psr/log | (transitive) | Structured failure logging in handler (reuse Phase 25 keys) | PSR-3 already injected into subscriber and copier |

### No New Packages

This phase installs zero new Composer packages. All dependencies (symfony/messenger, doctrine/orm, psr/log) are already present as optional dependencies. The phase is purely additive PHP code over existing infrastructure.

---

## Package Legitimacy Audit

> No new packages installed in this phase. N/A.

**Packages removed due to slopcheck [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none

---

## Architecture Patterns

### System Architecture Diagram

```
  LANDLORD HTTP REQUEST
  ┌──────────────────────────────────────────────────┐
  │  landlord EM::flush()                            │
  │    │                                             │
  │    ▼                                             │
  │  SharedEntitySyncSubscriber::onFlush()           │
  │    └─ buffers #[Shared] changesets               │
  │         (incl. pre-captured delete ids)          │
  │    │                                             │
  │    ▼                                             │
  │  SharedEntitySyncSubscriber::postFlush()         │
  │    │                                             │
  │    ├─[tenancy.shared.async=false]──────────────────────────────────────┐
  │    │  sync fan-out (Phase 25 unchanged)                                 │
  │    │                                                                    │
  │    └─[tenancy.shared.async=true]                │                      │
  │         │                                       │                      │
  │         ▼                                       │                      │
  │  MessageBusInterface::dispatch(                 │                      │
  │    SharedEntityChangedMessage(class,id,type))   │                      │
  │    per buffered change                          │                      │
  │         │                                       │                      │
  │         ▼                                       │                      │
  │  [message routed async → transport]             │                      │
  │    OR [unrouted → handler runs inline ◄──────── D-03 documented]       │
  │         │                                       │                      │
  │  HTTP response returns ◄────────────────────────┘                      │
  └──────────────────────────────────────────────────┘                     │
                                                                           │
  MESSENGER WORKER (or inline if unrouted)                                 │
  ┌──────────────────────────────────────────────────┐                     │
  │  SharedEntityChangedMessageHandler::__invoke()  │                      │
  │    │                                            │                      │
  │    ├─ landlordEm->find($class, $id)             │                      │
  │    │   ├─[found] → upsert (applyRow)            │                      │
  │    │   └─[not found AND type=insert/update]     │                      │
  │    │       → propagate delete (D-04)            │                      │
  │    │                                            │                      │
  │    ├─ foreach TenantProviderInterface::findAll()│                      │
  │    │   ├─ switchToTenant($tenant)               │                      │
  │    │   ├─ try SharedEntityCopier::applyRow()    │                      │
  │    │   └─ catch → log + collect failure         │                      │
  │    │                                            │                      │
  │    ├─[any failure] → throw aggregate exception  │                      │
  │    │   └─ Messenger retries per retry_strategy  │                      │
  │    │      Exhausted → failure transport (DLQ)   │                      │
  │    │                                            │                      │
  │    └─ restoreTenantContext()                    │                      │
  └──────────────────────────────────────────────────┘                    ─┘
```

### Recommended Project Structure (new files this phase)

```
src/
├── Message/
│   └── SharedEntityChangedMessage.php      # Value object: class+identifier+type
├── MessageHandler/
│   └── SharedEntityChangedMessageHandler.php  # Fan-out handler (implements __invoke)
├── DependencyInjection/
│   └── Compiler/
│       └── SharedAsyncContractPass.php     # Compile-time guard (D-06)
└── Subscriber/
    └── SharedEntitySyncSubscriber.php      # Modified: gains ?MessageBusInterface arg + postFlush branch

tests/
└── Integration/
    └── SharedEntity/
        ├── SharedEntityAsyncCanaryTest.php           # Transport round-trip + handler fan-out
        └── Support/
            └── SharedEntityAsyncTestKernel.php       # sync:// transport + DoctrineBundle + TenancyBundle
```

### Pattern 1: Handler Registration with Explicit Tag (NO autoconfigure)

**What:** Register the handler as a regular service with an explicit `messenger.message_handler` tag. Do NOT use `#[AsMessageHandler]` on the handler class — it would only be processed by `registerAttributeForAutoconfiguration` which requires autoconfigure to be enabled, and this bundle uses `->autoconfigure(false)` (implicit via no-autoconfigure) for Messenger-wired services.

**Why:** `#[AsMessageHandler]` is equivalent to `->tag('messenger.message_handler')` only when the service participates in FrameworkBundle's autoconfiguration. The existing pattern (Phase 6 `MessengerMiddlewarePass`, explicit `doctrine.event_listener` tags for Phase 25 subscriber) never uses autoconfigure — the handler must follow the same convention.

**When to use:** Always, for this bundle.

```php
// Source: vendor/symfony/framework-bundle/DependencyInjection/FrameworkExtension.php L727
// #[AsMessageHandler] maps to: ->tag('messenger.message_handler', $tagAttributes)
// Equivalent explicit registration in TenancyBundle::loadExtension():

// Guard: inside the existing interface_exists(EntityManagerInterface) + database.enabled block
// Further guard: also inside interface_exists(MessageBusInterface) check
$services->set('tenancy.shared_entity_changed_handler', SharedEntityChangedMessageHandler::class)
    ->args([
        service('doctrine.orm.landlord_entity_manager'),
        service('tenancy.provider'),
        service('tenancy.shared_entity_copier'),
        service('tenancy.context'),
        service('doctrine'),
        service('logger'),
    ])
    ->tag('messenger.message_handler', ['handles' => SharedEntityChangedMessage::class]);
```

[VERIFIED: vendor/symfony/framework-bundle/DependencyInjection/FrameworkExtension.php L727-737]

### Pattern 2: Retry / Failure Exception Contract

**What:** The handler throws a `\RuntimeException` subclass (not `HandlerFailedException`) when any tenant failed. Messenger's `SendFailedMessageForRetryListener` calls `$retryStrategy->isRetryable($envelope, $e)` for exceptions that are not `RecoverableExceptionInterface` or `UnrecoverableExceptionInterface`. A plain `\RuntimeException` subclass is retryable per the transport's `retry_strategy`.

**Key retry logic (from `SendFailedMessageForRetryListener::shouldRetry()`):**

```
if ($e instanceof RecoverableExceptionInterface) → always retry
if $e instanceof HandlerFailedException {
    if any wrapped exception is RecoverableExceptionInterface → retry
    if ALL wrapped exceptions are UnrecoverableExceptionInterface → do NOT retry
    otherwise → fall through to retryStrategy->isRetryable()
}
if $e instanceof UnrecoverableExceptionInterface → never retry
otherwise → retryStrategy->isRetryable($envelope, $e)  ← our path
```

**Our exception:** `SharedEntityAsyncFanOutException extends \RuntimeException` — no interface implementation needed. The transport's `retry_strategy` (user-configured) decides retryability. After retries exhausted, the message goes to the failure transport (dead-letter queue).

**DO NOT throw HandlerFailedException manually.** That class takes an array of exceptions and is constructed by Messenger's `HandleMessageMiddleware` to wrap single-handler failures. Constructing it manually in the handler is misuse of the internal API.

```php
// Source: vendor/symfony/messenger/EventListener/SendFailedMessageForRetryListener.php L117-146
// A plain RuntimeException subclass is retried per the transport's retry_strategy.

final class SharedEntityAsyncFanOutException extends \RuntimeException
{
    // No constructor needed — use RuntimeException default.
    // The message should include: failed tenant count + per-tenant error summary.
}
```

[VERIFIED: vendor/symfony/messenger/EventListener/SendFailedMessageForRetryListener.php]

### Pattern 3: Unrouted Message Falls Through to Handler (D-03 Verification)

**What:** When `SharedEntityChangedMessage` is NOT mapped in `framework.messenger.routing`, `SendMessageMiddleware` finds no sender (`$sender = null`) and calls `$stack->next()->handle($envelope, $stack)` — execution falls through to `HandleMessageMiddleware`, which invokes the handler inline. No exception, no error — the handler simply runs synchronously in the HTTP request.

**Confirmed from source:**

```php
// vendor/symfony/messenger/Middleware/SendMessageMiddleware.php L74-79
if (null === $sender) {
    return $stack->next()->handle($envelope, $stack);  // ← falls through to HandleMessageMiddleware
}
// message should only be sent and not be handled by the next middleware
return $envelope;  // ← only reached if message WAS sent to a transport
```

[VERIFIED: vendor/symfony/messenger/Middleware/SendMessageMiddleware.php L74-79]

This means D-03's claim "unrouted message executes synchronously on the bus — still correct, just not deferred" is correct. The planner should document this prominently: `SharedEntityChangedMessage` MUST be routed to an async transport for the "HTTP returns immediately" promise to hold.

### Pattern 4: SyncTransport Does NOT Serialize — Canary Strategy Change

**What:** `SyncTransport::send()` re-dispatches the envelope directly on the message bus without any PhpSerializer encode→decode step. The Phase 20 `AsyncCanaryTest` docblock's mention of "PhpSerializer encode→decode" is accurate for the mailer test because the Email object's `__serialize()` behavior is relevant to header survival. For `SharedEntityChangedMessage` (which carries only scalars), serialization is trivially correct and NOT the primary canary assertion.

**What the SHARE-03 canary MUST assert instead:**

1. With `tenancy.shared.async=true`, `postFlush` dispatches one `SharedEntityChangedMessage` per changed entity (NOT the sync fan-out).
2. The handler is invoked by the bus (via `sync://` transport's re-dispatch).
3. The handler reaches ALL tenant EMs with the correct change (assert per-tenant DB state).
4. The handler NEVER writes to the wrong tenant EM.
5. With `tenancy.shared.async=false`, `postFlush` performs sync fan-out (subscriber behavior unchanged, NO dispatch).
6. Vanished-row → delete propagation: dispatch an insert message, delete the landlord row before handling, assert handler deletes the tenant copies.

**SyncTransport re-dispatch flow:**

```
bus->dispatch(SharedEntityChangedMessage) [HTTP request]
  → TenantSendingMiddleware [note: message has no tenant context — it fans to ALL tenants]
  → SendMessageMiddleware → SyncTransport::send()
      → bus->dispatch(envelope.with(ReceivedStamp)) [re-enters bus]
          → TenantWorkerMiddleware [sees NO TenantStamp → passes through, no context set]
          → HandleMessageMiddleware → SharedEntityChangedMessageHandler::__invoke()
              → landlordEm->find() + per-tenant loop
```

Note: `TenantSendingMiddleware` will NOT add a `TenantStamp` to `SharedEntityChangedMessage` IF no tenant context is active at dispatch time (D-01: dispatch happens in `postFlush` after `restoreTenantContext()` restores null or the previous tenant). If a tenant IS active at dispatch time (landlord request had a tenant context), `TenantSendingMiddleware` would add a stamp — but `TenantWorkerMiddleware` processes the stamp by setting that tenant's context and running `BootstrapperChain::boot()`, which is wrong for a message that fans to ALL tenants. The subscriber MUST clear tenant context before dispatching, or dispatch in a context where no tenant is active. This is a planner item.

[VERIFIED: vendor/symfony/messenger/Transport/Sync/SyncTransport.php]

### Pattern 5: Compile-Time Guard Registration

**What:** The `SharedAsyncContractPass` must be registered in `TenancyBundle::build()` in a way that it can check the `tenancy.shared.async` container parameter. The existing pattern in `build()`:

```php
// Current build() - src/TenancyBundle.php L328-347
if (interface_exists(MessageBusInterface::class)) {
    $container->addCompilerPass(new MessengerMiddlewarePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1);
}
if (interface_exists(\Symfony\Component\Mailer\MailerInterface::class)) {
    $container->addCompilerPass(new MailerTransportContractPass());
}
if (interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
    $container->addCompilerPass(new SharedEntityMutualExclusionPass());
}
```

**For `SharedAsyncContractPass`, the registration condition is:** guard is needed when Doctrine is present (the shared-entity stack is wired) AND the pass needs to check whether `tenancy.shared.async=true` with Messenger absent. This means the pass should be registered inside the `interface_exists(EntityManagerInterface::class)` block (like `SharedEntityMutualExclusionPass`) but the pass itself short-circuits if `tenancy.shared.async=false`.

**CONTEXT.md flags this as a planner-confirm item:** "register it unconditionally and let it short-circuit, OR register it and check the parameter." Based on the `MailerTransportContractPass` pattern (registered when Mailer present, short-circuits when parameter not found), the cleanest approach for `SharedAsyncContractPass` is:

- Register it inside `interface_exists(EntityManagerInterface::class)` (Doctrine must be present for the shared-entity stack to exist at all).
- The pass: `if (!$container->hasParameter('tenancy.shared.async') || !$container->getParameter('tenancy.shared.async')) { return; }` — short-circuit when async is disabled.
- Then: `if (!interface_exists(MessageBusInterface::class)) { throw new \LogicException(...); }` — fail loud when async=true + no Messenger.

This pattern mirrors `MailerTransportContractPass::process()` L41-46 exactly (returns early when dependency absent / parameter not set / flag false).

[VERIFIED: src/DependencyInjection/Compiler/MailerTransportContractPass.php, src/TenancyBundle.php L328-347]

### Pattern 6: SharedEntityChangedMessage Shape

**What:** Pure scalar value object. Must be PHP-serializable through PhpSerializer (though SHARE-03 canary with `sync://` doesn't actually serialize — see Pattern 4). Carries only what is needed for the handler to re-fetch and apply:

```php
// Namespace: Tenancy\Bundle\Message (following bundle's established class structure)
final class SharedEntityChangedMessage
{
    /**
     * @param class-string           $entityClass Fully-qualified class name of the #[Shared] entity
     * @param array<string, mixed>   $identifier  Scalar identifier values from getIdentifierValues()
     *                                            (pre-captured in onFlush for deletes)
     * @param 'insert'|'update'|'delete' $changeType  Change type from the landlord UoW
     */
    public function __construct(
        public readonly string $entityClass,
        public readonly array $identifier,
        public readonly string $changeType,
    ) {}
}
```

The `identifier` array matches `SharedEntityCopier`'s existing `getIdentifierValues()` scalar-array shape exactly (reuse what is already captured in `onFlush`'s `pendingChanges['ids']` for deletes, and `$landlordMeta->getIdentifierValues($entity)` for inserts/updates).

[CITED: src/Subscriber/SharedEntitySyncSubscriber.php L60-74 (pendingChanges shape), src/Shared/SharedEntityCopier.php L67-68 (identifier capture)]

### Pattern 7: postFlush Branch

**What:** The subscriber gains a nullable `?MessageBusInterface $bus` constructor arg. The branch in `postFlush` is clean:

```php
// After the shared_db short-circuit and pendingChanges check:
if (null !== $this->bus) {  // async mode
    foreach ($changes as $change) {
        $ids = $change['ids'] ?? $landlordEm->getClassMetadata($change['entity']::class)
                                              ->getIdentifierValues($change['entity']);
        $this->bus->dispatch(new SharedEntityChangedMessage(
            $change['entity']::class,
            $ids,
            $change['type'],
        ));
    }
    return;
}
// sync path: existing fan-out loop (unchanged)
```

Note: the `$landlordEm` is available in `postFlush` via `$args->getObjectManager()`. For insert/update, `getIdentifierValues()` is still populated at `postFlush` time. For delete, the pre-captured `$change['ids']` must be used (Doctrine zeroes the entity identifier before `postFlush`). The message always receives a scalar identifier array — for delete this is the pre-captured array, for insert/update this is fetched from the entity directly.

[CITED: src/Subscriber/SharedEntitySyncSubscriber.php L63-73 (delete pre-capture), L101 (postFlush `$args->getObjectManager()`)]

### Anti-Patterns to Avoid

- **Using `#[AsMessageHandler]` on the handler class without explicit DI wiring:** The attribute is processed only through autoconfigure. In this bundle's explicit-tag convention, the attribute has no effect. Always use explicit `->tag('messenger.message_handler', ['handles' => ...])`.
- **Constructing `HandlerFailedException` manually:** This is an internal Messenger class wrapping per-handler exceptions. The bundle's aggregate exception is `\RuntimeException` subclass. `HandlerFailedException` is what Messenger wraps around handler throws internally — do not double-wrap.
- **Dispatching `SharedEntityChangedMessage` with a `TenantStamp`:** This message fans to ALL tenants; stamping it with a specific tenant causes `TenantWorkerMiddleware` to boot the bootstrapper chain for that single tenant, silently overriding the handler's own per-tenant context-switching. The subscriber must dispatch WITHOUT a TenantStamp.
- **Passing the full entity object in the message:** Doctrine entities hold references to the EntityManager and proxy infrastructure. Passing them in a message breaks Messenger serialization and violates the message-queue size discipline in the acceptance criteria.
- **Re-using `SharedEntityCopier::applyRow()` directly with the entity object from the message:** The handler must re-fetch the entity from the landlord EM at handle time — it cannot use the message's `entityClass` + `identifier` to somehow reconstitute the live entity object. The flow is: `landlordEm->find($class, $identifier)` → if null AND type != delete → propagate delete; else → `copier->applyRow(landlordEm, tenantEm, $entity, $effectiveType, $capturedIds)`.
- **Skipping the `TenantSendingMiddleware` concern:** If a tenant is active in the landlord request when `postFlush` fires (possible — the request may belong to a tenant), `TenantSendingMiddleware` would add a `TenantStamp` to the message. The subscriber's `postFlush` must be aware of this. The safest approach: the handler implementation ignores TenantStamp (since the handler sets tenant context per its own loop), and `TenantWorkerMiddleware` passes through cleanly when it processes the stamp. But the `BootstrapperChain::boot()` side-effect in the worker middleware should be considered.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Per-tenant upsert + delete | Custom handler copy logic | `SharedEntityCopier::applyRow()` | Phase 26 D-02: single source of truth; PK-preservation trick, syncInProgress re-entrancy, find-or-new idempotency all handled; duplicating would drift |
| Handler registration | `MessageHandlerInterface` (removed in Messenger 7.0) | Explicit `->tag('messenger.message_handler')` | `MessageHandlerInterface` was removed in Symfony 7.0 (CHANGELOG.md L55) |
| Custom retry strategy | Application-level retry loop inside handler | Throw `\RuntimeException` subclass and let transport `retry_strategy` drive retries | Messenger's built-in exponential backoff + dead-letter is the entire point of async mode |
| Tenant context switching | Custom `setTenant` + connection-close logic in handler | Reuse `switchToTenant()` + `restoreTenantContext()` mechanics from subscriber | These methods handle DBAL `close()` + `resetManager('tenant')` correctly; missing `close()` causes wrong-tenant queries silently |
| Serialization-survival test | Hand-crafted encode/decode assertions | `sync://` transport canary with actual handler invocation | The canary's value is asserting handler reach + per-tenant DB state, not low-level serialization |

---

## Runtime State Inventory

> Not applicable — this phase adds new code and config. No rename or migration. No stored data needs updating.

---

## Common Pitfalls

### Pitfall 1: TenantSendingMiddleware Stamp Pollution

**What goes wrong:** `SharedEntitySyncSubscriber::postFlush()` fires in the landlord HTTP request. If the landlord request has an active tenant context (e.g., a SaaS admin acting as a tenant), `TenantSendingMiddleware` stamps the dispatched `SharedEntityChangedMessage` with a `TenantStamp`. In the `sync://` test transport (and in production worker), `TenantWorkerMiddleware` then calls `BootstrapperChain::boot(tenant)` on that ONE tenant before the handler runs, and calls `BootstrapperChain::clear()` after — potentially closing the tenant connection that the handler's own loop needs. For a message that fans to ALL tenants, this is wrong.

**Why it happens:** `TenantSendingMiddleware` stamps any dispatched message when a tenant is in context. It doesn't know this particular message should not carry a stamp.

**How to avoid:** Two valid approaches: (a) clear `TenantContext` before dispatching, dispatch, then restore (the `previousTenant` save/restore pattern already exists in `postFlush` for the sync path); (b) let the stamp exist but make the handler's logic work correctly with or without a pre-restored tenant. The cleaner solution (a) is preferred for correctness — the subscriber saves `previousTenant` before the loop, and for async dispatch it should clear context before calling `$this->bus->dispatch()`. The handler's own loop re-sets context per tenant, so the handler doesn't care what state TenantWorkerMiddleware set.

**Warning signs:** If the integration test shows only one tenant getting updated when two exist, the stamp is limiting handler execution to the stamped tenant's context.

**Confirmed source:** `TenantSendingMiddleware::handle()` — stamps when `null === $envelope->last(TenantStamp::class) && null !== $tenant`. [VERIFIED: src/Messenger/TenantSendingMiddleware.php L19-25]

### Pitfall 2: SyncTransport Does NOT Exercise PhpSerializer

**What goes wrong:** Test plan copies the Phase 20 canary comment and asserts "message survived PhpSerializer encode→decode." With `sync://`, there is no serialization step.

**Why it happens:** The Phase 20 `AsyncCanaryTest` docblock says "The 'sync://' transport still runs PhpSerializer encode→decode in-process." This was accurate for the mailer test context (the `Email` object's `__serialize()` was the thing being tested). For a plain scalar value object, there is nothing to serialize that would fail.

**How to avoid:** The SHARE-03 canary must assert handler-reach and DB state, not serialization. The primary assertions are: (1) shared entity was upserted/deleted on every tenant DB; (2) the wrong tenant's DB was not touched. If future testing against a real transport (AMQP, Doctrine) is desired, a separate test is needed.

**Warning signs:** An assertion like `assertSame(serialize($msg), unserialize(serialize($msg)))` is not load-bearing for this message class.

### Pitfall 3: Vanished-Row Handler Re-fetch Race

**What goes wrong:** The handler does `$landlordEm->find($class, $identifier)` and gets a stale identity-map hit (the entity was deleted between dispatch and handle-time, but the landlord EM's identity map still holds the old instance).

**Why it happens:** Doctrine's identity map returns cached instances by default. If the landlord EM was not reset between dispatch and handle time (in a long-running worker), the re-fetch may return a cached entity that no longer exists in the DB.

**How to avoid:** Before the re-fetch, call `$landlordEm->clear()` or `$landlordEm->detach($existing)` to ensure the find() triggers a real DB query. Alternatively, use `$landlordEm->refresh($entity)` pattern. The simplest approach: always call `$landlordEm->find()` with `$landlordEm->clear($class)` before in the handler. OR accept that in `sync://` (test) this is a non-issue since the handler runs immediately, and document the production concern.

**Warning signs:** Integration test with `sync://` passes, but a real async worker with a long-lived EM returns stale data.

### Pitfall 4: Handler Autoconfigure Not Working

**What goes wrong:** The handler class is defined with `#[AsMessageHandler]` but NOT registered as a service with `->tag('messenger.message_handler')` in `loadExtension()`. The message is dispatched but no handler fires — the bus routes it to the `sync` transport, `SyncTransport` re-dispatches, `HandleMessageMiddleware` finds zero handlers.

**Why it happens:** `#[AsMessageHandler]` only works when `autoconfigure: true` on the service definition. Bundle services are not autoconfigured (they use explicit DI registration). `registerAttributeForAutoconfiguration` in FrameworkBundle processes the attribute only on autoconfigured services.

**How to avoid:** Always use explicit `->tag('messenger.message_handler', ['handles' => SharedEntityChangedMessage::class])` in `TenancyBundle::loadExtension()`. The `#[AsMessageHandler]` attribute can be left on the class as documentation but must not be relied upon for tag registration.

**Warning signs:** Bus dispatches successfully, no exception, but per-tenant DBs have no changes.

### Pitfall 5: Missing `database.enabled` Guard for Handler Registration

**What goes wrong:** Handler is registered outside the `$databaseConfig['enabled']` block, causing it to be wired even when database-per-tenant mode is not enabled. The handler's constructor dependencies (landlord EM, doctrine manager registry) don't exist under shared_db or minimal mode.

**Why it happens:** Forgetting that the shared-entity stack (subscriber + copier + handler) is ONLY registered under the `if ($databaseConfig['enabled'] ?? false)` block in `loadExtension()`.

**How to avoid:** The handler registration must be inside the same `if ($databaseConfig['enabled'] && interface_exists(EntityManagerInterface))` block as the subscriber and copier. Double-guard with `interface_exists(MessageBusInterface::class)` inside that block.

**Warning signs:** Container compilation error about missing `doctrine.orm.landlord_entity_manager` service in environments without `database.enabled: true`.

---

## Code Examples

### SharedEntityChangedMessage (recommended shape)

```php
// src/Message/SharedEntityChangedMessage.php
declare(strict_types=1);

namespace Tenancy\Bundle\Message;

/**
 * Lightweight async message dispatched by SharedEntitySyncSubscriber when
 * tenancy.shared.async: true. Carries only what the handler needs to re-fetch
 * the current entity state from the landlord EM at handle time.
 *
 * IMPORTANT: the handler re-fetches the LATEST state from the landlord EM —
 * tenants see current state, NOT the state at dispatch time. If the landlord
 * row was deleted between dispatch and handle time, the handler propagates a
 * tenant-side delete (D-04).
 *
 * @see .planning/phases/27-async-shared-entities/27-CONTEXT.md §D-04, §D-05
 */
final class SharedEntityChangedMessage
{
    /**
     * @param class-string               $entityClass Fully-qualified class name of the #[Shared] entity
     * @param array<string, scalar|null> $identifier  Scalar identifier values (pre-captured in onFlush)
     * @param 'insert'|'update'|'delete' $changeType
     */
    public function __construct(
        public readonly string $entityClass,
        public readonly array $identifier,
        public readonly string $changeType,
    ) {
    }
}
```

[CITED: src/Subscriber/SharedEntitySyncSubscriber.php L60-74, src/Shared/SharedEntityCopier.php L97]

### Handler Skeleton

```php
// src/MessageHandler/SharedEntityChangedMessageHandler.php
declare(strict_types=1);

namespace Tenancy\Bundle\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Exception\SharedEntityAsyncFanOutException;
use Tenancy\Bundle\Message\SharedEntityChangedMessage;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\Shared\SharedEntityCopierInterface;
use Tenancy\Bundle\TenantInterface;

final class SharedEntityChangedMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $landlordEm,
        private readonly TenantProviderInterface $tenantProvider,
        private readonly SharedEntityCopierInterface $copier,
        private readonly TenantContext $tenantContext,
        private readonly ManagerRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SharedEntityChangedMessage $message): void
    {
        $class = $message->entityClass;
        $identifier = $message->identifier;
        $changeType = $message->changeType;

        // Re-fetch latest landlord state at handle time (D-05 landmine).
        // For delete messages, the entity is gone — fetch returns null; that's expected.
        $landlordEntity = ('delete' !== $changeType)
            ? $this->landlordEm->find($class, $identifier)
            : null;

        // D-04: vanished row for an insert/update → propagate delete.
        $effectiveType = $changeType;
        if (null === $landlordEntity && 'delete' !== $changeType) {
            $this->logger->warning('tenancy.shared_entity_async_vanished_row', [
                'entity_class' => $class,
                'identifier' => $identifier,
                'original_type' => $changeType,
            ]);
            $effectiveType = 'delete';
        }

        $tenants = iterator_to_array($this->tenantProvider->findAll());
        $failures = [];
        $previousTenant = $this->tenantContext->hasTenant() ? $this->tenantContext->getTenant() : null;

        try {
            foreach ($tenants as $tenant) {
                $tenantEm = $this->switchToTenant($tenant);
                try {
                    $this->copier->applyRow(
                        $this->landlordEm,
                        $tenantEm,
                        // For delete: pass a minimal stand-in or the null — copier uses capturedIds
                        $landlordEntity ?? new \stdClass(), // handler must adapt for delete path
                        $effectiveType,
                        'delete' === $effectiveType ? $identifier : null,
                    );
                } catch (\Throwable $e) {
                    $this->logger->error('tenancy.shared_entity_async_fan_out_failed', [
                        'tenant_slug' => $tenant->getSlug(),
                        'entity_class' => $class,
                        'identifier' => $identifier,
                        'error' => $e->getMessage(),
                    ]);
                    $failures[] = $tenant->getSlug();
                    $this->registry->resetManager('tenant');
                }
            }
        } finally {
            $this->restoreTenantContext($previousTenant);
        }

        // D-02: throw to trigger Messenger retry if any tenant failed.
        if ([] !== $failures) {
            throw new SharedEntityAsyncFanOutException(sprintf(
                'Async shared entity fan-out failed for %d tenant(s): %s. Message will be retried per transport retry_strategy.',
                count($failures),
                implode(', ', $failures)
            ));
        }
    }

    // ... switchToTenant() and restoreTenantContext() — extract shared helper or duplicate from subscriber
}
```

Note: the `applyRow()` call with `$landlordEntity ?? new \stdClass()` is a placeholder. For the delete path, `SharedEntityCopier::applyRow()` checks `type === 'delete'` and uses `$capturedIds` — it does NOT use the `$entity` parameter in the delete branch (only calls `$tenantEm->find($class, $capturedIds)`). The planner should confirm that passing a dummy `\stdClass` for the entity on delete is safe, or restructure `applyRow()` to accept `?object $entity` with null allowed on delete.

[CITED: src/Shared/SharedEntityCopier.php L69-94]

### Compile-Time Guard Skeleton

```php
// src/DependencyInjection/Compiler/SharedAsyncContractPass.php
declare(strict_types=1);

namespace Tenancy\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\MessageBusInterface;

final class SharedAsyncContractPass implements CompilerPassInterface
{
    private const ASYNC_PARAM = 'tenancy.shared.async';

    public function process(ContainerBuilder $container): void
    {
        // Short-circuit if the parameter isn't set (Doctrine not wired / shared stack absent).
        if (!$container->hasParameter(self::ASYNC_PARAM)) {
            return;
        }

        // Short-circuit if async is disabled.
        if (!(bool) $container->getParameter(self::ASYNC_PARAM)) {
            return;
        }

        // async=true requires Messenger.
        if (!interface_exists(MessageBusInterface::class)) {
            throw new \LogicException(
                'tenancy: tenancy.shared.async: true requires symfony/messenger. '.
                'Install it (composer require symfony/messenger) or set tenancy.shared.async: false.'
            );
        }
    }
}
```

Registration in `TenancyBundle::build()` inside the `interface_exists(EntityManagerInterface)` block:

```php
// Alongside SharedEntityMutualExclusionPass
if (interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
    $container->addCompilerPass(new SharedEntityMutualExclusionPass());
    $container->addCompilerPass(new SharedAsyncContractPass());
}
```

[CITED: src/DependencyInjection/Compiler/MailerTransportContractPass.php L39-46, src/TenancyBundle.php L345-347]

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `MessageHandlerInterface` / `MessageSubscriberInterface` | `#[AsMessageHandler]` attribute OR explicit `messenger.message_handler` tag | Symfony 7.0 (removed interfaces) | Cannot use old interfaces; must use attribute (autoconfigured) or explicit tag (this bundle) |
| `HandlerFailedException::getNestedExceptions()` | `HandlerFailedException::getWrappedExceptions()` | Symfony 7.0 (removed old method) | If test code inspects handler exceptions, use `getWrappedExceptions()` not the removed getter |
| `InMemoryTransport` (top-level namespace) | `Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport` | Symfony 7.0 | Tests using `InMemoryTransport` must use the new namespace |

**Deprecated/outdated:**

- `MessageHandlerInterface` and `MessageSubscriberInterface`: removed in Symfony 7.0 (CHANGELOG L55). Do not reference.
- `HandlerFailedException::getNestedExceptions()`: removed in Symfony 7.0. Use `getWrappedExceptions()`.

[VERIFIED: vendor/symfony/messenger/CHANGELOG.md L55, L63]

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `SharedEntityCopier::applyRow()` is safe to call with a dummy entity object on delete (only uses `$capturedIds` in that branch) | Code Examples | If copier calls `$entity::class` before the delete-type check, a `\stdClass` would return wrong class name — handler would need to reconstitute a real entity stub or restructure the call | 
| A2 | Clearing `TenantContext` before dispatching in `postFlush` prevents `TenantSendingMiddleware` stamp pollution (Pitfall 1) | Common Pitfalls | If clear+dispatch+restore has thread-safety or ordering issues in the subscriber, stamp may still be added from a concurrent request (PHP-FPM is process-isolated, so this is not actually a risk) |
| A3 | The `sync://` transport's re-dispatch flow means `TenantWorkerMiddleware` runs before the handler | Architecture Diagram | If middleware ordering differs in some Symfony/Messenger version, the tenant-context state entering the handler may differ — but verified against `SyncTransport::send()` which re-dispatches the full envelope on the bus with all middleware |

---

## Open Questions (RESOLVED)

> All three resolved during planning; resolutions are recorded in the plan actions cited below.

1. **Handler's applyRow() call signature for delete path** — **RESOLVED (27-02 Task 1):** added a `deleteRow(tenantEm, class, capturedIds)` helper to `SharedEntityCopierInterface` + `SharedEntityCopier` (the copier stays the single source of truth). The handler calls `deleteRow()` for the delete path (incl. vanished-row→delete) and `applyRow()` ONLY with a live re-fetched non-null entity — the `\stdClass` placeholder below is explicitly overridden and a `grep -c 'new \stdClass()' == 0` acceptance criterion enforces it.
   - What we know: `SharedEntityCopier::applyRow()` checks `type === 'delete'` first and uses `$capturedIds`, NOT the `$entity` parameter, for the delete path. The `$entity` param is typed as `object`, not nullable.
   - What's unclear: Can the handler pass `$landlordEm->find($class, $identifier)` (which returns null for a delete+vanished row) safely? The delete branch in `applyRow()` only calls `$tenantEm->find($class, $capturedIds)` — it never reads `$entity` on the delete path.
   - Recommendation: Planner should verify `applyRow()`'s delete branch (lines 69-94 of SharedEntityCopier.php) does not dereference `$entity` before the `type === 'delete'` check. If it does, the handler needs a minimal stub entity or the copier needs a `deleteRow(tenantEm, class, ids)` helper. Based on current code review, the delete branch does NOT dereference `$entity` before the early return — passing `null` would fail PHPStan L9 since the param is typed `object`.

2. **`switchToTenant()` / `restoreTenantContext()` sharing between subscriber and handler** — **RESOLVED (27-02 Task 3):** duplicate the ~30 lines in the handler with a comment naming the subscriber as the source-of-truth twin (keeps the change file-scoped to 27-02 and avoids touching the subscriber's proven CR-01/CR-02 internals). Extraction to a shared service was considered and deferred.
   - What we know: These private methods in `SharedEntitySyncSubscriber` contain non-trivial DBAL connection management logic (Phase 25 CR-01/CR-02).
   - What's unclear: Whether to extract them to a shared `TenantContextSwitcher` service or duplicate the ~30 lines in the handler.
   - Recommendation: Extract to a shared service (`TenantContextSwitcher`) that both the subscriber and handler inject. Avoids drift between two copies of the DBAL-close + resetManager logic.

3. **Landlord EM identity-map staleness in long-running workers** — **RESOLVED (27-02 Task 3):** the handler calls `$landlordEm->clear($class)` before `find()`, structurally enforced by a `grep -Pzoq '(?s)->clear\(.*?->find\('` acceptance criterion (proves the ordering independent of any test passing).
   - What we know: `$landlordEm->find($class, $identifier)` may return a stale cached entity in a worker that processed many messages.
   - What's unclear: Whether to call `$landlordEm->clear($class)` before the re-fetch in the handler.
   - Recommendation: The handler should call `$landlordEm->clear($class)` (or `$landlordEm->refresh($entity)` if the entity is found) before trusting the re-fetch result. Document as a known production concern.

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.2+ | `readonly` properties in message, handler | PHP 8.5.6 | 8.5.6 | — |
| symfony/messenger | Async dispatch + handler infrastructure | Installed (require-dev) | v8.0.8 | Skip if not installed (guarded) |
| doctrine/orm | Landlord EM re-fetch in handler | Installed (optional dep) | (in vendor) | Skip if absent (guarded) |
| vendor/bin/phpunit | Test execution | PHPUnit 11.5.55 | 11.5.55 | — |
| vendor/bin/phpstan | Static analysis L9 | PHPStan 2.1.50 | 2.1.50 | — |
| vendor/bin/php-cs-fixer | Code style (@Symfony ruleset) | 3.95.1 | 3.95.1 | — |
| SQLite (PDO) | Integration tests (MemoryDB tenants) | Bundled with PHP | PHP 8.5.6 | — |

**Missing dependencies with no fallback:** None.

---

## Validation Architecture

> `workflow.nyquist_validation: true` — section required.

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5.55 |
| Config file | `phpunit.xml.dist` |
| Quick run command | `vendor/bin/phpunit --testsuite unit` |
| Full suite command | `vendor/bin/phpunit` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| SHARE-03-a | `async=true` causes `postFlush` to dispatch messages (not fan-out) | Unit | `vendor/bin/phpunit --testsuite unit --filter testPostFlushDispatchesWhenAsyncEnabled` | ❌ Wave 0 |
| SHARE-03-b | `async=false` (default) causes sync fan-out, no dispatch | Unit | `vendor/bin/phpunit --testsuite unit --filter testPostFlushUsesyncFanOutWhenAsyncDisabled` | ❌ Wave 0 |
| SHARE-03-c | `SharedEntityChangedMessage` carries class+identifier+change-type (NOT full entity) | Unit | `vendor/bin/phpunit --testsuite unit --filter testMessageCarriesOnlyScalars` | ❌ Wave 0 |
| SHARE-03-d | Handler re-fetches landlord state at handle time; tenants see LATEST state | Integration | `vendor/bin/phpunit --testsuite integration --filter testHandlerRefetchesLatestLandlordState` | ❌ Wave 0 |
| SHARE-03-e | Vanished landlord row at handle time → handler propagates tenant-side delete (D-04) | Integration | `vendor/bin/phpunit --testsuite integration --filter testVanishedRowPropagatesToTenantDelete` | ❌ Wave 0 |
| SHARE-03-f | Handler fans out to ALL tenants | Integration | `vendor/bin/phpunit --testsuite integration --filter testHandlerFansOutToAllTenants` | ❌ Wave 0 |
| SHARE-03-g | Handler: any tenant failure throws aggregate exception (Messenger retries) | Integration | `vendor/bin/phpunit --testsuite integration --filter testHandlerThrowsOnTenantFailure` | ❌ Wave 0 |
| SHARE-03-h | Handler: idempotent re-apply on retry (copier is find-or-new) | Integration | `vendor/bin/phpunit --testsuite integration --filter testHandlerIdempotentOnRetry` | ❌ Wave 0 |
| SHARE-03-i | Compile-time guard throws when `async=true` and Messenger absent | Unit (compiler pass test) | `vendor/bin/phpunit --testsuite unit --filter testGuardThrowsWhenMessengerAbsent` | ❌ Wave 0 |
| SHARE-03-j | Messenger transport round-trip canary (async canary) | Integration | `vendor/bin/phpunit --testsuite integration --filter testAsyncRoundTripCanary` | ❌ Wave 0 |

### Sampling Rate

- **Per task commit:** `vendor/bin/phpunit --testsuite unit`
- **Per wave merge:** `vendor/bin/phpunit` (full suite)
- **Phase gate:** Full suite green + PHPStan level 9 + php-cs-fixer check before `/gsd:verify-work`

### Wave 0 Gaps

All test files for this phase are new. The following must be created before or alongside implementation:

- [ ] `tests/Unit/Subscriber/SharedEntitySyncSubscriberAsyncTest.php` — covers SHARE-03-a, SHARE-03-b (unit test with mock MessageBusInterface)
- [ ] `tests/Unit/Message/SharedEntityChangedMessageTest.php` — covers SHARE-03-c (instantiation, property access, serialize/unserialize)
- [ ] `tests/Unit/DependencyInjection/Compiler/SharedAsyncContractPassTest.php` — covers SHARE-03-i
- [ ] `tests/Integration/SharedEntity/SharedEntityAsyncCanaryTest.php` — covers SHARE-03-d, SHARE-03-e, SHARE-03-f, SHARE-03-g, SHARE-03-h, SHARE-03-j
- [ ] `tests/Integration/SharedEntity/Support/SharedEntityAsyncTestKernel.php` — kernel for async canary (FrameworkBundle + DoctrineBundle + TenancyBundle, `sync://` transport, `tenancy.shared.async: true`)
- [ ] `tests/Integration/SharedEntity/Support/MakeSharedEntityAsyncServicesPublicPass.php` — expose handler + bus for test inspection

---

## Security Domain

> `security_enforcement` not set to `false` — section required.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no | — |
| V3 Session Management | no | — |
| V4 Access Control | yes | The handler MUST NOT cross-contaminate tenant DBs. Per-tenant try/catch with `resetManager()` on failure ensures a failed tenant flush doesn't leave another tenant's connection in a bad state. The `switchToTenant()` + `restoreTenantContext()` pattern (already proven in Phase 25) provides the isolation boundary. |
| V5 Input Validation | no (internal messages only) | `SharedEntityChangedMessage` is an internal message (dispatched only by the bundle's own subscriber); external input is not accepted. The message's `entityClass` must be validated against Doctrine metadata in the handler (only known `#[Shared]` classes should be processed) to prevent injection of arbitrary class names. |
| V6 Cryptography | no | — |

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Cross-tenant data leak via worker | Information Disclosure | Handler iterates ALL tenants via `TenantProviderInterface::findAll()`; each tenant gets only its own data via `switchToTenant()`. No TenantStamp = no single-tenant shortcut. The `restoreTenantContext()` in `finally` ensures context is always cleared after the loop. |
| Stale landlord identity map returning wrong data | Tampering | Call `$landlordEm->clear($class)` before `find()` in the handler to force a real DB query at handle time. Documented landmine in SHARE-03 acceptance criteria. |
| Dead-letter message with sensitive entity state | Information Disclosure | Message carries only class+identifier+type — NOT the entity payload. Dead-letter inspection reveals only the entity class name and primary key, not its data. This is the explicit envelope-size discipline in SHARE-03 acceptance criteria. |
| Arbitrary-class message injection | Elevation of Privilege | Validate `$message->entityClass` against `$this->copier->findSharedClasses($landlordEm)` in the handler before proceeding. Any class not in the `#[Shared]` set should throw `UnrecoverableExceptionInterface` (not retry, not silently skip). |

---

## Sources

### Primary (HIGH confidence)

- `vendor/symfony/messenger/Attribute/AsMessageHandler.php` — confirmed attribute signature + `TARGET_CLASS|TARGET_METHOD`
- `vendor/symfony/framework-bundle/DependencyInjection/FrameworkExtension.php` L727-737 — confirmed `#[AsMessageHandler]` is processed via `registerAttributeForAutoconfiguration` (autoconfigure required)
- `vendor/symfony/messenger/Middleware/SendMessageMiddleware.php` L74-79 — confirmed unrouted message falls through to next middleware (handler inline)
- `vendor/symfony/messenger/Transport/Sync/SyncTransport.php` — confirmed no PhpSerializer in SyncTransport; re-dispatches envelope on bus directly
- `vendor/symfony/messenger/EventListener/SendFailedMessageForRetryListener.php` L117-146 — confirmed plain `\RuntimeException` subclass is retryable via `retryStrategy->isRetryable()`
- `vendor/symfony/messenger/Exception/HandlerFailedException.php` — confirmed `HandlerFailedException extends RuntimeException`; constructor takes exceptions array
- `vendor/symfony/messenger/CHANGELOG.md` L55 — confirmed `MessageHandlerInterface` removed in 7.0
- `src/Subscriber/SharedEntitySyncSubscriber.php` — confirmed `postFlush` buffer shape, `switchToTenant()` mechanics, `restoreTenantContext()` pattern
- `src/Shared/SharedEntityCopier.php` — confirmed delete branch does not dereference `$entity` before `capturedIds` check; `isSyncInProgress()` re-entrancy flag
- `src/DependencyInjection/Compiler/MailerTransportContractPass.php` — confirmed guard pattern (return early when absent/disabled, throw on misconfiguration)
- `src/TenancyBundle.php` L328-347 — confirmed `build()` compiler pass registration pattern; `interface_exists(EntityManagerInterface)` guard for `SharedEntityMutualExclusionPass`
- `tests/Integration/Mailer/AsyncCanaryTest.php` + `MailerTestKernel.php` — confirmed canary test kernel structure and assertion patterns
- `src/Messenger/TenantSendingMiddleware.php` — confirmed stamp-addition condition (`hasTenant()` check)
- `phpunit.xml.dist` — confirmed test suites (unit / integration)

### Secondary (MEDIUM confidence)

- `vendor/symfony/messenger/Exception/RecoverableExceptionInterface.php` — confirmed retry interface contract

### Tertiary (LOW confidence)

- None.

---

## Metadata

**Confidence breakdown:**

- Handler registration pattern: HIGH — verified against installed FrameworkExtension source
- Retry/failure exception contract: HIGH — verified against installed SendFailedMessageForRetryListener source
- SyncTransport behavior (no PhpSerializer): HIGH — verified against installed SyncTransport source
- Compile-time guard pattern: HIGH — verified against MailerTransportContractPass + TenancyBundle::build()
- Canary test structure: HIGH — verified against AsyncCanaryTest + MailerTestKernel
- TenantSendingMiddleware stamp-pollution pitfall: HIGH — verified against TenantSendingMiddleware source
- applyRow delete-branch entity-parameter safety (A1): MEDIUM — read the code but not exhaustively traced all execution paths

**Research date:** 2026-06-15
**Valid until:** 2026-07-15 (stable framework; 30-day horizon)
