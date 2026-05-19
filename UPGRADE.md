# Upgrade Guide

## 0.2 to 0.3

Phase 20 introduces per-tenant Mailer support. The contract change is a
**BC break** on `TenantInterface`: three new abstract methods are required.
Two migration paths are documented below — pick whichever fits your codebase.

### TenantInterface — three new methods (BC break)

Any class previously implementing `TenantInterface` (a custom Tenant entity,
a test stub, anything that holds tenant identity) must declare three new
methods or PHP will fail at autoload time with
`must implement method getMailerDsn`:

```php
public function getMailerDsn(): ?string;
public function getMailerFrom(): ?string;
public function getMailerReplyTo(): ?string;
```

All three return a nullable string. Returning `null` from any of them is the
"landlord fallback" — the application's default Mailer DSN / From / Reply-To
will be used for that tenant. The bundle's own `Tenancy\Bundle\Entity\Tenant`
ships with these methods preconfigured (see the inlined columns in
`src/Entity/Tenant.php`). User-land tenants must opt in via one of the
following migration paths.

### Migration path A: use TenantMailerConfigTrait (recommended)

The bundle ships a drop-in trait that adds the three nullable columns and
the six getter/setter methods at once:

```php
use Tenancy\Bundle\Mailer\TenantMailerConfigTrait;

class Tenant implements TenantInterface
{
    use TenantMailerConfigTrait; // satisfies getMailerDsn/From/ReplyTo + adds 3 columns

    // ... your existing properties and methods ...
}
```

The trait declares `#[ORM\Column(type: 'string', length: 255, nullable: true)]`
on each of the three new properties (`mailerDsn`, `mailerFrom`, `mailerReplyTo`).
Running `bin/console doctrine:migrations:diff` after adding the trait will
generate a migration adding the columns `mailer_dsn`, `mailer_from`, and
`mailer_reply_to` to your tenants table.

### Migration path B: manual implementation

If you want different column names, types, or storage strategy, implement
the three methods by hand. Mirror the inline shape used in the bundle's own
`Tenant` entity:

```php
#[ORM\Column(type: 'string', length: 255, nullable: true)]
private ?string $mailerDsn = null;

#[ORM\Column(type: 'string', length: 255, nullable: true)]
private ?string $mailerFrom = null;

#[ORM\Column(type: 'string', length: 255, nullable: true)]
private ?string $mailerReplyTo = null;

public function getMailerDsn(): ?string { return $this->mailerDsn; }
public function setMailerDsn(?string $dsn): self { $this->mailerDsn = $dsn; return $this; }

public function getMailerFrom(): ?string { return $this->mailerFrom; }
public function setMailerFrom(?string $from): self { $this->mailerFrom = $from; return $this; }

public function getMailerReplyTo(): ?string { return $this->mailerReplyTo; }
public function setMailerReplyTo(?string $replyTo): self { $this->mailerReplyTo = $replyTo; return $this; }
```

Either path satisfies the interface. Pick **path A** if you want defaults
and a single-line addition; pick **path B** if your entity has a non-standard
column naming convention, you want different column types (e.g. `text` for
multi-line DSNs), or you need encrypted-at-rest storage with a custom Doctrine
type.

### Migration SQL snippet

For users without `doctrine/migrations` (or who prefer raw SQL), the
equivalent ALTER for the bundle's default `tenancy_tenants` table is:

```sql
ALTER TABLE tenancy_tenants
    ADD COLUMN mailer_dsn VARCHAR(255) DEFAULT NULL,
    ADD COLUMN mailer_from VARCHAR(255) DEFAULT NULL,
    ADD COLUMN mailer_reply_to VARCHAR(255) DEFAULT NULL;
```

