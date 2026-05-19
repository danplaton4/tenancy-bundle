# Phase 11: Documentation Site — Research

**Researched:** 2026-04-13
**Domain:** MkDocs Material static site generation, GitHub Pages deployment, PHP library documentation patterns
**Confidence:** HIGH

## Summary

Phase 11 builds a documentation site for `danplaton4/tenancy-bundle` using MkDocs Material, deployed to GitHub Pages via GitHub Actions. The toolchain is mature and well-understood: MkDocs Material 9.7.6 is the current stable release (March 2026), all previously Insiders-only features are now freely available (as of v9.7.0, November 2025), and the project is entering maintenance mode. This is a safe window to adopt — the tool is feature-complete and stable, but active users should be aware of the eventual transition to Zensical (the successor project).

The documentation has three audience tracks (User Guide, Contributor Guide, Architecture Reference), best served by MkDocs Material's `navigation.tabs` feature — each tab maps to one audience, with secondary navigation rendered as sidebar sections within each tab. The `docs/` folder lives at the repo root alongside `mkdocs.yml`. GitHub Actions deploys using `mkdocs gh-deploy --force` with `contents: write` permission, pushing to the `gh-pages` branch.

The primary PHP-specific concern is syntax highlighting: Pygments (used by mkdocs-material) only highlights PHP correctly when `<?php` is present. The standard workaround is the `extend_pygments_lang` option in `pymdownx.highlight`, which registers a `php` alias with `startinline: True` — enabling clean PHP snippets without opening tags throughout the docs.

**Primary recommendation:** Use `pip install "mkdocs-material==9.7.6"` pinned in `docs/requirements.txt`, enable `navigation.tabs` for three-track layout, add `extend_pygments_lang` for PHP highlighting, deploy via a dedicated `docs.yml` GitHub Actions workflow (separate from the existing `ci.yml`), trigger on push to master and manual dispatch.

## Project Constraints (from CLAUDE.md)

No CONTEXT.md exists for Phase 11 — no locked decisions to carry forward.

Key CLAUDE.md constraints that affect documentation content decisions:

- **PHP 8.2+, strict_types everywhere** — all PHP code examples in docs must use `declare(strict_types=1)`
- **Doctrine dependencies are optional** — documentation must clearly distinguish optional (Doctrine, Messenger) from required features
- **strict_mode defaults to ON** — this is a security decision and must be prominently documented
- **TenantContext is zero-dependency** — architecture docs must explain why
- **Bootstrapper `clear()` runs in reverse order** — must be explicitly documented in architecture section
- **Integration tests use SQLite `:memory:`** — contributor guide must document this for local setup
- **CI matrix: PHP 8.2/8.3/8.4 x Symfony 7.4/8.0** — contributor guide must reference existing `ci.yml`

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| mkdocs-material | 9.7.6 | Static site generation with Material Design theme | The standard for Python/PHP OSS project docs; all Insiders features now free; search, tabs, admonitions, code highlighting built-in |
| mkdocs | (bundled with mkdocs-material) | Site generator core | Pulled in automatically by mkdocs-material install |
| Pygments | (bundled) | Server-side syntax highlighting | Included transitively; provides PHP, YAML, Bash highlighting |
| pymdownx | (bundled) | Markdown extensions (superfences, tabbed, snippets, highlight) | Included transitively; required for all code block features |

[VERIFIED: PyPI registry — mkdocs-material 9.7.6 released March 19, 2026]
[VERIFIED: squidfunk.github.io/mkdocs-material — all Insiders features included in 9.7.0+]

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| mkdocs-minify-plugin | latest | Minify HTML output | Optional: reduces transfer size; install with `pip install mkdocs-material[recommended]` or separately |
| mike | latest | Multi-version docs | Skip for v1.0 launch — adds complexity; add when v1.1 ships |

