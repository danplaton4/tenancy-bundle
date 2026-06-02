<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Filesystem;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Event\TenantContextCleared;
use Tenancy\Bundle\Exception\MissingFilesystemConfigException;
use Tenancy\Bundle\Filesystem\FilesystemPrefixingDecorator;
use Tenancy\Bundle\Filesystem\LruFilesystemCache;
use Tenancy\Bundle\Filesystem\TenantAwareFilesystemDecorator;

/**
 * Integration test suite for BOOT-03: five DEC-FILE-TEST-ADAPTER scenarios +
 * autowiring-through-decorator regression (RESEARCH.md §Pitfall 6).
 *
 * Exercises the full Phase 24 wiring layer against a real Symfony kernel with
 * league/flysystem-bundle loaded and memory-adapter storages — no network, no
 * real disk IO.
 *
 * Scenarios:
 *   1. testPrefixModeIsolation — acme writes invisible to globex reads; paths
 *      land under tenant_{slug}/ prefix on the shared adapter.
 *   2. testPerTenantAdapterIsolation — distinct per-tenant adapters; LRU cache
 *      has size 2 after both tenants touch the per-tenant-adapter storage.
 *   3. testUntaggedServicesBypassScoping — public.storage bypasses scoping;
 *      file lands at literal path accessible from any tenant context.
 *   4. testMissingFilesystemConfigThrowsLogicException — 'broken' tenant (null
 *      config) triggers MissingFilesystemConfigException extends \LogicException;
 *      NOT instanceof \RuntimeException (Messenger no-retry pin).
 *   5. testLruCacheClearedOnTenantContextCleared — dispatching TenantContextCleared
 *      flushes the LRU cache to size 0.
 *   6. testAutowiringDelivesDecorator — Symfony decoration rewrites users.storage
 *      to the FilesystemPrefixingDecorator; the original service ID resolves to
 *      the decorator (RESEARCH.md §Pitfall 6 regression pin).
 *
 * @see .planning/phases/24-filesystem-bootstrapper/24-CONTEXT.md §DEC-FILE-TEST-ADAPTER
 * @see .planning/phases/24-filesystem-bootstrapper/24-RESEARCH.md §Pitfall 6
 */
final class FilesystemBootstrapperIntegrationTest extends KernelTestCase
{
    private static ?FilesystemTestKernel $filesystem_kernel = null;

    protected static function getKernelClass(): string
    {
        return FilesystemTestKernel::class;
    }

    public static function setUpBeforeClass(): void
    {
        if (!interface_exists(\League\Flysystem\FilesystemOperator::class)) {
            self::markTestSkipped('league/flysystem-bundle not installed');
        }

        // Clear stale kernel cache from prior test runs.
        $cacheRoot = sys_get_temp_dir().'/tenancy_filesystem_test_'.md5(FilesystemTestKernel::class).'_filesystem_test';
        if (is_dir($cacheRoot)) {
            self::removeDir($cacheRoot);
        }

        self::$filesystem_kernel = new FilesystemTestKernel('filesystem_test', true);
        self::$filesystem_kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$filesystem_kernel) {
            self::$filesystem_kernel->shutdown();
            self::$filesystem_kernel = null;
        }

