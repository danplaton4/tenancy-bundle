# Phase 31: Parallel Migrations - Pattern Map

**Mapped:** 2026-06-26
**Files analyzed:** 2 (1 new, 1 modified) + 2 test files
**Analogs found:** 2 / 2 (both exact for their respective concerns)

> Scope note: there is no phase-31 RESEARCH.md (research was skipped — milestone research was used instead). File list extracted from `31-CONTEXT.md` `<code_context>` + `<decisions>` and cross-checked against `ARCHITECTURE.md` §"Feature 3: ISOL-07" (lines 481-526) and the `STACK.md` bounded-pool snippet (lines 204-256). This phase is **purely CLI/process** — no controllers, components, HTTP, events, or schema. Treat all "request/response" vocabulary from the agent spec as N/A; the real axes here are **command-orchestration** and **subprocess fan-out**.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `src/Command/Migration/ParallelMigrationRunner.php` (NEW) | service (plain, no compiler pass) | batch / subprocess fan-out (bounded sliding-window pool) | `src/Command/TenantRunCommand.php` (spawn shape + processFactory seam) **+** `src/Command/TenantMigrateCommand.php` (failure aggregation + summary) | exact (composite) |
| `src/Command/TenantMigrateCommand.php` (MODIFIED) | command (console) | request-response (CLI) → delegates to batch runner on `--parallel` | itself (extend in place; sequential path stays byte-identical) | self / exact |
| `tests/Unit/Command/Migration/ParallelMigrationRunnerTest.php` (NEW) | test (unit) | mock-factory concurrency assertion | `tests/Unit/Command/TenantRunCommandTest.php` (processFactory mock seam) | exact |
| `tests/Integration/Command/...` (NEW, optional) | test (integration) | real SQLite per-tenant migrate | `tests/Integration/Command/TenantMigrateCommandIntegrationTest.php` | role-match |

**DI registration target:** `src/TenancyBundle.php` (NOT `config/services.php`) — the migrate command is registered *imperatively* inside the `database.enabled` + `class_exists(DependencyFactory)` block (lines 263-274), not in the static `config/services.php`. The new runner service must be wired in the **same** block so it inherits the `shared_db`-cannot-coexist guard structurally (D-06 / Pitfall 16). See "Shared Patterns → DI Registration" below.

---

## Pattern Assignments

### `src/Command/Migration/ParallelMigrationRunner.php` (NEW — service, subprocess fan-out)

This component has **two analogs** because it fuses two existing concerns: the spawn/exit shape from `TenantRunCommand` and the failure-aggregation/summary shape from `TenantMigrateCommand`. Copy from both.

**Analog A — `src/Command/TenantRunCommand.php` (spawn shape + test seam)**

**Imports + namespace convention** (`TenantRunCommand.php` lines 1-14): `declare(strict_types=1);`, namespace `Tenancy\Bundle\Command\*`, import `Symfony\Component\Process\Process`. The new class goes in `Tenancy\Bundle\Command\Migration` (matches the `src/Command/Migration/` path from ARCHITECTURE.md line 440 / 487).

**The processFactory test seam — copy this constructor docblock + signature verbatim in spirit** (`TenantRunCommand.php` lines 19-31):
```php
/**
 * @param \Closure(list<string>): Process|null $processFactory
 *     Optional test seam; receives the fully-tokenized command argv list
 *     (NO shell semantics) and returns a Process instance. Real callers
 *     leave this null and the command spawns a Process with array argv.
 */
public function __construct(
    private readonly ?TenantProviderInterface $tenantProvider,
    private readonly string $projectDir,
    private readonly ?\Closure $processFactory = null,
) {
```
The runner's constructor mirrors this: `string $projectDir` + `?\Closure $processFactory = null` (default null, nullable). This `\Closure(list<string>): Process` is the **one hard design constraint** (CONTEXT D-05/Discretion line 53) and the mechanism for the Pitfall-13 "at-most-N concurrent" quality gate.

