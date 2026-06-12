---
phase: 26
slug: tenancy-shared-resync-command
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-06-12
---

# Phase 26 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Source: `26-RESEARCH.md` § Validation Architecture.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11 (unit + integration suites) |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `vendor/bin/phpunit --testsuite unit` |
| **Full suite command** | `vendor/bin/phpunit` |
| **Estimated runtime** | ~5s unit · ~30s full (SQLite `:memory:` integration kernels) |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit --testsuite unit`
- **After every plan wave:** Run `vendor/bin/phpunit` (full suite incl. integration)
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** ~5 seconds (unit), ~30 seconds (full)

---

## Per-Task Verification Map

> Task IDs are assigned by the planner. Rows below map every SHARE-02 observable
> behavior to its automated proof; the planner MUST attach each behavior to a task
> and an `<automated>` verify command. `-x` = stop on first failure.

| Behavior | Req | Wave | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|----------|-----|------|-----------------|-----------|-------------------|-------------|--------|
| `#[Shared]` classes enumerated via landlord metadata (D-07, reflClass proxy-safe) | SHARE-02-a | 1 | only mapped `#[Shared]` classes touched | unit | `vendor/bin/phpunit tests/Unit/Shared/SharedEntityCopierTest.php -x` | ❌ W0 | ⬜ pending |
| `--dry-run` classifies each row insert/update/in-sync, **no flush** (D-03) | SHARE-02-b | 2 | read-only, no tenant writes | unit | `vendor/bin/phpunit tests/Unit/Command/SharedEntityResyncCommandTest.php -x` | ❌ W0 | ⬜ pending |
| Live run prints drift summary then `confirm()`; default-No aborts cleanly (D-04) | SHARE-02-c | 2 | no write without consent | unit | `vendor/bin/phpunit tests/Unit/Command/SharedEntityResyncCommandTest.php -x` | ❌ W0 | ⬜ pending |
| `--force` skips confirmation, proceeds immediately (D-04) | SHARE-02-d | 2 | explicit unattended intent | unit | `vendor/bin/phpunit tests/Unit/Command/SharedEntityResyncCommandTest.php -x` | ❌ W0 | ⬜ pending |
| `shared_db` driver → informational no-op, exits SUCCESS (D-05) | SHARE-02-e | 2 | no false-failure | unit | `vendor/bin/phpunit tests/Unit/Command/SharedEntityResyncCommandTest.php -x` | ❌ W0 | ⬜ pending |
| Continue-on-failure: one tenant fails, others succeed, exits FAILURE (D-06) | SHARE-02-f | 2 | one tenant's fault doesn't abort loop | unit | `vendor/bin/phpunit tests/Unit/Command/SharedEntityResyncCommandTest.php -x` | ❌ W0 | ⬜ pending |
| `TenantContext::clear()` + `BootstrapperChain::clear()` in `finally` per tenant (D-06) | SHARE-02-g | 2 | no context leak across tenants | unit | `vendor/bin/phpunit tests/Unit/Command/SharedEntityResyncCommandTest.php -x` | ❌ W0 | ⬜ pending |
| Idempotency: re-running after full sync produces no duplicates (D-02 find-or-new) | SHARE-02-h | 2 | re-run = same final state | integration | `vendor/bin/phpunit tests/Integration/SharedEntity/SharedEntityResyncCommandIntegrationTest.php -x` | ❌ W0 | ⬜ pending |
| Write-protection bypass: copier writes to tenant EM without throwing (LANDMINE) | SHARE-02-i | 1 | bypass scoped to sync only | integration | `vendor/bin/phpunit tests/Integration/SharedEntity/SharedEntityResyncCommandIntegrationTest.php -x` | ❌ W0 | ⬜ pending |
| SHARE-01 subscriber still works after copier extraction (regression) | SHARE-02-j | 1 | no Phase 25 regression | integration | `vendor/bin/phpunit tests/Integration/SharedEntity/SharedEntitySyncIntegrationTest.php` | ✅ existing | ⬜ pending |
| `--tenant=<slug>` targets single tenant only (D-01) | SHARE-02-k | 2 | scope honored | unit | `vendor/bin/phpunit tests/Unit/Command/SharedEntityResyncCommandTest.php -x` | ❌ W0 | ⬜ pending |
| Drift classification correctness: in-sync rows not counted as update (D-03) | SHARE-02-l | 2 | accurate diagnostic | integration | `vendor/bin/phpunit tests/Integration/SharedEntity/SharedEntityResyncCommandIntegrationTest.php -x` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Unit/Shared/SharedEntityCopierTest.php` — stubs for SHARE-02-a + classify correctness
- [ ] `tests/Unit/Command/SharedEntityResyncCommandTest.php` — stubs for SHARE-02-b..g, SHARE-02-k (CommandTester, mirroring `TenantMigrateCommandTest`)
- [ ] `tests/Integration/SharedEntity/SharedEntityResyncCommandIntegrationTest.php` — stubs for SHARE-02-h, -i, -l (full SQLite kernel)
- [ ] `tests/Integration/SharedEntity/Support/MakeSharedEntityResyncServicesPublicPass.php` — expose new copier + command services for test container access (mirrors `MakeSharedEntityServicesPublicPass`)

**Kernel reuse:** `SharedEntityFailureLoggingTestKernel` (extends `SharedEntitySyncTestKernel`) already has landlord + two tenant SQLite DBs and `RecordingLogger`. No new kernel needed — only a pass to make the new services public.

*Framework already installed (PHPUnit 11) — no framework install task required.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Interactive `Proceed? [y/N]` prompt rendering in a real TTY | SHARE-02-c | `CommandTester` simulates input but does not exercise a live terminal; prompt copy/UX is visual | Run `bin/console tenancy:shared:resync` in a terminal against a dev fixture, confirm the drift summary + `[y/N]` prompt render and that pressing Enter (default-No) aborts |

*All other phase behaviors have automated verification.*

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references (4 new test files above)
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
