---
phase: 24-filesystem-bootstrapper
plan: 04
subsystem: filesystem
tags: [filesystem, dsn-parser, flysystem, adapter-factory, logic-exception, credential-redaction]

# Dependency graph
requires:
  - phase: 24-filesystem-bootstrapper/00
    provides: "Stub AdapterDsnParserTest + composer require-dev league/flysystem-bundle + league/flysystem-memory"
  - phase: 24-filesystem-bootstrapper/02
    provides: "UnsupportedAdapterDsnSchemeException class (resolved at runtime via class_exists — graceful degradation when 24-02 has not yet landed at parser-build time)"
provides:
  - "AdapterDsnParser — DSN-string → FilesystemAdapter factory with 3 default schemes (local, memory, s3) + addScheme() extension point"
  - "Credential-redaction discipline at the parser layer — exception messages echo only the scheme name, never the DSN"
  - "Pattern for optional-dep adapter wiring (s3:// guards class_exists on league/flysystem-aws-s3-v3 + aws/aws-sdk-php; degrades to LogicException when absent)"
affects:
  - "24-06 TenantAwareFilesystemDecorator — primary consumer of parse() in per_tenant_adapter mode"
  - "24-09 docs/user-guide/filesystem-bootstrapper.md — documents adapter_dsn shapes + the addScheme() user extension path"

# Tech tracking
tech-stack:
  added: []  # No new composer deps; uses what 24-00 already added.
  patterns:
    - "Closure-registry DSN parser (scheme → \\Closure(string): FilesystemAdapter)"
    - "Graceful optional-dep degradation via class_exists FQCN strings"
    - "Hand-rolled RFC 3986 scheme regex when parse_url returns false for custom schemes"
    - "Credential-redaction-by-construction (exception message inputs are the scheme name, never the DSN)"

key-files:
  created:
    - "src/Filesystem/AdapterDsnParser.php"
  modified:
    - "tests/Unit/Filesystem/AdapterDsnParserTest.php — replaces the 24-00 stub"

key-decisions:
  - "Use a hand-rolled RFC 3986 scheme regex (not parse_url) — parse_url returns false for many <custom-scheme>:// strings whose scheme is not on PHP's built-in allow-list (local:///srv, s3:///bucket)"
  - "addScheme(string, \\Closure) lower-cases the key + last-write-wins on overwrite — matches the 'tag attribute is case-insensitive' convention used by the bundle's other autoconfig surfaces"
  - "s3:// falls back to UnsupportedAdapterDsnSchemeException (LogicException) when AWS SDK absent — same optional-dep discipline as Doctrine in MailerBootstrapper"
  - "Cross-plan dependency on 24-02 is handled via runtime class_exists() — parser degrades to plain \\LogicException with identical message shape; Messenger no-retry contract preserved via shared ancestry"

patterns-established:
  - "Pattern: Closure-keyed registry for optional features (scheme → builder closure). Reused next in 24-06 for per_tenant_adapter routing."
  - "Pattern: Credential-redaction-by-construction. Exception factories take only the SCHEME, never the DSN — eliminates the leak class entirely instead of redacting it after the fact."

requirements-completed: [BOOT-03]  # Partial — 24-04 satisfies the 'AdapterDsnParser parses tenant adapter_dsn' contract; 24-06/24-07/24-08 satisfy the rest of BOOT-03.

# Metrics
duration: 5min
completed: 2026-05-30
---

# Phase 24 Plan 04: AdapterDsnParser Summary

**Pluggable DSN → FilesystemAdapter parser with 3 default schemes (local/memory/s3) + addScheme() extension point + credential-redacted exception messages**

## Performance

- **Duration:** ~5 min
- **Started:** 2026-05-30T19:06:46Z
- **Completed:** 2026-05-30T19:11:59Z
- **Tasks:** 2 (combined into a single feat commit due to pre-commit-hook RED-blocking)
- **Files modified:** 2

## Accomplishments

