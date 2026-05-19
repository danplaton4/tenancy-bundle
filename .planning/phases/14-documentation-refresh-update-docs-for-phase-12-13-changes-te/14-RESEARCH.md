# Phase 14: Documentation Refresh — Research

**Researched:** 2026-04-14
**Domain:** Technical documentation — Markdown/MkDocs site, README.md, config stubs
**Confidence:** HIGH

---

## Summary

Phase 14 is a targeted documentation update. No new code is produced — the deliverable is accurate text in existing files. The research task is therefore an audit: read every existing doc that touches the features changed in phases 12 and 13, compare them to the as-shipped source code, and record every stale or missing passage.

The audit found **seven stale passages across five files** and **one new page** that needs to be added. The changes are strictly editorial — rewrite stale paragraphs, add the `tenancy:init` reference, fix the `cache_prefix_separator` default, and update the DI-compilation architecture doc to reflect the resolver filtering logic and the EM targeting fix. The README and Flex config stub both carry the wrong default separator value.

No library research is needed. All source of truth is the codebase itself (already verified against the test suite at 255 tests, 0 failures in Phase 13).

**Primary recommendation:** One plan, three waves — (1) user-guide updates, (2) architecture doc update, (3) README + Flex stub update.

---

## Project Constraints (from CLAUDE.md)

- PHP 8.2+ with `declare(strict_types=1)` — not relevant for doc-only changes
- PHPStan level 9, php-cs-fixer, PHPUnit 11 — doc changes do not touch source; test suite must remain green (no source changes expected)
- `strict_mode` defaults to ON — documentation must reflect this correctly (already does)
- Doctrine dependencies are optional — guarded by `class_exists()`/`interface_exists()` — docs must not imply hard dependencies

---

<phase_requirements>
## Phase Requirements

This phase has no pre-assigned requirement IDs. The researcher identified the following concrete documentation requirements by auditing phases 12-13 deliverables against existing docs:

| ID | Description | Affected File(s) |
|----|-------------|-----------------|
| DOC-A | Add `tenancy:init` command page to CLI Commands doc and update nav | `docs/user-guide/cli-commands.md`, `mkdocs.yml` |
| DOC-B | Fix `cache_prefix_separator` default from `':'` to `'.'` everywhere it appears in documentation | `docs/user-guide/configuration.md`, `docs/user-guide/installation.md`, `README.md`, flex stub |
| DOC-C | Update installation "Without Flex" stub to use `'.'` separator default | `docs/user-guide/installation.md` |
| DOC-D | Update cache-isolation.md to reflect the separator config and correct namespace format | `docs/user-guide/cache-isolation.md` |
| DOC-E | Update resolvers.md to accurately state that the `tenancy.resolvers` config now actually filters the resolver chain | `docs/user-guide/resolvers.md` |
| DOC-F | Update `docs/architecture/di-compilation.md` — ResolverChainPass section is stale (shows old pass that never reads the config parameter) | `docs/architecture/di-compilation.md` |
| DOC-G | Update `docs/architecture/di-compilation.md` — loadExtension table is missing `TenantInitCommand` in always-registered services | `docs/architecture/di-compilation.md` |
| DOC-H | Update `docs/user-guide/database-per-tenant.md` — EntityManagerResetListener section is stale (says "all EMs", correct is "tenant EM only") | `docs/user-guide/database-per-tenant.md` |
</phase_requirements>

---

## Audit Findings — Stale Content

This section is the core deliverable. Each finding names the exact file, the stale claim, and the correct replacement.

### Finding 1: `cache_prefix_separator` default is wrong in five places

**Stale claim:** Default is `':'`
**Correct value:** `'.'` (changed in Phase 13 Task 2 — colon is a PSR-6 reserved character that `withSubNamespace()` rejects)
**Source:** `src/Cache/TenantAwareCacheAdapter.php` line 18: `private readonly string $cachePrefixSeparator = '.'` [VERIFIED]

**Files carrying the stale value:**

