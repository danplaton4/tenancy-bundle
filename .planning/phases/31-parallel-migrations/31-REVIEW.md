---
phase: 31-parallel-migrations
reviewed: 2026-06-26T09:01:44Z
depth: standard
files_reviewed: 7
files_reviewed_list:
  - src/Command/Migration/ParallelMigrationRunner.php
  - src/Command/TenantMigrateCommand.php
  - src/TenancyBundle.php
  - tests/Unit/Command/Migration/ParallelMigrationRunnerTest.php
  - tests/Unit/Command/TenantMigrateCommandParallelTest.php
  - tests/Integration/Command/TenantMigrateCommandParallelIntegrationTest.php
  - tests/Integration/Command/Support/MakeCommandsPublicPass.php
findings:
  critical: 1
  warning: 6
  info: 4
  total: 11
status: partially_resolved
---

# Phase 31: Code Review Report

**Reviewed:** 2026-06-26T09:01:44Z
**Depth:** standard
**Files Reviewed:** 7
**Status:** issues_found

## Summary

Reviewed the Phase 31 parallel-migration surface: the bounded subprocess pool
(`ParallelMigrationRunner`), the new `--parallel/--concurrency/--dry-run/--format=json`
options on `TenantMigrateCommand`, the DI wiring in `TenancyBundle::loadExtension()`, and the
three associated test files.

Positives confirmed by tracing:
- The shared_db guard is correctly **guard-first** — it runs before concurrency parsing and
  before the parallel branch, so no subprocess can ever be spawned under shared_db (SC4 holds).
- The argv is array-shaped (`[PHP_BINARY, .../bin/console, 'tenancy:migrate', '--tenant='.$slug]`),
  with no shell string and no `Process::fromShellCommandline()` — no shell-injection surface.
  A malicious slug becomes a single inert argv token.
- Exit-code handling is correct: `null === $exitCode || 0 !== $exitCode` is failure; the
  `?? 0` anti-pattern is avoided (D-07 / Pitfall 15 holds).
- The no-flag sequential path is byte-identical to v0.4: when `$dryRun` is false the new
  branches in `runMigrationsForTenant()` are skipped and output matches the prior version
  line-for-line. `symfony/process` was already a hard dependency at the diff base — no new
  unguarded import was introduced.
- Output atomicity is preserved: each tenant's block is flushed in a single pass after the
  child exits, captured via the `start()` streaming callback (not `getOutput()`), avoiding
  the interleaving pitfall.

The one BLOCKER is in JSON mode: child output can contain invalid UTF-8, which makes
`json_encode()` return `false`, and the unchecked `(string)` cast silently emits an empty
"document" exactly when a tenant has failed. There are also several robustness/quality
warnings around the process-global signal-handler side effects, busy-wait CPU, and a couple
of tests whose assertions are conditional and therefore can pass without proving anything.

## Critical Issues

### CR-01: JSON mode silently emits an empty document on invalid-UTF-8 child output

**File:** `src/Command/TenantMigrateCommand.php:200` (with source at `src/Command/Migration/ParallelMigrationRunner.php:207-218`)

**Issue:** The aggregate JSON is produced with
`(string) json_encode($aggregate, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)`.
The `error` field of each row comes from `ParallelMigrationRunner::extractError()`, which
returns the last non-empty line of the **raw captured child output**
(`trim(strip_tags((string) end($lines)))`). Child stdout/stderr is captured verbatim through
the streaming callback and is not guaranteed to be valid UTF-8 — a migration that prints
binary, a truncated multibyte sequence, or a non-UTF-8 locale error message will land
invalid bytes in `error`. `json_encode()` without `JSON_INVALID_UTF8_SUBSTITUTE`/
`JSON_THROW_ON_ERROR` returns `false` on such input. `(string) false === ''`, so the command
writes a single blank line to stdout and still returns an exit code derived from
`$result->failed()`.

The machine-readable contract (D-03/D-04: "stdout carries ONLY the JSON document") is then
silently violated — a consumer parsing stdout gets empty/unparseable output with no error
signal — and this happens specifically in the failure path (the case JSON consumers most
need to read). This is a correctness/data-integrity defect in the documented output contract,
not a cosmetic one.

**Fix:** Use a serialization mode that cannot return `false` on bad bytes, and fail loudly if
encoding still fails:

