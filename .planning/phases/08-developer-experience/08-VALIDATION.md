---
phase: 8
slug: developer-experience
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-04-02
---

# Phase 8 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.0 |
| **Config file** | `phpunit.xml.dist` |
| **Quick run command** | `./vendor/bin/phpunit --testsuite integration --filter InteractsWithTenancy` |
| **Full suite command** | `./vendor/bin/phpunit` |
| **Estimated runtime** | ~10 seconds |

---

## Sampling Rate

- **After every task commit:** Run `./vendor/bin/phpunit --testsuite integration --filter InteractsWithTenancy`
- **After every plan wave:** Run `./vendor/bin/phpunit`
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** ~10 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 08-01-01 | 01 | 1 | DX-01 | integration | `./vendor/bin/phpunit --filter testInitializeTenantBootsContextAndSchema` | ❌ W0 | ⬜ pending |
| 08-01-02 | 01 | 1 | DX-01 | integration | `./vendor/bin/phpunit --filter testTearDownClearsContextOnException` | ❌ W0 | ⬜ pending |
| 08-01-03 | 01 | 1 | DX-01 | integration | `./vendor/bin/phpunit --filter testTwoMethodsGetIsolatedDatabases` | ❌ W0 | ⬜ pending |
| 08-01-04 | 01 | 1 | DX-01 | integration | `./vendor/bin/phpunit --filter testAssertTenantActive` | ❌ W0 | ⬜ pending |
| 08-01-05 | 01 | 1 | DX-01 | integration | `./vendor/bin/phpunit --filter testAssertNoTenant` | ❌ W0 | ⬜ pending |
| 08-01-06 | 01 | 1 | DX-01 | integration | `./vendor/bin/phpunit --filter testGetTenantService` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Integration/Testing/InteractsWithTenancyTest.php` — test stubs covering DX-01a through DX-01f (6 test methods, all skipped/pending initially)
- [ ] `tests/Integration/Testing/Support/TenancyTestKernel.php` — database-mode kernel for the trait test suite (extends existing kernel patterns with DoctrineBundle + `database.enabled: true`)
- [ ] `tests/Integration/Testing/Support/MakeTenancyTestServicesPublicPass.php` — compiler pass exposing `tenancy.context`, `tenancy.bootstrapper_chain`, `doctrine.dbal.tenant_connection` in test container

*These files must exist (with stubs) before Wave 1 execution begins.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| tearDown() always runs even on setUp() exception | DX-01 | PHPUnit lifecycle guarantee — automated test cannot easily simulate a setUp() failure without restructuring the test class | Write one test class where setUp() throws, confirm tearDown() runs and context is null afterward |

*All other phase behaviors have automated verification.*

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 15s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
