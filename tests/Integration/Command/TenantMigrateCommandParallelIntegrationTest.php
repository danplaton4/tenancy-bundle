<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Command;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Tenancy\Bundle\Command\Migration\ParallelMigrationRunner;
use Tenancy\Bundle\Command\TenantMigrateCommand;
use Tenancy\Bundle\Tests\Integration\Command\Support\CommandTestKernel;

/**
 * Integration tests for the parallel-mode wiring of TenantMigrateCommand.
 *
 * Boots a real SQLite :memory: kernel (database.enabled: true) and verifies:
 *  1. The container resolves tenancy.command.migrate as a TenantMigrateCommand instance.
 *  2. The ParallelMigrationRunner is wired as the command's 7th constructor argument
 *     (inside the class_exists(DependencyFactory) block — not config/services.php).
 *  3. The sequential no-flag path is unchanged (7-arg wiring did not break v0.4 behaviour).
 *
 * NOTE: We do NOT attempt to fork real PHP subprocesses here — that requires a real
 * bin/console which is not available in the test harness. Parallel-spawn behaviour
 * is fully covered by the unit tests with a mock process factory
 * (TenantMigrateCommandParallelTest). This integration test proves container wiring
 * only.
 */
final class TenantMigrateCommandParallelIntegrationTest extends TestCase
{
    private static CommandTestKernel $kernel;
    private static ContainerInterface $container;

    public static function setUpBeforeClass(): void
    {
        self::$kernel = new CommandTestKernel('command_parallel_test', false);
        self::$kernel->boot();
        self::$container = self::$kernel->getContainer();
    }

    public static function tearDownAfterClass(): void
    {
        self::$kernel->shutdown();
    }

    // -------------------------------------------------------------------------
    // Wiring: ParallelMigrationRunner is the 7th arg
    // -------------------------------------------------------------------------

    /**
     * tenancy.command.migrate must still be registered in the container after the
     * 7-arg wiring change — SC1 guard at the container level.
     */
    public function testMigrateCommandIsRegistered(): void
    {
        self::assertTrue(
            self::$container->has('tenancy.command.migrate'),
            'Container must have tenancy.command.migrate service (class_exists guard passed for doctrine/migrations)',
        );
    }

    /**
     * The fetched command must be an instance of TenantMigrateCommand (unchanged type).
     */
    public function testMigrateCommandIsCorrectType(): void
    {
        $command = self::$container->get('tenancy.command.migrate');

        self::assertInstanceOf(
            TenantMigrateCommand::class,
            $command,
            'tenancy.command.migrate must be an instance of TenantMigrateCommand',
        );
    }

    /**
     * The 7th constructor argument (parallelRunner) must be a ParallelMigrationRunner instance.
     *
     * This proves the 7-arg wiring in TenancyBundle.php succeeded: the runner is
     * registered as tenancy.command.migrate.parallel_runner inside the
     * class_exists(DependencyFactory) block and injected as the command's 7th arg.
     */
    public function testMigrateCommandHasParallelRunnerWired(): void
    {
        $command = self::$container->get('tenancy.command.migrate');
        self::assertInstanceOf(TenantMigrateCommand::class, $command);

        $reflection = new \ReflectionProperty(TenantMigrateCommand::class, 'parallelRunner');
        $runner = $reflection->getValue($command);

        self::assertInstanceOf(
            ParallelMigrationRunner::class,
            $runner,
            'TenantMigrateCommand must have a ParallelMigrationRunner wired as the 7th constructor arg. '
            .'The runner is registered in TenancyBundle.php inside the class_exists(DependencyFactory) block.',
        );
    }

    /**
     * The ParallelMigrationRunner is wired as the 7th arg.
     * Symfony's DI optimizer inlines single-use private services (the runner is only used by the
     * migrate command), so 'tenancy.command.migrate.parallel_runner' may not survive compilation
     * as a standalone retrievable service. The authoritative proof is the reflection check in
     * testMigrateCommandHasParallelRunnerWired — this test verifies that the migrate command
     * was successfully instantiated with all args (proving the registration block did not crash).
     */
    public function testParallelRunnerServiceIsRegistered(): void
    {
        // If the runner was NOT registered, the migrate command would have been instantiated
        // without its 7th arg — but then testMigrateCommandHasParallelRunnerWired would fail.
        // We verify here by fetching the migrate command (which must succeed) and asserting
        // that the container compiled without errors related to the runner service.
        $command = self::$container->get('tenancy.command.migrate');
        self::assertInstanceOf(TenantMigrateCommand::class, $command);

        // The runner must be present in the command's parallelRunner property.
        $reflection = new \ReflectionProperty(TenantMigrateCommand::class, 'parallelRunner');
        $runner = $reflection->getValue($command);

        // This assertion proves the runner WAS registered imperatively in TenancyBundle.php
        // and wired as the 7th arg (even though the DI optimizer may have inlined the service).
        self::assertNotNull($runner, 'parallelRunner must not be null — the service was registered and wired.');
        self::assertInstanceOf(
            ParallelMigrationRunner::class,
            $runner,
            'tenancy.command.migrate.parallel_runner must be a ParallelMigrationRunner instance. '
            .'Inlining by the Symfony DI optimizer is expected for single-use private services.',
        );
    }

    /**
     * The driver parameter must still be injected correctly after the 7-arg wiring change.
     * Regression guard: ensures the arg shift did not misalign existing args.
     */
    public function testMigrateCommandReceivesCorrectDriver(): void
    {
        $command = self::$container->get('tenancy.command.migrate');
        self::assertInstanceOf(TenantMigrateCommand::class, $command);

        $reflection = new \ReflectionProperty(TenantMigrateCommand::class, 'driver');
        $driver = $reflection->getValue($command);

        self::assertSame(
            'database_per_tenant',
            $driver,
            'TenantMigrateCommand must receive driver=database_per_tenant from the container; '
            .'the 7-arg wiring must not have shifted the driver arg out of position.',
        );
    }
}
