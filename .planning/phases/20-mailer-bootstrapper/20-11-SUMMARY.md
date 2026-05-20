---
phase: 20-mailer-bootstrapper
plan: 11
subsystem: mailer
tags: [mailer, security, routing, slug-validation, gap-closure, BL-02, defense-in-depth]

# Dependency graph
requires:
  - phase: 20-mailer-bootstrapper
    provides: "TenantAwareTransportsDecorator::send routing pipeline + WR-08 X-Transport non-mutation fix (Plan 20-09)"
provides:
  - "Empty-slug guard in TenantAwareTransportsDecorator::send — rejects X-Transport 'tenant_' (literal, no slug) with \\RuntimeException BEFORE any provider call"
  - "Character-set guard — rejects slugs outside /^[a-z0-9_-]+$/ (whitespace, unicode, path traversal, uppercase) BEFORE provider round-trip"
  - "Defense-in-depth coverage for the no-active-tenant path the existing T-20-03-02 cross-tenant guard misses (worker-pre-restoration, sync-context misuse)"
  - "Five new unit tests pinning the behavior, including a sanity test proving valid-slug routing is unaffected"
affects: [mailer, security, provider-implementations, worker]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Guard-before-call: input validation positioned BEFORE provider invocation so user-supplied TenantProviderInterface implementations can never receive malformed input"

key-files:
  created: []
  modified:
    - src/Mailer/TenantAwareTransportsDecorator.php
    - tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php

key-decisions:
  - "Em-dash (U+2014) used in new exception messages to match existing exception text in the same method (line 88 already used em-dash)"
  - "Character-set regex `[a-z0-9_-]+` matches the bundle's slug convention; chosen over a looser `[A-Za-z0-9_-]+` to reject uppercase (which would create a separate cache entry from the canonical lower-case form)"
  - "Both guards placed BEFORE the cross-tenant guard (not after) so the no-active-tenant path is covered — covering the unguarded worker-pre-restoration / sync-context-misuse vectors"
  - "Sanity test (testValidSlugStillRoutes) uses an anon-class invocation-counting spy instead of PlainSpyTransport because the latter does not expose a getSends() accessor (deviation from PLAN guidance — see Deviations below)"

patterns-established:
  - "Bundle-level input validation for hostile-input vectors at trust boundaries: the bundle validates the slug shape before any provider semantics are exercised, making provider implementations a defense-in-depth layer rather than the only layer"

requirements-completed: [BOOT-04]

# Metrics
duration: ~12min
completed: 2026-05-20
---

# Phase 20 Plan 11: Empty-slug + character-set guards (closes BL-02) Summary

**TenantAwareTransportsDecorator::send now rejects empty-slug and invalid-character X-Transport headers with explicit \\RuntimeException throws BEFORE the cross-tenant guard — covering the no-active-tenant hostile-input vector identified in REVIEW BL-02 / VERIFICATION gap #3**

## Performance

- **Duration:** ~12 minutes
- **Started:** 2026-05-20T10:06:00Z (approx — worktree base reset)
- **Completed:** 2026-05-20T10:18:30Z
- **Tasks:** 2 (both auto-execute, no checkpoints)
- **Files modified:** 2

## Accomplishments

- Empty-slug guard: `TenantAwareTransportsDecorator::send` now throws `\RuntimeException('tenancy: refusing to route mail — X-Transport "tenant_" has an empty slug.')` when `$headerValue === 'tenant_'` (literal, no slug). `findBySlug('')` is unreachable.
- Character-set guard: `TenantAwareTransportsDecorator::send` now throws `\RuntimeException('tenancy: refusing to route mail — X-Transport "tenant_<slug>" has an invalid slug (must match [a-z0-9_-]+).')` when `$slug` contains any character outside `[a-z0-9_-]+`. Catches: whitespace, dots, slashes, uppercase, unicode, path-traversal sequences.
- Both guards positioned **before** the existing T-20-03-02 cross-tenant guard, closing the no-active-tenant coverage gap (worker-pre-restoration, sync-context misuse) that VERIFICATION flagged.
- Five new unit tests pin the behavior end-to-end, including a sanity test (`testValidSlugStillRoutes`) proving valid-slug routing is unaffected.
- Full PHPUnit suite: **540 → 545 tests, 1993 → 2011 assertions, all green.** PHPStan level 9 clean.

## Task Commits

Each task was committed atomically (using `--no-verify` per orchestrator instruction — the wave hook will run once after orchestrator merges):

