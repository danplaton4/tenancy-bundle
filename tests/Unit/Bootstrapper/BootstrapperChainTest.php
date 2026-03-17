<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Bootstrapper;

use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tenancy\Bundle\Bootstrapper\BootstrapperChain;
use Tenancy\Bundle\Bootstrapper\TenantBootstrapperInterface;
use Tenancy\Bundle\TenantInterface;

final class BootstrapperChainTest extends TestCase
{
    private EventDispatcherInterface $eventDispatcher;
    private TenantInterface $tenant;
    private BootstrapperChain $chain;

    protected function setUp(): void
    {
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->tenant = $this->createMock(TenantInterface::class);
        $this->chain = new BootstrapperChain($this->eventDispatcher);
    }

    public function testBootCallsAllBootstrappersInOrder(): void
    {
        $callOrder = [];

        $bootstrapperA = $this->createMock(TenantBootstrapperInterface::class);
        $bootstrapperA->expects($this->once())
            ->method('boot')
            ->with($this->tenant)
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'A';
            });

        $bootstrapperB = $this->createMock(TenantBootstrapperInterface::class);
        $bootstrapperB->expects($this->once())
            ->method('boot')
            ->with($this->tenant)
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'B';
            });

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch');

        $this->chain->addBootstrapper($bootstrapperA);
        $this->chain->addBootstrapper($bootstrapperB);
        $this->chain->boot($this->tenant);

        $this->assertSame(['A', 'B'], $callOrder);
    }

    public function testClearCallsBootstrappersInReverseOrder(): void
    {
        $callOrder = [];

        $bootstrapperA = $this->createMock(TenantBootstrapperInterface::class);
        $bootstrapperA->expects($this->once())
            ->method('clear')
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'A';
            });

        $bootstrapperB = $this->createMock(TenantBootstrapperInterface::class);
        $bootstrapperB->expects($this->once())
            ->method('clear')
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'B';
            });

        $this->chain->addBootstrapper($bootstrapperA);
        $this->chain->addBootstrapper($bootstrapperB);
        $this->chain->clear();

        $this->assertSame(['B', 'A'], $callOrder);
    }
}
