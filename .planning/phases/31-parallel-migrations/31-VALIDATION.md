---
phase: 31
slug: parallel-migrations
nyquist_compliant: false
wave_0_complete: true
status: complete
created: 2026-07-06
---

# Phase 31 Parallel Migrations — Validation (Retrospective)

> This is a retrospective artifact created in Phase 34 (D-10 backfill) to make the v0.5
> phase set (31/32/33) uniform. Phase 31 completed and was verified before the advisory-only
> policy was written down explicitly.

**Status:** Complete (verified 2026-06-26 via `31-VERIFICATION.md`)

**Policy:** This artifact is advisory only — see
`docs/contributor-guide/test-infrastructure.md` for the full Nyquist `VALIDATION.md`
enforcement policy. A missing `VALIDATION.md` is not a phase failure; the live green
PHPUnit suite is the real gate. Phase 31 shipped 966+ passing tests (794 at verification
time) and was verified PASSED by `31-VERIFICATION.md` on 2026-06-26.

---

## Summary

Phase 31 introduced parallel `tenancy:migrate` via a bounded subprocess worker pool
(`ParallelMigrationRunner`). All 6 ISOL-07..12 requirements were SATISFIED and verified
on 2026-06-26. Full evidence is in `31-VERIFICATION.md`.

| Requirement | Status |
|-------------|--------|
| ISOL-07 | SATISFIED — `tenancy:migrate --parallel` bounded subprocess pool; sequential unchanged |
| ISOL-08 | SATISFIED — concurrency bounded by `--concurrency=N` (default 4, hard cap 32) |
| ISOL-09 | SATISFIED — atomic per-tenant output; null/killed exit = failure; continue-on-failure |
| ISOL-10 | SATISFIED — `--dry-run` reports would-migrate without applying |
| ISOL-11 | SATISFIED — `shared_db` driver refuses with clear message before any subprocess spawned |
| ISOL-12 | SATISFIED — `--format=json` emits machine-readable per-tenant results |

No `VALIDATION.md` was authored at the time Phase 31 shipped — consistent with the
discovery-only stance that is now the explicit policy for v0.5.
