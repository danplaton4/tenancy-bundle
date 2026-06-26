---
phase: 31-parallel-migrations
plan: 02
subsystem: cli
tags: [symfony-console, parallel, migrations, doctrine-migrations, json-output, dry-run]

# Dependency graph
requires:
  - phase: 31-parallel-migrations
    plan: 01
    provides: ParallelMigrationRunner service + ParallelMigrationResult DTO (run() contract)
provides:
  - TenantMigrateCommand: --parallel/--concurrency/--dry-run/--format flags with [1,32] clamp,
    shared_db guard-first ordering, delegation to ParallelMigrationRunner, human table + JSON
    aggregate output, FAILURE-if-any-failed exit code in both modes
  - TenancyBundle.php: imperative DI registration of tenancy.command.migrate.parallel_runner
    inside the class_exists(DependencyFactory) block; wired as 7th arg to migrate command
  - TenantMigrateCommandParallelTest: 10 unit tests covering SC1-SC5 + Discretion + D-07
  - TenantMigrateCommandParallelIntegrationTest: 5 integration tests proving container wiring
    + 7-arg registration integrity
affects:
  - 31-03: (none — phase 31 has 2 plans total; this is the last plan)
  - 34 (DOC-21): the operator-facing docs for --parallel/--concurrency/--dry-run/--format land here

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Guard-first ordering: shared_db guard runs BEFORE any parallel branch (D-06/SC4/T-31-05)"
    - "Nullable 7th arg for BC: ?ParallelMigrationRunner default null — existing 6-arg tests unchanged"
    - "emitBlocks flag bridge: command passes 'json'!==\$format to runner so runner is silent in JSON mode"
    - "array_values() before list<T> param: ensures non-empty-array<T> satisfies list<T> for PHPStan L9"
    - "CommandTester capture_stderr_separately: required for getErrorOutput() in clamp notice tests"
    - "Integration test: DI optimizer inlines single-use private services; wiring proven via reflection not get()"

key-files:
  created:
    - tests/Unit/Command/TenantMigrateCommandParallelTest.php
    - tests/Integration/Command/TenantMigrateCommandParallelIntegrationTest.php
  modified:
    - src/Command/TenantMigrateCommand.php
    - src/TenancyBundle.php
    - tests/Integration/Command/Support/MakeCommandsPublicPass.php

key-decisions:
  - "Nullable runner with default null: existing TenantMigrateCommandTest constructs the command with 6 args and must continue to work (SC1 BC constraint)"
  - "is_string() guard before is_numeric() for --concurrency: PHPStan L9 infers getOption() returns mixed; is_string() narrows it before the cast.string operation"
  - "ParallelMigrationRunner is final — no subclass spy possible for concurrency capture; tested via stderr clamp notice (observable behavior) + runner unit tests covering at-most-N invariant"
  - "Integration test uses reflection not container get() for runner: Symfony DI optimizer inlines single-use private services; container.get('tenancy.command.migrate.parallel_runner') throws ServiceNotFoundException after inlining"

patterns-established:
  - "Same-block imperative DI: plain service + command registered in same class_exists() block — parallel_runner registered BEFORE the migrate command that uses it"
  - "Separation of command-layer and runner-layer concerns: command owns clamp + guard + rendering; runner owns pool + per-tenant output + exit-code aggregation"

requirements-completed: [ISOL-07, ISOL-08, ISOL-09, ISOL-10, ISOL-11, ISOL-12]

# Metrics
duration: 22min
completed: 2026-06-26
---

# Phase 31 Plan 02: Command-Layer Parallel Surface Summary

**Operator-facing `--parallel`/`--concurrency`/`--dry-run`/`--format=json` flags on `tenancy:migrate`, wired to the plan-01 runner via a nullable 7th constructor arg, with [1,32] concurrency clamp, guard-first shared_db refusal, human summary table, and a single aggregate JSON document — SC1-SC5 green.**

## Performance

- **Duration:** ~22 min
- **Started:** 2026-06-26T08:30:00Z
- **Completed:** 2026-06-26T08:52:22Z
- **Tasks:** 3
- **Files modified:** 5 (2 modified, 3 created)

## Accomplishments

- `TenantMigrateCommand` extended with four new options; existing sequential foreach is byte-identical to v0.4 when `--parallel` is absent (SC1)
- `--concurrency` clamped to [1,32] at command layer before reaching the runner; >32 → clamp + stderr notice; non-string/<1 → `Command::INVALID` (ISOL-08/SC2)
- `--parallel` under `shared_db` hits the existing guard FIRST, returns `FAILURE` before any subprocess can be spawned (T-31-05/SC4/ISOL-11)
- `--format=json` routes through `run(..., emitBlocks=false)` and emits exactly one aggregate JSON object `{tenants:[...],summary:{...}}` to stdout; human table suppressed; operational notices stay on stderr (D-03/D-04/SC5/ISOL-12)
- `--dry-run` threads through both modes (sequential: compute plan without calling `migrate()`; parallel: forwarded to each child via runner, already proven in plan-01 tests) — D-05/ISOL-10
- `ParallelMigrationRunner` registered imperatively in `TenancyBundle.php` inside the `class_exists(DependencyFactory)` block; structurally absent under `shared_db` (Pitfall 16/D-06)
- 15 new tests (10 unit + 5 integration) + 7 existing migrate tests all green; PHPStan L9 clean; php-cs-fixer @Symfony clean; pre-commit suite (793 tests) green

