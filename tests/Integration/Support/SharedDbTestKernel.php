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
 * Minimal Symfony kernel for shared-DB filter integration tests.
 *
 * Registers FrameworkBundle + DoctrineBundle + TenancyBundle with:
 *   - single connection (default) using file-based SQLite
 *   - single entity manager (default) mapping both TenancyBundle entities and TestApp entities
 *   - tenancy.driver: shared_db so the TenantAwareFilter and SharedDriver are wired
 *   - strict_mode: true to test TenantMissingException on missing tenant
 *
 * Uses MakeSharedDbServicesPublicPass to expose default EM and shared_driver for test access.
 * Uses ReplaceTenancyProviderPass to avoid needing a real database for the tenant provider.
 */
class SharedDbTestKernel extends Kernel
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
        $container->addCompilerPass(new MakeSharedDbServicesPublicPass());
        $container->addCompilerPass(new ReplaceTenancyProviderPass());
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
                'driver'      => 'shared_db',
                'strict_mode' => true,
            ]);

            $container->loadFromExtension('doctrine', [
                'dbal' => [
                    'default_connection' => 'default',
                    'connections'        => [
                        'default' => [
                            'driver' => 'pdo_sqlite',
                            'path'   => sys_get_temp_dir() . '/tenancy_test_shared_db.db',
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
                                'TestApp' => [
                                    'is_bundle' => false,
                                    'type'      => 'attribute',
                                    'dir'       => __DIR__ . '/Entity',
                                    'prefix'    => 'Tenancy\\Bundle\\Tests\\Integration\\Support\\Entity',
                                    'alias'     => 'TestApp',
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
        return sys_get_temp_dir() . '/tenancy_shared_db_test_' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/tenancy_shared_db_test_' . $this->environment . '/logs';
    }
}
