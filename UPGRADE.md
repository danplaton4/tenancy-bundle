# Upgrade Guide

## Upgrading to 0.1

### Requirements

- **PHP**: `^8.2` (8.2, 8.3, and 8.4 are tested in CI)
- **Symfony**: `^7.4` or `^8.0`

### Optional Dependencies

The bundle's core requires only Symfony components. Install optional packages based on the features you need:

| Feature | Required packages |
|---------|-------------------|
| Database-per-tenant | `doctrine/dbal` ^4.4, `doctrine/doctrine-bundle` ^2.13 or ^3.0, `doctrine/orm` ^3.3 |
| Shared-DB (`#[TenantAware]`) | `doctrine/dbal` ^4.4, `doctrine/doctrine-bundle` ^2.13 or ^3.0, `doctrine/orm` ^3.3 |
| `tenancy:migrate` command | All of the above + `doctrine/migrations` ^3.9 |
| Messenger context propagation | `symfony/messenger` ^7.4 or ^8.0 |

All optional features are guarded by `class_exists()` / `interface_exists()` checks. The bundle will not error if a package is missing — the feature simply won't be registered.

### Configuration

After installing, Symfony Flex creates `config/packages/tenancy.yaml` with defaults:

```yaml
tenancy:
    driver: database_per_tenant   # or shared_db
    strict_mode: true             # throws TenantMissingException when no tenant is active
    database:
        enabled: false            # set to true for database-per-tenant driver
```

### Strict Mode

Strict mode is **on by default**. When enabled, querying a `#[TenantAware]` entity without an active tenant throws `TenantMissingException`. To allow unscoped queries (e.g., in admin panels), set `strict_mode: false` in your config.

### Breaking Changes

This is the initial `0.x` release. The public API is still stabilizing — minor releases on the `0.x` line may include breaking changes as architectural issues identified in early adopter feedback are addressed. A stable `1.0` will be tagged once those are resolved.