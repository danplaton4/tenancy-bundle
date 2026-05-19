---
phase: 13-audit-gap-closure
reviewed: 2026-04-13T00:00:00Z
depth: standard
files_reviewed: 10
files_reviewed_list:
  - src/Command/TenantMigrateCommand.php
  - src/Cache/TenantAwareCacheAdapter.php
  - config/services.php
  - src/TenancyBundle.php
  - src/DependencyInjection/Compiler/ResolverChainPass.php
  - src/EventListener/EntityManagerResetListener.php
  - tests/Unit/Command/TenantMigrateCommandTest.php
  - tests/Unit/Cache/TenantAwareCacheAdapterTest.php
  - tests/Unit/DependencyInjection/Compiler/ResolverChainPassTest.php
  - tests/Unit/EventListener/EntityManagerResetListenerTest.php
findings:
  critical: 1
  warning: 3
  info: 2
  total: 6
status: issues_found
---

# Phase 13: Code Review Report

**Reviewed:** 2026-04-13T00:00:00Z
**Depth:** standard
**Files Reviewed:** 10
**Status:** issues_found

## Summary

Ten files were reviewed covering: the `TenantMigrateCommand`, `TenantAwareCacheAdapter`, the DI services configuration, `TenancyBundle` loadExtension/build/prependExtension, the `ResolverChainPass` compiler pass, `EntityManagerResetListener`, and their corresponding unit test suites.

The implementation quality is generally high — strict types throughout, clear separation of concerns, good use of finally blocks for context cleanup, and thorough test coverage. One critical issue was found in `config/services.php`: `DoctrineTenantProvider` is registered unconditionally with a hard `doctrine.orm.default_entity_manager` service reference despite Doctrine ORM being an optional dependency. This will cause a container compile failure in applications that do not have Doctrine ORM installed.

Three warnings were found: an unhandled exception path in `TenantMigrateCommand` that crashes the command instead of returning `Command::FAILURE`; a fragile service-ID assumption in `ResolverChainPass` that could silently pass filtered-out resolvers; and a dead-code guard branch in `TenantMigrateCommand` that can never trigger in production due to the registration precondition.

---

## Critical Issues

### CR-01: `DoctrineTenantProvider` registered unconditionally with optional Doctrine ORM service

**File:** `config/services.php:58-64`

**Issue:** The `tenancy.provider` service is registered with `service('doctrine.orm.default_entity_manager')` as a hard dependency, but `doctrine/orm` is declared `require-dev` only — it is an optional dependency. If an application installs the bundle without Doctrine ORM (e.g., using a custom `TenantProviderInterface` implementation), the Symfony container will throw a `ServiceNotFoundException` at compile time when it resolves the `doctrine.orm.default_entity_manager` reference, even though the user never intends to use `DoctrineTenantProvider`.

The CLAUDE.md convention is explicit: "Doctrine dependencies are always optional — always guard with `class_exists()` or `interface_exists()`, never hard-import." The `DoctrineBootstrapper` registration (line 84) correctly follows this pattern; `tenancy.provider` does not.

**Fix:** Make the `DoctrineTenantProvider` registration conditional on Doctrine ORM being present, and fall back to no default provider (or a null provider). Users who install without Doctrine ORM must supply their own `TenantProviderInterface` service.

```php
// config/services.php — replace unconditional registration with a guard
if (interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
    $services->set('tenancy.provider', DoctrineTenantProvider::class)
        ->args([
            service('doctrine.orm.default_entity_manager'),
            service('cache.app'),
            param('tenancy.tenant_entity_class'),
        ]);
    $services->alias(TenantProviderInterface::class, 'tenancy.provider');
}
```

Note: resolvers that depend on `service('tenancy.provider')` (e.g., `HostResolver`, `HeaderResolver`, `QueryParamResolver`, `ConsoleResolver`) should also use `->nullOnInvalid()` or be conditionally registered when Doctrine ORM is absent, or the compiler pass should handle a missing `tenancy.provider` gracefully.

---

## Warnings

### WR-01: `TenantMigrateCommand::execute` — `findBySlug` failure propagates as unhandled exception instead of `Command::FAILURE`

**File:** `src/Command/TenantMigrateCommand.php:69-75`

**Issue:** When `--tenant` is provided, `findBySlug()` is called at line 72 **outside** the `try/catch` block that wraps the per-tenant migration loop (lines 87–97). If `findBySlug` throws `TenantNotFoundException` or `TenantInactiveException`, the exception propagates uncaught out of `execute()`, causing Symfony's console to print a raw stack trace instead of a clean error message and `Command::FAILURE` exit code.

This is inconsistent: the per-tenant `findAll()` path catches all `\Throwable` and accumulates failures. The `--tenant` path has no equivalent protection. The test `testTenantFilterNonexistentThrowsTenantNotFoundException` asserts `expectException`, confirming the crash is the current behaviour — but a CLI command crashing with an uncaught exception is a poor user experience and inconsistent with the rest of the command's error handling contract.

**Fix:**
```php
// src/Command/TenantMigrateCommand.php — wrap findBySlug in a try/catch
$tenantSlug = $input->getOption('tenant');

if (null !== $tenantSlug && \is_string($tenantSlug)) {
    try {
        $tenants = [$this->tenantProvider->findBySlug($tenantSlug)];
    } catch (\Tenancy\Bundle\Exception\TenantNotFoundException|\Tenancy\Bundle\Exception\TenantInactiveException $e) {
        $io->error($e->getMessage());
        return Command::FAILURE;
    }
} else {
    $tenants = $this->tenantProvider->findAll();
}
```

