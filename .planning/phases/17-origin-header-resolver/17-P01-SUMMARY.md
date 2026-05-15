---
phase: 17
plan: P01
subsystem: resolver
tags: [origin-header, tenant-resolver, psr-3, unit-tests, tdd]
dependency_graph:
  requires: []
  provides:
    - src/Resolver/OriginHeaderResolver.php
    - tests/Unit/Resolver/Support/RecordingLogger.php
    - tests/Unit/Resolver/OriginHeaderResolverTest.php
  affects:
    - resolver chain (priority 25 slot)
tech_stack:
  added:
    - Psr\Log\LoggerInterface injection (first logger in bundle)
    - Psr\Log\NullLogger default (constructor default value)
  patterns:
    - Final class + private readonly properties (mirrors HeaderResolver)
    - TenantNotFoundException swallow + TenantInactiveException bubble
    - Prefix-free suffix-strip wildcard matching (leftmost label)
    - Structured PSR-3 warning context for X-Tenant-ID mismatch
key_files:
  created:
    - src/Resolver/OriginHeaderResolver.php
    - tests/Unit/Resolver/Support/RecordingLogger.php
    - tests/Unit/Resolver/OriginHeaderResolverTest.php
  modified:
    - tests/bootstrap.php (worktree autoload registration — deviation)
decisions:
  - "NullLogger as constructor default: allows unit testing without logger arg; service wiring (Plan 03) injects the real logger"
  - "Wildcard slug via leftmost label: suffix-strip matches HostResolver pattern but extracts first label not last (Origin is left-anchored subdomain)"
  - "Mismatch comparison is case-insensitive strcasecmp: slugs may differ in case between clients"
metrics:
  duration: 3min
  completed: 2026-05-15
  tasks_completed: 3
  files_changed: 4
---

# Phase 17 Plan P01: OriginHeaderResolver core class + unit tests Summary

**One-liner:** `OriginHeaderResolver` implementing `TenantResolverInterface` with OPTIONS short-circuit, exact+wildcard allow-list matching, `TenantNotFoundException` swallow, and structured PSR-3 mismatch warning; plus 10-case PHPUnit suite via `RecordingLogger` fixture.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Create RecordingLogger PSR-3 fixture | c26613a | tests/Unit/Resolver/Support/RecordingLogger.php |
| 2 | Create OriginHeaderResolver class | 7c781d3 | src/Resolver/OriginHeaderResolver.php |
| 3 | Unit-test OriginHeaderResolver (10 cases) | 5aedf48 | tests/Unit/Resolver/OriginHeaderResolverTest.php, tests/bootstrap.php |
| - | Style fix: php-cs-fixer on RecordingLogger | 4b6ccdc | tests/Unit/Resolver/Support/RecordingLogger.php, .gitignore |

## Verification Results

- `vendor/bin/phpunit --filter OriginHeaderResolverTest --no-coverage`: **10 tests, 28 assertions, OK**
- `phpstan analyse src/Resolver/OriginHeaderResolver.php --level=9`: **No errors**
- `php -l` on all three new files: **No syntax errors**
- `php-cs-fixer check` on all three files: **Clean**

## Normalized Allow-List Entry Shape

The constructor accepts `private readonly array $allowList = []` where each entry is exactly:

```php
[
    'origin'          => 'https://acme.app.example.com:443', // lowercased host, explicit port
    'host'            => 'acme.app.example.com',
    'scheme'          => 'https',
    'port'            => 443,
    'is_wildcard'     => false,
    'wildcard_suffix' => null,          // '.app.example.com' for wildcard entries
    'slug'            => 'acme',        // null for wildcard entries — resolved at runtime
]
```

Plan 02 (compiler pass) must produce this exact shape. Plan 03 (bundle wiring) must pass it as the third constructor arg.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Fixed worktree autoloading for PHPUnit**
- **Found during:** Task 3 (running tests)
- **Issue:** `tests/bootstrap.php` loads `vendor/autoload.php` which uses the main repo's src/ and tests/ as PSR-4 roots; the worktree's src/ and tests/ directories are different paths, so `OriginHeaderResolver` and `RecordingLogger` were not found by the autoloader
- **Fix:** Added `$loader->addPsr4('Tenancy\\Bundle\\', __DIR__.'/../src')` and `$loader->addPsr4('Tenancy\\Bundle\\Tests\\', __DIR__)` to `tests/bootstrap.php` to register worktree paths before the main repo's paths take effect
- **Files modified:** `tests/bootstrap.php`
- **Commit:** 5aedf48
- **Impact:** The main repo's bootstrap.php is unchanged; this deviation only affects the worktree. The orchestrator should evaluate whether this bootstrap fix should be merged or whether the main repo already handles it correctly (it does — no change needed in main repo).

**2. [Rule 1 - Style] php-cs-fixer auto-fixed RecordingLogger**
- **Found during:** Post-Task-3 style check
- **Issue:** `use Stringable;` import should be removed; `\Stringable` used as FQCN per Symfony ruleset
- **Fix:** Auto-fixed via `php-cs-fixer fix`
- **Files modified:** `tests/Unit/Resolver/Support/RecordingLogger.php`
- **Commit:** 4b6ccdc

## Known Stubs

None — all three new files are fully implemented and wire-complete for their stated purpose. The resolver operates stand-alone via its constructor args; Plan 03 wires the container service definition.

## Threat Flags

| Flag | File | Description |
|------|------|-------------|
| threat_flag: information-disclosure | src/Resolver/OriginHeaderResolver.php | Mismatch warning emits `origin_slug` and `header_slug` to PSR-3 logger — mitigated per T-17-04 (structured context for SIEM/forensics, not exposed to HTTP response) |

Threat mitigations T-17-04, T-17-05, T-17-06 are all implemented and covered by tests.

## Self-Check

- `src/Resolver/OriginHeaderResolver.php`: FOUND
- `tests/Unit/Resolver/OriginHeaderResolverTest.php`: FOUND
- `tests/Unit/Resolver/Support/RecordingLogger.php`: FOUND
- Commit `c26613a` (RecordingLogger): FOUND
- Commit `7c781d3` (OriginHeaderResolver): FOUND
- Commit `5aedf48` (unit tests): FOUND

## Self-Check: PASSED
