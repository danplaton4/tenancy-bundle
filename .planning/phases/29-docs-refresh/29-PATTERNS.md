# Phase 29: Docs Refresh — Pattern Map

**Mapped:** 2026-06-18
**Files analyzed:** 6 (2 new, 4 existing modifications)
**Analogs found:** 6 / 6

---

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `docs/user-guide/shared-entities.md` | doc-page (comprehensive feature) | n/a | `docs/user-guide/filesystem-bootstrapper.md` | exact (same depth/shape, feature with command + exceptions + pitfalls) |
| `docs/user-guide/phpstan-extension.md` | doc-page (tooling install + rules) | n/a | `docs/user-guide/mailer-bootstrapper.md` + `docs/user-guide/shared-db.md` | role-match |
| `docs/user-guide/filesystem-bootstrapper.md` | doc-page (existing, drift fix) | n/a | self (line 205 only) | self-reference — single-line annotation |
| `scripts/docs-lint.sh` | CI shell script (extend) | n/a | self (existing `BUNDLES_VIOLATIONS` awk block, lines 73-89) | self-reference — structural twin |
| `mkdocs.yml` | build config (nav extension) | n/a | self (lines 68-84) | self-reference — two nav inserts |
| `UPGRADE.md` | release notes (section expansion) | n/a | self (lines 3-83) + `## 0.3.2 to 0.3.3` heading style | self-reference — expand existing section |

---

## Pattern Assignments

### `docs/user-guide/shared-entities.md` (NEW, comprehensive feature page)

**Primary analog:** `docs/user-guide/filesystem-bootstrapper.md`
**Secondary analogs:** `docs/user-guide/mailer-bootstrapper.md`, `docs/user-guide/shared-db.md`, `docs/user-guide/cli-commands.md`

#### H1 title pattern (filesystem-bootstrapper.md line 1)
```markdown
# Filesystem Bootstrapper (BOOT-03)
```
Mirror for new page:
```markdown
# Shared Entities (SHARE-01/02/03)
```
The pattern is: feature name in title case + requirement tag(s) in parentheses.

#### Opening paragraph pattern (filesystem-bootstrapper.md lines 3-8)
```markdown
Per-tenant filesystem scoping via [Flysystem](https://flysystem.thephpleague.com/). When a tenant
is resolved, every Flysystem service tagged `tenancy.scoped` automatically points at the active
tenant's storage — either as a sub-prefix on a shared adapter (prefix mode) or as a per-tenant
adapter instance (per-tenant-adapter mode). Untagged Flysystem services bypass scoping, so
landlord-side assets and shared resources remain accessible across the resolver chain.

This page covers what the bootstrapper does, how to configure both isolation strategies, the
configuration reference, exception handling, the path-traversal trust boundary, and a FAQ
section addressing common pitfalls.
```
Pattern: one-sentence feature headline, then a "what this page covers" sentence listing all sections. The new page follows the same pattern.

#### HR separator between major sections (filesystem-bootstrapper.md lines 14, 49, 66, 136, 213, 237, 279, 319, 425)
```markdown
---
```
Use `---` between every H2 block. This is consistent across filesystem-bootstrapper.md and mailer-bootstrapper.md.

#### Admonition block pattern (filesystem-bootstrapper.md lines 283-287)
```markdown
!!! warning "Application responsibility"
    The bundle treats **all path arguments passed to `$filesystem->write($path)`,
    `$filesystem->read($path)`, etc. as TRUSTED**.
```
Also from shared-db.md lines 36-39:
```markdown
!!! danger "Never combine `shared_db` with `database.enabled: true`"
    Setting both `driver: shared_db` AND `database.enabled: true` is rejected at compile time
    with a clear error.
```
And shared-db.md lines 125-129:
```markdown
!!! danger "Disable strict mode with caution"
    Setting `strict_mode: false` makes the filter return all rows when no tenant is active.
```
Pattern for landmine admonitions: `!!! warning` for operational landmines, `!!! danger` for security / data-loss risks. Use `!!! note` for informational callouts (shared-db.md lines 188-193).

