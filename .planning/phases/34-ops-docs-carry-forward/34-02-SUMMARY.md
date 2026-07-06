---
phase: 34-ops-docs-carry-forward
plan: "02"
subsystem: docs/ci
tags: [docs, upgrade-guide, docs-lint, bc-break, maintenance-mode]
dependency_graph:
  requires:
    - docs/ops/maintenance-mode.md (34-01)
    - docs/ops/health-checks.md (34-01)
    - docs/ops/parallel-migrations.md (34-01)
  provides:
    - scripts/docs-lint.sh ops-terms guard (OPS_TARGETS block)
    - UPGRADE.md 0.4 to 0.5 BC-break section
  affects:
    - CI docs-lint gate (new ops-terms guards active)
    - Adopter upgrade path (isInMaintenance BC break documented)
tech_stack:
  added: []
  patterns:
    - docs-lint.sh check() negative guard pattern (OPS_TARGETS scoped to docs/)
    - UPGRADE.md BC-break section template (mirrors 0.2 to 0.3 TenantInterface pattern)
key_files:
  created: []
  modified:
    - scripts/docs-lint.sh
    - UPGRADE.md
decisions:
  - "OPS_TARGETS=(docs/) used for all ops-terms guards — UPGRADE.md and CHANGELOG.md excluded (consistent with existing D-04/D-15 precedents)"
  - "Four wrong-form guards chosen: two command-name guards (activated/deactivated), one endpoint path guard (health/liveness), one header format guard (cache_control_no_store)"
  - "UPGRADE.md 0.4 to 0.5 section inserted above 0.4.0 to 0.4.1 (newest-first ordering); mirrors 0.2 to 0.3 template with two migration paths + no-action note"
  - "Doctrine optional-guard prose note added to Migration path A per CLAUDE.md convention"
metrics:
  duration: "~2 min"
  completed: "2026-07-06"
  tasks: 2
  files_changed: 2
---

# Phase 34 Plan 02: docs-lint Guard + UPGRADE 0.4→0.5 Summary

Docs-lint guard for new ops terms (D-04) added to `scripts/docs-lint.sh` via OPS_TARGETS block; `UPGRADE.md` extended with a complete `## 0.4 to 0.5` BC-break section covering `isInMaintenance()` and `TenantMaintenanceConfigTrait` migration path.

## What Was Built

**Task 1 — ops-terms negative guard in docs-lint.sh (commit ebbb62d)**

Added a `# D-04 (Phase 34): Ops-terms consistency guards.` block to `scripts/docs-lint.sh` after the existing `sqlite://` check. The block defines `OPS_TARGETS=(docs/)` (scoped to docs/ only, UPGRADE.md and CHANGELOG.md excluded) and adds four `check()` calls guarding wrong/stale forms:

- `tenancy:maintenance:activated` — wrong command name (correct: `tenancy:maintenance:enable`)
- `tenancy:maintenance:deactivated` — wrong command name (correct: `tenancy:maintenance:disable`)
- `health/liveness` — wrong endpoint path segment (correct: `/_tenancy/health/live`)
- `cache_control_no_store` — underscore form of the header (correct: `Cache-Control: no-store`)

All guards are NEGATIVE (fire EXIT=1 only if the wrong form IS found). None guards a correct term. Script exits 0 against the real 34-01 ops pages.

**Task 2 — UPGRADE.md 0.4 → 0.5 BC-break section (commit e20653a)**

Inserted `## 0.4 to 0.5` at the top of UPGRADE.md, immediately before `## 0.4.0 to 0.4.1` (newest-first ordering). Section structure mirrors the `## 0.2 to 0.3` TenantInterface BC-break template:

- Intro: Phase 32 introduces per-tenant maintenance mode; `isInMaintenance(): bool` is the one new required `TenantInterface` method
- Migration path A: `use TenantMaintenanceConfigTrait;` (recommended) — adds `isInMaintenance()` + `bool $inMaintenance = false` property + `in_maintenance` Doctrine column; `doctrine:migrations:diff` generates the column migration
- Migration path B: manual `return false` implementation
- No-action note: any class returning `false` from `isInMaintenance()` is fully v0.5-compatible; the trait makes the break a no-op for adopters who use it
- Doctrine optional-guard prose note in Migration path A (per CLAUDE.md convention)
- Cross-link to `docs/ops/maintenance-mode.md` for full configuration details

## Verification Results

**Task 1 verification (`bash scripts/docs-lint.sh; test $? -eq 0 && grep -q 'OPS_TARGETS' ... && [ count -ge 3 ]`):**
- `bash scripts/docs-lint.sh` exits 0 — PASS
- `grep -q 'OPS_TARGETS'` — PASS
- `grep -q 'tenancy:maintenance:activated'` — PASS
- `grep -cE 'check .+OPS_TARGETS' >= 3` — PASS (4 guards present)

**Task 2 verification (`grep -qE '^## 0.4 to 0.5' && grep -q isInMaintenance && grep -q TenantMaintenanceConfigTrait && grep -qi 'migration path' && awk position check`):**
- `## 0.4 to 0.5` heading present — PASS
- `isInMaintenance` present — PASS
- `TenantMaintenanceConfigTrait` present — PASS
- `migration path` (case-insensitive) present — PASS
- `## 0.4 to 0.5` appears before `## 0.4.0 to 0.4.1` (awk position check) — PASS
- `docs-lint.sh` still exits 0 after UPGRADE.md change — PASS (UPGRADE.md is not in OPS_TARGETS)

## Deviations from Plan

None — plan executed exactly as written.

The four ops-term guards chosen match the 34-PATTERNS.md §scripts/docs-lint.sh specification exactly. The UPGRADE.md section mirrors the 0.2→0.3 template as specified.

## Threat Flags

No new threat surface introduced.

- T-34-04 (Tampering — docs-lint mistakenly guards a CORRECT term): All four guards target WRONG/stale forms only. None of the guarded patterns appear in the 34-01 ops pages (verified by grep before implementation).
- T-34-05 (Repudiation — UPGRADE understates the BC break): Section documents both migration paths, the DB migration step (`doctrine:migrations:diff`), and a scoped no-action note. No blanket "nothing changed" — the note is explicitly for adopters satisfying the new method.

## Self-Check: PASSED

| Item | Status |
|------|--------|
| `scripts/docs-lint.sh` has `OPS_TARGETS` | FOUND |
| `scripts/docs-lint.sh` has `tenancy:maintenance:activated` guard | FOUND |
| `scripts/docs-lint.sh` exits 0 | PASS |
| `UPGRADE.md` has `## 0.4 to 0.5` heading | FOUND |
| `UPGRADE.md` `## 0.4 to 0.5` before `## 0.4.0 to 0.4.1` | CONFIRMED |
| commit ebbb62d exists | FOUND |
| commit e20653a exists | FOUND |