```php
$json = json_encode(
    $aggregate,
    \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR,
);
$output->writeln($json);
```

Optionally also sanitize at the source in `extractError()` (e.g.
`mb_convert_encoding($line, 'UTF-8', 'UTF-8')` or
`mb_scrub()`/`iconv('UTF-8', 'UTF-8//IGNORE', ...)`) so the human table path is robust too.
At minimum, never cast an unchecked `json_encode()` result to string.

## Warnings

### WR-01: Signal handler leaks process-global state and a dangling `&$running` reference

**File:** `src/Command/Migration/ParallelMigrationRunner.php:80-93`

**Issue:** `run()` calls `pcntl_async_signals(true)` and installs SIGTERM/SIGINT handlers, but
never restores the prior async-signals state nor unregisters the handlers when the method
returns. The handler closure captures `&$running` by reference. Consequences in a
long-running host (e.g. a Messenger worker or any process that calls `run()` more than once,
or calls it and then keeps running): (a) `pcntl_async_signals(true)` stays on globally,
silently changing the host's signal semantics; (b) the previously-installed handler still
holds a reference to the first call's now-empty `$running`, so a later signal iterates an
empty array and `exit(1)`s without stopping any children spawned by a subsequent call; (c)
any pre-existing application SIGTERM/SIGINT handler is overwritten and never restored.

**Fix:** Snapshot and restore around the run, e.g.:

```php
$prevAsync = pcntl_async_signals(true);
$prevTerm = pcntl_signal_get_handler(\SIGTERM);
$prevInt  = pcntl_signal_get_handler(\SIGINT);
try {
    pcntl_signal(\SIGTERM, $signalHandler);
    pcntl_signal(\SIGINT, $signalHandler);
    // ... pool loop ...
} finally {
    pcntl_signal(\SIGTERM, $prevTerm);
    pcntl_signal(\SIGINT, $prevInt);
    pcntl_async_signals($prevAsync);
}
```

(The `exit(1)` itself makes restore-on-signal moot, but restore-on-normal-return is needed.)

### WR-02: `exit(1)` inside a library bypasses caller shutdown / makes the runner non-reusable

**File:** `src/Command/Migration/ParallelMigrationRunner.php:88`

**Issue:** The signal handler calls `exit(1)` directly. For a service injected into a command
this hard-terminates the entire PHP process, bypassing Symfony's console/kernel shutdown,
`finally` blocks in callers, and any post-run cleanup. It also makes the class impossible to
reuse inside a host process that wants to handle SIGTERM gracefully. The design note (Pitfall
18) justifies forwarding the signal to children, but the unconditional `exit()` couples the
library to terminating the world.

**Fix:** Forward the signal to children and re-raise the default disposition instead of a hard
`exit`, so the caller's own handlers/shutdown still run:

```php
$signalHandler = function (int $signo) use (&$running): void {
    foreach ($running as $entry) {
        $entry['process']->stop(0);
    }
    pcntl_signal($signo, \SIG_DFL);
    posix_kill(posix_getpid(), $signo); // guarded by extension_loaded('posix')
};
```

If `exit` must stay, at least document that the runner is single-shot and not safe inside a
worker loop.

### WR-03: Busy-wait when the pool is saturated — `usleep` only gates on `$running`, not on idle polls

**File:** `src/Command/Migration/ParallelMigrationRunner.php:96-181`

**Issue:** The intended cadence is "poll every 50ms" (per the design note). But the
`usleep(50_000)` runs only after the reap pass when `[] !== $running`. In the steady state
that is fine, however the outer loop also re-enters the **fill** loop and re-iterates the full
reap `foreach` every cycle. When the pool is full and the queue still has items, each cycle
does: fill (no-op, pool full) → reap (all `isRunning()` true) → `usleep`. That is correct.
The real concern is the final drain: when `[] === $queue` but children remain, the loop still
re-runs fill (no-op) and reap each 50ms — acceptable. This is borderline, but flagged because
the loop structure makes the cadence depend on a subtle invariant (reap must always leave
`$running` non-empty if any child is still alive, which holds only because reap mutates
`$running` mid-foreach via `unset`). Mutating the array being `foreach`-ed (line 175 `unset`
inside the `foreach ($running as ...)` at line 134) is legal in PHP because `foreach`
iterates a copy of the iteration order, but it is fragile and easy to break in future edits.

