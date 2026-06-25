# Project Research Summary

**Project:** `danplaton4/tenancy-bundle` — v0.5 Operations & Scale
**Domain:** Symfony reusable bundle — production-operations additions to an event-driven multi-tenant kernel
**Researched:** 2026-06-25
**Confidence:** HIGH (all four research dimensions grounded in live source reads of v0.4.1 codebase + Packagist-verified dependency decisions)

---

## Executive Summary

v0.5 adds three self-contained operational features to a v0.4.1 baseline that is already green on CI and published on Packagist: per-tenant maintenance mode (OPS-01), health check infrastructure with optional LiipMonitorBundle integration (OPS-02), and parallel `tenancy:migrate` via a bounded subprocess worker pool (ISOL-07). All three features are implementable with **zero new production dependencies** — every required component (`symfony/cache`, `symfony/process`, `symfony/http-foundation`) is already in `require`. The only additive entry to `composer.json` is `liip/monitor-bundle ^2.25` in `require-dev` + `suggest` as an opt-in integration point for OPS-02.

The recommended build order, reconciled from the four research dimensions, is: **Phase 31 (ISOL-07)** first because it touches zero public interfaces and uses the established `symfony/process` subprocess pattern from `TenantRunCommand`; **Phase 32 (OPS-01)** second because it introduces the one BC-sensitive change (`TenantInterface::isInMaintenance()` mitigated by `TenantMaintenanceConfigTrait`) and establishes the allow-list config that Phase 33 must reference; **Phase 33 (OPS-02)** third because it depends on Phase 32's health-route allow-list, introduces the most novel surface area (`HealthCheckBootstrapperInterface`, `TenantHealthChecker`, two HTTP routes), and benefits from both prior phases being verified; **Phase 34 (DOC-21)** last as a documentation-only close-out. Features suggested OPS-01 → ISOL-07 → OPS-02; Architecture suggested ISOL-07 → OPS-01 → OPS-02. The reconciled order is **ISOL-07 → OPS-01 → OPS-02**, matching Architecture's recommendation, because ISOL-07 has strictly no interface dependencies while OPS-01's allow-list config is a genuine input to OPS-02.

The three dominant security risks across all features share the same root cause: operating on shared state before it is properly bounded to the current tenant. Maintenance mode must fire after the resolver runs (priority < 20), never before. Health probes must set `TenantContext` manually and clear it in a `finally` block — they must never call `BootstrapperChain::boot()`. Parallel migrations must spawn out-of-process subprocesses (each with their own `TenantContext` and DBAL connection), never parallelize in-process. Each of these rules has a corresponding quality-gate test that must pass before the phase closes.

---

## Key Findings

### Recommended Stack

All v0.5 features are built on the existing v0.4.1 stack. `lexik/maintenance-bundle` is confirmed abandoned (v2.1.5, February 2018, Symfony ^4.0 only — Packagist verified). Maintained forks (`toshy/maintenance-bundle`, `prolix/maintenance-bundle`) add a production dependency for a feature that takes ~3 classes to build natively, and neither supports per-tenant semantics. The decision is to build maintenance mode entirely in-bundle using `symfony/cache` (already required) as the per-request memoization layer over a DB column flag.

For OPS-02, `liip/monitor-bundle ^2.25` (9M Packagist installs, active as of 2026-03-23, Symfony ^6.4||^7.0||^8.0) is the integration target. It is added to `require-dev` + `suggest` only — never `require`. A `HealthCheckIntegrationPass` registers bundle-provided checks as `liip_monitor.check` services only when `class_exists(\LiipMonitorBundle\LiipMonitorBundle::class)`. A self-contained `TenantHealthController` at `/_tenancy/health` (opt-in, default disabled) provides the zero-dependency fallback path. For ISOL-07, `symfony/process` is already in `require` and is sufficient — no third-party process-pool library is warranted.

**Core technologies (additive to v0.4.1):**

- `symfony/cache` (`CacheInterface`): per-request memoization of the maintenance flag — already required; distributed-safe via Redis/Memcached backends; testable with `ArrayAdapter`
- `symfony/process` (`Process::start()` + poll loop): bounded subprocess worker pool for ISOL-07 — already in `require` since Phase 07
- `liip/monitor-bundle ^2.25`: optional OPS-02 integration — `require-dev` + `suggest` only; 9M installs, verified Symfony 7.4/8.x compatible
- `laminas/laminas-diagnostics ^1.27`: transitive dependency of liip; provides `CheckInterface` for `TenantConnectivityCheck` / `BootstrapperHealthCheck`

