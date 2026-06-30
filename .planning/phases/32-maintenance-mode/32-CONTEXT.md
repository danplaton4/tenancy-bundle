# Phase 32: Maintenance Mode - Context

**Gathered:** 2026-06-30
**Status:** Ready for planning

<domain>
## Phase Boundary

Deliver **MAINT-01 through MAINT-09**: per-tenant maintenance mode. An operator toggles a single tenant into maintenance via CLI; requests to that tenant return **HTTP 503 + `Retry-After` + `Cache-Control: no-store`**, while every other tenant, the landlord, and public/health routes keep serving normally.

**In scope:**
- CLI: `tenancy:maintenance:enable <slug>`, `tenancy:maintenance:disable <slug>`, `tenancy:maintenance:status` (MAINT-01/02/09).
- A `kernel.request` listener at **priority 16** (after `TenantContextOrchestrator` @20, so the tenant is already resolved) that returns 503 for a tenant in maintenance (MAINT-03).
- Null-tenant / landlord / public / health-route bypass via the listener's `!hasTenant()` early return (MAINT-04).
- Maintenance state on the tenant entity: a **DB column** added via a new `TenantMaintenanceConfigTrait`, authoritative, persists across requests/processes, never leaks (MAINT-05).
- A **global** IP / route / path allow-list that bypasses maintenance (MAINT-06).
- A custom Twig template override for the 503 page (MAINT-07).
- `TenantMaintenanceEnabled` / `TenantMaintenanceDisabled` events on toggle (MAINT-08).
- `MaintenanceModeContractPass` — fails compilation if any tenancy listener is wired at priority ≥ 20 (Success Criterion 3).
- `isInMaintenance(): bool` added to `TenantInterface`, BC break mitigated by the trait's `false` default.

**Out of scope (own phases / deferred — see REQUIREMENTS.md "Out of Scope"):**
- Health-check endpoints / CLI / LiipMonitorBundle (Phase 33 / OPS-02). Phase 32 only *provides the allow-list mechanism* that Phase 33 will use to exempt `/_tenancy/health` routes.
- Ops docs incl. the `maintenance-mode.md` page + UPGRADE 0.4→0.5 BC note (Phase 34 / DOC-21 — the *docs* for this feature land in 34, not here).
- **Global / site-wide (all-tenants) maintenance mode** — explicitly Out of Scope (a web-server/LB concern; deferred to v0.6 by demand). This phase is strictly *per-tenant*.
- **File-based maintenance flag** — explicitly Out of Scope (unsafe in multi-pod / async deployments; the DB column is the authority).
- Per-tenant maintenance message / per-tenant Retry-After / "down until" auto-expiry timestamp — deferred (see Deferred Ideas).

</domain>

<decisions>
## Implementation Decisions

### 503 response shape (MAINT-03, MAINT-07)
- **D-01: Built-in hardcoded HTML is the default 503 body — no Twig render on the hot path.** When no custom template is configured, the listener returns a small, self-contained HTML page built in PHP. Rationale: a maintenance page that itself 500s is the worst failure mode; mirrors Symfony's own unkillable error pages. Twig runs **only** when a custom template is configured (D-02).
- **D-02: Custom-template override = a single global config key `tenancy.maintenance.template`** (a Twig template path). When set, the listener renders it via the Twig service (`symfony/twig-bundle` is already a hard `require`). **If the render throws, fall back to the built-in HTML (D-01)** — defense in depth. Per-tenant template selection is deferred.
- **D-03: `Retry-After` comes from a global config default `tenancy.maintenance.retry_after`** (integer seconds, default `3600`). One knob so operators can tune CDN/client back-off without code changes. `Cache-Control: no-store` is always set on the 503 (MAINT-03; prevents a CDN/proxy from caching the 503 — research Pitfall on 5xx caching).
- **D-04: Content-negotiated body.** If the request negotiates JSON (`Accept: application/json`, or an XHR/JSON request), return a small JSON body `{"status":"maintenance","retryAfter":N}`; otherwise return HTML (built-in or custom-template). The **503 status + `Retry-After` + `Cache-Control: no-store` headers are identical** in both branches. Rationale: the bundle targets APIs + SPAs; an HTML blob in a JSON client is noise.

