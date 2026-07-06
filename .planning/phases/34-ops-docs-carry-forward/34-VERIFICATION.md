---
phase: 34-ops-docs-carry-forward
verified: 2026-07-06T00:00:00Z
status: human_needed
score: 9/9 must-haves verified
overrides_applied: 0
human_verification:
  - test: "Run the demo-smoke CI job (or local docker smoke) after the phase commit lands"
    expected: "The demo-smoke GitHub Actions job completes green on PHP 8.2 — the 'Install demo deps (composer)' step succeeds against the regenerated lock, FrankenPHP container boots, and bin/smoke.sh assertions pass and exit 0"
    why_human: "smoke.sh requires Docker + a live FrankenPHP container on port 80. The local automated gate (composer validate + install --dry-run) passed, but the full container smoke run cannot be executed on the host (PHP 8.5, no Docker required in this context). This checkpoint was explicitly designed as a human/CI gate in Plan 34-03 Task 2. Orchestrator notes that the smoke test WAS run and passed during phase execution — this item is provided for traceability."
---

# Phase 34: Ops Docs + Carry-Forward Verification Report

**Phase Goal:** The `docs/ops/` section documents maintenance mode, health checks, and parallel migrations with production-ready Kubernetes YAML and runbook patterns; the `examples/saas` demo runs on a single coherent PHP version; and the two open v0.4 UAT items are closed.
**Verified:** 2026-07-06
**Status:** human_needed (one CI-only smoke gate; all automated checks passed 9/9)
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | A reader can find a dedicated Operations section in the docs site nav listing the three v0.5 ops pages | ✓ VERIFIED | `mkdocs.yml` lines 87-90: `- Operations:` group after User Guide (line 68), before Examples (line 91); registers `ops/parallel-migrations.md`, `ops/maintenance-mode.md`, `ops/health-checks.md` |
| 2 | The parallel-migrations page documents `--parallel`, `--concurrency` (default 4, clamp 1-32 with notice), `--dry-run`, `--format`, `--tenant`, the shared_db guard, and null-exit=failure | ✓ VERIFIED | `docs/ops/parallel-migrations.md` (191 lines): Flags table covers all flags; WR-02 fix confirmed — `<1` fails with `INVALID`, `>32` clamped with notice; shared_db guard shows exact verbatim message "tenancy:migrate is only available…" (line 67); null/non-zero exit=FAILURE at lines 8-9 and 49 |
| 3 | The maintenance-mode page documents the three `tenancy:maintenance:*` commands, 503+Retry-After+Cache-Control: no-store, the three allow-list keys, the health-prefix cross-dependency, and the cache-invalidation timing | ✓ VERIFIED | `docs/ops/maintenance-mode.md` (306 lines): CR-01 fix confirmed — `enabled: true` present with "REQUIRED" comment in both YAML and PHP blocks; Retry-After line 4; Cache-Control: no-store, no-cache, must-revalidate line 5+25; all three commands; three allow-list keys table; `/_tenancy/health` allow_paths cross-dependency; cache-invalidation note |
| 4 | The health-checks page includes Kubernetes liveness+readiness probe YAML with periodSeconds+failureThreshold, the endpoint table, and the CDN 5xx-caching warning | ✓ VERIFIED | `docs/ops/health-checks.md` (349 lines): livenessProbe (periodSeconds: 10, failureThreshold: 3) + readinessProbe (periodSeconds: 30, failureThreshold: 2) at lines 165-181; endpoint table lines 82-84; CDN warning section at lines 201-210; CR-02 fix confirmed — IETF `application/health+json` shape with `checks` key, no phantom `slug`/`durationMs`/`error` keys |
| 5 | docs-lint.sh gains an ops-terms guard that fires (EXIT=1) on wrong/stale forms and still exits 0 against the correct ops pages | ✓ VERIFIED | `scripts/docs-lint.sh` lines 44-54: `OPS_TARGETS=(docs/)` defined; 5 `check()` invocations guarding wrong forms (`tenancy:maintenance:activated`, `tenancy:maintenance:deactivated`, `health/liveness`, `health/readiness`, `cache_control_no_store`); `bash scripts/docs-lint.sh` exits 0 |
| 6 | UPGRADE.md has a `0.4 to 0.5` section documenting the `isInMaintenance()` BC break and `TenantMaintenanceConfigTrait` migration path, positioned above `0.4.0 to 0.4.1` | ✓ VERIFIED | `UPGRADE.md` line 3: `## 0.4 to 0.5`; line 81: `## 0.4.0 to 0.4.1` — awk confirms correct order; section contains `isInMaintenance()`, `TenantMaintenanceConfigTrait`, both migration paths (A: trait, B: manual), `doctrine:migrations:diff`, and no-op note |
| 7 | `examples/saas/composer.json` pins `config.platform.php = "8.2.99"` and the regenerated lock has zero packages requiring `>=8.4`; Dockerfile stays on `dunglas/frankenphp:1-php8.2-bookworm` | ✓ VERIFIED | `composer.json` `config.platform.php` = `"8.2.99"` confirmed; python3 lock audit: `packages requiring >=8.4: NONE` (61 packages checked); Dockerfile `FROM dunglas/frankenphp:1-php8.2-bookworm AS base` unchanged |
| 8 | A regression test proves the `tenancy:shared:resync` confirm-YES branch proceeds to apply (Phase 26 QA-01 closure) | ✓ VERIFIED | `tests/Unit/Command/SharedEntityResyncCommandTest.php` line 158-179: `testLiveRunConfirmYesProceedsToApply()` uses `setInputs(['yes'])` (string token, not boolean) + `['interactive' => true]`; asserts `applyRow()` called `atLeastOnce()`; test passes (1 test, 4 assertions) |
| 9 | A regression test proves the PHPStan extension-installer zero-config auto-load contract (Phase 28 QA-01 closure) | ✓ VERIFIED | `tests/Unit/PHPStan/ExtensionInstallerContractTest.php`: 3 tests — `testComposerJsonDeclaresExtensionNeonInPhpstanIncludes`, `testExtensionNeonExistsAtDeclaredPath`, `testExtensionNeonDeclaresThreeRuleClasses`; all pass (3 tests, 5 assertions); asserts all three rule classes (MutualExclusionRule, TenantIdDriftRule, SharedEntityLeakRule) |

