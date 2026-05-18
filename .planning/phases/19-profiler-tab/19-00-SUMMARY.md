---
phase: 19-profiler-tab
plan: 00
subsystem: dev-infrastructure
tags: [profiler, test-kernel, dependencies, wave-0]
one_liner: "Wave 0 scaffolding for Phase 19 — dev deps (web-profiler-bundle, twig-bundle), profiler test directories, and ProfilerTestKernel ready for Plans 01–06"
status: complete
completed: 2026-05-18T19:37:45Z
duration_minutes: 7
task_count: 4
requirements: [DX-02]
dependency_graph:
  requires: []
  provides:
    - "symfony/web-profiler-bundle v8.0.11 (dev) — available for autoload in tests"
    - "symfony/twig-bundle v8.0.8 (dev) — available for autoload in tests"
    - "tests/Unit/Profiler/ directory tracked via .gitkeep"
    - "tests/Integration/Profiler/ directory tracked via .gitkeep"
    - "Tenancy\\Bundle\\Tests\\Integration\\Profiler\\Support\\ProfilerTestKernel autoloadable"
    - "TestKernel boots in non-test environments via cache.adapter.array"
  affects:
    - "Plans 01, 02, 03, 04 unit tests gain tests/Unit/Profiler home"
    - "Plan 05 (compile-out test) can boot TestKernel('prod', false) without filesystem cache failures"
    - "Plan 06 (WDT functional test) can boot ProfilerTestKernel and override loadRoutes"
tech_stack:
  added:
    - "symfony/web-profiler-bundle ^7.4||^8.0 (dev-only)"
    - "symfony/twig-bundle ^7.4||^8.0 (dev-only)"
  patterns:
    - "Test kernel shape mirror — same registerBundles+build+registerContainerConfiguration+getCacheDir pattern as TestKernel.php"
    - "Cache dir keyed by static::class + environment + debug flag — prevents compiled-container collisions across kernel variants"
    - ".gitkeep sentinels track empty directories before tests populate them"
key_files:
  created:
    - "tests/Integration/Profiler/Support/ProfilerTestKernel.php (83 lines)"
    - "tests/Unit/Profiler/.gitkeep"
    - "tests/Integration/Profiler/.gitkeep"
  modified:
    - "composer.json (require-dev: +2 entries, alphabetical)"
    - "tests/Integration/TestKernel.php (+1 line: cache.adapter.array)"
decisions:
  - "composer.lock is gitignored (library convention) — only composer.json is committed; dependencies regenerated on each install"
  - "ProfilerTestKernel defaults to debug=true (vs TestKernel's debug=false) because the profiler service graph is realized only in debug mode"
  - "Cache dir keying includes the debug flag suffix (debug|nodebug) to allow Plan 05 and Plan 06 to coexist without compiled-container collision"
  - "TestKernel preemptive harden uses cache.adapter.array (in-memory) — invisible to existing tests, removes CI failure mode for Plan 05 prod-env boot"
metrics:
  duration_minutes: 7
  completed_date: 2026-05-18
  test_count_before: 383
  test_count_after: 383
  test_assertions: 1017
  composer_lock_content_hash: "e04f974c23072ea54538566581386a2b"
---

# Phase 19 Plan 00: Wave 0 Setup Summary

## One-liner

Wave 0 scaffolding complete — dev dependencies for the Symfony Profiler tab (`symfony/web-profiler-bundle` v8.0.11, `symfony/twig-bundle` v8.0.8) added to `require-dev`, two new test directories (`tests/Unit/Profiler`, `tests/Integration/Profiler`) tracked via `.gitkeep`, and a dedicated `ProfilerTestKernel` stood up for Plan 06's WDT functional test. `TestKernel.php` preemptively hardened with `cache.adapter.array` so Plan 05's `prod`-env compile-out boot will not fail in CI.

## What Shipped

### 1. Dev dependencies — `composer.json` (Task 00-01)

Added to `require-dev` (alphabetical position):

```json
"symfony/twig-bundle": "^7.4||^8.0",
"symfony/web-profiler-bundle": "^7.4||^8.0"
```

Resolved versions (local install on this worktree):
- `symfony/web-profiler-bundle` → `v8.0.11`
- `symfony/twig-bundle` → `v8.0.8`

