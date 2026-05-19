---
phase: 13-audit-gap-closure
verified: 2026-04-13T21:45:00Z
status: passed
score: 7/7 must-haves verified
overrides_applied: 0
---

# Phase 13: Audit Gap Closure Verification Report

**Phase Goal:** Close all gaps identified in the v1.0 milestone audit: fix stale composer.lock (OSS-01), wire `tenancy.resolvers` config to actually filter active resolvers (RESV-05), fix TenantMigrateCommand nullable type mismatch (CLI-01), wire `cache_prefix_separator` into TenantAwareCacheAdapter (BOOT-02), and correct EntityManagerResetListener + DoctrineBootstrapper to target the tenant EM in database_per_tenant mode (BOOT-01).
**Verified:** 2026-04-13T21:45:00Z
**Status:** PASSED
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | `composer validate --strict` exits 0 (lock file in sync with composer.json) | VERIFIED | Exit code 0 confirmed; output: `./composer.json is valid` |
| 2 | TenantMigrateCommand accepts null Configuration without TypeError | VERIFIED | `?Configuration $migrationsConfig` at line 33; null guard at lines 63-67; `testNullMigrationsConfigExitsWithError` passes |
| 3 | cache_prefix_separator config value is used when building cache sub-namespace | VERIFIED | `$this->inner->withSubNamespace($tenant->getSlug().$this->cachePrefixSeparator)` at line 26 of TenantAwareCacheAdapter; DI param injected in services.php line 95 |
| 4 | ResolverChainPass filters built-in resolvers by tenancy.resolvers config list | VERIFIED | `BUILT_IN_RESOLVER_MAP` constant + `getParameter('tenancy.resolvers')` filtering in ResolverChainPass; `testProcessFiltersBuiltInResolversByConfigList` proves filtering |
| 5 | Custom resolvers (not in built-in map) always pass through ResolverChainPass filtering | VERIFIED | Logic at lines 59-64 in ResolverChainPass: only built-in FQCNs are subject to filtering; `testProcessAllowsCustomResolversEvenWhenFiltering` proves pass-through |
| 6 | DoctrineBootstrapper clears tenant EM (not landlord) in database_per_tenant mode | VERIFIED | TenancyBundle lines 114-117: `interface_exists` guard + `getDefinition('tenancy.doctrine_bootstrapper')->setArgument(0, new Reference('doctrine.orm.tenant_entity_manager'))` |
| 7 | EntityManagerResetListener resets only configured target EMs, not all EMs | VERIFIED | `$managersToReset = [null]` default, iterated in `__invoke`; overridden to `['tenant']` in database_per_tenant block (TenancyBundle lines 120-121); `testInvokeResetsOnlyConfiguredManagers` + `testInvokeResetsDefaultEmWhenNoManagersConfigured` both pass |

**Score:** 7/7 truths verified

### Deviation from Plan: cache_prefix_separator default

**Deviation:** Plan specified default separator `':'`. Actual implementation uses `'.'`.

**Reason (documented in SUMMARY):** The `:` character is a PSR-6 reserved character forbidden in `withSubNamespace()` namespace strings. Using `':'` caused `InvalidArgumentException` in integration tests. The `.` character is PSR-6 valid and semantically equivalent.

**Impact on truths:** Truth #3 still holds — the separator is used correctly. All 9 existing `withSubNamespace` tests updated from `'acme:'` to `'acme.'`; custom separator test uses `'acme_'`. Unit and integration tests all pass.

