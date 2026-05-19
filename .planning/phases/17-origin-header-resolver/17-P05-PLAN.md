---
id: 17-P05
phase: 17
plan: 05
name: Docs page + CHANGELOG entry
wave: 3
depends_on: [17-P03]
files_modified:
  - docs/user-guide/origin-header-resolver.md
  - CHANGELOG.md
autonomous: true
requirements: [RESV-06]
threats: [T-17-01]
must_haves:
  truths:
    - "`docs/user-guide/origin-header-resolver.md` exists with five required sections in order: Overview, Configuration, Trust Model, Mismatch Warning, Examples"
    - "The Trust Model section contains the verbatim sentence `Origin is a browser-locked routing hint, not an authentication credential. Pair this resolver with your real auth layer.`"
    - "The Trust Model section explicitly warns that Origin is trivially spoofable from curl/Postman/native mobile/server-to-server clients"
    - "The Configuration section shows both the explicit map form `{origin: '...', slug: '...'}` AND the wildcard shorthand string form"
    - "The Mismatch Warning section displays a sample structured log payload with keys origin, origin_slug, header_slug, winner"
    - "`CHANGELOG.md` Unreleased section has an Added bullet announcing the OriginHeaderResolver"
  artifacts:
    - path: docs/user-guide/origin-header-resolver.md
      provides: "User-facing resolver guide with mandatory Trust Model section"
      min_lines: 80
    - path: CHANGELOG.md
      provides: "Updated Unreleased section with OriginHeaderResolver entry"
      contains: "OriginHeaderResolver"
  key_links:
    - from: docs/user-guide/origin-header-resolver.md
      to: src/Resolver/OriginHeaderResolver.php
      via: "documents the resolver's public-facing config + runtime behavior"
      pattern: "tenancy.origin.allow_list"
---

<objective>
Ship the user-facing docs page `docs/user-guide/origin-header-resolver.md` and add a CHANGELOG.md entry for the OriginHeaderResolver. The docs page is a phase deliverable per RESV-06 acceptance criterion 5 (dedicated Trust Model docs section); the CHANGELOG entry is part of bundle release hygiene.

Purpose: Phase 17 success criterion 5 — Trust Model section MUST ship with the code, not be deferred to Phase 22 DOC-19 (per D-20). Phase 22 wires nav/cross-page integration; Phase 17 ships the page content.

Output: Two file edits (one new docs page, one CHANGELOG update). No code changes. Can run in parallel with Plan 04 (no file overlap).
</objective>

<execution_context>
@/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/.claude/get-shit-done/workflows/execute-plan.md
@/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/phases/17-origin-header-resolver/17-CONTEXT.md
@CLAUDE.md
@docs/user-guide/resolvers.md
@CHANGELOG.md
</context>

<tasks>

<task type="auto">
  <name>Task 1: Author docs/user-guide/origin-header-resolver.md</name>
  <files>docs/user-guide/origin-header-resolver.md</files>
  <read_first>
    - docs/user-guide/resolvers.md (existing page tone, structure, MkDocs Material conventions, table style, code-fence style)
    - .planning/phases/17-origin-header-resolver/17-CONTEXT.md decisions D-18, D-20, D-21, plus the specifics block at lines 222-237 for the verbatim sentence and example payloads
    - src/Resolver/OriginHeaderResolver.php (Plan 01 output — source of truth for the runtime behavior the docs describe)
  </read_first>
  <action>
Create `docs/user-guide/origin-header-resolver.md` with the exact content below. Required structure: five top-level sections (Overview, Configuration, Trust Model, Mismatch Warning, Examples) in that order, with exactly those H2 headings.

The verbatim sentence locked by CONTEXT.md `<specifics>` line 225 — keep character-for-character: `Origin is a browser-locked routing hint, not an authentication credential. Pair this resolver with your real auth layer.`

The structured log payload example MUST include all four keys: `origin`, `origin_slug`, `header_slug`, `winner`.

Use MkDocs Material flavor markdown (already used by sibling pages in `docs/user-guide/`). Code fences need language hints (`yaml`, `javascript`, `json`).

DO NOT add `mkdocs.yml` nav entries — Phase 22 DOC-19 owns nav wiring (D-20). Just create the page file.

DO NOT add cross-links from other docs pages.

Full content to write:

````markdown
# OriginHeaderResolver

SPA-friendly tenant resolver that reads the browser-set `Origin` HTTP header and matches it against a configurable allow-list. Sits in the resolver chain at priority **25** — above `HeaderResolver` (20), below `HostResolver` (30).

