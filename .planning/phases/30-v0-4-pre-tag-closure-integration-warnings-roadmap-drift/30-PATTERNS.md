# Phase 30: v0.4 Pre-Tag Closure — Pattern Map

**Mapped:** 2026-06-19
**Files analyzed:** 9 (2 new, 7 modified)
**Analogs found:** 9 / 9

---

## File Classification

| New / Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `src/Shared/TenantEmSwitcherInterface.php` (NEW) | interface | request-response | `src/Shared/SharedEntityCopierInterface.php` | exact |
| `src/Shared/TenantEmSwitcher.php` (NEW) | service | request-response | `src/Shared/SharedEntityCopier.php` | exact |
| `src/Subscriber/SharedEntitySyncSubscriber.php` | event-subscriber | event-driven | self (read current state) | — |
| `src/MessageHandler/SharedEntityChangedMessageHandler.php` | message-handler | event-driven | self (read current state) | — |
| `src/Subscriber/SharedEntityWriteProtectionListener.php` | event-subscriber | event-driven | self (read current state) | — |
| `src/Command/SharedEntityResyncCommand.php` | command | batch | self (read current state) | — |
| `config/services.php` | config / DI | — | self (read current state) | — |
| `docs/roadmap.md` | docs | — | self (read current state) | — |
| `scripts/docs-lint.sh` | script / CI | — | self (read current state) | — |

---

## Pattern Assignments

### `src/Shared/TenantEmSwitcherInterface.php` (NEW — interface, request-response)

**Primary analog:** `src/Shared/SharedEntityCopierInterface.php`
**Secondary analog:** `src/Command/Install/BundlesPhpInstallerInterface.php` (same motivation)

**Interface file header + namespace** (SharedEntityCopierInterface.php lines 1-6):
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Shared;

use Doctrine\ORM\EntityManagerInterface;
```

**Docblock convention** (SharedEntityCopierInterface.php lines 9-17 — copy motivation verbatim):
```php
/**
 * Contract for the tenant entity-manager switch/restore service.
 *
 * Extracted alongside the final TenantEmSwitcher so PHPUnit can create
 * mock objects for unit tests (PHPUnit 11 ClassIsFinalException
 * prevents mocking final classes — same pattern as TenantConnectionInterface).
 *
 * @see TenantEmSwitcher
 */
```

**Method signatures to declare** — extracted verbatim from the two duplicated private methods in the subscriber (lines 247-263, 330-343) and handler (lines 169-185, 194-207). These are the EXACT bodies the service will own:

`switchTo(TenantInterface $tenant): EntityManagerInterface`
- sets tenant context
- closes DBAL connection
- calls `registry->resetManager('tenant')`
- returns the fresh EM

`restore(?TenantInterface $previousTenant): void`
- restores or clears tenant context
- closes DBAL connection
- calls `registry->resetManager('tenant')`

**Interface structure pattern** (SharedEntityCopierInterface.php lines 19-78):
```php
interface TenantEmSwitcherInterface
{
    /**
     * Switch TenantContext to $tenant, close the tenant DBAL connection, and return
     * a fresh tenant EntityManager bound to that tenant's database.
     *
     * Lightweight per-change / per-message switch path. Contrast with
     * SharedEntityResyncCommand which uses setTenant() + bootstrapperChain->boot()
     * (full bootstrapper chain — appropriate for CLI backfill, not per-event fan-out).
     */
    public function switchTo(TenantInterface $tenant): EntityManagerInterface;

    /**
     * Restore the tenant context that was active before the fan-out and drop the
     * tenant connection handle so the next query reconnects under the restored context.
     *
     * CR-01: re-instates the request-scoped tenant (or clears if none was active).
     * CR-02: closing the connection prevents later queries in the same request from
     * silently hitting the last-switched tenant's DB.
     */
    public function restore(?TenantInterface $previousTenant): void;
}
```

**Namespace placement:** `src/Shared/` — mirrors `SharedEntityCopierInterface` (same directory, same namespace `Tenancy\Bundle\Shared`).

---

### `src/Shared/TenantEmSwitcher.php` (NEW — final service, request-response)

**Primary analog:** `src/Shared/SharedEntityCopier.php` (final class implementing its interface)

**File header + class declaration** (SharedEntityCopier.php lines 1-41):
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Shared;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\TenantInterface;

/**
 * Lightweight tenant EM switch/restore service for per-change fan-out.
 *
 * Owns the two operations that were previously duplicated across
 * SharedEntitySyncSubscriber and SharedEntityChangedMessageHandler (W-02).
 *
 * ## This is the lightweight switch path
 *
 * switchTo() does: setTenant() → tenant DBAL close() → resetManager('tenant').
 * restore() does: set-or-clear context → tenant DBAL close() → resetManager('tenant').
 *
 * This is intentionally NOT the full bootstrapper-chain path. SharedEntityResyncCommand
 * uses setTenant() + bootstrapperChain->boot() (fires TenantBootstrapped + all
 * bootstrappers) for CLI backfill semantics. Firing every bootstrapper on each
 * per-change / per-message event would cause perf + side-effect issues. See W-03
 * in the Phase 30 audit and the back-reference note in SharedEntityResyncCommand.
 *
 * @see SharedEntityResyncCommand::resyncForTenant() for the heavier full-boot path
 */
final class TenantEmSwitcher implements TenantEmSwitcherInterface
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ManagerRegistry $registry,
    ) {
    }
```