**Assessment:** This is a correct bug fix, not a regression. The plan's implementation detail was wrong; the goal (separator is actually injected and used) is achieved.

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `composer.lock` | Synced lock file matching composer.json content-hash | VERIFIED | `composer validate --strict` exits 0 |
| `src/Command/TenantMigrateCommand.php` | Nullable Configuration parameter with null guard | VERIFIED | `?Configuration $migrationsConfig` line 33; null guard lines 63-67; `runMigrationsForTenant` takes explicit non-null `Configuration` param (PHPStan level 9 compliant) |
| `src/Cache/TenantAwareCacheAdapter.php` | Cache prefix separator in constructor and pool() | VERIFIED | `string $cachePrefixSeparator = '.'` at line 18; used in pool() at line 26 |
| `src/DependencyInjection/Compiler/ResolverChainPass.php` | Resolver filtering by config parameter | VERIFIED | `BUILT_IN_RESOLVER_MAP` constant at lines 20-25; `getParameter('tenancy.resolvers')` at line 39 |
| `src/EventListener/EntityManagerResetListener.php` | Targeted EM reset via managersToReset parameter | VERIFIED | `array $managersToReset = [null]` at line 19; `foreach ($this->managersToReset as $name)` at line 29 |
| `src/TenancyBundle.php` | Conditional DI overrides for DoctrineBootstrapper and EntityManagerResetListener in database_per_tenant mode | VERIFIED | DoctrineBootstrapper override lines 113-117; EntityManagerResetListener override lines 119-121; both inside `if ($databaseConfig['enabled'] ?? false)` block |
| `config/services.php` | cache_prefix_separator parameter injection into tenancy.cache_adapter | VERIFIED | `param('tenancy.cache_prefix_separator')` at line 95 as third arg to `tenancy.cache_adapter` |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `src/TenancyBundle.php` | `config/services.php` | `loadExtension` overrides `tenancy.doctrine_bootstrapper` definition | WIRED | `getDefinition('tenancy.doctrine_bootstrapper')->setArgument(0, new Reference('doctrine.orm.tenant_entity_manager'))` confirmed at lines 115-116 |
| `src/DependencyInjection/Compiler/ResolverChainPass.php` | `src/TenancyBundle.php` | reads `tenancy.resolvers` container parameter set by `loadExtension` | WIRED | `$container->getParameter('tenancy.resolvers')` at line 39; parameter set in TenancyBundle line 91 |
| `config/services.php` | `src/Cache/TenantAwareCacheAdapter.php` | injects `cache_prefix_separator` as third constructor arg | WIRED | `param('tenancy.cache_prefix_separator')` at services.php line 95; received as `$cachePrefixSeparator` in constructor line 18 |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| composer validate --strict exits 0 | `composer validate --strict` | Exit code 0, "valid" | PASS |
| Full unit test suite passes | `vendor/bin/phpunit --testsuite unit` | 203 tests, 516 assertions, OK | PASS |
| Full test suite passes (unit + integration) | `vendor/bin/phpunit` | 264 tests, 637 assertions, OK | PASS |
| PHPStan level 9 clean | `vendor/bin/phpstan analyse src/` | 0 errors | PASS |
| php-cs-fixer clean | `vendor/bin/php-cs-fixer check --diff` | 0 violations, exit code 0 | PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|----------|
| OSS-01 | 13-01-PLAN.md | `composer.json` Packagist-ready; lock in sync | SATISFIED | `composer validate --strict` exits 0; composer.json has PHP `^8.2`, Symfony `^7.4\|\|^8.0`, `suggest` entries for doctrine/orm and doctrine/migrations, correct `extra.symfony` config. Note: REQUIREMENTS.md checkbox still shows unchecked — this is a tracking artifact from Phase 9; the technical requirement is now fully met. |
| CLI-01 | 13-01-PLAN.md | `tenancy:migrate` handles null Configuration gracefully | SATISFIED | `?Configuration $migrationsConfig`; null guard returns FAILURE with clear message; `testNullMigrationsConfigExitsWithError` passes |
| BOOT-02 | 13-01-PLAN.md | Cache bootstrapper uses configurable separator in namespace | SATISFIED | `$cachePrefixSeparator` injected via DI, used in `pool()`; all tests pass with `'acme.'` (separator changed from plan's `':'` to `'.'` — correct PSR-6 fix) |
| RESV-05 | 13-01-PLAN.md | Resolver chain filtered by tenancy.resolvers config | SATISFIED | `BUILT_IN_RESOLVER_MAP` + `getParameter('tenancy.resolvers')` filtering; custom resolvers always pass through; two new tests prove both behaviors |
| BOOT-01 | 13-01-PLAN.md | DoctrineBootstrapper targets tenant EM; EntityManagerResetListener resets only configured EMs | SATISFIED | DoctrineBootstrapper wired to `doctrine.orm.tenant_entity_manager` in database_per_tenant mode; EntityManagerResetListener uses `$managersToReset = ['tenant']` in database_per_tenant mode via DI override |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| None | — | No stubs, TODOs, or placeholder implementations found | — | — |

**Notable:** Commit `d557618` (not in SUMMARY) restored `TenantInitCommand` (Phase 12 work) after it was accidentally deleted by the Phase 13 executor during `composer update`. The restoration was executed by the developer and the codebase is now correct. This is not an anti-pattern in the current code — it is a resolved execution incident. The SUMMARY omits this commit, but the code is complete.

### Human Verification Required

No human verification items identified. All must-haves are programmatically verifiable and confirmed.

### Gaps Summary

No gaps. All 7 must-have truths verified. All 5 required artifacts exist and are substantive (non-stub), wired, and data-flowing. All 3 key links confirmed. Full test suite (264 tests) passes. PHPStan level 9 clean. Code style clean. `composer validate --strict` exits 0.

**One informational note (not a gap):** The REQUIREMENTS.md checkbox for OSS-01 remains unchecked (`[ ]`) and the traceability table says "Pending". This is a documentation tracking artifact — the technical requirement is fully met. No action required for phase closure, but REQUIREMENTS.md should be updated to mark OSS-01 complete in a future housekeeping pass.

---

_Verified: 2026-04-13T21:45:00Z_
_Verifier: Claude (gsd-verifier)_
