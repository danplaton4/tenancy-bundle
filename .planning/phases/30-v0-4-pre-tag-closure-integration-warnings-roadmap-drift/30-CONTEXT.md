# Phase 30: v0.4 Pre-Tag Closure (Integration Warnings + Roadmap Drift) - Context

**Gathered:** 2026-06-19
**Status:** Ready for planning

<domain>
## Phase Boundary

Close the five non-blocking tech-debt items the v0.4 milestone audit
(`.planning/v0.4-MILESTONE-AUDIT.md`, status `tech_debt`, **0 blockers**) surfaced, so v0.4
can be tagged clean. Scope is **fixed by the audit** — no new REQ-IDs, no new capabilities:

- **W-01** (SHARE-01) — testability seam: two sites type-hint the concrete `final SharedEntityCopier`.
- **W-02** (SHARE-03) — duplicated `switchToTenant()`/`restoreTenantContext()` across subscriber + handler.
- **W-03** (SHARE-02 ↔ SHARE-01) — asymmetric tenant-switch mechanisms (resync vs subscriber/handler).
- **WR-06/WR-07** — `docs/roadmap.md` drift (shipped v0.4 framed as "Next"; PHPStan extension mischaracterized).
- **WR-03** (folded in) — `scripts/docs-lint.sh` D-15 awk whitelist state leaks across files.

**Gate invariant:** the full PHPUnit suite, PHPStan L9 (`--memory-limit=512M` locally),
`php-cs-fixer`, and `scripts/docs-lint.sh` must all stay green.

</domain>

<decisions>
## Implementation Decisions

### W-02 — De-duplicate the tenant-switch logic
- **D-01:** Extract a **new injected helper service**, not a trait and not a keep-duplicated + drift-test.
  Ships as `TenantEmSwitcherInterface` + `final TenantEmSwitcher` (mock-a-final convention, mirrors
  `SharedEntityCopierInterface` / `TenantConnectionInterface`). The **interface** is injected into both
  `SharedEntitySyncSubscriber` and `SharedEntityChangedMessageHandler`.
- **D-02:** The service owns exactly the two methods that are byte-identical today:
  `switchTo(TenantInterface): EntityManagerInterface` (= current `switchToTenant()`:
  `setTenant()` → tenant DBAL `close()` → `registry->resetManager('tenant')`) and
  `restore(?TenantInterface): void` (= current `restoreTenantContext()`:
  set-or-clear context → tenant DBAL `close()` → `resetManager('tenant')`).
- **D-03:** Scope of the extraction is **subscriber + handler only.** Do NOT touch:
  the subscriber's **async-dispatch branch** in `postFlush()` (it does its own context clear/restore
  around `bus->dispatch()`, not via `switchToTenant()`), and the **resync command** (W-03 — different
  mechanism, deliberately stays out). New DI wiring goes in `config/services.php`.

### W-03 — Asymmetric tenant-switch mechanisms
- **D-04:** **Document the intentional asymmetry — do NOT reconcile.** Reconciling either direction was
  rejected: forcing `bootstrapperChain->boot()` into request/worker fan-out would fire `TenantBootstrapped`
  + every bootstrapper on *each* shared-entity change (perf + side-effects); stripping resync's boot would
  change its CLI-backfill semantics. Both touch proven code right before a tag.
- **D-05:** The documentation lives in two places: (1) the `TenantEmSwitcher` class docblock states it is the
  **lightweight per-change / per-message** switch path and explicitly contrasts with
  `SharedEntityResyncCommand`'s full `setTenant()+bootstrapperChain->boot()`; (2) a matching back-reference
  note on the resync command (near `:126-127`) points at the switcher and explains why resync stays on the
  heavier path. No behavior change.

### W-01 — Testability seam
- **D-06:** Change the two type-hints to the **interface**: `SharedEntitySyncSubscriber.php:97`
  (`private readonly SharedEntityCopier $copier` → `SharedEntityCopierInterface`) and
  `SharedEntityWriteProtectionListener.php:44` (same). The interface already exposes every method these
  classes call (`isShared()`, `applyRow()`, `isSyncInProgress()`) — no interface changes needed.
