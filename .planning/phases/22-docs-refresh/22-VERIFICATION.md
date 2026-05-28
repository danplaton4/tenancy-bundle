---
phase: 22-docs-refresh
verified: 2026-05-28T16:44:29Z
status: human_needed
score: 35/36 must-haves verified
overrides_applied: 0
human_verification:
  - test: "Run `mkdocs build --strict` against the full nav"
    expected: "Exit 0 — every nav entry resolves, every cross-link anchor exists, no broken internal links"
    why_human: "`mkdocs` is not installed on this machine; Plan 22-06 Task 6 acknowledged this and deferred to CI (`.github/workflows/docs.yml` line 39). Cannot verify programmatically from the verifier; CI on next push (or local `pip install -r docs/requirements.txt && mkdocs build --strict`) is the canonical gate."
  - test: "Render the three Profiler ASCII panels in MkDocs Material dark+light themes"
    expected: "Box-drawing characters (`┌─ ─┐`) align visually; tenant slug / FQCN columns don't wrap awkwardly; pymdownx fenced `text` blocks render with monospace font"
    why_human: "Visual rendering of box-drawing characters depends on the browser monospace font, the user's font-size setting, and how MkDocs Material wraps long FQCN lines. Plan 22-03 D-01 explicitly flagged this as Claude-discretion; grep verifies the characters are present, but only an eye can confirm the result reads cleanly."
  - test: "Open https://danplaton4.github.io/tenancy-bundle/roadmap/ once the next push lands and CI publishes"
    expected: "Canonical 45-line roadmap renders; CHANGELOG link resolves to GitHub; no 404 on the published URL"
    why_human: "The roadmap pointer chain (README badge → docs-site URL → docs/roadmap.md) was wired but the docs-site URL is a future GitHub Pages publish target. Until the next push triggers the docs build + publish, the URL returns 404. Only post-publish human navigation can confirm the chain is fully wired end-to-end."
  - test: "Verify the 4 internal cross-links in docs/examples/saas-demo.md resolve in the rendered docs site"
    expected: "All 4 See-also links navigate to the right pages: ../user-guide/installation.md, ../user-guide/profiler-tab.md, ../user-guide/mailer-bootstrapper.md, ../user-guide/origin-header-resolver.md"
    why_human: "Relative paths from docs/examples/ → docs/user-guide/ work in MkDocs Material but the `../../examples/saas/README.md` cross-tree link (file outside the docs/ root) may render as a broken anchor on the docs site (per RESEARCH §Landmines #1 — acceptable trade-off). Only browsing the published site can confirm what readers see."

# Note: human_verification items above are blocking only for the mkdocs --strict
# build; the visual checks are polish concerns that don't block tag readiness.
# Recommended workflow: push to master, let CI run mkdocs --strict, then visually
# confirm the published site.
---

# Phase 22: Docs Refresh Verification Report

**Phase Goal:** Docs Refresh — install page rewrite, new pages (resolver/profiler/mailer/demo/roadmap), UPGRADE 0.2→0.3, docs-lint extended (DOC-19).

**Verified:** 2026-05-28T16:44:29Z
**Status:** human_needed (35/36 truths VERIFIED in code; 1 human-only gate remaining — `mkdocs build --strict` on the docs-site nav)
**Re-verification:** No — initial verification

---

## Goal Achievement

### Roadmap Success Criteria (SC1–SC6)

