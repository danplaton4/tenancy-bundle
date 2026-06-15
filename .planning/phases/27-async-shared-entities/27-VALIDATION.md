---
phase: 27
slug: async-shared-entities
status: approved
nyquist_compliant: true
wave_0_complete: true
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
| Async dispatch occurs only when `tenancy.shared.async=true`; sync path unchanged when `false` | SHARE-03 | unit / integration | Branch coverage on `SharedEntitySyncSubscriber::postFlush()` (27-02 Task 2) |
| `SharedEntityChangedMessage` round-trips through the Messenger bus (canary) | SHARE-03 | integration (`sync://` transport) | Mirror `AsyncCanaryTest`; assert handler reach + per-tenant DB state (SyncTransport does NOT serialize) (27-03 Task 2) |
| Handler fans out to ALL tenants and re-fetches latest landlord state at handle time | SHARE-03 | integration (SQLite :memory:) | Per-tenant `switchToTenant()` loop; latest-state upsert (D-05); clear-before-find ordering grep-verified (27-02 Task 3) |
| Stamp-clearing: active dispatch-time tenant still fans out to ALL tenants | SHARE-03 (D-01) | integration | `testWrongTenantIsolationWithActiveDispatchTenant` sets active tenant before dispatch (27-03 Task 2) |
| Vanished landlord row at handle time → propagate tenant delete (convergence) | SHARE-03 (D-04) | integration | insert/update message whose master row is gone collapses to delete (27-03 Task 2) |
| Compile-time guard fires on `async: true` + no `symfony/messenger` | SHARE-03 (D-06) | unit (container compile) + structural grep | `\LogicException` at build time, mirrors `MailerTransportContractPass`; throw-on-absent path grep-verified independent of `markTestSkipped` (27-01 Task 3) |
| Idempotent re-apply on whole-message retry (D-02) | SHARE-03 (D-02) | integration | `SharedEntityCopier` find-or-new makes retry safe; re-dispatch yields one row per tenant (27-03 Task 2) |
| Best-effort attempt-all then throw aggregate on any tenant failure | SHARE-03 (D-02) | unit / integration | Plain `\RuntimeException` subclass triggers transport `retry_strategy`; integration induces failure via DROP TABLE on one tenant (27-03 Task 2) |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

*Existing infrastructure covers all phase requirements — PHPUnit 11 + SQLite `:memory:` integration kernel already established (Phases 25/26). No pre-Wave-0 test stubs are required: every new test file is created INSIDE the phase's own implementation tasks (the message/guard unit tests in 27-01 Task 3, the subscriber async test in 27-02 Task 2, and the canary kernel + test in 27-03 Tasks 1–2) alongside the code each one exercises. This is consistent with RESEARCH.md `## Validation Architecture → Wave 0 Gaps`, which lists those same test files as in-phase deliverables (not external prerequisites). Each implementation task therefore carries its own `<automated>` verify, satisfying Nyquist without a separate Wave 0 scaffolding pass.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Real broker deferral (HTTP returns immediately when message is routed to a true async transport) | SHARE-03 | Requires a live AMQP/Redis transport + worker process; CI uses `sync://` only | Route `SharedEntityChangedMessage` to an async transport in `framework.messenger.routing`, observe landlord response returns before fan-out completes, run `messenger:consume` to drain |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references (no MISSING refs — all test files created in-phase alongside their implementation)
- [x] No watch-mode flags
- [x] Feedback latency < 60s
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** approved
</content>
</invoke>
