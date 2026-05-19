---
phase: 20
slug: mailer-bootstrapper
status: draft
nyquist_compliant: false
wave_0_complete: false
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
| 20-W0-01 | W0 | 0 | BOOT-04 | — | Test scaffolding | infra | n/a (creates skeleton tests) | ❌ W0 | ⬜ pending |
| 20-XX-01 | TBD | 1+ | BOOT-04-a | — | MailerBootstrapper implements TenantBootstrapperInterface | unit | `vendor/bin/phpunit tests/Unit/Mailer/MailerBootstrapperTest.php` | ❌ W0 | ⬜ pending |
| 20-XX-02 | TBD | 1+ | BOOT-04-b | — | X-Transport header stamped by TenantMessageDecorator on MessageEvent(isQueued=false) | unit | `vendor/bin/phpunit tests/Unit/Mailer/TenantMessageDecoratorTest.php` | ❌ W0 | ⬜ pending |
| 20-XX-03 | TBD | 1+ | BOOT-04-c | T-20-cross-tenant | TenantAwareTransportsDecorator routes `tenant_<slug>` to correct transport; no-op if context missing | unit | `vendor/bin/phpunit tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php` | ❌ W0 | ⬜ pending |
| 20-XX-04 | TBD | 1+ | BOOT-04-d | T-20-socket-exhaustion | LruTransportCache evicts LRU and calls `stop()` on evicted SmtpTransport | unit | `vendor/bin/phpunit tests/Unit/Mailer/LruTransportCacheTest.php` | ❌ W0 | ⬜ pending |
| 20-XX-05 | TBD | 1+ | BOOT-04-e | T-20-dsn-leak | SanitizingMailerDecorator redacts password from TransportException message and trace | unit | `vendor/bin/phpunit tests/Unit/Mailer/SanitizingMailerDecoratorTest.php` | ❌ W0 | ⬜ pending |
| 20-XX-06 | TBD | 1+ | BOOT-04-f | — | MailerTransportContractPass rejects missing strategy at compile time | unit | `vendor/bin/phpunit tests/Unit/DependencyInjection/Compiler/MailerTransportContractPassTest.php` | ❌ W0 | ⬜ pending |
| 20-XX-07 | TBD | 2 | BOOT-04-g | T-20-cross-tenant | Async canary: dispatch in tenant A's HTTP context, worker in clean context, assert tenant A's SMTP DSN used | integration | `vendor/bin/phpunit tests/Integration/Mailer/AsyncCanaryTest.php` | ❌ W0 | ⬜ pending |
| 20-XX-08 | TBD | 1+ | BOOT-04-h | T-20-socket-exhaustion | LRU cache cleared on TenantContextCleared event (long-running worker bounded over 100 tenants) | unit/integration | `vendor/bin/phpunit tests/Unit/Mailer/LruTransportCacheTest.php` | ❌ W0 | ⬜ pending |
| 20-XX-09 | TBD | 1+ | BOOT-04-i | — | TenantMailerConfigTrait provides default impls for all 3 methods (getMailerDsn/getMailerFrom/getMailerReplyTo) | unit | `vendor/bin/phpunit tests/Unit/Mailer/TenantMailerConfigTraitTest.php` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

*Note: Task IDs `20-XX-NN` are placeholders — planner will replace with concrete `{plan}-{task}` IDs.*

---

## Wave 0 Requirements

- [ ] `tests/Unit/Mailer/MailerBootstrapperTest.php` — stubs for BOOT-04-a
- [ ] `tests/Unit/Mailer/TenantMessageDecoratorTest.php` — stubs for BOOT-04-b
- [ ] `tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php` — stubs for BOOT-04-c
- [ ] `tests/Unit/Mailer/LruTransportCacheTest.php` — stubs for BOOT-04-d, BOOT-04-h
- [ ] `tests/Unit/Mailer/SanitizingMailerDecoratorTest.php` — stubs for BOOT-04-e
- [ ] `tests/Unit/Mailer/TenantMailerConfigTraitTest.php` — stubs for BOOT-04-i
- [ ] `tests/Unit/DependencyInjection/Compiler/MailerTransportContractPassTest.php` — stubs for BOOT-04-f
- [ ] `tests/Integration/Mailer/AsyncCanaryTest.php` — stub for BOOT-04-g (the canary test — async dispatch + clean-worker assertion)
- [ ] `tests/Integration/Mailer/MailerTestKernel.php` — test kernel with spy transport for integration tests

*PHPUnit 11 already installed; existing `tests/Unit/` and `tests/Integration/` directories cover infrastructure.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Compiler failure messaging UX | BOOT-04-f | Verify error message readability when user enables Mailer bootstrapper without strategy | Temporarily remove strategy config from a test app, run `bin/console cache:clear`, verify error names the missing strategy and lists valid options |
| UPGRADE.md migration path clarity | BOOT-04-i | Documentation review — verify the `TenantMailerConfigTrait` vs. `getMailerDsn(): ?string` migration text is unambiguous | Read `UPGRADE.md`, run through migration steps mentally for both trait-adoption and manual-impl paths |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 45s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
