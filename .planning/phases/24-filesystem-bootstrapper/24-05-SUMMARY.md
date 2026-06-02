---
phase: 24-filesystem-bootstrapper
plan: 05
subsystem: filesystem
tags: [flysystem, decorator, prefix-mode, filesystem-operator, path-prefixer, tenant-isolation]

requires:
  - phase: 24-filesystem-bootstrapper-00
    provides: FilesystemPrefixingDecoratorTest stub, TenantFilesystemConfigTrait, LruFilesystemCache, InMemoryFilesystemAdapter available

provides:
  - FilesystemPrefixingDecorator — final class implementing FilesystemOperator 18-method interface + 3 @method extras (publicUrl/temporaryUrl/checksum)
  - Live-read prefix routing: PathPrefixer constructed fresh per call from TenantContext (no instance state)
  - listContents() returns tenant-relative paths (Q1 prefix-stripping)
  - publicUrl/temporaryUrl/checksum forward prefixed path to inner (Q2 accept)
  - FilesystemPrefixingDecoratorTest — 21 tests covering all behaviours including cross-tenant context-switch pin and reflection no-mutable-state pin

affects: [24-06, 24-07, 24-08, 24-09, filesystem-contract-pass, integration-tests]

tech-stack:
  added: []
  patterns:
    - "Live-read TenantContext per call: prefixer() method constructs fresh PathPrefixer every invocation — never cached in instance state (mirrors TenantAwareTransportsDecorator)"
    - "Closure-captured prefixer snapshot in listContents generator: outer method reads TenantContext once, passes immutable PathPrefixer to generator so prefix is consistent per listing call"
    - "Q1 prefix-stripping: listContents yields new FileAttributes/DirectoryAttributes with PathPrefixer::stripPrefix/stripDirectoryPrefix applied to return paths"
    - "Q2 accept: publicUrl/temporaryUrl/checksum forward prefixed path verbatim (documented as inherent property of prefix mode)"

key-files:
  created:
    - src/Filesystem/FilesystemPrefixingDecorator.php
    - tests/Unit/Filesystem/FilesystemPrefixingDecoratorTest.php
  modified: []

key-decisions:
  - "publicUrl/temporaryUrl/checksum are @method annotations on FilesystemOperator (not real interface methods in Flysystem 3.34.0) — implemented as extra public methods on the decorator; PHPStan level 9 accepts calls on FilesystemOperator via the @method stubs without error"
  - "Tests for publicUrl/temporaryUrl/checksum mock Filesystem (concrete class) not FilesystemOperator — PHPUnit cannot configure @method-annotated methods on interface mocks"
  - "listContents generator captures PathPrefixer instance outside the closure so the prefix is a single immutable snapshot per listing call — safe because outer method already read TenantContext live"

patterns-established:
  - "FilesystemOperator decorator shape: final class, 3 readonly ctor properties, single prefixer() read-point"
  - "FilesystemPrefixingDecoratorTest fixture() helper returns [decorator, inner, adapter, context] for compact per-test setup"

requirements-completed: [BOOT-03]

duration: 12min
completed: 2026-06-02
---

# Phase 24 Plan 05: FilesystemPrefixingDecorator Summary

**Prefix-mode FilesystemOperator decorator with live-read TenantContext, PathPrefixer per-call, listContents prefix-stripping, and 21-test behavioural suite including cross-tenant context-switch invariant pin**

## Performance

- **Duration:** ~12 min
- **Started:** 2026-06-02T20:05:00Z
- **Completed:** 2026-06-02T20:17:00Z
- **Tasks:** 2
- **Files modified:** 2

## Accomplishments

- Shipped `FilesystemPrefixingDecorator` implementing the full `FilesystemOperator` interface (18 methods from the interface + 3 extras via `@method` annotations: `publicUrl`, `temporaryUrl`, `checksum`)
- Implemented Q1 prefix-stripping in `listContents()`: returned `StorageAttributes` entries have the tenant prefix removed, so application code always works with tenant-relative paths
- Q2 accept: `publicUrl`/`temporaryUrl`/`checksum` forward the prefixed path to the inner operator (inherent property of prefix mode; documented in class docblock)
- `move()` and `copy()` prefix both source AND destination (critical for decoration correctness)
- Flipped `FilesystemPrefixingDecoratorTest` stub to 21 fully-green tests including cross-tenant context-switch live-read pin and reflection no-mutable-state assertion

