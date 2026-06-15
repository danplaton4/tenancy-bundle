<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\SharedEntity\Support;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Tenancy\Bundle\Message\SharedEntityChangedMessage;
use Tenancy\Bundle\TenancyBundle;

/**
 * Minimal Symfony kernel for SharedEntity async integration tests (SHARE-03).
 *
 * Structurally mirrors SharedEntitySyncTestKernel with these additions:
 *   - framework.messenger block: sync:// transport + SharedEntityChangedMessage routing
 *   - tenancy.shared.async: true
 *   - MakeSharedEntityAsyncServicesPublicPass exposes handler + bus + EMs for test inspection
 *
 * Uses distinct SQLite DB filenames (tenancy_shared_async_test_*.db) to avoid colliding
 * with the sync kernel's files (tenancy_shared_test_*.db).
 *
 * Three separate databases:
 *   - tenancy_shared_async_test_landlord.db   (landlord, default_connection)
 *   - tenancy_shared_async_test_placeholder.db (tenant connection placeholder)
 *   - StubMultiTenantProvider supplies per-tenant paths:
 *       - tenancy_shared_test_tenant_a.db  (tenant_a — same path as sync kernel; StubMultiTenantProvider
 *         returns a fixed path; tests must not run in parallel with the sync suite)
 *       - tenancy_shared_test_tenant_b.db  (tenant_b)
 */
class SharedEntityAsyncTestKernel extends Kernel
{
    public function __construct(string $environment = 'shared_async_test', bool $debug = false)
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
        $container->addCompilerPass(new MakeSharedEntityAsyncServicesPublicPass());
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
                'messenger' => [
                    'default_bus' => 'messenger.bus.default',
                    'buses' => [
                        'messenger.bus.default' => [
                            'default_middleware' => 'allow_no_handlers',
                        ],
                    ],
                    'transports' => [
                        'sync' => 'sync://',
                    ],
                    'routing' => [
                        SharedEntityChangedMessage::class => 'sync',
                    ],
                ],
            ]);

            $container->loadFromExtension('tenancy', [
                'database' => ['enabled' => true],
                'shared' => ['async' => true],
            ]);

            $container->loadFromExtension('doctrine', [
                'dbal' => [
                    'default_connection' => 'landlord',
                    'connections' => [
                        'landlord' => [
                            'driver' => 'pdo_sqlite',
                            'path' => sys_get_temp_dir().'/tenancy_shared_async_test_landlord.db',
                        ],
                        'tenant' => [
                            'driver' => 'pdo_sqlite',
                            'path' => sys_get_temp_dir().'/tenancy_shared_async_test_placeholder.db',
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
