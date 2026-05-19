# Phase 13: Audit Gap Closure - Research

**Researched:** 2026-04-13
**Domain:** Symfony DI compiler passes, Doctrine ORM bootstrapping, cache namespace wiring, PHP type safety, Composer lock management
**Confidence:** HIGH

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| OSS-01 | `composer.json` Packagist-ready with correct constraints; `composer.lock` in sync | composer validate confirms lock is stale; `composer update` regenerates it deterministically |
| RESV-05 | Resolver chain is configurable via `tenancy.resolvers` config; execution order controlled by DI tag priority | ResolverChainPass must filter tagged services against the `tenancy.resolvers` parameter; 4 built-in resolvers need an alias-to-FQCN map |
| CLI-01 | `tenancy:migrate` runs Doctrine migrations for every tenant; reports per-tenant success/failure | Constructor param `$migrationsConfig` must be nullable (`?Configuration`) to match `nullOnInvalid()` DI wiring |
| BOOT-02 | Cache bootstrapper isolates tenant cache at namespace level with configurable separator | `cache_prefix_separator` config value must be injected into `TenantAwareCacheAdapter` and used as the separator between tenant slug and sub-namespace |
| BOOT-01 | Doctrine bootstrapper calls `EntityManager::clear()` on tenant context switch; `EntityManagerResetListener` resets EM on clear | In `database_per_tenant` mode: DoctrineBootstrapper must target `doctrine.orm.tenant_entity_manager`; EntityManagerResetListener must reset only `tenant` EM, not landlord |
</phase_requirements>

---

## Summary

Phase 13 closes five gaps identified in the v1.0 milestone audit. All gaps are in existing, already-tested code — no new classes are needed. The fixes are surgical: one type annotation change, two DI wiring changes, one compiler pass enhancement, and one `composer update`. The test suite (259 tests, all passing) remains the regression guard; each fix needs targeted new tests and selective updates to existing ones.

The highest-risk change is RESV-05 (ResolverChainPass filtering). It requires a mapping from config short-names (`'host'`, `'header'`, etc.) to service IDs, and a filtering strategy in the compiler pass. The correct approach is to map short-names to FQCNs used as service IDs in `config/services.php`, then remove tagged services whose FQCN is not in the allowed list. This avoids changing the resolver registration model.

The BOOT-01 fix involves two independent sub-problems: (a) DoctrineBootstrapper targets the wrong EM in `database_per_tenant` mode — fix in `loadExtension()`; (b) EntityManagerResetListener resets all EMs including landlord — fix in the listener itself or via constructor arg. The audit also flags that existing integration tests (`EntityManagerResetIntegrationTest`) already assert landlord EM is NOT reset, and those tests currently pass against the `DoctrineTestKernel` (database_per_tenant). However, the unit test `testInvokeResetsMultipleEntityManagers` tests the current all-reset behavior, so it will need updating.

**Primary recommendation:** Execute all five fixes in a single plan (13-01) with a clear dependency order: OSS-01 first (isolated), then CLI-01 (isolated type fix), then BOOT-02 (add constructor param), then RESV-05 (compiler pass enhancement), then BOOT-01 (two-part DI + listener fix).

---

## Project Constraints (from CLAUDE.md)

- PHP 8.2+ with `declare(strict_types=1)` everywhere — no exceptions
- PHPStan level 9 — `?Configuration` nullable type must satisfy the type checker
- PHPUnit 11 unit + integration suites — all 259 tests must remain green after changes
- Doctrine dependencies are optional — always guard with `class_exists()`/`interface_exists()`, never hard-import
- `strict_mode` defaults to ON
- Compiler passes handle all service wiring — no manual DI config needed by users
- `TenantContext` is a zero-dependency value holder

---

## Gap-by-Gap Analysis

### OSS-01: Stale `composer.lock`

**Current state:** `composer validate --strict` exits non-zero with:
```
The lock file is not up to date with the latest changes in composer.json
```

