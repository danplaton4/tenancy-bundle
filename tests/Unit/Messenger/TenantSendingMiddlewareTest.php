<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Messenger;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Messenger\TenantSendingMiddleware;
use Tenancy\Bundle\Messenger\TenantStamp;
use Tenancy\Bundle\TenantInterface;

final class TenantSendingMiddlewareTest extends TestCase
{
    private TenantContext $tenantContext;
    private StackInterface&MockObject $stack;
    private MiddlewareInterface&MockObject $nextMiddleware;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext();
        $this->stack = $this->createMock(StackInterface::class);
        $this->nextMiddleware = $this->createMock(MiddlewareInterface::class);

        $this->stack->method('next')->willReturn($this->nextMiddleware);
    }

    private function buildMiddleware(): TenantSendingMiddleware
    {
        return new TenantSendingMiddleware($this->tenantContext);
    }

    public function testAttachesStampWhenTenantActive(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');
        $this->tenantContext->setTenant($tenant);

        $envelope = new Envelope(new \stdClass());

        $this->nextMiddleware
            ->expects($this->once())
            ->method('handle')
            ->willReturnCallback(function (Envelope $e, StackInterface $s): Envelope {
                return $e;
            });

        $middleware = $this->buildMiddleware();
        $result = $middleware->handle($envelope, $this->stack);

        /** @var TenantStamp|null $stamp */
        $stamp = $result->last(TenantStamp::class);
        $this->assertNotNull($stamp);
        $this->assertSame('acme', $stamp->getTenantSlug());
    }

    public function testPassesThroughWhenNoTenant(): void
    {
        // No tenant set on context
        $envelope = new Envelope(new \stdClass());

        $this->nextMiddleware
            ->expects($this->once())
            ->method('handle')
            ->willReturnCallback(function (Envelope $e, StackInterface $s): Envelope {
                return $e;
            });

        $middleware = $this->buildMiddleware();
        $result = $middleware->handle($envelope, $this->stack);

        $this->assertNull($result->last(TenantStamp::class));
    }

    public function testIdempotent_DoesNotDoubleStamp(): void
    {
        // Envelope already carries a TenantStamp
        $existingStamp = new TenantStamp('existing');
        $envelope = new Envelope(new \stdClass(), [$existingStamp]);

        // Even though context has a different tenant, middleware must NOT add another stamp
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('different-tenant');
        $this->tenantContext->setTenant($tenant);

        $capturedEnvelope = null;
        $this->nextMiddleware
            ->expects($this->once())
            ->method('handle')
            ->willReturnCallback(function (Envelope $e, StackInterface $s) use (&$capturedEnvelope): Envelope {
                $capturedEnvelope = $e;
                return $e;
            });

        $middleware = $this->buildMiddleware();
        $middleware->handle($envelope, $this->stack);

        // Only one TenantStamp (the original one) should be present
        $stamps = $capturedEnvelope->all(TenantStamp::class);
        $this->assertCount(1, $stamps);
        $this->assertSame('existing', $stamps[0]->getTenantSlug());
    }

    public function testCallsNextInStack(): void
    {
        $envelope = new Envelope(new \stdClass());

        $this->nextMiddleware
            ->expects($this->once())
            ->method('handle')
            ->willReturnArgument(0);

        $middleware = $this->buildMiddleware();
        $middleware->handle($envelope, $this->stack);
    }
}
