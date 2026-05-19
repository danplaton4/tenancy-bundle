# Phase 19: Profiler Tab — Pattern Map

**Mapped:** 2026-05-18
**Files analyzed:** 14 (12 new, 2 modified)
**Analogs found:** 11 / 14 (with strong in-bundle analogs); 3 templates have only vendor analogs (no in-bundle Twig precedent yet)

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `src/Profiler/TenantProfilerStash.php` (new) | event-listener / state-holder | event-driven | `src/EventListener/TenantContextOrchestrator.php` (multi-`#[AsEventListener]`) + `src/EventListener/EntityManagerResetListener.php` (single-attribute idiom) | exact (role+flow) |
| `src/Profiler/TenantDataCollector.php` (new) | data-collector | request-response (read-only on `kernel.response`) | vendor `vendor/symfony/framework-bundle/DataCollector/AbstractDataCollector.php` (no in-bundle analog) | role-only (no in-bundle precedent) |
| `src/Resources/views/Collector/tenant.html.twig` (new) | view template | render (read scalar `collector.data.*`) | vendor `symfony/web-profiler-bundle/Resources/views/Collector/translation.html.twig` (no in-bundle Twig template exists) | external-only |
| `src/Resources/views/Collector/_icon.svg.twig` (new) | view fragment / asset | render | vendor `symfony/web-profiler-bundle/Resources/views/Icon/translation.svg` | external-only |
| `config/services_dev.php` (new) | DI configurator | DI registration | tail of `config/services.php` (`interface_exists(MessageBusInterface::class)` block, lines 134–146) | exact (style) |
| `src/TenancyBundle.php` (modified) | bundle entry point | DI registration | self (lines 101–103 `loadExtension()`) | self |
| `composer.json` (modified) | manifest | metadata | self (existing `require-dev` block, lines 31–43) | self |
| `tests/Unit/Profiler/TenantProfilerStashTest.php` (new) | unit test | test | `tests/Unit/EventListener/EntityManagerResetListenerTest.php` (idiomatic pure-unit listener test) + `tests/Unit/EventListener/TenantContextOrchestratorTest.php` (multi-event listener test with spies) | exact (role+flow) |
| `tests/Unit/Profiler/TenantDataCollectorTest.php` (new) | unit test | test | `tests/Unit/EventListener/EntityManagerResetListenerTest.php` (no DataCollector unit test in bundle) | role-match |
| `tests/Integration/Profiler/TenantDataCollectorCompileOutTest.php` (new) | integration test | container introspection | `tests/Integration/ContainerCompilationTest.php` (kernel boot + `$container->has(...)`) + `tests/Integration/TestKernel.php` | exact |
| `tests/Integration/Profiler/TenantDataCollectorSerializationTest.php` (new) | integration test | serialize round-trip | `tests/Integration/ContainerCompilationTest.php` (testcase style); no native round-trip analog | role-only |
| `tests/Integration/Profiler/TenantDataCollectorWdtTest.php` (new) | integration test | HTTP request + HTML scrape | `tests/Integration/AutoconfigurationTest.php` (kernel-driven container assertion); no HTTP/Twig-rendering analog in bundle yet | role-only |
| `tests/Integration/Profiler/SourceLayoutTest.php` (new) | integration test | static-file assertion | `tests/Integration/ContainerCompilationTest.php` (TestCase only — uses no kernel) | role-only |
| `tests/Integration/Profiler/Support/ProfilerTestKernel.php` (new) | test kernel | kernel boot | `tests/Integration/TestKernel.php` (minimal `FrameworkBundle + TenancyBundle`) + `tests/Integration/Support/BootstrapperTestKernel.php` (kernel that adds an extra bundle) | exact |

## Pattern Assignments

---

### `src/Profiler/TenantProfilerStash.php` (event-listener / state-holder, event-driven)

**Primary analog:** `src/EventListener/TenantContextOrchestrator.php` (multi-attribute `#[AsEventListener]` style — TWO attributes stacked on the class).
**Secondary analog:** `src/EventListener/EntityManagerResetListener.php` (idiomatic `final class` + single attribute + `__invoke` style).

**Multi-event class-level `#[AsEventListener]` pattern** — copy directly from `src/EventListener/TenantContextOrchestrator.php` lines 18–20:

```php
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: TenantContextOrchestrator::PRIORITY)]
#[AsEventListener(event: KernelEvents::TERMINATE, method: 'onKernelTerminate')]
final class TenantContextOrchestrator
{
    // ...
    public function onKernelRequest(RequestEvent $event): void { /* ... */ }
    public function onKernelTerminate(TerminateEvent $event): void { /* ... */ }
}
```

For the stash: stack four `#[AsEventListener]` attributes on the class, one per event (`TenantResolved`, `TenantBootstrapped`, `TenantContextCleared`, `ExceptionEvent`), each with `method: 'on...'`. Note this matches the bundle's idiom — `EventSubscriberInterface` is NOT used anywhere in `src/`.

**`final class` + `private readonly` + `strict_types` header** — copy from `src/EventListener/EntityManagerResetListener.php` lines 1–6, 11–13:

```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\EventListener;

// ...

#[AsEventListener(event: TenantContextCleared::class)]
final class EntityManagerResetListener
{
```

**Tenancy-namespace exception predicate** — the stash filters captured exceptions to `Tenancy\Bundle\Exception\*` only. Reference the existing exceptions: `src/Exception/TenantNotFoundException.php` line 5 (`namespace Tenancy\Bundle\Exception`), `TenantInactiveException.php`, `TenantMissingException.php`. The predicate is `str_starts_with($throwable::class, 'Tenancy\\Bundle\\Exception\\')`.

**Event field access for capture** — verified shapes:

```php
// src/Event/TenantResolved.php line 15
public readonly string $resolvedBy,

// src/Event/TenantBootstrapped.php lines 13–16
/** @param string[] $bootstrappers FQCNs of bootstrappers that ran (in order) */
public function __construct(
    public readonly TenantInterface $tenant,
    public readonly array $bootstrappers,
) {

// src/Event/TenantContextCleared.php — empty marker event, no payload
```

So stash methods read `$event->resolvedBy` (string) and `$event->bootstrappers` (string[]) — no coercion needed beyond defensive `array_values(array_map('strval', ...))` at `collect()` time.

**`ResetInterface` import + method** — Symfony Contracts:

```php
use Symfony\Contracts\Service\ResetInterface;

final class TenantProfilerStash implements ResetInterface
{
    public function reset(): void
    {
        $this->resolvedBy = null;
        $this->bootstrapperFqcns = [];
        $this->capturedException = null;
    }
}
```

Bundle has no prior `ResetInterface` implementation, but the contract is straightforward. RESEARCH.md confirms autoconfigure tags `kernel.reset` automatically.

---

### `src/Profiler/TenantDataCollector.php` (data-collector, request-response)

**Primary analog:** vendor `vendor/symfony/framework-bundle/DataCollector/AbstractDataCollector.php` (no in-bundle analog — first DataCollector in this bundle).

**Base-class contract** — extend `AbstractDataCollector` (NOT `DataCollector` directly), override `getName()` and `static getTemplate()`:

```php
namespace Symfony\Bundle\FrameworkBundle\DataCollector;

abstract class AbstractDataCollector extends DataCollector implements TemplateAwareDataCollectorInterface
{
    public function getName(): string { return static::class; }
    public static function getTemplate(): ?string { return null; }
}
```

**`collect(Request, Response, ?Throwable)` signature** — from `DataCollectorInterface` (vendor):

```php
public function collect(Request $request, Response $response, ?\Throwable $exception = null): void;
```

**Constructor shape** — copy the project's idiom of `private readonly` promoted properties from `src/EventListener/TenantContextOrchestrator.php` lines 25–31:

```php
public function __construct(
    private readonly TenantContext $tenantContext,
    private readonly BootstrapperChain $bootstrapperChain,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly ResolverChain $resolverChain,
) {
}
```

For the collector, constructor args are `(TenantProfilerStash $stash, TenantContext $tenantContext, string $driver, string $landlordConnection)`. The string args come from DI parameters `%tenancy.driver%` and `%tenancy.landlord_connection%`, both already registered in `src/TenancyBundle.php` lines 118 and 120.

**`$this->data` scalar-only shape** — RESEARCH.md lines 722–731 give the exact 8-key assignment. Verified `TenantInterface` accessors in `src/TenantInterface.php`:

```php
public function getSlug(): string;   // line 9
public function getName(): string;   // line 16
```