- **D-07:** **Add mock-injection unit test(s)** that actually exercise the new seam — proving the gap is
  closed, not just opened. Minimum: a `SharedEntityWriteProtectionListener` test injecting a mock copier to
  assert the **re-entrancy bypass** (`isSyncInProgress() === true` → no throw) and the throw-on-`#[Shared]`-write
  path. Where the subscriber/handler are unit-tested, inject both the mock copier and the W-02 mock switcher.

### WR-06 / WR-07 — docs/roadmap.md reconciliation
- **D-08:** **Fuller pass** — reconcile the whole file to reality, not just the two flagged lines:
  - Move v0.4 (Filesystem/Flysystem bootstrapper + shared-entity replication + PHPStan extension) **out of
    "Next" into "Shipped"** (WR-06). Use the locked D-07 vocabulary "**landlord-side master**" +
    "**tenant-side read-only copy**" for the shared-entity line.
  - **Fix the PHPStan line** (WR-07) to the three real rules: `tenancy.mutualExclusion`,
    `tenancy.sharedEntityLeak`, `tenancy.tenantIdDrift` (drop the "tenant-scoped repositories injected into
    shared services" mischaracterization).
  - **Clear the stale framing:** remove/replace the "In progress — closing v0.3 / Phase 22" block and the
    "v0.3 — partial / Latest tag v0.3.2" wording (v0.3 milestone shipped at **v0.3.3**).
  - **Set Next = v0.5** (Operations & scale), per the existing Planned table.
- **D-09:** The v0.4 Shipped entry **carries no tag number** and links the CHANGELOG instead — this commit
  lands *before* the v0.4 tag exists (pre-tag closure), so citing `v0.4.x` would be a forward-reference.
  Describe the work as landed.

### WR-03 — docs-lint awk fold-in
- **D-10:** **Fold it in.** Add a one-line `FNR==1 { in_whitelist=0 }` (state reset at each new file) to the
  D-15 `bundles.php` awk block in `scripts/docs-lint.sh` (~line 73-81). Today `in_whitelist` leaks across
  files: a `bundles.php` reference at the top of a file that *follows* a file ending inside a whitelisted
  section slips CI (false negative). Confirmed cheap; closes a Phase 29 deferred item.

### Claude's Discretion
- Exact service/interface names (`TenantEmSwitcher` / `TenantEmSwitcherInterface` are the proposed names),
  method signatures, file placement (`src/Shared/` vs `src/Tenant/` — match the closest existing analog),
  and DI service id are the planner/executor's call.
- Precise test file locations and how many test cases — as long as D-07's "exercise the seam" intent is met.
- Exact roadmap.md prose/section ordering, as long as D-08/D-09 content decisions hold.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Audit source of truth (read first)
- `.planning/v0.4-MILESTONE-AUDIT.md` — origin of every item in this phase. "Integration warnings"
  §W-01/W-02/W-03 carry exact file:line pointers; "Tech Debt Summary" lists WR-03/WR-06/WR-07.
- `.planning/ROADMAP.md` (Phase 30 entry, ~line 20-30) — the locked phase goal + scope statement.

### Shared-entity vocabulary (for the roadmap fix)
- `docs/user-guide/shared-entities.md` — canonical D-07 wording "landlord-side master" /
  "tenant-side read-only copy" + the one-level-cascade landmine. The roadmap shared-entity line must match.

### PHPStan rule IDs (for WR-07)
- `.planning/ROADMAP.md` Phase 28 goal — authoritative list of the 3 rule IDs
  (`tenancy.mutualExclusion` / `tenancy.sharedEntityLeak` / `tenancy.tenantIdDrift`).
- `docs/user-guide/phpstan-extension.md` (Phase 29 D-03) — install + per-rule docs if prose is needed.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`src/Shared/SharedEntityCopierInterface.php`** — already exists (Phase 25), already declares every
  method W-01's two sites call (`applyRow`, `classifyRow`, `findSharedClasses`, `isShared`, `deleteRow`,
  `isSyncInProgress`). W-01 is a pure type-hint swap; **no interface edits**.
