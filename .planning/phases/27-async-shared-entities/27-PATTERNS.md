# Phase 27: Async Shared Entities - Pattern Map

**Mapped:** 2026-06-15
**Files analyzed:** 8 (2 new source files + 1 new compiler pass + 1 modified source file + 1 modified bundle + 1 integration test class + 1 test kernel + 1 make-public compiler pass)
**Analogs found:** 8 / 8

---

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `src/Message/SharedEntityChangedMessage.php` | value-object / message contract | event-driven | `src/Messenger/TenantStamp.php` (pure data carrier) | role-match |
| `src/MessageHandler/SharedEntityChangedMessageHandler.php` | handler / service | event-driven + CRUD fan-out | `src/Subscriber/SharedEntitySyncSubscriber.php` `postFlush()`+`applyChange()` | exact (same fan-out mechanics) |
| `src/DependencyInjection/Compiler/SharedAsyncContractPass.php` | compiler-pass | — | `src/DependencyInjection/Compiler/MailerTransportContractPass.php` | exact |
| `src/Subscriber/SharedEntitySyncSubscriber.php` *(modified)* | event-subscriber | event-driven + CRUD | self (existing) | self |
| `src/TenancyBundle.php` *(modified)* | bundle / DI config | — | self (existing — mirror `mailer`/`filesystem` node pattern) | self |
| `tests/Integration/SharedEntity/SharedEntityAsyncCanaryTest.php` | integration-test | event-driven + CRUD | `tests/Integration/Mailer/AsyncCanaryTest.php` | role-match |
| `tests/Integration/SharedEntity/Support/SharedEntityAsyncTestKernel.php` | test-kernel | — | `tests/Integration/SharedEntity/Support/SharedEntitySyncTestKernel.php` + `tests/Integration/Mailer/MailerTestKernel.php` | composite |
| `tests/Integration/SharedEntity/Support/MakeSharedEntityAsyncServicesPublicPass.php` | test-support compiler-pass | — | `tests/Integration/SharedEntity/Support/MakeSharedEntityServicesPublicPass.php` | exact |

---

## Pattern Assignments

### `src/Message/SharedEntityChangedMessage.php` (value-object, event-driven)

**Analog:** `src/Messenger/TenantStamp.php` — pure readonly data carrier with no deps.

**Imports pattern** — copy the `declare(strict_types=1)` + minimal namespace block style from `TenantStamp`:

```php
// src/Messenger/TenantStamp.php lines 1-12 (structure only — class name/body differs)
declare(strict_types=1);

namespace Tenancy\Bundle\Message;
```

**Core pattern** — pure `readonly` constructor promoted properties carrying only scalars. The `identifier` array shape comes from `SharedEntityCopier::applyRow()`'s `$capturedIds` param and from `$landlordMeta->getIdentifierValues($entity)` calls in the subscriber:

