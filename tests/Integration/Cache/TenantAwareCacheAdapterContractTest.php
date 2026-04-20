<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Cache;

use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Tenancy\Bundle\Cache\TenantAwareCacheAdapter;
use Tenancy\Bundle\Cache\TenantAwareTagAwareCacheAdapter;
use Tenancy\Bundle\TenancyBundle;
use Tenancy\Bundle\Tests\Integration\Support\ReplaceTenancyProviderPass;

/**
 * Stock-kernel boot test: proves the cache.app decorator chain resolves for every
 * interface alias Symfony's FrameworkBundle registers. Automated reproduction of
 * issue #5 — before FIX-01, injecting CacheInterface anywhere triggered TypeError.
 */
final class TenantAwareCacheAdapterContractTest extends TestCase
{
    private static ?Kernel $kernel = null;

    public static function setUpBeforeClass(): void
    {
        self::$kernel = new CacheContractIntegrationTestKernel('test', false);
        self::$kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$kernel) {
            self::$kernel->shutdown();
            self::$kernel = null;
        }
    }

    public function testCacheAppAliasesResolveToTenantAwareDecoratorWithoutTypeError(): void
    {
        $container = self::$kernel->getContainer();

        // Symfony's FrameworkBundle aliases cache.app to the PSR and Contracts interfaces.
        // Each alias must resolve to the (tenant-aware) decorated pool. No TypeError.
        $this->assertInstanceOf(CacheItemPoolInterface::class, $container->get(CacheItemPoolInterface::class));
        $this->assertInstanceOf(CacheInterface::class, $container->get(CacheInterface::class));
        $this->assertInstanceOf(NamespacedPoolInterface::class, $container->get(NamespacedPoolInterface::class));
        $this->assertInstanceOf(AdapterInterface::class, $container->get(CacheInterface::class));
        $this->assertInstanceOf(TenantAwareCacheAdapter::class, $container->get(CacheInterface::class));
    }

    public function testTagAwareAliasResolvesToTenantAwareTagAwareDecorator(): void
    {
        $container = self::$kernel->getContainer();

        $this->assertInstanceOf(TagAwareCacheInterface::class, $container->get(TagAwareCacheInterface::class));
        $this->assertInstanceOf(TagAwareAdapterInterface::class, $container->get('cache.app.taggable'));
        $this->assertInstanceOf(TenantAwareTagAwareCacheAdapter::class, $container->get(TagAwareCacheInterface::class));
    }

    public function testCallableFlavorGetDelegatesThroughDecorator(): void
    {
        /** @var CacheInterface $cache */
        $cache = self::$kernel->getContainer()->get(CacheInterface::class);
        $value = $cache->get('tenancy.test.key', static fn (ItemInterface $i): string => 'hello');
        $this->assertSame('hello', $value);
    }
}

final class CacheContractIntegrationTestKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new TenancyBundle()];
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new ReplaceTenancyProviderPass());
        $container->addCompilerPass(new MakeCacheAliasesPublicPass());
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
                'cache' => [
                    'app' => 'cache.adapter.filesystem',
                ],
            ]);
            $container->loadFromExtension('tenancy', []);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/tenancy_cache_contract_test_'.md5(static::class).'_'.$this->environment.'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/tenancy_cache_contract_test_'.md5(static::class).'_'.$this->environment.'/logs';
    }
}

final class MakeCacheAliasesPublicPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ([
            CacheItemPoolInterface::class,
            CacheInterface::class,
            NamespacedPoolInterface::class,
            TagAwareCacheInterface::class,
            'cache.app',
            'cache.app.taggable',
        ] as $id) {
            if ($container->hasAlias($id)) {
                $container->getAlias($id)->setPublic(true);
            }
            if ($container->hasDefinition($id)) {
                $container->getDefinition($id)->setPublic(true);
            }
        }
    }
}
