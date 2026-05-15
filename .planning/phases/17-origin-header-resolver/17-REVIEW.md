---
phase: 17-origin-header-resolver
reviewed: 2026-05-15T00:00:00Z
depth: standard
files_reviewed: 15
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
  - tests/Unit/Resolver/OriginHeaderResolverTest.php
  - tests/Unit/Resolver/Support/RecordingLogger.php
  - tests/bootstrap.php
  - .gitignore
findings:
  critical: 0
  warning: 6
  info: 5
  total: 11
status: issues_found
---

# Phase 17: Code Review Report

**Reviewed:** 2026-05-15
**Depth:** standard
**Files Reviewed:** 15
**Status:** issues_found

## Summary

The `OriginHeaderResolver` feature is well-scoped, defensively coded, and well-tested. CORS preflight is short-circuited, the empty-allow-list footgun is a compile-time error, the mismatch-warning shape is locked by both a unit test and an integration test, and the trust model is clearly documented.

No security-critical or correctness-blocking defects were found. However, there are several quality issues — most notably a **silent-misconfiguration footgun** where supplying both an explicit `slug` and a wildcard origin causes the explicit slug to be discarded without error. There are also a few smaller defects in validation precision, test coverage of an unreachable branch, code duplication between two `RecordingLogger` copies, and one stale claim in the user-guide doc.

---

## Warnings

### WR-01: Wildcard entry silently drops user-supplied `slug` (config-pass footgun)

**File:** `src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php:108-124`

**Issue:** When a user writes a wildcard entry with an explicit `slug`, e.g.

```yaml
- { origin: 'https://*.app.example.com', slug: 'pinned' }
```

the pass passes all validation (the `slug` is a non-empty string, and the entry is a wildcard so the "requires explicit slug" check is also skipped) and then unconditionally drops the user's slug on line 123:

```php
'slug' => $isWildcard ? null : $slug,
```

The runtime resolver then derives slug from the leftmost label of the matched Origin, completely ignoring the user's intent. This is exactly the class of silent-misconfiguration footgun the pass was designed to prevent — see the class-level docblock on lines 17-21. The CHANGELOG (line 17) and user-guide (`origin-header-resolver.md` § Configuration) both describe `{origin, slug}` and wildcard shorthand as separate, exclusive forms, so a user combining them is misconfigured.

**Fix:** Reject combined wildcard+slug at compile time:

```php
$slug = $entry['slug'] ?? null;
if (null !== $slug && (!is_string($slug) || '' === $slug)) {
    throw new \InvalidArgumentException(...);
}
if ($isWildcard && null !== $slug) {
    throw new \InvalidArgumentException(sprintf(
        'tenancy.origin.allow_list entry "%s" combines a wildcard origin with an explicit slug — wildcard entries derive their slug from the matched label at runtime; remove "slug" or replace the wildcard with an explicit origin',
        $raw,
    ));
}
if (!$isWildcard && null === $slug) {
    throw new \InvalidArgumentException(...);
}
```

Then add a unit test in `OriginHeaderResolverConfigPassTest` for the new failure mode, and remove the now-redundant `$isWildcard ? null : $slug` ternary on line 123 in favor of just `$slug` (or `null` when wildcard, asserted above).

---

### WR-02: `ResolverChainPass` filter logic is order-dependent and double-checks unnecessarily

**File:** `src/DependencyInjection/Compiler/ResolverChainPass.php:55-74`

**Issue:** The filter loop only skips a tagged resolver if its FQCN is **in** `BUILT_IN_RESOLVER_MAP` **and** not in `$allowedFqcns`. Custom resolvers (FQCN not in the built-in map) pass through unconditionally — that part is documented and intentional. However:

1. `$allowedFqcns` is populated from the configured short-names via the map, OR by accepting names that exist as classes/interfaces (line 46). If a user accidentally configures the FQCN of a **built-in** resolver under `tenancy.resolvers` (e.g. `'Tenancy\\Bundle\\Resolver\\OriginHeaderResolver'` rather than `'origin'`), it would be appended to `$allowedFqcns` by line 48 and accepted. That's permissive but technically inconsistent with the documented short-name interface.
2. The filter is only applied to resolvers tagged with `tenancy.resolver`. The `OriginHeaderResolver` service is registered conditionally in `TenancyBundle::loadExtension` (line 128) only when `'origin'` is in the configured resolvers. So the filter check on line 65 for `OriginHeaderResolver::class` is dead code in practice — if `'origin'` is not in the config, the service is never tagged.

The dead-code path is harmless but creates a maintenance footgun if the conditional registration is ever moved/removed: the filter would silently allow the resolver because it was never registered, and someone may later remove the filter assuming it has no callers.

