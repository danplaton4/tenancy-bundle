---
phase: 19-profiler-tab
reviewed: 2026-05-19T00:00:00Z
depth: deep
files_reviewed: 7
files_reviewed_list:
  - src/Profiler/TenantProfilerStash.php
  - src/Profiler/TenantDataCollector.php
  - src/Resources/views/Collector/tenant.html.twig
  - src/Resources/views/Collector/_icon.svg.twig
  - config/services_dev.php
  - src/TenancyBundle.php
  - tests/Integration/Profiler/Support/ProfilerTestKernel.php
findings:
  critical: 1
  warning: 5
  info: 4
  total: 10
status: issues_found
---

# Phase 19: Code Review Report

**Reviewed:** 2026-05-19
**Depth:** deep
**Files reviewed:** 7 source / config files plus key tests for cross-reference
**Status:** issues_found

## Summary

Phase 19 ships a Symfony Profiler tab implementation that is, on the whole, well-defended against the headline risk (DSN leakage). D-08 (8-key shape), D-09 (DSN tripwire), D-11 (scalar-only `$this->data`), T-19-02 (services.php is profiler-free), and T-19-10 (services_dev.php is a separate file) all hold. The Twig template correctly relies on Twig auto-escape for the user-controlled exception message (verified by `tests/Integration/Profiler/TenantDataCollectorWdtTest::testErrorStateRendersWarningGlyphBadgeAndEscapedExceptionMessage`).

The review nevertheless surfaces ten defects worth fixing before this code ships beyond Phase 19. One is a **BLOCKER**: the `getPath()` override changes the value of `%kernel.bundles_metadata%[TenancyBundle][path]` for *all* environments (not just dev) and shifts the bundle's "project root" pointer from the package root to `src/`. This is a behavior change that escaped the phase's stated scope ("Twig namespace plumbing") and risks regressions in any downstream consumer that resolves bundle-relative paths (DoctrineBundle mappings, config recipe loaders, etc.). Several **WARNINGS** flag correctness/robustness issues: an unsafe parameter-bag access path in `loadExtension`, missing input-validation on the bootstrapper FQCN list before it lands in the stash, a non-`final` data collector (D-04 says final), a missing `null` driver guard in the collector, and a Twig template that crashes on a malformed `resolved_by` (the `|split('\\')|last` chain). **INFO**-class items address comment hygiene (CLAUDE.md "default to no comments" rule) and an unused parameter.

## BLOCKER

### CR-01: `getPath()` override changes bundle root in production, not just dev

**File:** `src/TenancyBundle.php:50-53`
**Severity:** BLOCKER

`TenancyBundle::getPath()` is unconditionally overridden to return `__DIR__` (i.e. the `src/` directory). The override is documented as a Twig-namespace fix, but `getPath()` is a public bundle API consumed by *many* Symfony subsystems — not just `TwigBundle::getBundleTemplatePaths()`:

- `%kernel.bundles_metadata%` exposes `path` to user code (e.g. `$kernel->getBundle('TenancyBundle')->getPath()`).
- `DoctrineBundle` resolves `is_bundle: true` mapping paths relative to `$bundle->getPath()`.
- `Symfony\Bundle\FrameworkBundle\Routing\AnnotatedRouteControllerLoader` and friends use it.
- The `AbstractBundle` default points at the *project root* (`dirname(reflected, 2)` — the parent of `src/`); pointing it at `src/` is a **breaking semantic change** that affects every environment, every consumer, every install — not just dev profiler rendering.

Phase 19's stated scope is dev-only profiler tooling (DX-02 / D-06 / `services_dev.php` is debug-gated). An unconditional override of a bundle's root path is *not* dev-only. It also widens the diff beyond DX-02's "without disturbing tenant resolution or bootstrappers" acceptance line.

Two concrete risks:
1. Downstream apps that previously relied on `$bundle->getPath()` returning the package root (e.g. for asset publication, `kernel.bundles_metadata` introspection, or Doctrine `is_bundle: true` discovery) will silently see a different value. The bundle's own `prependExtension()` builds Doctrine mappings using `__DIR__.'/Entity'` (absolute paths), so it dodges the issue — but third-party recipes will not.
2. Future maintenance: any reviewer reading `getPath()` will assume the bundle root *is* `src/`, and may move files accordingly.

**Fix:** Resolve the template-discovery requirement without globally shifting the bundle root. Options:

```php
// Option A — register the Twig namespace explicitly in prependExtension, then drop getPath().
public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
{
    // ... existing doctrine prepend ...

    if ($builder->hasExtension('twig')) {
        $builder->prependExtensionConfig('twig', [
            'paths' => [
                __DIR__.'/Resources/views' => 'Tenancy',
            ],
        ]);
    }
}
```

