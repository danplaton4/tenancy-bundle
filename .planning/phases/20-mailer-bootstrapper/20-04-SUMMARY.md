---
phase: 20-mailer-bootstrapper
plan: 04
subsystem: mailer
tags: [mailer, di, compiler-pass, bundle, configuration, integration-tests]

requires:
  - phase: 20-mailer-bootstrapper
    plan: 02
    provides: "LruTransportCache + SanitizingMailerDecorator (registered in services.php this plan)"
  - phase: 20-mailer-bootstrapper
    plan: 03
    provides: "MailerBootstrapper + TenantMessageDecorator + TenantAwareTransportsDecorator (tagged / decorated in services.php this plan)"
provides:
  - "Tenancy\\Bundle\\DependencyInjection\\Compiler\\MailerTransportContractPass — compile-time guard with public constants X_TRANSPORT_SERVICE ('tenancy.mailer.message_decorator') and ASYNC_PARAM ('tenancy.mailer.async')"
  - "TenancyBundle mailer config tree: tenancy.mailer.transport_cache_size (int, default 32, min 1) + tenancy.mailer.async (scalar, default 'auto', validated ifNotInArray)"
  - "TenancyBundle parameters: tenancy.mailer.transport_cache_size, tenancy.mailer.async"
  - "config/services.php registrations for 5 mailer services under interface_exists(MailerInterface) guard"
  - "3 new ContainerCompilationTest methods covering happy-path compile + 2 failure modes"
affects: [20-05, 20-06, 20-07, 20-08]

tech-stack:
  added: []
  patterns:
    - "Always-on registration under interface_exists guard (D-05) — symfony/mailer is the only switch; no tenancy.mailer.enabled flag"
    - "Compile-time contract pass with public class constants exposed for documentation + test-pinning (X_TRANSPORT_SERVICE matches services.php ID by grep)"
    - "Per-test kernel subclasses (anonymous final classes in the same file) for integration tests that need to vary framework / tenancy configuration without polluting the shared MailerTestKernel"
    - "Defensive parameter handling: is_scalar() check on $config['mailer']['async'] before cast to string (PHPStan level 9 clean)"

key-files:
  created:
    - src/DependencyInjection/Compiler/MailerTransportContractPass.php
  modified:
    - src/TenancyBundle.php
    - config/services.php
    - tests/Unit/DependencyInjection/Compiler/MailerTransportContractPassTest.php
    - tests/Integration/ContainerCompilationTest.php
    - tests/Integration/Mailer/MailerTestKernel.php
    - tests/Integration/AutoconfigurationTest.php

key-decisions:
  - "Phase 20-04: contract pass treats a MISSING tenancy.mailer.async parameter as a hard \\LogicException (D-05 always-on philosophy) — never a silent default. The matching `if (interface_exists(MailerInterface))` block in TenancyBundle::loadExtension always sets the parameter, so the failure path can only be triggered by manual ContainerBuilder construction that bypasses Bundle::load() (e.g. AutoconfigurationTest::testBootstrapperChainDefinitionHasAddBootstrapperMethodCall — fixed by seeding the parameter)"
  - "Phase 20-04: framework.messenger.routing auto-detection walks getExtensionConfig('framework') in process() rather than reading a precomputed parameter — same approach as Symfony's own FrameworkExtension lookups; tolerates both leading-backslash and no-backslash FQCN keys"
  - "Phase 20-04: SanitizingMailerDecorator decorates `mailer` (not `mailer.mailer`) so it wraps the public MailerInterface alias users will inject; TenantAwareTransportsDecorator decorates `mailer.transports` so it intercepts the worker-side MessageHandler send"
  - "Phase 20-04: AutoconfigurationTest count assertions loosened to presence-by-class to accommodate the new bundle-shipped MailerBootstrapper (Rule 1 bug fix in pre-existing tests)"
  - "Phase 20-04: MailerTestKernel gets ReplaceTenancyProviderPass to mirror tests/Integration/TestKernel — required so the kernel boots without a real Doctrine EM (Rule 3 blocking)"

patterns-established:
  - "X_TRANSPORT_SERVICE / ASYNC_PARAM constants kept private but greppable — drift between the pass and services.php is caught by acceptance-criteria grep on both files"
  - "Anonymous test-kernel subclasses live in the same file as the test class — keeps the failure-mode kernels co-located with the test that drives them (mirrors the AutoconfigurationTest single/two kernel pattern)"

