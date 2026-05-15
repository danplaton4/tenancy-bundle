---
phase: 17-origin-header-resolver
reviewed: 2026-05-15T00:00:00Z
depth: standard
files_reviewed: 16
files_reviewed_list:
  - CHANGELOG.md
  - docs/user-guide/origin-header-resolver.md
  - src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php
  - src/DependencyInjection/Compiler/ResolverChainPass.php
  - src/Resolver/OriginHeaderResolver.php
  - src/TenancyBundle.php
  - tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php
  - tests/Integration/Resolver/Support/RecordingLogger.php
  - tests/Integration/Resolver/Support/StubTenant.php
  - tests/Integration/Resolver/Support/StubTenantProvider.php
  - tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php
  - tests/Unit/DependencyInjection/Compiler/ResolverChainPassTest.php
  - tests/Unit/Resolver/OriginHeaderResolverTest.php
  - tests/Unit/Resolver/Support/RecordingLogger.php
  - tests/bootstrap.php
  - .gitignore
findings:
  blocker: 0
  warning: 3
  total: 3
status: issues_found
---

# Phase 17: Code Review Report (Re-Review)

**Reviewed:** 2026-05-15
**Depth:** standard
**Status:** issues_found

## Summary

This re-review verifies the four fixes applied by `/gsd-code-review-fix 17` (commits 79749f6, 672bf3f, 904b009, 71e32c7) and re-examines the rest of the implementation adversarially.

**Verification of prior findings:**

| Prior finding | Status | Notes |
|---|---|---|
| WR-01 (wildcard + explicit slug silently dropped) | FIXED | `OriginHeaderResolverConfigPass.php:120-122` now throws; new unit test `testThrowsOnWildcardEntryWithExplicitSlug` locks the behavior. |
| WR-02 (misleading error for `https://*`) | FIXED | Pure-`*` host now produces "invalid wildcard suffix" message (line 102-104). Test `testThrowsOnPureStarWildcard` updated accordingly. |
| WR-03 (autoload triggered on arbitrary strings) | FIXED | `ResolverChainPass.php:46-49` now gates `class_exists`/`interface_exists` behind an FQCN-shaped regex and throws loudly on unrecognized short names. New unit tests `testProcessThrowsOnUnknownShortName` and `testProcessThrowsOnNonFqcnShapedString` lock the behavior. |
| WR-04 (`RecordingLogger::$records` public mutable) | FIXED | Both Unit and Integration `RecordingLogger.php` now declare `$records` private and expose `reset()` / `records()`. Integration test `setUp()` calls `$logger->reset()`. |

