# PHPStan Extension (DX-03)

Three static-analysis rules for PHPStan Level 9 projects: `tenancy.mutualExclusion` catches
the forbidden `#[Shared]` + `#[TenantAware]` combination on a single class; `tenancy.sharedEntityLeak`
catches queries against a landlord-side master `#[Shared]` entity routed through the tenant
EntityManager; `tenancy.tenantIdDrift` catches `#[TenantAware]` entities with a missing,
nullable, or non-string `tenant_id` column.

This page covers installation, all three rules with violation examples and fixes, and the
`checkSharedEntityLeaks` parameter.

---

## Overview

The extension ships three PHPStan rules that complement the runtime guards in the bundle:

- **Rule 1 — `tenancy.mutualExclusion`**: edit-time version of `SharedEntityMutualExclusionPass`.
  Catches the contradictory `#[Shared]` + `#[TenantAware]` combination on the same class at
  analysis time, without requiring a `tenancy.shared_entity` container tag.
- **Rule 2 — `tenancy.sharedEntityLeak`**: catches queries routing a landlord-side master
  `#[Shared]` entity through the tenant EntityManager. A `#[Shared]` entity is a landlord-side
  master; reading it through the tenant EM risks returning the wrong tenant-side read-only copy
  or leaking landlord master data.
- **Rule 3 — `tenancy.tenantIdDrift`**: catches `#[TenantAware]` entities whose `tenant_id`
  column is absent, nullable, or typed as a non-string Doctrine type — a latent cross-tenant
  data leak that the SQL filter cannot handle correctly.

---

## Installation

=== "extension-installer (recommended)"

    ```bash
    composer require --dev phpstan/extension-installer
    ```

    `extension-installer` reads `composer.json#extra.phpstan.includes` and automatically
    includes `extension.neon`. All three rules run on the next `phpstan analyse` — no
    `phpstan.neon` changes required.

=== "Manual"

    Add the extension neon to your `includes` block:

    ```neon
    # phpstan.neon
    includes:
        - vendor/danplaton4/tenancy-bundle/extension.neon
    ```

=== "With phpstan-doctrine"

    When you also use `phpstan/phpstan-doctrine`, include the doctrine-aware fragment instead:

    ```neon
    # phpstan.neon
    includes:
        - vendor/danplaton4/tenancy-bundle/extension-doctrine.neon
    ```

    This fragment injects `ObjectMetadataResolver` for Rule 3's full ORM metadata path
    (entities mapped via XML or YAML, not only PHP attributes).

!!! danger "Never include both extension.neon and extension-doctrine.neon"
    Both files register all three rules. PHPStan does not deduplicate tagged rules — including
    both causes every error to fire **twice**. Use `extension-doctrine.neon` **instead of**
    `extension.neon` when `phpstan/phpstan-doctrine` is installed, never in addition to it.

---

## Rule 1: Mutual Exclusion (`tenancy.mutualExclusion`)

Fires when a single class carries both `#[Shared]` and `#[TenantAware]`. A `#[Shared]` entity
is a landlord-side master record synced to all tenants; a `#[TenantAware]` entity is
tenant-scoped data filtered by `TenantAwareFilter`. These two roles are mutually exclusive.

```php
<?php
// VIOLATION: both attributes on the same class
use Tenancy\Bundle\Attribute\Shared;
use Tenancy\Bundle\Attribute\TenantAware;

#[Shared]
#[TenantAware]
class Plan
{
    // ERROR: Entity App\Entity\Plan cannot carry both #[Shared] and #[TenantAware].
    //        A shared entity is a landlord-side master; a TenantAware entity is tenant-scoped.
    //        Pick one.
}
```

```php
<?php
// FIX: pick one attribute
use Tenancy\Bundle\Attribute\Shared;

#[Shared]
class Plan
{
    // landlord-side master — synced to all tenants as a read-only copy
}
```

Rule 1 fires unconditionally — it is not gated by `checkSharedEntityLeaks`.

---

## Rule 2: Shared Entity Leak (`tenancy.sharedEntityLeak`)

Fires when the concrete `Doctrine\ORM\EntityManager` class (not the `EntityManagerInterface`)
calls `find()`, `getReference()`, or `getRepository()` with a `#[Shared]` entity class as the
first argument. A `#[Shared]` entity is a landlord-side master; querying it through the tenant
EntityManager risks operating on the wrong tenant-side read-only copy.

