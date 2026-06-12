# Phase 26: tenancy-shared-resync-command — Pattern Map

**Mapped:** 2026-06-12
**Files analyzed:** 9 (3 new production, 2 modified production, 4 new test)
**Analogs found:** 9 / 9

---

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `src/Shared/SharedEntityCopier.php` | service | CRUD + transform | `src/Subscriber/SharedEntitySyncSubscriber.php` | exact (extracted from it) |
| `src/Command/SharedEntityResyncCommand.php` | command | batch + request-response | `src/Command/TenantMigrateCommand.php` | exact |
| `src/Subscriber/SharedEntitySyncSubscriber.php` | subscriber | event-driven | self | modified |
| `src/Subscriber/SharedEntityWriteProtectionListener.php` | listener | event-driven | self | modified |
| `src/TenancyBundle.php` | config | DI wiring | self | modified |
| `tests/Unit/Shared/SharedEntityCopierTest.php` | test | unit | `tests/Unit/Command/TenantMigrateCommandTest.php` | role-match |
| `tests/Unit/Command/SharedEntityResyncCommandTest.php` | test | unit | `tests/Unit/Command/TenantMigrateCommandTest.php` | exact |
| `tests/Integration/SharedEntity/SharedEntityResyncCommandIntegrationTest.php` | test | integration | `tests/Integration/SharedEntity/SharedEntitySyncIntegrationTest.php` | exact |
| `tests/Integration/SharedEntity/Support/MakeSharedEntityResyncServicesPublicPass.php` | test-support | DI compiler pass | `tests/Integration/SharedEntity/Support/MakeSharedEntityServicesPublicPass.php` | exact |

---

## Pattern Assignments

### `src/Shared/SharedEntityCopier.php` (service, CRUD + transform)

**Analog:** `src/Subscriber/SharedEntitySyncSubscriber.php` (extraction source)

**Imports pattern** (lines 1-18):
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Shared;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Tenancy\Bundle\Attribute\Shared;
```

**Core upsert pattern — find-or-new + scalar copy** (SharedEntitySyncSubscriber lines 361-401):
```php
// insert or update: find-or-new + scalar field copy
$ids = $landlordMeta->getIdentifierValues($entity);
$existing = $tenantEm->find($class, $ids);
$tenantMeta = $tenantEm->getClassMetadata($class);

$isInsert = null === $existing;
$copy = $isInsert ? $tenantMeta->newInstance() : $existing;

// Copy scalar fields only — associations intentionally skipped (DEC-SHARE-02)
foreach ($landlordMeta->getFieldNames() as $fieldName) {
    $value = $landlordMeta->getFieldValue($entity, $fieldName);
    $tenantMeta->setFieldValue($copy, $fieldName, $value);
}

if ($isInsert) {
    // CR-01: GENERATOR_TYPE_NONE forces the copied landlord id to be authoritative
    if ($tenantMeta->isIdGeneratorIdentity()
        || (isset($tenantMeta->idGenerator) && $tenantMeta->idGenerator->isPostInsertGenerator())) {
        $tenantMeta->setIdGeneratorType(\Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_NONE);
    }
}

$tenantEm->persist($copy);
$tenantEm->flush();
```

**syncInProgress flag pattern — set/reset around flush** (SharedEntitySyncSubscriber lines 78, 182-193):
```php
private bool $syncInProgress = false;

// In applyRow() — flag owns the flush boundary, always reset in finally:
$this->syncInProgress = true;
try {
    $tenantEm->persist($copy);
    $tenantEm->flush();
} finally {
    $this->syncInProgress = false;
}

