# Shared Entities (SHARE-01/02/03)

Per-tenant replication of landlord-side Doctrine entities via `#[Shared]`. When an entity is
marked `#[Shared]`, every write on the landlord EntityManager is automatically fanned out to
every active tenant's EntityManager via `SharedEntitySyncSubscriber` — in the same request
synchronously, or asynchronously via Symfony Messenger.

This page covers the `#[Shared]` attribute, the synchronous and asynchronous sync models, the
`tenancy:shared:resync` command, write protection, and `shared_db` driver behavior. For the
`driver: shared_db` isolation strategy — one database, multiple tenants via SQL filter — see
[Shared-DB Driver](shared-db.md).

---

## Overview

The `#[Shared]` feature is designed for **database-per-tenant** projects. Each tenant has its
own isolated EntityManager, but some Doctrine entities — subscription plans, permission sets,
feature flags — need to appear identically in every tenant database.

The model is straightforward: one **landlord-side master** record on the landlord EntityManager
is the authoritative source of truth. Every tenant gets a **tenant-side read-only copy** — a
denormalized scalar-field mirror fanned out by `SharedEntitySyncSubscriber` on `postFlush`.

!!! note "Not the shared-DB driver"
    `#[Shared]` entity sync and the `driver: shared_db` isolation strategy are two different
    features. The `driver: shared_db` page covers a single-database, SQL-filter approach where
    there is no per-tenant EntityManager and no fan-out concept. The `#[Shared]` attribute is
    designed for `database_per_tenant` projects only. See [Shared-DB Driver](shared-db.md) and
    its [Shared Entities under `shared_db`](shared-db.md#shared-entities-shared-under-shared_db)
    section for how the attribute behaves when the subscriber detects `shared_db`.

---

## Marking Entities as Shared

Add the `#[Shared]` attribute (`Tenancy\Bundle\Attribute\Shared`) to any Doctrine entity that
must be synced to every tenant database:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Tenancy\Bundle\Attribute\Shared;

#[ORM\Entity]
#[ORM\Table(name: 'plans')]
#[Shared]
class Plan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $price;

    // getters / setters ...
}
```

`#[Shared]` is a zero-parameter `TARGET_CLASS` marker attribute — it carries no configuration.

!!! danger "Mutual exclusion with `#[TenantAware]`"
    A `#[Shared]` entity is a landlord-side master. A `#[TenantAware]` entity is tenant-scoped
    data. These two attributes are mutually exclusive on the same class. Placing both on a single
    entity is a compile-time error caught by `SharedEntityMutualExclusionPass`
    (`tenancy.mutualExclusion` in the [PHPStan Extension](phpstan-extension.md) also catches
    this at edit time).

---

## Sync Model (Default: Synchronous)

When a `#[Shared]` entity is written on the landlord EntityManager, `SharedEntitySyncSubscriber`
fans the change out to every tenant database in the same `postFlush` call.

The subscriber is registered on the **landlord EM connection only** (via `doctrine.event_listener`
tag `connection: landlord`). It buffers changesets in `onFlush` and applies or dispatches them
in `postFlush`. Fan-out covers **inserts, updates, and deletes**.

Fan-out is **best-effort**: if one tenant's apply step fails (e.g. network error, schema mismatch),
the error is caught and logged. The landlord transaction is never rolled back for a tenant-side
failure. The remaining tenants still receive the change.

!!! warning "One-level cascade boundary"
    `SharedEntityCopier::applyRow()` copies **scalar fields only** — the field list is
    `ClassMetadata::getFieldNames()`. Associations (`getAssociationNames()`) are intentionally
    skipped.

    **Consequence:** if a `#[Shared]` entity carries an association to a non-`#[Shared]` entity
    (e.g. `Plan` has a `ManyToOne` to `Category`), the association will be `null` on the tenant-side
    read-only copy. The referenced entity does not exist in the tenant database.

    **Fixes:**
    - Design shared entities to be **self-contained** with only scalar fields.
    - Mark associated entities `#[Shared]` as well so they are also synced and the association
      can be resolved on the tenant side.

---

## Async Mode

By default, fan-out happens synchronously in `postFlush`. For large numbers of tenants or
high-write workloads, you can route fan-out through Symfony Messenger:

```yaml
# config/packages/tenancy.yaml
tenancy:
    shared:
        async: true
```

When `async: true`, the subscriber dispatches one `Tenancy\Bundle\Message\SharedEntityChangedMessage`
per changed entity (not one per entity × tenant). The message carries:

- `string $entityClass` — the FQCN of the changed entity
- `array $identifier` — the scalar primary key identifier
- `string $changeType` — one of `insert`, `update`, `delete`

The full entity payload is **never** included. `SharedEntityChangedMessageHandler` re-fetches
the entity from the landlord database at handle time and applies the current state to each tenant.

A `SharedAsyncContractPass` compile-time guard fails when `tenancy.shared.async: true` and
`symfony/messenger` is absent — preventing a silent "all changes are lost" scenario.

!!! warning "You must configure transport routing"
    The bundle does **not** auto-route `SharedEntityChangedMessage` to a transport. If you do not
    add a `framework.messenger.routing` entry for the message class, Symfony handles the message
    synchronously inline — negating the async benefit. Add the routing entry explicitly:

    ```yaml
    # config/packages/messenger.yaml
    framework:
        messenger:
            routing:
                'Tenancy\Bundle\Message\SharedEntityChangedMessage': async
    ```

