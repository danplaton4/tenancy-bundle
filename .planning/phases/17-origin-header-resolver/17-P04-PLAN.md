---
id: 17-P04
phase: 17
plan: 04
name: End-to-end integration test booting TestKernel with OriginHeaderResolver
wave: 3
depends_on: [17-P03]
files_modified:
  - tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php
  - tests/Integration/Resolver/Support/StubTenant.php
  - tests/Integration/Resolver/Support/StubTenantProvider.php
  - tests/Integration/Resolver/Support/RecordingLogger.php
autonomous: true
requirements: [RESV-06]
threats: [T-17-01, T-17-02, T-17-03, T-17-04, T-17-05]
must_haves:
  truths:
    - "Booting an `OriginResolverTestKernel` configured with `tenancy.resolvers: ['header', 'origin']` and a populated allow-list produces a container with `tenancy.resolver_chain` containing the OriginHeaderResolver"
    - "Dispatching a `Request` with a matching `Origin: https://acme.app.example.com` through `ResolverChain::resolve()` returns a TenantResolution whose tenant slug is `'acme'`"
    - "Dispatching a `Request` with `method=OPTIONS` and a matching Origin returns null (preflight passthrough, no exception)"
    - "Dispatching a `Request` with Origin resolving to `acme` AND `X-Tenant-ID: beta` produces a `warning`-level log record on the captured `RecordingLogger`"
    - "Booting a kernel with `'origin'` in resolvers but an EMPTY allow_list fails at container compile time with the locked InvalidArgumentException message"
  artifacts:
    - path: tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php
      provides: "End-to-end test suite — 5 scenarios"
    - path: tests/Integration/Resolver/Support/StubTenant.php
      provides: "Test fixture tenant (copy of Messenger/Support equivalent)"
    - path: tests/Integration/Resolver/Support/StubTenantProvider.php
      provides: "Test fixture provider supporting addTenant + findBySlug"
    - path: tests/Integration/Resolver/Support/RecordingLogger.php
      provides: "PSR-3 logger fixture for integration-test log assertions (shares behavior with Unit/Resolver/Support equivalent but lives in its own namespace per integration-test convention)"
  key_links:
    - from: tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php
      to: src/Resolver/OriginHeaderResolver.php
      via: "container service `tenancy.resolver.origin` resolved via `tenancy.resolver_chain`"
      pattern: "tenancy\\.resolver_chain"
    - from: tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php
      to: tests/Integration/Resolver/Support/RecordingLogger.php
      via: "service replacement of `logger` alias to the RecordingLogger instance"
      pattern: "RecordingLogger"
---

<objective>
Boot a real Symfony kernel with `TenancyBundle` enabled, `'origin'` in `tenancy.resolvers`, and a populated `tenancy.origin.allow_list`. Inject a seeded `StubTenantProvider` (slug `acme`) and a `RecordingLogger`. Dispatch four kinds of `Request` through the actual `ResolverChain::resolve()` to assert:
1. Origin match → tenant resolved
2. OPTIONS preflight → null
3. Origin+X-Tenant-ID mismatch → tenant resolved AND warning log emitted with full context payload
4. Empty allow-list at kernel boot → container compile fails with the locked InvalidArgumentException message

This is the only plan that exercises Plans 01, 02, and 03 together against a real container.

Purpose: Phase 17 success criteria 1, 2, 4 are functional behaviors only verifiable end-to-end. Unit tests cover the resolver class and compiler pass in isolation; this plan proves the wiring works on a booted kernel.

Output: One new test file + three test-fixture files (StubTenant/StubTenantProvider/RecordingLogger).
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
@.planning/phases/17-origin-header-resolver/17-P03-SUMMARY.md
@CLAUDE.md
@tests/Integration/TenantResolutionIntegrationTest.php
@tests/Integration/Messenger/Support/StubTenant.php
@tests/Integration/Messenger/Support/StubTenantProvider.php
@tests/Integration/Support/ReplaceTenancyProviderPass.php
@src/Resolver/OriginHeaderResolver.php
@src/Resolver/ResolverChain.php
@src/TenancyBundle.php

<interfaces>
<!-- The container surface area this plan touches at runtime. -->

