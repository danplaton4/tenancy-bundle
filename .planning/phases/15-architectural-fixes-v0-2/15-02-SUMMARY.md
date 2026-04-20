---
phase: 15-architectural-fixes-v0-2
plan: 02
subsystem: resolver
tags: [tenancy, resolver-chain, value-object, orchestrator, exception-narrowing, symfony, phpunit]

# Dependency graph
requires:
  - phase: 02-tenant-resolution
    provides: ResolverChain, TenantResolverInterface, TenantNotFoundException, per-resolver catch/swallow convention
  - phase: 04-shared-db-driver
    provides: TenantAwareFilter strict_mode path, TenantMissingException
provides:
  - Final readonly TenantResolution value object (src/Resolver/TenantResolution.php)
  - ResolverChain::resolve() returning ?TenantResolution instead of array + throw
  - Orchestrator null-branch for public/landlord/health routes
  - Narrowed TenantNotFoundException semantics (provider-level rejection only)
  - Integration coverage for no-tenant request and strict_mode regression
affects: [phase-15-03, phase-15-04, downstream forks that called ResolverChain::resolve directly, kernel.exception listeners that caught TenantNotFoundException for the "no resolver matched" case]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Nullable typed return instead of exception-for-control-flow at the ResolverChain boundary"
    - "final readonly value object for resolver output (replaces array shape)"
    - "Orchestrator branches on null resolution — TenantContext stays empty, bootstrappers skipped, no TenantResolved dispatch"

key-files:
  created:
    - src/Resolver/TenantResolution.php
    - tests/Integration/EventListener/NoTenantRequestTest.php
    - tests/Integration/Filter/StrictModeWithNullResolutionTest.php
  modified:
    - src/Resolver/ResolverChain.php
    - src/EventListener/TenantContextOrchestrator.php
    - src/Exception/TenantNotFoundException.php
    - tests/Unit/Resolver/ResolverChainTest.php
    - tests/Unit/EventListener/TenantContextOrchestratorTest.php

key-decisions:
  - "ResolverChain returns ?TenantResolution — no-match is a valid outcome, not an exception"
  - "TenantNotFoundException is narrowed to provider-level rejection (identifier extracted, no matching active tenant); sole live thrower becomes DoctrineTenantProvider::findBySlug"
  - "Orchestrator leaves TenantContext empty on null resolution — shared_db + strict_mode is the security guardrail, not a 404 at the resolver chain"
  - "#[RequiresTenant] stretch goal deferred to backlog — not a security fix; strict_mode already covers the critical path"

patterns-established:
  - "Typed nullable return at architectural seams: prefer ?ValueObject over array+throw-on-empty for valid-but-empty outcomes"
  - "Security-critical regression tests: when a behavior change could drop a guardrail, write an explicit integration test that fails if the guardrail disappears (StrictModeWithNullResolutionTest)"

requirements-completed: [FIX-02]

# Metrics
duration: 9min
completed: 2026-04-20
---

# Phase 15 Plan 02: ResolverChain Nullable Semantics Summary

**ResolverChain::resolve() now returns a nullable TenantResolution value object — public routes proceed with empty TenantContext instead of a global 404, while strict_mode keeps data leaks sealed.**

## Performance

- **Duration:** ~9 min
- **Tasks:** 5 executed (Tasks 1-4 + Task 6 phase gate); Task 5 stretch deferred per decision gate
- **Files modified:** 8 (3 created, 5 modified)
- **Tests:** 273 pass (up from pre-plan baseline with 7 new unit/integration tests added)

## Accomplishments

- Introduced `final readonly TenantResolution(tenant, resolvedBy)` value object replacing the untyped `array{tenant, resolvedBy}` return from `ResolverChain::resolve()`.
- `ResolverChain::resolve()` now returns `?TenantResolution` — null is the new "no resolver matched" signal. The class no longer imports or throws `TenantNotFoundException`.
- `TenantContextOrchestrator::onKernelRequest` branches on null: leaves `TenantContext` empty, skips `BootstrapperChain::boot()`, does NOT dispatch `TenantResolved`. Public/landlord/health-check routes proceed untouched.
- Narrowed `TenantNotFoundException` docblock: the exception is now reserved for the case where a resolver extracted an identifier but the provider rejected it (`DoctrineTenantProvider::findBySlug`). 404 HTTP semantics remain correct for that narrowed case.
- Integration coverage:
  - `NoTenantRequestTest` — real kernel, no-resolver-match request does not throw and leaves `hasTenant()` false for main, sub, and terminate events.
  - `StrictModeWithNullResolutionTest` — shared_db + strict_mode + empty TenantContext → `TenantMissingException` still throws on `#[TenantAware]` findAll + DQL. Guardrail intact.

Closes GitHub issue #6 (control-flow-via-exception at the resolver chain).

