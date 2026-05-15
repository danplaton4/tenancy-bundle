# Phase 16: Governance Carry-Forward - Context

**Gathered:** 2026-05-15
**Status:** Ready for planning

<domain>
## Phase Boundary

Extend `gsd-sdk query audit-open` with two new scan categories so the v0.2 retrospective lessons surface *inside* the phase window, not at milestone close:

1. **Plan↔summary parity** — flag committed `PLAN.md` files that have no sibling `SUMMARY.md`.
2. **Stale `human_needed` VERIFICATION** — flag `VERIFICATION.md` files that have carried `status: human_needed` for more than 72 hours.

Plus: mark both items resolved in `RETROSPECTIVE.md` carry-forward section.

**Out of scope:** changes to bundle source code under `src/`. This is tooling-only. Also out of scope: items #3 (`.planning/` gitignore reconsideration) and #4 (executor sandbox root-cause) from the retrospective's carry-forward list — those are tracked for their own phases/milestones.

</domain>

<decisions>
## Implementation Decisions

### Code location & delivery vehicle (D-01)
- **D-01a:** Hybrid delivery — ship the extended scan as a `patch-package` patch against `@gsd-build/sdk` in this repo's `patches/` directory, AND open an upstream PR to `@gsd-build/sdk` in parallel.
- **D-01b:** Patch is the source of truth for *this repo* until upstream merges and releases. If upstream rejects or stalls, we keep the patch indefinitely.
- **D-01c:** Add `patch-package` as a devDependency-equivalent in the repo's tooling layer (no `package.json` in this PHP repo today — script will live at `.claude/get-shit-done/scripts/apply-audit-open-patch.sh` and run on `composer install` hook or be invoked manually).
- **D-01d:** Upstream PR target: `@gsd-build/sdk` repo, branch from the tag matching installed v1.41.2; PR description references this project's `RETROSPECTIVE.md` §"Retrospective Action Items" 1 + 2 as the failure-mode evidence.

**Why hybrid (not upstream-only / not vendored):**
- Upstream-only blocks this phase on someone else's review cadence — unacceptable for an internal carry-forward gate.
- Vendored copy or full fork creates divergence rot the next time `@gsd-build/sdk` releases.
- A diff-based patch is the standard pattern for "I need upstream functionality before they ship it." It tracks what changed, applies cleanly until it doesn't, and disappears when upstream catches up.

### Plan↔summary parity rule (D-02)
- **D-02a:** Rule: `PLAN.md` tracked by git AND no sibling `SUMMARY.md` tracked by git → gap.
- **D-02b:** "Sibling" means same phase directory, same plan slug prefix. E.g., `15-01-PLAN.md` requires `15-01-SUMMARY.md`.
- **D-02c:** Implementation reads `git ls-files .planning/phases/` once (single subprocess), then filters in JS — no per-file git calls.
- **D-02d:** Untracked PLAN.md files (working-copy in-flight planning) are **not** flagged. The moment a plan is committed, the audit clock starts.

**Why git-tracked (not strict 1:1, not frontmatter status, not age-based):**
- The exact v0.2 failure mode was: PLAN.md committed, executed, SUMMARY.md never authored. Git-tracked-without-sibling catches that precisely.
- Strict 1:1 file-existence creates false positives the instant `gsd-plan-phase` writes the PLAN before the executor runs.
- Frontmatter `status:` discipline isn't consistent enough across plans to rely on.
- Arbitrary age thresholds (e.g., 7 days) introduce a knob with no semantic anchor.
- Single `git ls-files` call is fast — no measurable scan-time regression.

### 72h TTL signal source (D-03)
- **D-03a:** Primary signal: new frontmatter field `human_needed_since: <ISO 8601 date>` on `VERIFICATION.md`.
- **D-03b:** Fallback signal (for legacy files lacking the field): file `mtime`. Documented as "approximate; add `human_needed_since:` for authoritative TTL."
- **D-03c:** When `gsd-verifier` (or any tool) writes `status: human_needed`, it MUST also write `human_needed_since:` set to `currentTimestamp(ISO)`. When status changes away from `human_needed`, the field is removed.
- **D-03d:** TTL threshold: 72 hours (the retrospective number). Constant in the audit code, not configurable in this phase.

