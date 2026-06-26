# Requirements: Symfony Tenancy Bundle — v0.5 Operations & Scale

**Defined:** 2026-06-26
**Core Value:** When a tenant is resolved, every Symfony service automatically re-configures itself for that tenant — zero boilerplate, zero leaks, zero guessing.
**Milestone goal:** Make the bundle operable at scale — per-tenant maintenance control, health visibility, faster migrations, and production-ops docs.

Baseline: v0.4.1 (tag), green CI, Symfony 8.1 supported. Phases continue from **31**.
Research: `.planning/research/SUMMARY.md`. Net-zero new production deps.

## v0.5 Requirements

### Maintenance Mode  (epic OPS-01)

- [ ] **MAINT-01**: An operator can put a single tenant into maintenance via `tenancy:maintenance:enable <slug>`, leaving other tenants and the landlord unaffected.
- [ ] **MAINT-02**: An operator can take a tenant out of maintenance via `tenancy:maintenance:disable <slug>`.
- [ ] **MAINT-03**: A request to a tenant in maintenance returns HTTP 503 with a `Retry-After` header and `Cache-Control: no-store`.
- [ ] **MAINT-04**: Landlord, public, and health-check routes are never blocked by a tenant's maintenance mode (null-tenant + allow-list bypass).
- [ ] **MAINT-05**: Maintenance state is stored on the tenant entity (DB column via `TenantMaintenanceConfigTrait`), persists across requests/processes, and never leaks across tenants.
- [ ] **MAINT-06**: An operator can configure an IP / route / path allow-list that bypasses maintenance (so operators can reach a tenant that is under maintenance).
- [ ] **MAINT-07**: An application can override the 503 maintenance response with a custom Twig template.
- [ ] **MAINT-08**: The bundle dispatches `TenantMaintenanceEnabled` / `TenantMaintenanceDisabled` events on toggle, for application hooks.
- [ ] **MAINT-09**: An operator can list all tenants currently in maintenance via `tenancy:maintenance:status`.

### Health Checks  (epic OPS-02)

- [ ] **HEALTH-01**: A liveness endpoint (`/_tenancy/health/live`) reports process health WITHOUT iterating tenants — fast enough for per-second load-balancer / k8s liveness probes.
- [ ] **HEALTH-02**: A per-tenant readiness endpoint (`/_tenancy/health/ready/{slug}`) probes that tenant's connectivity + bootstrapper health and returns IETF `application/health+json` with HTTP 200 (pass/warn) or 503 (fail).
- [ ] **HEALTH-03**: Bootstrappers expose a read-only probe via a new sibling `HealthCheckBootstrapperInterface` (no BC break); `TenantHealthChecker` runs probes without calling `boot()` and clears `TenantContext` in a `finally` block.
- [ ] **HEALTH-04**: Health responses never expose secrets — DSNs/credentials are redacted (`HealthResponseSanitizer`); the HTTP endpoints are opt-in (default disabled).
- [ ] **HEALTH-05**: A `tenancy:health [--tenant=<slug>|--all]` command reports per-tenant health from the CLI.
- [ ] **HEALTH-06**: An aggregate fleet-health endpoint summarizes all tenants for dashboards (bounded/sampled — explicitly NOT a k8s probe target).
- [ ] **HEALTH-07**: When `liip/monitor-bundle` is installed, the bundle's tenant checks auto-register as `liip_monitor.check` services (`class_exists`-guarded); absent the bundle, the self-contained endpoints + command still work.

### Parallel Migrations  (epic ISOL-07)

- [ ] **ISOL-07**: `tenancy:migrate --parallel` runs per-tenant migrations concurrently via a bounded `symfony/process` worker pool; sequential remains the default (no flag = unchanged behavior).
- [ ] **ISOL-08**: Concurrency is bounded by `--concurrency=N` (default 4, hard cap 32) to avoid DB connection exhaustion.
- [ ] **ISOL-09**: Parallel output is atomic per-tenant (no interleaving), exit codes are aggregated (a null/killed subprocess counts as failure), continue-on-failure is preserved, and a final summary table is printed.
- [ ] **ISOL-10**: `tenancy:migrate --parallel --dry-run` reports what each tenant would migrate without applying.
- [ ] **ISOL-11**: Parallel mode is guarded on the `shared_db` driver (single physical DB — parallel would corrupt the migrations table); it refuses or falls back to sequential with a clear message.
- [ ] **ISOL-12**: `tenancy:migrate --format=json` emits machine-readable per-tenant migration results.

