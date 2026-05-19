---
phase: 10-dependency-compatibility-audit
plan: "02"
subsystem: ci
tags: [ci, compatibility, prefer-lowest, no-messenger, documentation]
dependency_graph:
  requires: []
  provides: [prefer-lowest-ci-job, no-messenger-ci-job, updated-version-refs]
  affects: [.github/workflows/ci.yml, .planning/REQUIREMENTS.md, .planning/PROJECT.md]
tech_stack:
  added: []
  patterns: [prefer-lowest-testing, optional-dependency-guard-ci]
key_files:
  created: []
  modified:
    - .github/workflows/ci.yml
    - .planning/REQUIREMENTS.md
    - .planning/PROJECT.md
decisions:
  - "prefer-lowest job uses SYMFONY_REQUIRE=7.4.* to constrain Flex to the LTS floor while Composer resolves oldest stable versions of all other deps"
  - "no-messenger job excludes tests/Unit/Messenger (those tests require Messenger classes); includes DependencyInjection to verify compiler pass guard"
  - ".planning/ files are gitignored — disk changes made but cannot be committed; ci.yml changes are the committed artifact"
metrics:
  duration: "~2m 30s"
  completed_date: "2026-04-10"
  tasks_completed: 2
  files_changed: 3
---

# Phase 10 Plan 02: CI Matrix Expansion and Version Reference Cleanup Summary

**One-liner:** Expanded CI with prefer-lowest and no-messenger jobs to catch floor constraint violations and validate Messenger interface_exists guards; cleaned Symfony 6.4 references from planning docs.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Clean up Symfony 6.4 references in planning docs | (gitignored — disk only) | .planning/REQUIREMENTS.md, .planning/PROJECT.md |
| 2 | Add prefer-lowest and no-messenger CI jobs | 43c2cb7 | .github/workflows/ci.yml |

## What Was Built

### Task 1: Symfony 6.4 Reference Cleanup

Updated `.planning/REQUIREMENTS.md`:
- OSS-01: Changed `Symfony '^6.4|^7.0' constraints` to `Symfony '^7.4||^8.0' constraints`
- OSS-04: Changed `PHP 8.2/8.3/8.4 × Symfony 6.4/7.4 matrix` to `PHP 8.2/8.3/8.4 × Symfony 7.4/8.0 matrix`

Updated `.planning/PROJECT.md`:
- Context section: `Symfony 6.4+ / 7.x` → `Symfony 7.4+ / 8.x`
- Constraints section: `Symfony 6.4/7.x` → `Symfony 7.4/8.x`

Per D-07: Symfony 6.4 LTS is officially dropped; bundle supports Symfony 7.4+ and 8.x only.

### Task 2: CI Matrix Expansion

Added `prefer-lowest` job (D-09):
- PHP 8.2 (floor version), SYMFONY_REQUIRE='7.4.*', --prefer-lowest --prefer-stable
- Catches floor constraint violations by installing oldest compatible versions
- Runs full test suite to catch integration issues with old dependency versions

Added `no-messenger` job (D-10):
- PHP 8.2, removes symfony/messenger, runs all unit test dirs except Unit/Messenger
- Mirrors existing no-doctrine pattern to validate interface_exists guards
- Includes DependencyInjection to verify MessengerMiddlewarePass guard works

Verified `tests` job include entry for PHP 8.4 + Symfony 8.0.* is present (D-11 confirmed — DoctrineBundle 3.x resolved here since it requires PHP ^8.4).

## Deviations from Plan

### Auto-noted: .planning/ files are gitignored

**Found during:** Task 1 commit attempt
**Issue:** `.planning/` directory is in `.gitignore` (added via `chore: remove .claude/ and .planning/ from git tracking`). Changes to REQUIREMENTS.md and PROJECT.md cannot be committed to the worktree branch.
**Resolution:** Changes were applied to disk (correct content on filesystem) but not committed to git. This is by design — .planning/ files are local artifacts. The ci.yml commit (43c2cb7) is the committed artifact for this plan.
**Impact:** None on CI or runtime behavior; planning docs updated correctly on disk.

## Known Stubs

None — this plan produces CI configuration and documentation updates, no application code stubs.

## Threat Surface Scan

No new network endpoints, auth paths, file access patterns, or schema changes introduced. The new CI jobs reduce the threat surface by validating optional-dependency guard completeness.

## Verification Results

```
grep -c 'prefer-lowest' .github/workflows/ci.yml  → 2 (job name + composer-options)
grep -c 'no-messenger' .github/workflows/ci.yml   → 1 (job name)
grep "symfony: '8.0" .github/workflows/ci.yml      → symfony: '8.0.*' (preserved)
grep '6\.4' .planning/REQUIREMENTS.md              → (empty — 0 matches)
grep '6\.4' .planning/PROJECT.md                   → (empty — 0 matches)
```

## Self-Check: PASSED

- `.github/workflows/ci.yml` modified with prefer-lowest and no-messenger jobs: FOUND
- Commit 43c2cb7 exists: FOUND (`feat(10-02): add prefer-lowest and no-messenger CI jobs`)
- `.planning/REQUIREMENTS.md` updated on disk (no Symfony 6.4 references): VERIFIED
- `.planning/PROJECT.md` updated on disk (no Symfony 6.4 references): VERIFIED
