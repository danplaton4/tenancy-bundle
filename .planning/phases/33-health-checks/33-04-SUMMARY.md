---
phase: 33-health-checks
plan: 04
subsystem: health
tags: [health-checks, cli-command, tdd, security, sanitization]
dependency_graph:
  requires:
    - src/Health/TenantHealthCheckerInterface.php (plan 33-01/02)
    - src/Health/TenantHealthChecker.php (plan 33-02)
    - src/Health/HealthResponseSanitizer.php (plan 33-01)
    - src/Health/TenantHealthReport.php (plan 33-01)
    - src/Health/BootstrapperHealthResult.php (plan 33-01)
    - src/Health/HealthStatus.php (plan 33-01)
    - src/Provider/TenantProviderInterface.php (existing)
    - src/Exception/TenantNotFoundException.php (existing)
    - src/Exception/TenantInactiveException.php (existing)
  provides:
    - src/Command/TenantHealthCommand.php
    - tests/Unit/Command/TenantHealthCommandTest.php
  affects:
    - plan 33-05 (wiring adds console.command tag for TenantHealthCommand)
tech_stack:
  added: []
  patterns:
    - mirror TenantMigrateCommand streaming structure (per-tenant loop, exit aggregation)
    - delegate probe lifecycle to TenantHealthChecker::checkOne() (no TenantContext dependency)
    - JSON aggregate to raw $output (NOT SymfonyStyle) — stdout carries only the document
    - HealthResponseSanitizer applied to every output string before printing (T-33-04)
    - nullable TenantProviderInterface — no-Doctrine lane guard
key_files:
  created:
    - src/Command/TenantHealthCommand.php
    - tests/Unit/Command/TenantHealthCommandTest.php
  modified: []
decisions:
  - "Command depends on TenantHealthCheckerInterface (not concrete TenantHealthChecker) so PHPUnit can mock it in unit tests"
  - "Warn status counts as success for exit-code purposes — only Fail triggers non-zero exit (D-09)"
  - "JSON output field 'output' is omitted for pass-status tenants (mirrors migrate 'error?' semantics)"
  - "testCommandDoesNotDependOnTenantContext uses regex on use-statements (not string contains) to allow docblock mentions"
metrics:
  duration: "~5 minutes"
  completed: "2026-07-06T07:33:50Z"
  tasks_completed: 2
  files_created: 2
  files_modified: 0
  tests_added: 15
---

# Phase 33 Plan 04: CLI Health Command Summary

`tenancy:health` CLI command with per-tenant streaming output, exit-code aggregation (non-zero on any Fail), and sanitized `--format=json` aggregate mode; delegates the probe lifecycle entirely to `TenantHealthChecker::checkOne()` — no TenantContext dependency.

## Tasks Completed

| Task | Name | Commit | Files Created/Modified |
|------|------|--------|----------------------|
| 1+2 | tenancy:health command (options, streaming, exit aggregation, JSON, sanitization) | 7e160e7 | TenantHealthCommand.php, TenantHealthCommandTest.php |

Note: Tasks 1 and 2 were committed together because the pre-commit hook runs the full PHPUnit suite — RED-only commits (test file without source) are blocked by the hook. Both tasks landed in a single GREEN commit with all 15 tests passing.

## Verification Results

- `vendor/bin/phpunit --filter TenantHealthCommandTest` — 15 tests, 45 assertions, 0 failures
- `vendor/bin/phpunit --testsuite unit` — 775 tests, 2093 assertions, 2 skipped, 0 failures
- `vendor/bin/phpunit` full suite — 941 tests, 3738 assertions, 2 skipped, 0 failures
- `vendor/bin/phpstan analyse --memory-limit=512M` — [OK] No errors (level 9)
- `vendor/bin/php-cs-fixer check --diff` — clean (@Symfony ruleset)
- `grep -q "tenancy:health" src/Command/TenantHealthCommand.php` — FOUND
- `grep -L "EntityManager" src/Command/TenantHealthCommand.php` — file listed (no EntityManager import)
- `grep "use.*TenantContext" src/Command/TenantHealthCommand.php` — no match (no TenantContext import)