After Plan 03 wiring, when `tenancy.resolvers` includes `'origin'`:
- Service id `tenancy.resolver.origin` exists, class `OriginHeaderResolver`, tagged `tenancy.resolver` priority 25, three constructor args.
- Parameter `tenancy.origin.allow_list` is a `list<array{origin: string, host: string, scheme: string, port: int, is_wildcard: bool, wildcard_suffix: ?string, slug: ?string}>` (normalized by `OriginHeaderResolverConfigPass`).
- `ResolverChain::resolve(Request): ?TenantResolution` returns a `TenantResolution` value object or `null` when no resolver matches. **`TenantResolution` exposes its fields as `public readonly` properties (NOT getters)**: access via `$resolution->tenant` (TenantInterface) and `$resolution->resolvedBy` (string FQCN). See `src/Resolver/TenantResolution.php` (Phase 15 introduced it as `final readonly class` with promoted public properties).
- `MakeResolverChainPublicPass` (existing pattern in `tests/Integration/TenantResolutionIntegrationTest.php`) — copy or reuse to expose `tenancy.resolver_chain` for direct test inspection.

`StubTenant`/`StubTenantProvider` already exist under `tests/Integration/Messenger/Support/` — copy verbatim with a namespace rename to `Tenancy\Bundle\Tests\Integration\Resolver\Support` (per PATTERNS.md: bundle convention is duplicate-per-test-dir, not extract-to-shared).
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Create Integration/Resolver/Support fixtures (StubTenant, StubTenantProvider, RecordingLogger)</name>
  <files>
    tests/Integration/Resolver/Support/StubTenant.php
    tests/Integration/Resolver/Support/StubTenantProvider.php
    tests/Integration/Resolver/Support/RecordingLogger.php
  </files>
  <read_first>
    - tests/Integration/Messenger/Support/StubTenant.php (copy verbatim, swap namespace)
    - tests/Integration/Messenger/Support/StubTenantProvider.php (copy verbatim, swap namespace)
    - tests/Unit/Resolver/Support/RecordingLogger.php (Plan 01 Task 1 output — copy verbatim, swap namespace to `...Integration\Resolver\Support`)
  </read_first>
  <behavior>
    Three identical-shape fixture files under `tests/Integration/Resolver/Support/`:
    - `StubTenant.php` implements `TenantInterface` with single-`string $slug` constructor; methods return slug/null/empty/true as in Messenger/Support equivalent.
    - `StubTenantProvider.php` implements `TenantProviderInterface`; `addTenant()` + `findBySlug()` (throws `TenantNotFoundException`) + `findAll()`.
    - `RecordingLogger.php` extends `AbstractLogger`; public `array $records = []`; `log()` records `[level, message, context]`; `warnings()` returns filtered.
    All three live in namespace `Tenancy\Bundle\Tests\Integration\Resolver\Support`.
  </behavior>
  <action>
**File 1: `tests/Integration/Resolver/Support/StubTenant.php`** — verbatim copy of `tests/Integration/Messenger/Support/StubTenant.php` with one line changed (the namespace):

```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Resolver\Support;

use Tenancy\Bundle\TenantInterface;

/**
 * Simple stub tenant for Origin resolver integration tests.
 */
final class StubTenant implements TenantInterface
{
    public function __construct(private readonly string $slug)
    {
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getDomain(): ?string
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function getConnectionConfig(): array
    {
        return [];
    }

    public function getName(): string
    {
        return $this->slug;
    }

    public function isActive(): bool
    {
        return true;
    }
}
```

**File 2: `tests/Integration/Resolver/Support/StubTenantProvider.php`** — verbatim copy of `tests/Integration/Messenger/Support/StubTenantProvider.php` with namespace + doc-comment swap:

```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Resolver\Support;

use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;

/**
 * Stub TenantProvider for Origin resolver integration tests.
 * Holds a map of slug => TenantInterface for deterministic lookups.
 */
final class StubTenantProvider implements TenantProviderInterface
{
    /** @var array<string, TenantInterface> */
    private array $tenants = [];

    public function addTenant(TenantInterface $tenant): void
    {
        $this->tenants[$tenant->getSlug()] = $tenant;
    }

    public function findBySlug(string $slug): TenantInterface
    {
        if (!isset($this->tenants[$slug])) {
            throw new TenantNotFoundException($slug);
        }

        return $this->tenants[$slug];
    }

    public function findAll(): array
    {
        return array_values($this->tenants);
    }
}
```

**File 3: `tests/Integration/Resolver/Support/RecordingLogger.php`** — identical to Plan 01 Task 1 output but in the integration namespace:

```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Resolver\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * In-memory PSR-3 logger for integration tests — records every log() call so the
 * mismatch-warning behavior locked by CONTEXT.md D-11 can be asserted end-to-end.
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
  </action>
  <verify>
    <automated>php -l tests/Integration/Resolver/Support/StubTenant.php &amp;&amp; php -l tests/Integration/Resolver/Support/StubTenantProvider.php &amp;&amp; php -l tests/Integration/Resolver/Support/RecordingLogger.php</automated>
  </verify>
  <acceptance_criteria>
    - Three files exist under `tests/Integration/Resolver/Support/` (`StubTenant.php`, `StubTenantProvider.php`, `RecordingLogger.php`)
    - All three lint clean with `php -l`
    - All three declare namespace `Tenancy\Bundle\Tests\Integration\Resolver\Support`
    - `grep -c 'final class StubTenant implements TenantInterface' tests/Integration/Resolver/Support/StubTenant.php` outputs `1`
    - `grep -c 'final class StubTenantProvider implements TenantProviderInterface' tests/Integration/Resolver/Support/StubTenantProvider.php` outputs `1`
    - `grep -c 'final class RecordingLogger extends AbstractLogger' tests/Integration/Resolver/Support/RecordingLogger.php` outputs `1`
  </acceptance_criteria>
  <done>Three fixture files exist, lint clean, are namespace-coherent for integration-test consumption.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Create OriginHeaderResolverIntegrationTest with inline kernel + 5 scenarios</name>
  <files>tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php</files>
  <read_first>
    - tests/Integration/TenantResolutionIntegrationTest.php (inline-kernel + MakeResolverChainPublicPass + setUpBeforeClass/tearDownAfterClass template — copy structure)
    - tests/Integration/Resolver/Support/StubTenant.php (Task 1 output)
    - tests/Integration/Resolver/Support/StubTenantProvider.php (Task 1 output)
    - tests/Integration/Resolver/Support/RecordingLogger.php (Task 1 output)
    - src/Resolver/ResolverChain.php (to confirm the `resolve(): ?TenantResolution` return type)
    - src/Resolver/TenantResolution.php (**load-bearing — confirms `TenantResolution` exposes its fields via promoted `public readonly` PROPERTIES `tenant` and `resolvedBy`, NOT getters; tests MUST use `$resolution->tenant` and `$resolution->resolvedBy`**)
    - .planning/phases/17-origin-header-resolver/17-CONTEXT.md decisions D-24, D-25
  </read_first>
  <behavior>
    The test file contains three top-level final classes and one test class:
    1. **MakeResolverChainPublicPass** — copy of the analog in `TenantResolutionIntegrationTest.php` (exposes `tenancy.resolver_chain`, its `ResolverChain::class` alias, and `TenantContextOrchestrator`).
    2. **OriginResolverTestKernel** — extends `Kernel`, registers `FrameworkBundle` + `TenancyBundle`; in `build()` adds `MakeResolverChainPublicPass` + a custom `ReplaceProviderWithStubPass` (replace `tenancy.provider` definition with a synthetic `StubTenantProvider` service AND pre-seed two tenants via service factory) + a custom `ReplaceLoggerPass` (alias `logger` to a synthetic `RecordingLogger` service); `registerContainerConfiguration` loads `framework.secret` config AND `tenancy.resolvers: ['header', 'origin']` + `tenancy.origin.allow_list` (with `acme` explicit entry and `*.app.example.com` wildcard entry).
    3. **EmptyOriginAllowListTestKernel** — second kernel for scenario 5; identical setup except `tenancy.origin.allow_list` is `[]`. Boot MUST throw `InvalidArgumentException`.
    4. **OriginHeaderResolverIntegrationTest** — five tests:
       - `testOriginMatchResolvesTenant` — Request with `Origin: https://acme.app.example.com` returns TenantResolution with `$resolution->tenant->getSlug() === 'acme'`, `$resolution->resolvedBy === OriginHeaderResolver::class`
       - `testOptionsPreflightReturnsNull` — Request with method OPTIONS + matching Origin → `ResolverChain::resolve()` returns null (preflight passthrough)
       - `testWildcardOriginMatchResolvesTenant` — Request with `Origin: https://beta.app.example.com` (matching wildcard) returns TenantResolution with `$resolution->tenant->getSlug() === 'beta'`
       - `testMismatchWithXTenantIdLogsWarning` — Request with Origin acme AND `X-Tenant-ID: beta` → resolves acme (asserted via `$resolution->tenant->getSlug()`), `RecordingLogger::warnings()` count is 1, context shape matches D-11 locked payload
       - `testEmptyAllowListFailsAtBoot` (uses `EmptyOriginAllowListTestKernel`) — `expectException(\InvalidArgumentException::class)` and `expectExceptionMessage('tenancy.origin.allow_list is empty')`
  </behavior>
  <action>
