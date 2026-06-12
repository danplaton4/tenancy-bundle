# Phase 26: tenancy:shared:resync command - Context

**Gathered:** 2026-06-12
**Status:** Ready for planning

<domain>
## Phase Boundary

Deliver **SHARE-02**: a `tenancy:shared:resync` console command that performs the **bulk / initial sync** of all `#[Shared]` entities from the landlord EM into the target tenant(s)' EMs. It is the **official drift-repair tool** for Phase 25's best-effort runtime fan-out (D-07 from Phase 25): when a runtime per-tenant sync failed, or when `#[Shared]` was added to an entity that already has landlord rows, or when a new tenant is onboarded, this command back-fills/re-aligns each tenant's read-only copies.

**In scope:**
- The `tenancy:shared:resync` command with a `--tenant=<slug>` option (absent = all tenants), `--dry-run`, and `--force`.
- Enumerating all `#[Shared]` entity classes via landlord Doctrine metadata and walking their rows.
- Idempotent upsert into each tenant via a shared copier service extracted from Phase 25.
- Drift detection for `--dry-run` and the pre-execution summary.
- Continue-on-failure loop with a per-tenant pass/fail summary (mirroring `tenancy:migrate`).
- `shared_db` documented no-op.

**Out of scope (own phases):** async Messenger fan-out (Phase 27 / SHARE-03), the PHPStan correctness rule (Phase 28 / DX-03), the user-guide docs page (Phase 29 / DOC-20), orphan-copy *deletion* / full reconciliation (the command is additive/upsert-only — see Deferred Ideas).

</domain>

<decisions>
## Implementation Decisions