public function isSyncInProgress(): bool
{
    return $this->syncInProgress;
}
```

**isShared() proxy-safe attribute check — WR-01** (SharedEntitySyncSubscriber lines 414-418):
```php
// WR-01: reflect the REAL mapped class via ClassMetadata::$reflClass, not new \ReflectionClass($entity)
private function isShared(object $entity, EntityManagerInterface $em): bool
{
    $refl = $em->getClassMetadata($entity::class)->reflClass;

    return null !== $refl && [] !== $refl->getAttributes(Shared::class);
}
```

**classify (dry-run) pattern** (derived from doSync lines 361-377, read-only only):
```php
// classifyRow(): read-only, no flush, no entity creation
// Returns 'insert'|'update'|'in-sync'
$ids = $landlordMeta->getIdentifierValues($entity);
$existing = $tenantEm->find($class, $ids);
if (null === $existing) {
    return 'insert';
}
foreach ($landlordMeta->getFieldNames() as $field) {
    if ($landlordMeta->getFieldValue($entity, $field)
        !== $tenantMeta->getFieldValue($existing, $field)) {
        return 'update';
    }
}
return 'in-sync';
```

**#[Shared] class enumeration from metadata — D-07** (derived from isShared):
```php
/** @var list<class-string> $sharedClasses */
$sharedClasses = [];
foreach ($landlordEm->getMetadataFactory()->getAllMetadata() as $metadata) {
    $refl = $metadata->reflClass;
    if (null !== $refl && [] !== $refl->getAttributes(Shared::class)) {
        $sharedClasses[] = $metadata->getName();
    }
}
```

**Delete branch** (SharedEntitySyncSubscriber lines 337-357):
```php
if ('delete' === $type) {
    if (null === $capturedIds || [] === $capturedIds) {
        $this->logger->error('tenancy.shared_entity_sync_missing_delete_id', [
            'entity_class' => $class,
        ]);
        return;
    }
    $existing = $tenantEm->find($class, $capturedIds);
    if (null !== $existing) {
        $tenantEm->remove($existing);
        $tenantEm->flush();
    }
    return;
}
```

---

### `src/Command/SharedEntityResyncCommand.php` (command, batch)

**Analog:** `src/Command/TenantMigrateCommand.php`

**Imports + class declaration pattern** (TenantMigrateCommand lines 1-26):
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;

#[AsCommand(name: 'tenancy:shared:resync', description: 'Re-sync all #[Shared] entities to target tenant(s)')]
final class SharedEntityResyncCommand extends Command
{
    public function __construct(
        private readonly TenantProviderInterface $tenantProvider,
        private readonly BootstrapperChain $bootstrapperChain,
        private readonly TenantContext $tenantContext,
        private readonly string $driver,
        // + landlordEm, registry, copier
    ) {
        parent::__construct();
    }
```

**Option configuration — D-01 mirrors TenantMigrateCommand** (TenantMigrateCommand lines 39-46):
```php
protected function configure(): void
{
    $this->addOption(
        'tenant',
        null,
        InputOption::VALUE_OPTIONAL,
        'Re-sync a single tenant only',
    );
    // Plus: --dry-run (VALUE_NONE), --force (VALUE_NONE)
}
```

**shared_db no-op guard — D-05** (TenantMigrateCommand lines 57-66, but exit SUCCESS not FAILURE):
```php
if ('shared_db' === $this->driver) {
    $io->writeln('<info>tenancy:shared:resync is a no-op under the shared_db driver.</info>');
    $io->writeln('Under shared_db, #[Shared] entities exist once in the single shared DB — no per-tenant copies needed.');
    return Command::SUCCESS;  // NOTE: SUCCESS, not FAILURE (D-05)
}
```

**Tenant resolution pattern** (TenantMigrateCommand lines 74-92):
```php
$tenantSlug = $input->getOption('tenant');

if (null !== $tenantSlug && \is_string($tenantSlug)) {
    try {
        $tenants = [$this->tenantProvider->findBySlug($tenantSlug)];
    } catch (\Tenancy\Bundle\Exception\TenantNotFoundException|\Tenancy\Bundle\Exception\TenantInactiveException $e) {
        $io->error($e->getMessage());
        return Command::FAILURE;
    }
} else {
    $tenants = $this->tenantProvider->findAll();
}

if ([] === $tenants) {
    $io->writeln('No tenants found.');
    return Command::SUCCESS;
}
```

**Continue-on-failure loop — D-06** (TenantMigrateCommand lines 94-122):
```php
/** @var string[] $failures */
$failures = [];

foreach ($tenants as $tenant) {
    try {
        $this->resyncForTenant($tenant, $io);
        $io->writeln(sprintf(' <info>✓</info> %s', $tenant->getSlug()));
    } catch (\Throwable $e) {
        $failures[] = $tenant->getSlug();
        $io->writeln(sprintf(' <error>✗</error> %s (%s)', $tenant->getSlug(), $e->getMessage()));
    } finally {
        $this->tenantContext->clear();
        $this->bootstrapperChain->clear();
    }
}

$succeeded = count($tenants) - count($failures);
$io->writeln(sprintf('Completed: %d succeeded, %d failed', $succeeded, count($failures)));

if ([] !== $failures) {
    $io->writeln('Failed tenants:');
    foreach ($failures as $slug) {
        $io->writeln(sprintf('  - %s', $slug));
    }
    return Command::FAILURE;
}

return Command::SUCCESS;
```

