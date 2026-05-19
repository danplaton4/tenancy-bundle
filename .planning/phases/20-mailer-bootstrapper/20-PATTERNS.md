# Phase 20: Mailer Bootstrapper - Pattern Map

**Mapped:** 2026-05-19
**Files analyzed:** 18 new/modified files
**Analogs found:** 17 / 18

---

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `src/Bootstrapper/MailerBootstrapper.php` | bootstrapper | request-response | `src/Bootstrapper/DoctrineBootstrapper.php` | exact |
| `src/Mailer/TenantAwareTransportsDecorator.php` | decorator/service | request-response | `src/Cache/TenantAwareCacheAdapter.php` | role-match |
| `src/Mailer/TenantMessageDecorator.php` | event-listener | event-driven | `src/EventListener/TenantContextOrchestrator.php` | role-match |
| `src/Mailer/LruTransportCache.php` | utility | request-response | `src/Context/TenantContext.php` | partial |
| `src/Mailer/SanitizingMailerDecorator.php` | decorator | request-response | `src/Cache/TenantAwareCacheAdapter.php` | role-match |
| `src/Mailer/TenantMailerConfigTrait.php` | trait/utility | — | `src/Entity/Tenant.php` (property pattern) | partial |
| `src/DependencyInjection/Compiler/MailerTransportContractPass.php` | compiler-pass | — | `src/DependencyInjection/Compiler/MessengerMiddlewarePass.php` | exact |
| `src/Exception/TenantSanitizedTransportException.php` | exception | — | `src/Exception/TenantNotFoundException.php` | exact |
| `src/Profiler/TenantDataCollector.php` | data-collector | request-response | self (MODIFIED) | self |
| `src/Resources/views/Collector/tenant.html.twig` | template | — | self (MODIFIED) | self |
| `src/Command/TenancyInstallCommand.php` | command | — | self (MODIFIED) | self |
| `src/Command/Install/Step/MailerSetupStep.php` | command-step | — | `src/Command/Install/BundlesPhpInstaller.php` | role-match |
| `src/TenantInterface.php` | interface | — | self (MODIFIED) | self |
| `src/Entity/Tenant.php` | entity | CRUD | self (MODIFIED) | self |
| `src/TenancyBundle.php` | bundle | — | self (MODIFIED) | self |
| `tests/Unit/Mailer/MailerBootstrapperTest.php` | test | — | `tests/Unit/Bootstrapper/DoctrineBootstrapperTest.php` | exact |
| `tests/Unit/DependencyInjection/Compiler/MailerTransportContractPassTest.php` | test | — | `tests/Unit/DependencyInjection/Compiler/MessengerMiddlewarePassTest.php` | exact |
| `tests/Integration/Mailer/AsyncCanaryTest.php` | integration-test | event-driven | `tests/Integration/Messenger/MessengerMiddlewareIntegrationTest.php` | role-match |

---

## Pattern Assignments

### `src/Bootstrapper/MailerBootstrapper.php` (bootstrapper, request-response)

**Analog:** `src/Bootstrapper/DoctrineBootstrapper.php`

**Imports pattern** (lines 1-10):
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Bootstrapper;

use Tenancy\Bundle\TenantInterface;
```

**Class structure** (full file — 26 lines):
```php
final class DoctrineBootstrapper implements TenantBootstrapperInterface
{
    public function __construct(
        private readonly ?EntityManagerInterface $em,
    ) {
    }

    public function boot(TenantInterface $tenant): void
    {
        $this->em?->clear();
    }

    public function clear(): void
    {
        $this->em?->clear();
    }
}
```

**Key patterns to copy:**
- `final class` + `implements TenantBootstrapperInterface` — no exceptions
- `private readonly` constructor injection for optional deps (use `?TransportInterface` or the LRU cache as a nullable arg)
- `boot()` receives `TenantInterface $tenant` — do not store the tenant, just react
- `clear()` is symmetric; for MailerBootstrapper `boot()` and `clear()` are both no-ops per RESEARCH.md A3 (transport resolution is deferred to the decorator)
- `declare(strict_types=1)` on every file — non-negotiable

**DI tag pattern** (from `config/services.php` lines 91-94):
```php
$services->set('tenancy.doctrine_bootstrapper', DoctrineBootstrapper::class)
    ->args([service('doctrine.orm.entity_manager')->nullOnInvalid()])
    ->tag('tenancy.bootstrapper', ['priority' => -10]);