**Root cause:** `phpstan/phpstan ^2.1` and `friendsofphp/php-cs-fixer ^3.0` were added to `require-dev` after the lock was last committed. Both packages ARE present in the `packages-dev` section of the lock (as transitive deps), but the lock's `content-hash` no longer matches `composer.json`. [VERIFIED: composer validate --strict output in this session]

**What `composer update` will do (dry-run verified):**
- 1 new install: `ergebnis/agent-detector 1.1.1` (new php-cs-fixer dep)
- 16 upgrades: `doctrine/doctrine-bundle 2.18.2 → 3.2.2`, various `symfony/polyfill-*` 1.33 → 1.34, `friendsofphp/php-cs-fixer v3.94.2 → v3.95.1`, `symfony/` components v7.4.8 → v8.0.8 [VERIFIED: composer update --dry-run]

**Fix:** Run `composer update` in the repo root. No source file changes required. Verify `composer validate --strict` exits 0 afterwards.

**Test impact:** None directly, but running the full test suite after the lock update confirms no regressions from package upgrades.

**Risk:** Upgrading `doctrine/doctrine-bundle` from 2.18.2 to 3.2.2 is the most significant jump. DoctrineBundle 3.x requires PHP ^8.4 which is satisfied (dev environment is PHP 8.4.12). However, the CI matrix tests PHP 8.2/8.3/8.4 — DoctrineBundle 3.x on PHP 8.2/8.3 in CI may be relevant. The `composer.json` has `"doctrine/doctrine-bundle": "^2.13||^3.0"` in require-dev so this is expected. [VERIFIED: composer.json require-dev]

---

### RESV-05: `tenancy.resolvers` config is a no-op

**Current state:** `TenancyBundle::loadExtension()` stores the resolvers list as a DI parameter:
```php
->set('tenancy.resolvers', $config['resolvers']);
```
But `ResolverChainPass::process()` collects ALL services tagged `tenancy.resolver` unconditionally — the parameter is never read by any compiler pass. [VERIFIED: src/TenancyBundle.php:91, src/DependencyInjection/Compiler/ResolverChainPass.php]

**Default config value:**
```php
->defaultValue(['host', 'header', 'query_param', 'console'])
```

**Built-in resolver service IDs in `config/services.php`:**
| Short name | Service ID (= FQCN) |
|------------|---------------------|
| `host` | `Tenancy\Bundle\Resolver\HostResolver::class` |
| `header` | `Tenancy\Bundle\Resolver\HeaderResolver::class` |
| `query_param` | `Tenancy\Bundle\Resolver\QueryParamResolver::class` |
| `console` | `Tenancy\Bundle\Resolver\ConsoleResolver::class` |

[VERIFIED: config/services.php lines 44-74]

**Fix approach:** In `ResolverChainPass::process()`, read the `tenancy.resolvers` container parameter and build an allowed FQCN set. Filter the tagged services, keeping only those whose service ID (or FQCN class) appears in the allowed set. Custom resolver FQCNs not in the short-name map always pass through (user-registered custom resolvers must not be filtered out). [ASSUMED: "custom resolvers pass through" — the alternative is users must add their FQCN to the resolvers list explicitly. Either behavior is defensible. The planner should confirm the intended semantics — see Open Questions.]

**ConsoleResolver edge case:** `ConsoleResolver` is NOT tagged `tenancy.resolver` — it is registered with `->autoconfigure(true)` which picks up `TenantResolverInterface` autoconfiguration but ConsoleResolver operates independently (it listens on `ConsoleCommandEvent`, not the HTTP chain). The `console` entry in the default resolvers config creates conceptual confusion noted in the audit, but the fix should still respect the config: if `console` is excluded, `ConsoleResolver` should not be tagged or should be conditionally registered. Currently `ConsoleResolver` uses `autoconfigure(true)` rather than explicit `tenancy.resolver` tag — this means it IS in the tagged set that `ResolverChainPass` collects. [VERIFIED: config/services.php line 67-74; ConsoleResolver has autoconfigure but is not in the HTTP chain by design]

