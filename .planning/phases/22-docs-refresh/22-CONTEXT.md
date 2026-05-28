# Phase 22: Docs Refresh - Context

**Gathered:** 2026-05-22
**Status:** Ready for planning

<domain>
## Phase Boundary

The docs site catches up to what v0.3 actually shipped (Phases 17–21). Specifically:

1. `docs/user-guide/installation.md` becomes one-command — `composer require danplaton4/tenancy-bundle` + `bin/console tenancy:install`. Zero references to manually editing `bundles.php` on the install path.
2. **New page** `docs/user-guide/mailer-bootstrapper.md` documents the Phase 20 per-tenant Mailer (X-Transport strategy, async failure-mode warning, migration recipe).
3. **New page** `docs/examples/saas-demo.md` walks through the Phase 21 demo — thin docs-site intro that links to canonical `examples/saas/README.md`.
4. **New page** `docs/roadmap.md` becomes the canonical roadmap (full content on docs site). Repo-root `ROADMAP.md` becomes a thin pointer to the docs-site URL. README.md links to the docs URL directly.
5. `UPGRADE.md` 0.2→0.3 section already covers the `TenantInterface::getMailerDsn()` BC break + `TenantMailerConfigTrait` migration — verify wording is final, add a note that DEC-INST-02 (Phase 18) is reversed.
6. `scripts/docs-lint.sh` extended: fail on any `bundles.php` reference in docs/ outside Migration/Upgrade sections (regression guard for SC1).

**Scope expansion locked during discussion (1) — composer.json:** Add `nikic/php-parser` to `composer.json` `require` (was `suggest` per DEC-INST-02). Ship docs + dep change + install UX together as **v0.3.3** patch release. After this change, `tenancy:install` works without users having to know about nikic.

**Scope expansion locked during discussion (2) — yellow page refresh:** Four existing User Guide pages predate the v0.3 surface and need surgical additions (not rewrites) so the docs are genuinely complete in v0.3.3:
- `getting-started.md` — add v0.3 feature pointers (Origin resolver, Profiler tab, Mailer) with cross-links
- `configuration.md` — add `origin.allow_list` config block + per-tenant mailer config notes
- `resolvers.md` — add OriginHeaderResolver as the 5th built-in resolver (priority 25); cross-link to `origin-header-resolver.md`
- `cli-commands.md` — add `tenancy:install` entry as the headline one-command-setup path

</domain>

<decisions>
## Implementation Decisions

### Profiler tab visualization (SC2)
- **D-01:** `docs/user-guide/profiler-tab.md` shows the WDT panel as ASCII/code-block renders + prose describing each field + a "see it live in the demo" link to `examples/saas/README.md`. No PNGs, no SVGs, no Mermaid. Zero binary assets, zero drift maintenance.
- **D-02:** Cover all three panel states the page already promises to document: resolved tenant, no-tenant, error. Each ASCII block ~10 lines, total ~40 lines.

