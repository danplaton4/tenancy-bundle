# Phase 26: tenancy:shared:resync command — Research

**Researched:** 2026-06-12
**Domain:** Symfony Console command, Doctrine ORM metadata, SharedEntityCopier extraction, write-protection bypass
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** Mirror `tenancy:migrate` exactly — `--tenant=<slug>` `VALUE_OPTIONAL` option; absent = all tenants via `findAll()`. No `--all` flag. No positional `<SlugOrAll>` argument.
- **D-02:** Extract Phase 25's `SharedEntitySyncSubscriber::doSync()` upsert logic into a new shared service (`SharedEntityCopier`, working name) that BOTH the subscriber and the command call. Phase 25's full SHARE-01 test suite MUST stay green after extraction. `merge()` wording in REQUIREMENTS superseded — find-or-new is the idempotent path.
- **D-03:** `--dry-run` does REAL drift detection: classify every shared landlord row per tenant as `would-insert` / `would-update` / `in-sync`. Read-only, no flush. Copier exposes a classify/diff capability separate from apply.
- **D-04:** Live run computes drift summary first (same as `--dry-run`), then `SymfonyStyle::confirm('Proceed?', false)` default-No. `--force` skips. Under `-n` without `--force`, abort cleanly — do NOT proceed on `-n` alone.
- **D-05:** `shared_db` driver = informational no-op, exit `Command::SUCCESS` (NOT FAILURE). Reads `tenancy.driver` container parameter.
- **D-06:** Continue-on-failure per-tenant `try/catch/finally`; `✓`/`✗` per tenant; `Completed: N succeeded, M failed`; list of failed tenants; exit `FAILURE` if any failed. `finally` clears `TenantContext` + `BootstrapperChain` per tenant.
- **D-07:** Enumerate `#[Shared]` classes via landlord EM `getMetadataFactory()->getAllMetadata()` filtered by `reflClass->getAttributes(Shared::class)` — proxy-safe, same as `isShared()` in the subscriber.

### Claude's Discretion

- Exact service name/namespace for the extracted copier (working name `SharedEntityCopier`), its method surface (`classify()` / `apply()` split), and how the subscriber is refactored to call it.
- Precise mechanism by which the command sets/clears the `syncInProgress` re-entrancy bypass (see Integration Points — CRITICAL LANDMINE).
- Output table formatting details (column layout of drift breakdown), progress reporting cadence for large tenant counts, logging keys.
- Whether `--dry-run` flags orphaned tenant copies (rows present on tenant but no longer on landlord) — nice-to-have diagnostic; must NOT delete them.

### Deferred Ideas (OUT OF SCOPE)

- Orphan-copy deletion / full reconciliation (additive/upsert-only per SHARE-02).
- Async / batched resync for very large tenant counts (Phase 27 / SHARE-03).
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| SHARE-02 | Bulk-initial-sync command: `tenancy:shared:resync [--tenant=<slug>] [--dry-run] [--force]` — enumerates `#[Shared]` entity classes via Doctrine metadata, reports write plan before executing, idempotent upsert into target tenant(s), continue-on-failure matching `tenancy:migrate`, `shared_db` no-op | All sections below directly enable this requirement |
</phase_requirements>

---

## Summary

Phase 26 delivers `tenancy:shared:resync`, a CLI drift-repair tool for Phase 25's best-effort runtime sync. The command enumerates all `#[Shared]` entity classes from the landlord EM's metadata, walks their rows, and upserts each into the target tenant(s)' EMs using extracted logic shared with `SharedEntitySyncSubscriber`. The command mirrors `TenantMigrateCommand` precisely for CLI shape, failure model, and BootstrapperChain lifecycle.

The central refactor is extracting `SharedEntitySyncSubscriber::doSync()` + `isSyncInProgress()` into a new `SharedEntityCopier` service. This extraction is non-negotiable: `SharedEntityWriteProtectionListener` calls `$this->syncSubscriber->isSyncInProgress()` directly, so after extraction the listener must consult the copier's flag instead. The cleanest mechanism is moving the `$syncInProgress` bool and the `isSyncInProgress()` method to `SharedEntityCopier`, then making `SharedEntityWriteProtectionListener` depend on `SharedEntityCopier` (not `SharedEntitySyncSubscriber`). The subscriber becomes a thin caller. This is a narrow, safe refactor with zero change to the flag's semantics.

The dry-run classify/diff capability is new surface on `SharedEntityCopier`. Because the upsert is find-or-new, every live run rewrites every row unconditionally — so classification (compare landlord row against tenant copy, classify as `insert`/`update`/`in-sync`) is what gives the command diagnostic value. The copier exposes both classify-only and apply modes; the command calls classify first for the confirmation summary, then apply per tenant.

