<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface;
use Tenancy\Bundle\Command\SharedEntityResyncCommand;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\Shared\SharedEntityCopierInterface;
use Tenancy\Bundle\TenantInterface;

/**
 * Unit tests for SharedEntityResyncCommand (SHARE-02-b..g, SHARE-02-k).
 *
 * SharedEntityCopierInterface is the mock target — the final SharedEntityCopier
 * implements it (same pattern as TenantConnectionInterface for final TenantConnection).
 *
 * Real BootstrapperChain(new EventDispatcher()) + real TenantContext so finally-clear
 * assertions are observable.
 */
final class SharedEntityResyncCommandTest extends TestCase
{
    private TenantProviderInterface&MockObject $tenantProvider;
    private BootstrapperChain $bootstrapperChain;
    private TenantContext $tenantContext;
    private EntityManagerInterface&MockObject $landlordEm;
    private ManagerRegistry&MockObject $registry;
    private SharedEntityCopierInterface&MockObject $copier;

    protected function setUp(): void
    {
        $this->tenantProvider = $this->createMock(TenantProviderInterface::class);
        $this->bootstrapperChain = new BootstrapperChain(new EventDispatcher());
        $this->tenantContext = new TenantContext();
        $this->landlordEm = $this->createMock(EntityManagerInterface::class);
        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->copier = $this->createMock(SharedEntityCopierInterface::class);
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

    private function makeTenant(string $slug): TenantInterface&MockObject
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn($slug);

        return $tenant;
    }

    /**
     * Wire the landlordEm mock to return $entities from a repository for any class.
     *
     * @param list<class-string> $sharedClasses
     * @param list<object>       $entities
     */
    private function wireSharedClasses(array $sharedClasses, array $entities = []): void
    {
        $this->copier->method('findSharedClasses')->willReturn($sharedClasses);
        if ([] !== $sharedClasses) {
            $repo = $this->createMock(EntityRepository::class);
            $repo->method('findAll')->willReturn($entities);
            $this->landlordEm->method('getRepository')->willReturn($repo);
        }
    }

    /**
     * SHARE-02-e: shared_db driver → informational no-op message, exits SUCCESS (D-05).
     */
    public function testSharedDbDriverExitsSuccessWithNoOp(): void
    {
        $command = $this->makeCommand('shared_db');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('no-op', $tester->getDisplay());
    }