| File | Location | Stale text | Correct text |
|------|----------|-----------|-------------|
| `docs/user-guide/configuration.md` | `### tenancy.cache_prefix_separator` description + Full Example YAML/PHP blocks | `cache_prefix_separator: ':'` | `cache_prefix_separator: '.'` |
| `docs/user-guide/installation.md` | "Without Flex" full YAML example | `cache_prefix_separator: ':'` | `cache_prefix_separator: '.'` |
| `flex/danplaton4/tenancy-bundle/1.0/config/packages/tenancy.yaml` | Comment line `# cache_prefix_separator: ':'` | `':'` | `'.'` |
| `README.md` | Not present (README does not show the full config block — OK as-is) | N/A | N/A |

Additionally, the `configuration.md` description section says "a cache key `user.123` becomes `acme:user.123`" — this example uses the old colon separator and should be updated to `acme.user.123` (or reworded to avoid hardcoding the separator). [VERIFIED: `docs/user-guide/configuration.md` lines 72-76]

And `cache-isolation.md` shows an example of the namespace output:
```
Tenant 'acme':       cache key  →  app/acme:my_key
```
This uses a colon separator. After the fix the separator is `.` so the example should read `app/acme.my_key` or the example should note that the separator is configurable. [VERIFIED: `docs/user-guide/cache-isolation.md` lines 50-54]

### Finding 2: `docs/user-guide/cli-commands.md` — no `tenancy:init` command

**Stale claim:** The CLI Commands page opens with "The bundle provides two console commands" (`tenancy:migrate` and `tenancy:run`).
**Correct state:** Phase 12 added a third command: `tenancy:init`.
**Source:** `src/Command/TenantInitCommand.php` — registered unconditionally via `config/services.php` [VERIFIED]

**What the new section must document:**

- Command name: `tenancy:init`
- Purpose: Scaffolds `config/packages/tenancy.yaml` with fully commented defaults; detects Doctrine ORM and recommends driver
- Usage: `bin/console tenancy:init` / `bin/console tenancy:init --force`
- Behavior:
  - Creates `config/packages/tenancy.yaml` (creates the `config/packages/` directory if absent)
  - If file exists and `--force` is not passed: prints a warning and exits with failure
  - If `--force` is passed and file exists: overwrites with a note
  - Detects Doctrine via `interface_exists(EntityManagerInterface::class)` — prints recommended driver
  - Prints next-steps guidance after creation
- No dependencies: command is always registered, no optional packages required

**Output example (when Doctrine detected):**
```
 [OK] Created config/packages/tenancy.yaml

 Doctrine ORM detected — recommended driver: database_per_tenant
 Uncomment driver and set database.enabled: true in your config.

 Next Steps
 ----------
  * Review and uncomment the configuration values in config/packages/tenancy.yaml
  * Create your Tenant entity implementing Tenancy\Bundle\TenantInterface
  * Configure your host.app_domain if using subdomain-based resolution
  * Run bin/console doctrine:schema:update or create migrations for the Tenant entity
  * Visit https://github.com/danplaton4/tenancy-bundle for full documentation
```

### Finding 3: `docs/user-guide/resolvers.md` — resolver filtering note is misleading

**Stale claim (line 153-155):** "The `tenancy.resolvers` config key controls which HTTP resolvers are active." — This statement was correct as documentation intent but was not implemented until Phase 13. Now it is actually implemented. The current text is technically accurate post-Phase-13 but the note at line 153 says `console` in the resolvers list "has no effect — ConsoleResolver is registered unconditionally." This is still accurate and does not need changing.

**What does need updating:** The inline note at lines 183-185:
```
!!! note "ConsoleResolver is always active"
    Removing `console` from the `resolvers` list has no effect — `ConsoleResolver` is registered unconditionally as a `ConsoleCommandEvent` listener.
```

This note is **still correct** — the Phase 13 implementation confirms that `console` in the resolver config list only applies to the HTTP chain (ResolverChainPass filtering), while the ConsoleResolver's `ConsoleCommandEvent` listener fires regardless. [VERIFIED: `src/DependencyInjection/Compiler/ResolverChainPass.php` lines 62-67 — custom resolvers pass through; built-ins not in allowed list are skipped]

