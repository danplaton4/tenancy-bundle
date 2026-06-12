# Phase 26: tenancy:shared:resync command - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-06-12
**Phase:** 26-tenancy-shared-resync-command
**Areas discussed:** CLI signature & tenant selection, Upsert mechanism (merge() conflict), --dry-run depth, Live-run safety / confirmation

---

## CLI signature & tenant selection

| Option | Description | Selected |
|--------|-------------|----------|
| Mirror tenancy:migrate | `--tenant=<slug>` option (VALUE_OPTIONAL); absent = all tenants. Identical to tenancy:migrate; lowest surprise, code reuse; mass-write risk handled by the confirmation prompt. | ✓ |
| Require explicit --all | `--tenant=<slug>` + separate `--all` flag required to hit everything; no-arg errors/usage. Safer, but diverges from migrate. | |
| Positional <SlugOrAll> | Single positional arg (slug or 'all') — the SHARE-01 acceptance-line form. More compact, inconsistent with migrate. | |

**User's choice:** Mirror tenancy:migrate
**Notes:** Resolves the two conflicting CLI signatures in the requirements in favor of the existing convention. Safety against accidental full resync is delegated to the confirmation prompt (see Live-run safety below), not to an explicit `--all`.

---

## Upsert mechanism (merge() conflict)

| Option | Description | Selected |
|--------|-------------|----------|
| Extract & reuse Phase 25 logic | Pull SharedEntitySyncSubscriber::doSync() into a shared service both the subscriber and command call. Single source of truth; byte-identical copies; no ORM-3-incompatible merge(). Small refactor of shipped Phase 25 code. | ✓ |
| Duplicate logic in command | Re-implement find-or-new + scalar-copy + PK-preservation in the command; no refactor risk, but two copies that can drift. | |
| Literal merge() | Follow requirement text and use Doctrine merge(). merge() removed in ORM 3.0 (bundle-supported) — would break ORM 3 users. Not recommended. | |

**User's choice:** Extract & reuse Phase 25 logic
**Notes:** Explicitly supersedes the "uses merge() semantics" wording in REQUIREMENTS §SHARE-02. "Idempotent" intent is preserved — find-or-new is an idempotent upsert. Phase 25's SHARE-01 test suite must remain green after the extraction refactor.

---

## --dry-run depth

| Option | Description | Selected |
|--------|-------------|----------|
| Real drift detection | Read each tenant's copies and classify every shared row as would-insert / would-update / in-sync; per-tenant breakdown. Genuine diagnostic + drift-repair tool. Touches every tenant DB. | ✓ |
| Count-only plan | Print 'N entities × M tenants = X writes' from landlord counts × tenant count, no tenant reads. Cheap but always reports the maximum; no real signal. | |

**User's choice:** Real drift detection
**Notes:** Because the upsert is find-or-new, a live run rewrites every row regardless of change, so a raw write count is always N×M and non-diagnostic. The shared copier service must expose a read-only classify/diff capability for both dry-run and the pre-execution summary.

---

## Live-run safety / confirmation

| Option | Description | Selected |
|--------|-------------|----------|
| Confirm before executing | Print drift summary, then prompt 'Proceed? [y/N]' (SymfonyStyle::confirm, default No); --force skips for CI/non-interactive. Matches the area-1 safety expectation. | ✓ |
| Execute immediately | Print plan then run, no prompt — exactly like tenancy:migrate. Scriptable by default, but no last-chance guard before a large cross-tenant write. | |

**User's choice:** Confirm before executing
**Notes:** Under `--no-interaction` with default-No, confirm() aborts; the explicit unattended-run signal is `--force` (do NOT rely on `-n` alone to proceed). This is the safety mechanism that justified keeping the migrate-identical CLI shape (no explicit `--all`).

---

## Claude's Discretion

- Exact service name/namespace for the extracted copier (working name `SharedEntityCopier`) and its method surface (classify()/apply() split).
- The precise mechanism for setting/clearing the `syncInProgress` write-protection bypass from the command path.
- Output table formatting, progress cadence for large tenant counts, structured-log keys (reuse Phase 25's shape).
- Whether `--dry-run` additionally flags orphaned tenant copies as a read-only diagnostic (must not delete them).

## Deferred Ideas

- Orphan-copy deletion / full reconciliation (incl. deletes) — command is additive/upsert-only per SHARE-02; a true reconcile mode would be a future requirement.
- Async / batched resync for very large tenant counts — relates to Phase 27 (SHARE-03); synchronous loop is acceptable for v0.4.
