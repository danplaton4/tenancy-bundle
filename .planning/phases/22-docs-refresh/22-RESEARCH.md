# Phase 22: Docs Refresh — Research

**Researched:** 2026-05-28
**Domain:** Documentation (MkDocs Material, Bash/awk, composer.json edits)
**Confidence:** HIGH — every claim below was verified against the live repo on the master branch

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Profiler tab visualization (SC2):**
- **D-01:** `docs/user-guide/profiler-tab.md` shows the WDT panel as ASCII/code-block renders + prose describing each field + a "see it live in the demo" link to `examples/saas/README.md`. No PNGs, no SVGs, no Mermaid.
- **D-02:** Cover all three panel states the page already promises: resolved tenant, no-tenant, error. Each ASCII block ~10 lines, total ~40 lines.

**Roadmap mirror (SC4) — inverted direction:**
- **D-03:** `docs/roadmap.md` becomes the **canonical** roadmap.
- **D-04:** Repo-root `ROADMAP.md` becomes a thin pointer (~5 lines).
- **D-05:** README.md updates the `## Roadmap` section to link to the docs-site URL directly.

**Demo walkthrough (SC3):**
- **D-06:** New page lives at `docs/examples/saas-demo.md` — NEW top-level dir.
- **D-07:** `docs/user-guide/examples/` (api-header.md, saas-subdomain.md) moves up to `docs/examples/`.
- **D-08:** `docs/examples/saas-demo.md` is thin — intro + link to canonical `examples/saas/README.md`.

**Installation page + dep tree (SC1 + scope expansion 1):**
- **D-09:** Add `nikic/php-parser: ^5.0` to `composer.json` `require`. Keep in `require-dev` (existing version constraint stays — already `^5.0`).
- **D-10:** Drop `nikic/php-parser` from `composer.json` `suggest`.
- **D-11:** `installation.md` becomes one-command — `composer require` + `tenancy:install`. Zero references to manually editing `bundles.php`.
- **D-12:** Manual install instructions: removed entirely from `installation.md`.
- **D-13:** `UPGRADE.md` gets `## 0.3.2 to 0.3.3` section.

**Mailer page (SC2):**
- **D-14:** New `docs/user-guide/mailer-bootstrapper.md` covers (a) per-tenant DSN/From/ReplyTo, (b) X-Transport strategy (sync + async safe), (c) async failure-mode warning, (d) migration recipe.

**docs-lint rule (SC6):**
- **D-15:** Extend `scripts/docs-lint.sh` with `bundles.php` regression check using awk-based section filter.

**Yellow page refresh — scope expansion 2 (surgical additions):**
- **D-17 (`getting-started.md`):** Three short subsections (Origin / Profiler / Mailer teasers).
- **D-18 (`configuration.md`):** Add `origin.allow_list` config block + per-tenant mailer config notes.
- **D-19 (`resolvers.md`):** Add OriginHeaderResolver as 5th built-in resolver (priority 25). Update "4 built-in resolvers" intro to say 5.
- **D-20 (`cli-commands.md`):** Promote `tenancy:install` to top; demote `tenancy:init` to sub-section.

**mkdocs nav reorganization:**
- **D-16:** Add `profiler-tab.md`, `origin-header-resolver.md`, `mailer-bootstrapper.md` to User Guide; new top-level "Examples" section; new top-level "Roadmap" entry.

### Claude's Discretion
- Exact prose of each new docs page.
- Whether ASCII profiler-tab renders are stacked vertically or side-by-side in `pymdownx.tabbed` blocks.
- nikic footnote on `installation.md` — silent vs one-line callout.

### Deferred Ideas (OUT OF SCOPE)
- Per-resolver subpage refactor under a "Resolvers" nav group.
- Documentation versioning (mike, mkdocs-versioning).
- Symfony Flex recipe.
- `tenancy:install` shell-out to `composer require nikic/php-parser` (D-09 supersedes — nikic is always present after v0.3.3).
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| DOC-19 | Documentation reflects everything v0.3 ships. Install page replaces "manually add to `bundles.php`" with `tenancy:install`; new pages for `OriginHeaderResolver`, Profiler tab, and Mailer bootstrapper; demo walkthrough; public roadmap page on the docs site. | This research surveys every existing file the planner must edit (installation.md L17, profiler-tab.md, resolvers.md, configuration.md, cli-commands.md, getting-started.md, UPGRADE.md, composer.json, mkdocs.yml, scripts/docs-lint.sh) and provides exact insertion points, current shapes, and source-of-truth field names so each PLAN.md task is concretely actionable. The `## File Shape Survey` below directly maps DOC-19's 8 acceptance bullets to source files and line ranges. |
</phase_requirements>

## Summary

Phase 22 is a docs-only catch-up phase. Every claim below was verified against repo files on master (commit `c4269e1`, 2026-05-28). The phase touches **13 files** across `docs/`, repo root, and `scripts/`. There are zero new code paths and zero runtime behavior changes — the only non-docs change is moving `nikic/php-parser` from `composer.json#suggest` (line 53) to `require` (after line 28-29).

**Most important finding:** `.github/workflows/docs.yml` line 39 runs `mkdocs build --strict`. Any nav reorganization (D-07/D-16) that produces a broken cross-link will fail CI. The four cross-references to `user-guide/examples/*.md` (in `shared-db.md`, `user-guide/index.md` × 2, `database-per-tenant.md`) MUST be rewritten when those files move to `docs/examples/`.

**Second-most important:** The Profiler ASCII renders are tightly constrained by what `src/Resources/views/Collector/tenant.html.twig` actually shows. Drift between docs and template is a real risk — the field names in §"Profiler ASCII Source-of-Truth" below are extracted line-by-line from the template's `{% block panel %}`.

**Primary recommendation:** The planner can produce 4-6 plan files: (1) composer.json + UPGRADE.md edit, (2) docs-lint.sh awk extension, (3) new pages (mailer-bootstrapper.md, saas-demo.md, roadmap.md), (4) profiler-tab.md ASCII additions, (5) yellow-page surgical edits (getting-started/configuration/resolvers/cli-commands), (6) mkdocs.yml nav reorg + cross-link fixes + ROADMAP.md slimming + README.md roadmap link + installation.md rewrite. All five pillars of SC1-SC6 are well-scoped.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Public docs (rendered HTML on GitHub Pages) | MkDocs Material build pipeline | `mkdocs build --strict` in CI | Single source = `docs/` markdown; `mkdocs.yml` declares nav; strict-mode build enforces no broken links |
| Install regression guard | Bash script `scripts/docs-lint.sh` | CI workflow | Runs as part of CI, fails build on stale-pattern hit (existing 5 checks + 1 new) |
| Repo-root discoverability | `README.md` + `ROADMAP.md` + `UPGRADE.md` (GitHub UI) | docs-site URLs | Repo-root files are what GitHub renders on the project page; they POINT to canonical docs |
| Package metadata | `composer.json` (Packagist) | — | One file edit moves nikic suggest→require |
| Source-of-truth for Profiler fields | `src/Profiler/TenantDataCollector.php` + `src/Resources/views/Collector/tenant.html.twig` | docs/user-guide/profiler-tab.md mirrors | Code generates the fields, docs render the ASCII reproduction |