Do NOT call `$this->cloneVar()` — per D-11 and RESEARCH Pitfall 4, scalar arrays are stored directly. The base class `DataCollector::$data` is typed `protected array|Data` — plain array works.

**Connection-name DSN-leak defence** — copy the throw-on-DSN-shape pattern from RESEARCH lines 713–720:

```php
if ($connectionName !== null && (str_contains($connectionName, ':') || str_contains($connectionName, '@'))) {
    throw new \RuntimeException(sprintf(
        'TenantDataCollector: connection_name "%s" looks like a DSN — never display credentials.',
        $connectionName
    ));
}
```

---

### `src/Resources/views/Collector/tenant.html.twig` (view template, render)

**Primary analog:** vendor `symfony/web-profiler-bundle/Resources/views/Collector/translation.html.twig` (no in-bundle Twig template — this is the first one).

**Required block structure** — copy from RESEARCH lines 449–549. The three required blocks are `toolbar`, `menu`, `panel` (verified against Symfony HEAD via `gh api`). Template extends:

```twig
{% extends '@WebProfiler/Profiler/layout.html.twig' %}
```

**State-driven badge text** — three `{% if data.state == ... %}` branches:
- `'resolved'` → `{{ collector.data.slug }}`
- `'error'` → literal `⚠`
- `'null'` → literal `—`

**Status colors** — verified palette `'red'` (error) / `'yellow'` (null) / `'green'` (resolved) / `''` (neutral), passed via `sf-toolbar-status-{color}` and `label-status-{error|warning|success}`.

**Inline icon include** — `{{ include('@Tenancy/Collector/_icon.svg.twig') }}` — namespace `@Tenancy` auto-discovered by `AbstractBundle` (verified by `src/TenancyBundle.php` line 33 `extends AbstractBundle`).

---

### `src/Resources/views/Collector/_icon.svg.twig` (view fragment / asset, render)

**Primary analog:** vendor `symfony/web-profiler-bundle/Resources/views/Icon/translation.svg`.

**Canonical SVG attributes** (verified) — width/height 24, viewBox `0 0 24 24`, `stroke="currentColor"` (so the icon inherits toolbar text color), `fill="none"`, `stroke-width="1.5"`, `stroke-linecap="round"`, `stroke-linejoin="round"`. See RESEARCH lines 553–559 for the chain-glyph `<path>` data.

---

### `config/services_dev.php` (DI configurator, DI registration)

**Primary analog:** `config/services.php` tail block (lines 134–146 — the `interface_exists(MessageBusInterface::class)` conditional registration). The new file imports cleanly the same DSL functions and shape.

**File header (imports + `return` closure)** — copy from `config/services.php` lines 1–8, 31–32:

```php
<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// ... use statements for new classes ...

return function (ContainerConfigurator $container): void {
    $services = $container->services();
    // ...
};
```

**Service-definition style** — copy from `config/services.php` lines 81–88 (a service with `autoconfigure(true)` + multiple `service()`/`param()` args):

```php
$services->set(TenantContextOrchestrator::class)
    ->autoconfigure(true)
    ->args([
        service('tenancy.context'),
        service('tenancy.bootstrapper_chain'),
        service('event_dispatcher'),
        service('tenancy.resolver_chain'),
    ]);
```

**Tagged registration with attributes** — copy from `config/services.php` lines 62, 93, 117 (`->tag('name', ['key' => value, 'priority' => N])`):

```php
$services->set(HostResolver::class)
    ->args([/* ... */])
    ->tag('tenancy.resolver', ['priority' => 30]);
```

For the collector, the tag is `'data_collector'` with attributes `['id' => 'tenancy', 'template' => '@Tenancy/Collector/tenant.html.twig', 'priority' => 270]`. The collector should also be `->public()` so the compile-out test can `$container->has(TenantDataCollector::class)` (test-container `has()` is fine for private services too, but public is harmless).

**`param()` for DI parameter injection** — copy from `config/services.php` lines 60, 102 (`param('tenancy.host.app_domain')`, `param('tenancy.cache_prefix_separator')`). Used in the collector args: `param('tenancy.driver')`, `param('tenancy.landlord_connection')`.

**Why NOT inside `config/services.php`** — per RESEARCH Summary §1 and Pitfall 1: `ContainerConfigurator` exposes no `getParameter()`. The `interface_exists(MessageBusInterface::class)` precedent in `config/services.php` works only because it's a pure PHP function call. `kernel.debug` is a DI parameter — only readable from `ContainerBuilder` in `loadExtension()`. Hence the separate `services_dev.php` file imported conditionally.

