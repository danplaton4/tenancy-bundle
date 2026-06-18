# Phase 29: Docs Refresh - Context

**Gathered:** 2026-06-18
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 29 delivers the **v0.4 documentation refresh** (DOC-20) — the final phase of the
v0.4 "Storage & Shared Entities" milestone. It brings user-facing docs in line with
everything Phases 24-28 shipped:

- **Filesystem bootstrapper** (Phase 24) — already documented; verify accuracy.
- **Shared entities** (Phases 25/26/27) — `#[Shared]` sync model, async mode, the
  `tenancy:shared:resync` command — currently **undocumented**.
- **PHPStan extension** (Phase 28) — three rules + install — currently **undocumented**.
- **UPGRADE.md** 0.3 → 0.4 section.
- **`scripts/docs-lint.sh`** extended with a shared-entity terminology-ambiguity guard.

This is a documentation + docs-tooling phase. The scope is fixed by DOC-20. No new
runtime/library capabilities — discussion only clarified HOW to write/structure the docs
and the lint check.

</domain>

<decisions>
## Implementation Decisions

### Page Disposition (what gets created vs. touched)

- **D-01: `user-guide/filesystem-bootstrapper.md` → verify-only accuracy pass.** The page
  already exists (433 lines, comprehensive: overview, prefix vs per-tenant-adapter modes,
  configuration reference, `MissingFilesystemConfigException` + other exceptions, trust
  boundary, 7 pitfalls). DOC-20 assumed it was new — it is not. A plan re-checks the page
  against shipped code, fixes only drift, and adds cross-links to the new pages. **Do NOT
  rewrite a good page.** If research confirms zero drift, the only change is cross-links.
- **D-02: `user-guide/shared-entities.md` → NEW, single comprehensive page.** One page covers
  the whole shared-entity story: `#[Shared]` attribute, **sync vs async** model, the
  `tenancy:shared:resync` command, the **cascade-depth (one-level) landmine**, and the
  **tenant-side write-protection invariant**. Establish the canonical vocabulary up front
  (see D-07). Distinct from the existing `shared-db.md` page (that's the shared-DB *driver*,
  a different feature — the page must not be confused with it).
- **D-03: `user-guide/phpstan-extension.md` → NEW.** Covers installation
  (`composer require --dev` + `phpstan/extension-installer` auto-load via
  `composer.json#extra.phpstan.includes`), the `phpstan.neon` snippet, all **three rules**
  (mutual-exclusion `tenancy.mutualExclusion`, shared-entity-leak `tenancy.sharedEntityLeak`,
  `tenant_id`-drift `tenancy.tenantIdDrift`) each with an **example violation + fix**, and the
  `checkSharedEntityLeaks` parameter (default-true, how/why to toggle).

### docs-lint.sh Shared-Entity Ambiguity Check

- **D-04: Per-file disambiguation strictness.** The new check fails CI when a `docs/` file
  references "shared entit(y/ies)" but does **not** contain BOTH disambiguators
  ("landlord-side master" AND "tenant-side read-only copy") somewhere in that same file.
  Simplest rule, lowest false-positive rate, fits the existing `check()` helper idiom in
  `scripts/docs-lint.sh`. Rejected: per-occurrence proximity (false positives on a page that
  says "shared entity" dozens of times) and section-whitelist awk (more complexity than this
  warrants). The new shared-entities.md page must therefore include both phrases.

### UPGRADE.md

- **D-05: Always add a brief 0.3 → 0.4 section** (not strictly conditional). Even if research
  finds no BC break (none expected — `TenantInterface::getFilesystemConfig()` is OPTIONAL via
  trait), add a short section summarizing what v0.4 adds and an explicit "no breaking changes"
  note. If research DOES surface an unforeseen break, document it there. Per-minor upgrade
  notes aid discoverability; cost is low. (Note: `docs-lint.sh` does NOT scan UPGRADE.md, so
  migration-recipe terms there are exempt from the new check too.)

### mkdocs Navigation

- **D-06: Insert both new pages into the User Guide nav** (`mkdocs.yml`). Place
  `shared-entities.md` adjacent to `shared-db.md` (related storage topics); place
  `phpstan-extension.md` near `testing.md` / `strict-mode.md` (tooling/quality). Exact order
  at Claude's discretion.

### Terminology (drives both the page and the lint check)

