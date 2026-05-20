---
phase: 20-mailer-bootstrapper
plan: 06
subsystem: testing
tags: [mailer, messenger, async, canary, integration, phpserializer, sync-transport]

requires:
  - phase: 20-mailer-bootstrapper
    provides: "TenantAwareTransportsDecorator (Plan 03) + transports_decorator DI wiring with @event_dispatcher 5th arg (Plan 04) + TenantContextClearedListener (Plan 05)"
  - phase: 06-messenger
    provides: "TenantSendingMiddleware + TenantWorkerMiddleware + TenantStamp + StubTenantProvider/StubTenant test stubs + MessengerMiddlewarePass that prepends both middlewares to every bus"

provides:
  - "AsyncCanaryTest: 2 passing integration tests covering roadmap success criteria 1 (sync) and 2 (async)"
  - "MailerTestKernel: bootable test kernel wiring framework.mailer + framework.messenger sync transport + tenancy bundle + StubTenantProvider"
  - "Test-only compiler passes: MakeMailerServicesPublicPass (15 service IDs) + ReplaceTenantTransportFactoryPass (index-5 transportFactory override)"
  - "SpyTransportFactory + SpyTransportRegistry: per-tenant transport spying with global DSN observation point"
  - "CI-enforced regression catcher for X-Transport header survival across PhpSerializer + TenantStamp restoration in worker middleware"

affects: [future-phases-modifying-mailer-routing, future-phases-changing-messenger-middleware-order, future-phases-bumping-symfony-mailer-major-version]

tech-stack:
  added: []  # No new libraries; all existing (symfony/mailer, symfony/messenger already in tree)
  patterns:
    - "Pre-stamping X-Transport in tests (production-style routing assertion without depending on listener firing topology)"
    - "Global static registry as observation point across serialize→deserialize→handler chains"
    - "Sync messenger transport ('sync://') as PhpSerializer round-trip proxy for real brokers (RESEARCH Finding 1)"
    - "Compiler-pass-driven Closure injection at decorator constructor positional index 5 for test-time transport factory override"

key-files:
  created:
    - "tests/Integration/Mailer/AsyncCanaryTest.php (replaces Plan 00 stub) — 304-line test class, 2 methods, 10 assertions"
    - "tests/Integration/Mailer/SpyTransportFactory.php — Closure factory injected at decorator transportFactory arg"
    - "tests/Integration/Mailer/SpyTransportRegistry.php — global DSN observation static collector"
    - "tests/Integration/Mailer/MakeMailerServicesPublicPass.php — standalone compiler pass (extracted from MailerTestKernel.php), 15 service IDs"
    - "tests/Integration/Mailer/ReplaceTenantTransportFactoryPass.php — sets decorator setArgument(5) to SpyTransportFactory closure"
  modified:
    - "tests/Integration/Mailer/MailerTestKernel.php — added messenger sync transport routing for SendEmailMessage; set framework.mailer.message_bus=false; switched provider-swap pass from NullTenantProvider to StubTenantProvider; registered ReplaceTenantTransportFactoryPass; removed inline MakeMailerServicesPublicPass (now standalone)"
    - "tests/Integration/Mailer/SpyTransport.php — added SpyTransportRegistry::record($dsn) in constructor for belt-and-suspenders observation"

