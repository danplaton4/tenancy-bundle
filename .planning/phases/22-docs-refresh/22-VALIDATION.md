---
phase: 22
slug: docs-refresh
status: complete
nyquist_compliant: true
wave_0_complete: true
created: 2026-05-29
---

# Phase 22 — Validation Strategy

> Retroactive validation contract — phase shipped 2026-05-28 with VERIFICATION status `human_needed` (35/36 truths verified in code; 1 gate `mkdocs build --strict` deferred to CI). VALIDATION.md created during v0.3 milestone closure (Phase 23) to fill the Nyquist coverage gap surfaced by `v0.3-MILESTONE-AUDIT.md`.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11 (project suite) + bash `scripts/docs-lint.sh` + `composer validate` + (CI-only) `mkdocs build --strict` |
| **Config file** | `phpunit.xml.dist` + `scripts/docs-lint.sh` + `.github/workflows/docs.yml` |
| **Quick run command** | `bash scripts/docs-lint.sh` (~1s — checks no stale v0.1 terms + no `bundles.php` install-path regressions) |
| **Full suite command** | `vendor/bin/phpunit --no-coverage && bash scripts/docs-lint.sh && composer validate --no-check-publish` |
| **Strict mkdocs gate** | `mkdocs build --strict` — runs in CI via `.github/workflows/docs.yml` L39. Local: `pip install -r docs/requirements.txt && mkdocs build --strict` |
| **Estimated runtime** | ~5 seconds (phpunit cached + docs-lint + composer validate); ~30s with `mkdocs build --strict` |

---

## Sampling Rate

- **After every task commit:** `bash scripts/docs-lint.sh` (sub-second; catches `bundles.php` install-path regressions)
- **After every plan wave:** `vendor/bin/phpunit && bash scripts/docs-lint.sh && composer validate`
- **Before tagging v0.3.3:** Full suite + `mkdocs build --strict` (gated on CI green)
- **Max feedback latency:** 5 seconds (excluding mkdocs which is CI-gated)

---

## Per-Task Verification Map

Phase 22 shipped 6 plans (22-01 through 22-06) covering DOC-19 (Documentation Refresh). Verification is predominantly source-assertion based — docs-lint catches install-path regressions, `composer validate` enforces composer.json contract, mkdocs build catches link breakage.

| Task ID | Plan | Wave | Requirement | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|-----------------|-----------|-------------------|-------------|--------|
| 22-01-01 | 01 | 1 | DOC-19 (SC1) | `composer.json` has `nikic/php-parser` in `require` (not just `suggest`) | structural | `composer validate --no-check-publish` | ✅ exists | ✅ green |
| 22-01-02 | 01 | 1 | DOC-19 (SC1) | `docs/user-guide/installation.md` removes manual `bundles.php` step | structural | `grep -c 'bundles\.php' docs/user-guide/installation.md` returns 0 | ✅ exists | ✅ green |
| 22-01-03 | 01 | 1 | DOC-19 (SC1) | `docs/index.md` Quick Start uses `tenancy:install` | structural | `grep -c 'bin/console tenancy:install' docs/index.md` ≥ 1 | ✅ exists | ✅ green |
| 22-02-01 | 02 | 1 | DOC-19 (SC5) | UPGRADE.md gains `0.3.2 → 0.3.3` section | structural | `grep -c '^## 0.3.2 to 0.3.3' UPGRADE.md` returns 1 | ✅ exists | ✅ green |
| 22-02-02 | 02 | 1 | DOC-19 (SC5) | Stale `(coming in v0.3 docs refresh)` parenthetical removed | structural | `grep -c 'coming in.*docs refresh' UPGRADE.md` returns 0 | ✅ exists | ✅ green |
| 22-03-01 | 03 | 1 | DOC-19 (SC2) | `profiler-tab.md` has 3 ASCII renders for resolved/null/error | structural | `grep -c '┌─' docs/user-guide/profiler-tab.md` ≥ 3 | ✅ exists | ✅ green |
| 22-03-02 | 03 | 1 | DOC-19 (SC2) | `mailer-bootstrapper.md` exists with X-Transport strategy + migration recipe | structural | `test -f docs/user-guide/mailer-bootstrapper.md && grep -c 'X-Transport' docs/user-guide/mailer-bootstrapper.md` ≥ 1 | ✅ exists | ✅ green |
| 22-04-01 | 04 | 1 | DOC-19 (SC3) | `docs/examples/saas-demo.md` exists as thin walkthrough | structural | `test -f docs/examples/saas-demo.md && wc -l < docs/examples/saas-demo.md` ≤ 100 | ✅ exists | ✅ green |
| 22-04-02 | 04 | 1 | DOC-19 (SC3) | Old `docs/user-guide/examples/` removed | structural | `ls docs/user-guide/examples/ 2>&1` returns "No such file or directory" | ✅ exists | ✅ green |
| 22-04-03 | 04 | 1 | DOC-19 (SC3) | 4 cross-references updated to `../examples/` paths | structural | `grep -rE 'user-guide/examples/' docs/` returns no matches | ✅ exists | ✅ green |
| 22-05-01 | 05 | 1 | DOC-19 (SC2) | `getting-started.md` has Origin/Profiler/Mailer teaser subsections | structural | `grep -c '^### .* Origin\|^### .* Profiler\|^### .* Mailer' docs/user-guide/getting-started.md` ≥ 3 | ✅ exists | ✅ green |
| 22-05-02 | 05 | 1 | DOC-19 (SC2) | `resolvers.md` says "five resolvers" + OriginHeaderResolver row at priority 25 | structural | `grep -c 'five resolvers' docs/user-guide/resolvers.md` ≥ 1 + `grep -c 'OriginHeaderResolver.* 25 ' docs/user-guide/resolvers.md` ≥ 1 | ✅ exists | ✅ green |
| 22-05-03 | 05 | 1 | DOC-19 (SC2) | `cli-commands.md` has `## tenancy:install` as headline H2 | structural | `head -20 docs/user-guide/cli-commands.md \| grep -c '^## tenancy:install'` returns 1 | ✅ exists | ✅ green |
| 22-06-01 | 06 | 2 | DOC-19 (SC4) | `docs/roadmap.md` exists with canonical content | structural | `test -f docs/roadmap.md && wc -l < docs/roadmap.md` ≥ 30 | ✅ exists | ✅ green |
| 22-06-02 | 06 | 2 | DOC-19 (SC4) | Repo-root `ROADMAP.md` slimmed to ~5-12 lines with docs-site URL | structural | `wc -l < ROADMAP.md` ≤ 15 + `grep -c 'danplaton4.github.io' ROADMAP.md` ≥ 1 | ✅ exists | ✅ green |
| 22-06-03 | 06 | 2 | DOC-19 (SC4) | README.md badge + section link to docs-site roadmap URL | structural | `grep -c 'danplaton4.github.io/tenancy-bundle/roadmap' README.md` ≥ 2 | ✅ exists | ✅ green |
| 22-06-04 | 06 | 2 | DOC-19 (SC4) | mkdocs.yml nav reorganized with profiler/origin/mailer pages + Examples + Roadmap | structural | `grep -c 'profiler-tab\|origin-header-resolver\|mailer-bootstrapper' mkdocs.yml` ≥ 3 | ✅ exists | ✅ green |
| 22-06-05 | 06 | 2 | DOC-19 (SC6) | `scripts/docs-lint.sh` extended with D-15 awk check + whitelist | structural | `grep -c 'Migration\\\|Upgrade\\\|Manual setup' scripts/docs-lint.sh` ≥ 1 | ✅ exists | ✅ green |
| 22-06-06 | 06 | 2 | DOC-19 (SC6) | `bash scripts/docs-lint.sh` exits 0 against current docs/ | functional | `bash scripts/docs-lint.sh` | ✅ exists | ✅ green |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

