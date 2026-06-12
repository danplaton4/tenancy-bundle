---
phase: 26-tenancy-shared-resync-command
plan: 03
subsystem: database
tags: [doctrine-orm, shared-entities, console-command, di-wiring, phpunit, interface-extraction]

requires:
  - phase: 26-02
    provides: SharedEntityCopier with applyRow/classifyRow/findSharedClasses/isSyncInProgress; DI wired as tenancy.shared_entity_copier
  - phase: 26-01
    provides: SharedEntityResyncCommandTest stubs (7 skip-guarded, now activated)

provides:
  - SharedEntityResyncCommand — tenancy:shared:resync console command (two-pass classify→confirm→apply, D-01..D-07)
  - SharedEntityCopierInterface — contract for the copier service enabling PHPUnit mocking
  - DI registration: tenancy.command.shared_resync wired in interface_exists(EntityManagerInterface) block
  - CommandTestKernel 'doctrine' ManagerRegistry stub — fixes DI compilation for console.command-tagged services
  - SharedEntityResyncCommandTest — 7 real assertions (no skips) covering SHARE-02-b..g and SHARE-02-k

affects:
  - 26-04 (verification — command test stubs activated; SHARE-02-h/i/l integration stubs remain for verification wave)

tech-stack:
  added: []
  patterns:
    - "Interface extraction for final class testability: SharedEntityCopierInterface alongside final SharedEntityCopier — same pattern as TenantConnectionInterface for final TenantConnection (PHPUnit 11 ClassIsFinalException requires interface for mocking)"
    - "CommandTestKernel ManagerRegistry stub: console.command-tagged services are reachable from FrameworkBundle's console Application (unlike doctrine.event_listener tags); minimal kernels must stub 'doctrine' when registering console commands that inject ManagerRegistry"
    - "Two-pass classify→confirm→apply: landlord rows materialized once before both passes (not per tenant); classify pass uses try/catch to handle per-tenant errors non-fatally; apply pass is the continue-on-failure loop"

key-files:
  created:
    - src/Command/SharedEntityResyncCommand.php
    - src/Shared/SharedEntityCopierInterface.php
  modified:
    - src/Shared/SharedEntityCopier.php
    - src/TenancyBundle.php
    - tests/Unit/Command/SharedEntityResyncCommandTest.php
    - tests/Integration/Command/Support/CommandTestKernel.php

key-decisions:
  - "SharedEntityCopierInterface extracted alongside final SharedEntityCopier — command type-hints the interface; copier and subscriber/listener retain the concrete class. Same pattern as TenantConnectionInterface (Phase 3)"
  - "CommandTestKernel 'doctrine' stub added as Rule 1 auto-fix: console.command services are dependency-checked by DI compiler because FrameworkBundle's console Application is a public service; the subscriber (tagged doctrine.event_listener) is never reachable in this minimal kernel and did not need the stub. This explains why existing subscriber registration did not fail"
  - "Two-pass design: classify pass runs per-tenant with full BootstrapperChain boot/clear cycle to access each tenant EM; landlord rows are materialized once to avoid N+1 repository calls"

patterns-established:
  - "SharedEntityCopierInterface pattern: extract PHP interface for any final service class that needs to be injected into tested commands. This is the project's standard testability pattern (TenantConnectionInterface precedent)"
  - "CommandTestKernel stub addition: when a new console.command service is added to TenancyBundle, add its missing dependency stubs to CommandTestKernel — console commands are DI-validated eagerly, unlike event listener services"

requirements-completed: [SHARE-02]

duration: 11min
completed: 2026-06-13
---

# Phase 26 Plan 03: tenancy-shared-resync-command Command Build Summary

**tenancy:shared:resync console command with two-pass classify→confirm→apply flow, SharedEntityCopierInterface for testability, 7 real CommandTester assertions covering SHARE-02-b..g and -k**

## Performance

- **Duration:** ~11 min
- **Started:** 2026-06-12T20:51:44Z
- **Completed:** 2026-06-13T21:03:00Z
- **Tasks:** 3
- **Files modified:** 6

## Accomplishments

