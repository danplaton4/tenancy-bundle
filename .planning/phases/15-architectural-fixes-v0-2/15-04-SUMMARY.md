---
phase: 15-architectural-fixes-v0-2
plan: 04
subsystem: documentation-refresh
tags: [tenancy, docs, mkdocs, changelog, upgrade, dbal-middleware, tenancy-init, lint]

# Dependency graph
requires:
  - phase: 15-architectural-fixes-v0-2 plan 01
    provides: TenantAwareCacheAdapter full substitution surface + CacheDecoratorContractPass (referenced in di-compilation.md + CHANGELOG 0.2.0 Fixed)
  - phase: 15-architectural-fixes-v0-2 plan 02
    provides: ResolverChain nullable semantics + TenantResolution (referenced in event-lifecycle.md + UPGRADE 0.1 to 0.2 behavior-change callout)
  - phase: 15-architectural-fixes-v0-2 plan 03
    provides: TenantDriverMiddleware + TenantAwareDriver + DatabaseSwitchBootstrapper close()-only (referenced throughout architecture + user-guide docs)
provides:
  - docs/architecture/dbal-middleware.md (replaces dbal-wrapper.md) — canonical middleware pipeline narrative
  - CHANGELOG.md [0.2.0] section with Changed / Fixed / Removed / Migration / Tooling subsections + retrospective
  - UPGRADE.md "0.1 to 0.2" section with per-fix migration recipes
  - scripts/docs-lint.sh — CI-grade regression guard against stale v0.1 terms
  - TenantInitCommand::printNextSteps() emits sample doctrine.yaml + driver-family callout
  - tests/Integration/Command/TenantInitCommandYamlContentTest.php — 4 tests / 12 assertions locking the emit contract
affects:
  - All docs consumers — nav structure shifts (dbal-wrapper -> dbal-middleware)
  - tenancy:init users — additional output block with sample doctrine.yaml + warning
  - CI — cs-fixer job now runs docs-lint.sh as an additional step

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Docs-as-policy: CI lint script (scripts/docs-lint.sh) treats stale architectural terms as build errors — prevents doc drift from code changes"
    - "Changelog retrospective: [0.2.0] opens with a paragraph narrating why v1.0.0 was retracted; keeps the versioning story discoverable for downstream consumers"
    - "Command scaffolding with driver-family callout: printNextSteps() now emits an annotated doctrine.yaml with a Symfony warning block for the driver-family-match requirement"

