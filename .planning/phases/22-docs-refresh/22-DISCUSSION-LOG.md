# Phase 22: Docs Refresh - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-22
**Phase:** 22-docs-refresh
**Areas discussed:** Profiler tab screenshots strategy, Roadmap mirror strategy, Demo walkthrough — location/depth/sync model, installation.md restructure + nikic prereq

---

## Profiler tab screenshots strategy

### Q1 — How should profiler-tab.md show what the WDT panel looks like?

| Option | Description | Selected |
|--------|-------------|----------|
| ASCII/code-block render + describe + link to demo (Recommended) | Show the panel as a code-block ASCII representation plus prose describing each field, plus a 'see it live in the demo' link. Zero binary assets, zero drift maintenance. | ✓ |
| Real PNG screenshots committed to docs/assets/ | Capture actual PNGs from the running demo (acme/globex tenant pages + landlord). Highest fidelity but binary repo bloat, every WDT redesign means re-capturing. | |
| Inline SVG / Mermaid diagram of panel layout | Render the panel structure as a Mermaid diagram or hand-rolled SVG. Stays in version control as text but more work than ASCII. | |
| Skip screenshots entirely, just describe + link | No image content at all. Page describes the panel in prose. Cheapest but technically violates SC2's literal 'with screenshots' wording. | |

**User's choice:** ASCII/code-block render
**Notes:** Aligns with Symfony's own docs convention. Trades fidelity for zero-maintenance.

### Q2 — How many ASCII renders to include (3 panel states)?

| Option | Description | Selected |
|--------|-------------|----------|
| All 3 states — resolved, no-tenant, error (Recommended) | Show all three side-by-side or stacked. New users see the full surface area in one place. | ✓ |
| Just the resolved state — the happy path | Show only the most common case. Other 2 states described in prose. Cleaner but loses visual reinforcement. | |
| Resolved + error — skip no-tenant | Show success + failure. No-tenant is implicit. Compromise on space. | |

**User's choice:** All 3 states
**Notes:** Matches what the page already promises to document.

---

## Roadmap mirror strategy

### Q1 — How should docs/roadmap.md mirror ROADMAP.md?

| Option | Description | Selected |
|--------|-------------|----------|
| Hard-copy + docs-lint asserts byte-identical (Recommended) | `docs/roadmap.md` is a verbatim copy of repo-root ROADMAP.md. docs-lint fails CI if they diverge. | |
| Thin redirect page — 'See ROADMAP.md on GitHub' | `docs/roadmap.md` is just a few lines that link to the repo-root ROADMAP.md on GitHub. | |
| Install mkdocs-include / mkdocs-macros plugin | Adds a Python dependency to the docs build. `docs/roadmap.md` becomes `{% include 'ROADMAP.md' %}`. | |
| CI build-script generates docs/roadmap.md from ROADMAP.md | Pre-build step in CI copies ROADMAP.md → docs/roadmap.md. Don't commit docs/roadmap.md (gitignored). | |

**User's choice (free-text):** "i think it's 2, that ROADMAP.md should link to the docs, and not keep two same things living"
**Notes:** User INVERTED the direction. `docs/roadmap.md` becomes the canonical source (full content lives on docs site). Repo-root `ROADMAP.md` becomes the thin pointer. README.md links to docs-site URL directly. Single source of truth = docs site. Zero drift because there's only one canonical copy. This is a smarter answer than the recommended option.

---

## Demo walkthrough — location, depth, sync model

### Q1 — Where should the docs-site demo page live, and how much to duplicate?

| Option | Description | Selected |
|--------|-------------|----------|
| Thin docs page links to repo README (Recommended) | Page at `docs/examples/saas-demo.md`. Thin intro + 'what this demo proves' + 1-line install + ASCII teaser + link to `examples/saas/README.md` for the full walkthrough. | ✓ |
| Full walkthrough duplicated in docs | Full content of examples/saas/README.md mirrored at docs/examples/saas-demo.md. 250+ lines duplicated, drift risk. | |
| Fold into existing docs/user-guide/examples/ | Skip the new top-level dir, put the demo next to existing api-header.md / saas-subdomain.md. Violates SC3's literal location. | |

**User's choice:** Thin docs page links to repo README
**Notes:** Same single-source-of-truth pattern as the inverted roadmap mirror.

### Q2 — Side effect: two Examples sections in nav. What to do?

| Option | Description | Selected |
|--------|-------------|----------|
| Move existing Examples up too — single top-level (Recommended) | Move `docs/user-guide/examples/` contents up to `docs/examples/`. Update nav to one top-level Examples section with all 3 pages. | ✓ |
| Keep both — 'Examples' (top) + 'User Guide > Examples' (sub) | Live with two Examples sections. Top-level for full-stack demo, user-guide for code patterns. Confusing nav. | |
| Skip SC3's literal location — fold saas-demo into existing examples | Put saas-demo at user-guide/examples/saas-demo.md. Violates SC3 literal wording. | |

**User's choice:** Move existing Examples up too
**Notes:** Cleanest nav. Requires updating internal links to the 2 existing pages.

---

## installation.md restructure + nikic prereq prominence

### Q1 — What's the manual install's fate?

