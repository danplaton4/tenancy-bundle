---
phase: 17-origin-header-resolver
fixed_at: 2026-05-15T00:00:00Z
review_path: .planning/phases/17-origin-header-resolver/17-REVIEW.md
iteration: 1
findings_in_scope: 4
fixed: 4
skipped: 0
status: all_fixed
---

# Phase 17: Code Review Fix Report

**Fixed at:** 2026-05-15
**Source review:** `.planning/phases/17-origin-header-resolver/17-REVIEW.md`
**Iteration:** 1

**Summary:**
- Findings in scope: 4 (all WR-* warnings; IN-* findings deferred per `fix_scope: critical_warning`)
- Fixed: 4
- Skipped: 0

After each fix the full quality gate suite was rerun in an isolated worktree:
`vendor/bin/php-cs-fixer check`, `vendor/bin/phpstan analyse --memory-limit=512M`,
`vendor/bin/phpunit`. Final test count: **338 tests, 835 assertions, all green**
(up from 333; +5 new tests added for the regressions fixed below).

The local git `pre-commit` hook ran PHPStan without a memory limit and crashed at
the default 128M ceiling — unrelated to the changes (PHPStan reports `[OK] No errors`
when invoked with `--memory-limit=512M`, matching the Claude `pre-commit-quality.sh`
hook). Commits were created with `--no-verify` after all gates passed externally,
which is the documented behavior for parallel worktree agents per the project's
own pre-commit hook (`# Skip if --no-verify is present (parallel worktree agents)`).

## Fixed Issues

### WR-01: Wildcard entries silently discard user-provided `slug`

**Files modified:** `src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php`, `tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php`
**Commit:** `79749f6`
**Applied fix:** Added an explicit guard in `normalizeEntry()` — when an entry is detected as a wildcard (`$isWildcard === true`) and a non-null `slug` is also supplied, throw `InvalidArgumentException` with a message explaining that wildcard entries derive their slug from the matched label at runtime and the two concepts are mutually exclusive. Added a covering unit test `testThrowsOnWildcardEntryWithExplicitSlug` that feeds `['origin' => 'https://*.app.example.com', 'slug' => 'global-tenant']` and asserts the new exception message.

### WR-02: Misleading error message for `https://*` and similar bare-wildcard inputs

**Files modified:** `src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php`, `tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php`
**Commit:** `672bf3f`
**Applied fix:** Restructured the wildcard-detection branch to distinguish three failure modes:
  1. Multiple `*` characters → "mid-string wildcard" (unchanged).
  2. Lone `*` host (`https://*`) → "has an invalid wildcard suffix — wildcard must be \"*.\" followed by at least two labels".
  3. `*.` prefix with a degenerate suffix (`*.com`, `*.`, `*..foo`) → same "invalid wildcard suffix" message.

Updated `testThrowsOnPureStarWildcard` to assert the new, accurate message (previously it locked in the misleading message). Added `testThrowsOnWildcardWithSingleLabelSuffix` to cover the `https://*.com` branch.

### WR-03: `ResolverChainPass` triggers autoload on arbitrary user-supplied strings

**Files modified:** `src/DependencyInjection/Compiler/ResolverChainPass.php`, `tests/Unit/DependencyInjection/Compiler/ResolverChainPassTest.php`
**Commit:** `904b009`
**Applied fix:** Restricted the `class_exists()`/`interface_exists()` fallback to strings that match an FQCN-shaped regex (`/^[A-Z][A-Za-z0-9_]*(\\[A-Z][A-Za-z0-9_]*)+$/`). Anything that is neither a built-in short name nor an FQCN-shaped string now throws `InvalidArgumentException` listing the available built-in short names — eliminating both the silent-typo failure mode (`'orgin'`) and the autoload-on-arbitrary-string vector. Added three tests: `testProcessThrowsOnUnknownShortName` (typo), `testProcessThrowsOnNonFqcnShapedString` (non-FQCN garbage), `testProcessAcceptsFqcnShapedCustomResolverName` (positive path).

### WR-04: `RecordingLogger::$records` exposed as a mutable public array

**Files modified:** `tests/Unit/Resolver/Support/RecordingLogger.php`, `tests/Integration/Resolver/Support/RecordingLogger.php`, `tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php`
**Commit:** `71e32c7`
**Applied fix:** Made `$records` private in both `RecordingLogger` copies and added explicit `reset(): void` and `records(): array` accessor methods. Updated `OriginHeaderResolverIntegrationTest::setUp()` to call `$logger->reset()` instead of writing `$logger->records = []` directly. The (now-duplicated) duplication identified in IN-01 is unchanged — that consolidation is deferred to its own follow-up since it is in `Info` scope.

## Deferred (out of scope: `Info` findings)

The following findings were identified in REVIEW.md but not addressed this iteration because the fix scope is `critical_warning`. They remain valid follow-ups:

- **IN-01:** Duplicate `RecordingLogger` implementations (Unit and Integration copies are still byte-similar after WR-04 — could be consolidated to a shared `tests/Support/`).
- **IN-02:** Stricter slug-label validation in `OriginHeaderResolver::resolve()` before calling `findBySlug()`.
- **IN-03:** Log-amplification mitigation for mismatch warnings (documentation or dedup map).
- **IN-04:** Include array index in `OriginHeaderResolverConfigPass` error messages.
- **IN-05:** Use `$container->parameters()` consistently in `TenancyBundle::loadExtension()` for `tenancy.origin.allow_list`.

---

_Fixed: 2026-05-15_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
