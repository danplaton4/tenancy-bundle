---
phase: 31-parallel-migrations
plan: 01
subsystem: cli
tags: [symfony-process, bounded-pool, concurrency, subprocess, migrations]

# Dependency graph
requires: []
provides:
  - ParallelMigrationRunner service: bounded sliding-window subprocess pool for per-tenant migrations
  - ParallelMigrationResult DTO: D-03 JSON-ready shape (slug/status/migrationsApplied/durationMs/error + summary)
  - Test seam: \Closure(list<string>): Process processFactory constructor injection (mirrors TenantRunCommand)
affects:
  - 31-02: command-layer plan consumes ParallelMigrationRunner::run() and ParallelMigrationResult directly

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Bounded sliding-window subprocess pool: fill up to N → reap isRunning()==false → usleep(50ms) → repeat"
    - "Streaming output buffer: start(fn($type,$chunk) use (&$buffers[$slug]){...}) into separate per-slug buffer map — never getOutput() post-exit"
    - "Null-exit-as-failure: $ec = getExitCode(); $failed = (null === $ec || 0 !== $ec) — DO NOT use ?? 0"
    - "SIGTERM forwarding: extension_loaded('pcntl') guard → pcntl_async_signals(true) → pcntl_signal(SIGTERM,...)"
    - "Atomic output block: header + full buffer written at once on reap (D-01 / Pitfall 14)"

key-files:
  created:
    - src/Command/Migration/ParallelMigrationRunner.php
    - tests/Unit/Command/Migration/ParallelMigrationRunnerTest.php
  modified: []

key-decisions:
  - "Buffer map ($buffers[slug]) separate from $running array — PHP string-copy semantics would freeze the empty string at push-time if stored as a value inside $running; separate map with closure capturing $slug keeps the reference live"
  - "emitBlocks=true default on run(): caller passes false for --format=json to suppress human output while still getting the aggregate result (D-04)"
  - "parseMigrationsApplied() counts '++ migrating' lines as best-effort; authoritative pass/fail is always the exit code"
  - "usleep(50_000) poll cadence (50ms) matches STACK.md recommendation over PITFALLS.md 100ms suggestion"

patterns-established:
  - "processFactory seam: \\Closure(list<string>): Process|null constructor injection (nullable, default null) — mirrors TenantRunCommand exactly"
  - "Per-slug buffer map pattern for streaming multi-process output capture"

requirements-completed: [ISOL-07, ISOL-08, ISOL-09, ISOL-12]

# Metrics
duration: 35min
completed: 2026-06-26
---

# Phase 31 Plan 01: ParallelMigrationRunner Summary

**Bounded `symfony/process` worker pool with streaming buffer capture, null-exit-as-failure enforcement, and SIGTERM forwarding — the subprocess engine that plan 02's `--parallel` command flag delegates to.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-06-26T08:30:00Z
- **Completed:** 2026-06-26T09:05:00Z
- **Tasks:** 2
- **Files modified:** 2 (created)

## Accomplishments

- `ParallelMigrationRunner` service: non-blocking `start()+isRunning()` sliding-window pool, fills to concurrency cap, reaps on completion, buffers per-child output in PHP memory via streaming callback (never `getOutput()` post-exit — Pitfall 17)
- `ParallelMigrationResult` DTO in same file: per-tenant `slug/status/migrationsApplied/durationMs/error` + summary `succeeded()/failed()/total()/wallClockMs()` — D-03 JSON key shape, directly consumable by plan 02 command
- 8 unit tests proving SC2 (at-most-N concurrency), SC3 (null exit = failure, atomic output), SC5 (JSON shape), dry-run forwarding — all green, no PHPUnit deprecations
- PHPStan level 9 clean, php-cs-fixer @Symfony clean, pre-commit hook (full suite 778 tests) green

## Task Commits

1. **Task 1 + Task 2: ParallelMigrationRunner + unit tests** - `6253131` (feat)

## Files Created/Modified

- `src/Command/Migration/ParallelMigrationRunner.php` — `final class ParallelMigrationRunner` + `final class ParallelMigrationResult` in namespace `Tenancy\Bundle\Command\Migration`; 220+ lines
- `tests/Unit/Command/Migration/ParallelMigrationRunnerTest.php` — 8 test methods, 74 assertions, 0 deprecations

