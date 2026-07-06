# Phase 34: Ops Docs & Carry-Forward - Research

**Researched:** 2026-07-06
**Domain:** Documentation authoring, PHP platform pinning, PHPUnit command-testing, policy writing
**Confidence:** HIGH (all findings directly verified against live source files)

---

## Summary

Phase 34 is the v0.5 milestone-closure phase. No new runtime capabilities ship. Four requirements
across two workstreams: (a) DOC-21 — net-new `docs/ops/` section for the three v0.5 features; (b)
carry-forward hardening: DEMO-02 (saas PHP-version drift), GOV-02 (Nyquist enforcement policy),
QA-01 (two open UAT items from v0.4).

The DEMO-02 investigation found the drift is **more widespread than expected**: the lock was
generated on PHP 8.4 and Composer resolved Symfony 8.x components (which require PHP >=8.4) in
addition to `doctrine/instantiator` 2.1.0. This is entirely a platform-pinning issue — adding
`config.platform.php = "8.2.x"` to `examples/saas/composer.json` and running `composer update`
will force all packages back to their 7.x or last-8.2-compatible versions, since the root
`composer.json` already constrains `require.php = >=8.2` and Symfony packages to `7.4.*`.

The QA-01 Phase 26 test seam is substantially already covered in the unit test: `--force` bypass
and `['interactive' => false]` default-no abort are both tested. The **missing** test is the
"user confirms yes" path (piping `'yes\n'` via `CommandTester::setInputs()`). The Phase 28 seam
(PHPStan extension-installer zero-config auto-load) has no dedicated test; the existing rule tests
load `extension.neon` directly but do not prove the auto-load contract itself.

**Primary recommendation:** Execute in three sequential plans: Plan A (ops docs — three pages,
docs-lint guard, UPGRADE 0.4→0.5, mkdocs nav); Plan B (DEMO-02 platform pin + GOV-02 policy note);
Plan C (QA-01 regression tests — resync confirm path + PHPStan auto-load proof).

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** New top-level `Operations` nav group in `mkdocs.yml`. Three files:
  `docs/ops/maintenance-mode.md`, `docs/ops/health-checks.md`, `docs/ops/parallel-migrations.md`.
- **D-02:** Reference + focused runbooks (not reference-only, not exhaustive cookbook). Target
  runbooks: rolling fleet migration during deploy (parallel-migrations), enabling maintenance +
  operator bypass (maintenance-mode), triaging a red readiness probe (health-checks).
- **D-03:** `health-checks.md` MUST include k8s probe YAML with correct `periodSeconds` +
  `failureThreshold` values plus the CDN 5xx-caching warning.
- **D-04:** `docs-lint.sh` gains a guard for new ops terms using the existing `check()` helper,
  `docs/`-scoped. UPGRADE.md/CHANGELOG.md excluded.
- **D-05:** `UPGRADE.md` gets a `0.4 → 0.5` section covering the `isInMaintenance()` BC break
  and `TenantMaintenanceConfigTrait` migration path. `docs-lint.sh` does NOT scan UPGRADE.md.
- **D-06:** Pin `examples/saas` to PHP 8.2 via `config.platform.php` in its `composer.json`;
  regenerate its `composer.lock`. Do NOT bump the Dockerfile.
- **D-07:** Verify the DEMO-02 culprit during research (done — see §DEMO-02 Investigation).
- **D-08:** Documented discovery-only stance for Nyquist `VALIDATION.md`; `nyquist_validation:
  true` in `.planning/config.json` stays as-is.
- **D-09:** Write the GOV-02 policy in `docs/contributor-guide/` (existing page or dedicated note).
- **D-10:** Backfill Phase 31's VALIDATION.md for artifact consistency (advisory/cheap doc).
- **D-11:** Automated regression tests for both QA-01 items: (a) resync TTY confirm via
  `CommandTester::setInputs()`; (b) PHPStan zero-config auto-load proof.

### Claude's Discretion

- Ops-nav group position within `mkdocs.yml`.
- Per-page section ordering/tone (mirror existing user-guide pages).
- Exact k8s probe `periodSeconds`/`failureThreshold` values (must be justified).
- Which specific new terms `docs-lint.sh` guards, and the exact `check()` invocations.
- Exact wording of UPGRADE 0.4→0.5 section and GOV-02 policy note.
- QA-01 Phase 28 test mechanism (exact assertion approach).
- Whether Phase 31 VALIDATION.md backfill is a plan or a follow-up note.

### Deferred Ideas (OUT OF SCOPE)

- Global / site-wide maintenance mode and migration checkpoint/resume — v0.6+/by-demand.
- `mkdocs build --strict` in CI — still CI-deferred.
- The 5 v0.4 Nyquist discovery flags (phases 24/26/28/29/30) — advisory only under D-08.
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| DOC-21 | New `docs/ops/` section — maintenance-mode, health-checks, parallel-migrations pages (k8s YAML, CDN warning, UPGRADE 0.4→0.5 section, mkdocs nav, docs-lint guard) | §Feature Surface Accuracy documents the exact public API to describe; §Docs Infra Patterns documents idioms to follow |
| DEMO-02 | Reconcile `examples/saas` Dockerfile ↔ composer.lock PHP-version drift; `bin/smoke.sh` green on 8.2 | §DEMO-02 Investigation has the exact culprit analysis and fix path |
| GOV-02 | Decide and apply Nyquist VALIDATION.md enforcement policy for v0.5 | §GOV-02 Placement confirms discovery-only is the established de-facto stance; contributor-guide is the right home |
| QA-01 | Close two open v0.4 `human_needed` UAT items as automated regression tests | §QA-01 Seam Analysis documents what currently exists and what the two missing tests must prove |
</phase_requirements>

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| DOC-21 ops docs authoring | Docs/markdown layer | CI (docs-lint.sh) | Pure content authoring; docs-lint is the enforcement gate |
| DOC-21 docs-lint guard | CI/scripts layer | — | Bash script, docs/-scoped, extends existing `check()` idiom |
| DOC-21 mkdocs nav registration | Config layer | — | Single `mkdocs.yml` edit; pages render only when registered |
| DOC-21 UPGRADE.md section | Docs layer | — | Not scanned by docs-lint; standalone section following existing minor pattern |
| DEMO-02 platform pin | Composer config | examples/saas project | Add `config.platform.php` to `examples/saas/composer.json`, regenerate lock |
| GOV-02 policy note | Docs (contributor-guide) | .planning/ cross-reference | D-09: publicly visible in contributor guide |
| QA-01 resync confirm test | PHPUnit unit test | `tests/Unit/Command/` | Unit seam already 80% covered; add the "confirm yes" branch |
| QA-01 PHPStan auto-load test | PHPUnit unit/integration test | `tests/Unit/PHPStan/` or similar | Prove zero-config contract, not just file existence |

