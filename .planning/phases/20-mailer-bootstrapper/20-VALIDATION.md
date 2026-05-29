---
phase: 20
slug: mailer-bootstrapper
status: complete
nyquist_compliant: true
wave_0_complete: true
created: 2026-05-19
---

# Phase 20 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11 |
| **Config file** | `phpunit.xml.dist` |
| **Quick run command** | `vendor/bin/phpunit --testsuite unit` |
| **Full suite command** | `vendor/bin/phpunit` |
| **Estimated runtime** | ~15 seconds (unit), ~45 seconds (full) |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit --testsuite unit`
- **After every plan wave:** Run `vendor/bin/phpunit`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 45 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 20-W0-01 | W0 | 0 | BOOT-04 | — | Test scaffolding | infra | n/a (creates skeleton tests) | ✅ exists | ✅ green |
| 20-XX-01 | TBD | 1+ | BOOT-04-a | — | MailerBootstrapper implements TenantBootstrapperInterface | unit | `vendor/bin/phpunit tests/Unit/Mailer/MailerBootstrapperTest.php` | ✅ exists | ✅ green |
| 20-XX-02 | TBD | 1+ | BOOT-04-b | — | X-Transport header stamped by TenantMessageDecorator on MessageEvent(isQueued=false) | unit | `vendor/bin/phpunit tests/Unit/Mailer/TenantMessageDecoratorTest.php` | ✅ exists | ✅ green |
| 20-XX-03 | TBD | 1+ | BOOT-04-c | T-20-cross-tenant | TenantAwareTransportsDecorator routes `tenant_<slug>` to correct transport; no-op if context missing | unit | `vendor/bin/phpunit tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php` | ✅ exists | ✅ green |
| 20-XX-04 | TBD | 1+ | BOOT-04-d | T-20-socket-exhaustion | LruTransportCache evicts LRU and calls `stop()` on evicted SmtpTransport | unit | `vendor/bin/phpunit tests/Unit/Mailer/LruTransportCacheTest.php` | ✅ exists | ✅ green |
| 20-XX-05 | TBD | 1+ | BOOT-04-e | T-20-dsn-leak | SanitizingMailerDecorator redacts password from TransportException message and trace | unit | `vendor/bin/phpunit tests/Unit/Mailer/SanitizingMailerDecoratorTest.php` | ✅ exists | ✅ green |
| 20-XX-06 | TBD | 1+ | BOOT-04-f | — | MailerTransportContractPass rejects missing strategy at compile time | unit | `vendor/bin/phpunit tests/Unit/DependencyInjection/Compiler/MailerTransportContractPassTest.php` | ✅ exists | ✅ green |
| 20-XX-07 | TBD | 2 | BOOT-04-g | T-20-cross-tenant | Async canary: dispatch in tenant A's HTTP context, worker in clean context, assert tenant A's SMTP DSN used | integration | `vendor/bin/phpunit tests/Integration/Mailer/AsyncCanaryTest.php` | ✅ exists | ✅ green |
| 20-XX-08 | TBD | 1+ | BOOT-04-h | T-20-socket-exhaustion | LRU cache cleared on TenantContextCleared event (long-running worker bounded over 100 tenants) | unit/integration | `vendor/bin/phpunit tests/Unit/Mailer/LruTransportCacheTest.php` | ✅ exists | ✅ green |
| 20-XX-09 | TBD | 1+ | BOOT-04-i | — | TenantMailerConfigTrait provides default impls for all 3 methods (getMailerDsn/getMailerFrom/getMailerReplyTo) | unit | `vendor/bin/phpunit tests/Unit/Mailer/TenantMailerConfigTraitTest.php` | ✅ exists | ✅ green |

*Status: ✅ green · ✅ green · ❌ red · ⚠️ flaky*

*Note: Task IDs `20-XX-NN` are placeholders — planner will replace with concrete `{plan}-{task}` IDs.*

---

## Wave 0 Requirements

- [x] `tests/Unit/Mailer/MailerBootstrapperTest.php` — stubs for BOOT-04-a
- [x] `tests/Unit/Mailer/TenantMessageDecoratorTest.php` — stubs for BOOT-04-b
- [x] `tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php` — stubs for BOOT-04-c
- [x] `tests/Unit/Mailer/LruTransportCacheTest.php` — stubs for BOOT-04-d, BOOT-04-h
- [x] `tests/Unit/Mailer/SanitizingMailerDecoratorTest.php` — stubs for BOOT-04-e
- [x] `tests/Unit/Mailer/TenantMailerConfigTraitTest.php` — stubs for BOOT-04-i
- [x] `tests/Unit/DependencyInjection/Compiler/MailerTransportContractPassTest.php` — stubs for BOOT-04-f
- [x] `tests/Integration/Mailer/AsyncCanaryTest.php` — stub for BOOT-04-g (the canary test — async dispatch + clean-worker assertion)
- [x] `tests/Integration/Mailer/MailerTestKernel.php` — test kernel with spy transport for integration tests

*PHPUnit 11 already installed; existing `tests/Unit/` and `tests/Integration/` directories cover infrastructure.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Compiler failure messaging UX | BOOT-04-f | Verify error message readability when user enables Mailer bootstrapper without strategy | Temporarily remove strategy config from a test app, run `bin/console cache:clear`, verify error names the missing strategy and lists valid options |
| UPGRADE.md migration path clarity | BOOT-04-i | Documentation review — verify the `TenantMailerConfigTrait` vs. `getMailerDsn(): ?string` migration text is unambiguous | Read `UPGRADE.md`, run through migration steps mentally for both trait-adoption and manual-impl paths |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency < 45s
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** approved 2026-05-29 (retroactive — phase already shipped + verified)

---

## Validation Audit 2026-05-29

Retroactive audit during v0.3 milestone closure (Phase 23).

| Metric | Count |
|--------|-------|
| Gaps found | 0 |
| Resolved | 0 (none required — coverage was complete at execute-phase time) |
| Escalated | 0 |

**Audit basis:** All task IDs in the Per-Task Verification Map map to PHPUnit test methods that exist in the codebase and pass in the green 568-test suite (post-Phase 23 green-bar run, commit `4b0d1c6`). PHPStan level 9 clean. Initial VALIDATION.md status frontmatter (`draft` / `nyquist_compliant: false`) reflected pre-execution planning state, not actual coverage — refreshed here to match shipped reality.

**Approver:** Claude (gsd-orchestrator)
**Confirmed against:** `vendor/bin/phpunit --no-coverage` → `OK (568 tests, 2122 assertions)` at HEAD `4b0d1c6`.
