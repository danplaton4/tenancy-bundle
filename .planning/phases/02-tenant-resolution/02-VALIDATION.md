---
phase: 2
slug: tenant-resolution
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-03-18
---

# Phase 2 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x |
| **Config file** | `phpunit.xml.dist` |
| **Quick run command** | `docker compose run --rm php vendor/bin/phpunit --testsuite=unit` |
| **Full suite command** | `docker compose run --rm php vendor/bin/phpunit` |
| **Estimated runtime** | ~15 seconds |

---

## Sampling Rate

- **After every task commit:** Run `docker compose run --rm php vendor/bin/phpunit --testsuite=unit`
- **After every plan wave:** Run `docker compose run --rm php vendor/bin/phpunit`
- **Before `/gsd:verify-work`:** Full suite must be green
- **Max feedback latency:** 30 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------|-------------------|-------------|--------|
| 2-01-01 | 01 | 1 | RESV-01 | unit | `vendor/bin/phpunit tests/Resolver/` | ❌ W0 | ⬜ pending |
| 2-01-02 | 01 | 1 | RESV-05 | unit | `vendor/bin/phpunit tests/DependencyInjection/` | ❌ W0 | ⬜ pending |
| 2-02-01 | 02 | 1 | RESV-01 | unit | `vendor/bin/phpunit tests/Resolver/HostResolverTest.php` | ❌ W0 | ⬜ pending |
| 2-03-01 | 03 | 1 | RESV-02 | unit | `vendor/bin/phpunit tests/Resolver/HeaderResolverTest.php` | ❌ W0 | ⬜ pending |
| 2-03-02 | 03 | 1 | RESV-03 | unit | `vendor/bin/phpunit tests/Resolver/QueryParamResolverTest.php` | ❌ W0 | ⬜ pending |
| 2-04-01 | 04 | 2 | RESV-04 | unit | `vendor/bin/phpunit tests/Resolver/ConsoleResolverTest.php` | ❌ W0 | ⬜ pending |
| 2-05-01 | 05 | 2 | RESV-01,02,03,04,05 | integration | `vendor/bin/phpunit tests/Integration/` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Resolver/` directory — stub test files for each resolver
- [ ] `tests/Integration/TenantResolutionIntegrationTest.php` — end-to-end resolution tests
- [ ] `tests/DependencyInjection/ResolverChainPassTest.php` — compiler pass tests

*Existing PHPUnit infrastructure from Phase 1 covers framework setup — only test stubs needed.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Subdomain resolution against live DB | RESV-01 | Requires real HTTP request + landlord DB | Boot test kernel with real SQLite DB, send request to `tenant.app.test` |
| ConsoleResolver --tenant option binding | RESV-04 | Requires real Symfony Console Application run | Run `bin/console debug:container --tenant=test` in test kernel |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