The corresponding test `testTenantFilterNonexistentThrowsTenantNotFoundException` should be updated to assert `exitCode === Command::FAILURE` and a message in output rather than `expectException`.

---

### WR-02: `ResolverChainPass` — built-in resolver filtering relies on service ID equalling FQCN, which is convention not enforcement

**File:** `src/DependencyInjection/Compiler/ResolverChainPass.php:53-65`

**Issue:** The filtering logic at line 59 checks `in_array($serviceId, self::BUILT_IN_RESOLVER_MAP, true)` where `$serviceId = (string) $resolver` (the service ID string from the container). This check only works correctly when built-in resolvers are registered using their FQCN as the service ID. In `config/services.php` they are indeed registered by FQCN, so today this works.

However, the check is fragile: if a built-in resolver is ever re-registered under a different service ID (e.g., a decorator or alias such as `'tenancy.resolver.host'`), `in_array($serviceId, self::BUILT_IN_RESOLVER_MAP, true)` returns `false`, the service is treated as a custom resolver, and the filter is bypassed silently. The intended built-in would be injected even if the user explicitly excluded it from `tenancy.resolvers`.

The `ResolverChainPass` already has access to the container's `Definition` for each service — it can reliably determine the FQCN by inspecting the definition's class.

**Fix:**
```php
// src/DependencyInjection/Compiler/ResolverChainPass.php
foreach ($resolvers as $resolver) {
    $serviceId = (string) $resolver;

    if (null !== $allowedFqcns) {
        // Resolve actual class from definition to handle aliased service IDs
        $definition = $container->findDefinition($serviceId);
        $fqcn = $definition->getClass() ?? $serviceId;

        if (in_array($fqcn, self::BUILT_IN_RESOLVER_MAP, true)) {
            if (!in_array($fqcn, $allowedFqcns, true)) {
                continue;
            }
        }
    }

    $definition->addMethodCall('addResolver', [$resolver]);
}
```

---

### WR-03: `TenantMigrateCommand` — `shared_db` guard at line 52 is dead code; the command is never registered for that driver

**File:** `src/Command/TenantMigrateCommand.php:52-61` and `src/TenancyBundle.php:123-134`

**Issue:** The `shared_db` driver guard in `execute()` (lines 52–61) can never trigger in production. `TenantMigrateCommand` is only registered in the DI container when `database.enabled: true` (line 100 in `TenancyBundle.php`). The configuration schema (`TenancyBundle::configure`, lines 57–65) has a cross-field validator that rejects the combination of `driver: shared_db` and `database.enabled: true` with an exception. Therefore, when the command is registered, the driver is always `database_per_tenant`. The `$this->driver` constructor argument and the guard block are unreachable dead code.

While this is not a correctness bug today, it adds misleading complexity: a future developer reading the command might assume `shared_db` is a supported configuration path.

**Fix (two options):**

Option A — Remove the `$driver` constructor argument and the guard entirely, since the command can only ever run in `database_per_tenant` mode:
```php
// Remove $driver from constructor, remove the guard block in execute()
```

Option B — If the argument is retained for testability or future use, add a comment clarifying the invariant:
```php
// Note: this guard is a safety net only. The DI container registers
// TenantMigrateCommand exclusively when database.enabled: true,
// which the schema validator rejects in combination with driver: shared_db.
// Driver is always 'database_per_tenant' at runtime.
```

---

## Info

### IN-01: `EntityManagerResetListener` — `$managersToReset` property lacks a typed array declaration

**File:** `src/EventListener/EntityManagerResetListener.php:19`

**Issue:** The constructor parameter `private readonly array $managersToReset = [null]` is typed only as `array`. The PHPDoc `@param list<string|null>` documents the intent correctly, but the property type does not enforce it. PHP does not allow element-level type constraints on arrays, but PHPStan level 9 (the project's configured level) will enforce the PHPDoc type. This is an informational note; the code is correct.

**Fix:** Ensure PHPDoc and PHPStan annotations are consistent and that PHPStan level 9 actually catches callers passing invalid element types. No code change required unless PHPStan reports a mismatch.

---

### IN-02: `TenantMigrateCommandTest` — unused `$clearCallCount` variable passed to anonymous class constructor

**File:** `tests/Unit/Command/TenantMigrateCommandTest.php:216-231`

**Issue:** At line 216, `$clearCallCount = 0` is declared, then passed to the anonymous class constructor at line 217: `new class($clearCallCount) implements ...`. However, the anonymous class does not declare a matching constructor parameter — it only declares public `$clearCount` and `$bootCount` properties. The `$clearCallCount` variable is never read after this point. PHP would produce a parse/runtime error if the anonymous class definition did not accept the argument... but in fact this anonymous class has no declared constructor and the argument to `new class($clearCallCount)` is silently discarded (PHP ignores extra constructor args on anonymous classes with no constructor). This is dead/misleading code.

**Fix:**
```php
// Remove the unused variable and the constructor argument:
$spyBootstrapper = new class implements \Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface {
    public int $clearCount = 0;
    public int $bootCount = 0;
    // ...
};
```

---

_Reviewed: 2026-04-13T00:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