**Score:** 9/9 truths verified

---

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `docs/ops/parallel-migrations.md` | Parallel migrations command reference + rolling-fleet-migration runbook | ✓ VERIFIED | 191 lines (meets >=130); contains `--concurrency`, `--parallel`, `shared_db`, `## Runbook` heading |
| `docs/ops/maintenance-mode.md` | Maintenance mode feature reference + deploy-enable/operator-bypass runbook | ✓ VERIFIED | 306 lines (meets >=170); contains `Retry-After`, `Cache-Control: no-store`, all three commands, `allow_paths`, `## Runbook` heading, `/_tenancy/health` cross-ref, UPGRADE.md link |
| `docs/ops/health-checks.md` | Health checks feature reference + k8s probe YAML + CDN warning | ✓ VERIFIED | 349 lines (meets >=190); contains `readinessProbe`, `livenessProbe`, `periodSeconds`, `failureThreshold`, `/_tenancy/health/live`, CDN warning |
| `mkdocs.yml` | Operations nav group registering the three ops pages | ✓ VERIFIED | Lines 87-90: `Operations:` group with all three `ops/*.md` entries; positioned after User Guide (line 68), before Examples (line 91) |
| `scripts/docs-lint.sh` | New ops-terms negative guard using `OPS_TARGETS` | ✓ VERIFIED | `OPS_TARGETS=(docs/)` defined; 5 `check()` invocations guard 5 wrong/stale forms; still exits 0 |
| `UPGRADE.md` | `0.4 to 0.5` BC-break upgrade section | ✓ VERIFIED | `## 0.4 to 0.5` at line 3; above `## 0.4.0 to 0.4.1` at line 81; contains both migration paths |
| `examples/saas/composer.json` | `config.platform.php` pin to `8.2.99` | ✓ VERIFIED | `config.platform.php = "8.2.99"` present |
| `examples/saas/composer.lock` | PHP-8.2-coherent regenerated lock | ✓ VERIFIED | Zero packages require `>=8.4`; 61 packages in lock |
| `docs/contributor-guide/test-infrastructure.md` | Nyquist VALIDATION.md advisory-only policy note | ✓ VERIFIED | Section at line 208: `## Nyquist Validation Artifacts (VALIDATION.md)`; contains `advisory only`, `nyquist_validation`, `discovery workflow`, green-suite-is-the-real-gate statement |
| `.planning/phases/31-parallel-migrations/31-VALIDATION.md` | Retrospective Phase 31 VALIDATION artifact | ✓ VERIFIED | Exists; frontmatter `status: complete`; body records Phase 31 verified 2026-06-26; advisory-only note cross-referencing test-infrastructure.md |
| `tests/Unit/Command/SharedEntityResyncCommandTest.php` | SHARE-02-c confirm-yes regression test | ✓ VERIFIED | `testLiveRunConfirmYesProceedsToApply()` exists; uses `setInputs(['yes'])` + `['interactive' => true]`; asserts `applyRow()` is called |
| `tests/Unit/PHPStan/ExtensionInstallerContractTest.php` | PHPStan extension-installer metadata contract test | ✓ VERIFIED | Exists; extends `TestCase`; `declare(strict_types=1)`; namespace `Tenancy\Bundle\Tests\Unit\PHPStan`; 3 passing tests |

