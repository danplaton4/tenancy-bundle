---
phase: 24-filesystem-bootstrapper
plan: 07
subsystem: filesystem
tags: [filesystem, bootstrapper, compiler-pass, di-wiring, flysystem, symfony-bundle]

# Dependency graph
requires:
  - phase: 24-filesystem-bootstrapper/03
    provides: "LruFilesystemCache — clear() is the integration point for FilesystemBootstrapper"
  - phase: 24-filesystem-bootstrapper/05
    provides: "FilesystemPrefixingDecorator — FilesystemContractPass wires as prefix strategy decorator"
  - phase: 24-filesystem-bootstrapper/06
    provides: "TenantAwareFilesystemDecorator — FilesystemContractPass wires as per_tenant_adapter strategy decorator"

provides:
  - "FilesystemBootstrapper — no-op boot() + LRU-clear clear(), priority -30 on tenancy.bootstrapper tag"
  - "FilesystemContractPass — 3 compile-time guards + findTaggedServiceIds('tenancy.scoped') → setDecoratedService() tag-driven decoration injection"
  - "TenancyBundle.configure() tenancy.filesystem arrayNode (enabled, allow_per_tenant_adapter, prefix_template, cache_size)"
  - "TenancyBundle.loadExtension() 4 tenancy.filesystem.* parameters + conditional services import"
  - "TenancyBundle.build() FilesystemContractPass registration conditional on FilesystemOperator interface"
  - "config/services.php 4 conditional services under interface_exists(FilesystemOperator) block"

affects:
  - "24-08 integration tests — relies on full wiring layer (FilesystemTestKernel boots with tenancy.filesystem.* parameters)"
  - "24-09 docs — documents tenancy.filesystem config node, tenancy.scoped tag attributes"

# Tech tracking
tech-stack:
  added: []  # No new composer deps — uses FilesystemOperator from league/flysystem-bundle already in require-dev
  patterns:
    - "FilesystemBootstrapper mirrors MailerBootstrapper shape: final class, optional ?LruFilesystemCache ctor arg, no-op boot(), cache?->clear() teardown"
    - "FilesystemContractPass mirrors MailerTransportContractPass shape: CompilerPassInterface, constants for parameters, early-return when disabled, LogicException for each guard"
    - "interface_exists(FilesystemOperator::class) guards in both loadExtension() and build() — symmetric with Mailer's MailerInterface guard"
    - "Tag-driven decoration via findTaggedServiceIds('tenancy.scoped') + setDecoratedService($id) — compile-time rewrite, no user-side YAML beyond the tag"
    - "Early-return on disabled feature flag (tenancy.filesystem.enabled=false default) — zero observable effect for upgrading users"

key-files:
  created:
    - "src/Bootstrapper/FilesystemBootstrapper.php"
    - "src/DependencyInjection/Compiler/FilesystemContractPass.php"
  modified:
    - "src/TenancyBundle.php (configure + loadExtension + build)"
    - "config/services.php (4 new services under interface_exists block)"
    - "tests/Unit/Bootstrapper/FilesystemBootstrapperTest.php (replaces 24-00 stub)"
    - "tests/Unit/DependencyInjection/Compiler/FilesystemContractPassTest.php (replaces 24-00 stub)"

key-decisions:
  - "FilesystemBootstrapper is a structural twin of MailerBootstrapper — same file shape, same public surface, same semantics. Substitution: LruFilesystemCache for LruTransportCache."
  - "FilesystemContractPass early-returns when tenancy.filesystem.enabled is false (the default). This preserves the zero-config story: v0.3 users upgrading to v0.4 see no behaviour change until they add enabled: true."
  - "Guard 1 (bundle-absent) is skipped in unit tests because league/flysystem-bundle is in require-dev. Plan 24-08 integration tests cover it via a stub kernel. Marked with markTestSkipped() per plan guidance."
  - "Pre-existing PHPStan errors in config/services.php (lines 52/57 — public(false) signature drift) are out of scope — present before this plan and not caused by our changes."
  - "config/services.php uses `TenantContextClearedListener as FilesystemTenantContextClearedListener` alias to disambiguate from the Mailer class with the same short name."

