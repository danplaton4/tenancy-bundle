# Phase 17: OriginHeaderResolver — Context

**Gathered:** 2026-05-15
**Status:** Ready for planning
**Source:** Heavy upstream lock-in (REQUIREMENTS.md RESV-06 + DEC-RESV-01 + research/SUMMARY.md). Discussion ran autonomously per user instruction; residual gray areas resolved with reasonable defaults — user can redirect any decision before planning.

<domain>
## Phase Boundary

Deliver `OriginHeaderResolver` — a SPA-friendly tenant resolver that reads the browser-locked `Origin` HTTP header and resolves it to the active tenant via a configurable allow-list. Registered in the resolver chain at priority 25 (between `HostResolver` 30 and `HeaderResolver` 20). Ships with a compile-time guard (`OriginHeaderResolverConfigPass`) that rejects invalid allow-list shapes at container build time.

**In scope:**
- New resolver class `Tenancy\Bundle\Resolver\OriginHeaderResolver` implementing `TenantResolverInterface`, tagged `tenancy.resolver` priority **25** (DEC-RESV-01 locked).
- Allow-list configuration node under `tenancy.origin` — parsed-URL exact-equality matching (scheme + host + port), with optional left-most wildcard label support.
- Compile-time guard `OriginHeaderResolverConfigPass` rejecting empty allow-lists, unparseable URLs, and mid-string wildcards.
- `OriginHeaderResolverConfigPass` registered in `TenancyBundle::build()` only when the resolver short-name `origin` is in `tenancy.resolvers`.
- CORS preflight (`OPTIONS`) returns `null` (does not throw) so preflight requests fall through the resolver chain cleanly.
- Cross-resolver mismatch warning when `Origin` and `X-Tenant-ID` resolve to different tenant slugs in the same request.
- Short-name registration: `'origin'` becomes a valid entry in the `tenancy.resolvers` config array (built-in resolver map updated in `ResolverChainPass`).
- New docs page `docs/user-guide/origin-header-resolver.md` with a dedicated **Trust Model** section.
- Unit tests + integration test booting `TestKernel` with the allow-list, asserting end-to-end resolution + preflight + mismatch-warning behavior.

**Out of scope:**
- Origin-to-multiple-tenants ambiguity handling beyond the locked semantics (one origin → one tenant in the allow-list; wildcard label → slug = leftmost label).
- Automatic CORS response handling. This resolver only **reads** the Origin header; setting `Access-Control-Allow-Origin` is the application's responsibility (typically via `nelmio/cors-bundle`).
- DOC-19's cross-page docs refresh (Phase 22). This phase ships just the resolver-specific page.
- Symfony Flex recipe (project non-goal).
- Any change to existing `HeaderResolver`, `HostResolver`, `QueryParamResolver`, or `ConsoleResolver` beyond adding `'origin'` to the resolver short-name registry in `ResolverChainPass`.

**Release target:** v0.3.0 (Phase 17 is the first feature phase of the v0.3 milestone).

</domain>

<spec_lock>
## Locked Requirements (from REQUIREMENTS.md § RESV-06)

These are LOCKED by the requirements doc. Planner MUST NOT re-litigate.

- Implements `TenantResolverInterface`; tagged `tenancy.resolver` priority **25**; mirrors shape of `HeaderResolver`.
- Parsed-URL exact-equality matching: scheme + host + port all must match.
- Allow-list entries permit at most one **left-most** wildcard label (`*.app.example.com` allowed; mid-string wildcards like `app.*.example.com` rejected at compile time).
- Returns `null` on absent `Origin` header — falls through resolver chain.
- Returns `null` on CORS preflight (`OPTIONS`) requests — preflight must not throw.
- Emits a `warning`-level log entry when `Origin` and `X-Tenant-ID` resolve to **different** tenants in the same request.
- `OriginHeaderResolverConfigPass` rejects empty allow-lists and unparseable URLs at container compile time.
- Dedicated "Trust Model" docs section explains `Origin` is browser-protected cross-origin but trivially spoofable from non-browser clients (curl, Postman, mobile).

**Ratified decision:** DEC-RESV-01 — priority **25** (not 10). Rationale: above `HeaderResolver` (20) because `Origin` is browser-locked for cross-origin XHR and thus a stronger signal than `X-Tenant-ID`; below `HostResolver` (30) because explicit subdomain routing still wins when present.

</spec_lock>

<decisions>
## Implementation Decisions

### Allow-list shape & slug-extraction strategy

