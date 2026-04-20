---
phase: 15-architectural-fixes-v0-2
reviewed: 2026-04-20T00:00:00Z
depth: standard
files_reviewed: 34
files_reviewed_list:
  - src/Bootstrapper/DatabaseSwitchBootstrapper.php
  - src/Cache/TenantAwareCacheAdapter.php
  - src/Cache/TenantAwareTagAwareCacheAdapter.php
  - src/Command/TenantInitCommand.php
  - src/DBAL/TenantAwareDriver.php
  - src/DBAL/TenantDriverMiddleware.php
  - src/DependencyInjection/Compiler/CacheDecoratorContractPass.php
  - src/EventListener/TenantContextOrchestrator.php
  - src/Exception/TenantNotFoundException.php
  - src/Resolver/ResolverChain.php
  - src/Resolver/TenantResolution.php
  - src/TenancyBundle.php
  - src/Testing/InteractsWithTenancy.php
  - config/services.php
  - tests/Integration/Cache/DoctrineTenantProviderBootTest.php
  - tests/Integration/Cache/TenantAwareCacheAdapterContractTest.php
  - tests/Integration/Command/Support/StubConnectionFactory.php
  - tests/Integration/Command/TenantInitCommandYamlContentTest.php
  - tests/Integration/DBAL/DatabasePerTenantMiddlewareIntegrationTest.php
  - tests/Integration/DBAL/TenantDriverMiddlewareWiringTest.php
  - tests/Integration/EntityManagerResetIntegrationTest.php
  - tests/Integration/EventListener/NoTenantRequestTest.php
  - tests/Integration/Filter/StrictModeWithNullResolutionTest.php
  - tests/Integration/Support/DoctrineTestKernel.php
  - tests/Integration/Support/MakeDatabaseServicesPublicPass.php
  - tests/Integration/Testing/Support/TenancyTestKernel.php
  - tests/Unit/Bootstrapper/DatabaseSwitchBootstrapperTest.php
  - tests/Unit/Cache/TenantAwareCacheAdapterTest.php
  - tests/Unit/Cache/TenantAwareTagAwareCacheAdapterTest.php
  - tests/Unit/DBAL/TenantAwareDriverTest.php
  - tests/Unit/DependencyInjection/Compiler/CacheDecoratorContractPassTest.php
  - tests/Unit/EventListener/TenantContextOrchestratorTest.php
  - tests/Unit/Resolver/ResolverChainTest.php
  - scripts/docs-lint.sh
  - .github/workflows/ci.yml
findings:
  critical: 0
  warning: 5
  info: 10
  total: 15
status: issues_found
---

# Phase 15: Code Review Report

**Reviewed:** 2026-04-20T00:00:00Z
**Depth:** standard
**Files Reviewed:** 34
**Status:** issues_found

## Summary

Phase 15 delivers four architectural fixes that sharply improve correctness of cache decoration (FIX-01), resolver-chain semantics (FIX-02), and DBAL tenant routing (FIX-03). Code quality is high: `strict_types` is universal, Doctrine-optional guards are respected in event wiring, and the test suite exercises the full regression path for each issue end-to-end. No Critical issues were found.

Highlights that deserve credit:

- `TenantDriverMiddleware` + `TenantAwareDriver` is the correct architectural replacement for the v0.1 `wrapperClass`/`ReflectionProperty` hack. Tag scoping `['connection' => 'tenant']` is asserted by `testLandlordConnectionHasNoTenantDriverMiddlewareChild` — exactly the right regression guard against a landlord leak.
- `DatabasePerTenantMiddlewareIntegrationTest` performs a real two-file SQLite roundtrip with data-level assertions (row count goes 1 → 0 → 1 across switches). This is the gold-standard regression guard for issues #7/#8.
- `StrictModeWithNullResolutionTest` closes the security gap that FIX-02 could plausibly open (null resolution + shared-DB must still throw `TenantMissingException`).
- `docs-lint.sh` operationalizes "no stale v0.1 terms" and is wired into the `cs-fixer` CI job.
- No stale references to the deleted `TenantConnection` class in `src/` or production docs; the only matches are in CHANGELOG/UPGRADE (intentional migration narrative) and test method names (substring false-positives).

