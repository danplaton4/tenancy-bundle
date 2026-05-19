---
phase: 19-profiler-tab
fixed_at: 2026-05-19T00:00:00Z
review_path: .planning/phases/19-profiler-tab/19-REVIEW.md
iteration: 1
findings_in_scope: 6
fixed: 6
skipped: 0
status: all_fixed
---

# Phase 19: Code Review Fix Report

**Fixed at:** 2026-05-19
**Source review:** `.planning/phases/19-profiler-tab/19-REVIEW.md`
**Iteration:** 1

**Summary:**
- Findings in scope (critical + warning): 6
- Fixed: 6
- Skipped: 0
- Full suite green after each fix: 419 tests / 1143 assertions
- PHPStan level 9 green after each fix: `[OK] No errors`

## Fixed Issues

### CR-01: `getPath()` override changes bundle root in production, not just dev

**Files modified:** `src/TenancyBundle.php`
**Commit:** `9775af1`
**Applied fix:** Removed the unconditional `TenancyBundle::getPath(): string { return __DIR__; }` override (and its 14-line docblock). Registered the `@Tenancy` Twig namespace explicitly inside `prependExtension()` via `$builder->prependExtensionConfig('twig', ['paths' => [__DIR__.'/Resources/views' => 'Tenancy']])`, guarded by `$builder->hasExtension('twig')`. Confirmed `tests/Integration/Profiler/TenantDataCollectorWdtTest.php` (4 tests / 42 assertions) still resolves `@Tenancy/Collector/tenant.html.twig` through the new namespace registration.

### WR-01: `loadExtension()` `kernel.debug` check throws if the parameter is undefined

**Files modified:** `src/TenancyBundle.php`
**Commit:** `e05c630`
**Applied fix:** Replaced `if (true === $builder->getParameter('kernel.debug'))` with `if ($builder->hasParameter('kernel.debug') && (bool) $builder->getParameter('kernel.debug'))`. The `hasParameter()` guard avoids `ParameterNotFoundException` in stripped kernels; the `(bool)` cast tolerates string `'true'` from XML configs.

### WR-02: `TenantDataCollector` missing runtime validation of `$driver`

**Files modified:** `src/Profiler/TenantDataCollector.php`
**Commit:** `5778e36`
**Applied fix:** Added a `KNOWN_DRIVERS = ['database_per_tenant', 'shared_db']` class constant and a constructor-time `\in_array($driver, self::KNOWN_DRIVERS, true)` check that throws `\InvalidArgumentException` for unknown driver strings. Matches CLAUDE.md's strict-mode / security-incident posture — fail loudly at container-build time rather than silently rendering `-` in the profiler panel.

### WR-03: Stash captures bootstrapper FQCN list verbatim — no scalar-string assertion

**Files modified:** `src/Profiler/TenantProfilerStash.php`
**Commit:** `b02dd0d`
**Applied fix:** Changed `onTenantBootstrapped()` to coerce on capture: `$this->bootstrapperFqcns = array_values(array_map('strval', $event->bootstrappers))`. The `@var string[]` invariant now holds at the boundary; the collector's existing belt-and-braces coercion in `collect()` was left in place intentionally.

### WR-04: `tenant.html.twig` crashes if `resolved_by` is null

**Files modified:** `src/Resources/views/Collector/tenant.html.twig`
**Commit:** `313bf1a`
**Applied fix:** Wrapped the toolbar "Resolved by" tooltip in `{% if collector.data.resolved_by %}…{% else %}-{% endif %}` so the `|split('\\')|last` chain is no longer invoked on `null` under `strict_variables: true`.

### WR-05: WDT test kernel cache dir does not isolate per-PID

**Files modified:** `tests/Integration/Profiler/Support/ProfilerTestKernel.php`
**Commit:** `4861baf`
**Applied fix:** Reworked `getCacheDir()` and `getLogDir()` to hash `getmypid()` into the cache-dir key (`md5(static::class.'_'.getmypid())`). Parallel phpunit/paratest workers now get isolated cache dumps, eliminating the truncation race.

## Skipped Issues

None — all six in-scope findings were applied.

## Verification

After all six fixes:
- `vendor/bin/phpunit` → `OK (419 tests, 1143 assertions)`
- `vendor/bin/phpstan analyse --no-progress --memory-limit=512M` → `[OK] No errors`
- `vendor/bin/phpunit tests/Integration/Profiler/TenantDataCollectorWdtTest.php` → `OK (4 tests, 42 assertions)` (CR-01 gate)

All `fix(19): …` commits use `--no-verify` per the loop's fast-iteration constraint. Hooks will run on the orchestrator's final commit.

## Out-of-scope (deferred)

Per the iteration scope, the four INFO findings (IN-01 comment density, IN-02 unused `$event` param, IN-03 `assert()` in `getData()`, IN-04 `->public()` test convenience) were not addressed in this pass. They remain documented in `19-REVIEW.md` for follow-up if/when the team chooses to revisit.

---

_Fixed: 2026-05-19_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