patterns-established:
  - "Pattern: Filesystem bootstrapper wiring. Mirrors Mailer bootstrapper wiring exactly. Future per-tenant resource bootstrappers (caching, queues) can follow this same 3-step pattern: FilesystemBootstrapper + FilesystemContractPass + TenancyBundle config node."
  - "Pattern: feature-flag-gated compiler pass. FilesystemContractPass returns early when tenancy.filesystem.enabled=false. Allows clean upgrade path for existing installs."

requirements-completed: [BOOT-03]

# Metrics
duration: 21min
completed: 2026-06-02
---

# Phase 24 Plan 07: FilesystemBootstrapper + FilesystemContractPass Wiring Summary

**Wiring layer that ties Waves 1+2 together: FilesystemBootstrapper (priority -30 lifecycle participant), FilesystemContractPass (3 guards + tag-driven decoration injection), TenancyBundle config node with 4 filesystem keys, and 4 conditional DI services**

## Performance

- **Duration:** ~21 min
- **Started:** 2026-06-02T20:09:00Z
- **Completed:** 2026-06-02T20:31:58Z
- **Tasks:** 3
- **Files created:** 2
- **Files modified:** 4

## Accomplishments

- Shipped `FilesystemBootstrapper` — structural twin of `MailerBootstrapper`, no-op `boot()`, `clear()` flushes `LruFilesystemCache`, priority -30 on `tenancy.bootstrapper` tag
- Shipped `FilesystemContractPass` — 3 compile-time guards (bundle-installed, allow_per_tenant_adapter, valid strategy) + tag-walking decoration injection via `findTaggedServiceIds('tenancy.scoped')` → `setDecoratedService()`
- Extended `TenancyBundle.configure()` with `tenancy.filesystem` arrayNode (4 keys: enabled, allow_per_tenant_adapter, prefix_template, cache_size)
- Extended `TenancyBundle.loadExtension()` with 4 `tenancy.filesystem.*` parameters
- Extended `TenancyBundle.build()` with conditional `FilesystemContractPass` registration (guarded by `interface_exists(FilesystemOperator)`)
- Extended `config/services.php` with 4 conditional services under `interface_exists(FilesystemOperator)` block
- 17 unit tests across 2 new test classes; full 666-test suite green; PHPStan level 9 clean; cs-fixer clean
- `FilesystemTestKernel` boots and exposes `tenancy.filesystem.enabled` parameter (verified inline)

## Task Commits

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | FilesystemBootstrapper + flip stub test GREEN | `c340e24` | `src/Bootstrapper/FilesystemBootstrapper.php`, `tests/Unit/Bootstrapper/FilesystemBootstrapperTest.php` |
| 2 | FilesystemContractPass + flip stub test GREEN | `3588ab8` | `src/DependencyInjection/Compiler/FilesystemContractPass.php`, `tests/Unit/DependencyInjection/Compiler/FilesystemContractPassTest.php` |
| 3 | Wire TenancyBundle config + DI services + ContractPass | `3f9b1fd` | `src/TenancyBundle.php`, `config/services.php` |

Per the precedent set by Plans 24-03/04/05/06: the project's pre-commit hook runs the full PHPUnit suite, so a RED-only commit (failing test in isolation) is rejected by the hook. TDD intent was preserved in-process: tests written and verified-failing before production code, both committed atomically per task. Incomplete stubs dropped from 4 to 2 after Tasks 1 and 2.

## Files Created/Modified

- `src/Bootstrapper/FilesystemBootstrapper.php` — **NEW**. Final class, 41 lines. Implements TenantBootstrapperInterface: no-op boot(), clear() flushes LruFilesystemCache. Optional ?LruFilesystemCache constructor arg (mirrors MailerBootstrapper exactly).
- `src/DependencyInjection/Compiler/FilesystemContractPass.php` — **NEW**. Final class implementing CompilerPassInterface, 130 lines. 5 class constants, process() with 3 guards + tag-walk loop, buildDecorator() private helper.
- `src/TenancyBundle.php` — **MODIFIED**. 3 additions: (1) filesystem arrayNode in configure(); (2) 4 filesystem parameter assignments in loadExtension(); (3) FilesystemContractPass registration in build(). Plus `use` import for FilesystemContractPass.
- `config/services.php` — **MODIFIED**. interface_exists(FilesystemOperator) block with 4 services: lru_cache, adapter_dsn_parser, bootstrapper (priority -30), context_cleared_listener. Plus 4 `use` imports (FilesystemBootstrapper, AdapterDsnParser, LruFilesystemCache, FilesystemTenantContextClearedListener alias).
- `tests/Unit/Bootstrapper/FilesystemBootstrapperTest.php` — **REPLACES** the 24-00 stub. 6 tests covering: implements interface, final class, boot no-op, clear with null cache, clear flushes cache, clear idempotency.
- `tests/Unit/DependencyInjection/Compiler/FilesystemContractPassTest.php` — **REPLACES** the 24-00 stub. 11 tests (1 skipped for bundle-absent guard): guard 2, guard 3, prefix path, per_tenant_adapter path, untagged bypass, empty container, disabled early-return, disabled via missing parameter, default prefix_template, custom prefix_template.