The phase is ready to ship with the advisories noted below. WR-02 (tenant `url` key silent landlord-routing) is the most consequential — consider applying that hardening before tagging v0.2. WR-05 (Doctrine-optional guard on middleware registration) is the next priority for users who enable `database: true` in a non-Doctrine project.

## Warnings

### WR-01: `DatabaseSwitchBootstrapper::clear()` close-without-isConnected guard

**File:** `src/Bootstrapper/DatabaseSwitchBootstrapper.php:36-39`
**Issue:** `clear()` unconditionally calls `$this->connection->close()` on every tenant teardown. `close()` is idempotent at the DBAL layer but silently aborts any still-active transaction. In the current orchestrator flow, terminate runs after response flush, so this is safe. But if a future bootstrapper (or a user-registered one with higher priority) begins a transaction in `clear()`, an unguarded `close()` further down the reverse chain would silently roll it back with no error.
**Fix:** Guard with `isConnected()` to make the intent explicit:
```php
public function clear(): void
{
    if ($this->connection->isConnected()) {
        $this->connection->close();
    }
}
```

### WR-02: `TenantAwareDriver::connect()` silently ignores `url` key — potential data leak

**File:** `src/DBAL/TenantAwareDriver.php:41-52`
**Issue:** The docblock correctly states that `url` keys in tenant config have no effect (DriverManager parses `url` into discrete keys *before* middlewares run). But a tenant that mistakenly returns `['url' => 'mysql://tenant-a-host/...']` from `getConnectionConfig()` will be **silently routed to the landlord database** — `array_merge` will add the `url` key but the parent driver has already been resolved and the landlord's `host`/`dbname` still win. This is a data-leak footgun identical in severity to the one FIX-03 closed.
**Fix:** Fail loudly at connect time if a tenant returns `url`:
```php
if (null !== $tenant) {
    $config = $tenant->getConnectionConfig();
    if (array_key_exists('url', $config)) {
        throw new \LogicException(sprintf(
            'Tenant "%s" returned "url" in getConnectionConfig(); use discrete keys '
            .'(driver, host, dbname, ...) — url is parsed before middlewares run and has no effect.',
            $tenant->getSlug()
        ));
    }
    /** @var Params $params */
    $params = array_merge($params, $config);
}
```

### WR-03: `CacheDecoratorContractPass` edge case — class set on ChildDefinition bypasses parent walk

**File:** `src/DependencyInjection/Compiler/CacheDecoratorContractPass.php:68-84`
**Issue:** `resolveEffectiveClass()` walks the `ChildDefinition` parent chain only when `$def->getClass()` is null. For Symfony's current layout (`cache.app` is a `ChildDefinition` of `cache.adapter.filesystem` without a class override), this works. However, if a future Symfony release sets a class directly on the ChildDefinition pointing at a different concrete (or a trait composition), the pass would inspect that class and miss interfaces on the abstract parent.
**Fix:** Low priority — current behavior is correct for all Symfony versions the bundle targets. Add a regression test that asserts `class_implements` on `FilesystemAdapter` returns the full Symfony interface set, so a Symfony upgrade that alters the definition tree is caught early. Alternatively, document the ChildDefinition-walk assumption in the `resolveEffectiveClass()` body.

### WR-04: `TenantAwareTagAwareCacheAdapter::invalidateTags()` relies on inline `@var` cast

**File:** `src/Cache/TenantAwareTagAwareCacheAdapter.php:34-40`
**Issue:** The inline `@var` on `$pool = $this->pool()` tells PHPStan that `$pool` implements `TagAwareAdapterInterface`, but the parent's `pool()` returns an intersection that does NOT include the tag interfaces. The cast is only correct by construction (the subclass constructor narrowed `$inner` to a tag-aware type, so the parent `$inner->withSubNamespace()` must return a tag-aware proxy). If a future refactor of the parent relaxes the intersection, this cast silently becomes a lie. PHPStan level 9 accepts the cast without cross-checking the runtime return.
**Fix:** Override `pool()` in the subclass with a narrowed return type so the type system enforces it at the source:
```php
protected function pool(): TagAwareAdapterInterface&TagAwareCacheInterface&NamespacedPoolInterface&PruneableInterface&ResettableInterface
{
    /** @var TagAwareAdapterInterface&TagAwareCacheInterface&NamespacedPoolInterface&PruneableInterface&ResettableInterface $pool */
    $pool = parent::pool();

    return $pool;
}

public function invalidateTags(array $tags): bool
{
    return $this->pool()->invalidateTags($tags);
}
```

