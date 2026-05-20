---
phase: 20-mailer-bootstrapper
plan: 09
subsystem: mailer
tags: [mailer, x-transport, stamping, decorator, gap-closure, boot-04, wr-08]

# Dependency graph
requires:
  - phase: 20-mailer-bootstrapper
    provides: TenantAwareTransportsDecorator, TenantMessageDecorator, SanitizingMailerDecorator, LruTransportCache, SpyTransport test infrastructure
provides:
  - TenantMailerDecorator (upstream MailerInterface decorator stamping X-Transport / From / Reply-To from TenantContext)
  - Explicit decoration_priority chain on the `mailer` service (Sanitizing=0 OUTERMOST, Tenant=10 INNER)
  - WR-08 fix — TenantAwareTransportsDecorator no longer mutates the caller's Email by removing X-Transport
  - 7 unit tests + 3 integration tests proving upstream stamping path is operational with zero user boilerplate
  - Regression test proving same-Email re-send still routes to the tenant transport
affects: [20-mailer-bootstrapper VERIFICATION (closes Gap #1), v0.3 adoption surface, future mailer iterations]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Stacked Symfony DI decorators with explicit priority for ordering — INNER (10) stamps then OUTERMOST (0) sanitizes exceptions"
    - "Defense-in-depth dual-stamper: upstream MailerInterface decorator covers MailerInterface::send; downstream MessageEvent listener covers code paths that hit a Transport directly"
    - "Decorator never mutates caller's Message — leave routing metadata (X-Transport) intact so re-send works"

key-files:
  created:
    - src/Mailer/TenantMailerDecorator.php
    - tests/Unit/Mailer/TenantMailerDecoratorTest.php
  modified:
    - src/Mailer/TenantAwareTransportsDecorator.php
    - config/services.php
    - tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php
    - tests/Integration/Mailer/AsyncCanaryTest.php

key-decisions:
  - "TenantMailerDecorator decorates MailerInterface (the `mailer` service) at decoration_priority 10 — runs BEFORE Symfony's Mailer::send routes the message. This is the canonical position per BOOT-04 acceptance bullet (b)"
  - "SanitizingMailerDecorator stays at decoration_priority 0 (OUTERMOST) so its catch boundary wraps any exception thrown by the tenant stamper or inner mailer"
  - "TenantMessageDecorator (the MessageEvent listener) remains wired as defense-in-depth — covers code paths that bypass MailerInterface::send and call a Transport directly. Class docblock explicitly warns future maintainers not to remove it"
  - "TenantAwareTransportsDecorator no longer removes X-Transport from the caller's message (WR-08 fix). Tenant transports are leaf transports that do not re-route on the header — leaving it intact prevents cross-tenant misroute on Email re-send"
  - "Idempotency contract on both stampers: user-supplied X-Transport / From / Reply-To headers are NEVER overwritten"

patterns-established:
  - "Decoration priority discipline: when multiple decorators wrap the same service, set priorities explicitly even when the default would suffice — makes ordering reviewable in services.php without checking DI semantics"
  - "Renaming a test method when its assertion semantic flips (testStripsX -> testPreservesX) instead of leaving the now-misleading old name in place"

requirements-completed: [BOOT-04]

# Metrics
duration: 9min
completed: 2026-05-20
---

# Phase 20 Plan 09: Upstream X-Transport stamping decorator Summary

**TenantMailerDecorator decorates `mailer` at decoration_priority 10, stamping X-Transport / From / Reply-To from TenantContext BEFORE Symfony's Mailer::send routes — closes the BOOT-04 routing gap and removes the WR-08 header-mutation footgun in TenantAwareTransportsDecorator.**

## Performance

- **Duration:** 9 min
- **Started:** 2026-05-20T09:57:07Z
- **Completed:** 2026-05-20T10:06Z
- **Tasks:** 3
- **Files modified:** 6 (2 created, 4 modified)

## Accomplishments

- **Closes Gap #1 from 20-VERIFICATION.md** — `$mailer->send($email)` in tenant A's HTTP context now dispatches via tenant A's SMTP DSN with zero user boilerplate. The MessageEvent listener firing-point limitation is no longer a routing blocker; it is repositioned as defense-in-depth.
- **Closes WR-08 from 20-REVIEW.md** — `TenantAwareTransportsDecorator::send` no longer removes `X-Transport` from the caller's Email. Re-sending the same Email instance routes correctly on every send.
- **Explicit decoration priority chain** on the `mailer` service: SanitizingMailerDecorator (priority 0, OUTERMOST) wraps TenantMailerDecorator (priority 10, INNER) wraps the inner Mailer. Documented in `config/services.php` with the runtime chain comment.
- **10 new test methods** total (7 unit + 3 integration) prove the upstream stamping path is operational and the WR-08 regression is closed.

## Task Commits

Each task was committed atomically (`--no-verify`, per parallel-worktree protocol):

1. **Task 1: Create TenantMailerDecorator + delete X-Transport mutation in TenantAwareTransportsDecorator** — `6b1b10c` (feat)
2. **Task 2: Wire TenantMailerDecorator in config/services.php with correct decoration priority** — `35017b2` (feat)
3. **Task 3: Unit + integration tests proving the upstream stamping path** — `3568a19` (test)

## Files Created/Modified

- `src/Mailer/TenantMailerDecorator.php` — **created**. Final class implementing `MailerInterface`. `send()` calls `stamp()` then delegates to inner. `stamp()` reads `TenantContext::getTenant()`, returns early if no tenant or no mailerDsn, then adds `X-Transport: tenant_<slug>` (if absent) on any `Message`, plus `From` / `Reply-To` (if absent and tenant provides them) on `Email` subclasses. Pure `RawMessage` passes through untouched.
- `src/Mailer/TenantAwareTransportsDecorator.php` — **modified**. Deleted the line `$message->getHeaders()->remove('X-Transport')` (WR-08 fix). Class docblock updated with `@see TenantMailerDecorator` paragraph noting that X-Transport stamping is now upstream. Routing logic, cross-tenant guard, and buildAndCache untouched.
- `config/services.php` — **modified**. Added `tenancy.mailer.tenant_decorator` registration (`->decorate('mailer', null, 10)`) with `TenantContext` injected. Made `tenancy.mailer.sanitizing_decorator`'s priority explicit (`->decorate('mailer', null, 0)`). Added inline comment with the full runtime chain.
- `tests/Unit/Mailer/TenantMailerDecoratorTest.php` — **created**. 7 pure-unit tests: X-Transport stamping, From/Reply-To stamping, no-stamp when no tenant, no-stamp when no DSN, idempotency vs user-supplied X-Transport, RawMessage pass-through, inner-exception propagation.
- `tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php` — **modified**. Flipped three assertions that the X-Transport header is stripped after routing (lines 151, 182, 227 in the pre-plan file) to assert the WR-08 invariant — the header is PRESERVED post-routing. Renamed `testStripsXTransportHeaderAfterRouting` to `testPreservesXTransportHeaderAfterRouting`.
- `tests/Integration/Mailer/AsyncCanaryTest.php` — **modified**. Appended 3 integration tests: `testSyncDispatchUsesTenantDsnWithoutPreStamping`, `testAsyncDispatchWithoutPreStampingSurvivesMessengerRoundTrip`, `testReSendingPreservesXTransportRouting`.

## Decoration Priority Chain (as configured)

| Position | Service ID | Priority | Role |
|----------|------------|----------|------|
| OUTERMOST | `tenancy.mailer.sanitizing_decorator` | 0 | Catches `TransportException` / `ExceptionInterface`, redacts DSN credentials via `DsnSanitizer` |
| INNER | `tenancy.mailer.tenant_decorator` (Plan 20-09) | 10 | Stamps `X-Transport: tenant_<slug>` + `From` + `Reply-To` from active `TenantContext` |
| ORIGINAL | `Symfony\Component\Mailer\Mailer` | — | Routes to `mailer.transports` (decorated by `TenantAwareTransportsDecorator`) → `SmtpTransport` |

Runtime call order: user code → SanitizingMailerDecorator → TenantMailerDecorator → Mailer → mailer.transports (TenantAwareTransportsDecorator) → SMTP / Messenger.

## Test Count Delta

- **Unit (Mailer):** +7 (`TenantMailerDecoratorTest`); unit-suite total before/after: 402 / 409 (all passing).
- **Integration (Mailer):** +3 in `AsyncCanaryTest` (existing 2 preserved); Mailer-integration suite total before/after: 2 / 5 (all passing).
- **Plan-modified files combined run:** 24 tests, 67 assertions, all green (`vendor/bin/phpunit tests/Unit/Mailer/TenantMailerDecoratorTest.php tests/Integration/Mailer/AsyncCanaryTest.php tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php`).
- **Full unit suite:** 409/409 passing.
- **PHPStan level 9 on src:** No errors (`vendor/bin/phpstan analyse --memory-limit=512M`).

## Decisions Made

- **Rename `testStripsXTransportHeaderAfterRouting` to `testPreservesXTransportHeaderAfterRouting`** — when the assertion semantic flips entirely (from "strip" to "preserve"), the method name must follow. Leaving the old name would mislead future readers.
- **Flip the observer's `assertFalse($observer->sawHeaderAtSendTime)` to `assertTrue` as well** — the plan's grep listed lines 151/182/227 as the assertions to flip, but the same test also asserts what the inner transport saw at send time. Under the WR-08 fix the inner transport DOES see the header (it is no longer stripped before delegation), so that assertion must flip too. Documented inline.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Flipped the observer assertion in the renamed `testPreservesXTransportHeaderAfterRouting`**
- **Found during:** Task 1 (the assertion-flip step)
- **Issue:** The plan only listed three assertions to flip (lines 151, 182, 227 in the pre-plan file). The test at line 185 also has `assertFalse($observer->sawHeaderAtSendTime, ...)` which encodes the OLD invariant ("inner transport must receive the message AFTER X-Transport is stripped"). Under the WR-08 fix the inner transport DOES see the header at send time; the old assertion would have failed.
- **Fix:** Flipped that assertion to `assertTrue` with an updated message referencing WR-08 (Plan 20-09). Renamed the test method to `testPreservesXTransportHeaderAfterRouting` to reflect the new semantic.
- **Files modified:** `tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php`
- **Verification:** `vendor/bin/phpunit tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php` — 12 tests passing.
- **Committed in:** `6b1b10c` (Task 1 commit)

---

**Total deviations:** 1 auto-fixed (1 bug-fix in test assertion).
**Impact on plan:** Necessary for correctness — the unchanged assertion would have caused a test failure after the WR-08 source change. No scope creep; same file already in plan's `files_modified` list.

## Issues Encountered

- **Worktree-environment quirks (pre-existing, out of scope):** Running the full integration suite from the worktree surfaces two pre-existing problems unrelated to this plan: (a) `TenantInitCommandIntegrationTest::testInitCommandReceivesProjectDir` asserts `kernel.project_dir` matches the worktree path but the symfony cache resolves it to the parent repo path; (b) a `Cannot redeclare class TestProduct` fatal occurs later in the run when Doctrine entity discovery somehow picks up both the worktree and the parent repo paths. These are tooling artifacts of running tests inside a worktree with a shared parent vendor. Plan-modified test files (TenantMailerDecoratorTest, TenantAwareTransportsDecoratorTest, AsyncCanaryTest) and the broader Mailer suite all pass cleanly when invoked directly — the orchestrator's post-merge CI in the main repo path will not hit these worktree-specific artifacts.

## Threat Surface

No new attack surface introduced. The plan's threat register (T-20-09-01 through T-20-09-05) is fully mitigated:

| Threat | Mitigation status |
|--------|-------------------|
| T-20-09-01 (routing without stamping) | Mitigated — `testSyncDispatchUsesTenantDsnWithoutPreStamping` proves stamping engages without user code |
| T-20-09-02 (re-send misroute) | Mitigated — `testReSendingPreservesXTransportRouting` proves header survives |
| T-20-09-03 (overwrite user X-Transport) | Mitigated — `testDoesNotOverwriteUserSuppliedXTransport` |
| T-20-09-04 (sanitizer ordering) | Mitigated — explicit priorities in services.php; existing `SanitizingMailerDecoratorTest` still green |
| T-20-09-05 (lose defense-in-depth) | Accepted/retained — `tenancy.mailer.message_decorator` registration unchanged; class docblock warns future maintainers |

## User Setup Required

None — bundle-internal change. Existing users of `$mailer->send()` benefit automatically once they upgrade.

## Self-Check: PASSED

- `src/Mailer/TenantMailerDecorator.php` — FOUND
- `tests/Unit/Mailer/TenantMailerDecoratorTest.php` — FOUND
- Commit `6b1b10c` (Task 1) — FOUND
- Commit `35017b2` (Task 2) — FOUND
- Commit `3568a19` (Task 3) — FOUND
- `grep -c "remove..X-Transport" src/Mailer/TenantAwareTransportsDecorator.php` returns 0 — VERIFIED
- `grep -c "tenancy.mailer.tenant_decorator" config/services.php` returns 1 — VERIFIED
- PHPUnit suite green on all plan-modified files (24 tests, 67 assertions) — VERIFIED

## Next Phase Readiness

BOOT-04 acceptance criteria are now operationally true. The mailer-bootstrapper phase gap-closure wave (plans 20-09, 20-10, 20-11) can be considered architecturally complete once 20-10 and 20-11 land in parallel. Future iterations (e.g., DSN encryption at rest, per-tenant template overrides) build on top of the stamping pipeline established here.

---
*Phase: 20-mailer-bootstrapper*
*Plan: 09*
*Completed: 2026-05-20*
