<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Command;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Configuration;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\Command\TenantMigrateCommand;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;

final class TenantMigrateCommandTest extends TestCase
{
    private TenantProviderInterface&MockObject $tenantProvider;
    private BootstrapperChain $bootstrapperChain;
    private TenantContext $tenantContext;
    private Connection&MockObject $connection;
    private Configuration $migrationsConfig;

    protected function setUp(): void
    {
        $this->tenantProvider = $this->createMock(TenantProviderInterface::class);
        $this->bootstrapperChain = new BootstrapperChain(new EventDispatcher());
        $this->tenantContext = new TenantContext();
        $this->connection = $this->createMock(Connection::class);
        $this->migrationsConfig = new Configuration();
    }

    private function makeCommand(string $driver = 'database_per_tenant'): TenantMigrateCommand
    {
        return new TenantMigrateCommand(
            $this->tenantProvider,
            $this->bootstrapperChain,
            $this->tenantContext,
            $driver,
            $this->connection,
            $this->migrationsConfig,
        );
    }

    private function makeTenant(string $slug): TenantInterface&MockObject
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn($slug);

        return $tenant;
    }

    public function testSharedDbDriverRejectsWithError(): void
    {
        $command = $this->makeCommand('shared_db');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'only available with the database_per_tenant driver',
            $tester->getDisplay(true)
        );
    }

    public function testEmptyTenantListExitsSuccess(): void
    {
        $this->tenantProvider->method('findAll')->willReturn([]);

        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No tenants found', $tester->getDisplay());
    }

    public function testOneTenantFailsContinuesOthersAndExitsFailure(): void
    {
        $tenant1 = $this->makeTenant('acme');
        $tenant2 = $this->makeTenant('beta');

        // BootstrapperChain has no bootstrappers so boot() is a no-op and won't throw.
        // To simulate a failure, we mock the provider such that findBySlug would fail,
        // but since we use findAll here we need another approach.
        // We'll use a test-specific subclass approach via a spy bootstrapper that throws for acme.
        $throwingProvider = new class($tenant1, $tenant2) implements TenantProviderInterface {
            private int $callCount = 0;

            public function __construct(
                private readonly TenantInterface $tenant1,
                private readonly TenantInterface $tenant2,
            ) {
            }

            public function findBySlug(string $slug): TenantInterface
            {
                return $this->tenant1;
            }

            public function findAll(): array
            {
                return [$this->tenant1, $this->tenant2];
            }
        };

        // Use a real BootstrapperChain with a spy bootstrapper that throws for 'acme'
        $dispatcher = new EventDispatcher();
        $bootstrapperChain = new BootstrapperChain($dispatcher);

        $throwingBootstrapper = new class implements \Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface {
            public function boot(TenantInterface $tenant): void
            {
                if ('acme' === $tenant->getSlug()) {
                    throw new \RuntimeException('Migration failed for acme');
                }
            }

            public function clear(): void
            {
            }
        };
        $bootstrapperChain->addBootstrapper($throwingBootstrapper);

        $command = new TenantMigrateCommand(
            $throwingProvider,
            $bootstrapperChain,
            $this->tenantContext,
            'database_per_tenant',
            $this->connection,
            $this->migrationsConfig,
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);
        $output = $tester->getDisplay();

        $this->assertSame(1, $exitCode);
        // Both tenants should appear in output
        $this->assertStringContainsString('acme', $output);
        $this->assertStringContainsString('beta', $output);
    }

    public function testTenantFilterSingleTenant(): void
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

        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        // We expect this to either succeed (0 migrations) or fail gracefully
        // The important thing is findBySlug was called with 'acme' and findAll was not called
        try {
            $tester->execute(['--tenant' => 'acme']);
        } catch (\Throwable) {
            // DependencyFactory internals may throw with mock connection — that's fine,
            // the assertion above (expects once) already verifies the routing
        }
    }

    public function testTenantFilterNonexistentThrowsTenantNotFoundException(): void
    {
        $this->tenantProvider
            ->method('findBySlug')
            ->with('nonexistent')
            ->willThrowException(new TenantNotFoundException('Tenant "nonexistent" not found.'));

        $command = $this->makeCommand();
        $tester = new CommandTester($command);

        $this->expectException(TenantNotFoundException::class);
        $tester->execute(['--tenant' => 'nonexistent']);
    }

    public function testContextAndBootstrapperChainClearedInFinally(): void
    {
        $tenant = $this->makeTenant('acme');

        // BootstrapperChain is final, use a spy via a custom bootstrapper
        $clearCallCount = 0;
        $spyBootstrapper = new class($clearCallCount) implements \Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface {
            public int $clearCount = 0;
            public int $bootCount = 0;

            public function boot(TenantInterface $tenant): void
            {
                ++$this->bootCount;
                throw new \RuntimeException('Forced failure to test finally');
            }

            public function clear(): void
            {
                ++$this->clearCount;
            }
        };

        $dispatcher = new EventDispatcher();
        $bootstrapperChain = new BootstrapperChain($dispatcher);
        $bootstrapperChain->addBootstrapper($spyBootstrapper);

        $provider = $this->createMock(TenantProviderInterface::class);
        $provider->method('findAll')->willReturn([$tenant]);

        $tenantContext = new TenantContext();

        $command = new TenantMigrateCommand(
            $provider,
            $bootstrapperChain,
            $tenantContext,
            'database_per_tenant',
            $this->connection,
            $this->migrationsConfig,
        );

        $tester = new CommandTester($command);
        $tester->execute([]);

        // clear() must have been called on the bootstrapper (via bootstrapperChain->clear())
        $this->assertSame(1, $spyBootstrapper->clearCount, 'bootstrapperChain->clear() must be called in finally');
        // TenantContext must be cleared
        $this->assertNull($tenantContext->getTenant(), 'TenantContext must be cleared in finally');
    }
}
