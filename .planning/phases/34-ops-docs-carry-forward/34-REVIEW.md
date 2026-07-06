---
phase: 34-ops-docs-carry-forward
reviewed: 2026-07-06T00:00:00Z
depth: standard
files_reviewed: 10
files_reviewed_list:
  - UPGRADE.md
  - docs/contributor-guide/test-infrastructure.md
  - docs/ops/health-checks.md
  - docs/ops/maintenance-mode.md
  - docs/ops/parallel-migrations.md
  - examples/saas/composer.json
  - mkdocs.yml
  - scripts/docs-lint.sh
  - tests/Unit/Command/SharedEntityResyncCommandTest.php
  - tests/Unit/PHPStan/ExtensionInstallerContractTest.php
findings:
  critical: 2
  warning: 5
  info: 3
  total: 10
status: issues_found
---

# Phase 34: Code Review Report

**Reviewed:** 2026-07-06
**Depth:** standard
**Files Reviewed:** 10
**Status:** issues_found

## Summary

Phase 34 is the v0.5 closure phase (ops docs + carry-forward). Eight of ten files are
documentation; two are new tests plus a demo `composer.json` pin. The tests and the
`docs-lint.sh` D-04 guards are sound (verified against the real command internals and by
running the linter). The demo `composer.json` platform pin (`8.2.99`) is correct and the
file is valid JSON.

The serious defects are all in the **ops documentation**, where several published API
contracts do not match the shipped code. Two rise to BLOCKER level because they would
cause an operator following the docs verbatim to either (a) silently get an inert feature
or (b) parse a live JSON/HTTP contract by keys that do not exist. Because the whole point
of an ops guide is to be copy-paste-followable during an incident, doc-vs-code contract
drift in these files is a correctness defect, not cosmetic.

Adversarial verification performed: I traced every documented command name, config key,
route name, HTTP body shape, and JSON key against the real source
(`TenantHealthController`, `TenantHealthCommand`, `TenantMigrateCommand`,
`ParallelMigrationRunner`, `TenancyBundle::loadExtension`, the config tree in
`TenancyBundle.php`, and the maintenance listener). Findings below cite both the doc line
and the source line that contradicts it.

## Critical Issues

### CR-01: Maintenance-mode config example omits the required `enabled: true`, making the whole feature a silent no-op

**File:** `docs/ops/maintenance-mode.md:44-52` (Configuration section)
**Issue:** The maintenance listener (`tenancy.maintenance.listener`) is registered **only
when `tenancy.maintenance.enabled: true`** — confirmed in
`src/TenancyBundle.php:277` (`if ($maintenanceEnabled) { $services->set('tenancy.maintenance.listener', ...) }`)
and gated again by `MaintenanceModeContractPass::process()`
(`src/DependencyInjection/Compiler/MaintenanceModeContractPass.php:48`). The config tree
declares `enabled` with `defaultFalse()` (`src/TenancyBundle.php:140`).

The documented YAML/PHP config blocks show only `retry_after`, `allow_ips`,
`allow_routes`, `allow_paths` — they never set `enabled: true`. An operator who copies the
documented config gets maintenance mode that **never activates**: the listener is never
wired, so `tenancy:maintenance:enable acme` flips the DB flag but no 503 is ever returned.
For a per-tenant-outage feature this is a data-integrity / availability defect (operator
believes a tenant is walled off when it is still serving traffic).

**Fix:** Add the missing key to the YAML/PHP examples and call it out as required:
```yaml
# config/packages/tenancy.yaml
tenancy:
    maintenance:
        enabled: true          # REQUIRED — the listener is not registered unless this is true
        retry_after: 3600
        allow_ips: []
        allow_routes: []
        allow_paths: []
```
Also document the `template` key (it exists in the tree, `src/TenancyBundle.php:141`, and
is referenced obliquely in prose at line 28 "your custom Twig template if configured" but
never shown in the config reference).

### CR-02: Health-check response contracts (readiness HTTP body + `tenancy:health` JSON) documented with keys that do not exist in the code

**File:** `docs/ops/health-checks.md:96-108` (readiness body) and `docs/ops/health-checks.md:207-217` (CLI JSON)
**Issue:** Multiple published response shapes do not match the shipped serializers:

