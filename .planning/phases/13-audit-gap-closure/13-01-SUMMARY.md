---
phase: 13-audit-gap-closure
plan: "01"
subsystem: bundle-config
tags: [audit, cache, resolver-chain, doctrine, migrations, osss]
dependency_graph:
  requires: []
  provides: [OSS-01, CLI-01, BOOT-02, RESV-05, BOOT-01]
  affects: [src/Cache/TenantAwareCacheAdapter.php, src/DependencyInjection/Compiler/ResolverChainPass.php, src/EventListener/EntityManagerResetListener.php, src/TenancyBundle.php, src/Command/TenantMigrateCommand.php]
tech_stack:
  added: []
  patterns: [nullable-constructor-guard, compiler-pass-filtering, targeted-em-reset, configurable-cache-separator]
key_files:
  created: []
  modified:
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
decisions:
  - "cache_prefix_separator default changed from ':' to '.' — colon is a PSR-6 reserved character forbidden in withSubNamespace() namespace input; dot is valid and still clearly separates tenant slug from cache key"
  - "runMigrationsForTenant() receives Configuration as explicit non-null parameter rather than reading ?Configuration field — cleaner PHPStan level 9 narrowing without assert() or @var"
  - "EntityManagerResetListener.managersToReset defaults to [null] to call resetManager(null) — resets default EM in shared_db/single-EM mode; overridden to ['tenant'] in database_per_tenant via loadExtension"
metrics:
  duration_seconds: 645
  completed_date: "2026-04-13"
  tasks: 3
  files_modified: 10
---

# Phase 13 Plan 01: Audit Gap Closure — 5-Gap Fix Summary

**One-liner:** Five v1.0 audit gaps closed: nullable migrations config with null guard, configurable cache prefix separator wired via DI, resolver chain filtered by config short-names, DoctrineBootstrapper targeting tenant EM in database_per_tenant mode, and EntityManagerResetListener scoped to configured target EMs only.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Fix composer.lock sync and TenantMigrateCommand nullable type (OSS-01 + CLI-01) | cc6f626 | TenantMigrateCommand.php, TenantMigrateCommandTest.php |
| 2 | Wire cache_prefix_separator and add resolver config filtering (BOOT-02 + RESV-05) | a5414c1 | TenantAwareCacheAdapter.php, config/services.php, TenancyBundle.php, ResolverChainPass.php, test files |
| 3 | Correct DoctrineBootstrapper and EntityManagerResetListener EM targeting (BOOT-01) | 845c665 | EntityManagerResetListener.php, TenancyBundle.php, EntityManagerResetListenerTest.php |

## Verification Results

- `composer validate --strict`: exits 0
- `vendor/bin/phpunit`: 255 tests, 612 assertions — all pass
- `vendor/bin/phpstan analyse src/`: 0 errors (level 9)
- `vendor/bin/php-cs-fixer check --diff`: 0 violations

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] cache_prefix_separator default changed from ':' to '.'**

- **Found during:** Task 2, integration test CacheBootstrapperIntegrationTest
- **Issue:** Plan specified `cachePrefixSeparator = ':'` as default. `withSubNamespace('acme:')` calls `CacheItem::validateKey('acme:')` which throws `InvalidArgumentException` because `:` is a PSR-6 reserved character forbidden in cache key/namespace strings. Integration tests failed with: `Cache key "alpha:" contains reserved characters "{}()/\@:".`
- **Fix:** Changed default separator from `':'` to `'.'` in both `TenantAwareCacheAdapter` constructor and `TenancyBundle` config node. Updated unit test assertions from `'acme:'` to `'acme.'`. The `.` character is PSR-6 valid and functionally equivalent as a namespace delimiter.
- **Files modified:** `src/Cache/TenantAwareCacheAdapter.php`, `src/TenancyBundle.php`, `tests/Unit/Cache/TenantAwareCacheAdapterTest.php`
- **Commit:** d1beea7

**2. [Rule 1 - Bug] TenantMigrateCommand PHPStan level 9 error from nullable field**

- **Found during:** Task 1 (discovered during full PHPStan run after Task 2)
- **Issue:** Making `$migrationsConfig` nullable (`?Configuration`) but passing `$this->migrationsConfig` directly to `ExistingConfiguration()` (which requires non-null `Configuration`) caused PHPStan level 9 error: `Parameter #1 must be of type Configuration, Configuration|null given.`
- **Fix:** Extracted `$migrationsConfig` as an explicit non-null parameter in `runMigrationsForTenant(TenantInterface, Configuration, SymfonyStyle)`. The null guard in `execute()` returns early before calling this method, so PHPStan can correctly narrow the type via the parameter type declaration.
- **Files modified:** `src/Command/TenantMigrateCommand.php`
- **Commit:** d1beea7

**3. [Rule 3 - Blocking] Integration test container cache stale from DoctrineBundle 2.x→3.x**

- **Found during:** Task 1 (composer update upgraded DoctrineBundle 2.18.2 → 3.2.2)
- **Issue:** Cached DI containers (in `/tmp/tenancy_*`) were built against DoctrineBundle 2.x API. `ConnectionFactory::createConnection()` signature changed in 3.x (argument 3 `$mappingTypes` now requires `array`, not `null`). Stale containers caused all integration tests to fail.
- **Fix:** Cleared stale container cache directories. After clearing, fresh container compilation against DoctrineBundle 3.x passes correctly — no source code changes required.
- **Files modified:** None (runtime cache only)
- **Commit:** N/A

## Known Stubs

None. All config values are wired end-to-end: `cache_prefix_separator` flows from bundle config → DI parameter → constructor → pool(); `resolvers` config filters the resolver chain via compiler pass.

## Threat Flags

None. No new network endpoints, auth paths, file access patterns, or schema changes introduced. The threat model items T-13-01 through T-13-04 were addressed as designed.

## Self-Check: PASSED

- src/Command/TenantMigrateCommand.php — FOUND
- src/Cache/TenantAwareCacheAdapter.php — FOUND
- src/DependencyInjection/Compiler/ResolverChainPass.php — FOUND
- src/EventListener/EntityManagerResetListener.php — FOUND
- src/TenancyBundle.php — FOUND
- Commit cc6f626 — FOUND (CLI-01: nullable TenantMigrateCommand)
- Commit a5414c1 — FOUND (BOOT-02 + RESV-05: cache separator + resolver filtering)
- Commit 845c665 — FOUND (BOOT-01: DoctrineBootstrapper + EntityManagerResetListener EM targeting)
- Commit d1beea7 — FOUND (fix: separator default + PHPStan)