If your tenant table is named something other than `tenancy_tenants`,
substitute the actual table name. All three columns are nullable on existing
engines, so this is a non-blocking online ALTER on MySQL 8+, PostgreSQL 11+,
and SQLite — no downtime, no data backfill required (a null mailerDsn falls
back to your application's default Mailer DSN).

For full BOOT-04 context — bootstrapper architecture, per-tenant transport
cache, DSN sanitization, async Messenger interop — see the user-guide page
`docs/user-guide/mailer-bootstrapper.md` (coming in the v0.3 docs refresh).

## 0.1 to 0.2

Phase 15 applied four architectural fixes. This section covers behavior changes and
migration recipes.

### 1. Cache decorator works out of the box (Fix #5)

No user action required. Services that type-hint `CacheInterface`, `CacheItemPoolInterface`,
`TagAwareCacheInterface`, `PruneableInterface`, or `ResettableInterface` now resolve to
the tenant-aware decorator without `TypeError`. A new `CacheDecoratorContractPass`
compiler-pass guards against future regressions: if a Tenancy cache decorator is missing
any `Symfony\*` interface exposed by the decorated `cache.app` service, container
compilation fails with a clear `LogicException`.

### 2. ResolverChain nullable semantics (Fix #6) — **behavior change**

`ResolverChain::resolve()` now returns `?TenantResolution` instead of throwing on no
match. If your code caught `TenantNotFoundException` in a `kernel.exception` listener to
customize the 404 page for "no tenant matched", the exception will no longer fire for
that case — those requests proceed normally with an empty `TenantContext`. To preserve
a 404 for routes that require a tenant:

- Add an explicit `!$this->tenantContext->hasTenant()` check + `throw new TenantNotFoundException`
  in your controller (simple, explicit).
- Or wait for the `#[RequiresTenant]` attribute (see backlog) — the bundle will 404
  automatically on missing tenant for annotated controllers.

`DoctrineTenantProvider::findBySlug()` still throws `TenantNotFoundException` when a slug
is extracted but the provider cannot match it — the security-critical case (an attacker
sending an unknown tenant slug) is unchanged.

**Security note:** `strict_mode` on `#[TenantAware]` entity queries remains the
load-bearing guard. In a public request that reaches a tenant entity query,
`TenantMissingException` still fires (default behavior). Verify this holds in your app
via the integration pattern in `docs/user-guide/strict-mode.md`.

Listeners on `TenantResolved`: the event is no longer dispatched when no resolver
matches. If you relied on it firing for every request, add a `KernelEvents::REQUEST`
listener at a priority lower than 20 (or wire your own event) and branch on
`TenantContext::hasTenant()` to decide whether to run.

### 3. `TenantConnection` class removed (Fix #7 + #8)

`Tenancy\Bundle\DBAL\TenantConnection` and `Tenancy\Bundle\DBAL\TenantConnectionInterface`
are deleted. If you extended `TenantConnection`, migrate to a custom
`Doctrine\DBAL\Driver\Middleware`. The bundle ships `TenantDriverMiddleware` which covers
the default case; see `docs/architecture/dbal-middleware.md` for how to write a custom
one.

If you had `wrapper_class: Tenancy\Bundle\DBAL\TenantConnection` in your Doctrine config,
**remove that line**. The bundle now registers its middleware automatically via the
`doctrine.middleware` tag scoped to the `tenant` connection.

```yaml
# config/packages/doctrine.yaml — BEFORE
doctrine:
    dbal:
        connections:
            tenant:
                url: 'sqlite:///:memory:'
                wrapper_class: Tenancy\Bundle\DBAL\TenantConnection   # REMOVE

# AFTER (example for MySQL tenants)
doctrine:
    dbal:
        connections:
            tenant:
                # Driver family MUST match your tenant databases.
                # TenantDriverMiddleware merges tenant params at connect() time.
                driver: pdo_mysql
                host: '%env(TENANT_DB_HOST)%'
                user: '%env(TENANT_DB_USER)%'
                password: '%env(TENANT_DB_PASSWORD)%'
                dbname: placeholder_tenant
```

**Driver family match:** the tenant connection's `driver` parameter MUST match the driver
family of your tenant databases. The middleware merges tenant params at connect() time,
but the driver itself is resolved from the placeholder at container boot. Use
`pdo_mysql` for MySQL tenants, `pdo_pgsql` for PostgreSQL, `pdo_sqlite` for SQLite
(testing). You cannot mix driver families within a single `tenant` connection.

**Tenant `getConnectionConfig()` rule:** return discrete DBAL params (`dbname`, `host`,
`port`, `user`, `password`). Do **not** include a `url` key — DBAL resolves `url` before
middlewares run; `url` keys in tenant config are effectively ignored. This was a working
pattern under the v0.1 `wrapperClass` design; under v0.2 it is a no-op and the merged
discrete params carry the effective connection.

After upgrading, run:

```bash
composer dump-autoload --optimize
bin/console cache:clear
```

### 4. `tenancy:init` YAML sample (Fix #4)

The `tenancy.yaml` stub written by `bin/console tenancy:init` has not changed. But
`printNextSteps()` now also prints an annotated `doctrine.yaml` sample (MySQL driver
family) and a driver-family-match callout. Reference the sample when setting up your two
entity managers.

---

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
