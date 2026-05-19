---
phase: 09
slug: oss-hardening
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-04-09
---

# Phase 09 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x |
| **Config file** | phpunit.xml.dist |
| **Quick run command** | `vendor/bin/phpunit --testsuite unit` |
| **Full suite command** | `vendor/bin/phpunit` |
| **Estimated runtime** | ~15 seconds |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit --testsuite unit`
- **After every plan wave:** Run `vendor/bin/phpunit`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 15 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 09-01-01 | 01 | 1 | OSS-01 | — | N/A | manual | `composer validate --strict` | N/A | ⬜ pending |
| 09-02-01 | 02 | 1 | OSS-03 | — | N/A | manual | `test -f config/packages/tenancy.yaml` | N/A | ⬜ pending |
| 09-03-01 | 03 | 1 | OSS-02 | — | N/A | manual | `grep -q "Quick Start" README.md` | N/A | ⬜ pending |
| 09-04-01 | 04 | 2 | OSS-04 | — | N/A | integration | `vendor/bin/phpunit && vendor/bin/phpstan analyse` | ✅ | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `phpstan.neon` — PHPStan config at level 9
- [ ] `.php-cs-fixer.dist.php` — php-cs-fixer configuration

*Existing test infrastructure covers unit/integration testing. Quality tools need initial setup.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Flex recipe auto-registers bundle | OSS-03 | Requires a fresh Symfony project to test Flex installation | Create temp Symfony project, `composer require`, verify bundles.php and tenancy.yaml |
| Packagist constraint compatibility | OSS-01 | Requires Packagist resolver to validate constraint combinations | `composer create-project symfony/skeleton:6.4 temp && cd temp && composer require danplaton4/tenancy-bundle` |
| CI matrix passes on all combinations | OSS-04 | Requires GitHub Actions runner | Push to GitHub and verify all matrix jobs pass |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 15s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