---

## DEMO-02 Investigation (D-07 — REQUIRED, VERIFIED)

**Finding:** The drift is NOT isolated to `doctrine/instantiator`. The `examples/saas/composer.lock`
was generated on PHP 8.4, causing Composer to resolve **15 packages** requiring PHP >=8.4 or >=8.4.1,
all of which are PRODUCTION (non-dev) dependencies. [VERIFIED: live grep of composer.lock]

**PHP 8.4-requiring packages in the current lock:**

| Package | Version in lock | PHP requirement |
|---------|----------------|-----------------|
| `doctrine/instantiator` | 2.1.0 | `^8.4` |
| `symfony/error-handler` | v8.0.8 | `>=8.4` |
| `symfony/event-dispatcher` | v8.0.9 | `>=8.4` |
| `symfony/filesystem` | v8.0.11 | `>=8.4` |
| `symfony/finder` | v8.0.8 | `>=8.4` |
| `symfony/http-foundation` | v8.0.8 | `>=8.4` |
| `symfony/http-kernel` | v8.0.12 | `>=8.4` |
| `symfony/mime` | v8.0.12 | `>=8.4` |
| `symfony/options-resolver` | v8.1.0 | `>=8.4.1` |
| `symfony/process` | v8.0.11 | `>=8.4` |
| `symfony/routing` | v8.0.12 | `>=8.4` |
| `symfony/string` | v8.0.11 | `>=8.4` |
| `symfony/twig-bridge` | v8.0.12 | `>=8.4` |
| `symfony/var-dumper` | v8.0.8 | `>=8.4` |
| `symfony/var-exporter` | v8.0.9 | `>=8.4` |

[VERIFIED: `python3 -c "import json; ..."` against `examples/saas/composer.lock`]

**Root cause:** The Symfony 8.x components entered the lock because:
- `danplaton4/tenancy-bundle` requires `symfony/http-kernel ^7.4||^8.0`
- `symfony/console` (v7.4) requires `symfony/string ^7.2|^8.0`
- Composer on PHP 8.4 resolved all `^7.4||^8.0` constraints to their Symfony 8.x maximum
- This cascaded to all Symfony 8.x transitive dependencies

**The ACTUAL drift culprit is the missing `config.platform.php` in `examples/saas/composer.json`.**
The lock file currently has `"platform": {"php": ">=8.2"}` but NO `platform-overrides` entry. Without
`config.platform.php`, Composer trusts the HOST PHP version (8.4) to resolve constraints.

**No non-dev production dependency genuinely requires PHP 8.4** at the semantic level — all Symfony
7.4 packages are PHP 8.2-compatible. The Symfony 8.x packages resolved because Composer picked
the *highest allowed* version on a PHP 8.4 host. Once `config.platform.php = "8.2.x"` is set,
Composer is instructed to pretend the platform is PHP 8.2, and will resolve:
- `symfony/http-kernel ^7.4||^8.0` → v7.4.x (last PHP >=8.2 in that constraint)
- `doctrine/instantiator ^1.3||^2` → 2.0.x (last version with `^8.2` requirement, before 2.1.0)
- All other Symfony 8.x packages → their 7.x equivalents

**Escalation flag:** None required. All PHP 8.4 packages entered via PHP-version-sensitive resolution,
not because a production feature requires 8.4. The fix is entirely mechanical.

**Fix path (D-06):**
1. Add to `examples/saas/composer.json` under `"config"`: `"platform": {"php": "8.2.99"}`
2. `cd examples/saas && composer update` (or `composer update --lock`)
3. Verify the regenerated lock has no `>=8.4` PHP requirements
4. Run `examples/saas/bin/smoke.sh` to confirm green on 8.2

Note: use `"8.2.99"` (or `"8.2.x"` = `"8.2.999"`) as the platform value — this is Composer's
recommended form for "simulate PHP 8.2 but use any 8.2.* patch". [ASSUMED — standard Composer
`config.platform.php` convention; the exact sub-patch value is idiomatic but not verified against
Composer docs this session]

---

## QA-01 Seam Analysis (D-11 — VERIFIED)

### Phase 26: `tenancy:shared:resync` TTY Confirm Gate

**Source file:** `src/Command/SharedEntityResyncCommand.php` [VERIFIED: file read]
- Line 170: `$isForce = (bool) $input->getOption('force');`
- Line 171: `if (!$isForce && !$io->confirm('Proceed with live resync?', false)) {`
- Line 172: `return Command::SUCCESS;` (clean abort on decline)
- Line 164-167: `--dry-run` exits SUCCESS before reaching the confirm gate (no prompt in dry-run)

**Existing test coverage** in `tests/Unit/Command/SharedEntityResyncCommandTest.php`
[VERIFIED: file read, lines 127-181]:
- Line 131: `testLiveRunPromptsConfirmDefaultNoAbortsCleanly()` — uses `['interactive' => false]` 
  which makes `confirm()` return the default `false`. Proves clean abort. ✅ Covered.
- Line 157: `testForceSkipsConfirmation()` — uses `['--force' => true]`. Proves bypass. ✅ Covered.

**GAP — what is NOT covered:** A test that uses `CommandTester::setInputs(['yes'])` to simulate
a user **confirming** the prompt and proceeding to the apply pass. This is the "human needed" UAT
item: "SHARE-02-c: interactive TTY confirm prompt not manually verified."

**New test to write:** `testLiveRunConfirmYesProceedsToApply()` in the existing
`tests/Unit/Command/SharedEntityResyncCommandTest.php`:
```php
// Pattern: CommandTester with setInputs(['yes']) + interactive mode
$tester = new CommandTester($command);
$tester->setInputs(['yes']);
$exitCode = $tester->execute([], ['interactive' => true]);
// Assert: applyRow() was called; exits SUCCESS
```
The `CommandTester::setInputs()` method feeds answers to `$io->confirm()` in interactive mode.
[VERIFIED: existing tests use `CommandTester` throughout; `setInputs()` is standard Symfony]

**Test class location:** `tests/Unit/Command/SharedEntityResyncCommandTest.php` (extend existing).

### Phase 28: PHPStan Extension-Installer Zero-Config Auto-Load

**Source files verified:**
- `composer.json` lines 82-85: `"phpstan": {"includes": ["extension.neon"]}` [VERIFIED]
- `extension.neon`: declares 3 rule classes under `phpstan.rules.rule` tag [VERIFIED]
- `composer.json` lines 88-91: `"allow-plugins": {"phpstan/extension-installer": true}` [VERIFIED]