| Option | Description | Selected |
|--------|-------------|----------|
| Remove entirely from install page (Recommended) | Strip every mention of editing bundles.php from installation.md. tenancy:install is the install path. | ✓ |
| Keep as 'Manual install (fallback)' in a separate page | Move the manual instructions to a separate page only linked from installation.md as a fallback. | |
| Keep as bottom 'Troubleshooting' section on same page | Manual instructions stay in installation.md but moved to a bottom subsection. Risks SC1 literal violation. | |

**User's choice:** Remove entirely from install page
**Notes:** Cleanest interpretation of SC1. After D-09 (nikic in `require`) there's no edge case where the manual install would be needed.

### Q2 — Where does `composer require --dev nikic/php-parser` appear on the install page?

| Option | Description | Selected |
|--------|-------------|----------|
| Front and center, step 1 of install (Recommended) | First step on the install page is `composer require danplaton4/tenancy-bundle nikic/php-parser --dev`. | |
| Separate 'Requirements' subsection before the install command | Page leads with a small Requirements box that lists nikic as a dev dep. | |
| Hidden in a footnote, surfaced only when tenancy:install errors | Skip nikic on the main install page. Rely on tenancy:install's error message. | |

**User's choice (free-text):** "WAAAAAIT! WHAAAT, we need to install another package? why don't we put originaly in the tenancy-bundle a dev requirements, so it gets installed automatically, but user does not have to run it manually"
**Notes:** User pushed back on the whole premise. This triggered a scope-expansion discussion. Outcome: add `nikic/php-parser` to `composer.json` `require` (was `suggest` per DEC-INST-02 from Phase 18). Trade-off: ~50KB of AST parser in production deploys, accepted for one-command install UX. DEC-INST-02 reversed.

### Q3 — Fold the nikic dep change into Phase 22, or carve it out?

| Option | Description | Selected |
|--------|-------------|----------|
| Fold into Phase 22 — ship docs + dep change together as v0.3.3 (Recommended) | Composer change + docs in one phase, one v0.3.3 patch release. Coherent narrative. | ✓ |
| Keep Phase 22 docs-only — carve composer change to Phase 22.5 | Cleaner scope discipline but two version bumps. | |
| Leave it — stay with current DEC-INST-02, doc the prereq | Honor the original Phase 18 lock. Accept the one-extra-command UX cost. | |

**User's choice:** Fold into Phase 22
**Notes:** Phase 22 grows by one task (composer.json edit + UPGRADE.md note). v0.3.3 ships docs + dep + install UX together.

### Q4 — docs-lint rule strictness for bundles.php references?

| Option | Description | Selected |
|--------|-------------|----------|
| Fail on any bundles.php outside UPGRADE/Migration sections (Recommended) | Targeted check excluding lines inside `## Migration` or `## Upgrade` heading scope. | ✓ |
| Fail on any bundles.php in docs/ — period | Simpler check: any mention fails CI. Forces upgrade guides to refer to the file obliquely. | |
| Whitelist-based — lint passes a known list of allowed files | docs-lint has a hardcoded list of files where bundles.php mentions are allowed. | |

**User's choice:** Fail on any bundles.php outside UPGRADE/Migration sections
**Notes:** Captures the regression case without flagging legitimate upgrade-guide references.

---

## Claude's Discretion

- Exact prose of each new docs page (we discussed scope and structure, not paragraph-level wording).
- Whether the ASCII profiler-tab renders are stacked vertically or use pymdownx.tabbed side-by-side. Pick whichever renders cleanest in the MkDocs Material theme.
- Whether to include a silent footnote on installation.md mentioning that `nikic/php-parser` arrives transitively via the install, or to say nothing at all.
- mkdocs nav ordering of new entries (logical/feature-grouped vs alphabetical) — captured loosely as D-16, planner can refine.

## Follow-up scope expansion — yellow pages folded in

After the main 4-area discussion completed and CONTEXT.md was first committed (commit `5eb4396`), the user asked about the "yellow but not red" pages listed in the deferred section. Discussion outcome:

### Q — The 4 yellow pages: fold into Phase 22 or leave deferred?

| Option | Description | Selected |
|--------|-------------|----------|
| Fold into Phase 22 — ship docs truly complete in v0.3.3 (Recommended) | Add 4 page refreshes as surgical additions to existing pages. Phase 22 grows ~30-40%. v0.3.3 ships with docs genuinely complete for v0.3 surface. | ✓ |
| Leave deferred — ship tight Phase 22, refresh later | Stay with original scope. Yellow pages stay yellow until user feedback or future docs sweep. | |
| Mid — fold the worst two (getting-started + cli-commands), leave the others | Compromise. Refresh the two most-read pages now. | |

**User's choice:** Fold into Phase 22
**Notes:** v0.3.3 will ship `AbstractTenant` + nikic-in-require + new mailer + new demo page; better to have the docs site genuinely complete on that release rather than having half the User Guide silently predating the v0.3 surface. Triggered D-17/D-18/D-19/D-20 in CONTEXT.md.

## Deferred Ideas

- Symfony Flex recipe automation — project decision holds (no-Flex memory).
- `tenancy:install` shell-out to `composer require nikic/php-parser` — superseded by D-09 (nikic now a hard dep). Permanently retired.
- Per-resolver subpages under a "Resolvers" nav group — cosmetic, out of scope. May revisit if `resolvers.md` grows past ~500 lines after the D-19 addition.
- Documentation versioning (mike, mkdocs-versioning) — out of scope until adoption justifies maintenance cost.
