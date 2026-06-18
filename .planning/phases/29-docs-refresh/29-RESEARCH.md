# Phase 29: Docs Refresh — Research

**Researched:** 2026-06-18
**Domain:** Documentation accuracy + docs-tooling (PHP/Symfony bundle, Doctrine, PHPStan)
**Confidence:** HIGH — all key claims verified against live source files

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** `user-guide/filesystem-bootstrapper.md` → verify-only accuracy pass. Page exists (~433 lines). Do NOT rewrite a good page — only fix drift, add cross-links to new pages. If research confirms zero drift, only change is cross-links.
- **D-02:** `user-guide/shared-entities.md` → NEW, single comprehensive page. Covers `#[Shared]` attribute, sync vs async model, `tenancy:shared:resync` command, one-level cascade landmine, tenant-side write-protection. Establish canonical vocabulary (D-07) up front.
- **D-03:** `user-guide/phpstan-extension.md` → NEW. Covers install (`composer require --dev` + `phpstan/extension-installer`), `phpstan.neon` snippet, all three rules with example violation + fix, `checkSharedEntityLeaks` parameter (default-true, how to toggle).
- **D-04:** docs-lint.sh per-file disambiguation strictness. New check fails CI when a `docs/` file references "shared entit(y/ies)" but does NOT contain BOTH "landlord-side master" AND "tenant-side read-only copy" somewhere in that same file. Simplest rule; uses existing `check()` helper idiom.
- **D-05:** Always add a brief 0.3 → 0.4 section to UPGRADE.md. Even if no BC break, add a summary of what v0.4 adds and an explicit "no breaking changes" note.
- **D-06:** Insert both new pages into User Guide nav (`mkdocs.yml`). `shared-entities.md` adjacent to `shared-db.md`; `phpstan-extension.md` near `testing.md` / `strict-mode.md`. Exact order at Claude's discretion.
- **D-07:** Canonical vocabulary, locked. "landlord-side master" = authoritative `#[Shared]` record on landlord EM. "tenant-side read-only copy" = denormalized mirror fanned out to each tenant EM; write-protected via `SharedEntityWriteInTenantContextException`. The shared-entities page MUST contain both phrases verbatim so it passes D-04's lint check.

### Claude's Discretion

- Page depth/tone — mirror existing user-guide pages.
- `phpstan-extension.md` exact wording and example-violation code snippets.
- Whether to add a `tenancy:shared:resync` cross-link/stub into existing `cli-commands.md`.
- Exact nav ordering within the User Guide section (D-06).

### Deferred Ideas (OUT OF SCOPE)

None — discussion stayed within phase scope. Cross-tenant `#[Shared]` query patterns are explicitly out of scope per REQUIREMENTS.md Anti-Scope.

</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| DOC-20 | Documentation reflects everything v0.4 ships. New pages for Shared Entities sync model, PHPStan extension setup; verify filesystem-bootstrapper.md; UPGRADE.md 0.3→0.4 section; docs-lint.sh shared-entity disambiguation check. | All five deliverables researched; live code verified against planned APIs |

</phase_requirements>

---

## Summary

Phase 29 is a documentation + docs-tooling phase with fixed scope: five deliverables, no new runtime code. The research task is accuracy verification — does the existing `filesystem-bootstrapper.md` match what Phase 24 shipped? What exactly did Phases 25/26/27/28 ship? The answers below drive the planner's task granularity.

**Key finding — one confirmed drift** in `filesystem-bootstrapper.md`: the docs describe `services?: string[]` as a functional key in the `getFilesystemConfig()` return shape, but the live source (`TenantFilesystemConfigTrait` line 29) explicitly marks it `// NOT yet honored in v0.4 — reserved for future per-service scoping; setting this key is a no-op in the current release.` This one-line clarification must be added to the docs.

**All other filesystem-bootstrapper.md content is accurate.** Configuration keys, exception class names, mode names, priority order, decorator class names, and all seven pitfall descriptions match the live code exactly.

**All shared-entities and PHPStan APIs confirmed** against live source — the new pages will be new content, not corrections.

**Primary recommendation:** Planner should scope Wave 1 as the single drift fix to `filesystem-bootstrapper.md` + cross-links. All remaining deliverables are net-new content. The most complex task is the new `shared-entities.md` page (sync model + async model + command + landmines in one page). The `docs-lint.sh` extension is small but load-bearing for the CI gate.

---

## Architectural Responsibility Map

This is a docs/tooling phase — there is no runtime tier assignment. The single tooling artifact (`docs-lint.sh`) is a CI-layer shell script. All deliverables are documentation only.

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Accuracy verification + drift fix | docs layer | — | Static text comparison; no runtime path |
| New shared-entities page | docs layer | — | Describes runtime behavior; does not implement it |
| New phpstan-extension page | docs layer | — | Describes tool config; ships no new rules |
| UPGRADE.md 0.3→0.4 section | docs layer | — | Release notes; no runtime effect |
| docs-lint.sh D-04 check | CI layer | docs layer | Shell script that gates docs content in CI |
| mkdocs.yml nav update | build layer | docs layer | Build-time site generation only |

---

## Filesystem Bootstrapper Drift Audit (D-01)

### CONFIRMED DRIFT — `services?` key falsely described as functional

**File:** `docs/user-guide/filesystem-bootstrapper.md`, line 205