**Authoritative what-not-to-add:**

| Reject | Reason |
|--------|--------|
| `lexik/maintenance-bundle` (any version) | Abandoned 2018; Symfony ^4.0 only |
| `toshy/maintenance-bundle` / `prolix/maintenance-bundle` | No per-tenant semantics; adds dep for 3 classes |
| `liip/monitor-bundle` in `require` (hard) | Optional integration, not core tenancy |
| `spatie/async`, `graze/parallel-process`, `amphp/parallel` | Wrong abstraction or event-loop runtime; `Process::start()` poll is 20 lines |
| `symfony/lock` for migration concurrency | Overkill — pool is local to one invocation; "run one `tenancy:migrate` at a time" covers it |

---

### Expected Features

**Must have (table stakes):**

- OPS-01a/b/c — Per-tenant maintenance toggle (DB column on `AbstractTenant`); HTTP 503 + `Retry-After` + `Cache-Control: no-store`; CLI commands `tenancy:maintenance:enable <slug>` and `tenancy:maintenance:disable <slug>`
- OPS-01d — Landlord and health-check routes bypass maintenance unconditionally; failure to exempt health routes causes load-balancer restart loops
- OPS-02a/b — Per-tenant health endpoint returning IETF `application/health+json`; HTTP 200/503 based on aggregate (not 200-with-fail-body, which is invisible to infrastructure tooling)
- OPS-02c — `HealthCheckBootstrapperInterface` as a sibling (BC-safe) interface; bootstrappers opt in to expose read-only probes
- ISOL-07a/b/c — `--parallel` flag on `tenancy:migrate` with bounded concurrency (default 4); per-tenant atomic status output; continue-on-failure semantics preserved

**Should have (competitive differentiators, v0.5 scope):**

- OPS-01e/f/g/h — IP allowlist bypass; custom Twig 503 template override; `TenantMaintenanceEnabled/Disabled` events; `tenancy:maintenance:status` fleet listing
- OPS-02d/e/f — Aggregate fleet health endpoint (dashboards only, not k8s probes); liveness (`/health/live`) vs. readiness (`/health/ready/{slug}`) distinct routes; profiler WDT tab integration
- ISOL-07d/e/f — `--dry-run` flag; `--tenant=<slug>` filter preserved; `--format=json` machine-readable output

**Defer (v2+):**

- Global (all-tenants) maintenance mode — use web server layer; not a tenancy-bundle concern
- File-based maintenance flag — unsafe in multi-pod deployments
- Migration checkpoint/resume — Doctrine Migrations idempotency makes re-run the correct recovery; document this explicitly
- Health check application-level authentication — use network-level ACL; app auth breaks load-balancer probes
- Automatic rollback on parallel migration failure — Doctrine Migrations does not support cross-database rollback; continue-on-failure + re-run is the industry standard

---

### Architecture Approach

All three features are strictly additive to the v0.4.1 component graph. `TenantContextOrchestrator` (priority 20) and `BootstrapperChain::boot()`/`clear()` are unchanged. OPS-01 inserts a new `kernel.request` listener at priority 16. OPS-02 introduces `TenantHealthChecker`, which sets `TenantContext` manually and calls a new additive `BootstrapperChain::healthCheck()` — bypassing the full boot cycle. ISOL-07 extracts `ParallelMigrationRunner` from `TenantMigrateCommand` and delegates to out-of-process subprocesses that each run the existing single-tenant path.

**Major new/modified components:**

| Component | File | Role | NEW / MODIFIED |
|-----------|------|------|----------------|
| `TenantMaintenanceModeListener` | `src/EventListener/` | `kernel.request` prio=16; 503 after tenant resolved | NEW |
| `TenantMaintenanceConfigTrait` | `src/Maintenance/` | BC-safe trait for `AbstractTenant` maintenance column | NEW |
| `MaintenanceModePass` | `src/DependencyInjection/Compiler/` | Validates listener priority; wires driver | NEW |
| `HealthCheckBootstrapperInterface` | `src/Health/` | Sibling to `TenantBootstrapperInterface`; `check(TenantInterface): BootstrapperHealthResult` | NEW |
| `TenantHealthChecker` | `src/Health/` | Core: setTenant → healthCheck() → clear() — no boot() called | NEW |
| `TenantHealthCommand` | `src/Command/` | `tenancy:health` CLI surface | NEW |
| `TenantHealthController` | `src/Controller/` | `/_tenancy/health` HTTP surface; opt-in, default disabled | NEW |
| `HealthCheckIntegrationPass` | `src/DependencyInjection/Compiler/` | Registers liip_monitor.check services when LiipMonitorBundle present | NEW |
| `ParallelMigrationRunner` | `src/Command/Migration/` | Bounded subprocess worker pool; sliding-window with poll loop | NEW |
| `BootstrapperChain` | `src/Bootstrapper/` | Add `healthCheck()` method (additive, no BC break) | MODIFIED |
| `TenantMigrateCommand` | `src/Command/` | Add `--parallel` + `--concurrency`; shared_db guard preserved | MODIFIED |
| `AbstractTenant` | `src/Entity/` | Add `$isInMaintenance` bool column (or via trait) | MODIFIED |
| `TenantInterface` | `src/TenantInterface.php` | Add `isInMaintenance(): bool` — BC break mitigated by `TenantMaintenanceConfigTrait` | MODIFIED |

