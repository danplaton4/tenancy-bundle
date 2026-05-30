---
phase: 24-filesystem-bootstrapper
plan: 00
subsystem: testing
tags: [filesystem, testing, scaffolding, phpunit, flysystem]

requires:
  - phase: 20-mailer-bootstrapper
    provides: MailerTestKernel + MakeMailerServicesPublicPass + StubTenantMailerExtension patterns mirrored here for the Filesystem-side scaffolding
provides:
  - 9 stub unit tests covering every Wave 1-4 BOOT-03 component (FilesystemPrefixingDecorator, TenantAwareFilesystemDecorator, LruFilesystemCache, TenantFilesystemConfigTrait, TenantContextClearedListener, AdapterDsnParser, FilesystemBootstrapper, FilesystemContractPass, MissingFilesystemConfigException)
  - 2 stub integration tests for Plan 24-08 (FilesystemBootstrapperIntegrationTest 5-scenario suite + LongRunningWorkerFilesystemSimulationTest 100-tenant LRU)
  - FilesystemTestKernel — FrameworkBundle + DoctrineBundle + FlysystemBundle + TenancyBundle with two memory-adapter storages (users.storage tagged tenancy.scoped, public.storage untagged)
  - ScopedStorageTaggingPass — compile-time tag attachment for users.storage (registerContainerConfiguration closures cannot see bundle-built definitions)
  - MakeFilesystemServicesPublicPass — exposes both flysystem-bundle storage IDs + Phase 24 tenancy.filesystem.* service IDs for direct ->get() in tests (tolerant of missing defs)
  - StubTenantFilesystemExtension trait — opt-in filesystemConfig nullable JSON column + accessors for test tenants
  - league/flysystem-bundle ^3.7 + league/flysystem-memory ^3.31 in require-dev + suggest (NOT require — DEC-FILE-BUNDLE optional-dep policy)
affects: [24-01, 24-02, 24-03, 24-04, 24-05, 24-06, 24-07, 24-08]

tech-stack:
  added: [league/flysystem-bundle ^3.7, league/flysystem-memory ^3.31, league/flysystem ^3.34 (transitive), league/flysystem-local ^3.31 (transitive), league/mime-type-detection ^1.16 (transitive)]
  patterns:
    - "Wave-0 stub-test pattern: testStub() → markTestIncomplete('… implemented in Plan 24-XX') with VALIDATION.md row pointer; PHPUnit reports I (never F/E)"
    - "Compiler-pass-driven tag attachment for bundle-supplied services: registerContainerConfiguration closures run BEFORE extension processing, so they cannot mutate Definitions the bundle creates. Use a TYPE_BEFORE_OPTIMIZATION compiler pass instead — same pattern Phase 24's production FilesystemContractPass will use to walk findTaggedServiceIds('tenancy.scoped')"
    - "Defensive public-aliasing compiler pass: MakeFilesystemServicesPublicPass references service IDs that don't yet exist (tenancy.filesystem.bootstrapper etc.) — the hasDefinition/hasAlias guard makes the pass idempotent and safe to land before Wave 1"

key-files:
  created:
    - tests/Unit/Filesystem/FilesystemPrefixingDecoratorTest.php
    - tests/Unit/Filesystem/TenantAwareFilesystemDecoratorTest.php
    - tests/Unit/Filesystem/LruFilesystemCacheTest.php
    - tests/Unit/Filesystem/TenantFilesystemConfigTraitTest.php
    - tests/Unit/Filesystem/TenantContextClearedListenerTest.php
    - tests/Unit/Filesystem/AdapterDsnParserTest.php
    - tests/Unit/Bootstrapper/FilesystemBootstrapperTest.php
    - tests/Unit/DependencyInjection/Compiler/FilesystemContractPassTest.php
    - tests/Unit/Exception/MissingFilesystemConfigExceptionTest.php
    - tests/Integration/Filesystem/FilesystemTestKernel.php
    - tests/Integration/Filesystem/ScopedStorageTaggingPass.php
    - tests/Integration/Filesystem/MakeFilesystemServicesPublicPass.php
    - tests/Integration/Filesystem/FilesystemBootstrapperIntegrationTest.php
    - tests/Integration/Filesystem/LongRunningWorkerFilesystemSimulationTest.php
    - tests/Integration/Support/StubTenantFilesystemExtension.php
  modified:
    - composer.json

