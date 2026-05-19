---
phase: 6
slug: messenger-integration
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-03-19
---

# Phase 6 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11 |
| **Config file** | `phpunit.xml.dist` |
| **Quick run command** | `./vendor/bin/phpunit --testsuite messenger` |
| **Full suite command** | `./vendor/bin/phpunit` |
| **Estimated runtime** | ~15 seconds |

---

## Sampling Rate

- **After every task commit:** Run `./vendor/bin/phpunit --testsuite messenger`
- **After every plan wave:** Run `./vendor/bin/phpunit`
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** 15 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 6-01-01 | 01 | 1 | MSG-01 | unit | `./vendor/bin/phpunit tests/Unit/Messenger/TenantStampTest.php` | ❌ W0 | ⬜ pending |
| 6-02-01 | 02 | 1 | MSG-01 | unit | `./vendor/bin/phpunit tests/Unit/Messenger/TenantSendingMiddlewareTest.php` | ❌ W0 | ⬜ pending |
| 6-03-01 | 03 | 1 | MSG-02, MSG-03 | unit | `./vendor/bin/phpunit tests/Unit/Messenger/TenantWorkerMiddlewareTest.php` | ❌ W0 | ⬜ pending |
| 6-04-01 | 04 | 2 | MSG-01, MSG-02, MSG-03 | integration | `./vendor/bin/phpunit tests/Integration/Messenger/` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Unit/Messenger/TenantStampTest.php` — unit stubs for MSG-01 (stamp carries slug)
- [ ] `tests/Unit/Messenger/TenantSendingMiddlewareTest.php` — unit stubs for MSG-01 (auto-attach on dispatch)
- [ ] `tests/Unit/Messenger/TenantWorkerMiddlewareTest.php` — unit stubs for MSG-02, MSG-03 (boot from stamp, try/finally teardown)
- [ ] `tests/Integration/Messenger/MessengerTestKernel.php` — integration kernel with messenger bus configured

*PHPUnit and all Symfony test infra already installed from prior phases.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Worker process isolation — two workers concurrently process different tenant messages | MSG-03 | Requires real worker processes (bin/console messenger:consume) | Run two workers simultaneously, verify no cross-tenant context bleed in logs |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 15s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