    /**
     * SHARE-02-b: --dry-run classifies each row insert/update/in-sync but never flushes (D-03).
     */
    public function testDryRunNeverWrites(): void
    {
        $tenant = $this->makeTenant('acme');
        $this->tenantProvider->method('findAll')->willReturn([$tenant]);

        $entity = new \stdClass();
        $this->wireSharedClasses(['App\Entity\Config'], [$entity]);
        $this->copier->method('classifyRow')->willReturn('insert');

        // Key assertion: applyRow must NEVER be called in dry-run mode
        $this->copier->expects($this->never())->method('applyRow');

        $tenantEm = $this->createMock(EntityManagerInterface::class);
        $this->registry->method('getManager')->with('tenant')->willReturn($tenantEm);

        $command = $this->makeCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    /**
     * SHARE-02-c: Live run prints drift summary then confirm(); default-No aborts cleanly (D-04).
     * Using ['interactive' => false] so confirm() defaults to false → SUCCESS, no writes.
     */
    public function testLiveRunPromptsConfirmDefaultNoAbortsCleanly(): void
    {
        $tenant = $this->makeTenant('acme');
        $this->tenantProvider->method('findAll')->willReturn([$tenant]);

        $entity = new \stdClass();
        $this->wireSharedClasses(['App\Entity\Config'], [$entity]);
        $this->copier->method('classifyRow')->willReturn('insert');

        // applyRow must NOT be called — user declined (non-interactive defaults to false)
        $this->copier->expects($this->never())->method('applyRow');

        $tenantEm = $this->createMock(EntityManagerInterface::class);
        $this->registry->method('getManager')->with('tenant')->willReturn($tenantEm);

        $command = $this->makeCommand();
        $tester = new CommandTester($command);
        // Non-interactive: confirm() returns the default (false) → clean abort
        $exitCode = $tester->execute([], ['interactive' => false]);

        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    /**
     * SHARE-02-d: --force skips confirmation and proceeds immediately (D-04).
     */
    public function testForceSkipsConfirmation(): void
    {
        $tenant = $this->makeTenant('acme');
        $this->tenantProvider
            ->expects($this->once())
            ->method('findBySlug')
            ->with('acme')
            ->willReturn($tenant);
        $this->tenantProvider->expects($this->never())->method('findAll');

        $entity = new \stdClass();
        $this->wireSharedClasses(['App\Entity\Config'], [$entity]);
        // classifyRow returns 'insert' so applyRow WILL be called (no confirmation gate with --force)
        $this->copier->method('classifyRow')->willReturn('insert');
        $this->copier->expects($this->atLeastOnce())->method('applyRow');

        $tenantEm = $this->createMock(EntityManagerInterface::class);
        $this->registry->method('getManager')->with('tenant')->willReturn($tenantEm);

        $command = $this->makeCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--force' => true, '--tenant' => 'acme']);

        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    /**
     * SHARE-02-f: Continue-on-failure: one tenant fails, others succeed, command exits FAILURE (D-06).
     */
    public function testContinueOnFailureExitsFailureWhenAnyTenantFails(): void
    {
        $tenantAcme = $this->makeTenant('acme');
        $tenantBeta = $this->makeTenant('beta');

        $this->tenantProvider->method('findAll')->willReturn([$tenantAcme, $tenantBeta]);

        $entity = new \stdClass();
        $this->wireSharedClasses(['App\Entity\Config'], [$entity]);
        $this->copier->method('classifyRow')->willReturn('insert');

        $tenantEm = $this->createMock(EntityManagerInterface::class);
        $this->registry->method('getManager')->with('tenant')->willReturn($tenantEm);

        // Spy bootstrapper that throws for 'acme' in the apply pass (boot count > 2)
        $throwingBootstrapper = new class implements TenantBootstrapperInterface {
            private int $bootCount = 0;

            public function boot(TenantInterface $tenant): void
            {
                ++$this->bootCount;
                // First 2 boots are the classify pass; apply pass boots start at 3
                if ($this->bootCount > 2 && 'acme' === $tenant->getSlug()) {
                    throw new \RuntimeException('Resync failed for acme');
                }
            }

            public function clear(): void
            {
            }
        };

        $bootstrapperChain = new BootstrapperChain(new EventDispatcher());
        $bootstrapperChain->addBootstrapper($throwingBootstrapper);

        $command = new SharedEntityResyncCommand(
            $this->tenantProvider,
            $bootstrapperChain,
            $this->tenantContext,
            'database_per_tenant',
            $this->landlordEm,
            $this->registry,
            $this->copier,
        );

        $tester = new CommandTester($command);
        // --force skips confirm() so we reach the apply pass
        $exitCode = $tester->execute(['--force' => true]);
        $output = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $exitCode);
        // Both tenants must appear in the output
        $this->assertStringContainsString('acme', $output);
        $this->assertStringContainsString('beta', $output);
        // 'Completed:' summary line must appear
        $this->assertStringContainsString('Completed:', $output);
    }

    /**
     * SHARE-02-g: TenantContext::clear() + BootstrapperChain::clear() called in finally per tenant (D-06).
     */
    public function testContextAndBootstrapperClearedInFinally(): void
    {
        $tenant = $this->makeTenant('acme');
        $this->tenantProvider->method('findAll')->willReturn([$tenant]);

        $entity = new \stdClass();
        $this->wireSharedClasses(['App\Entity\Config'], [$entity]);
        $this->copier->method('classifyRow')->willReturn('insert');

        $tenantEm = $this->createMock(EntityManagerInterface::class);
        $this->registry->method('getManager')->with('tenant')->willReturn($tenantEm);

        // Spy bootstrapper that throws on 2nd boot (apply pass) to trigger the finally path
        $spyBootstrapper = new class implements TenantBootstrapperInterface {
            public int $clearCount = 0;
            private int $bootCount = 0;

            public function boot(TenantInterface $tenant): void
            {
                ++$this->bootCount;
                // Allow first boot (classify pass); force failure on second boot (apply pass)
                if ($this->bootCount > 1) {
                    throw new \RuntimeException('Forced failure to test finally');
                }
            }

            public function clear(): void
            {
                ++$this->clearCount;
            }
        };

        $tenantContext = new TenantContext();
        $bootstrapperChain = new BootstrapperChain(new EventDispatcher());
        $bootstrapperChain->addBootstrapper($spyBootstrapper);

        $command = new SharedEntityResyncCommand(
            $this->tenantProvider,
            $bootstrapperChain,
            $tenantContext,
            'database_per_tenant',
            $this->landlordEm,
            $this->registry,
            $this->copier,
        );

        $tester = new CommandTester($command);
        // --force skips confirm(), reaches apply pass which throws → finally fires
        $tester->execute(['--force' => true]);

        // clear() must have been called at least once (both classify pass and apply pass fire clear)
        $this->assertGreaterThanOrEqual(1, $spyBootstrapper->clearCount, 'bootstrapperChain->clear() must be called in finally');
        // TenantContext must be cleared
        $this->assertNull($tenantContext->getTenant(), 'TenantContext must be cleared in finally');
    }

    /**
     * SHARE-02-k: --tenant=<slug> targets single tenant only (D-01).
     */
    public function testTenantOptionTargetsSingleTenant(): void
    {
        $tenant = $this->makeTenant('acme');

        $this->tenantProvider
            ->expects($this->once())
            ->method('findBySlug')
            ->with('acme')
            ->willReturn($tenant);

        $this->tenantProvider
            ->expects($this->never())
            ->method('findAll');

        // findSharedClasses returns empty so command exits early with SUCCESS
        $this->copier->method('findSharedClasses')->willReturn([]);

        $command = $this->makeCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--tenant' => 'acme', '--force' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
    }
}
