# Phase 30: v0.4 Pre-Tag Closure (Integration Warnings + Roadmap Drift) - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-06-19
**Phase:** 30-v0-4-pre-tag-closure-integration-warnings-roadmap-drift
**Areas discussed:** W-02 de-dup mechanism, W-02 shape, W-03 asymmetry, W-01 testability depth, Docs/lint scope (WR-06/07 + WR-03)

---

## W-02 — De-dup mechanism

| Option | Description | Selected |
|--------|-------------|----------|
| Injected helper service | New final service + interface, injected into subscriber + handler; fits DI style, testable, gives W-03 a home | ✓ |
| Shared private trait | Trait used by both classes; no DI, but implicit property contract + harder to unit-test | |
| Keep duplicated + drift guard test | Leave duplication, add a test that fails on divergence; smallest change but doesn't remove dup | |

**User's choice:** Injected helper service (Recommended)
**Notes:** Naturally encapsulates the lightweight `close()+resetManager()` path that W-03 contrasts against resync's full boot.

## W-02 — Shape (interface vs concrete)

| Option | Description | Selected |
|--------|-------------|----------|
| Extract an interface | `TenantEmSwitcherInterface` + final `TenantEmSwitcher`, inject the interface; mock-a-final convention, serves W-01 testability too | ✓ |
| Concrete final class only | Inject the final class directly; less surface but subscriber/handler tests can't mock the switch step | |

**User's choice:** Extract an interface (Recommended)
**Notes:** Mirrors `SharedEntityCopierInterface` / `TenantConnectionInterface`.

---

## W-03 — Asymmetry: reconcile vs document

| Option | Description | Selected |
|--------|-------------|----------|
| Document the intentional asymmetry | Keep both mechanisms; switcher docblock contrasts resync's full boot + resync back-ref note; no behavior change | ✓ |
| Reconcile to one mechanism | Make both switch identically; rejected — full boot in fan-out fires all bootstrappers/events, stripping resync's boot changes its semantics | |

**User's choice:** Document the intentional asymmetry (Recommended)
**Notes:** Lowest risk before a tag.

---

## W-01 — Testability depth

| Option | Description | Selected |
|--------|-------------|----------|
| Swap type-hints + mock-injection test(s) | Change both sites to `SharedEntityCopierInterface` AND add unit test(s) injecting a mock copier/switcher to prove the seam | ✓ |
| Swap type-hints only | Just the two type-hint changes, no new test | |

**User's choice:** Swap type-hints + add mock-injection test(s) (Recommended)
**Notes:** Minimum proof = write-protection listener `isSyncInProgress()` bypass + throw-on-`#[Shared]`-write.

---

## Docs/lint scope — WR-06/07 roadmap reconciliation

| Option | Description | Selected |
|--------|-------------|----------|
| Fuller pass | v0.4 → Shipped (D-07 vocab), PHPStan → 3 real rules, clear stale v0.3 block + "partial/v0.3.2", Next = v0.5, no tag on v0.4 entry | ✓ |
| Minimal — only the two audit lines | Fix only WR-06 + WR-07, leave stale v0.3 framing | |

**User's choice:** Fuller pass — reconcile the whole file to reality (Recommended)
**Notes:** v0.4 Shipped entry carries no tag number (commit lands before the tag exists) — links CHANGELOG.

## Docs/lint scope — WR-03 docs-lint awk fold-in

| Option | Description | Selected |
|--------|-------------|----------|
| Fold it in now | Add one-line `FNR==1 { in_whitelist=0 }` reset to the D-15 awk block; closes a Phase 29 deferred item | ✓ |
| Defer it | Leave the awk bug for a later docs phase | |

**User's choice:** Fold it in now (Recommended)
**Notes:** Confirmed cheap; fixes a real cross-file false-negative in CI.

---

## Claude's Discretion

- Exact service/interface names, method signatures, file placement, DI service id (W-02).
- Precise test file locations and case count (W-01), as long as the seam is exercised.
- Exact roadmap.md prose and section ordering, as long as D-08/D-09 content holds.

## Deferred Ideas

- Nyquist validation gaps (phases 24/26/28/29) — `/gsd:validate-phase`, discovery-only.
- Manual UAT pending — Phase 26 TTY confirm; Phase 28 extension-installer auto-load (need a human).
- Phase 27 advisory review items (unvalidated changeType, stale-context-on-failure, dead findAll loop).
- Phase 29 info-level docs polish (tenancy:run caveat, slug-validation wording, addslashes characterization).
- `mkdocs build --strict` — CI-deferred (not installable locally).
