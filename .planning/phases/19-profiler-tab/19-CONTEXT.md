# Phase 19: Profiler Tab — Context

**Gathered:** 2026-05-18
**Status:** Ready for planning
**Source:** Heavy upstream lock-in (REQUIREMENTS.md DX-06/DX-02 acceptance criteria fully constrain WHAT and HOW key contracts work). Discussion ran autonomously per user instruction — gray areas resolved with reasonable defaults rooted in Symfony 7.x idiom and the bundle's existing event-driven architecture. User can redirect any decision before planning.

<domain>
## Phase Boundary

Deliver a **dev-only** Symfony Web Debug Toolbar "Tenancy" panel that, on every request in `kernel.debug = true` mode, surfaces the tenant context Symfony resolved for that request:

- **WDT badge** — tenant slug at a glance (or `—` for null-resolution, `⚠` for resolution error)
- **Profiler panel** — slug, tenant identifier, driver (`database_per_tenant` | `shared_db`), connection name (label only, never a DSN), resolver FQCN that picked the tenant, list of bootstrapper FQCNs that ran for the request
- **Three states cleanly handled:** resolved tenant / null-resolution (public/landlord/health-check route) / error during resolution
- **Stored-profile reload safe:** rehydrating a saved profiler dump renders the same panel without serialization errors
- **Compile-out guarantee:** the data collector and its supporting services are absent from the production container, verified by a CI integration test

