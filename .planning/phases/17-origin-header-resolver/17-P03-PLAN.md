---
id: 17-P03
phase: 17
plan: 03
name: Bundle wiring — configure() + loadExtension() + build() + ResolverChainPass map
wave: 2
depends_on: [17-P01, 17-P02]
files_modified:
  - src/TenancyBundle.php
  - src/DependencyInjection/Compiler/ResolverChainPass.php
autonomous: true
requirements: [RESV-06]
threats: [T-17-03]
must_haves:
  truths:
    - "A YAML config of `tenancy.origin.allow_list` accepts the wildcard shorthand string form (e.g. 'https://*.app.example.com') and the explicit map form (e.g. {origin: '...', slug: '...'})"
    - "When `'origin'` is in `tenancy.resolvers`, the `tenancy.resolver.origin` service is registered with `TenantProviderInterface`, `LoggerInterface`, and the `tenancy.origin.allow_list` parameter as its three constructor args; tagged `tenancy.resolver` priority 25"
    - "When `'origin'` is NOT in `tenancy.resolvers`, no `tenancy.resolver.origin` service is registered (zero footprint)"
    - "`OriginHeaderResolver::class` is reachable via the short-name `'origin'` through `ResolverChainPass::BUILT_IN_RESOLVER_MAP`"
    - "`OriginHeaderResolverConfigPass` is registered in `TenancyBundle::build()` unconditionally (it self-gates internally)"
    - "Default `tenancy.resolvers` value remains `['host', 'header', 'query_param', 'console']` (Origin stays opt-in per D-14)"
  artifacts:
    - path: src/TenancyBundle.php
      provides: "Updated configure/loadExtension/build wiring for origin resolver"
      contains: "->arrayNode('origin')"
    - path: src/DependencyInjection/Compiler/ResolverChainPass.php
      provides: "Updated BUILT_IN_RESOLVER_MAP with 'origin' entry"
      contains: "'origin' => OriginHeaderResolver::class"
  key_links:
    - from: src/TenancyBundle.php
      to: src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php
      via: "build() adds compiler pass"
      pattern: "addCompilerPass\\(new OriginHeaderResolverConfigPass\\(\\)\\)"
    - from: src/TenancyBundle.php
      to: src/Resolver/OriginHeaderResolver.php
      via: "loadExtension() service definition with three args + tag priority 25"
      pattern: "tenancy\\.resolver.+priority.+25"
    - from: src/DependencyInjection/Compiler/ResolverChainPass.php
      to: src/Resolver/OriginHeaderResolver.php
      via: "BUILT_IN_RESOLVER_MAP['origin']"
      pattern: "'origin' => OriginHeaderResolver::class"
---

<objective>
Wire `OriginHeaderResolver` (Plan 01) and `OriginHeaderResolverConfigPass` (Plan 02) into the bundle:
1. `TenancyBundle::configure()` — new `origin:` array node sibling to `host:`, with `beforeNormalization()` shorthand-to-map conversion on `allow_list` entries (D-18, D-19).
2. `TenancyBundle::loadExtension()` — when `'origin' in $config['resolvers']`, set the raw `tenancy.origin.allow_list` parameter (un-normalized — compiler pass normalizes) and register `tenancy.resolver.origin` service with three args, tagged priority 25 (D-15, D-16, D-17).
3. `TenancyBundle::build()` — append `new OriginHeaderResolverConfigPass()` to the compiler-pass list, unconditionally (D-15).
4. `ResolverChainPass::BUILT_IN_RESOLVER_MAP` — add `'origin' => OriginHeaderResolver::class` (D-13).

Purpose: This is the integration point. Plans 01 and 02 are byte-deterministic alone; Plan 03 makes them addressable from `tenancy.yaml`.