**Critical invariants preserved:**

- `TenantContext` remains zero-dependency
- `TenantBootstrapperInterface` is unchanged (`boot()` / `clear()` only)
- Optional-dependency posture: every new Doctrine/Liip integration guarded by `class_exists` / `interface_exists`
- Compiler passes handle all wiring — no user DI config required
- No-doctrine CI lane must remain green after every OPS phase

---

### Critical Pitfalls

The full pitfall catalogue (22 entries) is in `.planning/research/PITFALLS.md`. The five most phase-critical:

1. **Maintenance listener priority >= 20 (Pitfalls 1, 20)** — If the listener fires before `TenantContextOrchestrator` (prio=20), `TenantContext` is empty and maintenance mode silently does nothing. Register at priority 16. Add a `MaintenanceModeContractPass` that fails compile if any tenancy listener is registered at prio >= 20. Quality gate: assert orchestrator runs before maintenance listener.

2. **Health probes calling `BootstrapperChain::boot()` (Pitfall 8)** — `boot()` has side effects (DB switch, EM clear, cache namespace change) and can leak `TenantContext` into the next request in async runtimes (FrankenPHP, Swoole). `TenantHealthChecker` must call `setTenant()` + `healthCheck()` + `clear()` in a `try/finally`, never `boot()`. Quality gate: `TenantContext::hasTenant() === false` after health probe.

3. **Parallel migration without bounded concurrency (Pitfall 13)** — Each subprocess opens a DBAL connection. Unbounded spawning exhausts `max_connections` (MySQL default: 151) at ~20 tenants. Default `--concurrency=4` required. Hard cap at 32. Use sliding-window pool pattern (`start()` + `isRunning()` poll), not batch-and-wait. Quality gate: mock process factory asserting at-most-N concurrent.

4. **Parallel migration on shared_db driver (Pitfall 16)** — Multiple subprocesses would migrate the same physical DB simultaneously, corrupting the migrations table. The `TenantMigrateCommand` shared_db guard must be in a shared base or enforced by a compiler pass, not silently omitted from the parallel path. Quality gate: `driver: shared_db` → parallel migrate command not registered in container.

5. **Unauthenticated health endpoint exposing DSNs (Pitfall 10)** — Exception messages often contain `mysql://user:pass@host`. A `HealthResponseSanitizer` must strip DSN-shaped strings from any value entering the response body. Liveness endpoint returns only `{"status":"ok|degraded"}`. Quality gate: DSN-injection test (DSN-in → DSN-out-redacted).

**Additional non-negotiable constraints:**
- Landlord/health routes must always bypass maintenance (Pitfall 3) — null-branch on `!$tenantContext->hasTenant()`
- `TenantInterface` BC break mitigated by `TenantMaintenanceConfigTrait` with `return false` default (Pitfall 22)
- Subprocess output accumulated via streaming callback, not `getOutput()` post-exit — prevents 64KB pipe deadlock (Pitfall 17)
- Null exit code from killed/timed-out subprocess treated as failure, never success (Pitfall 15)

---

## Implications for Roadmap

### Phase 31: ISOL-07 — Parallel `tenancy:migrate`

**Rationale:** Zero public interface changes, zero schema changes, zero BC breaks. `symfony/process` already in `require`. Subprocess model reuses existing `tenancy:migrate --tenant=<slug>` single-tenant path — no new migration logic. Easiest to verify against existing SQLite integration test fixtures. Maximum risk-free early win before higher-risk phases land.

