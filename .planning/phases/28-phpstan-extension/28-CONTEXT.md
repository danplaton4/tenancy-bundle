# Phase 28: PHPStan Extension - Context

**Gathered:** 2026-06-16
**Status:** Ready for planning

<domain>
## Phase Boundary

Deliver **DX-03**: a PHPStan extension shipped with the bundle that catches `#[TenantAware]` / `#[Shared]` misuse at static-analysis time — *before* it becomes a runtime cross-tenant data-leak. Three rules, all locked by the SHARE-* acceptance criteria:

1. **Mutual exclusion (Rule 1)** — fires when a class carries BOTH `#[TenantAware]` and `#[Shared]`. Editor-time belt-and-suspenders OVER Phase 25's boot-time `SharedEntityMutualExclusionPass`; unlike the pass it scans attributes **directly** (does NOT depend on the `tenancy.shared_entity` container tag).
2. **Cross-EM leak (Rule 2)** — fires when a Doctrine query in **tenant-EM** context targets a `#[Shared]` entity without an explicit landlord override (the potential cross-tenant leak rule).
3. **`tenant_id` config drift (Rule 3)** — fires when a `#[TenantAware]` entity's `tenant_id` column is missing, nullable, or not a string type.

Distributed via `phpstan/extension-installer` + `composer.json#extra.phpstan.includes` (**DEC-PHPSTAN-01**, locked), with a documented manual `includes:` snippet for consumers who don't run extension-installer.

**In scope:**
- The three PHPStan rules above + their error identifiers and clear file/line/violation-kind messages.
- A shipped extension `.neon` wired into `composer.json#extra.phpstan.includes`, auto-loaded by `phpstan/extension-installer`.
- A `tenancy.checkSharedEntityLeaks` PHPStan parameter (default ON) gating Rule 2 only.
- Soft integration with `phpstan/phpstan-doctrine` (real `ClassMetadata` when present) + a reflection/`#[ORM\Column]` fallback when absent.
- `RuleTestCase`-based test coverage with violating + clean fixtures for each rule.

**Out of scope (own phases / explicit non-goals):**
- The user-guide `phpstan-extension.md` page — Phase 29 (DOC-20). This phase ships inline PHPDoc + a deferred note for the docs page.
- A configurable tenant-scope column name — the `TenantAwareFilter` hardcodes `tenant_id`, so Rule 3 validates that literal column; configurability would mask runtime failures (rejected, see D-04).
- Re-implementing the runtime mutual-exclusion guard — `SharedEntityMutualExclusionPass` stays the boot-time half; Rule 1 is additive editor-time detection (Phase 25 D-04).

</domain>

<decisions>
## Implementation Decisions

### Rule activation model
- **D-01 (user choice — middle path):** **Auto-fire the safe rules; gate the leak rule behind a parameter toggle.** `phpstan/extension-installer` auto-loads the shipped `extension.neon`, registering all three rules. Rule 1 (mutual exclusion) and Rule 3 (`tenant_id` drift) fire **zero-config** — they are low-false-positive and high-value, so they run on every consumer's `phpstan analyse` automatically. Rule 2 (cross-EM leak) is registered but its firing is gated by a documented PHPStan parameter `tenancy.checkSharedEntityLeaks` (**default `true`**, but trivially silenced with one line) so a noisy upgrade can't hard-break a consumer's CI with no granular off-switch. Consumers who do NOT use `phpstan/extension-installer` enable everything via a documented manual `includes:` snippet pointing at the shipped neon.
  - **Reconciliation of the acceptance wording:** the acceptance line names BOTH "auto-loaded via extension-installer" AND "opt-in via a documented phpstan.neon snippet" — these are NOT contradictory, they are **two install paths to the same outcome** (mirrors how `phpstan/phpstan-symfony` and `phpstan/phpstan-doctrine` ship). extension-installer present → zero-config auto-load; absent → the manual `includes:` snippet is the opt-in. DEC-PHPSTAN-01's "zero-config" is honored.
  - **Rejected — auto-fire all three (no toggle):** strongest security default and the literal zero-config reading, but Rule 2's false positives could break a consumer's CI on a version bump with only baseline/`ignoreErrors` to escape. The parameter toggle is the cheap insurance.
  - **Rejected — strictly opt-in (nothing fires until the user adds `includes:`):** quietest, but forfeits the zero-config promise and sharply lowers the odds the leak rules ever run for most users — the rules exist to be on by default.

