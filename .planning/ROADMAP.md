# Roadmap: Symfony Tenancy Bundle

## Milestones

- ✅ **v0.2 Architectural Fixes** — Phases 1–15 (shipped 2026-04-20)
- 📋 **v0.3 Adoption Surface** — install ergonomics + demo + Profiler tab + Mailer + OriginHeaderResolver + docs refresh
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

### 📋 v0.3 Adoption Surface — Phases 17–22 (6 active)

Goal: lower install friction + ship the highest-leverage missing features. 6 active phases, 6 active requirements. Phase 16 / GOV-01 skipped as a non-functional gate (see below). See `.planning/REQUIREMENTS.md` for full acceptance criteria and `.planning/research/SUMMARY.md` for the research synthesis.

- ⊘ **Phase 16: Governance Carry-Forward** — **SKIPPED.** Non-functional process tooling (`audit-open` extension). Retrospective items #1 (plan↔summary parity) and #2 (`human_needed` 72h TTL) acknowledged as gaps in `RETROSPECTIVE.md` but intentionally not enforced via tooling. Bundle-user value is zero; the v0.2 retrospective surfaces the lessons humans need without machine enforcement. Phase number retained for stable references; downstream phase numbers (17–22) unchanged.
- [x] **Phase 17: OriginHeaderResolver** — SPA-friendly resolver at priority 25, allow-list config, `OriginHeaderResolverConfigPass` guard (RESV-06) (completed 2026-05-15)
- [ ] **Phase 18: tenancy:install** — single-command setup (auto-registers bundle, runs `tenancy:init`), `nikic/php-parser` detection, ≥6 fixture corpus, atomic write + .bak (DX-06)
- [ ] **Phase 19: Profiler Tab** — `TenantDataCollector` + Twig template, dev-only, three render states (resolved/null/error), stored-profile reload tested (DX-02)
- [ ] **Phase 20: Mailer Bootstrapper** — `X-Transport` strategy (sync + async safe), `TenantInterface` BC break + trait migration, `MailerTransportContractPass` guard, async canary test (BOOT-04)
- [ ] **Phase 21: Demo App** — `examples/saas/` with FrankenPHP + Caddy + MariaDB, three-step fallback ladder, `bin/smoke.sh` CI release-gate (DEMO-01)
- [ ] **Phase 22: Docs Refresh** — install page rewrite, new pages (resolver/profiler/mailer/demo/roadmap), UPGRADE 0.2→0.3, docs-lint extended (DOC-19)

**Architectural decisions ratified** (see `REQUIREMENTS.md#architectural-decisions-ratified`): DEC-MAIL-01 X-Transport strategy, DEC-MAIL-02 full BOOT-04 in v0.3, DEC-MAIL-03 BC break with trait, DEC-RESV-01 priority 25, DEC-PROF-01 TenantResolved subscriber, DEC-INST-01 programmatic invoke, DEC-INST-02 refuse-on-nonstandard, DEC-DEMO-01 Caddy + `*.tenancy.localhost`.

**Explicit non-goal:** Symfony Flex recipe. Setup command is the supported onboarding path; revisit `symfony/recipes-contrib` only when install volume justifies the maintenance cost.

### 📋 Later Milestones

| Milestone | Theme | Key items |
|-----------|-------|-----------|
| v0.4 | Storage & Shared Entities | BOOT-03, SHARE-01/02/03, DX-03 PHPStan extension |
| v0.5 | Operations & Scale | OPS-01, OPS-02, ISOL-07 parallel migrations |
| v0.6 | Advanced Isolation | ISOL-06 PostgreSQL RLS (demand-gated; v1.0 candidate) |

### Future / By Demand

User-requestable but unscheduled. See `.planning/PROJECT.md#future--by-demand` for the canonical list and `ROADMAP.md` (repo root) for the public-facing version.

## Progress

| Milestone | Phases | Plans | Status   | Shipped    |
| --------- | ------ | ----- | -------- | ---------- |
| v0.2      | 1–15   | 48/48 | Complete | 2026-04-20 |
| v0.3      | 16–22  | 0/7   | Planning | —          |
