<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Profiler;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Service\ResetInterface;
use Tenancy\Bundle\Event\TenantBootstrapped;
use Tenancy\Bundle\Event\TenantContextCleared;
use Tenancy\Bundle\Event\TenantResolved;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Profiler\TenantProfilerStash;
use Tenancy\Bundle\TenantInterface;

final class TenantProfilerStashTest extends TestCase
{
    private TenantInterface&MockObject $tenant;
    private HttpKernelInterface&MockObject $kernel;
    private TenantProfilerStash $stash;

    protected function setUp(): void
    {
        $this->tenant = $this->createMock(TenantInterface::class);
        $this->kernel = $this->createMock(HttpKernelInterface::class);
        $this->stash = new TenantProfilerStash();
    }

    public function testHasFourAsEventListenerAttributes(): void
    {
        $reflection = new \ReflectionClass(TenantProfilerStash::class);
        $attributes = $reflection->getAttributes(AsEventListener::class);
        self::assertCount(4, $attributes, 'TenantProfilerStash must declare exactly 4 #[AsEventListener] attributes');
    }

    public function testAsEventListenerAttributesReferenceCorrectEventsAndMethods(): void
    {
        $reflection = new \ReflectionClass(TenantProfilerStash::class);
        $expected = [
            TenantResolved::class => 'onTenantResolved',
            TenantBootstrapped::class => 'onTenantBootstrapped',
            TenantContextCleared::class => 'onTenantContextCleared',
            ExceptionEvent::class => 'onKernelException',
        ];
        $actual = [];
        foreach ($reflection->getAttributes(AsEventListener::class) as $attr) {
            $inst = $attr->newInstance();
            $actual[$inst->event] = $inst->method;
        }
        self::assertSame($expected, $actual);
    }

    public function testImplementsResetInterface(): void
    {
        self::assertInstanceOf(ResetInterface::class, $this->stash);
    }

    public function testInitiallyAllGettersReturnNullOrEmpty(): void
    {
        self::assertNull($this->stash->getResolvedBy());
        self::assertSame([], $this->stash->getBootstrapperFqcns());
        self::assertNull($this->stash->getCapturedException());
    }

    public function testCapturesResolvedByOnTenantResolved(): void
    {
        $this->stash->onTenantResolved(new TenantResolved($this->tenant, null, 'My\\Ns\\FooResolver'));
        self::assertSame('My\\Ns\\FooResolver', $this->stash->getResolvedBy());
    }

    public function testCapturesBootstrappersOnTenantBootstrapped(): void
    {
        $this->stash->onTenantBootstrapped(new TenantBootstrapped($this->tenant, ['A\\B', 'C\\D']));
        self::assertSame(['A\\B', 'C\\D'], $this->stash->getBootstrapperFqcns());
    }

    public function testCapturesTenancyException(): void
    {
        $event = new ExceptionEvent(
            $this->kernel,
            Request::create('/'),
            HttpKernelInterface::MAIN_REQUEST,
            new TenantNotFoundException('tenant x missing'),
        );
        $this->stash->onKernelException($event);
        self::assertSame(
            ['class' => TenantNotFoundException::class, 'message' => 'tenant x missing'],
            $this->stash->getCapturedException(),
        );
    }

    public function testIgnoresNonTenancyExceptions(): void
    {
        $event = new ExceptionEvent(
            $this->kernel,
            Request::create('/'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('boom'),
        );
        $this->stash->onKernelException($event);
        self::assertNull($this->stash->getCapturedException());
    }

    public function testResetClearsAllFields(): void
    {
        $this->stash->onTenantResolved(new TenantResolved($this->tenant, null, 'X\\Y'));
        $this->stash->onTenantBootstrapped(new TenantBootstrapped($this->tenant, ['A']));
        $this->stash->onKernelException(new ExceptionEvent(
            $this->kernel,
            Request::create('/'),
            HttpKernelInterface::MAIN_REQUEST,
            new TenantNotFoundException('m'),
        ));

        $this->stash->reset();

        self::assertNull($this->stash->getResolvedBy());
        self::assertSame([], $this->stash->getBootstrapperFqcns());
        self::assertNull($this->stash->getCapturedException());
    }

    public function testOnTenantContextClearedCallsReset(): void
    {
        $this->stash->onTenantResolved(new TenantResolved($this->tenant, null, 'X\\Y'));
        $this->stash->onTenantBootstrapped(new TenantBootstrapped($this->tenant, ['A']));

        $this->stash->onTenantContextCleared(new TenantContextCleared());

        self::assertNull($this->stash->getResolvedBy());
        self::assertSame([], $this->stash->getBootstrapperFqcns());
        self::assertNull($this->stash->getCapturedException());
    }
}