### Stored maintenance state (MAINT-05) — the BC-sensitive decision
- **D-05: Pure boolean state.** One column: `bool $inMaintenance` on `AbstractTenant`, supplied by a new `TenantMaintenanceConfigTrait` (mirrors `TenantMailerConfigTrait` / `TenantFilesystemConfigTrait`). `TenantInterface` gains **exactly one** method — `isInMaintenance(): bool` — with the trait providing the `false` default so existing custom tenant entities don't break. Rationale: this is *the* BC break of the v0.5 milestone (called out in the roadmap's UPGRADE 0.4→0.5 note); every extra interface method widens it. Retry-After stays global config (D-03); an app wanting a per-tenant message can already read `$tenant` inside its custom Twig template (D-02). Matches the research-locked "DB column (bool) is authoritative".

### Allow-list (MAINT-06)
- **D-06: Global config block, not per-tenant.** `tenancy.maintenance.allow_ips`, `tenancy.maintenance.allow_routes`, `tenancy.maintenance.allow_paths`. Mirrors `OriginHeaderResolver`'s compile-time-normalized global `allow_list`. A maintenance allow-list is an operator concern (reach **any** down tenant), not a per-tenant setting — and keeps the entity at one bool (D-05). This config block is also where **Phase 33's health routes get exempted** (cross-phase handoff).
- **D-07: Matching semantics.** A request bypasses maintenance if **any** of the three matches (OR):
  - **IP:** `Symfony\Component\HttpFoundation\IpUtils::checkIp($request->getClientIp(), $allow_ips)` — supports single IPs and CIDR (e.g. `203.0.113.4`, `10.0.0.0/8`). Not previously used in the bundle but stdlib.
  - **Route:** exact `_route` name match against `allow_routes`.
  - **Path:** **prefix** match — `str_starts_with($request->getPathInfo(), $entry)` against `allow_paths` (e.g. `/admin`, `/_tenancy`). One entry covers a whole subtree, including the Phase 33 `/_tenancy` health prefix.

### Commands (MAINT-01/02/09)
- **D-08: Idempotent enable/disable; events fire only on a real state transition.** `enable` on an already-in-maintenance tenant prints "already in maintenance" and exits `0`; `disable` on an "up" tenant exits `0`. `TenantMaintenanceEnabled` / `TenantMaintenanceDisabled` (MAINT-08) is dispatched **only** when the boolean actually changes (no duplicate event on a no-op). Matches the idempotent lineage of Phase 26 resync / Phase 31 migrate; friendly to ops automation/playbooks.
- **D-09: Single slug per command.** `tenancy:maintenance:enable <slug>` takes exactly one tenant (MAINT-01 "a single tenant"). No `--all` / variadic — site-wide maintenance is explicitly Out of Scope (v0.6).
- **D-10: `status` = human table + opt-in `--format=json`.** `tenancy:maintenance:status` lists tenants **currently in maintenance** (MAINT-09) as a table; `--format=json` is added for CI/ops parity with the `--format=json` shipped on `tenancy:migrate` in Phase 31. Tenants not in maintenance are not listed.