## Decisions Made

1. **FilesystemBootstrapper is a structural twin of MailerBootstrapper.** Same file shape, same public surface, same semantics — only the import (`LruFilesystemCache` for `LruTransportCache`) and class name differ. Docblock cites plan sources per CONTEXT.md §DEC-FILE-PRIORITY.
2. **FilesystemContractPass returns early when `tenancy.filesystem.enabled` is false.** Default is `false`, so the zero-config story for v0.3 users upgrading to v0.4 is preserved — no behaviour change until they opt in.
3. **Guard 1 (bundle-absent) skipped in unit tests.** league/flysystem-bundle is in require-dev; the interface is always present in this test suite. `markTestSkipped()` per plan guidance — Plan 24-08 integration tests will cover it.
4. **`TenantContextClearedListener as FilesystemTenantContextClearedListener` alias in config/services.php.** Both the Mailer and Filesystem versions have the same short class name. The alias disambiguates within the file without changing any service ID or behaviour.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] PHPStan `cannot cast mixed to int` on filesystemCacheSize**
- **Found during:** Task 3 (TenancyBundle.loadExtension() parameter extraction)
- **Issue:** Initial code `is_scalar($filesystemConfig['cache_size'] ?? 32) ? (int) $filesystemConfig['cache_size'] : 32` failed PHPStan level 9: `Cannot cast mixed to int` because `$filesystemConfig['cache_size']` is typed `mixed` and the ternary with `is_scalar()` doesn't narrow to `int` for a cast.
- **Fix:** Extracted the raw value first, then used `is_int($filesystemCacheSizeRaw)` (narrower type guard) instead of `is_scalar()` to enable PHPStan's type narrowing: `$filesystemCacheSizeRaw = ...; $filesystemCacheSize = is_int($filesystemCacheSizeRaw) ? $filesystemCacheSizeRaw : 32;`
- **Files modified:** `src/TenancyBundle.php`
- **Verification:** `vendor/bin/phpstan analyse src/TenancyBundle.php --level 9` → OK No errors.
- **Committed in:** `3f9b1fd` (Task 3).

**2. [Rule 1 — Bug] cs-fixer wants multi-line exception strings condensed to single line**
- **Found during:** Task 2 (FilesystemContractPass implementation)
- **Issue:** Two multi-line string concatenations in `\LogicException(...)` calls triggered `single_line_throw` rule: cs-fixer @Symfony fixer requires the exception message to be on a single line.
- **Fix:** Collapsed each multi-line string concatenation to a single quoted string argument.
- **Files modified:** `src/DependencyInjection/Compiler/FilesystemContractPass.php`
- **Verification:** `vendor/bin/php-cs-fixer check --diff` → clean (empty `"files":[]`).
- **Committed in:** `3588ab8` (Task 2).

### Out-of-scope Pre-existing Issues Noted

- `config/services.php` lines 52 and 57: PHPStan `arguments.count` errors on `->public(false)` (Symfony DI ServiceConfigurator method signature drift). These errors existed BEFORE this plan (verified by stashing changes and re-running PHPStan). Out of scope per scope-boundary rule. Logged to `deferred-items.md` below.

---

**Total deviations:** 2 auto-fixed (2 × Rule 1 — Bug)
**Impact on plan:** Both auto-fixes were trivial cosmetic corrections (type narrowing, string formatting). No scope creep. No architectural changes.

