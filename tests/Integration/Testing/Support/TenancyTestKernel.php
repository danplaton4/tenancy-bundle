<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Testing\Support;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Tenancy\Bundle\DBAL\TenantConnection;
use Tenancy\Bundle\TenancyBundle;
use Tenancy\Bundle\Tests\Integration\Support\ReplaceTenancyProviderPass;

/**
 * Minimal Symfony kernel for InteractsWithTenancy trait integration tests.
 *
 * Registers FrameworkBundle + DoctrineBundle + TenancyBundle with:
 *   - landlord connection: file-based SQLite (tenancy_testing_trait_landlord.db)
 *   - tenant connection: file-based SQLite placeholder with TenantConnection wrapperClass
 *   - two separate entity managers (landlord + tenant)
 *   - tenancy.database.enabled: true (database-per-tenant mode)
 *
 * Uses MakeTenancyTestServicesPublicPass so InteractsWithTenancy can retrieve
 * tenancy services from the test container.
 * Uses ReplaceTenancyProviderPass to avoid needing a real database for the tenant provider.
 *
 * Uses 'tenancy_test' as default environment to avoid cache dir collisions with
 * other test kernels.
 */
class TenancyTestKernel extends Kernel
{
    public function __construct(string $environment = 'tenancy_test', bool $debug = false)
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
        $container->addCompilerPass(new MakeTenancyTestServicesPublicPass());
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
                            'path' => sys_get_temp_dir().'/tenancy_testing_trait_landlord.db',
                        ],
                        'tenant' => [
                            'driver' => 'pdo_sqlite',
                            'path' => sys_get_temp_dir().'/tenancy_testing_trait_placeholder.db',
                            'wrapper_class' => TenantConnection::class,
                        ],
                    ],
                ],
                'orm' => [
                    'enable_native_lazy_objects' => \PHP_VERSION_ID >= 80400,
                    'default_entity_manager' => 'landlord',
                    'entity_managers' => [
                        'landlord' => [
                            'connection' => 'landlord',
                            'mappings' => [
                                'TenancyBundle' => [
                                    'is_bundle' => false,
                                    'type' => 'attribute',
                                    'dir' => realpath(__DIR__.'/../../../../src/Entity'),
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
                                    'dir' => realpath(__DIR__.'/../../Support/Entity'),
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
        return sys_get_temp_dir().'/tenancy_testing_trait_'.$this->environment.'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_testing_trait_'.$this->environment.'/logs';
    }
}
