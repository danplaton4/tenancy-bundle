# Phase 33: Health Checks - Context

**Gathered:** 2026-07-02
**Status:** Ready for planning

<domain>
## Phase Boundary

Deliver **HEALTH-01 through HEALTH-07**: the bundle's first HTTP + CLI **health-check surface** for per-tenant connectivity and bootstrapper health.

**In scope:**
- **Liveness** endpoint `GET /_tenancy/health/live` — reports process health WITHOUT iterating tenants; fast enough for per-second LB/k8s liveness probes (HEALTH-01).
- **Readiness** endpoint `GET /_tenancy/health/ready/{slug}` — probes one tenant's connectivity + bootstrapper health; returns IETF `application/health+json` with HTTP 200 (pass/warn) or 503 (fail) (HEALTH-02).
- **Fleet** endpoint `GET /_tenancy/health` — aggregate summary over tenants for dashboards; bounded (cap + pagination); explicitly **NOT** a k8s probe target (HEALTH-06).
- `HealthCheckBootstrapperInterface` — a **sibling** to `TenantBootstrapperInterface` (no BC break); exposes a read-only `check()` probe (HEALTH-03).
- `TenantHealthChecker` — core service: `setTenant()` → run probes → `clear()` in a `finally`; **never calls `boot()`** (HEALTH-03, Success Criterion 2).
- `HealthResponseSanitizer` — redacts DSNs/credentials from any value entering a response body (HEALTH-04).
- `tenancy:health [--tenant=<slug>|--all]` CLI command (HEALTH-05).
- `HealthCheckIntegrationPass` — auto-registers bundle checks as `liip_monitor.check` services when `liip/monitor-bundle` is installed, `class_exists`-guarded; self-contained endpoints + command work unchanged when it's absent (HEALTH-07).

**Out of scope (own phases / deferred — see REQUIREMENTS.md "Out of Scope"):**
- Ops docs (`docs/ops/health-checks.md`, k8s probe YAML, CDN 5xx warning) → **Phase 34 / DOC-21**. The *docs* for this feature land in 34, not here.
- **Application-level auth on health endpoints** — explicitly Out of Scope (breaks LB/k8s probes). Protect via network ACL / opt-in route import instead.
- **`liip/monitor-bundle` as a hard `require`** — explicitly Out of Scope; stays `require-dev` + `suggest`, `class_exists`-guarded.
- Filesystem + Mailer bootstrapper probes — **deferred** (the sibling interface makes them BC-free to add later; see Deferred Ideas).
- Per-tenant probe-result caching — **deferred** (see Deferred Ideas).

</domain>

<decisions>
## Implementation Decisions

### Route registration & HTTP opt-in (HEALTH-01/02/06, HEALTH-04 opt-in)
- **D-01: Route-import IS the HTTP opt-in.** The bundle ships `config/routes/health.php`; the consuming app imports it with a prefix (e.g. `resource: '@TenancyBundle/config/routes/health.php'`, `prefix: /_tenancy/health`). This is the bundle's **first** HTTP route ever (it registered zero routes through v0.4.1). Rationale: Symfony's "compiler passes wire everything" philosophy applies to *service/DI wiring* — routing is deliberately the app's concern, and auto-injecting routes into a consumer's URL space is non-idiomatic (LiipMonitorBundle, web-profiler, api-platform all ship import-me route files). Route-import is the most honest form of "default disabled" (HEALTH-04): no import → the routes *literally do not exist*, not merely 404. Greppable, auditable, operator controls the mount point (composes with the network-ACL security posture). **No redundant `tenancy.health.enabled` flag** for HTTP — the import is the switch. The CLI command + `TenantHealthChecker` core are always registered (no HTTP-exposure risk).
- **D-02: Fleet endpoint ships as a SEPARATE importable resource** (`config/routes/health_fleet.php`) from live+ready (`config/routes/health.php`). The fleet endpoint enumerates/samples the whole tenant roster (information-disclosure + cost), while live/ready are single-target and cheap — different exposure profiles. Two imports let operators mount the probes on the LB network and separately firewall or decline the dashboard. Cost is one extra doc line; structurally reinforces "fleet is not a probe target."