**In scope:**
- New namespace `Tenancy\Bundle\Profiler\` with:
  - `TenantDataCollector` — `extends AbstractDataCollector`, `collect()` (not `lateCollect()`), `getName()` returns `tenancy`, `getTemplate()` returns the bundle template path
  - `TenantProfilerStash` — per-request state holder; subscribes to `TenantResolved`, `TenantBootstrapped`, `TenantContextCleared`, and `kernel.exception` (tenancy-namespaced exceptions only); implements `Symfony\Contracts\Service\ResetInterface`
- New Twig template `src/Resources/views/Collector/tenant.html.twig` with three `{% if data.state == '...' %}` branches
- `config/services.php` runtime guard — `if ($container->getParameter('kernel.debug')) { /* register profiler services */ }`
- Integration test `tests/Integration/Profiler/TenantDataCollectorCompileOutTest.php` — boots kernels with `debug=true` and `debug=false`, asserts collector service presence/absence accordingly
- Unit tests for stash (capture + reset semantics) and collector (each state produces serialization-safe scalar arrays)
- Stored-profile round-trip test — `serialize`/`unserialize` the collector, assert panel data survives
- Functional integration test — boots a tenancy-enabled test kernel with the WebProfilerBundle, drives a request, asserts the panel appears with the expected fields

**Out of scope:**
- New resolver, new bootstrapper, new event, or any change to `TenantContext`'s zero-dep contract (DX-02 acceptance line 3 explicitly forbids it)
- Mutating `BootstrapperChain` or `TenantContextOrchestrator` — both already dispatch the data we need (`TenantResolved::$resolvedBy`, `TenantBootstrapped::$bootstrappers`)
- Resolution-time / bootstrap-time perf metrics (deferred)
- A per-resolver attempt log (which resolvers declined and why) (deferred)
- A CLI mirror (`tenancy:debug` printing the same data) — different surface
- Production observability — this phase is strictly dev-tooling

**Release target:** v0.3.0 (Phase 19 of 6 v0.3 phases, requirement DX-02).

</domain>

<decisions>
## Implementation Decisions

### Stash mechanism (how `resolved_by` and bootstrapper FQCNs survive to `collect()`)

- **D-01 (Dedicated stash service):** Introduce `Tenancy\Bundle\Profiler\TenantProfilerStash` as a per-request stateful service. It subscribes to:
  - `TenantResolved` → captures `resolvedBy` FQCN (string)
  - `TenantBootstrapped` → captures `bootstrappers` FQCN list (string[])
  - `TenantContextCleared` → calls `$this->reset()`
  - `ExceptionEvent` (`kernel.exception`) → captures `['class' => $e::class, 'message' => $e->getMessage()]` IFF the exception class begins with `Tenancy\Bundle\Exception\` (defensive — no stack trace, no nested arrays)
  Exposes scalar/array getters: `getResolvedBy(): ?string`, `getBootstrapperFqcns(): array`, `getCapturedException(): ?array`. Implements `Symfony\Contracts\Service\ResetInterface` so long-running runtimes (FrankenPHP/Swoole/RoadRunner) auto-reset between requests.
- **D-02 (Rationale — why a dedicated service, not the collector itself):** Symfony's `DataCollector` lifecycle calls `collect()` once on `kernel.response`; by then the events have already fired. A separate stash keeps capture (event-time) and read (collect-time) cleanly separated, makes both halves unit-testable in isolation, and avoids forcing the collector to be an event subscriber (subscribers participate in DI in ways collectors don't have to). It also keeps `TenantContext` zero-dep — the contract DX-02 explicitly preserves.

### Three panel states (resolved / null / error)

- **D-03 (Exception capture scope):** Stash records exceptions **only** when `$e::class` starts with `Tenancy\Bundle\Exception\`. Domain exceptions from the application MUST NOT flip the panel into the error state — a 500 thrown by a controller after successful tenant resolution is still the "resolved" state. Stored fields are `class` (FQCN string) and `message` (string) — no stack trace, no `previous`, no context arrays. Keeps `$this->data` lean and serialization-safe.
- **D-04 (State classification, computed in `collect()`):**
  - `state = 'resolved'` if `$tenantContext->hasTenant()`
  - `state = 'error'` else if `$stash->getCapturedException() !== null`
  - `state = 'null'` otherwise (the by-design public/landlord/health-check path — `TenantContextOrchestrator::onKernelRequest` early-returns when `ResolverChain::resolve()` returns `null`, which is the documented happy-path for non-tenant routes)
- **D-05 (Twig rendering):** Single `tenant.html.twig` with three `{% if data.state == 'resolved' %}` / `{% elseif data.state == 'error' %}` / `{% else %}` blocks. WDT badge: tenant slug for resolved; literal `—` for null; literal `⚠` for error. One inline SVG icon (simple chain glyph) — no JavaScript, no external assets, no CSS imports.

### Compile-out enforcement + CI assertion

- **D-06 (DI guard style):** Use a runtime `if ($container->getParameter('kernel.debug')) { ... }` block in `config/services.php` (a closure over `$container = $configurator->extension(...)`-equivalent — match the existing `interface_exists(MessageBusInterface::class)` pattern already in `config/services.php`). Inside the block, register `tenancy.profiler.stash`, `tenancy.profiler.data_collector`, and the appropriate tags (`kernel.event_subscriber` on the stash; `data_collector` on the collector with `template` + `id` attributes). No dedicated compiler pass — simpler, single source of truth, matches the file's idiomatic style.
- **D-07 (CI compile-out test):** `tests/Integration/Profiler/TenantDataCollectorCompileOutTest.php` boots two minimal test kernels:
  - Kernel A: `debug=true, env=test` → asserts `$container->has(TenantDataCollector::class)` is `true`
  - Kernel B: `debug=false, env=prod` → asserts `$container->has(TenantDataCollector::class)` is `false` AND `$container->has(TenantProfilerStash::class)` is `false`
  Uses the same kernel-spinup pattern as `tests/Integration/IntegrationTestKernel.php` (verified via Phase 18's test infrastructure). Runs as part of `vendor/bin/phpunit --testsuite integration` — already invoked by the existing GitHub Actions matrix; no CI workflow changes required.

### Panel content depth + scalar discipline

- **D-08 (Required fields only — no extras):** `$this->data` is exactly:
  ```
  [
    'state'           => 'resolved'|'null'|'error',
    'slug'            => string|null,
    'tenant_label'    => string|null,   // from TenantInterface::getName(), human-readable identifier
    'driver'          => string|null,   // 'database_per_tenant' | 'shared_db', from %tenancy.driver%
    'connection_name' => string|null,   // LABEL ONLY (see D-09)
    'resolved_by'     => string|null,   // FQCN of the resolver class
    'bootstrappers'   => string[],      // FQCNs in run order; empty array when null/error state
    'error'           => array|null,    // ['class' => string, 'message' => string] or null
  ]
  ```
  No request URL, no host header, no headers-tried list, no timing — those are deferred. The `TenantInterface` does not expose a numeric `getId()`; the slug IS the primary identifier in the bundle's model. The panel surfaces `slug` (machine identifier) + `tenant_label` (`getName()`, human display) which together cover DX-02's "active slug, tenant ID" requirement faithfully.
- **D-09 (`connection_name` resolution — CRITICAL: never leak DSN credentials):** Collector reads two DI parameters at construction time (both already exist):
  - `%tenancy.driver%` — `'database_per_tenant'` or `'shared_db'`
  - `%tenancy.landlord_connection%` — defaults to `'default'`
  - For `database_per_tenant`: display the **tenant connection name** (the Doctrine connection name the bundle's `TenantConnection` wrapperClass is registered against). **Research must confirm the exact parameter name** — likely `%tenancy.database.connection_name%` or hardcoded `'tenant'`. If no dedicated parameter exists, surface the landlord connection name with a `(tenant connection: wraps {landlord_connection})` label.
  - For `shared_db`: display `%tenancy.landlord_connection%`.
  - **Hard rule:** the displayed value is a connection NAME string (`'default'`, `'tenant'`, `'landlord'`). It is NEVER the connection's `params` array, NEVER the DSN, NEVER any value containing `://` or `password`. Defensive sanitization at the collector boundary: if the captured string contains `:` or `@`, log a `RuntimeException` in dev (loud failure during testing).