## Task Commits

Executed against base `68840e18`. All commits use `--no-verify` per worktree execution policy (orchestrator re-runs hooks at merge).

1. **Task 1: TenantResolution + nullable ResolverChain (TDD)**
   - `8fb3adc` — test(15-02): add failing tests for TenantResolution value object and nullable ResolverChain
   - `b746fb1` — feat(15-02): introduce TenantResolution value object and make ResolverChain nullable
2. **Task 2: Orchestrator null-branch + exception narrowing (TDD)**
   - `c60f773` — test(15-02): add failing orchestrator tests for null-branch + TenantResolution consumption
   - `09fdb96` — feat(15-02): add null-branch to orchestrator and narrow TenantNotFoundException docblock
3. **Task 3: No-tenant integration test**
   - `c4fac29` — test(15-02): integration test proves no-tenant request leaves TenantContext empty
4. **Task 4: Strict-mode regression integration test**
   - `1e9ef83` — test(15-02): regression test — strict_mode still throws TenantMissingException on null resolution
5. **Task 6: Phase gate polish**
   - `1519362` — style(15-02): use single quotes for DQL literal in StrictModeWithNullResolutionTest

Task 5 (stretch `#[RequiresTenant]` attribute) deferred to backlog — see Deferred Items below.

## Files Created/Modified

**Created:**
- `src/Resolver/TenantResolution.php` — `final readonly class` value object; fields `public TenantInterface $tenant`, `public string $resolvedBy`.
- `tests/Integration/EventListener/NoTenantRequestTest.php` — real kernel + NullTenantProvider + host.app_domain:null; verifies main/sub/terminate paths leave TenantContext empty.
- `tests/Integration/Filter/StrictModeWithNullResolutionTest.php` — reuses `SharedDbTestKernel` with `test_strict_null` env, never calls `setTenant`; asserts TenantMissingException on findAll + DQL.

**Modified:**
- `src/Resolver/ResolverChain.php` — signature flip to `?TenantResolution`, removed TenantNotFoundException import + throw.
- `src/EventListener/TenantContextOrchestrator.php` — `onKernelRequest` now captures `$resolution` and early-returns on null before any side effects.
- `src/Exception/TenantNotFoundException.php` — class-level docblock narrating narrowed semantics; signature unchanged (still HttpExceptionInterface + 404).
- `tests/Unit/Resolver/ResolverChainTest.php` — replaced `expectException(TenantNotFoundException)` with `assertNull`; happy-path tests assert `TenantResolution` instance + property access.
- `tests/Unit/EventListener/TenantContextOrchestratorTest.php` — new `testOnKernelRequestIsNoOpWhenNoResolverMatches` + `testOnKernelRequestDoesNotDispatchTenantResolvedWhenChainReturnsNull`; spy bootstrapper now also tracks `bootCallCount`; existing happy-path tests now implicitly exercise TenantResolution via StubResolver pipeline.

## Decisions Made

- **Deferred stretch goal `#[RequiresTenant]` attribute (Task 5, option-b).** CONTEXT.md locked the stretch goal as "land if base + tests < 60% plan budget, else backlog". The architectural fix is fully landed and covered; the attribute is DX polish, not a security fix (strict_mode + `#[TenantAware]` already close the critical path, per threat register T-15-05 and `StrictModeWithNullResolutionTest`). Deferring keeps plan 15-02 focused and shippable.
- **Kept `TenantNotFoundException` 404 status code.** The narrowed semantics (provider-level rejection of an extracted identifier) still map cleanly to 404; no need to change HTTP behavior.
- **Did not delete or rename `TenantNotFoundException` — docblock-only narrowing.** Zero downstream call site churn beyond the single `use` removal in `ResolverChain.php`.
- **Shared `SharedDbTestKernel` reused for Task 4 with a distinct environment (`test_strict_null`)** to avoid cache/DB collision with `SharedDbFilterIntegrationTest`. No new kernel class needed.

## Deviations from Plan

None material. Plan executed exactly as written for Tasks 1-4 and Task 6.

One minor stylistic follow-up (post-gate):

**1. [Rule 1 - style] Replaced double-quoted DQL literal with single quotes**
- **Found during:** Task 6 (full quality gate)
- **Issue:** `php-cs-fixer check` flagged a double-quoted DQL literal in `StrictModeWithNullResolutionTest` (Symfony ruleset `single_quote` rule).
- **Fix:** Switched to single quotes — literal contains no variable interpolation.
- **Files modified:** tests/Integration/Filter/StrictModeWithNullResolutionTest.php
- **Verification:** `vendor/bin/php-cs-fixer check --diff --allow-risky=yes` clean; test still passes.
- **Committed in:** `1519362`

---

**Total deviations:** 1 style fix. **Impact:** cosmetic only, no behavior change.

## Issues Encountered

