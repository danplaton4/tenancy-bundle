# Phase 25: Shared Entities (Sync mode) - Pattern Map

**Mapped:** 2026-06-11
**Files analyzed:** 9 new/modified files
**Analogs found:** 9 / 9

---

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `src/Attribute/Shared.php` | attribute | — | `src/Attribute/TenantAware.php` | exact |
| `src/Exception/SharedEntityWriteInTenantContextException.php` | exception | — | `src/Exception/MissingFilesystemConfigException.php` | exact |
| `src/Subscriber/SharedEntitySyncSubscriber.php` | subscriber (Doctrine event) | event-driven (onFlush + postFlush) | `src/EventListener/TenantContextOrchestrator.php` | role-match (closest Symfony listener; no Doctrine subscriber exists yet) |
| `src/Subscriber/SharedEntityWriteProtectionListener.php` | subscriber (Doctrine event) | event-driven (onFlush) | `src/EventListener/TenantContextOrchestrator.php` | role-match |
| `src/DependencyInjection/Compiler/SharedEntityMutualExclusionPass.php` | compiler pass | — | `src/DependencyInjection/Compiler/FilesystemContractPass.php` | exact |
| `config/services.php` (modified — Doctrine event wiring block) | DI config | — | `config/services.php` lines 60–107 (existing Doctrine-guarded blocks) | exact |
| `src/TenancyBundle.php` (modified — `build()`) | bundle | — | `src/TenancyBundle.php` lines 269–286 (existing `build()`) | exact |
| `tests/Unit/Attribute/SharedTest.php` | test | — | `tests/Unit/Attribute/TenantAwareTest.php` | exact |
| `tests/Unit/DependencyInjection/Compiler/SharedEntityMutualExclusionPassTest.php` | test | — | `tests/Unit/DependencyInjection/Compiler/FilesystemContractPassTest.php` | exact |

> **Note on subscriber namespace:** There is currently no `src/Subscriber/` directory. The research explicitly recommends creating `src/Subscriber/` for Doctrine event subscribers, distinguishing them from Symfony kernel event listeners in `src/EventListener/`. This is consistent with Symfony convention — Doctrine event subscribers MUST NOT use `#[AsEventListener]` and MUST NOT use `autoconfigure(true)` (the `doctrine.event_listener` tag is the only wiring mechanism).

---

## Pattern Assignments

---

### `src/Attribute/Shared.php` (attribute, marker)

**Analog:** `src/Attribute/TenantAware.php`

**Full file** (lines 1–19):
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Attribute;

/**
 * Marks a Doctrine entity for automatic tenant scoping via SQL filter.
 *
 * Add a `tenant_id VARCHAR(63)` column to your entity. The SQL filter
 * injects the active tenant's slug automatically.
 *
 * In inheritance hierarchies (STI/JTI), place this attribute on the
 * root entity — Doctrine's addFilterConstraint receives parent metadata.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class TenantAware
{
}
```

**Copy pattern:** Mirror exactly — `declare(strict_types=1)`, `namespace Tenancy\Bundle\Attribute`, `#[\Attribute(\Attribute::TARGET_CLASS)]`, `final class`, empty body. Replace `TenantAware` with `Shared` and update the docblock to describe the `#[Shared]` contract.

---

### `src/Exception/SharedEntityWriteInTenantContextException.php` (exception)

**Analog:** `src/Exception/MissingFilesystemConfigException.php`

**Full file** (lines 1–28):
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Exception;

/**
 * ... docblock ...
 */