**However — new findings (carry-overs from the prior review's Info section that the fix workflow did not address, plus one regression observation):**

The previous review's five **Info** items were not in scope for the fix workflow (it ran WR-01..WR-04 only), so they remain. Several of them are upgraded here to **WARNING** because they materially affect maintainability and operator diagnosis, and one of them (test-helper duplication) is now actively drifting — the two `RecordingLogger.php` copies were touched independently for WR-04 and are still byte-identical, but the next divergent edit will be a silent bug.

No BLOCKER issues found. The fixes are correct, minimally-scoped, and well-tested. The implementation is safe to ship subject to the WARNING items below.

## Warnings

### WR-01: Duplicate `RecordingLogger` implementations remain (drift risk amplified by WR-04 fix)

**File:** `tests/Unit/Resolver/Support/RecordingLogger.php` and `tests/Integration/Resolver/Support/RecordingLogger.php`
**Issue:** The two files are still byte-identical aside from the namespace and docblock. The WR-04 fix had to change BOTH files in lockstep (commit 71e32c7); the next person who only updates one of them will introduce a silent test-isolation bug. The previous review flagged this as Info (IN-01) on the assumption it would not actively drift — the WR-04 fix demonstrated it already needs lockstep maintenance, so the duplication is no longer hypothetical.

This is a classic test-only DRY violation that only surfaces when the two copies diverge — at which point one suite will silently keep stale records between tests while the other will reset properly. By then the regression is invisible because tests still go green.

**Fix:** Promote a single `RecordingLogger` to a shared location and delete one copy. Two options:

1. **Shared namespace** — move to `tests/Support/RecordingLogger.php` under `Tenancy\Bundle\Tests\Support\RecordingLogger` and import from both suites.
2. **Canonical Unit copy** — keep `tests/Unit/Resolver/Support/RecordingLogger.php`, delete the Integration copy, and change `use Tenancy\Bundle\Tests\Integration\Resolver\Support\RecordingLogger;` in `OriginHeaderResolverIntegrationTest.php` (and `ReplaceLoggerPass::process()` line 65) to `use Tenancy\Bundle\Tests\Unit\Resolver\Support\RecordingLogger;`.

Option 1 is cleaner. The bootstrap (`tests/bootstrap.php` line 11) already maps `Tenancy\Bundle\Tests\\` to `tests/`, so a `Tenancy\Bundle\Tests\Support\RecordingLogger` placed in `tests/Support/RecordingLogger.php` autoloads with zero composer.json changes.

### WR-02: `OriginHeaderResolverConfigPass::describe()` discards index + value for non-string, non-array-with-origin entries

**File:** `src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php:48-50, 138-148`
**Issue:** When an allow-list entry is neither a string nor a `['origin' => string, …]` shape, `describe()` returns `get_debug_type($entry)` — so the error message becomes:

```
tenancy.origin.allow_list entry "array" is unparseable — must be an absolute origin URL (scheme://host[:port])
```

…or `"int"`, `"bool"`, etc. The user has no way to know **which index** in their YAML is bad or **what value** they actually passed. In a 20-entry production allow-list, this turns a 30-second fix into a search-and-replace exercise. The whole point of compile-time validation is to give the operator actionable feedback.

This was flagged as IN-04 in the prior review but not addressed. Re-classifying to WARNING because the bundle's failure mode for misconfiguration is one of its advertised features — every other validation path names the offending value precisely; this one is the lone gap.

**Fix:** Thread the index through `normalizeEntry()`:

```php
foreach ($allowList as $index => $entry) {
    $normalized[] = $this->normalizeEntry($entry, $index);
}

private function normalizeEntry(mixed $entry, int $index): array
{
    if (!is_array($entry) || !isset($entry['origin']) || !is_string($entry['origin']) || '' === $entry['origin']) {
        throw new \InvalidArgumentException(sprintf(
            'tenancy.origin.allow_list[%d] (%s) is unparseable — must be an absolute origin URL (scheme://host[:port])',
            $index,
            $this->describe($entry),
        ));
    }
    // … and so on for every throw in this method
}
```

Also enrich `describe()` to render scalar values via `var_export($entry, true)` truncated to ~80 chars rather than just `get_debug_type` — so `"int"` becomes `"int(42)"` and `"array"` becomes a serialized preview.

### WR-03: `RecordingLogger::log()` accepts `mixed $level` but `warnings()` only matches the string `'warning'`

**File:** `tests/Unit/Resolver/Support/RecordingLogger.php:22, 43` and `tests/Integration/Resolver/Support/RecordingLogger.php:22, 41`
**Issue:** `log($level, …)` types `$level` as `mixed` (per PSR-3 `LoggerInterface::log()` which uses `mixed`). The `warnings()` filter does `'warning' === $r['level']` — strict string comparison. If anyone logs with the PSR-3 constant `Psr\Log\LogLevel::WARNING` (which equals the string `'warning'`) the filter matches. But if a future change in the resolver uses `LogLevel::warning()` style or an enum, the filter silently misses.

More importantly: `OriginHeaderResolver::resolve()` line 79 calls `$this->logger->warning(...)` — `AbstractLogger::warning()` dispatches to `log(LogLevel::WARNING, …)` where `LogLevel::WARNING === 'warning'`. So today's behavior is correct, but the test helper's filter is needlessly fragile to PSR-3 conventions and obscures what it's really doing.

**Fix:** Compare case-insensitively or use the PSR-3 constant explicitly:

```php
use Psr\Log\LogLevel;

public function warnings(): array
{
    return array_values(array_filter(
        $this->records,
        static fn (array $r): bool => LogLevel::WARNING === $r['level'],
    ));
}
```

This is minor on its own, but the same pattern probably wants to grow `errors()` / `notices()` helpers later, and pinning the comparison to the PSR-3 constant prevents one whole class of "test passes locally, fails when someone uppercases the level" bugs.

## Notes on items NOT re-flagged

The prior review's IN-02 (wildcard slug regex validation), IN-03 (log-amplification rate-limiting), and IN-05 (parameter-setter style inconsistency in `TenancyBundle::loadExtension`) are real but acceptable for v1 — they remain as-is. They do not warrant blocking the phase; they are appropriate follow-up issues for a separate hardening pass.

The four fixes themselves were verified against the source and tests:

- **commit 79749f6 (WR-01)** — `OriginHeaderResolverConfigPass.php:120-122` correctly throws before reaching the array assembly. Test `testThrowsOnWildcardEntryWithExplicitSlug` exercises it.
- **commit 672bf3f (WR-02)** — `OriginHeaderResolverConfigPass.php:101-110` splits the bare-wildcard branch from the mid-string-wildcard branch with distinct messages. Tests `testThrowsOnPureStarWildcard` and `testThrowsOnWildcardWithSingleLabelSuffix` cover the new message; `testThrowsOnMidStringWildcard` and `testThrowsOnMultiLabelWildcard` still cover the original.
- **commit 904b009 (WR-03)** — Regex `/^[A-Z][A-Za-z0-9_]*(\\\\[A-Z][A-Za-z0-9_]*)+$/` requires at least one namespace separator and PascalCase segments. This rejects bare class names (e.g. `MyResolver`) — that may surprise users with single-namespace classes but is the safer default. Tests `testProcessThrowsOnUnknownShortName`, `testProcessThrowsOnNonFqcnShapedString`, `testProcessAcceptsFqcnShapedCustomResolverName` collectively prove the new branching.
- **commit 71e32c7 (WR-04)** — Both `RecordingLogger.php` files have private `$records`, public `reset()` and `records()`. Integration test `setUp()` calls `$logger->reset()` correctly. No callers in either suite touch `$records` directly.

---

_Reviewed: 2026-05-15_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard (re-review)_
