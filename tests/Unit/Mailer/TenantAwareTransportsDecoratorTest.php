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

            public function isInMaintenance(): bool
            {
                return false;
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
        $this->assertTrue($email->getHeaders()->has('X-Transport'), 'WR-08 (Plan 20-09): X-Transport must be PRESERVED on the caller message after routing — the bundle no longer mutates the input message');
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
        $this->assertTrue($email->getHeaders()->has('X-Transport'), 'WR-08 (Plan 20-09): X-Transport header preserved post-routing');
    }

    public function testPreservesXTransportHeaderAfterRouting(): void
    {
        $inner = $this->createMock(TransportInterface::class);
        $tenant = $this->makeTenant('smtp://acme', 'acme');
        $provider = $this->createMock(TenantProviderInterface::class);
        $provider->method('findBySlug')->willReturn($tenant);

        $cache = new LruTransportCache(8);
        $context = new TenantContext();

        $email = (new Email())->from('x@y')->to('a@b')->text('hi');
        $email->getHeaders()->addTextHeader('X-Transport', 'tenant_acme');

        // Use a header-observing spy that records whether X-Transport was still
        // present at the moment the tenant transport received the message.
        $observer = new class implements TransportInterface {
            public ?bool $sawHeaderAtSendTime = null;

            public function send(\Symfony\Component\Mime\RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): ?\Symfony\Component\Mailer\SentMessage
            {
                if ($message instanceof \Symfony\Component\Mime\Message) {
                    $this->sawHeaderAtSendTime = $message->getHeaders()->has('X-Transport');
                }

                return null;
            }

            public function __toString(): string
            {
                return 'header-observer';
            }
        };

        $factory = static fn (string $dsn, ?EventDispatcherInterface $dispatcher): TransportInterface => $observer;

        $decorator = new TenantAwareTransportsDecorator($inner, $provider, $cache, $context, null, $factory);
        $decorator->send($email);

        $this->assertTrue(
            $observer->sawHeaderAtSendTime,
            'WR-08 (Plan 20-09): inner tenant transport receives the message WITH X-Transport intact — the bundle no longer strips it before delegation'
        );
        $this->assertTrue($email->getHeaders()->has('X-Transport'), 'WR-08 (Plan 20-09): X-Transport header preserved post-routing');
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

    public function testRefusesCrossTenantRoutingWhenContextTenantSlugMismatches(): void
    {
        // Defensive cross-tenant guard (T-20-03-02 mitigation): if a tenant is active
        // in TenantContext AND its slug differs from the X-Transport header slug,
        // the decorator MUST refuse to send rather than risk leaking mail across tenants.
        $inner = $this->createMock(TransportInterface::class);
        $provider = $this->createMock(TenantProviderInterface::class);
        $provider->expects($this->never())->method('findBySlug');

        $cache = new LruTransportCache(8);
        $context = new TenantContext();
        $context->setTenant($this->makeTenant('smtp://other', 'other-tenant'));

        $email = (new Email())->from('x@y')->to('a@b')->text('hi');
        $email->getHeaders()->addTextHeader('X-Transport', 'tenant_acme');

        $decorator = new TenantAwareTransportsDecorator($inner, $provider, $cache, $context);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/cross-tenant|does not match/i');
        $decorator->send($email);
    }

    public function testAllowsRoutingWhenContextTenantMatchesHeaderSlug(): void
    {
        $inner = $this->createMock(TransportInterface::class);
        $tenant = $this->makeTenant('smtp://acme', 'acme');
        $provider = $this->createMock(TenantProviderInterface::class);
        $provider->method('findBySlug')->willReturn($tenant);

        $cache = new LruTransportCache(8);
        $context = new TenantContext();
        $context->setTenant($tenant);

        $email = (new Email())->from('x@y')->to('a@b')->text('hi');
        $email->getHeaders()->addTextHeader('X-Transport', 'tenant_acme');

        $sendCount = 0;
        $built = new class($sendCount) implements TransportInterface {
            public function __construct(private int &$sendCount)
            {
            }

            public function send(\Symfony\Component\Mime\RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): ?\Symfony\Component\Mailer\SentMessage
            {
                ++$this->sendCount;

                return null;
            }

            public function __toString(): string
            {
                return 'built';
            }
        };

        $factory = static fn (string $dsn, ?EventDispatcherInterface $dispatcher): TransportInterface => $built;

        $decorator = new TenantAwareTransportsDecorator($inner, $provider, $cache, $context, null, $factory);
        $decorator->send($email);

        $this->assertSame(1, $sendCount, 'tenant transport must be invoked when context slug matches header slug');
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

    /**
     * Plan 20-11 / REVIEW BL-02 — empty-slug X-Transport guard.
     *
     * X-Transport: tenant_ (literal, no slug after the underscore) must be
     * rejected with a \RuntimeException BEFORE any provider call. This
     * catches the no-active-tenant path that the cross-tenant guard misses
     * (worker pre-restoration, sync-context misuse).
     */
    public function testRefusesEmptySlugXTransportHeader(): void
    {
        $inner = $this->createMock(TransportInterface::class);
        $inner->expects($this->never())->method('send');
        $provider = $this->createMock(TenantProviderInterface::class);
        $provider->expects($this->never())->method('findBySlug');
        $cache = new LruTransportCache(8);
        $context = new TenantContext(); // no active tenant — the unguarded path

        $email = (new Email())->from('x@y')->to('a@b')->text('hi');
        $email->getHeaders()->addTextHeader('X-Transport', 'tenant_'); // literal, empty slug

        $decorator = new TenantAwareTransportsDecorator($inner, $provider, $cache, $context);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/has an empty slug/');
        $decorator->send($email);
    }

    /**
     * Plan 20-11 / REVIEW BL-02 — character-set guard.
     *
     * A path-traversal-ish slug must be rejected. The character-set regex
     * [a-z0-9_-]+ matches the bundle's slug convention.
     */
    public function testRefusesInvalidSlugCharacters(): void
    {
        $inner = $this->createMock(TransportInterface::class);
        $inner->expects($this->never())->method('send');
        $provider = $this->createMock(TenantProviderInterface::class);
        $provider->expects($this->never())->method('findBySlug');
        $cache = new LruTransportCache(8);
        $context = new TenantContext();

        $email = (new Email())->from('x@y')->to('a@b')->text('hi');
        $email->getHeaders()->addTextHeader('X-Transport', 'tenant_../etc/passwd');

        $decorator = new TenantAwareTransportsDecorator($inner, $provider, $cache, $context);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/has an invalid slug/');
        $decorator->send($email);
    }

    /**
     * Plan 20-11 / REVIEW BL-02 — whitespace in slug rejected.
     */
    public function testRefusesSlugWithWhitespace(): void
    {
        $inner = $this->createMock(TransportInterface::class);
        $provider = $this->createMock(TenantProviderInterface::class);
        $provider->expects($this->never())->method('findBySlug');
        $cache = new LruTransportCache(8);
        $context = new TenantContext();

        $email = (new Email())->from('x@y')->to('a@b')->text('hi');
        $email->getHeaders()->addTextHeader('X-Transport', 'tenant_ acme');

        $decorator = new TenantAwareTransportsDecorator($inner, $provider, $cache, $context);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/has an invalid slug/');
        $decorator->send($email);
    }

    /**
     * Plan 20-11 / REVIEW BL-02 — uppercase slug rejected (bundle convention
     * is lower-case slugs; an uppercase slug is a sign of mis-construction
     * upstream and routing it would create a separate cache entry from the
     * canonical lower-case form).
     */
    public function testRefusesSlugWithUppercase(): void
    {
        $inner = $this->createMock(TransportInterface::class);
        $provider = $this->createMock(TenantProviderInterface::class);
        $provider->expects($this->never())->method('findBySlug');
        $cache = new LruTransportCache(8);
        $context = new TenantContext();

        $email = (new Email())->from('x@y')->to('a@b')->text('hi');
        $email->getHeaders()->addTextHeader('X-Transport', 'tenant_ACME');

        $decorator = new TenantAwareTransportsDecorator($inner, $provider, $cache, $context);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/has an invalid slug/');
        $decorator->send($email);
    }

    /**
     * Plan 20-11 / REVIEW BL-02 — sanity case: valid slug still routes.
     * Proves the new guards don't break the happy path.
     */
    public function testValidSlugStillRoutes(): void
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

        // Anon-class spy that counts send invocations — mirrors the pattern from
        // testAllowsRoutingWhenContextTenantMatchesHeaderSlug. We can't use
        // PlainSpyTransport here because it does not record invocations.
        $sendCount = 0;
        $built = new class($sendCount) implements TransportInterface {
            public function __construct(private int &$sendCount)
            {
            }

            public function send(\Symfony\Component\Mime\RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): ?\Symfony\Component\Mailer\SentMessage
            {
                ++$this->sendCount;

                return null;
            }

            public function __toString(): string
            {
                return 'built';
            }
        };

        $factory = static fn (string $dsn, ?EventDispatcherInterface $dispatcher): TransportInterface => $built;

        $decorator = new TenantAwareTransportsDecorator($inner, $provider, $cache, $context, null, $factory);
        $decorator->send($email);

        // Routing happened: the built spy transport saw the send.
        self::assertSame(1, $sendCount, 'valid slug must still route to the built transport');
    }
}
