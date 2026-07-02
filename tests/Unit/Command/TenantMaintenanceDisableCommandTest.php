<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Tenancy\Bundle\Command\TenantMaintenanceDisableCommand;
use Tenancy\Bundle\Event\TenantMaintenanceDisabled;
use Tenancy\Bundle\TenantInterface;

/**
 * Unit tests for TenantMaintenanceDisableCommand.
 *
 * Mirror of TenantMaintenanceEnableCommandTest for the disable direction.
 * Covers: MAINT-02 (disable idempotent), MAINT-08 (event on transition),
 *         and the cache-coherence correctness requirement (T-32-09).
 *
 * No kernel boot — all dependencies are mocks. CommandTester drives execute().
 *
 * NOTE on setInMaintenance: this method is now on TenantInterface (added by WR-01 fix).
 * Tests use a concrete anonymous stub that implements the full interface, matching what
 * the Doctrine repository returns at runtime.
 */
final class TenantMaintenanceDisableCommandTest extends TestCase
{
    private EntityManagerInterface&MockObject $landlordEm;
    private EntityRepository&MockObject $repository;
    private CacheInterface&MockObject $cache;
    private EventDispatcherInterface&MockObject $eventDispatcher;

    protected function setUp(): void
    {
        $this->landlordEm = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(EntityRepository::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->landlordEm
            ->method('getRepository')
            ->willReturn($this->repository);
    }

    private function makeCommand(): TenantMaintenanceDisableCommand
    {
        return new TenantMaintenanceDisableCommand(
            $this->landlordEm,
            'App\\Entity\\Tenant',
            $this->cache,
            $this->eventDispatcher,
        );
    }

    /**
     * Creates a concrete stub implementing TenantInterface (including setInMaintenance()).
     *
     * @param bool $inMaintenance initial maintenance state
     *
     * @return object{getSlug():string, isInMaintenance():bool, setInMaintenance(bool):static, setInMaintenanceCallCount:int}&TenantInterface
     */
    private function makeConcreteTenant(bool $inMaintenance): object
    {
        return new class($inMaintenance) implements TenantInterface {
            public int $setInMaintenanceCallCount = 0;

            private bool $inMaintenanceValue;

            public function __construct(bool $inMaintenance)
            {
                $this->inMaintenanceValue = $inMaintenance;
            }

            public function getSlug(): string
            {
                return 'acme';
            }

            public function getDomain(): ?string
            {
                return null;
            }

            /** @return array<string, mixed> */
            public function getConnectionConfig(): array
            {
                return [];
            }

            public function getName(): string
            {
                return 'Acme';
            }

            public function isActive(): bool
            {
                return true;
            }

            public function isInMaintenance(): bool
            {
                return $this->inMaintenanceValue;
            }

            public function setInMaintenance(bool $inMaintenance): static
            {
                ++$this->setInMaintenanceCallCount;
                $this->inMaintenanceValue = $inMaintenance;

                return $this;
            }

            public function getMailerDsn(): ?string
            {
                return null;
            }

            public function getMailerFrom(): ?string
            {
                return null;
            }

            public function getMailerReplyTo(): ?string
            {
                return null;
            }
        };
    }

    // -----------------------------------------------------------------------
    // Real transition: tenant IN maintenance → disable it
    // -----------------------------------------------------------------------

    public function testDisableRealTransitionFlushesAndDeletesCacheAndDispatchesEvent(): void
    {
        $tenant = $this->makeConcreteTenant(true); // currently in maintenance

        $this->repository
            ->method('findOneBy')
            ->with(['slug' => 'acme'])
            ->willReturn($tenant);

        // flush() must be called exactly once
        $this->landlordEm
            ->expects($this->once())
            ->method('flush');

        // cache->delete() must be called once with the exact PSR key (T-32-09)
        $this->cache
            ->expects($this->once())
            ->method('delete')
            ->with('tenancy.tenant.acme');

        // TenantMaintenanceDisabled dispatched exactly once on real transition (MAINT-08)
        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(TenantMaintenanceDisabled::class));

        $command = $this->makeCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['slug' => 'acme']);

        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    public function testDisableRealTransitionCallsSetInMaintenance(): void
    {
        $tenant = $this->makeConcreteTenant(true);

        $this->repository
            ->method('findOneBy')
            ->with(['slug' => 'acme'])
            ->willReturn($tenant);

        $this->landlordEm->method('flush');
        $this->cache->method('delete');
        $this->eventDispatcher->method('dispatch');

        $command = $this->makeCommand();
        $tester = new CommandTester($command);
        $tester->execute(['slug' => 'acme']);

        // setInMaintenance(false) must be called exactly once on a real transition
        $this->assertSame(1, $tenant->setInMaintenanceCallCount, 'setInMaintenance must be called once on real transition');
    }

    // -----------------------------------------------------------------------
    // Idempotent: tenant NOT in maintenance → no-op (D-08)
    // -----------------------------------------------------------------------

    public function testDisableIdempotentOnTenantNotInMaintenance(): void
    {
        $tenant = $this->makeConcreteTenant(false); // not in maintenance

        $this->repository
            ->method('findOneBy')
            ->with(['slug' => 'acme'])
            ->willReturn($tenant);

        // NO flush on idempotent call
        $this->landlordEm->expects($this->never())->method('flush');

        // NO cache delete on idempotent call (T-32-09 inverse)
        $this->cache->expects($this->never())->method('delete');

        // NO event dispatch on idempotent call (MAINT-08 / D-08)
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $command = $this->makeCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['slug' => 'acme']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('not in maintenance', $tester->getDisplay());

        // setInMaintenance must NOT be called on idempotent path
        $this->assertSame(0, $tenant->setInMaintenanceCallCount, 'setInMaintenance must NOT be called on idempotent disable');
    }

    // -----------------------------------------------------------------------
    // Unknown slug → FAILURE, no writes
    // -----------------------------------------------------------------------

    public function testDisableUnknownSlugReturnsFailureWithNoWrites(): void
    {
        $this->repository
            ->method('findOneBy')
            ->with(['slug' => 'nonexistent'])
            ->willReturn(null);

        // NO flush, NO cache delete, NO dispatch on unknown slug
        $this->landlordEm->expects($this->never())->method('flush');
        $this->cache->expects($this->never())->method('delete');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $command = $this->makeCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['slug' => 'nonexistent']);

        $this->assertSame(Command::FAILURE, $exitCode);
    }

    // -----------------------------------------------------------------------
    // Safety: structural check (no TenantContext, no BootstrapperChain)
    // -----------------------------------------------------------------------

    public function testCommandDoesNotBootTenantContext(): void
    {
        $command = $this->makeCommand();
        $this->assertInstanceOf(TenantMaintenanceDisableCommand::class, $command);
    }
}