---

## Overview

When a single-page app at `https://acme.app.example.com` makes a cross-origin XHR/fetch call to your API at `https://api.example.com`, the browser stamps the request with `Origin: https://acme.app.example.com`. `OriginHeaderResolver` reads that header, looks the origin up in an allow-list configured under `tenancy.origin.allow_list`, and resolves the tenant — no extra header, no query param, no subdomain routing required.

The resolver is **opt-in**: it is not in the default `tenancy.resolvers` list. Enable it by adding `'origin'` to your resolver chain and configuring at least one allow-list entry.

Priority 25 means Origin wins over `X-Tenant-ID` (20) when both are set in the same request — see [Mismatch Warning](#mismatch-warning) below — and loses to subdomain-based `HostResolver` (30) when both are configured.

---

## Configuration

Add `'origin'` to your resolvers list and define the allow-list:

```yaml
# config/packages/tenancy.yaml
tenancy:
  resolvers: ['host', 'header', 'origin']
  origin:
    allow_list:
      # Explicit map form: pin a specific origin to a specific tenant slug.
      - { origin: 'https://acme.app.example.com', slug: 'acme' }
      - { origin: 'https://beta.app.example.com', slug: 'beta-customer' }

      # Wildcard shorthand: leftmost label becomes the slug at runtime.
      # `https://*.app.example.com` matches `https://anything.app.example.com`
      # and resolves to tenant slug = `anything`.
      - 'https://*.app.example.com'
```

### Allow-list entry rules

| Rule | Why |
|------|-----|
| Origin must be an absolute URL `scheme://host[:port]` | RFC 6454 — origins are bare authorities |
| Scheme must be `http` or `https` | Browsers only set `Origin` for these schemes |
| Port defaults to `80` for `http`, `443` for `https` when omitted | Normalized at compile time so runtime matching is exact-equality |
| Exactly one `*` allowed, in the **leftmost label only** | Mid-string wildcards (`app.*.example.com`) are silently permissive — rejected at compile time |
| No path, query, or fragment | Origins have no path component per RFC 6454 |
| Non-wildcard entries require an explicit `slug` | Wildcard entries derive slug from the matched label |

Invalid configurations fail at **container compile time** with a descriptive error naming the offending entry. There is no way to ship a misconfigured allow-list to runtime.

---

## Trust Model

> **Origin is a browser-locked routing hint, not an authentication credential. Pair this resolver with your real auth layer.**

### Where the `Origin` header comes from

For cross-origin XHR, `fetch()`, and CORS preflight requests, the browser sets `Origin` itself. JavaScript running in the page **cannot override** `Origin` — it is in the "forbidden header name" list of the Fetch standard. This makes `Origin` a strong tenant-routing signal **inside a browser context**: a tab origin-locked to `https://acme.app.example.com` will always send that exact `Origin` value, regardless of what the page's JavaScript wishes.

### Where the trust ends

`Origin` is **trivially settable from non-browser clients**:

- `curl -H 'Origin: https://acme.app.example.com' …` works
- Postman, Insomnia, HTTPie, native mobile (NSURLSession, OkHttp), server-to-server clients — all can forge `Origin` freely
- Bots and scrapers can spoof `Origin` to whatever value gets the response they want

Treat `OriginHeaderResolver` as a **routing convenience for browser SPAs**. Always pair it with your real authentication layer — Bearer tokens, cookies with CSRF protection, signed requests — for any endpoint that does anything sensitive. The resolver picks the tenant; your auth picks the user.

### Failure-safe by default

Two design choices make misconfiguration impossible-to-ship rather than dangerous-at-runtime:

1. **Opt-in** — Origin is not in the default resolvers list. Adding it is an explicit choice.
2. **Empty allow-list = compile error** — A YAML file with `'origin'` in resolvers but no `allow_list` entries fails at container build, not at runtime. There is no degenerate state where the resolver silently accepts every origin or silently rejects every origin.

### A note on `http://`

Both `http://` and `https://` are permitted in allow-list entries because local SPA dev servers (`http://localhost:3000`, `http://localhost:5173`) need to route to a dev tenant. **Mixing `http://` and `https://` origins in a production allow-list is a security smell** — production traffic should be HTTPS-only and your allow-list should reflect that.

---

## Mismatch Warning

When a request arrives with **both** a matching `Origin` header AND an `X-Tenant-ID` header whose slug differs from the Origin-resolved slug, `OriginHeaderResolver`:

1. Resolves the tenant from `Origin` (Origin wins because priority 25 > HeaderResolver's 20).
2. Emits a `warning`-level PSR-3 log record so operators can detect routing-confusion attempts.

The log payload is intentionally structured:

```json
{
  "level": "warning",
  "message": "Origin/X-Tenant-ID mismatch — Origin wins",
  "context": {
    "origin": "https://acme.app.example.com",
    "origin_slug": "acme",
    "header_slug": "beta",
    "winner": "origin"
  }
}
```

Wire this into your normal log pipeline; alert if the warning rate exceeds a baseline. Slug comparison is case-insensitive — `acme` and `ACME` are treated as the same tenant for the purposes of this check.

---

## Examples

### A React SPA at `app.example.com` calling an API at `api.example.com`

```yaml
tenancy:
  resolvers: ['origin']
  origin:
    allow_list:
      - 'https://*.app.example.com'  # any tenant subdomain
```

```javascript
// In the SPA — origin is set automatically by the browser
fetch('https://api.example.com/users', { credentials: 'include' })
```

### Two named tenants on the same multi-tenant SPA host

```yaml
tenancy:
  resolvers: ['origin', 'header']
  origin:
    allow_list:
      - { origin: 'https://acme.app.example.com', slug: 'acme' }
      - { origin: 'https://contoso.app.example.com', slug: 'contoso' }
```

### Local development with a Vite dev server

```yaml
tenancy:
  resolvers: ['origin']
  origin:
    allow_list:
      - { origin: 'http://localhost:5173', slug: 'dev-tenant' }
      - 'https://*.app.example.com'  # staging/prod still works
```

### CORS preflight requests

`OPTIONS` requests are passed through the resolver chain without any Origin parsing — the resolver returns `null` immediately so preflight never throws or routes to a tenant context. Browser preflight semantics are preserved. Setting `Access-Control-Allow-Origin` is your application's responsibility (typically via `nelmio/cors-bundle` or Symfony's built-in CORS handling).
````
  </action>
  <verify>
    <automated>test -f docs/user-guide/origin-header-resolver.md && [ "$(wc -l < docs/user-guide/origin-header-resolver.md)" -ge 80 ] && grep -q "Origin is a browser-locked routing hint, not an authentication credential. Pair this resolver with your real auth layer." docs/user-guide/origin-header-resolver.md && grep -q "^## Overview" docs/user-guide/origin-header-resolver.md && grep -q "^## Configuration" docs/user-guide/origin-header-resolver.md && grep -q "^## Trust Model" docs/user-guide/origin-header-resolver.md && grep -q "^## Mismatch Warning" docs/user-guide/origin-header-resolver.md && grep -q "^## Examples" docs/user-guide/origin-header-resolver.md</automated>
  </verify>
  <acceptance_criteria>
    - File `docs/user-guide/origin-header-resolver.md` exists
    - File has at least 80 lines: `wc -l docs/user-guide/origin-header-resolver.md` outputs a number >= 80
    - File contains the verbatim sentence `Origin is a browser-locked routing hint, not an authentication credential. Pair this resolver with your real auth layer.`
    - File contains all five required section headings as H2: `## Overview`, `## Configuration`, `## Trust Model`, `## Mismatch Warning`, `## Examples`
    - File contains all four mismatch-warning context keys: `"origin"`, `"origin_slug"`, `"header_slug"`, `"winner"`
    - File contains both shorthand and explicit allow-list entry forms in code fences
    - File mentions `curl`, `Postman`, and at least one native/server-to-server channel as spoofing vectors in the Trust Model section: `grep -E "curl|Postman" docs/user-guide/origin-header-resolver.md` returns matches
    - File describes the OPTIONS-preflight passthrough behavior (grep for `OPTIONS` finds at least one occurrence)
  </acceptance_criteria>
  <done>Docs page exists with all required sections; Trust Model contains the verbatim quote; mismatch-warning payload documented.</done>
</task>

<task type="auto">
  <name>Task 2: Update CHANGELOG.md with v0.3.0 Unreleased entry</name>
  <files>CHANGELOG.md</files>
  <read_first>
    - CHANGELOG.md (current state — first ~30 lines show the empty Unreleased section + previous entry style)
  </read_first>
  <action>
The current `CHANGELOG.md` has an empty `## [Unreleased]` section at the top. Add an `### Added` subsection inside `## [Unreleased]` with two bullets covering Phase 17 deliverables.

Current snippet around lines 1-12:

```
# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.1] — 2026-04-21
```

Replace with:

```
# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **`OriginHeaderResolver`** — SPA-friendly tenant resolver that reads the browser-set
  `Origin` HTTP header, matches it against a configurable allow-list under
  `tenancy.origin.allow_list`, and resolves the tenant. Registered in the resolver chain
  at priority 25 (above `HeaderResolver` 20, below `HostResolver` 30). Opt-in via
  `tenancy.resolvers: ['…', 'origin']`. Supports explicit `{origin, slug}` map entries
  and wildcard shorthand `'https://*.app.example.com'` (slug = leftmost label).
  CORS preflight (`OPTIONS`) requests pass through cleanly; mismatches with
  `X-Tenant-ID` are recorded as `warning`-level PSR-3 log entries with structured
  context. See `docs/user-guide/origin-header-resolver.md` § Trust Model — Origin is
  a routing hint, not an authentication credential.
- **`OriginHeaderResolverConfigPass`** — compile-time guard that rejects empty
  allow-lists, unparseable origin URLs, mid-string wildcards, multi-label wildcards,
  path/query/fragment-bearing origins, and non-wildcard entries missing an explicit
  slug. Misconfiguration fails at container build, not at runtime.

## [0.2.1] — 2026-04-21
```

Order rationale: `### Added` is the conventional Keep a Changelog top-of-section header order (Added > Changed > Deprecated > Removed > Fixed > Security). Phase 17 ships only additions, so only `### Added` is needed.

Do NOT modify the `[0.2.1]` or `[0.2.0]` sections.
  </action>
  <verify>
    <automated>grep -q "^## \[Unreleased\]" CHANGELOG.md && awk '/^## \[Unreleased\]/,/^## \[0.2.1\]/' CHANGELOG.md | grep -q "OriginHeaderResolver" && awk '/^## \[Unreleased\]/,/^## \[0.2.1\]/' CHANGELOG.md | grep -q "OriginHeaderResolverConfigPass" && awk '/^## \[Unreleased\]/,/^## \[0.2.1\]/' CHANGELOG.md | grep -q "### Added"</automated>
  </verify>
  <acceptance_criteria>
    - `## [Unreleased]` header still present and is the first version section in the file
    - Between `## [Unreleased]` and `## [0.2.1]` there is exactly one `### Added` subsection
    - That `### Added` subsection mentions `OriginHeaderResolver` and `OriginHeaderResolverConfigPass` as separate bullets
    - The bullet describing OriginHeaderResolver mentions priority 25, allow-list, and Trust Model docs link
    - The `[0.2.1] — 2026-04-21` and `[0.2.0] — 2026-04-20` sections are untouched: `git diff CHANGELOG.md` shows no edits below line ~30
  </acceptance_criteria>
  <done>CHANGELOG.md Unreleased section now describes Phase 17 deliverables under `### Added`; older entries unchanged.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Adopter reading docs → adopter's mental model of Origin trust | Trust Model section is the only place the spoofability caveat is surfaced before adopters wire the resolver into prod |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-17-01 | Spoofing (curl/Postman/native mobile setting Origin freely) | `OriginHeaderResolver` runtime behavior — fundamentally cannot prevent at code layer | accept (mitigated by docs) | Docs Trust Model section explicitly enumerates spoofing channels (curl, Postman, Insomnia, native mobile, server-to-server) and locks the verbatim sentence "Origin is a browser-locked routing hint, not an authentication credential. Pair this resolver with your real auth layer." — adopter is informed before adoption |
</threat_model>

<verification>
- Static: `test -f docs/user-guide/origin-header-resolver.md`
- Content: `grep`-based assertions in acceptance criteria
- Repo cleanliness: `git diff CHANGELOG.md` should show only additions between Unreleased and 0.2.1
</verification>

<success_criteria>
- `docs/user-guide/origin-header-resolver.md` exists with all 5 required sections and the verbatim Trust Model sentence
- `CHANGELOG.md` Unreleased section enumerates the two new symbols (resolver + compiler pass)
- No source or test files modified by this plan
</success_criteria>

<output>
After completion, create `.planning/phases/17-origin-header-resolver/17-P05-SUMMARY.md` capturing: docs page line count, list of section headings actually written, and any CHANGELOG style deviations (e.g. line wrap width).
</output>