## Decisions Made

- Buffer map (`$buffers[$slug]`) stored separately from `$running` entry: PHP copies strings on assignment, so storing `$buffer` as a value in `$running[$slug]['buffer']` would freeze an empty string. A separate `$buffers` array with the closure capturing `$slug` keeps the accumulating buffer live for the reap phase.
- `$emitBlocks` 5th param (default `true`): runner always builds the aggregate result and buffers per-tenant logs; JSON-mode callers pass `false` to suppress the human blocks. Matches D-04 cleanly.
- `parseMigrationsApplied()` counts `++ migrating` occurrences as best-effort; zero on no parseable count. Authoritative result is always the exit code.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] PHP string-copy pitfall: buffer reference in $running array**
- **Found during:** Task 1 implementation review
- **Issue:** Storing `$buffer = ''; $running[$slug]['buffer'] = $buffer` captures the empty string by value. The streaming closure would update a local `$buffer` variable, but `$running[$slug]['buffer']` would remain empty forever. The reap phase would read empty strings for all children.
- **Fix:** Introduced a separate `$buffers` (array<string, string>) map outside `$running`. The closure captures `$slug` (not `&$buffer`) and writes `$buffers[$slug] .= $chunk`. The reap phase reads `$buffers[$slug]`. This is the only semantically correct pattern for multi-process streaming buffers in PHP.
- **Files modified:** src/Command/Migration/ParallelMigrationRunner.php
- **Verification:** `testAtomicOutputNoInterleaving` passes — the 3-line per-tenant buffers appear correctly in the output.
- **Committed in:** 6253131 (feat commit)

---

**Total deviations:** 1 auto-fixed (Rule 1 - Bug)
**Impact on plan:** Critical correctness fix. Without it, all per-tenant output would be empty and the atomic-block feature would not work. No scope creep.

## Issues Encountered

- PHPStan `argument.type` error on status string: `$failed ? 'failed' : 'succeeded'` was inferred as `string` by PHPStan when the `$results` array was typed `list<array{...status: string...}>`. Fixed by changing the `@var` annotation on `$results` to use the union type `'succeeded'|'failed'` — PHPStan then narrowed `$status` from the ternary correctly.
- PHPUnit 11 deprecation on `@covers` doc annotations: removed all `@covers` tags from test method docblocks (the project uses `#[CoversClass]` attributes per `MailerSetupStepTest`; but since the plan doesn't require coverage enforcement, simply removing the deprecated annotations was cleaner).
- `assertIsInt()` on `ParallelMigrationResult::succeeded()` etc. triggered PHPStan `method.alreadyNarrowedType` errors — replaced with `assertGreaterThanOrEqual(0, ...)` assertions that are both meaningful and type-correct.

## Threat Surface Scan

No new network endpoints, auth paths, file access patterns, or schema changes. The runner exclusively uses `symfony/process` (already in `require`) via array argv (no shell — T-31-01 anti-tamper satisfied). SIGTERM handler is guarded by `extension_loaded('pcntl')`.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- `ParallelMigrationRunner::run()` signature and `ParallelMigrationResult` shape are the contract plan 02 (31-02) consumes.
- Plan 02 must add `ParallelMigrationRunner` as a constructor argument to `TenantMigrateCommand` and wire it in `TenancyBundle.php` inside the `class_exists(DependencyFactory)` block (31-PATTERNS.md "DI Registration" pattern).
- No blockers.

## Self-Check

- [x] `src/Command/Migration/ParallelMigrationRunner.php` exists and declares `final class ParallelMigrationRunner`
- [x] `tests/Unit/Command/Migration/ParallelMigrationRunnerTest.php` exists with 8 test methods
- [x] Commit `6253131` exists in git log
- [x] PHPStan level 9 clean on both files
- [x] PHPUnit 778 tests pass (full suite, pre-commit hook)

## Self-Check: PASSED

---
*Phase: 31-parallel-migrations*
*Completed: 2026-06-26*
