---
id: 17-P01
phase: 17
plan: 01
name: OriginHeaderResolver core class + unit tests
wave: 1
depends_on: []
files_modified:
  - src/Resolver/OriginHeaderResolver.php
  - tests/Unit/Resolver/OriginHeaderResolverTest.php
  - tests/Unit/Resolver/Support/RecordingLogger.php
autonomous: true
requirements: [RESV-06]
threats: [T-17-04, T-17-05, T-17-06]
must_haves:
  truths:
    - "Calling OriginHeaderResolver::resolve() with a Request whose Origin header matches an allow-list entry returns the resolved TenantInterface"
    - "Calling resolve() on a Request with method=OPTIONS returns null without ever reading the Origin header"
    - "Calling resolve() with an unparseable Origin value returns null and does not throw"
    - "When Origin resolves to slug X and X-Tenant-ID header carries slug Y (≠ X, case-insensitive), the logger receives one warning-level record with structured context {origin, origin_slug, header_slug, winner: 'origin'}"
    - "TenantNotFoundException from the provider is swallowed (returns null); TenantInactiveException bubbles"
  artifacts:
    - path: src/Resolver/OriginHeaderResolver.php
      provides: "Final OriginHeaderResolver implementing TenantResolverInterface"
      contains: "final class OriginHeaderResolver implements TenantResolverInterface"
    - path: tests/Unit/Resolver/OriginHeaderResolverTest.php
      provides: "Ten unit-test cases (CONTEXT.md D-22's nine + explicit empty-Origin case per D-08)"
    - path: tests/Unit/Resolver/Support/RecordingLogger.php
      provides: "PSR-3 LoggerInterface fixture recording level/message/context tuples"
  key_links:
    - from: src/Resolver/OriginHeaderResolver.php
      to: Psr\Log\LoggerInterface
      via: "constructor arg #2 with NullLogger default"
      pattern: "private readonly LoggerInterface \\$logger = new NullLogger"
    - from: src/Resolver/OriginHeaderResolver.php
      to: Tenancy\Bundle\Provider\TenantProviderInterface
      via: "constructor arg #1, autowired at compile time via service('tenancy.provider')->nullOnInvalid()"
      pattern: "TenantProviderInterface \\$tenantProvider"
---

<objective>
Ship `OriginHeaderResolver` — the runtime matcher class that reads the `Origin` HTTP header, matches it against a pre-normalized allow-list (passed in via constructor), and resolves to a `TenantInterface` via the existing `TenantProviderInterface`. Plus a full unit test suite (9 cases per D-22) and a small `RecordingLogger` PSR-3 fixture for asserting on the mismatch warning.

Purpose: Establish the resolver class first, in isolation from container wiring (Plan 03) and from the compiler-pass that validates the allow-list (Plan 02). The resolver's constructor takes a raw `array $allowList` of already-normalized entries — that contract is what Plan 02 (compiler pass) and Plan 03 (bundle wiring) build to.

Output: One new resolver class, one new unit test file, one tiny PSR-3 logger fixture. All three files are net-new — no existing files are modified.
</objective>

<execution_context>
@/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/.claude/get-shit-done/workflows/execute-plan.md
@/Users/danplaton/dev/packages/[claude-code] symfony-multitenancy/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/STATE.md
@.planning/phases/17-origin-header-resolver/17-CONTEXT.md
@.planning/phases/17-origin-header-resolver/17-RESEARCH.md
@.planning/phases/17-origin-header-resolver/17-PATTERNS.md
@CLAUDE.md
@src/Resolver/HeaderResolver.php
@src/Resolver/HostResolver.php
@src/Resolver/TenantResolverInterface.php
@src/Provider/TenantProviderInterface.php
@src/Exception/TenantNotFoundException.php
@src/Exception/TenantInactiveException.php
@tests/Unit/Resolver/HeaderResolverTest.php

<interfaces>
<!-- Key types and contracts the executor needs. Extracted from existing codebase. -->

`Tenancy\Bundle\Resolver\TenantResolverInterface` (src/Resolver/TenantResolverInterface.php):
```php
interface TenantResolverInterface
{
    public function resolve(Request $request): ?TenantInterface;
}
```