final class MissingFilesystemConfigException extends \LogicException
{
    public static function forTenant(string $slug): self
    {
        return new self(sprintf(
            'tenancy: tenant "%s" has no filesystemConfig.adapter_dsn ...',
            $slug
        ));
    }
}
```

**Copy pattern:**
- `declare(strict_types=1)`, `namespace Tenancy\Bundle\Exception`
- `final class ... extends \LogicException` — extends `\LogicException` (not `\RuntimeException`) per the WR-01 no-retry invariant documented in `MissingFilesystemConfigException`'s docblock
- Single `public static function forEntity(string $entityClass, string $tenantSlug): self` factory; uses `sprintf` for the message; returns `new self(...)`
- Docblock must explain why `\LogicException` (not `\RuntimeException`) — programmer/operator error; Messenger will NOT retry
- Second analog: `src/Exception/MissingTenantProviderException.php` uses constructor-injection style (no static factory) — prefer the static-factory style of `MissingFilesystemConfigException` per D-06 research pattern

---

### `src/Subscriber/SharedEntitySyncSubscriber.php` (subscriber, event-driven)

**Analog:** `src/EventListener/TenantContextOrchestrator.php` (closest Symfony event listener with constructor injection + early-return guard)

**Imports pattern from analog** (lines 1–17):
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
// ...
use Tenancy\Bundle\Context\TenantContext;
```

**Copy pattern (adapted for Doctrine subscriber — NOT Symfony event listener):**

Critical differences from the analog:
1. Namespace: `Tenancy\Bundle\Subscriber` (new namespace, no `src/Subscriber/` dir yet — create it)
2. Implements `Doctrine\Common\EventSubscriber`, NOT a Symfony listener interface
3. MUST NOT use `#[AsEventListener]` or `autoconfigure(true)` — registered via `doctrine.event_listener` tags only
4. Constructor: `readonly` properties via PHP 8.2+ promoted params
5. Early-return guard pattern (mirrors `TenantContextOrchestrator::onKernelRequest` line 35: `if (!$event->isMainRequest()) { return; }`)

**Imports block to use:**
```php
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Tenancy\Bundle\Attribute\Shared;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Doctrine\Persistence\ManagerRegistry;
```

**Core pattern — `getSubscribedEvents()`:**
```php
public function getSubscribedEvents(): array
{
    return [Events::onFlush, Events::postFlush];
}
```

**Core pattern — `onFlush` buffer (buffer BEFORE postFlush drains the arrays):**
```php
public function onFlush(OnFlushEventArgs $args): void
{
    $em  = $args->getObjectManager();
    $uow = $em->getUnitOfWork();
    foreach ($uow->getScheduledEntityInsertions() as $entity) {
        if ($this->isShared($entity)) {
            $this->pendingChanges[spl_object_id($entity)] =
                ['entity' => $entity, 'type' => 'insert'];
        }
    }
    // ... same for Updates, Deletions
}
```

**Core pattern — `postFlush` fan-out with D-03 short-circuit and D-01 best-effort:**
```php
public function postFlush(PostFlushEventArgs $args): void
{
    if ([] === $this->pendingChanges) { return; }
    if ('shared_db' === $this->driver) { $this->pendingChanges = []; return; }

    $changes = $this->pendingChanges;
    $this->pendingChanges = [];

    foreach ($changes as ['entity' => $entity, 'type' => $type]) {
        foreach ($this->tenantProvider->findAll() as $tenant) {
            $this->fanOutToTenant($args->getObjectManager(), $entity, $type, $tenant);
        }
    }
}
```

**Error handling pattern — `fanOutToTenant` with `finally` for context cleanup (D-07 logging):**
```php
private function fanOutToTenant(...): void
{
    try {
        $this->tenantContext->setTenant($tenant);
        $tenantEm = $this->registry->resetManager('tenant');
        $this->syncInProgress = true;
        $this->doSync($landlordEm, $tenantEm, $entity, $type);
        $this->syncInProgress = false;
    } catch (\Throwable $e) {
        $this->syncInProgress = false;
        $meta = $landlordEm->getClassMetadata($entity::class);
        $this->logger->warning('tenancy.shared_entity_sync_failed', [
            'tenant_slug'  => $tenant->getSlug(),
            'entity_class' => $entity::class,
            'identifier'   => $meta->getIdentifierValues($entity),
            'error'        => $e->getMessage(),
        ]);
    } finally {
        $this->tenantContext->clear();
    }
}
```

The `resetManager('tenant')` pattern is verified at `tests/Integration/DBAL/DatabasePerTenantMiddlewareIntegrationTest.php` line 67. Always call `resetManager` before using the tenant EM to clear the identity map.

