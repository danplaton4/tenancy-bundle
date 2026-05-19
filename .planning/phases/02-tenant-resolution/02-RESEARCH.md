# Phase 2: Tenant Resolution - Research

**Researched:** 2026-03-18
**Domain:** Symfony chain-of-responsibility resolver pattern, console option injection, PSR-6 cache in bundles, HttpExceptionInterface
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Resolver contract and chain:**
- `TenantResolverInterface`: single method `resolve(Request $request): ?TenantInterface` for HTTP resolvers. Console resolver has a separate interface or is handled via `ConsoleCommandEvent`.
- `ResolverChain`: chain-of-responsibility — runs resolvers in configured order, first match wins. Stops as soon as one resolver returns a non-null tenant.
- Resolver priority system: hybrid — YAML list `tenancy.resolvers: [host, header, query_param, console]` controls built-in order; custom resolvers use DI tag `tenancy.resolver` with a `priority` attribute.
- `ResolverChainPass`: compiler pass collects tagged `tenancy.resolver` services, builds the ordered chain (analogous to `BootstrapperChainPass` from Phase 1).

**HostResolver — v1 scope: subdomain only:**
- v1 identifies tenants from subdomain only. Custom domain lookup (Tenant.domain column) is deferred to a future phase.
- `app_domain` is optional (null by default): if not set, HostResolver skips entirely.
- `www` prefix: strip `www.` before extraction.
- Path-based tenant identification: to be assessed by researcher.

**Failure behavior:**
- No resolver matches → throw `TenantNotFoundException` → HTTP 404.
- Inactive tenant (`is_active = false`) → throw `TenantInactiveException` → HTTP 403.
- Exception type: domain exceptions that implement Symfony's `HttpExceptionInterface` — NOT extending `NotFoundHttpException` or `AccessDeniedHttpException` directly.
- First match wins. No "override" behavior.

**TenantProvider:**
- `TenantProviderInterface` with default `DoctrineTenantProvider` implementation.
- Resolvers call `TenantProviderInterface::findBySlug(string $slug): ?TenantInterface`.
- Caching via Symfony cache pool (PSR-6/16), `cache.app` or dedicated pool. Cache key: `tenancy.tenant.{slug}`.
- Phase 2 uses default `EntityManagerInterface`. Phase 3 will rewire to landlord EM.
- `TenantProviderInterface` tagged for user replacement via DI decoration or aliasing.

**ConsoleResolver:**
- `--tenant` option added to all console commands automatically via `ConsoleCommandEvent`.
- Missing `--tenant`: silent — no tenant context set.
- ConsoleResolver fires on `ConsoleCommandEvent`, NOT `kernel.request`.
- General app commands only; `tenancy:migrate` and `tenancy:run` manage own context.

**TenantContextOrchestrator wiring:**
- Phase 2 injects `ResolverChain` into `TenantContextOrchestrator::onKernelRequest()`.
- On success: `TenantContext::setTenant()`, then `BootstrapperChain::boot()`, then dispatch `TenantResolved` event.
- `resolvedBy` field in `TenantResolved` carries the FQCN of the winning resolver class.

### Claude's Discretion

- Exact algorithm for multi-segment subdomain extraction beyond `app_domain` stripping.
- Cache pool selection (dedicated `tenancy.cache` pool vs. `cache.app` namespace).
- Internal service IDs for `TenantProvider`, `ResolverChain`.
- Whether `--tenant` option is added in `ConsoleCommandEvent` listener or via a `CompilerPass` on command definitions.
- Exact config key name for `app_domain` — researcher to validate against stancl/tenancy patterns.

### Deferred Ideas (OUT OF SCOPE)

- Custom domain resolution — HostResolver in v1 handles subdomains only. Full custom domain (Tenant.domain column lookup) is a future phase.
- Path-based resolution — `/{tenant}/` URL prefix pattern. Needs router integration; deferred unless research shows it fits cleanly in Phase 2.
- ConsoleResolver `required` flag — configurable whether missing `--tenant` throws vs. silent pass. v1 is always silent.
- Tenant cache TTL config — expose TTL in bundle config. v1 uses a hard-coded default.
- `TenantProviderInterface::findAll()` — for CLI batch commands in Phase 7.
- `OriginHeaderResolver` — v1.1 (RESV-06). Not in this phase.
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| RESV-01 | `HostResolver` identifies the active tenant from subdomain (`tenant.app.com`) by querying the landlord DB | Subdomain extraction algorithm (strip `app_domain` suffix, leftmost segment), `TenantProviderInterface::findBySlug()`, `app_domain` config node |
| RESV-02 | `HeaderResolver` identifies the active tenant from `X-Tenant-ID` HTTP request header | `Request::headers->get('X-Tenant-ID')`, `TenantProviderInterface::findBySlug()` |
| RESV-03 | `QueryParamResolver` identifies the active tenant from `?_tenant=` query parameter | `Request::query->get('_tenant')`, `TenantProviderInterface::findBySlug()` |
| RESV-04 | `ConsoleResolver` identifies the active tenant from `--tenant=ID` CLI option, firing on `ConsoleCommandEvent` | Application-level `addOption` + `mergeApplicationDefinition()` + `bind()` rebind pattern; `ConsoleEvents::COMMAND` tag |
| RESV-05 | Resolver chain is configurable: custom resolvers implementing `TenantResolverInterface` with DI tag priority | `PriorityTaggedServiceTrait`, `tenancy.resolver` tag with `priority` attribute, `registerForAutoconfiguration` |
</phase_requirements>