#### PHP code block pattern (shared-db.md lines 48-77)
```markdown
```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Tenancy\Bundle\Attribute\TenantAware;

#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\Table(name: 'invoices')]
#[TenantAware]
class Invoice
{
    ...
}
```
```
Pattern: always `declare(strict_types=1)` + explicit `namespace App\Entity` in entity examples; use fully-qualified attribute imports.

#### Table pattern for configuration/options (filesystem-bootstrapper.md lines 219-224)
```markdown
| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `enabled` | `bool` | `false` | Enable the filesystem bootstrapper. |
```
Pattern for command options (cli-commands.md lines 31-35):
```markdown
| Flag | Effect |
|------|--------|
| `--dry-run` | Prints the proposed mutation without writing. |
| `--force` | Skip the confirmation prompt. |
```

#### Command usage block pattern (cli-commands.md lines 18-27)
```markdown
### Usage

```bash
# One-shot setup — registers the bundle + scaffolds config/packages/tenancy.yaml
bin/console tenancy:install

# Preview the mutation without writing anything
bin/console tenancy:install --dry-run
```
```

#### Command output example pattern (cli-commands.md lines 152-158)
```markdown
```
 ✓ acme
 ✓ demo
 ✗ broken-tenant (Connection refused: mysql:host=broken-host;dbname=broken_db)
Completed: 2 succeeded, 1 failed
```
```
Pattern: plain code block (no language tag) for terminal output. The `tenancy:shared:resync` output follows the same `✓ <slug>` / `✗ <slug> (<message>)` / `Completed: N succeeded, M failed` convention.

#### Exception documentation pattern (filesystem-bootstrapper.md lines 240-253)
```markdown
### `MissingFilesystemConfigException`

Thrown when `per_tenant_adapter` mode is active and the resolved tenant's
`filesystemConfig.adapter_dsn` is `null` or missing.

```php
Tenancy\Bundle\Exception\MissingFilesystemConfigException extends \LogicException
```

**Extends `\LogicException`, NOT `\RuntimeException`.** This is intentional ...
```
Pattern: H3 heading = backtick-wrapped class name; inline `php` block showing the class hierarchy; bold note on `LogicException` vs `RuntimeException` (no-retry semantics).

#### Pitfall FAQ pattern (filesystem-bootstrapper.md lines 321-333)
```markdown
### Pitfall 1: My `FilesystemOperator $defaultStorage` argument doesn't resolve

**Symptom:** `ServiceNotFoundException` or autowiring failure.

**Cause:** ...

**Fix:** ...
```
Pattern: H3 = `Pitfall N: <short description>`; three bold sub-labels (**Symptom**, **Cause**, **Fix**).

#### See also pattern (filesystem-bootstrapper.md lines 425-434)
```markdown
## See also

- [Configuration Reference](configuration.md) — `tenancy.filesystem.*` keys.
- [UPGRADE.md → 0.3 to 0.4](../../UPGRADE.md#03-to-04) — ...
- [Mailer Bootstrapper](mailer-bootstrapper.md) — ...
```
Pattern: bullet list of cross-links, each `[Link Text](target.md) — one-line description`. The `—` is an em-dash.

#### D-07 mandatory vocabulary placement
Both phrases must appear in the `## Overview` section verbatim:
- `landlord-side master`
- `tenant-side read-only copy`