**Test impact:** `ResolverChainPassTest` covers the current behavior. New test needed: `testProcessFiltersResolversByConfigList()`. The existing `testBundleConfigResolversDefault()` in `TenantResolutionIntegrationTest` verifies the parameter exists but does not verify filtering behavior — a new integration test for the filtering is recommended.

---

### CLI-01: `TenantMigrateCommand` nullable type mismatch

**Current state:**

DI wiring in `TenancyBundle::loadExtension()`:
```php
service('doctrine.migrations.configuration')->nullOnInvalid()
```
`nullOnInvalid()` means: if `doctrine.migrations.configuration` service is absent, inject `null`. [VERIFIED: src/TenancyBundle.php lines 119-120]

Constructor parameter:
```php
private readonly Configuration $migrationsConfig,
```
`Configuration` is non-nullable — PHP will throw a `TypeError` if null is injected. [VERIFIED: src/Command/TenantMigrateCommand.php line 33]

**Fix:** Change the constructor parameter to `?Configuration`:
```php
private readonly ?Configuration $migrationsConfig,
```

Update `runMigrationsForTenant()` to guard against null config:
```php
if (null === $this->migrationsConfig) {
    throw new \RuntimeException('doctrine/migrations is not configured. Add doctrine.migrations.configuration to your project.');
}
```
Or return early/throw before iterating tenants in `execute()`. The early-in-execute check is cleaner for UX (fail fast before iterating tenants).

**PHPStan impact:** PHPStan level 9 is currently clean. After the change, `$this->migrationsConfig` becomes `?Configuration` — all usages of it in `runMigrationsForTenant()` must null-check or use null-safe operator. Since `$migrationsConfig` is only used to construct `ExistingConfiguration`, a guard at the start of `runMigrationsForTenant()` is sufficient. [VERIFIED: src/Command/TenantMigrateCommand.php lines 113-115]

**Unit test impact:** `TenantMigrateCommandTest` calls `new Configuration()` in `setUp()` — this remains valid. A new test `testMigrateCommandFailsGracefullyWhenConfigurationIsNull()` should be added to prove the null-guard path. [VERIFIED: tests/Unit/Command/TenantMigrateCommandTest.php line 34]

---

### BOOT-02: `cache_prefix_separator` config is a no-op

**Current state:**

Config declared in `TenancyBundle::configure()`:
```php
->scalarNode('cache_prefix_separator')->defaultValue(':')->end()
```
[VERIFIED: src/TenancyBundle.php line 39]

But `loadExtension()` does NOT pass it to `tenancy.cache_adapter`. In `config/services.php`, `TenantAwareCacheAdapter` receives only `$inner` and `$tenantContext`:
```php
$services->set('tenancy.cache_adapter', TenantAwareCacheAdapter::class)
    ->decorate('cache.app')
    ->args([service('.inner'), service('tenancy.context')]);
```
[VERIFIED: config/services.php lines 91-96]

`TenantAwareCacheAdapter::pool()` currently calls `$this->inner->withSubNamespace($tenant->getSlug())` — the separator is hardcoded to the implicit sub-namespace path separator used by Symfony's cache component. [VERIFIED: src/Cache/TenantAwareCacheAdapter.php line 25]

**How Symfony's `withSubNamespace` separator works:** The `NamespacedPoolInterface::withSubNamespace()` contract concatenates the sub-namespace using the cache pool's own separator logic. The `cache_prefix_separator` config key suggests the intent is to allow customizing the separator between the parent namespace and the tenant slug, OR between the tenant slug and the cache key. Given the name "prefix separator," the most natural behavior is to use it as the separator in the namespace string: `$tenant->getSlug() . $separator . $subKey`. However, `withSubNamespace()` itself is an opaque call — the separator is internal to the cache backend. [ASSUMED: exact semantic intent of `cache_prefix_separator` — it most likely means the string prepended/appended to the tenant slug to form the namespace prefix, NOT the internal cache key separator. See Open Questions.]

