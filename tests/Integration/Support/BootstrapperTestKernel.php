<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Support;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Tenancy\Bundle\TenancyBundle;

/**
 * Minimal Symfony kernel for bootstrapper integration tests.
 *
 * Registers FrameworkBundle + DoctrineBundle + TenancyBundle with:
 *   - single connection (default) using file-based SQLite
 *   - single entity manager (default) mapping TenancyBundle entities
 *   - tenancy.driver: shared_db (simplest — single EM, DoctrineBootstrapper targets default EM)
 *   - strict_mode: true
 *
 * Uses MakeBootstrapperServicesPublicPass to expose DoctrineBootstrapper, TenantContext,
 * BootstrapperChain, EM, ManagerRegistry and cache.app for test container inspection.
 * Uses ReplaceTenancyProviderPass to avoid needing a real provider for these DI tests.
 */
class BootstrapperTestKernel extends Kernel
{
    public function __construct(string $environment = 'test', bool $debug = false)
    {
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new DoctrineBundle(),
            new TenancyBundle(),
        ];
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new MakeBootstrapperServicesPublicPass());
        $container->addCompilerPass(new ReplaceTenancyProviderPass());
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $dbPath = sys_get_temp_dir() . '/tenancy_bootstrapper_' . $this->environment . '.db';

        $loader->load(function (ContainerBuilder $container) use ($dbPath): void {
            $container->loadFromExtension('framework', [
                'secret'                => 'test',
                'test'                  => true,
                'http_method_override'  => false,
                'handle_all_throwables' => true,
                'php_errors'            => ['log' => true],
            ]);

            $container->loadFromExtension('tenancy', [
                'driver'      => 'shared_db',
                'strict_mode' => true,
            ]);

            $container->loadFromExtension('doctrine', [
                'dbal' => [
                    'default_connection' => 'default',
                    'connections'        => [
                        'default' => [
                            'driver' => 'pdo_sqlite',
                            'path'   => $dbPath,
                        ],
                    ],
                ],
                'orm' => [
                    'default_entity_manager' => 'default',
                    'enable_native_lazy_objects' => \PHP_VERSION_ID >= 80400,
                    'entity_managers'        => [
                        'default' => [
                            'connection' => 'default',
                            'mappings'   => [
                                'TenancyBundle' => [
                                    'is_bundle' => false,
                                    'type'      => 'attribute',
                                    'dir'       => realpath(__DIR__ . '/../../../src/Entity'),
                                    'prefix'    => 'Tenancy\\Bundle\\Entity',
                                    'alias'     => 'TenancyBundle',
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/tenancy_bootstrapper_test_' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/tenancy_bootstrapper_test_' . $this->environment . '/logs';
    }
}
