# Phase 25: Shared Entities (Sync mode) - Research

**Researched:** 2026-06-11
**Domain:** Doctrine ORM 3.x event subscribers, multi-EM fan-out, PHP attributes, compiler passes
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01 Best-effort fan-out.** Landlord transaction is already COMMITTED at postFlush. Per-tenant failures are caught + logged (PSR-3), never rethrown. NOT all-or-nothing, NOT fail-fast.
- **D-02 Full read-only enforcement.** Any attempt to insert, update, OR delete a `#[Shared]` entity while a tenant is active throws `SharedEntityWriteInTenantContextException extends \LogicException`. Enforced via a tenant-EM `onFlush` guard.
- **D-03 Documented no-op under `shared_db`.** Subscriber short-circuits when driver is `shared_db`. No compile-time rejection.
- **D-04 Compiler-pass guard at container compile time.** Rejects classes carrying both `#[Shared]` and `#[TenantAware]`. Mirrors `FilesystemContractPass` convention. Fails loud at boot.
- **D-05 Sync covers insert/update/delete.** Landlord delete propagates to tenant-side delete.
- **D-06 `#[Shared]` is a bare class-target marker attribute (no constructor params).** Mirrors `src/Attribute/TenantAware.php`.
- **D-07 Per-tenant error logging MUST be actionable:** tenant slug + entity class + identifier + failure.

### Claude's Discretion