---

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `mkdocs.yml` | `docs/ops/*.md` | nav group entries | ✓ WIRED | All three `ops/` paths in `Operations:` nav group |
| `docs/ops/maintenance-mode.md` | `docs/ops/health-checks.md` | health-prefix allow_paths cross-reference | ✓ WIRED | `/_tenancy/health` appears in allow_paths note at line 81+90; links to `health-checks.md` |
| `scripts/docs-lint.sh` | `docs/ops/` | `check()` invocations scoped to `OPS_TARGETS` | ✓ WIRED | 5 invocations use `"${OPS_TARGETS[@]}"` targeting `docs/` |
| `UPGRADE.md` | `TenantInterface::isInMaintenance()` | BC-break section | ✓ WIRED | Section directly documents the method; both migration paths reference it |
| `examples/saas/composer.json` | `examples/saas/composer.lock` | `config.platform.php` drives Composer resolution | ✓ WIRED | Pin present; lock regenerated under pin; zero >=8.4 packages |
| `tests/Unit/Command/SharedEntityResyncCommandTest.php` | `src/Command/SharedEntityResyncCommand.php` confirm gate | `setInputs(['yes'])` + interactive execute | ✓ WIRED | `setInputs(['yes'])` at line 175; `applyRow` mock expectation proves the gate was crossed |
| `tests/Unit/PHPStan/ExtensionInstallerContractTest.php` | `composer.json extra.phpstan.includes` + `extension.neon` | metadata + file-content assertions | ✓ WIRED | All three tests resolve to the real `composer.json` and `extension.neon` at `__DIR__.'/../../../'` |

---

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| docs-lint.sh exits 0 against all ops pages | `bash scripts/docs-lint.sh` | "docs-lint: OK — no stale v0.1 terms…" | ✓ PASS |
| confirm-YES regression test passes | `vendor/bin/phpunit --filter testLiveRunConfirmYesProceedsToApply tests/Unit/Command/SharedEntityResyncCommandTest.php` | OK (1 test, 4 assertions) | ✓ PASS |
| PHPStan extension-installer contract tests pass | `vendor/bin/phpunit tests/Unit/PHPStan/ExtensionInstallerContractTest.php` | OK (3 tests, 5 assertions) | ✓ PASS |
| composer.lock has zero >=8.4 packages | python3 lock audit script | `packages requiring >=8.4: NONE` | ✓ PASS |

---

### Probe Execution

