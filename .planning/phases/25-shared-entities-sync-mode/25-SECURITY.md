---
phase: 25
slug: shared-entities-sync-mode
status: verified
threats_open: 0
asvs_level: 1
created: 2026-06-12
---

# Phase 25 — Security

> Per-phase security contract: threat register, accepted risks, and audit trail.
> SHARE-01 Shared Entities (Sync mode) — landlord-to-tenant `#[Shared]` fan-out.

---

## Trust Boundaries

| Boundary | Description | Data Crossing |
|----------|-------------|---------------|
| tenant EM flush → tenant DB | User code in tenant context must NOT mutate a `#[Shared]` mirror | tenant-originated write to a shared mirror (rejected) |
| landlord `postFlush` → per-tenant EMs | Fan-out crosses into per-tenant databases; only scalar fields may cross | `#[Shared]` entity scalar fields (denormalized copy) |
| sync write vs user write | Re-entrancy flag distinguishes the subscriber's own flush from a user flush | control-flow boundary (no data) |

---

## Threat Register

| Threat ID | Category | Component | Disposition | Mitigation | Status |
|-----------|----------|-----------|-------------|------------|--------|
| T-25-01 | Tampering | tenant-side write of `#[Shared]` entity corrupting landlord master | mitigate | `SharedEntityWriteProtectionListener::onFlush()` throws `SharedEntityWriteInTenantContextException::forEntity()` on any `#[Shared]` entity in scheduled inserts/updates/deletions when a tenant is active (guard order: `hasTenant()` → `isSyncInProgress()` → scheduled-set inspection). Pinned by `testTenantSide{Persist,Update,Delete}Throws` (3 tests, pass). | closed |
| T-25-02 | Information Disclosure | cross-tenant data leak via association copy | mitigate | `SharedEntitySyncSubscriber::doSync()` copies `getFieldNames()` scalars only; `getAssociationNames()` and `merge()` are absent (DEC-SHARE-02 one-level cascade boundary). Pinned by `testAssociationsNotSynced` (7 assertions, pass). | closed |
| T-25-03 | Elevation of Privilege | stale `TenantContext` after fan-out leaks tenant context into the surrounding landlord request | mitigate | **Mitigation mechanism revised during code review (CR-01/CR-02, see `25-REVIEW.md`).** `postFlush()` snapshots `$previousTenant` before the fan-out loop, wraps the loop in `try/finally`, and calls `restoreTenantContext()` in the `finally` — re-setting the original tenant (or `clear()` if none), then closing the DBAL connection + resetting the tenant manager so the next query reconnects under the restored context. Context is left in exactly the pre-flush state regardless of fan-out count or failures. Pinned by `testFanOutRestoresActiveTenantContext` (5 assertions, pass). | closed |
| T-25-04 | Tampering | subscriber fires on wrong EM / sync write trips the write-protection guard | mitigate | (a) Re-entrancy: `$syncInProgress` set before `doSync()`, reset in `finally`; `SharedEntityWriteProtectionListener` bypasses when `isSyncInProgress()`. (b) Connection scoping: `tenancy.shared_entity_sync_subscriber` tagged `doctrine.event_listener connection: landlord` (onFlush+postFlush); `tenancy.shared_entity_write_protection` tagged `connection: tenant` (onFlush) in `TenancyBundle`, inside `database.enabled` + `interface_exists(EntityManagerInterface)` guards. Pinned by `testSyncWriteBypassesWriteProtection` + `testSubscriberWiredToLandlordEm`. | closed |
| T-25-SC | Tampering | supply chain (npm/pip/cargo installs) | n/a | No package installs introduced in this phase. | closed |

*Status: open · closed*
*Disposition: mitigate (implementation required) · accept (documented risk) · transfer (third-party)*

**Additional compile-time guard (DEC-SHARE-03):** `SharedEntityMutualExclusionPass` (registered in `TenancyBundle::build()`) throws `\LogicException` at container compile time when a `tenancy.shared_entity`-tagged class carries both `#[Shared]` and `#[TenantAware]`. Pinned by `testMutualExclusionGuardThrows`.

---

## Accepted Risks Log

| Risk ID | Threat Ref | Rationale | Accepted By | Date |
|---------|------------|-----------|-------------|------|

No accepted risks — all threats mitigated with verified implementation evidence.

---

## Advisory Findings (deferred, non-blocking)

Surfaced by the Phase 25 code review (`25-REVIEW.md`) and confirmed by the security audit. None block phase advancement — all declared threat mitigations are present in code. Recommended for follow-up (e.g. `/gsd:code-review 25 --fix` or a later phase):

| ID | Concern | Security relevance |
|----|---------|--------------------|
| WR-03 | `shared_db` no-op is structural (subscriber not registered under `shared_db`); the `postFlush` `driver === 'shared_db'` branch is unreachable dead code in the only config where it would trigger | Low — no-op is guaranteed by DI, not the runtime check |
| WR-04 | `isShared()` reflects `$entity::class` without resolving the real Doctrine class; an ORM 3 proxy subclass may not carry the attribute | Medium — a proxy could in principle bypass write-protection or skip fan-out; verify proxies expose the attribute |
| WR-05 | `$syncInProgress` is a global boolean, not scoped to the specific tenant EM | Low/Medium — a coincidental overlapping flush during a sync window could bypass write protection |
| WR-06 | `SharedEntityMutualExclusionPass` inspects only the class itself, not mapped-superclass/parent declarations | Low — `#[Shared]`+`#[TenantAware]` on a parent class would not be detected at compile time |
| IN-04 | `testPerTenantFailureIsLogged` asserts `>= 0` (always true) — best-effort isolation + PSR-3 structured logging (D-01/D-07) not meaningfully exercised | Low — test-quality gap, not a code defect |

---

## Security Audit Trail

| Audit Date | Threats Total | Closed | Open | Run By |
|------------|---------------|--------|------|--------|
| 2026-06-12 | 5 | 5 | 0 | gsd-security-auditor |

Note: 3 Critical tenant-isolation findings (CR-01/CR-02/CR-03) were caught by the Phase 25 code review and fixed in commit `ce93132` before this audit; T-25-03's mitigation mechanism was revised as a result. See `25-REVIEW.md` Resolution Addendum.

---

## Sign-Off

- [x] All threats have a disposition (mitigate / accept / transfer)
- [x] Accepted risks documented in Accepted Risks Log
- [x] `threats_open: 0` confirmed
- [x] `status: verified` set in frontmatter

**Approval:** verified 2026-06-12
