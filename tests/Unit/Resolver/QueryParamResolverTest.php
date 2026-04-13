<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Resolver;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\Exception\TenantInactiveException;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\Resolver\QueryParamResolver;
use Tenancy\Bundle\TenantInterface;

final class QueryParamResolverTest extends TestCase
{
    private TenantProviderInterface&MockObject $provider;
    private QueryParamResolver $resolver;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(TenantProviderInterface::class);
        $this->resolver = new QueryParamResolver($this->provider);
    }

    public function testReturnsNullWhenParamAbsent(): void
    {
        $this->provider->expects($this->never())->method('findBySlug');

        $request = Request::create('/');
        $result = $this->resolver->resolve($request);

        $this->assertNull($result);
    }

    public function testReturnsNullWhenParamEmpty(): void
    {
        $this->provider->expects($this->never())->method('findBySlug');

        $request = Request::create('/?_tenant=');
        $result = $this->resolver->resolve($request);

        $this->assertNull($result);
    }

    public function testReturnsTenantWhenParamPresent(): void
    {
        $tenant = $this->createMock(TenantInterface::class);

        $this->provider->expects($this->once())
            ->method('findBySlug')
            ->with('acme')
            ->willReturn($tenant);

        $request = Request::create('/?_tenant=acme');
        $result = $this->resolver->resolve($request);

        $this->assertSame($tenant, $result);
    }

    public function testReturnsNullWhenProviderThrowsNotFound(): void
    {
        $this->provider->expects($this->once())
            ->method('findBySlug')
            ->with('unknown')
            ->willThrowException(new TenantNotFoundException('Tenant "unknown" not found.'));

        $request = Request::create('/?_tenant=unknown');
        $result = $this->resolver->resolve($request);

        $this->assertNull($result);
    }

    public function testBubblesInactiveException(): void
    {
        $this->provider->expects($this->once())
            ->method('findBySlug')
            ->with('acme')
            ->willThrowException(new TenantInactiveException('acme'));

        $request = Request::create('/?_tenant=acme');

        $this->expectException(TenantInactiveException::class);
        $this->resolver->resolve($request);
    }
}