**Claimed in docs:**
```php
?array{
    prefix?: string,
    adapter_dsn?: string,
    services?: string[],      // limit scoping to these service IDs (empty = all tagged)
}
```

**Live source — `src/Filesystem/TenantFilesystemConfigTrait.php`, line 29:**
```php
// NOT yet honored in v0.4 — reserved for future per-service scoping;
// setting this key is a no-op in the current release.
```

**Verdict:** The `services?` key exists in the return shape type-hint but is a documented no-op in the implementation. The docs page presents it as functional ("limit scoping to these service IDs"). This is a user-misleading inaccuracy. The fix is a one-line note in the return-shape table/docblock on that page. [VERIFIED: live source read]

### Everything else in filesystem-bootstrapper.md: NO DRIFT

The following were verified against live code and are accurate:

| Docs claim | Source location | Verdict |
|------------|----------------|---------|
| `tenancy.filesystem.enabled` default `false` | `TenancyBundle.php` line 119 (`->booleanNode('enabled')->defaultFalse()`) | ACCURATE |
| `tenancy.filesystem.allow_per_tenant_adapter` default `true` | `TenancyBundle.php` line 120 | ACCURATE |
| `tenancy.filesystem.prefix_template` default `'tenant_{slug}/'` | `TenancyBundle.php` line 121 | ACCURATE |
| `tenancy.filesystem.cache_size` default `32` | `TenancyBundle.php` line 122 | ACCURATE |
| `MissingFilesystemConfigException extends \LogicException` | `src/Exception/MissingFilesystemConfigException.php` | ACCURATE |
| `UnsupportedAdapterDsnSchemeException extends \LogicException` | `src/Exception/UnsupportedAdapterDsnSchemeException.php` | ACCURATE |
| Decorator class names `FilesystemPrefixingDecorator`, `TenantAwareFilesystemDecorator` | `src/Filesystem/FilesystemPrefixingDecorator.php`, `TenantAwareFilesystemDecorator.php` | ACCURATE |
| `FilesystemBootstrapper::boot()` is a no-op | `src/Bootstrapper/FilesystemBootstrapper.php` lines 34-37 | ACCURATE |
| Priority -30 (after Mailer -20, after Doctrine -10) | `24-CONTEXT.md` DEC-FILE-PRIORITY (decision record) | ACCURATE |
| Trait is `Tenancy\Bundle\Filesystem\TenantFilesystemConfigTrait` | `src/Filesystem/TenantFilesystemConfigTrait.php` namespace | ACCURATE |
| Guard via `interface_exists(\League\Flysystem\FilesystemOperator::class)` | `TenancyBundle.php` build() line 383 | ACCURATE |
| `FilesystemContractPass` 3 compile-time guards | `src/DependencyInjection/Compiler/FilesystemContractPass.php` exists | ACCURATE |
| `ScopedStorageTaggingPass` TYPE_BEFORE_OPTIMIZATION priority 10 note (Pitfall 6) | `24-STATE.md` note | ACCURATE |
| 7 pitfalls correctly described | Cross-referenced against code patterns | ACCURATE |

**Cross-links needed (D-01, new pages not yet existing):**
- Add cross-link to `shared-entities.md` in the "See also" section.
- Add cross-link to `phpstan-extension.md` in the "See also" section.
- The existing "See also" already links to `UPGRADE.md#03-to-04` and `mailer-bootstrapper.md`.

---

## Shared Entities API — Verified Facts (for new shared-entities.md page)

### `#[Shared]` Attribute

- **Class:** `Tenancy\Bundle\Attribute\Shared` [VERIFIED: live source `src/Attribute/Shared.php`]
- **Declaration:** `#[\Attribute(\Attribute::TARGET_CLASS)] final class Shared {}` — zero-param bare TARGET_CLASS marker, exactly mirrors `TenantAware.php`
- **Namespace:** `Tenancy\Bundle\Attribute` [VERIFIED]
- **No constructor parameters.** [VERIFIED]

### Write Protection Exception

- **Class:** `Tenancy\Bundle\Exception\SharedEntityWriteInTenantContextException extends \LogicException` [VERIFIED: `src/Exception/SharedEntityWriteInTenantContextException.php`]
- **Static factory:** `SharedEntityWriteInTenantContextException::forEntity(string $entityClass, string $tenantSlug): self` [VERIFIED]
- **Extends `\LogicException`** (not `\RuntimeException`) — no-retry semantics under Messenger (WR-01 invariant) [VERIFIED]
- **Thrown by:** `SharedEntityWriteProtectionListener` on `onFlush` when scheduled insert/update/delete detected for `#[Shared]` entity in tenant context [VERIFIED: `src/Subscriber/SharedEntityWriteProtectionListener.php`]
- **Guards:** persist (insert), update (dirty managed copy), delete — full read-only enforcement (D-02) [VERIFIED: source docblock]

### Sync Subscriber

- **Class:** `Tenancy\Bundle\Subscriber\SharedEntitySyncSubscriber implements EventSubscriber` [VERIFIED: `src/Subscriber/SharedEntitySyncSubscriber.php`]
- **Listens on:** `Doctrine\ORM\Events::onFlush` (buffer changesets) AND `Doctrine\ORM\Events::postFlush` (apply or dispatch) [VERIFIED: source code]
- **Registered on:** landlord EM connection only via `doctrine.event_listener` tag `connection: landlord` (NOT autoconfigure, NOT `#[AsEventListener]`) [VERIFIED: `TenancyBundle.php` lines 303-304]
- **Fan-out source:** `TenantProviderInterface::findAll()` — enumerates all active tenants [VERIFIED]
- **Failure mode:** best-effort; per-tenant failures are caught+logged, never abort the landlord transaction (D-01) [VERIFIED: source docblock]
- **Change types covered:** insert / update / delete (D-05) [VERIFIED: source docblock]
- **`shared_db` short-circuit:** subscriber returns immediately when `tenancy.driver === 'shared_db'`, no fan-out (D-03) [VERIFIED: source docblock]

