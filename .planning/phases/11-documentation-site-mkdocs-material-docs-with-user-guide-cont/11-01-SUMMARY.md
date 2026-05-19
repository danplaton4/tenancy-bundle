---
phase: 11-documentation-site
plan: 01
subsystem: docs
tags: [mkdocs, mkdocs-material, github-pages, github-actions, documentation]

requires: []
provides:
  - MkDocs Material site configuration with three-tab navigation
  - Python dependency pinning for reproducible CI builds
  - GitHub Actions docs deployment workflow with path filtering
  - Landing page with hero, quick-start, features, and comparison table
  - Section index pages for User Guide, Contributor Guide, and Architecture Reference
  - 26 stub pages covering all nav-referenced documentation topics
affects:
  - 11-02
  - 11-03
  - 11-04
  - 11-05

tech-stack:
  added:
    - mkdocs-material==9.7.6
    - mkdocs (pulled in by mkdocs-material)
    - Pygments (bundled, PHP highlighting via extend_pygments_lang)
    - pymdownx extensions (bundled)
  patterns:
    - docs/ at repo root with mkdocs.yml at root (standard MkDocs layout)
    - navigation.tabs for three-audience documentation (User Guide / Contributor Guide / Architecture)
    - navigation.indexes requires index.md in each section directory
    - extend_pygments_lang with startinline:true for PHP highlighting without opening tags
    - Separate docs.yml workflow (path-filtered) independent from ci.yml
    - docs/requirements.txt pinning exact mkdocs-material version for reproducible CI

key-files:
  created:
    - mkdocs.yml
    - docs/requirements.txt
    - .github/workflows/docs.yml
    - docs/index.md
    - docs/user-guide/index.md
    - docs/contributor-guide/index.md
    - docs/architecture/index.md
    - docs/user-guide/installation.md (stub)
    - docs/user-guide/getting-started.md (stub)
    - docs/user-guide/configuration.md (stub)
    - docs/user-guide/resolvers.md (stub)
    - docs/user-guide/database-per-tenant.md (stub)
    - docs/user-guide/shared-db.md (stub)
    - docs/user-guide/cache-isolation.md (stub)
    - docs/user-guide/messenger.md (stub)
    - docs/user-guide/cli-commands.md (stub)
    - docs/user-guide/testing.md (stub)
    - docs/user-guide/strict-mode.md (stub)
    - docs/user-guide/examples/saas-subdomain.md (stub)
    - docs/user-guide/examples/api-header.md (stub)
    - docs/contributor-guide/setup.md (stub)
    - docs/contributor-guide/architecture.md (stub)
    - docs/contributor-guide/test-infrastructure.md (stub)
    - docs/contributor-guide/coding-standards.md (stub)
    - docs/contributor-guide/pr-workflow.md (stub)
    - docs/contributor-guide/custom-resolver.md (stub)
    - docs/contributor-guide/custom-bootstrapper.md (stub)
    - docs/architecture/event-lifecycle.md (stub)
    - docs/architecture/di-compilation.md (stub)
    - docs/architecture/dbal-wrapper.md (stub)
    - docs/architecture/sql-filter.md (stub)
    - docs/architecture/messenger-lifecycle.md (stub)
    - docs/architecture/design-decisions.md (stub)
  modified:
    - .gitignore (added site/ and .php-cs-fixer.cache)

key-decisions:
  - "mkdocs-material==9.7.6 pinned in docs/requirements.txt (not via mkdocs-material[recommended]) — minify plugin excluded as it requires separate install and would break CI builds"
  - "extend_pygments_lang with startinline:true registered for PHP — enables clean PHP snippets without <?php opening tags throughout all docs"
  - "Separate docs.yml workflow with paths filter (docs/**, mkdocs.yml) — decouples docs deployment from PHP CI, avoids triggering on every PHP commit"
  - "site/ added to .gitignore — mkdocs build output is generated, not source-controlled"

patterns-established:
  - "Pattern: All docs stub files contain exactly a # Title heading — minimum required for mkdocs build --strict to pass"
  - "Pattern: docs/requirements.txt is the single source of truth for Python dependencies — CI installs from this file"
  - "Pattern: Three navigation tabs map one-to-one to audience tracks (User Guide / Contributor Guide / Architecture Reference)"

requirements-completed: [DOC-01, DOC-02, DOC-03]

duration: 12min
completed: 2026-04-12
---

# Phase 11 Plan 01: Documentation Site Infrastructure Summary

**MkDocs Material 9.7.6 site with three-tab navigation, PHP syntax highlighting, GitHub Pages deployment pipeline, landing page with comparison matrix, and 30 docs files establishing the full nav tree**

## Performance

- **Duration:** ~12 min
- **Started:** 2026-04-12T00:00:00Z
- **Completed:** 2026-04-12T00:12:00Z
- **Tasks:** 2
- **Files modified:** 33 (3 infrastructure + 4 section pages + 26 stubs + .gitignore)

## Accomplishments