### Doctrine metadata source
- **D-02 (user choice — soft-integrate, degrade):** **Use `phpstan/phpstan-doctrine`'s `ObjectMetadataResolver` when present; degrade gracefully to a reflection scan when absent.** This matches the bundle's defining "Doctrine is an *optional, guarded* dependency" identity. When phpstan-doctrine is installed (it auto-loads alongside our extension via extension-installer), Rules 2 & 3 read real Doctrine `ClassMetadata` — works for **attribute / XML / YAML** mappings, and lets Rule 2 resolve the entity type returned by `getRepository()` / `find()`. When phpstan-doctrine is **absent**, Rule 3 falls back to a reflection scan of `#[ORM\Column]` attributes (attribute-mapping only) and Rule 2 resolves the entity only from a literal `::class` argument. Two code paths, but no forced dependency. Document "install `phpstan/phpstan-doctrine` for full coverage."
  - **Detection mechanism (for planner):** guard the phpstan-doctrine code path with a `class_exists`/service-availability check on the resolver — mirror the bundle's pervasive `class_exists`/`interface_exists` optional-dep convention.
  - **Rejected — hard-require phpstan-doctrine:** cleanest single code path and best Rule 2, but forces consumers to install AND configure phpstan-doctrine (an `objectManagerLoader`) for our rules to run at all, breaking the guarded-optional-dep pattern.
  - **Rejected — reflection/attribute-only (no coupling):** zero extra dependency, but silently blind to XML/YAML-mapped entities (Rule 3 gap) and the weakest Rule 2 (literal `::class` args only — no DQL/QueryBuilder/repository-return analysis).

### Rule 2 — cross-EM leak detection (the fragile rule)
- **D-03 (user choice — conservative / precision-first):** **Fire only when the rule can confidently see the tenant/default EM querying a `#[Shared]` entity; stay silent on an ambiguous `EntityManagerInterface`.** Rationale: a security rule that cries wolf gets toggled off and protects nobody; a precise one stays on. The runtime write-protection listener (Phase 25 D-02) + `tenancy:shared:resync` (Phase 26) remain the backstop for the cases Rule 2 conservatively skips.
  - **Recognized SAFE paths (no violation):** a query routed through the **named landlord EM** (the bundle wires a distinct `landlord` entity manager — landlord EM for the provider, default/tenant EM for app queries), OR an explicit `@phpstan-ignore` carrying the rule's error identifier `tenancy.sharedEntityLeak`.
  - **Acceptance-text note (for planner):** the acceptance's literal `setEntityManager('landlord')` method **does not exist** in the codebase and is illustrative only. The REAL static signal for "safe" is *which EM the query goes through* (named landlord EM vs tenant/default EM), not a fluent setter. Do not invent a `setEntityManager()` API to satisfy the literal wording.
  - **Rejected — tenant-default heuristic (fire on the autowired default EM):** catches the common real leak (people inject the default `EntityManagerInterface`, which IS the tenant EM under `database_per_tenant`) but false-positives in apps where the default EM is the landlord or that don't use `database_per_tenant`.
  - **Rejected — aggressive default-deny (flag every `#[Shared]` query unless proven-landlord or suppressed):** maximum leak coverage, but the highest false-positive rate; would lean entirely on the D-01 toggle + per-line suppression to stay tolerable, and a rule that's reflexively disabled provides zero value.

### Rule 3 — `tenant_id` config drift
- **D-04 (user choice — name + nullable + string-type):** **Fire when a `#[TenantAware]` entity (a) has no column mapped to `tenant_id`, OR (b) maps it nullable, OR (c) maps it to a non-string type.** The string-type check (beyond the two conditions the acceptance names verbatim) is a deliberate, natural strengthening of "config drift detection": `TenantAwareFilter` compares `"%s.tenant_id = '%s'"` against a **quoted string slug**, so an `int`/association `tenant_id` is a latent leak the missing-or-nullable checks alone would miss. **No length assertion** — the `VARCHAR(63)` in the attribute docblock is documentation, not an enforced hard limit, so asserting length ≥ 63 risks flagging valid columns (rejected).
  - **Column name is NOT configurable:** the filter hardcodes `tenant_id` (`src/Filter/TenantAwareFilter.php:47`), so Rule 3 validates that literal mapped **column name** (not the property name). Matching by property name or supporting a configurable name would let entities pass static analysis while still failing the runtime filter — exactly the drift this rule exists to catch.
  - Hierarchy awareness: like `SharedEntityMutualExclusionPass`, account for `#[TenantAware]` declared on a parent / `MappedSuperclass` (PHP class attributes are not inherited — walk ancestors). Same concern applies to Rule 1.