Create `tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php` with the structure below. Each kernel needs a unique cache+log dir seed (`md5(self::class)`) to avoid collision with other integration kernels.

**Critical API note (read `src/Resolver/TenantResolution.php` BEFORE writing):**
`TenantResolution` is a `final readonly class` with promoted `public readonly` properties:
```php
final readonly class TenantResolution {
    public function __construct(
        public TenantInterface $tenant,
        public string $resolvedBy,
    ) {}
}
```
There are **no getters**. Access fields directly: `$resolution->tenant`, `$resolution->resolvedBy`. Calling `$resolution->getTenant()` or `$resolution->getResolvedBy()` will throw `Error: Call to undefined method` and fail the suite.

```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Resolver;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Kernel;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\Resolver\OriginHeaderResolver;
use Tenancy\Bundle\Resolver\ResolverChain;
use Tenancy\Bundle\TenancyBundle;
use Tenancy\Bundle\Tests\Integration\Resolver\Support\RecordingLogger;
use Tenancy\Bundle\Tests\Integration\Resolver\Support\StubTenant;
use Tenancy\Bundle\Tests\Integration\Resolver\Support\StubTenantProvider;

/**
 * Compiler pass exposing tenancy.resolver_chain (+ ResolverChain alias) as public for test access.
 * Mirrors the pattern in tests/Integration/TenantResolutionIntegrationTest.php.
 */
final class MakeOriginResolverChainPublicPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition('tenancy.resolver_chain')) {
            $container->getDefinition('tenancy.resolver_chain')->setPublic(true);
        }
        if ($container->hasAlias(ResolverChain::class)) {
            $container->getAlias(ResolverChain::class)->setPublic(true);
        }
    }
}

/**
 * Replaces the tenancy.provider service definition with a pre-seeded StubTenantProvider.
 * Two tenants seeded: 'acme' and 'beta'.
 */
final class SeedStubProviderPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $factory = static function (): StubTenantProvider {
            $provider = new StubTenantProvider();
            $provider->addTenant(new StubTenant('acme'));
            $provider->addTenant(new StubTenant('beta'));

            return $provider;
        };

        $def = new Definition(StubTenantProvider::class);
        $def->setFactory($factory);
        $def->setPublic(true);

        $container->setDefinition('tenancy.provider', $def);
        $container->setAlias(TenantProviderInterface::class, 'tenancy.provider')->setPublic(true);
    }
}

/**
 * Replaces the `logger` alias / service so OriginHeaderResolver's nullOnInvalid logger
 * arg resolves to our RecordingLogger instance, letting tests inspect warning records.
 */
final class ReplaceLoggerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $def = new Definition(RecordingLogger::class);
        $def->setPublic(true);
        $container->setDefinition('logger', $def);
        $container->setAlias(LoggerInterface::class, 'logger')->setPublic(true);
    }
}

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
        $container->addCompilerPass(new SeedStubProviderPass());
        $container->addCompilerPass(new ReplaceLoggerPass());
        $container->addCompilerPass(new MakeOriginResolverChainPublicPass());
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
            $container->loadFromExtension('tenancy', [
                'resolvers' => ['header', 'origin'],
                'origin' => [
                    'allow_list' => [
                        ['origin' => 'https://acme.app.example.com', 'slug' => 'acme'],
                        'https://*.app.example.com',
                    ],
                ],
            ]);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tenancy_origin_test_'.md5(static::class).'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_origin_test_'.md5(static::class).'/logs';
    }
}

final class EmptyOriginAllowListTestKernel extends Kernel
{
    public function __construct()
    {
        parent::__construct('test_origin_empty', false);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new TenancyBundle()];
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
            $container->loadFromExtension('tenancy', [
                'resolvers' => ['header', 'origin'],
                'origin' => [
                    'allow_list' => [],
                ],
            ]);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tenancy_origin_empty_test_'.md5(static::class).'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_origin_empty_test_'.md5(static::class).'/logs';
    }
}

final class OriginHeaderResolverIntegrationTest extends TestCase
{
    private static ?OriginResolverTestKernel $kernel = null;

    public static function setUpBeforeClass(): void
    {
        self::$kernel = new OriginResolverTestKernel();
        self::$kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$kernel?->shutdown();
        self::$kernel = null;
    }

    protected function setUp(): void
    {
        // Reset the RecordingLogger between tests so warning assertions are isolated.
        $logger = self::$kernel->getContainer()->get('logger');
        if ($logger instanceof RecordingLogger) {
            $logger->records = [];
        }
    }

    public function testOriginMatchResolvesTenant(): void
    {
        $chain = self::$kernel->getContainer()->get(ResolverChain::class);
        $this->assertInstanceOf(ResolverChain::class, $chain);

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ORIGIN' => 'https://acme.app.example.com']);
        $resolution = $chain->resolve($request);

        $this->assertNotNull($resolution);
        // NOTE: TenantResolution exposes promoted public readonly PROPERTIES — no getters.
        $this->assertSame('acme', $resolution->tenant->getSlug());
        $this->assertSame(OriginHeaderResolver::class, $resolution->resolvedBy);
    }

    public function testWildcardOriginMatchResolvesTenant(): void
    {
        $chain = self::$kernel->getContainer()->get(ResolverChain::class);

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ORIGIN' => 'https://beta.app.example.com']);
        $resolution = $chain->resolve($request);

        $this->assertNotNull($resolution);
        $this->assertSame('beta', $resolution->tenant->getSlug());
        $this->assertSame(OriginHeaderResolver::class, $resolution->resolvedBy);
    }

    public function testOptionsPreflightReturnsNull(): void
    {
        $chain = self::$kernel->getContainer()->get(ResolverChain::class);

        $request = Request::create('/', 'OPTIONS', [], [], [], ['HTTP_ORIGIN' => 'https://acme.app.example.com']);
        $resolution = $chain->resolve($request);

        $this->assertNull($resolution);
    }

    public function testMismatchWithXTenantIdLogsWarning(): void
    {
        $chain = self::$kernel->getContainer()->get(ResolverChain::class);

        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_ORIGIN' => 'https://acme.app.example.com',
            'HTTP_X_TENANT_ID' => 'beta',
        ]);
        $resolution = $chain->resolve($request);

        $this->assertNotNull($resolution);
        $this->assertSame('acme', $resolution->tenant->getSlug(), 'Origin wins over X-Tenant-ID');

        $logger = self::$kernel->getContainer()->get('logger');
        $this->assertInstanceOf(RecordingLogger::class, $logger);
        $warnings = $logger->warnings();
        $this->assertCount(1, $warnings);
        $this->assertSame([
            'origin' => 'https://acme.app.example.com',
            'origin_slug' => 'acme',
            'header_slug' => 'beta',
            'winner' => 'origin',
        ], $warnings[0]['context']);
    }

    public function testEmptyAllowListFailsAtBoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tenancy.origin.allow_list is empty but "origin" is configured in tenancy.resolvers');

        $kernel = new EmptyOriginAllowListTestKernel();
        $kernel->boot();
    }
}
```