- **`TenantConnectionInterface`** — the bundle's precedent for "extract an interface alongside a `final`
  class so PHPUnit 11 can mock it" (cited verbatim in `SharedEntityCopierInterface`'s docblock). Mirror this
  for `TenantEmSwitcherInterface`.
- **`SharedEntityChangedMessageHandler` already type-hints `SharedEntityCopierInterface`** and the resync
  command does too — W-01 just brings the last two sites in line with the rest of the codebase.

### Established Patterns
- **Doctrine event subscribers are wired manually** via `doctrine.event_listener` tags in
  `config/services.php` — NOT `#[AsEventListener]`/autoconfigure (documented in both subscriber docblocks).
  The new `TenantEmSwitcher` is a plain injected service, so its wiring is ordinary DI.
- **Phase 27 OQ-2 (now being reversed):** the handler's duplication was a *deliberate* call to keep Plan
  27-02 file-scoped and avoid touching the subscriber's proven CR-01/CR-02 internals. W-02 explicitly
  revisits it — the extraction must **preserve** the CR-01 (save/restore request tenant) and CR-02
  (close connection after the loop) behavior exactly; `switchTo()`/`restore()` already encapsulate it.
- **`SharedEntitySyncSubscriber::postFlush()` async branch is a separate path** — it clears/restores
  `TenantContext` around `bus->dispatch()` and never calls `switchToTenant()`. Out of W-02 scope.

### Integration Points
- W-02: `config/services.php` (new service def + 2 argument changes); constructors of
  `SharedEntitySyncSubscriber` (`:91-100`) and `SharedEntityChangedMessageHandler` (`:54-62`).
- W-01: `SharedEntitySyncSubscriber.php:97`, `SharedEntityWriteProtectionListener.php:44`.
- W-03: `TenantEmSwitcher` docblock + `SharedEntityResyncCommand.php` (~`:126-127`) note.
- WR-06/07: `docs/roadmap.md` (Shipped/Next/In-progress sections + PHPStan line `:23`).
- WR-03: `scripts/docs-lint.sh` awk block (~`:73-81`).

</code_context>

<specifics>
## Specific Ideas

- Proposed names: `TenantEmSwitcher` / `TenantEmSwitcherInterface`, methods `switchTo()` / `restore()`.
- The extracted service IS the canonical "lightweight switch" referenced by W-03's documentation — one
  artifact resolves both W-02 (de-dup) and W-03 (the thing resync is contrasted against).
- Roadmap v0.4 Shipped entry: landed-but-untagged framing + CHANGELOG link (D-09).

</specifics>

<deferred>
## Deferred Ideas

These are tracked in the audit as tech debt but are **out of Phase 30 scope** (no user request to pull
them in; they are separate command/skill follow-ups, not pre-tag blockers):

- **Nyquist validation gaps** — phases 24/26/28/29 need `/gsd:validate-phase`. Discovery-only; not code.
- **Manual UAT pending** — Phase 26 TTY confirm prompt; Phase 28 extension-installer zero-config auto-load
  in a real consumer project. Require a human, not a code change.
- **Phase 27 advisory code review** — unvalidated `changeType`, stale-context-on-failure, dead `findAll()`
  loop. Advisory; the audit did not escalate them to warnings. Revisit if they recur.
- **Phase 29 info-level review notes** — `tenancy:run` shell-interpretation caveat, slug-validation "clear
  error" wording, `addslashes` characterization in `sql-filter.md`. Cosmetic docs polish.
- **`mkdocs build --strict`** — CI-deferred (mkdocs not installable locally); `docs-lint.sh` is the local
  green proxy.

### Reviewed Todos (not folded)
None — no pending todos matched this phase (`todo match-phase 30` → 0).

</deferred>

---

*Phase: 30-v0-4-pre-tag-closure-integration-warnings-roadmap-drift*
*Context gathered: 2026-06-19*