**Fix approach:** Add `$cachePrefixSeparator` as a constructor parameter to `TenantAwareCacheAdapter`. Set it in `config/services.php` via `param('tenancy.cache_prefix_separator')`. Add `->set('tenancy.cache_prefix_separator', $config['cache_prefix_separator'])` in `loadExtension()`. Use the separator when building the sub-namespace string in `pool()`.

**Constructor signature after fix:**
```php
public function __construct(
    private AdapterInterface&NamespacedPoolInterface $inner,
    private readonly TenantContext $tenantContext,
    private readonly string $cachePrefixSeparator = ':',
) {}
```

**pool() after fix:**
```php
if (null !== $tenant) {
    return $this->inner->withSubNamespace($tenant->getSlug() . $this->cachePrefixSeparator);
}
```

Wait — `withSubNamespace()` takes a namespace string, not a prefixed key. The separator is concatenated with the slug to form the namespace token passed to `withSubNamespace`. This is consistent with the parameter name "prefix separator." [ASSUMED: this concatenation semantic is the intended behavior]

**Test impact:** `TenantAwareCacheAdapterTest::testGetItemWithTenantDelegatesToScopedPool()` currently asserts `->withSubNamespace('acme')`. After fix it will assert `->withSubNamespace('acme:')` (slug + separator). All 12 existing cache adapter tests that call `withSubNamespace('acme')` will need updating. A new test `testCustomCachePrefixSeparatorIsUsed()` should be added.

---

### BOOT-01: DoctrineBootstrapper and EntityManagerResetListener EM targeting

This gap has two sub-problems:

#### Sub-problem A: DoctrineBootstrapper targets the wrong EM in `database_per_tenant` mode

**Current state:** In `config/services.php` the bootstrapper always gets `doctrine.orm.entity_manager` (= the default EM, which in `database_per_tenant` mode is the landlord EM):
```php
$services->set('tenancy.doctrine_bootstrapper', DoctrineBootstrapper::class)
    ->args([service('doctrine.orm.entity_manager')->nullOnInvalid()])
    ->tag('tenancy.bootstrapper', ['priority' => -10]);
```
[VERIFIED: config/services.php lines 86-88]

In `database_per_tenant` mode, the landlord EM is `doctrine.orm.landlord_entity_manager` and the tenant EM is `doctrine.orm.tenant_entity_manager`. The landlord EM never changes between requests; clearing it is wasteful and risks discarding unflushed landlord writes. [VERIFIED: src/TenancyBundle.php lines 102-111, audit finding]

**Fix approach:** Move DoctrineBootstrapper registration into the `database_per_tenant` conditional block in `loadExtension()` and wire it to `doctrine.orm.tenant_entity_manager`. Keep the existing unconditional registration (targeting `doctrine.orm.entity_manager`) for the non-database-enabled (shared_db / default) path.

Concrete change in `loadExtension()`:
```php
// Always-on default (shared_db and no-database mode):
if (!($databaseConfig['enabled'] ?? false)) {
    if (interface_exists(EntityManagerInterface::class)) {
        $services->set('tenancy.doctrine_bootstrapper', DoctrineBootstrapper::class)
            ->args([service('doctrine.orm.entity_manager')->nullOnInvalid()])
            ->tag('tenancy.bootstrapper', ['priority' => -10]);
    }
}

// database_per_tenant mode — register in the enabled block, targeting tenant EM:
if ($databaseConfig['enabled'] ?? false) {
    // ...
    if (interface_exists(EntityManagerInterface::class)) {
        $services->set('tenancy.doctrine_bootstrapper', DoctrineBootstrapper::class)
            ->args([service('doctrine.orm.tenant_entity_manager')->nullOnInvalid()])
            ->tag('tenancy.bootstrapper', ['priority' => -10]);
    }
}
```