## Task Commits

1. **Task 1: Extend TenantMigrateCommand** - `809f124` (feat)
2. **Task 2: Register ParallelMigrationRunner in TenancyBundle.php** - `55dca4c` (feat)
3. **Task 3: Command + integration tests** - `856e5db` (test)

## Files Created/Modified

- `src/Command/TenantMigrateCommand.php` — 7th nullable `?ParallelMigrationRunner` arg, 4 new options, concurrency clamp, shared_db guard preserved first, delegation branch, human table + JSON rendering, `runMigrationsForTenant()` dry-run branch
- `src/TenancyBundle.php` — `use` import for `ParallelMigrationRunner`; service registered inside `class_exists(DependencyFactory)` block; wired as 7th arg to migrate command
- `tests/Unit/Command/TenantMigrateCommandParallelTest.php` — 10 unit test methods (SC1-SC5, Discretion, D-07, BC)
- `tests/Integration/Command/TenantMigrateCommandParallelIntegrationTest.php` — 5 integration tests (container wiring, driver arg integrity, runner reflection)
- `tests/Integration/Command/Support/MakeCommandsPublicPass.php` — added `tenancy.command.migrate.parallel_runner` to public service IDs

## Decisions Made

- `?ParallelMigrationRunner $parallelRunner = null` as 7th constructor arg with default null: back-compat with all existing `TenantMigrateCommandTest` tests that construct the command with 6 args. Production DI always wires it; the null path is a defensive fallback (falls through to sequential).
- `is_string()` check before `is_numeric()` for `--concurrency`: `getOption()` returns `mixed`; PHPStan L9 requires the `is_string()` narrowing before any `(int)` cast or string concatenation.
- Clamp-test via stderr notice, not via spy subclass: `ParallelMigrationRunner` is `final` so anonymous subclassing for a concurrency-capture spy is impossible. The at-most-N invariant is proven in plan-01's `testAtMostNConcurrent`. The command-layer test proves the clamp via the observable stderr notice.
- Integration test uses reflection to prove runner wiring, not `container->get()`: Symfony's DI optimizer inlines single-use private services at compile time. The `tenancy.command.migrate.parallel_runner` service is only referenced once (as the 7th arg to the migrate command) and gets inlined. `container->get()` on an inlined service raises `ServiceNotFoundException`. Reflection on the command instance is the correct approach.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] PHPStan L9: `Cannot cast mixed to string` on `--concurrency` option value**
- **Found during:** Task 1 verification (PHPStan run)
- **Issue:** `$input->getOption('concurrency')` returns `mixed`; the error message used `(string) $concurrencyRaw` which PHPStan rejects as a `cast.string` error on a `mixed` value.
- **Fix:** Added `\is_string($concurrencyRaw)` as the first condition in the guard; used `sprintf()` with `gettype()` fallback for the error message.
- **Files modified:** `src/Command/TenantMigrateCommand.php`
- **Committed in:** `809f124`

**2. [Rule 1 - Bug] PHPStan L9: `non-empty-array<TenantInterface>` not assignable to `list<TenantInterface>`**
- **Found during:** Task 1 verification (PHPStan run)
- **Issue:** `$tenants` (from `findAll()` or `[$this->tenantProvider->findBySlug(...)]`) is typed `non-empty-array<TenantInterface>`, which PHPStan does not consider a `list<T>` (a list requires integer keys starting from 0). `ParallelMigrationRunner::run()` demands `list<TenantInterface>`.
- **Fix:** Added `array_values($tenants)` before the `->run()` call to produce a guaranteed-list.
- **Files modified:** `src/Command/TenantMigrateCommand.php`
- **Committed in:** `809f124`

**3. [Rule 1 - Bug] PHPStan L9: `mixed` iterables in JSON test assertions**
- **Found during:** Task 3 PHPStan run
- **Issue:** `json_decode(..., true)` returns `mixed`; `$decoded['tenants']` used in `foreach` was untyped, causing `foreach.nonIterable` + `argument.type` errors on `assertArrayHasKey()`.
- **Fix:** Added `/** @var array<mixed> */` phpdoc annotation with `(array)` cast on `$tenantRows` and `$summary` to narrow the type for PHPStan. Used `$this->assertIsArray($row)` inside the loop to narrow per-row type.
- **Files modified:** `tests/Unit/Command/TenantMigrateCommandParallelTest.php`
- **Committed in:** `856e5db`