requirements-completed: [BOOT-04]

metrics:
  duration_min: 32
  tasks: 3
  files_created: 1
  files_modified: 6
  commits: 4
  started: "2026-05-20T00:00:00Z"
  completed: "2026-05-20T00:32:00Z"
---

# Phase 20 Plan 04: Bundle DI Wiring + Compile-Time Guard Summary

**Shipped the load-bearing integration point for Phase 20: the `mailer` config tree, the 2 mailer parameters, 5 service registrations in `config/services.php` (LruTransportCache, MailerBootstrapper at priority -20, TenantMessageDecorator, TenantAwareTransportsDecorator with `@event_dispatcher`, SanitizingMailerDecorator), and a 7-scenario `MailerTransportContractPass` that turns "async-routed-without-strategy" misconfigs into compile-time `\LogicException` instead of silent landlord-transport fallbacks at production.**

## Mailer Config Tree (verbatim added to TenancyBundle::configure())

```php
->arrayNode('mailer')
->addDefaultsIfNotSet()
->children()
->integerNode('transport_cache_size')->defaultValue(32)->min(1)->end()
->scalarNode('async')
    ->defaultValue('auto')
    ->validate()
        ->ifNotInArray(['auto', 'true', 'false'])
        ->thenInvalid('tenancy.mailer.async must be one of "auto", "true", "false". Got %s')
    ->end()
->end()
->end()
->end()
```

## Parameters Set in TenancyBundle::loadExtension()

```php
->set('tenancy.mailer.transport_cache_size', $mailerCacheSize)   // int, default 32
->set('tenancy.mailer.async', $mailerAsync);                     // string, default 'auto'
```

`$mailerAsync` is built via `is_scalar($mailerAsyncRaw) ? (string) $mailerAsyncRaw : 'auto'` to satisfy PHPStan level 9 `cast.string` (`$config['mailer']` is `mixed` at the type level).

## 5 Mailer Services Registered in config/services.php

| Service ID | Class | Key flags |
|------------|-------|-----------|
| `tenancy.mailer.lru_cache` | `LruTransportCache` | args: `param('tenancy.mailer.transport_cache_size')` |
| `tenancy.mailer.bootstrapper` | `MailerBootstrapper` | args: `service('tenancy.mailer.lru_cache')->nullOnInvalid()` · tag `tenancy.bootstrapper` priority **-20** |
| `tenancy.mailer.message_decorator` | `TenantMessageDecorator` | args: `service('tenancy.context')` · `autoconfigure(true)` (picks up `EventSubscriberInterface`) |
| `tenancy.mailer.transports_decorator` | `TenantAwareTransportsDecorator` | `decorate('mailer.transports')` · 5 args including `service('event_dispatcher')` (RESEARCH Q2 RESOLVED) |
| `tenancy.mailer.sanitizing_decorator` | `SanitizingMailerDecorator` | `decorate('mailer')` · args: `service('.inner')` |

All five live inside an `if (interface_exists(MailerInterface::class))` block (D-05 always-on registration; no `tenancy.mailer.enabled` flag).

## MailerTransportContractPass Public Surface

```
FQCN:  Tenancy\Bundle\DependencyInjection\Compiler\MailerTransportContractPass
final class implements Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface

private const X_TRANSPORT_SERVICE = 'tenancy.mailer.message_decorator';
private const ASYNC_PARAM         = 'tenancy.mailer.async';

public function process(ContainerBuilder $container): void
```

Throws `\LogicException` in 3 distinct misconfig scenarios:
1. Missing parameter — actionable message names the config key.
2. Invalid value (anything other than `auto`/`true`/`false`) — names the actual bad value.
3. Async detected (auto or forced) + missing strategy service — explicit X-Transport reference + the `false` escape hatch.

Auto-detection walks `$container->getExtensionConfig('framework')` looking for `messenger.routing[SendEmailMessage::class]`, accepting both leading-backslash and no-backslash FQCN keys.

## Test Mapping

| Source | Test class | Tests | Assertions |
|--------|-----------|------:|-----------:|
| `MailerTransportContractPass` | `tests/Unit/DependencyInjection/Compiler/MailerTransportContractPassTest.php` | 10 | 15 |
| `TenancyBundle` + `config/services.php` | `tests/Integration/ContainerCompilationTest.php` (3 new) | 3 | 10 |
| **Total new for Plan 04** | | **13** | **25** |

## 3 New Integration Test Methods (in ContainerCompilationTest)

