---
phase: 28-phpstan-extension
plan: 06
subsystem: testing
tags: [phpstan, phpstan-rules, static-analysis, doctrine, optional-dependency, wiring, ci]

# Dependency graph
requires:
  - phase: 28-phpstan-extension
    plan: 05
    provides: "CR-01-fixed checkViaMetadata() + WR-02 MappedSuperclass skip + WR-04 positional args + resolver-injected CI tests"

provides:
  - "extension-doctrine.neon: standalone wired fragment injecting @PHPStan\\Type\\Doctrine\\ObjectMetadataResolver into TenantIdDriftRule (WR-01 closed)"
  - "phpstan-extension-dogfood.neon: updated to include ONLY extension-doctrine.neon — exercises wired metadata path end-to-end (truth #10 closed)"
  - "phpstan-extension-dogfood-nodoctrine.neon: base-only dogfood proving graceful degradation without Doctrine (WR-05 closed)"
  - "CI no-doctrine job extended: removes phpstan/phpstan-doctrine, asserts phpstan --version survives (Warning-4 guard), runs tests/Unit/PHPStan + base dogfood"

affects:
  - "consumers following composer.json suggest for phpstan/phpstan-doctrine — metadata path now actually runs"
  - "Phase 29 DOC-20 — consumer guidance for using extension-doctrine.neon (INSTEAD OF base extension.neon, not in addition to it)"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Standalone wired fragment pattern: extension-doctrine.neon registers FULL rule set (no includes:) so dogfood includes ONLY it — single TenantIdDriftRule registration, no PHPStan dedupe needed"
    - "Warning-4 guard in CI: 'phpstan --version' step after composer remove verifies phpstan/phpstan was not cascade-removed by removing phpstan-doctrine"
    - "Self-skip pattern for optional-dep tests: class_exists(ObjectMetadataResolver) in test setUp → metadata tests skip in no-doctrine lane; reflection tests still run"

key-files:
  created:
    - extension-doctrine.neon
    - phpstan-extension-dogfood-nodoctrine.neon
  modified:
    - phpstan-extension-dogfood.neon
    - .github/workflows/ci.yml

key-decisions:
  - "Standalone fragment (no includes:) chosen as the sole mechanism to avoid double-registration: loading both base extension.neon AND a wiring fragment would register TenantIdDriftRule twice (PHPStan Nette DI never dedupes phpstan.rules.rule by class), training suppression via doubled errors"
  - "phpstan-doctrine also removed in no-doctrine lane (not just doctrine/orm etc.): removes the resolver SERVICE so extension-doctrine.neon's @PHPStan\\Type\\Doctrine\\ObjectMetadataResolver reference would crash — proves the conditional-load model works and the base dogfood stays clean"
  - "Warning-4 guard (phpstan --version) placed BEFORE the dogfood step: if phpstan-doctrine removal ever cascade-removes phpstan/phpstan, the guard fails LOUDLY at the version step — not silently at the dogfood step where 'analyser missing' looks like a guard regression"

patterns-established:
  - "Conditional neon fragment pattern: @service refs to optional packages live in separate fragments never co-loaded with the base; base stays crash-safe for absent-dep consumers"
  - "No-dep lane pattern: remove optional dep + assert analyser survives + run rule tests + run base dogfood = 3-layer guard for optional-dependency contracts"

requirements-completed: [DX-03]

# Metrics
duration: 6min
completed: 2026-06-17
---

# Phase 28 Plan 06: Gap-Closure (WR-01/WR-05) Summary

**ObjectMetadataResolver wired via standalone doctrine fragment; no-doctrine CI lane proves graceful degradation with Warning-4 phpstan-survival guard**

## Performance

- **Duration:** ~6 min
- **Started:** 2026-06-17T06:16:55Z
- **Completed:** 2026-06-17T06:22:55Z
- **Tasks:** 2
- **Files modified:** 2 created, 2 modified

## Accomplishments

