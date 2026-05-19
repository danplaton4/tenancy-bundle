---
phase: 5
slug: infrastructure-bootstrappers
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-03-19
---

# Phase 5 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11 |
| **Config file** | `phpunit.xml.dist` |
| **Quick run command** | `vendor/bin/phpunit --testsuite unit` |
| **Full suite command** | `vendor/bin/phpunit` |
| **Estimated runtime** | ~15 seconds |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit --testsuite unit`
- **After every plan wave:** Run `vendor/bin/phpunit`
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** 15 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 5-01-01 | 01 | 1 | BOOT-01 | unit | `vendor/bin/phpunit tests/Unit/Bootstrapper/DoctrineBootstrapperTest.php` | ❌ W0 | ⬜ pending |
| 5-01-02 | 01 | 1 | BOOT-01 | unit | `vendor/bin/phpunit tests/Unit/Bootstrapper/DoctrineBootstrapperTest.php` | ❌ W0 | ⬜ pending |
| 5-01-03 | 01 | 1 | BOOT-01 | unit | `vendor/bin/phpunit tests/Unit/EventListener/EntityManagerResetListenerTest.php` | ✅ (update) | ⬜ pending |
| 5-01-04 | 01 | 2 | BOOT-01 | integration | `vendor/bin/phpunit tests/Integration/DoctrineBootstrapperIntegrationTest.php` | ❌ W0 | ⬜ pending |
| 5-02-01 | 02 | 1 | BOOT-02 | unit | `vendor/bin/phpunit tests/Unit/Cache/TenantAwareCacheAdapterTest.php` | ❌ W0 | ⬜ pending |
| 5-02-02 | 02 | 2 | BOOT-02 | integration | `vendor/bin/phpunit tests/Integration/CacheBootstrapperIntegrationTest.php` | ❌ W0 | ⬜ pending |
| 5-02-03 | 02 | 2 | BOOT-02 | integration | `vendor/bin/phpunit tests/Integration/CacheBootstrapperIntegrationTest.php` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Unit/Bootstrapper/DoctrineBootstrapperTest.php` — stubs for BOOT-01 unit assertions (boot/clear call EM::clear)
- [ ] `tests/Unit/Cache/TenantAwareCacheAdapterTest.php` — stubs for BOOT-02 unit assertions (decorator wiring, per-operation namespace)
- [ ] `tests/Integration/DoctrineBootstrapperIntegrationTest.php` — stubs for BOOT-01 identity map isolation integration test
- [ ] `tests/Integration/CacheBootstrapperIntegrationTest.php` — stubs for BOOT-02 namespace isolation and cross-tenant isolation integration tests

*Existing: `tests/Unit/EventListener/EntityManagerResetListenerTest.php` covers BOOT-01 fix — needs assertion update only (no stub required).*

---

## Manual-Only Verifications

*All phase behaviors have automated verification.*

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 15s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
