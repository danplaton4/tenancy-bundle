# Phase 25: Shared Entities (Sync mode) - Context

**Gathered:** 2026-06-04
**Status:** Ready for planning

<domain>
## Phase Boundary

Deliver **SHARE-01**: a `#[Shared]` PHP attribute plus a `SharedEntitySyncSubscriber` that listens on the **landlord EntityManager's** `Doctrine\ORM\Events::postFlush`. When a `#[Shared]`-attributed entity is written on the landlord EM, the subscriber fans the change out to each active tenant's EM (enumerated via `TenantProviderInterface::findAll()`), creating a read-only denormalized copy. Mode is **synchronous** (immediate, blocking fan-out). Tenant-side copies are write-protected via `SharedEntityWriteInTenantContextException extends \LogicException`. Cascade depth is limited to one level.

**In scope:** the `#[Shared]` attribute, the landlord-side sync subscriber, tenant-side write protection, the mutual-exclusion compile-time guard, and synchronous fan-out semantics.

**Out of scope (own phases):** the `tenancy:shared:resync` command (Phase 26 / SHARE-02), async Messenger fan-out (Phase 27 / SHARE-03), the PHPStan correctness rule (Phase 28 / DX-03), docs page (Phase 29 / DOC-20).

</domain>

<decisions>
## Implementation Decisions

### Sync fan-out failure semantics
- **D-01:** **Best-effort fan-out.** The landlord transaction is already COMMITTED by the time `postFlush` fires, so the landlord write can never be rolled back. The subscriber applies the change to every reachable tenant EM; per-tenant failures (tenant DB down, constraint violation) are caught and logged (PSR-3), never rethrown to abort the landlord request. Accumulated failures surface as log warnings. Drift is repaired out-of-band by `tenancy:shared:resync` (Phase 26). Explicitly NOT all-or-nothing, NOT fail-fast.

### Tenant-side write protection
- **D-02:** **Full read-only enforcement.** Any attempt to insert (persist), update (dirty managed copy), OR delete a `#[Shared]` entity while a tenant is active throws `SharedEntityWriteInTenantContextException extends \LogicException`. NOT limited to `persist()` (broader than the literal acceptance line) — allowing tenant-side updates/deletes would let a mirror silently diverge from the landlord master, which is exactly the data-integrity bug class this feature prevents. Enforced via a tenant-EM `onFlush` guard inspecting scheduled insertions/updates/deletions.

### shared_db driver behavior
- **D-03:** **Documented no-op under `shared_db`.** When `tenancy.driver = shared_db` there are no per-tenant EMs to fan out to — a `#[Shared]` entity lives once in the single shared database. The subscriber short-circuits (no work) when the driver is `shared_db`. Document this explicitly in the user guide. Consistent with SHARE-02's stated shared_db behavior. (Chosen over a compile-time rejection of `#[Shared]` under `shared_db` — a harmless no-op is less surprising for shared_db users.)

### Mutual-exclusion guard (DEC-SHARE-03)
- **D-04:** **Ship a container compiler-pass guard in Phase 25.** Scan Doctrine metadata at container-compile time; throw if any class carries both `#[Shared]` and `#[TenantAware]` (mutually exclusive — an entity is EITHER a landlord master OR tenant-scoped, never both). Mirrors the bundle's existing ContractPass convention (`FilesystemContractPass`, `MailerTransportContractPass`, `CacheDecoratorContractPass`). Phase 28 (DX-03) PHPStan rule adds editor-time detection ON TOP — belt-and-suspenders, not a replacement. Fails loud at boot instead of waiting for Phase 28.

### Derived implications (consequences of the above — no separate decision needed)
- **D-05:** Sync covers **insert / update / delete**. A landlord delete of a `#[Shared]` master propagates as a tenant-side delete. (Follows from best-effort fan-out + the full read-only model, and matches the SHARE-03 message carrying "change type insert/update/delete".)
- **D-06:** `#[Shared]` is a **bare class-target marker attribute** (no constructor params), mirroring `src/Attribute/TenantAware.php`. Per-tenant opt-out / selective sharing is out of scope for this phase.
- **D-07:** Best-effort failure handling makes the Phase 26 `tenancy:shared:resync` command the **official drift-repair mechanism**. Phase 25 logging MUST be actionable for resync/diagnosis: include tenant slug + entity class + identifier + the failure on each caught per-tenant error.