- **D-07: Canonical vocabulary, locked.**
  - **"landlord-side master"** = the authoritative `#[Shared]` record living on the landlord
    EntityManager (the single source of truth).
  - **"tenant-side read-only copy"** = the denormalized mirror fanned out to each tenant's EM;
    write-protected via `SharedEntityWriteInTenantContextException`.
  The shared-entities page MUST use this vocabulary (and contain both phrases verbatim so it
  passes D-04's lint check).

### Claude's Discretion

- **Page depth/tone** — mirror existing user-guide pages. Baseline lengths for calibration:
  `mailer-bootstrapper.md` ~140 lines, `shared-db.md` ~210, `cli-commands.md` ~240,
  `filesystem-bootstrapper.md` ~430 (comprehensive end of the range).
- **phpstan-extension.md** exact wording and example-violation code snippets.
- Whether to add a `tenancy:shared:resync` cross-link/stub into the existing
  `cli-commands.md` page (the comprehensive command docs live on shared-entities.md per D-02).
- Exact nav ordering within the User Guide section (D-06).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Requirement (the contract)
- `.planning/REQUIREMENTS.md` §DOC-20 — full acceptance criteria for all five deliverables
  (3 pages, UPGRADE section, docs-lint extension). Note its "new filesystem page" assumption
  is stale per D-01.

### Source-of-truth phase contexts (what the docs must describe accurately)
- `.planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md` — filesystem bootstrapper
  decisions; cross-check against the existing page (D-01).
- `.planning/phases/25-shared-entities-sync-mode/25-CONTEXT.md` — `#[Shared]` attribute,
  `SharedEntitySyncSubscriber` (landlord `postFlush` fan-out), full read-only enforcement
  (`SharedEntityWriteInTenantContextException`, D-02 of that phase), one-level cascade,
  mutual-exclusion compile-time guard, sync = insert/update/delete.
- `.planning/phases/26-tenancy-shared-resync-command/26-CONTEXT.md` — the
  `tenancy:shared:resync` command behavior.
- `.planning/phases/27-async-shared-entities/27-CONTEXT.md` — async fan-out mode (sync-vs-async
  story for the page).
- `.planning/phases/28-phpstan-extension/28-CONTEXT.md` — the three rules, the
  `checkSharedEntityLeaks` parameter, `phpstan/extension-installer` distribution
  (DEC-PHPSTAN-01), `phpstan/phpstan-doctrine` soft integration.

### Existing docs assets (style baseline + files to modify)
- `docs/user-guide/filesystem-bootstrapper.md` — existing comprehensive page (verify, don't rewrite).
- `docs/user-guide/shared-db.md`, `docs/user-guide/mailer-bootstrapper.md`,
  `docs/user-guide/cli-commands.md` — style/length baseline for the new pages.
- `mkdocs.yml` — nav structure; add two entries (D-06).
- `UPGRADE.md` — existing upgrade guide; add 0.3 → 0.4 section (D-05). NOT scanned by docs-lint.
- `scripts/docs-lint.sh` — existing `check()` helper + section-whitelist awk guard; extend with
  the per-file shared-entity check (D-04). Run from repo root; CI invokes it.

### Key Decisions referenced upstream
- `.planning/REQUIREMENTS.md` Key Decisions — DEC-SHARE-01 (sync default), DEC-SHARE-02
  (one-level cascade), DEC-SHARE-03 (mutual exclusion = compile-time error), DEC-PHPSTAN-01
  (extension-installer distribution).

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `scripts/docs-lint.sh` `check(pattern, desc, targets...)` helper — the new shared-entity
  check should reuse this idiom (or the awk pattern already in the file for section-aware
  scanning). The script already sets `EXIT=1` on any violation and prints a clean OK line.

### Established Patterns
- User-guide pages follow a consistent shape: H1 title (often with a REQ/feature tag, e.g.
  "Filesystem Bootstrapper (BOOT-03)"), Overview, Installation/Quick Start, Configuration,
  Exception/FAQ/Pitfalls, "See also". New pages should match this shape.
- docs-lint.sh scopes scans to `docs/` (+ `src/Command/TenantInitCommand.php`) and
  deliberately excludes CHANGELOG.md / UPGRADE.md from stale-term scans — the new check
  should follow the same docs/-only scoping.

### Integration Points
- `mkdocs.yml` nav — the two new pages must be registered or they won't appear in the built site.
- CI runs `scripts/docs-lint.sh`; the extended check participates in the existing CI docs gate.

</code_context>

<specifics>
## Specific Ideas

- The shared-entities page must verbatim contain both "landlord-side master" and
  "tenant-side read-only copy" (D-07) — this is both a clarity goal AND the condition that
  makes it pass the new docs-lint check (D-04). The two decisions are intentionally coupled.
- Distinguish `shared-entities.md` (the `#[Shared]` Doctrine entity sync feature) from the
  existing `shared-db.md` (the shared-database isolation *driver*). They are different
  features; the page should briefly note the distinction to avoid reader confusion.

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope. (Cross-tenant `#[Shared]` query patterns are
explicitly out of scope per REQUIREMENTS.md "Anti-Scope"; not a docs concern for v0.4.)

</deferred>

---

*Phase: 29-docs-refresh*
*Context gathered: 2026-06-18*