### Roadmap mirror (SC4) — inverted direction
- **D-03:** `docs/roadmap.md` becomes the **canonical** roadmap. Full content lives in the docs site (the markdown that's currently at repo-root `ROADMAP.md` moves there verbatim).
- **D-04:** Repo-root `ROADMAP.md` becomes a thin pointer (~5 lines): `# Roadmap` heading + one-sentence intro + a single link to `https://danplaton4.github.io/tenancy-bundle/roadmap/`. Stays in the repo for GitHub-UI discoverability.
- **D-05:** README.md updates the `## Roadmap` section to link to the docs-site URL directly, not the repo-root file. Single source of truth = docs site; drift is structurally impossible because there's only one canonical copy.

### Demo walkthrough (SC3) — location, depth, sync
- **D-06:** New page lives at `docs/examples/saas-demo.md` — honoring SC3's literal location, NEW top-level dir.
- **D-07:** Side effect: existing `docs/user-guide/examples/` (api-header.md, saas-subdomain.md) **moves up** to `docs/examples/`. mkdocs nav consolidates into one top-level Examples section. Update internal links to those two existing pages.
- **D-08:** `docs/examples/saas-demo.md` is a **thin** page: intro + "what this demo proves" + 1-line install + small ASCII teaser + link to canonical `examples/saas/README.md` for the full walkthrough. Do NOT duplicate the 250-line walkthrough. Single source of truth = the repo README.

### Installation page + dep tree (SC1 + scope expansion)
- **D-09:** Add `nikic/php-parser: ^5.0` to bundle `composer.json` `require` (was in `suggest`). Keep it in `require-dev` so the bundle's own tests don't change behaviour.
- **D-10:** Drop the `nikic/php-parser` entry from `composer.json` `suggest` (it's no longer a suggestion — it's a hard dep).
- **D-11:** `installation.md` becomes one-command: install + `tenancy:install`. Remove every reference to manually editing `bundles.php` from this page. nikic prereq becomes a footnote/aside at most (or completely silent — users get it transitively).
- **D-12:** Manual install instructions: **removed entirely** from `installation.md`. The Phase 18 install command always works now that nikic is a hard dep. No "fallback manual install" page is created.
- **D-13:** `UPGRADE.md` gets a `## 0.3.2 to 0.3.3` section explaining: `nikic/php-parser` now in `require` (previously in `suggest`). DEC-INST-02 from Phase 18 reversed by user feedback during Phase 22 discussion. Production deploys gain ~50KB of AST parser code that's idle at runtime; trade-off accepted for one-command install UX.

### Mailer page (SC2)
- **D-14:** New `docs/user-guide/mailer-bootstrapper.md` covers (a) per-tenant DSN + From + ReplyTo via `TenantMailerConfigTrait` or inline columns, (b) the **X-Transport strategy** (sync-safe + async-safe), (c) **async failure-mode warning** — what happens when an async worker dequeues a message for a tenant that's been deleted, (d) migration recipe for projects that already have a custom Tenant entity (`use TenantMailerConfigTrait` insertion via `tenancy:install --with-mailer`).

### docs-lint rule (SC6)
- **D-15:** Extend `scripts/docs-lint.sh` with a targeted check: `grep -rn 'bundles\.php' docs/ --include='*.md'` with `awk`-based filtering that excludes lines inside a `## Migration` or `## Upgrade` heading scope. Catches the regression case (install instructions sneaking `bundles.php` back in) without flagging legitimate references in upgrade guides.

### Yellow page refresh — surgical additions to existing User Guide pages (scope expansion 2)
- **D-17 (`getting-started.md`):** Add three short subsections to the existing page (do NOT rewrite the rest): (a) "Resolving tenants from SPA Origin headers" — 2-paragraph teaser pointing at `origin-header-resolver.md`; (b) "Inspecting the active tenant in dev" — 1-paragraph teaser pointing at `profiler-tab.md`; (c) "Per-tenant mailer config" — 2-paragraph teaser pointing at the new `mailer-bootstrapper.md`. Each subsection ends with a "Full guide → [page]" link. Keep the page's current install-and-config flow intact.
- **D-18 (`configuration.md`):** Add `origin.allow_list` config block to the reference (mirror the syntax from `docs/user-guide/origin-header-resolver.md` § Configuration so the two pages don't drift). Add a "Per-tenant mailer config" subsection that documents the three nullable Tenant columns (`mailerDsn`, `mailerFrom`, `mailerReplyTo`) and the `TenantMailerConfigTrait` shortcut. Cross-link to `mailer-bootstrapper.md` for the full strategy explanation.
- **D-19 (`resolvers.md`):** Add OriginHeaderResolver as the 5th built-in resolver with: priority (25), trust model summary (1 paragraph), allow-list requirement (1 sentence), and cross-link to `origin-header-resolver.md` for the full Trust Model section. Update the page's "4 built-in resolvers" intro to say 5. Do NOT duplicate the full Trust Model content from the dedicated page.
- **D-20 (`cli-commands.md`):** Promote `tenancy:install` to the top of the page as the headline one-command-setup path. Demote `tenancy:init` to a "Manual config scaffold" sub-section under it (init still has standalone uses — e.g., regenerating tenancy.yaml without re-registering the bundle). Document `tenancy:install --dry-run` and `--force` flags. Do NOT touch the `tenancy:migrate` / `tenancy:run` sections.

### mkdocs nav reorganization (D-07 side effect)
- **D-16:** mkdocs nav adds the following new entries (Claude discretion on ordering):
  - Under existing User Guide section: `profiler-tab.md`, `origin-header-resolver.md`, `mailer-bootstrapper.md` (these pages exist but are missing from nav today)
  - New top-level "Examples" section: `saas-subdomain.md`, `api-header.md`, `saas-demo.md`
  - New top-level "Roadmap" entry pointing at `roadmap.md`
- Logical ordering: install flow → core drivers → resolvers/bootstrappers → CLI → testing → strict mode → examples → roadmap. Planner can refine.

### Claude's Discretion
- Exact prose of each new docs page (we discussed scope and structure, not paragraph-level wording).
- Whether the ASCII profiler-tab renders are stacked vertically or side-by-side in tabbed pymdownx blocks. Pick whatever renders cleanest in the existing MkDocs Material theme.
- nikic footnote on `installation.md` — completely silent vs one-line "this command brings in `nikic/php-parser` automatically as a dependency". Either works.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Scope + success criteria
- `.planning/ROADMAP.md` §Phase 22 — six success criteria (SC1-SC6) that anchor scope
- `.planning/REQUIREMENTS.md` §DOC-19 — full acceptance list including Mailer page contents and roadmap mirror requirement

### Prior phase decisions that constrain Phase 22 content
- `.planning/phases/17-origin-header-resolver/17-CONTEXT.md` — Trust Model is already in `docs/user-guide/origin-header-resolver.md`. Do not regenerate; verify wording is current.
- `.planning/phases/18-tenancy-install/18-CONTEXT.md` §DEC-INST-02 / §D-01 — original "nikic in `suggest` for production lean-ness" rationale. This decision is **reversed** by Phase 22 D-09/D-10/D-13. Note the reversal in UPGRADE.md.
- `.planning/phases/19-profiler-tab/19-CONTEXT.md` — three panel states (resolved / no-tenant / error). `docs/user-guide/profiler-tab.md` already exists; this phase adds ASCII renders for SC2.
- `.planning/phases/20-mailer-bootstrapper/20-CONTEXT.md` — X-Transport strategy detail + async-canary failure mode for the new mailer-bootstrapper.md page (D-14).
- `.planning/phases/21-demo-app/21-CONTEXT.md` — demo scope + three-step fallback ladder for the new saas-demo.md page (D-06/D-08).

### Source-of-truth docs and code that drive page contents
- `examples/saas/README.md` — **canonical** demo walkthrough. `docs/examples/saas-demo.md` links here, does NOT duplicate.
- `ROADMAP.md` (repo root, current state — to be slimmed per D-04) — the content that moves to `docs/roadmap.md`.
- `UPGRADE.md` — already has the 0.2→0.3 Mailer BC break section. D-13 adds a 0.3.2→0.3.3 section.
- `composer.json` — D-09/D-10 edit `require` and `suggest`.
- `mkdocs.yml` — D-07 + D-16 edit `nav:`.
- `scripts/docs-lint.sh` — D-15 extends with the bundles.php-outside-Migration check.

### Existing docs to verify (not rewrite)
- `docs/user-guide/origin-header-resolver.md` — Trust Model section already present (verified during discussion)
- `docs/user-guide/profiler-tab.md` — exists, needs the 3-state ASCII renders added (no full rewrite)
- `docs/index.md` — already has the docs landing page; add Roadmap + Examples + new pages to nav links

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `scripts/docs-lint.sh` (48 lines): existing structure with a `check()` shell function that grep's a pattern across a TARGETS array. The new D-15 rule follows the same pattern — add one more `check` call with an awk-based section filter, or factor a new `check_outside_section()` helper.
- `UPGRADE.md`: stratified by `## X.Y to X.Z` headings. D-13 adds a new `## 0.3.2 to 0.3.3` section between the existing `## 0.3.1 to 0.3.2` and `## 0.2 to 0.3` sections.

### Established Patterns
- mkdocs Material theme is in use with `pymdownx.tabbed` + `pymdownx.superfences` enabled — supports tabbed code blocks if D-02's 3 ASCII renders go side-by-side as tabs.
- `composer.json` uses `^5.0` constraint style for major-version pinning. D-09 follows this for nikic.
- Single-line `composer suggest` entries describe the WHY (current entries use a verb-noun pattern: "Required for ...", "Adds a ... panel ..."). D-10 removes the nikic entry entirely.

### Integration Points
- `docs/examples/saas-demo.md` (new) references `examples/saas/README.md` (existing canonical). The link must remain stable as the demo evolves — no relative path tricks, use full repo-relative paths.
- `docs/roadmap.md` (new, canonical) is referenced from `README.md` (D-05) and from `ROADMAP.md` (D-04). Both pointers use the **docs-site URL** (`https://danplaton4.github.io/tenancy-bundle/roadmap/`), not the relative file path, so the pointers work for both repo browsers and docs-site readers.

</code_context>

<specifics>
## Specific Ideas

- The ASCII renders for the 3 profiler tab states should mirror what the WDT actually shows — the Profiler service files in `src/Profiler/` are the source of truth for which fields appear in each state. Read them before writing the ASCII.
- The repo-root `ROADMAP.md` post-slimming should retain enough content for GitHub-UI browsers to know "this project has a roadmap, here's where to read it" — not a single-line redirect that looks like a stub.
- The Mailer page's "async failure-mode warning" should specifically call out: a message dequeued for a deleted tenant must throw, not silently drop. Phase 20's `MailerTransportContractPass` enforces this contract; the docs should explain why it matters operationally.

</specifics>

<deferred>
## Deferred Ideas

- **Per-resolver subpage refactor** under a "Resolvers" nav group (currently flat `resolvers.md`). Cosmetic and out of scope. May revisit if `resolvers.md` grows past ~500 lines after the D-19 addition.
- **Documentation versioning** (mike, mkdocs-versioning, etc.) — when v0.4 and v1.0 ship, current docs site will only show the latest. Out of scope until adoption justifies the maintenance cost.
- **Symfony Flex recipe** to automate the install path further. Project decision (see PROJECT.md / no-Flex memory) holds: recipe maintenance cost > current install friction. Revisit when install volume is meaningful.
- **`tenancy:install` shell-out to `composer require nikic/php-parser`** if nikic is missing. D-09 supersedes the need — nikic is always present after this phase. The shell-out idea (option 4 in the nikic discussion) is permanently retired.

</deferred>

---

*Phase: 22-docs-refresh*
*Context gathered: 2026-05-22*