---

## Summary

Phase 2 builds the complete resolver layer on top of Phase 1's skeleton. The central design is a chain-of-responsibility `ResolverChain` that holds an ordered list of `TenantResolverInterface` implementations. Each HTTP resolver receives the Symfony `Request` object and returns a `TenantInterface` or null. The chain stops at the first non-null return. A `ResolverChainPass` — structured identically to Phase 1's `BootstrapperChainPass` — collects services tagged `tenancy.resolver` and populates the chain.

Four built-in resolvers cover all standard identification patterns: `HostResolver` (subdomain extraction from `Host` header against a configurable `app_domain`), `HeaderResolver` (`X-Tenant-ID` header), `QueryParamResolver` (`?_tenant=` parameter), and `ConsoleResolver` (`--tenant` CLI option via `ConsoleCommandEvent`). All four share a common `TenantProviderInterface` that wraps Doctrine lookups with a short-TTL cache. Failure modes produce `TenantNotFoundException` (HTTP 404) and `TenantInactiveException` (HTTP 403) — both implementing Symfony's `HttpExceptionInterface` directly, not extending the concrete HTTP exception classes.

The `ConsoleResolver` has a subtle technical requirement: the `--tenant` option must be registered on the Application's global input definition (not per-command) so that `mergeApplicationDefinition()` + `bind()` — which Symfony's Application runs _before_ firing `ConsoleCommandEvent` — picks it up correctly. Reading `$input->getOption('tenant')` in the ConsoleCommandEvent listener then works reliably on Symfony 6.4 and 7.x.

**Primary recommendation:** Follow the `BootstrapperChainPass` pattern exactly for `ResolverChainPass`, register `--tenant` on the Application-level definition (not per-command), and centralise the `is_active` guard in `TenantProviderInterface` so individual resolvers stay thin.

---

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| symfony/http-foundation | ^6.4\|^7.0 | `Request` object, headers, query params | Already in composer.json; resolvers receive this type |
| symfony/http-kernel | ^6.4\|^7.0 | `HttpExceptionInterface`, `KernelEvents::REQUEST` | Already in composer.json; exception interface lives here |
| symfony/console | ^6.4\|^7.0 | `ConsoleCommandEvent`, `InputOption`, `ConsoleEvents` | Symfony standard for CLI; ConsoleResolver depends on it |
| symfony/dependency-injection | ^6.4\|^7.0 | `PriorityTaggedServiceTrait`, `CompilerPassInterface` | Already in composer.json; pattern established in Phase 1 |
| symfony/cache | ^6.4\|^7.0 | `CacheInterface` (PSR-6 contracts) via `cache.app` | Standard Symfony cache pool — no extra library needed |
| doctrine/orm | ^3.3 | `EntityManagerInterface` for `DoctrineTenantProvider` | Already in require-dev; used as default EM in Phase 2 |