**`switchTo()` body — EXACT SOURCE** (SharedEntitySyncSubscriber.php lines 247-263):
```php
    public function switchTo(TenantInterface $tenant): EntityManagerInterface
    {
        $this->tenantContext->setTenant($tenant);

        // Force the tenant DBAL connection to reconnect via TenantAwareDriver::connect()
        // so it picks up the new tenant's connection params. Without close(), the
        // previously-open socket stays connected to the prior tenant's DB (DBAL only
        // calls connect() when the internal connection handle is null).
        $tenantConn = $this->registry->getConnection('tenant');
        if ($tenantConn instanceof Connection) {
            $tenantConn->close();
        }

        /** @var EntityManagerInterface $tenantEm */
        $tenantEm = $this->registry->resetManager('tenant');

        return $tenantEm;
    }
```

**`restore()` body — EXACT SOURCE** (SharedEntitySyncSubscriber.php lines 330-343):
```php
    public function restore(?TenantInterface $previousTenant): void
    {
        if (null !== $previousTenant) {
            $this->tenantContext->setTenant($previousTenant);
        } else {
            $this->tenantContext->clear();
        }

        $tenantConn = $this->registry->getConnection('tenant');
        if ($tenantConn instanceof Connection) {
            $tenantConn->close();
        }
        $this->registry->resetManager('tenant');
    }
```

**Byte-identity check (W-02 core claim):** The handler's copies (SharedEntityChangedMessageHandler.php lines 169-185, 194-207) are identical except the subscriber uses `\Doctrine\DBAL\Connection` with the leading backslash (class already imported in the subscriber's `use` block at line 7 via the `use Doctrine\Common\EventSubscriber;` import chain — actually the subscriber uses `\Doctrine\DBAL\Connection` as an inline check without a `use` import). The handler imports `use Doctrine\DBAL\Connection;` at line 7 and uses `Connection` unqualified. The new `TenantEmSwitcher` should use a `use Doctrine\DBAL\Connection;` import (handler style) for clarity. The logic is byte-identical.

**Import diff between subscriber and handler copies:**
- Subscriber `switchToTenant()` line 255: `if ($tenantConn instanceof \Doctrine\DBAL\Connection)` (fully-qualified, no `use`)
- Handler `switchToTenant()` line 177: `if ($tenantConn instanceof Connection)` (short form via `use Doctrine\DBAL\Connection;` at line 7)
- `TenantEmSwitcher` MUST use the `use` import form (handler style) — it is the canonical implementation.

---

### `src/Subscriber/SharedEntitySyncSubscriber.php` (MODIFIED)

**Current constructor** (lines 91-100) — W-01 type-hint change + W-02 switcher injection:
```php
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantProviderInterface $tenantProvider,
        private readonly ManagerRegistry $registry,
        private readonly LoggerInterface $logger,
        private readonly string $driver,
        private readonly SharedEntityCopier $copier,   // ← W-01: change to SharedEntityCopierInterface
        private readonly ?MessageBusInterface $bus = null,
    ) {
    }
```

**Changes needed:**
1. Line 18: change `use Tenancy\Bundle\Shared\SharedEntityCopier;` → `use Tenancy\Bundle\Shared\SharedEntityCopierInterface;`
2. Line 97: `SharedEntityCopier $copier` → `SharedEntityCopierInterface $copier`
3. Add constructor parameter for `TenantEmSwitcherInterface $switcher` (before the optional `?MessageBusInterface $bus`)
4. Remove private methods `switchToTenant()` (lines 246-263) and `restoreTenantContext()` (lines 330-343)
5. Replace call sites `$this->switchToTenant($tenant)` (line 224) → `$this->switcher->switchTo($tenant)` and `$this->restoreTenantContext($previousTenant)` (line 235) → `$this->switcher->restore($previousTenant)`