**What is missing:** The resolvers.md page does not mention that custom (user-registered) resolvers always pass through the filter — they are never blocked even if they are not listed in `tenancy.resolvers`. This should be documented to prevent confusion. [VERIFIED: `ResolverChainPass.php` lines 62-67 — the `in_array($fqcn, self::BUILT_IN_RESOLVER_MAP, true)` guard means only built-ins are filtered; others pass through]

### Finding 4: `docs/architecture/di-compilation.md` — ResolverChainPass section is stale

**Stale code block (lines 88-99):** Shows the old pass implementation that adds all tagged resolvers unconditionally without any config parameter reading. The actual Phase-13 implementation now reads the `tenancy.resolvers` parameter and filters built-in resolvers.

The stale block:
```php
public function process(ContainerBuilder $container): void
{
    $definition = $container->findDefinition(ResolverChain::class);
    $resolvers = $this->findAndSortTaggedServices('tenancy.resolver', $container);

    foreach ($resolvers as $resolver) {
        $definition->addMethodCall('addResolver', [$resolver]);
    }
}
```

**Correct implementation** (as shipped in `src/DependencyInjection/Compiler/ResolverChainPass.php`):
- Reads `tenancy.resolvers` parameter from the container
- Builds an allowed FQCN set from short names via `BUILT_IN_RESOLVER_MAP`
- Filters: built-in resolvers not in the allowed set are skipped; custom resolvers always pass through
- If the parameter is absent (no config), no filtering occurs (all resolvers added)

The architecture doc must be updated to show the current implementation. [VERIFIED: full source in `src/DependencyInjection/Compiler/ResolverChainPass.php`]

### Finding 5: `docs/architecture/di-compilation.md` — loadExtension table missing TenantInitCommand

**Stale claim:** The "Always Registered" services table (lines 225-234) lists TenantContextOrchestrator and EntityManagerResetListener but does not include `TenantInitCommand`.
**Correct state:** `tenancy.command.init` is registered unconditionally in `config/services.php` [VERIFIED: Phase 12 Summary confirms `tenancy.command.init` added to services.php unconditionally]

### Finding 6: `docs/user-guide/database-per-tenant.md` — EntityManagerResetListener stale description

**Stale claim (lines 204-210):**
```
`EntityManagerResetListener` listens for `TenantContextCleared` and calls `resetManager()` on the
registry. This clears the tenant EM's identity map, preventing entity objects from leaking between
requests.

!!! warning "Stale EM References"
    `resetManager()` is called on every tenant switch. Any `EntityManagerInterface` reference
    obtained before the switch may be invalid after.
```

This text says "resetManager() on the registry" without specifying which EM. Pre-Phase-13 the listener reset ALL registered EMs (including the landlord). Post-Phase-13 the listener only resets the configured target EMs.

**Correct behavior:**
- In `database_per_tenant` mode: only the `tenant` EM is reset (`resetManager('tenant')`)
- In `shared_db` / default mode: the default EM is reset (`resetManager(null)`)
- The landlord EM is **not** reset in database_per_tenant mode [VERIFIED: `EntityManagerResetListener.php` line 19 `private readonly array $managersToReset = [null]`; Phase 13 Summary decision: "EntityManagerResetListener.managersToReset defaults to [null] to call resetManager(null) — resets default EM in shared_db/single-EM mode; overridden to ['tenant'] in database_per_tenant via loadExtension"]

The warning note remains valid but should be scoped: it applies to the `tenant` EM only, not to the `landlord` EM.

### Finding 7: `docs/user-guide/installation.md` — tenancy:init not mentioned in the Without-Flex path

**Current state:** The "Without Flex" tab tells users to manually create `config/packages/tenancy.yaml`. Phase 12 added `tenancy:init` precisely for this use case — users without Flex can now run `bin/console tenancy:init` to generate the file.

**What to add:** After the "Without Flex" tab's manual-config block, add a tip admonition:
```
!!! tip "Or use tenancy:init"
    Instead of creating the config manually, run `bin/console tenancy:init` to
    generate a fully commented `config/packages/tenancy.yaml` with all keys and
    Doctrine-aware driver recommendations.
```

---

## Standard Stack for Documentation

