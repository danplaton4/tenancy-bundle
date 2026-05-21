---
phase: 18-tenancy-install
plan: 10
subsystem: bundle-zero-config
tags: [symfony, dependency-injection, nullOnInvalid, messenger, console, fail-loud]

requires:
  - phase: 18-08
    provides: ZeroConfigKernelBootTest canary (RED-bar) — regression test that fails on the null-provider crash

provides:
  - TenantRunCommand accepts nullable ?TenantProviderInterface constructor parameter — no TypeError on zero-config install
  - TenantRunCommand::execute() throws RuntimeException with tenancy:install guidance when provider is null
  - TenantWorkerMiddleware accepts nullable ?TenantProviderInterface constructor parameter — no TypeError on zero-config install
  - TenantWorkerMiddleware::handle() throws RuntimeException (with slug context) when a TenantStamp-ed envelope arrives but no provider is configured
  - No-stamp envelopes in TenantWorkerMiddleware still pass through cleanly (throwless)

affects:
  - 18-09 (parallel — fixes resolvers with fail-SILENT; same nullOnInvalid wiring pattern)
  - 18-11 (full verification + CHANGELOG — will confirm GREEN canary after 18-09 + 18-10 land)

tech-stack:
  added: []
  patterns:
    - "Fail-loud null guard: write-path services throw RuntimeException with tenancy:install guidance when provider is absent — contrasts with resolver fail-silent (return null) pattern"
    - "Nullable constructor parameter without = null default: ?TenantProviderInterface without default value; DI container always supplies the arg explicitly (nullOnInvalid returns null, not absent)"

key-files:
  created: []
  modified:
    - src/Command/TenantRunCommand.php
    - src/Messenger/TenantWorkerMiddleware.php

key-decisions:
  - "Fail-loud vs fail-silent split: TenantRunCommand and TenantWorkerMiddleware use fail-loud (RuntimeException) because both perform tenant-scoped WRITES (subprocess spawn; message dispatch). Silent no-op = data corruption. Resolvers use fail-silent (return null) because they're read-only chain lookups."
  - "No = null default on nullable constructor params: the container always passes the arg explicitly via services.php (service('tenancy.provider')->nullOnInvalid()), so PHP does not need a default value. Nullability alone is sufficient."
  - "TenantWorkerMiddleware guard placement: RuntimeException fires AFTER the null === $stamp early-return, so messages without TenantStamp pass through cleanly even when provider is absent. This preserves the bundle's opt-in design for non-tenant Messenger buses."
  - "Exception message interpolates slug: the TenantWorkerMiddleware error includes sprintf('%s', $stamp->getTenantSlug()) to give operators actionable forensic context about which tenant-scoped message triggered the misconfiguration."

patterns-established:
  - "Write-path services (commands, middleware dispatching to workers) assert provider non-null at entry method, not at construction — allows container to compile cleanly while failing loudly at use time"

requirements-completed:
  - DX-06

duration: 20min
completed: 2026-05-21
---

# Phase 18 Plan 10: Fail-Loud Null Guards for Write-Path Services Summary

**TenantRunCommand and TenantWorkerMiddleware accept nullable ?TenantProviderInterface and throw RuntimeException with tenancy:install guidance when a TenantStamp-ed write path is attempted without a configured provider**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-05-21T16:20:00Z
- **Completed:** 2026-05-21T16:45:00Z
- **Tasks:** 2
- **Files modified:** 2

## Accomplishments

- TenantRunCommand constructor changed from `TenantProviderInterface` to `?TenantProviderInterface` — no TypeError when container injects null via `nullOnInvalid()` on a zero-config install
- TenantRunCommand::execute() added fail-loud RuntimeException at entry, directing user to `bin/console tenancy:install`
- TenantWorkerMiddleware constructor changed to `?TenantProviderInterface` — same null-safe construction
- TenantWorkerMiddleware::handle() added fail-loud RuntimeException AFTER the no-stamp early-return (critical ordering), interpolating the offending TenantStamp slug for operator forensics
- All 304 existing tests (224 unit + 80 integration) pass; PHPStan level 9 clean; php-cs-fixer @Symfony clean

## Why Fail-Loud (Not Fail-Silent)

Plan 18-09 fixes 4 resolver sites with fail-SILENT semantics (return null / early-return). This plan deliberately uses the OPPOSITE policy for `TenantRunCommand` and `TenantWorkerMiddleware`:

- `TenantRunCommand` spawns subprocesses with `--tenant=<slug>` flag. Silently no-op'ing with a null provider would run the subprocess with NO tenant context — the child process would operate on unconfigured tenant state, a data-correctness disaster.
- `TenantWorkerMiddleware` handles Messenger worker dispatch for TenantStamp-ed envelopes. Silently passing through would dispatch a "tenant-scoped" handler with no actual tenant context — wrong-tenant data writes.