- **D-01 (Allow-list config):** New config node `tenancy.origin.allow_list[]`. Each entry is one of:
  - **Explicit map form** (object) — `{ origin: 'https://acme.app.example.com', slug: 'acme' }`. Origin is matched exactly (scheme + host + port); slug is the literal tenant slug returned. Safest, no implicit parsing.
  - **Wildcard form** (shorthand string) — `'https://*.app.example.com'`. Matches any origin whose host has exactly one leftmost label replacing `*`; slug is extracted as the matched leftmost label. Mirrors `HostResolver`'s host-stripping logic.
  - **Wildcard map form** (object with wildcard origin + no slug) — `{ origin: 'https://*.app.example.com' }`. Same as shorthand string; included for schema consistency with explicit form.
- **D-02 (Port normalization):** If the `origin` value omits a port, it is normalized to the default for the scheme (80 for http, 443 for https) **at compile time** by `OriginHeaderResolverConfigPass`. Equality matching at runtime always compares fully-resolved `scheme://host:port` strings.
- **D-03 (Scheme allow-list):** Both `http://` and `https://` are permitted in allow-list entries (developers need `http://localhost` for local SPA dev servers). The compiler pass does NOT reject `http://`. The Trust Model doc section explicitly warns that mixing `http://` and `https://` in production is a smell.
- **D-04 (Wildcard label slug):** When a wildcard entry matches, the slug is the literal label that replaced `*`. Example: allow-list `'https://*.app.example.com'`, request `Origin: https://acme.app.example.com` → slug = `acme`. Slug is then resolved via `TenantProviderInterface::findBySlug()` exactly like `HostResolver`/`HeaderResolver`.
- **D-05 (Wildcard cardinality):** Exactly **one** leftmost wildcard label. Mid-string (`app.*.example.com`) and multi-label (`*.*.example.com`) wildcards are **rejected by the compiler pass** with a descriptive error citing the offending entry. Pure-`*` origins (`https://*`) are also rejected.
- **D-06 (Path/query in allow-list):** Allow-list `origin` values MUST be bare origins — no path, no query, no fragment. Compiler pass rejects entries containing a path component (anything after the authority). Matches RFC 6454 (Web Origin) shape.

### Resolver runtime behavior

- **D-07 (Preflight detection):** Method-based — `if ('OPTIONS' === $request->getMethod()) { return null; }`. Checked **before** Origin parsing so preflight requests are cheap and side-effect-free. (Not header-based — `Access-Control-Request-Method` is unreliable across proxies.)
- **D-08 (Absent / empty Origin):** Treated identically — return `null` and fall through. Mirrors `HeaderResolver::resolve()` shape.
- **D-09 (Malformed Origin at runtime):** If the inbound `Origin` header is present but unparseable (e.g., garbage characters, missing scheme), return `null` rather than throw — the chain falls through and another resolver may succeed. No log entry (would invite log spam from misconfigured clients).
- **D-10 (Unknown slug):** When the matched slug fails `findBySlug()`, catch `TenantNotFoundException` and return `null` — mirrors `HeaderResolver`/`HostResolver` (Phase 02-02 decision). `TenantInactiveException` is NOT caught; bubbles as HTTP 403 like other resolvers.
- **D-11 (Mismatch warning):** After a successful resolve, peek `X-Tenant-ID` header. If present, non-empty, and DIFFERENT from the resolved tenant's slug → log `warning` with structured context (`origin`, `origin_slug`, `header_slug`, `winner: origin`). Do NOT attempt to resolve the header slug (no extra DB roundtrip); the warning records the textual mismatch only. Slugs are compared case-insensitively.
- **D-12 (Logger dependency):** Inject `Psr\Log\LoggerInterface` via constructor with `LoggerInterface $logger = new NullLogger()` default. Service wired with `service('logger')->nullOnInvalid()`. Bundle does NOT require `psr/log` directly — Symfony already brings it transitively via HttpKernel.

### Container & configuration wiring

