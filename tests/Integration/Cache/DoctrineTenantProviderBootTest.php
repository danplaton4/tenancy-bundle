<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Cache;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Tenancy\Bundle\Provider\DoctrineTenantProvider;
use Tenancy\Bundle\TenancyBundle;

/**
 * Proves issue #5 is closed: DoctrineTenantProvider type-hints CacheInterface
 * and the decorator must implement it, otherwise kernel boot fails with TypeError.
 *
 * Uses a dedicated kernel that does NOT replace tenancy.provider — we need the real
 * DoctrineTenantProvider to instantiate so the TypeError from #5 would surface.
 */
final class DoctrineTenantProviderBootTest extends TestCase
{
    private static ?Kernel $kernel = null;

    public static function setUpBeforeClass(): void
    {
        @unlink(sys_get_temp_dir().'/tenancy_test_landlord.db');
        self::$kernel = new DoctrineTenantProviderBootTestKernel();
        self::$kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$kernel) {
            self::$kernel->shutdown();
            self::$kernel = null;
        }
        @unlink(sys_get_temp_dir().'/tenancy_test_landlord.db');
    }

    public function testDoctrineTenantProviderBootsWithDecoratedCache(): void
    {
        $container = self::$kernel->getContainer();

        // Without FIX-01, container compilation would type-error on the CacheInterface
        // argument of DoctrineTenantProvider's constructor. Retrieving without error = fix.
        $provider = $container->get('tenancy.provider');
        $this->assertInstanceOf(DoctrineTenantProvider::class, $provider);
    }
}

final class DoctrineTenantProviderBootTestKernel extends Kernel
{
    public function __construct()
    {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new DoctrineBundle(), new TenancyBundle()];
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new MakeTenancyProviderPublicPass());
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

            $container->loadFromExtension('tenancy', []);

            $container->loadFromExtension('doctrine', [
                'dbal' => [
                    'default_connection' => 'default',
                    'connections' => [
                        'default' => [
                            'driver' => 'pdo_sqlite',
                            'path' => sys_get_temp_dir().'/tenancy_test_landlord.db',
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
                                    'dir' => realpath(__DIR__.'/../../../src/Entity'),
                                    'prefix' => 'Tenancy\\Bundle\\Entity',
                                    'alias' => 'TenancyBundle',
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
        return sys_get_temp_dir().'/tenancy_doctrine_boot_test_'.md5(static::class).'_'.$this->environment.'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_doctrine_boot_test_'.md5(static::class).'_'.$this->environment.'/logs';
    }
}

final class MakeTenancyProviderPublicPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition('tenancy.provider')) {
            $container->getDefinition('tenancy.provider')->setPublic(true);
        }
    }
}