`composer.lock` content-hash after install: `e04f974c23072ea54538566581386a2b`

**Note on lock file:** `composer.lock` is gitignored at the repo root (line-of-business for a published bundle — see `.gitignore` line 7). The plan's `files_modified` frontmatter listed it, but per project convention only `composer.json` is committed. Lock is regenerated on every install. Plans 01–06 can rely on `composer install` materializing the two new packages.

### 2. Test directory tree (Task 00-02)

```
tests/
├── Unit/
│   └── Profiler/
│       └── .gitkeep
└── Integration/
    └── Profiler/
        ├── .gitkeep
        └── Support/
            └── ProfilerTestKernel.php  (Task 00-03)
```

`phpunit.xml.dist` testsuite definitions use recursive `<directory>tests/Unit</directory>` and `<directory>tests/Integration</directory>` — no config change needed. `vendor/bin/phpunit --list-suites` still lists `integration (92 tests)` and `unit (291 tests)` clean.

### 3. `ProfilerTestKernel` (Task 00-03)

`tests/Integration/Profiler/Support/ProfilerTestKernel.php` — `final class` extending `Symfony\Component\HttpKernel\Kernel`. Mirrors the shape of `tests/Integration/TestKernel.php` with three extra bundles and three extra extension configs.

**Bundles registered:** FrameworkBundle, TwigBundle, WebProfilerBundle, TenancyBundle (in that order — TwigBundle must come before WebProfilerBundle).

**Default constructor:** `('test', true)` — debug=TRUE is the new default (the profiler service graph is realized only in debug mode).

**Cache dir convention:**

```
sys_get_temp_dir() . '/tenancy_bundle_profiler_test_' . md5(static::class) . '_' . $env . '_' . ($debug ? 'debug' : 'nodebug') . '/cache'
```

The `debug|nodebug` suffix is the key innovation — Plan 05's compile-out test will boot the kernel with `('prod', false)` and Plan 06's WDT test with `('test', true)`. The suffix prevents the two boots from sharing a compiled container (different bundle graphs).

**Extension configs:**

- `framework`: standard test config + `profiler: {enabled: true, collect: true}` + `router` pointing at `kernel::loadRoutes` service so Plan 06 can override `loadRoutes` via an anonymous subclass.
- `twig`: `default_path` points at `templates/` (intentionally empty — WebProfilerBundle ships its own templates).
- `web_profiler`: `toolbar: true, intercept_redirects: false`.

**Compiler pass:** `ReplaceTenancyProviderPass` (same as `TestKernel`) stubs `tenancy.provider` so the kernel can boot without a real database.

PHPStan level 9: clean. `php -l`: clean.

### 4. `TestKernel.php` preemptive harden (Task 00-04)

Added one line inside the existing `framework` extension config:

```php
'cache' => ['app' => 'cache.adapter.array'],
```

**Rationale:** Plan 05 Task 05-01 will boot `new TestKernel('prod', false)` to verify the compile-out behavior of profiler services. In `prod` env, Symfony's FrameworkBundle resolves `cache.app` to a real backend (filesystem by default), which can fail on CI runners or when `kernel.cache_dir` is shared across parallel test workers. `cache.adapter.array` is an in-memory adapter that never touches disk — guaranteed safe.

Diff is exactly one line. All 92 existing integration tests pass. Full suite: **383 tests, 1017 assertions, 0 failures.**

## What Plans 01–06 Can Now Assume

- **Plan 01–02 (Twig extension + DataCollector unit tests):** They will not touch the kernel; their stub-based unit tests live under `tests/Unit/Profiler/`. Directory exists.
- **Plan 03–04 (compiler pass + DI wiring unit tests):** Same — `tests/Unit/Profiler/` available.
- **Plan 05 (compile-out integration test):** Can boot `new TestKernel('prod', false)` without filesystem cache failure. Profiler services should be absent from the container in `kernel.debug=false` mode.
- **Plan 06 (WDT functional test):** Can extend `ProfilerTestKernel` via anonymous subclass to add a `/test-tenant` route via `loadRoutes()`. The kernel boots with WebProfilerBundle + TwigBundle, debug=true, ready to render the WDT.

## Deviations from Plan

