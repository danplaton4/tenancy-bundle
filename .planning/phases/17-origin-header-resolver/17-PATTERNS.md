# Phase 17: OriginHeaderResolver — Pattern Map

**Mapped:** 2026-05-15
**Files analyzed:** 9 (5 new, 4 edit) + 2 new test-support fixtures
**Analogs found:** 9 / 9 (full coverage — phase mirrors locked patterns)

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `src/Resolver/OriginHeaderResolver.php` | production resolver | request-response | `src/Resolver/HeaderResolver.php` + `src/Resolver/HostResolver.php` | exact (shape) + exact (wildcard slug extraction) |
| `src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php` | compiler pass (compile-time guard) | container-build / parameter-validate | `src/DependencyInjection/Compiler/CacheDecoratorContractPass.php` | exact |
| `src/DependencyInjection/Compiler/ResolverChainPass.php` (edit) | compiler pass (single-line edit) | container-build | self — single map entry added to `BUILT_IN_RESOLVER_MAP` | identity |
| `src/TenancyBundle.php::configure()` (edit) | configuration node | config-tree definition | existing `host:` array node (lines 52–57) | exact |
| `src/TenancyBundle.php::loadExtension()` (edit) | bundle wiring | container-build / conditional service registration | existing `if ($databaseConfig['enabled']) { ... }` branch (lines 102–148) and `if (driver === 'shared_db')` branch (lines 150–160) | exact (conditional branching pattern) |
| `src/TenancyBundle.php::build()` (edit) | compiler-pass registration | container-build | existing `$container->addCompilerPass(new CacheDecoratorContractPass())` (line 168) | exact |
| `tests/Unit/Resolver/OriginHeaderResolverTest.php` | unit test | test fixture-driven | `tests/Unit/Resolver/HeaderResolverTest.php` | exact |
| `tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php` | unit test (compiler pass) | container-build | No direct analog in repo (CacheDecoratorContractPass has only integration coverage via `ContainerCompilationTest`) — synthesize from `ContainerBuilder` usage pattern | partial (role-match) |
| `tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php` | integration test | kernel-boot + request dispatch | `tests/Integration/TenantResolutionIntegrationTest.php` (inline kernels) | exact |
| `tests/Integration/Resolver/Support/StubTenant.php` + `StubTenantProvider.php` | test fixtures | in-memory map | `tests/Integration/Messenger/Support/StubTenant.php` + `StubTenantProvider.php` | exact (copy verbatim or namespace-aliased) |
| `tests/Integration/Resolver/Support/RecordingLogger.php` | test fixture (PSR-3 logger) | record-and-assert | No analog (first logger fixture in bundle) — derive from `Psr\Log\AbstractLogger` | none — green-field |
| `docs/user-guide/origin-header-resolver.md` | docs | docs | (planner: locate any existing `docs/user-guide/*.md` page for tone/structure) | unknown |
| `CHANGELOG.md` (edit) | docs | docs | existing `## [Unreleased]` / `## [v0.2.x]` entries | exact |

## Pattern Assignments

### `src/Resolver/OriginHeaderResolver.php` (production resolver, request-response)

**Primary analog:** `src/Resolver/HeaderResolver.php` (shape, final class, exception swallow)
**Secondary analog:** `src/Resolver/HostResolver.php` (slug extraction via suffix-strip)

**Class shell + imports** (from `src/Resolver/HeaderResolver.php` lines 1–14):
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;