[VERIFIED: squidfunk.github.io/mkdocs-material — mkdocs-minify-plugin is NOT built-in, requires separate install via `mkdocs-material[recommended]` or `pip install mkdocs-minify-plugin`]
[ASSUMED: mike versioning is unnecessary for a v1.0 single-version launch — standard practice but not confirmed against this project's release cadence]

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| MkDocs Material | Docusaurus (React) | Docusaurus requires Node.js; adds JS toolchain to a PHP bundle repo; MkDocs requires only Python which is almost universally available in CI |
| MkDocs Material | Sphinx + RTD theme | Sphinx is RST-first; Markdown support is secondary; MkDocs Material has better UX for OSS bundles |
| MkDocs Material | GitBook | GitBook is paid/proprietary for advanced features; no local build |
| GitHub Pages | Netlify, Cloudflare Pages | GitHub Pages is free, zero-config for public repos, no additional accounts needed |

**Installation:**

```bash
pip install "mkdocs-material==9.7.6"
```

Or with optional minify:

```bash
pip install "mkdocs-material[recommended]==9.7.6"
```

**Version verification:** `pip show mkdocs-material` — confirmed 9.7.6 as of 2026-03-19 via PyPI.
[VERIFIED: pypi.org/project/mkdocs-material — 9.7.6 is latest stable as of March 2026]

## Architecture Patterns

### Recommended Project Structure

```
docs/
├── index.md                    # Landing page (hero, quick install, feature matrix)
├── requirements.txt            # Pinned: mkdocs-material==9.7.6
├── user-guide/
│   ├── index.md                # User guide overview
│   ├── installation.md         # composer require, Flex, manual registration
│   ├── getting-started.md      # 5-minute walkthrough end-to-end
│   ├── configuration.md        # Full tenancy.yaml reference (all keys)
│   ├── resolvers.md            # All 4 resolvers + custom resolver
│   ├── database-per-tenant.md  # DBAL wrapperClass driver
│   ├── shared-db.md            # SQL filter + #[TenantAware] attribute
│   ├── cache-isolation.md      # Cache bootstrapper namespace isolation
│   ├── messenger.md            # TenantStamp, sending + worker middleware
│   ├── cli-commands.md         # tenancy:migrate, tenancy:run
│   ├── testing.md              # InteractsWithTenancy trait usage
│   ├── strict-mode.md          # Why defaults on, how to disable, security implications
│   └── examples/
│       ├── saas-subdomain.md   # Full subdomain SaaS example
│       └── api-header.md       # X-Tenant-ID API example
├── contributor-guide/
│   ├── index.md                # Contributor guide overview
│   ├── setup.md                # Clone, composer install, test run
│   ├── architecture.md         # High-level event flow diagram
│   ├── test-infrastructure.md  # TestKernels, compiler passes, SQLite strategy
│   ├── coding-standards.md     # php-cs-fixer @Symfony, PHPStan level 9
│   ├── pr-workflow.md          # Fork, branch, PR checklist
│   ├── custom-resolver.md      # Implementing TenantResolverInterface
│   └── custom-bootstrapper.md  # Implementing TenantBootstrapperInterface
└── architecture/
    ├── index.md                # Architecture reference overview
    ├── event-lifecycle.md      # TenantResolved → TenantBootstrapped → TenantContextCleared
    ├── di-compilation.md       # Compiler passes, service tagging, container build
    ├── dbal-wrapper.md         # TenantConnection wrapperClass internals
    ├── sql-filter.md           # TenantAwareFilter Doctrine filter internals
    ├── messenger-lifecycle.md  # Stamp dispatch, worker middleware try/finally
    └── design-decisions.md     # Why strict_mode default ON, why zero-dep TenantContext, etc.

mkdocs.yml                      # Site configuration (repo root)
.github/workflows/docs.yml      # Separate docs deploy workflow
```

[VERIFIED: squidfunk.github.io/mkdocs-material — `docs/` at repo root is standard; `mkdocs.yml` at repo root]
[CITED: symfony.com/doc/current/bundles/best_practices.html — `docs/index.md` is mandatory per Symfony bundle best practices]

### Pattern 1: Three-Track Navigation with Tabs

**What:** Top-level nav sections map to audience tabs (User Guide, Contributor Guide, Architecture). Secondary sections become sidebar groups.
**When to use:** Whenever documentation has 2+ distinct audiences with different mental models.

```yaml
# Source: squidfunk.github.io/mkdocs-material/setup/setting-up-navigation/
theme:
  name: material
  features:
    - navigation.tabs
    - navigation.tabs.sticky
    - navigation.sections
    - navigation.indexes     # sections have their own index.md landing pages
    - navigation.top         # back-to-top button
    - navigation.path        # breadcrumbs
    - toc.follow             # auto-scroll TOC
    - search.highlight
    - search.suggest
    - content.code.copy
    - content.code.annotate
    - content.tabs.link      # linked content tabs (yaml/php tabs sync site-wide)
```

[VERIFIED: squidfunk.github.io/mkdocs-material/setup/setting-up-navigation/]

### Pattern 2: Full mkdocs.yml Configuration

```yaml
# Source: squidfunk.github.io/mkdocs-material — synthesized from official docs
site_name: Tenancy Bundle
site_url: https://danplaton4.github.io/tenancy-bundle/
site_description: Multi-tenancy for Symfony. Zero boilerplate, zero leaks.
site_author: Dan Platon
repo_name: danplaton4/tenancy-bundle
repo_url: https://github.com/danplaton4/tenancy-bundle
edit_uri: edit/master/docs/

theme:
  name: material
  palette:
    - scheme: default
      primary: indigo
      accent: indigo
      toggle:
        icon: material/brightness-7
        name: Switch to dark mode
    - scheme: slate
      primary: indigo
      accent: indigo
      toggle:
        icon: material/brightness-4
        name: Switch to light mode
  features:
    - navigation.tabs
    - navigation.tabs.sticky
    - navigation.sections
    - navigation.indexes
    - navigation.top
    - navigation.path
    - toc.follow
    - search.highlight
    - search.suggest
    - content.code.copy
    - content.code.annotate
    - content.tabs.link

plugins:
  - search
  # minify requires: pip install mkdocs-material[recommended]
  # - minify:
  #     minify_html: true

markdown_extensions:
  - admonition
  - pymdownx.details
  - pymdownx.superfences
  - pymdownx.tabbed:
      alternate_style: true
  - pymdownx.highlight:
      anchor_linenums: true
      line_spans: __span
      pygments_lang_class: true
      extend_pygments_lang:
        - name: php
          lang: php
          options:
            startinline: true
  - pymdownx.inlinehilite
  - pymdownx.snippets
  - pymdownx.emoji:
      emoji_index: !!python/name:material.extensions.emoji.twemoji
      emoji_generator: !!python/name:material.extensions.emoji.to_svg
  - attr_list
  - md_in_html
  - toc:
      permalink: true

nav:
  - Home: index.md
  - User Guide:
    - user-guide/index.md
    - Installation: user-guide/installation.md
    - Getting Started: user-guide/getting-started.md
    - Configuration Reference: user-guide/configuration.md
    - Resolvers: user-guide/resolvers.md
    - Database-per-Tenant: user-guide/database-per-tenant.md
    - Shared-DB Driver: user-guide/shared-db.md
    - Cache Isolation: user-guide/cache-isolation.md
    - Messenger Integration: user-guide/messenger.md
    - CLI Commands: user-guide/cli-commands.md
    - Testing: user-guide/testing.md
    - Strict Mode: user-guide/strict-mode.md
    - Examples:
      - SaaS Subdomain: user-guide/examples/saas-subdomain.md
      - API Header: user-guide/examples/api-header.md
  - Contributor Guide:
    - contributor-guide/index.md
    - Development Setup: contributor-guide/setup.md
    - Architecture Overview: contributor-guide/architecture.md
    - Test Infrastructure: contributor-guide/test-infrastructure.md
    - Coding Standards: contributor-guide/coding-standards.md
    - PR Workflow: contributor-guide/pr-workflow.md
    - Custom Resolver: contributor-guide/custom-resolver.md
    - Custom Bootstrapper: contributor-guide/custom-bootstrapper.md
  - Architecture Reference:
    - architecture/index.md
    - Event Lifecycle: architecture/event-lifecycle.md
    - DI Compilation Pipeline: architecture/di-compilation.md
    - DBAL Wrapper Mechanics: architecture/dbal-wrapper.md
    - SQL Filter Internals: architecture/sql-filter.md
    - Messenger Stamp Lifecycle: architecture/messenger-lifecycle.md
    - Design Decisions: architecture/design-decisions.md

extra:
  social:
    - icon: fontawesome/brands/github
      link: https://github.com/danplaton4/tenancy-bundle
```

[VERIFIED: squidfunk.github.io/mkdocs-material — all theme features, plugins, and markdown_extensions above are documented in official Material for MkDocs docs]

### Pattern 3: PHP Highlighting Without Opening Tags

**Problem:** Pygments requires `<?php` to recognize PHP code blocks. Documentation for a bundle shows class bodies, method signatures, and attributes — no opening tags.

**Solution:** `extend_pygments_lang` registers a custom alias with `startinline: True`.

```yaml
# In mkdocs.yml markdown_extensions:
- pymdownx.highlight:
    extend_pygments_lang:
      - name: php
        lang: php
        options:
          startinline: true
```

With this config, all ` ```php ` blocks in docs highlight correctly without `<?php`.

[VERIFIED: facelessuser.github.io/pymdown-extensions/extensions/highlight/ — extend_pygments_lang with startinline is the official workaround]
[VERIFIED: Multiple GitHub issues on squidfunk/mkdocs-material confirm this is the correct approach: #138, #1417, #2022, #4547]

### Pattern 4: GitHub Actions Docs Workflow

**What:** Separate `docs.yml` workflow (keep separate from `ci.yml` to avoid coupling test failures with doc deploys).

```yaml
# Source: squidfunk.github.io/mkdocs-material/publishing-your-site/
name: docs
on:
  push:
    branches: [master]
    paths:
      - 'docs/**'
      - 'mkdocs.yml'
  workflow_dispatch:

permissions:
  contents: write

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Configure Git Credentials
        run: |
          git config user.name github-actions[bot]
          git config user.email 41898282+github-actions[bot]@users.noreply.github.com

      - uses: actions/setup-python@v5
        with:
          python-version: '3.x'

      - run: echo "cache_id=$(date --utc '+%V')" >> $GITHUB_ENV

      - uses: actions/cache@v4
        with:
          key: mkdocs-material-${{ env.cache_id }}
          path: ~/.cache
          restore-keys: |
            mkdocs-material-

      - run: pip install -r docs/requirements.txt

      - run: mkdocs gh-deploy --force
```

`docs/requirements.txt`:
```
mkdocs-material==9.7.6
```

[VERIFIED: squidfunk.github.io/mkdocs-material/publishing-your-site/ — exact workflow from official docs with caching pattern]

### Pattern 5: Content Tabs for YAML/PHP Configuration Examples

**What:** Side-by-side tabs showing Symfony YAML config and the PHP equivalent — essential for configuration reference pages.

```markdown
=== "YAML"
    ```yaml
    tenancy:
        driver: database_per_tenant
        database:
            enabled: true
    ```

=== "PHP"
    ```php
    // config/packages/tenancy.php
    return static function (TenancyConfig $tenancy): void {
        $tenancy->driver('database_per_tenant');
        $tenancy->database()->enabled(true);
    };
    ```
```

Requires `pymdownx.tabbed` with `alternate_style: true` and `content.tabs.link` in theme features.
[VERIFIED: squidfunk.github.io/mkdocs-material/reference/content-tabs/]

### Pattern 6: Admonitions for Security/Strict Mode Callouts

**What:** Visual callout boxes for important warnings (data leaks, strict mode).

```markdown
!!! warning "strict_mode defaults to ON"
    Disabling strict mode means a `#[TenantAware]` entity queried without
    an active tenant will return all rows — a data leak. Only disable for
    trusted internal tooling.

!!! tip "First Request"
    The `kernel.request` listener fires at priority 20, before Security
    (priority 8) and after the Router (priority 32).
```

Requires `admonition` and `pymdownx.details` in `markdown_extensions`.
[VERIFIED: squidfunk.github.io/mkdocs-material/reference/admonitions/]

### Anti-Patterns to Avoid

- **Mixing `navigation.tabs` with `toc.integrate`:** Incompatible; sections cannot host TOC. Use `toc.follow` instead.
- **Using `navigation.indexes` without index.md files in each section directory:** The feature requires an `index.md` in each tab's top directory or the nav item is broken.
- **Installing `pip install mkdocs-material` without version pin:** In CI, an unpinned install picks up the next major version on release day. Pin to `==9.7.6` in `docs/requirements.txt`.
- **Putting PHP code without `<?php` and without the `extend_pygments_lang` fix:** Results in completely unhighlighted gray code blocks.
- **Adding the docs workflow to `ci.yml`:** Couples PHP test failures with docs deployment; use a separate `docs.yml` triggered only on docs-related file changes.
- **Enabling the `projects` or `social` plugin without understanding they need extra dependencies:** Social cards require `cairosvg` and `pillow`; skip for v1.0.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Site search | Custom search index or Algolia | MkDocs Material built-in search plugin | Client-side, offline, zero config, searches code blocks |
| Syntax highlighting | Custom highlight.js integration | Pygments via pymdownx.highlight | Bundled with mkdocs-material, server-side rendering, no JS required |
| Navigation tabs | Custom JS/CSS tab switcher | `navigation.tabs` theme feature | Built-in, mobile-responsive, sticky variant available |
| Content tabs (YAML/PHP) | Custom HTML | `pymdownx.tabbed` with `alternate_style: true` | Linked tabs sync across entire site |
| GitHub Pages deploy | Custom rsync/FTP script | `mkdocs gh-deploy --force` | Built-in command, handles branch push, tree management |
| Code copy button | Custom clipboard JS | `content.code.copy` theme feature | One-line config, Material Design UX |
| Dark mode | Custom CSS | `palette` with two schemes in mkdocs.yml | Built-in toggle, system preference detection |

**Key insight:** Every feature needed for this docs site is built into `mkdocs-material==9.7.6` (now that all Insiders features are free). The only exception is HTML minification, which requires `pip install mkdocs-material[recommended]` — and is optional for v1.0.

## Common Pitfalls

### Pitfall 1: PHP Code Without `<?php` Shows Gray/Unhighlighted

**What goes wrong:** All PHP code examples render as plain text — no keyword colors, no method highlighting.
**Why it happens:** Pygments' PHP lexer requires `<?php` to detect the language; without it, the lexer falls back to plain text. The standard `php` alias in Pygments does not set `startinline=True`.
**How to avoid:** Add `extend_pygments_lang` to `pymdownx.highlight` config in `mkdocs.yml` (see Pattern 3 above). This makes every ` ```php ` block start in inline mode.
**Warning signs:** After `mkdocs serve`, PHP code appears without any color in the preview.

[VERIFIED: github.com/squidfunk/mkdocs-material/issues/138 — long-standing known issue; extend_pygments_lang is the accepted workaround]

### Pitfall 2: `navigation.indexes` Requires `index.md` In Every Section

**What goes wrong:** Clicking a navigation tab or section header gives a 404 or navigates to the wrong page.
**Why it happens:** When `navigation.indexes` is enabled, MkDocs expects `index.md` to exist in each section directory that is listed as a bare section in the `nav` tree (e.g., `- User Guide: user-guide/index.md`).
**How to avoid:** Create `index.md` in `docs/user-guide/`, `docs/contributor-guide/`, and `docs/architecture/` as the first task in Wave 0.
**Warning signs:** `mkdocs build --strict` warns about missing index files.

[CITED: squidfunk.github.io/mkdocs-material/setup/setting-up-navigation/ — navigation.indexes documentation]

### Pitfall 3: GitHub Pages Source Branch Must Be Set to `gh-pages`

**What goes wrong:** Site deploys successfully (no workflow errors) but `https://danplaton4.github.io/tenancy-bundle/` shows "404 - There isn't a GitHub Pages site here."
**Why it happens:** The `gh-deploy` command pushes to the `gh-pages` branch, but GitHub Pages must be explicitly configured to serve from that branch under Settings › Pages.
**How to avoid:** After the first deployment, go to Settings › Pages and set Source to `gh-pages` branch, `/ (root)`.
**Warning signs:** Workflow succeeds but the URL is a 404. Green checkmark does not mean site is live.

[VERIFIED: squidfunk.github.io/mkdocs-material/publishing-your-site/ — explicit note that Pages source must be configured]

### Pitfall 4: `navigation.tabs` + `navigation.sections` Renders Differently at Different Viewports

**What goes wrong:** Documentation looks correct at desktop but navigation tabs collapse on mobile.
**Why it happens:** `navigation.tabs` only renders the tab bar on viewports above 1220px; on mobile, tabs fall back to standard sidebar navigation.
**How to avoid:** Test with browser devtools at mobile viewport. The behavior is correct and by design — just ensure the mobile nav structure is also logical.
**Warning signs:** Tabs disappear on mobile — expected behavior, not a bug.

[CITED: squidfunk.github.io/mkdocs-material/setup/setting-up-navigation/ — "rendered in a menu layer below the header for viewports above 1220px"]

### Pitfall 5: Docs Workflow Triggers on Every Push (Slow CI)

**What goes wrong:** Every commit to master triggers a docs rebuild and deploy, even for PHP-only changes.
**Why it happens:** Workflow `on: push: branches: [master]` without path filtering.
**How to avoid:** Add `paths:` filter to only trigger on `docs/**` or `mkdocs.yml` changes. Add `workflow_dispatch` for manual trigger.
**Warning signs:** Docs CI takes 60+ seconds on every PHP code change.

[VERIFIED: GitHub Actions documentation — `paths` filter is supported on `push` triggers]

### Pitfall 6: `mkdocs-material[recommended]` vs Bare Install

**What goes wrong:** `minify` plugin listed in `mkdocs.yml` but not installed → `ERROR - Config value 'plugins': The "minify" plugin is not installed`.
**Why it happens:** `minify` is not bundled with the base `mkdocs-material` package.
**How to avoid:** Either remove `minify` from `mkdocs.yml` for v1.0, or change `docs/requirements.txt` to `mkdocs-material[recommended]==9.7.6`.
**Warning signs:** `mkdocs build` exits with config error mentioning minify.

[VERIFIED: github.com/squidfunk/mkdocs-material/discussions/7485]

## Code Examples

Verified patterns from official sources:

### Minimal Working mkdocs.yml (Wave 0 Baseline)

```yaml
# Source: squidfunk.github.io/mkdocs-material/creating-your-site/
site_name: Tenancy Bundle
site_url: https://danplaton4.github.io/tenancy-bundle/
repo_url: https://github.com/danplaton4/tenancy-bundle

theme:
  name: material
  features:
    - navigation.tabs
    - content.code.copy

markdown_extensions:
  - admonition
  - pymdownx.superfences
  - pymdownx.highlight:
      extend_pygments_lang:
        - name: php
          lang: php
          options:
            startinline: true

plugins:
  - search
```

### Admonition (Security Warning)

```markdown
!!! danger "Data Leak Risk"
    When `strict_mode: false`, a `#[TenantAware]` entity repository
    returns ALL tenant rows when no tenant context is active.
    The default is `true` for this reason.
```

### Code Block with Title and Line Highlights

```markdown
```php title="src/Entity/Invoice.php" hl_lines="3 4"
#[ORM\Entity]
#[TenantAware]  // (1)!
class Invoice
{
    // Doctrine SQL filter appends `WHERE tenant_id = :id` automatically
}
```

1. Mark any Doctrine entity for automatic tenant scoping.
```

### Content Tabs (YAML/PHP Config)

```markdown
=== "tenancy.yaml"
    ```yaml
    tenancy:
        shared_db:
            enabled: true
            strict_mode: true  # default
    ```

=== "tenancy.php"
    ```php
    return static function (TenancyConfig $tenancy): void {
        $tenancy->sharedDb()->enabled(true)->strictMode(true);
    };
    ```
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Insiders-only: social cards, blog, tags | All features free in base package | Nov 2025 (v9.7.0) | No sponsorship required; full feature set for free |
| `pip install mkdocs-material` gets latest | Pin version in `docs/requirements.txt` | Best practice formalized ~2024 | Reproducible CI builds |
| `navigation.indexes` needed external plugin `mkdocs-section-index` | Built into Material as `navigation.indexes` feature flag | Material v8+ | No extra plugin needed |
| PHP highlighting required `<?php` | `extend_pygments_lang` with `startinline: true` | Workaround available since ~2019 | Clean PHP snippets in docs |
| Material for MkDocs = actively developed | Maintenance mode only (12 months) | Nov 2025 | No new features; critical bug fixes only; successor is Zensical |

**Deprecated/outdated:**
- Insiders sponsorship model: discontinued as of Nov 2025; all features freely available
- `mkdocs-section-index` third-party plugin: superseded by native `navigation.indexes` feature flag
- Alternative qualifier types for admonitions (e.g. `summary`, `tldr`): deprecated in v9, removed in v10

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Versioning with `mike` is unnecessary for v1.0 launch | Standard Stack (Supporting) | Low — versioning is addable later; skipping it saves complexity |
| A2 | `docs/` at repo root is preferred over a `docs-site/` subdirectory | Architecture Patterns | Low — both work; standard convention is `docs/` at root |
| A3 | Separate `docs.yml` workflow is preferable to adding docs deploy to `ci.yml` | Pattern 4 | Low — coupling would only slow CI, not break it |
| A4 | Social card generation (requires `cairosvg`, `pillow`) should be skipped for v1.0 | mkdocs.yml config | Low — social cards are cosmetic; can be added later |

## Open Questions

1. **GitHub Pages site URL**
   - What we know: `repo_url` is `https://github.com/danplaton4/tenancy-bundle`
   - What's unclear: Whether the GitHub Pages site URL will be `danplaton4.github.io/tenancy-bundle` or a custom domain — `site_url` in mkdocs.yml must match exactly for canonical URLs
   - Recommendation: Use `https://danplaton4.github.io/tenancy-bundle/` as the default; update if a custom domain is configured later

2. **Code snippets vs. inline docs — staying in sync**
   - What we know: `pymdownx.snippets` can embed content from source files via `--8<--` syntax, keeping code examples in sync with actual source
   - What's unclear: Whether the plan should use `--8<--` to pull real code from `src/` into docs, or write independent examples
   - Recommendation: For critical examples (TenantAwareFilter, TenantConnection, InteractsWithTenancy), use `pymdownx.snippets` to embed actual source; for configuration YAML, write inline

3. **`edit_uri` accuracy**
   - What we know: `edit_uri: edit/master/docs/` adds "Edit this page" links pointing to GitHub
   - What's unclear: Whether the default branch is `master` or something else
   - Recommendation: Confirm from `composer.json` (it shows `master`) — use `edit/master/docs/`
   [VERIFIED: .github/workflows/ci.yml — `push: branches: [master]` confirms master is the default branch]

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| Python 3 | mkdocs-material install | Available (local: 3.9.6) | 3.9.6 locally; `3.x` in CI via `actions/setup-python@v5` | — |
| pip | mkdocs-material install | Available | 24.2 | — |
| mkdocs-material | Docs build | Not installed locally | — (installs via `pip install`) | Install as part of Wave 0 |
| mkdocs CLI | `mkdocs serve`, `mkdocs build` | Not installed locally | — (pulled in by mkdocs-material) | — |
| GitHub Actions | docs.yml workflow | Available | CI is already in use (`ci.yml`) | — |
| gh-pages branch | GitHub Pages serving | Does not exist yet | — | Created automatically by `mkdocs gh-deploy --force` on first run |

**Missing dependencies with no fallback:**
- None — all deps install via pip as part of the docs setup task

**Missing dependencies with fallback:**
- mkdocs-material is not installed locally, but that is expected — install via `pip install -r docs/requirements.txt` as documented

## Validation Architecture

The docs phase produces no PHP code — no PHPUnit tests apply. Validation uses a different approach:

### Doc-Build Validation

| Check | Command | What It Catches |
|-------|---------|-----------------|
| Build without errors | `mkdocs build --strict` | Broken internal links, missing nav files, YAML config errors |
| Local preview | `mkdocs serve` | Rendering issues, navigation problems, highlighting failures |
| PHP highlighting | Visual check in browser | `extend_pygments_lang` working correctly |
| GitHub Actions | Push to master (docs path) | End-to-end deploy to gh-pages |

### Wave 0 Gaps (Files That Must Exist Before Content Can Be Added)

- [ ] `mkdocs.yml` at repo root
- [ ] `docs/requirements.txt` pinning `mkdocs-material==9.7.6`
- [ ] `docs/index.md` (landing page)
- [ ] `docs/user-guide/index.md`
- [ ] `docs/contributor-guide/index.md`
- [ ] `docs/architecture/index.md`
- [ ] `.github/workflows/docs.yml`

All other `.md` files can be stubbed (one-liner placeholder) and filled in per plan.

**Quick run command:** `mkdocs build --strict`
**Preview command:** `mkdocs serve --dirtyreload`

## Security Domain

This phase creates static Markdown documentation and a GitHub Actions workflow. No application code, no auth, no data handling.

**Applicable ASVS categories:** None — static site generation with no user input, no auth, no data storage.

**One security-adjacent concern:** The `docs.yml` workflow uses `permissions: contents: write` to push to `gh-pages`. This is the minimal required permission for `mkdocs gh-deploy`. The scope is limited to the repository itself.

[VERIFIED: squidfunk.github.io/mkdocs-material/publishing-your-site/ — `contents: write` is the documented minimum permission]

## Sources

### Primary (HIGH confidence)

- `squidfunk.github.io/mkdocs-material` — main features, installation, version confirmation
- `squidfunk.github.io/mkdocs-material/setup/setting-up-navigation/` — navigation.tabs, sections, indexes, all feature flags
- `squidfunk.github.io/mkdocs-material/publishing-your-site/` — exact GitHub Actions workflow YAML, permissions, caching
- `squidfunk.github.io/mkdocs-material/reference/content-tabs/` — pymdownx.tabbed configuration
- `squidfunk.github.io/mkdocs-material/reference/code-blocks/` — code highlighting config, line numbers
- `squidfunk.github.io/mkdocs-material/reference/admonitions/` — admonition types and syntax
- `squidfunk.github.io/mkdocs-material/plugins/` — built-in plugins list (search, blog, tags, optimize, social, privacy)
- `squidfunk.github.io/mkdocs-material/blog/2025/11/11/insiders-now-free-for-everyone/` — all Insiders features now free in v9.7.0
- `facelessuser.github.io/pymdown-extensions/extensions/highlight/` — extend_pygments_lang with startinline for PHP
- `pypi.org/project/mkdocs-material/` — version 9.7.6, release date March 19, 2026
- `github.com/squidfunk/mkdocs-material` — confirmed v9.7.6 as latest release
- `symfony.com/doc/current/bundles/best_practices.html` — docs/index.md as mandatory bundle file

### Secondary (MEDIUM confidence)

- `github.com/squidfunk/mkdocs-material/issues/138` (and #1417, #2022, #4547) — PHP highlighting issue and extend_pygments_lang workaround verified as accepted solution
- `github.com/squidfunk/mkdocs-material/discussions/7485` — minify plugin not built-in, requires separate install
- `squidfunk.github.io/mkdocs-material/setup/setting-up-versioning/` — mike versioning not Insiders-only; not needed for v1.0

### Tertiary (LOW confidence)

- None — all critical claims verified via official sources

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — versions verified against PyPI; Insiders-free status verified against official blog post
- Architecture (mkdocs.yml patterns): HIGH — all features verified against official MkDocs Material docs
- PHP highlighting workaround: HIGH — verified against pymdownx official docs and multiple GitHub issues confirming the accepted solution
- Pitfalls: HIGH — verified against official docs and GitHub issues
- Navigation pattern for three-track docs: MEDIUM — `navigation.tabs` is verified; the specific file structure is a recommendation based on the bundle's content domain [ASSUMED: A2, A3]

**Research date:** 2026-04-13
**Valid until:** 2026-07-13 (stable domain — MkDocs Material is in maintenance mode, no breaking changes expected)