1. **Task 1: Add empty-slug + character-set guards in TenantAwareTransportsDecorator::send** — `839c4e9` (feat)
2. **Task 2: Add empty-slug + character-set guard test cases to TenantAwareTransportsDecoratorTest** — `e6a2fd4` (test)

## Files Created/Modified

- `src/Mailer/TenantAwareTransportsDecorator.php` — Inserted two guard blocks (24 lines total) between the `$slug = substr(...)` extraction (line 79) and the existing cross-tenant guard (line 91). Both guards reference `Plan 20-11 / REVIEW BL-02` in their leading comment for traceability.
- `tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php` — Appended 5 test methods after `testFactoryReceivesInjectedEventDispatcher` and before the class closing brace. No existing assertions touched (Plan 20-09's WR-08 flips remain intact).

## Exception Messages (verbatim — test assertions pin these)

- Empty-slug exception: `tenancy: refusing to route mail — X-Transport "tenant_" has an empty slug.`
- Invalid-slug exception: `tenancy: refusing to route mail — X-Transport "tenant_<actual-slug>" has an invalid slug (must match [a-z0-9_-]+).`

Both messages use the em-dash character `—` (U+2014) for consistency with the existing line-88 cross-tenant exception in the same method.

## New Test Cases

1. `testRefusesEmptySlugXTransportHeader` — X-Transport `tenant_` (literal) → `\RuntimeException` matches `/has an empty slug/`, `findBySlug` never called, no active tenant on context (exercises the unguarded path the cross-tenant guard misses).
2. `testRefusesInvalidSlugCharacters` — X-Transport `tenant_../etc/passwd` → matches `/has an invalid slug/`, `findBySlug` never called.
3. `testRefusesSlugWithWhitespace` — X-Transport `tenant_ acme` → matches `/has an invalid slug/`, `findBySlug` never called.
4. `testRefusesSlugWithUppercase` — X-Transport `tenant_ACME` → matches `/has an invalid slug/`, `findBySlug` never called.
5. `testValidSlugStillRoutes` — X-Transport `tenant_acme` → routes through to the built tenant transport (invocation count == 1). Sanity check proving the new guards don't break the happy path.

## Decisions Made

- **Em-dash (U+2014) in new exception messages** — Matches the existing exception at line 88 in the same method for consistency. Verified by reading the file BEFORE editing.
- **Character set `[a-z0-9_-]+`** (not `[A-Za-z0-9_-]+`) — Rejects uppercase. Rationale: uppercase slugs would create a separate LRU cache entry from the canonical lower-case form and signal mis-construction upstream.
- **Guard position: BEFORE cross-tenant guard** — The cross-tenant guard at line 87 only triggers when `$activeTenant !== null`. Placing the new guards before it catches the no-active-tenant path (workers before `TenantWorkerMiddleware` restores context; sync-context misuse).
- **Inline guards (not extracted method)** — Two guard blocks, ~10 lines each with inline comments referencing `Plan 20-11 / REVIEW BL-02`. Extracting to a private `validateSlug()` helper would have hidden the regression marker from the call site without reducing complexity (the guards are deterministic and provider-agnostic).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Switched `testValidSlugStillRoutes` from `PlainSpyTransport::getSends()` to anon-class invocation-counting spy**

- **Found during:** Task 2 (running the new test file)
- **Issue:** The PLAN.md guidance for `testValidSlugStillRoutes` called `count($built->getSends())` but `tests/Unit/Mailer/Fixture/PlainSpyTransport` does NOT expose a `getSends()` method (the class is a stop-method-absent test double for an unrelated LRU-cache `method_exists()` guard test, not a per-send spy). Calling `$built->getSends()` would have produced `BadMethodCallException` at runtime.
- **Fix:** Replaced the `PlainSpyTransport` + `getSends()` invocation with an inline anon-class implementing `TransportInterface`, holding a counter-by-reference (`private int &$sendCount`), mirroring the pattern already used in the file's `testAllowsRoutingWhenContextTenantMatchesHeaderSlug`. The assertion `self::assertSame(1, $sendCount, ...)` directly proves the built transport's `send()` method was invoked exactly once after the new guards passed.
- **Files modified:** tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php
- **Verification:** `vendor/bin/phpunit tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php` now passes 17/17. Without this fix the test would have errored.
- **Committed in:** `e6a2fd4` (Task 2 commit)

---

**Total deviations:** 1 auto-fixed (1 bug — Rule 1)
**Impact on plan:** Cosmetic adjustment to a single test's spy mechanism. The acceptance criterion was a substantive routing assertion (count == 1), which the anon-class spy fulfills more cleanly than the originally-suggested `PlainSpyTransport::getSends()` (which doesn't exist). The plan's own note about "drop the FQCN prefix if the existing file already has the use statement" hinted at fixture verification — that verification surfaced the missing method as well. No scope creep.

