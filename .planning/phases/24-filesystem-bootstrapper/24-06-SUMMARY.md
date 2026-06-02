---
phase: 24-filesystem-bootstrapper
plan: 06
subsystem: filesystem
tags: [filesystem, decorator, per-tenant-adapter, lru-cache, dsn-parser, tenant-isolation]

# Dependency graph
requires:
  - phase: 24-filesystem-bootstrapper/03
    provides: "LruFilesystemCache — get/set/clear/size/hits/evictions"
  - phase: 24-filesystem-bootstrapper/04
    provides: "AdapterDsnParser — parse(dsn) → FilesystemAdapter"
  - phase: 24-filesystem-bootstrapper/02
    provides: "MissingFilesystemConfigException::forTenant(slug)"
  - phase: 24-filesystem-bootstrapper/01
    provides: "TenantFilesystemConfigTrait — getFilesystemConfig(): ?array"
provides:
  - "TenantAwareFilesystemDecorator — per_tenant_adapter mode FilesystemOperator decorator"
  - "method_exists() probe for getFilesystemConfig() — no trait = null config = MissingFilesystemConfigException"
  - "Live-read TenantContext per call: ZERO mutable instance state"
  - "TenantAwareFilesystemDecoratorTest — 15 tests covering all 9 behaviours"
affects:
  - "24-07 FilesystemBootstrapper + FilesystemContractPass — wires this decorator for per_tenant_adapter strategy"
  - "24-08 Integration tests — exercises this decorator end-to-end with the full kernel"
  - "24-09 Docs — documents per_tenant_adapter mode, adapter_dsn shapes"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Cache-first lookup: resolve() reads LruFilesystemCache→build-and-cache on miss — mirrors TenantAwareTransportsDecorator shape"
    - "method_exists() probe for optional trait method — zero BC break for custom tenant entities"
    - "ZERO non-readonly mutable instance state — live-read invariant enforced identically to FilesystemPrefixingDecorator (Plan 24-05)"
    - "No-active-tenant passthrough to $inner — consistent no-scoping semantic across both decorators"

key-files:
  created:
    - "src/Filesystem/TenantAwareFilesystemDecorator.php"
    - "tests/Unit/Filesystem/TenantAwareFilesystemDecoratorTest.php (replaces 24-00 stub)"

key-decisions:
  - "Path arguments forwarded VERBATIM to per-tenant adapter — no prefix arithmetic (unlike FilesystemPrefixingDecorator; each tenant adapter has its own root)"
  - "method_exists() probe not interface method — TenantInterface deliberately lacks getFilesystemConfig() (DEC-FILE-CONFIG zero BC break)"
  - "@phpstan-ignore method.notFound removed — PHPStan narrows correctly after method_exists() guard and the ignore fires as unmatched; plain call is clean"
  - "No same-namespace use statements — cs-fixer @Symfony no_unused_imports strips them (Phase 23 IN-05 lesson)"

patterns-established:
  - "Pattern: per-tenant-adapter decorator shape. Cache-first lookup + build-and-cache on miss + MissingFilesystemConfigException on absent config. First seen in this plan. Future per-tenant resource decorators (e.g. per-tenant S3 signing keys) can reuse this shape."
  - "Pattern: method_exists() probe for optional trait accessor. Avoids hard-coupling the decorator to TenantInterface; preserves v0.3 → v0.4 BC. Also used in AdapterDsnParser's cross-plan dependency guard."

requirements-completed: [BOOT-03]  # Partial — 24-06 ships per-tenant-adapter decorator + tests; 24-07 satisfies the rest of BOOT-03 (bootstrapper wiring + FilesystemContractPass).

# Metrics
duration: 4min
completed: 2026-06-02
---

# Phase 24 Plan 06: TenantAwareFilesystemDecorator Summary

**Per-tenant-adapter mode FilesystemOperator decorator with LRU-cached adapter instances, DSN-parsed per-tenant Filesystem construction, and 15-test behavioural suite covering cross-tenant isolation, missing-config exception, and live-read invariant pin**

## Performance

- **Duration:** ~4 min
- **Started:** 2026-06-02T20:14:26Z
- **Completed:** 2026-06-02T20:18:17Z
- **Tasks:** 2
- **Files created:** 2

## Accomplishments

