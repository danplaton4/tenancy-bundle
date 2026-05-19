# Phase 10: Dependency Compatibility Audit - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-04-10
**Phase:** 10-dependency-compatibility-audit
**Areas discussed:** Audit scope & deliverables, Symfony version range, Doctrine version strategy, CI matrix expansion

---

## Audit Scope & Deliverables

| Option | Description | Selected |
|--------|-------------|----------|
| Code fixes + CI only | Fix issues, update CI — no separate docs | |
| Code fixes + CI + compat matrix doc | Same + COMPATIBILITY.md for consumers | |
| Full audit report + fixes | Formal audit report of all dep interactions, then fix everything | ✓ |

**User's choice:** Full audit report + fixes
**Notes:** User wants thorough approach for OSS confidence

---

| Option | Description | Selected |
|--------|-------------|----------|
| Integrated deps only | Doctrine, Messenger, Symfony core — what's in composer.json | |
| All deps + future ones | Also audit Flysystem, Mailer, and other v1.1 deps | ✓ |

**User's choice:** All deps + future ones
**Notes:** Broad audit scope

---

| Option | Description | Selected |
|--------|-------------|----------|
| COMPATIBILITY.md in repo root | Visible to OSS consumers | |
| .planning/ only | Internal planning artifact | ✓ |
| Both | Full report internal, summary public | |

**User's choice:** .planning/ only
**Notes:** Internal artifact, findings applied to code/CI

---

| Option | Description | Selected |
|--------|-------------|----------|
| Yes, include v1.1 readiness | Forward compat section in report | |
| No, v1 scope only | Keep audit focused on current deps | ✓ |

**User's choice:** No, v1 scope only

---

| Option | Description | Selected |
|--------|-------------|----------|
| Constraints only | Check version ranges for v1.1 deps, no code | ✓ |
| Constraints + patterns | Also prototype integration patterns | |

**User's choice:** Constraints only
**Notes:** v1.1 deps get version range check, no code changes

---

| Option | Description | Selected |
|--------|-------------|----------|
| Yes, automated scan | Grep/PHPStan for PHP >8.2 syntax | ✓ |
| Trust CI | CI on PHP 8.2 already validates this | |

**User's choice:** Yes, automated scan

---

| Option | Description | Selected |
|--------|-------------|----------|
| Yes, check deprecations | Run with deprecation notices, fix proactively | ✓ |
| No, skip for now | Address when they become removals | |

**User's choice:** Yes, check deprecations

---

## Symfony Version Range

| Option | Description | Selected |
|--------|-------------|----------|
| Yes, 7+8 only | Drop 6.4 references, ^7.0\|\|^8.0 | ✓ |
| Keep 6.4 support | Broader reach, more constraints | |

**User's choice:** Yes, 7+8 only
**Notes:** Symfony 6.4 officially dropped

---

| Option | Description | Selected |
|--------|-------------|----------|
| Polyfill/shim pattern | Conditional code paths for 7.x vs 8.x | |
| Minimum Symfony 7.4 | Forward-compat APIs overlap | |
| You decide | Claude picks based on actual breaking changes | ✓ |

**User's choice:** Initially picked "Minimum Symfony 7.4", then clarified that Symfony 7 already requires PHP 8.2+ so the constraint question is about Symfony range not PHP range. Deferred to Claude's discretion.
**Notes:** User clarified PHP 8.2+ floor is natural from Symfony 7 requirement

---

| Option | Description | Selected |
|--------|-------------|----------|
| ^7.0\|\|^8.0 (broad) | All Symfony 7.x users | |
| ^7.4\|\|^8.0 (narrower) | Forward-compat APIs, cleaner code | |
| You decide | Claude picks based on actual API changes | ✓ |

**User's choice:** You decide
**Notes:** Deferred to Claude's research on actual breaking changes between Symfony 7.0-7.3 and 8.0

---

## Doctrine Version Strategy

| Option | Description | Selected |
|--------|-------------|----------|
| Support both (^2.13\|\|^3.0) | Current approach, both versions | |
| DoctrineBundle 3.x only | Simpler but locks out PHP 8.2/8.3 | |
| DoctrineBundle 2.x only | Safe for all PHP versions | |

**User's choice:** "You decide the best approach after research"
**Notes:** Deferred to Claude's research on DoctrineBundle compatibility

---

| Option | Description | Selected |
|--------|-------------|----------|
| Yes, test both 3.x and 4.x | Verify tenancy:migrate with both | |
| 3.x only for now | MigrationsBundle 4.0 not stable yet | |
| You decide | Claude determines based on release status | ✓ |

**User's choice:** You decide

---

| Option | Description | Selected |
|--------|-------------|----------|
| Yes, comprehensive guard audit | Trace every Doctrine import path | ✓ |
| Trust existing guards | No-doctrine CI job validates them | |

**User's choice:** Yes, comprehensive guard audit

---

## CI Matrix Expansion

| Option | Description | Selected |
|--------|-------------|----------|
| Yes, add prefer-lowest job | Catches floor constraint violations | ✓ |
| No, highest only | Simpler matrix | |
| You decide | Claude picks based on risk | |

**User's choice:** Yes, add prefer-lowest job

---

| Option | Description | Selected |
|--------|-------------|----------|
| Yes, add no-messenger job | Validates Messenger guards | ✓ |
| No, trust the guards | Only 2 guard points, low risk | |
| You decide | Claude decides based on risk | |

**User's choice:** Yes, add no-messenger job

---

| Option | Description | Selected |
|--------|-------------|----------|
| Separate job (current approach) | Clearer failure isolation | ✓ |
| Matrix include entry | More uniform but complex | |
| You decide | Claude picks for maintainability | |

**User's choice:** Separate job (current approach)

---

## Claude's Discretion

- Symfony constraint range (^7.0||^8.0 vs ^7.4||^8.0) — decide after research
- DoctrineBundle version strategy (2.x, 3.x, or both) — decide after research
- MigrationsBundle 4.x testing — decide based on release status

## Deferred Ideas

None — discussion stayed within phase scope
