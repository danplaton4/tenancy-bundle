---
phase: 19-profiler-tab
plan: 04
subsystem: infra
tags: [symfony, dependency-injection, profiler, web-debug-toolbar, kernel-debug, autoconfigure]

# Dependency graph
requires:
  - phase: 19-profiler-tab
    provides: TenantProfilerStash (Plan 01), TenantDataCollector (Plan 02)
provides:
  - Dev-only DI registration for TenantProfilerStash + TenantDataCollector
  - Conditional services_dev.php import in TenancyBundle::loadExtension() gated by kernel.debug
  - Compile-out guarantee for production containers (profiler services absent when debug=false)
affects: [19-05 (compile-out integration test + source-layout test), 19-06 (functional WDT test rendering the panel)]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Dev-only DI split: services_dev.php gated by ContainerBuilder->getParameter('kernel.debug')"
    - "Autoconfigure + explicit ->tag for data_collector tag id/template attributes"

key-files:
  created:
    - config/services_dev.php
  modified:
    - src/TenancyBundle.php

key-decisions:
  - "Use $builder->getParameter('kernel.debug') in loadExtension() — ContainerConfigurator has no getParameter() (RESEARCH Pitfall 1)"
  - "Yoda-style strict comparison (true === ...) per @Symfony cs-fixer ruleset"
  - "Both services ->public() so Plan 05's compile-out test can use $container->has(...)/get(...) without introspection"
  - "Explicit ->tag('data_collector', ['id' => 'tenancy', 'template' => '@Tenancy/...']) — autoconfigure cannot supply tag attributes"
  - "Guard sits in loadExtension(), not build() — D-06 explicitly rejects a dedicated compiler pass"
  - "No class_exists(WebProfilerBundle::class) check needed — kernel.debug is the singular guard"

patterns-established:
  - "Production DI (config/services.php) MUST NOT reference dev-only services — Plan 05 SourceLayoutTest enforces"
  - "Dev-only configurators live in config/services_dev.php and are imported conditionally"

requirements-completed: [DX-02]

# Metrics
duration: 20min
completed: 2026-05-19
---

# Phase 19 Plan 04: Profiler DI Wiring Summary

**Dev-only Symfony DI registration of TenantProfilerStash and TenantDataCollector via a new config/services_dev.php, imported by TenancyBundle::loadExtension() only when kernel.debug=true.**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-05-19T06:11:00Z
- **Completed:** 2026-05-19T06:31:42Z
- **Tasks:** 3
- **Files modified:** 2 (1 created, 1 edited)

## Accomplishments
- Created `config/services_dev.php` (53 lines) registering both profiler services as public, autoconfigured, with explicit `data_collector` tag attributes (`id='tenancy'`, `template='@Tenancy/Collector/tenant.html.twig'`).
- Added a 4-line conditional import in `TenancyBundle::loadExtension()` that pulls in services_dev.php only when `kernel.debug === true`.
- Verified `config/services.php` is bit-for-bit identical to its pre-Plan-04 state (T-19-02 architectural rule preserved).

## Task Commits

Each task was committed atomically (`--no-verify` per parallel-executor protocol):

1. **Task 04-01: Create config/services_dev.php** — `507e028` (feat)
2. **Task 04-02: Wire conditional import into TenancyBundle::loadExtension()** — `81367a8` (feat)
3. **Task 04-03: Verify config/services.php untouched** — `b156c8d` (chore, empty commit recording the gate)

## Files Created/Modified

### Created
- `config/services_dev.php` — Dev-only DI configurator for the Profiler stash + data collector. Imported only when `kernel.debug=true`.

### Modified
- `src/TenancyBundle.php` — Added 4-line conditional import block in `loadExtension()` immediately after the existing `services.php` import.

### Verbatim diff for src/TenancyBundle.php

```diff
@@ -102,6 +102,10 @@ class TenancyBundle extends AbstractBundle
     {
         $container->import('../config/services.php');

+        if (true === $builder->getParameter('kernel.debug')) {
+            $container->import('../config/services_dev.php');
+        }
+
         $builder->registerForAutoconfiguration(TenantBootstrapperInterface::class)
             ->addTag('tenancy.bootstrapper');
```

### Full content of config/services_dev.php

