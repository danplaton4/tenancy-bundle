---
phase: 14-documentation-refresh
plan: "02"
subsystem: documentation
tags: [docs, cli, cache, resolvers, di-compilation, database-per-tenant]
dependency_graph:
  requires: []
  provides:
    - docs/user-guide/cli-commands.md (tenancy:init documented with --force, Doctrine detection, output examples)
    - docs/user-guide/configuration.md (cache_prefix_separator default corrected to '.')
    - docs/user-guide/cache-isolation.md (separator examples corrected to '.')
    - docs/user-guide/resolvers.md (custom resolver pass-through note added)
    - docs/user-guide/database-per-tenant.md (EntityManagerResetListener per-driver EM targeting)
    - docs/architecture/di-compilation.md (ResolverChainPass filtering code, TenantInitCommand in table)
    - src/Command/TenantInitCommand.php (YAML template separator fixed to '.')
  affects:
    - User documentation accuracy for v1.0 shipped codebase
tech_stack:
  added: []
  patterns:
    - Documentation verified against source-of-truth source files before writing
key_files:
  created: []
  modified:
    - docs/user-guide/cli-commands.md
    - docs/user-guide/configuration.md
    - docs/user-guide/cache-isolation.md
    - docs/user-guide/resolvers.md
    - docs/user-guide/database-per-tenant.md
    - docs/architecture/di-compilation.md
    - src/Command/TenantInitCommand.php
decisions:
  - Documentation verified against TenantAwareCacheAdapter.php (line 18, separator '.'), ResolverChainPass.php (BUILT_IN_RESOLVER_MAP), EntityManagerResetListener.php (managersToReset), and TenantInitCommand.php source before writing
metrics:
  duration: ~8 minutes
  completed: "2026-04-14"
  tasks_completed: 4
  files_modified: 7
---

# Phase 14 Plan 02: Documentation Refresh (Phase 12/13 Changes) Summary

Documentation accuracy fix covering Phase 12 (tenancy:init command) and Phase 13 (resolver config filtering, cache separator default, EntityManagerResetListener per-driver targeting) changes — updated six documentation files and one source file to match as-shipped v1.0 behavior.

## Tasks Completed

| Task | Description | Commit | Files |
|------|-------------|--------|-------|
| 1 | Add tenancy:init section to cli-commands.md | 6bf60cc | docs/user-guide/cli-commands.md |
| 2 | Fix cache_prefix_separator default in configuration.md and cache-isolation.md | 1b1606f | docs/user-guide/configuration.md, docs/user-guide/cache-isolation.md |
| 3 | Update resolvers.md, database-per-tenant.md, and di-compilation.md | ecf53ba, 20294cb | docs/user-guide/resolvers.md, docs/user-guide/database-per-tenant.md, docs/architecture/di-compilation.md |
| 4 | Fix stale cache_prefix_separator in TenantInitCommand YAML template | 058bd9c | src/Command/TenantInitCommand.php |

## What Was Done

**Task 1 — cli-commands.md:** Updated opening paragraph from "two" to "three console commands". Added full `## tenancy:init` section documenting `--force` flag, Doctrine detection behavior, overwrite protection, and output examples for both Doctrine-detected and not-detected cases. Added Installation link to See Also section.

**Task 2 — configuration.md and cache-isolation.md:** Fixed four stale `:` occurrences: the table default, prose example (`acme.user.123`), YAML Full Example block, and PHP Full Example block. Fixed two stale examples in cache-isolation.md namespace isolation code block (tenant-scoped lines now show `.` separator). The "No tenant active" line retained its `:` (that's the Symfony cache namespace delimiter, not the tenancy separator).

**Task 3 — resolvers.md, database-per-tenant.md, di-compilation.md:**
- resolvers.md: Added "Custom resolvers always pass through" admonition explaining that the `tenancy.resolvers` config list only filters built-in resolvers; custom resolvers implementing `TenantResolverInterface` always pass through.
- database-per-tenant.md: Replaced EntityManagerResetListener section to distinguish per-driver behavior — `database_per_tenant` mode resets only the `tenant` EM via `resetManager('tenant')`; `shared_db` / single-EM mode resets the default EM via `resetManager(null)`. Updated warning admonition to specify "tenant EM" and note that the `landlord` EM is not affected.
- di-compilation.md: Replaced the old unconditional-loop ResolverChainPass code block with the real implementation showing `BUILT_IN_RESOLVER_MAP` and `allowedFqcns` filtering logic. Added explanation paragraph about custom resolver pass-through. Added `TenantInitCommand` row to the Always Registered services table.

**Task 4 — TenantInitCommand.php:** Fixed YAML template comment from `cache_prefix_separator: ':'` to `cache_prefix_separator: '.'` to align with `TenantAwareCacheAdapter.php` line 18 (actual default `'.'`).

## Deviations from Plan

### Auto-fixed Issues

None — plan executed exactly as written.

### Deferred Items

**Out-of-scope stale separator found during Task 2 verification:**
- **File:** `docs/user-guide/installation.md`
- **Issue:** `cache_prefix_separator: ':'` appears in that file (not in this plan's `files_modified` list)
- **Action:** Not fixed — out of scope for this plan. Logged to deferred items.

## Known Stubs

None — all documentation sections are fully written with real content derived from source files.

## Threat Flags

None — documentation-only changes, no new code execution paths, no secrets, no network endpoints introduced.

## Self-Check: PASSED

Files exist:
- docs/user-guide/cli-commands.md: FOUND
- docs/user-guide/configuration.md: FOUND
- docs/user-guide/cache-isolation.md: FOUND
- docs/user-guide/resolvers.md: FOUND
- docs/user-guide/database-per-tenant.md: FOUND
- docs/architecture/di-compilation.md: FOUND
- src/Command/TenantInitCommand.php: FOUND

Commits exist:
- 6bf60cc: docs(14-02): add tenancy:init section to cli-commands.md
- 1b1606f: docs(14-02): fix cache_prefix_separator default to '.'
- ecf53ba: docs(14-02): update resolvers.md and database-per-tenant.md
- 20294cb: docs(14-02): update di-compilation.md
- 058bd9c: fix(14-02): fix stale cache_prefix_separator in TenantInitCommand YAML template

Verification results:
- 3 tenancy: commands documented in cli-commands.md: PASS
- No stale ':' separator in task-scope docs: PASS
- BUILT_IN_RESOLVER_MAP in di-compilation.md: PASS
- tenancy.command.init in di-compilation.md table: PASS
- resetManager('tenant') in database-per-tenant.md: PASS
- Custom resolvers always pass through in resolvers.md: PASS
- cache_prefix_separator: '.' in TenantInitCommand.php: PASS
- Unit tests (203/203): PASS
