# Phase 27: Async Shared Entities - Context

**Gathered:** 2026-06-15
**Status:** Ready for planning

<domain>
## Phase Boundary

Deliver **SHARE-03**: an opt-in `tenancy.shared.async: true` mode in which `SharedEntitySyncSubscriber` **dispatches a lightweight `SharedEntityChangedMessage`** (entity class + identifier + change type) on `postFlush` **instead of** writing synchronously to every tenant EM. A Symfony Messenger worker handles the per-tenant fan-out, so the landlord HTTP request returns immediately. The `onFlush` buffering stays identical to sync mode; only the `postFlush` action branches on the async flag.

**In scope:**
- The `tenancy.shared` config node with a `booleanNode('async')` (default `false`) → `tenancy.shared.async` container parameter.
- `SharedEntityChangedMessage` value object carrying entity class + identifier + change type (insert/update/delete) — **NOT** the full entity payload.
- Branching `SharedEntitySyncSubscriber::postFlush()` to dispatch messages when async is enabled (sync path unchanged when `false`).
- A message handler that re-fetches latest landlord state and fans out to all tenants via the existing `SharedEntityCopier`.
- A `MailerTransportContractPass`-style compile-time guard that fails when `async: true` && `symfony/messenger` is absent.
- An `AsyncCanaryTest`-style integration test proving the message survives a Messenger transport round-trip.

**Out of scope (own phases / explicit non-goals):**
- PHPStan correctness rule for `#[Shared]` / `#[TenantAware]` — Phase 28 (DX-03).
- Docs page for shared-entities async mode — Phase 29 (DOC-20) (this phase only ships inline PHPDoc + a deferred note for the docs page).
- Orphan-copy deletion / full reconciliation — deferred since Phase 26 (additive/upsert-only model). See D-04 for how this constrains the vanished-row handling.
- Auto-routing `SharedEntityChangedMessage` to a transport — see D-03 (document-only).

</domain>

<decisions>
## Implementation Decisions

### Fan-out topology
- **D-01 (user delegated → recommended default locked):** **One `SharedEntityChangedMessage` per changed `#[Shared]` entity; the handler runs the full per-tenant loop over ALL tenants.** The handler reuses Phase 25's proven `postFlush` mechanics (`switchToTenant()` + `SharedEntityCopier::applyRow()`) almost verbatim, just relocated from the subscriber into the handler. Dispatch stays trivial — `findAll()` is NOT called on the landlord request, so the HTTP response returns as fast as possible. **No `TenantStamp` is used** — the message is not tenant-scoped (it fans to all tenants), so the handler sets each tenant's context itself rather than relying on `TenantWorkerMiddleware`'s stamp-restore path. Matches the requirement's "the Messenger worker handles per-tenant fan-out" wording.
  - **Rejected:** one message per `(change × tenant)` carrying a `TenantStamp(slug)`. It would give finer Messenger retry isolation (retry just the failed tenant), but it forces `findAll()` onto the landlord request (partially defeating "return immediately") and produces `N entities × M tenants` messages. The best-effort + resync model (Phase 25 D-01 / D-07) already absorbs per-tenant drift, so per-tenant retry isolation is not worth that cost.

### Worker failure / retry semantics
- **D-02 (user delegated → recommended default locked):** **Best-effort attempt-all, then throw-to-retry on any failure.** The handler applies the change to every tenant best-effort (per-tenant `try/catch`, logging each failure with the Phase 25 structured keys `tenant_slug` / `entity_class` / `identifier` / `error`). After the loop, **if ANY tenant failed, it throws an aggregate exception** so Messenger retries the whole message per the transport's `retry_strategy`. Re-applying to already-synced tenants is safe because `SharedEntityCopier` is idempotent (find-or-new). After retries are exhausted, the message lands in the configured failure transport (dead-letter) for operator inspection; `tenancy:shared:resync` remains the manual backstop.
  - **Critical distinction (record for planner):** **Throwing in the worker does NOT violate Phase 25's D-01 "never throw."** D-01's no-throw rule existed to protect the landlord's **HTTP request** (the landlord transaction was already committed in `postFlush`; aborting it would be pointless and harmful). The async worker has **no HTTP request to protect** — it is fully decoupled — so leveraging Messenger's retry/backoff is the entire point of async mode and is consistent with the *intent* of D-01.
  - The retry count and backoff are the **user's transport `retry_strategy`**, not the bundle's to configure. The bundle's only responsibility is to throw (or not) so the transport's strategy can act.
  - **Rejected:** pure best-effort log-and-ack (mirror sync D-01 exactly, never throw). Maximally consistent with sync, but discards Messenger's built-in retry — every transient tenant-DB blip would become a manual `resync`.