Notes for the executor:
- `Request::create('/', 'GET', [], [], [], ['HTTP_ORIGIN' => ...])` — the server-var prefix `HTTP_` is how Symfony populates the request `headers` bag from `$_SERVER`.
- `ResolverChain::resolve()` returns `?TenantResolution` (Phase 15 introduced this). Confirm by reading `src/Resolver/ResolverChain.php` before writing the test — if the method signature differs (e.g. still returns `?TenantInterface`), adapt assertions accordingly. The pre-flight test asserts `assertNull` either way.
- `$resolution->resolvedBy` is a string holding the resolver's class FQCN; compare to `OriginHeaderResolver::class`. **Do NOT** use `$resolution->getResolvedBy()` — there is no such method on `TenantResolution`.
- `$resolution->tenant` is a `TenantInterface`; call `->getSlug()` on it. **Do NOT** use `$resolution->getTenant()`.
- `setUp()` (instance-level) clears the logger between tests so test ordering doesn't pollute assertions. The kernel is shared (class-level) so the container is built once.
- For `testEmptyAllowListFailsAtBoot`, the second kernel must NOT share `setUpBeforeClass` — it boots inside the test method itself so the exception is observable in PHPUnit's expectation.
- If `ResolverChain` is final and not directly instantiable, accessing it via `self::$kernel->getContainer()->get(ResolverChain::class)` requires the alias to be public — handled by `MakeOriginResolverChainPublicPass`.
  </action>
  <verify>
    <automated>vendor/bin/phpunit --filter OriginHeaderResolverIntegrationTest --no-coverage 2>&amp;1 | tail -15</automated>
  </verify>
  <acceptance_criteria>
    - File `tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php` exists
    - `vendor/bin/phpunit --filter OriginHeaderResolverIntegrationTest --no-coverage` exits 0
    - File defines all five test methods: `testOriginMatchResolvesTenant`, `testWildcardOriginMatchResolvesTenant`, `testOptionsPreflightReturnsNull`, `testMismatchWithXTenantIdLogsWarning`, `testEmptyAllowListFailsAtBoot`
    - File defines exactly 4 final classes inside the namespace: `MakeOriginResolverChainPublicPass`, `SeedStubProviderPass`, `ReplaceLoggerPass`, plus the two kernels `OriginResolverTestKernel` and `EmptyOriginAllowListTestKernel`, plus the test class `OriginHeaderResolverIntegrationTest`
    - File contains literal `'https://*.app.example.com'` shorthand entry to verify D-19 normalization path end-to-end
    - File contains literal `['origin' => 'https://acme.app.example.com', 'slug' => 'acme']` map entry to verify D-18 explicit-form path
    - `testEmptyAllowListFailsAtBoot` uses `expectExceptionMessage('tenancy.origin.allow_list is empty but "origin" is configured in tenancy.resolvers')` — verifies Plan 02's compiler-pass guard fires through real boot
    - `tests/Integration/Resolver/Support/{StubTenant,StubTenantProvider,RecordingLogger}.php` are all present (Task 1 deliverables)
    - Total test count for the file is exactly 5
    - File uses property access `$resolution->tenant` and `$resolution->resolvedBy` (NOT getter calls) — confirm with `grep -cF '$resolution->getTenant()' tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php` outputs `0` AND `grep -cF '$resolution->getResolvedBy()' tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php` outputs `0`
  </acceptance_criteria>
  <done>All 5 integration scenarios pass; Plans 01+02+03 are proven to work together against a real Symfony kernel.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| HTTP request → bundle resolver chain → tenant context | First time Plans 01-03 see real request data |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-17-01 | Spoofing (curl/Postman setting Origin) | `OriginHeaderResolver` runtime behavior | accept (docs-only mitigation in Plan 05) | Threat is real but inherent to Origin trust model; integration test verifies the resolver still routes deterministically based on allow-list match — spoofability is a docs-layer warning (Plan 05 Trust Model section), not a runtime block |
