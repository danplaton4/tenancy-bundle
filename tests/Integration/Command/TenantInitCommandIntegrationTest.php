<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Command;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Tenancy\Bundle\Command\TenantInitCommand;
use Tenancy\Bundle\Tests\Integration\Command\Support\CommandTestKernel;

/**
 * Integration tests for TenantInitCommand DI wiring.
 *
 * Verifies that:
 * - tenancy.command.init is registered in the container
 * - The service is a TenantInitCommand instance
 * - kernel.project_dir is injected correctly
 */
final class TenantInitCommandIntegrationTest extends TestCase
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

    public function testInitCommandIsRegistered(): void
    {
        self::assertTrue(
            self::$container->has('tenancy.command.init'),
            'Container must have tenancy.command.init service'
        );
    }

    public function testInitCommandIsInstanceOfCommand(): void
    {
        $command = self::$container->get('tenancy.command.init');

        self::assertInstanceOf(
            TenantInitCommand::class,
            $command,
            'tenancy.command.init must be an instance of TenantInitCommand'
        );
    }

    public function testInitCommandReceivesProjectDir(): void
    {
        $command = self::$container->get('tenancy.command.init');
        self::assertInstanceOf(TenantInitCommand::class, $command);

        $reflection = new \ReflectionProperty(TenantInitCommand::class, 'projectDir');
        $projectDir = $reflection->getValue($command);

        self::assertSame(
            self::$kernel->getProjectDir(),
            $projectDir,
            'TenantInitCommand must receive kernel.project_dir from container'
        );
    }
}
