---
phase: 17-origin-header-resolver
verified: 2026-05-15T00:00:00Z
status: passed
score: 17/17 must-haves verified
overrides_applied: 0
---

# Phase 17: OriginHeaderResolver Verification Report

**Phase Goal:** OriginHeaderResolver — SPA-friendly resolver at priority 25, allow-list config, `OriginHeaderResolverConfigPass` guard (RESV-06)
**Verified:** 2026-05-15
**Status:** passed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | OriginHeaderResolver implements TenantResolverInterface, final class, priority 25 | VERIFIED | `src/Resolver/OriginHeaderResolver.php` line 26: `final class OriginHeaderResolver implements TenantResolverInterface`; `src/TenancyBundle.php` line 143: `->tag('tenancy.resolver', ['priority' => 25])` |
| 2 | Parsed-URL exact-equality matching (scheme + host + port); allow-list entries with at most one left-most wildcard label | VERIFIED | `matchOrigin()` method at lines 90-133 normalizes to `scheme://host:port` and does exact-equality for non-wildcard; wildcard uses `str_ends_with($host, $suffix)` + leftmost-label check rejecting multi-label |
| 3 | Returns null on absent Origin header (falls through resolver chain) | VERIFIED | Lines 57-60: `if (null === $origin || '' === $origin) { return null; }` |
| 4 | Returns null on CORS preflight (OPTIONS) requests | VERIFIED | Lines 51-54: `if ('OPTIONS' === $request->getMethod()) { return null; }` — checked before any header read |
| 5 | Warning log when Origin and X-Tenant-ID resolve to different tenants | VERIFIED | Lines 76-85: `strcasecmp($headerSlug, $tenant->getSlug())` with structured `warning`-level PSR-3 payload {origin, origin_slug, header_slug, winner: 'origin'} |
| 6 | OriginHeaderResolverConfigPass compile-time guard rejects empty allow-lists and unparseable URLs | VERIFIED | `src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php` lines 43-45 throw `InvalidArgumentException` on empty list; lines 63-88 reject unparseable URLs, non-http/https schemes, path/query/fragment, mid-string wildcards |
| 7 | Dedicated Trust Model docs section explaining Origin is browser-protected cross-origin but spoofable from non-browser clients | VERIFIED | `docs/user-guide/origin-header-resolver.md` — 150 lines, 5 H2 sections; Trust Model section (line 54) contains verbatim sentence; curl + Postman spoofing vectors enumerated at lines 64-65 |
| 8 | TenantNotFoundException swallowed; TenantInactiveException bubbles | VERIFIED | Lines 68-73: `catch (TenantNotFoundException)` returns null; comment `// TenantInactiveException is NOT caught — bubbles up as HTTP 403` |
| 9 | 'origin' short-name in ResolverChainPass::BUILT_IN_RESOLVER_MAP | VERIFIED | `src/DependencyInjection/Compiler/ResolverChainPass.php` line 24: `'origin' => OriginHeaderResolver::class,` |
| 10 | TenancyBundle wired — configure() origin node, loadExtension() conditional service, build() compiler pass | VERIFIED | `src/TenancyBundle.php` lines 60-88 (origin arrayNode), lines 128-144 (conditional service), line 219 (`addCompilerPass(new OriginHeaderResolverConfigPass())`) |
| 11 | Default tenancy.resolvers remains ['host', 'header', 'query_param', 'console'] — origin is opt-in | VERIFIED | `src/TenancyBundle.php` line 52: `->defaultValue(['host', 'header', 'query_param', 'console'])` — 'origin' absent |
| 12 | beforeNormalization() converts string shorthand entries to {origin, slug: null} map form | VERIFIED | `src/TenancyBundle.php` lines 65-77: `beforeNormalization()->always()` converts `is_string($entry)` to `['origin' => $entry, 'slug' => null]` |
| 13 | 10-case unit test suite (OriginHeaderResolverTest) | VERIFIED | `tests/Unit/Resolver/OriginHeaderResolverTest.php` — 10 test methods confirmed by grep and 10/10 pass |
| 14 | 14-case unit test suite (OriginHeaderResolverConfigPassTest) | VERIFIED | `tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php` — 14 test methods confirmed by grep and 14/14 pass |
| 15 | 5-case integration test suite with real kernel boot (OriginHeaderResolverIntegrationTest) | VERIFIED | `tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php` — 5 test methods; all 5 pass including empty-allow-list compile-time failure scenario |
| 16 | Full test suite 333/333 passing, 825 assertions | VERIFIED | `vendor/bin/phpunit --no-coverage` output: `OK (333 tests, 825 assertions)` |
| 17 | CHANGELOG.md Unreleased section has Added bullet for OriginHeaderResolver | VERIFIED | CHANGELOG.md lines between `## [Unreleased]` and `## [0.2.1]` contain `### Added` with bullets for both `OriginHeaderResolver` and `OriginHeaderResolverConfigPass` |