**Primary recommendation:** Implement `SharedEntityCopier` with a `classify()` method returning a `SyncDiffResult` (value object with insert/update/in-sync counts + per-row detail) and an `apply()` method (upsert + flush), extract `$syncInProgress` + `isSyncInProgress()` to `SharedEntityCopier`, rewire `SharedEntityWriteProtectionListener` to depend on `SharedEntityCopier`, thin out `SharedEntitySyncSubscriber` to delegate, then build the command on top.

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| `#[Shared]` class enumeration | API / Backend (landlord EM metadata) | — | Landlord owns the canonical source of truth; metadata inspection happens at command-boot time |
| Idempotent upsert (find-or-new + scalar copy) | API / Backend (SharedEntityCopier) | — | Pure ORM operation; no HTTP layer involved |
| Drift classification (classify/diff) | API / Backend (SharedEntityCopier) | — | Reads both landlord row and tenant copy; ORM comparison |
| Write-protection bypass | API / Backend (SharedEntityCopier.syncInProgress flag) | SharedEntityWriteProtectionListener | The copier owns the flag; the listener reads it. Single source of truth |
| Tenant enumeration | API / Backend (TenantProviderInterface) | — | Already established; findAll() / findBySlug() |
| Per-tenant EM switching | API / Backend (switchToTenant pattern) | BootstrapperChain | subscriber's switchToTenant() + BootstrapperChain::boot()/clear() both valid; see pitfall analysis |
| Confirmation prompt | Console (SymfonyStyle) | — | User-facing safety gate; belongs entirely in the command |
| Continue-on-failure loop | Console (command's execute()) | — | Per-tenant try/catch/finally mirrors TenantMigrateCommand |
| shared_db no-op | Console (command's execute()) | — | Early guard reading `tenancy.driver` param |
| Service registration | DI / config (TenancyBundle::loadExtension, services.php) | — | Doctrine-guarded; `class_exists`/`interface_exists` pattern |

---

## Standard Stack

### Core (all verified in the codebase)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `symfony/console` | ^7.0 | Command base, SymfonyStyle, CommandTester | Hard require in bundle; `TenantMigrateCommand` already uses it [VERIFIED: codebase] |
| `doctrine/orm` | ^2.x/^3.x | EntityManager, ClassMetadata, UnitOfWork | Optional dep — already guarded throughout; same pattern here [VERIFIED: codebase] |
| `doctrine/doctrine-bundle` | ^2.x | ManagerRegistry, connection management | Already a require-dev dep; switchToTenant() uses it [VERIFIED: codebase] |
| `psr/log` | ^3.0 | PSR-3 logger | Already injected into SharedEntitySyncSubscriber [VERIFIED: codebase] |
| `phpunit/phpunit` | ^11 | Unit + integration tests | Already in require-dev [VERIFIED: codebase] |

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `Symfony\Component\Console\Tester\CommandTester` | ships with symfony/console | Test execute(), getDisplay(), getStatusCode(), input stream for confirm() | All command unit tests in this project use it [VERIFIED: codebase] |
| `Symfony\Component\Console\Style\SymfonyStyle` | ships with symfony/console | IO formatting, confirm() prompt, writeln() | TenantMigrateCommand uses it; same here [VERIFIED: codebase] |

No new packages to install. Phase 26 uses only already-installed dependencies.

---

## Package Legitimacy Audit

No new external packages are introduced by this phase. All dependencies (`symfony/console`, `doctrine/orm`, `doctrine/doctrine-bundle`, `psr/log`, `phpunit/phpunit`) are already in `composer.json`. Slopcheck not required.

**Packages removed due to slopcheck [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none

---

## Architecture Patterns

### System Architecture Diagram

```
tenancy:shared:resync execute()
        │
        ├─ D-05: driver == 'shared_db'? → print no-op notice → EXIT SUCCESS
        │
        ├─ Resolve tenant list
        │    ├─ --tenant=<slug> → TenantProviderInterface::findBySlug() → [tenant]
        │    └─ (absent)       → TenantProviderInterface::findAll()    → [tenant...]
        │
        ├─ Enumerate #[Shared] classes
        │    └─ landlordEm->getMetadataFactory()->getAllMetadata()
        │         └─ filter: reflClass->getAttributes(Shared::class) != []
        │              └─ for each class: landlordEm->getRepository($class)->findAll()
        │                                  → landlord rows
        │
        ├─ Compute drift summary (classify pass — D-03)
        │    └─ for each tenant:
        │         ├─ SharedEntityCopier::classify(landlordEm, $class, $landlordRows, tenant)
        │         │    ├─ switchToTenant() → fresh tenant EM
        │         │    └─ for each row: tenantEm->find($class, $ids)
        │         │         ├─ null              → would-insert
        │         │         ├─ fields differ     → would-update
        │         │         └─ fields identical  → in-sync
        │         └─ SyncDiffResult (insert/update/in-sync counts)
        │
        ├─ Print drift summary (table: tenant | would-insert | would-update | in-sync)
        │
        ├─ --dry-run? → EXIT SUCCESS (no writes)
        │
        ├─ --force? skip → else: SymfonyStyle::confirm('Proceed?', false)
        │    └─ No / -n without --force → EXIT SUCCESS (abort cleanly)
        │
        └─ Apply pass (per-tenant try/catch/finally — D-06)
             └─ for each tenant:
                  try:
                    BootstrapperChain::boot($tenant)    [switches context + wires DB]
                    SharedEntityCopier::setInProgress(true)
                    for each class, for each landlord row:
                      SharedEntityCopier::apply(landlordEm, tenantEm, entity, 'insert'|'update')
                        └─ find-or-new + scalar-field copy + GENERATOR_TYPE_NONE trick + flush
                    SharedEntityCopier::setInProgress(false)
                    io->writeln(' ✓ <slug>')
                  catch \Throwable:
                    failures[] = slug
                    io->writeln(' ✗ <slug> (<message>)')
                  finally:
                    SharedEntityCopier::setInProgress(false)   [always reset]
                    TenantContext::clear()
                    BootstrapperChain::clear()
             └─ Completed: N succeeded, M failed
             └─ EXIT FAILURE if any failed, else EXIT SUCCESS
```

### Recommended Project Structure

```
src/
├── Command/
│   └── SharedEntityResyncCommand.php          # new — tenancy:shared:resync
├── Shared/
│   └── SharedEntityCopier.php                 # new — extracted from subscriber
│       # OR: src/Subscriber/SharedEntityCopier.php (to keep near subscriber)
│       # Planner's discretion — name/namespace is Claude's discretion per CONTEXT D-01
├── Subscriber/
│   ├── SharedEntitySyncSubscriber.php         # modified — delegate to copier
│   └── SharedEntityWriteProtectionListener.php # modified — depend on copier not subscriber
tests/
├── Unit/
│   ├── Shared/
│   │   └── SharedEntityCopierTest.php          # new — classify/apply unit tests
│   └── Command/
│       └── SharedEntityResyncCommandTest.php   # new — CommandTester unit tests
└── Integration/
    └── SharedEntity/
        └── SharedEntityResyncCommandIntegrationTest.php  # new — full E2E with SQLite
```

### Pattern 1: Copier Extraction — syncInProgress Flag Ownership

**What:** Move `$syncInProgress` bool + `isSyncInProgress()` from `SharedEntitySyncSubscriber` to `SharedEntityCopier`. Rewire `SharedEntityWriteProtectionListener` to depend on `SharedEntityCopier` instead of `SharedEntitySyncSubscriber`.

**Why this is the correct mechanism:**
`SharedEntityWriteProtectionListener` currently has:

```php
// Source: src/Subscriber/SharedEntityWriteProtectionListener.php:68
if ($this->syncSubscriber->isSyncInProgress()) {
    return;
}
```

After extraction, `SharedEntityCopier` owns the flag and exposes `isSyncInProgress()`. The listener constructor takes `SharedEntityCopier $copier` instead of (or in addition to) `SharedEntitySyncSubscriber $syncSubscriber`. The subscriber delegates its whole per-tenant write loop to the copier, which sets `$syncInProgress = true` around the flush, then always resets it in a `finally`.

**The command path:** The command calls `$this->copier->setInProgress(true)` before its apply loop per tenant and `setInProgress(false)` in `finally`. Alternatively the copier's `apply()` method itself can own the flag around `$tenantEm->flush()` — this is cleaner: the flag is set/cleared immediately around the flush call, not around the whole tenant batch, which is safe because Doctrine events fire synchronously within `flush()`.

**Recommended approach (cleanest):**

```php
// Source: verified from codebase analysis [VERIFIED: codebase]
// In SharedEntityCopier::apply():
$this->syncInProgress = true;
try {
    $tenantEm->persist($copy);
    $tenantEm->flush();
} finally {
    $this->syncInProgress = false;
}
```

The subscriber's WR-02 reasoning ("keep the flag set for this tenant's whole batch") relied on a loop where `applyChange()` could hand back a fresh EM after failure. With extraction, `apply()` is called per entity, so setting the flag per `flush()` call is correct — each `flush()` is atomic from Doctrine's event perspective.

**DI wiring impact:**
- `SharedEntityWriteProtectionListener` constructor: change `SharedEntitySyncSubscriber $syncSubscriber` to `SharedEntityCopier $copier` (or add copier alongside subscriber during transition; final goal is copier-only dependency).
- `SharedEntitySyncSubscriber` constructor: add `SharedEntityCopier $copier`.
- `config/services.php` and `TenancyBundle::loadExtension()`: update wiring accordingly (all inside the `database.enabled` Doctrine-guarded block).

### Pattern 2: Tenant EM Switching for the Command

**What:** Use `BootstrapperChain::boot($tenant)` + `BootstrapperChain::clear()` in `finally` — exactly as `TenantMigrateCommand::runMigrationsForTenant()` does. NOT the subscriber's `switchToTenant()` / `restoreTenantContext()` pattern.

**Why:** The subscriber's `switchToTenant()` was designed for fan-out within a landlord HTTP request (save/restore previousTenant, no BootstrapperChain). The command runs in a CLI context with no prior tenant active. `BootstrapperChain::boot()` is the established CLI pattern: it calls all bootstrappers (including `DatabaseSwitchBootstrapper` which calls `close()` on the tenant connection and lets `TenantAwareDriver::connect()` pick up the new tenant), sets `TenantContext`, and dispatches `TenantBootstrapped`. `BootstrapperChain::clear()` in `finally` restores the clean state. This mirrors `TenantMigrateCommand` exactly (D-01, D-06). [VERIFIED: codebase — TenantMigrateCommand.php lines 126-129]

**After `BootstrapperChain::boot()`**, get the tenant EM via `$this->registry->getManager('tenant')` (NOT `resetManager` here — boot already wired it to the correct DB). Before the next tenant's `boot()`, the `finally` `clear()` runs, and the next `boot()` will handle the switch.

**Reading landlord rows:** Query the landlord EM directly (injected as `$landlordEm`). The command holds references to both: `ManagerRegistry` to get the tenant EM after boot, and the landlord EM directly (same as how TenantMigrateCommand injects `$this->tenantConnection` for tenant, while landlord EM is the default). [VERIFIED: codebase — TenancyBundle.php loadExtension() Doctrine wiring]

### Pattern 3: classify() / apply() Surface on SharedEntityCopier

**What:** Two public methods with distinct modes; one shared internal core.

```php
// Source: derived from doSync() analysis [VERIFIED: codebase — SharedEntitySyncSubscriber.php:327-402]

/**
 * Classify a single landlord row vs the current tenant's copy.
 * NO flush. Read-only. Used by --dry-run and the live-run confirmation summary.
 *
 * @param array<string, mixed> $landlordIds  e.g. ['id' => 42]
 * @return 'insert'|'update'|'in-sync'
 */
public function classifyRow(
    EntityManagerInterface $landlordEm,
    EntityManagerInterface $tenantEm,
    object $entity,
): string {
    $class = $entity::class;
    $landlordMeta = $landlordEm->getClassMetadata($class);
    $ids = $landlordMeta->getIdentifierValues($entity);

    $existing = $tenantEm->find($class, $ids);
    if (null === $existing) {
        return 'insert';
    }

    // Compare scalar fields to detect drift
    $tenantMeta = $tenantEm->getClassMetadata($class);
    foreach ($landlordMeta->getFieldNames() as $field) {
        if ($landlordMeta->getFieldValue($entity, $field)
            !== $tenantMeta->getFieldValue($existing, $field)) {
            return 'update';
        }
    }

    return 'in-sync';
}

/**
 * Apply a single landlord row to the tenant EM (find-or-new + scalar copy + flush).
 * Sets $syncInProgress around the flush call.
 */
public function applyRow(
    EntityManagerInterface $landlordEm,
    EntityManagerInterface $tenantEm,
    object $entity,
    string $type = 'insert', // 'insert' | 'update'
): void { ... }
```

A `SyncDiffResult` value object (or a simple struct with three int counters) aggregates the classify output per tenant, per class. The command collects these into the summary table.

### Pattern 4: #[Shared] Class Discovery (D-07)

```php
// Source: verified from SharedEntitySyncSubscriber::isShared() [VERIFIED: codebase]
/** @var list<class-string> $sharedClasses */
$sharedClasses = [];
foreach ($landlordEm->getMetadataFactory()->getAllMetadata() as $metadata) {
    $refl = $metadata->reflClass;
    if (null !== $refl && [] !== $refl->getAttributes(Shared::class)) {
        $sharedClasses[] = $metadata->getName();
    }
}
```

This is proxy-safe (WR-01) because it uses `ClassMetadata::$reflClass` which always refers to the real mapped class, not the proxy subclass.

### Pattern 5: SymfonyStyle::confirm() under --no-interaction

```php
// Source: verified from TenantMigrateCommandTest.php pattern + Symfony docs [ASSUMED — behavior]
// CommandTester with ['--no-interaction' => true] simulates -n.
// SymfonyStyle::confirm('Proceed?', false) returns the default (false) under non-interactive mode.
// The command must check: if (!$io->confirm(...) && !$input->getOption('force')) { return SUCCESS; }
```

Under `--no-interaction` (`-n`), `SymfonyStyle::confirm()` immediately returns the default value without prompting. With `default=false`, it returns `false`. The command treats a `false` return as "user declined, abort cleanly" — exit `Command::SUCCESS` (the user got the dry-run summary; they just didn't confirm). Under `--force`, `confirm()` is skipped entirely. [CITED: https://symfony.com/doc/current/console/input.html#interactive-mode]

**Testing `confirm()` with `CommandTester`:**

```php
// Source: Symfony CommandTester docs pattern [ASSUMED]
// To simulate user typing 'yes':
$tester->setInputs(['yes']);
$tester->execute(['--tenant' => 'acme']);

// To simulate -n (non-interactive, confirm() returns default false):
$tester->execute([], ['interactive' => false]);
```

### Anti-Patterns to Avoid

- **Using `merge()`:** Removed in Doctrine ORM 3.0; the bundle supports ORM 3.x. Use find-or-new (already the pattern). [VERIFIED: codebase — SharedEntitySyncSubscriber.php comment line 321]
- **Calling `resetManager()` inside the command's apply loop per entity:** Only call `resetManager()` once per tenant (same reason as WR-04 in subscriber) — the identity map stays warm across entities in one tenant's batch.
- **Setting `$syncInProgress` at the command level (not in the copier's flush path):** If the flag is set outside `flush()`, a failure mid-flush could leave the flag set permanently, allowing unguarded writes in subsequent code. Always reset in `finally` inside `apply()`.
- **Reflecting `new \ReflectionClass($entity)` instead of `$metadata->reflClass`:** Proxy safety issue (WR-01). Always use `$em->getClassMetadata($entity::class)->reflClass`.
- **Not calling `close()` on the tenant DBAL connection between tenants:** Without `close()`, the socket stays open against the prior tenant's DB. `BootstrapperChain::boot()` handles this via `DatabaseSwitchBootstrapper` which calls `TenantConnection::switchTenant()` → `close()`. This is why using `BootstrapperChain::boot()/clear()` (not manual `switchToTenant()`) is correct for the command.
- **Calling `findAll()` per entity class per tenant:** Materialize landlord rows once per class, then loop over tenants — not `tenants × classes × rows × findAll()`.
- **Keeping the `confirm()` prompt inside `--dry-run` mode:** Dry-run should always exit cleanly without prompting. Only live run prompts.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Idempotent upsert across DBs | Custom merge/upsert SQL | `SharedEntityCopier::applyRow()` (extracted from existing `doSync()`) | PK-preservation trick, GENERATOR_TYPE_NONE, scalar-only copy — already proven correct with test coverage from Phase 25 |
| CLI input/output formatting | Custom writeln/table builders | `SymfonyStyle` | Already used in `TenantMigrateCommand`; provides `confirm()`, `table()`, `writeln()` with styles |
| Per-tenant EM lifecycle | Manual `TenantContext::setTenant()` + `registry->getConnection()->close()` | `BootstrapperChain::boot()` / `clear()` | Established pattern in `TenantMigrateCommand`; chains all bootstrappers in correct order |
| Command registration | Manual symfony/console wiring | `#[AsCommand]` + `config/services.php` service definition | Pattern used by all existing commands in this bundle |
| Non-interactive confirmation | `fgets(STDIN)` | `SymfonyStyle::confirm()` with `CommandTester::setInputs()` for tests | Symfony-standard; handles `-n` transparently |
| Proxy-safe attribute check | `new \ReflectionClass($entity)->getAttributes()` | `$em->getClassMetadata($class)->reflClass->getAttributes()` | WR-01: proxy subclasses don't inherit PHP attributes |

**Key insight:** 90% of the complexity in this phase is in logic that already exists in `SharedEntitySyncSubscriber::doSync()` and `isShared()`. The copier extraction is refactoring, not new implementation.

---

## Critical Landmine — Write-Protection Bypass (MUST READ)

### Current wiring (Phase 25)

```
SharedEntityWriteProtectionListener
  ↓ constructor dep: SharedEntitySyncSubscriber
  ↓ calls: $this->syncSubscriber->isSyncInProgress()
```

`isSyncInProgress()` is a public method on `SharedEntitySyncSubscriber`. `$syncInProgress` is a private bool set around `$tenantEm->flush()` in `applyChange()`.

### After extraction (Phase 26)

The `$syncInProgress` flag MUST move to `SharedEntityCopier` because:
1. The command calls `SharedEntityCopier::applyRow()` directly (not via the subscriber).
2. Without moving the flag, the command's writes to tenant EMs will trip `SharedEntityWriteProtectionListener` and throw `SharedEntityWriteInTenantContextException` on every upsert.

**Minimum viable refactor:**

```
SharedEntityCopier
  - private bool $syncInProgress = false
  + public isSyncInProgress(): bool
  + applyRow() sets/clears the flag around flush

SharedEntityWriteProtectionListener
  - constructor: was SharedEntitySyncSubscriber $syncSubscriber
  + constructor: SharedEntityCopier $copier
  - calls: $this->copier->isSyncInProgress()

SharedEntitySyncSubscriber
  + constructor: SharedEntityCopier $copier
  - delegates doSync() logic to $this->copier->applyRow()
  - delegates isShared() to $this->copier (or keeps private — isShared is also used in onFlush)
  - removes $syncInProgress entirely (owned by copier now)
  - removes doSync() (delegated)
```

**DI wiring in TenancyBundle::loadExtension() after refactor:**

```php
// [VERIFIED: codebase — TenancyBundle.php lines 271-288]
// Current:
$services->set('tenancy.shared_entity_write_protection', SharedEntityWriteProtectionListener::class)
    ->args([
        service('tenancy.context'),
        service('tenancy.shared_entity_sync_subscriber'),  // ← changes to copier
    ]);

// After:
$services->set('tenancy.shared_entity_copier', SharedEntityCopier::class)
    ->args([...]);

$services->set('tenancy.shared_entity_sync_subscriber', SharedEntitySyncSubscriber::class)
    ->args([
        service('tenancy.context'),
        service('tenancy.provider'),
        service('doctrine'),
        service('logger'),
        param('tenancy.driver'),
        service('tenancy.shared_entity_copier'),   // ← new
    ]);

$services->set('tenancy.shared_entity_write_protection', SharedEntityWriteProtectionListener::class)
    ->args([
        service('tenancy.context'),
        service('tenancy.shared_entity_copier'),   // ← changed from subscriber to copier
    ]);
```

**Phase 25 test suite green guarantee:** The Phase 25 integration tests (`SharedEntitySyncIntegrationTest`) test behavior (fan-out produces copies, write-protection throws, bypass works) — not the internal `isSyncInProgress()` implementation. After extraction, all observable behaviors remain identical. The one test that explicitly calls `isSyncInProgress()` is in `SharedEntitySyncSubscriberSharedDbTest.php` (unit test — it accesses `$subscriber->isSyncInProgress()`). This test will need to be updated to call `$copier->isSyncInProgress()` after extraction, OR the subscriber can retain a delegating `isSyncInProgress()` method that calls through to the copier (backward-compatible shim). Either approach keeps the test green. [VERIFIED: codebase — tests/Unit/Subscriber/SharedEntitySyncSubscriberSharedDbTest.php]

---

## Common Pitfalls

### Pitfall 1: syncInProgress flag not moved to copier
**What goes wrong:** Command calls `SharedEntityCopier::applyRow()` → `$tenantEm->flush()` → `onFlush` fires on tenant EM → `SharedEntityWriteProtectionListener::onFlush()` → `$this->syncSubscriber->isSyncInProgress()` returns `false` (subscriber flag, not copier) → throws `SharedEntityWriteInTenantContextException` on every resync write.
**Why it happens:** Forgetting that the write-protection listener must consult the copier's flag, not the subscriber's.
**How to avoid:** `SharedEntityWriteProtectionListener` must depend on `SharedEntityCopier`, not `SharedEntitySyncSubscriber`, after extraction. The copier owns the flag.
**Warning signs:** Every `applyRow()` call throws `SharedEntityWriteInTenantContextException` during integration testing.

### Pitfall 2: BootstrapperChain::boot() not called before accessing tenant EM
**What goes wrong:** Command accesses `$registry->getManager('tenant')` without calling `BootstrapperChain::boot($tenant)` first. The DBAL connection placeholder is still pointing at the placeholder SQLite path, not the tenant's DB.
**Why it happens:** The migrate command calls `$this->tenantContext->setTenant($tenant); $this->bootstrapperChain->boot($tenant);` at the start of each tenant iteration. The resync command must do the same.
**How to avoid:** Follow `TenantMigrateCommand::runMigrationsForTenant()` exactly: `setTenant` + `boot()` first.
**Warning signs:** All tenant EMs resolve to the same placeholder DB, or connection errors when tenant DB files don't exist.

### Pitfall 3: GENERATOR_TYPE_NONE not applied in classify mode
**What goes wrong:** `classifyRow()` calls `$tenantEm->find()` to get the existing copy — no flush, so no generator issue. But if `classify()` accidentally creates a `newInstance()` entity and hands it to the EM without a flag, a later flush could misassign the PK.
**Why it happens:** Classify-mode should be purely read-only: `find()` only, no `persist()`, no `newInstance()`. The GENERATOR_TYPE_NONE trick only applies in `applyRow()` on the insert path.
**How to avoid:** `classifyRow()` must call `$tenantEm->find()` only; no entity creation; no side effects on the EM's metadata or state.
**Warning signs:** Identity map pollution during dry-run.

### Pitfall 4: Not clearing TenantContext after --dry-run tenant switch
**What goes wrong:** `classifyRow()` may switch tenant context (to read the tenant copy) during the classify pass. If context is not restored after the classify loop, the subsequent live-run pass starts with stale context.
**Why it happens:** classify mode also needs tenant EM access, which requires setting the tenant context.
**How to avoid:** The classify pass must also use `BootstrapperChain::boot()/clear()` OR restore context via `TenantContext::clear()` + `registry->resetManager('tenant')` after each tenant in the classify loop. The simplest approach: run one combined classify+apply pass per tenant (classify for summary display, then apply immediately if --force or user confirmed), so the tenant switch happens once per tenant total.
**Warning signs:** Wrong tenant's data showing up in subsequent tenant passes.

### Pitfall 5: SymfonyStyle::confirm() not bypassed under --force
**What goes wrong:** Command always prompts even when `--force` is set, breaking CI/unattended use.
**How to avoid:** `if ($input->getOption('force') || $io->confirm('Proceed?', false)) { /* apply */ }`. Check `--force` first, short-circuit `confirm()`.
**Warning signs:** CI runs hang waiting for stdin.

### Pitfall 6: confirm() returning false treated as FAILURE instead of SUCCESS
**What goes wrong:** User runs `--dry-run`, sees drift summary, declines to proceed — command exits FAILURE. This is wrong: the user got the information they wanted and made an intentional choice.
**How to avoid:** Exit `Command::SUCCESS` on user-declined confirmation. Only exit `FAILURE` on actual sync failures (per-tenant exceptions).

### Pitfall 7: Not resetting the tenant EM between class iterations for the same tenant
**What goes wrong:** After syncing class A into tenant EM, the EM has class A entities in its identity map. When syncing class B, old class A proxies can interfere if class B references class A associations.
**Why it happens:** Cascade depth is 1 — associations are not copied — so this is mostly benign, but identity map can grow unbounded for very large landlord catalogs.
**How to avoid:** Between class iterations for the same tenant, optionally `$tenantEm->clear($class)` after flush to keep memory bounded. Not strictly required for correctness at Phase 26 scale.

---

## Code Examples

### Enumeration Pattern (D-07)

```php
// Source: derived from SharedEntitySyncSubscriber::isShared() [VERIFIED: codebase]
use Tenancy\Bundle\Attribute\Shared;

/** @var list<class-string> $sharedClasses */
$sharedClasses = [];
foreach ($landlordEm->getMetadataFactory()->getAllMetadata() as $metadata) {
    $refl = $metadata->reflClass;
    if (null !== $refl && [] !== $refl->getAttributes(Shared::class)) {
        $sharedClasses[] = $metadata->getName();
    }
}
```

### TenantMigrateCommand Continue-on-Failure Loop (exact analog for D-06)

```php
// Source: src/Command/TenantMigrateCommand.php lines 97-112 [VERIFIED: codebase]
foreach ($tenants as $tenant) {
    try {
        $this->runMigrationsForTenant($tenant, $this->migrationsConfig, $io);
        $io->writeln(sprintf(' <info>✓</info> %s', $tenant->getSlug()));
    } catch (\Throwable $e) {
        $failures[] = $tenant->getSlug();
        $io->writeln(sprintf(' <error>✗</error> %s (%s)', $tenant->getSlug(), $e->getMessage()));
    } finally {
        $this->tenantContext->clear();
        $this->bootstrapperChain->clear();
    }
}
```

### switchToTenant in TenantMigrateCommand (the correct CLI pattern)

```php
// Source: src/Command/TenantMigrateCommand.php lines 126-129 [VERIFIED: codebase]
private function runMigrationsForTenant(TenantInterface $tenant, ...): void
{
    $this->tenantContext->setTenant($tenant);
    $this->bootstrapperChain->boot($tenant);
    // ... now $this->tenantConnection is switched to tenant's DB
}
```

### GENERATOR_TYPE_NONE PK Preservation (CR-01)

```php
// Source: src/Subscriber/SharedEntitySyncSubscriber.php lines 394-397 [VERIFIED: codebase]
if ($tenantMeta->isIdGeneratorIdentity()
    || (isset($tenantMeta->idGenerator) && $tenantMeta->idGenerator->isPostInsertGenerator())) {
    $tenantMeta->setIdGeneratorType(\Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_NONE);
}
```

### CommandTester patterns for testing confirm() and --force

```php
// Source: established Symfony CommandTester pattern [ASSUMED]

// Test --force (skip confirmation):
$tester->execute(['--force' => true]);

// Test user confirming (interactive mode with setInputs):
$tester->setInputs(['yes']);
$tester->execute([]);

// Test --no-interaction (confirm() returns false = aborts cleanly):
$tester->execute([], ['interactive' => false]);
// Expect SUCCESS (user-declined, not an error)

// Test --dry-run (no writes, exits SUCCESS):
$tester->execute(['--dry-run' => true, '--force' => true]);
```

### Service Registration Pattern (Doctrine-guarded, from TenancyBundle)

```php
// Source: src/TenancyBundle.php lines 271-288 [VERIFIED: codebase]
if (interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
    // Both copier and command registered here, inside database.enabled block
    $services->set('tenancy.shared_entity_copier', SharedEntityCopier::class)
        ->args([...]);

    $services->set('tenancy.command.shared_resync', SharedEntityResyncCommand::class)
        ->args([...])
        ->tag('console.command');
}
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `EntityManager::merge()` for idempotent upsert | find-or-new + scalar copy | ORM 3.0 (merge() removed) | The REQUIREMENTS.md "merge() semantics" wording is superseded by D-02; find-or-new is the bundle's established pattern |
| `SymfonyStyle::confirm()` under `-n` | Returns default immediately (no prompt) | Always | Default-No (`false`) means `-n` without `--force` aborts cleanly |

**Deprecated/outdated:**
- `EntityManager::merge()`: removed in Doctrine ORM 3.0. All references in REQUIREMENTS.md §SHARE-02 to "uses `merge()` semantics" are superseded by Phase 25's established find-or-new pattern, locked in D-02.

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `SymfonyStyle::confirm()` returns the default value (not throwing) under `--no-interaction` | Pattern 5, Pitfall 6 | If it throws instead of returning the default, the abort-cleanly behavior breaks; mitigate: add explicit `$input->isInteractive()` check before calling confirm() |
| A2 | `CommandTester::setInputs(['yes'])` correctly simulates interactive confirmation for `SymfonyStyle::confirm()` | Code Examples section | If CommandTester does not pipe stdin correctly to SymfonyStyle, the confirmation test would hang or always return default; verify in Wave 0 test setup |
| A3 | `BootstrapperChain::boot($tenant)` is idempotent / safe to call with no prior tenant context (CLI cold start) | Pattern 2 | BootstrapperChain has no guard for "already booted" — calling `boot()` in a loop is the established TenantMigrateCommand pattern; if a bootstrapper has state issues on repeated cold-start calls, integration tests will catch it |

**If this table is empty:** All claims were verified. A1-A3 are narrow behavior assumptions verified against the codebase pattern but not confirmed against Symfony internals docs.

---

## Open Questions (RESOLVED)

1. **`isShared()` ownership after extraction**
   - What we know: `isShared()` is currently private to `SharedEntitySyncSubscriber` and is also needed in `onFlush()` to buffer changes. The copier needs the same check.
   - What's unclear: Should `isShared()` live on the copier (passed entity + em), or stay on the subscriber (which calls the copier only for upsert/classify)?
   - Recommendation: Move `isShared()` to `SharedEntityCopier` as a public static or instance method — it is pure metadata inspection with no subscriber-specific state. The subscriber's `onFlush()` calls `$this->copier->isShared($entity, $em)`.

2. **`SharedEntitySyncSubscriberSharedDbTest` backward compatibility**
   - What we know: This test calls `$subscriber->isSyncInProgress()` directly.
   - What's unclear: Should the subscriber retain a delegating `isSyncInProgress()` shim, or should the test be updated?
   - Recommendation: Update the test to call `$copier->isSyncInProgress()`. A delegating shim would keep dead public API on the subscriber indefinitely.

3. **Classify pass vs. apply pass — one or two tenant loops?**
   - What we know: D-04 requires computing the drift summary before prompting. D-03 says classify is read-only.
   - What's unclear: Should the command do classify-all-tenants → prompt → apply-all-tenants (two loops), or classify-one-tenant → apply-one-tenant → next (interleaved)?
   - Recommendation: Two-pass (classify all, summarize, prompt once, apply all). This is cleaner for the UX (single summary table, single prompt) and avoids half-applied state if user declines mid-loop. For large tenant counts a single loop would start applying before the user has seen the full picture.

---

## Environment Availability

Phase 26 is code-only (no new external tools/services needed). All required tools are already installed.

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | All code | ✓ | 8.2+ (project requirement) | — |
| Composer / vendor | Tests | ✓ | Already installed | — |
| doctrine/orm | SharedEntityCopier, command | ✓ | Already in vendor (optional dep, guarded) | — |
| symfony/console | Command | ✓ | ^7.x (hard require) | — |
| phpunit/phpunit | Tests | ✓ | ^11 (require-dev) | — |

**Missing dependencies with no fallback:** none.

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11 |
| Config file | `phpunit.xml` |
| Quick run command | `vendor/bin/phpunit --testsuite unit` |
| Full suite command | `vendor/bin/phpunit` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| SHARE-02-a | `#[Shared]` classes enumerated via landlord metadata (D-07) | unit | `vendor/bin/phpunit tests/Unit/Shared/SharedEntityCopierTest.php -x` | ❌ Wave 0 |
| SHARE-02-b | `--dry-run` classifies each row as insert/update/in-sync, no flush | unit | `vendor/bin/phpunit tests/Unit/Command/SharedEntityResyncCommandTest.php -x` | ❌ Wave 0 |
| SHARE-02-c | Live run: drift summary printed, `confirm()` called, default-No aborts cleanly | unit | `vendor/bin/phpunit tests/Unit/Command/SharedEntityResyncCommandTest.php -x` | ❌ Wave 0 |
| SHARE-02-d | `--force` skips confirmation, proceeds immediately | unit | `vendor/bin/phpunit tests/Unit/Command/SharedEntityResyncCommandTest.php -x` | ❌ Wave 0 |
| SHARE-02-e | `shared_db` driver → informational no-op, exits SUCCESS | unit | `vendor/bin/phpunit tests/Unit/Command/SharedEntityResyncCommandTest.php -x` | ❌ Wave 0 |
| SHARE-02-f | Continue-on-failure: one tenant fails, others succeed, exits FAILURE | unit | `vendor/bin/phpunit tests/Unit/Command/SharedEntityResyncCommandTest.php -x` | ❌ Wave 0 |
| SHARE-02-g | `TenantContext::clear()` + `BootstrapperChain::clear()` called in finally | unit | `vendor/bin/phpunit tests/Unit/Command/SharedEntityResyncCommandTest.php -x` | ❌ Wave 0 |
| SHARE-02-h | Idempotency: re-running after full sync produces no duplicates | integration | `vendor/bin/phpunit tests/Integration/SharedEntity/SharedEntityResyncCommandIntegrationTest.php -x` | ❌ Wave 0 |
| SHARE-02-i | Write-protection bypass: copier writes to tenant EM without throwing | integration | `vendor/bin/phpunit tests/Integration/SharedEntity/SharedEntityResyncCommandIntegrationTest.php -x` | ❌ Wave 0 |
| SHARE-02-j | SHARE-01 subscriber still works after copier extraction | integration | `vendor/bin/phpunit tests/Integration/SharedEntity/SharedEntitySyncIntegrationTest.php` | ✅ (existing Phase 25) |
| SHARE-02-k | `--tenant=<slug>` targets single tenant only | unit | `vendor/bin/phpunit tests/Unit/Command/SharedEntityResyncCommandTest.php -x` | ❌ Wave 0 |
| SHARE-02-l | Drift classification correctness: in-sync rows not counted as update | integration | `vendor/bin/phpunit tests/Integration/SharedEntity/SharedEntityResyncCommandIntegrationTest.php -x` | ❌ Wave 0 |

### Sampling Rate

- **Per task commit:** `vendor/bin/phpunit --testsuite unit` (fast, ~5s)
- **Per wave merge:** `vendor/bin/phpunit` (full suite including integration)
- **Phase gate:** Full suite green before `/gsd:verify-work`

### Wave 0 Gaps

- [ ] `tests/Unit/Shared/SharedEntityCopierTest.php` — covers SHARE-02-a, classify correctness
- [ ] `tests/Unit/Command/SharedEntityResyncCommandTest.php` — covers SHARE-02-b through SHARE-02-g, SHARE-02-k
- [ ] `tests/Integration/SharedEntity/SharedEntityResyncCommandIntegrationTest.php` — covers SHARE-02-h, SHARE-02-i, SHARE-02-l (full SQLite kernel, mirroring `SharedEntitySyncIntegrationTest`)
- [ ] `tests/Integration/SharedEntity/Support/MakeSharedEntityResyncServicesPublicPass.php` — expose new services for test container access (mirrors `MakeSharedEntityServicesPublicPass`)

**Kernel reuse:** `SharedEntityFailureLoggingTestKernel` (which extends `SharedEntitySyncTestKernel`) is the correct base for integration tests — it already has landlord + two tenant SQLite DBs and the `RecordingLogger`. No new kernel needed; the command service needs to be made public via a new or updated `MakeSharedEntityServicesPublicPass`.

---

## Security Domain

The `tenancy:shared:resync` command is a write-intensive CLI tool that upserts data from the landlord DB into ALL tenant DBs. Applicable ASVS controls:

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | No (CLI, no HTTP auth) | — |
| V3 Session Management | No | — |
| V4 Access Control | Yes — CLI access = system-level privilege | Standard OS-level access control (run as deploy user only); not a bundle concern |
| V5 Input Validation | Yes — `--tenant=<slug>` input | `TenantProviderInterface::findBySlug()` validates slug exists; `TenantNotFoundException` caught + failure returned |
| V6 Cryptography | No | — |

| Threat Pattern | STRIDE | Mitigation |
|----------------|--------|-----------|
| Cross-tenant data write (wrong EM) | Tampering | `BootstrapperChain::boot()` wires the DBAL connection to the correct tenant before any writes; `TenantContext` enforces single active tenant |
| Write-protection bypass permanence (flag leak) | Tampering | `syncInProgress = false` in `finally` inside `applyRow()` — resets even if flush throws |
| Orphan data from interrupted resync | Information Disclosure | Additive-only (no deletes); orphans are out of scope per CONTEXT deferred |
| Accidental mass-write to all tenants | Tampering | `SymfonyStyle::confirm()` default-No guard; `--force` makes intent explicit |

---

## Sources

### Primary (HIGH confidence)

- `src/Subscriber/SharedEntitySyncSubscriber.php` — full implementation of `doSync()`, `isShared()`, `switchToTenant()`, `$syncInProgress` flag [VERIFIED: codebase]
- `src/Subscriber/SharedEntityWriteProtectionListener.php` — `isSyncInProgress()` call site [VERIFIED: codebase]
- `src/Command/TenantMigrateCommand.php` — exact CLI shape to mirror (D-01, D-06 patterns) [VERIFIED: codebase]
- `src/TenancyBundle.php` — Doctrine-guarded service registration pattern, `database.enabled` block structure [VERIFIED: codebase]
- `config/services.php` — service registration conventions [VERIFIED: codebase]
- `tests/Integration/SharedEntity/SharedEntitySyncIntegrationTest.php` — Phase 25 test suite that must stay green (reference for integration test patterns) [VERIFIED: codebase]
- `tests/Unit/Command/TenantMigrateCommandTest.php` — CommandTester patterns, continue-on-failure test structure [VERIFIED: codebase]

### Secondary (MEDIUM confidence)

- `tests/Integration/SharedEntity/Support/SharedEntitySyncTestKernel.php` — kernel pattern for new integration test support class [VERIFIED: codebase]
- `tests/Unit/Subscriber/SharedEntitySyncSubscriberSharedDbTest.php` — existing test calling `isSyncInProgress()` that needs updating after extraction [VERIFIED: codebase]
- Symfony Console documentation on `SymfonyStyle::confirm()` under non-interactive mode [CITED: https://symfony.com/doc/current/console/input.html] [ASSUMED behavior under CommandTester]

### Tertiary (LOW confidence)

- None.

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — all libraries are already installed, patterns verified in codebase
- Architecture: HIGH — copier extraction design derived directly from existing code; all integration points verified
- Pitfalls: HIGH — pitfalls derived from existing Phase 25 code comments (WR-01, WR-02, WR-03, WR-04, CR-01, CR-02) and unit test patterns
- Write-protection bypass: HIGH — exact call sites and DI wiring verified in source

**Research date:** 2026-06-12
**Valid until:** 2026-07-12 (stable internal codebase; no external dependency updates expected)
