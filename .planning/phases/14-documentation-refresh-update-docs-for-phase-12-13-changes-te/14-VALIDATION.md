---
phase: 14
slug: documentation-refresh-update-docs-for-phase-12-13-changes-te
status: draft
nyquist_compliant: true
wave_0_complete: true
created: 2026-04-14
---

# Phase 14 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11 |
| **Config file** | phpunit.xml.dist |
| **Quick run command** | `vendor/bin/phpunit --testsuite unit` |
| **Full suite command** | `vendor/bin/phpunit` |
| **Estimated runtime** | ~10 seconds |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit --testsuite unit`
- **After every plan wave:** Run `vendor/bin/phpunit`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 10 seconds

---

## Per-Task Verification Map

| Task ID  | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command                                                                                                   | File Exists | Status     |
|----------|------|------|-------------|------------|-----------------|-----------|---------------------------------------------------------------------------------------------------------------------|-------------|------------|
| 14-01-01 | 01   | 1    | DOC-REFRESH | —          | N/A             | automated | `! test -d flex && ! grep -q '"symfony"' composer.json`                                                             | ✅          | ⬜ pending |
| 14-01-02 | 01   | 1    | DOC-REFRESH | —          | N/A             | automated | `! grep -qi "flex" docs/user-guide/installation.md && grep -q "tenancy:init" docs/user-guide/installation.md`       | ✅          | ⬜ pending |
| 14-01-03 | 01   | 1    | DOC-REFRESH | —          | N/A             | automated | `! grep -q "Flex recipe" docs/index.md && grep -q "tenancy:init" docs/index.md`                                    | ✅          | ⬜ pending |
| 14-02-01 | 02   | 1    | DOC-REFRESH | —          | N/A             | automated | `grep -c "tenancy:init" docs/user-guide/cli-commands.md \| grep -q "[3-9]"`                                        | ✅          | ⬜ pending |
| 14-02-02 | 02   | 1    | DOC-REFRESH | —          | N/A             | automated | `grep -q "cache_prefix_separator: '.'" docs/user-guide/configuration.md && grep -q "acme.user" docs/user-guide/cache-isolation.md` | ✅ | ⬜ pending |
| 14-02-03 | 02   | 1    | DOC-REFRESH | —          | N/A             | automated | `grep -q "Custom resolvers always pass through" docs/user-guide/resolvers.md && grep -q "BUILT_IN_RESOLVER_MAP" docs/architecture/di-compilation.md && grep -q "resetManager('tenant')" docs/user-guide/database-per-tenant.md` | ✅ | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

*Existing infrastructure covers all phase requirements.*

---

## Manual-Only Verifications

| Behavior      | Requirement | Why Manual         | Test Instructions                                                        |
|---------------|-------------|--------------------|--------------------------------------------------------------------------|
| Doc accuracy  | DOC-REFRESH | Content review     | Review updated docs against source code changes from phases 12-13        |
| MkDocs build  | DOC-REFRESH | Build validation   | Run `mkdocs build --strict` and verify no warnings                       |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency < 10s
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** approved 2026-04-14