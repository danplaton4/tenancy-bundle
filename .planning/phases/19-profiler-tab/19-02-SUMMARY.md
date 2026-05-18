---
phase: 19-profiler-tab
plan: 02
subsystem: profiler
tags: [profiler, data-collector, dx]
requirements: [DX-02]
dependency-graph:
  requires: [00, 01]
  provides:
    - "TenantDataCollector class with locked 8-key scalar-only data shape (D-08)"
    - "Public getData(): array<string, mixed> accessor for tests and integration round-trip"
    - "Hardcoded template path '@Tenancy/Collector/tenant.html.twig' (consumed by Plan 03)"
    - "Constructor argument order (stash, tenantContext, driver, landlordConnection) — Plan 04 wires DI against this"
    - "DSN-defence RuntimeException message 'looks like a DSN' (greppable by future regression tests)"
  affects: [03, 04, 05, 06]
tech-stack:
  added: []
  patterns:
    - "AbstractDataCollector subclass + collect() (NOT lateCollect)"
    - "private readonly constructor promotion"
    - "match expression for driver -> connection_name resolution"
    - "Defensive tripwire (str_contains ':' / '@') on operator-controlled DI parameter"
    - "Scalar normalization: array_values(array_map('strval', ...))"
key-files:
  created:
    - "src/Profiler/TenantDataCollector.php"
    - "src/Profiler/TenantProfilerStash.php (minimal stub — see Notes)"
    - "tests/Unit/Profiler/TenantDataCollectorTest.php"
  modified: []
decisions:
  - "Override getTemplate() with `: string` (narrowing parent's `?string`) — PHPStan level 9 flagged the unused null type; PHP covariance permits the narrowing"
  - "getData() exposes $this->data publicly so tests can assert the 8-key shape without reflection; also unlocks the serialization round-trip test in Plan 06"
  - "Created minimal local TenantProfilerStash stub in this worktree so test mocks resolve; Plan 19-01 (parallel) ships the canonical implementation that will supersede this at merge time"
metrics:
  completed: "2026-05-18"
---

# Phase 19 Plan 02: TenantDataCollector Summary

`TenantDataCollector` extends `Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector` and produces an 8-key scalar-only `$this->data` shape on `kernel.response`, locking the contract every downstream Plan 19 artifact reads.

## 8-Key Shape (Locked — D-08)

```php
[
  'state'           => 'resolved'|'null'|'error',     // computed from TenantContext + stash exception capture
  'slug'            => string|null,                    // $tenant?->getSlug()
  'tenant_label'    => string|null,                    // $tenant?->getName()
  'driver'          => string,                         // %tenancy.driver% verbatim
  'connection_name' => string|null,                    // 'tenant' for d_p_t, %tenancy.landlord_connection% for shared_db
  'resolved_by'     => string|null,                    // $stash->getResolvedBy()
  'bootstrappers'   => string[],                       // array_values(array_map('strval', $stash->getBootstrapperFqcns()))
  'error'           => array{class:string,message:string}|null,  // $stash->getCapturedException()
]
```

State computation order:
1. `state = 'resolved'` if `$this->tenantContext->getTenant() !== null`
2. else `state = 'error'` if `$this->stash->getCapturedException() !== null`
3. else `state = 'null'`

## Constructor Signature (For Plan 04 DI Wiring)

Positional order:

```php
public function __construct(
    private readonly TenantProfilerStash $stash,
    private readonly TenantContext $tenantContext,
    private readonly string $driver,             // from param('tenancy.driver')
    private readonly string $landlordConnection, // from param('tenancy.landlord_connection')
)
```

Plan 04 must wire `service('tenancy.profiler.stash')`, `service('tenancy.context')`, `param('tenancy.driver')`, `param('tenancy.landlord_connection')` in that exact order.

## Profiler Identity (For Plan 03 + Plan 04)