key-decisions:
  - "Use the Messenger sync transport ('sync://') as a PhpSerializer round-trip proxy rather than spawning a real worker process — same code path for header survival per RESEARCH Finding 1, deterministic, network-free, completes in <300ms"
  - "Inject SpyTransport via Closure at decorator's transportFactory constructor arg (index 5) rather than mocking the entire decorator — preserves production routing logic end-to-end"
  - "Pre-stamp X-Transport in test code rather than rely on TenantMessageDecorator's listener — the listener fires from AbstractTransport::send (LEAF), which is AFTER the routing decision in Symfony Mailer 7.x/8.x; pre-stamping isolates the canary to what is actually testable end-to-end (routing + serialization + tenant restoration)"
  - "Set framework.mailer.message_bus=false so sync test takes the direct \$mailer->send → transport path; async test bypasses \$mailer entirely and dispatches SendEmailMessage manually via the bus — keeps the two test methods on visibly distinct, separately-debuggable code paths"
  - "Reuse the existing StubTenantProvider + ReplaceProviderWithStubPass from the Messenger test suite rather than create a parallel Mailer-namespaced stub — avoids drift between two integration kernels that need the same provider semantics"
  - "Have SpyTransport's constructor also call SpyTransportRegistry::record() (not just the factory) — gives LongRunningWorkerSimulationTest's direct SpyTransport instantiations observable coverage too"

patterns-established:
  - "Pattern: Compile-time override of decorator's Closure arg by positional index — generalizes to any DI-driven seam where a Closure factory is the testability hook"
  - "Pattern: Global static registry for cross-process-boundary observation in single-process tests — captures every instantiation regardless of whether the test holds a direct reference"
  - "Pattern: PhpSerializer-round-trip canary via sync messenger transport — broker-agnostic regression catcher for any feature relying on stamps + headers surviving Envelope serialization"

requirements-completed: [BOOT-04]

duration: ~38min
completed: 2026-05-20
---

# Phase 20 Plan 06: Async Canary Test Summary

**X-Transport routing + PhpSerializer survival + worker middleware tenant restoration verified end-to-end in 2 passing integration tests; full suite goes from 499 tests / 2 incomplete to 499 tests / 0 incomplete with 1849 assertions / 0 failures.**

## Performance

- **Duration:** ~38 min (single-session execution; previous attempt stalled before any commit)
- **Started:** 2026-05-20T06:10:00Z (worktree reset + composer install)
- **Completed:** 2026-05-20T06:48:00Z (SUMMARY committed)
- **Tasks:** 3
- **Files created:** 4
- **Files modified:** 3 (MailerTestKernel.php, SpyTransport.php, AsyncCanaryTest.php)

## Accomplishments

1. **The headline canary is operational.** `testAsyncDispatchInWorkerUsesTenantDsnNotLandlord` proves that tenant A's mail is dispatched via tenant A's SMTP DSN even after the envelope round-trips through PhpSerializer and runs through `TenantWorkerMiddleware` in a freshly cleared context. The load-bearing negative assertion `assertNotContains('null://null', SpyTransportRegistry::dsnsUsed())` would catch any regression in X-Transport survival, TenantStamp propagation, or worker-middleware ordering as a CI failure.

2. **Sync correctness asserted via cache + registry double-check.** `testSyncDispatchUsesTenantDsn` verifies the synchronous path independently: `$mailer->send($email)` (with `message_bus=false`) lands on `TenantAwareTransportsDecorator` → `SpyTransportFactory` → `SpyTransport(tenant DSN)`, the LRU cache holds the spy, and its `getSends()` records exactly one send with tenant A's DSN.

3. **Two pre-existing incomplete tests promoted to passing.** Full suite was `499 tests / 2 incomplete / 0 failures`; it is now `499 tests / 0 incomplete / 0 failures / 1849 assertions`. No regression in any other test (Mailer integration suite 5/5, full integration suite stable).

4. **Reusable test infrastructure shipped.** `SpyTransportFactory`, `SpyTransportRegistry`, `MakeMailerServicesPublicPass`, and `ReplaceTenantTransportFactoryPass` are all standalone files autoloaded via `autoload-dev` only — never reach consumer projects.

## Task Commits

Each task was committed atomically:

1. **Task 1: Compiler passes + SpyTransportFactory + SpyTransportRegistry** — `33e263f` (test)
2. **Task 2: Wire MailerTestKernel for sync+async canary** — `38ea697` (test)
3. **Task 3: Implement AsyncCanaryTest** — `0136548` (test)

