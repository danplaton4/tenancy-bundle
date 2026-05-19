---
phase: 07-cli-commands
verified: 2026-04-01T00:00:00Z
status: passed
score: 9/9 must-haves verified
re_verification: false
---

# Phase 7: CLI Commands Verification Report

**Phase Goal:** Operators can run Doctrine migrations for all tenants from a single command and can execute any Symfony console command scoped to a specific tenant
**Verified:** 2026-04-01
**Status:** passed
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | `tenancy:migrate` iterates all tenants from `TenantProviderInterface::findAll()` and runs Doctrine migrations for each | VERIFIED | `TenantMigrateCommand::execute()` calls `$this->tenantProvider->findAll()` (line 68); `runMigrationsForTenant()` calls `DependencyFactory::fromConnection()` then migrates (lines 113–129) |
| 2 | `tenancy:migrate` continues on tenant failure, prints per-tenant status, exits 1 if any failed | VERIFIED | try/catch/finally loop (lines 81–91); `$failures[]` accumulation; `Command::FAILURE` returned when `$failures !== []` (lines 96–103); unit test `testOneTenantFailsContinuesOthersAndExitsFailure` passes |
| 3 | `tenancy:migrate` rejects `shared_db` driver with stderr error and exit code 1 | VERIFIED | Lines 52–61 check `$this->driver === 'shared_db'`, write to `$output->getErrorOutput()`, return `Command::FAILURE`; unit test `testSharedDbDriverRejectsWithError` passes |
| 4 | `tenancy:migrate` accepts `--tenant=<slug>` to run migrations for a single tenant only | VERIFIED | `configure()` adds `--tenant` option (lines 39–46); `execute()` branches on `$tenantSlug !== null` (lines 63–69); unit tests `testTenantFilterSingleTenant` and `testTenantFilterNonexistentThrowsTenantNotFoundException` pass |
| 5 | `tenancy:migrate` is not registered when `doctrine/migrations` is absent | VERIFIED | `config/services.php` lines 94–105 wrap registration in `class_exists(\Doctrine\Migrations\DependencyFactory::class)` guard; structurally sound, programmatically confirmed |
| 6 | `tenancy:run` spawns a subprocess with `--tenant=<slug>` and the inner command, forwarding stdout/stderr | VERIFIED | `TenantRunCommand::execute()` builds `$commandLine` with `--tenant=%s` (lines 46–52), calls `Process::fromShellCommandline()`, streams output via callback (lines 60–62); unit test `testValidTenantSpawnsProcess` and `testOutputForwarded` pass |
| 7 | `tenancy:run` exits with the child process exit code | VERIFIED | Line 64: `return $process->getExitCode() ?? 0`; unit test `testChildExitCodePropagated` passes with exit code 42 |
| 8 | `tenancy:run` validates the tenant exists before spawning the subprocess | VERIFIED | Line 42: `$this->tenantProvider->findBySlug($tenantSlug)` called before Process construction; unit test `testNonexistentTenantThrows` confirms `TenantNotFoundException` propagates before any process is spawned |
| 9 | Both commands are registered in container and discoverable | VERIFIED | Integration tests `testMigrateCommandIsRegistered` and `testRunCommandIsRegistered` pass; DI confirmed via reflection tests `testMigrateCommandReceivesCorrectDriver` and `testRunCommandReceivesProjectDir` |

**Score:** 9/9 truths verified

---

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `src/Provider/TenantProviderInterface.php` | `findAll(): array` method signature | VERIFIED | Line 24: `public function findAll(): array` with PHPDoc `@return TenantInterface[]` |
| `src/Provider/DoctrineTenantProvider.php` | `findAll()` implementation via landlord EM, no cache | VERIFIED | Lines 61–68: calls `$this->entityManager->getRepository(...)->findAll()`, does not use `$this->cache` |
| `src/Command/TenantMigrateCommand.php` | `tenancy:migrate` console command | VERIFIED | Line 24: `#[AsCommand(name: 'tenancy:migrate')]`; `final class`; full implementation 132 lines |
| `src/Command/TenantRunCommand.php` | `tenancy:run` console command | VERIFIED | Line 15: `#[AsCommand(name: 'tenancy:run')]`; `final class`; Process-based subprocess spawning |
| `config/services.php` | DI registration with `class_exists` guard for migrate; unconditional for run | VERIFIED | Lines 94–112: migrate wrapped in `class_exists(\Doctrine\Migrations\DependencyFactory::class)`, run registered unconditionally with `param('kernel.project_dir')` |
| `composer.json` | `symfony/process` in `require`; `doctrine/migrations` in `require-dev` + `suggest` | VERIFIED | `require`: `"symfony/process": "^6.4||^7.0"`; `require-dev`: `"doctrine/migrations": "^3.9"`; `suggest`: both entries present |
| `tests/Unit/Command/TenantMigrateCommandTest.php` | Unit tests for tenancy:migrate | VERIFIED | 6 tests covering: shared_db guard, empty list, continue-on-failure, --tenant filter, TenantNotFoundException, finally cleanup |
| `tests/Unit/Command/TenantRunCommandTest.php` | Unit tests for tenancy:run | VERIFIED | 4 tests covering: process spawning with correct args, exit code propagation, output forwarding, TenantNotFoundException |
| `tests/Integration/Command/TenantMigrateCommandIntegrationTest.php` | Integration tests for tenancy:migrate DI | VERIFIED | 4 tests: service registered, is TenantMigrateCommand instance, receives correct driver, tenancy.command.run also registered |
| `tests/Integration/Command/TenantRunCommandIntegrationTest.php` | Integration tests for tenancy:run DI | VERIFIED | 3 tests: service registered, is TenantRunCommand instance, receives kernel.project_dir |
| `tests/Integration/Command/Support/CommandTestKernel.php` | Test kernel for command integration tests | VERIFIED | Registers FrameworkBundle + TenancyBundle, stubs Doctrine services, includes MakeCommandsPublicPass + ReplaceTenancyProviderPass |