```
MailerBootstrapper must be tagged with `tenancy.bootstrapper` at **priority lower than -10** (e.g. `-20`) so it runs after DoctrineBootstrapper. `BootstrapperChain::clear()` iterates `array_reverse($this->bootstrappers)` (line 39 of BootstrapperChain.php), so lower-priority bootstrap = earlier `clear()` — consistent with D-07 requirement.

---

### `src/Mailer/TenantAwareTransportsDecorator.php` (decorator, request-response)

**Analog:** `src/Cache/TenantAwareCacheAdapter.php` (decorator pattern) + `config/services.php` `->decorate()` wiring

**DI decoration pattern** (from `config/services.php` lines 96-103):
```php
$services->set('tenancy.cache_adapter', TenantAwareCacheAdapter::class)
    ->decorate('cache.app')
    ->args([
        service('.inner'),
        service('tenancy.context'),
        param('tenancy.cache_prefix_separator'),
    ]);
```

**Decoration pattern for mailer.transports** (from RESEARCH.md):
```php
$services->set('tenancy.mailer.transports_decorator', TenantAwareTransportsDecorator::class)
    ->decorate('mailer.transports')
    ->args([
        service('.inner'),                              // inner Transports registry
        service('tenancy.provider')->nullOnInvalid(),   // TenantProviderInterface
        service('tenancy.mailer.lru_cache'),            // LruTransportCache
        service('tenancy.context'),                     // TenantContext
    ]);
```

**Class-level pattern to copy:**
- `final class` + `implements TransportInterface` (NOT extends Transports — it is `final` in Symfony)
- `private readonly` for all 4 constructor args
- Guard check: `if (!$message instanceof Message || !$message->getHeaders()->has('X-Transport'))` → delegate to `$this->inner->send()`
- Only intercept when `str_starts_with($transportName, 'tenant_')` — all other names pass through
- LRU-miss path: call `TenantProviderInterface::findBySlug()`, build transport, cache it
- Strip `X-Transport` header after routing (matches Symfony's own `Transports::send()` behaviour)
- `interface_exists(\Symfony\Component\Mailer\MailerInterface::class)` guard in services.php before registering (matches Messenger guard at lines 134-145 of services.php)

---

### `src/Mailer/TenantMessageDecorator.php` (event-listener, event-driven)

**Analog:** `src/EventListener/TenantContextOrchestrator.php` (event subscriber pattern)

**Event subscriber pattern** (from TenantContextOrchestrator — autoconfigure picks up `EventSubscriberInterface`):
```php
// From config/services.php lines 81-88:
$services->set(TenantContextOrchestrator::class)
    ->autoconfigure(true)
    ->args([...]);
```

**Subscriber registration pattern** (from RESEARCH.md — verified against Symfony source):
```php
public static function getSubscribedEvents(): array
{
    // Priority 100 — run BEFORE Symfony's own MessengerTransportListener (priority 0)
    return [MessageEvent::class => ['onMessage', 100]];
}
```

**Listener body pattern:**
- Accept `TenantContext` as only constructor arg — zero additional deps
- Early-return when `$this->context->getTenant()` is null — never stamp without tenant
- Early-return when `!$message instanceof Message` — guard for RawMessage subclass
- Use `$message->getHeaders()->addTextHeader('X-Transport', 'tenant_'.$tenant->getSlug())` only when header is absent (`!$headers->has('X-Transport')`)
- Listen on `MessageEvent` with `isQueued() === false` (transport-level, not pre-dispatch) per RESEARCH.md Finding 2
- DI registration: `->autoconfigure(true)` — autoconfigure picks up `EventSubscriberInterface`

---

### `src/Mailer/LruTransportCache.php` (utility, in-memory)

**Analog:** `src/Context/TenantContext.php` (stateful value holder, no Symfony deps)

**Value-holder pattern** (TenantContext is zero-dep, holds state, has clear()):
```php
// TenantContext pattern: final class, no interface, private state, clear() method
final class TenantContext
{
    private ?TenantInterface $tenant = null;