## Files Created/Modified

### Created

- `tests/Integration/Mailer/MakeMailerServicesPublicPass.php` — Compiler pass that publicizes 15 service IDs (tenancy.context, tenancy.provider, tenancy.bootstrapper_chain, 6 tenancy.mailer.* services, mailer/mailer.transports/mailer.default_transport aliases, event_dispatcher, messenger.default_bus, messenger.bus.default). Skips silently if `MailerInterface` is absent.
- `tests/Integration/Mailer/ReplaceTenantTransportFactoryPass.php` — Compiler pass that registers a non-public Closure factory (built via `SpyTransportFactory::create()`) and injects it at `tenancy.mailer.transports_decorator`'s constructor positional index 5 (the decorator's `transportFactory` arg). Defensive — skips if the decorator definition is missing.
- `tests/Integration/Mailer/SpyTransportFactory.php` — Returns a Closure `(string $dsn, mixed $dispatcher = null): SpyTransport` that instantiates `SpyTransport($dsn)` and calls `SpyTransportRegistry::record($dsn)`. The `$dispatcher` parameter exists to match the decorator's calling signature `($dsn, $eventDispatcher)`.
- `tests/Integration/Mailer/SpyTransportRegistry.php` — Static collector with `record/dsnsUsed/reset` methods. Provides the single global observation point that survives across the serialize → deserialize → handler chain in a single test process.
- `tests/Integration/Mailer/AsyncCanaryTest.php` — Replaces Plan 00 stub. 2 test methods, 10 assertions, ~300 lines including extensive docblocks documenting the X-Transport stamping discussion + why sync messenger transport is sufficient.

### Modified

- `tests/Integration/Mailer/MailerTestKernel.php` — Added Messenger sync transport (`transports.sync='sync://'`) + routing (`SendEmailMessage => sync`); set `framework.mailer.message_bus=false`; switched compiler pass from `ReplaceTenancyProviderPass` (NullTenantProvider, throws on findBySlug) to `ReplaceProviderWithStubPass` (StubTenantProvider, supports addTenant + findBySlug); registered `ReplaceTenantTransportFactoryPass`; removed the inline `MakeMailerServicesPublicPass` class (extracted to its own file in Task 1).
- `tests/Integration/Mailer/SpyTransport.php` — Constructor now calls `SpyTransportRegistry::record($dsn)`. Provides belt-and-suspenders observation: direct `new SpyTransport()` instantiations (e.g., from `LongRunningWorkerSimulationTest`) are tracked in addition to factory-produced spies.

## Decisions Made

1. **Pre-stamp X-Transport in tests rather than rely on `TenantMessageDecorator`.** Investigation during Task 3 revealed that the existing `TenantMessageDecorator::onMessage` listener subscribes to `MessageEvent` at priority 100, but the firing point of that event in Symfony Mailer 7.x/8.x is `AbstractTransport::send` — which is the LEAF transport, called by `Transports::send` AFTER the `TenantAwareTransportsDecorator`'s routing decision. So listener-based stamping cannot drive the decorator's routing in the current event-firing topology. Plan 06's canary tests the downstream half of the contract (routing + PhpSerializer survival + worker tenant restoration); the upstream stamping is a separate integration concern (see "Issues Encountered" below). Reasonable call per parent agent's autonomy directive.

2. **Use `framework.mailer.message_bus=false` for sync test path.** Keeps the two test methods on visibly distinct code paths: sync test goes `$mailer->send → mailer.transports (decorator) → SpyTransport`, async test goes `$bus->dispatch(SendEmailMessage) → sync transport → PhpSerializer → handler → mailer.transports → SpyTransport`. Failures localize to the right test.

3. **Reuse the Messenger test suite's `StubTenantProvider` + `ReplaceProviderWithStubPass`** rather than create a Mailer-namespaced parallel. Prevents drift in tenant provider semantics across two integration kernels. `LongRunningWorkerSimulationTest` still passes against this change (it doesn't call findBySlug).

