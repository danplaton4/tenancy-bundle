# Phase 31: Parallel Migrations - Context

**Gathered:** 2026-06-26
**Status:** Ready for planning

<domain>
## Phase Boundary

Deliver **ISOL-07 through ISOL-12**: a `--parallel` mode on the existing `tenancy:migrate` command that runs per-tenant Doctrine migrations concurrently via a **bounded subprocess worker pool** (`symfony/process`, already a production `require`). Sequential remains the default — `tenancy:migrate` with no flag behaves **byte-for-byte identically to v0.4** (the existing in-process loop is untouched).

**In scope:**
- `--parallel` flag → bounded subprocess pool; `--concurrency=N` (default 4, hard cap 32).
- `--dry-run` → reports what each tenant would migrate without applying (flows through whichever execution mode is selected).
- `--format=json` → machine-readable per-tenant results (orthogonal to `--parallel`; works in both modes).
- Atomic per-tenant output (no interleaving), exit-code aggregation (null/killed subprocess = failure), continue-on-failure preserved, final summary table.
- shared_db driver guard for the parallel path.
- A `ParallelMigrationRunner` extracted from / alongside `TenantMigrateCommand`.

**Out of scope (own phases / deferred):**
- Maintenance mode (Phase 32 / OPS-01), Health checks (Phase 33 / OPS-02), Ops docs incl. the `parallel-migrations.md` page (Phase 34 / DOC-21 — the *docs* for this feature land in 34, not here).
- Migration checkpoint/resume & auto-rollback — explicitly **Out of Scope** (REQUIREMENTS.md): Doctrine Migrations is idempotent, so re-run-on-failure is the supported recovery. Continue-on-failure + re-run is the contract.
- Any third-party process-pool library (`spatie/async`, `amphp/parallel`, `graze/parallel-process`) — `symfony/process` + a ~20-line sliding-window poll covers it (net-zero new prod deps).

</domain>

<decisions>
## Implementation Decisions

### Live output & progress (human / non-JSON mode)
- **D-01: Block-on-completion output.** When each tenant's subprocess finishes, flush its full output as **one atomic block** — a `✓`/`✗` header line for the tenant + that tenant's captured migration log — in **completion order**. No live progress bar. This is the natural parallel extension of today's sequential `✓`/`✗` lines (lowest surprise). Atomicity (no interleaving) is the hard requirement from Success Criterion 3 / ISOL-09; buffering each subprocess's streamed output and flushing the whole block on completion satisfies it.
- **D-02: Rich summary table at the end.** Columns: **tenant slug · status (✓/✗) · migrations applied · duration**. Footer: `N succeeded, M failed` + **total wall-clock**. Rationale: the phase's value proposition is cutting fleet-wide migration time, so per-tenant duration + total wall-clock make the speedup visible and surface slow tenants. (Supersedes the minimal "Completed: N succeeded, M failed" + failed-list that sequential prints today — sequential's plainer output stays as-is; the rich table is the parallel summary.)

### `--format=json` output (ISOL-12)
- **D-03: Single aggregate JSON object, emitted once after all workers finish.** Shape:
  `{"tenants":[{"slug","status","migrationsApplied","durationMs","error?"}],"summary":{"succeeded","failed","total","wallClockMs"}}`.
  Easiest for CI / `jq` / `json.loads` (vs NDJSON streaming, which was considered and rejected for harder CI consumption).
- **D-04: JSON mode = pure machine output.** In `--format=json`, the human block-on-completion output (D-01) and the summary table (D-02) are **suppressed**; stdout carries **only** the JSON document. Operational warnings go to **stderr**. The **exit code** still carries pass/fail (FAILURE if any tenant failed — D-07). Per-tenant objects carry status / count / duration / error message — **not** the full captured migration log (keeps the document bounded).

