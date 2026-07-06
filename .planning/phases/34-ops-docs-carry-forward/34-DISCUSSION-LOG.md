# Phase 34: Ops Docs & Carry-Forward - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-07-06
**Phase:** 34-ops-docs-carry-forward
**Areas discussed:** Ops docs structure, saas demo PHP version, Nyquist policy, UAT closure method

---

## Area selection

All four workstreams (DOC-21, DEMO-02, GOV-02, QA-01) were selected for discussion.

---

## Ops docs structure (DOC-21)

Nav placement was treated as settled by REQUIREMENTS.md ("new `docs/ops/` section") → a new
top-level `Operations` group in `mkdocs.yml`. The open decision was runbook depth.

| Option | Description | Selected |
|--------|-------------|----------|
| Reference + focused runbooks | Feature reference + config + (health) k8s YAML + CDN warning, PLUS 1–2 concrete runbooks per page (fleet migration during deploy, maintenance mid-deploy, triaging a red readiness probe). | ✓ |
| Reference only | Feature + config + k8s YAML + CDN warning; operations kept to a short callout. | |
| Comprehensive cookbook | Exhaustive scenario coverage / failure-mode matrices per page. | |

**User's choice:** Reference + focused runbooks
**Notes:** Depth mirrors existing user-guide pages (Phase 29 baseline). k8s probe YAML + CDN
5xx-caching warning on the health page are non-optional Success-Criterion-1 items.

---

## saas demo PHP version (DEMO-02)

Drift: `Dockerfile` = `frankenphp:1-php8.2` vs a `composer.lock` dependency requiring `php: ^8.4`;
no `config.platform.php` pin in the demo `composer.json`.

| Option | Description | Selected |
|--------|-------------|----------|
| Pin platform to 8.2 + regen lock | Add `config.platform.php`=8.2.x, regenerate lock so nothing needs ^8.4; Dockerfile unchanged. Proves the bundle at its 8.2 floor. | ✓ |
| Bump Dockerfile to php8.4 | Change base to `frankenphp:1-php8.4`, keep lock as-is. | |
| Standardize on 8.3 | Dockerfile php8.3 + platform pin 8.3.x + regen lock (matrix midpoint). | |

**User's choice:** Pin platform to 8.2 + regenerate lock
**Notes:** Minimal-churn coherent fix that keeps the demo on the supported floor. Planner should
confirm the exact ^8.4 culprit package resolves down under the pin (expected to be a dev dep).

---

## Nyquist policy (GOV-02)

Context: `nyquist_validation: true` is set in `.planning/config.json`, but Phase 31 shipped with no
VALIDATION.md (32 and 33 have one) — de-facto discovery-only.

| Option | Description | Selected |
|--------|-------------|----------|
| Document discovery-only | VALIDATION.md advisory; live green suite is the gate. Write the stance down. | ✓ |
| Enforce as a hard gate | Block phase-complete on validation gaps. | |
| Hybrid: advisory report, non-blocking | Keep a per-phase coverage report but never block. | |

**User's choice:** Document discovery-only stance
**Notes:** Matches v0.4 precedent + current reality; a hard gate is retroactively inconsistent
(Phase 31) and disproportionate for a small-maintainer OSS bundle. Policy to be written in the
contributor guide (Claude's-discretion call, accepted). Phase 31 VALIDATION.md to be backfilled for
artifact consistency (Claude's-discretion call, accepted).

---

## UAT closure method (QA-01)

Both v0.4 `human_needed` items have testable seams in code today.

| Option | Description | Selected |
|--------|-------------|----------|
| Code-level seams for both | Phase 26: test the resync confirm gate (non-interactive stream + `--force` bypass + clean abort). Phase 28: test extension-installer zero-config auto-load. | ✓ |
| Documented manual protocol for both | Manual-exercise checklists; no new tests. | |
| Mixed (per item) | Code seam where cheap, manual protocol otherwise. | |

**User's choice:** Automated code-level seams for both
**Notes:** Converts both "human_needed" items into permanent regression protection; tests must
"prove the gap is closed, not just opened" (Phase 30 D-07 principle).

---

## Confirmation gate

Presented locked decisions + two discretion calls (GOV-02 policy in contributor-guide; backfill
Phase 31 VALIDATION.md). User chose **"Write CONTEXT.md"** — both discretion calls accepted as-is.

## Claude's Discretion

- Ops-nav group position; per-page section ordering/tone; page lengths (mirror user-guide baselines).
- Exact k8s probe `periodSeconds`/`failureThreshold`; which new terms `docs-lint.sh` guards.
- UPGRADE 0.4→0.5 wording; GOV-02 policy note wording and exact file.
- QA-01 Phase 28 test mechanism; whether the Phase 31 VALIDATION.md backfill is a plan or a follow-up.

## Deferred Ideas

- Global/site-wide maintenance mode; migration checkpoint/resume — v0.6+/by-demand (REQUIREMENTS.md).
- `mkdocs build --strict` in CI — still CI-deferred; `docs-lint.sh` remains the local proxy.
- The 5 v0.4 Nyquist discovery flags (phases 24/26/28/29/30) — advisory under the discovery-only
  stance; no code action (distinct from the in-scope Phase 31 backfill).
</content>