## File Shape Survey

Concrete current state of every file the planner edits. Every line number was verified directly against the file in this session.

### 1. `src/Profiler/TenantDataCollector.php` (178 lines)

Source of truth for the 8-key `$this->data` shape that drives the Twig template. The collector emits a flat array keyed on:

| Key | Type | Populated when state == | Source |
|-----|------|-------------------------|--------|
| `state` | `'resolved' \| 'null' \| 'error'` | always | computed from `tenantContext->getTenant()` + `stash->getCapturedException()` (lines 60-68) |
| `slug` | `?string` | `resolved` | `$tenant?->getSlug()` (line 83) |
| `tenant_label` | `?string` | `resolved` | `$tenant?->getName()` (line 84) |
| `driver` | `'database_per_tenant' \| 'shared_db'` | always | constructor param (validated against `KNOWN_DRIVERS` on line 43) |
| `connection_name` | `?string` | always (label-only) | `match` on driver: `'tenant'` for db-per-tenant, `$landlordConnection` for shared (lines 70-74) |
| `resolved_by` | `?string` | `resolved` (FQCN) | `$stash->getResolvedBy()` (line 87) — populated by `TenantProfilerStash::onTenantResolved` |
| `bootstrappers` | `string[]` | `resolved` | `$stash->getBootstrapperFqcns()` (line 88) |
| `error` | `?array{class:string, message:string}` | `error` | `$stash->getCapturedException()` (line 89) — only tenancy-namespaced exceptions captured |

**Optional 10-key `mailer` sub-array** is appended when `LruTransportCache` is wired (line 94-96, populated by `collectMailerState()` lines 145-177): `from`, `reply_to`, `dsn_redacted`, `cache_size`, `cache_max`, `cache_hits`, `cache_evictions`, `strategy` (always `'x_transport'`), `async_detected`, `badge` (`'OK' \| 'MISSING'`).

### 2. `src/Profiler/TenantProfilerStash.php` (95 lines)

Event subscriber that captures three signals: `TenantResolved::resolvedBy` (line 42), `TenantBootstrapped::bootstrappers` (line 47), tenancy-namespaced exceptions (lines 55-65 — only classes prefixed `Tenancy\\Bundle\\Exception\\`). Resets on `TenantContextCleared` (line 52) and implements `ResetInterface` (line 28) for long-running runtimes.

### 3. `src/Resources/views/Collector/tenant.html.twig` (164 lines)

Renders three blocks: `toolbar`, `menu`, `panel`. The `panel` block (lines 67-164) is the source of truth for what each state actually displays. Critical structure:

**Resolved state** (lines 70-150) — `.metrics` grid with 4 cells (Slug, Tenant, Driver, Connection) → `<h3>Resolved by</h3>` → `<code>{{ resolved_by }}</code>` → `<h3>Bootstrappers (N)</h3>` → `<ul><li><code>FQCN</code></li>...` → optional Mailer subsection (lines 108-149) gated on `{% if collector.data.mailer is defined %}`.

**Error state** (lines 151-156) — `<h3>Resolution error</h3>` → `<p><strong>{{ error.class }}</strong></p><p>{{ error.message }}</p>`.

**Null state** (lines 158-162) — Two-line empty panel: "No tenant resolved for this request." / "This is the expected state for public, landlord, and health-check routes."

**Toolbar** (lines 3-55) — badge shows tenant slug when resolved, `⚠` on error, `—` on null. Status pill colors: red/yellow/green.

### 4. `scripts/docs-lint.sh` (48 lines)

Existing structure:
- Lines 18-19: `set -euo pipefail; EXIT=0`
- Lines 22-33: `check()` function. Signature: `check <pattern> <description> <targets...>`. Body invokes `grep -rnE --color=auto -- "$pattern" "${targets[@]}"` and on any match: echoes "ERROR: $desc" and sets `EXIT=1`.
- Line 36: `TARGETS=(docs/ src/Command/TenantInitCommand.php)`
- Lines 38-42: 5 existing `check` calls — `wrapperClass`, `wrapper_class`, `ReflectionProperty`, `TenantConnection`, `sqlite://`.
- Lines 44-46: success message gate.
- Line 48: `exit $EXIT`.

CHANGELOG.md and UPGRADE.md are **deliberately not scanned** (commented at lines 12-14) so legitimate references in migration recipes survive.

### 5. `composer.json` (76 lines)

Verified content:
- `require` block: lines 20-30. Contains 8 entries (php, 7 symfony packages). **No nikic entry currently.**
- `require-dev` block: lines 31-47. Contains 16 entries including `"nikic/php-parser": "^5.0"` at line 37 (alphabetically sorted between `friendsofphp/php-cs-fixer` line 36 and `phpstan/phpstan` line 38). **D-09 keeps this line as-is.**
- `suggest` block: lines 48-57. Contains 8 entries. **nikic/php-parser at line 53:**
  ```
  "nikic/php-parser": "Required to run bin/console tenancy:install (one-shot installer; not needed at runtime)",
  ```
  D-10 deletes this single line. Block has trailing-comma considerations: line 53 ends with `,`; line 52 (`doctrine/migrations`) also ends with `,`; line 54 (`symfony/mailer`) follows. Removal of line 53 leaves the comma chain intact.
- `config: { sort-packages: true }` on line 74 — composer will re-sort on next `composer update`, but a manual JSON edit must preserve alphabetical order on `require`. The nikic line goes between `php` (line 21) and `symfony/cache` (line 22): correct alphabetical position is **immediately after line 21** (`n` < `s`).

### 6. `UPGRADE.md` (317 lines)

Heading structure verified:
- Line 1: `# Upgrade Guide`
- Line 3: `## 0.3.1 to 0.3.2` — most recent section, covers AbstractTenant split + demo boot fixes
- Line 73: `## 0.2 to 0.3` — covers Mailer BC break (TenantInterface 3 new methods, trait migration path A/B, SQL snippet)
- Line 175: `## 0.1 to 0.2` — covers Phase 15 architectural fixes
- Line 279: `## Upgrading to 0.1` — initial requirements