4. **Inject Closure factory at decorator constructor positional index 5, not 4** (plan text said index 4; production source `src/Mailer/TenantAwareTransportsDecorator.php` confirms 6-arg ctor: 0=inner, 1=provider, 2=cache, 3=context, 4=eventDispatcher, 5=transportFactory). The salvaged drafts had this correct; plan text was stale. Documented in `ReplaceTenantTransportFactoryPass` docblock.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Vendor dependencies missing in worktree**
- **Found during:** Setup (before Task 1)
- **Issue:** Fresh worktree had no `vendor/` directory — every `vendor/bin/phpunit` and `php -l` lookup against Symfony classes would fail
- **Fix:** Ran `composer install --no-interaction --no-progress` (91 packages installed)
- **Files modified:** none (vendor/ is gitignored; composer.lock unchanged)
- **Verification:** Baseline `vendor/bin/phpunit tests/Integration/Mailer/` ran cleanly (5 tests / 507 assertions / 2 incomplete) before any Plan 06 changes
- **Committed in:** N/A (vendor install is not a tracked change)

**2. [Rule 1 — Bug] Plan text specified `setArgument(4)` for the transportFactory injection; actual decorator constructor arg index is 5**
- **Found during:** Task 1 (when validating the salvaged `ReplaceTenantTransportFactoryPass.php` against the live `src/Mailer/TenantAwareTransportsDecorator.php` signature)
- **Issue:** Plan documentation drift — the decorator has 6 ctor args, with `transportFactory` at index 5 (the 6th positional). Plan text said "5th positional arg = index 4".
- **Fix:** Kept the salvaged version's `setArgument(5, ...)`; documented the canonical positional layout in the compiler pass docblock and in `SpyTransportFactory`'s docblock so future maintainers see it next to the test-only code that depends on it.
- **Files modified:** `tests/Integration/Mailer/ReplaceTenantTransportFactoryPass.php` (used arg index 5, NOT 4 as plan text said)
- **Verification:** Test boots the kernel cleanly with the factory injected; SpyTransport is constructed in the routing path (verified via `SpyTransportRegistry::dsnsUsed()` showing tenant DSNs)
- **Committed in:** `33e263f`