**Note:** `symfony/console` is a Symfony component that ships with `symfony/framework-bundle` but is not currently listed as a direct `require` in `composer.json`. It must be added as a direct requirement since `ConsoleResolver` uses it at runtime.

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| symfony/contracts | ^3.x | `CacheInterface` type hint in `DoctrineTenantProvider` | Already transitively available via symfony/* components |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `cache.app` pool with key prefix `tenancy.tenant.` | Dedicated `tenancy.cache` pool | Dedicated pool adds one config entry in `framework.cache.pools`; `cache.app` is simpler for Phase 2, dedicated pool is cleaner for Phase 5 cache namespace isolation. Recommendation: use `cache.app` with prefixed keys for now; Phase 5 will introduce namespace isolation at adapter level. |
| `ConsoleCommandEvent` listener for `--tenant` | Compiler pass on each Command definition | Compiler pass approach requires iterating all command definitions at container compile time, adding coupling to every registered command. Event listener approach is cleaner and consistent with how bootstrappers/resolvers work (intercepting at framework level). |
| Implementing `HttpExceptionInterface` directly | Extending `HttpException` base class | Extending `HttpException` tightly couples domain exceptions to `symfony/http-kernel`'s concrete class hierarchy. Implementing the interface directly honors the CONTEXT.md decision and decouples domain exceptions from HTTP class hierarchy. |

**Installation:**
```bash
# symfony/console must be added as a direct require (currently only in require-dev transitively)
composer require symfony/console:"^6.4||^7.0"
```

No additional libraries needed — all other dependencies are already declared.

---

## Architecture Patterns

### Recommended Project Structure

```
src/
├── Resolver/
│   ├── TenantResolverInterface.php       # HTTP resolver contract
│   ├── ResolverChain.php                  # Chain-of-responsibility
│   ├── HostResolver.php                   # Subdomain extraction
│   ├── HeaderResolver.php                 # X-Tenant-ID header
│   ├── QueryParamResolver.php             # ?_tenant= param
│   └── ConsoleResolver.php                # ConsoleCommandEvent listener
├── Provider/
│   ├── TenantProviderInterface.php        # Tenant lookup contract
│   └── DoctrineTenantProvider.php         # Doctrine + cache implementation
├── Exception/
│   ├── TenantNotFoundException.php        # HTTP 404, implements HttpExceptionInterface
│   └── TenantInactiveException.php        # HTTP 403, implements HttpExceptionInterface
└── DependencyInjection/
    └── Compiler/
        ├── BootstrapperChainPass.php      # Phase 1 (existing)
        └── ResolverChainPass.php          # Phase 2 (new, same pattern)
```

### Pattern 1: ResolverChain (Chain of Responsibility)

**What:** An ordered list of `TenantResolverInterface` implementations. Iterates in priority order; returns the first non-null result; throws `TenantNotFoundException` if no resolver matched.

**When to use:** Any time tenant identity must be determined from an arbitrary request context.

**Example:**
```php
// Source: Pattern derived from BootstrapperChain (src/Bootstrapper/BootstrapperChain.php)
final class ResolverChain
{
    /** @var TenantResolverInterface[] */
    private array $resolvers = [];

    public function addResolver(TenantResolverInterface $resolver): void
    {
        $this->resolvers[] = $resolver;
    }

    public function resolve(Request $request): TenantInterface
    {
        foreach ($this->resolvers as $resolver) {
            $tenant = $resolver->resolve($request);
            if ($tenant !== null) {
                return $tenant;
            }
        }
        throw new TenantNotFoundException();
    }

    public function getWinningResolverClass(Request $request): string
    {
        // Alternative: track winner inside resolve() and return with tenant
    }
}
```

**Recommendation:** Track the winning resolver FQCN inside `resolve()` by returning a value object `ResolverResult` that carries both `TenantInterface` and `string $resolvedBy`, rather than making two passes. This is cleaner than a second `getWinningResolverClass()` method.

### Pattern 2: ResolverChainPass (Compiler Pass)

**What:** Collects tagged `tenancy.resolver` services and calls `addResolver()` on the chain definition. Direct clone of `BootstrapperChainPass`.

**When to use:** Container compile time.

**Example:**
```php
// Source: Based on src/DependencyInjection/Compiler/BootstrapperChainPass.php
final class ResolverChainPass implements CompilerPassInterface
{
    use PriorityTaggedServiceTrait;

    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(ResolverChain::class)) {
            return;
        }

        $definition = $container->findDefinition(ResolverChain::class);
        $resolvers = $this->findAndSortTaggedServices('tenancy.resolver', $container);

        foreach ($resolvers as $resolver) {
            $definition->addMethodCall('addResolver', [$resolver]);
        }
    }
}
```

### Pattern 3: TenantResolverInterface

**What:** Contract for all HTTP resolvers. Returns `?TenantInterface` — never throws; the chain handles missing-tenant throwing.

**Example:**
```php
interface TenantResolverInterface
{
    public function resolve(Request $request): ?TenantInterface;
}
```

### Pattern 4: HttpExceptionInterface Implementation for Domain Exceptions

**What:** `TenantNotFoundException` and `TenantInactiveException` implement `HttpExceptionInterface` directly (not extending `HttpException`) to return the correct HTTP status code without coupling to Symfony's concrete exception hierarchy.

**When to use:** Any time a domain exception must map to an HTTP response code.

**Example:**
```php
// Source: Symfony\Component\HttpKernel\Exception\HttpExceptionInterface contract
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class TenantNotFoundException extends \RuntimeException implements HttpExceptionInterface
{
    public function getStatusCode(): int
    {
        return 404;
    }

    public function getHeaders(): array
    {
        return [];
    }
}

final class TenantInactiveException extends \RuntimeException implements HttpExceptionInterface
{
    public function getStatusCode(): int
    {
        return 403;
    }

