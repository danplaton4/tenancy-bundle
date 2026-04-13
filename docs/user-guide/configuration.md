# Configuration Reference

All configuration lives under the `tenancy:` key in `config/packages/tenancy.yaml`. Every key has a sensible default — you only need to specify the keys you want to change.

---

## Config Keys

### `tenancy.driver`

| Type | Default |
|------|---------|
| `string` | `database_per_tenant` |

Selects the tenant isolation strategy. Two values are supported:

- `database_per_tenant` — Each tenant gets its own database. The DBAL connection is switched at runtime using `TenantConnection` (a DBAL `wrapperClass` subclass). Requires `doctrine/dbal` and `database.enabled: true`.
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
| `string` | `:` |

The separator inserted between the tenant slug and the cache key when the cache bootstrapper namespaces the cache pool. For example, with the default separator and slug `acme`, a cache key `user.123` becomes `acme:user.123`.

---

### `tenancy.database.enabled`

| Type | Default |
|------|---------|
| `bool` | `false` |

Set to `true` to activate the database-per-tenant driver. This:

1. Registers `DatabaseSwitchBootstrapper` as a bootstrapper
2. Rewires `DoctrineTenantProvider` to use the `landlord` entity manager
3. Registers the `tenancy:migrate` command (if `doctrine/migrations` is installed)

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
        cache_prefix_separator: ':'
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
            'cache_prefix_separator' => ':',
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