**Array-argv spawn — NO shell** (`TenantRunCommand.php` lines 73-81):
```php
$command = array_merge(
    [\PHP_BINARY, $this->projectDir.'/bin/console'],
    $tokens,
    ['--tenant='.$tenantSlug],
);

$process = (null !== $this->processFactory)
    ? ($this->processFactory)($command)
    : new Process($command);

$process->setTimeout(null);
```
For the runner the argv is fixed: `[\PHP_BINARY, $this->projectDir.'/bin/console', 'tenancy:migrate', '--tenant='.$slug]` (+ pass-through flags like `--dry-run`, and `--format` is NOT forwarded — the child stays human-mode; the parent aggregates JSON, D-04). `setTimeout(null)` is required (Discretion: "No per-subprocess timeout — never kill a migration mid-flight").

**Exit-code handling — CRITICAL DIVERGENCE (Pitfall 15):**
`TenantRunCommand.php` line 89 uses `return $process->getExitCode() ?? 0;` — a pass-through fallback that maps null→success. The runner **MUST NOT** copy the `?? 0`. Per Pitfall 15 + D-07, a `null` exit code (killed/timed-out/crashed child) is **FAILURE, never success**. Aggregate as: `$exitCode = $process->getExitCode(); $failed = (null === $exitCode || 0 !== $exitCode);`.

**The blocking-vs-poll DIVERGENCE:**
`TenantRunCommand.php` line 85 uses blocking `$process->run($callback)`. The runner needs the **non-blocking** form: `$process->start($callback)` + an `isRunning()` poll loop (sliding window). This is the documented Pitfall-17 safe pattern (PITFALLS.md lines 517-526) and the bounded-pool loop from STACK.md lines 204-229:
```php
// accumulate streamed output in PHP memory per child (Pitfall 17 — never getOutput() post-exit)
$buffer = '';
$process->start(function (string $type, string $chunk) use (&$buffer): void {
    $buffer .= $chunk;
});
// ... poll loop: while (count($running) >= $limit) { foreach reap !isRunning(); usleep(...) }
```
Poll cadence: STACK.md suggests `usleep(50_000)` (50ms); PITFALLS.md pseudo-code uses `usleep(100_000)` (100ms). Exact cadence left to planning (CONTEXT line 53).

**Analog B — `src/Command/TenantMigrateCommand.php` (failure aggregation + summary + slug-keyed model)**

**Slug-keyed failure model + continue-on-failure** (`TenantMigrateCommand.php` lines 94-120) — the runner preserves this exact shape, extended to a richer per-tenant row (D-02: slug · status · migrationsApplied · duration):
```php
/** @var string[] $failures */
$failures = [];
foreach ($tenants as $tenant) {
    try {
        $this->runMigrationsForTenant($tenant, $this->migrationsConfig, $io);
        $io->writeln(sprintf(' <info>✓</info> %s', $tenant->getSlug()));
    } catch (\Throwable $e) {
        $failures[] = $tenant->getSlug();
        $io->writeln(sprintf(' <error>✗</error> %s (%s)', $tenant->getSlug(), $e->getMessage()));
    } finally {
        $this->tenantContext->clear();
        $this->bootstrapperChain->clear();
    }
}
$succeeded = count($tenants) - count($failures);
$io->writeln(sprintf('Completed: %d succeeded, %d failed', $succeeded, count($failures)));
```
Three carry-forwards for the runner:
1. The `✓` / `✗ (message)` per-tenant line is the *atomic block header* (D-01). In parallel mode, buffer the child's streamed log and flush header + log together on completion (no interleaving — Pitfall 14).
2. `count - count($failures)` succeeded-tally + `Completed: N succeeded, M failed` is the *footer*; D-02 supersedes the plain footer with a rich table (slug/status/applied/duration) + total wall-clock. Sequential's plain footer stays as-is.
3. The `try/finally` `tenantContext->clear()` + `bootstrapperChain->clear()` is **in-process state hygiene for the SEQUENTIAL parent only**. The parallel runner does NOT touch `TenantContext`/`BootstrapperChain` — each child process owns its own context (Anti-Pattern V5-A4, ARCHITECTURE.md lines 713-717). The runner only spawns + reaps + aggregates.

