---
phase: 04
slug: shared-db-driver
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-03-19
---

# Phase 04 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11 |
| **Config file** | `phpunit.xml.dist` |
| **Quick run command** | `vendor/bin/phpunit tests/Unit/Filter/ --no-coverage` |
| **Full suite command** | `vendor/bin/phpunit --no-coverage` |
| **Estimated runtime** | ~5 seconds (unit), ~15 seconds (full) |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit tests/Unit/Filter/ --no-coverage`
- **After every plan wave:** Run `vendor/bin/phpunit --no-coverage`
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** ~15 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 04-01-01 | 01 | 1 | ISOL-04 | unit | `vendor/bin/phpunit tests/Unit/Attribute/TenantAwareTest.php --no-coverage` | ❌ W0 | ⬜ pending |
| 04-02-01 | 02 | 1 | ISOL-03, ISOL-04 | unit | `vendor/bin/phpunit tests/Unit/Filter/TenantAwareFilterTest.php --no-coverage` | ❌ W0 | ⬜ pending |
| 04-03-01 | 03 | 2 | ISOL-05 | unit | `vendor/bin/phpunit tests/Unit/Filter/TenantAwareFilterTest.php --no-coverage` | ❌ W0 | ⬜ pending |
| 04-03-02 | 03 | 2 | ISOL-03, ISOL-05 | unit | `vendor/bin/phpunit --testsuite=unit --no-coverage` | ❌ W0 | ⬜ pending |
| 04-03-02 | 03 | 3 | ISOL-03, ISOL-04, ISOL-05 | integration | `vendor/bin/phpunit tests/Integration/SharedDbFilterIntegrationTest.php --no-coverage` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Unit/Filter/TenantAwareFilterTest.php` — unit test stubs for `addFilterConstraint` (attribute present/absent, strict/permissive, no-tenant paths)
- [ ] `tests/Unit/Attribute/TenantAwareTest.php` — attribute target enforcement (class-only)
- [ ] `tests/Integration/SharedDbFilterIntegrationTest.php` — end-to-end SQL filter scoping stubs
- [ ] `tests/Integration/Support/SharedDbTestKernel.php` — single-EM kernel with `tenancy.driver: shared_db`
- [ ] `tests/Integration/Support/Entity/TestTenantProduct.php` — `#[TenantAware]` entity with `tenant_id VARCHAR(63)` column

*All Wave 0 files are created as stubs (empty test classes with method signatures) before implementation begins. Implementation plans fill them in.*

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
