---
phase: 22-docs-refresh
plan: 06
subsystem: docs
tags:
  - docs
  - roadmap-mirror
  - mkdocs-nav
  - docs-lint
  - integration

# Dependency graph
requires:
  - phase: 22-docs-refresh/22-01
    provides: composer.json (nikic moved to require) + installation.md one-command flow + docs/index.md Quick Start rewrite
  - phase: 22-docs-refresh/22-02
    provides: UPGRADE.md 0.3.2 to 0.3.3 section
  - phase: 22-docs-refresh/22-03
    provides: docs/user-guide/mailer-bootstrapper.md (new) + profiler-tab.md 3-state ASCII renders
  - phase: 22-docs-refresh/22-04
    provides: docs/examples/ relocation (saas-subdomain, api-header moved up) + new saas-demo.md thin page
  - phase: 22-docs-refresh/22-05
    provides: getting-started teasers + configuration origin/mailer blocks + resolvers.md OriginHeaderResolver entry + cli-commands.md tenancy:install promoted
provides:
  - canonical docs/roadmap.md (mkdocs nav, /roadmap/ URL on docs site)
  - slimmed repo-root ROADMAP.md (7-line pointer for GitHub-UI discoverability)
  - README.md surface pointing to docs-site roadmap URL (both L12 badge + ## Roadmap section)
  - reorganized mkdocs.yml nav (User Guide expanded with profiler-tab + origin-header-resolver + mailer-bootstrapper; new top-level Examples and Roadmap sections; old nested Examples removed)
  - docs/index.md resolver count corrected to 5 (matches README L104) + Roadmap CTA button
  - scripts/docs-lint.sh extended with whitelist-aware bundles.php install-path regression guard (D-15)
affects:
  - v0.3.3-release-tag (this is the LAST phase 22 plan; all six are now SUMMARY-complete)
  - future-docs-PRs (the new docs-lint rule guards against bundles.php install-path regressions)

# Tech tracking
tech-stack:
  added: []  # docs/script-only changes
  patterns:
    - "Canonical docs source in docs/ + thin repo-root pointer (drift-proof single source of truth)"
    - "awk H2-section-scoped whitelist for docs-lint regression rules (extensible to other patterns)"

key-files:
  created:
    - docs/roadmap.md  # canonical roadmap (45 lines, full content)
  modified:
    - ROADMAP.md  # slimmed to 7-line docs-site pointer
    - README.md  # both L12 badge + ## Roadmap section now link to docs-site URL
    - mkdocs.yml  # nav reorganized: new User Guide pages, top-level Examples + Roadmap
    - docs/index.md  # resolver count 4→5 + Roadmap CTA button
    - scripts/docs-lint.sh  # new D-15 awk-based bundles.php install-path guard

key-decisions:
  - "docs/roadmap.md is the canonical roadmap; repo-root ROADMAP.md is a 7-line pointer to https://danplaton4.github.io/tenancy-bundle/roadmap/. Single source of truth = docs site (D-03/D-04)."
  - "Both README.md L12 badge AND ## Roadmap section point to docs-site URL for consistency — users clicking either link land on the same canonical page (D-05)."
  - "mkdocs nav ordering: install flow → core drivers → resolvers (chain + Origin) → bootstrappers (cache + mailer) → integrations (messenger) → CLI → dev tooling (Profiler) → testing → strict mode. Examples and Roadmap become top-level (peers of User Guide), not subsections."
  - "docs-lint Approach A whitelist widened beyond plan literal (Migration/Upgrade/Manual setup/Troubleshooting/Do I have to do anything?) to ALSO include tenancy:install — because cli-commands.md's `## tenancy:install` H2 documents the command's AUTO-MUTATION behavior, which is legitimately about bundles.php. See Deviation #1."
  - "docs/roadmap.md's CHANGELOG link converted from relative `(CHANGELOG.md)` to absolute GitHub URL — relative repo-root links don't resolve from `/docs/` rendered on the docs site (mkdocs renders at /tenancy-bundle/roadmap/)."

patterns-established:
  - "Roadmap drift-proofing: thin repo-root pointer + canonical docs-site page eliminates structural drift between the two surfaces."
  - "docs-lint awk pattern: H2 section-scoping (track current `^## ` heading, skip body lines under whitelisted sections) is reusable for future regression rules."
  - "mkdocs nav grouping: examples and roadmap deserve top-level peers, not deep nesting under User Guide. Users discover them from the global sidebar."

requirements-completed:
  - DOC-19

# Metrics
duration: 28min
completed: 2026-05-28
---

# Phase 22 Plan 06: Docs Refresh Integration Summary

**Wired the v0.3.3 docs-site final state: canonical docs/roadmap.md replaces repo-root ROADMAP.md, mkdocs nav reorganized to surface all v0.3 pages, README + docs/index.md updated to point at the new roadmap URL, and scripts/docs-lint.sh extended with a whitelist-aware bundles.php install-path regression guard.**

## Performance

- **Duration:** ~28 min (sequential on main tree, normal commits with hooks running php-cs-fixer + PHPStan + PHPUnit on each task)
- **Started:** 2026-05-28T~19:25Z (approx)
- **Completed:** 2026-05-28T~19:53Z (approx)
- **Tasks:** 6 (5 file-modifying + 1 verification-only)
- **Files modified:** 6 (1 created: docs/roadmap.md; 5 modified: ROADMAP.md, README.md, mkdocs.yml, docs/index.md, scripts/docs-lint.sh)

## Accomplishments

- **Roadmap inverted to docs-site canonical.** Full ~46-line roadmap content moved from repo-root ROADMAP.md to docs/roadmap.md verbatim (modulo one cross-link to CHANGELOG.md converted to absolute GitHub URL). Repo-root ROADMAP.md slimmed to a 7-line pointer — structurally drift-proof.
- **README.md aligned to docs-site URL.** Both the L12 badge-line `[Roadmap](...)` and the L188 `## Roadmap` section now link to https://danplaton4.github.io/tenancy-bundle/roadmap/.
- **mkdocs.yml nav reorganized for v0.3.** User Guide gained Origin Header Resolver, Mailer Bootstrapper, and Profiler Tab (3 pages that existed on disk but weren't navigable). Old nested `Examples:` block removed; new top-level `Examples:` (3 entries) and top-level `Roadmap:` added. Reordering follows install → drivers → resolvers → bootstrappers → integrations → CLI → dev tooling → testing → strict mode.
- **docs/index.md resolver count fixed.** L73 `**4 built-in resolvers**` → `**5 built-in resolvers**` with Origin header (SPA-friendly, allow-listed) in the description, matching README L104. Added Roadmap CTA button to the Quick Start block.
- **docs-lint extended with D-15 rule.** Awk-based H2-section-scoped check fails CI on `bundles.php` references outside whitelisted sections (Migration / Upgrade / Manual setup / Troubleshooting / Do I have to do anything? / tenancy:install). Synthetic-violation tested to confirm the rule fires; passes clean against current docs/ state.
- **Integration smoke run.** docs-lint exits 0; composer validate (non-strict) exits 0; mkdocs build --strict deferred to CI (mkdocs not installed locally; flagged below).

## Task Commits

Each task was committed atomically with pre-commit hooks running php-cs-fixer + PHPStan + PHPUnit (559 tests / 2,069 assertions, all green):

1. **Task 1: Create canonical docs/roadmap.md + slim repo-root ROADMAP.md** — `a2ac271` (docs)
2. **Task 2: Point README.md Roadmap section at docs-site URL** — `0accdf8` (docs)
3. **Task 3: Reorganize mkdocs.yml nav** — `eabe7b2` (docs)
4. **Task 4: Fix resolver count + add Roadmap link in docs/index.md** — `6751be6` (docs)
5. **Task 5: Extend scripts/docs-lint.sh with D-15 bundles.php guard** — `30f0b7d` (feat)
6. **Task 6: Integration smoke** — no commit (verification-only task; composer.lock sync was a no-op after re-running `composer update nikic/php-parser` — composer reported "Nothing to modify")

**Plan metadata commit:** will follow this SUMMARY (docs(22-06): create SUMMARY.md).

## Files Created/Modified

- **CREATED** `docs/roadmap.md` (45 lines) — canonical roadmap content (Shipped, In progress, Next, Planned, Future, Want something here?). CHANGELOG cross-link converted from relative to absolute GitHub URL for docs-site compatibility.
- **MODIFIED** `ROADMAP.md` (46 → 7 lines) — slimmed to a single docs-site pointer for GitHub-UI discoverability.
- **MODIFIED** `README.md` (2 lines changed) — L12 badge `[Roadmap](ROADMAP.md)` → `[Roadmap](https://danplaton4.github.io/tenancy-bundle/roadmap/)`; L188 `## Roadmap` section prose now reads "See the [roadmap on the documentation site](https://...) for what's shipping next...".
- **MODIFIED** `mkdocs.yml` (105 → 110 lines, nav block restructured) — User Guide expanded (3 new entries: Origin Header Resolver, Mailer Bootstrapper, Profiler Tab) + reordered. Old nested `Examples:` removed. New top-level `Examples:` (3 entries) and `Roadmap:` (1 entry) added.
- **MODIFIED** `docs/index.md` (2 lines changed) — L73 Features table resolver-count fix + Roadmap CTA button under Quick Start.
- **MODIFIED** `scripts/docs-lint.sh` (47 → 95 lines) — D-15 awk-based bundles.php install-path guard, fully documented in-script with rationale for the wider-than-plan-literal whitelist.

## Verification Gates

All gates the plan defined were run:

- ✅ `test -f docs/roadmap.md` AND `grep -c "v0.3 Adoption Surface" docs/roadmap.md` → 1
- ✅ `wc -l ROADMAP.md` → 7 (target ≤10/~5; passes with slack)
- ✅ `grep -n "danplaton4.github.io/tenancy-bundle/roadmap" README.md` → 2 matches (L12 badge + L188 prose)
- ✅ `grep -n "danplaton4.github.io/tenancy-bundle/roadmap" ROADMAP.md` → 1 match
- ✅ `grep -n "mailer-bootstrapper.md" mkdocs.yml` → 1 match
- ✅ `grep -n "profiler-tab.md" mkdocs.yml` → 1 match
- ✅ `grep -n "origin-header-resolver.md" mkdocs.yml` → 1 match
- ✅ `grep -n "  - Roadmap: roadmap.md" mkdocs.yml` → 1 match (top-level, 2-space indent)
- ✅ `grep -nE "examples/(saas-subdomain|api-header|saas-demo)\.md" mkdocs.yml` → 3 matches
- ✅ No old nested `user-guide/examples/` paths remain in mkdocs.yml (verified `grep -c` returned 0)
- ✅ `grep -n "5 built-in resolvers" docs/index.md` → 1 match
- ✅ `bash scripts/docs-lint.sh` → exit 0 (clean against current docs/)
- ⚠ `mkdocs build --strict` → SKIPPED locally (`mkdocs` not installed on this machine). Canonical gate is `.github/workflows/docs.yml` line 39 on every push to master. Local-verification path: `pip install -r docs/requirements.txt && mkdocs build --strict`.
- ⚠ `composer validate --strict --no-check-publish` → exit 1 (only the expected nikic require/require-dev duplication warning, intentional per D-09). Non-strict `composer validate --no-check-publish` → exit 0. The lock-file-out-of-sync error from earlier resolved itself: composer reported "Nothing to modify in lock file" because the lock was already in sync — the validate warning was stale.

### Synthetic-violation test for the new D-15 rule

I dropped a test file `docs/_test-lint-trip.md` with a `## Quick Start` section (NOT whitelisted) containing `bundles.php`. `bash scripts/docs-lint.sh` correctly exited 1 with the file:line violation. After removing the test file, the script exited 0 again. The rule works.

## Decisions Made

See `key-decisions` in frontmatter. Highlights:

- **Roadmap inversion direction.** Per locked decisions D-03/D-04/D-05, the docs-site is canonical and the repo-root file is a thin pointer. The opposite direction (repo-root canonical, docs-site mirror) was rejected because docs-site updates would lag behind repo-root edits, creating drift.
- **mkdocs nav promotion of Examples and Roadmap to top-level.** Per D-07 + D-16, these are peers of User Guide / Contributor Guide / Architecture Reference. They aren't sub-topics — they're independent discovery surfaces with their own URL prefixes.
- **CHANGELOG cross-link in docs/roadmap.md.** The original ROADMAP.md L8 had `[CHANGELOG](CHANGELOG.md)` (relative repo-root link). When moved to docs/roadmap.md, relative `CHANGELOG.md` no longer resolves (the file is at repo root, not in docs/). I converted to absolute GitHub URL `https://github.com/danplaton4/tenancy-bundle/blob/master/CHANGELOG.md`. This is technically a deviation from "verbatim" but the alternative (keeping the broken relative link) would fail mkdocs --strict. Documented in the Task 1 commit message.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] Widened docs-lint whitelist to include `tenancy:install`**

- **Found during:** Task 5 (extending scripts/docs-lint.sh)
- **Issue:** The plan's Approach A whitelist (Migration / Upgrade / Manual setup / Troubleshooting / Do I have to do anything?) was derived from RESEARCH Open Q1, which only enumerated profiler-tab.md's bundles.php references. But Plan 22-05 created `## tenancy:install` H2 in cli-commands.md, which LEGITIMATELY references `config/bundles.php` 7 times (lines 11, 33, 34, 36, 38, 42, 48) because the command's PRIMARY behavior IS the auto-mutation of bundles.php via nikic AST. Without `tenancy:install` in the whitelist, the new lint rule would block legitimate Plan 22-05 documentation. The plan's literal whitelist text was incomplete relative to the post-Plan-22-05 docs state.
- **Fix:** Added `tenancy:install` to the awk whitelist regex: `^(Migration|Upgrade|Manual setup|Troubleshooting|Do I have to do anything\?|tenancy:install)`. Documented the rationale in a comment block above the rule in scripts/docs-lint.sh so future authors understand why the whitelist is wider than CONTEXT.md D-15's literal text.
- **Files modified:** scripts/docs-lint.sh
- **Verification:** `bash scripts/docs-lint.sh` exits 0 against current docs/ state (including all 7 bundles.php mentions in cli-commands.md). Synthetic test: dropped `bundles.php` reference in a `## Quick Start` section → rule fires correctly with exit 1.
- **Committed in:** `30f0b7d` (Task 5 commit)

**2. [Rule 3 - Blocking] Ran `composer update nikic/php-parser` to verify lock file sync**

- **Found during:** Task 6 (integration smoke — first `composer validate --strict` run reported "Lock file is not up to date")
- **Issue:** Plan 22-01 (commit 6bc1482) added nikic/php-parser to composer.json `require` but did not commit a lock file update. First `composer validate --strict --no-check-publish` exited 2 with "The lock file is not up to date with the latest changes in composer.json".
- **Fix:** Ran `composer update nikic/php-parser --no-interaction`. Composer reported "Nothing to modify in lock file" — the lock file was actually already correctly synced (the validate warning was stale from a prior state). No files changed; working tree remained clean after the run. Subsequent `composer validate --no-check-publish` (non-strict) exits 0. Strict mode exits 1 only on the intentional D-09 require/require-dev duplication warning.
- **Files modified:** none (composer.lock was already in sync; this was a verification step)
- **Verification:** `git status --short` shows no modifications. Both `composer validate --no-check-publish` and `composer validate --strict --no-check-publish` re-run show only the D-09 duplication warning (intentional, documented in commit 6bc1482).
- **Committed in:** N/A (no file changes)

---

**Total deviations:** 2 auto-fixed (1 missing critical, 1 blocking)
**Impact on plan:** Both deviations were necessary for the docs-lint rule to be correct against the post-Plan-22-05 docs state and for the integration smoke to be meaningful. No scope creep — both stayed within Plan 22-06's authorized files (scripts/docs-lint.sh) or were verification-only (composer commands).

## Issues Encountered

- **mkdocs not installed locally.** This machine doesn't have mkdocs on PATH. The plan's verification gate ("mkdocs build --strict exits 0") cannot be run locally. Per the plan, I logged this gap and proceeded — the canonical gate is `.github/workflows/docs.yml` line 39 which runs `mkdocs build --strict` on every push to master. Manual local verification path: `pip install -r docs/requirements.txt && mkdocs build --strict`. ALL 20 files referenced in the new mkdocs nav have been verified to exist on disk (Task 3 file-presence check), so the most common --strict failure mode (missing nav target) is precluded.
- **composer validate --strict exit code.** Strict mode treats the intentional D-09 require/require-dev duplication as an error (exits 1). Per Plan 22-01's documented design, nikic stays in both `require` (for one-command install) and `require-dev` (for tooling); composer dedupes during install. CI does not actually run `composer validate` (verified via `grep -rn "composer validate" .github/`), so this strict-mode exit code is a local-only cosmetic. Non-strict `composer validate --no-check-publish` exits 0.

## Phase 22 Closure

This is the **last plan in Phase 22**. All six plans are now SUMMARY-complete:

- 22-01-SUMMARY.md — composer.json (nikic→require) + installation.md rewrite + index.md Quick Start
- 22-02-SUMMARY.md — UPGRADE.md 0.3.2 to 0.3.3 section
- 22-03-SUMMARY.md — profiler-tab.md 3-state ASCII renders + mailer-bootstrapper.md (new)
- 22-04-SUMMARY.md — examples/ relocation + saas-demo.md (new)
- 22-05-SUMMARY.md — getting-started/configuration/resolvers/cli-commands yellow-page edits
- 22-06-SUMMARY.md — this file (integration: roadmap mirror, mkdocs nav, docs-lint extension)

**Phase 22 deliverables (DOC-19) closed:** SC1 (install page one-command) ✅ — Plan 22-01. SC2 (profiler-tab ASCII + Mailer page) ✅ — Plan 22-03. SC3 (demo walkthrough) ✅ — Plan 22-04. SC4 (roadmap mirror) ✅ — Plan 22-06 Task 1+4. SC5 (UPGRADE.md 0.2→0.3 final) ✅ — Plan 22-02. SC6 (docs-lint extended) ✅ — Plan 22-06 Task 5.

**v0.3.3 release readiness:** docs are aligned with the v0.3 surface. The remaining steps (composer tag, GitHub release, push to Packagist) are outside Phase 22's scope.

## User Setup Required

None — no external service configuration required. All changes are documentation, build manifest (composer.json was Plan 22-01), and CI script edits.

## Next Phase Readiness

- **v0.3.3 release tag:** docs are ready. The CI pipeline (`mkdocs build --strict` + existing CI workflows) is the bottom-line gate before tagging.
- **Future docs PRs:** the new D-15 docs-lint rule guards against `bundles.php` install-path regressions. Any PR re-introducing manual `bundles.php` editing prose outside whitelisted sections (Migration / Upgrade / Manual setup / Troubleshooting / Do I have to do anything? / tenancy:install) will fail CI.
- **Phase 23+:** No blockers. Phase 22 closed DOC-19. The next phase can be planned freely.

## Self-Check: PASSED

Verified all created/modified files exist:

- FOUND: docs/roadmap.md
- FOUND: ROADMAP.md (slimmed)
- FOUND: README.md (modified)
- FOUND: mkdocs.yml (modified)
- FOUND: docs/index.md (modified)
- FOUND: scripts/docs-lint.sh (modified)

Verified all commit hashes:

- FOUND: a2ac271 (Task 1)
- FOUND: 0accdf8 (Task 2)
- FOUND: eabe7b2 (Task 3)
- FOUND: 6751be6 (Task 4)
- FOUND: 30f0b7d (Task 5)

---
*Phase: 22-docs-refresh*
*Completed: 2026-05-28*