**Re-entrancy accessor:**
```php
public function isSyncInProgress(): bool
{
    return $this->syncInProgress;
}
```

---

### `src/Subscriber/SharedEntityWriteProtectionListener.php` (subscriber, event-driven)

**Analog:** `src/EventListener/TenantContextOrchestrator.php` (early-return guard pattern)

**Copy pattern:** Same namespace/structure as `SharedEntitySyncSubscriber` above.

**Imports block:**
```php
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Tenancy\Bundle\Attribute\Shared;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Exception\SharedEntityWriteInTenantContextException;
```

**`getSubscribedEvents()`:**
```php
public function getSubscribedEvents(): array
{
    return [Events::onFlush];
}
```

**Core pattern — early-return guards (mirrors `TenantContextOrchestrator` line 35 pattern):**
```php
public function onFlush(OnFlushEventArgs $args): void
{
    if (!$this->tenantContext->hasTenant()) { return; }   // landlord context
    if ($this->syncSubscriber->isSyncInProgress()) { return; }  // re-entrancy bypass

    $em  = $args->getObjectManager();
    $uow = $em->getUnitOfWork();
    $tenant = $this->tenantContext->getTenant();

    foreach ([
        $uow->getScheduledEntityInsertions(),
        $uow->getScheduledEntityUpdates(),
        $uow->getScheduledEntityDeletions(),
    ] as $entities) {
        foreach ($entities as $entity) {
            $rc = new \ReflectionClass($entity);
            if ([] !== $rc->getAttributes(Shared::class)) {
                throw SharedEntityWriteInTenantContextException::forEntity(
                    $entity::class,
                    $tenant->getSlug(),
                );
            }
        }
    }
}
```

---

### `src/DependencyInjection/Compiler/SharedEntityMutualExclusionPass.php` (compiler pass)

**Analog:** `src/DependencyInjection/Compiler/FilesystemContractPass.php`

**Imports pattern** (lines 1–13):
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
```

**Auth/guard pattern — Doctrine `interface_exists` early-return** (mirrors `MailerTransportContractPass` line 41, `FilesystemContractPass` line 75):
```php
public function process(ContainerBuilder $container): void
{
    if (!interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
        return;
    }
    // ...
}
```

**Core pattern — `\LogicException` throw on conflict** (mirrors `FilesystemContractPass` lines 76, 87, 95, 101):
```php
throw new \LogicException(sprintf(
    'Entity "%s" cannot carry both #[Shared] and #[TenantAware]. '
    . 'A shared entity is a landlord-side master; a TenantAware entity is '
    . 'tenant-scoped. Pick one.',
    $class,
));
```

**Entity class discovery note (A1 — ASSUMED in research):** The research flags this as the uncertain part. Three viable approaches for the planner:
1. Walk `doctrine.orm.*.metadata_driver` container service definitions
2. Require users to tag shared entity service definitions with a `tenancy.shared` container tag (same pattern as `tenancy.scoped` in `FilesystemContractPass`)
3. Defer to a `loadClassMetadata` runtime listener (fires at first kernel request, not compile time — does not fully satisfy D-04)

The `tenancy.scoped` tag-walking approach in `FilesystemContractPass` (lines 80–108) is the established bundle pattern: `$container->findTaggedServiceIds('tenancy.scoped')`. The planner should apply the same pattern with `tenancy.shared` tag, or use `tenancy.shared_entity` as the tag name.

---

### `config/services.php` (modified — add Doctrine event wiring block)

**Analog:** `config/services.php` lines 60–107 (existing `interface_exists(Doctrine\ORM\EntityManagerInterface::class)` guards)

**Doctrine guard pattern** (lines 60–68 and 103–107):
```php
if (interface_exists(Doctrine\ORM\EntityManagerInterface::class)) {
    $services->set('tenancy.provider', DoctrineTenantProvider::class)
        ->args([...])
    // ...
}

