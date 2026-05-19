---
phase: 14-documentation-refresh
plan: "01"
subsystem: documentation
tags: [docs, flex-removal, tenancy-init, onboarding, composer]
dependency_graph:
  requires: []
  provides: [no-flex-references, tenancy-init-as-primary-onboarding]
  affects: [docs/user-guide/installation.md, docs/index.md, docs/user-guide/index.md, README.md, composer.json]
tech_stack:
  added: []
  patterns: [tenancy:init as primary onboarding, single installation path]
key_files:
  created: []
  modified:
    - composer.json
    - docs/user-guide/installation.md
    - docs/index.md
    - docs/user-guide/index.md
    - README.md
  deleted:
    - flex/danplaton4/tenancy-bundle/1.0/config/packages/tenancy.yaml
    - flex/danplaton4/tenancy-bundle/1.0/manifest.json
decisions:
  - "tenancy:init replaces Symfony Flex as primary zero-config onboarding path"
  - "cache_prefix_separator default documented as '.' (dot) not ':' (colon) across all docs"
  - "extra.symfony block removed from composer.json; branch-alias preserved"
metrics:
  duration: "~8 min"
  completed_date: "2026-04-14"
  tasks_completed: 3
  files_modified: 5
  files_deleted: 2
---

# Phase 14 Plan 01: Remove Symfony Flex Artifacts and Update Onboarding Documentation Summary

Purged all Symfony Flex artifacts and references from the repository; replaced Flex-based auto-registration with `tenancy:init` as the primary zero-config onboarding path across code and all documentation.

## Tasks Completed

| # | Task | Commit | Files |
|---|------|--------|-------|
| 1 | Delete flex/ directory and remove extra.symfony from composer.json | de1348e | flex/ (deleted), composer.json |
| 2 | Rewrite installation.md — remove Flex tab, single path, add tenancy:init tip | c0afa2f | docs/user-guide/installation.md |
| 3 | Remove Flex references from index.md, user-guide/index.md, README.md | 010e466 | docs/index.md, docs/user-guide/index.md, README.md |

## What Was Built

- **flex/ directory deleted:** Removed `flex/danplaton4/tenancy-bundle/1.0/` recipe files entirely — Flex auto-registration is no longer offered.
- **composer.json:** Removed `extra.symfony.bundles` block; preserved `extra.branch-alias`.
- **installation.md:** Replaced tab-based With Flex / Without Flex layout with a single standard registration path. Fixed `cache_prefix_separator` default from `':'` to `'.'`. Added `tenancy:init` tip admonition with `cli-commands.md#tenancyinit` cross-reference.
- **docs/index.md:** Updated Quick Start step 1 description; replaced "Flex recipe (zero-config)" comparison table row with "`tenancy:init` scaffolding"; added `tenancy:init` to CLI commands feature row.
- **docs/user-guide/index.md:** Updated Installation description from "Flex auto-config, manual registration" to "bundle registration, tenancy:init".
- **README.md:** Updated Quick Start step 1 description; replaced "Flex recipe (zero-config install)" comparison table row with "`tenancy:init` scaffolding"; added `tenancy:init` to CLI commands feature bullet.

## Verification

All 5 verification checks passed:

1. `test ! -d flex/` — PASS
2. `php -r 'json_decode(...); echo json_last_error() === 0 ? "valid" : "invalid";'` — PASS (valid JSON)
3. `! grep -riq 'flex' docs/user-guide/installation.md docs/index.md docs/user-guide/index.md README.md` — PASS
4. `grep -l 'tenancy:init' ... | wc -l` — 4 (all 4 doc files)
5. `composer.json` retains `branch-alias`, no `extra.symfony` block

## Deviations from Plan

None — plan executed exactly as written.

The automated verification command `! grep -q '"symfony"' composer.json` in Task 1 produces a false negative because `"symfony"` appears in the `keywords` array (expected, correct). The actual acceptance criteria — `extra.symfony` block removed, `branch-alias` preserved, valid JSON — are all met.

## Known Stubs

None — all onboarding paths now wire to real `tenancy:init` command (implemented in Phase 12).

## Threat Flags

None — documentation-only changes; no new network endpoints, auth paths, or schema changes introduced.

## Self-Check: PASSED

- de1348e commit exists: FOUND
- c0afa2f commit exists: FOUND
- 010e466 commit exists: FOUND
- docs/user-guide/installation.md modified: FOUND
- docs/index.md modified: FOUND
- docs/user-guide/index.md modified: FOUND
- README.md modified: FOUND
- composer.json modified: FOUND
- flex/ directory deleted: CONFIRMED
