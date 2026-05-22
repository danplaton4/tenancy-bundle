---
phase: 21
slug: demo-app
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-22
---

# Phase 21 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | bash + curl (smoke level — primary) · bundle's existing PHPUnit 11 suite (regression, unchanged) |
| **Config file** | `examples/saas/bin/smoke.sh` (smoke) · root `phpunit.xml.dist` (regression — unchanged) |
| **Quick run command** | `vendor/bin/phpunit --testsuite unit` (bundle regression — ~5s) |
| **Full suite command** | `vendor/bin/phpunit && cd examples/saas && docker compose up -d --wait && bash bin/smoke.sh && docker compose down -v` |
| **Estimated runtime** | ~30s bundle PHPUnit + ~45–90s demo `docker compose up --wait` + ~5s smoke |

**Key principle (from RESEARCH §Validation Architecture):** Phase 21 does NOT add new PHPUnit tests to the bundle. The smoke script + GitHub workflow ARE the tests. Bundle isolation invariants are covered by the existing Phases 1–20 integration suite.

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit --testsuite unit` (bundle stays green during demo construction)
- **After every plan wave:** Run full suite command (bundle + demo smoke up/down/destroy)
- **Before `/gsd:verify-work`:** Full suite green + manual walkthrough (Phase 19 WDT panel render check + Phase 20 Mailpit UI screenshots)
- **Max feedback latency:** ~120 seconds for the full suite (demo bring-up dominates)

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 21-XX-YY | * | 0 | DEMO-01 | — | smoke harness scaffold exists | unit (file-exists) | `test -x examples/saas/bin/smoke.sh` | ❌ W0 | ⬜ pending |
| 21-XX-YY | * | 1 | DEMO-01.1 | — | `docker compose up -d --wait` exits 0 (all services healthy) | smoke | `cd examples/saas && docker compose up -d --wait` | ❌ W0 | ⬜ pending |
| 21-XX-YY | * | 2 | DEMO-01.2 | T-21-01 | `*.tenancy.localhost` routes via Caddy; per-tenant body marker present | smoke | `curl -fsS -H "Host: acme.tenancy.localhost" http://localhost/ \| grep -q "ACME"` | ❌ W0 | ⬜ pending |
| 21-XX-YY | * | 2 | DEMO-01.2 | T-21-02 | tenant1 markers NEVER appear on tenant2 page | smoke | `bash bin/smoke.sh` (`grep -v` cross-tenant assertions) | ❌ W0 | ⬜ pending |
| 21-XX-YY | * | 2 | DEMO-01.4 | — | smoke script is DNS-independent via `Host:` header | smoke | `bash examples/saas/bin/smoke.sh` (exit 0) | ❌ W0 | ⬜ pending |
| 21-XX-YY | * | 2 | (ext P17) | — | `Origin:` header alone resolves a tenant | smoke | `curl -fsS -H "Origin: https://acme.tenancy.localhost" http://localhost/ \| grep -q "ACME"` | ❌ W0 | ⬜ pending |
| 21-XX-YY | * | 3 | DEMO-01.5 | — | GH Actions workflow runs smoke on push to master; non-zero exit blocks merge | CI gate | `.github/workflows/demo-smoke.yml` validated by `act` or push-to-branch dry-run | ❌ W0 | ⬜ pending |
| 21-XX-YY | * | 3 | DEMO-01.3 | — | README contains three-step fallback ladder section headings | doc | `grep -q "## Three-step fallback" examples/saas/README.md` | ❌ W0 | ⬜ pending |
| 21-XX-YY | * | 3 | DEMO-01.6 | — | bundle source edit visible in demo after `docker compose restart php` | manual-only | (manual walkthrough; documented in README) | ❌ W0 | ⬜ pending |

> Concrete `Task ID`s populate when `gsd-planner` finalises plan/task numbering. The rows above seed the planner with the required minimum coverage.

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `examples/saas/bin/smoke.sh` — covers DEMO-01.2/04 + Phase 17 Origin extension; exit 0 = pass, non-zero = at least one assertion failed
- [ ] `examples/saas/compose.yaml` with healthchecks (php, db, mailpit) — covers DEMO-01.1; `--wait` only returns when all are healthy
- [ ] `.github/workflows/demo-smoke.yml` — covers DEMO-01.5; uses `docker compose up -d --wait` + retry gate + `bash bin/smoke.sh`
- [ ] `examples/saas/Caddyfile` — covers DEMO-01.2 (`*.tenancy.localhost, tenancy.localhost { tls internal; … }`)
- [ ] `examples/saas/README.md` — covers DEMO-01.3 (three-step fallback ladder); must have `## Three-step fallback` section heading for grep verification
- [ ] `examples/saas/composer.json` path repo — covers DEMO-01.6 (`repositories: [{ type: path, url: "../../", symlink: true }]`)
- [ ] `examples/saas/src/Command/SeedDemoCommand.php` — covers fixtures + per-tenant DB provisioning (replaces the non-existent `tenancy:migrate --create-dbs` from CONTEXT.md D-05; see RESEARCH §"Critical Discrepancy with CONTEXT.md")

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Phase 19 Tenancy WDT panel renders with tenant/driver/bootstrappers fields | (ext P19) | Browser-rendered DOM under `dev` mode — no headless harness in this phase | Open `http://acme.tenancy.localhost/` in Chrome dev mode; observe the bottom WDT bar; click Tenancy panel; confirm `Tenant: acme`, driver, bootstrapper list |
| Phase 20 Mailpit shows three distinct From/Reply-To per tenant | (ext P20) | Visual UI inspection — Mailpit's API exists but copy-paste UX is the point | POST `/_demo/send-test-mail` to each of `acme.`, `globex.`, `initech.` subdomains; open `http://localhost:8025`; confirm three messages with distinct `From: hello@<slug>.example` and `Reply-To` |
| Caddy trust UX on macOS Chrome + Safari, Linux Chromium + Firefox, Windows WSL2 + Chrome | DEMO-01.3 | Per-browser/OS trust store behavior — cannot automate from CI | Walk the README's three-step fallback ladder on each target combo; document Firefox manual-import quirk |
| Bundle source change reflects on demo after rebuild | DEMO-01.6 | Requires editing bundle source — semantically a developer-loop assertion | Edit `src/Resolver/HostResolver.php` (e.g., add `error_log('TOUCHED')`), `docker compose restart php`, hit a tenant URL, observe log via `docker compose logs php` |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references (smoke.sh, compose.yaml, Caddyfile, demo-smoke.yml, README, composer.json, SeedDemoCommand)
- [ ] No watch-mode flags
- [ ] Feedback latency < 120s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