key-files:
  created:
    - docs/architecture/dbal-middleware.md (via git mv + rewrite)
    - scripts/docs-lint.sh
    - tests/Integration/Command/TenantInitCommandYamlContentTest.php
    - .planning/phases/15-architectural-fixes-v0-2/15-04-SUMMARY.md
  modified:
    - docs/architecture/design-decisions.md (decision #5 flipped to REJECTED)
    - docs/architecture/di-compilation.md (middleware + CacheDecoratorContractPass narration)
    - docs/architecture/event-lifecycle.md (nullable resolver chain, close()+middleware flow)
    - docs/architecture/index.md (nav link)
    - mkdocs.yml (nav label + path)
    - docs/user-guide/database-per-tenant.md (full rewrite — MySQL sample, driver-family callout)
    - docs/user-guide/configuration.md (driver description + database.enabled effect)
    - docs/user-guide/installation.md (optional-deps description)
    - docs/user-guide/getting-started.md (MySQL sample, driver-family callout, nullable narrative)
    - docs/user-guide/testing.md (initializeTenant narrative)
    - docs/user-guide/examples/saas-subdomain.md (MySQL sample + summary row)
    - docs/user-guide/index.md + docs/index.md (feature blurbs)
    - docs/contributor-guide/architecture.md (DBAL namespace + driver-mode description)
    - docs/contributor-guide/test-infrastructure.md (SQLite strategy narration + test renames)
    - docs/contributor-guide/setup.md (sample phpunit invocation path)
    - src/Command/TenantInitCommand.php (sampleDoctrineYaml + driver-family warning)
    - CHANGELOG.md (0.2.0 section + release-link footer)
    - UPGRADE.md (0.1 -> 0.2 section above 0.1 section)
    - .github/workflows/ci.yml (docs-lint step in cs-fixer job)
  deleted:
    - docs/architecture/dbal-wrapper.md (renamed via git mv to dbal-middleware.md)

key-decisions:
  - "git mv dbal-wrapper.md -> dbal-middleware.md + rewrite body, rather than keeping a redirect stub — no external v0.1 users per Packagist metadata (2 self-downloads)"
  - "Paraphrase around forbidden lint-script terms (wrapperClass, ReflectionProperty, wrapper_class, TenantConnection) in the 'Considered and rejected' narrative — avoids needing per-file lint-script exclusions and keeps the docs-lint scope uniform"
  - "CHANGELOG.md + UPGRADE.md are EXCLUDED from docs-lint.sh scope — migration recipes intentionally reference the deleted class by name so downstream users can find their old config"
  - "Driver-family-match callout is present in BOTH docs/user-guide/database-per-tenant.md AND tenancy:init CLI output — users reading either source land on the same warning, closing the doc/command drift gap that contributed to Issue #4"
  - "docs-lint.sh wired into the cs-fixer CI job (not a separate job) — keeps CI graph lean; lint cost is seconds"

patterns-established:
  - "Force-added SUMMARY.md inside worktree .planning (gitignored dir) via git add -f — required because the orchestrator deletes the worktree after return and uncommitted SUMMARY would be lost"
  - "composer install in worktree to break autoload symlink from main — required when executing integration tests that exercise modified src/ files (main repo's vendor cache points at main's src)"

requirements-completed: [FIX-04]

# Metrics
duration: ~45min
completed: 2026-04-20
---

# Phase 15 Plan 04: Documentation Refresh + CHANGELOG + UPGRADE Summary

**Docs now describe post-Phase-15 architecture accurately — wrapperClass/reflection narrative is renamed/rewritten as driver-middleware, all sqlite:// placeholders for MySQL tenants are replaced with pdo_mysql samples, CHANGELOG [0.2.0] + UPGRADE 0.1→0.2 capture the full migration path, and scripts/docs-lint.sh prevents future drift.**

## Performance

- **Duration:** ~45 min (7 tasks, 7 commits)
- **Tasks:** 7/7 executed exactly as planned
- **Files modified:** 22 (4 created, 17 modified, 1 renamed)
- **Tests:** 300 pass (up from 296 pre-plan) — 4 new integration tests in TenantInitCommandYamlContentTest

## Accomplishments

- **Architecture docs** — `docs/architecture/dbal-wrapper.md` renamed + rewritten as `docs/architecture/dbal-middleware.md`. New body describes the four-step middleware pipeline (compile-time tag → DriverManager wrap → lazy connect → close()+reconnect on tenant switch). A "Considered and rejected" section explains why the v0.1 subclass-plus-reflection approach is incompatible with DBAL 4's immutable `Connection::$driver`. `design-decisions.md` flips decision #5 from accepted to **REJECTED** with an explicit alternatives table. `di-compilation.md` lists `CacheDecoratorContractPass` (new from 15-01) and corrects the DatabaseSwitchBootstrapper narrative to `close()`-only. `event-lifecycle.md` updates the resolver-chain pseudocode for `?TenantResolution` return + null-branch in the orchestrator.
- **User-guide docs** — full rewrite of `database-per-tenant.md` (MySQL driver sample, middleware pipeline narrative, driver-family-match callout, discrete-params rule, no-url-key warning). `getting-started.md` and `examples/saas-subdomain.md` swap their `sqlite:///:memory:` + `wrapper_class` code blocks for MySQL placeholder configs with the same callout. `testing.md` rewrites the `initializeTenant()` sequence narration around `$connection->close()` + middleware lazy-reconnect. `configuration.md`, `installation.md`, `user-guide/index.md`, and `docs/index.md` fix their blurbs. Net effect: zero `wrapperClass`, `wrapper_class`, `TenantConnection`, `ReflectionProperty`, or `sqlite://` references remain in `docs/`.
- **Contributor-guide docs** — `architecture.md` namespace table points `Tenancy\Bundle\DBAL` at `TenantDriverMiddleware` + `TenantAwareDriver`. `test-infrastructure.md` describes the middleware-based SQLite strategy; `DatabaseSwitchIntegrationTest` (deleted in 15-03) references rename to `DatabasePerTenantMiddlewareIntegrationTest` with the new `tests/Integration/DBAL/` path. `setup.md` example invocation updated.
- **`tenancy:init` command** — `printNextSteps()` emits an annotated doctrine.yaml sample with `pdo_mysql`, dual entity managers, and a SymfonyStyle warning block for the driver-family requirement. A new integration test `TenantInitCommandYamlContentTest` (4 tests / 12 assertions) pins the emit contract: `pdo_mysql`, `placeholder_tenant`, `entity_managers`, landlord/tenant prefixes, driver-family callout, `TenantDriverMiddleware` mention — and negative assertions that no `wrapper_class` or `sqlite://` leaks.
- **CHANGELOG.md** — `[0.2.0] — 2026-04-20` section with Retrospective paragraph (why v1.0.0 was retracted), Changed / Fixed / Removed / Migration / Tooling subsections, explicit `Closes #5`, `#6`, `#7`, `#8` markers, and release-link footer entry.
- **UPGRADE.md** — new `## 0.1 to 0.2` section at the top covering all four fixes. Fix #6 includes the behavior-change callout for `kernel.exception` listeners and the `strict_mode` security note. Fix #7+#8 includes a before/after YAML diff, driver-family-match requirement, no-url-key rule, and post-upgrade `composer dump-autoload --optimize` + `cache:clear` commands.
- **`scripts/docs-lint.sh`** — executable shell script scanning `docs/` + `src/Command/TenantInitCommand.php` for five stale terms (`wrapperClass`, `wrapper_class`, `ReflectionProperty`, `TenantConnection`, `sqlite://`). Exits 0 currently; wired into `.github/workflows/ci.yml` cs-fixer job so future regressions fail before merge.

Closes GitHub Issue #4 (cross-cutting documentation accuracy).

## Task Commits

Executed against base `688e28d`. All commits use `--no-verify` per worktree execution policy (orchestrator re-runs hooks at merge).

1. **Task 1: Architecture docs rewrite**
   - `dfb0caf` — docs(15-04): rewrite architecture docs for DBAL driver-middleware design
2. **Task 2: User-guide docs rewrite**
   - `ddc4e4a` — docs(15-04): rewrite user-guide docs for middleware architecture
3. **Task 3: Contributor-guide docs update**
   - `e0d1b74` — docs(15-04): update contributor-guide docs for middleware architecture
4. **Task 4: tenancy:init command + test**
   - `67dd90c` — feat(15-04): emit sample doctrine.yaml from tenancy:init with MySQL driver
5. **Task 5: CHANGELOG + UPGRADE**
   - `4178110` — docs(15-04): add CHANGELOG 0.2.0 + UPGRADE 0.1 to 0.2 section
6. **Task 6: docs-lint.sh + CI wiring**
   - `1ebeab1` — chore(15-04): add scripts/docs-lint.sh + wire into CI
7. **Task 7: Full suite + cs-fixer style pass**
   - `a59b238` — style(15-04): php-cs-fixer pass on TenantInitCommand

## Files Created/Modified/Deleted

**Created (4):**
- `docs/architecture/dbal-middleware.md` (git-renamed from dbal-wrapper.md, body fully rewritten)
- `scripts/docs-lint.sh` (executable, scans two targets for five stale terms)
- `tests/Integration/Command/TenantInitCommandYamlContentTest.php` (4 tests / 12 assertions)
- `.planning/phases/15-architectural-fixes-v0-2/15-04-SUMMARY.md` (this file)

**Modified (17):**
- `docs/architecture/design-decisions.md` — decision #5 flipped to REJECTED with alternatives table
- `docs/architecture/di-compilation.md` — middleware + CacheDecoratorContractPass narration; dependency-graph node updated
- `docs/architecture/event-lifecycle.md` — resolver-chain return type and orchestrator null-branch narration; bootstrapper table updated
- `docs/architecture/index.md` — nav-link rename
- `mkdocs.yml` — nav entry label + path
- `docs/user-guide/database-per-tenant.md` — full rewrite
- `docs/user-guide/configuration.md` — driver description + database.enabled effect list
- `docs/user-guide/installation.md` — optional-deps table description
- `docs/user-guide/getting-started.md` — MySQL sample + driver-family callout + nullable narrative
- `docs/user-guide/testing.md` — initializeTenant narration
- `docs/user-guide/examples/saas-subdomain.md` — MySQL sample + summary row
- `docs/user-guide/index.md` + `docs/index.md` — feature blurbs
- `docs/contributor-guide/architecture.md` — DBAL namespace + driver-mode narration
- `docs/contributor-guide/test-infrastructure.md` — SQLite strategy + test renames
- `docs/contributor-guide/setup.md` — example phpunit invocation path
- `src/Command/TenantInitCommand.php` — sampleDoctrineYaml() + driver-family warning block
- `CHANGELOG.md` — [0.2.0] section + footer link
- `UPGRADE.md` — "0.1 to 0.2" section
- `.github/workflows/ci.yml` — docs-lint step in cs-fixer job

**Deleted (1):**
- `docs/architecture/dbal-wrapper.md` (rename via `git mv`)

## Decisions Made

- **Rename + rewrite over parallel rewrite.** Per RESEARCH § Open Question 3, the planner's recommendation was `git mv` to preserve git history and avoid nav-link churn. Packagist metadata showed 2 self-downloads on v0.1 — no external users with stale bookmarks to consider. Executed with `git mv docs/architecture/dbal-wrapper.md docs/architecture/dbal-middleware.md` followed by full body rewrite.
- **Paraphrase around forbidden lint-script terms in the "rejected" narrative.** The acceptance criterion `! grep -rEq '(wrapperClass|ReflectionProperty)' docs/architecture/` conflicts with `grep -q 'Considered and rejected'` — the rejection narrative naturally wants to name the rejected approach. Resolved by describing the approach functionally ("private-property reflection mutation", "DoctrineBundle tenant-connection YAML option that tells DriverManager to instantiate a custom Connection subclass") without using the literal forbidden strings. Keeps docs-lint scope uniform (no per-file exclusions).
- **CHANGELOG.md + UPGRADE.md excluded from docs-lint scope.** Migration recipes intentionally name the deleted class, the removed YAML option, and the dropped URL form so downstream users can locate their old config. The lint script's TARGETS array is `docs/` + `src/Command/TenantInitCommand.php` only.
- **docs-lint.sh wired into cs-fixer CI job, not a separate job.** The lint cost is milliseconds; a dedicated CI job would double infrastructure for no signal gain. cs-fixer runs on PHP 8.2 matrix which includes bash.
- **TenantInitCommand sample embedded as nowdoc heredoc.** Uses `<<<'YAML'` (quoted delimiter) to avoid escaping `$` parameter references (`'%env(DATABASE_URL)%'`). Heredoc indentation is 12 spaces (matching the method body) — PHP 7.3+ supports flexible heredoc indentation; the leading spaces are stripped by the parser.
- **Autoload regeneration in worktree.** The worktree's initial `vendor/` was a symlink to the main repo's vendor dir, which meant composer's classmap pointed at main repo `src/`. After editing `src/Command/TenantInitCommand.php` in the worktree, `composer install` in the worktree was required for the integration test to exercise the modified class. Future worktree executors should note this: `vendor/` as symlink causes "phantom" autoload behavior when modifying src/.

## Deviations from Plan

**1. [Rule 3 - Blocking] Worktree `.planning/` was sparse; files had to be read from the main repo**
- **Found during:** Initial context load
- **Issue:** The worktree's `.planning/` directory contained only a placeholder file from phase 14; the full phase-15 planning files (CONTEXT, RESEARCH, plan files, prior summaries) were present only in the main repo's `.planning/`. The PLAN.md reference `@.planning/phases/15-architectural-fixes-v0-2/15-CONTEXT.md` would have failed.
- **Fix:** Read all referenced planning files via absolute paths to the main repo. No code change — just a loading workaround. SUMMARY.md is committed into the worktree's `.planning/` via `git add -f` (the orchestrator merges it back).
- **Files modified:** None.
- **Committed in:** N/A — diagnostic only.

**2. [Rule 3 - Blocking] Worktree base commit mismatch; needed `git reset --hard`**
- **Found during:** Initial worktree branch verification
- **Issue:** The worktree's initial `HEAD` was `68840e1` (pre-15-01/02/03), but the executor instructions specified base `688e28d` (post-15-03 merge). Without plan 15-03 landed, the docs couldn't describe the actual current architecture.
- **Fix:** `git reset --hard 688e28dafccf125a171ea46ce96b705e15a94dc7` per the worktree_branch_check protocol.
- **Files modified:** None (the reset was a state correction).
- **Committed in:** N/A — pre-plan step.

**3. [Rule 3 - Blocking] Vendor symlink caused autoload to resolve to main repo's src/**
- **Found during:** Task 4, running TenantInitCommandYamlContentTest
- **Issue:** Initial `ln -s vendor /.../vendor` symlink to main repo caused `ReflectionClass::getFileName()` to point at main repo's `src/Command/TenantInitCommand.php`, so tests ran against the unmodified class. The 3 of 4 new assertions failed.
- **Fix:** `rm vendor && composer install` in the worktree. This took ~1 minute but rebuilt an autoload classmap that resolves to the worktree's src/.
- **Files modified:** None (vendor is gitignored).
- **Committed in:** N/A — dev-env step.

**4. [Rule 1 - style] Minor single-quote normalization on TenantInitCommand**
- **Found during:** Task 7 (full quality gate)
- **Issue:** `vendor/bin/php-cs-fixer check` flagged a double-quoted string in the new driver-family warning body (`@Symfony` ruleset).
- **Fix:** Replaced the two double-quoted concatenations with single quotes (no variable interpolation in either).
- **Files modified:** `src/Command/TenantInitCommand.php`.
- **Verification:** `vendor/bin/php-cs-fixer check --diff --allow-risky=yes` clean; `vendor/bin/phpunit tests/Unit/Command/ tests/Integration/Command/` all 14 tests pass.
- **Committed in:** `a59b238`.

**Total deviations:** 4 (3 environmental, 1 cosmetic). **Impact:** none on planned artifacts or scope. The plan's files-to-modify list was followed exactly.

## Threat Model Coverage

Threat register dispositions from the plan (T-15-14..T-15-16) are implemented as specified:

- **T-15-14 (Tampering — docs drift with stale code examples):** Mitigated. `scripts/docs-lint.sh` runs in CI on every PR as part of the cs-fixer job. Five stale terms scanned. Exit non-zero if any match surfaces in `docs/` or `src/Command/TenantInitCommand.php`.
- **T-15-15 (Information Disclosure — rejected approach described as current):** Mitigated. `docs/architecture/design-decisions.md` §5 explicitly flips to **REJECTED** with DBAL 4 immutability rationale and an alternatives table. `docs/architecture/dbal-middleware.md` §"Considered and rejected" explains why the v0.1 approach was tried and why it doesn't work on DBAL 4.
- **T-15-16 (Denial of Service — user copies sqlite:// placeholder for MySQL deployment):** Mitigated. All `sqlite://` URL-form placeholders are gone from `docs/` and `src/Command/TenantInitCommand.php`. The driver-family-match callout is present in BOTH `docs/user-guide/database-per-tenant.md` AND the tenancy:init CLI output. The lint script's `sqlite://` pattern catches any re-introduction.

No new threat surface introduced beyond what was modeled.

## Issues Encountered

- **Worktree vendor symlink vs. composer autoload:** initial test runs resolved `TenantInitCommand` to the main repo's unmodified class. Root cause documented above; resolution was a standard `composer install` in the worktree. Future worktree executors should either (a) run composer install up front, or (b) verify `ReflectionClass::getFileName()` for critical classes before running tests that depend on src/ changes.
- **SymfonyStyle heredoc output appeared truncated in early debug runs:** turned out to be the vendor-symlink issue masquerading as truncation. Once composer install resolved autoload correctly, the sample YAML + warning block appeared in the command output as designed.
- **Phase 15-03 had deleted `tests/Integration/DatabaseSwitchIntegrationTest.php`** but the contributor-guide docs still referenced the file in two places (test-infrastructure.md + setup.md). Updated both to reference the replacement `DatabasePerTenantMiddlewareIntegrationTest` at its new `tests/Integration/DBAL/` path. This discovery was in-scope (contributor-guide was in the plan's files_modified list).

No issues blocked task completion.

## Quality Gate

Full phase gate (Task 7):

- `vendor/bin/phpunit` — **300 tests / 720 assertions, all green** (up from 296 pre-plan — 4 new in `TenantInitCommandYamlContentTest`)
- `vendor/bin/phpstan analyse --memory-limit=512M` — **level 9 clean across 44 files**
- `vendor/bin/php-cs-fixer check --diff --allow-risky=yes` — **clean** (0 files changed after the Task 7 style pass)
- `./scripts/docs-lint.sh` — **OK** (no stale v0.1 terms anywhere in `docs/` or `src/Command/TenantInitCommand.php`)

## TDD Gate Compliance

Task 4 tests were authored alongside the source change rather than RED-first — acceptable for documentation-adjacent integration tests where the exact assertion shape depends on the emitted CLI output format. The plan marked Task 4 as `type="auto"` (no `tdd="true"`), so no RED/GREEN gate was required.

Task 6's docs-lint.sh is tested by self-execution: running the script against the current tree must exit 0, which is covered in Task 7's quality gate.

## Next Phase Readiness

- **Phase 15 merge / v0.2.0 tag** — all four fixes (FIX-01 through FIX-04) are landed, documented, and covered by regression tests. The architecture is internally consistent (docs match source) and the stale-term lint is in CI. Recommended tag message: "v0.2.0 — architectural fixes for cache decorator, resolver optionality, DBAL 4 middleware, documentation accuracy. Closes #5, #6, #7, #8."
- **Phase 16 (next)** — open for planning. Candidates from backlog: `#[RequiresTenant]` attribute (deferred from plan 15-02), RLS driver (ISOL-06 — v1.1 territory), profiler toolbar tab (DX-02), PHPStan extension (DX-03).
- **Commit footers `Fixes #5`, `#6`, `#7`, `#8`** — should be added in the Phase 15 merge commit or on individual close-issue PRs if those are pursued separately.

## Self-Check: PASSED

**Files verified:**
- FOUND: docs/architecture/dbal-middleware.md
- FOUND: docs/architecture/design-decisions.md (modified — decision #5 REJECTED)
- FOUND: docs/architecture/di-compilation.md (modified)
- FOUND: docs/architecture/event-lifecycle.md (modified)
- FOUND: docs/architecture/index.md (modified)
- DELETED: docs/architecture/dbal-wrapper.md
- FOUND: mkdocs.yml (modified)
- FOUND: docs/user-guide/database-per-tenant.md (rewritten)
- FOUND: docs/user-guide/configuration.md (modified)
- FOUND: docs/user-guide/installation.md (modified)
- FOUND: docs/user-guide/getting-started.md (modified)
- FOUND: docs/user-guide/testing.md (modified)
- FOUND: docs/user-guide/examples/saas-subdomain.md (modified)
- FOUND: docs/user-guide/index.md (modified)
- FOUND: docs/index.md (modified)
- FOUND: docs/contributor-guide/architecture.md (modified)
- FOUND: docs/contributor-guide/test-infrastructure.md (modified)
- FOUND: docs/contributor-guide/setup.md (modified)
- FOUND: src/Command/TenantInitCommand.php (modified — sampleDoctrineYaml + warning)
- FOUND: tests/Integration/Command/TenantInitCommandYamlContentTest.php
- FOUND: CHANGELOG.md (modified — [0.2.0] section)
- FOUND: UPGRADE.md (modified — 0.1 to 0.2 section)
- FOUND: scripts/docs-lint.sh (executable)
- FOUND: .github/workflows/ci.yml (modified — docs-lint step)

**Commits verified:**
- FOUND: dfb0caf (Task 1 — architecture docs)
- FOUND: ddc4e4a (Task 2 — user-guide docs)
- FOUND: e0d1b74 (Task 3 — contributor-guide docs)
- FOUND: 67dd90c (Task 4 — tenancy:init + test)
- FOUND: 4178110 (Task 5 — CHANGELOG + UPGRADE)
- FOUND: 1ebeab1 (Task 6 — docs-lint.sh + CI)
- FOUND: a59b238 (Task 7 — cs-fixer style pass)

**Quality gate:**
- phpunit (300 tests): PASS
- phpstan (level 9, 44 files): PASS
- php-cs-fixer (@Symfony, risky): PASS
- docs-lint.sh: PASS

---
*Phase: 15-architectural-fixes-v0-2*
*Plan: 04*
*Completed: 2026-04-20*
