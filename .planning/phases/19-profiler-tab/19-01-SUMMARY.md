---
phase: 19-profiler-tab
plan: 01
subsystem: profiler
tags: [profiler, event-listener, reset-interface, tdd]
requires: [src/Event/TenantResolved.php, src/Event/TenantBootstrapped.php, src/Event/TenantContextCleared.php, src/Exception/*]
provides: [Tenancy\Bundle\Profiler\TenantProfilerStash]
affects: []
tech-stack:
  added: []
  patterns: ["#[AsEventListener] multi-attribute stacking", "ResetInterface for long-running runtimes", "str_starts_with namespace-prefix predicate"]
key-files:
  created:
    - src/Profiler/TenantProfilerStash.php
    - tests/Unit/Profiler/TenantProfilerStashTest.php
  modified: []
decisions:
  - "Stash captures only Tenancy\\Bundle\\Exception\\* exceptions — application exceptions carrying user data never enter the profiler (T-19-05)"
  - "Stores ['class'=>string,'message'=>string] shape, never the Throwable object (T-19-06 — no closure/PDO leaks via previous chain)"
  - "Uses 4 class-level #[AsEventListener] attributes — bundle idiom, no EventSubscriberInterface anywhere in src/"
  - "Implements ResetInterface so ServicesResetter clears state between requests in FrankenPHP/Swoole/RoadRunner (T-19-04)"
metrics:
  duration: ~4 minutes
  tasks-completed: 2
  files-changed: 2
  date: 2026-05-18
---

# Phase 19 Plan 01: TenantProfilerStash Summary

Per-request event-listener state holder that captures resolved-by FQCN, bootstrapper FQCN list, and tenancy-exception class+message at event time so `TenantDataCollector::collect()` can read them on `kernel.response`.

## What Was Built

`Tenancy\Bundle\Profiler\TenantProfilerStash` — a final, zero-constructor-arg event listener implementing `ResetInterface`. Subscribes to four events via stacked `#[AsEventListener]` attributes and exposes three getters. Bridges the gap between event-time data (`TenantResolved` fires on kernel.request) and collect-time read (`DataCollector::collect()` runs on kernel.response) while keeping `TenantContext` zero-dep.

## Public Surface (for Plan 02 collector)

```php
public function getResolvedBy(): ?string
public function getBootstrapperFqcns(): array          // string[]
public function getCapturedException(): ?array         // array{class: string, message: string}|null
public function reset(): void                          // ResetInterface
```

Event handler methods (called by the dispatcher — not normally invoked directly):
- `onTenantResolved(TenantResolved $event): void`
- `onTenantBootstrapped(TenantBootstrapped $event): void`
- `onTenantContextCleared(TenantContextCleared $event): void`
- `onKernelException(ExceptionEvent $event): void`

**Plan 02 note:** Return types are stable — `?string`, `string[]`, `array{class:string,message:string}|null`. Collector may still defensively normalise bootstrappers with `array_values(array_map('strval', ...))` as defence-in-depth.

## Tests (10/10 passing, 16 assertions)

| # | Test | Status |
|---|------|--------|
| 1 | testHasFourAsEventListenerAttributes | PASS |
| 2 | testAsEventListenerAttributesReferenceCorrectEventsAndMethods | PASS |
| 3 | testImplementsResetInterface | PASS |
| 4 | testInitiallyAllGettersReturnNullOrEmpty | PASS |
| 5 | testCapturesResolvedByOnTenantResolved | PASS |
| 6 | testCapturesBootstrappersOnTenantBootstrapped | PASS |
| 7 | testCapturesTenancyException | PASS |
| 8 | testIgnoresNonTenancyExceptions | PASS |
| 9 | testResetClearsAllFields | PASS |
| 10 | testOnTenantContextClearedCallsReset | PASS |

## Verification Gates (all green)

- `php -l src/Profiler/TenantProfilerStash.php` — exit 0
- `php -l tests/Unit/Profiler/TenantProfilerStashTest.php` — exit 0
- `vendor/bin/phpunit --filter TenantProfilerStashTest` — 10 tests, 0 failures
- `vendor/bin/phpstan analyse src/Profiler tests/Unit/Profiler --level=9` — `[OK] No errors`
- `vendor/bin/php-cs-fixer fix src/Profiler/TenantProfilerStash.php --dry-run` — clean
- `vendor/bin/php-cs-fixer fix tests/Unit/Profiler/TenantProfilerStashTest.php --dry-run` — clean
- `grep -c EventSubscriberInterface src/Profiler/TenantProfilerStash.php` — 0 (bundle idiom preserved)
- `git diff src/Context/TenantContext.php` — empty (zero-dep contract preserved)

## PHPStan Annotations Used

- `/** @var string[] */` on `$bootstrapperFqcns`
- `/** @var array{class: string, message: string}|null */` on `$capturedException`
- `/** @return string[] */` on `getBootstrapperFqcns()`
- `/** @return array{class: string, message: string}|null */` on `getCapturedException()`

Level 9 passes without further suppression.

## TDD Gate Compliance

- RED commit `d32009d` — `test(19-01): RED — add failing TenantProfilerStashTest` (10 failing tests, class-not-found)
- GREEN commit `3f5a263` — `feat(19-01): GREEN — implement TenantProfilerStash` (10/10 pass)
- REFACTOR — not needed; implementation was minimal and clean on first pass.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Updated TenantResolved constructor calls in test file**
- **Found during:** Task 01-01 (writing the test file)
- **Issue:** Plan skeleton called `new TenantResolved(tenant, 'resolver-fqcn')` (2 args), but the actual `src/Event/TenantResolved.php` constructor signature is `(TenantInterface $tenant, ?Request $request, string $resolvedBy)` — 3 args. The plan was written against an older event shape.
- **Fix:** Pass `null` for the `$request` argument in every test that constructs `TenantResolved` (tests 5, 9, 10). This is legal per the constructor's `?Request` type and does not affect what the stash captures (only `$resolvedBy` is read).
- **Files modified:** tests/Unit/Profiler/TenantProfilerStashTest.php (3 call sites)
- **Commit:** d32009d

No other deviations. Implementation file matched the plan verbatim.

## Threat Model Outcomes

| Threat | Mitigation in this plan |
|--------|------------------------|
| T-19-04 (stale state in long-running runtimes) | Implements `ResetInterface` (autoconfigure adds `kernel.reset` tag); also resets on `TenantContextCleared`. Tested by `testResetClearsAllFields` + `testOnTenantContextClearedCallsReset`. |
| T-19-05 (information disclosure — application exception text) | Hard-coded `str_starts_with(::class, 'Tenancy\\Bundle\\Exception\\')` predicate. Tested by `testIgnoresNonTenancyExceptions`. |
| T-19-06 (Throwable serialization leak via previous chain) | Stores only `['class' => string, 'message' => string]` — Throwable instance is never retained. |

## Known Stubs

None — the stash is fully functional. DI registration happens in Plan 04 (not a stub: Plan 04 is the explicit next step, this plan's scope ends at the class definition).

## Threat Flags

None — no new network endpoints, auth paths, file access patterns, or trust-boundary surface introduced.

## Self-Check: PASSED

- FOUND: src/Profiler/TenantProfilerStash.php
- FOUND: tests/Unit/Profiler/TenantProfilerStashTest.php
- FOUND: commit d32009d (test/RED)
- FOUND: commit 3f5a263 (feat/GREEN)
