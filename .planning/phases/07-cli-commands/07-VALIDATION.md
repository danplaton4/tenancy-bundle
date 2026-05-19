---
phase: 7
slug: cli-commands
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-04-01
---

# Phase 7 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `vendor/bin/phpunit tests/Unit/Command/ --no-coverage` |
| **Full suite command** | `vendor/bin/phpunit --no-coverage` |
| **Estimated runtime** | ~10 seconds |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit tests/Unit/Command/ --no-coverage`
- **After every plan wave:** Run `vendor/bin/phpunit --no-coverage`
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** 15 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 7-01-01 | 01 | 1 | CLI-01 | unit | `vendor/bin/phpunit tests/Unit/Provider/ --no-coverage` | ❌ W0 | ⬜ pending |
| 7-01-02 | 01 | 1 | CLI-01 | unit | `vendor/bin/phpunit tests/Unit/Command/TenantMigrateCommandTest.php --no-coverage` | ❌ W0 | ⬜ pending |
| 7-02-01 | 02 | 2 | CLI-02 | unit | `vendor/bin/phpunit tests/Unit/Command/TenantRunCommandTest.php --no-coverage` | ❌ W0 | ⬜ pending |
| 7-03-01 | 03 | 3 | CLI-01 | integration | `vendor/bin/phpunit tests/Integration/Command/ --no-coverage` | ❌ W0 | ⬜ pending |
| 7-03-02 | 03 | 3 | CLI-02 | integration | `vendor/bin/phpunit tests/Integration/Command/ --no-coverage` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Unit/Command/TenantMigrateCommandTest.php` — unit stubs for CLI-01
- [ ] `tests/Unit/Command/TenantRunCommandTest.php` — unit stubs for CLI-02
- [ ] `tests/Integration/Command/TenantMigrateCommandIntegrationTest.php` — integration stubs for CLI-01
- [ ] `tests/Integration/Command/TenantRunCommandIntegrationTest.php` — integration stubs for CLI-02

*Existing PHPUnit infrastructure covers all phase requirements — no new framework installs needed.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| `tenancy:migrate` driver guard (shared_db outputs error + exits 1) | CLI-01 | Requires a running app configured with shared_db driver | Run `bin/console tenancy:migrate` in a shared_db app; verify stderr message and exit code |
| `tenancy:run` subprocess output passthrough | CLI-02 | Requires live subprocess spawning | Run `bin/console tenancy:run acme "about"` and verify child output appears in parent stdout |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 15s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
