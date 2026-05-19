---
phase: 13
slug: audit-gap-closure
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-04-13
---

# Phase 13 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.5.55 |
| **Config file** | `phpunit.xml.dist` |
| **Quick run command** | `vendor/bin/phpunit --testsuite unit` |
| **Full suite command** | `vendor/bin/phpunit` |
| **Estimated runtime** | ~5 seconds |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit --testsuite unit`
- **After every plan wave:** Run `vendor/bin/phpunit`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 10 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 13-01-01 | 01 | 1 | OSS-01 | — | N/A | smoke | `composer validate --strict` | N/A (shell) | ⬜ pending |
| 13-01-02 | 01 | 1 | CLI-01 | — | N/A | unit | `vendor/bin/phpunit tests/Unit/Command/TenantMigrateCommandTest.php` | ✅ (needs new method) | ⬜ pending |
| 13-01-03 | 01 | 1 | BOOT-02 | — | N/A | unit | `vendor/bin/phpunit tests/Unit/Cache/TenantAwareCacheAdapterTest.php` | ✅ (needs update + new) | ⬜ pending |
| 13-01-04 | 01 | 1 | RESV-05 | — | Resolver filtering prevents unauthorized resolver activation | unit+integration | `vendor/bin/phpunit tests/Unit/DependencyInjection/Compiler/ResolverChainPassTest.php` | ✅ (needs new method) | ⬜ pending |
| 13-01-05 | 01 | 1 | BOOT-01 | — | Tenant EM isolation prevents cross-tenant data leaks | unit+integration | `vendor/bin/phpunit tests/Unit/EventListener/EntityManagerResetListenerTest.php` | ✅ (needs update) | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

*Existing infrastructure covers all phase requirements. New test methods will be added to existing test files.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Flex recipe installs cleanly in fresh project | OSS-01 | Requires fresh Symfony project outside dev env | `composer create-project symfony/skeleton test-app && cd test-app && composer require danplaton4/tenancy-bundle` |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 10s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