key-decisions:
  - "Phase 24-00: league/flysystem-bundle + league/flysystem-memory in require-dev + suggest only (NEVER require) — preserves DEC-FILE-BUNDLE optional-dep policy. Production --no-dev installs verified clean (composer install --no-dev --dry-run removes all 5 flysystem packages from the resolved set)"
  - "Phase 24-00: RESEARCH §Pitfall 3 / Assumption A7 — league/flysystem-memory is NOT transitively pulled by league/flysystem 3.34.0, contradicting CONTEXT §DEC-FILE-TEST-ADAPTER. composer.json explicitly requires-dev league/flysystem-memory ^3.31"
  - "Phase 24-00: stubs use markTestIncomplete (NOT markTestSkipped) because the league/flysystem-bundle + league/flysystem-memory are NOW in require-dev — every CI install will have them. The interface_exists() guards still live on the production-side classes that Wave 1+ will add, so the bundle still degrades cleanly under --no-dev"
  - "Phase 24-00: ScopedStorageTaggingPass extracted as a separate file (NOT inlined in FilesystemTestKernel) because the compile-pass logic is structurally identical to what Phase 24's production FilesystemContractPass will do — keeping it as a discrete class makes the precedent unmistakeable to Plan 24-07's implementer"

patterns-established:
  - "Stub-test shape: declare(strict_types=1); namespace Tenancy\\Bundle\\Tests\\Unit\\Filesystem; final class XTest extends TestCase { public function testStub(): void { markTestIncomplete('Stub — implemented in Plan 24-XX. See …/24-VALIDATION.md.') } }"
  - "Bundle-supplied-service mutation pattern: compiler pass at TYPE_BEFORE_OPTIMIZATION (default) addTag() on findDefinition('users.storage') — registerContainerConfiguration closures CANNOT see bundle-built definitions; this is the only correct technique"
  - "Test-kernel guarding: every conditionally-registered bundle (DoctrineBundle, FlysystemBundle) wrapped in class_exists() — keeps the kernel file syntactically valid under --no-dev installs and gracefully degrades when an optional bundle is absent"

requirements-completed: []

duration: ~25min
completed: 2026-05-30
---

# Phase 24 Plan 00: Filesystem Test Scaffolding Summary

**15 scaffolding files (9 stub unit tests + 2 stub integration tests + FilesystemTestKernel + 2 compiler passes + StubTenantFilesystemExtension trait) + composer.json deps unblock every Wave 1+ task in Phase 24 — `vendor/bin/phpunit tests/Unit/Filesystem tests/Integration/Filesystem tests/Unit/Bootstrapper/FilesystemBootstrapperTest.php tests/Unit/DependencyInjection/Compiler/FilesystemContractPassTest.php tests/Unit/Exception/MissingFilesystemConfigExceptionTest.php` reports 11 incomplete, zero failures, zero errors. Bundle still boots cleanly under `composer install --no-dev`.**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-05-30T18:24:00Z (approximate — kicked off after orchestrator handed off)
- **Completed:** 2026-05-30T18:48:56Z
- **Tasks:** 3 (per PLAN.md)
- **Files created:** 15 (9 unit-test stubs + 2 integration stubs + 1 kernel + 2 compiler passes + 1 trait)
- **Files modified:** 1 (composer.json)
- **Test count delta:** 568 → 579 tests (+11 incomplete stubs; 0 failures, 0 errors)

## Accomplishments

- 9 stub unit-test classes — one per Wave 1-4 BOOT-03 component — each pointing forward at the downstream plan that will fill it in (24-01 through 24-07)
- 2 stub integration-test classes — `FilesystemBootstrapperIntegrationTest` (5-scenario suite from DEC-FILE-TEST-ADAPTER) and `LongRunningWorkerFilesystemSimulationTest` (100-tenant LRU mirror of Phase 20)
- `FilesystemTestKernel`: kernel-level scaffolding with FrameworkBundle + DoctrineBundle + FlysystemBundle + TenancyBundle. Two memory-adapter storages (`users.storage` tagged `tenancy.scoped`, `public.storage` untagged). Doctrine wired against `sqlite:///:memory:` with `Tenancy\Bundle\Entity` mapping.
- `ScopedStorageTaggingPass`: compile-time `tenancy.scoped` tag attachment to `users.storage` (registerContainerConfiguration closures run BEFORE extension processing — they cannot see bundle-built definitions; the canonical workaround is a compiler pass at `TYPE_BEFORE_OPTIMIZATION`).
- `MakeFilesystemServicesPublicPass`: defensive public-aliasing compiler pass; mirrors `MakeMailerServicesPublicPass`. References Wave 1-3 service IDs that don't yet exist (`tenancy.filesystem.bootstrapper`, etc.) and uses `hasDefinition`/`hasAlias` guards.
- `StubTenantFilesystemExtension` trait: drop-in `filesystemConfig` accessors so existing test tenants can opt into the Phase 24 contract without breakage. Doctrine attribute unconditional (require-dev pulls Doctrine, mirroring `StubTenantMailerExtension`).
- `league/flysystem-bundle ^3.7` + `league/flysystem-memory ^3.31` added to `require-dev` and `suggest` — both stay optional, `interface_exists(\League\Flysystem\FilesystemOperator::class)` will gate production code in Wave 1+.

