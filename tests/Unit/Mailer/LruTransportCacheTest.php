<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Mailer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Tenancy\Bundle\Mailer\LruTransportCache;
use Tenancy\Bundle\Tests\Unit\Mailer\Fixture\PlainSpyTransport;
use Tenancy\Bundle\Tests\Unit\Mailer\Fixture\StoppableSpyTransport;

/**
 * @see .planning/phases/20-mailer-bootstrapper/20-VALIDATION.md row BOOT-04-d/h
 */
final class LruTransportCacheTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists(TransportInterface::class)) {
            self::markTestSkipped('symfony/mailer not installed');
        }
    }

    public function testGetReturnsNullOnEmptyCache(): void
    {
        $cache = new LruTransportCache();
        self::assertNull($cache->get('foo'));
    }

    public function testSetThenGetRoundTrip(): void
    {
        $cache = new LruTransportCache();
        $t = new PlainSpyTransport('t1');
        $cache->set('foo', $t);
        self::assertSame($t, $cache->get('foo'));
    }

    public function testEvictsLeastRecentlyUsedOnOverflow(): void
    {
        $cache = new LruTransportCache(2);
        $a = new StoppableSpyTransport('a');
        $b = new StoppableSpyTransport('b');
        $c = new StoppableSpyTransport('c');

        $cache->set('a', $a);
        $cache->set('b', $b);
        $cache->set('c', $c); // should evict 'a' (LRU)

        self::assertNull($cache->get('a'));
        self::assertSame($b, $cache->get('b'));
        self::assertSame($c, $cache->get('c'));
    }

    public function testGetTouchesLruOrder(): void
    {
        $cache = new LruTransportCache(2);
        $a = new StoppableSpyTransport('a');
        $b = new StoppableSpyTransport('b');
        $c = new StoppableSpyTransport('c');

        $cache->set('a', $a);
        $cache->set('b', $b);
        $cache->get('a');     // touch a → b is now LRU
        $cache->set('c', $c); // should evict b, not a

        self::assertSame($a, $cache->get('a'));
        self::assertNull($cache->get('b'));
        self::assertSame($c, $cache->get('c'));
    }

    public function testEvictedTransportHasStopCalled(): void
    {
        $cache = new LruTransportCache(1);
        $a = new StoppableSpyTransport('a');
        $b = new StoppableSpyTransport('b');

        $cache->set('a', $a);
        self::assertSame(0, $a->stopCalls);

        $cache->set('b', $b); // evicts a

        self::assertSame(1, $a->stopCalls, 'evicted transport must receive stop()');
        self::assertSame(0, $b->stopCalls, 'still-resident transport must not receive stop()');
    }

    public function testClearStopsAllAndEmpties(): void
    {
        $cache = new LruTransportCache(3);
        $a = new StoppableSpyTransport('a');
        $b = new StoppableSpyTransport('b');

        $cache->set('a', $a);
        $cache->set('b', $b);

        $cache->clear();

        self::assertSame(1, $a->stopCalls);
        self::assertSame(1, $b->stopCalls);
        self::assertNull($cache->get('a'));
        self::assertNull($cache->get('b'));
        self::assertSame(0, $cache->size());
    }

    public function testReSetSameSlugDoesNotEvictOthers(): void
    {
        $cache = new LruTransportCache(2);
        $a = new StoppableSpyTransport('a');
        $b = new StoppableSpyTransport('b');
        $a2 = new StoppableSpyTransport('a2');

        $cache->set('a', $a);
        $cache->set('b', $b);
        $cache->set('a', $a2); // update — does NOT evict b

        self::assertSame($a2, $cache->get('a'));
        self::assertSame($b, $cache->get('b'));
        self::assertSame(0, $a->stopCalls, 'replacing slug must not stop() the prior value');
        self::assertSame(0, $b->stopCalls);
    }

    public function testPlainTransportWithoutStopIsEvictedGracefully(): void
    {
        $cache = new LruTransportCache(1);
        $plain = new PlainSpyTransport('plain');
        $next = new PlainSpyTransport('next');

        $cache->set('a', $plain);
        // Should NOT raise — method_exists() guard avoids calling stop()
        $cache->set('b', $next);

        self::assertNull($cache->get('a'));
        self::assertSame($next, $cache->get('b'));
    }

    public function testDefaultMaxSizeIsThirtyTwo(): void
    {
        // D-03 default size verified via constructor parameter reflection.
        $reflection = new \ReflectionMethod(LruTransportCache::class, '__construct');
        $param = $reflection->getParameters()[0];
        self::assertTrue($param->isDefaultValueAvailable());
        self::assertSame(32, $param->getDefaultValue());
    }
}