### Probe depth & bootstrapper coverage (HEALTH-03)
- **D-03: Real connectivity probes, not shallow config checks.** `check()` does a live, cheap round-trip. For the DB: `close()` + lazy connect + `SELECT 1` (exactly the research-validated path). A shallow "is-configured" check is static and can never catch a DOWN dependency — which is the entire point of readiness and what makes 200-vs-503 meaningful to a load balancer.
- **D-04: Coverage this phase = `DatabaseSwitchBootstrapper` + `SharedDriver` only.** These are the two isolation drivers (mutually exclusive: `database_per_tenant` vs `shared_db`), so shipping `check()` on both gives the real DB readiness signal across both modes. **Excluded this phase:** `MailerBootstrapper` (a live SMTP connect is slow/flaky, and mail being down does NOT mean the tenant can't serve requests — it would cause false 503s / probe restart loops); `FilesystemBootstrapper` (deferred — cheap for local adapters but slow/costly for S3-style remote adapters); `DoctrineBootstrapper` (EM-clear has no signal distinct from the DB probe). Because the interface is a **sibling**, all of these are BC-free to add in a later phase.

### Response contract & status model (HEALTH-02, Success Criteria 1/2)
- **D-05: IETF `application/health+json`, states `pass` / `warn` / `fail`.** Aggregate = strict worst-of: any `fail` → HTTP 503; else any `warn` → HTTP 200; else `pass` → HTTP 200 (matches the ROADMAP Success Criterion "HTTP 200 (pass/warn) or 503 (fail)"). The two shipped probes (D-04) emit only `pass`/`fail`; `warn` is carried in the format for future/custom checks.
- **D-06: Unknown `{slug}` on `/ready/{slug}` → HTTP 404** (not 503). An unknown slug is a configuration/routing error, semantically distinct from "tenant exists but unhealthy" (503); lets an operator tell a typo / deleted tenant apart from a real outage. The `{slug}` readiness endpoint is a targeted operator/LB probe, not pod-level liveness, so 404 won't trigger a restart loop. Body is still `application/health+json` with `status: fail` + a "tenant not found" note.
- **D-07: Liveness = pure process check.** `GET /_tenancy/health/live` returns HTTP 200 `{"status":"ok"}` the instant the PHP process can execute the action — zero dependency I/O, never iterates tenants, never touches the DB, **no `degraded` state**. Correct k8s semantics: a FAILED liveness probe KILLS the pod, so it must only fail when the process is truly dead. Dependency health is readiness' job.

### Fleet & CLI at scale (HEALTH-05, HEALTH-06)
- **D-08: HTTP fleet endpoint = cap + pagination.** Default `limit=50` (hard max ~200), `offset`; each request probes at most `limit` tenants **sequentially** (health probes are in-process — never parallelize in-process; that rule is only for out-of-process migrations) and returns per-page tenant statuses + a rollup summary (`{"pass":N,"warn":N,"fail":N}`) + `total`. Deterministic, full coverage via paging, every request bounded. Example: `GET /_tenancy/health?limit=50&offset=0` → `{"total":1240,"offset":0,"limit":50,"summary":{...},"tenants":[...]}`.
- **D-09: `tenancy:health --all` is UNBOUNDED and streams per-tenant.** It iterates every tenant sequentially, streaming results as it goes (mirrors `tenancy:migrate`'s per-tenant output), and exits non-zero if any tenant fails. Bounding is an HTTP-request-safety concern; the CLI is a deliberate operator action, not an auto-fired probe, and has no per-second-probe risk. `--tenant=<slug>` reports one tenant (HEALTH-05).

### Claude's Discretion (locked defaults — downstream can finalize during planning)
- **Two MEDIUM-confidence research flags (SUMMARY.md) — resolve/verify during planning:**
  1. **Route-prefix conflict:** ALREADY VERIFIED during this discussion's codebase scout — the bundle registers **zero** HTTP routes through v0.4.1 (`grep _tenancy src/ config/` = empty; no `src/Controller/`). `/_tenancy/health` is conflict-free. Prefix is effectively locked.
  2. **`DatabaseSwitchBootstrapper::check()` probe safety** — still needs an integration test proving `close()` + lightweight connect + `SELECT 1` under a manual `TenantContext::setTenant()` does **not** mutate global service state, and that `TenantContext::hasTenant() === false` after the probe (Success Criterion 2). This is the one genuine correctness question left for planning/execution.
- **`HealthCheckBootstrapperInterface` shape** (TERTIARY-confidence per research — original to this bundle): the `check()` return type (a `BootstrapperHealthResult` value object with a status enum + component name + optional detail/output), whether `BootstrapperChain` gains an additive `healthCheck()` method vs. the checker iterating bootstrappers directly, and a `TenantHealthReport` aggregate VO — all working names; final surfaces are a planning call. The set→probe→clear-in-`finally` invariant (D, HEALTH-03) is NOT discretionary.
- **`HealthResponseSanitizer`** should reuse / generalize the existing `src/Mailer/DsnSanitizer.php` regex (single source of truth) rather than inventing a new redaction path.
- **Config schema** (a `health` node in `TenancyBundle::getConfigTreeBuilder()`): fleet default `limit` / hard max, and any liip-integration toggle — a planning call. Note D-01: there is **no** HTTP `enabled` flag (route-import is the opt-in).
- Exact class names/namespaces (`TenantHealthChecker`, `TenantHealthController`, `TenantHealthCommand`, `HealthCheckBootstrapperInterface`, `HealthResponseSanitizer`, `HealthCheckIntegrationPass`, and the result/report VOs) and placement (`src/Health/`, `src/Controller/`, `src/Command/`, `src/DependencyInjection/Compiler/`) are working names for planning.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Requirements + locked success criteria
- `.planning/REQUIREMENTS.md` §"Health Checks (epic OPS-02)" — HEALTH-01..HEALTH-07 acceptance criteria; the "Out of Scope" table (no app-level auth on endpoints; `liip/monitor-bundle` not a hard require).
- `.planning/ROADMAP.md` §"Phase 33: Health Checks" — Goal + the 6 Success Criteria (the authoritative TRUE-conditions), incl. "set `TenantContext` manually, run probes, clear in `finally` — `boot()` never called; `hasTenant()` is false after the probe."

### Research (this milestone — HIGH confidence, grounded in live v0.4.1 source reads)
- `.planning/research/SUMMARY.md` §"Phase 33: OPS-02 — Health Checks / MonitorBundle Integration" (lines ~157-169) — component/file table (`HealthCheckBootstrapperInterface`, `TenantHealthChecker`, `TenantHealthController`, `HealthCheckIntegrationPass`, `HealthResponseSanitizer`, additive `BootstrapperChain::healthCheck()`); the **two MEDIUM research flags** (route-prefix conflict — now verified clear; `DatabaseSwitchBootstrapper::check()` probe-safety test); TERTIARY note that the probe API shape is original to this bundle.
- `.planning/research/PITFALLS.md` — the health pitfalls: **8** (health probes calling `boot()` → side effects + `TenantContext` leak in async runtimes → `setTenant()`+`healthCheck()`+`clear()` in `try/finally`), **10** (unauthenticated endpoint exposing DSNs → `HealthResponseSanitizer`; liveness returns only `{"status":"ok"}`), **3** (landlord/health routes must always bypass maintenance — the Phase 32 `allow_paths` handoff).
- `.planning/research/ARCHITECTURE.md` — "strictly additive to the v0.4.1 graph; `TenantContextOrchestrator` (prio 20) and `BootstrapperChain::boot()`/`clear()` unchanged; OPS-02 adds `TenantHealthChecker` which sets `TenantContext` manually and calls an additive `BootstrapperChain::healthCheck()`, bypassing the full boot cycle."
- `.planning/research/STACK.md` — `liip/monitor-bundle ^2.25` (9M installs, Symfony 7.4/8.x verified) as the OPS-02 integration target, `require-dev` + `suggest` only, `class_exists`-guarded; `laminas/laminas-diagnostics` transitive `CheckInterface`; the reject list; net-zero new prod deps.

### Direct code analogs (the established bundle conventions this phase mirrors / extends)
- `src/Bootstrapper/TenantBootstrapperInterface.php` — the interface the new `HealthCheckBootstrapperInterface` sits beside as a **sibling** (both are `boot()`/`clear()`-free additions; health adds `check()`).
- `src/Bootstrapper/BootstrapperChain.php` — the boot/clear loop; add an **additive** `healthCheck()` (no BC break) if planning chooses the chain-mediated path.
- `src/Bootstrapper/DatabaseSwitchBootstrapper.php` — gets `check()` (SELECT 1). Note it currently `close()`s the connection on `boot()`/`clear()`; the probe must be safe under a manual `TenantContext` (research flag 2).
- `src/Driver/SharedDriver.php` + `src/Driver/TenantDriverInterface.php` — `SharedDriver` gets `check()` for `shared_db`. `TenantDriverInterface extends TenantBootstrapperInterface`, and both drivers implement it — so both are the natural `HealthCheckBootstrapperInterface` implementors.
- `src/Context/TenantContext.php` — `setTenant()` / `clear()` / `hasTenant()` are **already public** — `TenantHealthChecker` uses them directly for the set→probe→clear path (no new API needed).
- `src/Mailer/DsnSanitizer.php` — the single-source DSN-redaction regex (`REDACTION_REGEX`); `HealthResponseSanitizer` should reuse/generalize it, not reinvent.
- `src/Profiler/TenantDataCollector.php` — the v0.3 profiler DSN-redaction precedent (the "profiler DSN leak analogue" research cites for OPS-02).
- `src/Command/TenantMigrateCommand.php` — per-tenant streaming output, `--tenant=`/`--all`, exit-code aggregation, `--format=json` single-aggregate-object → the template for `tenancy:health` (D-09).
- `src/DependencyInjection/Compiler/{MailerTransportContractPass,FilesystemContractPass}.php` — the `class_exists`-guarded compiler-pass pattern → template for `HealthCheckIntegrationPass` (liip guard); registered in `TenancyBundle::build()`.
- `src/TenancyBundle.php` — `build()` (compiler-pass registration) + `getConfigTreeBuilder()` (the new `health` config node). Routes are user-imported (D-01), NOT prepended here.
- `config/services.php` — the `class_exists`/`interface_exists` conditional service-registration pattern; where the checker, controller, command, sanitizer, and probes get wired + tagged (`console.command`).

### Direct cross-phase handoffs
- `.planning/phases/32-maintenance-mode/32-CONTEXT.md` — **D-06/D-07 + Integration Points:** the maintenance `allow_paths` config block is where `/_tenancy` (health prefix) MUST be exempted so probes never receive a 503 (Pitfall 3). Also the `--format=json` single-object convention and the content-negotiated JSON precedent (D-04) that the health responses mirror.
- `.planning/phases/31-parallel-migrations/31-CONTEXT.md` — the `--format=json` aggregate convention + the per-tenant atomic-output / null-exit-is-failure lineage the CLI mirrors.

No external (non-`.planning`) specs or ADRs — requirements + research + the source files above fully capture the design.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`TenantContext`** (`src/Context/TenantContext.php`) — `setTenant()` / `clear()` / `hasTenant()` are already public. The whole set→probe→clear-in-`finally` path (HEALTH-03) needs no new context API.
- **`DsnSanitizer`** (`src/Mailer/DsnSanitizer.php`) — `REDACTION_REGEX` + `redact()`; the single source of truth for DSN redaction. `HealthResponseSanitizer` generalizes this to scrub any DSN-shaped string from a response body (HEALTH-04).
- **Both isolation drivers** implement `TenantDriverInterface` (extends `TenantBootstrapperInterface`): `DatabaseSwitchBootstrapper` (`src/Bootstrapper/`) and `SharedDriver` (`src/Driver/`) — the two `HealthCheckBootstrapperInterface` implementors (D-04).
- **`BootstrapperChain`** (`src/Bootstrapper/BootstrapperChain.php`) — a `foreach` over bootstrappers; an additive `healthCheck()` (skip bootstrappers not implementing the sibling interface) is the natural probe orchestrator.
- **Contract-pass + `class_exists` pattern** (`src/DependencyInjection/Compiler/*ContractPass.php`, `config/services.php`) → `HealthCheckIntegrationPass` for the liip guard.
- **CLI** (`src/Command/TenantMigrateCommand.php`) — streaming per-tenant output, `--tenant`/`--all`, `--format=json`, exit aggregation → `tenancy:health`.

### Established Patterns
- **Optional-dependency posture:** every liip/Doctrine touch is `class_exists`/`interface_exists`-guarded; the no-doctrine + no-liip CI lanes must stay green. The self-contained endpoints + command work with zero optional deps.
- **Additive-only to the v0.4.1 graph:** `TenantContextOrchestrator` (prio 20) and `BootstrapperChain::boot()`/`clear()` are untouched. `HealthCheckBootstrapperInterface` is a *sibling* to `TenantBootstrapperInterface` — no existing bootstrapper is forced to implement it.
- **Compiler passes wire services; routing is the app's job** (D-01) — the bundle ships route files to import, not auto-injected routes.

### Integration Points
- **Consumes Phase 32's `allow_paths`:** the operator must add `/_tenancy` (or the chosen health prefix) to `tenancy.maintenance.allow_paths` so a tenant in maintenance still answers health probes (Pitfall 3 — failure = LB restart loop). Document this in Phase 34.
- **Landlord-side reads:** readiness/fleet resolve tenants via `TenantProviderInterface` (`findBySlug()` / `findAll()`), then the checker manually sets `TenantContext` per probe — it does NOT call `BootstrapperChain::boot()`.

### ⚠ Research / planning flag — probe safety (resolve during planning/execution)
The one genuine correctness question: `DatabaseSwitchBootstrapper::check()` performs `close()` + connect + `SELECT 1` under a *manually set* `TenantContext`. Planning MUST add an integration test proving (a) this does not mutate global service state (the next real request re-connects cleanly), and (b) `TenantContext::hasTenant() === false` after the probe completes (the `finally` clear ran). Everything else in this phase is mechanical against established patterns.

</code_context>

<specifics>
## Specific Ideas

The consistent steer was **correct operational semantics + minimum exposure + reuse existing conventions**:
- **k8s liveness-vs-readiness discipline** drove D-07 (liveness never tests dependencies — a failed liveness probe kills the pod) and D-06 (unknown slug = 404, a config error, not a 503 outage).
- **Honest "default disabled"** drove D-01: route-import as the opt-in means the endpoints literally don't exist until imported — the strongest form of default-off, and it keeps routing in the app's control (composes with the network-ACL-only security posture).
- **Scale safety** drove D-08 (every HTTP fleet request bounded via cap+pagination) while D-09 keeps the CLI honest to its operator intent (unbounded `--all`).
- **Probes must mean something** drove D-03 (real `SELECT 1`, not a static config check) and the deliberate exclusion of flaky signals (Mailer/SMTP) from readiness (D-04).

</specifics>

<deferred>
## Deferred Ideas

- **Filesystem + Mailer bootstrapper probes** — excluded from D-04 this phase. Filesystem: a cheap `directoryExists` works for local adapters but is slow/costly for S3-style remote adapters. Mailer: a live SMTP connect is slow/flaky and not a readiness signal. The **sibling** `HealthCheckBootstrapperInterface` makes both BC-free to add in a later phase if demand appears.
- **Per-tenant probe-result caching** — a short-TTL cache (e.g. `symfony/cache` `ArrayAdapter`/Redis) so repeated readiness hits + the fleet endpoint reuse fresh-ish results instead of re-probing DBs. Rejected as the fleet-bounding mechanism (D-08 chose cap+pagination — "probe-all-with-TTL" still probes everyone on the first uncached call). A future enhancement, not v0.5 scope.
- **Fleet random-sampling mode** — a `?sample=N` statistical read for very large fleets. Cap+pagination (D-08) covers the dashboard case deterministically; sampling can be added later if huge-fleet operators ask.
- **Application-level auth on health endpoints** — already Out of Scope in REQUIREMENTS.md (breaks LB/k8s probes; use network ACL / opt-in route import).

None of the above are scope creep into Phase 33 — discussion stayed within the HEALTH-01..07 boundary.

</deferred>

---

*Phase: 33-health-checks*
*Context gathered: 2026-07-02*