**Fix:** Extract the poll/reap into a clearly-structured single sleep-per-cycle loop and avoid
`unset()` on the array under iteration; collect finished slugs first, then unset after the
loop:

```php
$finished = [];
foreach ($running as $slug => $entry) {
    if ($entry['process']->isRunning()) { continue; }
    // ... build result row ...
    $finished[] = $slug;
}
foreach ($finished as $slug) { unset($running[$slug], $buffers[$slug]); }
if ([] !== $running || [] !== $queue) { usleep(50_000); }
```

### WR-04: `testSequentialPathByteIdenticalRegression` asserts conditionally — can pass while proving nothing

**File:** `tests/Unit/Command/TenantMigrateCommandParallelTest.php:143-159`

**Issue:** The body wraps `execute()` in `try { } catch (\Throwable) { }` and then asserts
`Completed:` **only if** `'' !== $display`. With a `createMock(Connection::class)`,
`DependencyFactory::fromConnection(...)->getMetadataStorage()->ensureInitialized()` throws
before any `$io->writeln`, so the per-tenant `catch` records a failure and the footer is
written — but the whole `execute()` can also throw out of the loop in some setups, leaving
`$display` empty and the conditional assertion skipped. The only unconditional guarantee is
"the never-called factory was not called", which is enforced by the closure's `$this->fail()`.
The SC1 footer/byte-identity claim in the test name is therefore not actually verified here.

**Fix:** Make the byte-identity assertion unconditional. Either stub the connection/dependency
factory enough to reach the footer, or assert on the exception-free portion explicitly, or
split into two tests: one asserting the factory is untouched (already solid) and one that
reaches the `Completed:` footer with a connection mock configured to let
`ensureInitialized()`/plan calculation succeed with an empty plan.

### WR-05: `testDryRunReportsWithoutApplying` cannot fail on the behavior it claims to test

**File:** `tests/Unit/Command/TenantMigrateCommandParallelTest.php:343-380`

