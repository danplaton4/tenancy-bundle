---
phase: 24-filesystem-bootstrapper
plan: 09
subsystem: filesystem
tags: [filesystem, demo, docs, flysystem, upgrade-guide, examples]

# Dependency graph
requires:
  - phase: 24-filesystem-bootstrapper/07
    provides: "FilesystemBootstrapper + FilesystemContractPass + tenancy.filesystem config node — config node shape the docs and services.yaml reference"
  - phase: 24-filesystem-bootstrapper/08
    provides: "Integration test suite proving all BOOT-03 acceptance criteria — docs can cite test file paths as verification evidence"

provides:
  - "examples/saas/src/Controller/TenantUploadController.php — GET /uploads + POST /uploads exercising bundle's prefix-mode decorator end-to-end"
  - "examples/saas/templates/upload/index.html.twig — file list + upload form (~25 lines)"
  - "examples/saas/config/packages/flysystem.yaml — users.storage with local adapter"
  - "examples/saas/config/services.yaml — tenancy.scoped tag on users.storage (strategy: prefix)"
  - "examples/saas/config/packages/tenancy.yaml — filesystem.enabled: true"
  - "examples/saas/composer.json — league/flysystem-bundle ^3.7 + league/flysystem-memory ^3.31 in require"
  - "docs/user-guide/filesystem-bootstrapper.md — 8-section seed page covering prefix mode, per-tenant-adapter mode, config reference, exceptions, path-traversal trust boundary, FAQ (7 pitfalls)"
  - "mkdocs.yml nav entry — Filesystem Bootstrapper after Mailer Bootstrapper in User Guide"
  - "UPGRADE.md ## 0.3 → 0.4 section — zero-BC-break adoption path, flysystem-bundle install, tenancy.scoped tagging, filesystem_config migration SQL"

affects:
  - "Phase 29 (DOC-20) — polish/expand docs/user-guide/filesystem-bootstrapper.md; add Profiler Filesystem subsection"
  - "Future contributors reading examples/saas/ — upload page demonstrates bundle wiring shape"

# Tech tracking
tech-stack:
  added:
    - "league/flysystem-bundle: ^3.7 (added to examples/saas/composer.json require)"
    - "league/flysystem-memory: ^3.31 (added to examples/saas/composer.json require)"
  patterns:
    - "Demo controller pattern: inject FilesystemOperator $usersStorage via constructor; never touch tenant slug; decorator handles prefix transparently"
    - "Docs seed pattern: 8-section structure mirroring mailer-bootstrapper.md (Overview / Installation / Quick Start / Per-tenant mode / Config reference / Exceptions / Trust boundary / FAQ)"
    - "UPGRADE.md insertion: newest-first, ## X.Y → X.Z above existing patch-level sections"

key-files:
  created:
    - "examples/saas/src/Controller/TenantUploadController.php"
    - "examples/saas/templates/upload/index.html.twig"
    - "examples/saas/config/packages/flysystem.yaml"
    - "docs/user-guide/filesystem-bootstrapper.md"
  modified:
    - "examples/saas/config/services.yaml (tenancy.scoped tag + comments)"
    - "examples/saas/config/packages/tenancy.yaml (filesystem.enabled: true)"
    - "examples/saas/composer.json (2 new require entries)"
    - "mkdocs.yml (nav entry after mailer-bootstrapper)"
    - "UPGRADE.md (0.3 → 0.4 section at top)"

key-decisions:
  - "Demo controller uses basename() sanitisation for uploaded filenames — application-level cosmetic guard matching the docs trust-boundary section. No deeper sanitisation (realpath() etc.) in the demo — the docs section covers the full recipe."
  - "Twig template lists files unconditionally (no tenant-state guard branch) per Phase 23 INT-01 lesson."
  - "TenantUploadController accepts TenantContext in constructor (for template rendering) even though the filesystem operations are fully transparent — needed because the template displays tenant.name and tenant.slug."
  - "flysystem.yaml uses 'adapter: local' + 'options.directory' shape (league/flysystem-bundle 3.x canonical config)."
  - "composer.lock is gitignored at root; ran `composer update league/flysystem-bundle league/flysystem-memory --no-interaction` to update the lock file locally; only composer.json is tracked in git."
  - "Docs page forward-references Phase 29 (DOC-20) in two places: the intro paragraph and the See also section for the Profiler Filesystem subsection."