---

### `src/TenancyBundle.php` (bundle entry point, DI registration — MODIFIED)

**Modification target:** `loadExtension()` method, lines 101–124.

**Existing pattern (line 103) — the `->import()` call:**

```php
public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
{
    $container->import('../config/services.php');
    // ...
}
```

**Add immediately after line 103:**

```php
if ($builder->getParameter('kernel.debug') === true) {
    $container->import('../config/services_dev.php');
}
```

**Rationale for placement** — `$builder->getParameter('kernel.debug')` is verified to work in `loadExtension()` because the method signature (line 101) receives `ContainerBuilder $builder` as the third argument. Symfony itself uses `$builder->getParameter('kernel.debug')` from inside `FrameworkExtension::load()` (RESEARCH source list: vendor lines 797, 996, 1209, 1779). This is the safest, idiom-respecting placement.

**Do NOT add to `build()`** — that's the compiler-pass slot (line 213+). D-06 explicitly rejects a dedicated compiler pass.

---

### `composer.json` (manifest, metadata — MODIFIED)

**Modification target:** `require-dev` block, lines 31–43.

**Existing pattern (lines 31–43) — each entry is `"package/name": "version-constraint"` sorted alphabetically (the `"sort-packages": true` config at line 68 enforces this):**

```json
"require-dev": {
    "doctrine/dbal": "^4.4",
    "doctrine/doctrine-bundle": "^2.13||^3.0",
    "doctrine/migrations": "^3.9",
    "doctrine/orm": "^3.3",
    "friendsofphp/php-cs-fixer": "^3.0",
    "nikic/php-parser": "^5.0",
    "phpstan/phpstan": "^2.1",
    "phpunit/phpunit": "^11.0",
    "symfony/framework-bundle": "^7.4||^8.0",
    "symfony/messenger": "^7.4||^8.0",
    "symfony/phpunit-bridge": "^7.4||^8.0"
}
```

**Add** (alphabetically positioned — `twig-bundle` after `phpunit-bridge`; `web-profiler-bundle` after `twig-bundle`):

```json
"symfony/twig-bundle": "^7.4||^8.0",
"symfony/web-profiler-bundle": "^7.4||^8.0"
```

Constraint `^7.4||^8.0` matches the bundle's existing Symfony component matrix on every other `symfony/*` entry.

After editing, run `composer update --dev` (or at minimum `composer update symfony/web-profiler-bundle symfony/twig-bundle --with-all-dependencies`) and commit `composer.lock`.

---

### `tests/Unit/Profiler/TenantProfilerStashTest.php` (unit test, test)

**Primary analog:** `tests/Unit/EventListener/EntityManagerResetListenerTest.php` (the closest single-purpose listener-with-attribute test).
**Secondary analog:** `tests/Unit/EventListener/TenantContextOrchestratorTest.php` (multi-event listener with spies).

**File header + class shape** — copy from `tests/Unit/EventListener/EntityManagerResetListenerTest.php` lines 1–14:

```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\EventListener;

use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Tenancy\Bundle\Event\TenantContextCleared;
use Tenancy\Bundle\EventListener\EntityManagerResetListener;

final class EntityManagerResetListenerTest extends TestCase
{
    private ManagerRegistry&MockObject $managerRegistry;
    private EntityManagerResetListener $listener;

    protected function setUp(): void
    {
        $this->managerRegistry = $this->createMock(ManagerRegistry::class);
        $this->listener = new EntityManagerResetListener($this->managerRegistry);
    }
```

The bundle convention: `final class XxxTest extends TestCase`, `protected function setUp(): void`, typed fields including intersection types (`Foo&MockObject`).

**`#[AsEventListener]` attribute introspection test** — copy from `tests/Unit/EventListener/EntityManagerResetListenerTest.php` lines 69–82:

```php
public function testHasAsEventListenerAttribute(): void
{
    $reflection = new \ReflectionClass(EntityManagerResetListener::class);
    $attributes = $reflection->getAttributes(AsEventListener::class);

    $this->assertNotEmpty($attributes, 'EntityManagerResetListener must have #[AsEventListener] attribute');

    $attribute = $attributes[0]->newInstance();
    $this->assertSame(
        TenantContextCleared::class,
        $attribute->event,
        'AsEventListener must specify event: TenantContextCleared::class',
    );
}
```

