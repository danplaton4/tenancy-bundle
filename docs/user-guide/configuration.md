# Configuration Reference

All configuration lives under the `tenancy:` key in `config/packages/tenancy.yaml`. Every key has a sensible default — you only need to specify the keys you want to change.

---

## Config Keys

### `tenancy.driver`

| Type | Default |
|------|---------|
| `string` | `database_per_tenant` |

Selects the tenant isolation strategy. Two values are supported:

- `database_per_tenant` — Each tenant gets its own database. The bundle routes through a `Doctrine\DBAL\Driver\Middleware` on the `tenant` connection; the middleware merges the active tenant's `getConnectionConfig()` at `connect()` time. Requires `doctrine/dbal` and `database.enabled: true`. See [Database-per-Tenant Driver](database-per-tenant.md) for the full pipeline.
- `shared_db` — All tenants share one database. Queries are automatically scoped via the `tenancy_aware` Doctrine SQL filter on entities marked `#[TenantAware]`. Requires `doctrine/orm`.

---

### `tenancy.strict_mode`

| Type | Default |
|------|---------|
| `bool` | `true` |

When `true` (the default), querying a `#[TenantAware]` entity without an active tenant context throws `TenantMissingException`. This prevents cross-tenant data leaks.

When `false`, queries without an active tenant silently return all rows from all tenants.

See [Strict Mode](strict-mode.md) for the full security rationale and how to disable.

---

### `tenancy.landlord_connection`

| Type | Default |
|------|---------|
| `string` | `default` |

The DBAL connection name used for the landlord (central) database — the database that stores the `tenancy_tenants` table. When `database.enabled: true`, the bundle rewires `DoctrineTenantProvider` to use the entity manager bound to this connection.

---

### `tenancy.tenant_entity_class`

| Type | Default |
|------|---------|
| `string` | `Tenancy\Bundle\Entity\Tenant` |

The fully-qualified class name of your Tenant entity. Must implement `Tenancy\Bundle\TenantInterface`:

```php
interface TenantInterface
{
    public function getSlug(): string;
    public function getDomain(): ?string;
    public function getConnectionConfig(): array;
    public function getName(): string;
    public function isActive(): bool;
}
```

Override this if you extend `Tenancy\Bundle\Entity\Tenant` or provide your own implementation.

---

### `tenancy.cache_prefix_separator`

| Type | Default |
|------|---------|
| `string` | `.` |

The separator inserted between the tenant slug and the cache key when the cache bootstrapper namespaces the cache pool. For example, with the default separator and slug `acme`, a cache key `user.123` becomes `acme.user.123`.

---

### `tenancy.database.enabled`

| Type | Default |
|------|---------|
| `bool` | `false` |

Set to `true` to activate the database-per-tenant driver. This:

1. Registers `DatabaseSwitchBootstrapper` as a bootstrapper (calls `$connection->close()` on boot/clear)
2. Registers `TenantDriverMiddleware` with the `doctrine.middleware` tag scoped to `connection: tenant`; the landlord connection is never tagged
3. Rewires `DoctrineTenantProvider` to use the `landlord` entity manager
4. Registers the `tenancy:migrate` command (if `doctrine/migrations` is installed)