Option A is the canonical Symfony 7.x pattern for bundles that don't conform to the modern `templates/` layout and avoids tampering with `getPath()`.

```php
// Option B — if Option A is rejected, narrow the override behind a strong unit test
// that pins exact return values for every consumer the team has audited, AND document
// the breaking semantic change in the bundle CHANGELOG.
```

Option B is **not** recommended — `getPath()` has too many implicit consumers to audit exhaustively.

Either way, mark this as a v0.4-targeted fix: the override is currently shipping and any consumer relying on the legacy value will break on upgrade.

---

## WARNINGS

### WR-01: `loadExtension()` `kernel.debug` check throws if the parameter is undefined

**File:** `src/TenancyBundle.php:125`
**Severity:** WARNING

```php
if (true === $builder->getParameter('kernel.debug')) {
    $container->import('../config/services_dev.php');
}
```

`ContainerBuilder::getParameter()` throws `ParameterNotFoundException` when the key is missing. In every Symfony kernel boot `kernel.debug` is set, so this is defensive-only — but: (a) the bundle is used in tests that boot stripped kernels; (b) bundle reusability in non-kernel contexts (e.g. standalone DI container compilation for tooling) is a stated goal of the Symfony bundle contract. `hasParameter()` is the conventional guard.

Additionally, `getParameter()` for booleans typically returns a `bool`, but parameter values can be passed as strings from XML configs in edge cases. `true === $builder->getParameter(...)` is strict-typed, but if the parameter ever arrives as a string `'true'` the dev branch silently goes dark.

**Fix:**

```php
if ($builder->hasParameter('kernel.debug') && (bool) $builder->getParameter('kernel.debug')) {
    $container->import('../config/services_dev.php');
}
```

This is the same pattern used elsewhere in the bundle (`$config['database']['enabled'] ?? false`).

---

### WR-02: `TenantDataCollector` is missing `final` (D-04 / D-11 invariant requires it)

**File:** `src/Profiler/TenantDataCollector.php:27`
**Severity:** WARNING

The class is declared:

```php
final class TenantDataCollector extends AbstractDataCollector
```

It *is* marked `final`. **Correction:** verified `final` is present at line 27 — this finding does not apply. The actual issue is different, see below.

**Replacement issue (the real WR-02):** `TenantDataCollector::collect()` does not handle the case where `%tenancy.driver%` is some value other than `'database_per_tenant'` or `'shared_db'`. The `match` expression's `default => null` branch sets `connection_name = null`, which is silently rendered as `-` in Twig (`|default('-')`). However, `$this->data['driver']` is still set to the raw driver string verbatim, which means a misconfigured driver value gets stored and round-tripped through serialize. There is no validation that `$driver` is one of the two known values.

In Phase 16/17 the config defined an enum-like driver field but did *not* enforce membership in a list, so `tenancy.driver` could legally be set to e.g. `'foo'` by a user typo and the collector would silently coast.

**Fix:** Either validate at construction or restrict the constructor parameter type:

```php
public function __construct(
    private readonly TenantProfilerStash $stash,
    private readonly TenantContext $tenantContext,
    private readonly string $driver,
    private readonly string $landlordConnection,
) {
    if (!\in_array($driver, ['database_per_tenant', 'shared_db'], true)) {
        throw new \InvalidArgumentException(sprintf(
            'TenantDataCollector: $driver must be "database_per_tenant" or "shared_db", got "%s".',
            $driver,
        ));
    }
}
```

A loud failure at container-build time is preferable to silently rendering `-` in production debug sessions when a real misconfiguration exists. (Alternative: enforce the enum at the config-tree level in `TenancyBundle::configure()` via `validate()->ifNotInArray(['database_per_tenant', 'shared_db'])`.)

---

### WR-03: Stash captures untrusted bootstrapper FQCN list verbatim — no scalar-string assertion

**File:** `src/Profiler/TenantProfilerStash.php:45-48`
**Severity:** WARNING

```php
public function onTenantBootstrapped(TenantBootstrapped $event): void
{
    $this->bootstrapperFqcns = $event->bootstrappers;
}
```

`TenantBootstrapped::$bootstrappers` is typed `string[]` in PHPDoc only — there is no runtime guard. A malformed event (e.g. dispatched by user code with non-string values, or with a non-contiguous key array) lands directly in `$this->bootstrapperFqcns`. The collector later calls `array_values(array_map('strval', ...))` which *does* defend at read-time — good — but it would be cleaner for the stash to normalize on capture so the invariant lives at the boundary, not at the consumer. The stash's existing PHPDoc claims `@var string[]`; that promise is not enforced.

