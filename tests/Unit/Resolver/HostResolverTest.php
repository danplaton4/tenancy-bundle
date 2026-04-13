<?php

declare(strict_types=1);

namespace Tenancy\Bundle\Tests\Unit\Resolver;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Tenancy\Bundle\Exception\TenantInactiveException;
use Tenancy\Bundle\Exception\TenantNotFoundException;
use Tenancy\Bundle\Provider\TenantProviderInterface;
use Tenancy\Bundle\Resolver\HostResolver;
use Tenancy\Bundle\TenantInterface;

final class HostResolverTest extends TestCase
{
    private TenantProviderInterface&MockObject $provider;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(TenantProviderInterface::class);
    }

    public function testReturnsNullWhenAppDomainIsNull(): void
    {
        $resolver = new HostResolver($this->provider, null);

        $this->provider->expects($this->never())->method('findBySlug');

        $request = Request::create('http://acme.app.com/');
        $result = $resolver->resolve($request);

        $this->assertNull($result);
    }

    public function testExtractsSlugFromSubdomain(): void
    {
        $resolver = new HostResolver($this->provider, 'app.com');
        $tenant = $this->createMock(TenantInterface::class);

        $this->provider->expects($this->once())
            ->method('findBySlug')
            ->with('acme')
            ->willReturn($tenant);

        $request = Request::create('http://acme.app.com/');
        $result = $resolver->resolve($request);

        $this->assertSame($tenant, $result);
    }

    public function testExtractsSlugFromMultiSegmentSubdomain(): void
    {
        $resolver = new HostResolver($this->provider, 'app.com');
        $tenant = $this->createMock(TenantInterface::class);

        $this->provider->expects($this->once())
            ->method('findBySlug')
            ->with('acme')
            ->willReturn($tenant);

        // api.acme.app.com → last segment before app.com is 'acme'
        $request = Request::create('http://api.acme.app.com/');
        $result = $resolver->resolve($request);

        $this->assertSame($tenant, $result);
    }

    public function testStripsWwwPrefix(): void
    {
        $resolver = new HostResolver($this->provider, 'app.com');
        $tenant = $this->createMock(TenantInterface::class);

        $this->provider->expects($this->once())
            ->method('findBySlug')
            ->with('acme')
            ->willReturn($tenant);

        // www.acme.app.com → strip www → acme.app.com → slug 'acme'
        $request = Request::create('http://www.acme.app.com/');
        $result = $resolver->resolve($request);

        $this->assertSame($tenant, $result);
    }

    public function testCaseInsensitive(): void
    {
        $resolver = new HostResolver($this->provider, 'app.com');
        $tenant = $this->createMock(TenantInterface::class);

        $this->provider->expects($this->once())
            ->method('findBySlug')
            ->with('acme')
            ->willReturn($tenant);

        // WWW.ACME.APP.COM → normalised to acme
        $request = Request::create('http://WWW.ACME.APP.COM/');
        $result = $resolver->resolve($request);

        $this->assertSame($tenant, $result);
    }

    public function testReturnsNullWhenHostDoesNotMatchAppDomain(): void
    {
        $resolver = new HostResolver($this->provider, 'app.com');

        $this->provider->expects($this->never())->method('findBySlug');

        $request = Request::create('http://otherdomain.com/');
        $result = $resolver->resolve($request);

        $this->assertNull($result);
    }

    public function testReturnsNullWhenHostIsExactlyAppDomain(): void
    {
        $resolver = new HostResolver($this->provider, 'app.com');

        $this->provider->expects($this->never())->method('findBySlug');

        // Host equals app_domain — no subdomain at all
        $request = Request::create('http://app.com/');
        $result = $resolver->resolve($request);

        $this->assertNull($result);
    }

    public function testReturnsNullWhenOnlyWwwPrefix(): void
    {
        $resolver = new HostResolver($this->provider, 'app.com');

        $this->provider->expects($this->never())->method('findBySlug');

        // www.app.com → strip www → app.com → no subdomain remaining
        $request = Request::create('http://www.app.com/');
        $result = $resolver->resolve($request);

        $this->assertNull($result);
    }

    public function testReturnsNullWhenProviderThrowsNotFoundException(): void
    {
        $resolver = new HostResolver($this->provider, 'app.com');

        $this->provider->expects($this->once())
            ->method('findBySlug')
            ->with('acme')
            ->willThrowException(new TenantNotFoundException('Tenant "acme" not found.'));

        $request = Request::create('http://acme.app.com/');
        $result = $resolver->resolve($request);

        // TenantNotFoundException is caught — lets chain try other resolvers
        $this->assertNull($result);
    }

    public function testBubblesInactiveException(): void
    {
        $resolver = new HostResolver($this->provider, 'app.com');

        $this->provider->expects($this->once())
            ->method('findBySlug')
            ->with('acme')
            ->willThrowException(new TenantInactiveException('acme'));

        $request = Request::create('http://acme.app.com/');

        // TenantInactiveException must NOT be caught — bubbles up as HTTP 403
        $this->expectException(TenantInactiveException::class);
        $resolver->resolve($request);
    }

    public function testCallsProviderWithExtractedSlug(): void
    {
        $resolver = new HostResolver($this->provider, 'app.com');
        $tenant = $this->createMock(TenantInterface::class);

        $this->provider->expects($this->once())
            ->method('findBySlug')
            ->with('acme')
            ->willReturn($tenant);

        $request = Request::create('http://acme.app.com/');
        $resolver->resolve($request);
    }
}
