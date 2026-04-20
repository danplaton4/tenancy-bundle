# Roadmap: Symfony Tenancy Bundle

## Milestones

- ✅ **v0.2 Architectural Fixes** — Phases 1–15 (shipped 2026-04-20)
- 📋 **Next milestone** — TBD (run `/gsd-new-milestone` to start)

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

### 📋 Next Milestone (TBD)

Run `/gsd-new-milestone` to start planning the next release. Candidate themes from PROJECT.md out-of-scope / backlog:
- Symfony Profiler "Tenancy" WDT tab (DX-02)
- PHPStan extension for `#[TenantAware]` correctness rules (DX-03)
- Async shared-entity fan-out via Messenger (SHARE-03)
- Tenant-level maintenance mode (OPS-01)
- Health check integration (OPS-02)
- `OriginHeaderResolver` for SPA auth (RESV-06)

## Progress

| Milestone | Phases | Plans | Status   | Shipped    |
| --------- | ------ | ----- | -------- | ---------- |
| v0.2      | 1–15   | 48/48 | Complete | 2026-04-20 |