**BootstrapperChain tenant switch — D-06** (TenantMigrateCommand lines 125-128):
```php
private function resyncForTenant(TenantInterface $tenant, SymfonyStyle $io): void
{
    $this->tenantContext->setTenant($tenant);
    $this->bootstrapperChain->boot($tenant);
    // Now retrieve tenant EM via $this->registry->getManager('tenant')
}
```

**--force / confirm() pattern — D-04**:
```php
// After computing drift summary for all tenants:
if (!$input->getOption('dry-run')) {
    if (!$input->getOption('force') && !$io->confirm('Proceed with live resync?', false)) {
        // User declined or -n without --force — clean abort
        return Command::SUCCESS;
    }
    // apply pass
}
```

---

### `src/Subscriber/SharedEntitySyncSubscriber.php` (modified)

**What changes:** Constructor gains `SharedEntityCopier $copier`. The `$syncInProgress` bool and `isSyncInProgress()` method are REMOVED (owned by copier). `doSync()` method body is replaced by `$this->copier->applyRow(...)`. `isShared()` either moves to copier (recommended) or delegates to `$this->copier->isShared(...)`.

**Existing constructor pattern** (SharedEntitySyncSubscriber lines 80-87):
```php
public function __construct(
    private readonly TenantContext $tenantContext,
    private readonly TenantProviderInterface $tenantProvider,
    private readonly ManagerRegistry $registry,
    private readonly LoggerInterface $logger,
    private readonly string $driver,
    // ADD: private readonly SharedEntityCopier $copier,
) {
}
```

**postFlush syncInProgress usage to remove** (SharedEntitySyncSubscriber lines 182-193):
```php
// BEFORE (to remove):
$this->syncInProgress = true;
try {
    foreach ($changes as $change) {
        $tenantEm = $this->applyChange($landlordEm, $tenantEm, $tenant, $change);
    }
} finally {
    $this->syncInProgress = false;
}

// AFTER (copier owns flag; subscriber just delegates):
foreach ($changes as $change) {
    $tenantEm = $this->applyChange($landlordEm, $tenantEm, $tenant, $change);
}
```

**Backward-compat note for SharedEntitySyncSubscriberSharedDbTest:** The test at line 115 calls `$subscriber->isSyncInProgress()`. After extraction, update the test to call `$copier->isSyncInProgress()` directly — do NOT add a delegating shim to the subscriber.

---

### `src/Subscriber/SharedEntityWriteProtectionListener.php` (modified)

**What changes:** Constructor dependency changes from `SharedEntitySyncSubscriber $syncSubscriber` to `SharedEntityCopier $copier`. The call at line 68 changes accordingly.

**Current constructor** (SharedEntityWriteProtectionListener lines 41-45):
```php
public function __construct(
    private readonly TenantContext $tenantContext,
    private readonly SharedEntitySyncSubscriber $syncSubscriber,  // ← CHANGE TO SharedEntityCopier $copier
) {
}
```

**Current bypass call** (SharedEntityWriteProtectionListener lines 67-70):
```php
// BEFORE:
if ($this->syncSubscriber->isSyncInProgress()) {
    return;
}

// AFTER:
if ($this->copier->isSyncInProgress()) {
    return;
}
```

---

### `src/TenancyBundle.php` (modified — DI wiring)

**What changes:** Inside the `interface_exists(EntityManagerInterface::class)` block (lines 271-289): add `tenancy.shared_entity_copier` registration, add `tenancy.shared_entity_copier` as arg to subscriber, change write-protection listener to depend on copier, add `tenancy.command.shared_resync` registration.

