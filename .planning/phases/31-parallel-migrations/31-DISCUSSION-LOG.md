# Phase 31: Parallel Migrations - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-06-26
**Phase:** 31-parallel-migrations
**Areas discussed:** Live output & progress, JSON output shape, Dry-run execution path, shared_db guard

---

## Live output & progress

### Q1 — Primary live view while the worker pool runs (human/non-JSON mode)

| Option | Description | Selected |
|--------|-------------|----------|
| Block-on-completion | Each finished tenant's full output flushed as one atomic block (✓/✗ header + captured log) in completion order; no progress bar; final summary table at end | ✓ |
| Quiet + live counter | Single updating status line ("running 4 · done 12/40 · failed 1"); per-tenant output only for failures; summary table lists all | |
| ProgressBar + blocks | Symfony ConsoleSection/ProgressBar advancing as tenants complete, plus per-tenant blocks below | |

**User's choice:** Block-on-completion.
**Notes:** Natural parallel extension of today's sequential ✓/✗ lines; satisfies the atomic-per-tenant (no interleaving) hard requirement via buffer-then-flush.

### Q2 — Contents of the final summary table

| Option | Description | Selected |
|--------|-------------|----------|
| Rich (status + count + duration) | Columns: slug · status · migrations applied · duration; footer "N succeeded, M failed" + total wall-clock | ✓ |
| Rich + failure reason | Above plus a truncated error-message column for failed tenants | |
| Minimal | Today's ✓/✗ list + "Completed: N succeeded, M failed" + failed list; no columns, no timing | |

**User's choice:** Rich (status + count + duration).
**Notes:** Per-tenant + total wall-clock make the speedup visible and surface slow tenants — the point of the feature.

---

## JSON output shape

### Q1 — `--format=json` shape and stdout interaction

| Option | Description | Selected |
|--------|-------------|----------|
| Aggregate object at end | One JSON doc after all workers finish: `{tenants:[{slug,status,migrationsApplied,durationMs,error?}], summary:{succeeded,failed,total,wallClockMs}}`; pure JSON on stdout; exit code carries pass/fail | ✓ |
| NDJSON streaming | One JSON object per line as each tenant completes, plus a final summary line | |
| Aggregate array only | Top-level array of per-tenant objects, no summary wrapper | |

**User's choice:** Aggregate object at end.
**Notes:** Easiest for CI/`jq`/`json.loads`. Corollary locked without a separate question: JSON mode suppresses human output (stdout = JSON only), warnings to stderr, per-tenant objects carry status/count/duration/error (not the full migration log).

---

## Dry-run execution path

### Q1 — How `--dry-run` executes relative to `--parallel`

| Option | Description | Selected |
|--------|-------------|----------|
| Flows through execution mode | `--dry-run` is a flag passed to the worker; `--parallel --dry-run` uses the pool, `--dry-run` alone runs in-process; one code path; output identical to a live run; surfaces per-tenant boot/connectivity failures | ✓ |
| Always in-process sequential | `--dry-run` ignores `--parallel` (with a notice); always a quick read-only in-process scan; simpler but a separate dry-run path | |

**User's choice:** Flows through execution mode.
**Notes:** One code path; matches ISOL-10's explicit `--parallel --dry-run` pairing.

---

## shared_db guard

### Q1 — How the shared_db guard for `--parallel` is satisfied (ISOL-11)

| Option | Description | Selected |
|--------|-------------|----------|
| Reuse existing hard-refuse | `--parallel` inherits the existing driver guard: shared_db → Command::FAILURE with a parallel-aware message; guard runs before the parallel branch; no new compiler pass (command already unregistered under shared_db) | ✓ |
| Add compiler-pass guard too | Belt-and-suspenders compile-time guard + dedicated test, on top of the runtime FAILURE | |
| Fall back to sequential | Drop to sequential with a warning (caveat: sequential also fails on shared_db here) | |

**User's choice:** Reuse existing hard-refuse.
**Notes:** Existing DI registration (command only wired when `database.enabled: true`; config rejects `shared_db + database.enabled`) already provides the structural guarantee the research Pitfall-16 quality gate asks for. "Fall back to sequential" rejected because sequential migrate can't run on shared_db either.

---

## Claude's Discretion

Confirmed defaults the user accepted without separate questions (captured in CONTEXT.md `### Claude's Discretion`):
- Worker entrypoint = existing public `tenancy:migrate --tenant=<slug>` single-tenant path (not a hidden internal command).
- `--parallel` + `--tenant=<slug>` → single tenant, no pool spawned (parallel is a no-op for one tenant).
- No per-subprocess timeout (never kill a migration mid-flight); orphan cleanup via SIGTERM forwarding on Ctrl-C.
- `--concurrency` clamped to [1, 32] (>32 → clamp with notice; non-numeric/<1 → error).
- Exact runner class name/namespace/method surface, streaming-callback wiring, and poll cadence — left to research/planning; the `processFactory` test seam is the one hard design constraint.

## Deferred Ideas

- Migration checkpoint / resume (already in REQUIREMENTS.md Future / v0.6+).
- NDJSON `--format` variant (considered, rejected for now in favor of aggregate object).
- Quiet/progress-counter and ProgressBar live views (considered, not chosen; possible future ergonomics add).