final class HeaderResolver implements TenantResolverInterface
{
    public const HEADER_NAME = 'X-Tenant-ID';
```

**Constructor pattern — `final` + `private readonly`** (lines 16–19 of `HeaderResolver`):
```php
public function __construct(
    private readonly TenantProviderInterface $tenantProvider,
) {
}
```

For Phase 17, extend to 3 args per CONTEXT.md D-12/D-16:
```php
public function __construct(
    private readonly TenantProviderInterface $tenantProvider,
    private readonly LoggerInterface $logger = new NullLogger(),
    private readonly array $allowList = [],
) {
}
```

**Core resolve() pattern — exception swallow** (`HeaderResolver` lines 21–35):
```php
public function resolve(Request $request): ?TenantInterface
{
    $slug = $request->headers->get(self::HEADER_NAME);

    if (null === $slug || '' === $slug) {
        return null;
    }

    try {
        return $this->tenantProvider->findBySlug($slug);
    } catch (TenantNotFoundException) {
        return null;
    }
    // TenantInactiveException is NOT caught — bubbles up as HTTP 403
}
```

Copy the comment verbatim (CONTEXT.md D-10).

**Wildcard suffix-strip pattern** (from `src/Resolver/HostResolver.php::extractSlug()` lines 39–67):
```php
private function extractSlug(string $host, string $appDomain): ?string
{
    $host = strtolower($host);

    // Strip www. prefix
    if (str_starts_with($host, 'www.')) {
        $host = substr($host, 4);
    }

    $suffix = '.'.strtolower($appDomain);

    // Host must end with .app_domain
    if (!str_ends_with($host, $suffix)) {
        return null;
    }

    // Strip app_domain suffix to get subdomain prefix
    $subdomain = substr($host, 0, -strlen($suffix));

    if ('' === $subdomain) {
        return null; // Host is exactly app_domain, no subdomain
    }

    // For multi-segment subdomains (api.acme), take the last segment as the slug
    $parts = explode('.', $subdomain);
    $slug = end($parts);

    return '' !== $slug ? $slug : null;
}
```

For OriginHeaderResolver, adapt to the pre-normalized wildcard entry: `wildcard_suffix` already includes the leading `.` (e.g. `.app.example.com`), and the leftmost label is the slug. Use `str_ends_with($host, $entry['wildcard_suffix'])` + `substr` + `explode('.')` + `array_shift` (leftmost, not last — wildcard is at the LEFT in Origin matching, vs RIGHT-most subdomain in Host matching). The mechanism is identical; only the side of the slug label flips.

**Header read + preflight gate** (composed, per CONTEXT.md D-07/D-08):
```php
// D-07: preflight short-circuit BEFORE Origin parsing
if ('OPTIONS' === $request->getMethod()) {
    return null;
}

// D-08: absent/empty Origin → null
$origin = $request->headers->get('Origin');
if (null === $origin || '' === $origin) {
    return null;
}
```

**Mismatch warning — structured PSR-3 context** (CONTEXT.md D-11):
```php
$headerSlug = $request->headers->get('X-Tenant-ID');
if (null !== $headerSlug && '' !== $headerSlug
    && 0 !== strcasecmp($headerSlug, $tenant->getSlug())) {
    $this->logger->warning('Origin/X-Tenant-ID mismatch — Origin wins', [
        'origin' => $origin,
        'origin_slug' => $tenant->getSlug(),
        'header_slug' => $headerSlug,
        'winner' => 'origin',
    ]);
}
```

---

### `src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php` (compiler pass, container-build)

**Analog:** `src/DependencyInjection/Compiler/CacheDecoratorContractPass.php`

**Class shell + namespace** (lines 1–10):
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
```

**Final class + class-doc style** (lines 24–25 — multi-line doc explaining the invariant guarded and why a compile-time guard is preferred):
```php
/**
 * Asserts at container compile time that ...
 * Without this pass, ... silently compiles; ...
 */
final class CacheDecoratorContractPass implements CompilerPassInterface
```

**Short-circuit pattern** (lines 38–44):
```php
public function process(ContainerBuilder $container): void
{
    foreach (self::DECORATORS as $decoratorId => $decoratedId) {
        if (!$container->hasDefinition($decoratorId)) {
            continue;
        }
        if (!$container->hasDefinition($decoratedId)) {
            continue;
        }
        // ...
    }
}
```

For Phase 17 (per CONTEXT.md D-15), the equivalent short-circuit checks are:
```php
public function process(ContainerBuilder $container): void
{
    if (!$container->hasParameter('tenancy.resolvers')) {
        return;
    }
    /** @var list<string> $resolvers */
    $resolvers = $container->getParameter('tenancy.resolvers');
    if (!in_array('origin', $resolvers, true)) {
        return; // origin resolver not opted in; nothing to validate
    }
    if (!$container->hasParameter('tenancy.origin.allow_list')) {
        // origin in resolvers but no allow-list parameter — fail hard
        throw new \InvalidArgumentException('tenancy.origin.allow_list is empty but "origin" is configured in tenancy.resolvers — ...');
    }
    // ...validate each entry...
}
```

**Throw pattern — descriptive message with `sprintf`** (line 63):
```php
throw new \LogicException(sprintf('Cache decorator "%s" must implement every Symfony interface exposed by "%s". Missing: %s', $decoratorClass, $decoratedClass, implode(', ', $missing)));
```

Reuse this `sprintf` + entry-quoting style for all four error messages CONTEXT.md lines 227–231 lists verbatim:
- `tenancy.origin.allow_list entry "[entry]" is unparseable — must be an absolute origin URL (scheme://host[:port])`
- `tenancy.origin.allow_list entry "[entry]" contains a mid-string wildcard — only one leftmost label may be "*"`
- `tenancy.origin.allow_list entry "[entry]" contains a path/query — origin URLs must be bare authorities`
- `tenancy.origin.allow_list is empty but "origin" is configured in tenancy.resolvers — either remove "origin" from resolvers or add at least one allow-list entry`

**Constant for static config map** (lines 28–34):
```php
/**
 * Decorator service ID => decorated service ID.
 *
 * @var array<string, string>
 */
private const DECORATORS = [
    'tenancy.cache_adapter' => 'cache.app',
    'tenancy.cache_adapter.taggable' => 'cache.app.taggable',
];
```

For Phase 17 the equivalent is not a const but the runtime-loaded parameter `tenancy.origin.allow_list` — but the **doc-block style** for class-level constants (purpose + `@var` shape) carries.

---

### `src/DependencyInjection/Compiler/ResolverChainPass.php` (single-line edit)

**Analog:** self — `BUILT_IN_RESOLVER_MAP` lines 20–25:
```php
private const BUILT_IN_RESOLVER_MAP = [
    'host' => HostResolver::class,
    'header' => HeaderResolver::class,
    'query_param' => QueryParamResolver::class,
    'console' => ConsoleResolver::class,
];
```

**Edit:** add one line `'origin' => OriginHeaderResolver::class,` (CONTEXT.md D-13). Add matching `use Tenancy\Bundle\Resolver\OriginHeaderResolver;` at the top, sorted alphabetically among the existing `Resolver\*` imports (lines 10–14).

No other change to `ResolverChainPass`. The `findAndSortTaggedServices('tenancy.resolver', ...)` flow already handles priority 25 transparently.

---

### `src/TenancyBundle.php::configure()` (edit — add `origin:` node)

**Analog:** existing `host:` node (lines 52–57):
```php
->arrayNode('host')
->addDefaultsIfNotSet()
->children()
->scalarNode('app_domain')->defaultNull()->end()
->end()
->end()
```

**For `origin:` node** (CONTEXT.md D-18, D-19) — mirrors shape but with array-prototype child + `beforeNormalization` on `allow_list`:
```php
->arrayNode('origin')
->addDefaultsIfNotSet()
->children()
->arrayNode('allow_list')
->beforeNormalization()
    ->always(function (mixed $v): array {
        if (!is_array($v)) {
            return [];
        }
        return array_map(static fn (mixed $entry): array =>
            is_string($entry) ? ['origin' => $entry, 'slug' => null] : $entry
        , $v);
    })
->end()
->arrayPrototype()
->children()
->scalarNode('origin')->isRequired()->end()
->scalarNode('slug')->defaultNull()->end()
->end()
->end()
->end()
->end()
->end()
```

Insertion point: sibling of `host:` (just before the closing `->end()` on line 58, before the top-level `->validate()` block on line 59).

---

### `src/TenancyBundle.php::loadExtension()` (edit — conditional service registration)

**Analog:** existing `if (($config['driver'] ?? '') === 'shared_db') { ... }` branch (lines 150–160):
```php
if (($config['driver'] ?? 'database_per_tenant') === 'shared_db') {
    $services = $container->services();

    $services->set('tenancy.shared_driver', SharedDriver::class)
        ->args([
            service('doctrine.orm.default_entity_manager'),
            service('tenancy.context'),
            '%tenancy.strict_mode%',
        ])
        ->tag('tenancy.bootstrapper');
}
```

**Service definition style** — from `config/services.php` line 54–63 (HostResolver / HeaderResolver wiring):
```php
$services->set(HostResolver::class)
    ->args([
        service('tenancy.provider')->nullOnInvalid(),
        param('tenancy.host.app_domain'),
    ])
    ->tag('tenancy.resolver', ['priority' => 30]);

$services->set(HeaderResolver::class)
    ->args([service('tenancy.provider')->nullOnInvalid()])
    ->tag('tenancy.resolver', ['priority' => 20]);
```

**For Phase 17** (CONTEXT.md D-16), compose:
```php
if (in_array('origin', $config['resolvers'], true)) {
    // Pre-parse allow_list into normalized struct (CONTEXT.md D-17)
    $normalized = self::normalizeOriginAllowList($config['origin']['allow_list'] ?? []);
    $builder->setParameter('tenancy.origin.allow_list', $normalized);

    $services = $container->services();
    $services->set('tenancy.resolver.origin', OriginHeaderResolver::class)
        ->args([
            service('tenancy.provider')->nullOnInvalid(),
            service('logger')->nullOnInvalid(),
            '%tenancy.origin.allow_list%',
        ])
        ->tag('tenancy.resolver', ['priority' => 25]);
}
```

Use `service('logger')->nullOnInvalid()` exactly — same syntax as `service('tenancy.provider')->nullOnInvalid()` used everywhere else.

---

### `src/TenancyBundle.php::build()` (edit — register compiler pass)

**Analog:** existing `build()` body lines 163–172:
```php
public function build(ContainerBuilder $container): void
{
    parent::build($container);
    $container->addCompilerPass(new BootstrapperChainPass());
    $container->addCompilerPass(new ResolverChainPass());
    $container->addCompilerPass(new CacheDecoratorContractPass());
    if (interface_exists(MessageBusInterface::class)) {
        $container->addCompilerPass(new MessengerMiddlewarePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1);
    }
}
```

**Edit:** append `$container->addCompilerPass(new OriginHeaderResolverConfigPass());` directly after the `CacheDecoratorContractPass` line. Order: must be AFTER `ResolverChainPass` since it inspects `tenancy.resolvers` parameter, AND AFTER `loadExtension()` has set `tenancy.origin.allow_list` (compiler passes run after extensions, so this is automatic).

Add matching `use Tenancy\Bundle\DependencyInjection\Compiler\OriginHeaderResolverConfigPass;` import, sorted alphabetically among the existing compiler-pass imports (lines 22–25).

---

### `tests/Unit/Resolver/OriginHeaderResolverTest.php` (unit test)

**Analog:** `tests/Unit/Resolver/HeaderResolverTest.php`

**Test class shell + setUp** (lines 1–25):
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
use Tenancy\Bundle\Resolver\HeaderResolver;
use Tenancy\Bundle\TenantInterface;

final class HeaderResolverTest extends TestCase
{
    private TenantProviderInterface&MockObject $provider;
    private HeaderResolver $resolver;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(TenantProviderInterface::class);
        $this->resolver = new HeaderResolver($this->provider);
    }
```

**Header-driven Request fixtures** — `Request::create('/', 'GET', [], [], [], ['HTTP_X_TENANT_ID' => '...'])` (lines 41, 56, 69, 82). For Origin, use `HTTP_ORIGIN`:
```php
$request = Request::create('/', 'GET', [], [], [], ['HTTP_ORIGIN' => 'https://acme.app.example.com']);
```

For preflight tests, use `Request::create('/', 'OPTIONS', ...)`.

**Three canonical test patterns** to mirror — copy each pattern, swap header + add Origin-specific cases per CONTEXT.md D-22:

1. **Absent / empty header → null + provider never called** (lines 27–45):
```php
public function testReturnsNullWhenHeaderAbsent(): void
{
    $this->provider->expects($this->never())->method('findBySlug');
    $request = Request::create('/');
    $result = $this->resolver->resolve($request);
    $this->assertNull($result);
}
```

2. **TenantNotFoundException swallow** (lines 62–73):
```php
public function testReturnsNullWhenProviderThrowsNotFound(): void
{
    $this->provider->expects($this->once())
        ->method('findBySlug')
        ->with('unknown')
        ->willThrowException(new TenantNotFoundException('Tenant "unknown" not found.'));

    $request = Request::create('/', 'GET', [], [], [], ['HTTP_X_TENANT_ID' => 'unknown']);
    $result = $this->resolver->resolve($request);

    $this->assertNull($result);
}
```

3. **TenantInactiveException bubbles** (lines 75–86):
```php
public function testBubblesInactiveException(): void
{
    $this->provider->expects($this->once())
        ->method('findBySlug')
        ->with('acme')
        ->willThrowException(new TenantInactiveException('acme'));

    $request = Request::create('/', 'GET', [], [], [], ['HTTP_X_TENANT_ID' => 'acme']);

    $this->expectException(TenantInactiveException::class);
    $this->resolver->resolve($request);
}
```

**Additional Origin-specific tests required** (CONTEXT.md D-22, no analog — green-field):
- `testReturnsNullOnOptionsPreflightRegardlessOfHeader`
- `testReturnsNullOnUnparseableOrigin`
- `testExactMatchHit` (allow-list with `{origin, slug}` map → resolved tenant)
- `testWildcardMatchExtractsLeftmostLabel`
- `testNonMatchingOriginReturnsNull`
- `testMismatchWithXTenantIdLogsWarning` — inject a `RecordingLogger` (or PHPUnit `MockObject` of `LoggerInterface`) and assert one `warning`-level record with the exact context shape `['origin', 'origin_slug', 'header_slug', 'winner' => 'origin']`.

---

### `tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php` (unit test)

**Analog:** no direct compiler-pass unit test in repo. Build from `ContainerBuilder` direct-construction. Reference pattern: each test instantiates `new ContainerBuilder()`, sets parameters, invokes `$pass->process($container)`, asserts exception or post-state.

**Skeleton derived from CONTEXT.md D-23:**
```php
public function testThrowsOnEmptyAllowListWhenOriginConfigured(): void
{
    $container = new ContainerBuilder();
    $container->setParameter('tenancy.resolvers', ['host', 'origin']);
    // Do NOT set tenancy.origin.allow_list

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('tenancy.origin.allow_list is empty');

    (new OriginHeaderResolverConfigPass())->process($container);
}

public function testNoOpWhenOriginNotInResolvers(): void
{
    $container = new ContainerBuilder();
    $container->setParameter('tenancy.resolvers', ['host', 'header']);
    // No allow_list parameter — should not throw

    (new OriginHeaderResolverConfigPass())->process($container);

    $this->assertTrue(true); // pass returns silently
}
```

Cover all six cases from D-23: empty list, unparseable URL, mid-string wildcard, multi-label wildcard, path/query in origin, valid mixed list (no throw).

---

### `tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php` (integration test)

**Analog:** `tests/Integration/TenantResolutionIntegrationTest.php`

**Inline kernel pattern** (lines 51–92 of TenantResolutionIntegrationTest):
```php
final class ResolverTestKernel extends Kernel
{
    public function __construct()
    {
        parent::__construct('test_resolver', false);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new TenancyBundle()];
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new ReplaceTenancyProviderPass());
        $container->addCompilerPass(new MakeResolverChainPublicPass());
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test-secret',
                'test' => true,
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
            ]);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tenancy_resolver_test_'.md5(self::class).'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_resolver_test_'.md5(self::class).'/logs';
    }
}
```

For Phase 17, copy this kernel verbatim into `tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php`, but:
1. Rename class to `OriginResolverTestKernel`
2. Add a `tenancy` extension load with `resolvers: ['host', 'header', 'origin']` and an `origin.allow_list` containing one explicit entry + one wildcard
3. Cache/log dirs use a different `md5(self::class)` seed to avoid collision with `ResolverTestKernel`

**`MakeResolverChainPublicPass` pattern** (lines 29–45) — copy verbatim, also make `TenantContext` public so the test can assert `getTenant()->getSlug()`.

**`setUpBeforeClass` / `tearDownAfterClass` lifecycle** (lines 161–174):
```php
public static function setUpBeforeClass(): void
{
    static::$kernel = new ResolverTestKernel();
    static::$kernel->boot();
}