### One-Level Cascade Boundary

- **Mechanism:** `SharedEntityCopier::applyRow()` copies `getFieldNames()` (scalar fields) ONLY; `getAssociationNames()` are intentionally skipped [VERIFIED: `src/Subscriber/SharedEntitySyncSubscriber.php` class docblock lines 48-54]
- **Documented landmine:** if a `#[Shared]` entity carries an association to a non-`#[Shared]` entity, the association is NULL on the tenant side [VERIFIED]
- **Fix:** design shared entities to be self-contained (scalar fields only), OR ensure associated entities also carry `#[Shared]`

### Async Mode (SHARE-03)

- **Config key:** `tenancy.shared.async: true` (default `false`) [VERIFIED: `TenancyBundle.php` line 128 `->booleanNode('async')->defaultFalse()`]
- **Container parameter:** `tenancy.shared.async` (bool) [VERIFIED: `TenancyBundle.php` line 199]
- **Message class:** `Tenancy\Bundle\Message\SharedEntityChangedMessage` [VERIFIED: `src/Message/SharedEntityChangedMessage.php`]
  - Properties: `string $entityClass`, `array $identifier`, `string $changeType` (one of `'insert'|'update'|'delete'`)
  - Never carries the full entity payload — scalars only
- **Handler:** `Tenancy\Bundle\MessageHandler\SharedEntityChangedMessageHandler` [VERIFIED: file exists]
- **Fan-out topology:** one message per changed entity; handler does the per-tenant loop (NOT one message per `entity × tenant`) [VERIFIED: 27-CONTEXT.md D-01]
- **State at handle time:** handler re-fetches LATEST landlord state; tenants see latest state, not dispatch-time state — documented landmine (D-05)
- **Vanished row at handle time:** treated as tenant-side delete (D-04)
- **Transport routing:** user must configure `framework.messenger.routing` themselves — bundle does NOT auto-route; if not routed async, message handles synchronously inline (D-03)
- **Re-entrancy:** write-protection listener consulted; handler uses `SharedEntityCopier` which owns the `syncInProgress` flag [VERIFIED: `SharedEntityCopierInterface::isSyncInProgress()`]
- **Compile-time guard:** `SharedAsyncContractPass` — fails when `tenancy.shared.async: true` + Messenger absent [VERIFIED: `src/DependencyInjection/Compiler/SharedAsyncContractPass.php`]

### `SharedEntityCopierInterface` contract

- **Interface:** `Tenancy\Bundle\Shared\SharedEntityCopierInterface` [VERIFIED: `src/Shared/SharedEntityCopierInterface.php`]
- `applyRow(EntityManagerInterface $landlordEm, EntityManagerInterface $tenantEm, object $entity, string $type = 'insert', ?array $capturedIds = null): void`
- `classifyRow(EntityManagerInterface $landlordEm, EntityManagerInterface $tenantEm, object $entity): string` — returns `'insert'|'update'|'in-sync'`
- `findSharedClasses(EntityManagerInterface $landlordEm): array` — returns `list<class-string>`
- `isShared(object $entity, EntityManagerInterface $em): bool`
- `deleteRow(EntityManagerInterface $tenantEm, string $class, array $capturedIds): void` — idempotent delete; sets `syncInProgress` flag
- `isSyncInProgress(): bool`

---

## `tenancy:shared:resync` Command — Verified Facts

- **Command name:** `tenancy:shared:resync` [VERIFIED: `src/Command/SharedEntityResyncCommand.php` line 21]
- **Class:** `Tenancy\Bundle\Command\SharedEntityResyncCommand` [VERIFIED]
- **Options:**
  - `--tenant` (`VALUE_OPTIONAL`) — sync a single tenant by slug; absent = all tenants [VERIFIED: lines 38-44]
  - `--dry-run` (`VALUE_NONE`) — classify drift without writing [VERIFIED: lines 45-49]
  - `--force` (`VALUE_NONE`) — skip the confirmation prompt for CI/non-interactive use [VERIFIED: lines 50-55]
- **NO `--all` flag** — absent `--tenant` means all tenants (mirrors `tenancy:migrate`) [VERIFIED: source code]
- **`shared_db` behavior:** prints informational message and returns `Command::SUCCESS` (not FAILURE) [VERIFIED: lines 65-69]
- **Drift classification:** `would-insert` / `would-update` / `in-sync` per tenant per entity (D-03 real classification) [VERIFIED: execute() classify pass]
- **Table output columns:** Tenant, Would-Insert, Would-Update, In-Sync, Status [VERIFIED: line 162]
- **Confirmation prompt:** `'Proceed with live resync?'` with default `false` (No); `--force` skips [VERIFIED: lines 170-172]
- **Apply output:** `✓ <slug>` per success, `✗ <slug> (<message>)` per failure [VERIFIED: lines 197/202]
- **Summary line:** `Completed: N succeeded, M failed` [VERIFIED: line 212]
- **Exit codes:** `Command::SUCCESS` on full success or no tenants; `Command::FAILURE` if any tenant failed (including classify errors) [VERIFIED]
- **Idempotent:** find-or-new (NOT Doctrine `merge()`, which was removed in ORM 3.0) [VERIFIED: 26-CONTEXT.md D-02]
- **Class enumeration:** via `SharedEntityCopierInterface::findSharedClasses(landlordEm)` which walks `ClassMetadataFactory::getAllMetadata()` [VERIFIED: source line 94]