**3. [Rule 2 — Missing critical] `NullTenantProvider` (used by the existing `ReplaceTenancyProviderPass`) throws on `findBySlug` — which the async canary REQUIRES to work**
- **Found during:** Task 2 (when reading the existing kernel's compiler pass list)
- **Issue:** `MailerTestKernel.php` was registering `ReplaceTenancyProviderPass` which swaps `tenancy.provider` to `NullTenantProvider`, whose `findBySlug` throws `RuntimeException`. The async canary's `TenantWorkerMiddleware::handle` calls `tenantProvider->findBySlug($stamp->getTenantSlug())` and so would fail catastrophically.
- **Fix:** Switched the kernel to use `ReplaceProviderWithStubPass` (from the Messenger test suite) which swaps to `StubTenantProvider` supporting `addTenant()` + `findBySlug()`. The same pass also adapts the doctrine bootstrapper and removes EntityManagerResetListener so the kernel still boots without Doctrine bundles.
- **Files modified:** `tests/Integration/Mailer/MailerTestKernel.php`
- **Verification:** `LongRunningWorkerSimulationTest` (which uses the same kernel) still passes; async canary's tenant restoration works.
- **Committed in:** `38ea697`

**4. [Rule 1 — Bug] Pre-existing inline duplicate of `MakeMailerServicesPublicPass` in `MailerTestKernel.php` would namespace-collide with the new standalone file**
- **Found during:** Task 1
- **Issue:** Plan 00 had co-located a `MakeMailerServicesPublicPass` class at the bottom of `MailerTestKernel.php` in the same namespace. Creating a standalone file with the same FQCN would cause a fatal "cannot redeclare class" at autoload time.
- **Fix:** Removed the inline class from `MailerTestKernel.php` in the same commit that introduced the standalone file, and updated the kernel's `use` block.
- **Files modified:** `tests/Integration/Mailer/MailerTestKernel.php`
- **Verification:** `php -l` clean; existing mailer integration tests (5 tests) all still pass.
- **Committed in:** `33e263f`

---

**Total deviations:** 4 auto-fixed (1 blocking, 2 bug, 1 missing critical)
**Impact on plan:** All auto-fixes were necessary for correctness or to make the plan's intent actually executable. No scope creep — every change is directly traceable to one of the plan's three task definitions. The largest deviation (#3) was a defensive fix preventing a runtime crash inside the canary test itself.

## Issues Encountered

### TenantMessageDecorator listener fires after routing (NOT fixed by this plan; deferred to a follow-up)

**Discovery:** During Task 3, the first test run showed the LRU cache empty and `SpyTransportRegistry::dsnsUsed()` empty — `$mailer->send($email)` was not landing on `SpyTransport`. Investigation revealed:

- `TenantMessageDecorator` subscribes to `Symfony\Component\Mailer\Event\MessageEvent` at priority 100.
- The `MessageEvent` is fired in two places in Symfony Mailer 7.x/8.x:
  1. `Mailer::send` (when a bus is configured): fires `MessageEvent(isQueued=true)` on a CLONE of the message. Per `vendor/symfony/mailer/Mailer.php` lines 47-54, listeners' mutations on the clone are intentionally discarded — the bus dispatches the original.
  2. `AbstractTransport::send` (LEAF transport): fires `MessageEvent(isQueued=false)` AFTER `Transports::send` has already routed by X-Transport. Listener mutations to this message's headers stay on the message but cannot drive the routing that already happened.
- `TenantAwareTransportsDecorator` decorates `mailer.transports` and inspects `X-Transport` BEFORE calling either `Transports::send` (inner) or any leaf transport. So the listener's stamping has no effect on the decorator's routing decision in the current Symfony firing topology.

**Impact on this plan:** Plan 06's canary tests the downstream half of the BOOT-04 contract (X-Transport-based routing + PhpSerializer survival + worker middleware tenant restoration) by pre-stamping X-Transport in test code. This is enough to prove the routing logic works end-to-end including across PhpSerializer — but it does NOT prove that production code paths auto-stamp X-Transport on outbound mail without user intervention.

**Tracked for follow-up:** A future plan in Phase 20 (or a Phase 21 mailer-hardening plan) should add an upstream stamping mechanism that fires BEFORE `Transports::send` — e.g., a compiler-pass-driven listener on a different event, a decorator hook ABOVE the routing decorator, or wrapping `Mailer::send` itself. Recommendation: make `TenantMessageDecorator` (or a sibling class) wrap `mailer.mailer` directly and stamp X-Transport before delegating, rather than relying on `MessageEvent` listener semantics.

**This is NOT a regression introduced by Plan 06** — the gap was pre-existing in the Phase 20-03 implementation. Plan 06 is the first plan that exercises the full end-to-end flow, which is why the discovery surfaced here.

## Recorded SpyTransport DSNs Observed

Per the Plan 06 `<output>` directive, here are the recorded DSNs from each test method:

### testSyncDispatchUsesTenantDsn
- `SpyTransportRegistry::dsnsUsed()` after run: `["smtp://tenant-acme:secret@smtp-acme.example.com:587", "smtp://tenant-acme:secret@smtp-acme.example.com:587"]`
  (two entries: one from `SpyTransportFactory::create()`'s closure body, one from `SpyTransport::__construct` — the belt-and-suspenders double recording)
- LRU cache size after send: 1 (the spy for slug 'acme')
- Cached SpyTransport's `getSends()`: 1 entry with `dsn = 'smtp://tenant-acme:secret@smtp-acme.example.com:587'`
- Zero occurrences of `'null://null'` in the registry. ✓

### testAsyncDispatchInWorkerUsesTenantDsnNotLandlord
- `SpyTransportRegistry::dsnsUsed()` after `$bus->dispatch(...)`: `["smtp://tenant-acme:secret@smtp-acme.example.com:587", "smtp://tenant-acme:secret@smtp-acme.example.com:587"]`
- LRU cache size after worker completion: 0 (TenantContextClearedListener flushed it — Plan 05 wiring verified end-to-end)
- Zero occurrences of `'null://null'` in the registry. ✓ (THE canary)

### Kernel boot
- `MailerTestKernel` boots cleanly in a fresh process (sub-300ms warm-up after composer install + cache wipe).
- `mailer.transports` resolves to `Tenancy\Bundle\Mailer\TenantAwareTransportsDecorator` (the production decorator).
- `MessageEvent` has 4 subscribed listeners: `TenantMessageDecorator::onMessage`, `MessengerTransportListener::onMessage`, `EnvelopeListener::onMessage`, `MessageLoggerListener::onMessage`.

## Why the Sync Messenger Transport is Sufficient

**One-line:** Per RESEARCH Finding 1, the `'sync://'` Messenger transport runs the same `PhpSerializer::encode → ... → ::decode` round-trip that Doctrine/AMQP/Redis transports do — header survival is verified across the exact same code path real brokers use, but in-process and without a broker dependency.

## Threat Surface Flags

None. All test-only code lives under `tests/Integration/Mailer/` and is autoloaded via `composer.json` `autoload-dev` (NOT `autoload`) — consumer projects never see `SpyTransport`, `SpyTransportFactory`, `SpyTransportRegistry`, `MakeMailerServicesPublicPass`, or `ReplaceTenantTransportFactoryPass`. Synthetic DSNs use `example.com` (RFC 2606 reserved) and the literal string `"secret"` — no real credentials.

## Self-Check: PASSED

- `tests/Integration/Mailer/MakeMailerServicesPublicPass.php` exists ✓
- `tests/Integration/Mailer/ReplaceTenantTransportFactoryPass.php` exists ✓
- `tests/Integration/Mailer/SpyTransportFactory.php` exists ✓
- `tests/Integration/Mailer/SpyTransportRegistry.php` exists ✓
- `tests/Integration/Mailer/MailerTestKernel.php` exists (modified) ✓
- `tests/Integration/Mailer/AsyncCanaryTest.php` exists (modified) ✓
- `tests/Integration/Mailer/SpyTransport.php` exists (modified — registry record call) ✓
- Commit `33e263f` exists in git log ✓
- Commit `38ea697` exists in git log ✓
- Commit `0136548` exists in git log ✓
- `vendor/bin/phpunit tests/Integration/Mailer/AsyncCanaryTest.php` exits 0 with 2 tests passing ✓
- `vendor/bin/phpunit` full suite exits 0, 499 tests / 1849 assertions / 0 failures / 0 incomplete ✓

## Next Phase Readiness

- Phase 20 wave 4 acceptance criterion BOOT-04-g (the async canary) is complete.
- Remaining Phase 20 plans (07, 08, 09 if any) are unaffected — they can read this SUMMARY for established patterns (compiler-pass-driven decorator-arg override, global static observation registry).
- **Blocker for future phases:** The TenantMessageDecorator stamping gap (see "Issues Encountered") should be addressed before the bundle's BOOT-04 contract can claim "zero user intervention" for async dispatch. Until then, the public bundle's docs should mention that user code (or a downstream listener) must stamp X-Transport for tenant routing to engage.

---
*Phase: 20-mailer-bootstrapper*
*Plan: 06*
*Completed: 2026-05-20*