    public function getTenant(): ?TenantInterface { ... }
    public function setTenant(TenantInterface $tenant): void { ... }
    public function clear(): void { $this->tenant = null; }
    public function hasTenant(): bool { ... }
}
```

**LruTransportCache adapts this pattern:**
- `final class`, no interface, `private array $cache = []` as state
- Constructor takes `int $maxSize = 32` — the only arg (no Symfony deps)
- `get(string $slug): ?TransportInterface` — promotes accessed key to end of array
- `set(string $slug, TransportInterface $transport): void` — evicts LRU (first key via `array_key_first()`) if at capacity, calls `stopTransport()`
- `clear(): void` — iterates all and calls `stopTransport()`, then `$this->cache = []`
- `private function stopTransport(TransportInterface $t): void` — `if (method_exists($t, 'stop')) { $t->stop(); }`
- Event listener for `TenantContextCleared` calls `$this->lruCache->clear()` — either in `MailerBootstrapper::clear()` or via a dedicated event subscriber

**DI registration** (no tag needed — injected as service arg into decorator):
```php
$services->set('tenancy.mailer.lru_cache', LruTransportCache::class)
    ->args([param('tenancy.mailer.transport_cache_size')]);
```

---

### `src/Mailer/SanitizingMailerDecorator.php` (decorator, request-response)

**Analog:** `src/Cache/TenantAwareCacheAdapter.php` (decorator → delegates to inner, wraps exceptions)

**Decorator pattern** — copy the `.decorate('mailer')` + `.inner` wiring from cache adapter:
```php
// From config/services.php lines 96-103 (structural template):
$services->set('tenancy.mailer.sanitizing_decorator', SanitizingMailerDecorator::class)
    ->decorate('mailer')
    ->args([service('.inner')]);   // single inner dep only
```

**Class structure to follow:**
- `final class SanitizingMailerDecorator implements MailerInterface`
- `private readonly MailerInterface $inner` — single constructor arg
- `send(RawMessage $message, ?Envelope $envelope = null): void` — no return (MailerInterface contract)
- Wrap in `try { $this->inner->send($message, $envelope); } catch (TransportExceptionInterface $e) { ... }`
- Regex redaction: `preg_replace('/(:[\/]{0,2}[^:]+:)[^@]+(@)/', '$1***$2', $e->getMessage())`
- Throw `new TenantSanitizedTransportException($sanitized ?? $e->getMessage(), $e->getCode(), $e)`
- `interface_exists(\Symfony\Component\Mailer\MailerInterface::class)` guard before registering in services.php

---

### `src/Mailer/TenantMailerConfigTrait.php` (trait, utility)

**Analog:** `src/Entity/Tenant.php` (property + getter/setter pattern)

**Property and getter pattern from Tenant entity** (lines 19-22, 57-61):
```php
#[ORM\Column(type: 'string', length: 253, nullable: true)]
private ?string $domain = null;

public function getDomain(): ?string
{
    return $this->domain;
}
```

**Trait pattern to copy:**
```php
// Three nullable columns, three getters, matching TenantInterface methods
trait TenantMailerConfigTrait
{
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $mailerDsn = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $mailerFrom = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $mailerReplyTo = null;

    public function getMailerDsn(): ?string { return $this->mailerDsn; }
    public function getMailerFrom(): ?string { return $this->mailerFrom; }
    public function getMailerReplyTo(): ?string { return $this->mailerReplyTo; }

    public function setMailerDsn(?string $dsn): static { $this->mailerDsn = $dsn; return $this; }
    public function setMailerFrom(?string $from): static { $this->mailerFrom = $from; return $this; }
    public function setMailerReplyTo(?string $replyTo): static { $this->mailerReplyTo = $replyTo; return $this; }
}
```
- `declare(strict_types=1)` at top — matches every other file
- No `final` on traits (PHP does not allow it)
- Setters return `static` (not `self`) for inheritance compatibility — matches Tenant entity pattern (lines 83-110)

---

### `src/DependencyInjection/Compiler/MailerTransportContractPass.php` (compiler-pass)

**Analog:** `src/DependencyInjection/Compiler/MessengerMiddlewarePass.php` (exact role match)

**Imports pattern** (lines 1-9 of MessengerMiddlewarePass.php):
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
```

**interface_exists guard pattern** (lines 26-29):
```php
public function process(ContainerBuilder $container): void
{
    if (!interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
        return;
    }
    // ... rest of pass
}
```
For MailerTransportContractPass: substitute `\Symfony\Component\Mailer\MailerInterface::class`.

**\LogicException error message pattern** (from CacheDecoratorContractPass.php line 63):
```php
throw new \LogicException(sprintf(
    'Cache decorator "%s" must implement every Symfony interface exposed by "%s". Missing: %s',
    $decoratorClass, $decoratedClass, implode(', ', $missing)
));
```
Copy this `\LogicException(sprintf(...))` shape for the mailer error messages.