### Plan vs. Reality: composer.lock

The plan's `files_modified` frontmatter listed `composer.lock` and several verification steps assumed it would be committed. However, `.gitignore` line 7 explicitly excludes `composer.lock` (standard library/bundle convention — only application repos commit it). Per CLAUDE.md and the project's audit history, this is intentional.

**Action taken:** Modified composer.json was committed; composer.lock was generated locally (for `composer show` verification) but not staged. Every downstream plan must `composer install` before running tests. This is already documented in the project's developer onboarding (DX-04, DX-05) and CI workflows.

**Not a Rule 4 architectural change** — just a documentation note for the orchestrator. The plan's intent (lock the dev deps) is honored: composer.json pinned to `^7.4||^8.0` and the lock file in CI will pick the same versions.

### Auto-fix: stale /tmp test kernel caches from sibling worktree

When running the integration suite after Task 00-04, PHPUnit hit a fatal `Cannot redeclare class TestProduct` error — the cached compiled containers in `/var/folders/.../T/tenancy_*` referenced absolute file paths from the main repo, conflicting with this worktree's copy of the same test entity. This is a pre-existing artifact of `getCacheDir()` keying only by `static::class` + `$environment` (not by repo path).

**Auto-fix (Rule 3 — blocking):** Cleared `/tmp/tenancy_*` caches with `find ... -exec rm -rf {} +`. Integration suite then passed clean (92/92, 219 assertions). Out of scope for this plan to fix the keying — `getCacheDir()` change would risk every existing test kernel. Logged here for future reference.

**Files modified:** none (cache cleanup only).
**Commit:** none (no code change).

## Verification Results

| Check | Result |
|---|---|
| `composer validate --strict --no-check-publish` | PASS |
| `composer show symfony/web-profiler-bundle` | PASS (v8.0.11) |
| `composer show symfony/twig-bundle` | PASS (v8.0.8) |
| `jq '.require \| has("symfony/web-profiler-bundle")'` | `false` (dev-only as required by threat T-19-W0-01) |
| `php -l tests/Integration/Profiler/Support/ProfilerTestKernel.php` | PASS |
| `vendor/bin/phpstan analyse ... --level=9` | PASS (No errors) |
| `vendor/bin/phpunit --list-suites` | PASS (unit 291, integration 92) |
| Full suite `vendor/bin/phpunit` | **383 tests, 1017 assertions, 0 failures** |

## Commits

| Task | Commit | Message |
|------|--------|---------|
| 00-01 | `8652b82` | `chore(19-00): add web-profiler-bundle and twig-bundle to require-dev` |
| 00-02 | `eadcd25` | `chore(19-00): scaffold tests/Unit/Profiler and tests/Integration/Profiler directories` |
| 00-03 | `2af0578` | `test(19-00): add ProfilerTestKernel for WDT integration tests` |
| 00-04 | `4ee94f8` | `test(19-00): preemptively harden TestKernel with cache.adapter.array` |

## Threat Surface Scan

Threat register from PLAN.md:

| Threat ID | Disposition | Status |
|-----------|-------------|--------|
| T-19-W0-01 (Tampering — composer.json) | mitigate | Mitigated. `jq '.require \| has("symfony/web-profiler-bundle")'` returns `false`; both packages live exclusively in `require-dev`. |
| T-19-W0-02 (Info Disclosure — ProfilerTestKernel) | accept | Accepted. Cache dir under `sys_get_temp_dir()`, no real tenants, no DSNs, no secrets touch the kernel. |

No new threat surface introduced. No `threat_flag` entries.

## Self-Check: PASSED

- File `composer.json` modified — FOUND
- File `tests/Unit/Profiler/.gitkeep` exists — FOUND
- File `tests/Integration/Profiler/.gitkeep` exists — FOUND
- File `tests/Integration/Profiler/Support/ProfilerTestKernel.php` exists — FOUND
- File `tests/Integration/TestKernel.php` modified (+1 line) — FOUND
- Commit `8652b82` (Task 00-01) — FOUND in `git log`
- Commit `eadcd25` (Task 00-02) — FOUND in `git log`
- Commit `2af0578` (Task 00-03) — FOUND in `git log`
- Commit `4ee94f8` (Task 00-04) — FOUND in `git log`
