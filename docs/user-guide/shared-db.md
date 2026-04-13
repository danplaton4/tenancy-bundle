# Shared-DB Driver

In shared-DB mode, all tenants share one database. A Doctrine SQL filter (`TenantAwareFilter`)
automatically appends `WHERE tenant_id = '<slug>'` to every query for entities marked with the
`#[TenantAware]` attribute. Simpler to operate than database-per-tenant — one database, one set
of migrations — but with less physical isolation.

## Overview

- One database, multiple tenants
- Doctrine SQL filter scopes all queries for `#[TenantAware]` entities automatically
- No DBAL connection switching — the filter operates at the SQL level
- Works with any DBAL-supported database (MySQL, PostgreSQL, SQLite)

## Configuration

=== "YAML"

    ```yaml
    # config/packages/tenancy.yaml
    tenancy:
        driver: shared_db
        strict_mode: true  # default — throws on query without active tenant
    ```

=== "PHP"

    ```php
    // config/packages/tenancy.php
    return static function (Tenancy\Bundle\TenancyBundle $tenancy): void {
        $tenancy->driver('shared_db');
        $tenancy->strictMode(true);
    };
    ```

!!! danger "Never combine `shared_db` with `database.enabled: true`"
    Setting both `driver: shared_db` AND `database.enabled: true` is rejected at compile time
    with a clear error. These are mutually exclusive isolation strategies — pick one. The shared-DB
    driver uses the default entity manager; no tenant EM is needed.

## Marking Entities as Tenant-Aware

Add the `#[TenantAware]` attribute to any Doctrine entity that should be scoped per tenant. The
entity **must** have a `tenant_id VARCHAR(63)` column:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Tenancy\Bundle\Attribute\TenantAware;

#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\Table(name: 'invoices')]
#[TenantAware]
class Invoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 63)]
    private string $tenantId;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 255)]
    private string $description;

    // ... getters / setters
}
```

The `tenant_id` column stores the tenant's slug. You are responsible for setting it when creating
entities:

```php
$invoice = new Invoice();
$invoice->setTenantId($tenantContext->getTenant()->getSlug());
$invoice->setAmount('99.99');
$invoice->setDescription('Pro plan subscription');
$em->persist($invoice);
$em->flush();
```

## How the SQL Filter Works

When a request boots the tenant context, `SharedDriver::boot()` injects `TenantContext` into the
`TenantAwareFilter` instance. For every query involving an entity class, Doctrine calls
`TenantAwareFilter::addFilterConstraint()`:

```
Entity has #[TenantAware]?
  No  → return '' (no constraint — entity is unscoped)
  Yes → Is a tenant active?
          No + strict_mode → throw TenantMissingException
          No + permissive  → return '' (returns all rows — dangerous!)
         Yes → return "{alias}.tenant_id = '<slug>'"
```

The resulting SQL looks like:

```sql
-- Without filter
SELECT i.* FROM invoices i WHERE i.status = 'pending';

-- With TenantAwareFilter (tenant slug = 'acme')
SELECT i.* FROM invoices i WHERE i.status = 'pending' AND i.tenant_id = 'acme';
```

The filter is automatically registered in Doctrine via `prependExtension` when `driver: shared_db`
is configured — no manual Doctrine filter configuration required.

## Strict Mode

With `strict_mode: true` (default), querying a `#[TenantAware]` entity without an active tenant
throws `TenantMissingException`. This prevents accidental full-table scans in console commands or
background jobs that run without tenant context.

!!! danger "Disable strict mode with caution"
    Setting `strict_mode: false` makes the filter return all rows when no tenant is active. Any
    console command, async job, or code path that runs without a resolved tenant will silently
    return data from **all** tenants. This is a **data leak**. Only disable strict mode if every
    unguarded code path is explicitly safe to handle cross-tenant data.

See [Strict Mode](strict-mode.md) for strategies to handle non-tenant code paths.

## Mixed Entities

Entities **without** `#[TenantAware]` are completely unaffected by the filter. They return full
result sets regardless of tenant context. Use this for genuinely shared data:

```php
// No #[TenantAware] — shared across all tenants
#[ORM\Entity]
class Country
{
    #[ORM\Id]
    #[ORM\Column(length: 2)]
    private string $code;

    #[ORM\Column(length: 100)]
    private string $name;
}
```

This is useful for lookup tables, user profiles (if users span tenants), or any global data.

## Inheritance Hierarchies

In Single Table Inheritance (STI) or Joined Table Inheritance (JTI), place `#[TenantAware]` on
the **root entity only**. Doctrine passes root entity metadata to `addFilterConstraint()`, so
child entities are automatically scoped.

```php
#[ORM\Entity]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type')]
#[TenantAware]          // <-- on root only
class Document { ... }

#[ORM\Entity]
class Invoice extends Document { ... }  // inherits tenant scoping

#[ORM\Entity]
class Receipt extends Document { ... }  // inherits tenant scoping
```

## See Also

- [Database-per-Tenant Driver](database-per-tenant.md) — maximum isolation with separate databases
- [Strict Mode](strict-mode.md) — handling non-tenant contexts safely
- [Examples: API Header](examples/api-header.md) — shared-DB with REST API example