This phase produces Markdown files consumed by MkDocs Material. No new libraries are installed.

### MkDocs Material Conventions (already in use)

| Pattern | Syntax | Example |
|---------|--------|---------|
| Admonitions | `!!! tip/note/warning/danger "Title"` | Used throughout existing docs |
| Tab groups | `=== "YAML"` / `=== "PHP"` | Used in configuration.md |
| Code blocks with source annotations | ` ```php ` with inline comments | Used in architecture docs |
| Internal cross-links | `[text](relative-path.md)` | Used throughout |

[VERIFIED: all patterns confirmed in existing `.md` files in `docs/`]

---

## Architecture Patterns

### Documentation Update Pattern for This Project

All documentation in this project follows an established pattern:

- **User Guide pages**: focused on "how to use"; use admonitions for warnings/tips; prefer YAML tab groups for config examples
- **Architecture Reference pages**: use code blocks showing simplified source; explain "why" not just "what"; use ASCII diagrams
- **Configuration reference**: table-per-key with Type/Default table, then description with examples

**The planner must not change this structure** — the task is surgical updates to existing pages, not restructuring.

### File Update Strategy

Each update is a targeted edit to specific lines within an existing file. The planner should generate one task per logical change group (not one task per file, not one task per line).

Suggested groupings:

| Task | Changes | Files |
|------|---------|-------|
| 1 | Add `tenancy:init` section to CLI Commands; update intro sentence; update nav | `cli-commands.md`, `mkdocs.yml` |
| 2 | Fix `cache_prefix_separator` default (`':'` → `'.'`) + update separator example | `configuration.md`, `cache-isolation.md`, `installation.md` |
| 3 | Update Flex stub separator comment | `flex/danplaton4/tenancy-bundle/1.0/config/packages/tenancy.yaml` |
| 4 | Update resolvers.md — add custom-resolver pass-through note | `resolvers.md` |
| 5 | Update `di-compilation.md` — fix ResolverChainPass code block, add TenantInitCommand to table | `di-compilation.md` |
| 6 | Update `database-per-tenant.md` — scope EntityManagerResetListener description | `database-per-tenant.md` |
| 7 | Update `installation.md` — add `tenancy:init` tip to Without-Flex path | `installation.md` |

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Validating updated separator value | Manual review | Read `src/Cache/TenantAwareCacheAdapter.php` line 18 | Single source of truth is the constructor default |
| Generating tenancy:init output example | Guess from plan | Read `src/Command/TenantInitCommand.php` `execute()` and `printNextSteps()` | Exact strings are in the source |
| Verifying ResolverChainPass behavior | Guess | Read `src/DependencyInjection/Compiler/ResolverChainPass.php` | Pass was implemented exactly as documented in Phase 13 summary |

---

## Common Pitfalls

### Pitfall 1: Changing `cache_prefix_separator` example but missing the format-string context

**What goes wrong:** The configuration.md section on `cache_prefix_separator` contains a prose example: "with the default separator and slug `acme`, a cache key `user.123` becomes `acme:user.123`." This must change to `acme.user.123`. But `cache-isolation.md` has a separate visual that shows `app/acme:my_key` — both must be updated consistently.

**Warning signs:** If the planner updates `configuration.md` but not `cache-isolation.md`, users will see contradictory examples.

### Pitfall 2: Adding `tenancy:init` to the CLI Commands nav and forgetting to update mkdocs.yml

**What goes wrong:** If `tenancy:init` is documented as a standalone section within `cli-commands.md` (not a new page), `mkdocs.yml` does not need changing. But if the planner adds a new page (e.g. `docs/user-guide/tenancy-init.md`), the nav in `mkdocs.yml` must be updated.

**How to avoid:** Keep `tenancy:init` as a new `##` section within the existing `cli-commands.md` — this is consistent with how `tenancy:migrate` and `tenancy:run` are currently co-located in the same file. No nav change required.

### Pitfall 3: Rewriting the ResolverChainPass code block with pseudocode instead of real code

**What goes wrong:** The architecture docs use simplified-but-real code ("simplified" means irrelevant lines omitted, but the shown logic is verbatim). Using pseudocode breaks the architecture docs' guarantee of accuracy.

