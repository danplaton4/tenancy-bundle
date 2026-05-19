---
id: 17-P02
phase: 17
plan: 02
name: OriginHeaderResolverConfigPass compile-time guard + unit tests
wave: 1
depends_on: []
files_modified:
  - src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php
  - tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php
autonomous: true
requirements: [RESV-06]
threats: [T-17-02, T-17-03]
must_haves:
  truths:
    - "Container compilation fails with `InvalidArgumentException` and a message naming the offending entry when an allow_list entry contains a mid-string wildcard"
    - "Container compilation fails with `InvalidArgumentException` and a clear message when `'origin'` is in `tenancy.resolvers` but the `tenancy.origin.allow_list` parameter is empty or unset"
    - "Container compilation fails when an allow_list entry contains a path/query/fragment component"
    - "When `'origin'` is NOT in `tenancy.resolvers`, the pass returns silently — no exception even with an empty/missing allow_list parameter"
    - "When the allow-list is valid the pass writes back a normalized parameter where every entry has a deterministic port and shape"
  artifacts:
    - path: src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php
      provides: "Final CompilerPassInterface implementation gating origin resolver config"
      contains: "final class OriginHeaderResolverConfigPass implements CompilerPassInterface"
    - path: tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php
      provides: "Seven test cases per CONTEXT.md D-23 covering valid + each invalid shape + the no-op short-circuit"
  key_links:
    - from: src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php
      to: "Container parameter `tenancy.origin.allow_list`"
      via: "ContainerBuilder::getParameter / setParameter"
      pattern: "getParameter\\('tenancy\\.origin\\.allow_list'\\)"
    - from: src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php
      to: "Container parameter `tenancy.resolvers`"
      via: "ContainerBuilder::getParameter (read-only gate)"
      pattern: "in_array\\('origin', .+true\\)"
---

<objective>
Ship `OriginHeaderResolverConfigPass` — the compile-time guard that validates `tenancy.origin.allow_list` BEFORE the container is built. It normalizes each entry (port default per D-02), rejects empty lists / unparseable URLs / mid-string wildcards / multi-label wildcards / pure-`*` wildcards / path-or-query bodies, and writes the normalized result back as the same container parameter so Plan 03's resolver service definition consumes it as-is.

Purpose: A misconfigured allow-list at runtime is the security pitfall the whole phase is designed to neuter (T-17-02, T-17-03). Compile-time rejection turns a runtime data leak into a container build error with a clear message naming the offending entry.

Output: One new compiler-pass class and one unit test suite. The pass is independent of Plan 01's resolver class (only reads/writes container parameters; doesn't depend on `OriginHeaderResolver` symbol).
</objective>

<execution_context>
@/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/.claude/get-shit-done/workflows/execute-plan.md
@/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/phases/17-origin-header-resolver/17-CONTEXT.md
@.planning/phases/17-origin-header-resolver/17-RESEARCH.md
@.planning/phases/17-origin-header-resolver/17-PATTERNS.md
@CLAUDE.md
@src/DependencyInjection/Compiler/CacheDecoratorContractPass.php
@src/DependencyInjection/Compiler/ResolverChainPass.php

<interfaces>
<!-- Symfony CompilerPassInterface contract executor will implement. -->

`Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface`:
```php
interface CompilerPassInterface
{
    public function process(ContainerBuilder $container): void;
}
```

The pass consumes two container parameters (set by `TenancyBundle::loadExtension()` in Plan 03 — for this plan, treat the contract as "if set, do this; if not, treat as gate"):
- `tenancy.resolvers` — `list<string>` of short names (e.g. `['host', 'header', 'origin']`)
- `tenancy.origin.allow_list` — `list<array{origin: string, slug: ?string}>` of RAW entries (un-normalized; the pass normalizes them into the full D-17 shape)

After the pass, `tenancy.origin.allow_list` MUST hold the fully normalized D-17 shape:
```php
list<array{
    origin: string,           // "https://acme.app.example.com:443"
    host: string,             // "acme.app.example.com" (lowercased)
    scheme: string,           // "http"|"https"
    port: int,                // 80|443 etc
    is_wildcard: bool,
    wildcard_suffix: ?string, // ".app.example.com" or null
    slug: ?string,            // explicit slug or null for wildcard
}>
```

