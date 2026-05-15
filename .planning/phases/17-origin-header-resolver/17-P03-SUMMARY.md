---
phase: 17
plan: P03
subsystem: bundle-wiring
tags: [origin-header, bundle-wiring, compiler-pass, configuration, dependency-injection]
dependency_graph:
  requires:
    - src/Resolver/OriginHeaderResolver.php (Plan 01)
    - src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php (Plan 02)
  provides:
    - src/TenancyBundle.php (updated configure/loadExtension/build)
    - src/DependencyInjection/Compiler/ResolverChainPass.php (updated BUILT_IN_RESOLVER_MAP)
  affects:
    - Container compilation (OriginHeaderResolverConfigPass now registered unconditionally)
    - tenancy.resolver.origin service (registered when 'origin' in tenancy.resolvers)
    - tenancy.origin.allow_list parameter (set when 'origin' in tenancy.resolvers)
    - resolver chain filtering (short-name 'origin' resolves to OriginHeaderResolver FQCN)
tech_stack:
  added: []
  patterns:
    - Conditional service registration on config['resolvers'] membership check
    - beforeNormalization()->always() for shorthand-to-map config conversion
    - arrayPrototype with isRequired()+cannotBeEmpty() scalar constraints
    - @var PHPDoc cast for PHPStan level 9 mixed array access
key_files:
  created: []
  modified:
    - src/DependencyInjection/Compiler/ResolverChainPass.php
    - src/TenancyBundle.php
decisions:
  - "PHPStan level 9 requires explicit @var cast for config['resolvers'] (mixed array access) before in_array call"
  - "rawAllowList annotated as list<array<string, mixed>> so setParameter() satisfies array|bool|float|int|string|UnitEnum|null constraint"
  - "Six ->end() calls correctly close: arrayPrototype->children, arrayPrototype, allow_list arrayNode, origin->children, origin arrayNode, rootNode->children"
metrics:
  duration: 340s
  completed: 2026-05-15
  tasks_completed: 2
  files_changed: 2
---

# Phase 17 Plan P03: Bundle wiring — configure() + loadExtension() + build() + ResolverChainPass map Summary

**One-liner:** Wired `OriginHeaderResolver` and `OriginHeaderResolverConfigPass` into `TenancyBundle` by adding the `origin:` config node with shorthand normalization, conditional `tenancy.resolver.origin` service registration at priority 25, unconditional compiler pass registration, and the `'origin'` short-name entry in `ResolverChainPass::BUILT_IN_RESOLVER_MAP`.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Add 'origin' to ResolverChainPass::BUILT_IN_RESOLVER_MAP | 2bd1db0 | src/DependencyInjection/Compiler/ResolverChainPass.php |
| 2 | Wire TenancyBundle — configure(), loadExtension(), build() | fab8523 | src/TenancyBundle.php |

## Verification Results

- `php -l src/TenancyBundle.php src/DependencyInjection/Compiler/ResolverChainPass.php`: **No syntax errors**
- `phpstan analyse src/ --level=9 --memory-limit=512M --no-progress`: **No errors**
- `vendor/bin/phpunit --testsuite unit --no-coverage`: **248 tests, 639 assertions, OK**
- `php-cs-fixer check` on both modified files: **No fixable files**

## Changes Applied

### Task 1: ResolverChainPass.php

- Added `use Tenancy\Bundle\Resolver\OriginHeaderResolver;` import alphabetically after `HostResolver`, before `QueryParamResolver`
- Added `'origin' => OriginHeaderResolver::class,` to `BUILT_IN_RESOLVER_MAP` between `'header'` and `'query_param'`
- Map now has 5 entries; short-name `'origin'` resolves to the FQCN for runtime chain filtering

### Task 2: TenancyBundle.php

**Imports added (2 new use statements):**
- `use Tenancy\Bundle\DependencyInjection\Compiler\OriginHeaderResolverConfigPass;` (alphabetically after `CacheDecoratorContractPass`)
- `use Tenancy\Bundle\Resolver\OriginHeaderResolver;` (alphabetically after `Filter\TenantAwareFilter`, before `Resolver\TenantResolverInterface`)