```php
// Derived from: src/Subscriber/SharedEntitySyncSubscriber.php lines 72-128
// $ids = $em->getClassMetadata($entity::class)->getIdentifierValues($entity);   (line 123)
// $this->pendingChanges[...] = ['entity' => $entity, 'type' => 'delete', 'ids' => $ids];  (line 124-128)
// The identifier shape is array<string, mixed> — scalar primary-key values only.

final class SharedEntityChangedMessage
{
    /**
     * @param class-string               $entityClass FQCN of the #[Shared] entity
     * @param array<string, mixed>       $identifier  Scalar PK values (pre-captured in onFlush for deletes;
     *                                               from getIdentifierValues() for insert/update)
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

**Critical constraint — NO full entity object:** `SharedEntityCopier::applyRow()` receives `object $entity` typed non-nullable (see `src/Shared/SharedEntityCopierInterface.php` lines 26-30). The message MUST carry only scalars. Doctrine entity objects hold EM references and break Messenger serialization.

---

### `src/MessageHandler/SharedEntityChangedMessageHandler.php` (handler, event-driven + CRUD fan-out)

**Analog:** `src/Subscriber/SharedEntitySyncSubscriber.php` — the `postFlush()` / `applyChange()` / `switchToTenant()` / `restoreTenantContext()` body is the nearly verbatim source for the handler's `__invoke()`.

**CRITICAL — applyRow() delete-path constraint (Open Question A1 RESOLVED):**
`SharedEntityCopier::applyRow()` dereferences `$entity::class` at line 66 BEFORE the `if ('delete' === $type)` check at line 69. Therefore `$entity` must be a real typed object (not null, not `\stdClass`) — passing null would fail PHPStan L9 since the param is `object`. The handler has two options for the delete path:
1. Call `$landlordEm->find($class, $identifier)` — but the entity is gone (deleted), so this returns null.
2. Use a class-based approach: since `$class` is known, the handler can call a hypothetical `deleteRow()` helper, OR it can call `applyRow()` only when it has a real `$entity` for upsert, and handle the delete branch directly via `$tenantEm->find($class, $capturedIds)` + `$tenantEm->remove()` + `flush()`.

**Recommended resolution (planner must confirm):** For `type=delete` (and for the vanished-row→delete case), bypass `applyRow()` entirely and replicate the 6-line delete sub-path from `SharedEntityCopier::applyRow()` lines 69-94 directly in the handler. This avoids the non-nullable `$entity` problem and is idempotent.

**Constructor + DI registration pattern** (from `src/TenancyBundle.php` lines 273-311):
```php
// Handler constructor — same dependency set as the subscriber + resync command:
// tenancy.context, tenancy.provider, doctrine (ManagerRegistry), logger,
// doctrine.orm.landlord_entity_manager, tenancy.shared_entity_copier

// Registration in TenancyBundle::loadExtension() — inside the
//   if ($databaseConfig['enabled'] ?? false) { if (interface_exists(EntityManagerInterface)) { ... } }
// block, further guarded by interface_exists(MessageBusInterface):
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
// NO #[AsMessageHandler] — autoconfigure is NOT used for this bundle's explicit-tag services
// (verified: FrameworkExtension.php L727 processes the attribute only for autoconfigured services)
```

**Fan-out core pattern** — copy from `src/Subscriber/SharedEntitySyncSubscriber.php` lines 151-188:
```php
// postFlush() lines 151-188 — the handler's __invoke() is this loop with modifications:
// 1. previousTenant save/restore pattern (lines 158-159 + finally lines 186-188)
// 2. tenants materialized once (lines 167-170) — iterator_to_array() equivalent
// 3. switchToTenant() per tenant (line 176)
// 4. per-change apply with error recovery (line 183 → applyChange())