- Shipped `TenantAwareFilesystemDecorator` implementing all 21 `FilesystemOperator` methods (18 interface methods + 3 `@method` extras: `publicUrl`, `temporaryUrl`, `checksum`)
- `resolve()` reads `TenantContext` LIVE per call: cache hit → cached `Filesystem`; cache miss → `buildAndCache()` → `AdapterDsnParser::parse()` → `new Filesystem($adapter)` → `LruFilesystemCache::set()`
- `readConfig()` uses `method_exists()` probe: tenants without `getFilesystemConfig()` receive `MissingFilesystemConfigException` instead of a fatal "call to undefined method"
- `MissingFilesystemConfigException` raised for: null `getFilesystemConfig()`, missing `adapter_dsn` key, entity without `getFilesystemConfig()` method
- No-active-tenant passthrough to `$inner` — consistent "no tenant = no scoping" semantic with `FilesystemPrefixingDecorator`
- Reflection-verified: all 4 constructor properties are `readonly`; class is `final`; zero mutable instance state
- Flipped `TenantAwareFilesystemDecoratorTest` stub to 15 fully-green tests

## Task Commits

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Implement TenantAwareFilesystemDecorator | `d0ba3f0` | `src/Filesystem/TenantAwareFilesystemDecorator.php` |
| 2 | Flip TenantAwareFilesystemDecoratorTest stub to GREEN | `7064c0e` | `tests/Unit/Filesystem/TenantAwareFilesystemDecoratorTest.php` |

Per the precedent set by Plans 24-03/24-04/24-05: the project's pre-commit hook runs the full PHPUnit suite, so the canonical RED-only commit is rejected by the hook. TDD intent was preserved in-process: tests written first, verified failing before the source was written, both committed atomically per task. Incomplete stubs dropped from 6 to 5 after Task 2.

## Files Created/Modified

- `src/Filesystem/TenantAwareFilesystemDecorator.php` — **NEW**. Final class, 280 lines. Public surface: constructor (`$inner`, `$context`, `$cache`, `$parser` all readonly), 21 `FilesystemOperator` methods each delegating to `resolve()`. Private: `resolve(): FilesystemOperator`, `buildAndCache(TenantInterface): FilesystemOperator`, `readConfig(TenantInterface): ?array`.
- `tests/Unit/Filesystem/TenantAwareFilesystemDecoratorTest.php` — **REPLACES** the 24-00 stub. 15 test methods, 32 assertions. Uses real `LruFilesystemCache`, `AdapterDsnParser`, `TenantContext`, and `InMemoryFilesystemAdapter` — no mocks.

## Decisions Made

1. **Path arguments forwarded VERBATIM — no prefix.** Unlike `FilesystemPrefixingDecorator`, this decorator does not manipulate paths. Each tenant's adapter has its own root (the `adapter_dsn`), so the application writes to unqualified paths like `uploads/report.pdf` and the adapter routes to the tenant-specific bucket/directory root. Adding a prefix would double-scope and break path expectations.
2. **`@phpstan-ignore method.notFound` removed.** The plan's `<action>` block mentioned adding this annotation if PHPStan level 9 narrowing fails. In practice, PHPStan narrows the type correctly after the `method_exists()` guard — the annotation itself triggered "No error with identifier method.notFound is reported" (unmatched ignore). Removed the annotation; plain call is clean.
3. **No same-namespace `use` statements for `LruFilesystemCache` and `AdapterDsnParser`.** Both live in `Tenancy\Bundle\Filesystem` — same namespace as the decorator. Per Phase 23 IN-05 lesson, cs-fixer @Symfony `no_unused_imports` strips same-namespace imports. Used unqualified class names throughout (they are already in scope via namespace).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] `@phpstan-ignore method.notFound` fires as unmatched**
- **Found during:** Initial PHPStan run on Task 1.
- **Issue:** The plan's `<action>` block included `@phpstan-ignore method.notFound` on the `$tenant->getFilesystemConfig()` call. PHPStan level 9 does NOT error on this call because `method_exists()` narrows the type correctly. The `@phpstan-ignore` itself produced "No error with identifier method.notFound is reported on line N (non-ignorable)".
- **Fix:** Removed the `@phpstan-ignore` annotation. Plain call with no annotation is clean at level 9.
- **Files modified:** `src/Filesystem/TenantAwareFilesystemDecorator.php`
- **Verification:** `vendor/bin/phpstan analyse --level 9` → OK No errors.
- **Committed in:** `d0ba3f0` (Task 1).