if (interface_exists(Doctrine\ORM\EntityManagerInterface::class)) {
    $services->set('tenancy.doctrine_bootstrapper', DoctrineBootstrapper::class)
        ->args([service('doctrine.orm.entity_manager')->nullOnInvalid()])
        ->tag('tenancy.bootstrapper', ['priority' => -10]);
}
```

**New block to add** (mirrors the guard + `.tag()` chaining pattern):
```php
if (interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
    $services->set('tenancy.shared_entity_sync_subscriber', SharedEntitySyncSubscriber::class)
        ->args([
            service('tenancy.context'),
            service('tenancy.provider'),
            service('doctrine'),
            service('logger'),
            param('tenancy.driver'),
        ])
        ->tag('doctrine.event_listener', ['event' => 'onFlush',   'connection' => 'landlord'])
        ->tag('doctrine.event_listener', ['event' => 'postFlush', 'connection' => 'landlord']);

    $services->set('tenancy.shared_entity_write_protection', SharedEntityWriteProtectionListener::class)
        ->args([
            service('tenancy.context'),
            service('tenancy.shared_entity_sync_subscriber'),
        ])
        ->tag('doctrine.event_listener', ['event' => 'onFlush', 'connection' => 'tenant']);
}
```

**Critical constraint:** Do NOT use `->autoconfigure(true)` on these services (verified from research — `#[AsEventListener]` and Doctrine subscribers are incompatible; autoconfigure would register wrong tags).

**Open question A2 (from research):** When `tenancy.driver = shared_db`, there is no `landlord` or `tenant` connection. The `'connection' => 'landlord'` tag may cause a container error. The planner must decide whether to: (a) wrap the service registration in an additional `if ('database_per_tenant' === driver)` check using a container parameter, or (b) rely on the D-03 runtime short-circuit and accept that the tag silently does nothing when the connection is absent. See `TenancyBundle.php` lines 208 and 256 for precedent on driver-conditional service registration.

---

### `src/TenancyBundle.php` — `build()` method (modified)

**Analog:** `src/TenancyBundle.php` lines 269–286 (the existing `build()` method)

**Core pattern** (lines 269–286):
```php
public function build(ContainerBuilder $container): void
{
    parent::build($container);
    $container->addCompilerPass(new BootstrapperChainPass());
    $container->addCompilerPass(new ResolverChainPass());
    $container->addCompilerPass(new CacheDecoratorContractPass());
    $container->addCompilerPass(new OriginHeaderResolverConfigPass());
    if (interface_exists(MessageBusInterface::class)) {
        $container->addCompilerPass(new MessengerMiddlewarePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1);
    }
    if (interface_exists(\Symfony\Component\Mailer\MailerInterface::class)) {
        $container->addCompilerPass(new MailerTransportContractPass());
    }
    if (interface_exists(\League\Flysystem\FilesystemOperator::class)) {
        $container->addCompilerPass(new FilesystemContractPass());
    }
}
```

**Change to apply — append inside `build()`:**
```php
if (interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
    $container->addCompilerPass(new SharedEntityMutualExclusionPass());
}
```

Add a `use` statement at the top of `TenancyBundle.php`:
```php
use Tenancy\Bundle\DependencyInjection\Compiler\SharedEntityMutualExclusionPass;
```

The existing pattern for optional-dep guards in `build()` always uses `interface_exists(...)` on the **interface** (not `class_exists` on a class). Use `\Doctrine\ORM\EntityManagerInterface::class` to match lines 233 and 278.

---

### `tests/Unit/Attribute/SharedTest.php` (test)

**Analog:** `tests/Unit/Attribute/TenantAwareTest.php`

**Full file** (lines 1–28):
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Attribute;

use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Attribute\TenantAware;

final class TenantAwareTest extends TestCase
{
    public function testAttributeHasTargetClassFlag(): void
    {
        $reflClass = new \ReflectionClass(TenantAware::class);
        $attributes = $reflClass->getAttributes(\Attribute::class);

        $this->assertNotEmpty($attributes, 'TenantAware class must have #[Attribute] attribute declared');

        $attributeInstance = $attributes[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_CLASS, $attributeInstance->flags);
    }