The overview paragraph should also contain a disambiguation sentence pointing to `shared-db.md`, e.g.:
```markdown
> **Not the shared-DB driver.** `shared-entities.md` covers the `#[Shared]` attribute sync
> feature for database-per-tenant projects. For the `driver: shared_db` isolation strategy
> (one database, multiple tenants via SQL filter), see [Shared-DB Driver](shared-db.md).
```

#### Target section structure (from RESEARCH.md Architecture Patterns)
```
# Shared Entities (SHARE-01/02/03)
<opening paragraph + disambiguation + page roadmap>
---
## Overview
## Marking Entities as Shared
## Sync Model (Default: Synchronous)
## Async Mode
## tenancy:shared:resync Command
## Write Protection
## shared_db Driver Behavior
## See also
```

---

### `docs/user-guide/phpstan-extension.md` (NEW, tooling page)

**Primary analog:** `docs/user-guide/mailer-bootstrapper.md` (tooling install + conceptual explanation + edge cases)
**Secondary analog:** `docs/user-guide/shared-db.md` (tabbed config blocks + admonitions)

#### H1 title pattern (mirroring filesystem-bootstrapper.md line 1)
```markdown
# PHPStan Extension (DX-03)
```

#### Opening paragraph pattern (mailer-bootstrapper.md lines 1-5)
```markdown
Per-tenant SMTP transport with per-tenant `From` / `Reply-To` headers, correct under both
synchronous Mailer dispatch and Messenger-routed async dispatch.

This page covers the four things you need to know to operate the bootstrapper in production: ...
```
Mirror for new page: one sentence per rule group summary, then "This page covers: installation, three rules with examples, `checkSharedEntityLeaks` parameter."

#### Tabbed installation block pattern (shared-db.md lines 17-32)
```markdown
=== "YAML"

    ```yaml
    # config/packages/tenancy.yaml
    tenancy:
        driver: shared_db
    ```

=== "PHP"

    ```php
    ...
    ```
```
For phpstan-extension.md, use tabs for the three install paths (extension-installer / manual / with-doctrine), e.g.:
```markdown
=== "extension-installer (recommended)"

    ```bash
    composer require --dev phpstan/extension-installer
    ```
    Nothing else needed — installer reads `composer.json#extra.phpstan.includes` automatically.

=== "Manual"

    ```neon
    # phpstan.neon
    includes:
        - vendor/danplaton4/tenancy-bundle/extension.neon
    ```

=== "With phpstan-doctrine"

    ```neon
    # phpstan.neon
    includes:
        - vendor/danplaton4/tenancy-bundle/extension-doctrine.neon
        # Do NOT also include extension.neon — would double-register all three rules
    ```
```

#### Rule section pattern — violation + fix (from RESEARCH.md PHPStan Extension section)
Each rule should be an H2 section with:
1. One-sentence "what it catches" description
2. `violation` code block
3. `fix` code block (with comment label)
4. Any per-rule parameter note

```markdown
## Rule 1: Mutual Exclusion (`tenancy.mutualExclusion`)

Fires when a single class carries both `#[Shared]` and `#[TenantAware]` ...

```php
// VIOLATION
#[Shared]
#[TenantAware]
class Plan { ... }
// ERROR: Entity App\Entity\Plan cannot carry both #[Shared] and #[TenantAware].
```

```php
// FIX: pick one
#[Shared]
class Plan { ... }
```
```

#### Parameter toggle admonition (for `checkSharedEntityLeaks`)
```markdown
!!! note "Disabling Rule 2 project-wide"
    ```neon
    parameters:
        tenancy:
            checkSharedEntityLeaks: false
    ```
    Rules 1 and 3 fire unconditionally regardless of this setting.
```

#### Target section structure
```
# PHPStan Extension (DX-03)
<opening paragraph>
---
## Overview
## Installation
## Rule 1: Mutual Exclusion (tenancy.mutualExclusion)
## Rule 2: Shared Entity Leak (tenancy.sharedEntityLeak)
## Rule 3: Tenant ID Drift (tenancy.tenantIdDrift)
## See also
```

---

### `docs/user-guide/filesystem-bootstrapper.md` (EXISTING, drift fix only)

**Analog:** self — only line 205 changes.

#### Exact line 205 context (current state, lines 201-208)
```markdown
```php
?array{
    prefix?: string,          // override for prefix mode (defaults to 'tenant_{slug}/')
    adapter_dsn?: string,     // per_tenant_adapter DSN (e.g. 's3:///bucket?region=eu-central-1')
    services?: string[],      // limit scoping to these service IDs (empty = all tagged)
}
```
```

The `services?: string[]` comment must be replaced. Pattern to follow from the same file's admonition style for no-op/reserved items — inline comment is the right vehicle (stays within the code block, no prose shift). Replace the comment on line 205 with:
```php
    services?: string[],      // reserved — no-op in v0.4; setting this key has no effect in the current release
