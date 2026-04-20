<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\Cache\ResettableInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;
use Tenancy\Bundle\Cache\TenantAwareCacheAdapter;
use Tenancy\Bundle\DependencyInjection\Compiler\CacheDecoratorContractPass;

final class CacheDecoratorContractPassTest extends TestCase
{
    public function testNoOpWhenDecoratorAbsent(): void
    {
        $container = new ContainerBuilder();
        // No 'tenancy.cache_adapter' defined — pass is a no-op.
        (new CacheDecoratorContractPass())->process($container);

        $this->addToAssertionCount(1);
    }

    public function testNoOpWhenDecoratedAbsent(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('tenancy.cache_adapter', new Definition(TenantAwareCacheAdapter::class));
        // No 'cache.app' defined — pass skips.
        (new CacheDecoratorContractPass())->process($container);

        $this->addToAssertionCount(1);
    }

    public function testPassesWhenDecoratorImplementsEverySymfonyInterface(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('tenancy.cache_adapter', new Definition(TenantAwareCacheAdapter::class));
        $container->setDefinition('cache.app', new Definition(FilesystemAdapter::class));

        (new CacheDecoratorContractPass())->process($container);

        $this->addToAssertionCount(1); // No exception = pass
    }

    public function testThrowsWhenDecoratorMissesSymfonyInterface(): void
    {
        $container = new ContainerBuilder();
        // Stub decorator that only implements AdapterInterface + PruneableInterface
        // (missing CacheInterface, NamespacedPoolInterface, ResettableInterface).
        $container->setDefinition('tenancy.cache_adapter', new Definition(StubIncompleteCacheDecorator::class));
        $container->setDefinition('cache.app', new Definition(FilesystemAdapter::class));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Cache decorator .* must implement every Symfony interface/');

        (new CacheDecoratorContractPass())->process($container);
    }

    public function testDoesNotRequireNonSymfonyInterfaces(): void
    {
        // FilesystemAdapter inherits Psr\Log\LoggerAwareInterface via AbstractAdapter;
        // TenantAwareCacheAdapter does not implement it — pass must NOT complain about
        // non-Symfony interfaces.
        $container = new ContainerBuilder();
        $container->setDefinition('tenancy.cache_adapter', new Definition(TenantAwareCacheAdapter::class));
        $container->setDefinition('cache.app', new Definition(FilesystemAdapter::class));

        (new CacheDecoratorContractPass())->process($container);

        $this->addToAssertionCount(1);
    }

    /**
     * Regression guard for WR-03: the compile-time contract pass assumes that
     * FilesystemAdapter (the concrete class behind cache.app's default parent
     * chain) exposes a known Symfony\* interface set. If a future Symfony
     * release alters the class hierarchy (e.g. renames or drops an interface
     * on AbstractAdapter), this test will fail loudly instead of the pass
     * silently relaxing its guarantees.
     */
    public function testFilesystemAdapterExposesExpectedSymfonyInterfaceSet(): void
    {
        $implemented = class_implements(FilesystemAdapter::class);
        self::assertIsArray($implemented);

        $symfonyInterfaces = array_values(array_filter(
            $implemented,
            static fn (string $i): bool => str_starts_with($i, 'Symfony\\'),
        ));

        $expected = [
            AdapterInterface::class,
            CacheInterface::class,
            NamespacedPoolInterface::class,
            PruneableInterface::class,
            ResettableInterface::class,
        ];

        foreach ($expected as $interface) {
            self::assertContains(
                $interface,
                $symfonyInterfaces,
                sprintf(
                    'FilesystemAdapter must implement %s — if this fails, a Symfony '
                    .'upgrade changed the cache.app interface set and CacheDecoratorContractPass '
                    .'(and TenantAwareCacheAdapter) needs to be updated.',
                    $interface,
                ),
            );
        }
    }
}

/**
 * Stub used only by testThrowsWhenDecoratorMissesSymfonyInterface — implements
 * only a subset of cache.app's contracts to trigger the LogicException.
 */
final class StubIncompleteCacheDecorator implements AdapterInterface, PruneableInterface
{
    public function getItem(mixed $key): CacheItem
    {
        throw new \LogicException();
    }

    /** @return iterable<string, CacheItem> */
    public function getItems(array $keys = []): iterable
    {
        return [];
    }

    public function hasItem(mixed $key): bool
    {
        return false;
    }

    public function clear(string $prefix = ''): bool
    {
        return true;
    }

    public function deleteItem(mixed $key): bool
    {
        return true;
    }

    public function deleteItems(array $keys): bool
    {
        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return true;
    }

    public function commit(): bool
    {
        return true;
    }

    public function prune(): bool
    {
        return true;
    }
}