### Transport routing
- **D-03 (user choice):** **Document-only.** The bundle ships `SharedEntityChangedMessage` + its handler; the user is responsible for mapping `SharedEntityChangedMessage` to their async transport in `framework.messenger.routing` — exactly as they would route `Symfony\Component\Mailer\Messenger\SendEmailMessage`. If the message is **not** routed to an async transport, it executes **synchronously on the message bus** (handler runs inline in the request) — still correct, just not deferred. This MUST be documented prominently (the "HTTP returns immediately" promise holds only when the user routes the message async). Mirrors the bundle's established non-intrusive Messenger posture — Phases 6 and 20 never force `framework.messenger.routing`.
  - **Rejected:** auto-prepending `framework.messenger.routing` for `SharedEntityChangedMessage`. It would fight the user's own Messenger config and require a transport-name config knob the bundle has no good default for.

### Delete & stale-state handling (the "latest state" landmine)
- **D-04 (user choice):** **Vanished row = delete.** The acceptance criterion locks "re-fetch the current entity state from the landlord EM at handle time." Two cases follow:
  1. **`type=delete`** — the message carries the identifier (captured in `onFlush` before Doctrine zeroes it, exactly as the sync subscriber already does). The handler deletes the tenant copies by id, reusing `SharedEntityCopier`'s existing delete-by-id path (Phase 25 D-05).
  2. **`type=insert`/`update` but the landlord row is GONE at handle time** (deleted between dispatch and worker execution) — the re-fetch returns nothing, so the handler **propagates a tenant-side delete**: the master row no longer exists, therefore the mirror must not either. This converges to the correct eventual-consistency end-state, and a separately-dispatched delete message becomes an idempotent no-op.
  - **Why delete and not no-op:** Phase 26 explicitly **deferred orphan-copy deletion** (`resync` is additive/upsert-only). A "no-op + log" choice would leave a now-orphaned tenant copy that **no existing tool can repair** — a permanent drift. Collapsing vanished-row inserts/updates into a delete here is the *only* repair path, so it must live in this handler.
  - Log every state-collapse (a re-fetch that turned an insert/update into a delete) at a level operators can grep, reusing the structured-log shape.

### Derived implications (consequences of the above + locked acceptance — no separate decision)
- **D-05:** Because the handler always **re-fetches the latest landlord state** at handle time, `insert` and `update` **collapse into a single "upsert-by-id from latest state"** operation in the handler. Only `delete` (including the vanished-row→delete case in D-04) is a distinct branch. The message's `type` field therefore primarily distinguishes **delete vs upsert** at the handler — it is NOT a literal replay of the original DML. (This is the explicit consequence of the requirement's "tenants see the LATEST state, not the state at dispatch time" landmine.)
- **D-06:** `tenancy.shared.async` is a plain **`booleanNode()->defaultFalse()`** — NOT the mailer's tri-state `'auto'/'true'/'false'` scalar. The mailer needed tri-state because it auto-detects `framework.messenger.routing` for `SendEmailMessage`; this phase has no routing to auto-detect (D-03 leaves routing to the user), so a straight opt-in boolean matches the requirement's literal `tenancy.shared.async: true` and is less surprising. The compile-time guard reads the resulting `tenancy.shared.async` parameter and throws a descriptive `\LogicException` at build time when it is `true` && `!interface_exists(Symfony\Component\Messenger\MessageBusInterface::class)`.
- **D-07:** The async subscriber needs a **`MessageBusInterface` dependency** — inject it as **nullable/optional** (`?MessageBusInterface`) so the subscriber is constructible when Messenger is absent and `async: false`; the guard (D-06) is what prevents the `async: true` + no-Messenger misconfiguration from ever reaching runtime. Wiring stays inside the existing `interface_exists(EntityManagerInterface)` + `database_per_tenant` block in `TenancyBundle::loadExtension()` (the subscriber is never registered under `shared_db`).