**Async branch is OUT OF SCOPE** (lines 170-199): `$this->tenantContext->setTenant()` / `$this->tenantContext->clear()` inside the async branch (the `$this->bus !== null` path) are separate context operations, NOT calls to `switchToTenant()`. D-03 explicitly excludes this branch.

---

### `src/MessageHandler/SharedEntityChangedMessageHandler.php` (MODIFIED)

**Current constructor** (lines 54-62):
```php
    public function __construct(
        private readonly EntityManagerInterface $landlordEm,
        private readonly TenantProviderInterface $tenantProvider,
        private readonly SharedEntityCopierInterface $copier,
        private readonly TenantContext $tenantContext,
        private readonly ManagerRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {
    }
```

**Changes needed:**
1. Add `TenantEmSwitcherInterface $switcher` constructor parameter
2. Remove `use Doctrine\DBAL\Connection;` import (no longer needed after removing the private methods)
3. Remove private methods `switchToTenant()` (lines 169-185) and `restoreTenantContext()` (lines 194-207)
4. Replace `$this->switchToTenant($tenant)` (line 125) → `$this->switcher->switchTo($tenant)`
5. Replace `$this->restoreTenantContext($previousTenant)` (line 152) → `$this->switcher->restore($previousTenant)`
6. The reset-on-failure `$this->registry->resetManager('tenant')` at line 147 stays — it is inside the per-tenant catch block, not in `restoreTenantContext()`.

**Note on ManagerRegistry retention:** The `ManagerRegistry` is still needed in the handler for the failure-path `$this->registry->resetManager('tenant')` at line 147. Do NOT remove that import or field.

---

### `src/Subscriber/SharedEntityWriteProtectionListener.php` (MODIFIED — W-01 only)

**Current constructor** (lines 42-45):
```php
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SharedEntityCopier $copier,
    ) {
    }
```

**Change needed:**
1. Line 13: change `use Tenancy\Bundle\Shared\SharedEntityCopier;` → `use Tenancy\Bundle\Shared\SharedEntityCopierInterface;`
2. Line 44: `SharedEntityCopier $copier` → `SharedEntityCopierInterface $copier`

No behavioral change. The interface already exposes `isSyncInProgress()` (SharedEntityCopierInterface.php line 77).

---

### `src/Command/SharedEntityResyncCommand.php` (MODIFIED — W-03 docblock note only)

**Target location** for back-reference note: `resyncForTenant()` method (lines 234-250), specifically the `bootstrapperChain->boot($tenant)` call at line 237.

**Current `resyncForTenant()` signature** (lines 234-237):
```php
    private function resyncForTenant(TenantInterface $tenant, array $landlordRowsByClass): void
    {
        $this->tenantContext->setTenant($tenant);
        $this->bootstrapperChain->boot($tenant);
```

**Change needed:** Add a docblock or inline comment near line 237 explaining WHY this uses the full `bootstrapperChain->boot()` path and referencing `TenantEmSwitcher` as the lightweight alternative. No behavior change.

**Pattern for asymmetry note** (mirrors D-05 wording from CONTEXT.md):
```php
    /**
     * Boot tenant context and apply all shared rows to the tenant EM.
     *
     * ## Full bootstrapper-chain path (intentional — contrast with TenantEmSwitcher)
     *
     * This method uses setTenant() + bootstrapperChain->boot() (the full boot path),
     * which fires TenantBootstrapped and every registered bootstrapper. This is correct
     * for CLI backfill: the resync command is a heavyweight one-off that should apply
     * all tenant configuration exactly as a real request would.
     *
     * SharedEntitySyncSubscriber and SharedEntityChangedMessageHandler use the lightweight
     * TenantEmSwitcher (setTenant + DBAL close + resetManager) instead — firing all
     * bootstrappers on every per-change / per-message event would cause perf and side-effects.
     *
     * @see TenantEmSwitcher — the lightweight per-change path
     */
```

---

### `config/services.php` (MODIFIED — new service def + 2 argument changes)

**DI wiring conventions** (lines 60-68 — SharedEntityCopier registration style in TenancyBundle::loadExtension(), mirrored for Shared services):