**Registration in TenancyBundle::build()** (lines 217-228 of TenancyBundle.php):
```php
public function build(ContainerBuilder $container): void
{
    parent::build($container);
    $container->addCompilerPass(new BootstrapperChainPass());
    $container->addCompilerPass(new ResolverChainPass());
    $container->addCompilerPass(new CacheDecoratorContractPass());
    $container->addCompilerPass(new OriginHeaderResolverConfigPass());
    if (interface_exists(MessageBusInterface::class)) {
        $container->addCompilerPass(new MessengerMiddlewarePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1);
    }
}
```
Add `MailerTransportContractPass` here with `interface_exists(\Symfony\Component\Mailer\MailerInterface::class)` guard. Run at default priority (TYPE_BEFORE_OPTIMIZATION, 0) — it only validates, not modifies.

**getExtensionConfig pattern for async detection** (from RESEARCH.md Pattern 4):
```php
private function detectAsyncRouting(ContainerBuilder $container): bool
{
    $sendEmailClass = \Symfony\Component\Mailer\Messenger\SendEmailMessage::class;
    foreach ($container->getExtensionConfig('framework') as $config) {
        $routing = $config['messenger']['routing'] ?? [];
        if (isset($routing[$sendEmailClass])) {
            return true;
        }
    }
    return false;
}
```

---

### `src/Exception/TenantSanitizedTransportException.php` (exception)

**Analog:** `src/Exception/TenantNotFoundException.php` (exact role match)