### Claude's Discretion
- HOW changes are captured (the standard pattern: buffer changesets in `onFlush` since `postFlush` no longer has them, then apply in `postFlush`), the exact tenant-EM switching/`merge()` mechanics, and logger service wiring are left to research + planning.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Requirement + locked decisions
- `.planning/REQUIREMENTS.md` §SHARE-01 — full acceptance criteria for the shared-entity sync (postFlush subscriber, `findAll()` fan-out, write-protection exception, one-level cascade, dry-run note).
- `.planning/REQUIREMENTS.md` Key Decisions: **DEC-SHARE-01** (sync default), **DEC-SHARE-02** (one-level cascade), **DEC-SHARE-03** (mutual exclusion = compile-time error), **DEC-PHPSTAN-01** (extension-installer distribution, relevant for Phase 28 hand-off).
- `.planning/ROADMAP.md` — Phase 25 scope line + "Tentative architectural defaults" (DEC-SHARE-01/02/03) + v0.4 milestone framing.

### Adjacent-phase boundaries (do NOT pull into Phase 25)
- `.planning/REQUIREMENTS.md` §SHARE-02 (Phase 26 — `tenancy:shared:resync`), §SHARE-03 (Phase 27 — async), §DX-03 (Phase 28 — PHPStan rule). These define where Phase 25's responsibility ends.

No external (non-`.planning`) specs or ADRs — requirements are fully captured in `.planning/REQUIREMENTS.md` and the decisions above.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `src/Attribute/TenantAware.php` — bare `#[\Attribute(\Attribute::TARGET_CLASS)] final class` marker. The new `src/Attribute/Shared.php` mirrors this exactly (D-06).
- `src/Provider/TenantProviderInterface.php::findAll()` (impl `src/Provider/DoctrineTenantProvider.php`) — enumerates all tenants from the landlord; the fan-out loop's tenant source (locked by SHARE-01 acceptance).
- `src/Exception/MissingFilesystemConfigException.php`, `src/Exception/MissingTenantProviderException.php` — established `extends \LogicException` pattern + static factory style; model for `SharedEntityWriteInTenantContextException`.
- `src/DependencyInjection/Compiler/FilesystemContractPass.php` (also `MailerTransportContractPass.php`, `CacheDecoratorContractPass.php`) — the compiler-pass-with-compile-time-guard convention to mirror for D-04.

### Established Patterns
- Driver mode is exposed as the `tenancy.driver` container parameter with values `database_per_tenant` | `shared_db` (a driver-enum validator was just added in Phase 24's `TenancyBundle::configure()`). The shared_db no-op short-circuit (D-03) reads this.
- Landlord EM vs tenant EM split comes from Phase 3 (database-per-tenant driver: `src/DBAL/TenantDriverMiddleware.php`, `TenantAwareDriver.php`) and Phase 5 (`DoctrineBootstrapper` does `clear()`/`resetManager` on tenant switch). The subscriber attaches to the landlord EM; fan-out switches tenant context to reach each tenant EM.
- Optional Doctrine dependency: guard all Doctrine event wiring + the compiler pass with `class_exists`/`interface_exists` (project convention — Doctrine is an optional dep).

### Integration Points
- **New territory:** the bundle has NO existing Doctrine `postFlush`/`onFlush` event subscriber yet (closest analog is the event-LISTENER pattern in `src/Filesystem/TenantContextClearedListener.php` / `src/EventListener/TenantContextOrchestrator.php`). Both the landlord sync subscriber and the tenant-side write-protection guard are new Doctrine event subscribers.
- Register the mutual-exclusion compiler pass in `TenancyBundle::build()` guarded by `class_exists(Doctrine\ORM\...)`, alongside the existing ContractPass registrations.
- Tenant-side write-protection guard (D-02) is a second subscriber on the tenant EM's `onFlush`, throwing when a `#[Shared]` entity is in the scheduled insert/update/delete sets while a tenant is active.

</code_context>

<specifics>
## Specific Ideas

No idiosyncratic "I want it like X" references — the user selected all recommended defaults. The strongest steer is the project's standing security posture: a cross-tenant data leak is a security incident, which is why write protection is full read-only (D-02) and the mutual-exclusion guard fails loud at boot (D-04).

</specifics>

<deferred>
## Deferred Ideas

- **`tenancy:shared:resync` command** — Phase 26 (SHARE-02). The official drift-repair tool for the best-effort failures from D-01/D-07.
- **Async fan-out via Messenger** (`tenancy.shared.async: true`, `SharedEntityChangedMessage`) — Phase 27 (SHARE-03).
- **PHPStan rule** for `#[Shared]`/`#[TenantAware]` correctness + cross-tenant-query leak detection — Phase 28 (DX-03). Phase 25's compiler-pass guard is the runtime/boot-time half of this.
- **Cross-tenant aggregation queries** and **read-replica routing for `#[Shared]`** — explicit non-goals (`.planning/REQUIREMENTS.md` Out-of-Scope table; deferred to ≥ v0.5).

None of the above are scope creep into Phase 25 — discussion stayed within the SHARE-01 boundary.

</deferred>

---

*Phase: 25-shared-entities-sync-mode*
*Context gathered: 2026-06-04*