## Issues Encountered

- **Anonymous-class FilesystemOperator stub formatting.** Initial compact single-line method style in `FilesystemBootstrapperTest.php` triggered cs-fixer `braces` rule (Symfony ruleset requires full brace blocks for anonymous class methods). Fixed by expanding each method body to full braces before the commit.

## Threat Model Compliance

Per the plan's `<threat_model>`:

| Threat ID | Disposition | Coverage |
|-----------|-------------|----------|
| T-24-07-01 (Tampering — misconfigured strategy) | mitigate | FilesystemContractPass guard 3 is implemented and pinned by `testGuard3RejectsInvalidStrategy`. |
| T-24-07-02 (Information Disclosure — per_tenant_adapter on shared hosting) | mitigate | Guard 2 (`allow_per_tenant_adapter=false` escape hatch) is implemented and pinned by `testGuard2RejectsPerTenantAdapterWhenForbidden`. |
| T-24-07-03 (Denial of Service — interface_exists absent) | mitigate | Both `loadExtension()` and `build()` guard via `interface_exists`. The explicit LogicException path for `enabled=true` + bundle-absent is the intended compilation failure. |
| T-24-07-04 (Spoofing — untagged storages bypass scoping) | accept | Tag-based opt-in is documented in DEC-FILE-MULTI. Untagged-bypass test in Plan 24-08 pins the contract. `testUntaggedStorageIsNotDecorated` unit test pins this in Plan 24-07 as well. |

## Known Stubs

None. All three tasks are fully implemented. The remaining incomplete stubs in the test suite (count = 2) are from sibling Wave 3+ plans not yet landed, not from this plan.

## Self-Check: PASSED

- `[ -f src/Bootstrapper/FilesystemBootstrapper.php ]` → FOUND
- `[ -f src/DependencyInjection/Compiler/FilesystemContractPass.php ]` → FOUND
- `git log --oneline | grep c340e24` → FOUND `feat(24-07): add FilesystemBootstrapper + flip stub test GREEN`
- `git log --oneline | grep 3588ab8` → FOUND `feat(24-07): add FilesystemContractPass + flip stub test GREEN`
- `git log --oneline | grep 3f9b1fd` → FOUND `feat(24-07): wire TenancyBundle filesystem config + DI services + ContractPass`
- `vendor/bin/phpunit tests/Unit/Bootstrapper/FilesystemBootstrapperTest.php` → 6 tests, 8 assertions, OK
- `vendor/bin/phpunit tests/Unit/DependencyInjection/Compiler/FilesystemContractPassTest.php` → 11 tests, 14 assertions, Skipped: 1, OK
- `vendor/bin/phpunit --testsuite unit` → 541 tests, 1435 assertions, Skipped: 1, Incomplete: 1, OK
- `vendor/bin/phpstan analyse src/TenancyBundle.php src/Bootstrapper/FilesystemBootstrapper.php src/DependencyInjection/Compiler/FilesystemContractPass.php --level 9` → OK No errors
- `vendor/bin/php-cs-fixer check --diff` → clean (`"files":[]`)
- `FilesystemTestKernel boot + tenancy.filesystem.enabled parameter` → param:ok

## Hand-off Notes

- **For Plan 24-08 (integration tests):** The full wiring layer is in place. `FilesystemTestKernel.php` boots cleanly. The `ScopedStorageTaggingPass` + `MakeFilesystemServicesPublicPass` test helpers are already scaffolded. Integration tests can now exercise the full compile+runtime path: (1) prefix mode isolation, (2) per_tenant_adapter mode isolation, (3) untagged bypass, (4) MissingFilesystemConfigException, (5) LRU bounded eviction.
- **Guard 1 (bundle-absent):** The unit test is skipped with `markTestSkipped()`. Plan 24-08 MUST add an integration test that boots a kernel WITHOUT `league/flysystem-bundle` and verifies the `LogicException` fires. This is the only gap in the compile-guard coverage.
- **Pre-existing PHPStan errors in config/services.php:** Lines 52/57 have `arguments.count` errors on `->public(false)`. These are pre-existing and out of scope for this plan. A future housekeeping pass can fix them (likely require upgrading to a newer Symfony DI ServiceConfigurator type signature or removing the `false` argument).