Phase 22 had no Wave 0 (no test infrastructure to install — all gates are existing tools).

- [x] Existing infrastructure covers all phase requirements (`composer validate`, `scripts/docs-lint.sh`, PHPUnit, mkdocs-via-CI).

---

## Manual-Only Verifications

Per `22-HUMAN-UAT.md` — these are intrinsically not automatable from within the verifier sandbox:

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| `mkdocs build --strict` against full nav | DOC-19 (SC2/SC4) | mkdocs not installed locally; canonical gate is CI (`.github/workflows/docs.yml`) | Push to master → CI runs `mkdocs build --strict` → green = pass |
| Visual rendering of Profiler ASCII panels in MkDocs Material light+dark themes | DOC-19 (SC2) | Browser monospace font + line-wrap behavior cannot be asserted programmatically | `mkdocs serve` locally OR open published site after CI publish; eye-check box-drawing alignment |
| Post-publish docs-site URL `https://danplaton4.github.io/tenancy-bundle/roadmap/` resolves | DOC-19 (SC4) | URL is a future GitHub Pages target; resolves only after next push triggers docs build | Open the URL in a browser after CI publish |
| Cross-tree links from `docs/` to `../../examples/saas/README.md` render acceptably | DOC-19 (SC3) | MkDocs cross-tree link behavior depends on rendered-site context | Open `docs/examples/saas-demo.md` and `docs/user-guide/profiler-tab.md` on published site, click cross-tree links |

These 4 items are documented in `.planning/phases/22-docs-refresh/22-HUMAN-UAT.md` (4 items pending). None are blocking for v0.3.3 tag — `mkdocs build --strict` will run in CI on the tag push.

---

## Validation Sign-Off

- [x] All tasks have automated source-assertion or functional command (18/18 tasks mapped)
- [x] Sampling continuity: docs-lint covers every commit
- [x] Wave 0 requirements: N/A (no infrastructure setup)
- [x] No watch-mode flags
- [x] Feedback latency < 5s (excluding CI-only mkdocs)
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** approved 2026-05-29 (retroactive — phase already shipped + verified)

---

## Validation Audit 2026-05-29

Retroactive audit during v0.3 milestone closure (Phase 23). VALIDATION.md was missing entirely from this phase prior to this audit — generated from scratch using `.claude/get-shit-done/templates/VALIDATION.md` + `22-VERIFICATION.md` content + `22-CONTEXT.md` plan-task mapping.

| Metric | Count |
|--------|-------|
| Gaps found | 1 (missing VALIDATION.md file) |
| Resolved | 1 (file generated from artifacts) |
| Escalated | 0 |

**Audit basis:** All 18 task IDs in the Per-Task Verification Map map to source-assertion commands that pass at HEAD `4b0d1c6` (post-Phase 23 green-bar). `bash scripts/docs-lint.sh` exits 0. `composer validate --no-check-publish` exits 0. `vendor/bin/phpunit` → 568 tests green.

**Mkdocs --strict outstanding:** Not run locally (mkdocs not installed). Tracked in `22-HUMAN-UAT.md` and gated by `.github/workflows/docs.yml`. Non-blocking for v0.3.3 tag — CI runs on push.

**Approver:** Claude (gsd-orchestrator)
**Confirmed against:** Green-bar matrix (PHPUnit + PHPStan + cs-fixer + docs-lint + composer validate) at HEAD `4b0d1c6`.
