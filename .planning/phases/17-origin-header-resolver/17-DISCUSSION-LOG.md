# Phase 17: OriginHeaderResolver — Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in `17-CONTEXT.md` — this log preserves the alternatives considered.

**Date:** 2026-05-15
**Phase:** 17-origin-header-resolver
**Mode:** Autonomous (user instruction: "work without stopping for clarifying questions"). No `AskUserQuestion` round-trip; gray areas were enumerated and resolved with documented defaults flagged in `<assumptions>` for redirect.
**Areas resolved autonomously:** Allow-list shape & slug strategy; resolver runtime behavior; container & DI wiring; YAML config schema; documentation scope; testing scope.

---

## Allow-list shape & slug-extraction strategy

| Option | Description | Selected |
|--------|-------------|----------|
| Explicit-only map | Every entry is `{origin, slug}`; no wildcards. Safest, most verbose. | |
| Wildcard-only | Allow-list is suffix patterns (`https://*.app.example.com`); slug always = leftmost label. Minimal config, less safe for one-off origins. | |
| Both — explicit + wildcard shorthand | Object form gives explicit `origin → slug`; shorthand string form is a wildcard that extracts the slug from the leftmost label. | ✓ |

**Resolution rationale:** Mirrors how `HostResolver` extracts subdomain slugs and how SaaS deployments naturally evolve (start with a wildcard for the standard tenant subdomain, add explicit entries for vanity/legacy origins). Compiler pass + normalization keep runtime matcher pure equality + suffix check.

---

## Permitted URL schemes in allow-list

| Option | Description | Selected |
|--------|-------------|----------|
| HTTPS-only | Reject `http://` at compile time. Forces TLS in dev. | |
| Permit both, warn in docs | Allow `http://` and `https://`; docs Trust Model section warns about mixed schemes. | ✓ |
| Permit both, opt-in flag for `http` | Default HTTPS-only; require `tenancy.origin.allow_insecure: true` to permit `http://`. | |

**Resolution rationale:** Local dev with Vite/Next/CRA dev servers uses `http://localhost:5173` etc. Forcing TLS at compile time would push every adopter to set the opt-in flag immediately. Documenting the trust caveat is the right ergonomic trade.

---

## Mismatch-warning mechanism (Origin vs X-Tenant-ID)

| Option | Description | Selected |
|--------|-------------|----------|
| Inline in resolver — textual compare | After successful Origin resolve, peek `X-Tenant-ID`; if present and slug differs textually, log warning. No DB roundtrip. | ✓ |
| Inline in resolver — resolve both, compare entities | Resolve both Origin and Header slugs; compare tenant entity IDs. Extra DB query per request when both headers set. | |
| Separate event subscriber on `TenantResolved` | Decouple from resolver; subscriber reads both headers, fires the warning. Adds a moving part. | |

**Resolution rationale:** Textual mismatch is sufficient for the audit-log intent recorded in RESV-06 acceptance. Avoid the extra query (cost grows with malicious-or-misconfigured clients hammering both headers). Keep the concern local to the resolver so the warning path is obvious in code review.

---

## Resolver default-enable vs opt-in

| Option | Description | Selected |
|--------|-------------|----------|
| Default-on (like Host, Header, QueryParam, Console) | Add `'origin'` to default `tenancy.resolvers` list. Maximum discoverability. | |
| Opt-in via `tenancy.resolvers` config | Stays out of the default list; users add `'origin'` after configuring allow-list. Compiler pass fails build if `'origin'` is in resolvers but allow-list is empty. | ✓ |

**Resolution rationale:** Security-sensitive resolver. Default-on with no allow-list would either silently no-op (bad UX — debugging mystery) or fail container build for every existing adopter post-upgrade (worse). Opt-in is failure-safe: the resolver is only active when the adopter has explicitly configured it, and incomplete config fails at compile time.

---

## Documentation scope for Phase 17 vs Phase 22

| Option | Description | Selected |
|--------|-------------|----------|
| Resolver-specific page now, cross-page integration in Phase 22 | Ship `docs/user-guide/origin-header-resolver.md` with Overview/Configuration/Trust Model/Examples. Phase 22 DOC-19 handles nav, index links, cross-references from install/security pages. | ✓ |
| All docs in Phase 22 | Phase 17 ships code only; Trust Model section lives only in PHPDoc comments until DOC-19. | |
| All docs in Phase 17 | Phase 17 ships the resolver page AND wires nav/index/cross-refs. Bleeds into DOC-19's job. | |

**Resolution rationale:** RESV-06 acceptance explicitly requires a "Trust Model" docs section — must ship with the code. But DOC-19 owns docs structure cohesion; preempting it would force rework when Phase 22 lands.

---

## Configuration node shape

| Option | Description | Selected |
|--------|-------------|----------|
| `tenancy.origin.allow_list[]` mirroring `tenancy.host` | New `origin:` node sibling to `host:`; `allow_list[]` array prototype permitting object-form (explicit map) and string-form (shorthand wildcard); `beforeNormalization` lifts strings into objects. | ✓ |
| Top-level `tenancy.allow_list` | Generic allow-list — couples Origin config to a top-level node. Reads worse and conflates concerns if other resolvers ever need their own allow-list. | |
| Per-resolver config under `tenancy.resolvers` as a structured map | Replaces the current scalar short-name list with a map (`resolvers: { origin: { allow_list: [...] }, ... }`). BC break for existing adopters. | |

**Resolution rationale:** Sibling-to-`host:` shape is the obvious extension; preserves the existing `tenancy.resolvers` scalar list (no BC break).

---

## Claude's Discretion

The following are explicitly flexible during planning/implementation:

- Exact wildcard-matcher internals (regex vs. suffix-strip — suffix-strip recommended for parity with `HostResolver`).
- Named struct vs. typed array for the normalized allow-list entry.
- Whether to extract a private `OriginMatcher` collaborator for testability or keep matching inline.
- PSR-3 log message wording (warning level + structured context shape are locked; the human-readable string is not).
- Compile-pass file location (current convention: `src/DependencyInjection/Compiler/`).

## Deferred Ideas

Captured in `17-CONTEXT.md` `<deferred>`. Summary:

- CORS response handling (`nelmio/cors-bundle` integration → Phase 22 DOC-19 note only).
- Multi-tenant Origin → one origin resolves to N tenants (out of trust model scope; future requirement only).
- Persistent Origin/X-Tenant-ID mismatch audit log (v0.5 Operations milestone).
- `Sec-Fetch-*` header cross-validation (future requirement; capture in backlog if requested).
- Per-tenant CORS allow-list on the `Tenant` entity (v0.5+ if asked).

## Open Items for User Redirect

Per autonomous-mode protocol, the following decisions are flagged in `17-CONTEXT.md` `<assumptions>` for user review BEFORE `/gsd-plan-phase 17` runs:

1. Allow-list shape — both explicit + wildcard shorthand (current pick). Alternatives: explicit-only or wildcard-only.
2. `http://` permitted in allow-list with docs warning. Alternative: HTTPS-only with opt-in flag.
3. Mismatch warning uses textual compare only. Alternative: resolve both slugs (extra query).
4. `'origin'` is opt-in via `tenancy.resolvers`. Alternative: default-enable.
5. Phase 17 ships docs page; Phase 22 wires nav. Alternative: defer all docs.
