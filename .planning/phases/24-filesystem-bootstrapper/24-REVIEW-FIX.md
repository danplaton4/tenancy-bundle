---
phase: 24-filesystem-bootstrapper
fixed_at: 2026-06-03T00:00:00Z
review_path: .planning/phases/24-filesystem-bootstrapper/24-REVIEW.md
iteration: 1
findings_in_scope: 13
fixed: 11
skipped: 2
status: partial
---

# Phase 24: Code Review Fix Report

**Fixed at:** 2026-06-03
**Source review:** `.planning/phases/24-filesystem-bootstrapper/24-REVIEW.md`
**Iteration:** 1

**Summary:**
- Findings in scope: 13 (CR-01, WR-01–WR-06, IN-01–IN-06; WR-02/IN-04/IN-07 explicitly skipped per instructions)
- Fixed: 11
- Skipped: 2 (WR-02, IN-04 — by design per review instructions; IN-07 also skipped per instructions but out of scope count)

Final suite state: **689 tests, 2950 assertions, Skipped: 1 (by-design), Incomplete: 0, Failures: 0**
PHPStan: **No errors**
cs-fixer: **clean**

## Fixed Issues

### CR-01: FilesystemTestKernel invalid driver + TenancyBundle compile-time guard

**Files modified:** `tests/Integration/Filesystem/FilesystemTestKernel.php`, `src/TenancyBundle.php`
**Commit:** `3d39771`
**Applied fix:** Changed `'driver' => 'shared_database'` to `'driver' => 'database_per_tenant'` in the kernel (line 148). Added a `->validate()->ifNotInArray(['database_per_tenant', 'shared_db'])->thenInvalid(...)` guard on the `driver` scalar node in `TenancyBundle::configure()` so future typos fail at container compile time.

### WR-01: FilesystemPrefixingDecorator trailing-slash normalisation + test

**Files modified:** `src/Filesystem/FilesystemPrefixingDecorator.php`, `tests/Unit/Filesystem/FilesystemPrefixingDecoratorTest.php`
**Commit:** `2be64f1`
**Applied fix:** Added a trailing-slash normalisation guard in `prefixer()`: `if ('' !== $prefix && !str_ends_with($prefix, '/')) { $prefix .= '/'; }`. Added `testNoTrailingSlashTemplateProducesRelativeListPaths()` which uses template `tenant_{slug}` (no trailing slash) and asserts `listContents()` returns `reports.txt`, not `/reports.txt`.

### WR-03: FilesystemContractPass rejects multiple tenancy.scoped tags + test

**Files modified:** `src/DependencyInjection/Compiler/FilesystemContractPass.php`, `tests/Unit/DependencyInjection/Compiler/FilesystemContractPassTest.php`
**Commit:** `d6a0901`
**Applied fix:** Added guard 4 at the top of the `foreach` loop: `if (count($tags) > 1) { throw new \LogicException(...) }`. Flattened the inner `foreach ($tags as $attrs)` loop to a direct `$attrs = $tags[0]` assignment after the guard. Added `testMultipleTagsOnSameServiceThrowsLogicException()` which attaches two `tenancy.scoped` tags to the same service and asserts a `LogicException` with the service ID and "declared 2 times".

### WR-04: Implement MissingFilesystemConfigExceptionTest + UnsupportedAdapterDsnSchemeExceptionTest

**Files modified:** `tests/Unit/Exception/MissingFilesystemConfigExceptionTest.php`
**Files created:** `tests/Unit/Exception/UnsupportedAdapterDsnSchemeExceptionTest.php`
**Commit:** `bd41344`
**Applied fix:** Replaced the `markTestIncomplete` stub with five real assertions: `forTenant('acme')` returns a `\LogicException`, is NOT a `\RuntimeException`, message contains the slug, message contains `adapter_dsn`, and returns an instance of `MissingFilesystemConfigException`. Created `UnsupportedAdapterDsnSchemeExceptionTest.php` asserting `forScheme()` ancestry (`\LogicException`, not `\RuntimeException`), self-type, message contains scheme name and supported list, and credential-leak guard (no `key=`/`secret=` in message). Incomplete count in suite dropped from 1 to 0.

### WR-05: Demo app schema note (no migration needed)