- `getName(): string` returns the literal `'tenancy'` — the profiler URL `/_profiler/{token}?panel=tenancy` resolves only when both this and Plan 04's `data_collector` tag `id` attribute agree on `'tenancy'`.
- `getTemplate(): string` returns the literal `'@Tenancy/Collector/tenant.html.twig'` — Plan 03 must create the template at `src/Resources/views/Collector/tenant.html.twig`. Plan 04's `data_collector` tag `template` attribute MUST be the identical string for the WDT badge to render.
- `getTemplate()` is `: string` (narrowed from the parent's `: ?string`) — PHPStan level 9 demanded the narrowing; PHP covariance permits it.

## DSN-Defence Exception (For Plan 05 SourceLayoutTest + Future Regression Tests)

The defensive tripwire throws this exact message format when `connection_name` contains `:` or `@`:

```
TenantDataCollector: connection_name "<value>" looks like a DSN — never display credentials.
```

The greppable invariant is the literal substring `looks like a DSN` (present exactly once in `src/Profiler/TenantDataCollector.php`). Two unit tests assert the throw fires for `mysql://user:pass@host/db` and for `user@host` respectively.

## Verification Evidence

- `php -l src/Profiler/TenantDataCollector.php` — no syntax errors
- `vendor/bin/phpunit --filter TenantDataCollectorTest` — 12 tests, 24 assertions, 0 failures
- `vendor/bin/phpunit --testsuite unit` — 303 tests, 822 assertions, 0 failures (no regressions in the broader suite)
- `vendor/bin/phpstan analyse src/Profiler tests/Unit/Profiler --level=9` — `[OK] No errors`
- `vendor/bin/php-cs-fixer fix src/Profiler/TenantDataCollector.php --diff --dry-run` — no fixable issues

## Tasks Completed

| Task | Name | Commit |
|------|------|--------|
| 02-01 | RED — add failing TenantDataCollectorTest (12 test methods) | `fe60da1` |
| 02-02 | GREEN — implement TenantDataCollector + minimal stash stub | `2c3e994` |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — PHPStan correctness] Narrowed `getTemplate()` return type from `?string` to `string`**
- **Found during:** Task 02-02 verification
- **Issue:** PHPStan level 9 (with `treatPhpDocTypesAsCertain: false`) flagged `return.unusedType` because the method body always returns a non-null string literal; the `?string` declared return type was strictly wider than the actual return.
- **Fix:** Changed signature to `public static function getTemplate(): string`. PHP allows narrowing the parent (`AbstractDataCollector::getTemplate(): ?string`) under return-type covariance.
- **Files modified:** `src/Profiler/TenantDataCollector.php` (line 84)
- **Commit:** `2c3e994`
- **Impact for Plan 04:** The `data_collector` tag still uses the same template string; no contract change for the DI tag attribute.

**2. [Rule 1 — PHPStan correctness] Removed redundant scalar/string post-narrow assertions in tests**
- **Found during:** Task 02-02 verification (running PHPStan on `tests/Unit/Profiler/` as the plan's verify clause demands)
- **Issue:** PHPStan flagged `assertIsScalar($value)` after `if (is_scalar($value))` and `assertIsString($b)` after iterating a known `array{0: string, 1: string}` as `staticMethod.alreadyNarrowedType` errors.
- **Fix:** Removed the redundant inner assertions; kept the structural assertions (`assertIsArray`, `assertSame` of expected values) that carry test value. The defensive intent (catch object/closure leakage) is preserved because `assertIsArray` runs after the scalar branch and rejects non-array values.
- **Files modified:** `tests/Unit/Profiler/TenantDataCollectorTest.php` (lines 188–197, 214)
- **Commit:** `2c3e994`

**3. [Rule 3 — blocking dependency] Created minimal `TenantProfilerStash` stub in worktree**
- **Found during:** Task 02-01 setup
- **Issue:** Plan 19-01 (executing in parallel in a sibling worktree) creates `src/Profiler/TenantProfilerStash.php`. Because both agents branched from the same base commit, this worktree cannot see Plan 01's file. PHPUnit's `createMock()` and PHP's class-resolution for the collector typehint both require the class to exist.
- **Fix:** Wrote a minimal non-final `TenantProfilerStash` class exposing only the three public getters the collector reads (`getResolvedBy`, `getBootstrapperFqcns`, `getCapturedException`) with safe default returns (`null`, `[]`, `null`). All bodies are inert — the real semantics live in Plan 01's version. At merge time, Plan 01's full implementation (with `#[AsEventListener]` attributes, `ResetInterface`, and capture logic) supersedes this stub.
- **Files modified:** `src/Profiler/TenantProfilerStash.php` (new, 30 lines, marked as a stub in the docblock)
- **Commit:** `2c3e994`
- **Merge guidance:** When merging Plan 01 and Plan 02 onto trunk, take Plan 01's version of `src/Profiler/TenantProfilerStash.php` (full implementation). Plan 02's collector + test will work unmodified against Plan 01's stash because the public API surface (3 getters with the documented signatures) is identical.

## Notes for Downstream Plans

- **Plan 03 (Twig template):** Render `collector.data.state`, `collector.data.slug`, `collector.data.tenant_label`, etc. — keys are stable and scalar.
- **Plan 04 (DI registration):** Constructor arg order is `(stash, tenantContext, driver, landlordConnection)`. Tag with `data_collector` `id='tenancy'`, `template='@Tenancy/Collector/tenant.html.twig'`. Register only inside `if ($builder->getParameter('kernel.debug') === true)` guard per D-06.
- **Plan 05 (source-layout test):** May grep for `'looks like a DSN'` in `src/Profiler/TenantDataCollector.php` as evidence the defence is wired.
- **Plan 06 (serialization + WDT tests):** `getData()` is public and returns the 8-key array. Native `serialize($collector); unserialize(...);` round-trips losslessly because every value is scalar or string-array.

## Self-Check: PASSED

Files exist:
- FOUND: src/Profiler/TenantDataCollector.php
- FOUND: src/Profiler/TenantProfilerStash.php (stub)
- FOUND: tests/Unit/Profiler/TenantDataCollectorTest.php

Commits exist:
- FOUND: fe60da1 (RED — test)
- FOUND: 2c3e994 (GREEN — collector)
