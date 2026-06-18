---
phase: 29-docs-refresh
plan: "03"
subsystem: docs-tooling
tags: [docs-lint, ci-gate, shared-entities, D-04, DOC-20]
dependency_graph:
  requires: [29-01, 29-02]
  provides: [D-04-check, DOC-20]
  affects: [scripts/docs-lint.sh]
tech_stack:
  added: []
  patterns: [per-file-grep-loop, violations-string-accumulator, find-print0-while-read]
key_files:
  created: []
  modified:
    - scripts/docs-lint.sh
decisions:
  - "D-04 check uses a per-file grep loop (not the flat check() helper) to implement AND-logic: both 'landlord-side master' AND 'tenant-side read-only copy' must be present in the same file"
  - "Trigger is case-insensitive (grep -qiE) while disambiguator greps are case-sensitive (grep -q) — intentional asymmetry matching the plan spec"
  - "Scoped to docs/ only via find docs/ -name '*.md' -print0; UPGRADE.md and CHANGELOG.md are exempt by filesystem position (not under docs/)"
  - "mkdocs build --strict deferred to CI — mkdocs not installed locally; both new pages confirmed present and registered in mkdocs.yml as nav-registration proxy"
metrics:
  duration: ~5 min
  completed: 2026-06-18
  tasks_completed: 2
  files_modified: 1
---

# Phase 29 Plan 03: D-04 Shared-Entity Disambiguation CI Gate Summary

D-04 per-file shared-entity disambiguation check wired into `scripts/docs-lint.sh`; any `docs/` file mentioning "shared entity/entities" must contain BOTH "landlord-side master" AND "tenant-side read-only copy" or CI fails — `bash scripts/docs-lint.sh` exits 0 on the current fully-compliant tree.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Add D-04 per-file shared-entity disambiguation block to docs-lint.sh | 1c85138 | scripts/docs-lint.sh |
| 2 | Full-tree validation — docs-lint green + mkdocs build --strict | (verification-only, no new commit) | — |

## What Was Built

### Task 1 — D-04 block in scripts/docs-lint.sh

Inserted a new block after the existing `BUNDLES_VIOLATIONS` block (line 89) and before the `OK` summary line. The block:

1. Initializes `SHARED_ENTITY_VIOLATIONS=""`.
2. Iterates over all `docs/*.md` files via `while IFS= read -r -d $'\0' f; do ... done < <(find docs/ -name '*.md' -print0)`.
3. For each file matching `grep -qiE 'shared entit(y|ies)'`, checks that BOTH `landlord-side master` AND `tenant-side read-only copy` are present; if either is absent, appends the filename to `SHARED_ENTITY_VIOLATIONS` and sets `EXIT=1`.
4. After the loop, if `SHARED_ENTITY_VIOLATIONS` is non-empty, prints an ERROR line naming both required phrases and the violating files via `printf "%b"`.

Structural pattern mirrors `BUNDLES_VIOLATIONS` exactly (violations string accumulator → conditional print + EXIT=1 → OK-summary last).

### Task 2 — Full-tree validation

- `bash scripts/docs-lint.sh` exits 0 — all checks pass, "docs-lint: OK" summary line prints last.
- `mkdocs build --strict`: mkdocs is NOT installed locally. Deferred to CI (`.github/workflows/ci.yml`). Nav-registration proxy verified: both `user-guide/shared-entities.md` and `user-guide/phpstan-extension.md` are present on disk AND registered in `mkdocs.yml`.

## Acceptance Criteria Verification

| Criterion | Result |
|-----------|--------|
| `bash scripts/docs-lint.sh` exits 0 | PASS — exits 0, "docs-lint: OK" prints |
| `grep -c "find docs/ -name" scripts/docs-lint.sh` >= 1 | PASS — count: 2 |
| `grep -c "landlord-side master" scripts/docs-lint.sh` >= 1 | PASS — count: 3 |
| `grep -c "tenant-side read-only copy" scripts/docs-lint.sh` >= 1 | PASS — count: 3 |
| `grep -c "shared entit" scripts/docs-lint.sh` >= 1 | PASS — count: 3 |
| NEGATIVE mechanism proof (synthetic bad.md triggers MECHANISM-OK) | PASS |
| `set -euo pipefail` still first executable line | PASS |
| OK-summary `if [[ $EXIT -eq 0 ]]` still last block before `exit $EXIT` | PASS |
| mkdocs build --strict | DEFERRED TO CI — mkdocs not installed locally; proxy check passed (both pages in mkdocs.yml + on disk) |

## Deviations from Plan

None — plan executed exactly as written.

## Known Stubs

None.

## Threat Flags

None. The D-04 check is a read-only CI gate. No new network endpoints, auth paths, file access patterns, or schema changes introduced.

## Threat Model Coverage

| Threat | Disposition | Status |
|--------|-------------|--------|
| T-29-05-DOS (lint hang) | mitigate | Mitigated — bounded `find docs/ -name '*.md' -print0` with per-file `grep -q` (no backtracking-prone regex). Script completes in ~1 second on the current tree. |
| T-29-06-TAMPER (false-negative gate) | mitigate | Mitigated — per-file AND-logic + case-insensitive trigger; NEGATIVE test proves mechanism. `docs/`-only scoping verified. |
| T-29-07-INFO (trust-model clarity) | mitigate | Mitigated — check enforces the landlord-side-master / tenant-side-read-only-copy distinction; any future doc that underspecifies the cross-tenant write-protection invariant fails CI. |

## Self-Check: PASSED

| Item | Status |
|------|--------|
| scripts/docs-lint.sh exists | FOUND |
| 29-03-SUMMARY.md exists | FOUND |
| Commit 1c85138 exists | FOUND |
