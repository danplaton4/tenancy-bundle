---
phase: 22-docs-refresh
plan: 05
subsystem: docs
tags: [docs, yellow-page-refresh, user-guide, v0.3.3]
requires: []
provides:
  - "getting-started.md teasers cross-link to origin-header-resolver.md / profiler-tab.md / mailer-bootstrapper.md (D-17)"
  - "configuration.md has `tenancy.origin.allow_list` H3 + 5th resolver row at priority 25 + Per-tenant mailer config H3 (D-18)"
  - "resolvers.md lists 5 resolvers, has a new OriginHeaderResolver H2 between HostResolver and HeaderResolver, with cross-link to origin-header-resolver.md#trust-model (D-19)"
  - "cli-commands.md has tenancy:install as the headline H2 with slug `tenancy-install` for Plan 22-01 / 22-03 cross-links (D-20)"
affects:
  - "Cross-page references inside docs/user-guide/ — Plan 22-06 mkdocs strict-build will validate"
tech-stack:
  added: []
  patterns: ["MkDocs Material default slug rules", "byte-identical YAML mirror to avoid drift"]
key-files:
  created:
    - .planning/phases/22-docs-refresh/22-05-SUMMARY.md
  modified:
    - docs/user-guide/getting-started.md
    - docs/user-guide/configuration.md
    - docs/user-guide/resolvers.md
    - docs/user-guide/cli-commands.md
decisions:
  - "D-20: chose Option A — kept `tenancy:init` as peer H2 (not demoted to H3). Reason: init has standalone uses (regenerating tenancy.yaml without re-registering the bundle); peer-H2 preserves any external anchor links to `#tenancy-init`; the intro at L3-7 frames `tenancy:install` as the headline."
  - "D-18 YAML mirror: configuration.md L132-145 YAML block is byte-identical to origin-header-resolver.md L21-35. Verified by `diff` — no drift between the two pages."
  - "D-19 secondary fix: updated the 'Custom resolvers always pass through' note at L205 from 'four built-in resolvers' to 'five built-in resolvers' so the doc is internally consistent after adding OriginHeaderResolver. Applied under deviation Rule 1 (factual correctness within scope of D-19)."
metrics:
  completed_date: 2026-05-28
  duration: ~15min
  tasks: 4
  files_modified: 4
---

# Phase 22 Plan 05: Yellow page refresh Summary

Surgical additions to four existing User Guide pages so v0.3 features (Origin resolver, Profiler tab, per-tenant Mailer, one-command install) are discoverable from the established entry points — without rewriting any of the pages' install/config flow.

## What shipped

| File | Lines added | Edit |
|------|-------------|------|
| `docs/user-guide/getting-started.md` | +28 | New `## Beyond the basics` H2 with three H3 teasers (Origin / Profiler / Mailer), each ending with a "Full guide →" cross-link. Inserted between `## What Happens on Every Request` and `## Next Steps` per RESEARCH §"File Shape Survey #10". Existing install-and-config flow at L25-218 preserved verbatim. |
| `docs/user-guide/configuration.md` | +62 | (a) New H3 `` ### `tenancy.origin.allow_list` `` with YAML block byte-identical to `origin-header-resolver.md` § Configuration. (b) Resolvers table at L104-110 grew from 4 rows to 5 (new `origin` row at priority 25, slotted between `host`/30 and `header`/20). (c) New H3 `### Per-tenant mailer config` documenting `mailerDsn` / `mailerFrom` / `mailerReplyTo` + `TenantMailerConfigTrait` + cross-link to `mailer-bootstrapper.md`. |
| `docs/user-guide/resolvers.md` | +20 / -3 | (a) Intro L3 bumped from "four resolvers" to "five resolvers". (b) Resolver Priority Table at L15-21 gained a row for `OriginHeaderResolver` at priority 25. (c) New `## OriginHeaderResolver` H2 at L69-81 between `## HostResolver` and `## HeaderResolver`, with 1-paragraph trust-model summary and cross-link to `origin-header-resolver.md#trust-model` (anchor verified to exist at L52 of the dedicated page). (d) Secondary: updated "four built-in resolvers" → "five built-in resolvers" at L205 for internal consistency. |
| `docs/user-guide/cli-commands.md` | +56 / -3 | Intro reframed: "four commands … one-shot setup command". New `## tenancy:install` H2 at L9-56 (headline) with usage, flags (`--dry-run`, `--force`), behavior on non-standard `bundles.php`, idempotency notes, cross-link to `installation.md`. `## tenancy:init` kept as peer H2 (see D-20 decision). `## tenancy:migrate` and `## tenancy:run` sections byte-identical to pre-edit state (verified by `diff`). |

**Total:** +163 / -6 lines across 4 files; 4 atomic commits (one per file).

## Commits

| Hash | Task |
|------|------|
| `cb8b83c` | docs(22-05): add Origin/Profiler/Mailer teasers to getting-started.md (D-17) |
| `fffe478` | docs(22-05): add origin.allow_list + mailer config to configuration.md (D-18) |
| `e61e67f` | docs(22-05): add OriginHeaderResolver as 5th resolver in resolvers.md (D-19) |
| `270c27a` | docs(22-05): promote tenancy:install to headline in cli-commands.md (D-20) |

## Verification

All grep gates from the plan pass:

