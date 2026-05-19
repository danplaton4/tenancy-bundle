<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Mailer;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tenancy\Bundle\Bootstrapper\MailerBootstrapper;
use Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface;
use Tenancy\Bundle\Mailer\LruTransportCache;
use Tenancy\Bundle\TenantInterface;

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
        /** @var LruTransportCache&MockObject $cache */
        $cache = $this->createMock(LruTransportCache::class);
        $cache->expects($this->never())->method($this->anything());

        $tenant = $this->createMock(TenantInterface::class);

        $bootstrapper = new MailerBootstrapper($cache);
        $bootstrapper->boot($tenant);
    }

    public function testClearFlushesLruTransportCache(): void
    {
        /** @var LruTransportCache&MockObject $cache */
        $cache = $this->createMock(LruTransportCache::class);
        $cache->expects($this->once())->method('clear');

        $bootstrapper = new MailerBootstrapper($cache);
        $bootstrapper->clear();
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
        $this->assertTrue(true);
    }
}