For the stash, iterate `getAttributes(AsEventListener::class)` and assert four entries (one per event class).

**Multi-event capture-and-reset spy pattern** — copy the `SpyBootstrapper` callback technique from `tests/Unit/EventListener/TenantContextOrchestratorTest.php` lines 29–48 (a spy class with counters + an `$onClear` callback). For the stash, equivalent assertions: build a `TenantResolved` instance, call `onTenantResolved($event)`, assert `getResolvedBy()` returns the FQCN; then call `reset()` and assert the getter returns null.

**Tenancy-exception-only capture predicate test** — build two `ExceptionEvent` instances, one with `new TenantNotFoundException('x')` (in scope), one with `new \RuntimeException('x')` (out of scope); assert only the first is captured. `ExceptionEvent` constructor: `new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $throwable)`. See `TenantContextOrchestratorTest::testOnKernelRequestIgnoresSubRequests` lines 117–130 for the `HttpKernelInterface` mock + `RequestEvent` build idiom, which transfers directly to `ExceptionEvent`.

---

### `tests/Unit/Profiler/TenantDataCollectorTest.php` (unit test, test)

**Primary analog:** `tests/Unit/EventListener/EntityManagerResetListenerTest.php` (closest pure-unit test that wires mocks via constructor — no Doctrine, no kernel).

**setUp pattern with mocks** — copy from `tests/Unit/EventListener/EntityManagerResetListenerTest.php` lines 19–23. For the collector:

```php
protected function setUp(): void
{
    $this->stash = $this->createMock(TenantProfilerStash::class);   // or build a real one
    $this->tenantContext = new TenantContext();                      // zero-dep value holder
    $this->collector = new TenantDataCollector(
        $this->stash,
        $this->tenantContext,
        'database_per_tenant',
        'default',
    );
}
```

**`TenantInterface` mock** — copy from `tests/Unit/EventListener/TenantContextOrchestratorTest.php` line 94:

```php
$this->tenant = $this->createMock(TenantInterface::class);
```

Then `$this->tenant->method('getSlug')->willReturn('acme'); $this->tenant->method('getName')->willReturn('Acme Corp');` to feed scalar values into `$tenant?->getSlug()` / `$tenant?->getName()` reads inside `collect()`.

**`collect()` invocation in unit test** — Symfony `Request` and `Response` instances are trivial to construct in unit tests (no kernel needed):

```php
$this->collector->collect(Request::create('/'), new Response());
```

(Pattern verified: `tests/Unit/EventListener/TenantContextOrchestratorTest.php` uses `Request::create('/')` and `new Response()` throughout.)

**8-key data shape assertion** — assert `$collector->getData()` (or via reflection on the protected `$data`) returns the exact 8 keys per D-08. Test each state (`resolved` / `null` / `error`) and the DSN-defence throw.

---

### `tests/Integration/Profiler/TenantDataCollectorCompileOutTest.php` (integration test, container introspection)

**Primary analog:** `tests/Integration/ContainerCompilationTest.php` + `tests/Integration/TestKernel.php`.

**Kernel-boot test class shape** — copy from `tests/Integration/ContainerCompilationTest.php` lines 11–30:

```php
final class ContainerCompilationTest extends TestCase
{
    private static TestKernel $kernel;

    public static function setUpBeforeClass(): void
    {
        static::$kernel = new TestKernel('test', false);
        static::$kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        static::$kernel->shutdown();
    }

    public function testContainerCompilesWithoutCircularReferences(): void
    {
        $this->assertTrue(static::$kernel->getContainer()->has('tenancy.context'));
    }
```

**Parameterized kernels (debug=true vs debug=false)** — the existing `TestKernel::__construct(string $environment = 'test', bool $debug = false)` (`tests/Integration/TestKernel.php` lines 21–24) already takes `(environment, debug)` — boot twice:

```php
$debugKernel = new TestKernel('test', true);
$debugKernel->boot();
self::assertTrue($debugKernel->getContainer()->has(TenantDataCollector::class));
self::assertTrue($debugKernel->getContainer()->has(TenantProfilerStash::class));
$debugKernel->shutdown();

$prodKernel = new TestKernel('prod', false);
$prodKernel->boot();
self::assertFalse($prodKernel->getContainer()->has(TenantDataCollector::class));
self::assertFalse($prodKernel->getContainer()->has(TenantProfilerStash::class));
$prodKernel->shutdown();
```