| #   | Success Criterion | Status     | Evidence       |
| --- | ----------------- | ---------- | -------------- |
| SC1 | `docs/user-guide/installation.md` says "run `bin/console tenancy:install`" — zero references to manually editing `bundles.php` on the install path | ✓ VERIFIED | `grep -c 'bundles\.php' docs/user-guide/installation.md` returns `0`; `grep -c 'bin/console tenancy:install' docs/user-guide/installation.md` returns `3`; install page is 107 lines with one-command flow at L9-23 (Section 1 + 2) |
| SC2 | New pages exist and are linked from `docs/index.md` nav: `user-guide/origin-header-resolver.md` (with Trust Model section), `user-guide/profiler-tab.md` (with screenshots from Phase 19/21 — substituted with ASCII renders per D-01), `user-guide/mailer-bootstrapper.md` (with X-Transport strategy + async failure-mode warning + migration recipe) | ✓ VERIFIED | `origin-header-resolver.md` exists with `## Trust Model` H2 at L52; `profiler-tab.md` has 3-state ASCII renders at L79-91 (resolved), L101-109 (null), L119-129 (error); `mailer-bootstrapper.md` is a new 138-line file covering DSN config + X-Transport + async failure-mode warning + migration recipe; all 3 entries appear in mkdocs.yml nav at L76, L78, L81 |
| SC3 | `docs/examples/saas-demo.md` walks through the Phase 21 demo end-to-end | ✓ VERIFIED | File exists at `docs/examples/saas-demo.md` (69 lines, thin per D-08). Contains intro + "What this demo proves" bullets + Quick start + ASCII teaser + link to canonical `../../examples/saas/README.md`. NOT a duplication — it's a thin pointer page per D-08. |
| SC4 | `docs/roadmap.md` mirrors repo-root `ROADMAP.md`; both linked from `docs/index.md` + `README.md` | ✓ VERIFIED | `docs/roadmap.md` exists (45 lines, canonical); repo-root `ROADMAP.md` slimmed to 7 lines (`# Roadmap` heading + intro + URL to https://danplaton4.github.io/tenancy-bundle/roadmap/); README.md L12 badge and L188-190 `## Roadmap` section both link to docs-site URL; `docs/index.md` L64 button links to `roadmap.md`. NOTE: The direction is inverted per D-03/D-04 (docs is canonical, repo-root is pointer) — better than literal mirror. |
| SC5 | `UPGRADE.md` 0.2 → 0.3 section explains `TenantInterface::getMailerDsn()` BC break and the `TenantMailerConfigTrait` mitigation | ✓ VERIFIED | UPGRADE.md L115-215 contains the full 0.2→0.3 section: 3 abstract methods (`getMailerDsn`, `getMailerFrom`, `getMailerReplyTo`), Migration path A (trait), Migration path B (manual), ALTER TABLE snippet, present-tense link to `docs/user-guide/mailer-bootstrapper.md` at L215. Stale `(coming in the v0.3 docs refresh)` parenthetical replaced. |
| SC6 | `scripts/docs-lint.sh` extended with a check that fails on any `bundles.php` install-path reference outside the UPGRADE / Migration sections | ✓ VERIFIED | `scripts/docs-lint.sh` L44-89 contains the new D-15 awk-scoped check with whitelist (`Migration`, `Upgrade`, `Manual setup`, `Troubleshooting`, `Do I have to do anything?`, `tenancy:install`). Running `bash scripts/docs-lint.sh` exits 0 against current docs/. Synthetic-violation test in 22-06-SUMMARY confirms the rule fires when violated. |

**Score:** 6/6 Roadmap Success Criteria verified

---

### Plan-level Observable Truths (must_haves from each PLAN frontmatter)

#### Plan 22-01 (composer.json + installation.md + index.md)

| #   | Truth (paraphrased) | Status     | Evidence       |
| --- | ------------------- | ---------- | -------------- |
| 01-1 | composer.json `require` has `nikic/php-parser: ^5.0` between `php` and `symfony/cache` | ✓ VERIFIED | composer.json L20-23 shows `php` → `nikic/php-parser` → `symfony/cache` in alphabetical order |
| 01-2 | composer.json `suggest` does NOT contain `nikic/php-parser` | ✓ VERIFIED | composer.json L49-57 `suggest` block lists 7 entries (none for nikic); grep confirms |
| 01-3 | composer.json `require-dev` still contains `nikic/php-parser: ^5.0` | ✓ VERIFIED | composer.json L38 |
| 01-4 | `composer validate` exits 0 | ✓ VERIFIED | `composer validate --no-check-publish` exits 0 with one expected warning about nikic in require + require-dev (intentional per D-09) |
| 01-5 | docs/user-guide/installation.md no longer contains `bundles.php` on install path | ✓ VERIFIED | `grep -c 'bundles\.php' docs/user-guide/installation.md` returns 0 |
| 01-6 | installation.md install flow is `composer require` + `bin/console tenancy:install` | ✓ VERIFIED | docs/user-guide/installation.md L9-23 shows section 1 (composer) + section 2 (tenancy:install) |
| 01-7 | installation.md does NOT include a Manual install / fallback section | ✓ VERIFIED | No `## Manual` or `## Fallback` H2 sections found; one paragraph at L32 mentions non-standard `bundles.php` shape returning a snippet via the command itself, not a manual section |
| 01-8 | docs/index.md L22 area no longer instructs manual `config/bundles.php` registration | ✓ VERIFIED | docs/index.md L14-23 Quick Start block now uses two-line composer require + tenancy:install flow; no `config/bundles.php` reference anywhere in the file |

#### Plan 22-02 (UPGRADE.md)

| #   | Truth (paraphrased) | Status     | Evidence       |
| --- | ------------------- | ---------- | -------------- |
| 02-1 | UPGRADE.md has new `## 0.3.2 to 0.3.3` H2 at top | ✓ VERIFIED | L3 |
| 02-2 | New section explains nikic suggest→require + DEC-INST-02 reversal | ✓ VERIFIED | L10-27 (`### What changed`) names DEC-INST-02 explicitly at L12 and L21 |
| 02-3 | Section states "no application code or schema changes are required" | ✓ VERIFIED | L8 (single-line per the reflow fix) AND L32 (lowercase echo) |
| 02-4 | Section includes dev-deps removal one-liner | ✓ VERIFIED | L36-42 (`### Note for users who installed nikic manually`) |
| 02-5 | Existing `## 0.2 to 0.3` Mailer BC section preserved | ✓ VERIFIED | L115-215; all 3 abstract methods + path A trait + path B manual + ALTER TABLE snippet intact |
| 02-6 | Stale `(coming in the v0.3 docs refresh)` parenthetical replaced with present-tense link | ✓ VERIFIED | L213-215 reads `see the [Mailer Bootstrapper guide](docs/user-guide/mailer-bootstrapper.md)` |

#### Plan 22-03 (profiler-tab.md + mailer-bootstrapper.md)

| #   | Truth (paraphrased) | Status     | Evidence       |
| --- | ------------------- | ---------- | -------------- |
| 03-1 | profiler-tab.md has RESOLVED panel ASCII with Slug, Tenant, Driver, Connection, Resolved by, Bootstrappers | ✓ VERIFIED | L79-91 contains box-drawing ASCII with all 6 field labels verbatim from `src/Resources/views/Collector/tenant.html.twig` |
| 03-2 | profiler-tab.md has NULL panel ASCII with the two fixed prose lines | ✓ VERIFIED | L101-109 contains the box and L104-106 has "No tenant resolved for this request." + "This is the expected state for public, landlord, and health-check routes." |
| 03-3 | profiler-tab.md has ERROR panel ASCII with Resolution error + exception class + message | ✓ VERIFIED | L119-129 shows `Resolution error` + `TenantInactiveException` + `Tenant "acme" is inactive.` |
| 03-4 | profiler-tab.md has live-demo link to `examples/saas/README.md` | ✓ VERIFIED | L67 |
| 03-5 | Total ASCII content ~40 lines (per D-02) | ✓ VERIFIED | 3 blocks of ~10 lines each = ~30 lines plus headers/prose — within target |
| 03-6 | mailer-bootstrapper.md exists | ✓ VERIFIED | 138 lines |
| 03-7 | mailer-bootstrapper.md documents all 4 D-14 parts | ✓ VERIFIED | (a) `## Configuring per-tenant SMTP` L19-67, (b) `## The X-Transport strategy` L70-87, (c) `## Async failure-mode warning` L91-107, (d) `## Migration recipe` L111-128 |
| 03-8 | mailer-bootstrapper.md warns "must throw, not silently drop" + cites MailerTransportContractPass | ✓ VERIFIED | L93 has the bolded "must throw, not silently drop"; L100 cites `src/DependencyInjection/Compiler/MailerTransportContractPass.php` |
| 03-9 | profiler-tab.md preserves `bundles.php` refs at L22/L35/L125/L135 (web-profiler-bundle install, not tenancy) | ✓ VERIFIED | L22, L35 in `## Do I have to do anything?`; L125 (was reported as L125 but now actually L148) in `## Troubleshooting`; L135 (was L135 now L160) — `grep -c 'bundles\.php'` returns 4 |

#### Plan 22-04 (examples reorg + saas-demo.md)

| #   | Truth (paraphrased) | Status     | Evidence       |
| --- | ------------------- | ---------- | -------------- |
| 04-1 | `docs/examples/saas-subdomain.md` exists | ✓ VERIFIED | 355 lines, moved via git mv |
| 04-2 | `docs/examples/api-header.md` exists | ✓ VERIFIED | 301 lines, moved via git mv |
| 04-3 | `docs/examples/saas-demo.md` exists as new thin page | ✓ VERIFIED | 69 lines, under the 100-line "thin" cap |
| 04-4 | Old `docs/user-guide/examples/` paths no longer exist | ✓ VERIFIED | `ls docs/user-guide/examples/` returns "No such file or directory" |
| 04-5 | 4 cross-references updated to `../examples/` paths | ✓ VERIFIED | shared-db.md L178, user-guide/index.md L27+L28, database-per-tenant.md L260 — all confirmed |
| 04-6 | saas-demo.md is thin (intro + what this proves + 1-line install + ASCII teaser + canonical link) | ✓ VERIFIED | Structure at L1-69; canonical link at L62 `[examples/saas/README.md](../../examples/saas/README.md)` |
| 04-7 | saas-demo.md does NOT duplicate the 250-line canonical walkthrough | ✓ VERIFIED | 69 lines vs canonical's 181 lines; no smoke.sh body, no full fallback ladder, no Mailpit walkthrough |

#### Plan 22-05 (yellow page refresh — 4 pages)

| #   | Truth (paraphrased) | Status     | Evidence       |
| --- | ------------------- | ---------- | -------------- |
| 05-1 | getting-started.md has three teaser subsections cross-linking to Origin/Profiler/Mailer pages | ✓ VERIFIED | L258 `## Beyond the basics` H2 with H3 subsections at L262 (Origin), L270 (Profiler), L276 (Mailer); each ends with "Full guide → [page](...)" |
| 05-2 | getting-started.md preserves install-and-config flow | ✓ VERIFIED | Pre-existing H2 sections (Prerequisites, Choose Your Driver, Path A, Path B, What Happens on Every Request, Next Steps) all present |
| 05-3 | configuration.md has `### tenancy.origin.allow_list` H3 mirroring origin-header-resolver.md | ✓ VERIFIED | L130 H3 + YAML block at L138-152 |
| 05-4 | configuration.md resolvers table has `origin` row at priority 25 | ✓ VERIFIED | L107 `\| `origin` \| `OriginHeaderResolver` \| 25 \|` |
| 05-5 | configuration.md has `### Per-tenant mailer config` H3 | ✓ VERIFIED | L158 H3 + 30 lines documenting `mailerDsn`/`mailerFrom`/`mailerReplyTo` + `TenantMailerConfigTrait` + link to mailer-bootstrapper.md |
| 05-6 | resolvers.md L2 (now L3) says "five resolvers" | ✓ VERIFIED | L3 `The bundle ships with five resolvers` |
| 05-7 | resolvers.md priority table has OriginHeaderResolver at 25 | ✓ VERIFIED | L18 `\| `OriginHeaderResolver` \| 25 \| Header: `Origin` (browser-locked) \| `tenancy.origin.allow_list` \|` |
| 05-8 | resolvers.md has new `## OriginHeaderResolver` H2 between HostResolver and HeaderResolver | ✓ VERIFIED | L69 H2; L36 HostResolver; L85 HeaderResolver — order confirmed; L81 has `[Full Trust Model →](origin-header-resolver.md#trust-model)` |
| 05-9 | cli-commands.md has `## tenancy:install` H2 as headline | ✓ VERIFIED | L9 `## tenancy:install` is the first H2 after intro; tenancy:init demoted in framing (kept at peer L57 per Option A choice) |
| 05-10 | cli-commands.md tenancy:init demoted to peer or sub-section (Option A taken) | ✓ VERIFIED | L57 `## tenancy:init` remains as peer H2 — Option A explicitly chosen and documented in SUMMARY |
| 05-11 | cli-commands.md `tenancy:migrate` and `tenancy:run` sections NOT touched | ✓ VERIFIED | L124 `## tenancy:migrate` + L185 `## tenancy:run` both still present; content preserved per SUMMARY's byte-identical claim |
| 05-12 | Anchor slug `tenancy-install` exists for cross-links from installation.md + mailer-bootstrapper.md | ✓ VERIFIED | H2 `## tenancy:install` generates canonical mkdocs Material slug `tenancy-install`; consumed by installation.md L25 and mailer-bootstrapper.md L128 + L135 |

#### Plan 22-06 (integration: roadmap mirror + mkdocs nav + docs-lint)

| #   | Truth (paraphrased) | Status     | Evidence       |
| --- | ------------------- | ---------- | -------------- |
| 06-1 | docs/roadmap.md exists with full canonical content | ✓ VERIFIED | 45 lines (`# Roadmap` H1 + Shipped/In progress/Next/Planned/Future/Want something here? sections); contains `v0.3 Adoption Surface` text at L9 |
| 06-2 | Repo-root ROADMAP.md slimmed to ~5-12 lines pointer | ✓ VERIFIED | 7 lines, includes docs-site URL `https://danplaton4.github.io/tenancy-bundle/roadmap/` at L5 |
| 06-3 | README.md `## Roadmap` section links to docs-site URL | ✓ VERIFIED | L188 H2 + L190 `[roadmap on the documentation site](https://danplaton4.github.io/tenancy-bundle/roadmap/)` |
| 06-4 | README.md L12 badge area links to docs-site URL (or repo-root pointer — discretion) | ✓ VERIFIED | L12 badge line: `[Roadmap](https://danplaton4.github.io/tenancy-bundle/roadmap/)` — chosen docs-site URL per recommendation |
| 06-5 | mkdocs.yml nav adds profiler-tab.md + origin-header-resolver.md + mailer-bootstrapper.md; new Examples + Roadmap | ✓ VERIFIED | mkdocs.yml L76 (Origin), L78 (Mailer), L81 (Profiler) under User Guide; L84-87 new top-level Examples (3 entries); L105 new top-level Roadmap |
| 06-6 | mkdocs.yml old nested `Examples:` under User Guide REMOVED | ✓ VERIFIED | No `user-guide/examples/` references in mkdocs.yml; nav is clean |
| 06-7 | docs/index.md has link to new Roadmap page | ✓ VERIFIED | L64 `[:octicons-arrow-right-24: Roadmap](roadmap.md){ .md-button }` |
| 06-8 | scripts/docs-lint.sh has new D-15 check with whitelist | ✓ VERIFIED | L44-89 — awk pattern + whitelist comment block; whitelist explicitly lists `Migration\|Upgrade\|Manual setup\|Troubleshooting\|Do I have to do anything?\|tenancy:install` |
| 06-9 | `mkdocs build --strict` exits 0 | ? UNCERTAIN | mkdocs is NOT installed locally on this machine (`which mkdocs` returns nothing). Plan 22-06 Task 6 SUMMARY acknowledged this and deferred to CI (`.github/workflows/docs.yml` line 39). All 20 files referenced in the new mkdocs nav have been verified to exist on disk; cross-link slugs (`tenancy-install`, `trust-model`) are confirmed. Routed to human verification. |
| 06-10 | `bash scripts/docs-lint.sh` exits 0 | ✓ VERIFIED | I ran it: exit 0, output `docs-lint: OK — no stale v0.1 terms in docs/ or tenancy:init command, and no bundles.php install-path regressions.` |
| 06-11 | Resolver count in docs/index.md L72 (was `**4 built-in resolvers**`) updated to 5 | ✓ VERIFIED | L74 reads `**5 built-in resolvers**` (table row, not L72 — file structure shifted from Plan 01 edits but the fix applied correctly) |

**Score:** 35/36 truths VERIFIED, 1 routed to human verification (mkdocs --strict).

---

### Required Artifacts Verification

| Artifact | Status | Lines | Notes |
| -------- | ------ | ----- | ----- |
| `composer.json` | ✓ VERIFIED | 76 | nikic in require (L22) + require-dev (L38); not in suggest (L49-57) |
| `docs/user-guide/installation.md` | ✓ VERIFIED | 107 | One-command flow at L9-23 |
| `docs/index.md` | ✓ VERIFIED | 138 | Quick Start at L14-23; resolver count `**5**` at L74 |
| `UPGRADE.md` | ✓ VERIFIED | 359 | New `## 0.3.2 to 0.3.3` at L3; existing `## 0.2 to 0.3` at L115 preserved |
| `docs/user-guide/profiler-tab.md` | ✓ VERIFIED | 177 | 3-state ASCII renders at L79-129 |
| `docs/user-guide/mailer-bootstrapper.md` | ✓ VERIFIED | 138 (new) | 4 D-14 parts + See Also |
| `docs/examples/saas-subdomain.md` | ✓ VERIFIED | 355 | Moved via git mv from `docs/user-guide/examples/` |
| `docs/examples/api-header.md` | ✓ VERIFIED | 301 | Moved via git mv from `docs/user-guide/examples/` |
| `docs/examples/saas-demo.md` | ✓ VERIFIED | 69 (new) | Thin walkthrough |
| `docs/user-guide/getting-started.md` | ✓ VERIFIED | 291 | "Beyond the basics" H2 at L258 |
| `docs/user-guide/configuration.md` | ✓ VERIFIED | 322 | New origin allow_list H3 + 5th resolver row + per-tenant mailer H3 |
| `docs/user-guide/resolvers.md` | ✓ VERIFIED | 308 | "Five resolvers" + new OriginHeaderResolver H2 + priority-table row |
| `docs/user-guide/cli-commands.md` | ✓ VERIFIED | 240 | tenancy:install H2 at L9; migrate/run sections preserved |
| `docs/roadmap.md` | ✓ VERIFIED | 45 (new) | Canonical roadmap |
| `ROADMAP.md` | ✓ VERIFIED | 7 (slimmed) | Pointer to docs-site URL |
| `README.md` | ✓ VERIFIED | 198 | L12 badge + L188 H2 both point to docs-site roadmap URL |
| `mkdocs.yml` | ✓ VERIFIED | 110 | Nav reorganized; 3 new User Guide entries + top-level Examples + Roadmap |
| `scripts/docs-lint.sh` | ✓ VERIFIED | 95 | D-15 awk check at L44-89 |

---

### Key Link Verification

| From | To | Status | Details |
| ---- | --- | ------ | ------- |
| `composer.json#require` | `nikic/php-parser` on Packagist | ✓ WIRED | Line 22, version `^5.0` |
| `docs/user-guide/installation.md` L25 | `cli-commands.md#tenancy-install` | ✓ WIRED | `## tenancy:install` H2 at cli-commands.md L9; canonical mkdocs Material slug `tenancy-install` |
| `docs/user-guide/mailer-bootstrapper.md` L128/L135 | `cli-commands.md#tenancy-install` | ✓ WIRED | Same anchor as above |
| `docs/user-guide/resolvers.md` L81 | `origin-header-resolver.md#trust-model` | ✓ WIRED | `## Trust Model` H2 exists in origin-header-resolver.md (grep confirmed) |
| `docs/user-guide/profiler-tab.md` L67 | `../../examples/saas/README.md` | ⚠ CROSS-TREE | Relative path from `docs/user-guide/` → `examples/saas/README.md` (outside docs/ root). MkDocs handles this as a static link; works in repo browser; may 404 on docs site (acceptable trade-off per RESEARCH §Landmines #1) |
| `docs/examples/saas-demo.md` L62 | `../../examples/saas/README.md` | ⚠ CROSS-TREE | Same pattern as above |
| `ROADMAP.md` L5 | `https://danplaton4.github.io/tenancy-bundle/roadmap/` | ✓ WIRED | URL points to docs/roadmap.md after docs build |
| `README.md` L12 | `https://danplaton4.github.io/tenancy-bundle/roadmap/` | ✓ WIRED | Same target |
| `README.md` L190 | `https://danplaton4.github.io/tenancy-bundle/roadmap/` | ✓ WIRED | Same target |
| `docs/user-guide/index.md` L27-28 | `../examples/saas-subdomain.md` + `../examples/api-header.md` | ✓ WIRED | Both files exist post-move |
| `docs/user-guide/shared-db.md` L178 | `../examples/api-header.md` | ✓ WIRED | Target exists |
| `docs/user-guide/database-per-tenant.md` L260 | `../examples/saas-subdomain.md` | ✓ WIRED | Target exists |
| `docs/index.md` L64 | `roadmap.md` | ✓ WIRED | Relative link inside docs/ |
| `UPGRADE.md` L215 | `docs/user-guide/mailer-bootstrapper.md` | ✓ WIRED | Target exists |

---

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
| -------- | ------- | ------ | ------ |
| docs-lint passes | `bash scripts/docs-lint.sh` | exit 0, output "docs-lint: OK — no stale v0.1 terms..." | ✓ PASS |
| composer.json validates | `composer validate --no-check-publish` | exit 0 (one expected warning about nikic in require + require-dev — intentional per D-09) | ✓ PASS |
| Zero `bundles.php` on install path | `grep -c 'bundles\.php' docs/user-guide/installation.md docs/index.md` | 0 + 0 | ✓ PASS |
| `bundles.php` preserved in whitelisted sections | `grep -c 'bundles\.php' docs/user-guide/profiler-tab.md docs/user-guide/cli-commands.md` | 4 + 7 (under whitelisted H2s — verified by lint exit 0) | ✓ PASS |
| 5-resolver consistency | `grep -E 'five resolvers\|5 built-in' docs/user-guide/resolvers.md docs/index.md README.md` | match in all 3 | ✓ PASS |
| Zero old example paths | `grep -rnE '\(examples/saas-subdomain\.md\)\|\(examples/api-header\.md\)\|user-guide/examples/' docs/` | (zero matches, exit 1) | ✓ PASS |
| `mkdocs build --strict` | `mkdocs build --strict` | `mkdocs not found` (not installed locally) | ? SKIP — routed to human/CI |
| Source-order of UPGRADE.md versions | `grep -nE '^## 0' UPGRADE.md` | 3:0.3.3 → 45:0.3.2 → 115:0.3 → 217:0.2 | ✓ PASS |

---

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
| ----------- | ---------- | ----------- | ------ | -------- |
| DOC-19 | 22-01..22-06 | Documentation reflects everything v0.3 ships. Install page replaces "manually add to `bundles.php`" with `tenancy:install`; new pages for `OriginHeaderResolver`, Profiler tab, and Mailer bootstrapper; demo walkthrough; public roadmap page on the docs site mirroring `ROADMAP.md` at the repo root. | ✓ SATISFIED | All 6 SC closed; install page rewritten (SC1); new pages for Origin/Profiler/Mailer wired into nav (SC2); demo walkthrough at docs/examples/saas-demo.md (SC3); docs/roadmap.md canonical + repo-root pointer (SC4); UPGRADE.md 0.2→0.3 Mailer BC section verified intact (SC5); docs-lint extended with bundles.php install-path guard (SC6). |

No orphaned requirements: `.planning/REQUIREMENTS.md` maps only DOC-19 to Phase 22.

---

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
| ---- | ---- | ------- | -------- | ------ |
| `docs/index.md` | L77 | Features table still lists `CLI commands: tenancy:init, tenancy:migrate, and tenancy:run` — missing `tenancy:install` | ℹ Info | Polish gap. README.md L109 correctly lists all 4 (including install). Inconsistency between landing-page Features table and README; not in any must-have. Recommend follow-up. |
| `docs/index.md` | L92 | "How It Works" diagram shows ResolverChain as `(Host / Header / QueryParam / Console)` — missing Origin | ℹ Info | Same class of inconsistency; the diagram pre-dates v0.3 and would benefit from an Origin entry to match the 5-resolver framing established elsewhere. Out of scope for explicit must-haves but recommended polish for v0.3.3. |
| `tests/Unit/Composer/ComposerJsonContractTest.php` | (auto-fix Rule 3 per Plan 22-01 SUMMARY) | Test inverted to match new contract | ℹ Info | Plan 22-01 SUMMARY documents this auto-fix. Required because the original test guarded the Phase 18 contract that Phase 22 explicitly reverses. Inversion is correct and the new test still asserts the load-bearing invariants (nikic in require with ^5.x pin, in require-dev with ^5.x pin, absent from suggest). |

No 🛑 Blockers found. No `TBD`/`FIXME`/`XXX` markers introduced by this phase in modified files (spot-check across the 18 modified files).

---

### Human Verification Required

#### 1. mkdocs --strict build

**Test:** Run `mkdocs build --strict` against the full nav
**Expected:** Exit 0 — every nav entry resolves, every cross-link anchor exists, no broken internal links
**Why human:** `mkdocs` is not installed on this verifier machine (`which mkdocs` returns nothing). Plan 22-06 Task 6 acknowledged this and deferred to CI (`.github/workflows/docs.yml` line 39). All 20 files referenced in the new mkdocs nav have been verified to exist on disk; cross-link slugs (`tenancy-install`, `trust-model`) verified. **Recommended:** push to master, let CI run, or locally `pip install -r docs/requirements.txt && mkdocs build --strict`.

#### 2. Visual rendering of Profiler ASCII panels

**Test:** Render the three Profiler ASCII panels in MkDocs Material dark+light themes
**Expected:** Box-drawing characters (`┌─ ─┐`) align visually; tenant slug / FQCN columns don't wrap awkwardly; pymdownx fenced `text` blocks render with monospace font
**Why human:** Visual rendering of box-drawing characters depends on the browser monospace font, the user's font-size setting, and how MkDocs Material wraps long FQCN lines. Plan 22-03 D-01 explicitly flagged this as Claude-discretion; grep verifies the characters are present, but only an eye can confirm the result reads cleanly.

#### 3. Verify docs-site roadmap URL resolves

**Test:** Open https://danplaton4.github.io/tenancy-bundle/roadmap/ once the next push lands and CI publishes
**Expected:** Canonical 45-line roadmap renders; CHANGELOG link resolves to GitHub; no 404 on the published URL
**Why human:** The roadmap pointer chain (README badge → docs-site URL → docs/roadmap.md) was wired but the docs-site URL is a future GitHub Pages publish target. Until the next push triggers the docs build + publish, the URL returns 404. Only post-publish human navigation can confirm the chain is fully wired end-to-end.

#### 4. Verify saas-demo.md cross-tree links render correctly

**Test:** Verify the cross-tree link in docs/examples/saas-demo.md (`../../examples/saas/README.md`) and the same link in docs/user-guide/profiler-tab.md (`../../examples/saas/README.md`) work as expected on the published docs site
**Expected:** Link either resolves to the rendered demo README (if mkdocs picked up cross-tree files) or fails gracefully (per RESEARCH §Landmines #1 — acceptable trade-off if the link 404s on docs site but works on GitHub UI)
**Why human:** MkDocs strict mode tolerates cross-tree file links but they may or may not render as working anchors on the published site. Only browsing the published site or running `mkdocs build --strict` confirms.

---

### Gaps Summary (Polish Items, Not Blockers)

**Minor inconsistencies found** (not blocking the phase goal — DOC-19 fully satisfied):

1. **docs/index.md L77 Features table — CLI commands row** does not include `tenancy:install`. README.md L109 correctly lists all 4. This is a polish inconsistency that the planner may want to address as a quick follow-up to maintain the "one-command headline" framing across all surface pages.

2. **docs/index.md L92 "How It Works" diagram** lists 4 resolvers (`Host / Header / QueryParam / Console`) — missing Origin. Resolvers.md and configuration.md correctly list 5; the diagram is out of sync. Same polish category as #1.

Neither item is a must-have failure. Both are recommended for a v0.3.3 polish pass but do not block the phase goal or tag readiness.

---

## Re-verification Eligibility

This is the initial verification. If gaps are addressed via a follow-up plan, the re-verification mode will:
- Re-check items 1-2 above (Features table + How It Works diagram)
- Re-run `mkdocs build --strict` via CI on subsequent push
- Maintain VERIFIED status for the 35/36 truths already verified

---

_Verified: 2026-05-28T16:44:29Z_
_Verifier: Claude (gsd-verifier)_