public static function tearDownAfterClass(): void
{
    static::$kernel->shutdown();
}
```

**Test method shape** — for Phase 17, dispatch a `Request` through the kernel's `handle()` (HTTP kernel route) OR call `ResolverChain::resolve()` directly with a hand-constructed `Request` (faster, no routing required). Reference for direct-call:
```php
$request = Request::create('/', 'GET', [], [], [], ['HTTP_ORIGIN' => 'https://acme.app.example.com']);
$resolution = static::$kernel->getContainer()->get(ResolverChain::class)->resolve($request);
$this->assertNotNull($resolution);
$this->assertSame('acme', $resolution->getTenant()->getSlug());
```

(Recall `ResolverChain::resolve()` returns `?TenantResolution` per Phase 15 — verify exact signature in `src/Resolver/ResolverChain.php`.)

---

### `tests/Integration/Resolver/Support/StubTenant.php` + `StubTenantProvider.php` (test fixtures)

**Analog:** `tests/Integration/Messenger/Support/StubTenant.php` + `tests/Integration/Messenger/Support/StubTenantProvider.php` — copy verbatim, change namespace from `Tenancy\Bundle\Tests\Integration\Messenger\Support` → `Tenancy\Bundle\Tests\Integration\Resolver\Support`.

**StubTenant** — final class, single `string $slug` constructor, all `TenantInterface` methods return either the slug or null/empty/true (full text in fixture file lines 1–43).

**StubTenantProvider** — `addTenant(TenantInterface)` + `findBySlug(string)` throwing `TenantNotFoundException` + `findAll()` (lines 1–38).

**Planner note:** the planner MAY instead reference the existing Messenger Support fixtures via namespace import — but the existing tests are siblings and copy-paste-with-namespace-rename is a more honest pattern (each test directory owns its fixtures). CONTEXT.md `<specifics>` line 236 says "reuse existing fixtures *if they exist*; otherwise add minimal new fixtures". They exist under `Messenger/Support/`. Planner's call: reuse-via-import vs. copy-with-rename.

---

### `tests/Integration/Resolver/Support/RecordingLogger.php` (test fixture — no analog)

No existing PSR-3 logger fixture in the bundle. Build green-field per RESEARCH.md "Recommend `RecordingLogger`":
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Resolver\Support;

use Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string|Stringable, context: array<mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }

    /** @return list<array{level: mixed, message: string|Stringable, context: array<mixed>}> */
    public function warnings(): array
    {
        return array_values(array_filter($this->records, static fn (array $r): bool => 'warning' === $r['level']));
    }
}
```

