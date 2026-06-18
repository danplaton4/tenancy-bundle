# Phase 29: Docs Refresh - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-06-18
**Phase:** 29-docs-refresh
**Areas discussed:** Filesystem page disposition, docs-lint check strictness, UPGRADE.md 0.3→0.4 policy, shared-entities page scope

---

## Filesystem Page Disposition

| Option | Description | Selected |
|--------|-------------|----------|
| Verify-only accuracy pass | Research confirms the existing page matches shipped code; fix only drift, add cross-links to new pages. Don't rewrite a good page. | ✓ |
| Full refresh/rewrite | Re-audit structure and rewrite for consistency with the new pages, even where accurate. | |
| Mark satisfied, skip entirely | Treat DOC-20's filesystem criterion as already met; no plan touches the page. | |

**User's choice:** Verify-only accuracy pass
**Notes:** `docs/user-guide/filesystem-bootstrapper.md` already exists (433 lines, comprehensive — DOC-20's "new page" assumption is stale). Captured as D-01.

---

## docs-lint Shared-Entity Ambiguity Check

| Option | Description | Selected |
|--------|-------------|----------|
| Per-file disambiguation | Any docs/ file mentioning "shared entit(y/ies)" must contain BOTH "landlord-side master" and "tenant-side read-only copy". Simplest, lowest false-positive, fits existing check() idiom. | ✓ |
| Per-occurrence proximity | Each occurrence must be within N lines of a disambiguator. Stricter, higher false-positive risk. | |
| Section-whitelist (like bundles.php) | Model on the existing awk section-whitelist guard. Most flexible, most complex. | |

**User's choice:** Per-file disambiguation
**Notes:** Captured as D-04. Coupled with D-07 (canonical vocabulary) — the new shared-entities page must contain both phrases to pass.

---

## UPGRADE.md 0.3 → 0.4 Policy

| Option | Description | Selected |
|--------|-------------|----------|
| Always add a brief section | Add a 0.3→0.4 section regardless: summarize what v0.4 adds + explicit "no breaking changes" note (or document any break research finds). | ✓ |
| Strictly conditional | Only add if research finds an actual BC break; otherwise skip entirely (DOC-20 literal reading). | |

**User's choice:** Always add a brief section
**Notes:** Captured as D-05. No BC break expected (`getFilesystemConfig()` optional via trait), but a short discoverability-oriented section is worth the low cost.

---

## shared-entities Page Scope

| Option | Description | Selected |
|--------|-------------|----------|
| One comprehensive page | Single shared-entities.md: #[Shared], sync vs async, resync command, cascade-depth landmine, write-protection invariant; landlord-master/tenant-copy vocabulary up front. | ✓ |
| Concept page + command in cli-commands.md | shared-entities.md covers the model; fold resync into existing cli-commands.md. | |
| Sync-led, async as subsection | Lead with sync; async + resync as later sections. | |

**User's choice:** One comprehensive page
**Notes:** Captured as D-02. Covers Phases 25/26/27 in one page. Must be distinguished from the existing shared-db.md (different feature).

---

## Claude's Discretion

- phpstan-extension.md exact wording + example-violation code snippets (coverage locked in D-03).
- Page depth/tone — mirror existing user-guide pages.
- Whether to add a `tenancy:shared:resync` cross-link/stub into cli-commands.md.
- Exact nav ordering within the User Guide section (D-06 fixes placement, not order).

## Deferred Ideas

None — discussion stayed within phase scope.