1. **Readiness HTTP body.** Docs (lines 98, 104) show
   `{"status":"pass","slug":"acme","durationMs":12}` and
   `{"status":"fail","slug":"acme","error":"REDACTED"}`. The real body from
   `TenantHealthController::buildReadyBody()` (`src/Controller/TenantHealthController.php:216-252`)
   is the IETF `application/health+json` shape:
   `{"status":"pass","checks":{"tenancy:db:acme":[{"componentId":...,"componentType":"datastore","status":"pass","time":...}]}}`.
   There is **no `slug` key, no `durationMs` key, and no `error` key** — failures surface as
   a top-level `output` string (line 248), not `error`.

2. **`tenancy:health --format=json` CLI output.** Docs (lines 210-216) show per-tenant
   `{"slug","status","durationMs"}` / `{"slug","status","error"}` and summary
   `{"pass","fail","total"}`. The real emitter
   (`src/Command/TenantHealthCommand.php:209-245`) produces per-tenant
   `{"slug","status","output"?}` (no `durationMs`, no `error` — the key is `output`, line
   224) and summary `{"pass","warn","fail","total"}` (always includes `warn`, line 241).

A CI script or dashboard written against the documented keys silently reads `null` for
`durationMs`/`error` and misses `warn` in the summary. For a monitoring contract this is a
correctness defect (a failing tenant's error text is under `output`, not `error`; alerting
keyed on the documented shape never fires).

**Fix:** Replace the documented readiness body with the actual IETF `checks` shape, and
correct the CLI JSON example to use `output` (not `error`), drop the non-existent
`durationMs`, and add `warn` to the summary:
```json
// GET /_tenancy/health/ready/{slug}
{
  "status": "pass",
  "checks": {
    "tenancy:db:acme": [
      {"componentId": "…", "componentType": "datastore", "status": "pass", "time": "2026-07-06T…Z"}
    ]
  }
}
```
```json
// bin/console tenancy:health --all --format=json
{
  "tenants": [
    {"slug": "acme", "status": "pass"},
    {"slug": "broken-tenant", "status": "fail", "output": "REDACTED"}
  ],
  "summary": {"pass": 1, "warn": 0, "fail": 1, "total": 2}
}
```

## Warnings

### WR-01: parallel-migrations.md claims DSN redaction that the runner does not perform

**File:** `docs/ops/parallel-migrations.md:128-129`
**Issue:** The doc states "DSN credentials appearing in error messages are redacted by
`HealthResponseSanitizer` before the wire." `HealthResponseSanitizer` is used **only** by
`TenantHealthCommand` (`src/Command/TenantHealthCommand.php:15,45`); the migrate path does
not use it. `ParallelMigrationRunner::extractError()`
(`src/Command/Migration/ParallelMigrationRunner.php:231-253`) takes the last non-empty
line of the child process buffer, runs only `strip_tags()` + UTF-8 scrubbing, and returns
it verbatim as the `error` field. No credential redaction happens. The doc's own JSON
example (line 116) even shows a raw `host`/`dbname` with only `user=REDACTED`, contradicting
the "credentials redacted" sentence. This is a false security assurance: an operator who
pipes `--format=json` into logs believing DSNs are scrubbed may leak host/user/password
from a connection-error message.
**Fix:** Remove the `HealthResponseSanitizer` claim. State plainly that the migrate JSON
`error` field is the child process's last output line, is **not** credential-redacted, and
that operators must treat migration JSON as sensitive (or scrub before persisting).

### WR-02: parallel-migrations.md `--concurrency` semantics contradict the command

**File:** `docs/ops/parallel-migrations.md:37`
**Issue:** The flags table says `--concurrency` is "Clamped to the range `[1, 32]`; values
above 32 are silently reduced to 32 with a console notice." Two inaccuracies vs.
`TenantMigrateCommand::execute()`:
- Lower bound is **not clamped**: a value `< 1` (or non-numeric) returns
  `Command::INVALID` with an error message (`src/Command/TenantMigrateCommand.php:109-118`),
  it is not silently raised to 1.
- The reduction to 32 is **not silent** — it emits `<comment>--concurrency clamped to 32…`
  (line 122-129). "Silently reduced … with a console notice" is self-contradictory.