**Delivers:** `--parallel` / `--concurrency=N` flags; `ParallelMigrationRunner` (sliding-window pool); atomic per-tenant output; exit-code aggregation (null = failure); SIGTERM forwarding; shared_db guard.

**Addresses:** ISOL-07a, ISOL-07b, ISOL-07c, ISOL-07d (dry-run), ISOL-07e (--tenant filter), ISOL-07f (--format=json)

**Avoids:** Pitfalls 13 (unbounded concurrency), 14 (interleaved output), 15 (lost exit code), 16 (shared_db double-migration), 17 (deadlock), 18 (orphaned processes), 19 (no actionable failure report)

**Research flag:** None — fully specified. No deeper research needed before planning.

---

### Phase 32: OPS-01 — Tenant Maintenance Mode

**Rationale:** Introduces the one BC-sensitive change (`TenantInterface::isInMaintenance()`) and establishes the allow-list configuration block (`tenancy.maintenance.allow_ips`, `allow_routes`, `allow_paths`) that Phase 33 must reference to exempt health-check routes. Independently shippable and directly valuable. Lower risk than OPS-02 (no new HTTP routes, no external integration).

**Delivers:** `TenantMaintenanceModeListener` at prio=16; `TenantMaintenanceConfigTrait` on `AbstractTenant`; `isInMaintenance(): bool` on `TenantInterface` (mitigated by trait); `tenancy:maintenance:enable/disable/status` commands; `TenantMaintenanceEnabled/Disabled` events; IP allowlist bypass; configurable Twig 503 template override; `Retry-After` + `Cache-Control: no-store`; `MaintenanceModeContractPass`.

**Addresses:** OPS-01a through OPS-01h

**Avoids:** Pitfalls 1, 2, 3, 4, 5, 6, 7, 20, 21, 22

**Research flag:** Storage decision resolved in this summary — **DB column** (`AbstractTenant::$isInMaintenance` bool) is authoritative; cache is for per-request memoization only (5s max TTL for "in maintenance"). STACK.md explored cache-as-primary-store; ARCHITECTURE.md and PITFALLS.md both recommend DB column. No further research needed.

---

### Phase 33: OPS-02 — Health Checks / MonitorBundle Integration

**Rationale:** Depends on Phase 32's allow-list config (health routes must be in the maintenance bypass list). Most novel phase: new public sibling interface, new core service, two distinct HTTP routes, optional external bundle integration. Benefits from Phases 31 and 32 being stabilized.

**Delivers:** `HealthCheckBootstrapperInterface` + `BootstrapperHealthResult` + `TenantHealthReport`; `TenantHealthChecker` (set→probe→clear, no `boot()`); `BootstrapperChain::healthCheck()` (additive); `/health/live` (liveness, no tenant iteration) + `/health/ready/{slug}` (readiness, sampled probes); `tenancy:health` command; `HealthCheckIntegrationPass` (liip guard); `HealthResponseSanitizer`; `DatabaseSwitchBootstrapper` + `SharedDriver` implementing `HealthCheckBootstrapperInterface`.

**Addresses:** OPS-02a through OPS-02f

**Avoids:** Pitfalls 8, 9, 10, 11, 12, 21

**Research flag:** Two MEDIUM-confidence items need pre-plan validation:
- Verify `/_tenancy/health` route prefix does not conflict with any existing route in v0.4.1 before finalizing path.
- Validate `DatabaseSwitchBootstrapper::check()` probe safety via an integration test: `close()` + lightweight connect + `SELECT 1` under manual `TenantContext::setTenant()` must not mutate global service state.

---

### Phase 34: DOC-21 — Ops Documentation + v0.4 Carry-Forward

**Rationale:** Documents what shipped in Phases 31–33. Also closes v0.4 carry-forward: `examples/saas` PHP-version drift fix, Nyquist `VALIDATION.md` enforcement decision, and the 2 `human_needed` UAT items.

**Delivers:** `docs/ops/maintenance-mode.md` (incl. CDN 5xx caching warning), `docs/ops/health-checks.md` (incl. Kubernetes probe YAML with correct liveness/readiness `periodSeconds` + `failureThreshold`), `docs/ops/parallel-migrations.md` (incl. re-run-on-failure instructions); UPGRADE 0.4→0.5; `docs-lint.sh` extension; `examples/saas` Dockerfile fix; Nyquist enforcement decision; UAT item closure.

**Research flag:** None — documentation-only. No deeper research needed.

---

### Phase Ordering Rationale

**Reconciled order: ISOL-07 (31) → OPS-01 (32) → OPS-02 (33) → DOC-21 (34)**