$previousTenant = $this->tenantContext->hasTenant() ? $this->tenantContext->getTenant() : null;
$tenants = [];
foreach ($this->tenantProvider->findAll() as $tenant) {
    $tenants[] = $tenant;
}
try {
    foreach ($tenants as $tenant) {
        $tenantEm = $this->switchToTenant($tenant);
        // ... apply change per tenant (best-effort with per-tenant try/catch)
    }
} finally {
    $this->restoreTenantContext($previousTenant);
}
```

**switchToTenant() pattern** — copy from `src/Subscriber/SharedEntitySyncSubscriber.php` lines 198-215:
```php
// src/Subscriber/SharedEntitySyncSubscriber.php lines 198-215
private function switchToTenant(TenantInterface $tenant): EntityManagerInterface
{
    $this->tenantContext->setTenant($tenant);

    $tenantConn = $this->registry->getConnection('tenant');
    if ($tenantConn instanceof \Doctrine\DBAL\Connection) {
        $tenantConn->close();
    }

    /** @var EntityManagerInterface $tenantEm */
    $tenantEm = $this->registry->resetManager('tenant');

    return $tenantEm;
}
```

**restoreTenantContext() pattern** — copy from `src/Subscriber/SharedEntitySyncSubscriber.php` lines 282-295:
```php
// src/Subscriber/SharedEntitySyncSubscriber.php lines 282-295
private function restoreTenantContext(?TenantInterface $previousTenant): void
{
    if (null !== $previousTenant) {
        $this->tenantContext->setTenant($previousTenant);
    } else {
        $this->tenantContext->clear();
    }

    $tenantConn = $this->registry->getConnection('tenant');
    if ($tenantConn instanceof \Doctrine\DBAL\Connection) {
        $tenantConn->close();
    }
    $this->registry->resetManager('tenant');
}
```

**Structured failure logging pattern** — copy from `src/Subscriber/SharedEntitySyncSubscriber.php` lines 254-260 (the `applyChange()` catch block). Handler THROWS after the loop (D-02), unlike subscriber which swallows:
```php
// src/Subscriber/SharedEntitySyncSubscriber.php lines 254-260 — reuse same log keys:
$this->logger->error('tenancy.shared_entity_sync_failed', [
    'tenant_slug' => $tenant->getSlug(),
    'entity_class' => $entity::class,
    'identifier' => $identifier,
    'error' => $e->getMessage(),
]);
// Handler uses: 'tenancy.shared_entity_async_fan_out_failed' (same shape, different key)
// After loop: if ($failures !== []) { throw new SharedEntityAsyncFanOutException(...); }
```

**CRITICAL — TenantSendingMiddleware stamp-pollution (Pitfall 1 from RESEARCH):** Before dispatching in `postFlush()`, `TenantContext` MUST be cleared to prevent `TenantSendingMiddleware` from stamping the message with the current tenant. Pattern: save `$previousTenant` → `$tenantContext->clear()` → dispatch → restore. This is the same save/restore pattern used by the sync fan-out loop, just applied to the dispatch itself.

**Aggregate exception** — new `final class SharedEntityAsyncFanOutException extends \RuntimeException`. Plain `\RuntimeException` subclass with no extra interface — Messenger's `SendFailedMessageForRetryListener` routes it through `retryStrategy->isRetryable()` (verified: `vendor/symfony/messenger/EventListener/SendFailedMessageForRetryListener.php` lines 117-146). Do NOT construct `HandlerFailedException` manually.

---

### `src/DependencyInjection/Compiler/SharedAsyncContractPass.php` (compiler-pass)

**Analog:** `src/DependencyInjection/Compiler/MailerTransportContractPass.php` — exact structural template.

**Full MailerTransportContractPass::process() pattern** (`src/DependencyInjection/Compiler/MailerTransportContractPass.php` lines 39-68):
```php
// Lines 39-68 — the guard structure to mirror:
public function process(ContainerBuilder $container): void
{
    if (!interface_exists(\Symfony\Component\Mailer\MailerInterface::class)) {
        return;   // short-circuit when optional dep absent
    }

    if (!$container->hasParameter(self::ASYNC_PARAM)) {
        throw new \LogicException(sprintf(
            'tenancy: parameter "%s" must be declared ...', self::ASYNC_PARAM
        ));
    }
    // ... parse param, short-circuit on false, throw on misconfiguration
}
```

**SharedAsyncContractPass adaptation** — three-stage guard (mirrors MailerTransportContractPass but for Messenger absence, not service absence):
```php
// Stage 1: short-circuit when shared-entity stack is absent (Doctrine not installed,
//          or database.enabled: false — parameter never set):
if (!$container->hasParameter('tenancy.shared.async')) {
    return;
}

// Stage 2: short-circuit when async is disabled (no guard needed):
if (!(bool) $container->getParameter('tenancy.shared.async')) {
    return;
}