**Files modified:** `examples/saas/config/packages/tenancy.yaml`
**Commit:** `1199fcf`
**Applied fix:** Added a comment under `filesystem: enabled: true` confirming the demo uses `SchemaTool::updateSchema` (mapping-driven via `SeedDemoCommand`) — the `filesystemConfig` column is auto-created on next `app:seed-demo` run. No manual `ALTER TABLE` or migration framework is needed for the demo. End-user apps using doctrine/migrations are directed to `UPGRADE.md §0.3→0.4`.

### WR-06: AdapterDsnParser throws on array-style query values + test

**Files modified:** `src/Filesystem/AdapterDsnParser.php`, `tests/Unit/Filesystem/AdapterDsnParserTest.php`
**Commit:** `934509d`
**Applied fix:** In `parseQuery()`, replaced the silent `is_scalar` filter with a guard that throws `\InvalidArgumentException` when a non-scalar value is encountered, naming only the offending key (never the value) to preserve credential-leak discipline T-24-04-01. Added three tests: `testArrayStyleQueryValueThrowsInvalidArgumentException()`, `testArrayStyleQueryExceptionMessageNamesKey()`, and `testArrayStyleQueryExceptionMessageDoesNotEchoValue()`.

### IN-01: Remove dead service IDs from MakeFilesystemServicesPublicPass

**Files modified:** `tests/Integration/Filesystem/MakeFilesystemServicesPublicPass.php`
**Commit:** `0deed03`
**Applied fix:** Removed `tenancy.filesystem.prefixing_decorator` and `tenancy.filesystem.tenant_aware_decorator` from the IDs list (these are never registered under those names — the compiler pass registers them under `<id>.tenant_scoped`). Added a clarifying comment explaining the actual registration pattern.

### IN-02: Add use TenantInterface import to TenantAwareFilesystemDecorator

**Files modified:** `src/Filesystem/TenantAwareFilesystemDecorator.php`
**Commit:** `2a683c0`
**Applied fix:** Added `use Tenancy\Bundle\TenantInterface;` import. Replaced both inline `\Tenancy\Bundle\TenantInterface` FQCNs in `buildAndCache()` and `readConfig()` with the unqualified `TenantInterface`. cs-fixer kept the cross-namespace import as expected.

### IN-06: Fix typo test method names

**Files modified:** `tests/Integration/Filesystem/FilesystemBootstrapperIntegrationTest.php`, `tests/Integration/Filesystem/LongRunningWorkerFilesystemSimulationTest.php`
**Commit:** `0abc27a`
**Applied fix:** Renamed `testAutowiringDelivesDecorator` → `testAutowiringDeliversDecorator` and `testCrossTenatLeakNegativeAssertion` → `testCrossTenantLeakNegativeAssertion`.

### IN-03 + IN-05: Document unimplemented services key and AbstractTenant collision (document-only)

**Files modified:** `src/Filesystem/TenantFilesystemConfigTrait.php`, `tests/Integration/Support/StubTenantFilesystemExtension.php`
**Commit:** `88693aa`
**Applied fix (IN-03):** Updated the `services?` key comment in `TenantFilesystemConfigTrait` docblock to read "NOT yet honored in v0.4 — reserved for future per-service scoping; setting this key is a no-op in the current release."
**Applied fix (IN-05):** Added "Do NOT combine with AbstractTenant" warning to both `TenantFilesystemConfigTrait` and `StubTenantFilesystemExtension` docblocks explaining the duplicate column mapping risk.

## Skipped Issues

### WR-02: Empty-slug degenerate prefix

**File:** `src/Filesystem/FilesystemPrefixingDecorator.php:246-254`
**Reason:** Skipped by design per review instructions. The `slug` is the non-empty natural primary key — this is the project convention (documented in memory). Defensive runtime guards would add noise without protection given the DB-level constraint.

### IN-04: AbstractTenant self vs static on setFilesystemConfig()

**File:** `src/Entity/AbstractTenant.php:162`
**Reason:** Skipped by design per review instructions. Returning `self` matches `AbstractTenant`'s local convention (all setters return `self`). The inconsistency with `TenantFilesystemConfigTrait` (which returns `static`) is cosmetic.

### IN-07: Demo TenantUploadController no-tenant guard

**File:** `examples/saas/src/Controller/TenantUploadController.php:36-45`
**Reason:** Skipped by design per review instructions. The controller has an explicit "Remove from any non-local deployment" disclaimer. No change made.

---

_Fixed: 2026-06-03_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