1. **`testKernelCompilesWithMailerBundleConfigured`** — boots `MailerTestKernel`, asserts both parameters and all 5 service IDs are visible after compile. **Expected:** pass with no exception.
2. **`testCompilerPassFailsWhenAsyncRoutingDetectedButStrategyAbsent`** — `MailerAsyncRoutingKernel` enables `framework.messenger.routing` for `SendEmailMessage`, forces `tenancy.mailer.async: true`, removes the `tenancy.mailer.message_decorator` definition via a high-priority `RemoveMessageDecoratorPass` so the contract pass sees the absence. **Expected:** `\LogicException` matching `/X-Transport|message_decorator/`.
3. **`testCompilerPassFailsWhenAsyncParamIsInvalidValue`** — `MailerInvalidAsyncKernel` sets `tenancy.mailer.async: sometimes`. **Expected:** either `InvalidConfigurationException` (config-tree validation, runs first) or `\LogicException` (compiler pass) — both prove "invalid value rejected at build time".

All three skip cleanly when `MailerInterface` is unavailable.

## Task Commits

| # | Task | Commit | Type |
|---|------|--------|------|
| 1 | RED — failing tests for MailerTransportContractPass | `00761b8` | test |
| 1 | GREEN — MailerTransportContractPass implementation | `2d29790` | feat |
| 2 | Wire bundle config tree + 5 services in services.php | `c0749b7` | feat |
| 3 | Integration tests + pre-existing-test fixes (Autoconfig + MailerTestKernel) | `96fa2b3` | test |

## Files Created/Modified

### Created
- `src/DependencyInjection/Compiler/MailerTransportContractPass.php` — final class, 90 lines, 4 `throw new \LogicException` paths.

### Modified
- `src/TenancyBundle.php` — added `use` import for the pass, `mailer` arrayNode in `configure()`, 2 parameter `->set()` calls in `loadExtension()`, conditional `addCompilerPass(new MailerTransportContractPass())` in `build()`.
- `config/services.php` — added 5 `use` imports + 1 conditional block of 5 service registrations.
- `tests/Unit/DependencyInjection/Compiler/MailerTransportContractPassTest.php` — converted Plan 00 stub to a 10-test behavior suite covering all 7 scenarios + 3 edge cases (10 tests, 15 assertions).
- `tests/Integration/ContainerCompilationTest.php` — added 3 test methods + 3 inline test-kernel subclasses (`MailerAsyncRoutingKernel`, `MailerInvalidAsyncKernel`, `RemoveMessageDecoratorPass`).
- `tests/Integration/Mailer/MailerTestKernel.php` — added `ReplaceTenancyProviderPass` registration in `build()` so the kernel compiles without a real Doctrine EM.
- `tests/Integration/AutoconfigurationTest.php` — loosened exact-count assertions to presence-by-class (Rule 1 fix), seeded `tenancy.mailer.async` in the bypass-loadExtension test method.

## Decisions Made

- **Always-on registration with interface_exists guard (D-05):** No `tenancy.mailer.enabled` flag. Either symfony/mailer is installed (all 5 services + the compiler pass register) or it isn't (silent no-op). Mirrors the existing Messenger pattern.
- **Hard `\LogicException` on missing parameter:** Plan acceptance criterion test #9 (`testThrowsWhenAsyncParameterMissingEntirely`) explicitly demands this. The TenancyBundle's `loadExtension` always sets the parameter when called via the normal Bundle::load() path, so the only failure mode is "user constructed ContainerBuilder manually and bypassed Bundle::load()" — that path needs to seed the parameter (documented in the throw message).
- **Auto-detection via `getExtensionConfig('framework')`:** Walks the framework extension config rather than reading a derived parameter, because Symfony's `FrameworkExtension` may not have run yet at the priority the contract pass executes. Same approach Symfony uses for its own messenger.routing introspection.
- **Per-test inline kernel subclasses for failure-mode integration tests:** Keeps the kernel-specific configuration (forced async routing, invalid async value) co-located with the test class that uses it, rather than polluting MailerTestKernel with conditional logic. Same pattern as `SingleBootstrapperKernel`/`TwoBootstrappersKernel` in AutoconfigurationTest.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Worktree lacked `composer.lock` / `vendor/`**
- **Found during:** pre-flight (vendor/bin/phpunit absent).
- **Issue:** Same Rule-3 blocker documented in Plans 20-00 / 20-01 / 20-02 / 20-03.
- **Fix:** `cp ../../../composer.lock . && composer install --no-interaction`.
- **Files modified:** none committed (composer.lock + vendor/ stay gitignored).
- **Verification:** `vendor/bin/phpunit --version` returns `PHPUnit 11.5.55`; `vendor/bin/phpstan --version` returns `2.1.50`.

