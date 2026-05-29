# Phase 23 Discussion Log

**Mode:** audit-driven (no AskUserQuestion calls — scope was pre-determined by `.planning/v0.3-MILESTONE-AUDIT.md`)
**Started:** 2026-05-29
**Concluded:** 2026-05-29
**Decision count:** 8

## Context

The user invoked `/gsd:discuss-phase 23` immediately after the audit produced `status: tech_debt`. The audit explicitly enumerated 17 deferred items, 1 cross-phase WARNING (INT-01), and pre-tag housekeeping. The phase scope is fixed by that audit; the only open questions are HOW to implement each closure.

Auto mode + yolo mode were both active. Per the auto-mode directive ("bias toward working without stopping for clarifying questions — make the reasonable call and keep going"), Claude made the reasonable implementation calls inline and wrote CONTEXT.md without an interactive question loop.

The user retains full ability to redirect during plan-phase or execute-phase.

## Decisions Made Without User Interaction (with justification)

### INT-01 — Twig restructure approach

**Claude chose:** Move the entire mailer block out of the resolved-only branch to render unconditionally when `collector.data.mailer is defined`.

**Justification:** The collector populates the data on every request when LruTransportCache is wired (including null/error states). The original Twig nesting was a bug, not a design choice. Moving the block out matches the data contract.

**User can redirect to:** Collector-side guard (skip populating on null state). Bad because cache metrics are useful on null state, but a valid trade-off.

### CR-01 — Drift guard mechanism

**Claude chose:** Strengthen the existing `NullableProviderInjectionContractTest` to assert `= null` defaults via reflection. Skip the PHPStan rule.

**Justification:** Contract test already exists from Phase 18 gap closure. Strengthening it is cheap. PHPStan rule would require either a custom extension or a non-trivial pattern match — out of scope for closure phase.

**User can redirect to:** Custom PHPStan rule, separate test file, or accept current state.

### WR-01 — Exception class name

**Claude chose:** `Tenancy\Bundle\Exception\MissingTenantProviderException extends \LogicException`.

**Justification:** Greppable, self-documenting in stack traces, matches existing exception namespace pattern (`TenantNotFoundException`, `TenantInactiveException`, `TenantSanitizedTransportException` already live under `src/Exception/`).

**User can redirect to:** Bare `\LogicException` (less self-documenting), or a different prefix like `MisconfiguredException`.

### WR-02, WR-03, WR-04 — Nit handling

**Claude chose:** Defensive comments (WR-02 ConsoleResolver, WR-04 shell-injection trust boundary), pattern alignment (WR-03 QueryParamResolver `is_string()` check).

**Justification:** Minimal-change closures. None of these need behavioral changes — just clarity and consistency.

### IN-01 through IN-05 — Canary cleanup

**Claude chose:** Drop stale annotations, fix double-removal in tearDown, add PID to cache-dir hash, add explicit `use TenantStamp` import.

**Justification:** Pure cosmetics per audit. No behavioral change. Defensible as low-risk hygiene.

### Smoke.sh mailer assertion — Tenant count + tool choice

**Claude chose:** Two tenants (acme + globex), use `jq` for response parsing.

**Justification:**
- Two tenants prove isolation. Three add no signal.
- `jq` is on Ubuntu CI by default. Adding `python -c json.load` or sed-based parsing trades a clean assertion for opaque fragility.

**User can redirect to:** All 3 tenants (acme/globex/initech), different parsing tool, or in-PHP test instead of bash.

### CHANGELOG version dates

**Claude chose:** v0.3.2 — 2026-05-22 (Phase 21 ship date), v0.3.3 — 2026-05-29 (today, Phase 23 ship date).

**Justification:** Standard convention is `<version> — <ship date>`. v0.3.2 actually shipped on 2026-05-22 (per Phase 21 VERIFICATION + memory note). v0.3.3 ships today after Phase 23 lands.

**User can redirect to:** Different dates, but unlikely.

### REQUIREMENTS.md checkbox refresh

**Claude chose:** Flip RESV-06, DEMO-01, DOC-19 to `[x]` inline as part of Phase 23 work (not deferring to complete-milestone archival).

**Justification:** Cleaner git history — the milestone-archive commit becomes a pure file move rather than a file move + traceability update. Skipped GOV-01 (skipped status preserved).

## Scope Creep Captured

None. The user's intent ("comply with everything, resolve all tech debts") was fulfilled by the audit-driven scope. No new requirements or capabilities were added during discussion.

## Deferred Ideas

None for Phase 23. All identified tech debt was incorporated. Out-of-scope items (Mailpit-add-on docs, APM observability, parallel migrations) belong in v0.4–v0.6 per `PROJECT.md#later-milestones`.

## Canonical Refs Added During Discussion

All 14 canonical refs in 23-CONTEXT.md were added during the audit (not the discussion). The discussion did not introduce new refs.

---

_Recorded: 2026-05-29_
_Recorder: Claude (gsd-discuss-phase, audit-driven mode)_