**Exception pattern** (full file — 38 lines):
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Exception;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class TenantNotFoundException extends \RuntimeException implements HttpExceptionInterface
{
    public function __construct(string $message = 'Tenant not found.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
    // ...
}
```

**TenantSanitizedTransportException diverges on:**
- Extends `\Symfony\Component\Mailer\Exception\TransportException` (NOT `\RuntimeException`) — preserves the exception type contract for callers catching `TransportException`
- Does NOT implement `HttpExceptionInterface` (not an HTTP error)
- Constructor: `__construct(string $message, int $code = 0, ?\Throwable $previous = null)` — matches `TransportException` parent signature
- `final class` — matches every other exception in `src/Exception/`
- Lives in `src/Exception/` namespace: `namespace Tenancy\Bundle\Exception;`

---

### `src/Profiler/TenantDataCollector.php` (MODIFIED — data-collector)

**Pattern:** Extend the existing `collect()` method and `$this->data` array.

**Existing data array shape** (lines 69-78):
```php
$this->data = [
    'state' => $state,
    'slug' => $tenant?->getSlug(),
    'tenant_label' => $tenant?->getName(),
    'driver' => $this->driver,
    'connection_name' => $connectionName,
    'resolved_by' => $this->stash->getResolvedBy(),
    'bootstrappers' => array_values(array_map('strval', $this->stash->getBootstrapperFqcns())),
    'error' => $this->stash->getCapturedException(),
];
```

**Key constraints from existing code:**
- `$this->data` must remain scalar-only — no objects (enforced by TenantDataCollectorTest `testDataContainsOnlyScalarsAndStringArrays`)
- New `mailer` key must be a scalar-only nested array: `['from' => ?string, 'reply_to' => ?string, 'dsn_redacted' => ?string, 'cache_size' => int, 'cache_max' => int, 'cache_hits' => int, 'cache_evictions' => int, 'strategy' => string, 'async_detected' => bool, 'badge' => string]`
- DSN redaction: apply same regex as `SanitizingMailerDecorator` — `preg_replace('/(:[\/]{0,2}[^:]+:)[^@]+(@)/', '$1***$2', $dsn)` — NEVER store raw DSN in `$this->data`
- Add `collectMailerState(): array` private method, call from `collect()`, merge result into `$this->data`
- Constructor gains `LruTransportCache` and `string $mailerAsync` args — follow existing `private readonly string $driver` pattern (lines 35-43)
- `getData()` assertion on line 104 (`assert(is_array($this->data))`) remains valid — do not break it

**DSN security tripwire pattern** (lines 64-67):
```php
// Copy this defensive pattern for DSN in the mailer section:
if (null !== $connectionName && (str_contains($connectionName, ':') || str_contains($connectionName, '@'))) {
    throw new \RuntimeException(sprintf('TenantDataCollector: connection_name "%s" looks like a DSN ...', $connectionName));
}
```

---

### `src/Resources/views/Collector/tenant.html.twig` (MODIFIED — template)

**Pattern:** Add a collapsible `{% block panel %}` subsection after the bootstrappers list.

**Existing panel section structure** (lines 67-117):
```twig
{% block panel %}
    <h2>Tenancy</h2>

    {% if collector.data.state == 'resolved' %}
        <div class="metrics">...</div>
        <h3>Resolved by</h3>
        <h3>Bootstrappers ({{ collector.data.bootstrappers|length }})</h3>
        ...
    {% elseif ... %}
    {% else %}
    {% endif %}
{% endblock %}
```

**Mailer subsection to add inside the `resolved` branch, after Bootstrappers:**
```twig
{% if collector.data.mailer is defined %}
    <h3>Mailer</h3>
    <div class="metrics">
        <div class="metric">
            <span class="value sf-toolbar-status sf-toolbar-status-{{
                collector.data.mailer.badge == 'OK' ? 'green' :
                (collector.data.mailer.badge == 'ERROR' ? 'red' : 'yellow')
            }}">{{ collector.data.mailer.badge }}</span>
            <span class="label">Status</span>
        </div>
        {# ... cache_size / cache_max / cache_hits metrics ... #}
    </div>
    {# mailerFrom, redacted DSN, async strategy row #}
{% endif %}
```
Keep it behind `{% if collector.data.mailer is defined %}` so the template degrades gracefully when Mailer is not installed.

---

### `src/Command/TenancyInstallCommand.php` (MODIFIED — command)

**Pattern:** Add `--with-mailer` option following the existing `--force` / `--dry-run` pattern.

**Existing option registration** (lines 46-50):
```php
protected function configure(): void
{
    $this
        ->addOption('force', null, InputOption::VALUE_NONE, '...')
        ->addOption('dry-run', null, InputOption::VALUE_NONE, '...');
}
```

**Existing option read pattern** (lines 56-57):
```php
$force = (bool) $input->getOption('force');
$dryRun = (bool) $input->getOption('dry-run');
```

**Delegation pattern to sub-step** (lines 127-145 — `delegateToTenancyInit()`):
Copy this delegation pattern for `--with-mailer`: after `tenancy:init` completes successfully, call `$this->mailerSetupStep->run($io, $input)` (injected via constructor). The sub-step is a separate class (`MailerSetupStep`) — analogous to `BundlesPhpInstaller`.

**SymfonyStyle output vocabulary** (lines 109-113):
```php
$io->success('...');
$io->note('...');
$io->warning('...');
$io->error([...]);
$io->section('...');
$io->listing([...]);
```
MailerSetupStep must use the same vocabulary — no raw `echo` or `writeln` calls.

---

### `src/Command/Install/Step/MailerSetupStep.php` (NEW — command step)

**Analog:** `src/Command/Install/BundlesPhpInstaller.php` (install sub-step pattern)

**Class structure pattern** (lines 37-55 of BundlesPhpInstaller.php):
```php
final class BundlesPhpInstaller implements BundlesPhpInstallerInterface
{
    public function __construct(
        private readonly Filesystem $filesystem = new Filesystem(),
        ?\Closure $lintRunner = null,
    ) { ... }

    public function install(string $bundlesPhpPath, bool $dryRun = false): InstallResult
    {
        if (!class_exists(ParserFactory::class)) {
            return InstallResult::devDependencyMissing();
        }
        // ...
    }
}
```

**MailerSetupStep follows this pattern:**
- `final class MailerSetupStep` — no interface needed unless shared across commands
- Constructor: `private readonly Filesystem $filesystem`, `private readonly string $projectDir`, `private readonly string $tenantEntityClass`
- `run(SymfonyStyle $io, bool $dryRun = false): int` returns `Command::SUCCESS` or `Command::FAILURE`
- `if (!class_exists(ParserFactory::class)) { $io->note('...'); return Command::SUCCESS; }` — graceful no-op when nikic/php-parser absent (same escape hatch as BundlesPhpInstaller)
- Migration scaffold: write a .php migration file using `$filesystem->dumpFile()` — same atomic write as BundlesPhpInstaller line 89
- Entity mutation: use `nikic/php-parser` to detect + insert `use TenantMailerConfigTrait;` — use `ParserFactory::createForNewestSupportedVersion()` (same as BundlesPhpInstaller line 126)
- Non-standard entity: refuse (print manual snippet, return `Command::SUCCESS`) — same refusal-is-success pattern as BundlesPhpInstaller line 66-68

---

### `src/TenantInterface.php` (MODIFIED — interface)

**Pattern:** Add 3 methods following existing method signature style (lines 8-19):
```php
interface TenantInterface
{
    public function getSlug(): string;
    public function getDomain(): ?string;
    /** @return array<string, mixed> */
    public function getConnectionConfig(): array;
    public function getName(): string;
    public function isActive(): bool;
}
```

**New methods to add:**
```php
public function getMailerDsn(): ?string;
public function getMailerFrom(): ?string;
public function getMailerReplyTo(): ?string;
```
All nullable — matches `getDomain(): ?string`. No docblock needed (scalars).

---

### `src/Entity/Tenant.php` (MODIFIED — entity)

**Pattern:** Add 3 columns + getters/setters following the `domain` pattern (lines 19-22, 83-88):
```php
#[ORM\Column(type: 'string', length: 253, nullable: true)]
private ?string $domain = null;

public function setDomain(?string $domain): self
{
    $this->domain = $domain;
    return $this;
}
```

**Decision on trait vs inline:** Planner should inline the 3 columns in `Tenant.php` (for self-documentation) AND ship `TenantMailerConfigTrait.php` for users with custom entities. The bundle's own `Tenant` entity does not use the trait — it inlines for readability. Add `use TenantMailerConfigTrait;` as a comment showing users the alternative.

---

### `src/TenancyBundle.php` (MODIFIED — bundle)

**Pattern:** `configure()` gains new `mailer` arrayNode; `loadExtension()` gains Mailer service wiring; `build()` gains compiler pass.

**Config tree pattern** (lines 36-97):
```php
->arrayNode('database')
->addDefaultsIfNotSet()
->children()
->booleanNode('enabled')->defaultFalse()->end()
->end()
->end()
```

**Mailer config to add in `configure()`:**
```php
->arrayNode('mailer')
->addDefaultsIfNotSet()
->children()
->integerNode('transport_cache_size')->defaultValue(32)->end()
->scalarNode('async')->defaultValue('auto')->end()
->end()
->end()
```

**Parameter registration pattern** (lines 121-128):
```php
$container->parameters()
    ->set('tenancy.driver', $config['driver'])
    // ...
    ->set('tenancy.mailer.transport_cache_size', $config['mailer']['transport_cache_size'])
    ->set('tenancy.mailer.async', $config['mailer']['async']);
```

**interface_exists guard in loadExtension()** (lines 157-158):
```php
if (!interface_exists(\Doctrine\DBAL\Driver\Middleware::class)) {
    throw new \LogicException('...');
}
```
Mailer wiring uses `if (interface_exists(\Symfony\Component\Mailer\MailerInterface::class))` — same pattern but does NOT throw; silently skips (D-05 always-on means skipping when package absent, not erroring).

**Messenger service block pattern** (lines 134-145 of services.php):
```php
if (interface_exists(MessageBusInterface::class)) {
    $services->set('tenancy.messenger.sending_middleware', TenantSendingMiddleware::class)
        ->args([service('tenancy.context')]);
    // ...
}
```
Mailer block follows the same `if (interface_exists(...))` wrapping in `services.php` (not in `loadExtension()` — services.php is the canonical place for service definitions).

---

### `tests/Unit/Mailer/MailerBootstrapperTest.php` (unit test)

**Analog:** `tests/Unit/Bootstrapper/DoctrineBootstrapperTest.php` (exact match)

**Test class pattern** (full file — 47 lines):
```php
final class DoctrineBootstrapperTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private DoctrineBootstrapper $bootstrapper;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->bootstrapper = new DoctrineBootstrapper($this->em);
    }

    public function testBootClearsEntityManager(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $this->em->expects($this->once())->method('clear');
        $this->bootstrapper->boot($tenant);
    }

    public function testImplementsTenantBootstrapperInterface(): void
    {
        $this->assertInstanceOf(TenantBootstrapperInterface::class, $this->bootstrapper);
    }
}
```

**Copy patterns:**
- `final class` test
- `protected function setUp(): void` for bootstrapper instantiation
- `$this->createMock()` for deps
- One test per behavior: `testBootIsNoOp()`, `testClearIsNoOp()`, `testImplementsTenantBootstrapperInterface()`
- `$tenant = $this->createMock(TenantInterface::class)` — never instantiate the real Tenant entity in unit tests

---

### `tests/Unit/DependencyInjection/Compiler/MailerTransportContractPassTest.php` (unit test)

**Analog:** `tests/Unit/DependencyInjection/Compiler/MessengerMiddlewarePassTest.php` (exact match)

**Test pattern** (lines 14-25):
```php
final class MessengerMiddlewarePassTest extends TestCase
{
    public function testSkipsWhenNoMessengerBusesRegistered(): void
    {
        $container = new ContainerBuilder();
        $pass = new MessengerMiddlewarePass();
        $pass->process($container);
        $this->assertTrue(true); // No exception — pass exits gracefully
    }
```

**markTestSkipped pattern** (lines 29-31):
```php
if (!interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
    $this->markTestSkipped('symfony/messenger not installed');
}
```
Apply same skip guard: `if (!interface_exists(\Symfony\Component\Mailer\MailerInterface::class)) { $this->markTestSkipped('symfony/mailer not installed'); }`

**ContainerBuilder setup pattern** (lines 36-44):
```php
$container = new ContainerBuilder();
$busDefinition = new Definition(\stdClass::class);
$busDefinition->addTag('messenger.bus');
$container->setDefinition('messenger.bus.default', $busDefinition);
$container->setParameter('messenger.bus.default.middleware', [...]);
```
For mailer pass test: set `tenancy.mailer.async` parameter + optionally mock `framework` extension config for async detection test case.

---

### `tests/Integration/Mailer/AsyncCanaryTest.php` (integration test)

**Analog:** `tests/Integration/Messenger/MessengerMiddlewareIntegrationTest.php` (role-match)

**Test kernel lifecycle pattern** (lines 34-53):
```php
public static function setUpBeforeClass(): void
{
    $cacheDir = sys_get_temp_dir().'/tenancy_messenger_test';
    if (is_dir($cacheDir)) { self::removeDir($cacheDir); }

    static::$kernel = new MessengerTestKernel('messenger_test', false);
    static::$kernel->boot();
}

public static function tearDownAfterClass(): void
{
    static::$kernel->shutdown();
    $cacheDir = sys_get_temp_dir().'/tenancy_messenger_test';
    if (is_dir($cacheDir)) { self::removeDir($cacheDir); }
}
```

**Context cleanup between tests** (lines 63-69):
```php
protected function setUp(): void
{
    $container = static::$kernel->getContainer();
    /** @var TenantContext $context */
    $context = $container->get('tenancy.context');
    $context->clear();
}
```

**StubTenant/StubTenantProvider pattern** (lines 44-49 — from Messenger test):
```php
$provider = $container->get('tenancy.provider');
$provider->addTenant(new StubTenant('acme'));
```
For mailer canary: `StubTenant` must also return a `mailerDsn` — extend `StubTenant` with `getMailerDsn(): ?string { return 'smtp://user:pass@localhost:1025'; }`. Use a `SpyTransport` (not a real SMTP server) registered as the `mailer.transports` for the test.

**Test kernel pattern** (MessengerTestKernel.php, lines 39-44):
```php
public function build(ContainerBuilder $container): void
{
    parent::build($container);
    $container->addCompilerPass(new MakeMessengerServicesPublicPass());
    $container->addCompilerPass(new ReplaceProviderWithStubPass());
}
```
Mailer test kernel: add `MakeMailerServicesPublicPass` (makes `tenancy.mailer.transports_decorator`, `tenancy.mailer.lru_cache` public for test inspection) and `ReplaceTransportsWithSpyPass` (replaces `mailer.transports` inner with a `SpyTransport`).

**removeDir helper** (lines 197-212) — copy verbatim to MailerTestKernel or a shared test support class.

---

## Shared Patterns

### `declare(strict_types=1)` + `final class`
**Source:** Every file in `src/` — universally applied
**Apply to:** ALL new files (MailerBootstrapper, TenantAwareTransportsDecorator, TenantMessageDecorator, LruTransportCache, SanitizingMailerDecorator, MailerTransportContractPass, TenantSanitizedTransportException, MailerSetupStep, all test files)
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Mailer;

// ...

final class MailerBootstrapper implements TenantBootstrapperInterface
```

### `interface_exists()` Guard for Optional Deps
**Source:** `src/DependencyInjection/Compiler/MessengerMiddlewarePass.php` lines 28-30; `config/services.php` lines 134-145
**Apply to:** Every place Mailer classes are referenced in DI or compiler passes
```php
// In compiler pass:
if (!interface_exists(\Symfony\Component\Mailer\MailerInterface::class)) {
    return;
}

// In services.php:
if (interface_exists(\Symfony\Component\Mailer\MailerInterface::class)) {
    $services->set('tenancy.mailer_bootstrapper', MailerBootstrapper::class)
        ->tag('tenancy.bootstrapper', ['priority' => -20]);
    // ... other mailer services
}
```

### `private readonly` Constructor Injection
**Source:** `src/Bootstrapper/DoctrineBootstrapper.php` lines 11-14; `src/Messenger/TenantWorkerMiddleware.php` lines 17-23
**Apply to:** All new `final class` implementations
```php
public function __construct(
    private readonly TenantContext $context,
    private readonly TenantProviderInterface $provider,
    private readonly LruTransportCache $lruCache,
) {
}
```

### Service Decoration via `.decorate()` + `service('.inner')`
**Source:** `config/services.php` lines 96-110
**Apply to:** `TenantAwareTransportsDecorator` (decorates `mailer.transports`), `SanitizingMailerDecorator` (decorates `mailer`)
```php
$services->set('tenancy.mailer.transports_decorator', TenantAwareTransportsDecorator::class)
    ->decorate('mailer.transports')
    ->args([service('.inner'), ...]);
```

### `\LogicException` for Compile-Time Contract Violations
**Source:** `src/DependencyInjection/Compiler/CacheDecoratorContractPass.php` line 63; `src/TenancyBundle.php` line 158
**Apply to:** `MailerTransportContractPass::process()` error paths
```php
throw new \LogicException(sprintf(
    'tenancy: Mailer is routed async but the X-Transport strategy is not configured. %s',
    'Set tenancy.mailer.async: false to disable this check.'
));
```

### `->nullOnInvalid()` for Optional Service References
**Source:** `config/services.php` lines 48, 59, 66 — used when a service may not exist
**Apply to:** `service('tenancy.provider')->nullOnInvalid()` in the TenantAwareTransportsDecorator registration — if no provider is wired (edge case), the decorator skips tenant resolution
```php
service('tenancy.provider')->nullOnInvalid(),
```

### SymfonyStyle Output Vocabulary
**Source:** `src/Command/TenancyInstallCommand.php` lines 54-56, 109-116, 161-170
**Apply to:** `MailerSetupStep::run()` — all user-facing output
```php
$io->title('...');
$io->success('...');
$io->note('...');
$io->warning('...');
$io->error([...]);
$io->section('...');
$io->listing([...]);
$io->text('...');
```

### `setUpBeforeClass`/`tearDownAfterClass` Kernel Lifecycle in Integration Tests
**Source:** `tests/Integration/Messenger/MessengerMiddlewareIntegrationTest.php` lines 34-68
**Apply to:** `tests/Integration/Mailer/AsyncCanaryTest.php` and `tests/Integration/Mailer/MailerTestKernel.php`

### DSN Credential Redaction (shared helper)
**Source:** New — extracted from SanitizingMailerDecorator for reuse in TenantDataCollector
**Apply to:** `SanitizingMailerDecorator::send()` catch block AND `TenantDataCollector::collectMailerState()`
```php
// Candidate for a standalone static helper or a trait — planner to decide:
private function redactDsn(?string $dsn): ?string
{
    if (null === $dsn) {
        return null;
    }
    return preg_replace('/(:[\/]{0,2}[^:]+:)[^@]+(@)/', '$1***$2', $dsn) ?? $dsn;
}
```
Planner decision: either inline this in both classes or extract to `src/Mailer/DsnSanitizer.php` (a `final class` with a single `static function redact(string $dsn): string`).

---

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `src/Mailer/LruTransportCache.php` | utility | in-process cache | No LRU cache exists in the codebase; closest is TenantContext (stateful holder) but it has no eviction semantics. Implement from scratch using PHP ordered-array pattern per RESEARCH.md Pattern 3. |

---

## Metadata

**Analog search scope:** `src/Bootstrapper/`, `src/DependencyInjection/Compiler/`, `src/Cache/`, `src/Command/`, `src/Exception/`, `src/Profiler/`, `src/Messenger/`, `config/`, `tests/Unit/Bootstrapper/`, `tests/Unit/DependencyInjection/Compiler/`, `tests/Integration/Messenger/`
**Files scanned:** 28 source files + 15 test files read in full
**Pattern extraction date:** 2026-05-19
