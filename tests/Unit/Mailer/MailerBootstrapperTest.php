<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Mailer;

use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Bootstrapper\MailerBootstrapper;
use Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface;
use Tenancy\Bundle\Mailer\LruTransportCache;
use Tenancy\Bundle\TenantInterface;
use Tenancy\Bundle\Tests\Unit\Mailer\Fixture\StoppableSpyTransport;

/**
 * Behavior tests for MailerBootstrapper.
 *
 * Covers BOOT-04-a per .planning/phases/20-mailer-bootstrapper/20-VALIDATION.md:
 * MailerBootstrapper implements TenantBootstrapperInterface, boot() is a no-op,
 * clear() flushes the LRU transport cache (per D-07 — mailer cleanup before EM reset).
 */
final class MailerBootstrapperTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists(\Symfony\Component\Mailer\MailerInterface::class)) {
            $this->markTestSkipped('symfony/mailer not installed');
        }
    }

    public function testImplementsTenantBootstrapperInterface(): void
    {
        $bootstrapper = new MailerBootstrapper(null);
        $this->assertInstanceOf(TenantBootstrapperInterface::class, $bootstrapper);
    }

    public function testBootIsNoOp(): void
    {
        // Use a real LruTransportCache (final, not mockable) loaded with a stoppable
        // spy transport. boot() must NOT call clear() — verified by asserting the spy
        // recorded zero stop() calls and the cache still contains the entry.
        $cache = new LruTransportCache(32);
        $spy = new StoppableSpyTransport('boot-noop-spy');
        $cache->set('acme', $spy);

        $tenant = $this->createMock(TenantInterface::class);

        $bootstrapper = new MailerBootstrapper($cache);
        $bootstrapper->boot($tenant);

        $this->assertSame(0, $spy->stopCalls, 'boot() must not invoke transport stop()');
        $this->assertSame(1, $cache->size(), 'boot() must not clear the cache');
    }

    public function testClearFlushesLruTransportCache(): void
    {
        // Real LruTransportCache + spy: clear() must propagate to cache->clear(),
        // which calls stop() on every cached transport (D-07 mailer-before-EM).
        $cache = new LruTransportCache(32);
        $spy = new StoppableSpyTransport('clear-flush-spy');
        $cache->set('acme', $spy);

        $bootstrapper = new MailerBootstrapper($cache);
        $bootstrapper->clear();

        $this->assertSame(1, $spy->stopCalls, 'clear() must flush LRU which stops each cached transport');
        $this->assertSame(0, $cache->size(), 'clear() must empty the cache');
    }

    public function testClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(MailerBootstrapper::class);
        $this->assertTrue($reflection->isFinal(), 'MailerBootstrapper must be final');
    }

    public function testConstructorAcceptsNullLruTransportCache(): void
    {
        // When mailer dep is absent the LRU isn't registered as a service — the
        // bootstrapper still loads via constructor with null. clear() then short-circuits.
        $bootstrapper = new MailerBootstrapper(null);
        $bootstrapper->clear(); // must not error

        $this->assertInstanceOf(TenantBootstrapperInterface::class, $bootstrapper);

        // Reflection confirms the constructor parameter is nullable.
        $reflection = new \ReflectionClass(MailerBootstrapper::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
        $params = $constructor->getParameters();
        $this->assertCount(1, $params);
        $type = $params[0]->getType();
        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertTrue($type->allowsNull(), 'Constructor cache parameter must be nullable');
        $this->assertSame(LruTransportCache::class, $type->getName());
    }
}