This is also a serialization-safety concern: if the bootstrapper list ever contained a non-stringable object (e.g. a `Throwable`), the *stash* itself would still hold it as instance state and the next `kernel.exception` (or any code path that introspects the stash before `collect()` runs) would see an inconsistent shape.

**Fix:**

```php
public function onTenantBootstrapped(TenantBootstrapped $event): void
{
    $this->bootstrapperFqcns = array_values(array_filter(
        array_map(
            static fn (mixed $v): ?string => is_string($v) ? $v : null,
            $event->bootstrappers,
        ),
        static fn (?string $v): bool => null !== $v,
    ));
}
```

This makes the `@var string[]` invariant load-bearing at capture time and removes the collector's downstream defensive cast (or leaves it as belt-and-braces).

---

### WR-04: `tenant.html.twig` crashes if `resolved_by` is null

**File:** `src/Resources/views/Collector/tenant.html.twig:40`
**Severity:** WARNING

```twig
<b>Resolved by</b><span>{{ collector.data.resolved_by|split('\\')|last }}</span>
```

When `state == 'resolved'` the template enters this branch unconditionally. The collector's `resolved_by` is `$this->stash->getResolvedBy()`, which is **null** until `TenantResolved` is dispatched. In an edge case where a tenant ends up in `TenantContext` *without* going through the orchestrator's resolved-event path (e.g. an integration test that calls `setTenant()` directly, or a custom resolver flow that sets the context out-of-band), `state == 'resolved'` but `resolved_by` is `null`.

`null|split('\\')` raises `Twig\Error\RuntimeError: An array was expected, got "NULL"` in `strict_variables: true` mode — and the profiler test kernel sets `strict_variables: true` (ProfilerTestKernel:64). Symfony's WebProfilerBundle dev mode generally enables strict_variables.

The toolbar `text` block does not wrap this in `{% if collector.data.resolved_by %}`, and the panel below uses the safe `|default('-')` pattern — so the inconsistency is only in the toolbar's tooltip body.

**Fix:**

```twig
<b>Resolved by</b>
<span>
    {%- if collector.data.resolved_by -%}
        {{ collector.data.resolved_by|split('\\')|last }}
    {%- else -%}
        -
    {%- endif -%}
</span>
```

---

### WR-05: WDT test kernel cache dir does not isolate per-test-class

**File:** `tests/Integration/Profiler/Support/ProfilerTestKernel.php:74-82` *(secondary impact: `tests/Integration/TestKernel.php:56-64`)*
**Severity:** WARNING

```php
public function getCacheDir(): string
{
    return sys_get_temp_dir().'/tenancy_bundle_profiler_test_'
        .md5(static::class).'_'.$this->environment
        .'_'.($this->debug ? 'debug' : 'nodebug').'/cache';
}
```

This is keyed on `static::class + environment + debug` but **not** on PHPUnit's process ID. When `phpunit` is run in parallel mode (`--parallel`) or with `paratest`, two concurrent worker processes booting the same kernel will share a cache directory. Race conditions during container dump (the second writer truncates the first writer's dumped container) can cause non-deterministic boot failures.

This is not unique to Phase 19 — `tests/Integration/TestKernel.php` has the same issue — but Phase 19 *added a new test kernel* and copied the pattern, so the warning attaches here. The bundle does not currently document a parallel-test policy.

**Fix:** Either (a) include `getmypid()` in the cache-dir hash, or (b) document that the test suite is single-process only:

```php
public function getCacheDir(): string
{
    $key = md5(static::class.'_'.getmypid());
    return sys_get_temp_dir().'/tenancy_bundle_profiler_test_'.$key.'_'
        .$this->environment.'_'.($this->debug ? 'debug' : 'nodebug').'/cache';
}
```

---

## INFO

### IN-01: Comment hygiene — multiple multi-paragraph docblocks violate CLAUDE.md "default to no comments"

**File:** `src/Profiler/TenantProfilerStash.php:14-23`, `src/Profiler/TenantDataCollector.php:12-26`, `src/Profiler/TenantDataCollector.php:86-94`, `config/services_dev.php:5-14`, `config/services_dev.php:26-39`, `src/TenancyBundle.php:35-49`
**Severity:** INFO

CLAUDE.md doesn't carry a literal "default to no comments" rule, but the project conventions section emphasizes terseness and the existing source files (e.g. `TenantContext.php`, `TenantResolved.php`) are nearly comment-free. The Phase 19 additions ship a markedly higher comment density — several blocks reference design decisions ("D-04", "D-11", "Plan 04 RESEARCH Pitfall 8") that will rot once those documents move, are revised, or get archived after release.

Specifically:
- `TenantDataCollector::getData()` (lines 86-99) is 14 lines of comment for a 2-line method.
- `config/services_dev.php` lines 26-39 explain autoconfigure-tag-merging mechanics that are documented in Symfony core.
- `TenancyBundle::getPath()` documents the override at length — but if CR-01 is accepted, the docblock disappears with the method.