### WR-05: `TenancyBundle` registers `TenantDriverMiddleware` without guarding on DoctrineBundle presence

**File:** `src/TenancyBundle.php:102-116`
**Issue:** The block under `if ($databaseConfig['enabled'] ?? false)` references `service('doctrine.dbal.tenant_connection')` and tags `doctrine.middleware` without first checking that DoctrineBundle / DBAL is installed. If a user sets `tenancy.database.enabled: true` in a project without DoctrineBundle, compile-time fails with a cryptic "unknown service" error from Symfony rather than a descriptive TenancyBundle message. The `TenantMigrateCommand` block on line 132 *does* guard on `class_exists(\Doctrine\Migrations\DependencyFactory::class)`, so the pattern is already established — it's just missing one level up.
**Fix:** Add an early guard with a descriptive exception:
```php
if ($databaseConfig['enabled'] ?? false) {
    if (!class_exists(\Doctrine\DBAL\Driver\Middleware::class)) {
        throw new \LogicException(
            'tenancy.database.enabled: true requires doctrine/dbal and doctrine/doctrine-bundle. '
            .'Install them (composer require doctrine/doctrine-bundle) or switch to driver: shared_db.'
        );
    }

    $container->parameters()->set('tenancy.database.enabled', true);
    // ... rest of existing block
}
```

## Info

### IN-01: `TenantInitCommand` swallows `file_put_contents` error detail

**File:** `src/Command/TenantInitCommand.php:58-62`
**Issue:** When `file_put_contents` fails, only the target path is reported — the underlying reason (permission denied, disk full) is available via `error_get_last()` but not surfaced, making user debugging harder.
**Fix:**
```php
if (false === file_put_contents($targetPath, $yamlContent)) {
    $lastError = error_get_last()['message'] ?? 'unknown';
    $io->error(sprintf('Could not write %s: %s', $targetPath, $lastError));

    return Command::FAILURE;
}
```

### IN-02: No test assertion locks in the `cache_prefix_separator` default in the generated YAML

**File:** `src/Command/TenantInitCommand.php:113`, `tests/Integration/Command/TenantInitCommandYamlContentTest.php`
**Issue:** The generated YAML emits `"    # cache_prefix_separator: '.'"`. STATE/memory notes that this default was recently changed from `:` to `.`. No test asserts the separator line renders with the correct default character, so a future refactor could silently drift the sample out of sync with `TenancyBundle::configure()`.
**Fix:** Add to `TenantInitCommandYamlContentTest`:
```php
public function testEmittedYamlUsesDotSeparatorDefault(): void
{
    $display = $this->runInitCommand();
    self::assertStringContainsString("cache_prefix_separator: '.'", $display);
}
```

### IN-03: `DatabaseSwitchBootstrapper` class-level docblock / `TenantDriverInterface` semantic mismatch

**File:** `src/Bootstrapper/DatabaseSwitchBootstrapper.php:25`
**Issue:** The class `implements TenantDriverInterface` but the class docblock describes it as a dumb connection-closer that "holds no tenant-specific state." `TenantDriverInterface` is semantically a marker for isolation drivers (e.g. `SharedDriver`), while this class is really an auxiliary bootstrapper that *enables* the middleware's rotation. Future maintainers may try to extend `TenantDriverInterface` with driver-specific methods and be surprised.
**Fix:** Either (a) tighten the class-level docblock to "Auxiliary driver-mode bootstrapper; the real isolation mechanism is TenantDriverMiddleware" or (b) drop `TenantDriverInterface` and keep only `TenantBootstrapperInterface` (no behavior change — `TenantDriverInterface` adds no methods). Option (b) is cleaner if there are no external consumers.

### IN-04: `InteractsWithTenancy::tearDown` trait composition caveat undocumented

**File:** `src/Testing/InteractsWithTenancy.php:131-135`
**Issue:** PHPUnit's `tearDown()` chain is voluntary — if a consumer's test uses multiple traits that each define `tearDown()`, PHP's trait method resolution resolves to whichever trait was used last, silently skipping the others' cleanup. This is standard PHPUnit behavior, but users who combine `InteractsWithTenancy` with other test traits could experience silent leaks.
**Fix:** Add a doc paragraph to the trait docblock:
```
Note on trait composition: if your test uses multiple traits that each define tearDown(),
PHP will resolve to the last-used trait's method. Use `use TraitA, TraitB { TraitA::tearDown
insteadof TraitB; }` to compose explicitly, or call $this->clearTenant() manually.
```

