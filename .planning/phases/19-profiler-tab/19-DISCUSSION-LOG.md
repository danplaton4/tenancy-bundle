# Phase 19: Profiler Tab — Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in `19-CONTEXT.md` — this log preserves the alternatives considered.

**Date:** 2026-05-18
**Phase:** 19-profiler-tab
**Mode:** Autonomous (user directive: no clarifying questions). Claude analyzed the four gray areas left after REQUIREMENTS.md DX-02 locked the acceptance criteria, made the reasonable call on each, and documented the alternatives below.

**Areas considered:** Stash mechanism, Panel-state classification, Compile-out enforcement, Panel content depth + scalar discipline

---

## Stash mechanism (resolved_by + bootstrapper FQCNs survival to `collect()`)

| Option | Description | Selected |
|--------|-------------|----------|
| A. Dedicated `TenantProfilerStash` service (subscriber) read by collector | Separates capture (event-time) from read (collect-time). Implements `ResetInterface`. Single-responsibility services. | ✓ |
| B. DataCollector itself implements `EventSubscriberInterface` | Fewer moving parts but tangles two lifecycles in one class; collector becomes stateful across boot/collect boundary. | |
| C. Stash via `Request::$attributes` set by an orchestrator hook | Avoids a new service entirely but couples the panel to a magic attribute key and forces `TenantContextOrchestrator` to grow new responsibilities. Violates "TenantContext zero-dep" spirit. | |
| D. Modify `TenantContext` to expose `getResolvedBy()` / `getBootstrappers()` | Simplest read path but **explicitly forbidden** by DX-02 acceptance line 3 ("keeps TenantContext zero-dependency contract intact"). | |

**Claude's choice:** A. Locked by D-01/D-02 in CONTEXT.md.
**Rationale:** Option A is the literal implementation of the DX-02 phrasing "via a `TenantResolved` event subscriber that stashes the resolver FQCN." Cleanest test surface, no contract violations, idiomatic Symfony.

---

## Three-state classification (resolved / null / error)

| Option | Description | Selected |
|--------|-------------|----------|
| A. State derived in `collect()` from `TenantContext::hasTenant()` + stash exception flag | Single source of truth at collect-time; null-resolution path is the by-design happy path for public routes. | ✓ |
| B. State eagerly set by orchestrator at request time | Requires the orchestrator to know about the profiler — couples production code to a dev-only concern. | |
| C. Three separate data collectors (one per state) | Symfony only supports one collector per `getName()`; reshapes the API for no benefit. | |

**Claude's choice:** A. Locked by D-03/D-04/D-05.
**Rationale:** Computing state at `collect()`-time matches the `AbstractDataCollector` contract. Exception capture is scoped to `Tenancy\Bundle\Exception\*` (D-03) so generic 500s after successful resolution don't flip the panel into a misleading "error" state.

---

## Compile-out enforcement + CI assertion

| Option | Description | Selected |
|--------|-------------|----------|
| A. Runtime `if ($container->getParameter('kernel.debug'))` block in `config/services.php` | Matches the existing `interface_exists(MessageBusInterface::class)` pattern at the file tail. One source of truth. | ✓ |
| B. Dedicated compiler pass that removes the data collector when `!debug` | Defensive but adds a pass class for a one-line check; over-engineered for the constraint. | |
| C. Conditional via `when@dev` YAML import overlay | The bundle uses PHP DI (`config/services.php`), not YAML; would require introducing YAML config files just for this. | |
| D. Service tagged `kernel.reset` + per-request null-check | Doesn't actually remove the service from prod containers — fails the DX-02 compile-out requirement. | |

**Claude's choice:** A. Locked by D-06.
**CI test:** Integration test `TenantDataCollectorCompileOutTest.php` boots `debug=true` and `debug=false` kernels and asserts service presence/absence respectively (D-07). Runs as part of existing `--testsuite integration`.

---

## Panel content depth + scalar discipline

| Option | Description | Selected |
|--------|-------------|----------|
| A. Strictly the required fields (slug, label, driver, connection_name, resolved_by, bootstrappers, state, error) | Minimum data, max safety, easy serialization round-trip. | ✓ |
| B. Required + request URL/host/headers tried | Useful but Symfony's bundled Request panel already surfaces this; we'd be duplicating data. | |
| C. Required + per-resolver attempt list (which resolvers tried + declined) | High value for debug but requires plumbing on the ResolverChain — out of DX-02 scope. Deferred. | |
| D. Required + Stopwatch timings (resolve, boot, total) | Tempting but requires `Stopwatch` integration; not in DX-02 acceptance. Deferred. | |

**Claude's choice:** A. Locked by D-08.
**Hard rule (D-09, D-11):** `$this->data` is scalars/string-arrays only. `connection_name` is a label string, never a DSN; defensive `:`/`@` check rejects DSN-shaped values at the collector boundary.

---

## Claude's Discretion

- Internal class layout of `TenantProfilerStash` (separate fields vs single `?array $captured` blob) — researcher/planner picks based on test ergonomics.
- Twig template prose (panel headings, table labels) — researcher cross-references Symfony's bundled data collectors for tone consistency.
- Bootstrapper list rendered as `<ul>` or `<table>` — researcher's call per WebProfilerBundle CSS norms.
- Icon SVG path data — any ~24px chain/link/key glyph; researcher picks.

## Deferred Ideas

(Recorded in `19-CONTEXT.md` `<deferred>` — paraphrased here for the audit trail.)

- Resolution / bootstrap perf metrics → future "Profiler Perf Panel" phase
- Per-resolver attempt log → future debug-only feature
- Tenant-scoped cache hit/miss counters → future cache-observability phase
- `tenancy:debug` CLI mirror → separate phase if requested
- Multi-tenant-per-request (sub-requests) → Profiler limitation, explicitly out of scope
- Production observability (StatsD/OTel export) → different constraints, different phase
