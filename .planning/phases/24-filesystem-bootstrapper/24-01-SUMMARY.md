---
plan: 24-01
phase: 24-filesystem-bootstrapper
title: TenantFilesystemConfigTrait + AbstractTenant.filesystemConfig column
status: complete
shipped: 2026-05-30
commits: [ff77357]
files_modified:
  - src/Filesystem/TenantFilesystemConfigTrait.php
  - src/Entity/AbstractTenant.php
  - tests/Unit/Filesystem/TenantFilesystemConfigTraitTest.php
requirements: [BOOT-03]
test_count_delta: +8 tests / +15 assertions (trait test stub → 8 passing tests)
deviations:
  - "Parallel-agent contention during Wave 1 caused this plan's initial executor to mark EXECUTION BLOCKED. The trait code, AbstractTenant edits, and test bodies were preserved in the working tree; orchestrator committed atomically after Wave 1 serialization."
---

# Plan 24-01 Summary

**Goal:** Ship `TenantFilesystemConfigTrait` (matching the Phase 20 `TenantMailerConfigTrait` pattern) and add a nullable `filesystemConfig` JSON column to `AbstractTenant`. Zero BC break for downstream users with custom Tenant entities.

## What Shipped

- **`src/Filesystem/TenantFilesystemConfigTrait.php`** — 63 LOC. Provides `getFilesystemConfig(): ?array` + `setFilesystemConfig(?array): static`. Returns nullable per DEC-FILE-CONFIG. Documented return shape `{prefix?, adapter_dsn?, services?}` but does NOT validate here — validation lives downstream (Plans 24-06/24-07).
- **`src/Entity/AbstractTenant.php`** — +22 LOC. Adds inlined `filesystemConfig` nullable JSON column after `mailerReplyTo` + before `createdAt`. Matches Phase 20 commenting style: users with custom Tenant entities can `use TenantFilesystemConfigTrait;` instead — both routes identical at the Doctrine layer.
- **`tests/Unit/Filesystem/TenantFilesystemConfigTraitTest.php`** — replaced the 24-00 stub with 8 behavioral tests:
  - default-null returns
  - prefix-only round-trip
  - adapter_dsn + services round-trip
  - null reset clears state
  - unknown-key permissive (RESEARCH Q5)
  - fluent setter (chainable on static return)
  - Doctrine `#[ORM\Column]` attribute reflection check
  - private property shape reflection

## Anti-Pattern Guards Applied

- ✅ No same-namespace `use` statements (cs-fixer @Symfony strips them via `no_unused_imports`)
- ✅ No optional-before-required ctor params (trait has no constructor)
- ✅ FQCN used for same-namespace cross-references in docblock examples

## Test Suite State

- Before: 579 tests / 2122 assertions (7 incomplete from 24-00 stubs)
- After: 617 tests / 2203 assertions (7 incomplete — the trait test is now complete)

Pre-commit hook (php-cs-fixer + PHPStan level 9 + PHPUnit 617/2203) passed on commit `ff77357`.

## Parallel-Agent Contention Note

Wave 1 of Phase 24 spawned 4 agents in parallel (24-01, 24-02, 24-03, 24-04) against the same git working tree (no worktree isolation). 24-03 + 24-04 landed clean via creative atomic-add-and-commit serialization; 24-01 + 24-02 hit race conditions where peer agents' `git reset` calls cleared the index between `git add` and `git commit`. The work was preserved in the working tree; orchestrator finalized the commits sequentially.

**Lesson for downstream Wave 2+:** serialize, OR use Agent tool's `isolation: "worktree"` mode.

## Next

Wave 2 (Plans 24-05, 24-06 decorators) depends on this work + 24-02, 24-03, 24-04. All Wave 1 deliverables shipped.

---
_Shipped: 2026-05-30 (post-race recovery by orchestrator)_
