---
phase: 22-docs-refresh
plan: 04
subsystem: docs
tags: [docs, examples, mkdocs, file-moves, cross-links]

requires:
  - phase: 21-demo-app
    provides: examples/saas/ runnable demo + canonical examples/saas/README.md
provides:
  - docs/examples/ top-level Examples directory (new home for example pages)
  - docs/examples/saas-subdomain.md (moved up one level, history preserved)
  - docs/examples/api-header.md (moved up one level, history preserved)
  - docs/examples/saas-demo.md (new thin walkthrough pointing at canonical demo README)
  - 4 cross-references in user-guide/ updated to ../examples/ paths
  - 6 internal See-Also links in moved files corrected to ../user-guide/<page>.md
affects:
  - Plan 22-06 (mkdocs nav reorg — adds the new top-level Examples section + roadmap)
  - All future docs phases that link to example pages

tech-stack:
  added: []
  patterns:
    - "Move + atomic cross-ref update in one plan to keep mkdocs --strict green"
    - "Thin walkthrough page: intro + value bullets + 1-line install + ASCII teaser + canonical-link"

key-files:
  created:
    - docs/examples/saas-demo.md
  modified:
    - docs/examples/saas-subdomain.md (renamed from docs/user-guide/examples/, 3 internal links fixed)
    - docs/examples/api-header.md (renamed from docs/user-guide/examples/, 3 internal links fixed)
    - docs/user-guide/shared-db.md (L178 cross-ref)
    - docs/user-guide/index.md (L27, L28 cross-refs)
    - docs/user-guide/database-per-tenant.md (L260 cross-ref)

key-decisions:
  - "Use git mv (not cp+rm) so renames are detected by git — 100% similarity confirmed"
  - "Fold internal See-Also link fixes inside the moved files into Task 2's cross-ref commit (deviation Rule 3: the moves broke ../<page>.md links, which would fail mkdocs --strict; not in plan's Task 1 scope by design — Task 1 enforced byte-identical content)"
  - "saas-demo.md page is 69 lines (well under the 100-line thin cap); does not duplicate the 181-line canonical demo README"
  - "saas-demo.md cross-link to mailer-bootstrapper.md will resolve once Plan 22-03 lands in the same wave; mkdocs --strict is run from Plan 22-06 after the whole phase lands"

patterns-established:
  - "Atomic file-move + cross-ref-update pattern: one plan owns BOTH so mkdocs --strict never sees a broken intermediate state"
  - "Thin landing page: defer the full walkthrough to a single canonical doc via one prominent link"

requirements-completed: [DOC-19]

duration: ~8min
completed: 2026-05-28
---

# Phase 22 Plan 04: Examples reorg + thin saas-demo Summary

**Reorganized docs/user-guide/examples/ to docs/examples/, atomically updated 4 cross-references and 6 internal See-Also links, and added a 69-line saas-demo.md that points at the canonical examples/saas/README.md without duplicating it.**

## Performance

- **Duration:** ~8 min
- **Started:** 2026-05-28T19:14:00Z (approx.)
- **Completed:** 2026-05-28T19:22:00Z (approx.)
- **Tasks:** 3
- **Files modified:** 6 (2 renamed, 1 created, 3 cross-ref edits)

## Accomplishments

- Moved `docs/user-guide/examples/saas-subdomain.md` -> `docs/examples/saas-subdomain.md` (git rename, 100% similarity)
- Moved `docs/user-guide/examples/api-header.md` -> `docs/examples/api-header.md` (git rename, 100% similarity)
- Removed the now-empty `docs/user-guide/examples/` directory
- Updated 4 external cross-references in shared-db.md / user-guide/index.md (×2) / database-per-tenant.md to `../examples/...` paths
- Fixed 6 internal See-Also links inside the moved files (`../<page>.md` -> `../user-guide/<page>.md`)
- Created `docs/examples/saas-demo.md` (69 lines) — thin demo intro with intro, value bullets, quick-start, ASCII teaser, link to canonical `examples/saas/README.md`, and See-also cross-links
- Verified `grep -rnE '\(examples/saas-subdomain\.md\)|\(examples/api-header\.md\)|user-guide/examples/' docs/` returns ZERO matches

## Task Commits

Each task was committed atomically:

1. **Task 1: Move examples to docs/examples/ (D-07)** — `5545e94` (docs)
2. **Task 2: Update 4 cross-references + 6 internal See-Also links** — `26bfeec` (docs)
3. **Task 3: Add docs/examples/saas-demo.md thin walkthrough (D-06/D-08)** — `a0886e8` (docs)

## Files Created/Modified

- `docs/examples/saas-demo.md` (69 lines, NEW) — thin landing page; intro + 5 value bullets + 1-line quick-start + ASCII tenant-pair teaser + link to canonical `examples/saas/README.md` + 4 See-also cross-links
- `docs/examples/saas-subdomain.md` (renamed from `docs/user-guide/examples/saas-subdomain.md`, 100% git similarity; 3 internal See-Also links updated from `../<page>.md` to `../user-guide/<page>.md`)
- `docs/examples/api-header.md` (renamed from `docs/user-guide/examples/api-header.md`, 100% git similarity; 3 internal See-Also links updated)
- `docs/user-guide/shared-db.md` — L178 `examples/api-header.md` -> `../examples/api-header.md`
- `docs/user-guide/index.md` — L27 + L28 `examples/<page>.md` -> `../examples/<page>.md`
- `docs/user-guide/database-per-tenant.md` — L260 `examples/saas-subdomain.md` -> `../examples/saas-subdomain.md`

## Decisions Made