### Claude's Discretion (sensible defaults locked here — confirmed with user via "you recommend")
- All of D-01..D-04 (the entire 503-response shape) were the user's explicit "you recommend" — locked as above.
- **Persistence path:** enable/disable resolve the tenant via `TenantProviderInterface::findBySlug()`, flip the bool, and persist via the **landlord EntityManager** (tenants live landlord-side). This is a plain landlord-side column write — it MUST NOT call `BootstrapperChain::boot()` or set `TenantContext` (unlike `SharedEntityResyncCommand`, which does boot per tenant). Confirm the exact landlord-EM accessor during planning.
- Exact class names/namespaces (`TenantMaintenanceModeListener`, `TenantMaintenanceConfigTrait`, `MaintenanceModeContractPass`, the three command classes, the two event classes) are working names; final placement (`src/EventListener/`, `src/Maintenance/`, `src/DependencyInjection/Compiler/`, `src/Command/`, `src/Event/`) and method surfaces are for planning.
- Config schema lives in `TenancyBundle` `getConfigTreeBuilder()` under a new `maintenance` node (`enabled` default false?, `template`, `retry_after`, `allow_ips`, `allow_routes`, `allow_paths`). Whether maintenance is feature-flagged behind `tenancy.maintenance.enabled` (like filesystem) vs always-on is a planning call — the listener is cheap (one in-memory bool read) so always-on is defensible, but a flag matches the bundle's opt-in convention.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Requirements + locked decisions
- `.planning/REQUIREMENTS.md` §"Maintenance Mode (epic OPS-01)" — MAINT-01..MAINT-09 acceptance criteria; the "Out of Scope" table (no global maintenance, no file-based flag, no `liip/monitor-bundle` require).
- `.planning/ROADMAP.md` §"Phase 32: Maintenance Mode" — Goal + the 5 Success Criteria (the authoritative TRUE-conditions), incl. the priority-16 / `MaintenanceModeContractPass` ≥20 compile-fail requirement.

### Research (this milestone — HIGH confidence, grounded in live v0.4.1 source reads)
- `.planning/research/SUMMARY.md` §"Phase 32: OPS-01 — Tenant Maintenance Mode" (lines ~143-153) — "fully specified, plan immediately"; storage decision resolved (DB column bool authoritative, cache = per-request memoization only, 5s max TTL); names the components; the OPS-01a–h breakdown; the component/file table (lines ~81-93) listing `TenantMaintenanceModeListener` / `TenantMaintenanceConfigTrait` / `MaintenanceModePass` and the `TenantInterface` MODIFIED row.
- `.planning/research/PITFALLS.md` — the maintenance pitfalls: **1/20** (listener priority ≥ 20 → empty context → silently does nothing; register at 16 + `MaintenanceModeContractPass` compile-fail), **3** (landlord/health routes must always bypass → null-branch on `!hasTenant()`), **22** (`TenantInterface` BC break → `TenantMaintenanceConfigTrait` `return false` default), and the CDN 5xx-caching warning (`Cache-Control: no-store`).
- `.planning/research/ARCHITECTURE.md` — "strictly additive to the v0.4.1 graph; `TenantContextOrchestrator` (prio 20) and `BootstrapperChain` unchanged; OPS-01 inserts a new `kernel.request` listener at priority 16."
- `.planning/research/STACK.md` — `lexik/maintenance-bundle` abandoned (2018, Symfony ^4); build natively; net-zero new prod deps (`symfony/cache`, `http-foundation`, `twig-bundle` already required); the reject list (`toshy/`, `prolix/` forks — no per-tenant semantics).