    public function testAttributeCanBeInstantiated(): void
    {
        $instance = new TenantAware();
        $this->assertInstanceOf(TenantAware::class, $instance);
    }
}
```

**Copy pattern:** Replace `TenantAware` with `Shared` throughout. Test class name: `SharedTest`. The two test methods cover SHARE-01-a.

---

### `tests/Unit/DependencyInjection/Compiler/SharedEntityMutualExclusionPassTest.php` (test)

**Analog:** `tests/Unit/DependencyInjection/Compiler/FilesystemContractPassTest.php`

**Imports + class shape** (lines 1–29):
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Tenancy\Bundle\DependencyInjection\Compiler\FilesystemContractPass;
// ...

final class FilesystemContractPassTest extends TestCase
{
    // ... test methods that call (new FilesystemContractPass())->process($container)
```

**Copy pattern:**
- `declare(strict_types=1)`, same namespace
- Instantiate `ContainerBuilder`, add definitions with attributes set, call `(new SharedEntityMutualExclusionPass())->process($container)`
- `testMutualExclusionGuardThrows()`: register a class carrying both `#[Shared]` + `#[TenantAware]`, assert `\LogicException` thrown (SHARE-01-l)
- `testNoExceptionWhenOnlySharedPresent()`: class with only `#[Shared]`, assert no exception
- `testNoExceptionWhenDoctrineAbsent()`: if `interface_exists(\Doctrine\ORM\EntityManagerInterface::class)` returns false (cannot be simulated directly — document as skipped)

---

## Shared Patterns

### 1. `declare(strict_types=1)` header
**Source:** Every `src/` file in the bundle
**Apply to:** All new PHP files
Every file starts with `<?php` then `declare(strict_types=1);` on line 3. No exceptions.

### 2. Doctrine optional-dep guard
**Source:** `config/services.php` lines 60, 103; `src/TenancyBundle.php` lines 233, 278, 283
**Apply to:** `config/services.php` additions, `src/TenancyBundle.php` `build()` addition, `SharedEntityMutualExclusionPass::process()`
```php
if (interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
    // all Doctrine-dependent wiring here
}
```
Use `interface_exists` on the interface (not `class_exists` on a class).

### 3. `\LogicException` throw style in compiler passes
**Source:** `src/DependencyInjection/Compiler/FilesystemContractPass.php` lines 76, 87, 95, 101; `MailerTransportContractPass.php` lines 46, 67
**Apply to:** `SharedEntityMutualExclusionPass::process()`
```php
throw new \LogicException(sprintf('tenancy: ...message with context...', $class));
```
Always use bare `\LogicException` (not a custom exception class) in compiler passes. Message must start with `tenancy:` prefix for searchability.

### 4. PSR-3 structured logging (D-07)
**Source:** `src/EventListener/TenantContextOrchestrator.php` patterns; `config/services.php` `service('logger')` wiring
**Apply to:** `SharedEntitySyncSubscriber` per-tenant error catch blocks
```php
$this->logger->warning('tenancy.shared_entity_sync_failed', [
    'tenant_slug'  => $tenant->getSlug(),
    'entity_class' => $entity::class,
    'identifier'   => $meta->getIdentifierValues($entity),
    'error'        => $e->getMessage(),
]);
```
Use structured context array (PSR-3 convention). The channel key is a dot-namespaced string starting with `tenancy.`.

### 5. `readonly` constructor property promotion
**Source:** `src/EventListener/TenantContextOrchestrator.php` lines 26–31; pervasive in PHP 8.2 bundle source
**Apply to:** All new subscriber/listener constructors
```php
public function __construct(
    private readonly TenantContext $tenantContext,
    private readonly TenantProviderInterface $tenantProvider,
    // ...
) {}
```

### 6. `service(...)->nullOnInvalid()` for optional deps in services.php
**Source:** `config/services.php` lines 63, 87, 105 etc.
**Apply to:** `service('logger')` and `service('tenancy.provider')` in the new subscriber definitions — if these might be absent in some container configurations, use `->nullOnInvalid()`