**How to avoid:** Copy the actual logic from `src/DependencyInjection/Compiler/ResolverChainPass.php` and strip only the import block and inline comments. Add `// simplified` comment at the top.

### Pitfall 4: Omitting the `--force` flag from the `tenancy:init` documentation

**What goes wrong:** Without documenting `--force`, users who run `tenancy:init` a second time (e.g. to regenerate after config changes) will receive a failure with no clear path forward.

**Warning signs:** The command returns `Command::FAILURE` when the file exists and `--force` is absent — this is a user-visible behavior that must be documented.

---

## Code Examples

### Correct `tenancy:init` usage block (verified from `TenantInitCommand::configure()`)

```bash
# First-time setup — creates config/packages/tenancy.yaml
bin/console tenancy:init

# Regenerate (overwrite existing file)
bin/console tenancy:init --force
```

[VERIFIED: `src/Command/TenantInitCommand.php` lines 25-26]

### Correct `cache_prefix_separator` full config example

```yaml
# config/packages/tenancy.yaml
tenancy:
    driver: database_per_tenant
    strict_mode: true
    landlord_connection: default
    tenant_entity_class: Tenancy\Bundle\Entity\Tenant
    cache_prefix_separator: '.'
    database:
        enabled: false
    resolvers:
        - host
        - header
        - query_param
        - console
    host:
        app_domain: ~
```

[VERIFIED: `src/Cache/TenantAwareCacheAdapter.php` line 18 — default is `'.'`]

### Correct ResolverChainPass code block for architecture doc

```php
// src/DependencyInjection/Compiler/ResolverChainPass.php (simplified)
public function process(ContainerBuilder $container): void
{
    $definition = $container->findDefinition(ResolverChain::class);

    // Build allowed FQCN set from config short-names (e.g. 'host', 'header')
    $allowedFqcns = null;
    if ($container->hasParameter('tenancy.resolvers')) {
        $allowedFqcns = [];
        foreach ($container->getParameter('tenancy.resolvers') as $name) {
            if (isset(self::BUILT_IN_RESOLVER_MAP[$name])) {
                $allowedFqcns[] = self::BUILT_IN_RESOLVER_MAP[$name];
            }
        }
    }

    $resolvers = $this->findAndSortTaggedServices('tenancy.resolver', $container);

    foreach ($resolvers as $resolver) {
        $serviceId = (string) $resolver;
        if (null !== $allowedFqcns) {
            $fqcn = $container->findDefinition($serviceId)->getClass() ?? $serviceId;
            // Built-in resolvers must be in the allowed list
            if (in_array($fqcn, self::BUILT_IN_RESOLVER_MAP, true)
                && !in_array($fqcn, $allowedFqcns, true)) {
                continue; // Skip — not in config
            }
            // Custom resolvers (not in built-in map) always pass through
        }
        $definition->addMethodCall('addResolver', [$resolver]);
    }
}
```

[VERIFIED: full source in `src/DependencyInjection/Compiler/ResolverChainPass.php`]

### Correct EntityManagerResetListener description

In `database_per_tenant` mode: only the `tenant` EM is reset via `resetManager('tenant')`.
In `shared_db` / single-EM mode: the default EM is reset via `resetManager(null)`.
The `landlord` EM is never reset on `TenantContextCleared`.

[VERIFIED: `src/EventListener/EntityManagerResetListener.php` lines 19, 29-31; Phase 13 summary decision]

---

## Runtime State Inventory

Step 2.5 is not applicable. This is a documentation-only phase with no rename/refactor/migration — no runtime state changes.

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | `vendor/bin/phpunit` after doc changes | Yes | 8.4.12 | — |
| PHPUnit 11 | Test suite must stay green (no source changes, but validate) | Yes | 11.5.55 | — |

Documentation changes do not require MkDocs to be installed locally — the CI docs workflow handles rendering. If the planner wants to preview locally, `pip install mkdocs-material` is available, but it is not required for plan execution.

---

## Validation Architecture

