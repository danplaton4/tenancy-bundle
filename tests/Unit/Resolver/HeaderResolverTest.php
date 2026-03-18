<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Resolver;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\Exception\TenantInactiveException;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\Resolver\HeaderResolver;
use Tenancy\Bundle\TenantInterface;

final class HeaderResolverTest extends TestCase
{
    private TenantProviderInterface&MockObject $provider;
    private HeaderResolver $resolver;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(TenantProviderInterface::class);
        $this->resolver = new HeaderResolver($this->provider);
    }

    public function testReturnsNullWhenHeaderAbsent(): void
    {
        $this->provider->expects($this->never())->method('findBySlug');

        $request = Request::create('/');
        $result = $this->resolver->resolve($request);

        $this->assertNull($result);
    }

    public function testReturnsNullWhenHeaderEmpty(): void
    {
        $this->provider->expects($this->never())->method('findBySlug');

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_X_TENANT_ID' => '']);
        $result = $this->resolver->resolve($request);

        $this->assertNull($result);
    }

    public function testReturnsTenantWhenHeaderPresent(): void
    {
        $tenant = $this->createMock(TenantInterface::class);

        $this->provider->expects($this->once())
            ->method('findBySlug')
            ->with('acme')
            ->willReturn($tenant);

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_X_TENANT_ID' => 'acme']);
        $result = $this->resolver->resolve($request);

        $this->assertSame($tenant, $result);
    }

    public function testReturnsNullWhenProviderThrowsNotFound(): void
    {
        $this->provider->expects($this->once())
            ->method('findBySlug')
            ->with('unknown')
            ->willThrowException(new TenantNotFoundException('Tenant "unknown" not found.'));

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_X_TENANT_ID' => 'unknown']);
        $result = $this->resolver->resolve($request);

        $this->assertNull($result);
    }

    public function testBubblesInactiveException(): void
    {
        $this->provider->expects($this->once())
            ->method('findBySlug')
            ->with('acme')
            ->willThrowException(new TenantInactiveException('acme'));

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_X_TENANT_ID' => 'acme']);

        $this->expectException(TenantInactiveException::class);
        $this->resolver->resolve($request);
    }
}
