# Phase 19: Profiler Tab — Research

**Researched:** 2026-05-18
**Domain:** Symfony 7.x Web Profiler / DataCollector integration for a Symfony bundle
**Confidence:** HIGH (every external API claim verified against vendor/ source and GitHub `symfony/symfony` HEAD; every "the bundle already does X" claim verified by reading the actual file)

## Summary

Phase 19 delivers a dev-only Symfony Web Debug Toolbar "Tenancy" panel. CONTEXT.md (D-01..D-13) locks every architectural decision; this research validates those decisions against the actual Symfony 7.x APIs and the actual bundle source code, surfaces two contradictions the planner must resolve, and produces a Nyquist validation map covering all 5 ROADMAP success criteria.

**Two contradictions found between CONTEXT.md and actual code/APIs:**

1. **D-06 is technically infeasible as written.** D-06 says "Use a runtime `if ($container->getParameter('kernel.debug')) { ... }` block in `config/services.php`." But `config/services.php` receives a `ContainerConfigurator`, NOT a `ContainerBuilder` (see `vendor/symfony/dependency-injection/Loader/Configurator/ContainerConfigurator.php` line 30 — it has `parameters()`, `services()`, `import()`, `env()` but NO `getParameter()`). The `interface_exists(MessageBusInterface::class)` pattern works because `interface_exists()` is pure PHP — `kernel.debug` is a container parameter only readable from a `ContainerBuilder`. **Resolution:** Implement the guard in `TenancyBundle::loadExtension()` (which has `$builder: ContainerBuilder`) by conditionally importing a sibling file `config/services_dev.php`. This preserves D-06's intent (single source of truth, no compiler pass, matches the bundle's "conditional registration in config/services" idiom) while being implementable.

2. **CONTEXT.md `<code_context>` line 177 says "stash auto-registers via `kernel.event_subscriber` tag (autoconfigure)"** but every event listener in `src/EventListener/` and `src/Resolver/ConsoleResolver.php` uses **`#[AsEventListener]` attributes**, not `EventSubscriberInterface`. The bundle has zero `EventSubscriberInterface` implementations. **Resolution:** Use `#[AsEventListener]` per-method attributes on `TenantProfilerStash` to match the established bundle idiom (D-CLAUDE-DISCRETION area — "Internal class layout").

