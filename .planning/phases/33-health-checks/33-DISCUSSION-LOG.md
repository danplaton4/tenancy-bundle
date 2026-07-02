# Phase 33: Health Checks - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-07-02
**Phase:** 33-health-checks
**Areas discussed:** Route registration & opt-in, Probe depth & bootstrapper coverage, Response contract & status model, Fleet endpoint bounding at scale

---

## Route registration & opt-in

### Q1 — How should the /_tenancy/health/* routes get registered in a consuming app?

| Option | Description | Selected |
|--------|-------------|----------|
| User imports a shipped route file | Bundle ships `config/routes/health.php`; user adds a 3-line import. Standard Symfony idiom (Liip, web-profiler). Explicit/greppable; forgetting = no endpoints. | ✓ (via "You recommend") |
| Bundle auto-loads routes, gated by config flag | Bundle prepends the resource; `tenancy.health.enabled=true` → routes exist, zero import. Matches "wire everything" philosophy but non-idiomatic/surprising. | |
| You recommend | Pick best fit for conventions + "opt-in default disabled". | ✓ (chosen) |

**User's choice:** "You recommend" → locked **route-import as the HTTP opt-in** (D-01).
**Notes:** Rationale: compiler-pass philosophy is for service wiring, not routing (app's concern); route-import is the most honest "default disabled" (routes don't exist until imported); composes with network-ACL security posture; no redundant `enabled` flag.

### Q2 — Should the fleet endpoint be separately importable?

| Option | Description | Selected |
|--------|-------------|----------|
| Separate route file | `routes/health.php` (live+ready) + `routes/health_fleet.php` (aggregate). Operators mount/firewall separately. | ✓ (via "You recommend") |
| One route file, all three | live+ready+fleet together; rely on network ACL to restrict fleet. Simplest. | |
| You recommend | Pick based on exposure-profile difference. | ✓ (chosen) |

**User's choice:** "You recommend" → locked **separate route files** (D-02).
**Notes:** Fleet enumerates/samples the whole tenant roster (info disclosure + cost) vs. single-target cheap probes — different exposure profiles justify one extra import line.

---

## Probe depth & bootstrapper coverage

### Q1 — How deep should a readiness probe go?

| Option | Description | Selected |
|--------|-------------|----------|
| Real connectivity | Live cheap round-trip: DB `close()`+connect+`SELECT 1`. Makes 200/503 meaningful. | ✓ |
| Shallow / config-presence | Verify configured/wired, no I/O. Fast but near-useless for readiness. | |
| You recommend | — | |

**User's choice:** Real connectivity (recommended) → D-03.
**Notes:** A static config check can never catch a DOWN dependency, defeating readiness.

### Q2 — Which bootstrappers implement HealthCheckBootstrapperInterface this phase? (multiSelect)

| Option | Description | Selected |
|--------|-------------|----------|
| DatabaseSwitch (SELECT 1) | database_per_tenant core readiness signal; research-flagged safety test. | ✓ |
| SharedDriver (shared_db) | shared_db connectivity; mutually exclusive with DatabaseSwitch → both cover both modes. | ✓ |
| Filesystem (adapter reachability) | Cheap for local, slow/costly for S3; flaky signal. | |
| Mailer (SMTP connect) | Slow/flaky; mail-down ≠ can't-serve; false 503s. | |

**User's choice:** DatabaseSwitch + SharedDriver only → D-04.
**Notes:** Filesystem + Mailer deferred (sibling interface = BC-free to add later). Doctrine EM-clear excluded (no distinct signal).

---

## Response contract & status model

### Q1 — /ready/{slug} for a non-existent slug — what status?

| Option | Description | Selected |
|--------|-------------|----------|
| 404 Not Found | Unknown slug = config/routing error, distinct from 503 outage. Body still health+json status:fail. | ✓ |
| 503 Service Unavailable | Uniform non-serviceable = unhealthy; conflates typo with outage. | |
| You recommend | — | |

**User's choice:** 404 (recommended) → D-06.
**Notes:** Separates misconfiguration from real outages; {slug} readiness is a targeted probe, not pod liveness, so 404 won't cause restart loops. Status model (pass/warn/fail, worst-of, fail→503) framed as locked by ROADMAP success criterion → D-05.

### Q2 — What does /health/live check?

| Option | Description | Selected |
|--------|-------------|----------|
| Pure process, always 200 | 200 `{"status":"ok"}`, zero dependency I/O, no tenant iteration, no degraded. Correct k8s liveness. | ✓ |
| Process + landlord DB ping | Also checks landlord connection, can return degraded. Risks restarting healthy pods on a transient blip. | |
| You recommend | — | |

**User's choice:** Pure process, always 200 (recommended) → D-07.
**Notes:** A failed liveness probe KILLS the pod — must only fail when the process is truly dead; dependency health is readiness' job.

---

## Fleet endpoint bounding at scale

### Q1 — How should the HTTP fleet endpoint bound work per request?

| Option | Description | Selected |
|--------|-------------|----------|
| Cap + pagination | Default limit 50 (max ~200), ?limit=&offset=; per-page statuses + rollup + total. Deterministic, bounded, full coverage via paging. | ✓ |
| Random sample of N | Statistical read; non-deterministic, can miss a specific down tenant. | |
| Probe-all with cached TTL | Full coverage, cached; first uncached call still probes everyone. Adds cache dep. | |
| You recommend | — | |

**User's choice:** Cap + pagination (recommended) → D-08.
**Notes:** Health probes run in-process sequentially; a low bounded limit keeps latency acceptable. Sampling + cache-all deferred.

### Q2 — Should tenancy:health --all be bounded like HTTP, or iterate every tenant?

| Option | Description | Selected |
|--------|-------------|----------|
| Unbounded, stream per-tenant | Iterate every tenant, stream results (like tenancy:migrate), non-zero exit on any failure. | ✓ |
| Bounded like HTTP (--limit) | Same cap/pagination as endpoint; consistent but hobbles a legitimate operator action. | |
| You recommend | — | |

**User's choice:** Unbounded, stream per-tenant (recommended) → D-09.
**Notes:** Bounding is an HTTP-request-safety concern; the CLI is a deliberate operator action, not an auto-probe.

---

## Claude's Discretion

- Route-prefix conflict research flag — VERIFIED clear during scout (bundle has zero HTTP routes through v0.4.1).
- `DatabaseSwitchBootstrapper::check()` probe-safety integration test — left for planning/execution (the one genuine correctness question).
- `HealthCheckBootstrapperInterface` / result-VO / `BootstrapperChain::healthCheck()` shapes — TERTIARY-confidence, working names, planning call. The set→probe→clear-in-`finally` invariant is NOT discretionary.
- `HealthResponseSanitizer` reuses `src/Mailer/DsnSanitizer.php` regex.
- `health` config node shape (fleet default limit, liip toggle) — planning call; no HTTP `enabled` flag (route-import is opt-in).
- Class names/namespaces + placement — working names.

## Deferred Ideas

- Filesystem + Mailer bootstrapper probes (sibling interface = BC-free later).
- Per-tenant probe-result caching (short-TTL).
- Fleet random-sampling mode (`?sample=N`) for very large fleets.
- Application-level auth on health endpoints (already Out of Scope — use network ACL).