- saas-demo.md kept at 69 lines — well under the 100-line "thin" cap, leaving room for the canonical 181-line `examples/saas/README.md` to remain the single source of truth.
- The ASCII teaser uses a side-by-side two-column tenant comparison (Acme vs Globex) + a WDT trailer line — small, illustrative, not a tutorial.
- The "What this demo proves" bullets explicitly call out v0.3 features (subdomain resolver, profiler tab, mailer bootstrapper, origin header resolver, three-step fallback ladder) so a docs reader sees the value before opening the canonical README.
- saas-demo.md cross-links to `../user-guide/mailer-bootstrapper.md` — this file is created by Plan 22-03 (same wave). `mkdocs build --strict` runs only at the end of Plan 22-06, by which time all wave-2 plans (including 22-03) have landed, so the link will resolve.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Internal See-Also links inside moved files broke after the rename**

- **Found during:** Task 1 verification (grep for `\]\(\.\.?/[^)]+\.md` in the moved files)
- **Issue:** Both moved files contain See-Also lists with `../<page>.md` relative links (e.g., `../shared-db.md`, `../database-per-tenant.md`, `../testing.md`, `../cli-commands.md`, `../strict-mode.md`, `../resolvers.md`). Pre-move these resolved to `docs/user-guide/<page>.md` because `..` from `docs/user-guide/examples/` was `docs/user-guide/`. Post-move, `..` from `docs/examples/` is `docs/`, so all 6 links now resolved to non-existent `docs/<page>.md`. The plan's Task 1 instruction said "DO NOT edit the content" (byte-identical move), but its `<action>` block explicitly anticipated this case: "If they point to `../something.md` (going up to `docs/user-guide/`), those paths now break — update to `../user-guide/something.md`."
- **Fix:** Updated 6 internal links inside Task 2's cross-ref commit (atomic with the 4 external cross-refs). Specifically: in `docs/examples/saas-subdomain.md`, `../database-per-tenant.md`, `../cli-commands.md`, `../testing.md` -> `../user-guide/<page>.md`. In `docs/examples/api-header.md`, `../shared-db.md`, `../strict-mode.md`, `../resolvers.md` -> `../user-guide/<page>.md`.
- **Files modified:** `docs/examples/saas-subdomain.md`, `docs/examples/api-header.md`
- **Verification:** `grep -nE '\]\(\.\./[a-z][^)]+\.md' docs/examples/saas-subdomain.md docs/examples/api-header.md` shows all 6 links now have the `../user-guide/` prefix.
- **Committed in:** `26bfeec` (Task 2 commit, alongside the 4 external cross-ref updates).

---

**Total deviations:** 1 auto-fixed (Rule 3, blocking).
**Impact on plan:** Necessary for mkdocs --strict correctness — without this fix the moved See-Also links would 404 in the published site (CI gate). No scope creep; the plan's `<action>` block had already anticipated this exact situation.

## Issues Encountered

- None. The plan's task-by-task structure mapped cleanly to commits. Pre-commit hooks (php-cs-fixer, PHPStan, PHPUnit) ran on each commit — all green (559 tests, 2069 assertions, 0 failures).

## Verification Gates

All overall-verification gates from the plan pass:

```
=== File presence ===
all 3 examples present
old paths gone

=== Zero remaining old refs ===
grep -rnE '\(examples/saas-subdomain\.md\)|\(examples/api-header\.md\)|user-guide/examples/' docs/
(zero matches, exit=1)

=== find docs/examples ===
docs/examples/saas-demo.md
docs/examples/api-header.md
docs/examples/saas-subdomain.md
```

git status shows two clean renames (`5545e94`) + three modified files (`26bfeec`) + one new file (`a0886e8`).

## Non-D-07 cross-references found during grep

None. The RESEARCH §"Cross-link Map" 4-line table was exhaustive — the verification grep confirmed there were no additional references to the old `examples/<page>.md` paths anywhere in `docs/`. The only additional fix needed was the 6 internal See-Also links INSIDE the moved files (see Deviations Rule 3 above), which is a different category — those are reverse-direction links from the moved files back to their original neighbors, not cross-references TO the moved files.

## Self-Check: PASSED

- `docs/examples/saas-subdomain.md` exists: FOUND
- `docs/examples/api-header.md` exists: FOUND
- `docs/examples/saas-demo.md` exists: FOUND (69 lines)
- `docs/user-guide/examples/saas-subdomain.md` does NOT exist: confirmed
- `docs/user-guide/examples/api-header.md` does NOT exist: confirmed
- `docs/user-guide/examples/` directory does NOT exist: confirmed
- Commit `5545e94` exists in git log: FOUND
- Commit `26bfeec` exists in git log: FOUND
- Commit `a0886e8` exists in git log: FOUND
- Zero matches for old paths in `docs/`: confirmed (grep exit=1)

## Next Phase Readiness

Ready for Plan 22-06 (mkdocs nav reorg, D-16). At Plan 22-06:

1. Remove the nested `Examples:` block under `User Guide:` in `mkdocs.yml` (lines 81-83 of the current nav).
2. Add a new top-level `Examples:` block with three entries: `examples/saas-subdomain.md`, `examples/api-header.md`, `examples/saas-demo.md`.
3. Run `mkdocs build --strict` locally — it should pass because:
   - All 4 external cross-references in user-guide/*.md point at valid `../examples/...` paths (this plan).
   - All 6 internal See-Also links in `docs/examples/<page>.md` point at valid `../user-guide/...` paths (this plan).
   - The `../user-guide/mailer-bootstrapper.md` cross-link from `saas-demo.md` resolves (Plan 22-03 created the file).
   - The `../../examples/saas/README.md` cross-link from `saas-demo.md` is a cross-tree file link; per RESEARCH §Landmines #1, mkdocs strict tolerates this (renders as HTML anchor, valid in repo browser).

No blockers.

---
*Phase: 22-docs-refresh*
*Completed: 2026-05-28*