```

No other changes to the file body. Two additions to the existing `## See also` section (lines 425-434):
```markdown
- [Shared Entities](shared-entities.md) — `#[Shared]` attribute sync model for database-per-tenant projects.
- [PHPStan Extension](phpstan-extension.md) — static analysis rules that catch filesystem and tenancy misconfigurations.
```

---

### `scripts/docs-lint.sh` (EXISTING, D-04 block appended)

**Analog:** self — the existing `BUNDLES_VIOLATIONS` awk block (lines 73-89) is the structural twin.

#### `check()` helper signature (lines 22-33) — for reference, NOT for D-04
```bash
check() {
    local pattern="$1"
    local desc="$2"
    shift 2
    local targets=("$@")

    if grep -rnE --color=auto -- "$pattern" "${targets[@]}" 2>/dev/null; then
        echo ""
        echo "ERROR: $desc — remove these occurrences or justify via an inline comment."
        EXIT=1
    fi
}
```
The `check()` helper does a FLAT grep across all targets. It CANNOT implement D-04's per-file AND-logic. Do NOT use it for D-04.

#### `BUNDLES_VIOLATIONS` structural pattern (lines 73-89) — the model for D-04
```bash
BUNDLES_VIOLATIONS=$(awk '
    /^## / {
        section = $0
        sub(/^## /, "", section)
        in_whitelist = (section ~ /^(Migration|Upgrade|Manual setup|...)/)
        next
    }
    !in_whitelist { print FILENAME ":" FNR ":" $0 }
' $(find docs/ -name '*.md') | grep -E 'bundles\.php' || true)

if [ -n "$BUNDLES_VIOLATIONS" ]; then
    echo ""
    echo "ERROR: 'bundles.php' install-path reference found ..."
    echo "$BUNDLES_VIOLATIONS"
    EXIT=1
fi
```
Pattern: compute a violations string, leave it empty on no violations, then print + set `EXIT=1` only if non-empty.

#### D-04 block — exact implementation to append (after line 89, before line 91)
```bash
# D-04: fail when a docs/ file references "shared entity/entities" without BOTH
# canonical disambiguation phrases ("landlord-side master" AND "tenant-side read-only copy").
# Scoped to docs/ only — UPGRADE.md and CHANGELOG.md are NOT under docs/ and are exempt.
# Per-file AND-logic requires a loop; the flat check() helper cannot do this.

SHARED_ENTITY_VIOLATIONS=""
while IFS= read -r -d $'\0' f; do
    if grep -qiE 'shared entit(y|ies)' "$f"; then
        if ! grep -q 'landlord-side master' "$f" || ! grep -q 'tenant-side read-only copy' "$f"; then
            SHARED_ENTITY_VIOLATIONS="${SHARED_ENTITY_VIOLATIONS}${f}\n"
            EXIT=1
        fi
    fi
done < <(find docs/ -name '*.md' -print0)

if [ -n "$SHARED_ENTITY_VIOLATIONS" ]; then
    echo ""
    echo "ERROR: File(s) reference 'shared entity/entities' without BOTH disambiguation phrases"
    echo "       ('landlord-side master' AND 'tenant-side read-only copy'):"
    printf "%b" "$SHARED_ENTITY_VIOLATIONS"
fi
```

#### OK message line (line 92) — must stay at the end, unconditionally after all checks
```bash
if [[ $EXIT -eq 0 ]]; then
    echo "docs-lint: OK — no stale v0.1 terms in docs/ or tenancy:init command, and no bundles.php install-path regressions."
fi
```
The D-04 block is inserted BEFORE this line. The OK message remains the last thing in the script.

---

### `mkdocs.yml` (EXISTING, two nav inserts)

**Analog:** self — lines 68-84.

#### Current User Guide nav block (lines 68-84)
```yaml
  - User Guide:
    - user-guide/index.md
    - Installation: user-guide/installation.md
    - Getting Started: user-guide/getting-started.md
    - Configuration Reference: user-guide/configuration.md
    - Database-per-Tenant: user-guide/database-per-tenant.md
    - Shared-DB Driver: user-guide/shared-db.md
    - Resolvers: user-guide/resolvers.md
    - Origin Header Resolver: user-guide/origin-header-resolver.md
    - Cache Isolation: user-guide/cache-isolation.md
    - Mailer Bootstrapper: user-guide/mailer-bootstrapper.md
    - Filesystem Bootstrapper: user-guide/filesystem-bootstrapper.md
    - Messenger Integration: user-guide/messenger.md
    - CLI Commands: user-guide/cli-commands.md
    - Profiler Tab: user-guide/profiler-tab.md
    - Testing: user-guide/testing.md
    - Strict Mode: user-guide/strict-mode.md
```

#### Target state after D-06 inserts
```yaml
  - User Guide:
    - user-guide/index.md
    - Installation: user-guide/installation.md
    - Getting Started: user-guide/getting-started.md
    - Configuration Reference: user-guide/configuration.md
    - Database-per-Tenant: user-guide/database-per-tenant.md
    - Shared-DB Driver: user-guide/shared-db.md
    - Shared Entities: user-guide/shared-entities.md          # INSERT after shared-db.md
    - Resolvers: user-guide/resolvers.md
    - Origin Header Resolver: user-guide/origin-header-resolver.md
    - Cache Isolation: user-guide/cache-isolation.md
    - Mailer Bootstrapper: user-guide/mailer-bootstrapper.md
    - Filesystem Bootstrapper: user-guide/filesystem-bootstrapper.md
    - Messenger Integration: user-guide/messenger.md
    - CLI Commands: user-guide/cli-commands.md
    - Profiler Tab: user-guide/profiler-tab.md
    - Testing: user-guide/testing.md
    - Strict Mode: user-guide/strict-mode.md
    - PHPStan Extension: user-guide/phpstan-extension.md      # INSERT after strict-mode.md
```

Insert points are exact: `shared-entities.md` line inserted after the `Shared-DB Driver` line; `phpstan-extension.md` line appended as the last entry in the User Guide list.

---

### `UPGRADE.md` (EXISTING, expand `## 0.3 → 0.4` section)

**Analog:** self — the existing `## 0.3 → 0.4` section (lines 3-83) + `## 0.3.2 to 0.3.3` for heading style.

#### Existing section structure to modify (lines 3-83)
```
## 0.3 → 0.4
### Breaking changes
**None.** ...
### New: Filesystem Bootstrapper (BOOT-03)
...
#### Adoption path
...
#### No action required for prefix mode
...
#### No action required if filesystem isolation is not needed
...
> **Note:** This section will be expanded ... (Phase 29 / DOC-20).   ← REMOVE THIS LINE
---
```

#### H3 heading style (from existing entries, lines 5, 11)
```markdown
### Breaking changes
### New: Filesystem Bootstrapper (BOOT-03)
```
New H3 headings follow the same pattern: `### New: <Feature Name> (<Req-ID>)`.

#### Section to ADD — append after "No action required if filesystem isolation is not needed" block, replacing the placeholder Note
```markdown
### New: Shared Entities (SHARE-01/02/03)

v0.4 ships the `#[Shared]` attribute — a Doctrine entity sync model for database-per-tenant
projects. Entities marked `#[Shared]` are maintained on the landlord EntityManager and
automatically fanned out to every tenant database on `postFlush`.

This feature is fully opt-in. Projects that do not mark any entity `#[Shared]` see zero
behaviour change. No schema migration is required unless you adopt the feature.

For full details see [Shared Entities](docs/user-guide/shared-entities.md).

#### No action required

Adopting shared entities is opt-in:
- Mark a Doctrine entity with `#[Shared]` when you want it mirrored to all tenant databases.
- Run `bin/console tenancy:shared:resync` after the first migration to backfill existing tenants.
- No `TenantInterface` changes. No new config keys required (async mode is opt-in via
  `tenancy.shared.async: true`).

---

### New: PHPStan Extension (DX-03)

v0.4 ships three static analysis rules for Psalm/PHPStan Level 9 projects. The extension is
distributed via `phpstan/extension-installer` — zero manual config for most projects.

```bash
composer require --dev phpstan/extension-installer
```

If you already use `phpstan/phpstan-doctrine`, include `extension-doctrine.neon` **instead of**
`extension.neon` — not in addition to it.

For full details see [PHPStan Extension](docs/user-guide/phpstan-extension.md).

#### No action required

The extension only runs if explicitly installed. Existing projects see zero behaviour change.

---

### v0.4 milestone: no breaking changes

All four v0.4 capabilities (filesystem bootstrapper, shared entities, `tenancy:shared:resync`
command, PHPStan extension) are fully opt-in. `TenantInterface` is unchanged. Any project that
implemented `TenantInterface` in v0.3 runs without modification in v0.4.
```

#### Note line to REMOVE (line 82-83)
```markdown
> **Note:** This section will be expanded with troubleshooting tips and additional adoption
> notes in the v0.4 docs refresh (Phase 29 / DOC-20).
```
Delete this blockquote entirely when expanding the section.

---

## Shared Patterns

### MkDocs admonition extensions (available across all new pages)

Confirmed enabled in `mkdocs.yml` (lines 42-45):
```yaml
markdown_extensions:
  - admonition
  - pymdownx.details
  - pymdownx.superfences
```
All four admonition types are available: `!!! note`, `!!! tip`, `!!! warning`, `!!! danger`.
Use `!!! warning` for operational landmines (cascade depth, async transport routing requirement).
Use `!!! danger` for security/data-loss risks (write to tenant EM, strict-mode off).
Use `!!! note` for informational "by design" callouts.

### Tab blocks (available across all new pages)

Confirmed enabled in `mkdocs.yml` (lines 46-48):
```yaml
  - pymdownx.tabbed:
      alternate_style: true
```
Use `=== "Tab Label"` + 4-space indent for multi-path installation instructions.

### PHP code blocks

From `mkdocs.yml` (lines 50-57):
```yaml
  - pymdownx.highlight:
      ...
      extend_pygments_lang:
        - name: php
          lang: php
          options:
            startinline: true
```
`startinline: true` means PHP code blocks do NOT need the opening `<?php` tag to get syntax highlighting. However, full file examples should still include `<?php\n\ndeclare(strict_types=1);` to mirror the project convention (see `shared-db.md` lines 46-50 for the pattern used in entity examples).

### `declare(strict_types=1)` in PHP entity examples

All entity examples in existing pages use the full header:
```php
<?php

declare(strict_types=1);

namespace App\Entity;
```
New pages must follow the same convention for entity code blocks.

### Cross-link style

All cross-links use relative paths from the `docs/user-guide/` directory:
- Same directory: `[Page Title](filename.md)`
- Parent-level: `[UPGRADE.md](../../UPGRADE.md#anchor)`
- Section anchor: `[CLI Commands](cli-commands.md#tenancy-install)`

---

## No Analog Found

No files in scope lack a codebase analog. All six files have either an exact self-analog or a close role-match analog in the existing docs/tooling layer.

---

## Metadata

**Analog search scope:** `docs/user-guide/`, `scripts/`, `mkdocs.yml`, `UPGRADE.md`
**Files scanned:** 8 (filesystem-bootstrapper.md, mailer-bootstrapper.md, shared-db.md, cli-commands.md, docs-lint.sh, mkdocs.yml, UPGRADE.md, RESEARCH.md)
**Pattern extraction date:** 2026-06-18