    public function getHeaders(): array
    {
        return [];
    }
}
```

### Pattern 5: ConsoleResolver — Application-Level Option Registration

**What:** The `--tenant` option must be added to the Application's global input definition BEFORE `ConsoleCommandEvent` fires, so that `mergeApplicationDefinition()` + `bind()` (which Symfony runs before dispatching the event) picks it up. The ConsoleCommandEvent listener then reads `$input->getOption('tenant')` reliably.

**Why this works:** Symfony Application's `doRunCommand()` sequence is:
1. `$command->mergeApplicationDefinition()` — merges application-level options into the command definition
2. `$input->bind($command->getDefinition())` — binds and parses input against the merged definition
3. `ConsoleCommandEvent` dispatched — input is already bound and options are readable

If `--tenant` is added to the Application's definition before this sequence (i.e., at bundle boot or via an `EventListener` that runs on `ConsoleCommandEvent` but adds to Application definition first), then step 2 will bind `--tenant` correctly.

**Example:**
```php
// Source: Verified against symfony/console Application.php 7.3 source
final class ConsoleResolver
{
    #[AsEventListener(event: ConsoleEvents::COMMAND)]
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();
        $input = $event->getInput();

        // Add --tenant to the application definition if not already present
        // This is safe to call multiple times (check hasOption first)
        $appDefinition = $command->getApplication()->getDefinition();
        if (!$appDefinition->hasOption('tenant')) {
            $appDefinition->addOption(
                new InputOption('tenant', null, InputOption::VALUE_OPTIONAL, 'Tenant slug/ID')
            );
            // After modifying the application definition, rebind the input
            $command->mergeApplicationDefinition();
            $input->bind($command->getDefinition());
        }

        $slug = $input->getOption('tenant');
        if ($slug === null || $slug === '') {
            return; // Silent — no tenant context
        }

