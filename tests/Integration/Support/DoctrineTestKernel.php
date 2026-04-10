<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Support;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Tenancy\Bundle\DBAL\TenantConnection;
use Tenancy\Bundle\TenancyBundle;

/**
 * Minimal Symfony kernel for Doctrine-based integration tests.
 *
 * Registers FrameworkBundle + DoctrineBundle + TenancyBundle with:
 *   - landlord connection: file-based SQLite (tenancy_test_landlord.db)
 *   - tenant connection: file-based SQLite placeholder with TenantConnection wrapperClass
 *   - two separate entity managers (landlord + tenant)
 *   - tenancy.database.enabled: true so DatabaseSwitchBootstrapper and EntityManagerResetListener are wired
 *
 * Uses MakeDatabaseServicesPublicPass so tests can retrieve doctrine services from container.
 * Uses ReplaceTenancyProviderPass to avoid needing a real database for the tenant provider.
 */
class DoctrineTestKernel extends Kernel
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
        $container->addCompilerPass(new MakeDatabaseServicesPublicPass());
        $container->addCompilerPass(new ReplaceTenancyProviderPass());
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test',
                'test' => true,
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
            ]);

            $container->loadFromExtension('tenancy', [
                'database' => ['enabled' => true],
            ]);

            $container->loadFromExtension('doctrine', [
                'dbal' => [
                    'default_connection' => 'landlord',
                    'connections' => [
                        'landlord' => [
                            'driver' => 'pdo_sqlite',
                            'path' => sys_get_temp_dir() . '/tenancy_test_landlord.db',
                        ],
                        'tenant' => [
                            'driver' => 'pdo_sqlite',
                            'path' => sys_get_temp_dir() . '/tenancy_test_placeholder.db',
                            'wrapper_class' => TenantConnection::class,
                        ],
                    ],
                ],
                'orm' => [
                    'default_entity_manager' => 'landlord',
                    'enable_native_lazy_objects' => true,
                    'entity_managers' => [
                        'landlord' => [
                            'connection' => 'landlord',
                            'mappings' => [
                                'TenancyBundle' => [
                                    'is_bundle' => false,
                                    'type' => 'attribute',
                                    'dir' => realpath(__DIR__ . '/../../../src/Entity'),
                                    'prefix' => 'Tenancy\\Bundle\\Entity',
                                    'alias' => 'TenancyBundle',
                                ],
                            ],
                        ],
                        'tenant' => [
                            'connection' => 'tenant',
                            'mappings' => [
                                'TestApp' => [
                                    'is_bundle' => false,
                                    'type' => 'attribute',
                                    'dir' => __DIR__ . '/Entity',
                                    'prefix' => 'Tenancy\\Bundle\\Tests\\Integration\\Support\\Entity',
                                    'alias' => 'TestApp',
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
        return sys_get_temp_dir() . '/tenancy_doctrine_test_' . md5(static::class) . '_' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/tenancy_doctrine_test_' . md5(static::class) . '_' . $this->environment . '/logs';
    }
}
