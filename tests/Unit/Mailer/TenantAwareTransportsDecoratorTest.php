<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Mailer;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Mailer\LruTransportCache;
use Tenancy\Bundle\Mailer\TenantAwareTransportsDecorator;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;
use Tenancy\Bundle\Tests\Integration\Support\StubTenantMailerExtension;
use Tenancy\Bundle\Tests\Unit\Mailer\Fixture\PlainSpyTransport;

/**
 * Behavior tests for TenantAwareTransportsDecorator.
 *
 * Covers BOOT-04-c per .planning/phases/20-mailer-bootstrapper/20-VALIDATION.md:
 * tenant_<slug> X-Transport routing through TenantProviderInterface::findBySlug
 * + LruTransportCache; non-tenant traffic passes through unchanged; header
 * stripped after routing; EventDispatcher pass-through to Transport::fromDsn
 * so SentMessageEvent / FailedMessageEvent fire from tenant transports.
 */
final class TenantAwareTransportsDecoratorTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists(TransportInterface::class)) {
            $this->markTestSkipped('symfony/mailer not installed');
        }
    }

    private function makeTenant(?string $dsn, string $slug = 'acme'): TenantInterface
    {
        $tenant = new class implements TenantInterface {
            use StubTenantMailerExtension;

            private string $slug = 'acme';

            public function getSlug(): string
            {
                return $this->slug;
            }

            public function setSlug(string $slug): void
            {
                $this->slug = $slug;
            }

            public function getDomain(): ?string
            {
                return null;
            }

            public function getConnectionConfig(): array
            {
                return [];
            }

            public function getName(): string
            {
                return $this->slug;
            }

            public function isActive(): bool
            {
                return true;
            }
        };
        $tenant->setSlug($slug);
        $tenant->setMailerDsn($dsn);

        return $tenant;
    }

    public function testImplementsTransportInterfaceAndIsFinal(): void
    {
        $reflection = new \ReflectionClass(TenantAwareTransportsDecorator::class);
        $this->assertTrue($reflection->isFinal(), 'class must be final');
        $this->assertTrue($reflection->implementsInterface(TransportInterface::class));
    }

    public function testDelegatesToInnerWhenNoXTransportHeader(): void
    {
        $inner = $this->createMock(TransportInterface::class);
        $provider = $this->createMock(TenantProviderInterface::class);
        $cache = new LruTransportCache(8);
        $context = new TenantContext();

        $email = (new Email())->from('x@y')->to('a@b')->text('hi');
        $inner->expects($this->once())->method('send')->with($email);
        $provider->expects($this->never())->method('findBySlug');

        $decorator = new TenantAwareTransportsDecorator($inner, $provider, $cache, $context);
        $decorator->send($email);
    }

    public function testDelegatesToInnerWhenXTransportNotTenantPrefixed(): void
    {
        $inner = $this->createMock(TransportInterface::class);
        $provider = $this->createMock(TenantProviderInterface::class);
        $cache = new LruTransportCache(8);
        $context = new TenantContext();

        $email = (new Email())->from('x@y')->to('a@b')->text('hi');
        $email->getHeaders()->addTextHeader('X-Transport', 'marketing');

        $inner->expects($this->once())->method('send')->with($email);
        $provider->expects($this->never())->method('findBySlug');

        $decorator = new TenantAwareTransportsDecorator($inner, $provider, $cache, $context);
        $decorator->send($email);

        // Non-tenant header preserved on delegate; only tenant_* routing strips the header.
        $this->assertTrue($email->getHeaders()->has('X-Transport'));
    }

    public function testUsesCachedTransportOnLruHit(): void
    {
        $inner = $this->createMock(TransportInterface::class);
        $inner->expects($this->never())->method('send');

        $provider = $this->createMock(TenantProviderInterface::class);
        $provider->expects($this->never())->method('findBySlug');

        $cache = new LruTransportCache(8);
        $cachedSpy = new PlainSpyTransport('cached');
        $cache->set('acme', $cachedSpy);

        $context = new TenantContext();

        $email = (new Email())->from('x@y')->to('a@b')->text('hi');
        $email->getHeaders()->addTextHeader('X-Transport', 'tenant_acme');

        $factoryInvocations = 0;
        $factory = function (string $dsn, ?EventDispatcherInterface $dispatcher) use (&$factoryInvocations): TransportInterface {
            ++$factoryInvocations;

            return new PlainSpyTransport('built');
        };

        $decorator = new TenantAwareTransportsDecorator($inner, $provider, $cache, $context, null, $factory);
        $decorator->send($email);

        $this->assertSame(0, $factoryInvocations, 'cache hit must not invoke transport factory');
        $this->assertFalse($email->getHeaders()->has('X-Transport'), 'X-Transport must be stripped after routing');
    }

    public function testBuildsAndCachesTransportOnLruMiss(): void
    {
        $inner = $this->createMock(TransportInterface::class);
        $inner->expects($this->never())->method('send');

        $tenant = $this->makeTenant('smtp://acme', 'acme');
        $provider = $this->createMock(TenantProviderInterface::class);
        $provider->expects($this->once())->method('findBySlug')->with('acme')->willReturn($tenant);

        $cache = new LruTransportCache(8);
        $context = new TenantContext();

        $email = (new Email())->from('x@y')->to('a@b')->text('hi');
        $email->getHeaders()->addTextHeader('X-Transport', 'tenant_acme');

        $capturedDsn = null;
        $built = new PlainSpyTransport('built');
        $factory = function (string $dsn, ?EventDispatcherInterface $dispatcher) use (&$capturedDsn, $built): TransportInterface {
            $capturedDsn = $dsn;

            return $built;
        };

        $decorator = new TenantAwareTransportsDecorator($inner, $provider, $cache, $context, null, $factory);
        $decorator->send($email);

        $this->assertSame('smtp://acme', $capturedDsn);
        $this->assertSame($built, $cache->get('acme'), 'built transport must be stored in LRU');
        $this->assertFalse($email->getHeaders()->has('X-Transport'));
    }

    public function testStripsXTransportHeaderAfterRouting(): void
    {
        $inner = $this->createMock(TransportInterface::class);
        $tenant = $this->makeTenant('smtp://acme', 'acme');
        $provider = $this->createMock(TenantProviderInterface::class);
        $provider->method('findBySlug')->willReturn($tenant);

        $cache = new LruTransportCache(8);
        $context = new TenantContext();

        $email = (new Email())->from('x@y')->to('a@b')->text('hi');
        $email->getHeaders()->addTextHeader('X-Transport', 'tenant_acme');

        $sawHeader = null;
        $factory = function () use (&$sawHeader, $email): TransportInterface {
            $spy = new class($sawHeader) implements TransportInterface {
                /** @param-out bool $sawHeader */
                public function __construct(private mixed &$sawHeader)
                {
                }

                public function send(\Symfony\Component\Mime\RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): ?\Symfony\Component\Mailer\SentMessage
                {
                    if ($message instanceof \Symfony\Component\Mime\Message) {
                        $this->sawHeader = $message->getHeaders()->has('X-Transport');
                    }

                    return null;
                }

                public function __toString(): string
                {
                    return 'header-spy';
                }
            };

            return $spy;
        };

        $decorator = new TenantAwareTransportsDecorator($inner, $provider, $cache, $context, null, $factory);
        $decorator->send($email);

        $this->assertFalse($sawHeader, 'inner tenant transport must receive the message AFTER X-Transport is stripped');
    }

    public function testTenantWithNullDsnThrowsRuntimeException(): void
    {
        $inner = $this->createMock(TransportInterface::class);
        $tenant = $this->makeTenant(null, 'acme');
        $provider = $this->createMock(TenantProviderInterface::class);
        $provider->method('findBySlug')->willReturn($tenant);

        $cache = new LruTransportCache(8);
        $context = new TenantContext();

        $email = (new Email())->from('x@y')->to('a@b')->text('hi');
        $email->getHeaders()->addTextHeader('X-Transport', 'tenant_acme');

        $decorator = new TenantAwareTransportsDecorator($inner, $provider, $cache, $context);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/acme/');
        $decorator->send($email);
    }

    public function testToStringPrefixesInnerWithTenantAware(): void
    {
        $inner = $this->createMock(TransportInterface::class);
        $inner->method('__toString')->willReturn('inner-name');

        $provider = $this->createMock(TenantProviderInterface::class);
        $cache = new LruTransportCache(8);
        $context = new TenantContext();

        $decorator = new TenantAwareTransportsDecorator($inner, $provider, $cache, $context);
        $this->assertStringStartsWith('tenant-aware:', (string) $decorator);
    }

    public function testThrowsWhenProviderIsNullAndTenantRoutingRequested(): void
    {
        $inner = $this->createMock(TransportInterface::class);
        $cache = new LruTransportCache(8);
        $context = new TenantContext();

        $email = (new Email())->from('x@y')->to('a@b')->text('hi');
        $email->getHeaders()->addTextHeader('X-Transport', 'tenant_acme');

        $decorator = new TenantAwareTransportsDecorator($inner, null, $cache, $context);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/provider/i');
        $decorator->send($email);
    }

    public function testFactoryReceivesInjectedEventDispatcher(): void
    {
        $inner = $this->createMock(TransportInterface::class);
        $tenant = $this->makeTenant('smtp://acme', 'acme');
        $provider = $this->createMock(TenantProviderInterface::class);
        $provider->method('findBySlug')->willReturn($tenant);

        $cache = new LruTransportCache(8);
        $context = new TenantContext();

        /** @var EventDispatcherInterface&MockObject $dispatcher */
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        $email = (new Email())->from('x@y')->to('a@b')->text('hi');
        $email->getHeaders()->addTextHeader('X-Transport', 'tenant_acme');

        $capturedDispatcher = null;
        $factory = function (string $dsn, ?EventDispatcherInterface $d) use (&$capturedDispatcher): TransportInterface {
            $capturedDispatcher = $d;

            return new PlainSpyTransport('built');
        };

        $decorator = new TenantAwareTransportsDecorator($inner, $provider, $cache, $context, $dispatcher, $factory);
        $decorator->send($email);

        $this->assertSame($dispatcher, $capturedDispatcher, 'transport factory must receive the injected event dispatcher');
    }
}