None. The plan's `<interfaces>` block was accurate; the test kernel patterns from `TenantResolutionIntegrationTest` and `SharedDbFilterIntegrationTest` transferred cleanly to the two new integration tests.

## Threat Model Coverage

Threat register dispositions from the plan (T-15-05..T-15-08) are implemented as specified:

- **T-15-05 (Information Disclosure — public route returning 200 without tenant):** Mitigated. strict_mode + `#[TenantAware]` + `TenantAwareFilter` still throw `TenantMissingException` on any tenant-scoped query when TenantContext is empty. Regression guarded by `StrictModeWithNullResolutionTest` (2 tests: findAll + DQL).
- **T-15-06 (EoP — subscribers assuming TenantResolved fires every request):** Accept + document. No production subscribers on `TenantResolved` in-bundle. UPGRADE.md (plan 15-04 territory) will carry the migration note.
- **T-15-07 (Spoofing via malformed Host):** Mitigated. Resolver-level behavior unchanged — per-resolver catch of `TenantNotFoundException` + strict_mode filter unchanged from v0.1.
- **T-15-08 (DoS — no-match requests now run the full pipeline):** Accept. No amplification; strict_mode short-circuits on any tenant-entity touch.

No new threat surface introduced beyond what was modeled.

## Deferred Items (for backlog)

- **#[RequiresTenant] controller attribute + argument resolver / kernel-exception listener (Task 5 stretch, deferred per option-b).**
  Proposed shape: `#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]` PHP attribute + `kernel.controller` listener that reads the attribute via reflection and throws `TenantNotFoundException` (→ 404) when `!$tenantContext->hasTenant()` on a tagged route.
  Estimated effort: ~4 files + one listener wiring point + one integration test.
  Rationale for deferral: strict_mode already closes the security case; this is an opt-in DX sugar for controllers that want route-level tenant enforcement without manual `if (!$ctx->hasTenant()) throw...`. Non-blocking for v0.2 release.
  Action item: run `/gsd-add-backlog` with title "Add #[RequiresTenant] controller attribute for opt-in tenant enforcement" after plan merge.

## TDD Gate Compliance

Tasks 1 and 2 followed the full RED → GREEN cycle:

- **Task 1:** test commit `8fb3adc` (RED, 7 failures) → feat commit `b746fb1` (GREEN, all pass). No REFACTOR step needed — implementation was minimal by design.
- **Task 2:** test commit `c60f773` (RED, 6 failures) → feat commit `09fdb96` (GREEN, all pass). No REFACTOR step needed.

Tasks 3-4 are test-only additions (integration coverage); no separate RED/GREEN split required — the tests pass against the already-landed Task 2 GREEN.

## Next Phase Readiness

- **Plan 15-03 (DBAL middleware refactor):** Independent of this plan's changes. ResolverChain signature change doesn't touch the DBAL layer.
- **Plan 15-04 (UPGRADE.md + CHANGELOG):** Will document this breaking internal change: `ResolverChain::resolve()` return type changed from `array{tenant, resolvedBy}` to `?TenantResolution`. Migration recipe for listeners on `TenantResolved` (now may not fire) is noted in threat T-15-06.
- **Commit footer `Fixes #6`:** Will be applied to the merge commit when the phase PR lands (or any consolidating commit in plan 15-04 that closes the issue). Per-task commits in this worktree use `Refs #6` to avoid premature auto-close.

## Self-Check: PASSED

**Files verified:**
- FOUND: src/Resolver/TenantResolution.php
- FOUND: src/Resolver/ResolverChain.php (modified)
- FOUND: src/EventListener/TenantContextOrchestrator.php (modified)
- FOUND: src/Exception/TenantNotFoundException.php (modified)
- FOUND: tests/Unit/Resolver/ResolverChainTest.php (modified)
- FOUND: tests/Unit/EventListener/TenantContextOrchestratorTest.php (modified)
- FOUND: tests/Integration/EventListener/NoTenantRequestTest.php
- FOUND: tests/Integration/Filter/StrictModeWithNullResolutionTest.php

**Commits verified:**
- FOUND: 8fb3adc (test: resolver chain RED)
- FOUND: b746fb1 (feat: TenantResolution + nullable chain)
- FOUND: c60f773 (test: orchestrator RED)
- FOUND: 09fdb96 (feat: orchestrator null-branch)
- FOUND: c4fac29 (test: no-tenant integration)
- FOUND: 1e9ef83 (test: strict-mode regression)
- FOUND: 1519362 (style: cs-fixer fix)

**Quality gate:**
- phpunit (273 tests): PASS
- phpstan (level 9, 42 files): PASS
- php-cs-fixer (@Symfony): PASS

---
*Phase: 15-architectural-fixes-v0-2*
*Plan: 02*
*Completed: 2026-04-20*