### Claude's Discretion
- The exact `SharedEntityChangedMessage` shape (property names, identifier representation — reuse the copier's existing `getIdentifierValues()` scalar-array shape), its namespace (`Tenancy\Bundle\Message\…` is the natural home), and whether the handler is a separate `…Handler` class or an `__invoke`able service.
- The precise mechanism for branching `postFlush()` between sync fan-out and async dispatch (e.g., a private `dispatchAsync()` vs `fanOutSync()` split), and how the handler reuses `switchToTenant()` (extract a shared helper vs duplicate the small method).
- The aggregate-exception type thrown in D-02 (a new `\RuntimeException` subclass vs reusing/wrapping `HandlerFailedException` semantics) and the exact log keys for the dead-letter path — reuse Phase 25's structured shape.
- Compile-time guard class name (`SharedAsyncContractPass` is a working name) and where it registers in `TenancyBundle::build()` (alongside `MailerTransportContractPass`, guarded by `interface_exists`).
- The canary test's kernel/transport plumbing — follow `tests/Integration/Mailer/AsyncCanaryTest.php`'s `sync://` + `PhpSerializer` round-trip pattern (no real broker).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Requirement + locked decisions
- `.planning/REQUIREMENTS.md` §SHARE-03 — full acceptance criteria (opt-in `tenancy.shared.async: true` w/ sync default; message carries class+identifier+change-type, NOT full payload; worker re-fetches latest state at handle time = documented landmine; `MailerTransportContractPass`-style guard for "async + no messenger"; integration test proving Messenger round-trip per the Phase 20 AsyncCanaryTest pattern).
- `.planning/REQUIREMENTS.md` Key Decisions: **DEC-SHARE-01** (sync is the default), **DEC-PHPSTAN-01** (Phase 28 hand-off context).
- `.planning/ROADMAP.md` line 87 — Phase 27 scope line + "Tentative architectural defaults" + v0.4 milestone framing.

### Direct dependencies — MUST read (this phase modifies/reuses their code)
- `.planning/phases/25-shared-entities-sync-mode/25-CONTEXT.md` — Phase 25 decisions. Especially **D-01** best-effort fan-out (the rule async re-interprets — see D-02 above), **D-05** insert/update/delete coverage, **D-07** resync = official drift-repair / actionable logging shape.
- `.planning/phases/26-tenancy-shared-resync-command/26-CONTEXT.md` — Phase 26 decisions. Especially **D-02** `SharedEntityCopier` is the single source of truth for apply/classify (the async handler MUST reuse it, never re-implement), and the **orphan-deletion deferral** (the constraint behind D-04's vanished=delete choice).

### Source files (read before implementing)
- `src/Subscriber/SharedEntitySyncSubscriber.php` — the dispatch point; `onFlush` buffering is reused as-is, `postFlush` branches on the async flag.
- `src/Shared/SharedEntityCopier.php` + `SharedEntityCopierInterface.php` — the apply/delete/classify engine the handler reuses.
- `src/DependencyInjection/Compiler/MailerTransportContractPass.php` — the compile-time-guard pattern to mirror (D-06).
- `src/Messenger/TenantStamp.php`, `TenantSendingMiddleware.php`, `TenantWorkerMiddleware.php`, `src/DependencyInjection/Compiler/MessengerMiddlewarePass.php` — Phase 6 Messenger integration (context; note D-01 intentionally does NOT use `TenantStamp`).
- `tests/Integration/Mailer/AsyncCanaryTest.php` — the canary round-trip test pattern to mirror for the SHARE-03 acceptance test.
- `src/TenancyBundle.php` — config tree (~L100-122 for the `mailer`/`filesystem` node pattern to mirror for `shared`), config→parameter flow (~L155-185), shared-entity service wiring (~L260-312), and compiler-pass registration in `build()` (~L328-344).

No external (non-`.planning`) specs or ADRs — requirements and prior-phase decisions are fully captured in the files above.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`SharedEntitySyncSubscriber::onFlush()`** — buffers `#[Shared]` changesets (incl. pre-captured delete identifiers, since Doctrine zeroes them before `postFlush`). Reused verbatim; only the `postFlush` action changes for async.
- **`SharedEntityCopier::applyRow()` / delete-by-id path / `isShared()`** — the idempotent upsert + delete engine. The async handler calls these exactly as the sync `postFlush` loop does. Idempotency (find-or-new) is what makes D-02's whole-message retry safe.
- **`SharedEntitySyncSubscriber::switchToTenant()` / `restoreTenantContext()`** — per-tenant context switch + teardown (close tenant DBAL connection, `resetManager('tenant')`). The handler needs the same mechanics.
- **`MailerTransportContractPass`** — exact template for the D-06 guard: `interface_exists` short-circuit, `hasParameter` check, descriptive `\LogicException` at build time.
- **`AsyncCanaryTest`** — exact template for the round-trip test: boot a kernel with a `sync://` transport, dispatch on the bus, let `PhpSerializer` encode→decode the envelope, assert the handler reached the tenant EMs (and never the wrong tenant).

### Established Patterns
- **Config → parameter:** array node in `TenancyBundle::configure()` → read in `loadExtension()` → `->set('tenancy.shared.async', $bool)`. Mirror the `mailer`/`filesystem` nodes.
- **Optional deps:** guard ALL Messenger wiring with `interface_exists(MessageBusInterface::class)` and ALL Doctrine wiring with `interface_exists(EntityManagerInterface::class)` (project convention — both are optional deps).
- **Doctrine subscriber wiring:** NO `#[AsEventListener]`/autoconfigure — registered via explicit `doctrine.event_listener` tags (connection: landlord) in `TenancyBundle::loadExtension()` (Pattern 7). The subscriber already follows this.
- **Structured PSR-3 logging keys:** `tenant_slug`, `entity_class`, `identifier`, `error` — reuse for async failures + state-collapse logs so they stay greppable across sync, resync, and async.
- **Subscriber registered only in `database_per_tenant`:** shared_db has no per-tenant EMs (config validator forbids `shared_db` + `database.enabled`), so the subscriber + handler never register under shared_db — the no-op is structural.

### Integration Points
- **`SharedEntitySyncSubscriber` gains a `?MessageBusInterface` constructor arg** (D-07, nullable for Messenger-absent construction). `postFlush` branches: `tenancy.shared.async` true → dispatch one `SharedEntityChangedMessage` per buffered change; false → existing sync fan-out.
- **New message + handler** registered (Messenger-guarded) in `TenancyBundle::loadExtension()` alongside the shared-entity services. Handler depends on the landlord EM, `TenantProviderInterface`, `SharedEntityCopier`, `TenantContext`, `ManagerRegistry`, `LoggerInterface` — same dependency set the subscriber's sync path uses.
- **New `tenancy.shared` config node** + `tenancy.shared.async` parameter in `TenancyBundle` (none exists today — Phases 25/26 added services but no config section).
- **New compile-time guard** registered in `TenancyBundle::build()` under `interface_exists(MailerInterface)`-style guarding (here: gate the *registration* so the pass can still assert when Messenger is missing but async is requested — mirror how `MailerTransportContractPass` is always added when Mailer is present; for SHARE-03 the pass must run whenever the bundle could be configured async, so register it unconditionally and let it short-circuit, OR register it and check the parameter — planner to confirm against the MailerTransportContractPass registration condition).

</code_context>

<specifics>
## Specific Ideas

No idiosyncratic "I want it like X" references. For the two consequential, genuinely-async decisions (topology D-01, retry D-02) the user explicitly **delegated to the recommended default** ("you decide"), signalling trust in the bundle-consistency rationale. For the two with a clear correctness pull (routing D-03, vanished-row D-04) the user picked the option that preserves the bundle's non-intrusive Messenger posture and guarantees eventual convergence given Phase 26's orphan-deletion deferral. The consistent steer across all four: **reuse Phase 25/26 internals, mirror the Phase 20 mailer-async precedent, and keep the async path a thin branch over the proven sync machinery.**

</specifics>

<deferred>
## Deferred Ideas

- **PHPStan rule** for `#[Shared]`/`#[TenantAware]` correctness + cross-tenant-query leak detection — Phase 28 (DX-03).
- **Docs page** for shared-entities (sync + async modes), incl. the "route `SharedEntityChangedMessage` async yourself" guidance from D-03 and the "tenants see latest state, not dispatch-time state" landmine from D-05 — Phase 29 (DOC-20). This phase ships inline PHPDoc only.
- **Orphan-copy deletion / full reconciliation** in `tenancy:shared:resync` — still deferred (Phase 26). D-04's vanished=delete is a *runtime* convergence path, NOT a batch reconcile; a true reconcile mode remains future work if delete-driven drift proves operationally real.
- **Per-tenant async retry isolation** (one message per tenant w/ `TenantStamp`) — rejected as D-01's alternative; revisit only if a future need for per-tenant dead-lettering emerges.

None of the above are scope creep into Phase 27 — discussion stayed within the SHARE-03 boundary.

</deferred>

---

*Phase: 27-async-shared-entities*
*Context gathered: 2026-06-15*