`nyquist_validation` is enabled (config.json has no `false` override).

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5.55 |
| Config file | `phpunit.xml.dist` |
| Quick run command | `vendor/bin/phpunit --testsuite unit` |
| Full suite command | `vendor/bin/phpunit` |

### Phase Requirements to Test Map

This phase makes no source code changes. All tests should remain green without modification.

| Doc Req | Behavior to Verify | Test Type | Automated Command | Notes |
|---------|-------------------|-----------|-------------------|-------|
| DOC-B | `cache_prefix_separator` default is `'.'` in source | unit (already passing) | `vendor/bin/phpunit tests/Unit/Cache/TenantAwareCacheAdapterTest.php` | Tests were updated in Phase 13; verify still pass |
| DOC-E/F | ResolverChainPass filters by config | unit (already passing) | `vendor/bin/phpunit tests/Unit/DependencyInjection/Compiler/ResolverChainPassTest.php` | Tests were added in Phase 13 |
| All | No regressions from doc edits | full suite | `vendor/bin/phpunit` | Documentation files do not affect PHPUnit |

### Wave 0 Gaps

None — no new test files needed for a documentation-only phase.

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | The Flex recipe stub at `flex/danplaton4/tenancy-bundle/1.0/config/packages/tenancy.yaml` should be updated to match the new `'.'` separator default | Finding 1 | If the recipe is intentionally left out of scope (Packagist submission has its own process), the stub update is moot — but it costs nothing to update it |
| A2 | `tenancy:init` should be documented as a new section inside the existing `cli-commands.md` rather than a new standalone page | Finding 2 | If the team prefers a standalone page, the nav in `mkdocs.yml` also needs updating |

---

## Open Questions (RESOLVED)

1. **Should the Flex stub file be updated alongside the docs?**
   - What we know: The Flex recipe stub at `flex/danplaton4/tenancy-bundle/1.0/config/packages/tenancy.yaml` shows `# cache_prefix_separator: ':'` — the old default.
   - What's unclear: The Flex recipe submission process is separate from the bundle repo. Updating the file in the repo is safe (it is the source of truth for the recipe content), but it may or may not be deployed to the Flex server in this cycle.
   - Recommendation: Update the file in the repo to `'.'` — it is correct regardless of deployment timing.

2. **Does the `tenancy:init` command belong in the User Guide nav as a separate entry?**
   - What we know: `tenancy:migrate` and `tenancy:run` are both documented within a single `cli-commands.md` page using `##` headings.
   - Recommendation: Keep `tenancy:init` as a `##` section in the same file. Adding a new nav entry for a single command of this size would fragment the navigation unnecessarily.

---

## Sources

### Primary (HIGH confidence)

- `src/Command/TenantInitCommand.php` — exact command behavior, YAML template, next-steps output [VERIFIED]
- `src/Cache/TenantAwareCacheAdapter.php` line 18 — separator default is `'.'` [VERIFIED]
- `src/DependencyInjection/Compiler/ResolverChainPass.php` — current filtering logic [VERIFIED]
- `src/EventListener/EntityManagerResetListener.php` — `managersToReset` default and behavior [VERIFIED]
- `.planning/phases/12-developer-onboarding-tenancy-init-scaffolding-command-that-c/12-01-SUMMARY.md` — confirmed decisions [VERIFIED]
- `.planning/phases/13-audit-gap-closure/13-01-SUMMARY.md` — confirmed decisions [VERIFIED]
- All `docs/user-guide/*.md` and `docs/architecture/*.md` files — read in full [VERIFIED]

### Secondary (MEDIUM confidence)

- `.planning/phases/13-audit-gap-closure/13-RESEARCH.md` — separator semantics: "concatenated to tenant slug to form namespace token" — confirmed as correct by inspecting actual TenantAwareCacheAdapter source

---

## Metadata

**Confidence breakdown:**
- Stale content identification: HIGH — all files read in full; stale claims identified by direct comparison with source code
- Required new content: HIGH — `tenancy:init` behavior confirmed from source code
- Assumptions on scope: MEDIUM — two open questions remain (Flex stub update, standalone page vs. section)

**Research date:** 2026-04-14
**Valid until:** Indefinite — this is a documentation snapshot of a stable codebase (v1.0 released)