This is the EXACT shape Plan 01's `OriginHeaderResolver::__construct` array parameter accepts (Task 2 of Plan 01).
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Create OriginHeaderResolverConfigPass</name>
  <files>src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php</files>
  <read_first>
    - src/DependencyInjection/Compiler/CacheDecoratorContractPass.php (the analog — copy class header doc-block style, `final class` + `implements CompilerPassInterface`, `sprintf`-based throw pattern, short-circuit-on-missing convention)
    - src/DependencyInjection/Compiler/ResolverChainPass.php (shows how `tenancy.resolvers` parameter is read elsewhere — same idiom must be used)
    - .planning/phases/17-origin-header-resolver/17-CONTEXT.md decisions D-02, D-05, D-06, D-15, D-17 (these dictate exact behavior)
    - .planning/phases/17-origin-header-resolver/17-CONTEXT.md `<specifics>` lines 227-231 (verbatim error-message strings)
  </read_first>
  <behavior>
    Step-by-step `process(ContainerBuilder $container): void`:
    1. Read `tenancy.resolvers` parameter; if not set → return (D-15 no-op)
    2. If `'origin'` is NOT in that list → return (D-15 no-op — pass is unconditionally registered in TenancyBundle::build but self-gates)
    3. Read `tenancy.origin.allow_list` parameter. If not set OR empty array → throw `\InvalidArgumentException` with the message `'tenancy.origin.allow_list is empty but "origin" is configured in tenancy.resolvers — either remove "origin" from resolvers or add at least one allow-list entry'`
    4. For each entry, validate AND normalize:
       a. Entry MUST be an array with a non-empty string `origin` key. If missing/empty → throw `'tenancy.origin.allow_list entry "[entry]" is unparseable — must be an absolute origin URL (scheme://host[:port])'` (with `[entry]` = the var_export of the raw value, sanitized).
       b. Run `parse_url($entry['origin'])`. If `false`, or missing `scheme`, or missing `host` → throw the unparseable message.
       c. Scheme MUST be `http` or `https` (lowercased) → otherwise throw unparseable message.
       d. If the parsed structure has `path`, `query`, or `fragment` (any non-empty value of those) → throw `'tenancy.origin.allow_list entry "<entry>" contains a path/query — origin URLs must be bare authorities'`.
       e. Detect wildcard: lowercased host either is exactly `'*'` (pure-`*` → throw), starts with `'*.'` and has no further `*` (valid leftmost wildcard), or has NO `*` at all (exact entry). Any other `*` placement → throw `'tenancy.origin.allow_list entry "<entry>" contains a mid-string wildcard — only one leftmost label may be "*"'`. Multi-`*` (e.g. `*.*.example.com` or `*.example.*`) → throw the same mid-string message.
       f. Compute `$port = $parts['port'] ?? ('https' === $scheme ? 443 : 80)` (D-02).
       g. Build normalized entry:
          - non-wildcard: `origin = sprintf('%s://%s:%d', $scheme, $host, $port)`, `host = strtolower(host)`, `scheme`, `port`, `is_wildcard = false`, `wildcard_suffix = null`, `slug = $entry['slug'] ?? null` (must be non-empty string; if null/empty → throw `'tenancy.origin.allow_list entry "<entry>" requires an explicit slug when the origin contains no wildcard label'`).
          - wildcard: same `origin` field (preserves the `*` literally for diagnostic logs), `host = strtolower(host)`, `scheme`, `port`, `is_wildcard = true`, `wildcard_suffix = '.'.substr($host, 2)` (the part after `*.`, prefixed with a dot for `str_ends_with` matching), `slug = null` (slug is derived at runtime from the matched label).
    5. Write the normalized list back: `$container->setParameter('tenancy.origin.allow_list', $normalized)`.
  </behavior>
  <action>
Create `src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php` with the content below. Class header doc-block follows `CacheDecoratorContractPass` style (multi-line, explains the invariant guarded and why compile-time). Throws use `sprintf` quoting the offending raw entry.