- Shipped `AdapterDsnParser` with three default schemes pre-registered: `local://` → `LocalFilesystemAdapter`, `memory://` → `InMemoryFilesystemAdapter`, `s3://` → `AwsS3V3Adapter` (optional dep, gracefully degrades to `LogicException` when AWS SDK absent).
- Public `addScheme(string $scheme, \Closure $factory): void` and `supportedSchemes(): list<string>` API lets downstream phases (24-06) and downstream users register additional schemes (Azure, GCS, DigitalOcean Spaces, …) without modifying core.
- 19-test behavioural suite (22 assertions) covers all schemes positive paths, unknown-scheme failure mode, the addScheme() round-trip, lowercase-key normalisation, last-write-wins overwrite, AND four dedicated credential-leak negative assertions (threat T-24-04-01 regression gates).
- PHPStan level 9 clean, php-cs-fixer @Symfony clean, full pre-commit suite green (617 tests, 2203 assertions, 7 incomplete stubs from sibling Wave 1 plans).

## Task Commits

- **Task 1+2 (combined): AdapterDsnParser + AdapterDsnParserTest** — `67abf91` (feat)

The two TDD tasks were committed as a single `feat(24-04)` commit. The project's pre-commit hook runs the full PHPUnit suite — a RED-only commit (failing test in isolation) is rejected by the hook, so the canonical RED → GREEN cycle is collapsed into a single passing commit. The TDD intent (test first, then implementation that makes it pass) was preserved during execution: the test file was written and verified-failing in-process before the parser source was written, then both were committed atomically.

## Files Created/Modified

- `src/Filesystem/AdapterDsnParser.php` — **NEW**. Final class with:
  - 1 public constructor (registers 3 default schemes).
  - 3 public methods: `addScheme()`, `supportedSchemes()`, `parse()`.
  - 1 private exception factory (`unsupportedScheme()`) handling the 24-02 cross-plan transition.
  - 3 private builder closures (local / memory / s3).
  - 2 private static helpers (`splitPathQuery`, `parseQuery`).
- `tests/Unit/Filesystem/AdapterDsnParserTest.php` — **REPLACES** the 24-00 stub with 19 behavioural test methods.

## Decisions Made

1. **Hand-rolled RFC 3986 scheme regex instead of `parse_url()`.** PHP's `parse_url('local:///srv/uploads')` returns `false` because the scheme is not on its built-in allow-list. The regex `/^([a-zA-Z][a-zA-Z0-9+\-.]*):\/\/(.*)$/` reliably extracts the scheme and the remainder for all three default schemes plus user-registered ones.
2. **`addScheme()` lower-cases the key and overwrites on collision.** Matches the case-insensitive tag-attribute convention used by `tenancy.resolver` / `tenancy.bootstrapper` autoconfigure tags. Last-write-wins lets users replace a default scheme (e.g. inject a test double for `s3://`) without first having to look up the registry.
3. **Cross-plan dependency on 24-02 handled via runtime `class_exists()`.** The dedicated `UnsupportedAdapterDsnSchemeException` class is owned by Plan 24-02 (Wave 1, parallel). When 24-02 has not yet landed at parse-time, the parser throws a plain `\LogicException` with an identically shaped message. Both branches share the `\LogicException` ancestry that anchors the Messenger no-retry invariant — the test suite asserts on the ancestry, not the leaf class identity, so both states pass.
4. **Credential-redaction-by-construction.** The `unsupportedScheme()` factory takes the SCHEME NAME as its argument, never the DSN string. This eliminates the credential-leak class entirely instead of relying on regex post-processing (the `DsnSanitizer` approach used by the Mailer bundle, which lives downstream of message construction). T-24-04-01 has FOUR regression gates: `testS3ExceptionMessageDoesNotLeakCredentials` (LEAK_AKIA / LEAK_SECRET markers), `testS3ExceptionMessageDoesNotContainQueryString` (literal `key=` / `secret=` markers), `testUnknownSchemeExceptionDoesNotLeakDsnCredentials` (ftp userinfo `LEAK_PWD_Z`), plus the structural assertion in `testS3ExceptionAncestryIsLogicException` that the scheme name DOES appear (positive: message remains actionable).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] `parse_url()` returns false for custom-scheme DSNs.**
- **Found during:** Initial test run after writing the parser.
- **Issue:** The plan's `<action>` block instructed `parse_url($dsn, PHP_URL_SCHEME)` for scheme extraction. PHP's `parse_url('local:///srv/uploads')` returns `false` because PHP requires non-standard schemes to follow stricter URI rules; this caused all positive-path tests to throw `UnsupportedAdapterDsnSchemeException` instead of returning the expected adapter.
- **Fix:** Switched to a hand-rolled RFC 3986 regex (`/^([a-zA-Z][a-zA-Z0-9+\-.]*):\/\/(.*)$/`) for scheme extraction, plus a manual `?` split for path/query separation. Documented in the class-level docblock §"Why a hand-rolled scheme matcher (not parse_url)".
- **Files modified:** `src/Filesystem/AdapterDsnParser.php`
- **Verification:** All 19 tests pass.
- **Committed in:** `67abf91` (Task 1+2 commit).