### `--dry-run` execution path (ISOL-10)
- **D-05: `--dry-run` flows through the execution mode — ONE code path.** `--dry-run` is just a flag handed to the worker. `--parallel --dry-run` runs the same bounded pool (each subprocess does the single-tenant dry-run: compute the migration plan, don't apply); `--dry-run` alone runs sequentially in-process. Output/JSON shapes are **identical to a live run** ("would apply N" instead of "applied N"). Benefit: dry-run surfaces per-tenant boot/connectivity failures **exactly** as a real run would, and there's no second dry-run-only code path to maintain. Matches ISOL-10's explicit `--parallel --dry-run` pairing.

### shared_db guard (ISOL-11)
- **D-06: Reuse the existing hard-refuse; no new compiler pass.** `--parallel` inherits `TenantMigrateCommand`'s existing driver guard: under `shared_db` → `Command::FAILURE` with a **parallel-aware message** (e.g. "parallel migration is not supported under the shared_db driver"). The guard MUST run **before** the parallel branch so **no subprocess is ever spawned**. No new compiler pass is added: the command is **already unregistered** under shared_db (DI registers `TenantMigrateCommand` only when `database.enabled: true`, and the config schema rejects `shared_db + database.enabled: true`), so the research Pitfall-16 quality gate ("parallel migrate not wired under shared_db") is **already satisfied structurally**. "Fall back to sequential" was rejected: sequential migrate also can't run on shared_db (no per-tenant DBs), so it would just fail with extra steps.

### Failure / exit-code semantics (locked by Success Criteria + carried from sequential)
- **D-07: Continue-on-failure + exit aggregation.** One tenant's failure does not stop the pool; all tenants are attempted. Exit `Command::FAILURE` if **any** tenant failed, else `SUCCESS`. A **null exit code** from a killed/timed-out/crashed subprocess is counted as **FAILURE, never success** (Pitfall 15). This mirrors the sequential command's continue-on-failure model (Phase 26 D-06 lineage).

### Claude's Discretion (sensible defaults locked here — confirmed with user)
- **Worker entrypoint:** each subprocess invokes the existing **public** single-tenant path `bin/console tenancy:migrate --tenant=<slug>` (reuse, observable in process lists, `--tenant` already exists) — **not** a hidden internal worker command. (Per research; reuses the established `TenantRunCommand` argv-spawn shape.)
- **`--parallel` + `--tenant=<slug>` interaction:** a single tenant → **no pool spawned**; `--parallel` is a no-op for one tenant.
- **No per-subprocess timeout** — never kill a migration mid-flight (mirrors `TenantRunCommand`'s `setTimeout(null)`). Orphan cleanup on operator Ctrl-C via **SIGTERM forwarding** to live children (Pitfall 18).
- **`--concurrency` clamping:** clamp to `[1, 32]`; a value > 32 clamps to 32 **with a notice**; non-numeric or < 1 → input error.
- Exact class name/namespace of the extracted runner (`ParallelMigrationRunner` is a working name), its method surface, the per-worker streaming-callback wiring, and the precise sliding-window poll cadence — all left to research/planning. The **process-factory test seam** (below) is the one hard constraint on the design.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Requirements + locked decisions
- `.planning/REQUIREMENTS.md` §"Parallel Migrations (epic ISOL-07)" — ISOL-07..ISOL-12 acceptance criteria; the "Out of Scope" table (no checkpoint/resume, no third-party pool lib, no `symfony/lock`).
- `.planning/ROADMAP.md` §"Phase 31: Parallel Migrations" — Goal + the 5 Success Criteria (the authoritative TRUE-conditions this phase must satisfy).

### Research (this milestone — HIGH confidence, grounded in live v0.4.1 source reads)
- `.planning/research/SUMMARY.md` §"Phase 31: ISOL-07" — "fully specified, plan immediately, no separate research phase." Names the components, addresses ISOL-07a–f, lists avoided Pitfalls 13–19.
- `.planning/research/PITFALLS.md` — the parallel-migration pitfalls: **13** (unbounded concurrency → connection exhaustion; default 4 / cap 32 / sliding-window), **14** (interleaved output), **15** (lost/null exit code = failure), **16** (shared_db double-migration), **17** (64KB pipe deadlock → streaming callback, not `getOutput()` post-exit), **18** (orphaned processes → SIGTERM forwarding), **19** (no actionable failure report).
- `.planning/research/ARCHITECTURE.md` — `ParallelMigrationRunner` extraction; "strictly additive to the v0.4.1 graph; `TenantContextOrchestrator`/`BootstrapperChain` unchanged."
- `.planning/research/STACK.md` — why `symfony/process` (already required) is sufficient; the reject list.

### Direct command analog — Phase 26 (SHARE-02), the established CLI convention
- `.planning/phases/26-tenancy-shared-resync-command/26-CONTEXT.md` — D-01 (`--tenant` `VALUE_OPTIONAL`, else `findAll()`), D-06 (per-tenant `try/catch/finally`, `✓`/`✗`, `Completed:` summary, FAILURE-if-any). The migration command's failure model that this phase preserves.

No external (non-`.planning`) specs or ADRs — requirements + research + the source files in code_context fully capture the design.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `src/Command/TenantMigrateCommand.php` — **the command to extend** (do NOT rewrite the sequential path; the no-flag behavior must stay byte-identical, Success Criterion 1). Already has: `--tenant` `VALUE_OPTIONAL` filter (ISOL-11e), the **shared_db early-FAILURE guard** (lines 57–66 — D-06 reuses this), the `doctrine/migrations` null-config guard, the per-tenant `try/catch/finally` with `TenantContext::clear()` + `BootstrapperChain::clear()`, and `runMigrationsForTenant()` which computes the plan via `getPlanUntilVersion('latest')` and short-circuits on an empty plan (the read-only core that `--dry-run` reuses — D-05).
- `src/Command/TenantRunCommand.php` — **the subprocess pattern to mirror.** Its `\Closure(list<string>): Process` **`processFactory` test seam** (constructor arg, default null) is the exact mechanism for the research "mock process factory" quality gate (Success Criterion 2: assert at-most-N concurrent). Also: array-argv `new Process($command)` (NO shell — `[PHP_BINARY, projectDir.'/bin/console', ...tokens, '--tenant='.$slug]`), `setTimeout(null)`, streaming `$process->run(callback)`, and `getExitCode() ?? 0` exit handling. The parallel runner needs `Process::start()` + an `isRunning()` poll loop instead of blocking `run()`, but the spawn/argv/exit shape is identical.
- `src/Provider/TenantProviderInterface.php` (`findAll()`, `findBySlug()`) — tenant enumeration source, same as today.

### Established Patterns
- Command registration: `#[AsCommand]` + constructor DI, registered in **`config/services.php`** alongside the other `tenancy:*` commands; only wired when `database.enabled: true` / Doctrine present (`class_exists`/`interface_exists` guards). The process factory should be injectable (nullable, default-null) exactly like `TenantRunCommand`'s, for testability.
- Driver mode read from the `tenancy.driver` container parameter (already injected into `TenantMigrateCommand` as `$driver`).
- Structured failure reporting reuses the slug-keyed model already in the command.

### Integration Points
- The new `ParallelMigrationRunner` is a **plain service** (no compiler pass needed — D-06). It receives the process factory + project dir, spawns one `bin/console tenancy:migrate --tenant=<slug>` child per tenant through a bounded sliding-window pool, buffers each child's streamed stdout/stderr, and on each completion flushes the atomic block (D-01) / records the per-tenant JSON row (D-03).
- **Subprocess output capture MUST be streaming** (accumulate via the `run`/`start` callback), never `getOutput()` after exit — Pitfall 17 (64KB pipe deadlock).
- The child process is the existing single-tenant migrate path, so each child gets its own kernel boot, `TenantContext`, and DBAL connection — the **only** safe way to parallelize (never in-process; research invariant).

</code_context>

<specifics>
## Specific Ideas

The consistent steer throughout discussion was **consistency with the existing bundle + reuse over reinvention**: extend `tenancy:migrate` (don't fork a new command), reuse the `TenantRunCommand` `processFactory` seam and argv-spawn shape, reuse the existing shared_db guard, and keep one code path for dry-run. The only place the user chose *added* richness over the minimal status quo was the **summary table** (D-02: status + migrations-applied + duration + total wall-clock) — because making the speedup visible is the point of the feature.

</specifics>

<deferred>
## Deferred Ideas

- **Migration checkpoint / resume** — resume a partially-applied parallel run. Already tracked in REQUIREMENTS.md "Future Requirements (v0.6+ / by demand)"; out of scope here (idempotent re-run is the supported recovery).
- **NDJSON streaming `--format`** — considered for `--format=json` and rejected (D-03) in favor of a single aggregate object for easier CI consumption. If a live-dashboard consumer ever needs it, it could be a future `--format=ndjson`.
- **Quiet/progress-counter and ProgressBar live views** — considered for output (D-01) and not chosen; block-on-completion was preferred. A `--quiet`-style compact counter could be a future ergonomics add if fleets grow large enough to make per-tenant blocks noisy.

None of the above are scope creep into Phase 31 — discussion stayed within the ISOL-07..12 boundary.

</deferred>

---

*Phase: 31-parallel-migrations*
*Context gathered: 2026-06-26*