---

## PHPStan Extension — Verified Facts (for new phpstan-extension.md page)

### Rule identifiers (confirmed from live source)

| Rule | Error Identifier | Source File |
|------|-----------------|-------------|
| Mutual Exclusion (`#[Shared]` + `#[TenantAware]` on same class) | `tenancy.mutualExclusion` | `src/PHPStan/Rule/MutualExclusionRule.php` line 58 |
| Shared Entity Leak (query `#[Shared]` via concrete tenant `EntityManager`) | `tenancy.sharedEntityLeak` | `src/PHPStan/Rule/SharedEntityLeakRule.php` line 136 |
| Tenant ID Drift (`#[TenantAware]` missing/nullable/non-string `tenant_id`) | `tenancy.tenantIdDrift` | `src/PHPStan/Rule/TenantIdDriftRule.php` lines 261/275/291 |

All three confirmed [VERIFIED: live source].

### `checkSharedEntityLeaks` parameter

- **Default:** `true` [VERIFIED: `extension.neon` line 11 + `extension-doctrine.neon` line 36]
- **Scope:** gates Rule 2 (`tenancy.sharedEntityLeak`) only; Rules 1 and 3 fire unconditionally [VERIFIED: `SharedEntityLeakRule.php` line 66]
- **Set in phpstan.neon** to silence Rule 2 for a project:
  ```neon
  parameters:
      tenancy:
          checkSharedEntityLeaks: false
  ```

### Extension files

- **Primary extension:** `extension.neon` — auto-loaded by `phpstan/extension-installer` (base path, no phpstan-doctrine) [VERIFIED: root of repo]
- **Doctrine-aware fragment:** `extension-doctrine.neon` — injects `ObjectMetadataResolver` for Rule 3's metadata path when `phpstan/phpstan-doctrine` is installed; must be loaded INSTEAD OF (not in addition to) `extension.neon` [VERIFIED: `extension-doctrine.neon` header comment]
- **composer.json registration:** `extra.phpstan.includes: ["extension.neon"]` [VERIFIED: `composer.json` lines 80-84]
- **`phpstan/extension-installer`** allowed plugin: `"phpstan/extension-installer": true` in `composer.json` config [VERIFIED]

### Installation paths

**Path A — with `phpstan/extension-installer` (recommended, zero-config):**
```bash
composer require --dev phpstan/extension-installer
```
`extension-installer` reads `composer.json#extra.phpstan.includes` and auto-includes `extension.neon`. All three rules run on `phpstan analyse`.

**Path B — manual includes snippet:**
```neon
# phpstan.neon
includes:
    - vendor/danplaton4/tenancy-bundle/extension.neon
```

**Path C — with phpstan/phpstan-doctrine for full metadata coverage:**
```neon
# phpstan.neon
includes:
    - vendor/danplaton4/tenancy-bundle/extension-doctrine.neon
    # Do NOT also include extension.neon — would double-register all three rules
```

### Rule violation examples (derived from rule logic)

**Rule 1 — `tenancy.mutualExclusion`:**
```php
// VIOLATION: both attributes on the same class
use Tenancy\Bundle\Attribute\Shared;
use Tenancy\Bundle\Attribute\TenantAware;

#[Shared]
#[TenantAware]
class Plan { ... }
// ERROR: Entity App\Entity\Plan cannot carry both #[Shared] and #[TenantAware].
//        A shared entity is a landlord-side master; a TenantAware entity is tenant-scoped. Pick one.
```
```php
// FIX: pick one
#[Shared]
class Plan { ... }   // landlord-side master — synced to all tenants
```

**Rule 2 — `tenancy.sharedEntityLeak`:**
```php
// VIOLATION: concrete EntityManager (not interface) querying a #[Shared] entity
use Doctrine\ORM\EntityManager;
use App\Entity\Plan;

class PlanService {
    public function __construct(private EntityManager $em) {}

    public function find(int $id): ?Plan {
        return $this->em->find(Plan::class, $id);
        // ERROR: Entity App\Entity\Plan is #[Shared]. Querying it through the tenant
        //        EntityManager risks a cross-tenant data leak. Route the query through
        //        the named landlord EntityManager.
    }
}
```
```php
// FIX A: inject the named landlord EM
use Doctrine\ORM\EntityManagerInterface;

class PlanService {
    public function __construct(
        #[\Symfony\Bridge\Doctrine\Attribute\MapEntity(entityManagerName: 'landlord')]
        private EntityManagerInterface $landlordEm,
    ) {}

    public function find(int $id): ?Plan {
        return $this->landlordEm->find(Plan::class, $id);
    }
}
```
```php
// FIX B: suppress per-site when intentional
/** @phpstan-ignore tenancy.sharedEntityLeak */
return $this->em->find(Plan::class, $id);
```
Note: Rule 2 fires ONLY when the caller type is the concrete `Doctrine\ORM\EntityManager` class (NOT the `EntityManagerInterface`). Interface-typed callers are silent (conservative D-03).