**2. [Rule 3 — Blocking] `LocalFilesystemAdapter` eagerly mkdir's its target in the constructor.**
- **Found during:** Initial test run for `testParseLocalReturnsLocalAdapter`.
- **Issue:** The plan's example DSN `local:///srv/uploads` lands the test process inside the macOS `/srv` read-only system directory. `LocalFilesystemAdapter::__construct()` triggers an immediate `mkdir`, which fails with `UnableToCreateDirectory`.
- **Fix:** Switched the test fixtures to use `sys_get_temp_dir().'/tenancy_adapter_dsn_test_'.bin2hex(random_bytes(4))` — a writable, per-test-run temp directory that is `@rmdir`'d on tear-down. Test behaviour unchanged.
- **Files modified:** `tests/Unit/Filesystem/AdapterDsnParserTest.php`
- **Verification:** `vendor/bin/phpunit tests/Unit/Filesystem/AdapterDsnParserTest.php` → 19 passing.
- **Committed in:** `67abf91` (Task 1+2 commit).

**3. [Rule 3 — Blocking] PHPStan: `method_exists($dedicatedClass, 'forScheme')` always evaluates to true after `class_exists()`.**
- **Found during:** PHPStan run after writing the parser.
- **Issue:** Once `class_exists('Tenancy\\Bundle\\Exception\\UnsupportedAdapterDsnSchemeException')` is true, PHPStan narrows the type and reports `function.alreadyNarrowedType` on the subsequent `method_exists()` check.
- **Fix:** Replaced the `method_exists` guard with a typed `callable` cast (`@var callable(string, string): \LogicException $factory`). PHPStan now infers a clean callable contract; the runtime semantics are unchanged.
- **Files modified:** `src/Filesystem/AdapterDsnParser.php`
- **Verification:** `vendor/bin/phpstan analyse --level 9 --no-progress` → OK.
- **Committed in:** `67abf91` (Task 1+2 commit).

**4. [Rule 3 — Blocking] cs-fixer @Symfony `single_line_throw` rule.**
- **Found during:** cs-fixer check.
- **Issue:** Two `throw new \InvalidArgumentException(\n    'message'\n);` blocks violated the @Symfony `single_line_throw` rule.
- **Fix:** Ran `vendor/bin/php-cs-fixer fix --allow-risky=yes src/Filesystem/AdapterDsnParser.php` (auto-fix). One single-line collapse for each throw site.
- **Files modified:** `src/Filesystem/AdapterDsnParser.php`
- **Verification:** `vendor/bin/php-cs-fixer check --diff --allow-risky=yes` → clean.
- **Committed in:** `67abf91` (Task 1+2 commit).

### Plan-Spec Adjustments (NOT auto-fixed deviations — documented for traceability)

**A. Combined RED + GREEN TDD into a single commit.** The plan's `<task>` blocks both carry `tdd="true"`. The project's pre-commit hook runs the full PHPUnit suite — committing a failing test in isolation (the canonical RED step) is rejected by the hook. Tests were written first and verified-failing in-process (Phase: TDD RED) before the parser source was written; both were then committed atomically. TDD intent preserved; commit topology adjusted for the hook reality.