The Shared entity services are wired inside `TenancyBundle::loadExtension()` (NOT in `config/services.php` directly — confirm by checking `TenancyBundle.php`). However, the DI style for plain injected services is:

```php
// Plain injected service (no tags, no decoration) — follow this style:
$services->set('tenancy.shared.em_switcher', TenantEmSwitcher::class)
    ->args([
        service('tenancy.context'),
        service('doctrine'),   // ManagerRegistry — check the exact service id used in this project
    ]);
$services->alias(TenantEmSwitcherInterface::class, 'tenancy.shared.em_switcher');
```

**Existing argument-update pattern for subscriber** — find where `SharedEntitySyncSubscriber` is currently wired (will be in `TenancyBundle::loadExtension()`, not `config/services.php`) and add the `TenantEmSwitcherInterface` argument in the same position it appears in the constructor (5th position, before the optional bus).

**Existing argument-update pattern for handler** — same file, handler wiring, add `TenantEmSwitcherInterface` argument.

**Service id convention:** `tenancy.shared.em_switcher` (matches `tenancy.shared.*` namespace used for shared-entity services).

**Note:** Run `grep -n "SharedEntitySyncSubscriber\|SharedEntityChangedMessageHandler\|SharedEntityWriteProtectionListener" src/TenancyBundle.php` to locate exact wiring lines before planning — this file is the real DI registration site for these classes.

---

### `docs/roadmap.md` (MODIFIED — WR-06/07)

**Current state of stale sections:**

Line 9 (v0.3 Shipped — stale wording):
```markdown
- ✅ **v0.3 Adoption Surface — partial** (2026-05-22) — ... Latest tag: **v0.3.2**.
```
Must become: milestone shipped at **v0.3.3** (remove "partial", update tag).

Lines 13-15 (stale "In progress" block):
```markdown
## In progress — closing v0.3

- 📝 **Phase 22 — Docs Refresh (DOC-19)** — install page rewrite ...
```
This entire section must be removed.