**Important:** `tests/Integration/TestKernel.php` (lines 55–62) keys cache dir by `static::class` and environment — so booting the same class twice with different envs uses different cache dirs (no compiled-container collision).

---

### `tests/Integration/Profiler/TenantDataCollectorSerializationTest.php` (integration test, serialize round-trip)

**Primary analog:** `tests/Integration/ContainerCompilationTest.php` for test-class shape; serialization is plain PHP.

**Build collector → populate via `collect()` → serialize/unserialize → assert equality:**

```php
$collector = new TenantDataCollector(/* args with real stash */);
$collector->collect(Request::create('/'), new Response());

$blob = serialize($collector);
$restored = unserialize($blob);

self::assertInstanceOf(TenantDataCollector::class, $restored);
self::assertSame($collector->getData(), $restored->getData());
```

**`DataCollector::__serialize`/`__unserialize`** are inherited and round-trip only `$this->data` (vendor `vendor/symfony/http-kernel/DataCollector/DataCollector.php` lines 87–95 — verified). Because our 8-key shape is scalar-only, native `serialize()` is lossless.

**Note on `getData()` accessor:** `AbstractDataCollector::$data` is `protected`. The new collector should expose `public function getData(): array { return $this->data; }` (used by the test). This getter does not exist in the base class.

---

### `tests/Integration/Profiler/TenantDataCollectorWdtTest.php` (integration test, HTTP request + HTML scrape)

**Primary analog:** `tests/Integration/AutoconfigurationTest.php` (kernel-driven integration test that uses `static::$kernel->getContainer()` and asserts container shape) — closest in shape.

**No HTTP-rendering integration test exists in the bundle yet.** This test is the first. The kernel boot pattern is reusable from `AutoconfigurationTest::setUpBeforeClass` (lines 173–186):

```php
public static function setUpBeforeClass(): void
{
    static::$singleKernel = new SingleBootstrapperKernel();
    static::$singleKernel->boot();
    static::$twoKernel = new TwoBootstrappersKernel();
    static::$twoKernel->boot();
}

public static function tearDownAfterClass(): void
{
    static::$singleKernel->shutdown();
    static::$twoKernel->shutdown();
}
```

The WDT test uses `ProfilerTestKernel` (see next file) which adds `WebProfilerBundle + TwigBundle`. Drive a request via `$kernel->handle(Request::create('/'))` and scrape the rendered HTML for substrings — `acme` (slug), `—` (null state badge), `⚠` (error state badge), `Tenancy\\Bundle\\Resolver\\HostResolver` (resolved_by FQCN).

---

### `tests/Integration/Profiler/SourceLayoutTest.php` (integration test, static-file assertion)

**Primary analog:** `tests/Integration/ContainerCompilationTest.php` for the bare `TestCase` shape (no kernel).

**Static-file assertion pattern** — no kernel boot; just `file_get_contents` + `assertStringNotContainsString`:

```php
public function testProfilerClassesAreNotReferencedInProductionServicesFile(): void
{
    $contents = file_get_contents(__DIR__.'/../../../config/services.php');
    self::assertIsString($contents);
    self::assertStringNotContainsString('TenantDataCollector', $contents);
    self::assertStringNotContainsString('TenantProfilerStash', $contents);
    self::assertStringNotContainsString('Tenancy\\Bundle\\Profiler\\', $contents);
}
```

This complements the runtime kernel-boot check (per RESEARCH Pitfall 8).

---

### `tests/Integration/Profiler/Support/ProfilerTestKernel.php` (test kernel, kernel boot)

**Primary analog:** `tests/Integration/TestKernel.php` (minimal — `FrameworkBundle + TenancyBundle`).
**Secondary analog:** `tests/Integration/Support/BootstrapperTestKernel.php` (kernel that registers an extra bundle — DoctrineBundle in that case).

**Full kernel shape** — copy from `tests/Integration/TestKernel.php` (whole file):