**configure() — new `origin:` arrayNode:**
- Added as sibling of existing `host:` node, before `->validate()` closing the rootNode children
- Contains `allow_list` arrayNode with:
  - `->defaultValue([])` — empty list is valid (compiler pass enforces non-empty only when 'origin' is in resolvers)
  - `->beforeNormalization()->always(...)` — converts string entries to `['origin' => $string, 'slug' => null]` (D-19 shorthand support)
  - `->arrayPrototype()->children()` with `scalarNode('origin')->isRequired()->cannotBeEmpty()` and `scalarNode('slug')->defaultNull()`
- Default `tenancy.resolvers` value **NOT changed** — remains `['host', 'header', 'query_param', 'console']` (D-14 opt-in)

**loadExtension() — conditional origin wiring:**
- Guards with `in_array('origin', $configuredResolvers, true)` (PHPStan-safe via `@var list<string>` cast)
- Sets `tenancy.origin.allow_list` parameter with raw `{origin, slug}` shape (compiler pass normalizes to D-17 shape)
- Registers `tenancy.resolver.origin` service pointing at `OriginHeaderResolver::class` with 3 args:
  - `service('tenancy.provider')->nullOnInvalid()`
  - `service('logger')->nullOnInvalid()`
  - `param('tenancy.origin.allow_list')`
- Tagged `tenancy.resolver` with `['priority' => 25]`

**build() — unconditional compiler pass registration:**
- Added `$container->addCompilerPass(new OriginHeaderResolverConfigPass());` after `CacheDecoratorContractPass` line
- Pass is unconditional; it self-gates inside `process()` when 'origin' is not configured (D-15)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] PHPStan level 9 type errors on mixed array access**
- **Found during:** Task 2 PHPStan verification
- **Issue 1:** `$config['resolvers']` typed as `mixed` by PHPStan; `in_array(string, mixed, true)` fails level 9 type check (`argument.type`)
- **Issue 2:** `$rawAllowList = $originConfig['allow_list'] ?? []` typed as `mixed`; `ContainerBuilder::setParameter()` requires `array|bool|float|int|string|UnitEnum|null` (`argument.type`)
- **Fix:** Added `@var list<string> $configuredResolvers` before the `in_array` call; added `@var list<array<string, mixed>> $rawAllowList` before `setParameter()` call. Pattern mirrors existing `$hostConfig` and `$databaseConfig` docblocks in the same method.
- **Files modified:** src/TenancyBundle.php
- **Commit:** fab8523

## Known Stubs

None — both modified files contain no placeholder values. `tenancy.resolver.origin` is registered with live service references. The allow-list parameter flows from config to compiler pass normalization to resolver constructor at runtime.

## Threat Coverage

| Threat ID | Mitigation | Evidence |
|-----------|-----------|---------|
| T-17-03 | Origin resolver opt-in: default resolvers unchanged; compiler pass rejects empty allow-list when 'origin' opted in | Default `->defaultValue(['host', 'header', 'query_param', 'console'])` line intact; `OriginHeaderResolverConfigPass` registered unconditionally in `build()` |

## Threat Flags

None — these are edits to existing bundle wiring files. No new network endpoints, auth paths, file access patterns, or schema changes introduced. The new `tenancy.origin.allow_list` parameter is a container parameter set at compile time, not a runtime trust boundary.

## Self-Check

- `src/DependencyInjection/Compiler/ResolverChainPass.php` — EXISTS
- `src/TenancyBundle.php` — EXISTS
- Commit `2bd1db0` (ResolverChainPass) — FOUND
- Commit `fab8523` (TenancyBundle) — FOUND
- `'origin' => OriginHeaderResolver::class` in BUILT_IN_RESOLVER_MAP — CONFIRMED
- `->arrayNode('origin')` in configure() — CONFIRMED
- `addCompilerPass(new OriginHeaderResolverConfigPass())` in build() — CONFIRMED
- `in_array('origin', $configuredResolvers, true)` guard in loadExtension() — CONFIRMED
- Default resolvers unchanged (`['host', 'header', 'query_param', 'console']`) — CONFIRMED
- 248 unit tests green — CONFIRMED

## Self-Check: PASSED