**Rule 3 — `tenancy.tenantIdDrift`:**
```php
// VIOLATION A: missing tenant_id column
#[ORM\Entity]
#[TenantAware]
class Invoice {
    #[ORM\Column]
    private int $amount;
    // ERROR: Class App\Entity\Invoice is #[TenantAware] but has no column mapped to tenant_id.
}
```
```php
// VIOLATION B: tenant_id is nullable
#[TenantAware]
class Invoice {
    #[ORM\Column(length: 63, nullable: true)]
    private ?string $tenantId;
    // ERROR: nullable tenant_id prevents scoping.
}
```
```php
// VIOLATION C: non-string type
#[TenantAware]
class Invoice {
    #[ORM\Column(type: 'integer')]
    private int $tenantId;
    // ERROR: non-string type incompatible with TenantAwareFilter quoted-string comparison.
}
```
```php
// FIX: non-nullable string column
#[ORM\Column(length: 63)]
private string $tenantId;
```
Accepted types: `string`, `ascii_string`, `guid`, `uuid` (case-insensitive). [VERIFIED: `TenantIdDriftRule.php` line 32]

---

## `scripts/docs-lint.sh` — Verified State

**Read from:** `scripts/docs-lint.sh` [VERIFIED: live source]

### Existing checks

1. `check 'wrapperClass'` — v0.1 DBAL stale term
2. `check 'wrapper_class'` — v0.1 YAML form
3. `check 'ReflectionProperty'` — v0.1 hack
4. `check 'TenantConnection'` — class deleted in v0.2
5. `check 'sqlite://'` — old URL form
6. **`BUNDLES_VIOLATIONS` awk block** — `bundles.php` install-path references outside whitelisted H2 sections (Migration / Upgrade / Manual setup / Troubleshooting / Do I have to do anything? / tenancy:install)

### `check()` helper signature

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

**Idiom:** `check <grep-pattern> <description> "${TARGETS[@]}"` — uses `grep -rnE` and sets `EXIT=1` on match. Simple, no per-occurrence context needed.

### Scoping

- **Targets:** `docs/` dir + `src/Command/TenantInitCommand.php`
- **Excluded from scanning:** `CHANGELOG.md`, `UPGRADE.md` (intentionally contain stale terms in migration recipes). This exclusion is structural — `TARGETS=()` only contains `docs/` and the command file.

### D-04 new check design

The new check must: fail when a `docs/` file references "shared entit(y/ies)" but does NOT contain BOTH "landlord-side master" AND "tenant-side read-only copy" somewhere in the same file.

**The right implementation approach** (fitting the existing script style) is NOT a simple `check()` call (which does a flat grep across all targets). It requires a per-file loop. The correct pattern:

```bash
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

Key points:
- Scoped to `docs/` only (UPGRADE.md and CHANGELOG.md are automatically excluded because they're not under `docs/`)
- Per-file logic: grep for the pattern FIRST, then check for both disambiguators
- Case-insensitive pattern (`grep -qi`) to catch "Shared Entity" and "shared entities"
- `EXIT=1` set inline (same as the awk block style)
- Does NOT use the `check()` helper (which does single grep across all targets, cannot do per-file AND logic)
- Follows the existing `BUNDLES_VIOLATIONS` awk block style — compute violations string, then print and set EXIT=1 if non-empty

### CI integration

The script is run from the repo root (`scripts/docs-lint.sh`). It is called by CI (`.github/workflows/ci.yml`). No structural changes needed — the new block appends after existing checks. [VERIFIED: script structure]

---

## `mkdocs.yml` — Verified Nav State

**Current User Guide nav (lines 67-84 of `mkdocs.yml`):**
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

**D-06 insertion points:**
- `shared-entities.md` → insert AFTER `Shared-DB Driver: user-guide/shared-db.md` (adjacent, related storage topics)
- `phpstan-extension.md` → insert AFTER `Strict Mode: user-guide/strict-mode.md` (tooling/quality grouping at end of User Guide)

**Recommended final ordering in User Guide (Claude's discretion under D-06):**
```yaml
    - Shared-DB Driver: user-guide/shared-db.md
    - Shared Entities: user-guide/shared-entities.md          # NEW — insert here
    - Resolvers: user-guide/resolvers.md
    ...
    - Strict Mode: user-guide/strict-mode.md
    - PHPStan Extension: user-guide/phpstan-extension.md      # NEW — insert here
```

---

## `UPGRADE.md` — Verified State

**Current structure:**
- `## 0.3 → 0.4` — exists (added during Phase 24); covers only the Filesystem Bootstrapper; ends with a note "This section will be expanded with troubleshooting tips and additional adoption notes in the v0.4 docs refresh (Phase 29 / DOC-20)."
- `## 0.3.2 to 0.3.3`
- `## 0.3.1 to 0.3.2`
- `## 0.2 to 0.3`
- `## Upgrading to 0.1`

**D-05 task:** The `## 0.3 → 0.4` section already exists and has content. The task is to EXPAND it (not create it). Add subsections for:
1. Shared Entities (SHARE-01/02/03) — no BC break, opt-in
2. PHPStan Extension (DX-03) — no BC break, opt-in via composer require --dev
3. Remove the "will be expanded in Phase 29" placeholder note
4. Add explicit "no breaking changes" statement for the full v0.4 milestone