**4. [Rule 1 - Bug] CommandTester::getErrorOutput() requires `capture_stderr_separately`**
- **Found during:** Task 3 test run
- **Issue:** `$tester->getErrorOutput()` threw `LogicException` because `CommandTester` by default merges stdout + stderr into one stream.
- **Fix:** Added `['capture_stderr_separately' => true]` as the second argument to `$tester->execute()` in the clamp test.
- **Files modified:** `tests/Unit/Command/TenantMigrateCommandParallelTest.php`
- **Committed in:** `856e5db`

**5. [Rule 1 - Bug] Cannot extend `final` class `ParallelMigrationRunner` for spy subclass**
- **Found during:** Task 3 test run (PHP Fatal Error)
- **Issue:** `testConcurrencyClampAboveCapWithStderrNotice` used an anonymous subclass of `ParallelMigrationRunner` to capture the `$concurrency` arg. `ParallelMigrationRunner` is declared `final` — fatal error.
- **Fix:** Rewrote the test to assert the observable behavior: (a) exit is not `Command::INVALID` (clamped value reached the runner), and (b) stderr contains the clamp notice text. The at-most-32 invariant is separately proven in `ParallelMigrationRunnerTest::testAtMostNConcurrent`.
- **Files modified:** `tests/Unit/Command/TenantMigrateCommandParallelTest.php`
- **Committed in:** `856e5db`

**6. [Rule 1 - Bug] Integration test: `container->get()` throws on inlined private service**
- **Found during:** Task 3 test run (Symfony ServiceNotFoundException)
- **Issue:** `testParallelRunnerServiceIsRegistered` originally called `container->has()` (returned false) then `container->get()` (threw `ServiceNotFoundException: service removed or inlined`). The Symfony DI optimizer inlines single-use private services at compile time.
- **Fix:** Replaced the `has()`/`get()` pair with a reflection check on the already-resolved `tenancy.command.migrate` command instance. This correctly asserts the runner was registered and wired without depending on the service ID surviving compilation.
- **Files modified:** `tests/Integration/Command/TenantMigrateCommandParallelIntegrationTest.php`
- **Committed in:** `856e5db`

---

**Total deviations:** 6 auto-fixed (all Rule 1 - Bug)
**Impact on plan:** All fixes were correctness requirements (PHPStan L9 compliance, test infrastructure constraints, PHP class-system constraints). No scope creep; no behavioral changes beyond the bugs fixed.

## Issues Encountered

- PHPStan L9 required two type-narrowing fixes on `TenantMigrateCommand.php` (mixed return of `getOption()`, non-empty-array vs list distinction) — both fixed inline before commit.
- PHPUnit `CommandTester` stderr separation is opt-in (`capture_stderr_separately`); must be passed as the second arg to `execute()`, not as a CommandTester constructor option.
- Symfony DI compiler inlines private, single-use services — the integration test cannot use `container->get('tenancy.command.migrate.parallel_runner')` to prove registration. Reflection on the command instance is the correct verification approach.

## Threat Surface Scan

No new network endpoints, auth paths, file access patterns, or schema changes introduced. All files are CLI command and test layer. The four threat mitigations from the plan's `<threat_model>` are satisfied:
- T-31-05 (shared_db guard bypass): guard runs first, proven by `testSharedDbParallelRefusesAndSpawnsNothing`.
- T-31-06 (unbounded concurrency): clamped to [1,32], proven by `testConcurrencyClampAboveCapWithStderrNotice` + `testConcurrencyInvalidValues`.
- T-31-07 (argv injection): no new argv construction in the command; tenant slugs pass through to the runner which uses array argv (proven in plan-01).
- T-31-08 (JSON disclosure): per-tenant rows carry status/count/duration only; full log not included; warnings go to stderr.

## Known Stubs

None — all options flow to real runtime behavior (runner delegation, sequential foreach, JSON emission). No hardcoded empty values or placeholder text in the shipped code.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- Phase 31 is COMPLETE: both plans (runner pool + command surface) delivered all 6 ISOL requirements (ISOL-07 through ISOL-12).
- Phase 32 (OPS-01 / maintenance mode): ready to plan. No blockers from Phase 31.
- The `tenancy:migrate --parallel` command is operator-usable immediately; docs land in Phase 34 (DOC-21).

## Self-Check

- [x] `src/Command/TenantMigrateCommand.php` modified with 4 new options + delegation branch
- [x] `src/TenancyBundle.php` modified with runner registration + 7th arg wiring
- [x] `tests/Unit/Command/TenantMigrateCommandParallelTest.php` created with 10 test methods
- [x] `tests/Integration/Command/TenantMigrateCommandParallelIntegrationTest.php` created with 5 test methods
- [x] Commit `809f124` exists: feat(31-02) TenantMigrateCommand
- [x] Commit `55dca4c` exists: feat(31-02) TenancyBundle.php
- [x] Commit `856e5db` exists: test(31-02) parallel tests
- [x] PHPStan L9 clean on src/Command/TenantMigrateCommand.php, src/TenancyBundle.php
- [x] 15 parallel tests green, 7 existing migrate tests green
- [x] `grep -c 'ParallelMigrationRunner' config/services.php` = 0

## Self-Check: PASSED

---
*Phase: 31-parallel-migrations*
*Completed: 2026-06-26*