## Issues Encountered

- **Worktree vendor bootstrap** — The fresh worktree had no `vendor/` directory or `composer.lock`. Symlinking from the main project (`vendor → ../../vendor`) produced an autoloader path collision that caused `Cannot redeclare class Tenancy\Bundle\Entity\Tenant` because both the main-project and worktree `src/` paths got mapped. Resolved by replacing the symlink with a real `composer install` in the worktree.
- **Stale system tempdir cache** — Several integration tests use `sys_get_temp_dir()/tenancy_*` cache directories shared across the host. The cached compiled containers held absolute paths to the main project's `src/`, also triggering the redeclaration. Resolved by `rm -rf /var/folders/.../T/tenancy_*` before re-running the suite. Full suite then passed 545/545.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- BL-02 from `20-VERIFICATION.md` / `20-REVIEW.md` is now closed. The verification's grep gate `grep -c "findBySlug('')" src/` returns 0 (no source path can call `findBySlug` with an empty string — the guard makes that unreachable).
- All four STRIDE threats in the plan's threat register (T-20-11-01..04) are now mitigated:
  - T-20-11-01 (Info Disclosure / Spoofing via empty-slug routing): mitigated by empty-slug guard + `testRefusesEmptySlugXTransportHeader`.
  - T-20-11-02 (Tampering via malformed slug): mitigated by character-set guard + four negative tests.
  - T-20-11-03 (DoS via unbounded LRU on invalid slugs): mitigated — guards run before `cache.get` / `cache.set`, so invalid slugs never reach the LRU.
  - T-20-11-04 (Elevation via provider-matching exploit): mitigated — bundle-level guard sits above the provider, deterministic and provider-agnostic.
- Ready for orchestrator to merge the worktree, run the wave's php-cs-fixer hook once across the merged tree, and update STATE.md / ROADMAP.md.

## Self-Check

Verifying claims before returning to the orchestrator:

**Files exist:**

- `src/Mailer/TenantAwareTransportsDecorator.php` — FOUND (modified, +24 lines)
- `tests/Unit/Mailer/TenantAwareTransportsDecoratorTest.php` — FOUND (modified, +147 lines, 5 new test methods)
- `.planning/phases/20-mailer-bootstrapper/20-11-SUMMARY.md` — FOUND (this file)

**Commits exist (git log --oneline):**

- `839c4e9` feat(20-11): add empty-slug + character-set guards in TenantAwareTransportsDecorator (BL-02) — FOUND
- `e6a2fd4` test(20-11): cover empty-slug + character-set guards in TenantAwareTransportsDecorator (BL-02) — FOUND

**Test counts:**

- TenantAwareTransportsDecoratorTest.php: 12 → 17 tests, 31 → 49 assertions — VERIFIED
- Full suite: 545 tests / 2011 assertions, all green — VERIFIED

**Acceptance criteria (from Task 1 + Task 2):**

- `grep -c "has an empty slug" src/Mailer/TenantAwareTransportsDecorator.php` returns 1 — PASS
- `grep -c "has an invalid slug" src/Mailer/TenantAwareTransportsDecorator.php` returns 1 — PASS
- `grep -F "preg_match('/^[a-z0-9_-]+$/', $slug)" src/Mailer/TenantAwareTransportsDecorator.php` returns 1 — PASS (PLAN's literal-grep was over-escaped; actual code matches the regex literally)
- `grep -c "BL-02" src/Mailer/TenantAwareTransportsDecorator.php` returns 2 (≥1) — PASS
- `grep -c "Plan 20-11" src/Mailer/TenantAwareTransportsDecorator.php` returns 2 (≥1) — PASS
- Order check: 'has an empty slug' (line 90) < 'does not match active tenant' (line 112) — PASS (guards positioned before cross-tenant guard)
- 5 new test methods each grep == 1 — PASS
- 4 `addTextHeader('X-Transport', ...)` literals each grep == 1 — PASS
- `provider->expects($this->never())->method('findBySlug')` grep == 8 (4 new + 4 pre-existing, ≥4 new) — PASS
- PHPStan level 9 on the modified source file: `[OK] No errors` — PASS

## Self-Check: PASSED

---

*Phase: 20-mailer-bootstrapper*
*Completed: 2026-05-20*
