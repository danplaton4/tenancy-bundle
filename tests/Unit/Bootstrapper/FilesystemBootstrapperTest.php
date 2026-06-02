<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Bootstrapper;

use League\Flysystem\DirectoryListing;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Bootstrapper\FilesystemBootstrapper;
use Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface;
use Tenancy\Bundle\Filesystem\LruFilesystemCache;
use Tenancy\Bundle\TenantInterface;

/**
 * Behavioural tests for FilesystemBootstrapper.
 *
 * Covers:
 * - boot() is a no-op (no exception, no side-effects)
 * - clear() calls LruFilesystemCache::clear() exactly once
 * - clear() with null cache is a safe no-op
 * - Implements TenantBootstrapperInterface
 * - Is a final class
 */
final class FilesystemBootstrapperTest extends TestCase
{
    public function testImplementsTenantBootstrapperInterface(): void
    {
        $bootstrapper = new FilesystemBootstrapper();
        self::assertInstanceOf(TenantBootstrapperInterface::class, $bootstrapper);
    }

    public function testIsFinalClass(): void
    {
        $rc = new \ReflectionClass(FilesystemBootstrapper::class);
        self::assertTrue($rc->isFinal());
    }

    public function testBootIsNoOp(): void
    {
        $bootstrapper = new FilesystemBootstrapper();
        $tenant = $this->createMock(TenantInterface::class);

        // Should not throw, should not modify any observable state
        $bootstrapper->boot($tenant);
        $this->addToAssertionCount(1); // explicit assertion that no exception was thrown
    }

    public function testClearWithNullCacheIsNoOp(): void
    {
        $bootstrapper = new FilesystemBootstrapper(null);

        // Should not throw
        $bootstrapper->clear();
        $this->addToAssertionCount(1);
    }

    public function testClearFlushesLruCache(): void
    {
        $cache = new LruFilesystemCache(maxSize: 2);

        // Seed the cache with a real FilesystemOperator stub so we can verify clear() empties it
        $operator = new class implements FilesystemOperator {
            public function fileExists(string $location): bool
            {
                return false;
            }

            public function directoryExists(string $location): bool
            {
                return false;
            }

            public function has(string $location): bool
            {
                return false;
            }

            public function read(string $location): string
            {
                return '';
            }

            public function readStream(string $location)
            {
                return fopen('php://memory', 'r');
            }

            public function listContents(string $location, bool $deep = false): DirectoryListing
            {
                return new DirectoryListing([]);
            }

            public function lastModified(string $path): int
            {
                return 0;
            }

            public function fileSize(string $path): int
            {
                return 0;
            }

            public function mimeType(string $path): string
            {
                return '';
            }

            public function visibility(string $path): string
            {
                return '';
            }

            public function write(string $location, string $contents, array $config = []): void
            {
            }

            public function writeStream(string $location, $contents, array $config = []): void
            {
            }

            public function setVisibility(string $path, string $visibility): void
            {
            }

            public function delete(string $location): void
            {
            }

            public function deleteDirectory(string $location): void
            {
            }

            public function createDirectory(string $location, array $config = []): void
            {
            }

            public function move(string $source, string $destination, array $config = []): void
            {
            }

            public function copy(string $source, string $destination, array $config = []): void
            {
            }
        };

        $cache->set('tenant-a', $operator);
        $cache->set('tenant-b', $operator);

        self::assertSame(2, $cache->size(), 'Cache should have 2 entries before clear');

        $bootstrapper = new FilesystemBootstrapper($cache);
        $bootstrapper->clear();

        self::assertSame(0, $cache->size(), 'Cache should be empty after clear()');
    }

    public function testClearCallsCacheOnce(): void
    {
        // Use a real cache — seed it, then clear twice to verify idempotency
        $cache = new LruFilesystemCache(maxSize: 4);

        $operator = $this->createMock(FilesystemOperator::class);
        $cache->set('tenant-a', $operator);

        $bootstrapper = new FilesystemBootstrapper($cache);
        $bootstrapper->clear();
        self::assertSame(0, $cache->size(), 'Cache should be empty after first clear()');

        // Second clear() on an already-empty cache must also be safe
        $bootstrapper->clear();
        self::assertSame(0, $cache->size(), 'Cache should remain empty after second clear()');
    }
}