// Stage 3: async=true requires Messenger — fail loud at build time:
if (!interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
    throw new \LogicException(
        'tenancy: tenancy.shared.async: true requires symfony/messenger. '.
        'Install it (composer require symfony/messenger) or set tenancy.shared.async: false.'
    );
}
```

**Registration in `TenancyBundle::build()`** — inside the `interface_exists(EntityManagerInterface)` block, alongside `SharedEntityMutualExclusionPass`:
```php
// src/TenancyBundle.php lines 345-347 — ADD SharedAsyncContractPass here:
if (interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
    $container->addCompilerPass(new SharedEntityMutualExclusionPass());
    $container->addCompilerPass(new SharedAsyncContractPass());  // NEW
}
```

---

### `src/Subscriber/SharedEntitySyncSubscriber.php` *(modified)*

**Analog:** self (the existing file is the pattern source).

**Constructor modification** — add `?MessageBusInterface $bus` as nullable arg (D-07). The subscriber must remain constructible when Messenger is absent and async is false:
```php
// Current constructor: src/Subscriber/SharedEntitySyncSubscriber.php lines 76-84
public function __construct(
    private readonly TenantContext $tenantContext,
    private readonly TenantProviderInterface $tenantProvider,
    private readonly ManagerRegistry $registry,
    private readonly LoggerInterface $logger,
    private readonly string $driver,
    private readonly SharedEntityCopier $copier,
    // NEW (D-07): nullable — absent when Messenger not installed:
    private readonly ?MessageBusInterface $bus = null,
) {
}
```

**postFlush() async branch** — insert AFTER the `shared_db` short-circuit (lines 144-149) and AFTER clearing `$this->pendingChanges` (line 152). The branch dispatches then returns early, leaving the sync path below it intact:
```php
// INSERT after line 152 ($changes = $this->pendingChanges; $this->pendingChanges = []):
if (null !== $this->bus) {
    // D-01: dispatch one message per changed entity (NOT one per tenant).
    // CRITICAL (Pitfall 1): clear tenant context before dispatch to prevent
    // TenantSendingMiddleware from stamping this fan-to-all-tenants message
    // with the current tenant slug.
    $previousTenant = $this->tenantContext->hasTenant() ? $this->tenantContext->getTenant() : null;
    if (null !== $previousTenant) {
        $this->tenantContext->clear();
    }
    try {
        foreach ($changes as $change) {
            $entity = $change['entity'];
            $type = $change['type'];
            // For insert/update: identifier from entity (still set at postFlush time).
            // For delete: use pre-captured ids (Doctrine zeroed the entity's identifier).
            $ids = 'delete' === $type
                ? ($change['ids'] ?? [])
                : $args->getObjectManager()->getClassMetadata($entity::class)->getIdentifierValues($entity);
            $this->bus->dispatch(new SharedEntityChangedMessage($entity::class, $ids, $type));
        }
    } finally {
        if (null !== $previousTenant) {
            $this->tenantContext->setTenant($previousTenant);
        }
    }

    return;
}
// sync path: existing lines 159-188 unchanged
```

**DI wiring update** in `TenancyBundle::loadExtension()` — add `?MessageBusInterface` arg and `tenancy.shared.async` parameter. Existing wiring at `src/TenancyBundle.php` lines 280-290:
```php
// EXISTING (src/TenancyBundle.php lines 280-290):
$services->set('tenancy.shared_entity_sync_subscriber', SharedEntitySyncSubscriber::class)
    ->args([
        service('tenancy.context'),
        service('tenancy.provider'),
        service('doctrine'),
        service('logger'),
        param('tenancy.driver'),
        service('tenancy.shared_entity_copier'),
        // ADD (D-07): nullable bus arg — null when Messenger absent or async=false:
        // service('messenger.bus.default')->nullOnInvalid(),
        // BUT: only wire the bus when async is enabled AND Messenger is present.
        // Planner: inject conditionally (param-driven null reference) or always inject
        // as nullOnInvalid() since the guard (SharedAsyncContractPass) prevents
        // async=true + no-Messenger from reaching runtime.
    ])
    ->tag('doctrine.event_listener', ['event' => 'onFlush', 'connection' => 'landlord'])
    ->tag('doctrine.event_listener', ['event' => 'postFlush', 'connection' => 'landlord']);
```

---

### `src/TenancyBundle.php` *(modified)*

**Analog:** self — mirrors the existing `mailer`/`filesystem` node patterns.

**`configure()` — new `tenancy.shared` array node** — insert after the `filesystem` node (lines 113-121), before the closing `->end()` of the root node:
```php
// PATTERN: src/TenancyBundle.php lines 100-121 (mailer and filesystem nodes)
// mailer node (lines 100-112):
->arrayNode('mailer')
->addDefaultsIfNotSet()
->children()
->integerNode('transport_cache_size')->defaultValue(32)->min(1)->end()
->scalarNode('async')
    ->defaultValue('auto')
    // ... validate