**Current Doctrine-guarded block pattern** (TenancyBundle lines 271-289):
```php
if (interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
    $services->set('tenancy.shared_entity_sync_subscriber', SharedEntitySyncSubscriber::class)
        ->args([
            service('tenancy.context'),
            service('tenancy.provider'),
            service('doctrine'),
            service('logger'),
            param('tenancy.driver'),
        ])
        ->tag('doctrine.event_listener', ['event' => 'onFlush', 'connection' => 'landlord'])
        ->tag('doctrine.event_listener', ['event' => 'postFlush', 'connection' => 'landlord']);

    $services->set('tenancy.shared_entity_write_protection', SharedEntityWriteProtectionListener::class)
        ->args([
            service('tenancy.context'),
            service('tenancy.shared_entity_sync_subscriber'),  // ← CHANGE TO tenancy.shared_entity_copier
        ])
        ->tag('doctrine.event_listener', ['event' => 'onFlush', 'connection' => 'tenant']);
}
```

**After wiring changes — concrete diff to apply:**
```php
if (interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
    // NEW — copier first (subscriber and command both depend on it)
    $services->set('tenancy.shared_entity_copier', SharedEntityCopier::class)
        ->args([
            service('logger'),
        ]);

    $services->set('tenancy.shared_entity_sync_subscriber', SharedEntitySyncSubscriber::class)
        ->args([
            service('tenancy.context'),
            service('tenancy.provider'),
            service('doctrine'),
            service('logger'),
            param('tenancy.driver'),
            service('tenancy.shared_entity_copier'),  // NEW
        ])
        ->tag('doctrine.event_listener', ['event' => 'onFlush', 'connection' => 'landlord'])
        ->tag('doctrine.event_listener', ['event' => 'postFlush', 'connection' => 'landlord']);

    $services->set('tenancy.shared_entity_write_protection', SharedEntityWriteProtectionListener::class)
        ->args([
            service('tenancy.context'),
            service('tenancy.shared_entity_copier'),  // CHANGED from sync_subscriber
        ])
        ->tag('doctrine.event_listener', ['event' => 'onFlush', 'connection' => 'tenant']);

    // NEW — resync command (not gated on doctrine/migrations, unlike tenancy:migrate)
    $services->set('tenancy.command.shared_resync', SharedEntityResyncCommand::class)
        ->args([
            service('tenancy.provider'),
            service('tenancy.bootstrapper_chain'),
            service('tenancy.context'),
            param('tenancy.driver'),
            service('doctrine.orm.landlord_entity_manager'),
            service('doctrine'),
            service('tenancy.shared_entity_copier'),
        ])
        ->tag('console.command');
}
```

**Existing gate for TenantMigrateCommand (for comparison)** (TenancyBundle lines 245-256):
```php
if (class_exists(\Doctrine\Migrations\DependencyFactory::class)) {
    $services->set('tenancy.command.migrate', TenantMigrateCommand::class)
        ->args([...])
        ->tag('console.command');
}
```
Note: the resync command does NOT need the `Doctrine\Migrations` gate — it only needs `EntityManagerInterface`.

---

### `tests/Unit/Shared/SharedEntityCopierTest.php` (new unit test)

**Analog:** `tests/Unit/Subscriber/SharedEntitySyncSubscriberSharedDbTest.php`

**Test class pattern** (SharedEntitySyncSubscriberSharedDbTest lines 57-79):
```php
final class SharedEntityCopierTest extends TestCase
{
    // Use createMock(EntityManagerInterface::class) for both landlordEm and tenantEm
    // Use createMock(ClassMetadata::class) to control getFieldNames(), getIdentifierValues(), etc.
    // Test classifyRow() returns 'insert' when find() returns null
    // Test classifyRow() returns 'update' when scalar field differs
    // Test classifyRow() returns 'in-sync' when all fields match
    // Test applyRow() sets syncInProgress=true during flush and false after
    // Test isSyncInProgress() returns false outside of applyRow()
    // Test isShared() proxy-safe check using $metadata->reflClass
}
```

**Mock EM + ClassMetadata pattern** (SharedEntitySyncSubscriberSharedDbTest lines 86-96):
```php
/** @var EntityManagerInterface&MockObject $landlordEm */
$landlordEm = $this->createMock(EntityManagerInterface::class);
$landlordEm->method('getClassMetadata')->willReturn(
    $this->createMock(\Doctrine\ORM\Mapping\ClassMetadata::class)
);
```

---

### `tests/Unit/Command/SharedEntityResyncCommandTest.php` (new unit test)