### IN-05: `CacheDecoratorContractPass` `class_implements` after `class_exists` — defensive `?: []` is correct

**File:** `src/DependencyInjection/Compiler/CacheDecoratorContractPass.php:56-57`
**Issue:** `class_implements()` can technically return `false` on autoload failure. The existing `?: []` fallback is the right guard, and the prior `class_exists()` check guarantees autoload succeeded for this path. No action required — noting as info for completeness.
**Fix:** None.

### IN-06: `TenantAwareCacheAdapter::pool()` allocates scoped proxy on every call

**File:** `src/Cache/TenantAwareCacheAdapter.php:25-33`
**Issue:** Every `getItem`/`hasItem`/`deleteItem`/etc. calls `pool()`, which re-calls `$this->inner->withSubNamespace(...)`. Symfony's adapters return a cheap proxy, but the behavior is not contractually guaranteed — a custom adapter wired by a user could perform real work in `withSubNamespace`. Out of scope per the v1 review rules (performance), but worth a docblock note.
**Fix:** No code change. Optionally add a `@throws` / perf note on the `pool()` docblock: "Called once per cache operation — implementations of NamespacedPoolInterface::withSubNamespace() must be O(1)."

### IN-07: Integration tests share `tenancy_test_landlord.db` path — parallel-run conflict

**File:** `tests/Integration/Cache/DoctrineTenantProviderBootTest.php:30,41`, also `tests/Integration/DBAL/*Test.php`, `tests/Integration/EntityManagerResetIntegrationTest.php`
**Issue:** Multiple test kernels write to `sys_get_temp_dir().'/tenancy_test_landlord.db'` (and `tenancy_test_placeholder.db`). PHPUnit runs them serially by default, but parallel runners (`paratest`) or concurrent CI jobs on the same runner would race. Each kernel cleans up in `tearDownAfterClass`, so a late-starting test could delete an in-flight test's DB.
**Fix:** Namespace the temp files per-test class using `md5(static::class)` or `uniqid()`:
```php
self::$landlordPath = sys_get_temp_dir().'/tenancy_'.md5(static::class).'_landlord.db';
```

### IN-08: `docs-lint.sh` scope is intentionally narrow — README not covered

**File:** `scripts/docs-lint.sh:36`
**Issue:** `TARGETS=(docs/ src/Command/TenantInitCommand.php)` deliberately excludes `CHANGELOG.md`/`UPGRADE.md` (migration narrative). `README.md` is also excluded; if the top-level README gets a "quick start" snippet that references `wrapper_class`, this script won't catch it.
**Fix:** No change required — current scope is correct. If README grows DBAL-config examples, add it to `TARGETS`.

### IN-09: `NoTenantRequestTest` kernel has no cleanup of cache dir on failure

**File:** `tests/Integration/EventListener/NoTenantRequestTest.php:84-92`
**Issue:** Cache/log dirs hash `self::class` and use `sys_get_temp_dir()` — no explicit `rm -rf` in `tearDownAfterClass`. Symfony kernel shutdown handles cache-file cleanup normally, but a fatal test failure mid-run could leave stale cache. Harmless in practice (next run rebuilds), but a pattern other test kernels in this phase also follow without teardown.
**Fix:** No action required — Symfony's cache-rebuild handles the stale-file case.

### IN-10: `TenantAwareCacheAdapter` non-`final` to allow the tag-aware subclass

**File:** `src/Cache/TenantAwareCacheAdapter.php:16`
**Issue:** The class is non-final specifically so `TenantAwareTagAwareCacheAdapter` can extend it (with `protected $inner` and `protected function pool()`). This also allows third-party extension which may or may not be intentional. Composition-over-inheritance would make the tag-aware adapter hold a `TenantAwareCacheAdapter` instance instead of extending it, but that would duplicate every delegating method.
**Fix:** No action — the inheritance model is explicit and documented in the `TenantAwareTagAwareCacheAdapter` class-level docblock.

---

_Reviewed: 2026-04-20T00:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