- MkDocs Material site configuration with full theme features, PHP highlighting fix, and three-tab nav
- GitHub Actions deployment workflow triggered only on docs/** and mkdocs.yml changes
- Landing page with hero headline, YAML/PHP quick-start tabs, features table, comparison matrix
- All 26 stub pages created so `mkdocs build --strict` passes immediately (exit code 0)

## Task Commits

Each task was committed atomically:

1. **Task 1: MkDocs configuration, Python deps, GitHub Actions workflow** - `92807d8` (feat)
2. **Task 2: Landing page, section indexes, all stub docs pages** - `3df9739` (feat)

**Plan metadata:** (docs commit pending)

## Files Created/Modified

- `mkdocs.yml` - Full Material theme config: navigation.tabs, PHP highlighting, 3-tab nav
- `docs/requirements.txt` - Pins mkdocs-material==9.7.6 for reproducible CI
- `.github/workflows/docs.yml` - Path-filtered deploy to GitHub Pages via mkdocs gh-deploy
- `docs/index.md` - Landing page with hero, quick-start YAML/PHP tabs, features, comparison
- `docs/user-guide/index.md` - User Guide section landing with navigation links
- `docs/contributor-guide/index.md` - Contributor Guide section landing
- `docs/architecture/index.md` - Architecture Reference section landing
- `docs/user-guide/*.md` (11 stubs + 2 example stubs) - Stub pages for all user guide nav entries
- `docs/contributor-guide/*.md` (7 stubs) - Stub pages for all contributor guide nav entries
- `docs/architecture/*.md` (6 stubs) - Stub pages for all architecture reference nav entries
- `.gitignore` - Added site/ (mkdocs build output) and .php-cs-fixer.cache

## Decisions Made

- Excluded minify plugin: not bundled with base mkdocs-material, requires separate pip install that would break CI builds with the pinned `docs/requirements.txt` approach
- Used `extend_pygments_lang` with `startinline: true` for PHP highlighting — the standard workaround for PHP code without opening `<?php` tags
- Separate `docs.yml` workflow (not added to `ci.yml`) — decouples docs deployment from PHP test failures; path filter prevents unnecessary redeploys on PHP-only commits
- Added `site/` to `.gitignore` as auto-fix (Rule 2) — mkdocs build output appeared as untracked files that should never be committed

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] Added site/ to .gitignore**
- **Found during:** Task 2 (after running mkdocs build --strict for verification)
- **Issue:** `mkdocs build` generated a `site/` directory that appeared as untracked files. Without .gitignore entry, this generated output would be accidentally committed.
- **Fix:** Added `site/` and `.php-cs-fixer.cache` to `.gitignore`
- **Files modified:** `.gitignore`
- **Verification:** `git status --short` shows `site/` is no longer listed as untracked
- **Committed in:** `3df9739` (Task 2 commit)

---

**Total deviations:** 1 auto-fixed (missing critical)
**Impact on plan:** Auto-fix prevents generated output from polluting version control. No scope creep.

## Issues Encountered

- `mkdocs build --strict` output includes a visible warning block from the Material team about MkDocs 2.0 incompatibility — this is an informational banner, not a strict warning, and the exit code is 0. The build passes correctly.

## Known Stubs

The following 26 stub files exist with only a `# Title` heading. They are intentional placeholders for subsequent plans (11-02 through 11-05) to fill in:

- `docs/user-guide/installation.md`
- `docs/user-guide/getting-started.md`
- `docs/user-guide/configuration.md`
- `docs/user-guide/resolvers.md`
- `docs/user-guide/database-per-tenant.md`
- `docs/user-guide/shared-db.md`
- `docs/user-guide/cache-isolation.md`
- `docs/user-guide/messenger.md`
- `docs/user-guide/cli-commands.md`
- `docs/user-guide/testing.md`
- `docs/user-guide/strict-mode.md`
- `docs/user-guide/examples/saas-subdomain.md`
- `docs/user-guide/examples/api-header.md`
- `docs/contributor-guide/setup.md`
- `docs/contributor-guide/architecture.md`
- `docs/contributor-guide/test-infrastructure.md`
- `docs/contributor-guide/coding-standards.md`
- `docs/contributor-guide/pr-workflow.md`
- `docs/contributor-guide/custom-resolver.md`
- `docs/contributor-guide/custom-bootstrapper.md`
- `docs/architecture/event-lifecycle.md`
- `docs/architecture/di-compilation.md`
- `docs/architecture/dbal-wrapper.md`
- `docs/architecture/sql-filter.md`
- `docs/architecture/messenger-lifecycle.md`
- `docs/architecture/design-decisions.md`

These stubs are intentional and necessary — they allow `mkdocs build --strict` to pass while providing anchor pages for all nav entries. Plans 11-02 through 11-05 will fill in content.

## User Setup Required

None — no external service configuration required. GitHub Pages source branch (`gh-pages`) must be manually configured in repository Settings once the first docs deployment runs, but this is a one-time post-deploy step.

## Next Phase Readiness

- All stub files exist: plans 11-02 through 11-05 can immediately start filling in content without breaking the build
- `mkdocs build --strict` passes at exit code 0 — ready for CI integration
- Three-tab navigation structure established and functional
- GitHub Actions workflow ready to deploy on first push to master touching docs files

---
*Phase: 11-documentation-site*
*Completed: 2026-04-12*

## Self-Check: PASSED

- FOUND: mkdocs.yml
- FOUND: docs/requirements.txt
- FOUND: .github/workflows/docs.yml
- FOUND: docs/index.md
- FOUND: docs/user-guide/index.md
- FOUND: docs/contributor-guide/index.md
- FOUND: docs/architecture/index.md
- FOUND: commit 92807d8
- FOUND: commit 3df9739
- mkdocs build --strict exits 0