```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Asserts at container compile time that the `tenancy.origin.allow_list` parameter is
 * non-empty and well-formed whenever the `'origin'` resolver short-name is configured
 * in `tenancy.resolvers`. Normalizes each entry (port defaults, lowercased host,
 * wildcard-suffix extraction) so the runtime resolver does pure equality + suffix-check.
 *
 * Without this pass, an empty allow-list silently auto-rejects every Origin (no tenant
 * resolves and the chain falls through), a mid-string wildcard `app.*.example.com`
 * compiles fine and creates a routing footgun, and a path-bearing origin like
 * `https://acme.app.example.com/api` parses cleanly but mismatches every browser-sent
 * `Origin` value. This pass turns each of those into a clear container-build error.
 *
 * Self-gated: short-circuits silently when `'origin'` is not in `tenancy.resolvers`,
 * so the pass can be registered unconditionally in `TenancyBundle::build()`.
 */
final class OriginHeaderResolverConfigPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('tenancy.resolvers')) {
            return;
        }

        /** @var list<string> $resolvers */
        $resolvers = $container->getParameter('tenancy.resolvers');
        if (!in_array('origin', $resolvers, true)) {
            return;
        }

        $allowList = $container->hasParameter('tenancy.origin.allow_list')
            ? $container->getParameter('tenancy.origin.allow_list')
            : [];

        if (!is_array($allowList) || [] === $allowList) {
            throw new \InvalidArgumentException('tenancy.origin.allow_list is empty but "origin" is configured in tenancy.resolvers — either remove "origin" from resolvers or add at least one allow-list entry');
        }

        $normalized = [];
        foreach ($allowList as $entry) {
            $normalized[] = $this->normalizeEntry($entry);
        }

        $container->setParameter('tenancy.origin.allow_list', $normalized);
    }

    /**
     * @param mixed $entry
     *
     * @return array{
     *     origin: string, host: string, scheme: string, port: int,
     *     is_wildcard: bool, wildcard_suffix: ?string, slug: ?string,
     * }
     */
    private function normalizeEntry(mixed $entry): array
    {
        if (!is_array($entry) || !isset($entry['origin']) || !is_string($entry['origin']) || '' === $entry['origin']) {
            throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list entry "%s" is unparseable — must be an absolute origin URL (scheme://host[:port])', $this->describe($entry)));
        }

        $raw = $entry['origin'];
        $parts = parse_url($raw);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list entry "%s" is unparseable — must be an absolute origin URL (scheme://host[:port])', $raw));
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ('http' !== $scheme && 'https' !== $scheme) {
            throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list entry "%s" is unparseable — must be an absolute origin URL (scheme://host[:port])', $raw));
        }

        if (isset($parts['path']) && '' !== $parts['path']
            || isset($parts['query']) && '' !== $parts['query']
            || isset($parts['fragment']) && '' !== $parts['fragment']
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list entry "%s" contains a path/query — origin URLs must be bare authorities', $raw));
        }

        $host = strtolower((string) $parts['host']);
        if ('' === $host) {
            throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list entry "%s" is unparseable — must be an absolute origin URL (scheme://host[:port])', $raw));
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ('https' === $scheme ? 443 : 80);

        $isWildcard = false;
        $wildcardSuffix = null;
        if (str_contains($host, '*')) {
            // Acceptable shape: exactly "*." followed by a label-bearing suffix, no further "*".
            if (!str_starts_with($host, '*.') || substr_count($host, '*') !== 1) {
                throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list entry "%s" contains a mid-string wildcard — only one leftmost label may be "*"', $raw));
            }
            $tail = substr($host, 2); // drop "*."
            if ('' === $tail || str_starts_with($tail, '.') || !str_contains($tail, '.')) {
                // Examples rejected: "*", "*.", "*.com" with empty/degenerate suffix, "*..foo"
                throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list entry "%s" contains a mid-string wildcard — only one leftmost label may be "*"', $raw));
            }
            $isWildcard = true;
            $wildcardSuffix = '.'.$tail;
        }

        $slug = $entry['slug'] ?? null;
        if (null !== $slug && (!is_string($slug) || '' === $slug)) {
            throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list entry "%s" has an invalid slug — must be a non-empty string or null (wildcard entries derive slug at runtime)', $raw));
        }
        if (!$isWildcard && (null === $slug || '' === $slug)) {
            throw new \InvalidArgumentException(sprintf('tenancy.origin.allow_list entry "%s" requires an explicit slug when the origin contains no wildcard label', $raw));
        }

        return [
            'origin' => sprintf('%s://%s:%d', $scheme, $host, $port),
            'host' => $host,
            'scheme' => $scheme,
            'port' => $port,
            'is_wildcard' => $isWildcard,
            'wildcard_suffix' => $wildcardSuffix,
            'slug' => $isWildcard ? null : $slug,
        ];
    }

    private function describe(mixed $entry): string
    {
        if (is_string($entry)) {
            return $entry;
        }
        if (is_array($entry) && isset($entry['origin']) && is_string($entry['origin'])) {
            return $entry['origin'];
        }

        return get_debug_type($entry);
    }
}
```

Notes:
- The four error-message strings on lines naming the offending entry come **verbatim** from CONTEXT.md `<specifics>` lines 227-231 (apart from `<entry>` token substitution). DO NOT paraphrase them — Plan 02 test cases assert on the literal strings.
- Userinfo (`user:pass@`) in an origin is also forbidden (origins per RFC 6454 have no userinfo); the path/query branch catches `$parts['user']`/`$parts['pass']` and routes them through the same error.
- The "explicit slug required for non-wildcard" check (last new error) is implied by D-01: the explicit-map form mandates `slug`, and the wildcard map form makes `slug` null. A non-wildcard entry without a slug would silently null the resolver output and is an obvious user mistake — fail fast.
  </action>
  <verify>
    <automated>php -l "src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php" &amp;&amp; vendor/bin/phpstan analyse src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php --level=9 --no-progress 2>&amp;1 | tail -5</automated>
  </verify>
  <acceptance_criteria>
    - File `src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php` exists
    - `php -l` exits 0
    - Contains exactly `final class OriginHeaderResolverConfigPass implements CompilerPassInterface`
    - Contains all four verbatim error-message substrings: `'is empty but "origin" is configured in tenancy.resolvers'`, `'is unparseable — must be an absolute origin URL'`, `'contains a path/query — origin URLs must be bare authorities'`, `'contains a mid-string wildcard — only one leftmost label may be "*"'`
    - Contains short-circuit `if (!$container->hasParameter('tenancy.resolvers'))` returning early
    - Contains the `in_array('origin', $resolvers, true)` self-gate
    - Writes back the normalized parameter via `setParameter('tenancy.origin.allow_list', $normalized)`
    - `vendor/bin/phpstan analyse src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php --level=9 --no-progress` exits 0
  </acceptance_criteria>
  <done>Compiler pass file exists, lints clean, PHPStan level-9 clean; all four CONTEXT.md error strings present verbatim.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Unit-test OriginHeaderResolverConfigPass (D-23 cases)</name>
  <files>tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php</files>
  <read_first>
    - src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php (the pass just written)
    - .planning/phases/17-origin-header-resolver/17-CONTEXT.md decision D-23 (six cases enumerated; plus the new "explicit slug required" case from Task 1)
    - .planning/phases/17-origin-header-resolver/17-PATTERNS.md (compiler-pass test skeleton — direct ContainerBuilder construction, no analog test file in repo)
  </read_first>
  <behavior>
    Test methods (each instantiates a fresh `ContainerBuilder`, sets parameters, invokes `$pass->process($container)`, asserts on either exception type+message OR post-state of `tenancy.origin.allow_list` parameter):
    - testNoOpWhenOriginNotInResolvers
    - testNoOpWhenResolversParameterAbsent
    - testThrowsOnEmptyAllowListWhenOriginConfigured
    - testThrowsOnMissingAllowListParameter
    - testThrowsOnUnparseableOriginUrl
    - testThrowsOnSchemeOtherThanHttpHttps
    - testThrowsOnMidStringWildcard
    - testThrowsOnMultiLabelWildcard
    - testThrowsOnPureStarWildcard
    - testThrowsOnPathInOrigin
    - testThrowsOnQueryInOrigin
    - testThrowsOnUserInfoInOrigin
    - testThrowsOnNonWildcardEntryMissingSlug
    - testValidMixedAllowListIsNormalized — asserts the final parameter shape including default port 443 injection, lowercased host, and `wildcard_suffix` value
  </behavior>
  <action>
Create `tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tenancy\Bundle\DependencyInjection\Compiler\OriginHeaderResolverConfigPass;

final class OriginHeaderResolverConfigPassTest extends TestCase
{
    public function testNoOpWhenOriginNotInResolvers(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('tenancy.resolvers', ['host', 'header']);
        // tenancy.origin.allow_list intentionally unset.

        (new OriginHeaderResolverConfigPass())->process($container);

        $this->assertFalse($container->hasParameter('tenancy.origin.allow_list'));
    }

    public function testNoOpWhenResolversParameterAbsent(): void
    {
        $container = new ContainerBuilder();

        (new OriginHeaderResolverConfigPass())->process($container);

        $this->assertFalse($container->hasParameter('tenancy.origin.allow_list'));
    }

    public function testThrowsOnEmptyAllowListWhenOriginConfigured(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('tenancy.resolvers', ['host', 'origin']);
        $container->setParameter('tenancy.origin.allow_list', []);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tenancy.origin.allow_list is empty but "origin" is configured in tenancy.resolvers');

        (new OriginHeaderResolverConfigPass())->process($container);
    }

    public function testThrowsOnMissingAllowListParameter(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('tenancy.resolvers', ['origin']);
        // No tenancy.origin.allow_list parameter.

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tenancy.origin.allow_list is empty');

        (new OriginHeaderResolverConfigPass())->process($container);
    }

    public function testThrowsOnUnparseableOriginUrl(): void
    {
        $container = $this->containerWith([['origin' => 'not a url', 'slug' => 'x']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is unparseable — must be an absolute origin URL');

        (new OriginHeaderResolverConfigPass())->process($container);
    }

    public function testThrowsOnSchemeOtherThanHttpHttps(): void
    {
        $container = $this->containerWith([['origin' => 'ftp://acme.example.com', 'slug' => 'acme']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is unparseable');

        (new OriginHeaderResolverConfigPass())->process($container);
    }

    public function testThrowsOnMidStringWildcard(): void
    {
        $container = $this->containerWith([['origin' => 'https://app.*.example.com', 'slug' => null]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contains a mid-string wildcard — only one leftmost label may be "*"');

        (new OriginHeaderResolverConfigPass())->process($container);
    }

    public function testThrowsOnMultiLabelWildcard(): void
    {
        $container = $this->containerWith([['origin' => 'https://*.*.example.com', 'slug' => null]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contains a mid-string wildcard');

        (new OriginHeaderResolverConfigPass())->process($container);
    }

    public function testThrowsOnPureStarWildcard(): void
    {
        $container = $this->containerWith([['origin' => 'https://*', 'slug' => null]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contains a mid-string wildcard');

        (new OriginHeaderResolverConfigPass())->process($container);
    }

    public function testThrowsOnPathInOrigin(): void
    {
        $container = $this->containerWith([['origin' => 'https://acme.app.example.com/api', 'slug' => 'acme']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contains a path/query — origin URLs must be bare authorities');

        (new OriginHeaderResolverConfigPass())->process($container);
    }

    public function testThrowsOnQueryInOrigin(): void
    {
        $container = $this->containerWith([['origin' => 'https://acme.app.example.com?x=1', 'slug' => 'acme']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contains a path/query');

        (new OriginHeaderResolverConfigPass())->process($container);
    }

    public function testThrowsOnUserInfoInOrigin(): void
    {
        $container = $this->containerWith([['origin' => 'https://user:pass@acme.app.example.com', 'slug' => 'acme']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('contains a path/query');

        (new OriginHeaderResolverConfigPass())->process($container);
    }

    public function testThrowsOnNonWildcardEntryMissingSlug(): void
    {
        $container = $this->containerWith([['origin' => 'https://acme.app.example.com', 'slug' => null]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires an explicit slug');

        (new OriginHeaderResolverConfigPass())->process($container);
    }

    public function testValidMixedAllowListIsNormalized(): void
    {
        $container = $this->containerWith([
            ['origin' => 'https://acme.app.example.com', 'slug' => 'acme'],
            ['origin' => 'http://beta.dev.example.com:8080', 'slug' => 'beta'],
            ['origin' => 'https://*.app.example.com', 'slug' => null],
        ]);

        (new OriginHeaderResolverConfigPass())->process($container);

        /** @var list<array<string, mixed>> $normalized */
        $normalized = $container->getParameter('tenancy.origin.allow_list');

        $this->assertCount(3, $normalized);

        $this->assertSame('https://acme.app.example.com:443', $normalized[0]['origin']);
        $this->assertSame('acme.app.example.com', $normalized[0]['host']);
        $this->assertSame('https', $normalized[0]['scheme']);
        $this->assertSame(443, $normalized[0]['port']);
        $this->assertFalse($normalized[0]['is_wildcard']);
        $this->assertNull($normalized[0]['wildcard_suffix']);
        $this->assertSame('acme', $normalized[0]['slug']);

        $this->assertSame('http://beta.dev.example.com:8080', $normalized[1]['origin']);
        $this->assertSame(8080, $normalized[1]['port']);
        $this->assertSame('http', $normalized[1]['scheme']);
        $this->assertFalse($normalized[1]['is_wildcard']);

        $this->assertTrue($normalized[2]['is_wildcard']);
        $this->assertSame('.app.example.com', $normalized[2]['wildcard_suffix']);
        $this->assertSame(443, $normalized[2]['port']);
        $this->assertNull($normalized[2]['slug']);
    }

    /**
     * @param list<array<string, mixed>> $allowList
     */
    private function containerWith(array $allowList): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('tenancy.resolvers', ['host', 'origin']);
        $container->setParameter('tenancy.origin.allow_list', $allowList);

        return $container;
    }
}
```
  </action>
  <verify>
    <automated>vendor/bin/phpunit --filter OriginHeaderResolverConfigPassTest --no-coverage 2>&amp;1 | tail -10</automated>
  </verify>
  <acceptance_criteria>
    - File `tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php` exists
    - `vendor/bin/phpunit --filter OriginHeaderResolverConfigPassTest --no-coverage` exits 0
    - File contains all 14 test method declarations listed in the behavior section
    - `testValidMixedAllowListIsNormalized` asserts (a) port 443 defaulted for `https`, (b) port 8080 preserved when explicit, (c) `wildcard_suffix` equals `'.app.example.com'`, (d) `slug` null for wildcard entry, (e) normalized origin string `'https://acme.app.example.com:443'`
    - Each `throws` test uses both `expectException(\InvalidArgumentException::class)` and `expectExceptionMessage(...)` asserting on a substring of the verbatim error from CONTEXT.md
  </acceptance_criteria>
  <done>All 14 compiler-pass unit tests pass; the pass is fully behaviorally locked.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| application config (`tenancy.yaml`) → bundle compiler | YAML allow-list values must not silently produce a permissive routing rule |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-17-02 | Elevation of privilege (silent allow-list expansion) | `OriginHeaderResolverConfigPass::normalizeEntry()` mid-string wildcard rejection | mitigate | Reject mid-string `*` at compile time with the exact message `tenancy.origin.allow_list entry "%s" contains a mid-string wildcard — only one leftmost label may be "*"`. Validated by `testThrowsOnMidStringWildcard`, `testThrowsOnMultiLabelWildcard`, `testThrowsOnPureStarWildcard` |
| T-17-03 | Denial of service (config error → empty allow-list silently accepts no origin) | `OriginHeaderResolverConfigPass::process()` empty-list rejection | mitigate | Hard fail with `tenancy.origin.allow_list is empty but "origin" is configured in tenancy.resolvers — ...` whenever `'origin'` is in resolvers but the allow-list parameter is empty/unset. Validated by `testThrowsOnEmptyAllowListWhenOriginConfigured` and `testThrowsOnMissingAllowListParameter` |
</threat_model>

<verification>
- Static: `php -l src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php`
- Type: `vendor/bin/phpstan analyse src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php --level=9 --no-progress`
- Tests: `vendor/bin/phpunit --filter OriginHeaderResolverConfigPassTest --no-coverage`
- Style: `vendor/bin/php-cs-fixer check src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php`
</verification>

<success_criteria>
- `vendor/bin/phpunit --filter OriginHeaderResolverConfigPassTest` exits 0 with 14 tests
- `vendor/bin/phpstan analyse src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php` clean at level 9
- All four CONTEXT.md error-message strings present verbatim in the compiler pass source
- No files outside `files_modified` are touched
</success_criteria>

<output>
After completion, create `.planning/phases/17-origin-header-resolver/17-P02-SUMMARY.md` capturing: tests passing count, the final normalized entry shape produced by the pass (must match Plan 01's constructor expectation), and any deviations.
</output>