patterns-established:
  - "Pattern: per-tenant upload demo. One controller + one template exercises the full bundle decoration chain. Future bootrstrappers (queue, cache) can follow this 3-file demo pattern."
  - "Pattern: UPGRADE.md insertion order. New minor-version sections go above existing patch-level sections (newest-first). The 0.3 → 0.4 section is above 0.3.2 to 0.3.3."

requirements-completed: [BOOT-03]

# Metrics
duration: 15min
completed: 2026-06-03
---

# Phase 24 Plan 09: Demo Upload Page + Docs Seed + UPGRADE 0.3 → 0.4 Summary

**Per-tenant upload demo (GET+POST /uploads via FilesystemPrefixingDecorator), 8-section filesystem-bootstrapper.md seed page, and zero-BC-break UPGRADE 0.3→0.4 section closing Phase 24 with both in-process (Plan 24-08) and live-exercise verification paths in place**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-06-02T21:00:00Z
- **Completed:** 2026-06-03T00:00:00Z
- **Tasks:** 3
- **Files created:** 4
- **Files modified:** 5

## Accomplishments

- Shipped `TenantUploadController` with GET `/uploads` (list) + POST `/uploads` (write via bundle-decorated `$usersStorage`) — controller never references the tenant slug; the decorator handles the `tenant_{slug}/` prefix transparently
- Shipped `upload/index.html.twig` — file listing + upload form, extends `base.html.twig`, unconditional rendering (no tenant-state guard per Phase 23 INT-01 lesson)
- Wired `examples/saas/` Flysystem config: `flysystem.yaml` (local adapter for `users.storage`), `services.yaml` (`tenancy.scoped` tag with `strategy: prefix`), `tenancy.yaml` (`filesystem.enabled: true`), `composer.json` (`league/flysystem-bundle ^3.7` + `league/flysystem-memory ^3.31` in `require`)
- Created `docs/user-guide/filesystem-bootstrapper.md` (8 H2 sections, ~290 lines): Overview, Installation, Quick Start (prefix mode), Per-tenant-adapter mode, Configuration reference, Exception handling, Trust boundary (path traversal), FAQ (7 pitfalls from RESEARCH.md)
- Added mkdocs.yml nav entry `Filesystem Bootstrapper: user-guide/filesystem-bootstrapper.md` after `Mailer Bootstrapper`
- Added `UPGRADE.md ## 0.3 → 0.4` section above existing `0.3.2 to 0.3.3` section — documents zero BC break, 3-step adoption path, inline `filesystem_config` migration SQL, `TenantFilesystemConfigTrait` recipe
- Full PHPUnit suite (674 tests) green through all 3 task commits; PHPStan level 9 clean; cs-fixer clean

## Task Commits

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | TenantUploadController + Twig template + saas demo flysystem wiring | `f89e53f` | `examples/saas/src/Controller/TenantUploadController.php`, `examples/saas/templates/upload/index.html.twig`, `examples/saas/config/packages/flysystem.yaml`, `examples/saas/config/services.yaml`, `examples/saas/config/packages/tenancy.yaml`, `examples/saas/composer.json` |
| 2 | docs/user-guide/filesystem-bootstrapper.md seed page + mkdocs.yml nav entry | `eefda0e` | `docs/user-guide/filesystem-bootstrapper.md`, `mkdocs.yml` |
| 3 | UPGRADE.md 0.3 → 0.4 section | `6de4c79` | `UPGRADE.md` |

## Files Created/Modified

### Created

- `examples/saas/src/Controller/TenantUploadController.php` — NEW. Final class, 65 lines. Two routes: GET `/uploads` (listContents + render) + POST `/uploads` (writeStream + redirect). Constructor: `FilesystemOperator $usersStorage` + `TenantContext $tenantContext`. Applies `basename()` sanitisation (demo-level path-traversal guard per T-24-09-01).
- `examples/saas/templates/upload/index.html.twig` — NEW. Extends `base.html.twig`. Displays tenant name/slug, uploaded file list (unconditional), and upload form targeting `tenant_upload_create` route.
- `examples/saas/config/packages/flysystem.yaml` — NEW. Defines `users.storage` with `local` adapter pointing to `%kernel.project_dir%/var/storage/users`.
- `docs/user-guide/filesystem-bootstrapper.md` — NEW. 8-section seed page, ~290 lines. Forward-references Phase 29 / DOC-20 for polish.

### Modified

