<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Command;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Tenancy\Bundle\Command\TenantRunCommand;
use Tenancy\Bundle\Tests\Integration\Command\Support\CommandTestKernel;

/**
 * Integration tests for TenantRunCommand DI wiring.
 *
 * Verifies that:
 * - tenancy.command.run is registered in the container
 * - The service is a TenantRunCommand instance
 * - kernel.project_dir is injected correctly
 *
 * Note on shared_db driver guard: the tenancy:migrate command exits early with
 * exit code 1 when driver=shared_db. This is already tested in unit tests
 * (Plan 07-01 Task 2). Testing it via integration requires a separate kernel
 * with driver=shared_db, which would also need the full TenantMigrateCommand DI
 * dependencies wired — a disproportionate effort for behaviour already covered.
 * This gap is documented here for completeness.
 */
final class TenantRunCommandIntegrationTest extends TestCase
{
    private static CommandTestKernel $kernel;
    private static ContainerInterface $container;

    public static function setUpBeforeClass(): void
    {
        self::$kernel = new CommandTestKernel('command_test', false);
        self::$kernel->boot();
        self::$container = self::$kernel->getContainer();
    }

    public static function tearDownAfterClass(): void
    {
        self::$kernel->shutdown();
    }

    public function testRunCommandIsRegistered(): void
    {
        self::assertTrue(
            self::$container->has('tenancy.command.run'),
            'Container must have tenancy.command.run service'
        );
    }

    public function testRunCommandIsInstanceOfCommand(): void
    {
        $command = self::$container->get('tenancy.command.run');

        self::assertInstanceOf(
            TenantRunCommand::class,
            $command,
            'tenancy.command.run must be an instance of TenantRunCommand'
        );
    }

    public function testRunCommandReceivesProjectDir(): void
    {
        $command = self::$container->get('tenancy.command.run');
        self::assertInstanceOf(TenantRunCommand::class, $command);

        $reflection = new \ReflectionProperty(TenantRunCommand::class, 'projectDir');
        $projectDir = $reflection->getValue($command);

        self::assertSame(
            self::$kernel->getProjectDir(),
            $projectDir,
            'TenantRunCommand must receive kernel.project_dir from container'
        );
    }
}