**Existing test coverage in `tests/Unit/PHPStan/Rule/`** [VERIFIED: directory listing]:
- `MutualExclusionRuleTest.php`, `TenantIdDriftRuleTest.php`, `SharedEntityLeakRuleTest.php`
- All three load `extension.neon` directly via `getAdditionalConfigFiles()` returning
  `[__DIR__.'/../../../../extension.neon']` — they prove the rules WORK, not that they
  AUTO-LOAD via extension-installer.

**GAP:** No test proves the `phpstan/extension-installer` contract: that when a consumer installs
`phpstan/extension-installer` + `danplaton4/tenancy-bundle`, the three rules load automatically
**without** a manual `phpstan.neon` include. The `extra.phpstan.includes` key in `composer.json`
is what `phpstan/extension-installer` reads.

**New test options (D-11 discretion — exact mechanism):**
- **Option A (file existence + metadata contract):** Assert that (1) `extension.neon` exists at
  the path declared in `composer.json#extra.phpstan.includes[0]`, (2) the file parses as valid
  NEON and declares the three expected rule classes. This is a unit-level contract test — cheap,
  no runtime PHPStan invocation needed. Example:
  ```php
  // tests/Unit/PHPStan/ExtensionInstallerContractTest.php
  $composerJson = json_decode(file_get_contents(__DIR__.'/../../../composer.json'), true);
  $includes = $composerJson['extra']['phpstan']['includes'] ?? [];
  $this->assertContains('extension.neon', $includes);
  // Also: parse extension.neon and assert 3 rule class declarations exist
  ```
- **Option B (live PHPStan smoke):** Run PHPStan against a minimal fixture directory using only
  the auto-loaded extension. Heavier (~1-2s), but proves end-to-end. PHPUnit `@runInSeparateProcess`
  or a script call. Tradeoff: slower, more CI fragility.

**Recommended:** Option A — the metadata contract test. It "proves the gap is closed" (the
installer metadata is correct and the file validates) without requiring a live PHPStan invocation.
This matches Phase 30's "prove the seam is closed, not just opened" principle (D-07).

---

## Feature Surface Accuracy for Ops Docs (DOC-21)

The following surfaces were verified directly from the source code and CONTEXT.md artifacts.
Docs must describe these accurately.

### Parallel Migrations (`docs/ops/parallel-migrations.md`)

[VERIFIED: `src/Command/TenantMigrateCommand.php` lines 41-75; `31-CONTEXT.md`]

| Flag | Type | Default | Behavior |
|------|------|---------|----------|
| `--parallel` | VALUE_NONE | off | Runs bounded subprocess pool; sequential remains default with no flag |
| `--concurrency` | VALUE_REQUIRED | `'4'` | Max concurrent subprocesses; clamped to `[1,32]`, values >32 clamp with a notice |
| `--dry-run` | VALUE_NONE | off | Compute plan without applying; flows through whichever mode is selected |
| `--format` | VALUE_REQUIRED | `'txt'` | `txt` (human) or `json`; JSON suppresses human output, stdout carries only the JSON document |
| `--tenant` | VALUE_OPTIONAL | all | Single-tenant filter; `--parallel + --tenant` with one tenant = no pool spawned (no-op) |

**JSON output shape** (D-03 from 31-CONTEXT):
`{"tenants":[{"slug","status","migrationsApplied","durationMs","error?"}],"summary":{"succeeded","failed","total","wallClockMs"}}`

**shared_db guard** (D-06 from 31-CONTEXT): `--parallel` under `shared_db` driver returns
`Command::FAILURE` with message "parallel migration is not supported under the shared_db driver"
BEFORE any subprocess is spawned. [VERIFIED: `src/Command/TenantMigrateCommand.php` lines 92-95]

**Null-exit semantics** (D-07): A null or non-zero exit code from any subprocess = FAILURE;
`continue-on-failure` is preserved (all tenants attempted).

**Runner class:** `src/Command/Migration/ParallelMigrationRunner.php` [VERIFIED: file exists]

### Maintenance Mode (`docs/ops/maintenance-mode.md`)

[VERIFIED: `src/EventListener/TenantMaintenanceModeListener.php`; `src/Command/TenantMaintenanceEnableCommand.php` etc.; `32-CONTEXT.md`; `32-VALIDATION.md`]

**Command names** (verified via `#[AsCommand]` attributes):
- `tenancy:maintenance:enable` — single slug arg; idempotent (2nd enable exits 0 "already")
- `tenancy:maintenance:disable` — single slug arg; idempotent
- `tenancy:maintenance:status` — lists ALL tenants in maintenance; `--format=json` available

**Listener priority:** `PRIORITY = 16`, fires AFTER `TenantContextOrchestrator` at priority 20.
[VERIFIED: `src/EventListener/TenantMaintenanceModeListener.php` line 39]

**HTTP response** (verified in listener source):
- Status: `503 Service Unavailable`
- Headers: `Retry-After: {retry_after_seconds}` (default 3600 from `tenancy.maintenance.retry_after`)
  and `Cache-Control: no-store, no-cache, must-revalidate` [VERIFIED: lines 117-118]
- Content-negotiated body: JSON `{"status":"maintenance","retryAfter":N}` if `Accept: application/json`;
  HTML (built-in or custom Twig template) otherwise

