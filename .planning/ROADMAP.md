# Roadmap: Symfony Tenancy Bundle

## Milestones

- ✅ **v0.2 — Architectural Fixes** — Phases 1–15 (shipped 2026-04-20, tag v0.2.1)
- ✅ **v0.3 — Adoption Surface** — Phases 17–23 (shipped 2026-05-29, tag v0.3.3)
- ✅ **v0.4 — Storage & Shared Entities** — Phases 24–30 (shipped 2026-06-19, tag v0.4.0)
- ✅ **v0.5 — Operations & Scale** — Phases 31–34 (shipped 2026-07-06, tag v0.5.0)

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

<details>
<summary>✅ v0.5 Operations & Scale (Phases 31–34) — SHIPPED 2026-07-06 (tag v0.5.0)</summary>

Per-tenant operability at scale: parallel `tenancy:migrate` (bounded subprocess worker pool), per-tenant maintenance mode (HTTP 503 + `Retry-After` + allow-list bypass), tenant health checks (IETF `application/health+json` endpoints + `tenancy:health` CLI + optional LiipMonitorBundle), a production `docs/ops/` section, plus the v0.4 carry-forward closure (examples/saas PHP-version drift, Nyquist enforcement policy, and the two `human_needed` UAT items converted to regression tests).

- [x] Phase 31: Parallel Migrations (2 plans) — ISOL-07..12 — completed 2026-06-26
- [x] Phase 32: Maintenance Mode (4 plans) — MAINT-01..09 — completed 2026-07-01
- [x] Phase 33: Health Checks (5 plans) — HEALTH-01..07 — completed 2026-07-06
- [x] Phase 34: Ops Docs & Carry-Forward (5 plans) — DOC-21/DEMO-02/GOV-02/QA-01 — completed 2026-07-06

Full detail: `.planning/milestones/v0.5-ROADMAP.md` · `.planning/milestones/v0.5-REQUIREMENTS.md`

</details>

## Progress

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 1–15. Foundation → Architectural Fixes | v0.2 | 48/48 | Complete | 2026-04-20 |
| 17–23. Adoption Surface | v0.3 | 53/53 | Complete | 2026-05-29 |
| 24. Filesystem Bootstrapper | v0.4 | 10/10 | Complete | 2026-06-03 |
| 25. Shared Entities (Sync) | v0.4 | 5/5 | Complete | 2026-06-11 |
| 26. `tenancy:shared:resync` | v0.4 | 4/4 | Complete | 2026-06-13 |
| 27. Async Shared Entities | v0.4 | 3/3 | Complete | 2026-06-15 |
| 28. PHPStan Extension | v0.4 | 7/7 | Complete | 2026-06-17 |
| 29. Docs Refresh | v0.4 | 3/3 | Complete | 2026-06-18 |
| 30. v0.4 pre-tag closure | v0.4 | 2/2 | Complete | 2026-06-19 |
| 31. Parallel Migrations | v0.5 | 2/2 | Complete | 2026-06-26 |
| 32. Maintenance Mode | v0.5 | 4/4 | Complete | 2026-07-01 |
| 33. Health Checks | v0.5 | 5/5 | Complete | 2026-07-06 |
| 34. Ops Docs & Carry-Forward | v0.5 | 5/5 | Complete | 2026-07-06 |