No `probe-*.sh` scripts declared or discoverable for Phase 34. Behavioral spot-checks above substitute.

---

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| DOC-21 | 34-01, 34-02 | `docs/ops/` section — maintenance, health, parallel-migrations pages, k8s YAML, CDN warning, runbooks, UPGRADE 0.4→0.5, docs-lint guard | ✓ SATISFIED | Three `docs/ops/*.md` exist and pass all must-have checks; `mkdocs.yml` Operations group present; `scripts/docs-lint.sh` OPS_TARGETS guard exits 0; `UPGRADE.md` `## 0.4 to 0.5` section present |
| DEMO-02 | 34-03 | Reconcile `examples/saas` Dockerfile ↔ composer.lock PHP-version drift | ✓ SATISFIED | `config.platform.php = "8.2.99"` in composer.json; lock audit confirms zero >=8.4 packages; Dockerfile unchanged; CI smoke test human-verified (per orchestrator notes) |
| GOV-02 | 34-04 | Nyquist VALIDATION.md enforcement policy explicit for v0.5; Phase 31 backfill | ✓ SATISFIED | Policy section in `docs/contributor-guide/test-infrastructure.md`; `.planning/phases/31-parallel-migrations/31-VALIDATION.md` exists with `status: complete`; v0.5 set (31/32/33) uniform |
| QA-01 | 34-05 | Close two Phase 26/28 `human_needed` UAT items as regression tests | ✓ SATISFIED | `testLiveRunConfirmYesProceedsToApply()` passes (confirm-YES branch exercised); `ExtensionInstallerContractTest` passes (3 tests proving installer metadata contract) |

**Requirements orphaned:** None — all four Phase 34 requirements (DOC-21, DEMO-02, GOV-02, QA-01) are accounted for. The remaining v0.5 requirements (ISOL-07..12, MAINT-01..09, HEALTH-01..07) are mapped to Phases 31-33 and are not Phase 34 scope.

---

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| — | — | No `TBD`, `FIXME`, or `XXX` markers found in any Phase-34-modified file | ✓ Clean | — |

No stub implementations, hardcoded empty returns, or placeholder prose found in any of the 12 modified files. The code-review blockers (CR-01: `enabled: true` missing; CR-02: wrong health response keys) and all 5 warnings were fixed pre-verification and confirmed in current HEAD.

---

### Human Verification Required

#### 1. DEMO-02 — demo-smoke CI job green on PHP 8.2

**Test:** After the phase commit lands on a branch, watch the `demo-smoke` GitHub Actions job. Alternatively, locally: `cd examples/saas && docker compose up -d --wait --build && bash bin/smoke.sh` then `docker compose down -v`.

**Expected:** The `demo-smoke` job completes green — specifically, the "Install demo deps (composer)" step succeeds on the PHP 8.2 runner against the regenerated lock (this is the step that was failing before the platform pin). The FrankenPHP container boots, and `bin/smoke.sh` landlord + per-tenant assertions pass and exit 0.

**Why human:** `bin/smoke.sh` requires Docker and a live FrankenPHP container on port 80. The local automated gate (lock audit: zero >=8.4 packages, `composer validate`, `composer install --dry-run`) is green. The full container smoke cannot be run on the host (PHP 8.5, Docker not in scope here). This is an explicit `checkpoint:human-verify` gate defined in Plan 34-03 Task 2.

**Orchestrator note:** Per verification context, the orchestrator ran this smoke test during phase execution on FrankenPHP PHP 8.2.32 and it passed (exit 0). This human verification item is included for completeness and traceability, not because there is an open question.

---

### Gaps Summary

No gaps found. All 9/9 must-haves are verified in the current codebase. The code review caught 2 blockers (CR-01: maintenance config omits `enabled: true`; CR-02: health response contract drift) and 5 warnings — all fixed and confirmed in HEAD before this verification ran. The one `human_needed` item (DEMO-02 CI smoke) is a structural CI gate that was acknowledged as human-verify from the start.

---

_Verified: 2026-07-06_
_Verifier: Claude (gsd-verifier)_