Wait — `config/services.php` is imported unconditionally at the top of `loadExtension()`, which means it always registers the DoctrineBootstrapper. The fix needs to either: (a) move the DoctrineBootstrapper out of `config/services.php` entirely and into `loadExtension()` for conditional registration; OR (b) override the DoctrineBootstrapper definition in the `database_per_tenant` block. Option (b) is simpler — re-call `->set('tenancy.doctrine_bootstrapper', DoctrineBootstrapper::class)` inside the `if ($databaseConfig['enabled'])` block to override the prior definition. [VERIFIED: config/services.php line 85-89 vs TenancyBundle.php import at line 71]

#### Sub-problem B: EntityManagerResetListener resets ALL EMs including landlord

**Current state:** The listener iterates `getManagerNames()` and resets every EM:
```php
foreach ($this->managerRegistry->getManagerNames() as $name => $id) {
    $this->managerRegistry->resetManager($name);
}
```
[VERIFIED: src/EventListener/EntityManagerResetListener.php lines 25-27]

In `database_per_tenant` mode, this resets both `landlord` and `tenant` EMs. The landlord EM should not be reset on tenant context clear. [VERIFIED: audit finding]

**Existing test contradiction:** `EntityManagerResetIntegrationTest::testLandlordEmNotResetOnTenantContextCleared()` ALREADY ASSERTS the landlord EM is not reset after `TenantContextCleared` — and it currently PASSES. [VERIFIED: tests/Integration/EntityManagerResetIntegrationTest.php lines 140-162, full test suite run passes]

This apparent contradiction means: in the current `DoctrineTestKernel` (which uses `database_per_tenant` mode), the test passes because resetting the landlord EM via `resetManager('landlord')` creates a new EM proxy internally but the container's `doctrine.orm.landlord_entity_manager` reference (a lazy proxy) returns the same object_id before and after. The test checks `spl_object_id` of the container-fetched EM, which stays the same because of DoctrineBundle's lazy proxy wrapping. So the test passes vacuously — it doesn't actually detect the issue.

**Fix approach for listener:** Inject the target EM name (or a list of EM names) as a constructor parameter, defaulting to resetting only the tenant EM in `database_per_tenant` mode. Alternatively, add a constructor parameter `$managersToReset` (array of names) injected via DI. In `database_per_tenant` mode, inject `['tenant']`; in other modes, inject `[null]` (which calls `resetManager()` with null = default EM).

The cleanest approach:
```php
public function __construct(
    private readonly ?ManagerRegistry $managerRegistry,
    private readonly array $managersToReset = [null],  // null = default EM
) {}
```
DI wiring in `loadExtension()` sets `['tenant']` when `database_per_tenant` mode is enabled.

**Unit test impact:** `testInvokeResetsMultipleEntityManagers()` currently asserts both `landlord` and `tenant` are reset (the current all-reset behavior). This test must be updated to reflect the targeted reset behavior. `testInvokeResetsAllEntityManagers()` tests the single-default-EM case — remains valid. [VERIFIED: tests/Unit/EventListener/EntityManagerResetListenerTest.php]

**DoctrineBootstrapperIntegrationTest:** This test uses `BootstrapperTestKernel` (shared_db, single default EM). It calls `resetManager()` with no arg — this test is testing the no-arg reset path and is not affected by the `database_per_tenant`-specific changes. [VERIFIED: tests/Integration/DoctrineBootstrapperIntegrationTest.php]

---

## Architecture Patterns

### DI Wiring Override Pattern

When `config/services.php` registers a service unconditionally and `loadExtension()` needs to conditionally override one argument, the standard Symfony pattern is to call `$builder->getDefinition('service.id')->setArgument(index, new Reference(...))`. This is already used in `loadExtension()` for the provider rewiring:
```php
$builder->getDefinition('tenancy.provider')
    ->setArgument(0, new Reference('doctrine.orm.landlord_entity_manager'));
```
[VERIFIED: src/TenancyBundle.php line 109-110]

The same pattern applies to overriding DoctrineBootstrapper's EM argument.

### Compiler Pass Parameter Access Pattern

`ResolverChainPass` runs after `loadExtension()`, so container parameters are available. Access pattern:
```php
$allowedResolvers = $container->getParameter('tenancy.resolvers'); // array of short names
```
Then map short names to FQCNs (hardcoded map in the pass) and filter tagged services.