## Deviations from Plan

**1. [Rule 1 - Bug] Structural test assertion too broad (string contains vs import check)**

- **Found during:** Task 1 (GREEN phase — test assertion failed)
- **Issue:** `testCommandDoesNotDependOnTenantContext` used `assertStringNotContainsString('TenantContext', ...)` but the source file mentions "TenantContext" in docblock comments — the assertion correctly failed even though no `use` import exists
- **Fix:** Changed to `assertDoesNotMatchRegularExpression('/^use\s+.*TenantContext\s*;/m', ...)` — tests only the `use` statement import, which is the actual dependency constraint
- **Files modified:** tests/Unit/Command/TenantHealthCommandTest.php
- **Impact:** Test now precisely asserts the behavioral constraint (no dependency injection of TenantContext) while allowing docblock mentions

**2. [Rule 3 - Blocking] RED commit blocked by pre-commit hook (PHPUnit runs full suite)**

- **Found during:** Task 1 RED phase commit attempt
- **Issue:** The pre-commit hook runs the full PHPUnit suite, blocking intentionally failing tests
- **Fix:** Implemented both Tasks 1 and 2 in a single GREEN pass — wrote tests first, then implemented source, then committed both together. All 15 tests pass in the commit.
- **Impact:** TDD spirit preserved (tests written before/alongside implementation); the RED→GREEN cycle was compressed to a single commit due to project tooling

## Must-Have Verification

| Truth | Status |
|-------|--------|
| `tenancy:health --tenant=<slug>` reports health for exactly one tenant | PROVEN (testSingleTenantHealthCheck: checkOne called once with that tenant) |
| `tenancy:health --all` iterates every tenant sequentially, streams per-tenant status, exits non-zero if any fail | PROVEN (testMixedFleetWithOneFailExitsFailure + testCheckerIsCalledOncePerTenantNoContextClear) |
| `tenancy:health --format=json` emits a single machine-readable aggregate object (mirrors migrate) | PROVEN (testJsonFormatProducesParseableAggregate: json_decode with JSON_THROW_ON_ERROR, tenants+summary keys) |
| The command output never contains a raw DSN or credential | PROVEN (testDsnPasswordIsRedactedInTxtOutput + testDsnPasswordIsRedactedInJsonOutput: 's3cr3t' absent, '***' present) |
| An unknown --tenant slug produces a clear error and non-zero exit | PROVEN (testUnknownTenantSlugReturnsFailureWithClearError) |

All artifacts from the plan's `must_haves`:

| Artifact | Status |
|----------|--------|
| src/Command/TenantHealthCommand.php (contains "tenancy:health") | EXISTS, confirmed |
| checker->checkOne delegation (command loop calls checkOne per tenant) | EXISTS, confirmed — no `->clear()` in execute() |

## Threat Surface Scan

- T-33-04 (Information Disclosure): Mitigated — every output string routed through `$this->sanitizer->sanitize()` before printing in both `executeTxt()` and `executeJson()`; two DSN redaction tests prove the guard (txt + json).
- T-33-CLI-CTX (Tampering — command probe loop): Mitigated — command takes no TenantContext dependency; lifecycle fully delegated to `checkOne()`; regex test asserts no `use TenantContext` import.
- T-33-CLI-BOUND (DoS — --all): Accepted by design per D-09. Explicit operator action, not auto-fired. No bound added.

No new threat surface beyond what the plan anticipated.

## Known Stubs

None — all implementations are complete behavioral code. The command fully implements streaming, exit aggregation, JSON mode, and sanitization. DI wiring (console.command tag) is intentionally deferred to plan 33-05 as specified.

## Self-Check: PASSED

Created files confirmed to exist:
- [x] src/Command/TenantHealthCommand.php
- [x] tests/Unit/Command/TenantHealthCommandTest.php

Commits verified in git log:
- [x] 7e160e7 feat(33-04): implement tenancy:health CLI command (HEALTH-05)