Use this in both the unit `testMismatchWithXTenantIdLogsWarning` and the integration `testMismatchEmitsWarningLog` test.

---

### `docs/user-guide/origin-header-resolver.md` (new docs page)

No code analog. Per CONTEXT.md D-20 / D-21, sections in order:
1. **Overview** (1 paragraph: what the resolver does, where it sits in chain priority)
2. **Configuration** (YAML example mirroring D-18 — both explicit-map and wildcard-shorthand forms)
3. **Trust Model** (REQUIRED — verbatim points from D-21)
4. **Mismatch Warning** (1 paragraph + sample log line showing the structured context payload from D-11)
5. **Examples** (2–3 minimal SPA → API examples; one shows preflight passthrough)

The "Trust Model" section MUST read as a security note. Use the verbatim quote from CONTEXT.md `<specifics>` line 225: *"Origin is a browser-locked routing hint, not an authentication credential. Pair this resolver with your real auth layer."*

---

### `CHANGELOG.md` (edit)

Add a new `## [0.3.0] - Unreleased` section at the top (or append to existing Unreleased section if present). Entry pattern follows the existing CHANGELOG style — verify by reading the file head. One bullet under `### Added`:
- `OriginHeaderResolver` — SPA-friendly tenant resolver reading the `Origin` HTTP header, allow-list driven, compile-time-guarded, priority 25.