**Analog:** `tests/Unit/Command/TenantMigrateCommandTest.php`

**Test setUp + makeCommand factory pattern** (TenantMigrateCommandTest lines 22-48):
```php
final class SharedEntityResyncCommandTest extends TestCase
{
    private TenantProviderInterface&MockObject $tenantProvider;
    private BootstrapperChain $bootstrapperChain;
    private TenantContext $tenantContext;
    // + MockObject for landlordEm, registry, copier

    protected function setUp(): void
    {
        $this->tenantProvider = $this->createMock(TenantProviderInterface::class);
        $this->bootstrapperChain = new BootstrapperChain(new EventDispatcher());
        $this->tenantContext = new TenantContext();
    }

    private function makeCommand(string $driver = 'database_per_tenant'): SharedEntityResyncCommand
    {
        return new SharedEntityResyncCommand(
            $this->tenantProvider,
            $this->bootstrapperChain,
            $this->tenantContext,
            $driver,
            $this->landlordEm,
            $this->registry,
            $this->copier,
        );
    }
```

**shared_db exits SUCCESS test** (TenantMigrateCommandTest lines 78-90, note SUCCESS not FAILURE):
```php
public function testSharedDbDriverExitsSuccessWithNoOpMessage(): void
{
    $command = $this->makeCommand('shared_db');
    $tester = new CommandTester($command);

    $exitCode = $tester->execute([]);

    $this->assertSame(Command::SUCCESS, $exitCode);  // D-05: SUCCESS not FAILURE
    $this->assertStringContainsString('no-op', $tester->getDisplay());
}
```

**Continue-on-failure test** (TenantMigrateCommandTest lines 105-169 — exact same spy-bootstrapper pattern):
```php
// Use a spy bootstrapper that throws for 'acme' tenant
$throwingBootstrapper = new class implements TenantBootstrapperInterface {
    public function boot(TenantInterface $tenant): void
    {
        if ('acme' === $tenant->getSlug()) {
            throw new \RuntimeException('Resync failed for acme');
        }
    }
    public function clear(): void {}
};
$bootstrapperChain->addBootstrapper($throwingBootstrapper);
// Expect exitCode = 1, both slugs in output
```

**--force skips confirm() test**:
```php
public function testForceSkipsConfirmation(): void
{
    // $tester->execute(['--force' => true]) must proceed without prompting
    $tester->execute(['--force' => true, '--tenant' => 'acme']);
    // assert writes happened (copier mock expects applyRow calls)
}
```

**--no-interaction aborts cleanly (default-No) test**:
```php
public function testNoInteractionWithoutForceAbortsCleanly(): void
{
    $tester->execute([], ['interactive' => false]);
    $this->assertSame(Command::SUCCESS, $exitCode);
    // assert copier applyRow never called
}
```

**--dry-run no-flush test**:
```php
public function testDryRunNeverCallsApplyRow(): void
{
    $this->copier->expects($this->never())->method('applyRow');
    $tester->execute(['--dry-run' => true]);
    $this->assertSame(Command::SUCCESS, $exitCode);
}
```

**context + bootstrapperChain cleared in finally** (TenantMigrateCommandTest lines 214-261 — exact pattern):
```php
// Spy bootstrapper with clear counter; assert clearCount === 1 after failure
$this->assertSame(1, $spyBootstrapper->clearCount, 'bootstrapperChain->clear() must be called in finally');
$this->assertNull($tenantContext->getTenant(), 'TenantContext must be cleared in finally');
```

**tenant filter --tenant=<slug> uses findBySlug** (TenantMigrateCommandTest lines 171-196):
```php
$this->tenantProvider->expects($this->once())->method('findBySlug')->with('acme');
$this->tenantProvider->expects($this->never())->method('findAll');
$tester->execute(['--tenant' => 'acme']);
```

---

### `tests/Integration/SharedEntity/SharedEntityResyncCommandIntegrationTest.php` (new integration test)

**Analog:** `tests/Integration/SharedEntity/SharedEntitySyncIntegrationTest.php`