### Direct code analogs (the established bundle conventions this phase mirrors)
- `src/Mailer/TenantMailerConfigTrait.php` + `src/Filesystem/TenantFilesystemConfigTrait.php` — the zero-BC-break trait pattern (nullable column + getter/setter + `static` return) → template for `TenantMaintenanceConfigTrait`.
- `src/TenantInterface.php` + `src/Entity/AbstractTenant.php` — where `isInMaintenance(): bool` + the `#[ORM\Column(type: 'boolean')] private bool $inMaintenance = false;` column are added.
- `src/DependencyInjection/Compiler/MailerTransportContractPass.php` + `FilesystemContractPass.php` — compile-time guard pattern (early-return when disabled → assert → `throw new \LogicException`), registered in `TenancyBundle::build()` → template for `MaintenanceModeContractPass`.
- `src/EventListener/TenantContextOrchestrator.php` — `#[AsEventListener(KernelEvents::REQUEST, priority: 20)]`, `isMainRequest()` guard, the null-branch (`resolve()` → if null, return) → the priority-16 listener mirrors this shape (but reads `TenantContext`, not the resolver).
- `src/Context/TenantContext.php` — `hasTenant()` / `getTenant()` — the listener's tenant check.
- `src/Command/TenantMigrateCommand.php` + `src/Command/SharedEntityResyncCommand.php` — `#[AsCommand(name: 'tenancy:*')]`, constructor DI, `findBySlug()`/`findAll()`, registration in `config/services.php`; `SharedEntityResyncCommand` shows the EM persist/flush pattern (BUT note D-Discretion: maintenance writes landlord-side, no boot).
- `src/Event/TenantResolved.php` / `TenantBootstrapped.php` / `TenantContextCleared.php` — readonly-constructor event pattern → template for `TenantMaintenanceEnabled` / `TenantMaintenanceDisabled`.
- `src/Resolver/OriginHeaderResolver.php` + its `OriginHeaderResolverConfigPass` — the global compile-time-normalized allow-list precedent (D-06).

### Direct command-convention analog — Phase 31
- `.planning/phases/31-parallel-migrations/31-CONTEXT.md` — the `--format=json` single-aggregate-object convention (D-03/D-04 there) that `tenancy:maintenance:status --format=json` (D-10) should mirror; the idempotent/continue-on-failure CLI lineage.

No external (non-`.planning`) specs or ADRs — requirements + research + the source files above fully capture the design.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **Config-trait pattern** — `src/Mailer/TenantMailerConfigTrait.php`, `src/Filesystem/TenantFilesystemConfigTrait.php`: nullable `#[ORM\Column]` property + getter/setter + fluent `static` return, supplying a safe default. `TenantMaintenanceConfigTrait` follows this exactly with `bool $inMaintenance = false` and `isInMaintenance(): bool` / a setter.
- **`AbstractTenant`** (`src/Entity/AbstractTenant.php`, `#[ORM\MappedSuperclass]`) already declares `bool $isActive = true` via `#[ORM\Column(type: 'boolean')]` — the exact column style for `$inMaintenance`.
- **Contract-pass pattern** — `src/DependencyInjection/Compiler/{MailerTransportContractPass,FilesystemContractPass}.php`: early-return when feature absent/disabled → guard checks → `throw new \LogicException`. Registered in `TenancyBundle::build()` (the `addCompilerPass` block). `MaintenanceModeContractPass` asserts the listener's `kernel.request` priority is `< 20` (Success Criterion 3).
- **`TenantContextOrchestrator`** (`src/EventListener/TenantContextOrchestrator.php`, `PRIORITY = 20`) — copy its `isMainRequest()` guard + null-branch shape; the maintenance listener at **16** reads `$tenantContext->hasTenant()` / `getTenant()->isInMaintenance()` instead of running the resolver.
- **`TenantContext`** (`src/Context/TenantContext.php`) — `hasTenant()`, `getTenant()`. The whole maintenance check is one in-memory bool read off the already-resolved tenant (see research flag below).
- **`IpUtils`** — `Symfony\Component\HttpFoundation\IpUtils::checkIp()` (stdlib, not yet used in bundle) for `allow_ips` CIDR matching.
- **CLI** — `src/Command/TenantMigrateCommand.php` / `SharedEntityResyncCommand.php`: `#[AsCommand]`, DI, `findBySlug()`/`findAll()`, `config/services.php` registration + `console.command` tag.
- **Events** — `src/Event/*` readonly-constructor classes.