**2. [Rule 1 — Bug] AutoconfigurationTest exact-count assertions broke after MailerBootstrapper landed**
- **Found during:** full-suite run after Task 2 commit.
- **Issue:** `testBootstrapperInterfaceImplementationIsAutoTagged` asserted `assertCount(1, $bootstrappers)` and `testMultipleBootstrappersAreAllCollected` asserted `assertCount(2, …)`. After my services.php change adds `tenancy.mailer.bootstrapper`, the BootstrapperChain has +1 entry in every test that boots TenancyBundle with mailer installed.
- **Fix:** Loosened both assertions to presence-by-class (`assertContains(DummyBootstrapper::class, $classes)`), with comments explaining that bundle-shipped bootstrappers may also be present. Same semantic intent ("the user's bootstrapper got auto-tagged and collected"), more robust to future additions.
- **Files modified:** `tests/Integration/AutoconfigurationTest.php`
- **Verification:** Both tests pass; full suite 492 / 0 failures / 0 errors.
- **Committed in:** `96fa2b3`

**3. [Rule 3 — Blocking] AutoconfigurationTest::testBootstrapperChainDefinitionHasAddBootstrapperMethodCall bypasses Bundle::load()**
- **Found during:** full-suite run.
- **Issue:** This test constructs a `ContainerBuilder` manually and calls only `$bundle->build($container)`, NOT `$bundle->load(...)`. With the new `MailerTransportContractPass` registered in `build()` under `interface_exists(MailerInterface)`, the pass runs at compile time but the `tenancy.mailer.async` parameter is never set (because `loadExtension` was bypassed), so the pass throws.
- **Fix:** Seed the parameter inline at the same level as the other manual-build steps: `if (interface_exists(MailerInterface)) $container->setParameter('tenancy.mailer.async', 'false');`. Documented in a comment why the seed is needed.
- **Files modified:** `tests/Integration/AutoconfigurationTest.php`
- **Verification:** Test passes; the full path through `Bundle::load()` (exercised by every other integration test) still works because `loadExtension` always sets the parameter.
- **Committed in:** `96fa2b3`

**4. [Rule 3 — Blocking] MailerTestKernel could not boot because `tenancy.provider` requires Doctrine ORM EM**
- **Found during:** Task 3 first PHPUnit run on the new `testKernelCompilesWithMailerBundleConfigured`.
- **Issue:** TenancyBundle's `services.php` registers `tenancy.provider` (DoctrineTenantProvider) inside an `interface_exists(EntityManagerInterface)` block. Since doctrine/orm IS in composer require-dev, the interface exists, the service registers, but the `doctrine.orm.default_entity_manager` argument is never supplied because MailerTestKernel doesn't load DoctrineBundle.
- **Fix:** Registered `ReplaceTenancyProviderPass` in `MailerTestKernel::build()` (mirrors `tests/Integration/TestKernel` — the same fix Plan 00 considered but deferred). Plan 06+ can override later if a real StubTenantProvider is needed.
- **Files modified:** `tests/Integration/Mailer/MailerTestKernel.php`
- **Verification:** All 8 ContainerCompilationTest tests pass.
- **Committed in:** `96fa2b3`

**5. [Rule 1 — Bug] PHPStan level-9 `cast.string` on `(string) $config['mailer']['async']`**
- **Found during:** Task 2 PHPStan run.
- **Issue:** `$config` is typed `array<string, mixed>`; casting a `mixed` value with `(string)` is flagged because non-scalar values (arrays, objects) raise.
- **Fix:** Extract via temp variable + `is_scalar()` guard before cast. Same approach used inside the contract pass for the parameter value.
- **Files modified:** `src/TenancyBundle.php`
- **Verification:** `vendor/bin/phpstan analyse src/TenancyBundle.php --level=9` returns "No errors".
- **Committed in:** `c0749b7`