- **D-13 (Resolver short-name registration):** `'origin' => OriginHeaderResolver::class` added to `ResolverChainPass::BUILT_IN_RESOLVER_MAP`. Users opt in by adding `'origin'` to the `tenancy.resolvers` config list.
- **D-14 (Default resolver list — opt-in):** `OriginHeaderResolver` is **NOT** added to the default `tenancy.resolvers` value (`['host', 'header', 'query_param', 'console']` stays unchanged). Rationale: security-sensitive resolver requires an explicit allow-list — silently auto-enabling it without config would be a footgun. Users opt in by configuring `tenancy.origin.allow_list[]` and adding `'origin'` to `tenancy.resolvers`.
- **D-15 (Compiler pass gating):** `OriginHeaderResolverConfigPass` is registered in `TenancyBundle::build()` unconditionally (mirrors `CacheDecoratorContractPass`). Inside `process()`, the pass short-circuits with `return` when `tenancy.origin.allow_list` is unset OR when `'origin'` is not in `tenancy.resolvers`. The pass FAILS hard only when `origin` is in the configured resolvers AND the allow-list is empty/invalid.
- **D-16 (Service definition):** `tenancy.resolver.origin` service registered in `TenancyBundle::loadExtension()` only when `'origin'` is in `$config['resolvers']`. Two constructor args: `TenantProviderInterface` (autowired via `tenancy.provider`), `LoggerInterface` (logger, null-safe). Allow-list passed as a third arg — pre-parsed structure (array of typed entries) produced by `loadExtension()` to avoid runtime re-parsing.
- **D-17 (Allow-list parameter shape):** Stored as parameter `tenancy.origin.allow_list` with normalized structure:
  ```php
  // Each entry:
  [
    'origin' => 'https://acme.app.example.com:443',  // normalized: lowercased host, explicit port
    'host' => 'acme.app.example.com',
    'scheme' => 'https',
    'port' => 443,
    'is_wildcard' => false,
    'wildcard_suffix' => null,                        // e.g. '.app.example.com' for wildcard entries
    'slug' => 'acme',                                 // null for wildcard entries — resolved at runtime
  ]
  ```
  Normalization done once in `loadExtension()`; runtime matcher is pure equality + suffix check.

### Config schema (Configuration node)

- **D-18 (YAML shape):** Mirrors existing `host:` node shape — array-of-array under `tenancy.origin`:
  ```yaml
  tenancy:
    resolvers: ['host', 'header', 'origin', 'console']
    origin:
      allow_list:
        - { origin: 'https://acme.app.example.com', slug: 'acme' }
        - { origin: 'https://beta.app.example.com', slug: 'beta-customer' }
        - 'https://*.app.example.com'  # shorthand: wildcard, slug = leftmost label
  ```
- **D-19 (Shorthand string normalization):** `Configuration::beforeNormalization()` on `allow_list` converts each string entry into `{origin: <string>, slug: null}` before the prototype validator runs. Keeps the prototype clean — single shape inside the parser.

### Documentation

- **D-20 (Docs page scope for Phase 17):** Ship `docs/user-guide/origin-header-resolver.md` in this phase — concise, focused. Sections: Overview, Configuration, **Trust Model** (REQUIRED), Mismatch Warning, Examples. Phase 22 (DOC-19) handles cross-page integration (index, navigation links from install page, refs from other pages).
- **D-21 (Trust Model section minimum content):**
  - `Origin` is set by the browser for cross-origin XHR/fetch and cannot be set by JavaScript on a non-CORS request → strong signal in a browser context.
  - `Origin` is **trivially spoofable** from curl, Postman, native mobile, server-to-server. Treat it as a routing hint, not an authentication factor.
  - Pair with a real auth layer (Bearer/cookie/CSRF) for security-sensitive endpoints.
  - Document that the resolver is opt-in (must add to `tenancy.resolvers`) and that empty/unconfigured allow-list is a compile error — failure-safe by design.

### Testing

- **D-22 (Unit tests):** `tests/Unit/Resolver/OriginHeaderResolverTest.php` covers: absent header → null; empty header → null; OPTIONS request → null (regardless of header); exact-match allow-list hit → resolved tenant; wildcard hit → resolved by leftmost label; non-matching origin → null; unknown slug → null (catches TenantNotFoundException); inactive tenant → bubbles TenantInactiveException; mismatch with `X-Tenant-ID` → resolves Origin + logs warning at `warning` level with structured context.
- **D-23 (Compiler-pass tests):** `tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php` covers: empty allow-list + `origin` configured → InvalidArgumentException; unparseable URL → exception; mid-string wildcard → exception; multi-label wildcard → exception; path/query in origin → exception; `'origin'` NOT in resolvers + empty allow-list → pass returns silently (no-op); valid mixed allow-list → no exception, parameter normalized correctly.
- **D-24 (Integration test):** `tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php` boots `TestKernel` with `tenancy.origin.allow_list` configured and a seeded SQLite tenant table; dispatches a `Request` with a matching `Origin` and asserts `TenantContext::getTenant()->getSlug()` matches the expected slug after kernel.request. One additional case for preflight (OPTIONS → context empty). One additional case for mismatch warning capture via `TestHandler` (Monolog) or `BufferingLogger`.
- **D-25 (Existing test isolation):** Add `'origin'` to the integration `TestKernel` config only in the new tests' kernels — do NOT modify `tests/Integration/_kernel/` shared fixtures so existing 14-phase suite remains unchanged.