The fail-loud policy guarantees the misconfiguration is surfaced immediately at use time (not silently corrupting data), while still allowing the container to compile and the kernel to boot in zero-config mode (PHP only instantiates these services when they're actually invoked).

## RuntimeException Message Wording

**TenantRunCommand:**
```
tenancy:run requires a configured tenant provider. The bundle was loaded but no `tenancy:` config block is present, so `tenancy.provider` is unbound. Run `bin/console tenancy:install` to scaffold the config, ensure `doctrine` is installed, then re-try.
```

**TenantWorkerMiddleware:**
```
TenantWorkerMiddleware received an envelope stamped with TenantStamp(slug='<slug>') but the bundle has no configured tenant provider. The `tenancy.provider` service is unbound (no `tenancy:` config block present). Run `bin/console tenancy:install` and configure the bundle before dispatching tenant-scoped messages.
```

Both messages:
- Name the missing dependency (`tenancy.provider` unbound)
- Explain the cause (no `tenancy:` config block)
- Give the fix (`bin/console tenancy:install`)

The worker message also interpolates the slug from the offending TenantStamp — operators running `bin/console messenger:consume` can immediately identify which tenant-scoped message triggered the misconfiguration.

## Task Commits

Each task was committed atomically:

1. **Task 1: TenantRunCommand nullable + fail-loud guard** - `c665ad9` (fix)
2. **Task 2: TenantWorkerMiddleware nullable + fail-loud guard** - `fff9894` (fix)

**Plan metadata:** (this SUMMARY commit)

## Files Created/Modified

- `src/Command/TenantRunCommand.php` — Constructor param `?TenantProviderInterface` + fail-loud guard in execute()
- `src/Messenger/TenantWorkerMiddleware.php` — Constructor param `?TenantProviderInterface` + fail-loud guard in handle() (after no-stamp early-return)

## Decisions Made

- Fail-loud vs fail-silent split: write paths use RuntimeException, resolvers use null/early-return (see plan 18-09)
- No `= null` default on nullable params: container always supplies the arg via `service('tenancy.provider')->nullOnInvalid()`; PHP default not needed, and having it would break PHP's positional argument rules for TenantWorkerMiddleware (non-nullable args follow in the list)
- Guard placement in TenantWorkerMiddleware: AFTER the no-stamp early-return to preserve throwless pass-through for non-tenant messages
- Exception message interpolates slug for forensic context in worker/async scenarios

## Deviations from Plan

None — plan executed exactly as specified.

The plan correctly anticipated the need for a single-line exception message (php-cs-fixer @Symfony style requires long strings on one line rather than concatenated multiline expressions). The initial implementation used concatenated strings; php-cs-fixer flagged this and the fix was applied inline before the commit, within the scope of the task.

## Issues Encountered

**Worktree vendor setup:** The worktree did not have a `vendor/` directory. A custom `vendor/autoload.php` was created that delegates to the main repo's vendor autoloader then overrides the `Tenancy\Bundle\` PSR-4 paths to point at the worktree's `src/` and `tests/`. This ensures tests load the worktree's source files, not the main repo's (which is at a later commit with Phase 20 mailer changes that would cause interface mismatch).

**Parallel-worktree cache conflict:** When running the full test suite, integration tests failed with a "Cannot redeclare class" error because Symfony kernel test caches (in `sys_get_temp_dir()`) were shared between the parallel worktrees (18-09 and 18-10 both use the same cache path key based on `md5(static::class)`). Resolved by clearing all `tenancy_*` temp directories before each test run. The pre-commit hook works cleanly when caches are clear.

## Canary Status

ZeroConfigKernelBootTest (created in plan 18-08) is not present in this worktree (worktree base is commit 23c3802, before the canary was added in plan 18-08's execution). The full GREEN verification of the canary happens in plan 18-11 after all wave-2 plans (18-09, 18-10) are merged to master.

The contracts this plan delivers — nullable constructors + fail-loud guards — are the precise changes required to pass the canary. Plan 18-11 will confirm the GREEN bar.

## Next Phase Readiness

- Both write-path defect sites (items 5 and 6 from the 18-VERIFICATION.md defect inventory) are fixed
- Combined with plan 18-09's 4 resolver fixes, all 6 defect sites are resolved
- Plan 18-11 (full integration verification + CHANGELOG) can proceed after both 18-09 and 18-10 are merged

## Self-Check: PASSED

- `src/Command/TenantRunCommand.php`: FOUND, nullable signature, RuntimeException present, tenancy:install mentioned
- `src/Messenger/TenantWorkerMiddleware.php`: FOUND, nullable signature, RuntimeException present (post-stamp guard), tenancy:install mentioned
- `.planning/phases/18-tenancy-install/18-10-SUMMARY.md`: FOUND
- Commit c665ad9 (Task 1): FOUND
- Commit fff9894 (Task 2): FOUND

---
*Phase: 18-tenancy-install*
*Completed: 2026-05-21*
