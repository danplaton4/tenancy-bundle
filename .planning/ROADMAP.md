# Roadmap: Symfony Tenancy Bundle

## Milestones

- ✅ **v0.2 — Architectural Fixes** — Phases 1–15 (shipped 2026-04-20, tag v0.2.1)
- ✅ **v0.3 — Adoption Surface** — Phases 17–23 (shipped 2026-05-29, tag v0.3.3)
- ✅ **v0.4 — Storage & Shared Entities** — Phases 24–30 (shipped 2026-06-19, tag v0.4.0)
- 🚧 **v0.5 — Operations & Scale** — Phases 31–34 (in progress)

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

### 🚧 v0.5 Operations & Scale (In Progress — Phases 31–34)

**Milestone Goal:** Make the bundle operable at scale — per-tenant maintenance control, health visibility, faster migrations, and production-ops docs.

- [x] **Phase 31: Parallel Migrations** - Bounded subprocess worker pool for `tenancy:migrate --parallel` (completed 2026-06-26)
- [x] **Phase 32: Maintenance Mode** - Per-tenant HTTP 503 toggle with allow-list bypass (completed 2026-07-01)
- [x] **Phase 33: Health Checks** - Liveness/readiness probes with optional LiipMonitorBundle integration (completed 2026-07-06)
- [ ] **Phase 34: Ops Docs & Carry-Forward** - Production ops docs, saas demo fix, Nyquist enforcement, UAT closures

## Phase Details

### Phase 31: Parallel Migrations

**Goal**: Operators can run per-tenant migrations concurrently via a bounded subprocess worker pool, dramatically reducing fleet-wide migration time while preserving all existing sequential guarantees
**Depends on**: Phase 30 (v0.4 baseline)
**Requirements**: ISOL-07, ISOL-08, ISOL-09, ISOL-10, ISOL-11, ISOL-12
**Success Criteria** (what must be TRUE):

  1. `tenancy:migrate --parallel` runs migrations for all tenants concurrently; `tenancy:migrate` (no flag) behaves identically to v0.4 with no observable change
  2. Concurrency is capped at `--concurrency=N` (default 4, hard cap 32); at most N subprocesses are active at any moment (verified by mock process factory)
  3. Output is atomic per-tenant (no interleaving across tenants); a killed or null-exit subprocess is counted as failure, not success; the final summary table lists per-tenant pass/fail
  4. `tenancy:migrate --parallel` on a `shared_db` driver tenant set refuses to run (or falls back to sequential) with a clear message explaining why
  5. `tenancy:migrate --format=json` emits a machine-readable JSON object per tenant with migration status, and `--dry-run` reports what would migrate without applying

**Plans**: 2 plans
Plans:
**Wave 1**

- [x] 31-01-PLAN.md — ParallelMigrationRunner subprocess worker pool + unit test (ISOL-07/08/09/12)

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 31-02-PLAN.md — tenancy:migrate --parallel/--concurrency/--dry-run/--format=json command surface + DI wiring + tests (ISOL-07..12)

### Phase 32: Maintenance Mode

**Goal**: Operators can put individual tenants into maintenance mode via CLI, returning HTTP 503 with `Retry-After` and `Cache-Control: no-store` to those tenant's requests while other tenants and the landlord continue serving normally
**Depends on**: Phase 31
**Requirements**: MAINT-01, MAINT-02, MAINT-03, MAINT-04, MAINT-05, MAINT-06, MAINT-07, MAINT-08, MAINT-09
**Success Criteria** (what must be TRUE):

  1. `tenancy:maintenance:enable <slug>` puts exactly one tenant into maintenance; `tenancy:maintenance:disable <slug>` takes it out; `tenancy:maintenance:status` lists all tenants currently in maintenance
  2. A request to a tenant in maintenance returns HTTP 503 with `Retry-After` and `Cache-Control: no-store`; requests to other tenants, the landlord, and health-check routes are unaffected (null-tenant branch bypasses the listener)
  3. The maintenance listener fires at `kernel.request` priority 16 (after `TenantContextOrchestrator` at priority 20); the `MaintenanceModeContractPass` fails compilation if the listener is wired at priority >= 20
  4. An IP, route, or path on the configured allow-list bypasses maintenance and receives a normal response even when the tenant is in maintenance
  5. The application can supply a custom Twig template for the 503 page; `TenantMaintenanceEnabled` / `TenantMaintenanceDisabled` events are dispatched on toggle so application hooks can react