### 7. No `autoconfigure(true)` on Doctrine event subscribers
**Source:** Research verification of `RegisterEventListenersAndSubscribersPass.php` (Symfony DoctrineBundle)
**Apply to:** Both `SharedEntitySyncSubscriber` and `SharedEntityWriteProtectionListener` service definitions
MUST NOT use `->autoconfigure(true)`. Doctrine subscribers are wired exclusively via `doctrine.event_listener` tags. Using autoconfigure would add wrong Symfony kernel event tags.

---

## No Analog Found

There are no files in this phase with no close analog. The bundle has strong analogs for all 7 file roles.

The only **new territory** is the `src/Subscriber/` namespace directory — it does not yet exist. The planner must create the directory as part of the first subscriber task.

---

## Assumptions / Open Questions for Planner

These are items flagged as ASSUMED in the research that need concrete resolution during planning:

| # | Question | Risk | Recommendation |
|---|---|---|---|
| A1 | How does `SharedEntityMutualExclusionPass` enumerate entity classes at compile time? | Guard never fires if discovery is wrong | Use `tenancy.shared_entity` container tag (mirrors `tenancy.scoped` tag in `FilesystemContractPass`) — users tag their entity service definitions; the pass validates them |
| A2 | `'connection' => 'landlord'` tag under `shared_db` driver (no landlord connection) | Container error at boot under `shared_db` | Wrap subscriber registration in additional driver check — or rely on D-03 short-circuit if DoctrineBundle silently ignores missing connections |
| A3 | `DoctrineTenantProvider::findAll()` includes inactive tenants | Inactive tenants receive sync writes | For Phase 25, sync all tenants from `findAll()` — document that inactive tenants receive sync; Phase 26 resync handles catch-up |

---

## Metadata

**Analog search scope:** `src/Attribute/`, `src/Exception/`, `src/DependencyInjection/Compiler/`, `src/EventListener/`, `config/services.php`, `src/TenancyBundle.php`, `tests/Unit/Attribute/`, `tests/Unit/DependencyInjection/Compiler/`, `tests/Integration/Support/`
**Files scanned:** 16 source files + directory listing
**Pattern extraction date:** 2026-06-11

---

## PATTERN MAPPING COMPLETE

**Phase:** 25 - Shared Entities (Sync mode)
**Files classified:** 9
**Analogs found:** 9 / 9

### Coverage
- Files with exact analog: 5 (`Shared.php`, `SharedEntityWriteInTenantContextException.php`, `SharedEntityMutualExclusionPass.php`, `TenancyBundle.php build()`, `SharedTest.php`)
- Files with role-match analog: 4 (`SharedEntitySyncSubscriber.php`, `SharedEntityWriteProtectionListener.php`, `config/services.php` additions, `SharedEntityMutualExclusionPassTest.php`)
- Files with no analog: 0

### Key Patterns Identified
- Bare marker attributes: `#[\Attribute(\Attribute::TARGET_CLASS)] final class` with empty body — exact copy of `TenantAware.php`
- Exceptions: `final class extends \LogicException` + `public static function forX(): self` factory — exact copy of `MissingFilesystemConfigException.php`
- Doctrine optional-dep guard: `interface_exists(\Doctrine\ORM\EntityManagerInterface::class)` wraps ALL Doctrine wiring in both `services.php` and `TenancyBundle::build()`
- Compiler pass throw style: bare `\LogicException` with `sprintf`, `tenancy:` prefix, descriptive message — mirrors `FilesystemContractPass`
- `build()` compiler pass registration: `if (interface_exists(...)) { $container->addCompilerPass(new ...Pass()); }` — mirrors lines 280–285 of `TenancyBundle.php`
- Doctrine event subscribers: `implements EventSubscriber` + `doctrine.event_listener` tag with `connection` scoping — NOT Symfony `#[AsEventListener]`, NOT `autoconfigure(true)`
- `resetManager('tenant')` before each tenant EM use — verified at `DatabasePerTenantMiddlewareIntegrationTest.php` line 67
- `readonly` constructor promoted properties — PHP 8.2+ pervasive pattern

### File Created
`/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/.planning/phases/25-shared-entities-sync-mode/25-PATTERNS.md`

### Ready for Planning
Pattern mapping complete. Planner can now reference analog patterns in PLAN.md files.
