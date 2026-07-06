---
phase: 34-ops-docs-carry-forward
plan: "01"
subsystem: docs
tags: [docs, ops, mkdocs, parallel-migrations, maintenance-mode, health-checks]
dependency_graph:
  requires: []
  provides:
    - docs/ops/parallel-migrations.md
    - docs/ops/maintenance-mode.md
    - docs/ops/health-checks.md
    - mkdocs.yml Operations nav group
  affects:
    - docs site nav (Operations group added)
    - docs-lint.sh scan scope (new docs/ops/ pages now included)
tech_stack:
  added: []
  patterns:
    - MkDocs Material nav group insertion (after User Guide, before Examples)
    - MkDocs admonition idioms: !!! tip, !!! warning, !!! note (from shared-db.md analog)
    - YAML/PHP config tab pattern (from shared-db.md ==="YAML"/==="PHP" idiom)
    - Command-reference page structure (H1 + flags table + behavior bullets + runbook + see-also)
    - Feature-reference page structure (value-prop opener + how-it-works + config + runbook)
key_files:
  created:
    - docs/ops/parallel-migrations.md
    - docs/ops/maintenance-mode.md
    - docs/ops/health-checks.md
  modified:
    - mkdocs.yml
decisions:
  - "Operations nav group inserted after User Guide and before Examples for natural reading flow"
  - "parallel-migrations.md mirrors cli-commands.md structure (flags table, behavior bullets, tip admonition)"
  - "maintenance-mode.md mirrors mailer-bootstrapper.md structure (value-prop opener, How it works, section dividers)"
  - "health-checks.md includes livenessProbe periodSeconds:10 and readinessProbe periodSeconds:30 with inline DB-cost rationale"
  - "CDN caching warning added to both maintenance-mode.md and health-checks.md"
  - "No positive docs-lint guards added in this plan (guards for wrong ops terms deferred to plan 34-02 per plan scope)"
metrics:
  duration: "~15 min"
  completed: "2026-07-06"
  tasks: 2
  files_changed: 4
---

# Phase 34 Plan 01: Ops Docs — Three v0.5 Operations Pages Summary

Three `docs/ops/` pages documenting the v0.5 ops features (parallel migrations, per-tenant maintenance mode, tenant health checks) authored with production-ready k8s probe YAML and registered in a new `Operations` nav group in `mkdocs.yml`.

## What Was Built

**Task 1 — `docs/ops/parallel-migrations.md` and `docs/ops/maintenance-mode.md` (commit 605776c)**

`parallel-migrations.md` (187 lines): command-reference page mirroring `cli-commands.md` structure. Covers the `--parallel` flag on the existing `tenancy:migrate` command (not a new command), `--concurrency` (default 4, clamped 1–32 with a notice), `--dry-run`, `--format` (txt|json), `--tenant`; the shared_db guard returning FAILURE before any subprocess spawns; the JSON output shape with `migrationsApplied`/`durationMs`/`error`; a rolling fleet migration runbook.

`maintenance-mode.md` (302 lines): feature-reference page mirroring `mailer-bootstrapper.md` structure. Covers `TenantMaintenanceModeListener` priority 16 (fires after orchestrator at 20), HTTP 503 + `Retry-After` + `Cache-Control: no-store, no-cache, must-revalidate`, content-negotiated HTML/JSON body, all three `tenancy:maintenance:enable|disable|status` commands (idempotent), `allow_ips`/`allow_routes`/`allow_paths` allow-list with YAML+PHP config tabs, CDN caching warning, cache-invalidation timing note (enable/disable delete `tenancy.tenant.<slug>` key immediately), health-prefix cross-dependency note, deploy runbook, and `isInMaintenance()` BC break migration with trait path + manual path + link to UPGRADE.md.

**Task 2 — `docs/ops/health-checks.md` and `mkdocs.yml` edit (commit 1877486)**

`health-checks.md` (318 lines): feature-reference page. Covers how the health checker works (no `boot()` call, `HealthResponseSanitizer` DSN redaction), opt-in route import mechanism (`config/routes/health.php` and `config/routes/health_fleet.php`), endpoint reference table (`/_tenancy/health/live` 200, `/_tenancy/health/ready/{slug}` 200/503/404, fleet 200), k8s `livenessProbe` YAML (`periodSeconds: 10 / failureThreshold: 3`) and `readinessProbe` YAML (`periodSeconds: 30 / failureThreshold: 2`) with inline rationale table, network-ACL-over-app-auth security note, CDN 5xx caching warning, `tenancy:health` CLI command, fleet dashboard (paginated, NOT a probe target), LiipMonitorBundle optional integration, red-readiness runbook.

`mkdocs.yml`: Operations nav group inserted between User Guide and Examples, registering all three `ops/*.md` pages.

## Verification Results

All automated gate checks pass:

- `test -f docs/ops/parallel-migrations.md docs/ops/maintenance-mode.md docs/ops/health-checks.md` — all three pages exist
- `grep -q -- '--concurrency' docs/ops/parallel-migrations.md` — PASS
- `grep -q 'shared_db' docs/ops/parallel-migrations.md` — PASS
- `grep -q 'Retry-After' docs/ops/maintenance-mode.md` — PASS
- `grep -q 'tenancy:maintenance:enable' docs/ops/maintenance-mode.md` — PASS (and disable, status)
- `grep -q 'allow_paths' docs/ops/maintenance-mode.md` — PASS
- `grep -q 'Cache-Control' docs/ops/maintenance-mode.md` — PASS
- `grep -q 'readinessProbe:' docs/ops/health-checks.md` — PASS
- `grep -q 'periodSeconds:' docs/ops/health-checks.md` — PASS (distinct: 10 liveness, 30 readiness)
- `grep -q 'failureThreshold:' docs/ops/health-checks.md` — PASS
- `grep -q '/_tenancy/health/live' docs/ops/health-checks.md` — PASS
- `grep -qi 'CDN' docs/ops/health-checks.md` — PASS
- `grep -q 'Operations:' mkdocs.yml` — PASS (inserted after User Guide, before Examples)
- `bash scripts/docs-lint.sh` — exits 0 (OK)
- Line counts: parallel-migrations 187 (min 130 ✓), maintenance-mode 302 (min 170 ✓), health-checks 318 (min 190 ✓)

## Deviations from Plan

None — plan executed exactly as written.

Both tasks mirror the specified analog structures, describe the verified feature surfaces accurately, satisfy all automated grep gates, and the docs-lint.sh gate exits 0.

## Threat Flags

No new threat surface introduced. All example DSNs use placeholders only (e.g., `mysql:host=db-host;dbname=broken_db;user=REDACTED`). No real credentials appear in any example. Health endpoint auth guidance recommends network ACL over app-level auth (T-34-02 mitigated). CDN 5xx-caching warning present in both maintenance-mode.md and health-checks.md (T-34-02 DoS path documented).

## Self-Check: PASSED

| Item | Status |
|------|--------|
| `docs/ops/parallel-migrations.md` exists | FOUND |
| `docs/ops/maintenance-mode.md` exists | FOUND |
| `docs/ops/health-checks.md` exists | FOUND |
| `mkdocs.yml` exists | FOUND |
| commit 605776c exists | FOUND |
| commit 1877486 exists | FOUND |