**SC5 verification:** the 0.2→0.3 Mailer BC break wording IS final and complete. Lines 73-173 cover the BC break (3 new abstract methods on TenantInterface), Migration path A (trait), Migration path B (manual), raw SQL ALTER snippet, and a `coming in the v0.3 docs refresh` reference to `docs/user-guide/mailer-bootstrapper.md` (which Phase 22 D-14 creates — closes that loop). One small wording tweak the planner may want to apply: line 173 currently says `(coming in the v0.3 docs refresh)`. After Phase 22 ships, this becomes simply `(docs/user-guide/mailer-bootstrapper.md)` or it can be left as historical context. Either is fine.

**D-13 insertion point:** new `## 0.3.2 to 0.3.3` section goes **between line 2 (blank after `# Upgrade Guide`) and line 3 (`## 0.3.1 to 0.3.2`)**. The pattern in this file is reverse-chronological (newest first), so the new section goes at the TOP. The planner inserts the new content starting at what will become line 3, pushing the existing 0.3.1→0.3.2 section down.

### 7. `mkdocs.yml` (106 lines)

Current `nav:` block: lines 66-100. Structure:
- Home: `index.md`
- User Guide (lines 68-83): 12 entries — `user-guide/index.md`, Installation, Getting Started, Configuration Reference, Resolvers, Database-per-Tenant, Shared-DB Driver, Cache Isolation, Messenger Integration, CLI Commands, Testing, Strict Mode, **and a nested `Examples:` sub-section at lines 81-83 with 2 entries** (SaaS Subdomain, API Header).
- Contributor Guide (lines 84-92): 8 entries.
- Architecture Reference (lines 93-100): 7 entries.

**Critical gaps (D-16):**
- `user-guide/profiler-tab.md` exists in the repo but is NOT in nav.
- `user-guide/origin-header-resolver.md` exists but is NOT in nav.
- `user-guide/mailer-bootstrapper.md` does NOT exist yet and is NOT in nav.

**markdown_extensions** (lines 41-64) confirms `pymdownx.superfences`, `pymdownx.tabbed` (with `alternate_style: true`), and `attr_list` are enabled. Tabbed code blocks for the 3-state ASCII renders are supported.

### 8. `docs/user-guide/origin-header-resolver.md` (151 lines)

**SC2 verification:** The "Trust Model" section IS present (line 52: `## Trust Model`). It has 4 sub-headings: `### Where the Origin header comes from` (line 56), `### Where the trust ends` (line 60), `### Failure-safe by default` (line 70), `### A note on http://` (line 77).

**Anchor for D-19 cross-link:** the planner should link from `resolvers.md`'s OriginHeaderResolver section to **`origin-header-resolver.md#trust-model`** (the H2 anchor).

**Configuration syntax to mirror in D-18:** lines 19-35 of `origin-header-resolver.md` show the canonical `tenancy.origin.allow_list` YAML shape (mixed map-form + wildcard-shorthand entries). `configuration.md` should mirror this exact YAML or risk drift.

### 9. `docs/user-guide/profiler-tab.md` (155 lines)

Already has solid prose:
- L1-7: H1 + intro
- L9-61: `## Do I have to do anything?` section with sub-sections
- L65-108: `## What will I see?` — already has prose for each of the 3 states AND one minimal text-only "Example panel content" block at lines 79-90 (resolved state, 11 lines). **The other two states (null, error) currently have NO ASCII renders.**
- L112-117: `## Privacy and safety`
- L120-141: `## Troubleshooting`
- L145-155: `## Internals (for the curious)`

**Where the 3 ASCII renders go (D-01/D-02):** the existing minimal block at lines 79-90 should be expanded; matching blocks should be added under the null state (currently lines 92-98) and error state (currently lines 100-108). All three blocks have prose ABOVE them. The planner inserts the ASCII content immediately after each state's bullet list of "Toolbar badge / Status pill / Panel sections" prose.

**bundles.php reference inside this file (lines 22, 35, 125, 135):** these are inside the `## Do I have to do anything?` / Troubleshooting sections — they document the manual web-profiler-bundle install path, NOT the tenancy install path. D-15 must NOT flag these. See §"docs-lint awk Pattern" below for how the awk filter handles this.

### 10. `docs/user-guide/getting-started.md` (264 lines)

H2 headings: `## Prerequisites` (line 5), `## Choose Your Driver` (line 13), `## Path A: Database-per-Tenant` (line 25), `## Path B: Shared-DB` (line 149), `## What Happens on Every Request` (line 220), `## Next Steps` (line 258).

**D-17 insertion point:** the three new subsections (Origin teaser, Profiler teaser, Mailer teaser) fit cleanly **between line 254 (the closing `\`\`\`` of the kernel-flow code block) and line 256 (the `---` rule that separates from `## Next Steps`)**. A new `## Beyond the basics` (or similar discretion) H2 hosts the three sub-sections. Existing install-and-config flow at L25-218 stays intact.

### 11. `docs/user-guide/configuration.md` (261 lines)

H2/H3 structure:
- L1-3: intro
- L5: `## Config Keys` → 8 H3 entries: `### tenancy.driver` (L9), `### tenancy.strict_mode` (L22), `### tenancy.landlord_connection` (L36), `### tenancy.tenant_entity_class` (L46), `### tenancy.cache_prefix_separator` (L69), `### tenancy.database.enabled` (L79), `### tenancy.resolvers` (L96), `### tenancy.host.app_domain` (L115)
- L129: `## Validation Rules`
- L145: `## Full Example`
- L192: `## Minimal Examples`

**D-18 insertion points:**
1. New `### tenancy.origin.allow_list` H3 — insert **between line 127 (end of `### tenancy.host.app_domain` block) and line 129 (`## Validation Rules` H2)**. This keeps all H3 config-key entries grouped together.
2. The resolvers table at line 102-110 currently lists 4 aliases (host/header/query_param/console). D-18 should add a 5th row for `origin` with priority 25.
3. Per-tenant mailer config notes: add a new H3 `### tenancy.mailer.async` (mirroring `MailerTransportContractPass` line 36-37 — accepts `'auto' | 'true' | 'false'`) inside `## Config Keys`. The three nullable Tenant columns (`mailerDsn`, `mailerFrom`, `mailerReplyTo`) and `TenantMailerConfigTrait` shortcut belong in a **separate `### Per-tenant mailer config` sub-section** — placement is discretionary, but immediately after the new `origin.allow_list` block is clean.