**BC break verification:** [VERIFIED: `UPGRADE.md` line 7] "None. `TenantInterface` is unchanged (DEC-FILE-CONFIG locked)." The new shared-entity features are opt-in (tag `#[Shared]`, enable `database_per_tenant`); the PHPStan extension is require-dev opt-in. No BC breaks in v0.4. [VERIFIED: all phase CONTEXT.md files]

---

## Style Baseline for New Pages

Measured from existing pages [VERIFIED by reading]:

| Page | Lines | Shape |
|------|-------|-------|
| `mailer-bootstrapper.md` | ~140 | H1 (feature name), overview prose, config section, exceptions/fallback notes, See also |
| `shared-db.md` | ~210 | H1, Overview bullets, Configuration tabs, entity marking, how filter works, strict mode, mixed entities, inheritance, See also |
| `cli-commands.md` | ~240 | H1, then H2 per command (name, usage code, flags table, behavior, idempotency, error cases) |
| `filesystem-bootstrapper.md` | ~433 | H1 (with requirement tag), hr-separated sections, config table, exception class names, trust boundary warning, 7 pitfalls, See also |

**Target length guidance (Claude's discretion):**
- `shared-entities.md`: 300-380 lines (comprehensive — covers sync + async + command + two landmines + write-protection)
- `phpstan-extension.md`: 200-280 lines (covers install + 3 rules with examples)

**Page shape to mirror:**
- H1 with feature tag, e.g. `# Shared Entities (SHARE-01/02/03)`
- Brief overview / motivation paragraph
- Table of contents via MkDocs auto-TOC (no manual TOC needed with `toc: follow` in mkdocs.yml)
- H2 sections: Overview, Marking Entities, Sync Model, Async Mode (or separate H3s), `tenancy:shared:resync`, Cascade Landmine, Write Protection, See Also
- Admonition blocks (`!!! warning`) for landmines and security notes (MkDocs Material `admonition` extension is enabled)
- Code blocks with `php` language tag

---

## Package Legitimacy Audit

This phase installs NO new packages. All deliverables are documentation files, a shell script edit, and a YAML nav edit. No `npm install`, `pip install`, or `composer require` (other than the documented install paths in the docs content itself).

**Packages removed due to slopcheck [SLOP] verdict:** none — no packages to check.

---

## Common Pitfalls

### Pitfall 1: Confusing `shared-entities.md` with `shared-db.md`

**What goes wrong:** A reader or writer confuses the two pages. `shared-db.md` covers the shared-database isolation DRIVER (`driver: shared_db`). `shared-entities.md` covers the `#[Shared]` attribute sync feature (for database-per-tenant driver). They are different features.

**How to avoid:** The new `shared-entities.md` page MUST include a brief disambiguation note early (e.g. in the overview) pointing to `shared-db.md` and clarifying the distinction. The existing `shared-db.md` already has a `#[Shared]` section (lines 174-208) describing the `shared_db` no-op behavior — that section already exists and is accurate; the new page should cross-link to it.

### Pitfall 2: docs-lint.sh D-04 check false-negative on UPGRADE.md

**What goes wrong:** The new shared-entities content in UPGRADE.md might use "shared entity" without the canonical disambiguators — but UPGRADE.md is NOT scanned by docs-lint.sh (by design, same as CHANGELOG.md). This is intentional per D-05 of 29-CONTEXT.md ("docs-lint.sh does NOT scan UPGRADE.md, so migration-recipe terms there are exempt from the new check too").

**How to avoid:** The planner must verify the D-04 check targets `docs/` only (which it will, by using `find docs/`). The UPGRADE.md shared-entities section does NOT need to contain the canonical disambiguators.

### Pitfall 3: Double-registration of PHPStan rules

**What goes wrong:** The docs instruct a user to include BOTH `extension.neon` AND `extension-doctrine.neon`. Both files register all three rules. PHPStan does not deduplicate tagged services, so every error fires twice.

**How to avoid:** The `phpstan-extension.md` page MUST clearly state: include `extension-doctrine.neon` INSTEAD OF `extension.neon` — not in addition to it. This is spelled out in `extension-doctrine.neon`'s header comment. [VERIFIED]

### Pitfall 4: `services?` key in `getFilesystemConfig()` return shape

**What goes wrong:** The docs describe `services?: string[]` as functional. It is a no-op in v0.4 (see Drift Audit above). If left uncorrected, users will waste time setting this key.

**How to avoid:** The filesystem-bootstrapper.md drift fix must add a "Reserved for future use — no-op in v0.4" annotation to the `services?` key documentation.

### Pitfall 5: `tenancy:shared:resync` command not mentioned in cli-commands.md

**What goes wrong:** `cli-commands.md` documents four commands (`tenancy:install`, `tenancy:init`, `tenancy:migrate`, `tenancy:run`). The `tenancy:shared:resync` command is a fifth command that now exists. Readers checking the CLI commands page won't know it exists.

**How to avoid:** Per 29-CONTEXT.md Claude's discretion, add a brief stub or cross-link in `cli-commands.md` pointing to the comprehensive `tenancy:shared:resync` docs on `shared-entities.md`. A one-paragraph stub with a "see shared-entities.md for full docs" cross-link is sufficient. [ASSUMED — not locked by CONTEXT.md, Claude's discretion]

### Pitfall 6: docs-lint.sh async transport landmine not covered