`Tenancy\Bundle\Provider\TenantProviderInterface` (src/Provider/TenantProviderInterface.php):
```php
interface TenantProviderInterface
{
    /** @throws TenantNotFoundException|TenantInactiveException */
    public function findBySlug(string $slug): TenantInterface;
    /** @return list<TenantInterface> */
    public function findAll(): array;
}
```

`Tenancy\Bundle\TenantInterface` (src/TenantInterface.php) — `getSlug(): string` is what we compare against for the mismatch check.

Normalized allow-list entry shape (locked by CONTEXT.md D-17). The compiler pass (Plan 02) produces these — Plan 01's constructor must accept this exact shape:
```php
// @phpstan-type AllowListEntry array{
//     origin: string,           // e.g. "https://acme.app.example.com:443"
//     host: string,             // e.g. "acme.app.example.com"  (lowercased)
//     scheme: string,           // "http" or "https"
//     port: int,                // 80 or 443 default
//     is_wildcard: bool,        // true if entry was "https://*.app.example.com"
//     wildcard_suffix: ?string, // e.g. ".app.example.com" (leading dot); null when is_wildcard=false
//     slug: ?string,            // explicit slug for non-wildcard entries; null for wildcard entries (slug = matched label at runtime)
// }
```
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Create RecordingLogger PSR-3 fixture</name>
  <files>tests/Unit/Resolver/Support/RecordingLogger.php</files>
  <read_first>
    - tests/Unit/Resolver/HeaderResolverTest.php (to confirm php-cs-fixer @Symfony shape — strict_types, namespace, final class, blank-line conventions)
    - .planning/phases/17-origin-header-resolver/17-PATTERNS.md (section "RecordingLogger" — green-field spec)
    - .planning/phases/17-origin-header-resolver/17-RESEARCH.md (search "RecordingLogger" — matches PATTERNS.md verbatim)
  </read_first>
  <behavior>
    - new RecordingLogger() exposes a public `array $records` initially `[]`
    - calling `$logger->warning('msg', ['k' => 'v'])` appends `['level' => 'warning', 'message' => 'msg', 'context' => ['k' => 'v']]` to `records`
    - calling `$logger->info('msg')` appends `['level' => 'info', 'message' => 'msg', 'context' => []]`
    - `warnings()` method returns only records whose `level === 'warning'`, re-indexed via `array_values()`
  </behavior>
  <action>
Create `tests/Unit/Resolver/Support/RecordingLogger.php` with this exact content:

```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Resolver\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * In-memory PSR-3 logger that records every log() call.
 * Used by OriginHeaderResolverTest to assert the mismatch warning is emitted with the
 * exact structured context shape locked by CONTEXT.md D-11.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string|Stringable, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }

    /** @return list<array{level: mixed, message: string|Stringable, context: array<mixed>}> */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (array $r): bool => 'warning' === $r['level'],
        ));
    }
}
```