**Fix:** "Values `< 1` (or non-numeric) fail with exit code `INVALID`. Values `> 32` are
reduced to 32 and a console notice is printed."

### WR-03: parallel-migrations.md shared_db guard message does not match the emitted string

**File:** `docs/ops/parallel-migrations.md:66-67`
**Issue:** The doc shows the guard output as
`[ERROR] parallel migration is not supported under the shared_db driver`. The actual
message (`src/Command/TenantMigrateCommand.php:94`) is
`tenancy:migrate is only available with the database_per_tenant driver. Parallel migration
is not supported under the shared_db driver.` Operators grepping logs for the documented
string will not match. Minor, but this is a copy-paste ops guide.
**Fix:** Quote the real message verbatim.

### WR-04: health-checks.md fleet response documents `notes` but the code emits `output`

**File:** `docs/ops/health-checks.md:233-243`
**Issue:** The fleet JSON example shows a non-pass tenant entry as
`{"slug":"demo","status":"warn","notes":"REDACTED"}`. The fleet builder
(`src/Controller/TenantHealthController.php:180-186`) emits the key `output`, never `notes`.
A dashboard parsing `notes` gets nothing.
**Fix:** Change `"notes"` to `"output"` in the fleet example.

### WR-05: examples/saas/composer.json pins a stale bundle version alias (`0.3.x-dev`) for a v0.5 demo

**File:** `examples/saas/composer.json:15`
**Issue:** The path-repo `versions` map declares
`"danplaton4/tenancy-bundle": "0.3.x-dev"`. The current milestone is v0.5 (latest tag
v0.4.1). Because the require is `@dev` against a symlinked `path` repo, Composer still
resolves the local checkout, so the demo installs — but the declared alias is misleading
and would satisfy an unintended constraint if a consumer ever tightened the require to
`^0.3`. For a "runnable demo" shipped as the v0.5 reference, the alias should track the
line being demonstrated.
**Fix:** Update to `"0.5.x-dev"` (or drop the `versions` override entirely and let the
`path` repo infer the version from the symlinked package's `branch-alias`). Note: the
bundle's own `composer.json` `branch-alias` (`dev-master: 0.1.x-dev`) is also stale but is
out of scope for this phase.

## Info

### IN-01: test-infrastructure.md file-count metrics are stale

**File:** `docs/contributor-guide/test-infrastructure.md:22-23`
**Issue:** The doc claims "1.7:1 test-to-source file ratio (68 test files to 40 source
files)" and "`src/` — Bundle source (40 files)". Actual counts: 115 source `.php` files,
233 test `.php` files. The numbers are from an earlier milestone (CLAUDE.md echoes the same
stale "40 files"). Not a correctness bug, but a contributor onboarding doc with wrong
figures.
**Fix:** Recompute or make the phrasing version-agnostic ("roughly 2:1 test-to-source").

### IN-02: health-checks.md `health/liveness` guard covers only one of two plausible wrong forms

**File:** `scripts/docs-lint.sh:52`
**Issue:** The D-04 guard `health/liveness` catches the wrong readiness-style spelling of
the liveness path but there is no symmetric guard for `health/readiness` (the analogous
wrong form of `/ready`). The guard is correct as written (verified it fires on
`health/liveness` and does not false-positive on the real `health/live`), just incomplete.
**Fix (optional):** Add `check 'health/readiness' "Wrong endpoint path segment (use
/_tenancy/health/ready/{slug}, not health/readiness)" "${OPS_TARGETS[@]}"` for symmetry.

### IN-03: docs-lint.sh uses unquoted command substitution in the bundles.php scan

**File:** `scripts/docs-lint.sh:93`
**Issue:** `awk '…' $(find docs/ -name '*.md')` passes the find output unquoted. No current
docs path contains whitespace, so this works today, but a future docs filename with a space
would split into multiple awk arguments and silently skip files. `set -euo pipefail` will
not catch this (word-splitting is not an error). Low risk given the naming convention.
**Fix (optional):** Use `find docs/ -name '*.md' -print0 | xargs -0 awk '…'` or a
`while IFS= read -r -d '' f` loop (as the SHARED_ENTITY block already does at line 114).

---

_Reviewed: 2026-07-06_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
