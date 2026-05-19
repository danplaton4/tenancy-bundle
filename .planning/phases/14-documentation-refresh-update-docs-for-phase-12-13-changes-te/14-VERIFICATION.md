---
phase: 14-documentation-refresh
verified: 2026-04-14T00:00:00Z
status: passed
score: 19/19 must-haves verified
overrides_applied: 0
---

# Phase 14: Documentation Refresh Verification Report

**Phase Goal:** Remove all Symfony Flex artifacts (flex/ directory, extra.symfony in composer.json) and references from documentation. Update all docs to reflect Phase 12 (tenancy:init command as primary setup path) and Phase 13 (resolver config filtering, cache_prefix_separator default change to '.', EntityManagerResetListener EM targeting). Fix stale passages across seven files, add the tenancy:init command section, and update the DI Compilation architecture doc.
**Verified:** 2026-04-14
**Status:** passed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths (Plan 01 — Flex Removal)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | The flex/ directory does not exist in the repository | VERIFIED | `test ! -d flex/` confirms directory absent |
| 2 | composer.json has no extra.symfony block | VERIFIED | `extra` block contains only `branch-alias`; "symfony" appears only in `keywords` array (expected) |
| 3 | No documentation page mentions Symfony Flex | VERIFIED | grep -rqi 'flex' docs/ returns zero matches |
| 4 | installation.md has no tab-based With Flex / Without Flex layout | VERIFIED | No occurrence of "With Flex", "Without Flex", or `===` tab syntax in file |
| 5 | installation.md presents a single standard installation path | VERIFIED | Single `## 2. Bundle Registration` section without tab groups |
| 6 | installation.md mentions tenancy:init as the recommended setup method | VERIFIED | Lines 46-49: `!!! tip "Or use tenancy:init"` admonition with cli-commands.md#tenancyinit cross-reference |
| 7 | index.md comparison table has no Flex row | VERIFIED | Line 125: `` `tenancy:init` scaffolding `` row replaces Flex recipe row |
| 8 | README.md comparison table has no Flex row | VERIFIED | Line 98: `` `tenancy:init` scaffolding `` row replaces Flex recipe row |
| 9 | cache_prefix_separator default shown as '.' not ':' in installation.md | VERIFIED | Line 34: `cache_prefix_separator: '.'` |

**Plan 01 Score:** 9/9 truths verified

### Observable Truths (Plan 02 — Phase 12/13 Doc Accuracy)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | cli-commands.md documents three commands: tenancy:init, tenancy:migrate, and tenancy:run | VERIFIED | `grep -c '^## tenancy:' cli-commands.md` returns 3; line 3 says "three console commands" |
| 2 | cli-commands.md tenancy:init section documents --force flag, Doctrine detection, overwrite protection, and next-steps output | VERIFIED | Lines 19, 26-28, 34 confirm --force, overwrite protection, Doctrine detection, and "No dependencies" statement; lines 43/60 show output examples for both cases |
| 3 | configuration.md shows cache_prefix_separator default as '.' not ':' | VERIFIED | Line 73 table row shows `.`; no `cache_prefix_separator: ':'` exists anywhere in docs/ |
| 4 | configuration.md example shows 'acme.user.123' not 'acme:user.123' | VERIFIED | Line 75 prose: "becomes `acme.user.123`" |
| 5 | cache-isolation.md namespace examples use '.' separator not ':' | VERIFIED | Lines 51-52: `app/acme.my_key` and `app/demo.my_key`; "No tenant active" line correctly retains `:` (Symfony namespace delimiter) |
| 6 | resolvers.md documents that custom resolvers always pass through the config filter | VERIFIED | Line 187-190: admonition "Custom resolvers always pass through" with explanation that built-in filter does not apply |
| 7 | database-per-tenant.md EntityManagerResetListener section specifies which EM is reset per driver mode | VERIFIED | Lines 206-208: database_per_tenant resets `tenant` EM via `resetManager('tenant')`; shared_db resets default via `resetManager(null)`; line 207 states "landlord EM is never reset" |
| 8 | di-compilation.md ResolverChainPass code block shows the config filtering logic | VERIFIED | Lines 96-117: `$allowedFqcns`, `BUILT_IN_RESOLVER_MAP` filtering, custom resolver pass-through comment all present |
| 9 | di-compilation.md always-registered services table includes TenantInitCommand | VERIFIED | Line 260: `| tenancy.command.init | TenantInitCommand | Scaffolds config/packages/tenancy.yaml |` |
| 10 | TenantInitCommand.php YAML template shows cache_prefix_separator: '.' not ':' | VERIFIED | Line 113: `"    # cache_prefix_separator: '.',"` |

**Plan 02 Score:** 10/10 truths verified