**Plans**: 4 plans
Plans:
**Wave 1**

- [x] 32-01-PLAN.md — Maintenance state foundation: TenantMaintenanceConfigTrait + TenantInterface::isInMaintenance() + AbstractTenant column, toggle events, MaintenanceModeContractPass (MAINT-05/08, Success Criterion 3)

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 32-02-PLAN.md — TenantMaintenanceModeListener @ priority 16: 503 + Retry-After + Cache-Control: no-store, null-tenant/allow-list bypass, content-negotiated body, Twig-with-HTML-fallback (MAINT-03/04/06/07)
- [x] 32-03-PLAN.md — tenancy:maintenance:enable/disable/status commands: idempotent landlord-side writes, PSR cache invalidation, event-on-transition, --format=json (MAINT-01/02/08/09)

**Wave 3** *(blocked on Wave 2 completion)*

- [x] 32-04-PLAN.md — DI wiring: maintenance config node + parameters, conditional listener registration @16, command registration + landlord-EM rewire, unconditional contract-pass, priority-16 + no-Doctrine integration tests (MAINT-01/02/03/04/06/07/09)

**UI hint**: yes

### Phase 33: Health Checks

**Goal**: Operators can probe per-tenant connectivity and bootstrapper health via HTTP endpoints and a CLI command, with optional LiipMonitorBundle auto-registration, and health responses never expose DSNs or credentials
**Depends on**: Phase 32
**Requirements**: HEALTH-01, HEALTH-02, HEALTH-03, HEALTH-04, HEALTH-05, HEALTH-06, HEALTH-07
**Success Criteria** (what must be TRUE):

  1. `GET /_tenancy/health/live` returns HTTP 200 `{"status":"ok"}` immediately without iterating tenants; it is fast enough for per-second load-balancer / k8s liveness probes
  2. `GET /_tenancy/health/ready/{slug}` returns IETF `application/health+json` with HTTP 200 (pass/warn) or HTTP 503 (fail); `TenantHealthChecker` sets `TenantContext` manually, runs probes, and clears it in a `finally` block — `boot()` is never called; `TenantContext::hasTenant()` is `false` after the probe completes
  3. A bootstrapper implementing `HealthCheckBootstrapperInterface` exposes a read-only `check()` probe that is called by `TenantHealthChecker` without triggering `boot()` / `clear()` side effects
  4. Health responses never contain raw DSN strings or credentials; a DSN injected into any bootstrapper exception message is redacted by `HealthResponseSanitizer` before it reaches the response body
  5. `tenancy:health [--tenant=<slug>|--all]` reports per-tenant health from the CLI; an aggregate fleet endpoint summarizes all tenants for dashboard use (explicitly not a k8s probe target)
  6. When `liip/monitor-bundle` is installed, bundle health checks auto-register as `liip_monitor.check` services (guarded by `class_exists`); absent the bundle, the self-contained endpoints and command work unchanged

**Plans**: 5 plans (4 waves)
Plans:
**Wave 1**

- [x] 33-01-PLAN.md — Contract layer: HealthStatus enum, HealthCheckBootstrapperInterface (sibling), BootstrapperHealthResult + TenantHealthReport VOs, HealthResponseSanitizer (reuses DsnSanitizer regex) + unit tests (HEALTH-03/04)

**Wave 2** *(blocked on Wave 1)*

- [x] 33-02-PLAN.md — Probe engine: TenantHealthChecker (set→probe→clear-in-finally, never boot()), additive BootstrapperChain::healthCheck(), DatabaseSwitchBootstrapper + SharedDriver check() probes, checker/sanitizer DI wiring, two-tenant SQLite probe-safety integration test (HEALTH-02/03)

**Wave 3** *(blocked on Wave 2)*