**6. [Rule 2 — Missing Critical] PHPStan level-9 errors in MailerTransportContractPass implementation**
- **Found during:** Task 1 GREEN PHPStan run (immediately after writing the implementation).
- **Issue:** Two level-9 errors — `cast.string` on `(string) $container->getParameter(...)` (parameter values are `mixed`) and `offsetAccess.nonOffsetAccessible` on `$config['messenger']['routing']` (the `framework` extension config tree is `mixed` at the bundle level).
- **Fix:** Added `is_scalar()` guard before string cast (with a 3rd \LogicException path for non-scalar values — actually strengthens the type check), and `is_array()` guards on the nested config lookups. No `@phpstan-ignore` annotations used; all 4 throw paths remain reachable and tested.
- **Files modified:** `src/DependencyInjection/Compiler/MailerTransportContractPass.php`
- **Verification:** PHPStan clean; all 10 unit tests still pass.
- **Committed in:** `2d29790`

**7. [Rule 1 — Bug] Pre-existing PHPStan level-9 error in AutoconfigurationTest extended by my edits**
- **Found during:** Final PHPStan sweep.
- **Issue:** The `array_map(fn (object $b) => $b::class, ...)` pattern was already present once (line 227) with a level-9 callable-type error. My loosened assertion duplicated the pattern, doubling the error count to 2.
- **Fix:** Replaced both occurrences with a typed `static fn (mixed $b): string => is_object($b) ? $b::class : get_debug_type($b)` callback. PHPStan clean.
- **Files modified:** `tests/Integration/AutoconfigurationTest.php`
- **Verification:** PHPStan level 9 returns "No errors" on AutoconfigurationTest — net `-1` errors compared to the pre-existing baseline.
- **Committed in:** `96fa2b3`

### Out-of-scope discoveries

- Pre-existing PHPStan level-9 `arguments.count` errors in `config/services.php` lines 45 + 50 (`->public(false)` two-arg call on a method whose signature is one optional arg). Confirmed pre-existing via `git stash` baseline check. Logged in `.planning/phases/20-mailer-bootstrapper/deferred-items.md` already by Plan 00.

**Total deviations:** 7 auto-fixed (3 blocking workspace/test infrastructure + 3 bug fixes + 1 missing-critical for type safety). All folded into the relevant atomic task commits — no separate refactor commits.

## Threat Surface Audit

Per the plan's `<threat_model>`:

- **T-20-04-01 (E/I — async-without-strategy → landlord fallback):** `mitigate` disposition VERIFIED. `testCompilerPassFailsWhenAsyncRoutingDetectedButStrategyAbsent` (integration, Test 2) and `testThrowsWhenAsyncIsTrueAndStrategyServiceIsAbsent` + `testAutoModeWithSendEmailRoutingThrowsWhenStrategyAbsent` (unit) collectively prove the compile-time guard fires. There is no way to ship a Symfony app with async-routed Mailer + tenancy bundle + missing strategy through `bin/console cache:clear`.
- **T-20-04-02 (T — service ID drift):** `mitigate` VERIFIED. The pass exposes `X_TRANSPORT_SERVICE = 'tenancy.mailer.message_decorator'` as a class constant; services.php registers the service with that exact ID. Acceptance grep confirms both files contain the string. Future refactors that rename one side will fail the grep step in this plan.
- **T-20-04-03 (D — invalid async value → undefined behavior):** `mitigate` VERIFIED two-layer. The config tree's `ifNotInArray(['auto', 'true', 'false'])` rejects bad values at extension load. The compiler pass independently rejects unknown values in its match (4th `throw new \LogicException`). `testCompilerPassFailsWhenAsyncParamIsInvalidValue` accepts either exception type — both prove the misconfig is caught before the kernel finishes booting.
- **T-20-04-04 (I — internal service ID in error message):** `accept` disposition CONFIRMED. The error message references `tenancy.mailer.message_decorator` to help users diagnose; not a credential leak. Bundle-internal nomenclature only.

No `threat_flag` entries to add. No new threat surface introduced beyond what `<threat_model>` enumerated.

## TDD Gate Compliance

Plan is `type: execute` but Task 1 declared `tdd="true"` — RED+GREEN gate sequence verified:

| Task | RED commit | GREEN commit | Gate order |
|------|------------|--------------|------------|
| 1    | `00761b8`  | `2d29790`    | RED → GREEN |
| 2    | — (no tdd flag) | `c0749b7` | feat (covered by Task 3 integration tests) |
| 3    | — (no tdd flag) | `96fa2b3` | test (verifies Task 2 wiring + adds 3 new integration tests) |

No REFACTOR commits required.

## Validation Compliance