->end()
->end()
->end()

// filesystem node (lines 113-121):
->arrayNode('filesystem')
->addDefaultsIfNotSet()
->children()
->booleanNode('enabled')->defaultFalse()->end()
// ...
->end()
->end()

// NEW tenancy.shared node — D-06: plain boolean, NOT mailer tri-state:
->arrayNode('shared')
->addDefaultsIfNotSet()
->children()
->booleanNode('async')->defaultFalse()->end()
->end()
->end()
```

**`loadExtension()` — config→parameter flow** — mirror the mailer/filesystem extraction pattern (lines 155-185):
```php
// PATTERN: src/TenancyBundle.php lines 155-185 (mailerConfig extraction → parameter set)
/** @var array<string, mixed> $sharedConfig */
$sharedConfig = $config['shared'] ?? [];
$sharedAsync = (bool) ($sharedConfig['async'] ?? false);

// Inside $container->parameters()->set(...) chain (lines 172-185):
->set('tenancy.shared.async', $sharedAsync)
```

**NOTE:** `tenancy.shared.async` parameter is set UNCONDITIONALLY (not gated on `database.enabled`) so the `SharedAsyncContractPass` can always find it and short-circuit gracefully. The database.enabled gate controls service wiring (below), not the parameter.

**`loadExtension()` — message + handler wiring** — insert inside the `if (interface_exists(EntityManagerInterface))` block (lines 273-312), further guarded by `interface_exists(MessageBusInterface)`:
```php
// PATTERN: src/TenancyBundle.php lines 273-311 (shared-entity service wiring structure)
if (interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
    // ... existing copier, subscriber, write-protection, resync-command wiring ...

    // NEW (D-07): wire bus into subscriber and register handler only when async=true and Messenger present:
    if (interface_exists(MessageBusInterface::class)) {
        // Update subscriber to receive the bus:
        $builder->getDefinition('tenancy.shared_entity_sync_subscriber')
            ->setArgument(6, new Reference('messenger.bus.default'));

        // Register handler:
        $services->set('tenancy.shared_entity_changed_handler', SharedEntityChangedMessageHandler::class)
            ->args([...])
            ->tag('messenger.message_handler', ['handles' => SharedEntityChangedMessage::class]);
    }
}
```

**`build()` — compiler pass registration** — lines 328-347, adding `SharedAsyncContractPass` alongside `SharedEntityMutualExclusionPass`:
```php
// src/TenancyBundle.php lines 345-347 (current):
if (interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
    $container->addCompilerPass(new SharedEntityMutualExclusionPass());
    // ADD:
    $container->addCompilerPass(new SharedAsyncContractPass());
}
```

---

### `tests/Integration/SharedEntity/SharedEntityAsyncCanaryTest.php` (integration-test)

**Analog:** `tests/Integration/Mailer/AsyncCanaryTest.php` — class/kernel lifecycle, setUp reset pattern, bus dispatch, `setUpBeforeClass`/`tearDownAfterClass` kernel boot/shutdown.

**Class lifecycle pattern** — copy from `AsyncCanaryTest` lines 71-105:
```php
// AsyncCanaryTest.php lines 71-105 — kernel boot/shutdown:
final class SharedEntityAsyncCanaryTest extends TestCase
{
    private static ?SharedEntityAsyncTestKernel $kernel = null;

    public static function setUpBeforeClass(): void
    {
        if (!interface_exists(MessageBusInterface::class)) {
            self::markTestSkipped('symfony/messenger not installed');
        }
        // clear stale cache dir, boot kernel
        self::$kernel = new SharedEntityAsyncTestKernel('shared_async_test', false);
        self::$kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$kernel) {
            self::$kernel->shutdown();
            self::$kernel = null;
        }
        // unlink test DB files (pattern from SharedEntitySyncIntegrationTest lines 113-120)
    }
