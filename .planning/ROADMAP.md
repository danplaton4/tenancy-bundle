# Roadmap: Symfony Tenancy Bundle

## Milestones

- ✅ **v0.2 Architectural Fixes** — Phases 1–15 (shipped 2026-04-20)
- ✅ **v0.3 Adoption Surface** — Phases 17–23 (shipped 2026-05-29, tag v0.3.3)
- 📋 **v0.4 Storage & Shared Entities** — Filesystem bootstrapper, shared entities sync/async, PHPStan extension
- 📋 **v0.5 Operations & Scale** — maintenance mode, health checks, parallel migrations
- 📋 **v0.6 Advanced Isolation** *(demand-gated)* — PostgreSQL RLS driver; v1.0 candidate if adoption validates

## Phases

<details>
<summary>✅ v0.2 Architectural Fixes (Phases 1–15) — SHIPPED 2026-04-20</summary>

- [x] Phase 1: Core Foundation (completed 2026-03-18)
- [x] Phase 2: Tenant Resolution (completed 2026-03-18)
- [x] Phase 3: Database-Per-Tenant Driver (completed 2026-03-19)
- [x] Phase 4: Shared-DB Driver (completed 2026-03-19)
- [x] Phase 5: Infrastructure Bootstrappers (completed 2026-03-19)
- [x] Phase 6: Messenger Integration (completed 2026-03-20)
- [x] Phase 7: CLI Commands (completed 2026-03-21)
- [x] Phase 8: Developer Experience — InteractsWithTenancy (completed 2026-04-02)
- [x] Phase 9: OSS Hardening (completed 2026-04-12)
- [x] Phase 10: Dependency Compatibility Audit (completed 2026-04-10)
- [x] Phase 11: Documentation Site — MkDocs Material (completed 2026-04-12)
- [x] Phase 12: Developer Onboarding — tenancy:init (completed 2026-04-13)
- [x] Phase 13: Audit Gap Closure (completed 2026-04-13)
- [x] Phase 14: Documentation refresh — remove Flex (completed 2026-04-14)
- [x] Phase 15: Architectural Fixes (v0.2) — cache, resolver, DBAL middleware, docs (completed 2026-04-20)

**Full details:** See `.planning/milestones/v0.2-ROADMAP.md` for phase goals, requirements, and plan breakdowns.

</details>

<details>
<summary>✅ v0.3 Adoption Surface (Phases 17–23, Phase 16 SKIPPED) — SHIPPED 2026-05-29 (tag v0.3.3)</summary>

- ⊘ Phase 16: Governance Carry-Forward — SKIPPED (non-functional gate, decision 2026-05-15)
- [x] Phase 17: OriginHeaderResolver (completed 2026-05-15) — RESV-06
- [x] Phase 18: tenancy:install (completed 2026-05-22, shipped in v0.3.0) — DX-06
- [x] Phase 19: Profiler Tab (completed 2026-05-19) — DX-02
- [x] Phase 20: Mailer Bootstrapper (completed 2026-05-20) — BOOT-04
- [x] Phase 21: Demo App (completed 2026-05-22, shipped in v0.3.2) — DEMO-01
- [x] Phase 22: Docs Refresh (completed 2026-05-28, shipped in v0.3.3) — DOC-19
- [x] Phase 23: v0.3 Tech-Debt Closure (completed 2026-05-29) — audit-driven closure

**Full details:** See `.planning/milestones/v0.3-ROADMAP.md` for phase goals, requirements, plan breakdowns, and the milestone summary (decisions, issues resolved, tech debt). Audit at `.planning/milestones/v0.3-MILESTONE-AUDIT.md`.

</details>

### 📋 Later Milestones

| Milestone | Theme | Key items |
|-----------|-------|-----------|
| v0.4 | Storage & Shared Entities | BOOT-03, SHARE-01/02/03, DX-03 PHPStan extension |
| v0.5 | Operations & Scale | OPS-01, OPS-02, ISOL-07 parallel migrations |
| v0.6 | Advanced Isolation | ISOL-06 PostgreSQL RLS (demand-gated; v1.0 candidate) |

### Future / By Demand

User-requestable but unscheduled. See `.planning/PROJECT.md#future--by-demand` for the canonical list and `ROADMAP.md` (repo root) for the public-facing version.

## Progress

| Milestone | Phases | Plans   | Status   | Shipped    | Tag     |
| --------- | ------ | ------- | -------- | ---------- | ------- |
| v0.2      | 1–15   | 48/48   | Complete | 2026-04-20 | v0.2.0  |
| v0.3      | 17–23  | 53/53   | Complete | 2026-05-29 | v0.3.3  |

*Next milestone: v0.4 Storage & Shared Entities — start with `/gsd:new-milestone`.*
