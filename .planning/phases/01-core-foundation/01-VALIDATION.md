---
phase: 1
slug: core-foundation
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-03-17
---

# Phase 1 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11 |
| **Config file** | `phpunit.xml.dist` (Wave 0 creates) |
| **Quick run command** | `vendor/bin/phpunit --testsuite unit` |
| **Full suite command** | `vendor/bin/phpunit` |
| **Estimated runtime** | ~10 seconds |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit --testsuite unit`
- **After every plan wave:** Run `vendor/bin/phpunit`
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** ~10 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 01-01-T1 | 01 | 1 | CORE-03 | unit | `vendor/bin/phpunit tests/Unit/DependencyInjection/` | ❌ W0 | ⬜ pending |
| 01-01-T2 | 01 | 1 | CORE-03 | unit | `vendor/bin/phpunit tests/Unit/DependencyInjection/BootstrapperChainPassTest.php` | ❌ W0 | ⬜ pending |
| 01-02-T1 | 02 | 1 | CORE-01 | unit | `vendor/bin/phpunit tests/Unit/TenantContextTest.php` | ❌ W0 | ⬜ pending |
| 01-02-T2 | 02 | 1 | CORE-03 | unit | `vendor/bin/phpunit tests/Unit/Bootstrapper/BootstrapperChainTest.php` | ❌ W0 | ⬜ pending |
| 01-03-T1 | 03 | 2 | CORE-02 | unit | `vendor/bin/phpunit tests/Unit/Event/` | ❌ W0 | ⬜ pending |
| 01-04-T1 | 04 | 2 | CORE-04 | unit | `vendor/bin/phpunit tests/Unit/Entity/TenantEntityTest.php` | ❌ W0 | ⬜ pending |
| 01-05-T1 | 05 | 3 | CORE-05 | integration | `vendor/bin/phpunit tests/Integration/Listener/TenantContextOrchestratorTest.php` | ❌ W0 | ⬜ pending |
| 01-05-T2 | 05 | 3 | CORE-01 | integration | `vendor/bin/phpunit tests/Integration/` | ❌ W0 | ⬜ pending |

> **Note (01-04-T1):** CORE-04 is verified at the structural/unit level in Phase 1 (field definitions, TenantInterface implementation, attribute mapping). DB round-trip persistence against the landlord EntityManager is deferred to Phase 3, when the dual EntityManager configuration is available. The test file for this row is `tests/Unit/Entity/TenantEntityTest.php` (not `tests/Integration/Entity/TenantEntityTest.php`).

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `phpunit.xml.dist` — PHPUnit 11 config, test suites: unit (`tests/Unit/`) and integration (`tests/Integration/`)
- [ ] `tests/Unit/TenantContextTest.php` — stubs for CORE-01 (setTenant/getTenant/hasTenant/clear)
- [ ] `tests/Unit/DependencyInjection/BootstrapperChainPassTest.php` — stubs for CORE-03 (compiler pass collects tagged services)
- [ ] `tests/Unit/Bootstrapper/BootstrapperChainTest.php` — stubs for CORE-03 (chain runs bootstrappers in priority order)
- [ ] `tests/Unit/Event/TenantResolvedTest.php` — stubs for CORE-02 (event carries tenant, request, resolvedBy)
- [ ] `tests/Unit/Event/TenantBootstrappedTest.php` — stubs for CORE-02 (event carries tenant, bootstrappers[])
- [ ] `tests/Unit/Event/TenantContextClearedTest.php` — stubs for CORE-02 (signal-only event instantiates)
- [ ] `tests/Unit/Entity/TenantEntityTest.php` — stubs for CORE-04 (Tenant entity structural checks: field definitions, TenantInterface implementation, attribute mapping)
- [ ] ~~`tests/Integration/Entity/TenantEntityTest.php`~~ — **Deferred to Phase 3** — requires dual EntityManager configuration; DB round-trip persistence verified there
- [ ] `tests/Integration/Listener/TenantContextOrchestratorTest.php` — stubs for CORE-05 (priority 20, isMainRequest guard)
- [ ] `composer.json` — with phpunit/phpunit:^11 in require-dev

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Zero circular dependency errors | CORE-01 | Requires live Symfony container boot | Run `bin/console debug:container tenancy.context` in a test app; confirm no `ServiceCircularReferenceException` |
| tenancy.bootstrapper tag auto-discovery | CORE-03 | Requires container compilation with a real tagged service | Register a dummy bootstrapper tagged `tenancy.bootstrapper` in test app; confirm it appears in `BootstrapperChain` |
| Tenant entity DB round-trip persistence | CORE-04 | Deferred to Phase 3 integration tests when landlord EntityManager is fully configured | Verify in Phase 3 plan 03-05: persist a Tenant via the landlord EM and query it back |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 10s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