- [x] 33-03-PLAN.md — HTTP surface: TenantHealthController (live/ready/fleet) + two importable route files (health.php, health_fleet.php), IETF application/health+json, 200/503/404 mapping, bounded fleet pagination, sanitized bodies + controller unit tests (HEALTH-01/02/06)
- [x] 33-04-PLAN.md — CLI: tenancy:health [--tenant=<slug>|--all] [--format=json], per-tenant streaming, exit-code aggregation, sanitized output, single JSON aggregate + command unit tests (HEALTH-05)

**Wave 4** *(blocked on Wave 3)*

- [x] 33-05-PLAN.md — Integration/wiring: health config node + tenancy.health.* params (no HTTP enabled flag), public controller + console.command wiring, HealthCheckIntegrationPass (class_exists-guarded liip auto-register) + TenantConnectivityCheck adapter, liip require-dev/suggest, no-liip + end-to-end HTTP integration tests (HEALTH-01/04/05/06/07)

### Phase 34: Ops Docs & Carry-Forward

**Goal**: The `docs/ops/` section documents maintenance mode, health checks, and parallel migrations with production-ready Kubernetes YAML and runbook patterns; the `examples/saas` demo runs on a single coherent PHP version; and the two open v0.4 UAT items are closed
**Depends on**: Phase 33
**Requirements**: DOC-21, DEMO-02, GOV-02, QA-01
**Success Criteria** (what must be TRUE):

  1. `docs/ops/maintenance-mode.md`, `docs/ops/health-checks.md`, and `docs/ops/parallel-migrations.md` exist, are in `mkdocs.yml` nav, and `docs-lint.sh` guards their new terms; the health-checks page includes Kubernetes liveness/readiness probe YAML with correct `periodSeconds` + `failureThreshold`, and the CDN 5xx-caching warning
  2. `UPGRADE.md` contains a `0.4 → 0.5` section covering the `TenantInterface::isInMaintenance()` BC break and the `TenantMaintenanceConfigTrait` migration path
  3. `bin/smoke.sh` passes on a single coherent PHP version after the `examples/saas` `Dockerfile` ↔ `composer.lock` PHP-version drift is resolved
  4. A written Nyquist `VALIDATION.md` enforcement policy for v0.5 phases is in place — either enforcing per-phase coverage as a gate or documenting the discovery-only stance explicitly
  5. Both v0.4 `human_needed` UAT items (Phase 26 `tenancy:shared:resync` TTY confirm, Phase 28 PHPStan extension-installer auto-load) are closed — each converted to a code-level testability seam or a documented manual-exercise protocol

**Plans**: 5 plans (2 waves)
Plans:
**Wave 1**

- [ ] 34-01-PLAN.md — Three docs/ops/*.md pages (parallel-migrations, maintenance-mode, health-checks incl. k8s probe YAML + CDN warning) + Operations mkdocs nav group (DOC-21)
- [ ] 34-03-PLAN.md — DEMO-02 examples/saas config.platform.php=8.2.99 pin + composer.lock regen (no >=8.4 deps); smoke.sh green on 8.2 via CI checkpoint (DEMO-02)
- [ ] 34-04-PLAN.md — GOV-02 Nyquist advisory-only policy note in contributor-guide + Phase 31 VALIDATION.md backfill (GOV-02)
- [ ] 34-05-PLAN.md — QA-01 regression tests: resync confirm-YES branch + PHPStan extension-installer auto-load contract (QA-01)

**Wave 2** *(blocked on 34-01)*

- [ ] 34-02-PLAN.md — DOC-21 docs-lint.sh ops-terms negative guard + UPGRADE.md 0.4→0.5 BC-break section (DOC-21)

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
| 31. Parallel Migrations | v0.5 | 2/2 | Complete    | 2026-06-26 |
| 32. Maintenance Mode | v0.5 | 4/4 | Complete    | 2026-07-01 |
| 33. Health Checks | v0.5 | 5/5 | Complete    | 2026-07-06 |
| 34. Ops Docs & Carry-Forward | v0.5 | 0/5 | Planned | - |