### Documentation  (epic DOC-21)

- [ ] **DOC-21**: New `docs/ops/` section — maintenance-mode, health-checks, and parallel-migrations pages (incl. k8s liveness/readiness probe YAML, the CDN 5xx-caching warning, and re-run-on-failure guidance), an UPGRADE 0.4→0.5 section (covering the `TenantInterface::isInMaintenance()` BC break + trait mitigation), mkdocs nav entries, and a `docs-lint.sh` guard for the new terms.

### Carry-forward / Hardening  (v0.4 debt folded into v0.5)

- [ ] **DEMO-02**: Reconcile the `examples/saas` `Dockerfile` ↔ `composer.lock` PHP-version drift (open since v0.3) so `bin/smoke.sh` is green on a single coherent PHP version.
- [ ] **GOV-02**: Decide and apply a Nyquist `VALIDATION.md` enforcement policy for v0.5 phases — either enforce per-phase coverage as a gate, or document the discovery-only stance explicitly.
- [ ] **QA-01**: Close the 2 `human_needed` UAT items (Phase 26 `tenancy:shared:resync` TTY confirm, Phase 28 PHPStan extension-installer auto-load) — convert each to a code-level testability seam or a documented manual-exercise protocol.

## Future Requirements (deferred)

Tracked, not in the v0.5 roadmap.

### Advanced Isolation (v0.6)
- **ISOL-06**: PostgreSQL Row-Level Security driver
- When-to-pick-which-driver matrix docs

### Operations (v0.6+ / by demand)
- Global (all-tenants) maintenance mode — promote a maintenance posture across the whole fleet at once
- Migration checkpoint / resume — resume a partially-applied parallel migration run

## Out of Scope

Explicitly excluded for v0.5, with reasoning.

| Feature | Reason |
|---------|--------|
| Global / site-wide maintenance mode | A web-server / load-balancer concern; this milestone is about *per-tenant* control. Deferred to v0.6 by demand. |
| File-based maintenance flag | Unsafe in multi-pod / async-runtime deployments (no shared state); the DB column is the authority. |
| Migration checkpoint/resume & auto-rollback | Doctrine Migrations is idempotent — re-running is the correct, supported recovery; cross-DB rollback isn't supported. Continue-on-failure + re-run is the standard. |
| Application-level auth on health endpoints | Breaks load-balancer / k8s probes; protect via network ACL / opt-in routes instead. |
| Third-party process-pool library (spatie/async, amphp, etc.) | `symfony/process` (already required) + a ~20-line bounded poll loop covers it; avoids an event-loop runtime dependency. |
| `liip/monitor-bundle` as a hard `require` | It is an optional integration, not core tenancy — stays `require-dev` + `suggest`, guarded by `class_exists`. |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| ISOL-07 | Phase 31 | Pending |
| ISOL-08 | Phase 31 | Pending |
| ISOL-09 | Phase 31 | Pending |
| ISOL-10 | Phase 31 | Pending |
| ISOL-11 | Phase 31 | Pending |
| ISOL-12 | Phase 31 | Pending |
| MAINT-01 | Phase 32 | Pending |
| MAINT-02 | Phase 32 | Pending |
| MAINT-03 | Phase 32 | Pending |
| MAINT-04 | Phase 32 | Pending |
| MAINT-05 | Phase 32 | Pending |
| MAINT-06 | Phase 32 | Pending |
| MAINT-07 | Phase 32 | Pending |
| MAINT-08 | Phase 32 | Pending |
| MAINT-09 | Phase 32 | Pending |
| HEALTH-01 | Phase 33 | Pending |
| HEALTH-02 | Phase 33 | Pending |
| HEALTH-03 | Phase 33 | Pending |
| HEALTH-04 | Phase 33 | Pending |
| HEALTH-05 | Phase 33 | Pending |
| HEALTH-06 | Phase 33 | Pending |
| HEALTH-07 | Phase 33 | Pending |
| DOC-21 | Phase 34 | Pending |
| DEMO-02 | Phase 34 | Pending |
| GOV-02 | Phase 34 | Pending |
| QA-01 | Phase 34 | Pending |

**Coverage:**
- v0.5 requirements: 26 total (MAINT 9, HEALTH 7, ISOL 6, DOC 1, DEMO 1, GOV 1, QA 1)
- Mapped to phases: 26
- Unmapped: 0 ✓

---
*Requirements defined: 2026-06-26 for v0.5 Operations & Scale*
*Last updated: 2026-06-26 — traceability table populated after roadmap creation*
