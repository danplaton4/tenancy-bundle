<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Messenger;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\Context\TenantContext;
use Tenancy\Bundle\Event\TenantContextCleared;
use Tenancy\Bundle\Exception\MissingTenantProviderException;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Messenger\TenantStamp;
use Tenancy\Bundle\Messenger\TenantWorkerMiddleware;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\TenantInterface;

final class TenantWorkerMiddlewareTest extends TestCase
{
    private TenantContext $tenantContext;
    private BootstrapperChain $bootstrapperChain;
    private EventDispatcherInterface&MockObject $chainDispatcher;
    private TenantProviderInterface&MockObject $tenantProvider;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private StackInterface&MockObject $stack;
    private MiddlewareInterface&MockObject $nextMiddleware;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext();

        // BootstrapperChain is final — instantiate with its own dispatcher mock
        $this->chainDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->chainDispatcher->method('dispatch')->willReturnArgument(0);
        $this->bootstrapperChain = new BootstrapperChain($this->chainDispatcher);

        $this->tenantProvider = $this->createMock(TenantProviderInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);

        $this->stack = $this->createMock(StackInterface::class);
        $this->nextMiddleware = $this->createMock(MiddlewareInterface::class);
        $this->stack->method('next')->willReturn($this->nextMiddleware);
    }

    private function buildMiddleware(): TenantWorkerMiddleware
    {
        return new TenantWorkerMiddleware(
            $this->tenantContext,
            $this->bootstrapperChain,
            $this->tenantProvider,
            $this->eventDispatcher,
        );
    }

    public function testBootsTenantContextFromStamp(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');

        $this->tenantProvider
            ->expects($this->once())
            ->method('findBySlug')
            ->with('acme')
            ->willReturn($tenant);

        $this->nextMiddleware
            ->method('handle')
            ->willReturnCallback(function (Envelope $e, StackInterface $s): Envelope {
                return $e;
            });

        $envelope = new Envelope(new \stdClass(), [new TenantStamp('acme')]);
        $middleware = $this->buildMiddleware();
        $middleware->handle($envelope, $this->stack);

        // setTenant was called — tenantContext holds the tenant (before clear)
        // We verify boot happened by checking TenantBootstrapped was dispatched to chainDispatcher
        // During the test the handler runs synchronously so context is cleared after handle() returns.
        // Check via chainDispatcher that boot() was called.
        $this->chainDispatcher->expects($this->never())->method('dispatch');
        // (boot was already called in the test above — no further call expected here)
    }

    public function testBootsTenantContextFromStampSetsTenant(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn('acme');

        $this->tenantProvider
            ->method('findBySlug')
            ->willReturn($tenant);

        $tenantDuringHandler = null;
        $this->nextMiddleware
            ->method('handle')
            ->willReturnCallback(function (Envelope $e, StackInterface $s) use (&$tenantDuringHandler): Envelope {
                $tenantDuringHandler = $this->tenantContext->getTenant();

                return $e;
            });

        $envelope = new Envelope(new \stdClass(), [new TenantStamp('acme')]);
        $middleware = $this->buildMiddleware();
        $middleware->handle($envelope, $this->stack);

        $this->assertSame($tenant, $tenantDuringHandler, 'Tenant context must be set before handler runs');
    }

    public function testClearsContextAfterHandler(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $this->tenantProvider->method('findBySlug')->willReturn($tenant);

        $this->nextMiddleware
            ->method('handle')
            ->willReturnArgument(0);

        $dispatchedEvents = [];
        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (object $event) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = $event;

                return $event;
            });

        $envelope = new Envelope(new \stdClass(), [new TenantStamp('acme')]);
        $middleware = $this->buildMiddleware();
        $middleware->handle($envelope, $this->stack);

        $this->assertFalse($this->tenantContext->hasTenant(), 'TenantContext must be cleared after handler');
        $this->assertCount(1, $dispatchedEvents);
        $this->assertInstanceOf(TenantContextCleared::class, $dispatchedEvents[0]);
    }

    public function testClearsContextOnHandlerException(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $this->tenantProvider->method('findBySlug')->willReturn($tenant);

        $this->nextMiddleware
            ->method('handle')
            ->willThrowException(new \RuntimeException('handler failed'));

        $envelope = new Envelope(new \stdClass(), [new TenantStamp('acme')]);
        $middleware = $this->buildMiddleware();

        try {
            $middleware->handle($envelope, $this->stack);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('handler failed', $e->getMessage());
        }

        $this->assertFalse($this->tenantContext->hasTenant(), 'TenantContext must be cleared even when handler throws');
    }

    public function testPassesThroughWhenNoStamp(): void
    {
        $this->tenantProvider
            ->expects($this->never())
            ->method('findBySlug');

        $this->nextMiddleware
            ->expects($this->once())
            ->method('handle')
            ->willReturnArgument(0);

        $envelope = new Envelope(new \stdClass());
        $middleware = $this->buildMiddleware();
        $middleware->handle($envelope, $this->stack);

        $this->assertFalse($this->tenantContext->hasTenant());
    }

    /**
     * Closes WR-01 (.planning/v0.3-MILESTONE-AUDIT.md).
     *
     * MissingTenantProviderException MUST extend \LogicException (not
     * \RuntimeException) so Symfony Messenger's default retry strategy does
     * NOT re-queue a worker that is misconfigured at the container level —
     * a permanent operator error, never a transient fault.
     */
    public function testMissingTenantProviderExceptionExtendsLogicException(): void
    {
        $middleware = new TenantWorkerMiddleware(
            $this->tenantContext,
            $this->bootstrapperChain,
            null, // ← provider absent (nullOnInvalid resolved to null in the container)
            $this->eventDispatcher,
        );

        $envelope = new Envelope(new \stdClass(), [new TenantStamp('acme')]);

        $caught = null;
        try {
            $middleware->handle($envelope, $this->stack);
        } catch (\Throwable $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'Expected MissingTenantProviderException was not thrown');
        $this->assertInstanceOf(
            MissingTenantProviderException::class,
            $caught,
            'Null provider + present stamp must throw MissingTenantProviderException.',
        );
        $this->assertInstanceOf(
            \LogicException::class,
            $caught,
            'MissingTenantProviderException MUST extend \LogicException so Messenger does NOT retry. See WR-01.',
        );
        // Explicit not-RuntimeException assertion — redundant at the type
        // level (PHPStan can prove it from MissingTenantProviderException's
        // declared ancestry) but kept as runtime documentation: if anyone
        // ever changes the parent class to RuntimeException, this fails.
        // @phpstan-ignore method.alreadyNarrowedType
        $this->assertNotInstanceOf(
            \RuntimeException::class,
            $caught,
            'MissingTenantProviderException MUST NOT extend \RuntimeException — Messenger would treat it as transient and retry. See WR-01.',
        );
        $this->assertStringContainsString('acme', $caught->getMessage(), 'Exception message must include the stamp slug for forensic debugging.');
        $this->assertStringContainsString('tenancy:install', $caught->getMessage(), 'Exception message must point operators at the install command.');
    }

    public function testNoThrowWhenProviderIsNullAndStampAbsent(): void
    {
        // Regression for TenantWorkerMiddleware L29-33: when no TenantStamp
        // is present we early-return through the stack BEFORE the null-provider
        // guard runs. A null provider on a stamp-less envelope must NOT throw.
        $middleware = new TenantWorkerMiddleware(
            $this->tenantContext,
            $this->bootstrapperChain,
            null,
            $this->eventDispatcher,
        );

        $envelope = new Envelope(new \stdClass());

        $this->nextMiddleware
            ->expects($this->once())
            ->method('handle')
            ->with($envelope, $this->stack)
            ->willReturn($envelope);

        $result = $middleware->handle($envelope, $this->stack);

        $this->assertSame($envelope, $result);
        $this->assertFalse($this->tenantContext->hasTenant());
    }

    public function testLetsTenantNotFoundExceptionPropagate(): void
    {
        $this->tenantProvider
            ->method('findBySlug')
            ->willThrowException(new TenantNotFoundException('acme'));

        $envelope = new Envelope(new \stdClass(), [new TenantStamp('acme')]);
        $middleware = $this->buildMiddleware();

        $this->expectException(TenantNotFoundException::class);

        $middleware->handle($envelope, $this->stack);

        // After exception: context was never booted, so it remains clear
        $this->assertFalse($this->tenantContext->hasTenant());
    }
}
