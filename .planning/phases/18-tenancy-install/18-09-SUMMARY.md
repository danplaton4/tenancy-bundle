---
phase: 18-tenancy-install
plan: "09"
subsystem: resolver
tags: [gap-closure, zero-config, nullable-provider, fail-silent, wave-2]
dependency_graph:
  requires: [18-08]
  provides: [nullable-resolver-constructors, canary-partial-green]
  affects: [18-10, 18-11]
tech_stack:
  added: []
  patterns: [nullable-constructor-param, early-return-null-guard, fail-silent-resolver-chain]
key_files:
  created: []
  modified:
    - src/Resolver/HostResolver.php
    - src/Resolver/HeaderResolver.php
    - src/Resolver/QueryParamResolver.php
    - src/Resolver/ConsoleResolver.php
decisions:
  - "HTTP resolvers (HostResolver, HeaderResolver, QueryParamResolver) use ?TenantProviderInterface = null (optional, nullable) — container always passes either a real provider or null via nullOnInvalid()"
  - "ConsoleResolver uses ?TenantProviderInterface without = null — nullable-only, not optional — to avoid PHP 8.1+ deprecation warning for an optional parameter declared before three required parameters"
  - "Fail-silent policy: resolvers return null / no-op when provider is absent, letting the resolver chain fall through — correct for read-only request-time resolution"
  - "config/services.php left unchanged — nullOnInvalid() was already the correct DI expression of intent"
metrics:
  duration_minutes: ~15
  completed_date: "2026-05-21"
  tasks_completed: 2
  files_modified: 4
---

# Phase 18 Plan 09: Fail-Silent Resolver Fixes Summary

**One-liner:** 4 resolver constructors accept nullable TenantProviderInterface with early-return guards, restoring zero-config bootability for HostResolver, HeaderResolver, QueryParamResolver, and ConsoleResolver.

---

## What Was Built

### Task 1: HTTP Resolvers (HostResolver, HeaderResolver, QueryParamResolver)

Each of the 3 HTTP resolver files received two changes:

**Signature change (identical for all 3):**
```php
// Before (defect site):
private readonly TenantProviderInterface $tenantProvider,

// After (fix):
private readonly ?TenantProviderInterface $tenantProvider = null,
```

**Guard insertion at top of `resolve()` (identical for all 3):**
```php
// Zero-config mode: no provider bound. Yield to next resolver in chain.
if (null === $this->tenantProvider) {
    return null;
}
```

For `HostResolver`, the new provider-null guard is placed BEFORE the existing `appDomain`-null guard, preserving the conventional order: provider-null → domain-null → slug extraction → findBySlug.

### Task 2: ConsoleResolver

**Signature change (nullable-only, no default):**
```php
// Before (defect site):
private readonly TenantProviderInterface $tenantProvider,

// After (fix — nullable without = null to avoid PHP 8.1+ optional-before-required):
private readonly ?TenantProviderInterface $tenantProvider,
```

**Guard insertion at top of `onConsoleCommand()` (void return, not null):**
```php
// Zero-config mode: no provider bound. Skip console tenant resolution.
if (null === $this->tenantProvider) {
    return;
}
```

The `= null` default is intentionally omitted because ConsoleResolver has 3 required parameters after `$tenantProvider` (`TenantContext`, `BootstrapperChain`, `EventDispatcherInterface`). Adding `= null` would create a PHP 8.1+ deprecated signature (optional before required). The container always supplies all 4 positional arguments via `->args([..., nullOnInvalid(), ..., ..., ...])` — the null arrives positionally from `nullOnInvalid()`, not from the default.

---

## Fail-Silent Policy Rationale

The resolver chain is a **read-only HTTP/console request-time chain**: when no `tenancy.provider` is configured, there is no tenant universe to resolve against. Returning `null` from each resolver is correct — the chain falls through to null-resolution, which the system already handles (DX-02 Profiler "null-resolution" state). This differs from the write-path sites (`TenantRunCommand`, `TenantWorkerMiddleware`) where fail-loud (throw `RuntimeException`) is safer — those are handled by plan 18-10.

---

## DI Wiring

`config/services.php` was **not modified**. The `nullOnInvalid()` expressions (lines 56, 62, 66, 72) were already the correct DI intent — the mismatch was entirely in the consumer constructors. The null-safe constructor signatures now align with the DI wiring.

---

## Test Results

### Existing Test Suite (545 tests, 2 runs)

Both task commits passed the full pre-commit test suite:
- 545 tests, 2011 assertions — OK
- All resolver unit + integration tests green

### Canary Test (ZeroConfigKernelBootTest)

Before this plan (RED state): 2 errors
```
ERRORS! Tests: 3, Assertions: 4, Errors: 2
```

After this plan (GREEN for resolver sites):
```
OK, but there were issues!
Tests: 3, Assertions: 6, PHPUnit Deprecations: 1, Risky: 1.
```

The "Risky" flag is the known PHPUnit 11 kernel boot handler note (not a failure). All 3 canary assertions pass:
- `testContainerCompilesAndKernelBoots` — PASS (risky = kernel handler note)
- `testHostResolverInstantiatesWithNullProvider` — PASS
- `testConsoleApplicationVersionCommandExitsZero` — PASS

The remaining 2 defect sites (`TenantRunCommand`, `TenantWorkerMiddleware`) are handled by plan 18-10. Full GREEN-bar verification is plan 18-11's responsibility.

---

## Deviations from Plan

None — plan executed exactly as written.

The plan noted that services.php might need `->nullOnInvalid()` changes. Reading the file confirmed it already had the correct wiring at all 4 sites (lines 56, 62, 66, 72). No changes to services.php were needed.

---

## Commits

| Task | Commit | Files |
|------|--------|-------|
| 1 — HTTP resolvers | f0f5491 | src/Resolver/HostResolver.php, src/Resolver/HeaderResolver.php, src/Resolver/QueryParamResolver.php |
| 2 — ConsoleResolver | a88c74c | src/Resolver/ConsoleResolver.php |

---

## Handoff to Plan 18-10

The 2 remaining defect sites are:
- `src/Command/TenantRunCommand.php` (line 19) — write-path, fail-loud with RuntimeException
- `src/Messenger/TenantWorkerMiddleware.php` (line 21) — write-path, fail-loud with RuntimeException

Plan 18-10 addresses these with the same nullable-constructor pattern but fail-loud (throw RuntimeException with actionable message) instead of fail-silent.

---

## Self-Check: PASSED

| Item | Status |
|------|--------|
| src/Resolver/HostResolver.php | FOUND |
| src/Resolver/HeaderResolver.php | FOUND |
| src/Resolver/QueryParamResolver.php | FOUND |
| src/Resolver/ConsoleResolver.php | FOUND |
| .planning/phases/18-tenancy-install/18-09-SUMMARY.md | FOUND |
| Commit f0f5491 | FOUND |
| Commit a88c74c | FOUND |
