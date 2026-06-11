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
 * Minimal kernel for the SharedEntity no-op test under the shared_db driver.
 *
 * Mirrors SharedDbTestKernel but maps SharedTestEntities in the default EM
 * so TestPlan can be persisted in the single shared database. Used exclusively
 * by SharedEntityNoDatabaseKernelTest::testNoOpUnderSharedDb() (SHARE-01-j).
 *
 * When driver = shared_db, the SharedEntitySyncSubscriber is a documented no-op:
 * there are no per-tenant EMs to fan out to (D-03).
 */
class SharedEntityNoDbTestKernel extends Kernel
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
                'driver' => 'shared_db',
            ]);

            $container->loadFromExtension('doctrine', [
                'dbal' => [
                    'default_connection' => 'default',
                    'connections' => [
                        'default' => [
                            'driver' => 'pdo_sqlite',
                            'path' => sys_get_temp_dir().'/tenancy_shared_entity_nodb_test.db',
                        ],
                    ],
                ],
                'orm' => [
                    'default_entity_manager' => 'default',
                    'entity_managers' => [
                        'default' => [
                            'connection' => 'default',
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