- `examples/saas/config/services.yaml` — Added `users.storage` service entry with `tenancy.scoped` tag (`strategy: prefix, prefix_template: 'tenant_{slug}/'`). Added explanatory comments.
- `examples/saas/config/packages/tenancy.yaml` — Added `filesystem: { enabled: true }` under the `tenancy:` root.
- `examples/saas/composer.json` — Added `league/flysystem-bundle: ^3.7` and `league/flysystem-memory: ^3.31` to `require` (NOT `require-dev` — demo is the live-stack verification step).
- `mkdocs.yml` — Inserted `- Filesystem Bootstrapper: user-guide/filesystem-bootstrapper.md` after the Mailer Bootstrapper entry in the User Guide nav section.
- `UPGRADE.md` — Inserted `## 0.3 → 0.4` section at line 3 (above `## 0.3.2 to 0.3.3`), covering: no BC break, adoption path (install, tag, enable, optional migration), inline `ALTER TABLE` SQL snippet.

## Decisions Made

1. **`TenantUploadController` takes `TenantContext` as a constructor arg** even though the filesystem operations are fully transparent to tenant identity. The context is needed because the template renders `tenant.name` and `tenant.slug`. Not a deviation — matches the demo controller shape from `DemoMailController.php`.

2. **Twig template lists files unconditionally.** No `{% if tenant %}` guard around the file list — per Phase 23 INT-01 lesson (nested-branch rendering is the anti-pattern). The `{% else %}` on the `{% for %}` loop handles the empty-list case gracefully.

3. **`composer.lock` is gitignored.** Root `.gitignore` ignores `composer.lock` globally. Ran `composer update league/flysystem-bundle league/flysystem-memory --no-interaction` to update the demo's lock file locally; only `composer.json` is tracked. This is consistent with the existing demo setup.

4. **`flysystem.yaml` uses `adapter: local` + `options.directory`** (league/flysystem-bundle 3.x canonical config shape from RESEARCH.md §Code Examples). Not the `local:` shorthand, which is the Flysystem-bundle 2.x style.

5. **UPGRADE.md section uses `## 0.3 → 0.4`** (arrow notation consistent with the section title in the plan), placed above `## 0.3.2 to 0.3.3` at the top of the file.

## Deviations from Plan

None — plan executed exactly as written. All 3 tasks completed without rule-triggered auto-fixes.

The `composer validate` exit-code-2 warning about the lock file (before `composer update`) was addressed by running the targeted update, which is the natural resolution and was anticipated by the plan's "Add `composer update` to the demo's lock file" requirement.

## Threat Model Compliance

| Threat ID | Disposition | Coverage |
|-----------|-------------|----------|
| T-24-09-01 (Tampering — path traversal in demo upload) | mitigate | `TenantUploadController::create()` applies `basename($file->getClientOriginalName())` before `writeStream()`. Trust boundary documented in docs/user-guide/filesystem-bootstrapper.md §Trust boundary. |
| T-24-09-02 (Information Disclosure — slug in disk path) | accept | Inherent property of prefix mode. Documented prominently in docs §Overview and §Quick Start. |
| T-24-09-SC (Tampering — composer install of league packages) | mitigate | Packages verified APPROVED in RESEARCH.md §Package Legitimacy Audit (thephpleague org, MIT, 50M+/month on Packagist). |

## Known Stubs

None. All three tasks are fully implemented. The docs page notes "Phase 29 (DOC-20) will polish/expand" — this is intentional scope documentation, not a stub that prevents the plan's goal from being achieved.

## Threat Flags

None — no new network endpoints, auth paths, file access patterns, or schema changes at trust boundaries beyond those already captured in the plan's threat model.

## Self-Check: PASSED

- `[ -f examples/saas/src/Controller/TenantUploadController.php ]` → FOUND
- `[ -f examples/saas/templates/upload/index.html.twig ]` → FOUND
- `[ -f examples/saas/config/packages/flysystem.yaml ]` → FOUND
- `[ -f docs/user-guide/filesystem-bootstrapper.md ]` → FOUND
- `git log --oneline | grep f89e53f` → FOUND feat(24-09): add TenantUploadController
- `git log --oneline | grep eefda0e` → FOUND docs(24-09): add filesystem-bootstrapper.md seed page
- `git log --oneline | grep 6de4c79` → FOUND docs(24-09): add UPGRADE.md 0.3 → 0.4 section

---
*Phase: 24-filesystem-bootstrapper*
*Completed: 2026-06-03*