- Created `SharedEntityResyncCommand` in `Tenancy\Bundle\Command` namespace implementing all D-01..D-07 locked decisions: shared_db no-op (SUCCESS), --tenant single/all resolution, two-pass classify→confirm→apply with drift summary table, --dry-run exits before any write, --force skips confirm(), -n without --force aborts cleanly, per-tenant try/catch/finally continue-on-failure, 'Completed: N succeeded, M failed' summary
- Extracted `SharedEntityCopierInterface` alongside final `SharedEntityCopier` — command type-hints the interface; PHPUnit can mock the interface without ClassIsFinalException
- Registered `tenancy.command.shared_resync` in `TenancyBundle::loadExtension()` inside the `interface_exists(EntityManagerInterface::class)` block (NOT gated on Doctrine\Migrations), wired with 7 args including `doctrine.orm.landlord_entity_manager`, `doctrine`, and `tenancy.shared_entity_copier`
- Fixed `CommandTestKernel` to stub the `doctrine` ManagerRegistry service — console.command-tagged services are DI-validated eagerly (reachable from FrameworkBundle's console Application), unlike doctrine.event_listener tags that are unreachable in minimal kernels
- Activated all 7 Wave 0 command unit test stubs: no markTestSkipped calls; 7 tests, 28 assertions, all green

## Task Commits

1. **Task 1: Implement SharedEntityResyncCommand (two-pass classify→confirm→apply)** - `a056625` (feat)
2. **Task 2: Register tenancy.command.shared_resync in DI** - `e9968e4` (feat)
3. **Task 3: Fill the command unit test with real CommandTester assertions** - `e2928f4` (feat)

## Files Created/Modified

- `src/Command/SharedEntityResyncCommand.php` — New: two-pass classify→confirm→apply command with D-01..D-07 semantics
- `src/Shared/SharedEntityCopierInterface.php` — New: PHP interface for the final SharedEntityCopier (testability extraction)
- `src/Shared/SharedEntityCopier.php` — Modified: implements SharedEntityCopierInterface
- `src/TenancyBundle.php` — Modified: SharedEntityResyncCommand import + tenancy.command.shared_resync registration + SharedEntityCopierInterface use-import update
- `tests/Unit/Command/SharedEntityResyncCommandTest.php` — Activated: 7 real CommandTester assertions (SHARE-02-b..g, SHARE-02-k)
- `tests/Integration/Command/Support/CommandTestKernel.php` — Modified: added 'doctrine' ManagerRegistry stub compiler pass

## Decisions Made

- `SharedEntityCopierInterface` extracted (not just removing `final` from the copier). Removing `final` would open the class to unconstrained subclassing; the interface is the correct boundary — callers depend on behavior, not implementation. Matches TenantConnectionInterface precedent.
- `CommandTestKernel` stub approach: add a minimal `new Definition(\stdClass::class)` for `doctrine` — sufficient to pass the DI compiler's `CheckExceptionOnInvalidReferenceBehaviorPass` without requiring DoctrineBundle or a real ManagerRegistry implementation.
- Two-pass design (classify then apply) reuses BootstrapperChain::boot/clear cycle for both passes. The classify pass does not write, so errors there are non-fatal (caught, continue). The apply pass is the canonical continue-on-failure loop from TenantMigrateCommand.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Task 2 commit rejected — 'doctrine' service missing in CommandTestKernel**
- **Found during:** Task 2 commit attempt (pre-commit hook runs full suite)
- **Issue:** `tenancy.command.shared_resync` is tagged `console.command` — FrameworkBundle's console Application is a public service so Symfony's DI compiler eagerly validates all its tagged command dependencies. The `doctrine` (ManagerRegistry) service is not registered in `CommandTestKernel` (which omits DoctrineBundle). The existing `tenancy.shared_entity_sync_subscriber` also uses `service('doctrine')` but is tagged `doctrine.event_listener` — that tag is never reachable in this minimal kernel, so it was silently skipped by the DI validator.
- **Fix:** Added a separate compiler pass to `CommandTestKernel::build()` that registers a `\stdClass` stub for `doctrine` when `EntityManagerInterface` is available. Protected by `hasDefinition()/hasAlias()` guards for idempotency.
- **Files modified:** `tests/Integration/Command/Support/CommandTestKernel.php`
- **Verification:** `vendor/bin/phpunit tests/Integration/Command/` — 26 tests, 90 assertions, 0 errors
- **Committed in:** `e9968e4` (Task 2 commit)

**2. [Rule 1 - Bug] SharedEntityCopier is final — cannot be mocked or subclassed**
- **Found during:** Task 3 (initial test run with `createMock(SharedEntityCopier::class)`)
- **Issue:** `SharedEntityCopier` is declared `final class` — PHPUnit 11 throws `ClassIsFinalException` when `createMock()` is called on it. Cannot subclass for a spy either.
- **Fix:** Extracted `SharedEntityCopierInterface` with the 5 public methods (`applyRow`, `classifyRow`, `findSharedClasses`, `isShared`, `isSyncInProgress`). Updated `SharedEntityCopier` to implement it. Updated `SharedEntityResyncCommand` constructor to type-hint `SharedEntityCopierInterface`. Test mocks the interface.
- **Files modified:** `src/Shared/SharedEntityCopierInterface.php` (new), `src/Shared/SharedEntityCopier.php`, `src/Command/SharedEntityResyncCommand.php`
- **Verification:** `vendor/bin/phpunit tests/Unit/Command/SharedEntityResyncCommandTest.php` — 7 tests, 28 assertions, 0 skips; `vendor/bin/phpstan analyse src/` — no errors
- **Committed in:** `e2928f4` (Task 3 commit)

---

**Total deviations:** 2 auto-fixed (Rule 1 - bug: missing DI stub, Rule 1 - bug: final class prevents mocking)
**Impact on plan:** Zero scope creep. Both auto-fixes correct testability and DI wiring issues introduced by the plan's own changes. No architectural changes — interface extraction is explicitly the established pattern for final classes in this codebase.

## Issues Encountered

None beyond the two documented deviations above.

## Known Stubs

None — the command is fully wired to the real SharedEntityCopier via DI; no placeholder data flows to output.

## Threat Flags

No new threat surface introduced beyond what is in the plan's `<threat_model>`:
- T-26-03-XTENANT: mitigated by BootstrapperChain::boot($tenant) in both classify and apply passes
- T-26-03-MASSWRITE: mitigated by confirm() gate (default-No) with --force for explicit unattended intent
- T-26-03-INPUT: mitigated by findBySlug TenantNotFoundException|TenantInactiveException catch
- T-26-03-DRYRUN: mitigated by dry-run returning before confirm() and before any applyRow call

## Self-Check: PASSED

Files exist:
- src/Command/SharedEntityResyncCommand.php: FOUND
- src/Shared/SharedEntityCopierInterface.php: FOUND
- src/Shared/SharedEntityCopier.php: FOUND (modified)
- src/TenancyBundle.php: FOUND (modified)
- tests/Unit/Command/SharedEntityResyncCommandTest.php: FOUND (activated)
- tests/Integration/Command/Support/CommandTestKernel.php: FOUND (modified)

Commits exist:
- a056625: feat(26-03): implement SharedEntityResyncCommand — FOUND
- e9968e4: feat(26-03): register tenancy.command.shared_resync in DI — FOUND
- e2928f4: feat(26-03): fill SharedEntityResyncCommandTest with real CommandTester assertions — FOUND

Full suite: 729 tests, 3093 assertions, 4 skipped (integration stubs for 26-04), 0 failures — green.

## Next Phase Readiness

- `tenancy:shared:resync` command is fully implemented, wired in DI, and unit-tested
- `SharedEntityCopierInterface` is available for any future callers that need to mock the copier
- `CommandTestKernel` is updated with the 'doctrine' stub — future commands injecting ManagerRegistry will compile
- 4 skipped integration stubs remain (SHARE-02-h idempotency, -i write-protection bypass, -l drift classification) — these activate in Plan 26-04 (verification wave)
- Plan 26-04 can proceed immediately

---
*Phase: 26-tenancy-shared-resync-command*
*Completed: 2026-06-13*