**setUpBeforeClass / tearDownAfterClass kernel lifecycle** (SharedEntitySyncIntegrationTest lines 47-114):
```php
final class SharedEntityResyncCommandIntegrationTest extends TestCase
{
    private static ?SharedEntityFailureLoggingTestKernel $kernel = null;
    // Same $landlordPath / $tenantAPath / $tenantBPath / $placeholderPath fields

    public static function setUpBeforeClass(): void
    {
        // Same path setup: self::$landlordPath = sys_get_temp_dir().'/tenancy_shared_test_landlord.db';
        // Same @unlink cleanup
        // Boot SharedEntityFailureLoggingTestKernel (reuse — no new kernel needed per RESEARCH.md)
        // Pre-create schemas on landlord + both tenants (SchemaTool pattern identical)
        // ctx->clear() + registry->resetManager('tenant') before tests start
    }

    public static function tearDownAfterClass(): void
    {
        // $kernel->shutdown(); @unlink all paths
    }
```

**setUp context reset per test** (SharedEntitySyncIntegrationTest lines 104-114):
```php
protected function setUp(): void
{
    $container = self::$kernel->getContainer();
    $ctx = $container->get('tenancy.context');
    $ctx->clear();
    $registry = $container->get('doctrine');
    $registry->resetManager('tenant');
}
```

**Idempotency test pattern** (covering SHARE-02-h):
```php
public function testResyncIsIdempotent(): void
{
    // 1. Insert a TestPlan on landlord EM (bypassing fan-out by using a fresh kernel state)
    // 2. Run command via CommandTester with --force
    // 3. Assert tenant copy exists with correct id (CR-01 key equality)
    // 4. Run command again with --force
    // 5. Assert no duplicate rows (idempotent upsert)
    // Pattern: use CommandTester, not raw command execution
    $command = $container->get('tenancy.command.shared_resync');
    $tester = new CommandTester($command);
    $tester->execute(['--force' => true]);
}
```

**Write-protection bypass test pattern** (covering SHARE-02-i):
```php
public function testResyncWritesBypassesWriteProtection(): void
{
    // Run command against a tenant that has #[Shared] schema
    // Assert no SharedEntityWriteInTenantContextException thrown
    // Assert rows land in tenant DBs
    // Pattern mirrors testSyncWriteBypassesWriteProtection() in SharedEntitySyncIntegrationTest
}
```

**switchTenantManager helper** (SharedEntitySyncIntegrationTest lines 907-918 — copy verbatim):
```php
private function switchTenantManager(ManagerRegistry $registry, TenantContext $ctx, TenantInterface $tenant): EntityManagerInterface
{
    $ctx->setTenant($tenant);
    $conn = $registry->getConnection('tenant');
    if ($conn instanceof \Doctrine\DBAL\Connection) {
        $conn->close();
    }
    return $registry->resetManager('tenant');
}
```

---

### `tests/Integration/SharedEntity/Support/MakeSharedEntityResyncServicesPublicPass.php` (new compiler pass)

**Analog:** `tests/Integration/SharedEntity/Support/MakeSharedEntityServicesPublicPass.php`

**Complete pattern** (MakeSharedEntityServicesPublicPass lines 1-38):
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\SharedEntity\Support;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class MakeSharedEntityResyncServicesPublicPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $ids = [
            'tenancy.shared_entity_copier',
            'tenancy.command.shared_resync',
            'tenancy.shared_entity_sync_subscriber',
            'tenancy.shared_entity_write_protection',
            'tenancy.context',
            'doctrine.orm.landlord_entity_manager',
            'doctrine',
            'doctrine.dbal.tenant_connection',
        ];

        foreach ($ids as $id) {
            if ($container->hasDefinition($id)) {
                $container->getDefinition($id)->setPublic(true);
            } elseif ($container->hasAlias($id)) {
                $container->getAlias($id)->setPublic(true);
            }
        }
    }
}
```

The integration test kernel should extend `SharedEntityFailureLoggingTestKernel` (which already extends `SharedEntitySyncTestKernel`) and add this new pass alongside `InjectRecordingLoggerPass`:
```php
final class SharedEntityResyncCommandIntegrationTestKernel extends SharedEntityFailureLoggingTestKernel
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new MakeSharedEntityResyncServicesPublicPass());
    }
}
```

OR the simpler alternative: update `MakeSharedEntityServicesPublicPass` to also expose the new services (additive, no new class needed).

---

## Shared Patterns

### strict_types + namespace
**Source:** Every file in `src/`
**Apply to:** All new PHP files
```php
<?php

