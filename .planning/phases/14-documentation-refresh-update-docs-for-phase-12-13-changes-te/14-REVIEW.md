---
phase: 14-documentation-refresh
reviewed: 2026-04-14T12:00:00Z
depth: standard
files_reviewed: 12
files_reviewed_list:
  - src/Command/TenantInitCommand.php
  - composer.json
  - README.md
  - docs/user-guide/installation.md
  - docs/user-guide/cli-commands.md
  - docs/user-guide/configuration.md
  - docs/user-guide/cache-isolation.md
  - docs/user-guide/resolvers.md
  - docs/user-guide/database-per-tenant.md
  - docs/architecture/di-compilation.md
  - docs/index.md
  - docs/user-guide/index.md
findings:
  critical: 0
  warning: 2
  info: 2
  total: 4
status: issues_found
---

# Phase 14: Code Review Report

**Reviewed:** 2026-04-14T12:00:00Z
**Depth:** standard
**Files Reviewed:** 12
**Status:** issues_found

## Summary

Phase 14 makes documentation-only changes (plus one source fix in `TenantInitCommand.php`). The core changes are well-executed: Flex references are consistently replaced with `tenancy:init`, `cache_prefix_separator` default corrected from `:` to `.` across all docs and the YAML template, `flex/` directory removed, `extra.symfony` block removed from `composer.json`, and the `tenancy:init` section added to `cli-commands.md` is thorough and accurate.

The one source code change (TenantInitCommand.php line 113: `':'` to `'.'`) correctly aligns the generated YAML comment with the actual default in `TenancyBundle::configure()` (`->defaultValue('.')`).

Two warnings found: one documentation inaccuracy in `di-compilation.md` (ConsoleResolver incorrectly listed with `tenancy.resolver` tag and priority 5), and stale Doctrine version ranges in `installation.md`.

## Warnings

### WR-01: ConsoleResolver incorrectly listed in ResolverChainPass priority table

**File:** `docs/architecture/di-compilation.md:138`
**Issue:** The "Built-in resolver priorities" table lists `ConsoleResolver` with priority 5 and source `tenancy.resolver` tag. However, `ConsoleResolver` is NOT tagged with `tenancy.resolver` in `config/services.php`. It is registered as an event listener via `#[AsEventListener(event: ConsoleEvents::COMMAND)]` and `autoconfigure(true)` -- it does not participate in the `ResolverChain` at all. The other documentation files (resolvers.md) correctly describe ConsoleResolver's priority as "N/A" and state it is not part of the HTTP resolver chain. This table entry contradicts both the source code and the rest of the docs.
**Fix:** Remove the ConsoleResolver row from the table, or change it to indicate it is not part of the resolver chain:

```markdown
| Resolver | Priority | Source |
|----------|----------|--------|
| `HostResolver` | 30 | `tenancy.resolver` tag |
| `HeaderResolver` | 20 | `tenancy.resolver` tag |
| `QueryParamResolver` | 10 | `tenancy.resolver` tag |
```

Note: `ConsoleResolver` is not tagged `tenancy.resolver` -- it operates as a `ConsoleCommandEvent` listener independent of the resolver chain.

### WR-02: Stale Doctrine version ranges in installation requirements table

**File:** `docs/user-guide/installation.md:75-79`
**Issue:** The "Requirements Summary" table lists Doctrine dependency versions that do not match `composer.json` `require-dev`:

| Package | Docs claim | composer.json |
|---------|-----------|---------------|
| `doctrine/orm` | `^2.17` or `^3.0` | `^3.3` |
| `doctrine/dbal` | `^3.6` or `^4.0` | `^4.4` |
| `doctrine/doctrine-bundle` | `^2.11` | `^2.13\|\|^3.0` |
| `doctrine/migrations` | `^3.7` | `^3.9` |

The docs advertise broader version support (ORM 2.x, DBAL 3.x) that is not tested in CI and may not actually work. The `suggest` block in composer.json lists narrower ranges (`^4.4`, `^3.3`, `^2.13||^3.0`, `^3.9`) that should be the authoritative source.

**Fix:** Update the table to match the `suggest` block in `composer.json`:

```markdown
| doctrine/orm *(optional)* | `^3.3` |
| doctrine/dbal *(optional)* | `^4.4` |
| doctrine/doctrine-bundle *(optional)* | `^2.13` or `^3.0` |
| doctrine/migrations *(optional)* | `^3.9` |
```

## Info

### IN-01: installation.md verification example shows tenancy.context as non-public

**File:** `docs/user-guide/installation.md:101`
**Issue:** The verification output example shows `Public: no` for the `tenancy.context` service. However, `config/services.php` line 31 registers it with `->public()`. The expected output would show `Public: yes`. This is a pre-existing inaccuracy not introduced in this phase, but worth noting since this page was modified.
**Fix:** Change the verification example to show `Public: yes`.

### IN-02: di-compilation.md ResolverChainPass code block omits FQCN fallback branch

**File:** `docs/architecture/di-compilation.md:96-122`
**Issue:** The simplified code block for `ResolverChainPass::process()` omits the `elseif (class_exists($name) || interface_exists($name))` branch (actual source line 44-46) that handles FQCN-based resolver names in the config. The code is labeled "(simplified)" so this is not strictly wrong, but readers implementing custom resolvers by FQCN in the config list would not understand why it works from this code alone.
**Fix:** Either add the branch or add a comment noting the omission: `// (simplified — also handles FQCN entries in the resolvers config)`.

---

_Reviewed: 2026-04-14T12:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