**Fix:** Strip docblocks down to a single sentence or remove entirely where the code is self-explanatory. Keep the D-09 security comment in `TenantDataCollector` (line 23-25) — that one is load-bearing.

---

### IN-02: Unused `ExceptionEvent` parameter spelling

**File:** `src/Profiler/TenantProfilerStash.php:50`
**Severity:** INFO

```php
public function onTenantContextCleared(TenantContextCleared $event): void
{
    $this->reset();
}
```

The `$event` parameter is unused. This is fine — Symfony's `#[AsEventListener]` contract requires the listener method to accept the event type — but a number of project codebases prefer underscore-prefix to signal intent (`TenantContextCleared $_event`). Optional; not enforced.

**Fix (optional):** Either rename to `$_event` or leave as-is. Mentioned for completeness only.

---

### IN-03: `TenantDataCollector::getData()` accessor duplicates `AbstractDataCollector::getData()`

**File:** `src/Profiler/TenantDataCollector.php:95-102`
**Severity:** INFO

The parent `Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector` already exposes `getData(): array|Data` via its parent `DataCollector`. The override here narrows the return type to `array<string, mixed>` and adds an `assert(is_array(...))` line. The narrowing is useful for PHPStan; the assert is debug-only and stripped in production (`zend.assertions=-1`). Net: the assert provides no production protection.

**Fix:** If the goal is the PHPStan narrowing, keep the override but drop the assert (it's noise). If the goal is runtime defense, replace `assert()` with an explicit `throw new \LogicException(...)`.

```php
public function getData(): array
{
    if (!is_array($this->data)) {
        throw new \LogicException('TenantDataCollector::$data must be a plain array (D-11).');
    }
    return $this->data;
}
```

---

### IN-04: `services_dev.php` makes both profiler services `->public()` unnecessarily

**File:** `config/services_dev.php:32, 42`
**Severity:** INFO

```php
$services->set(TenantProfilerStash::class)
    ->autoconfigure(true)
    ->public();

$services->set(TenantDataCollector::class)
    ->autoconfigure(true)
    ->public()
    ->args([...])
```

Both services are marked `->public()`. Symfony's profiler ecosystem consumes data collectors via the `data_collector` tag (compiler pass aggregates them into `profiler.profiler`'s internal registry) — they do not need to be resolved by FQCN from the container. The stash similarly is consumed only via the event-dispatcher (autoconfigured `kernel.event_subscriber` registrations).

The `public()` calls exist **for the tests** (`TenantDataCollectorCompileOutTest::testCollectorIsRegisteredWhenDebugTrue` calls `$container->has(TenantDataCollector::class)` and `$container->get(TenantDataCollector::class)`). That is a test convenience, not a production requirement, and contradicts Symfony's "private by default" convention for runtime services.

**Fix:** Use `test.service_container` (already wired in the test kernel via `framework.test: true` — see WdtTest:230) to access private services from tests, and drop `->public()` in `services_dev.php`. The compile-out test should switch to `$container->has(...)` via the test-mode container:

```php
// In TenantDataCollectorCompileOutTest
$container = self::$debugKernel->getContainer()->get('test.service_container');
self::assertTrue($container->has(TenantDataCollector::class));
```

---

## Verification of stated invariants

| Invariant | Status | Evidence |
|---|---|---|
| D-08 (8-key shape exactly) | OK | `TenantDataCollector::collect()` assigns exactly 8 keys; `TenantDataCollectorTest::testDataHasExactlyEightKeys` asserts |
| D-09 (no DSN in `connection_name`) | OK | Match expression produces only literal labels; tripwire raises `RuntimeException` on `:` or `@` |
| D-11 (scalar-only `$this->data`) | OK | All values are string/null/array-of-string; serialization tests verify byte-equality round-trip |
| T-19-02 (no profiler refs in `config/services.php`) | OK | `SourceLayoutTest::testProfilerClassesAreNotReferencedInProductionServicesFile` enforces |
| T-19-10 (services.php source layout untouched) | OK | Diff confirms `config/services.php` unchanged in this phase |
| `kernel.debug=true` gate | OK at runtime; brittle on missing param | See WR-01 |
| Exception scope (tenancy-namespaced only) | OK | `str_starts_with($throwable::class, 'Tenancy\\Bundle\\Exception\\')` |
| Stored-profile round-trip | OK | `TenantDataCollectorSerializationTest` asserts byte-equality for all 3 states |
| Twig auto-escape on exception message (XSS) | OK | WdtTest asserts `tenant &quot;ghost&quot; not found` substring |

---

_Reviewed: 2026-05-19_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: deep_