### Resolver Short-Name Alias Map (to be implemented in ResolverChainPass)

```php
private const BUILT_IN_RESOLVER_MAP = [
    'host'        => HostResolver::class,
    'header'      => HeaderResolver::class,
    'query_param' => QueryParamResolver::class,
    'console'     => ConsoleResolver::class,
];
```
Custom resolvers (not in this map) should always be registered — user-provided FQCNs pass through without filtering.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Composer lock sync | Manual lock editing | `composer update` | Composer manages dependency resolution graph |
| EM name introspection | Custom EM registry scanning | `ManagerRegistry::getManagerNames()` | Already available via Doctrine DI |
| Cache sub-namespace | Custom key-prefix logic | `NamespacedPoolInterface::withSubNamespace()` | Symfony cache handles namespace isolation correctly |

---

## Common Pitfalls

### Pitfall 1: Removing ConsoleResolver from HTTP chain also breaks console context boot

**What goes wrong:** ConsoleResolver is not part of the HTTP resolver chain (it listens on `ConsoleCommandEvent` directly), but it is tagged `tenancy.resolver` via autoconfiguration. If the RESV-05 filter removes it from the chain when `console` is absent from `tenancy.resolvers`, that is correct for the HTTP chain. However, the ConsoleResolver's `ConsoleCommandEvent` listener is separate from the chain — removing the `tenancy.resolver` tag only prevents chain inclusion, it does not remove the event listener.

**How to avoid:** The fix should only filter services OUT of `ResolverChain::addResolver()` calls. ConsoleResolver's event listener registration is independent (it uses `autoconfigure(true)` which registers `AsEventListener`). Even if filtered from the resolver chain, it still works as a console context bootstrapper.

**Warning signs:** If tests expect ConsoleResolver to be absent from the ResolverChain when `console` is excluded from config, verify the EventListener is still registered separately.

### Pitfall 2: DoctrineBootstrapper override uses wrong service ID

**What goes wrong:** `config/services.php` registers it as `'tenancy.doctrine_bootstrapper'` (string ID), but the override in `loadExtension()` must use the same string ID. Using `DoctrineBootstrapper::class` as the ID in one place and `'tenancy.doctrine_bootstrapper'` in another results in two separate definitions.

**How to avoid:** Always use `'tenancy.doctrine_bootstrapper'` as the service ID when overriding. Confirm with `$builder->getDefinition('tenancy.doctrine_bootstrapper')` pattern.

### Pitfall 3: `composer update` changes test kernel behavior if DoctrineBundle 3.x breaks PHP 8.2 paths

**What goes wrong:** DoctrineBundle 3.x requires PHP ^8.4 per the `require-dev` constraints. But the test integration kernels check `doctrine/doctrine-bundle` version for `enable_native_lazy_objects`. After upgrading to 3.x, this check will activate on PHP 8.4 in all test kernels. This is expected and correct, but may expose previously hidden issues.

**How to avoid:** Run the full test suite immediately after `composer update`. If integration tests fail, investigate DoctrineBundle 3.x breaking changes.

### Pitfall 4: PHPStan null-safety on `?Configuration`

**What goes wrong:** After making `$migrationsConfig` nullable, PHPStan level 9 will flag any usage of `$this->migrationsConfig->...` without a prior null check.

**How to avoid:** Add the null guard early in `execute()` or `runMigrationsForTenant()` before any `Configuration` method calls. The early `execute()` guard is preferred for UX.

### Pitfall 5: `cache_prefix_separator` tests use hardcoded `'acme'` slug

**What goes wrong:** The 12 existing cache adapter tests assert `withSubNamespace('acme')`. After adding the separator, they will need `withSubNamespace('acme:')` (or whatever the default separator produces). Missing to update all 12 tests causes assertion failures.

**How to avoid:** Update ALL tests that assert the `withSubNamespace` argument. Use the default separator value `':'` in the updated assertions.

---

