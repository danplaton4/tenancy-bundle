# Phase 28: PHPStan Extension - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-06-16
**Phase:** 28-phpstan-extension
**Areas discussed:** Rule activation model, Doctrine metadata source, Rule 2 leak detection, Rule 3 drift checks

---

## Rule activation model

| Option | Description | Selected |
|--------|-------------|----------|
| Auto-fire safe, gate leak rule | extension-installer auto-loads; Rules 1 & 3 fire zero-config; Rule 2 behind a `tenancy.checkSharedEntityLeaks` parameter toggle (default ON, silenceable); manual `includes:` for non-installer users | ✓ |
| Auto-fire all three | Pure zero-config: all 3 rules fire on every consumer's analyse; strongest security default; risk Rule 2 false positives break CI on upgrade with no granular off-switch | |
| Strictly opt-in | Nothing fires until the user adds the `includes:` snippet, even with extension-installer; quietest, but forfeits zero-config and lowers odds the leak rules ever run | |

**User's choice:** Auto-fire safe, gate leak rule
**Notes:** The acceptance line names both "auto-loaded via extension-installer" and "opt-in via documented phpstan.neon snippet" — reconciled as two install paths to the same outcome (mirrors phpstan-symfony/phpstan-doctrine). The parameter toggle is cheap insurance against a noisy Rule 2 hard-breaking a consumer's CI.

---

## Doctrine metadata source

| Option | Description | Selected |
|--------|-------------|----------|
| Soft-integrate, degrade | Use phpstan-doctrine's ObjectMetadataResolver when present (real ClassMetadata, any mapping format, resolves query entity types); fall back to `#[ORM\Column]` reflection scan + literal `::class` resolution when absent; no forced dep | ✓ |
| Hard-require phpstan-doctrine | Build Rules 2 & 3 only on phpstan-doctrine's metadata; cleanest single path, full XML/YAML, strongest Rule 2; forces consumers to install + configure phpstan-doctrine, breaking the guarded-optional-dep pattern | |
| Reflection/attribute-only | No phpstan-doctrine coupling; zero extra dep; blind to XML/YAML mappings (Rule 3 gap) and weakest Rule 2 (literal `::class` only) | |

**User's choice:** Soft-integrate, degrade
**Notes:** Matches the bundle's defining "Doctrine is an optional, guarded dependency" identity. Guard the phpstan-doctrine path with a `class_exists`/service-availability check.

---

## Rule 2 leak detection

| Option | Description | Selected |
|--------|-------------|----------|
| Conservative (stay silent) | Fire only when confidently the tenant/default EM queries a `#[Shared]` entity; silent on ambiguous `EntityManagerInterface`; safe = named landlord EM or `@phpstan-ignore tenancy.sharedEntityLeak`; precision over recall so the rule stays enabled | ✓ |
| Tenant-default heuristic | Treat the default/autowired EM as the tenant EM and fire on it; false positives where the default EM is the landlord or `database_per_tenant` isn't used | |
| Aggressive (default-deny) | Flag every `#[Shared]` query unless proven-landlord or suppressed; max coverage, highest false-positive rate, leans hard on the toggle + suppression | |

**User's choice:** Conservative (stay silent)
**Notes:** A security rule that cries wolf gets toggled off and protects nobody. The acceptance's literal `setEntityManager('landlord')` does not exist in the codebase and is illustrative — the real static signal is which EM the query goes through (named landlord vs tenant/default). Runtime write-protection (Phase 25) + resync (Phase 26) backstop the conservatively-skipped cases.

---

## Rule 3 drift checks

| Option | Description | Selected |
|--------|-------------|----------|
| Name + nullable + string-type | Fire when `tenant_id` column is missing, nullable, or non-string; string-type check targets the insidious int/association drift the filter's quoted-slug comparison would break on | ✓ |
| Name + nullable only (spec-literal) | Fire only on the two verbatim acceptance conditions (missing OR not nullable=false); no type opinion; a non-string tenant_id slips through | |
| Add length ≥ 63 too | Recommended option plus asserting VARCHAR length ≥ 63 (matching the docblock); most thorough but 63 isn't an enforced limit, risks flagging valid columns | |

**User's choice:** Name + nullable + string-type
**Notes:** Column name is NOT configurable — the filter hardcodes `tenant_id` (`src/Filter/TenantAwareFilter.php:47`), so Rule 3 validates that literal mapped column name. No length assertion (the VARCHAR(63) docblock is documentation, not an enforced limit).

---

## Claude's Discretion

- Rule-class location/namespace, the shipped neon filename, and keeping the shipped extension neon separate from the bundle's self-analysis `phpstan.neon`.
- Exact error-identifier strings (consistent `tenancy.*` namespace; `tenancy.sharedEntityLeak` is fixed by D-03, the others are Claude's to name).
- Whether each rule is one class or shares helpers (hierarchy-aware attribute detector, shared class resolver).
- `RuleTestCase` fixture design and whether the extension dogfoods on the bundle's own `src`/fixtures.
- `composer.json` require-dev additions to test the rules vs `suggest` entries for consumers.
- Reflection-fallback mechanics (reading `name`/`nullable`/`type` from `#[ORM\Column]` args) vs the phpstan-doctrine `ClassMetadata` path.

## Deferred Ideas

- User-guide `phpstan-extension.md` page — Phase 29 (DOC-20). This phase ships inline PHPDoc + a deferred note only.
- Aggressive / tenant-default Rule 2 modes — rejected as D-03 alternatives; revisit only if conservative Rule 2 misses real leaks (a future opt-in `strictness` parameter could layer on the existing toggle).
- VARCHAR length assertion on `tenant_id` — rejected in D-04; revisit only if a concrete length-driven bug surfaces.
- Configurable tenant-scope column name — rejected outright (structurally out of scope, not deferred).
