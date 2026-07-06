---
phase: 34-ops-docs-carry-forward
plan: "04"
subsystem: docs/governance
tags: [docs, governance, nyquist, validation, advisory-policy]
dependency_graph:
  requires: []
  provides: [GOV-02-policy, phase-31-validation-backfill]
  affects: [docs/contributor-guide/test-infrastructure.md, .planning/phases/31-parallel-migrations/31-VALIDATION.md]
tech_stack:
  added: []
  patterns: [advisory-policy-documentation, planning-artifact-backfill]
key_files:
  created:
    - .planning/phases/31-parallel-migrations/31-VALIDATION.md
  modified:
    - docs/contributor-guide/test-infrastructure.md
decisions:
  - "D-08: Nyquist VALIDATION.md is advisory-only; the live green PHPUnit suite is the real phase gate"
  - "D-09: Policy written in docs/contributor-guide/test-infrastructure.md (appended section, no new page needed)"
  - "D-10: Phase 31 VALIDATION.md backfilled as retrospective artifact for v0.5 set uniformity"
metrics:
  duration: "~12 minutes"
  completed: "2026-07-06"
  tasks_completed: 2
  files_modified: 1
  files_created: 1
---

# Phase 34 Plan 04: GOV-02 Nyquist Policy + Phase 31 Validation Backfill Summary

Documented the Nyquist `VALIDATION.md` advisory-only policy explicitly in the contributor guide and backfilled the Phase 31 VALIDATION.md so the v0.5 phase set (31/32/33) is uniform.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Add Nyquist VALIDATION.md policy note to contributor guide | f1fb735 | docs/contributor-guide/test-infrastructure.md |
| 2 | Backfill Phase 31 VALIDATION.md for artifact consistency | d3c0ad1 | .planning/phases/31-parallel-migrations/31-VALIDATION.md |

## What Was Built

### Task 1: Nyquist VALIDATION.md Advisory-Only Policy Note

Appended a new `## Nyquist Validation Artifacts (VALIDATION.md)` section to
`docs/contributor-guide/test-infrastructure.md` after the "Running Tests by Category" section.

The section explicitly states (per D-08):
- `VALIDATION.md` files in v0.5 phase directories are **advisory only** — the live green PHPUnit
  suite is the real phase gate
- `nyquist_validation: true` in `.planning/config.json` governs the Nyquist *discovery workflow*
  (surfacing gaps for human review), not a blocking gate
- Phases shipping a green PHPUnit suite are complete regardless of whether a `VALIDATION.md` was
  authored
- This was the de-facto policy from v0.4 (Phase 31 shipped without one), now made explicit for v0.5
- Cross-references `32-VALIDATION.md` and `33-VALIDATION.md` as format examples

**Acceptance criteria met:**
- Section mentions `VALIDATION.md` — yes
- Contains `advisory` / `discovery workflow` — yes
- States the live green suite is the real gate — yes
- References `nyquist_validation` config flag — yes
- `bash scripts/docs-lint.sh` exits 0 — yes (verified)

### Task 2: Phase 31 VALIDATION.md Backfill

Created `.planning/phases/31-parallel-migrations/31-VALIDATION.md` as a minimal retrospective
artifact mirroring the Phase 32 frontmatter schema:

```yaml
nyquist_compliant: false
wave_0_complete: true
status: complete
```

Body records:
- Phase 31 completed and was verified 2026-06-26 via `31-VERIFICATION.md`
- ISOL-07..12 satisfaction table
- Advisory-only policy note with cross-reference to the contributor guide

**Acceptance criteria met:**
- File exists — yes
- `status: complete` in frontmatter — yes
- Contains `retrospective` and `advisory` — yes
- v0.5 phase set (31/32/33) all have VALIDATION.md — yes

## Verification Results

```
docs-lint.sh: OK — no stale v0.1 terms in docs/ or tenancy:init command
PHPUnit: 966 tests, 3821 assertions, 2 skipped (pre-existing), 0 failures
PHPStan: OK No errors
```

## Deviations from Plan

None — plan executed exactly as written. Both tasks required only two file operations (one edit,
one create) with no blocking issues.

## Known Stubs

None. This plan is documentation-only; no data flows, no UI rendering, no stubs.

## Threat Flags

No new security surface introduced. This plan modifies only documentation and a planning artifact;
no runtime code, no credentials, no network endpoints.

## Self-Check: PASSED

- [x] `docs/contributor-guide/test-infrastructure.md` exists and contains VALIDATION.md policy
- [x] `.planning/phases/31-parallel-migrations/31-VALIDATION.md` exists with `status: complete`
- [x] Commit f1fb735 exists (Task 1)
- [x] Commit d3c0ad1 exists (Task 2)
- [x] `bash scripts/docs-lint.sh` exits 0
- [x] PHPUnit suite green (966/966 tests, 0 failures)
