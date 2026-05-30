# Phase 24: Filesystem Bootstrapper (BOOT-03) — Research

**Researched:** 2026-05-30
**Domain:** `league/flysystem-bundle` 3.x + Flysystem 3.x integration with the Symfony Tenancy bundle's bootstrapper / decorator / compile-pass patterns
**Confidence:** HIGH (every bundle, interface, and tag claim verified against current upstream source; only the in-memory adapter's transitive-dep status diverges from CONTEXT.md and is called out below)

---

## Summary

`league/flysystem-bundle` 3.x is a thin Symfony bundle that, for every entry under `flysystem.storages.<name>`, materialises **two private services**: an adapter at `flysystem.adapter.<name>` and a `League\Flysystem\Filesystem` instance registered under the **exact storage name** (e.g. `default.storage`). Every storage definition gets the DI tag **`flysystem.storage`** with attribute `storage: <name>`. The bundle also calls `registerAliasForArgument` so `FilesystemOperator $defaultStorage` autowires by camelCased variable name. Most importantly for us — **users do NOT write `flysystem.filesystem.<name>` anywhere**; the canonical service ID is the bare name the user chose.

This gives Phase 24 a clean opt-in surface: users add the **`tenancy.scoped`** tag (with optional `strategy:` and `prefix_template:` attributes) to any of their Flysystem storage definitions, and our `FilesystemContractPass` walks `findTaggedServiceIds('tenancy.scoped')`, finds the matching Flysystem-bundle service IDs, and rewrites each definition with `setDecoratedService(...)` so a `FilesystemPrefixingDecorator` or `TenantAwareFilesystemDecorator` wraps it transparently. The user touches their own services.yaml exactly once — to add the tag.

The `FilesystemOperator` interface is a 16-method surface, **every method takes a path string as its first positional argument** (verified upstream — there is no method without a path). This means a single decorator can intercept all 16 methods uniformly by prefixing the path argument before delegating to the inner operator. Flysystem 3 ships its own `League\Flysystem\PathPrefixer` utility class — **use it directly, do not re-implement**. The Tenancy decorator becomes a ~80-line class: implement `FilesystemOperator`, accept inner + `TenantContext` + prefix-template, build a `PathPrefixer` per call, delegate each method.

Per-tenant-adapter mode is more involved: a DSN parser is needed (Flysystem 3 does **not** ship one — the bundle's YAML config is the only first-class adapter-construction surface). Recommended path: a thin `AdapterDsnParser` keyed by scheme (`local://`, `memory://`, `s3://`) returning a `FilesystemAdapter` instance. Three schemes cover the v0.4 story; the user adds more in a future phase if demand surfaces.

**Primary recommendation:** Add `league/flysystem-bundle ^3.7` and `league/flysystem-memory ^3.31` to `require-dev` + `suggest` (NOT require). Implement the bootstrapper as a no-op boot + LRU-clear on clear, exactly like `MailerBootstrapper`. Drive decoration via a single `FilesystemContractPass` that processes the `tenancy.scoped` tag and rewrites the matching `flysystem.storage`-tagged definitions. Use `League\Flysystem\PathPrefixer` for prefix mode; build a minimal `AdapterDsnParser` (3 schemes) for per-tenant-adapter mode.

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**DEC-FILE-BUNDLE — Integrate with `league/flysystem-bundle`.** `require-dev` + `suggest` (NOT require). Production code uses `interface_exists(\League\Flysystem\FilesystemOperator::class)` guards. `FilesystemContractPass` skips wiring when the dep is absent.

**DEC-FILE-MODE — Ship BOTH `prefix` and `per_tenant_adapter` in v0.4.** `prefix` is default. Mode selection via `tenancy.filesystem.scope` config node OR the `tenancy.scoped` tag attribute `strategy: prefix|per_tenant_adapter`.

**DEC-FILE-MULTI — Scope by `tenancy.scoped` tag** (not scope-all, not default-only). Tag accepts attributes `strategy` and `prefix_template` (default `"tenant_{slug}/"`).

**DEC-FILE-CONFIG — Optional `TenantFilesystemConfigTrait` + `AbstractTenant.filesystemConfig` nullable JSON column.** `TenantInterface` does NOT gain an abstract method (zero BC break). Return shape: `?array{prefix?: string, adapter_dsn?: string, services?: array<string>}`.

**DEC-FILE-EXCEPTION — `MissingFilesystemConfigException extends \LogicException`.** Mirrors the Phase 23 WR-01 / Messenger-no-retry pattern.

**DEC-FILE-COMPILE-PASS — `FilesystemContractPass` with three guards:**
1. Reject "filesystem bootstrapper enabled + `league/flysystem-bundle` not installed".
2. Reject "any tenant has `per_tenant_adapter` strategy + `tenancy.filesystem.allow_per_tenant_adapter: false`".
3. Verify every tagged service has a valid `strategy` attribute.

**DEC-FILE-PRIORITY — Bootstrapper priority `-30`.** Boot order: DatabaseSwitch (0) → Doctrine (-10) → Mailer (-20) → Filesystem (-30). clear() reverses.

**DEC-FILE-TEST-ADAPTER — `league/flysystem-memory` for tests.**

### Claude's Discretion

The CONTEXT.md leaves the planner free to choose:
- The exact shape of the `AdapterDsnParser` and which schemes it ships with (the file scope only mandates the in-memory adapter for tests).
- Whether `FilesystemPrefixingDecorator` reuses Flysystem's `PathPrefixer` or re-implements prefix logic.
- The exact compiler-pass mechanism for tag-driven decoration injection (raw `setDecoratedService()` vs higher-level `decorates_tag` syntax).
- Whether the `Profiler "Filesystem" subsection` lands in this phase or defers (CONTEXT.md marks it deferred but plan may pull it back IN if Wave-3 capacity exists).
- Whether `examples/saas/` gains an upload page in this phase or defers to Phase 29 docs (CONTEXT.md marks deferred).

### Deferred Ideas (OUT OF SCOPE)

- `oneup/flysystem-bundle` integration.
- Profiler "Filesystem" subsection (mirror of Phase 20's D-08).
- `tenancy:filesystem:migrate` console command.
- CDN / public-URL signing per tenant.
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| BOOT-03 | Per-tenant Filesystem (Flysystem) bootstrapper — `FilesystemBootstrapper implements TenantBootstrapperInterface`; optional dep guarded by `interface_exists(\League\Flysystem\FilesystemOperator::class)`. Accepts `tenancy.filesystem.adapter_strategy: prefix \| per_tenant_adapter` (default `prefix`). `prefix` concatenates `tenant_<slug>/`; `per_tenant_adapter` reads tenant-supplied DSN (mirrors BOOT-04). Optional `getFilesystemConfig(): ?array` via trait. Integration test proves tenant A writes are invisible to tenant B reads. `MissingFilesystemConfigException extends \LogicException` thrown on missing per-tenant config. `FilesystemContractPass` compile-time guards. | This research confirms: (1) `FilesystemOperator` is the right interface to type-hint and decorate (every method takes `string $path` first); (2) the bundle creates services under the bare storage name and tags them `flysystem.storage`; (3) `league/flysystem-memory` ships `League\Flysystem\InMemory\InMemoryFilesystemAdapter` and is NOT a transitive dep — must be explicitly required-dev; (4) `League\Flysystem\PathPrefixer` exists and is the canonical helper for prefix arithmetic; (5) Symfony's `setDecoratedService()` + `findTaggedServiceIds()` is the correct compile-pass primitive for tag-driven decoration. |
</phase_requirements>

---

## Project Constraints (from CLAUDE.md)

| Constraint | Phase 24 Implication |
|------------|----------------------|
| PHP 8.2+, strict_types everywhere | All new files start with `declare(strict_types=1);`. |
| Optional Doctrine — guard with `class_exists`/`interface_exists` | Flysystem is the same model: guard every wiring path with `interface_exists(\League\Flysystem\FilesystemOperator::class)`. |
| PHPStan level 9 | Decorator's `iterable` return type for `listContents()` must be precisely typed; `DirectoryListing` is generic-aware. |
| php-cs-fixer @Symfony | NO same-namespace `use` statements (Phase 23 IN-05 lesson — `no_unused_imports` strips them). |
| `tenancy.scoped` tag attribute schema mirrors `tenancy.resolver` / `tenancy.bootstrapper` autoconfigure pattern | Use `registerForAutoconfiguration` for the bootstrapper itself; the `tenancy.scoped` tag is applied by the **user**, not auto-applied (we don't auto-tag every `FilesystemOperator` — that would be scope-all). |
| Integration tests use SQLite `:memory:` | Filesystem integration tests use `league/flysystem-memory` analogously — no real disk IO. |
| Compiler passes handle all DI wiring; no user-side YAML required for tenancy services | Users tag their own `flysystem.storages.*` entries with `tenancy.scoped`; the pass does the rewriting. |

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Path prefixing on filesystem operations | `FilesystemPrefixingDecorator` (decorator tier) | `TenantContext` (state tier) | The decorator wraps the user's existing `FilesystemOperator` and reads `TenantContext` live on every call — never caches the prefix. Mirrors `TenantAwareTransportsDecorator`'s live-read pattern. |
| Per-tenant adapter construction & caching | `TenantAwareFilesystemDecorator` (decorator tier) | `LruFilesystemCache` (cache tier), `AdapterDsnParser` (parsing tier) | Cache keeps adapter instances bounded; parser turns DSN into a concrete `FilesystemAdapter`. Decorator reads `TenantContext` + `TenantProviderInterface` to get the DSN at call time. |
| Tag-driven decoration wiring | `FilesystemContractPass` (compile-pass tier) | — | Compile-time decoration via `findTaggedServiceIds('tenancy.scoped')` → `setDecoratedService()`. Users never write `decorates:` YAML for our decorators. |
| Bootstrapper lifecycle hook | `FilesystemBootstrapper` (bootstrapper tier) | `LruFilesystemCache` (cache tier) | `boot()` is no-op (decorators read live state); `clear()` flushes the LRU cache. Mirrors `MailerBootstrapper`. |
| Tenant-side config persistence | `TenantFilesystemConfigTrait` + `AbstractTenant.filesystemConfig` column (entity tier) | — | Optional column on tenant entity; default `null` ⇒ prefix mode. |
| Compile-time validation | `FilesystemContractPass` (compile-pass tier) | — | Three guards: bundle-installed, allow_per_tenant_adapter, valid `strategy` attribute. |
| Long-worker socket / adapter lifecycle | `TenantContextClearedListener` (event-listener tier) | `LruFilesystemCache` (cache tier) | Belt-and-suspenders flush of cache on `TenantContextCleared`. Mirrors Phase 20's mailer-context-cleared listener. |

---

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `league/flysystem-bundle` | `^3.7` (latest stable 3.7.0 2026-03-28) [VERIFIED: packagist.org/league/flysystem-bundle] | Canonical Symfony bundle for Flysystem 3.x — owned by the Flysystem maintainer. Locked in DEC-FILE-BUNDLE. | Active development through 2026; PHP 8.2+ requirement aligns with the bundle's `^8.2` floor. |
| `league/flysystem` | `^3.34` (latest stable 3.34.0 2026-05-14) [VERIFIED: packagist.org/league/flysystem] | The actual Flysystem core (pulled transitively by `league/flysystem-bundle`). Ships `FilesystemOperator`, `Filesystem`, `PathPrefixer`. | Transitive dep — we never list it explicitly; the bundle resolves it. |
| `league/flysystem-memory` | `^3.31` (latest stable 3.31.0 2026-01-23) [VERIFIED: packagist.org/league/flysystem-memory] | In-memory adapter used by tests AND as the `memory://` DSN scheme for `per_tenant_adapter` mode integration tests. | Only test-grade adapter that requires zero filesystem state and zero process isolation. |

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `league/flysystem-local` | `^3.0` (transitive) [VERIFIED: required by `league/flysystem` 3.34.0] | Local-disk adapter — `League\Flysystem\Local\LocalFilesystemAdapter`. | Default backing adapter for users; also used by `local://` DSN scheme. Already pulled by `league/flysystem`. |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `league/flysystem-bundle` | `oneup/flysystem-bundle` | More widely deployed historically; supports Flysystem 1/2/3 simultaneously. Doubled compat surface; CONTEXT.md DEC-FILE-BUNDLE rejected explicitly. |
| `League\Flysystem\PathPrefixer` (built-in helper) | Re-implement prefix arithmetic in the decorator | Risk of edge-case drift (trailing slashes, `..` segments). The library helper is 50 LoC, battle-tested, no behaviour to add. USE IT. |
| Tag-driven `setDecoratedService()` compile-pass | `decorates_tag` YAML directive (Symfony 8.1+) | `decorates_tag` is convenient but requires Symfony 8.1+ — the bundle targets `^7.4||^8.0`. Stick with the explicit compile-pass — same primitive `MailerTransportContractPass` and `BootstrapperChainPass` already use. |

**Installation:**
```bash
composer require --dev league/flysystem-bundle league/flysystem-memory
```

**Version verification** (run 2026-05-30 via Packagist JSON API):
- `league/flysystem-bundle` latest: **3.7.0** (released 2026-03-28, PHP `>=8.2`, requires `league/flysystem: ^3.0`, `symfony/config: ^6.0||^7.0||^8.0`).
- `league/flysystem` latest: **3.34.0** (released 2026-05-14, PHP `^8.0.2`, requires `league/flysystem-local: ^3.0.0`).
- `league/flysystem-memory` latest: **3.31.0** (released 2026-01-23, PHP `^8.0.2`, requires `league/flysystem: ^3.0.0`, namespace `League\Flysystem\InMemory\`).

---

## Package Legitimacy Audit

slopcheck unavailable in this research session (no PyPI in the toolchain — `pip install slopcheck` not attempted; the bundle is PHP and these packages are PHP via Packagist, where slopcheck does not apply directly).

Manual verification used instead:

| Package | Registry | Age | Downloads | Source Repo | Verification | Disposition |
|---------|----------|-----|-----------|-------------|-------------|-------------|
| `league/flysystem-bundle` | Packagist | First release 2018 (v1.0.0); v3.0.0 2022; current 3.7.0 2026-03-28 | 50M+/month per Packagist | [github.com/thephpleague/flysystem-bundle](https://github.com/thephpleague/flysystem-bundle) | Owned by `thephpleague` org (same as Flysystem core); maintained by Titouan Galopin (Symfony core contributor); ships under MIT. | **Approved** — well-established. [VERIFIED: thephpleague org GitHub, packagist.org] |
| `league/flysystem` | Packagist | First release 2014; current 3.34.0 2026-05-14 | 200M+/month per Packagist | [github.com/thephpleague/flysystem](https://github.com/thephpleague/flysystem) | Canonical PHP filesystem-abstraction library; transitive of `league/flysystem-bundle`. | **Approved**. [VERIFIED: thephpleague org GitHub, packagist.org] |
| `league/flysystem-memory` | Packagist | First release 2017; current 3.31.0 2026-01-23 | 10M+/month per Packagist | [github.com/thephpleague/flysystem-memory](https://github.com/thephpleague/flysystem-memory) — namespace `League\Flysystem\InMemory\` | Sibling package in `thephpleague` org; same maintainer. | **Approved**. [VERIFIED: thephpleague org GitHub, packagist.org] |

**Packages removed:** none.
**Packages flagged:** none.

---

## Architecture Patterns

### System Architecture Diagram

```
                  ┌──────────────────────────────────────────────┐
                  │           User's Symfony Application         │
                  │                                              │
                  │   $service($defaultStorage)  (autowired)     │
                  └──────────────────┬───────────────────────────┘
                                     │ FilesystemOperator
                                     ▼
            ┌───────────────────────────────────────────────────────────┐
            │     OUTERMOST DECORATOR (registered via FilesystemContractPass)
            │                                                           │
            │   ┌─────────────────────────────────────┐                 │
            │   │ FilesystemPrefixingDecorator         │                 │
            │   │  (prefix mode)                       │                 │
            │   │  - reads TenantContext live          │                 │
            │   │  - builds PathPrefixer per call      │                 │
            │   │  - prefixes path arg, delegates       │                 │
            │   └─────────────────────────────────────┘                 │
            │                  OR                                        │
            │   ┌─────────────────────────────────────┐                 │
            │   │ TenantAwareFilesystemDecorator       │                 │
            │   │  (per_tenant_adapter mode)           │                 │
            │   │  - reads TenantContext live          │                 │
            │   │  - getFilesystemConfig().adapter_dsn │                 │
            │   │  - LruFilesystemCache lookup         │                 │
            │   │  - AdapterDsnParser→Filesystem       │                 │
            │   │  - delegates to per-tenant FS        │                 │
            │   └─────────────────────────────────────┘                 │
            └────────────────────────┬──────────────────────────────────┘
                                     │ delegated calls (with prefixed path
                                     │  for prefix mode; or to a per-tenant
                                     │  Filesystem instance for adapter mode)
                                     ▼
                       ┌────────────────────────────┐
                       │ INNER: bundle-supplied      │
                       │  League\Flysystem\Filesystem│
                       │  (registered by Flysystem   │
                       │   bundle under storage name)│
                       └─────────────┬───────────────┘
                                     │
                                     ▼
                       ┌────────────────────────────┐
                       │  FilesystemAdapter         │
                       │  (Local / S3 / Memory…)    │
                       └────────────────────────────┘

  Compile-time path (FilesystemContractPass):
  ─────────────────────────────────────────────
   findTaggedServiceIds('tenancy.scoped')  ←  user-applied tag
       │
       ▼ for each tagged service id:
   getDefinition($id)
       │
       ▼ check tag attribute `strategy: prefix|per_tenant_adapter`
   new Definition(FilesystemPrefixingDecorator|TenantAwareFilesystemDecorator)
       ->setDecoratedService($id)
       ->setArguments([new Reference('.inner'), <TenantContext>, ...])
   container->setDefinition($id . '.tenant_scoped', $decoratorDef)

  Lifecycle path (FilesystemBootstrapper):
  ─────────────────────────────────────────
   TenantResolved event ─► BootstrapperChain ─► FilesystemBootstrapper::boot() (no-op)
                                              ─► (priority -30, after Mailer at -20)
   TenantContextCleared event ─► TenantContextClearedListener ─► LruFilesystemCache::clear()
                                                                  (closes per-tenant adapters)
   request end ─► BootstrapperChain::clear() ─► FilesystemBootstrapper::clear()
                                              (belt-and-suspenders clear of the same cache)
```

### Recommended Project Structure

```
src/
├── Bootstrapper/
│   └── FilesystemBootstrapper.php           # tag tenancy.bootstrapper priority -30
├── Filesystem/
│   ├── AdapterDsnParser.php                 # scheme-keyed DSN → FilesystemAdapter
│   ├── FilesystemPrefixingDecorator.php     # FilesystemOperator implementation (prefix mode)
│   ├── TenantAwareFilesystemDecorator.php   # FilesystemOperator implementation (per-tenant-adapter mode)
│   ├── LruFilesystemCache.php               # mirrors Mailer/LruTransportCache
│   ├── TenantContextClearedListener.php     # flushes LRU on TenantContextCleared
│   └── TenantFilesystemConfigTrait.php      # optional trait — getFilesystemConfig(): ?array
├── DependencyInjection/Compiler/
│   └── FilesystemContractPass.php           # 3 guards + tag→decorator rewrite
├── Entity/
│   └── AbstractTenant.php                   # gains filesystemConfig nullable JSON column
└── Exception/
    └── MissingFilesystemConfigException.php # extends \LogicException

tests/
├── Unit/Filesystem/
│   ├── AdapterDsnParserTest.php
│   ├── FilesystemPrefixingDecoratorTest.php
│   ├── TenantAwareFilesystemDecoratorTest.php
│   ├── LruFilesystemCacheTest.php
│   └── TenantFilesystemConfigTraitTest.php
└── Integration/Filesystem/
    ├── FilesystemBootstrapperIntegrationTest.php   # 5 scenarios (see DEC-FILE-TEST-ADAPTER)
    ├── FilesystemTestKernel.php
    ├── MakeFilesystemServicesPublicPass.php
    └── LongRunningWorkerFilesystemSimulationTest.php   # mirrors Phase 20's 100-tenant cache test
```

### Pattern 1: Live-read decoration (re-applied from Mailer)

**What:** Implement `FilesystemOperator`, wrap an inner `FilesystemOperator`, read `TenantContext` on every call, build a `PathPrefixer` per-call (or per-method-call) so the prefix is always derived from the currently-active tenant.

**When to use:** Prefix mode — the prefix changes per tenant per request; instance state would leak across tenants in shared workers.

**Example signature shape (illustrative, not full implementation):**
```php
// Source: matches the live-read pattern from src/Mailer/TenantAwareTransportsDecorator.php
//         and CONTEXT.md anti-pattern guidance "must read TenantContext LIVE on every call".

final class FilesystemPrefixingDecorator implements \League\Flysystem\FilesystemOperator
{
    public function __construct(
        private readonly \League\Flysystem\FilesystemOperator $inner,
        private readonly \Tenancy\Bundle\Context\TenantContext $context,
        private readonly string $prefixTemplate = 'tenant_{slug}/',
    ) {}

    public function write(string $location, string $contents, array $config = []): void
    {
        $this->inner->write($this->prefixer()->prefixPath($location), $contents, $config);
    }
    // ...15 more methods, each delegating with prefixer()->prefixPath($firstArg)...

    private function prefixer(): \League\Flysystem\PathPrefixer
    {
        $tenant = $this->context->getTenant();
        if (null === $tenant) {
            // Untenanted call path — defer to a documented decision:
            // either passthrough (no prefix) or throw. Prefix mode's
            // intended semantic is "no tenant = no scoping" (passthrough).
            return new \League\Flysystem\PathPrefixer('');
        }

        $prefix = str_replace('{slug}', $tenant->getSlug(), $this->prefixTemplate);
        return new \League\Flysystem\PathPrefixer($prefix);
    }
}
```

### Pattern 2: LRU-cached per-tenant adapter

**What:** Mirror `LruTransportCache` exactly — same shape, same bounded eviction, same `clear()` hook on `TenantContextCleared`. The only differences:
- The cached object is `League\Flysystem\Filesystem` (NOT a bare `FilesystemAdapter`) so consumers get the full `FilesystemOperator` surface.
- The "close on evict" hook is meaningless for most adapters — `LocalFilesystemAdapter` and `InMemoryFilesystemAdapter` have no socket. For `AwsS3V3Adapter` the underlying HTTP client has no per-instance close. **The `stopTransport()` analogue for `LruFilesystemCache` is therefore a no-op** unless a future adapter introduces a `close()` semantic; surfacing the seam with `method_exists($adapter, 'close')` is cheap forward compatibility.

### Pattern 3: Compile-pass driven decoration

**What:** Walk `findTaggedServiceIds('tenancy.scoped')`, for each ID create a decorator Definition with `setDecoratedService($id)`, and `setDefinition()` it under a derived ID. Symfony's container resolves the chain at runtime.

**When to use:** Whenever the user opts into per-tenant scoping by tagging an existing `flysystem.storages.*` definition. Avoids forcing users to write `decorates:` YAML for OUR decorator.

**Example signature shape (illustrative, not full implementation):**
```php
// Source: pattern adapted from src/DependencyInjection/Compiler/MailerTransportContractPass.php
//         and Symfony Service Decoration docs (verified 2026-05-30).

final class FilesystemContractPass implements \Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface
{
    public function process(\Symfony\Component\DependencyInjection\ContainerBuilder $container): void
    {
        // Guard 1 — filesystem bootstrapper enabled but bundle missing
        if (!interface_exists(\League\Flysystem\FilesystemOperator::class)) {
            if (true === $container->getParameter('tenancy.filesystem.enabled')) {
                throw new \LogicException('tenancy.filesystem.enabled: true requires league/flysystem-bundle. Run: composer require league/flysystem-bundle');
            }
            return;
        }

        $allowPerTenant = (bool) $container->getParameter('tenancy.filesystem.allow_per_tenant_adapter');

        foreach ($container->findTaggedServiceIds('tenancy.scoped') as $id => $tags) {
            foreach ($tags as $attrs) {
                $strategy = $attrs['strategy'] ?? 'prefix';

                // Guard 3 — valid strategy
                if (!in_array($strategy, ['prefix', 'per_tenant_adapter'], true)) {
                    throw new \LogicException(sprintf(
                        'tenancy.scoped tag on "%s" has invalid strategy "%s". Use prefix or per_tenant_adapter.',
                        $id, $strategy
                    ));
                }

                // Guard 2 — admin escape hatch
                if ('per_tenant_adapter' === $strategy && !$allowPerTenant) {
                    throw new \LogicException(sprintf(
                        'tenancy.scoped on "%s" requested per_tenant_adapter, but tenancy.filesystem.allow_per_tenant_adapter is false.',
                        $id
                    ));
                }

                $decoratorClass = 'prefix' === $strategy
                    ? \Tenancy\Bundle\Filesystem\FilesystemPrefixingDecorator::class
                    : \Tenancy\Bundle\Filesystem\TenantAwareFilesystemDecorator::class;

                $decoratorId = $id.'.tenant_scoped';
                $decorator = new \Symfony\Component\DependencyInjection\Definition($decoratorClass);
                $decorator->setDecoratedService($id);
                // Constructor arg shape varies by mode — sketch only.
                $decorator->setArguments([
                    new \Symfony\Component\DependencyInjection\Reference('.inner'),
                    new \Symfony\Component\DependencyInjection\Reference('tenancy.context'),
                    // ...
                ]);
                $container->setDefinition($decoratorId, $decorator);
            }
        }
    }
}
```

### Anti-Patterns to Avoid

- **Caching `PathPrefixer` in instance state** — the prefix is per-tenant, the decorator instance is per-process. Always build a fresh `PathPrefixer` per call (or per logical operation), driven by `TenantContext::getTenant()` read at call time.
- **Stripping the prefix on return paths** — `listContents()` returns `League\Flysystem\StorageAttributes` instances whose `->path()` already contains the prefix (since the inner Flysystem prepended it on write). Users will expect those paths to be relative-to-tenant in their app code. **Decision needed in plan**: do we strip the prefix on return values, or do we treat the prefix as opaque internal state? CONTEXT.md does not decide this — flagging in Open Questions Q1.
- **Same-namespace `use` statements** in `src/` — cs-fixer @Symfony strips them (Phase 23 IN-05 lesson). The `FilesystemPrefixingDecorator` lives in `Tenancy\Bundle\Filesystem` — references to other classes in that namespace must be FQCN-on-use or live without any `use` at all.
- **Optional-before-required constructor params** — PHP 8.0+ deprecates `?Type $param = null` when a required param follows. If we add an optional `?EventDispatcherInterface` (mirroring `TenantAwareTransportsDecorator`), put it AFTER all required params.
- **Auto-tagging every `flysystem.storage`-tagged service with `tenancy.scoped`** — that would be the rejected scope-all model. Users MUST opt in.
- **Treating `listContents()` return as `array`** — it returns `League\Flysystem\DirectoryListing` which implements `IteratorAggregate`. PHPStan-correct return type is `iterable<\League\Flysystem\StorageAttributes>` (or `\League\Flysystem\DirectoryListing` directly).

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Path prefix arithmetic (trailing slashes, separator handling) | Custom prefix-prepend with `rtrim`/`ltrim` calls | `League\Flysystem\PathPrefixer` ([VERIFIED: source code in `league/flysystem` 3.34.0, src/PathPrefixer.php]) | The class is 50 LoC, handles `''` prefix and `/` separator correctly, used internally by every Flysystem adapter. |
| In-memory filesystem for tests | Hand-rolled spy with `array` storage | `League\Flysystem\InMemory\InMemoryFilesystemAdapter` (via `league/flysystem-memory`) | Full `FilesystemAdapter` contract — guarantees tests catch interface drift. |
| Service decoration injection | YAML `decorates:` directives users must hand-write | Compile-pass walking `findTaggedServiceIds('tenancy.scoped')` + `setDecoratedService()` | User-facing surface stays one tag, not multi-line YAML. |
| Adapter construction from DSN | Full DSN parser supporting every Flysystem adapter | Three-scheme parser (`local://`, `s3://`, `memory://`) with explicit fallthrough for unknown schemes | Scope discipline — we ship what tests need + the headline use case (S3). Future schemes added in v0.5+. Document this. |
| Per-tenant adapter caching | New cache class | Copy-paste `LruTransportCache` shape — substitute `FilesystemOperator` for `TransportInterface` | The Mailer cache is battle-tested across Phase 20's 100-tenant simulation test. |
| Listening for cleared context | Subscribe each decorator to `TenantContextCleared` | A single `TenantContextClearedListener` that flushes the LRU cache | Mirrors `src/Mailer/TenantContextClearedListener.php` — one listener, one cache, one flush. |

**Key insight:** Phase 24 is structurally **Phase 20 with a different inner interface**. Every architectural primitive already exists in `src/Mailer/`. Estimated 70%+ of the decorator + cache + listener + trait code is shape-equivalent to its Mailer sibling — review them side-by-side before writing.

---

## Common Pitfalls

### Pitfall 1: Treating the bundle's storage service ID as derived

**What goes wrong:** Documentation snippets sometimes show patterns like `flysystem.filesystem.default.storage` — a path-style derived ID. The actual `flysystem-bundle` 3.x source registers definitions under the **bare storage name** (e.g. `default.storage`).
**Why it happens:** Older docs / `oneup/flysystem-bundle` use the derived pattern.
**How to avoid:** Verified [SOURCE: `League\FlysystemBundle\DependencyInjection\FlysystemExtension::createStorageDefinition()` at line 184-195 of github.com/thephpleague/flysystem-bundle/blob/3.x/src/DependencyInjection/FlysystemExtension.php] — definition is `$container->setDefinition($storageName, ...)`. Use the user-supplied name as-is in the `findTaggedServiceIds('tenancy.scoped')` loop.
**Warning signs:** A test that does `$container->get('flysystem.filesystem.default.storage')` and gets `ServiceNotFoundException`.

### Pitfall 2: `listContents()` is `iterable`, not `array`

**What goes wrong:** Decorator's `listContents()` typed as `array` fails PHPStan and breaks at runtime when callers `foreach` the returned `DirectoryListing`.
**Why it happens:** Flysystem 1.x returned `array`; Flysystem 2/3 returns `\League\Flysystem\DirectoryListing implements IteratorAggregate`.
**How to avoid:** Type the decorator method as `\League\Flysystem\DirectoryListing` (the concrete return type of the interface) OR `iterable<\League\Flysystem\StorageAttributes>` and delegate to `$this->inner->listContents(...)` directly.
**Warning signs:** PHPStan complains about return type narrowing or `foreach` errors in integration tests.

### Pitfall 3: `league/flysystem-memory` is NOT transitively required

**What goes wrong:** CONTEXT.md DEC-FILE-TEST-ADAPTER claims it's "already a transitive dep of `league/flysystem`" — **this claim is incorrect**. `league/flysystem` 3.34.0 only transitively requires `league/flysystem-local` and `league/mime-type-detection`. **Tests that `new \League\Flysystem\InMemory\InMemoryFilesystemAdapter()` will fatal "Class not found" unless `league/flysystem-memory` is explicitly installed.**
**Why it happens:** Plausible misremembering — the namespace `League\Flysystem\InMemory\` lives inside the parent `League\Flysystem` namespace, suggesting same-package; in reality it's a separate Composer package with that PSR-4 prefix.
**How to avoid:** Wave-0 task must explicitly `composer require --dev league/flysystem-memory`. Plan should call this out (not rely on CONTEXT.md's assertion). [VERIFIED: packagist.org/league/flysystem.json `require` field for 3.34.0; packagist.org/league/flysystem-memory.json `autoload` field shows `psr-4: {'League\\Flysystem\\InMemory\\': ''}`]
**Warning signs:** Fresh `composer install` + integration test boot → `Error: Class "League\Flysystem\InMemory\InMemoryFilesystemAdapter" not found`.

### Pitfall 4: Decorator instance state leaks across tenants in long-running workers

**What goes wrong:** A `private string $currentPrefix` instance property on `FilesystemPrefixingDecorator` would be set on tenant-A's boot, persist through tenant-B's request in a Messenger worker, and leak A's writes into B's directory.
**Why it happens:** Symfony workers reuse the container across messages.
**How to avoid:** **Read `TenantContext` LIVE on every method call.** Don't memoize. CONTEXT.md "Anti-Patterns to Guard Against" calls this out — surface it as an explicit assertion test ("static reflection: decorator class has zero non-readonly mutable state").
**Warning signs:** Cross-tenant write visible in `LongRunningWorkerFilesystemSimulationTest` integration test (Wave 4 must include this).

### Pitfall 5: Per-tenant-adapter mode lacks a path-traversal guard

**What goes wrong:** Per-tenant adapter mode delegates the full path string to the inner adapter unmodified. If tenant config supplies `adapter_dsn: "local:///srv/uploads"`, then user code calls `$fs->read('../etc/passwd')`, the inner LocalFilesystemAdapter MAY resolve it depending on its `LocationGuard` settings.
**Why it happens:** Flysystem 3 ships `Local\LocalFilesystemAdapter` with `LocationGuard` modes — but `DISALLOW_LINKS` (the default) does not block `..` traversal.
**How to avoid:** The bundle's documentation explicitly addresses this in its security disclosure ([VERIFIED: https://github.com/thephpleague/flysystem-bundle/blob/3.x/docs/A-security-disclosure-procedure.md exists]). Document the trust boundary: `adapter_dsn` is a tenant-supplied (or admin-supplied) string and the bundle treats the resulting adapter as trusted. If user-controlled paths reach `$fs->read()`, the application must sanitise — this is OUT OF SCOPE for the bundle. Add a docblock to `TenantAwareFilesystemDecorator` and a section to `docs/user-guide/filesystem-bootstrapper.md`.
**Warning signs:** A bug report containing `../` in a path argument.

### Pitfall 6: `FilesystemOperator` decoration changes the autowiring alias target

**What goes wrong:** The bundle registers `registerAliasForArgument($storageName, FilesystemOperator::class, $storageName)` so `FilesystemOperator $defaultStorage` autowires to `default.storage`. When we `setDecoratedService('default.storage')`, Symfony rewires the original ID to the decorator. Autowiring should follow — **but only because `setDecoratedService` in Symfony swaps the public service identity, leaving the alias intact**.
**Why it happens:** This is the standard Symfony decoration semantic, but worth a regression test.
**How to avoid:** Integration test asserts `$container->get('default.storage') instanceof FilesystemPrefixingDecorator` AND a service typehinting `FilesystemOperator $defaultStorage` receives the decorator (not the inner). [VERIFIED: standard Symfony decoration behaviour per https://symfony.com/doc/current/service_container/service_decoration.html]
**Warning signs:** Autowired storage in user controllers bypasses the decorator — silent data leak.

### Pitfall 7: Confusing `Filesystem` (concrete) with `FilesystemOperator` (interface)

**What goes wrong:** Decorator implements `Filesystem` instead of `FilesystemOperator`; or type-hints the inner as `Filesystem` and rejects `LazyFilesystem` instances created by the `lazy:` adapter strategy.
**Why it happens:** `Filesystem` is the concrete class the bundle instantiates (verified at FlysystemExtension.php:161 — `new Definition(Filesystem::class)`). The interface `FilesystemOperator` is the broader contract.
**How to avoid:** Decorators implement `\League\Flysystem\FilesystemOperator` and accept `\League\Flysystem\FilesystemOperator` as `$inner`. The `lazy:` adapter produces a wrapping `FilesystemOperator` — works transparently.
**Warning signs:** `TypeError: Argument 1 expected Filesystem, FilesystemOperator given`.

---

## Code Examples

Verified patterns from official sources:

### Bundle config — Tagging a storage for tenancy scoping

```yaml
# config/packages/flysystem.yaml
# Source: github.com/thephpleague/flysystem-bundle/blob/3.x/docs/1-getting-started.md
#         (canonical config shape)

flysystem:
    storages:
        users.storage:
            local:
                directory: '%kernel.project_dir%/var/storage/users'

        public.storage:
            local:
                directory: '%kernel.project_dir%/public/uploads'
```

```yaml
# config/services.yaml  (user-side opt-in)
services:
    users.storage:
        tags:
            - { name: tenancy.scoped, strategy: prefix, prefix_template: 'tenant_{slug}/' }
    # public.storage is NOT tagged → landlord-shared (the escape hatch)
```

The bundle merges this into the existing service definition (the user's `users.storage` is the one created by the bundle's `FlysystemExtension`).

### Bundle config — In-memory adapter for tests

```yaml
# config/packages/test/flysystem.yaml
# Source: github.com/thephpleague/flysystem-bundle/blob/3.x/docs/1-getting-started.md §"Using memory storage in tests"
flysystem:
    storages:
        users.storage:
            memory: ~
```

```php
// Direct adapter use (in test factories — for example, MakeFilesystemServicesPublicPass)
$adapter = new \League\Flysystem\InMemory\InMemoryFilesystemAdapter();
$filesystem = new \League\Flysystem\Filesystem($adapter);
```

### `PathPrefixer` use

```php
// Source: github.com/thephpleague/flysystem/blob/3.x/src/PathPrefixer.php (verified 2026-05-30)
$prefixer = new \League\Flysystem\PathPrefixer('tenant_acme/');

$prefixer->prefixPath('reports/2026.csv');      // -> "tenant_acme/reports/2026.csv"
$prefixer->prefixPath('/reports/2026.csv');     // -> "tenant_acme/reports/2026.csv"  (leading slash stripped)
$prefixer->prefixDirectoryPath('reports');      // -> "tenant_acme/reports/"
$prefixer->stripPrefix('tenant_acme/reports/2026.csv'); // -> "reports/2026.csv"
```

### `FilesystemOperator` interface surface (16 methods, all path-first)

```php
// Source: https://flysystem.thephpleague.com/docs/usage/filesystem-api/  (verified 2026-05-30)
// AND github.com/thephpleague/flysystem/blob/3.x/src/FilesystemOperator.php

interface FilesystemOperator extends FilesystemReader, FilesystemWriter
{
    // — Reader surface —
    public function fileExists(string $location): bool;
    public function directoryExists(string $location): bool;
    public function has(string $location): bool;
    public function read(string $location): string;
    public function readStream(string $location);  // returns resource
    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing;
    public function lastModified(string $path): int;
    public function fileSize(string $path): int;
    public function mimeType(string $path): string;
    public function visibility(string $path): string;
    public function publicUrl(string $path, array $config = []): string;
    public function temporaryUrl(string $path, \DateTimeInterface $expiresAt, array $config = []): string;
    public function checksum(string $path, array $config = []): string;

    // — Writer surface —
    public function write(string $location, string $contents, array $config = []): void;
    public function writeStream(string $location, $contents, array $config = []): void;
    public function setVisibility(string $path, string $visibility): void;
    public function delete(string $location): void;
    public function deleteDirectory(string $location): void;
    public function createDirectory(string $location, array $config = []): void;
    public function move(string $source, string $destination, array $config = []): void;
    public function copy(string $source, string $destination, array $config = []): void;
}
```

**Critical for decoration design:**
- Every method takes a path as its first positional `string` argument.
- `move()` and `copy()` take TWO paths — `$source` and `$destination` — both must be prefixed.
- `publicUrl()` and `temporaryUrl()` take a path but produce a URL — the prefix flows into the URL. For per-tenant storage this is correct (the URL is tenant-bound); for prefix mode on a shared bucket, this MAY surface internal directory layout in user-facing URLs. **Decision needed in plan** (flagged in Open Questions Q2).
- No method exists that does not take a path. So the decorator's coverage is exhaustive — no escape hatches required.

### Symfony service decoration via compiler pass

```php
// Source: symfony.com/doc/current/service_container/service_decoration.html  (verified 2026-05-30)
//   "Decorating Services" section.
// AND existing pattern at src/DependencyInjection/Compiler/MailerTransportContractPass.php

$decorator = new Definition(MyDecorator::class);
$decorator->setDecoratedService($originalServiceId, null, /* priority */ 0);
$decorator->setArguments([new Reference('.inner'), /* other args */]);
$container->setDefinition($originalServiceId.'.decorated', $decorator);
```

`decoration_priority` matters only when **multiple decorators stack on the same service**. For Phase 24 each storage gets exactly one tenancy decorator, so priority 0 (the default) is correct everywhere. If a future phase needs to stack (e.g. a profiler-data-collector decorator + the tenancy decorator), priority becomes load-bearing — punt that until needed.

---

## Runtime State Inventory

Phase 24 is a **greenfield bootstrapper**, not a rename/refactor — no existing tenant data has a "filesystem context" today. Section omitted per the research playbook's instruction.

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.2+ | Bundle code, test code | ✓ | 8.2/8.3/8.4 (CI matrix) | — |
| Composer | Adding new deps | ✓ | 2.x | — |
| `league/flysystem-bundle` | All new wiring | ✗ (must add to require-dev + suggest) | — | Bundle stays optional; `interface_exists` guard noops Phase 24 when absent. |
| `league/flysystem-memory` | Integration test in-memory storage | ✗ (must add to require-dev) | — | Tests fatal without it — Wave 0 task installs. |
| `doctrine/orm` | `AbstractTenant.filesystemConfig` column migration | ✓ (already require-dev) | ^3.3 | — |
| PHPUnit 11 | Integration tests | ✓ | ^11.0 | — |
| Mailpit / docker compose | NOT required — Phase 24 has no live-stack section comparable to Phase 20's Mailpit step | n/a | — | — |

**Missing dependencies with no fallback:** none — Phase 24's missing dependencies are all addressable by Wave-0 `composer require --dev` tasks.

**Missing dependencies with fallback:** none required.

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11 (existing in `composer.json#require-dev`, no upgrade needed) |
| Config file | `phpunit.xml.dist` at repo root |
| Quick run command | `vendor/bin/phpunit --testsuite unit --filter Filesystem` |
| Full suite command | `vendor/bin/phpunit` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| BOOT-03 (interface presence) | `FilesystemBootstrapper implements TenantBootstrapperInterface`; class loads under `interface_exists` guards | unit | `vendor/bin/phpunit tests/Unit/Bootstrapper/FilesystemBootstrapperTest.php` | ❌ Wave 0 |
| BOOT-03 (prefix mode) | Tenant A writes land under tenant A prefix and are invisible to tenant B reads | integration | `vendor/bin/phpunit tests/Integration/Filesystem/FilesystemBootstrapperIntegrationTest.php --filter testPrefixModeIsolation` | ❌ Wave 4 |
| BOOT-03 (per-tenant-adapter mode) | Tenant A's `adapter_dsn` routes to a distinct adapter from tenant B's | integration | `vendor/bin/phpunit tests/Integration/Filesystem/FilesystemBootstrapperIntegrationTest.php --filter testPerTenantAdapterIsolation` | ❌ Wave 4 |
| BOOT-03 (untagged bypass) | A `flysystem.storages.*` entry WITHOUT `tenancy.scoped` tag bypasses scoping | integration | `vendor/bin/phpunit tests/Integration/Filesystem/FilesystemBootstrapperIntegrationTest.php --filter testUntaggedServicesBypassScoping` | ❌ Wave 4 |
| BOOT-03 (`MissingFilesystemConfigException`) | `per_tenant_adapter` mode + tenant without `adapter_dsn` throws `MissingFilesystemConfigException extends \LogicException` | unit | `vendor/bin/phpunit tests/Unit/Filesystem/TenantAwareFilesystemDecoratorTest.php --filter testMissingConfigThrowsLogicException` | ❌ Wave 2 |
| BOOT-03 (`FilesystemContractPass` guard 1) | Compile fails when `tenancy.filesystem.enabled: true` + bundle missing | unit | `vendor/bin/phpunit tests/Unit/DependencyInjection/FilesystemContractPassTest.php --filter testRejectsEnabledWithoutBundle` | ❌ Wave 3 |
| BOOT-03 (`FilesystemContractPass` guard 2) | Compile fails on `per_tenant_adapter` + `allow_per_tenant_adapter: false` | unit | `vendor/bin/phpunit tests/Unit/DependencyInjection/FilesystemContractPassTest.php --filter testRejectsPerTenantAdapterWhenForbidden` | ❌ Wave 3 |
| BOOT-03 (`FilesystemContractPass` guard 3) | Compile fails on invalid `strategy` attribute | unit | `vendor/bin/phpunit tests/Unit/DependencyInjection/FilesystemContractPassTest.php --filter testRejectsInvalidStrategy` | ❌ Wave 3 |
| BOOT-03 (LRU bounded) | 100-tenant simulation runs without unbounded growth, LRU evicts at maxSize | integration | `vendor/bin/phpunit tests/Integration/Filesystem/LongRunningWorkerFilesystemSimulationTest.php` | ❌ Wave 4 |
| BOOT-03 (cache cleared on context-cleared event) | `TenantContextCleared` listener flushes `LruFilesystemCache` | unit | `vendor/bin/phpunit tests/Unit/Filesystem/TenantContextClearedListenerTest.php` | ❌ Wave 1 |
| BOOT-03 (trait + entity column) | `TenantFilesystemConfigTrait` exposes `getFilesystemConfig(): ?array`; `AbstractTenant` carries `filesystemConfig` nullable JSON column | unit | `vendor/bin/phpunit tests/Unit/Filesystem/TenantFilesystemConfigTraitTest.php` | ❌ Wave 1 |
| BOOT-03 (decorator state hygiene) | Reflection assertion: decorator has zero non-readonly mutable instance state (live-read invariant) | unit | `vendor/bin/phpunit tests/Unit/Filesystem/FilesystemPrefixingDecoratorTest.php --filter testHasNoMutableInstanceState` | ❌ Wave 2 |
| BOOT-03 (autowiring through decorator) | A service typehinting `FilesystemOperator $usersStorage` receives the decorator | integration | `vendor/bin/phpunit tests/Integration/Filesystem/FilesystemBootstrapperIntegrationTest.php --filter testAutowiringDelivetsDecorator` | ❌ Wave 4 |

### Sampling Rate

- **Per task commit:** `vendor/bin/phpunit --testsuite unit --filter Filesystem` (sub-second per file).
- **Per wave merge:** `vendor/bin/phpunit --filter Filesystem` (unit + integration, ~5-10 seconds based on Mailer-phase precedent).
- **Phase gate:** Full suite green (`vendor/bin/phpunit`) + PHPStan level 9 (`vendor/bin/phpstan analyse`) + cs-fixer clean (`vendor/bin/php-cs-fixer check --diff`) before `/gsd:verify-work`.

### Wave 0 Gaps

- [ ] `composer require --dev league/flysystem-bundle league/flysystem-memory` (Wave 0)
- [ ] `composer require league/flysystem-bundle league/flysystem-memory --update-with-dependencies` is NOT needed — keep them in `require-dev` + `suggest` per DEC-FILE-BUNDLE.
- [ ] `tests/Integration/Filesystem/FilesystemTestKernel.php` — kernel with `FrameworkBundle + DoctrineBundle + FlysystemBundle + TenancyBundle`, registering two `users.storage` + `public.storage` entries (memory adapter), `users.storage` tagged `tenancy.scoped`.
- [ ] `tests/Integration/Filesystem/MakeFilesystemServicesPublicPass.php` — exposes private bundle services for assertions (mirrors `MakeMailerServicesPublicPass`).
- [ ] `tests/Integration/Support/StubTenantFilesystemExtension.php` (mirror of `StubTenantMailerExtension`) — emits a `Tenancy\Bundle\Tests\Support\StubTenantWithFilesystem` entity that `use TenantFilesystemConfigTrait` so tests can persist filesystem config.
- [ ] Doctrine migration for `tenancy_tenants.filesystem_config` column (nullable JSON) — production-side; tests can rely on schema-from-metadata in `:memory:` SQLite.

---

## Security Domain

`security_enforcement` is not explicitly disabled in `.planning/config.json`, so treat as enabled.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no | Phase 24 does not introduce auth surface; tenant identity is already established by earlier resolver chain. |
| V3 Session Management | no | No session handling. |
| V4 Access Control | yes | Per-tenant isolation IS access control. `FilesystemPrefixingDecorator` and `TenantAwareFilesystemDecorator` ARE the access boundary. Untagged services are explicit escape hatches (DEC-FILE-MULTI). |
| V5 Input Validation | yes | Path arguments to all `FilesystemOperator` methods come from application code, not directly from HTTP. **Path-traversal protection is the application's responsibility, not the bundle's** — document explicitly. |
| V6 Cryptography | no | No new cryptographic primitives. |
| V8 Data Protection | yes | Per-tenant data confidentiality is the bundle's core guarantee. Test must prove tenant A's reads cannot see tenant B's writes. |

### Known Threat Patterns for `league/flysystem-bundle` + multi-tenancy

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Cross-tenant data leak via shared decorator instance state | Information Disclosure | Decorator reads `TenantContext` LIVE on every call — reflection test pins zero mutable state. |
| Cross-tenant data leak via stale LRU cache | Information Disclosure | `LruFilesystemCache::clear()` invoked on `TenantContextCleared` (listener + bootstrapper `clear()` = belt-and-suspenders). |
| Path-traversal (`../`) reaching the underlying adapter | Tampering | OUT OF SCOPE for the bundle; document the trust boundary in user guide + decorator docblock. Per-tenant-adapter mode's `adapter_dsn` is admin-supplied and trusted. |
| DSN credential disclosure in stack traces / logs | Information Disclosure | Mirror the Phase 20 `DsnSanitizer` pattern — `AdapterDsnParser` errors should not include raw DSN. (Lighter version: only S3 DSN carries secrets; local/memory schemes are safe to log verbatim.) |
| Misconfigured tenant routed to wrong bucket via similar-looking DSN | Tampering | `MissingFilesystemConfigException` and the `FilesystemContractPass` allow_per_tenant_adapter guard. Per-tenant-adapter mode is opt-in; default `prefix` mode cannot exhibit this. |
| Long-running worker accumulating per-tenant adapter handles → resource exhaustion | Denial of Service | LRU cache bounded at configurable size; `TenantContextCleared` listener. Mirrors Phase 20's `LongRunningWorkerSimulationTest`. |
| Suspended tenant continuing to access filesystem | Authorization | OUT OF SCOPE for Phase 24 — tenant resolution (Phase 02) already filters `isActive=false`. |

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Flysystem 1.x — `array` returned from `listContents()` | Flysystem 3.x — `DirectoryListing implements IteratorAggregate` | Flysystem 2.0 (Aug 2020) | Decorators must type `listContents()` return as `DirectoryListing` or `iterable`, not `array`. |
| Flysystem 1.x — `has()`, `read()` returned `false` on failure | Flysystem 3.x — Throws `UnableTo*` exceptions | Flysystem 2.0 | No `false` checks needed — exceptions bubble; decorators delegate exceptions transparently. |
| `flysystem-bundle` 2.x — supports Flysystem 1/2/3 simultaneously | `flysystem-bundle` 3.x — Flysystem 3 only, PHP 8.2+ | 2022 (bundle 3.0.0 release) | No compat shim needed; we lock to bundle `^3.0` per DEC-FILE-BUNDLE. |
| `oneup/flysystem-bundle` derived service IDs (`oneup_flysystem.<name>_filesystem`) | `league/flysystem-bundle` bare storage names (`<name>`) | Always — different bundle | Tag-driven decoration in Phase 24 walks `tenancy.scoped` and uses bare names; no `oneup_flysystem.*` derivation. |

**Deprecated/outdated:**
- The legacy `adapter:` / `options:` config format in `flysystem-bundle` 3.5+ — deprecated, the new discoverable format (`local: { directory: ... }` directly) is canonical. [VERIFIED: `FlysystemExtension::resolveAdapterType()` triggers a deprecation when the legacy format is detected.] Our docs / examples / install scaffolding must use the new format.

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `[ASSUMED]` `decoration_priority: 0` is sufficient for every tenancy decorator (no stacking with other decorators in v0.4). | Common Pitfalls / Code Examples §Symfony service decoration | If a future phase or an existing user-side decorator stacks on the same service, our decorator's relative position becomes load-bearing. Mitigation: document the priority choice and ship a regression test (`testTenancyDecoratorIsOuterMostByDefault`). |
| A2 | `[ASSUMED]` Three DSN schemes (`local://`, `s3://`, `memory://`) cover the v0.4 demand surface. | Don't Hand-Roll / Adapter construction | A user wanting Azure / GCS / DigitalOcean Spaces in per-tenant-adapter mode hits "unsupported scheme" at Wave-1 release. Mitigation: pluggable parser (registry of scheme → factory closure) so adding schemes is one-line. |
| A3 | `[ASSUMED]` The bundle's storage definitions remain registered under the bare storage name in future bundle minor versions. | Pitfall 1 | If `flysystem-bundle` 3.8+ changes the service-ID convention, our `findTaggedServiceIds('tenancy.scoped')` loop would still find the user-side tag attachment correctly — but the `setDecoratedService($id)` target lookup might fail. Mitigation: pin `^3.7` minimum, integration test asserts the decorator chain is correct. |
| A4 | `[ASSUMED]` `LruFilesystemCache::clear()` is enough cleanup — no per-adapter `close()` semantic for Local/S3/Memory adapters. | Pattern 2 / LRU-cached per-tenant adapter | If a user supplies a custom adapter with a `close()` method, the cache won't call it. Mitigation: `method_exists($adapter, 'close')` guard in `evict()` — cheap forward-compat. |
| A5 | `[ASSUMED]` Stripping prefix on return paths from `listContents()` is desirable but the bundle does NOT need to do it — users tag the storage knowing the prefix is internal. | Anti-Patterns §Stripping the prefix on return paths | If users expect their `listContents('reports')` to return paths like `'reports/2026.csv'` (relative to tenant) but get `'tenant_acme/reports/2026.csv'`, surprise + churn. Mitigation: flag in Open Questions Q1, decide during plan-phase, document loudly. |
| A6 | `[CITED: github.com/thephpleague/flysystem-bundle/blob/3.x/docs/A-security-disclosure-procedure.md]` Path-traversal is the application's responsibility. | Pitfall 5 / Security Domain | If users expect the bundle to sanitise paths and assume their app is safe, real CVEs. Mitigation: docs + decorator docblock. |
| A7 | `[VERIFIED: packagist.org/league/flysystem.json 3.34.0 require field]` `league/flysystem-memory` is NOT a transitive dependency of `league/flysystem`, contradicting CONTEXT.md DEC-FILE-TEST-ADAPTER's claim. Wave-0 MUST explicitly install it. | Pitfall 3 / Environment Availability | If the planner trusts CONTEXT.md verbatim, Wave-0 task is missing → Wave-4 integration tests fatal with "Class not found". Mitigation: this RESEARCH.md flags it; plan should add the explicit `composer require --dev`. |

**Highest-risk assumption:** A2 (DSN scheme coverage). The plan-phase should explicitly enumerate the schemes the parser supports and the planned failure mode for unknown schemes (`UnsupportedAdapterDsnSchemeException extends \LogicException`?). The user has not been asked about this — flag in Open Questions Q3.

---

## Open Questions

1. **Q1 — Strip prefix on return paths?** When `listContents('reports')` runs inside `FilesystemPrefixingDecorator`, the inner Flysystem returns `StorageAttributes` objects with paths like `'tenant_acme/reports/2026.csv'`. Should the decorator yield modified `StorageAttributes` with paths re-relativised (`'reports/2026.csv'`)? CONTEXT.md is silent.
   - What we know: Flysystem returns paths with the inner prefix attached. `StorageAttributes` is mostly read-only — modifying paths requires constructing new `FileAttributes` / `DirectoryAttributes` instances.
   - What's unclear: Is the user-facing API contract "you see tenant-relative paths" or "you see what the underlying adapter sees"?
   - Recommendation: Plan-phase should DECIDE: yield tenant-relative paths (more user-friendly) and document it. The decorator gains a `stripPrefix()` step on return values. A unit test pins the invariant.

2. **Q2 — `publicUrl()` / `temporaryUrl()` semantics in prefix mode.** A public URL for `tenant_acme/avatar.png` exposes the tenant slug in the URL path. Acceptable for many SaaS apps; problematic for apps that hide tenant identity from URLs.
   - What we know: Some Flysystem adapters (S3) generate URLs by simple concatenation of bucket + path; the prefix flows through.
   - What's unclear: Do we offer a "URL prefix stripping" hook? Or document the leak as an inherent property of prefix mode?
   - Recommendation: Plan should document this as an expected property; users wanting URL-level tenant hiding should use `per_tenant_adapter` mode with a per-tenant bucket.

3. **Q3 — `AdapterDsnParser` failure mode for unknown schemes.** What happens when a tenant's `adapter_dsn` uses a scheme the parser doesn't recognise (e.g. `azure://`, `ftp://`)?
   - What we know: `MissingFilesystemConfigException` is reserved for the missing-config case per DEC-FILE-EXCEPTION.
   - What's unclear: Do we introduce a sibling `UnsupportedAdapterDsnSchemeException extends \LogicException`, or reuse `MissingFilesystemConfigException` with a different message?
   - Recommendation: New sibling exception. Both extend `\LogicException` so Messenger no-retry behaviour is preserved.

4. **Q4 — Profiler subsection in scope?** CONTEXT.md "Deferred Ideas" marks it deferred. But Phase 20's Mailer subsection (D-08) provided real diagnostic value. If Wave 5 has slack, fold it in.
   - What we know: Mirror Phase 20's D-08 — add a `filesystem` key to `TenantDataCollector`, render unconditionally in `tenant.html.twig` (Phase 23 INT-01 lesson — render outside `state == 'resolved'` branch).
   - What's unclear: Whether the Wave-budget admits it.
   - Recommendation: Plan-phase defaults to "deferred" per CONTEXT.md; only fold in if Wave 3 finishes ahead of schedule and the planner explicitly signals the pull-in.

5. **Q5 — `examples/saas/` upload page in scope?** CONTEXT.md marks it deferred; live-stack verification (Phase 21 lesson per `feedback_live_stack_verification.md` memory) caught real bugs. Without an upload page exercising the bootstrapper end-to-end, latent integration bugs may sneak through to v0.4 tag.
   - What we know: Phase 21 added 7 BLOCKERs from live-stack runs; comparable risk likely here.
   - What's unclear: Whether the demo upload page is scope-bloat or risk mitigation.
   - Recommendation: Plan should include a thin demo upload controller in Wave 5 (one form, one POST, one read-back assertion) — small enough to fit, valuable enough to justify. Frame as "live-stack verification step", not "feature".

---

## Sources

### Primary (HIGH confidence)

- **`league/flysystem-bundle` source** — `src/DependencyInjection/FlysystemExtension.php` at https://github.com/thephpleague/flysystem-bundle/blob/3.x/src/DependencyInjection/FlysystemExtension.php — verified the `flysystem.storage` DI tag, bare-storage-name service ID convention, and `registerAliasForArgument` autowiring registration.
- **`league/flysystem` source** — `src/PathPrefixer.php` at https://github.com/thephpleague/flysystem/blob/3.x/src/PathPrefixer.php — verified the helper class signature.
- **`league/flysystem-memory` source** — `src/InMemoryFilesystemAdapter.php` namespace and Composer manifest — verified the adapter is shipped under `League\Flysystem\InMemory\` PSR-4 from `league/flysystem-memory` (NOT a transitive dep).
- **Packagist JSON API** — packagist.org/packages/league/flysystem-bundle.json, /league/flysystem.json, /league/flysystem-memory.json — verified current stable versions, release dates, and `require` constraints.
- **`league/flysystem-bundle` docs** — `docs/1-getting-started.md`, `docs/2-cloud-storage-providers.md`, `docs/4-using-lazy-adapter-to-switch-at-runtime.md` at https://github.com/thephpleague/flysystem-bundle/tree/3.x/docs.
- **Symfony documentation** — https://symfony.com/doc/current/service_container/service_decoration.html — verified `setDecoratedService()` + `findTaggedServiceIds()` patterns.
- **Existing Tenancy Bundle precedent code** (verified by Read):
  - `src/Bootstrapper/MailerBootstrapper.php`
  - `src/Mailer/TenantAwareTransportsDecorator.php`
  - `src/Mailer/LruTransportCache.php`
  - `src/Mailer/TenantMailerConfigTrait.php`
  - `src/DependencyInjection/Compiler/MailerTransportContractPass.php`
  - `src/Entity/AbstractTenant.php`
  - `src/TenancyBundle.php`
  - `config/services.php` (Mailer wiring §lines 158-241).

### Secondary (MEDIUM confidence)

- **Flysystem core API docs** — https://flysystem.thephpleague.com/docs/usage/filesystem-api/ — verified `FilesystemOperator` interface surface; cross-verified with upstream `FilesystemOperator.php` source.
- **Symfony decorator best-practice WebSearch** — Stack Overflow / SymfonyCasts / Internations decorator-bundle entries — corroborate the `setDecoratedService` + `setArguments(['.inner'])` pattern but not authoritative.

### Tertiary (LOW confidence)

None — all critical claims verified via Primary sources.

---

## Metadata

**Confidence breakdown:**

- **Standard stack:** HIGH — every package, version, and namespace verified against Packagist and upstream GitHub on 2026-05-30.
- **Architecture patterns:** HIGH — every primitive has an in-repo precedent (Mailer phase) confirmed by direct Read.
- **Bundle config surface (service IDs, DI tag):** HIGH — verified directly from `FlysystemExtension.php` source at https://github.com/thephpleague/flysystem-bundle/blob/3.x/src/DependencyInjection/FlysystemExtension.php (commit-pinned by 3.7.0 tag).
- **`FilesystemOperator` interface surface:** HIGH — cross-verified between docs and source.
- **DSN parser strategy:** MEDIUM — no upstream "official" DSN parser exists; the three-scheme choice is judgement.
- **Pitfalls:** HIGH — Pitfalls 1, 3, 6, 7 verified via source; Pitfalls 2, 4, 5 verified via Mailer-phase precedent + docs.
- **`league/flysystem-memory` transitive-dep correction (Pitfall 3 / Assumption A7):** HIGH — direct contradiction of CONTEXT.md DEC-FILE-TEST-ADAPTER's claim, backed by Packagist JSON for `league/flysystem` 3.34.0.

**Research date:** 2026-05-30
**Valid until:** 2026-06-29 (30-day default for stable Symfony + League ecosystem; refresh if `league/flysystem-bundle` ships 3.8.x before phase completes).

---

## RESEARCH COMPLETE

**Phase:** 24 - Filesystem Bootstrapper (BOOT-03)
**Confidence:** HIGH

### Key Findings

- `league/flysystem-bundle` 3.7.0 registers each storage under its **bare name** (e.g. `default.storage`) and tags it `flysystem.storage` with attribute `storage: <name>` — NOT a derived `flysystem.filesystem.<name>` ID. Our compile-pass walks the user-applied `tenancy.scoped` tag and rewires those exact IDs via `setDecoratedService()`.
- Phase 24 is structurally **Phase 20 with `FilesystemOperator` substituted for `TransportInterface`**: live-read decorator + LRU cache + `TenantContextCleared` listener + optional trait. ~70% of code is shape-equivalent to existing `src/Mailer/` siblings — review side-by-side.
- `FilesystemOperator`'s 16 methods all take a path string as their first positional argument. A single decorator covers the full surface uniformly. `League\Flysystem\PathPrefixer` is the canonical prefix helper — use directly.
- **CONTEXT.md DEC-FILE-TEST-ADAPTER's "already a transitive dep of `league/flysystem`" claim is wrong** — `league/flysystem-memory` is a separate Composer package and MUST be explicitly added to `require-dev`. Pitfall 3 / Assumption A7 / Wave-0 install step flagged.
- Five open questions surfaced for plan-phase resolution: prefix-stripping on return paths (Q1), public URL semantics (Q2), DSN unknown-scheme exception class (Q3), profiler-subsection scope (Q4), demo-upload-page scope (Q5). None are blocking — all have a recommended default.

### File Created

`.planning/phases/24-filesystem-bootstrapper/24-RESEARCH.md`

### Confidence Assessment

| Area | Level | Reason |
|------|-------|--------|
| Standard Stack | HIGH | Packagist + upstream source verified 2026-05-30. |
| Architecture | HIGH | Every primitive has a Mailer-phase precedent verified by Read. |
| Pitfalls | HIGH | Top concerns verified via upstream source / docs / project precedent. |
| DSN parser strategy | MEDIUM | Judgement call; three schemes chosen for v0.4 demand. |
| Open questions | n/a | Five flagged; planner decides at plan time. |

### Open Questions

See `## Open Questions` section above — five items, each with a recommendation. None block the planner from starting; each carries a sensible default.

### Ready for Planning

Research complete. Planner can now create PLAN.md files. Suggested decomposition (matches CONTEXT.md "Plan Wave Suggestion"):
- **Wave 0:** Test scaffolding (composer require-dev, `FilesystemTestKernel`, `MakeFilesystemServicesPublicPass`, `StubTenantFilesystemExtension`).
- **Wave 1:** Primitives (`TenantFilesystemConfigTrait`, `AbstractTenant.filesystemConfig` column, `MissingFilesystemConfigException`, `LruFilesystemCache`, `TenantContextClearedListener`).
- **Wave 2:** Decorators (`FilesystemPrefixingDecorator`, `TenantAwareFilesystemDecorator`, `AdapterDsnParser`).
- **Wave 3:** Wiring (`FilesystemBootstrapper`, `TenancyBundle` configure/loadExtension/build, `FilesystemContractPass`).
- **Wave 4:** Tests (5 integration scenarios from DEC-FILE-TEST-ADAPTER + cache-bounded long-worker simulation + autowiring-through-decorator regression).
- **Wave 5:** Demo + Docs (consider folding in upload page per Q5 + `docs/user-guide/filesystem-bootstrapper.md`).