```

**setUp() reset pattern** — copy from `SharedEntitySyncIntegrationTest` (schema/context reset before each test). Also reset TenantContext, reset managers to avoid cross-test identity-map pollution.

**Bus dispatch pattern** — copy from `AsyncCanaryTest` lines 267-290 (dispatch on `messenger.bus.default`):
```php
// AsyncCanaryTest.php lines 267-290:
/** @var MessageBusInterface $bus */
$bus = $container->get('messenger.bus.default');
$bus->dispatch(new SharedEntityChangedMessage($entityClass, $identifier, 'insert'));
```

**Canary assertions (RESEARCH Pattern 4 — handler reach, NOT serialization):**
The SHARE-03 canary asserts:
1. With `async=true`: `postFlush` dispatches messages; per-tenant DBs have the copied row after dispatch (sync transport routes to handler inline).
2. Handler fans out to ALL tenants: both tenant_a.db and tenant_b.db have the entity.
3. Wrong-tenant isolation: tenant_a's DB has no tenant_b-specific data.
4. Vanished-row→delete: dispatch an insert, delete the landlord row, assert tenant copies were deleted.
5. `async=false`: no dispatch, sync fan-out runs (subscriber behavior unchanged).

**NOT the pattern from the Mailer canary** — no PhpSerializer assertion needed (RESEARCH Critical Finding, Pattern 4). The `sync://` transport re-dispatches on the bus without serialization.

---

### `tests/Integration/SharedEntity/Support/SharedEntityAsyncTestKernel.php` (test-kernel)

**Primary analog:** `tests/Integration/SharedEntity/Support/SharedEntitySyncTestKernel.php` — provides the Doctrine (DoctrineBundle) + TenancyBundle + SQLite fixture configuration that the async kernel must extend.

**Secondary analog:** `tests/Integration/Mailer/MailerTestKernel.php` — provides the Messenger `sync://` transport configuration pattern.

**Key differences from SyncTestKernel:**
1. Add `sync://` Messenger transport (copy from `MailerTestKernel.php` lines 101-114).
2. Route `SharedEntityChangedMessage::class => 'sync'` (not `SendEmailMessage`).
3. Set `tenancy.shared.async: true` in the `tenancy` config block.
4. Add `MakeSharedEntityAsyncServicesPublicPass` in `build()` to expose handler + bus.

```php
// MailerTestKernel.php lines 101-114 — Messenger config to adapt:
'messenger' => [
    'default_bus' => 'messenger.bus.default',
    'buses' => [
        'messenger.bus.default' => ['default_middleware' => 'allow_no_handlers'],
    ],
    'transports' => ['sync' => 'sync://'],
    'routing' => [
        SendEmailMessage::class => 'sync',  // → replace with SharedEntityChangedMessage::class
    ],
],
```

**Tenancy config block** — copy from `SharedEntitySyncTestKernel.php` lines 71-73 then add `shared.async: true`:
```php
// SharedEntitySyncTestKernel.php lines 71-73 (base):
$container->loadFromExtension('tenancy', [
    'database' => ['enabled' => true],
    // ADD:
    'shared' => ['async' => true],
]);
```

**Cache dir** — copy from `SharedEntitySyncTestKernel.php` lines 142-148 (uses `md5(static::class)` to avoid cross-kernel cache collision).

---

### `tests/Integration/SharedEntity/Support/MakeSharedEntityAsyncServicesPublicPass.php` (test-support compiler-pass)

**Analog:** `tests/Integration/SharedEntity/Support/MakeSharedEntityServicesPublicPass.php` — exact structural copy, different service ID list.