**The read-only core that `--dry-run` reuses** (`TenantMigrateCommand.php` lines 125-147, `runMigrationsForTenant()`): computes the plan via `getPlanUntilVersion(...resolveVersionAlias('latest'))` and short-circuits `if (0 === count($plan)) return;` before `getMigrator()->migrate(...)`. `--dry-run` (D-05) flows through the *same* child entrypoint, so this method is **not duplicated** — the child runs the real single-tenant path; the planner adds the `--dry-run` branch (compute plan, do not call `migrate()`) inside this method, gated by a new `--dry-run` option on the command. ONE code path.

---

### `src/Command/TenantMigrateCommand.php` (MODIFIED — command, CLI request-response → batch delegation)

**Analog: itself.** The sequential path (lines 48-147) must stay byte-identical (Success Criterion 1). All changes are *additive*.

**Options block to extend** (`TenantMigrateCommand.php` lines 38-46) — add `--parallel`, `--concurrency`, `--dry-run`, `--format` alongside the existing `--tenant`:
```php
protected function configure(): void
{
    $this->addOption(
        'tenant',
        null,
        InputOption::VALUE_OPTIONAL,
        'Run migrations for a single tenant only',
    );
    // NEW: --parallel (VALUE_NONE), --concurrency (VALUE_REQUIRED default 4, clamp [1,32]),
    //      --dry-run (VALUE_NONE), --format (VALUE_REQUIRED 'txt'|'json')
}
```

**The shared_db guard MUST stay first, before any branch** (`TenantMigrateCommand.php` lines 52-66) — reuse verbatim; D-06 only asks for a parallel-aware *message*. The guard runs **before** the `--parallel` branch so no subprocess is ever spawned under shared_db:
```php
if ('shared_db' === $this->driver) {
    $errorOutput = $output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface
        ? $output->getErrorOutput()
        : $output;
    $errorOutput->writeln(
        '<error>tenancy:migrate is only available with the database_per_tenant driver.</error>'
    );
    return Command::FAILURE;
}
```

**The doctrine/migrations null-config guard stays** (`TenantMigrateCommand.php` lines 68-72):
```php
if (null === $this->migrationsConfig) {
    $io->error('doctrine/migrations is not configured. ...');
    return Command::FAILURE;
}
```

**Tenant enumeration stays** (`TenantMigrateCommand.php` lines 74-92): `--tenant=<slug>` → `[findBySlug($slug)]` (catches `TenantNotFoundException|TenantInactiveException`); else `findAll()`; empty → `SUCCESS`. Discretion: `--parallel` + single `--tenant` → no pool spawned (parallel is a no-op for one tenant — short-circuit to the sequential single-tenant path).

**Delegation branch (NEW):** after the guards + enumeration, `if ($parallel && count($tenants) > 1) { return $this->parallelRunner->run($tenants, $concurrency, $dryRun, $format, $io/$output); }` else fall through to the **unchanged** sequential `foreach` (lines 97-122). ARCHITECTURE.md lines 422-435 confirm: "the sequential loop remains unchanged as the fallback and as the implementation used by each child subprocess."

**Constructor (MODIFIED):** add `private readonly ParallelMigrationRunner $parallelRunner` (or nullable for BC in unit tests) to the existing 6-arg constructor (lines 27-36). The runner is a separate service so the command stays thin.

---

### `tests/Unit/Command/Migration/ParallelMigrationRunnerTest.php` (NEW — test)

