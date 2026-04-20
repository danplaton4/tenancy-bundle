---
phase: 15-architectural-fixes-v0-2
fixed_at: 2026-04-20T00:00:00Z
review_path: .planning/phases/15-architectural-fixes-v0-2/15-REVIEW.md
iteration: 1
findings_in_scope: 5
fixed: 5
skipped: 0
status: all_fixed
---

# Phase 15: Code Review Fix Report

**Fixed at:** 2026-04-20T00:00:00Z
**Source review:** `.planning/phases/15-architectural-fixes-v0-2/15-REVIEW.md`
**Iteration:** 1

**Summary:**
- Findings in scope: 5 (0 critical, 5 warning)
- Fixed: 5
- Skipped: 0

All five warnings from `15-REVIEW.md` were applied cleanly. Every fix was verified with `php -l`, the targeted unit test subset, and a final full-suite pass (`vendor/bin/phpunit` → 302 tests, 727 assertions, all green; `vendor/bin/phpstan analyse --memory-limit=512M` → clean at level 9; `vendor/bin/php-cs-fixer check --allow-risky=yes` → clean).

## Fixed Issues

### WR-02: `TenantAwareDriver::connect()` silently ignores `url` key — potential data leak

**Files modified:** `src/DBAL/TenantAwareDriver.php`
**Commit:** `66c0fc4` (+ style follow-up `5438e0c`)
**Applied fix:** Added an `array_key_exists('url', $tenantConfig)` guard inside `connect()` that throws a `\LogicException` identifying the offending tenant slug and explaining that `url` is parsed before middlewares run. Prevents a tenant that mistakenly returns `url` from `getConnectionConfig()` from being silently routed to the landlord database.

### WR-05: `TenancyBundle` registers `TenantDriverMiddleware` without guarding on DoctrineBundle presence

**Files modified:** `src/TenancyBundle.php`
**Commit:** `4c8ce72` (+ style follow-up `5438e0c`)
**Applied fix:** Added an early `class_exists(\Doctrine\DBAL\Driver\Middleware::class)` guard inside the `if ($databaseConfig['enabled'] ?? false)` branch. When DoctrineBundle/DBAL is missing, users now see a descriptive `\LogicException` pointing at `composer require doctrine/doctrine-bundle` or `driver: shared_db` instead of Symfony's cryptic "unknown service" compile error. Mirrors the existing class-exists pattern around `Doctrine\Migrations\DependencyFactory` further down in the block.

### WR-01: `DatabaseSwitchBootstrapper::clear()` close-without-isConnected guard

**Files modified:** `src/Bootstrapper/DatabaseSwitchBootstrapper.php`, `tests/Unit/Bootstrapper/DatabaseSwitchBootstrapperTest.php`
**Commit:** `d6e7dcb`
**Applied fix:** Wrapped `$this->connection->close()` in `clear()` with an `isConnected()` guard. Split the unit test `testClearClosesTheConnection` into `testClearClosesTheConnectionWhenConnected` and `testClearSkipsCloseWhenNotConnected` so both branches are covered.

### WR-04: `TenantAwareTagAwareCacheAdapter::invalidateTags()` relies on inline `@var` cast

**Files modified:** `src/Cache/TenantAwareTagAwareCacheAdapter.php`
**Commit:** `c601c59`
**Applied fix:** Overrode `pool()` in the subclass with a narrowed return type (`TagAwareAdapterInterface&TagAwareCacheInterface&NamespacedPoolInterface&PruneableInterface&ResettableInterface`) so the narrowing is type-system-enforced rather than docblock-enforced. `invalidateTags()` now simply delegates to `$this->pool()->invalidateTags($tags)` without the inline cast. PHPStan level 9 passes with the covariant return override.

### WR-03: `CacheDecoratorContractPass` edge case — class set on ChildDefinition bypasses parent walk

**Files modified:** `tests/Unit/DependencyInjection/Compiler/CacheDecoratorContractPassTest.php`
**Commit:** `18ae560`
**Applied fix:** Added `testFilesystemAdapterExposesExpectedSymfonyInterfaceSet` which asserts that `class_implements(FilesystemAdapter::class)` contains the full Symfony interface set the pass relies on (`AdapterInterface`, `CacheInterface`, `NamespacedPoolInterface`, `PruneableInterface`, `ResettableInterface`). A future Symfony release that renames or drops one of those interfaces — and could therefore cause `resolveEffectiveClass()` to silently walk into a different concrete — will now fail this test loudly. No production code change was required (per the reviewer's explicit guidance: "current behavior is correct for all Symfony versions the bundle targets").

## Skipped Issues

_None — all five in-scope findings were fixed._

---

_Fixed: 2026-04-20T00:00:00Z_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