---

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `TenantMigrateCommand.php` | `TenantProviderInterface.php` | `findAll()` call in `execute()` | WIRED | Line 68: `$this->tenantProvider->findAll()` |
| `TenantMigrateCommand.php` | `TenantProviderInterface.php` | `findBySlug()` in `--tenant` branch | WIRED | Line 66: `$this->tenantProvider->findBySlug((string) $tenantSlug)` |
| `TenantMigrateCommand.php` | `BootstrapperChain.php` | `boot()` and `clear()` per tenant | WIRED | Line 111: `$this->bootstrapperChain->boot($tenant)`; line 89: `$this->bootstrapperChain->clear()` in finally |
| `TenantMigrateCommand.php` | `TenantContext.php` | `setTenant()` and `clear()` per tenant | WIRED | Line 110: `$this->tenantContext->setTenant($tenant)`; line 88: `$this->tenantContext->clear()` in finally |
| `config/services.php` | `TenantMigrateCommand.php` | `class_exists` guard for DI registration | WIRED | Lines 94–105: `class_exists(\Doctrine\Migrations\DependencyFactory::class)` guard |
| `config/services.php` | `TenantRunCommand.php` | Unconditional DI registration | WIRED | Lines 107–112: no guard; receives `service('tenancy.provider')` and `param('kernel.project_dir')` |
| `TenantRunCommand.php` | `TenantProviderInterface.php` | `findBySlug()` to validate tenant | WIRED | Line 42: `$this->tenantProvider->findBySlug($tenantSlug)` |
| `TenantRunCommand.php` | `symfony/process` | `Process::fromShellCommandline()` subprocess | WIRED | Line 12: `use Symfony\Component\Process\Process`; line 56: `Process::fromShellCommandline($commandLine)` |

---

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| CLI-01 | 07-01, 07-03 | `tenancy:migrate` runs Doctrine migrations for every tenant sequentially, reporting per-tenant success/failure | SATISFIED | Command fully implemented; iterates `findAll()`, runs migrations per tenant, continues on failure, prints per-tenant status, exits 1 on any failure; unit and integration tests pass |
| CLI-02 | 07-02, 07-03 | `tenancy:run {tenantId} "command:name arg1"` wraps any Symfony console command with full tenant context bootstrapped | SATISFIED | Command fully implemented; validates tenant, spawns subprocess via `Process::fromShellCommandline()` with `--tenant=<slug>` appended, forwards stdout/stderr, propagates exit code; `symfony/process` in production `require` block; unit and integration tests pass |

**Note on CLI-01 REQUIREMENTS.md status:** The REQUIREMENTS.md shows `- [ ] **CLI-01**` (unchecked) while `- [x] **CLI-02**` is checked. The ROADMAP also shows `1/3 plans executed` for Phase 7 (reflecting state at the time of the last ROADMAP update). The actual codebase evidence confirms CLI-01 is fully implemented — both commands exist, tests pass, and all acceptance criteria are met.

---

### Anti-Patterns Found

No anti-patterns detected in phase 07 source files.

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| — | — | No TODOs, FIXMEs, stubs, or empty implementations found | — | — |

---

### Pre-Existing Test Failures (Not Introduced by Phase 7)

The full test suite (`php vendor/bin/phpunit --no-coverage`) shows 17 errors, but these are pre-existing failures unrelated to Phase 7:

- **7 errors**: `TenantAwareFilterTest` — fails with `symfony/var-exporter` LazyGhost unavailability (Doctrine ORM PHP 8.4 compatibility issue, pre-dates Phase 7)
- **10 errors**: Various integration tests (`AutoconfigurationTest`, `ContainerCompilationTest`, `DatabaseSwitchIntegrationTest`, etc.) — fail with `ServiceNotFoundException: doctrine.orm.default_entity_manager` (pre-existing test kernel configuration issue)

Confirmed pre-existing: `git diff e310e05 HEAD -- tests/Integration/AutoconfigurationTest.php` returns empty (file not modified by Phase 7); same failure reproduced at commit `e310e05` (last Phase 6 commit).

**Phase 7 target tests all pass:**
- `tests/Unit/Command/TenantMigrateCommandTest.php` — 6/6 tests pass
- `tests/Unit/Command/TenantRunCommandTest.php` — 4/4 tests pass
- `tests/Unit/Provider/` — 8/8 tests pass (includes `findAll()`)
- `tests/Integration/Command/` — 7/7 tests pass

---

### Human Verification Required

None. All behavioral requirements are exercised by passing automated tests. No visual, real-time, or external-service behavior requires human inspection.

---

### Gaps Summary

No gaps. All 9 observable truths are verified, all required artifacts exist and are substantive, all key links are wired, and both requirements (CLI-01, CLI-02) are satisfied by the codebase.

---

_Verified: 2026-04-01_
_Verifier: Claude (gsd-verifier)_