- ✅ `src/DependencyInjection/Compiler/MailerTransportContractPass.php` exists with `final class MailerTransportContractPass implements CompilerPassInterface` (1 occurrence), `interface_exists(\Symfony\Component\Mailer\MailerInterface::class)` early-return guard (1 occurrence), `getExtensionConfig('framework')` walk (1 occurrence), 4 `throw new \LogicException` paths (≥ 3 required by acceptance criteria).
- ✅ `src/TenancyBundle.php` declares the `mailer` arrayNode with `transport_cache_size` int default 32 + `async` scalar default 'auto' with `ifNotInArray(['auto', 'true', 'false'])` validation; loadExtension sets both parameters; build() registers the compiler pass under the MailerInterface guard.
- ✅ `config/services.php` registers all 5 mailer services under `interface_exists(MailerInterface)` block; decorates `mailer.transports` with `@event_dispatcher` 5th arg (RESEARCH Q2 RESOLVED); decorates `mailer` with SanitizingMailerDecorator; tags MailerBootstrapper at priority -20; service ID `tenancy.mailer.message_decorator` matches the contract pass constant.
- ✅ 3 new integration tests in `ContainerCompilationTest` — each MailerInterface-guarded; happy-path asserts 5 service IDs + 2 parameters; both failure-mode tests assert exception messages.
- ✅ `vendor/bin/phpunit --testsuite unit` → 381 tests, 1010 assertions, 0 failures, 0 errors.
- ✅ `vendor/bin/phpunit` full suite → 492 tests, 1323 assertions, 0 failures, 0 errors (2 incomplete are pre-existing Wave-0 stubs for Plans 05/06/07/08).
- ✅ `vendor/bin/phpstan analyse … --level=9 --memory-limit=512M` clean across all 6 touched files.
- ✅ `vendor/bin/php-cs-fixer check` clean on all 6 touched files.

## Next Plan Readiness

- **Plan 20-05 (Profiler):** `TenantDataCollector::collectMailerState()` can read `tenancy.mailer.lru_cache` directly (it's registered + visible via the MakeMailerServicesPublicPass for tests). The `tenancy.mailer.transport_cache_size` parameter is available for the "max" display.
- **Plan 20-06 (Async canary):** `MailerTestKernel` now boots cleanly (ReplaceTenancyProviderPass landed) and exposes all 5 mailer services publicly via `MakeMailerServicesPublicPass`. The async canary can dispatch a `SendEmailMessage` and assert the worker routes through `TenantAwareTransportsDecorator`.
- **Plan 20-07 (additional compile-time guards / Mailer test kernel polish):** the contract pass is already shipped; Plan 07 may add additional misconfig scenarios (e.g. JSON Serializer detection per RESEARCH Pitfall 2) by extending the same pass.
- **Plan 20-08 (docs / UPGRADE.md):** can quote the verbatim config tree from this SUMMARY's "Mailer Config Tree" section. Public class constants `X_TRANSPORT_SERVICE` + `ASYNC_PARAM` are documented above.

No blockers for downstream waves.

## Self-Check: PASSED

Verified all 7 created/modified files exist on disk and all 4 task commits are present in git log:

```
$ git log --oneline 4e740d57a4bb06bafe5f70735e45620a907da786..HEAD
96fa2b3 test(20-04): integration coverage for mailer DI wiring + compile-time guard
c0749b7 feat(20-04): wire mailer config tree + 5 services in DI container
2d29790 feat(20-04): add MailerTransportContractPass compile-time guard
00761b8 test(20-04): add failing tests for MailerTransportContractPass
```

Verified files:
- `src/DependencyInjection/Compiler/MailerTransportContractPass.php` — FOUND
- `src/TenancyBundle.php` — MODIFIED (arrayNode + parameters + compiler pass registration)
- `config/services.php` — MODIFIED (5 service registrations + 5 use imports)
- `tests/Unit/DependencyInjection/Compiler/MailerTransportContractPassTest.php` — MODIFIED (10 tests, 15 assertions)
- `tests/Integration/ContainerCompilationTest.php` — MODIFIED (3 new test methods + 3 test-kernel subclasses)
- `tests/Integration/Mailer/MailerTestKernel.php` — MODIFIED (ReplaceTenancyProviderPass added)
- `tests/Integration/AutoconfigurationTest.php` — MODIFIED (presence-by-class assertions + seed parameter)

---
*Phase: 20-mailer-bootstrapper*
*Plan: 04*
*Completed: 2026-05-20*