Lines 17-23 (v0.4 framed as "Next" — WR-06):
```markdown
## Next — v0.4 Storage & shared entities

- **Filesystem (Flysystem) bootstrapper** — ...
- **Shared-entity replication** — ... "landlord-side master" / "tenant-side read-only copy" ...
- **PHPStan extension** for `#[TenantAware]` correctness — static check that tenant-scoped repositories aren't accidentally injected into shared services
```
Move this entire block to "Shipped" as v0.4 (no tag number per D-09 — forward reference), linking CHANGELOG. Fix the PHPStan line (WR-07): replace "tenant-scoped repositories aren't accidentally injected into shared services" with the three real rule IDs: `tenancy.mutualExclusion`, `tenancy.sharedEntityLeak`, `tenancy.tenantIdDrift`.

**New "Next" section** (D-08): Set Next = v0.5 (Operations & scale), pulling from the existing Planned table row (line 29).

**D-09 framing pattern** (no tag number):
```markdown
- ✅ **v0.4 Storage & Shared Entities** — Filesystem (Flysystem) bootstrapper; shared-entity
  replication (landlord-side master → tenant-side read-only copy via Doctrine events + Messenger
  async fan-out); PHPStan extension with three static rules (`tenancy.mutualExclusion`,
  `tenancy.sharedEntityLeak`, `tenancy.tenantIdDrift`). See [CHANGELOG](https://github.com/danplaton4/tenancy-bundle/blob/master/CHANGELOG.md).
```

**Canonical vocabulary source:** `docs/user-guide/shared-entities.md` (confirmed used in roadmap line 22 already — keep it).

---

### `scripts/docs-lint.sh` (MODIFIED — WR-03)

**Current awk block** (lines 73-81):
```bash
BUNDLES_VIOLATIONS=$(awk '
    /^## / {
        section = $0
        sub(/^## /, "", section)
        in_whitelist = (section ~ /^(Migration|Upgrade|Manual setup|Troubleshooting|Do I have to do anything\?|tenancy:install)/)
        next
    }
    !in_whitelist { print FILENAME ":" FNR ":" $0 }
' $(find docs/ -name '*.md') | grep -E 'bundles\.php' || true)
```

**Bug:** `in_whitelist` variable is never reset between files. When `find docs/ -name '*.md'` feeds multiple files to awk, if file N ends while `in_whitelist=1`, file N+1 starts with `in_whitelist` still `1` — silently passing non-whitelisted `bundles.php` references.

**Fix:** Add `FNR==1 { in_whitelist=0 }` as the first rule in the awk block (D-10):
```bash
BUNDLES_VIOLATIONS=$(awk '
    FNR==1 { in_whitelist=0 }
    /^## / {
        section = $0
        sub(/^## /, "", section)
        in_whitelist = (section ~ /^(Migration|Upgrade|Manual setup|Troubleshooting|Do I have to do anything\?|tenancy:install)/)
        next
    }
    !in_whitelist { print FILENAME ":" FNR ":" $0 }
' $(find docs/ -name '*.md') | grep -E 'bundles\.php' || true)
```

`FNR` is the per-file record number (resets to 1 at each new file); `NR` is the global record number (never resets). Using `FNR==1` is the correct awk idiom for "at the start of each new file".

---

## Shared Patterns

### Final class + interface convention (W-01/W-02 foundation)

**Source:** `src/Shared/SharedEntityCopierInterface.php` (lines 9-17) + `src/Command/Install/BundlesPhpInstallerInterface.php` (lines 7-14)

**Apply to:** `TenantEmSwitcherInterface.php` (new file)

Docblock must cite: "same pattern as TenantConnectionInterface" — this is the established project vocabulary for this convention, cited in both existing interfaces.

```php
/**
 * Contract for [service description].
 *
 * Extracted alongside the final [ConcreteClass] so PHPUnit can create
 * mock objects for unit tests (PHPUnit 11 ClassIsFinalException
 * prevents mocking final classes — same pattern as TenantConnectionInterface).
 *
 * @see [ConcreteClass]
 */
```

### Doctrine optional-dependency guard

**Source:** `config/services.php` lines 60-68 and 103-107

**Apply to:** Any new DI wiring that touches Doctrine (TenantEmSwitcher uses `ManagerRegistry` which is Doctrine). The TenantEmSwitcher service MUST be registered inside an `if (interface_exists(Doctrine\ORM\EntityManagerInterface::class))` guard — the subscriber and handler are already guarded, the switcher belongs in the same block.

### Type-hint injection style (constructor promotion)

**Source:** `src/Shared/SharedEntityCopier.php` lines 44-47, `src/MessageHandler/SharedEntityChangedMessageHandler.php` lines 54-62

All service constructors use `private readonly` promoted properties. No setters. No lazy injection. `TenantEmSwitcher` follows the same pattern.

### `declare(strict_types=1)` + namespace

Every PHP source file opens with:
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\[Subdirectory];
```
No exceptions. `TenantEmSwitcher` and `TenantEmSwitcherInterface` are in `namespace Tenancy\Bundle\Shared;`.

### Unit test structure for new seam (D-07)

**Source:** `tests/Unit/Command/SharedEntityResyncCommandTest.php` lines 1-49

Test for `SharedEntityWriteProtectionListener` (minimum D-07 requirement):
```php
final class SharedEntityWriteProtectionListenerTest extends TestCase
{
    private SharedEntityCopierInterface&MockObject $copier;
    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        $this->copier = $this->createMock(SharedEntityCopierInterface::class);
        $this->tenantContext = new TenantContext();
    }
```

**Two mandatory test cases (D-07):**
1. Re-entrancy bypass: `$copier->isSyncInProgress()` returns `true` → `onFlush()` returns without throwing
2. Throw-on-Shared-write: tenant active, `isSyncInProgress()` false, entity with `#[Shared]` in scheduled insertions → throws `SharedEntityWriteInTenantContextException`

---

## Byte-Identity Verification Table

The W-02 extraction MUST preserve exact behavior. The table below shows the two method bodies side-by-side for the planner to confirm before writing code:

| Method | Subscriber (lines) | Handler (lines) | Difference |
|---|---|---|---|
| `switchToTenant()` / `switchTo()` | 247-263 | 169-185 | Import style only: subscriber uses `\Doctrine\DBAL\Connection` (FQCN inline), handler uses `Connection` (via `use` import). Logic identical. |
| `restoreTenantContext()` / `restore()` | 330-343 | 194-207 | Same import-style difference. Logic identical. |

`TenantEmSwitcher` adopts the `use Doctrine\DBAL\Connection;` import style (handler convention) as the canonical form.

---

## No Analog Found

All files have analogs or are self-referential reads. No "no analog" entries.

---

## Metadata

**Analog search scope:** `src/`, `tests/Unit/`, `config/`, `docs/`, `scripts/`
**Files read:** 11
**Pattern extraction date:** 2026-06-19
