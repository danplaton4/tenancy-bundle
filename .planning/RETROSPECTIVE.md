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

## Milestone: v0.4 — Storage & Shared Entities

**Shipped:** 2026-06-19 (tag v0.4.0)
**Phases:** 7 (24–30) | **Plans:** 34 | **Tests:** 770 / 3242 assertions
**Timeline:** 2026-05-29 → 2026-06-19 (~21 days)

> Note: v0.3 (Adoption Surface) shipped 2026-05-29 but a retrospective section was not appended at its close. Its history lives in `.planning/MILESTONES.md` and `.planning/milestones/v0.3-ROADMAP.md`.

### What Was Built

- **Filesystem bootstrapper** (Phase 24): per-tenant Flysystem with `prefix` + `per_tenant_adapter` modes, `FilesystemContractPass` compile-time guard, LRU-cached operators, credential-redacted exceptions
- **Shared-entity sync model** (Phases 25–27): `#[Shared]` landlord→tenant fan-out via Doctrine `postFlush`, compile-time mutual exclusion with `#[TenantAware]`, tenant-side write protection, `tenancy:shared:resync` (idempotent, continue-on-failure), and opt-in async fan-out via Messenger with a scalar message + re-fetch-at-handle
- **PHPStan extension** (Phase 28): three consumer-facing rules (mutual exclusion, shared-entity leak, tenant_id drift) shipped via extension-installer auto-load, graceful degradation without phpstan-doctrine, no-doctrine CI lane
- **Docs refresh** (Phase 29): new shared-entities + phpstan-extension pages, UPGRADE 0.3→0.4 (no breaking changes), docs-lint shared-entity disambiguation check
- **Audit-driven pre-tag closure** (Phase 30): single `TenantEmSwitcher` de-duplicating tenant-switch logic, `SharedEntityCopierInterface` mock seam, docs/roadmap reconciliation, docs-lint awk fix

### What Worked

- **Wave-0 RED test scaffold before production code** (Phases 24/25/26): each shared-entity phase opened with skip-guarded named test stubs (`SHARE-0X-a..m`), turning acceptance criteria into a concrete checklist the executor filled in. Made "done" unambiguous.
- **Extracting the single write path early** (`SharedEntityCopier`, Phase 26): once the copier was the one place tenant-side writes happen, the async handler (Phase 27) and the resync command reused it instead of re-implementing fan-out — three call sites, one source of truth.
- **Compile-time guards as the v0.4 default** (`FilesystemContractPass`, `SharedEntityMutualExclusionPass`, `SharedAsyncContractPass`): every optional-dependency / misuse failure mode converted to a container-compile error with a descriptive message, consistent with the v0.2 `CacheDecoratorContractPass` pattern.
- **Shipping a PHPStan extension to catch the bug class at edit time** (Phase 28): the `#[Shared]`/`#[TenantAware]` mutual-exclusion invariant is enforced three ways — compile-time pass, PHPStan rule, and runtime — so consumers get the earliest possible signal.
- **Re-audit after the closure phase** (Phase 30): the first v0.4 audit produced `tech_debt` and spawned Phase 30; the re-audit confirmed 11/11 and 0 blockers before tagging. Closing the loop on the audit prevented shipping with stale findings.

### What Was Inefficient

- **ROADMAP.md rolled forward to only the last 1–3 phases during execution**, so at milestone close the live ROADMAP held only phases 28–30 and the `milestone complete` CLI's phase filter could only see Phase 30. The full v0.4 archive had to be reconstructed by hand from SUMMARY one-liners + git. Lesson: either keep all in-milestone phases in ROADMAP.md until close, or have the archive step read from the phases directory rather than the (lossy) live ROADMAP.
- **Nyquist VALIDATION.md coverage drifted** — 5 of 7 phases (24/26/28/29/30) reached close without a compliant VALIDATION.md. The live suite stayed green so it never blocked, but the discovery flags accumulated exactly like v0.2's `human_needed` items did.
- **Two `human_needed` UAT items carried the full milestone** (Phase 26 TTY confirm, Phase 28 extension-installer auto-load) — the same "human-verification as deferred debt" pattern flagged in the v0.2 retro recurred. Both are genuinely environment-gated, but they were never converted to code-level testability seams.
- **Three code-review warnings deferred from Phase 29 to Phase 30** (WR-03 awk bug, WR-06/07 roadmap drift) rather than fixed in-phase — they were in-scope-adjacent and folding them forward was correct, but it grew the closure phase.

### Patterns Established

