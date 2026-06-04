# Phase 25: Shared Entities (Sync mode) - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-06-04
**Phase:** 25-shared-entities-sync-mode
**Areas discussed:** Fan-out failure semantics, Write-protection scope, shared_db driver behavior, Mutual-exclusion guard timing

Framing note: SHARE-01 is heavily pre-specified by REQUIREMENTS.md acceptance criteria and the locked DEC-SHARE-01/02/03 decisions. The discussion targeted only the four genuinely-open implementation decisions. The user selected all four areas, then chose the recommended default for each.

---

## Fan-out failure semantics

| Option | Description | Selected |
|--------|-------------|----------|
| Best-effort + log drift | Apply to every reachable tenant; catch+log per-tenant failures; landlord request succeeds; drift repaired via Phase 26 resync | ✓ |
| Fail-fast (rethrow) | Rethrow first tenant failure; partial sync + exception to caller; no auto-repair | |
| All-or-nothing | Span landlord + all tenant writes in one tx boundary; often impossible across separate DBs | |

**User's choice:** Best-effort + log drift
**Notes:** postFlush is post-commit, so the landlord write cannot be rolled back — best-effort is the only consistent option that keeps the landlord request reliable. Becomes D-01; makes the Phase 26 resync command the official drift-repair path (D-07).

---

## Write-protection scope

| Option | Description | Selected |
|--------|-------------|----------|
| All mutations: insert+update+delete | Full read-only mirror; any tenant-context write throws SharedEntityWriteInTenantContextException | ✓ |
| persist() only (literal acceptance) | Block new inserts only; allows updates/deletes of synced copies | |

**User's choice:** All mutations (insert+update+delete)
**Notes:** Broader than the literal acceptance wording. Allowing tenant-side updates/deletes would let a mirror silently diverge from the landlord master — the data-integrity bug class the feature prevents. Becomes D-02.

---

## shared_db driver behavior

| Option | Description | Selected |
|--------|-------------|----------|
| Documented no-op | Subscriber short-circuits when driver=shared_db (entity lives once; nothing to fan out); document explicitly | ✓ |
| Compile-time guard rejecting #[Shared] under shared_db | Treat #[Shared] as database_per_tenant-only; fail at compile under shared_db | |

**User's choice:** Documented no-op
**Notes:** Mirrors SHARE-02's stated shared_db behavior; a harmless no-op is less surprising for shared_db users than a hard rejection. Becomes D-03.

---

## Mutual-exclusion guard timing

| Option | Description | Selected |
|--------|-------------|----------|
| Container compiler-pass guard now | Scan Doctrine metadata at compile; throw on #[Shared]+#[TenantAware]; mirrors existing ContractPasses; PHPStan (Phase 28) adds editor-time on top | ✓ |
| Runtime guard in subscriber | Subscriber throws on both-attribute entity during fan-out; weaker + later | |
| Defer entirely to Phase 28 PHPStan | No Phase 25 guard; rely on future static rule | |

**User's choice:** Container compiler-pass guard now
**Notes:** Fails loud at boot rather than waiting for Phase 28; consistent with the bundle's FilesystemContractPass/MailerTransportContractPass/CacheDecoratorContractPass convention. Belt-and-suspenders with the Phase 28 PHPStan rule. Becomes D-04.

## Claude's Discretion

- Change-capture mechanics (buffer changesets in `onFlush` → apply in `postFlush`), tenant-EM switching/`merge()` semantics, and logger wiring — left to research + planning.

## Deferred Ideas

- `tenancy:shared:resync` command → Phase 26 (SHARE-02)
- Async Messenger fan-out → Phase 27 (SHARE-03)
- PHPStan correctness rule for `#[Shared]`/`#[TenantAware]` → Phase 28 (DX-03)
- Cross-tenant aggregation queries, read-replica routing for `#[Shared]` → explicit non-goals (≥ v0.5)