**Issue:** Like WR-04, this test swallows all throwables and only runs an `assertThat(...)`
inside `if ('' !== $display)`. The `logicalOr` accepts `'dry-run'` OR `'Completed:'` OR
`'No tenants found'` — i.e. essentially any non-empty output passes. It does not prove that
`migrate()` was skipped (the actual D-05/ISOL-10 contract). The named guarantee ("computes
plan without applying") is not exercised because the mock connection throws before the
dry-run branch is reached, so the dry-run short-circuit is never observed.

**Fix:** Assert the `[dry-run] ... would apply` / `nothing to migrate` wording against a
connection/dependency-factory test double that reaches `runMigrationsForTenant()` and verify
`getMigrator()->migrate()` is never invoked (e.g. via a spy on the migrator, or by injecting a
fake `Configuration`/factory). If reaching that branch in a unit test is impractical, move the
assertion to an integration test with a real SQLite plan.

### WR-06: Child output written raw to console may emit ANSI/control bytes; child verbosity not forwarded

**File:** `src/Command/Migration/ParallelMigrationRunner.php:170-172`

**Issue:** The captured child buffer is written with `$output->write($buffer)` verbatim. The
child is a full `bin/console tenancy:migrate` invocation whose `SymfonyStyle` may emit ANSI
color codes and decoration depending on the child's TTY detection, independent of the parent's
`--no-ansi`/verbosity. The parent therefore can render escape sequences it would otherwise
suppress, and conversely cannot propagate `-v/-vv/--no-ansi` to children (argv never forwards
verbosity). In human mode this is cosmetic; combined with CR-01 it also means non-printable
bytes reach the terminal.

**Fix:** Forward the relevant flags to the child argv (e.g. `--no-ansi` when
`!$output->isDecorated()`, and a verbosity flag mirroring the parent), or strip control
sequences from the buffer before writing in human mode. At minimum document that child output
is passed through unfiltered.

## Info

### IN-01: `ParallelMigrationRunner.php` declares two classes in one file

**File:** `src/Command/Migration/ParallelMigrationRunner.php:23,236`

**Issue:** `ParallelMigrationRunner` and `ParallelMigrationResult` live in the same file.
PSR-4 autoloading resolves `ParallelMigrationResult` only because `ParallelMigrationRunner` is
loaded first; referencing `ParallelMigrationResult` before the runner class (in any future
code path) would fail to autoload. It also trips the `@Symfony` one-class-per-file expectation.

**Fix:** Move `ParallelMigrationResult` to its own file
`src/Command/Migration/ParallelMigrationResult.php`.

### IN-02: `migrationsApplied` is a heuristic `substr_count($buffer, '++ migrating')`

**File:** `src/Command/Migration/ParallelMigrationRunner.php:195-202`

**Issue:** The applied-count parses a Doctrine Migrations 3.x log string. It is documented as
best-effort, but it will silently report `0` (or wrong counts) against Doctrine Migrations 4.x
or any locale/format change, and it double-counts nothing but also misses migrations that log
differently. Since this value is surfaced in the JSON contract and the human table, consumers
may treat it as authoritative.

**Fix:** Keep it best-effort but make the fragility explicit in the JSON key docs, or derive
the count from a structured signal (e.g. the child emitting a final machine-readable line) in
a follow-up. No action required for this phase beyond a comment already present.

### IN-03: `extractError` returns the last line, which is often the least useful line

**File:** `src/Command/Migration/ParallelMigrationRunner.php:207-223`

**Issue:** For a failed migration the last non-empty line is frequently a stack-trace frame or
a trailing "Migration ... failed" summary rather than the root exception message. This is a
quality/usefulness concern, not a correctness bug.

**Fix:** Prefer the first line matching an error marker (e.g. `Exception`, `error`, `failed`)
or the first stderr line, falling back to the last line.

### IN-04: Integration test `testParallelRunnerServiceIsRegistered` is a near-duplicate of `testMigrateCommandHasParallelRunnerWired`

**File:** `tests/Integration/Command/TenantMigrateCommandParallelIntegrationTest.php:106-128`

**Issue:** Both tests fetch `tenancy.command.migrate`, reflect the `parallelRunner` property,
and assert it is a `ParallelMigrationRunner`. The second test's own docblock acknowledges the
service may be inlined and that the reflection check is the authoritative proof — making it
largely redundant with the first.

**Fix:** Drop one, or repurpose `testParallelRunnerServiceIsRegistered` to assert something
distinct (e.g. that the runner received `kernel.project_dir` as its `projectDir` arg).

---

## Resolution

**CR-01 — FIXED** (commit `72d0239`)
- `TenantMigrateCommand.php`: replaced `(string) json_encode(...)` with
  `JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR` flags so bad bytes in child output
  are substituted rather than silently producing an empty document. The `(string)` cast is removed.
- `ParallelMigrationRunner::extractError()`: extracted line is now scrubbed to valid UTF-8 via
  `mb_convert_encoding($line, 'UTF-8', 'UTF-8')` (PHP 8.2-compatible; guarded by `extension_loaded('mbstring')`).
- Regression test `testJsonFormatWithInvalidUtf8ChildOutputProducesValidDocument()` added to
  `tests/Unit/Command/TenantMigrateCommandParallelTest.php` — drives `--parallel --format=json`
  with a failing child whose output contains `\xFF\xFE` bytes; asserts a single decodable JSON
  document is produced.

**WR-01 — FIXED** (commit `a0fa83a`)
- `ParallelMigrationRunner::run()`: pcntl async-signals state and previous SIGTERM/SIGINT handlers
  are now snapshotted before installation (via `pcntl_signal_get_handler()`) and restored in a
  `finally` block wrapping the pool loop. `exit(1)` inside the signal handler is retained
  (Pitfall 18 design decision). Snapshot vars are typed `callable|int|null` for PHPStan L9.

**Deferred findings (tracked tech-debt — no action in this pass):**
- WR-02: `exit(1)` inside library / non-reusable runner
- WR-03: busy-wait poll-loop refactor (collect-then-unset)
- WR-04: `testSequentialPathByteIdenticalRegression` conditional assertion weakness
- WR-05: `testDryRunReportsWithoutApplying` conditional assertion weakness
- WR-06: ANSI/verbosity passthrough to child processes
- IN-01: two classes in one file (`ParallelMigrationResult` split)
- IN-02: `migrationsApplied` heuristic fragility
- IN-03: `extractError` returns last line (least useful)
- IN-04: near-duplicate integration test

---

_Reviewed: 2026-06-26T09:01:44Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