**What goes wrong:** The D-03 transport routing decision (user must configure `framework.messenger.routing`) is a well-documented "gotcha" — if not routed async, `tenancy.shared.async: true` produces no error but messages execute synchronously. Not documenting this in the new `shared-entities.md` page would mislead users who expect `async: true` to "just work" without Messenger transport config.

**How to avoid:** The `shared-entities.md` page MUST include a `!!! warning` admonition on the async transport routing requirement. [VERIFIED from 27-CONTEXT.md D-03]

---

## Architecture Patterns

### Recommended New Page: `shared-entities.md` Section Structure

```
# Shared Entities (SHARE-01/02/03)

## Overview
- What the feature does (landlord master → tenant read-only copies)
- Distinction from shared-db.md (different feature)
- Vocabulary: "landlord-side master" and "tenant-side read-only copy" (D-07 — both phrases MUST appear for D-04 lint check)

## Marking Entities as Shared
- `#[Shared]` attribute (zero-param, TARGET_CLASS)
- Mutual exclusion with `#[TenantAware]`

## Sync Model (Default: Synchronous)
- How postFlush fan-out works
- Best-effort failure semantics (log, never abort landlord transaction)
- Cascade depth: one level (scalar fields only) — LANDMINE admonition

## Async Mode
- `tenancy.shared.async: true`
- `SharedEntityChangedMessage` payload (class + identifier + changeType)
- LANDMINE: transport routing must be configured by user
- LANDMINE: tenants see latest state, not dispatch-time state

## tenancy:shared:resync Command
- Full signature (options, defaults)
- Dry-run mode (classification output)
- Confirmation prompt / --force
- shared_db no-op
- Continue-on-failure summary

## Write Protection
- `SharedEntityWriteInTenantContextException`
- What triggers it (persist, update, delete in tenant context)
- How to fix (write to landlord EM; sync propagates)

## shared_db Driver Behavior
- documented no-op

## See Also
```

### Recommended New Page: `phpstan-extension.md` Section Structure

```
# PHPStan Extension (DX-03)

## Overview
- Three rules, what they catch

## Installation
- Path A: extension-installer (recommended)
- Path B: manual includes
- Path C: with phpstan-doctrine (INSTEAD OF, not in addition to)

## Rule 1: Mutual Exclusion (tenancy.mutualExclusion)
- What it catches
- Violation example + fix

## Rule 2: Shared Entity Leak (tenancy.sharedEntityLeak)
- What it catches (conservative — concrete EntityManager only)
- Violation example + fix (landlord EM or @phpstan-ignore)
- Disabling via checkSharedEntityLeaks parameter

## Rule 3: Tenant ID Drift (tenancy.tenantIdDrift)
- What it catches (missing / nullable / non-string)
- Violation examples (3 cases) + fix
- Accepted types: string, ascii_string, guid, uuid

## See Also
```

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | `scripts/docs-lint.sh` (shell, run from repo root) + `mkdocs build --strict` (Python, via CI) |
| Config file | None for docs-lint; `mkdocs.yml` for mkdocs |
| Quick run | `bash scripts/docs-lint.sh` |
| Full suite | `bash scripts/docs-lint.sh && mkdocs build --strict` |

### What docs-lint.sh Can and Cannot Catch

| Validates | Cannot validate |
|-----------|----------------|
| Absence of stale v0.1 terms (wrapperClass, etc.) | Semantic accuracy of new content |
| `bundles.php` install-path regressions | Whether code examples are syntactically valid PHP |
| Shared-entity disambiguator presence (D-04 new check) | Whether API names/method signatures are correct |
| All `docs/` files scanned (by find) | Content in UPGRADE.md / CHANGELOG.md (excluded by design) |

**Semantic accuracy is validated by the planner/verifier cross-referencing source files** — the lint check is purely syntactic/terminological.

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| DOC-20 | docs-lint.sh D-04 check flags files with "shared entity" but missing disambiguators | smoke | `bash scripts/docs-lint.sh` | Wave 0 task |
| DOC-20 | Both new pages registered in mkdocs.yml nav | build | `mkdocs build --strict` | Wave 0 (mkdocs must be installed) |
| DOC-20 | filesystem-bootstrapper.md drift fix applied | smoke | `bash scripts/docs-lint.sh` (no stale terms) | Existing file modified |
| DOC-20 | shared-entities.md passes D-04 (contains both disambiguators) | smoke | `bash scripts/docs-lint.sh` | Wave 0 (new file created) |
| DOC-20 | UPGRADE.md 0.3→0.4 section expanded | manual | cross-reference source | Existing file modified |
| DOC-20 | phpstan-extension.md accurate rule identifiers | manual | cross-reference source | Wave 0 (new file created) |

### Sampling Rate

- **Per task commit:** `bash scripts/docs-lint.sh` from repo root
- **Per wave merge:** `bash scripts/docs-lint.sh && mkdocs build --strict` (if mkdocs available)
- **Phase gate:** All docs-lint checks green + mkdocs build --strict clean before `/gsd:verify-work`

### Wave 0 Gaps

- [ ] `docs/user-guide/shared-entities.md` — new file; must exist before D-04 check can validate it
- [ ] `docs/user-guide/phpstan-extension.md` — new file; must exist before mkdocs build can validate nav
- [ ] D-04 check in `scripts/docs-lint.sh` — must be added (Wave 1); Wave 0 can add a placeholder failing check to prove the mechanism
- [ ] `mkdocs build` environment — mkdocs may or may not be installed locally; CI runs it; for local verification use `pip install mkdocs-material` if needed

---

## Security Domain

This is a documentation-only phase. No new runtime code ships. The `docs-lint.sh` D-04 check enforces terminology discipline that helps prevent user confusion about the trust model (landlord-side vs tenant-side). No ASVS categories apply.

---

## Sources

### Primary (HIGH confidence)
- Live source: `src/Attribute/Shared.php` — confirmed class name, namespace, zero params
- Live source: `src/Exception/SharedEntityWriteInTenantContextException.php` — confirmed class hierarchy, static factory signature
- Live source: `src/Command/SharedEntityResyncCommand.php` — confirmed command name, all options, flow
- Live source: `src/PHPStan/Rule/MutualExclusionRule.php` — confirmed `tenancy.mutualExclusion` identifier
- Live source: `src/PHPStan/Rule/SharedEntityLeakRule.php` — confirmed `tenancy.sharedEntityLeak` identifier, `checkSharedEntityLeaks` default
- Live source: `src/PHPStan/Rule/TenantIdDriftRule.php` — confirmed `tenancy.tenantIdDrift` identifier, accepted string types
- Live source: `extension.neon` — confirmed `checkSharedEntityLeaks: true` default, all three rules
- Live source: `extension-doctrine.neon` — confirmed separate fragment, double-registration warning
- Live source: `composer.json` — confirmed `extra.phpstan.includes: ["extension.neon"]`
- Live source: `scripts/docs-lint.sh` — confirmed `check()` helper signature, target scoping, EXIT convention
- Live source: `mkdocs.yml` — confirmed current nav structure, insertion points
- Live source: `UPGRADE.md` — confirmed `## 0.3 → 0.4` section exists, placeholder note to remove
- Live source: `docs/user-guide/filesystem-bootstrapper.md` — confirmed drift at line 205 (`services?` key)
- Live source: `src/Filesystem/TenantFilesystemConfigTrait.php` line 29 — confirmed `services?` is a no-op
- Live source: `src/TenancyBundle.php` lines 119-128 — confirmed all config key defaults
- Live source: `src/Subscriber/SharedEntitySyncSubscriber.php` — confirmed sync/async dispatch behavior
- Live source: `src/Shared/SharedEntityCopierInterface.php` — confirmed full interface contract
- Live source: `src/Message/SharedEntityChangedMessage.php` — confirmed message shape

