---
phase: 17-origin-header-resolver
fixed_at: 2026-05-15T00:00:00Z
review_path: .planning/phases/17-origin-header-resolver/17-REVIEW.md
iteration: 2
findings_in_scope: 3
fixed: 3
skipped: 0
status: all_fixed
---

# Phase 17: Code Review Fix Report (Iteration 2)

**Fixed at:** 2026-05-15
**Source review:** `.planning/phases/17-origin-header-resolver/17-REVIEW.md`
**Iteration:** 2

**Summary:**
- Findings in scope: 3 (WR-01, WR-02, WR-03 — all Warning; no Blocker)
- Fixed: 3
- Skipped: 0

All three new warnings from the re-review are addressed. The full PHPUnit
suite is green after each commit (338 -> 340 tests, +2 new test cases
covering the WR-02 enriched error wording). PHPStan level 9 is clean
and `php-cs-fixer` reports no remaining issues. Iteration 1's REVIEW-FIX
report (which covered WR-01..WR-04 of the original review) is fully
superseded by this file.

## Fixed Issues

### WR-01: Duplicate `RecordingLogger` implementations remain (drift risk amplified by WR-04 fix)

**Files modified:**
- `tests/Support/RecordingLogger.php` (new — canonical shared copy under `Tenancy\Bundle\Tests\Support`)
- `tests/Unit/Resolver/Support/RecordingLogger.php` (deleted)
- `tests/Integration/Resolver/Support/RecordingLogger.php` (deleted)
- `tests/Unit/Resolver/OriginHeaderResolverTest.php` (import updated)
- `tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php` (import updated; imports also auto-reordered by php-cs-fixer)

**Commit:** `be0ff4c`

**Applied fix:** Took option 1 from the review (cleaner, no composer.json changes
required). Created a single shared `Tenancy\Bundle\Tests\Support\RecordingLogger`
at `tests/Support/RecordingLogger.php`. The `tests/bootstrap.php` already maps
`Tenancy\Bundle\Tests\\` to `tests/`, so autoloading works in both suites
without any composer or PHPUnit configuration change. Removed both duplicate
copies and updated the two consumers' `use` statements. The
`ReplaceLoggerPass::process()` in the integration test now constructs the
shared class via its new namespace.

The now-empty `tests/Unit/Resolver/Support/` directory was removed.

### WR-02: `OriginHeaderResolverConfigPass::describe()` discards index + value for non-string, non-array-with-origin entries

**Files modified:**
- `src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php`
- `tests/Unit/DependencyInjection/Compiler/OriginHeaderResolverConfigPassTest.php`

**Commit:** `37f5068`

**Applied fix:** Threaded the array index through `normalizeEntry(mixed $entry, int $index)`
and rewrote every `\InvalidArgumentException` message in the method to follow
the format `tenancy.origin.allow_list[%d] ("%s") <reason>` (or
`tenancy.origin.allow_list[%d] (%s) <reason>` for the type-fallback path).
Enriched `describe()` so:

- Strings render as `"value"` (quoted)
- Arrays with a string `origin` key render as `"origin-value"` (quoted)
- Everything else renders as `<type>: <var_export>` truncated to 80 chars
  (e.g. `int: 42`)

Added two new unit tests:
- `testErrorMessageIncludesIndexAndQuotedOrigin` — verifies a wildcard-with-explicit-slug
  error at index 1 reports `tenancy.origin.allow_list[1] ("https://*.app.example.com")`.
- `testErrorMessageForNonArrayEntryShowsTypeAndValue` — verifies a raw `42` entry at
  index 0 reports `tenancy.origin.allow_list[0] (int: 42) is unparseable`.

All 13 pre-existing `OriginHeaderResolverConfigPassTest` tests still pass
because they use substring matching on the `<reason>` portion of each message
(e.g. `'is unparseable'`, `'contains a mid-string wildcard'`), which is
unchanged by the new prefix. The integration-test assertion on
`'tenancy.origin.allow_list is empty but "origin" is configured in tenancy.resolvers'`
also still matches — that top-level error is generated before the
`normalizeEntry()` loop.

### WR-03: `RecordingLogger::log()` accepts `mixed $level` but `warnings()` only matches the string `'warning'`

**Files modified:**
- `tests/Support/RecordingLogger.php`

**Commit:** `3026663`

**Applied fix:** Imported `Psr\Log\LogLevel` and replaced the bare string
`'warning'` in `warnings()` with `LogLevel::WARNING`. Behaviour-equivalent
today because `AbstractLogger::warning()` dispatches via
`log(LogLevel::WARNING, ...)` and `LogLevel::WARNING === 'warning'`. The
test now reads as "match warning-level records" rather than "match this
magic string." Added an inline comment explaining the PSR-3 contract so
the next maintainer does not revert the constant back to a literal.

All four pre-existing assertions that depend on `warnings()` continue to pass:
- `OriginHeaderResolverTest::testMismatchWithXTenantIdLogsWarningAtWarningLevelWithStructuredContext` (count + level + context)
- `OriginHeaderResolverIntegrationTest::testMismatchWithXTenantIdLogsWarning` (count + context)

---

_Fixed: 2026-05-15_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 2_