## Dependencies Between Fixes

Execution order within plan 13-01:

1. **OSS-01** — Isolated. Run `composer update`, verify tests pass. No source changes.
2. **CLI-01** — Isolated type fix. Change `Configuration` to `?Configuration`, add null guard, add test.
3. **BOOT-02** — Add constructor param to `TenantAwareCacheAdapter`, update DI wiring in `config/services.php` and `loadExtension()`, update 12 existing tests + add new test.
4. **RESV-05** — Enhance `ResolverChainPass` to read `tenancy.resolvers` parameter, add alias map, filter tagged services. Add unit test + integration test.
5. **BOOT-01** — Two-part: (a) override DoctrineBootstrapper arg in `loadExtension()` database_per_tenant block; (b) add `$managersToReset` param to `EntityManagerResetListener`, update DI wiring. Update unit test `testInvokeResetsMultipleEntityManagers()`.

There are NO cross-fix dependencies. Each fix is self-contained at the source level.

---

## State of the Art

| Old Behavior | Correct Behavior | Impact |
|---|---|---|
| `tenancy.resolvers` config stored as DI parameter but never read by compiler pass | ResolverChainPass reads parameter and filters tagged services by allowed names | RESV-05 becomes truly configurable |
| `TenantMigrateCommand.$migrationsConfig` non-nullable, `nullOnInvalid()` in DI | Nullable type + null guard in execute | No TypeError when migrations not configured |
| `cache_prefix_separator` declared, not injected | Injected as constructor arg, used in `pool()` | Config API actually controls behavior |
| DoctrineBootstrapper wired to default (= landlord) EM in database_per_tenant mode | Wired to `doctrine.orm.tenant_entity_manager` in database_per_tenant mode | Correct EM cleared on tenant boot/clear |
| EntityManagerResetListener resets all EMs on `TenantContextCleared` | Resets only configured target EMs (default: `[null]`, database_per_tenant: `['tenant']`) | Landlord EM not unnecessarily reset |

---

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5.55 |
| Config file | `phpunit.xml.dist` |
| Quick run command | `vendor/bin/phpunit --testsuite unit` |
| Full suite command | `vendor/bin/phpunit` |

### Phase Requirements to Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| OSS-01 | `composer validate --strict` exits 0 | smoke | `composer validate --strict` | N/A (shell command) |
| RESV-05 | ResolverChainPass filters resolvers by config | unit | `vendor/bin/phpunit tests/Unit/DependencyInjection/Compiler/ResolverChainPassTest.php` | Yes (needs new test method) |
| RESV-05 | ResolverChain only contains configured resolvers in container | integration | `vendor/bin/phpunit tests/Integration/TenantResolutionIntegrationTest.php` | Yes (needs new test method) |
| CLI-01 | TenantMigrateCommand accepts null config gracefully | unit | `vendor/bin/phpunit tests/Unit/Command/TenantMigrateCommandTest.php` | Yes (needs new test method) |
| BOOT-02 | Cache adapter uses separator in sub-namespace | unit | `vendor/bin/phpunit tests/Unit/Cache/TenantAwareCacheAdapterTest.php` | Yes (needs update + new test) |
| BOOT-01 | DoctrineBootstrapper clears tenant EM in db-per-tenant mode | integration | `vendor/bin/phpunit tests/Integration/DoctrineBootstrapperIntegrationTest.php` | Yes (may need new method for db-per-tenant path) |
| BOOT-01 | EntityManagerResetListener resets only tenant EM | unit | `vendor/bin/phpunit tests/Unit/EventListener/EntityManagerResetListenerTest.php` | Yes (needs update to multi-EM test) |

### Sampling Rate
- Per task commit: `vendor/bin/phpunit --testsuite unit`
- Per wave merge: `vendor/bin/phpunit`
- Phase gate: `vendor/bin/phpunit && vendor/bin/phpstan analyse && composer validate --strict`