### Claude's Discretion
- Rule-class location/namespace (e.g., `src/PHPStan/Rule/…`), the shipped neon filename (`extension.neon` vs `rules.neon`), and how the shipped extension neon stays cleanly separate from the bundle's own self-analysis `phpstan.neon` (which analyses the bundle's `src` at level 9).
- Exact error-identifier strings — keep a consistent `tenancy.*` namespace (the Rule 2 suppression identifier `tenancy.sharedEntityLeak` is referenced in D-03; analogous identifiers for Rule 1 / Rule 3 are Claude's to name, e.g. `tenancy.mutualExclusion`, `tenancy.tenantIdDrift`).
- Whether each rule is one `Rule` class or shares helpers (e.g., a shared attribute-in-hierarchy detector and a shared `#[Shared]`/`#[TenantAware]` class resolver).
- `RuleTestCase` fixture design (violating + clean entities per rule) and whether the extension dogfoods on the bundle's own `src` / test fixtures in CI.
- `composer.json` require-dev additions needed to TEST the rules (`phpstan/extension-installer`, `phpstan/phpstan-doctrine`) vs `suggest` entries for consumers — planner to confirm against the existing require-dev block.
- The precise reflection-fallback mechanics for D-02 (reading `name` / `nullable` / `type` from `#[ORM\Column]` attribute arguments) vs the phpstan-doctrine `ClassMetadata` path.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Requirement + locked decisions
- `.planning/REQUIREMENTS.md` §DX-03 (lines 44–49) — full acceptance criteria: Rule 1 mutual exclusion, Rule 2 cross-EM `#[Shared]` leak, Rule 3 `tenant_id` missing/nullable; `phpstan/extension-installer` auto-load via `composer.json#extra.phpstan.includes` + documented opt-in snippet; clear file+line+violation-kind messages.
- `.planning/REQUIREMENTS.md` §"Architectural Decisions" — **DEC-PHPSTAN-01** (extension-installer distribution, "same pattern as `phpstan/phpstan-symfony` and `phpstan/phpstan-doctrine` — zero-config") and **DEC-SHARE-03** (mutual exclusion = compile-time error, the Phase 25 runtime half this rule complements).
- `.planning/ROADMAP.md` line 94 — Phase 28 scope line + "Tentative architectural defaults" (DEC-PHPSTAN-01).

### Direct dependencies — MUST read (this phase complements / mirrors their code)
- `.planning/phases/25-shared-entities-sync-mode/25-CONTEXT.md` — Phase 25 decisions. Especially **D-04** (the `SharedEntityMutualExclusionPass` boot-time guard Rule 1 sits on top of, and its explicit "Phase 28 PHPStan rule adds editor-time detection ON TOP" framing) and **D-06** (`#[Shared]` / `#[TenantAware]` are bare class-target markers — what the rules scan for).
- `.planning/phases/27-async-shared-entities/27-CONTEXT.md` — most-recent prior phase; confirms the shared-entity model the rules protect and the established "reuse internals, mirror precedent, guard optional deps" steer.

### Source files (read before implementing)
- `src/Attribute/TenantAware.php` + `src/Attribute/Shared.php` — the two bare marker attributes the rules detect (both `#[\Attribute(\Attribute::TARGET_CLASS)] final class`). TenantAware's docblock states the `tenant_id VARCHAR(63)` convention Rule 3 validates.
- `src/Filter/TenantAwareFilter.php` (line 47, `"%s.tenant_id = '%s'"`) — proof the scope column is hardcoded to `tenant_id` and compared as a quoted string (the basis for Rule 3's name + string-type checks).
- `src/DependencyInjection/Compiler/SharedEntityMutualExclusionPass.php` — the boot-time mutual-exclusion guard Rule 1 mirrors at edit-time; note its `hasAttributeInHierarchy()` ancestor-walk (PHP class attributes aren't inherited) — Rules 1 & 3 need the same hierarchy awareness, but WITHOUT the `tenancy.shared_entity` tag dependency.
- `composer.json` — `extra` block (currently only `branch-alias`; gains `extra.phpstan.includes`), the `require-dev` block (`phpstan/phpstan: ^2.1`, `nikic/php-parser: ^5.0` already present), and `suggest` block.
- `phpstan.neon` — the bundle's OWN level-9 self-analysis config (analyses `src`); keep the shipped consumer-facing extension neon separate from this.
- `.github/workflows/ci.yml` (~line 59 `phpstan:` job, line 72 `vendor/bin/phpstan analyse`) — where the bundle's self-analysis runs; consider how/whether rule tests + dogfooding slot in.

### External references (research targets — verify current API)
- `phpstan/phpstan-doctrine` — `ObjectMetadataResolver` API for obtaining `ClassMetadata` and resolving entity types of repository/`find()` calls (the D-02 "present" path). Referenced as the intended companion in `.planning/milestones/v0.2-research/STACK.md` lines 44–45.
- `phpstan/extension-installer` — `composer.json#extra.phpstan.includes` auto-registration mechanism (DEC-PHPSTAN-01). Referenced in `.planning/milestones/v0.2-research/STACK.md` line 45.
- PHPStan `RuleTestCase` (from `phpstan/phpstan`) — the standard rule-testing harness for the fixture-based tests.

No project-internal ADRs beyond the `.planning/` files above — requirements and prior-phase decisions are fully captured.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`SharedEntityMutualExclusionPass::hasAttributeInHierarchy()`** — the ancestor-walking attribute detector (PHP class attributes are not inherited; a `#[Shared]`/`#[TenantAware]` on a `MappedSuperclass` must be found by walking `getParentClass()`). Rules 1 & 3 need the same logic, but expressed against PHPStan's `ClassReflection` rather than native `\ReflectionClass`.
- **`src/Attribute/Shared.php` + `TenantAware.php`** — bare `TARGET_CLASS` markers with no constructor args; the rules match by attribute presence only.
- **`src/Filter/TenantAwareFilter.php`** — the hardcoded `tenant_id` quoted-string comparison that defines Rule 3's correctness target.

### Established Patterns
- **Optional-dependency guarding** — the whole bundle guards Doctrine/Messenger/Mailer/Flysystem wiring with `class_exists`/`interface_exists`. D-02's "use phpstan-doctrine when present, degrade when absent" is the same pattern applied to a dev-time tool.
- **ContractPass family** — `SharedEntityMutualExclusionPass`, `FilesystemContractPass`, `MailerTransportContractPass`, `CacheDecoratorContractPass`, `SharedAsyncContractPass` are the bundle's boot-time guards. The PHPStan rules are the **edit-time** complement to this family (Rule 1 ↔ `SharedEntityMutualExclusionPass` explicitly).
- **`tenant_id` is hardcoded, not configurable** — no config knob exists for the scope column; Rule 3 validates the literal `tenant_id` column name accordingly.

### Integration Points
- **New territory:** the bundle ships NO PHPStan extension today — `phpstan/phpstan: ^2.1` is require-dev for the bundle's OWN level-9 self-analysis only. Phase 28 adds a *consumer-facing* extension: rule classes (likely `src/PHPStan/…`), a shipped `extension.neon`, and an `extra.phpstan.includes` entry in `composer.json`.
- **`composer.json#extra`** currently holds only `branch-alias`; it gains `phpstan.includes`. require-dev likely gains `phpstan/extension-installer` and `phpstan/phpstan-doctrine` to test the rules (the bundle must run its own rules in CI). `nikic/php-parser: ^5.0` is already a production `require`.
- **Two PHPStan configs coexist:** the bundle's self-analysis `phpstan.neon` (analyses `src` at level 9) and the shipped consumer-facing extension neon. Keep them distinct; optionally dogfood the shipped rules on the bundle's own fixtures.
- **Rule 2 ↔ landlord/tenant EM split** — the static "safe vs leak" signal comes from the Phase 3 dual-EM wiring (named `landlord` EM vs default/tenant EM). Rule 2 must recognize the landlord EM as the safe path.

</code_context>

<specifics>
## Specific Ideas

No idiosyncratic "I want it like X" references. Across all four areas the user picked the **precision/consistency-preserving** option rather than the maximalist one: gate the noisy leak rule behind a toggle (D-01), keep phpstan-doctrine an optional integration to honor the guarded-optional-dep identity (D-02), make Rule 2 conservative so it stays enabled (D-03), and strengthen Rule 3 with a string-type check that targets the genuinely-insidious drift while declining the over-opinionated length assertion (D-04). The consistent steer: **a security rule only helps if it stays on — favor precision and bundle-consistency over catching every theoretical case**, with the runtime guards (Phase 25 write-protection, Phase 26 resync) as the backstop for what static analysis conservatively skips.

</specifics>

<deferred>
## Deferred Ideas

- **User-guide `phpstan-extension.md` page** — installation, the `phpstan.neon` snippet, each rule's purpose + an example violation/fix — Phase 29 (DOC-20). This phase ships inline PHPDoc + a deferred note only.
- **Configurable tenant-scope column name** — rejected outright (the filter hardcodes `tenant_id`); not deferred, structurally out of scope.
- **Aggressive / tenant-default Rule 2 modes** — rejected as D-03 alternatives; revisit only if conservative Rule 2 proves to miss real leaks operators care about (a future opt-in `strictness` parameter could layer on top of the existing toggle).
- **VARCHAR length assertion on `tenant_id`** — rejected in D-04 (63 isn't an enforced limit); revisit only if a concrete length-driven bug surfaces.

None of the above are scope creep into Phase 28 — discussion stayed within the DX-03 boundary.

</deferred>

---

*Phase: 28-phpstan-extension*
*Context gathered: 2026-06-16*