### Established Patterns
- Compiler passes are registered in `TenancyBundle::build()`; feature config nodes live in `TenancyBundle::getConfigTreeBuilder()` (the `maintenance` node is new). Symfony `#[AsEventListener]` attribute on the listener class declares the priority (as `TenantContextOrchestrator` does), which `MaintenanceModeContractPass` inspects.
- Listener ordering invariant: `TenantContextOrchestrator` @20 (after Router @32, before Security firewall @8); maintenance @16 sits *inside* that window so the tenant is resolved but we're still early enough to short-circuit the response.
- 503 / error: the bundle currently throws `HttpExceptionInterface` exceptions (e.g. `TenantInactiveException` → 403). Maintenance instead **builds a Response directly** (`$event->setResponse(new Response(..., 503, headers))`) so it can attach `Retry-After` + `Cache-Control: no-store` and content-negotiate — not via an exception.

### Integration Points
- **Cross-phase handoff to Phase 33:** the `allow_paths` config block is where `/_tenancy/health*` routes must be exempted so health probes never get a 503 (research Pitfall 3 — failure to exempt health routes → LB restart loop). Phase 33 references this config.
- **Landlord EM write:** enable/disable persist the flipped bool to the **landlord** EM (tenants are landlord-side entities). No tenant `boot()` / `TenantContext` set in the commands (contrast with resync).
- The listener is strictly additive: `TenantContextOrchestrator` / `BootstrapperChain` are untouched (research ARCHITECTURE invariant).

### ⚠ Research / planning flag — cache coherence (resolve before coding)
Because the listener runs at priority **16, after** the tenant is resolved at 20, `getTenant()->isInMaintenance()` is a **free in-memory read** — the `symfony/cache` memoization the research mentioned is likely **unnecessary on the request path**. The real subtlety: when an operator flips the DB column (enable), does the `TenantProvider` serve a **stale cached tenant object** on the next request, so maintenance doesn't take effect (or doesn't lift)? Planning MUST verify the provider's caching/identity-map behavior and decide whether any cache invalidation is needed on toggle (e.g. the enable/disable command clearing a provider cache, or the provider reading fresh). This is the one genuine correctness question; the rest is mechanical.

</code_context>

<specifics>
## Specific Ideas

The consistent steer was **minimum BC surface + robustness + consistency with the existing bundle**:
- The 503 page must be **unkillable** — hardcoded HTML default, Twig only for an opt-in override with HTML fallback (D-01/D-02). An ops feature whose error page can itself 500 is unacceptable.
- Keep the `TenantInterface` change to **exactly one method** (`isInMaintenance(): bool`) because it is the milestone's sole BC break (D-05). Everything that could have been per-tenant state (message, retry-after, until) was pushed to global config or deferred to protect that surface.
- Reuse established conventions wholesale: the config-trait, contract-pass, `#[AsEventListener]` priority, `OriginHeaderResolver` global-allow-list, and the Phase 31 `--format=json` shape.

The only place richness was chosen over the bare minimum was content-negotiated JSON 503s (D-04) — because the bundle is used by APIs/SPAs and a status code alone is a poor client contract.

</specifics>

<deferred>
## Deferred Ideas

- **Per-tenant maintenance metadata** — a per-tenant message ("back at 5pm"), per-tenant `Retry-After`, and/or a `?DateTimeImmutable $inMaintenanceUntil` auto-expiry timestamp. Considered in "State richness" and rejected for v0.5 to keep the BC break to one bool method. A future non-breaking trait extension if demand appears. (An app can already render a custom message today via the D-02 Twig override reading `$tenant`.)
- **Per-tenant custom 503 template selection** — a template path stored per tenant. Deferred; the global `tenancy.maintenance.template` (D-02) covers the common case.
- **Per-tenant allow-lists** — rejected in favor of the global operator allow-list (D-06).
- **Global / site-wide (all-tenants) maintenance mode** and **variadic / `--all` enable** — already Out of Scope in REQUIREMENTS.md (web-server/LB concern; v0.6 by demand). `--all` edges toward it, so single-slug only (D-09).

None of the above are scope creep into Phase 32 — discussion stayed within the MAINT-01..09 boundary.

</deferred>

---

*Phase: 32-maintenance-mode*
*Context gathered: 2026-06-30*
