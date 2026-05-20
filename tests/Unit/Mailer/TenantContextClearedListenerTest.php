<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Mailer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Tenancy\Bundle\Event\TenantContextCleared;
use Tenancy\Bundle\Mailer\LruTransportCache;
use Tenancy\Bundle\Mailer\TenantContextClearedListener;
use Tenancy\Bundle\Tests\Unit\Mailer\Fixture\StoppableSpyTransport;

/**
 * Behavior tests for TenantContextClearedListener.
 *
 * Covers BOOT-04 (event-driven flush path) per Plan 20-05:
 * the listener subscribes to TenantContextCleared and invokes
 * LruTransportCache::clear() exactly once per dispatch, ensuring every
 * tenant teardown closes its SMTP sockets even when the BootstrapperChain
 * cleanup is bypassed.
 */
final class TenantContextClearedListenerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists(\Symfony\Component\Mailer\MailerInterface::class)) {
            $this->markTestSkipped('symfony/mailer not installed');
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
        // Use a real LruTransportCache (final, not mockable) seeded with a
        // stoppable spy — assert stop() is called exactly once via clear().
        $cache = new LruTransportCache(32);
        $spy = new StoppableSpyTransport('listener-spy');
        $cache->set('acme', $spy);

        $listener = new TenantContextClearedListener($cache);
        $listener->onContextCleared(new TenantContextCleared());

        $this->assertSame(1, $spy->stopCalls, 'clear() must invoke stop() once on each cached transport');
        $this->assertSame(0, $cache->size(), 'clear() must empty the cache');
    }

    public function testMultipleDispatchesEachTriggerClear(): void
    {
        // No debouncing: each dispatch invokes cache->clear() independently.
        $cache = new LruTransportCache(32);
        $listener = new TenantContextClearedListener($cache);

        $spy1 = new StoppableSpyTransport('spy-1');
        $cache->set('acme', $spy1);
        $listener->onContextCleared(new TenantContextCleared());
        $this->assertSame(1, $spy1->stopCalls);
        $this->assertSame(0, $cache->size());

        $spy2 = new StoppableSpyTransport('spy-2');
        $cache->set('beta', $spy2);
        $listener->onContextCleared(new TenantContextCleared());
        $this->assertSame(1, $spy2->stopCalls, 'Second dispatch must clear() the cache again');
        $this->assertSame(0, $cache->size());
    }

    public function testClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(TenantContextClearedListener::class);

        $this->assertTrue($reflection->isFinal(), 'TenantContextClearedListener must be final');
        $this->assertTrue(
            $reflection->implementsInterface(EventSubscriberInterface::class),
            'TenantContextClearedListener must implement EventSubscriberInterface'
        );
    }
}
