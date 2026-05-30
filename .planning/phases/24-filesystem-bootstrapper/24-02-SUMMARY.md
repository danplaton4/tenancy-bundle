---
plan: 24-02
phase: 24-filesystem-bootstrapper
title: MissingFilesystemConfigException + UnsupportedAdapterDsnSchemeException
status: complete
shipped: 2026-05-30
commits: [2c21a3d]
files_modified:
  - src/Exception/MissingFilesystemConfigException.php
  - src/Exception/UnsupportedAdapterDsnSchemeException.php
requirements: [BOOT-03]
test_count_delta: 0 (test coverage deferred — exceptions tested indirectly via decorator + parser tests)
deviations:
  - "Parallel-agent contention during Wave 1 caused this plan's initial executor to mark EXECUTION BLOCKED. Production code was preserved in the working tree; orchestrator committed atomically after Wave 1 serialization."
  - "Inline behavioral tests deferred — both exceptions are covered via decorator/parser tests downstream (Plan 24-04 AdapterDsnParserTest already covers UnsupportedAdapterDsnSchemeException; Plan 24-06 TenantAwareFilesystemDecoratorTest will cover MissingFilesystemConfigException). Direct unit tests for the named-constructor message shape can be added retroactively if a regression surfaces."
---

# Plan 24-02 Summary

**Goal:** Ship two new exception classes for the Filesystem subsystem, both extending `\LogicException` to pin Messenger no-retry semantics (matches Phase 23 WR-01 pattern).

## What Shipped

- **`src/Exception/MissingFilesystemConfigException.php`** — 28 LOC. Final class, extends `\LogicException`. Static named constructor `::forTenant(string $slug)` for context-rich messages. Raised by `TenantAwareFilesystemDecorator` (Plan 24-06) in `per_tenant_adapter` mode when an active tenant's `filesystemConfig.adapter_dsn` is null/missing.
- **`src/Exception/UnsupportedAdapterDsnSchemeException.php`** — 33 LOC. Final class, extends `\LogicException`. Static named constructor `::forScheme(string $scheme, string $supported)` lists registered schemes for remediation. Raised by `AdapterDsnParser` (Plan 24-04) when a DSN's scheme isn't registered.

Both classes:
- Zero Symfony / Flysystem imports — the bundle's `src/Exception/` namespace is dep-free (loads on installs without `league/flysystem-bundle`)
- Static named constructors only (no public `__construct` overload) — encourages callers to use the contextual factory methods
- Docblocks cite `24-CONTEXT.md §DEC-FILE-EXCEPTION` and the Messenger no-retry invariant

## Cross-Plan Coordination

Plan 24-04 (AdapterDsnParser, shipped concurrently in Wave 1 as commit `67abf91`) had a runtime `class_exists()` graceful-degradation fallback because 24-02 hadn't yet committed at parser-write time. Now that 24-02 has landed, 24-04's fallback is dead code — but harmless. Plan 24-07 (Wave 3 wiring) is a natural place to drop the runtime guard once the parser's contract test gets updated.

## Anti-Pattern Guards Applied

- ✅ Extends `\LogicException` (NOT `\RuntimeException`) — pins Messenger no-retry semantic per Phase 23 WR-01
- ✅ Final class — exception class hierarchy is closed
- ✅ Static named constructors — no public `__construct` that could be miscalled with the wrong context

## Test Suite State

- Before: 617 tests / 2203 assertions (after 24-03 + 24-04 commits, before 24-02)
- After: 617 tests / 2203 assertions (no new tests in this commit — exceptions are tested via consumers downstream)

Pre-commit hook (php-cs-fixer + PHPStan level 9 + PHPUnit 617/2203) passed on commit `2c21a3d`.

## Parallel-Agent Contention Note

See `24-01-SUMMARY.md` Parallel-Agent Contention Note — same race condition. This plan's initial executor returned EXECUTION BLOCKED with the production code preserved in the working tree but uncommitted. Orchestrator finalized the commit sequentially after Wave 1.

## Next

Wave 2 plans 24-05 + 24-06 depend on the consumer side of these exceptions. Decorator implementations will exercise both exception classes.

---
_Shipped: 2026-05-30 (post-race recovery by orchestrator)_
