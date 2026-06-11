# Roadmap: Symfony Tenancy Bundle

## Milestones

- ✅ **v0.2 Architectural Fixes** — Phases 1–15 (shipped 2026-04-20)
- ✅ **v0.3 Adoption Surface** — Phases 17–23 (shipped 2026-05-29, tag v0.3.3)
- 🚧 **v0.4 Storage & Shared Entities** — Phases 24–29 (in progress; defined 2026-05-29) — Filesystem bootstrapper, shared entities sync/async, PHPStan extension
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

### 📋 v0.4 Storage & Shared Entities — Phases 24–29 (6 active, in progress)

Goal: make a real SaaS work end-to-end. v0.3 closed the install funnel; v0.4 closes the storage + data-sharing gaps. 6 active phases, 6 active requirements. See `.planning/REQUIREMENTS.md` for full acceptance criteria.

- [x] **Phase 24: Filesystem Bootstrapper** — Flysystem integration with prefix + per-tenant-adapter strategies, `FilesystemContractPass` compile-time guard, in-memory test integration (BOOT-03) (completed 2026-06-02)
  - **Goal:** When a tenant is resolved, every Flysystem service tagged `tenancy.scoped` automatically points at the active tenant's storage — either as a sub-prefix on a shared adapter (prefix mode, default) or as a per-tenant adapter instance (per-tenant-adapter mode, opt-in).
  - **Plans:** 10 plans
  - Plans:
    - [x] 24-00-PLAN.md — Wave 0 scaffolding: composer deps (league/flysystem-bundle + league/flysystem-memory require-dev/suggest), 11 stub test files, FilesystemTestKernel + MakeFilesystemServicesPublicPass, StubTenantFilesystemExtension trait
    - [x] 24-01-PLAN.md — Wave 1: TenantFilesystemConfigTrait + AbstractTenant.filesystemConfig nullable JSON column (DEC-FILE-CONFIG, zero BC break)
    - [x] 24-02-PLAN.md — Wave 1: MissingFilesystemConfigException + UnsupportedAdapterDsnSchemeException, both extends \\LogicException (Messenger no-retry)
    - [x] 24-03-PLAN.md — Wave 1: LruFilesystemCache (bounded, default 32) + TenantContextClearedListener (belt-and-suspenders flush)
    - [x] 24-04-PLAN.md — Wave 1: AdapterDsnParser with 3 schemes (local://, memory://, s3://) + addScheme() extension point + credential-leak guard
    - [x] 24-05-PLAN.md — Wave 2: FilesystemPrefixingDecorator (prefix mode, 21-method FilesystemOperator surface, live-read invariant, Q1 strip-on-listContents)
    - [x] 24-06-PLAN.md — Wave 2: TenantAwareFilesystemDecorator (per_tenant_adapter mode, cache+parser integration, MissingFilesystemConfigException raise)
    - [x] 24-07-PLAN.md — Wave 3: FilesystemBootstrapper (priority -30) + FilesystemContractPass (3 compile-time guards + tag→decorator rewrite) + TenancyBundle config node + DI wiring
    - [x] 24-08-PLAN.md — Wave 4: 5-scenario integration suite + autowiring-regression test (Pitfall 6) + 100-tenant LRU long-worker simulation
    - [x] 24-09-PLAN.md — Wave 5: examples/saas/ upload page (live-stack verification) + docs/user-guide/filesystem-bootstrapper.md seed + UPGRADE 0.3 → 0.4 section
- [ ] **Phase 25: Shared Entities (Sync mode)** — `#[Shared]` attribute + `SharedEntitySyncSubscriber` on landlord postFlush + tenant-side write protection + `SharedEntityWriteInTenantContextException` (SHARE-01)
  - **Goal:** When a `#[Shared]`-attributed entity is written on the landlord EM, a read-only denormalized copy is synced (best-effort, synchronous) into every tenant's EM via Doctrine `onFlush`-buffer/`postFlush`-fanout; tenant-side writes of `#[Shared]` entities are blocked, and `#[Shared]` + `#[TenantAware]` co-presence fails loud at container build.
  - **Plans:** 5 plans across 4 waves
  - Plans:
    - [x] 25-00-PLAN.md — Wave 0: test infrastructure (landlord + 2-tenant SQLite kernel, #[Shared] test entities + association entity, 2-tenant stub provider, public-services pass, unit + integration test scaffolds for SHARE-01-a..m)
    - [x] 25-01-PLAN.md — Wave 2: `#[Shared]` bare marker attribute (D-06) + `SharedEntityWriteInTenantContextException extends \LogicException` (D-02 foundation)
    - [x] 25-02-PLAN.md — Wave 3: `SharedEntityMutualExclusionPass` compile-time guard via `tenancy.shared_entity` tag (D-04 / DEC-SHARE-03)
    - [ ] 25-03-PLAN.md — Wave 3: `SharedEntitySyncSubscriber` (onFlush-buffer → postFlush best-effort fan-out, no merge(), scalar-only copy, shared_db no-op, re-entrancy flag, actionable logging — D-01/D-03/D-05/D-07) + `SharedEntityWriteProtectionListener` (tenant onFlush read-only guard + re-entrancy bypass — D-02)
    - [ ] 25-04-PLAN.md — Wave 4: connection-scoped DI wiring (landlord subscriber + tenant guard in the database_per_tenant block, A2) + compiler-pass registration in build() + shared_db no-op docs note + full SHARE-01 suite green-up
- [ ] **Phase 26: `tenancy:shared:resync` command** — bulk-initial-sync console command with continue-on-failure + dry-run + per-tenant pass/fail summary (SHARE-02)
- [ ] **Phase 27: Async Shared Entities** — opt-in `tenancy.shared.async: true` mode with Messenger fan-out via `SharedEntityChangedMessage` + AsyncCanaryTest pattern (SHARE-03)
- [ ] **Phase 28: PHPStan Extension** — rules for `#[TenantAware]` + `#[Shared]` correctness, `phpstan/extension-installer` auto-load (DX-03)
- [ ] **Phase 29: Docs Refresh** — new pages for filesystem-bootstrapper, shared-entities, phpstan-extension; UPGRADE 0.3 → 0.4 (if BC breaks land); docs-lint shared-entity ambiguity check (DOC-20)

**Tentative architectural defaults** (subject to flip in `/gsd:discuss-phase`): DEC-FILE-01 prefix-mode default, DEC-FILE-02 optional trait for getFilesystemConfig() (no BC break), DEC-SHARE-01 sync default, DEC-SHARE-02 one-level cascade, DEC-SHARE-03 #[Shared]+#[TenantAware] mutually exclusive at compile time, DEC-PHPSTAN-01 phpstan/extension-installer distribution.

**Carry-forward from v0.3 retrospective:** refresh `examples/saas/composer.lock` PHP version drift early; closure-phase CONTEXT.md should `git log --since=<audit-date>` over canonical_refs (lesson from Phase 23 stale-audit on CR-01 + IN-05); reconsider Phase 16 GOV-01 only if v0.4 surfaces a concrete recurrence.

**Explicit non-goals (carried from v0.3):** Symfony Flex recipe (revisit when install volume justifies cost), v1.0 tag (deferred until external adoption signals validation).

### 📋 Later Milestones

| Milestone | Theme | Key items |
|-----------|-------|-----------|
| v0.5 | Operations & Scale | OPS-01, OPS-02, ISOL-07 parallel migrations |
| v0.6 | Advanced Isolation | ISOL-06 PostgreSQL RLS (demand-gated; v1.0 candidate) |

### Future / By Demand

User-requestable but unscheduled. See `.planning/PROJECT.md#future--by-demand` for the canonical list and `ROADMAP.md` (repo root) for the public-facing version.

## Progress

| Milestone | Phases | Plans   | Status      | Shipped    | Tag     |
| --------- | ------ | ------- | ----------- | ---------- | ------- |
| v0.2      | 1–15   | 48/48   | Complete    | 2026-04-20 | v0.2.0  |
| v0.3      | 17–23  | 53/53   | Complete    | 2026-05-29 | v0.3.3  |
| v0.4      | 24–29  | 0/15+   | In Progress | —          | —       |

*v0.4: defined 2026-05-29 after v0.3.3 ship. Phase 24 planned 2026-05-30 (10 plans across 6 waves). Phase 25 planned 2026-06-11 (5 plans across 4 waves). Phases 26–29 plan counts derived once `/gsd:plan-phase` runs for each. Next: `/gsd:execute-phase 25` to start SHARE-01 implementation.*
