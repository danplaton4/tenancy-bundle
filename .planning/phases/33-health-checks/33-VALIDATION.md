---
phase: 33
slug: health-checks
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-07-02
---

# Phase 33 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Derived from `33-RESEARCH.md` §"Validation Architecture". The per-task map below is
> scaffolded by the planner and completed at execution time.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11 (unit + integration suites) |
| **Config file** | `phpunit.xml.dist` |
| **Quick run command** | `vendor/bin/phpunit --testsuite unit` |
| **Full suite command** | `vendor/bin/phpunit` |
| **Estimated runtime** | ~30 seconds (SQLite `:memory:` integration) |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit --testsuite unit`
- **After every plan wave:** Run `vendor/bin/phpunit`
- **Before `/gsd:verify-work`:** Full suite must be green + `vendor/bin/phpstan analyse --memory-limit=512M` clean
- **Max feedback latency:** ~30 seconds

---

## Per-Task Verification Map

> Populated by the planner from RESEARCH.md Validation Architecture; ❌ W0 rows are Wave 0 gaps.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| {N}-01-01 | 01 | 1 | HEALTH-{XX} | T-33-01 / — | {expected secure behavior or "N/A"} | integration | `vendor/bin/phpunit --filter {Test}` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] Integration test proving `DatabaseSwitchBootstrapper::check()` under a manual `TenantContext::setTenant()` leaves `TenantContext::hasTenant() === false` after the probe and the next real request reconnects cleanly (Success Criterion 2 / research probe-safety flag) — two SQLite tenants.
- [ ] Integration test proving a DSN injected into a bootstrapper exception message is redacted by `HealthResponseSanitizer` before it reaches the response body (HEALTH-04, no-DSN-leak).
- [ ] Test lane proving self-contained endpoints + `tenancy:health` work with `liip/monitor-bundle` ABSENT, and the `liip_monitor.check` auto-registration fires when it is PRESENT (HEALTH-07, class_exists guard both directions).

*If none: "Existing infrastructure covers all phase requirements."*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Per-second LB/k8s liveness probe latency | HEALTH-01 | Real-time probe cadence is an operational property, not a unit assertion | Curl `GET /_tenancy/health/live` in a loop; confirm sub-ms 200 `{"status":"ok"}` with no tenant iteration |

*If none: "All phase behaviors have automated verification."*

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