| T-17-02 | Elevation via wildcard | E2E allow-list validation | mitigate (already covered by Plan 02 + Plan 03 wiring; Plan 04 exercises it via real boot) | `testEmptyAllowListFailsAtBoot` exercises the compile-time path through a real kernel boot — proves the guard fires end-to-end |
| T-17-03 | DoS via empty allow-list | `OriginHeaderResolverConfigPass` triggered by real Symfony container compile | mitigate | `testEmptyAllowListFailsAtBoot` validates the kernel refuses to boot with the locked error message |
| T-17-04 | Routing confusion (mismatch) | `OriginHeaderResolver` mismatch warning | mitigate | `testMismatchWithXTenantIdLogsWarning` asserts the structured log record reaches the captured logger with the full payload — closes the forensics loop |
| T-17-05 | Preflight breakage | `OriginHeaderResolver` OPTIONS short-circuit | mitigate | `testOptionsPreflightReturnsNull` verifies preflight requests pass through cleanly even with a matching Origin |
</threat_model>

<verification>
- Tests: `vendor/bin/phpunit --filter OriginHeaderResolverIntegrationTest --no-coverage`
- Tests (full): `vendor/bin/phpunit --no-coverage` — assert NO regressions in existing integration suite
- Static: `php -l` on all four new files
- Type: `vendor/bin/phpstan analyse tests/Integration/Resolver --level=9 --no-progress`
- Style: `vendor/bin/php-cs-fixer check tests/Integration/Resolver`
</verification>

<success_criteria>
- All 5 integration tests pass
- Full PHPUnit suite (unit + integration) green: `vendor/bin/phpunit` exits 0
- Zero regressions in `tests/Integration/TenantResolutionIntegrationTest.php` or any other existing integration test
- PHPStan level 9 clean on `tests/Integration/Resolver/`
- Four new files created; no existing files modified
</success_criteria>

<output>
After completion, create `.planning/phases/17-origin-header-resolver/17-P04-SUMMARY.md` capturing: pass/fail status of each of the 5 scenarios, the actual `ResolverChain::resolve()` return shape observed (in case it differs from `?TenantResolution`), and any kernel boot quirks (Symfony version differences, deprecation notices, etc.).
</output>
</content>
</invoke>