**2. [Rule 1 — Bug] cs-fixer blank-line between `@security` blocks**
- **Found during:** cs-fixer check on Task 1.
- **Issue:** The plan's docblock included a blank `*` line between two consecutive `@security` annotation blocks. cs-fixer @Symfony flags this as a blank-line violation in the docblock.
- **Fix:** Removed the blank `*` separator line between `@security path-traversal` and `@security tenant-isolation`.
- **Files modified:** `src/Filesystem/TenantAwareFilesystemDecorator.php`
- **Verification:** `vendor/bin/php-cs-fixer check --diff` → clean.
- **Committed in:** `d0ba3f0` (Task 1).

### Plan-as-Written Items

- The plan's `<verify>` block instructed running PHPStan against both `src/` and test files. The project's `phpstan.neon` only scans `src/` (same project convention noted in Plan 24-03). PHPStan run on `src/` only — clean.

### No Architectural Changes

No Rule 4 issues triggered. Design as planned was implementable exactly as written with only the two minor cosmetic auto-fixes above.

## Threat Model Compliance

Per the plan's `<threat_model>`:

| Threat ID | Disposition | Coverage |
|-----------|-------------|----------|
| T-24-06-01 (cross-tenant adapter reuse via instance state) | mitigate | (1) Final class + 4 readonly properties — enforced by reflection test; (2) `resolve()` reads `TenantContext` fresh per call; (3) cross-tenant context switch behavioural pin in `testContextSwitchRoutesToCorrectAdapter()`. |
| T-24-06-02 (misconfigured tenant retried by Messenger) | mitigate | `MissingFilesystemConfigException extends \LogicException` — ancestry test pins the contract via `testMissingFilesystemConfigExceptionIsLogicException()` with explicit negative assertion `assertNotInstanceOf(\RuntimeException::class, $e)`. |
| T-24-06-03 (adapter_dsn pointing at landlord bucket) | accept | adapter_dsn is admin-supplied; trust boundary is the admin UI. Documented in decorator docblock `@security tenant-isolation`. |
| T-24-06-04 (adapter_dsn credential leak in exception trace) | mitigate | Plan 24-04's `AdapterDsnParser::unsupportedScheme()` receives only the scheme name, never the DSN. This decorator forwards exceptions from the parser unmodified. |

## Known Stubs

None. Both deliverables are fully functional. The downstream consumer (`FilesystemContractPass` wiring in Plan 24-07) is intentionally out of this plan's scope.

## Self-Check: PASSED

- `[ -f src/Filesystem/TenantAwareFilesystemDecorator.php ]` → FOUND
- `[ -f tests/Unit/Filesystem/TenantAwareFilesystemDecoratorTest.php ]` → FOUND
- `git log --oneline | grep d0ba3f0` → FOUND `feat(24-06): add TenantAwareFilesystemDecorator`
- `git log --oneline | grep 7064c0e` → FOUND `feat(24-06): flip TenantAwareFilesystemDecoratorTest stub to GREEN`
- `vendor/bin/phpunit tests/Unit/Filesystem/TenantAwareFilesystemDecoratorTest.php` → 15 tests, 32 assertions, OK
- `vendor/bin/phpstan analyse --no-progress` → OK No errors
- `vendor/bin/php-cs-fixer check --diff` → clean
- Reflection: all properties readonly, class final → VERIFIED inline during Task 1

## Hand-off Notes

- **For Plan 24-07 (`FilesystemBootstrapper` + `FilesystemContractPass`):** Wire `TenantAwareFilesystemDecorator` as the `per_tenant_adapter` strategy decorator. Constructor signature: `(FilesystemOperator $inner, TenantContext $context, LruFilesystemCache $cache, AdapterDsnParser $parser)`. The `$cache` and `$parser` are shared services — register them once in `services.php` (or `config/services.php`) and inject via `Reference`. `FilesystemBootstrapper::clear()` should call `$cache->clear()` (belt-and-suspenders with `TenantContextClearedListener` from Plan 24-03).
- **For Plan 24-08 (integration tests):** The `per_tenant_adapter` integration scenario should exercise this decorator end-to-end: two tenants with distinct `memory://` DSNs, prove write isolation, prove `LruFilesystemCache` eviction at `maxSize`.
- **For Plan 24-09 (docs):** Document the `method_exists()` probe behaviour — custom tenant entities without `TenantFilesystemConfigTrait` receive `MissingFilesystemConfigException`; add the trait to opt in. Also document the path-traversal trust boundary (`@security path-traversal` docblock).
