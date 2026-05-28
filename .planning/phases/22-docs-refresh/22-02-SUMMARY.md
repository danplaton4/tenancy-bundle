---
phase: 22-docs-refresh
plan: 02
subsystem: docs
tags:
  - docs
  - upgrade-guide
  - v0.3.3
requires:
  - Phase 18 DEC-INST-02 history (referenced as the reversed decision)
provides:
  - UPGRADE.md ## 0.3.2 to 0.3.3 section (D-13)
  - polished present-tense cross-link to mailer-bootstrapper.md (SC5)
affects:
  - UPGRADE.md (lines 1-46 newly inserted, line 213-215 reflowed)
tech-stack:
  added: []
  patterns:
    - reverse-chronological H2 stacking in UPGRADE.md (new sections at top)
key-files:
  created: []
  modified:
    - UPGRADE.md
decisions:
  - Inline the must-have grep phrase "no application code or schema changes are required" on a single line so the plan's automated `grep -q` gate matches (Markdown soft-wrap defeats line-based grep; both bolded headline and lowercase body sentence carry the phrase)
  - Use Markdown link form `[Mailer Bootstrapper guide](docs/user-guide/mailer-bootstrapper.md)` for the SC5 polish (vs bare parenthetical) — GitHub renders it as a click-through, the docs-lint script does NOT scan UPGRADE.md so cross-link rules don't apply, target file is being created by Plan 22-03 in this same wave
  - Keep the dev-deps removal note as a dedicated `### Note for users who installed nikic manually` subsection (vs a single inline sentence) — gives it visual weight so users skimming for "do I need to do anything?" find it on first scan
metrics:
  duration: ~15 minutes
  completed: 2026-05-28
---

# Phase 22 Plan 02: UPGRADE.md 0.3.2→0.3.3 + Mailer cross-link polish — Summary

One-liner: Inserted a new `## 0.3.2 to 0.3.3` H2 section at the top of UPGRADE.md documenting the nikic/php-parser `suggest`→`require` reversal of DEC-INST-02, and replaced the stale `(coming in the v0.3 docs refresh)` parenthetical in the `## 0.2 to 0.3` section with a present-tense Markdown link to `docs/user-guide/mailer-bootstrapper.md` (created in parallel by Plan 22-03).

## What Shipped

### Task 1 — New `## 0.3.2 to 0.3.3` H2 section (commit `37a81c8`)

UPGRADE.md L3-43: new section inserted between the H1 title and the existing `## 0.3.1 to 0.3.2` section. Contents:

- **Intro paragraph** — one sentence framing the dependency-tree change, ending with the bolded headline `**No application code or schema changes are required.**`
- **`### What changed`** — two paragraphs: (1) what v0.3.0–v0.3.2 did per Phase 18 DEC-INST-02 (nikic in `suggest`, two-command install, manual `composer require --dev nikic/php-parser` prerequisite), (2) what v0.3.3 does (nikic in `require`, single-command install, ~50 KB AST parser code now ships to production — trade-off explicitly noted and accepted)
- **`### Action required`** — `**None.**` followed by a short paragraph: `composer update danplaton4/tenancy-bundle`, no app code or schema changes are required, no Doctrine migrations, no config edits
- **`### Note for users who installed nikic manually`** — covers Research Open Q3: users who previously ran `composer require --dev nikic/php-parser` as a v0.3.0–v0.3.2 workaround may now remove it from `require-dev`; Composer dedupes so leaving it is harmless

### Task 2 — Polish `## 0.2 to 0.3` Mailer cross-link (commit `b0544c3`)