```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Tenancy\Bundle\TenancyBundle;
use Tenancy\Bundle\Tests\Integration\Support\ReplaceTenancyProviderPass;

class TestKernel extends Kernel
{
    public function __construct(string $environment = 'test', bool $debug = false)
    {
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new TenancyBundle(),
        ];
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new ReplaceTenancyProviderPass());
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
        return sys_get_temp_dir().'/tenancy_bundle_test_'.md5(static::class).'_'.$this->environment.'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_bundle_test_'.md5(static::class).'_'.$this->environment.'/logs';
    }
}
```

**Extra bundles** — add `WebProfilerBundle` and `TwigBundle` to `registerBundles()`:

```php
public function registerBundles(): iterable
{
    return [
        new FrameworkBundle(),
        new TwigBundle(),
        new WebProfilerBundle(),
        new TenancyBundle(),
    ];
}
```

(The pattern of adding extra bundles is verified in `tests/Integration/Support/BootstrapperTestKernel.php` lines 34–41 — adds `DoctrineBundle` on top of `FrameworkBundle + TenancyBundle`.)

**Extra extension config** — copy the multi-extension load pattern from `BootstrapperTestKernel.php` lines 54–67 (loads `framework`, then `tenancy`, then `doctrine`). For ProfilerTestKernel, also load:

```php
$container->loadFromExtension('web_profiler', [
    'toolbar' => true,
    'intercept_redirects' => false,
]);
$container->loadFromExtension('framework', [
    // ... existing keys ...
    'profiler' => ['enabled' => true, 'collect' => true],
    'router'   => ['utf8' => true, 'resource' => 'kernel::loadRoutes', 'type' => 'service'],
]);
$container->loadFromExtension('twig', [
    'default_path' => '%kernel.project_dir%/templates',
    'strict_variables' => true,
]);
```

**Debug mode** — instantiate with `new ProfilerTestKernel('test', true)` so the `kernel.debug` gate registers the profiler services.

---

## Shared Patterns

### Pattern: `strict_types=1` + namespace header

**Source:** Every `src/**/*.php` file. Concrete example from `src/EventListener/EntityManagerResetListener.php` lines 1–5:

```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\EventListener;
```

**Apply to:** All new PHP files (`src/Profiler/*.php`, `tests/Unit/Profiler/*.php`, `tests/Integration/Profiler/**/*.php`).

### Pattern: `#[AsEventListener]` over `EventSubscriberInterface`

**Source:** `src/EventListener/TenantContextOrchestrator.php` lines 18–19 (multi-attribute) and `src/EventListener/EntityManagerResetListener.php` line 11 (single attribute).

```php
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: TenantContextOrchestrator::PRIORITY)]
#[AsEventListener(event: KernelEvents::TERMINATE, method: 'onKernelTerminate')]
final class TenantContextOrchestrator
```

**Apply to:** `src/Profiler/TenantProfilerStash.php`. Bundle has ZERO `EventSubscriberInterface` implementations — using one would create a one-off inconsistency.

### Pattern: PHP-DSL DI configuration with `service()` / `param()` / `->tag(...)`

**Source:** `config/services.php` lines 1–8 (imports), 31–32 (closure shape), 57–63 (service with tag + priority).

```php
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(HostResolver::class)
        ->args([
            service('tenancy.provider')->nullOnInvalid(),
            param('tenancy.host.app_domain'),
        ])
        ->tag('tenancy.resolver', ['priority' => 30]);
};
```

**Apply to:** `config/services_dev.php`.

### Pattern: Conditional DI registration

**Source:** `config/services.php` lines 134–145 (`interface_exists(MessageBusInterface::class)`) and `src/TenancyBundle.php` lines 152, 200, 220 (multiple conditional blocks in `loadExtension()` and `build()`).

```php
if (interface_exists(MessageBusInterface::class)) {
    $services->set('tenancy.messenger.sending_middleware', TenantSendingMiddleware::class)
        ->args([service('tenancy.context')]);
    // ...
}
```

**Apply to:** `src/TenancyBundle.php` — adding the `kernel.debug` conditional `$container->import('../config/services_dev.php')`. Same idiom (`if (cond) { register services }`); only the predicate differs (`$builder->getParameter('kernel.debug') === true`).

### Pattern: Test kernel lifecycle with `setUpBeforeClass` / `tearDownAfterClass`

**Source:** `tests/Integration/ContainerCompilationTest.php` lines 15–24 and `tests/Integration/AutoconfigurationTest.php` lines 173–186.