- HOW changes are captured (changeset buffering pattern — buffer in `onFlush`, apply in `postFlush`)
- Exact tenant-EM switching / upsert mechanics (including ORM 3.x `merge()` removal — see §Architecture Patterns)
- Logger service wiring
- Re-entrancy guard mechanism (distinguish subscriber's own sync writes from user writes)

### Deferred Ideas (OUT OF SCOPE)

- `tenancy:shared:resync` command — Phase 26 (SHARE-02)
- Async Messenger fan-out — Phase 27 (SHARE-03)
- PHPStan correctness rule — Phase 28 (DX-03)
- Docs page — Phase 29 (DOC-20)
- Cross-tenant queries, read-replica routing — v0.5+

</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| SHARE-01 | `#[Shared]` PHP attribute marks an entity for cross-tenant sync; `SharedEntitySyncSubscriber` on landlord EM postFlush fans changes to all tenant EMs via `TenantProviderInterface::findAll()`; tenant copies are write-protected; cascade depth limited to one level; `SharedEntityWriteInTenantContextException extends \LogicException` | §Architecture Patterns covers all sub-requirements; §Common Pitfalls covers cascade landmine; §Validation Architecture maps each acceptance criterion to a test |

</phase_requirements>

---

## Summary

Phase 25 delivers SHARE-01: a `#[Shared]` PHP attribute, a `SharedEntitySyncSubscriber` that fans landlord EM writes to all tenant EMs synchronously on `postFlush`, a tenant-side write protection guard (`onFlush` on each tenant EM), and a container compiler-pass that rejects `#[Shared]` + `#[TenantAware]` on the same class.

The installed stack is Doctrine ORM **3.6.3** with DBAL **4.4.3** and DoctrineBundle **3.2.2**. In ORM 3.x, `EntityManagerInterface::merge()` does not exist — it was removed in ORM 3.0. The modern equivalent for the sync fan-out is a find-or-new + field-copy approach using `ClassMetadata::getFieldNames()` / `getFieldValue()` / `setFieldValue()`. The `#[Shared]` attribute is a straightforward clone of `src/Attribute/TenantAware.php`.

The MOST IMPORTANT verified discovery is changeset capture timing: `UnitOfWork::executeInserts()` runs `unset($this->entityInsertions[$oid])` for each entity as it processes them during the flush, so by the time `postFlush` fires, `entityInsertions`, `entityUpdates`, and `entityDeletions` are all **empty**. Changes MUST be buffered in an `onFlush` listener and stored on the subscriber before `postFlush` runs.

**Primary recommendation:** Implement a single `SharedEntitySyncSubscriber` that listens to BOTH `onFlush` (landlord EM — buffer `#[Shared]` entity changesets) and `postFlush` (landlord EM — execute the buffered fan-out). A companion `SharedEntityWriteProtectionListener` listens to `onFlush` on TENANT EMs and throws if a `#[Shared]` entity appears in the scheduled inserts/updates/deletions (with re-entrancy bypass for subscriber-initiated writes).

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| `#[Shared]` PHP attribute definition | PHP class (marker) | — | Zero-dep bare attribute, mirrors `TenantAware.php` |
| Changeset capture (landlord side) | ORM event subscriber (`onFlush`) | — | `postFlush` changeset arrays are empty by event time (see §Pitfall 1); must buffer in `onFlush` |
| Fan-out execution | ORM event subscriber (`postFlush`) | — | Landlord transaction committed; safe to write to tenants |
| Tenant EM write protection | ORM event subscriber (`onFlush`, tenant EM) | — | Must fire before DB operations; `onFlush` is the only event with access to scheduled sets |
| Tenant enumeration | `TenantProviderInterface::findAll()` (API tier) | — | Established bundle API; already used by `tenancy:migrate` |
| `#[Shared]` + `#[TenantAware]` mutual exclusion | DI compiler pass (build time) | — | Boot-time fail-loud; mirrors `FilesystemContractPass`, `MailerTransportContractPass` |
| Re-entrancy guard (sync writes bypass write-protection) | Subscriber state flag (PHP object) | — | Simple boolean on subscriber; injected into write-protection listener |
| `shared_db` short-circuit | Subscriber constructor/`onFlush` check | — | Reads `tenancy.driver` container parameter |
| Exception for write attempts | `SharedEntityWriteInTenantContextException extends \LogicException` | — | Follows bundle's no-retry exception policy |
| Per-tenant error logging | PSR-3 logger on subscriber | — | D-07 requires actionable fields |

---

## Standard Stack

No NEW external packages are introduced by this phase. All dependencies are already declared.

### Core (already installed)
| Library | Version | Purpose | Source |
|---------|---------|---------|--------|
| `doctrine/orm` | `^3.3` (installed: 3.6.3) | ORM events, UnitOfWork, ClassMetadata | `[VERIFIED: composer.json]` |
| `doctrine/dbal` | `^4.4` (installed: 4.4.3) | DBAL connection for tenant switching | `[VERIFIED: composer.json]` |
| `doctrine/doctrine-bundle` | `^2.13\|\|^3.0` (installed: 3.2.2) | Event listener tag wiring | `[VERIFIED: composer.json]` |
| `psr/log` | (transitive) | LoggerInterface for per-tenant error logging | `[VERIFIED: composer.json transitive]` |

### No new packages
This phase requires NO `composer require` step. All ORM, DBAL, and logger dependencies are already in `require-dev` / `suggest`.

---

## Package Legitimacy Audit

> No new packages are installed in this phase. This section is intentionally empty.

**Packages removed due to slopcheck [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none

---

## Architecture Patterns

### System Architecture Diagram

```
LANDLORD EM flush() call
        |
        v
  UnitOfWork::commit()
        |
        +-- onFlush event fires ---> SharedEntitySyncSubscriber::onFlush()
        |                                  |
        |                            UoW still has scheduled
        |                            insertions/updates/deletions
        |                                  |
        |                            Filter for #[Shared] entities
        |                            Buffer into $pendingInserts /
        |                            $pendingUpdates / $pendingDeletes
        |
        +-- executeInserts() -- clears entityInsertions per entity
        +-- executeUpdates() -- clears entityUpdates per entity
        +-- executeDeletions() -- clears entityDeletions
        +-- $conn->commit() -- landlord TX committed
        |
        v
  postFlush event fires ---> SharedEntitySyncSubscriber::postFlush()
        |
        |  [D-03 check: if driver == 'shared_db' → return immediately]
        |
        |  For each buffered change:
        |    For each TenantProviderInterface::findAll() tenant:
        |      try {
        |        TenantContext::setTenant($tenant)
        |        $registry->resetManager('tenant')  [clears identity map]
        |        tenantEm = $registry->getManager('tenant')
        |        $syncInProgress = true              [re-entrancy guard]
        |        [find-or-new entity in tenant EM]
        |        [copy scalar fields via ClassMetadata]
        |        tenantEm->persist($copy)
        |        tenantEm->flush()    <-- triggers onFlush on tenant EM
        |                                 write-protection bypassed (flag=true)
        |        $syncInProgress = false
        |      } catch (\Throwable $e) {
        |        $logger->warning(...)   [D-07: slug + class + id + error]
        |      }
        |
        |  TenantContext::clear()     [restore to landlord context]
        |  $registry->resetManager('tenant')  [clean up tenant EM]

TENANT EM onFlush event
        |
        v
  SharedEntityWriteProtectionListener::onFlush()
        |
        |  [if $syncInProgress === true → return (re-entrancy bypass)]
        |  [if TenantContext::hasTenant() === false → return (no tenant active)]
        |
        |  Inspect UoW->getScheduledEntityInsertions()
        |          UoW->getScheduledEntityUpdates()
        |          UoW->getScheduledEntityDeletions()
        |
        |  For each entity with #[Shared] attribute:
        |    throw SharedEntityWriteInTenantContextException
        |
        v
  (tenant write blocked; D-02 enforced)
```

### Recommended Project Structure

```
src/
├── Attribute/
│   └── Shared.php                          # NEW: bare marker attribute (mirror TenantAware.php)
├── Exception/
│   └── SharedEntityWriteInTenantContextException.php  # NEW: extends \LogicException
├── Subscriber/
│   └── SharedEntitySyncSubscriber.php      # NEW: onFlush (buffer) + postFlush (fan-out)
│   └── SharedEntityWriteProtectionListener.php  # NEW: tenant EM onFlush guard
├── DependencyInjection/
│   └── Compiler/
│       └── SharedEntityMutualExclusionPass.php  # NEW: compile-time #[Shared]+#[TenantAware] guard
└── TenancyBundle.php                       # MODIFIED: register new compiler pass in build()
config/
└── services.php                            # MODIFIED: register subscriber + listener with tags
```

---

### Pattern 1: `#[Shared]` Attribute — mirror of `TenantAware.php`

**What:** A bare PHP 8.x class-target marker attribute.
**When to use:** Placed on any Doctrine entity that should be denormalized to all tenant EMs.

```php
// src/Attribute/Shared.php
// Source: mirrors src/Attribute/TenantAware.php exactly
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Attribute;

/**
 * Marks a Doctrine entity as a landlord-side master record to be synced read-only
 * into each active tenant's EntityManager via SharedEntitySyncSubscriber.
 *
 * MUST NOT be combined with #[TenantAware] — a compiler-pass guard enforces this
 * at container build time.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Shared
{
}
```

`[VERIFIED: codebase grep src/Attribute/TenantAware.php]` — exact mirror of the existing pattern.

---

### Pattern 2: Exception — mirror of `MissingFilesystemConfigException`

**What:** `extends \LogicException` + static factory, following the WR-01 no-retry invariant.
**When to use:** Thrown by `SharedEntityWriteProtectionListener` when a `#[Shared]` entity appears in tenant-EM scheduled writes.

```php
// src/Exception/SharedEntityWriteInTenantContextException.php
// Source: mirrors src/Exception/MissingFilesystemConfigException.php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Exception;

final class SharedEntityWriteInTenantContextException extends \LogicException
{
    public static function forEntity(string $entityClass, string $tenantSlug): self
    {
        return new self(sprintf(
            'tenancy: cannot write #[Shared] entity "%s" in tenant context "%s". '
            . 'Shared entities are read-only on the tenant side — write to the landlord EM instead.',
            $entityClass,
            $tenantSlug,
        ));
    }
}
```

`[VERIFIED: codebase grep src/Exception/MissingFilesystemConfigException.php]`

---

### Pattern 3: Changeset capture — `onFlush` buffering (CRITICAL)

**What:** Buffer `#[Shared]` entity changesets in `onFlush` because they are CLEARED before `postFlush`.
**Why:** `[VERIFIED: read vendor/doctrine/orm/src/UnitOfWork.php]` — `executeInserts()` runs `unset($this->entityInsertions[$oid])` for each entity. By the time `postFlush` fires at line 471, all three scheduled-entity arrays are empty.

**Verified ORM 3.6.3 UnitOfWork execution order:**
1. `onFlush` fires (line 379) — scheduled sets FULL, changeSets FULL
2. `executeInserts()` — clears `entityInsertions` per-entity (line 1057)
3. `executeUpdates()` — clears `entityUpdates` per-entity (line 1143)
4. `executeDeletions()` — clears `entityDeletions` per-entity (line 1821)
5. `conn->commit()` — landlord TX committed
6. `postFlush` fires (line 471) — scheduled sets EMPTY
7. `postCommitCleanup()` (line 473) — entity/changeSets arrays zeroed

**Relevant ORM 3.6.3 API signatures (all `[VERIFIED: vendor/doctrine/orm/src/UnitOfWork.php]`):**

```php
// UnitOfWork methods — available in onFlush, EMPTY in postFlush:
/** @phpstan-return array<int, object> */
public function getScheduledEntityInsertions(): array;   // line 3001

/** @phpstan-return array<int, object> */
public function getScheduledEntityUpdates(): array;       // line 3011

/** @phpstan-return array<int, object> */
public function getScheduledEntityDeletions(): array;     // line 3021

/** @phpstan-return array<string, array{mixed, mixed}|PersistentCollection> */
public function & getEntityChangeSet(object $entity): array;  // line 525
```

**Identifier extraction via ClassMetadata (ORM 3.6.3):**
```php
// Source: vendor/doctrine/orm/src/Mapping/ClassMetadata.php line 641
/** @return array<string, mixed> */
public function getIdentifierValues(object $entity): array;
```

**SharedEntitySyncSubscriber skeleton:**

```php
// src/Subscriber/SharedEntitySyncSubscriber.php
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

final class SharedEntitySyncSubscriber implements EventSubscriber
{
    /** @var array<string, array{entity: object, type: 'insert'|'update'|'delete'}> */
    private array $pendingChanges = [];

    private bool $syncInProgress = false;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantProviderInterface $tenantProvider,
        private readonly ManagerRegistry $registry,
        private readonly LoggerInterface $logger,
        private readonly string $driver,  // 'database_per_tenant' | 'shared_db'
    ) {}

    public function getSubscribedEvents(): array
    {
        return [Events::onFlush, Events::postFlush];
    }

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
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($this->isShared($entity)) {
                $this->pendingChanges[spl_object_id($entity)] =
                    ['entity' => $entity, 'type' => 'update'];
            }
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($this->isShared($entity)) {
                $this->pendingChanges[spl_object_id($entity)] =
                    ['entity' => $entity, 'type' => 'delete'];
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->pendingChanges) {
            return;
        }
        if ('shared_db' === $this->driver) {   // D-03: no-op
            $this->pendingChanges = [];
            return;
        }

        $changes = $this->pendingChanges;
        $this->pendingChanges = [];

        foreach ($changes as ['entity' => $entity, 'type' => $type]) {
            foreach ($this->tenantProvider->findAll() as $tenant) {
                $this->fanOutToTenant($args->getObjectManager(), $entity, $type, $tenant);
            }
        }
    }

    private function fanOutToTenant(
        EntityManagerInterface $landlordEm,
        object $entity,
        string $type,
        TenantInterface $tenant,
    ): void {
        try {
            $this->tenantContext->setTenant($tenant);
            $tenantEm = $this->registry->resetManager('tenant');

            $this->syncInProgress = true;
            $this->doSync($landlordEm, $tenantEm, $entity, $type);
            $this->syncInProgress = false;
        } catch (\Throwable $e) {
            $this->syncInProgress = false;
            $class = $entity::class;
            $meta  = $landlordEm->getClassMetadata($class);
            $ids   = $meta->getIdentifierValues($entity);
            $this->logger->warning('tenancy.shared_entity_sync_failed', [
                'tenant_slug'  => $tenant->getSlug(),
                'entity_class' => $class,
                'identifier'   => $ids,
                'error'        => $e->getMessage(),
            ]);
        } finally {
            $this->tenantContext->clear();
        }
    }

    public function isSyncInProgress(): bool
    {
        return $this->syncInProgress;
    }

    private function isShared(object $entity): bool
    {
        $rc = new \ReflectionClass($entity);
        return [] !== $rc->getAttributes(Shared::class);
    }
}
```

`[VERIFIED: UnitOfWork.php lines 379, 471, 473, 1057, 1143, 1821]`

---

### Pattern 4: Tenant-EM upsert — ORM 3.x `merge()` removed

**Critical finding:** `[VERIFIED: reflection of EntityManagerInterface in installed ORM 3.6.3]` — `merge()` is NOT in `EntityManagerInterface`. It was removed in ORM 3.0.

**Modern equivalent for insert/update fan-out:**

```php
// Source: ClassMetadata API verified in vendor/doctrine/orm/src/Mapping/ClassMetadata.php
private function doSync(
    EntityManagerInterface $landlordEm,
    EntityManagerInterface $tenantEm,
    object $entity,
    string $type,
): void {
    $class    = $entity::class;
    $landlordMeta = $landlordEm->getClassMetadata($class);
    $ids      = $landlordMeta->getIdentifierValues($entity);

    if ('delete' === $type) {
        $existing = $tenantEm->find($class, $ids);
        if (null !== $existing) {
            $tenantEm->remove($existing);
            $tenantEm->flush();
        }
        return;
    }

    // insert or update: find-or-new + scalar field copy (one level only)
    $existing = $tenantEm->find($class, $ids);
    if (null === $existing) {
        $tenantMeta = $tenantEm->getClassMetadata($class);
        $copy = $tenantMeta->newInstance();  // line 816 — no constructor invoked
    } else {
        $copy = $existing;
        $tenantMeta = $tenantEm->getClassMetadata($class);
    }

    // Copy scalar fields only — association fields skipped (DEC-SHARE-02 one-level boundary)
    foreach ($landlordMeta->getFieldNames() as $fieldName) {
        $value = $landlordMeta->getFieldValue($entity, $fieldName);
        $tenantMeta->setFieldValue($copy, $fieldName, $value);
    }

    $tenantEm->persist($copy);
    $tenantEm->flush();
}
```

Key ORM 3.6.3 ClassMetadata API (all `[VERIFIED: vendor/doctrine/orm/src/Mapping/ClassMetadata.php]`):
- `getFieldNames(): array` — line 2524 — returns all mapped scalar field names
- `getFieldValue(object $entity, string $field): mixed` — line 692 — reads via PropertyAccessor
- `setFieldValue(object $entity, string $field, mixed $value): void` — line 684
- `newInstance(): object` — line 816 — uses Doctrine instantiator (no constructor)
- `getIdentifierValues(object $entity): array<string, mixed>` — line 641
- `getAssociationNames(): array` — line 2532 — use to detect what to skip

---

### Pattern 5: Tenant EM write protection — `onFlush` guard

**What:** A second Doctrine event listener attached to TENANT EMs that throws `SharedEntityWriteInTenantContextException` when a `#[Shared]` entity appears in tenant-side scheduled writes.
**Re-entrancy bypass:** The guard holds a reference to `SharedEntitySyncSubscriber` and checks `$subscriber->isSyncInProgress()`. This distinguishes legitimate sync writes from user writes.

```php
// src/Subscriber/SharedEntityWriteProtectionListener.php
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

final class SharedEntityWriteProtectionListener implements EventSubscriber
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SharedEntitySyncSubscriber $syncSubscriber,
    ) {}

    public function getSubscribedEvents(): array
    {
        return [Events::onFlush];
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        // Bypass: no tenant active (landlord context or console without resolver)
        if (!$this->tenantContext->hasTenant()) {
            return;
        }
        // Bypass: this is a subscriber-originated sync write (re-entrancy guard D-02)
        if ($this->syncSubscriber->isSyncInProgress()) {
            return;
        }

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
}
```

---

### Pattern 6: Doctrine event listener tag wiring — connection-scoped

**Finding:** `[VERIFIED: vendor/symfony/doctrine-bridge/DependencyInjection/CompilerPass/RegisterEventListenersAndSubscribersPass.php lines 73-104]` — the `doctrine.event_listener` tag accepts a `connection` attribute. When `connection` is specified, the listener is registered ONLY with that connection's event manager. When omitted, it registers with ALL connections.

**For the landlord subscriber:**
```php
// config/services.php — inside if (interface_exists(EntityManagerInterface::class)) block
$services->set('tenancy.shared_entity_sync_subscriber', SharedEntitySyncSubscriber::class)
    ->args([
        service('tenancy.context'),
        service('tenancy.provider'),
        service('doctrine'),
        service('logger'),
        param('tenancy.driver'),
    ])
    ->tag('doctrine.event_listener', ['event' => Events::onFlush,   'connection' => 'landlord'])
    ->tag('doctrine.event_listener', ['event' => Events::postFlush, 'connection' => 'landlord']);
```

**For the tenant-EM write protection listener:**
```php
$services->set('tenancy.shared_entity_write_protection', SharedEntityWriteProtectionListener::class)
    ->args([
        service('tenancy.context'),
        service('tenancy.shared_entity_sync_subscriber'),
    ])
    ->tag('doctrine.event_listener', ['event' => Events::onFlush, 'connection' => 'tenant']);
```

**IMPORTANT:** In `database_per_tenant` mode there are `landlord` and `tenant` connections. In `shared_db` mode there is only `default`. The subscriber must check `$this->driver` and short-circuit for `shared_db` at runtime (D-03). The write-protection listener also only fires for the tenant connection.

**Registration guard:** Both services MUST be wrapped in `if (interface_exists(\Doctrine\ORM\EntityManagerInterface::class))` in `services.php`, following the established pattern.

---

### Pattern 7: Compiler-pass guard — `SharedEntityMutualExclusionPass`

**What:** At container compile time, scans all Doctrine entity metadata to find classes carrying both `#[Shared]` and `#[TenantAware]`. Throws `\LogicException` if found.

**Approach:** The D-04 guard cannot use a Doctrine metadata scan at compile time (the container is not yet booted). Instead, it must use PHP reflection directly on the classes declared in Doctrine entity mappings — the same approach used conceptually by Doctrine's mapping drivers. The practical approach is:

1. Collect all PHP files under mapped entity directories (from container parameters or by finding tagged entity-mapping service IDs).
2. Reflect on each class found; check `getAttributes(Shared::class)` and `getAttributes(TenantAware::class)`.
3. Throw if both are present.

**Alternative simpler approach (proven by ORM's own ResolveTargetEntityListener):** Register a `loadClassMetadata` event listener that checks at first metadata load time. However, this fires at runtime (first kernel request), not compile time. For true compile-time (D-04), the pass must read class files.

**Most practical compile-time approach:** Walk container definitions tagged with Doctrine entity-related service tags, or — more reliably — enumerate the `doctrine.orm.*.mappings` container parameters. For each mapped entity directory, use `nikic/php-parser` (already required) or native reflection.

**Simpler approach that mirrors FilesystemContractPass:** The pass can simply scan the bundle's own entity dir (`src/Entity/`) and the user's app entity dirs found via container parameter. At minimum, check all PHP classes that are already autoloaded (i.e., use `class_exists()` on known entity classes from container parameters). However, Doctrine entity scanning at compile time requires knowing what classes are entities.

**Recommended pattern:** Store a `tenancy.shared_entities` container parameter that the compiler pass reads. When `#[Shared]` entities are registered (auto-scanned or user-declared), the pass checks each class for `#[TenantAware]` co-presence. The simplest reliable approach: use the same PHP reflection approach that already works for Doctrine's attribute driver.

**Compiler pass skeleton:**
```php
// src/DependencyInjection/Compiler/SharedEntityMutualExclusionPass.php
final class SharedEntityMutualExclusionPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
            return;
        }

        // Collect entity classes from doctrine.orm.*.metadata_driver service IDs
        // or from the auto-discovered class list if available.
        // For each class: check co-presence of both attributes.
        foreach ($this->discoverEntityClasses($container) as $class) {
            if (!class_exists($class)) {
                continue;
            }
            $rc = new \ReflectionClass($class);
            $hasShared = [] !== $rc->getAttributes(\Tenancy\Bundle\Attribute\Shared::class);
            $hasTenantAware = [] !== $rc->getAttributes(\Tenancy\Bundle\Attribute\TenantAware::class);
            if ($hasShared && $hasTenantAware) {
                throw new \LogicException(sprintf(
                    'Entity "%s" cannot carry both #[Shared] and #[TenantAware]. '
                    . 'A shared entity is a landlord-side master; a TenantAware entity is '
                    . 'tenant-scoped. Pick one.',
                    $class,
                ));
            }
        }
    }
}
```

**Registration in TenancyBundle::build()** (mirrors existing pattern):
```php
// src/TenancyBundle.php — add to build() method
if (interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
    $container->addCompilerPass(new SharedEntityMutualExclusionPass());
}
```

**Entity class discovery at compile time:** The planner should research whether using `$container->getParameter('doctrine.entity_managers')` and walking `doctrine.orm.{em}_metadata_driver` service definitions is viable. A pragmatic alternative: have users register shared entities via a `tenancy.shared` container tag, and the pass validates tag-annotated service definitions — similar to how Doctrine's entity-listener resolver works.

`[ASSUMED]` — The exact compile-time entity-class discovery mechanism will need a concrete implementation decision during planning. The attribute-check logic is certain; the class enumeration mechanism is not.

---

### Pattern 8: One-level cascade boundary (DEC-SHARE-02)

**What:** `doSync()` copies scalar fields only. Association fields returned by `getAssociationNames()` are intentionally skipped.
**Landmine:** If a `#[Shared]` entity has a `ManyToOne` or `OneToOne` association to another entity, that associated entity is NOT synced unless it also carries `#[Shared]`. The tenant EM will receive a copy with the association field set to the foreign key value only — a broken reference unless the associated entity exists (via its own `#[Shared]` sync) in the tenant DB.
**Documentation requirement:** RESEARCH must flag this to the planner; the planner must produce a task that adds a docblock warning on `SharedEntitySyncSubscriber` and the user guide note (Phase 29 / DOC-20).

```php
// One-level boundary enforcement: skip associations
foreach ($landlordMeta->getFieldNames() as $fieldName) {
    // getFieldNames() returns scalar fields only — associations are NOT included
    // per ClassMetadata contract. This is the natural boundary (DEC-SHARE-02).
    $value = $landlordMeta->getFieldValue($entity, $fieldName);
    $tenantMeta->setFieldValue($copy, $fieldName, $value);
}
// DO NOT iterate getAssociationNames() — that would break the one-level boundary.
```

`[VERIFIED: ClassMetadata docs — getFieldNames() vs getAssociationNames() are separate]`

---

### Anti-Patterns to Avoid

- **Buffering in `postFlush` — WRONG.** By the time `postFlush` fires, `entityInsertions/Updates/Deletions` are all cleared. Must buffer in `onFlush`. `[VERIFIED: UnitOfWork.php lines 1057, 1143, 1821]`
- **Using `merge()` — REMOVED in ORM 3.x.** `merge()` was deprecated in ORM 2.x and removed in ORM 3.0. `[VERIFIED: reflection of installed ORM 3.6.3 EntityManagerInterface — no merge() method]`
- **No re-entrancy guard.** The write-protection listener fires on ALL tenant-EM flushes. Without the bypass flag, the subscriber's own `$tenantEm->flush()` would immediately throw `SharedEntityWriteInTenantContextException`.
- **Attaching subscriber to ALL connections.** Omitting `'connection' => 'landlord'` from the event listener tag would register it on the `tenant` connection too. The landlord subscriber must ONLY fire for landlord writes. `[VERIFIED: RegisterEventListenersAndSubscribersPass.php]`
- **Calling `$registry->getManager('tenant')` without `resetManager()` first.** The tenant EM holds stale identity maps between fan-out iterations. Always call `resetManager('tenant')` before using the tenant EM in the fan-out loop — same pattern as `DatabasePerTenantMiddlewareIntegrationTest` uses (line 67: `$registry->resetManager('tenant')`). `[VERIFIED: tests/Integration/DBAL/DatabasePerTenantMiddlewareIntegrationTest.php line 67]`
- **Not clearing `TenantContext` after each tenant in the loop.** If an exception is thrown mid-loop, `TenantContext` would be left in a tenant state. Use a `finally` block to call `$this->tenantContext->clear()`.
- **Skipping `interface_exists(\Doctrine\ORM\EntityManagerInterface::class)` guard.** All Doctrine wiring must be inside this guard. `[VERIFIED: config/services.php lines 60, 103]`
- **Using `#[AsEventListener]` on Doctrine event subscribers.** Doctrine subscribers are NOT Symfony event listeners. They must use the `doctrine.event_listener` service tag with `event` and optionally `connection` attributes. Do NOT use `autoconfigure(true)` for these services. `[VERIFIED: RegisterEventListenersAndSubscribersPass.php]`

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Object instantiation without constructor | Manual `new $class()` | `ClassMetadata::newInstance()` | Uses Doctrine instantiator; works with ORM proxy infrastructure |
| Field enumeration | Custom reflection loop | `ClassMetadata::getFieldNames()` | Already handles embedded objects, inheritance, column maps |
| Field read/write | `ReflectionProperty` access | `ClassMetadata::getFieldValue()` / `setFieldValue()` | Uses Doctrine PropertyAccessors; handles promoted props, lazy objects |
| Identity extraction | Manual field iteration | `ClassMetadata::getIdentifierValues()` | Handles composite PKs, FK identifiers |
| Attribute detection | `class_exists()` heuristics | `\ReflectionClass::getAttributes(Shared::class)` | PHP 8.x native; type-safe; cache-friendly |
| Tenant EM access | Direct service lookup | `ManagerRegistry::resetManager('tenant')` | Clears identity map; established pattern in this codebase |
| Log formatting | Custom array concat | PSR-3 `$logger->warning($msg, $context)` with context array | Structured logging for D-07 |

**Key insight:** Doctrine's ClassMetadata API replaces ALL custom field-iteration and object-cloning logic. The `getFieldNames()` / `getFieldValue()` / `setFieldValue()` trio provides a safe ORM-aware field copy that respects embedded objects, value types, and lazy loading infrastructure.

---

## Common Pitfalls

### Pitfall 1: Changeset arrays are empty in `postFlush`
**What goes wrong:** Subscribing only to `postFlush` and calling `$uow->getScheduledEntityInsertions()` returns an empty array — no entities are synced.
**Why it happens:** `[VERIFIED: UnitOfWork.php]` — `executeInserts()` runs `unset($this->entityInsertions[$oid])` for each entity as it processes them. Same for updates/deletions. The scheduled-entity arrays are fully drained before `postFlush` fires.
**How to avoid:** Buffer ALL changesets in `onFlush`. Store on the subscriber instance as `$pendingChanges = []`. `postFlush` then iterates the buffer.
**Warning signs:** Fan-out loop runs 0 iterations; no tenant writes occur; no log warnings.

### Pitfall 2: Missing re-entrancy guard breaks write protection
**What goes wrong:** The subscriber calls `$tenantEm->flush()` to apply the copy. This triggers `onFlush` on the tenant EM. The write-protection listener fires, sees the `#[Shared]` entity in the scheduled insertions, and throws — aborting the sync.
**Why it happens:** The write-protection guard cannot distinguish "sync-originated write" from "user write" without a flag.
**How to avoid:** Expose `isSyncInProgress(): bool` on `SharedEntitySyncSubscriber`. Set the flag to `true` before `$tenantEm->flush()`, reset it in a `finally` block.
**Warning signs:** Every fan-out attempt immediately throws `SharedEntityWriteInTenantContextException` with no user-originated write visible.

### Pitfall 3: Stale tenant EM identity map after previous fan-out iteration
**What goes wrong:** The second tenant in the fan-out loop receives a stale cached entity from the first tenant's identity map — data cross-contamination.
**Why it happens:** `getManager('tenant')` returns the same EM instance. Identity map accumulates across tenants.
**How to avoid:** Call `$registry->resetManager('tenant')` before reading/writing the tenant EM inside the loop. `[VERIFIED: DatabasePerTenantMiddlewareIntegrationTest.php line 67]` uses `resetManager('tenant')` before each schema operation.
**Warning signs:** Tenant B's sync contains data from Tenant A's prior fan-out.

### Pitfall 4: `TenantContext` left in tenant state after failed fan-out
**What goes wrong:** An exception in one tenant's fan-out leaves `TenantContext` pointing at that tenant. The next fan-out iteration (or the landlord request continuation) runs in the wrong tenant context.
**Why it happens:** No `finally` block around `TenantContext::clear()`.
**How to avoid:** Wrap the per-tenant fan-out in try/catch/finally. Always call `$this->tenantContext->clear()` in the `finally` block, even if the tenant write failed.
**Warning signs:** Subsequent landlord queries hit the wrong database; TenantContext has a stale tenant after postFlush.

### Pitfall 5: Subscriber fires on tenant connection (wrong EM)
**What goes wrong:** A tenant-side `flush()` triggers the landlord-subscriber `onFlush`. The subscriber buffers tenant entities as if they are landlord changes; next `postFlush` fans them out to ALL tenants (infinite loop / wrong data).
**Why it happens:** Event listener tag without `connection` attribute registers on ALL connections.
**How to avoid:** Always tag `doctrine.event_listener` with `'connection' => 'landlord'` for the sync subscriber. `[VERIFIED: RegisterEventListenersAndSubscribersPass.php]`
**Warning signs:** Fan-out triggering fan-out; exponential flush calls; subscriber `$pendingChanges` growing unboundedly.

### Pitfall 6: ORM `merge()` does not exist in ORM 3.x
**What goes wrong:** `$tenantEm->merge($entity)` triggers fatal error — method does not exist.
**Why it happens:** `merge()` was removed in ORM 3.0. Training data about "merge() for upsert" is stale.
**How to avoid:** Use find-or-new + field-copy via `ClassMetadata`. `[VERIFIED: reflection of EntityManagerInterface in ORM 3.6.3]`
**Warning signs:** `Call to undefined method EntityManagerInterface::merge()`.

### Pitfall 7: Association fields copied to tenant EM with broken references
**What goes wrong:** A `#[Shared]` entity has a `ManyToOne` to `Category`. `ClassMetadata::getAssociationNames()` includes `'category'`. If the loop copies association fields, Doctrine tries to load `Category` on the tenant EM where it may not exist.
**Why it happens:** Naive field copy without association filtering.
**How to avoid:** Use `getFieldNames()` only (scalar fields). Skip `getAssociationNames()`. The one-level cascade boundary is enforced by this field-selection. `[VERIFIED: ClassMetadata::getFieldNames() vs getAssociationNames()]`
**Warning signs:** `EntityNotFoundException` on the tenant EM during fan-out; associations pointing to landlord IDs that don't exist in the tenant DB.

---

## Code Examples

### Verified API: UnitOfWork scheduled entity methods (ORM 3.6.3)

```php
// Source: vendor/doctrine/orm/src/UnitOfWork.php lines 3001-3021
// Call these in onFlush, NOT postFlush

/** @phpstan-return array<int, object> */
public function getScheduledEntityInsertions(): array;

/** @phpstan-return array<int, object> */
public function getScheduledEntityUpdates(): array;

/** @phpstan-return array<int, object> */
public function getScheduledEntityDeletions(): array;
```

### Verified API: OnFlushEventArgs / PostFlushEventArgs (ORM 3.6.3)

```php
// Source: vendor/doctrine/orm/src/Event/OnFlushEventArgs.php
// Source: vendor/doctrine/orm/src/Event/PostFlushEventArgs.php
// Both extend Doctrine\Persistence\Event\ManagerEventArgs<EntityManagerInterface>

$em  = $args->getObjectManager();   // returns EntityManagerInterface
$uow = $em->getUnitOfWork();        // returns UnitOfWork
```

### Verified API: ClassMetadata field operations (ORM 3.6.3)

```php
// Source: vendor/doctrine/orm/src/Mapping/ClassMetadata.php
$meta = $em->getClassMetadata($entityClass);

$meta->getFieldNames(): array                         // scalar field names only
$meta->getAssociationNames(): array                   // association names (SKIP for one-level boundary)
$meta->getFieldValue(object $entity, string $field): mixed
$meta->setFieldValue(object $entity, string $field, mixed $value): void
$meta->getIdentifierValues(object $entity): array<string, mixed>
$meta->newInstance(): object                          // instantiates without constructor
```

### Verified API: EventSubscriber registration (Doctrine Common)

```php
// Source: vendor/doctrine/event-manager/src/EventSubscriber.php
use Doctrine\Common\EventSubscriber;

interface EventSubscriber {
    /** @return string[] */
    public function getSubscribedEvents();
}
```

### Verified API: ManagerRegistry for tenant EM access

```php
// Source: vendor/doctrine/persistence/src/Persistence/ManagerRegistry.php
// Pattern verified: DatabasePerTenantMiddlewareIntegrationTest.php line 67
$em = $registry->resetManager('tenant');   // returns EntityManagerInterface, clears identity map
```

### Verified: Doctrine event_listener tag with connection scoping

```php
// Source: RegisterEventListenersAndSubscribersPass.php lines 73-104
// Verified: 'connection' attribute scopes listener to ONE connection's event manager
->tag('doctrine.event_listener', ['event' => 'onFlush', 'connection' => 'landlord'])
->tag('doctrine.event_listener', ['event' => 'postFlush', 'connection' => 'landlord'])
->tag('doctrine.event_listener', ['event' => 'onFlush', 'connection' => 'tenant'])
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `EntityManager::merge()` for detached entity upsert | find-or-new + ClassMetadata field copy | ORM 3.0 | Must NOT use `merge()` — it does not exist |
| `EventSubscriberInterface` via Doctrine Common | Same (`Doctrine\Common\EventSubscriber`) — still valid | Still current in ORM 3.x | No change needed |
| `getEntityChangeSet()` available in postFlush | Changesets cleared BEFORE postFlush (via `executeInserts()` etc.) | ORM internals unchanged but often misunderstood | Must buffer in `onFlush` |

**Deprecated/outdated:**
- `EntityManager::merge()` — removed ORM 3.0; replaced by find-or-new + field copy
- `EntityManager::detach()` — still exists but not needed for this pattern
- `ReflectionProperty` for field access — superseded by `ClassMetadata::getFieldValue()` / `setFieldValue()` via PropertyAccessors

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Compiler-pass entity-class discovery: the planner will need to determine the exact mechanism for enumerating entity classes at compile time | Pattern 7 | If discovery mechanism is wrong, D-04 guard never fires |
| A2 | `'connection' => 'landlord'` event listener tag works with `shared_db` driver (which has no 'landlord' connection) | Pattern 6 | In `shared_db` mode with no 'landlord' connection, the tag may throw — must validate and conditionally omit the connection attribute |
| A3 | DoctrineTenantProvider::findAll() includes BOTH active and inactive tenants | Pattern 3 fan-out | Inactive tenants would receive sync writes if findAll() includes them — verify expected behavior |

---

## Open Questions (RESOLVED)

> All three questions were resolved by the planner during Phase 25 planning (2026-06-11).
> Resolutions are authoritative in the cited PLAN.md files; summarized inline below.
>
> - **RESOLVED Q1 (shared_db connection scoping)** → Plan 25-04: subscriber + write-protection
>   listener service registration lives INSIDE the `database.enabled` block (which implies
>   `database_per_tenant`), so under `shared_db` the services are never registered and no
>   missing `landlord`/`tenant` connection is referenced. The in-subscriber D-03 short-circuit
>   is belt-and-suspenders.
> - **RESOLVED Q2 (findAll inactive tenants)** → Plan 25-03: sync ALL tenants returned by
>   `findAll()`, including inactive; drift repair is deferred to Phase 26 `tenancy:shared:resync`.
> - **RESOLVED Q3 (compile-time entity enumeration)** → Plan 25-02: option (a) — entities are
>   discovered via a `tenancy.shared_entity` container service tag walked by `findTaggedServiceIds()`,
>   mirroring the `FilesystemContractPass` convention.

1. **shared_db + event listener tag connection scoping**
   - What we know: `shared_db` mode has only a `default` connection, no `landlord` or `tenant` connections
   - What's unclear: Does `'connection' => 'landlord'` on the event listener tag throw when there is no `landlord` connection registered?
   - Recommendation: Wrap subscriber registration in `database.enabled` check (which implies `database_per_tenant`); for `shared_db` register on `'connection' => 'default'` or omit the connection attribute and rely on the D-03 short-circuit inside the subscriber

2. **findAll() includes inactive tenants**
   - What we know: `DoctrineTenantProvider::findAll()` (line 65-74) returns ALL tenants including inactive, per its docblock ("Operator tools need visibility on all tenants")
   - What's unclear: Should the sync fan-out skip inactive tenants?
   - Recommendation: For Phase 25, sync ALL tenants returned by findAll() — including inactive. Drift repair via Phase 26 `tenancy:shared:resync` handles catch-up when a tenant is reactivated.

3. **Compiler-pass entity class enumeration**
   - What we know: No existing compiler pass in this bundle scans entity class attributes
   - What's unclear: The most reliable way to enumerate Doctrine entity classes at container compile time without booting the kernel
   - Recommendation: Planner options: (a) require users to tag shared-entity service definitions with `tenancy.shared`; (b) scan mapped entity directories from doctrine container parameters; (c) defer the mutual-exclusion check to a `loadClassMetadata` listener (runtime-first-boot instead of compile-time)

---

## Environment Availability

> Step 2.6: PHP Doctrine toolchain verified via `composer.json` and `vendor/`.

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| `doctrine/orm` | Core fan-out | Yes | 3.6.3 | — |
| `doctrine/dbal` | Tenant connection switching | Yes | 4.4.3 | — |
| `doctrine/doctrine-bundle` | Event listener tag wiring | Yes | 3.2.2 | — |
| `psr/log` | D-07 per-tenant error logging | Yes (transitive) | — | — |
| SQLite `:memory:` | Integration tests | Yes | — | — |
| PHPUnit 11 | Test suite | Yes | 11.5.55 | — |

**Missing dependencies with no fallback:** None.

---

## Validation Architecture

> Nyquist validation is enabled (`workflow.nyquist_validation: true` in `.planning/config.json`).

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5.55 |
| Config file | `phpunit.xml` or `phpunit.xml.dist` (root) |
| Quick run command | `vendor/bin/phpunit --testsuite unit` |
| Full suite command | `vendor/bin/phpunit` |
| Integration suite | `vendor/bin/phpunit --testsuite integration` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| SHARE-01-a | `#[Shared]` attribute is a bare class-target PHP attribute | Unit | `vendor/bin/phpunit --filter testSharedAttributeIsClassTarget` | No — Wave 0 |
| SHARE-01-b | `SharedEntitySyncSubscriber` is registered as `onFlush`+`postFlush` listener on landlord EM | Integration (container) | `vendor/bin/phpunit --filter testSubscriberWiredToLandlordEm` | No — Wave 0 |
| SHARE-01-c | On landlord flush, `#[Shared]` insert is fanned to all tenant EMs | Integration (SQLite) | `vendor/bin/phpunit --filter testInsertFansOutToAllTenants` | No — Wave 0 |
| SHARE-01-d | On landlord flush, `#[Shared]` update is fanned to all tenant EMs | Integration (SQLite) | `vendor/bin/phpunit --filter testUpdateFansOutToAllTenants` | No — Wave 0 |
| SHARE-01-e | On landlord flush, `#[Shared]` delete propagates to tenant EMs | Integration (SQLite) | `vendor/bin/phpunit --filter testDeleteFansOutToAllTenants` | No — Wave 0 |
| SHARE-01-f | Tenant-side persist of `#[Shared]` entity throws `SharedEntityWriteInTenantContextException` | Integration | `vendor/bin/phpunit --filter testTenantSidePersistThrows` | No — Wave 0 |
| SHARE-01-g | Tenant-side update of `#[Shared]` entity throws | Integration | `vendor/bin/phpunit --filter testTenantSideUpdateThrows` | No — Wave 0 |
| SHARE-01-h | Tenant-side delete of `#[Shared]` entity throws | Integration | `vendor/bin/phpunit --filter testTenantSideDeleteThrows` | No — Wave 0 |
| SHARE-01-i | Subscriber-initiated sync write bypasses write-protection guard | Integration | `vendor/bin/phpunit --filter testSyncWriteBypassesWriteProtection` | No — Wave 0 |
| SHARE-01-j | Subscriber is a no-op under `shared_db` driver | Unit (or integration) | `vendor/bin/phpunit --filter testNoOpUnderSharedDb` | No — Wave 0 |
| SHARE-01-k | Per-tenant failure is caught+logged, does not abort fan-out | Integration | `vendor/bin/phpunit --filter testPerTenantFailureIsLogged` | No — Wave 0 |
| SHARE-01-l | Compiler-pass throws when `#[Shared]` + `#[TenantAware]` co-present | Unit (compiler pass) | `vendor/bin/phpunit --filter testMutualExclusionGuardThrows` | No — Wave 0 |
| SHARE-01-m | Cascade depth limited: association fields on `#[Shared]` entity are NOT synced | Integration | `vendor/bin/phpunit --filter testAssociationsNotSynced` | No — Wave 0 |

### Integration Test Harness Shape

The integration tests for SHARE-01 follow the `DatabasePerTenantMiddlewareIntegrationTest.php` pattern exactly:

```
tests/Integration/SharedEntity/
├── SharedEntitySyncIntegrationTest.php    # fan-out (c,d,e), write protection (f,g,h,i), logging (k)
├── SharedEntityNoDatabaseKernelTest.php   # no-op under shared_db (j)
└── Support/
    ├── SharedEntitySyncTestKernel.php      # DoctrineTestKernel variant: landlord + 2 tenant DBs
    ├── Entity/
    │   └── TestPlan.php                    # #[Shared] entity with scalar fields only
    │   └── TestPlanWithAssociation.php     # #[Shared] entity with association (for cascade test)
    └── MakeSharedEntityServicesPublicPass.php
```

**Kernel configuration for integration tests:**
- Landlord: SQLite file `tenancy_shared_test_landlord.db`
- Tenant 1: SQLite file `tenancy_shared_test_tenant_a.db`
- Tenant 2: SQLite file `tenancy_shared_test_tenant_b.db`
- Uses `setUpBeforeClass()` / `tearDownAfterClass()` (established pattern)
- `TenantProviderInterface` replaced with a stub that returns 2 test tenants
- All three DBs pre-created with `SchemaTool::createSchema()` before tests run

### Sampling Rate
- **Per task commit:** `vendor/bin/phpunit --testsuite unit`
- **Per wave merge:** `vendor/bin/phpunit`
- **Phase gate:** Full suite green before `/gsd:verify-work`

### Wave 0 Gaps
All test files are NEW — no existing tests cover SHARE-01.

- [ ] `tests/Integration/SharedEntity/SharedEntitySyncIntegrationTest.php` — covers SHARE-01-c through SHARE-01-k
- [ ] `tests/Integration/SharedEntity/SharedEntityNoDatabaseKernelTest.php` — covers SHARE-01-j
- [ ] `tests/Integration/SharedEntity/Support/SharedEntitySyncTestKernel.php` — test kernel with landlord + 2 tenant DBs
- [ ] `tests/Integration/SharedEntity/Support/Entity/TestPlan.php` — test entity with `#[Shared]`
- [ ] `tests/Unit/Subscriber/SharedEntitySyncSubscriberTest.php` — covers SHARE-01-a, SHARE-01-b (DI wiring unit)
- [ ] `tests/Unit/DependencyInjection/Compiler/SharedEntityMutualExclusionPassTest.php` — covers SHARE-01-l

No framework install needed — PHPUnit 11 already in place.

---

## Security Domain

> `security_enforcement` is not explicitly false in config — treat as enabled.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | No | — |
| V3 Session Management | No | — |
| V4 Access Control | Yes — tenant data isolation | `TenantContext::hasTenant()` guard; write-protection exception blocks cross-tenant writes |
| V5 Input Validation | No — entity fields are ORM-managed | ClassMetadata field copy; no raw user input |
| V6 Cryptography | No | — |

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Tenant writes to `#[Shared]` entity, corrupting landlord master | Tampering | `SharedEntityWriteInTenantContextException` thrown by tenant-EM `onFlush` guard (D-02) |
| Cross-tenant data leak via association copy | Information Disclosure | One-level cascade: skip `getAssociationNames()` entirely (DEC-SHARE-02) |
| Stale TenantContext left after failed fan-out | Elevation of Privilege | `finally` block always calls `TenantContext::clear()` |
| Subscriber fires on wrong EM (tenant writes fanned out) | Tampering | `'connection' => 'landlord'` tag on subscriber; driver short-circuit on `shared_db` |
| `strict_mode` bypass via sync path | Information Disclosure | Sync subscriber does not participate in `strict_mode` filtering — it copies scalar fields explicitly; no SQL filter applied to landlord reads |

---

## Sources

### Primary (HIGH confidence)
- `vendor/doctrine/orm/src/UnitOfWork.php` — changeset lifecycle (lines 1057, 1143, 1821, 471, 473, 476–488); scheduled-entity API (lines 3001–3021); getEntityChangeSet (line 525)
- `vendor/doctrine/orm/src/Mapping/ClassMetadata.php` — getFieldNames (2524), getAssociationNames (2532), getIdentifierValues (641), getFieldValue (692), setFieldValue (684), newInstance (816)
- `vendor/doctrine/orm/src/Events.php` — Events::onFlush, Events::postFlush, Events::preFlush
- `vendor/doctrine/orm/src/Event/OnFlushEventArgs.php`, `PostFlushEventArgs.php` — getObjectManager() signature
- `vendor/doctrine/persistence/src/Persistence/Event/ManagerEventArgs.php` — getObjectManager(): ObjectManager
- `vendor/doctrine/event-manager/src/EventSubscriber.php` — EventSubscriber::getSubscribedEvents()
- `vendor/symfony/doctrine-bridge/DependencyInjection/CompilerPass/RegisterEventListenersAndSubscribersPass.php` — 'connection' tag attribute for EM scoping
- `vendor/doctrine/orm/src/EntityManagerInterface.php` — confirmed merge() absent in ORM 3.6.3; confirmed find(), getClassMetadata(), getMetadataFactory(), wrapInTransaction(), getUnitOfWork()
- `vendor/doctrine/persistence/src/Persistence/ManagerRegistry.php` — resetManager(string|null $name): ObjectManager
- `src/Attribute/TenantAware.php` — model for `src/Attribute/Shared.php`
- `src/Exception/MissingFilesystemConfigException.php` — model for `SharedEntityWriteInTenantContextException`
- `src/DependencyInjection/Compiler/FilesystemContractPass.php`, `MailerTransportContractPass.php` — compiler-pass convention for D-04
- `config/services.php` — DI wiring conventions, Doctrine guard pattern, event service tag examples
- `src/TenancyBundle.php` — build() method for compiler-pass registration
- `tests/Integration/DBAL/DatabasePerTenantMiddlewareIntegrationTest.php` — resetManager('tenant') pattern (line 67), two-tenant SQLite test harness
- `tests/Integration/Support/DoctrineTestKernel.php` — landlord + tenant EM test kernel pattern
- `composer.json` — Doctrine ORM `^3.3`, DBAL `^4.4`, DoctrineBundle `^2.13||^3.0` constraints

### Secondary (MEDIUM confidence)
- Installed package versions confirmed via `php -r "\Composer\InstalledVersions::getVersion()"`: ORM 3.6.3, DBAL 4.4.3, DoctrineBundle 3.2.2

### Tertiary (LOW confidence)
- Training knowledge about Doctrine ORM 3.x `merge()` removal — confirmed HIGH via reflection of installed ORM 3.6.3

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — all versions read from `composer.json` + installed packages
- Architecture: HIGH — all patterns derived from reading actual installed source files
- Pitfalls: HIGH — derived from verified UnitOfWork source (no speculation)
- Compiler-pass entity enumeration: LOW/ASSUMED — mechanism uncertain

**Research date:** 2026-06-11
**Valid until:** 2026-07-11 (Doctrine ORM 3.x API stable; internal UnitOfWork ordering verified against 3.6.3)
