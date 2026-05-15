# Phase 16: Governance Carry-Forward — Discussion Log

**Discussion date:** 2026-05-15
**Mode:** `discuss` (default, no flags)
**Purpose:** Human-readable audit trail of the discussion that produced `16-CONTEXT.md`. Not consumed by downstream agents.

---

## Pre-discussion analysis

**Phase scope (from ROADMAP.md / REQUIREMENTS.md GOV-01):**
- Extend `gsd-sdk query audit-open` with plan↔summary parity check
- Extend `gsd-sdk query audit-open` with `human_needed` 72h TTL check
- Tooling-only, not bundle source
- Mark items resolved in `RETROSPECTIVE.md`

**Discovered upstream realities:**
- The `audit-open` tool lives in `@gsd-build/sdk` (npm package, v1.41.2 installed), not in this repo. Source: `node_modules/@gsd-build/sdk/src/query/audit-open.ts`.
- The SDK is maintained externally — user confirmed they don't own it but can submit updates.
- Existing scan structure uses 🔴/🟡/🔵 severity tiers with `scanVerificationGaps`, `scanUatGaps`, etc. — clean extension points exist.
- CLI dispatcher honors `process.exitCode` set by handlers — `--strict` flag is straightforward to implement.

**Prior decisions carried forward:**
- v0.2 retrospective evidence: 4 plans (09-03, 09-04, 11-04, 11-05) shipped without SUMMARY.md; 3 phases (09, 10, 12) carried `human_needed` for 7–42 days. These are the test-fixture facts.
- v0.2 lesson: "Planning tools enforce what they check" — drives the 🔴-tier severity decision.

---

## Discussion areas

### Area 1: Code location & delivery vehicle

**Question presented:** Where does the change live? Upstream-only PR, vendored copy, hybrid patch + PR, or patch-package style?

**Options offered:**
1. Upstream PR to `@gsd-build/sdk`
2. Vendor under `.claude/get-shit-done/`
3. Local sdk patch + upstream PR (hybrid)
4. Patch script applied at install (patch-package)

**User answer:** "we don't actually work out the gsd, it's a package managed by somebody else, but if needed update we can do" — request for best-practice recommendation.

**Recommendation taken:** Hybrid — `patch-package` style in this repo (D-01a/b/c) AND upstream PR in parallel (D-01d).

**Rationale:**
- Upstream-only blocks this phase on someone else's review cadence.
- Vendored copy creates divergence on future SDK releases.
- Patch-based hybrid is the standard pattern for "need upstream not-yet-shipped behavior": tracks the exact diff, applies cleanly, disappears when upstream catches up.

### Area 2: Plan↔summary parity rule

**Question presented:** What counts as a plan-without-summary gap?

**Options offered:**
1. Strict 1:1 — PLAN.md exists → SUMMARY.md must exist
2. Frontmatter status — only flag if PLAN.md is `status: complete`
3. Age-based — PLAN.md older than 7 days requires SUMMARY.md
4. Git-aware — PLAN.md committed → SUMMARY.md required

**User answer:** "do the best practice" — request for recommendation.

**Recommendation taken:** Git-aware (D-02). PLAN.md tracked by git AND no sibling SUMMARY.md tracked by git → gap.

**Rationale:**
- Matches the exact v0.2 incident (committed plans, no summary).
- Strict 1:1 false-positives on in-flight plans the instant `gsd-plan-phase` commits.
- Frontmatter status discipline isn't reliable.
- Age thresholds are arbitrary.
- One `git ls-files .planning/phases/` call covers the whole scan — fast.

### Area 3: 72h TTL signal source

**Question presented:** How do we compute "human_needed for >72 hours"?

**Options offered:**
1. Frontmatter `updated:` field
2. File mtime
3. Git log: first commit that introduced `human_needed`
4. Frontmatter `human_needed_since:` (new explicit field)

**User answer:** "which is the recommended" — request for recommendation.

**Recommendation taken:** Option 4 with mtime fallback (D-03).

**Rationale:**
- `updated:` conflates any edit with a status transition — wrong semantics.
- File mtime alone is clobbered by checkouts, edits, etc.
- Git log is correct but per-file subprocess calls are slow.
- Explicit `human_needed_since:` is one-line agent change, zero ambiguity. mtime fallback prevents legacy files from escaping the audit.

### Area 4: Report severity & exit behavior

**Question presented:** How loud should the new findings be? Should `audit-open` exit non-zero?

**Options offered:**
1. Both 🔴 critical, no exit code change
2. Both 🔴 critical AND non-zero exit when found
3. Plan-parity 🔴, stale-human-needed 🟡
4. Both 🟡 warning tier

**User answer:** "which is the best practice?" — request for recommendation.

**Recommendation taken:** Both 🔴 critical, plus opt-in `--strict` flag for non-zero exit (D-04).

**Rationale:**
- These checks exist *because* the retrospective said they should block close — warning tier defeats the purpose.
- Hard-changing default exit to 1 could break unknown scripted callers of `audit-open`. Opt-in `--strict` is additive — zero breakage for existing callers, full enforcement for CI/milestone-close gates.

---

## Scope-creep redirected

None — all four areas stayed within the GOV-01 acceptance criteria.

---

## Claude's discretion items

- Patch-package invocation surface (script vs npm hook vs makefile)
- `--strict` failure message phrasing
- `human_needed_since:` documentation snippet in `RETROSPECTIVE.md`
- Whether to bundle an `--audit-categories=` allow-list flag now (skip unless trivial)

---

## Deferred to other phases

- Configurable TTL threshold (defer until a second project needs it)
- Auto-escalation of stale `human_needed` to Known Gaps entries
- `.planning/` gitignore reconsideration (retrospective item #3)
- Executor sandbox-denial root-cause (retrospective item #4)

---

*Discussion conducted: 2026-05-15*
*Mode: discuss (default, no flags)*
*CONTEXT.md produced: 16-CONTEXT.md*