### Secondary (context documents)
- `.planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md` — DEC-FILE-* decisions
- `.planning/phases/25-shared-entities-sync-mode/25-CONTEXT.md` — D-01..D-07 sync decisions
- `.planning/phases/26-tenancy-shared-resync-command/26-CONTEXT.md` — D-01..D-07 command decisions
- `.planning/phases/27-async-shared-entities/27-CONTEXT.md` — D-01..D-07 async decisions
- `.planning/phases/28-phpstan-extension/28-CONTEXT.md` — D-01..D-04 PHPStan decisions

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `mkdocs build --strict` catches broken internal nav links and missing page references | Validation Architecture | If not strict, broken nav could pass build; medium risk — CI would catch at review |
| A2 | `tenancy:shared:resync` stub cross-link in `cli-commands.md` is the right approach for discoverability | Pitfall 5 / Common Pitfalls | If wrong, users might miss the command; low risk — the comprehensive docs are on shared-entities.md |

**All other claims verified against live source files at research time.**

---

## Open Questions

1. **`cli-commands.md` — full entry or stub cross-link for `tenancy:shared:resync`?**
   - What we know: `tenancy:shared:resync` is a 5th command not in the current `cli-commands.md`. Context (Claude's discretion) says comprehensive docs live on `shared-entities.md`.
   - What's unclear: should `cli-commands.md` get a full command entry (like the others) or a one-paragraph stub with a cross-link?
   - Recommendation: one-paragraph stub + cross-link to `shared-entities.md`. The command's purpose is fundamentally about the shared-entity feature, not a generic tenant operation. The pattern of `cli-commands.md` listing brief summaries + the full docs being on the feature page is the right split.

2. **`extension-doctrine.neon` consumer install path — document in `phpstan-extension.md`?**
   - What we know: `extension-doctrine.neon` provides `ObjectMetadataResolver` injection for Rule 3's full metadata path (XML/YAML-mapped entities). It must be included INSTEAD OF `extension.neon`.
   - What's unclear: whether this warrants a dedicated section or just a `!!! note` admonition in the phpstan-extension.md installation section.
   - Recommendation: a `!!! note` admonition under "Installation" (not a full H2 section) — it's an advanced configuration that most users don't need.

---

## Metadata

**Confidence breakdown:**
- Filesystem drift audit: HIGH — read both live doc and live source; single confirmed drift at line 205
- Shared-entities API facts: HIGH — read live source for every class/method/exception mentioned
- Command signature: HIGH — read live `SharedEntityResyncCommand.php` line by line
- PHPStan rule identifiers: HIGH — read each rule file; checked both neon files
- docs-lint.sh idioms: HIGH — read full script; D-04 implementation pattern derived from existing awk block pattern
- mkdocs.yml nav: HIGH — read live file; confirmed insertion points
- UPGRADE.md state: HIGH — read live file; confirmed section exists + placeholder note to remove

**Research date:** 2026-06-18
**Valid until:** 2026-07-18 (stable domain; only invalidated if source code changes between research and planning, which should not happen in a docs-only phase)