        // Resolve and set tenant context
        $tenant = $this->tenantProvider->findBySlug((string) $slug);
        $this->tenantContext->setTenant($tenant);
        $this->bootstrapperChain->boot($tenant);
        $this->eventDispatcher->dispatch(
            new TenantResolved($tenant, null, static::class)
        );
    }
}
```

**PITFALL:** Adding the option INSIDE the ConsoleCommandEvent listener (after Symfony has already bound input) means `$input->getOption('tenant')` will throw `InvalidArgumentException: The "tenant" option does not exist`. The solution is to add the option to the Application definition and call `mergeApplicationDefinition()` + `bind()` again inside the listener, OR register the option on the Application definition at an earlier point (e.g., on the `ConsoleEvents::COMMAND` event but before the existing bind attempt — which is what the rebind approach achieves).

### Pattern 6: TenantProviderInterface with Cache

**What:** Wraps Doctrine lookup with a short-TTL cache. `is_active` check is centralised here.

**Example:**
```php
// Source: Symfony cache pool documentation + project patterns
final class DoctrineTenantProvider implements TenantProviderInterface
{
    private const CACHE_TTL = 300; // 5 minutes — not exposed in config for Phase 2

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheInterface $cache,         // injected as cache.app
        private readonly string $tenantEntityClass,
    ) {}

    public function findBySlug(string $slug): TenantInterface
    {
        $tenant = $this->cache->get(
            'tenancy.tenant.' . $slug,
            function (ItemInterface $item) use ($slug): ?TenantInterface {
                $item->expiresAfter(self::CACHE_TTL);
                return $this->entityManager->getRepository($this->tenantEntityClass)
                    ->findOneBy(['slug' => $slug]);
            }
        );

        if ($tenant === null) {
            throw new TenantNotFoundException(sprintf('Tenant "%s" not found.', $slug));
        }

        if (!$tenant->isActive()) {
            throw new TenantInactiveException(sprintf('Tenant "%s" is inactive.', $slug));
        }

        return $tenant;
    }
}
```

### Pattern 7: HostResolver Subdomain Extraction

**What:** Extract the tenant slug from the leftmost subdomain segment that is not part of `app_domain`. If `app_domain` is `null`, skip entirely.

**Algorithm (Claude's discretion — recommended):**
1. If `app_domain` config is null or empty → return null immediately (skip resolver)
2. Strip `www.` prefix from host if present
3. If host does not end with `.{app_domain}` → return null (not on our domain)
4. Strip `.{app_domain}` suffix → remaining is `acme` or `api.acme`
5. Take the rightmost non-empty segment from the remaining prefix as the slug (handles `api.acme.app.com` → `acme`)

**Recommendation:** Take the **last** segment before `app_domain` as the slug. This handles `api.acme.app.com` → slug `acme` naturally (the leftmost being `api`, the segment immediately before `app.com` being `acme`).

**Example:**
```php
// Source: Claude's discretion per CONTEXT.md
private function extractSlug(string $host): ?string
{
    if ($this->appDomain === null) {
        return null;
    }
    $host = strtolower(ltrim($host, 'www.'));
    $suffix = '.' . strtolower($this->appDomain);
    if (!str_ends_with($host, $suffix)) {
        return null;
    }
    $subdomain = substr($host, 0, -strlen($suffix));
    // For "api.acme", take the last segment → "acme"
    $parts = explode('.', $subdomain);
    $slug = end($parts);
    return $slug !== '' && $slug !== false ? $slug : null;
}
```

### Anti-Patterns to Avoid

- **Throwing in TenantResolverInterface::resolve():** Resolvers must return null for "not applicable" signals; only the chain throws `TenantNotFoundException` when no resolver matched. Exception: active-status check in `DoctrineTenantProvider::findBySlug()` is acceptable because it's definitively wrong state.
- **Per-command option registration via compiler pass:** Iterating all command definitions at compile time couples the bundle to every registered command and breaks when lazy commands are used. Use the Application-level definition + ConsoleCommandEvent rebind instead.
- **Checking `is_active` in each resolver separately:** Centralise the check in `DoctrineTenantProvider::findBySlug()` so all resolvers benefit automatically.
- **Hard-coding `X-Tenant-ID` or `_tenant` as constants inside resolvers:** Define them as configurable properties or at least name constants for easy override in future phases.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Ordered tagged service collection | Custom sort loop | `PriorityTaggedServiceTrait::findAndSortTaggedServices()` | Handles priority attribute, `getDefaultPriority()` method, and `AsTaggedItem` attribute; battle-tested across Symfony core |
| HTTP exception → status code mapping | Custom exception handler | Implement `HttpExceptionInterface` directly | Symfony's `ErrorListener` already reads `getStatusCode()` from this interface |
| Cache with TTL and lazy computation | Custom cache wrapper | `Symfony\Contracts\Cache\CacheInterface::get()` with callback | Handles cache miss, computation, and storage in one call; PSR-6 compliant |
| Input definition merging for console options | Manual `InputDefinition` assembly | `$command->mergeApplicationDefinition()` + `$input->bind()` | This is Symfony's own internal mechanism, already available in the event |
| Tenant-aware service autoconfiguration | Manual tag registration per service | `registerForAutoconfiguration(TenantResolverInterface::class)->addTag('tenancy.resolver')` | Automatic discovery without per-service config |

**Key insight:** The compiler pass + `PriorityTaggedServiceTrait` pattern from Phase 1 (`BootstrapperChainPass`) is the canonical solution for ordered tagged service chains in Symfony bundles. `ResolverChainPass` should be a near-copy.

---

## Common Pitfalls

### Pitfall 1: Adding `--tenant` Option Too Late in ConsoleCommandEvent

**What goes wrong:** Adding the option to the command's definition inside the `ConsoleCommandEvent` listener throws `InvalidArgumentException: The "tenant" option does not exist` when calling `$input->getOption('tenant')`, because input was already bound without knowing about the option.

**Why it happens:** Symfony's `Application::doRunCommand()` calls `mergeApplicationDefinition()` + `bind()` BEFORE dispatching `ConsoleCommandEvent`. An option added after this point is not in the bound input's parsed tokens.

**How to avoid:** Add `--tenant` to `$command->getApplication()->getDefinition()`, then call `$command->mergeApplicationDefinition()` and `$input->bind($command->getDefinition())` again inside the listener. This sequence re-parses the input tokens against the updated definition, making `$input->getOption('tenant')` available. Guard with `if (!$appDefinition->hasOption('tenant'))` to be idempotent.

**Warning signs:** `InvalidArgumentException: The "tenant" option does not exist.` at `getOption()` call.

### Pitfall 2: TenantNotFoundException Thrown When No Resolver Is Applicable (Not Just When All Resolvers Tried and Failed)

**What goes wrong:** An HTTP API request with `X-Tenant-ID` configured but no subdomain should not fail if `HostResolver` has null `app_domain` and skips. `TenantNotFoundException` must only fire when every resolver has returned null, not when HostResolver skips due to unconfigured `app_domain`.

**Why it happens:** Confusing "I cannot apply to this request" (null return) with "I looked and found nothing" (throw). All resolvers must use the null-return protocol for "not applicable".

**How to avoid:** Resolvers return `null` for both "not applicable" and "not found by me". Only `ResolverChain` throws `TenantNotFoundException` when the entire chain returns null.

### Pitfall 3: Cache Poisoning on Inactive Tenant

**What goes wrong:** An inactive tenant's null lookup is cached, then the tenant is reactivated; the cache returns null for minutes after reactivation.

**Why it happens:** Caching the null result of `findOneBy(['slug' => $slug])` when tenant doesn't exist is correct, but `DoctrineTenantProvider` should never cache a result that would cause `TenantInactiveException` to fire — or at least, the `is_active` check should occur after the cache retrieval so that reactivation takes effect within one TTL cycle.

**How to avoid:** Cache the raw `TenantInterface` object (including inactive tenants). Run the `is_active` check AFTER retrieving from cache. This way, reactivation reflects within one TTL cycle without requiring cache invalidation.

### Pitfall 4: Integration Test Kernel Missing `symfony/console` Registration

**What goes wrong:** Phase 2 integration tests for `ConsoleResolver` fail with `ConsoleCommandEvent not found` or `Application not bootstrapped` because `TestKernel` doesn't register console commands or the console application.

**Why it happens:** The existing `TestKernel` has no console configuration. `ConsoleResolver` requires a console application to exist.

**How to avoid:** Add a `ConsoleKernel` test variant or register a dummy command in `TestKernel` for ConsoleResolver tests. Alternatively, test `ConsoleResolver` in isolation with a mocked `ConsoleCommandEvent`.

### Pitfall 5: `PriorityTaggedServiceTrait` Sorting Order (Higher = Earlier)

**What goes wrong:** Resolvers execute in inverse expected order. A resolver tagged `priority: 10` runs AFTER one tagged `priority: 5`.

**Why it happens:** In Symfony's `PriorityTaggedServiceTrait`, higher priority numbers run FIRST (sorted descending). This matches kernel event listener convention but is counterintuitive if you think of priority as a simple index.

**How to avoid:** Document in `ResolverChain` docblock: "Higher priority values run first. Default priority is 0." Align with the exact same behavior as `BootstrapperChainPass`.

### Pitfall 6: `TenantProviderInterface` Not Decorated for Phase 3

**What goes wrong:** Phase 3 needs to replace the Doctrine EM in `DoctrineTenantProvider` with the `landlord` EM. If `DoctrineTenantProvider` is wired with `EntityManagerInterface` directly and registered as `TenantProviderInterface`, Phase 3 must swap the entire service rather than just the EM argument.

**How to avoid:** Wire `DoctrineTenantProvider` as the default implementation of `TenantProviderInterface` using a service alias. Phase 3 then decorates or re-aliases without touching Phase 2 code. This is the established Symfony decoration pattern.

---

## Code Examples

Verified patterns from official sources:

### HttpExceptionInterface Implementation
```php
// Source: Symfony HttpKernel HttpExceptionInterface (symfony/http-kernel)
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class TenantNotFoundException extends \RuntimeException implements HttpExceptionInterface
{
    public function __construct(string $message = 'Tenant not found.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int { return 404; }
    public function getHeaders(): array { return []; }
}
```

### Bundle configure() — Adding resolvers List and host.app_domain
```php
// Source: Symfony bundle configuration docs + existing TenancyBundle::configure() pattern
public function configure(DefinitionConfigurator $definition): void
{
    $definition->rootNode()
        ->children()
            // ... existing nodes ...
            ->arrayNode('resolvers')
                ->scalarPrototype()->end()
                ->defaultValue(['host', 'header', 'query_param', 'console'])
            ->end()
            ->arrayNode('host')
                ->children()
                    ->scalarNode('app_domain')->defaultNull()->end()
                ->end()
            ->end()
        ->end()
    ;
}
```

### PriorityTaggedServiceTrait in ResolverChainPass
```php
// Source: Identical pattern to src/DependencyInjection/Compiler/BootstrapperChainPass.php
use Symfony\Component\DependencyInjection\Compiler\PriorityTaggedServiceTrait;

final class ResolverChainPass implements CompilerPassInterface
{
    use PriorityTaggedServiceTrait;

    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(ResolverChain::class)) {
            return;
        }
        $definition = $container->findDefinition(ResolverChain::class);
        foreach ($this->findAndSortTaggedServices('tenancy.resolver', $container) as $resolver) {
            $definition->addMethodCall('addResolver', [$resolver]);
        }
    }
}
```

### Injecting cache.app in services.php
```php
// Source: Symfony cache documentation — cache.app is the default autowirable pool
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

