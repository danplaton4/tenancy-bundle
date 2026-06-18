---
phase: 29
slug: docs-refresh
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-06-18
---

# Phase 29 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> This is a documentation + docs-tooling phase: the verification mechanism is
> `scripts/docs-lint.sh` (terminology/install-path guards) plus `mkdocs build --strict`
> (nav registration + internal-link integrity). Semantic accuracy of prose is validated
> by cross-referencing the source-of-truth phase contexts/code, not by the lint check.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | `scripts/docs-lint.sh` (shell, run from repo root) + `mkdocs build --strict` (Python, CI) |
| **Config file** | none for docs-lint; `mkdocs.yml` for mkdocs |
| **Quick run command** | `bash scripts/docs-lint.sh` |
| **Full suite command** | `bash scripts/docs-lint.sh && mkdocs build --strict` |
| **Estimated runtime** | ~5 seconds (lint) + ~10 seconds (mkdocs build) |

---

## Sampling Rate

- **After every task commit:** Run `bash scripts/docs-lint.sh` from repo root
- **After every plan wave:** Run `bash scripts/docs-lint.sh && mkdocs build --strict` (if mkdocs available locally; `pip install mkdocs-material` otherwise CI covers it)
- **Before `/gsd:verify-work`:** docs-lint green AND `mkdocs build --strict` clean
- **Max feedback latency:** ~15 seconds

---

## What docs-lint.sh Can and Cannot Catch

| Validates (automated) | Cannot validate (manual / cross-ref) |
|-----------------------|--------------------------------------|
| Absence of stale terms (wrapperClass, v0.1 install paths) | Semantic accuracy of new prose |
| `bundles.php` install-path regressions | Whether code examples are syntactically valid PHP |
| Shared-entity disambiguator presence (D-04 new check) | Whether API names / method signatures are correct |
| All `docs/` files scanned via `find` | Content in UPGRADE.md / CHANGELOG.md (excluded by design) |

**Semantic accuracy is validated by cross-referencing source files** (the 5 source-of-truth phase contexts + `src/`) — the lint check is purely syntactic/terminological.

---

## Per-Task Verification Map

| Item | Deliverable | Wave | Requirement | Test Type | Automated Command | Status |
|------|-------------|------|-------------|-----------|-------------------|--------|
| docs-lint D-04 check | `scripts/docs-lint.sh` per-file shared-entity disambiguation guard | 1 | DOC-20 | smoke | `bash scripts/docs-lint.sh` | ⬜ pending |
| shared-entities.md | NEW page; must contain BOTH "landlord-side master" AND "tenant-side read-only copy" verbatim (D-07) → passes D-04 | 1 | DOC-20 | smoke | `bash scripts/docs-lint.sh` | ⬜ pending |
| phpstan-extension.md | NEW page; rule IDs accurate vs source | 1 | DOC-20 | manual | cross-reference `src/PHPStan/` | ⬜ pending |
| filesystem-bootstrapper.md | drift fix (services[] no-op annotation) + cross-links; no stale terms | 1 | DOC-20 | smoke | `bash scripts/docs-lint.sh` | ⬜ pending |
| mkdocs.yml nav | both new pages registered → build resolves them | 1 | DOC-20 | build | `mkdocs build --strict` | ⬜ pending |
| UPGRADE.md 0.3→0.4 | expand existing section; remove "expanded in Phase 29" placeholder | 1 | DOC-20 | manual | cross-reference source (UPGRADE excluded from lint) | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- New page files (`shared-entities.md`, `phpstan-extension.md`) must EXIST before the D-04 lint check and `mkdocs build --strict` can validate them — file creation is itself the prerequisite, no separate test stub needed.
- `mkdocs build` environment: mkdocs may not be installed locally; CI runs it. For local verification: `pip install mkdocs-material`.

*No separate Wave 0 test-stub wave: docs deliverables are validated by lint + build once the files exist; the D-04 check is itself a Wave 1 deliverable.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| New-page prose semantically matches shipped code | DOC-20 | Lint is terminological, not semantic | Cross-reference each API claim (`#[Shared]` namespace, `SharedEntityWriteInTenantContextException::forEntity`, `tenancy:shared:resync` options `--tenant`/`--dry-run`/`--force`, rule IDs `tenancy.mutualExclusion`/`tenancy.sharedEntityLeak`/`tenancy.tenantIdDrift`, `checkSharedEntityLeaks` default `true`) against `src/` |
| UPGRADE.md 0.3→0.4 accuracy | DOC-20 | UPGRADE.md excluded from docs-lint by design | Confirm "no breaking changes" claim against `TenantInterface`/`TenantFilesystemConfigTrait` |

---

## Validation Sign-Off

- [ ] Every deliverable has a docs-lint, mkdocs-build, or documented manual cross-reference check
- [ ] Sampling continuity: docs-lint runs after every task commit
- [ ] New page files created before lint/build validation depends on them
- [ ] No watch-mode flags
- [ ] Feedback latency < 15s
- [ ] `nyquist_compliant: true` set in frontmatter once plans satisfy the map above

**Approval:** pending