UPGRADE.md L213-215 (originally L173 before Task 1's insertion shifted everything down):

- Before: ``` `docs/user-guide/mailer-bootstrapper.md` (coming in the v0.3 docs refresh). ```
- After: ``` [Mailer Bootstrapper guide](docs/user-guide/mailer-bootstrapper.md). ```

The rest of the `## 0.2 to 0.3` Mailer BC break section (3 new abstract methods, Migration path A trait, Migration path B manual, raw SQL ALTER snippet) is untouched per SC5.

### Followup — Reflow for grep-gate compliance (commit `1f65f25`)

The plan's automated verification gate (`grep -q 'no application code or schema changes' UPGRADE.md`) is case-sensitive and line-based. The first commit had the phrase wrapped across two soft-wrapped lines as a bolded `**No application code or schema...changes are required.**`, which defeated `grep`. Followup commit:

1. Reflowed the bolded headline onto a single line at L8
2. Added a second lowercase occurrence inside `### Action required` (L32) so both case-sensitive `grep -q` AND case-insensitive `grep -qi` pass

## Source-Order of Version Headings (verification)

```
3:## 0.3.2 to 0.3.3    ← new
45:## 0.3.1 to 0.3.2
115:## 0.2 to 0.3
217:## 0.1 to 0.2
321:## Upgrading to 0.1
```

Correct reverse-chronological order per the file's established pattern.

## Plan Automated Gates (all PASS)

| Gate | Result |
|------|--------|
| `head -10 UPGRADE.md \| grep -q '^## 0.3.2 to 0.3.3$'` | PASS — heading at L3 |
| `grep -q 'DEC-INST-02' UPGRADE.md` | PASS — 2 occurrences |
| `grep -q 'no application code or schema changes' UPGRADE.md` | PASS — L32 |
| `grep -q 'composer require --dev nikic/php-parser' UPGRADE.md` | PASS — 2 occurrences (L14, L37) |
| `! grep -q 'coming in the v0.3 docs refresh' UPGRADE.md` | PASS — replaced |
| `grep -q 'mailer-bootstrapper.md' UPGRADE.md` | PASS — L214 link |
| `grep -q 'TenantMailerConfigTrait' UPGRADE.md` | PASS — 0.2→0.3 trait section preserved |
| `grep -q 'ALTER TABLE' UPGRADE.md` | PASS — 0.2→0.3 SQL snippet preserved |
| `grep -q 'TenantInterface' UPGRADE.md` | PASS — 0.2→0.3 BC contract preserved |
| `grep -q 'getMailerDsn' UPGRADE.md` | PASS — 0.2→0.3 method preserved |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — bug] Grep gate defeated by soft-wrap on the must-have phrase**

- **Found during:** Task 1 verification (after the section commit)
- **Issue:** The plan's automated verification `grep -q 'no application code or schema changes' UPGRADE.md` returned no match because the phrase was wrapped across L7-L8 in the bolded headline. `grep` is line-based and the soft wrap broke it (`...**No application code or schema\n changes are required.**`).
- **Fix:** Reflowed the bolded headline onto a single line at L8; added a second lowercase occurrence inside `### Action required` so both case variants of the gate match.
- **Files modified:** UPGRADE.md
- **Commit:** `1f65f25`

No other deviations. All other plan steps executed as written.

## Commits

| Hash | Type | Description |
|------|------|-------------|
| `37a81c8` | docs | add UPGRADE.md 0.3.2 to 0.3.3 section (D-13) |
| `b0544c3` | docs | polish 0.2 to 0.3 Mailer cross-link to present-tense (SC5) |
| `1f65f25` | docs | inline must-have phrase on a single line for grep gates |

## Cross-references for downstream plans

- Plan 22-03 (Wave 1) creates `docs/user-guide/mailer-bootstrapper.md` — the target of the new Markdown link added by Task 2. Plan 22-03 MUST exist by end of Wave 1 or `mkdocs build --strict` will fail in CI (UPGRADE.md is not in the mkdocs nav so the link is not part of the strict build, but GitHub UI users would see a dead link).
- Plan 22-04 (composer.json edit) makes the dependency-tree claims in the new UPGRADE section true. The two plans MUST ship together as v0.3.3 — UPGRADE.md without the composer.json change would be a lie, composer.json change without UPGRADE.md would surprise existing users.

## Success Criteria

- [x] New `## 0.3.2 to 0.3.3` section present at top of UPGRADE.md
- [x] Section explains nikic suggest→require + DEC-INST-02 reversal
- [x] Section states "no application code or schema changes are required" (case-sensitive grep PASS)
- [x] Section includes dev-deps removal one-liner (Open Q3)
- [x] Existing `## 0.2 to 0.3` Mailer BC content preserved (TenantInterface, getMailerDsn, TenantMailerConfigTrait, ALTER TABLE all present)
- [x] L173 (now L213-215) parenthetical replaced with Markdown link to mailer-bootstrapper.md
- [x] Each task committed individually
- [x] SUMMARY.md created
- [x] D-13 implemented; SC5 verified intact; Research Open Q3 closed

## Self-Check: PASSED

- UPGRADE.md exists and contains the new section at L3 — FOUND
- Commit `37a81c8` (Task 1) — FOUND in git log
- Commit `b0544c3` (Task 2) — FOUND in git log
- Commit `1f65f25` (reflow fix) — FOUND in git log
- All 10 plan verification gates PASS
