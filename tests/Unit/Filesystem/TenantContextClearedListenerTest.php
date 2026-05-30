<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Filesystem;

use League\Flysystem\DirectoryListing;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Tenancy\Bundle\Event\TenantContextCleared;
use Tenancy\Bundle\Filesystem\LruFilesystemCache;
use Tenancy\Bundle\Filesystem\TenantContextClearedListener;

/**
 * Behavior tests for the Phase 24 TenantContextClearedListener.
 *
 * Covers BOOT-03 (event-driven flush path) — the listener subscribes to
 * TenantContextCleared and invokes LruFilesystemCache::clear() exactly once
 * per dispatch, ensuring every tenant teardown closes its FilesystemOperator
 * adapters even when BootstrapperChain cleanup is bypassed (e.g. unusual
 * Messenger middleware ordering).
 *
 * @see .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-PRIORITY
 * @see .planning/phases/24-filesystem-bootstrapper/24-03-PLAN.md Task 2
 */
final class TenantContextClearedListenerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists(FilesystemOperator::class)) {
            $this->markTestSkipped('league/flysystem not installed');
        }
    }

    public function testSubscribesToTenantContextClearedEvent(): void
    {
        $subscribed = TenantContextClearedListener::getSubscribedEvents();

        $this->assertSame(
            [TenantContextCleared::class => 'onContextCleared'],
            $subscribed,
            'Listener must subscribe to TenantContextCleared with method onContextCleared'
        );
    }

    public function testOnContextClearedCallsCacheClearOnce(): void
    {
        // Use a real LruFilesystemCache (final, not mockable) seeded with a
        // closeable spy — assert close() is called exactly once via clear().
        $cache = new LruFilesystemCache(32);
        $spy = $this->makeCloseableOperator();
        $cache->set('acme', $spy);

        $listener = new TenantContextClearedListener($cache);
        $listener->onContextCleared(new TenantContextCleared());

        $this->assertSame(1, $spy->closeCalls, 'clear() must invoke close() once on each cached operator');
        $this->assertSame(0, $cache->size(), 'clear() must empty the cache');
    }

    public function testMultipleDispatchesEachTriggerClear(): void
    {
        // No debouncing: each dispatch invokes cache->clear() independently.
        $cache = new LruFilesystemCache(32);
        $listener = new TenantContextClearedListener($cache);

        $spy1 = $this->makeCloseableOperator();
        $cache->set('acme', $spy1);
        $listener->onContextCleared(new TenantContextCleared());
        $this->assertSame(1, $spy1->closeCalls);
        $this->assertSame(0, $cache->size());

        $spy2 = $this->makeCloseableOperator();
        $cache->set('beta', $spy2);
        $listener->onContextCleared(new TenantContextCleared());
        $this->assertSame(1, $spy2->closeCalls, 'Second dispatch must clear() the cache again');
        $this->assertSame(0, $cache->size());
    }

    public function testClassIsFinalAndImplementsEventSubscriberInterface(): void
    {
        $reflection = new \ReflectionClass(TenantContextClearedListener::class);

        $this->assertTrue($reflection->isFinal(), 'TenantContextClearedListener must be final');
        $this->assertTrue(
            $reflection->implementsInterface(EventSubscriberInterface::class),
            'TenantContextClearedListener must implement EventSubscriberInterface'
        );
    }

    public function testConstructorRequiresLruFilesystemCache(): void
    {
        // Constructor accepts exactly one required parameter typed LruFilesystemCache.
        $reflection = new \ReflectionMethod(TenantContextClearedListener::class, '__construct');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params, 'constructor must accept exactly one argument');
        $type = $params[0]->getType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertSame(LruFilesystemCache::class, $type->getName());
        $this->assertFalse($params[0]->isDefaultValueAvailable(), 'constructor argument must be required');
    }

    private function makeCloseableOperator(): object
    {
        return new class implements FilesystemOperator {
            public int $closeCalls = 0;

            public function close(): void
            {
                ++$this->closeCalls;
            }

            public function fileExists(string $location): bool
            {
                throw new \LogicException('unused in this test');
            }

            public function directoryExists(string $location): bool
            {
                throw new \LogicException('unused in this test');
            }

            public function has(string $location): bool
            {
                throw new \LogicException('unused in this test');
            }

            public function read(string $location): string
            {
                throw new \LogicException('unused in this test');
            }

            /**
             * @return resource
             */
            public function readStream(string $location)
            {
                throw new \LogicException('unused in this test');
            }

            public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
            {
                throw new \LogicException('unused in this test');
            }

            public function lastModified(string $path): int
            {
                throw new \LogicException('unused in this test');
            }

            public function fileSize(string $path): int
            {
                throw new \LogicException('unused in this test');
            }

            public function mimeType(string $path): string
            {
                throw new \LogicException('unused in this test');
            }

            public function visibility(string $path): string
            {
                throw new \LogicException('unused in this test');
            }

            public function write(string $location, string $contents, array $config = []): void
            {
                throw new \LogicException('unused in this test');
            }

            /**
             * @param resource $contents
             */
            public function writeStream(string $location, $contents, array $config = []): void
            {
                throw new \LogicException('unused in this test');
            }

            public function setVisibility(string $path, string $visibility): void
            {
                throw new \LogicException('unused in this test');
            }

            public function delete(string $location): void
            {
                throw new \LogicException('unused in this test');
            }

            public function deleteDirectory(string $location): void
            {
                throw new \LogicException('unused in this test');
            }

            public function createDirectory(string $location, array $config = []): void
            {
                throw new \LogicException('unused in this test');
            }

            public function move(string $source, string $destination, array $config = []): void
            {
                throw new \LogicException('unused in this test');
            }

            public function copy(string $source, string $destination, array $config = []): void
            {
                throw new \LogicException('unused in this test');
            }
        };
    }
}
