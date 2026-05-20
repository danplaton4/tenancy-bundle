<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Integration\Mailer;

use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Event\TenantContextCleared;
use Tenancy\Bundle\Mailer\LruTransportCache;
use Tenancy\Bundle\Tests\Integration\Messenger\Support\StubTenant;

/**
 * Roadmap success criterion 6: long-running worker stays bounded.
 *
 * Boots the full Mailer test kernel and drives the LRU transport cache
 * through 100 distinct tenants — once with intermediate TenantContextCleared
 * events (the canonical worker iteration shape) and once without (pure LRU
 * eviction). Final assertion verifies that the TenantContextClearedListener
 * (Plan 20-05 Task 1) is actually wired into the event dispatcher: dispatching
 * the event through the real container must empty the cache.
 *
 * No network, no real SMTP, no real Messenger transport — all assertions are
 * on LruTransportCache state.
 */
final class LongRunningWorkerSimulationTest extends TestCase
{
    private static ?MailerTestKernel $kernel = null;

    public static function setUpBeforeClass(): void
    {
        if (!interface_exists(\Symfony\Component\Mailer\MailerInterface::class)) {
            self::markTestSkipped('symfony/mailer not installed');
        }

        // Wipe stale kernel cache from prior runs (different process, same /var/folders).
        $cacheDir = sys_get_temp_dir().'/tenancy_mailer_test';
        if (is_dir($cacheDir)) {
            self::removeDir($cacheDir);
        }

        self::$kernel = new MailerTestKernel('mailer_test', false);
        self::$kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$kernel) {
            self::$kernel->shutdown();
            self::$kernel = null;
        }

        $cacheDir = sys_get_temp_dir().'/tenancy_mailer_test';
        if (is_dir($cacheDir)) {
            self::removeDir($cacheDir);
        }
    }

    protected function setUp(): void
    {
        // Reset shared mutable state between tests in this class.
        $container = $this->kernel()->getContainer();
        /** @var LruTransportCache $cache */
        $cache = $container->get('tenancy.mailer.lru_cache');
        $cache->clear();

        /** @var TenantContext $context */
        $context = $container->get('tenancy.context');
        $context->clear();
    }

    /**
     * Type-narrowing accessor so static analysis sees a non-null kernel.
     * setUpBeforeClass() either boots the kernel or skips the whole class.
     */
    private function kernel(): MailerTestKernel
    {
        if (null === self::$kernel) {
            self::markTestSkipped('Kernel not booted (symfony/mailer absent)');
        }

        return self::$kernel;
    }

    public function testCacheSizeRemainsBoundedAcross100Tenants(): void
    {
        $container = $this->kernel()->getContainer();
        /** @var LruTransportCache $cache */
        $cache = $container->get('tenancy.mailer.lru_cache');
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get('event_dispatcher');
        /** @var TenantContext $context */
        $context = $container->get('tenancy.context');

        $maxSize = $cache->maxSize(); // 32 by default

        for ($i = 0; $i < 100; ++$i) {
            $slug = 'tenant_'.$i;
            $tenant = new StubTenant($slug);
            $tenant->setMailerDsn(sprintf('smtp://u:p@host-%d:25', $i));

            // Simulate one worker iteration: activate context, populate
            // the cache (proxy for a real send going through the decorator),
            // then dispatch the teardown event.
            $context->setTenant($tenant);
            $cache->set($slug, new SpyTransport($tenant->getMailerDsn() ?? ''));

            self::assertLessThanOrEqual(
                $maxSize,
                $cache->size(),
                sprintf('After tenant %d, cache size exceeded maxSize (%d)', $i, $maxSize)
            );

            $dispatcher->dispatch(new TenantContextCleared());

            self::assertSame(
                0,
                $cache->size(),
                sprintf('After TenantContextCleared for tenant %d, cache should be empty', $i)
            );

            $context->clear();
        }
    }

    public function testCacheLruEvictionStaysBoundedWithoutContextClear(): void
    {
        $container = $this->kernel()->getContainer();
        /** @var LruTransportCache $cache */
        $cache = $container->get('tenancy.mailer.lru_cache');
        $cache->clear();

        $maxSize = $cache->maxSize();

        for ($i = 0; $i < 100; ++$i) {
            $cache->set('tenant_'.$i, new SpyTransport('smtp://h-'.$i));
            self::assertLessThanOrEqual($maxSize, $cache->size());
        }

        // Final size sits at maxSize — LRU has been evicting.
        self::assertSame($maxSize, $cache->size());
        // Most recent slug is still present.
        self::assertNotNull($cache->get('tenant_99'));
        // Earliest slugs were evicted.
        self::assertNull($cache->get('tenant_0'));
        self::assertGreaterThanOrEqual(
            100 - $maxSize,
            $cache->evictions(),
            'LRU should have evicted at least (100 - maxSize) entries'
        );
    }

    public function testListenerActuallyWiredIntoEventDispatcher(): void
    {
        // Sanity check: dispatching the event through the real container must
        // trigger the listener and empty the cache. Without the listener wired
        // in services.php (Plan 05 Task 1), this fails.
        $container = $this->kernel()->getContainer();
        /** @var LruTransportCache $cache */
        $cache = $container->get('tenancy.mailer.lru_cache');
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get('event_dispatcher');

        $cache->set('warmup', new SpyTransport('smtp://x'));
        self::assertSame(1, $cache->size());

        $dispatcher->dispatch(new TenantContextCleared());

        self::assertSame(
            0,
            $cache->size(),
            'TenantContextClearedListener must have flushed the cache'
        );
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
