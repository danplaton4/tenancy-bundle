---
phase: 10
slug: dependency-compatibility-audit
status: validated
nyquist_compliant: true
wave_0_complete: true
created: 2026-04-10
validated: 2026-04-11
---

# Phase 10 — Validation Strategy

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
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 15 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 10-01-01 | 01 | 1 | D-04 | — | N/A | static | `vendor/bin/phpstan analyse` | ✅ | ✅ green |
| 10-01-02 | 01 | 1 | D-06 | — | N/A | grep | `grep -rn 'class_exists\|interface_exists' src/` | ✅ | ✅ green |
| 10-01-03 | 01 | 1 | D-08 | — | N/A | static | `vendor/bin/phpstan analyse` | ✅ | ✅ green |
| 10-02-01 | 02 | 1 | D-07 | — | N/A | unit | `vendor/bin/phpunit --testsuite unit` | ✅ | ✅ green |
| 10-02-02 | 02 | 1 | D-09 | — | N/A | ci | `grep -c 'prefer-lowest' .github/workflows/ci.yml` | ✅ | ✅ green |
| 10-02-03 | 02 | 1 | D-10 | — | N/A | ci | `grep -c 'no-messenger' .github/workflows/ci.yml` | ✅ | ✅ green |
| 10-02-04 | 02 | 1 | D-11 | — | N/A | ci | `grep -c '8.0.*' .github/workflows/ci.yml` | ✅ | ✅ green |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- Existing infrastructure covers all phase requirements. PHPUnit, PHPStan, and php-cs-fixer already configured.
- New CI jobs (prefer-lowest, no-messenger) are created as part of the phase work, not prerequisites.

*Existing infrastructure covers all phase requirements.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Symfony 8 + DoctrineBundle 3.x CI job | D-11 | Requires GitHub Actions runner | Push branch, verify CI passes |
| `--prefer-lowest` constraint resolution | D-09 | Requires full Composer solver | Run `composer update --prefer-lowest` locally or via CI |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency < 15s
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** approved 2026-04-11

## Validation Audit 2026-04-11
| Metric | Count |
|--------|-------|
| Gaps found | 0 |
| Resolved | 0 |
| Escalated | 0 |

All 7 verification tasks pass automated checks. D-09, D-10, D-11 verified structurally (CI job definitions present); runtime validation deferred to Manual-Only (GitHub Actions required).
