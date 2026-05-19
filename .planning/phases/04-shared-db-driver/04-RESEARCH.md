# Phase 4: Shared-DB Driver - Research

**Researched:** 2026-03-19
**Domain:** Doctrine ORM 3 SQL Filters, PHP 8 Attributes, Symfony DI / bundle extension wiring
**Confidence:** HIGH (core API verified against official docs and ORM source; injection pattern verified against ORM 3.3.1 source)

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- `#[TenantAware]` is a pure marker attribute — no parameters
- `tenant_id` column is VARCHAR(63) holding the tenant slug (immutable)
- Filter is always-enabled — registered in Doctrine config via `prependExtension`, never toggled by application code
- Filter reads `TenantContext` directly (injected via custom setter, not `setParameter`)
- `TenantMissingException` thrown inside `addFilterConstraint()`
- `SharedDriver` implements `TenantDriverInterface` (boot/clear pattern)
- `driver: shared_db` is mutually exclusive with `database.enabled: true`
- Activated via `tenancy.driver: shared_db` config key
- `TenantMissingException` extends `\RuntimeException`, message includes entity class name
- `strict_mode` config option (default: `true`) — if false, return `''` instead of throwing

### Claude's Discretion

- Exact Doctrine filter parameter injection mechanism (filter parameters vs constructor injection via Doctrine's filter infrastructure)
- Internal implementation of reflection caching for `#[TenantAware]` attribute detection
- Integration test kernel setup (reuse Phase 3's `DoctrineTestKernel` or adapt)

### Deferred Ideas (OUT OF SCOPE)

- Hybrid mode (shared-DB on landlord + per-tenant DB for tenant EM)
- Per-route / per-controller opt-out from strict mode via annotations
- PHPStan extension for `#[TenantAware]` correctness (DX-03, v1.1)
- Profiler integration (DX-02, v1.1)
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| ISOL-03 | Shared-DB driver registers a Doctrine SQL Filter (`TenantAwareFilter`) that appends `tenant_id = :id` to every query for entities marked `#[TenantAware]` | Filter API, registration pattern, and `addFilterConstraint` return contract all verified |
| ISOL-04 | `#[TenantAware]` PHP attribute marks Doctrine entities for automatic SQL filter scoping in shared-DB mode | PHP attribute API, `ClassMetadata::reflClass->getAttributes()` pattern verified |
| ISOL-05 | `strict_mode` config option (default: `true`) throws `TenantMissingException` when a `#[TenantAware]` entity is queried with no active tenant context | Exception throw inside `addFilterConstraint()` confirmed safe; `hasParameter` / custom property check pattern identified |
</phase_requirements>

---

## Summary

Phase 4 implements the shared-database isolation strategy using a Doctrine SQL filter. The filter intercepts every Doctrine query at the SQL-generation stage, checks whether the target entity carries `#[TenantAware]`, and appends `WHERE tenant_id = :slug` if it does. Without a tenant in context it either throws `TenantMissingException` (strict mode) or returns `''` (permissive mode).

The central architecture challenge is **service injection into a SQLFilter**. Doctrine instantiates `SQLFilter` subclasses internally with a final constructor accepting only `EntityManagerInterface` — the Symfony DI container cannot inject `TenantContext` via constructor. The verified solution is the **custom setter pattern**: `FilterCollection::enable($name)` returns the filter instance, at which point `SharedDriver::boot()` calls a custom `setTenantContext(TenantContext $ctx)` method. Because `TenantContext` is a singleton Symfony service, it only needs to be injected once (at filter enable time). From then on, `addFilterConstraint()` reads live state via `$this->tenantContext->hasTenant()` / `getTenant()->getSlug()` on every query.

The filter is registered as `enabled: true` in the Doctrine config via `prependExtension` — a fully supported DoctrineBundle feature that eliminates any window where a `TenantAware` entity could be queried unfiltered.

**Primary recommendation:** Use the custom setter pattern (inject `TenantContext` once via `setTenantContext()` in `SharedDriver::boot()`) and read live state inside `addFilterConstraint()`. This avoids the `setParameter`/`getParameter` scalar constraint and is the established community pattern for complex service injection.

---

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `doctrine/orm` | `^3.3` (in use: `^3.3` per composer.json) | Provides `SQLFilter` base class, `FilterCollection`, `ClassMetadata` | Only ORM; no alternatives |
| `doctrine/doctrine-bundle` | `^2.13` (in use) | `doctrine.orm.filters` config key, `enabled: true` support | DoctrineBundle wires filters into Doctrine config |
| PHP 8 Reflection API | Built-in | `ReflectionClass::getAttributes()` for `#[TenantAware]` detection | Native; no library needed |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `Symfony\Component\DependencyInjection` | `^6.4\|^7.0` | `prependExtension`, conditional service wiring | Already in use |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Custom setter injection | `setParameter` / `getParameter` | `getParameter()` goes through `PDO::quote()` and adds quotes around string values; does not support object references. Unsuitable for passing a service. |
| Always-enabled filter | Enable/disable in bootstrapper | Creates a window where filter is not active; data leak possible if boot fails. |
| Custom setter injection | Static service locator | Static state breaks testability; discouraged in modern Symfony. |

---

## Architecture Patterns

### New File Locations

```
src/
├── Attribute/
│   └── TenantAware.php          # #[TenantAware] marker attribute
├── Driver/
│   └── SharedDriver.php         # TenantDriverInterface: boot sets slug, clear unsets it
├── Filter/
│   └── TenantAwareFilter.php    # extends SQLFilter; addFilterConstraint + setTenantContext
└── Exception/
    └── TenantMissingException.php  # extends \RuntimeException

tests/Integration/
├── Support/
│   ├── SharedDbTestKernel.php      # single-EM kernel with shared_db driver + SQL logger
│   └── Entity/
│       └── TestTenantProduct.php   # #[TenantAware] entity for integration tests
└── SharedDbFilterIntegrationTest.php
```

### Pattern 1: PHP 8 Attribute Definition

```php
// Source: PHP 8 Attribute docs + CONTEXT.md locked decision
#[\Attribute(\Attribute::TARGET_CLASS)]
final class TenantAware {}
```

- `TARGET_CLASS` restricts use to class declarations (compile-time enforcement).
- No constructor parameters — pure marker.

### Pattern 2: `#[TenantAware]` Detection in Filter

```php
// Source: ClassMetadata ORM 3.3.1 source (reflClass is public ?ReflectionClass)
// Source: PHP reflection docs (getAttributes with class filter)
public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
{
    // reflClass is public on ORM 3 ClassMetadata; nullable — guard required
    $reflClass = $targetEntity->reflClass;
    if ($reflClass === null) {
        return '';
    }
    if (empty($reflClass->getAttributes(TenantAware::class))) {
        return ''; // entity not tenant-scoped — no filter injected
    }

    if (!$this->tenantContext->hasTenant()) {
        if ($this->strictMode) {
            throw new TenantMissingException(
                sprintf("No active tenant in context. Cannot query TenantAware entity '%s' in strict mode.", $targetEntity->getName())
            );
        }
        return ''; // strict_mode: false — return all rows
    }

    $slug = $this->tenantContext->getTenant()->getSlug();
    return sprintf("%s.tenant_id = '%s'", $targetTableAlias, addslashes($slug));
}
```

**Important caveat on `getParameter()`:** `SQLFilter::getParameter()` passes the value through `PDO::quote()` which wraps it in single quotes automatically. For a varchar slug like `acme`, `getParameter('tenant_id')` would return `'acme'` (with quotes), making the SQL `tenant_id = 'acme'`. This works but forces a string round-trip and cannot reference live service state. The custom setter pattern is strictly better because `TenantContext` is read at call time — the slug is always current without needing to call `setParameter` again on tenant switch.

**Alternative using getParameter (acceptable but inferior):**

```php
// setParameter approach: SharedDriver::boot() calls:
// $em->getFilters()->getFilter('tenancy_aware')->setParameter('tenancy_tenant_id', $tenant->getSlug());
// Then in addFilterConstraint():
return sprintf('%s.tenant_id = %s', $targetTableAlias, $this->getParameter('tenancy_tenant_id'));
// NOTE: getParameter() returns the value with PDO quotes: 'slug-value'
```

The `setParameter` approach requires `SharedDriver::boot()` to reach the EntityManager, which creates a circular dependency risk. The custom setter approach avoids this by reading `TenantContext` directly.

### Pattern 3: Custom Setter Service Injection

```php
// Source: FilterCollection::enable() returns the filter instance (verified)
// Source: michaelperrin.fr pattern; community-confirmed in multiple sources
final class TenantAwareFilter extends SQLFilter
{
    private TenantContext $tenantContext;
    private bool $strictMode = true;

    public function setTenantContext(TenantContext $context, bool $strictMode): void
    {
        $this->tenantContext = $context;
        $this->strictMode = $strictMode;
    }

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        // ... see Pattern 2 above
    }
}
```

`SharedDriver::boot()` is called once per request before any queries. Since the filter is already enabled (from config), `boot()` calls `getFilters()->getFilter('tenancy_aware')` to retrieve the live filter instance and calls `setTenantContext()`:

```php
// In SharedDriver::boot(TenantInterface $tenant)
// (EntityManagerInterface is injected into SharedDriver via DI)
$filter = $this->em->getFilters()->getFilter('tenancy_aware');
// Filter already has TenantContext from initial injection — it reads live state at query time.
// boot() does NOT need to call setTenantContext again if TenantContext is a singleton service.
// TenantContext::setTenant() was already called by BootstrapperChain before boot() runs.
```

**CRITICAL INSIGHT:** Because `TenantContext` is a Symfony singleton service and `$this->tenantContext->hasTenant()` is read at query time (not at boot time), `SharedDriver::boot()` and `clear()` do NOT need to touch the filter at all. The filter reads the live `TenantContext` state on every `addFilterConstraint()` call.

The filter needs `TenantContext` injected once at container compilation / filter registration time. This can be done via a `kernel.request` subscriber that injects the context after the filter is first enabled by DoctrineBundle's auto-enable mechanism.

**Recommended injection sequence:**

1. DoctrineBundle enables the filter at application boot (via `enabled: true` in config)
2. A `TenantAwareFilterConfigurator` event subscriber (tagged `kernel.event_subscriber`) runs on `kernel.request` priority 100 (before tenant resolution at priority 20) and calls:
   ```php
   $em->getFilters()->getFilter('tenancy_aware')
      ->setTenantContext($this->tenantContext, $this->strictMode);
   ```
3. From that point, every Doctrine query reads `$this->tenantContext->hasTenant()` live.

Actually, simpler: inject `TenantContext` in a `CompilerPass` or during `loadExtension()` wiring as a method call on the filter service definition. BUT: DoctrineBundle creates filters internally, not via the Symfony DI container — there is no service definition for the filter in the container.

**Simplest correct approach:** Wire a `SharedFilterConfigurator` service that:
- Is injected with `TenantContext` and `strict_mode` via DI
- Listens on `kernel.request` at high priority (or is called from `SharedDriver::boot()` if `SharedDriver` holds the EntityManager)

**OR, even simpler:** `SharedDriver` itself holds `EntityManagerInterface` and calls `getFilter()->setTenantContext()` in its `boot()` method after the context is already set. Since `boot()` runs before any Doctrine queries in a request, the injection happens in time.

### Pattern 4: Doctrine Filter Registration (DoctrineBundle)

```yaml
# Produced by prependExtension() — equivalent YAML for reference
doctrine:
  orm:
    filters:
      tenancy_aware:
        class: Tenancy\Bundle\Filter\TenantAwareFilter
        enabled: true  # always-on
```

In PHP (via `prependExtension`):

```php
// Source: DoctrineBundle configuration reference (verified)
$builder->prependExtensionConfig('doctrine', [
    'orm' => [
        'filters' => [
            'tenancy_aware' => [
                'class'   => TenantAwareFilter::class,
                'enabled' => true,
            ],
        ],
    ],
]);
```

`enabled: true` in DoctrineBundle's filter config auto-enables the filter without any `$em->getFilters()->enable()` call in application code. This is a standard, supported DoctrineBundle feature (verified from official configuration reference).

### Pattern 5: SharedDriver Implementation

```php
// Structural reference: DatabaseSwitchBootstrapper (same boot/clear contract)
final class SharedDriver implements TenantDriverInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
        private readonly bool $strictMode,
    ) {}

    public function boot(TenantInterface $tenant): void
    {
        // TenantContext::setTenant() has already been called by BootstrapperChain.
        // The filter reads TenantContext live — no additional action needed.
        // However, we inject TenantContext into the filter here as a safety measure
        // in case the filter was re-enabled (disable/enable loses injected state).
        /** @var TenantAwareFilter $filter */
        $filter = $this->em->getFilters()->getFilter('tenancy_aware');
        $filter->setTenantContext($this->tenantContext, $this->strictMode);
    }

    public function clear(): void
    {
        // TenantContext::clear() is called by BootstrapperChain.
        // Filter reads live context — no filter parameter to clear.
        // No action needed; TenantContext::hasTenant() will return false after clear().
    }
}
```

### Pattern 6: Config Validator (compile-time mutual exclusion)

```php
// In TenancyBundle::configure()
$definition->rootNode()
    ->validate()
        ->ifTrue(function (array $v): bool {
            return ($v['driver'] ?? '') === 'shared_db'
                && ($v['database']['enabled'] ?? false) === true;
        })
        ->thenInvalid(
            'tenancy.driver: shared_db cannot be combined with tenancy.database.enabled: true. Choose one isolation strategy.'
        )
    ->end();
```

### Pattern 7: loadExtension Conditional Block

The new block is parallel to the existing `database.enabled` block:

```php
if (($config['driver'] ?? 'database_per_tenant') === 'shared_db') {
    $services->set('tenancy.shared_driver', SharedDriver::class)
        ->args([
            service('doctrine.orm.default_entity_manager'), // or doctrine service
            service('tenancy.context'),
            '%tenancy.strict_mode%',
        ])
        ->tag('tenancy.bootstrapper');
}
```

### Anti-Patterns to Avoid

- **Calling `setParameter()` with non-scalar types:** `getParameter()` wraps via `PDO::quote()`. Only use for scalar slugs if choosing this path.
- **Enabling/disabling the filter in `SharedDriver`:** Calling `disable()` then `enable()` loses all previously set parameters (GitHub issue #10536). The always-enabled model avoids this entirely.
- **Reading entity class name via `$targetEntity->getName()` + `new \ReflectionClass()`:** `ClassMetadata::reflClass` is already populated — no need to construct a second `ReflectionClass`.
- **Calling `newInstance()` on the attribute in `addFilterConstraint()`:** For a pure marker, only `empty($reflClass->getAttributes(TenantAware::class))` is needed. `newInstance()` adds construction overhead unnecessarily.
- **Using `$targetEntity->reflClass->implementsInterface()` instead of attribute check:** The attribute is the locked decision; interface-based detection is not used.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| SQL injection protection for slug value | Custom escaping | `PDO::quote()` (via `getParameter`) or `addslashes()` on a validated slug (VARCHAR 63, alphanumeric+dash convention) | Slug is a controlled value (alphanumeric + dash, max 63 chars), but PDO quoting is still safer |
| Reflection caching | Custom static cache array | Rely on `ClassMetadata::reflClass` — Doctrine already caches `ClassMetadata` per class in its metadata factory; `reflClass` is populated once at metadata load time | Double-caching adds complexity without benefit |
| Filter enable/disable lifecycle | Custom enable/disable state machine | `FilterCollection::getFilter()` on an always-enabled filter | Doctrine already tracks enabled state |

**Key insight:** Doctrine's metadata factory caches `ClassMetadata` per class name across the entire process lifetime. `getReflectionClass()` / `reflClass` is populated once when the metadata is first loaded. Calling `$reflClass->getAttributes(TenantAware::class)` per query is therefore cheap — no additional per-request caching required.

---

## Common Pitfalls

### Pitfall 1: Filter Constructor is Final — Cannot Inject via DI Container

**What goes wrong:** Developer tries to type-hint `TenantContext` in the filter constructor. PHP fatal error: "Cannot override final constructor".

**Why it happens:** `SQLFilter::__construct(EntityManagerInterface $em)` is declared `final` in ORM 3 source. Doctrine instantiates filters via `FilterCollection` internally, not via the Symfony DI container.

**How to avoid:** Use the custom setter pattern (`setTenantContext()`). Inject via `SharedDriver::boot()` calling `getFilters()->getFilter('tenancy_aware')->setTenantContext(...)`.

**Warning signs:** PHPStan error "cannot override final method" or container exception about unknown constructor argument.

### Pitfall 2: `disable()` + `enable()` Resets Injected State

**What goes wrong:** If application code (or a test) calls `$em->getFilters()->disable('tenancy_aware')` and then `enable('tenancy_aware')` (e.g., in an admin context), the `TenantContext` reference is lost — `$this->tenantContext` becomes uninitialized.

**Why it happens:** GitHub issue #10536 documents that `FilterCollection::enable()` creates a new filter instance, discarding all previously set properties.

**How to avoid:** Never call `disable()` + `enable()` as a pair. The only supported escape hatch is `disable()` alone (for admin/migration contexts) with no subsequent re-enable within the same request. Document this in the bundle.

**Warning signs:** `Typed property TenantAwareFilter::$tenantContext has not been initialized` fatal error.

### Pitfall 3: `getParameter()` Adds Quotes Around the Value

**What goes wrong:** Developer uses `setParameter('tenant_id', 'acme')` and builds SQL as `$targetTableAlias.tenant_id = %s` with `$this->getParameter('tenant_id')`. The result is `tenant_id = 'acme'` (already quoted), which is correct. However, if they wrap it again in `sprintf("'%s'", $this->getParameter('tenant_id'))` they get double quotes.

**Why it happens:** `SQLFilter::getParameter()` calls `PDO::quote()` internally, which wraps string values in single quotes.

**How to avoid:** Use the custom setter / live `TenantContext` read approach (no `setParameter` needed). If using `setParameter`, use `$this->getParameter('name')` directly in the SQL string without additional quoting.

**Warning signs:** SQL syntax errors with double-quoted string values, or wrong results with extra quotes.

### Pitfall 4: `ClassMetadata::reflClass` Can Be Null

**What goes wrong:** Unguarded access to `$targetEntity->reflClass->getAttributes(...)` triggers a null-dereference fatal error.

**Why it happens:** `ClassMetadata::$reflClass` is typed as `?ReflectionClass`. For proxy classes or incomplete metadata, it may be null.

**How to avoid:** Always guard: `if ($targetEntity->reflClass === null) { return ''; }`.

**Warning signs:** `Call to a member function getAttributes() on null`.

### Pitfall 5: Inheritance — `addFilterConstraint` Receives Parent Metadata

**What goes wrong:** In Single Table Inheritance (STI) or Joined Table Inheritance (JTI), `addFilterConstraint` is called with the parent entity's `ClassMetadata`. A child entity marked `#[TenantAware]` won't match the attribute check if the parent is not also marked.

**Why it happens:** ORM issue #2924 documents this long-standing behavior. Doctrine passes the root/parent class metadata for inheritance scenarios.

**How to avoid:** Mark `#[TenantAware]` on the parent entity in inheritance hierarchies. Document this as a bundle convention. No special handling needed in the filter itself.

**Warning signs:** Filter not applied to child entities despite having `#[TenantAware]`.

### Pitfall 6: Filter Not Applied to Native SQL Queries

**What goes wrong:** Queries via `$em->getConnection()->executeQuery(...)` or `$em->createNativeQuery(...)` bypass Doctrine's filter layer entirely.

**Why it happens:** SQL filters operate at the DQL/QueryBuilder level where Doctrine controls SQL generation. Native SQL bypasses this.

**How to avoid:** Document as a known limitation. Escape hatch for admin contexts should use native SQL if they need to bypass the filter intentionally.

**Warning signs:** Cross-tenant data visible in native query results despite filter being active.

---

## Code Examples

### TenantAware Attribute
```php
// Source: PHP docs — Attribute::TARGET_CLASS
#[\Attribute(\Attribute::TARGET_CLASS)]
final class TenantAware {}
```

### TenantAwareFilter Skeleton
```php
// Source: Doctrine ORM 3.3.1 SQLFilter source + Pattern 2/3 above
final class TenantAwareFilter extends SQLFilter
{
    private TenantContext $tenantContext;
    private bool $strictMode = true;

    public function setTenantContext(TenantContext $context, bool $strictMode): void
    {
        $this->tenantContext = $context;
        $this->strictMode = $strictMode;
    }

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        $reflClass = $targetEntity->reflClass;
        if ($reflClass === null || empty($reflClass->getAttributes(TenantAware::class))) {
            return '';
        }

        if (!$this->tenantContext->hasTenant()) {
            if ($this->strictMode) {
                throw new TenantMissingException(
                    sprintf(
                        "No active tenant in context. Cannot query TenantAware entity '%s' in strict mode.",
                        $targetEntity->getName()
                    )
                );
            }
            return '';
        }

        return sprintf(
            "%s.tenant_id = '%s'",
            $targetTableAlias,
            addslashes($this->tenantContext->getTenant()->getSlug())
        );
    }
}
```

### TenantMissingException
```php
// Follows TenantNotFoundException convention (extends RuntimeException, not HttpExceptionInterface)
final class TenantMissingException extends \RuntimeException {}
```

### SharedDriver Registration in loadExtension
```php
// Inside TenancyBundle::loadExtension() — parallel to database.enabled block
if (($config['driver'] ?? 'database_per_tenant') === 'shared_db') {
    $services->set('tenancy.shared_driver', SharedDriver::class)
        ->args([
            service('doctrine.orm.default_entity_manager'),
            service('tenancy.context'),
            param('tenancy.strict_mode'),
        ])
        ->tag('tenancy.bootstrapper');
}
```

### Filter Registration in prependExtension
```php
// Inside TenancyBundle::prependExtension() — under $isSharedDb branch
if ($isSharedDb) {
    $builder->prependExtensionConfig('doctrine', [
        'orm' => [
            'filters' => [
                'tenancy_aware' => [
                    'class'   => TenantAwareFilter::class,
                    'enabled' => true,
                ],
            ],
        ],
    ]);
}
```

### SharedFilterTestKernel (integration test)
```php
// Adapts DoctrineTestKernel: single connection, single EM, driver: shared_db
// Key differences from Phase 3:
// - No TenantConnection wrapperClass needed
// - Single entity manager (default)
// - tenancy.driver: shared_db in tenancy config
// - Test entity must have tenant_id column and #[TenantAware]
$container->loadFromExtension('tenancy', [
    'driver' => 'shared_db',
    'strict_mode' => true,
]);
// Doctrine config: single connection, single EM, no dual-EM setup
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| ORM 2 `ClassMetadataInfo` + annotation reader for attribute detection | ORM 3 `ClassMetadata::reflClass->getAttributes()` natively | ORM 3.0 (2023) | No Annotation Reader dependency needed |
| `FilterCollection::enable()` in a kernel.request listener | `enabled: true` in DoctrineBundle filter config | DoctrineBundle ~2.x | Zero application code needed to activate the filter |
| Doctrine 2 `reflClass` as public property | ORM 3 same (`public ?ReflectionClass $reflClass`) | Unchanged in ORM 3 | Direct access still works |

**Deprecated/outdated:**
- `ClassMetadataInfo` class: Removed in ORM 3. Use `ClassMetadata` directly.
- `@Annotation` / Doctrine Annotations: Replaced by PHP 8 attributes. No `doctrine/annotations` dependency needed.
- `doctrine/common` `AnnotationReader` for entity metadata: Not needed for PHP attribute detection.

---

## Open Questions

1. **Which EntityManager does `SharedDriver` receive?**
   - What we know: `shared_db` driver uses a single EM (no dual-EM setup). The default EM is used.
   - What's unclear: If the user has configured `database.enabled: true` for a landlord/tenant split AND tries shared_db (which is compile-time forbidden), there's no ambiguity. For standard single-EM setup, the default EM is `doctrine.orm.default_entity_manager`.
   - Recommendation: Use `service('doctrine.orm.default_entity_manager')` as the EM reference in SharedDriver wiring. This is the standard single-EM Symfony Doctrine setup.

2. **Does DoctrineBundle 2.x support multi-EM filter registration?**
   - What we know: DoctrineBundle supports `entity_managers.{name}.filters` as a separate config path for per-EM filter registration.
   - What's unclear: Whether `orm.filters` (top-level) applies to all EMs or only the default.
   - Recommendation: Since `shared_db` is mutually exclusive with `database.enabled`, there is always only one entity manager in scope. Top-level `orm.filters` applies to the default EM. No per-EM filter syntax needed.

3. **Initialization race: what if a query fires before `SharedDriver::boot()`?**
   - What we know: `$this->tenantContext` in the filter is a typed property (no default). If `addFilterConstraint()` is called before `setTenantContext()`, PHP throws "Typed property not initialized".
   - Recommendation: In `TenantAwareFilter::addFilterConstraint()`, add a guard: `if (!isset($this->tenantContext)) { return ''; }` or initialize `$this->tenantContext` as nullable with `null` default. Since DoctrineBundle enables the filter at kernel boot (before any request), and `SharedDriver::boot()` runs at kernel.request priority 20 before controllers, this should not occur in normal flow. The guard is a safety net for console commands or test contexts.

---

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11 (in use via composer.json) |
| Config file | `phpunit.xml.dist` |
| Quick run command | `vendor/bin/phpunit tests/Unit/Filter/ --no-coverage` |
| Full suite command | `vendor/bin/phpunit --no-coverage` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| ISOL-03 | SQL filter appends `WHERE tenant_id = 'slug'` for `#[TenantAware]` entity — verified via SQL log | integration | `vendor/bin/phpunit tests/Integration/SharedDbFilterIntegrationTest.php -x` | ❌ Wave 0 |
| ISOL-03 | SQL filter returns `''` for entity without `#[TenantAware]` — verified via SQL log | integration | `vendor/bin/phpunit tests/Integration/SharedDbFilterIntegrationTest.php -x` | ❌ Wave 0 |
| ISOL-04 | `#[TenantAware]` attribute present on class detected by `reflClass->getAttributes()` | unit | `vendor/bin/phpunit tests/Unit/Filter/TenantAwareFilterTest.php -x` | ❌ Wave 0 |
| ISOL-05 | `addFilterConstraint` throws `TenantMissingException` when no tenant + strict_mode=true | unit | `vendor/bin/phpunit tests/Unit/Filter/TenantAwareFilterTest.php -x` | ❌ Wave 0 |
| ISOL-05 | `addFilterConstraint` returns `''` when no tenant + strict_mode=false | unit | `vendor/bin/phpunit tests/Unit/Filter/TenantAwareFilterTest.php -x` | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `vendor/bin/phpunit tests/Unit/Filter/ --no-coverage`
- **Per wave merge:** `vendor/bin/phpunit --no-coverage`
- **Phase gate:** Full suite green before `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Unit/Filter/TenantAwareFilterTest.php` — unit tests for `addFilterConstraint` logic (attribute present/absent, strict/permissive, no-tenant)
- [ ] `tests/Unit/Attribute/TenantAwareTest.php` — attribute target enforcement (only on classes)
- [ ] `tests/Integration/SharedDbFilterIntegrationTest.php` — end-to-end SQL filter scoping
- [ ] `tests/Integration/Support/SharedDbTestKernel.php` — single-EM kernel with shared_db driver
- [ ] `tests/Integration/Support/Entity/TestTenantProduct.php` — `#[TenantAware]` entity with `tenant_id` column

---

## Sources

### Primary (HIGH confidence)
- `https://www.doctrine-project.org/projects/doctrine-orm/en/3.6/reference/filters.html` — `addFilterConstraint` signature, return contract, `getParameter`/`setParameter` scalar constraint, no constructor injection
- `https://github.com/doctrine/orm/blob/3.3.1/src/Query/Filter/SQLFilter.php` (fetched) — confirmed `__construct(EntityManagerInterface)` is final; all public methods; no service injection constructor support
- `https://github.com/doctrine/orm/blob/3.3.1/src/Mapping/ClassMetadata.php` (fetched) — confirmed `$reflClass` is `public ?ReflectionClass`; `getName()` method present
- `https://symfony.com/bundles/DoctrineBundle/current/configuration.html` — `doctrine.orm.filters.{name}.class/enabled/parameters` config structure verified; `enabled: true` is a first-class DoctrineBundle feature
- `https://www.php.net/manual/en/language.attributes.reflection.php` — `ReflectionClass::getAttributes(ClassName::class)` returns empty array when attribute absent; exact API verified

### Secondary (MEDIUM confidence)
- `https://www.michaelperrin.fr/blog/2014/12/doctrine-filters` — Custom setter injection pattern (`setAnnotationReader`); `FilterCollection::enable()` returns filter instance; injecting non-scalar services; verified consistent with ORM source
- `https://github.com/doctrine/orm/issues/10536` — `disable()` + `enable()` loses filter parameters; `suspend()`/`restore()` as alternative; confirmed by multiple commenters
- `https://github.com/doctrine/orm/issues/2924` — STI/JTI: `addFilterConstraint` receives parent class metadata; mark `#[TenantAware]` on root entity for inheritance
- `https://gist.github.com/CarlosEduardo/aedfa640e3f7f22451686fb7e57228e3` — Reference implementation: `$targetEntity->reflClass->implementsInterface()` pattern (interface) adapted here to attribute check

### Tertiary (LOW confidence — for awareness only)
- WebSearch results about `setParameter` quoting behavior: consistent across multiple sources; confirmed by GitHub issues #5811, #7508

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — Doctrine ORM 3.3 (in use), DoctrineBundle 2.13 (in use), PHP reflection API (built-in)
- Architecture (filter registration, `enabled: true`): HIGH — verified from official DoctrineBundle config docs
- Architecture (custom setter injection): HIGH — `SQLFilter` constructor confirmed final from source; setter pattern confirmed from multiple community sources consistent with ORM design
- Architecture (`ClassMetadata::reflClass` access): HIGH — confirmed from ORM 3.3.1 source
- Pitfalls (inheritance, disable/enable state loss): MEDIUM — GitHub issue evidence; not in official docs
- Integration test adaptation: HIGH — Phase 3 `DoctrineTestKernel` is a known-good template; adaptation is straightforward

**Research date:** 2026-03-19
**Valid until:** 2026-09-19 (Doctrine ORM 3 stable; SQLFilter API has been stable since ORM 2; filter registration via DoctrineBundle config is a long-standing feature)