**`tenancy.database.connection_name` parameter does NOT exist** (D-09's open question). Doctrine connection names in the bundle are hardcoded by convention: `'tenant'` (DBAL wrapped connection) and `'landlord'` (or the value of `%tenancy.landlord_connection%`, default `'default'`). The collector should hardcode `'tenant'` for the database-per-tenant driver and read `%tenancy.landlord_connection%` for shared_db.

**Primary recommendation:** Implement per D-01..D-13, with two tactical adjustments above. WebProfilerBundle `^7.4||^8.0` to `require-dev`. AbstractDataCollector is auto-tagged with `data_collector` by FrameworkExtension; `ResetInterface` is auto-tagged `kernel.reset`. `$this->data` containing only scalars/string-arrays survives serialize/unserialize losslessly because `DataCollector::__serialize/__unserialize` round-trips only that one property.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Event-time capture (resolver FQCN, bootstrapper FQCNs, tenancy exceptions) | Stash service (event listener) | — | Per CONTEXT D-01: separates event-time capture from collect-time read. Lives in `Tenancy\Bundle\Profiler\TenantProfilerStash`. |
| Collect-time read (build scalar `$this->data` on `kernel.response`) | DataCollector | TenantContext (reads `getSlug()`, `getName()`) | `AbstractDataCollector::collect()` runs synchronously on `kernel.response`. Per DX-02: `collect()` NOT `lateCollect()`. |
| Tier compile-out (collector absent in prod container) | Bundle extension (`TenancyBundle::loadExtension()`) | — | The `if ($builder->getParameter('kernel.debug'))` runtime gate sits at the extension layer, NOT in a compiler pass. Sole entry point that registers the dev services. |
| Twig rendering of three states (resolved/null/error) | Twig template (`@Tenancy/Collector/tenant.html.twig`) | WebProfilerBundle layout (`@WebProfiler/Profiler/layout.html.twig`) | All UI logic in the template; collector returns scalar data only. |
| Bundle re-resource auto-discovery (Twig namespace, template path) | `AbstractBundle` (Symfony 7.x default) | — | `src/Resources/views/Collector/tenant.html.twig` is auto-exposed as `@Tenancy/Collector/tenant.html.twig` because `TenancyBundle extends AbstractBundle` (`src/TenancyBundle.php` line 33). |
| Long-running runtime safety (FrankenPHP, Swoole, RoadRunner) | Stash via `ResetInterface` | Symfony `ServicesResetter` | `ResetInterface` is autoconfigured with `kernel.reset` tag by FrameworkExtension; HttpKernel calls `services_resetter::reset()` between requests. |

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01** — Introduce `Tenancy\Bundle\Profiler\TenantProfilerStash` as a per-request stateful service subscribing to `TenantResolved` (captures `resolvedBy` FQCN), `TenantBootstrapped` (captures `bootstrappers` FQCN list), `TenantContextCleared` (calls `$this->reset()`), and `ExceptionEvent`/`kernel.exception` (captures `['class' => $e::class, 'message' => $e->getMessage()]` IFF the exception class begins with `Tenancy\Bundle\Exception\`). Exposes scalar getters: `getResolvedBy(): ?string`, `getBootstrapperFqcns(): array`, `getCapturedException(): ?array`. Implements `Symfony\Contracts\Service\ResetInterface`.
- **D-02** — Rationale: Symfony's `DataCollector` lifecycle calls `collect()` once on `kernel.response`; by then the events have already fired. A separate stash keeps capture (event-time) and read (collect-time) cleanly separated, makes both halves unit-testable in isolation, and keeps `TenantContext` zero-dep.
- **D-03** — Stash records exceptions ONLY when `$e::class` starts with `Tenancy\Bundle\Exception\`. Domain exceptions from the application MUST NOT flip the panel into the error state. Stored fields: `class` (FQCN string) and `message` (string) — no stack trace, no `previous`, no context arrays.
- **D-04** — State classification computed in `collect()`:
  - `state = 'resolved'` if `$tenantContext->hasTenant()`
  - `state = 'error'` else if `$stash->getCapturedException() !== null`
  - `state = 'null'` otherwise (by-design happy-path for public/landlord/health-check routes)
- **D-05** — Single `tenant.html.twig` with three `{% if data.state == ... %}` branches. WDT badge: tenant slug for resolved; literal `—` for null; literal `⚠` for error. One inline SVG icon. No JavaScript, no external assets, no CSS imports.
- **D-06** — Runtime `if ($container->getParameter('kernel.debug')) { ... }` block in `config/services.php` matching the existing `interface_exists(MessageBusInterface::class)` idiomatic style. Inside: register `tenancy.profiler.stash`, `tenancy.profiler.data_collector` with appropriate tags. No dedicated compiler pass. **[CONTRADICTION — see Summary §1; planner must resolve.]**
- **D-07** — CI compile-out test `tests/Integration/Profiler/TenantDataCollectorCompileOutTest.php` boots two minimal test kernels:
  - Kernel A: `debug=true, env=test` → asserts `$container->has(TenantDataCollector::class)` is `true`
  - Kernel B: `debug=false, env=prod` → asserts `$container->has(TenantDataCollector::class)` is `false` AND `$container->has(TenantProfilerStash::class)` is `false`
- **D-08** — `$this->data` is exactly the 8-key shape: `state`, `slug`, `tenant_label`, `driver`, `connection_name`, `resolved_by`, `bootstrappers`, `error`. No request URL, no host header, no headers-tried list, no timing.
- **D-09** — `connection_name` resolution: never leak DSN credentials. Collector reads `%tenancy.driver%` and `%tenancy.landlord_connection%`. For `database_per_tenant`: display the tenant connection name (research must confirm — see Summary; the answer is hardcoded `'tenant'`). For `shared_db`: display `%tenancy.landlord_connection%`. Hard rule: displayed value is a connection NAME string. Defensive sanitization: if the captured string contains `:` or `@`, log a `RuntimeException` in dev.
- **D-10** — WDT badge: text-only `Tenant: {slug}` for resolved, `Tenant: —` for null, `Tenant: ⚠` for error. Single inline SVG icon (~24×24).
- **D-11** — Scalar-only `$this->data`. NO `TenantInterface` instances, NO closures, NO DBAL `Connection`, NO `Doctrine\ORM\EntityManagerInterface`, NO `Throwable` objects. Defensive normalization: `array_values(array_map('strval', $stash->getBootstrapperFqcns()))`.
- **D-12** — Twig template path: `src/Resources/views/Collector/tenant.html.twig`. `@Tenancy` Twig namespace is auto-registered by `AbstractBundle`; `getTemplate()` returns `'@Tenancy/Collector/tenant.html.twig'`. Single file plus an inline icon helper `_icon.svg.twig`.
- **D-13** — Test inventory: (1) `tests/Unit/Profiler/TenantProfilerStashTest.php`, (2) `tests/Unit/Profiler/TenantDataCollectorTest.php`, (3) `tests/Integration/Profiler/TenantDataCollectorSerializationTest.php`, (4) `tests/Integration/Profiler/TenantDataCollectorCompileOutTest.php`, (5) `tests/Integration/Profiler/TenantDataCollectorWdtTest.php`.

### Claude's Discretion

- Internal class layout of `TenantProfilerStash` (private fields vs single `?array $captured` blob).
- Exact wording inside the Twig template (panel headings, table labels) — cross-reference Symfony bundled data collectors for tone consistency.
- Whether to render the bootstrapper list as a `<ul>` or a `<table>`.
- Icon SVG path data — any simple ~24px chain/link/key glyph.

### Deferred Ideas (OUT OF SCOPE)

- Resolution-time / bootstrap-time perf metrics (`Stopwatch` integration).
- Per-resolver attempt log.
- Tenant-scoped cache hit/miss counters.
- `tenancy:debug` CLI mirror.
- Multi-tenant-per-request (sub-requests with different tenants).
- Production observability hook (StatsD/OpenTelemetry export).
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| DX-02 | "Symfony Profiler ships a 'Tenancy' panel in the Web Debug Toolbar showing the active tenant for the current request — slug, ID, driver, connection name, resolved-by FQCN, bootstrappers run. Panel renders cleanly in three states." | `AbstractDataCollector` contract verified in `vendor/symfony/framework-bundle/DataCollector/AbstractDataCollector.php` lines 19–30 and `vendor/symfony/http-kernel/DataCollector/DataCollector.php` lines 29–101. `TenantResolved::$resolvedBy` (string FQCN) verified at `src/Event/TenantResolved.php` line 15. `TenantBootstrapped::$bootstrappers` (string[]) verified at `src/Event/TenantBootstrapped.php` line 16. `TenantContextOrchestrator::onKernelRequest()` null-resolution early-return verified at `src/EventListener/TenantContextOrchestrator.php` lines 41–45. Compile-out enforceability via `TenancyBundle::loadExtension()`'s access to `$builder: ContainerBuilder` and `$builder->getParameter('kernel.debug')` verified at FrameworkExtension's own pattern (`vendor/symfony/framework-bundle/DependencyInjection/FrameworkExtension.php` lines 797, 996, 1209, 1779). Stored-profile round-trip safety verified at `vendor/symfony/http-kernel/Profiler/FileProfilerStorage.php` lines 164 (`serialize`) + 309 (`unserialize`) — native PHP serialization via `DataCollector::__serialize` which round-trips only `$this->data` (`vendor/symfony/http-kernel/DataCollector/DataCollector.php` lines 87–95). |

</phase_requirements>

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `symfony/http-kernel` | `^7.4\|\|^8.0` (already in `composer.json` require) | Provides `DataCollectorInterface`, `Profiler`, `FileProfilerStorage`, `KernelEvents`, `ExceptionEvent`. | Already a hard dep of the bundle. |
| `symfony/framework-bundle` | `^7.4\|\|^8.0` (already in `composer.json` require-dev) | Provides `AbstractDataCollector`, `TemplateAwareDataCollectorInterface`, `ProfilerPass`, autoconfiguration of `DataCollectorInterface` → `data_collector` tag (FrameworkExtension line 653–654). | Already in require-dev. |
| `symfony/web-profiler-bundle` | `^7.4\|\|^8.0` (NEW require-dev) | Provides the toolbar/profiler UI, `@WebProfiler/Profiler/layout.html.twig`, `@WebProfiler/Profiler/toolbar_item.html.twig`. | Required only for the WDT rendering test. End-user apps already depend on it in their own require-dev via Symfony Flex. **[VERIFIED: GitHub `symfony/web-profiler-bundle` releases — stable lines `v7.4.11`, `v8.0.11` (2026 cadence matches main symfony repo).]** |
| `symfony/event-dispatcher` | `^7.4\|\|^8.0` (already in require) | `#[AsEventListener]` attribute (used by every existing listener in `src/EventListener/`). | Already a hard dep. |
| `symfony/twig-bundle` | (transitive via `symfony/web-profiler-bundle`) | Renders the panel template. Not a direct require — pulled in by WebProfilerBundle dev install. | — |

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `symfony/service-contracts` | (already transitive) | `ResetInterface` autoconfigured by `kernel.reset` tag — see FrameworkExtension lines 671–672. | Stash implements this for FrankenPHP/Swoole/RoadRunner safety. |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Standalone `TenantProfilerStash` service (D-01) | Collector itself implements `EventSubscriberInterface` | CONTEXT D-02 rejected — tangles event-time + collect-time lifecycles in one class; harder to unit-test in isolation. |
| `if ($builder->getParameter('kernel.debug'))` guard in `loadExtension()` | Dedicated compiler pass | CONTEXT D-06 rejected the compiler-pass approach as over-engineered for a one-line check. |
| `#[AsEventListener]` attribute style | `EventSubscriberInterface::getSubscribedEvents()` | Either works (both result in `kernel.event_listener` registrations). The bundle's idiom is `#[AsEventListener]` (verified — every listener in `src/EventListener/`). Match it. |
| Loading `services_dev.php` conditionally | `when@dev` YAML overlay | The bundle uses PHP DI exclusively; YAML overlay would introduce a new config format just for this. |

**Installation:**

```bash
composer require --dev symfony/web-profiler-bundle:^7.4
```

The version constraint must match the bundle's existing Symfony matrix (`^7.4||^8.0`). Add to `composer.json` `require-dev`:

```json
"symfony/web-profiler-bundle": "^7.4||^8.0",
"symfony/twig-bundle": "^7.4||^8.0"
```

**Version verification:**

```bash
gh api /repos/symfony/web-profiler-bundle/releases --jq '.[].tag_name' | head -5
# v8.1.0-BETA2  v8.0.11  v7.4.11  v6.4.39  v8.1.0-BETA1
```

Stable 7.x = v7.4.11, stable 8.x = v8.0.11 (as of 2026-05-18). `[VERIFIED: GitHub releases API]`

## Architecture Patterns

### System Architecture Diagram

```
                  ┌──────────────────────────────────────────┐
                  │ HTTP Request (kernel.debug = true)       │
                  └────────────────────┬─────────────────────┘
                                       │
                                       ▼
   priority 20 ┌─────────────────────────────────────────────┐
   kernel.req  │ TenantContextOrchestrator::onKernelRequest()│
               │  ├─ ResolverChain::resolve() → ?Resolution  │
               │  │                                          │
               │  ├─ if null → return  (NULL-RESOLUTION)     │
               │  │                                          │
               │  ├─ else:                                   │
               │  │   ├─ TenantContext::setTenant()          │
               │  │   ├─ BootstrapperChain::boot()           │
               │  │   │   └─ dispatch TenantBootstrapped     │
               │  │   │       (carries string[] FQCNs)       │
               │  │   └─ dispatch TenantResolved             │
               │  │       (carries string FQCN resolvedBy)   │
               │  │                                          │
               │  └─ (if exception in Tenancy\Bundle\Exception\)
               │      kernel.exception fires next            │
               └─────────────────────┬───────────────────────┘
                                     │
       ┌─────────────────────────────┴─────────────────────────────┐
       │                  EVENT SUBSCRIBERS                         │
       │                                                            │
       │   ┌─────────────────────────────────────────────────────┐  │
       │   │ TenantProfilerStash                                 │  │
       │   │  #[AsEventListener(TenantResolved::class)]          │  │
       │   │  → $resolvedBy: ?string                             │  │
       │   │  #[AsEventListener(TenantBootstrapped::class)]      │  │
       │   │  → $bootstrappers: string[]                         │  │
       │   │  #[AsEventListener(TenantContextCleared::class)]    │  │
       │   │  → reset()                                          │  │
       │   │  #[AsEventListener(ExceptionEvent::class)]          │  │
       │   │  → if str_starts_with($e::class,                    │  │
       │   │       'Tenancy\\Bundle\\Exception\\')               │  │
       │   │     $captured = ['class'=>..,'message'=>..]         │  │
       │   │                                                     │  │
       │   │  implements ResetInterface                          │  │
       │   │  (Symfony auto-tags kernel.reset; HttpKernel        │  │
       │   │   calls services_resetter::reset() between          │  │
       │   │   requests in long-running runtimes)                │  │
       │   └─────────────────────────────────────────────────────┘  │
       └──────────────────────────────┬─────────────────────────────┘
                                      │
                                      ▼
      kernel.response  ┌────────────────────────────────────────┐
                       │ TenantDataCollector extends            │
                       │ AbstractDataCollector                  │
                       │                                        │
                       │ collect(Request, Response, ?Throwable) │
                       │  → state = TenantContext::hasTenant()  │
                       │       ? 'resolved'                     │
                       │       : ($stash->captured ? 'error'    │
                       │                            : 'null')   │
                       │                                        │
                       │  → $this->data = [                     │
                       │       state, slug, tenant_label,       │
                       │       driver, connection_name,         │
                       │       resolved_by, bootstrappers,      │
                       │       error                            │
                       │    ]   // scalars + string[] only      │
                       │                                        │
                       │ getName(): 'tenancy'                   │
                       │ getTemplate():                         │
                       │   '@Tenancy/Collector/tenant.html.twig'│
                       └─────────────────────┬──────────────────┘
                                             │
                                             ▼
        ┌──────────────────────────────────────────────────────────┐
        │  Profiler (Symfony framework-bundle)                     │
        │   → Profiler::collect() — invokes every data_collector   │
        │   → Profile object → FileProfilerStorage::write()        │
        │      → serialize($profile)  (native PHP)                 │
        │      → /var/cache/dev/profiler/xx/xx/{token}             │
        └──────────────────────────────────────────────────────────┘
                                             │
                                             ▼ (later: developer reloads)
        ┌──────────────────────────────────────────────────────────┐
        │  FileProfilerStorage::read() → unserialize() →           │
        │  DataCollector::__unserialize() restores $this->data     │
        │  → @Tenancy/Collector/tenant.html.twig renders same      │
        └──────────────────────────────────────────────────────────┘
```

### Recommended Project Structure

```
src/
├── Profiler/                                    # NEW namespace
│   ├── TenantProfilerStash.php                  # ResetInterface + 4× #[AsEventListener]
│   └── TenantDataCollector.php                  # extends AbstractDataCollector
├── Resources/
│   └── views/
│       └── Collector/
│           ├── tenant.html.twig                 # 3-state panel + WDT badge
│           └── _icon.svg.twig                   # ~24×24 inline SVG (chain/link glyph)
config/
├── services.php                                 # existing (untouched)
└── services_dev.php                             # NEW — kernel.debug-gated profiler services
src/TenancyBundle.php                            # ADD ~3 lines in loadExtension() to import services_dev.php
tests/
├── Unit/Profiler/
│   ├── TenantProfilerStashTest.php
│   └── TenantDataCollectorTest.php
└── Integration/Profiler/
    ├── TenantDataCollectorCompileOutTest.php
    ├── TenantDataCollectorSerializationTest.php
    └── TenantDataCollectorWdtTest.php
```

### Pattern 1: AbstractDataCollector contract (verified)

**What:** `AbstractDataCollector` extends `DataCollector` and implements `TemplateAwareDataCollectorInterface`. `getName()` defaults to `static::class`; override to return a stable short name (`'tenancy'`). `getTemplate()` is `static` (signature: `public static function getTemplate(): ?string`); override to return the template path.

**Source:** `vendor/symfony/framework-bundle/DataCollector/AbstractDataCollector.php` (full file, 30 lines)

```php
namespace Symfony\Bundle\FrameworkBundle\DataCollector;

use Symfony\Component\HttpKernel\DataCollector\DataCollector;

abstract class AbstractDataCollector extends DataCollector implements TemplateAwareDataCollectorInterface
{
    public function getName(): string
    {
        return static::class;
    }

    public static function getTemplate(): ?string
    {
        return null;
    }
}
```

**`DataCollectorInterface` (`vendor/symfony/http-kernel/DataCollector/DataCollectorInterface.php` lines 23–34):**

```php
interface DataCollectorInterface extends ResetInterface
{
    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void;
    public function getName(): string;
}
```

**`DataCollector` base class** (`vendor/symfony/http-kernel/DataCollector/DataCollector.php`):
- `protected array|Data $data = [];` (line 31) — the property to populate in `collect()`
- `public function __serialize(): array { return ['data' => $this->data]; }` (line 87–89)
- `public function __unserialize(array $data): void { $this->data = $data['data'] ?? $data["\0*\0data"]; }` (line 92–95)
- `public function reset(): void { $this->data = []; }` (line 97–100)

`[VERIFIED: vendor/ source]`

**Critical insight:** `$this->data` can be `array` or `Data` (VarDumper Cloner wrapper). For scalar/string-array values, leave it as `array` — `cloneVar()` is only needed when you have arbitrary objects to dump. Our 8-key shape (D-08) is all scalars/string-arrays — no `cloneVar()` needed. This is what makes the stored-profile round-trip trivial: PHP's native `serialize()` on a plain array is symmetric.

### Pattern 2: Collector registration (corrected — D-06 contradiction resolution)

**What:** Register profiler services only when `kernel.debug = true`.

**Why not D-06 as written:** `config/services.php` receives `ContainerConfigurator`, which exposes `parameters()` (writer), `services()`, `import()`, `env()` — but NOT `getParameter()`. The existing `interface_exists(MessageBusInterface::class)` block works because that's a pure-PHP function call, not a container read. To read `kernel.debug` we need `ContainerBuilder`, which is the third arg to `TenancyBundle::loadExtension()`.

**Recommended approach (resolves D-06 intent while being implementable):**

```php
// src/TenancyBundle.php — add ~3 lines after the existing `$container->import('../config/services.php');`
public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
{
    $container->import('../config/services.php');

    if ($builder->getParameter('kernel.debug')) {
        $container->import('../config/services_dev.php');
    }

    // ... rest of existing loadExtension unchanged ...
}
```

```php
// config/services_dev.php — NEW file, mirrors config/services.php style
<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Tenancy\Bundle\Profiler\TenantDataCollector;
use Tenancy\Bundle\Profiler\TenantProfilerStash;

return function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(TenantProfilerStash::class)
        ->autoconfigure(true)   // picks up kernel.event_listener + kernel.reset
        ->public(false);

    $services->set(TenantDataCollector::class)
        ->autoconfigure(true)   // picks up data_collector tag (via DataCollectorInterface)
        ->public()              // public so the compile-out test can $container->has(...)
        ->args([
            service(TenantProfilerStash::class),
            service('tenancy.context'),
            param('tenancy.driver'),
            param('tenancy.landlord_connection'),
        ])
        ->tag('data_collector', [
            'id' => 'tenancy',
            'template' => '@Tenancy/Collector/tenant.html.twig',
            'priority' => 270,   // between TwigDataCollector and TranslationDataCollector
        ]);
};
```

**Source for the tag attribute shape:** `vendor/symfony/framework-bundle/Resources/config/translation_debug.php` lines 23–28 (the canonical Symfony example).

**Source for autoconfiguration:** `vendor/symfony/framework-bundle/DependencyInjection/FrameworkExtension.php` lines 653–654:
```php
$container->registerForAutoconfiguration(DataCollectorInterface::class)
    ->addTag('data_collector');
```
And lines 671–672:
```php
$container->registerForAutoconfiguration(ResetInterface::class)
    ->addTag('kernel.reset', ['method' => 'reset']);
```

So `->autoconfigure(true)` on the stash gives it `kernel.reset` automatically. We still need the explicit `->tag('data_collector', [...])` on the collector because autoconfigure can't supply the `id` and `template` attributes.

`[VERIFIED: vendor/ source]`

### Pattern 3: Stash with `#[AsEventListener]` per-method attributes (matches bundle idiom)

**What:** Use `#[AsEventListener]` attributes, NOT `EventSubscriberInterface`. Verified bundle idiom — `src/EventListener/TenantContextOrchestrator.php` lines 18–19 and `src/EventListener/EntityManagerResetListener.php` line 11 both use this pattern; bundle has zero `EventSubscriberInterface` implementations.

**Skeleton:**

```php
namespace Tenancy\Bundle\Profiler;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Contracts\Service\ResetInterface;
use Tenancy\Bundle\Event\TenantBootstrapped;
use Tenancy\Bundle\Event\TenantContextCleared;
use Tenancy\Bundle\Event\TenantResolved;

final class TenantProfilerStash implements ResetInterface
{
    private ?string $resolvedBy = null;
    /** @var string[] */
    private array $bootstrapperFqcns = [];
    /** @var array{class:string,message:string}|null */
    private ?array $capturedException = null;

    #[AsEventListener(event: TenantResolved::class)]
    public function onTenantResolved(TenantResolved $event): void
    {
        $this->resolvedBy = $event->resolvedBy;
    }

    #[AsEventListener(event: TenantBootstrapped::class)]
    public function onTenantBootstrapped(TenantBootstrapped $event): void
    {
        $this->bootstrapperFqcns = $event->bootstrappers;
    }

    #[AsEventListener(event: TenantContextCleared::class)]
    public function onTenantContextCleared(TenantContextCleared $event): void
    {
        $this->reset();
    }

    #[AsEventListener(event: ExceptionEvent::class)]
    public function onKernelException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        if (!str_starts_with($throwable::class, 'Tenancy\\Bundle\\Exception\\')) {
            return;
        }
        $this->capturedException = [
            'class' => $throwable::class,
            'message' => $throwable->getMessage(),
        ];
    }

    public function getResolvedBy(): ?string { return $this->resolvedBy; }
    /** @return string[] */
    public function getBootstrapperFqcns(): array { return $this->bootstrapperFqcns; }
    /** @return array{class:string,message:string}|null */
    public function getCapturedException(): ?array { return $this->capturedException; }

    public function reset(): void
    {
        $this->resolvedBy = null;
        $this->bootstrapperFqcns = [];
        $this->capturedException = null;
    }
}
```

`ExceptionEvent` source verified: `vendor/symfony/http-kernel/Event/ExceptionEvent.php` line 30 (`final class ExceptionEvent extends RequestEvent`), `getThrowable()` line 47. `[VERIFIED]`

### Pattern 4: Twig template structure (verified against `symfony/symfony` HEAD)

**Source:** `gh api /repos/symfony/symfony/contents/src/Symfony/Bundle/WebProfilerBundle/Resources/views/Collector/translation.html.twig` — the canonical example.

**Required blocks:** `toolbar` (WDT badge), `menu` (sidebar entry in the full profiler), `panel` (the panel content). Extending `@WebProfiler/Profiler/layout.html.twig` is the convention.

```twig
{# src/Resources/views/Collector/tenant.html.twig #}
{% extends '@WebProfiler/Profiler/layout.html.twig' %}

{% block toolbar %}
    {% set icon %}
        {{ include('@Tenancy/Collector/_icon.svg.twig') }}
        <span class="sf-toolbar-value">
            {% if collector.data.state == 'resolved' %}{{ collector.data.slug }}
            {% elseif collector.data.state == 'error' %}⚠
            {% else %}—
            {% endif %}
        </span>
    {% endset %}

    {% set text %}
        <div class="sf-toolbar-info-piece">
            <b>State</b>
            <span class="sf-toolbar-status sf-toolbar-status-{{
                collector.data.state == 'error' ? 'red' :
                (collector.data.state == 'null' ? 'yellow' : 'green')
            }}">{{ collector.data.state }}</span>
        </div>
        <div class="sf-toolbar-info-piece">
            <b>Driver</b>
            <span>{{ collector.data.driver|default('-') }}</span>
        </div>
        {% if collector.data.state == 'resolved' %}
            <div class="sf-toolbar-info-piece">
                <b>Slug</b><span>{{ collector.data.slug }}</span>
            </div>
            <div class="sf-toolbar-info-piece">
                <b>Resolved by</b><span>{{ collector.data.resolved_by|split('\\')|last }}</span>
            </div>
        {% endif %}
    {% endset %}

    {% set status_color = collector.data.state == 'error' ? 'red' :
                          (collector.data.state == 'null' ? 'yellow' : '') %}
    {{ include('@WebProfiler/Profiler/toolbar_item.html.twig', {link: profiler_url, status: status_color}) }}
{% endblock %}

{% block menu %}
    <span class="label {{ collector.data.state == 'error' ? 'label-status-error' :
                          (collector.data.state == 'null' ? 'label-status-warning' : '') }}">
        <span class="icon">{{ include('@Tenancy/Collector/_icon.svg.twig') }}</span>
        <strong>Tenancy</strong>
    </span>
{% endblock %}

{% block panel %}
    <h2>Tenancy</h2>

    {% if collector.data.state == 'resolved' %}
        <div class="metrics">
            <div class="metric">
                <span class="value">{{ collector.data.slug }}</span>
                <span class="label">Slug</span>
            </div>
            <div class="metric">
                <span class="value">{{ collector.data.tenant_label|default('-') }}</span>
                <span class="label">Tenant</span>
            </div>
            <div class="metric">
                <span class="value">{{ collector.data.driver }}</span>
                <span class="label">Driver</span>
            </div>
            <div class="metric">
                <span class="value">{{ collector.data.connection_name|default('-') }}</span>
                <span class="label">Connection</span>
            </div>
        </div>

        <h3>Resolved by</h3>
        <code>{{ collector.data.resolved_by }}</code>

        <h3>Bootstrappers ({{ collector.data.bootstrappers|length }})</h3>
        {% if collector.data.bootstrappers is empty %}
            <div class="empty"><p>No bootstrappers ran.</p></div>
        {% else %}
            <ul>
                {% for fqcn in collector.data.bootstrappers %}
                    <li><code>{{ fqcn }}</code></li>
                {% endfor %}
            </ul>
        {% endif %}

    {% elseif collector.data.state == 'error' %}
        <h3>Resolution error</h3>
        <div class="empty empty-panel">
            <p><strong>{{ collector.data.error.class }}</strong></p>
            <p>{{ collector.data.error.message }}</p>
        </div>

    {% else %}
        <div class="empty empty-panel">
            <p>No tenant resolved for this request.</p>
            <p>This is the expected state for public, landlord, and health-check routes.</p>
        </div>
    {% endif %}
{% endblock %}
```

```twig
{# src/Resources/views/Collector/_icon.svg.twig #}
<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
     stroke-width="1.5" stroke="currentColor" fill="none"
     stroke-linecap="round" stroke-linejoin="round">
    <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
    <path d="M10 14a3.5 3.5 0 0 0 5 0l4 -4a3.5 3.5 0 0 0 -5 -5l-.5 .5"/>
    <path d="M14 10a3.5 3.5 0 0 0 -5 0l-4 4a3.5 3.5 0 0 0 5 5l.5 -.5"/>
</svg>
```

**Source for icon attributes:** `gh api /repos/symfony/symfony/contents/src/Symfony/Bundle/WebProfilerBundle/Resources/views/Icon/translation.svg` returns SVG with `xmlns="http://www.w3.org/2000/svg"`, `width="24"`, `height="24"`, `viewBox="0 0 24 24"`, `stroke-width="1.5"`, `stroke="currentColor"`. Match this shape — the WebProfilerBundle CSS expects `currentColor` so the icon picks up the toolbar's text color. `[VERIFIED: GitHub symfony/symfony Icon/translation.svg]`

**Source for toolbar status colors:** `gh api /repos/symfony/symfony/contents/src/Symfony/Bundle/WebProfilerBundle/Resources/views/Collector/translation.html.twig` — values are `'red'`, `'yellow'`, `'green'` (empty string = neutral). CSS classes: `sf-toolbar-status-{color}`. Menu badge: `label-status-{error|warning|success}`. `[VERIFIED]`

### Anti-Patterns to Avoid

- **Calling `$this->cloneVar()` for scalar data.** The base `DataCollector::cloneVar()` exists to wrap arbitrary objects in a serializable `Data` wrapper (uses `VarCloner` with `CutStub` casters). For our 8-key scalar shape this is unnecessary overhead and changes the Twig accessor from `collector.data.slug` to `collector.data.slug.value` — breaks the template. **Do not call `cloneVar()`.**
- **Storing a `TenantInterface` instance in `$this->data`.** Even though `Tenant` entities are usually serializable, storing one risks dragging a lazy Doctrine proxy or a closure into the profile dump. **Always extract scalars (`->getSlug()`, `->getName()`) at `collect()` time.**
- **Using `lateCollect()` for this collector.** DX-02 acceptance line 2 explicitly forbids it. `collect()` reads scalars synchronously on `kernel.response`; `lateCollect()` runs after, on `kernel.terminate`, and was historically used for things that needed to defer expensive cloning. Not needed here.
- **Storing the captured `Throwable` directly.** Per D-03/D-11, only `['class' => string, 'message' => string]` — no stack trace, no `previous`, no Throwable instance.
- **Hardcoding the connection name as the DSN-laden array.** Per D-09, the displayed value is a NAME string (`'tenant'`, `'default'`). Verify with the `:`/`@` defensive check.
- **Forgetting `->public()` on the collector for the compile-out test.** D-07's test does `$container->has(TenantDataCollector::class)` — but `has()` works for both public and private services on the test container, so this is fine. However, `$container->get(TenantDataCollector::class)` for assertion-by-instance does require public. Setting `->public()` on the collector is harmless and gives the test ergonomic access.
- **Registering the collector inside `loadExtension()`'s body using `services()` directly without the import.** Doing so puts dev-only registration code on the hot prod path; the `config/services_dev.php` import is the cleaner split.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Serializing `$this->data` | Custom `__serialize`/`__sleep` overrides | Leave it to `DataCollector::__serialize` (already round-trips only `$this->data`) | Built-in handles `Data` wrapper edge cases; overriding loses `__unserialize` symmetry. |
| Cloning objects for safe display | Manual `(array) $object` casts | `$this->cloneVar()` (but per D-11 we don't need it — store scalars only) | The `VarCloner` handles cycles, lazy proxies, closures. But we skip it entirely by storing scalars. |
| Twig namespace registration | Manual `addBundleResource()` calls | `AbstractBundle` auto-discovers `src/Resources/views/` as `@Tenancy/...` | Symfony 7.x `AbstractBundle` default. `[VERIFIED: TenancyBundle extends AbstractBundle, src/TenancyBundle.php line 33]` |
| Per-request state reset in long-running runtimes | Custom reset listener on `kernel.terminate` | `implements ResetInterface` (autoconfigured → `kernel.reset` tag → `ServicesResetter` calls between requests) | Symfony's `ServicesResetter` is the canonical mechanism for FrankenPHP/Swoole/RoadRunner. |
| `data_collector` tag registration | Manual `Profiler::add()` call | `->tag('data_collector', ['id' => ..., 'template' => ...])` (ProfilerPass adds them) | `vendor/symfony/framework-bundle/DependencyInjection/Compiler/ProfilerPass.php` lines 33–59. |

**Key insight:** Symfony's profiler ecosystem is unusually disciplined about serialization. Every quirk (the `Data` wrapper, `cloneVar()`, `__serialize`/`__unserialize`, `kernel.reset` autoconfiguration) exists for a reason. Stay on the rails: extend `AbstractDataCollector`, store scalars, return the template path from `getTemplate()`, and let Symfony do everything else.

## Common Pitfalls

### Pitfall 1: `kernel.debug` is unreadable from `config/services.php`

**What goes wrong:** Following D-06's text literally — adding `if ($container->getParameter('kernel.debug')) { ... }` inside `config/services.php` — fails because `ContainerConfigurator` does not expose `getParameter()`. The closure signature is `function (ContainerConfigurator $container): void`.

**Why it happens:** The existing `interface_exists(MessageBusInterface::class)` block in `config/services.php` (line 134) misleads — it works because `interface_exists()` is pure PHP, not a DI lookup. `kernel.debug` IS a DI lookup.

**How to avoid:** Read the parameter from `TenancyBundle::loadExtension()`, which has the `ContainerBuilder $builder` argument (the third parameter). Conditionally import `config/services_dev.php` only when `$builder->getParameter('kernel.debug')` is `true`. See Pattern 2 above.

**Warning signs:** PHPStan level 9 will catch the typo (`ContainerConfigurator::getParameter()` doesn't exist). If you bypass PHPStan, the runtime error is `Error: Call to undefined method Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator::getParameter()`.

### Pitfall 2: Bundle convention is `#[AsEventListener]`, not `EventSubscriberInterface`

**What goes wrong:** Following CONTEXT.md `<code_context>` line 177 ("auto-registers via `kernel.event_subscriber` tag") means writing `implements EventSubscriberInterface { public static function getSubscribedEvents() ... }`. Both produce working code, but it breaks the bundle's idiomatic consistency and creates a one-off in PHPStan diff/code review.

**Why it happens:** CONTEXT.md was assembled from generic Symfony idiom, not from a scan of the bundle's existing listeners.

**How to avoid:** Match the established pattern — every listener in `src/EventListener/` uses `#[AsEventListener]` (verified for `TenantContextOrchestrator.php` lines 18–19, `EntityManagerResetListener.php` line 11, `ConsoleResolver.php` line 17). The stash gets four `#[AsEventListener]` attributes, one per method.

**Warning signs:** A new file with `implements EventSubscriberInterface` is the only such occurrence in the bundle. Code review should flag it.

### Pitfall 3: `tenancy.database.connection_name` parameter does NOT exist

**What goes wrong:** D-09 hedges ("likely `%tenancy.database.connection_name%` or hardcoded `'tenant'`"). The parameter does NOT exist in the bundle. Searching `src/` for `tenancy.database.connection_name` returns zero results (verified by `grep -rn "tenancy.database.connection_name" src/`).

**Why it happens:** The bundle hardcodes Doctrine connection names by convention. `src/TenancyBundle.php` lines 162 and 193 reference `doctrine.dbal.tenant_connection` — the Doctrine connection name is `'tenant'` by hardcoded convention (set when the user runs `tenancy:install` or manually configures `doctrine.dbal.connections.tenant` in their app).

**How to avoid:** In the collector:
- For `database_per_tenant` driver: return the literal string `'tenant'`.
- For `shared_db` driver: read `%tenancy.landlord_connection%` (default `'default'`).
- Document the assumption in a PHPDoc on the collector field, since the user's app could in principle name the connection differently. If the bundle ever introduces a `tenancy.database.connection_name` config key, the collector updates trivially.

**Warning signs:** If a future test fails because the user's app named their tenant connection `'tenant_db'` instead of `'tenant'`, the panel shows wrong data but does not throw — the value is still a label string and passes the `:`/`@` sanitization check.

### Pitfall 4: `cloneVar()` would break the scalar-only contract

**What goes wrong:** Calling `$this->data = $this->cloneVar($scalarArray)` wraps the array in a `VarDumper\Cloner\Data` object. Twig accessor changes: `collector.data.slug` (scalar) becomes `collector.data.slug` returning a `Data` cursor — `|first` and `.value` semantics differ. Template breaks.

**Why it happens:** Some Symfony docs and older blog posts demonstrate `cloneVar()` as universal — useful for arbitrary objects but unnecessary (and harmful) for plain scalars.

**How to avoid:** **Never call `cloneVar()`** in this collector. The `protected array|Data $data` type union accepts plain arrays directly.

**Warning signs:** Twig errors like `Method "slug" for object "Symfony\Component\VarDumper\Cloner\Data" does not exist` or `An exception has been thrown during the rendering of a template ("Argument of count() must be of type Countable|array, Symfony\...\Data given")` on panel render.

### Pitfall 5: Storing a `Throwable` instance breaks serialization

**What goes wrong:** `$this->data['error'] = $throwable` — most Symfony exception classes are serializable, but a `previous` chain can drag in non-serializable objects (closures inside HTTP middleware traces, PDOExceptions with active resource handles).

**Why it happens:** Convenience — "the exception has everything I need."

**How to avoid:** Per D-03/D-11, store ONLY `['class' => $throwable::class, 'message' => $throwable->getMessage()]`. The stash does this at capture time, before any serialization.

**Warning signs:** `FileProfilerStorage::write()` throws `Serialization of 'Closure' is not allowed` or similar.

### Pitfall 6: Doctrine `Connection` object leaking through `connection_name`

**What goes wrong:** Wiring `service('doctrine.dbal.tenant_connection')` into the collector and storing `$connection->getParams()` for display — exposes the password.

**Why it happens:** "Get the real connection name from the connection itself" feels more dynamic.

**How to avoid:** Per D-09, the displayed value is a LABEL string injected via DI parameters at construction time. NEVER wire a `Connection` instance into the collector. The defensive `:`/`@` check at the collector boundary is the final defence — but the architecture must not give it the chance to fire in normal use.

**Warning signs:** Panel shows `mysql://root:secret@db:3306/tenant_acme` instead of `tenant`. The defensive `:`/`@` check would log a `RuntimeException` in dev — but that's a defence, not the primary contract.

### Pitfall 7: WebProfilerBundle not in `require-dev` blocks WDT integration test

**What goes wrong:** `TenantDataCollectorWdtTest.php` (D-13 test 5) boots a kernel with WebProfilerBundle to drive a request and assert the panel renders. If WebProfilerBundle isn't in `require-dev`, the test fails with `Class "Symfony\Bundle\WebProfilerBundle\WebProfilerBundle" not found`.

**Why it happens:** The bundle's `composer.json` doesn't list WebProfilerBundle today — verified `[VERIFIED: /Users/danplaton/dev/.../composer.json]` shows require-dev has `framework-bundle`, `messenger`, `phpunit-bridge`, etc., but no `web-profiler-bundle`.

**How to avoid:** Plan task: add `"symfony/web-profiler-bundle": "^7.4||^8.0"` and `"symfony/twig-bundle": "^7.4||^8.0"` to `composer.json` require-dev as a Wave 0 step.

**Warning signs:** First WDT integration test run errors out during kernel boot, not during the actual assertion.

### Pitfall 8: AbstractDataCollector compile-out — `data_collector` autoconfigure tag is harmless in prod but the collector class still loads

**What goes wrong:** Someone "fixes" the conditional import by always importing services_dev.php (e.g., refactoring to centralize service definitions). The compile-out test still passes superficially if `kernel.debug = false` triggers FrameworkBundle to remove the profiler service — but `TenantDataCollector::class` is still in the container.

**Why it happens:** Surface-level symptoms (no panel in prod) hide deeper issue (unused service in container).

**How to avoid:** The Nyquist validation map (below) includes a static check: `grep -rn "TenantDataCollector\|TenantProfilerStash" config/services.php` MUST return zero results (only `services_dev.php` may reference them). This complements the runtime kernel boot check.

**Warning signs:** Compile-out kernel test passes but `grep` finds the classes leaking into `config/services.php`.

## Code Examples

### TenantDataCollector skeleton

```php
namespace Tenancy\Bundle\Profiler;

use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tenancy\Bundle\Context\TenantContext;

final class TenantDataCollector extends AbstractDataCollector
{
    public function __construct(
        private readonly TenantProfilerStash $stash,
        private readonly TenantContext $tenantContext,
        private readonly string $driver,                // %tenancy.driver%
        private readonly string $landlordConnection,    // %tenancy.landlord_connection%
    ) {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $tenant = $this->tenantContext->getTenant();

        if ($tenant !== null) {
            $state = 'resolved';
        } elseif ($this->stash->getCapturedException() !== null) {
            $state = 'error';
        } else {
            $state = 'null';
        }

        $connectionName = match ($this->driver) {
            'database_per_tenant' => 'tenant',          // hardcoded by Doctrine convention; see Pitfall 3
            'shared_db'           => $this->landlordConnection,
            default               => null,
        };

        // Defensive: connection_name is a LABEL, never a DSN.
        if ($connectionName !== null && (str_contains($connectionName, ':') || str_contains($connectionName, '@'))) {
            // Loud failure in dev — this should be unreachable.
            throw new \RuntimeException(sprintf(
                'TenantDataCollector: connection_name "%s" looks like a DSN — never display credentials.',
                $connectionName
            ));
        }

        $this->data = [
            'state'           => $state,
            'slug'            => $tenant?->getSlug(),
            'tenant_label'    => $tenant?->getName(),
            'driver'          => $this->driver,
            'connection_name' => $connectionName,
            'resolved_by'     => $this->stash->getResolvedBy(),
            'bootstrappers'   => array_values(array_map('strval', $this->stash->getBootstrapperFqcns())),
            'error'           => $this->stash->getCapturedException(),
        ];
    }

    public function getName(): string
    {
        return 'tenancy';
    }

    public static function getTemplate(): ?string
    {
        return '@Tenancy/Collector/tenant.html.twig';
    }

    // reset() inherited from DataCollector — clears $this->data to [].
}
```

`TenantInterface::getName(): string` verified at `src/TenantInterface.php` line 16. `TenantInterface::getSlug(): string` verified at line 9. `[VERIFIED: src/TenantInterface.php]`

### Compile-out test skeleton

```php
namespace Tenancy\Bundle\Tests\Integration\Profiler;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Tenancy\Bundle\Profiler\TenantDataCollector;
use Tenancy\Bundle\Profiler\TenantProfilerStash;
use Tenancy\Bundle\TenancyBundle;
use Tenancy\Bundle\Tests\Integration\Support\ReplaceTenancyProviderPass;

final class TenantDataCollectorCompileOutTest extends TestCase
{
    public function testCollectorIsRegisteredWhenDebugTrue(): void
    {
        $kernel = new class('test', true) extends Kernel {
            public function registerBundles(): iterable
            {
                return [new FrameworkBundle(), new TenancyBundle()];
            }
            public function build(ContainerBuilder $container): void
            {
                parent::build($container);
                $container->addCompilerPass(new ReplaceTenancyProviderPass());
            }
            public function registerContainerConfiguration(LoaderInterface $loader): void
            {
                $loader->load(static function (ContainerBuilder $c): void {
                    $c->loadFromExtension('framework', [
                        'secret' => 'test', 'test' => true, 'http_method_override' => false,
                        'handle_all_throwables' => true, 'php_errors' => ['log' => true],
                    ]);
                });
            }
            public function getCacheDir(): string { return sys_get_temp_dir().'/tenancy_test_debug_'.spl_object_id($this); }
            public function getLogDir(): string  { return sys_get_temp_dir().'/tenancy_test_debug_logs_'.spl_object_id($this); }
        };
        $kernel->boot();

        try {
            self::assertTrue($kernel->getContainer()->has(TenantDataCollector::class));
            self::assertTrue($kernel->getContainer()->has(TenantProfilerStash::class));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testCollectorIsAbsentWhenDebugFalse(): void
    {
        // Same kernel but new __construct('prod', false). Assert false for both has() calls.
        // ...
    }
}
```

The existing `tests/Integration/TestKernel.php` (lines 21–32) demonstrates the minimal kernel — `FrameworkBundle + TenancyBundle + ReplaceTenancyProviderPass`. The compile-out test re-uses this shape but parameterizes `(environment, debug)`. `[VERIFIED: tests/Integration/TestKernel.php]`

### Stored-profile round-trip test skeleton

```php
namespace Tenancy\Bundle\Tests\Integration\Profiler;

use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Profiler\TenantDataCollector;
// ... setup collector with stash, populate via collect(...) ...

final class TenantDataCollectorSerializationTest extends TestCase
{
    public function testCollectorRoundTripsViaSerializeUnserialize(): void
    {
        $collector = /* build & populate */;
        $collector->collect(new Request(), new Response());

        $blob = serialize($collector);
        $restored = unserialize($blob);

        self::assertInstanceOf(TenantDataCollector::class, $restored);
        self::assertSame($collector->getData(), $restored->getData());
    }
}
```

`DataCollector` defines `__serialize` and `__unserialize` (`vendor/symfony/http-kernel/DataCollector/DataCollector.php` lines 87–95) — both round-trip only `$this->data`. With our 8-key scalar shape this is guaranteed lossless. `getData()` is the conventional accessor used by Symfony's bundled tests (not in `AbstractDataCollector` source — collectors typically expose data via Twig `collector.foo` magic getters or explicit accessors; the test can use reflection or a temporary getter).

**Note on `getData()` accessor:** The base `DataCollector::$data` is `protected`. Twig accesses it via `collector.data.foo` which works because of Twig's `__call`/property access. For PHPUnit assertions, expose a `public function getData(): array` on `TenantDataCollector` (returns `$this->data` cast to array — if `Data` wrapper ever enters, throw).

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `extends DataCollector` + manual `setTemplate()` parameter | `extends AbstractDataCollector` + `public static function getTemplate(): ?string` override | Symfony 4.4+ | One-line template registration; `ProfilerPass` auto-detects. |
| YAML config with `tags: - { name: data_collector, ... }` | PHP DSL `->tag('data_collector', [...])` (or `#[AutoconfigureTag]`) | Symfony 5.3+ | Type-safe, IDE-discoverable. |
| `EventSubscriberInterface` | `#[AsEventListener]` per-method attribute | Symfony 5.4+ | Less boilerplate; per-method event binding. Bundle's idiom. |
| Custom `kernel.terminate` reset listener | `implements ResetInterface` (autoconfigured `kernel.reset`) | Symfony 5.3+ | Works across HttpKernel + Messenger + long-running runtimes uniformly. |
| `$this->data = $this->cloneVar(...)` for all data | Only wrap when you have non-scalar objects | Always — but the docs improved post-7.0 | Scalar data should NOT be cloned — preserves direct Twig accessor. |

**Deprecated/outdated:**

- Nothing in this phase relies on deprecated APIs. All used APIs (`AbstractDataCollector`, `#[AsEventListener]`, `ResetInterface` autoconfiguration, `ProfilerPass`) are stable in Symfony 7.4 LTS and 8.0+.

## Runtime State Inventory

This is a greenfield phase — no rename, refactor, migration, or string replacement involved. New code lives in a new namespace (`Tenancy\Bundle\Profiler\`). No existing data, configuration, OS-registered state, secrets, or build artifacts are affected.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | None — no DB writes, no cache writes. Profiler dumps live in user-app `var/cache/dev/profiler/` (managed by user's Symfony app, not by this bundle). | None |
| Live service config | None — purely DI service registration, no external service config | None |
| OS-registered state | None | None |
| Secrets / env vars | None (the `connection_name` displayed is a label, never a credentialled DSN) | None |
| Build artifacts | None (new code, no rebuild of existing artifacts) | None |

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| `php` | All code | ✓ | 8.2+ (project minimum) | — |
| `symfony/web-profiler-bundle` | WDT integration test (D-13 #5) | ✗ (must be added) | Target `^7.4\|\|^8.0` | None — must add to `require-dev`; this is a Wave 0 task. |
| `symfony/twig-bundle` | Twig rendering in WDT test | ✗ (pulled transitively by WebProfilerBundle) | Target `^7.4\|\|^8.0` | Pulled by WebProfilerBundle require — no explicit action needed unless WDT test needs Twig in isolation. |
| `symfony/framework-bundle` | AbstractDataCollector, autoconfiguration | ✓ | `^7.4\|\|^8.0` (require-dev) | — |
| `symfony/http-kernel` | Profiler infrastructure | ✓ | `^7.4\|\|^8.0` (require) | — |
| GitHub Actions CI | Runs `phpunit --testsuite integration` (includes compile-out test) | ✓ | — | — |

**Missing dependencies with no fallback:** None.

**Missing dependencies with fallback:** `symfony/web-profiler-bundle` and `symfony/twig-bundle` — must add to `require-dev` (Wave 0 task).

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11 (already in `require-dev`: `"phpunit/phpunit": "^11.0"`) |
| Config file | `phpunit.xml` / `phpunit.xml.dist` (must verify exists; if not, Wave 0 creates) |
| Quick run command | `vendor/bin/phpunit --testsuite unit --filter Profiler` (~2s) |
| Full suite command | `vendor/bin/phpunit` (full unit + integration) |
| Static check command | `vendor/bin/phpstan analyse src/Profiler tests/Unit/Profiler tests/Integration/Profiler` |
| Style command | `vendor/bin/php-cs-fixer fix src/Profiler tests/Unit/Profiler tests/Integration/Profiler --diff` |

### Phase Requirements → Test Map

ROADMAP defines 5 success criteria for Phase 19; the Nyquist principle demands ≥2 independent validation classes per criterion. Each row below provides at least one runtime test + at least one static/structural check.

| Criterion | ROADMAP # | Test Type | Automated Command | Test File |
|-----------|-----------|-----------|-------------------|-----------|
| **A. WDT badge visible with active slug** | 1 | functional integration | `vendor/bin/phpunit --filter TenantDataCollectorWdtTest::testBadgeShowsSlugForResolvedTenant` | `tests/Integration/Profiler/TenantDataCollectorWdtTest.php` (NEW — Wave 0) |
| A. (static counterpart) | 1 | structural — Twig template contains required block names | `vendor/bin/phpunit --filter TenantDataCollectorTest::testTemplatePathReturnsBundleNamespace` + `grep -l "block toolbar\|block menu\|block panel" src/Resources/views/Collector/tenant.html.twig` | `tests/Unit/Profiler/TenantDataCollectorTest.php` (NEW — Wave 0) |
| **B. Panel data correct (slug, ID, driver, connection, resolver FQCN, bootstrappers)** | 2 | unit — `collect()` produces 8-key shape with correct values | `vendor/bin/phpunit --filter TenantDataCollectorTest::testCollectProducesResolvedStateShape` | `tests/Unit/Profiler/TenantDataCollectorTest.php` |
| B. (functional counterpart) | 2 | functional — render template, scrape rendered HTML, assert substrings | `vendor/bin/phpunit --filter TenantDataCollectorWdtTest::testPanelRendersAllRequiredFields` | `tests/Integration/Profiler/TenantDataCollectorWdtTest.php` |
| **C. Null-resolution state renders cleanly** | 3 | unit — `collect()` with no tenant produces `state == 'null'` | `vendor/bin/phpunit --filter TenantDataCollectorTest::testCollectProducesNullStateWhenNoTenant` | `tests/Unit/Profiler/TenantDataCollectorTest.php` |
| C. (functional counterpart) | 3 | functional — public route → panel shows "—" badge + "no tenant" panel body | `vendor/bin/phpunit --filter TenantDataCollectorWdtTest::testNullResolutionShowsEmDashAndNoTenantPanel` | `tests/Integration/Profiler/TenantDataCollectorWdtTest.php` |
| C. (error counterpart) | 3 | unit — exception during resolution → `state == 'error'` | `vendor/bin/phpunit --filter TenantDataCollectorTest::testCollectProducesErrorStateWhenStashCapturedException` | `tests/Unit/Profiler/TenantDataCollectorTest.php` |
| **D. Stored-profile reload safe (serialize/unserialize round-trip)** | 4 | integration — `serialize($collector); $r = unserialize(...);` round-trip equality | `vendor/bin/phpunit --filter TenantDataCollectorSerializationTest` | `tests/Integration/Profiler/TenantDataCollectorSerializationTest.php` (NEW — Wave 0) |
| D. (negative counterpart) | 4 | unit — collector data contains NO `Throwable` instances, NO closures, NO objects | `vendor/bin/phpunit --filter TenantDataCollectorTest::testDataContainsOnlyScalarsAndStringArrays` | `tests/Unit/Profiler/TenantDataCollectorTest.php` |
| **E. Compile-out: collector absent in `kernel.debug=false` container** | 5 | integration — boot 2 kernels (debug=true, debug=false), assert `has()` | `vendor/bin/phpunit --filter TenantDataCollectorCompileOutTest` | `tests/Integration/Profiler/TenantDataCollectorCompileOutTest.php` (NEW — Wave 0) |
| E. (structural counterpart) | 5 | static — `grep -c "TenantDataCollector\|TenantProfilerStash" config/services.php` must equal 0 (only `config/services_dev.php` may reference these classes) | Shell check, ideally codified in a `tests/Integration/Profiler/SourceLayoutTest.php` PHPUnit test that opens `config/services.php` and asserts absence | `tests/Integration/Profiler/SourceLayoutTest.php` (NEW — Wave 0, optional but recommended per "refactor that moves registration into a compiler pass can't silently break compile-out") |
| **Connection-name DSN-leak defence (D-09 hard rule)** | (covered under B) | unit — feed collector a DSN-looking string, assert RuntimeException | `vendor/bin/phpunit --filter TenantDataCollectorTest::testConnectionNameDsnLikeStringThrows` | `tests/Unit/Profiler/TenantDataCollectorTest.php` |
| **Exception capture scope (D-03)** | (cross-cuts C) | unit — non-tenancy exceptions are ignored; only `Tenancy\Bundle\Exception\*` capture | `vendor/bin/phpunit --filter TenantProfilerStashTest::testIgnoresNonTenancyExceptions` | `tests/Unit/Profiler/TenantProfilerStashTest.php` (NEW — Wave 0) |
| **Stash reset semantics (long-running runtimes)** | (cross-cuts all) | unit — `reset()` clears all 3 fields | `vendor/bin/phpunit --filter TenantProfilerStashTest::testResetClearsAllFields` | `tests/Unit/Profiler/TenantProfilerStashTest.php` |

### Sampling Rate

- **Per task commit:** `vendor/bin/phpunit --testsuite unit --filter Profiler` (sub-second, every commit)
- **Per wave merge:** `vendor/bin/phpunit --filter Profiler` (unit + integration, ~10s)
- **Phase gate (`/gsd-verify-work`):** `vendor/bin/phpunit && vendor/bin/phpstan analyse && vendor/bin/php-cs-fixer check`

### Wave 0 Gaps

- [ ] `composer.json` — add `"symfony/web-profiler-bundle": "^7.4||^8.0"` and `"symfony/twig-bundle": "^7.4||^8.0"` to `require-dev`. Run `composer update --dev` and commit `composer.lock`.
- [ ] `tests/Unit/Profiler/` directory — does not exist; create with `.gitkeep` placeholder or first test file.
- [ ] `tests/Integration/Profiler/` directory — does not exist; create.
- [ ] `tests/Integration/Profiler/Support/ProfilerTestKernel.php` — optional dedicated test kernel that adds `WebProfilerBundle` + `TwigBundle` on top of `TenancyBundle + FrameworkBundle`. Reuse the existing `tests/Integration/TestKernel.php` shape.
- [ ] No new PHPUnit fixtures needed — existing `tests/Integration/Support/ReplaceTenancyProviderPass.php` (referenced by `TestKernel.php` line 39) covers the compile-out test's tenancy.provider stub.
- [ ] Verify `phpunit.xml` (or `.dist`) has an `<testsuite name="integration">` element that includes `tests/Integration/Profiler` — should be automatic if `tests/Integration` is already covered by glob.

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no | No auth code introduced. |
| V3 Session Management | no | No session code. |
| V4 Access Control | yes (dev-only gate) | `kernel.debug` guard ensures collector is absent in prod container. Defence-in-depth: even if it leaked into prod, profiler URL is itself dev-only. |
| V5 Input Validation | yes (defensive) | Connection-name `:`/`@` check at collector boundary (D-09 hard rule). Stash exception capture scope check (D-03 — only `Tenancy\Bundle\Exception\*`). |
| V6 Cryptography | no | No crypto, no secrets handling. |
| V8 Data Protection | yes | DSN credentials must never appear in `$this->data` (D-09 / D-11). Verified by unit test (Validation row "Connection-name DSN-leak defence"). |
| V14 Configuration | yes | Compile-out is the principal config-security control. Verified by D-07 integration test (Validation row E) + static check (Validation row E counterpart). |

### Known Threat Patterns for `Symfony 7.x Web Profiler / multi-tenant bundle`

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Profiler leaks credentials to dev viewer | Information Disclosure | Store only scalar label strings; never wire `Doctrine\DBAL\Connection` into the collector; defensive `:`/`@` check on `connection_name`. |
| Profiler enabled in production by misconfiguration | Information Disclosure | `kernel.debug` runtime gate at DI registration time; compile-out test fails CI if a refactor breaks this. |
| Serialization of sensitive object graph through `$this->data` | Information Disclosure | Scalar-only contract (D-11) enforced by unit test asserting only scalars + string arrays. |
| Profile dump contains stale tenant context from a previous request (long-running runtime) | Tampering / Information Disclosure | Stash implements `ResetInterface`; Symfony's `ServicesResetter` calls `reset()` between requests on FrankenPHP/Swoole/RoadRunner. |
| Generic application 500s flip the panel into "error" state, misleading the developer | Repudiation | Exception scope check (D-03) — only `Tenancy\Bundle\Exception\*` is recorded. |

## Project Constraints (from CLAUDE.md)

The bundle's `CLAUDE.md` enforces the following — research output respects them all:

- **`strict_types=1`** on every PHP file. Applies to `src/Profiler/*` and `tests/Unit/Profiler/*`, `tests/Integration/Profiler/*`.
- **Doctrine optional, guarded by `class_exists` / `interface_exists`.** Phase 19 has zero Doctrine dependency — collector reads `TenantContext` (zero-dep) and DI parameter scalars. No Doctrine import in `src/Profiler/`.
- **PHPStan level 9** — all new code must pass. The `@var` and `@return` annotations in `TenantProfilerStash` (`array{class:string,message:string}|null`, `string[]`) are level-9 compliant.
- **`php-cs-fixer` `@Symfony` ruleset.** New code follows existing project formatting. Run `vendor/bin/php-cs-fixer fix src/Profiler tests/Unit/Profiler tests/Integration/Profiler` before commit.
- **PHPUnit 11** — match the existing test class style (`final class`, `setUpBeforeClass`/`tearDownAfterClass` for kernel lifecycle, `:memory:` SQLite where DB is needed).
- **`TenantContext` is zero-dep — no constructor args.** Phase 19 never modifies `TenantContext`. ✓
- **Bootstrapper `clear()` runs in reverse.** Not applicable — Phase 19 introduces no new bootstrapper.
- **Compiler passes handle service wiring — no manual DI config needed by users.** Phase 19 introduces NO new compiler pass; uses runtime `if` in `loadExtension()` per CONTEXT D-06 intent. End-user activation is automatic via WebProfilerBundle's `require-dev` discovery — zero user config.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | The bundle's Doctrine `'tenant'` connection name is hardcoded by convention — no `tenancy.database.connection_name` parameter exists | Pitfall 3, Code Examples (TenantDataCollector) | LOW — the displayed value would still be a label string passing the `:`/`@` sanitization check. Worst case: panel shows `'tenant'` when user named it `'tenant_db'`. `[VERIFIED via grep on src/, but only in this research session — if Phase 20+ introduces the parameter, the collector updates trivially.]` |
| A2 | WebProfilerBundle 7.4.11 / 8.0.11 (latest stable as of 2026-05-18) are compatible with the bundle's existing Symfony `^7.4\|\|^8.0` matrix | Standard Stack | LOW — version lines mirror `symfony/symfony` itself; CI runs the matrix and would catch any mismatch. `[VERIFIED via gh api on releases]` |
| A3 | Symfony 7.x `$container->getParameter('kernel.debug')` from inside a bundle's `loadExtension()` returns `bool` (not string) | Pattern 2 | LOW — verified by usage pattern in `vendor/symfony/framework-bundle/DependencyInjection/FrameworkExtension.php` lines 797, 996, 1209, where it's used in `if (...)` directly. `[VERIFIED]` |
| A4 | Twig namespace `@Tenancy/...` is auto-registered by `AbstractBundle` for `src/Resources/views/` | Pattern 4, D-12 | MEDIUM — if `AbstractBundle` resource discovery doesn't apply (e.g., user's app has a non-standard Twig setup), `getTemplate()` returns a path that fails to render. Mitigation: WDT integration test renders the template — if discovery fails, the test fails loudly during planning, not in user apps. `[CITED: symfony.com/doc/current/bundles.html — AbstractBundle convention]` |
| A5 | `array_values(array_map('strval', $stash->getBootstrapperFqcns()))` is sufficient defensive normalization | Code Examples | LOW — `getBootstrapperFqcns()` returns a `string[]` from `TenantBootstrapped::$bootstrappers` which is already typed as `string[]`. The defensive map is belt-and-suspenders. `[VERIFIED via src/Event/TenantBootstrapped.php line 16]` |

**Note:** No claim in this research conflicts with CONTEXT.md's locked decisions. The two "contradictions" surfaced in the Summary are between CONTEXT.md's *implementation phrasing* and the *actual Symfony APIs* — D-06 and the EventSubscriberInterface mention. The locked *intent* (compile-out via runtime guard in DI config; stash captures events) is preserved; only the mechanism shifts to what's actually implementable / matches bundle idiom.

## Open Questions (RESOLVED)

1. **Should the stash also capture which resolver class is the WINNER vs the chain order tried?**
   - What we know: `TenantResolved::$resolvedBy` is a single FQCN (the winner). `ResolverChain` does not currently dispatch per-resolver attempts.
   - What's unclear: Nothing — the resolver order is captured implicitly because only the winning resolver fires `TenantResolved`. Per-resolver attempts are CONTEXT.md `<deferred>` (line 196).
   - **RESOLVED:** Honor the deferred status. Single `resolved_by` FQCN is correct for Phase 19. Per-resolver attempt log is a separate future phase.

2. **Should the collector also surface `kernel.debug` and `kernel.environment` for diagnostic clarity?**
   - What we know: Symfony's built-in `ConfigDataCollector` already surfaces these.
   - What's unclear: Whether the tenant panel should duplicate them.
   - **RESOLVED:** NO — D-08 locks the 8-key shape; ConfigDataCollector already shows env/debug; duplication adds maintenance burden with no marginal value. The panel does not surface kernel.debug or kernel.environment.

3. **Does `tests/Integration/Profiler/TenantDataCollectorWdtTest.php` need a separate test kernel that adds WebProfilerBundle + TwigBundle, or can it extend the existing `TestKernel.php`?**
   - What we know: The existing `TestKernel.php` registers only `FrameworkBundle + TenancyBundle`. WebProfilerBundle requires TwigBundle.
   - What's unclear: Whether adding the two bundles in a subclass or a sibling kernel is cleaner.
   - **RESOLVED:** Create a sibling `tests/Integration/Profiler/Support/ProfilerTestKernel.php` that re-uses the same cache dir pattern as `TestKernel.php`. Cleaner separation; `TestKernel.php` stays minimal. This is implemented in Plan 00 (Wave 0 setup).

## Sources

### Primary (HIGH confidence)

- **`vendor/symfony/framework-bundle/DataCollector/AbstractDataCollector.php`** — full file (30 lines) read; confirms `getName()` returns `static::class` by default, `getTemplate()` is static returning `?string`. `[VERIFIED]`
- **`vendor/symfony/http-kernel/DataCollector/DataCollector.php`** — confirms `protected array|Data $data`, `__serialize`, `__unserialize`, `reset()`, `cloneVar()` semantics. `[VERIFIED]`
- **`vendor/symfony/http-kernel/DataCollector/DataCollectorInterface.php`** — confirms `extends ResetInterface` and `collect(Request, Response, ?Throwable)` signature. `[VERIFIED]`
- **`vendor/symfony/framework-bundle/DependencyInjection/FrameworkExtension.php`** lines 653–654 (DataCollectorInterface autoconfigure → `data_collector` tag), 671–672 (ResetInterface autoconfigure → `kernel.reset` tag), 797 / 996 / 1209 / 1779 (`kernel.debug` parameter usage pattern from `ContainerBuilder`). `[VERIFIED]`
- **`vendor/symfony/framework-bundle/DependencyInjection/Compiler/ProfilerPass.php`** — full file (62 lines) read; confirms tag attribute semantics (`id`, `template`, `priority`). `[VERIFIED]`
- **`vendor/symfony/framework-bundle/Resources/config/translation_debug.php`** lines 19–30 — canonical tag-attribute example used by Symfony itself. `[VERIFIED]`
- **`vendor/symfony/http-kernel/Profiler/FileProfilerStorage.php`** lines 164 (`$data = serialize($data);`) + 309 (`unserialize($data)`) — confirms native PHP serialization round-trip. `[VERIFIED]`
- **`vendor/symfony/service-contracts/ResetInterface.php`** — full file (33 lines); confirms `public function reset()` signature. `[VERIFIED]`
- **`vendor/symfony/http-kernel/Event/ExceptionEvent.php`** lines 30, 47 — `final class ExceptionEvent extends RequestEvent`, `getThrowable()`. `[VERIFIED]`
- **`vendor/symfony/dependency-injection/Loader/Configurator/ContainerConfigurator.php`** lines 30–96 — confirms exposed methods: `extension()`, `import()`, `parameters()`, `services()`, `env()`. NO `getParameter()`. This is the contradiction in D-06. `[VERIFIED]`
- **`src/TenancyBundle.php`** lines 33, 101–124 — confirms `class TenancyBundle extends AbstractBundle`, `loadExtension(array, ContainerConfigurator, ContainerBuilder)` signature, `tenancy.driver` and `tenancy.landlord_connection` parameter registration. `[VERIFIED]`
- **`src/Event/TenantResolved.php`** line 15 — `public readonly string $resolvedBy`. `[VERIFIED]`
- **`src/Event/TenantBootstrapped.php`** line 16 — `public readonly array $bootstrappers` typed as `string[]` via PHPDoc. `[VERIFIED]`
- **`src/Event/TenantContextCleared.php`** — empty event class (no fields). `[VERIFIED]`
- **`src/Context/TenantContext.php`** — zero-dep value holder; `hasTenant()`, `getTenant()`. `[VERIFIED]`
- **`src/Bootstrapper/BootstrapperChain.php`** lines 25–35 — confirms `boot()` builds the `$fqcns` array via `$bootstrapper::class` and dispatches `TenantBootstrapped($tenant, $fqcns)`. `[VERIFIED]`
- **`src/EventListener/TenantContextOrchestrator.php`** lines 18–19 (#[AsEventListener] attributes), 41–45 (null-resolution early-return path). `[VERIFIED]`
- **`src/TenantInterface.php`** lines 9 (`getSlug(): string`), 16 (`getName(): string`). `[VERIFIED]`
- **`config/services.php`** lines 134–146 — the `interface_exists(MessageBusInterface::class)` conditional registration pattern that CONTEXT D-06 references. `[VERIFIED]`
- **`tests/Integration/TestKernel.php`** lines 21–63 — confirms the minimal-kernel pattern for the compile-out test. `[VERIFIED]`
- **`composer.json`** — current `require`, `require-dev`, `suggest` blocks. `[VERIFIED]`
- **`gh api /repos/symfony/symfony/contents/src/Symfony/Bundle/WebProfilerBundle/Resources/views/Collector/translation.html.twig`** — canonical Twig template structure: blocks `toolbar`, `menu`, `panel`, status colors `red`/`yellow`/`green`/`''`. `[VERIFIED via GitHub API]`
- **`gh api /repos/symfony/symfony/contents/src/Symfony/Bundle/WebProfilerBundle/Resources/views/Icon/translation.svg`** — canonical SVG icon attributes. `[VERIFIED]`
- **`gh api /repos/symfony/symfony/contents/src/Symfony/Bundle/WebProfilerBundle/Resources/views/Profiler/toolbar_item.html.twig`** — confirms `link`/`status`/`icon`/`text` block interface. `[VERIFIED]`
- **`gh api /repos/symfony/web-profiler-bundle/releases`** — stable lines 7.4.x / 8.0.x as of 2026-05-18. `[VERIFIED]`

### Secondary (MEDIUM confidence)

- Symfony 7.x official docs — https://symfony.com/doc/current/profiler/data_collector.html — referenced for `AbstractDataCollector` API but not re-fetched in this session (vendor/ source is authoritative).
- Symfony 7.x `#[AsEventListener]` attribute — bundle's own consistent use across `src/EventListener/*` and `src/Resolver/ConsoleResolver.php` is the authoritative example for this codebase.

### Tertiary (LOW confidence)

- None.

## Metadata

**Confidence breakdown:**

- **Standard stack:** HIGH — every library version verified against vendor/ source or GitHub releases API. WebProfilerBundle constraint `^7.4||^8.0` matches the bundle's existing Symfony matrix.
- **Architecture:** HIGH — every claim about bundle internals verified by reading the actual file. Two CONTEXT.md contradictions surfaced (D-06 mechanism, EventSubscriberInterface phrasing) with concrete resolution paths.
- **Pitfalls:** HIGH — each pitfall ties to a verified Symfony API quirk or a verified bundle convention. Pitfall 1 (kernel.debug from ContainerConfigurator) is THE single most-likely-to-bite landmine; it's grounded directly in vendor/ source.
- **Validation architecture:** HIGH — Nyquist map covers all 5 ROADMAP criteria with ≥2 independent checks each; commands runnable on Wave 0 once Wave 0 files exist.
- **Security domain:** HIGH — DSN-leak defence and compile-out are the two security-relevant controls; both have automated tests planned.

**Research date:** 2026-05-18
**Valid until:** 2026-06-17 (30 days — Symfony 7.x APIs are stable; only risk is a WebProfilerBundle 7.4.x patch that changes a template block name, which would be caught immediately by the WDT integration test).