$services->set('tenancy.provider', DoctrineTenantProvider::class)
    ->args([
        service('doctrine.orm.default_entity_manager'),
        service('cache.app'),
        param('tenancy.tenant_entity_class'),
    ]);
$services->alias(TenantProviderInterface::class, 'tenancy.provider');
```

### ConsoleCommandEvent Listener Service Registration
```php
// Source: Symfony console events documentation
// In services.php:
$services->set(ConsoleResolver::class)
    ->autoconfigure(true)
    ->args([
        service('tenancy.provider'),
        service('tenancy.context'),
        service('tenancy.bootstrapper_chain'),
        service('event_dispatcher'),
    ]);
```

---

## Path-Based Resolution Assessment

**Verdict: Defer to future phase. Out of scope for Phase 2.**

Path-based resolution (`/{tenant}/prefix`) requires router integration (custom route loader or route prefix matching) that goes beyond what the `Request` object alone provides. Extracting a tenant from `$request->getPathInfo()` is technically simple, but:

1. It requires URL rewriting conventions that affect every route in the application — a significant developer experience constraint.
2. Subdomain and header approaches cover all stated v1 use cases.
3. CONTEXT.md classifies this as "in scope if research shows it fits cleanly" — it does not fit cleanly, it requires router-level changes.

No `PathResolver` in Phase 2.

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `NotFoundHttpException` for custom "entity not found" exceptions | Implement `HttpExceptionInterface` directly on domain exceptions | Symfony 6.3+ (HTTP exception attributes) | Domain exceptions stay in domain layer; HTTP mapping is a thin interface implementation |
| Manually tagging every resolver service | `registerForAutoconfiguration(TenantResolverInterface::class)->addTag('tenancy.resolver')` | Symfony 5.3+ (autoconfiguration improvements) | Zero-config resolver discovery |
| Building priority-sorted lists manually | `PriorityTaggedServiceTrait::findAndSortTaggedServices()` | Symfony 3.2+ | Automatic priority handling including `getDefaultPriority()` static method on service class |
| Registering console options per command | Application-level `getDefinition()->addOption()` + rebind in ConsoleCommandEvent | Long-standing; confirmed working in Symfony 7.x | One listener handles all commands; zero opt-in for command developers |

**Deprecated/outdated:**
- Extending `NotFoundHttpException` for non-routing domain exceptions: couples domain to HttpFoundation's exception hierarchy.
- Direct EM injection without caching in resolvers: queries landlord DB on every request; cache is mandatory for production.

---

## Open Questions

1. **`symfony/console` as direct dependency**
   - What we know: `console` is currently only in `require-dev` transitively via `symfony/framework-bundle`.
   - What's unclear: Whether `symfony/console` is already available transitively from `symfony/http-kernel` or `symfony/event-dispatcher` at runtime.
   - Recommendation: Check `composer.json` transitive graph. If not transitively available at runtime, add `symfony/console: ^6.4||^7.0` to `require`. For a bundle that adds a `ConsoleCommandEvent` listener, this is a legitimate runtime dependency.

2. **ConsoleResolver and non-HTTP context (e.g., standalone console app without HttpKernel)**
   - What we know: `ConsoleResolver` listens to `ConsoleEvents::COMMAND`, not `KernelEvents::REQUEST`. It is decoupled from HTTP.
   - What's unclear: Whether the `TenantContextOrchestrator` (HTTP listener) should guard against running when `ConsoleResolver` already set the tenant.
   - Recommendation: `TenantContextOrchestrator::onKernelRequest()` checks `$event->isMainRequest()` (already in Phase 1 code). The console path never fires `kernel.request`, so no conflict. No guard needed.

3. **Cache invalidation on tenant update**
   - What we know: Cache TTL is hard-coded at ~5 minutes for Phase 2.
   - What's unclear: Whether Phase 2 should wire a Doctrine event listener to invalidate `tenancy.tenant.{slug}` on `Tenant` entity update.
   - Recommendation: Defer to Phase 5 or Phase 8. Hard-coded TTL is acceptable for Phase 2. Planner should note this as a TODO.

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5 |
| Config file | `phpunit.xml.dist` |
| Quick run command | `./vendor/bin/phpunit --testsuite unit --no-coverage` |
| Full suite command | `./vendor/bin/phpunit --no-coverage` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| RESV-01 | HostResolver extracts subdomain from host and returns correct tenant | unit | `./vendor/bin/phpunit tests/Unit/Resolver/HostResolverTest.php --no-coverage` | ❌ Wave 0 |
| RESV-01 | HostResolver returns null when app_domain is null | unit | `./vendor/bin/phpunit tests/Unit/Resolver/HostResolverTest.php --no-coverage` | ❌ Wave 0 |
| RESV-01 | HostResolver returns null when host does not match app_domain suffix | unit | `./vendor/bin/phpunit tests/Unit/Resolver/HostResolverTest.php --no-coverage` | ❌ Wave 0 |
| RESV-02 | HeaderResolver returns tenant from X-Tenant-ID header | unit | `./vendor/bin/phpunit tests/Unit/Resolver/HeaderResolverTest.php --no-coverage` | ❌ Wave 0 |
| RESV-02 | HeaderResolver returns null when header is absent | unit | `./vendor/bin/phpunit tests/Unit/Resolver/HeaderResolverTest.php --no-coverage` | ❌ Wave 0 |
| RESV-03 | QueryParamResolver returns tenant from ?_tenant= param | unit | `./vendor/bin/phpunit tests/Unit/Resolver/QueryParamResolverTest.php --no-coverage` | ❌ Wave 0 |
| RESV-03 | QueryParamResolver returns null when param is absent | unit | `./vendor/bin/phpunit tests/Unit/Resolver/QueryParamResolverTest.php --no-coverage` | ❌ Wave 0 |
| RESV-04 | ConsoleResolver reads --tenant option and sets tenant context | unit | `./vendor/bin/phpunit tests/Unit/Resolver/ConsoleResolverTest.php --no-coverage` | ❌ Wave 0 |
| RESV-04 | ConsoleResolver is silent when --tenant is absent | unit | `./vendor/bin/phpunit tests/Unit/Resolver/ConsoleResolverTest.php --no-coverage` | ❌ Wave 0 |
| RESV-05 | ResolverChain calls resolvers in priority order, stops at first match | unit | `./vendor/bin/phpunit tests/Unit/Resolver/ResolverChainTest.php --no-coverage` | ❌ Wave 0 |
| RESV-05 | ResolverChain throws TenantNotFoundException when no resolver matches | unit | `./vendor/bin/phpunit tests/Unit/Resolver/ResolverChainTest.php --no-coverage` | ❌ Wave 0 |
| RESV-05 | ResolverChainPass collects tagged resolvers in priority order | unit | `./vendor/bin/phpunit tests/Unit/DependencyInjection/Compiler/ResolverChainPassTest.php --no-coverage` | ❌ Wave 0 |
| RESV-01..04 | Full integration: ResolverChain wired to TenantContextOrchestrator resolves all four resolver types | integration | `./vendor/bin/phpunit tests/Integration/ --no-coverage` | ❌ Wave 0 |

### Sampling Rate

- **Per task commit:** `./vendor/bin/phpunit --testsuite unit --no-coverage`
- **Per wave merge:** `./vendor/bin/phpunit --no-coverage`
- **Phase gate:** Full suite green before `/gsd:verify-work`

### Wave 0 Gaps

- [ ] `tests/Unit/Resolver/HostResolverTest.php` — covers RESV-01
- [ ] `tests/Unit/Resolver/HeaderResolverTest.php` — covers RESV-02
- [ ] `tests/Unit/Resolver/QueryParamResolverTest.php` — covers RESV-03
- [ ] `tests/Unit/Resolver/ConsoleResolverTest.php` — covers RESV-04
- [ ] `tests/Unit/Resolver/ResolverChainTest.php` — covers RESV-05 chain behavior
- [ ] `tests/Unit/DependencyInjection/Compiler/ResolverChainPassTest.php` — covers RESV-05 compiler pass
- [ ] `tests/Unit/Provider/DoctrineTenantProviderTest.php` — covers shared provider used by all resolvers
- [ ] `tests/Unit/Exception/TenantNotFoundExceptionTest.php` — covers HTTP 404 mapping
- [ ] `tests/Unit/Exception/TenantInactiveExceptionTest.php` — covers HTTP 403 mapping
- [ ] Integration test variant with `ResolverChain` wired — extends existing `TestKernel` pattern

---

## Sources

### Primary (HIGH confidence)
- Symfony source: `symfony/console/Application.php` 7.3 — confirmed ConsoleCommandEvent fires AFTER initial bind; rebind pattern in listener is required and works
- Existing codebase: `src/DependencyInjection/Compiler/BootstrapperChainPass.php` — canonical `ResolverChainPass` template
- Existing codebase: `src/EventListener/TenantContextOrchestrator.php` — Phase 2 injection point confirmed at line 36
- Existing codebase: `src/TenancyBundle.php` — config extension pattern; `resolvers` and `host.app_domain` nodes must be added here
- Symfony docs: `https://symfony.com/doc/current/components/config/definition.html` — `arrayNode` + `scalarPrototype` for resolvers list
- Symfony docs: `https://symfony.com/doc/current/cache.html` — `cache.app` pool injection via `CacheInterface`
- Symfony docs: `https://symfony.com/doc/current/service_container/tags.html` — `priority` attribute on `tenancy.resolver` tag