**Score:** 17/17 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `src/Resolver/OriginHeaderResolver.php` | Final class implementing TenantResolverInterface, priority 25, OPTIONS short-circuit, exact+wildcard matching, mismatch warning | VERIFIED | 135 lines; fully implemented; `final class OriginHeaderResolver implements TenantResolverInterface`; all behavior present |
| `src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php` | Final CompilerPassInterface, validates allow-list at compile time, self-gating | VERIFIED | 139 lines; fully implemented; 4 distinct error messages per CONTEXT.md D-23; self-gates via `in_array('origin', $resolvers, true)` |
| `src/DependencyInjection/Compiler/ResolverChainPass.php` | Updated BUILT_IN_RESOLVER_MAP with 'origin' entry | VERIFIED | Line 24: `'origin' => OriginHeaderResolver::class,` present; 5 total entries |
| `src/TenancyBundle.php` | origin config node, conditional service definition, compiler pass registration | VERIFIED | All three edit sites applied; origin arrayNode at lines 60-88; conditional service at 128-144; compiler pass at line 219 |
| `tests/Unit/Resolver/OriginHeaderResolverTest.php` | 10 unit test cases | VERIFIED | 10 test methods; all pass |
| `tests/Unit/Resolver/Support/RecordingLogger.php` | PSR-3 fixture extending AbstractLogger | VERIFIED | Exists in `tests/Unit/Resolver/Support/` |
| `tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php` | 14 unit test cases | VERIFIED | 14 test methods; all pass |
| `tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php` | 5 e2e integration scenarios | VERIFIED | 5 test methods; all pass against real Symfony kernel |
| `tests/Integration/Resolver/Support/StubTenant.php` | TenantInterface stub fixture | VERIFIED | Exists |
| `tests/Integration/Resolver/Support/StubTenantProvider.php` | TenantProviderInterface stub fixture | VERIFIED | Exists |
| `tests/Integration/Resolver/Support/RecordingLogger.php` | PSR-3 in-memory logger fixture (integration namespace) | VERIFIED | Exists |
| `docs/user-guide/origin-header-resolver.md` | 80+ line guide, 5 required H2 sections, verbatim Trust Model sentence | VERIFIED | 150 lines; 5 H2 sections (Overview, Configuration, Trust Model, Mismatch Warning, Examples) in correct order; verbatim sentence at line 54 |
| `CHANGELOG.md` | Unreleased section with OriginHeaderResolver Added bullet | VERIFIED | Both OriginHeaderResolver and OriginHeaderResolverConfigPass listed under `### Added` |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `src/TenancyBundle.php` | `src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php` | `build()` addCompilerPass | WIRED | Line 219: `$container->addCompilerPass(new OriginHeaderResolverConfigPass())` |
| `src/TenancyBundle.php` | `src/Resolver/OriginHeaderResolver.php` | `loadExtension()` service definition priority 25 | WIRED | Lines 137-143: `->set('tenancy.resolver.origin', OriginHeaderResolver::class)->args([...])->tag('tenancy.resolver', ['priority' => 25])` |
| `src/DependencyInjection/Compiler/ResolverChainPass.php` | `src/Resolver/OriginHeaderResolver.php` | `BUILT_IN_RESOLVER_MAP['origin']` | WIRED | Line 24: `'origin' => OriginHeaderResolver::class` |
| `src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php` | Container parameter `tenancy.origin.allow_list` | `getParameter` / `setParameter` | WIRED | Lines 39-52: reads raw list, normalizes, writes back |
| `src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php` | Container parameter `tenancy.resolvers` | `getParameter` (self-gate) | WIRED | Lines 34-36: `in_array('origin', $resolvers, true)` |
| `tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php` | `src/Resolver/OriginHeaderResolver.php` | container service `tenancy.resolver_chain` → `ResolverChain::resolve()` | WIRED | Integration tests dispatch requests through the real resolver chain and assert `$resolution->resolvedBy === OriginHeaderResolver::class` |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `OriginHeaderResolver::resolve()` | `$origin` from `$request->headers->get('Origin')` | HTTP request header | Yes — real Symfony Request object | FLOWING |
| `OriginHeaderResolver::resolve()` | `$slug` from `matchOrigin()` | `$this->allowList` (constructor arg, compiler-normalized) | Yes — container parameter set at compile time | FLOWING |
| `OriginHeaderResolver::resolve()` | `$tenant` from `$tenantProvider->findBySlug($slug)` | `TenantProviderInterface` — real provider or stub in tests | Yes — real provider in production, StubTenantProvider in integration tests | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| 10 unit tests pass | `vendor/bin/phpunit --filter OriginHeaderResolverTest --no-coverage` | 10 tests, 28 assertions, OK | PASS |
| 14 compiler-pass unit tests pass | `vendor/bin/phpunit --filter OriginHeaderResolverConfigPassTest --no-coverage` | 14 tests, OK | PASS |
| 5 integration tests pass | `vendor/bin/phpunit --filter OriginHeaderResolverIntegrationTest --no-coverage` | 5 tests, OK | PASS |
| Full suite 333/333 | `vendor/bin/phpunit --no-coverage` | 333 tests, 825 assertions, OK | PASS |
| PHPStan level 9 clean on key source files | `vendor/bin/phpstan analyse src/Resolver/OriginHeaderResolver.php src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php src/DependencyInjection/Compiler/ResolverChainPass.php src/TenancyBundle.php --level=9 --no-progress` | No errors | PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|----------|
| RESV-06 | P01, P02, P03, P04, P05 | OriginHeaderResolver at priority 25, allow-list config, OriginHeaderResolverConfigPass guard, Trust Model docs | SATISFIED | All 7 acceptance criteria met: (1) implements TenantResolverInterface, tagged priority 25; (2) exact-equality + leftmost-wildcard matching, mid-string wildcards rejected; (3) returns null on absent Origin; (4) returns null on OPTIONS; (5) warning log on Origin/X-Tenant-ID mismatch; (6) OriginHeaderResolverConfigPass rejects empty lists + unparseable URLs; (7) Trust Model docs section with verbatim spoofability warning |