```php
private static TestKernel $kernel;

public static function setUpBeforeClass(): void
{
    static::$kernel = new TestKernel('test', false);
    static::$kernel->boot();
}

public static function tearDownAfterClass(): void
{
    static::$kernel->shutdown();
}
```

**Apply to:** All integration tests under `tests/Integration/Profiler/` that need a booted kernel (`TenantDataCollectorCompileOutTest`, `TenantDataCollectorWdtTest`). `SourceLayoutTest` and `TenantDataCollectorSerializationTest` do NOT need a booted kernel.

### Pattern: `final class XxxTest extends TestCase` with typed fields and `setUp(): void`

**Source:** `tests/Unit/EventListener/EntityManagerResetListenerTest.php` lines 14–23. Use intersection types for mocks (`Foo&MockObject`).

```php
final class EntityManagerResetListenerTest extends TestCase
{
    private ManagerRegistry&MockObject $managerRegistry;
    private EntityManagerResetListener $listener;

    protected function setUp(): void
    {
        $this->managerRegistry = $this->createMock(ManagerRegistry::class);
        $this->listener = new EntityManagerResetListener($this->managerRegistry);
    }
}
```

**Apply to:** All unit tests in `tests/Unit/Profiler/`.

## No Analog Found (in bundle)

These artifacts are firsts for this bundle — no existing in-bundle file matches. Planner should reference vendor analogs (already cited in RESEARCH.md) instead:

| File | Role | Data Flow | Reason | Vendor Reference |
|------|------|-----------|--------|------------------|
| `src/Profiler/TenantDataCollector.php` | data-collector | request-response | No prior `AbstractDataCollector` subclass in bundle | `vendor/symfony/framework-bundle/DataCollector/AbstractDataCollector.php` + `vendor/symfony/framework-bundle/Resources/config/translation_debug.php` (tag attributes example) |
| `src/Resources/views/Collector/tenant.html.twig` | view template | render | No prior Twig template in bundle (first time `src/Resources/views/` exists) | `gh api /repos/symfony/symfony/contents/src/Symfony/Bundle/WebProfilerBundle/Resources/views/Collector/translation.html.twig` (RESEARCH lines 449–549 has the verified shape) |
| `src/Resources/views/Collector/_icon.svg.twig` | view fragment | render | No prior SVG in bundle | `gh api /repos/symfony/symfony/contents/src/Symfony/Bundle/WebProfilerBundle/Resources/views/Icon/translation.svg` (RESEARCH lines 553–559) |
| `tests/Integration/Profiler/TenantDataCollectorWdtTest.php` | functional HTTP test | request-response + HTML scrape | No prior HTTP-driving integration test in bundle | RESEARCH Validation Architecture rows under criterion A/B/C |
| `tests/Integration/Profiler/TenantDataCollectorSerializationTest.php` | serialize round-trip test | native PHP serialize | No prior serialize round-trip test in bundle | RESEARCH lines 813–836 (skeleton + `DataCollector::__serialize`/`__unserialize` rationale) |

## Metadata

**Analog search scope:**
- `src/EventListener/` (2 files — both analyzed)
- `src/Event/` (3 event classes — `TenantResolved`, `TenantBootstrapped`, `TenantContextCleared` — all analyzed)
- `src/Context/TenantContext.php` (zero-dep value holder)
- `src/Exception/` (3 exception classes — namespace prefix verified)
- `src/TenantInterface.php` (accessor shapes verified)
- `src/TenancyBundle.php` (loadExtension entry point analyzed)
- `config/services.php` (PHP-DSL DI patterns analyzed end-to-end)
- `tests/Unit/EventListener/` (both files analyzed)
- `tests/Integration/` (`TestKernel.php`, `AutoconfigurationTest.php`, `ContainerCompilationTest.php`, `Support/BootstrapperTestKernel.php`)
- `composer.json` (require-dev block analyzed)

**Files scanned:** 18 source/test/config files read end-to-end; ~6 additional files briefly inspected.

**Pattern extraction date:** 2026-05-18

**Project skills inspection:** No `.claude/skills/` or `.agents/skills/` directory present in the repo (verified by `ls`). Project conventions sourced from `/CLAUDE.md` (strict_types, PHPStan level 9, php-cs-fixer `@Symfony`, PHPUnit 11, optional Doctrine guards) and applied throughout the pattern assignments above.
