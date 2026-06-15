# Phase 27: Async Shared Entities - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-06-15
**Phase:** 27-async-shared-entities
**Areas discussed:** Fan-out topology, Failure & retry, Transport routing, Delete & stale state

---

## Fan-out topology

| Option | Description | Selected |
|--------|-------------|----------|
| One msg → all tenants | One `SharedEntityChangedMessage` per change; handler loops all tenants (reuse `postFlush` + `SharedEntityCopier`); no `TenantStamp`; dispatch trivial; HTTP returns fastest. | ✓ (via "You decide" → recommended) |
| One msg per tenant | N×M messages each carrying `TenantStamp`; one tenant per message; max retry isolation but `findAll()` on the request + high message volume. | |
| You decide | Delegate to research + planning. | ◀ user selected |

**User's choice:** "You decide" → Claude locked the recommended default (one message per change, worker loops all tenants).
**Notes:** Rationale: matches the requirement's "the worker handles per-tenant fan-out" wording; reuses Phase 25's proven loop; best-effort + resync already absorbs per-tenant drift, so per-tenant retry isolation isn't worth the dispatch-side cost (`findAll()` on request + N×M messages).

---

## Failure & retry

| Option | Description | Selected |
|--------|-------------|----------|
| Retry via Messenger | Best-effort attempt-all + log; throw aggregate if any failed → Messenger retries (idempotent re-apply); dead-letter after max retries; resync = manual backstop. | ✓ (via "You decide" → recommended) |
| Best-effort log-and-ack | Mirror sync D-01 exactly: catch per-tenant, log, always ack, never retry; resync is the only repair. | |
| You decide | Delegate to research + planning. | ◀ user selected |

**User's choice:** "You decide" → Claude locked the recommended default (throw-to-retry on any tenant failure).
**Notes:** Key distinction recorded: throwing in the worker does NOT violate D-01 — D-01's no-throw rule protected the landlord HTTP request, which the decoupled worker doesn't have. Retry count/backoff is the user's transport `retry_strategy`. Idempotent `SharedEntityCopier` makes whole-message retry safe.

---

## Transport routing

| Option | Description | Selected |
|--------|-------------|----------|
| Document-only | User maps `SharedEntityChangedMessage` in `framework.messenger.routing` (like `SendEmailMessage`); unrouted = sync-on-bus; document prominently. | ✓ |
| Auto-prepend routing | Bundle prepends routing config to a transport named in tenancy config. | |
| You decide | Delegate to research + planning. | |

**User's choice:** Document-only.
**Notes:** Mirrors the bundle's non-intrusive Messenger posture (Phases 6 & 20 never force routing). The "HTTP returns immediately" promise holds only when the user routes the message async — must be documented.

---

## Delete & stale state

| Option | Description | Selected |
|--------|-------------|----------|
| Vanished = delete | `type=delete` deletes tenant copy by carried id; insert/update with missing landlord row at handle time → propagate tenant-side delete (master gone → mirror shouldn't exist). Converges; log as state-collapse. | ✓ |
| No-op + log | Skip vanished-row inserts/updates, let resync reconcile. | |
| You decide | Delegate to research + planning. | |

**User's choice:** Vanished = delete.
**Notes:** Decisive factor: Phase 26 deferred orphan-copy deletion (resync is upsert-only), so "no-op + log" would leave un-repairable drift. Collapsing vanished-row inserts/updates into a delete here is the only repair path.

---

## Claude's Discretion

- **Topology (D-01)** and **failure/retry (D-02)** — user explicitly delegated ("You decide"); Claude locked the recommended defaults with rationale captured in CONTEXT.md.
- Within all decisions: message shape/namespace, handler class form, the `postFlush` sync/async branch mechanism, the aggregate-exception type, the guard class name, and the canary test plumbing — left to research + planning per CONTEXT.md "Claude's Discretion".

## Deferred Ideas

- PHPStan rule for `#[Shared]`/`#[TenantAware]` correctness — Phase 28 (DX-03).
- Docs page for shared-entities async mode (route-it-yourself guidance + latest-state landmine) — Phase 29 (DOC-20).
- Orphan-copy deletion / full reconciliation in `tenancy:shared:resync` — still deferred (Phase 26).
- Per-tenant async retry isolation (one message per tenant w/ `TenantStamp`) — rejected as D-01's alternative; revisit only if per-tenant dead-lettering is later needed.
