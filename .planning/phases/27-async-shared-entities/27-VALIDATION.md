---
phase: 27
slug: async-shared-entities
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-06-15
---

# Phase 27 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11 (unit + integration suites) |
| **Config file** | `phpunit.xml.dist` |
| **Quick run command** | `vendor/bin/phpunit --testsuite unit` |
| **Full suite command** | `vendor/bin/phpunit` |
| **Estimated runtime** | ~30–60 seconds (SQLite :memory: integration suite) |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit --testsuite unit`
- **After every plan wave:** Run `vendor/bin/phpunit`
- **Before `/gsd:verify-work`:** Full suite must be green + `vendor/bin/phpstan analyse` (level 9) clean
- **Max feedback latency:** 60 seconds

---

## Per-Task Verification Map

> Populated by the planner / gsd-nyquist-auditor from PLAN.md tasks. Critical SHARE-03 behaviors to sample (from RESEARCH.md `## Validation Architecture`):

| Behavior | Requirement | Test Type | Notes |
|----------|-------------|-----------|-------|
| Async dispatch occurs only when `tenancy.shared.async=true`; sync path unchanged when `false` | SHARE-03 | unit / integration | Branch coverage on `SharedEntitySyncSubscriber::postFlush()` |
| `SharedEntityChangedMessage` round-trips through the Messenger bus (canary) | SHARE-03 | integration (`sync://` transport) | Mirror `AsyncCanaryTest`; assert handler reach + per-tenant DB state (SyncTransport does NOT serialize) |
| Handler fans out to ALL tenants and re-fetches latest landlord state at handle time | SHARE-03 | integration (SQLite :memory:) | Per-tenant `switchToTenant()` loop; latest-state upsert (D-05) |
| Vanished landlord row at handle time → propagate tenant delete (convergence) | SHARE-03 (D-04) | integration | insert/update message whose master row is gone collapses to delete |
| Compile-time guard fires on `async: true` + no `symfony/messenger` | SHARE-03 (D-06) | unit (container compile) | `\LogicException` at build time, mirrors `MailerTransportContractPass` |
| Idempotent re-apply on whole-message retry (D-02) | SHARE-03 (D-02) | unit / integration | `SharedEntityCopier` find-or-new makes retry safe |
| Best-effort attempt-all then throw aggregate on any tenant failure | SHARE-03 (D-02) | unit | Plain `\RuntimeException` subclass triggers transport `retry_strategy` |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

*Existing infrastructure covers all phase requirements — PHPUnit 11 + SQLite `:memory:` integration kernel already established (Phases 25/26). New test files (canary + handler + guard) are created within the phase's own plans, not as a Wave 0 prerequisite.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Real broker deferral (HTTP returns immediately when message is routed to a true async transport) | SHARE-03 | Requires a live AMQP/Redis transport + worker process; CI uses `sync://` only | Route `SharedEntityChangedMessage` to an async transport in `framework.messenger.routing`, observe landlord response returns before fan-out completes, run `messenger:consume` to drain |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
