---
phase: quick
plan: 260410-llx
status: complete
subsystem: core
tags: [symfony-version, composer, ci, cache, intersection-types]
dependency_graph:
  requires: []
  provides: [symfony-7-8-support]
  affects: [composer.json, ci-matrix, TenantAwareCacheAdapter, README, CLAUDE.md]
tech_stack:
  added: []
  patterns: [intersection-types]
key_files:
  created: []
  modified:
    - composer.json
    - .github/workflows/ci.yml
    - src/Cache/TenantAwareCacheAdapter.php
    - README.md
    - CLAUDE.md
decisions:
  - Drop Symfony 6.4 support; minimum is now ^7.0 enabling PHP 8.2+ intersection types without runtime guards
  - CI matrix covers Symfony 7.4 and 8.0 (was 6.4 and 7.4)
metrics:
  duration: ~5 min
  completed: "2026-04-09"
  tasks: 3
  files: 5
---

# Quick Task 260410-llx: Drop Symfony 6.4 Support, Target ^7.0||^8.0 Summary

**One-liner:** Dropped Symfony 6.4 support across composer.json, CI matrix, TenantAwareCacheAdapter, and docs — enabling clean PHP 8.2 intersection types with no runtime instanceof guards.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Update composer.json and CI matrix | 657f107 | composer.json, .github/workflows/ci.yml |
| 2 | Clean TenantAwareCacheAdapter intersection type | 8ef6d56 | src/Cache/TenantAwareCacheAdapter.php |
| 3 | Update documentation references | 3ce05a9 | README.md, CLAUDE.md |

## What Was Done

**Task 1 — composer.json and CI matrix:**
- All 8 `symfony/*` entries in `require` changed from `^6.4||^7.0` to `^7.0||^8.0`
- All 3 `symfony/*` entries in `require-dev` changed from `^6.4||^7.0` to `^7.0||^8.0`
- `suggest` block messenger description updated to `^7.0||^8.0`
- CI matrix `symfony` row changed from `['6.4.*', '7.4.*']` to `['7.4.*', '8.0.*']`

**Task 2 — TenantAwareCacheAdapter:**
- Class declaration extended: `implements AdapterInterface, NamespacedPoolInterface`
- Constructor `$inner` parameter type changed to intersection `AdapterInterface&NamespacedPoolInterface`
- `pool()` return type changed to `AdapterInterface&NamespacedPoolInterface`
- Removed `$this->inner instanceof NamespacedPoolInterface` runtime guard — condition was guarding a type already enforced by the constructor parameter type

**Task 3 — Docs:**
- README.md requirements: `^6.4` or `^7.0` → `^7.0` or `^8.0`
- CLAUDE.md project description: Symfony 6.4/7.x → Symfony 7.x/8.x
- CLAUDE.md stack entry: Symfony 6.4 / 7.x → Symfony 7.x / 8.x
- CLAUDE.md CI entry: 6.4/7.4 matrix → 7.4/8.0 matrix

## Verification Results

1. `grep -rn '6\.4' composer.json .github/workflows/ci.yml src/ README.md CLAUDE.md CONTRIBUTING.md` — no matches
2. `vendor/bin/phpunit tests/Unit/Cache/TenantAwareCacheAdapterTest.php` — 7/7 tests pass, 23 assertions
3. `vendor/bin/phpstan analyse src/Cache/TenantAwareCacheAdapter.php` — No errors (level 9)
4. All symfony/* constraints show `^7.0||^8.0`
5. No `instanceof NamespacedPoolInterface` in src/Cache/TenantAwareCacheAdapter.php

## Deviations from Plan

None — plan executed exactly as written.

## Known Stubs

None.

## Threat Flags

None — no new network endpoints, auth paths, file access patterns, or schema changes introduced.

## Self-Check: PASSED

- composer.json: FOUND
- .github/workflows/ci.yml: FOUND
- src/Cache/TenantAwareCacheAdapter.php: FOUND
- README.md: FOUND
- CLAUDE.md: FOUND
- Commit 657f107: FOUND
- Commit 8ef6d56: FOUND
- Commit 3ce05a9: FOUND