- **D-10 (WDT badge):** Text-only `Tenant: {slug}` for resolved, `Tenant: —` for null, `Tenant: ⚠` for error. Single inline SVG icon (~24×24 chain/link glyph) embedded via `{{ include('@Tenancy/Collector/_icon.svg.twig') }}` — no asset pipeline, no external CSS.

### Serialization safety

- **D-11 (Scalar-only `$this->data`):** `collect()` assigns exactly one `array<string, scalar|string[]|null|array{class:string,message:string}>` to `$this->data`. NO `TenantInterface` instances, NO closures, NO DBAL `Connection`, NO `Doctrine\ORM\EntityManagerInterface`, NO `Throwable` objects. All FQCN strings stored as plain `string`. Defensive normalization: `array_values(array_map('strval', $stash->getBootstrapperFqcns()))`. The bundled stored-profile round-trip test (`serialize($collector); unserialize(...);` asserting `getData()` equality) is the canonical proof.

### Template location + asset hygiene

- **D-12 (Twig template path):** `src/Resources/views/Collector/tenant.html.twig`. Symfony auto-discovers via `AbstractBundle`'s default path mapping; the bundle's `@Tenancy` Twig namespace exposes the template as `@Tenancy/Collector/tenant.html.twig`. `getTemplate()` returns this path. Single file, no partials beyond the inline icon helper (`_icon.svg.twig`).

### Tests (required by acceptance + planning hooks)

- **D-13 (Test inventory):** Plan must include:
  1. `tests/Unit/Profiler/TenantProfilerStashTest.php` — each subscriber method captures correctly; `reset()` clears all fields; non-tenancy exceptions are ignored.
  2. `tests/Unit/Profiler/TenantDataCollectorTest.php` — `collect()` produces the D-08 shape for all three states; `connection_name` defensive sanitization rejects DSN-looking strings.
  3. `tests/Integration/Profiler/TenantDataCollectorSerializationTest.php` — stored-profile round-trip (`serialize` + `unserialize`) preserves `getData()` byte-for-byte.
  4. `tests/Integration/Profiler/TenantDataCollectorCompileOutTest.php` — D-07.
  5. `tests/Integration/Profiler/TenantDataCollectorWdtTest.php` — boots a tenancy-enabled test kernel with WebProfilerBundle in `require-dev`, drives a resolved-tenant request, a null-resolution request, and an error request; asserts the profiler URL responds 200 and the rendered panel contains expected substrings (slug for resolved, `—` for null, exception class for error).