```php
<?php
// VIOLATION: concrete EntityManager (not interface) querying a #[Shared] entity
use Doctrine\ORM\EntityManager;
use App\Entity\Plan;

class PlanService
{
    public function __construct(private EntityManager $em) {}

    public function find(int $id): ?Plan
    {
        return $this->em->find(Plan::class, $id);
        // ERROR: Entity App\Entity\Plan is #[Shared]. Querying it through the tenant
        //        EntityManager risks a cross-tenant data leak. Route the query through
        //        the named landlord EntityManager.
    }
}
```

```php
<?php
// FIX A: inject the named landlord EM using MapEntity
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;

class PlanService
{
    public function __construct(
        #[MapEntity(entityManagerName: 'landlord')]
        private EntityManagerInterface $landlordEm,
    ) {}

    public function find(int $id): ?Plan
    {
        return $this->landlordEm->find(Plan::class, $id);
    }
}
```

```php
<?php
// FIX B: suppress per-site when you have verified the query is on the landlord path
/** @phpstan-ignore tenancy.sharedEntityLeak */
return $this->em->find(Plan::class, $id);
```

**Rule 2 is conservative by design.** It fires **only** when the caller type is the concrete
`Doctrine\ORM\EntityManager` class. Interface-typed callers (`EntityManagerInterface`) are
silent — PHPStan cannot statically distinguish the landlord EM from the tenant EM when both
are typed as the interface. Rename your injection target or suppress per-site.

!!! note "Disabling Rule 2 project-wide"
    ```neon
    # phpstan.neon
    parameters:
        tenancy:
            checkSharedEntityLeaks: false
    ```

    This disables Rule 2 (`tenancy.sharedEntityLeak`) only. Rules 1 and 3 fire unconditionally
    regardless of this setting.

---

## Rule 3: Tenant ID Drift (`tenancy.tenantIdDrift`)

Fires when a `#[TenantAware]` entity has a `tenant_id` column that is missing, nullable, or
typed as a non-string Doctrine type. `TenantAwareFilter` constructs the SQL constraint as
`{alias}.tenant_id = '<slug>'` — a non-nullable string comparison. A missing, nullable, or
integer-typed column produces incorrect SQL or a runtime error.

```php
<?php
// VIOLATION A: missing tenant_id column entirely
use Tenancy\Bundle\Attribute\TenantAware;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[TenantAware]
class Invoice
{
    #[ORM\Column]
    private int $amount;
    // ERROR: Class App\Entity\Invoice is #[TenantAware] but has no column mapped to tenant_id.
}
```

```php
<?php
// VIOLATION B: tenant_id is nullable
#[ORM\Entity]
#[TenantAware]
class Invoice
{
    #[ORM\Column(length: 63, nullable: true)]
    private ?string $tenantId;
    // ERROR: tenant_id on App\Entity\Invoice is nullable — nullable tenant_id prevents scoping.
}
```

```php
<?php
// VIOLATION C: non-string Doctrine type
#[ORM\Entity]
#[TenantAware]
class Invoice
{
    #[ORM\Column(type: 'integer')]
    private int $tenantId;
    // ERROR: tenant_id on App\Entity\Invoice has type 'integer' — non-string type is
    //        incompatible with TenantAwareFilter's quoted-string comparison.
}
```

```php
<?php
// FIX: non-nullable string column
#[ORM\Entity]
#[TenantAware]
class Invoice
{
    #[ORM\Column(length: 63)]
    private string $tenantId;
}
```

Accepted Doctrine types for `tenant_id` (case-insensitive): `string`, `ascii_string`, `guid`,
`uuid`. No length assertion is made — any string-family type is accepted.

Rule 3 fires unconditionally — it is not gated by `checkSharedEntityLeaks`.

---

## See also

- [Shared Entities](shared-entities.md) — `#[Shared]` attribute sync model; a landlord-side
  master fanned out as a tenant-side read-only copy to every tenant database.
- [Shared-DB Driver](shared-db.md) — `driver: shared_db` isolation strategy (SQL filter
  approach; distinct from `#[Shared]` entity sync).
- [Strict Mode](strict-mode.md) — handling non-tenant contexts safely with `#[TenantAware]`.
- [Configuration Reference](configuration.md) — `tenancy.shared.*` keys.