**Fix:** Either (a) register `OriginHeaderResolver` unconditionally and rely solely on `ResolverChainPass` filtering (matching `HostResolver`/`HeaderResolver` pattern), or (b) drop `OriginHeaderResolver::class` from `BUILT_IN_RESOLVER_MAP` since it doesn't follow the always-registered pattern. Pick one and document the chosen invariant in the pass docblock.

---

### WR-03: Doc claim about "no extra DB roundtrip" is contradicted by the implementation

**File:** `src/Resolver/OriginHeaderResolver.php:75-85`

**Issue:** The inline comment on line 75 reads:

```php
// D-11: peek X-Tenant-ID; warn on mismatch (Origin wins, no extra DB roundtrip).
```

The implementation does **not** do an extra DB lookup for the header slug — correct. But the warning context (line 82) reports `header_slug` as the **raw, unvalidated** value from `X-Tenant-ID`. If the comment intends to convey "we don't validate the header_slug exists," that should be in the doc-comment, not the inline. More importantly, the user-guide doc says:

> "Slug comparison is case-insensitive — `acme` and `ACME` are treated as the same tenant for the purposes of this check."

But case-insensitive `strcasecmp` does **not** mean they are "the same tenant" — it means the warning suppression is case-insensitive. The wording in the doc could mislead an operator into thinking the resolver normalizes tenant slugs. Clarify the doc:

```
Slug comparison for *mismatch detection* is case-insensitive — `acme` and
`ACME` do not produce a warning. Slug resolution itself uses whatever the
allow-list and provider configure (typically lowercase).
```

**Fix:** Update `docs/user-guide/origin-header-resolver.md` § Mismatch Warning to clarify that case-insensitivity applies to the *warning* check, not to tenant identity.

---

### WR-04: Mismatch-warning context logs attacker-controlled `header_slug` unsanitized

**File:** `src/Resolver/OriginHeaderResolver.php:79-84`

**Issue:** `$headerSlug` is taken directly from `$request->headers->get('X-Tenant-ID')` and emitted as a PSR-3 log context value. PSR-3 implementations vary in their handling of structured context — most pass it through as-is to a JSON encoder (safe) but some line-formatters interpolate context values into the message line. If a malicious client sends:

```
X-Tenant-ID: foo\nlevel=error msg="fake admin event"
```

a line-based log formatter could produce a forged log entry. Same concern applies to `$origin` (line 80), though Symfony's `HeaderBag` strips CR/LF from header values on construction, mitigating this for browser-set headers — but the value still reaches log output unsanitized.