### Secondary (MEDIUM confidence)
- Symfony blog: `https://symfony.com/blog/new-in-symfony-6-3-http-exception-attributes` — confirmed `HttpExceptionInterface` is the right interface for custom HTTP-mapped exceptions
- Tomas Votruba: `https://tomasvotruba.com/blog/2018/09/03/4-ways-to-add-global-option-or-argument-to-symfony-console-application` — comparative analysis of global option patterns; Application-level definition confirmed as most robust
- stancl/tenancy docs: `https://tenancyforlaravel.com/docs/v3/configuration/` — `central_domains` (Laravel equiv of `app_domain`); confirms the standard config key pattern for host-based tenant identification

### Tertiary (LOW confidence)
- Matthias Noback (2013): `https://matthiasnoback.nl/2013/11/symfony2-add-a-global-option-to-console-commands-and-generate-pid-file/` — older but foundational article on Application-level option injection; pattern still valid but article predates Symfony 6+

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — all libraries already in composer.json; only `symfony/console` runtime dependency needs verification
- Architecture patterns: HIGH — `BootstrapperChainPass` is a direct template; ConsoleCommandEvent sequence verified against Symfony 7.3 source
- Pitfalls: HIGH — ConsoleCommandEvent bind timing verified against Application.php source; cache/active-tenant issues are standard patterns
- Config shape: HIGH — verified against existing `TenancyBundle::configure()` pattern and Symfony config definition docs

**Research date:** 2026-03-18
**Valid until:** 2026-04-18 (stable Symfony APIs; 30-day validity)
