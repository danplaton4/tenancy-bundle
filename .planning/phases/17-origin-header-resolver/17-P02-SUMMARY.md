---
phase: 17
plan: P02
subsystem: compiler-pass
tags: [origin-header, compiler-pass, compile-time-guard, unit-tests, tdd, security]
dependency_graph:
  requires: []
  provides:
    - src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php
    - tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php
  affects:
    - container compilation (rejects invalid origin allow-list at build time)
    - tenancy.origin.allow_list parameter (normalized in-place)
tech_stack:
  added: []
  patterns:
    - CompilerPassInterface with self-gating short-circuit (mirrors CacheDecoratorContractPass)
    - parse_url + scheme/host/port normalization at compile time
    - Leftmost-wildcard detection via str_starts_with + substr_count
    - Direct ContainerBuilder instantiation in unit tests (no kernel boot)
key_files:
  created:
    - src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php
    - tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php
  modified: []
decisions:
  - "PHPStan level 9 flagged redundant '' === $slug check after non-empty string guard — removed, check simplified to null === $slug for the non-wildcard slug requirement"
  - "php-cs-fixer required Yoda comparison (1 !== substr_count) and removal of @param mixed docblock — fixed before final commit"
metrics:
  duration_seconds: 290
  completed_date: "2026-05-15"
  tasks_completed: 2
  files_created: 2
  files_modified: 0
---

# Phase 17 Plan P02: OriginHeaderResolverConfigPass compile-time guard + unit tests Summary

**One-liner:** Compile-time guard that validates and normalizes `tenancy.origin.allow_list` — rejects empty lists, unparseable URLs, mid-string wildcards, and path-bearing origins at container build time.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Create OriginHeaderResolverConfigPass | 532b462 | src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php |
| 2 | Unit-test OriginHeaderResolverConfigPass (D-23 cases) | 59e6f6b | tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php |

## Verification Results

- `php -l src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php` — No syntax errors
- `vendor/bin/phpstan analyse src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php --level=9` — No errors
- `vendor/bin/phpunit --filter OriginHeaderResolverConfigPassTest --no-coverage` — 14 tests, 40 assertions, OK
- `vendor/bin/php-cs-fixer check --config=.php-cs-fixer.dist.php` — No fixable files
- Full unit suite (238 tests, 611 assertions) — OK

## Normalized Entry Shape Produced

After the pass runs, `tenancy.origin.allow_list` holds entries in the exact D-17 shape expected by Plan 01's `OriginHeaderResolver::__construct`:

```php
[
    'origin' => 'https://acme.app.example.com:443',  // normalized: scheme://host:port
    'host'   => 'acme.app.example.com',               // lowercase
    'scheme' => 'https',
    'port'   => 443,                                   // defaulted per D-02
    'is_wildcard'    => false,
    'wildcard_suffix' => null,                         // '.app.example.com' for wildcards
    'slug'   => 'acme',                                // null for wildcard entries
]
```

## Test Coverage (14 methods)

| Method | Scenario |
|--------|----------|
| testNoOpWhenOriginNotInResolvers | Self-gate: origin not in resolvers → pass returns silently |
| testNoOpWhenResolversParameterAbsent | Self-gate: no resolvers parameter → pass returns silently |
| testThrowsOnEmptyAllowListWhenOriginConfigured | Empty array allow-list → InvalidArgumentException |
| testThrowsOnMissingAllowListParameter | No allow-list parameter → InvalidArgumentException |
| testThrowsOnUnparseableOriginUrl | Non-URL string → InvalidArgumentException |
| testThrowsOnSchemeOtherThanHttpHttps | ftp:// scheme → InvalidArgumentException |
| testThrowsOnMidStringWildcard | app.*.example.com → InvalidArgumentException |
| testThrowsOnMultiLabelWildcard | *.*.example.com → InvalidArgumentException |
| testThrowsOnPureStarWildcard | https://* → InvalidArgumentException |
| testThrowsOnPathInOrigin | https://host/api → InvalidArgumentException |
| testThrowsOnQueryInOrigin | https://host?x=1 → InvalidArgumentException |
| testThrowsOnUserInfoInOrigin | https://user:pass@host → InvalidArgumentException |
| testThrowsOnNonWildcardEntryMissingSlug | Exact origin without slug → InvalidArgumentException |
| testValidMixedAllowListIsNormalized | 3-entry list → normalized, port 443 defaulted, wildcard_suffix correct |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] PHPStan level 9 flagged redundant empty-string comparison**
- **Found during:** Task 1 verification
- **Issue:** `'' === $slug` on line 114 was always false after the prior non-empty string guard at line 111 had already established that `$slug` is `non-empty-string|null`
- **Fix:** Simplified the non-wildcard slug check from `null === $slug || '' === $slug` to `null === $slug`
- **Files modified:** src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php
- **Commit:** 59e6f6b (bundled with style fixes)

**2. [Rule 1 - Style] php-cs-fixer required Yoda comparison and docblock cleanup**
- **Found during:** Task 2 style check
- **Issue:** `substr_count($host, '*') !== 1` needed to be `1 !== substr_count($host, '*')` per @Symfony ruleset; `@param mixed $entry` docblock with trailing blank line needed removal
- **Fix:** Applied both style fixes
- **Files modified:** src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php
- **Commit:** 59e6f6b

## Threat Coverage

| Threat ID | Mitigation | Test |
|-----------|-----------|------|
| T-17-02 | Mid-string wildcard rejection at compile time | testThrowsOnMidStringWildcard, testThrowsOnMultiLabelWildcard, testThrowsOnPureStarWildcard |
| T-17-03 | Empty allow-list hard-fail when origin in resolvers | testThrowsOnEmptyAllowListWhenOriginConfigured, testThrowsOnMissingAllowListParameter |

## Known Stubs

None — the compiler pass is complete with full validation and normalization logic.

## Threat Flags

None — this pass reads/writes only the `tenancy.origin.allow_list` container parameter; no new network endpoints, auth paths, file access, or schema changes introduced.

## Self-Check: PASSED

- `src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php` — EXISTS
- `tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php` — EXISTS
- Commit 532b462 — EXISTS
- Commit 59e6f6b — EXISTS