### Claude's Discretion

- Internal class layout of `TenantProfilerStash` (private fields vs single `?array $captured` blob) — researcher/planner picks based on test ergonomics.
- Exact wording inside the Twig template (panel headings, table labels) — researcher cross-references Symfony's bundled data collectors (TwigDataCollector, DoctrineDataCollector) for tone consistency.
- Whether to display the bootstrapper list as a `<ul>` or a `<table>` — researcher's call based on Symfony's WebProfilerBundle CSS norms.
- Icon SVG path data — any simple ~24px chain/link/key glyph is fine; the Symfony Profiler ecosystem accepts inline SVG via the standard helper.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Authoritative scope + acceptance

- `.planning/REQUIREMENTS.md` §DX-02 (lines 30–36) — the locked acceptance criteria. The phrase "via a `TenantResolved` event subscriber that stashes the resolver FQCN — keeps `TenantContext` zero-dependency contract intact" is the precise architectural constraint D-01 implements.
- `.planning/ROADMAP.md` §"Phase 19: Profiler Tab" (lines 84–95) — goal + 5 success criteria.

### Bundle architecture (existing code that must NOT change)

- `src/Context/TenantContext.php` — zero-dep contract being preserved (no new constructor args).
- `src/Event/TenantResolved.php` — public readonly `resolvedBy: string` is the FQCN source the stash captures (already plumbed; no change needed).
- `src/Event/TenantBootstrapped.php` — public readonly `bootstrappers: string[]` is the FQCN list source the stash captures.
- `src/Event/TenantContextCleared.php` — triggers the stash's `reset()`.
- `src/Bootstrapper/BootstrapperChain.php` — already builds and dispatches the FQCN list (line ~26–32 in current code).
- `src/EventListener/TenantContextOrchestrator.php` — already passes `$resolution->resolvedBy` into `TenantResolved`; null-resolution path early-returns without dispatching — the basis for D-04's `state = 'null'` semantics.
- `src/TenancyBundle.php` — `loadExtension()` declares `tenancy.driver` (line ~118) and the bundle's other DI parameters. Planner must confirm the exact tenant-connection-name parameter (see D-09).
- `config/services.php` — pattern for conditional registration already established (`interface_exists(MessageBusInterface::class)` block at file tail). Match this style for the `kernel.debug` guard.

### Prior phase decisions (apply or constrain)