**Analog: `tests/Unit/Command/TenantRunCommandTest.php`** — copy the `processFactory` mock pattern (lines 36-58):
```php
$capturedCommand = null;
/** @var Process&MockObject $processMock */
$processMock = $this->createMock(Process::class);
$processMock->method('run')->willReturn(0);          // for parallel: mock start()/isRunning()/getExitCode()
$processMock->method('getExitCode')->willReturn(0);
$processFactory = function (array $command) use ($processMock, &$capturedCommand): Process {
    $capturedCommand = $command;
    return $processMock;
};
```
Adapt for the runner's non-blocking flow: mock `isRunning()` to return `true` once then `false` (so the poll loop reaps), mock `getExitCode()`, and use a **counting factory** to assert at-most-N concurrent processes (Pitfall 13 quality gate — `KernelListenerPriorityPass`-style invariant). The exit-code tests (`TenantRunCommandTest.php` lines 60-79) become the Pitfall-15 "killed child (`getExitCode()===null`) counts as failure" test. Argv assertions (lines 53-57: `assertContains('--tenant=acme', ...)`, `'/app/bin/console'`) carry over directly.

---

## Shared Patterns

### Argv subprocess spawn (NO shell)
**Source:** `src/Command/TenantRunCommand.php` lines 71-81
**Apply to:** `ParallelMigrationRunner`
Tokenized array argv straight to `execve()` — `[\PHP_BINARY, $projectDir.'/bin/console', 'tenancy:migrate', '--tenant='.$slug]`. Never `Process::fromShellCommandline()`, never a shell string (security lineage WR-04; the runner's input is internal/trusted but the array form is the established convention).

### Streaming output capture (Pitfall 17 — no pipe deadlock)
**Source:** PITFALLS.md lines 517-526 + `TenantRunCommand.php` line 85 (`run(callback)` shape)
**Apply to:** `ParallelMigrationRunner`
Accumulate each child's stdout/stderr in a **PHP-memory buffer via the `start()` callback**, never `getOutput()` after exit. `$process->start(fn($type, $chunk) => $buffer .= $chunk)` then poll `isRunning()`. This simultaneously satisfies Pitfall 14 (buffer → flush atomically on completion = no interleaving) and Pitfall 17 (continuous pipe drain = no 64KB deadlock).

### Null-exit-code = FAILURE (Pitfall 15 — divergence from analog)
**Source:** the **anti-pattern** in `TenantRunCommand.php` line 89 (`getExitCode() ?? 0`); the correct rule in PITFALLS.md lines 461-464 + CONTEXT D-07
**Apply to:** `ParallelMigrationRunner` aggregation
`$ec = $process->getExitCode(); $ok = (0 === $ec);` — `null` and any non-zero are failure. Exit `Command::FAILURE` if **any** tenant failed.

### Continue-on-failure + slug-keyed failure aggregation
**Source:** `src/Command/TenantMigrateCommand.php` lines 94-120
**Apply to:** `ParallelMigrationRunner` (rich table per D-02) and the unchanged sequential path
One tenant's failure never stops the pool; collect `$failures[]` keyed by slug; footer tally `succeeded = total - failed`. Phase-26 D-06 lineage (CONTEXT canonical_refs).

### SIGTERM forwarding to children (Pitfall 18)
**Source:** PITFALLS.md lines 548-560 (no existing codebase analog — new pattern)
**Apply to:** `ParallelMigrationRunner` (or the command around the runner call)
```php
if (extension_loaded('pcntl')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$runningProcesses): void {
        foreach ($runningProcesses as $process) { $process->stop(0); }
        exit(1);
    });
}
```
On parent Ctrl-C/SIGTERM, `$process->stop()` all live children. Document that without `pcntl` (Windows/some containers) forwarding is unavailable → recommend `--concurrency=1`. There is **no existing pcntl/signal code in the bundle** — this is net-new (see "No Analog Found").

### DI registration (imperative, inside the database.enabled block)
**Source:** `src/TenancyBundle.php` lines 263-274 (the migrate command registration) — NOT `config/services.php`
**Apply to:** the new `ParallelMigrationRunner` service
```php
if (class_exists(\Doctrine\Migrations\DependencyFactory::class)) {
    $services->set('tenancy.command.migrate', TenantMigrateCommand::class)
        ->args([
            service('tenancy.provider'),
            service('tenancy.bootstrapper_chain'),
            service('tenancy.context'),
            param('tenancy.driver'),
            service('doctrine.dbal.tenant_connection'),
            service('doctrine.migrations.configuration')->nullOnInvalid(),
        ])
        ->tag('console.command');
}
```
Register `tenancy.command.migrate.parallel_runner` (working id) in this **same** `if (class_exists(DependencyFactory))` block (which itself is nested inside `if ($databaseConfig['enabled'])`, line 229). Wire it with `param('kernel.project_dir')` for `$projectDir` and **no** `processFactory` arg (constructor default-null — exactly like `TenantRunCommand` in `config/services.php` lines 125-130, which passes only `tenancy.provider` + `kernel.project_dir` and lets `processFactory` default to null). Then add the runner service as a 7th arg to the migrate command's `->args([...])`. Because this block is gated on `database.enabled: true` and the config validator (TenancyBundle.php lines 134-140) forbids `shared_db + database.enabled: true`, the runner is **structurally never registered under shared_db** — Pitfall 16 is satisfied by wiring, no new compiler pass needed (D-06).

### project_dir parameter
**Source:** `config/services.php` lines 128, 134, 142 + `src/TenancyBundle.php` (Reference pattern)
**Apply to:** runner constructor — inject `param('kernel.project_dir')` as `string $projectDir`, used to build `$projectDir.'/bin/console'`.

---

## No Analog Found

| File / Concern | Role | Data Flow | Reason |
|----------------|------|-----------|--------|
| SIGTERM child-forwarding (`pcntl_signal`) | signal handling | event-driven | No `pcntl`/signal-handler code exists anywhere in `src/`. New pattern — copy the PITFALLS.md lines 548-560 skeleton; guard with `extension_loaded('pcntl')`. |
| Non-blocking `Process::start()` + `isRunning()` sliding-window poll | subprocess pool | batch | Existing commands only use **blocking** `$process->run()` (`TenantRunCommand` line 85). The non-blocking poll loop is net-new — base it on STACK.md lines 204-229 + PITFALLS.md lines 382-397. |
| Aggregate JSON document emission (D-03 shape `{"tenants":[...],"summary":{...}}`) | output formatting | transform | No JSON-output command exists in the bundle (all existing commands are human/`SymfonyStyle` only). New pattern — build the array, `json_encode`, write to stdout; suppress human blocks + table in `--format=json` (D-04); operational warnings → stderr via `ConsoleOutputInterface::getErrorOutput()` (the same stderr accessor already used in `TenantMigrateCommand.php` lines 58-60). |
| Rich summary table (slug · status · applied · duration + wall-clock footer) | output formatting | transform | Existing commands print plain `✓`/`✗` lines + a one-line `Completed:` footer (no `SymfonyStyle::table()` usage in the command layer). New — use `SymfonyStyle::table()` / `createTable()`; D-02. |

> All four are *additive* and small. None require a third-party library (STACK.md: net-zero new prod deps; `symfony/process` already required; reject list lines 313-328).

## Metadata

**Analog search scope:** `src/Command/` (all 5 commands + `Install/`), `config/services.php`, `src/TenancyBundle.php` (imperative DI), `tests/Unit/Command/`, `tests/Integration/Command/`, `src/Provider/TenantProviderInterface.php`.
**Files scanned:** 7 source + 4 test/config (TenantMigrateCommand, TenantRunCommand, TenantProviderInterface, config/services.php, TenancyBundle.php, TenantRunCommandTest, TenantMigrateCommandTest).
**Pattern extraction date:** 2026-06-26
**Project conventions honored:** `declare(strict_types=1)` everywhere; Doctrine/migrations guarded by `class_exists(DependencyFactory::class)`; PHP 8.2+ constructor property promotion + readonly; no new prod deps.