Namespace MUST be `Tenancy\Bundle\Tests\Unit\Resolver\Support` (matches PSR-4 autoload-dev mapping `Tenancy\\Bundle\\Tests\\` → `tests/`). The class is `final`; `$records` is intentionally `public` so test code can read it directly (test fixture convention). `extends AbstractLogger` so we inherit `info()`, `warning()`, `error()`, etc. delegating to `log()`. Stringable is imported as a global-namespace alias — PHP 8+ ships `Stringable` in root namespace.
  </action>
  <verify>
    <automated>php -l "tests/Unit/Resolver/Support/RecordingLogger.php" &amp;&amp; grep -q 'final class RecordingLogger extends AbstractLogger' tests/Unit/Resolver/Support/RecordingLogger.php &amp;&amp; grep -q 'namespace Tenancy\\\\Bundle\\\\Tests\\\\Unit\\\\Resolver\\\\Support;' tests/Unit/Resolver/Support/RecordingLogger.php</automated>
  </verify>
  <acceptance_criteria>
    - File `tests/Unit/Resolver/Support/RecordingLogger.php` exists
    - `php -l tests/Unit/Resolver/Support/RecordingLogger.php` exits 0
    - File contains exactly `final class RecordingLogger extends AbstractLogger`
    - File contains exactly `namespace Tenancy\Bundle\Tests\Unit\Resolver\Support;`
    - File contains `public array $records = [];`
    - File contains `public function log($level, string|Stringable $message, array $context = []): void`
    - File contains `public function warnings(): array`
    - `grep -c '^declare(strict_types=1);' tests/Unit/Resolver/Support/RecordingLogger.php` outputs `1`
  </acceptance_criteria>
  <done>RecordingLogger fixture exists, lints clean, and is structurally identical to the spec above.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Create OriginHeaderResolver class</name>
  <files>src/Resolver/OriginHeaderResolver.php</files>
  <read_first>
    - src/Resolver/HeaderResolver.php (shape template — copy structure including the `// TenantInactiveException is NOT caught — bubbles up as HTTP 403` comment verbatim)
    - src/Resolver/HostResolver.php (suffix-strip wildcard pattern; note the algorithm extracts the LAST label of a subdomain, whereas Origin matching extracts the FIRST/LEFTMOST label — invert)
    - src/Resolver/TenantResolverInterface.php (interface contract)
    - src/Provider/TenantProviderInterface.php (constructor dep)
    - src/Exception/TenantNotFoundException.php (caught and swallowed)
    - src/Exception/TenantInactiveException.php (NOT caught — bubbles)
    - .planning/phases/17-origin-header-resolver/17-CONTEXT.md decisions D-07, D-08, D-09, D-10, D-11, D-12, D-17
    - .planning/phases/17-origin-header-resolver/17-PATTERNS.md section "src/Resolver/OriginHeaderResolver.php"
  </read_first>
  <behavior>
    - Constructor signature: `(TenantProviderInterface $tenantProvider, LoggerInterface $logger = new NullLogger(), array $allowList = [])` — all three readonly private. Default empty array allow-list is permitted at the constructor level (Plan 02 compiler pass enforces non-emptiness at compile time).
    - `resolve(Request $request): ?TenantInterface` — public, single method on interface
    - Step 1 (D-07): if `$request->getMethod() === 'OPTIONS'` return null IMMEDIATELY (before reading headers)
    - Step 2 (D-08): read `Origin` header; absent OR empty string → return null
    - Step 3 (D-09): if parsing fails (parse_url returns false, or missing scheme/host) → return null silently (no log)
    - Step 4: walk `$this->allowList` in order:
      - non-wildcard entry: match if normalized incoming `scheme://host:port` string-equals `entry['origin']`. On match, slug = `entry['slug']`.
      - wildcard entry: match if `scheme === entry['scheme']` AND `port === entry['port']` AND host ends with `entry['wildcard_suffix']` (e.g. `.app.example.com`). Slug = leftmost label of host (everything BEFORE the suffix; reject empty leftmost label).
    - Step 5: call `$this->tenantProvider->findBySlug($slug)`. Catch `TenantNotFoundException` → return null. Do NOT catch `TenantInactiveException`.
    - Step 6 (D-11): after a successful tenant resolution, peek `X-Tenant-ID` header. If present, non-empty, and `0 !== strcasecmp($headerSlug, $tenant->getSlug())` → call `$this->logger->warning(...)` with the structured context shape locked in D-11.
    - Return the resolved `TenantInterface`.
  </behavior>
  <action>
Create `src/Resolver/OriginHeaderResolver.php` with the following content. The class MUST be `final`, properties MUST be `private readonly`, and MUST mirror `HeaderResolver` shape (including the trailing exception-swallow comment verbatim).