declare(strict_types=1);

namespace Tenancy\Bundle\...;
```

### Doctrine optional guard
**Source:** `src/TenancyBundle.php` lines 211-212, 236, 271
**Apply to:** `src/TenancyBundle.php` wiring of copier + command, `src/Shared/SharedEntityCopier.php` class registration
```php
if (interface_exists(\Doctrine\ORM\EntityManagerInterface::class)) {
    // register copier, subscriber (with copier arg), listener (with copier arg), resync command
}
```

### PSR-3 structured log shape
**Source:** `src/Subscriber/SharedEntitySyncSubscriber.php` lines 275-280
**Apply to:** `SharedEntityCopier::applyRow()` failure logging, `SharedEntityResyncCommand` per-tenant failure logging
```php
$this->logger->error('tenancy.shared_entity_sync_failed', [
    'tenant_slug' => $tenant->getSlug(),
    'entity_class' => $entity::class,
    'identifier' => $identifier,
    'error' => $e->getMessage(),
]);
```

### #[AsCommand] registration
**Source:** `src/Command/TenantMigrateCommand.php` lines 24-25
**Apply to:** `src/Command/SharedEntityResyncCommand.php`
```php
#[AsCommand(name: 'tenancy:shared:resync', description: 'Re-sync all #[Shared] entities to target tenant(s)')]
final class SharedEntityResyncCommand extends Command
```

### TenantContext::clear() + BootstrapperChain::clear() in finally
**Source:** `src/Command/TenantMigrateCommand.php` lines 104-107
**Apply to:** `SharedEntityResyncCommand` per-tenant loop
```php
} finally {
    $this->tenantContext->clear();
    $this->bootstrapperChain->clear();
}
```

### CommandTester + MockObject unit test skeleton
**Source:** `tests/Unit/Command/TenantMigrateCommandTest.php` lines 22-48
**Apply to:** `tests/Unit/Command/SharedEntityResyncCommandTest.php`
```php
private TenantProviderInterface&MockObject $tenantProvider;
private BootstrapperChain $bootstrapperChain;
private TenantContext $tenantContext;

protected function setUp(): void
{
    $this->tenantProvider = $this->createMock(TenantProviderInterface::class);
    $this->bootstrapperChain = new BootstrapperChain(new EventDispatcher());
    $this->tenantContext = new TenantContext();
}
```

---

## No Analog Found

All files have close analogs in the codebase. No file requires falling back to RESEARCH.md-only patterns.

---

## Critical Anti-Patterns (Do NOT Repeat)

| Anti-Pattern | Source | Avoid Because |
|---|---|---|
| `EntityManager::merge()` | REQUIREMENTS.md §SHARE-02 (superseded) | Removed in ORM 3.0; find-or-new is the correct idempotent path (D-02) |
| `new \ReflectionClass($entity)->getAttributes()` | SharedEntitySyncSubscriber WR-01 comment | Proxy subclasses don't inherit PHP attributes; use `$em->getClassMetadata($entity::class)->reflClass` |
| Setting `$syncInProgress` at command level rather than inside `applyRow()` | RESEARCH.md Pitfall 1 | Flag must be reset in `finally` inside the flush boundary; command-level flag can leak if flush throws |
| Calling `resetManager()` per entity inside one tenant's loop | SharedEntitySyncSubscriber WR-04 | Keep identity map warm across entities for one tenant; reset once per tenant switch |
| `$io->confirm()` inside `--dry-run` branch | RESEARCH.md Pitfall 5 | Dry-run exits before the prompt; only live run prompts |
| Exiting `Command::FAILURE` when user declines confirmation | RESEARCH.md Pitfall 6 | Clean decline = SUCCESS; only actual sync failure = FAILURE |
| Keeping `isSyncInProgress()` on the subscriber after extraction | RESEARCH.md Open Q #2 | Moves dead public API to the copier permanently; update the test instead |

---

## Metadata

**Analog search scope:** `src/Command/`, `src/Subscriber/`, `src/TenancyBundle.php`, `tests/Unit/Command/`, `tests/Unit/Subscriber/`, `tests/Integration/SharedEntity/`
**Files scanned:** 9 source files read in full
**Pattern extraction date:** 2026-06-12