```php
<?php

declare(strict_types=1);

/*
 * Dev-only DI registration for the Tenancy Profiler tab.
 *
 * This file is imported by TenancyBundle::loadExtension() ONLY when
 * $builder->getParameter('kernel.debug') === true. Production containers
 * (debug=false) never see these services — verified by
 * tests/Integration/Profiler/TenantDataCollectorCompileOutTest.php.
 *
 * Phase 19 — Profiler Tab — requirement DX-02.
 */

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Tenancy\Bundle\Profiler\TenantDataCollector;
use Tenancy\Bundle\Profiler\TenantProfilerStash;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Stash — captures resolver FQCN, bootstrapper FQCN list, and tenancy exceptions from event time.
    // Zero constructor args. Autoconfigure picks up:
    //   - 4 #[AsEventListener] attributes → kernel event listener registrations
    //   - implements ResetInterface → `kernel.reset` tag (between-request cleanup in long-running runtimes)
    $services->set(TenantProfilerStash::class)
        ->autoconfigure(true)
        ->public();

    // Data collector — reads stash + TenantContext + driver/landlord params on kernel.response.
    // Autoconfigure adds the `data_collector` tag because TenantDataCollector extends AbstractDataCollector
    // (which implements DataCollectorInterface), BUT autoconfigure cannot supply the tag's `id` and
    // `template` attributes — those must be explicit. The explicit ->tag(...) call below adds them
    // alongside the autoconfigured tag (Symfony merges tag attributes when both autoconfigure and
    // explicit tag are present).
    $services->set(TenantDataCollector::class)
        ->autoconfigure(true)
        ->public()
        ->args([
            service(TenantProfilerStash::class),
            service('tenancy.context'),
            param('tenancy.driver'),
            param('tenancy.landlord_connection'),
        ])
        ->tag('data_collector', [
            'id' => 'tenancy',
            'template' => '@Tenancy/Collector/tenant.html.twig',
        ]);
};
```

### Canonical service IDs

- `Tenancy\Bundle\Profiler\TenantProfilerStash` (class-name service ID; public)
- `Tenancy\Bundle\Profiler\TenantDataCollector` (class-name service ID; public)

### `data_collector` tag attributes (locked by DX-02)

| Attribute | Value                                  |
| --------- | -------------------------------------- |
| `id`      | `tenancy`                              |
| `template`| `@Tenancy/Collector/tenant.html.twig` |

### Production-config integrity

`config/services.php` is **untouched** by this plan. Verified by:
- `grep -c 'TenantProfilerStash\|TenantDataCollector\|Tenancy\\Bundle\\Profiler' config/services.php` → `0`
- `grep -c 'services_dev' config/services.php` → `0`
- `git diff e14bff3 -- config/services.php` → empty
- `git diff config/services.php` (vs HEAD) → empty

## Decisions Made
- **Yoda comparison `true === $builder->getParameter('kernel.debug')`** — required by `@Symfony` cs-fixer ruleset. Semantics preserved (strict equality; only literal `true` from the framework Kernel passes).
- Followed plan exactly otherwise.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Style/Critical] Yoda comparison applied to kernel.debug guard**
- **Found during:** Task 04-02 (post-edit php-cs-fixer dry-run)
- **Issue:** Plan specified `$builder->getParameter('kernel.debug') === true`, but the project's `.php-cs-fixer.dist.php` enforces `@Symfony` which mandates Yoda-style comparisons (`true === ...`).
- **Fix:** Swapped operand order to `true === $builder->getParameter('kernel.debug')`. Strict-equality semantics are preserved.
- **Files modified:** `src/TenancyBundle.php`
- **Verification:** `vendor/bin/php-cs-fixer fix src/TenancyBundle.php --diff --dry-run` reports zero fixable files; PHPStan level 9 still clean; 313 unit tests still pass.
- **Committed in:** `81367a8` (Task 04-02 commit, already includes the Yoda form)

---

**Total deviations:** 1 auto-fixed (Rule 2 — project coding-style mandate).
**Impact on plan:** Zero — the guard is functionally identical; the only change is operand order to satisfy CLAUDE.md's stated code-style rules.

## Issues Encountered

- The worktree initially had no `vendor/` directory; symlinked `vendor` to the main repo's installed dependencies so `vendor/bin/phpstan`, `vendor/bin/phpunit`, and `vendor/bin/php-cs-fixer` could run. Symlink is not committed (untracked link, ignored).

## Verification Results

All Plan-19-04 `<verification>` checks pass:

- `php -l config/services_dev.php` → exit 0 ✓
- `php -l src/TenancyBundle.php` → exit 0 ✓
- `vendor/bin/phpunit --testsuite unit` → 313 tests, 838 assertions, OK ✓
- `vendor/bin/phpstan analyse src/TenancyBundle.php src/Profiler config/services_dev.php --level=9` → No errors ✓
- `vendor/bin/php-cs-fixer fix src/TenancyBundle.php --diff --dry-run` → clean ✓
- `grep -c 'kernel.debug' src/TenancyBundle.php` → 1 (singular guard) ✓
- `git diff config/services.php` → empty ✓
- `grep -c 'TenantProfilerStash\|TenantDataCollector' config/services.php` → 0 ✓

## User Setup Required

None — purely DI/config wiring; no environment variables or dashboard changes.

## Next Phase Readiness

Plans 19-05 (compile-out + source-layout tests) and 19-06 (functional WDT test) can now:
1. Boot a kernel with `debug=true` and assert `$container->has(TenantDataCollector::class) === true`.
2. Boot a kernel with `debug=false` and assert `$container->has(TenantDataCollector::class) === false` AND `$container->has(TenantProfilerStash::class) === false`.
3. Grep `config/services.php` for profiler refs and assert zero — automated mirror of Task 04-03.

## Self-Check: PASSED

- `config/services_dev.php` exists ✓
- `src/TenancyBundle.php` modified (conditional import block present) ✓
- Commit `507e028` exists ✓
- Commit `81367a8` exists ✓
- Commit `b156c8d` exists ✓

---
*Phase: 19-profiler-tab*
*Completed: 2026-05-19*
