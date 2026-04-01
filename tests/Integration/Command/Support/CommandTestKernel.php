<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Command\Support;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Configuration;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpKernel\Kernel;
use Tenancy\Bundle\TenancyBundle;
use Tenancy\Bundle\Tests\Integration\Support\ReplaceTenancyProviderPass;

/**
 * Minimal Symfony kernel for CLI command integration tests.
 *
 * Registers FrameworkBundle + TenancyBundle only (no DoctrineBundle) with:
 *   - database_per_tenant driver (no database.enabled required for DI wiring test)
 *   - Stub definitions for Doctrine services required by tenancy:migrate
 *   - MakeCommandsPublicPass to expose command services for test inspection
 *   - ReplaceTenancyProviderPass to avoid needing a real provider for DI tests
 *
 * We intentionally avoid DoctrineBundle because its EntityManager initialization
 * triggers cache warmers that are not compatible with the minimal test setup and cause
 * unrelated errors. The command DI wiring test only needs the services wired — not connected.
 */
class CommandTestKernel extends Kernel
{
    public function __construct(string $environment = 'command_test', bool $debug = false)
    {
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new TenancyBundle(),
        ];
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new MakeCommandsPublicPass());
        $container->addCompilerPass(new ReplaceTenancyProviderPass());

        // Stub the Doctrine DBAL tenant connection (required by TenantMigrateCommand).
        // The real service is provided by DoctrineBundle; we provide a stub
        // so the container compiles without DoctrineBundle installed.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                // Only register stubs if doctrine/migrations is available (same guard as services.php)
                if (!class_exists(\Doctrine\Migrations\DependencyFactory::class)) {
                    return;
                }

                if (!$container->hasDefinition('doctrine.dbal.tenant_connection')) {
                    $stub = new Definition(Connection::class);
                    $stub->setAbstract(false);
                    $stub->setPublic(false);
                    $stub->setFactory([StubConnectionFactory::class, 'create']);
                    $container->setDefinition('doctrine.dbal.tenant_connection', $stub);
                }

                if (!$container->hasDefinition('doctrine.migrations.configuration')) {
                    $migrationsConfig = new Definition(Configuration::class);
                    $migrationsConfig->setPublic(false);
                    $container->setDefinition('doctrine.migrations.configuration', $migrationsConfig);
                }

                // Also stub doctrine.orm.default_entity_manager for DoctrineBootstrapper
                if (!$container->hasDefinition('doctrine.orm.default_entity_manager')
                    && !$container->hasAlias('doctrine.orm.default_entity_manager')) {
                    $emStub = new Definition(\stdClass::class);
                    $emStub->setPublic(false);
                    $container->setDefinition('doctrine.orm.default_entity_manager', $emStub);
                }
            }
        });
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret'                => 'test',
                'test'                  => true,
                'http_method_override'  => false,
                'handle_all_throwables' => true,
                'php_errors'            => ['log' => true],
            ]);

            $container->loadFromExtension('tenancy', [
                'driver'              => 'database_per_tenant',
                'tenant_entity_class' => 'Tenancy\\Bundle\\Entity\\Tenant',
                'host'                => ['app_domain' => 'app.test'],
            ]);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/tenancy_command_test/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/tenancy_command_test/logs';
    }
}
