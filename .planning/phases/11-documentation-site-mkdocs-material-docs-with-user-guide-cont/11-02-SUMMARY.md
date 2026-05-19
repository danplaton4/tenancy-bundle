---
phase: 11-documentation-site
plan: 02
subsystem: documentation
tags: [mkdocs, mkdocs-material, user-guide, installation, configuration, resolvers, strict-mode]

# Dependency graph
requires:
  - phase: 11-01
    provides: MkDocs Material site scaffolding, mkdocs.yml nav, stub pages for all user guide files

provides:
  - Installation page (Flex/manual registration, optional deps table, verification steps)
  - Getting Started 5-minute walkthrough (database_per_tenant and shared_db driver paths)
  - Configuration Reference (all 8 tenancy.yaml keys with YAML/PHP tabs, validation rules, minimal examples)
  - Resolvers page (all 4 built-in resolvers + custom resolver example with PathResolver)
  - Strict Mode page (security rationale, technical internals, how to disable)

affects: [11-03, contributor-guide, architecture]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "MkDocs Material content tabs (=== \"YAML\" / === \"PHP\") for dual-format config examples"
    - "Admonitions (!!! warning, !!! danger, !!! note, !!! tip) for callouts"
    - "Priority table pattern for documenting resolver ordering"

key-files:
  created: []
  modified:
    - docs/user-guide/installation.md
    - docs/user-guide/getting-started.md
    - docs/user-guide/configuration.md
    - docs/user-guide/resolvers.md
    - docs/user-guide/strict-mode.md

key-decisions:
  - "Documented all 8 config keys from TenancyBundle::configure() rather than just the commonly-used ones"
  - "Getting started walkthrough covers both drivers with separate paths rather than a single hybrid example"
  - "Custom resolver example uses PathResolver (/tenant/{slug}/...) as a concrete, self-contained example"
  - "ConsoleResolver documented as separate from HTTP chain to avoid confusion about its priority=N/A"

patterns-established:
  - "Source-first documentation: every code example was derived from actual source files, not invented"
  - "YAML + PHP content tabs for all configuration examples"
  - "Admonition for any security-relevant or footgun-adjacent behavior"
  - "Cross-page navigation links at the bottom of each page"

requirements-completed: [DOC-04, DOC-05, DOC-06, DOC-07, DOC-08]

# Metrics
duration: 35min
completed: 2026-04-12
---

# Phase 11 Plan 02: User Guide Core Pages Summary

**Five user guide pages written from source code with working PHP 8.2+ examples, YAML/PHP content tabs, and cross-page navigation covering the full installation-to-configuration critical path.**

## Performance

- **Duration:** ~35 min
- **Started:** 2026-04-12T00:00:00Z
- **Completed:** 2026-04-12T00:35:00Z
- **Tasks:** 3
- **Files modified:** 5

## Accomplishments

- Installation page covers Symfony Flex auto-registration, manual registration, optional deps table with all 5 optional packages, and `debug:container` verification steps
- Getting Started page provides complete 5-minute walkthroughs for both `database_per_tenant` (dual-EM Doctrine config with `TenantConnection` wrapper_class) and `shared_db` (`#[TenantAware]` entity example) drivers with request lifecycle diagram
- Configuration reference documents all 8 config keys (from `TenancyBundle::configure()`) with types, defaults, YAML/PHP tabs, validation rule (shared_db + database.enabled = error), and 3 minimal example scenarios
- Resolvers page covers all 4 built-in resolvers with priority table, exception behavior, and a complete custom `PathResolver` example implementing `TenantResolverInterface`
- Strict mode page explains the security rationale, `TenantAwareFilter` 4-branch logic, when to disable, and scoped disable patterns (per-environment, env var)

## Task Commits

1. **Task 1: Installation and Getting Started** - `fadd893` (feat)
2. **Task 2: Configuration Reference and Strict Mode** - `6f11e06` (feat)
3. **Task 3: Resolvers page** - `9771f77` (feat)

## Files Created/Modified

- `docs/user-guide/installation.md` — 141 lines: Composer install, Flex/manual tabs, optional deps table, verification commands
- `docs/user-guide/getting-started.md` — 243 lines: Prerequisites, driver comparison, two complete quick-path walkthroughs, request lifecycle diagram
- `docs/user-guide/configuration.md` — 259 lines: All 8 config keys, YAML/PHP tabs, validation rules, minimal examples for 3 scenarios
- `docs/user-guide/resolvers.md` — 284 lines: Priority table, 4 resolver sections, enable/disable config, custom resolver with full PathResolver example
- `docs/user-guide/strict-mode.md` — 117 lines: Security rationale, technical flow, console behavior, disable instructions, scoped disable patterns

## Decisions Made

- Documented all 8 config keys from `TenancyBundle::configure()` rather than just commonly-used ones — the reference page should be complete
- Getting started covers both drivers with separate step-by-step paths rather than a combined hybrid to keep each path clear and actionable
- Custom resolver example uses `PathResolver` (path-based slug extraction) as it demonstrates a clear, different use case from the built-in resolvers
- `ConsoleResolver` documented explicitly as separate from the HTTP chain (`ConsoleCommandEvent` vs `kernel.request`) because it is a common point of confusion given its "N/A" priority

## Deviations from Plan

None — plan executed exactly as written. All acceptance criteria met. `mkdocs build --strict` exits 0 throughout.

## Issues Encountered

- Worktree was at `a181de2` (pre-phase-11 commit), not at the expected base `06e7fa9`. Rebased onto `06e7fa9` before starting — resolved automatically.
- `mkdocs` not on `$PATH`; used `python3 -m mkdocs` instead — worked correctly.
- After Task 2, `configuration.md` contained a forward link to `resolvers.md#custom-resolver` which produced an INFO-level message in mkdocs (not a build error). This resolved itself after Task 3 created the `#custom-resolver` section.

## Next Phase Readiness

- All 5 core user guide pages are complete with accurate, source-derived content
- Cross-page navigation links are in place
- `mkdocs build --strict` passes cleanly (exit 0)
- Phase 11 Plan 03 can proceed with database-per-tenant, shared-db, cache, messenger, and CLI commands pages

## Self-Check: PASSED

Files exist:
- FOUND: docs/user-guide/installation.md
- FOUND: docs/user-guide/getting-started.md
- FOUND: docs/user-guide/configuration.md
- FOUND: docs/user-guide/resolvers.md
- FOUND: docs/user-guide/strict-mode.md

Commits exist:
- FOUND: fadd893
- FOUND: 6f11e06
- FOUND: 9771f77

---
*Phase: 11-documentation-site*
*Completed: 2026-04-12*