```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Resolver;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;

/**
 * Resolves the active tenant from the browser-set `Origin` HTTP header.
 *
 * Registered at resolver-chain priority 25 (above HeaderResolver=20, below HostResolver=30):
 * Origin is browser-locked for cross-origin XHR/fetch so it is a stronger tenant signal than
 * X-Tenant-ID, but explicit subdomain routing (HostResolver) still wins.
 *
 * Trust model: `Origin` is set by the browser and cannot be forged from JS on a non-CORS
 * request, but it IS trivially settable from curl / Postman / native mobile / server-to-server.
 * This resolver is a routing hint, not an authentication factor — pair it with a real auth layer.
 * See `docs/user-guide/origin-header-resolver.md` § Trust Model.
 */
final class OriginHeaderResolver implements TenantResolverInterface
{
    public const HEADER_NAME = 'Origin';
    public const MISMATCH_HEADER_NAME = 'X-Tenant-ID';

    /**
     * @param list<array{
     *     origin: string,
     *     host: string,
     *     scheme: string,
     *     port: int,
     *     is_wildcard: bool,
     *     wildcard_suffix: ?string,
     *     slug: ?string,
     * }> $allowList Normalized at compile time by OriginHeaderResolverConfigPass
     */
    public function __construct(
        private readonly TenantProviderInterface $tenantProvider,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly array $allowList = [],
    ) {
    }

    public function resolve(Request $request): ?TenantInterface
    {
        // D-07: CORS preflight short-circuit BEFORE Origin parsing — preflight must not throw.
        if ('OPTIONS' === $request->getMethod()) {
            return null;
        }

        // D-08: absent or empty Origin header → fall through resolver chain.
        $origin = $request->headers->get(self::HEADER_NAME);
        if (null === $origin || '' === $origin) {
            return null;
        }

        // D-09: unparseable Origin → silently null (no log spam from misconfigured clients).
        $slug = $this->matchOrigin($origin);
        if (null === $slug) {
            return null;
        }

        try {
            $tenant = $this->tenantProvider->findBySlug($slug);
        } catch (TenantNotFoundException) {
            return null;
        }
        // TenantInactiveException is NOT caught — bubbles up as HTTP 403

        // D-11: peek X-Tenant-ID; warn on mismatch (Origin wins, no extra DB roundtrip).
        $headerSlug = $request->headers->get(self::MISMATCH_HEADER_NAME);
        if (null !== $headerSlug && '' !== $headerSlug
            && 0 !== strcasecmp($headerSlug, $tenant->getSlug())) {
            $this->logger->warning('Origin/X-Tenant-ID mismatch — Origin wins', [
                'origin' => $origin,
                'origin_slug' => $tenant->getSlug(),
                'header_slug' => $headerSlug,
                'winner' => 'origin',
            ]);
        }

        return $tenant;
    }

    private function matchOrigin(string $origin): ?string
    {
        $parts = parse_url($origin);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ('http' !== $scheme && 'https' !== $scheme) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        if ('' === $host) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ('https' === $scheme ? 443 : 80);
        $normalized = sprintf('%s://%s:%d', $scheme, $host, $port);

        foreach ($this->allowList as $entry) {
            if ($entry['is_wildcard']) {
                if ($scheme !== $entry['scheme'] || $port !== $entry['port']) {
                    continue;
                }
                $suffix = $entry['wildcard_suffix'];
                if (null === $suffix || !str_ends_with($host, $suffix)) {
                    continue;
                }
                $label = substr($host, 0, -strlen($suffix));
                if ('' === $label || str_contains($label, '.')) {
                    continue; // wildcard accepts exactly one leftmost label
                }

                return $label;
            }

            if ($normalized === $entry['origin']) {
                return $entry['slug'];
            }
        }

        return null;
    }
}
```