Must NOT be combined with `driver: shared_db` — see [Validation Rules](#validation-rules).

---

### `tenancy.resolvers`

| Type | Default |
|------|---------|
| `string[]` | `['host', 'header', 'query_param', 'console']` |

The list of active resolver aliases, in priority order (highest priority first). Built-in resolver aliases:

| Alias | Class | Priority |
|-------|-------|----------|
| `host` | `HostResolver` | 30 |
| `origin` | `OriginHeaderResolver` | 25 |
| `header` | `HeaderResolver` | 20 |
| `query_param` | `QueryParamResolver` | 10 |
| `console` | `ConsoleResolver` | N/A (ConsoleCommandEvent) |

To disable a resolver, remove its alias from the list. Custom resolvers are registered automatically via DI autoconfiguration (see [Custom Resolver](resolvers.md#custom-resolver)).

---

### `tenancy.host.app_domain`

| Type | Default |
|------|---------|
| `string\|null` | `null` |

The base domain used by `HostResolver` for subdomain extraction. When set to `example.com`, a request to `acme.example.com` resolves to tenant slug `acme`.

When `null` (the default), `HostResolver` always returns `null` and passes control to the next resolver in the chain.

For multi-segment subdomains (e.g. `api.acme.example.com`), the last segment before the `app_domain` suffix is used as the slug (`acme`).

---

### `tenancy.origin.allow_list`

| Type | Default |
|------|---------|
| `array` | `[]` (empty — resolver disabled) |

The list of allow-listed origins for `OriginHeaderResolver` (priority 25). Each entry is either a map form `{ origin: ..., slug: ... }` pinning a specific origin to a specific tenant slug, or a wildcard-shorthand string where the leftmost label of the matched host becomes the slug at runtime.

```yaml
# config/packages/tenancy.yaml
tenancy:
  resolvers: ['host', 'header', 'origin']
  origin:
    allow_list:
      # Explicit map form: pin a specific origin to a specific tenant slug.
      - { origin: 'https://acme.app.example.com', slug: 'acme' }
      - { origin: 'https://beta.app.example.com', slug: 'beta-customer' }

      # Wildcard shorthand: leftmost label becomes the slug at runtime.
      # `https://*.app.example.com` matches `https://anything.app.example.com`
      # and resolves to tenant slug = `anything`.
      - 'https://*.app.example.com'
```

Each entry must be an absolute URL with scheme `http` or `https`, no path/query/fragment, and at most one `*` in the leftmost label. Invalid configurations fail at **container compile time** with a descriptive error — there is no way to ship a misconfigured allow-list to runtime. For the full Trust Model (what `Origin` guarantees from a browser context, where the trust ends, and the recommended pairing with real authentication), see [Origin Header Resolver](origin-header-resolver.md).

---

### Per-tenant mailer config

Each tenant can carry its own SMTP transport, `From` header, and `Reply-To` header via three nullable columns on the Tenant entity. The bundle reads these via the `getMailerDsn()`, `getMailerFrom()`, and `getMailerReplyTo()` methods on `TenantInterface`. Returning `null` from any getter falls back to the landlord's default Mailer config.

| Column | Type | Purpose |
|--------|------|---------|
| `mailerDsn` | `string\|null` | SMTP transport DSN (e.g. `smtp://user:****@smtp.example.com:587`) |
| `mailerFrom` | `string\|null` | `From:` header on outgoing emails |
| `mailerReplyTo` | `string\|null` | `Reply-To:` header (optional) |

The bundle ships `Tenancy\Bundle\Mailer\TenantMailerConfigTrait` as a one-line shortcut — `use TenantMailerConfigTrait;` inside a custom Tenant entity supplies all three columns, their getters, and their setters:

```php
<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM; // optional — only honored if doctrine/orm is installed
use Tenancy\Bundle\Entity\AbstractTenant;
use Tenancy\Bundle\Mailer\TenantMailerConfigTrait;

#[ORM\Entity]
#[ORM\Table(name: 'tenancy_tenants')]
class AppTenant extends AbstractTenant
{
    use TenantMailerConfigTrait;
}
```

The bootstrapper works under both synchronous Mailer dispatch and Messenger-routed async dispatch via an `X-Transport: tenant_<slug>` stamp. A message dequeued for a deleted tenant throws (rather than silently dropping) — this contract is enforced at container compile time. For the full X-Transport strategy and async failure-mode warning, see the [Mailer Bootstrapper guide](mailer-bootstrapper.md).

---

## Validation Rules

The bundle enforces one compile-time constraint:

!!! danger "shared_db + database.enabled = error"
    Setting `driver: shared_db` and `database.enabled: true` simultaneously is rejected at container compile time:

    ```
    tenancy.driver: shared_db cannot be combined with tenancy.database.enabled: true.
    Choose one isolation strategy.
    ```

    These are mutually exclusive — `database_per_tenant` uses a per-tenant DBAL connection, while `shared_db` uses a SQL filter on a shared connection. You cannot run both at once.

---

## Full Example

=== "YAML"

    ```yaml
    # config/packages/tenancy.yaml
    tenancy:
        driver: database_per_tenant
        strict_mode: true
        landlord_connection: default
        tenant_entity_class: Tenancy\Bundle\Entity\Tenant
        cache_prefix_separator: '.'
        database:
            enabled: false
        resolvers:
            - host
            - header
            - query_param
            - console
        host:
            app_domain: ~
    ```

=== "PHP"

    ```php
    // config/packages/tenancy.php
    return static function (\Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator $container): void {
        $container->extension('tenancy', [
            'driver'               => 'database_per_tenant',
            'strict_mode'          => true,
            'landlord_connection'  => 'default',
            'tenant_entity_class'  => 'Tenancy\\Bundle\\Entity\\Tenant',
            'cache_prefix_separator' => '.',
            'database' => [
                'enabled' => false,
            ],
            'resolvers' => ['host', 'header', 'query_param', 'console'],
            'host' => [
                'app_domain' => null,
            ],
        ]);
    };
    ```

---

## Minimal Examples

### Scenario 1: Database-per-Tenant (Subdomain SaaS)

=== "YAML"

    ```yaml
    tenancy:
        driver: database_per_tenant
        database:
            enabled: true
        host:
            app_domain: myapp.com
    ```

=== "PHP"

    ```php
    $container->extension('tenancy', [
        'driver'   => 'database_per_tenant',
        'database' => ['enabled' => true],
        'host'     => ['app_domain' => 'myapp.com'],
    ]);
    ```

### Scenario 2: Shared-DB (API-first)

=== "YAML"

    ```yaml
    tenancy:
        driver: shared_db
        resolvers:
            - header
    ```

=== "PHP"

    ```php
    $container->extension('tenancy', [
        'driver'    => 'shared_db',
        'resolvers' => ['header'],
    ]);
    ```

### Scenario 3: API-only with Header Resolver Only

=== "YAML"

    ```yaml
    tenancy:
        driver: shared_db
        strict_mode: false
        resolvers:
            - header
    ```

=== "PHP"

    ```php
    $container->extension('tenancy', [
        'driver'      => 'shared_db',
        'strict_mode' => false,
        'resolvers'   => ['header'],
    ]);
    ```

!!! warning "strict_mode: false"
    Disabling strict mode means that queries without an active tenant return all rows from all tenants. Use this only when cross-tenant queries are intentional (e.g. internal admin tooling). See [Strict Mode](strict-mode.md).