### Claude's Discretion

- Exact wildcard matcher implementation (regex vs. suffix-strip vs. parsed-URL comparison). Suffix-strip recommended for parity with `HostResolver::extractSlug`.
- Internal struct name for the normalized allow-list entry (named class vs. typed array).
- Whether to factor a tiny private `OriginMatcher` collaborator out of the resolver for testability, or keep matching inline. Either is fine; planner picks based on test cardinality.
- Whether `OriginHeaderResolverConfigPass` lives in `src/DependencyInjection/Compiler/` (current convention) or alongside the resolver. Current convention wins.
- PSR-3 log message wording (warning level and structured context shape are locked; the human-readable string is flexible).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project-internal specs (LOCKED requirements)
- `.planning/REQUIREMENTS.md` § `RESV-06` (lines 52–59) — Acceptance criteria for the resolver. **MUST read.**
- `.planning/REQUIREMENTS.md` § `Architectural Decisions (Ratified)` (DEC-RESV-01) — Locks priority 25.
- `.planning/ROADMAP.md` § Phase 17 — Phase goal + dependency note (Phase 16 skipped, downstream numbers unchanged).
- `.planning/PROJECT.md` — Bundle vision, conventions, OSS posture.

### Project-internal research
- `.planning/research/SUMMARY.md` — Top-level v0.3 research findings; § "Critical Pitfalls" entry #5 covers Origin-spoofability mitigation; § "Compile-Time Guards" lists `OriginHeaderResolverConfigPass` as mandatory; § "Phase 1 — RESV-06" describes the phase shape.
- `.planning/research/ARCHITECTURE.md` (if present) — Per-feature integration map for `OriginHeaderResolver`.
- `.planning/research/PITFALLS.md` (if present) — Detailed pitfall #5 on Origin spoofability.

### Existing bundle source (the code that changes and analogs to mirror)
- `src/Resolver/HeaderResolver.php` — Shape to mirror (final, `TenantResolverInterface`, catch `TenantNotFoundException`).
- `src/Resolver/HostResolver.php` — Slug-extraction pattern to mirror for the wildcard case (suffix strip + last-label extraction).
- `src/Resolver/ResolverChain.php` — Insertion order is now priority-tag driven via `ResolverChainPass`; no change needed beyond the short-name map update.
- `src/Resolver/TenantResolverInterface.php` — Contract.
- `src/DependencyInjection/Compiler/ResolverChainPass.php` — Add `'origin' => OriginHeaderResolver::class` to `BUILT_IN_RESOLVER_MAP`.
- `src/DependencyInjection/Compiler/CacheDecoratorContractPass.php` — Pattern for a compile-time contract pass that short-circuits on missing definitions and throws on contract violations.
- `src/TenancyBundle.php` — `configure()` adds the `origin` node; `loadExtension()` registers the service + parameter when opted in; `build()` adds the new compiler pass.
- `src/Provider/TenantProviderInterface.php` — Resolver dependency for slug lookups.
- `src/Exception/TenantNotFoundException.php` / `TenantInactiveException.php` — Existing exception semantics, mirror exactly.
- `tests/Integration/_kernel/TestKernel.php` (and analogs) — Reference for the integration test kernel shape; **do not modify** for Phase 17 — new tests own their own kernel config.
- `tests/Unit/Resolver/HeaderResolverTest.php` — Test shape to mirror.

