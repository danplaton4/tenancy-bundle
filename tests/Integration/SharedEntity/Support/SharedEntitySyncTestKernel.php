<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\SharedEntity\Support;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Tenancy\Bundle\TenancyBundle;

/**
 * Minimal Symfony kernel for SharedEntity integration tests.
 *
 * Registers FrameworkBundle + DoctrineBundle + TenancyBundle with:
 *   - landlord connection: file-based SQLite (tenancy_shared_test_landlord.db)
 *   - tenant connection: file-based SQLite placeholder (tenancy_shared_test_placeholder.db)
 *     (TenantDriverMiddleware is wired automatically by TenancyBundle when database.enabled: true)
 *   - three separate databases in total:
 *       - tenancy_shared_test_landlord.db   (landlord, default_connection)
 *       - tenancy_shared_test_placeholder.db (tenant connection placeholder)
 *       - tenancy_shared_test_tenant_a.db   (per-tenant — set via getConnectionConfig() on each tenant)
 *       - tenancy_shared_test_tenant_b.db   (per-tenant — set via getConnectionConfig() on each tenant)
 *   - tenancy.database.enabled: true so DatabaseSwitchBootstrapper and sync subscriber are wired
 *
 * Landlord EM maps BOTH src/Entity (TenancyBundle entities like Tenant) AND Support/Entity
 * (TestPlan, TestPlanWithAssociation) so the landlord can persist #[Shared] test entities.
 *
 * Tenant EM maps Support/Entity so each per-tenant DB receives the same schema.
 *
 * Uses MakeSharedEntityServicesPublicPass to expose shared-entity services for test access
 * (tolerates absent services while Wave 3 code lands).
 * Uses a provider-replacement pass to swap tenancy.provider with StubMultiTenantProvider.
 */
class SharedEntitySyncTestKernel extends Kernel
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
        $container->addCompilerPass(new MakeSharedEntityServicesPublicPass());
        $container->addCompilerPass(new ReplaceWithStubMultiTenantProviderPass());
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
                            'path' => sys_get_temp_dir().'/tenancy_shared_test_landlord.db',
                        ],
                        'tenant' => [
                            'driver' => 'pdo_sqlite',
                            'path' => sys_get_temp_dir().'/tenancy_shared_test_placeholder.db',
                        ],
                    ],
                ],
                'orm' => [
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
                                'SharedTestEntities' => [
                                    'is_bundle' => false,
                                    'type' => 'attribute',
                                    'dir' => __DIR__.'/Entity',
                                    'prefix' => 'Tenancy\\Bundle\\Tests\\Integration\\SharedEntity\\Support\\Entity',
                                    'alias' => 'SharedTestEntities',
                                ],
                            ],
                        ],
                        'tenant' => [
                            'connection' => 'tenant',
                            'mappings' => [
                                'SharedTestEntities' => [
                                    'is_bundle' => false,
                                    'type' => 'attribute',
                                    'dir' => __DIR__.'/Entity',
                                    'prefix' => 'Tenancy\\Bundle\\Tests\\Integration\\SharedEntity\\Support\\Entity',
                                    'alias' => 'SharedTestEntities',
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

            // enable_native_lazy_objects: only supported in DoctrineBundle >= 2.14
            if (\PHP_VERSION_ID >= 80400 && version_compare(
                \Composer\InstalledVersions::getVersion('doctrine/doctrine-bundle') ?? '0',
                '2.14.0',
                '>='
            )) {
                $container->loadFromExtension('doctrine', [
                    'orm' => ['enable_native_lazy_objects' => true],
                ]);
            }
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tenancy_doctrine_test_'.md5(static::class).'_'.$this->environment.'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_doctrine_test_'.md5(static::class).'_'.$this->environment.'/logs';
    }
}