        $cacheRoot = sys_get_temp_dir().'/tenancy_filesystem_test_'.md5(FilesystemTestKernel::class).'_filesystem_test';
        if (is_dir($cacheRoot)) {
            self::removeDir($cacheRoot);
        }
    }

    protected function setUp(): void
    {
        // Reset shared mutable state between test methods.
        $container = $this->kernel()->getContainer();

        /** @var TenantContext $context */
        $context = $container->get('tenancy.context');
        $context->clear();

        /** @var LruFilesystemCache $cache */
        $cache = $container->get('tenancy.filesystem.lru_cache');
        $cache->clear();
    }

    // -------------------------------------------------------------------------
    // Scenario 1: prefix mode isolation
    // -------------------------------------------------------------------------

    public function testPrefixModeIsolation(): void
    {
        $container = $this->kernel()->getContainer();

        /** @var TenantContext $context */
        $context = $container->get('tenancy.context');

        /** @var \Tenancy\Bundle\Provider\TenantProviderInterface $provider */
        $provider = $container->get('tenancy.provider');

        /** @var \League\Flysystem\FilesystemOperator $usersStorage */
        $usersStorage = $container->get('users.storage');

        $acme = $provider->findBySlug('acme');
        $globex = $provider->findBySlug('globex');

        // Write under acme context.
        $context->setTenant($acme);
        $usersStorage->write('reports.txt', 'hello-acme');

        // Switch to globex — file should NOT exist.
        $context->setTenant($globex);
        self::assertFalse(
            $usersStorage->fileExists('reports.txt'),
            'globex should not see acme\'s reports.txt under prefix mode'
        );

        // Write under globex context.
        $usersStorage->write('reports.txt', 'hello-globex');

        // Switch back to acme — original write must be intact.
        $context->setTenant($acme);
        self::assertSame(
            'hello-acme',
            $usersStorage->read('reports.txt'),
            'acme must still read its own reports.txt after globex wrote its own'
        );

        // Verify the PREFIX is actually "tenant_{slug}/" by inspecting the
        // path reported by listContents (prefix is stripped for callers, so the
        // returned path should be tenant-relative, i.e. "reports.txt").
        $listed = [];
        foreach ($usersStorage->listContents('') as $entry) {
            $listed[] = $entry->path();
        }
        self::assertContains(
            'reports.txt',
            $listed,
            'listContents() must return tenant-relative paths (prefix stripped)'
        );
    }

    // -------------------------------------------------------------------------
    // Scenario 2: per_tenant_adapter mode isolation
    // -------------------------------------------------------------------------

    public function testPerTenantAdapterIsolation(): void
    {
        $container = $this->kernel()->getContainer();

        /** @var TenantContext $context */
        $context = $container->get('tenancy.context');

        /** @var \Tenancy\Bundle\Provider\TenantProviderInterface $provider */
        $provider = $container->get('tenancy.provider');

        /** @var \League\Flysystem\FilesystemOperator $bucketsStorage */
        $bucketsStorage = $container->get('tenant_buckets.storage');

        /** @var LruFilesystemCache $cache */
        $cache = $container->get('tenancy.filesystem.lru_cache');

        $acme = $provider->findBySlug('acme');
        $globex = $provider->findBySlug('globex');

        // Write under acme context.
        $context->setTenant($acme);
        $bucketsStorage->write('uploads/doc.txt', 'acme-content');

        // LRU cache should have 1 entry (acme's adapter).
        self::assertSame(1, $cache->size(), 'LRU cache should have 1 entry after first write');

        // Switch to globex — distinct adapter, file must NOT exist there.
        $context->setTenant($globex);
        self::assertFalse(
            $bucketsStorage->fileExists('uploads/doc.txt'),
            'globex\'s per-tenant adapter must NOT see acme\'s uploads/doc.txt'
        );

        $bucketsStorage->write('uploads/doc.txt', 'globex-content');

        // LRU cache should now have 2 entries (acme + globex) — both adapters created.
        self::assertSame(2, $cache->size(), 'LRU cache should have 2 entries after both tenants wrote');

        // Switch back to acme — verify isolation holds.
        $context->setTenant($acme);
        self::assertSame(
            'acme-content',
            $bucketsStorage->read('uploads/doc.txt'),
            'acme must still read its own uploads/doc.txt from its per-tenant adapter'
        );
    }

    // -------------------------------------------------------------------------
    // Scenario 3: untagged services bypass scoping
    // -------------------------------------------------------------------------

    public function testUntaggedServicesBypassScoping(): void
    {
        $container = $this->kernel()->getContainer();

        /** @var TenantContext $context */
        $context = $container->get('tenancy.context');

        /** @var \Tenancy\Bundle\Provider\TenantProviderInterface $provider */
        $provider = $container->get('tenancy.provider');

        /** @var \League\Flysystem\FilesystemOperator $publicStorage */
        $publicStorage = $container->get('public.storage');

        // Write under acme context.
        $acme = $provider->findBySlug('acme');
        $context->setTenant($acme);

        $publicStorage->write('logo.png', 'landlord-logo-data');

        // Switch to globex — untagged storage: file MUST be readable unchanged.
        $globex = $provider->findBySlug('globex');
        $context->setTenant($globex);

        self::assertTrue(
            $publicStorage->fileExists('logo.png'),
            'public.storage (untagged) must be accessible from any tenant context'
        );
        self::assertSame(
            'landlord-logo-data',
            $publicStorage->read('logo.png'),
            'public.storage content must be identical regardless of tenant context'
        );

        // No tenant context — still readable.
        $context->clear();
        self::assertSame(
            'landlord-logo-data',
            $publicStorage->read('logo.png'),
            'public.storage must be readable with no tenant context'
        );
    }

    // -------------------------------------------------------------------------
    // Scenario 4: MissingFilesystemConfigException + no Messenger retry
    // -------------------------------------------------------------------------

    public function testMissingFilesystemConfigThrowsLogicException(): void
    {
        $container = $this->kernel()->getContainer();

        /** @var TenantContext $context */
        $context = $container->get('tenancy.context');

        /** @var \Tenancy\Bundle\Provider\TenantProviderInterface $provider */
        $provider = $container->get('tenancy.provider');

        /** @var \League\Flysystem\FilesystemOperator $bucketsStorage */
        $bucketsStorage = $container->get('tenant_buckets.storage');

        $broken = $provider->findBySlug('broken');
        $context->setTenant($broken);

        $caught = null;
        try {
            $bucketsStorage->write('any.txt', 'data');
        } catch (\Throwable $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'Expected MissingFilesystemConfigException to be thrown');
        self::assertInstanceOf(
            MissingFilesystemConfigException::class,
            $caught,
            'Exception must be MissingFilesystemConfigException'
        );
        // LogicException ancestry → Messenger does NOT retry this message.
        self::assertInstanceOf(
            \LogicException::class,
            $caught,
            'MissingFilesystemConfigException must extend \\LogicException (Messenger no-retry pin)'
        );
        // Negative assertion: must NOT be RuntimeException (that would trigger retry).
        // PHPStan knows this is statically true (MissingFilesystemConfigException ⊂ LogicException,
        // disjoint from RuntimeException) — the inline assertion is intentional documentation of
        // the DEC-FILE-EXCEPTION / Messenger no-retry invariant, not a runtime guard.
        // Mirrors Phase 23-02 WR-01 pattern. See STATE.md §Decisions [Phase 23-02].
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertNotInstanceOf(
            \RuntimeException::class,
            $caught,
            'MissingFilesystemConfigException must NOT be instanceof \\RuntimeException'
        );
    }

    // -------------------------------------------------------------------------
    // Scenario 5: LRU cache cleared on TenantContextCleared
    // -------------------------------------------------------------------------

    public function testLruCacheClearedOnTenantContextCleared(): void
    {
        $container = $this->kernel()->getContainer();

        /** @var TenantContext $context */
        $context = $container->get('tenancy.context');

        /** @var \Tenancy\Bundle\Provider\TenantProviderInterface $provider */
        $provider = $container->get('tenancy.provider');

        /** @var LruFilesystemCache $cache */
        $cache = $container->get('tenancy.filesystem.lru_cache');

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get('event_dispatcher');

        /** @var \League\Flysystem\FilesystemOperator $bucketsStorage */
        $bucketsStorage = $container->get('tenant_buckets.storage');

        // Prime the cache with at least one entry.
        $acme = $provider->findBySlug('acme');
        $context->setTenant($acme);
        $bucketsStorage->write('probe.txt', 'x');

        self::assertGreaterThanOrEqual(
            1,
            $cache->size(),
            'LRU cache must have at least 1 entry after a write'
        );

        // Dispatch the long-worker teardown event.
        $dispatcher->dispatch(new TenantContextCleared());

        self::assertSame(
            0,
            $cache->size(),
            'TenantContextClearedListener must have flushed the LRU cache to size 0'
        );
    }

    // -------------------------------------------------------------------------
    // Scenario 6: autowiring-through-decorator regression (RESEARCH.md Pitfall 6)
    // -------------------------------------------------------------------------

    public function testAutowiringDeliversDecorator(): void
    {
        $container = $this->kernel()->getContainer();

        // users.storage is wrapped by FilesystemContractPass via setDecoratedService().
        // Symfony's service decoration rewrites the original service ID to the
        // outer decorator — $container->get('users.storage') MUST return the
        // decorator, not the inner League\Flysystem\Filesystem.
        $usersStorage = $container->get('users.storage');
        self::assertInstanceOf(
            FilesystemPrefixingDecorator::class,
            $usersStorage,
            'users.storage must resolve to FilesystemPrefixingDecorator (RESEARCH Pitfall 6 — decorator must wrap the service)'
        );

        // tenant_buckets.storage similarly decorated with TenantAwareFilesystemDecorator.
        $bucketsStorage = $container->get('tenant_buckets.storage');
        self::assertInstanceOf(
            TenantAwareFilesystemDecorator::class,
            $bucketsStorage,
            'tenant_buckets.storage must resolve to TenantAwareFilesystemDecorator'
        );

        // public.storage (untagged) must NOT be a decorator instance.
        $publicStorage = $container->get('public.storage');
        self::assertNotInstanceOf(
            FilesystemPrefixingDecorator::class,
            $publicStorage,
            'public.storage (untagged) must NOT be wrapped by a tenancy decorator'
        );
        self::assertNotInstanceOf(
            TenantAwareFilesystemDecorator::class,
            $publicStorage,
            'public.storage (untagged) must NOT be wrapped by TenantAwareFilesystemDecorator'
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function kernel(): FilesystemTestKernel
    {
        if (null === self::$filesystem_kernel) {
            self::markTestSkipped('Filesystem kernel not booted (league/flysystem-bundle absent)');
        }

        return self::$filesystem_kernel;
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if (false === $items) {
            return;
        }

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.\DIRECTORY_SEPARATOR.$item;
            if (is_dir($path) && !is_link($path)) {
                self::removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