## Task Commits

Each task was committed atomically:

1. **Task 1: Add Flysystem deps to composer.json (require-dev + suggest)** — `09cd869` (chore)
2. **Task 2: Create 9 stub unit test classes** — `6d3b536` (test)
3. **Task 3: Create integration test scaffolding (kernel + compiler pass + 2 stub integration tests + stub tenant trait)** — `7783601` (test)

## Files Created/Modified

### Created

- `tests/Unit/Filesystem/FilesystemPrefixingDecoratorTest.php` — stub for Plan 24-05 (prefix-mode decorator)
- `tests/Unit/Filesystem/TenantAwareFilesystemDecoratorTest.php` — stub for Plan 24-06 (per-tenant-adapter decorator)
- `tests/Unit/Filesystem/LruFilesystemCacheTest.php` — stub for Plan 24-03 (LRU cache + clear())
- `tests/Unit/Filesystem/TenantFilesystemConfigTraitTest.php` — stub for Plan 24-01 (trait default impl)
- `tests/Unit/Filesystem/TenantContextClearedListenerTest.php` — stub for Plan 24-03 (listener flushes LRU on TenantContextCleared)
- `tests/Unit/Filesystem/AdapterDsnParserTest.php` — stub for Plan 24-04 (DSN parser, 3 schemes + unsupported-scheme exception)
- `tests/Unit/Bootstrapper/FilesystemBootstrapperTest.php` — stub for Plan 24-07 (bootstrapper no-op boot + cache clear)
- `tests/Unit/DependencyInjection/Compiler/FilesystemContractPassTest.php` — stub for Plan 24-07 (3 compile-time guards)
- `tests/Unit/Exception/MissingFilesystemConfigExceptionTest.php` — stub for Plan 24-02 (LogicException ancestry pinning)
- `tests/Integration/Filesystem/FilesystemTestKernel.php` — Phase 24 integration kernel (FrameworkBundle + DoctrineBundle + FlysystemBundle + TenancyBundle, two memory storages)
- `tests/Integration/Filesystem/ScopedStorageTaggingPass.php` — compile-time tag attachment for users.storage (DEVIATION — see below)
- `tests/Integration/Filesystem/MakeFilesystemServicesPublicPass.php` — public-aliasing pass for tests (mirrors MakeMailerServicesPublicPass)
- `tests/Integration/Filesystem/FilesystemBootstrapperIntegrationTest.php` — stub for Plan 24-08 (5 BOOT-03 scenarios)
- `tests/Integration/Filesystem/LongRunningWorkerFilesystemSimulationTest.php` — stub for Plan 24-08 (100-tenant LRU simulation)
- `tests/Integration/Support/StubTenantFilesystemExtension.php` — trait with filesystemConfig setter/getter

### Modified

- `composer.json` — added `league/flysystem-bundle ^3.7` and `league/flysystem-memory ^3.31` to `require-dev`; added matching entries to `suggest`. Resolves under `composer.lock` (5 packages added, all under `thephpleague` ownership per RESEARCH §Package Legitimacy Audit).

## Decisions Made

