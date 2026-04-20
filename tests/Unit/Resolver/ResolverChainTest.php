<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Resolver;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\Resolver\ResolverChain;
use Tenancy\Bundle\Resolver\TenantResolution;
use Tenancy\Bundle\Resolver\TenantResolverInterface;
use Tenancy\Bundle\TenantInterface;

final class ResolverChainTest extends TestCase
{
    private Request $request;
    private ResolverChain $chain;

    protected function setUp(): void
    {
        $this->request = Request::create('/');
        $this->chain = new ResolverChain();
    }

    // -------------------------------------------------------------------------
    // TenantResolution value object
    // -------------------------------------------------------------------------

    public function testTenantResolutionIsConstructibleWithReadonlyFields(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $resolution = new TenantResolution($tenant, 'HostResolver');

        $this->assertSame($tenant, $resolution->tenant);
        $this->assertSame('HostResolver', $resolution->resolvedBy);
    }

    // -------------------------------------------------------------------------
    // ResolverChain::resolve()
    // -------------------------------------------------------------------------

    public function testResolveReturnsTenantResolutionWhenResolverMatches(): void
    {
        $tenant = $this->createMock(TenantInterface::class);

        $resolver = $this->createMock(TenantResolverInterface::class);
        $resolver->expects($this->once())
            ->method('resolve')
            ->with($this->request)
            ->willReturn($tenant);

        $this->chain->addResolver($resolver);

        $resolution = $this->chain->resolve($this->request);

        $this->assertInstanceOf(TenantResolution::class, $resolution);
        $this->assertSame($tenant, $resolution->tenant);
        $this->assertSame($resolver::class, $resolution->resolvedBy);
    }

    public function testResolveReturnsFirstNonNullResult(): void
    {
        $tenant = $this->createMock(TenantInterface::class);

        $resolverA = $this->createMock(TenantResolverInterface::class);
        $resolverA->expects($this->once())
            ->method('resolve')
            ->with($this->request)
            ->willReturn($tenant);

        $resolverB = $this->createMock(TenantResolverInterface::class);
        $resolverB->expects($this->never())
            ->method('resolve');

        $this->chain->addResolver($resolverA);
        $this->chain->addResolver($resolverB);

        $resolution = $this->chain->resolve($this->request);

        $this->assertInstanceOf(TenantResolution::class, $resolution);
        $this->assertSame($tenant, $resolution->tenant);
        $this->assertSame($resolverA::class, $resolution->resolvedBy);
    }

    public function testResolveSkipsNullResultsAndContinues(): void
    {
        $tenant = $this->createMock(TenantInterface::class);

        $resolverA = $this->createMock(TenantResolverInterface::class);
        $resolverA->expects($this->once())
            ->method('resolve')
            ->with($this->request)
            ->willReturn(null);

        $resolverB = $this->createMock(TenantResolverInterface::class);
        $resolverB->expects($this->once())
            ->method('resolve')
            ->with($this->request)
            ->willReturn($tenant);

        $this->chain->addResolver($resolverA);
        $this->chain->addResolver($resolverB);

        $resolution = $this->chain->resolve($this->request);

        $this->assertInstanceOf(TenantResolution::class, $resolution);
        $this->assertSame($tenant, $resolution->tenant);
        $this->assertSame($resolverB::class, $resolution->resolvedBy);
    }

    public function testResolveReturnsNullWhenNoResolverMatches(): void
    {
        $resolverA = $this->createMock(TenantResolverInterface::class);
        $resolverA->method('resolve')->willReturn(null);

        $resolverB = $this->createMock(TenantResolverInterface::class);
        $resolverB->method('resolve')->willReturn(null);

        $this->chain->addResolver($resolverA);
        $this->chain->addResolver($resolverB);

        $this->assertNull($this->chain->resolve($this->request));
    }

    public function testResolveReturnsNullWhenChainIsEmpty(): void
    {
        $this->assertNull($this->chain->resolve($this->request));
    }

    public function testResolvedByCarriesCorrectFqcn(): void
    {
        $tenant = $this->createMock(TenantInterface::class);

        $resolver = $this->createMock(TenantResolverInterface::class);
        $resolver->method('resolve')->willReturn($tenant);

        $this->chain->addResolver($resolver);

        $resolution = $this->chain->resolve($this->request);

        $this->assertInstanceOf(TenantResolution::class, $resolution);
        $this->assertNotEmpty($resolution->resolvedBy);
        $this->assertSame($resolver::class, $resolution->resolvedBy);
    }

    public function testAddResolverAppendsToInternalList(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $callOrder = [];

        $resolverA = $this->createMock(TenantResolverInterface::class);
        $resolverA->method('resolve')->willReturnCallback(function () use (&$callOrder, $tenant): TenantInterface {
            $callOrder[] = 'A';

            return $tenant;
        });

        $resolverB = $this->createMock(TenantResolverInterface::class);
        $resolverB->method('resolve')->willReturnCallback(function () use (&$callOrder): null {
            $callOrder[] = 'B';

            return null;
        });

        // Add A then B — A should run first and win
        $this->chain->addResolver($resolverA);
        $this->chain->addResolver($resolverB);

        $this->chain->resolve($this->request);

        $this->assertSame(['A'], $callOrder, 'Resolver A should be called first and chain stops at first match');
    }
}