- `.planning/phases/18-tenancy-install/18-CONTEXT.md` — established the project's integration-test kernel pattern (`tests/Integration/IntegrationTestKernel.php`) and the conditional-service-registration convention. The compile-out test in D-07 must mirror this kernel-spinup pattern.
- `.planning/phases/15-architectural-fixes-v0-2/15-CONTEXT.md` — relevant for connection-name semantics under both drivers (touched the bundle's connection-name handling).
- `.planning/phases/17-origin-header-resolver/17-CONTEXT.md` — most recent resolver-shape decisions; `OriginHeaderResolver::class` will appear in the panel's `resolved_by` field for typical SaaS dev workflows.

### External docs (researcher should validate the contracts)

- Symfony 7.x Profiler — `AbstractDataCollector` API: https://symfony.com/doc/current/profiler/data_collector.html (`collect()` vs `lateCollect()`, `getName()`, `getTemplate()`).
- Symfony 7.x `Symfony\Contracts\Service\ResetInterface` — for the stash's request-boundary reset in long-running runtimes.
- Symfony 7.x WebProfilerBundle — toolbar/icon template conventions (block names `toolbar`, `head`, `menu`, `panel`).

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets

- **`TenantResolved::$resolvedBy`** (`src/Event/TenantResolved.php`) — already a `public readonly string` FQCN. Zero plumbing work to surface it; stash just subscribes.
- **`TenantBootstrapped::$bootstrappers`** (`src/Event/TenantBootstrapped.php`) — already a `public readonly string[]` of FQCNs. Zero plumbing work.
- **`TenantContextCleared`** event (`src/Event/TenantContextCleared.php`) — natural reset trigger; stash subscribes and clears.
- **`TenantContext::hasTenant()`** — the canonical "did a tenant get resolved?" check; D-04 uses it as the primary state discriminator.
- **`%tenancy.driver%`** DI parameter (`src/TenancyBundle.php` line ~118) — already wired; collector reads directly.
- **Conditional service-registration pattern** in `config/services.php` tail (`interface_exists(MessageBusInterface::class)` block) — direct template for the `kernel.debug` guard.

### Established Patterns

- **Bundle uses `AbstractBundle`** (`src/TenancyBundle.php`) — Symfony 7.x idiom; default resource paths apply, so `src/Resources/views/Collector/tenant.html.twig` is auto-discovered without explicit configuration.
- **Optional-dependency guards** — bundle convention is `class_exists(...)` / `interface_exists(...)` for Doctrine/Messenger. WebProfilerBundle is a `require-dev` concern; production never loads it, and the `kernel.debug` guard subsumes the need for `class_exists` here.
- **Per-request services with `ResetInterface`** — bundle precedent for state holders. The stash follows the same shape.
- **Integration-test kernel** (`tests/Integration/IntegrationTestKernel.php`) — boot in `:memory:` SQLite; same harness used for the compile-out test in D-07.

### Integration Points

- **Event wiring:** Stash auto-registers via `kernel.event_subscriber` tag (autoconfigure). No manual `AsEventListener` attribute needed; subscriber interface is the path of least friction.
- **Twig namespace:** `@Tenancy` namespace is auto-registered by `AbstractBundle`; `getTemplate()` returns `'@Tenancy/Collector/tenant.html.twig'`.
- **WebProfilerBundle:** ships only as `require-dev` in the bundle's own composer.json (not currently a dep — planner must add). End-user installs already have WebProfilerBundle in their app's `require-dev` per standard Symfony Flex recipe; the bundle's profiler integration silently activates when both `WebProfilerBundle` and `kernel.debug=true` are present.

</code_context>

<specifics>
## Specific Ideas

- **The "stashes the resolver FQCN" phrasing in DX-02** is the precise architectural directive. D-01 implements it literally — a stash, subscribing to `TenantResolved`, storing the FQCN string. The stash also grows to handle `TenantBootstrapped` and tenancy exceptions because they share the same lifecycle constraint (need to survive from event-time to `collect()`-time).
- **"No DSN strings with credentials"** in the DX-02 acceptance is enforced by D-09's hard rule: the panel surfaces a connection NAME (a label like `'default'`), never the connection's params/DSN. The defensive `:`/`@` check rejects any DSN-looking value.
- **"`collect()` NOT `lateCollect()`"** in DX-02 is a deliberate constraint: the collector reads scalars synchronously on `kernel.response`. The stash holds the events' captured strings exactly so `collect()` can be synchronous and scalar-only.

</specifics>

<deferred>
## Deferred Ideas

- **Resolution-time / bootstrap-time perf metrics** — a future "Profiler Perf Panel" phase (post-v0.3) could add `Stopwatch` events around the resolver chain and bootstrapper chain and surface durations. Not in DX-02 scope.
- **Per-resolver attempt log** — listing every resolver that was tried, whether it declined and why, before the winning resolver matched. Useful for debugging resolver-order issues but not required by DX-02. Future debug-only feature.
- **Tenant-scoped cache hit/miss counters** — surface how many times the tenant-aware cache adapter served scoped keys vs missed. Could be part of a future cache-observability phase. Out of scope for Phase 19.
- **`tenancy:debug` CLI command** — a console mirror that prints the same panel data for a hypothetical request (`bin/console tenancy:debug --host=acme.example.com`). Different surface, different concerns, separate phase if it materializes.
- **Multi-tenant-per-request (sub-requests with different tenants)** — Symfony's Profiler only collects the main request; sub-request profiling is its own rabbit hole. Explicitly out of scope.
- **Production observability hook (StatsD/OpenTelemetry export of the same data)** — would belong in a separate "production observability" phase and would be governed by very different constraints (no Twig, no profiler, must be cheap on hot path). Not Phase 19.

</deferred>

---

*Phase: 19-profiler-tab*
*Context gathered: 2026-05-18*
