---
phase: 13-audit-gap-closure
fixed_at: 2026-04-13T00:00:00Z
review_path: .planning/phases/13-audit-gap-closure/13-REVIEW.md
iteration: 1
findings_in_scope: 4
fixed: 4
skipped: 0
status: all_fixed
---

# Phase 13: Code Review Fix Report

**Fixed at:** 2026-04-13T00:00:00Z
**Source review:** .planning/phases/13-audit-gap-closure/13-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 4 (1 Critical, 3 Warning; Info findings excluded per fix_scope)
- Fixed: 4
- Skipped: 0

## Fixed Issues

### CR-01: `DoctrineTenantProvider` registered unconditionally with optional Doctrine ORM service

**Files modified:** `config/services.php`
**Commit:** 0b082a3
**Applied fix:** Wrapped the `tenancy.provider` / `DoctrineTenantProvider` service registration in an `interface_exists(\Doctrine\ORM\EntityManagerInterface::class)` guard (matching the existing pattern for `DoctrineBootstrapper` on line 86). All six downstream references to `service('tenancy.provider')` — in `HostResolver`, `HeaderResolver`, `QueryParamResolver`, `ConsoleResolver`, `TenantRunCommand`, and `TenantWorkerMiddleware` — were updated to use `->nullOnInvalid()` so they do not cause a `ServiceNotFoundException` when Doctrine ORM is absent.

---

### WR-01: `TenantMigrateCommand::execute` — `findBySlug` failure propagates as unhandled exception

**Files modified:** `src/Command/TenantMigrateCommand.php`, `tests/Unit/Command/TenantMigrateCommandTest.php`
**Commit:** 3c64a96
**Applied fix:** Wrapped the `$this->tenantProvider->findBySlug($tenantSlug)` call in a `try/catch` that catches both `TenantNotFoundException` and `TenantInactiveException`, calls `$io->error($e->getMessage())`, and returns `Command::FAILURE`. Updated the test `testTenantFilterNonexistentThrowsTenantNotFoundException` (renamed to `testTenantFilterNonexistentReturnsFailureWithErrorMessage`) to assert `Command::FAILURE` exit code and the error message in output rather than `expectException`. Added the missing `use Symfony\Component\Console\Command\Command` import to the test file.

---

### WR-02: `ResolverChainPass` — built-in resolver filtering relies on service ID equalling FQCN

**Files modified:** `src/DependencyInjection/Compiler/ResolverChainPass.php`
**Commit:** b1dba1f
**Applied fix:** Inside the filtering loop, when `$allowedFqcns` is active, the code now calls `$container->findDefinition($serviceId)` to obtain the `Definition` object and reads `$resolverDefinition->getClass() ?? $serviceId` as the authoritative FQCN. Both `in_array` checks now compare against `$fqcn` instead of `$serviceId`, so a built-in resolver registered under a different service ID (alias, decorator) is still correctly identified and filtered.

---

### WR-03: `TenantMigrateCommand` — `shared_db` guard is dead code; command never registered for that driver

**Files modified:** `src/Command/TenantMigrateCommand.php`
**Commit:** 518678f
**Applied fix:** Applied Option B from the review: added a clarifying comment above the `if ('shared_db' === $this->driver)` guard explaining the invariant — that this branch is unreachable in production because the DI container only registers `TenantMigrateCommand` when `database.enabled: true`, and the configuration schema rejects `driver: shared_db` combined with `database.enabled: true`. The guard is retained for testability and defence-in-depth.

---

_Fixed: 2026-04-13T00:00:00Z_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