### Wave 0 Gaps
None — existing test infrastructure covers all phase requirements. New test methods will be added to existing test files.

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|-------------|-----------|---------|----------|
| PHP | All fixes | Yes | 8.4.12 | — |
| composer | OSS-01 | Yes | (in PATH) | — |
| PHPUnit 11 | Test validation | Yes | 11.5.55 | — |
| PHPStan | Static analysis | Yes | 2.x | — |
| php-cs-fixer | Style check | Yes | 3.94.2 | — |
| SQLite | Integration tests | Yes (pdo_sqlite) | — | — |

No missing dependencies.

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Custom resolvers (not in BUILT_IN_RESOLVER_MAP) should always pass through ResolverChainPass filtering | RESV-05 fix approach | If custom resolvers should also require explicit listing, the filtering logic is different |
| A2 | `cache_prefix_separator` is appended to the tenant slug to form the withSubNamespace argument (e.g., `'acme:'`) | BOOT-02 fix approach | If separator is meant for key-level (not namespace-level) separation, the usage point changes |
| A3 | EntityManagerResetListener should reset only `['tenant']` in database_per_tenant mode, not reset `['landlord']` | BOOT-01 Sub-B fix | If landlord EM also needs resetting in some workflows, the allowed list changes |

---

## Open Questions

1. **RESV-05: Do custom (user-registered) resolvers need to be listed in `tenancy.resolvers` to be activated?**
   - What we know: The config default is `['host', 'header', 'query_param', 'console']`
   - What's unclear: Should a custom resolver tagged `tenancy.resolver` always be added (filter only applies to built-ins), or must users add their FQCN to the list?
   - Recommendation: Pass-through all non-built-in resolvers by default (most DX-friendly). Document that users can override the full list including their custom FQCN if they want strict control.

2. **BOOT-02: What does `cache_prefix_separator` separate — namespace tokens or key tokens?**
   - What we know: The method call is `->withSubNamespace($tenant->getSlug() . $separator)`
   - What's unclear: Is `':'` a separator between the parent namespace and slug (`'tenancy:acme'`), or between slug and cache key (`'acme:some-key'`)?
   - Recommendation: The most natural interpretation given `NamespacedPoolInterface` is namespace-level: the adapter calls `withSubNamespace($slug . $separator)` or `withSubNamespace($separator . $slug)`. The planner should confirm the intended direction.

---

## Sources

### Primary (HIGH confidence)
- Codebase grep — all source files read directly in this session; findings are verified
- `vendor/bin/phpunit` — test suite output (259 passing) [VERIFIED]
- `composer validate --strict` — lock staleness confirmation [VERIFIED]
- `composer update --dry-run` — upgrade scope confirmation [VERIFIED]
- `vendor/bin/phpstan analyse` — no errors baseline [VERIFIED]
- `vendor/bin/php-cs-fixer check` — clean baseline [VERIFIED]

### Secondary (MEDIUM confidence)
- Symfony `NamespacedPoolInterface` contract — behavior of `withSubNamespace()` separator is inferred from interface name and usage; not inspected at the Symfony source level in this session [ASSUMED for separator semantics]

---

## Metadata

**Confidence breakdown:**
- OSS-01 fix: HIGH — `composer validate` confirms the exact problem and `composer update --dry-run` confirms the scope
- CLI-01 fix: HIGH — type mismatch is directly visible in source; `?Configuration` + null-guard is the standard PHP pattern
- BOOT-02 fix (wiring): HIGH — DI wiring gap directly visible; constructor param addition is straightforward
- BOOT-02 fix (separator semantics): MEDIUM — exact concatenation direction needs planner confirmation
- RESV-05 fix: HIGH — gap is directly visible; filter approach well-established in Symfony compiler passes
- RESV-05 custom resolver pass-through: MEDIUM — defensible design choice, planner should confirm
- BOOT-01 DoctrineBootstrapper fix: HIGH — override pattern already used in same file
- BOOT-01 EntityManagerResetListener fix: HIGH — the constructor-param approach is direct; target list is clear

**Research date:** 2026-04-13
**Valid until:** 2026-05-13 (stable codebase)