- **Wave-0 skip-guarded named-scenario test scaffold** — open a feature phase with `markTestSkipped`-guarded test methods named after acceptance criteria; flip green as production code lands. Multi-class skip-guards check `class_exists` on every required symbol to avoid false un-skipping mid-phase.
- **Single mutating service reused across sync/async/CLI** — extract the write path (`SharedEntityCopier`) and tenant-switch (`TenantEmSwitcher`) into one injected, interface-typed, mockable service rather than duplicating logic per consumer.
- **Optional-dependency phases ship a no-dep CI lane** — Phase 28's no-doctrine lane (and the Flysystem/Messenger guards) prove the `interface_exists`/`class_exists` guards actually degrade gracefully, not just in theory.
- **Audit → closure phase → re-audit before tag** — when `/gsd:audit-milestone` returns `tech_debt`, fold the code-closeable items into one inserted closure phase, then re-audit to confirm before `/gsd:complete-milestone`.

### Key Lessons

1. **Don't let ROADMAP.md lose in-milestone history before close.** A rolling/summary ROADMAP is fine for navigation but the milestone archive must draw from a complete source (phases dir or git), or the close step silently under-reports scope.
2. **Three-way invariant enforcement is worth it for data-leak bug classes.** `#[Shared]`/`#[TenantAware]` exclusivity is caught at compile time, by PHPStan at edit time, and at runtime — defense in depth where a single missed guard is a cross-tenant leak.
3. **`human_needed` UAT is still debt, two milestones later.** The v0.2 lesson ("human-verification items are technical debt, not documentation") recurred verbatim in v0.4. The durable fix is a code-level testability seam, not a follow-up reminder.
4. **Re-auditing after a closure phase pays for itself.** It caught that the "tenant-switch twin without drift test" advisory was made moot by the W-02 extraction — avoiding a phantom follow-up.

### Cost Observations

- **Model mix:** Opus for orchestration + planning/verification; executor agents per plan. Wave-based parallelization used where plans were independent (e.g. Phase 30's two plans ran in parallel).
- **Sessions:** ~21 calendar days, phases shipped roughly every 2–3 days (24→30).
- **Notable:** The single-write-path extraction (Phase 26) front-loaded effort that paid off in Phases 27 and 30 — async fan-out and the mock seam were cheap because the copier already existed. The hand-reconstructed archive at close (~20 min) was the main avoidable cost, traceable to the rolled-forward ROADMAP.

---

## Cross-Milestone Trends

### Planning-vs-Execution Drift

| Milestone | Plans Executed | Plans with SUMMARY at close | Retroactive summaries needed |
|-----------|----------------|------------------------------|-------------------------------|
| v0.2      | 48             | 44 (at close)                | 4                             |
| v0.4      | 34             | 34                           | 0                             |

### Human-Verification Resolution Latency

| Milestone | `human_needed` at phase close | Still `human_needed` at milestone close | Days latent |
|-----------|--------------------------------|------------------------------------------|-------------|
| v0.2      | 3 (phases 09, 10, 12)          | 3                                        | 7–42        |
| v0.4      | 2 (phases 26, 28)              | 2                                        | 2–6         |

### Retrospective Action Items (carry forward)

1. ⊘ ~~Add plan↔summary parity to `audit-open` so missing SUMMARY.md is caught inside the phase, not at milestone close.~~ **Acknowledged as a known gap (2026-05-15) but not actioned.** Originally captured as GOV-01 / Phase 16; skipped as non-functional. We don't own `@gsd-build/sdk` and a parallel local audit tool isn't worth the maintenance cost relative to bundle-user value. Mitigation: humans watching this retrospective.
2. ⊘ ~~Add a 72-hour TTL to `human_needed` VERIFICATION status — auto-escalate to gap-closure planning after expiry.~~ **Acknowledged as a known gap (2026-05-15) but not actioned.** Same reasoning as #1. Mitigation: humans watching this retrospective; treat any new `human_needed` as code-level testability debt to resolve in the same phase, not as a future-self problem.
3. ✓ Reconsider whether `.planning/` should remain gitignored for future milestones. **Resolved:** `.planning/` is now tracked (`commit_docs: true` locked); planning history committed alongside code.
4. Root-cause the executor sandbox denial seen in plan 15-01. (Still open — not recurred in v0.4.)
5. **(v0.4)** Keep all in-milestone phases in ROADMAP.md until close, OR have the archive step read from the phases directory rather than the rolled-forward live ROADMAP — which under-reported v0.4 scope to the `milestone complete` CLI.
6. **(v0.4)** Convert the two carried `human_needed` UAT items (Phase 26 TTY confirm, Phase 28 extension-installer auto-load) to code-level testability seams instead of leaving them environment-gated.
7. **(v0.4)** Decide whether v0.5 enforces Nyquist VALIDATION.md per phase or keeps it discovery-only — coverage drifted to 2/7 compliant in v0.4.
8. **(v0.4, carried from v0.3)** Resolve `examples/saas/composer.lock` vs `Dockerfile` PHP-version drift early in v0.5.
