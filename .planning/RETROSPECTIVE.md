# Retrospective — Symfony Tenancy Bundle

Living retrospective. One section per shipped milestone. Patterns, lessons, and cost observations accumulate across releases; the Cross-Milestone Trends section at the bottom summarizes.

---

## Milestone: v0.2 — Architectural Fixes

**Shipped:** 2026-04-20
**Phases:** 15 | **Plans:** 48 | **Tests:** 304 / 739 assertions
**Timeline:** 2026-03-17 → 2026-04-20 (~34 days)

### What Was Built

- **Core foundation & resolvers** (Phases 1–2): event-driven `TenantContext` + four resolvers (Host, Header, QueryParam, Console) with pluggable chain
- **Isolation drivers** (Phases 3–4): database-per-tenant (DBAL connection switch) + shared-DB (Doctrine SQL filter with `#[TenantAware]`) + strict mode
- **Infrastructure bootstrappers** (Phase 5): Doctrine identity-map reset, cache namespace isolation
- **Cross-cutting integration** (Phases 6–7): Messenger stamp + middlewares, `tenancy:migrate` + `tenancy:run` CLI commands
- **DX & OSS readiness** (Phases 8–12): `InteractsWithTenancy` PHPUnit trait, Packagist metadata, MkDocs Material site deployed to GitHub Pages, `tenancy:init` scaffolding command
- **Audit & cleanup** (Phases 13–14): resolver config wiring, type/signature fixes, Flex recipe removal, docs accuracy pass
- **Architectural fixes** (Phase 15): four defects from downstream demo projects (#5–#8) resolved at the architecture level — cache decorator contract parity + compile-time guard, nullable resolver returns, DBAL 4 driver-middleware replacement for `wrapperClass`, documentation alignment

### What Worked

- **Wave-based parallel execution** on Phase 15 (2 plans wave-1, 1 plan wave-2, 1 plan wave-3) cut wall time substantially without conflicts — worktree isolation + post-merge test gate caught zero integration bugs.
- **Retractions over patches**: when v1.0.0 surfaced four defects in downstream projects, the team retracted the tag and redid the work as architectural fixes rather than point-patches. Result: issue #5's fix shipped with a compile-time `CacheDecoratorContractPass` that prevents the *class* of bug from returning, not just the specific instance.
- **Compile-time guards > runtime assertions**: The `CacheDecoratorContractPass` pattern converted a "silent at boot, explodes at consumption" failure mode into a deterministic container compilation error with a descriptive message.
- **TDD with RED/GREEN/REFACTOR commit trail** (Phase 15 plans 15-01, 15-02, 15-03): test-first commits created an auditable failure-mode record; every fix ships with evidence that it actually closes the regression.
- **GSD discuss → plan → execute cadence** for the v0.2 fixes avoided the scope creep that often accompanies architectural rework. Each of the four fixes was its own plan with locked scope; no fix silently grew to include the next.

### What Was Inefficient

- **Executor sandbox denial mid-plan (Phase 15-01)**: the executor agent hit repeated "Permission has been denied" errors after committing Task 3's RED. Recovery required orchestrator-level intervention to commit the stranded Task 3 GREEN, finish Tasks 4 & 5 inline, and author the SUMMARY.md manually. Lesson: orchestrator recovery path is robust, but the root cause of the sandbox denial is unresolved and could recur.
- **Planning bookkeeping drift**: four plans (09-03, 09-04, 11-04, 11-05) shipped their artifacts but never had SUMMARY.md files written. Caught at milestone close. Cost: retroactive summary authoring at a moment when the focus should be closing, not reconstructing history.
- **Human-verification items that were never revisited**: Phases 09, 10, 12 all shipped with `human_needed` VERIFICATION items ("needs manual CLI run", "needs CI confirmation"). None were followed up on until milestone close. Lesson: human-verification status should either (a) resolve within a phase window or (b) auto-convert to a Known Gaps entry, not quietly accumulate for weeks.
- **Non-Doctrine `tenancy:init` path was unreachable in tests** for months — a trivial refactor (protected `detectDoctrine()` seam) done at milestone close would have been worth doing in Phase 12. Lesson: when a VERIFICATION item says "can't test this in our environment", treat that as a code-level testability defect, not just a documentation item.
- **Audit gap unseen by automated checks**: the audit-open tool checks phase UAT / debug / quick tasks but doesn't enforce plan↔summary parity. Milestone-close readiness depended on `roadmap analyze` which *did* catch it, but only when asked.

### Patterns Established

- **RED / GREEN / REFACTOR commit trail for architectural fixes** — test commit first (with failing-test evidence), implementation second, cleanup optional. Makes the regression auditable in `git log`.
- **Retroactive SUMMARY.md authoring** when plans were executed but not tracked — include `retroactive: true` and `retroactive_note:` fields in frontmatter so the drift is visible.
- **Seam extraction for environment-dependent detection** (`detectDoctrine()`, etc.) — when unit tests can't exercise a branch, extract the decision into a protected method overridable in test subclasses rather than mocking `interface_exists()`.
- **`.planning/`-gitignore repos** require `git add -f` to commit any SUMMARY.md / VERIFICATION.md / PROJECT.md. Workflow agents need explicit instruction.
- **Worktree merge protocol** handles intentional deletions (TenantConnection removal in Phase 15) without triggering the deletion guard once the scope is documented in the plan.
- **Post-merge test gate + full `vendor/bin/phpunit` run** after each worktree merge — not per-plan, per-wave. Catches integration conflicts that per-plan self-checks cannot see.

### Key Lessons

1. **Architectural fixes beat point-patches even at the cost of a retracted tag.** v1.0.0 was retracted and the line restarted at v0.1.0. v0.2.0 ships clean and the regression class is closed at the DI layer, not papered over at the usage site.
2. **Human-verification items are technical debt, not documentation.** Every `human_needed` VERIFICATION status should either resolve within 72 hours or become a code-level testability issue.
3. **Planning tools enforce what they check.** `audit-open` gave false clear because it didn't check plan/summary parity. Adding a check there would have caught the four missing summaries months ago.
4. **Tag retractions are cheap; defect retractions are cheaper.** Retracting v1.0.0 within 24 hours cost nothing downstream (two self-installs per Packagist stats, zero external dependents). Had the defects made it into real adoption, the cost would have been 100× higher.
5. **Post-merge test gate is non-negotiable for parallel execution.** Worktree isolation makes each plan's Self-Check pass locally; the cross-plan integration bugs only surface after merge. The per-wave test gate caught zero bugs in v0.2 — that *is* the signal that the gate works.
6. **`.planning/` being gitignored is a smell.** It forces every commit of planning artifacts through `git add -f`, and means planning history can diverge from code history. Worth reconsidering for v0.3.

### Cost Observations

- **Model mix (Phase 15 executor agents):** Sonnet 4.6 for plans, Opus 4.6 for orchestration. Approximately 1M tokens across 4 executor agents + 1 verifier + 1 code-reviewer + 1 code-fixer.
- **Sessions:** Phase 15 execution ran in a single long session (~3 hours wall time including sandbox-denial recovery for plan 15-01).
- **Notable efficiency wins:**
  - Wave-based parallel execution for plans 15-01 & 15-02 cut sequential time by ~40%.
  - Delegating code review and fix to specialized subagents kept orchestrator context lean (~15%) while still producing actionable results (5 warnings all auto-fixed).
  - `gsd-sdk query milestone.complete` automated MILESTONES.md, STATE.md, and milestone archive files — saving ~20 minutes of manual bookkeeping.
- **Notable efficiency losses:**
  - Sandbox denial during plan 15-01 cost an estimated 30 minutes of recovery work and manual test/SUMMARY authoring.
  - Retroactive SUMMARY authoring for four plans (09-03, 09-04, 11-04, 11-05) at milestone close cost ~15 minutes — preventable with per-phase plan/summary parity checks.

---

## Cross-Milestone Trends

### Planning-vs-Execution Drift

| Milestone | Plans Executed | Plans with SUMMARY at close | Retroactive summaries needed |
|-----------|----------------|------------------------------|-------------------------------|
| v0.2      | 48             | 44 (at close)                | 4                             |

### Human-Verification Resolution Latency

| Milestone | `human_needed` at phase close | Still `human_needed` at milestone close | Days latent |
|-----------|--------------------------------|------------------------------------------|-------------|
| v0.2      | 3 (phases 09, 10, 12)          | 3                                        | 7–42        |

### Retrospective Action Items (carry forward)

1. Add plan↔summary parity to `audit-open` so missing SUMMARY.md is caught inside the phase, not at milestone close.
2. Add a 72-hour TTL to `human_needed` VERIFICATION status — auto-escalate to gap-closure planning after expiry.
3. Reconsider whether `.planning/` should remain gitignored for future milestones.
4. Root-cause the executor sandbox denial seen in plan 15-01.