**Why explicit field + mtime fallback (not frontmatter `updated:`, not git log):**
- `updated:` conflates "any edit to this file" with "transitioned to human_needed" — a typo fix would reset the clock. Wrong semantics.
- File `mtime` alone has the same problem and is also clobbered by git checkouts.
- `git log` is semantically correct but adds per-file subprocess calls — slow at scale, complex to implement portably.
- An explicit `human_needed_since:` field is one line of agent code, zero ambiguity, and the audit reads it directly. mtime fallback prevents legacy files from being silently exempted.

### Report severity & exit behavior (D-04)
- **D-04a:** Both new categories report at 🔴 critical tier, alongside `debug_sessions`, `uat_gaps`, `verification_gaps`.
- **D-04b:** Existing `verification_gaps` already flags `human_needed` regardless of age. New category `verification_stale_human_needed` is a stricter sub-flag that surfaces age. Both can fire on the same file; the report deduplicates by showing the staler one first.
- **D-04c:** New top-level category in `AuditOpenResult`: `plan_summary_gaps` (alongside `verification_gaps`).
- **D-04d:** Add `--strict` flag to `audit-open`. When `--strict` is passed AND any 🔴 category has items, the handler sets `process.exitCode = 1`. Default behavior (no flag) stays exit 0 — backward-compatible for existing scripted callers.
- **D-04e:** `--strict` is the intended flag for CI / pre-milestone-close gates. Document it in the audit-open help text.

**Why critical tier + opt-in --strict (not non-zero by default, not warning tier):**
- These checks exist *because* the v0.2 retrospective showed they should block milestone close. Warning tier would defeat the purpose.
- Hard-changing the default exit code could break unknown downstream automation that scripts against `gsd-sdk query audit-open` today. Opt-in via flag is additive — zero behavior change for existing callers, full enforcement for new ones.

### Documentation & traceability (D-05)
- **D-05a:** Update `.planning/RETROSPECTIVE.md` §"Retrospective Action Items" — items 1 and 2 marked `RESOLVED (v0.3 — Phase 16)` with date and brief note linking to this phase's SUMMARY.md.
- **D-05b:** Add a paragraph to `RETROSPECTIVE.md` documenting the `human_needed_since:` convention so future verifier agents know to populate it.
- **D-05c:** No public-facing docs change (docs site is for bundle users, not GSD-tooling internals).

### Tests (D-06)
- **D-06a:** Upstream PR includes Vitest unit tests covering: plan-with-summary (pass), plan-without-summary (fail), untracked plan (skipped), human_needed under 72h (pass), human_needed over 72h via `human_needed_since` (fail), human_needed over 72h via mtime fallback (fail), `--strict` flag exit code behavior.
- **D-06b:** Local patch ships with the same test file; if upstream merges, the test moves with the patch deletion.
- **D-06c:** No new test framework — reuse whatever `@gsd-build/sdk` uses (Vitest, per `summary.test.ts` / `verify.test.ts` neighbors).

### Claude's Discretion
- Exact patch-package invocation surface (`.npmrc` integration vs explicit script vs makefile target)
- Phrasing of the `--strict` flag's failure message (one-liner is fine)
- Exact format of `human_needed_since:` documentation snippet in `RETROSPECTIVE.md`
- Whether to add an `--audit-categories` allow-list flag at the same time (skip unless the diff would be trivial)

</decisions>

<specifics>
## Specific Ideas

- The retrospective evidence is concrete and citable: four plans (09-03, 09-04, 11-04, 11-05) were the v0.2 plan↔summary drift; three phases (09, 10, 12) were the human_needed-stale offenders. Use these as the test-fixture inspiration (sanitized).
- Patch-package as a pattern — well-trodden in JS ecosystems for exactly this "I need upstream-not-yet-released behavior" workflow. Keep the patch file minimal — only the audit-open scan additions plus the new field documentation.
- The 🔴/🟡/🔵 tier system in `formatAuditReport` is already idiomatic; new categories should slot into the existing severity-ordered output (debug → uat → verification → plan_summary → verification_stale → quick → todos → threads → seeds → context_questions).
- `audit-open` is invoked both interactively and inside `milestone.complete` workflows — `--strict` is for the latter eventually, manual invocation should keep returning exit 0.

