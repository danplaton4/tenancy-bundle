---
phase: 15-architectural-fixes-v0-2
plan: 01
status: complete
requirements: [FIX-01]
closes_issues: [5]
---

# Plan 15-01: Cache Decorator Contract Parity — SUMMARY

## What Shipped

- **`TenantAwareCacheAdapter`** — widened from `AdapterInterface, NamespacedPoolInterface` to the full `cache.app` substitution surface: `AdapterInterface, CacheInterface, NamespacedPoolInterface, PruneableInterface, ResettableInterface`. Class is now non-final (extensible), `$inner` and helpers are `protected` so the tag-aware sibling can extend.
- **`TenantAwareTagAwareCacheAdapter`** — new sibling decorator that extends the base and implements `TagAwareAdapterInterface, TagAwareCacheInterface`. Delegates `invalidateTags()` through the tenant-scoped `pool()`.
- **`CacheDecoratorContractPass`** — compile-time guard that inspects every Tenancy-owned decorator and its decorated target. Throws `LogicException` at container compile if any `Symfony\*` interface on the decorated class is missing from the decorator. Filters non-Symfony interfaces (e.g. `Psr\Log\LoggerAwareInterface`). Uses `ChildDefinition::getParent()` recursion to resolve the effective class of parent-definition services like `cache.app`.
- **`TenancyBundle::build()`** — registers the new pass alongside `BootstrapperChainPass` and `ResolverChainPass`.
- **`config/services.php`** — adds the second decorator wiring (`decorate('cache.app.taggable')`).
- **Unit tests** — cover the 5-interface parity, tenant-scoped `get/delete`, pool-wide `prune/reset`, the clone pattern, the sibling's `invalidateTags`, and all 5 pass scenarios (no-op × 2, happy path, sad path, non-Symfony filter).
- **Integration tests**:
  - `TenantAwareCacheAdapterContractTest` — boots a stock FrameworkBundle + TenancyBundle kernel and proves every `cache.app` alias (`CacheItemPoolInterface`, `CacheInterface`, `NamespacedPoolInterface`, `TagAwareCacheInterface`) resolves to the `TenantAware*` decorators without `TypeError`.
  - `DoctrineTenantProviderBootTest` — boots a dedicated Doctrine-ORM kernel and instantiates the real `DoctrineTenantProvider` (whose constructor type-hints `CacheInterface`). This is the automated regression for issue #5.

## Tests

- PHPUnit: **283 tests / 688 assertions** — full suite green.
- PHPStan: **level 9, 43 files** — no errors.
- php-cs-fixer: `@Symfony` ruleset — clean.

## Commits (worktree branch `worktree-agent-a8317269`, base `68840e1`)

1. `1758739` test(15-01): add failing tests for full cache.app substitution surface
2. `1b019af` feat(15-01): widen TenantAwareCacheAdapter to full cache.app substitution surface
3. `b57669d` test(15-01): add failing tests for TenantAwareTagAwareCacheAdapter sibling
4. `dd35102` feat(15-01): add TenantAwareTagAwareCacheAdapter + wire cache.app.taggable decorator
5. `2c44253` test(15-01): add failing tests for CacheDecoratorContractPass
6. `01848db` feat(15-01): add CacheDecoratorContractPass and register in TenancyBundle::build()
7. `21c40b1` test(15-01): add integration tests proving issue #5 is closed
8. `19510c3` fix(15-01): use ChildDefinition for parent-definition recursion + style pass

## Key Files

### Created
- `src/Cache/TenantAwareTagAwareCacheAdapter.php`
- `src/DependencyInjection/Compiler/CacheDecoratorContractPass.php`
- `tests/Unit/Cache/TenantAwareTagAwareCacheAdapterTest.php`
- `tests/Unit/DependencyInjection/Compiler/CacheDecoratorContractPassTest.php`
- `tests/Integration/Cache/TenantAwareCacheAdapterContractTest.php`
- `tests/Integration/Cache/DoctrineTenantProviderBootTest.php`

### Modified
- `src/Cache/TenantAwareCacheAdapter.php` (5-interface parity, non-final, protected members)
- `src/TenancyBundle.php` (register `CacheDecoratorContractPass`)
- `config/services.php` (`tenancy.cache_adapter.taggable` decorator)
- `tests/Unit/Cache/TenantAwareCacheAdapterTest.php` (expanded mock intersection + new parity/pool-wide tests)

## Deviations

- **Task 3 committed across two commits** instead of one (`2c44253` RED, `01848db` GREEN) because the initial attempt was interrupted mid-task; recovery commit preserved the implementation.
- **Task 4 Doctrine boot test** — the plan suggested reusing the existing `DoctrineTestKernel`, but that kernel uses `ReplaceTenancyProviderPass` which swaps the real `DoctrineTenantProvider` for `NullTenantProvider`, defeating the purpose of this regression test. Created a dedicated `DoctrineTenantProviderBootTestKernel` that does NOT replace the provider, so the container actually instantiates the real class against the decorated `cache.app` — which is exactly what issue #5 reproduces.
- **Final style/PHPStan pass landed as commit 8** rather than inline with Task 3, due to a post-commit discovery that `Definition::getParent()` is only available on `ChildDefinition`. No behavioral change.

## Closes

`Fixes #5` — should appear in the merge commit footer.