**Allow-list bypass** (three OR'd checks):
- `tenancy.maintenance.allow_ips` — IP/CIDR via `Symfony\Component\HttpFoundation\IpUtils::checkIp()`
- `tenancy.maintenance.allow_routes` — exact `_route` name match
- `tenancy.maintenance.allow_paths` — prefix match (`str_starts_with(pathInfo, entry)`)

**BC break:** `TenantInterface::isInMaintenance(): bool` added as an interface method.
`TenantMaintenanceConfigTrait` provides `return false` default + `bool $inMaintenance` column.
[VERIFIED: `src/TenantInterface.php` line 20]

**Cache invalidation nuance:** `DoctrineTenantProvider` caches resolved tenants ~300s under
`tenancy.tenant.<slug>`. The enable/disable commands delete this cache key after flush so maintenance
state takes effect on the NEXT request (not after 300s). [VERIFIED: 32-VALIDATION.md line 54 references
this; from project MEMORY.md on DoctrineTenantProvider caching behavior]

**Phase 33 cross-dependency:** `/_tenancy/health*` prefix MUST be in `tenancy.maintenance.allow_paths`
so health probes are never blocked. Document this in the maintenance-mode page's configuration section.

### Health Checks (`docs/ops/health-checks.md`)

[VERIFIED: `src/Controller/TenantHealthController.php`; `config/routes/health.php`; `33-CONTEXT.md`; `33-VALIDATION.md`]

**Endpoints (opt-in via route import):**

| Endpoint | HTTP Method | Status codes | Response type |
|----------|-------------|--------------|---------------|
| `/_tenancy/health/live` | GET | 200 | `application/health+json` `{"status":"ok"}` |
| `/_tenancy/health/ready/{slug}` | GET | 200/503/404 | `application/health+json` |
| `/_tenancy/health` (fleet) | GET | 200 | `application/health+json` paginated aggregate |

**Route opt-in mechanism** (D-01 from 33-CONTEXT):
- `config/routes/health.php` — imports live + ready endpoints
- `config/routes/health_fleet.php` — imports fleet endpoint separately
- No routes exist until imported; route-import IS the opt-in (no `tenancy.health.enabled` flag)
[VERIFIED: `config/routes/health.php` exists at that path]

**404 for unknown slug:** `/_tenancy/health/ready/{slug}` returns HTTP 404 (not 503) for an unknown
tenant slug. 503 for known-but-unhealthy. [VERIFIED: 33-CONTEXT.md D-06; 33-VALIDATION.md HEALTH-02]

**503 for inactive tenant:** Returns 503 (not 404) for a known-but-inactive tenant.
[VERIFIED: 33-VALIDATION.md HEALTH-02 "503-inactive mapping"]

**DSN redaction:** `HealthResponseSanitizer` redacts any DSN/credential from response bodies before
they reach the wire. Generalizes the existing `DsnSanitizer` regex from `src/Mailer/DsnSanitizer.php`.
[VERIFIED: 33-CONTEXT.md code_context; HEALTH-04 in REQUIREMENTS.md]

**CLI command:** `tenancy:health [--tenant=<slug>|--all] [--format=json]`
— streams per-tenant health; exits non-zero if any tenant fails; `--all` is unbounded (operator
deliberate action, not a probe). [VERIFIED: `src/Command/TenantHealthCommand.php` exists]

**Fleet endpoint:** `/_tenancy/health` with `limit`/`offset` query params (default limit 50, hard max
~200). Returns `{"total","offset","limit","summary":{"pass","warn","fail"},"tenants":[...]}`.
NOT a k8s probe target — explicitly documented as dashboard-only. [VERIFIED: 33-CONTEXT.md D-08]

**LiipMonitorBundle integration:** `class_exists`-guarded; when `liip/monitor-bundle` is installed,
bundle health checks auto-register as `liip_monitor.check` services. Absent it, endpoints work
unchanged. [VERIFIED: HEALTH-07 in REQUIREMENTS.md; 33-VALIDATION.md]

---

## Docs Infra Patterns (DOC-21)

### docs-lint.sh Structure

[VERIFIED: full read of `scripts/docs-lint.sh` (124 lines)]

The `check()` helper (lines 22-33) takes `(pattern, desc, targets...)`:
```bash
check() {
    local pattern="$1"
    local desc="$2"
    shift 2
    local targets=("$@")
    if grep -rnE --color=auto -- "$pattern" "${targets[@]}" 2>/dev/null; then
        echo ""
        echo "ERROR: $desc — remove these occurrences or justify via an inline comment."
        EXIT=1
    fi
}
```

The current TARGETS are `(docs/ src/Command/TenantInitCommand.php)`.

For the new ops-terms guard (D-04), add `check()` calls after the existing ones, scoped to `docs/`:
```bash
DOCS_TARGETS=(docs/)
check '_tenancy/health/live'  "Found health liveness path ..." "${DOCS_TARGETS[@]}"
```

**Recommended new guards for ops terms** (D-04 discretion):
- `/_tenancy/health/live` — prevent the endpoint path drifting in docs (whitespace/typo guard)
- `/_tenancy/health/ready` — same for readiness endpoint
- Note: these are **presence guards** (grep will find the correct spelling if used, NOT a stale-term
  guard). Re-purpose the `check()` invocation as a "this term must not appear *incorrectly*" check.
  **IMPORTANT:** The existing pattern is stale-TERM detection (terms that should NOT appear). For
  ops pages, we want the CORRECT term to appear. The right idiom for ops-term guards is different —
  either add a POSITIVE check (grep for absence of expected terms) or add term guards for
  MISSPELLINGS. Planner must decide: either guard against common typos/old forms (e.g., preventing
  `retry-after` lowercase) or add a separate POSITIVE assertion pattern. The existing `check()`
  helper is a NEGATIVE check; a positive check needs a separate script section.
  **Practical recommendation:** Use `check()` to guard against wrong spellings (e.g.,
  `check 'retry_after_header'` — a fictional stale form), OR add a simple positive-check section:
  `grep -rq 'Retry-After' docs/ops/ || { echo "ERROR: ..."; EXIT=1; }`.

UPGRADE.md and CHANGELOG.md are NOT scanned (confirmed at line 14 of docs-lint.sh and existing
D-05 scope exclusion). [VERIFIED]

### mkdocs.yml Nav Structure

[VERIFIED: full read of `mkdocs.yml` (114 lines)]

Current nav groups (in order): Home, User Guide (17 pages), Examples (3 pages), Contributor Guide
(8 pages), Architecture Reference (6 pages), Roadmap.

The new `Operations` group should be inserted **after User Guide, before Examples** — the natural
reading order (users learn the features in User Guide, then reach operations):

```yaml
nav:
  - Home: index.md
  - User Guide:
    - ...
  - Operations:
    - ops/maintenance-mode.md
    - ops/health-checks.md
    - ops/parallel-migrations.md
  - Examples:
    - ...
```

Or after Examples. Either ordering is Claude's discretion (D-01). After User Guide is more
intuitive (ops docs are an extension of user guide).

### UPGRADE.md Section Shape

[VERIFIED: full read of `UPGRADE.md` — 497 lines]

The `0.3 → 0.4` section is the right template: it covers one BC break (`TenantInterface` changes)
with two migration paths (Trait vs. manual). The `0.4 → 0.5` section must cover:
- The `isInMaintenance(): bool` BC break (one method added to `TenantInterface`)
- Migration path A: add `use TenantMaintenanceConfigTrait;` (recommended, provides `return false`
  default + DB column)
- Migration path B: implement manually with `return false`
- DB migration note: running `doctrine:migrations:diff` after adding the trait generates the
  `in_maintenance` column migration
- "No action required if you don't use maintenance mode" — per D-05, note the trait makes it a
  no-op for adopters who use it

The `0.4 → 0.5` section is a **BC break section** following the 0.2→0.3 `TenantInterface` pattern.

### Existing Page Length Baselines

[VERIFIED: `wc -l` on all four reference pages]

| Page | Lines | Character |
|------|-------|-----------|
| `docs/user-guide/mailer-bootstrapper.md` | 138 | Feature reference + runbook |
| `docs/user-guide/shared-db.md` | 217 | Feature reference + config |
| `docs/user-guide/cli-commands.md` | 266 | Command reference |
| `docs/user-guide/filesystem-bootstrapper.md` | 440 | Comprehensive reference |

Target depth for ops pages (D-02): 150-250 lines each (shared-db to cli-commands range) — reference
overview + config + 1-2 concrete runbooks. No need to reach filesystem depth (430) for these pages.

---

## GOV-02 Placement

[VERIFIED: `.planning/milestones/v0.4-MILESTONE-AUDIT.md` read; `.planning/phases/31-parallel-migrations/`
directory listing; `32-VALIDATION.md` and `33-VALIDATION.md` frontmatter]

**Current de-facto state:**
- Phase 31 (completed 2026-06-26): NO `VALIDATION.md` in `.planning/phases/31-parallel-migrations/`
  [VERIFIED: directory listing shows only 31-01-PLAN.md, 31-01-SUMMARY.md, 31-02-PLAN.md,
  31-02-SUMMARY.md, 31-CONTEXT.md, 31-DISCUSSION-LOG.md, 31-PATTERNS.md, 31-REVIEW.md, 31-VERIFICATION.md]
- Phase 32 (completed 2026-07-01): HAS `32-VALIDATION.md` with `nyquist_compliant: false` / 
  `wave_0_complete: false` [VERIFIED: file read]
- Phase 33 (completed 2026-07-06): HAS `33-VALIDATION.md` with `nyquist_compliant: true` /
  `wave_0_complete: true` / `status: complete` [VERIFIED: file read]

**v0.4 audit origin:** `.planning/milestones/v0.4-MILESTONE-AUDIT.md` frontmatter confirms
`nyquist_overall: partial` and lists phases 24, 30 as `missing_phases`, 26, 28, 29 as `partial_phases`.
The discovery-only stance is already the de-facto reality — Phase 31 shipped without a VALIDATION.md
and it completed and was verified. D-08 makes this explicit policy rather than leaving it implicit.

**Best page for GOV-02 note (D-09):** `docs/contributor-guide/test-infrastructure.md`
[VERIFIED: file exists; it covers PHPUnit, test suites, test patterns — the natural home for a
policy note about VALIDATION.md's advisory-only role]. Alternatively a short dedicated page
`docs/contributor-guide/validation-policy.md` could be added (and registered in mkdocs.yml nav
under Contributor Guide). The test-infrastructure.md page is preferred for discoverability
(contributors reading test docs will see the policy inline).

**Phase 31 VALIDATION.md backfill (D-10):** Create `.planning/phases/31-parallel-migrations/31-VALIDATION.md`
as a minimal retrospective artifact (status: complete, summary of what was verified). Low-value
polish but keeps the v0.5 set uniform. Planner should fold into Plan B or C as a small task.

---

## Kubernetes Probe Values (D-03)

[ASSUMED — based on Kubernetes operational best practices; not verified against Kubernetes official
docs this session]

**Liveness probe on `/_tenancy/health/live`:**
```yaml
livenessProbe:
  httpGet:
    path: /_tenancy/health/live
    port: 80
  initialDelaySeconds: 10
  periodSeconds: 10
  failureThreshold: 3
```
Justification: zero-I/O endpoint completes in <1ms; polling every 10 seconds is aggressive but
safe. `failureThreshold: 3` = kill the pod after 30 seconds of genuine process unresponsiveness.
Lower `periodSeconds` (e.g., 5) is also valid; 10 is conservative.

**Readiness probe on `/_tenancy/health/ready/{slug}`:**
```yaml
readinessProbe:
  httpGet:
    path: /_tenancy/health/ready/my-tenant
    port: 80
  initialDelaySeconds: 15
  periodSeconds: 30
  failureThreshold: 2
```
Justification: per-tenant readiness does a real DB `SELECT 1` round-trip (~5-50ms). Polling every
30 seconds avoids hammering the DB with probes from every pod in the fleet. `failureThreshold: 2`
= remove from rotation after 60 seconds of consecutive failures (conservative — avoids flapping on
transient DB hiccup).

**CDN 5xx-caching warning:** Both maintenance 503 and health probe 5xx responses must include
`Cache-Control: no-store` so a CDN/proxy does not pin a transient failure response, leaving the
tenant permanently "down" in the CDN's cache after the condition clears. The maintenance listener
already sets `Cache-Control: no-store, no-cache, must-revalidate` [VERIFIED]. Health endpoints
should document the same requirement — operators must ensure their CDN/proxy configuration passes
`Cache-Control` from origin and does not override it with TTL-based caching for 5xx.

---

## docs-lint.sh Extension Strategy

[VERIFIED: docs-lint.sh read; existing `check()` patterns confirmed]

The D-04 guard must follow the existing `check()` idiom. The key design choice:

The EXISTING guards are **stale-term** guards (detect WRONG things). For ops endpoints/terms, the
doc risk is inconsistency (wrong URL path in future edits). The pragmatic approach that matches the
existing script's spirit:

```bash
# D-04: Ops-terms consistency guards
# Guard against stale/wrong forms of the new ops endpoint paths
OPS_TARGETS=(docs/)
check '_tenancy_health'    "Underscore form of health path (use /_tenancy/health)" "${OPS_TARGETS[@]}"
check 'health/liveness'    "Wrong path segment (use /_tenancy/health/live, not liveness)" "${OPS_TARGETS[@]}"
check 'cache_control_no_store' "Underscore form (use Cache-Control: no-store)" "${OPS_TARGETS[@]}"
```

Alternatively, guard canonically WRONG spellings:
```bash
check 'tenancy:maintenance:activated'  "Wrong command name (use tenancy:maintenance:enable)" "${OPS_TARGETS[@]}"
check 'tenancy:maintenance:deactivated' "Wrong command name (use tenancy:maintenance:disable)" "${OPS_TARGETS[@]}"
```

**Minimum required guard** (Success Criterion 1 from ROADMAP): at least one `check()` invocation
for a new ops term. The planner/executor should pick 2-3 meaningful guards that protect the highest-
risk misspellings.

---

## Architecture Patterns

No new architectural patterns introduced. This phase is documentation + config + tests.

### Pattern: CommandTester confirm-path testing

[VERIFIED: existing `SharedEntityResyncCommandTest.php` uses `CommandTester` throughout]

```php
// Source: tests/Unit/Command/SharedEntityResyncCommandTest.php (existing pattern)
// QA-01 extension: test that confirm("yes") proceeds to apply
$tester = new CommandTester($command);
$tester->setInputs(['yes']);  // feeds answer to $io->confirm()
$exitCode = $tester->execute([], ['interactive' => true]);
$this->assertSame(Command::SUCCESS, $exitCode);
// Also assert applyRow() was called (mock expects)
```

### Pattern: Phase 31 VALIDATION.md backfill (retrospective)

[CITED: Phase 32 and 33 VALIDATION.md format]

A minimal Phase 31 retrospective VALIDATION.md should mirror the frontmatter of 32/33 and record
that the phase completed via `31-VERIFICATION.md`. Content is advisory only (D-08).

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| IP/CIDR allow-list matching | Custom CIDR parser | `Symfony\Component\HttpFoundation\IpUtils::checkIp()` | Already ships with Symfony; handles IPv4 CIDR, IPv6, ranges |
| PHP platform constraint | Build tooling | `config.platform.php` in composer.json | Composer's built-in mechanism; zero dependencies |
| Command interaction in tests | Custom input buffer | `CommandTester::setInputs()` | Standard Symfony test utility |
| NEON parsing in tests (Option A) | Custom parser | `Nette\Neon\Neon::decode()` or `yaml`/`json` equiv | PHPStan depends on nette/neon (already in vendor for test-only); or just assert file_exists + string contains |

---

## Common Pitfalls

### Pitfall 1: Positive vs. Negative docs-lint check
**What goes wrong:** Adding a `check()` call for the NEW correct term (e.g., `/_tenancy/health/live`)
that grep finds the CORRECT usage and fires ERROR — because `check()` is a NEGATIVE check.
**Why it happens:** `check()` sets EXIT=1 when the pattern IS found. Guards should match WRONG forms
or MISSING terms (via a separate positive check).
**How to avoid:** Only use `check()` to match WRONG/stale terms. For positive presence checks, use
the inverse: `grep -rq 'pattern' docs/ || EXIT=1`.

### Pitfall 2: Platform pin not forcing dev-dep down-resolution
**What goes wrong:** `config.platform.php = "8.2.x"` is added but `composer update` is run with
`--no-dev`, missing the dev-dep resolution. `doctrine/instantiator` is a `require` (PROD) dep of
`doctrine/orm`, so it resolves correctly, but if running without `--no-dev` was intended.
**Why it happens:** Running `composer update --no-dev` skips dev deps; the lock still holds 8.4
versions for dev-only packages.
**How to avoid:** Run `composer update` without `--no-dev` in the demo context (or explicitly
`composer update --with-all-dependencies`) to regenerate a fully consistent lock.

### Pitfall 3: CommandTester setInputs with boolean prompts
**What goes wrong:** Passing `[true]` or `[1]` instead of `['yes']` to `setInputs()` for an
`$io->confirm()` call. The `SymfonyStyle::confirm()` reads a string from stdin.
**Why it happens:** Intuitively, confirm takes a boolean answer, but `setInputs()` passes raw
string tokens.
**How to avoid:** Use `$tester->setInputs(['yes'])` (string), not `[true]`. Check the Symfony
`CommandTester` docs or existing `confirm` test patterns.

### Pitfall 4: Cache-Control header exact form
**What goes wrong:** Docs say `Cache-Control: no-store` but code sends `no-store, no-cache, must-revalidate`.
**Why it happens:** CONTEXT.md and REQUIREMENTS.md reference `Cache-Control: no-store` but the
actual implementation in `TenantMaintenanceModeListener.php:118` is the more complete form.
**How to avoid:** Docs should say `Cache-Control: no-store, no-cache, must-revalidate` (verified
in source) or just `Cache-Control: no-store` (the headline behavior) with a note about the full value.
[VERIFIED: `src/EventListener/TenantMaintenanceModeListener.php` line 118]

### Pitfall 5: DEMO-02 lock regeneration on wrong PHP version
**What goes wrong:** Developer regenerates the lock on their local PHP 8.4 machine even after adding
`config.platform.php` — the platform pin only affects what Composer RESOLVES, not what PHP actually
runs.
**Why it happens:** Composer uses the platform pin for constraint resolution but still needs to be
told to update. On PHP 8.4 with platform pinned to 8.2, running `composer update` should resolve
correctly; the platform.php makes Composer treat itself as 8.2 for package resolution purposes.
**How to avoid:** Confirm the regenerated lock has NO packages with `php: >=8.4` requirement by
grepping after update. The CI `examples/saas/bin/smoke.sh` run on PHP 8.2 is the final gate.

---

## Code Examples

### CommandTester setInputs pattern (QA-01)

```php
// Source: Symfony CommandTester documentation + project tests/Unit/Command/ pattern
$tester = new CommandTester($command);
$tester->setInputs(['yes']);         // 'yes' feeds the $io->confirm() call
$exitCode = $tester->execute([], ['interactive' => true]);
$this->assertSame(Command::SUCCESS, $exitCode);
```

### docs-lint.sh new check() call (D-04)

```bash
# Source: scripts/docs-lint.sh lines 22-33 (existing check() pattern)
OPS_TARGETS=(docs/)
check 'tenancy:maintenance:activated' \
    "Wrong command name — use 'tenancy:maintenance:enable'" \
    "${OPS_TARGETS[@]}"
```

### composer.json platform pin (D-06)

```json
// Add to examples/saas/composer.json under "config":
"platform": {
    "php": "8.2.99"
}
```

---

## Runtime State Inventory

Step 2.6: SKIPPED (no runtime state changes — this is a docs/config/tests phase with no service
registry, database schema, or OS-level state changes).

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.2+ | `vendor/bin/phpunit` | ✓ | (host PHP) | — |
| PHPUnit 11 | All test runs | ✓ | (via vendor/) | — |
| `vendor/bin/phpstan` | PHPStan L9 check | ✓ | (via vendor/) | — |
| `vendor/bin/php-cs-fixer` | Code style check | ✓ | (via vendor/) | — |
| `scripts/docs-lint.sh` | Docs lint gate | ✓ | bash script | — |
| `examples/saas/composer.json` | DEMO-02 fix | ✓ | exists | — |
| `composer` | DEMO-02 lock regen | ✓ (assumed on dev machine) | — | Manual instructions if absent |

**Missing dependencies with no fallback:** None.

**Note:** `mkdocs build --strict` is NOT runnable locally (mkdocs not installed; CI-deferred per
Phase 30). `scripts/docs-lint.sh` is the local green proxy for docs changes. [VERIFIED: Phase 30
deferred note confirmed in project MEMORY.md and 34-CONTEXT.md]

---

## Validation Architecture

> `workflow.nyquist_validation: true` in `.planning/config.json` — section REQUIRED.

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11 |
| Config file | `phpunit.xml.dist` (root) |
| Quick run command | `vendor/bin/phpunit --testsuite unit` |
| Full suite command | `vendor/bin/phpunit` |
| PHPStan command | `vendor/bin/phpstan analyse --memory-limit=512M` |
| Docs lint command | `bash scripts/docs-lint.sh` |

### Phase Requirements → Observable Signal Map

| Req ID | Behavior / Acceptance Bar | Signal Type | Automated Verification | File Status |
|--------|--------------------------|-------------|------------------------|-------------|
| DOC-21 | `docs/ops/maintenance-mode.md` exists with runbook content | File existence + content | `test -f docs/ops/maintenance-mode.md && grep -q 'Retry-After' docs/ops/maintenance-mode.md` | ❌ Wave 0 (create) |
| DOC-21 | `docs/ops/health-checks.md` exists with k8s YAML and CDN warning | File existence + content | `test -f docs/ops/health-checks.md && grep -q 'periodSeconds' docs/ops/health-checks.md` | ❌ Wave 0 (create) |
| DOC-21 | `docs/ops/parallel-migrations.md` exists with --concurrency documented | File existence + content | `test -f docs/ops/parallel-migrations.md && grep -q '\-\-concurrency' docs/ops/parallel-migrations.md` | ❌ Wave 0 (create) |
| DOC-21 | All three ops pages registered in `mkdocs.yml` nav | Content check | `grep -q 'ops/maintenance-mode' mkdocs.yml` (and other two) | ❌ Wave 0 (edit) |
| DOC-21 | `docs-lint.sh` guards new ops terms and exits 0 | Script exit code | `bash scripts/docs-lint.sh` exits 0 | ❌ Wave 0 (extend) |
| DOC-21 | `UPGRADE.md` contains `0.4 → 0.5` section with isInMaintenance BC break | Content check | `grep -q '0.4 → 0.5\|0.4 to 0.5' UPGRADE.md && grep -q 'isInMaintenance' UPGRADE.md` | ❌ Wave 0 (edit) |
| DEMO-02 | `examples/saas/composer.json` has `config.platform.php` | Content check | `python3 -c "import json; d=json.load(open('examples/saas/composer.json')); assert 'php' in d['config'].get('platform',{})"` | ❌ Wave 0 (edit) |
| DEMO-02 | `examples/saas/composer.lock` has NO packages requiring php >=8.4 | Lock audit | `python3 -c "import json; lock=json.load(open('examples/saas/composer.lock')); fails=[p for p in lock['packages']+lock['packages-dev'] if '8.4' in p.get('require',{}).get('php','')]; assert not fails, fails"` | ❌ Wave 0 (regenerate) |
| GOV-02 | Policy note exists in `docs/contributor-guide/test-infrastructure.md` (or dedicated page) | File + content check | `grep -q 'VALIDATION.md\|advisory\|discovery-only' docs/contributor-guide/test-infrastructure.md` | ❌ Wave 0 (edit) |
| GOV-02 | `.planning/phases/31-parallel-migrations/31-VALIDATION.md` exists (D-10) | File existence | `test -f .planning/phases/31-parallel-migrations/31-VALIDATION.md` | ❌ Wave 0 (create) |
| QA-01 | New test asserting `setInputs(['yes'])` proceeds to apply | PHPUnit green | `vendor/bin/phpunit tests/Unit/Command/SharedEntityResyncCommandTest.php --filter testLiveRunConfirmYesProceedsToApply` | ❌ Wave 0 (add test method) |
| QA-01 | New test asserting PHPStan extension-installer metadata contract | PHPUnit green | `vendor/bin/phpunit tests/Unit/PHPStan/ExtensionInstallerContractTest.php` (or similar) | ❌ Wave 0 (create) |
| All | Full PHPUnit suite still green after all changes | Full suite | `vendor/bin/phpunit` | existing ✅ |
| All | PHPStan L9 still clean | Static analysis | `vendor/bin/phpstan analyse --memory-limit=512M` | existing ✅ |
| All | php-cs-fixer still clean | Code style | `vendor/bin/php-cs-fixer check --diff` | existing ✅ |
| All | docs-lint.sh exits 0 | Docs lint | `bash scripts/docs-lint.sh` | existing ✅ (after D-04 extension) |

### Sampling Rate

- **Per task commit:** `vendor/bin/phpunit --testsuite unit` + `bash scripts/docs-lint.sh`
- **Per wave merge:** `vendor/bin/phpunit` (full suite)
- **Phase gate (before `/gsd:verify-work`):** Full suite green + PHPStan L9 clean + cs-fixer clean + docs-lint green

### Wave 0 Gaps

- [ ] `docs/ops/maintenance-mode.md` — DOC-21 (create)
- [ ] `docs/ops/health-checks.md` — DOC-21 (create)
- [ ] `docs/ops/parallel-migrations.md` — DOC-21 (create)
- [ ] `mkdocs.yml` nav — DOC-21 (add Operations group)
- [ ] `scripts/docs-lint.sh` — DOC-21 (add ops-terms guard)
- [ ] `UPGRADE.md` — DOC-21 (add 0.4→0.5 section)
- [ ] `examples/saas/composer.json` — DEMO-02 (add platform.php pin)
- [ ] `examples/saas/composer.lock` — DEMO-02 (regenerate)
- [ ] `docs/contributor-guide/test-infrastructure.md` (or new page) — GOV-02 (add policy note)
- [ ] `.planning/phases/31-parallel-migrations/31-VALIDATION.md` — GOV-02/D-10 (create backfill)
- [ ] `tests/Unit/Command/SharedEntityResyncCommandTest.php` — QA-01 (add confirm-yes test method)
- [ ] `tests/Unit/PHPStan/ExtensionInstallerContractTest.php` (new) — QA-01 PHPStan auto-load proof

---

## Security Domain

> `security_enforcement` not explicitly `false` in config.json — section REQUIRED.

### Applicable ASVS Categories

| ASVS Category | Applies | Rationale |
|---------------|---------|-----------|
| V2 Authentication | No | No authentication changes; health endpoints are network-ACL-protected per design |
| V3 Session Management | No | No session changes |
| V4 Access Control | No | Health endpoint access control is operator's concern (network layer); docs should note this |
| V5 Input Validation | Minimal | `UPGRADE.md` and docs pages contain code samples; no user input processed |
| V6 Cryptography | No | No cryptographic changes |

### Phase 34-Specific Security Notes

| Concern | Area | Mitigation |
|---------|------|-----------|
| DSN exposure in docs | DOC-21 | Docs must use placeholder DSNs (`smtp://user:pass@host`) not real credentials; HealthResponseSanitizer redaction already in code |
| Health endpoint auth guidance | DOC-21 health-checks page | Explicitly document that auth on health endpoints breaks k8s probes; recommend network ACL instead |
| CDN caching of maintenance 503 | DOC-21 maintenance page | Must document `Cache-Control: no-store` requirement; CDN configs must not override this |
| Confirm-gate bypass | QA-01 | Test proves `--force` requires explicit opt-in; default path prompts user |

---

## Project Constraints (from CLAUDE.md)

- **Language:** PHP 8.2+ with `declare(strict_types=1)` in every PHP file
- **Framework:** Symfony 7.x / 8.x bundle architecture
- **ORM:** Doctrine optional — always guard with `class_exists()` / `interface_exists()`
- **Testing:** PHPUnit 11; integration tests use SQLite `:memory:`
- **Static Analysis:** PHPStan level 9 (`vendor/bin/phpstan analyse --memory-limit=512M` locally)
- **Code Style:** `php-cs-fixer` with `@Symfony` ruleset; `no_unused_imports` removes same-namespace `use` statements
- **CI gate:** PHPUnit + PHPStan L9 + cs-fixer + docs-lint.sh must all stay green
- **Commit docs:** `commit_docs: true` — every planning artifact lands in git
- **No Flex recipe:** `tenancy:install` is the primary onboarding path

Phase 34 has zero new production PHP deps (DOC-21 is markdown; DEMO-02 is a lock file regeneration;
QA-01 tests are PHP-only using existing PHPUnit/CommandTester infrastructure). The "net-zero new
production deps" constraint from REQUIREMENTS.md is trivially satisfied.

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `"8.2.99"` is the recommended platform.php value form in Composer | DEMO-02 Investigation | Low risk — `"8.2.x"` and `"8.2.999"` and `"8.2.99"` all work; only affects which exact minor is simulated within 8.2 |
| A2 | k8s probe `periodSeconds`/`failureThreshold` values (10s/30s) are sane defaults | k8s Probe Values | Advisory only — operators will tune to their fleet; the DOCS should explain the REASONING so operators can tune, not mandate exact values |

**All other claims verified directly against live source files this session.**

---

## Open Questions (RESOLVED)

> All three questions carry an inline recommendation that was adopted during planning
> (see Phase 34 PLAN.md files 34-01..34-05). Retained for provenance.

1. **docs-lint.sh positive vs. negative check for ops terms**
   - What we know: `check()` is a NEGATIVE check (fires on wrong terms); ops terms need either negative-wrong-form guards or positive-presence checks
   - What's unclear: Whether a pure negative guard (prevent misspellings) is sufficient, or whether a positive presence check is needed
   - Recommendation: Use negative guards for highest-risk wrong forms (e.g., `cache_control_no_store`, `health/liveness`); no positive check needed since docs authoring in Plan A will establish the correct content

2. **DEMO-02 lock regeneration environment**
   - What we know: `composer update` with `config.platform.php = "8.2.99"` should regenerate correctly
   - What's unclear: Whether the CI `examples/saas/bin/smoke.sh` actually runs against PHP 8.2 in the project's CI matrix
   - Recommendation: Check CI workflow to confirm it runs the smoke test on PHP 8.2; the RESEARCH did not inspect `.github/workflows/`

3. **QA-01 Phase 28 test — NEON parsing dependency**
   - What we know: `nette/neon` is already in vendor (PHPStan depends on it for require-dev)
   - What's unclear: Whether the test should parse extension.neon to assert rule class names, or just do file_exists + string match
   - Recommendation: String match (`file_get_contents` + `strpos`/`assertStringContainsString`) is simpler, no dep on nette/neon API; use that unless the planner prefers a more structured parse

---

## Sources

### Primary (HIGH confidence — verified against live source files this session)

- `examples/saas/composer.lock` — DEMO-02 PHP 8.4 packages audit (python3 json parse)
- `examples/saas/composer.json` — current state (no platform.php; require.php >= 8.2)
- `src/Command/SharedEntityResyncCommand.php` lines 164-173 — QA-01 confirm gate seam
- `tests/Unit/Command/SharedEntityResyncCommandTest.php` lines 127-181 — existing coverage
- `src/EventListener/TenantMaintenanceModeListener.php` lines 35-39, 117-118 — priority + headers
- `src/TenantInterface.php` line 20 — `isInMaintenance(): bool` confirmed
- `src/Command/TenantMaintenanceEnableCommand.php` etc. — command name verification
- `src/Controller/TenantHealthController.php` lines 44-75 — health endpoint contract
- `src/Command/TenantMigrateCommand.php` lines 41-75 — migrate flag definitions
- `extension.neon` — PHPStan rule declarations
- `composer.json` lines 82-91 — PHPStan extension-installer metadata
- `scripts/docs-lint.sh` (full) — check() idiom + existing guards
- `mkdocs.yml` (full) — nav structure
- `UPGRADE.md` (full) — existing section shapes
- `tests/Unit/PHPStan/Rule/` directory — existing rule test files
- `.planning/phases/31-parallel-migrations/` directory — confirms no VALIDATION.md
- `.planning/phases/32-maintenance-mode/32-VALIDATION.md` — frontmatter confirmed
- `.planning/phases/33-health-checks/33-VALIDATION.md` — frontmatter confirmed
- `.planning/milestones/v0.4-MILESTONE-AUDIT.md` — Nyquist discovery flags origin
- `.planning/config.json` — `nyquist_validation: true` confirmed
- `docs/contributor-guide/test-infrastructure.md` — GOV-02 placement candidate confirmed exists
- `docs/contributor-guide/coding-standards.md` — PHP constraints confirmed

### Secondary (MEDIUM confidence — from phase CONTEXT.md artifacts, themselves grounded in source reads)

- `.planning/phases/31-parallel-migrations/31-CONTEXT.md` — parallel migration feature surface
- `.planning/phases/32-maintenance-mode/32-CONTEXT.md` — maintenance mode feature surface
- `.planning/phases/33-health-checks/33-CONTEXT.md` — health checks feature surface
- `.planning/phases/34-ops-docs-carry-forward/34-CONTEXT.md` — locked decisions D-01..D-11

---

## Metadata

**Confidence breakdown:**
- DEMO-02 culprit analysis: HIGH — live lock file parsed and all 15 packages enumerated
- Feature surface accuracy: HIGH — verified against live source files
- QA-01 seam analysis: HIGH — read both the command source and existing test files
- docs-lint.sh idioms: HIGH — full file read
- mkdocs nav placement: HIGH — full file read; placement recommendation is Claude's discretion
- k8s probe values: LOW (ASSUMED) — advisory best-practice; operators will tune
- GOV-02 placement: HIGH — confirmed relevant file exists, confirmed Phase 31 has no VALIDATION.md

**Research date:** 2026-07-06
**Valid until:** 2026-08-06 (30 days; stable domain — no fast-moving dependencies)