### 12. `docs/user-guide/resolvers.md` (292 lines)

H2 structure: `## Overview` (L7) → `## Resolver Priority Table` (L11) → `## Exception Behavior` (L25) → `## HostResolver` (L33) → `## HeaderResolver` (L66) → `## QueryParamResolver` (L91) → `## ConsoleResolver` (L122) → `## Enabling and Disabling Resolvers` (L161) → `## Custom Resolver` (L195) → `## Deep Dive` (L289).

**D-19 changes (3 surgical edits):**
1. Line 2 (intro): `"four resolvers"` → `"five resolvers"`. **Verified:** line 2 currently reads "The bundle ships with **four resolvers** and supports unlimited custom resolvers". Note: contains `**four resolvers**` markdown bold — the exact replacement is `**four resolvers**` → `**five resolvers**`.
2. Resolver Priority Table at L14-22: insert a new row for `OriginHeaderResolver` between the `HostResolver` row (L17, priority 30) and the `HeaderResolver` row (L18, priority 20). New row: `| OriginHeaderResolver | 25 | Header: Origin (browser-locked) | tenancy.origin.allow_list |`.
3. New `## OriginHeaderResolver` H2 section between line 64 (end of `## HostResolver`'s "When app_domain is null" subsection) and line 66 (start of `## HeaderResolver`). Content: priority 25, trust-model summary (1 paragraph, NOT duplicating the full content from origin-header-resolver.md), allow-list requirement (1 sentence), cross-link `[Full Trust Model →](origin-header-resolver.md#trust-model)`.

### 13. `docs/user-guide/cli-commands.md` (191 lines)

H2 structure: `## tenancy:init` (L7) → `## tenancy:migrate` (L74) → `## tenancy:run` (L135) → `## See Also` (L185).

**D-20 changes:**
1. Update line 3-5 intro: currently says "three console commands: `tenancy:init` for scaffolding configuration, `tenancy:migrate` for ... and `tenancy:run` ...". Rewrite to mention **four commands** (or "the one-shot setup command plus three subcommands") and list `tenancy:install` as the headline.
2. Insert new `## tenancy:install` H2 **between line 5 (end of intro) and line 7 (`## tenancy:init` H2)**. Content per CONTEXT.md: one-command-setup path, `--dry-run` flag, `--force` flag.
3. Demote `tenancy:init` from H2 (line 7) to H3 (`### tenancy:init`) under the new install section as a sub-section "Manual config scaffold". The current L7 through L72 stays intact in content but moves under the new H2 as an indented sub-section.
4. **Do NOT modify** the `tenancy:migrate` (L74-133) or `tenancy:run` (L135-183) sections per D-20.

### 14. `docs/index.md` (137 lines)

H2 sections: `## Quick Start` (L14), `## Features` (L66), `## How It Works` (L82), `## Comparison` (L114), `## Requirements` (L132). No Roadmap link currently.

**D-05 implication:** the index already says "Register the bundle in `config/bundles.php`" (line 22) and "run `bin/console tenancy:init`" — this needs to be rewritten to the one-command `tenancy:install` path, or the line needs to be soft-linked to the new install page. Strict-mode build implications: any change to L22 must keep the inline code formatting valid (no missing backticks).

Roadmap nav link addition: D-16 adds a top-level Roadmap entry — index.md may want a button link or paragraph reference too (discretion).

### 15. `ROADMAP.md` (repo root, 46 lines)

Currently a full roadmap with sections: `## Shipped` (L5), `## In progress — closing v0.3` (L13), `## Next — v0.4 Storage & shared entities` (L17), `## Planned` (L23), `## Future — by demand` (L32), `## Want something here?` (L43).

**D-04 slimming:** keep `# Roadmap` heading + 1-sentence intro + 1 link to `https://danplaton4.github.io/tenancy-bundle/roadmap/`. Total target ~5 lines. **The content currently at lines 5-46 moves verbatim to `docs/roadmap.md`** (D-03 says "the markdown that's currently at repo-root `ROADMAP.md` moves there verbatim").

### 16. `README.md` (repo root, 198 lines)

`## Roadmap` section at lines 188-190 currently links to `[ROADMAP.md](ROADMAP.md)` (relative file link). D-05 changes this to the docs-site URL: `[https://danplaton4.github.io/tenancy-bundle/roadmap/](https://danplaton4.github.io/tenancy-bundle/roadmap/)`. Also line 12 in the badges/links line currently links `[Roadmap](ROADMAP.md)` — that should likely change too (discretion: docs-site URL is cleaner, repo-root pointer also works since ROADMAP.md still exists as a 5-line thin pointer).

### 17. `examples/saas/README.md` (181 lines)

Confirmed canonical demo walkthrough. Content includes two-minute-boot section, three-step fallback ladder (`*.tenancy.localhost` magic, `/etc/hosts` fallback, browser-native), Profiler walkthrough, Mailpit walkthrough. D-08's `docs/examples/saas-demo.md` MUST NOT duplicate this — it should be a thin intro page that points here.

### 18. `docs/user-guide/installation.md` (122 lines, the SC1 target)

Current structure ends in `## 5. Verification` (L83). The `## 2. Bundle Registration` (L15) is the section that contains the `bundles.php` reference at line 17: `Add the bundle to \`config/bundles.php\`:`. D-11/D-12 rewrites this entire L15-50 section to the one-command flow. The post-install verification (L83-117) stays intact.

## Profiler ASCII Source-of-Truth

The Twig template (`src/Resources/views/Collector/tenant.html.twig`) lines 67-164 is the source of truth. For each state, the ASCII render must show exactly these fields with exactly these labels (label = bold prose in HTML, value = `{{ collector.data.* }}`). Drift = docs lie about code.

### State 1: Resolved (fires when `state == 'resolved'`)

Trigger: `TenantContext->getTenant()` returns non-null. Twig block: lines 70-150 of `tenant.html.twig`.

Fields in display order (matches the `.metrics` grid + the H3 sub-sections):

| Label (UI) | Source key | Example value |
|------------|-----------|---------------|
| Slug | `data.slug` | `acme` |
| Tenant | `data.tenant_label` (default `-`) | `Acme Corporation` |
| Driver | `data.driver` | `shared_db` or `database_per_tenant` |
| Connection | `data.connection_name` (default `-`) | `tenant` or `default` |
| Resolved by (H3) | `data.resolved_by` (default `-`) | full FQCN — Twig basenames it via `\|split('\\')\|last` in toolbar only; panel shows full FQCN |
| Bootstrappers (N) (H3) | `data.bootstrappers` (string[]) | `Tenancy\Bundle\Bootstrapper\DoctrineBootstrapper`, etc. |

Optional Mailer sub-block (when `LruTransportCache` wired):

| Label | Source | Example |
|-------|--------|---------|
| Status badge | `data.mailer.badge` | `OK` (green) or `MISSING` (yellow) |
| Transport cache | `data.mailer.cache_size/cache_max` | `0/100` |
| Cache hits | `data.mailer.cache_hits` | `0` |
| Cache evictions | `data.mailer.cache_evictions` | `0` |
| Strategy | `data.mailer.strategy` | always `x_transport` |
| Async | `data.mailer.async_detected` (optional) | `auto` / `true` / `false` |
| From | `data.mailer.from` | `noreply@acme.example` |
| Reply-To | `data.mailer.reply_to` | `(none)` if null |
| DSN (redacted) | `data.mailer.dsn_redacted` | `smtp://acme:***@smtp.acme.example:587` |

### State 2: Null (fires when `state == 'null'`)

Trigger: no tenant resolved AND no captured exception. Twig block: lines 158-162. Renders only two paragraphs of fixed prose:
- `No tenant resolved for this request.`
- `This is the expected state for public, landlord, and health-check routes.`

ASCII render is intentionally minimal — there are no fields to display in this state. The page should show this as a small block (5-6 lines) to give visual symmetry with the other two states.

### State 3: Error (fires when `state == 'error'`)

Trigger: a `Tenancy\Bundle\Exception\*` exception was captured by `TenantProfilerStash::onKernelException` (only classes prefixed `Tenancy\\Bundle\\Exception\\`). Twig block: lines 151-156.

Fields:

| Label (UI) | Source key | Example value |
|------------|-----------|---------------|
| Heading | (fixed) | `Resolution error` |
| Exception class (bold) | `data.error.class` | `Tenancy\Bundle\Exception\TenantInactiveException` |
| Exception message | `data.error.message` | `Tenant "acme" is inactive.` |

Note: per the existing prose at line 108 of `profiler-tab.md`, `TenantNotFoundException` is NOT shown as an error state — resolvers catch it and the request falls through to the null state. Only `TenantInactiveException`, `TenantMissingException`, etc. trigger the error state. The ASCII example should pick a realistic case (typically `TenantInactiveException`).

## docs-lint awk Pattern

D-15 extends `scripts/docs-lint.sh` with a check that fires on `bundles.php` occurrences in `docs/` markdown files EXCEPT inside `## Migration` or `## Upgrade` headed scopes. Note: `UPGRADE.md` is already out-of-scope of the TARGETS array, so this rule applies only within `docs/`.

**Important:** D-15 needs to allow legitimate `bundles.php` references inside heading scopes named "Migration" or "Upgrade". Looking at the current docs after D-11, the remaining `bundles.php` references will be:
- `docs/user-guide/profiler-tab.md` lines 22, 35, 125, 135 — these are inside `## Do I have to do anything?` / `## Troubleshooting` (NOT inside an Upgrade/Migration section).
- `docs/index.md` line 22 — inside `## Quick Start` (NOT inside Upgrade/Migration). This line WILL be rewritten by D-05/D-11 so the reference disappears.

**This means D-15 must whitelist profiler-tab.md's bundles.php mentions** — they describe the manual web-profiler-bundle install path, which is unrelated to the tenancy install regression we're guarding against. There are 3 viable approaches:

**Approach A (recommended): scope by H2 section, whitelist `## Manual setup` and `## Troubleshooting` headings**

```bash
# Add to scripts/docs-lint.sh after the existing 5 check calls.

# D-15: fail on bundles.php in docs/ outside whitelisted sections.
# Whitelisted H2 sections: "Migration", "Upgrade", "Manual setup", "Troubleshooting", "Do I have to do anything?"
# Implementation: awk tracks current H2 heading; when in a whitelisted section, lines are dropped before grep.

awk '
    /^## / {
        section = $0
        # Strip leading "## " for easier matching.
        sub(/^## /, "", section)
        in_whitelist = (section ~ /^(Migration|Upgrade|Manual setup|Troubleshooting|Do I have to do anything\?)/)
        next
    }
    !in_whitelist { print FILENAME ":" FNR ":" $0 }
' $(find docs/ -name '*.md') | grep -nE 'bundles\.php' && {
    echo "ERROR: 'bundles.php' install-path reference found in docs/ outside whitelisted sections (Migration / Upgrade / Manual setup / Troubleshooting)."
    EXIT=1
}
```

**Approach B (CONTEXT.md exact text): scope by `## Migration` / `## Upgrade` only**

D-15 literally says "excludes lines inside a `## Migration` or `## Upgrade` heading scope". If we follow this verbatim, the profiler-tab.md mentions FAIL the check — and the planner has two options: (1) restructure profiler-tab.md to nest those mentions under an `## Upgrade` or `## Manual setup` heading, (2) widen the whitelist (Approach A).

**Recommended: Approach A** — the simpler whitelist set covers all current legitimate uses without forcing structural changes to profiler-tab.md. The planner can document the whitelist in a comment block inside `scripts/docs-lint.sh` so future authors know which section names are "safe."

**Awk section-tracking logic (the load-bearing piece):**

```awk
/^## / { section = $0; sub(/^## /, "", section); in_whitelist = (section ~ /^(Migration|Upgrade|Manual setup|Troubleshooting|Do I have to do anything\?)/); next }
!in_whitelist { print FILENAME ":" FNR ":" $0 }
```

The `next` after section detection means heading lines themselves never reach the grep step — only body lines. The `FILENAME ":" FNR ":"` prefix preserves grep-style output for human-readable errors.

**Alternative simpler approach (Approach C — flag-day rename):** since the only legitimate `bundles.php` references after D-11 are in profiler-tab.md (describing the OPTIONAL web-profiler-bundle install, NOT the tenancy install), the planner could pragmatically just inline-comment those lines in the markdown with HTML comment markers and grep for a different pattern. This is uglier. Approach A is cleaner.

## mkdocs Nav Diff

Current state (`mkdocs.yml` lines 66-100):

```yaml
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
  - Contributor Guide: ...
  - Architecture Reference: ...
```

Target state after D-07 + D-16 (recommended ordering per CONTEXT.md "install flow → core drivers → resolvers/bootstrappers → CLI → testing → strict mode → examples → roadmap"):

```yaml
nav:
  - Home: index.md
  - User Guide:
    - user-guide/index.md
    - Installation: user-guide/installation.md
    - Getting Started: user-guide/getting-started.md
    - Configuration Reference: user-guide/configuration.md
    - Database-per-Tenant: user-guide/database-per-tenant.md
    - Shared-DB Driver: user-guide/shared-db.md
    - Resolvers: user-guide/resolvers.md
    - Origin Header Resolver: user-guide/origin-header-resolver.md   # NEW (already on disk)
    - Cache Isolation: user-guide/cache-isolation.md
    - Mailer Bootstrapper: user-guide/mailer-bootstrapper.md         # NEW (new page)
    - Messenger Integration: user-guide/messenger.md
    - CLI Commands: user-guide/cli-commands.md
    - Profiler Tab: user-guide/profiler-tab.md                       # NEW (already on disk)
    - Testing: user-guide/testing.md
    - Strict Mode: user-guide/strict-mode.md
    # REMOVED: nested Examples section
  - Examples:                                                        # NEW top-level
    - SaaS Subdomain: examples/saas-subdomain.md                     # MOVED from user-guide/examples/
    - API Header: examples/api-header.md                             # MOVED from user-guide/examples/
    - SaaS Demo (runnable): examples/saas-demo.md                    # NEW
  - Contributor Guide: (unchanged)
  - Architecture Reference: (unchanged)
  - Roadmap: roadmap.md                                              # NEW top-level
```

**File moves required (D-07):**
- `docs/user-guide/examples/saas-subdomain.md` → `docs/examples/saas-subdomain.md`
- `docs/user-guide/examples/api-header.md` → `docs/examples/api-header.md`
- Remove empty directory `docs/user-guide/examples/` after the move.

**New files (D-06, D-14, D-03):**
- `docs/examples/saas-demo.md` (NEW, thin walkthrough)
- `docs/user-guide/mailer-bootstrapper.md` (NEW)
- `docs/roadmap.md` (NEW, canonical roadmap)

## UPGRADE.md Insertion Point

Per §6 of the File Shape Survey:

- `UPGRADE.md` line 1 = `# Upgrade Guide`
- Line 2 = blank
- Line 3 = `## 0.3.1 to 0.3.2` (current top section)

**Insert the new `## 0.3.2 to 0.3.3` section AT line 3**, pushing existing content down. The new section sits between line 2 (blank) and the old line 3 (which becomes the new ~line 50ish depending on section length).

Required content per D-13:
- New H2: `## 0.3.2 to 0.3.3`
- Body: `nikic/php-parser` now in `require` (previously in `suggest`). DEC-INST-02 from Phase 18 reversed by user feedback during Phase 22 discussion. Production deploys gain ~50KB of AST parser code that's idle at runtime; trade-off accepted for one-command install UX.
- The section needs a blank line and a separator before the existing `## 0.3.1 to 0.3.2` heading (standard 2-blank-line separator pattern used elsewhere in this file — verified at line 70-72 between 0.3.1→0.3.2 and 0.2→0.3).

**Optional polish (SC5 "verify wording is final"):** the existing line 173 says `(coming in the v0.3 docs refresh)` referring to `mailer-bootstrapper.md`. After Phase 22, that page exists. The planner may update the parenthetical to drop "coming in" — discretionary.

## composer.json Edit Map

D-09: Add `nikic/php-parser` to `require`.

Exact insertion point: between line 21 (`"php": "^8.2",`) and line 22 (`"symfony/cache": "^7.4||^8.0",`).

New line content (alphabetical between `php` and `symfony/*`):
```
        "nikic/php-parser": "^5.0",
```

Indentation: 8 spaces (matches existing `require` block lines). Trailing comma required (it precedes the symfony/cache line).

D-10: Remove nikic from `suggest`.

Exact line to delete: line 53.
```
        "nikic/php-parser": "Required to run bin/console tenancy:install (one-shot installer; not needed at runtime)",
```

The preceding line 52 (`doctrine/migrations`) keeps its trailing comma. The following line 54 (`symfony/mailer`) is unaffected. JSON syntactic validity is preserved.

D-09 keep in `require-dev` (no change needed): line 37 `"nikic/php-parser": "^5.0",` stays. The package will then appear in both `require` and `require-dev`, which is correct — composer treats `require` as the source of truth for production resolution and `require-dev` as supplementary; having the same package in both is benign (composer dedupes during install).

**Validation step the planner SHOULD include:** after editing, run `composer validate` to confirm the JSON is still well-formed and the manifest is valid.

## Cross-link Map

D-07 moves two files. The following cross-references currently target the OLD paths and MUST be updated:

| File | Line | Current link | New link |
|------|------|--------------|----------|
| `docs/user-guide/shared-db.md` | 178 | `[Examples: API Header](examples/api-header.md)` | `[Examples: API Header](../examples/api-header.md)` |
| `docs/user-guide/index.md` | 27 | `[SaaS Subdomain](examples/saas-subdomain.md)` | `[SaaS Subdomain](../examples/saas-subdomain.md)` |
| `docs/user-guide/index.md` | 28 | `[API Header](examples/api-header.md)` | `[API Header](../examples/api-header.md)` |
| `docs/user-guide/database-per-tenant.md` | 260 | `[Examples: SaaS Subdomain](examples/saas-subdomain.md)` | `[Examples: SaaS Subdomain](../examples/saas-subdomain.md)` |

**Critical:** because `mkdocs build --strict` is on (verified in `.github/workflows/docs.yml` line 39), missing any one of these will fail the build. The planner MUST list all 4 line-edits in the same plan that performs the file move.

**Search command for verification** (the planner can append this to the move plan as a post-step):
```bash
grep -rn "user-guide/examples\|examples/saas-subdomain.md\|examples/api-header.md" docs/
```
After the move + edits, this command should show ZERO matches in `docs/user-guide/**` and the only matches should be in `docs/user-guide/index.md`'s NEW lines (which now use `../examples/`).

## Landmines

These are the non-obvious failure modes the planner must address:

### 1. `mkdocs build --strict` will fail on broken links

`.github/workflows/docs.yml` line 39 runs `mkdocs build --strict`. Any plan that adds a new page to nav without creating the file, OR moves a file without updating cross-refs, will silently pass local validation but fail CI. **Mitigation:** every plan that touches `mkdocs.yml` or moves a file MUST include a `mkdocs build --strict` smoke step locally before the commit lands.

### 2. The `Examples` section reorder is a structural nav change, not an additive one

D-07 removes the nested `Examples:` block from under `User Guide:` AND adds a new top-level `Examples:`. If the planner accidentally leaves both, mkdocs will not error (both pages are referenced), but the nav becomes confusing. The planner must DELETE the L81-83 nested block in `mkdocs.yml` as part of the same edit that adds the top-level block.

### 3. profiler-tab.md's bundles.php references will FAIL the new docs-lint rule unless the whitelist covers them

See §"docs-lint awk Pattern" above. Approach A (whitelist `## Manual setup` and `## Troubleshooting`) avoids restructuring profiler-tab.md. Approach B (literal CONTEXT.md wording) requires restructuring. Planner must pick one — Approach A is the lighter-touch path.

### 4. The docs index.md L22 reference to `config/bundles.php` will trip D-15

The line `Register the bundle in \`config/bundles.php\`, then run \`bin/console tenancy:init\`...` lives under `## Quick Start` H2 (line 14). Approach A's whitelist does NOT cover `Quick Start`. D-11 will rewrite this line anyway (one-command install), so the issue resolves itself if D-11 ships before/with D-15. **Plan ordering matters:** put the doc-rewrite tasks BEFORE the docs-lint rule task, or include the docs-lint rule task in the same plan that completes the rewrites (so when CI runs the new rule, the doc is already clean).

### 5. `docs/roadmap.md` link format from README.md (D-05)

The README references must use the docs-site URL `https://danplaton4.github.io/tenancy-bundle/roadmap/` (note trailing slash — MkDocs Material default behavior generates per-page directories, not `.html` files; the `roadmap.md` page renders at `/roadmap/`). Anchor links work the same way. Mismatched URL slug = 404 in production. Verified pattern: line 178 of README.md already uses `getting-started/` with trailing slash — same convention.

### 6. composer.json sort-packages may reorder the require block on next composer update

`"sort-packages": true` (line 74) means a developer running `composer update` will get the require/require-dev blocks re-sorted. Inserting `nikic/php-parser` at the correct alphabetical position (between `php` and `symfony/cache`) makes this a no-op. Any other position will silently get re-sorted on the next dependency change, which is fine but produces a cosmetic diff. **Mitigation:** insert at the correct position in this phase. Verify with `composer normalize` (if available) or `composer validate --strict`.

### 7. UPGRADE.md 0.2→0.3 line 173 ("coming in the v0.3 docs refresh") becomes stale

Cosmetic but visible. After Phase 22 ships and `docs/user-guide/mailer-bootstrapper.md` exists, the parenthetical is misleading. Discretion suggests updating to `(see docs/user-guide/mailer-bootstrapper.md)`.

### 8. The `## 0.3.2 to 0.3.3` UPGRADE.md section must clarify that no entity migration is needed

Unlike the `## 0.2 to 0.3` BC break (which requires a schema migration), v0.3.3 is purely a composer.json change. The planner should explicitly say "no code changes required" so users aren't worried about migration scripts. Phrase it as: "Run `composer update danplaton4/tenancy-bundle` and you're done — no application code or schema changes are required."

### 9. Resolver count in docs/index.md L72 ("4 built-in resolvers") drifts from README.md L104 ("5 built-in resolvers incl. Origin")

README.md L104 already correctly says 5 resolvers. docs/index.md L72 still says `**4 built-in resolvers**`. Phase 22 should fix this for consistency — same fix as D-19's resolvers.md update. Add this to the same plan that handles D-19.

### 10. `docs/user-guide/index.md` lines 14-23 (Features list) is missing entries for the new pages

This file's `## Features` section enumerates feature pages. Currently lists 9 entries through line 23. After D-16 adds origin-header-resolver, mailer-bootstrapper, profiler-tab to nav, this list should also reference them for in-page discoverability. The "Real-World Examples" section at line 25-28 also references the moved examples (paths must update — see Cross-link Map above).

### 11. `requirements.txt` for docs build (line 1 of docs/requirements.txt — verified to exist)

There's a `docs/requirements.txt` file present. The planner doesn't need to touch it — but if any of the new pages use a markdown extension not already enabled (e.g., `pymdownx.tasklist` for checkboxes), `mkdocs.yml` would need an update too. All current new-page needs (tabbed code blocks, admonitions, tables) are already supported.

### 12. `examples/saas/README.md` is repo-root canonical for the demo — DON'T move it

D-08 says `docs/examples/saas-demo.md` is a THIN page pointing at `examples/saas/README.md`. The canonical demo doc stays in the repo at its current path. If the planner accidentally moves or duplicates it, the Phase 21 link from README.md line 12 and 82 breaks.

## Validation Architecture

**Not applicable.** Phase 22 is docs-only — no code-behavior to nyquist-validate. The only validation is:
- `mkdocs build --strict` passes (already gates CI on every push to master via `.github/workflows/docs.yml`)
- `scripts/docs-lint.sh` passes (existing CI gate; D-15 adds one more check call to it)
- `composer validate` passes after composer.json edit
- Human walkthrough of the rendered docs site post-merge

No PHPUnit tests are added or modified by this phase. The orchestrator can skip the Validation Architecture / nyquist-validation step.

## Project Constraints (from CLAUDE.md)

Even though this phase is docs-only, the planner must honor:
- `strict_types=1` is project-wide — N/A for docs changes
- `php-cs-fixer @Symfony` — N/A for docs but if any docs include PHP snippets, follow the style
- `PHPStan level 9` — N/A for docs
- Doctrine is **optional** (`class_exists`/`interface_exists` guards) — every docs example that references Doctrine should mention it's optional, matching existing conventions in `installation.md` L53-66
- `tenancy:init` is the primary onboarding path per MEMORY (`feedback_no_flex.md`) — D-11 elevates `tenancy:install` to that role for v0.3.3; both commands coexist
- Every planning artifact lands in git per MEMORY (`feedback_docs_always_commit.md`) — `commit_docs: true` is already set in `.planning/config.json` (verified)

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | The `mkdocs build --strict` step in CI fails on any broken nav reference (vs. just warning) | Landmines #1 | Low — `--strict` is a documented MkDocs flag that turns warnings into errors. Verified in MkDocs docs broadly but not re-checked in this session. [ASSUMED] |
| A2 | composer's "sort-packages: true" config alphabetizes both `require` and `require-dev` and `suggest` (not just one of them) | composer.json Edit Map | Low — visual diff only. Worst case: composer re-sorts on next update. [ASSUMED] |
| A3 | MkDocs Material renders `docs/roadmap.md` at URL `/roadmap/` (with trailing slash) by default | Landmines #5 | Low — verified pattern matches existing README.md L178 (`getting-started/`). If the deployed site uses a different URL scheme, the README link will 404. The planner should manually open `https://danplaton4.github.io/tenancy-bundle/` after deploy to verify. [ASSUMED but conservative] |
| A4 | Approach A's whitelist (`Manual setup`, `Troubleshooting`, `Do I have to do anything?`) is acceptable to the user, even though CONTEXT.md D-15 literally only mentions `Migration`/`Upgrade` | docs-lint awk Pattern | Medium — if user wants strict adherence to D-15's literal text, the planner must restructure profiler-tab.md instead. The discuss-phase decision tree should surface this. |
| A5 | D-13's UPGRADE.md "0.3.2 to 0.3.3" section is the only NEW upgrade entry needed — no other BC breaks in v0.3.3 | UPGRADE.md Insertion Point | Low — verified by reading CONTEXT.md and confirming v0.3.3 is purely composer + docs. No code BC breaks ship. |

**All other claims in this research were verified against repo files in this session.**

## Open Questions

1. **Approach A vs B for D-15's awk filter** — see Landmines #3. CONTEXT.md says "`## Migration` or `## Upgrade` heading scope" literally; profiler-tab.md's `bundles.php` mentions live under `## Manual setup` and `## Troubleshooting`. Either widen the whitelist (Approach A) or restructure profiler-tab.md.
   - **What we know:** Approach A is structurally lighter and matches the spirit of CONTEXT.md.
   - **What's unclear:** Does the user want strict literal adherence to "Migration/Upgrade only"?
   - **Recommendation:** Planner picks Approach A and notes the deviation in the relevant PLAN.md. Discuss-phase may want to confirm if there's uncertainty.

2. **Should `docs/index.md` L22 (`Register the bundle in config/bundles.php...`) be rewritten by Phase 22?** This line is on the docs LANDING page (not installation.md). D-11 covers `installation.md` specifically. The landing page is technically out of scope of D-11 but in scope of SC1 ("Install page mentions only `tenancy:install`").
   - **Recommendation:** Yes, rewrite L22 of `docs/index.md` as part of the same plan that handles installation.md (D-11). This is consistent with SC1's intent.

3. **What does the new `## 0.3.2 to 0.3.3` UPGRADE section say about how this affects users with `nikic/php-parser` already in their composer.lock from v0.3.0+?** If a user already ran `tenancy:install` in v0.3.0/0.3.1/0.3.2 (which required them to install nikic into require-dev manually per the README line 57 callout), upgrading to v0.3.3 transparently moves nikic to a transitive dep. No action needed, but the UPGRADE entry should briefly say so to avoid confusion.
   - **Recommendation:** Include a one-liner in the UPGRADE section: "Existing users who ran `composer require --dev nikic/php-parser` previously can safely remove it from their dev-deps; composer will resolve it as a transitive dependency."

4. **D-19's mention of "5th built-in resolver" plus the existing intro at `resolvers.md` L9 mentioning `ConsoleResolver` which doesn't participate in the HTTP chain** — does that count as "built-in resolver"? The README counts 5 (including Origin); resolvers.md L2 currently says "four resolvers". If we count Console, that's 5 → 6.
   - **Recommendation:** Match README's framing: 5 built-in HTTP-chain participants. Console is separate (already explained at L24 of `resolvers.md` as "operates independently from the HTTP resolver chain"). Update the intro to say something like "five built-in HTTP resolvers and one console-only resolver" — or use the README's exact phrasing for consistency.

## Sources

### Primary (HIGH confidence)
- `src/Profiler/TenantDataCollector.php` (lines 1-178) — full read, source of truth for 8-key `$this->data` shape
- `src/Profiler/TenantProfilerStash.php` (lines 1-95) — full read, event subscriber field names
- `src/Resources/views/Collector/tenant.html.twig` (lines 1-164) — full read, ASCII reference for 3 panel states
- `src/DependencyInjection/Compiler/MailerTransportContractPass.php` (lines 1-106) — async-failure-mode contract documentation source
- `src/Mailer/TenantMailerConfigTrait.php` (lines 1-65) — trait shape for D-18 mailer config section
- `composer.json` (lines 1-76) — full read
- `UPGRADE.md` (lines 1-317) — full read, structure + 0.2→0.3 wording verified
- `mkdocs.yml` (lines 1-106) — full read
- `scripts/docs-lint.sh` (lines 1-48) — full read
- `docs/user-guide/profiler-tab.md` (lines 1-155) — full read
- `docs/user-guide/origin-header-resolver.md` (lines 1-151) — Trust Model anchor verified
- `docs/user-guide/getting-started.md` (lines 1-264) — full read, insertion points identified
- `docs/user-guide/configuration.md` (lines 1-261) — full read, H3 structure mapped
- `docs/user-guide/resolvers.md` (lines 1-292) — full read, 3 surgical edit points identified
- `docs/user-guide/cli-commands.md` (lines 1-191) — full read
- `docs/user-guide/installation.md` (lines 1-122) — full read
- `docs/index.md` (lines 1-137) — full read
- `docs/user-guide/index.md` (lines 1-28) — full read
- `ROADMAP.md` (lines 1-46) — full read
- `README.md` (lines 1-198) — full read
- `.planning/phases/22-docs-refresh/22-CONTEXT.md` (lines 1-150) — full read
- `.planning/REQUIREMENTS.md` — DOC-19 verified at L62-72
- `.planning/ROADMAP.md` — Phase 22 SC1-SC6 verified at L223-238
- `.github/workflows/docs.yml` — verified `mkdocs build --strict` on line 39

### Secondary (MEDIUM confidence)
- Grep across `docs/` for cross-link discovery — 4 references to `user-guide/examples/*.md` found and tabulated in Cross-link Map

### Tertiary (LOW confidence)
- None.

## Metadata

**Confidence breakdown:**
- File shape survey: HIGH — every file read in full, line numbers verified in this session
- Profiler ASCII source-of-truth: HIGH — direct extraction from Twig template + collector code
- docs-lint awk pattern: HIGH (Approach A logic) / MEDIUM (whether user accepts deviation from CONTEXT.md literal wording — flagged in Open Questions #1)
- mkdocs nav diff: HIGH — verified against actual nav block
- UPGRADE.md insertion: HIGH — heading line numbers verified
- composer.json edit map: HIGH — line numbers and alphabetical ordering verified
- Cross-link map: HIGH — every match confirmed by repo-wide grep
- Landmines: HIGH for code-verifiable items (#1, #4, #5, #6, #9, #10) / MEDIUM for assumptions about user intent (#3, #7, #8)

**Research date:** 2026-05-28
**Valid until:** 30 days (stable, docs-only domain; source files may shift if other work lands on master)

## RESEARCH COMPLETE