- **Optional-dep enforcement:** kept Flysystem packages in `require-dev` + `suggest`, NEVER `require`. Verified `composer install --no-dev --dry-run` removes all 5 packages from the resolved set. The `interface_exists(\League\Flysystem\FilesystemOperator::class)` guard pattern (matching Phase 20 Mailer's `MailerInterface` shape) gates all Wave 1+ production code.
- **Pitfall 3 correction over CONTEXT:** RESEARCH §Pitfall 3 / Assumption A7 confirmed `league/flysystem-memory` is NOT transitively pulled by `league/flysystem` 3.34.0 — contradicts CONTEXT §DEC-FILE-TEST-ADAPTER. This plan explicitly adds `league/flysystem-memory ^3.31` to `require-dev` so the in-memory adapter (used by both the integration suite AND the production `memory://` DSN scheme of the Wave-2 `AdapterDsnParser`) is reachable without a follow-up composer-require step.
- **Stub guard simpler than Phase 20:** Phase 20 stubs used `markTestSkipped` (when MailerInterface absent) → `markTestIncomplete`. Phase 24 stubs go straight to `markTestIncomplete` because Flysystem packages live in `require-dev` and every CI install carries them. Users on a stripped-down install (no flysystem-bundle) still see the bundle load cleanly — they just don't run the test suite, which is the appropriate signal.
- **Compiler pass extraction (Rule 2 deviation):** see Deviations section.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] ScopedStorageTaggingPass extracted from FilesystemTestKernel.php**
- **Found during:** Task 3 verification — the original PLAN action attached `tenancy.scoped` to `users.storage` inside a deferred `$loader->load(function (ContainerBuilder $container): void { … })` closure in `registerContainerConfiguration()`.
- **Issue:** Symfony's `registerContainerConfiguration` closures all execute during the **config-loading** phase — BEFORE the FlysystemExtension's `load()` method runs and builds the `users.storage` Definition. The closure's `$container->hasDefinition('users.storage')` returned `false` → silent early-return → tag never attached. Direct verification via a custom inspector compiler pass at `TYPE_BEFORE_OPTIMIZATION` priority `-10000` confirmed `findTaggedServiceIds('tenancy.scoped')` returned `[]` after kernel boot.
- **Fix:** Extracted the tag-attachment logic into `tests/Integration/Filesystem/ScopedStorageTaggingPass.php` (a `CompilerPassInterface` implementation) and registered it in `FilesystemTestKernel::build()` alongside `MakeFilesystemServicesPublicPass`. Compiler passes run AFTER all extensions have built their definitions, so `users.storage` is reachable. Verification: a static-state inspector pass at low priority now sees `users.storage` tagged `tenancy.scoped` with `{"strategy":"prefix","prefix_template":"tenant_{slug}/"}` — exactly what the Wave 1+ FilesystemContractPass will walk.
- **Why missing-critical:** Without this, Wave 1+ integration tests cannot exercise the tag-driven decoration wiring at all — `FilesystemContractPass` would never find a tagged service to wrap. The plan's `<verify>` clause for Task 3 ("`users.storage` carries the `tenancy.scoped` DI tag") would have silently failed.
- **Files modified:** `tests/Integration/Filesystem/FilesystemTestKernel.php` (removed closure, added `addCompilerPass(new ScopedStorageTaggingPass())`), `tests/Integration/Filesystem/ScopedStorageTaggingPass.php` (new).
- **Committed in:** `7783601` (Task 3 commit).

### Out of Scope

**1. [Out of scope] PHP CS Fixer version mismatch warning**
- **Found during:** Task 3 cs-fixer check.
- **Issue:** `php-cs-fixer 3.95.1` emits a stderr warning: "You are running PHP CS Fixer on PHP 8.5.6, but the minimum PHP version supported by your project in composer.json is PHP 8.2." Pre-existing — not caused by Plan 24-00.
- **Disposition:** Logged here for visibility; not addressed. The CI matrix runs PHP 8.2/8.3/8.4, so locally-run 8.5 cs-fixer is a developer-environment concern, not a project artifact.

---

**Total deviations:** 1 auto-fixed (Rule 2 — compiler-pass extraction; structural necessity, not scope creep) + 1 out-of-scope (warning, not error).
**Impact on plan:** Plan executed to deliverables exactly as written. The compiler-pass extraction is a structural improvement that also establishes the canonical Phase 24 tagging pattern for the production `FilesystemContractPass` in Plan 24-07.

## Issues Encountered