**Overall Score:** 19/19 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `docs/user-guide/installation.md` | Single installation path with tenancy:init tip | VERIFIED | Single path, tip admonition at lines 46-49, cross-reference to cli-commands.md#tenancyinit |
| `docs/index.md` | Landing page with no Flex references, tenancy:init as setup path | VERIFIED | No flex; tenancy:init at lines 22, 75, 125 |
| `docs/user-guide/index.md` | User guide index with no Flex reference in Installation description | VERIFIED | Line 9: "bundle registration, tenancy:init" |
| `README.md` | README with no Flex references, tenancy:init as setup path | VERIFIED | No flex; tenancy:init at lines 21, 54, 98 |
| `composer.json` | extra.symfony block removed, branch-alias preserved | VERIFIED | Only `branch-alias` in `extra` block |
| `docs/user-guide/cli-commands.md` | Complete CLI reference including tenancy:init command | VERIFIED | Three sections, full tenancy:init documentation |
| `docs/user-guide/configuration.md` | Configuration reference with correct cache_prefix_separator default ('.') | VERIFIED | Table, prose, YAML, and PHP examples all use '.' |
| `docs/user-guide/cache-isolation.md` | Cache isolation docs with correct separator examples | VERIFIED | Tenant-scoped lines use '.' separator |
| `docs/user-guide/resolvers.md` | Resolver docs with custom resolver pass-through note | VERIFIED | Admonition added at line 187 |
| `docs/user-guide/database-per-tenant.md` | Database driver docs with scoped EM reset description | VERIFIED | Per-driver behavior documented, landlord EM noted as never reset |
| `docs/architecture/di-compilation.md` | DI compilation reference with updated ResolverChainPass and TenantInitCommand | VERIFIED | Filtering code block, explanation paragraph, TenantInitCommand service row |
| `src/Command/TenantInitCommand.php` | Correct cache_prefix_separator default in YAML template | VERIFIED | Line 113: `# cache_prefix_separator: '.'` |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `docs/user-guide/installation.md` | `docs/user-guide/cli-commands.md` | tenancy:init cross-reference | WIRED | Line 49: `[CLI Commands](cli-commands.md#tenancyinit)` |
| `docs/user-guide/cli-commands.md` | `src/Command/TenantInitCommand.php` | documents the command behavior | WIRED | --force, Doctrine detection, overwrite protection all match TenantInitCommand.php implementation |
| `docs/architecture/di-compilation.md` | `src/DependencyInjection/Compiler/ResolverChainPass.php` | shows simplified source code with BUILT_IN_RESOLVER_MAP | WIRED | Lines 96-117 in di-compilation.md show BUILT_IN_RESOLVER_MAP filtering matching actual compiler pass |

### Data-Flow Trace (Level 4)

Not applicable — this is a documentation-only phase. No dynamic data rendering components are introduced. `src/Command/TenantInitCommand.php` source change (line 113) is a string literal fix, not a data flow concern.

### Behavioral Spot-Checks

| Behavior | Check | Result | Status |
|----------|-------|--------|--------|
| flex/ directory absent | `test ! -d flex/` | Exit 0 | PASS |
| composer.json extra.symfony removed | No `"symfony"` key inside `"extra"` block | Only `branch-alias` in extra | PASS |
| No flex references in any doc | `grep -rqi 'flex' docs/` | Zero matches | PASS |
| tenancy:init in all 4 onboarding docs | `grep -l 'tenancy:init' installation.md index.md user-guide/index.md README.md | wc -l` | 4 | PASS |
| No stale colon separator in docs | `grep -rn "cache_prefix_separator: ':'" docs/` | Zero matches | PASS |
| TenantInitCommand.php uses dot separator | Line 113 of TenantInitCommand.php | `# cache_prefix_separator: '.'` | PASS |
| cli-commands.md has 3 tenancy: sections | `grep -c '^## tenancy:' cli-commands.md` | 3 | PASS |

### Requirements Coverage

| Requirement | Source Plans | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| DOC-REFRESH | 14-01-PLAN.md, 14-02-PLAN.md | Documentation refresh for Phase 12/13 changes, Flex removal | SATISFIED | All 19 must-haves verified across both plans |

**Note on DOC-REFRESH:** This ID appears in ROADMAP.md and both PLANs but is NOT defined in REQUIREMENTS.md. It is an informal tracking label used exclusively in Phase 14. There are no REQUIREMENTS.md IDs assigned to Phase 14 in the traceability table.

**Note on REQUIREMENTS.md consistency:** OSS-01 (line 54) still specifies "correct `extra.symfony` bundle configuration" and OSS-03 (line 56) marks the "Flex recipe" as complete — both are now stale given the deliberate Flex removal in Phase 14. Neither was updated in REQUIREMENTS.md to reflect this decision. The `feedback_no_flex.md` project memory documents the intentional decision to drop Flex. This is a requirements traceability debt, not a Phase 14 gap.

### Anti-Patterns Found

No blockers or warnings detected:

- No TODOs, FIXMEs, or placeholder text introduced
- No stub implementations
- All documentation changes are substantive and reference-accurate
- No "coming soon" or placeholder sections
- The `"symfony"` string in composer.json `keywords` array (line 6) is expected and unrelated to the removed `extra.symfony` block

### Human Verification Required

None. All phase goals are verifiable programmatically. Documentation content accuracy was verified against source-of-truth PHP files:

- `cache_prefix_separator: '.'` verified against `src/Cache/TenantAwareCacheAdapter.php` (actual default)
- `resetManager('tenant')` behavior verified against `src/EventListener/EntityManagerResetListener.php`
- `BUILT_IN_RESOLVER_MAP` filtering verified against `src/DependencyInjection/Compiler/ResolverChainPass.php`
- `tenancy:init` command behavior (--force, Doctrine detection, overwrite protection) verified against `src/Command/TenantInitCommand.php`

### Gaps Summary

No gaps. All 19 must-haves from both PLAN frontmatter files are satisfied. The flex/ directory is removed, composer.json is clean, all documentation is Flex-free and promotes tenancy:init, separator defaults are corrected throughout, and all Phase 13 behavioral changes are accurately documented.

---

_Verified: 2026-04-14_
_Verifier: Claude (gsd-verifier)_
