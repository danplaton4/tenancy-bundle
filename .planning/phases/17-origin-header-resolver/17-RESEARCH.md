# Phase 17: OriginHeaderResolver — Research

**Researched:** 2026-05-15
**Domain:** Symfony tenant resolver — `Origin` HTTP header → tenant slug, allow-list-driven, compile-time-guarded
**Confidence:** HIGH

## Summary

Phase 17 ships `OriginHeaderResolver` as the fifth tenant resolver in the bundle. The phase is a small, well-bounded addition that mirrors an established pattern: `HeaderResolver` for the class shape, `HostResolver::extractSlug()` for wildcard slug extraction, and `CacheDecoratorContractPass` for the compile-time invariant guard. The only mildly novel sub-area is **Configuration `beforeNormalization()` string→array shorthand** — that pattern is well-documented in Symfony but is not yet used elsewhere in the bundle.

The phase is heavily over-specified upstream. CONTEXT.md (D-01..D-25) locks every meaningful design decision; the research-relevant question is **"will the locked design execute cleanly against the existing code?"** It will. The four areas that earned extra scrutiny were (1) `parse_url()` behavior on borderline inputs at compile time, (2) `Origin: null` and RFC 6454 grammar edge cases that affect the runtime matcher, (3) the integration test pattern for asserting on PSR-3 log emission, and (4) confirming the compile-time guard pattern translates 1:1 from `CacheDecoratorContractPass`. All four resolve cleanly.

**Primary recommendation:** Implement the resolver, the config pass, and the docs page as three separate plans inside Phase 17. Compile-time guard tests (Plan-1) gate the resolver class (Plan-2) which gates the integration tests + docs (Plan-3). No external dependency additions; no BC break; opt-in by design.

## User Constraints (from CONTEXT.md)

### Locked Decisions

**Spec lock from REQUIREMENTS.md § RESV-06 and DEC-RESV-01 (planner MUST NOT re-litigate):**

- `OriginHeaderResolver` implements `TenantResolverInterface`; tagged `tenancy.resolver` priority **25**; mirrors shape of `HeaderResolver`.
- Parsed-URL exact-equality matching: scheme + host + port all must match.
- Allow-list entries permit at most one **left-most** wildcard label (`*.app.example.com` allowed; mid-string wildcards like `app.*.example.com` rejected at compile time).
- Returns `null` on absent `Origin` header — falls through resolver chain.
- Returns `null` on CORS preflight (`OPTIONS`) requests — preflight must not throw.
- Emits a `warning`-level log entry when `Origin` and `X-Tenant-ID` resolve to **different** tenants in the same request.
- `OriginHeaderResolverConfigPass` rejects empty allow-lists and unparseable URLs at container compile time.
- Dedicated "Trust Model" docs section explains `Origin` is browser-protected cross-origin but trivially spoofable from non-browser clients.

**Implementation decisions from CONTEXT.md (verbatim, condensed):**

- **D-01** Allow-list config under `tenancy.origin.allow_list[]`. Each entry: explicit `{origin, slug}` map, wildcard shorthand string, or wildcard map `{origin}` (no `slug`).
- **D-02** Compile-time port normalization: omitted port becomes 80 (http) or 443 (https). Runtime compares fully-resolved `scheme://host:port`.
- **D-03** Both `http://` and `https://` permitted in allow-list (local dev). Trust Model docs warn against mixing in prod.
- **D-04** Wildcard match → slug = literal label that replaced `*`. Resolved via `TenantProviderInterface::findBySlug()`.
- **D-05** Exactly **one** leftmost wildcard label. Mid-string, multi-label, and pure-`*` rejected by compiler pass.
- **D-06** Allow-list `origin` values MUST be bare origins — no path/query/fragment. Compiler pass rejects.
- **D-07** Preflight detection by `$request->getMethod() === 'OPTIONS'`, checked BEFORE Origin parsing.
- **D-08** Absent/empty Origin → `null`, falls through.
- **D-09** Malformed Origin at runtime → `null` (no log spam from misconfigured clients).
- **D-10** Unknown slug → catch `TenantNotFoundException`, return `null`. `TenantInactiveException` bubbles (HTTP 403).
- **D-11** Mismatch warning: peek `X-Tenant-ID`, compare textually case-insensitively, no extra DB roundtrip. Structured context `['origin', 'origin_slug', 'header_slug', 'winner' => 'origin']`.
- **D-12** Inject `Psr\Log\LoggerInterface` via constructor; default `new NullLogger()`. Service wired with `service('logger')->nullOnInvalid()`.
- **D-13** `'origin' => OriginHeaderResolver::class` added to `ResolverChainPass::BUILT_IN_RESOLVER_MAP`.
- **D-14** `OriginHeaderResolver` is **NOT** in the default `tenancy.resolvers` list. Opt-in by config.
- **D-15** `OriginHeaderResolverConfigPass` registered in `TenancyBundle::build()` unconditionally; short-circuits inside `process()` when `tenancy.origin.allow_list` unset OR `'origin'` not in `tenancy.resolvers`.
- **D-16** `tenancy.resolver.origin` service registered in `loadExtension()` only when `'origin'` in `$config['resolvers']`. Three args: `TenantProviderInterface`, `LoggerInterface` (null-safe), pre-parsed allow-list array.
- **D-17** Allow-list parameter `tenancy.origin.allow_list` stored as normalized array; each entry: `{origin, host, scheme, port, is_wildcard, wildcard_suffix, slug}`.
- **D-18** YAML mirrors `host:` node shape — array-of-array under `tenancy.origin`.
- **D-19** `Configuration::beforeNormalization()` on `allow_list` converts string entries → `{origin: <string>, slug: null}` before prototype validator runs.
- **D-20** Ship `docs/user-guide/origin-header-resolver.md` in this phase — Overview, Configuration, Trust Model (REQUIRED), Mismatch Warning, Examples. Cross-page nav integration deferred to Phase 22 DOC-19.
- **D-21** Trust Model section minimum content (browser-locked cross-origin XHR, trivially spoofable from non-browser clients, pair with real auth, opt-in design, empty allow-list = compile error).
- **D-22** Unit tests in `tests/Unit/Resolver/OriginHeaderResolverTest.php`.
- **D-23** Compiler-pass tests in `tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php`.
- **D-24** Integration test in `tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php` boots `TestKernel`-style kernel, seeds tenants, dispatches `Request` with matching `Origin`, asserts `TenantContext::getTenant()->getSlug()` after `kernel.request`. Plus preflight + mismatch-warning cases.
- **D-25** Do NOT modify `tests/Integration/TestKernel.php` (no `_kernel/` directory exists — kernel lives at `tests/Integration/TestKernel.php`) or shared fixtures. New tests own their own kernel.

### Claude's Discretion

- Exact wildcard matcher implementation (regex vs. suffix-strip vs. parsed-URL comparison). Suffix-strip recommended for parity with `HostResolver::extractSlug()`.
- Internal struct name for the normalized allow-list entry (named class vs. typed array).
- Whether to factor a tiny private `OriginMatcher` collaborator out of the resolver for testability, or keep matching inline. Either is fine.
- Whether `OriginHeaderResolverConfigPass` lives in `src/DependencyInjection/Compiler/` (current convention) or alongside the resolver. Current convention wins.
- PSR-3 log message wording (warning level + structured context shape are locked; the human-readable string is flexible).

### Deferred Ideas (OUT OF SCOPE)

- CORS response handling (Phase 22 DOC-19 documents the `nelmio/cors-bundle` integration).
- Multi-tenant Origin (one origin → many tenants). Trust model collapses; new requirement if ever needed.
- Origin → tenant audit log table. v0.5 Operations milestone.
- `Sec-Fetch-*` header validation. Future requirement; backlog if requested.
- Per-tenant CORS allow-list on the Tenant entity. v0.5+ if asked.
- Public ROADMAP docs link to this resolver. Phase 22 DOC-19 job.

## Project Constraints (from CLAUDE.md)

- **PHP 8.2+** with `declare(strict_types=1);` everywhere. `[VERIFIED: composer.json line 21]`
- **Symfony 7.4||^8.0** — bundle architecture, `AbstractBundle` pattern. `[VERIFIED: composer.json]`
- **Doctrine deps are optional** — guard with `class_exists`/`interface_exists`. **This phase has zero Doctrine touch points** — the resolver depends only on `TenantProviderInterface` (already optionally Doctrine-backed via `services.php` `interface_exists` guard). No new Doctrine guards needed. `[VERIFIED: src/TenancyBundle.php lines 102-103, 127, 136]`
- **PHPStan level 9** — strict typing, no `mixed` without phpdoc generics. `[VERIFIED: CLAUDE.md]`
- **PHPUnit 11** — `final` resolvers cannot be mocked; use stub providers and `createMock(TenantInterface::class)` (interface mocking still allowed). `[VERIFIED: tests/Unit/Resolver/HeaderResolverTest.php pattern]`
- **php-cs-fixer with `@Symfony` ruleset** — `final` classes, `private readonly` properties. `[VERIFIED: existing resolvers all follow this]`
- **strict_mode defaults ON** — a data leak is a security incident. Applies indirectly: the OriginHeaderResolver MUST exact-match (no substring) and the compile-time guard MUST reject ambiguous wildcards.

## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| **RESV-06** | `OriginHeaderResolver` resolves the active tenant from the `Origin` HTTP header (SPA-friendly), priority 25, allow-list-driven, with compile-time guard and warning log on conflict | See Standard Stack (HeaderResolver/HostResolver mirror), Architecture Patterns (compile-time guard pass), Code Examples (configure() node, beforeNormalization shorthand, suffix-strip matcher), Pitfalls (Origin: null, parse_url quirks, preflight) |

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Read `Origin` HTTP header | Symfony HTTP kernel layer (Request) | — | Header inspection is a per-request HTTP concern; same tier as existing resolvers (HostResolver reads `getHost()`, HeaderResolver reads `headers->get()`). |
| Match origin → tenant slug | Bundle resolver layer (`src/Resolver/`) | — | Pure runtime logic against a pre-parsed allow-list; isolated by `TenantResolverInterface`. |
| Allow-list normalization (parse, port-default, wildcard-strip) | Bundle DI extension (`TenancyBundle::loadExtension()`) | — | Parse-once-at-compile-time, store as container parameter — same pattern as `tenancy.host.app_domain`. |
| Allow-list validation (rejects empty/malformed/mid-wildcard/path) | Bundle compiler pass (`src/DependencyInjection/Compiler/`) | — | Fail-fast at container build time, NOT at runtime — mirrors `CacheDecoratorContractPass`. |
| Tenant lookup by slug | Bundle provider layer (`TenantProviderInterface`) | — | Reuse existing provider; no new dependency. |
| Mismatch warning | Bundle resolver layer + PSR-3 logger | Symfony monolog (host application) | Resolver emits structured PSR-3 record; host app's monolog routes it. |
| Trust Model documentation | Docs (`docs/user-guide/origin-header-resolver.md`) | — | Security-sensitive: docs are part of the deliverable per RESV-06 acceptance. |

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `symfony/http-foundation` | ^7.4 \|\| ^8.0 | `Request::headers->get('Origin')`, `Request::getMethod()` | Already a hard `require`; same component the other resolvers use. `[VERIFIED: composer.json line 27]` |
| `symfony/dependency-injection` | ^7.4 \|\| ^8.0 | `CompilerPassInterface`, `ContainerBuilder`, parameter storage | Already a hard `require`. `[VERIFIED: composer.json line 25]` |
| `symfony/config` | ^7.4 \|\| ^8.0 | `DefinitionConfigurator`, `beforeNormalization()`, `ArrayNodeDefinition` | Already a hard `require`. `[VERIFIED: composer.json line 23]` |
| `psr/log` | 3.0.2 (transitive) | `LoggerInterface`, `NullLogger` | Already installed transitively via `symfony/http-kernel`. Do NOT add to `require` — same pattern CONTEXT.md D-12 dictates. `[VERIFIED: composer show psr/log → version 3.0.2 released 2024-09-11]` |

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `monolog/monolog` (TestHandler) | — | Capture log records in integration tests | NOT a bundle dependency; only used in integration test verification if monolog-bundle is present. Otherwise use a custom test logger that implements `Psr\Log\LoggerInterface` and records calls. `[CITED: https://akrabat.com/using-monologs-testhandler/]` |
| `symfony/framework-bundle` | ^7.4 \|\| ^8.0 (dev) | Integration test kernel | Already in `require-dev`. `[VERIFIED: composer.json line 39]` |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `parse_url()` for compile-time normalization | `Symfony\Component\HttpFoundation\Request::create()` and extract host/scheme/port | `Request::create()` is heavier and intended for runtime; `parse_url()` is sufficient when paired with explicit validation. Use `parse_url()` + manual port defaulting. |
| Suffix-strip wildcard matcher | Regex matcher | Suffix-strip is what `HostResolver::extractSlug()` uses (lines 39–67 of `HostResolver.php`); proven; faster; reads as obviously-correct in code review. Regex would be slower and easier to get wrong (lookahead/escaping landmines). Suffix-strip wins. `[VERIFIED: src/Resolver/HostResolver.php]` |
| Named DTO class for normalized allow-list entry | Typed array `array{origin: string, host: string, scheme: string, port: int, is_wildcard: bool, wildcard_suffix: ?string, slug: ?string}` | Typed array with phpdoc generic for PHPStan is enough; named DTO adds a class for negligible benefit. CONTEXT.md D-17 dictates array shape; planner discretion on whether to add a struct class. Recommend typed array — simpler diff. |
| Monolog `TestHandler` for log assertions | A small fixture `class RecordingLogger implements LoggerInterface` that stores `[level, message, context]` tuples | Bundle does not depend on monolog. A test-only `RecordingLogger` (≈30 lines) keeps the test self-contained and matches the bundle's existing test-fixture convention (`NullTenantProvider`, `StubTenantProvider`, `StubTenant`). Recommend `RecordingLogger`. |

**Installation:** No new dependencies. Phase 17 is additive against the existing v0.2 surface — `composer.json` unchanged.

**Version verification:** `psr/log` 3.0.2 confirmed via `composer show psr/log` against the installed lockfile (released 2024-09-11). Already present transitively; no `require` addition needed. `[VERIFIED: 2026-05-15 against installed vendor/]`

## Architecture Patterns

### System Architecture Diagram

```
                  ┌──────────────────────────────────────────────────────────┐
                  │            CONTAINER COMPILE TIME (one-shot)             │
                  │                                                          │
                  │  config/services.php  +  TenancyBundle::loadExtension()  │
                  │              │                                           │
                  │              ▼                                           │
                  │   tenancy.origin.allow_list (parameter)                  │
                  │   normalized array<entry>                                │
                  │              │                                           │
                  │              ▼                                           │
                  │   OriginHeaderResolverConfigPass::process()              │
                  │   • allow-list non-empty?     [throws on violation]      │
                  │   • each entry parseable?     [throws on violation]      │
                  │   • no path/query?            [throws on violation]      │
                  │   • wildcard cardinality ≤ 1? [throws on violation]      │
                  │   • normalize port            [mutates parameter]        │
                  │              │                                           │
                  │              ▼                                           │
                  │   ResolverChainPass::process()                           │
                  │   • adds OriginHeaderResolver (priority 25) to chain     │
                  └──────────────────────────────────────────────────────────┘
                                       │
                                       ▼
                  ┌──────────────────────────────────────────────────────────┐
                  │              REQUEST RUNTIME (per request)               │
                  │                                                          │
                  │  HTTP Request                                            │
                  │      │                                                   │
                  │      ▼ (kernel.request, priority 20)                     │
                  │  TenantContextOrchestrator → ResolverChain::resolve()    │
                  │      │                                                   │
                  │      ├─→ HostResolver        (priority 30) tries first   │
                  │      │   returns ?TenantInterface                        │
                  │      │                                                   │
                  │      ├─→ OriginHeaderResolver (priority 25) ◄── PHASE 17 │
                  │      │   1. method=OPTIONS? → null   (preflight cheap)   │
                  │      │   2. Origin header absent/empty? → null           │
                  │      │   3. parse_url($origin); malformed? → null        │
                  │      │   4. match against normalized allow-list:         │
                  │      │       • exact entry (scheme+host+port equal)     │
                  │      │       • wildcard entry (suffix-strip → label)    │
                  │      │   5. slug → TenantProviderInterface::findBySlug() │
                  │      │       • TenantNotFoundException → null           │
                  │      │       • TenantInactiveException → bubbles (403)  │
                  │      │   6. peek X-Tenant-ID; differs? → warning log    │
                  │      │   returns ?TenantInterface                        │
                  │      │                                                   │
                  │      ├─→ HeaderResolver      (priority 20)               │
                  │      └─→ QueryParamResolver  (priority 10)               │
                  │              │                                           │
                  │              ▼                                           │
                  │   TenantContext::setTenant() → BootstrapperChain::boot() │
                  └──────────────────────────────────────────────────────────┘
```

### Recommended Project Structure

No directory changes. New files slot into existing namespaces:

```
src/
├── Resolver/
│   └── OriginHeaderResolver.php                    (NEW — final, implements TenantResolverInterface)
├── DependencyInjection/Compiler/
│   ├── ResolverChainPass.php                       (EDIT — add 'origin' to BUILT_IN_RESOLVER_MAP)
│   └── OriginHeaderResolverConfigPass.php          (NEW — final, implements CompilerPassInterface)
└── TenancyBundle.php                               (EDIT — configure() + loadExtension() + build())

config/
└── services.php                                    (no edits — service registered in loadExtension() conditionally)

tests/
├── Unit/
│   ├── Resolver/
│   │   └── OriginHeaderResolverTest.php            (NEW)
│   └── DependencyInjection/Compiler/
│       └── OriginHeaderResolverConfigPassTest.php  (NEW)
└── Integration/
    └── Resolver/
        ├── OriginHeaderResolverIntegrationTest.php (NEW)
        └── Support/
            ├── OriginResolverTestKernel.php        (NEW — owns its own kernel; does NOT modify TestKernel.php)
            └── RecordingLogger.php                 (NEW — PSR-3 logger that records calls for assertion)

docs/user-guide/
└── origin-header-resolver.md                       (NEW — Overview, Configuration, Trust Model, Mismatch Warning, Examples)
```

### Component Responsibilities

| File | Responsibility |
|------|---------------|
| `src/Resolver/OriginHeaderResolver.php` | Pure runtime matcher: preflight short-circuit → header read → parse → allow-list match (exact + wildcard) → slug lookup → mismatch warning. Constructor: `(TenantProviderInterface $provider, LoggerInterface $logger = new NullLogger(), array $allowList = [])`. |
| `src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php` | Compile-time guard. Short-circuits when `'origin'` not in `tenancy.resolvers` OR `tenancy.origin.allow_list` parameter unset. Throws `InvalidArgumentException` with entry-quoting message on each invariant violation. |
| `src/TenancyBundle.php::configure()` | Add `origin:` array node sibling to `host:`. `allow_list` is an array-of-array prototype with `beforeNormalization()->ifString()` → `['origin' => $v, 'slug' => null]`. |
| `src/TenancyBundle.php::loadExtension()` | When `'origin' in $config['resolvers']`: normalize each entry (parse_url, default port, detect wildcard, extract suffix, validate scheme), store as `tenancy.origin.allow_list` parameter, register `tenancy.resolver.origin` service with the parameter as third arg. |
| `src/TenancyBundle.php::build()` | Append `$container->addCompilerPass(new OriginHeaderResolverConfigPass());` after existing passes. Unconditional registration; the pass self-gates internally. |
| `src/DependencyInjection/Compiler/ResolverChainPass.php` | Single-line edit: `'origin' => OriginHeaderResolver::class,` added to `BUILT_IN_RESOLVER_MAP`. |
| Test kernel `OriginResolverTestKernel.php` | Replaces tenant provider with `StubTenantProvider` (existing fixture under `tests/Integration/Messenger/Support/`); registers `'origin'` in `tenancy.resolvers`; configures `tenancy.origin.allow_list`; exposes `ResolverChain` + `TenantContext` publicly via a `Make*PublicPass`. |
| `RecordingLogger.php` | Implements `Psr\Log\LoggerInterface` with `info()`/`warning()`/etc. each storing `[level, message, context]` in a public `records[]` for test assertions. ~30 lines. |

### Pattern 1: Final resolver mirroring `HeaderResolver`

**What:** A final class implementing `TenantResolverInterface`, with private readonly constructor properties and a single `resolve(Request): ?TenantInterface` method that swallows `TenantNotFoundException` and lets `TenantInactiveException` bubble.

**When to use:** All bundle resolvers follow this shape (`HeaderResolver`, `HostResolver`, `QueryParamResolver`). OriginHeaderResolver MUST too.

**Example:** (paraphrased from `src/Resolver/HeaderResolver.php`)

```php
// Source: src/Resolver/HeaderResolver.php (template) + CONTEXT.md D-08..D-12
declare(strict_types=1);

namespace Tenancy\Bundle\Resolver;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;

final class OriginHeaderResolver implements TenantResolverInterface
{
    public const HEADER_NAME = 'Origin';
    public const MISMATCH_HEADER_NAME = 'X-Tenant-ID';

    /**
     * @param list<array{
     *     origin: string, host: string, scheme: string, port: int,
     *     is_wildcard: bool, wildcard_suffix: ?string, slug: ?string
     * }> $allowList
     */
    public function __construct(
        private readonly TenantProviderInterface $tenantProvider,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly array $allowList = [],
    ) {
    }

    public function resolve(Request $request): ?TenantInterface
    {
        // D-07: preflight short-circuit BEFORE Origin parsing
        if ('OPTIONS' === $request->getMethod()) {
            return null;
        }

        // D-08: absent/empty Origin → null
        $origin = $request->headers->get(self::HEADER_NAME);
        if (null === $origin || '' === $origin) {
            return null;
        }

        // D-09: malformed Origin → null (no log spam)
        $slug = $this->matchOrigin($origin);
        if (null === $slug) {
            return null;
        }

        try {
            $tenant = $this->tenantProvider->findBySlug($slug);
        } catch (TenantNotFoundException) {
            return null;  // D-10
        }
        // TenantInactiveException bubbles (HTTP 403) — D-10

        // D-11: mismatch warning
        $headerSlug = $request->headers->get(self::MISMATCH_HEADER_NAME);
        if (null !== $headerSlug && '' !== $headerSlug
            && 0 !== strcasecmp($headerSlug, $tenant->getSlug())) {
            $this->logger->warning(
                'Origin and X-Tenant-ID resolved to different tenants; Origin wins.',
                [
                    'origin' => $origin,
                    'origin_slug' => $tenant->getSlug(),
                    'header_slug' => $headerSlug,
                    'winner' => 'origin',
                ],
            );
        }

        return $tenant;
    }

    private function matchOrigin(string $origin): ?string
    {
        // parse_url + scheme/host/port normalization + allow-list scan
        // (suffix-strip wildcard match for is_wildcard=true entries)
        // returns slug or null
        // ... implementation per CONTEXT.md D-02/D-04/D-05
    }
}
```

### Pattern 2: Compile-time contract pass mirroring `CacheDecoratorContractPass`

**What:** A final compiler pass that short-circuits when the feature isn't opted in, then throws a descriptive exception (`\InvalidArgumentException` per CONTEXT.md style for config) with the offending entry quoted in the message.

**When to use:** Any "if feature enabled, then config must satisfy invariant X" guard. Mirrors `CacheDecoratorContractPass` exactly (lines 36–66 of that file).

**Example:**

```php
// Source: src/DependencyInjection/Compiler/CacheDecoratorContractPass.php (template) + CONTEXT.md D-15, specifics block
declare(strict_types=1);

namespace Tenancy\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class OriginHeaderResolverConfigPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Short-circuit: feature not configured
        if (!$container->hasParameter('tenancy.resolvers')) {
            return;
        }
        $resolvers = $container->getParameter('tenancy.resolvers');
        if (!is_array($resolvers) || !in_array('origin', $resolvers, true)) {
            return; // 'origin' not opted in — pass is a no-op
        }

        // 'origin' IS configured — allow-list MUST exist and be non-empty
        if (!$container->hasParameter('tenancy.origin.allow_list')) {
            throw new \InvalidArgumentException(
                'tenancy.origin.allow_list is empty but "origin" is configured in tenancy.resolvers — '
                .'either remove "origin" from resolvers or add at least one allow-list entry.'
            );
        }
        // (validation of each entry happens here; for entries already normalized
        //  in loadExtension(), the pass re-verifies invariants — defense in depth)
    }
}
```

**Note on validation locus:** CONTEXT.md D-17 says normalization happens in `loadExtension()`. The compiler pass then *re-verifies* the normalized parameter shape — this is defense-in-depth: if a future refactor reorders `loadExtension()` or a downstream user injects the parameter directly, the pass still catches malformed entries. The pass's primary job is the "empty allow-list while opted in" invariant; per-entry validation is the secondary job.

### Pattern 3: Configuration `beforeNormalization()` string-shorthand

**What:** A normalization hook that runs *before* the prototype validator, converting a scalar string entry into the canonical array shape. Keeps the prototype clean — single shape inside the parser.

**When to use:** When users should be able to write either `'string-shorthand'` or `{long: form}` and the parser must accept both. This is the standard Symfony pattern for shorthand config. `[CITED: https://symfony.com/doc/current/components/config/definition.html — § Normalization]`

**Example:**

```php
// Source: https://symfony.com/doc/current/components/config/definition.html § Normalization
//         + CONTEXT.md D-18, D-19
$definition->rootNode()
    ->children()
        ->arrayNode('origin')
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('allow_list')
                    ->beforeNormalization()
                        ->castToArray()
                    ->end()
                    ->arrayPrototype()
                        ->beforeNormalization()
                            ->ifString()
                            ->then(fn (string $v) => ['origin' => $v, 'slug' => null])
                        ->end()
                        ->children()
                            ->scalarNode('origin')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('slug')->defaultNull()->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ->end()
    ->end()
;
```