## Shared Patterns

### `declare(strict_types=1);` + file header

**Source:** every `src/**/*.php` file (universal — e.g. `src/Resolver/HeaderResolver.php` line 3)
**Apply to:** every new `.php` file in this phase
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\...;
```

### `final` + `private readonly` properties

**Source:** every resolver (`HeaderResolver.php` line 12, `HostResolver.php` line 12) and every compiler pass (`CacheDecoratorContractPass.php` line 24).
**Apply to:** `OriginHeaderResolver`, `OriginHeaderResolverConfigPass`, `RecordingLogger` (final but `public array $records` is intentional — test fixture).

### Exception-swallow comment style

**Source:** `src/Resolver/HeaderResolver.php` line 34, `src/Resolver/HostResolver.php` line 36.
**Apply to:** `OriginHeaderResolver` — keep the literal comment `// TenantInactiveException is NOT caught — bubbles up as HTTP 403`.

### `service('...')->nullOnInvalid()` autowiring

**Source:** `config/services.php` lines 56, 62, 66, 72 (all resolvers use this for `tenancy.provider`); `src/TenancyBundle.php` line 100 (`service('doctrine')->nullOnInvalid()`).
**Apply to:** `tenancy.resolver.origin` service definition (both `tenancy.provider` and `logger` args).

