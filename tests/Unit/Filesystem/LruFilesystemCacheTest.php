<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Filesystem;

use League\Flysystem\DirectoryListing;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Filesystem\LruFilesystemCache;

/**
 * Behavioural tests for LruFilesystemCache (per-tenant FilesystemOperator LRU).
 *
 * Mirrors tests/Unit/Mailer/LruTransportCacheTest assertion-for-assertion,
 * substituting FilesystemOperator for TransportInterface and close() for stop().
 *
 * @see .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-MODE
 * @see .planning/phases/24-filesystem-bootstrapper/24-03-PLAN.md Task 1
 */
final class LruFilesystemCacheTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists(FilesystemOperator::class)) {
            self::markTestSkipped('league/flysystem not installed');
        }
    }

    public function testGetReturnsNullOnEmptyCache(): void
    {
        $cache = new LruFilesystemCache();
        self::assertNull($cache->get('foo'));
    }

    public function testSetThenGetRoundTripIncrementsHits(): void
    {
        $cache = new LruFilesystemCache();
        $fs = self::makePlainOperator('t1');

        $cache->set('foo', $fs);
        self::assertSame($fs, $cache->get('foo'));
        self::assertSame(1, $cache->hits());
    }

    public function testEvictsLeastRecentlyUsedOnOverflow(): void
    {
        $cache = new LruFilesystemCache(2);
        $a = self::makeCloseableOperator('a');
        $b = self::makeCloseableOperator('b');
        $c = self::makeCloseableOperator('c');

        $cache->set('a', $a);
        $cache->set('b', $b);
        $cache->set('c', $c); // should evict 'a' (LRU)

        self::assertNull($cache->get('a'));
        self::assertSame($b, $cache->get('b'));
        self::assertSame($c, $cache->get('c'));
        self::assertSame(1, $cache->evictions());
    }

    public function testGetTouchesLruOrder(): void
    {
        $cache = new LruFilesystemCache(2);
        $a = self::makeCloseableOperator('a');
        $b = self::makeCloseableOperator('b');
        $c = self::makeCloseableOperator('c');

        $cache->set('a', $a);
        $cache->set('b', $b);
        $cache->get('a');     // touch a → b is now LRU
        $cache->set('c', $c); // should evict b, not a

        self::assertSame($a, $cache->get('a'));
        self::assertNull($cache->get('b'));
        self::assertSame($c, $cache->get('c'));
    }

    public function testClearClosesAllAndEmpties(): void
    {
        $cache = new LruFilesystemCache(3);
        $a = self::makeCloseableOperator('a');
        $b = self::makeCloseableOperator('b');

        $cache->set('a', $a);
        $cache->set('b', $b);

        $cache->clear();

        self::assertSame(1, $a->closeCalls);
        self::assertSame(1, $b->closeCalls);
        self::assertNull($cache->get('a'));
        self::assertNull($cache->get('b'));
        self::assertSame(0, $cache->size());
    }

    public function testEvictedOperatorHasCloseCalled(): void
    {
        $cache = new LruFilesystemCache(1);
        $a = self::makeCloseableOperator('a');
        $b = self::makeCloseableOperator('b');

        $cache->set('a', $a);
        self::assertSame(0, $a->closeCalls);

        $cache->set('b', $b); // evicts a

        self::assertSame(1, $a->closeCalls, 'evicted operator must receive close()');
        self::assertSame(0, $b->closeCalls, 'still-resident operator must not receive close()');
    }

    public function testPlainOperatorWithoutCloseIsEvictedGracefully(): void
    {
        $cache = new LruFilesystemCache(1);
        $plain = self::makePlainOperator('plain');
        $next = self::makePlainOperator('next');

        $cache->set('a', $plain);
        // Should NOT raise — method_exists() guard avoids calling close()
        $cache->set('b', $next);

        self::assertNull($cache->get('a'));
        self::assertSame($next, $cache->get('b'));
        self::assertSame(1, $cache->evictions());
    }

    public function testHitsAndEvictionsCountersRoundTrip(): void
    {
        $cache = new LruFilesystemCache(2);
        $a = self::makeCloseableOperator('a');
        $b = self::makeCloseableOperator('b');
        $c = self::makeCloseableOperator('c');

        $cache->set('a', $a);
        $cache->set('b', $b);
        $cache->get('a');
        $cache->get('a');
        $cache->get('b');

        self::assertSame(3, $cache->hits());
        self::assertSame(0, $cache->evictions());

        $cache->set('c', $c); // evicts b (b was last accessed before a's second touch... wait)
        // After three gets above: a (touched twice), b (touched once last) → most-recent = b, oldest = a.
        // So set('c') evicts a, not b.
        self::assertSame(1, $cache->evictions());
        self::assertNull($cache->get('a'));
    }

    public function testDefaultMaxSizeIsThirtyTwo(): void
    {
        $cache = new LruFilesystemCache();
        self::assertSame(32, $cache->maxSize());
    }

    public function testReSetSameSlugDoesNotEvictOthers(): void
    {
        $cache = new LruFilesystemCache(2);
        $a = self::makeCloseableOperator('a');
        $b = self::makeCloseableOperator('b');
        $a2 = self::makeCloseableOperator('a2');

        $cache->set('a', $a);
        $cache->set('b', $b);
        $cache->set('a', $a2); // update — does NOT evict b

        self::assertSame($a2, $cache->get('a'));
        self::assertSame($b, $cache->get('b'));
        self::assertSame(0, $a->closeCalls, 'replacing slug must not close() the prior value');
        self::assertSame(0, $b->closeCalls);
    }

    private static function makePlainOperator(string $label): FilesystemOperator
    {
        return new class($label) implements FilesystemOperator {
            public function __construct(private readonly string $label = 'plain')
            {
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

    private static function makeCloseableOperator(string $label): object
    {
        return new class($label) implements FilesystemOperator {
            public int $closeCalls = 0;

            public function __construct(private readonly string $label = 'closeable')
            {
            }

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
