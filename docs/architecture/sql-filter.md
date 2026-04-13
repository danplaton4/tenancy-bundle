# SQL Filter Internals

`TenantAwareFilter` is a Doctrine SQL Filter that automatically appends `WHERE tenant_id = 'slug'` to any query involving an entity marked with `#[TenantAware]`. This page explains how the filter works, its four-branch `addFilterConstraint()` logic, and the strict mode safety guarantee.

## Overview

Doctrine SQL Filters intercept every DQL query and can append SQL conditions to the generated `WHERE` clause. When the `tenancy_aware` filter is enabled and a tenant is active:

```sql
-- Without filter:
SELECT i.* FROM invoices i WHERE i.status = 'open'

-- With filter (tenant 'acme' active):
SELECT i.* FROM invoices i WHERE i.status = 'open' AND i.tenant_id = 'acme'
```

The filter is applied at the SQL level — it works regardless of whether you use DQL, `QueryBuilder`, or `EntityRepository::find()`.

---

## SQLFilter Base Class and Setter Injection

Doctrine's `SQLFilter` has a **final constructor** that accepts only `EntityManagerInterface`. Custom filters cannot add constructor parameters.

`TenantAwareFilter` receives its dependencies via a setter method that `SharedDriver::boot()` calls:

```php
final class TenantAwareFilter extends SQLFilter
{
    private ?TenantContext $tenantContext = null;
    private bool $strictMode = true;

    public function setTenantContext(TenantContext $context, bool $strictMode): void
    {
        $this->tenantContext = $context;
        $this->strictMode = $strictMode;
    }
}
```

`SharedDriver::boot()` retrieves the filter instance from the EntityManager's filter collection and injects the context:

```php
public function boot(TenantInterface $tenant): void
{
    /** @var TenantAwareFilter $filter */
    $filter = $this->em->getFilters()->getFilter('tenancy_aware');
    $filter->setTenantContext($this->tenantContext, $this->strictMode);
}
```

The filter must be enabled in Doctrine config before this call can succeed. `TenancyBundle::prependExtension()` handles this automatically when `driver: shared_db` is configured.

---

## addFilterConstraint() Logic

The core method that Doctrine calls for every filtered entity:

```php
public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
{
    // Branch 1: null guard — context not yet injected
    if (null === $this->tenantContext) {
        return '';
    }

    // Branch 2: entity not marked #[TenantAware] — skip
    $reflClass = $targetEntity->reflClass;
    if (null === $reflClass || empty($reflClass->getAttributes(TenantAware::class))) {
        return '';
    }

    // Branch 3/4: TenantAware entity — check active tenant
    $tenant = $this->tenantContext->getTenant();
    if (null === $tenant) {
        if ($this->strictMode) {
            throw new TenantMissingException($targetEntity->getName());  // Branch 3
        }
        return '';  // Branch 4
    }

    return sprintf(
        "%s.tenant_id = '%s'",
        $targetTableAlias,
        addslashes($tenant->getSlug())
    );
}
```

### Branch 1: Null Guard

When `tenantContext` is null (i.e. `setTenantContext()` was never called), the filter returns `''` silently. This prevents crashes in console commands or test contexts that boot Doctrine before `SharedDriver::boot()` runs.

**Example:** A console command that queries `User` entities (not tenant-aware) would boot Doctrine before any tenant context exists. Without this guard, the filter would throw a null reference error.

### Branch 2: Not TenantAware — No Filter Applied

If the entity does not have the `#[TenantAware]` attribute, the filter returns `''` — Doctrine does not append any SQL condition. Shared entities (e.g. `User`, `Permission`, `Country`) are never tenant-scoped.

Attribute detection uses PHP's `ReflectionClass::getAttributes()`:

```php
$reflClass->getAttributes(TenantAware::class)
```

This checks the entity class itself. For inheritance hierarchies (STI/CTI), place `#[TenantAware]` on the **root entity** — Doctrine passes root metadata to `addFilterConstraint()`.

### Branch 3: TenantAware + No Tenant + Strict Mode — Exception

!!! danger "Data leak risk with strict_mode: false"
    When `strict_mode` is `false` and no tenant is active, Branch 4 returns `''` — meaning all rows from all tenants are returned. This is a **cross-tenant data leak**. Strict mode is `true` by default for this reason.

If the entity is `#[TenantAware]`, no tenant is active, and `strictMode` is `true`:

```php
throw new TenantMissingException($targetEntity->getName());
```

`TenantMissingException` signals that a tenant-scoped query was attempted without a tenant context. This is a programming error, not a runtime condition — it should never happen in a correctly-bootstrapped request.

### Branch 4: TenantAware + No Tenant + Permissive Mode — No Filter

When `strict_mode` is `false` and no tenant is active, the filter returns `''`. All rows are returned, unscoped. This mode exists for admin tooling that intentionally queries across all tenants.

### Tenant Active — Scoped Query

When a tenant is active, the filter appends:

```
{alias}.tenant_id = 'slug'
```

The `addslashes()` call escapes single quotes in the slug to prevent SQL injection in the generated condition.

---

## The #[TenantAware] Attribute

```php
#[\Attribute(\Attribute::TARGET_CLASS)]
final class TenantAware
{
}
```

Applied to entities that must be tenant-scoped:

```php
#[ORM\Entity]
#[TenantAware]
class Invoice
{
    #[ORM\Column]
    public string $tenant_id;

    // ...
}
```

The entity must have a `tenant_id` column (VARCHAR). The filter injects the tenant slug into the query — it does not automatically populate the `tenant_id` column on `persist()`. That is the responsibility of application code or a Doctrine `PrePersist` listener.

---

## SharedDriver::clear() is an Intentional No-Op

```php
public function clear(): void
{
    // No action needed. TenantContext::clear() is called by BootstrapperChain
    // before this method runs. The filter reads TenantContext::hasTenant()
    // live at query time, so it will correctly throw or return '' on next query.
}
```

The `TenantAwareFilter` does **not** need to be disabled explicitly. When `TenantContext::clear()` runs (called by `BootstrapperChain` during teardown), `getTenant()` returns `null`. On the next query, `addFilterConstraint()` enters Branch 3 or 4 — either throwing or returning no constraint. Disabling the filter would be redundant.

This is intentional: re-enabling the filter after each request would require re-calling `setTenantContext()`. Reading the live `TenantContext` on every query is simpler and correct.

---

## SQL Output Examples

### Normal tenant request

```sql
-- Entity: Invoice (has #[TenantAware]), tenant 'acme' active
SELECT i.id, i.amount, i.status
FROM invoices i
WHERE i.tenant_id = 'acme'
```

### Mixed query (TenantAware + shared entity join)

```sql
-- Invoice is TenantAware; User is not
SELECT i.id, u.email
FROM invoices i
INNER JOIN users u ON u.id = i.user_id
WHERE i.tenant_id = 'acme'
-- No filter on users — User has no #[TenantAware] attribute
```

### Admin query with strict_mode: false

```sql
-- No tenant active, strict_mode: false — all rows returned
SELECT i.id, i.amount
FROM invoices i
-- No tenant_id condition — cross-tenant query
```

---

## Filter Registration

The filter is registered via `TenancyBundle::prependExtension()` when `driver: shared_db`:

```yaml
# Prepended automatically — no user config needed
doctrine:
    orm:
        filters:
            tenancy_aware:
                class: Tenancy\Bundle\Filter\TenantAwareFilter
                enabled: true
```

Setting `enabled: true` at registration means Doctrine enables the filter on EntityManager construction. `SharedDriver::boot()` then injects `TenantContext` into the already-enabled filter instance.