```
docs/user-guide/getting-started.md:
  origin-header-resolver / profiler-tab / mailer-bootstrapper  -> 3 matches (1 each)
  H2 sections preserved (Prerequisites, Choose Your Driver, Path A, Path B,
    What Happens on Every Request, Next Steps)                 -> 6

docs/user-guide/configuration.md:
  ### `tenancy.origin.allow_list` H3                           -> 1 match (L130)
  ### Per-tenant mailer config H3                              -> 1 match (L158)
  `origin` row in resolvers table at priority 25               -> L107
  mailerDsn / TenantMailerConfigTrait / mailer-bootstrapper.md -> all present
  YAML block byte-identical to origin-header-resolver.md       -> diff confirms

docs/user-guide/resolvers.md:
  "five resolvers" at L3                                       -> match
  No remaining "four resolvers" or "four built-in"             -> none
  ## OriginHeaderResolver H2                                   -> L69 (exactly 1)
  | `OriginHeaderResolver` | 25 | … |                          -> L18
  origin-header-resolver.md#trust-model                        -> L81
  H2 order: HostResolver → OriginHeaderResolver → HeaderResolver -> verified

docs/user-guide/cli-commands.md:
  ## tenancy:install H2                                        -> L9 (exactly 1)
  ## tenancy:migrate H2                                        -> L124 (1, byte-identical body)
  ## tenancy:run H2                                            -> L185 (1, byte-identical body)
  --dry-run and --force documented                             -> L23, L26, L33, L34
  "four commands" / "one-shot setup" framing                   -> L3

docs/user-guide/origin-header-resolver.md:
  ## Trust Model anchor                                        -> L52 (#trust-model resolves)
```

## Deviations from Plan

### Auto-fixed issues

**1. [Rule 1 - Internal consistency] Updated "four built-in resolvers" → "five built-in resolvers" at resolvers.md L205**
- **Found during:** Task 3
- **Issue:** After adding OriginHeaderResolver as the 5th resolver, the "Custom resolvers always pass through" note at L205 still said the config filters "the four built-in resolvers (`host`, `header`, `query_param`, `console`)" — internally inconsistent.
- **Fix:** Updated to "five built-in resolvers (`host`, `origin`, `header`, `query_param`, `console`)" — matches the priority-table ordering.
- **Files modified:** `docs/user-guide/resolvers.md`
- **Commit:** `e61e67f`
- **Justification:** This is within scope of D-19 (resolver count framing). The plan's "preservation rules" said not to touch other H2 sections; this is one phrase inside an admonition block, not a section header. Internal consistency takes precedence — the alternative would have been to leave a contradictory statement in the same file as the new "five resolvers" intro.

No other deviations. The four tasks executed as planned.

## D-20 option choice (Option A vs Option B)

**Chose Option A** — kept `tenancy:init` as a peer H2 (not demoted to `### tenancy:init` under `## tenancy:install`).

**Reasoning:**
- `tenancy:init` has standalone uses (regenerating `config/packages/tenancy.yaml` without re-registering the bundle) per CONTEXT D-20 itself
- Peer-H2 preserves any external anchor links to `cli-commands.md#tenancy-init` that may already exist in older docs, blog posts, or issue threads
- The intro at L3-7 ("four commands: a one-shot setup command — `tenancy:install` — that … plus three subcommands") frames `tenancy:install` as the headline; physical ordering (install at L9, init at L57) reinforces this
- The new `tenancy:install` § ends with "See also: [Installation](installation.md) for the full v0.3.3 one-command install flow", giving readers a natural exit to the install page
- Option B (demote init to H3) would have created a `### Manual config scaffold` heading whose anchor slug differs from `tenancy-init` and broken those external links

The planner explicitly delegated this choice to Claude's discretion; Option A is the recommended path per the plan's own framing ("Option A is simpler and preserves anchor links").

## Cross-link integrity

All cross-link targets confirmed to exist:

| Source | Target | Resolves? |
|--------|--------|-----------|
| `getting-started.md` "Resolving tenants from SPA Origin headers" | `origin-header-resolver.md` | ✓ exists |
| `getting-started.md` "Inspecting the active tenant in dev" | `profiler-tab.md` | ✓ exists |
| `getting-started.md` "Per-tenant mailer config" | `mailer-bootstrapper.md` | ✓ exists (created Plan 22-03) |
| `configuration.md` "Per-tenant mailer config" | `mailer-bootstrapper.md` | ✓ exists |
| `configuration.md` allow_list note | `origin-header-resolver.md` | ✓ exists |
| `resolvers.md` OriginHeaderResolver H2 | `origin-header-resolver.md#trust-model` | ✓ anchor at L52 |
| `cli-commands.md` tenancy:install "See also" | `installation.md` | ✓ exists |

Plan 22-06 will run `mkdocs build --strict` against the full nav after Wave 1 lands; any anchor slug mismatch will surface there.

## Threat surface scan

No new threat surface introduced. All edits are documentation prose + table rows + YAML examples (using sanitized `smtp://user:****@smtp.example.com:587` placeholders per T-22-12). No new network endpoints, auth paths, file access patterns, or schema changes.

## Self-Check: PASSED

- All 4 files modified per plan (`git diff cb8b83c^..270c27a --stat -- docs/user-guide/` confirms)
- All 4 commits exist in git log:
  - `cb8b83c` getting-started.md — FOUND
  - `fffe478` configuration.md — FOUND
  - `e61e67f` resolvers.md — FOUND
  - `270c27a` cli-commands.md — FOUND
- All grep gates pass (see Verification section)
- YAML mirror verified byte-identical via `diff`
- `tenancy:migrate` and `tenancy:run` sections byte-identical to pre-edit state via `diff`
- `## Trust Model` anchor in origin-header-resolver.md verified at L52