## Task Commits

Each task was committed atomically:

1. **Task 1: Implement FilesystemPrefixingDecorator** - `b8ba25e` (feat)
2. **Task 2: Flip FilesystemPrefixingDecoratorTest to GREEN** - `d481c14` (test)

**Plan metadata:** _(docs commit follows)_

## Files Created/Modified

- `src/Filesystem/FilesystemPrefixingDecorator.php` — final class implementing FilesystemOperator; 3 readonly ctor properties; single `prefixer()` live-read method; all 18+3 methods delegate to inner with prefixed paths
- `tests/Unit/Filesystem/FilesystemPrefixingDecoratorTest.php` — 21 tests covering all behaviours: write/read/delete/move/copy/list, listContents re-relativisation, Q2 URL methods, no-tenant passthrough, custom prefix template, double slug substitution, cross-tenant context switch, reflection pin

## Decisions Made

- **Flysystem 3.34.0 `@method` annotations**: `publicUrl`, `temporaryUrl`, `checksum` are annotated with `@method` on `FilesystemReader` but are not declared in the `FilesystemOperator` interface. PHPStan level 9 accepts calls to these via the `@method` stubs without erroring (verified). Implemented as extra public methods on the decorator alongside the 18 interface methods.
- **Test mocking for `@method` extras**: PHPUnit cannot configure `@method`-annotated methods on an interface mock (`FilesystemOperator`). Tests for these 3 methods mock `Filesystem` (the concrete class) instead — it has the methods as actual public methods.
- **listContents generator closure**: The `PathPrefixer` instance is captured outside the closure (not read inside the generator). The outer `listContents()` call already read `TenantContext` live; passing the immutable `$prefixer` into the generator ensures prefix consistency for the entire listing while avoiding repeated context reads per entry.

## Deviations from Plan

None — plan executed exactly as written. The 3 `@method` extras (`publicUrl`/`temporaryUrl`/`checksum`) required implementation insight about Flysystem 3.34.0's interface surface (they're `@method` annotations, not real interface methods), but the implementation and test strategy follow plan intent exactly.

## Issues Encountered

- **PHPStan unmatched-ignore**: Initial implementation included `@phpstan-ignore-next-line` comments before `publicUrl`/`temporaryUrl`/`checksum` calls on `FilesystemOperator`. PHPStan level 9 does NOT error on those calls (the `@method` annotations make them valid), so the ignore directives themselves produced "No error to ignore is reported" errors. Removed the comments — clean run.
- **PHPUnit mock limitation**: `createMock(FilesystemOperator::class)` cannot configure `publicUrl`/`temporaryUrl`/`checksum` because they're not real interface methods. Switched to `createMock(Filesystem::class)` for those 3 tests.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- `FilesystemPrefixingDecorator` is ready for use in `FilesystemContractPass` (Plan 24-07) to rewrite `tenancy.scoped`-tagged service definitions with this decorator
- `FilesystemBootstrapper` (Plan 24-06) can reference this decorator directly for prefix-mode wiring
- Integration tests (Plan 24-08) can exercise the decorator against the in-memory adapter

## Self-Check

- [x] `src/Filesystem/FilesystemPrefixingDecorator.php` exists and is non-empty
- [x] `tests/Unit/Filesystem/FilesystemPrefixingDecoratorTest.php` exists with 21 tests
- [x] Commit `b8ba25e` exists (Task 1)
- [x] Commit `d481c14` exists (Task 2)
- [x] PHPStan level 9 clean on both files
- [x] cs-fixer clean on both files
- [x] 21/21 tests pass
- [x] Reflection confirms zero mutable instance state

## Self-Check: PASSED

---
*Phase: 24-filesystem-bootstrapper*
*Completed: 2026-06-02*