The `beforeNormalization()->ifString()->then(...)` pair is well-documented and widely used (`monolog-bundle`'s `handlers` node uses this exact pattern for handler shorthand). `[CITED: https://symfony.com/doc/current/components/config/definition.html]` `[CITED: https://github.com/symfony/symfony/issues/28923 — confirms it works on child nodes including prototypes; the GitHub issue is specifically about it NOT working on the *root* node, which is not our case here]`

### Pattern 4: Suffix-strip wildcard matcher (mirrors `HostResolver`)

**What:** Pre-compute a `wildcard_suffix` (e.g. `.app.example.com`) at compile time. At runtime, check `str_ends_with($host, $suffix)`, strip the suffix, and verify the remainder is a single label (no `.` allowed → reject multi-label).

**When to use:** Wildcard origin match per CONTEXT.md D-04/D-05. Avoids regex entirely.

**Example:**

```php
// Source: src/Resolver/HostResolver.php::extractSlug() lines 39–67 (template) + CONTEXT.md D-04, D-05
// Compile-time: for entry 'https://*.app.example.com'
//   wildcard_suffix = '.app.example.com'
//   host (template)  = '*.app.example.com'
// Runtime: incoming Origin: 'https://acme.app.example.com'
//   parsed_host = 'acme.app.example.com'
//   if (!str_ends_with($parsed_host, $entry['wildcard_suffix'])) → no match
//   $label = substr($parsed_host, 0, -strlen($entry['wildcard_suffix']))
//   if (str_contains($label, '.') || '' === $label) → reject (would be multi-label)
//   slug = $label  →  'acme'
```

### Anti-Patterns to Avoid

- **`str_contains($origin, $allowEntry)` / `str_ends_with($origin, $allowEntry)`:** Subdomain spoof — `attacker.com.tenant.example.com` matches `tenant.example.com`. **Always** use parsed-URL exact equality (scheme + host + port) or the suffix-strip-with-single-label-verification matcher above. `[CITED: .planning/research/PITFALLS.md line 447, 490]`
- **Trusting `Access-Control-Request-Method` to detect preflight:** Header is unreliable across proxies and CDNs that may strip CORS-bookkeeping headers. Use `$request->getMethod() === 'OPTIONS'` (per D-07). The OPTIONS method alone is sufficient because the only legitimate non-CORS OPTIONS use case is rare (HTTP capability probing); even those benignly return `null` and fall through. `[CITED: https://developer.mozilla.org/en-US/docs/Glossary/Preflight_request — preflight is *defined* as an OPTIONS request]`
- **Resolving the X-Tenant-ID slug for the mismatch warning:** Extra DB roundtrip per request when both headers are set; not worth it for an audit log. Compare textually (D-11). `[CITED: CONTEXT.md D-11]`
- **`lateCollect()` for any future profiler integration:** Tenant context is cleared by `kernel.terminate` — `collect()` (NOT `lateCollect()`) is the only safe hook. Not in Phase 17 scope but worth flagging for the planner since the mismatch warning *could* be tempting to defer to a later phase. `[CITED: .planning/research/SUMMARY.md "Lifecycle correctness" section]`
- **Logging on absent or malformed Origin:** Spammy in misconfigured environments; CONTEXT.md D-09 explicitly forbids it.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| URL parsing (compile-time normalization) | A regex-based URL parser | `parse_url()` from PHP core | Battle-tested; well-known quirks documented (see Common Pitfalls). Hand-rolled parsers miss IPv6, IDN, and trailing-slash edge cases. |
| Allow-list config schema validation | A custom JSON-schema-style validator | Symfony `Configuration` TreeBuilder + `OriginHeaderResolverConfigPass` for cross-entry invariants | Two-layer pattern: tree builder validates per-entry structure; compiler pass validates cross-cutting invariants (non-empty when opted in, no path/query, single leftmost wildcard). Reuses bundle's existing patterns. |
| PSR-3 logger | A custom logging class | `Psr\Log\LoggerInterface` injected; `Psr\Log\NullLogger` as default | Already in transitive deps (`psr/log` 3.0.2). Standard interface; host app's monolog/whatever picks up the warning automatically. |
| Wildcard matcher | A regex like `^https://([^.]+)\.app\.example\.com$` | Suffix-strip-then-verify-single-label per `HostResolver::extractSlug()` | Suffix-strip is faster, simpler, and proven in `HostResolver`. Regex invites escaping bugs and is overkill for the constrained grammar (one leftmost label). |
| Test log capture | A wrapper around `error_log()` or echo | A small `RecordingLogger` PSR-3 implementation, ~30 lines, in `tests/Integration/Resolver/Support/` | Self-contained, no dependency on `monolog/monolog` (which isn't a bundle dep). `monolog/monolog`'s `TestHandler` is the alternative if monolog is already available. `[CITED: https://akrabat.com/using-monologs-testhandler/]` |
| Test kernel | A bespoke kernel | Copy + adapt `tests/Integration/TenantResolutionIntegrationTest.php`'s `ResolverTestKernel` pattern | Pattern is well-established in this codebase (see `MakeResolverChainPublicPass` + `ReplaceTenancyProviderPass`); just inline a new kernel class in the test file or in `Support/`. |

**Key insight:** Phase 17 is almost entirely an exercise in reusing established bundle patterns. The only meaningfully new pattern is `beforeNormalization()->ifString()` — well-documented in Symfony but not yet used in this bundle. Everything else is "copy and adapt" from one of three siblings (`HeaderResolver`, `HostResolver`, `CacheDecoratorContractPass`).

## Common Pitfalls

### Pitfall 1: `parse_url()` quirks at compile-time normalization

**What goes wrong:** A user writes `'allow_list: ['app.example.com']'` (no scheme). `parse_url()` returns `['path' => 'app.example.com']` — no `host`, no `scheme`. If the compiler pass only checks `!isset($parts['host'])` it will throw, but if it tries to be clever and treat the path as a host, it silently mis-parses. Conversely, `'https://example.com/foo'` parses cleanly with `path => '/foo'` — must explicitly reject paths per D-06.

**Why it happens:** PHP's `parse_url()` is lenient by design — it parses URIs of unknown structure. Schemeless inputs become path-only parses; a stray colon in a path may be parsed as a port (historical bug, partially fixed). `[CITED: https://www.php.net/manual/en/function.parse-url.php — note 4]` `[CITED: https://bugs.php.net/bug.php?id=70942]`

**How to avoid:**
- Reject any entry where `parse_url()` returns `false` OR where `!isset($parts['scheme'])` OR where `!isset($parts['host'])`.
- Reject any entry where `isset($parts['path']) && '' !== $parts['path']` (D-06: bare origin only — no path).
- Reject any entry where `isset($parts['query'])` or `isset($parts['fragment'])` (D-06).
- Reject schemes other than `http`/`https` (D-03 permits both).
- Default port at normalize time: `$port = $parts['port'] ?? ('https' === $scheme ? 443 : 80);` (D-02).
- Lowercase the host before storing (URLs are case-insensitive in host component per RFC 3986).

**Warning signs:** Container compiles cleanly but tenants resolve unexpectedly at runtime → almost certainly a parse_url quirk in the normalization step. Add a unit test for each rejected shape.

`[VERIFIED: php -r 'var_dump(parse_url("app.example.com"));' → ['path' => 'app.example.com']; var_dump(parse_url("https://example.com/foo")); → host+path both present]`

### Pitfall 2: `Origin: null` from sandboxed iframes, `file://`, and privacy contexts

**What goes wrong:** A browser sends `Origin: null` from a sandboxed iframe, a `data:` URL, a redirect chain, or a `file://`-loaded HTML page. The literal string `null` (four ASCII characters) appears as the header value. A naive matcher might treat it as a missing header, or worse, try to match it against an allow-list entry literally named `null`. `[CITED: https://datatracker.ietf.org/doc/html/rfc6454 § 7.3]` `[CITED: https://gist.github.com/LanZeroth/2b42d11a36b07adaa5b746828ba67303]`

**Why it happens:** RFC 6454 § 7.3: "Whenever a user agent issues an HTTP request from a 'privacy-sensitive' context, the user agent MUST send the value 'null' in the Origin header field." Opaque origins (sandboxed iframes, file://, data:) serialize to `null`. `[CITED: https://www.rfc-editor.org/rfc/rfc6454.html]`

**How to avoid:**
- Treat the string `null` exactly as a malformed input → `parse_url('null')` returns `['path' => 'null']` (no scheme), which the compile-time validator rejects in allow-list entries and the runtime matcher fails to match. Result: `Origin: null` falls through cleanly. No special-case code needed beyond the scheme-required check.
- Document the behavior explicitly in the Trust Model section: "If the request has `Origin: null` (sandboxed iframe, file://, certain redirect chains), this resolver returns null and the chain falls through."
- **Critical security note:** NEVER allow `null` as a literal allow-list entry. The compiler pass's "scheme required" check already prevents this, but the Trust Model docs should call it out (a `null`-origin allow-list entry would grant tenant access to any sandboxed iframe on the internet — a CORS-trusted-null-origin vulnerability). `[CITED: https://forum.portswigger.net/thread/cors-vulnerability-with-trusted-null-origin-...]`

**Warning signs:** SPA users report tenant resolution failing in some workflows (in-app iframe previews, embedded content) — likely `Origin: null` falling through. The fall-through is correct; the documentation should make it predictable.

### Pitfall 3: Browsers omit `Origin` on same-origin GET requests

**What goes wrong:** A SPA hosted at `https://acme.app.example.com` makes a GET request to `https://acme.app.example.com/api/data`. The browser may omit `Origin` entirely (same-origin GET; spec-allowed but historically inconsistent across browsers — Chrome sends it for all CORS-applicable requests, older Safari/Firefox versions skip it on same-origin GET). The resolver returns `null` and the chain falls through to `HeaderResolver`/`QueryParamResolver`.

**Why it happens:** RFC 6454 originally allowed user agents to omit `Origin` on same-origin GET/HEAD. The Fetch standard later required it, but legacy behavior persists. `[CITED: https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Origin]`

**How to avoid:**
- This is expected behavior — the resolver's `null`-on-absent semantics (D-08) already handle it.
- In practice this means **`HostResolver` will usually resolve the tenant first anyway** (same-origin GET means the request reached `acme.app.example.com`, so `getHost()` returns the right thing). Priority 30 > 25 means `HostResolver` wins. This is the correct behavior — Origin is a SPA cross-origin signal, not a same-origin signal.
- Document in Trust Model: "OriginHeaderResolver only fires on cross-origin XHR/fetch; same-origin requests are resolved by `HostResolver` first."

**Warning signs:** None expected — this is the designed behavior. Surfacing it in docs prevents support questions.

### Pitfall 4: `Origin` spoofability from non-browser clients (THE core security caveat)

**What goes wrong:** A server-to-server attacker with a valid tenant-B user credential sets `Origin: https://tenant-a.app.example.com` from curl/Postman/a malicious mobile app. Our resolver returns tenant-A. If access control is *only* tenant-resolution + authentication-without-cross-check, the attacker accesses tenant-A's data as a tenant-B user.

**Why it happens:** The browser same-origin policy prevents JavaScript from forging the Origin header. But curl, Postman, mobile clients, and server-to-server callers have no such restriction — they can send any Origin they want. `[CITED: .planning/research/PITFALLS.md § Pitfall 5]`

**How to avoid:**
- This pitfall is mitigated *by documentation*, not by code. The Trust Model section (D-20, D-21) is the deliverable.
- The resolver's job is to extract the tenant slug. **Access control is the application's job.** The Trust Model section must say so plainly: *"Origin is a browser-locked routing hint, not an authentication credential. Pair this resolver with your real auth layer (Bearer/cookie/CSRF) for security-sensitive endpoints."*
- The opt-in design (D-14: NOT in default `tenancy.resolvers`) is itself a mitigation — users must consciously enable the resolver.
- The compile-time guard for empty allow-list (D-15) prevents a "default-on but unconfigured" footgun.

**Warning signs:** None at the bundle level. If a user reports an incident, the docs should already have made the trust model explicit; if not, the docs are the bug.

### Pitfall 5: Allow-list normalization mutates the parameter — re-validate in the compiler pass

**What goes wrong:** `loadExtension()` parses and normalizes each entry, then stores the array as `tenancy.origin.allow_list`. If a future refactor short-circuits normalization (e.g., a user injects the parameter directly via DI override, or a refactor moves normalization), the pass needs to still catch invalid entries.

**Why it happens:** Defense-in-depth: parameters can be overridden by `services.yaml`/`services.php` directly; relying solely on `loadExtension()` for validation creates a single point of failure.

**How to avoid:**
- Compiler pass re-verifies each normalized entry's shape: `scheme in [http, https]`, `host non-empty`, `port int 1..65535`, `is_wildcard ∈ {true, false}`, `is_wildcard=true → wildcard_suffix non-null && starts with "."` , `is_wildcard=false → slug non-empty string`.
- Test the pass against directly-injected parameters that bypass `loadExtension()` to prove defense-in-depth.

**Warning signs:** Tests pass at the unit level but fail at integration because a fixture bypassed normalization. Add at least one compiler-pass test that sets `tenancy.origin.allow_list` parameter directly with a malformed entry and asserts the throw.

### Pitfall 6: Logger autowiring breaks when monolog-bundle is absent

**What goes wrong:** Bundle ships expecting `service('logger')`; user has not installed `symfony/monolog-bundle`; container fails to compile because `logger` service doesn't exist.

**Why it happens:** Symfony's `framework.php_errors.log` config registers a logger only when monolog is present (or when explicitly configured). Bundles that hard-depend on `logger` break in monolog-less projects.

**How to avoid:**
- Use `service('logger')->nullOnInvalid()` in the service definition (CONTEXT.md D-12).
- Default constructor arg to `new NullLogger()` so a `null` injection still yields a working resolver.
- No `psr/log` require; the bundle uses the interface from the transitive dependency.
- Unit-test the resolver with `LoggerInterface = new NullLogger()` to prove the mismatch-warning path is null-safe.

**Warning signs:** CI breaks for matrix entries that don't install monolog. Fix is the `nullOnInvalid()` + default ctor arg combo.

## Runtime State Inventory

Not applicable — Phase 17 is a greenfield addition (new resolver + new compiler pass). No rename, no refactor, no migration of stored data.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | None — no schema change, no datastore interaction beyond the existing `TenantProviderInterface::findBySlug()` call. | None. |
| Live service config | None — no external service touched. | None. |
| OS-registered state | None — no OS-level registration. | None. |
| Secrets/env vars | None — no new env vars or secrets. | None. |
| Build artifacts | None — no compiled artifact rename. | None. |

## Code Examples

### Example 1: `TenancyBundle::configure()` — add the `origin` node sibling to `host`

```php
// Source: src/TenancyBundle.php lines 33-68 (template — host: node) + CONTEXT.md D-18, D-19
$definition->rootNode()
    ->children()
        ->scalarNode('driver')->defaultValue('database_per_tenant')->end()
        // ... existing nodes unchanged ...
        ->arrayNode('host')
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('app_domain')->defaultNull()->end()
            ->end()
        ->end()
        ->arrayNode('origin')                                  // NEW
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('allow_list')
                    ->arrayPrototype()
                        ->beforeNormalization()
                            ->ifString()
                            ->then(fn (string $v) => ['origin' => $v, 'slug' => null])
                        ->end()
                        ->children()
                            ->scalarNode('origin')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('slug')->defaultNull()->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ->end()
    ->end()
    ->validate()
        // ... existing validate block unchanged ...
    ->end();
```

### Example 2: `loadExtension()` allow-list normalization and conditional service registration

```php
// Source: src/TenancyBundle.php lines 71-161 (template — opt-in service registration pattern)
//         + CONTEXT.md D-16, D-17

// Inside loadExtension(), after existing parameter sets:
if (in_array('origin', $config['resolvers'] ?? [], true)) {
    /** @var array{allow_list: list<array{origin: string, slug: ?string}>} $originConfig */
    $originConfig = $config['origin'] ?? ['allow_list' => []];

    $normalized = [];
    foreach ($originConfig['allow_list'] as $entry) {
        $normalized[] = $this->normalizeAllowListEntry($entry);
        // normalizeAllowListEntry() (private method on the bundle):
        // - parse_url($entry['origin'])
        // - throw InvalidArgumentException with quoted entry on:
        //     parse fails / no scheme / scheme not http|https / no host /
        //     path present / query present / fragment present
        // - detect is_wildcard (host starts with '*.')
        // - if wildcard: derive wildcard_suffix = '.' + substr(host, 2);
        //   verify no mid-string '*'; verify at least one suffix label
        // - if not wildcard: derive slug = $entry['slug'] ?? throw (explicit-form requires slug)
        // - default port (80/443) based on scheme
        // - lowercase host
        // - return the normalized array shape per D-17
    }

    $container->parameters()->set('tenancy.origin.allow_list', $normalized);

    $services = $container->services();
    $services->set('tenancy.resolver.origin', \Tenancy\Bundle\Resolver\OriginHeaderResolver::class)
        ->args([
            service('tenancy.provider')->nullOnInvalid(),
            service('logger')->nullOnInvalid(),
            param('tenancy.origin.allow_list'),
        ])
        ->tag('tenancy.resolver', ['priority' => 25]);
}
```

**Note on validation locus:** The bundle CAN do all validation inside `loadExtension()` (throws `\InvalidArgumentException` during extension load, which surfaces as a container compile error). The `OriginHeaderResolverConfigPass` is then a defense-in-depth re-check + the "empty allow-list while opted in" cross-cutting check that `loadExtension()` can't easily do alone (because in `loadExtension()` the user's `tenancy.resolvers` list and the `tenancy.origin.allow_list` may be processed in either order depending on bundle config merging).

**Alternative split:** `loadExtension()` does *parsing* only (normalize shape); `OriginHeaderResolverConfigPass` does *validation* (throws). Planner picks one of these two splits — both are defensible. CONTEXT.md D-15 leans toward "compile pass throws hard; loadExtension normalizes." Either works.

### Example 3: `build()` — register the compiler pass

```php
// Source: src/TenancyBundle.php lines 163-173 (template) + CONTEXT.md D-15
public function build(ContainerBuilder $container): void
{
    parent::build($container);
    $container->addCompilerPass(new BootstrapperChainPass());
    $container->addCompilerPass(new ResolverChainPass());
    $container->addCompilerPass(new CacheDecoratorContractPass());
    $container->addCompilerPass(new OriginHeaderResolverConfigPass());  // NEW
    if (interface_exists(MessageBusInterface::class)) {
        $container->addCompilerPass(new MessengerMiddlewarePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1);
    }
}
```

### Example 4: `ResolverChainPass` — one-line short-name registration

```php
// Source: src/DependencyInjection/Compiler/ResolverChainPass.php lines 20-25 (edit) + CONTEXT.md D-13
private const BUILT_IN_RESOLVER_MAP = [
    'host' => HostResolver::class,
    'header' => HeaderResolver::class,
    'query_param' => QueryParamResolver::class,
    'console' => ConsoleResolver::class,
    'origin' => OriginHeaderResolver::class,  // NEW
];
```

### Example 5: Integration kernel pattern (mirrors `ResolverTestKernel`)

```php
// Source: tests/Integration/TenantResolutionIntegrationTest.php lines 29-92 (template) + CONTEXT.md D-24, D-25
// File: tests/Integration/Resolver/Support/OriginResolverTestKernel.php
namespace Tenancy\Bundle\Tests\Integration\Resolver\Support;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Tenancy\Bundle\TenancyBundle;
use Tenancy\Bundle\Tests\Integration\Messenger\Support\StubTenantProvider;
use Tenancy\Bundle\Tests\Integration\Messenger\Support\ReplaceProviderWithStubPass;

final class OriginResolverTestKernel extends Kernel
{
    public function __construct()
    {
        parent::__construct('test_origin_resolver', false);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new TenancyBundle()];
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new ReplaceProviderWithStubPass());
        // + a Make*PublicPass for ResolverChain + TenantContext if not already public
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test-secret', 'test' => true,
                'http_method_override' => false, 'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
            ]);
            $container->loadFromExtension('tenancy', [
                'resolvers' => ['host', 'header', 'origin', 'query_param', 'console'],
                'origin' => [
                    'allow_list' => [
                        ['origin' => 'https://acme.app.example.test', 'slug' => 'acme'],
                        'https://*.beta.app.example.test',
                    ],
                ],
            ]);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tenancy_origin_resolver_'.md5(self::class).'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_origin_resolver_'.md5(self::class).'/logs';
    }
}
```

**Note on reuse:** `StubTenantProvider` already exists at `tests/Integration/Messenger/Support/StubTenantProvider.php` and is shape-compatible (implements `TenantProviderInterface`, has `addTenant()` method, throws `TenantNotFoundException` on miss). Phase 17 should reuse it directly — no need to add a new stub. `ReplaceProviderWithStubPass` exists in the same `Support/` namespace and is also reusable. `[VERIFIED: tests/Integration/Messenger/Support/StubTenantProvider.php]`

### Example 6: `RecordingLogger` for log assertions

```php
// File: tests/Integration/Resolver/Support/RecordingLogger.php
namespace Tenancy\Bundle\Tests\Integration\Resolver\Support;

use Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
```

Use in the test:

```php
$logger = new RecordingLogger();
// inject into the resolver (in a unit test) or replace the 'logger' service
// in the test kernel via compiler pass (in an integration test).
$this->resolver = new OriginHeaderResolver($provider, $logger, $allowList);

$this->resolver->resolve($request);

$this->assertCount(1, $logger->records);
$this->assertSame('warning', $logger->records[0]['level']);
$this->assertSame('origin', $logger->records[0]['context']['winner']);
$this->assertSame('beta', $logger->records[0]['context']['header_slug']);
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| No `Origin`-based resolver in this bundle | `OriginHeaderResolver` at priority 25 | Phase 17 (v0.3) | SPA-friendly cross-origin tenant resolution; closes parity gap with stancl/tenancy v4 PR #621 |
| `parse_url()` historical quirks with port-like substrings | Modern PHP 8.2+ behavior is stable; bugs only persist for IPv6 + path with colons | PHP 7.4+ | Not relevant to allow-list (which only takes scheme+host+port, no query) |
| Naive substring origin matching | Parsed-URL exact-equality matching with explicit single-leftmost-wildcard support | Industry consensus post-2018 CORS-bypass advisories | Mandatory for any production allow-list |

**Deprecated/outdated:**
- Treating `Origin` as authentication: NEVER. Trust Model section makes this explicit. `[CITED: .planning/research/PITFALLS.md § Pitfall 5]`
- `Access-Control-Request-Method`-based preflight detection: unreliable; use OPTIONS method check. `[CITED: https://developer.mozilla.org/en-US/docs/Glossary/Preflight_request]`

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | The locked decisions D-01..D-25 in CONTEXT.md are final and the planner will treat them as such. | User Constraints | If the user reopens any decision, the planner must re-derive that part of the plan. CONTEXT.md explicitly invites redirection before planning. |
| A2 | `monolog/monolog` is NOT a hard dependency of this bundle (verified absent from composer.json); therefore log capture in tests uses a custom `RecordingLogger`, not `Monolog\Handler\TestHandler`. | Standard Stack, Code Examples Ex. 6 | If monolog were preferred, use `TestHandler`. Verified against `composer.json` — monolog not present. |
| A3 | The `psr/log` 3.0.2 transitive version is stable for the v0.3 milestone window (PSR-3 LoggerInterface is interface-frozen). | Standard Stack | Very low risk — PSR-3 LoggerInterface signature is stable since 2012. |
| A4 | The `beforeNormalization()->ifString()->then(fn)` pattern works correctly on `arrayPrototype()` children (it definitely works there; the GitHub issue #28923 is specifically about it not working on the *root* node, which doesn't apply here). | Architecture Patterns Pattern 3 | Low risk — `[CITED: Symfony docs explicitly demonstrate the pattern on prototype children]`. Verifiable in the unit test for the Configuration class. |
| A5 | Existing `StubTenantProvider` at `tests/Integration/Messenger/Support/` is reusable from the new test namespace `tests/Integration/Resolver/Support/` (PSR-4 autoload covers both via `Tenancy\Bundle\Tests\` root). | Code Examples Ex. 5 | Low risk — confirmed via `composer.json` autoload-dev block (`Tenancy\Bundle\Tests\` → `tests/`). If a circular dep across test subnamespaces is a concern, copy the StubTenantProvider into `Resolver/Support/`. |
| A6 | The `OriginHeaderResolverConfigPass` re-verifying parameter shape (defense-in-depth) is the correct interpretation of CONTEXT.md D-15. The alternative is "loadExtension() does all validation; the pass only checks the cross-cutting empty-while-opted-in invariant." Both are defensible; the discretion block (D-15 specifics) is silent on which. | Pitfalls Pitfall 5, Code Examples Ex. 2 | Planner picks. Both pass the acceptance criteria. |
| A7 | CONTEXT.md references `tests/Integration/_kernel/` but the actual code has `tests/Integration/TestKernel.php` (no `_kernel/` directory). Phase 17 should NOT modify `tests/Integration/TestKernel.php`; the new test kernel lives in `tests/Integration/Resolver/Support/`. | Recommended Project Structure, User Constraints D-25 | Confirmed by `find` against the codebase — no `_kernel/` directory exists. CONTEXT.md text is slightly inaccurate but the intent is clear: don't touch shared fixtures. |

## Open Questions

1. **Should the resolver expose a `getResolvedOrigin()`/`getResolvedAllowListEntry()` method for the future Profiler tab (Phase 19 / DX-02)?**
   - What we know: Phase 19 will add a `TenantDataCollector` that needs `resolved_by` and contextual data (DEC-PROF-01).
   - What's unclear: Whether to add a public method now or let Phase 19 add it later.
   - Recommendation: Defer. Add it in Phase 19 when the data collector is built. Phase 17's surface is leaner without it; YAGNI.

2. **Should `tenancy.origin.allow_list` parameter validation throw `\InvalidArgumentException` or `\LogicException`?**
   - What we know: `CacheDecoratorContractPass` uses `\LogicException` (line 63); `TenancyBundle::configure()` `validate()` block uses `thenInvalid()` which throws `InvalidConfigurationException`.
   - What's unclear: The "right" exception class for compile-time config validation in this bundle.
   - Recommendation: Use `\InvalidArgumentException` — it semantically matches "the caller-supplied config is invalid" and is the convention for parameter validation. `\LogicException` reads as "the *code* is wrong" (e.g., a missing interface on a decorator class), which is a different category.

3. **Does the integration test need to assert on `TenantBootstrapped` event firing, or is asserting on `TenantContext::getTenant()->getSlug()` after `handle()` sufficient?**
   - What we know: CONTEXT.md D-24 says "asserts `TenantContext::getTenant()->getSlug()` matches the expected slug after `kernel.request`."
   - What's unclear: Whether the test also needs to assert on the event.
   - Recommendation: Context-only assertion is sufficient. The event is tested by Phase 02 fixtures; re-testing in Phase 17 is redundant.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | All | ✓ | 8.4.12 (system) — composer.json requires ^8.2 | — |
| PHPUnit | Tests | ✓ | 11.5.55 | — |
| `symfony/http-foundation` | `Request::headers`, `Request::getMethod()` | ✓ | ^7.4 \|\| ^8.0 (per composer.json) | — |
| `symfony/dependency-injection` | Compiler pass | ✓ | ^7.4 \|\| ^8.0 | — |
| `symfony/config` | `Configuration::beforeNormalization()` | ✓ | ^7.4 \|\| ^8.0 | — |
| `psr/log` | `LoggerInterface`, `NullLogger` | ✓ (transitive) | 3.0.2 | — |
| `symfony/framework-bundle` | Integration test kernel | ✓ (dev) | ^7.4 \|\| ^8.0 | — |
| `monolog/monolog` | (Optional — `TestHandler` for log assertions) | ✗ | — | Use a custom `RecordingLogger` PSR-3 implementation (~30 lines) instead |

**Missing dependencies with no fallback:** None.

**Missing dependencies with fallback:** Only monolog (test log assertion). `RecordingLogger` fallback is preferable anyway — keeps tests self-contained without adding a non-bundle dependency. `[VERIFIED: 2026-05-15 via composer show & composer.json]`

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5.55 |
| Config file | `phpunit.xml.dist` at repo root (existing) |
| Quick run command | `vendor/bin/phpunit --testsuite unit` (unit only, sub-second per test class) |
| Full suite command | `vendor/bin/phpunit` (full unit + integration; integration boots minimal kernel) |
| Per-test-class | `vendor/bin/phpunit tests/Unit/Resolver/OriginHeaderResolverTest.php` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| RESV-06 | Implements `TenantResolverInterface`; tagged with priority 25 | unit (reflection / DI assertion) | `vendor/bin/phpunit tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php` | ❌ Wave 0 |
| RESV-06 | Parsed-URL exact-equality matching (scheme + host + port) | unit | `vendor/bin/phpunit tests/Unit/Resolver/OriginHeaderResolverTest.php --filter=testExactMatch` | ❌ Wave 0 |
| RESV-06 | Wildcard with leftmost label resolves slug = label | unit | `vendor/bin/phpunit tests/Unit/Resolver/OriginHeaderResolverTest.php --filter=testWildcardMatch` | ❌ Wave 0 |
| RESV-06 | Returns null on absent `Origin` | unit | `vendor/bin/phpunit tests/Unit/Resolver/OriginHeaderResolverTest.php --filter=testReturnsNullWhenHeaderAbsent` | ❌ Wave 0 |
| RESV-06 | Returns null on CORS preflight (OPTIONS) | unit | `vendor/bin/phpunit tests/Unit/Resolver/OriginHeaderResolverTest.php --filter=testPreflightReturnsNull` | ❌ Wave 0 |
| RESV-06 | Returns null on malformed/unknown Origin (no throw) | unit | `vendor/bin/phpunit tests/Unit/Resolver/OriginHeaderResolverTest.php --filter=testMalformedOriginReturnsNull` | ❌ Wave 0 |
| RESV-06 | Bubbles `TenantInactiveException` | unit | `vendor/bin/phpunit tests/Unit/Resolver/OriginHeaderResolverTest.php --filter=testInactiveBubbles` | ❌ Wave 0 |
| RESV-06 | Warning log on Origin/X-Tenant-ID mismatch with structured context | unit (RecordingLogger) | `vendor/bin/phpunit tests/Unit/Resolver/OriginHeaderResolverTest.php --filter=testMismatchWarning` | ❌ Wave 0 |
| RESV-06 | Compile-time rejection: empty allow-list while `origin` opted in | unit (compiler pass) | `vendor/bin/phpunit tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php --filter=testEmpty` | ❌ Wave 0 |
| RESV-06 | Compile-time rejection: unparseable URL | unit (compiler pass) | `vendor/bin/phpunit tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php --filter=testUnparseable` | ❌ Wave 0 |
| RESV-06 | Compile-time rejection: mid-string wildcard | unit (compiler pass) | `vendor/bin/phpunit tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php --filter=testMidStringWildcard` | ❌ Wave 0 |
| RESV-06 | Compile-time rejection: path/query in origin | unit (compiler pass) | `vendor/bin/phpunit tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php --filter=testPathInOrigin` | ❌ Wave 0 |
| RESV-06 | End-to-end: configured kernel resolves tenant from `Origin` header | integration | `vendor/bin/phpunit tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php --filter=testResolvesFromOrigin` | ❌ Wave 0 |
| RESV-06 | End-to-end: preflight does not populate tenant context | integration | `vendor/bin/phpunit tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php --filter=testPreflightLeavesContextEmpty` | ❌ Wave 0 |
| RESV-06 | End-to-end: mismatch warning captured by injected logger | integration | `vendor/bin/phpunit tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php --filter=testMismatchWarningLogged` | ❌ Wave 0 |
| RESV-06 | Trust Model docs section exists | docs lint | `grep -i "Trust Model" docs/user-guide/origin-header-resolver.md` (manual or in `scripts/docs-lint.sh` extension) | ❌ Wave 0 |

### Sampling Rate

- **Per task commit:** `vendor/bin/phpunit --testsuite unit` (target: < 10s total; resolver unit + compiler-pass unit cover the core behavior).
- **Per wave merge:** `vendor/bin/phpunit` (full suite; integration adds ~5s).
- **Phase gate:** Full suite green + `vendor/bin/phpstan analyse` clean (level 9) + `vendor/bin/php-cs-fixer check --diff` clean before `/gsd-verify-work`.

### Wave 0 Gaps

- [ ] `tests/Unit/Resolver/OriginHeaderResolverTest.php` — covers RESV-06 (runtime resolver behavior)
- [ ] `tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php` — covers RESV-06 (compile-time guard)
- [ ] `tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php` — covers RESV-06 (end-to-end DI wiring + log capture)
- [ ] `tests/Integration/Resolver/Support/OriginResolverTestKernel.php` — kernel fixture
- [ ] `tests/Integration/Resolver/Support/RecordingLogger.php` — PSR-3 log capture for assertions
- [ ] Framework install: none — PHPUnit, framework-bundle, http-foundation all present
- [ ] (Optional) PHPStan baseline addition or extension if new generics need it — verify clean against level 9 in the resolver

### Runtime Observability Probes (Nyquist Validation)

Phase 17 has two security-sensitive surfaces — the compile-time guard and the warning-level log — that warrant explicit observability assertion.

**Compile-time guard observability:** The compiler pass throws `\InvalidArgumentException` with a structured message format (CONTEXT.md "specifics" block). The error message is itself the observability signal — it must:
1. Name the offending allow-list entry verbatim (so users can grep their config).
2. State the rule that was violated (so users know why it failed).
3. Suggest the remediation (so users know how to fix it).

Test assertion: each `OriginHeaderResolverConfigPassTest::test*` method asserts on `$this->expectExceptionMessage($substring)` for the entry name AND for the rule violation phrase. This double assertion regression-guards against silently rewording error messages in future refactors.

**Runtime warning-log observability:** The mismatch warning is the only operational signal that an attacker (or a misconfigured client) is sending inconsistent tenant identifiers. The structured context fields are forensic-query-shaped:
- `origin` — exact header value (post-validation)
- `origin_slug` — tenant slug resolved from Origin
- `header_slug` — raw X-Tenant-ID value (NOT resolved — no extra DB roundtrip)
- `winner` — always `'origin'` (locks the contract; if a future phase changes the winner this becomes a forensic discrepancy signal)

Test assertion: `RecordingLogger::records[0]['context']` is asserted key-by-key, including `'winner' => 'origin'`. This locks the contract; a future change to the warning shape will fail this test, forcing the change to be deliberate.

**Sampling adequacy (Nyquist principle):** A single integration test covers the full DI + Request → ResolverChain → Origin matcher → provider → context-set + warning-log loop. The unit-test fan-out covers each branch (preflight, absent, malformed, exact match, wildcard match, unknown slug, inactive, mismatch). Together: every observable behavior has at least one automated probe; the warning-context shape has a structured-field assertion that resists silent reshaping.

## Security Domain

Security enforcement is **enabled** (no explicit `false` in `.planning/config.json`). This phase has a high security profile — it adds a new tenant-identification surface whose trust model is non-obvious.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | NO | Resolver is identification (routing), not authentication. Trust Model docs section makes this distinction explicit. |
| V3 Session Management | NO | No session state introduced. |
| V4 Access Control | INDIRECT | Resolver outputs tenant identity that downstream access control consumes. Bundle does NOT enforce access control; the docs MUST tell users that pairing with auth is mandatory. |
| V5 Input Validation | YES | Compile-time: Symfony Configuration TreeBuilder + `OriginHeaderResolverConfigPass`. Runtime: `parse_url()` + scheme/host/port checks; returns `null` on malformed input. |
| V6 Cryptography | NO | No cryptographic operations. |
| V13 API & Web Service | YES | The resolver is a public HTTP attack surface. Allow-list-driven exact matching is the V13 control. |
| V14 Configuration | YES | Compile-time guard rejecting empty/malformed allow-lists is the V14 control. |

### Known Threat Patterns for the resolver tier

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| **Subdomain spoof in allow-list match** (e.g., `attacker.com.tenant.example.com` matches `tenant.example.com` via substring/`endsWith`) | Spoofing | Parsed-URL exact equality (scheme + host + port); single-leftmost-wildcard with strict label cardinality. NEVER `str_contains`/`endsWith`. |
| **Trusted-null-origin** (allow-list entry literally `null`, granting access to any sandboxed iframe) | Spoofing | Compile-time guard rejects entries with no scheme; `null` (4 ASCII chars) has no scheme; rejected. Doc explicitly mentions in Trust Model. |
| **Non-browser Origin spoofing** (curl/Postman set arbitrary Origin) | Spoofing | Documentation-only mitigation: Trust Model section explains the limitation and requires auth-layer pairing. Bundle cannot prevent this technically. |
| **CORS preflight 500** (orchestrator throws on OPTIONS request because resolver doesn't handle it) | Denial of Service (SPA cannot complete the actual request) | D-07 preflight short-circuit returns `null`; chain proceeds; orchestrator's null-path leaves context empty; CORS middleware responds normally. |
| **Origin/X-Tenant-ID confusion** (legit dev workflow vs. credential-stuffing) | Tampering | Warning log on mismatch with structured context; documented in Mismatch Warning docs section. NOT an exception — could be a legitimate dev/testing flow. |
| **Compile-time bypass** (empty allow-list silently means "match nothing" so resolver is benign-but-broken) | Configuration error → silent feature failure | `OriginHeaderResolverConfigPass` throws on empty allow-list while `origin` opted in. Container fails to compile. Better than runtime silence. |
| **Misordered resolver priority** (operator changes priority to put Origin above Host, accidentally exposing wrong tenant) | Configuration error | DEC-RESV-01 locks priority at 25; this is a built-in tag — operators can't change it without overriding the service definition. Document the priority contract in resolver docblock. |

**Failure-safe by design:** The four most important security properties are baked in by config and compile-time guards:
1. Resolver is **opt-in** (D-14) — must be explicitly added to `tenancy.resolvers`.
2. Empty allow-list **fails container compilation** (D-15) — no silent "match-nothing" state.
3. Path/query/fragment in allow-list entries **fails compilation** (D-06) — no covert URL-component matching.
4. Mid-string and multi-label wildcards **fail compilation** (D-05) — no surprise wildcard semantics.

### CLAUDE.md security requirements

`strict_mode` (defaults ON, CLAUDE.md "A data leak is a security incident"):
- Phase 17 does NOT interact with `strict_mode` directly (that's a SharedDriver concern). But the spirit applies: the resolver MUST exact-match the allow-list and MUST NOT substring/regex/glob.
- Documentation MUST explicitly say "Origin is a routing hint, not authentication" — failure to document is treated with the same severity as a data-leak code path.

## Sources

### Primary (HIGH confidence)
- `.planning/REQUIREMENTS.md` § RESV-06 (lines 52–59) and DEC-RESV-01 (line 133) — locked acceptance criteria.
- `.planning/research/SUMMARY.md` § "Critical Pitfalls #5", "Compile-Time Guards", "Phase 1 — RESV-06".
- `.planning/research/PITFALLS.md` § "Pitfall 5: OriginHeaderResolver trusts a browser-controlled header" (lines 251–302) — full security analysis.
- `.planning/phases/17-origin-header-resolver/17-CONTEXT.md` — D-01..D-25 locked decisions.
- `src/Resolver/HeaderResolver.php` (lines 1–36) — exact template for resolver class shape.
- `src/Resolver/HostResolver.php` (lines 39–67) — `extractSlug()` suffix-strip wildcard matcher pattern.
- `src/Resolver/ResolverChain.php` — chain semantics, `TenantResolution` return shape.
- `src/DependencyInjection/Compiler/ResolverChainPass.php` (lines 20–25) — `BUILT_IN_RESOLVER_MAP` extension point.
- `src/DependencyInjection/Compiler/CacheDecoratorContractPass.php` (lines 24–66) — compile-time contract pass template.
- `src/TenancyBundle.php` (lines 33–229) — `configure()` + `loadExtension()` + `build()` integration points.
- `src/Exception/TenantNotFoundException.php`, `src/Exception/TenantInactiveException.php` — existing exception semantics.
- `tests/Unit/Resolver/HeaderResolverTest.php` (lines 1–87) — unit test pattern.
- `tests/Integration/TenantResolutionIntegrationTest.php` (lines 29–92) — integration kernel pattern (`ResolverTestKernel`, `MakeResolverChainPublicPass`, `ReplaceTenancyProviderPass`).
- `tests/Integration/Messenger/Support/StubTenant.php`, `StubTenantProvider.php`, `ReplaceProviderWithStubPass.php` — reusable test fixtures.
- `tests/Integration/Support/NullTenantProvider.php`, `ReplaceTenancyProviderPass.php` — alternative test fixtures.
- `composer.json` (lines 20–48) — dependency versions, scope (require vs dev vs suggest).
- [Symfony Docs — Defining and Processing Configuration Values](https://symfony.com/doc/current/components/config/definition.html) — `beforeNormalization()->ifString()->then()` pattern (Pattern 3).
- [RFC 6454 — The Web Origin Concept](https://www.rfc-editor.org/rfc/rfc6454.html) — Origin grammar, `null` semantics, privacy-sensitive context.
- [PHP Manual — parse_url](https://www.php.net/manual/en/function.parse-url.php) — return shape, return values for malformed inputs.
- [MDN — Preflight request](https://developer.mozilla.org/en-US/docs/Glossary/Preflight_request) — preflight is an OPTIONS request by definition.
- [archtechx/tenancy PR #621](https://github.com/archtechx/tenancy/pull/621) — sibling-ecosystem reference implementation for Origin header resolver in Laravel.

### Secondary (MEDIUM confidence)
- [Symfony issue #28923](https://github.com/symfony/symfony/issues/28923) — confirms `beforeNormalization->ifString` works on child/prototype nodes (the issue is about the root node).
- [Rob Allen — Using Monolog's TestHandler](https://akrabat.com/using-monologs-testhandler/) — alternative log-capture approach (rejected for this bundle since monolog is not a dep).
- [PortSwigger Forum — CORS vulnerability with trusted null origin](https://forum.portswigger.net/thread/cors-vulnerability-with-trusted-null-origin-origin-header-null-for-xhr-request-made-from-iframe-with-sandbox-attribute-daaf528f) — `Origin: null` allow-list risk.
- [Explanation of null Origin Header — gist](https://gist.github.com/LanZeroth/2b42d11a36b07adaa5b746828ba67303) — full enumeration of contexts that produce `Origin: null`.
- [PHP Bug #70942 — parse_url incorrect port detection](https://bugs.php.net/bug.php?id=70942) — historical parse_url quirks (mostly irrelevant for our scheme+host+port matching but flagged for completeness).

### Tertiary (LOW confidence — none)
None — all claims in this research are either verified against the codebase or cited to authoritative external sources.

## Metadata

**Confidence breakdown:**
- Standard stack: **HIGH** — every library is already a hard dep, version-verified via composer; no new packages.
- Architecture: **HIGH** — every pattern (resolver shape, compiler pass shape, beforeNormalization shorthand, suffix-strip matcher, test kernel) has either a direct codebase template or an authoritative Symfony-docs citation.
- Pitfalls: **HIGH** — Origin spoofability, `Origin: null`, parse_url quirks, preflight detection are all verified against RFC/MDN/PHP-docs; the bundle's existing `PITFALLS.md` pre-validates the core security analysis.
- Validation Architecture: **HIGH** — test framework, fixtures, and assertion patterns all map 1:1 to existing test classes in this codebase.
- Security Domain: **HIGH** — STRIDE patterns match the seven explicit threats in `PITFALLS.md` § Pitfall 5.

**Research date:** 2026-05-15
**Valid until:** 2026-06-14 (30 days — Symfony/PHP/PSR-3 are stable; only re-research if Symfony 8.0 introduces a breaking change to Configuration TreeBuilder, which is improbable in a minor cycle).