Notes for the executor:
- `parse_url()` may return `false` (rare) or an array missing `scheme`/`host` for garbage input — guard with `!isset($parts['scheme'], $parts['host'])`.
- Wildcard slug rule (D-04): exactly ONE leftmost label. If the remainder after stripping the suffix contains a `.`, it's a multi-label substitution and must be rejected at runtime as a defensive check (the compiler pass already rejects multi-label wildcard ENTRIES, but a stray multi-level subdomain in an incoming request shouldn't be misread as a 1-label match).
- The mismatch comparison uses `strcasecmp` per D-11 (case-insensitive slug compare).
- Do NOT use a logger-conditional check like `if ($this->logger)` — the null-safe collaborator is `NullLogger`, which silently absorbs calls.
  </action>
  <verify>
    <automated>php -l "src/Resolver/OriginHeaderResolver.php" &amp;&amp; vendor/bin/phpstan analyse src/Resolver/OriginHeaderResolver.php --level=9 --no-progress 2>&amp;1 | tail -5</automated>
  </verify>
  <acceptance_criteria>
    - File `src/Resolver/OriginHeaderResolver.php` exists
    - `php -l src/Resolver/OriginHeaderResolver.php` exits 0
    - File contains exactly `final class OriginHeaderResolver implements TenantResolverInterface`
    - File contains the comment `// TenantInactiveException is NOT caught — bubbles up as HTTP 403`
    - File contains `public const HEADER_NAME = 'Origin';`
    - File contains `public const MISMATCH_HEADER_NAME = 'X-Tenant-ID';`
    - File contains `private readonly TenantProviderInterface $tenantProvider`
    - File contains `private readonly LoggerInterface $logger = new NullLogger()`
    - File contains `private readonly array $allowList = []`
    - File contains the literal string `'Origin/X-Tenant-ID mismatch — Origin wins'`
    - File contains all four context keys for the mismatch warning: `'origin'`, `'origin_slug'`, `'header_slug'`, `'winner' => 'origin'`
    - File contains `if ('OPTIONS' === $request->getMethod())` (preflight short-circuit)
    - `vendor/bin/phpstan analyse src/Resolver/OriginHeaderResolver.php --level=9 --no-progress` exits 0
    - `grep -c '^declare(strict_types=1);' src/Resolver/OriginHeaderResolver.php` outputs `1`
  </acceptance_criteria>
  <done>OriginHeaderResolver class exists, lints clean, passes PHPStan level 9, and mirrors HeaderResolver shape with the locked extensions from CONTEXT.md.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: Unit-test OriginHeaderResolver (9 cases per D-22)</name>
  <files>tests/Unit/Resolver/OriginHeaderResolverTest.php</files>
  <read_first>
    - tests/Unit/Resolver/HeaderResolverTest.php (test shell template — final class, MockObject, setUp shape)
    - src/Resolver/OriginHeaderResolver.php (just-written class — to make sure tests align with implementation)
    - tests/Unit/Resolver/Support/RecordingLogger.php (just-written fixture — used in mismatch-warning test)
    - .planning/phases/17-origin-header-resolver/17-CONTEXT.md decision D-22 (nine test cases enumerated)
    - .planning/phases/17-origin-header-resolver/17-PATTERNS.md section "tests/Unit/Resolver/OriginHeaderResolverTest.php"
  </read_first>
  <behavior>
    Ten test methods (D-22's nine plus the explicit empty-Origin case per D-08), each asserts exactly one slice of behavior:
    - testReturnsNullWhenOriginHeaderAbsent
    - testReturnsNullWhenOriginHeaderEmpty
    - testReturnsNullOnOptionsPreflightEvenWhenOriginPresent
    - testExactAllowListEntryResolvesTenant
    - testWildcardAllowListEntryResolvesViaLeftmostLabel
    - testNonMatchingOriginReturnsNull
    - testReturnsNullOnUnparseableOrigin
    - testReturnsNullWhenProviderThrowsNotFound
    - testBubblesInactiveException
    - testMismatchWithXTenantIdLogsWarningAtWarningLevelWithStructuredContext
  </behavior>
  <action>
Create `tests/Unit/Resolver/OriginHeaderResolverTest.php` with the test cases below. Use `RecordingLogger` (from Task 1) for the mismatch-warning test, and a PHPUnit MockObject of `TenantProviderInterface` for the rest.

The allow-list fixture used across tests should be a single helper method returning the normalized shape — exactly the structure CONTEXT.md D-17 locks. Hardcode two entries:
1. Explicit non-wildcard entry: `https://acme.app.example.com:443` → slug `acme`
2. Wildcard entry: `https://*.app.example.com` → suffix `.app.example.com`, port 443

```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Resolver;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\Exception\TenantInactiveException;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\Resolver\OriginHeaderResolver;
use Tenancy\Bundle\TenantInterface;
use Tenancy\Bundle\Tests\Unit\Resolver\Support\RecordingLogger;

final class OriginHeaderResolverTest extends TestCase
{
    private TenantProviderInterface&MockObject $provider;
    private RecordingLogger $logger;
    private OriginHeaderResolver $resolver;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(TenantProviderInterface::class);
        $this->logger = new RecordingLogger();
        $this->resolver = new OriginHeaderResolver(
            $this->provider,
            $this->logger,
            $this->allowList(),
        );
    }

    public function testReturnsNullWhenOriginHeaderAbsent(): void
    {
        $this->provider->expects($this->never())->method('findBySlug');

        $request = Request::create('/');
        $this->assertNull($this->resolver->resolve($request));
    }

    public function testReturnsNullWhenOriginHeaderEmpty(): void
    {
        $this->provider->expects($this->never())->method('findBySlug');

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ORIGIN' => '']);
        $this->assertNull($this->resolver->resolve($request));
    }

    public function testReturnsNullOnOptionsPreflightEvenWhenOriginPresent(): void
    {
        $this->provider->expects($this->never())->method('findBySlug');

        $request = Request::create('/', 'OPTIONS', [], [], [], ['HTTP_ORIGIN' => 'https://acme.app.example.com']);
        $this->assertNull($this->resolver->resolve($request));
    }

    public function testExactAllowListEntryResolvesTenant(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');

        $this->provider->expects($this->once())
            ->method('findBySlug')
            ->with('acme')
            ->willReturn($tenant);

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ORIGIN' => 'https://acme.app.example.com']);
        $this->assertSame($tenant, $this->resolver->resolve($request));
    }

    public function testWildcardAllowListEntryResolvesViaLeftmostLabel(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('beta');

        $this->provider->expects($this->once())
            ->method('findBySlug')
            ->with('beta')
            ->willReturn($tenant);

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ORIGIN' => 'https://beta.app.example.com']);
        $this->assertSame($tenant, $this->resolver->resolve($request));
    }

    public function testNonMatchingOriginReturnsNull(): void
    {
        $this->provider->expects($this->never())->method('findBySlug');

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ORIGIN' => 'https://evil.example.org']);
        $this->assertNull($this->resolver->resolve($request));
    }

    public function testReturnsNullOnUnparseableOrigin(): void
    {
        $this->provider->expects($this->never())->method('findBySlug');

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ORIGIN' => 'not a url']);
        $this->assertNull($this->resolver->resolve($request));
    }

    public function testReturnsNullWhenProviderThrowsNotFound(): void
    {
        $this->provider->expects($this->once())
            ->method('findBySlug')
            ->with('acme')
            ->willThrowException(new TenantNotFoundException('Tenant "acme" not found.'));

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ORIGIN' => 'https://acme.app.example.com']);
        $this->assertNull($this->resolver->resolve($request));
    }

    public function testBubblesInactiveException(): void
    {
        $this->provider->expects($this->once())
            ->method('findBySlug')
            ->with('acme')
            ->willThrowException(new TenantInactiveException('acme'));

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ORIGIN' => 'https://acme.app.example.com']);

        $this->expectException(TenantInactiveException::class);
        $this->resolver->resolve($request);
    }

    public function testMismatchWithXTenantIdLogsWarningAtWarningLevelWithStructuredContext(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');

        $this->provider->expects($this->once())
            ->method('findBySlug')
            ->with('acme')
            ->willReturn($tenant);

        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_ORIGIN' => 'https://acme.app.example.com',
            'HTTP_X_TENANT_ID' => 'beta',
        ]);

        $this->assertSame($tenant, $this->resolver->resolve($request));

        $warnings = $this->logger->warnings();
        $this->assertCount(1, $warnings);
        $this->assertSame('warning', $warnings[0]['level']);
        $this->assertSame([
            'origin' => 'https://acme.app.example.com',
            'origin_slug' => 'acme',
            'header_slug' => 'beta',
            'winner' => 'origin',
        ], $warnings[0]['context']);
    }

    /**
     * @return list<array{
     *     origin: string, host: string, scheme: string, port: int,
     *     is_wildcard: bool, wildcard_suffix: ?string, slug: ?string
     * }>
     */
    private function allowList(): array
    {
        return [
            [
                'origin' => 'https://acme.app.example.com:443',
                'host' => 'acme.app.example.com',
                'scheme' => 'https',
                'port' => 443,
                'is_wildcard' => false,
                'wildcard_suffix' => null,
                'slug' => 'acme',
            ],
            [
                'origin' => 'https://*.app.example.com:443',
                'host' => '*.app.example.com',
                'scheme' => 'https',
                'port' => 443,
                'is_wildcard' => true,
                'wildcard_suffix' => '.app.example.com',
                'slug' => null,
            ],
        ];
    }
}
```

Note: the test class lives at `tests/Unit/Resolver/OriginHeaderResolverTest.php` (D-22 path). The exact-match entry's expected matched origin string in `RecordingLogger` context is `'https://acme.app.example.com'` (the incoming header value, NOT the normalized stored form) — verify in the implementation that the resolver passes `$origin` as captured from the header into the log payload.
  </action>
  <verify>
    <automated>vendor/bin/phpunit --filter OriginHeaderResolverTest --no-coverage 2>&amp;1 | tail -10</automated>
  </verify>
  <acceptance_criteria>
    - File `tests/Unit/Resolver/OriginHeaderResolverTest.php` exists
    - `vendor/bin/phpunit --filter OriginHeaderResolverTest --no-coverage` exits 0
    - File contains all 10 test method declarations: `testReturnsNullWhenOriginHeaderAbsent`, `testReturnsNullWhenOriginHeaderEmpty`, `testReturnsNullOnOptionsPreflightEvenWhenOriginPresent`, `testExactAllowListEntryResolvesTenant`, `testWildcardAllowListEntryResolvesViaLeftmostLabel`, `testNonMatchingOriginReturnsNull`, `testReturnsNullOnUnparseableOrigin`, `testReturnsNullWhenProviderThrowsNotFound`, `testBubblesInactiveException`, `testMismatchWithXTenantIdLogsWarningAtWarningLevelWithStructuredContext`
    - Test count from phpunit output is exactly 10
    - File imports `Tenancy\Bundle\Tests\Unit\Resolver\Support\RecordingLogger`
    - File asserts on the structured context shape `{origin, origin_slug, header_slug, winner: 'origin'}` literally
  </acceptance_criteria>
  <done>All 10 unit tests pass under PHPUnit; failures here block all downstream plans.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| browser → bundle resolver | Untrusted `Origin` header crosses this boundary on every cross-origin XHR/fetch |
| non-browser client → bundle resolver | curl / Postman / mobile clients can freely set `Origin` — Origin alone is NOT authentication |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-17-04 | Information disclosure (Spoofing+I) | `OriginHeaderResolver::resolve()` mismatch detection | mitigate | Peek `X-Tenant-ID`; if textually different (case-insensitive), emit `warning`-level PSR-3 record with structured context {origin, origin_slug, header_slug, winner: 'origin'} so SIEM/forensics can detect routing-confusion attempts |
| T-17-05 | Denial of service | CORS preflight (`OPTIONS`) hitting the resolver | mitigate | Method check `'OPTIONS' === $request->getMethod()` returns null BEFORE Origin parsing — preflight is cheap and side-effect-free |
| T-17-06 | Tampering / malformed input | Garbage Origin header (`Origin: null`, `Origin: ` plus junk, missing scheme) | mitigate | `parse_url()` returns false / missing scheme+host → resolver returns null silently (no log spam, no crash, chain falls through to other resolvers) |
</threat_model>

<verification>
- Static: `php -l` on each new file
- Type: `vendor/bin/phpstan analyse src/Resolver/OriginHeaderResolver.php --level=9 --no-progress`
- Tests: `vendor/bin/phpunit --filter OriginHeaderResolverTest --no-coverage`
- Style: `vendor/bin/php-cs-fixer check src/Resolver/OriginHeaderResolver.php tests/Unit/Resolver/OriginHeaderResolverTest.php tests/Unit/Resolver/Support/RecordingLogger.php`
</verification>

<success_criteria>
- `vendor/bin/phpunit --filter OriginHeaderResolverTest` exits 0 with 10 tests, 10 assertions or more
- `vendor/bin/phpstan analyse src/Resolver/OriginHeaderResolver.php` is clean at level 9
- All three new files lint clean (`php -l`)
- No files outside the three listed in `files_modified` are touched
</success_criteria>

<output>
After completion, create `.planning/phases/17-origin-header-resolver/17-P01-SUMMARY.md` capturing: tests passing count, any deviations from the spec, and the exact normalized-entry shape the constructor accepts (so Plan 02's compiler pass can target it byte-for-byte).
</output>