**Full pattern** (`MakeSharedEntityServicesPublicPass.php` lines 1-44):
```php
// lines 1-44 — copy entire structure, change the $ids array:
final class MakeSharedEntityAsyncServicesPublicPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $ids = [
            'tenancy.shared_entity_changed_handler',  // NEW: async handler
            'messenger.bus.default',                  // NEW: expose bus
            // inherit from MakeSharedEntityServicesPublicPass:
            'tenancy.shared_entity_sync_subscriber',
            'tenancy.shared_entity_copier',
            'tenancy.context',
            'doctrine.orm.landlord_entity_manager',
            'doctrine',
            'doctrine.dbal.tenant_connection',
        ];

        foreach ($ids as $id) {
            if ($container->hasDefinition($id)) {
                $container->getDefinition($id)->setPublic(true);
            } elseif ($container->hasAlias($id)) {
                $container->getAlias($id)->setPublic(true);
            }
        }
    }
}
```

---

## Shared Patterns

### Optional Dependency Guard
**Source:** `src/TenancyBundle.php` lines 213-215, 238-240, 273
**Apply to:** All Messenger and Doctrine wiring, compiler pass registration
```php
// TenancyBundle.php line 213 — Doctrine DBAL guard example:
if (!interface_exists(\Doctrine\DBAL\Driver\Middleware::class)) {
    throw new \LogicException('...');
}
// TenancyBundle.php line 238 — Doctrine ORM guard for optional wiring:
if (interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) { ... }
// TenancyBundle.php line 335 — Messenger guard for compiler pass:
if (interface_exists(MessageBusInterface::class)) { ... }
```

### Tenant Context Save/Restore
**Source:** `src/Subscriber/SharedEntitySyncSubscriber.php` lines 158-159 (save), 186-188 (restore via finally)
**Apply to:** `SharedEntitySyncSubscriber::postFlush()` async branch (dispatch), `SharedEntityChangedMessageHandler::__invoke()` (fan-out loop)
```php
// SharedEntitySyncSubscriber.php line 158-159:
$previousTenant = $this->tenantContext->hasTenant() ? $this->tenantContext->getTenant() : null;
// ... work ...
// lines 186-188:
} finally {
    $this->restoreTenantContext($previousTenant);
}
```

### DBAL Connection Close + EM Reset (switchToTenant)
**Source:** `src/Subscriber/SharedEntitySyncSubscriber.php` lines 198-215 (`switchToTenant`)
**Apply to:** `SharedEntityChangedMessageHandler::__invoke()` per-tenant loop. Copy verbatim — missing `close()` causes wrong-tenant DBAL socket reuse (CR-01/CR-02 from Phase 25).

### Explicit Doctrine Event Listener Tags (NO autoconfigure)
**Source:** `src/TenancyBundle.php` lines 288-290
**Apply to:** Any additional Doctrine subscriber registered in this phase
```php
// TenancyBundle.php lines 288-290:
->tag('doctrine.event_listener', ['event' => 'onFlush', 'connection' => 'landlord'])
->tag('doctrine.event_listener', ['event' => 'postFlush', 'connection' => 'landlord']);
// NEVER use #[AsEventListener] or autoconfigure for Doctrine subscribers in this bundle
```

### Explicit Messenger Handler Tag (NO #[AsMessageHandler])
**Source:** RESEARCH.md Pattern 1 (verified against `vendor/symfony/framework-bundle/DependencyInjection/FrameworkExtension.php` L727)
**Apply to:** `SharedEntityChangedMessageHandler` registration in `TenancyBundle::loadExtension()`
```php
->tag('messenger.message_handler', ['handles' => SharedEntityChangedMessage::class]);
// NOT: #[AsMessageHandler] — autoconfigure not active for bundle-registered services
```

### Structured PSR-3 Log Keys
**Source:** `src/Subscriber/SharedEntitySyncSubscriber.php` lines 254-260
**Apply to:** Handler failure logging, state-collapse (vanished-row→delete) logging
```php
// subscriber applyChange() lines 254-260:
$this->logger->error('tenancy.shared_entity_sync_failed', [
    'tenant_slug' => $tenant->getSlug(),
    'entity_class' => $entity::class,
    'identifier' => $identifier,
    'error' => $e->getMessage(),
]);
// Handler uses same shape with key prefix 'tenancy.shared_entity_async_fan_out_failed'
// State-collapse: 'tenancy.shared_entity_async_vanished_row' + 'original_type' extra key
```

