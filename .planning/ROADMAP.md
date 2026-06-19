# Roadmap: Symfony Tenancy Bundle

## Milestones

- ✅ **v0.2 — Architectural Fixes** — Phases 1–15 (shipped 2026-04-20, tag v0.2.1)
- ✅ **v0.3 — Adoption Surface** — Phases 17–23 (shipped 2026-05-29, tag v0.3.3)
- ✅ **v0.4 — Storage & Shared Entities** — Phases 24–30 (shipped 2026-06-19, tag v0.4.0)
- 📋 **v0.5 — Operations & Scale** — planned (run `/gsd:new-milestone`)

## Phases

<details>
<summary>✅ v0.2 Architectural Fixes (Phases 1–15) — SHIPPED 2026-04-20</summary>

Core foundation + resolvers, both isolation drivers (database-per-tenant + shared-DB), infrastructure bootstrappers (Doctrine/cache), Messenger + CLI integration, DX/OSS readiness (MkDocs site, `tenancy:init`), and four downstream-defect architectural fixes (cache decorator contract parity, nullable resolver returns, DBAL 4 driver-middleware, docs alignment).

Full detail: `.planning/milestones/v0.2-ROADMAP.md` · `.planning/milestones/v0.2-REQUIREMENTS.md`

</details>

<details>
<summary>✅ v0.3 Adoption Surface (Phases 17–23) — SHIPPED 2026-05-29 (tag v0.3.3)</summary>

`tenancy:install` one-command setup, per-tenant Mailer bootstrapper (sync + async), Profiler "Tenancy" panel, `OriginHeaderResolver`, runnable two-tenant demo (`examples/saas/`), docs refresh, and an audit-driven tech-debt closure phase. Phase 16 (GOV-01) skipped as a non-functional gate.

Full detail: `.planning/milestones/v0.3-ROADMAP.md` · `.planning/milestones/v0.3-REQUIREMENTS.md`

</details>

<details>
<summary>✅ v0.4 Storage & Shared Entities (Phases 24–30) — SHIPPED 2026-06-19 (tag v0.4.0)</summary>

- [x] Phase 24: Filesystem Bootstrapper (10 plans) — BOOT-03 — completed 2026-06-03
- [x] Phase 25: Shared Entities (Sync mode) (5 plans) — SHARE-01 — completed 2026-06-11
- [x] Phase 26: `tenancy:shared:resync` command (4 plans) — SHARE-02 — completed 2026-06-13
- [x] Phase 27: Async Shared Entities (3 plans) — SHARE-03 — completed 2026-06-15
- [x] Phase 28: PHPStan Extension (7 plans) — DX-03 — completed 2026-06-17
- [x] Phase 29: Docs Refresh (3 plans) — DOC-20 — completed 2026-06-18
- [x] Phase 30: v0.4 pre-tag closure (2 plans, audit-driven) — W-/WR-/D- closure — completed 2026-06-19

Full detail: `.planning/milestones/v0.4-ROADMAP.md` · `.planning/milestones/v0.4-REQUIREMENTS.md` · `.planning/milestones/v0.4-MILESTONE-AUDIT.md`

</details>

### 📋 v0.5 Operations & Scale (Planned — next milestone)

Production-readiness. Candidate requirements (refined in `/gsd:new-milestone`):

- **OPS-01** — Tenant-level maintenance mode
- **OPS-02** — Health check / MonitorBundle integration
- **ISOL-07** — Parallel `tenancy:migrate` via `symfony/process`
- New ops docs section: deploy guide, runbook patterns
- Carry-forward: `examples/saas/` Dockerfile vs `composer.lock` PHP-version drift (tech debt from v0.3/v0.4); root-cause executor sandbox denial (plan 15-01)

**Start with:** `/gsd:new-milestone`.

## Progress

| Phase                                | Milestone | Plans Complete | Status   | Completed  |
| ------------------------------------ | --------- | -------------- | -------- | ---------- |
| 1–15. Foundation → Architectural Fixes | v0.2      | 48/48          | Complete | 2026-04-20 |
| 17–23. Adoption Surface              | v0.3      | 53/53          | Complete | 2026-05-29 |
| 24. Filesystem Bootstrapper          | v0.4      | 10/10          | Complete | 2026-06-03 |
| 25. Shared Entities (Sync)           | v0.4      | 5/5            | Complete | 2026-06-11 |
| 26. `tenancy:shared:resync`          | v0.4      | 4/4            | Complete | 2026-06-13 |
| 27. Async Shared Entities            | v0.4      | 3/3            | Complete | 2026-06-15 |
| 28. PHPStan Extension                | v0.4      | 7/7            | Complete | 2026-06-17 |
| 29. Docs Refresh                     | v0.4      | 3/3            | Complete | 2026-06-18 |
| 30. v0.4 pre-tag closure             | v0.4      | 2/2            | Complete | 2026-06-19 |
| v0.5 Operations & Scale              | v0.5      | —              | Planned  | -          |
