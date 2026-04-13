<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Command;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Tenancy\Bundle\Command\TenantMigrateCommand;
use Tenancy\Bundle\Tests\Integration\Command\Support\CommandTestKernel;

/**
 * Integration tests for TenantMigrateCommand DI wiring.
 *
 * Verifies that:
 * - tenancy.command.migrate is registered in the container when doctrine/migrations is available
 * - The service is an instance of TenantMigrateCommand
 * - The driver parameter is injected correctly
 *
 * Note: Testing the "command NOT registered when doctrine/migrations absent" scenario cannot be
 * done in this test suite because doctrine/migrations IS installed (it is a require-dev
 * dependency). The class_exists guard in config/services.php is verified by code review.
 */
final class TenantMigrateCommandIntegrationTest extends TestCase
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

    public function testMigrateCommandIsRegistered(): void
    {
        self::assertTrue(
            self::$container->has('tenancy.command.migrate'),
            'Container must have tenancy.command.migrate service (class_exists guard passed for doctrine/migrations)'
        );
    }

    public function testMigrateCommandIsTaggedAsConsoleCommand(): void
    {
        $command = self::$container->get('tenancy.command.migrate');

        self::assertInstanceOf(
            TenantMigrateCommand::class,
            $command,
            'tenancy.command.migrate must be an instance of TenantMigrateCommand'
        );
    }

    public function testMigrateCommandReceivesCorrectDriver(): void
    {
        $command = self::$container->get('tenancy.command.migrate');
        self::assertInstanceOf(TenantMigrateCommand::class, $command);

        $reflection = new \ReflectionProperty(TenantMigrateCommand::class, 'driver');
        $driver = $reflection->getValue($command);

        self::assertSame(
            'database_per_tenant',
            $driver,
            'TenantMigrateCommand must receive driver=database_per_tenant from container'
        );
    }

    public function testRunCommandIsRegistered(): void
    {
        self::assertTrue(
            self::$container->has('tenancy.command.run'),
            'Container must have tenancy.command.run service'
        );
    }
}