- WR-01 closed: `ObjectMetadataResolver` is now injected into `TenantIdDriftRule` via `extension-doctrine.neon` — a standalone fragment that registers the full rule set with `objectMetadataResolver: @PHPStan\Type\Doctrine\ObjectMetadataResolver`; the D-02 metadata path is reachable (truth #10 closed)
- Double-registration resolved via standalone fragment: `extension-doctrine.neon` has no `includes:` section (YAML key absent) so the dogfood including ONLY it produces exactly ONE `TenantIdDriftRule` service registration; `vendor/bin/phpstan analyse -c phpstan-extension-dogfood.neon` exits 0 with zero errors (not doubled)
- WR-05 closed: no-doctrine CI lane now (a) removes `phpstan/phpstan-doctrine`, (b) asserts `phpstan --version` exits 0 (Warning-4 guard proving phpstan/phpstan survived), (c) runs `tests/Unit/PHPStan` (metadata tests self-skip via `class_exists` guard), and (d) runs `phpstan-extension-dogfood-nodoctrine.neon` over `src/`
- Base `extension.neon` stays resolver-less and UNCHANGED — degrade path preserved, extension-installer auto-load safe in all environments
- All verification passes: both dogfoods exit 0, bundle self-analysis exits 0, full suite 761/761 (0 failures)

## Task Commits

1. **Task 1: Wire ObjectMetadataResolver via standalone doctrine fragment (WR-01)** - `0f7179e` (feat)
2. **Task 2: WR-05 — no-doctrine CI lane runs base dogfood + tests/Unit/PHPStan** - `ae8d326` (feat)

## Files Created/Modified

- `extension-doctrine.neon` — NEW: standalone wired fragment (no `includes:` YAML key); registers full rule set with `TenantIdDriftRule` getting `objectMetadataResolver: @PHPStan\Type\Doctrine\ObjectMetadataResolver`; MUST NOT be loaded without phpstan-doctrine (resolver service absent → config-merge crash)
- `phpstan-extension-dogfood.neon` — UPDATED: `includes:` changed from `[extension.neon]` to `[extension-doctrine.neon]`; exercises the wired metadata path; exactly one `TenantIdDriftRule` registered (single-fire confirmed by 0-error dogfood run)
- `phpstan-extension-dogfood-nodoctrine.neon` — NEW: base-only dogfood (includes `extension.neon` only); used by no-doctrine CI lane; `grep -c 'extension-doctrine'` returns 0
- `.github/workflows/ci.yml` — no-doctrine job extended with 3 new steps: `phpstan-doctrine` added to remove command, `phpstan --version` guard, `tests/Unit/PHPStan` added to phpunit step, dogfood step with `phpstan-extension-dogfood-nodoctrine.neon`; with-doctrine `phpstan` job at lines 59-75 UNTOUCHED

## Decisions Made

- **Standalone fragment (no base include) is the ONLY safe mechanism**: PHPStan's Nette DI does not dedupe `phpstan.rules.rule`-tagged services by class. Including both `extension.neon` (resolver-less `TenantIdDriftRule`) and a wiring fragment would produce two registrations — every rule violation fires twice, training consumers to suppress the rule. The standalone fragment owns the full rule set and the dogfood includes ONLY it.
- **phpstan-doctrine removed in no-doctrine lane**: removing only `doctrine/orm` etc. leaves phpstan-doctrine installed (its resolver service still registered). The lane must also remove phpstan-doctrine so `extension-doctrine.neon`'s `@service` reference would be absent — proving the base dogfood is the correct no-doctrine tool. phpstan/phpstan is a separate top-level require-dev, not a transitive dep of phpstan-doctrine alone, so removing phpstan-doctrine does not cascade-remove the analyser.
- **Warning-4 guard placement**: the `phpstan --version` assertion step runs BEFORE the dogfood step so a cascade-remove of phpstan/phpstan fails at the correct step (version check, not the dogfood) — CI triagers see "analyser missing" not "guard regression".

## Dogfood Includes Structure (for output spec)

| Config | Includes | Purpose |
|--------|----------|---------|
| `phpstan-extension-dogfood.neon` | `extension-doctrine.neon` ONLY | Wired path (Doctrine + phpstan-doctrine present); exercises metadata path with WR-01 resolver injection + CR-01 fix |
| `phpstan-extension-dogfood-nodoctrine.neon` | `extension.neon` ONLY | No-doctrine path; proves graceful degradation; runs in CI after Doctrine + phpstan-doctrine removed |
| `extension-doctrine.neon` | NONE (no includes: key) | Standalone wired fragment; MUST NOT be loaded without phpstan-doctrine |
| `extension.neon` | NONE | Base resolver-less; auto-loaded via extension-installer; safe in all environments |

## phpstan/phpstan Survival in no-doctrine Lane (Warning-4)

Confirmed: `phpstan/phpstan` is a separate direct `require-dev` entry in `composer.json`. Removing `phpstan/phpstan-doctrine` does not cascade-remove it. The `phpstan --version` step in CI will exit 0 (analyser present), ensuring the dogfood step failure means a guard regression — not a missing analyser.

## tests/Unit/PHPStan Green Without phpstan-doctrine

The 28-05 metadata-path tests (`testMetadataPathEntersMissingTenantId` and `testMetadataPathEntersFoundTenantId`) carry `class_exists(\PHPStan\Type\Doctrine\ObjectMetadataResolver::class) or $this->markTestSkipped(...)` guards. With phpstan-doctrine removed, both skip. The remaining reflection-path tests (7 tests) and MappedSuperclass hierarchy tests still run and assert behavior — proving the rule classes load without fatal error. CI suite passes.

## Deviations from Plan

None — plan executed exactly as written. Both acceptance criteria checks passed first time (dogfood exits 0; python YAML assertions all pass).

## Threat Flags

None — no new network endpoints, auth paths, file access patterns, or schema changes introduced.

## Known Stubs

None — all artifacts are neon config and CI YAML; no runtime data flows.

## User Setup Required

None.

## Next Phase Readiness

- Plan 28-06 closes WR-01 and WR-05. Together with 28-05 (CR-01/WR-02/WR-04), all gap-closure work is complete.
- Phase 28 verification can now re-run: truths #9 (CR-01), #10 (WR-01), #11 (WR-02), #12 (WR-04) should all be VERIFIED.
- Consumer guidance for using `extension-doctrine.neon` is deferred to Phase 29 DOC-20 (INSTEAD OF base, not in addition to it).
- The Warning-4 phpstan-survival guard is a standing CI protection — no follow-up needed.

---
*Phase: 28-phpstan-extension*
*Completed: 2026-06-17*