**B. Cross-plan dependency on 24-02 deferred to runtime check.** The plan's `<truths>` mandate throwing `UnsupportedAdapterDsnSchemeException::forScheme(...)` directly. Sibling plan 24-02 (also in Wave 1) owns that class. The execution-context note explicitly forbade creating `src/Exception/UnsupportedAdapterDsnSchemeException.php` from inside 24-04 (atomicity rule). When 24-02 has not yet landed at parse-build time, the parser falls back to throwing a plain `\LogicException` with an identically shaped message. Both classes share the `\LogicException` ancestry, so the Messenger no-retry contract holds across both states; the test suite asserts on the shared ancestry rather than the leaf class identity. Once 24-02 lands, the runtime `class_exists()` check transparently upgrades the parser to throw the dedicated class.

---

**Total deviations:** 4 auto-fixed (all Rule 3 blocking) + 2 plan-spec adjustments documented for traceability.
**Impact on plan:** All auto-fixes were necessary for the verify commands to pass. No scope creep — every line of new source maps back to a behaviour in the plan's `<behavior>` block.

## Issues Encountered

- **Concurrent-agent commit contention (multi-Wave-1 race).** Wave 1 ran four plans (24-01 trait, 24-02 exceptions, 24-03 LRU cache, 24-04 parser) concurrently on the same `master` branch. During Wave 1 execution the working tree saw 5 reflog entries showing reset / re-commit cycles as sibling agents iterated. My initial commit attempt at ~22:08 was absorbed into a sibling agent's reset-and-recommit cycle (commit `9b5faa0` → `d5f7a0a` → `b303dea` cycle on the 24-03 message). After confirming the workspace stabilised on `20406fc` (a clean 24-03 follow-up commit by the sibling agent), I re-staged my two files explicitly via `git add src/Filesystem/AdapterDsnParser.php` + `git add tests/Unit/Filesystem/AdapterDsnParserTest.php` (one file per `git add` call to avoid the directory-add picking up sibling untracked files) and re-committed. The final commit `67abf91 feat(24-04)` landed cleanly; full pre-commit hook (cs-fixer + PHPStan level 9 + full PHPUnit suite) passed in-line.
- **Cross-plan exception dependency.** Resolved via runtime `class_exists()` graceful degradation (see Deviation B above).

## Threat Flags

None — no new security-relevant surface beyond what the plan's `<threat_model>` already enumerates (T-24-04-01 information disclosure: mitigated; T-24-04-02 tampering via malformed DSN: mitigated; T-24-04-03 path-traversal in local://: accepted per RESEARCH.md Pitfall 5).

## Known Stubs

None — `AdapterDsnParser` is feature-complete for the v0.4 demand surface. Sibling test files (`FilesystemPrefixingDecoratorTest`, `TenantAwareFilesystemDecoratorTest`) remain stubbed and are owned by Wave 2 plans (24-05 and 24-06) — out of scope here.

## Next Phase Readiness

- **Plan 24-05 (FilesystemPrefixingDecorator)** can proceed independently — does not depend on `AdapterDsnParser`.
- **Plan 24-06 (TenantAwareFilesystemDecorator)** is the primary downstream consumer of `parse()` — instantiates per-tenant adapters from `getFilesystemConfig()['adapter_dsn']` and caches the results via `LruFilesystemCache` (the 24-03 Wave 1 deliverable).
- **Plan 24-02 (UnsupportedAdapterDsnSchemeException)** landing automatically upgrades the parser's exception class via the runtime `class_exists()` check — no follow-up plan needed to "switch over" to the dedicated class. A small refactor commit in any post-24-02 plan can remove the fallback branch once 24-02 is provably in.

## Self-Check: PASSED

- `src/Filesystem/AdapterDsnParser.php` — FOUND on disk.
- `tests/Unit/Filesystem/AdapterDsnParserTest.php` — FOUND on disk.
- Commit `67abf91` — FOUND in `git log`.
- `vendor/bin/phpunit tests/Unit/Filesystem/AdapterDsnParserTest.php` — 19/19 green, 22 assertions.
- `vendor/bin/phpstan analyse --level 9` — clean.
- `vendor/bin/php-cs-fixer check --diff` — clean.
- Pre-commit hook ran inline on the actual commit and confirmed all three gates: cs-fixer + PHPStan + PHPUnit (617 tests, 2203 assertions) green.

---

*Phase: 24-filesystem-bootstrapper*
*Plan: 04*
*Completed: 2026-05-30*