- FEATURES.md suggested OPS-01 → ISOL-07 → OPS-02 (OPS-01 smallest, validates event dispatch).
- ARCHITECTURE.md suggested ISOL-07 → OPS-01 → OPS-02 (ISOL-07 zero interface impact; OPS-01 establishes allow-list config OPS-02 depends on).
- The architecture ordering is recommended because: (1) ISOL-07 has strictly no dependencies on any v0.5 work and is the highest-certainty phase; (2) OPS-01's allow-list config is a concrete runtime dependency of OPS-02's health route registration — a real sequencing constraint, not a preference; (3) keeping the BC-sensitive `TenantInterface` change in an isolated phase makes it easier to audit.
- The roadmapper should treat this as a recommendation — the phases are sufficiently independent that OPS-01 before ISOL-07 is viable if operator urgency dictates it.

---

### Research Flags

**Phases needing deeper research during planning:**

- **Phase 33 (OPS-02):** Two MEDIUM-confidence items before coding begins — route prefix conflict check and `DatabaseSwitchBootstrapper::check()` probe safety validation. Neither blocks planning, but both need resolution before execution.

**Phases with standard patterns (skip research-phase):**

- **Phase 31 (ISOL-07):** Fully specified. Established pattern. Plan immediately.
- **Phase 32 (OPS-01):** Fully specified. Storage decision resolved. `TenantMaintenanceConfigTrait` mirrors established trait pattern. Plan immediately.
- **Phase 34 (DOC-21):** Documentation-only. Plan after Phases 31–33 verified.

---

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH | Packagist-verified (2026-06-25). `lexik` abandonment confirmed. `liip/monitor-bundle` compat confirmed. `symfony/process` sufficiency confirmed via Symfony docs + GitHub issue #8454. |
| Features | HIGH (table stakes) / MEDIUM (differentiators) | Table stakes grounded in IETF RFC 7231, IETF draft-inadarei-api-health-check-06, stancl/tenancy v3 source, Symfony Process docs. |
| Architecture | HIGH | All design decisions grounded in live v0.4.1 source reads. Two MEDIUM-confidence areas called out (route prefix, DB health probe path) with mitigation steps. |
| Pitfalls | HIGH | 22 pitfalls catalogued; security-critical ones grounded in live source reads and established bundle patterns from prior milestones (v0.3 profiler DSN leak analogue directly applies to OPS-02). |

**Overall confidence:** HIGH

### Gaps to Address

- **Maintenance flag storage:** Resolved here. DB column (`AbstractTenant::$isInMaintenance`) authoritative; cache is per-request memoization only with 5s max TTL.
- **OPS-02 route prefix:** `/_tenancy/health` assumed — verify against v0.4.1 routing before Phase 33 planning.
- **Nyquist VALIDATION.md enforcement:** Phase 34 governance call — needs a decision by the project owner.
- **2 human_needed UAT items:** Phase 34 scope. Both need either a code-level testability seam or a documented manual-exercise protocol.

---

## Sources

### Primary (HIGH confidence)

- Live source reads (2026-06-25): `TenantContextOrchestrator.php` (prio=20 confirmed), `BootstrapperChain.php` (boot/clear loop, no healthCheck), `TenantMigrateCommand.php` (sequential loop, shared_db guard), `TenantRunCommand.php` (subprocess pattern, exit code handling), `TenantInterface.php` (7-method surface), `AbstractTenant.php` (bool column + trait pattern), `TenantContext.php` (value holder), `config/services.php` (class_exists guard pattern)
- lexik/maintenance-bundle on Packagist — abandoned confirmed
- liip/monitor-bundle on Packagist — v2.25.0 (2026-03-23), compat verified
- Symfony Process Component docs — start/isRunning/wait; streaming callback; deadlock warning
- symfony/symfony Issue #8454 — no native process pool; open since 2013
- IETF draft-inadarei-api-health-check-06 — pass/fail/warn; application/health+json

### Secondary (MEDIUM confidence)

- stancl/tenancy v3 — Tenant Maintenance Mode (DB flag storage model)
- LiipMonitorBundle README (2.x) — liip_monitor.check tag; CheckInterface
- macpaw/symfony-health-check-bundle — alternative verified at interface level
- Health-check design references — liveness vs. readiness route structure

### Tertiary (LOW confidence)

- `HealthCheckBootstrapperInterface` probe API shape and naming — original to this bundle; naming may adjust during Phase 33 planning

---

*Research completed: 2026-06-25*
*Ready for roadmap: yes*