This is a defense-in-depth issue, not a confirmed exploit (Monolog's `JsonFormatter` and `LineFormatter` both escape control characters). Worth a note and a length cap.

**Fix:** Cap the logged header value at a reasonable length and strip control characters before logging:

```php
$safeHeaderSlug = preg_replace('/[\x00-\x1F\x7F]/', '', substr($headerSlug, 0, 128));
$this->logger->warning('Origin/X-Tenant-ID mismatch — Origin wins', [
    'origin' => $origin,
    'origin_slug' => $tenant->getSlug(),
    'header_slug' => $safeHeaderSlug,
    'winner' => 'origin',
]);
```

Update `testMismatchWithXTenantIdLogsWarningAtWarningLevelWithStructuredContext` to assert sanitization.

---

### WR-05: `OriginHeaderResolverConfigPass::normalizeEntry` accepts `userinfo`-shaped errors under wrong message

**File:** `src/DependencyInjection/Compiler/OriginHeaderResolverConfigPass.php:78-83`

**Issue:** The conditional bundles together five distinct failure modes (`path`, `query`, `fragment`, `user`, `pass`) under a single error message:

```
contains a path/query — origin URLs must be bare authorities
```

The unit test `testThrowsOnUserInfoInOrigin` (line 127) asserts this same message for the userinfo case. The message is misleading when the actual problem is `https://user:pass@host` (no path, no query) — an operator reading the error would not know to remove the `user:pass@` segment.

**Fix:** Split the validation into two messages, or generalize the message to "contains path/query/fragment/userinfo":

```php
$disallowed = [];
if (isset($parts['path']) && '' !== $parts['path']) { $disallowed[] = 'path'; }
if (isset($parts['query']) && '' !== $parts['query']) { $disallowed[] = 'query'; }
if (isset($parts['fragment']) && '' !== $parts['fragment']) { $disallowed[] = 'fragment'; }
if (isset($parts['user']) || isset($parts['pass'])) { $disallowed[] = 'userinfo'; }
if ([] !== $disallowed) {
    throw new \InvalidArgumentException(sprintf(
        'tenancy.origin.allow_list entry "%s" contains disallowed components (%s) — origin URLs must be bare authorities (scheme://host[:port])',
        $raw,
        implode(', ', $disallowed),
    ));
}
```

Update the test to assert the new wording.

---

### WR-06: `testReturnsNullWhenOriginHeaderEmpty` likely exercises the `null` branch, not the `''` branch

**File:** `tests/Unit/Resolver/OriginHeaderResolverTest.php:42-48`

**Issue:** The test calls:

```php
$request = Request::create('/', 'GET', [], [], [], ['HTTP_ORIGIN' => '']);
```

Symfony's `HeaderBag` stores the empty string but `$request->headers->get('Origin')` will return `''` only if the bag actually retains the empty value. In several Symfony versions / SAPI shims, empty-string headers passed through `$_SERVER` are filtered. The test asserts the resolver returns `null`, which is true in both branches (`null === $origin` and `'' === $origin`) — so the test passes regardless of which branch fires.

To actually cover the `'' === $origin` branch on line 58 of `OriginHeaderResolver.php`, set the header explicitly on the request:

**Fix:**

```php
public function testReturnsNullWhenOriginHeaderEmpty(): void
{
    $this->provider->expects($this->never())->method('findBySlug');

    $request = Request::create('/', 'GET');
    $request->headers->set('Origin', '');
    self::assertSame('', $request->headers->get('Origin'), 'header bag retains empty string');
    $this->assertNull($this->resolver->resolve($request));
}
```

Otherwise the `'' === $origin` arm of the OR check is genuinely uncovered.

---

## Info

### IN-01: `RecordingLogger` is duplicated across unit and integration test trees

**File:** `tests/Unit/Resolver/Support/RecordingLogger.php`, `tests/Integration/Resolver/Support/RecordingLogger.php`

**Issue:** Both files are functionally identical (same `records` array, same `warnings()` filter, same docblock anchoring to D-11). Only the namespace differs.

**Fix:** Consolidate into a single shared test support class (e.g., `tests/Support/RecordingLogger.php` under namespace `Tenancy\Bundle\Tests\Support`) and import from both unit and integration tests. Less drift risk if the schema of recorded records ever changes.

---

### IN-02: User-guide mentions `nelmio/cors-bundle` without a links/refs section

**File:** `docs/user-guide/origin-header-resolver.md:150`

**Issue:** The CORS preflight section recommends `nelmio/cors-bundle` but doesn't link to it or mention version compatibility. Minor doc polish.

**Fix:** Add an inline link `[nelmio/cors-bundle](https://github.com/nelmio/NelmioCorsBundle)` or a Resources section.

---

### IN-03: `matchOrigin()` silently rejects malformed wildcard suffixes — duplicated validation

**File:** `src/Resolver/OriginHeaderResolver.php:115-122`

**Issue:** Lines 116 (`null === $suffix`) and 120 (`'' === $label || str_contains($label, '.')`) duplicate validation already enforced by `OriginHeaderResolverConfigPass`. This is defense-in-depth and fine, but the resolver silently `continue`s rather than logging/asserting — if a malformed entry ever sneaks past the compile pass (e.g., the parameter is overwritten by another bundle at runtime), the resolver will silently fail to match instead of surfacing the bug. Consider a single `assert()` for development environments:

```php
assert(null !== $suffix, 'wildcard entry without suffix — config pass invariant violated');
```

**Fix:** Optional — add `assert()` calls for invariants guaranteed by the config pass.

---

### IN-04: CHANGELOG entry omits the wildcard slug case

**File:** `CHANGELOG.md:16-17`

**Issue:** The Added bullet says "Supports explicit `{origin, slug}` map entries and wildcard shorthand `'https://*.app.example.com'` (slug = leftmost label)." This is correct, but does not warn about WR-01 (combining the two forms silently discards the explicit slug). Once WR-01 is fixed, no change is needed; if WR-01 is rejected, the CHANGELOG should explicitly call out the precedence rule.

**Fix:** After deciding WR-01, ensure CHANGELOG matches the chosen semantics.

---

### IN-05: Tests use class-name md5 for cache dir but do not clean up on failure

**File:** `tests/Integration/Resolver/OriginHeaderResolverIntegrationTest.php:114-122, 156-164`

**Issue:** `getCacheDir()` and `getLogDir()` use `sys_get_temp_dir().'/tenancy_origin_test_'.md5(static::class)`. The two test kernels generate distinct paths (good for isolation) but no `tearDown`/`tearDownAfterClass` removes the temp dirs. Successive test runs accumulate stale caches in `/tmp`.

**Fix:** Add cleanup in `tearDownAfterClass`:

```php
public static function tearDownAfterClass(): void
{
    self::$kernel->shutdown();
    // Best-effort cleanup; ignore errors so test failures aren't masked.
    @exec('rm -rf ' . escapeshellarg(self::$kernel->getCacheDir() . '/..'));
}
```

Or use Symfony's `Filesystem::remove()`.

---

_Reviewed: 2026-05-15_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
