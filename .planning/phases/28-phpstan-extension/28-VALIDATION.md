---
phase: 28
slug: phpstan-extension
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-06-16
---

# Phase 28 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> Derived from `28-RESEARCH.md` § Validation Architecture. Task IDs are assigned by the planner; rows below map DX-03 acceptance criteria to their automated proof.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11 (`phpunit/phpunit ^11.0`) via `PHPStan\Testing\RuleTestCase` |
| **Config file** | `phpunit.xml.dist` (existing — `tests/Unit` glob already covers `tests/Unit/PHPStan`) |
| **Quick run command** | `vendor/bin/phpunit tests/Unit/PHPStan --no-coverage` |
| **Full suite command** | `vendor/bin/phpunit` |
| **Estimated runtime** | ~15 seconds (rule tests only); full suite ~60s |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit tests/Unit/PHPStan --no-coverage`
- **After every plan wave:** Run `vendor/bin/phpunit && vendor/bin/phpstan analyse`
- **Before `/gsd:verify-work`:** Full suite green + `vendor/bin/phpstan analyse` clean (level-9 self-analysis must still pass after adding `src/PHPStan/`)
- **Max feedback latency:** ~15 seconds (quick run)

---

## Per-Task Verification Map

> Task IDs (`28-NN-MM`) are bound by the planner. Each acceptance criterion below MUST be claimed by at least one task's `<automated>` verification.

| Acceptance | Rule | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|------------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| AC1 fires | Rule 1 | DX-03 | T-leak-mutual | Reports error when `#[Shared]` + `#[TenantAware]` co-present on a class | unit (RuleTestCase) | `vendor/bin/phpunit tests/Unit/PHPStan/Rule/MutualExclusionRuleTest.php` | ❌ W0 | ⬜ pending |
| AC1 hierarchy | Rule 1 | DX-03 | T-leak-mutual | Fires when a marker sits on a parent / `MappedSuperclass` (attributes not inherited) | unit (RuleTestCase) | same | ❌ W0 | ⬜ pending |
| AC1 clean | Rule 1 | DX-03 | — | Silent when only one of the two attributes is present | unit (RuleTestCase) | same | ❌ W0 | ⬜ pending |
| AC2 fires | Rule 2 | DX-03 | T-leak-crossEM | Fires when tenant/default EM queries a `#[Shared]` entity without landlord override | unit (RuleTestCase) | `vendor/bin/phpunit tests/Unit/PHPStan/Rule/SharedEntityLeakRuleTest.php` | ❌ W0 | ⬜ pending |
| AC2 gated-off | Rule 2 | DX-03 | T-leak-crossEM | Silent when `tenancy.checkSharedEntityLeaks: false` | unit (RuleTestCase) | same | ❌ W0 | ⬜ pending |
| AC2 conservative | Rule 2 | DX-03 | T-leak-crossEM | Silent on ambiguous `EntityManagerInterface` / landlord-EM path (no false positive) | unit (RuleTestCase) | same | ❌ W0 | ⬜ pending |
| AC3 missing | Rule 3 | DX-03 | T-drift | Fires when `#[TenantAware]` entity has no column mapped to `tenant_id` | unit (RuleTestCase) | `vendor/bin/phpunit tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php` | ❌ W0 | ⬜ pending |
| AC3 nullable | Rule 3 | DX-03 | T-drift | Fires when `tenant_id` column is nullable | unit (RuleTestCase) | same | ❌ W0 | ⬜ pending |
| AC3 type | Rule 3 | DX-03 | T-drift | Fires when `tenant_id` column is a non-string type | unit (RuleTestCase) | same | ❌ W0 | ⬜ pending |
| AC4 install | neon | DX-03 | — | `extension.neon` loadable via `getAdditionalConfigFiles()`; `composer.json#extra.phpstan.includes` points at it | unit (all rule tests load the neon) | `vendor/bin/phpunit tests/Unit/PHPStan` | ❌ W0 | ⬜ pending |
| AC5 message | all | DX-03 | — | Error message names file + line + violation kind; carries a `tenancy.*` identifier | unit (RuleTestCase message+identifier assertions) | same | ❌ W0 | ⬜ pending |
| Self-analysis | — | — | — | Bundle's own `phpstan analyse` (level 9) still green after adding `src/PHPStan/` | static analysis | `vendor/bin/phpstan analyse` | ✅ (existing CI job) | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `src/PHPStan/Rule/` — 3 rule classes + optional `Helper/AttributeHierarchyHelper.php` (shared ancestor-walk + marker resolver)
- [ ] `extension.neon` at package root (new file — `parametersSchema` + `parameters` default for `tenancy.checkSharedEntityLeaks`, `services` registering the 3 rules with `phpstan.rules.rule` tag)
- [ ] `tests/Unit/PHPStan/Rule/MutualExclusionRuleTest.php` — covers AC1
- [ ] `tests/Unit/PHPStan/Rule/SharedEntityLeakRuleTest.php` — covers AC2 (incl. gated-off + conservative-silent)
- [ ] `tests/Unit/PHPStan/Rule/TenantIdDriftRuleTest.php` — covers AC3
- [ ] `tests/Unit/PHPStan/Rule/data/` — violating + clean fixtures per rule (incl. a hierarchy/`MappedSuperclass` fixture for Rule 1, and a non-string `tenant_id` fixture for Rule 3)
- [ ] `phpunit.xml.dist` — confirm `tests/Unit/PHPStan` is covered by the existing `tests/Unit` directory glob (it is — no edit needed)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Zero-config auto-load via `phpstan/extension-installer` in a real consumer project | DX-03 AC4 | extension-installer behavior depends on a downstream consumer's composer install; not reproducible inside the bundle's own RuleTestCase harness | In a scratch consumer project: `composer require --dev danplaton4/tenancy-bundle phpstan/extension-installer`, then `vendor/bin/phpstan analyse` — confirm the 3 rules register without a manual `includes:` line |
| `phpstan/phpstan-doctrine` "present" path (ObjectMetadataResolver) with XML/YAML-mapped entities | DX-03 AC3 (D-02 present) | phpstan-doctrine 2.0.x is dev-only and requires phpstan ^2.2.2 vs 2.1.50 installed — may not be CI-installable this phase; reflection fallback is the stable tested path | If phpstan-doctrine is installed in a consumer: confirm Rule 3 reads real `ClassMetadata` for an XML-mapped `#[TenantAware]` entity |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 15s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
