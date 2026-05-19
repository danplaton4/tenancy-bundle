---
phase: 3
slug: database-per-tenant-driver
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-03-19
---

# Phase 3 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x |
| **Config file** | `phpunit.xml.dist` (root) |
| **Quick run command** | `./vendor/bin/phpunit --testsuite=unit` |
| **Full suite command** | `./vendor/bin/phpunit` |
| **Estimated runtime** | ~30 seconds |

---

## Sampling Rate

- **After every task commit:** Run `./vendor/bin/phpunit --testsuite=unit`
- **After every plan wave:** Run `./vendor/bin/phpunit`
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** ~30 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 3-01-01 | 01 | 1 | ISOL-01 | unit | `./vendor/bin/phpunit tests/Unit/DBAL/TenantConnectionTest.php -x` | ❌ W0 | ⬜ pending |
| 3-02-01 | 02 | 1 | ISOL-01 | unit | `./vendor/bin/phpunit tests/Unit/DBAL/TenantConnectionTest.php -x` | ❌ W0 | ⬜ pending |
| 3-02-02 | 02 | 1 | ISOL-01 | unit | `./vendor/bin/phpunit tests/Unit/DBAL/TenantConnectionTest.php::testReset -x` | ❌ W0 | ⬜ pending |
| 3-03-01 | 03 | 2 | ISOL-02 | integration | `./vendor/bin/phpunit tests/Integration/DatabaseSwitchIntegrationTest.php -x` | ❌ W0 | ⬜ pending |
| 3-03-02 | 03 | 2 | ISOL-02 | integration | `./vendor/bin/phpunit tests/Integration/DatabaseSwitchIntegrationTest.php::testLandlordEmUnaffected -x` | ❌ W0 | ⬜ pending |
| 3-04-01 | 04 | 2 | ISOL-01 | integration | `./vendor/bin/phpunit tests/Integration/EntityManagerResetIntegrationTest.php -x` | ❌ W0 | ⬜ pending |
| 3-01-02 | 01 | 1 | ISOL-01+02 | unit | `./vendor/bin/phpunit tests/Unit/Bootstrapper/DatabaseSwitchBootstrapperTest.php -x` | ❌ W0 | ⬜ pending |
| 3-05-01 | 05 | 3 | ISOL-01 | integration | `./vendor/bin/phpunit tests/Integration/DatabaseSwitchIntegrationTest.php -x` | ❌ W0 | ⬜ pending |
| 3-05-02 | 05 | 3 | ISOL-02 | integration | `./vendor/bin/phpunit tests/Integration/EntityManagerResetIntegrationTest.php -x` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Unit/DBAL/TenantConnectionTest.php` — stubs for ISOL-01 (switchTenant, reset, reflection)
- [ ] `tests/Unit/Bootstrapper/DatabaseSwitchBootstrapperTest.php` — stubs for boot/clear delegation
- [ ] `tests/Integration/DoctrineTestKernel.php` — dual-EM test kernel with file-based SQLite
- [ ] `tests/Integration/DatabaseSwitchIntegrationTest.php` — stubs for cross-tenant query isolation
- [ ] `tests/Integration/EntityManagerResetIntegrationTest.php` — stubs for resetManager('tenant') behavior

*Existing infrastructure (`phpunit.xml.dist`, `tests/bootstrap.php`) already present — no framework install needed.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| SQLite file cleanup on test failure | ISOL-01 | Teardown may not run on crash | Inspect `sys_get_temp_dir()` for orphaned `.db` files after failed test runs |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