### Anti-Patterns Found

No blockers or stub patterns found. The one occurrence of "placeholder" in source files (`src/TenancyBundle.php` line 166) is a comment within an existing code path for TenantDriverMiddleware and is unrelated to Phase 17 changes.

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| (none) | — | — | — | — |

### Human Verification Required

None. All behaviors are verifiable programmatically. Visual/UX aspects (docs page readability) are informational only and do not block the goal.

### Gaps Summary

No gaps. All 17 must-haves are verified. The phase delivered:

- `OriginHeaderResolver` at priority 25 with OPTIONS short-circuit, exact+wildcard allow-list matching, TenantNotFoundException swallow, TenantInactiveException bubble, and structured PSR-3 mismatch warning — fully wired into the resolver chain.
- `OriginHeaderResolverConfigPass` compile-time guard with 4 distinct error messages, self-gating when 'origin' not in resolvers — registered unconditionally in `TenancyBundle::build()`.
- `ResolverChainPass::BUILT_IN_RESOLVER_MAP` updated with `'origin'` short-name entry.
- `TenancyBundle` wired with `origin:` config node (shorthand normalization + isRequired/cannotBeEmpty constraints), conditional `tenancy.resolver.origin` service registration, and unconditional compiler pass registration. Default resolvers unchanged (opt-in).
- 10 + 14 + 5 = 29 tests covering all scenarios, all passing. Full suite 333/333.
- PHPStan level 9 clean across all modified files.
- Trust Model docs page (150 lines, 5 required sections, verbatim security sentence) and CHANGELOG entry.

RESV-06 is fully satisfied.

---

_Verified: 2026-05-15_
_Verifier: Claude (gsd-verifier)_
