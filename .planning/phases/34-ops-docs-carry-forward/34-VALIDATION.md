---
phase: 34
slug: ops-docs-carry-forward
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-07-06
---

# Phase 34 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11 (unit + integration suites) |
| **Config file** | `phpunit.xml.dist` |
| **Quick run command** | `vendor/bin/phpunit --testsuite unit` |
| **Full suite command** | `vendor/bin/phpunit` |
| **Estimated runtime** | ~30–60 seconds |

Non-PHPUnit phase gates (docs/config workstreams — see Per-Task map):
- `scripts/docs-lint.sh` — must exit 0 (docs/-scoped term guard)
- `examples/saas/bin/smoke.sh` — must be green on PHP 8.2 (DEMO-02)
- `vendor/bin/phpstan analyse --memory-limit=512M` — PHPStan L9 clean
- `vendor/bin/php-cs-fixer check --diff` — cs-fixer @Symfony clean

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit --testsuite unit` (or the relevant gate script for docs/config-only tasks)
- **After every plan wave:** Run `vendor/bin/phpunit` + `scripts/docs-lint.sh`
- **Before `/gsd:verify-work`:** Full suite + docs-lint + smoke.sh must be green
- **Max feedback latency:** ~60 seconds

---

## Per-Task Verification Map

> Populated during execution / `/gsd:validate-phase` from the finalized PLAN.md task IDs.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| _pending_ | — | — | DOC-21 / DEMO-02 / GOV-02 / QA-01 | — | — | — | — | — | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

*Existing infrastructure covers all phase requirements — PHPUnit 11 + docs-lint.sh + smoke.sh are already in place; no new framework install needed. QA-01 adds tests to existing/new files under `tests/`.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| `mkdocs build --strict` renders the new Operations nav group | DOC-21 | mkdocs not installable locally (CI-deferred; Phase 30 precedent) | CI runs `mkdocs build --strict`; `docs-lint.sh` is the local green proxy |

*All other phase behaviors have automated verification.*

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