### Config→Parameter Flow
**Source:** `src/TenancyBundle.php` lines 155-185 (mailer/filesystem extraction pattern)
**Apply to:** New `tenancy.shared.async` parameter extraction
```php
// lines 155-170 (mailerConfig extraction pattern to mirror):
/** @var array<string, mixed> $mailerConfig */
$mailerConfig = $config['mailer'] ?? [];
$mailerAsyncRaw = $mailerConfig['async'] ?? 'auto';
$mailerAsync = is_scalar($mailerAsyncRaw) ? (string) $mailerAsyncRaw : 'auto';
// Then in ->set() chain (line 181):
->set('tenancy.mailer.async', $mailerAsync)
```

### Kernel Cache Dir Isolation
**Source:** `tests/Integration/SharedEntity/Support/SharedEntitySyncTestKernel.php` lines 142-148
**Apply to:** `SharedEntityAsyncTestKernel`
```php
// SharedEntitySyncTestKernel.php lines 142-148:
public function getCacheDir(): string
{
    return sys_get_temp_dir().'/tenancy_doctrine_test_'.md5(static::class).'_'.$this->environment.'/cache';
}
```

---

## Critical Open Items for Planner

### OQ-1: applyRow() delete-path entity parameter
`SharedEntityCopier::applyRow()` dereferences `$entity::class` at line 66 BEFORE the `if ('delete' === $type)` check at line 69. Since `$entity` is typed `object` (non-nullable), passing null fails PHPStan L9.

**Recommended resolution:** For `type=delete` (and vanished-row→delete), replicate the 6-line delete sub-path from `SharedEntityCopier.php` lines 82-93 directly in the handler, bypassing `applyRow()` entirely. This avoids the non-nullable issue and is equally idempotent (`$tenantEm->find()` → `remove()` → `flush()`).

```php
// SharedEntityCopier.php lines 82-93 (delete sub-path to replicate in handler):
$existing = $tenantEm->find($class, $capturedIds);
if (null !== $existing) {
    $tenantEm->remove($existing);
    // Note: syncInProgress flag is owned by the copier — handler must either
    // call $copier->applyRow() (only when entity is non-null/upsert) or
    // reproduce the flag-set/flush/flag-reset inline.
    // The write-protection listener checks isSyncInProgress() on the copier —
    // the handler's direct flush() will be blocked unless the flag is set.
    // PLANNER: handler must call copier->applyRow() for upsert (with real entity)
    // and handle delete via copier's separate path (or expose deleteRow() on copier).
}
```

### OQ-2: switchToTenant()/restoreTenantContext() sharing
Extract to a shared `TenantContextSwitcher` service (recommended) or duplicate ~30 lines in the handler. If duplicated, the handler's copy MUST be kept in sync with the subscriber's copy (DBAL `close()` + `resetManager()` semantics are non-obvious).

### OQ-3: Landlord EM identity-map staleness
In long-running workers, call `$landlordEm->clear($class)` before `$landlordEm->find($class, $identifier)` to force a real DB query. The `sync://` test transport runs inline and is not affected, but a production AMQP/Doctrine worker reuses the EM across messages.

---

## No Analog Found

All files have analogs. No gaps.

---

## Metadata

**Analog search scope:** `src/`, `tests/Integration/`
**Files scanned:** 12 source files read directly
**Key source line counts confirmed:**
- `SharedEntitySyncSubscriber.php` — 296 lines (read complete)
- `SharedEntityCopier.php` — 222 lines (read complete)
- `MailerTransportContractPass.php` — 106 lines (read complete)
- `TenancyBundle.php` — 412 lines (read complete)
- `AsyncCanaryTest.php` — 503 lines (read complete)
- `MailerTestKernel.php` — 133 lines (read complete)
- `SharedEntitySyncTestKernel.php` — 149 lines (read complete)
- `MakeSharedEntityServicesPublicPass.php` — 44 lines (read complete)
- `SharedEntityCopierInterface.php` — 62 lines (read complete)
**Pattern extraction date:** 2026-06-15