!!! warning "Tenants see latest state, not dispatch-time state"
    The handler re-fetches the **current** landlord-side row at handle time. If the entity is
    updated twice before the handler processes the first message, both messages will apply the
    same (latest) state. If the entity is deleted between dispatch and handle time, the handler
    treats the vanished row as a tenant-side delete and removes the tenant-side copy.

    In async mode, strict ordering is not guaranteed. Design your `#[Shared]` entities to be safe
    under idempotent last-write-wins semantics.

---

## `tenancy:shared:resync` Command

The `tenancy:shared:resync` command brings all tenant databases into sync with the landlord-side
master records. Use it after the first migration to backfill existing tenants, after a failed
async delivery window, or to diagnose and repair drift.

### Usage

```bash
# Classify drift for all tenants (dry-run — no writes)
bin/console tenancy:shared:resync --dry-run

# Live resync for all tenants (prompts for confirmation)
bin/console tenancy:shared:resync

# Live resync for a single tenant only
bin/console tenancy:shared:resync --tenant=acme

# Skip confirmation prompt (CI / non-interactive)
bin/console tenancy:shared:resync --force
```

### Flags

| Flag | Effect |
|------|--------|
| `--tenant=<slug>` | Sync a single tenant only. Absent = all tenants from `TenantProviderInterface::findAll()`. |
| `--dry-run` | Classify drift (would-insert / would-update / in-sync) without writing anything. |
| `--force` | Skip the `'Proceed with live resync?'` confirmation prompt. Safe for CI. |

Omitting `--tenant` targets all tenants — no separate "all" flag exists.

### Dry-Run Output

```
 Tenant        Would-Insert  Would-Update  In-Sync  Status
 ──────────────────────────────────────────────────────
 acme          3             1             12       ok
 demo          0             0             16       ok
 broken-corp   —             —             —        ERROR
```

The drift table columns are: **Tenant**, **Would-Insert**, **Would-Update**, **In-Sync**, **Status**.
A `STATUS=ERROR` row means the classify pass threw for that tenant (e.g. unreachable database).

### Live Resync

After the drift table, the command prompts:

```
Proceed with live resync? (yes/no) [no]:
```

The default answer is **no**. Pass `--force` to skip the prompt in non-interactive contexts.

Per-tenant output during apply:

```
 ✓ acme
 ✓ demo
 ✗ broken-corp (Connection refused: mysql:host=broken-corp-host;dbname=shared)
Completed: 2 succeeded, 1 failed
```

Fan-out continues even if one tenant fails. Exit code is `0` when all tenants succeed (or when
no tenants or no `#[Shared]` classes are found); `1` if any tenant failed.

### Idempotency

The command uses find-or-new logic — not Doctrine `merge()` (removed in ORM 3). Running it
multiple times on an in-sync database is safe.

### `shared_db` Driver

Under `shared_db`, `tenancy:shared:resync` prints an informational message and exits with
`Command::SUCCESS`. There are no per-tenant EntityManagers to sync to — the entity lives once
in the single shared database.

---

## Write Protection

Tenant-side copies are **read-only**. Any attempt to persist, update, or delete a `#[Shared]`
entity while a tenant context is active throws:

### `SharedEntityWriteInTenantContextException`

```php
Tenancy\Bundle\Exception\SharedEntityWriteInTenantContextException extends \LogicException
```

**Extends `\LogicException`, NOT `\RuntimeException`.** This is intentional (WR-01 no-retry
invariant): Symfony Messenger's default retry strategy retries `RuntimeException` subclasses.
A tenant-side write to a shared entity is a programmer or operator error, not a transient
fault — retrying would reproduce the same exception. The message goes to the DLQ immediately.

The exception is thrown by `SharedEntityWriteProtectionListener` in its `onFlush` handler when
a scheduled insert, update, or delete is detected for a `#[Shared]` entity in tenant context.

The static factory `SharedEntityWriteInTenantContextException::forEntity(string $entityClass, string $tenantSlug): self`
builds the exception message naming both the entity class and the offending tenant slug.

**Fix:** write to the **landlord-side master** EntityManager (the `landlord` named EM). The sync
subscriber will propagate the change to all tenant-side read-only copies automatically.

```php
// WRONG — throws SharedEntityWriteInTenantContextException
$tenantEm->persist($plan);
$tenantEm->flush();

// RIGHT — write to the landlord EM; sync subscriber fans out to all tenants
$landlordEm->persist($plan);
$landlordEm->flush();
```

---

## `shared_db` Driver Behavior

Under `driver: shared_db`, `SharedEntitySyncSubscriber` short-circuits immediately. When it
detects that the active driver is `shared_db`, the subscriber skips the `findAll()` call and
returns without dispatching any fan-out. Placing `#[Shared]` on an entity in a `shared_db`
project is **silently harmless** — the attribute is ignored.

This is the correct behavior: under `shared_db`, all tenants share one database and one
EntityManager. There are no per-tenant copies to create. See [Shared-DB Driver](shared-db.md)
for the `shared_db` isolation model.

---

## See also

- [Shared-DB Driver](shared-db.md) — the `driver: shared_db` isolation strategy (one database,
  SQL filter); a distinct feature from the `#[Shared]` entity sync model.
- [PHPStan Extension](phpstan-extension.md) — `tenancy.mutualExclusion` (catches `#[Shared]` +
  `#[TenantAware]` on the same class) and `tenancy.sharedEntityLeak` (catches querying a
  `#[Shared]` entity through the tenant EM).
- [CLI Commands](cli-commands.md) — full reference for all bundle commands.
- [Configuration Reference](configuration.md) — `tenancy.shared.*` keys.
- [UPGRADE.md → 0.3 to 0.4](https://github.com/danplaton4/tenancy-bundle/blob/master/UPGRADE.md#03-to-04) — opt-in adoption path; no breaking
  changes for existing projects.