</specifics>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase requirement & origin
- `.planning/REQUIREMENTS.md` §"Governance (Tooling Carry-Forward)" — GOV-01 acceptance criteria (4 bullets)
- `.planning/RETROSPECTIVE.md` §"Retrospective Action Items (carry forward)" — items 1 and 2 are this phase's targets
- `.planning/RETROSPECTIVE.md` §"What Was Inefficient" + §"Key Lessons" — failure-mode evidence used in tests and upstream PR description

### Tool to extend
- Upstream source (read-only reference, not in this repo): `node_modules/@gsd-build/sdk/src/query/audit-open.ts` — installed at `/Users/danplaton/.nvm/versions/node/v22.19.0/lib/node_modules/@gsd-build/sdk/src/query/audit-open.ts` v1.41.2
- Existing categories to mirror in style: `scanVerificationGaps` (lines 336–387) and `scanUatGaps` (lines 280–334) in the same file
- CLI exit-code convention reference: `node_modules/@gsd-build/sdk/src/cli.ts` — handlers set `process.exitCode = 1` directly; no special handler-result field needed

### State & traceability
- `.planning/STATE.md` — current milestone v0.3, position "Phase 16 — Governance Carry-Forward (next up)"
- `.planning/ROADMAP.md` line 40 — phase row & GOV-01 anchor
- `.planning/PROJECT.md` §"Current Milestone — v0.3 Adoption Surface" — milestone context

### Pattern reference (not a constraint, but useful)
- `patch-package` npm tool docs — standard pattern for in-repo patches against installed dependencies

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `scanVerificationGaps()` in audit-open.ts (lines 336–387) is the closest template for `scanVerificationStaleHumanNeeded` — same phase-dir iteration, same frontmatter parsing, just add the date comparison.
- `extractFrontmatter()` from `./frontmatter.js` already used by every scan function — handles the `human_needed_since:` field automatically with no extra code.
- `formatAuditReport()` (lines 588–704) already has the severity-tiered output structure — new categories drop in by appending two more `if (counts.X > 0)` blocks in the right tier order.
- `AuditOpenResult` interface (lines 463–489) is the canonical shape — extend `counts` and `items` with the two new categories.

### Established Patterns
- Each scan function returns `Array<Record<string, unknown>>` with a `scan_error: true` sentinel for I/O failures. New scans follow the same shape.
- `countReal()` filter excludes `scan_error` and `_remainder_count` from totals. No change needed.
- `has_open_items` is computed from `counts.total > 0`. New categories automatically gate milestone close once their counts are added to the sum.
- Frontmatter parsing handles missing fields gracefully (returns `undefined`); fallback logic for `human_needed_since` → mtime can branch on `fm.human_needed_since` truthiness.

### Integration Points
- The `auditOpen` query handler is exported and registered in `dist/query/audit-open.js`. Patch must rebuild or directly patch `dist/` — verify which the upstream CI ships.
- `gsd-sdk query audit-open` CLI invocation runs through `cli.ts` dispatcher; `--strict` arg threading needs no dispatcher change (args[] is passed directly to the handler).
- `milestone.complete` workflow (separate file in SDK) currently consumes `has_open_items` — needs no change to pick up the new categories.

</code_context>

<deferred>
## Deferred Ideas

- **Configurable TTL threshold** — instead of hard-coded 72h. Defer until/unless a second project wants a different cadence.
- **`--audit-categories=` allow-list flag** — useful for CI scripts that only care about specific gaps. Defer; not in this phase's scope.
- **Auto-conversion of stale `human_needed` to a `Known Gaps` entry** — retrospective hinted at it ("auto-escalate to gap-closure planning"). Out of scope here; this phase only adds detection. Conversion is a future phase if/when the workflow exists.
- **Reconsider `.planning/` gitignore status** — retrospective item #3, separate concern, separate phase.
- **Root-cause the executor sandbox denial from plan 15-01** — retrospective item #4, separate concern, separate phase.

</deferred>

---

*Phase: 16-governance-carry-forward*
*Context gathered: 2026-05-15*