- **`registerContainerConfiguration` cannot mutate bundle-built definitions:** see Deviation 1. Documented for downstream plans — any future need to tag, decorate, or otherwise mutate `flysystem.storages.*` Definitions must go through a compiler pass, not a config-time closure. Wave 1+ implementers should treat this as the canonical pattern.
- **Symfony compiled-container tag stripping:** A pre-commit-time sanity check via `$container->findTaggedServiceIds('tenancy.scoped')` on the BOOTED container returned `[]` (compiled containers strip tags — they're build-time-only metadata). Verification therefore requires a custom inspector compiler pass at low priority, not runtime `findTaggedServiceIds` calls. Documented for Wave 1+ test authors who may otherwise assume the booted container retains tags.

## Threat Surface Audit

`<threat_model>` covered T-24-00-SC (Tampering — supply-chain) and T-24-00-01 (Information Disclosure — test temp dirs). Both mitigations confirmed:

- **T-24-00-SC:** `composer.json` grep shows `league/flysystem-bundle` and `league/flysystem-memory` only inside the `require-dev` and `suggest` blocks (never `require`). RESEARCH §Package Legitimacy Audit verified both packages are owned by `thephpleague` org with 12+ year history and 10M+/month downloads. No `[ASSUMED]` or `[SUS]` packages — no blocking-human checkpoint required.
- **T-24-00-01:** FilesystemTestKernel cache/log dirs sit under `sys_get_temp_dir().'/tenancy_filesystem_test_'.md5(static::class).'_'.$this->environment.'/...'` — isolated per-static-class + per-environment, matches the Phase 20 pattern that cleaned up without issue.

No new threat surface introduced beyond what `<threat_model>` enumerated. No `threat_flag` entries.

## Validation Compliance

- ✅ All 11 Wave 1+ target test files exist and report as PHPUnit `I` (incomplete) — Nyquist Rule unblocked for Wave 1
- ✅ `vendor/bin/phpunit tests/Unit/Filesystem tests/Integration/Filesystem tests/Unit/Bootstrapper/FilesystemBootstrapperTest.php tests/Unit/DependencyInjection/Compiler/FilesystemContractPassTest.php tests/Unit/Exception/MissingFilesystemConfigExceptionTest.php` → 11 incomplete, 0 failures, 0 errors
- ✅ Full suite `vendor/bin/phpunit` → 579 tests, 11 incomplete, 0 failures, 0 errors (was 568 / 0 / 0 / 0 pre-plan — clean delta of +11 incomplete stubs)
- ✅ `composer validate --no-check-publish --no-interaction` → exit 0 (one pre-existing `nikic/php-parser` warning, unrelated)
- ✅ `composer install --no-dev --no-scripts --no-interaction --dry-run` → removes all 5 flysystem packages from the resolved set (production install unaffected)
- ✅ `jq -e '.["require-dev"]["league/flysystem-bundle"] and .["require-dev"]["league/flysystem-memory"] and (.require["league/flysystem-bundle"] // null) == null and .suggest["league/flysystem-bundle"] and .suggest["league/flysystem-memory"]' composer.json` → `true`
- ✅ `vendor/bin/phpstan analyse tests/Unit/Filesystem tests/Integration/Filesystem tests/Integration/Support/StubTenantFilesystemExtension.php tests/Unit/Bootstrapper/FilesystemBootstrapperTest.php tests/Unit/DependencyInjection/Compiler/FilesystemContractPassTest.php tests/Unit/Exception/MissingFilesystemConfigExceptionTest.php --level 9` → No errors
- ✅ `vendor/bin/php-cs-fixer check --diff --config=.php-cs-fixer.dist.php` → all files compliant (0 files needing changes)
- ✅ FilesystemTestKernel boots, both `users.storage` and `public.storage` resolve to `League\Flysystem\Filesystem` instances; `users.storage` tagged `tenancy.scoped` with `{"strategy":"prefix","prefix_template":"tenant_{slug}/"}` (verified via low-priority inspector compiler pass — the canonical technique on a compiled container)

## Next Phase Readiness

Wave 1 plans (24-01 through 24-08) can now use any of the 11 target test files in their `<verify><automated>` commands without violating the Nyquist Rule. Specifically:

- **24-01** (TenantFilesystemConfigTrait + AbstractTenant column) → `TenantFilesystemConfigTraitTest`; can reuse `StubTenantFilesystemExtension`
- **24-02** (MissingFilesystemConfigException) → `MissingFilesystemConfigExceptionTest`
- **24-03** (LruFilesystemCache + TenantContextClearedListener) → `LruFilesystemCacheTest`, `TenantContextClearedListenerTest`
- **24-04** (AdapterDsnParser) → `AdapterDsnParserTest`
- **24-05** (FilesystemPrefixingDecorator) → `FilesystemPrefixingDecoratorTest`
- **24-06** (TenantAwareFilesystemDecorator) → `TenantAwareFilesystemDecoratorTest`
- **24-07** (FilesystemBootstrapper + FilesystemContractPass + TenancyBundle wiring) → `FilesystemBootstrapperTest`, `FilesystemContractPassTest`
- **24-08** (Integration suite + long-worker simulation) → `FilesystemBootstrapperIntegrationTest`, `LongRunningWorkerFilesystemSimulationTest` (uses `FilesystemTestKernel` + `MakeFilesystemServicesPublicPass` + `ScopedStorageTaggingPass`)

No blockers for Wave 1.

## Self-Check: PASSED

Verified all 15 files exist on disk and all 3 task commits are present in git log:

- `composer.json` (modified) — `09cd869`
- 9 unit-test stubs under `tests/Unit/{Filesystem,Bootstrapper,DependencyInjection/Compiler,Exception}/` — `6d3b536`
- 6 integration files under `tests/Integration/{Filesystem,Support}/` — `7783601`

---
*Phase: 24-filesystem-bootstrapper*
*Completed: 2026-05-30*
