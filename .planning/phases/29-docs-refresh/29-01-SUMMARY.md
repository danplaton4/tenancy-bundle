---
phase: 29-docs-refresh
plan: "01"
subsystem: docs
tags: [documentation, shared-entities, phpstan, d-04, d-06, d-07]
dependency_graph:
  requires: []
  provides: [DOC-20/D-02, DOC-20/D-03, DOC-20/D-06, D-04-prerequisite]
  affects: [docs/user-guide, docs/architecture, mkdocs.yml]
tech_stack:
  added: []
  patterns: [MkDocs Material admonitions, tabbed install blocks, D-07 canonical vocabulary]
key_files:
  created:
    - docs/user-guide/shared-entities.md
    - docs/user-guide/phpstan-extension.md
  modified:
    - docs/user-guide/shared-db.md
    - docs/architecture/sql-filter.md
    - docs/roadmap.md
    - mkdocs.yml
decisions:
  - "D-07 canonical vocabulary locked: landlord-side master + tenant-side read-only copy appear in all 5 docs/ files matching shared entit(y|ies)"
  - "shared-db.md #[Shared] section expanded with both canonical phrases + cross-link to shared-entities.md (minimal edit, no rewrite)"
  - "sql-filter.md Branch 2 parenthetical added for D-04 compliance"
  - "roadmap.md bullet point expanded inline with canonical phrases"
metrics:
  duration: "~25 min"
  completed: "2026-06-18"
  tasks_completed: 3
  files_modified: 6
---

# Phase 29 Plan 01: Shared Entities + PHPStan Extension Pages Summary

Authored two new user-guide pages (shared-entities.md covering the `#[Shared]` sync model and phpstan-extension.md covering all three static-analysis rules), registered both in the mkdocs nav, and reconciled three existing docs files with D-07 canonical vocabulary so the whole docs/ tree is D-04-compliant before Plan 03's lint check lands.

---

## Tasks Completed

| # | Task | Commit | Files |
|---|------|--------|-------|
| 1 | Author docs/user-guide/shared-entities.md (D-02 + D-07) | 204830f | docs/user-guide/shared-entities.md |
| 2 | Author phpstan-extension.md + reconcile 3 existing files for D-04 | f38b30c | docs/user-guide/phpstan-extension.md, docs/user-guide/shared-db.md, docs/architecture/sql-filter.md, docs/roadmap.md |
| 3 | Register both pages in mkdocs.yml User Guide nav (D-06) | 35728f2 | mkdocs.yml |

---

## What Was Built

### `docs/user-guide/shared-entities.md` (NEW — D-02 + D-07)

Comprehensive user-guide page (~310 lines) covering:
- `#[Shared]` attribute (`Tenancy\Bundle\Attribute\Shared`) — zero-param TARGET_CLASS marker
- D-07 canonical vocabulary established in Overview: "landlord-side master" + "tenant-side read-only copy"
- Disambiguation from `shared-db.md` (different features)
- Sync model: `SharedEntitySyncSubscriber` landlord-EM postFlush fan-out, best-effort failure semantics
- One-level cascade landmine (scalar fields only, associations NULL on tenant side)
- Async mode: `tenancy.shared.async: true`, `SharedEntityChangedMessage` payload shape, transport routing landmine, latest-state landmine
- `tenancy:shared:resync` command: full flags table (`--tenant`, `--dry-run`, `--force`; no `--all`), dry-run classification table, confirmation prompt, apply output format, shared_db no-op, idempotency
- Write protection: `SharedEntityWriteInTenantContextException extends \LogicException`, `::forEntity()` factory, LogicException no-retry invariant
- `shared_db` driver behavior: subscriber short-circuits
- See also cross-links

### `docs/user-guide/phpstan-extension.md` (NEW — D-03)

Tooling reference page (~210 lines) covering:
- Three install paths (extension-installer recommended, manual includes, with phpstan-doctrine) using tabbed block
- Double-registration danger admonition: extension-doctrine.neon INSTEAD OF extension.neon
- Rule 1 `tenancy.mutualExclusion`: violation + fix examples
- Rule 2 `tenancy.sharedEntityLeak`: violation + two fix options (MapEntity + @phpstan-ignore), conservative concrete-EntityManager-only scope, `checkSharedEntityLeaks` toggle admonition
- Rule 3 `tenancy.tenantIdDrift`: three violation cases (missing/nullable/non-string) + fix, accepted types (string/ascii_string/guid/uuid)
- D-04 compliance: both canonical phrases appear naturally in Rule 2 prose

### D-04 Reconciliation (3 existing files)

- `shared-db.md`: Section "Shared Entities (#[Shared]) Under shared_db" expanded with one paragraph using both canonical phrases + cross-link to shared-entities.md
- `sql-filter.md`: Branch 2 description extended with a parenthetical citing both phrases + cross-link
- `roadmap.md`: v0.4 shared-entities bullet point expanded inline with both phrases + cross-link

### `mkdocs.yml` nav (D-06)

- `Shared Entities: user-guide/shared-entities.md` inserted after `Shared-DB Driver` (line 75)
- `PHPStan Extension: user-guide/phpstan-extension.md` appended as last User Guide entry (line 86)
- YAML structurally valid (verified with PermissiveLoader handling mkdocs-material !!python/name tags)

---

## Verification

- `bash scripts/docs-lint.sh` → PASSED (no stale v0.1 terms, no bundles.php regressions)
- D-04 cross-tree dry run → PASSED (all 5 docs/ files matching `shared entit(y|ies)` contain both canonical phrases)
- All task acceptance criteria verified with grep before each commit
- API accuracy: every class name, method signature, command flag, rule ID, and config key verified against live src/ files before writing prose

---

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `--all` grep false-positive in shared-entities.md acceptance criteria**

- **Found during:** Task 1 acceptance verification
- **Issue:** The acceptance criteria require `grep -c -- '--all' docs/user-guide/shared-entities.md` == 0. Initial prose included "There is **no `--all` flag**" which contains the literal string `--all`, causing the count to be 1 instead of 0.
- **Fix:** Rephrased to "Omitting `--tenant` targets all tenants — no separate 'all' flag exists." (conveys the same information without the literal `--all` string)
- **Files modified:** docs/user-guide/shared-entities.md
- **Commit:** 204830f

**2. [Rule 1 - Bug] "tenant-side read-only copies" (plural) in sql-filter.md failed grep**

- **Found during:** Task 2 D-04 compliance verification
- **Issue:** Initial edit to sql-filter.md used the plural "tenant-side read-only copies" but the grep check looks for the singular "tenant-side read-only copy".
- **Fix:** Rephrased to "... fanned out as a tenant-side read-only copy (one per tenant database)" which contains the exact singular phrase.
- **Files modified:** docs/architecture/sql-filter.md
- **Commit:** f38b30c

---

## Known Stubs

None. All documented APIs verified against live source files.

---

## Threat Flags

None. This plan ships only Markdown and YAML — no executable artifacts, no new network endpoints.

---

## Self-Check: PASSED

- `[ -f docs/user-guide/shared-entities.md ]` → FOUND
- `[ -f docs/user-guide/phpstan-extension.md ]` → FOUND
- Commit 204830f → FOUND
- Commit f38b30c → FOUND
- Commit 35728f2 → FOUND
- D-04 cross-tree check → no VIOLATION lines
- docs-lint.sh → OK