### CLI signature & tenant selection
- **D-01:** **Mirror `tenancy:migrate` exactly.** A single `--tenant=<slug>` option (`InputOption::VALUE_OPTIONAL`); when absent, the command targets **all** tenants via `TenantProviderInterface::findAll()`. No positional `<SlugOrAll>` argument, no separate `--all` flag. Resolves the two conflicting signatures in the requirements (the positional `<SlugOrAll>` form in SHARE-01's acceptance line and the `[--tenant=<slug>|--all]` form in SHARE-02) in favor of the established `tenancy:migrate` convention — lowest surprise, maximal code reuse. The accidental-mass-write risk is handled by the confirmation prompt (D-04), not by an explicit `--all`.

### Upsert mechanism (resolves the merge() conflict)
- **D-02:** **Extract Phase 25's upsert logic into a shared service and reuse it.** Pull `SharedEntitySyncSubscriber::doSync()` (find-or-new + scalar-field copy + the `GENERATOR_TYPE_NONE` PK-preservation trick + delete handling) into a new shared service (working name `SharedEntityCopier`) that BOTH the existing subscriber and the new command call. **Single source of truth** — resync-produced copies are byte-identical to runtime-sync copies; the subtle PK-preservation and scalar-only-copy logic is never duplicated and cannot drift.
  - **This explicitly supersedes the literal "uses `merge()` semantics" wording in REQUIREMENTS §SHARE-02.** `Doctrine\ORM\EntityManager::merge()` was **removed in ORM 3.0** (which the bundle supports), and Phase 25 deliberately avoided it. "Idempotent" is preserved — find-or-new IS an idempotent upsert (re-running produces no duplicates and the same final state).
  - Refactor scope: this touches just-shipped Phase 25 code (`SharedEntitySyncSubscriber`). Acceptable and intended — the subscriber becomes a thin caller of the extracted copier. Phase 25's full SHARE-01 test suite MUST stay green after extraction.

### --dry-run depth
- **D-03:** **Real drift detection, not a count.** For each target tenant, read the existing tenant-side copies and classify every shared row as **would-insert** (missing on tenant) / **would-update** (present but scalar fields differ from landlord = drift) / **in-sync** (identical). Print a per-tenant breakdown. This makes `--dry-run` a genuine *diagnostic + drift-repair* tool per SHARE-01's framing, not just the `N × M` multiplier.
  - Rationale captured for the planner: because the upsert is find-or-new (D-02), a **live** run rewrites every shared row on every tenant regardless of whether it changed — so a raw "X writes" figure is always `N entities × M tenants` and carries no diagnostic signal. The value is in the insert/update/in-sync classification.
  - Consequence: the extracted `SharedEntityCopier` (D-02) MUST expose a **read-only classify/diff** capability (compare landlord row vs tenant copy, return insert/update/in-sync) in addition to its apply capability. Dry-run uses classify-only (no flush); live run uses classify-then-apply.

### Live-run safety / confirmation
- **D-04:** **Confirm before executing a live run.** A non-dry-run invocation first computes and prints the drift summary (same computation as `--dry-run`, D-03), then prompts `Proceed? [y/N]` via `SymfonyStyle::confirm()` with **default No**. A `--force` flag skips the prompt for CI / non-interactive use.
  - Non-interactive semantics: under `--no-interaction` (`-n`) with default-No, `confirm()` returns No and the command aborts cleanly — the explicit "yes, run it unattended" signal is `--force`. (Note for planner: do NOT rely on `-n` alone to proceed; require `--force`.)
  - This is the safety mechanism deferred from D-01 — it guards the accidental full resync that an explicit `--all` flag would otherwise guard, while keeping the `tenancy:migrate`-identical CLI shape.

### Derived implications (consequences of the above + locked acceptance — no separate decision needed)
- **D-05:** **`shared_db` = informational no-op exiting `Command::SUCCESS`.** Under `tenancy.driver = shared_db` there are no per-tenant EMs — `#[Shared]` entities live once in the single shared DB. The command prints a clear "no-op under shared_db" message and exits **SUCCESS** (NOT `FAILURE` like `tenancy:migrate`'s shared_db guard does), because SHARE-02 explicitly calls this a *documented no-op*, not an error condition. Reads the `tenancy.driver` container parameter, consistent with Phase 25 D-03.
- **D-06:** **Continue-on-failure + per-tenant summary, mirroring `tenancy:migrate`.** Per-tenant `try/catch/finally`; one tenant's failure (DB down, constraint violation) is caught, logged, and does NOT abort the loop. Emit `✓`/`✗` per tenant, a `Completed: N succeeded, M failed` line, and a failed-tenant list. Exit `Command::FAILURE` if **any** tenant failed, `SUCCESS` otherwise. `finally` clears `TenantContext` + `BootstrapperChain` per tenant exactly as the migrate command does.
- **D-07:** **`#[Shared]` class enumeration via landlord metadata.** Discover all `#[Shared]` entity classes by iterating the landlord EM's `ClassMetadataFactory::getAllMetadata()` and testing each class's `ClassMetadata::$reflClass` for the `#[Shared]` attribute — the **same `reflClass`-based attribute resolution** `SharedEntitySyncSubscriber::isShared()` uses (WR-01: resolve against the real mapped class, never `new ReflectionClass($entity)`, to survive Doctrine proxies). Then query each class's rows on the landlord and feed them through the copier.

### Claude's Discretion
- Exact service name/namespace for the extracted copier (`SharedEntityCopier` is a working name), its method surface (`classify()` / `apply()` split), and how the subscriber is refactored to call it.
- The precise mechanism by which the command sets/clears the `syncInProgress` re-entrancy bypass on the write-protection path (see Integration Points) — left to research + planning, consistent with Phase 25 leaving "the exact tenant-EM switching mechanics" to Claude's discretion.
- Output table formatting details (column layout of the drift breakdown), progress reporting cadence for large tenant counts, and logging keys (reuse Phase 25's `tenant_slug` / `entity_class` / `identifier` structured-log shape).
- Whether `--dry-run` additionally *flags* (read-only, informational) orphaned tenant copies (rows present on a tenant but no longer on the landlord) — nice-to-have diagnostic; it must NOT delete them (orphan deletion is deferred, see below).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Requirement + locked decisions
- `.planning/REQUIREMENTS.md` §SHARE-02 — full acceptance criteria (enumerate `#[Shared]` classes via metadata; report write plan before executing; respect `--dry-run`; works under both drivers with `shared_db` no-op; continue-on-failure matching `tenancy:migrate` with per-tenant pass/fail summary). **NOTE:** its "idempotent (uses `merge()` semantics, not `persist()`)" wording is **superseded by D-02** (merge() removed in ORM 3.0; find-or-new is the idempotent path).
- `.planning/REQUIREMENTS.md` §SHARE-01 acceptance line — defines `tenancy:shared:resync --dry-run` as the "diagnostic + repair of drift" tool (basis for D-03), and DEC-SHARE-01/02/03 key decisions.
- `.planning/ROADMAP.md` — Phase 26 scope line (line 79) + "Tentative architectural defaults" (DEC-SHARE-01/02/03) + v0.4 milestone framing.

### Direct dependency — Phase 25 (SHARE-01), MUST read
- `.planning/phases/25-shared-entities-sync-mode/25-CONTEXT.md` — Phase 25 decisions D-01..D-07. Especially: **D-01** best-effort fan-out (why resync exists), **D-02** full read-only tenant write protection (the guard this command must bypass), **D-03** shared_db no-op, **D-07** resync = official drift-repair mechanism + actionable logging shape.

No external (non-`.planning`) specs or ADRs — requirements and prior-phase decisions are fully captured in the files above plus the source files listed in code_context.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `src/Command/TenantMigrateCommand.php` — **the analog to mirror** for D-01 (`--tenant` `VALUE_OPTIONAL` option, `findBySlug` else `findAll`, empty-tenant-list short-circuit) and D-06 (per-tenant `try/catch/finally` with `TenantContext::clear()` + `BootstrapperChain::clear()`, `✓`/`✗` lines, `Completed: N succeeded, M failed` summary, FAILURE-if-any-failed). Also models the `shared_db` early guard (but resync returns SUCCESS, not FAILURE — D-05) and the `SymfonyStyle` usage.
- `src/Subscriber/SharedEntitySyncSubscriber.php::doSync()` (lines ~327-402) — **the upsert logic to extract** (D-02): find-or-new (`$tenantEm->find($class, $ids)`), scalar-only field copy via `getFieldNames()` (NEVER `getAssociationNames()` — one-level cascade boundary), and the **`GENERATOR_TYPE_NONE` PK-preservation trick** (CR-01: forces the copied landlord id to be authoritative so master/copy keys stay equal across DBs). Its `delete` branch and `isShared()` (`reflClass`-based, proxy-safe — WR-01) are equally reusable for D-07.
- `src/Provider/TenantProviderInterface.php` (`findAll()`, `findBySlug()`; impl `DoctrineTenantProvider.php`) — tenant enumeration source.
- `src/Context/TenantContext.php` + `src/Bootstrapper/BootstrapperChain.php` — per-tenant context switch/teardown, used exactly as in the migrate command.

### Established Patterns
- Command registration: `#[AsCommand(name: '...', description: '...')] final class … extends Command`, constructor DI, registered in `config/services.php`.
- Driver mode: `tenancy.driver` container parameter (`database_per_tenant` | `shared_db`) — D-05 reads this.
- Optional Doctrine dependency: guard all Doctrine-touching wiring with `class_exists`/`interface_exists` (project convention). The command + copier should only be registered when Doctrine ORM is present.
- Structured PSR-3 logging keys from Phase 25: `tenant_slug`, `entity_class`, `identifier`, `error` — reuse this shape for resync failures so logs are greppable across runtime sync and resync.

### Integration Points
- **CRITICAL LANDMINE — write-protection bypass:** the command writes `#[Shared]` entities INTO tenant EMs, which `src/Subscriber/SharedEntityWriteProtectionListener.php` BLOCKS (throws `SharedEntityWriteInTenantContextException`) unless the originator is the sync subscriber. Phase 25 gates this via `SharedEntitySyncSubscriber::isSyncInProgress()` (the `$syncInProgress` re-entrancy flag, set per-tenant-batch in `postFlush()`). The resync command's writes MUST trip the same bypass — most naturally by routing writes through the extracted `SharedEntityCopier` and having the copier own the `syncInProgress`-equivalent flag the write-protection listener consults. Get this wrong and every resync write throws.
- **Tenant EM switching:** reuse the subscriber's `switchToTenant()` mechanics (set `TenantContext`, `close()` the tenant DBAL connection so `TenantAwareDriver::connect()` reconnects with the new params, `resetManager('tenant')`) — and restore/teardown context per tenant. The migrate command does the equivalent via `BootstrapperChain::boot()`/`clear()`.
- **`#[Shared]` class discovery:** landlord EM `getMetadataFactory()->getAllMetadata()` filtered by the `reflClass` `#[Shared]` attribute check (D-07).
- New compiler-pass wiring is NOT expected — the command is a normal service; register it (Doctrine-guarded) in `config/services.php` alongside the other `tenancy:*` commands.

</code_context>

<specifics>
## Specific Ideas

No idiosyncratic "I want it like X" references — the user selected the recommended option for all four discussed areas. The consistent steer is **consistency with the existing bundle**: mirror `tenancy:migrate`'s CLI shape and failure model, reuse (not reinvent) Phase 25's proven sync internals, and add a confirmation guard because a bulk cross-tenant write is more consequential than an idempotent migration.

</specifics>

<deferred>
## Deferred Ideas

- **Orphan-copy deletion / full reconciliation** — deleting tenant-side copies whose landlord master no longer exists. The command is **additive/upsert-only** per SHARE-02's literal wording ("walks all `#[Shared]` entities on the landlord and writes them into the target tenant(s)"). `--dry-run` MAY *flag* orphans as a read-only diagnostic (Claude's discretion, D-04 note), but the live run never deletes. A true reconcile mode (incl. deletes) would be its own future requirement if drift-from-deletes proves to be a real operational problem.
- **Async / batched resync for very large tenant counts** — relates to Phase 27 (SHARE-03) async fan-out; out of scope here. The synchronous loop is acceptable for v0.4.

None of the above are scope creep into Phase 26 — discussion stayed within the SHARE-02 boundary.

</deferred>

---

*Phase: 26-tenancy-shared-resync-command*
*Context gathered: 2026-06-12*
