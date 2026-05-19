---
phase: 20-mailer-bootstrapper
plan: 00
subsystem: testing
tags: [mailer, testing, scaffolding, phpunit, symfony-mailer]

requires:
  - phase: 06-messenger-integration
    provides: MessengerTestKernel pattern + MakeMessengerServicesPublicPass shape mirrored here
provides:
  - 8 stub unit tests covering all BOOT-04 sub-ids (a, b, c, d/h, e, e-helper, f, i)
  - SpyTransport — TransportInterface impl recording sends + DSN for async canary assertions
  - MailerTestKernel — kernel with framework.mailer (null://null landlord fallback) + Messenger
  - MakeMailerServicesPublicPass — exposes Phase 20 service IDs (tolerant of missing defs)
  - StubTenantMailerExtension trait — opt-in getMailer{Dsn,From,ReplyTo} for existing test stubs
  - AsyncCanaryTest stub — 2 incomplete methods for sync (Plan 04) + async (Plan 06) BOOT-04-g
  - symfony/mailer + symfony/mime added to require-dev (NOT require — D-05 optional-dep pattern)
affects: [20-01, 20-02, 20-03, 20-04, 20-05, 20-06, 20-07, 20-08]

tech-stack:
  added: [symfony/mailer ^7.4||^8.0, symfony/mime ^7.4||^8.0]
  patterns:
    - "Wave-0 stub-test pattern: markTestSkipped on missing-MailerInterface → markTestIncomplete with VALIDATION.md row pointer; PHPUnit reports S/I (never F/E)"
    - "Co-located test-kernel + compiler-pass: MailerTestKernel and MakeMailerServicesPublicPass live in the same file for Wave 0; Wave 1 may extract"
    - "Defensive compiler pass: hasDefinition/hasAlias guard lets the pass register service IDs that don't yet exist in Wave 0 (created in later waves)"

key-files:
  created:
    - tests/Unit/Mailer/MailerBootstrapperTest.php
    - tests/Unit/Mailer/TenantMessageDecoratorTest.php
    - tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php
    - tests/Unit/Mailer/LruTransportCacheTest.php
    - tests/Unit/Mailer/SanitizingMailerDecoratorTest.php
    - tests/Unit/Mailer/TenantMailerConfigTraitTest.php
    - tests/Unit/Mailer/DsnSanitizerTest.php
    - tests/Unit/DependencyInjection/Compiler/MailerTransportContractPassTest.php
    - tests/Integration/Mailer/SpyTransport.php
    - tests/Integration/Mailer/MailerTestKernel.php
    - tests/Integration/Mailer/AsyncCanaryTest.php
    - tests/Integration/Support/StubTenantMailerExtension.php
    - tests/Unit/Mailer/.gitkeep
    - tests/Integration/Mailer/.gitkeep
  modified:
    - composer.json

key-decisions:
  - "Phase 20-00: symfony/mailer + symfony/mime in require-dev only (NEVER require) — preserves the optional-dep pattern documented in D-05 / CLAUDE.md"
  - "Phase 20-00: every stub guards on interface_exists(MailerInterface::class) FIRST, then markTestIncomplete — so users without symfony/mailer get clean S (skipped) reports instead of I (incomplete)"
  - "Phase 20-00: MakeMailerServicesPublicPass co-located in MailerTestKernel.php for Wave 0 — eliminates an extra Support/ file when the pass has no other consumers yet"
  - "Phase 20-00: AsyncCanaryTest stub uses class-level static ?MailerTestKernel \$kernel marked @phpstan-ignore — actual kernel boot deferred to Plan 04 (sync) / Plan 06 (async)"

patterns-established:
  - "Stub-test shape: declare(strict_types=1); namespace Tenancy\\Bundle\\Tests\\Unit\\Mailer; final class XTest extends TestCase { public function testStubReservedForWave1Implementation(): void { markTestSkipped|markTestIncomplete } }"
  - "VALIDATION.md row pointer in markTestIncomplete message — keeps the implementation pointer findable from PHPUnit output without re-opening the plan"
  - "Per-tenant transport test pattern: SpyTransport(string \$dsn) records send() calls with the constructor DSN, enabling the canary to assert WHICH DSN the worker actually used"

requirements-completed: [BOOT-04]

duration: ~22min
completed: 2026-05-19
---

# Phase 20 Plan 00: Mailer Test Scaffolding Summary

**12 stub PHPUnit files + composer.json edit unblock every Wave 1+ task in Phase 20 — `vendor/bin/phpunit tests/Unit/Mailer/ tests/Integration/Mailer/` reports 9 incomplete, zero failures, zero errors.**

## Performance

- **Duration:** ~22 min
- **Started:** 2026-05-19T21:20:34Z
- **Completed:** 2026-05-19T21:42:45Z
- **Tasks:** 3
- **Files created:** 14 (8 unit-test stubs + 4 integration files + 2 .gitkeep)
- **Files modified:** 1 (composer.json)

## Accomplishments

- 8 stub unit-test classes — one per BOOT-04 sub-id from 20-VALIDATION.md — each pointing future implementers back at the validation row that will turn it green
- `SpyTransport`: 45-line TransportInterface implementation that records every `send()` call paired with the constructor DSN — the load-bearing primitive for the async canary
- `MailerTestKernel` + `MakeMailerServicesPublicPass`: kernel-level scaffolding for Phase 20 integration tests; FrameworkBundle wired with `mailer.dsn: null://null` landlord fallback + Messenger default bus; compiler pass safely exposes future Phase-20 service IDs without erroring on missing definitions
- `StubTenantMailerExtension` trait: drop-in mailer accessors so existing Phase 6 `StubTenant` and other test tenants can opt into the Phase 20 contract without breakage
- `AsyncCanaryTest`: 2 stub methods marking the sync (Plan 04) and async (Plan 06) BOOT-04-g paths as incomplete with explicit plan-pointer messages
- `symfony/mailer ^7.4||^8.0` and `symfony/mime ^7.4||^8.0` added to `require-dev` and to `suggest` — installable transitively in CI without forcing the optional dependency on bundle consumers

## Task Commits

Each task was committed atomically:

1. **Task 1: Add symfony/mailer to require-dev and create test directories** — `19205bd` (chore)
2. **Task 2: Write all 8 stub unit test classes** — `b5cfb7e` (test)
3. **Task 3: Create MailerTestKernel, SpyTransport, StubTenantMailerExtension, AsyncCanaryTest stub** — `22e97df` (test)

## Files Created/Modified

### Created
- `tests/Unit/Mailer/MailerBootstrapperTest.php` — stub for BOOT-04-a (MailerBootstrapper implements TenantBootstrapperInterface)
- `tests/Unit/Mailer/TenantMessageDecoratorTest.php` — stub for BOOT-04-b (X-Transport header on MessageEvent)
- `tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php` — stub for BOOT-04-c (tenant_<slug> routing)
- `tests/Unit/Mailer/LruTransportCacheTest.php` — stub for BOOT-04-d/h (LRU eviction + clear-on-event)
- `tests/Unit/Mailer/SanitizingMailerDecoratorTest.php` — stub for BOOT-04-e (DSN redaction on TransportException)
- `tests/Unit/Mailer/TenantMailerConfigTraitTest.php` — stub for BOOT-04-i (trait default impls)
- `tests/Unit/Mailer/DsnSanitizerTest.php` — stub for BOOT-04-e-helper (standalone redact() helper)
- `tests/Unit/DependencyInjection/Compiler/MailerTransportContractPassTest.php` — stub for BOOT-04-f (compile-time strategy / async-without-x_transport guard)
- `tests/Integration/Mailer/SpyTransport.php` — TransportInterface impl recording sends + DSN
- `tests/Integration/Mailer/MailerTestKernel.php` — Mailer+Messenger test kernel + MakeMailerServicesPublicPass
- `tests/Integration/Mailer/AsyncCanaryTest.php` — sync + async canary stubs for Plan 04 / Plan 06
- `tests/Integration/Support/StubTenantMailerExtension.php` — trait with getMailer{Dsn,From,ReplyTo} setters/getters
- `tests/Unit/Mailer/.gitkeep`, `tests/Integration/Mailer/.gitkeep` — directory presence in git

### Modified
- `composer.json` — added `symfony/mailer` and `symfony/mime` (^7.4||^8.0) to `require-dev`; added `symfony/mailer` to `suggest`

## Decisions Made

- **Optional-dep enforcement:** kept `symfony/mailer` in `require-dev`, not `require` — D-05 (and the CLAUDE.md "optional Doctrine" pattern) treats Mailer as an opt-in integration; promoting to `require` would force the dependency on shared-db-only consumers
- **Stub guard order:** `markTestSkipped` (missing MailerInterface) → `markTestIncomplete` — gives users without symfony/mailer a clean S in PHPUnit output, while users with it installed see the I incomplete that motivates Wave 1+ implementation
- **Co-located compiler pass:** `MakeMailerServicesPublicPass` lives inside `MailerTestKernel.php` for Wave 0 since no other test currently consumes it; extracting to `tests/Integration/Mailer/Support/` is reserved for when a second consumer appears
- **Defensive service-ID list:** the compiler pass references Phase 20 service IDs that don't yet exist (`tenancy.mailer.bootstrapper` etc.) — the `hasDefinition`/`hasAlias` guard makes the pass idempotent and safe to land before Wave 1

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Worktree lacked `composer.lock` / `vendor/`**
- **Found during:** Task 1 (composer validate after edit)
- **Issue:** Worktrees only share git-tracked files; `composer.lock` is gitignored, so the worktree had no installed dependencies — PHPUnit could not run.
- **Fix:** Copied `composer.lock` from the main checkout (`cp ../../../composer.lock ./`) then `composer update symfony/mailer symfony/mime` to install the new + previously-locked deps into this worktree's `vendor/`. Both files remain gitignored.
- **Files modified:** none committed (composer.lock + vendor/ stay gitignored)
- **Verification:** `composer show symfony/mailer` resolves; `vendor/bin/phpunit` exits 0
- **Committed in:** n/a — workspace setup, not committed

**2. [Rule 2 - Missing Critical] PHPStan-level-9 noise on new files**
- **Found during:** Task 3 final verification
- **Issue:** PHPStan flagged `AsyncCanaryTest::$kernel` (never assigned non-null in Wave 0) and `SpyTransport::send()` (`?SentMessage` return where null is permitted by the TransportInterface contract but never produced by the spy). Both are technically accurate but unactionable until Wave 1.
- **Fix:** Added single-line `@phpstan-ignore-next-line` annotations with rationale on each occurrence — keeps PHPStan green on Phase 20 files without weakening level-9 enforcement elsewhere.
- **Files modified:** `tests/Integration/Mailer/AsyncCanaryTest.php`, `tests/Integration/Mailer/SpyTransport.php`
- **Verification:** `vendor/bin/phpstan analyse tests/Unit/Mailer tests/Integration/Mailer --level=9` → "No errors"
- **Committed in:** `22e97df` (Task 3 commit)

**3. [Out of scope] Pre-existing PHPStan warnings in `TestProduct.php` + `TestTenantProduct.php`**
- **Found during:** Task 3 (running phpstan on `tests/Integration/Support/`)
- **Issue:** Two `property.unusedType` warnings on `id` properties (int|null never assigned int) — predate Plan 20-00 (last touched in `f62a0fc` initial release).
- **Disposition:** Out of scope per executor scope-boundary rules. Logged to `.planning/phases/20-mailer-bootstrapper/deferred-items.md` for a future cleanup pass.

---

**Total deviations:** 2 auto-fixed (1 blocking — composer setup, 1 missing-critical — phpstan annotations) + 1 out-of-scope (deferred)
**Impact on plan:** Plan executed exactly as written; deviations were workspace/static-analysis hygiene only, not behavioral changes. No scope creep.

## Issues Encountered

- **Shared TMPDIR cross-pollution:** During the full-suite verification (`vendor/bin/phpunit`) some cached integration containers under `$TMPDIR/tenancy_*` referenced the main repo's path, causing "Cannot redeclare class Tenancy\\Bundle\\Entity\\Tenant". Resolved by `rm -rf "$TMPDIR"tenancy_*` before each full-suite run — this is a known worktree limitation (test kernels write outside the worktree), not caused by Plan 20-00. After clearing, the full 429-test suite passes with 10 incomplete (9 new Mailer stubs + 1 pre-existing) and zero failures/errors.

## Threat Surface Audit

`<threat_model>` covered T-20-W0-01 (require-dev placement) and T-20-W0-02 (SpyTransport DSN storage). Both mitigations confirmed:
- composer.json grep shows `symfony/mailer` only inside the `require-dev` and `suggest` blocks (never `require`)
- `SpyTransport` carries an explicit security note in its docblock instructing callers to use synthetic DSNs only

No new threat surface introduced beyond what `<threat_model>` enumerated.

## Validation Compliance

- ✅ All 9 Wave 1+ target files exist and report as PHPUnit S/I (skipped/incomplete) — Nyquist Rule unblocked for Wave 1
- ✅ `vendor/bin/phpunit tests/Unit/Mailer/ tests/Integration/Mailer/` → 9 incomplete, 0 failures, 0 errors
- ✅ Full suite `vendor/bin/phpunit` → 429 tests, 10 incomplete, 0 failures, 0 errors
- ✅ `composer validate --strict --no-check-publish` → exit 0
- ✅ `vendor/bin/phpstan analyse tests/Unit/Mailer tests/Integration/Mailer --level=9` → No errors

## Next Phase Readiness

Wave 1 plans (20-01 through 20-08) can now use any of the 9 target test files in their `<automated>` verification commands without violating the Nyquist Rule. Specifically:
- 20-01 (TenantInterface mailer methods) → `TenantMailerConfigTraitTest`, can reuse `StubTenantMailerExtension`
- 20-02 (MailerBootstrapper + chain registration) → `MailerBootstrapperTest`
- 20-03 (MessageEvent listener) → `TenantMessageDecoratorTest`
- 20-04 (Transport-mux decorator + LRU cache) → `TenantAwareTransportsDecoratorTest`, `LruTransportCacheTest`, `AsyncCanaryTest::testSyncDispatchUsesTenantDsn`
- 20-05 (DSN sanitization + profiler) → `SanitizingMailerDecoratorTest`, `DsnSanitizerTest`
- 20-06 (Async canary + Messenger interop) → `AsyncCanaryTest::testAsyncDispatchInWorkerUsesTenantDsnNotLandlord` (uses `SpyTransport` + `MailerTestKernel`)
- 20-07 (Compile-time guard) → `MailerTransportContractPassTest`
- 20-08 (Docs / UPGRADE.md) → no automated test target — covered by manual checks

No blockers for Wave 1.

## Self-Check: PASSED

Verified all 14 files exist on disk and all 3 task commits are present in git log.

---
*Phase: 20-mailer-bootstrapper*
*Completed: 2026-05-19*