### Prior-phase context
- `.planning/phases/15-architectural-fixes-v0-2/15-CONTEXT.md` — Sets the precedent for `ResolverChain::resolve(): ?TenantResolution` and the resolver "swallow `TenantNotFoundException` → return null" convention (Issue #6 fix).
- `.planning/phases/02-tenant-resolution/` SUMMARY — Original Phase 02-02 decision: resolvers swallow `TenantNotFoundException`. Mirror it.

### Upstream references
- RFC 6454 (The Web Origin Concept) — Defines Origin grammar (scheme + host + port; no path).
- MDN `Origin` header — Browser-set semantics, when it's omitted (same-origin GET), preflight behavior.
- stancl/tenancy v4 Origin Header Resolver — PR #621 (cited in research/SUMMARY.md); reference implementation in a sibling ecosystem.

### Documentation (output target)
- `docs/user-guide/origin-header-resolver.md` — **New page, created in this phase.**
- `docs/index.md` / docs nav — Cross-linking deferred to Phase 22 DOC-19. Phase 17 only adds the page file; nav edits are Phase 22's job.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`HeaderResolver`** — direct template for class shape: `final`, single `resolve(Request): ?TenantInterface`, constructor injects `TenantProviderInterface`, catches `TenantNotFoundException` and returns `null`.
- **`HostResolver::extractSlug()`** — suffix-strip pattern transferable to wildcard-form Origin matching. Same algorithm: lowercase host, strip suffix, take leftmost label.
- **`ResolverChainPass::BUILT_IN_RESOLVER_MAP`** — single line addition (`'origin' => OriginHeaderResolver::class`) is the entire opt-in registration mechanism. No tag changes needed (`tenancy.resolver` autoconfiguration already in place).
- **`CacheDecoratorContractPass`** — exact template for `OriginHeaderResolverConfigPass`: implements `CompilerPassInterface`, short-circuits on missing definition/parameter, throws `LogicException`/`InvalidArgumentException` with descriptive messages.
- **`TenancyBundle::configure()` `host:` node** — exact template for the new `origin:` node (array under `tenancy`, `addDefaultsIfNotSet()`, scalar children).
- **`TenancyBundle::loadExtension()` opt-in pattern** — `if (($config['driver'] ?? '') === 'shared_db')` branching mirrors the conditional service registration we need for `'origin'` in `$config['resolvers']`.

### Established Patterns
- **Resolver opt-in:** Configured via `tenancy.resolvers` short-name list; `ResolverChainPass` filters which built-ins are added to the chain. Origin slots in cleanly — no changes to the filtering mechanism.
- **`TenantNotFoundException` swallow:** All HTTP-chain resolvers (`HeaderResolver`, `HostResolver`, `QueryParamResolver`) swallow `TenantNotFoundException` and return `null`; `TenantInactiveException` bubbles. Follow exactly.
- **Doctrine-optional guard:** `class_exists`/`interface_exists` guards for Doctrine dependencies. **This phase has zero Doctrine touch points** — resolver depends only on `TenantProviderInterface` (already optionally Doctrine-backed). No new guards needed.
- **Compile-time contract pass:** `CacheDecoratorContractPass` proves the pattern — fail container build with a descriptive error rather than fail at runtime. `OriginHeaderResolverConfigPass` follows identical shape.
- **PSR-3 logging:** Bundle does not currently log anywhere; this phase introduces the **first** `LoggerInterface` injection. Use Symfony's autowired `logger` service, `nullOnInvalid()` so non-logger setups still boot.
- **Final readonly DI:** All resolvers are `final` with `private readonly` properties. Maintain.

### Integration Points
- `TenancyBundle::configure()` — add `origin` node sibling to `host`.
- `TenancyBundle::loadExtension()` — conditional `tenancy.resolver.origin` service registration + `tenancy.origin.allow_list` parameter normalization.
- `TenancyBundle::build()` — register `OriginHeaderResolverConfigPass`.
- `ResolverChainPass::BUILT_IN_RESOLVER_MAP` — add `'origin'` entry.
- `tests/Integration/_kernel/` — new test-only kernel config under `tests/Integration/Resolver/` (do not modify shared fixtures).
- `docs/user-guide/` — new file `origin-header-resolver.md`.

### Files expected to change (informational — planner finalizes)
1. **New:** `src/Resolver/OriginHeaderResolver.php`
2. **New:** `src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php`
3. **Edit:** `src/TenancyBundle.php` (configure + loadExtension + build)
4. **Edit:** `src/DependencyInjection/Compiler/ResolverChainPass.php` (single line: add `'origin'` to map)
5. **New:** `tests/Unit/Resolver/OriginHeaderResolverTest.php`
6. **New:** `tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php`
7. **New:** `tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php`
8. **New:** `docs/user-guide/origin-header-resolver.md`
9. **Edit:** `CHANGELOG.md` (v0.3.0 unreleased section)

</code_context>

<specifics>
## Specific Ideas

- Mirror `HeaderResolver` shape exactly — same constructor signature pattern (provider first, optional collaborator second), same exception-swallow comment style. Readers should recognize this as a "drop-in sibling" to the existing resolvers.
- The Trust Model docs section should read more like a security note than a config reference. Quote the spoofability caveat plainly: "*Origin is a browser-locked routing hint, not an authentication credential. Pair this resolver with your real auth layer.*"
- For the wildcard matcher, prefer suffix-strip over regex — it's how `HostResolver` does it, runs faster, and reads obviously-correct in the diff.
- Compile-time error messages should name the offending allow-list entry verbatim. Pattern:
  - `tenancy.origin.allow_list entry "[entry]" is unparseable — must be an absolute origin URL (scheme://host[:port])`
  - `tenancy.origin.allow_list entry "[entry]" contains a mid-string wildcard — only one leftmost label may be "*"`
  - `tenancy.origin.allow_list entry "[entry]" contains a path/query — origin URLs must be bare authorities`
  - `tenancy.origin.allow_list is empty but "origin" is configured in tenancy.resolvers — either remove "origin" from resolvers or add at least one allow-list entry`
- The mismatch warning's log context should be machine-readable; treat it like a structured event, not a sentence. Example payload:
  ```php
  ['origin' => 'https://acme.app.example.com', 'origin_slug' => 'acme', 'header_slug' => 'beta', 'winner' => 'origin']
  ```
- Test data should reuse the existing `Tenancy\Bundle\Tests\Fixtures\Tenant` / `InMemoryTenantProvider` fixtures if they exist; otherwise add minimal new fixtures alongside the new test files.

</specifics>

<deferred>
## Deferred Ideas

- **CORS response handling.** The resolver only reads `Origin`; setting `Access-Control-Allow-Origin`, `Access-Control-Allow-Credentials`, etc. is the application's responsibility. Document the `nelmio/cors-bundle` integration in Phase 22 DOC-19, not here.
- **Multi-tenant Origin (one origin → many tenants via a path or sub-resource).** Out of scope — the trust model collapses if one allow-list entry can resolve to N tenants. If a real adopter needs this, it's a new requirement.
- **Origin → tenant audit log table.** Persisted log of every Origin/X-Tenant-ID mismatch for post-hoc forensics. Belongs in v0.5 Operations milestone (next to maintenance mode + health checks).
- **`Sec-Fetch-*` header validation.** Modern browsers send `Sec-Fetch-Site`, `Sec-Fetch-Mode`, `Sec-Fetch-Dest`. Cross-checking these against `Origin` would harden the spoofability story but is non-trivial and not in RESV-06. Future requirement; capture in backlog if requested.
- **Per-tenant CORS allow-list (Origin allow-list lives on the Tenant entity itself).** Inverts the current model (global config) to a per-tenant column. Reasonable for large multi-tenant SaaS but breaks the "configure once, deploy" simplicity. Not in v0.3 scope; revisit in v0.5+ if asked.
- **Public ROADMAP page docs link to this resolver.** Phase 22 (DOC-19) job.

</deferred>

<assumptions>
## Assumptions to Flag for User

These are decisions I made autonomously per the no-pause instruction. Flag any to redirect before `/gsd-plan-phase 17` runs:

1. **D-01/D-04** — Allow-list entries support both explicit `{origin, slug}` maps AND wildcard shorthand with slug = leftmost label. Could simplify to **explicit-only** (safer, more typing) or **wildcard-only** (no per-tenant config, less safe). Current pick balances both.
2. **D-03** — `http://` permitted in allow-list (needed for local dev). Could lock to `https://` only and require an opt-in `tenancy.origin.allow_insecure: true` flag for dev. Current pick: permit, warn in docs.
3. **D-11** — Mismatch warning peeks `X-Tenant-ID` and compares textually only (no DB roundtrip for the header slug). Could resolve both and compare tenant entities — costs an extra query per request when both headers are set. Current pick: textual comparison is enough for the audit-log intent.
4. **D-14** — `'origin'` is **opt-in** (not added to default `tenancy.resolvers` list). Could default-enable like other resolvers, but a default-on security-sensitive resolver with no allow-list is a footgun. Current pick: opt-in.
5. **D-20** — Phase 17 ships `docs/user-guide/origin-header-resolver.md` (resolver-specific page only); Phase 22 DOC-19 wires the cross-page integration. Could push docs entirely to Phase 22, but the Trust Model section ships with the code per RESV-06 acceptance.

If any of these need to flip, say so before planning starts. Otherwise the planner takes them as locked.

</assumptions>

---

*Phase: 17-origin-header-resolver*
*Context gathered: 2026-05-15 — autonomous discussion per user instruction; gray areas resolved with documented defaults*
