---
phase: 22-docs-refresh
plan: 03
subsystem: docs
tags: [docs, profiler, mailer, x-transport, mkdocs]

requires:
  - phase: 19-profiler-tab
    provides: The three-state Tenancy WDT panel (resolved/null/error) that the new ASCII renders depict
  - phase: 20-mailer-bootstrapper
    provides: MailerBootstrapper, TenantMessageDecorator, MailerTransportContractPass, TenantMailerConfigTrait — the four pieces the new mailer-bootstrapper.md documents
provides:
  - Three fenced ASCII renders (resolved / null / error) in docs/user-guide/profiler-tab.md mirroring tenant.html.twig field labels verbatim
  - Live-demo link from profiler-tab.md to examples/saas/README.md
  - New User Guide page docs/user-guide/mailer-bootstrapper.md (138 lines, 4 D-14 content areas)
affects:
  - 22-04 (mkdocs.yml nav reorganization — needs to wire mailer-bootstrapper.md and profiler-tab.md into the User Guide nav)
  - 22-05 (configuration.md per-tenant mailer config block — cross-linked from mailer-bootstrapper.md)
  - 22-06 (docs-lint.sh — Approach A whitelist must protect profiler-tab.md's bundles.php references)

tech-stack:
  added: []
  patterns:
    - "ASCII-art panel renders in fenced `text` code blocks (no PNGs, no SVGs, no Mermaid) — drift-free vs Twig template because labels match verbatim"
    - "Cross-page link from docs/user-guide/ to repo-root examples/saas/README.md via ../../examples/saas/README.md"

key-files:
  created:
    - docs/user-guide/mailer-bootstrapper.md
  modified:
    - docs/user-guide/profiler-tab.md

key-decisions:
  - "ASCII renders stacked vertically (not pymdownx.tabbed side-by-side) — keeps grep-ability and source-readability, matches the prose-then-block pattern already used in the resolved-state section"
  - "Mailer page links to UPGRADE.md for the canonical 0.2→0.3 migration recipe rather than duplicating the ALTER TABLE snippet — single source of truth"
  - "Bordered box-drawing ASCII (U+2500 family) for the three panel renders — visually distinct from regular code samples, signals 'this is a UI mockup'"

patterns-established:
  - "Profiler ASCII source-of-truth: field labels must match src/Resources/views/Collector/tenant.html.twig verbatim. Acceptance criteria grep for the literal Twig labels."
  - "Mailer docs link to compiler-pass file path (src/DependencyInjection/Compiler/MailerTransportContractPass.php) by full repo-relative path so readers can grep the enforcement source."

requirements-completed: [DOC-19]

duration: ~25min
completed: 2026-05-28
---

# Phase 22 Plan 03: Profiler ASCII + Mailer Page Summary

**Three-state profiler ASCII renders in profiler-tab.md (resolved / null / error) plus a new 138-line mailer-bootstrapper.md page covering DSN config, X-Transport strategy, async failure-mode warning, and migration recipe.**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-05-28T15:48:00Z (approx)
- **Completed:** 2026-05-28T16:14:03Z
- **Tasks:** 2
- **Files modified:** 1
- **Files created:** 1

## Accomplishments

- Profiler tab page now shows the actual WDT panel layout for all three states (resolved with Slug/Tenant/Driver/Connection grid + Resolved by FQCN + Bootstrappers list, null with the two fixed prose lines, error with Resolution error heading + TenantInactiveException). Field labels mirror `src/Resources/views/Collector/tenant.html.twig` lines 70-162 **verbatim** — no drift.
- "See it live in the demo" link added pointing at `../../examples/saas/README.md` (the canonical demo that ships with the Profiler enabled).
- New `docs/user-guide/mailer-bootstrapper.md` documents the Phase 20 surface in 138 lines across five H2 sections: How it works, Configuring per-tenant SMTP, The X-Transport strategy, Async failure-mode warning, Migration recipe. Plus a `See also` cross-link block.
- The async failure-mode warning explicitly states "a message dequeued for a deleted tenant must throw, not silently drop" and cites `MailerTransportContractPass` (full path `src/DependencyInjection/Compiler/MailerTransportContractPass.php`) as the compile-time enforcement source.
- Migration recipe section deliberately does NOT duplicate the `UPGRADE.md` 0.2→0.3 ALTER TABLE snippet — it links to UPGRADE.md as the canonical recipe (verified: `grep -c 'ALTER TABLE tenant' docs/user-guide/mailer-bootstrapper.md` returns 0).

## Task Commits

1. **Task 1: Add 3-state ASCII renders to profiler-tab.md (D-01/D-02)** — `ca7f1b1` (docs)
2. **Task 2: Create docs/user-guide/mailer-bootstrapper.md (D-14)** — `8ca2964` (docs)

## Files Created/Modified

- `docs/user-guide/profiler-tab.md` (modified, +35 / -12 lines) — Three ASCII panel renders inserted at lines 80-91 (resolved), 102-110 (null), 119-128 (error). Demo link inline in the section intro at line 67. `bundles.php` references at L22/L35/L125/L135 preserved.
- `docs/user-guide/mailer-bootstrapper.md` (created, 138 lines) — Five H2 sections + See Also block.

## Field Label Verification

The ASCII renders mirror `src/Resources/views/Collector/tenant.html.twig` lines 70-162 verbatim. No deviations from the Twig source:

| State | Twig labels (template) | ASCII labels (docs) | Match? |
|-------|------------------------|---------------------|--------|
| Resolved (grid) | Slug, Tenant, Driver, Connection | Slug, Tenant, Driver, Connection | yes |
| Resolved (H3) | Resolved by | Resolved by | yes |
| Resolved (H3) | Bootstrappers (N) | Bootstrappers (2) | yes |
| Null (prose) | "No tenant resolved for this request." / "This is the expected state for public, landlord, and health-check routes." | identical (split across two lines for the box width) | yes |
| Error (H3) | Resolution error | Resolution error | yes |
| Error (class) | `Tenancy\Bundle\Exception\TenantInactiveException` | identical | yes |
| Error (message) | `Tenant "acme" is inactive.` | identical | yes |

## UPGRADE.md non-duplication verification

`grep -c 'ALTER TABLE tenant' docs/user-guide/mailer-bootstrapper.md` → **0**. The mailer page links to `UPGRADE.md#02-to-03` rather than duplicating the migration SQL, which keeps the canonical recipe in a single location and avoids drift between docs.

## Decisions Made

- **ASCII layout: vertical stacking (not pymdownx tabs).** Three blocks in sequence, each preceded by its `### State` heading. Tabs would have hidden two of the three states behind UI affordances that grep can't see — vertical stacking keeps the source readable and grep-discoverable.
- **Bordered box-drawing characters for ASCII panels.** Using `┌─ ─┐` borders signals "this is a UI mockup" and visually separates the panel renders from any neighboring code samples (none here, but for future maintenance).
- **Mailer migration recipe by link, not by duplication.** The 4-method, two-path migration recipe lives in UPGRADE.md as the canonical source. Mailer page references it via `(../../UPGRADE.md#02-to-03)`. Removes the maintenance burden of keeping two copies in sync.

## Deviations from Plan

None — plan executed exactly as written. All verification gates pass:

- `grep -c '^\`\`\`' docs/user-guide/profiler-tab.md` → 16 (even, ≥ 6)
- `grep -c 'Resolution error' docs/user-guide/profiler-tab.md` → 1
- `grep -c 'No tenant resolved for this request' docs/user-guide/profiler-tab.md` → 2 (prose + ASCII block)
- `grep -c 'TenantInactiveException' docs/user-guide/profiler-tab.md` → 2 (prose + ASCII block)
- `grep -c 'Resolved by' docs/user-guide/profiler-tab.md` → 1
- `grep -c 'Bootstrappers' docs/user-guide/profiler-tab.md` → 1
- `grep -c 'examples/saas/README.md' docs/user-guide/profiler-tab.md` → 1
- `grep -c 'bundles\.php' docs/user-guide/profiler-tab.md` → 4 (preserved)
- `grep -c '^## ' docs/user-guide/profiler-tab.md` → 5 (intact H2 structure)
- `test -f docs/user-guide/mailer-bootstrapper.md` → exists
- `head -1 docs/user-guide/mailer-bootstrapper.md` → `# Mailer Bootstrapper`
- `wc -l docs/user-guide/mailer-bootstrapper.md` → 138 (≥ 80)
- `grep -c 'X-Transport' docs/user-guide/mailer-bootstrapper.md` → 7
- `grep -c 'TenantMailerConfigTrait' docs/user-guide/mailer-bootstrapper.md` → 6
- `grep -c 'MailerTransportContractPass' docs/user-guide/mailer-bootstrapper.md` → 1
- `grep -c 'must throw' docs/user-guide/mailer-bootstrapper.md` → 1 (matches plan acceptance: "must throw" OR "not silently drop"; we have both in the same sentence)
- `grep -c 'ALTER TABLE tenant' docs/user-guide/mailer-bootstrapper.md` → 0

## Issues Encountered

None.

## User Setup Required

None — docs-only changes.

## Next Phase Readiness

- Plan 22-04 can now wire `docs/user-guide/profiler-tab.md` and `docs/user-guide/mailer-bootstrapper.md` into `mkdocs.yml` nav (per D-16). The files exist and `mkdocs build --strict` will find them.
- Plan 22-05's `configuration.md#per-tenant-mailer-config` cross-link target is referenced from the new mailer page — that anchor must be created by Plan 22-05 or `mkdocs build --strict` will error.
- Plan 22-06's docs-lint Approach A whitelist must include the H2 sections `## Do I have to do anything?` and `## Troubleshooting` to allow `profiler-tab.md`'s preserved `bundles.php` references at L22/L35/L125/L135 to survive the new lint rule.

## Self-Check: PASSED

- Files exist: `docs/user-guide/profiler-tab.md` FOUND, `docs/user-guide/mailer-bootstrapper.md` FOUND
- Commits exist: `ca7f1b1` FOUND, `8ca2964` FOUND
- All grep/test gates from `<verify>` and `<acceptance_criteria>` blocks pass

---

*Phase: 22-docs-refresh*
*Plan: 03*
*Completed: 2026-05-28*