Output: Two file edits. No new files.
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
@.planning/phases/17-origin-header-resolver/17-P01-SUMMARY.md
@.planning/phases/17-origin-header-resolver/17-P02-SUMMARY.md
@CLAUDE.md
@src/TenancyBundle.php
@src/DependencyInjection/Compiler/ResolverChainPass.php
@src/Resolver/OriginHeaderResolver.php
@src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php
@config/services.php
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Add `origin' to ResolverChainPass::BUILT_IN_RESOLVER_MAP</name>
  <files>src/DependencyInjection/Compiler/ResolverChainPass.php</files>
  <read_first>
    - src/DependencyInjection/Compiler/ResolverChainPass.php (current state — single-line edit)
    - src/Resolver/OriginHeaderResolver.php (FQCN to reference: `Tenancy\Bundle\Resolver\OriginHeaderResolver`)
    - .planning/phases/17-origin-header-resolver/17-CONTEXT.md decision D-13
  </read_first>
  <behavior>
    - Add `use Tenancy\Bundle\Resolver\OriginHeaderResolver;` to the import list, sorted alphabetically among the existing `Tenancy\Bundle\Resolver\*` imports.
    - Add `'origin' => OriginHeaderResolver::class,` to `BUILT_IN_RESOLVER_MAP`. Per existing convention in the constant, position the entry alphabetically (between `'host'` and `'header'` would NOT be alphabetical — `'origin'` slots between `'header'` and `'query_param'`).
    - No other change to ResolverChainPass — the `findAndSortTaggedServices('tenancy.resolver', ...)` flow already handles priority 25 transparently.
  </behavior>
  <action>
Apply two changes to `src/DependencyInjection/Compiler/ResolverChainPass.php`:

1. Imports — add the `OriginHeaderResolver` import alphabetically (the existing block is sorted; the new line goes after `HostResolver` and before `QueryParamResolver`):

Before:
```php
use Tenancy\Bundle\Resolver\ConsoleResolver;
use Tenancy\Bundle\Resolver\HeaderResolver;
use Tenancy\Bundle\Resolver\HostResolver;
use Tenancy\Bundle\Resolver\QueryParamResolver;
use Tenancy\Bundle\Resolver\ResolverChain;
```

After:
```php
use Tenancy\Bundle\Resolver\ConsoleResolver;
use Tenancy\Bundle\Resolver\HeaderResolver;
use Tenancy\Bundle\Resolver\HostResolver;
use Tenancy\Bundle\Resolver\OriginHeaderResolver;
use Tenancy\Bundle\Resolver\QueryParamResolver;
use Tenancy\Bundle\Resolver\ResolverChain;
```

2. `BUILT_IN_RESOLVER_MAP` — insert the new entry. Existing block (lines ~20-25):
```php
private const BUILT_IN_RESOLVER_MAP = [
    'host' => HostResolver::class,
    'header' => HeaderResolver::class,
    'query_param' => QueryParamResolver::class,
    'console' => ConsoleResolver::class,
];
```

After:
```php
private const BUILT_IN_RESOLVER_MAP = [
    'host' => HostResolver::class,
    'header' => HeaderResolver::class,
    'origin' => OriginHeaderResolver::class,
    'query_param' => QueryParamResolver::class,
    'console' => ConsoleResolver::class,
];
```

Order rationale: the existing list isn't strictly alphabetical (it's priority-descending). Place `'origin'` between `'header'` and `'query_param'` to match priority order (30 host, 20 header, 25 origin slots above header per DEC-RESV-01 — but the map order is documentation only; runtime priority comes from the `tenancy.resolver` tag attribute, not map position). Putting `'origin'` adjacent to `'header'` makes the resolver-chain ordering visually consistent with the runtime priority chain.
  </action>
  <verify>
    <automated>php -l src/DependencyInjection/Compiler/ResolverChainPass.php &amp;&amp; grep -q "'origin' => OriginHeaderResolver::class," src/DependencyInjection/Compiler/ResolverChainPass.php &amp;&amp; grep -q "use Tenancy.Bundle.Resolver.OriginHeaderResolver;" src/DependencyInjection/Compiler/ResolverChainPass.php</automated>
  </verify>
  <acceptance_criteria>
    - `php -l src/DependencyInjection/Compiler/ResolverChainPass.php` exits 0
    - `grep -cF "'origin' => OriginHeaderResolver::class," src/DependencyInjection/Compiler/ResolverChainPass.php` outputs `1`
    - `grep -cF "use Tenancy\Bundle\Resolver\OriginHeaderResolver;" src/DependencyInjection/Compiler/ResolverChainPass.php` outputs `1`
    - Total entries in `BUILT_IN_RESOLVER_MAP` (the lines matching `=>`) is exactly 5: `awk '/private const BUILT_IN_RESOLVER_MAP/,/];/' src/DependencyInjection/Compiler/ResolverChainPass.php | grep -c '=>'` outputs `5`
    - `vendor/bin/phpstan analyse src/DependencyInjection/Compiler/ResolverChainPass.php --level=9 --no-progress` exits 0
  </acceptance_criteria>
  <done>ResolverChainPass single-line edit applied, lints + types clean; the short-name `'origin'` now resolves to the resolver class FQCN.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Wire TenancyBundle — configure(), loadExtension(), build()</name>
  <files>src/TenancyBundle.php</files>
  <read_first>
    - src/TenancyBundle.php (current state — three sites to edit; read whole file so the integration is contextual, not blind)
    - src/Resolver/OriginHeaderResolver.php (FQCN + constructor signature to wire)
    - src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php (FQCN for build())
    - .planning/phases/17-origin-header-resolver/17-CONTEXT.md decisions D-13, D-14, D-15, D-16, D-17, D-18, D-19
    - .planning/phases/17-origin-header-resolver/17-PATTERNS.md sections "TenancyBundle::configure()", "TenancyBundle::loadExtension()", "TenancyBundle::build()"
  </read_first>
  <behavior>
    Three edit sites in TenancyBundle.php:

    A. **Imports (top of file)** — add three new `use` statements, sorted alphabetically among existing imports:
       - `use Tenancy\Bundle\DependencyInjection\Compiler\OriginHeaderResolverConfigPass;`
       - `use Tenancy\Bundle\Resolver\OriginHeaderResolver;`
       - `use Psr\Log\LoggerInterface;` only if you reference it directly — Symfony will autowire via the string service id, so it's not strictly required; OMIT it.

    B. **`configure()`** — add an `origin:` array node as a SIBLING of the existing `host:` node, BEFORE the closing `->end()` that ends the `rootNode()->children()` block (currently line 58 — right after the `host:` arrayNode closes on line 57 and before the `->end()` that closes `->children()` on line 58). Inside `origin:`, define an `allow_list` arrayNode with:
       - `->beforeNormalization()->always(...)` converting any non-array string entry to `{origin: <string>, slug: null}` (D-19)
       - `->arrayPrototype()->children()` with `scalarNode('origin')->isRequired()->cannotBeEmpty()` and `scalarNode('slug')->defaultNull()` (D-18 — both `isRequired()` and `cannotBeEmpty()` are load-bearing: `isRequired()` rejects entries missing the `origin` key, `cannotBeEmpty()` rejects empty-string `origin` values at the earliest tier)
       - Default value: empty array (`->defaultValue([])`)

    C. **`loadExtension()`** — branch on `in_array('origin', $config['resolvers'], true)`. When true:
       - Read raw allow-list from `$config['origin']['allow_list'] ?? []`
       - Store as parameter `tenancy.origin.allow_list` (RAW shape — array of `{origin, slug}`). The compiler pass (Plan 02) normalizes it in-place.
       - Register service `tenancy.resolver.origin` pointing at `OriginHeaderResolver::class`, three args: `service('tenancy.provider')->nullOnInvalid()`, `service('logger')->nullOnInvalid()`, `param('tenancy.origin.allow_list')`. Tag `tenancy.resolver` with `['priority' => 25]`.

    D. **`build()`** — append one new line `$container->addCompilerPass(new OriginHeaderResolverConfigPass());` AFTER the existing `addCompilerPass(new CacheDecoratorContractPass());` line and before the `if (interface_exists(MessageBusInterface::class)) {` block. Unconditional — the pass self-gates.

    Default `tenancy.resolvers` list MUST stay `['host', 'header', 'query_param', 'console']` — DO NOT add `'origin'` to the default (D-14, opt-in resolver).
  </behavior>
  <action>
**Edit A: Imports.** Add to the imports block at the top of `src/TenancyBundle.php`, maintaining alphabetical order. After the existing imports:

```php
use Tenancy\Bundle\DependencyInjection\Compiler\CacheDecoratorContractPass;
```

Add (alphabetically after CacheDecoratorContractPass):
```php
use Tenancy\Bundle\DependencyInjection\Compiler\OriginHeaderResolverConfigPass;
```

And after the existing `use Tenancy\Bundle\Resolver\TenantResolverInterface;` keep the file's existing import order; add an entry for OriginHeaderResolver alphabetically (before TenantResolverInterface):
```php
use Tenancy\Bundle\Resolver\OriginHeaderResolver;
use Tenancy\Bundle\Resolver\TenantResolverInterface;
```

**Edit B: `configure()`.** Within the `rootNode()->children()` block, insert the `origin:` arrayNode as a sibling of `host:`. The new block sits between the existing `host:` arrayNode close (currently `->end()` on line 57) and the closing `->end()` on line 58 that ends `->children()`.

Existing snippet:
```php
            ->arrayNode('host')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('app_domain')->defaultNull()->end()
            ->end()
            ->end()
            ->end()
```

Replace with:
```php
            ->arrayNode('host')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('app_domain')->defaultNull()->end()
            ->end()
            ->end()
            ->arrayNode('origin')
            ->addDefaultsIfNotSet()
            ->children()
            ->arrayNode('allow_list')
            ->defaultValue([])
            ->beforeNormalization()
                ->always(static function (mixed $v): array {
                    if (!is_array($v)) {
                        return [];
                    }

                    return array_map(
                        static fn (mixed $entry): mixed => is_string($entry)
                            ? ['origin' => $entry, 'slug' => null]
                            : $entry,
                        $v,
                    );
                })
            ->end()
            ->arrayPrototype()
            ->children()
            ->scalarNode('origin')->isRequired()->cannotBeEmpty()->end()
            ->scalarNode('slug')->defaultNull()->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->end()
```

Indentation may be reflow-adjusted by php-cs-fixer's `@Symfony` ruleset; the LOGICAL nesting above is correct. The `beforeNormalization()->always(...)` callback returns either a string-converted entry as a map OR the entry as-is — Symfony then runs the arrayPrototype validator on the result. This implements D-19's shorthand support.

**Note on `isRequired()` + `cannotBeEmpty()`:** Both calls on the `origin` scalar are load-bearing (D-18 + earliest-tier rejection). `isRequired()` ensures map-form entries that omit the `origin` key fail config validation; `cannotBeEmpty()` rejects empty-string `origin` values BEFORE they reach Plan 02's compiler-pass normalization (defense in depth). If `@Symfony` php-cs-fixer reformats the chain across multiple lines, that's fine — the acceptance criteria below check each call independently.

**Edit C: `loadExtension()`.** After the existing `->set('tenancy.resolvers', $config['resolvers'])` line (around line 93), and BEFORE the existing `// Always-on: EntityManagerResetListener` block (around line 96), add the origin block:

```php
        if (in_array('origin', $config['resolvers'], true)) {
            /** @var array<string, mixed> $originConfig */
            $originConfig = $config['origin'] ?? [];
            $rawAllowList = $originConfig['allow_list'] ?? [];

            $builder->setParameter('tenancy.origin.allow_list', $rawAllowList);

            $services = $container->services();
            $services->set('tenancy.resolver.origin', OriginHeaderResolver::class)
                ->args([
                    service('tenancy.provider')->nullOnInvalid(),
                    service('logger')->nullOnInvalid(),
                    param('tenancy.origin.allow_list'),
                ])
                ->tag('tenancy.resolver', ['priority' => 25]);
        }
```

Notes:
- The raw allow-list parameter is the un-normalized shape `list<array{origin: string, slug: ?string}>`. `OriginHeaderResolverConfigPass` (Plan 02) runs LATER (compiler passes execute after extensions) and rewrites the parameter in place with the full D-17 normalized shape. The runtime resolver gets the normalized shape.
- `service('logger')->nullOnInvalid()` matches existing pattern at line 100 (`service('doctrine')->nullOnInvalid()`). `psr/log` is transitively present via http-kernel; no new `require` entry needed (D-12).
- The conditional placement matters: it must run BEFORE the early-return-style branches below (`if ($databaseConfig['enabled'])`, `if ($config['driver'] === 'shared_db')`) so the parameter exists regardless of driver mode. Insertion before the always-on EntityManagerResetListener block keeps the file flow consistent: config → parameters → always-on services → conditional services.

**Edit D: `build()`.** Existing block (lines 163-173):
```php
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new BootstrapperChainPass());
        $container->addCompilerPass(new ResolverChainPass());
        $container->addCompilerPass(new CacheDecoratorContractPass());
        if (interface_exists(MessageBusInterface::class)) {
            // Priority 1 ensures this runs BEFORE MessengerPass (priority 0) which consumes the parameter
            $container->addCompilerPass(new MessengerMiddlewarePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1);
        }
    }
```

Add one line after the `CacheDecoratorContractPass` registration:

```php
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new BootstrapperChainPass());
        $container->addCompilerPass(new ResolverChainPass());
        $container->addCompilerPass(new CacheDecoratorContractPass());
        $container->addCompilerPass(new OriginHeaderResolverConfigPass());
        if (interface_exists(MessageBusInterface::class)) {
            // Priority 1 ensures this runs BEFORE MessengerPass (priority 0) which consumes the parameter
            $container->addCompilerPass(new MessengerMiddlewarePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1);
        }
    }
```

Pass order rationale: `OriginHeaderResolverConfigPass` reads `tenancy.resolvers` and `tenancy.origin.allow_list` parameters set in `loadExtension()` — Symfony runs extensions before compiler passes, so the parameters are available. The pass should run AFTER `ResolverChainPass` is registered (purely conceptual — the two passes don't depend on each other's outputs; placement is for diff readability).
  </action>
  <verify>
    <automated>php -l src/TenancyBundle.php &amp;&amp; vendor/bin/phpstan analyse src/TenancyBundle.php --level=9 --no-progress 2>&amp;1 | tail -5 &amp;&amp; vendor/bin/phpunit --testsuite unit --no-coverage 2>&amp;1 | tail -5</automated>
  </verify>
  <acceptance_criteria>
    - `php -l src/TenancyBundle.php` exits 0
    - `grep -cF "addCompilerPass(new OriginHeaderResolverConfigPass())" src/TenancyBundle.php` outputs `1`
    - `grep -cF "->arrayNode('origin')" src/TenancyBundle.php` outputs `1`
    - `grep -cF "->arrayNode('allow_list')" src/TenancyBundle.php` outputs `1`
    - `grep -cF "tenancy.resolver.origin" src/TenancyBundle.php` outputs `1`
    - `grep -cF "OriginHeaderResolver::class" src/TenancyBundle.php` outputs `1`
    - `grep -cF "'priority' => 25" src/TenancyBundle.php` outputs `1`
    - Resolver-enablement guard present — use `-F` to neutralize the `[`/`]` regex hazard. Inside a Bash double-quoted heredoc the `$` is preserved by single-quoting around the literal: `grep -cF "in_array('origin', \$config['resolvers'], true)" src/TenancyBundle.php` outputs `1`. If the executor's shell expands `$config` to empty, swap to single quotes: `grep -cF 'in_array('"'"'origin'"'"', $config['"'"'resolvers'"'"'], true)' src/TenancyBundle.php` outputs `1`.
    - `grep -cF "use Tenancy\Bundle\DependencyInjection\Compiler\OriginHeaderResolverConfigPass;" src/TenancyBundle.php` outputs `1`
    - `grep -cF "use Tenancy\Bundle\Resolver\OriginHeaderResolver;" src/TenancyBundle.php` outputs `1`
    - **Default resolvers value unchanged** (D-14 lock): `grep -cF "defaultValue(['host', 'header', 'query_param', 'console'])" src/TenancyBundle.php` outputs `1`. The `-F` flag is REQUIRED here — without it the `[...]` becomes a regex character class and would match unintended partial strings, defeating the lock.
    - **`isRequired()` survives on the `origin` scalar** (D-18 / load-bearing): `grep -cF "->scalarNode('origin')->isRequired()" src/TenancyBundle.php` outputs `1`. If php-cs-fixer splits the chain across lines so the scalar declaration and `->isRequired()` end up on different lines, relax to two separate single-pattern checks: `grep -cF "->scalarNode('origin')" src/TenancyBundle.php` outputs `1` AND `grep -cF "->isRequired()" src/TenancyBundle.php` outputs at least `1` (locating the call somewhere in the file; verify by visual inspection that it is attached to the `origin` scalar inside the `tenancy.origin.allow_list` prototype).
    - **`cannotBeEmpty()` survives on the `origin` scalar** (earliest-tier rejection): `grep -cF "->cannotBeEmpty()" src/TenancyBundle.php` outputs at least `1`. If the bundle adds other `cannotBeEmpty()` calls elsewhere in `configure()`, raise the expected count accordingly OR pin to the specific chain: `grep -cF "->scalarNode('origin')->isRequired()->cannotBeEmpty()" src/TenancyBundle.php` outputs `1`. Choose the pinned form if formatting is single-line, the relaxed form if php-cs-fixer split the chain.
    - `vendor/bin/phpstan analyse src/TenancyBundle.php --level=9 --no-progress` exits 0
    - `vendor/bin/phpunit --testsuite unit --no-coverage` exits 0 (existing unit tests + Plans 01 & 02 tests all still pass)
  </acceptance_criteria>
  <done>TenancyBundle configures + wires + registers the new resolver and compiler pass; entire unit test suite stays green; PHPStan level 9 clean.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| `tenancy.yaml` (app config) → Symfony Config component → `ContainerBuilder` parameters | Misconfigured YAML must not silently bypass the compile-time guard |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-17-03 | Denial of service (silent footgun via opt-in confusion) | `TenancyBundle::configure()` + default `tenancy.resolvers` list | mitigate | Origin resolver is OPT-IN per D-14. Default `tenancy.resolvers` value remains `['host', 'header', 'query_param', 'console']`. Compile-time guard (Plan 02) catches "user added 'origin' to resolvers but forgot to populate allow_list". Validated by: existing `defaultValue` line unchanged + Plan 02 compiler pass active for any config that includes `'origin'` |
</threat_model>

<verification>
- Static: `php -l src/TenancyBundle.php src/DependencyInjection/Compiler/ResolverChainPass.php`
- Type: `vendor/bin/phpstan analyse --level=9 --no-progress` (full project)
- Tests: `vendor/bin/phpunit --testsuite unit --no-coverage` (Plans 01 & 02 tests continue passing; no regressions in existing unit suite)
- Style: `vendor/bin/php-cs-fixer check src/TenancyBundle.php src/DependencyInjection/Compiler/ResolverChainPass.php`
</verification>

<success_criteria>
- `vendor/bin/phpunit --testsuite unit` exits 0 (full unit suite green, including new Plans 01 & 02 tests)
- `vendor/bin/phpstan analyse --level=9` exits 0 across the project
- `tenancy.resolver.origin` service appears in container ONLY when `'origin'` is in `tenancy.resolvers` (verified in Plan 04 integration test, not here)
- `OriginHeaderResolverConfigPass` runs unconditionally during container build (validated by Plan 04's misconfigured-allow-list scenario)
- No new files created; only the two listed files modified
</success_criteria>

<output>
After completion, create `.planning/phases/17-origin-header-resolver/17-P03-SUMMARY.md` capturing: which lines were inserted into `TenancyBundle.php`, confirmation that the default `tenancy.resolvers` value was NOT changed, and any `php-cs-fixer` reformatting performed.
</output>
</content>
</invoke>