### Compile-time guard return-early pattern

**Source:** `CacheDecoratorContractPass::process()` lines 38–44 — guard with `if (!hasDefinition) continue;` before doing real work.
**Apply to:** `OriginHeaderResolverConfigPass::process()` — `if (!hasParameter(...) || !in_array('origin', ...)) return;` short-circuit before validation loop.

### Inline kernel + `MakePublicPass` for integration tests

**Source:** `tests/Integration/TenantResolutionIntegrationTest.php` lines 29–92.
**Apply to:** the new `OriginResolverTestKernel` + a fresh `MakeResolverChainPublicPass` (or reuse if planner decides to extract it — note current code duplicates it in each integration test file; convention is duplicate-not-extract).

### Test fixture lifecycle

**Source:** `TenantResolutionIntegrationTest::setUpBeforeClass()` / `tearDownAfterClass()` (lines 161–174).
**Apply to:** `OriginHeaderResolverIntegrationTest` — same shape, single kernel instance per test class.

### `Request::create()` for unit-test HTTP fixtures

**Source:** every unit test in `tests/Unit/Resolver/` — `Request::create('/', 'GET', [], [], [], ['HTTP_X_TENANT_ID' => 'acme'])`.
**Apply to:** `OriginHeaderResolverTest` — use `HTTP_ORIGIN` server var; use `Request::create('/', 'OPTIONS', ...)` for preflight tests.

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `tests/Integration/Resolver/Support/RecordingLogger.php` | PSR-3 logger test fixture | record-and-assert | Bundle has not yet injected any logger anywhere; this is the first. Build green-field from `Psr\Log\AbstractLogger`. |
| `docs/user-guide/origin-header-resolver.md` | docs page | docs | Planner should locate any sibling `docs/user-guide/*.md` for tone — not surveyed in this pattern map (out of code-pattern scope). |
| `tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php` | compiler-pass unit test | container-build | No existing unit-level test of a compiler pass in the repo (CacheDecoratorContractPass is covered only via `ContainerCompilationTest` integration). Build green-field using direct `ContainerBuilder` instantiation. |

## Metadata

**Analog search scope:**
- `src/Resolver/` (all 5 resolvers)
- `src/DependencyInjection/Compiler/` (all 4 existing compiler passes — focused on `CacheDecoratorContractPass` + `ResolverChainPass`)
- `src/TenancyBundle.php` (configure / loadExtension / build)
- `config/services.php` (resolver service definitions, lines 40–75)
- `tests/Unit/Resolver/` (HeaderResolverTest as test template)
- `tests/Integration/` (TenantResolutionIntegrationTest as integration template + Messenger/Support fixtures)

**Files scanned:** 9 production + 4 test
**Pattern extraction date:** 2026-05-15
